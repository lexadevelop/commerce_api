<?php declare(strict_types = 1);

namespace Drupal\commerce_api\Resource\Checkout;

use Drupal\commerce_api\EntityResourceShim;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Exception\OrderVersionMismatchException;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentOrderUpdaterInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\ManualPaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\JsonApiResource\ResourceObjectData;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi_resources\Resource\EntityResourceBase;
use Drupal\jsonapi_resources\Unstable\Entity\ResourceObjectToEntityMapperAwareTrait;
use Drupal\jsonapi_resources\Unstable\Value\NewResourceObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Resource for manual and payment method payment transactions.
 */
final class PaymentResource extends EntityResourceBase implements ContainerInjectionInterface {

  use ResourceObjectToEntityMapperAwareTrait;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  private $renderer;

  /**
   * The JSON:API controller shim.
   *
   * @var \Drupal\commerce_api\EntityResourceShim
   */
  protected $inner;

  /**
   * The payment order updater.
   *
   * @var \Drupal\commerce_payment\PaymentOrderUpdaterInterface
   */
  protected $paymentOrderUpdater;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs a new PaymentResource object.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The render.
   * @param \Drupal\commerce_api\EntityResourceShim $jsonapi_controller
   *   The JSON:API controller shim.
   * @param \Drupal\commerce_payment\PaymentOrderUpdaterInterface $payment_order_updater
   *   The order update manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(RendererInterface $renderer, EntityResourceShim $jsonapi_controller, PaymentOrderUpdaterInterface $payment_order_updater, LoggerInterface $logger, Connection $connection) {
    $this->renderer = $renderer;
    $this->inner = $jsonapi_controller;
    $this->paymentOrderUpdater = $payment_order_updater;
    $this->logger = $logger;
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('renderer'),
      $container->get('commerce_api.jsonapi_controller_shim'),
      $container->get('commerce_payment.order_updater'),
      $container->get('logger.channel.commerce_payment'),
      $container->get('database')
    );
  }

  /**
   * Process the resource request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order.
   * @param \Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel $document
   *   The deserialized request document.
   *
   * @return \Drupal\jsonapi\ResourceResponse
   *   The response.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException
   */
  public function process(Request $request, OrderInterface $commerce_order, JsonApiDocumentTopLevel $document): ResourceResponse {
    // Starting a transaction here ensures no payment will be saved in case
    // of an order version mismatch exception.
    $transaction = $this->connection->startTransaction();
    try {
      return $this->doProcess($request, $commerce_order, $document);
    }
    catch (EntityStorageException $exception) {
      if ($exception->getPrevious() instanceof OrderVersionMismatchException) {
        $transaction->rollBack();
        throw new ConflictHttpException($exception->getMessage(), $exception);
      }
      throw $exception;
    }
  }

  /**
   * Process the resource request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel $document
   *   The deserialized request document.
   *
   * @return \Drupal\jsonapi\ResourceResponse
   *   The response.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function doProcess(Request $request, OrderInterface $order, JsonApiDocumentTopLevel $document) {
    if ($document === NULL) {
      throw new UnprocessableEntityHttpException('The request document was empty.');
    }

    if ($order->get('payment_gateway')->isEmpty()) {
      throw new BadRequestHttpException('A payment gateway is not set for this order.');
    }
    $payment_gateway = $order->get('payment_gateway')->entity;
    if (!$payment_gateway instanceof PaymentGatewayInterface) {
      throw new BadRequestHttpException('A payment gateway is not set for this order.');
    }

    $payment_gateway_plugin = $payment_gateway->getPlugin();
    if (!$payment_gateway_plugin instanceof ManualPaymentGatewayInterface && !$payment_gateway_plugin instanceof SupportsStoredPaymentMethodsInterface) {
      throw new BadRequestHttpException(sprintf('The payment gateway for the order does not implement %s or %s', ManualPaymentGatewayInterface::class, SupportsStoredPaymentMethodsInterface::class));
    }

    $data = $document->getData();
    if ($data->getCardinality() !== 1) {
      throw new UnprocessableEntityHttpException("The request document's primary data must not be an array.");
    }

    $resource_object = $data->getIterator()->current();
    assert($resource_object instanceof NewResourceObject);

    $allowed_fields = ['capture'];
    $has_disallowed_fields = array_diff(array_keys($resource_object->getFields()), $allowed_fields);
    if (count($has_disallowed_fields) > 0) {
      throw new UnprocessableEntityHttpException('The following fields are not allowed: ' . implode(', ', $has_disallowed_fields));
    }

    $payment = $this->resourceObjectToEntityMapper->createEntityFromResourceObject($resource_object);
    assert($payment instanceof PaymentInterface);

    // @todo make this fields write access denied in FieldAccess.
    // @todo is there a way to set these in the resource object directly before mapping.
    $payment->state = 'new';
    $payment->amount = $order->getBalance();
    $payment->payment_gateway = $order->get('payment_gateway')->target_id;
    $payment->payment_method = $order->get('payment_method')->target_id;
    $payment->order_id = $order->id();

    try {
      if ($payment_gateway_plugin instanceof SupportsStoredPaymentMethodsInterface) {
        $capture = $resource_object->getField('capture') ?? TRUE;
        $payment_gateway_plugin->createPayment($payment, $capture);
      }
      elseif ($payment_gateway_plugin instanceof ManualPaymentGatewayInterface) {
        $payment_gateway_plugin->createPayment($payment);
      }
      // No other payment gateway processing possibilities.
      else {
        throw new UnprocessableEntityHttpException('We encountered an unexpected error processing your payment method. Please try again later.');
      }
    }
    catch (DeclineException $e) {
      $this->logger->error($e->getMessage());
      throw new UnprocessableEntityHttpException('We encountered an error processing your payment method. Please verify your details and try again.');
    }
    catch (PaymentGatewayException $e) {
      $this->logger->error($e->getMessage());
      throw new UnprocessableEntityHttpException('We encountered an unexpected error processing your payment method. Please try again later.');
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->error($e->getMessage());
      throw new UnprocessableEntityHttpException($e->getMessage());
    }

    $render_context = new RenderContext();
    $this->renderer->executeInRenderContext($render_context, function () use ($order) {
      // The on return method is concerned with creating/completing payments,
      // so we can assume the order has been finished and place it.
      $order->getState()->applyTransitionById('place');

      if ($this->paymentOrderUpdater->needsUpdate($order)) {
        $this->paymentOrderUpdater->updateOrder($order);
      }

      $order->save();
    });

    $primary_data = new ResourceObjectData([
      ResourceObject::createFromEntity($this->resourceTypeRepository->get($payment->getEntityTypeId(), $payment->bundle()), $payment),
    ], 1);
    $response = $this->createJsonapiResponse($primary_data, $request, 201);
    if (!$render_context->isEmpty()) {
      $response->addCacheableDependency($render_context->pop());
    }
    return $response;
  }

}

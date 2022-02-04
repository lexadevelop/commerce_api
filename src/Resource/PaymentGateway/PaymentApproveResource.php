<?php declare(strict_types = 1);

namespace Drupal\commerce_api\Resource\PaymentGateway;

use Drupal\commerce_api\Resource\FixIncludeTrait;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Exception\OrderVersionMismatchException;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\jsonapi_resources\Resource\EntityResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Resource for off-site payment transactions.
 *
 * @see \Drupal\commerce_payment\Controller\PaymentCheckoutController.
 */
final class PaymentApproveResource extends EntityResourceBase implements ContainerInjectionInterface {

  use FixIncludeTrait;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  private $renderer;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private $connection;

  /**
   * Constructs a new OnReturnResource object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(LoggerInterface $logger, RendererInterface $renderer, Connection $connection) {
    $this->logger = $logger;
    $this->renderer = $renderer;
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('logger.channel.commerce_payment'),
      $container->get('renderer'),
      $container->get('database')
    );
  }

  /**
   * Process the request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order.
   */
  public function process(Request $request, OrderInterface $commerce_order) {
    $transaction = $this->connection->startTransaction();
    try {
      return $this->doProcess($request, $commerce_order);
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
   * Process the request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  private function doProcess(Request $request, OrderInterface $order) {
    // @todo should this actually be a "not allowed" exception?
    //   instead be kind and just return the order object to be reentrant.
    if ($order->getState()->getId() !== 'draft') {
      $this->fixOrderInclude($request);
      $top_level_data = $this->createIndividualDataFromEntity($order);
      return $this->createJsonapiResponse($top_level_data, $request);
    }

    if ($order->get('payment_gateway')->isEmpty()) {
      throw new AccessException('A payment gateway is not set for this order.');
    }
    $payment_gateway = $order->get('payment_gateway')->entity;
    if (!$payment_gateway instanceof PaymentGatewayInterface) {
      throw new AccessException('A payment gateway is not set for this order.');
    }

    $payment_gateway_plugin = $payment_gateway->getPlugin();
    if (!$payment_gateway_plugin instanceof OffsitePaymentGatewayInterface) {
      // @todo this message feels too internal to expose to a frontend.
      throw new AccessException('The payment gateway for the order does not implement ' . OffsitePaymentGatewayInterface::class);
    }

    try {
      $payment_gateway_plugin->onReturn($order, $request);
    }
    catch (PaymentGatewayException $e) {
      $this->logger->error($e->getMessage());
      throw new PaymentGatewayException(
        'Payment failed at the payment server. Please review your information and try again.',
        $e->getCode(),
        $e
      );
    }

    // The on return method is concerned with creating/completing payments, so
    // we can assume the order has been finished and place it.
    $render_context = new RenderContext();
    $this->renderer->executeInRenderContext($render_context, function () use ($order) {
      $order->getState()->applyTransitionById('place');
      $order->save();
    });

    $this->fixOrderInclude($request);
    $top_level_data = $this->createIndividualDataFromEntity($order);
    return $this->createJsonapiResponse($top_level_data, $request);
  }

}

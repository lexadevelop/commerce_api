<?php declare(strict_types = 1);

namespace Drupal\commerce_api\Resource;

use Drupal\commerce_api\OrderValidationTrait;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi_resources\Resource\EntityResourceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class CheckoutResource extends EntityResourceBase implements ContainerInjectionInterface {

  use OrderValidationTrait;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  private $eventDispatcher;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  private $renderer;

  /**
   * Constructs a new CheckoutResource object.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(EventDispatcherInterface $event_dispatcher, RendererInterface $renderer) {
    $this->eventDispatcher = $event_dispatcher;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('event_dispatcher'),
      $container->get('renderer')
    );
  }

  /**
   * Process the resource request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param array $resource_types
   *   The resource types for this resource.
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
   */
  public function process(Request $request, array $resource_types, OrderInterface $commerce_order, JsonApiDocumentTopLevel $document = NULL): ResourceResponse {
    // Must use this due to strict checking in JsonapiResourceController;.
    // @todo fix in https://www.drupal.org/project/jsonapi_resources/issues/3096949
    $resource_type = reset($resource_types);
    $save_order = FALSE;
    if ($document) {
      $data = $document->getData();
      if ($data->getCardinality() !== 1) {
        throw new UnprocessableEntityHttpException("The request document's primary data must not be an array.");
      }
      $resource_object = $data->getIterator()->current();
      assert($resource_object instanceof ResourceObject);

      $fields = $resource_object->getFields();
      // Build an array of field names to validate.
      $field_names = [];

      // If the `email` field was provided, set it on the order.
      if (isset($fields['email'])) {
        $field_names[] = 'mail';
        $commerce_order->setEmail($fields['email']);
      }

      foreach ($fields as $field_name => $value) {
        if (in_array($field_name, ['email'])) {
          continue;
        }
        if (!$commerce_order->hasField($field_name)) {
          throw new UnprocessableEntityHttpException(sprintf("Cannot set the unknown field %s.", $field_name));
        }
        $field_names[] = $field_name;
        $commerce_order->set($field_name, $value);
      }

      if (isset($fields['shipping_method'])) {
        $field_names[] = 'shipments';
      }
      if (isset($fields['payment_instrument'])) {
        $field_names[] = 'payment_method';
        $field_names[] = 'payment_gateway';
      }

      // Validate the provided fields, which will throw 422 if invalid.
      // HOWEVER! It doesn't recursively validate referenced entities. So it will
      // validate `shipments` has valid values, but not the shipments. And then
      // it will only validate shipping_profile is a valid reference, but not its
      // address.
      // @todo investigate recursive/nested validation? 🤔
      static::validate($commerce_order, $field_names);
      $save_order = TRUE;
    }
    elseif (!$commerce_order->getData('checkout_init_event_dispatched', FALSE)) {
      $event = new OrderEvent($commerce_order);
      // @todo replace the event name by the right one once
      // https://www.drupal.org/project/commerce/issues/3104564 is resolved.
      $this->eventDispatcher->dispatch('commerce_checkout.init', $event);
      $commerce_order->setData('checkout_init_event_dispatched', TRUE);
      $save_order = TRUE;
    }

    if ($save_order) {
      // The order refresh process might bubble cacheable metadata that needs to
      // be collected and added to the response. This occurs if the promotion
      // module is installed and queries for available promotions.
      $render_context = new RenderContext();
      $this->renderer->executeInRenderContext($render_context, function () use ($commerce_order) {
        $commerce_order->save();
      });

      // For some reason adjustments after refresh are not available unless
      // we reload here. same with saved shipment data. Something is screwing
      // with the references.
      $order_storage = $this->entityTypeManager->getStorage('commerce_order');
      $commerce_order = $order_storage->load($commerce_order->id());
      assert($commerce_order instanceof OrderInterface);
    }

    $primary_data = $this->createIndividualDataFromEntity($commerce_order);
    $response = $this->createJsonapiResponse($primary_data, $request);
    if (isset($render_context) && !$render_context->isEmpty()) {
      $response->addCacheableDependency($render_context->pop());
    }
    return $response;
  }

}

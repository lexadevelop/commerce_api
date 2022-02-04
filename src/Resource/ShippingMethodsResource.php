<?php declare(strict_types = 1);

namespace Drupal\commerce_api\Resource;

use Drupal\commerce_api\Plugin\DataType\ShippingRate as ShippingRateDataType;
use Drupal\commerce_api\ResourceType\RenamableResourceType;
use Drupal\commerce_api\TypedData\ShippingRateDefinition;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\ShipmentManagerInterface;
use Drupal\commerce_shipping\ShippingOrderManagerInterface;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\TypedData\TypedDataTrait;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\JsonApiResource\ResourceObjectData;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeAttribute;
use Drupal\jsonapi_resources\Resource\ResourceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

final class ShippingMethodsResource extends ResourceBase implements ContainerInjectionInterface {
  use TypedDataTrait;

  /**
   * The shipment manager.
   *
   * @var \Drupal\commerce_shipping\ShipmentManagerInterface
   */
  private $shipmentManager;

  /**
   * The shipping order manager.
   *
   * @var \Drupal\commerce_shipping\ShippingOrderManagerInterface
   */
  private $shippingOrderManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  private $renderer;

  /**
   * Constructs a new ShippingMethodsResource object.
   *
   * @param \Drupal\commerce_shipping\ShipmentManagerInterface $shipment_manager
   *   The shipment manager.
   * @param \Drupal\commerce_shipping\ShippingOrderManagerInterface $shipping_order_manager
   *   The shipping order manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(ShipmentManagerInterface $shipment_manager, ShippingOrderManagerInterface $shipping_order_manager, RendererInterface $renderer) {
    $this->shipmentManager = $shipment_manager;
    $this->shippingOrderManager = $shipping_order_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new self(
      $container->get('commerce_shipping.shipment_manager'),
      $container->get('commerce_shipping.order_manager'),
      $container->get('renderer')
    );
    $instance->setTypedDataManager($container->get('typed_data_manager'));
    return $instance;
  }

  /**
   * Process the resource request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param array $resource_types
   *   The resource tpyes for this resource.
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order.
   *
   * @return \Drupal\jsonapi\ResourceResponse
   *   The response.
   */
  public function process(Request $request, array $resource_types, OrderInterface $commerce_order): ResourceResponse {
    $shipments = $commerce_order->get('shipments')->referencedEntities();
    if (empty($shipments)) {
      $shipping_profile = $commerce_order->get('shipping_information')->entity;
      $shipments = $this->shippingOrderManager->pack($commerce_order, $shipping_profile);
    }
    $resource_type = reset($resource_types);

    $render_context = new RenderContext();
    $options = $this->renderer->executeInRenderContext($render_context, function () use ($shipments, $resource_type) {
      $options = [];
      foreach ($shipments as $shipment) {
        assert($shipment instanceof ShipmentInterface);
        $shipping_rate_data_definition = ShippingRateDefinition::create();
        $options[] = array_map(function (ShippingRate $rate) use ($resource_type, $shipping_rate_data_definition) {
          $data = $this->getTypedDataManager()->create($shipping_rate_data_definition, $rate->toArray());
          assert($data instanceof ShippingRateDataType);
          $resource_object_data = $data->getProperties();
          unset($resource_object_data['id']);
          return new ResourceObject(
            new CacheableMetadata(),
            $resource_type,
            $rate->getId(),
            NULL,
            $resource_object_data,
            new LinkCollection([])
          );
        }, $this->shipmentManager->calculateRates($shipment));
      }
      return array_merge([], ...$options);
    });
    $response = $this->createJsonapiResponse(new ResourceObjectData($options), $request);
    $response->addCacheableDependency($commerce_order);
    if (!$render_context->isEmpty()) {
      $response->addCacheableDependency($render_context->pop());
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteResourceTypes(Route $route, string $route_name): array {
    return [$this->getShippingRateOptionResourceType()];
  }

  /**
   * Get the shipping rate option resource type.
   *
   * @return \Drupal\jsonapi\ResourceType\ResourceType
   *   The resource type.
   */
  private function getShippingRateOptionResourceType(): ResourceType {
    $resource_type = new RenamableResourceType(
      'shipping_rate_option',
      'shipping_rate_option',
      NULL,
      'shipping-rate-option',
      FALSE,
      FALSE,
      FALSE,
      FALSE,
      [
        'optionId' => new ResourceTypeAttribute('optionId', 'optionId'),
        'label' => new ResourceTypeAttribute('label', 'label'),
        'methodId' => new ResourceTypeAttribute('methodId', 'methodId'),
        'rate' => new ResourceTypeAttribute('rate', 'rate'),

      ]
    );
    $resource_type->setRelatableResourceTypes([]);
    return $resource_type;
  }

}

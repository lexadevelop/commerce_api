<?php

namespace Drupal\Tests\commerce_api\Kernel\Cart;

use Drupal\commerce_api\Resource\CartUpdateItemResource;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Component\Serialization\Json;
use Drupal\jsonapi\Exception\EntityAccessDeniedHttpException;
use Drupal\jsonapi\Exception\UnprocessableHttpEntityException;
use Drupal\Tests\commerce_api\Kernel\KernelTestBase;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tests the CartUpdateItemResource.
 *
 * @group commerce_api
 */
final class CartUpdateItemResourceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_order_test',
  ];

  /**
   * @dataProvider dataUpdateItemAttributes
   */
  public function testUpdateItem($test_sku, array $test_document, $expected_exception_class = '', $expected_exception_message = '') {
    /** @var \Drupal\commerce_product\Entity\ProductVariation $product_variation */
    $product_variation = ProductVariation::create([
      'uuid' => '9dc0ce8a-1d62-40a2-bbf9-7b6041fd08d1',
      'type' => 'default',
      'sku' => 'TEST',
      'status' => 1,
      'price' => new Price('4.00', 'USD'),
    ]);
    $product_variation->save();

    /** @var \Drupal\commerce_product\Entity\Product $product */
    $product = Product::create([
      'type' => 'default',
      'stores' => [$this->store->id()],
    ]);
    /** @var \Drupal\commerce_product\Entity\ProductVariation $product_variation */
    $product_variation = ProductVariation::create([
      'type' => 'default',
      'sku' => $test_sku,
      'status' => 1,
      'price' => new Price('4.00', 'USD'),
    ]);
    $product_variation->save();
    $product->addVariation($product_variation);
    $product->save();

    $order_item = OrderItem::create([
      'type' => 'default',
      'quantity' => '1',
      'unit_price' => $product_variation->getPrice(),
      'purchased_entity' => $product_variation->id(),
    ]);
    assert($order_item instanceof OrderItem);
    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'mail' => 'test@example.com',
      'ip_address' => '127.0.0.1',
      'order_number' => '6',
      'store_id' => $this->store,
      'order_items' => [$order_item],
    ]);
    assert($order instanceof Order);
    $order->save();

    $document['data'] = [
      'type' => $order_item->getEntityTypeId() . '--' . $order_item->bundle(),
      'id' => $order_item->uuid(),
    ];
    $document['data'] += $test_document;
    $document['data'] += ['attributes' => [], 'relationships' => []];
    $request = Request::create("https://localhost/cart/{$order->uuid()}/items/{$order_item->uuid()}", 'POST', [], [], [], [], Json::encode($document));

    $route = $this->container->get('router')->getRouteCollection()->get('commerce_api.carts.update_item');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $this->container->get('request_stack')->push($request);

    $controller = $this->getController();

    if (!empty($expected_exception_class)) {
      // Simulate an order version mismatch exception.
      if ($expected_exception_class === ConflictHttpException::class) {
        $order = $this->reloadEntity($order);
        $order->setVersion(0);
      }
      $this->expectException($expected_exception_class);
      if (!empty($expected_exception_message)) {
        $this->expectExceptionMessage($expected_exception_message);
      }
    }

    $controller->process($request, $order, $order_item);
  }

  /**
   * Test data for order item updates.
   *
   * @return \Generator
   *   The test data.
   */
  public function dataUpdateItemAttributes() {
    yield [
      'ABC123',
      [
        'attributes' => [
          'quantity' => 10,
        ],
      ],
    ];
    yield [
      'ABC123',
      [
        'attributes' => [
          'quantity' => 10,
        ],
      ],
      ConflictHttpException::class,
    ];
    yield [
      'ABC123',
      [
        'attributes' => [
          'quantity' => -1,
        ],
      ],
      UnprocessableHttpEntityException::class,
      'Unprocessable Entity: validation failed.',
    ];
    yield [
      'ABC123',
      [
        'relationships' => [
          'purchased_entity' => [
            'data' => [
              'type' => 'product-variation--default',
              'id' => '9dc0ce8a-1d62-40a2-bbf9-7b6041fd08d1',
            ],
          ],
        ],
      ],
      EntityAccessDeniedHttpException::class,
      'The `purchased_entity` field cannot be modified.',
    ];
    yield [
      'TEST_123',
      [
        'attributes' => [
          'quantity' => 10,
        ],
      ],
      UnprocessableHttpEntityException::class,
      'Unprocessable Entity: validation failed.',
    ];
  }

  /**
   * Gets the controller to test.
   *
   * @return \Drupal\commerce_api\Resource\CartUpdateItemResource
   *   The controller.
   */
  protected function getController() {
    $controller = new CartUpdateItemResource(
      $this->container->get('commerce_cart.cart_provider'),
      $this->container->get('commerce_cart.cart_manager'),
      $this->container->get('commerce_api.jsonapi_controller_shim')
    );
    $controller->setResourceResponseFactory($this->container->get('jsonapi_resources.resource_response_factory'));
    $controller->setResourceTypeRepository($this->container->get('jsonapi.resource_type.repository'));
    $controller->setEntityTypeManager($this->container->get('entity_type.manager'));
    $controller->setEntityAccessChecker($this->container->get('jsonapi_resources.entity_access_checker'));
    return $controller;
  }

}

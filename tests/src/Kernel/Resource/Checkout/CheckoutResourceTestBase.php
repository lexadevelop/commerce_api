<?php

namespace Drupal\Tests\commerce_api\Kernel\Resource\Checkout;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShippingMethod;
use Drupal\Component\Serialization\Json;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Tests\commerce_api\Kernel\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class CheckoutResourceTestBase extends KernelTestBase implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'physical',
    'commerce_shipping',
    'commerce_payment',
    'commerce_payment_example',
  ];

  protected const TEST_ORDER_UUID = 'd59cd06e-c674-490d-aad9-541a1625e47f';
  protected const TEST_ORDER_ITEM_UUID = 'e8daecd7-6444-4d9a-9bd1-84dc5466dba7';
  protected const TEST_STORE_UUID = '01ffcd69-eb18-4e76-980c-395c60babf83';

  /**
   * The test order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $container
      ->getDefinition('jsonapi_resources.argument_resolver.document')
      ->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_payment_method');
    $this->installEntitySchema('commerce_shipment');
    $this->installEntitySchema('commerce_shipping_method');
    $this->installConfig(['commerce_shipping']);

    $onsite_gateway = PaymentGateway::create([
      'id' => 'onsite',
      'label' => 'On-site',
      'plugin' => 'example_onsite',
      'configuration' => [
        'api_key' => '2342fewfsfs',
        'payment_method_types' => ['credit_card'],
      ],
    ]);
    $onsite_gateway->save();

    $product_variation = $this->createTestProductVariation([], [
      'type' => 'default',
      'sku' => 'JSONAPI_SKU',
      'status' => 1,
      'title' => 'JSONAPI',
      'price' => new Price('4.00', 'USD'),
    ]);
    $product_variation->save();
    $order_item = OrderItem::create([
      'uuid' => self::TEST_ORDER_ITEM_UUID,
      'type' => 'default',
      'quantity' => '1',
      'title' => $product_variation->label(),
      'unit_price' => $product_variation->getPrice(),
      'purchased_entity' => $product_variation->id(),
    ]);
    $order_item->save();
    assert($order_item instanceof OrderItem);
    $order = Order::create([
      'uuid' => self::TEST_ORDER_UUID,
      'type' => 'default',
      'state' => 'draft',
      'ip_address' => '127.0.0.1',
      'store_id' => $this->store,
    ]);
    assert($order instanceof Order);
    $order->addItem($order_item);
    $order->save();
    $this->order = $order;
    $this->container->get('commerce_cart.cart_session')->addCartId($this->order->id());

    $shipping_method = ShippingMethod::create([
      'stores' => $this->store->id(),
      'name' => 'Example',
      'plugin' => [
        'target_plugin_id' => 'flat_rate',
        'target_plugin_configuration' => [
          'rate_label' => 'Flat rate',
          'rate_amount' => [
            'number' => '5',
            'currency_code' => 'USD',
          ],
        ],
      ],
      'status' => TRUE,
      'weight' => 1,
    ]);
    $shipping_method->save();

    $another_shipping_method = ShippingMethod::create([
      'stores' => $this->store->id(),
      'name' => 'Another shipping method',
      'plugin' => [
        'target_plugin_id' => 'flat_rate',
        'target_plugin_configuration' => [
          'rate_label' => 'Flat rate',
          'rate_amount' => [
            'number' => '20',
            'currency_code' => 'USD',
          ],
        ],
      ],
      'status' => TRUE,
      'weight' => 0,
    ]);
    $another_shipping_method->save();
  }

  /**
   * Perform a mock request and return the request pushed to the stack.
   *
   * @param string $uri
   *   The uri.
   * @param string $method
   *   The method.
   * @param array $document
   *   The document.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   *
   * @throws \Exception
   */
  protected function getMockedRequest(string $uri, string $method, array $document = []): Request {
    $request = Request::create($uri, $method, [], [], [], [], $document ? Json::encode($document) : NULL);
    $request->headers->set('Content-Type', 'application/vnd.api+json');
    $request->headers->set('Accept', 'application/vnd.api+json');
    return $request;
  }

  /**
   * Process the request with the resource controller.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   *
   * @throws \Exception
   */
  protected function processRequest(Request $request): Response {
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Build a test JSON:API response document.
   *
   * @param array $attributes
   *   The resource object's attributes.
   * @param array $meta
   *   The meta constraints.
   * @param array $relationships
   *   The relationships.
   * @param array $links
   *   The links.
   *
   * @return array
   *   The document.
   */
  protected function buildResponseJsonApiDocument(array $attributes, array $meta = [], array $relationships = [], array $links = []) {
    $document = [
      'jsonapi' => [
        'meta' => [
          'links' => [
            'self' => ['href' => 'http://jsonapi.org/format/1.0/'],
          ],
        ],
        'version' => '1.0',
      ],
      'data' => [
        'id' => self::TEST_ORDER_UUID,
        'type' => 'order--default',
        'attributes' => $attributes + [
          'order_number' => NULL,
          'billing_information' => NULL,
          'shipping_information' => NULL,
          'shipping_method' => '',
          'total_paid' => NULL,
          'payment_instrument' => NULL,
          'order_total' => [
            'subtotal' => [
              'number' => '4.0',
              'currency_code' => 'USD',
              'formatted' => '$4.00',
            ],
            'adjustments' => [],
            'total' => [
              'number' => '4.0',
              'currency_code' => 'USD',
              'formatted' => '$4.00',
            ],
          ],
          'total_price' => [
            'number' => '4.0',
            'currency_code' => 'USD',
            'formatted' => '$4.00',
          ],
        ],
        'relationships' => [
          'coupons' => [
            'links' => [
              'self' => [
                'href' => 'http://localhost/jsonapi/orders/default/' . self::TEST_ORDER_UUID . '/relationships/coupons',
              ],
              'related' => [
                'href' => 'http://localhost/jsonapi/orders/default/' . self::TEST_ORDER_UUID . '/coupons',
              ],
            ],
          ],
          'order_items' => [
            'data' => [
              [
                'id' => self::TEST_ORDER_ITEM_UUID,
                'type' => 'order-item--default',
              ],
            ],
            'links' => [
              'self' => [
                'href' => 'http://localhost/jsonapi/orders/default/' . self::TEST_ORDER_UUID . '/relationships/order_items',
              ],
              'related' => [
                'href' => 'http://localhost/jsonapi/orders/default/' . self::TEST_ORDER_UUID . '/order_items',
              ],
            ],
          ],
          'store_id' => [
            'data' => [
              // Replaced before assertion.
              'id' => NULL,
              'type' => 'store--online',
            ],
            'links' => [
              'self' => [
                'href' => 'http://localhost/jsonapi/orders/default/' . self::TEST_ORDER_UUID . '/relationships/store_id',
              ],
              'related' => [
                'href' => 'http://localhost/jsonapi/orders/default/' . self::TEST_ORDER_UUID . '/store_id',
              ],
            ],
          ],
          'shipments' => [
            'links' => [
              'self' => [
                'href' => 'http://localhost/jsonapi/orders/default/' . self::TEST_ORDER_UUID . '/relationships/shipments',
              ],
              'related' => [
                'href' => 'http://localhost/jsonapi/orders/default/' . self::TEST_ORDER_UUID . '/shipments',
              ],
            ],
          ],
        ] + $relationships,
        'meta' => $meta + [
          'payment_options' => static::getPaymentOptionsMetaValue(),
          'shipping_rates' => static::getShippingMethodsMetaValue(),
        ],
        'links' => [
          'self' => [
            'href' => 'http://localhost/jsonapi/orders/default/' . self::TEST_ORDER_UUID,
          ],
        ] + $links,
      ],
      'links' => [
        'self' => [
          'href' => 'http://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID,
        ],
      ],
    ];
    if ($relationships === NULL) {
      unset($document['data']['relationships']);
    }
    return $document;
  }

  /**
   * Get the shipping-methods link.
   *
   * @return array
   *   The link.
   */
  protected static function getShippingMethodsLink() {
    return [
      'href' => 'http://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID . '/shipping-methods',
    ];
  }

  /**
   * Get the payment-create link.
   *
   * @return array
   *   The link.
   */
  protected static function getPaymentCreateLink() {
    return [
      'href' => 'http://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID . '/payment',
    ];
  }

  /**
   * Get the payment options meta.
   *
   * @return array
   *   The payment options meta.
   */
  protected static function getPaymentOptionsMetaValue(): array {
    return [
      [
        'id' => 'new--credit_card--onsite',
        'label' => 'Credit card',
        'payment_gateway_id' => 'onsite',
        'payment_method_id' => NULL,
        'payment_method_type_id' => 'credit_card',
      ],
    ];
  }

  /**
   * Get the shipping methods relationship.
   *
   * @return array
   *   The relationship.
   */
  protected static function getShippingMethodsMetaValue(): array {
    return [
      [
        'id' => '2--default',
        'shipping_method_id' => '2',
        'service' => [
          'id' => 'default',
          'label' => 'Flat rate',
        ],
        'original_amount' => [
          'number' => '20',
          'currency_code' => 'USD',
          'formatted' => '$20.00',
        ],
        'amount' => [
          'number' => '20',
          'currency_code' => 'USD',
          'formatted' => '$20.00',
        ],
        'delivery_date' => NULL,
        'description' => NULL,
        'data' => [],
      ],
      [
        'id' => '1--default',
        'shipping_method_id' => '1',
        'service' => [
          'id' => 'default',
          'label' => 'Flat rate',
        ],
        'original_amount' => [
          'number' => '5',
          'currency_code' => 'USD',
          'formatted' => '$5.00',
        ],
        'amount' => [
          'number' => '5',
          'currency_code' => 'USD',
          'formatted' => '$5.00',
        ],
        'delivery_date' => NULL,
        'description' => NULL,
        'data' => [],
      ],
    ];
  }

}

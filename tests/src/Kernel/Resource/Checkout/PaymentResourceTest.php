<?php declare(strict_types = 1);

namespace Drupal\Tests\commerce_api\Kernel\Resource\Checkout;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\Component\Serialization\Json;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group commerce_api
 */
final class PaymentResourceTest extends CheckoutResourceTestBase {

  protected const TEST_PAYMENT_METHOD_UUID = '99edaa72-85b0-4160-bdb5-56d846f3ba22';
  protected const TEST_OFFSITE_PAYMENT_METHOD_UUID = '672d1161-fb32-4f5e-972f-0caaa1e7a93e';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_payment_method');
    $this->installEntitySchema('commerce_payment');

    $manual_payment_gateway = PaymentGateway::create([
      'id' => 'cod',
      'label' => 'Manual',
      'plugin' => 'manual',
      'configuration' => [
        'display_label' => 'Cash on delivery',
        'instructions' => [
          'value' => 'Sample payment instructions.',
          'format' => 'plain_text',
        ],
      ],
    ]);
    $manual_payment_gateway->save();
    $offsite_payment_gateway = PaymentGateway::create([
      'id' => 'offsite',
      'label' => 'Off-site',
      'plugin' => 'example_offsite_redirect',
      'configuration' => [
        'redirect_method' => 'post',
        'payment_method_types' => ['credit_card'],
      ],
    ]);
    $offsite_payment_gateway->save();
    $stored_offsite_payment_gateway = PaymentGateway::create([
      'id' => 'stored_offsite',
      'label' => 'Stored Off-site',
      'plugin' => 'example_stored_offsite_redirect',
      'configuration' => [
        'redirect_method' => 'post',
        'payment_method_types' => ['credit_card'],
      ],
    ]);
    $stored_offsite_payment_gateway->save();

    $profile = Profile::create([
      'type' => 'customer',
      'uid' => 0,
      'address' => [
        'country_code' => 'US',
        'postal_code' => '53177',
        'locality' => 'Milwaukee',
        'address_line1' => 'Pabst Blue Ribbon Dr',
        'administrative_area' => 'WI',
        'given_name' => 'Frederick',
        'family_name' => 'Pabst',
      ],
    ]);
    assert($profile instanceof ProfileInterface);
    $profile->save();

    $payment_method = PaymentMethod::create([
      'uuid' => self::TEST_PAYMENT_METHOD_UUID,
      'uid' => 0,
      'type' => 'credit_card',
      'payment_gateway' => 'onsite',
      'card_type' => 'visa',
      'card_number' => '1111',
      'reusable' => TRUE,
      'expires' => strtotime('2028/03/24'),
      'billing_profile' => $profile,
    ]);
    $payment_method->save();
    $offsite_payment_method = PaymentMethod::create([
      'uuid' => self::TEST_OFFSITE_PAYMENT_METHOD_UUID,
      'uid' => 0,
      'type' => 'credit_card',
      'payment_gateway' => 'stored_offsite',
      'card_type' => 'visa',
      'card_number' => '4444',
      'reusable' => TRUE,
      'expires' => strtotime('2028/03/24'),
      'billing_profile' => $profile,
    ]);
    $offsite_payment_method->save();

  }

  /**
   * Tests creating payment by patching portions of `payment_instrument`.
   *
   * @dataProvider dataForPayment
   */
  public function testCreatePayment(
    string $payment_type,
    string $payment_gateway_id,
    ?string $payment_method_id,
    bool $capture,
    int $expected_status_code,
    string $expected_state
  ) {
    $checkout_request = $this->getMockedRequest(
      'https://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID,
      'PATCH',
      [
        'data' => [
          'type' => 'order--default',
          'id' => self::TEST_ORDER_UUID,
          'attributes' => [
            'payment_instrument' => [
              'payment_gateway_id' => $payment_gateway_id,
            ],
          ],
        ],
      ]
    );
    $response = $this->processRequest($checkout_request);
    assert($response instanceof Response);
    $this->assertEquals(200, $response->getStatusCode(), var_export((string) $response->getContent(), TRUE));
    $data = Json::decode((string) $response->getContent());

    if ($payment_type === 'payment--payment-manual') {
      $this->assertArrayHasKey('payment-create', $data['data']['links'], 'Manual payments have payment-create link');
    }
    else {
      $this->assertArrayNotHasKey('payment-create', $data['data']['links'], 'The payment-create link is not available until a payment method is set.');
    }

    // If we were provided a payment method, attach it to the order and verify
    // that the payment-create link is available.
    if ($payment_method_id !== NULL) {
      $checkout_request = $this->getMockedRequest(
        'https://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID,
        'PATCH',
        [
          'data' => [
            'type' => 'order--default',
            'id' => self::TEST_ORDER_UUID,
            'attributes' => [
              'payment_instrument' => [
                'payment_gateway_id' => $payment_gateway_id,
                'payment_method_id' => $payment_method_id,
              ],
            ],
          ],
        ]
      );
      $response = $this->processRequest($checkout_request);
      assert($response instanceof Response);
      $this->assertEquals(200, $response->getStatusCode());
      $data = Json::decode((string) $response->getContent());
      $this->assertArrayHasKey('payment-create', $data['data']['links'], var_export($data, TRUE));
    }

    $payment_request = $this->getMockedRequest(
      'https://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID . '/payment',
      'POST',
      [
        'data' => [
          'type' => $payment_type,
          'attributes' => [
            'capture' => $capture,
          ],
        ],
      ]
    );

    $response = $this->processRequest($payment_request);
    assert($response instanceof Response);
    $this->assertEquals($expected_status_code, $response->getStatusCode(), var_export($response, TRUE));
    if ($expected_status_code === 201) {
      $data = Json::decode((string) $response->getContent());
      $this->assertEquals($payment_type, $data['data']['type']);
      $this->assertEquals([
        'number' => '4',
        'currency_code' => 'USD',
        'formatted' => '$4.00',
      ], $data['data']['attributes']['amount']);
      $this->assertEquals($expected_state, $data['data']['attributes']['state']);
    }
  }

  /**
   * Tests creating payment using data from `payment_options` meta.
   *
   * @dataProvider dataForPayment
   */
  public function testCreatePaymentWithPaymentOptions(
    string $payment_type,
    string $payment_gateway_id,
    ?string $payment_method_id,
    bool $capture,
    int $expected_status_code,
    string $expected_state
  ) {
    $test_user = $this->createUser();
    $this->container->get('commerce_order.order_assignment')->assign($this->order, $test_user);

    $entity_repository = $this->container->get('entity.repository');
    $onsite_payment_method = $entity_repository->loadEntityByUuid('commerce_payment_method', self::TEST_PAYMENT_METHOD_UUID);
    assert($onsite_payment_method instanceof PaymentMethod);
    $onsite_payment_method->setOwner($test_user)->save();

    $offsite_payment_method = $entity_repository->loadEntityByUuid('commerce_payment_method', self::TEST_OFFSITE_PAYMENT_METHOD_UUID);
    assert($offsite_payment_method instanceof PaymentMethod);
    $offsite_payment_method->setOwner($test_user)->save();

    $checkout_request = $this->getMockedRequest(
      'https://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID,
      'GET'
    );
    $response = $this->processRequest($checkout_request);
    assert($response instanceof Response);
    $this->assertEquals(200, $response->getStatusCode(), var_export((string) $response->getContent(), TRUE));
    $data = Json::decode((string) $response->getContent());

    $selected_payment_option = NULL;
    foreach ($data['data']['meta']['payment_options'] as $payment_option) {
      if ($payment_option['payment_gateway_id'] === $payment_gateway_id && $payment_option['payment_method_id'] === $payment_method_id) {
        $selected_payment_option = $payment_option;
      }
    }
    $this->assertNotNull($selected_payment_option);

    $checkout_request = $this->getMockedRequest(
      'https://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID,
      'PATCH',
      [
        'data' => [
          'type' => 'order--default',
          'id' => self::TEST_ORDER_UUID,
          'attributes' => [
            'payment_instrument' => $selected_payment_option,
          ],
        ],
      ]
    );
    $response = $this->processRequest($checkout_request);
    assert($response instanceof Response);
    $this->assertEquals(200, $response->getStatusCode(), var_export((string) $response->getContent(), TRUE));
    $data = Json::decode((string) $response->getContent());

    if ($payment_type === 'payment--payment-manual') {
      $this->assertArrayHasKey('payment-create', $data['data']['links']);
    }
    elseif ($payment_method_id !== NULL) {
      $this->assertArrayHasKey('payment-create', $data['data']['links']);
    }

    $payment_request = $this->getMockedRequest(
      'https://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID . '/payment',
      'POST',
      [
        'data' => [
          'type' => $payment_type,
          'attributes' => [
            'capture' => $capture,
          ],
        ],
      ]
    );

    $response = $this->processRequest($payment_request);
    assert($response instanceof Response);
    $this->assertEquals($expected_status_code, $response->getStatusCode(), var_export($response, TRUE));
    if ($expected_status_code === 201) {
      $data = Json::decode((string) $response->getContent());
      $this->assertEquals($payment_type, $data['data']['type']);
      $this->assertEquals([
        'number' => '4',
        'currency_code' => 'USD',
        'formatted' => '$4.00',
      ], $data['data']['attributes']['amount']);
      $this->assertEquals($expected_state, $data['data']['attributes']['state']);
    }
  }

  /**
   * Tests paying for an order and using `order_id` include.
   *
   * @dataProvider dataForTransactionMode
   */
  public function testOrderInclude(?bool $capture) {
    $entity_repository = $this->container->get('entity.repository');
    $payment_method = $entity_repository->loadEntityByUuid('commerce_payment_method', self::TEST_PAYMENT_METHOD_UUID);

    $this->order->set('payment_gateway', 'onsite');
    $this->order->set('payment_method', $payment_method->id());
    $this->order->save();

    $document = [
      'data' => [
        'type' => 'payment--payment-default',
        'attributes' => [
          'capture' => $capture,
        ],
      ],
    ];

    $request = $this->getMockedRequest(
      'https://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID . '/payment?include=order_id',
      'POST',
      $document
    );
    $response = $this->processRequest($request);
    $data = Json::decode((string) $response->getContent());
    $this->assertArrayHasKey('included', $data, var_export($response, TRUE));
    $order = $data['included'][0];
    $this->assertEquals(self::TEST_ORDER_UUID, $order['id']);
    $this->assertEquals('completed', $order['attributes']['state']);

    if ($capture === FALSE) {
      $this->assertNull($order['attributes']['total_paid'], 'Authorization only payments do not mark the order as paid.');
    }
    else {
      $this->assertEquals($order['attributes']['total_price'], $order['attributes']['total_paid'], var_export($order, TRUE));
    }

    $this->assertEquals([
      'constraints',
      'payment_options',
      'shipping_rates',
    ], array_keys($order['meta']), 'Included order still has meta attached');
  }

  /**
   * Tests disallowed fields.
   *
   * @dataProvider dataForDisallowedFields
   */
  public function testDisallowedFields(array $attributes, int $expected_status_code, string $expected_error = '') {
    $entity_repository = $this->container->get('entity.repository');
    $payment_method = $entity_repository->loadEntityByUuid('commerce_payment_method', self::TEST_PAYMENT_METHOD_UUID);

    $this->order->set('payment_gateway', 'onsite');
    $this->order->set('payment_method', $payment_method->id());
    $this->order->save();

    $document = [
      'data' => [
        'type' => 'payment--payment-default',
        'attributes' => $attributes,
      ],
    ];

    $request = $this->getMockedRequest(
      'https://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID . '/payment',
      'POST',
      $document
    );
    $response = $this->processRequest($request);
    $data = Json::decode((string) $response->getContent());
    $this->assertEquals($expected_status_code, $response->getStatusCode(), var_export($data, TRUE));
    if ($expected_status_code !== 201) {
      $this->assertCount(1, $data['errors']);
      $error = $data['errors'][0];
      $this->assertEquals($expected_error, $error['detail']);
    }
  }

  /**
   * Tests the order version mismatch exception.
   */
  public function testOrderVersionMismatchException() {
    $entity_repository = $this->container->get('entity.repository');
    $payment_method = $entity_repository->loadEntityByUuid('commerce_payment_method', self::TEST_PAYMENT_METHOD_UUID);
    $this->order->set('payment_gateway', 'onsite');
    $this->order->set('payment_method', $payment_method->id());
    $this->order->save();

    $document = [
      'data' => [
        'type' => 'payment--payment-default',
        'attributes' => ['capture' => TRUE],
      ],
    ];

    $request = $this->getMockedRequest(
      'https://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID . '/payment',
      'POST',
      $document
    );
    $this->order = $this->reloadEntity($this->order);
    $this->order->setVersion(1);
    $response = $this->processRequest($request);
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->loadMultipleByOrder($this->order);
    $this->order = $this->reloadEntity($this->order);
    $this->assertEquals(2, $this->order->getVersion());
    $this->assertCount(0, $payment);
    $data = Json::decode((string) $response->getContent());
    $this->assertEquals(409, $response->getStatusCode(), var_export($data, TRUE));
  }

  /**
   * Test parameters.
   *
   * @return \Generator
   *   The test data.
   */
  public function dataForPayment(): \Generator {
    // Payment with payment method, immediate capture.
    yield [
      'payment--payment-default',
      'onsite',
      self::TEST_PAYMENT_METHOD_UUID,
      TRUE,
      201,
      'completed',
    ];
    // Payment with payment method, authorization only.
    yield [
      'payment--payment-default',
      'onsite',
      self::TEST_PAYMENT_METHOD_UUID,
      FALSE,
      201,
      'authorization',
    ];
    // Payment with payment method, missing payment method.
    yield [
      'payment--payment-default',
      'onsite',
      NULL,
      FALSE,
      422,
      'completed',
    ];
    // Manual payment.
    yield [
      'payment--payment-manual',
      'cod',
      NULL,
      TRUE,
      201,
      'pending',
    ];
    // Offsite payment gateway.
    yield [
      'payment--payment-default',
      'offsite',
      NULL,
      TRUE,
      400,
      'completed',
    ];
    // Offsite with stored payment method support, no payment method.
    yield [
      'payment--payment-default',
      'stored_offsite',
      NULL,
      TRUE,
      422,
      'completed',
    ];
    // Offsite with stored payment method support.
    yield [
      'payment--payment-default',
      'stored_offsite',
      self::TEST_OFFSITE_PAYMENT_METHOD_UUID,
      TRUE,
      201,
      'completed',
    ];
  }

  /**
   * Test parameters for transaction modes.
   *
   * @return \Generator
   *   The test data.
   */
  public function dataForTransactionMode(): \Generator {
    // Capture.
    yield [TRUE];
    // Authorize.
    yield [FALSE];
    // None specified, default to capture.
    yield [NULL];
  }

  /**
   * Test parameters for disallowed fields.
   *
   * @return \Generator
   *   The test data.
   */
  public function dataForDisallowedFields(): \Generator {
    yield [
      ['capture' => TRUE],
      201,
    ];
    yield [
      ['capture' => TRUE, 'state' => 'bar'],
      422,
      'The following fields are not allowed: state',
    ];
    yield [
      [],
      201,
    ];
    yield [
      ['remote_id' => '123'],
      422,
      'The following fields are not allowed: remote_id',
    ];
    yield [
      ['remote_id' => '123', 'remote_state' => 'foo'],
      422,
      'The following fields are not allowed: remote_id, remote_state',
    ];
  }

}

<?php declare(strict_types = 1);

namespace Drupal\Tests\commerce_api\Kernel\Field;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\Tests\commerce_api\Kernel\KernelTestBase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Test the payment instrument field.
 *
 * @group commerce_api
 */
final class PaymentInstrumentFieldTest extends KernelTestBase {

  protected const TEST_PAYMENT_METHOD_UUID = '99edaa72-85b0-4160-bdb5-56d846f3ba22';
  protected const TEST_OFFSITE_PAYMENT_METHOD_UUID = '672d1161-fb32-4f5e-972f-0caaa1e7a93e';

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_payment_example',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_payment_method');
    PaymentGateway::create([
      'id' => 'cod',
      'label' => 'Manual',
      'plugin' => 'manual',
    ])->save();
    PaymentGateway::create([
      'id' => 'onsite',
      'plugin' => 'example_onsite',
    ])->save();
    PaymentGateway::create([
      'id' => 'example_offsite',
      'plugin' => 'example_offsite_redirect',
    ])->save();
    PaymentGateway::create([
      'id' => 'stored_offsite',
      'plugin' => 'example_stored_offsite_redirect',
    ])->save();

    PaymentMethod::create([
      'uuid' => self::TEST_PAYMENT_METHOD_UUID,
      'uid' => 0,
      'type' => 'credit_card',
      'payment_gateway' => 'onsite',
      'card_type' => 'visa',
      'card_number' => '1111',
      'reusable' => TRUE,
      'expires' => strtotime('2028/03/24'),
      'billing_profile' => Profile::create([
        'type' => 'customer',
        'uid' => 0,
      ]),
    ])->save();
    PaymentMethod::create([
      'uuid' => self::TEST_OFFSITE_PAYMENT_METHOD_UUID,
      'uid' => 0,
      'type' => 'credit_card',
      'payment_gateway' => 'stored_offsite',
      'card_type' => 'visa',
      'card_number' => '4444',
      'reusable' => TRUE,
      'expires' => strtotime('2028/03/24'),
      'billing_profile' => Profile::create([
        'type' => 'customer',
        'uid' => 0,
      ]),
    ])->save();
  }

  /**
   * Tests the billing order profile through the order profile field.
   *
   * @dataProvider dataPaymentInstruments
   */
  public function testPaymentInstrument(
    ?string $payment_gateway_id,
    ?string $payment_method_id,
    string $payment_method_type,
    array $payment_details,
    bool $expects_payment_method,
    ?string $expected_error
  ) {
    $order = $this->createOrder();
    $this->assertTrue($order->hasField('payment_instrument'));

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
    $profile = $this->reloadEntity($profile);
    $order->setBillingProfile($profile);

    if ($expected_error) {
      $this->expectException(UnprocessableEntityHttpException::class);
      $this->expectExceptionMessage($expected_error);
    }

    $order->set('payment_instrument', [
      'payment_gateway_id' => $payment_gateway_id,
      'payment_method_type' => $payment_method_type,
      'payment_method_id' => $payment_method_id,
      'payment_details' => $payment_details,
    ]);

    if ($expects_payment_method) {
      /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
      $payment_method = $order->get('payment_method')->entity;
      $this->assertInstanceOf(PaymentMethodInterface::class, $payment_method);
      $this->assertEquals($payment_method->getBillingProfile(), $order->getBillingProfile());
      $this->assertEquals($payment_method_type, $payment_method->bundle());
      $this->assertEquals($payment_gateway_id, $payment_method->getPaymentGatewayId());
    }
  }

  /**
   * Data for payment instrument testing.
   *
   * @return \Generator
   *   The test data.
   */
  public function dataPaymentInstruments(): \Generator {
    yield [
      NULL,
      NULL,
      '',
      [],
      FALSE,
      'You must specify a `payment_gateway_id`',
    ];
    yield [
      'onsite',
      NULL,
      'credit_card',
      [
        'type' => 'visa',
        'number' => '4111111111111111',
        'expiration' => [
          'month' => '05',
          'year' => '2026',
        ],
      ],
      TRUE,
      NULL,
    ];
    yield [
      'onsite',
      NULL,
      'credit_card',
      [
        'type' => 'visa',
        'number' => '4111111111111111',
      ],
      TRUE,
      'We encountered an unexpected error processing your payment method. Please try again later.',
    ];
    yield [
      'example_offsite',
      NULL,
      'credit_card',
      [],
      FALSE,
      NULL,
    ];
    yield [
      'onsite',
      self::TEST_PAYMENT_METHOD_UUID,
      'credit_card',
      [],
      TRUE,
      NULL,
    ];
    yield [
      'stored_offsite',
      self::TEST_OFFSITE_PAYMENT_METHOD_UUID,
      'credit_card',
      [],
      TRUE,
      NULL,
    ];
    yield [
      'stored_offsite',
      NULL,
      'credit_card',
      [
        'foo' => 'bar',
      ],
      TRUE,
      'The referenced payment gateway does not support creating payment methods.',
    ];
    yield [
      'stored_offsite',
      self::TEST_PAYMENT_METHOD_UUID,
      'credit_card',
      [],
      TRUE,
      'Provided payment method does not belong to the payment gateway.',
    ];
    yield [
      'onsite',
      '38ca7cbb-25ce-4fa1-9872-a9db41ede2d6',
      'credit_card',
      [],
      TRUE,
      'Provided payment method does not exist',
    ];
  }

  /**
   * Creates a test order.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  private function createOrder(): OrderInterface {
    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'store_id' => $this->store,
    ]);
    assert($order instanceof OrderInterface);
    return $order;
  }

}

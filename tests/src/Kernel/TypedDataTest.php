<?php declare(strict_types = 1);

namespace Drupal\Tests\commerce_api\Kernel;

use Drupal\commerce_api\Plugin\DataType\Adjustment as AdjustmentDataType;
use Drupal\commerce_api\Plugin\DataType\PaymentOption as PaymentOptionDataType;
use Drupal\commerce_api\Plugin\DataType\Price as PriceDataType;
use Drupal\commerce_api\Plugin\DataType\ShippingRate as ShippingRateDataType;
use Drupal\commerce_api\TypedData\AdjustmentDataDefinition;
use Drupal\commerce_api\TypedData\PaymentOptionDefinition;
use Drupal\commerce_api\TypedData\PriceDataDefinition;
use Drupal\commerce_order\Adjustment as AdjustmentValueObject;
use Drupal\commerce_price\Price as PriceValueObject;
use Drupal\commerce_api\TypedData\ShippingRateDefinition;
use Drupal\commerce_payment\PaymentOption as PaymentOptionValueObject;
use Drupal\commerce_shipping\ShippingRate as ShippingRateValueObject;
use Drupal\commerce_shipping\ShippingService as ShippingServiceValueObject;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Tests the TypedData implementations.
 *
 * @group commerce_api
 */
final class TypedDataTest extends KernelTestBase {

  /**
   * The serializer.
   *
   * @var object|\Symfony\Component\Serializer\Serializer
   */
  private $serializer;

  /**
   * The typed data manager.
   *
   * @var \Drupal\Core\TypedData\TypedDataManager
   */
  private $typedDataManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->config('system.date')
      ->set('timezone.default', @date_default_timezone_get())
      ->save();
    $this->serializer = $this->container->get('serializer');
    $this->typedDataManager = $this->container->get('typed_data_manager');
  }

  /**
   * Test the price data definition and data type.
   */
  public function testPriceDataDefinition(): void {
    $price_object = new PriceValueObject('5.99', 'USD');
    $price_typed_data = $this->typedDataManager->create(PriceDataDefinition::create(), $price_object->toArray());
    $this->assertInstanceOf(PriceDataType::class, $price_typed_data);
    $price_normalized = $this->serializer->normalize($price_typed_data);
    $this->assertEquals([
      'number' => '5.99',
      'currency_code' => 'USD',
      'formatted' => '$5.99',
    ], $price_normalized);
  }

  /**
   * Test the adjustment data definition and data type.
   */
  public function testAdjustmentDataDefinition(): void {
    $adjustment_object = new AdjustmentValueObject([
      'type' => 'custom',
      'label' => '10% off',
      'amount' => new PriceValueObject('-1.00', 'USD'),
      'percentage' => '0.1',
    ]);
    $adjustment_typed_data = $this->typedDataManager->create(AdjustmentDataDefinition::create(), $adjustment_object->toArray());
    $this->assertInstanceOf(AdjustmentDataType::class, $adjustment_typed_data);
    $adjustment_normalized = $this->serializer->normalize($adjustment_typed_data);
    $this->assertEquals([
      'type' => 'custom',
      'label' => '10% off',
      'amount' => [
        'number' => '-1.00',
        'currency_code' => 'USD',
        'formatted' => '-$1.00',
      ],
      'percentage' => '0.1',
      'total' => [
        'number' => '-1.00',
        'currency_code' => 'USD',
        'formatted' => '-$1.00',
      ],
      'source_id' => NULL,
      'included' => FALSE,
      'locked' => TRUE,
    ], $adjustment_normalized);
  }

  /**
   * Test the shipping rate data definition and data type.
   */
  public function testShippingRateDefinition(): void {
    $definition = [
      'id' => '717c2f9',
      'shipping_method_id' => 'standard',
      'service' => new ShippingServiceValueObject('test', 'Test'),
      'original_amount' => new PriceValueObject('15.00', 'USD'),
      'amount' => new PriceValueObject('10.00', 'USD'),
      'description' => 'Delivery in 3-5 business days.',
      'delivery_date' => new DrupalDateTime('2016-11-24', 'UTC', ['langcode' => 'en']),
      'data' => [
        'arbitrary_data' => 10,
      ],
    ];
    $shipping_rate_object = new ShippingRateValueObject($definition);
    $shipping_rate_typed_data = $this->typedDataManager->create(ShippingRateDefinition::create(), $shipping_rate_object->toArray());
    $this->assertInstanceOf(ShippingRateDataType::class, $shipping_rate_typed_data);
    $normalized = $this->serializer->normalize($shipping_rate_typed_data);
    $this->assertEquals([
      'id' => '717c2f9',
      'shipping_method_id' => 'standard',
      'description' => 'Delivery in 3-5 business days.',
      'service' => [
        'id' => 'test',
        'label' => 'Test',
      ],
      'original_amount' => [
        'number' => '15.00',
        'currency_code' => 'USD',
        'formatted' => '$15.00',
      ],
      'amount' => [
        'number' => '10.00',
        'currency_code' => 'USD',
        'formatted' => '$10.00',
      ],
      'delivery_date' => '2016-11-24T11:00:00+11:00',
      'data' => [
        'arbitrary_data' => 10,
      ],
    ], $normalized);
  }

  /**
   * Test the payment option definition and data type.
   */
  public function testPaymentOptionDefinition(): void {
    $definition = [
      'id' => 'cash_on_delivery',
      'label' => 'Cash on delivery',
      'payment_gateway_id' => 'cash_on_delivery',
    ];
    $payment_option = new PaymentOptionValueObject($definition);
    $payment_option_typed_data = $this->typedDataManager->create(PaymentOptionDefinition::create(), [
      'id' => $payment_option->getId(),
      'label' => $payment_option->getLabel(),
      'payment_gateway_id' => $payment_option->getPaymentGatewayId(),
      'payment_method_type_id' => $payment_option->getPaymentMethodTypeId(),
      'payment_method_id' => $payment_option->getPaymentMethodId(),
    ]);
    $this->assertInstanceOf(PaymentOptionDataType::class, $payment_option_typed_data);
    $normalized = $this->serializer->normalize($payment_option_typed_data);
    $this->assertEquals([
      'id' => 'cash_on_delivery',
      'label' => 'Cash on delivery',
      'payment_gateway_id' => 'cash_on_delivery',
      'payment_method_id' => NULL,
      'payment_method_type_id' => NULL,
    ], $normalized);
  }

}

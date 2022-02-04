<?php declare(strict_types = 1);

namespace Drupal\commerce_api\Plugin\DataType;

use Drupal\Core\TypedData\Plugin\DataType\Map;

/**
 * @DataType(
 *   id = "payment_option",
 *   label = @Translation("Payment option"),
 *   description = @Translation("Payment option."),
 *   definition_class = "\Drupal\commerce_api\TypedData\PaymentOptionDefinition"
 * )
 */
final class PaymentOption extends Map {

  /**
   * The value.
   *
   * @var array
   *
   * @note ::getValue() assumes the `value` property, but it doesn't exist.
   */
  protected $value = [];

}

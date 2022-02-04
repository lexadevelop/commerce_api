<?php declare(strict_types = 1);

namespace Drupal\commerce_api\Plugin\DataType;

use Drupal\Core\TypedData\Plugin\DataType\Map;

/**
 * @DataType(
 *   id = "shipping_service",
 *   label = @Translation("Shipping service"),
 *   description = @Translation("Tax number information."),
 *   definition_class = "\Drupal\commerce_api\TypedData\ShippingServiceDefinition"
 * )
 */
final class ShippingService extends Map {

  /**
   * The value.
   *
   * @var array
   *
   * @note ::getValue() assumes the `value` property, but it doesn't exist.
   */
  protected $value = [];

}

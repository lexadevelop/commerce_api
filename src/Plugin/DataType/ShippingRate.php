<?php declare(strict_types = 1);

namespace Drupal\commerce_api\Plugin\DataType;

use Drupal\commerce_price\Price as PriceValueObject;
use Drupal\commerce_shipping\ShippingService as ShippingServiceValueObject;
use Drupal\Core\TypedData\Plugin\DataType\Map;

/**
 * @DataType(
 *   id = "shipping_rate",
 *   label = @Translation("Shipping rate"),
 *   description = @Translation("Shipping rate information."),
 *   definition_class = "\Drupal\commerce_api\TypedData\ShippingRateDefinition"
 * )
 */
final class ShippingRate extends Map {

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE) {
    foreach ($values as $key => $value) {
      if ($value instanceof ShippingServiceValueObject) {
        // @note there is no toArray method.
        $values[$key] = [
          'id' => $value->getId(),
          'label' => $value->getLabel(),
        ];
      }
      elseif ($value instanceof PriceValueObject) {
        $values[$key] = $value->toArray();
      }
    }
    parent::setValue($values, $notify);
  }

  /**
   * The value.
   *
   * @var array
   *
   * @note ::getValue() assumes the `value` property, but it doesn't exist.
   */
  protected $value = [];

}

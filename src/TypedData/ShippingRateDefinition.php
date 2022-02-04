<?php declare(strict_types = 1);

namespace Drupal\commerce_api\TypedData;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionBase;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;

final class ShippingRateDefinition extends ComplexDataDefinitionBase {

  /**
   * {@inheritdoc}
   */
  public static function create($type = 'shipping_rate') {
    $definition['type'] = $type;
    return new static($definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {
    $properties = [];
    $properties['id'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('ID'))
      ->setReadOnly(TRUE);
    $properties['shipping_method_id'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setReadOnly(TRUE);
    $properties['service'] = ShippingServiceDefinition::create()
      ->setLabel(new TranslatableMarkup('Shipping service'))
      ->setReadOnly(TRUE);
    $properties['original_amount'] = PriceDataDefinition::create()
      ->setLabel(new TranslatableMarkup('Amount'));
    $properties['amount'] = PriceDataDefinition::create()
      ->setLabel(new TranslatableMarkup('Amount'));
    $properties['description'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setReadOnly(TRUE);
    $properties['delivery_date'] = DataDefinition::create('datetime_iso8601')
      ->setLabel(new TranslatableMarkup('Delivery date'));
    $properties['data'] = MapDataDefinition::create()
      ->setLabel(new TranslatableMarkup('Data'));
    return $properties;
  }

}

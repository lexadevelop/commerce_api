<?php declare(strict_types = 1);

namespace Drupal\commerce_api\TypedData;

use Drupal\Core\TypedData\ComplexDataDefinitionBase;
use Drupal\Core\TypedData\DataDefinition;

final class ShippingServiceDefinition extends ComplexDataDefinitionBase {

  /**
   * {@inheritdoc}
   */
  public static function create($type = 'shipping_service') {
    $definition['type'] = $type;
    return new static($definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {
    $properties = [];
    $properties['id'] = DataDefinition::create('string')
      ->setLabel(t('ID'))
      ->setReadOnly(TRUE);
    $properties['label'] = DataDefinition::create('string')
      ->setLabel(t('Label'))
      ->setReadOnly(TRUE);
    return $properties;
  }

}

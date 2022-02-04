<?php declare(strict_types = 1);

namespace Drupal\commerce_api\TypedData;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionBase;
use Drupal\Core\TypedData\DataDefinition;

final class PaymentOptionDefinition extends ComplexDataDefinitionBase {

  /**
   * {@inheritdoc}
   */
  public static function create($type = 'payment_option') {
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
    $properties['label'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setReadOnly(TRUE);
    $properties['payment_gateway_id'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Payment gateway ID'))
      ->setReadOnly(TRUE);
    $properties['payment_method_id'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Payment method ID'));
    $properties['payment_method_type_id'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Payment method type ID'));

    return $properties;
  }

}

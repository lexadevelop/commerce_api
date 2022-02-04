<?php declare(strict_types = 1);

namespace Drupal\commerce_api\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * @FieldType(
 *   id = "payment_instrument",
 *   label = @Translation("Payment instrument"),
 *   no_ui = TRUE,
 *   list_class = "\Drupal\commerce_api\Plugin\Field\FieldType\PaymentInstrumentItemList",
 * )
 *
 * @property string $payment_gateway_id
 * @property string $payment_method_id
 * @property string $payment_method_type
 * @property array $payment_details
 */
final class PaymentInstrument extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['payment_gateway_id'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Payment gateway ID'));
    $properties['payment_method_id'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Payment method ID'));
    $properties['payment_method_type'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Payment method type'));
    $properties['payment_details'] = MapDataDefinition::create()
      ->setLabel(new TranslatableMarkup('Payment details'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return NULL;
  }

}

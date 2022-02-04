<?php declare(strict_types = 1);

namespace Drupal\commerce_api\Plugin\Field\FieldType;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileInterface;

final class OrderProfileItemList extends FieldItemList {
  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue() {
    $profile = $this->getProfile();
    $value = [
      'entity' => $profile,
    ];
    $supported_field_types = ['address', 'commerce_tax_number'];
    foreach ($profile->getFieldDefinitions() as $field_name => $field_definition) {
      if (!in_array($field_definition->getType(), $supported_field_types, TRUE)) {
        continue;
      }
      if ($profile->get($field_name)->isEmpty()) {
        continue;
      }
      $value[$field_name] = $profile->get($field_name)->first()->getValue();
    }
    $this->list[0] = $this->createItem(0, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE) {
    $profile = NULL;
    // Only set the profile if actual values were passed.
    if (is_array($values) && !isset($values['entity'])) {
      $values['entity'] = $this->getProfile();
      $profile = $values['entity'];
    }
    parent::setValue($values, $notify);
    // Make sure to mark this as computed, overriding the method prevents
    // ComputedItemListTrait::setValue from running, which performs this flag.
    $this->valueComputed = TRUE;

    // Computed values are ignored.
    if ($profile && is_array($values)) {
      foreach ($values as $property_name => $property_value) {
        if ($property_name === 'entity') {
          continue;
        }
        $profile->set($property_name, $property_value);
      }
    }

    $order = $this->getEntity();
    assert($order instanceof OrderInterface);
    $profile_type = $this->getSetting('profile_type') ?: 'billing';
    if ($profile_type === 'billing') {
      // Note that we don't call setBillingProfile() here on purpose so we can
      // support nullifying the profile.
      $order->set('billing_profile', $profile);
    }
    else {
      $order->setData('shipping_profile', $profile);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onChange($delta) {
    parent::onChange($delta);
    // When the field value is set via the magic setter, only the onChange()
    // method is invoked but not setValue().
    $this->setValue($this->list[$delta]->getValue());
  }

  /**
   * {@inheritdoc}
   */
  public function applyDefaultValue($notify = TRUE) {
    $this->computeValue();
    return $this;
  }

  /**
   * Get the profile for the field.
   *
   * @return \Drupal\profile\Entity\ProfileInterface
   *   The profile.
   */
  private function getProfile(): ProfileInterface {
    $order = $this->getEntity();
    assert($order instanceof OrderInterface);
    $profile_type = $this->getSetting('profile_type') ?: 'billing';
    $collected_profiles = $order->collectProfiles();
    $profile = $collected_profiles[$profile_type] ?? NULL;
    if ($profile === NULL) {
      $profile = Profile::create([
        'type' => $this->getSetting('profile_bundle'),
        'uid' => 0,
      ]);
    }
    return $profile;
  }

}

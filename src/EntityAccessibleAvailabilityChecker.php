<?php declare(strict_types = 1);

namespace Drupal\commerce_api;

use Drupal\commerce_order\AvailabilityCheckerInterface;
use Drupal\commerce\Context;
use Drupal\commerce_order\AvailabilityResult;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityPublishedInterface;

final class EntityAccessibleAvailabilityChecker implements AvailabilityCheckerInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(OrderItemInterface $order_item) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function check(OrderItemInterface $order_item, Context $context) {
    $purchasable_entity = $order_item->getPurchasedEntity();
    // If the purchasable entity is publishable, immediately return false if
    // it is unpublished and skip entity access checks for performance.
    if ($purchasable_entity instanceof EntityPublishedInterface && $purchasable_entity->isPublished() === FALSE) {
      return AvailabilityResult::unavailable();
    }
    if (!$purchasable_entity->access('view', $context->getCustomer())) {
      return AvailabilityResult::unavailable();
    }

    return AvailabilityResult::neutral();
  }

}

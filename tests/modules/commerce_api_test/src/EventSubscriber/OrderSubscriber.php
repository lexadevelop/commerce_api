<?php

namespace Drupal\commerce_api_test\EventSubscriber;

use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\commerce_order\Exception\OrderVersionMismatchException;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderSubscriber implements EventSubscriberInterface {

  /**
   * The state key/value store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a new OrderSubscriber object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state key/value store.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      OrderEvents::ORDER_PRESAVE => ['onPresave'],
    ];
  }

  /**
   * Simulates an order version mismatch exception on demand for tests.
   *
   * @param \Drupal\commerce_order\Event\OrderEvent $event
   *   The order event.
   */
  public function onPresave(OrderEvent $event) {
    if ($this->state->get('trigger_order_version_mismatch', FALSE)) {
      throw new OrderVersionMismatchException('The order has either been modified by another user, or you have already submitted modifications. As a result, your changes cannot be saved.');
    }
  }

}

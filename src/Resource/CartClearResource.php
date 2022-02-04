<?php

namespace Drupal\commerce_api\Resource;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Exception\OrderVersionMismatchException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\jsonapi\ResourceResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CartClearResource extends CartResourceBase {

  /**
   * Clear a cart's items.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The cart.
   *
   * @return \Drupal\jsonapi\ResourceResponse
   *   The response.
   */
  public function process(OrderInterface $commerce_order): ResourceResponse {
    try {
      $this->cartManager->emptyCart($commerce_order);
    }
    catch (EntityStorageException $exception) {
      if ($exception->getPrevious() instanceof OrderVersionMismatchException) {
        throw new ConflictHttpException($exception->getMessage(), $exception);
      }
      throw $exception;
    }
    return new ResourceResponse(NULL, 204);
  }

}

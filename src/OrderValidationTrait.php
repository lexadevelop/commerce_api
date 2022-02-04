<?php

namespace Drupal\commerce_api;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\jsonapi\Exception\UnprocessableHttpEntityException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Provides a helper method to validate an order.
 *
 * This trait is copied/adapted from JSON API with special handling for the
 * OrderVersionConstraint for which we return a different exception.
 */
trait OrderValidationTrait {

  /**
   * Verifies the order does not violate any validation constraints.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   * @param string[] $field_names
   *   (optional) An array of field names. If specified, filters the violations
   *   list to include only this set of fields. Defaults to NULL,
   *   which means that all violations will be reported.
   *
   * @throws \Drupal\jsonapi\Exception\UnprocessableHttpEntityException
   *   Thrown when violations remain after filtering.
   * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException
   *   Thrown when an OrderVersion violation is found.
   *
   * @see \Drupal\jsonapi\Entity\EntityValidationTrait::validate()
   */
  protected static function validate(EntityInterface $entity, array $field_names = NULL) {
    assert($entity instanceof OrderInterface);
    $violations = $entity->validate();

    // Remove violations of inaccessible fields as they cannot stem from our
    // changes.
    $violations->filterByFieldAccess();

    // Filter violations based on the given fields.
    if ($field_names !== NULL) {
      $violations->filterByFields(
        array_diff(array_keys($entity->getFieldDefinitions()), $field_names)
      );
    }

    if (count($violations) > 0) {
      // Instantiate the OrderVersion constraint, to see if one of the
      // violation messages matches the order version constraint message.
      // Unfortunately, that is our only way of determining whether an
      // OrderVersion constraint violation occurred.
      $constraint_manager = $entity->getTypedData()->getTypedDataManager()->getValidationConstraintManager();
      $order_version_constraint = $constraint_manager->create('OrderVersion', []);
      foreach ($violations as $violation) {
        if ($violation->getMessageTemplate() === $order_version_constraint->message) {
          throw new ConflictHttpException($violation->getMessage());
        }
      }

      // Instead of returning a generic 400 response we use the more specific
      // 422 Unprocessable Entity code from RFC 4918. That way clients can
      // distinguish between general syntax errors in bad serializations (code
      // 400) and semantic errors in well-formed requests (code 422).
      // @see \Drupal\jsonapi\Normalizer\UnprocessableHttpEntityExceptionNormalizer
      $exception = new UnprocessableHttpEntityException();
      $exception->setViolations($violations);
      throw $exception;
    }
  }

}

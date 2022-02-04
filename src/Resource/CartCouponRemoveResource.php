<?php

namespace Drupal\commerce_api\Resource;

use Drupal\commerce_api\EntityResourceShim;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Exception\OrderVersionMismatchException;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi_resources\Resource\EntityResourceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CartCouponRemoveResource extends EntityResourceBase implements ContainerInjectionInterface {

  /**
   * The JSON:API controller shim.
   *
   * @var \Drupal\commerce_api\EntityResourceShim
   */
  protected $inner;

  /**
   * Constructs a new CartCouponAddResource object.
   *
   * @param \Drupal\commerce_api\EntityResourceShim $jsonapi_controller
   *   The JSON:API controller shim.
   */
  public function __construct(EntityResourceShim $jsonapi_controller) {
    $this->inner = $jsonapi_controller;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_api.jsonapi_controller_shim')
    );
  }

  /**
   * Processes the request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order.
   *
   * @return \Drupal\jsonapi\ResourceResponse
   *   The response.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\TypedData\Exception\ReadOnlyException
   */
  public function process(Request $request, OrderInterface $commerce_order) {
    $resource_type = $this->resourceTypeRepository->get($commerce_order->getEntityTypeId(), $commerce_order->bundle());
    $internal_relationship_field_name = $resource_type->getInternalName('coupons');
    $field_list = $commerce_order->{$internal_relationship_field_name};
    assert($field_list instanceof EntityReferenceFieldItemListInterface);
    $field_list->setValue(NULL);
    try {
      $commerce_order->save();
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

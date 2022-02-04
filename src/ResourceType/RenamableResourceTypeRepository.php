<?php

namespace Drupal\commerce_api\ResourceType;

use Drupal\commerce_api\Events\RenamableResourceTypeBuildEvent;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Installer\InstallerKernel;
use Drupal\jsonapi\ResourceType\ResourceTypeBuildEvents;
use Drupal\jsonapi\ResourceType\ResourceTypeRepository;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Decorates resource type repository to support resource type renaming.
 *
 * @todo remove after https://www.drupal.org/project/drupal/issues/3105318
 * @todo add integration test coverage with jsonapi_cross_bundles
 */
class RenamableResourceTypeRepository extends ResourceTypeRepository {

  /**
   * {@inheritdoc}
   */
  protected function createResourceType(EntityTypeInterface $entity_type, $bundle) {
    $raw_fields = $this->getAllFieldNames($entity_type, $bundle);
    $internalize_resource_type = $entity_type->isInternal();
    $fields = $this->getFields($raw_fields, $entity_type, $bundle);
    $type_name = NULL;
    $custom_path = NULL;
    if (!$internalize_resource_type) {
      $event = RenamableResourceTypeBuildEvent::createFromEntityTypeAndBundle($entity_type, $bundle, $fields);
      $this->eventDispatcher->dispatch(ResourceTypeBuildEvents::BUILD, $event);
      $internalize_resource_type = $event->resourceTypeShouldBeDisabled();
      $fields = $event->getFields();
      $type_name = $event->getResourceTypeName();
      $custom_path = $event->getCustomPath();
    }
    return new RenamableResourceType(
      $entity_type->id(),
      $bundle,
      $entity_type->getClass(),
      $type_name,
      $custom_path,
      $internalize_resource_type,
      static::isLocatableResourceType($entity_type, $bundle),
      static::isMutableResourceType($entity_type, $bundle),
      static::isVersionableResourceType($entity_type),
      $fields
    );
  }

  /**
   * {@inheritdoc}
   */
  public function get($entity_type_id, $bundle) {
    assert(is_string($bundle) && !empty($bundle), 'A bundle ID is required. Bundleless entity types should pass the entity type ID again.');
    if (empty($entity_type_id)) {
      throw new PreconditionFailedHttpException('Server error. The current route is malformed.');
    }

    return static::lookupResourceType($this->all(), $entity_type_id, $bundle);
  }

  /**
   * Get relatable resource types from a field definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition from which to calculate relatable JSON:API resource
   *   types.
   * @param \Drupal\jsonapi\ResourceType\ResourceType[] $resource_types
   *   A list of JSON:API resource types.
   *
   * @return \Drupal\jsonapi\ResourceType\ResourceType[]
   *   The JSON:API resource types with which the given field may have a
   *   relationship.
   */
  protected function getRelatableResourceTypesFromFieldDefinition(FieldDefinitionInterface $field_definition, array $resource_types) {
    $item_definition = $field_definition->getItemDefinition();
    $entity_type_id = $item_definition->getSetting('target_type');
    $handler_settings = $item_definition->getSetting('handler_settings');
    $target_bundles = empty($handler_settings['target_bundles']) ? $this->getAllBundlesForEntityType($entity_type_id) : $handler_settings['target_bundles'];
    $relatable_resource_types = [];

    foreach ($target_bundles as $target_bundle) {
      if ($resource_type = static::lookupResourceType($resource_types, $entity_type_id, $target_bundle)) {
        $relatable_resource_types[] = $resource_type;
      }
      // Do not warn during the site installation since system integrity
      // is not guaranteed in this period and the warnings may pop up falsy,
      // adding confusion to the process.
      elseif (!InstallerKernel::installationAttempted()) {
        trigger_error(
          sprintf(
            'The "%s" at "%s:%s" references the "%s:%s" entity type that does not exist. Please take action.',
            $field_definition->getName(),
            $field_definition->getTargetEntityTypeId(),
            $field_definition->getTargetBundle(),
            $entity_type_id,
            $target_bundle
          ),
          E_USER_WARNING
        );
      }
    }

    return $relatable_resource_types;
  }

  /**
   * Lookup a resource type by entity type ID and bundle name.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceType[] $resource_types
   *   The list of resource types to do a lookup.
   * @param string $entity_type_id
   *   The entity type of a seekable resource type.
   * @param string $bundle
   *   The entity bundle of a seekable resource type.
   *
   * @return \Drupal\jsonapi\ResourceType\ResourceType|null
   *   The resource type or NULL if one cannot be found.
   */
  protected static function lookupResourceType(array $resource_types, $entity_type_id, $bundle) {
    if (isset($resource_types["$entity_type_id--$bundle"])) {
      return $resource_types["$entity_type_id--$bundle"];
    }

    foreach ($resource_types as $resource_type) {
      if ($resource_type->getEntityTypeId() === $entity_type_id && $resource_type->getBundle() === $bundle) {
        return $resource_type;
      }
    }

    return NULL;
  }

}

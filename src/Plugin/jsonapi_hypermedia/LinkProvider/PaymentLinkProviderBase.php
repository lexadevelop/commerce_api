<?php declare(strict_types=1);

namespace Drupal\commerce_api\Plugin\jsonapi_hypermedia\LinkProvider;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi_hypermedia\AccessRestrictedLink;
use Drupal\jsonapi_hypermedia\Plugin\LinkProviderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base link provider classes for links involving order payment.
 */
abstract class PaymentLinkProviderBase extends LinkProviderBase implements ContainerFactoryPluginInterface {

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, string $plugin_id, $plugin_definition, EntityRepositoryInterface $entity_repository, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityRepository = $entity_repository;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity.repository'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getLink($context) {
    assert($context instanceof ResourceObject);
    if ($this->routeMatch->getRouteName() !== 'commerce_api.checkout') {
      return AccessRestrictedLink::createInaccessibleLink(new CacheableMetadata());
    }
    $entity = $this->entityRepository->loadEntityByUuid(
      'commerce_order',
      $context->getId()
    );
    assert($entity instanceof OrderInterface);

    $cache_metadata = new CacheableMetadata();
    $cache_metadata->addCacheableDependency($entity);

    if ($entity->get('payment_gateway')->isEmpty()) {
      return AccessRestrictedLink::createInaccessibleLink($cache_metadata);
    }
    $payment_gateway = $entity->get('payment_gateway')->entity;
    if (!$payment_gateway instanceof PaymentGatewayInterface) {
      return AccessRestrictedLink::createInaccessibleLink($cache_metadata);
    }
    $cache_metadata->addCacheableDependency($payment_gateway);

    return $this->doGetLink($entity, $payment_gateway, $cache_metadata);
  }

  /**
   * Plugin specific logic to return the hypermedia link.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway
   *   The payment gateway.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheable_metadata
   *   The cacheable metadata.
   *
   * @return \Drupal\jsonapi_hypermedia\AccessRestrictedLink
   *   A link to be added to the context object. An AccessRestrictedLink
   *   should be returned if the link target may be inaccessible to some users.
   */
  abstract protected function doGetLink(OrderInterface $order, PaymentGatewayInterface $payment_gateway, CacheableMetadata $cacheable_metadata);

}

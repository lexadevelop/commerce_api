<?php declare(strict_types = 1);

namespace Drupal\commerce_api\Plugin\jsonapi_hypermedia\LinkProvider;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;
use Drupal\jsonapi_hypermedia\AccessRestrictedLink;

/**
* Class PaymentGatewayOnReturnLinkprovider.
*
* @JsonapiHypermediaLinkProvider(
*   id = "commerce_api.payment.approve",
*   link_relation_type = "payment-approve",
*   deriver = "\Drupal\commerce_api\Plugin\Derivative\OrderResourceTypeDeriver",
* )
*
* @internal
*/
final class PaymentApproveLinkProvider extends PaymentLinkProviderBase {

  /**
   * {@inheritdoc}
   */
  public function doGetLink(OrderInterface $order, PaymentGatewayInterface $payment_gateway, CacheableMetadata $cacheable_metadata) {
    $plugin = $payment_gateway->getPlugin();
    // The Approve resource is for off-site and manual payments.
    if (!$plugin instanceof OffsitePaymentGatewayInterface) {
      return AccessRestrictedLink::createInaccessibleLink($cacheable_metadata);
    }

    // If the off-site payment gateway supports stored payment methods, do not
    // provide this link. We only want the authorize and capture links to be
    // returned.
    if ($plugin instanceof SupportsStoredPaymentMethodsInterface && !$order->get('payment_method')->isEmpty()) {
      return AccessRestrictedLink::createInaccessibleLink($cacheable_metadata);
    }

    return AccessRestrictedLink::createLink(
      AccessResult::allowed(),
      $cacheable_metadata,
      new Url('commerce_api.checkout.payment_approve', [
        'commerce_order' => $order->uuid(),
        'payment_gateway' => $payment_gateway->uuid(),
      ]),
      $this->getLinkRelationType()
    );
  }

}

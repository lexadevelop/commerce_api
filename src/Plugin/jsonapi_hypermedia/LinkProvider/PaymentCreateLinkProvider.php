<?php declare(strict_types = 1);

namespace Drupal\commerce_api\Plugin\jsonapi_hypermedia\LinkProvider;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\ManualPaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;
use Drupal\jsonapi_hypermedia\AccessRestrictedLink;

/**
 * Class CapturePaymentMethodPaymentLinkProvider.
 *
 * @JsonapiHypermediaLinkProvider(
 *   id = "commerce_api.payment.payment_create",
 *   link_relation_type = "payment-create",
 *   deriver = "\Drupal\commerce_api\Plugin\Derivative\OrderResourceTypeDeriver",
 * )
 *
 * @internal
 */
final class PaymentCreateLinkProvider extends PaymentLinkProviderBase {

  /**
   * {@inheritdoc}
   */
  public function doGetLink(OrderInterface $order, PaymentGatewayInterface $payment_gateway, CacheableMetadata $cacheable_metadata) {
    $plugin = $payment_gateway->getPlugin();
    // Only display if the payment gateway supports authorizations, stored
    // payment methods, and a payment method has been attached to the order.
    // @todo needs test: supports manual, and SupportsStoredPaymentMethodsInterface BUT only if payment method attached.
    $is_manual_payment = $plugin instanceof ManualPaymentGatewayInterface;
    $supports_payment_methods = $plugin instanceof SupportsStoredPaymentMethodsInterface;
    $has_payment_method = !$order->get('payment_method')->isEmpty();
    if (!$is_manual_payment && !($supports_payment_methods && $has_payment_method)) {
      return AccessRestrictedLink::createInaccessibleLink($cacheable_metadata);
    }

    return AccessRestrictedLink::createLink(
      AccessResult::allowed(),
      $cacheable_metadata,
      new Url('commerce_api.checkout.payment', [
        'commerce_order' => $order->uuid(),
      ]),
      $this->getLinkRelationType()
    );
  }

}

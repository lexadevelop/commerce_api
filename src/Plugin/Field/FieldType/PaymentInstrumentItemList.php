<?php declare(strict_types = 1);

namespace Drupal\commerce_api\Plugin\Field\FieldType;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsCreatingPaymentMethodsInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class PaymentInstrumentItemList extends FieldItemList {

  use ComputedItemListTrait;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  private $entityFieldManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  private $entityRepository;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * {@inheritdoc}
   */
  public function __construct(DataDefinitionInterface $definition, $name = NULL, TypedDataInterface $parent = NULL) {
    parent::__construct($definition, $name, $parent);

    // @note TypedData API does not support dependency injection.
    // @see \Drupal\Core\TypedData\TypedDataManager::createInstance().
    $this->entityFieldManager = \Drupal::service('entity_field.manager');
    $this->entityTypeManager = \Drupal::entityTypeManager();
    $this->entityRepository = \Drupal::service('entity.repository');
    $this->logger = \Drupal::service('logger.channel.commerce_payment');
  }

  /**
   * {@inheritdoc}
   */
  protected function computeValue() {
    $order = $this->getEntity();
    assert($order instanceof OrderInterface);

    $values = [];
    if (!$order->get('payment_method')->isEmpty() && $order->get('payment_method')->entity) {
      $payment_method = $order->get('payment_method')->entity;
      assert($payment_method instanceof PaymentMethodInterface);
      $bundle_field_definitions = array_filter($this->entityFieldManager->getFieldDefinitions('commerce_payment_method', $payment_method->bundle()), static function (FieldDefinitionInterface $definition) {
        return !empty($definition->getTargetBundle());
      });

      $values = [
        'payment_method_id' => $payment_method->uuid(),
        'payment_method_type' => $payment_method->bundle(),
        'payment_gateway_id' => $payment_method->getPaymentGatewayId(),
        'payment_details' => array_map(static function (FieldDefinitionInterface $field_definition) use ($payment_method) {
          $field_name = $field_definition->getName();
          if ($payment_method->get($field_name)->isEmpty()) {
            return NULL;
          }
          $main_property_name = $field_definition->getFieldStorageDefinition()->getMainPropertyName();
          if ($main_property_name === NULL) {
            $properties = $field_definition->getFieldStorageDefinition()->getPropertyNames();
            $main_property_name = reset($properties);
          }
          return $payment_method->get($field_name)->{$main_property_name};
        }, $bundle_field_definitions),
      ];
    }
    elseif (!$order->get('payment_gateway')->isEmpty() && $order->get('payment_gateway')->entity) {
      $values['payment_gateway_id'] = $order->get('payment_gateway')->target_id;
    }
    $this->list[0] = $this->createItem(0, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE) {
    $values += [
      'payment_gateway_id' => NULL,
      'payment_method_id' => NULL,
      'payment_method_type' => '',
      'payment_details' => [],
    ];
    if ($values['payment_gateway_id'] === NULL) {
      throw new UnprocessableEntityHttpException('You must specify a `payment_gateway_id`');
    }
    if (!is_array($values['payment_details'])) {
      throw new UnprocessableEntityHttpException('payment_details must be an array');
    }
    parent::setValue($values, $notify);

    // Make sure that subsequent getter calls do not try to compute the values
    // again.
    $this->valueComputed = TRUE;

    $order = $this->getEntity();
    assert($order instanceof OrderInterface);

    $order->set('payment_gateway', $values['payment_gateway_id']);
    $payment_gateway = $order->get('payment_gateway')->entity;
    if (!$payment_gateway instanceof PaymentGatewayInterface) {
      throw new UnprocessableEntityHttpException('Payment gateway does not exist.');
    }

    $payment_gateway_plugin = $payment_gateway->getPlugin();
    if ($values['payment_method_id'] !== NULL) {
      $payment_method = $this->entityRepository->loadEntityByUuid('commerce_payment_method', $values['payment_method_id']);
      if (!$payment_method instanceof PaymentMethodInterface) {
        throw new UnprocessableEntityHttpException('Provided payment method does not exist');
      }
      if ($payment_gateway->id() !== $payment_method->getPaymentGatewayId()) {
        throw new UnprocessableEntityHttpException('Provided payment method does not belong to the payment gateway.');
      }

      $order->set('payment_method', $payment_method);
      $order->setBillingProfile($payment_method->getBillingProfile());
    }
    elseif ($values['payment_details'] !== []) {
      if (!$payment_gateway_plugin instanceof SupportsCreatingPaymentMethodsInterface) {
        throw new UnprocessableEntityHttpException('The referenced payment gateway does not support creating payment methods.');
      }
      try {
        /** @var \Drupal\commerce_payment\PaymentMethodStorageInterface $payment_method_storage */
        $payment_method_storage = $this->entityTypeManager->getStorage('commerce_payment_method');
        $payment_method = $payment_method_storage->createForCustomer(
          $values['payment_method_type'],
          $values['payment_gateway_id'],
          $order->getCustomerId(),
          // @todo support also passing a billing profile?
          // @note this currently requires you to set the billing information
          // ahead of time and understand that this must be done before
          // performing PATCH for this field.
          $order->getBillingProfile()
        );
        $payment_gateway_plugin->createPaymentMethod($payment_method, $values['payment_details']);
        $order->set('payment_method', $payment_method);
      }
      catch (PaymentGatewayException $e) {
        $this->logger->error($e->getMessage());
        throw new UnprocessableEntityHttpException('We encountered an error processing your payment method. Please verify your details and try again.');
      }
      catch (\Exception $e) {
        $this->logger->error($e->getMessage());
        throw new UnprocessableEntityHttpException('We encountered an unexpected error processing your payment method. Please try again later.');
      }
    }
  }

}

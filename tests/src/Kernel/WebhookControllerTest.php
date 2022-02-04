<?php declare(strict_types = 1);

namespace Drupal\Tests\commerce_api\Kernel;

use Drupal\commerce_api\Controller\WebhookController;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the WebhookController.
 *
 * @covers \Drupal\commerce_api\Controller\WebhookController::handleTransition()
 *
 * @group commerce_api
 */
final class WebhookControllerTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'serialization',
    'jsonapi',
    'jsonapi_resources',
    'jsonapi_hypermedia',
    'commerce_cart',
    'commerce_api',
  ];

  /**
   * The test order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  private $order;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'TEST_' . strtolower($this->randomMachineName()),
      'title' => $this->randomString(),
      'price' => new Price('12.00', 'USD'),
      'status' => TRUE,
    ]);
    $variation->save();
    $order_item = OrderItem::create([
      'type' => 'default',
      'quantity' => '1',
      'title' => $variation->label(),
      'unit_price' => $variation->getPrice(),
      'purchased_entity' => $variation->id(),
    ]);
    assert($order_item instanceof OrderItem);
    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'ip_address' => '127.0.0.1',
      'store_id' => $this->store,
      'order_items' => [$order_item],
    ]);
    assert($order instanceof Order);
    $order->save();
    $this->order = $this->reloadEntity($order);
  }

  /**
   * Tests the webhook controller.
   */
  public function testWebhookController() {
    $request = new Request();
    $request->attributes->set('commerce_order', $this->order->uuid());
    $request->attributes->set('transition', 'place');
    /** @var \Drupal\Core\Routing\RouteProviderInterface $route_provider */
    $route_provider = $this->container->get('router.route_provider');
    $route = $route_provider->getRouteByName('commerce_api.webhook_order_transition');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $route_match = RouteMatch::createFromRequest($request);
    $controller = new WebhookController($this->container->get('event_dispatcher'));
    $response = $controller->handleTransition($this->order, $request, $route_match);
    $this->assertEquals(new JsonResponse(['message' => 'OK']), $response);

    $this->assertEquals('completed', $this->order->getState()->getId());
    $this->order = $this->reloadEntity($this->order);
    $response = $controller->handleTransition($this->order, $request, $route_match);
    $error_message = 'Cannot apply the "%s" transition to the order %s.';
    $message = sprintf($error_message, 'place', $this->order->id());
    $this->assertEquals(new JsonResponse(['message' => $message], 400), $response);

    $request->attributes->set('transition', 'cancel');
    $route_match = RouteMatch::createFromRequest($request);
    $message = sprintf($error_message, 'cancel', $this->order->id());
    $response = $controller->handleTransition($this->order, $request, $route_match);
    $this->assertEquals(new JsonResponse(['message' => $message], 400), $response);

    $this->order->state = 'draft';
    $response = $controller->handleTransition($this->order, $request, $route_match);
    $this->assertEquals(new JsonResponse(['message' => 'OK']), $response);

    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = OrderType::load('default');
    $order_type->setWorkflowId('order_fulfillment');
    $order_type->save();
    $this->order->state = 'fulfillment';
    $request->attributes->set('transition', 'fulfill');
    $route_match = RouteMatch::createFromRequest($request);
    $response = $controller->handleTransition($this->order, $request, $route_match);
    $this->assertEquals(new JsonResponse(['message' => 'OK']), $response);
  }

}

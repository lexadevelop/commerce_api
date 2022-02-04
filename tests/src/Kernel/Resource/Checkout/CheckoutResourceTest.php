<?php

namespace Drupal\Tests\commerce_api\Kernel\Resource\Checkout;

use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\commerce_shipping\Entity\Shipment;
use Drupal\Component\Serialization\Json;

/**
 * Tests the CheckoutResource.
 *
 * @group commerce_api
 *
 * @requires module commerce_shipping
 */
final class CheckoutResourceTest extends CheckoutResourceTestBase {

  /**
   * Tests checkout PATCH requests.
   *
   * @param array $test_document
   *   The test request document.
   * @param array $expected_document
   *   The expected response document.
   *
   * @dataProvider dataDocuments
   *
   * @throws \Exception
   */
  public function testRequestAndResponse(array $test_document, array $expected_document) {
    $document['data'] = [
      'type' => 'order--default',
      'id' => self::TEST_ORDER_UUID,
      'attributes' => $test_document['attributes'] ?? [],
      'relationships' => $test_document['relationships'] ?? [],
      'meta' => $test_document['meta'] ?? [],
    ];

    $request = $this->getMockedRequest(
      'http://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID,
      'PATCH',
      $document
    );

    $response = $this->processRequest($request);

    $decoded_document = Json::decode($response->getContent());
    if (isset($decoded_document['errors'])) {
      $this->assertEquals($expected_document, $decoded_document, var_export($decoded_document, TRUE));
    }
    else {
      if (isset($expected_document['data']['relationships']['store_id']['data'])) {
        $expected_document['data']['relationships']['store_id']['data']['id'] = $this->store->uuid();
      }
      if (isset($expected_document['data']['attributes']['payment_instrument']['payment_method_id'])) {
        $created_payment_method = PaymentMethod::load(1);
        $this->assertNotNull($created_payment_method);
        $expected_document['data']['attributes']['payment_instrument']['payment_method_id'] = $created_payment_method->uuid();
        $expected_document['data']['meta']['payment_options'][0]['payment_method_id'] = $created_payment_method->uuid();
      }
      $shipment = Shipment::load(1);
      if ($shipment) {
        $expected_document['data']['relationships']['shipments']['data'] = [
          [
            'id' => $shipment->uuid(),
            'type' => 'shipment--default',
          ],
        ];
      }
      $this->assertEquals($expected_document, $decoded_document, var_export($decoded_document, TRUE));
    }
  }

  /**
   * Tests that a 409 is returned in case of an order version mismatch.
   */
  public function testOrderVersionMismatchResponse() {
    $document['data'] = [
      'type' => 'order--default',
      'id' => self::TEST_ORDER_UUID,
      'attributes' => [
        'email' => 'tester@example.com',
      ],
    ];
    $request = $this->getMockedRequest(
      'http://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID,
      'PATCH',
      $document
    );
    $this->order = $this->reloadEntity($this->order);
    $this->order->setVersion(0);
    $response = $this->processRequest($request);
    $this->assertEquals(409, $response->getStatusCode());
  }

  /**
   * Test documents for PATCHing checkout.
   *
   * @return \Generator
   *   The test data.
   */
  public function dataDocuments(): \Generator {
    yield [
      [
        'attributes' => [
          'email' => 'tester@example.com',
        ],
      ],
      $this->buildResponseJsonApiDocument([
        'email' => 'tester@example.com',
        'state' => 'draft',
      ],
      [
        'constraints' => [
          [
            'required' => [
              'detail' => 'This value should not be null.',
              'source' => [
                'pointer' => 'billing_profile',
              ],
            ],
          ],
          [
            'required' => [
              'detail' => 'This value should not be null.',
              'source' => [
                'pointer' => 'shipping_information',
              ],
            ],
          ],
        ],
      ],
      [],
      [
        'shipping-methods' => static::getShippingMethodsLink(),
      ]
      ),
    ];
    yield [
      [
        'attributes' => [
          'email' => 'testerexample.com',
        ],
      ],
      [
        'jsonapi' => [
          'meta' => [
            'links' => [
              'self' => ['href' => 'http://jsonapi.org/format/1.0/'],
            ],
          ],
          'version' => '1.0',
        ],
        'errors' => [
          [
            'title' => 'Unprocessable Entity',
            'status' => '422',
            'detail' => 'mail.0.value: This value is not a valid email address.',
            'source' => [
              'pointer' => '/data/attributes/mail/value',
            ],
          ],
        ],
      ],
    ];
    yield [
      [
        'attributes' => [
          'email' => 'tester@example.com',
          'shipping_information' => [
            // Required to always send the country code.
            'address' => [
              'country_code' => 'US',
              'postal_code' => '94043',
            ],
          ],
        ],
      ],
      $this->buildResponseJsonApiDocument([
        'email' => 'tester@example.com',
        'state' => 'draft',
        'shipping_method' => '',
        'shipping_information' => [
          'address' => [
            'country_code' => 'US',
            'postal_code' => '94043',
          ],
        ],
      ],
        [
          'constraints' => [
            [
              'required' => [
                'detail' => 'This value should not be null.',
                'source' => [
                  'pointer' => 'billing_profile',
                ],
              ],
            ],
          ],
        ],
        [],
        [
          'shipping-methods' => static::getShippingMethodsLink(),
        ]
      ),
    ];
    yield [
      [
        'attributes' => [
          'email' => 'tester@example.com',
          'shipping_information' => [
            // This should throw an error on postal_code validation.
            'address' => [
              'country_code' => 'US',
              'administrative_area' => 'CA',
              'postal_code' => '11111',
            ],
          ],
        ],
      ],
      $this->buildResponseJsonApiDocument([
        'email' => 'tester@example.com',
        'state' => 'draft',
        'shipping_information' => [
          'address' => [
            'country_code' => 'US',
            'administrative_area' => 'CA',
            'postal_code' => '11111',
          ],
        ],
      ],
        [
          'constraints' => [
            [
              'required' => [
                'detail' => 'This value should not be null.',
                'source' => [
                  'pointer' => 'billing_profile',
                ],
              ],
            ],
          ],
        ],
        [],
        [
          'shipping-methods' => static::getShippingMethodsLink(),
        ]
      ),
    ];
    yield [
      [
        'attributes' => [
          'email' => 'tester@example.com',
          'shipping_information' => [
            'address' => [
              'country_code' => 'US',
              'postal_code' => '94043',
            ],
          ],
          'shipping_method' => '2--default',
        ],
      ],
      $this->buildResponseJsonApiDocument([
        'email' => 'tester@example.com',
        'state' => 'draft',
        'shipping_information' => [
          'address' => [
            'country_code' => 'US',
            'postal_code' => '94043',
          ],
        ],
        'shipping_method' => '2--default',
        'order_total' => [
          'subtotal' => [
            'number' => '4.0',
            'currency_code' => 'USD',
            'formatted' => '$4.00',
          ],
          'adjustments' => [
            [
              'type' => 'shipping',
              'label' => 'Shipping',
              'amount' => [
                'number' => '20.00',
                'currency_code' => 'USD',
                'formatted' => '$20.00',
              ],
              'percentage' => NULL,
              'source_id' => 1,
              'included' => FALSE,
              'locked' => FALSE,
              'total' => [
                'number' => '20.00',
                'currency_code' => 'USD',
                'formatted' => '$20.00',
              ],
            ],
          ],
          'total' => [
            'number' => '24.0',
            'currency_code' => 'USD',
            'formatted' => '$24.00',
          ],
        ],
        'total_price' => [
          'number' => '24.0',
          'currency_code' => 'USD',
          'formatted' => '$24.00',
        ],
      ],
        [
          'constraints' => [
            [
              'required' => [
                'detail' => 'This value should not be null.',
                'source' => [
                  'pointer' => 'billing_profile',
                ],
              ],
            ],
          ],
        ],
        [],
        [
          'shipping-methods' => static::getShippingMethodsLink(),
        ]
      ),
    ];
    yield [
      [
        'attributes' => [
          'email' => 'tester@example.com',
          'state' => 'draft',
          'shipping_information' => [
            'address' => [
              'country_code' => 'US',
              'postal_code' => '94043',
            ],
          ],
          'shipping_method' => '2--default',
          'billing_information' => [
            'address' => [
              'country_code' => 'US',
              'postal_code' => '94043',
              'given_name' => 'Bryan',
              'family_name' => 'Centarro',
            ],
          ],
          'payment_instrument' => [
            'payment_gateway_id' => 'onsite',
            'payment_method_type' => 'credit_card',
            'payment_details' => [
              'type' => 'visa',
              'number' => '4111111111111111',
              'expiration' => [
                'month' => '05',
                'year' => '2026',
              ],
            ],
          ],
        ],
      ],
      $this->buildResponseJsonApiDocument([
        'email' => 'tester@example.com',
        'state' => 'draft',
        'billing_information' => [
          'address' => [
            'country_code' => 'US',
            'postal_code' => '94043',
            'given_name' => 'Bryan',
            'family_name' => 'Centarro',
          ],
        ],
        'shipping_information' => [
          'address' => [
            'country_code' => 'US',
            'postal_code' => '94043',
          ],
        ],
        'payment_instrument' => [
          'payment_gateway_id' => 'onsite',
          'payment_method_type' => 'credit_card',
          'payment_method_id' => '',
          'payment_details' => [
            'card_type' => 'visa',
            'card_number' => '1111',
            'card_exp_month' => '05',
            'card_exp_year' => '2026',
          ],
        ],
        'shipping_method' => '2--default',
        'order_total' => [
          'subtotal' => [
            'number' => '4.0',
            'currency_code' => 'USD',
            'formatted' => '$4.00',
          ],
          'adjustments' => [
            [
              'type' => 'shipping',
              'label' => 'Shipping',
              'amount' => [
                'number' => '20.00',
                'currency_code' => 'USD',
                'formatted' => '$20.00',
              ],
              'percentage' => NULL,
              'source_id' => 1,
              'included' => FALSE,
              'locked' => FALSE,
              'total' => [
                'number' => '20.00',
                'currency_code' => 'USD',
                'formatted' => '$20.00',
              ],
            ],
          ],
          'total' => [
            'number' => '24.0',
            'currency_code' => 'USD',
            'formatted' => '$24.00',
          ],
        ],
        'total_price' => [
          'number' => '24.0',
          'currency_code' => 'USD',
          'formatted' => '$24.00',
        ],
      ],
        [
          'payment_options' => [
            [
              'id' => '1',
              'label' => 'Visa ending in 1111',
              'payment_gateway_id' => 'onsite',
              'payment_method_id' => '1',
              'payment_method_type_id' => NULL,
            ],
            [
              'id' => 'new--credit_card--onsite',
              'label' => 'Credit card',
              'payment_gateway_id' => 'onsite',
              'payment_method_id' => NULL,
              'payment_method_type_id' => 'credit_card',
            ],
          ],
        ],
        [],
        [
          'shipping-methods' => static::getShippingMethodsLink(),
          'payment-create' => static::getPaymentCreateLink(),
        ]
      ),
    ];
    yield [
      [
        'attributes' => [
          'email' => 'tester@example.com',
          'payment_instrument' => [
            'payment_gateway_id' => 'invalid',
          ],
        ],
      ],
      [
        'jsonapi' => [
          'meta' => [
            'links' => [
              'self' => ['href' => 'http://jsonapi.org/format/1.0/'],
            ],
          ],
          'version' => '1.0',
        ],
        'errors' => [
          [
            'title' => 'Unprocessable Entity',
            'status' => '422',
            'detail' => 'Payment gateway does not exist.',
            'links' => [
              'via' => [
                'href' => 'http://localhost/jsonapi/checkout/' . self::TEST_ORDER_UUID,
              ],
            ],
          ],
        ],
      ],
    ];
    yield [
      [
        'attributes' => [
          'email' => 'tester@example.com',
          'payment_instrument' => [
            'payment_gateway_id' => 'onsite',
          ],
        ],
      ],
      $this->buildResponseJsonApiDocument([
        'email' => 'tester@example.com',
        'state' => 'draft',
        'payment_instrument' => [
          'payment_gateway_id' => 'onsite',
          'payment_method_id' => NULL,
          'payment_method_type' => NULL,
          'payment_details' => [],
        ],
      ],
        [
          'constraints' => [
            [
              'required' => [
                'detail' => 'This value should not be null.',
                'source' => [
                  'pointer' => 'billing_profile',
                ],
              ],
            ],
            [
              'required' => [
                'detail' => 'This value should not be null.',
                'source' => [
                  'pointer' => 'shipping_information',
                ],
              ],
            ],
          ],
        ],
        [],
        [
          'shipping-methods' => static::getShippingMethodsLink(),
        ]
      ),
    ];
  }

}

<?php

declare(strict_types=1);

use Flagship\Shipping\Collections\PackingCollection;
use Flagship\Shipping\Collections\RatesCollection;
use Flagship\Shipping\Objects\Packing;
use Flagship\Shipping\Exceptions\QuoteException;
use Flagship\Shipping\Objects\Rate;
use PHPUnit\Framework\TestCase;

class FlagshipShippingProxy extends FlagshipShipping
{
    public ?array $mockBoxes = null;
    public ?PackingCollection $mockPackingResponse = null;
    public ?array $lastPackingPayload = null;

    public function publicGetRatesString(array $ratesArray): string
    {
        return $this->getRatesString($ratesArray);
    }

    public function publicGetShippingCost(array $rate, Carrier $carrier): float
    {
        return $this->getShippingCost($rate, $carrier);
    }

    public function publicGetCouriers(array $rate): array
    {
        return $this->getCouriers($rate);
    }

    public function publicGetDimension($dimension)
    {
        return $this->getDimension($dimension);
    }

    public function publicGetWeight($weight)
    {
        return $this->getWeight($weight);
    }

    public function publicGetPackedItems($packings): array
    {
        return $this->getPackedItems($packings);
    }

    public function publicGetItemsByQty($product, $order, $items): array
    {
        return $this->getItemsByQty($product, $order, $items);
    }

    public function publicGetPackages($order = null, $destinationCountryIso = null): array
    {
        return $this->getPackages($order, $destinationCountryIso);
    }

    public function publicPrepareRates(RatesCollection $rates): array
    {
        return $this->prepareRates($rates);
    }

    public function publicGetBaseUrl(): string
    {
        return $this->getBaseUrl();
    }

    public function publicVerifyToken(string $token, int $testEnv): bool
    {
        return $this->verifyToken($token, $testEnv);
    }

    public function publicBuildCheckoutPayload(Address $address): array
    {
        return $this->buildCheckoutPayload($address);
    }

    public function publicGetPayloadForShipment(int $orderId): array
    {
        return $this->getPayloadForShipment($orderId);
    }

    public function publicLogQuoteTraffic(array $payload, $response): void
    {
        $this->logQuoteTrafficIfEnabled($payload, $response);
    }

    protected function getBoxes(): array
    {
        if (is_array($this->mockBoxes)) {
            return $this->mockBoxes;
        }

        return parent::getBoxes();
    }

    protected function executePackingRequest(array $packingPayload): PackingCollection
    {
        $this->lastPackingPayload = $packingPayload;

        if ($this->mockPackingResponse instanceof PackingCollection) {
            return $this->mockPackingResponse;
        }

        return parent::executePackingRequest($packingPayload);
    }
}

final class FlagshipShippingTest extends TestCase
{
    private FlagshipShippingProxy $module;

    protected function setUp(): void
    {
        parent::setUp();

        Tools::clearValues();
        Cache::clean('packagesCount');
        Configuration::updateValue('flagship_packing_api', 0);
        Configuration::updateValue('flagship_fee', 0.0);
        Configuration::updateValue('flagship_markup', 0.0);
        Configuration::updateValue('flagship_test_env', 0);
        Configuration::updateValue('PS_DIMENSION_UNIT', 'in');
        Configuration::updateValue('PS_WEIGHT_UNIT', 'lb');
        Configuration::updateValue('flagship_api_token', '__unset__');

        Context::getContext()->cookie = new Cookie();
        Context::getContext()->controller = new Controller();
        Context::getContext()->controller->php_self = 'index';

        Address::mockAddress(1, [
            'id_country' => 1,
            'id_state' => 1,
            'city' => 'Vancouver',
            'postcode' => 'V6B1A1',
            'address1' => '123 Main',
            'address2' => 'Suite 100',
            'phone' => '6045550100',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'company' => '',
        ]);

        Order::mockOrder(1, [
            'id_customer' => 1,
            'id_address_delivery' => 1,
            'products' => [
                [
                    'product_quantity' => 1,
                    'width' => 4,
                    'height' => 5,
                    'depth' => 6,
                    'weight' => 2,
                    'product_weight' => 2,
                    'product_name' => 'Default Item',
                    'unit_price_tax_excl' => 10,
                    'product_reference' => 'SKU-DEFAULT',
                    'is_virtual' => false,
                ],
            ],
        ]);

        $this->module = new FlagshipShippingProxy();
        PrestaShopLogger::$logs = [];
    }

    public function testGetRatesStringTrimsTrailingComma(): void
    {
        $rates = [
            ['courier' => 'UPS Standard', 'subtotal' => 10.5, 'taxes' => 1.5],
            ['courier' => 'FedEx International Priority', 'subtotal' => 12.0, 'taxes' => 2.0],
        ];

        $result = $this->module->publicGetRatesString($rates);

        $this->assertSame(
            'UPS Standard-10.5-1.5,FedEx International Priority-12-2',
            $result,
            'Rates string should concatenate entries without a trailing comma.'
        );
    }

    public function testGetShippingCostAppliesMarkupAndFee(): void
    {
        Configuration::updateValue('flagship_markup', 10);
        Configuration::updateValue('flagship_fee', 5);

        $carrier = new Carrier();
        $carrier->name = 'UPS Standard';

        $cost = $this->module->publicGetShippingCost(['UPS Standard-100'], $carrier);

        $this->assertSame(115.0, $cost);
    }

    public function testGetShippingCostReturnsZeroForUnknownCarrier(): void
    {
        $carrier = new Carrier();
        $carrier->name = 'Purolator';

        $cost = $this->module->publicGetShippingCost(['UPS Standard-100'], $carrier);

        $this->assertSame(0.0, $cost);
    }

    public function testGetCouriersParsesCarrierNames(): void
    {
        $entries = [
            'UPS Standard-10-1.5',
            'FedEx International Priority-12-2',
        ];

        $couriers = $this->module->publicGetCouriers($entries);

        $this->assertSame(['UPS Standard', 'FedEx International Priority'], $couriers);
    }

    public function testGetDimensionConvertsCentimeters(): void
    {
        Configuration::updateValue('PS_DIMENSION_UNIT', 'cm');
        Configuration::updateValue('flagship_packing_api', 0);

        $dimension = $this->module->publicGetDimension(2.54);

        $this->assertEquals(1, $dimension);
    }

    public function testGetDimensionGuaranteesMinimumOfOne(): void
    {
        Configuration::updateValue('flagship_packing_api', 0);

        $dimension = $this->module->publicGetDimension(0);

        $this->assertEquals(1, $dimension);
    }

    public function testGetWeightConvertsKilogramsWithoutRounding(): void
    {
        Configuration::updateValue('PS_WEIGHT_UNIT', 'kg');
        Configuration::updateValue('flagship_packing_api', 0);

        $weight = $this->module->publicGetWeight(0.5);

        $this->assertEqualsWithDelta(1.10231, $weight, 0.0001);
    }

    public function testGetWeightGuaranteesMinimumOfOne(): void
    {
        Configuration::updateValue('flagship_packing_api', 0);

        $weight = $this->module->publicGetWeight(0);

        $this->assertEquals(1, $weight);
    }

    public function testGetItemsByQtyExpandsCartProductQuantity(): void
    {
        $product = [
            'quantity' => 3,
            'width' => 2,
            'height' => 3,
            'depth' => 4,
            'weight' => 5,
            'name' => 'Widget',
        ];

        $items = $this->module->publicGetItemsByQty($product, null, []);

        $this->assertCount(3, $items);
        $this->assertSame('Widget', $items[0]['description']);
    }

    public function testGetItemsByQtyReadsOrderQuantities(): void
    {
        $product = [
            'product_quantity' => 2,
            'width' => 1,
            'height' => 2,
            'depth' => 3,
            'weight' => 4,
            'product_name' => 'Order Item',
        ];

        $order = new Order(1);

        $items = $this->module->publicGetItemsByQty($product, $order, []);

        $this->assertCount(2, $items);
        $this->assertSame('Order Item', $items[0]['description']);
    }

    public function testGetPackedItemsReturnsFallbackWhenNull(): void
    {
        $items = $this->module->publicGetPackedItems(null);

        $this->assertSame(
            [
                'length' => 1.0,
                'width' => 1.0,
                'height' => 1.0,
                'weight' => 1.0,
                'description' => 'Goods',
            ],
            $items
        );
    }

    public function testGetPackedItemsTransformsCollection(): void
    {
        $packing = new PackingCollection([
            new Flagship\Shipping\Objects\Packing((object)[
                'length' => 10,
                'width' => 5,
                'height' => 3,
                'weight' => 2,
                'box_model' => 'Small Box',
            ]),
        ]);

        $items = $this->module->publicGetPackedItems($packing);

        $this->assertEquals(
            [
                [
                    'length' => 10,
                    'width' => 5,
                    'height' => 3,
                    'weight' => 2,
                    'description' => 'Small Box',
                ],
            ],
            $items
        );
    }

    public function testPrepareRatesFormatsFedExName(): void
    {
        $rates = new RatesCollection([
            new Rate((object)[
                'price' => (object)[
                    'subtotal' => 10.5,
                    'taxes' => ['GST' => 1.5],
                ],
                'service' => (object)[
                    'courier_name' => 'FedEx',
                    'courier_desc' => 'International Priority',
                    'courier_code' => 'FXIP',
                    'estimated_delivery_date' => '2025-01-01',
                    'transit_time' => '2',
                ],
            ]),
            new Rate((object)[
                'price' => (object)[
                    'subtotal' => 12.0,
                    'taxes' => ['GST' => 2.0],
                ],
                'service' => (object)[
                    'courier_name' => 'UPS',
                    'courier_desc' => 'Standard',
                    'courier_code' => 'UPSS',
                    'estimated_delivery_date' => '2025-01-02',
                    'transit_time' => '3',
                ],
            ]),
            new Rate((object)[
                'price' => (object)[
                    'subtotal' => 15.0,
                    'taxes' => ['GST' => 2.0],
                ],
                'service' => (object)[
                    'courier_name' => 'FedEx',
                    'courier_desc' => 'International Connect Plus',
                    'courier_code' => 'FXCON',
                    'estimated_delivery_date' => '2025-01-03',
                    'transit_time' => '4',
                ],
            ]),
        ]);

        $prepared = $this->module->publicPrepareRates($rates);

        $this->assertSame('FedEx International Priority', $prepared[0]['courier']);
        $this->assertSame('Standard', $prepared[1]['courier']);
        $this->assertSame(10.5, $prepared[0]['subtotal']);
        $this->assertSame(1.5, $prepared[0]['taxes']);

        $this->assertSame('FedEx International Connect Plus', $prepared[2]['courier']);
        $this->assertSame(15.0, $prepared[2]['subtotal']);
        $this->assertSame(2.0, $prepared[2]['taxes']);
    }

    public function testBuildCheckoutPayloadNormalizesAddresses(): void
    {
        $address = new Address();
        $address->company = 'Acme Corp';
        $address->address2 = 'Warehouse Floor 123456789';

        $payload = $this->module->publicBuildCheckoutPayload($address);

        $this->assertSame('BC', $payload['from']['state']);
        $this->assertSame('QC', $payload['to']['state']);
        $this->assertSame(substr('Warehouse Floor 123456789', 0, 17), $payload['to']['suite']);
        $this->assertTrue($payload['to']['is_commercial']);
        $this->assertSame('imperial', $payload['packages']['units']);
        $this->assertSame('package', $payload['packages']['type']);
        $this->assertSame('goods', $payload['packages']['content']);
    }

    public function testBuildCheckoutPayloadSupportsCanadaToUsaShipments(): void
    {
        $address = new Address();
        $address->id_country = 2; // US
        $address->id_state = 2; // NY
        $address->city = 'New York';
        $address->postcode = '10001';
        $address->company = '';

        $payload = $this->module->publicBuildCheckoutPayload($address);

        $this->assertSame('CA', $payload['from']['country']);
        $this->assertSame('BC', $payload['from']['state']);
        $this->assertSame('US', $payload['to']['country']);
        $this->assertSame('NY', $payload['to']['state']);
        $this->assertFalse($payload['to']['is_commercial']);
    }

    public function testCheckoutPayloadMarksShipperAsPayer(): void
    {
        $payload = $this->module->publicBuildCheckoutPayload(new Address());

        $this->assertArrayHasKey('payment', $payload);
        $this->assertSame('F', $payload['payment']['payer']);
    }

    public function testShipmentPayloadIncludesCustomsInvoiceForUsa(): void
    {
        Address::mockAddress(1, [
            'id_country' => 2,
            'id_state' => 2,
            'city' => 'New York',
            'postcode' => '10001',
            'address1' => '1 Test St',
            'address2' => '',
            'company' => '',
            'phone' => '5555555555',
            'firstname' => 'Alice',
            'lastname' => 'Smith',
        ]);

        Order::mockOrder(1, [
            'id_customer' => 1,
            'id_address_delivery' => 1,
            'products' => [
                [
                    'product_quantity' => 1,
                    'width' => 4,
                    'height' => 5,
                    'depth' => 6,
                    'weight' => 2,
                    'product_weight' => 2,
                    'product_name' => 'Widget A',
                    'unit_price_tax_excl' => 10,
                    'product_reference' => 'SKU-A',
                    'is_virtual' => false,
                ],
            ],
        ]);

        $order = new Order(1);
        $payload = $this->module->publicGetPayloadForShipment(1);

        $this->assertArrayHasKey('customs_invoice', $payload);
        $this->assertNotEmpty($payload['customs_invoice']['items']);
        $this->assertSame('CAD', $payload['customs_invoice']['currency']);
    }

    public function testOriginStateFallsBackToOverrideWhenShopStateMissing(): void
    {
        Configuration::updateValue('PS_SHOP_STATE_ID', 0);
        Configuration::updateValue('FLAGSHIP_ORIGIN_STATE_ISO', 'ON');

        $payload = $this->module->publicBuildCheckoutPayload(new Address());

        $this->assertSame('ON', $payload['from']['state']);
    }

    public function testGetPackagesAggregatesCrossBorderWhenPackingDisabled(): void
    {
        Configuration::updateValue('flagship_packing_api', 0);

        Context::getContext()->cart->products = [
            [
                'quantity' => 1,
                'width' => 4,
                'height' => 5,
                'depth' => 6,
                'weight' => 2,
                'name' => 'Widget A',
                'is_virtual' => false,
            ],
            [
                'quantity' => 1,
                'width' => 3,
                'height' => 4,
                'depth' => 5,
                'weight' => 1.5,
                'name' => 'Widget B',
                'is_virtual' => false,
            ],
        ];

        $packages = $this->module->publicGetPackages(null, 'US');

        $this->assertCount(1, $packages['items']);
        $this->assertGreaterThan(0, $packages['items'][0]['height']);
    }

    public function testQuoteDebugLoggingCanBeToggled(): void
    {
        Configuration::updateValue('FS_DEBUG_RATE_TRAFFIC', 1);
        PrestaShopLogger::$logs = [];

        $payload = ['from' => ['city' => 'Montreal']];
        $response = (object)['status' => 'ok'];

        $this->module->publicLogQuoteTraffic($payload, $response);

        $this->assertCount(2, PrestaShopLogger::$logs);
        $this->assertStringContainsString('payload', PrestaShopLogger::$logs[0]);
        $this->assertStringContainsString('response', PrestaShopLogger::$logs[1]);

        Configuration::updateValue('FS_DEBUG_RATE_TRAFFIC', 0);
        PrestaShopLogger::$logs = [];
        $this->module->publicLogQuoteTraffic($payload, $response);
        $this->assertCount(0, PrestaShopLogger::$logs);
    }

    public function testGetPackagesUsesPackingApiResponse(): void
    {
        Configuration::updateValue('flagship_packing_api', 1);
        Configuration::updateValue('flagship_api_token', 'token');

        Context::getContext()->cart->products = [
            [
                'quantity' => 1,
                'width' => 4,
                'height' => 5,
                'depth' => 6,
                'weight' => 2,
                'name' => 'Widget A',
                'is_virtual' => false,
            ],
            [
                'quantity' => 1,
                'width' => 2,
                'height' => 3,
                'depth' => 4,
                'weight' => 1.5,
                'name' => 'Widget B',
                'is_virtual' => false,
            ],
        ];

        $this->module->mockBoxes = [
            [
                'box_model' => 'Small Box',
                'length' => 10.0,
                'width' => 8.0,
                'height' => 6.0,
                'weight' => 1.0,
                'max_weight' => 15.0,
            ],
            [
                'box_model' => 'Large Box',
                'length' => 20.0,
                'width' => 16.0,
                'height' => 12.0,
                'weight' => 2.0,
                'max_weight' => 40.0,
            ],
        ];

        $packingCollection = new PackingCollection([
            new Packing((object)[
                'box_model' => 'Small Box',
                'length' => 10,
                'width' => 8,
                'height' => 6,
                'weight' => 5,
            ]),
        ]);

        $this->module->mockPackingResponse = $packingCollection;

        $packages = $this->module->publicGetPackages();

        $this->assertSame('Small Box', $packages['items'][0]['description']);
        $this->assertSame(10.0, $packages['items'][0]['length']);
        $this->assertSame(5.0, $packages['items'][0]['weight']);
        $this->assertSame('imperial', $packages['units']);
        $this->assertSame('goods', $packages['content']);

        $this->assertNotNull($this->module->lastPackingPayload);
        $this->assertCount(2, $this->module->lastPackingPayload['boxes']);
        $this->assertCount(2, $this->module->lastPackingPayload['items']);
        $this->assertSame(4.0, $this->module->lastPackingPayload['items'][0]['width']);
        $this->assertSame(2.0, $this->module->lastPackingPayload['items'][1]['width']);
    }

    public function testShipmentPayloadMarksShipperAsPayer(): void
    {
        Context::getContext()->cart->products = [];
        $payload = $this->module->publicGetPayloadForShipment(1);

        $this->assertArrayHasKey('payment', $payload);
        $this->assertSame('F', $payload['payment']['payer']);
    }

    /**
     * Debug helper: prints SmartShip rates (requires FLAGSHIP_TEST_API_TOKEN).
     *
     * Steps:
     *   1. Export FLAGSHIP_TEST_API_TOKEN with your SmartShip sandbox token.
     *   2. (Optional) Export FLAGSHIP_BASE_ENV=test to hit the sandbox.
     *   3. Run `vendor\bin\phpunit --filter testDebugPrintSmartShipRates`.
     */
    public function testDebugPrintSmartShipRates(): void
    {
        $token = $this->requireSandboxToken();
        $this->configureSandboxToken($token);

        $address = new Address();
        $address->company = '';
        $address->id_country = 2; // Ship to US
        $address->id_state = 2;   // NY
        $address->city = 'New York';
        $address->postcode = '10001';

        Context::getContext()->cart->products = [
            [
                'quantity' => 1,
                'width' => 4,
                'height' => 5,
                'depth' => 6,
                'weight' => 2,
                'name' => 'Widget A',
                'is_virtual' => false,
            ],
            [
                'quantity' => 1,
                'width' => 2,
                'height' => 3,
                'depth' => 4,
                'weight' => 1.5,
                'name' => 'Widget B',
                'is_virtual' => false,
            ],
        ];

        $payload = $this->module->publicBuildCheckoutPayload($address);
        fwrite(STDERR, "SmartShip payload:\n".json_encode($payload, JSON_PRETTY_PRINT)."\n");

        $request = new FlagshipDetailedQuoteRequest(
            $token,
            $this->module->getApiBaseUrl(),
            $payload,
            'Prestashop',
            _PS_VERSION_
        );

        $request->setStoreName('Flagship Test Debug');
        $rates = $request->executeWithDetails()->sortByPrice();

        $this->assertNotEmpty($rates->all(), 'SmartShip returned no carriers.');
        $this->printRateCollection($rates);
    }

    public function testGetBaseUrlSwitchesToSandbox(): void
    {
        Configuration::updateValue('flagship_test_env', 1);

        $this->assertSame(SMARTSHIP_TEST_API_URL, $this->module->publicGetBaseUrl());
    }

    public function testVerifyTokenAgainstSandboxWhenTokenProvided(): void
    {
        $token = $this->requireSandboxToken();

        Configuration::updateValue('flagship_api_token', 'previous-token');

        $this->assertTrue(
            $this->module->publicVerifyToken($token, 1),
            'Sandbox token should be accepted when valid.'
        );

        $this->assertSame($token, Configuration::get('flagship_api_token'));
    }

    public function testPackingApiPacksRandomItemsAgainstSandbox(): void
    {
        $token = $this->requireSandboxToken();
        $this->configureSandboxToken($token);
        Configuration::updateValue('flagship_packing_api', 1);

        $products = $this->generateRandomCartProducts();
        Context::getContext()->cart->products = $products;
        $this->module->mockBoxes = $this->buildBoxesForProducts($products);

        $packages = $this->module->publicGetPackages();

        $this->assertNotEmpty($packages, 'Packing API returned an empty response.');
        $this->assertArrayHasKey('items', $packages);
        $this->assertNotEmpty($packages['items'], 'Packing API returned no packed items.');
        $this->assertSame('imperial', $packages['units']);

        foreach ($packages['items'] as $packedItem) {
            $this->assertGreaterThan(0, $packedItem['length'], 'Packed length must be positive.');
            $this->assertGreaterThan(0, $packedItem['width'], 'Packed width must be positive.');
            $this->assertGreaterThan(0, $packedItem['height'], 'Packed height must be positive.');
            $this->assertGreaterThan(0, $packedItem['weight'], 'Packed weight must be positive.');
        }
    }

    protected function printRateCollection(RatesCollection $rates): void
    {
        fwrite(STDERR, "Returned services:\n");
        foreach ($rates as $rate) {
            fwrite(
                STDERR,
                sprintf(
                    " - %s %s => %.2f\n",
                    $rate->getCourierName(),
                    $rate->getCourierDescription(),
                    $rate->getTotal()
                )
            );
        }
    }

    private function requireSandboxToken(): string
    {
        $token = getenv('FLAGSHIP_TEST_API_TOKEN');
        $this->assertNotFalse($token, 'Set FLAGSHIP_TEST_API_TOKEN with your sandbox key before running integration tests.');
        $token = trim((string)$token);
        $this->assertNotSame('', $token, 'FLAGSHIP_TEST_API_TOKEN cannot be empty.');
        return $token;
    }

    private function configureSandboxToken(string $token): void
    {
        $useSandbox = getenv('FLAGSHIP_BASE_ENV') === 'test';
        Configuration::updateValue('flagship_api_token', $token);
        Configuration::updateValue('flagship_test_env', $useSandbox ? 1 : 0);
    }

    private function generateRandomCartProducts(): array
    {
        $count = random_int(2, 3);
        $products = [];
        for ($i = 1; $i <= $count; $i++) {
            $products[] = [
                'quantity' => 1,
                'width' => random_int(3, 7),
                'height' => random_int(2, 6),
                'depth' => random_int(4, 9),
                'weight' => random_int(1, 5),
                'name' => 'Random Item '.$i,
                'is_virtual' => false,
            ];
        }
        return $products;
    }

    private function buildBoxesForProducts(array $products): array
    {
        $maxWidth = max(array_column($products, 'width'));
        $maxHeight = max(array_column($products, 'height'));
        $maxLength = max(array_column($products, 'depth'));
        $totalWeight = array_sum(array_column($products, 'weight'));

        $baseBox = [
            'box_model' => 'Packing Test Box A '.random_int(1000, 9999),
            'length' => $maxLength + random_int(3, 6),
            'width' => $maxWidth + random_int(3, 6),
            'height' => $maxHeight + random_int(3, 6),
            'weight' => 1,
            'max_weight' => $totalWeight + 10,
        ];

        $spareBox = [
            'box_model' => 'Packing Test Box B '.random_int(1000, 9999),
            'length' => $baseBox['length'] + random_int(2, 4),
            'width' => $baseBox['width'] + random_int(2, 4),
            'height' => $baseBox['height'] + random_int(2, 4),
            'weight' => 1,
            'max_weight' => $totalWeight + 20,
        ];

        return [$baseBox, $spareBox];
    }
}

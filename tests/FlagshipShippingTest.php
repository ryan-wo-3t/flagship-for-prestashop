<?php

declare(strict_types=1);

use Flagship\Shipping\Collections\PackingCollection;
use Flagship\Shipping\Collections\RatesCollection;
use Flagship\Shipping\Objects\Packing;
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

    public function publicGetPackages($order = null): array
    {
        return $this->getPackages($order);
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

        $this->assertSame('QC', $payload['from']['state']);
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
        $this->assertSame('QC', $payload['from']['state']);
        $this->assertSame('US', $payload['to']['country']);
        $this->assertSame('NY', $payload['to']['state']);
        $this->assertFalse($payload['to']['is_commercial']);
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

    /**
     * Debug helper: prints live SmartShip rates (requires FLAGSHIP_TEST_API_TOKEN env var).
     *
     * Steps:
     *   1. Export FLAGSHIP_TEST_API_TOKEN with your SmartShip sandbox token.
     *   2. (Optional) Export FLAGSHIP_BASE_ENV=test to hit the sandbox; omit for production.
     *   3. Run `vendor\bin\phpunit --filter testDebugPrintSmartShipRates`.
     *
     * NOTE: This test performs a real HTTP call and logs address/package data. Only enable when debugging.
     */
    public function testDebugPrintSmartShipRates(): void
    {
        $token = getenv('FLAGSHIP_TEST_API_TOKEN');
        if (!$token) {
            $this->markTestSkipped('Set FLAGSHIP_TEST_API_TOKEN to run this debug helper.');
        }

        $useSandbox = getenv('FLAGSHIP_BASE_ENV') === 'test';
        Configuration::updateValue('flagship_api_token', $token);
        Configuration::updateValue('flagship_test_env', $useSandbox ? 1 : 0);

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

        $this->assertNotEmpty(iterator_to_array($rates));
    }

    public function testGetBaseUrlSwitchesToSandbox(): void
    {
        Configuration::updateValue('flagship_test_env', 1);

        $this->assertSame(SMARTSHIP_TEST_API_URL, $this->module->publicGetBaseUrl());
    }

    public function testVerifyTokenAgainstSandboxWhenTokenProvided(): void
    {
        $token = getenv('FLAGSHIP_TEST_API_TOKEN');
        if (!$token) {
            $this->markTestSkipped('FLAGSHIP_TEST_API_TOKEN not set; skipping sandbox verification test.');
        }

        Configuration::updateValue('flagship_api_token', 'previous-token');

        $this->assertTrue(
            $this->module->publicVerifyToken($token, 1),
            'Sandbox token should be accepted when valid.'
        );

        $this->assertSame($token, Configuration::get('flagship_api_token'));
    }
}

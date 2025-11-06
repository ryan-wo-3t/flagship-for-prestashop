<?php

declare(strict_types=1);

use Flagship\Shipping\Collections\PackingCollection;
use Flagship\Shipping\Collections\RatesCollection;
use Flagship\Shipping\Objects\Rate;
use PHPUnit\Framework\TestCase;

class FlagshipShippingProxy extends FlagshipShipping
{
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

    public function testGetWeightConvertsKilogramsAndRoundsUp(): void
    {
        Configuration::updateValue('PS_WEIGHT_UNIT', 'kg');
        Configuration::updateValue('flagship_packing_api', 0);

        $weight = $this->module->publicGetWeight(0.5);

        $this->assertSame(2.0, $weight);
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
                'length' => 1,
                'width' => 1,
                'height' => 1,
                'weight' => 1,
                'description' => 'packed items',
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
        ]);

        $prepared = $this->module->publicPrepareRates($rates);

        $this->assertSame('FedEx International Priority', $prepared[0]['courier']);
        $this->assertSame('Standard', $prepared[1]['courier']);
        $this->assertSame(10.5, $prepared[0]['subtotal']);
        $this->assertSame(1.5, $prepared[0]['taxes']);
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

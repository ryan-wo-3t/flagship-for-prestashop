#!/usr/bin/env php
<?php

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This diagnostic must be run from the command line.\n");
    exit(1);
}

$rootDir = dirname(__DIR__, 4);
require_once $rootDir . '/config/config.inc.php';
require_once dirname(__DIR__, 2) . '/flagshipshipping.php';

$options = getopt('', ['cart-id:', 'address-id::']);

if (!isset($options['cart-id'])) {
    fwrite(STDERR, "Usage: php rate_check.php --cart-id=123 [--address-id=456]\n");
    exit(1);
}

$cartId = (int)$options['cart-id'];
$addressId = isset($options['address-id']) ? (int)$options['address-id'] : null;

$cart = new Cart($cartId);
if (!Validate::isLoadedObject($cart)) {
    fwrite(STDERR, "Cart {$cartId} was not found.\n");
    exit(1);
}

if (!$addressId) {
    $addressId = (int)$cart->id_address_delivery;
}

$address = new Address($addressId);
if (!Validate::isLoadedObject($address)) {
    fwrite(STDERR, "Address {$addressId} was not found.\n");
    exit(1);
}

$context = Context::getContext();
$context->cart = $cart;
$context->customer = new Customer($cart->id_customer);
$context->currency = new Currency($cart->id_currency);
$context->language = new Language($cart->id_lang);

$module = Module::getInstanceByName('flagshipshipping');
if (!$module instanceof FlagshipShipping) {
    fwrite(STDERR, "The flagshipshipping module could not be initialized.\n");
    exit(1);
}

$payload = $module->buildCheckoutPayload($address);
$token = Configuration::get('flagship_api_token');

if (empty($token)) {
    fwrite(STDERR, "FlagShip API token is not configured.\n");
    exit(1);
}

$quoteRequest = new FlagshipDetailedQuoteRequest(
    $token,
    $module->getApiBaseUrl(),
    $payload,
    'Prestashop',
    _PS_VERSION_
);

$quoteRequest->setStoreName($context->shop->name);
$quoteRequest->setOrderId($cartId);

try {
    $rates = $quoteRequest->executeWithDetails();
    $statusCode = (int)$quoteRequest->getResponseCode();
    $rawResponse = $quoteRequest->getRawResponse();
} catch (\Flagship\Shipping\Exceptions\QuoteException $exception) {
    fwrite(STDERR, "Quote request failed: " . $exception->getMessage() . "\n");
    exit(1);
}

echo "HTTP status: {$statusCode}\n\n";
echo "Payload:\n" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "Rates:\n";
$count = 0;
foreach ($rates->sortByPrice() as $rate) {
    /** @var \Flagship\Shipping\Objects\Rate $rate */
    $label = trim($rate->getCourierName() . ' ' . $rate->getCourierDescription());
    $label = $label === '' ? 'Unnamed Service' : $label;
    printf("  - %s => %.2f\n", $label, $rate->getTotal());
    $count++;
}

if ($count === 0) {
    echo "  (no rates returned)\n";
}

$errors = [];
if (is_object($rawResponse)) {
    if (isset($rawResponse->errors)) {
        $errors = array_merge($errors, (array)$rawResponse->errors);
    }
    if (isset($rawResponse->content) && isset($rawResponse->content->errors)) {
        $errors = array_merge($errors, (array)$rawResponse->content->errors);
    }
}

echo "\nErrors:\n";
if (empty($errors)) {
    echo "  (none)\n";
} else {
    foreach ($errors as $error) {
        if (is_object($error)) {
            $error = (array)$error;
        }
        if (is_array($error)) {
            $parts = [];
            if (!empty($error['courier_name'])) {
                $parts[] = $error['courier_name'];
            }
            if (!empty($error['service_name'])) {
                $parts[] = $error['service_name'];
            }
            if (!empty($error['message'])) {
                $parts[] = $error['message'];
            } elseif (!empty($error['error'])) {
                $parts[] = $error['error'];
            }
            $error = trim(implode(' ', $parts));
        }
        echo '  - ' . $error . "\n";
    }
}

$exitCode = ($statusCode === 200 || $statusCode === 206) ? 0 : 1;
exit($exitCode);

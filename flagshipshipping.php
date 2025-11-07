<?php
/**
 * 2007-2019 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    include_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/classes/FlagshipDetailedQuoteRequest.php';

use Flagship\Shipping\Exceptions\GetShipmentByIdException;
use Flagship\Shipping\Exceptions\PackingException;
use Flagship\Shipping\Flagship;

//NO Trailing slashes please
define('SMARTSHIP_WEB_URL', 'https://smartship-ng.flagshipcompany.com');
define('SMARTSHIP_API_URL', 'https://api.smartship.io');
define('SMARTSHIP_TEST_API_URL', 'https://test-api.smartship.io');
define('SMARTSHIP_TEST_WEB_URL','https://test-smartshipng.flagshipcompany.com');

#[\AllowDynamicProperties]
class FlagshipShipping extends CarrierModule
{
    private const CONFIG_CLEAN_CHECKOUT_OPTIONS = 'FS_CLEAN_CHECKOUT_OPTIONS';
    private const CONFIG_DEBUG_PARTIAL_QUOTES = 'FS_DEBUG_PARTIAL_QUOTES';
    private const CLEAN_CHECKOUT_OPTION_KEYS = ['signature_required', 'saturday_delivery', 'cod', 'insurance'];

    public $id_carrier;
    protected $config_form = false;
    protected $url;

    public function __construct()
    {
        $this->name = 'flagshipshipping';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.26';
        $this->author = 'FlagShip Courier Solutions';
        $this->need_instance = 0;
        $this->url = SMARTSHIP_WEB_URL;

        $this->logger = new FileLogger(0); //0 == debug level, logDebug() won’t work without this.
        $this->logger->setFilename(_PS_ROOT_DIR_."/var/logs/flagship.log");

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('FlagShip For PrestaShop');
        $this->description = $this->l('Send your shipments with FlagShip now.');
        $this->description .= $this->l(' Drop the hassle of figuring out the best prices.');
        $this->description .= $this->l(' Get real time prices from major courier service providers.');
        $this->description .= $this->l(' Your customers will never have to deal with a delayed delivery again.');
        $this->description .= $this->l(' A happy customer is a happy You!');

        $this->confirmUninstall = $this->l('Uninstalling FlagShip will remove all shipments.');
        $this->confirmUninstall .= $this->l(' Are you sure you want to uninstall?');

        $this->ps_versions_compliancy = array('min' => '1.7.8', 'max' => _PS_VERSION_);

        $this->registerHook('displayAdminOrderSide');
        $this->registerHook('actionValidateCustomerAddressForm');
        $this->registerHook('actionCartSave');

        $this->ensureCheckoutConfigDefaults();
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */

    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        Db::getInstance()->execute('
                CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'flagship_shipping` (
                `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_order` INT(10) UNSIGNED NOT NULL,
                `flagship_shipment_id` INT(10) UNSIGNED NULL,
                PRIMARY KEY (`id`)
                )
            ');

        Db::getInstance()->execute('
                CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'flagship_boxes` (
                `id` INT(2) UNSIGNED NOT NULL AUTO_INCREMENT,
                `model` VARCHAR(25) NOT NULL,
                `length` INT(2) UNSIGNED NOT NULL,
                `width` INT(2) UNSIGNED NOT NULL,
                `height` INT(2) UNSIGNED NOT NULL,
                `weight` FLOAT(4,2) UNSIGNED NOT NULL,
                `max_weight` FLOAT(4,2) UNSIGNED NOT NULL,
                PRIMARY KEY(`id`)
                )
            ');

        $this->logger->logDebug("Flagship for prestashop installed");
        $this->ensureCheckoutConfigDefaults();
        return parent::install();
    }

    public function uninstall()
    {

        Configuration::deleteByName('flagship_api_token');
        Configuration::deleteByName('flagship_fee');
        Configuration::deleteByName('flagship_markup');
        Configuration::deleteByName('flagship_residential');
        Configuration::deleteByName('flagship_test_env');
        Configuration::deleteByName(self::CONFIG_CLEAN_CHECKOUT_OPTIONS);
        Configuration::deleteByName(self::CONFIG_DEBUG_PARTIAL_QUOTES);

        $query = new DbQuery();
        $query->select('*')->from('flagship_shipping');

        $rows = Db::getInstance()->executeS($query);

        if (count($rows) == 0) {
            Db::getInstance()->execute('DROP TABLE `'._DB_PREFIX_.'flagship_shipping`');
        }
        
        Db::getInstance()->execute('DROP TABLE `'._DB_PREFIX_.'flagship_boxes`');
        Db::getInstance()->execute('DELETE FROM `'._DB_PREFIX_.'carrier` WHERE external_module_name = "flagshipshipping"');
        $this->logger->logDebug("Flagship for prestashop uninstalled");
        return parent::uninstall();
    }

    public function hookDisplayAdminAfterHeader(array $params)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
          CURLOPT_URL => "https://api.github.com/repos/flagshipcompany/flagship-for-prestashop/releases/latest",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "GET",
          CURLOPT_USERAGENT => " ",
        ));

	$response = json_decode(curl_exec($curl),True);

	curl_close($curl);
	$latestTag = array_key_exists("tag_name",$response) ? Tools::substr($response["tag_name"], 1) : 0;

        $latestTagNumber = strrchr($latestTag,".");
        $versionNumber = strrchr($this->version, ".");

        $tagMismatch = $latestTagNumber > $versionNumber ? 1 : 0;

        $this->context->smarty->assign(array(
            'tagMismatch' => $tagMismatch
        ));
        return $this->display(__FILE__,'notification.tpl');
    }

    /**
     * Load the configuration form
     */
    public function getContent() : string
    {
        /**
         * If values have been submitted in the form, process.
         */
        $output = '';

        if (((bool)Tools::isSubmit('submit'.$this->name.'Module')) == true) {
            $output .= $this->postProcess();
        }

        if (((bool)Tools::isSubmit('submit'.$this->name.'BoxModule')) == true) {
            $output .= $this->insertBoxDetails();
        }

        $this->context->smarty->assign('module_dir', $this->_path);
        $this->context->smarty->assign('boxes', $this->getBoxesString());
        $this->context->smarty->assign('units', $this->getStoreUnits());
        $output .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/note.tpl');
        $output .= $this->renderForm();
        $output .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/boxes.tpl');
        $output .= $this->renderBoxesForm();
        return $output;
    }

    public function hookDisplayBackOfficeOrderActions(array $params)
    {
        $id_order = $params["id_order"];
        $this->url = Configuration::get('flagship_test_env') ? SMARTSHIP_TEST_WEB_URL : SMARTSHIP_WEB_URL;
        $shipmentId = $this->getShipmentId($id_order);
        $shipmentFlag = is_null($shipmentId) ? 0 : $shipmentId;
        $convertUrl = $this->url."/shipping/$shipmentId/convert";
        $shipmentData = null !== $shipmentId ? $this->getShipment($shipmentId) : [];
        $this->context->smarty->assign(array(
            'url' => $convertUrl,
            'shipmentFlag' => $shipmentFlag,
            'isDeleted' => null === $shipmentData ? true : false,
            'isNew' => count($shipmentData) == 0 ? true : false,
            'SMARTSHIP_WEB_URL' => $this->url,
            'orderId' => $id_order,
            'img_dir' => _PS_IMG_DIR_,
            'trackingNumber' => empty($shipmentData) ? '' : $shipmentData['shipment']->tracking_number,
            'trackingUrl' => empty($shipmentData) ? '' : $this->getTrackingUrl($shipmentData)
        ));
        return $this->display(__FILE__, 'flagship.tpl');
    }

    public function prepareShipment(string $token, int $orderId) : string
    {
        $url = $this->getBaseUrl();
        try {
            $storeName = $this->context->shop->name;
            $flagship = new Flagship($token, $url, 'Prestashop', _PS_VERSION_);
            $payload = $this->getPayloadForShipment($orderId);
            $this->logger->logDebug("Payload for prepare shipment: ".json_encode($payload));
            $prepareShipment = $flagship->prepareShipmentRequest($payload)->setStoreName($storeName)->setOrderId($orderId);
            $prepareShipment = $prepareShipment->execute();
            $shipmentId = $prepareShipment->shipment->id;
            $this->logger->logDebug("Flagship shipment prepared for order id: ".$orderId);
            $this->updateOrder($shipmentId, $orderId);
            return $this->displayConfirmation('FlagShip Shipment Prepared : '.$shipmentId);
        } catch (Exception $e) {
            return $this->displayError($e->getMessage());
        }
    }

    public function updateShipment(string $token, int $orderId, int $shipmentId) : string
    {
        $url = $this->getBaseUrl();
        try {
            $storeName = $this->context->shop->name;
            $flagship = new Flagship($token, $url, 'Prestashop', _PS_VERSION_);
            $payload = $this->getPayloadForShipment($orderId);
            $this->logger->logDebug("Payload for upload shipment: ".json_encode($payload));
            $updateShipment = $flagship->editShipmentRequest($payload, $shipmentId)->setStoreName($storeName)->setOrderId($orderId);
            $updatedShipment = $updateShipment->execute();
            $updatedShipmentId = $updatedShipment->shipment->id;
            return $this->displayConfirmation('Updated! FlagShip Shipment: '.$updatedShipmentId);
        } catch (Exception $e) {
            return $this->displayError($e->getMessage());
        }
    }

    //do not use return type or argument type
    public function getOrderShippingCost($params, $shipping_cost)
    {
        if (Cache::isStored('packagesCount') && Cache::retrieve('packagesCount') == 0) {
            return false;
        }

        $currentController = Context::getContext()->controller->php_self;

        if (str_contains($currentController, 'order-detail')) {
            return $shipping_cost;
        }

        $id_address_delivery = Context::getContext()->cart->id_address_delivery;
        $address = new Address($id_address_delivery);
        if ($id_address_delivery == 0) {
            return $shipping_cost;
        }
       
        $carrier = new Carrier($this->id_carrier);
        if (isset(Context::getContext()->cookie->rates)) {
            $rate = explode(",", Context::getContext()->cookie->rate);
            $couriers = $this->getCouriers($rate);
            return !in_array($carrier->name, $couriers) ? false : $this->getShippingCost($rate, $carrier);
        }

        $token = Configuration::get('flagship_api_token');
        $url = $this->getBaseUrl();
        $payload = $this->getPayload($address);

        if (!isset(Context::getContext()->cookie->rates)) {
            $storeName = $this->context->shop->name;
            $this->logger->logDebug("Quotes payload: ".json_encode($payload));
            $quoteRequest = new FlagshipDetailedQuoteRequest($token, $url, $payload, 'Prestashop', _PS_VERSION_);
            $quoteRequest->setStoreName($storeName);
            $rates = $quoteRequest->executeWithDetails()->sortByPrice();
            $this->logPartialQuoteWarnings(
                (int)$quoteRequest->getResponseCode(),
                $quoteRequest->getRawResponse()
            );
            Context::getContext()->cookie->rates = 1;
            $ratesArray = $this->prepareRates($rates);
            $str = $this->getRatesString($ratesArray);
            Context::getContext()->cookie->rate = $str;
        }

        return $shipping_cost;
    }

    protected function getRatesString(array $ratesArray) : string
    {
        $str = '';
        foreach ($ratesArray as $value) {
            $str .= implode("-", $value).",";
        }
        $str = rtrim($str, ',');

        return $str;
    }

    protected function getShippingCost(array $rate, Carrier $carrier) : float
    {
        $shipping_cost = 0.00;
        foreach ($rate as $value) {
            $cost = floatVal(Tools::substr($value, strpos($value, "-")+1));
            $cost += floatVal((Configuration::get("flagship_markup")/100) * $cost);
            $cost += floatVal(Configuration::get('flagship_fee'));
            $shipping_cost=Tools::substr($value, 0, strpos($value, "-")) == $carrier->name ? $cost : $shipping_cost;
        }
        
        return $shipping_cost;
    }

    protected function getCouriers($rate){
        $couriers = [];
        foreach ($rate as $value) {
            $service = Tools::substr($value, 0, strpos($value, "-"));
            $couriers[] = strcasecmp($service, 'FedEx') === 0 ? 'FedEx '.$service : $service;
        }

        return $couriers;
    }

    public function getOrderShippingCostExternal($params) : bool
    {
        return true;
    }

    public function hookActionValidateCustomerAddressForm() : bool
    {
        unset(Context::getContext()->cookie->rates);
        unset(Context::getContext()->cookie->rate);
        
        return true;
    }

    public function hookActionCartSave() : bool
    {
        unset(Context::getContext()->cookie->rates);
        unset(Context::getContext()->cookie->rate);

        return true;
    }

    public function getBoxesString() : string
    {
        $boxes = '';
        $query = new DbQuery();
        $query->select('*')->from('flagship_boxes');

        $rows = Db::getInstance()->executeS($query);

        if (count($rows) == 0) {
            $boxes = 'No boxes set';
            return $boxes;
        }

        foreach ($rows as $row) {
            $boxes .= '<row id = "'.$row["id"].'"><a class="delete"';
            $boxes .= ' data-toggle="tooltip" title="Delete Box">';
            $boxes .= '<i class="icon icon-trash"></i></a>';
            $boxes .=' <strong>'.$row["model"].'</strong> : ';
            $boxes .= $row["length"].' x '.$row["width"].' x '.$row["height"];
            $boxes .= ' x '.$row["weight"].'<strong>Max Weight</strong> : ';
            $boxes .= $row["max_weight"].'</row><br/>';
        }
        return $boxes;
    }

    protected function getShipmentId(int $id_order) : ?int
    {
        $sql = new DbQuery();
        $sql->select('flagship_shipment_id');
        $sql->from('flagship_shipping', 'fs');
        $sql->where('fs.id_order = '.$id_order);
        $shipmentId = Db::getInstance()->executeS($sql);
        if (empty($shipmentId)) {
            return null;
        }
        return $shipmentId[0]['flagship_shipment_id'];
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm() : string
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitflagshipshippingModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm([$this->getConfigForm()]);
    }

    protected function renderBoxesForm() : string
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitflagshipshippingBoxModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm([$this->getBoxesForm()]);
    }

    protected function getStoreUnits()
    {
        $units = Configuration::get("PS_DIMENSION_UNIT");
        $units .= ",".Configuration::get("PS_WEIGHT_UNIT");
        return $units;
    }

    protected function getPayloadForShipment(int $orderId) : array
    {
        $from = [
            "name"=>substr(Configuration::get('PS_SHOP_NAME'),0,29),
            "attn"=>substr(Configuration::get('PS_SHOP_NAME'),0,20),
            "address"=>substr(Configuration::get('PS_SHOP_ADDR1'),0,29),
            "suite"=>substr(Configuration::get('PS_SHOP_ADDR2'),0,17),
            "city"=>Configuration::get('PS_SHOP_CITY'),
            "country"=>Country::getIsoById(Configuration::get('PS_SHOP_COUNTRY_ID')),
            "state"=>$this->getStateCode(Configuration::get('PS_SHOP_STATE_ID')),
            "postal_code"=>Configuration::get('PS_SHOP_CODE'),
            "phone"=> Configuration::get('PS_SHOP_PHONE'),
            "is_commercial"=>true
        ];

        $order = new Order($orderId);
        $addressTo = new Address($order->id_address_delivery);
        $customer = new Customer($order->id_customer);

        $products = $order->getProductsDetail();

        $name = empty($addressTo->company) ? $addressTo->firstname : $addressTo->company;
        $isCommercial = Configuration::get('flagship_residential') ? false : true;
        $driverInstructions = Configuration::get('flagship_email_on_label') ? $customer->email : '';
        $trackingEmail =  Configuration::get('flagship_tracking_email') ? $customer->email : Configuration::get('PS_SHOP_EMAIL');

        $to = [
            "name"=>substr($name,0,29),
            "attn"=>substr($addressTo->firstname.' '.$addressTo->lastname,0,20),
            "address"=>substr($addressTo->address1,0,29),
            "suite"=>substr($addressTo->address2,0,17),
            "city"=>$addressTo->city,
            "country"=>Country::getIsoById((int)$addressTo->id_country),
            "state"=>$this->getStateCode((int)$addressTo->id_state),
            "postal_code"=>$addressTo->postcode,
            "phone"=> $addressTo->phone,
            "is_commercial"=>$isCommercial
        ];

        $package = $this->getPackages($order);

        $options = [
            "signature_required"=>false,
            "reference"=>substr(substr(Configuration::get('PS_SHOP_NAME'), 0, 17)." Order#".$orderId, 0, 29),
            "driver_instructions"=>substr($driverInstructions,0,29),
            "shipment_tracking_emails"=> $trackingEmail
        ];

        $payment = [
            "payer"=>"F"
        ];

        $payload = [
            'from' => $from,
            'to'  => $to,
            'packages' => $package,
            'options' => $options,
            'payment' => $payment
        ];
        return $payload;
    }

    protected function getTotalWeight(array $products) : float
    {
        $total = 0;
        foreach ($products as $product) {
            $total += $product["weight"]*$product["product_quantity"];
        }
        if ($total<1) {
            $total = 1;
        }
        return $total;
    }

    protected function getWeightUnits() : string
    {
        if (Configuration::get('PS_WEIGHT_UNIT') === 'kg') {
            return 'metric';
        }
        return 'imperial';
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm() : array
    {
        return [
            'form' =>
            [
                'legend' =>
                [
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'col' => 4,
                        'type' => 'select',
                        'label' => $this->l('Test Environment'),
                        'desc' =>  $this->l('Use FlagShip\'s test environment. Any shipments made in the test environment will not be shipped.'),
                        'name' => 'flagship_test_env',
                        'options' => [
                            'query' => [
                                [
                                    'key' => 0,
                                    'name' => 'No'
                                ],
                                [
                                    'key' => 1,
                                    'name' => 'Yes'
                                ]
                            ],
                            'id' => 'key',
                            'name' => 'name',
                        ]

                    ],
                    [
                        'col' => 4,
                        'type' => 'text',
                        'desc' => Configuration::get('flagship_api_token') ? 'API Token is set'
                            : $this->l('Enter API Token'),
                        'name' => 'flagship_api_token',
                        'label' => $this->l('API Token'),
                    ],
                    [
                        'col' => 4,
                        'type' => 'text',
                        'name' => 'flagship_markup',
                        'label' =>$this->l('Percentage Markup'),
                        'desc' =>  $this->l('This percentage markup will be added to the rate quoted to the customer on your store front.'),
                    ],
                    [
                        'col' => 4,
                        'type' => 'text',
                        'label' => $this->l('Flat Handling Fee'),
                        'name' => 'flagship_fee',
                        'desc' =>  $this->l('This flat fee will be added to the rate quoted to the customer on your store front.'),
                    ],
                    [
                        'col' => 4,
                        'type' => 'select',
                        'label' => $this->l('Use Packing Api'),
                        'name' => 'flagship_packing_api',
                        'desc' =>  $this->l('If enabled, an algorithm will pack all products in the cart in the boxes provided below.'),
                        'options' => [
                            'query' => [
                                [
                                    'key' => 0,
                                    'name' => 'No'
                                ],
                                [
                                    'key' => 1,
                                    'name' => 'Yes'
                                ]
                            ],
                            'id' => 'key',
                            'name' => 'name',
                        ]
                    ],
                    [
                        'col' => 4,
                        'type' => 'select',
                        'label' => $this->l('Residential Shipments'),
                        'desc' =>  $this->l('Mark all shipments as residential'),
                        'name' => 'flagship_residential',
                        'options' => [
                            'query' => [
                                [
                                    'key' => 0,
                                    'name' => 'No'
                                ],
                                [
                                    'key' => 1,
                                    'name' => 'Yes'
                                ]
                            ],
                            'id' => 'key',
                            'name' => 'name',
                        ]
                    ],
                    [
                        'col' => 4,
                        'type' => 'select',
                        'label' => $this->l('Use customer email as tracking'),
                        'desc' =>  $this->l('Select if you want to use customer email as tracking email'),
                        'name' => 'flagship_tracking_email',
                        'options' => [
                            'query' => [
                                [
                                    'key' => 0,
                                    'name' => 'No'
                                ],
                                [
                                    'key' => 1,
                                    'name' => 'Yes'
                                ]
                            ],
                            'id' => 'key',
                            'name' => 'name',
                        ]
                    ],
                    [
                        'col' => 4,
                        'type' => 'select',
                        'label' => $this->l('Show customer email on shipping label'),
                        'desc' =>  $this->l('Select if you want to show customer email as reference on the shipping label'),
                        'name' => 'flagship_email_on_label',
                        'options' => [
                            'query' => [
                                [
                                    'key' => 0,
                                    'name' => 'No'
                                ],
                                [
                                    'key' => 1,
                                    'name' => 'Yes'
                                ]
                            ],
                            'id' => 'key',
                            'name' => 'name',
                        ]
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    protected function ensureCheckoutConfigDefaults() : void
    {
        $cleanOptions = Configuration::get(self::CONFIG_CLEAN_CHECKOUT_OPTIONS);
        if ($cleanOptions === false || $cleanOptions === null) {
            Configuration::updateValue(self::CONFIG_CLEAN_CHECKOUT_OPTIONS, 1);
        }

        $debugFlag = Configuration::get(self::CONFIG_DEBUG_PARTIAL_QUOTES);
        if ($debugFlag === false || $debugFlag === null) {
            Configuration::updateValue(self::CONFIG_DEBUG_PARTIAL_QUOTES, 1);
        }
    }

    protected function getBoxesForm() : array
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Add New Box (Units: '
                                .Configuration::get('PS_DIMENSION_UNIT').','
                                .Configuration::get('PS_WEIGHT_UNIT').')'),
                    'icon' => 'icon-plus-circle'
                ],
                'input' => [
                    [
                        'col' => 4,
                        'type' => 'text',
                        'name' => 'flagship_box_model',
                        'label' => $this->l('Box Model'),
                    ],
                    [
                        'col' => 4,
                        'type' => 'text',
                        'name' => 'flagship_box_length',
                        'label' => $this->l('Length'),
                    ],
                    [
                        'col' => 4,
                        'type' => 'text',
                        'name' => 'flagship_box_width',
                        'label' => $this->l('Width'),
                    ],
                    [
                        'col' => 4,
                        'type' => 'text',
                        'name' => 'flagship_box_height',
                        'label' => $this->l('Height'),
                    ],
                    [
                        'col' => 4,
                        'type' => 'text',
                        'name' => 'flagship_box_weight',
                        'label' => $this->l('Weight'),
                    ],
                    [
                        'col' => 4,
                        'type' => 'text',
                        'name' => 'flagship_box_max_weight',
                        'label' => $this->l('Max Weight'),
                    ]
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ]
            ]
        ];
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues() : array
    {
        $apiToken = Configuration::get('flagship_api_token') ? Configuration::get('flagship_api_token') : '';
        return [
            'flagship_test_env' => Configuration::get('flagship_test_env'),
            'flagship_api_token' => '',
            'flagship_markup' => Configuration::get('flagship_markup'),
            'flagship_fee' => Configuration::get('flagship_fee'),
            'flagship_residential' => Configuration::get('flagship_residential'),
            'flagship_email_on_label' => Configuration::get('flagship_email_on_label'),
            'flagship_packing_api' => Configuration::get('flagship_packing_api'),
            'flagship_tracking_email' => Configuration::get('flagship_tracking_email'),
        ];
    }

    /**
     * Save form data.
     */
    protected function postProcess() 
    {
        $apiToken = empty(Tools::getValue('flagship_api_token')) ?
                Configuration::get('flagship_api_token') :
                Tools::getValue('flagship_api_token');

        $fee = empty(Tools::getValue('flagship_fee')) ? 0 : Tools::getValue('flagship_fee');
        $markup = empty(Tools::getValue('flagship_markup')) ? 0 : Tools::getValue('flagship_markup');
        $residential = empty(Tools::getValue('flagship_residential')) ? 0 :
                    Tools::getValue('flagship_residential');
        $testEnv = empty(Tools::getValue('flagship_test_env')) ? 0 : Tools::getValue('flagship_test_env');
        $emailOnLabel = empty(Tools::getValue('flagship_email_on_label')) ? 0 : Tools::getValue('flagship_email_on_label');
        $packing = empty(Tools::getValue('flagship_packing_api')) ? 0 : Tools::getValue('flagship_packing_api');
        $trackingEmail = empty(Tools::getValue('flagship_tracking_email')) ? 0 : Tools::getValue('flagship_tracking_email');

        if (is_string(Configuration::get('flagship_fee')) && is_string(Configuration::get('flagship_api_token')) && is_string(Configuration::get('flagship_markup')) ) { //fields exist in db
            $feeFlag = $fee != Configuration::get('flagship_fee') ?
                                Configuration::updateValue('flagship_fee', $fee) : 0 ;
            $markupFlag = $markup != Configuration::get('flagship_markup') ?
                                Configuration::updateValue('flagship_markup', $markup) : 0 ;
            $residentialFlag = $residential != Configuration::get('flagship_residential') ?
                                Configuration::updateValue('flagship_residential', $residential) : 0 ;
            $testEnvFlag = $testEnv != Configuration::get('flagship_test_env') ?
                                Configuration::updateValue('flagship_test_env', $testEnv) : 0;
            $emailOnLabel = $emailOnLabel != Configuration::get('flagship_email_on_label') ?
                                Configuration::updateValue('flagship_email_on_label', $emailOnLabel) : 0;
            $trackingEmail = $trackingEmail != Configuration::get('flagship_tracking_email') ?
                                Configuration::updateValue('flagship_tracking_email', $trackingEmail) : 0;
            $packing = $packing != Configuration::get('flagship_packing_api') ?
                                Configuration::updateValue('flagship_packing_api', $packing) : 0;

            return $this->displayConfirmation($this->getReturnMessage($apiToken, $testEnv, $feeFlag, $markupFlag, $residentialFlag,$emailOnLabel, $packing));

        }

        if ($this->setApiToken($apiToken, $testEnv) && $this->setMarkup($markup) && $this->setHandlingFee($fee) && $this->setTestEnv($testEnv) && $this->setResidential($residential) && $this->setEmailOnLabel($emailOnLabel)) {
            $storeName = $this->context->shop->name;
            $url = $this->getBaseUrl();
            $flagship = new Flagship($apiToken, $url, 'Prestashop', _PS_VERSION_);
            $availableServices = $flagship->availableServicesRequest()->setStoreName($storeName)->execute();
            $this->prepareCarriers($availableServices);

            return $this->displayConfirmation($this->l('FlagShip Configured'));
        }
        return $this->displayWarning($this->l("Oops! Token is invalid or same token is set."));
    }

    protected function getReturnMessage(string $apiToken, int $testEnv, int $feeFlag, int $markupFlag, int $residentialFlag, int $emailOnLabel, int $packing) : string
    {
        $returnMessage = "<b>";
        $validToken = 0;
        if(strcmp($apiToken,Configuration::get('flagship_api_token')) != 0 && $this->isTokenValid($apiToken, $testEnv))
        {
            $validToken = Configuration::updateValue('flagship_api_token', $apiToken);
        }

        if($validToken == 1){
            $returnMessage .= "Token Updated! ";
        }

        if($validToken == 0){
            $returnMessage .= "Token not updated! ";
        }

        if($feeFlag || $markupFlag || $residentialFlag || $emailOnLabel || $packing){
            $returnMessage .= "Settings Updated";
        }

        $returnMessage .= "</b>";
        return $returnMessage;
    }

    protected function prepareCarriers($availableServices) : int {
        foreach ($availableServices as $availableService) {
            $carrier = $this->addCarrier($availableService);
            $this->addZones($carrier);
            $this->addGroups($carrier);
            $this->addRanges($carrier);
        }
        return 0;
    }

    protected function getBaseUrl() : string {
        $baseUrl = Configuration::get('flagship_test_env') == 1 ? SMARTSHIP_TEST_API_URL : SMARTSHIP_API_URL;
        return $baseUrl;
    }

    public function getApiBaseUrl() : string
    {
        return $this->getBaseUrl();
    }

    protected function setResidential(string $residential) : int {
        return Configuration::updateValue('flagship_residential', $residential);
    }

    protected function setTestEnv(string $testEnv) : int {
        return Configuration::updateValue('flagship_test_env', $testEnv);
    }

    protected function setEmailOnLabel(string $emailOnLabel) : int {
        return Configuration::updateValue('flagship_email_on_label', $emailOnLabel);
    }

    protected function setTrackingEmail(string $trackingEmail) : int {
        return Configuration::updateValue('flagship_tracking_email', $trackingEmail);
    }

    protected function insertBoxDetails() : string
    {
        $length = Tools::getValue('flagship_box_length');
        $width = Tools::getValue('flagship_box_width');
        $height = Tools::getValue('flagship_box_height');

        $girth = 2*$width + 2*$height;
        if ($this->getWeightUnits() == 'imperial' && ($length + $girth > 165) ||
        ($this->getWeightUnits() == 'metric' &&
        $this->validateMetricDimensions($length, $width, $height) > 165)) {
            return $this->displayWarning($this->l('Box too big'));
        }

        $data = [
            "model" => Tools::getValue('flagship_box_model'),
            "length" => Tools::getValue('flagship_box_length'),
            "width" => Tools::getValue('flagship_box_width'),
            "height" => Tools::getValue('flagship_box_height'),
            "weight" => Tools::getValue('flagship_box_weight'),
            "max_weight" => Tools::getValue('flagship_box_max_weight')
        ];

        Db::getInstance()->insert('flagship_boxes', $data);
        return $this->displayConfirmation($this->l('Box added'));
    }


    protected function validateMetricDimensions(float $length, float $width, float $height) : float
    {
        $length = $length/2.54;
        $width = $width/2.54;
        $height = $height/2.54;
        $girth = 2*$width + 2*$height;

        return $length + $girth;
    }


    protected function verifyToken(string $apiToken, int $testEnv) : bool
    {
        if ($this->isTokenValid($apiToken, $testEnv) && !$this->isCurrentTokenSame($apiToken)) {
            Configuration::updateValue('flagship_api_token', $apiToken);
            return true;
        }
        return false;
    }

    protected function setPacking(string $packing) : int 
    {
        return Configuration::updateValue('flagship_packing_api', $packing);
    }

    protected function setHandlingFee(string $fee) : int
    {
        return Configuration::updateValue('flagship_fee', $fee);
    }

    protected function setMarkup(string $markup) : int
    {
        return Configuration::updateValue('flagship_markup', $markup);
    }

    protected function isCurrentTokenSame(string $token) : bool
    {
        $currentToken = Configuration::get('flagship_api_token');
        if ($currentToken === $token) {
            return true;
        }
        return false;
    }

    protected function isTokenValid(string $token, int $testEnv) : bool
    {
        $url = $testEnv == 1 ? SMARTSHIP_TEST_API_URL : SMARTSHIP_API_URL;

        $flagship = new Flagship($token, $url, 'Prestashop', _PS_VERSION_); //storeName
        try {
            $storeName = $this->context->shop->name;
            $checkTokenRequest = $flagship->validateTokenRequest($token)->setStoreName($storeName);
            $checkTokenRequest->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    protected function setApiToken(string $apiToken, int $testEnv) : string
    {
        if (!$this->verifyToken($apiToken, $testEnv)) {
            return false;
        }
        Configuration::updateValue('flagship_api_token', $apiToken);
        return true;
    }

    protected function prepareRates(\Flagship\Shipping\Collections\RatesCollection $rates) : array
    {
        $ratesArray = [];
        foreach ($rates as $rate) {
            $ratesArray[] = [
                "courier" => $rate->getCourierName() == 'FedEx' ?
                    'FedEx '.$rate->getCourierDescription() :
                    $rate->getCourierDescription(),
                "subtotal" => $rate->getSubtotal(),
                "taxes" => $rate->getTaxesTotal()
            ];
        }
        return $ratesArray;
    }

    protected function getCourierImage(
        \Flagship\Shipping\Objects\Service $availableService,
        string $courier,
        string $img
    ) : string {
        if (stripos($availableService->getDescription(), $courier) === 0) {
            return Tools::strtolower($courier);
        }
        return $img;
    }

    protected function addCarrier(\Flagship\Shipping\Objects\Service $availableService) //Mixed return type
    {

        $carrier = new Carrier();

        $carrier->name = $this->l($availableService->getDescription());
        $carrier->is_module = true;
        $carrier->active = 1;
        $carrier->range_behavior = 1;
        $carrier->need_range = 1;
        $carrier->shipping_external = true;
        $carrier->range_behavior = 0;
        $carrier->external_module_name = $this->name;
        $carrier->shipping_method = 2;
        $img = 'fedex';

        $couriers = ['canpar','ups','purolator','dhl','gls','nationex'];

        foreach ($couriers as $courier) {
            $img = $this->getCourierImage($availableService, $courier, $img);
        }

        foreach (Language::getLanguages() as $lang) {
            $carrier->delay[$lang['id_lang']] = $this->l('Contact FlagShip');
        }

        if ($carrier->add() == true) {
            @copy(dirname(__FILE__).'/views/img/'.$img.'.png', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.jpg');
            Configuration::updateValue($this->name, (int)$carrier->id);

            $this->id_carrier = (int)$carrier->id;
            return $carrier;
        }

        return false;
    }

    protected function addGroups(Carrier $carrier) : int
    {
        $groups_ids = array();
        $groups = Group::getGroups(Context::getContext()->language->id);
        foreach ($groups as $group) {
            $groups_ids[] = $group['id_group'];
        }
        $carrier->setGroups($groups_ids);
        return 0;
    }

    protected function addRanges(Carrier $carrier) : int
    {
        $range_price = new RangePrice();
        $range_price->id_carrier = $carrier->id;
        $range_price->delimiter1 = '0';
        $range_price->delimiter2 = '10000';
        $range_price->add();

        $range_weight = new RangeWeight();
        $range_weight->id_carrier = $carrier->id;
        $range_weight->delimiter1 = '0';
        $range_weight->delimiter2 = '10000';
        $range_weight->add();

        return 0;
    }

    protected function addZones(Carrier $carrier) : int
    {
        $zones = Zone::getZones();
        foreach ($zones as $zone) {
            $carrier->addZone($zone['id_zone']);
        }
        return 0;
    }

    protected function getStateCode(int $code) : string
    {
        if ($code <= 0) {
            return '';
        }

        $isoCode = State::getIsoById($code);

        if ($isoCode === false || $isoCode === null) {
            return '';
        }

        return Tools::substr($isoCode, 0, 2);
    }

    protected function getPayload(Address $address) : array
    {
        $from = [
            "name" => $this->limitString(Configuration::get('PS_SHOP_NAME'), 29),
            "attn" => $this->limitString(Configuration::get('PS_SHOP_NAME'), 20),
            "address" => $this->limitString(Configuration::get('PS_SHOP_ADDR1'), 29),
            "suite" => $this->formatSuite(Configuration::get('PS_SHOP_ADDR2')),
            "city" => Configuration::get('PS_SHOP_CITY'),
            "country" => Country::getIsoById(Configuration::get('PS_SHOP_COUNTRY_ID')),
            "state" => $this->getStateCode((int)Configuration::get('PS_SHOP_STATE_ID')),
            "postal_code" => Configuration::get('PS_SHOP_CODE'),
            "phone" => Configuration::get('PS_SHOP_PHONE'),
            "is_commercial" => true
        ];

        $to = [
            "name" => $this->limitString($this->getDestinationName($address), 29),
            "attn" => $this->limitString(trim($address->firstname.' '.$address->lastname), 20),
            "address" => $this->limitString($address->address1, 29),
            "suite" => $this->formatSuite($address->address2),
            "city" => $address->city,
            "country" => Country::getIsoById($address->id_country),
            "state" => $this->getStateCode((int)$address->id_state),
            "postal_code" => $address->postcode,
            "phone" => !empty($address->phone) ? $address->phone : $address->phone_mobile,
            "is_commercial" => $this->isCommercialDestination($address)
        ];

        $packages = $this->normalizePackages($this->getPackages());

        $payment = [
            "payer" => "F"
        ];

        $options = $this->cleanCheckoutOptions([
            "address_correction" => true
        ]);

        return [
            "from" => $from,
            "to" => $to,
            "packages" => $packages,
            "payment" => $payment,
            "options" => $options
        ];
    }

    public function buildCheckoutPayload(Address $address) : array
    {
        return $this->getPayload($address);
    }

    protected function limitString(?string $value, int $length) : string
    {
        $trimmed = trim((string)$value);
        if ($trimmed === '') {
            return '';
        }
        return Tools::substr($trimmed, 0, $length);
    }

    protected function formatSuite(?string $value) : string
    {
        $suite = trim((string)$value);
        if ($suite === '') {
            return '';
        }
        return Tools::substr($suite, 0, 17);
    }

    protected function getDestinationName(Address $address) : string
    {
        if (!empty($address->company)) {
            return $address->company;
        }
        return trim($address->firstname.' '.$address->lastname);
    }

    protected function isCommercialDestination(Address $address) : bool
    {
        return !empty($address->company);
    }

    protected function normalizePackages(array $packages) : array
    {
        $packages['units'] = 'imperial';
        $packages['type'] = 'package';
        $packages['content'] = 'goods';

        return $packages;
    }

    protected function cleanCheckoutOptions(array $options) : array
    {
        if (!$this->shouldCleanCheckoutOptions()) {
            return $options;
        }

        foreach (self::CLEAN_CHECKOUT_OPTION_KEYS as $optionKey) {
            unset($options[$optionKey]);
        }

        return $options;
    }

    protected function shouldCleanCheckoutOptions() : bool
    {
        return (int)Configuration::get(self::CONFIG_CLEAN_CHECKOUT_OPTIONS) === 1;
    }

    protected function getItemDescription(?string $value) : string
    {
        $description = trim((string)$value);
        if ($description === '') {
            return 'Goods';
        }

        return Tools::substr($description, 0, 29);
    }

    protected function logPartialQuoteWarnings(int $statusCode, $response) : void
    {
        if ($statusCode !== 206 || (int)Configuration::get(self::CONFIG_DEBUG_PARTIAL_QUOTES) !== 1) {
            return;
        }

        $errors = $this->extractPartialQuoteErrors($response);

        if (empty($errors)) {
            return;
        }

        $message = '[FlagShip] Partial quote errors: '.implode('; ', $errors);
        PrestaShopLogger::addLog($message, 2, null, $this->name);
    }

    protected function extractPartialQuoteErrors($response) : array
    {
        if (!is_object($response)) {
            return [];
        }

        $errorSections = [];

        if (isset($response->errors)) {
            $errorSections[] = (array)$response->errors;
        }

        if (isset($response->content) && isset($response->content->errors)) {
            $errorSections[] = (array)$response->content->errors;
        }

        $messages = [];

        foreach ($errorSections as $errors) {
            foreach ($errors as $error) {
                if (is_string($error)) {
                    $messages[] = $error;
                    continue;
                }

                if (is_object($error)) {
                    $error = (array)$error;
                }

                if (!is_array($error)) {
                    continue;
                }

                $parts = [];

                $courier = $error['courier_name'] ?? $error['courier'] ?? null;
                if (!empty($courier)) {
                    $parts[] = $courier;
                }

                if (!empty($error['service']) && (empty($courier) || $error['service'] !== $courier)) {
                    $parts[] = $error['service'];
                }

                if (!empty($error['service_name'])) {
                    $parts[] = $error['service_name'];
                }

                $message = $error['message'] ?? $error['error'] ?? null;
                if (!empty($message)) {
                    $parts[] = $message;
                }

                $formatted = trim(implode(' ', $parts));
                if ($formatted !== '') {
                    $messages[] = $formatted;
                }
            }
        }

        return array_values(array_filter($messages));
    }

    protected function getBoxes() : array
    {
        $query = new DbQuery();
        $query->select('model,length,width,height,weight,max_weight')->from('flagship_boxes');

        $rows = Db::getInstance()->executeS($query);
        $boxes = [];
        foreach ($rows as $row) {
            $boxes[] = [
                "box_model" => $row["model"],
                "length" => (float)$this->getDimension($row["length"]),
                "width" => (float)$this->getDimension($row["width"]),
                "height" => (float)$this->getDimension($row["height"]),
                "weight" => (float)$this->getWeight($row["weight"]),
                "max_weight" => (float)$this->getWeight($row["max_weight"])
            ];
        }
        return $boxes;
    }

    protected function getPackages($order = null) : array
    {
        $products = is_null($order) ? Context::getContext()->cart->getProducts() : $order->getProductsDetail();
        $packages = [];
        $items = [];

        $boxes = $this->getBoxes();

        foreach ($products as $product) {
            if($product['is_virtual']) continue;
            $items = $this->getItemsByQty($product, $order, $items);
        }

        if(!Configuration::get('flagship_packing_api') || count($boxes) == 0){ //use items as they are if boxes are not set
            $temp = $items;

            return [
                'items' => $temp,
                "units" => "imperial",
                "type"  => "package",
                "content" => "goods"
            ];
        }

        $token = Configuration::get('flagship_api_token');
        $url = $this->getBaseUrl();
        $flagship = new Flagship($token, $url, 'Prestashop', _PS_VERSION_);
        $packingPayload = [
            'items' => $items,
            'boxes' => $boxes,
            'units' => "imperial"
        ];

        try{
            $this->logger->logDebug("Packing payload: ".json_encode($packingPayload));
            $packings = $flagship->packingRequest($packingPayload)->execute();
            $this->logger->logDebug("Packing response: ".json_encode($packings));
            $packedItems = $this->getPackedItems($packings);

            $packages = [
                "items" => $packedItems,
                "units" => "imperial",
                "type"  => "package",
                "content" => "goods"
            ];

            return $packages;
        } catch (PackingException $e) {
            $this->logger->logError("Error packing items: ".$e->getMessage());
            Cache::store('packagesCount', 0);
            return [];
        }
        
    }

    protected function getPackedItems(?\Flagship\Shipping\Collections\PackingCollection $packings = null) : array
    {
        if ($packings == null) {
            return [
                'length' => 1.0,
                'width' => 1.0,
                'height' => 1.0,
                'weight' => 1.0,
                'description' => 'Goods'
            ];
        }

        $packedItems = [];
        foreach ($packings as $packing) {
            $packedItems[] = [
                'length' => (float)$packing->getLength(),
                'width' => (float)$packing->getWidth(),
                'height' => (float)$packing->getHeight(),
                'weight' => max((float)$packing->getWeight(), 1.0),
                'description' => $this->getItemDescription($packing->getBoxModel())
            ];
        }

        return $packedItems;
    }

    protected function getItemsByQty($product, $order, $items) : array
    {
        $qty = is_null($order) ? $product["quantity"] : $product["product_quantity"];

        for ($i=0; $i < $qty; $i++) {
            $items[] = [
                "width"  => (float)$this->getDimension($product["width"]),
                "height" => (float)$this->getDimension($product["height"]),
                "length" => (float)$this->getDimension($product["depth"]),
                "weight" => (float)$this->getWeight($product["weight"]),
                "description" => $this->getItemDescription(is_null($order) ?
                    $product["name"] :
                    $product["product_name"])
            ];
        }
        return $items;
    }

    protected function getDimension($dimension)
    {
        if(Configuration::get('PS_DIMENSION_UNIT') === 'cm') {
            $dimension  = $dimension / 2.54;
        }
        if ($dimension <= 0) {
            return 1.0;
        }
        return (float)$dimension;
    }

    protected function getWeight($weight)
    {
        if(Configuration::get('PS_WEIGHT_UNIT') === 'kg') {
            $kgLbs = 2.20462;
            $weight = $weight * $kgLbs;
        }

        if ($weight <= 0) {
            return 1.0;
        }

        return (float)$weight;
    }

    protected function updateOrder(int $shipmentId, int $orderId) : bool
    {
        $data = [
            "id_order" => $orderId,
            "flagship_shipment_id" => $shipmentId
        ];
        return Db::getInstance()->insert('flagship_shipping', $data);
    }

    protected function getShipment(int $shipmentId) : array {
        $token = Configuration::get('flagship_api_token');
        $url = $this->getBaseUrl();
        $flagship = new Flagship($token, $url, 'Prestashop', _PS_VERSION_);
        try{
            $request = $flagship->getShipmentByIdRequest($shipmentId);
            return (array)$request->execute();
        } catch (GetShipmentByIdException $e) {
            $this->logger->logError("Error getting shipment: ".$e->getMessage());
            return [];
        }
        
    }

    protected function getTrackingUrl($shipment) : string {
        $courier = $shipment['shipment']->service->courier_name;
        $trackingNumber = $shipment['shipment']->tracking_number;
        switch ($courier) {
            case 'purolator':
                $url = 'https://eshiponline.purolator.com/ShipOnline/Public/Track/TrackingDetails.aspx?pup=Y&pin='.$trackingNumber.'&lang=E';
                break;
            case 'ups':
                $url = 'http://wwwapps.ups.com/WebTracking/track?HTMLVersion=5.0&loc=en_CA&Requester=UPSHome&trackNums='.$trackingNumber.'&track.x=Track';
                break;
            case 'gls':
                $url = "https://gls-group.com/CA/en/send-and-receive/track-a-shipment/?match=$trackingNumber";
                break;
            case 'dhl':
                $url = 'http://www.dhl.com/en/express/tracking.html?AWB='.$trackingNumber.'&brand=DHL';
                break;
            case 'fedex':
                $url = 'http://www.fedex.com/Tracking?ascend_header=1&clienttype=dotcomreg&track=y&cntry_code=ca_english&language=english&tracknumbers='.$trackingNumber.'&action=1&language=null&cntry_code=ca_english';
                break;
            case 'canpar':
                $url = 'https://www.canpar.com/en/track/TrackingAction.do?reference='.$trackingNumber.'&locale=en';
                break;
            case 'nationex':
                $url = 'https://www.nationex.com/en/track/tracking-report/?tracking[]='.$trackingNumber;
                break;
            case 'canadapost':
                $url = 'https://www.canadapost-postescanada.ca/track-reperage/en#/details/'.$trackingNumber;
                break;
            default:
                $url = "https://www.flagshipcompany.com/log-in/";
                break;
        }
        return $url;
    }

}

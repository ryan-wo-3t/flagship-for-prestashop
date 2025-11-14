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
if (file_exists(__DIR__ . '/classes/FlagshipPackingBox.php')) {
    require_once __DIR__ . '/classes/FlagshipPackingBox.php';
}
if (file_exists(__DIR__ . '/classes/FlagshipPackingItem.php')) {
    require_once __DIR__ . '/classes/FlagshipPackingItem.php';
}

use DVDoug\BoxPacker\NoBoxesAvailableException;
use DVDoug\BoxPacker\Packer;
use DVDoug\BoxPacker\PackedBoxList;
use Flagship\Shipping\Exceptions\GetShipmentByIdException;
use Flagship\Shipping\Exceptions\GetShipmentListException;
use Flagship\Shipping\Flagship;
use Flagship\Shipping\Objects\Shipment as FlagshipShipment;

//NO Trailing slashes please
define('SMARTSHIP_WEB_URL', 'https://smartship-ng.flagshipcompany.com');
define('SMARTSHIP_API_URL', 'https://api.smartship.io');
define('SMARTSHIP_TEST_API_URL', 'https://test-api.smartship.io');
define('SMARTSHIP_TEST_WEB_URL','https://test-smartshipng.flagshipcompany.com');

class FlagshipShipping extends CarrierModule
{
    public $id_carrier;
    protected $config_form = false;
    protected $url;
    protected $boxPackingWasUsed = false;
    protected $orderBlockRendered = false;
    protected const TRACKING_PLACEHOLDER = '@';

    public function __construct()
    {
        $this->name = 'flagshipshipping';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.270';
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
        $this->registerHook('displayAdminOrderMainBottom');
        $this->registerHook('displayAdminOrderMainBottom2');
        $this->registerHook('actionValidateCustomerAddressForm');
        $this->registerHook('actionCartSave');
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

        Configuration::updateValue('flagship_show_packing_layers', 0);
        foreach ($this->getTrackingUrlDefaults() as $carrier => $template) {
            Configuration::updateValue('flagship_tracking_url_'.$carrier, $template);
        }

        $this->logger->logDebug("Flagship for prestashop installed");
        return parent::install();
    }

    public function uninstall()
    {

        Configuration::deleteByName('flagship_api_token');
        Configuration::deleteByName('flagship_fee');
        Configuration::deleteByName('flagship_markup');
        Configuration::deleteByName('flagship_residential');
        Configuration::deleteByName('flagship_test_env');
        Configuration::deleteByName('flagship_show_packing_layers');
        foreach (array_keys($this->getTrackingUrlDefaults()) as $carrier) {
            Configuration::deleteByName('flagship_tracking_url_'.$carrier);
        }

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
        $output .= $this->renderTrackingLinksForm();
        return $output;
    }

    public function hookDisplayBackOfficeOrderActions(array $params)
    {
        $id_order = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        return $this->renderAdminOrderBlock($id_order);
    }

    public function hookDisplayAdminOrderSide(array $params)
    {
        $id_order = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        return $this->renderAdminOrderBlock($id_order);
    }

    public function hookDisplayAdminOrderMainBottom(array $params)
    {
        $id_order = $this->getOrderIdFromParams($params);
        return $this->renderAdminOrderBlock($id_order);
    }

    public function hookDisplayAdminOrderMainBottom2(array $params)
    {
        $id_order = $this->getOrderIdFromParams($params);
        return $this->renderAdminOrderBlock($id_order);
    }

    protected function getOrderIdFromParams(array $params) : int
    {
        if (isset($params['id_order'])) {
            return (int) $params['id_order'];
        }
        if (isset($params['orderId'])) {
            return (int) $params['orderId'];
        }
        if (isset($params['order']) && isset($params['order']['id'])) {
            return (int) $params['order']['id'];
        }

        return 0;
    }

    protected function renderAdminOrderBlock(int $id_order) : string
    {
        if ($this->orderBlockRendered || $id_order <= 0) {
            return '';
        }
        $this->orderBlockRendered = true;

        $this->url = Configuration::get('flagship_test_env') ? SMARTSHIP_TEST_WEB_URL : SMARTSHIP_WEB_URL;
        $order = new Order($id_order);
        $orderTrackingNumber = Validate::isLoadedObject($order) ? $this->getOrderTrackingNumber($order) : '';
        $trackingShipment = !empty($orderTrackingNumber) ? $this->findFlagshipShipmentByTracking($orderTrackingNumber) : null;
        $trackingIsFlagship = $trackingShipment instanceof FlagshipShipment;
        $trackingShipmentLink = $trackingIsFlagship ? $this->getFlagshipShipmentDashboardUrl((int)$trackingShipment->shipment->id) : '';
        $trackingCarrierLink = $trackingIsFlagship ? $this->getTrackingUrl(['shipment' => $trackingShipment->shipment]) : '';
        $trackingCourierName = $trackingIsFlagship ? $trackingShipment->shipment->service->courier_name : '';
        $trackingCourierDisplayName = $trackingCourierName ? $this->getCarrierDisplayName($trackingCourierName) : '';

        $shipmentId = $this->getShipmentId($id_order);
        $shipmentFlag = is_null($shipmentId) ? 0 : $shipmentId;
        $convertUrl = '';
        $isNewShipment = is_null($shipmentId);
        $shipmentData = $isNewShipment ? [] : $this->getShipment($shipmentId);
        if (empty($shipmentData) && $trackingIsFlagship) {
            $shipmentData = ['shipment' => $trackingShipment->shipment];
            $isNewShipment = false;
            $isDeletedShipment = false;
            if ($shipmentFlag != $trackingShipment->shipment->id) {
                $shipmentFlag = (int)$trackingShipment->shipment->id;
                $this->updateOrder($shipmentFlag, $id_order);
            }
        } else {
            $isDeletedShipment = !$isNewShipment && empty($shipmentData);
        }
        if ($shipmentFlag) {
            $convertUrl = $this->url."/shipping/$shipmentFlag/overview";
        }
        $packedBoxes = [];
        $showBoxSizeToggle = (bool) Configuration::get('flagship_show_box_size');
        $showPackingLayersToggle = (bool) Configuration::get('flagship_show_packing_layers');

        if ($showBoxSizeToggle && Configuration::get('flagship_packing_api') && Validate::isLoadedObject($order)) {
            $packageData = $this->getPackages($order);
            if ($this->boxPackingWasUsed && isset($packageData['items'])) {
                $packedBoxes = $this->formatPackedBoxesForDisplay($packageData['items']);
            }
        }

        $shipmentTrackingNumber = empty($shipmentData) ? '' : ($shipmentData['shipment']->tracking_number ?? '');
        $showBoxSizes = $showBoxSizeToggle && !empty($packedBoxes);
        $showPackingDetails = $showBoxSizes && $showPackingLayersToggle;
        $canModifyShipment = !$isDeletedShipment && empty($shipmentTrackingNumber) && !$trackingIsFlagship;
        $this->context->smarty->assign(array(
            'url' => $convertUrl,
            'shipmentFlag' => $shipmentFlag,
            'isDeleted' => $isDeletedShipment,
            'isNew' => $isNewShipment,
            'SMARTSHIP_WEB_URL' => $this->url,
            'orderId' => $id_order,
            'img_dir' => _PS_IMG_DIR_,
            'trackingNumber' => $shipmentTrackingNumber,
            'trackingUrl' => empty($shipmentData) ? '' : $this->getTrackingUrl($shipmentData),
            'packedBoxes' => $packedBoxes,
            'showBoxSizes' => $showBoxSizes,
            'showPackingDetails' => $showPackingDetails,
            'orderTrackingNumber' => $orderTrackingNumber,
            'trackingIsFlagship' => $trackingIsFlagship,
            'trackingCourierName' => $trackingCourierDisplayName,
            'trackingShipmentLink' => $trackingShipmentLink,
            'trackingCarrierLink' => $trackingCarrierLink,
            'canModifyShipment' => $canModifyShipment
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
            $orderLink = $this->getOrderAdminLink($orderId);
            $prepareShipment = $flagship->prepareShipmentRequest($payload)
                ->setStoreName($storeName)
                ->setOrderId($orderId)
                ->setOrderLink($orderLink);
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
            $orderLink = $this->getOrderAdminLink($orderId);
            $updateShipment = $flagship->editShipmentRequest($payload, $shipmentId)
                ->setStoreName($storeName)
                ->setOrderId($orderId)
                ->setOrderLink($orderLink);
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
        $flagship = new Flagship($token, $url, 'Prestashop', _PS_VERSION_);
        try {
            $payload = $this->getPayload($address);
        } catch (Exception $e) {
            $this->logger->logError("Unable to build FlagShip payload: ".$e->getMessage());
            return false;
        }

        if (!isset(Context::getContext()->cookie->rates)) {
            $storeName = $this->context->shop->name;
            $this->logger->logDebug("Quotes payload: ".json_encode($payload));
            $rates = $flagship->createQuoteRequest($payload)->setStoreName($storeName)->execute()->sortByPrice();
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
        $str = rtrim($str);

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

    protected function renderTrackingLinksForm() : string
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit'.$this->name.'Module';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getTrackingLinksFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm([$this->getTrackingLinksForm()]);
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
            "state"=>$this->getStateCode(
                (int) Configuration::get('PS_SHOP_STATE_ID'),
                (int) Configuration::get('PS_SHOP_COUNTRY_ID')
            ),
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
            "state"=>$this->getStateCode((int)$addressTo->id_state, (int)$addressTo->id_country),
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
                        'label' => $this->l('Show Packed Box Size on Orders'),
                        'name' => 'flagship_show_box_size',
                        'desc' =>  $this->l('Toggle the packed box summary visibility on the order page.'),
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
                        'label' => $this->l('Show Packing Layer Details'),
                        'name' => 'flagship_show_packing_layers',
                        'desc' =>  $this->l('Display per-layer packing details when box sizes are shown.'),
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
            'flagship_show_box_size' => Configuration::get('flagship_show_box_size'),
            'flagship_show_packing_layers' => Configuration::get('flagship_show_packing_layers'),
        ];
    }

    protected function getTrackingLinksFormValues() : array
    {
        $values = [];
        foreach ($this->getTrackingUrlConfig() as $carrier => $template) {
            $values['flagship_tracking_url_'.$carrier] = $template;
        }
        return $values;
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
        $showBoxSize = empty(Tools::getValue('flagship_show_box_size')) ? 0 : Tools::getValue('flagship_show_box_size');
        $showPackingLayers = empty(Tools::getValue('flagship_show_packing_layers')) ? 0 : Tools::getValue('flagship_show_packing_layers');
        $trackingUrlChanges = 0;
        $submittedTrackingUrls = [];
        foreach ($this->getTrackingUrlDefaults() as $carrier => $defaultUrl) {
            $fieldName = 'flagship_tracking_url_'.$carrier;
            $value = (string)Tools::getValue($fieldName, '');
            $submittedTrackingUrls[$carrier] = $this->sanitizeTrackingTemplate($value, $defaultUrl);
        }

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
            $showBoxSize = $showBoxSize != Configuration::get('flagship_show_box_size') ?
                                Configuration::updateValue('flagship_show_box_size', $showBoxSize) : 0;
            $showPackingLayers = $showPackingLayers != Configuration::get('flagship_show_packing_layers') ?
                                Configuration::updateValue('flagship_show_packing_layers', $showPackingLayers) : 0;
            foreach ($submittedTrackingUrls as $carrier => $template) {
                $key = 'flagship_tracking_url_'.$carrier;
                if ($template != Configuration::get($key)) {
                    Configuration::updateValue($key, $template);
                    $trackingUrlChanges = 1;
                }
            }

            $trackingUrlChanges = $this->updateCarrierTrackingTemplates($submittedTrackingUrls) ? 1 : $trackingUrlChanges;

            return $this->displayConfirmation($this->getReturnMessage($apiToken, $testEnv, $feeFlag, $markupFlag, $residentialFlag,$emailOnLabel, $packing, $showBoxSize, $showPackingLayers, $trackingUrlChanges));

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

    protected function getReturnMessage(string $apiToken, int $testEnv, int $feeFlag, int $markupFlag, int $residentialFlag, int $emailOnLabel, int $packing, int $showBoxSize, int $showPackingLayers, int $trackingUrls) : string
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

        if($feeFlag || $markupFlag || $residentialFlag || $emailOnLabel || $packing || $showBoxSize || $showPackingLayers || $trackingUrls){
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

        $trackingTemplates = $this->getTrackingUrlConfig();
        $carrierKey = $this->detectCarrierKeyFromName($availableService->getDescription());
        if ($carrierKey && isset($trackingTemplates[$carrierKey])) {
            $carrier->url = $trackingTemplates[$carrierKey];
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

    protected function getStateCode(int $stateId, int $countryId = 0) : string
    {
        $countryId = (int)$countryId;

        if ($stateId === 0) {
            $this->assertUsStateRequirement($countryId);
            return $this->getDefaultStateCode();
        }

        $sql = new DbQuery();
        $sql->select('s.iso_code, s.id_country');
        $sql->from('state', 's');
        $sql->where('s.id_state = '.(int)$stateId);

        $state = Db::getInstance()->getRow($sql);
        if (!$state) {
            $this->assertUsStateRequirement($countryId);
            return $this->getDefaultStateCode();
        }

        $iso = Tools::strtoupper($state['iso_code']);
        $resolvedCountryId = (int)$state['id_country'] ?: $countryId;

        if ($this->isUnitedStates($resolvedCountryId) && !$this->isValidUsState($iso)) {
            throw new Exception($this->l('US shipments require a valid two-letter state.'));
        }

        return empty($iso) ? $this->getDefaultStateCode() : $iso;
    }

    protected function assertUsStateRequirement(int $countryId) : void
    {
        if ($countryId > 0 && $this->isUnitedStates($countryId)) {
            throw new Exception($this->l('US shipments require a valid two-letter state.'));
        }
    }

    protected function getDefaultStateCode() : string
    {
        return 'QC';
    }

    protected function isUnitedStates(int $countryId) : bool
    {
        if ($countryId <= 0) {
            return false;
        }

        static $isoCache = [];
        if (!array_key_exists($countryId, $isoCache)) {
            $isoCache[$countryId] = Tools::strtoupper((string) Country::getIsoById($countryId));
        }

        return $isoCache[$countryId] === 'US';
    }

    protected function isValidUsState(string $stateCode) : bool
    {
        static $validStates = [
            'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA',
            'HI','ID','IL','IN','IA','KS','KY','LA','ME','MD',
            'MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ',
            'NM','NY','NC','ND','OH','OK','OR','PA','RI','SC',
            'SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'
        ];

        return in_array($stateCode, $validStates, true);
    }

    protected function getPayload(Address $address) : array
    {

        $from = [
            "city"=>Configuration::get('PS_SHOP_CITY'),
            "country"=>Country::getIsoById(Configuration::get('PS_SHOP_COUNTRY_ID')),
            "state"=>$this->getStateCode(
                (int) Configuration::get('PS_SHOP_STATE_ID'),
                (int) Configuration::get('PS_SHOP_COUNTRY_ID')
            ),
            "postal_code"=>Configuration::get('PS_SHOP_CODE'),
            "is_commercial"=>true
        ];

        $to = [
            "city"=>$address->city,
            "country"=>Country::getIsoById($address->id_country),
            "state"=>$this->getStateCode((int)$address->id_state, (int)$address->id_country),
            "postal_code"=>$address->postcode,
            "is_commercial"=> Configuration::get('flagship_residential') ? false : true
        ];
        $packages = $this->getPackages();

        $payment = [
            "payer" => "F"
        ];
        $options = [
            "address_correction" => true
        ];

        $payload = [
            "from" => $from,
            "to" => $to,
            "packages" => $packages,
            "payment" => $payment,
            "options" => $options
        ];

        return $payload;
    }

    protected function getBoxes() : array
    {
        $query = new DbQuery();
        $query->select('model,length,width,height,weight,max_weight')->from('flagship_boxes');

        $rows = Db::getInstance()->executeS($query);
        $boxes = [];
        $usePackingApi = (bool) Configuration::get('flagship_packing_api');
        foreach ($rows as $row) {
            if ($usePackingApi) {
                $boxes[] = [
                    "box_model" => $row["model"],
                    "length" => $this->getPackingDimension($row["length"]),
                    "width" => $this->getPackingDimension($row["width"]),
                    "height" => $this->getPackingDimension($row["height"]),
                    "weight" => $this->getPackingWeight($row["weight"]),
                    "max_weight" => $this->getPackingWeight($row["max_weight"])
                ];
                continue;
            }

            $boxes[] = [
                "box_model" => $row["model"],
                "length" => ceil($this->getDimension($row["length"])),
                "width" => ceil($this->getDimension($row["width"])),
                "height" => ceil($this->getDimension($row["height"])),
                "weight" => $this->getWeight($row["weight"]),
                "max_weight" => $this->getWeight($row["max_weight"])
            ];
        }
        return $boxes;
    }

    protected function getPackages($order = null) : array
    {
        $products = is_null($order) ? Context::getContext()->cart->getProducts() : $order->getProductsDetail();
        $items = [];
        $packingItems = [];
        $boxes = $this->getBoxes();
        $this->boxPackingWasUsed = false;
        $usePackingApi = (bool) Configuration::get('flagship_packing_api');

        foreach ($products as $product) {
            if ($product['is_virtual']) {
                continue;
            }
            $items = $this->getItemsByQty($product, $order, $items);
            if ($usePackingApi) {
                $packingItems = $this->getItemsByQty($product, $order, $packingItems, true);
            }
        }

        if (count($items) == 0) {
            return $this->buildPackageStructure($items);
        }

        if (!$usePackingApi || count($boxes) == 0) {
            return $this->buildPackageStructure($items);
        }

        $packingPayload = [
            'items' => $packingItems,
            'boxes' => $boxes,
            'units' => "metric"
        ];

        try{
            $this->logger->logDebug("Packing payload (BoxPacker): ".json_encode($packingPayload));
            $packedItems = $this->packItemsWithBoxPacker($packingItems, $boxes);
            $this->logger->logDebug("Packing response (BoxPacker): ".json_encode($packedItems));

            if (count($packedItems) === 0) {
                return $this->buildPackageStructure($items);
            }

            $this->boxPackingWasUsed = true;
            return $this->buildPackageStructure($packedItems);
        } catch (NoBoxesAvailableException $e) {
            $this->logger->logError("Error packing items: ".$e->getMessage());
            Cache::store('packagesCount', 0);
            return [];
        } catch (Exception $e) {
            $this->logger->logError("Error packing items: ".$e->getMessage());
            Cache::store('packagesCount', 0);
            return [];
        }

    }

    protected function buildPackageStructure(array $items) : array
    {
        return [
            'items' => $items,
            "units" => "imperial",
            "type"  => "package",
            "content" => "goods"
        ];
    }

    protected function packItemsWithBoxPacker(array $items, array $boxes) : array
    {
        $packer = new Packer();

        foreach ($boxes as $box) {
            $packer->addBox(
                new FlagshipPackingBox(
                    $box['box_model'],
                    (int)$box['width'],
                    (int)$box['length'],
                    (int)$box['height'],
                    (int)$box['weight'],
                    (int)$box['max_weight']
                )
            );
        }

        foreach ($items as $index => $item) {
            $description = array_key_exists('description', $item) ? $item['description'] : 'Item #'.($index + 1);
            $packer->addItem(
                new FlagshipPackingItem(
                    $description,
                    (int)$item['width'],
                    (int)$item['length'],
                    (int)$item['height'],
                    (int)$item['weight']
                )
            );
        }

        $packedBoxes = $packer->pack();

        return $this->getPackedItems($packedBoxes);
    }

    protected function getPackedItems(PackedBoxList $packings) : array
    {
        if ($packings == null || $packings->count() === 0) {
            return [];
        }

        $packedItems = [];
        foreach ($packings as $packing) {
            $layerSummary = $this->buildLayerSummary($packing);
            $packedItems[] = [
                'length' => $this->convertMillimetresToInches($packing->getBox()->getOuterLength()),
                'width' => $this->convertMillimetresToInches($packing->getBox()->getOuterWidth()),
                'height' => $this->convertMillimetresToInches($packing->getBox()->getOuterDepth()),
                'weight' => $this->convertGramsToPounds($packing->getWeight()),
                'description' => $packing->getBox()->getReference(),
                'packing_method' => empty($layerSummary) ? '' : $this->l('Layered packing order'),
                'layers' => $layerSummary
            ];
        }

        return $packedItems;
    }

    protected function getItemsByQty($product, $order, array $items, bool $forPacking = false) : array
    {
        $qty = is_null($order) ? $product["quantity"] : $product["product_quantity"];

        for ($i=0; $i < $qty; $i++) {
            $items[] = [
                "width"  => $forPacking ? $this->getPackingDimension($product["width"]) : $this->getDimension($product["width"]),
                "height" => $forPacking ? $this->getPackingDimension($product["height"]) : $this->getDimension($product["height"]),
                "length" => $forPacking ? $this->getPackingDimension($product["depth"]) : $this->getDimension($product["depth"]),
                "weight" => $forPacking ? $this->getPackingWeight($product["weight"]) : $this->getWeight($product["weight"]),
                "description"=>is_null($order) ? $product["name"] : $product["product_name"]
            ];
        }
        return $items;
    }

    protected function getDimension($dimension)
    {
        if(Configuration::get('PS_DIMENSION_UNIT') === 'cm') {
            $cmInches = 0.393701;
            $dimension  = $dimension * $cmInches;
        }
        if(!Configuration::get('flagship_packing_api') || 0 == $dimension ) {
            $dimension = max(ceil($dimension),1);
        }
        return $dimension;
    }

    protected function getPackingDimension($dimension) : int
    {
        $unit = Tools::strtolower(Configuration::get('PS_DIMENSION_UNIT'));
        switch ($unit) {
            case 'cm':
                $dimension *= 10;
                break;
            case 'm':
                $dimension *= 1000;
                break;
            case 'mm':
                // already in millimetres
                break;
            default:
                $dimension *= 25.4;
                break;
        }

        return max(1, (int)ceil($dimension));
    }

    protected function getWeight($weight)
    {
        if(Configuration::get('PS_WEIGHT_UNIT') === 'kg') {
            $kgLbs = 2.20462;
            $weight = $weight * $kgLbs;
        }

        if(!Configuration::get('flagship_packing_api') || 0 == $weight) {
            $weight = max(ceil($weight),1);
        }
        return $weight;
    }

    protected function getPackingWeight($weight) : int
    {
        $unit = Tools::strtolower(Configuration::get('PS_WEIGHT_UNIT'));
        switch ($unit) {
            case 'kg':
                $weight *= 1000;
                break;
            case 'g':
                // already in grams
                break;
            default:
                $weight *= 453.592;
                break;
        }

        return max(1, (int)ceil($weight));
    }

    protected function convertInchesToMillimetres(float $value) : int
    {
        return max(1, (int)ceil($value * 25.4));
    }

    protected function convertMillimetresToInches(int $value) : int
    {
        return max(1, (int)ceil($value / 25.4));
    }

    protected function convertPoundsToGrams(float $value) : int
    {
        return max(1, (int)ceil($value * 453.592));
    }

    protected function convertGramsToPounds(int $value) : int
    {
        return max(1, (int)ceil($value / 453.592));
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
        $courier = Tools::strtolower($shipment['shipment']->service->courier_name);
        $trackingNumber = $shipment['shipment']->tracking_number;
        $templates = $this->getTrackingUrlConfig();

        if (isset($templates[$courier])) {
            return $this->formatTrackingUrlTemplate($templates[$courier], $trackingNumber);
        }

        return "https://www.flagshipcompany.com/log-in/";
    }

    protected function formatPackedBoxesForDisplay(array $packedItems) : array
    {
        $formatted = [];
        foreach ($packedItems as $index => $packedItem) {
            if (!isset($packedItem['length'], $packedItem['width'], $packedItem['height'], $packedItem['weight'])) {
                continue;
            }

            $formatted[] = [
                'label' => empty($packedItem['description']) ? sprintf($this->l('Box %s'), $index + 1) : $packedItem['description'],
                'length' => (int)$packedItem['length'],
                'width' => (int)$packedItem['width'],
                'height' => (int)$packedItem['height'],
                'weight' => (int)$packedItem['weight'],
                'packing_method' => $packedItem['packing_method'] ?? '',
                'layers' => $packedItem['layers'] ?? []
            ];
        }

        return $formatted;
    }

    protected function buildLayerSummary(\DVDoug\BoxPacker\PackedBox $packedBox) : array
    {
        $layers = [];
        foreach ($packedBox->getItems() as $packedItem) {
            $layerStart = (int)$packedItem->getZ();
            if (!isset($layers[$layerStart])) {
                $layers[$layerStart] = [
                    'start_mm' => $layerStart,
                    'items' => []
                ];
            }
            $itemDescription = method_exists($packedItem->getItem(), 'getDescription') ?
                $packedItem->getItem()->getDescription() :
                $this->l('Item');
            $layers[$layerStart]['items'][] = $itemDescription;
        }

        if (empty($layers)) {
            return [];
        }

        ksort($layers);
        $summary = [];
        $layerNumber = 1;
        foreach ($layers as $layer) {
            $summary[] = [
                'title' => sprintf($this->l('Layer %d'), $layerNumber++),
                'start_mm' => $layer['start_mm'],
                'start_in' => $this->convertMillimetresToInches($layer['start_mm']),
                'items' => $layer['items']
            ];
        }

        return $summary;
    }

    protected function getTrackingUrlDefaults() : array
    {
        $p = self::TRACKING_PLACEHOLDER;
        return [
            'purolator' => 'https://www.purolator.com/en/shipping/tracker?pins='.$p,
            'ups' => 'https://www.ups.com/track?tracknum='.$p,
            'gls' => 'https://gls-canada.com/parcel-tracking?trackingNumber='.$p,
            'dhl' => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id='.$p,
            'fedex' => 'https://www.fedex.com/fedextrack/?tracknumbers='.$p,
            'canpar' => 'https://www.canpar.com/en/track/track.aspx?reference='.$p,
            'nationex' => 'https://www.nationex.com/en/tracking/?trackingNumber='.$p,
            'canadapost' => 'https://www.canadapost-postescanada.ca/track-reperage/en#/details/'.$p,
        ];
    }

    protected function getTrackingLinksForm() : array
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Carrier Tracking URL Templates'),
                    'icon' => 'icon-truck',
                ],
                'input' => array_merge(
                    [
                        [
                            'type' => 'html',
                            'name' => 'tracking_urls_info',
                            'html_content' => '<p class="text-muted mb-3">'.$this->l('Use @ where the tracking number should appear. Leave a field blank to restore the default link.').'</p>'
                        ],
                    ],
                    [
                        $this->buildTrackingUrlFormField('purolator', 'Purolator'),
                        $this->buildTrackingUrlFormField('ups', 'UPS'),
                        $this->buildTrackingUrlFormField('gls', 'GLS Canada'),
                        $this->buildTrackingUrlFormField('dhl', 'DHL Express'),
                        $this->buildTrackingUrlFormField('fedex', 'FedEx'),
                        $this->buildTrackingUrlFormField('canpar', 'Canpar'),
                        $this->buildTrackingUrlFormField('nationex', 'Nationex'),
                        $this->buildTrackingUrlFormField('canadapost', 'Canada Post'),
                    ]
                ),
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    protected function sanitizeTrackingTemplate(string $template, string $default) : string
    {
        $template = trim($template);
        if ($template === '' || $template === self::TRACKING_PLACEHOLDER) {
            return $default;
        }
        if (strpos($template, self::TRACKING_PLACEHOLDER) === false) {
            $template .= self::TRACKING_PLACEHOLDER;
        }
        return $template;
    }

    protected function getTrackingUrlConfig() : array
    {
        $defaults = $this->getTrackingUrlDefaults();
        $config = [];
        foreach ($defaults as $carrier => $defaultUrl) {
            $key = 'flagship_tracking_url_'.$carrier;
            $value = Configuration::get($key);
            $config[$carrier] = $this->sanitizeTrackingTemplate(empty($value) ? $defaultUrl : $value, $defaultUrl);
        }
        return $config;
    }

    protected function formatTrackingUrlTemplate(string $template, string $trackingNumber) : string
    {
        if (strpos($template, self::TRACKING_PLACEHOLDER) === false) {
            return rtrim($template, '/') . '/' . rawurlencode($trackingNumber);
        }

        return str_replace(self::TRACKING_PLACEHOLDER, rawurlencode($trackingNumber), $template);
    }

    protected function buildTrackingUrlFormField(string $carrier, string $label) : array
    {
        return [
            'col' => 6,
            'type' => 'text',
            'label' => sprintf($this->l('%s tracking URL template'), $label),
            'name' => 'flagship_tracking_url_'.$carrier,
            'desc' => $this->l('Use @ where the tracking number should appear. Leave blank to revert to the default.'),
        ];
    }

    protected function getCarrierAliasMap() : array
    {
        return [
            'purolator' => ['purolator'],
            'ups' => ['ups', 'united parcel service'],
            'gls' => ['gls'],
            'dhl' => ['dhl'],
            'fedex' => ['fedex'],
            'canpar' => ['canpar'],
            'nationex' => ['nationex'],
            'canadapost' => ['canada post', 'canadapost'],
        ];
    }

    protected function detectCarrierKeyFromName(string $name) : ?string
    {
        $normalized = Tools::strtolower($name);
        foreach ($this->getCarrierAliasMap() as $key => $aliases) {
            foreach ($aliases as $alias) {
                if (Tools::substr($normalized, 0, Tools::strlen($alias)) === $alias) {
                    return $key;
                }
            }
        }
        return null;
    }

    protected function getCarrierDisplayName(string $name) : string
    {
        $key = $this->detectCarrierKeyFromName($name);
        if (!$key) {
            return Tools::ucwords($name);
        }
        $labels = [
            'purolator' => 'Purolator',
            'ups' => 'UPS',
            'gls' => 'GLS',
            'dhl' => 'DHL',
            'fedex' => 'FedEx',
            'canpar' => 'CanPar',
            'nationex' => 'Nationex',
            'canadapost' => 'Canada Post',
        ];
        return $labels[$key] ?? Tools::ucwords($name);
    }

    protected function getOrderAdminLink(int $orderId) : string
    {
        return $this->context->link->getAdminLink(
            'AdminOrders',
            true,
            [],
            [
                'id_order' => $orderId,
                'vieworder' => 1,
            ]
        );
    }

    protected function updateCarrierTrackingTemplates(array $templates) : bool
    {
        $updated = false;
        $languageId = (int)$this->context->language->id;
        $carrierRows = Carrier::getCarriers(
            $languageId,
            false,
            false,
            false,
            null,
            Carrier::ALL_CARRIERS
        );
        $seen = [];
        foreach ($carrierRows as $row) {
            $carrierId = (int)$row['id_carrier'];
            if (isset($seen[$carrierId])) {
                continue;
            }
            $seen[$carrierId] = true;
            $carrier = new Carrier($carrierId);
            if ($carrier->external_module_name !== $this->name) {
                continue;
            }
            $key = $this->detectCarrierKeyFromName($carrier->name);
            if (!$key || !isset($templates[$key])) {
                continue;
            }
            $template = $templates[$key];
            if ($carrier->url !== $template) {
                $carrier->url = $template;
                $carrier->update();
                $updated = true;
            }
        }
        return $updated;
    }

    protected function getOrderTrackingNumber(Order $order) : string
    {
        if (!empty($order->shipping_number)) {
            return $order->shipping_number;
        }

        if (method_exists($order, 'getIdOrderCarrier')) {
            $idOrderCarrier = (int)$order->getIdOrderCarrier();
            if ($idOrderCarrier > 0) {
                $orderCarrier = new OrderCarrier($idOrderCarrier);
                if (!empty($orderCarrier->tracking_number)) {
                    return $orderCarrier->tracking_number;
                }
            }
        }

        $rows = Db::getInstance()->executeS('SELECT tracking_number FROM '._DB_PREFIX_.'order_carrier WHERE id_order = '.(int)$order->id.' ORDER BY date_add DESC');
        foreach ($rows as $row) {
            if (!empty($row['tracking_number'])) {
                return $row['tracking_number'];
            }
        }

        return '';
    }

    protected function findFlagshipShipmentByTracking(string $trackingNumber) : ?FlagshipShipment
    {
        if (empty($trackingNumber)) {
            return null;
        }
        $token = Configuration::get('flagship_api_token');
        if (empty($token)) {
            return null;
        }

        try {
            $flagship = new Flagship($token, $this->getBaseUrl(), 'Prestashop', _PS_VERSION_);
            $request = $flagship->getShipmentListRequest();
            $request->addFilter('tracking_number', rawurlencode($trackingNumber));
            $shipments = $request->execute();
            return $shipments->getByTrackingNumber($trackingNumber);
        } catch (GetShipmentListException $e) {
            $this->logger->logDebug("FlagShip tracking lookup empty: ".$e->getMessage());
            return null;
        } catch (Exception $e) {
            $this->logger->logError("FlagShip tracking lookup failed: ".$e->getMessage());
            return null;
        }
    }

    protected function getFlagshipShipmentDashboardUrl(int $shipmentId) : string
    {
        if ($shipmentId <= 0) {
            return '';
        }
        $base = Configuration::get('flagship_test_env') ? SMARTSHIP_TEST_WEB_URL : SMARTSHIP_WEB_URL;
        return rtrim($base, '/').'/shipping/'.$shipmentId.'/overview';
    }

}

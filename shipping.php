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

include dirname(__FILE__).'/../../config/config.inc.php';
include dirname(__FILE__).'/flagshipshipping.php';

$flagship = new FlagshipShipping();
$id_order = Tools::getValue('order_id');
$action = Tools::getValue('action');
$updateShipmentId = Tools::getValue('shipment_id');

$apiToken = Configuration::get('flagship_api_token');
header('Content-Type: application/json');

if ($action === 'update') {
    $update = $flagship->updateShipment($apiToken, $id_order, $updateShipmentId);
    echo json_encode([
        'success' => true,
        'message' => $update,
    ]);
    return 0;
}

$result = $flagship->prepareShipment($apiToken, $id_order);
echo json_encode($result);
return 0;

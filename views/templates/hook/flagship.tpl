{*
* FlagShip Courier Solutions
*
* NOTICE OF LICENSE
*
* This source file is subject to The MIT License
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to support@flagshipcompany.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author FlagShip Courier Solutions <support@flagshipcompany.com>
*  @copyright  FlagShip Courier Solutions
*  @license    https://opensource.org/licenses/MIT
*
*}
<script type="text/javascript">
	var orderId = "{$orderId|escape:'htmlall':'UTF-8'}";
	var shipmentId = "{$shipmentFlag|escape:'htmlall':'UTF-8'}";
</script>

{if $isNew}
	<a href="#" class="btn btn-default send_to_flagship" id="send_to_flagship"><i class="icon-truck"></i>Send To FlagShip</a>
{elseif $isDeleted}
	<p>Shipment cannot be retrieved. Please visit your FlagShip dashboard</p>
{else}
	<div>
	{if $shipmentFlag}
		{if !empty($trackingNumber) }
			Tracking Number: {$trackingNumber|escape:'htmlall':'UTF-8'}
			<br/>
			<a class="btn btn-default" href="{$trackingUrl|escape:'htmlall':'UTF-8'}" target="_blank" class="shipmentLink">
				Track your shipment
			</a>
		{else}
		<span class="success font-weight-bold">FlagShip Shipment: </span>
		<a href="{$url|escape:'htmlall':'UTF-8'}" target="_blank" class="shipmentLink">
		{$shipmentFlag|escape:'htmlall':'UTF-8'}
		</a>
		<br/>
		<a class="btn btn-default send_to_flagship" id="update_shipment">Update Shipment</a>
		<a class="btn btn-default convert" id="convert_shipment" href="{$url|escape:'htmlall':'UTF-8'}" target="_blank">Convert Shipment</a>
		{/if}
	{/if}
	</div>
{/if}

{if $showBoxSizes}
<div class="flagship-packed-boxes">
	<p><strong>{l s='Selected box sizes' mod='flagshipshipping'}</strong></p>
	<ul class="list-unstyled">
		{foreach from=$packedBoxes item=box}
			<li>
				{$box.label|escape:'htmlall':'UTF-8'} - {$box.length|escape:'htmlall':'UTF-8'} x {$box.width|escape:'htmlall':'UTF-8'} x {$box.height|escape:'htmlall':'UTF-8'} {l s='in' mod='flagshipshipping'} ({$box.weight|escape:'htmlall':'UTF-8'} {l s='lb' mod='flagshipshipping'})
			</li>
		{/foreach}
	</ul>
</div>
{/if}

<div class="response"><img src="{$base_url|escape:'htmlall':'UTF-8'}img/loader.gif" alt="Loading..." id="loading-image"/>
</div>
<script>

	var url = "{$module_dir|escape:'htmlall':'UTF-8'}shipping.php";
	var action = 'prepare';
	var msg = 'FlagShip Shipment: ';

	$(document).ajaxStart(function(){
		$("#loading-image").show();
	});
	$(document).ajaxStop(function(){
		$("#loading-image").hide();
	});

	$(document).ready(function(){
		$("#loading-image").hide();
		$(".send_to_flagship").click(function(e){
			if($(this).attr('id') == 'update_shipment'){
				action = 'update';
			}

			e.preventDefault();

			$.ajax({
			  url : url,
			  type : 'POST',
			  data : {
			  	order_id : orderId,
			  	shipment_id : shipmentId,
			  	action : action
			  },

			  success : function(response){
			  	$(".response").html(response);
			  	$("#send_to_flagship").hide();
			  	return 0;
			  }
			});
		});
	});
</script>

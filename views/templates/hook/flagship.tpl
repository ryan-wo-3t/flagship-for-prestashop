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
{else}
<div class="card flagship-shipment mt-3">
	<div class="card-body">
		<div class="d-flex flex-wrap justify-content-between align-items-center">
			<div>
				<p class="card-title font-weight-bold mb-1">{l s='FlagShip Shipment' mod='flagshipshipping'}</p>
				{if $shipmentFlag}
					<p class="mb-2">
						<strong>{l s='Shipment ID' mod='flagshipshipping'}:</strong>
						{if !$isDeleted && $url}
						<a href="{$url|escape:'htmlall':'UTF-8'}" target="_blank" class="shipmentLink">
							{$shipmentFlag|escape:'htmlall':'UTF-8'}
						</a>
						{else}
							{$shipmentFlag|escape:'htmlall':'UTF-8'}
						{/if}
					</p>
				{/if}

				{if $trackingIsFlagship}
					<p class="mb-0">
						<strong>{l s='Tracking number' mod='flagshipshipping'}:</strong>
						{$orderTrackingNumber|escape:'htmlall':'UTF-8'}
					</p>
				{elseif !empty($trackingNumber)}
					<p class="mb-0">
						<strong>{l s='Tracking number' mod='flagshipshipping'}:</strong>
						{$trackingNumber|escape:'htmlall':'UTF-8'}
					</p>
				{/if}
			</div>

			<div class="btn-group mb-2">
				{if !$isDeleted && $shipmentFlag}
					<a class="btn btn-default send_to_flagship" id="update_shipment">{l s='Update Shipment' mod='flagshipshipping'}</a>
					<a class="btn btn-default convert" id="convert_shipment" href="{$url|escape:'htmlall':'UTF-8'}" target="_blank">{l s='Convert Shipment' mod='flagshipshipping'}</a>
				{/if}
			</div>
		</div>

		{if $isDeleted}
			<div class="alert alert-warning mt-3 mb-0">
				{l s='Shipment cannot be retrieved. Please visit your FlagShip dashboard.' mod='flagshipshipping'}
			</div>
		{else}
			{if $trackingIsFlagship}
				<div class="btn-group mt-3">
					{if $trackingShipmentLink}
					<a class="btn btn-outline-primary" href="{$trackingShipmentLink|escape:'htmlall':'UTF-8'}" target="_blank">
						{l s='View in FlagShip' mod='flagshipshipping'}
					</a>
					{/if}
					{if $trackingCarrierLink}
					<a class="btn btn-outline-secondary" href="{$trackingCarrierLink|escape:'htmlall':'UTF-8'}" target="_blank">
						{if $trackingCourierName}
							{l s='Track with %s' sprintf=[$trackingCourierName] mod='flagshipshipping'}
						{else}
							{l s='Track with carrier' mod='flagshipshipping'}
						{/if}
					</a>
					{/if}
				</div>
			{elseif $trackingUrl}
				<div class="mt-3">
					<a class="btn btn-outline-secondary" href="{$trackingUrl|escape:'htmlall':'UTF-8'}" target="_blank">
						{l s='Track with carrier' mod='flagshipshipping'}
					</a>
				</div>
			{/if}
		{/if}
	</div>
</div>
{/if}

{if $showBoxSizes}
<div class="flagship-packed-boxes card mt-3">
	<div class="card-body">
		<p class="card-title font-weight-bold mb-3">{l s='Selected box sizes' mod='flagshipshipping'}</p>
		{foreach from=$packedBoxes item=box}
			<div class="flagship-packed-box border rounded p-3 mb-3">
				<div class="d-flex justify-content-between align-items-center flex-wrap">
					<span class="h6 mb-2 mb-sm-0">{$box.label|escape:'htmlall':'UTF-8'}</span>
					<span class="text-muted">
						{$box.length|escape:'htmlall':'UTF-8'} &times; {$box.width|escape:'htmlall':'UTF-8'} &times; {$box.height|escape:'htmlall':'UTF-8'} {l s='in' mod='flagshipshipping'}
						&middot; {$box.weight|escape:'htmlall':'UTF-8'} {l s='lb' mod='flagshipshipping'}
					</span>
				</div>
				{if $showPackingDetails && !empty($box.packing_method)}
					<div class="small text-muted mt-2">
						<strong>{l s='Packing method' mod='flagshipshipping'}:</strong> {$box.packing_method|escape:'htmlall':'UTF-8'}
					</div>
				{/if}
				{if $showPackingDetails && !empty($box.layers)}
					<div class="flagship-packed-layers mt-2">
						{foreach from=$box.layers item=layer}
							<div class="border-left pl-3 py-2 mb-2 bg-light">
								<span class="badge badge-secondary mr-2">{$layer.title|escape:'htmlall':'UTF-8'}</span>
								<span class="text-muted">
									{l s='Start' mod='flagshipshipping'}: {$layer.start_in|escape:'htmlall':'UTF-8'} {l s='in' mod='flagshipshipping'} / {$layer.start_mm|escape:'htmlall':'UTF-8'} {l s='mm' mod='flagshipshipping'}
								</span>
								<div class="mt-1">
									{$layer.items|@implode:', '|escape:'htmlall':'UTF-8'}
								</div>
							</div>
						{/foreach}
					</div>
				{/if}
			</div>
		{/foreach}
	</div>
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


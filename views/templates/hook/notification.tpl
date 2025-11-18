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
{if $tagMismatch}
<div class="panel">
<div class = "panel-body" style="font-size:15px;">
    	You are using an older version of FlagShip For PrestaShop. Please update to the latest version.
        {if isset($latestDownloadUrl) && $latestDownloadUrl}
        <div class="mt-2">
            <a class="btn btn-primary" href="{$latestDownloadUrl|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">
                {l s='Download module zip file' mod='flagshipshipping'}
            </a>
        </div>
        {/if}
    </div>
</div>
{/if}

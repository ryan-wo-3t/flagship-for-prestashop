<div class="panel">
    <h3>{l s='FlagShip package summary' mod='flagshipshipping'}</h3>
    {if $flagship_package_summary && $flagship_package_summary.items|count > 0}
        <p class="text-muted">
            {l s='Units' mod='flagshipshipping'}: {$flagship_package_summary.units|escape:'html':'UTF-8'}
            • {l s='Type' mod='flagshipshipping'}: {$flagship_package_summary.type|escape:'html':'UTF-8'}
            • {l s='Content' mod='flagshipshipping'}: {$flagship_package_summary.content|escape:'html':'UTF-8'}
        </p>
        <table class="table table-bordered table-condensed">
            <thead>
                <tr>
                    <th>#</th>
                    <th>{l s='Description' mod='flagshipshipping'}</th>
                    <th>{l s='Length' mod='flagshipshipping'}</th>
                    <th>{l s='Width' mod='flagshipshipping'}</th>
                    <th>{l s='Height' mod='flagshipshipping'}</th>
                    <th>{l s='Weight' mod='flagshipshipping'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$flagship_package_summary.items item=item}
                    <tr>
                        <td>{$item.index}</td>
                        <td>{$item.description|escape:'html':'UTF-8'}</td>
                        <td>{$item.length}</td>
                        <td>{$item.width}</td>
                        <td>{$item.height}</td>
                        <td>{$item.weight}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {else}
        <p class="text-muted">
            {l s='Package information is not available for this order yet.' mod='flagshipshipping'}
        </p>
    {/if}
</div>

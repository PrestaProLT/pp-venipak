{if $venipak_tracking}
<div class="ppvenipak-tracking">
    <p>{l s='Tracking number' d='Modules.Ppvenipak.Shop' mod='ppvenipak'}:
       <strong>{$venipak_tracking}</strong>
       <a href="{$tracking_url}" target="_blank">{l s='Track shipment' d='Modules.Ppvenipak.Shop' mod='ppvenipak'}</a>
    </p>
</div>
{/if}

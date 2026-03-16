{* Venipak carrier extra content — checkout *}
{if $carrier_type == 'pickup'}
    <div class="ppvenipak-pickup-selector" data-terminals-url="{$terminals_url}">
        <p>{l s='Select pickup point' d='Modules.Ppvenipak.Shop' mod='ppvenipak'}</p>
        <div class="ppvenipak-pickup-selector__map" id="ppvenipak-map"></div>
        <div class="ppvenipak-pickup-selector__list" id="ppvenipak-terminal-list"></div>
    </div>
{elseif $carrier_type == 'courier'}
    <div class="ppvenipak-courier-fields">
        {* Extra fields rendered here based on config *}
    </div>
{/if}

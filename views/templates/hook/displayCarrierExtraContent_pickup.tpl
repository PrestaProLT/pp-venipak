<div class="ppvenipak-pickup" data-ajax-url="{$ppvenipak_ajax_url}" data-country="{$ppvenipak_country_code}" data-carrier-id="{$ppvenipak_carrier_id}">

    {* Search bar *}
    <div class="ppvenipak-pickup__search">
        <input type="text" class="ppvenipak-pickup__search-input js-ppvenipak-search"
               placeholder="{l s='Search by city or postcode' d='Modules.Ppvenipak.Shop' mod='ppvenipak'}" />
    </div>

    {* Selected terminal display *}
    <div class="ppvenipak-pickup__selected js-ppvenipak-selected" {if !$ppvenipak_selected_terminal}style="display:none"{/if}>
        <div class="ppvenipak-pickup__selected-icon">&#10003;</div>
        <div class="ppvenipak-pickup__selected-info">
            <strong class="js-ppvenipak-selected-name">{if $ppvenipak_selected_terminal}{$ppvenipak_selected_terminal.info.name}{/if}</strong>
            <span class="js-ppvenipak-selected-address">{if $ppvenipak_selected_terminal}{$ppvenipak_selected_terminal.info.address}, {$ppvenipak_selected_terminal.info.city}{/if}</span>
        </div>
        <button type="button" class="ppvenipak-pickup__change-btn js-ppvenipak-change">
            {l s='Change' d='Modules.Ppvenipak.Shop' mod='ppvenipak'}
        </button>
    </div>

    {* Map container *}
    <div class="ppvenipak-pickup__map-container js-ppvenipak-map-wrap" {if $ppvenipak_selected_terminal}style="display:none"{/if}>
        <div id="ppvenipak-map" class="ppvenipak-pickup__map"></div>
    </div>

    {* Terminal list (below map) *}
    <div class="ppvenipak-pickup__list js-ppvenipak-list-wrap" {if $ppvenipak_selected_terminal}style="display:none"{/if}>
        <div class="ppvenipak-pickup__list-items js-ppvenipak-list">
            {* Populated via JS *}
        </div>
    </div>

    {* Hidden input for validation *}
    <input type="hidden" name="ppvenipak_terminal_id" class="js-ppvenipak-terminal-id"
           value="{if $ppvenipak_selected_terminal}{$ppvenipak_selected_terminal.id}{/if}" />

    {* Error message area *}
    <div class="ppvenipak-pickup__error js-ppvenipak-error" style="display:none"></div>
</div>

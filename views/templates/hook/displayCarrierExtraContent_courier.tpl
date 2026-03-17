<div class="ppvenipak-courier" data-ajax-url="{$ppvenipak_ajax_url}" data-carrier-id="{$ppvenipak_carrier_id}">

    {if $ppvenipak_show_door_code}
    <div class="ppvenipak-courier__field">
        <label for="ppvenipak-door-code">{l s='Door code' d='Modules.Ppvenipak.Shop' mod='ppvenipak'}</label>
        <input type="text" id="ppvenipak-door-code" name="ppvenipak_door_code" maxlength="10"
               class="ppvenipak-courier__input js-ppvenipak-extra-field"
               value="{$ppvenipak_saved_fields.door_code|default:''}" />
    </div>
    {/if}

    {if $ppvenipak_show_cabinet_no}
    <div class="ppvenipak-courier__field">
        <label for="ppvenipak-cabinet">{l s='Cabinet number' d='Modules.Ppvenipak.Shop' mod='ppvenipak'}</label>
        <input type="text" id="ppvenipak-cabinet" name="ppvenipak_cabinet_number" maxlength="10"
               class="ppvenipak-courier__input js-ppvenipak-extra-field"
               value="{$ppvenipak_saved_fields.cabinet_number|default:''}" />
    </div>
    {/if}

    {if $ppvenipak_show_warehouse_no}
    <div class="ppvenipak-courier__field">
        <label for="ppvenipak-warehouse">{l s='Warehouse number' d='Modules.Ppvenipak.Shop' mod='ppvenipak'}</label>
        <input type="text" id="ppvenipak-warehouse" name="ppvenipak_warehouse_number" maxlength="10"
               class="ppvenipak-courier__input js-ppvenipak-extra-field"
               value="{$ppvenipak_saved_fields.warehouse_number|default:''}" />
    </div>
    {/if}

    {if $ppvenipak_show_delivery_time && $ppvenipak_time_options|count > 0}
    <div class="ppvenipak-courier__field">
        <label for="ppvenipak-delivery-time">{l s='Preferred delivery time' d='Modules.Ppvenipak.Shop' mod='ppvenipak'}</label>
        <select id="ppvenipak-delivery-time" name="ppvenipak_delivery_time"
                class="ppvenipak-courier__select js-ppvenipak-extra-field">
            {foreach $ppvenipak_time_options as $value => $label}
                <option value="{$value}" {if $ppvenipak_saved_fields.delivery_time|default:'' == $value}selected{/if}>{$label}</option>
            {/foreach}
        </select>
    </div>
    {/if}

    {if $ppvenipak_show_call_before}
    <div class="ppvenipak-courier__field ppvenipak-courier__field--checkbox">
        <label>
            <input type="checkbox" name="ppvenipak_carrier_call" value="1"
                   class="ppvenipak-courier__checkbox js-ppvenipak-extra-field"
                   {if $ppvenipak_saved_fields.carrier_call|default:0}checked{/if} />
            {l s='Call before delivery' d='Modules.Ppvenipak.Shop' mod='ppvenipak'}
        </label>
    </div>
    {/if}

</div>

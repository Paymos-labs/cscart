<div class="control-group">
    <label class="control-label" for="paymos_mode">{__("paymos.mode")}</label>
    <div class="controls">
        <select name="payment_data[processor_params][mode]" id="paymos_mode">
            <option value="sandbox" {if $processor_params.mode == "sandbox" || !$processor_params.mode}selected="selected"{/if}>{__("paymos.mode_sandbox")}</option>
            <option value="live" {if $processor_params.mode == "live"}selected="selected"{/if}>{__("paymos.mode_live")}</option>
        </select>
        <p class="muted description">{__("paymos.mode_description")}</p>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="paymos_pending_status">{__("paymos.pending_status")}</label>
    <div class="controls">
        <input type="text"
               name="payment_data[processor_params][pending_status]"
               id="paymos_pending_status"
               value="{$processor_params.pending_status|default:"O"|escape}"
               class="input-small" />
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="paymos_paid_status">{__("paymos.paid_status")}</label>
    <div class="controls">
        <input type="text"
               name="payment_data[processor_params][paid_status]"
               id="paymos_paid_status"
               value="{$processor_params.paid_status|default:"P"|escape}"
               class="input-small" />
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="paymos_confirming_status">{__("paymos.confirming_status")}</label>
    <div class="controls">
        <input type="text"
               name="payment_data[processor_params][confirming_status]"
               id="paymos_confirming_status"
               value="{$processor_params.confirming_status|default:"O"|escape}"
               class="input-small" />
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="paymos_failed_status">{__("paymos.failed_status")}</label>
    <div class="controls">
        <input type="text"
               name="payment_data[processor_params][failed_status]"
               id="paymos_failed_status"
               value="{$processor_params.failed_status|default:"F"|escape}"
               class="input-small" />
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="paymos_cancelled_status">{__("paymos.cancelled_status")}</label>
    <div class="controls">
        <input type="text"
               name="payment_data[processor_params][cancelled_status]"
               id="paymos_cancelled_status"
               value="{$processor_params.cancelled_status|default:"D"|escape}"
               class="input-small" />
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="paymos_debug_logging">{__("paymos.debug_logging")}</label>
    <div class="controls">
        <input type="hidden" name="payment_data[processor_params][debug_logging]" value="N" />
        <input type="checkbox"
               name="payment_data[processor_params][debug_logging]"
               id="paymos_debug_logging"
               value="Y"
               {if $processor_params.debug_logging == "Y"}checked="checked"{/if} />
    </div>
</div>

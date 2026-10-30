{capture name="mainbox"}
<p>Order #{$paymos_refund_panel.order_id|escape}</p>
<dl class="dl-horizontal">
    <dt>Full refund</dt><dd>{$paymos_refund_panel.summary.full_refund_amount|default:"—"|escape} {$paymos_refund_panel.summary.refund_asset.currency|escape}</dd>
    <dt>Overpayment refund</dt><dd>{$paymos_refund_panel.summary.overpayment_refund_amount|default:"—"|escape} {$paymos_refund_panel.summary.refund_asset.currency|escape}</dd>
    <dt>Recipient</dt><dd>{$paymos_refund_panel.summary.sender_address|default:"—"|escape}</dd>
</dl>
{if !$paymos_refund_panel.can_full && !$paymos_refund_panel.can_overpayment}<p class="muted" role="status">Refunds through Paymos are not available for this order right now. If this happens on every order, reconnect the add-on with Connect Paymos in the payment method settings.</p>{/if}
<form action="{"paymos.refund"|fn_url}" method="post">
    <input type="hidden" name="security_hash" value="{$paymos_refund_security_hash|escape}" />
    <input type="hidden" name="order_id" value="{$paymos_refund_panel.order_id|escape}" />
    <input type="hidden" name="request_token" value="{$paymos_refund_panel.request_token|escape}" />
    <button type="submit" name="refund_mode" value="full" class="btn" data-copy="refund_full_action" {if !$paymos_refund_panel.can_full}disabled{/if}>Refund in full</button>
    <button type="submit" name="refund_mode" value="overpayment" class="btn" data-copy="refund_overpayment_action" {if !$paymos_refund_panel.can_overpayment}disabled{/if}>Refund overpayment</button>
</form>
{foreach $paymos_refund_panel.operations as $operation}
<div class="well">
    <dl class="dl-horizontal">
        <dt>Type</dt><dd>{$operation.mode|escape}</dd>
        <dt>Transfer / record</dt><dd>{$operation.cash_state|escape} / {$operation.projection_state|escape}</dd>
        <dt>Amount</dt><dd>{$operation.observation.amount|default:"—"|escape} {$operation.observation.currency|escape} {$operation.observation.network|escape}</dd>
        <dt>Recipient</dt><dd>{$operation.observation.first_sender|default:"—"|escape}</dd>
        {if $operation.full_draft}<dt>Order total</dt><dd>{$operation.full_draft.amount|escape} {$operation.full_draft.currency|escape}</dd>{/if}
        {if $operation.review_reason}<dt>Reason</dt><dd>{$operation.review_reason|escape}</dd>{/if}
    </dl>
    <form action="{"paymos.refund_reconcile"|fn_url}" method="post">
        <input type="hidden" name="security_hash" value="{$paymos_refund_security_hash|escape}" />
        <input type="hidden" name="order_id" value="{$paymos_refund_panel.order_id|escape}" />
        <input type="hidden" name="operation_id" value="{$operation.operation_id|escape}" />
        <button type="submit" class="btn">{if $operation.mode == "full"}Check full refund{else}Check overpayment refund{/if}</button>
    </form>
</div>
{/foreach}
{foreach $paymos_refund_panel.unbound as $observation}
<div class="well">
    <p>Refund made outside CS-Cart: {$observation.observation.mode|escape} / {$observation.observation.status|escape}</p>
    <p>{$observation.observation.amount|escape} {$observation.observation.currency|escape} {$observation.observation.network|escape}</p>
    {if $observation.review_reason}<p>Reason: {$observation.review_reason|escape}</p>{/if}
</div>
{/foreach}
{/capture}
{include file="common/mainbox.tpl" title="Paymos refunds" content=$smarty.capture.mainbox}

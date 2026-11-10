{capture name="mainbox"}
<p>{__("paymos.refund_order")} #{$paymos_refund_panel.order_id|escape}</p>
<dl class="dl-horizontal">
    <dt>{__("paymos.refund_full")}</dt><dd>{$paymos_refund_panel.summary.full_refund_amount|default:"—"|escape} {$paymos_refund_panel.summary.refund_asset.currency|escape}</dd>
    <dt>{__("paymos.refund_overpayment")}</dt><dd>{$paymos_refund_panel.summary.overpayment_refund_amount|default:"—"|escape} {$paymos_refund_panel.summary.refund_asset.currency|escape}</dd>
    <dt>{__("paymos.refund_recipient")}</dt><dd>{$paymos_refund_panel.summary.sender_address|default:"—"|escape}</dd>
</dl>
{if !$paymos_refund_panel.can_full && !$paymos_refund_panel.can_overpayment}<p class="muted" role="status">{__("paymos.refund_unavailable")}</p>{/if}
<form action="{"paymos.refund"|fn_url}" method="post">
    <input type="hidden" name="security_hash" value="{$paymos_refund_security_hash|escape}" />
    <input type="hidden" name="order_id" value="{$paymos_refund_panel.order_id|escape}" />
    <input type="hidden" name="request_token" value="{$paymos_refund_panel.request_token|escape}" />
    <button type="submit" name="refund_mode" value="full" class="btn" data-copy="refund_full_action" {if !$paymos_refund_panel.can_full}disabled{/if}>{__("paymos.refund_full_action")}</button>
    <button type="submit" name="refund_mode" value="overpayment" class="btn" data-copy="refund_overpayment_action" {if !$paymos_refund_panel.can_overpayment}disabled{/if}>{__("paymos.refund_overpayment_action")}</button>
</form>
{foreach $paymos_refund_panel.operations as $operation}
<div class="well">
    <dl class="dl-horizontal">
        <dt>{__("paymos.refund_type")}</dt><dd>{$operation.mode|escape}</dd>
        <dt>{__("paymos.refund_transfer_record")}</dt><dd>{$operation.cash_state|escape} / {$operation.projection_state|escape}</dd>
        <dt>{__("paymos.refund_amount")}</dt><dd>{$operation.observation.amount|default:"—"|escape} {$operation.observation.currency|escape} {$operation.observation.network|escape}</dd>
        <dt>{__("paymos.refund_recipient")}</dt><dd>{$operation.observation.first_sender|default:"—"|escape}</dd>
        {if $operation.full_draft}<dt>{__("paymos.refund_order_total")}</dt><dd>{$operation.full_draft.amount|escape} {$operation.full_draft.currency|escape}</dd>{/if}
        {if $operation.review_reason}<dt>{__("paymos.refund_reason")}</dt><dd>{$operation.review_reason|escape}</dd>{/if}
    </dl>
    <form action="{"paymos.refund_reconcile"|fn_url}" method="post">
        <input type="hidden" name="security_hash" value="{$paymos_refund_security_hash|escape}" />
        <input type="hidden" name="order_id" value="{$paymos_refund_panel.order_id|escape}" />
        <input type="hidden" name="operation_id" value="{$operation.operation_id|escape}" />
        <button type="submit" class="btn">{if $operation.mode == "full"}{__("paymos.refund_check_full")}{else}{__("paymos.refund_check_overpayment")}{/if}</button>
    </form>
</div>
{/foreach}
{foreach $paymos_refund_panel.unbound as $observation}
<div class="well">
    <p>{__("paymos.refund_external")}: {$observation.observation.mode|escape} / {$observation.observation.status|escape}</p>
    <p>{$observation.observation.amount|escape} {$observation.observation.currency|escape} {$observation.observation.network|escape}</p>
    {if $observation.review_reason}<p>{__("paymos.refund_reason")}: {$observation.review_reason|escape}</p>{/if}
</div>
{/foreach}
{/capture}
{include file="common/mainbox.tpl" title=__("paymos.refunds") content=$smarty.capture.mainbox}

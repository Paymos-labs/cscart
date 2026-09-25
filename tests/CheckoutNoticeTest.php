<?php

declare(strict_types=1);

use PaymosCsCart\CheckoutNotice;

// BUG-181: the checkout notice was hard-coded English ("Paymos payment error: "
// plus the exception message) whatever language the buyer reads.

if (!function_exists('__')) {
    /**
     * CS-Cart's translator, reading the add-on's own catalogues the way the
     * install imports them. A variable the store never imported comes back
     * as a placeholder made of its name.
     *
     * @param string $name
     * @param array<string, string> $params
     */
    function __($name, $params = array(), $lang_code = null)
    {
        $language = $lang_code !== null ? $lang_code : $GLOBALS['paymos_cscart_test_language'];
        $values = paymos_cscart_po_values($language);
        if (!isset($values[$name])) {
            return '_' . $name;
        }

        return strtr($values[$name], is_array($params) ? $params : array());
    }
}

/**
 * @return array<string, string> language variable name => msgstr
 */
function paymos_cscart_po_values($language)
{
    $text = (string) file_get_contents(PAYMOS_CSCART_PLUGIN_DIR . 'var/langs/' . $language . '/addons/paymos.po');
    preg_match_all('/^msgctxt "Languages::([^"]+)"\nmsgid "(?:[^"\\\\]|\\\\.)*"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/m', $text, $matches, PREG_SET_ORDER);
    $values = array();
    foreach ($matches as $match) {
        $values[$match[1]] = stripcslashes($match[2]);
    }

    return $values;
}

function paymos_cscart_blocked_exception()
{
    return new \Paymos\Plugin\InvoiceReplacementBlockedException(
        \Paymos\Plugin\InvoiceReplacementResult::blocked('inv_old', 'awaiting_payment', \Paymos\Plugin\InvoiceReplacementResult::REASON_OPEN)
    );
}

function test_cscart_checkout_notice_for_a_blocked_replacement_is_the_translated_sdk_message()
{
    $blocked = paymos_cscart_blocked_exception();

    $GLOBALS['paymos_cscart_test_language'] = 'en';
    assertSameValue($blocked->getMessage(), CheckoutNotice::text($blocked), 'English reads the SDK buyer message, with no error prefix.');

    foreach (array('ru', 'de', 'es', 'tr', 'zh') as $language) {
        $GLOBALS['paymos_cscart_test_language'] = $language;
        $values = paymos_cscart_po_values($language);
        assertTrueValue(isset($values['paymos.replacement_blocked']) && $values['paymos.replacement_blocked'] !== '', $language . ': the catalogue carries the message.');
        assertSameValue($values['paymos.replacement_blocked'], CheckoutNotice::text($blocked), $language . ': the notice comes from the catalogue.');
    }
}

function test_cscart_checkout_notice_for_any_other_failure_is_a_translated_generic_line()
{
    // BUG-188: the exception message followed the translated prefix — English
    // whatever the buyer reads, and sometimes internal ("Paymos credentials are
    // incomplete.", a server validation detail). The buyer gets a generic line;
    // the cause goes to the merchant's log.
    $failure = new \RuntimeException('Paymos could not create the invoice: project prj_123 is not active (field: project_id)');

    $GLOBALS['paymos_cscart_test_language'] = 'en';
    assertSameValue('Paymos payment error: unable to create invoice.', CheckoutNotice::text($failure), 'English reads the generic line.');
    $english = paymos_cscart_po_values('en');

    foreach (array('en', 'ru', 'de', 'es', 'tr', 'zh') as $language) {
        $GLOBALS['paymos_cscart_test_language'] = $language;
        $values = paymos_cscart_po_values($language);
        assertTrueValue(isset($values['paymos.checkout_failed']) && $values['paymos.checkout_failed'] !== '', $language . ': the catalogue carries the generic line.');
        assertSameValue($values['paymos.checkout_failed'], CheckoutNotice::text($failure), $language . ': the notice comes from the catalogue.');
        assertFalseValue(strpos(CheckoutNotice::text($failure), 'prj_123') !== false, $language . ': nothing of the exception reaches the buyer.');
        if ($language !== 'en') {
            assertFalseValue($values['paymos.checkout_failed'] === $english['paymos.checkout_failed'], $language . ': the generic line is translated.');
        }
        assertFalseValue(isset($values['paymos.checkout_error']), $language . ': the old prefix with the [error] placeholder is gone.');
    }
}

function test_cscart_manual_review_reason_is_translatable()
{
    $blocked = paymos_cscart_blocked_exception();
    $summary = $blocked->result()->summary();

    $expected = array(
        'en' => 'Paymos payment needs manual review.',
        'ru' => 'Оплату Paymos нужно проверить вручную.',
        // English until translated.
        'de' => 'Paymos payment needs manual review.',
        'es' => 'Paymos payment needs manual review.',
        'tr' => 'Paymos payment needs manual review.',
        'zh' => 'Paymos payment needs manual review.',
    );

    foreach ($expected as $language => $prefix) {
        $GLOBALS['paymos_cscart_test_language'] = $language;
        $values = paymos_cscart_po_values($language);
        assertSameValue($prefix, isset($values['paymos.manual_review']) ? $values['paymos.manual_review'] : null, $language . ': the catalogue carries the manual-review line.');
        assertSameValue($prefix . ' ' . $summary, CheckoutNotice::manualReviewReason($blocked), $language . ': the order\'s reason is the line plus the replacement summary.');
    }

    assertSameValue('Paymos payment needs manual review. ' . $summary, CheckoutNotice::manualReviewReason($blocked, static function ($name) {
        return '_' . $name;
    }), 'a store that has not imported the variable reads English, never the variable name.');
}

function test_cscart_checkout_notice_falls_back_to_english_when_the_store_has_not_imported_the_variables()
{
    // A store that copied the files over without reinstalling or upgrading the
    // add-on has no row for the new variables.
    $GLOBALS['paymos_cscart_test_language'] = 'en';
    $blocked = paymos_cscart_blocked_exception();

    assertSameValue($blocked->getMessage(), CheckoutNotice::text($blocked, static function ($name) {
        return '_' . $name;
    }), 'a missing variable never reaches the buyer as its name.');
    assertSameValue('Paymos payment error: unable to create invoice.', CheckoutNotice::text(new \RuntimeException('boom'), static function ($name) {
        return $name;
    }), 'a bare name counts as missing too.');
}

function test_cscart_payment_script_shows_the_checkout_notice()
{
    $script = (string) file_get_contents(PAYMOS_CSCART_ADDON_DIR . 'payments/paymos.php');

    assertSameValue(2, substr_count($script, "fn_set_notification('E', __('error'), \\PaymosCsCart\\CheckoutNotice::text(\$e));"), 'both checkout failure branches show CheckoutNotice.');
    assertFalseValue(strpos($script, "'Paymos payment error: '") !== false, 'no hard-coded English prefix is left.');
    assertFalseValue(strpos($script, 'Paymos payment needs manual review.') !== false, 'the manual-review reason is not hard-coded English.');
    assertContainsValue("'reason_text' => \\PaymosCsCart\\CheckoutNotice::manualReviewReason(\$e),", $script, 'the order\'s reason comes from the catalogue.');
}

function test_cscart_payment_script_logs_the_raw_failure_for_the_merchant()
{
    $script = (string) file_get_contents(PAYMOS_CSCART_ADDON_DIR . 'payments/paymos.php');

    $catchAt = strrpos($script, 'catch (\\Throwable $e)');
    assertTrueValue($catchAt !== false, 'the script has a catch-all checkout branch.');
    $branch = substr($script, $catchAt);
    assertContainsValue("(new \\PaymosCsCart\\CsCartAdapter())->log('Paymos CS-Cart checkout failed.', array('order_id' => (int) \$order_id, 'error' => \$e->getMessage()));", $branch, 'the cause the buyer no longer sees is logged for the merchant.');

    $blockedAt = strpos($script, 'catch (\\Paymos\\Plugin\\InvoiceReplacementBlockedException $e)');
    $blocked = substr($script, $blockedAt, $catchAt - $blockedAt);
    assertContainsValue("(new \\PaymosCsCart\\CsCartAdapter())->log('Paymos CS-Cart invoice was not replaced.', array('order_id' => (int) \$order_id, 'manual_review' => \$e->result()->summary()));", $blocked, 'a blocked replacement is logged with its summary.');
}

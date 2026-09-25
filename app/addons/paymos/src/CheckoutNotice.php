<?php

declare(strict_types=1);

namespace PaymosCsCart;

use Paymos\Plugin\InvoiceReplacementBlockedException;

/**
 * The texts a failed checkout produces, in the store's language
 * (var/langs/<lang>/addons/paymos.po): the notice the buyer reads, and the
 * reason stored on an order whose invoice replacement was blocked.
 */
final class CheckoutNotice
{
    /** English for paymos.checkout_failed, used when the store has no row for it. */
    const CHECKOUT_FAILED = 'Paymos payment error: unable to create invoice.';

    /** English for paymos.manual_review, used when the store has no row for it. */
    const MANUAL_REVIEW = 'Paymos payment needs manual review.';

    /**
     * @param callable|null $translate CS-Cart's __() by default.
     * @return string
     */
    public static function text(\Throwable $e, $translate = null)
    {
        if ($translate === null && function_exists('__')) {
            $translate = '__';
        }

        if ($e instanceof InvoiceReplacementBlockedException) {
            // The old invoice may still be paid: the buyer is asked to contact
            // the store, not to pay again. The English text is the SDK's own.
            return self::translate($translate, 'paymos.replacement_blocked', array(), $e->getMessage());
        }

        // Anything else is one generic line. The exception text is English and
        // may be internal (a credential or server validation detail), so it goes
        // to the merchant's log instead (payments/paymos.php, BUG-188).
        return self::translate($translate, 'paymos.checkout_failed', array(), self::CHECKOUT_FAILED);
    }

    /**
     * The reason written to the order's payment information when the invoice
     * replacement was blocked: the translated line, then the SDK's summary of
     * the old invoice for the merchant.
     *
     * @param callable|null $translate CS-Cart's __() by default.
     * @return string
     */
    public static function manualReviewReason(InvoiceReplacementBlockedException $e, $translate = null)
    {
        if ($translate === null && function_exists('__')) {
            $translate = '__';
        }

        return self::translate($translate, 'paymos.manual_review', array(), self::MANUAL_REVIEW)
            . ' ' . $e->result()->summary();
    }

    /**
     * A store that copied the files in without installing or upgrading the
     * add-on has not imported the variable, and CS-Cart answers with a
     * placeholder made of its name. That never reaches the buyer.
     *
     * @param callable|null         $translate
     * @param string                $name
     * @param array<string, string> $params
     * @param string                $english
     * @return string
     */
    private static function translate($translate, $name, array $params, $english)
    {
        if ($translate !== null) {
            $text = (string) call_user_func($translate, $name, $params);
            if ($text !== '' && $text !== $name && $text !== '_' . $name) {
                return $text;
            }
        }

        return strtr($english, $params);
    }
}

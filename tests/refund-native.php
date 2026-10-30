<?php

declare(strict_types=1);

/**
 * Required stand contract: approved licensed 4.19.x and 4.20.x packages, exact
 * patch version and package hash, PHP/DB versions, installed addon+SDK revision.
 * Run protected settings -> order panel -> full/overpayment against sandbox.
 * Evidence must include native DB rows before/after, HTTP trace with secrets
 * redacted, one marker, unchanged status/stock/RMA, and no payment/mail hooks.
 * Inject death before/after native commit, lost response, callback-before-binding,
 * reorg/recompletion and cron recovery. Exercise role/CSRF/company/store/payment
 * refusals and a prior manual/partial return. Prove fresh install, schema upgrade,
 * reinstall preserving credentials/history, and MySQL concurrent token/binding CAS.
 * A local stub or an environment variable is never native package evidence.
 */
final class CsCartNativeSkipped extends RuntimeException {}

function test_cscart_refund_native_package_419_routes_permissions_and_install()
{
    throw new CsCartNativeSkipped('No licensed CS-Cart 4.19.x package or approved stand; route, Smarty, ACL and installation not executed.');
}

function test_cscart_refund_native_package_420_routes_permissions_and_install()
{
    throw new CsCartNativeSkipped('No licensed CS-Cart 4.20.x package or approved stand; route, Smarty, ACL and installation not executed.');
}

function test_cscart_refund_native_120_100_over20_full100_and_effects()
{
    throw new CsCartNativeSkipped('Native 4.19/4.20 DB, RMA, stock and mail probes unavailable; SQL doubles are not native financial/effect proof.');
}

function test_cscart_refund_native_crash_concurrency_and_scheduler()
{
    throw new CsCartNativeSkipped('No native MySQL connection or platform cron; concurrent CAS, ownership, crash and real commit boundaries not executed.');
}

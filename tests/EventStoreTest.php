<?php

declare(strict_types=1);

/**
 * A10-02: the DB-backed EventStore's owner-token fencing. CS-Cart exposes its
 * database through the global db_query/db_get_field/db_get_row helpers, so the
 * fake below defines exactly those (guarded by function_exists) over an array
 * of rows. Once defined they exist for the whole run; the reset hook in
 * bootstrap clears the rows between tests. DDL and every other table (config,
 * information_schema) are no-ops/empty, so the remaining tests see the same
 * "nothing stored" behaviour they had without the functions.
 */
final class PaymosCsCartFakeDbState
{
    /** @var array<string, array<string, int|string>> */
    public static $events = array();

    public static function reset()
    {
        self::$events = array();
    }
}

function paymos_cscart_fake_db_format($pattern, array $args)
{
    $index = 0;

    return preg_replace_callback(
        '/\?(i|s)/',
        static function ($match) use (&$index, $args) {
            if (!array_key_exists($index, $args)) {
                return $match[0];
            }

            $value = $args[$index++];
            if ($match[1] === 'i') {
                return (string) (int) $value;
            }

            return "'" . addslashes((string) $value) . "'";
        },
        (string) $pattern
    );
}

if (!function_exists('db_query')) {
    function db_query($pattern, ...$args)
    {
        $pattern = (string) $pattern;

        // INSERT INTO ?:paymos_events ?e — the data array is the only argument.
        if (preg_match('/^INSERT\s+INTO\s+\?:paymos_events\s+\?e/i', $pattern, $m)) {
            $data = isset($args[0]) && is_array($args[0]) ? $args[0] : array();
            if (isset($data['event_id'])) {
                if (isset(PaymosCsCartFakeDbState::$events[$data['event_id']])) {
                    throw new Exception('Duplicate entry for key PRIMARY: ' . $data['event_id']);
                }
                PaymosCsCartFakeDbState::$events[$data['event_id']] = $data;
            }

            return true;
        }

        $sql = paymos_cscart_fake_db_format($pattern, $args);

        if (preg_match("/^DELETE FROM \?:paymos_events WHERE expires_at < (\d+)$/i", $sql, $m)) {
            foreach (PaymosCsCartFakeDbState::$events as $id => $row) {
                if ((int) $row['expires_at'] < (int) $m[1]) {
                    unset(PaymosCsCartFakeDbState::$events[$id]);
                }
            }

            return true;
        }

        if (preg_match("/^DELETE FROM \?:paymos_events WHERE event_id = '([^']*)' AND owner_token = '([^']*)'$/i", $sql, $m)) {
            $id = stripslashes($m[1]);
            if (isset(PaymosCsCartFakeDbState::$events[$id])
                && (string) PaymosCsCartFakeDbState::$events[$id]['owner_token'] === stripslashes($m[2])) {
                unset(PaymosCsCartFakeDbState::$events[$id]);
            }

            return true;
        }

        if (preg_match("/^UPDATE \?:paymos_events SET expires_at = (\d+) WHERE event_id = '([^']*)' AND owner_token = '([^']*)'$/i", $sql, $m)) {
            $id = stripslashes($m[2]);
            if (isset(PaymosCsCartFakeDbState::$events[$id])
                && (string) PaymosCsCartFakeDbState::$events[$id]['owner_token'] === stripslashes($m[3])) {
                PaymosCsCartFakeDbState::$events[$id]['expires_at'] = (int) $m[1];
            }

            return true;
        }

        // CREATE/ALTER and statements against other tables: no-op.
        return true;
    }
}

if (!function_exists('db_get_field')) {
    function db_get_field($pattern, ...$args)
    {
        $sql = paymos_cscart_fake_db_format((string) $pattern, $args);

        if (preg_match("/^SELECT ([a-z_, ]+) FROM \?:paymos_events WHERE event_id = '([^']*)'/i", $sql, $m)) {
            $field = trim(explode(',', $m[1])[0]);
            $id = stripslashes($m[2]);

            return isset(PaymosCsCartFakeDbState::$events[$id][$field])
                ? (string) PaymosCsCartFakeDbState::$events[$id][$field]
                : '';
        }

        // SHOW COLUMNS, the config table, information_schema: nothing found.
        return '';
    }
}

if (!function_exists('db_get_row')) {
    function db_get_row($pattern, ...$args)
    {
        $sql = paymos_cscart_fake_db_format((string) $pattern, $args);

        if (preg_match("/^SELECT ([a-z_, ]+) FROM \?:paymos_events WHERE event_id = '([^']*)'/i", $sql, $m)) {
            $id = stripslashes($m[2]);
            if (!isset(PaymosCsCartFakeDbState::$events[$id])) {
                return array();
            }

            $row = array();
            foreach (array_map('trim', explode(',', $m[1])) as $field) {
                if (isset(PaymosCsCartFakeDbState::$events[$id][$field])) {
                    $row[$field] = PaymosCsCartFakeDbState::$events[$id][$field];
                }
            }

            return $row;
        }

        return array();
    }
}

/**
 * A10-02: a delivery whose reservation expired must not delete the successor's
 * row, and while the successor is still busy a third delivery must be rejected
 * as in-flight until the successor commits.
 */
function test_cscart_stale_owner_release_does_not_touch_the_successor()
{
    PaymosCsCartFakeDbState::reset();
    $ownerA = new PaymosCsCart\EventStore();
    assertTrueValue($ownerA->remember('evt_stale_release', 604800), 'delivery A reserves the event.');

    // Delivery A is stalled past its 300s reservation: rewind the row's expiry
    // so the next remember() prunes it and takes the id over.
    PaymosCsCartFakeDbState::$events['evt_stale_release']['expires_at'] = time() - 1;

    $ownerB = new PaymosCsCart\EventStore();
    assertTrueValue($ownerB->remember('evt_stale_release', 604800), 'delivery B takes over the expired reservation.');
    $tokenB = (string) PaymosCsCartFakeDbState::$events['evt_stale_release']['owner_token'];
    assertTrueValue($tokenB !== '', 'the successor row must carry its own owner token.');

    // A wakes up and fails: its release() must complete silently and leave B's row.
    $ownerA->release();
    assertTrueValue(isset(PaymosCsCartFakeDbState::$events['evt_stale_release']), 'a stale release must not delete the successor reservation.');
    assertSameValue($tokenB, (string) PaymosCsCartFakeDbState::$events['evt_stale_release']['owner_token'], 'the successor row must keep its own token.');

    // While B is busy, a third delivery is rejected as in-flight, not duplicate.
    $ownerC = new PaymosCsCart\EventStore();
    assertFalseValue($ownerC->remember('evt_stale_release', 604800), 'a third delivery while B is busy must lose the race.');
    assertFalseValue($ownerC->isCommitted('evt_stale_release'), 'the event is not committed while B is still processing.');

    assertTrueValue($ownerB->commit(), 'the successor commits its own reservation.');
    assertTrueValue($ownerC->isCommitted('evt_stale_release'), 'after B commits the event is a duplicate.');
}

/**
 * A10-02: a stale delivery's commit() must report lost ownership (false) and
 * must not extend the successor's short reservation into a committed one.
 */
function test_cscart_stale_owner_commit_does_not_confirm_the_successor()
{
    PaymosCsCartFakeDbState::reset();
    $ownerA = new PaymosCsCart\EventStore();
    assertTrueValue($ownerA->remember('evt_stale_commit', 604800), 'delivery A reserves the event.');

    PaymosCsCartFakeDbState::$events['evt_stale_commit']['expires_at'] = time() - 1;

    $ownerB = new PaymosCsCart\EventStore();
    assertTrueValue($ownerB->remember('evt_stale_commit', 604800), 'delivery B takes over the expired reservation.');
    $reservedByB = (int) PaymosCsCartFakeDbState::$events['evt_stale_commit']['expires_at'];

    assertFalseValue($ownerA->commit(), 'a stale commit must report lost ownership, not success.');
    assertSameValue($reservedByB, (int) PaymosCsCartFakeDbState::$events['evt_stale_commit']['expires_at'], "a stale commit must not extend the successor's reservation.");
    assertFalseValue($ownerB->isCommitted('evt_stale_commit'), "B's row must still look busy, not committed.");

    $ownerC = new PaymosCsCart\EventStore();
    assertFalseValue($ownerC->remember('evt_stale_commit', 604800), 'C is still rejected while B is busy.');

    assertTrueValue($ownerB->commit(), 'the successor commits its own reservation.');
    assertTrueValue($ownerC->isCommitted('evt_stale_commit'), 'after B commits the event is a duplicate.');
}

/**
 * A10-02: owner tokens come from random_bytes, not the clock — two reservations
 * taken within the same second can never share a token.
 */
function test_cscart_owner_tokens_do_not_collide_within_the_same_second()
{
    PaymosCsCartFakeDbState::reset();
    $first = new PaymosCsCart\EventStore();
    $second = new PaymosCsCart\EventStore();

    assertTrueValue($first->remember('evt_token_a', 604800), 'first reservation.');
    assertTrueValue($second->remember('evt_token_b', 604800), 'second reservation in the same second.');

    $tokenA = (string) PaymosCsCartFakeDbState::$events['evt_token_a']['owner_token'];
    $tokenB = (string) PaymosCsCartFakeDbState::$events['evt_token_b']['owner_token'];

    assertTrueValue((bool) preg_match('/^[0-9a-f]{32}$/', $tokenA), 'owner token must be 16 random bytes as hex.');
    assertTrueValue((bool) preg_match('/^[0-9a-f]{32}$/', $tokenB), 'owner token must be 16 random bytes as hex.');
    assertTrueValue($tokenA !== $tokenB, 'two reservations in the same second must not share an owner token.');
    assertSameValue(
        (int) PaymosCsCartFakeDbState::$events['evt_token_a']['created_at'],
        (int) PaymosCsCartFakeDbState::$events['evt_token_b']['created_at'],
        'precondition: both rows were written in the same second.'
    );
}

/**
 * A10-02: commit() with nothing pending, or against a row taken over by a
 * successor, is a quiet non-success (zero matched rows), never an error.
 */
function test_cscart_zero_row_commit_is_reported_as_not_owned()
{
    PaymosCsCartFakeDbState::reset();
    $store = new PaymosCsCart\EventStore();

    assertFalseValue($store->commit(), 'commit without a pending reservation must report non-success.');

    assertTrueValue($store->remember('evt_zero_rows', 604800), 'reserve before losing the row.');
    PaymosCsCartFakeDbState::$events['evt_zero_rows']['expires_at'] = time() - 1;
    $successor = new PaymosCsCart\EventStore();
    assertTrueValue($successor->remember('evt_zero_rows', 604800), 'the successor takes the expired id over.');
    assertFalseValue($store->commit(), 'a commit that matched zero rows must report lost ownership.');
    assertTrueValue(isset(PaymosCsCartFakeDbState::$events['evt_zero_rows']), 'the failed commit must leave the successor row in place.');
}

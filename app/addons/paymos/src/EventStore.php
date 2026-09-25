<?php

declare(strict_types=1);

namespace PaymosCsCart;

use Paymos\Webhook\CommitAwareEventStoreInterface;

final class EventStore implements CommitAwareEventStoreInterface
{
    /** @var string */
    private $pendingEventId = '';

    /** @var int */
    private $pendingTtlSeconds = 0;

    /**
     * Short in-flight reservation (seconds). remember() inserts a row that lives
     * only this long so that a process which dies after INSERT but before
     * commit()/release() self-heals within minutes — instead of blacklisting the
     * event for the full 7-day TTL and rejecting every server retry as duplicate.
     * commit() then extends the row to the full SDK TTL once the order update
     * succeeded. Matches the OpenCart EventStore.
     */
    private const RESERVATION_SECONDS = 300;

    public function remember($eventId, $ttlSeconds)
    {
        Migrations::ensure();
        if (!function_exists('db_query') || !function_exists('db_get_field')) {
            return true;
        }

        $eventId = (string) $eventId;
        $ttlSeconds = (int) $ttlSeconds;
        $now = time();
        db_query('DELETE FROM ?:paymos_events WHERE expires_at < ?i', $now);

        $existing = db_get_field('SELECT event_id FROM ?:paymos_events WHERE event_id = ?s LIMIT 1', $eventId);
        if ($existing !== null && $existing !== false && $existing !== '') {
            return false;
        }

        try {
            // In-flight row gets only a short reservation; commit() extends it to
            // the full TTL after the order update succeeds. RESERVATION_SECONDS is
            // far longer than any single webhook processing, so a fast re-delivery
            // still loses the dedup race while the lock is held.
            db_query('INSERT INTO ?:paymos_events ?e', array(
                'event_id' => $eventId,
                'expires_at' => $now + self::RESERVATION_SECONDS,
                'created_at' => $now,
            ));
        } catch (\Exception $e) {
            return false;
        }

        $this->pendingEventId = $eventId;
        $this->pendingTtlSeconds = $ttlSeconds;

        return true;
    }

    /**
     * Whether the event was processed and committed — as opposed to merely
     * locked by a delivery that has not finished (BUG-103: that one must be
     * answered non-2xx, or a retry arriving mid-processing marks it delivered).
     * A committed row lives past its reservation; a lock does not.
     */
    public function isCommitted($eventId)
    {
        if (!function_exists('db_get_row')) {
            return false;
        }

        $row = db_get_row('SELECT expires_at, created_at FROM ?:paymos_events WHERE event_id = ?s', (string) $eventId);
        if (!is_array($row) || count($row) === 0) {
            return false;
        }

        $expiresAt = isset($row['expires_at']) ? (int) $row['expires_at'] : 0;
        $createdAt = isset($row['created_at']) ? (int) $row['created_at'] : 0;

        return $expiresAt > time() && $expiresAt > $createdAt + self::RESERVATION_SECONDS;
    }

    public function commit()
    {
        if ($this->pendingEventId === '' || !function_exists('db_query')) {
            return;
        }

        db_query(
            'UPDATE ?:paymos_events SET expires_at = ?i WHERE event_id = ?s',
            time() + ($this->pendingTtlSeconds > 0 ? $this->pendingTtlSeconds : 300),
            $this->pendingEventId
        );

        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }

    public function release()
    {
        if ($this->pendingEventId === '' || !function_exists('db_query')) {
            return;
        }

        db_query('DELETE FROM ?:paymos_events WHERE event_id = ?s', $this->pendingEventId);

        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }
}

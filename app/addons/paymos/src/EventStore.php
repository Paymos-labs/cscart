<?php

declare(strict_types=1);

namespace PaymosCsCart;

use Paymos\Webhook\CommitAwareEventStoreInterface;

final class EventStore implements CommitAwareEventStoreInterface
{
    /** @var string */
    private $pendingEventId = '';

    /**
     * Owner token of the reservation remember() took (A10-02). Each successful
     * remember() mints a fresh random token; commit()/release() match it in
     * their WHERE clause, so a delivery whose reservation expired (and whose
     * event id was taken over by a newer delivery) can no longer delete or
     * commit the successor's row.
     *
     * @var string
     */
    private $pendingOwnerToken = '';

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

        $ownerToken = bin2hex(random_bytes(16));

        try {
            // In-flight row gets only a short reservation; commit() extends it to
            // the full TTL after the order update succeeds. RESERVATION_SECONDS is
            // far longer than any single webhook processing, so a fast re-delivery
            // still loses the dedup race while the lock is held.
            db_query('INSERT INTO ?:paymos_events ?e', array(
                'event_id' => $eventId,
                'owner_token' => $ownerToken,
                'expires_at' => $now + self::RESERVATION_SECONDS,
                'created_at' => $now,
            ));
        } catch (\Exception $e) {
            return false;
        }

        $this->pendingEventId = $eventId;
        $this->pendingOwnerToken = $ownerToken;
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

    /**
     * Extend the reservation to the full dedup window — but only if this store
     * instance still owns it. Zero matched rows mean a newer delivery took the
     * event id over after this reservation expired (A10-02): that successor's
     * row must stay untouched, and the commit is reported as not owned (false),
     * not as an error.
     *
     * @return bool True when this owner's row was extended; false when nothing
     *              was pending or the reservation was lost to a successor.
     */
    public function commit()
    {
        if ($this->pendingEventId === '' || !function_exists('db_query')) {
            return false;
        }

        $eventId = $this->pendingEventId;
        $ownerToken = $this->pendingOwnerToken;

        db_query(
            'UPDATE ?:paymos_events SET expires_at = ?i WHERE event_id = ?s AND owner_token = ?s',
            time() + ($this->pendingTtlSeconds > 0 ? $this->pendingTtlSeconds : 300),
            $eventId,
            $ownerToken
        );

        // db_query() does not report affected rows, so ownership is confirmed
        // by reading the row back: it is still ours exactly when the token
        // written by this remember() is the one in the row.
        $row = function_exists('db_get_row')
            ? db_get_row('SELECT owner_token FROM ?:paymos_events WHERE event_id = ?s', $eventId)
            : null;
        $stillOwner = is_array($row)
            && count($row) > 0
            && isset($row['owner_token'])
            && (string) $row['owner_token'] === $ownerToken;

        $this->pendingEventId = '';
        $this->pendingOwnerToken = '';
        $this->pendingTtlSeconds = 0;

        return $stillOwner;
    }

    /**
     * Drop the in-flight lock so the server can retry a failed processing run.
     * Only this owner's row is deleted: when the reservation expired and a
     * newer delivery owns the event id now, release() completes silently and
     * leaves the successor's row alone (A10-02).
     */
    public function release()
    {
        if ($this->pendingEventId === '' || !function_exists('db_query')) {
            return;
        }

        db_query(
            'DELETE FROM ?:paymos_events WHERE event_id = ?s AND owner_token = ?s',
            $this->pendingEventId,
            $this->pendingOwnerToken
        );

        $this->pendingEventId = '';
        $this->pendingOwnerToken = '';
        $this->pendingTtlSeconds = 0;
    }
}

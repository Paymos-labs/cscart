<?php

declare(strict_types=1);

namespace PaymosCsCart;

final class Migrations
{
    public const INVOICES_TABLE = 'paymos_invoices';
    public const EVENTS_TABLE = 'paymos_events';

    public static function ensure()
    {
        if (!function_exists('db_query')) {
            return;
        }

        // utf8mb4 for new installs; an existing utf8 (utf8mb3) table is converted
        // right after its CREATE so joins against ?:orders stop crossing charset
        // lines on MySQL 8, where utf8mb3 is deprecated (and gone in MySQL 9).
        //
        // The conversion MUST follow the CREATE of the same table: on a fresh
        // database an ALTER that ran first would hit a table that does not exist
        // yet. Keeping each pair together is what makes that unorderable.
        db_query("CREATE TABLE IF NOT EXISTS ?:paymos_invoices (
            id int(11) unsigned NOT NULL AUTO_INCREMENT,
            cscart_order_id int(11) unsigned NOT NULL,
            paymos_invoice_id varchar(128) NOT NULL,
            external_order_id varchar(128) NOT NULL,
            environment varchar(16) NOT NULL,
            project_id varchar(128) NOT NULL,
            amount varchar(64) NOT NULL,
            currency varchar(16) NOT NULL,
            payment_url text NOT NULL,
            status varchar(64) NOT NULL,
            renew_count int(11) unsigned NOT NULL DEFAULT 0,
            paid_late_noted varchar(128) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY paymos_invoice_id (paymos_invoice_id),
            UNIQUE KEY external_order_id (external_order_id),
            KEY cscart_order_id (cscart_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::ensureCharset(self::INVOICES_TABLE);
        self::ensurePaidLateNoted();

        db_query("CREATE TABLE IF NOT EXISTS ?:paymos_events (
            event_id varchar(128) NOT NULL,
            owner_token char(32) NOT NULL DEFAULT '',
            expires_at int(11) unsigned NOT NULL,
            created_at int(11) unsigned NOT NULL,
            PRIMARY KEY (event_id),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::ensureCharset(self::EVENTS_TABLE);
        self::ensureEventOwnerToken();
        RefundSchema::ensure();
    }

    /**
     * Tables created before the owner-token reservation fix (A10-02) lack the
     * `owner_token` column. Old rows keep '' and never match a live owner
     * token, so they simply expire via their reservation TTL (<=300s) — no
     * data migration is needed, and the takeover window closes itself.
     *
     * @return void
     */
    private static function ensureEventOwnerToken()
    {
        if (!function_exists('db_get_field') || !function_exists('db_query')) {
            return;
        }

        if (db_get_field("SHOW COLUMNS FROM ?:paymos_events LIKE 'owner_token'")) {
            return;
        }

        try {
            db_query("ALTER TABLE ?:paymos_events ADD COLUMN owner_token char(32) NOT NULL DEFAULT ''");
        } catch (\Throwable $e) {
            // A store whose ALTER failed keeps working until the next request
            // retries it; expired rows still age out by TTL.
        }
    }

    /**
     * Convert one existing table to utf8mb4, and only when it is not utf8mb4
     * already: the charset is read from information_schema so the ALTER never
     * runs twice, and an absent row (no such table) is a no-op rather than an
     * ALTER against nothing.
     *
     * @param string $table
     * @return void
     */
    /**
     * Tables created before invoice-late-payment lack the paid-late marker column; SHOW COLUMNS works on
     * MySQL and MariaDB alike, so the ALTER runs once per store.
     *
     * @return void
     */
    private static function ensurePaidLateNoted()
    {
        if (!function_exists('db_get_field')) {
            return;
        }

        if (db_get_field("SHOW COLUMNS FROM ?:paymos_invoices LIKE 'paid_late_noted'")) {
            return;
        }

        db_query('ALTER TABLE ?:paymos_invoices ADD COLUMN paid_late_noted varchar(128) NULL');
    }

    private static function ensureCharset($table)
    {
        if (!function_exists('db_get_row')) {
            return;
        }

        $row = db_get_row(
            "SELECT c.CHARACTER_SET_NAME as cs"
            . " FROM information_schema.TABLES t"
            . " JOIN information_schema.COLLATIONS c ON (c.COLLATION_NAME = t.TABLE_COLLATION)"
            . " WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = '?:{$table}'"
        );
        if (!is_array($row) || !isset($row['cs']) || strtoupper((string) $row['cs']) === 'UTF8MB4') {
            return;
        }

        db_query("ALTER TABLE ?:{$table} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}

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
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY paymos_invoice_id (paymos_invoice_id),
            UNIQUE KEY external_order_id (external_order_id),
            KEY cscart_order_id (cscart_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        db_query("CREATE TABLE IF NOT EXISTS ?:paymos_events (
            event_id varchar(128) NOT NULL,
            expires_at int(11) unsigned NOT NULL,
            created_at int(11) unsigned NOT NULL,
            PRIMARY KEY (event_id),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }
}

<?php

declare(strict_types=1);

namespace PaymosCsCart;

/** All reads and writes use CS-Cart's native master connection. */
class RefundDatabase
{
    public function query(string $sql)
    {
        if (!function_exists('db_query')) {
            throw new \RuntimeException('refund_database_unavailable');
        }
        if (preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) {
            if (!function_exists('db_get_array')) {
                throw new \RuntimeException('refund_database_unavailable');
            }
            $rows = db_get_array($sql);
            if (!is_array($rows)) {
                throw new \RuntimeException('refund_database_read_failed');
            }
            return (object) array('rows' => $rows, 'row' => count($rows) ? $rows[0] : array());
        }
        if (db_query($sql) === false) {
            throw new \RuntimeException('refund_database_write_failed');
        }
        return (object) array('rows' => array(), 'row' => array());
    }

    public function escape(string $value): string
    {
        if (!function_exists('db_quote')) {
            throw new \RuntimeException('refund_database_unavailable');
        }
        return substr(db_quote('?s', $value), 1, -1);
    }

    public function countAffected(): int
    {
        return (int) db_get_field('SELECT ROW_COUNT()');
    }

    public function table(string $name): string
    {
        if (!preg_match('/^[a-z_]+$/D', $name)) {
            throw new \InvalidArgumentException('refund_invalid_table');
        }
        return db_quote('?:' . $name);
    }
}

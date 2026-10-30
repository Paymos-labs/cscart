<?php

declare(strict_types=1);

namespace PaymosCsCart;

final class RefundSchema
{
    public static function statements(): array
    {
        return array(
            "CREATE TABLE IF NOT EXISTS ?:paymos_refund_installation (
                id TINYINT UNSIGNED NOT NULL,
                installation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY (installation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS ?:paymos_refund_intent (
                operation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                installation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                request_token VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                payment_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                external_order_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                withdrawal_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
                payload LONGTEXT NOT NULL,
                created_at BIGINT NOT NULL,
                next_due BIGINT NOT NULL,
                poll_count INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (operation_id), UNIQUE KEY request_token (installation_id, request_token),
                UNIQUE KEY (external_order_id), UNIQUE KEY (withdrawal_id),
                KEY (payment_key), KEY due (next_due, operation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS ?:paymos_refund_projection (
                operation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                order_id INT UNSIGNED NOT NULL,
                invoice_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                withdrawal_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                note TEXT NOT NULL,
                result LONGTEXT NOT NULL,
                PRIMARY KEY (operation_id), UNIQUE KEY (withdrawal_id), KEY (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS ?:paymos_refund_unbound (
                withdrawal_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                order_id INT UNSIGNED NOT NULL,
                payload LONGTEXT NOT NULL,
                result LONGTEXT NULL,
                review_reason VARCHAR(64) NULL,
                note TEXT NOT NULL,
                next_due BIGINT NOT NULL,
                created_at BIGINT NOT NULL,
                poll_count INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (withdrawal_id), KEY (order_id), KEY due (next_due, withdrawal_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function ensure(): void
    {
        $db = new RefundDatabase();
        foreach (self::statements() as $sql) {
            $db->query($sql);
        }
    }
}

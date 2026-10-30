<?php

declare(strict_types=1);

defined('BOOTSTRAP') or die('Access denied');

if (function_exists('fn_register_hooks')) {
    fn_register_hooks('change_order_status_pre');
}

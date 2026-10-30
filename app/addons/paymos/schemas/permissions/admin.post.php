<?php

declare(strict_types=1);

defined('BOOTSTRAP') or die('Access denied');

$schema['paymos']['modes']['refunds']['permissions'] = 'edit_order';
$schema['paymos']['modes']['refund']['permissions'] = 'edit_order';
$schema['paymos']['modes']['refund_reconcile']['permissions'] = 'edit_order';

return $schema;

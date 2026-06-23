<?php

declare(strict_types=1);

namespace PaymosCsCart;

interface CsCartAdapterInterface
{
    public function getOrder($orderId);

    public function finishPayment($orderId, array $ppResponse);

    public function log($message, array $context = array());
}

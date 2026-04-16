<?php

namespace App\Domain\Trading;

use App\Models\Order;
use RuntimeException;

class UncertainLiveOrderException extends RuntimeException
{
    public function __construct(
        public readonly Order $order,
        string $message = 'Order submission outcome is uncertain. Manual reconcile is required before retry.'
    ) {
        parent::__construct($message);
    }
}

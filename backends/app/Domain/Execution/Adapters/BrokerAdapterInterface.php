<?php

namespace App\Domain\Execution\Adapters;

interface BrokerAdapterInterface
{
    public function testConnection(array $credentials): array;
}

<?php

namespace App\Domain\Risk\DTO;

class RiskDecision
{
    public function __construct(
        public string $status,
        public string $reason,
        public array $limitsHit = [],
        public array $meta = []
    ) {
    }
}

<?php

namespace App\Domain\Ops;

use App\Models\RecoveryDrill;

class RecoveryDrillResultRecorder
{
    public function record(array $payload): RecoveryDrill
    {
        return RecoveryDrill::create($payload);
    }
}


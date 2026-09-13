<?php

namespace App\Domain\AuditResearch\Export;

use App\Domain\AuditResearch\SecretFieldScrubber;

class JsonAuditExporter
{
    public function export(array $report): string
    {
        $safe = SecretFieldScrubber::scrub($report);

        return json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}

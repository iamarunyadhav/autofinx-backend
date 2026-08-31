<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class AutoFinxMarketFeedCleanupCommand extends Command
{
    protected $signature = 'autofinx:market-feed:cleanup {--apply : Actually delete stale AutoFinix market-feed runtime keys}';

    protected $description = 'Dry-run cleanup of obsolete AutoFinix market-feed runtime Redis keys.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->line($apply ? 'APPLY MODE' : 'DRY RUN');

        $patterns = [
            'autofinx:market-feed:*:worker',
            'autofinx:market:recovery:*',
            'autofinx:runtime:market-ws-consume:*',
            'autofinx:runtime:future-trade-live-feed:*',
        ];

        $candidates = [];
        foreach ($patterns as $pattern) {
            foreach ($this->keys($pattern) as $key) {
                $ttl = (int) Redis::ttl($key);
                if ($ttl === -1 || $ttl === -2) {
                    $candidates[$key] = ['pattern' => $pattern, 'ttl' => $ttl];
                }
            }
        }

        if ($candidates === []) {
            $this->line('No obsolete immortal/missing AutoFinix market-feed runtime keys found.');
            return self::SUCCESS;
        }

        foreach ($candidates as $key => $meta) {
            $this->line(sprintf('%s ttl=%s pattern=%s', $apply ? 'DELETE' : 'WOULD_DELETE', $meta['ttl'], $key));
            if ($apply && str_starts_with($key, 'autofinx:')) {
                Redis::del($key);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function keys(string $pattern): array
    {
        try {
            return array_values(array_filter(array_map('strval', Redis::keys($pattern))));
        } catch (\Throwable) {
            return [];
        }
    }
}

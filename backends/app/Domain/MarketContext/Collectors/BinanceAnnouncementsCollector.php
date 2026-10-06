<?php

namespace App\Domain\MarketContext\Collectors;

use App\Domain\MarketContext\Data\CollectResult;
use App\Domain\MarketContext\Data\RawItem;
use Illuminate\Support\Carbon;

/**
 * Binance announcement catalogs (48 = new listings, 161 = delistings). Real
 * response (verified 2026-10-06): {"code":"000000","data":{"catalogs":[{"catalogId":48,
 * "articles":[{"id":..,"code":"<hex>","title":"..","releaseDate":<ms epoch>}]}]},"success":true}
 * This is the website CMS endpoint, not a published API: registry enabled=false.
 */
final class BinanceAnnouncementsCollector extends BaseCollector
{
    public function collect(?string $etag, ?string $lastModified, Carbon $now): CollectResult
    {
        $items = [];
        $failed = 0;
        foreach ((array) ($this->config['catalogs'] ?? []) as $catalogId => $hint) {
            try {
                $result = $this->expectOk($this->fetcher->get($this->config['url'].'?type=1&pageNo=1&pageSize='.(int) ($this->config['page_size'] ?? 10).'&catalogId='.(int) $catalogId));
                $items = array_merge($items, $this->parse($result->body, (string) $hint, $now));
            } catch (SourceFetchException) {
                $failed++;
            }
        }
        if ($items === [] && $failed > 0) {
            throw new SourceFetchException('ALL_CATALOGS_FAILED');
        }

        return new CollectResult(items: $items, failedParts: $failed);
    }

    /** @return list<RawItem> */
    public function parse(string $body, string $hint, Carbon $now): array
    {
        $json = json_decode($body, true, 16);
        if (! is_array($json) || ($json['success'] ?? false) !== true || ! isset($json['data']['catalogs'])) {
            throw new SourceFetchException('BAD_FORMAT');
        }
        $maxTitle = (int) config('market_context.limits.max_title_chars', 240);
        $items = [];
        foreach ((array) $json['data']['catalogs'] as $catalog) {
            foreach (array_slice((array) ($catalog['articles'] ?? []), 0, (int) config('market_context.limits.max_items_per_source', 30)) as $article) {
                $title = $this->clean($article['title'] ?? '', $maxTitle);
                $code = (string) ($article['code'] ?? '');
                if ($title === '' || ! isset($article['releaseDate']) || ! is_numeric($article['releaseDate']) || preg_match('/^[a-f0-9]{16,64}$/', $code) !== 1) {
                    continue;
                }
                $published = Carbon::createFromTimestampMsUTC((int) $article['releaseDate']);
                $items[] = new RawItem($this->source, 'binance-'.$code, $title, null,
                    'https://www.binance.com/en/support/announcement/detail/'.$code,
                    $published->greaterThan($now->copy()->utc()->addMinutes(5)) ? $now->copy()->utc() : $published, $hint);
            }
        }

        return $items;
    }
}

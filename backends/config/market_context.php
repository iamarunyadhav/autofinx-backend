<?php

use App\Domain\Intelligence\ExternalContextTaxonomy as Tax;
use App\Domain\MarketContext\Collectors\BinanceAnnouncementsCollector;
use App\Domain\MarketContext\Collectors\BinanceDerivativesMetricsCollector;
use App\Domain\MarketContext\Collectors\FearGreedCollector;
use App\Domain\MarketContext\Collectors\RssNewsCollector;

/*
 * Market Context (docs/MARKET_CONTEXT_PLAN.md). Shadow-only: nothing in trading
 * reads this module. Default OFF; with the flag off no command fetches, writes
 * or schedules anything.
 */
return [
    'enabled' => filter_var(env('AUTOFINX_MARKET_CONTEXT_ENABLED', false), FILTER_VALIDATE_BOOL),

    'schema_version' => 1,

    // Same envelope as Nexus\NexusSourceService: HTTPS only, fixed registry URLs,
    // no redirects (unless a source lists allow_redirect_hosts), 3s/8s, 256 KiB.
    'http' => [
        'connect_timeout' => 3,
        'timeout' => 8,
        'max_bytes' => 262144,
        'user_agent' => 'AutoFinX-MarketContext/1.0',
        // Pin a verified public address (anti DNS-rebinding), as Nexus does.
        'pin_dns' => true,
    ],

    'limits' => [
        'max_items_per_source' => 30,
        'max_excerpt_chars' => 300,
        'max_title_chars' => 240,
        // Hard caps on rows a snapshot read may load.
        'snapshot_max_events' => 400,
        'snapshot_max_metric_rows' => 800,
        'snapshot_top_drivers' => 5,
        // ~100 bytes per day keeps the alternative.me body under http.max_bytes.
        'fear_greed_backfill_max_days' => 1500,
    ],

    // A source that fails this many times in a row is skipped for the cooldown.
    'breaker' => [
        'failure_threshold' => 5,
        'cooldown_minutes' => 60,
        'backoff_minutes' => 10,
        'lock_seconds' => 120,
    ],

    'retention' => [
        'events_days' => 180,
        'excerpt_days' => 30,   // bounded excerpt dropped, normalized row kept
        'metrics_days' => 730,
        'snapshots_days' => 90,
        'chunk' => 500,
    ],

    'snapshot' => [
        'bucket_minutes' => 15,
        'lookback_hours' => 24,
        'metrics_lookback_hours' => 96,
        'oi_change_window_hours' => 24,
    ],

    // Rules-only risk model (Phase 1). Severity prior x category multiplier x
    // relevance x time decay; contributions combine as 1 - prod(1 - c).
    'risk' => [
        'severity_prior' => ['LOW' => 0.05, 'MEDIUM' => 0.25, 'HIGH' => 0.55, 'CRITICAL' => 0.9],
        'category_multiplier' => [
            Tax::CATEGORY_SECURITY_INCIDENT => 1.0,
            Tax::CATEGORY_EXCHANGE_INCIDENT => 1.0,
            Tax::CATEGORY_NETWORK_OUTAGE => 0.9,
            Tax::CATEGORY_SYSTEMIC_MARKET_STRESS => 1.0,
            Tax::CATEGORY_LIQUIDITY_RISK => 0.9,
            Tax::CATEGORY_REGULATORY_NEWS => 0.8,
            Tax::CATEGORY_MACRO_EVENT => 0.8,
            Tax::CATEGORY_GEOPOLITICAL_RISK => 0.7,
            Tax::CATEGORY_ASSET_SPECIFIC_NEWS => 0.6,
            Tax::CATEGORY_CRYPTO_MARKET_NEWS => 0.4,
        ],
        'half_life_hours' => [
            'default' => 6,
            Tax::CATEGORY_SECURITY_INCIDENT => 24,
            Tax::CATEGORY_EXCHANGE_INCIDENT => 12,
            Tax::CATEGORY_NETWORK_OUTAGE => 8,
            Tax::CATEGORY_REGULATORY_NEWS => 12,
            Tax::CATEGORY_SYSTEMIC_MARKET_STRESS => 24,
        ],
        // Score thresholds for MEDIUM / HIGH / CRITICAL (below the first is LOW).
        'levels' => ['MEDIUM' => 0.12, 'HIGH' => 0.35, 'CRITICAL' => 0.65],
        // Contributions below this are ignored (keeps drivers meaningful).
        'min_contribution' => 0.01,
        'fear_greed_extreme_low' => 20,
        'fear_greed_extreme_high' => 80,
        'oi_change_flag_pct' => 10.0,
    ],

    // Asset-class-neutral profiles: adding gold/forex = a profile entry plus the
    // collectors it names. 'tickers' match case-sensitively, 'names' case-insensitively.
    'profiles' => [
        'BTC' => ['enabled' => true, 'asset_class' => 'CRYPTO', 'name' => 'Bitcoin', 'components' => ['BTC'],
            'aliases' => ['names' => ['bitcoin'], 'tickers' => ['BTC', 'BTCUSDT', 'XBT']],
            'macro_drivers' => ['fed', 'cpi', 'etf_flows', 'regulation'], 'binance_futures_symbol' => 'BTCUSDT',
            'collectors' => ['fear_greed', 'binance_derivatives', 'binance_announcements', 'rss_fed', 'rss_ecb', 'rss_coindesk', 'rss_cointelegraph']],
        'ETH' => ['enabled' => true, 'asset_class' => 'CRYPTO', 'name' => 'Ethereum', 'components' => ['ETH'],
            'aliases' => ['names' => ['ethereum', 'ether'], 'tickers' => ['ETH', 'ETHUSDT']],
            'macro_drivers' => ['fed', 'cpi', 'etf_flows', 'regulation'], 'binance_futures_symbol' => 'ETHUSDT',
            'collectors' => ['fear_greed', 'binance_derivatives', 'binance_announcements', 'rss_fed', 'rss_ecb', 'rss_coindesk', 'rss_cointelegraph']],
        'BNB' => ['enabled' => true, 'asset_class' => 'CRYPTO', 'name' => 'BNB', 'components' => ['BNB'],
            'aliases' => ['names' => ['bnb chain', 'binance coin'], 'tickers' => ['BNB', 'BNBUSDT']],
            'macro_drivers' => ['fed', 'regulation', 'exchange_incidents'], 'binance_futures_symbol' => 'BNBUSDT',
            'collectors' => ['fear_greed', 'binance_derivatives', 'binance_announcements', 'rss_fed', 'rss_ecb', 'rss_coindesk', 'rss_cointelegraph']],
        'SOL' => ['enabled' => true, 'asset_class' => 'CRYPTO', 'name' => 'Solana', 'components' => ['SOL'],
            'aliases' => ['names' => ['solana'], 'tickers' => ['SOL', 'SOLUSDT']],
            'macro_drivers' => ['fed', 'regulation'], 'binance_futures_symbol' => 'SOLUSDT',
            'collectors' => ['fear_greed', 'binance_derivatives', 'binance_announcements', 'rss_fed', 'rss_ecb', 'rss_coindesk', 'rss_cointelegraph']],
        'XRP' => ['enabled' => true, 'asset_class' => 'CRYPTO', 'name' => 'XRP', 'components' => ['XRP'],
            'aliases' => ['names' => ['ripple'], 'tickers' => ['XRP', 'XRPUSDT']],
            'macro_drivers' => ['fed', 'regulation', 'sec_litigation'], 'binance_futures_symbol' => 'XRPUSDT',
            'collectors' => ['fear_greed', 'binance_derivatives', 'binance_announcements', 'rss_fed', 'rss_ecb', 'rss_coindesk', 'rss_cointelegraph']],
        'SUI' => ['enabled' => true, 'asset_class' => 'CRYPTO', 'name' => 'Sui', 'components' => ['SUI'],
            'aliases' => ['names' => ['sui network'], 'tickers' => ['SUI', 'SUIUSDT']],
            'macro_drivers' => ['fed', 'regulation'], 'binance_futures_symbol' => 'SUIUSDT',
            'collectors' => ['fear_greed', 'binance_derivatives', 'binance_announcements', 'rss_fed', 'rss_ecb', 'rss_coindesk', 'rss_cointelegraph']],
        // Placeholders proving the shape. DISABLED: no collectors exist for them yet (Phase 4).
        'XAU' => ['enabled' => false, 'asset_class' => 'METALS', 'name' => 'Gold', 'components' => ['XAU', 'USD'],
            'aliases' => ['names' => ['gold', 'bullion'], 'tickers' => ['XAU', 'XAUUSD']],
            'macro_drivers' => ['fed', 'real_yields', 'usd', 'geopolitics'],
            'collectors' => ['rss_fed'], 'planned_collectors' => ['fred_yields', 'cftc_cot']],
        'EURUSD' => ['enabled' => false, 'asset_class' => 'FOREX', 'name' => 'EUR/USD', 'components' => ['EUR', 'USD'],
            'aliases' => ['names' => ['euro', 'ecb'], 'tickers' => ['EUR', 'USD', 'EURUSD']],
            'macro_drivers' => ['ecb', 'fed', 'cpi', 'nfp'],
            'collectors' => ['rss_fed', 'rss_ecb'], 'planned_collectors' => ['economic_calendar', 'cftc_cot']],
    ],

    // Fixed registry. enabled=false sources are implemented and tested but never fetched.
    'sources' => [
        'fear_greed' => ['enabled' => true, 'collector' => FearGreedCollector::class, 'kind' => 'metric', 'asset_class' => 'CRYPTO',
            'url' => 'https://api.alternative.me/fng/', 'refresh_minutes' => 360, 'stale_after_minutes' => 2160,
            // History is day-stamped, not publish-stamped: assume it was usable one day later.
            'backfill_lag_hours' => 24],
        'binance_derivatives' => ['enabled' => true, 'collector' => BinanceDerivativesMetricsCollector::class, 'kind' => 'metric', 'asset_class' => 'CRYPTO',
            'url' => 'https://fapi.binance.com/futures/data/', 'refresh_minutes' => 30, 'stale_after_minutes' => 240,
            'period' => '1h', 'buckets' => 6],
        // Undocumented website CMS endpoint (not a published API): left OFF until the
        // operator confirms Binance terms permit it. Parser is tested against a recorded fixture.
        'binance_announcements' => ['enabled' => false, 'collector' => BinanceAnnouncementsCollector::class, 'kind' => 'event', 'asset_class' => 'CRYPTO',
            'url' => 'https://www.binance.com/bapi/composite/v1/public/cms/article/list/query', 'refresh_minutes' => 30, 'stale_after_minutes' => 10080,
            'catalogs' => ['48' => 'listing', '161' => 'delisting'], 'page_size' => 10, 'scope' => 'market_wide'],
        // Same URLs as config/nexus.php (a test asserts they stay equal).
        'rss_fed' => ['enabled' => true, 'collector' => RssNewsCollector::class, 'kind' => 'event', 'asset_class' => 'CRYPTO',
            'url' => 'https://www.federalreserve.gov/feeds/press_all.xml', 'refresh_minutes' => 60, 'stale_after_minutes' => 10080,
            'category' => Tax::CATEGORY_MACRO_EVENT, 'severity' => Tax::SEVERITY_LOW, 'scope' => 'market_wide', 'excerpt' => true],
        'rss_ecb' => ['enabled' => true, 'collector' => RssNewsCollector::class, 'kind' => 'event', 'asset_class' => 'CRYPTO',
            'url' => 'https://www.ecb.europa.eu/rss/press.html', 'refresh_minutes' => 60, 'stale_after_minutes' => 10080,
            'category' => Tax::CATEGORY_MACRO_EVENT, 'severity' => Tax::SEVERITY_LOW, 'scope' => 'market_wide', 'excerpt' => true],
        // Headline-level only (excerpt=false): no article text is stored.
        'rss_coindesk' => ['enabled' => true, 'collector' => RssNewsCollector::class, 'kind' => 'event', 'asset_class' => 'CRYPTO',
            'url' => 'https://www.coindesk.com/arc/outboundfeeds/rss', 'refresh_minutes' => 15, 'stale_after_minutes' => 720,
            'category' => Tax::CATEGORY_CRYPTO_MARKET_NEWS, 'severity' => Tax::SEVERITY_LOW, 'scope' => 'asset_specific', 'excerpt' => false],
        'rss_cointelegraph' => ['enabled' => true, 'collector' => RssNewsCollector::class, 'kind' => 'event', 'asset_class' => 'CRYPTO',
            'url' => 'https://cointelegraph.com/rss', 'refresh_minutes' => 15, 'stale_after_minutes' => 720,
            'category' => Tax::CATEGORY_CRYPTO_MARKET_NEWS, 'severity' => Tax::SEVERITY_LOW, 'scope' => 'asset_specific', 'excerpt' => false],
    ],
];

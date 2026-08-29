<?php

namespace App\Domain\MarketData\Enums;

enum FeedState: string
{
    case CONNECTING = 'CONNECTING';
    case LIVE = 'LIVE';
    case DEGRADED = 'DEGRADED';
    case STALE = 'STALE';
    case RECOVERING = 'RECOVERING';
    case OFFLINE = 'OFFLINE';
    case MARKET_CLOSED = 'MARKET_CLOSED';
    case DISABLED = 'DISABLED';
}

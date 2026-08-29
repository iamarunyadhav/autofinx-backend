<?php

namespace App\Domain\MarketData\Enums;

enum StreamType: string
{
    case QUOTE = 'QUOTE';
    case TRADE = 'TRADE';
    case CANDLE = 'CANDLE';
    case ORDER_BOOK = 'ORDER_BOOK';
    case USER_STREAM = 'USER_STREAM';
}

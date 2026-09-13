<?php

namespace App\Domain\AuditResearch;

/**
 * A plain const list, not a DB enum column type, deliberately — new
 * categories can be added here without a migration. `diagnosis_category` on
 * audit_decision_snapshots / `root_cause_category` on audit_incidents are
 * free-text varchar columns that are expected to hold one of these values.
 */
final class RootCauseCategory
{
    public const CORRECT_BEHAVIOR = 'CORRECT_BEHAVIOR';
    public const STRATEGY_RULE = 'STRATEGY_RULE';
    public const FORECAST_ERROR = 'FORECAST_ERROR';
    public const DIRECTION_ERROR = 'DIRECTION_ERROR';
    public const MARKET_ANALYSIS_ERROR = 'MARKET_ANALYSIS_ERROR';
    public const DATA_STALE = 'DATA_STALE';
    public const DATA_GAP = 'DATA_GAP';
    public const FEED_DISCONNECTED = 'FEED_DISCONNECTED';
    public const FEED_LATENCY = 'FEED_LATENCY';
    public const QUEUE_LATENCY = 'QUEUE_LATENCY';
    public const WORKER_FAILURE = 'WORKER_FAILURE';
    public const API_LATENCY = 'API_LATENCY';
    public const API_ERROR = 'API_ERROR';
    public const EXCHANGE_REJECTION = 'EXCHANGE_REJECTION';
    public const BALANCE_INSUFFICIENT = 'BALANCE_INSUFFICIENT';
    public const RISK_BLOCK = 'RISK_BLOCK';
    public const ORDER_EXECUTION_ERROR = 'ORDER_EXECUTION_ERROR';
    public const SOFTWARE_DEFECT = 'SOFTWARE_DEFECT';
    public const CONFIGURATION_ERROR = 'CONFIGURATION_ERROR';
    public const UNKNOWN = 'UNKNOWN';

    public const ALL = [
        self::CORRECT_BEHAVIOR,
        self::STRATEGY_RULE,
        self::FORECAST_ERROR,
        self::DIRECTION_ERROR,
        self::MARKET_ANALYSIS_ERROR,
        self::DATA_STALE,
        self::DATA_GAP,
        self::FEED_DISCONNECTED,
        self::FEED_LATENCY,
        self::QUEUE_LATENCY,
        self::WORKER_FAILURE,
        self::API_LATENCY,
        self::API_ERROR,
        self::EXCHANGE_REJECTION,
        self::BALANCE_INSUFFICIENT,
        self::RISK_BLOCK,
        self::ORDER_EXECUTION_ERROR,
        self::SOFTWARE_DEFECT,
        self::CONFIGURATION_ERROR,
        self::UNKNOWN,
    ];
}

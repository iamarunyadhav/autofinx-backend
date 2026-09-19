# Capability map

The runtime registry records exact relative source paths and file presence. It deliberately reports CODE_PRESENT_UNVERIFIED_RUNTIME.

Verified code anchors include Trading/QuickTradeService, Trading/RobotSessionService, FutureTrade/FutureTradeBotService, Fast2X/Fast2XService, RatioBot/RatioBotBrainService, MarketIntelligence/UnifiedForecastEngine, Portfolio/PortfolioAllocationService, Advanced/SmartOrderRouter, Risk/RiskEngine and AuditResearch/AuditEventRecorder.
ForexGrid/GridLotSizer and Pepperstone/BasketExitEngine exist; broker-account readiness remains independent.

Observations include Quick Trade, Robot sessions, Future Trade logical trades, Fast2X trades via config ownership, Ratio Bot decisions via config ownership, Forex/Metals configs and audit events. Missing schemas/adapters are reported explicitly.

No runtime observation is proof that an entire capability is absent. No recent rows means insufficient observations.


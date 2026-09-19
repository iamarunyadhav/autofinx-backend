<?php

return [
    'enabled' => (bool) env('AUTOFINX_NEXUS_ENABLED', false),
    'autonomy' => (int) env('AUTOFINX_NEXUS_AUTONOMY_LEVEL', 2),
    'connection' => env('AUTOFINX_NEXUS_QUEUE_CONNECTION', 'nexus'),
    'queue' => 'agent_research',
    'training_queue' => 'agent_training',
    'max_pending' => 100,
    'retention_days' => 90,
    'llm' => [
        'enabled' => (bool) env('AUTOFINX_NEXUS_LLM_ENABLED', false),
        // OpenAI-compatible chat-completions transport; an operator-owned endpoint.
        'provider' => env('AUTOFINX_NEXUS_LLM_PROVIDER', 'openai-compatible'),
        'url' => env('AUTOFINX_NEXUS_LLM_URL'),
        'key' => env('AUTOFINX_NEXUS_LLM_API_KEY'),
        'routine_model' => env('AUTOFINX_NEXUS_ROUTINE_MODEL'),
        'deep_model' => env('AUTOFINX_NEXUS_DEEP_MODEL'),
        'max_output_tokens' => 2000,
        'max_input_bytes' => 16000,
        'calls_per_hour' => (int) env('AUTOFINX_NEXUS_MAX_CALLS_PER_HOUR', 4),
        'deep_calls_per_day' => (int) env('AUTOFINX_NEXUS_MAX_DEEP_CALLS_PER_DAY', 1),
        'daily_budget' => (float) env('AUTOFINX_NEXUS_DAILY_LLM_BUDGET_USD', 1),
        'monthly_budget' => (float) env('AUTOFINX_NEXUS_MONTHLY_LLM_BUDGET_USD', 15),
        // Required, conservative upper bound for BOTH configured models, per million tokens.
        'input_price' => (float) env('AUTOFINX_NEXUS_INPUT_USD_PER_MILLION', 0),
        'output_price' => (float) env('AUTOFINX_NEXUS_OUTPUT_USD_PER_MILLION', 0),
    ],
    // Only deployment-controlled sources. Never accept an arbitrary URL from a prompt/API.
    'sources' => [
        'federal-reserve' => ['url' => 'https://www.federalreserve.gov/feeds/press_all.xml', 'format' => 'rss', 'category' => 'macro', 'refresh_minutes' => 240, 'reliability' => 'primary', 'enabled' => true],
        'ecb' => ['url' => 'https://www.ecb.europa.eu/rss/press.html', 'format' => 'rss', 'category' => 'macro', 'refresh_minutes' => 240, 'reliability' => 'primary', 'enabled' => true],
        'arxiv-quant' => ['url' => 'https://export.arxiv.org/api/query?search_query=cat:q-fin.TR&sortBy=submittedDate&sortOrder=descending&max_results=10', 'format' => 'atom', 'category' => 'quant-research', 'refresh_minutes' => 1440, 'reliability' => 'unreviewed-research', 'enabled' => true],
    ],
];

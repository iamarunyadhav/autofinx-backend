<?php

/*
 * Strategy Lab (docs/ml-engine-v1/SPEC.md §105, D-030/D-031). Research only: candidates are audited as TEXT
 * (never executed) and tested by the V4 Python runner on local development data. Nothing here reaches an
 * order path. Heavy tests need the 5-year data set, which lives on the research PC (work_dir); where it is
 * not configured (e.g. the production server) a test is refused with RUNNER_UNAVAILABLE.
 */
return [
    'enabled' => (bool) env('AUTOFINX_STRATEGY_LAB_ENABLED', true),

    // Data / artifacts of the V4 research pipeline (binance data, features, reports), e.g. D:\autofinx-ml-v4.
    'work_dir' => env('AUTOFINX_STRATEGY_LAB_WORK_DIR'),
    'python_bin' => env('AUTOFINX_STRATEGY_LAB_PYTHON_BIN', env('AUTOFINX_ML_PYTHON_BIN', base_path('external-predictive-engine/.venv/bin/python'))),
    'engine_dir' => base_path('external-predictive-engine'),
    'artifacts_dir' => env('AUTOFINX_STRATEGY_LAB_ARTIFACTS_DIR', storage_path('app/private/strategy-lab')),

    'queue' => [
        'connection' => env('AUTOFINX_STRATEGY_LAB_QUEUE_CONNECTION', 'ml_training'),
        'name' => env('AUTOFINX_STRATEGY_LAB_QUEUE', 'strategy_lab'),
    ],
    // Must stay below the queue's retry_after (ml_training: 2400 s).
    'timeout_seconds' => ['quick' => (int) env('AUTOFINX_STRATEGY_LAB_QUICK_TIMEOUT', 900), 'deep' => (int) env('AUTOFINX_STRATEGY_LAB_DEEP_TIMEOUT', 2200)],
    'heartbeat_seconds' => 5,
    'poll_interval_ms' => 1000,
    'log_tail_lines' => 40,

    'max_source_bytes' => 120000,

    /*
     * Translated candidates. A version is testable only when its NORMALIZED source hash (CRLF -> LF, trailing
     * spaces stripped, trimmed) matches a registered translation; the Python adapter of the same key holds
     * the reviewed implementation. Arbitrary Pine is never interpreted (UNSUPPORTED / NEEDS_TRANSLATION).
     */
    'adapters' => [
        'autofinx-v13' => [
            'label' => 'AutoFinX v13 (Final) — owner Pine, translated 2026-10-07',
            'normalized_sha256' => ['21ff22ddfd13f524db3f9cc45b7b1c2feeed169ea63777d8b9367668d1846fe7'],
        ],
        'v1' => [
            'label' => 'AutoFinX v10.1 Smart Short (translated 2026-10-08)',
            'normalized_sha256' => ['5709908d30fa5b4462b8fc9594be2a9079030a5cd1132afc26ff989fb1f6ba2d'],
        ],
        'v2' => [
            'label' => 'AutoFinX UT Bot Pro v6 (translated 2026-10-08)',
            'normalized_sha256' => ['4b724f5e36995e79f1e04dfca431df4c23746067cc51f21cc6c1f1bdbc5bfa7e'],
        ],
        'v3' => [
            'label' => 'AutoFinX TF-1 Donchian Trend v1 (translated 2026-10-08)',
            'normalized_sha256' => ['414fd84f0d386f7af01c7e3904af719a4c85d12c5f631cd084960cbeea56e3af'],
        ],
        'v4' => [
            'label' => 'AutoFinX TF-1 Donchian Trend v2 filtered (translated 2026-10-08)',
            'normalized_sha256' => ['1e10fb1386a4cb3ed1d2f20d589acd1baba8f8b054d15f5b2c817e96027a1416'],
        ],
    ],
];

<?php

return [
    // Master switch. When false, AuditEventRecorder::record() is a true
    // no-op (checked first, before any DB work) — the Ratio Bot trading
    // path must be provably unaffected either way. Default true is fine for
    // this dev-stage build; flip off in .env if instrumentation overhead
    // ever needs to be ruled out during an incident.
    'enabled' => filter_var(env('AUDIT_RESEARCH_ENABLED', true), FILTER_VALIDATE_BOOL),
];

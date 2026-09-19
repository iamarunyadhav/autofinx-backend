# Architecture

Laravel's existing scheduler dispatches Nexus every 15 minutes. One nexus_runs record per workspace/time slot prevents duplicates. agent_research and agent_training have separate workers and use the nexus Redis connection (retry_after 180s; worker timeout 110s).

NexusCycleService → capability inventory → bounded workspace observations → registered public source collection → baseline/gap hypotheses → gated model synthesis → daily/weekly reports → portable knowledge.

Four Nexus-only tables: nexus_runs, nexus_entries (typed capability/observation/source/candidate/experiment/report documents), nexus_reviews, nexus_model_calls. Every row has workspace_id. Public source cache alone is shared across workspaces; provider budgets are globally reserved.

NexusController verifies workspace membership on all routes; owner/admin is required for mutations. Existing trading tables are read only. Experiment outputs remain in Nexus, not Ratio Bot calibration tables.

The dedicated queue connection defaults to Redis. A durable database queue is supported only with retry_after >110. Never use sync/failover-to-sync. Source bodies, queries, replay size, queue backlog and API page size are bounded.

No worker deployment was performed by the coding session. See ../docs/NEXUS_OPERATIONS.md.


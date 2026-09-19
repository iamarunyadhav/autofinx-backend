# Autonomy policy

0: local observations and reports only.
1: observations, external research and candidate proposals.
2: the above plus operator-requested queued forecast experiments.
3–6: roadmap only; never grant execution through a configuration switch.

Ordinary cycles call no LLM when disabled. Enabled research requires new evidence, configured model and prices, a daily reasoning gate, global hourly/deep-call limits and daily/monthly reserved-cost limits. Failed calls retain their reservation to avoid undercounting timeout charges.


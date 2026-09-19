# Data sources

config/nexus.php owns URL, format, category, reliability, refresh interval and enabled state. Initial sources: Federal Reserve press RSS, ECB press RSS, arXiv q-fin.TR Atom. arXiv items are explicitly unreviewed research. These are configured sources, not evidence that all endpoints were successfully contacted in deployment.

Internal market/execution data is reused; Nexus does not start duplicate exchange polling. Add proprietary news, broker, economic calendar or market feeds only with a reviewed adapter and lawful access. Credentials stay in server configuration.

Public HTTP: HTTPS, fixed registry, public DNS pinning, no redirects, 3s connect/8s total timeout, 256KiB transfer/body cap, XML entity/DTD rejection, 10 items and bounded text. Cache refreshes independently from the 15-minute scheduler. Failure is explicit and does not fabricate empty successful data.


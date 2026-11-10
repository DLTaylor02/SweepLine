# Durable discovery searches

Discovery submits a persistent job and polls its owner-only status. Heavy geographic lookup, overlap consolidation and scoring run outside PHP-FPM's short request deadline. A queued request is accepted work, not a discarded request. The UI shows queued/running stages, a queue count, preparation status and explicit failures. Available partial roads remain visible.

The installer enables `sweepline-search.service`, a PHP CLI process using the same account, permissions and hardening as the existing background worker. It is independent of `sweepline-worker.service`: one discovery job and one import/export can execute concurrently. Search jobs themselves run serially to bound database/CPU load on shared hosts; this is not unlimited parallel processing. Database contention can still delay or fail work, but it now produces a durable stage-specific failure rather than depending on a long-lived HTTP connection.

Identical pending requests by the same user share a job. Different requests queue up to four per user and 128 overall; excess requests receive an explicit 429 response. SuperAdmin can cancel queued/running searches in Manage. Running cancellation is cooperative between phases. Restart recovery marks interrupted searches failed and retains their last stage.

Browser-tab session storage retains the current job ID, scoped to the signed-in user. Returning to Discovery in that tab reconnects to its progress/results without submitting duplicate discovery work. Navigating away does not cancel server work. Other tabs can submit separate searches. This does not synchronize current selections across devices; History remains the persistent cross-session record. Expired job IDs show an explicit error and can be retried with Discover roads.

Search workers save History snapshots independently of the page. While regional preparation continues, the open Discovery page periodically requests updated discovery passes; leaving the page preserves its latest partial snapshot, not an automatically refreshed final snapshot. Each completed pass updates the same History entry.

Query execution is capped at 45 seconds per statement with a two-second lock timeout. A cooperative three-minute total budget is checked at phase boundaries. Terminal job payloads are eligible for cleanup after 30 minutes, checked hourly; History snapshots remain under existing retention limits. Existing global PHP settings are unchanged.

Deploy with `sudo ./setup.sh` to apply additive migration 022 and install/restart both services. Diagnose with:

```bash
sudo systemctl status sweepline-search.service
sudo journalctl -u sweepline-search.service -n 100 --no-pager
sudo journalctl -u sweepline-worker.service -n 100 --no-pager
```

The search journal records phase durations and safe database failure identifiers, not query parameters or credentials. A slow or unavailable database can still affect enqueue/status requests.

API clients may POST `/api/search` with JSON `background:true` to receive HTTP 202 with `search_job`; poll GET `/api/search-jobs/{id}`. Completed responses include `result`. Status and results are private to the requesting owner. POST `/api/admin/search-jobs/{id}/cancel` requires SuperAdmin and CSRF. Without `background:true`, the existing synchronous API remains available for compatibility and still has web-request time limits.

Missing-road diagnostics also use this queue. POST /api/search-diagnostics validates the road name and filters, returns HTTP 202, and is polled using the same owner-only search-job endpoint. Diagnostic passes check existing source tags, candidate exclusions and actual consolidated search visibility; they never prepare coverage or start imports. The UI displays current stages and retains an explicit error on failure. Diagnostic jobs share discovery admission limits and can be cancelled in Manage.

Diagnostic explanations follow the same discovery pass: a source candidate may fall outside the initial top 600, lack a cached profile score, join a combined stretch that fails a filter, or rank below the final top 100. Combined filter failures identify the combined road and failed requirements. Original candidate requirements are shown separately; passing those alone does not imply that the combined result qualifies. No thresholds or result limits are changed by this diagnostic improvement.

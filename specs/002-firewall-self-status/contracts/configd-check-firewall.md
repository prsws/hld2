# Contract: configd action `casadelta check_firewall`

**Definition file**: `src/opnsense/service/conf/actions.d/actions_casadelta.conf`
**Script**: `/usr/local/opnsense/scripts/CasaDelta/firewall_status.php`
**Data definitions**: [data-model.md](../data-model.md) §1–§3

```ini
[check_firewall]
command:/usr/local/opnsense/scripts/CasaDelta/firewall_status.php
parameters:%s
type:script_output
message:Casa Delta firewall self-status check
```

## Invocation

| Call                                    | Meaning                                                    |
|-----------------------------------------|------------------------------------------------------------|
| `configctl casadelta check_firewall`    | Read only if the snapshot is stale and not in cooldown; otherwise return the existing snapshot. |
| `configctl casadelta check_firewall force`| Read unless the cooldown (5 s) is active.                  |

Any other parameter value is treated like no parameter. The script ignores everything else in
argv. Like `check_hosts`, any other parameter value behaves like the no-parameter form.

## Output (stdout, single JSON document)

The document is the `FirewallSnapshot` (data-model §3). `performed` and `retry_after_s` describe
**this** invocation only and are not saved in the snapshot file.

```json
{
  "schema": 1,
  "ok": true,
  "error": null,
  "performed": true,
  "retry_after_s": 0,
  "started_at": "2026-10-16T08:40:00Z",
  "finished_at": "2026-10-16T08:40:02Z",
  "duration_ms": 1980,
  "fingerprint": "0000…0000",
  "firewall": {
    "hostname": "firewall01",
    "version": "26.7.6",
    "uptime_s": 842133,
    "last_boot": "2026-10-10T20:37:40Z",
    "load_avg_1m": 0.31, "load_avg_5m": 0.48, "load_avg_15m": 0.52,
    "cpu_cores": 4,
    "cpu_usage_percent": 12.5,
    "memory_used": 1180310528, "memory_total": 16723411456, "memory_used_percent": 7.06,
    "disk_used": 3874375168, "disk_total": 37683710976, "disk_used_percent": 10.28,
    "cpu_temperature": 48.5, "cpu_temperature_present": true,
    "warnings": [],
    "all_unavailable": false,
    "collected_at": "2026-10-16T08:40:02Z"
  }
}
```

The `fingerprint` is the **fixed sentinel** `SnapshotFreshness::FIREWALL_FINGERPRINT` (a constant
SHA-256); it is *not* derived from any host inventory. On a host without a CPU temperature reader
the `firewall` object simply omits `cpu_temperature` and sets
`"cpu_temperature_present": false` (FR-007).

## Guarantees (Principle II)

| Guarantee                      | Bound                                                     |
|--------------------------------|-----------------------------------------------------------|
| Reads per run                 | A fixed set of short-lived local commands (R-08 sources)   |
| Per-value timeout             | Strict per-value deadline per metric (R-10)               |
| Whole-run cap                 | 10 s; unfinished readers are SIGKILL'd and their value is marked unavailable |
| Concurrency                    | Reads run sequentially within the script (the read set is small and cheap); the *parallel* aspect is the two configd actions in the API (R-09), not within this script |
| Minimum interval between runs | 5 s (global, enforced under `flock`)                      |
| Persistent processes          | None: the script exits when the run ends                  |
| Writes                        | Only `/tmp/casadelta_dashboard_firewall.json` (+ `.tmp`) and `/tmp/casadelta_dashboard_firewall.lock` |
| Config access                 | **Read-only and minimal.** The script reads only OS telemetry sysctls/commands. It never writes `config.xml`, never reads the plugin model, never touches filter, route, NAT or interface state, and never calls `configctl filter`/`interface`/similar. |
| Shell usage                   | None. Every reader runs through `proc_open` with an argv array; no reader argument is ever built from user or network input, so command injection is not possible. |
| Logging                       | One syslog line per run (`casadelta-dashboard: firewall self-status in X ms, N unavailable`), plus failures. No secrets, no per-metric spam. |

## Failure contract

- **A read that fails to produce a value** (the script cannot start, crashes, exceeds the run cap,
  or configd returns empty / non-JSON / `Execute error`): the run completes with `ok = false` and
  a `firewall` object where **every value is `null`** and `all_unavailable = true`. The API serves
  that object; the panel shows the single discreet notice (FR-013 — the spec names "backend
  failure" as exactly this case). The host status below is unaffected (FR-017).
- **Individual reader failures** do **not** set `ok = false` and do **not** blank the object; they
  leave just that value `null` ("unavailable") while every other value renders normally (FR-012).
- A still-fresh cached snapshot (≤ 55 s old) is served even if a *previous* read had failed —
  freshness, not history, decides what the API returns.

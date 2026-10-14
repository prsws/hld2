# Contract: configd action `casadelta check_hosts`

**Definition file**: `src/opnsense/service/conf/actions.d/actions_casadelta.conf`
**Script**: `/usr/local/opnsense/scripts/CasaDelta/check_hosts.php`
**Data definitions**: [data-model.md](../data-model.md) §2–§4

```ini
[check_hosts]
command:/usr/local/opnsense/scripts/CasaDelta/check_hosts.php
parameters:%s
type:script_output
message:Casa Delta host status check
```

## Invocation

| Call                                   | Meaning                                                    |
|----------------------------------------|------------------------------------------------------------|
| `configctl casadelta check_hosts`      | Probe only if the snapshot is stale and not in cooldown; otherwise return the existing snapshot. |
| `configctl casadelta check_hosts force`| Probe unless the cooldown (5 s) is active.                 |

Any other parameter value is treated like no parameter. The script ignores everything else in
argv.

## Output (stdout, single JSON document)

```json
{
  "schema": 1,
  "ok": true,
  "error": null,
  "performed": true,
  "retry_after_s": 0,
  "started_at": "2026-10-14T09:15:00Z",
  "finished_at": "2026-10-14T09:15:02Z",
  "duration_ms": 1840,
  "fingerprint": "3b1f…",
  "results": {
    "6f1c0e8e-…": { "state": "up", "latency_ms": 0.42, "last_checked": "2026-10-14T09:15:01Z", "error_message": null },
    "a2d4…":      { "state": "cannot_resolve", "latency_ms": null, "last_checked": "2026-10-14T09:15:01Z", "error_message": "cannot resolve pve.lan" }
  }
}
```

`performed` and `retry_after_s` describe **this** invocation only. They are not saved in the
snapshot file.

## Guarantees (Principle II)

| Guarantee                      | Bound                                                     |
|--------------------------------|-----------------------------------------------------------|
| Probes per host per run        | Exactly 2 ICMP echo requests (`ping -c 2`)                |
| Per-probe wait                 | 1000 ms (`-W 1000`); ping hard timeout 3 s (`-t 3`)       |
| Per-process wall-clock deadline| 4 s, then SIGKILL                                         |
| Parallel ping processes        | ≤ 16                                                      |
| Whole-run cap                  | 20 s; unfinished hosts are reported `down` with "probe timed out" |
| Minimum interval between runs  | 5 s (global, enforced under `flock`)                      |
| Persistent processes           | None: the script exits when the run ends                  |
| Writes                         | Only `/tmp/casadelta_dashboard_status.json` (+ `.tmp`) and `/tmp/casadelta_dashboard_check.lock` |
| Config access                  | Read-only. The script reads only `hosts.host` of model `PRSWS\CasaDelta\CasaDelta` (`//PRSWS/CasaDelta`). It never writes `config.xml`, never touches filter, route, NAT or interface state, and never calls `configctl filter`, `interface` or similar |
| Shell usage                    | None. Probes run through `proc_open` with an argv array   |
| Logging                        | One syslog line per run (`casadelta-dashboard: checked N hosts in X ms`), plus failures. No per-host spam and no secrets |

## Failure contract

- If the config can't be loaded or an internal error happens, the script prints
  `{"schema":1,"ok":false,"error":"…","results":{…previous results…}}` and exits 0. The status API
  maps this to `subsystem_status = "error"`.
- A crash or timeout gives empty or non-JSON output (or configd's `Execute error`). The status API
  maps this to `subsystem_status = "error"` too.

# Contract: Firewall object in the Status API

**Controller**: `PRSWS\CasaDelta\Api\StatusController` (extends `OPNsense\Base\ApiControllerBase`)
**Privilege**: `page-casadelta-status` (slice 001 [acl-menu.md](../../001-host-status-monitoring/contracts/acl-menu.md)) — **no new privilege**
**Auth**: An OPNsense GUI session (CSRF token added by `ajaxCall`) or an API key/secret (HTTP Basic).
**Data definitions**: [data-model.md](../data-model.md) §2–§4

This slice **extends** slice 001's status API. The existing `summary` and `groups` keys, the
endpoint routes, the method checks and the host behaviour are **unchanged**. The only change is a
new top-level `firewall` key in the response body (see
[contracts/status-api.md](../../001-host-status-monitoring/contracts/status-api.md) for the parts
not repeated here).

**Refresh mechanics (R-09)**: `buildStatus()` runs `check_hosts` and `check_firewall` in
**parallel** through a bounded `proc_open` pool whenever the respective snapshot is stale or the
request is forced. Each side is independently deduplicated by its own snapshot, so a request where
both snapshots are fresh launches **no** process and returns both objects from cache. The firewall
side has a 15 s wall-clock deadline; the hosts side keeps slice 001's 30 s budget. The response
waits for both.

---

## `GET /api/casadelta/status/get`

### 200 OK, normal (firewall healthy)

```json
{
  "summary": {
    "total_hosts": 3, "up_hosts": 1, "down_hosts": 1, "unresolved_hosts": 1, "pending_hosts": 0,
    "last_run": "2026-10-16T08:40:02Z", "next_check_at": "2026-10-16T08:41:02Z",
    "subsystem_status": "ok", "subsystem_error": null
  },
  "groups": [ "…unchanged from slice 001…" ],
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

The `firewall` object is **always present** — even when `total_hosts = 0` and `groups = []`, and
even when every host is `down` (FR-017, SC-004).

### 200 OK, some values unreadable (FR-012)

```json
"firewall": {
  "hostname": "firewall01", "version": "26.7.6", "uptime_s": 842133,
  "last_boot": "2026-10-10T20:37:40Z",
  "load_avg_1m": 0.31, "load_avg_5m": 0.48, "load_avg_15m": 0.52, "cpu_cores": 4,
  "cpu_usage_percent": null,
  "memory_used": 1180310528, "memory_total": 16723411456, "memory_used_percent": 7.06,
  "disk_used": null, "disk_total": 37683710976, "disk_used_percent": null,
  "cpu_temperature_present": false,
  "warnings": [],
  "all_unavailable": false,
  "collected_at": "2026-10-16T08:40:02Z"
}
```

`cpu_usage_percent` and `disk_used` are `null` → the view renders "unavailable" for exactly those.
`cpu_temperature` is **absent** because `cpu_temperature_present = false` (FR-007). All other values
render normally.

### 200 OK, all values unavailable (FR-013)

```json
"firewall": {
  "hostname": null, "version": null, "uptime_s": null, "last_boot": null,
  "load_avg_1m": null, "load_avg_5m": null, "load_avg_15m": null, "cpu_cores": null,
  "cpu_usage_percent": null,
  "memory_used": null, "memory_total": null, "memory_used_percent": null,
  "disk_used": null, "disk_total": null, "disk_used_percent": null,
  "cpu_temperature_present": false,
  "warnings": [],
  "all_unavailable": true,
  "collected_at": "2026-10-16T08:40:02Z"
}
```

`all_unavailable = true` drives the single discreet notice in the panel. The host `groups` below are
unaffected and fully functional (FR-017). Recovery is automatic on the next successful read — no
user action.

---

## `POST /api/casadelta/status/refresh`

Identical to slice 001, plus the same `firewall` key as above and the same `refresh` object
(`performed`, `retry_after_s`). The forced refresh triggers `check_firewall force` in parallel with
`check_hosts force`.

```json
"refresh": { "performed": true, "retry_after_s": 5 }
```

---

## `firewall` object invariants

- **Always present** in the body, regardless of host configuration or host state (FR-017).
- `warnings` is a subset of `{ "memory", "disk", "load" }` and **never** contains a metric whose
  value is `null` (a highlight implies the threshold was actually crossed — SC-002).
- `cpu_temperature` is present **only** when `cpu_temperature_present` is `true`; when that flag
  is `false` the field is omitted, never `null`, never "unavailable" (FR-007, SC-003).
- `all_unavailable` is `true` **only** when every readable metric is `null` — this covers both a
  run in which all readers failed and a backend read that failed to produce a value (crash,
  timeout, non-JSON — FR-013 names "backend failure" as exactly this case). The view then shows a
  single discreet notice and no individual highlights, and it clears automatically on the next
  successful read.
- Timestamps (`last_boot`, `collected_at`) are ISO 8601 UTC; the view renders them in the viewer's
  local time (FR-003).
- No field contains secrets, file paths or raw command output.

### Errors

| Condition                          | Response                                                            |
|------------------------------------|---------------------------------------------------------------------|
| No privilege                       | HTTP 403 (enforced by the OPNsense ACL layer, unchanged)            |
| Method other than GET / refresh not POST | Rejected with `{"status":"failed"}`, unchanged              |
| Backend firewall read failure (crash/timeout/non-JSON) | 200 with `all_unavailable = true` and all-null values; host `summary`/`groups` unaffected (FR-013, FR-017) |

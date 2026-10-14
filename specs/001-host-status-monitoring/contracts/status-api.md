# Contract: Status API (viewer)

**Controller**: `PRSWS\CasaDelta\Api\StatusController` (extends `OPNsense\Base\ApiControllerBase`)
**Privilege**: `page-casadelta-status` (see [acl-menu.md](./acl-menu.md))
**Auth**: An OPNsense GUI session (the CSRF token is added by `ajaxCall`) or an API key/secret
(HTTP Basic).
**Data definitions**: [data-model.md](../data-model.md) §3–§6

Neither endpoint changes the firewall configuration.

---

## `GET /api/casadelta/status/get`

Returns the current dashboard status. Behaviour:

1. Load the enabled hosts from `hosts.host` in `PRSWS\CasaDelta\CasaDelta` and compute the
   fingerprint.
2. Read `/tmp/casadelta_dashboard_status.json`. If it is fresh (data-model §4), use it as-is.
   **No configd call is made.**
3. Otherwise call `configctl casadelta check_hosts` (30 s timeout) and use the snapshot it returns.
4. Join the results to the current config by UUID, sort them (data-model §6), compute the
   summary, and respond.

### 200 OK, normal

```json
{
  "summary": {
    "total_hosts": 3,
    "up_hosts": 1,
    "down_hosts": 1,
    "unresolved_hosts": 1,
    "pending_hosts": 0,
    "last_run": "2026-10-14T09:15:02Z",
    "next_check_at": "2026-10-14T09:16:02Z",
    "subsystem_status": "ok",
    "subsystem_error": null
  },
  "groups": [
    {
      "name": "Network",
      "hosts": [
        {
          "host_uuid": "6f1c0e8e-1b8a-4c47-9d0e-6f3e2a1d9b10",
          "name": "Core Switch",
          "address": "192.168.1.2",
          "group": "Network",
          "sort_order": 0,
          "state": "up",
          "latency_ms": 0.42,
          "last_checked": "2026-10-14T09:15:01Z",
          "error_message": null,
          "stale": false
        }
      ]
    },
    {
      "name": "Nodes",
      "hosts": [
        {
          "host_uuid": "a2d4…",
          "name": "Proxmox Node 1",
          "address": "pve.lan:8006",
          "group": "Nodes",
          "sort_order": 10,
          "state": "cannot_resolve",
          "latency_ms": null,
          "last_checked": "2026-10-14T09:15:01Z",
          "error_message": "cannot resolve pve.lan",
          "stale": false
        },
        {
          "host_uuid": "c9e0…",
          "name": "NAS",
          "address": "[2001:db8::20]:5000",
          "group": "Nodes",
          "sort_order": 20,
          "state": "down",
          "latency_ms": null,
          "last_checked": "2026-10-14T09:15:02Z",
          "error_message": null,
          "stale": false
        }
      ]
    }
  ]
}
```

### 200 OK, check subsystem failure (FR-014)

```json
{
  "summary": {
    "total_hosts": 3, "up_hosts": 1, "down_hosts": 1, "unresolved_hosts": 1, "pending_hosts": 0,
    "last_run": "2026-10-14T09:12:02Z",
    "next_check_at": "2026-10-14T09:13:02Z",
    "subsystem_status": "error",
    "subsystem_error": "Host check could not be executed (backend timeout)."
  },
  "groups": [ "…last known results, every host with \"stale\": true…" ]
}
```

### 200 OK, no enabled hosts

`summary.total_hosts = 0`, `groups = []`, `subsystem_status = "ok"`. In this case configd is not
called at all.

### Invariants

- `groups[]` and `groups[].hosts[]` are already in display order. Clients MUST NOT re-sort them.
- Disabled hosts never appear, and `total_hosts` counts enabled hosts only.
- `latency_ms` is non-null only when `state = "up"`.
- `state = "down"` is never produced by a subsystem failure.
- No field contains secrets, file paths or raw command output.

### Errors

| Condition                         | Response                                   |
|-----------------------------------|--------------------------------------------|
| No privilege                      | HTTP 403 (enforced by the OPNsense ACL layer) |
| Method other than GET             | Rejected with `{"status":"failed"}`; no check is executed |

---

## `POST /api/casadelta/status/refresh`

This is the manual refresh (FR-010). It calls `configctl casadelta check_hosts force`. The
server-side cooldown is global: if the last run finished less than 5 s ago, the snapshot is
returned without probing.

### 200 OK

The body is the same as for `GET /get`, plus:

```json
{
  "refresh": {
    "performed": true,
    "retry_after_s": 5
  }
}
```

- `performed = false` with `retry_after_s` between 1 and 5 means the cooldown was active and the
  existing results were returned.
- The client MUST disable the "Refresh now" button for `max(5, retry_after_s)` seconds.

### Errors

| Condition                 | Response                                                        |
|---------------------------|-----------------------------------------------------------------|
| No privilege              | HTTP 403                                                        |
| Method other than POST    | `{"status":"failed"}`, no check executed                        |
| Missing/invalid CSRF (session auth) | Rejected by the framework                             |
| Backend failure           | 200 with `subsystem_status = "error"`, as above                 |

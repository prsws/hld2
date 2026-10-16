# Data Model: Firewall Self-Status

**Feature**: `002-firewall-self-status` | **Date**: 2026-10-16 | **Plan**: [plan.md](./plan.md)

This slice has **one** storage domain, and it is **ephemeral only** — there is no persistent
configuration, because thresholds are fixed and there is no settings surface
([research R-01](./research.md#r-01-no-configuration-surface-fixed-thresholds)).

- **Ephemeral runtime state**: a single snapshot file in `/tmp`, written only by the `check_firewall`
  configd script. It is never part of the configuration and is never web-served.

The slice reuses slice 001's `SnapshotFreshness` pure class (55 s TTL, 5 s cooldown, schema check)
for the firewall snapshot, but with a fixed sentinel fingerprint, because the firewall's identity
does not depend on the host inventory (R-03).

---

## 1. `FirewallTelemetry` (ephemeral raw read, produced once per run)

The raw output of the bounded local read set in `firewall_status.php`, parsed by the pure
`FirewallTelemetry` class. Each metric is either a real value or `null` (unreadable). A `null` here
means the read **failed or timed out** — it is *not* the "sensor absent" case for temperature.

| Field               | Type        | Notes                                                                 |
|---------------------|-------------|-----------------------------------------------------------------------|
| `hostname`          | string\|null | `gethostname()`.                                                     |
| `version`           | string\|null | Installed OPNsense version.                                          |
| `last_boot`         | int\|null     | Unix time of the last boot (from `kern.boottime`).                   |
| `uptime_s`          | int\|null     | `now − last_boot`, whole seconds.                                    |
| `load_avg_1m`       | float\|null | From `vm.loadavg`.                                                   |
| `load_avg_5m`       | float\|null | The warning source (R-07).                                           |
| `load_avg_15m`      | float\|null |                                                                      |
| `cpu_cores`         | int\|null     | `hw.ncpu` (logical). The load-warning basis.                         |
| `cpu_usage_percent` | float\|null | `100 − idle%` over a 1 s sample (R-05).                             |
| `memory_free`       | int\|null     | Bytes, free pages × page size.                                       |
| `memory_inactive`   | int\|null     | Bytes, reclaimable.                                                  |
| `memory_laundry`    | int\|null     | Bytes, reclaimable.                                                  |
| `memory_arc`        | int\|null     | Bytes, ZFS ARC (reclaimable); `0` on a non-ZFS box.                  |
| `memory_total`      | int\|null     | Bytes, physical RAM (`hw.physmem`).                                  |
| `disk_used`         | int\|null     | Bytes, used on the root filesystem (`df /`).                         |
| `disk_total`        | int\|null     | Bytes, total on the root filesystem.                                 |
| `cpu_temperature`   | float\|null | °C, present only when the hardware reports one (`sensors`). `null` when the reader is absent **or** the read failed — the *omitted vs unavailable* distinction is resolved by `cpu_temperature_present` (R-06). |
| `cpu_temperature_present` | bool       | `false` when the appliance has no CPU temperature reader at all (→ value omitted entirely, FR-007). `true` means a reader exists; the value is real unless `cpu_temperature` is null (→ "unavailable"). |

**Validation rules** (from FR-002…FR-008):

- Byte fields are non-negative integers; percentages are `0 ≤ x ≤ 100` where derivable.
- `cpu_usage_percent` is clamped to `0..100`.
- A metric with a `null` value is **not** counted for any percentage or warning.

**Memory "used" (FR-005)** is *derived* from the raw fields, not stored raw:

```text
memory_reclaimable = memory_free + memory_inactive + memory_laundry + memory_arc
memory_used        = memory_total − memory_reclaimable
memory_used_percent = (memory_used / memory_total) × 100
```

All four reclaimable terms must be present for the derivation; if `memory_total` is present but any
reclaimable term is `null`, `memory_used` is reported as `null` (unavailable) rather than guessed.
A large ZFS ARC is therefore *reclaimable* and never counts as used, so it cannot trip the 90%
warning (Edge Case: large ZFS cache).

---

## 2. `FirewallStatus` (ephemeral, the display-ready object)

Produced by the pure `FirewallStatusAssembler` from a `FirewallTelemetry`. This is the object the
API returns in the `firewall` key and the Volt view renders.

| Field               | Type        | Derivation / notes                                                   |
|---------------------|-------------|----------------------------------------------------------------------|
| `hostname`          | string\|null | From telemetry; `null` → "unavailable".                             |
| `version`           | string\|null | From telemetry; `null` → "unavailable".                             |
| `uptime_s`          | int\|null     | From telemetry; the view renders it as `Xh Ym Zs`.                  |
| `last_boot`         | string\|null | ISO 8601 UTC of the last boot; the view renders it in browser-local time. |
| `load_avg_1m`       | float\|null | Displayed with 2 decimals.                                           |
| `load_avg_5m`       | float\|null | Displayed with 2 decimals.                                           |
| `load_avg_15m`      | float\|null | Displayed with 2 decimals.                                           |
| `cpu_cores`         | int\|null     |                                                                      |
| `cpu_usage_percent` | float\|null | Displayed with 1 decimal and a `%`.                                  |
| `memory_used`       | int\|null     | Derived bytes (FR-005). `null` → "unavailable".                     |
| `memory_total`      | int\|null     | Bytes. The view formats used/total with the percentage.             |
| `memory_used_percent` | float\|null | Derived; drives the memory warning (R-04, FR-008).                  |
| `disk_used`         | int\|null     | Bytes. `null` → "unavailable".                                      |
| `disk_total`        | int\|null     | Bytes.                                                              |
| `disk_used_percent` | float\|null | Derived; drives the disk warning (FR-008).                          |
| `cpu_temperature`   | float\|null | **Omitted from the object** (not `null`) when `cpu_temperature_present` is false (FR-007). Present only when the hardware reports it. |
| `cpu_temperature_present` | bool     | Lets the view omit the row when false.                              |
| `warnings`          | list | Names of metrics over threshold: subset of `{ "memory", "disk", "load" }` (R-07). Never contains a metric whose value is `null` (Edge Case: highlight with unavailable). |
| `all_unavailable`   | bool    | True when every metric is `null` (FR-013) — drives the single discreet notice. |
| `collected_at`      | string\|null | ISO 8601 UTC of the run; rendered in browser-local time.            |

**Warning rules (FR-008, fixed — FR-009)**:

| Warning  | Condition (only when the value is not `null`)            |
|----------|----------------------------------------------------------|
| `memory` | `memory_used_percent > 90`                               |
| `disk`   | `disk_used_percent > 90`                                 |
| `load`   | `load_avg_5m > cpu_cores`                                |

A value that is `null` (unreadable) is **never** warned — the highlight applies only to
successfully read values that cross a threshold (Edge Case: warning with unavailable values).

**Availability rule (FR-012)**: each field renders its real value when present, or the literal
"unavailable" when `null`. The panel always renders; one `null` never blanks the others.

**`cpu_temperature` special case (FR-007)**: when `cpu_temperature_present` is `false` the
temperature field is **absent** from the object (the view omits the whole row) — it is never shown
as "unavailable", a warning, or a placeholder. When present but the read failed, it is `null` and
renders "unavailable".

---

## 3. `FirewallSnapshot` (ephemeral file `/tmp/casadelta_dashboard_firewall.json`)

The shared result cache (R-03). Only `firewall_status.php` writes it, using temp file + `rename()`
while holding `flock` on `/tmp/casadelta_dashboard_firewall.lock`. It wraps one `FirewallStatus`
object with the shared run metadata.

| Field          | Type                         | Notes                                                                 |
|----------------|------------------------------|-----------------------------------------------------------------------|
| `schema`       | int                          | `1`. Readers reject unknown versions.                                  |
| `ok`           | bool                         | `false` if the run itself failed to produce any value.                |
| `error`        | string \| null               | Message for the failure when `ok = false`.                            |
| `started_at`   | string (ISO 8601 UTC)        |                                                                       |
| `finished_at`  | string (ISO 8601 UTC)        | Basis for freshness (55 s TTL).                                       |
| `duration_ms`  | int                          | Used to check the performance budget.                                 |
| `fingerprint`  | string (hex SHA-256)         | **Fixed sentinel** `SnapshotFreshness::FIREWALL_FINGERPRINT`; the firewall has no per-host inventory to hash (R-03). Freshness is purely time-based. |
| `firewall`     | object                       | The `FirewallStatus` object from §2 (the display-ready payload).     |

**Freshness rule** (reuses `SnapshotFreshness`, a pure class):

```text
fresh    ⇔ ok ∧ schema = 1 ∧ (now − finished_at) < 55 s ∧ fingerprint = FIREWALL_FINGERPRINT
cooldown ⇔ (now − finished_at) < 5 s   (no new read run starts during the cooldown, forced or not)
```

**Run lifecycle** (inside the configd script):

```text
Idle ──request(stale|force)──> WaitingForLock ──lock──> Recheck
Recheck ──fresh (normal request) | cooldown (any request)──> ReturnExisting ──> Idle
Recheck ──otherwise──> Reading (bounded per-value + 10 s run cap) ──> Writing ──rename──> Idle
Reading ──fatal error / all-unavailable──> Failed (ok=false or all_unavailable, snapshot written) ──> Idle
```

---

## 4. `DashboardStatusResponse` (the extended slice 001 body)

The existing slice 001 status body gains exactly one new top-level key. The existing `summary` and
`groups` keys are **unchanged** (this slice does not modify host-checking behaviour).

| Key        | Type     | Notes                                                                     |
|------------|----------|---------------------------------------------------------------------------|
| `summary`  | object   | **Unchanged** from slice 001 (data-model §5 there).                       |
| `groups`   | list     | **Unchanged** from slice 001.                                             |
| `firewall` | object   | **New** — the `FirewallStatus` object from §2 (see [contracts/firewall-api.md](./contracts/firewall-api.md)). Always present in the body, whether or not hosts are configured or up (FR-017). |
| `refresh`  | object   | Present only on `POST /refresh`; **unchanged** shape from slice 001.      |

**Relationships**:

```text
FirewallTelemetry (raw read) 1 ── 1 FirewallStatus (assembled) ── 1 FirewallSnapshot (/tmp file)
FirewallSnapshot 1 ── 0..1 (shared by any number of viewers within the TTL)
DashboardStatusResponse.firewall = latest FirewallStatus
```

No configuration entity exists in this slice (R-01): there is no model container, no `config.xml`
section, and no settings binding.

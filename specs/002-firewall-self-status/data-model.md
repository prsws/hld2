# Data Model: Firewall Self-Status

**Feature**: `002-firewall-self-status` | **Date**: 2026-10-16 | **Plan**: [plan.md](./plan.md)

This slice has **no storage domain at all** — there is no persistent configuration (thresholds
are fixed, [research R-01](./research.md#r-01-no-configuration-surface-fixed-thresholds)), no
`/tmp` file, no lock, no snapshot and no freshness machinery
([research R-02](./research.md#r-02-telemetry-read-in-process--no-configd-action-no-script-no-snapshot)).
All state is **per-request, in memory**: one read, one assembled object, both discarded when the
API response completes. FR-015 (ephemeral, no history, no daemon) is therefore satisfied by
construction. Slice 001's storage domains (its `config.xml` section and its `/tmp` snapshot) are
unchanged.

Two ephemeral objects make up this slice:

---

## 1. `FirewallTelemetry` (ephemeral value object, produced once per read)

The raw result of the in-process read performed by the pure `FirewallTelemetryReader`
([research R-03…R-07](./research.md)): bounded `/sbin/sysctl -i` calls parsed **by name** (one full
OID set, plus a short `kern.cp_time`-only call for the CPU delta, R-03/R-04), the OPNsense version
file, and PHP disk built-ins. Each field is a real value or `null`. For fields sourced from the
`sysctl` calls,
`null` means the OID was **absent** or the call **failed** — never an error (R-03). The only
special case is temperature, where `null` can mean "OID absent" or "read failed";
`cpu_temperature_present` disambiguates (R-05).

| Field                  | Type         | Notes                                                                 |
|------------------------|--------------|-----------------------------------------------------------------------|
| `hostname`             | string\|null | `gethostname()` (the `kern.hostname` OID in the `sysctl` output is a fallback). |
| `version`              | string\|null | `"CORE_PRODUCT CORE_VERSION"` parsed from the JSON file `/usr/local/opnsense/version/core`. |
| `last_boot`            | int\|null     | Unix time — the `sec` of `kern.boottime` (`{ sec = N, usec = N } <date>`). |
| `uptime_s`             | int\|null     | `now − last_boot`, whole seconds (computed by the reader).             |
| `load_avg_1m`          | float\|null | The three numbers of `vm.loadavg` (`{ a b c }`).                       |
| `load_avg_5m`          | float\|null | The warning source (assembler, FR-008).                               |
| `load_avg_15m`         | float\|null |                                                                      |
| `cpu_cores`            | int\|null     | `hw.ncpu` (logical). The load-warning basis.                          |
| `cp_time_before`       | 5 ints\|null  | First `kern.cp_time` sample: **user nice sys intr idle**.             |
| `cp_time_after`        | 5 ints\|null  | Second `kern.cp_time` sample, taken **~500 ms after the first** (R-04). |
| `cpu_usage_percent`    | float\|null | Delta of the two samples, `0..100` (R-04). `null` if either sample is absent or the call failed. |
| `memory_total`         | int\|null     | Bytes, `hw.physmem`.                                                  |
| `v_page_count`         | int\|null     | `vm.stats.vm.v_page_count` (pages).                                   |
| `v_free_count`         | int\|null     | `vm.stats.vm.v_free_count` (pages, reclaimable).                      |
| `v_inactive_count`     | int\|null     | `vm.stats.vm.v_inactive_count` (pages, reclaimable).                  |
| `v_laundry_count`      | int\|null     | `vm.stats.vm.v_laundry_count` (pages, reclaimable).                   |
| `pagesize`             | int\|null     | `hw.pagesize` (bytes).                                                |
| `memory_arc_bytes`     | int          | `kstat.zfs.misc.arcstats.size` **if the OID is present, else `0`** (absent on a UFS box). The only raw term normalized from *absence* to `0`, because ARC is an internal math term, not a displayed metric (R-03, R-04). |
| `disk_total`           | int\|null     | Bytes, `disk_total_space('/')`.                                       |
| `disk_free`            | int\|null     | Bytes, `disk_free_space('/')`.                                        |
| `cpu_temperature`      | float\|null | °C — the **max** of `dev.cpu.0.temperature` … `dev.cpu.(N−1).temperature` (e.g. `67.0C` → `67.0`); `null` if none present or unparseable. `hw.acpi.thermal.*` is never used (bogus 0.1 °C on this hardware, R-05). |
| `cpu_temperature_present` | bool       | `true` when at least one `dev.cpu.N.temperature` OID appears in the `sysctl` output (i.e. the kernel kld is loaded); `false` → value omitted entirely (FR-007). |

**Validation rules** (from FR-002…FR-008):

- Byte fields are non-negative integers; percentages are clamped to `0 ≤ x ≤ 100`.
- A `null` metric is never counted for any percentage or warning.

---

## 2. `FirewallStatus` (ephemeral, the display-ready object)

Produced per request by the pure `FirewallStatusAssembler` from a `FirewallTelemetry`
([research R-08](./research.md#r-08-the-firewall-api-object-and-the-two-pure-classes)). This is
the object the API returns in the `firewall` key and the Volt view renders.

| Field               | Type        | Derivation / notes                                                   |
|---------------------|-------------|----------------------------------------------------------------------|
| `hostname`          | string\|null | From telemetry; `null` → "unavailable".                             |
| `version`           | string\|null | From telemetry; `null` → "unavailable".                             |
| `uptime_s`          | int\|null     | From telemetry; the view renders it as `Xd Xh Xm Xs`.               |
| `last_boot`         | string\|null | ISO 8601 UTC of the last boot; the view renders it in browser-local time. |
| `load_avg_1m`       | float\|null | Displayed with 2 decimals.                                           |
| `load_avg_5m`       | float\|null | Displayed with 2 decimals.                                           |
| `load_avg_15m`      | float\|null | Displayed with 2 decimals.                                           |
| `cpu_cores`         | int\|null     |                                                                      |
| `cpu_usage_percent` | float\|null | From telemetry; displayed with 1 decimal and a `%`.                 |
| `memory_used`       | int\|null     | Derived bytes (FR-005). `null` → "unavailable".                     |
| `memory_total`      | int\|null     | Bytes, `hw.physmem`. The view formats used/total with the percentage. |
| `memory_used_percent` | float\|null | Derived; drives the memory warning (FR-008).                        |
| `disk_used`         | int\|null     | Derived bytes. `null` → "unavailable".                              |
| `disk_total`        | int\|null     | Bytes.                                                              |
| `disk_used_percent` | float\|null | Derived; drives the disk warning (FR-008).                          |
| `cpu_temperature`   | float\|null | **Omitted from the object** (not `null`) when `cpu_temperature_present` is `false` (FR-007). Present only when the hardware reports it. |
| `cpu_temperature_present` | bool     | Lets the view omit the row when `false`.                            |
| `warnings`          | list | Names of metrics over threshold: subset of `{ "memory", "disk", "load" }` (FR-008). Never contains a metric whose value is `null` (Edge Case: highlight with unavailable). |
| `all_unavailable`   | bool    | `true` only when **every** displayed metric is `null` (FR-013) — drives the single discreet notice. |
| `collected_at`      | string\|null | ISO 8601 UTC of the read; rendered in browser-local time.            |

**Derived values (pure math in the assembler)**:

```text
memory_used        = (v_page_count − v_free_count − v_inactive_count − v_laundry_count)
                     × pagesize − memory_arc_bytes
memory_used_percent = (memory_used / memory_total) × 100        (clamped 0..100)
disk_used          = disk_total − disk_free
disk_used_percent  = (disk_used / disk_total) × 100             (clamped 0..100)
```

- All five inputs of `memory_used` (`memory_total`, the four page counts, `pagesize`) must be
  present, else `memory_used` is `null` (unavailable) rather than guessed. `memory_arc_bytes` is
  **never** `null` — it is `0` when the ARC OID is absent (UFS), so the formula is defined on both
  filesystem types: on UFS it reduces to the conventional "used"; a large ZFS ARC is subtracted
  and therefore *reclaimable*, never counting toward the 90% warning (Edge Case: large ZFS cache,
  FR-005).
- `cpu_usage_percent` comes from the reader (the two `kern.cp_time` samples, R-04); the assembler
  only clamps and passes it through.

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
as "unavailable", a warning, or a placeholder. When the kld is present but a reading is missing,
the value is `null` and renders "unavailable".

---

## 3. `DashboardStatusResponse` (the extended slice 001 body)

The existing slice 001 status body gains exactly one new top-level key. The existing `summary` and
`groups` keys are **unchanged** (this slice does not modify host-checking behaviour), and the
`refresh` object keeps its slice 001 meaning (it describes the `check_hosts` run).

| Key        | Type     | Notes                                                                     |
|------------|----------|---------------------------------------------------------------------------|
| `summary`  | object   | **Unchanged** from slice 001 (data-model §5 there).                       |
| `groups`   | list     | **Unchanged** from slice 001.                                             |
| `firewall` | object   | **New** — the `FirewallStatus` object from §2 (see [contracts/firewall-api.md](./contracts/firewall-api.md)). Always present in the body, whether or not hosts are configured or up (FR-017). |
| `refresh`  | object   | Present only on `POST /refresh`; **unchanged** shape and semantics from slice 001. |

**Relationships** (all in memory; nothing is stored or shared between requests):

```text
FirewallTelemetry (in memory, one per read)  1 ── 1  FirewallStatus (in memory, one per request)
FirewallStatus  1 ── 0..n  API responses   (recomputed on every request; no snapshot, no file)
DashboardStatusResponse.firewall = the FirewallStatus assembled for that request
```

No configuration entity exists in this slice (R-01). The first draft's `FirewallSnapshot`
(`/tmp/casadelta_dashboard_firewall.json` plus its lock) is **removed** with the configd action
(R-02) — it has no successor in this design.

# Research: Firewall Self-Status

**Feature**: `002-firewall-self-status` | **Date**: 2026-10-16 | **Plan**: [plan.md](./plan.md)

This document resolves every open item from the plan's Technical Context. Each entry records the
decision, the rationale, and the alternatives considered. Items tagged **VM-verify** are design
assumptions about OPNsense 26.7 / FreeBSD 15 internals. They MUST be confirmed on the test VM
during the first implementation task (Principle VIII). If one turns out wrong, the stated
fallback applies. This slice extends the slice 001 package; it inherits that slice's packaging
(R-01), naming (R-02), language (R-03), configd pattern (R-06) and snapshot pattern (R-07).

---

## R-01 No configuration surface (fixed thresholds)

- **Decision**: This slice persists nothing. Thresholds (memory > 90%, disk > 90%, 5-min load >
  core count) are fixed constants in the assembler (FR-009); there is no model container, no
  `config.xml` section, no settings page and no new privilege (FR-014, FR-009). The only new state
  is one ephemeral `/tmp` snapshot.
- **Rationale**: The spec mandates fixed thresholds and explicitly defers configurability. Adding a
  model container for values that cannot change would be dead configuration (Principle VI) and
  would pull Principle IV (backup/restore, secrets) into a slice that holds none.
- **Alternatives considered**: A `thresholds` container in the existing `PRSWS\CasaDelta` model
  (rejected: the spec forbids configuring them in this slice; defer to a later slice that actually
  needs them).

## R-02 Telemetry source: the firewall's own OS, via a second configd action

- **Decision**: A new configd action `casadelta check_firewall [force]`
  (`service/conf/actions.d/actions_casadelta.conf`) runs
  `/usr/local/opnsense/scripts/CasaDelta/firewall_status.php`. The script reads the appliance's own
  OS with short-lived local commands (`sysctl`, `df`, `top`/`vmstat`, `sensors`) and PHP
  built-ins, writes an ephemeral snapshot, and prints one JSON document. No probes to any other
  host (FR-011). No daemon: the script exits when the run ends (FR-015, Principle II).
- **Rationale**: Mirrors slice 001's `check_hosts` (R-06 there): configd already runs on every
  OPNsense install, so the plugin adds no process, and reads happen only while somebody requests
  status. Every read is a read-only local command, so the firewall's networking configuration is
  never touched (Principle II).
- **Alternatives considered**: Reading metrics directly in the API controller (rejected: it would
  put a ~2 s read set in the request path for every viewer and could not be deduplicated). A
  polling daemon (prohibited, FR-015). An SNMP/agent export (adds a dependency, violates FR-015).

## R-03 Shared snapshot, deduplication and freshness (reuse of the slice 001 rules)

- **Decision**: One shared snapshot file `/tmp/casadelta_dashboard_firewall.json`, written
  atomically (temp file + `rename()`) by `firewall_status.php` under `flock(LOCK_EX)` on
  `/tmp/casadelta_dashboard_firewall.lock`. It reuses the **existing** `SnapshotFreshness` pure
  class (55 s TTL, 5 s cooldown, schema check) with a **fixed sentinel fingerprint**
  (`SnapshotFreshness::FIREWALL_FINGERPRINT`), because the firewall's identity does not depend on
  the host inventory — freshness is purely time-based. The status API reads the snapshot directly
  (fast path, no configd call) and only invokes the action when it is stale or the user forces a
  refresh. Double-checked locking (re-read after acquiring the lock) is reused verbatim.
- **Rationale**: One read per ~60 s window for any number of viewers (SC-005, FR-010), with no
  daemon. `/tmp` is outside every web-served path and is cleared on reboot; the de-install hook
  removes the files (R-12). A separate file and lock from `check_hosts` keeps the two checks
  independent so they can run in parallel (R-09).
- **Alternatives considered**: Reusing the hosts snapshot file (rejected: the two checks have
  different lifecycles and would block each other on the lock). APCu (not guaranteed enabled).
  Storing telemetry in `config.xml` (rejected: it would write firewall config on every read and
  pollute backups, violating FR-015 and Principle IV).

## R-04 Memory "used" = memory not available to applications (FR-005)

- **Decision**: `memory_used` is computed as
  `total − (free + inactive + laundry + arc)`, i.e. it counts only memory that applications
  cannot reclaim — `active` + `wired` (+ any non-reclaimable cache). `memory_total` is physical
  RAM. The 90% warning threshold (FR-008) is evaluated on **this** definition, so a healthy box
  with a large ZFS ARC cache (reclaimable) never trips a false memory warning (Edge Case: large
  ZFS cache). `memory_used_percent = memory_used / memory_total × 100`.
  - **VM-verify**: the exact FreeBSD sources for each term. Candidates: `sysctl hw.physmem` for
    total; `vm.stats.sys.vfree`/`vactive`/`vinactive`/`vwired` (pages) and `vm.stats.vm.vlaundry`
    for the states, and the ARC size via `zfs_arc` sysctls or `vm.stats` — times the page size
    (`vm.pagesize`). Fallback: parse `top -b -n 1`'s `Mem:` (Active/Inact/Wired/Free) and `ARC:`
    lines, which the OS already reconciles. Either way the *definition* is fixed; only the source
    of the four reclaimable/free terms is confirmed on the VM.
- **Rationale**: The spec is explicit that reclaimable memory must not count as used. Computing it
  as `total − reclaimable` is robust to whether the box runs ZFS (ARC) or not — on a non-ZFS box
  the arc term is simply zero and the formula reduces to a conventional "used".
- **Alternatives considered**: `top`'s single "used" figure (rejected: it does not cleanly exclude
  ARC and would produce the false-positive the spec forbids). Counting `free` only (rejected:
  that over-counts used by the reclaimable pools).

## R-05 Current CPU usage (the slowest read)

- **Decision**: `cpu_usage_percent = 100 − idle%` from a single 1-second sample, read from
  `top -b -n 1` (its `CPU:` line reports Mod/User/Nice/Sys/Idle) or, as a fallback, the second
  line of `vmstat 1 2` (average over one second). `cpu_cores` is `sysctl hw.ncpu` (logical
  cores) and is the basis for the load warning (R-07). This read takes ~1 s and is the dominant
  cost of the run; it is bounded by a 3 s per-read deadline (R-10).
  - **VM-verify**: the exact `top -b -n 1` / `vmstat 1 2` column layout on FreeBSD 15 and that
    `hw.ncpu` reports logical (not physical) cores. Fallback: use `vmstat` and compute idle from
    the `id` column.
- **Rationale**: "Current" usage needs a window; a 1 s sample is the cheapest that is meaningful.
  It is local, bounded and infrequent (~1/60 s), so it stays within the "no noticeable delay"
  budget (SC-005) — and because it runs in parallel with the host check (R-09) it does not extend
  the host refresh at all.
- **Alternatives considered**: A longer (5 s) sample for stability (rejected: it would dominate the
  read set). Per-core percentages (out of scope: the spec asks for a single current usage).

## R-06 CPU temperature is opportunistic and never a dependency (FR-007)

- **Decision**: Read CPU temperature with `sensors` (the `coretemp`/`k8temp`-style readers) if the
  reader is present and reports a CPU value. The plugin **never adds `sensors`/`coretemp` as a
  package dependency** (Principle VII). If the binary is absent or reports no CPU temperature, the
  value is **omitted entirely** — it is not shown as "unavailable", a warning, or a placeholder
  (FR-007, Edge Case: no sensor). This is distinct from a *transient* read failure, which shows as
  "unavailable" (FR-012).
  - **VM-verify**: whether `sensors` ships on the test VM and the exact output format for the CPU
    chip; the mapping of a chip reading to a single `cpu_temperature` (max of core readings).
- **Rationale**: Hardware differs (VMs and minimal platforms report no thermal data). Omitting when
  absent — rather than erroring — is what makes the panel trustworthy on any appliance
  (Principle III). Not declaring the reader as a dependency means the feature is spec-complete
  regardless of what the appliance happens to have.
- **Alternatives considered**: Adding `sysutils/coretemp` + `sysutils/...` to the package
  `RUN_DEPENDS` (rejected: it would force a package dependency for a value the spec says may simply
  not exist, and it can fail on VMs). Reading `/dev/coretemp0` raw (rejected: needs a driver and
  is more code for the same opportunistic result).

## R-07 Load average and its warning threshold (FR-008)

- **Decision**: `load_avg_1m/5m/15m` from `sysctl vm.loadavg` (or `getloadavg()`). The warning
  compares the **5-minute** average to `cpu_cores` (`hw.ncpu`): warn when `load_avg_5m >
  cpu_cores` (FR-008). 1-min and 15-min are displayed but not warning sources.
- **Rationale**: The spec names exactly these three values and one threshold. `vm.loadavg` is a
  base sysctl (no dependency) and reads in microseconds.
- **Alternatives considered**: Warning on 1-min (rejected: too spiky, false positives). A
  configurable threshold (out of scope, FR-009).

## R-08 Hostname, OPNsense version, uptime and last boot (FR-002, FR-003)

- **Decision**:
  - `hostname`: `gethostname()` (a local syscall — cheapest possible source, no subprocess).
  - `version`: the installed OPNsense release string. **VM-verify** the exact source; candidates,
    in order: the `opnsense/version` file under the OPNsense tree, the OPNsense core version
    constant, or `pkg info -q opnsense-core`. Fallback if unreadable: the value degrades to
    "unavailable" (FR-012) — it never breaks the panel.
  - `uptime` and `last_boot`: `sysctl kern.boottime` gives the boot timestamp; `last_boot` is that
    timestamp (rendered in the viewer's local time, FR-003) and `uptime = now − last_boot`.
  - **VM-verify**: the `kern.boottime` output shape and the version source path.
- **Rationale**: All are local reads with no external dependency (Principle III). Uptime is derived
  from the system clock at read time; a wall-clock change is simply reflected next cycle with no
  spurious warning (Edge Case: clock changes).
- **Alternatives considered**: `proc uptime` / `who -b` (rejected: more fragile parsing for the
  same data `kern.boottime` gives directly).

## R-09 API runs the two checks in parallel (the key latency decision)

- **Decision**: In `Api\StatusController::buildStatus()`, when a check is required (snapshot stale
  or forced), the controller launches the two configd actions **concurrently** through a bounded
  `proc_open` pool: `configctl casadelta check_hosts [force]` and `configctl casadelta check_firewall
  [force]`. Each side has its own wall-clock deadline — the hosts side keeps slice 001's 30 s
  budget; the firewall side gets a 15 s deadline (its own run cap is 10 s, R-10). The response
  waits for both. A side that only needs to be refreshed (the other's snapshot is still fresh)
  runs alone. The firewall read set (~2 s) therefore adds **no** serial delay to the host refresh:
  the hosts side remains the long pole, and the firewall result is ready within it (SC-005).
  - **VM-verify**: the exact `configctl` path and that two concurrent `configctl` invocations from
    `proc_open` (argv array, no shell) both complete and return parseable JSON.
- **Rationale**: Slice 001 ran `check_hosts` alone through `Backend::configdRun` (synchronous).
  Serializing a second ~2 s read behind it would add it to every refresh; running them in parallel
  keeps the total bounded by the slower side. Both sides are still independently deduplicated by
  their own snapshots (R-03), so a fresh pair costs zero process launches.
- **Alternatives considered**: Serial calls (rejected: ~2 s added per refresh, against SC-005).
  Merging both checks into one configd action/script (rejected: it would couple the two lifecycles
  and force a re-probe of hosts whenever only the firewall is stale — and vice versa). Two
  separate long-running pollers (prohibited, FR-015).

## R-10 Per-value timeouts, run cap and degradation mapping (FR-016)

- **Decision**: Every read in `firewall_status.php` is a local command run through `proc_open`
  (argv array, no shell) with a **strict per-value timeout**; the whole run is hard-capped at
  10 s. Budget per value: hostname/version/uptime/load (sub-second, 2 s deadline), cpu (1 s sample,
  3 s deadline), memory (2 s), disk (`df /`, 2 s), temperature (`sensors`, 2 s). At the run cap the
  script SIGKILLs any running reader and finalizes. A value whose read timed out or errored is
  marked **unavailable** for exactly that value; the rest of the snapshot keeps its real data
  (FR-012). If *all* values are unavailable, `all_unavailable = true` drives the single discreet
  notice (FR-013).
- **Rationale**: An unresponsive reader (a hung `sensors`, a wedged `df`) must never stall the
  shared ~60 s cycle or the host display (FR-016, Principle II). Bounding each read and the whole
  run guarantees the firewall side fits its 15 s API deadline (R-09) and the panel always renders.
- **Alternatives considered**: One global timeout with no per-value bound (rejected: one hang would
  blank every value). Unbounded reads (violates FR-016).

## R-11 The `firewall` API object and the `FirewallStatusAssembler`

- **Decision**: A new **pure** class `PRSWS\CasaDelta\Lib\FirewallStatusAssembler` (framework-free,
  unit-testable) takes the raw telemetry and produces the display-ready `firewall` object: it
  applies the R-04 memory definition, the R-07 load threshold and the 90% disk/memory rules to
  compute `warnings` (a set of `memory` | `disk` | `load`), sets per-metric `available` flags
  (and the special `cpu_temperature_present` that distinguishes *omitted* from *unreadable*),
  formats `collected_at`, and sets `all_unavailable`. The same class is used by both the configd
  script (to build the snapshot's `firewall` payload) and the API controller (to build the
  response object). See [contracts/firewall-api.md](./contracts/firewall-api.md).
  - A second **pure** class `PRSWS\CasaDelta\Lib\FirewallTelemetry` holds the bounded local-read
    helpers and the per-value output parsers (hostname/version/boot/load/cpu/memory/disk/sensors),
    mirroring slice 001's `PingResultParser`. Its parsers are the unit-test surface.
- **Rationale**: Keeping the thresholds, the memory definition and the availability logic in a pure
  class means the warning/no-false-positive behaviour (SC-002) and the degradation behaviour
  (FR-012/FR-013) are deterministic and covered by PHPUnit on the dev machine — no appliance needed
  (Principle VIII). Both consumers share one implementation, so the panel and the snapshot can't
  drift.
- **Alternatives considered**: Computing warnings in the Volt view (rejected: it would put policy
  in the UI and be untestable without a browser). Duplicating the logic in the script and the API
  (rejected: drift).

## R-12 Access, packaging and clean lifecycle (FR-014, Principle I)

- **Decision**: The `firewall` object is served from the **existing** `/ui/casadelta/status` page
  and the **existing** `/api/casadelta/status/get` and `/refresh` endpoints, already gated by
  `page-casadelta-status` (slice 001 [acl-menu.md](../001-host-status-monitoring/contracts/acl-menu.md)).
  No new privilege, no new URL, no new menu entry — the panel inherits the existing access matrix
  exactly (FR-014, Principle V). Packaging deltas: `Makefile` `PLUGIN_VERSION` 0.1.0 → 0.2.0; a
  0.2.0 entry in `pkg-descr` (still "Requires OPNsense 26.7 or later"); `+POST_DEINSTALL.post`
  extended to also remove `/tmp/casadelta_dashboard_firewall*` (Principle I).
- **Rationale**: Reusing the slice 001 privilege and URL means a view-only account sees the panel
  and nobody else does, with zero new ACL surface (SC-006). The version bump and changelog entry
  satisfy the Technical Constraints for a new release.
- **Alternatives considered**: A dedicated `/ui/casadelta/firewall` page + privilege (rejected:
  the spec places the panel on the existing page and forbids a new privilege/URL).

## R-13 Testing strategy (Principle VIII)

- **Decision**: Three layers, all non-production (mirrors slice 001 R-15):
  1. **Unit (dev machine, PHPUnit)**: `FirewallTelemetry` parser matrix (each raw reader's output
     → typed value or "unavailable") and `FirewallStatusAssembler` (warning rules, the R-04 memory
     definition, availability, `cpu_temperature` omitted-vs-unreadable, `all_unavailable`).
  2. **Integration (test VM)**: a `tests/integration/firewall_api.sh` curl/jq contract test for the
     `firewall` object shape, the warning rules, and the parallel-refresh/cooldown behaviour.
  3. **Manual checklist (test VM)**: in [quickstart.md](./quickstart.md) — the full panel, each
     threshold crossing/clearing, a no-temperature VM, all-unavailable, and the zero-host /
     all-hosts-down cases.
- **Rationale**: The pure classes run on the dev machine; the MVC controller, the configd script
  and the UI need the appliance runtime, so those are validated on the test VM.

---

All Technical Context unknowns are resolved. No `NEEDS CLARIFICATION` items remain.

## VM-verify results (to be filled on the test VM, OPNsense 26.7 / FreeBSD 15)

- **R-04 (memory terms)**: confirm the exact sysctls for free/active/inactive/wired/laundry/arc and
  `vm.pagesize`, or the `top -b -n 1` `Mem:`/`ARC:` line layout.
- **R-05 (cpu)**: confirm `top -b -n 1` `CPU:` layout (or `vmstat 1 2` idle column) and that
  `hw.ncpu` is logical cores.
- **R-06 (temperature)**: confirm whether `sensors` is present and its CPU output shape.
- **R-08 (version/boot)**: confirm the OPNsense version source path and `kern.boottime` shape.
- **R-09 (parallel configctl)**: confirm the `configctl` path and that two concurrent invocations
  return parseable JSON.
- **R-10 (run behaviour)**: confirm the 10 s run cap and per-read deadlines behave as budgeted.

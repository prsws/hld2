# Research: Firewall Self-Status

**Feature**: `002-firewall-self-status` | **Date**: 2026-10-16 | **Plan**: [plan.md](./plan.md)

This document resolves every open item from the plan's Technical Context. Each entry records the
decision, the rationale, and the alternatives considered. Items tagged **VM-verify** are design
assumptions about OPNsense 26.7 / FreeBSD 15 internals that are not yet confirmed; they MUST be
checked on the test VM during the first implementation task (Principle VIII). If one turns out
wrong, the stated fallback applies. This slice extends the slice 001 package; it inherits that
slice's packaging, naming, language baseline and ACL (R-10).

---

## R-01 No configuration surface (fixed thresholds)

- **Decision**: This slice persists nothing. Thresholds (memory > 90%, disk > 90%, 5-min load >
  core count) are fixed constants in the assembler (FR-009); there is no model container, no
  `config.xml` section, no settings page and no new privilege (FR-014, FR-009). The slice creates
  **no files at all** — no `/tmp` snapshot, no lock, no configd state (R-02).
- **Rationale**: The spec mandates fixed thresholds and explicitly defers configurability. Adding a
  model container for values that cannot change would be dead configuration (Principle VI) and
  would pull Principle IV (backup/restore, secrets) into a slice that holds none.
- **Alternatives considered**: A `thresholds` container in the existing `PRSWS\CasaDelta` model
  (rejected: the spec forbids configuring them in this slice; defer to a later slice that actually
  needs them).

## R-02 Telemetry read in-process — no configd action, no script, no snapshot

- **Decision**: A new **pure** library class `PRSWS\CasaDelta\Lib\FirewallTelemetryReader` reads
  the appliance's own OS **in-process**, and `Api\StatusController::buildStatus()` calls it on
  every response: one bounded `/sbin/sysctl -i` call for the full OID set (argv array, no shell,
  strict 2 s deadline, R-03) and a short `kern.cp_time`-only call for the CPU delta (R-04),
  plus PHP built-ins — `gethostname()`, a `file_get_contents()` read of
  `/usr/local/opnsense/version/core`, and `disk_total_space('/')` / `disk_free_space('/')`
  (R-04…R-07). There is **no new configd action**, no backend script, no `/tmp` snapshot, no lock,
  no freshness/deduplication machinery, and no parallel `proc_open` pool. The first draft's
  `casadelta check_firewall` action and `scripts/CasaDelta/firewall_status.php` are **removed from
  this design**. The slice 001 `check_hosts` path (configd action, script, `/tmp` snapshot,
  `SnapshotFreshness` reuse, `StatusAssembler`) and the `check_hosts` part of
  `Api\StatusController::runCheck` are **exactly as they are** — not touched.
- **Rationale**: The telemetry read is cheap (at most two ~2 s bounded calls plus in-process
  reads, R-06)
  and read-only (Principle II), so there is no need for the deduplication machinery that slice 001
  needed for a long host probe run: a snapshot exists to make one expensive shared check serve many
  viewers, but here the read is a single cheap call, so a snapshot would only add files, a lock, a
  freshness schema and a configd entry to clean up and maintain. Reading in-process keeps all
  logic in the unit-testable `Lib/` classes and leaves slice 001's host path byte-for-byte intact.
- **Alternatives considered**: The first draft's configd action + shared snapshot + parallel
  `proc_open` pool (rejected: the added surface — configd entry, script, two `/tmp` files, lock,
  `SnapshotFreshness` sentinel, parallel pool — is not justified for one bounded in-process read,
  and it forced a 15 s parallel deadline and cross-check coupling into the API). A polling daemon
  (prohibited, FR-015). An SNMP/agent export (adds a dependency, violates FR-015).

## R-03 The `sysctl -i` calls and their output (verified on the test box)

- **Decision**: At most **two** bounded launches per read, **never in parallel**:
  1. one call with the full OID set:

  ```text
  /sbin/sysctl -i kern.hostname kern.boottime vm.loadavg hw.ncpu kern.cp_time hw.physmem
               hw.pagesize vm.stats.vm.v_page_count vm.stats.vm.v_free_count
               vm.stats.vm.v_inactive_count vm.stats.vm.v_laundry_count
               kstat.zfs.misc.arcstats.size dev.cpu.0.temperature … dev.cpu.(N−1).temperature
  ```

  where `N = hw.ncpu` (learned from the same output), run through `proc_open` with an argv array,
  no shell, and a **strict 2 s deadline** (then SIGKILL; FR-016, SC-005);
  2. after a **~500 ms** sleep, one short call requesting `kern.cp_time` only, for the second
  CPU-delta sample (R-04), with the same argv/no-shell/2 s deadline form. No other process is
  ever launched. The output of each call is parsed
  **by name**: each line is `name: value`. `-i` ignores unknown OIDs, so on the verified UFS test
  box `kstat.zfs.misc.arcstats.size` is simply absent from the output — **a missing OID means that
  value is absent/unavailable; it is never an error** (R-04, R-05). If the call itself exits
  non-zero (or times out), every value sourced from it is `null` in one step (R-06, FR-013).
  The OID list is built from a fixed array plus a `dev.cpu.<i>.temperature` entry per core — no
  user or network input enters argv, so command injection is impossible.
  - **VM-verify**: the exact `-i` behaviour on a **ZFS** install (all OIDs, including the ARC
    one, present; exit 0) — the test box is UFS, so the ZFS path is unit-test-only for now (R-12).
    Fallback if `-i` on this FreeBSD differs: the parser is tolerant by design — unknown/skipped
    lines are ignored, so only a completely missing OID list would matter.
- **Rationale**: One bounded call per read set is the cheapest correct way to get every
  sysctl-sourced value:
  no per-value process (the first draft's per-value `proc_open` readers each paid process-creation
  cost and needed per-value deadlines; the second, short `kern.cp_time`-only call is the only
  additional launch and exists solely for the delta), no `sensors`/`top`/`vmstat`/`df` at all, and the `-i`
  missing-OID semantics map directly onto FR-007/FR-012 (absent → null or omitted, never error).
- **Alternatives considered**: Per-value `sysctl -n` calls (rejected: N processes for N values,
  more deadlines to enforce, for the same data). `vmstat`/`top`/`sensors` (rejected: they are
  unavailable on a VM or a minimal install; `dev.cpu.N.temperature` via `coretemp` is the base
  source and the base `sysctl` binary is always present). Reusing slice 001's snapshot mechanism to
  share one read across viewers (rejected with the whole snapshot concept, R-02).

## R-04 Parsed value formats, the CPU delta, and memory math

- **Decision** (verified on the test box, OPNsense 26.7.7, UFS root):
  - `kern.boottime` → `{ sec = N, usec = N } <date string>`; take **`sec`** as the boot Unix time.
  - `vm.loadavg` → `{ a b c }`; the three numbers are the 1/5/15-minute averages (R-08).
  - `kern.cp_time` → five tick counters in the order **user nice sys intr idle** (verified).
  - CPU usage: take two `kern.cp_time` samples **~500 ms apart** (the reader issues the single
    `sysctl -i` call, sleeps ~500 ms, then a second `sysctl -i` call for `kern.cp_time` only), and
    compute `usage = 100 × (Δuser + Δnice + Δsys + Δintr) / Δtotal_ticks`, clamped to `0..100`
    (R-04, FR-004). Two calls for the delta is still one *kind* of bounded call; each side keeps
    the 2 s deadline, so the CPU value is the slowest one at ~3 s worst case (R-06).
  - Memory (FR-005, verified): `memory_total = hw.physmem`;
    `memory_used = (v_page_count − v_free_count − v_inactive_count − v_laundry_count) ×
    hw.pagesize − ARC`, where `ARC = kstat.zfs.misc.arcstats.size` **if the OID is present, else 0**
    (absent on the verified UFS box). Free/inactive/laundry are all *reclaimable*, so `used` is
    memory not available to applications; `memory_used_percent = memory_used / memory_total × 100`,
    clamped to `0..100`. All seven OIDs are parsed from the same single `sysctl -i` output, so
    they are atomic with respect to each other — no torn mixed-source computation (R-03).
  - The 90% memory warning (FR-008) is evaluated on **this** definition, so a healthy box with a
    large ZFS ARC (reclaimable, subtracted) never trips a false warning (Edge Case: large ZFS
    cache). **VM-verify**: the ZFS install behaviour (ARC present, non-zero) — unit-tested for
    now (R-12).
- **Rationale**: Reading the whole memory set from one `sysctl -i` output removes the first draft's
  multi-source consistency problem (its `top -b -n 1` `Mem:`/`ARC:` fallback mixed processes and
  formats) and makes the definition a single pure formula over integers — trivially unit-testable
  for both the ZFS (ARC present) and UFS (ARC absent) cases.
- **Alternatives considered**: `top`'s single "used" figure (rejected: does not cleanly exclude ARC
  and is unavailable in every form this design allows; the spec forbids `top`). Counting `free`
  only (rejected: over-counts used by the reclaimable pools, exactly the false-positive the spec's
  ZFS edge case forbids).

## R-05 CPU temperature source (verified on the test box)

- **Decision**: `cpu_temperature = max(dev.cpu.0.temperature … dev.cpu.(N−1).temperature)` parsed
  from the same `sysctl -i` output — values like `67.0C`. `dev.cpu.N.temperature` exists when the
  `coretemp` (or `k8temp`) kld is loaded, which it **is on the test box**; on the N6005 baseline it
  reports real readings, so the row shows on the test box (M2 in [quickstart.md](./quickstart.md)
  asserts the real reading). `hw.acpi.thermal.*` is **not** used — it reports a bogus 0.1 °C on
  this hardware. If **no** `dev.cpu.N.temperature` OID is present in the output (kld not loaded),
  `cpu_temperature_present = false` and the value is **omitted entirely** — not "unavailable", not
  a warning, not a placeholder (FR-007, Edge Case: no sensor).
- **Rationale**: A per-core OID that the kernel driver exposes directly, read through the same
  call already being made, with absence semantics that map exactly onto FR-007 — and a verified real
  value on the baseline hardware, so the feature is demoable end to end.
- **Alternatives considered**: `hw.acpi.thermal.*` (rejected: measured bogus 0.1 °C on this
  hardware). `sensors` (rejected: not guaranteed present; the kernel kld + `dev.cpu.N.temperature`
  is the base source it reads anyway). `/dev/coretemp0` raw reads (rejected: same data, more code
  and a driver dependency).

## R-06 Bound, deadline, and degradation mapping (FR-016)

- **Decision**: The read has exactly two external components: the bounded `sysctl -i` call(s) with
  a strict **2 s deadline** each (R-03, R-04), and a **~500 ms sample gap** between the two
  `kern.cp_time` samples. No other process is ever launched (version = file read, hostname =
  syscall, disk = PHP stat built-ins — all in-process, bounded by PHP's own limits). If a `sysctl`
  call times out or exits non-zero, every value sourced from it is `null` in one step; a value
  whose individual OID is missing from a successful call is `null` for exactly that value
  (temperature is the special "omit entirely" case, R-05). `null` → "unavailable" in the view
  (FR-012); **all** values `null` → `all_unavailable = true` → the single discreet notice
  (FR-013). Worst-case added latency per response ≈ 3 s (two 2 s-deadline calls + the 500 ms gap,
  serial worst case; the normal path is the first call's runtime plus the gap), bounded, so it
  adds no unbounded or parallel-process cost to the refresh (SC-005).
- **Rationale**: FR-016 requires that an unresponsive source cannot stall the shared refresh cycle.
  One strict deadline on each launched `sysctl` call, plus in-process reads that cannot hang, is the
  minimal mechanism that guarantees it — no per-value deadlines, no run cap, no SIGKILL choreography
  beyond the two serial deadlines.
- **Alternatives considered**: Per-value timeouts plus a whole-run cap (the first draft's model;
  rejected: it was sized for a multi-process read set that no longer exists). No deadline (violates
  FR-016).

## R-07 Version and disk sources (verified on the test box)

- **Decision**:
  - `version`: parse `/usr/local/opnsense/version/core` — a small JSON file holding
    `CORE_PRODUCT` and `CORE_VERSION` among its keys — with `file_get_contents()` + `json_decode()`;
    the display value is `"CORE_PRODUCT CORE_VERSION"` (e.g. `26.7.7`). Unreadable or invalid file
    → `version = null` → "unavailable" (FR-012). No process is launched for it.
  - `disk_used` / `disk_total` for the root disk: `disk_total_space('/')` and
    `disk_free_space('/')` (PHP stat built-ins; `disk_used = disk_total − disk_free`);
    `disk_used_percent = disk_used / disk_total × 100`, clamped to `0..100`. No `df` process.
  - `hostname`: `gethostname()` (syscall — the `kern.hostname` OID is also in the `sysctl` output;
    the reader prefers the syscall and treats the OID as a fallback).
  - `uptime_s = now − last_boot` (from `kern.boottime` `sec`, R-04); `last_boot` is that Unix time
    rendered in the viewer's local time (FR-003). A wall-clock change is simply reflected next
    cycle with no spurious warning (Edge Case: clock changes).
- **Rationale**: All are local, in-process reads with no external dependency (Principle III) and no
  process-creation cost, leaving `sysctl` as the only launched binary in the whole read.
- **Alternatives considered**: `pkg info -q opnsense-core` (rejected: a process for a value a file
  already holds). `df /` parsing (rejected: a process and a locale-sensitive format for what PHP
  returns natively).

## R-08 The `firewall` API object and the two pure classes

- **Decision**:
  - `PRSWS\CasaDelta\Lib\FirewallTelemetryReader` (**pure**): owns the read — it runs the bounded
    `sysctl -i` calls (full OID set; the short `kern.cp_time`-only delta call), parses `name: value` lines **by name** (R-03), derives the CPU delta
    (R-04), the version string and the disk figures (R-07), and returns one `FirewallTelemetry`
    value object (data-model §1) whose fields are real values or `null`. The `sysctl` invocation
    (argv array + deadline) and the sample gap are injectable seams so the whole read is
    unit-testable on the dev machine with captured real `sysctl` outputs.
  - `PRSWS\CasaDelta\Lib\FirewallStatusAssembler` (**pure**): takes a `FirewallTelemetry` and
    produces the display-ready `FirewallStatus` object (data-model §2): the FR-005 memory
    definition, the FR-008 warning rules (`memory` / `disk` / `load`, never on a `null` value),
    `cpu_temperature_present` (omitted vs unreadable, FR-007), `all_unavailable` (FR-013) and the
    `collected_at` stamp. See [contracts/firewall-api.md](./contracts/firewall-api.md).
- **Rationale**: Keeping the parse + math + policy in two framework-free pure classes makes the
  warning/no-false-positive behaviour (SC-002), the ZFS-vs-UFS memory behaviour (Edge Case: large
  ZFS cache) and the degradation behaviour (FR-012/FR-013) deterministic and covered by PHPUnit on
  the dev machine — no appliance needed (Principle VIII) — and gives the controller a single
  two-line integration point.
- **Alternatives considered**: Computing warnings in the Volt view (rejected: policy in the UI,
  untestable without a browser). Parsing in the controller (rejected: it would couple I/O and
  policy and be untestable without the OPNsense runtime).

## R-09 Testing strategy (Principle VIII)

- **Decision**: Three layers, all non-production:
  1. **Unit (dev machine, PHPUnit)**:
     - `FirewallTelemetryReaderTest`: the `sysctl -i` output parser against captured real output —
       every OID parsed by name, a **missing OID** (`arcstats.size` on the verified UFS box) →
       absent value, a non-zero exit / timeout → all-sysctls-`null`, malformed lines ignored;
       `kern.boottime` `{ sec = … }`, `vm.loadavg` `{ a b c }`, five-counter `kern.cp_time`,
       `67.0C` temperatures, the version JSON, version file missing, disk built-ins.
     - `FirewallStatusAssemblerTest`: CPU-delta math, the FR-005 memory formula with a **ZFS case
       (ARC present)** and a **UFS case (ARC absent)**, the 90% memory/disk and 5-min-load
       thresholds (crossing and clearing, independently), no-warning-when-`null`, temperature
       omitted-vs-unavailable (FR-007), `all_unavailable` (FR-013).
  2. **Integration (test VM)**: `tests/integration/firewall_api.sh` — `curl`/`jq` contract tests for
     the `firewall` object shape and invariants on both endpoints (R-11), including the degradation
     cases via the fault-injection switch below.
  3. **Manual checklist (test VM)**: [quickstart.md](./quickstart.md) — the full panel, each
     threshold crossing/clearing, a forced unreadable value, all-unavailable, and the zero-host /
     all-hosts-down cases.
  - **Fault injection (test-only switch)**: the readers are pure, so `chmod 000`-style external
    breakage is a poor integration seam on the VM — the OPNsense web process runs as root and
    ignores file permissions. Instead `FirewallTelemetryReader` honours an optional, off-by-default
    fault-injection switch (env/config variable) that simulates source failure: `sysctl` timeout or
    non-zero exit, or a failed version-file read. The switch is absent in production builds and
    documented in [quickstart.md](./quickstart.md) §4 (M10/M11/M14), where it drives the
    FR-012/FR-013 degradation checks end to end.
- **Rationale**: The pure classes carry every decision in this document, so the dev-machine suite
  proves the ZFS/UFS split, the thresholds and the degradation; only the appliance's live wiring
  (controller call, view render, ACL) needs the VM.

## R-10 Access, packaging and clean lifecycle (FR-014, Principle I)

- **Decision**: The `firewall` object is served from the **existing** `/ui/casadelta/status` page
  and the **existing** `/api/casadelta/status/get` and `/refresh` endpoints, already gated by
  `page-casadelta-status` (slice 001
  [acl-menu.md](../001-host-status-monitoring/contracts/acl-menu.md)). No new privilege, no new
  URL, no new menu entry, **no new configd action** — the panel inherits the existing access matrix
  exactly (FR-014, Principle V). Packaging deltas: `Makefile` `PLUGIN_VERSION` 0.1.0 → 0.2.0; a
  0.2.0 entry in `pkg-descr` (still "Requires OPNsense 26.7 or later"); `+POST_DEINSTALL.post` is
  **unchanged** — this slice adds **no `/tmp` files**, so the slice 001 hook's existing
  `/tmp/casadelta_dashboard_*` removals remain the complete cleanup (Principle I).
- **Rationale**: Reusing the slice 001 privilege and URL means a view-only account sees the panel
  and nobody else does, with zero new ACL surface (SC-006). With no files this slice creates,
  de-install residue is structurally impossible, which is what the gate question in Principle I
  asks for.
- **Alternatives considered**: A dedicated `/ui/casadelta/firewall` page + privilege (rejected:
  the spec places the panel on the existing page and forbids a new privilege/URL). A configd action
  for the read (rejected, R-02).

## R-11 The status API, extended

- **Decision**: `Api\StatusController::buildStatus()` keeps its slice 001 host logic **exactly as
  it is** — same snapshot read, same stale/forced decision, same `check_hosts` invocation, same
  30 s budget, same summary and groups computation. The **only** addition: it then calls
  `FirewallTelemetryReader` in-process (R-02, R-06) and assembles the `firewall` object with
  `FirewallStatusAssembler` (R-08) into the response body, for **both** `GET /get` and
  `POST /refresh` ([contracts/firewall-api.md](./contracts/firewall-api.md)). The host side and the
  firewall side are independent within the response: a firewall read failure never touches
  `summary`/`groups` (FR-017) and vice versa. There is no parallel `proc_open` pool and no
  cross-check deduplication — the two data paths simply run in the one request.
- **Rationale**: The in-process firewall read (worst case ~3 s, bounded, R-06) serially inside the
  response is acceptable for ≤ 10 concurrent viewers (SC-005) and removes every piece of the first
  draft's latency machinery: the 15 s deadline, the parallel pool, the two-snapshot interaction and
  the "second refresh within 5 s" cross-check cooldown.
- **Alternatives considered**: The first draft's parallel `proc_open` pool for the two configd
  actions (rejected with the configd action, R-02). Fire-and-forget background read with a stale
  `firewall` object (rejected: it would silently serve stale telemetry and reintroduce snapshot
  semantics the design removes).

## R-12 VM-verify list

- **Verified on the test box** (OPNsense 26.7.7, UFS root, `coretemp` loaded):
  - `kern.boottime` `{ sec = …, usec = … } <date>` shape; `vm.loadavg` `{ a b c }` shape; five
    counters in `kern.cp_time` in user/nice/sys/intr/idle order; `67.0C`-style
    `dev.cpu.N.temperature`; `hw.physmem` / `hw.pagesize` / the four `vm.stats.vm.v_*` OIDs all
    present and sane.
  - `/usr/local/opnsense/version/core` is JSON with `CORE_PRODUCT` / `CORE_VERSION`.
  - `coretemp` is loaded → `dev.cpu.N.temperature` present (temperature row shows on the test box).
  - `hw.acpi.thermal.*` reports a bogus 0.1 °C — deliberately unused (R-05).
  - `kstat.zfs.misc.arcstats.size` is **absent** on this UFS box; `sysctl -i` skips it silently
    (exit 0).
- **Unverified — VM-verify (ZFS install only)**: with a ZFS root the ARC OID is present and
  non-zero, and the ARC-subtracted memory figure matches the ARC visible in the system (Edge Case:
  large ZFS cache). This box runs UFS, so the **ZFS/ARC-present path remains unit-test-only** for
  now; confirm it if/when a ZFS test install is available. Fallback if it ever disagrees with
  `top`'s `Mem:` line: keep the formula (the spec's definition is normative, FR-005) and reconcile
  the source, not the definition.

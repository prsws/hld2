# Implementation Plan: Firewall Self-Status

**Branch**: `002-firewall-self-status` | **Date**: 2026-10-16 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/002-firewall-self-status/spec.md`

**Note**: This template is filled in by the `/speckit-plan` command; its definition describes the execution workflow.

## Summary

The second vertical slice of Casa Delta Dashboard v2. Viewers see a new **"Firewall" panel at the
top of the existing Host Status page** (slice 001), above all host groups, showing the health of
the firewall appliance itself: hostname, OPNsense version, uptime, last boot, load average (1/5/15),
current CPU usage, memory used/total, disk used/total for the root disk, and CPU temperature only
when the hardware reports it. Values that cross fixed thresholds (memory > 90%, disk > 90%, 5-minute
load > core count) are highlighted as warnings. The panel refreshes on the same ~60 s cycle and via
the existing "Refresh now" button. It appears even when no hosts are configured or every host is
down, and any single unreadable value degrades to "unavailable" without breaking the panel. Access
reuses the slice 001 `page-casadelta-status` privilege; there is no new privilege and no settings page.

**Technical approach** (see [research.md](./research.md)):

- **Telemetry read in-process**: a new library class
  `PRSWS\CasaDelta\Lib\FirewallTelemetryReader` — the I/O boundary with an injectable command
  runner — performs **bounded calls to `/sbin/sysctl -i`** —
  one for the full OID set and a short `kern.cp_time` + per-core-temperature call for the CPU
  delta and sensor readings (argv array, no shell,
  strict 1 s deadline each, never in parallel) — plus PHP built-ins
  (`gethostname()`, a `file_get_contents()` read of the OPNsense core version file,
  `disk_total_space('/')` / `disk_free_space('/')`). `Api\StatusController::buildStatus()` calls it
  directly; there is **no new configd action, no backend script, no daemon** (R-02, FR-011, FR-015).
- **No snapshot, no deduplication**: the first draft's shared `/tmp` snapshot, its lock, the
  `SnapshotFreshness` reuse and the parallel `proc_open` pool are all **removed**. The
  `firewall` object is freshly read in-process per response. Slice 001's `check_hosts` path and the
  `StatusController` host logic stay **exactly as they are** (R-02).
- **Reuse, not new surface**: this slice adds no model container, no config, no settings page, no
  new privilege, no new URL and no new configd entry. The status API gains a `firewall` object in
  its existing `GET /api/casadelta/status/get` and `POST .../refresh` responses, and the existing
  `status.volt` renders the Firewall panel at the top. Access is inherited from
  `page-casadelta-status` (FR-014).
- **Graceful degradation**: the sysctl call is bounded by a strict 1 s deadline (FR-016); each
  requested OID is parsed by name, so a missing OID (or a failed call) makes exactly that value
  `null` ("unavailable") — never an error, never a warning source. CPU temperature whose OIDs the
  hardware does not report is omitted entirely (FR-007); a fully unreadable read shows a single
  discreet notice (FR-012, FR-013).

## Technical Context

**Language/Version**: PHP 8.x as shipped by OPNsense 26.7, written to a PHP 8.2-compatible syntax subset (same baseline as slice 001). JavaScript ES2017 in the Volt view, no transpilation.

**Primary Dependencies**: OPNsense MVC framework (`OPNsense\Base` model/controllers, Volt) and the GUI-bundled jQuery, Bootstrap and OPNsense JS helpers (`ajaxGet`, `ajaxCall`). Local OS readers: the FreeBSD base `/sbin/sysctl` binary (bounded, in-process; the only launched binary) and PHP built-ins (`gethostname()`, `file_get_contents()`, `disk_total_space()`, `disk_free_space()`). No `sensors`, `top`, `vmstat` or `df` (R-03). Adds no runtime packages (Principles I, VII).

**Storage**: **None.** No persistent configuration (thresholds are fixed; FR-009), no `/tmp` files, no lock, no snapshot, no configd state. The only runtime state is one in-memory telemetry object per API response, discarded when the request ends. Slice 001's `/tmp` snapshot files and its `+POST_DEINSTALL.post` hook are unchanged.

**Testing**: PHPUnit (dev-only Composer dependency) for the two new library classes (the I/O-boundary reader, the pure assembler), including both a ZFS case (ARC present) and a UFS case (ARC absent). `curl`/`jq` contract tests against the test VM API. A manual checklist on the test VM (R-09, R-12, [quickstart.md](./quickstart.md)).

**Target Platform**: OPNsense 26.7 on FreeBSD 15 (amd64). Baseline hardware is a 4-core Intel N6005. Minimum supported version: 26.7 (R-10, Technical Constraints). Sources verified on the test box: OPNsense 26.7.7, UFS root, `coretemp` loaded (R-03).

**Project Type**: An OPNsense plugin (MVC web module) in an `opnsense/plugins`-style tree, extending the `os-casadelta-dashboard` package from slice 001 — **no new configd action or backend script in this slice**.

**Performance Goals**: Firewall telemetry is produced by two bounded, serial `/sbin/sysctl -i` calls (one full OID set, one short `kern.cp_time` + per-core-temperature call; strict 1 s deadline each; the missing OIDs skipped are simply absent values) plus a ~500 ms sample gap and in-process file/stat reads — a worst-case read of ~2.5 s (1 + 0.5 + 1; normal ≈ 0.6 s). Every status response performs such an in-process read, bounded, so it adds no unbounded latency to the host refresh; the hosts `check_hosts` path keeps slice 001's own budgets (SC-005). The panel loads with complete values well within the spec's 3 s budget (SC-001) on baseline hardware.

**Constraints**: The bounded `sysctl` calls (full OID set, then the short `kern.cp_time` + per-core-temperature call, serial) each have a strict 1 s deadline; any value whose source is missing or whose call failed degrades to `null` for exactly that value (FR-012, FR-016). No shared snapshot file, no `/tmp` writes, no lock, no daemon, no new configd action, no parallel process pool. Fault injection is a per-source marker file under `/tmp/casadelta_dashboard_fault_` (R-09) that the reader checks only if present — it ships inactive and is removed by the existing `+POST_DEINSTALL` glob. Read-only toward all firewall networking configuration. Negligible aggregate CPU/memory on baseline hardware (Principle II). No CDN, no new package dependency.

**Scale/Scope**: A homelab firewall. Two new library classes in `library/PRSWS/CasaDelta/Lib/` (the I/O-boundary reader, the pure assembler), one new API object (`firewall`) in the existing status endpoints, one new panel in the existing status page, one new unit-test surface plus one integration test script. No new privileges, no new URLs, no new model container, no new configd entry, no new `/tmp` file. Bounded for the ≤ 10 concurrent viewers inherited from slice 001.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | Gate question | Pre-research | Post-design | Evidence |
|---|-----------|---------------|--------------|-------------|----------|
| I | Firewall-native | Built only from plugin framework parts (model, API, Volt, menu, ACL, configd)? No standalone web root or system-file edits? Leaves no residue beyond its config section? | PASS | PASS | R-02, R-10. All new files sit in the package tree (`library/PRSWS/CasaDelta/Lib`, the API controller, `status.volt`). There is **no new configd action** in `actions_casadelta.conf` (the slice 001 `[check_hosts]` entry is untouched) and **no new `/tmp` files**, so the slice 001 `+POST_DEINSTALL.post` hook needs no extension. No system files, no web-root changes, no base PHP edits. |
| II | Firewall must not be harmed | Read-only toward rules/interfaces/routes/NAT? Strict timeout, bounded concurrency, minimum interval? No permanent daemon? Negligible overhead? | PASS | PASS | [firewall-api.md](./contracts/firewall-api.md). Every read is local and read-only: bounded `/sbin/sysctl -i` calls (argv array, no shell, 1 s deadline each, serial — no parallel processes), one version-file read, PHP stat built-ins (R-03, R-06). Nothing is written, no process pool, no daemon. At most two ~1 s serial bounded reads per response is CPU-negligible on the N6005. |
| III | Self-sufficient | All assets local? No runtime dependency on another LAN host? Graceful degradation? | PASS | PASS | Telemetry is read from the firewall's own OS, in-process (R-02) — strictly more self-sufficient than slice 001, which needs the LAN. UI uses only GUI-bundled assets ([ui-firewall.md](./contracts/ui-firewall.md)). Unreadable values and an all-failed read degrade to "unavailable" / a single notice rather than breaking the panel (FR-012, FR-013). |
| IV | Configuration lives in the firewall | Settings in the model / config.xml, edited via GUI or API, in backups? No `.env`? No secrets? | PASS | PASS | This slice adds no configuration at all (thresholds are fixed, FR-009), so there is nothing to persist, back up, or leak. It holds no secrets and creates no runtime file in `/tmp` or anywhere else (R-02, R-10). |
| V | Firewall identity & least privilege | Uses OPNsense users and ACLs only? View and manage distinct? | PASS | PASS | The panel and the `firewall` API object are served from the existing `/ui/casadelta/status` and `/api/casadelta/status/*` URLs, which are already gated by `page-casadelta-status` (slice 001 [acl-menu.md](../001-host-status-monitoring/contracts/acl-menu.md)). No new privilege, no new URL pattern, so the existing access matrix applies unchanged (FR-014). |
| VI | Thin vertical slice | One user-visible capability, installable and demoable on its own, across the layers it needs? | PASS | PASS | The Firewall panel is a complete, independently demoable capability: an in-process backend reader + API object + Volt panel. It intentionally touches no model/settings layer because the slice has no configuration by design (R-01), and it adds no configd backend because the read is in-process (R-02). Both are deliberate scoping choices, not a "framework-first" spec. Demoable alone on the slice 001 page (quickstart §3–§4). |
| VII | Use what the platform provides | Built-in UI components preferred? Every added library or build step justified? | PASS | PASS | The panel reuses Bootstrap panels/labels and the `ajaxGet`/`ajaxCall` helpers already used by slice 001 ([ui-firewall.md](./contracts/ui-firewall.md)). The telemetry readers are the FreeBSD base `/sbin/sysctl` binary and PHP built-ins only — no `sensors`, `top`, `vmstat` or `df`, and no new runtime library and no build step (R-03, R-05). PHPUnit remains dev-only and is never shipped. |
| VIII | Never develop against production | Test-VM-only development? Automated tests plus a manual checklist? | PASS | PASS | [quickstart.md](./quickstart.md) §0–§5. Unit tests run on the dev machine; integration and the manual checklist run on the test VM. Production receives only the packaged artifact. |
| — | Technical constraints | Targets 26.7 / FreeBSD 15? Follows plugin conventions? Declares the minimum version? CSRF + output encoding? | PASS | PASS | R-10. New files follow the slice 001 `mvc/app/library/PRSWS/CasaDelta/Lib` and `mvc/app/...` layout. `pkg-descr` keeps "Requires OPNsense 26.7 or later" and gains a 0.2.0 changelog entry. The API is reached through the framework (CSRF via `ajaxCall`); all panel values are inserted as text, never HTML. |

**Result**: Every gate passes, both before research and after design, with no violations that require
a Complexity Tracking entry. Design notes that refine the spec without contradicting it:

- **No configuration layer**: slice 001's Principle VI example spans model/settings/backend/API/view/ACL;
  this slice has no configuration by design (fixed thresholds, FR-009, no settings page). It still spans
  backend / API / view and reuses the existing ACL — a complete vertical capability, so Principle VI is
  met. This is recorded here rather than in Complexity Tracking because it is a scoping choice the spec
  mandates, not a deviation.
- **CPU usage from counter deltas**: current CPU usage is the delta of two `kern.cp_time` samples
  taken ~500 ms apart; the first sample comes from the one full `sysctl -i` call, the second from
  a short follow-up `sysctl -i` call requesting `kern.cp_time` plus the per-core `dev.cpu.<i>.temperature` OIDs
  (the per-core list is built from `hw.ncpu` returned by the first call — R-03, R-04, R-05). This
  replaces
  the first draft's 1-second `vmstat`/`top` sample and its parallel `proc_open` pool, keeping the
  whole read well within the SC-001/SC-005 budgets.
- **Temperature source**: `dev.cpu.N.temperature` per core (max), read in the second `sysctl -i`
  call; verified present on the test box
  (`coretemp` loaded). `hw.acpi.thermal.*` is deliberately **not** used — it reports a bogus 0.1 °C on
  this hardware (R-05).
- **ARC term**: `kstat.zfs.misc.arcstats.size` exists only on ZFS installations; the test box has a UFS
  root, so the ARC-present path is covered by unit tests only and remains the one VM-verify item (R-12).

## Project Structure

### Documentation (this feature)

```text
specs/002-firewall-self-status/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
│   ├── firewall-api.md
│   └── ui-firewall.md
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
net-mgmt/casadelta-dashboard/
├── Makefile                                  # PLUGIN_VERSION 0.1.0 -> 0.2.0 (R-10)
├── pkg-descr                                 # description + 0.2.0 changelog entry
├── +POST_DEINSTALL.post                      # UNCHANGED (slice 001); this slice adds no /tmp files, so no extension
└── src/
    └── opnsense/
        └── mvc/app/
            ├── controllers/PRSWS/CasaDelta/
            │   └── Api/
            │       └── StatusController.php  # EXTENDED: host logic unchanged; buildStatus() gains an in-process FirewallTelemetryReader read and adds `firewall` to the response body
            ├── library/PRSWS/CasaDelta/Lib/
            │   ├── FirewallTelemetryReader.php   # NEW (I/O boundary, injectable runner): bounded `sysctl -i` calls (full set; then cp_time + per-core temps), per-OID parsing, version file, disk built-ins
            │   └── FirewallStatusAssembler.php   # NEW (pure): CPU-delta %, memory, disk percentages, thresholds/warnings, availability, the `firewall` object
            └── views/PRSWS/CasaDelta/
                └── status.volt               # EXTENDED: renders the Firewall panel above #cd-groups, updates in place on the same poll/refresh
        (no scripts/CasaDelta entry and no new action in service/conf/actions.d/actions_casadelta.conf)

tests/
├── unit/
│   ├── FirewallTelemetryReaderTest.php       # NEW: `sysctl -i` output parsing by name (missing OIDs, -i skip messages, exit 0), version JSON, version-file-missing, disk built-ins
│   └── FirewallStatusAssemblerTest.php       # NEW: CPU-delta, memory math (ZFS-with-ARC and UFS-ARC-absent cases), thresholds, warnings, availability, all_unavailable
└── integration/
    └── firewall_api.sh                       # NEW: curl/jq contract tests for the `firewall` object (TEST VM only)

composer.json                                 # unchanged (PHPUnit dev-only; new Lib classes are already PSR-4 mapped)
phpunit.xml.dist                             # unchanged
```

**Structure Decision**: This slice extends the slice 001 package in place rather than adding a new
mount point, URL, model container, privilege, configd action, or `/tmp` file. The two new classes
live in the same `mvc/app/library/PRSWS/CasaDelta/Lib/` directory (already PSR-4 mapped in
`composer.json` and autoloaded by `autoload.php`), so their unit tests run on the dev machine with
no appliance. The **only** slice-001 files modified are the API controller and the status view, and
both changes are additive: the `check_hosts` path (configd action, script, snapshot,
`SnapshotFreshness`, `StatusAssembler`) and the host parts of `Api\StatusController` stay exactly as
they are.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

No violations. Every gate passes (see Constitution Check). The design notes there (no configuration
layer; `kern.cp_time` deltas instead of a 1-second sampler; `dev.cpu.N.temperature` instead of
`sensors`; in-process read without a shared snapshot) are spec-mandated or spec-compatible scoping
decisions, not deviations, so they are recorded in the gate evidence rather than here.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |

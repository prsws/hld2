# Implementation Plan: Firewall Self-Status

**Branch**: `002-firewall-self-status` | **Date**: 2026-10-15 | **Spec**: [spec.md](./spec.md)

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

- **Telemetry source**: the firewall's own OS. A new configd action `casadelta check_firewall [force]`
  (R-02) runs a small set of bounded local reads (`sysctl`, `df`, `vmstat`/`top`, `sensors`) through a
  short-lived PHP script. It writes an ephemeral snapshot to `/tmp` and prints one JSON document. There
  is no daemon, and no probes to any other host (FR-011, FR-015).
- **Reuse, not new surface**: this slice adds no model container, no config, no settings page, no new
  privilege and no new URL. The status API (slice 001) gains a `firewall` object in its existing
  `GET /api/casadelta/status/get` and `POST .../refresh` responses, and the existing `status.volt`
  renders the Firewall panel at the top. Access is inherited from `page-casadelta-status` (FR-014).
- **Sharing**: the firewall snapshot reuses slice 001's `SnapshotFreshness` rules (55 s TTL, 5 s
  cooldown, schema check) with a fixed sentinel fingerprint, so one read happens per ~60 s window for
  any number of viewers. The status API reads the snapshot directly (fast path) and only invokes the
  configd action when it is stale or the user forces a refresh (R-09).
- **Graceful degradation**: each read is bounded by a strict per-value timeout (FR-016); a failed read
  yields "unavailable" for just that value, and a fully failed refresh shows a single discreet notice
  (FR-012, FR-013). CPU temperature that the hardware does not report is omitted entirely (FR-007).

## Technical Context

**Language/Version**: PHP 8.x as shipped by OPNsense 26.7, written to a PHP 8.2-compatible syntax subset (same baseline as slice 001). JavaScript ES2017 in the Volt view, no transpilation.

**Primary Dependencies**: OPNsense MVC framework (`OPNsense\Base` model/controllers, Volt), configd, and the GUI-bundled jQuery, Bootstrap and OPNsense JS helpers (`ajaxGet`, `ajaxCall`). Local OS readers: FreeBSD base `sysctl`, `df`, `vmstat`/`top`, and `sensors` (R-06 — used opportunistically, never a new package dependency). Adds no runtime packages (Principles I, VII).

**Storage**: No persistent configuration (thresholds are fixed; FR-009). One ephemeral snapshot file `/tmp/casadelta_dashboard_firewall.json` plus a lock file, written only by the `check_firewall` script, read by the status API. It is not part of `config.xml`, is never web-served, and is removed on de-install.

**Testing**: PHPUnit (dev-only Composer dependency) for the two new pure library classes. `curl`/`jq` contract tests against the test VM API. A manual checklist on the test VM (R-13, [quickstart.md](./quickstart.md)).

**Target Platform**: OPNsense 26.7 on FreeBSD 15 (amd64). Baseline hardware is a 4-core Intel N6005. Minimum supported version: 26.7 (R-12, Technical Constraints).

**Project Type**: An OPNsense plugin (MVC web module + configd backend action) in an `opnsense/plugins`-style tree, extending the `os-casadelta-dashboard` package from slice 001.

**Performance Goals**: Firewall telemetry is produced by local reads only. A typical read set completes in under ~2 s (the current-CPU sample is the slowest value); the whole `check_firewall` run is hard-capped (R-09). A fresh-snapshot poll (the common case) makes no configd call and adds zero latency to the status response. On the ~60 s boundary poll the read is bounded, so it adds no noticeable delay to the host status refresh (SC-005). The panel loads with complete values within 3 s of page load on baseline hardware (SC-001).

**Constraints**: Every read has a strict per-value timeout and a whole-run cap (FR-016). One read per ~60 s window, deduplicated by the shared snapshot, with a 5 s minimum interval between forced reads (Principle II, R-09). Read-only toward all firewall networking configuration. Negligible aggregate CPU/memory on baseline hardware (Principle II). No daemon, no CDN, no new package dependency.

**Scale/Scope**: A homelab firewall. One new configd action, one new `/tmp` snapshot, one new API object (`firewall`) in the existing status endpoints, one new panel in the existing status page, two new pure library classes, no new privileges, no new URLs, no new model container. Bounded for the ≤ 10 concurrent viewers inherited from slice 001.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | Gate question | Pre-research | Post-design | Evidence |
|---|-----------|---------------|--------------|-------------|----------|
| I | Firewall-native | Built only from plugin framework parts (model, API, Volt, menu, ACL, configd)? No standalone web root or system-file edits? Leaves no residue beyond its config section? | PASS | PASS | R-02, R-12. All new files sit in the package tree (`scripts/CasaDelta`, `library/.../Lib`, the API controller, `status.volt`). The action is a native configd `script_output` entry in the existing `actions_casadelta.conf`. The `+POST_DEINSTALL.post` hook is extended to remove the new `/tmp/casadelta_dashboard_firewall_*` files. No system files, no web-root changes, no base PHP edits. |
| II | Firewall must not be harmed | Read-only toward rules/interfaces/routes/NAT? Strict timeout, bounded concurrency, minimum interval? No permanent daemon? Negligible overhead? | PASS | PASS | [configd-check-firewall.md](./contracts/configd-check-firewall.md). Every read is a local, read-only command (`sysctl`/`df`/`vmstat`/`sensors`) with a strict per-value timeout and a whole-run cap (R-09). Runs only on demand via configd; no daemon. One read per ~60 s window, 5 s minimum interval between forced reads (cooldown). Local reads are CPU-negligible on the N6005. |
| III | Self-sufficient | All assets local? No runtime dependency on another LAN host? Graceful degradation? | PASS | PASS | Telemetry is read from the firewall's own OS (R-02) — strictly more self-sufficient than slice 001, which needs the LAN. UI uses only GUI-bundled assets ([ui-firewall.md](./contracts/ui-firewall.md)). Unreadable values and an all-failed refresh degrade to "unavailable" / a single notice rather than breaking the panel (FR-012, FR-013). |
| IV | Configuration lives in the firewall | Settings in the model / config.xml, edited via GUI or API, in backups? No `.env`? No secrets? | PASS | PASS | This slice adds no configuration at all (thresholds are fixed, FR-009), so there is nothing to persist, back up, or leak. It holds no secrets. The snapshot is a runtime artifact in `/tmp`, outside the web root and not configuration (R-09, R-12). |
| V | Firewall identity & least privilege | Uses OPNsense users and ACLs only? View and manage distinct? | PASS | PASS | The panel and the `firewall` API object are served from the existing `/ui/casadelta/status` and `/api/casadelta/status/*` URLs, which are already gated by `page-casadelta-status` (slice 001 [acl-menu.md](../001-host-status-monitoring/contracts/acl-menu.md)). No new privilege, no new URL pattern, so the existing access matrix applies unchanged (FR-014). |
| VI | Thin vertical slice | One user-visible capability, installable and demoable on its own, across the layers it needs? | PASS | PASS | The Firewall panel is a complete, independently demoable capability: a configd backend action + API object + Volt panel. It intentionally touches no model/settings layer because the slice has no configuration by design (R-01); that is a deliberate scoping, not a horizontal "framework-first" spec. Demoable alone on the slice 001 page (quickstart §3–§4). |
| VII | Use what the platform provides | Built-in UI components preferred? Every added library or build step justified? | PASS | PASS | The panel reuses Bootstrap panels/labels and the `ajaxGet`/`ajaxCall` helpers already used by slice 001 ([ui-firewall.md](./contracts/ui-firewall.md)). The telemetry readers are FreeBSD base utilities; `sensors` is used opportunistically and is **not** added as a package dependency (R-06), so no new runtime library and no build step. PHPUnit remains dev-only and is never shipped. |
| VIII | Never develop against production | Test-VM-only development? Automated tests plus a manual checklist? | PASS | PASS | [quickstart.md](./quickstart.md) §0–§5. Unit tests run on the dev machine; integration and the manual checklist run on the test VM. Production receives only the packaged artifact. |
| — | Technical constraints | Targets 26.7 / FreeBSD 15? Follows plugin conventions? Declares the minimum version? CSRF + output encoding? | PASS | PASS | R-12. New files follow the slice 001 `mvc/app/...` and `scripts/CasaDelta` layout. `pkg-descr` keeps "Requires OPNsense 26.7 or later" and gains a 0.2.0 changelog entry. The API is reached through the framework (CSRF via `ajaxCall`); all panel values are inserted as text, never HTML. |

**Result**: Every gate passes, both before research and after design, with no violations that require
a Complexity Tracking entry. Design notes that refine the spec without contradicting it:

- **No configuration layer**: slice 001's Principle VI example spans model/settings/backend/API/view/ACL;
  this slice has no configuration by design (fixed thresholds, FR-009, no settings page). It still spans
  backend / API / view and reuses the existing ACL — a complete vertical capability, so Principle VI is
  met. This is recorded here rather than in Complexity Tracking because it is a scoping choice the spec
  mandates, not a deviation.
- **CPU usage is the slowest read**: a "current" CPU sample needs a ~1 s window (`vmstat 1 2` /
  `top -b -n 1`), so it is the dominant cost of the read set (R-05). It is still local, bounded, and
  infrequent, so it stays within the "no noticeable delay" budget. See R-09 for the budget.
- **Temperature is opportunistic**: `sensors` is used if present on the appliance and omitted otherwise;
  the plugin never adds it as a dependency, so the feature is spec-compliant (FR-007) even on an
  appliance that lacks the reader (R-06).

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
│   ├── configd-check-firewall.md
│   └── ui-firewall.md
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
net-mgmt/casadelta-dashboard/
├── Makefile                                  # PLUGIN_VERSION 0.1.0 -> 0.2.0 (R-12)
├── pkg-descr                                 # description + 0.2.0 changelog entry
├── +POST_DEINSTALL.post                      # extended: also removes /tmp/casadelta_dashboard_firewall_* (R-12)
└── src/
    └── opnsense/
        ├── mvc/app/
        │   ├── controllers/PRSWS/CasaDelta/
        │   │   └── Api/
        │   │       └── StatusController.php  # EXTENDED: reads the firewall snapshot, runs check_firewall when stale/forced, adds `firewall` to the response body
        │   ├── library/PRSWS/CasaDelta/Lib/
        │   │   ├── FirewallTelemetry.php     # NEW (pure): bounded local reads + per-value parsers
        │   │   └── FirewallStatusAssembler.php # NEW (pure): assembles the `firewall` API object, warnings, availability
        │   └── views/PRSWS/CasaDelta/
        │       └── status.volt               # EXTENDED: renders the Firewall panel above #cd-groups, updates in place on the same poll/refresh
        └── scripts/CasaDelta/
            └── firewall_status.php           # NEW: configd target, runs the bounded local read set, writes the firewall snapshot, prints JSON
    └── service/conf/actions.d/
        └── actions_casadelta.conf            # EXTENDED: adds the [check_firewall] action

tests/
├── unit/
│   ├── FirewallTelemetryTest.php             # NEW: parser matrix (hostname/version/uptime/load/cpu/memory/disk/temperature)
│   └── FirewallStatusAssemblerTest.php       # NEW: warning + availability + all-unavailable logic
└── integration/
    └── firewall_api.sh                       # NEW: curl/jq contract tests for the `firewall` object (TEST VM only)

composer.json                                 # unchanged (PHPUnit dev-only; new Lib classes are already PSR-4 mapped)
phpunit.xml.dist                             # unchanged
```

**Structure Decision**: This slice extends the slice 001 package in place rather than adding a new
mount point, URL, model container, or privilege. The two new pure classes live in the same
`mvc/app/library/PRSWS/CasaDelta/Lib/` directory (already PSR-4 mapped in `composer.json` and
autoloaded by `autoload.php`), so their unit tests run on the dev machine with no appliance. The
backend read logic lives in `scripts/CasaDelta/firewall_status.php` under the existing configd action
file, mirroring `check_hosts.php`. The API controller and the status view are the only two slice-001
files modified, and both changes are additive. Nothing new enters the package except files that follow
the existing conventions.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

No violations. Every gate passes (see Constitution Check). The two design notes there (no
configuration layer; opportunistic `sensors`) are spec-mandated scoping decisions, not deviations, so
they are recorded in the gate evidence rather than here.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |

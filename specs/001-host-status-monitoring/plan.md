# Implementation Plan: Host Status Monitoring

**Branch**: `001-host-status-monitoring` | **Date**: 2026-10-14 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-host-status-monitoring/spec.md`

**Note**: This template is filled in by the `/speckit-plan` command; its definition describes the execution workflow.

## Summary

This is the first vertical slice of Casa Delta Dashboard v2. It ships as the OPNsense plugin
`os-casadelta-dashboard`. Administrators maintain a list of monitored hosts (name, address with an
optional port, group, sort order, description, enabled flag) in the firewall configuration through
a native settings page. Viewers see a status page that groups the enabled hosts in a fixed order
(Network → Nodes → Apps → custom A–Z), sorted by sort order and then by name. Each host shows up,
down or cannot resolve, its latency and the time of its last check, and there's a summary line.
The page refreshes itself about every 60 s and has a rate-limited "Refresh now" button.

**Technical approach** (see [research.md](./research.md)):

- **Naming**: PHP vendor `PRSWS`, module `CasaDelta`. The plugin's main model is
  `PRSWS\CasaDelta\CasaDelta`, mounted at `//PRSWS/CasaDelta`. Pages and API live under
  `/ui/casadelta/*` and `/api/casadelta/*` (R-02).
- **Configuration**: the host list is the `hosts.host` container of that model, with a custom
  `PRSWS\CasaDelta\FieldTypes\HostAddressField` for address validation. Later slices add their
  own containers to the same model instead of new mount points.
- **Settings API**: CRUD through `ApiMutableModelControllerBase`. It uses only `UIBootgrid` and
  `base_dialog`.
- **Checks**: a configd action `casadelta check_hosts` runs a short-lived PHP script. The script
  sends 2 ICMP probes per host through a bounded `proc_open` pool of `/sbin/ping` processes, with
  hard timeouts.
- **Sharing results**: the results go into one flock-protected `/tmp` snapshot that all viewers
  share. Server-guided poll scheduling (`next_check_at`) keeps it to one check per minute for any
  number of viewers. There is no daemon.
- **Access**: two ACL privileges separate viewing from managing.

## Technical Context

**Language/Version**: PHP 8.x as shipped by OPNsense 26.7 (code limited to PHP 8.2 syntax). JavaScript ES2017 in Volt views, with no transpilation (R-03).

**Primary Dependencies**: OPNsense MVC framework (`OPNsense\Base` models and controllers, Volt), configd, and the GUI-bundled jQuery, Bootstrap and OPNsense JS helpers (`ajaxGet`, `ajaxCall`, `UIBootgrid`). Uses FreeBSD base `/sbin/ping`. Adds no runtime packages (R-03, R-10, R-17).

**Storage**: OPNsense `config.xml` section `//PRSWS/CasaDelta` (container `hosts.host`) for the host inventory. An ephemeral JSON snapshot `/tmp/casadelta_dashboard_status.json` plus a lock file for check results (R-07).

**Testing**: PHPUnit (dev-only Composer dependency) for the pure library classes on the dev machine. `curl`/`jq` contract tests against the test VM API. A manual checklist on the test VM (R-15, [quickstart.md](./quickstart.md)).

**Target Platform**: OPNsense 26.7 on FreeBSD 15 (amd64). Baseline hardware is a 4-core Intel N6005. Minimum supported version: 26.7 (R-14).

**Project Type**: An OPNsense plugin (an MVC web module plus a configd backend action) in an `opnsense/plugins`-style tree (R-01).

**Performance Goals**: Cached status renders in under 2 s, and a cold check of ≤ 50 hosts finishes in ≤ 5 s typical (≤ 20 s hard cap). At most one probe run per 60 s with ≤ 10 viewers. A state change is visible within 60 s ± 5 s (SC-003–SC-005, R-08).

**Constraints**: 2 ICMP probes per host; 1 s wait per probe, 3 s ping timeout, 4 s process deadline, 20 s run cap; ≤ 16 parallel pings; 5 s global refresh cooldown. No daemon, no CDN, read-only toward networking config, negligible CPU (< 2 %) (FR-013, Principles II and III).

**Scale/Scope**: A homelab with up to 50 monitored hosts and up to 10 concurrent viewers. Two pages (Status, Settings), 8 API endpoints, 1 configd action, 2 privileges.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | Gate question | Pre-research | Post-design | Evidence |
|---|-----------|---------------|--------------|-------------|----------|
| I | Firewall-native | Built only from plugin framework parts (model, API, Volt, menu, ACL, configd)? Without a standalone web root or system file edits? Leaves no residue beyond its config section? | PASS | PASS | R-01, R-02, R-13. Source tree below. All files are in the package manifest. `/tmp` runtime files are removed on de-install. The only residue is the plugin's own `//PRSWS/CasaDelta` config section |
| II | Firewall must not be harmed | Read-only toward rules, interfaces, routes and NAT? Timeouts, bounded concurrency and a minimum interval? No permanent daemon? Negligible overhead? | PASS | PASS | [configd-check-hosts.md](./contracts/configd-check-hosts.md) guarantees table. The script only reads the plugin's own model. It reuses the existing configd. The 5 s cooldown and the 55 s TTL bound the load |
| III | Self-sufficient | All assets local? No runtime dependency on another LAN host? Internet-dependent features degrade gracefully? | PASS | PASS | Only GUI-bundled assets are used ([ui-pages.md](./contracts/ui-pages.md)). If DNS is down, hosts show `cannot resolve` rather than breaking the page. Manual check M13 |
| IV | Configuration lives in the firewall | Settings kept in the model or config.xml, edited through the GUI or API, included in backups? No `.env` files? No secrets in web paths? | PASS | PASS | Data-model §1. This slice holds no secrets. The snapshot is outside the web root and isn't config. Test credentials stay in the dev shell. Manual checks M15 and M16 |
| V | Identity & least privilege | Uses OPNsense users and ACLs only? View and manage privileges separate? | PASS | PASS | [acl-menu.md](./contracts/acl-menu.md) access matrix (`page-casadelta-status` vs `page-casadelta-settings`). Integration test plus manual check M12 |
| VI | Thin vertical slice | One user-visible capability across model, settings, backend, API, view and ACL? Installable and demoable alone? | PASS | PASS | Every layer is in the source tree. Quickstart §2–§4 demo it on its own |
| VII | Use what the platform provides | Built-in UI components preferred? Every added library or build step justified? | PASS | PASS | `UIBootgrid`, `base_dialog`, `ajaxCall`, Bootstrap labels. No new runtime library and no asset build. PHPUnit is dev-only and never shipped (R-15), so it isn't a runtime library or build step under Principle VII |
| VIII | Never develop against production | Test-VM-only development? Packaged releases only? Automated tests plus a manual checklist? | PASS | PASS | [quickstart.md](./quickstart.md) §0–§5. Unit and integration tests plus checklist M1–M17 |
| — | Technical constraints | Targets 26.7 / FreeBSD 15? Follows plugin conventions? States the minimum version? Uses CSRF and output encoding? | PASS | PASS | R-01, R-14. CSRF comes through the framework `ajaxCall`. Text-only DOM insertion ([ui-pages.md](./contracts/ui-pages.md)). Probes run from an argv array without a shell |

**Result**: Every gate passes, both before research and after design, with no violations. The
Complexity Tracking table is empty. Design notes that refine the spec without contradicting it:

- A transient `pending` state shows a newly enabled host that has no check result yet. This
  normally happens only when the 5 s cooldown or a failed run postponed its first check. A
  fingerprint change otherwise triggers an immediate check. `pending` is never counted as up or
  down (data-model §3).
- The spec's `HostController` role is implemented as `Api\SettingsController`, which follows the
  OPNsense naming convention. No `ServiceController` exists, because there is no service to
  reload (settings-api contract).

## Project Structure

### Documentation (this feature)

```text
specs/001-host-status-monitoring/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
│   ├── status-api.md
│   ├── settings-api.md
│   ├── configd-check-hosts.md
│   ├── acl-menu.md
│   └── ui-pages.md
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
net-mgmt/casadelta-dashboard/                 # plugin dir (opnsense/plugins layout)
├── Makefile                                  # PLUGIN_NAME=casadelta-dashboard, PLUGIN_VERSION=0.1.0
├── pkg-descr                                 # description, "Requires OPNsense 26.7 or later", changelog
├── +POST_DEINSTALL.post                      # removes /tmp/casadelta_dashboard_* (R-13)
└── src/
    └── opnsense/
        ├── mvc/app/
        │   ├── models/PRSWS/CasaDelta/
        │   │   ├── CasaDelta.php             # BaseModel PRSWS\CasaDelta\CasaDelta (main model)
        │   │   ├── CasaDelta.xml             # mount //PRSWS/CasaDelta, hosts.host ArrayField
        │   │   ├── FieldTypes/HostAddressField.php  # PRSWS\CasaDelta\FieldTypes\HostAddressField
        │   │   ├── ACL/ACL.xml
        │   │   └── Menu/Menu.xml
        │   ├── controllers/PRSWS/CasaDelta/
        │   │   ├── StatusController.php      # UI: /ui/casadelta/status
        │   │   ├── SettingsController.php    # UI: /ui/casadelta/settings
        │   │   ├── forms/dialogHost.xml
        │   │   └── Api/
        │   │       ├── StatusController.php  # /api/casadelta/status: get, refresh
        │   │       └── SettingsController.php# /api/casadelta/settings: searchHost/getHost/addHost/setHost/delHost/toggleHost
        │   ├── library/PRSWS/CasaDelta/Lib/  # framework-free, unit-tested
        │   │   ├── HostAddress.php
        │   │   ├── HostOrdering.php
        │   │   ├── PingResultParser.php
        │   │   ├── SnapshotFreshness.php
        │   │   └── StatusAssembler.php
        │   └── views/PRSWS/CasaDelta/
        │       ├── status.volt
        │       └── settings.volt
        ├── scripts/CasaDelta/
        │   └── check_hosts.php               # configd target, bounded ping pool, snapshot writer
        └── service/conf/actions.d/
            └── actions_casadelta.conf

tests/
├── bootstrap.php                             # autoloads library/ only (no OPNsense runtime)
├── unit/                                     # PHPUnit, runs on the dev machine
│   ├── HostAddressTest.php
│   ├── HostOrderingTest.php
│   ├── PingResultParserTest.php
│   ├── SnapshotFreshnessTest.php
│   └── StatusAssemblerTest.php
└── integration/                              # curl/jq against the TEST VM only
    ├── run.sh
    ├── acl_matrix.sh
    ├── settings_crud.sh
    └── status_api.sh

composer.json                                 # require-dev: phpunit only, never packaged
phpunit.xml.dist
```

**Structure Decision**: The plugin is a single project in the `opnsense/plugins` directory
convention (`net-mgmt/casadelta-dashboard/src/opnsense/...`), so the same tree can be copied into an
`opnsense/plugins` checkout and built with `make package`. The logic that can be tested without the
appliance (address parsing, ordering, ping parsing, freshness, status assembly) is kept out of the
models and controllers in `mvc/app/library/PRSWS/CasaDelta/Lib/`. The models, the controllers
and the configd script then stay thin, and the unit tests run on the dev machine without OPNsense.
Tests and the Composer files live outside `net-mgmt/`, so they never enter the package.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

No violations. This table is intentionally empty.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |

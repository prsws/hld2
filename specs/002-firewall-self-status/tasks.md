---

description: "Task list for Firewall Self-Status (002-firewall-self-status)"
---

# Tasks: Firewall Self-Status

**Input**: Design documents from `/specs/002-firewall-self-status/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Included. Constitution Principle VIII requires automated tests plus a manual checklist on the test instance. Unit tests (PHPUnit) cover the framework-free library classes on the dev machine and are written **before** the code they test. Integration tests (`curl`/`jq`) and the manual checklist run against the **test instance only** (192.168.1.1), never production.

**Organization**: Tasks are grouped by user story. US1 (P1) the Firewall panel with its values, US2 (P2) threshold warnings, US3 (P3) graceful degradation.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1, US2 or US3. Setup, Foundational and Polish tasks carry no story label
- Every task names exact file paths and the plan sections (R-xx), requirements (FR-xxx) and checklist items (Mxx) it implements

## Path Conventions

All paths are relative to the repository root. `PKG` means `net-mgmt/casadelta-dashboard`, `APP` means `net-mgmt/casadelta-dashboard/src/opnsense/mvc/app`, and `LIB` means `APP/library/PRSWS/CasaDelta/Lib` (namespace `PRSWS\CasaDelta\Lib`, already PSR-4 mapped in `composer.json` and loaded by `LIB/autoload.php`).

- New library classes: `LIB/FirewallTelemetry.php`, `LIB/FirewallTelemetryReader.php`, `LIB/FirewallStatusAssembler.php`
- Extended slice 001 files: `APP/controllers/PRSWS/CasaDelta/Api/StatusController.php`, `APP/views/PRSWS/CasaDelta/status.volt`, `tests/integration/run.sh`, `PKG/Makefile`, `PKG/pkg-descr`, `PKG/+POST_DEINSTALL.post`
- New tests: `tests/unit/FirewallTelemetryReaderTest.php`, `tests/unit/FirewallStatusAssemblerTest.php`, `tests/integration/firewall_api.sh`, fixtures in `tests/fixtures/firewall/`

Fixed names (do not change): everything listed in slice 001's tasks.md, plus the API key `firewall`, the fault marker files `/tmp/casadelta_dashboard_fault_{sysctl,version,disk,all}` (R-09) and the version display format `OPNsense 26.7.7` (R-07).

**Do not touch**: `PKG/src/opnsense/scripts/CasaDelta/check_hosts.php`, `PKG/src/opnsense/service/conf/actions.d/actions_casadelta.conf`, the existing `LIB` classes, and the host logic in `Api/StatusController.php` (`buildStatus`, `loadHosts`, `readSnapshot`, `runCheck`) (R-02, R-11).

## Decisions made while writing this task list

These close small gaps in the plan. They are binding for implementation; change them only by updating the plan first.

1. **Uninstall hook**: research R-09/R-10 say the existing `+POST_DEINSTALL.post` removes `/tmp/casadelta_dashboard_*` by glob. It does not: it removes three named files. T002 extends the hook so the fault markers are removed too, and corrects the plan wording.
2. **Where the `firewall` key is added**: `buildStatus()` returns early when no host is enabled. The firewall object is therefore added in `getAction()` and `refreshAction()` after `buildStatus()` returns, so the zero-host path is covered (FR-017) and `buildStatus()` stays untouched.
3. **Call 1 fails or `hw.ncpu` is missing**: call 2 and the 500 ms sleep are skipped. CPU % is `null`, `cpu_temperature_present` is `false`. Worst case stays bounded at about 1 s for this path.
4. **Call 2 fails** (timeout or non-zero exit): CPU % is `null` and `cpu_temperature_present` is `false`, so the temperature row is hidden for that cycle rather than shown as "unavailable". This keeps SC-003 ("100% of page loads" with no temperature placeholder on hardware without a sensor) strict; a transient hidden row on sensor hardware is harmless.
5. **`fault_all` nulls every displayed value**, including the hostname, so `all_unavailable` becomes `true` (FR-013, M11, M14).
6. **The command runner is a callable**, not a new class: `callable(list<string> $argv, float $timeoutSeconds): ?array{exit: int, stdout: string}`, returning `null` on timeout or start failure. The default implementation lives in the reader. This keeps the plan's "two new classes" plus the value object.

---

## Phase 1: Setup

**Purpose**: Packaging changes and the test fixture directory

- [ ] T001 [P] In `PKG/Makefile` set `PLUGIN_VERSION` to `0.2.0`. In `PKG/pkg-descr` add one sentence about the Firewall panel to the description and a `0.2.0` changelog entry above `0.1.0` ("Firewall panel on the Host Status page: hostname, version, uptime, load, CPU, memory, disk, CPU temperature when reported; fixed warning thresholds"). Keep "Requires OPNsense 26.7 or later" (R-10)
- [ ] T002 [P] Extend `PKG/+POST_DEINSTALL.post` to also `rm -f` the four fault markers `/tmp/casadelta_dashboard_fault_sysctl`, `/tmp/casadelta_dashboard_fault_version`, `/tmp/casadelta_dashboard_fault_disk` and `/tmp/casadelta_dashboard_fault_all` (keep the three slice 001 lines unchanged). Then correct the docs that call the hook "unchanged" or a "glob": research.md R-09 and R-10, plan.md (Storage, the `+POST_DEINSTALL.post` line in Source Code, Constitution Check row I) and quickstart.md M17 — the hook now names the marker files explicitly (Decision 1)
- [X] T003 [P] Create `tests/fixtures/firewall/` with a `README.md` stating which files are captured from the test instance and which are synthetic

**Checkpoint**: Version bump and uninstall cleanup in place.

---

## Phase 1b: Capture fixtures on the test instance

**Purpose**: Unit tests parse real output, not invented output. Run on the **test instance (192.168.1.1) only**. Start `sh` first (the root shell is tcsh).

- [X] T004 On the test instance, capture into `tests/fixtures/firewall/`:
  - `sysctl_call1_ufs.txt`: output of `/sbin/sysctl -i kern.hostname kern.boottime vm.loadavg hw.ncpu kern.cp_time hw.physmem hw.pagesize vm.stats.vm.v_page_count vm.stats.vm.v_free_count vm.stats.vm.v_inactive_count vm.stats.vm.v_laundry_count kstat.zfs.misc.arcstats.size` (record that the exit code is 0 and that the ARC line is absent)
  - `sysctl_call2.txt`: output of `/sbin/sysctl -i kern.cp_time dev.cpu.0.temperature dev.cpu.1.temperature dev.cpu.2.temperature dev.cpu.3.temperature`, taken about 0.5 s after call 1
  - `version_core.json`: contents of `/usr/local/opnsense/version/core`
  - `sysctl_call1_zfs.txt` (**synthetic**, marked in the README): the UFS capture with `v_free_count` lowered and one line `kstat.zfs.misc.arcstats.size: <bytes>` added, so that raw used memory is above 90% but used memory minus ARC is well below it (for the large-ARC test, Edge Case "Large ZFS cache")
  - `sysctl_call2_nosensor.txt` (**synthetic**): the call 2 capture with every `dev.cpu.N.temperature` line removed
  - `sysctl_call2_mixed.txt` (**synthetic**): the call 2 capture with different per-core temperatures (all four cores read the same value on the test box), for the maximum test

**Checkpoint**: Fixtures committed. Unit tests may start.

---

## Phase 2: Foundational

**Purpose**: The value object every story uses

- [ ] T005 Create `LIB/FirewallTelemetry.php`: a `final` class with public `readonly` constructor-promoted properties exactly as data-model.md §1, **without** `cpu_usage_percent` (the assembler computes it, R-08): `hostname`, `version` (`?string`); `last_boot`, `uptime_s`, `cpu_cores`, `memory_total`, `v_page_count`, `v_free_count`, `v_inactive_count`, `v_laundry_count`, `pagesize`, `disk_total`, `disk_free` (`?int`); `load_avg_1m`, `load_avg_5m`, `load_avg_15m`, `cpu_temperature` (`?float`); `cp_time_before`, `cp_time_after` (`?array` of five ints: user, nice, sys, intr, idle); `memory_arc_bytes` (`int`, default `0`, never null); `cpu_temperature_present` (`bool`). Add a static `allUnavailable(): self` that returns every nullable field `null`, ARC `0` and `cpu_temperature_present = false`. PHP 8.2-compatible syntax, no framework dependency

**Checkpoint**: Stories can start.

---

## Phase 3: User Story 1 - Checking the Firewall Itself First (Priority: P1) 🎯 MVP

**Goal**: A "Firewall" panel at the top of `/ui/casadelta/status` shows hostname, version, uptime, last boot, load 1/5/15, CPU %, memory used/total, disk used/total and the CPU temperature, read in-process on every status response, refreshed with the existing ~60 s poll and "Refresh now" button, and present with zero hosts or all hosts down.

**Independent Test**: On the test instance with slice 001 installed, open the status page and run `tests/integration/firewall_api.sh` plus quickstart M1–M6.

### Tests for User Story 1 (write first, confirm they fail)

- [ ] T006 [P] [US1] Create `tests/unit/FirewallTelemetryReaderTest.php` (happy path), injecting a fake runner (returns the T004 fixtures), a fake sleeper, a fixed clock, a fake file reader (returns `version_core.json`), fake disk functions, a fake `gethostname` and a fake marker check that finds no marker. Assert:
  - exactly two runner calls, in order. Call 1 argv is exactly `['/sbin/sysctl', '-i', <the 12 OIDs of R-03, in that order>]` with timeout `1.0`. Call 2 argv is exactly `['/sbin/sysctl', '-i', 'kern.cp_time', 'dev.cpu.0.temperature', …, 'dev.cpu.3.temperature']`, built from `hw.ncpu = 4`, timeout `1.0`
  - the sleeper is called once, with 500 ms, between the two calls
  - values are parsed **by name**: shuffle the fixture lines and get identical results
  - `kern.boottime` → `last_boot` = the `sec` value; `uptime_s` = clock − `last_boot`
  - `vm.loadavg` `{ a b c }` → three floats; `kern.cp_time` → five ints for `cp_time_before` (call 1) and `cp_time_after` (call 2)
  - `hw.physmem`, `hw.pagesize` and the four `vm.stats.vm.v_*` counts are parsed as ints; the UFS fixture gives `memory_arc_bytes = 0`; the ZFS fixture gives the ARC value
  - with `sysctl_call2_mixed.txt` (`67.0C`, `56.0C`, `61.0C`, `58.0C`) → `cpu_temperature` = `67.0`, the maximum as a float; `cpu_temperature_present = true`; `hw.acpi.thermal.*` is never requested (R-05)
  - `version` = `"OPNsense 26.7.7"` built from `CORE_PRODUCT` + `" "` + `CORE_VERSION` (R-07)
  - `hostname` comes from the injected `gethostname`; when that returns `false` or an empty string it falls back to the `kern.hostname` line (R-07)
  - `disk_total` / `disk_free` come from the injected disk functions for path `/`
- [ ] T007 [P] [US1] Create `tests/unit/FirewallStatusAssemblerTest.php` (happy path) with hand-built `FirewallTelemetry` objects and a fixed `now`. Assert:
  - `cpu_usage_percent` = `100 × (Δuser + Δnice + Δsys + Δintr) / Δtotal` from the two samples, clamped to 0..100; `null` when either sample is `null` or Δtotal is 0 (R-04)
  - UFS case: `memory_used = (v_page_count − v_free_count − v_inactive_count − v_laundry_count) × pagesize`; ZFS case: the same minus `memory_arc_bytes`; `memory_used` clamped to ≥ 0 when ARC is larger than the counted pages; `memory_used_percent = memory_used / memory_total × 100`, clamped to 0..100 (FR-005)
  - `disk_used = disk_total − disk_free`; `disk_used_percent` clamped to 0..100 (FR-006)
  - `last_boot` and `collected_at` are ISO 8601 UTC strings; `uptime_s` and `cpu_cores` pass through
  - the output keys are exactly those of the normal example in contracts/firewall-api.md, including `cpu_temperature` when `cpu_temperature_present` is `true`, `warnings` (an empty list for now) and `all_unavailable` (`false` for now)
- [ ] T008 [P] [US1] Create `tests/integration/firewall_api.sh` using `tests/integration/lib.sh` (`require_tools`, `refuse_production`, `require_env OPN_VIEW_KEY OPN_VIEW_SECRET`). Assert, for both `GET /api/casadelta/status/get` and `POST /api/casadelta/status/refresh`:
  - a `firewall` object is present next to `summary` and `groups`, with every key of contracts/firewall-api.md
  - `cpu_temperature` is present (this test box has `coretemp` loaded), `version` starts with `OPNsense `, `cpu_cores` ≥ 1
  - `warnings` is a subset of `memory`, `disk`, `load` and names no metric whose value is `null`
  - two GETs at least 1 s apart return different `collected_at` values (fresh in-process read, quickstart §3)
  - the response text contains none of `/tmp`, `/usr`, `/sbin`, `sysctl` (no paths or raw output)
  - when the instance has no enabled hosts (`summary.total_hosts = 0`), the `firewall` object is still present; otherwise print a skip notice for that check (M3 covers it manually)

### Implementation for User Story 1

- [ ] T009 [US1] Create `LIB/FirewallTelemetryReader.php` so T006 passes. The constructor takes optional seams (defaults in brackets): `$runner` (a default that runs `proc_open` with an **argv array**, no shell, reads stdout non-blocking until exit or the deadline, sends SIGKILL at the deadline and returns `null` on timeout or start failure), `$sleeper` (`usleep`), `$clock` (`time`), `$fileReader` (`@file_get_contents`), `$diskTotal` / `$diskFree` (`disk_total_space` / `disk_free_space`), `$hostnameFn` (`gethostname`), `$markerExists` (`file_exists`). `read(): FirewallTelemetry` runs call 1, sleeps 500 ms, runs call 2 with the temperature OIDs built from `hw.ncpu`, and parses each output **by name** (split on the first `": "`; ignore lines without it). Only fixed OID strings ever enter argv (R-03). Every value comes from the firewall's own OS, nothing is stored and no process outlives the request (FR-011, FR-015, FR-016). Depends on T005 and T006
- [ ] T010 [US1] Create `LIB/FirewallStatusAssembler.php` so T007 passes: a static `assemble(FirewallTelemetry $t, int $now): array` that returns the `firewall` object of contracts/firewall-api.md (R-08). For now `warnings` is `[]` and `all_unavailable` is `false`; US2 and US3 complete them. Depends on T005 and T007
- [ ] T011 [US1] Extend `APP/controllers/PRSWS/CasaDelta/Api/StatusController.php` (additive only, Decision 2):
  - add a private `readFirewall(): array` that runs `(new FirewallTelemetryReader())->read()` and `FirewallStatusAssembler::assemble(...)`, wrapped in `try { … } catch (\Throwable $e)`; on any exception return `FirewallStatusAssembler::assemble(FirewallTelemetry::allUnavailable(), time())` so the response is still 200 and hosts are unaffected (FR-013, FR-017)
  - in `getAction()` set `$body['firewall'] = $this->readFirewall()` after `buildStatus()` and before returning; in `refreshAction()` do the same before adding `refresh`
  - add the `use` lines for the three new classes; leave `buildStatus`, `loadHosts`, `readSnapshot` and `runCheck` byte-for-byte unchanged (R-11). Depends on T009 and T010
- [ ] T012 [US1] Extend `APP/views/PRSWS/CasaDelta/status.volt` per contracts/ui-firewall.md:
  - insert a `panel panel-default` with id `cd-firewall` and heading "Firewall" **between** `#cd-banner` and the summary row (FR-001, FR-002, FR-004); a read-only label/value grid with one element id per value (`cd-fw-hostname`, `cd-fw-version`, `cd-fw-uptime`, `cd-fw-lastboot`, `cd-fw-load`, `cd-fw-cpu`, `cd-fw-memory`, `cd-fw-disk`, `cd-fw-temp` inside a row `cd-fw-temp-row`), and a footer `cd-fw-asof` ("as of HH:MM:SS")
  - add `renderFirewall(fw)` and call it from the existing `render(data)` when `data.firewall` exists (no new timer and no new request, FR-010)
  - formatting helpers: uptime as `Xd Xh Xm Xs` (days omitted when zero); `last_boot` and `collected_at` with `toLocaleString()` / `toLocaleTimeString()` (FR-003); load `0.31 / 0.48 / 0.52` (2 decimals); CPU `12.5%` (1 decimal); memory and disk as `1.1 GiB / 15.6 GiB (7%)` (binary units, 1 decimal)
  - every value inserted with `.text()`, never as HTML; new strings wrapped in `lang._()`; GUI-bundled assets only. Depends on T011
- [ ] T013 [P] [US1] Add `firewall_api.sh` to the loop in `tests/integration/run.sh` (after `status_api.sh`) and update its header comment
- [ ] T014 [US1] Run `vendor/bin/phpunit` on the dev machine (all slice 001 suites must still pass). Build and install 0.2.0 on the **test instance** (quickstart §2: `rm -rf work; make package` in `/usr/plugins/net-mgmt/casadelta-dashboard`, never `make clean`; `pkg add -f …`; clear `/usr/local/opnsense/mvc/app/cache/*.php` if the page looks stale), run `tests/integration/run.sh`, then walk M1, M3, M4, M5, M6, M12, M13 (privilege reuse, FR-014), M15 and M16. Confirm SC-001: the first `status/get` returns in under 3 s. Record the results. Never run against production

**Checkpoint**: The Firewall panel works on the test instance with real values. This is the MVP.

---

## Phase 4: User Story 2 - Spotting a Problem at a Glance (Priority: P2)

**Goal**: Memory > 90%, disk > 90% and 5-minute load > core count are highlighted, and the highlight clears when the value returns under the threshold (FR-008, FR-009).

**Independent Test**: Unit tests T015, then quickstart M7–M9 on the test instance.

### Tests for User Story 2

- [ ] T015 [P] [US2] Extend `tests/unit/FirewallStatusAssemblerTest.php` with warning cases:
  - `memory_used_percent` exactly 90.0 → no warning; 90.01 → `memory`. Same pair for `disk`
  - `load_avg_5m` equal to `cpu_cores` → no warning; greater → `load`. The 1- and 15-minute values never warn
  - several metrics over their thresholds → each one listed; others unaffected (US2-AS5)
  - a `null` value never warns: `memory_used` `null` → no `memory`; `cpu_cores` or `load_avg_5m` `null` → no `load` (Edge Case)
  - **large ARC**: built from the `sysctl_call1_zfs.txt` numbers, raw used memory above 90% but used minus ARC below it → no `memory` warning (Edge Case "Large ZFS cache", SC-002)
  - `warnings` is always in the fixed order `memory`, `disk`, `load`
  - CPU usage and temperature never produce a warning (FR-008 lists only memory, disk and load)

### Implementation for User Story 2

- [ ] T016 [US2] Implement the warning rules in `LIB/FirewallStatusAssembler.php` as fixed class constants (90, 90, core count) so T015 passes; no configuration (FR-009). Depends on T010 and T015
- [ ] T017 [US2] Extend `APP/views/PRSWS/CasaDelta/status.volt`: for each of `memory`, `disk` and `load`, when the name is in `fw.warnings` add a `label label-warning` badge and a `cd-fw-warn` class (a tinted background defined in a small `<style>` block scoped to `#cd-firewall`); remove both when the name is absent. A cell showing "unavailable" is never highlighted (contracts/ui-firewall.md "Warning highlight"). Depends on T012 and T016
- [ ] T018 [US2] On the **test instance**, rebuild and reinstall, then walk M7 (disk, using a large file in `/` that you delete afterwards), M8 (load, with a bounded `yes > /dev/null` per core) and M9 (memory). This box is UFS, so M9 exercises only the no-ARC path; the large-ARC case stays covered by T015 (R-12). Record the results

**Checkpoint**: US1 and US2 work together on the test instance.

---

## Phase 5: User Story 3 - Unreadable Values Degrade Gracefully (Priority: P3)

**Goal**: Any unreadable value shows "unavailable" while the rest renders; if everything fails, a single discreet notice appears and clears on the next good read; the temperature row is absent, not "unavailable", when the hardware reports no sensor (FR-007, FR-012, FR-013, FR-016).

**Independent Test**: Unit tests T019–T020, then quickstart M2, M10, M11 and M14 plus T025 on the test instance.

### Tests for User Story 3

- [ ] T019 [P] [US3] Extend `tests/unit/FirewallTelemetryReaderTest.php` with failure cases:
  - call 1 returns `null` (timeout) → every call-1 value `null`; call 2 and the sleeper are **not** invoked; CPU samples `null`; `cpu_temperature_present = false`; version, disk and hostname still read (Decision 3). Same result when call 1 returns a non-zero exit code
  - call 1 succeeds without a `hw.ncpu` line → call 2 and the sleeper skipped (Decision 3)
  - call 2 returns `null` or a non-zero exit → `cp_time_after = null`, `cpu_temperature = null`, `cpu_temperature_present = false`; every call-1 value intact (Decision 4)
  - `sysctl_call2_nosensor.txt` → `cpu_temperature_present = false`, `cpu_temperature = null` (FR-007)
  - temperature lines present but unparseable (`abc`) → `cpu_temperature_present = true`, `cpu_temperature = null`
  - one OID line missing (for example `vm.loadavg`) → only those fields `null`
  - malformed lines (no `": "`) are ignored
  - version file missing, invalid JSON, or missing `CORE_PRODUCT` / `CORE_VERSION` → `version = null`
  - a disk function returning `false` → that disk field `null`
  - fault markers (R-09, checked once at the start of `read()` through the injected `$markerExists`): `_fault_sysctl` behaves like a call-1 timeout; `_fault_version` nulls only `version`; `_fault_disk` nulls only the disk fields; `_fault_all` nulls every value including `hostname` (Decision 5). No marker → no effect
  - the runner never receives anything except `/sbin/sysctl`, `-i` and fixed OID strings
- [ ] T020 [P] [US3] Extend `tests/unit/FirewallStatusAssemblerTest.php` with degradation cases:
  - `all_unavailable` is `true` only when every displayed value is `null` (hostname, version, uptime, last boot, the three loads, cores, CPU %, memory used/total, disk used/total; temperature excluded); one non-null value → `false` (FR-013)
  - `FirewallTelemetry::allUnavailable()` → `all_unavailable = true`, `warnings = []`
  - `cpu_temperature_present = false` → the `cpu_temperature` key is **absent** from the output; `true` with a `null` value → the key is present with `null` (FR-007)
  - any `null` memory input → `memory_used` and `memory_used_percent` `null`; `disk_total = 0` or `null` → `disk_used_percent` `null`

### Implementation for User Story 3

- [ ] T021 [US3] Implement the failure handling and the fault markers in `LIB/FirewallTelemetryReader.php` so T019 passes. Marker paths exactly `/tmp/casadelta_dashboard_fault_{sysctl,version,disk,all}`; the reader never creates or deletes them (they ship inactive, R-09). Depends on T009 and T019
- [ ] T022 [US3] Implement `all_unavailable`, the temperature-key omission and the null propagation in `LIB/FirewallStatusAssembler.php` so T020 passes. Depends on T016 and T020
- [ ] T023 [US3] Extend `APP/views/PRSWS/CasaDelta/status.volt`:
  - a `null` value renders the text "unavailable" in that cell only, leaving the other cells alone (FR-012)
  - `fw.all_unavailable` → hide the value grid and show `#cd-fw-notice` ("Firewall telemetry is currently unavailable."); heading and box stay; restore the grid on the next good response (FR-013)
  - no `cpu_temperature` key → hide `#cd-fw-temp-row`; key present but `null` → "unavailable" (FR-007)
  - on a failed request keep the last rendered panel; the existing `poll()` already skips `render()` on failure, so confirm that and do not blank the panel. Depends on T017 and T022
- [ ] T024 [P] [US3] Extend `tests/integration/firewall_api.sh` with an optional fault section that runs only when `OPN_SSH` (an ssh target for the **test instance**) is set, and prints a skip notice otherwise. It touches `/tmp/casadelta_dashboard_fault_version` and asserts `firewall.version = null` with the other values non-null, then `_fault_all` and asserts `all_unavailable = true` while `summary` and `groups` are still present. It removes the markers in a `trap` on exit. It refuses to run when `OPN_SSH` matches `PRODUCTION_HOST`
- [ ] T025 [US3] On the **test instance**, rebuild and reinstall, then walk M2, M10, M11 and M14. Then verify FR-007 on real hardware: `kldunload coretemp`, reload the page and confirm the temperature row is **absent** with no error, then `kldload coretemp` and confirm it returns. Confirm no stale marker files remain in `/tmp`. Record the results

**Checkpoint**: All three stories work on the test instance.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [ ] T026 Run `vendor/bin/phpunit` (every slice 001 and slice 002 suite green). Run `git diff main --stat` and confirm the only slice 001 files changed are those listed under Path Conventions; `check_hosts.php`, `actions_casadelta.conf` and the existing `LIB` classes are unchanged
- [ ] T027 Final Constitution Check against `.specify/memory/constitution.md` (Principles I–VIII): no new configd action, no daemon, no `/tmp` file written by the plugin (the markers are created only by a tester), no CDN or new library, `.text()` everywhere in the view, no paths, secrets or raw command output in API responses. Update plan.md Complexity Tracking only if a justified exception exists
- [ ] T028 On the **test instance**, touch all four fault markers, run `pkg delete os-casadelta-dashboard` and confirm the markers and every package file are gone and only `<PRSWS><CasaDelta>` remains in `/conf/config.xml` (M17). Reinstall 0.2.0 and record M1–M17 pass/fail in the release notes
- [ ] T029 Build the release package with `make package` on the test instance, check that the manifest contains only plugin files (no `tests/`, `tests/fixtures/`, `composer.*` or `vendor/`), then open the pull request. Production installation is done by José only, from that verified artifact (quickstart §5)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)** and **fixture capture (Phase 1b)**: no dependencies; T004 must finish before T006
- **Foundational (Phase 2)**: T005 blocks all stories
- **US1 (Phase 3)**: depends on Phase 2 and T004
- **US2 (Phase 4)**: depends on T010 (assembler exists) and T012 (panel exists)
- **US3 (Phase 5)**: depends on T009, T016 and T017 (it extends the reader, assembler and view)
- **Polish (Phase 6)**: depends on all stories

### Task-Level Dependencies

- T006 depends on T004. T009 depends on T005 and T006. T010 depends on T005 and T007
- T011 depends on T009 and T010. T012 depends on T011. T014 depends on T008, T012 and T013
- T016 depends on T010 and T015. T017 depends on T012 and T016. T018 depends on T017
- T021 depends on T009 and T019. T022 depends on T016 and T020. T023 depends on T017 and T022. T025 depends on T021, T023 and T024
- T026–T029 depend on T025

### Parallel Opportunities

- Setup: T001, T002 and T003 in parallel
- US1 tests T006, T007 and T008 in parallel (T006 after T004). T013 at any time in US1
- US2 test T015 can be written while US1's T011–T014 are in progress
- US3 tests T019, T020 and T024 in parallel

### Parallel Example: User Story 1

```text
Task: "T006 Create tests/unit/FirewallTelemetryReaderTest.php"
Task: "T007 Create tests/unit/FirewallStatusAssemblerTest.php"
Task: "T008 Create tests/integration/firewall_api.sh"
Then:
Task: "T009 Create LIB/FirewallTelemetryReader.php"
Task: "T010 Create LIB/FirewallStatusAssembler.php"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1, Phase 1b (fixtures from the test instance), Phase 2
2. Phase 3 (US1), then stop and validate on the test instance (T014)
3. The panel is already useful without warnings or degradation polish

### Incremental Delivery

1. US1: the panel with real values (MVP)
2. US2: warning highlights
3. US3: unavailable values, the all-unavailable notice, hidden temperature row
4. Polish: no regressions, constitution check, uninstall, package, pull request

### Notes for the Implementing Agent

- Implement one phase per session; commit and push after each phase so a crash loses at most one phase
- Keep each file write small: create a file, then add to it in further edits, instead of one very large write
- Do not stop to ask between tasks of a phase unless a task cannot be completed as written
- Mark each task `[X]` in this file when it is done

### Rules That Apply to Every Task

- Develop and test only on the test instance (192.168.1.1), never on the production firewall (Principle VIII)
- Do not edit OPNsense system files or core web server config; add no third-party library or build step (Principles I and VII)
- The plugin never writes firewall networking configuration (Principle II)

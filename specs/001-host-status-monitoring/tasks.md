---

description: "Task list for Host Status Monitoring (001-host-status-monitoring)"
---

# Tasks: Host Status Monitoring

**Input**: Design documents from `/specs/001-host-status-monitoring/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Included. Constitution Principle VIII requires automated tests for models, controllers and backend scripts where the framework allows, plus a manual checklist on the test VM (plan.md "Testing", research R-15). Unit tests (PHPUnit) cover the framework-free library classes on the dev machine. Integration tests (`curl`/`jq`) run against the **test VM only**, never production.

**Organization**: Tasks are grouped by user story. US1 (P1) status page, US2 (P2) host settings, US3 (P3) privilege separation.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1, US2 or US3. Setup, Foundational and Polish tasks carry no story label
- Every task names exact file paths

## Path Conventions

All paths are relative to the repository root. `PLUGIN` below means `net-mgmt/casadelta-dashboard/src/opnsense`, and `APP` means `net-mgmt/casadelta-dashboard/src/opnsense/mvc/app`.

- Models: `APP/models/PRSWS/CasaDelta/`
- Controllers: `APP/controllers/PRSWS/CasaDelta/` (API controllers in `Api/`)
- Views: `APP/views/PRSWS/CasaDelta/`
- Library (framework-free): `APP/library/PRSWS/CasaDelta/Lib/` (namespace `PRSWS\CasaDelta\Lib`)
- configd script: `PLUGIN/scripts/CasaDelta/check_hosts.php`
- configd action: `PLUGIN/service/conf/actions.d/actions_casadelta.conf`
- Unit tests: `tests/unit/`. Integration tests: `tests/integration/`

Fixed names (do not change): PHP vendor/module `PRSWS\CasaDelta`, main model `PRSWS\CasaDelta\CasaDelta` mounted at `//PRSWS/CasaDelta`, package `os-casadelta-dashboard`, URLs `/ui/casadelta/{status,settings}` and `/api/casadelta/{status,settings}/*`, ACL keys `page-casadelta-status` and `page-casadelta-settings`, configd action `casadelta check_hosts`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Plugin skeleton and dev-machine test tooling

- [X] T001 Create the plugin directory tree from plan.md "Source Code" under `net-mgmt/casadelta-dashboard/` (empty directories for `src/opnsense/mvc/app/{models/PRSWS/CasaDelta/{FieldTypes,ACL,Menu},controllers/PRSWS/CasaDelta/{forms,Api},library/PRSWS/CasaDelta/Lib,views/PRSWS/CasaDelta}`, `src/opnsense/scripts/CasaDelta`, `src/opnsense/service/conf/actions.d`) plus `tests/unit/` and `tests/integration/`
- [X] T002 [P] Create `net-mgmt/casadelta-dashboard/Makefile` using the `opnsense/plugins` Mk framework with `PLUGIN_NAME=casadelta-dashboard`, `PLUGIN_VERSION=0.1.0`, `PLUGIN_COMMENT=Casa Delta host status dashboard`, `PLUGIN_MAINTAINER` as a placeholder, and including `../../Mk/plugins.mk`
- [X] T003 [P] Create `net-mgmt/casadelta-dashboard/pkg-descr` with a short description, the line "Requires OPNsense 26.7 or later" (R-14), and a changelog entry for 0.1.0
- [X] T004 [P] Create `composer.json` at the repository root with `require-dev` containing only `phpunit/phpunit` and a PSR-4 `autoload-dev` mapping `PRSWS\\CasaDelta\\Lib\\` to `net-mgmt/casadelta-dashboard/src/opnsense/mvc/app/library/PRSWS/CasaDelta/Lib/`. No `require` entries (R-15, Principle VII)
- [X] T005 [P] Create `phpunit.xml.dist` at the repository root pointing at `tests/unit` with `tests/bootstrap.php`, and create `tests/bootstrap.php` that loads only `vendor/autoload.php` and the library directory (no OPNsense runtime)
- [X] T006 [P] Create `net-mgmt/casadelta-dashboard/+POST_DEINSTALL.post` that removes `/tmp/casadelta_dashboard_status.json`, `/tmp/casadelta_dashboard_status.json.tmp` and `/tmp/casadelta_dashboard_check.lock` (R-13). Mark it with a `VM-verify` comment: confirm the plugins `Mk` framework runs this hook; if not, switch to the documented `pkg-deinstall` mechanism

**Checkpoint**: Skeleton exists and `composer install && vendor/bin/phpunit` runs (zero tests is fine).

---

## Phase 1b: Test-VM spike

**Purpose**: Confirm OPNsense 26.7 assumptions on the test VM before any script depends on them. Must finish before T024.

- [x] T041 Verify the VM-verify assumptions from research.md (ping `-W` units and exit code 68, the MVC bootstrap include for CLI scripts, `+POST_DEINSTALL.post` execution). Also run a hello-world CLI script on the VM that does `require_once('script/load_phalcon.php');` and reads `//PRSWS/CasaDelta`, and record whether it loads the model and reads the node and update `specs/001-host-status-monitoring/research.md` with the confirmed outcome or the fallback actually used

**Checkpoint**: Assumptions confirmed or fallbacks chosen. T024 may start.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The main model, the menu and the privilege definitions that every story needs. No story work starts before this phase is complete.

- [X] T007 Create the main model definition `APP/models/PRSWS/CasaDelta/CasaDelta.xml`: `<mount>//PRSWS/CasaDelta</mount>`, `<version>1.0.0</version>`, container `hosts` with `host` as an `ArrayField`. Fields and constraints, quoted from data-model.md §1:
  - `enabled`: `BooleanField`, required, default `1`, "`0` or `1`".
  - `name`: `TextField`, required, "1–64 characters, trimmed, printable characters only" with message "Display name must be 1–64 printable characters.", plus a `UniqueConstraint` across `hosts.host` with message "A host with this display name already exists.".
  - `address`: `.\HostAddressField` (resolves to `PRSWS\CasaDelta\FieldTypes\HostAddressField`), required, IPv4, IPv6 or hostname optionally followed by a port per research R-04.
  - `group`: `TextField`, required, default `Network`, "1–32 characters, letters, digits, space, `-` and `_`" with message "Group must be 1–32 characters: letters, digits, space, - or _.".
  - `sort_order`: `IntegerField`, not required, default `0`, "Integer from −9999 to 9999" with message "Sort order must be an integer between -9999 and 9999.". Empty is stored as `0`.
  - `description`: `TextField`, not required, default empty, "0–255 characters, single line" with message "Description must be at most 255 characters.".
  - The model holds no secrets in this slice.
- [X] T008 [P] Create `APP/models/PRSWS/CasaDelta/CasaDelta.php`: `namespace PRSWS\CasaDelta; class CasaDelta extends \OPNsense\Base\BaseModel {}` (thin, no logic)
- [X] T009 [P] Create `APP/models/PRSWS/CasaDelta/ACL/ACL.xml` defining exactly two privileges (contracts/acl-menu.md): `page-casadelta-status` named "Casa Delta: Host Status (View)" with patterns `ui/casadelta/status*` and `api/casadelta/status/*`, and `page-casadelta-settings` named "Casa Delta: Host Settings (Manage)" with patterns `ui/casadelta/settings*` and `api/casadelta/settings/*`. Neither privilege may include the other's patterns
- [X] T010 [P] Create `APP/models/PRSWS/CasaDelta/Menu/Menu.xml` with a top-level "Casa Delta" section and two entries, "Host Status" (`/ui/casadelta/status`) and "Host Settings" (`/ui/casadelta/settings`), per contracts/acl-menu.md
- [X] T011 [P] Create `PLUGIN/service/conf/actions.d/actions_casadelta.conf` with the `[check_hosts]` action exactly as in contracts/configd-check-hosts.md (`command:/usr/local/opnsense/scripts/CasaDelta/check_hosts.php`, `parameters:%s`, `type:script_output`, `message:Casa Delta host status check`)
- [X] T013 [P] Create unit test `tests/unit/HostAddressTest.php` (written first; it must fail before T012 exists). Cover every row of the "Validation matrix" table in `contracts/settings-api.md` (source of truth), asserting the exact accepted/rejected result and message for each row, plus every example in data-model.md §2 (`192.168.1.1`, `192.168.1.10:8006`, `fe80::1`, `[2001:db8::1]:8006`, `pve.lan:8006`, rejected `192.168.1` and `pve.lan:0`), plus port 65535 accepted and 65536 rejected. Confirm it fails, then passes once T012 is implemented
- [X] T012 Create the address parser `APP/library/PRSWS/CasaDelta/Lib/HostAddress.php` implementing research R-04: `[v6]:port`; a bare IPv6 (two or more colons, no brackets) means no port (`fe80::1:8006` is an IPv6 address); `host:port` for IPv4 and hostnames; port 1–65535; attributes `raw`, `host`, `port`, `kind` (`ipv4`|`ipv6`|`hostname`) per data-model.md §2. Provide a static `parse(string): HostAddress` that throws or returns an error carrying the specific message for every rejected row of the "Validation matrix" table in `contracts/settings-api.md`, which is the source of truth (currently: "Invalid IPv4 address.", "Port must be between 1 and 65535." including leading-zero ports such as `pve.lan:08006`, "Only IPv6 addresses may be bracketed.", "Invalid hostname.", "Enter a host, not a URL.", "IPv6 zone identifiers are not supported.", "Address is required."). Implement every message in that table, and re-read the table before finishing in case it changed. No framework dependency. Depends on T013
- [X] T014 Create the custom field type `APP/models/PRSWS/CasaDelta/FieldTypes/HostAddressField.php` (`namespace PRSWS\CasaDelta\FieldTypes`, extends `OPNsense\Base\FieldTypes\BaseField`) whose validator calls `PRSWS\CasaDelta\Lib\HostAddress` and returns the parser's error message as the validation message. Depends on T012

**Checkpoint**: Model, field type, ACL, menu, configd action definition and address parser exist. User stories can start.

---

## Phase 3: User Story 1 - Viewing Live Host Status Dashboard (Priority: P1) 🎯 MVP

**Goal**: A viewer opens `/ui/casadelta/status` and sees every enabled host grouped (Network → Nodes → Apps → custom A–Z), ordered by sort order then name, with state (up / down / cannot resolve), latency, last-check time, a "X up out of Y" summary, a 60 s self-refresh and a rate-limited "Refresh now" button. Checks run only while the page is viewed and are shared between viewers. A broken check mechanism shows a banner, not all-down.

**Independent Test**: With a few enabled hosts seeded in the model on the test VM (via `configctl`-free means such as the Settings API from US2 or a hand-seeded test `config.xml`), open the status page and run `tests/integration/status_api.sh` and the quickstart status checklist items. This story does not require the settings UI.

### Tests for User Story 1

- [X] T015 [P] [US1] Create `tests/unit/HostOrderingTest.php` for `HostOrdering`: sort key `(groupRank, groupRank = 3 ? lower(group) : "", sort_order, lower(name), uuid)` with `network → 0, nodes → 1, apps → 2, anything else → 3` (case-insensitive); custom groups A–Z; default sort order 0 with name tie-break; negative sort orders first
- [X] T016 [P] [US1] Create `tests/unit/PingResultParserTest.php` for `PingResultParser` using the state rules in data-model.md §3: exit 0 → `up` with average RTT (2 decimals) from the `round-trip min/avg/max` line; exit 2 → `down`, latency null; exit 68 or "cannot resolve"/"Unknown host" in stderr → `cannot_resolve`; killed at deadline or other exit code → `down` with `error_message` "probe timed out" or similar; `latency_ms` non-null only when `up`
- [X] T017 [P] [US1] Create `tests/unit/SnapshotFreshnessTest.php` for `SnapshotFreshness`: `fresh ⇔ ok ∧ schema = 1 ∧ (now − finished_at) < 55 s ∧ fingerprint = currentFingerprint`; `cooldown ⇔ (now − finished_at) < 5 s`; boundary cases at 54 s, 55 s, 4 s and 5 s; unknown schema is not fresh; a different fingerprint is not fresh; fingerprint helper hashes the sorted `uuid|address` pairs of enabled hosts (SHA-256 hex)
- [X] T018 [P] [US1] Create `tests/unit/StatusAssemblerTest.php` for `StatusAssembler`: joins snapshot results to current config by UUID; enabled hosts missing from the snapshot become `pending` (never counted up or down); disabled hosts are excluded and not counted; summary counts `total_hosts`, `up_hosts`, `down_hosts`, `unresolved_hosts`, `pending_hosts`; `next_check_at = finished_at + 60 s`; on `ok = false` or unreadable snapshot set `subsystem_status = "error"` with a non-sensitive message and mark every result `stale: true` without turning any host `down`; empty config gives `total_hosts = 0`, `groups = []`, status `ok`; output groups come out in `HostOrdering` order
- [X] T019 [P] [US1] Create `tests/integration/status_api.sh` (curl + jq against the test VM, base URL and API key/secret from environment variables, never hard-coded). It asserts: `GET /api/casadelta/status/get` returns `summary` and `groups`; `groups` and hosts are in display order; disabled hosts are absent; `latency_ms` is non-null only for `up`; two requests within 55 s do not change `summary.last_run`; `POST /api/casadelta/status/refresh` returns `refresh.performed` and `refresh.retry_after_s`; a second refresh within 5 s returns `performed = false` with `retry_after_s` between 1 and 5; `GET` on refresh and `POST` on get return `{"status":"failed"}` with no check executed

### Implementation for User Story 1

- [X] T020 [P] [US1] Implement `APP/library/PRSWS/CasaDelta/Lib/HostOrdering.php` per data-model.md §6 so T015 passes
- [X] T021 [P] [US1] Implement `APP/library/PRSWS/CasaDelta/Lib/PingResultParser.php` per data-model.md §3 so T016 passes. Parse from an exit code, stdout and stderr passed in, with no process execution inside the class
- [X] T022 [P] [US1] Implement `APP/library/PRSWS/CasaDelta/Lib/SnapshotFreshness.php` per data-model.md §4 so T017 passes (55 s TTL, 5 s cooldown, fingerprint helper)
- [X] T023 [US1] Implement `APP/library/PRSWS/CasaDelta/Lib/StatusAssembler.php` per data-model.md §3, §5 and §6 and contracts/status-api.md so T018 passes. Depends on T020 and T022
- [X] T024 [US1] Implement the configd script `PLUGIN/scripts/CasaDelta/check_hosts.php` per contracts/configd-check-hosts.md and research R-06 to R-10:
  - Load the model through the MVC bootstrap (`require_once('script/load_phalcon.php');`, VM-verify per R-06; fallback `OPNsense\Core\Config::getInstance()->object()` reading `//PRSWS/CasaDelta/hosts`) and read only enabled hosts. Read-only: never write `config.xml`.
  - Parse each address with `HostAddress` and probe `host` only (strip the port); `-4` / `-6` / no flag from `kind`.
  - Exactly 2 probes per host using the exact ping command from research R-10, `/sbin/ping -c 2 -i 0.5 -W 1000 -t 3 [-4|-6] <target>`, via `proc_open` with an argv array (no shell), SIGKILL at a 4 s per-process deadline, at most 16 parallel pings, 20 s whole-run cap (unfinished hosts reported `down` with "probe timed out").
  - Take `flock` on `/tmp/casadelta_dashboard_check.lock`, re-check freshness and the 5 s global cooldown under the lock (argument `force` skips the freshness test but not the cooldown), write `/tmp/casadelta_dashboard_status.json` via a `.tmp` file and `rename()`.
  - Print one JSON document including `performed` and `retry_after_s` (not saved in the file). On internal failure print `{"schema":1,"ok":false,"error":"…","results":{…previous results…}}` and exit 0.
  - One syslog line per run (`casadelta-dashboard: checked N hosts in X ms`), no per-host lines. No daemon. Depends on T012, T021 and T022
- [X] T025 [US1] Implement `APP/controllers/PRSWS/CasaDelta/Api/StatusController.php` (`namespace PRSWS\CasaDelta\Api`, extends `OPNsense\Base\ApiControllerBase`) per contracts/status-api.md:
  - `getAction` (GET only): load enabled hosts, compute fingerprint, use the snapshot if fresh with no configd call; otherwise run `configctl casadelta check_hosts` with a 30 s timeout. If there are no enabled hosts, never call configd.
  - `refreshAction` (POST only): run `configctl casadelta check_hosts force` and add `refresh.performed` and `refresh.retry_after_s`.
  - Wrong method returns `{"status":"failed"}` with no check run. A configd error, timeout or non-JSON output yields `subsystem_status = "error"` with last known results marked stale, never `down`.
  - No secrets, paths or raw command output in responses. Depends on T023 and T024
- [X] T026 [P] [US1] Create the UI controller `APP/controllers/PRSWS/CasaDelta/StatusController.php` (extends `OPNsense\Base\IndexController`, `indexAction` picks the view `PRSWS/CasaDelta/status`) serving `/ui/casadelta/status`
- [X] T027 [US1] Create the view `APP/views/PRSWS/CasaDelta/status.volt` per contracts/ui-pages.md:
  - Use only GUI-bundled assets (jQuery, Bootstrap, `ajaxGet`, `ajaxCall`). No CDN or remote font (FR-017).
  - Render the API response exactly in the order received, one table or panel per group, with state label (up / down / cannot resolve / pending), latency, last-check time and the configured address (including any port) shown as-is.
  - Summary line `"{up_hosts} up out of {total_hosts} hosts"`.
  - Poll `GET /api/casadelta/status/get` and schedule the next poll from `summary.next_check_at`, about every 60 s, with no full reload.
  - "Refresh now" button using `ajaxCall` POST to refresh, disabled for `max(5, retry_after_s)` seconds with a countdown.
  - Warning banner when `subsystem_status = "error"`, with stale rows visibly marked.
  - Insert text with `.text()` or an equivalent, never as HTML. Show an empty-state message when `total_hosts = 0`. Depends on T025 and T026
- [ ] T028 [US1] Run `vendor/bin/phpunit` locally and confirm T013 and T015–T018 pass. Then build and install the package on the **test VM** following quickstart.md §2–§3 and run `tests/integration/status_api.sh` (T019) against it. Record results in the pull request or notes. Never run against production

**Checkpoint**: US1 is fully functional and demoable on the test VM with seeded hosts.

---

## Phase 4: User Story 2 - Managing Monitored Hosts in Firewall Configuration (Priority: P2)

**Goal**: An administrator adds, edits, disables and deletes monitored hosts on `/ui/casadelta/settings`. Display names are unique, addresses are validated at save time with a clear message, `sort_order` defaults to 0, and everything is stored in the firewall configuration (survives reboot, firmware upgrade, backup and restore).

**Independent Test**: With US2 alone, run `tests/integration/settings_crud.sh` against the test VM and the settings items of the quickstart checklist. Verify add, edit, toggle, delete, duplicate-name rejection and invalid-address rejection, and that the entries appear in `config.xml` under `<PRSWS><CasaDelta>`.

### Tests for User Story 2

- [X] T029 [P] [US2] Create `tests/integration/settings_crud.sh` (curl + jq against the test VM). It asserts: `addHost` with valid data returns `result = "saved"` and a UUID; `searchHost` lists it; `getHost/{uuid}` returns the stored values and `getHost` with no UUID returns blank defaults (`enabled = 1`, `group = Network`, `sort_order = 0`); `setHost/{uuid}` updates it; `toggleHost/{uuid}/0` and `/1` flip `enabled`; duplicate `name` is rejected with "A host with this display name already exists."; invalid addresses are rejected with the specific messages for `192.168.1`, `pve.lan:0`, `pve.lan:65536` and `pve.lan:08006` (leading-zero port); the valid forms `192.168.1.10:8006`, `pve.lan:8006` and `[2001:db8::1]:8006` are accepted; a missing `sort_order` is stored as `0`; `delHost/{uuid}` removes it. Clean up every host it creates

### Implementation for User Story 2

- [X] T030 [P] [US2] Create the dialog definition `APP/controllers/PRSWS/CasaDelta/forms/dialogHost.xml` with fields `enabled` (checkbox), `name`, `address`, `group` (with suggestions `Network`, `Nodes`, `Apps`), `sort_order`, `description`, each id `host.<field>` matching the model path and with help text (address help mentions the optional port)
- [X] T031 [US2] Implement `APP/controllers/PRSWS/CasaDelta/Api/SettingsController.php` (`namespace PRSWS\CasaDelta\Api`, extends `OPNsense\Base\ApiMutableModelControllerBase`, `$internalModelName = 'casadelta'`, `$internalModelClass = 'PRSWS\CasaDelta\CasaDelta'`) per contracts/settings-api.md. Actions: `searchHostAction` (`searchBase('hosts.host', …)`), `getHostAction($uuid = null)`, `addHostAction`, `setHostAction($uuid)`, `delHostAction($uuid)`, `toggleHostAction($uuid, $enabled = null)`. No service reload (no `ServiceController`). Depends on T007, T008 and T014
- [X] T032 [P] [US2] Create the UI controller `APP/controllers/PRSWS/CasaDelta/SettingsController.php` (extends `OPNsense\Base\IndexController`; `indexAction` assigns `formDialogHost` via `getForm('dialogHost')` and picks the view `PRSWS/CasaDelta/settings`) serving `/ui/casadelta/settings`
- [X] T033 [US2] Create the view `APP/views/PRSWS/CasaDelta/settings.volt` per contracts/ui-pages.md using only `UIBootgrid` and `base_dialog`: grid columns enabled (toggle), name, address, group, sort order, description, with add / edit / delete / toggle commands against `/api/casadelta/settings/*`. Server validation messages must show in the dialog. Include a link or note that disabled hosts are not shown on the status page. Depends on T030, T031 and T032
- [ ] T034 [US2] Build and install on the **test VM** and run `tests/integration/settings_crud.sh` (T029). Confirm in `/conf/config.xml` on the VM that the entries sit under `<PRSWS><CasaDelta><hosts><host uuid="…">`, and that a status page refresh (US1) shows an added host and drops a disabled or deleted host within one refresh cycle. Never run against production

**Checkpoint**: US1 and US2 both work independently and together.

---

## Phase 5: User Story 3 - Role-Based Access Control & Privilege Separation (Priority: P3)

**Goal**: Viewing status and changing configuration are separate privileges. A read-only account can use the status page and status API but cannot see or call anything under settings.

**Independent Test**: With test users on the VM holding only `page-casadelta-status`, only `page-casadelta-settings`, both, and neither, run `tests/integration/acl_matrix.sh` and verify the matrix in contracts/acl-menu.md.

### Tests for User Story 3

- [X] T035 [P] [US3] Create `tests/integration/acl_matrix.sh` (curl against the test VM, with four API key/secret pairs read from environment variables for the view-only, settings-only, both and neither users). It asserts the access matrix from contracts/acl-menu.md for `GET /ui/casadelta/status`, `GET /ui/casadelta/settings`, `GET /api/casadelta/status/get`, `POST /api/casadelta/status/refresh`, and the settings endpoints `searchHost`, `addHost`, `setHost`, `delHost`, `toggleHost` (200 where privileged, 403 otherwise). A view-only account MUST get 403 on every settings endpoint and a settings-only account MUST get 403 on every status endpoint

### Implementation for User Story 3

- [X] T036 [US3] Verify `APP/models/PRSWS/CasaDelta/ACL/ACL.xml` and `Menu/Menu.xml` (T009, T010) against contracts/acl-menu.md. Fix any pattern that lets one privilege reach the other's pages or endpoints. Confirm the menu items carry the matching `page-casadelta-*` visibility so OPNsense hides items the user cannot use. Confirm no controller action performs its own privilege logic or bypasses the ACL layer
- [ ] T037 [US3] On the **test VM**, create the four test users and groups (quickstart.md "test users"), assign the privileges, run `tests/integration/acl_matrix.sh` (T035) and walk the quickstart privilege checklist item (M12). Confirm a view-only user sees only "Host Status" in the menu and cannot change any data. Never run against production

**Checkpoint**: All three stories work independently on the test VM.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Constitution gates, resource guarantees and release readiness

- [X] T038 [P] Create `tests/integration/run.sh` that runs `status_api.sh`, `settings_crud.sh` and `acl_matrix.sh` in order against the test VM and exits non-zero on the first failure. It must refuse to run if the target URL matches a value in an optional `PRODUCTION_HOST` environment variable (Principle VIII guard)
- [ ] T039 Verify the resource guarantees in contracts/configd-check-hosts.md on the test VM with about 50 enabled hosts: a cold check finishes in about 5 s typically and never over 20 s, at most 16 parallel pings, no process remains after the run, CPU stays under 2 %, and 10 concurrent viewers cause at most one probe run per 60 s (SC-003–SC-005)
- [ ] T040 Verify the failure paths on the test VM: stop or break `configd` (or make the script fail) and confirm the status page shows the warning banner with stale results and no host turns `down`. Verify an unresolvable hostname shows "cannot resolve", not "down" (FR-007, FR-014)
> T041 (VM-verify assumptions) moved to Phase 1b: Test-VM spike.
- [ ] T042 Run the full manual checklist M1–M17 from `specs/001-host-status-monitoring/quickstart.md` on the test VM, including M13 (works with LAN hosts and DNS down), M15 and M16 (configuration backup and restore) and M17 (after uninstall, only `<PRSWS><CasaDelta>` remains in `config.xml`). Record pass or fail per item
- [X] T043 Run a final Constitution Check against `.specify/memory/constitution.md` (Principles I–VIII): no standalone web root or edits to system files, read-only toward networking config, no CDN assets, no `.env` or secrets, separate privileges, no new libraries or build steps. Update the plan's Complexity Tracking only if a justified exception exists
- [ ] T044 Build the release package with `make package` on the test VM, verify the package manifest contains only plugin files and no tests, `composer.json`, or `vendor/`, and only then hand the verified package over for production installation

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies. Start immediately
- **Test-VM spike (Phase 1b)**: Depends on Setup. T041 must complete before T024
- **Foundational (Phase 2)**: Depends on Setup. **Blocks all user stories**
- **US1 (Phase 3)**: Depends on Foundational. Needs hosts in the model for a meaningful demo, seeded on the VM or added through US2
- **US2 (Phase 4)**: Depends on Foundational only. Independent of US1
- **US3 (Phase 5)**: Depends on Foundational for the ACL and menu, and on the endpoints from US1 and US2 existing to run the full matrix
- **Polish (Phase 6)**: Depends on all stories being complete

### Within Each Story

- Unit tests are written first and must fail before the matching library class is implemented
- Library classes, then the configd script, then API controllers, then UI controller and view, then the VM verification task

### Task-Level Dependencies

- T012 depends on T013 (tests first). T014 depends on T012
- T023 depends on T020, T022. T024 depends on T012, T021, T022, T041. T025 depends on T023, T024
- T027 depends on T025, T026. T028 depends on T019, T027
- T031 depends on T007, T008, T014. T033 depends on T030, T031, T032. T034 depends on T029, T033
- T037 depends on T035 and on US1 and US2 being installed

### Parallel Opportunities

- Setup: T002–T006 in parallel after T001
- Foundational: T008, T009, T010, T011, T013 in parallel after T007. T012 follows T013
- US1 tests T015–T019 in parallel. Library classes T020, T021, T022 in parallel. T026 in parallel with T024 and T025
- US2: T029, T030 and T032 in parallel
- US2 can be built in parallel with US1 by a second developer after Phase 2

### Parallel Example: User Story 1

```text
Task: "T015 Create tests/unit/HostOrderingTest.php"
Task: "T016 Create tests/unit/PingResultParserTest.php"
Task: "T017 Create tests/unit/SnapshotFreshnessTest.php"
Task: "T018 Create tests/unit/StatusAssemblerTest.php"
Then:
Task: "T020 Implement HostOrdering.php"
Task: "T021 Implement PingResultParser.php"
Task: "T022 Implement SnapshotFreshness.php"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup, then Phase 2 Foundational
2. Phase 3 (US1), then stop and validate on the test VM with seeded hosts (T028)
3. Demo the status page. This is the dashboard's most-used feature

Note: without US2 the host list can only be seeded by hand on the test VM, so in practice deliver US2 right after US1 to get a usable product.

### Incremental Delivery

1. Setup + Foundational: model, ACL, menu, parser ready
2. US1: status page works (MVP)
3. US2: hosts managed from the GUI
4. US3: privilege separation verified
5. Polish: resource, failure, backup and uninstall checks, then package for production

### Rules That Apply to Every Task

- Develop and test only on a non-production OPNsense VM (Principle VIII)
- Do not edit OPNsense system files or core web server config. Do not add third-party libraries or build steps (Principles I and VII)
- The plugin never writes firewall networking configuration (Principle II)

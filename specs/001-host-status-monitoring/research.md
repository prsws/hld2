# Research: Host Status Monitoring

**Feature**: `001-host-status-monitoring` | **Date**: 2026-10-14 | **Plan**: [plan.md](./plan.md)

This document resolves every open item from the plan's Technical Context. Each entry records the
decision, the rationale, and the alternatives considered. Items tagged **VM-verify** are design
assumptions about OPNsense 26.7 internals. They MUST be confirmed on the test VM during the first
implementation task (Principle VIII). If one turns out wrong, the stated fallback applies.

---

## R-01 Plugin packaging and repository layout

- **Decision**: Lay out the repository like the `opnsense/plugins` tree, with the plugin at
  `net-mgmt/casadelta-dashboard/` (package name `os-casadelta-dashboard`). It holds `Makefile`,
  `pkg-descr` and `src/`, and `src/` mirrors the installed filesystem under `/usr/local/`. Build the
  package on the test VM (or a FreeBSD 15 build host) by placing the directory inside a checkout of
  `opnsense/plugins` and running `make package`. For fast iteration on the test VM, use
  `make install` / `make upgrade`.
- **Rationale**: This matches the official plugin conventions (Constitution, Technical
  Constraints) and produces a standard `pkg` that installs and uninstalls cleanly (Principle I).
- **Alternatives considered**: A custom tarball plus an install script (rejected: it edits system
  files directly and leaves residue). A standalone web root (prohibited by Principle I).

## R-02 MVC namespace, module name and URL routing

- **Decision**: Follow the OPNsense convention of vendor = company, module = product, main model
  named after the module. Vendor directory `PRSWS` and module `CasaDelta` give PHP namespace
  `PRSWS\CasaDelta` and URL module `casadelta`:
  - Directories: `models/PRSWS/CasaDelta`, `controllers/PRSWS/CasaDelta`,
    `views/PRSWS/CasaDelta`, `library/PRSWS/CasaDelta/Lib`.
  - UI: `/ui/casadelta/status`, `/ui/casadelta/settings`
  - API: `/api/casadelta/status/*`, `/api/casadelta/settings/*`
  - Model class: `PRSWS\CasaDelta\CasaDelta` (`CasaDelta.php` + `CasaDelta.xml`, as named in
    FR-001), mounted at `//PRSWS/CasaDelta`. The host list is its `hosts.host` container.
  - Custom field type: `PRSWS\CasaDelta\FieldTypes\HostAddressField`.
  - Unchanged: package `os-casadelta-dashboard`, plugin dir `net-mgmt/casadelta-dashboard`,
    configd action `casadelta check_hosts`, `/tmp` file names and menu labels.
- **Rationale**: The OPNsense router resolves `/{ui|api}/{module}/{controller}/{action}` across all
  vendor directories under `mvc/app/controllers/`, so third-party vendor namespaces are supported.
  The config mount path is the one name that can't change later without a config migration, so it
  is fixed now to a product-specific name. `casadelta` is specific enough not to collide with
  another installed module, unlike a generic `dashboard`. One main model per product lets later
  slices (firewall status, WAN/VPN IP, etc.) add their own containers next to `hosts` in the same
  model, instead of creating new mount points.
- **Alternatives considered**: `OPNsense\CasaDelta` (rejected: the `OPNsense` vendor namespace
  is reserved for upstream code). Vendor `CasaDelta` with module `Dashboard` and model `Host`
  (rejected: `dashboard` is a generic module name likely to collide, and a host-specific model
  would force each later slice to add its own mount point).

## R-03 Language, runtime and frameworks

- **Decision**: PHP 8.x as shipped with OPNsense 26.7, written to a PHP 8.2-compatible syntax
  subset (readonly properties, enums and first-class callables are allowed, nothing newer). The
  framework is OPNsense MVC (`OPNsense\Base\BaseModel`, `ApiControllerBase`,
  `ApiMutableModelControllerBase`, `IndexController`, Volt views), plus configd for backend
  actions. Frontend: jQuery, Bootstrap and OPNsense JS helpers (`ajaxGet`, `ajaxCall`,
  `UIBootgrid`, `mapDataToFormUI`, `saveFormToEndpoint`), all already shipped by the base GUI.
- **Rationale**: These are the only runtimes available on the appliance without adding packages
  (Principles I, VII). The backend check script is written in PHP too, so the plugin uses one
  language and the script can reuse the plugin's model and address parser.
- **Alternatives considered**: A Python 3 backend script (rejected: it would duplicate address
  parsing and config reading in a second language). Node or other runtimes (not on the appliance).

## R-04 Host address validation (IPv4 / IPv6 / hostname + optional port)

- **Decision**: Add a framework-free value parser `PRSWS\CasaDelta\Lib\HostAddress` (in
  `mvc/app/library/PRSWS/CasaDelta/Lib/`), wrapped by a custom model field type
  `PRSWS\CasaDelta\FieldTypes\HostAddressField` that returns the parser's error message as the
  validation message. Rules:
  1. `[v6]:port`: an IPv6 literal in brackets, then `:` and a port.
  2. A bare IPv6 literal (two or more colons, no brackets) means there is no port. `fe80::1:8006`
     is read as an IPv6 address, never as address plus port.
  3. `ipv4[:port]` is checked with `filter_var(..., FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)`.
  4. `hostname[:port]`: RFC 1123 labels (1–63 characters, `[A-Za-z0-9-]`, no leading or trailing
     hyphen), at most 253 characters, optional trailing dot, single-label names (e.g. `pve`)
     allowed. If every label is numeric, the value must be a valid IPv4 address, so `192.168.1`
     and `999.1.1.1` are rejected.
  5. The port, when present, must be decimal 1–65535 without leading zeros.
  6. IPv6 zone IDs (`%igb0`), URLs and whitespace are rejected in this slice, with explicit
     messages.
- **Rationale**: No single built-in OPNsense field type accepts "IP or hostname, with an optional
  port". Keeping the logic in a pure class lets it run under PHPUnit on the dev machine with no
  appliance (Principle VIII, automated tests), and the check script reuses it to strip the port.
- **Alternatives considered**: A `TextField` with one large regex `Mask` (rejected: unreadable, and
  it can't produce specific error messages). Separate `address` and `port` fields (rejected: the
  spec defines a single combined address field).

## R-05 Display-name uniqueness

- **Decision**: Use the built-in `UniqueConstraint` on `hosts.host.name`, with a custom
  `ValidationMessage` ("A host with this display name already exists."). Comparison is exact.
- **Rationale**: Principle VII prefers platform primitives. The constraint runs inside
  `ApiMutableModelControllerBase::addBase/setBase` validation, so the error appears next to the
  field in the dialog.
- **Alternatives considered**: A custom case-insensitive constraint (deferred: not required by the
  spec, and it adds code).

## R-06 Check execution path (on demand, no daemon)

- **Decision**: Use a configd action `casadelta check_hosts [force]`, defined in
  `service/conf/actions.d/actions_casadelta.conf` with `type:script_output`, which runs
  `/usr/local/opnsense/scripts/CasaDelta/check_hosts.php`. The status API calls it with
  `(new \OPNsense\Core\Backend())->configdRun('casadelta check_hosts', false, 30)` only when the
  shared snapshot is stale, or when the user asks for a manual refresh. configd already runs on
  every OPNsense install, so the plugin adds no daemon.
- **Rationale**: Satisfies FR-011 and Principle II: there is no persistent process, and checks run
  only while somebody requests status. configd runs actions as root, which allows sub-second ping
  intervals and raw ICMP.
- **VM-verify**: Confirm the bootstrap include for MVC models in CLI scripts
  (`require_once('script/load_phalcon.php');`, which keeps its historic name on current releases).
  Fallback: read `//PRSWS/CasaDelta/hosts` through `OPNsense\Core\Config::getInstance()->object()`.
- **Alternatives considered**: A cron job every minute (rejected: it runs when nobody is watching,
  against the "checks run only while viewed" requirement). A persistent poller daemon (prohibited
  without justification, and not needed). The configd built-in `cache_ttl` (rejected: it can't be
  bypassed for the manual refresh and can't be invalidated on config changes).

## R-07 Shared result cache, deduplication and locking

- **Decision**: Use one shared snapshot file, `/tmp/casadelta_dashboard_status.json`, written
  atomically (temp file + `rename()`) by the check script. The script serializes runs with
  `flock(LOCK_EX)` on `/tmp/casadelta_dashboard_check.lock` and re-checks freshness after
  acquiring the lock (double-checked locking). If several viewers hit a stale cache at the same
  moment, they queue on the lock; the first one runs the check and the others just reuse its
  result.
  - **Fresh**: `now - finished_at < 55 s` **and** the snapshot `fingerprint` matches the current
    config. The fingerprint is a SHA-256 over the sorted `uuid|address` pairs of the enabled hosts.
  - The status API reads the snapshot directly (fast path, no configd call). It goes through
    configd only when the snapshot is stale, missing, unreadable or has a fingerprint mismatch.
  - The API always takes host metadata (name, group, sort order, enabled) from the **current**
    config and joins results by UUID. Renames, re-sorts and disables show up at once without a
    re-check, and disabled hosts are never shown (FR-015, US2-AS4).
- **Rationale**: Meets FR-012 and SC-005 (one check per 60 s window for any number of viewers) and
  SC-003 (cached render well under 2 s). `/tmp` is outside every web-served path (Principle IV) and
  is cleared on reboot. A package de-install hook removes the files (R-13).
- **Alternatives considered**: APCu or shared memory (not guaranteed to be enabled, and harder to
  inspect). Storing results in `config.xml` (rejected: it would write firewall config on every
  check and pollute backups).

## R-08 Refresh cadence that meets both SC-004 and SC-005

- **Problem**: With fixed 60 s client timers and a cache TTL, the worst-case delay before a state
  change is visible is about 60 s + TTL. Keeping it within 60 s needs a small TTL, but a small TTL
  breaks "≤ 1 check per 60 s for 10 viewers".
- **Decision**: Use server-guided polling. Each status response includes `next_check_at`
  (`finished_at + 60 s`). Clients schedule their next poll for
  `clamp(next_check_at - now + 2 s, 30 s, 65 s)`, falling back to 60 s if the field is missing.
  All viewers therefore line up behind the same check boundary: the first poll after the boundary
  triggers the run, and the others get the brand-new snapshot. The interval stays "about 60 s",
  results are seconds old rather than up to a TTL old, and only one check runs per window. The
  freshness TTL (55 s) is kept slightly below the 60 s cycle so a lone viewer always gets a new
  check on each poll.
- **Rationale**: Satisfies FR-010, SC-004 (60 s ± 5 s) and SC-005 together, without a daemon.
- **Alternatives considered**: A fixed 60 s timer with a 30 s TTL (rejected: two to three checks
  per minute with several viewers, and up to 90 s detection). Push or SSE (rejected: it needs a
  long-running process).

## R-09 Manual refresh and cooldown

- **Decision**: `POST /api/casadelta/status/refresh` calls `casadelta check_hosts force`. The
  script enforces a **global** minimum interval of 5 s between runs: if the last run finished less
  than 5 s ago, it returns the current snapshot with `retry_after_s` instead of probing. The UI
  disables the "Refresh now" button for 5 s with a countdown, whatever the result. Refresh is
  allowed for the view privilege, because it changes no configuration.
- **Rationale**: FR-010 requires the cooldown. Enforcing it on the server, and not only in the
  browser, protects the firewall from scripted abuse and from many viewers clicking at once
  (Principle II).
- **Alternatives considered**: A per-user cooldown in the session (rejected: it doesn't cap the
  total load across users).

## R-10 ICMP probing on FreeBSD 15

- **Decision**: Run one `/sbin/ping` process per host from a bounded `proc_open` pool:
  - Command: `/sbin/ping -c 2 -i 0.5 -W 1000 -t 3 [-4|-6] <target>`. Use `-4`/`-6` for IP
    literals; leave the flag out for hostnames so the system resolver and ping pick the address
    family.
  - Parameters: 2 echo requests (clarification 4), a 1000 ms wait per reply, and a 3 s hard
    timeout inside ping. The supervisor adds a 4 s per-process wall-clock deadline (SIGKILL), and
    the whole run stops at 20 s.
  - **Concurrency**: at most 16 ping processes at once (constant `MAX_PARALLEL`).
  - **Classification**: exit 0 means `up`, with latency taken from the `round-trip
    min/avg/max/stddev` line (avg, in ms). Exit 2 (no reply) means `down`. Exit 68 (`EX_NOHOST`)
    or a `cannot resolve` line in stderr means `cannot_resolve`. A killed process or any other
    exit code means `down`, with `error_message` holding the reason (e.g. "probe timed out").
  - Only the host part of the address is probed; the port is stripped by `HostAddress` (FR-003).
  - Probe arguments are passed as an argv array (no shell), and targets are validated by
    `HostAddress` first, so command injection isn't possible.
- **Rationale**: The ICMP tools in base need no extra package. Every probe is bounded in time and
  count (FR-013, Principle II). Fifty hosts all down would finish in about 4 pool rounds of
  roughly 3 s, and typical healthy runs finish in about 1 s (SC-003).
- **VM-verify**: Confirm the `-W` units (ms) and the exit code 68 for unresolved names on FreeBSD
  15's `ping`. Also confirm that `ping` without `-4/-6` handles AAAA-only hostnames; otherwise
  resolve with `dns_get_record()` first and pass the family flag.
- **Alternatives considered**: `fping` (not in base, so it would be an extra dependency). Raw
  sockets from PHP (needs the sockets extension and root, and adds complexity). One sequential
  loop (rejected: too slow).

## R-11 Detecting a check subsystem failure (FR-014)

- **Decision**: The status API treats any of these as `subsystem_status = "error"`: the configd
  result is empty or contains `Execute error`, the JSON doesn't decode, the snapshot fails schema
  validation, or the script reports `"ok": false`. The response then carries `subsystem_error` (a
  short, non-sensitive message) and the **last known** results, marked `stale: true` with their
  original `last_checked`. Hosts are never set to `down` because of a subsystem failure. The UI
  shows a warning banner above the groups.
- **Rationale**: Meets FR-014. Keeping stale results with honest timestamps is more useful than an
  empty page, and still avoids false "down" states.
- **Alternatives considered**: Clearing all results on error (rejected: it hides useful
  last-known data).

## R-12 ACLs, menu and privilege split

- **Decision**: In `ACL/ACL.xml`, define two privileges:
  - `page-casadelta-status` ("Casa Delta: Host Status (View)"): `ui/casadelta/status*`,
    `api/casadelta/status/*`.
  - `page-casadelta-settings` ("Casa Delta: Host Settings (Manage)"):
    `ui/casadelta/settings*`, `api/casadelta/settings/*`.

  `Menu/Menu.xml` adds a top-level "Casa Delta" section with "Host Status" and "Host Settings"
  entries. OPNsense hides menu items the user isn't privileged for.
- **Rationale**: Principle V and FR-016. It reuses OPNsense users and groups, and needs no extra
  login.
- **Alternatives considered**: One privilege with a read-only flag (not supported by the OPNsense
  ACL model).

## R-13 Clean uninstall (Principle I)

- **Decision**: All code lives in the package manifest, so `pkg delete` removes it. Runtime files
  (`/tmp/casadelta_dashboard_*`) are removed by a `+POST_DEINSTALL.post` hook in the plugin
  directory, and `/tmp` is cleared on reboot anyway. The `//PRSWS/CasaDelta` config section is
  the only thing left behind, as the constitution allows.
- **VM-verify**: Confirm that the plugins `Mk` framework picks up `+POST_DEINSTALL.post`. If it
  doesn't, use the documented `pkg-deinstall` mechanism instead.

## R-14 Minimum OPNsense version declaration

- **Decision**: State "Requires OPNsense 26.7 or later" in the first paragraph of `pkg-descr`, and
  repeat it in every release entry of the `pkg-descr` changelog. Set `PLUGIN_VERSION=0.1.0` for
  this first slice.
- **Rationale**: Required by the Technical Constraints. `pkg-descr` is what the firmware GUI
  shows for each plugin.

## R-15 Testing strategy

- **Decision**: Three layers, all non-production (Principle VIII):
  1. **Unit (dev machine, PHPUnit 11+)**: the pure library classes (`HostAddress`,
     `HostOrdering`, `PingResultParser`, `SnapshotFreshness`, `StatusAssembler`). PHPUnit is a
     Composer **dev-only** dependency at the repository root and is never packaged or shipped.
  2. **Integration (test VM)**: shell-driven contract tests (`tests/integration/*.sh`) that call
     the test VM's API with `curl` using API keys of two test users (view-only and admin).
     Credentials come from the developer's shell environment. They are never committed and never
     placed on the firewall. The tests cover CRUD validation, the ACL split (403 for view-only
     users on settings), the status schema and the refresh cooldown.
  3. **Manual checklist (test VM)**: in `quickstart.md`. It covers UI behaviour, auto-refresh
     timing, unplugging a host, DNS failure, configd failure, and uninstall residue.
- **Rationale**: The MVC models and controllers need the appliance runtime, so they are tested
  against a real test VM. Pure logic is tested quickly on the dev machine.
- **Alternatives considered**: Running OPNsense's core PHPUnit suite on the VM (deferred: it needs
  extra packages on the firewall).

## R-16 Sorting (FR-006)

- **Decision**: `HostOrdering` sorts by group rank first: `Network`=0, `Nodes`=1, `Apps`=2, then
  custom groups at rank 3, ordered among themselves by case-insensitive A–Z. Within a group, hosts
  are sorted by `sort_order` ascending (default 0, range −9999…9999), then by case-insensitive
  `name`, then by `uuid` as the final tie-breaker so the order is always stable. Group-name
  matching for the fixed groups is case-insensitive, and the display name keeps its configured
  casing.
- **Rationale**: The order is deterministic, matches the clarified spec, and is unit-testable.

## R-17 UI building blocks (Principle VII)

- **Decision**:
  - **Settings page**: `UIBootgrid` for the host grid, a `base_dialog` partial driven by
    `forms/dialogHost.xml`, and the standard search, add, edit, delete and toggle commands.
  - **Status page**: Bootstrap panels per group with a `table table-condensed`, Bootstrap label
    badges for state (`label-success`, `label-danger`, `label-warning`), and `ajaxGet` and
    `ajaxCall` for data. Timers use `setTimeout`. Updates are applied in place by diffing rows on
    `uuid`, which avoids flicker. No extra JS or CSS libraries, and no build step.
- **Alternatives considered**: A dashboard widget for the new OPNsense widget framework (deferred
  to a later slice: the spec asks for a status page).

---

All Technical Context unknowns are resolved. No `NEEDS CLARIFICATION` items remain.

## VM-verify results (2026-10-14, OPNsense 26.7.6 / FreeBSD 15.1-RELEASE-p4)

- **R-10 (ICMP probing)**: `ping -c 2 -i 0.5 -W 1000 -t 3` is confirmed.
  - Exit 0 means `up`, with a `round-trip min/avg/max/stddev` line.
  - Exit 2 means no reply. The probe completes in about 2.1 s.
  - Exit 68 with stderr `cannot resolve <name>: ...` means the name is unresolved (`cannot_resolve`).
- **R-06 (CLI bootstrap)**: `require_once('script/load_phalcon.php')` plus `OPNsense\Core\Config::getInstance()->object()` works in a CLI script. No fallback is needed.
- **R-13 (uninstall hook)**: The plugins Mk framework (`Mk/plugins.mk`, `PLUGIN_SCRIPTS`) handles the hook. The filename `+POST_DEINSTALL.post` is confirmed by existing plugins `security/crowdsec` and `security/wazuh-agent`. No change to T006.
- **R-01 (packaging and migrations)**: `plugins.mk` runs model migrations for every `models/<vendor>/<module>` directory, so `PRSWS/CasaDelta` needs no special handling.

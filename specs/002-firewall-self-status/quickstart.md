# Quickstart & Validation Guide: Firewall Self-Status

**Feature**: `002-firewall-self-status` | **Plan**: [plan.md](./plan.md)

This guide proves the slice end to end. **Run every step on the non-production test VM and never
on the production firewall (Principle VIII).** Payloads, schemas and error texts are defined in
[contracts/](./contracts/) and [data-model.md](./data-model.md) and are not repeated here. This
slice extends the slice 001 package, so its §1–§2 steps are unchanged; §3–§4 add the firewall
coverage.

---

## 0. Prerequisites

| Item | Requirement |
|------|-------------|
| Test VM | OPNsense 26.7 (FreeBSD 15), reachable from the dev machine at `$OPN_HOST`, with a snapshot taken before install. **Slice 001 already installed** (it provides the Host Status page, the `page-casadelta-status` privilege and the refresh cycle this slice extends). |
| Build tree on VM | `git clone https://github.com/opnsense/plugins /usr/plugins`. Copy or rsync this repo's `net-mgmt/casadelta-dashboard/` to `/usr/plugins/net-mgmt/casadelta-dashboard/` |
| Dev machine | PHP 8.2+ CLI, Composer, `curl`, `jq` |
| Test users on VM | `cd-viewer` with only *Casa Delta: Host Status (View)* (`page-casadelta-status`), `cd-admin` with the settings privilege. Each has an API key. |
| Credentials | Exported in the dev shell only (`OPN_VIEW_KEY`, `OPN_VIEW_SECRET`, `OPN_ADMIN_KEY`, `OPN_ADMIN_SECRET`). Never commit them or copy them to the VM |
| Host fixtures | At least one host configured for the zero-host / all-down checks in §4 (reuse slice 001's fixtures). |

## 1. Automated unit tests (dev machine)

```bash
composer install            # dev-only: PHPUnit
vendor/bin/phpunit --testsuite unit
```

**Expected**: all tests pass, including the two new suites. `FirewallTelemetryReaderTest` covers
the `sysctl -i` output parsing **by name** — every documented OID, a missing OID (the UFS box has
no ARC OID) → absent value, a non-zero exit / timeout → all sysctl-sourced values `null`,
malformed lines ignored, `kern.boottime` `{ sec = … }`, `vm.loadavg` `{ a b c }`, five-counter
`kern.cp_time`, `67.0C` temperatures, the version JSON, a missing version file, and the disk
built-ins. `FirewallStatusAssemblerTest` covers the CPU-delta math, the FR-005 memory definition
with a **ZFS case (ARC present)** and a **UFS case (ARC absent = 0)** — including a large-ARC case
that must **not** warn — the three FR-008 warning rules (crossing and clearing, independently), the
no-warning-when-`null` rule, the temperature omitted-vs-unavailable split (FR-007), and the
`all_unavailable` logic (FR-013).

## 2. Build and install on the test VM

```bash
ssh root@$OPN_HOST 'cd /usr/plugins/net-mgmt/casadelta-dashboard && make package && \
  pkg add -f work/pkg/os-casadelta-dashboard-*.pkg'
```

**Expected**:
- **System → Firmware → Plugins** lists `os-casadelta-dashboard` at **0.2.0** with "Requires
  OPNsense 26.7 or later" and the new changelog entry.
- `configctl casadelta check_hosts` works unchanged (no regression); `actions_casadelta.conf`
  still contains only the slice 001 `[check_hosts]` entry (no `check_firewall`).
- `ps ax | grep -i casadelta` shows **no** new persistent process.

## 3. Automated integration tests (dev machine → test VM API)

`run.sh` is extended to include `firewall_api.sh` in its loop:

```bash
OPN_HOST=… OPN_VIEW_KEY=… OPN_VIEW_SECRET=… OPN_ADMIN_KEY=… OPN_ADMIN_SECRET=… \
  tests/integration/run.sh
```

**Expected**: all checks pass, including the new `firewall_api.sh`:
- `GET /api/casadelta/status/get` and `POST .../refresh` both carry a `firewall` object matching
  [firewall-api.md](./contracts/firewall-api.md) (every documented field present, temperature
  present on this `coretemp` box).
- `firewall` is present when `total_hosts = 0` and when all hosts are `down` (FR-017).
- `warnings` is a subset of `{memory, disk, load}` and contains no metric whose value is `null`.
- The `refresh` object keeps its slice 001 meaning (it describes the `check_hosts` run); repeated
  `POST /refresh` within 5 s still return `performed:false` exactly as in slice 001, and the
  `firewall` object is re-read in-process on each request (fresh `collected_at` each time).
- The response does not expose file paths or raw command output.

## 4. Manual verification checklist (test VM GUI)

Open **Casa Delta → Host Status**. Seed hosts as `cd-admin` (or root) as needed for M3/M4.

| # | Step | Expected outcome | Spec ref |
|---|------|------------------|----------|
| M1 | Open the Host Status page with hosts configured | A **Firewall** panel appears at the very top, above the summary line and all host groups, showing hostname, OPNsense version, uptime, last boot (local time), load (1/5/15), CPU %, memory used/total (with %), disk used/total (with %), and an "as of" time | US1-AS1, FR-001…FR-004 |
| M2 | Note the temperature row | This test box has `coretemp` loaded, so the row is **present** with a real reading (max of `dev.cpu.N.temperature`). On a box where the kld is not loaded the row must be **absent** — no error, warning, or placeholder | US3-AS1, FR-007, SC-003 |
| M3 | With **zero** configured hosts (disable or delete all), reload the page | The Firewall panel renders in full at the top; the host area shows the slice 001 empty state; the panel keeps updating on the ~60 s cycle | US1-AS4, FR-017, SC-004 |
| M4 | With **all hosts down**, reload | Firewall panel renders and updates normally; host rows show `down` (unchanged slice 001 behaviour) | US1-AS4, FR-017 |
| M5 | Leave the page open, watch DevTools → Network | About one `status/get` call every 60 s; **both** the panel and the host rows update in the same response, in place, no full reload | US1-AS3, FR-010 |
| M6 | Click **Refresh now** | Button disables with a 5 s countdown; the Firewall panel and host rows both update from that one request | US1-AS3, FR-010 |
| M7 | Push **disk** usage above 90% on the root disk (create a large file in `/`), wait one cycle, then delete it | On crossing, only the disk cell is highlighted as a warning; memory and all other values render normally. After the file is deleted, the highlight clears on the next cycle. No false-positive warnings in the meantime | US2-AS2, US2-AS4, FR-008, SC-002 |
| M8 | Hold **sustained CPU load** above the core count (e.g. a bounded `yes > /dev/null` × cores for one cycle) | Only the load cell is highlighted; it clears when the load is released. Memory/disk unaffected | US2-AS3, FR-008 |
| M9 | Temporarily exhaust available memory (bounded, e.g. a short-lived anonymous-allocate process) | The memory cell highlights only when **used** (the FR-005 definition, ARC excluded) exceeds 90%. A box that merely has a large ZFS ARC does **not** highlight | US2-AS1, FR-005, FR-008, Edge: large ZFS cache |
| M10 | Enable the reader's test-only fault-injection switch for a single source (the version file; see R-09), then disable it | Exactly that value shows "unavailable"; every other value renders with real data; no panel breakage; the highlight never applies to an unavailable value | US3-AS3, FR-012, Edge: highlight with unavailable |
| M11 | Force **all** values unavailable (enable the reader's test-only fault-injection switch for the whole read — R-09), then disable it | The panel shows a single discreet "telemetry unavailable" notice with the heading and box intact; the host groups below remain fully functional; it recovers automatically on the next good cycle with no user action | US3-AS4, FR-013, FR-017 |
| M12 | Immediately after a VM reboot | Low uptime and reset load render normally, no special handling; `last_boot` reflects the new boot | Edge: freshly rebooted |
| M13 | Log in as `cd-viewer` | The full Firewall panel is visible (it reuses `page-casadelta-status`). Log in as a user **without** that privilege: no access to the page or the `firewall` object. No settings surface exists for the panel | US3, SC-006, FR-014 |
| M14 | Enable the reader's test-only fault-injection switch for the whole read (R-09), then disable it | The Firewall panel degrades to the all-unavailable notice; the host groups are **unaffected** (independent data paths). Disable it and it recovers on the next cycle | FR-017, R-09, Principle II |
| M15 | Watch `top -P` across a refresh | No sustained CPU spike; the in-process firewall read (two bounded, serial `sysctl` calls plus a ~500 ms sample gap) completes in ~2–3 s and adds no visible delay to the host refresh | SC-005, R-06, Principle II |
| M16 | Reboot the VM, then open the page | The Firewall panel works after reboot; every read runs on demand in-process and this slice keeps no `/tmp` state at all | Principle IV |
| M17 | Uninstall the plugin (`pkg delete os-casadelta-dashboard`) | No package files remain. Slice 001's `/tmp/casadelta_dashboard_status*` files are removed by the existing hook; this slice creates **no** `/tmp` files, so nothing of it lingers. Only the `<PRSWS><CasaDelta>` section remains in `/conf/config.xml` (unchanged from slice 001) | Principle I, R-10 |

Record the outcome of M1–M17 (pass/fail, with notes) in the release notes before any package is
promoted to production.

## 5. Production promotion

Only a package built and verified in sections 1–4 may be installed on production. Install it
through **System → Firmware**, or with `pkg add` of that exact artifact. Never install from a
working tree.

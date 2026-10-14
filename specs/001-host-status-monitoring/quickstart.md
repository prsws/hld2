# Quickstart & Validation Guide: Host Status Monitoring

**Feature**: `001-host-status-monitoring` | **Plan**: [plan.md](./plan.md)

This guide proves the slice end to end. **Run every step on the non-production test VM and never on
the production firewall (Principle VIII).** Payloads, schemas and error texts are defined in
[contracts/](./contracts/) and [data-model.md](./data-model.md) and are not repeated here.

---

## 0. Prerequisites

| Item | Requirement |
|------|-------------|
| Test VM | OPNsense 26.7 (FreeBSD 15), reachable from the dev machine at `$OPN_HOST`, with a snapshot taken before install |
| Build tree on VM | `git clone https://github.com/opnsense/plugins /usr/plugins`. Copy or rsync this repo's `net-mgmt/casadelta-dashboard/` to `/usr/plugins/net-mgmt/casadelta-dashboard/` |
| Dev machine | PHP 8.2+ CLI, Composer, `curl`, `jq` |
| Test users on VM | `cd-viewer` with only *Casa Delta: Host Status (View)* (`page-casadelta-status`), `cd-admin` with only *Casa Delta: Host Settings (Manage)* (`page-casadelta-settings`). Each user has an API key |
| Credentials | Exported in the dev shell only (`OPN_VIEW_KEY`, `OPN_VIEW_SECRET`, `OPN_ADMIN_KEY`, `OPN_ADMIN_SECRET`). Never commit them or copy them to the VM |
| LAN fixtures | One host that answers ping (`$UP_IP`), one unused IP in a connected subnet (`$DOWN_IP`), and one non-existent name (`nonexistent.invalid`) |

## 1. Automated unit tests (dev machine)

```bash
composer install            # dev-only: PHPUnit
vendor/bin/phpunit --testsuite unit
```

**Expected**: all tests pass. They cover address parsing (the full matrix in
[settings-api.md](./contracts/settings-api.md#validation-matrix-from-fr-002-fr-003)), ordering
(data-model §6), ping output classification (data-model §3), freshness and cooldown
(data-model §4), and status assembly (summary counts, stale and pending handling).

## 2. Build and install on the test VM

```bash
ssh root@$OPN_HOST 'cd /usr/plugins/net-mgmt/casadelta-dashboard && make package && \
  pkg add -f work/pkg/os-casadelta-dashboard-*.pkg'
```

**Expected**:
- **System → Firmware → Plugins** lists `os-casadelta-dashboard` with "Requires OPNsense 26.7 or
  later" in its description.
- The **Casa Delta** menu appears for root.
- `configctl casadelta check_hosts` prints JSON with `"schema":1`.
- Checking the processes before and after (`ps ax | grep -i casadelta`) shows that no new
  persistent process is running.

## 3. Automated integration tests (dev machine → test VM API)

```bash
OPN_HOST=… OPN_VIEW_KEY=… OPN_VIEW_SECRET=… OPN_ADMIN_KEY=… OPN_ADMIN_SECRET=… \
  tests/integration/run.sh
```

**Expected**: all checks pass:
- The ACL matrix in [acl-menu.md](./contracts/acl-menu.md) is reproduced exactly (200 or 403).
- Add, set, toggle and delete work for `cd-admin`, and every row of the validation matrix gives
  the documented result.
- `GET /api/casadelta/status/get` matches the schema in [status-api.md](./contracts/status-api.md),
  with groups in fixed order and sort order respected.
- Two `POST /api/casadelta/status/refresh` calls within 5 s: the second returns `performed:false`
  and `retry_after_s > 0`.
- 10 parallel `GET /api/casadelta/status/get` calls on a stale snapshot result in exactly **one**
  probe run. Check `finished_at` and the syslog line count.

## 4. Manual verification checklist (test VM GUI)

Seed these hosts as `cd-admin` (or root):

| Name | Address | Group | Sort | Enabled |
|------|---------|-------|------|---------|
| Gateway | `$UP_IP` | Network | 0 | ✓ |
| Hypervisor UI | `$UP_IP:8006` | Nodes | 10 | ✓ |
| Dead Box | `$DOWN_IP` | Nodes | 5 | ✓ |
| Ghost | `nonexistent.invalid` | Apps | 0 | ✓ |
| Zeta Custom | `$UP_IP` | Zeta | 0 | ✓ |
| Alpha Custom | `$UP_IP` | Alpha | 0 | ✓ |
| Parked | `$UP_IP` | Apps | 0 | ✗ |

| # | Step | Expected outcome | Spec ref |
|---|------|------------------|----------|
| M1 | Add a host with an existing name | Save is refused and the uniqueness message appears at the Name field | US2-AS2, FR-002 |
| M2 | Enter `192.168.1`, `pve.lan:70000`, `[10.0.0.1]:80` | Each one is refused with its specific message | US2-AS3, FR-003 |
| M3 | Open **Host Status** | Groups appear in the order Network, Nodes, Apps, Alpha, Zeta. In Nodes, Dead Box (5) comes before Hypervisor UI (10). Parked isn't shown | US1-AS1, FR-006, FR-015 |
| M4 | Read the states | Gateway and the custom hosts are `up` with latency in ms, Dead Box is `down`, Ghost is `cannot resolve` | FR-007 |
| M5 | Read the summary | "4 up out of 6 hosts" | FR-009 |
| M6 | Leave the page open and watch DevTools → Network | About one `/api/casadelta/status/get` call every 60 s and no full page reload. The rows update in place | US1-AS2, FR-010 |
| M7 | Click **Refresh now** twice quickly | The button is disabled with a countdown of about 5 s, and the second click isn't possible | US1-AS3 |
| M8 | Note the time, then unplug or shut down the machine at `$UP_IP` | Within one cycle (≤ 65 s), every row using `$UP_IP` (including `$UP_IP:8006`) shows `down`. When the machine comes back, they return to `up` within one cycle | US1-AS4, SC-004 |
| M9 | Open the status page in 3 browsers or users at once for 3 minutes | `clog /var/log/system/latest.log \| grep casadelta-dashboard` shows about 1 run per minute | US1-AS6, SC-005 |
| M10 | Simulate a backend failure: `chmod -x /usr/local/opnsense/scripts/CasaDelta/check_hosts.php`, then wait for the next poll | A warning banner appears, the rows are marked stale, and no host is turned `down` by the failure. Restore with `chmod +x` and the banner clears | US1-AS7, FR-014 |
| M11 | Disable all hosts | "No monitored hosts configured or enabled." is shown, and no run is logged | Edge: zero hosts |
| M12 | Log in as `cd-viewer` | Only **Host Status** (`/ui/casadelta/status`) is in the menu. `/ui/casadelta/settings` and `/api/casadelta/settings/*` are denied | US3-AS1, SC-006 |
| M13 | Disconnect the VM's WAN and power off or isolate every other LAN host (keep only the browser's link to the VM), then hard-reload the status page | The page renders fully (no hanging assets), with the hosts `down` or `cannot resolve` | SC-007, Principle III |
| M14 | Watch `top -P` during a check of all 7 hosts | There is no sustained CPU spike, and the ping processes exit within about 4 s | SC-005, Principle II |
| M15 | **System → Configuration → Backups**: download, delete a host, restore | The host returns after the restore | Principle IV |
| M16 | Reboot the VM | The hosts persist and the status page works after the reboot | Principle IV |
| M17 | Uninstall the plugin (`pkg delete os-casadelta-dashboard`) | No files remain from the package. `/tmp/casadelta_dashboard_*` is gone, and only the `<PRSWS><CasaDelta>` section (with its `<hosts>` container) remains in `/conf/config.xml` | Principle I |

Record the outcome of M1–M17 (pass/fail, with notes) in the release notes before any package is
promoted to production.

## 5. Production promotion

Only a package built and verified in sections 1–4 may be installed on production. Install it
through **System → Firmware**, or with `pkg add` of that exact artifact. Never install from a
working tree.

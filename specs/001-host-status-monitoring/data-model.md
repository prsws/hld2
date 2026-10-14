# Data Model: Host Status Monitoring

**Feature**: `001-host-status-monitoring` | **Date**: 2026-10-14 | **Plan**: [plan.md](./plan.md)

The feature has two storage domains:

1. **Persistent configuration**: lives in OPNsense `config.xml` under `//PRSWS/CasaDelta`
   (container `hosts.host`). It is edited only through the GUI or API, and is included in config
   backup and restore (Principle IV).
2. **Ephemeral runtime state**: a single snapshot file in `/tmp`, written only by the configd
   check script. It is never part of the configuration and is never web-served.

---

## 1. `MonitoredHost` (persistent, model `PRSWS\CasaDelta\CasaDelta`, container `hosts.host`)

- **Model**: `PRSWS\CasaDelta\CasaDelta`, the plugin's main model, named after the module. Its
  files are `CasaDelta.php` and `CasaDelta.xml`, under `models/PRSWS/CasaDelta/`.
- **Mount**: `//PRSWS/CasaDelta`
- **Collection**: `hosts.host`, an `ArrayField` with one entry per host and a UUID key that the
  framework generates.
- **Custom field type**: `.\HostAddressField` in the table below is
  `PRSWS\CasaDelta\FieldTypes\HostAddressField`.
- **Settings API binding**: `$internalModelName = 'casadelta'`,
  `$internalModelClass = 'PRSWS\CasaDelta\CasaDelta'` (see
  [settings-api.md](./contracts/settings-api.md)).

> **Note for later slices**: `hosts` is only the first container in this model. Later slices
> (firewall status, WAN/VPN IP, etc.) add their own containers next to `hosts` in the same
> `PRSWS\CasaDelta\CasaDelta` model and under the same `//PRSWS/CasaDelta` mount. They MUST NOT
> create new mount points. The mount path can't be changed later without a config migration.

| Field         | Model type                  | Required | Default | Validation rule (error message)                                                                                                    |
|---------------|-----------------------------|----------|---------|------------------------------------------------------------------------------------------------------------------------------------|
| `uuid`        | ArrayField key (generated)  | yes      | auto    | Managed by the framework.                                                                                                          |
| `enabled`     | `BooleanField`              | yes      | `1`     | `0` or `1`.                                                                                                                        |
| `name`        | `TextField`                 | yes      | —       | 1–64 characters, trimmed, printable characters only ("Display name must be 1–64 printable characters."). **Unique** across `hosts.host` (`UniqueConstraint`: "A host with this display name already exists."). |
| `address`     | `.\HostAddressField`        | yes      | —       | IPv4, IPv6 or hostname, optionally followed by a port, following the rules in [research R-04](./research.md#r-04-host-address-validation-ipv4--ipv6--hostname--optional-port). Specific messages such as "Invalid IPv4 address.", "Port must be between 1 and 65535.", "IPv6 addresses with a port must use [addr]:port." and "Invalid hostname." |
| `group`       | `TextField`                 | yes      | `Network` | 1–32 characters, letters, digits, space, `-` and `_` ("Group must be 1–32 characters: letters, digits, space, - or _."). The dialog suggests `Network`, `Nodes` and `Apps`. |
| `sort_order`  | `IntegerField`              | no       | `0`     | Integer from −9999 to 9999 ("Sort order must be an integer between -9999 and 9999."). Empty is stored as `0`.                      |
| `description` | `TextField`                 | no       | empty   | 0–255 characters, single line ("Description must be at most 255 characters.").                                                    |

**Model-level rules**

- The model holds no secrets in this slice, so no field needs masking (Principle IV). This is
  noted for future slices.
- The model is never written to by the check script; the script only reads it (Principle II).

**Lifecycle (administrator)**

```text
(none) --add--> Enabled --toggle--> Disabled --toggle--> Enabled
   Enabled|Disabled --set (edit)--> same state with new values
   Enabled|Disabled --del--> (removed)
```

- **Enabled**: included in checks, the snapshot fingerprint and the status page.
- **Disabled**: kept in config and shown in the settings grid. It is not checked, not counted and
  not shown on the status page (FR-015).

---

## 2. `HostAddress` (value object, pure library `PRSWS\CasaDelta\Lib\HostAddress`)

This object is the result of parsing `MonitoredHost.address`. It is never stored separately.

| Attribute  | Type                              | Meaning                                                              |
|------------|-----------------------------------|----------------------------------------------------------------------|
| `raw`      | string                            | The configured value, shown as-is in the UI.                         |
| `host`     | string                            | IP literal (without brackets) or hostname. This is the ICMP target.  |
| `port`     | int \| null                       | Port for information only. It is never probed.                       |
| `kind`     | enum `ipv4` \| `ipv6` \| `hostname` | Decides whether ping gets `-4`, `-6` or no family flag.            |

Examples:

| `raw`                  | `host`        | `port` | `kind`     |
|------------------------|---------------|--------|------------|
| `192.168.1.1`          | `192.168.1.1` | null   | `ipv4`     |
| `192.168.1.10:8006`    | `192.168.1.10`| 8006   | `ipv4`     |
| `fe80::1`              | `fe80::1`     | null   | `ipv6`     |
| `[2001:db8::1]:8006`   | `2001:db8::1` | 8006   | `ipv6`     |
| `pve.lan:8006`         | `pve.lan`     | 8006   | `hostname` |
| `192.168.1` → rejected | —             | —      | —          |
| `pve.lan:0` → rejected | —             | —      | —          |

---

## 3. `HostStatusResult` (ephemeral, one per enabled host and check run)

| Field           | Type                                    | Notes                                                                 |
|-----------------|-----------------------------------------|-----------------------------------------------------------------------|
| `host_uuid`     | string                                  | Join key to `MonitoredHost.uuid`.                                     |
| `name`          | string                                  | Taken from the current config when the API responds.                  |
| `address`       | string                                  | `HostAddress.raw`, from the current config.                           |
| `group`         | string                                  | From the current config.                                              |
| `sort_order`    | int                                     | From the current config (default 0).                                  |
| `state`         | enum `up` \| `down` \| `cannot_resolve` \| `pending` | `pending` is used only when the config has an enabled host that the snapshot doesn't contain yet (a check has just been triggered). |
| `latency_ms`    | float \| null                           | Average RTT in ms with 2 decimals. Present only when `state = up`.    |
| `last_checked`  | string (ISO 8601 UTC)                   | When this host's probe finished. Null when `pending`.                 |
| `error_message` | string \| null                          | Short diagnostic, e.g. "cannot resolve pve.lan" or "probe timed out". |
| `stale`         | bool                                    | True when the result comes from an older snapshot because the latest check failed (FR-014). |

**State rules** (from FR-007 and research R-10):

| ping outcome                                     | `state`          | `latency_ms` |
|--------------------------------------------------|------------------|--------------|
| exit 0 (≥ 1 of 2 replies)                        | `up`             | avg RTT      |
| exit 2 (0 of 2 replies)                          | `down`           | null         |
| exit 68 / "cannot resolve" in stderr             | `cannot_resolve` | null         |
| killed at the 4 s deadline / other exit code     | `down` with `error_message` | null |

**Host state transitions** (one transition per check run; there is no hysteresis beyond the
2-probe rule):

```text
pending ──first check──> up | down | cannot_resolve
up ⇄ down ⇄ cannot_resolve        (any state can follow any other on the next run)
any ──host disabled or deleted──> (dropped from the status page at the next API response)
```

---

## 4. `StatusSnapshot` (ephemeral file `/tmp/casadelta_dashboard_status.json`)

This is the shared result cache (FR-012). Only `check_hosts.php` writes it, using temp file +
`rename()` while holding `flock` on `/tmp/casadelta_dashboard_check.lock`.

| Field          | Type                         | Notes                                                                 |
|----------------|------------------------------|-----------------------------------------------------------------------|
| `schema`       | int                          | `1`. Readers reject unknown versions, which counts as a subsystem error. |
| `ok`           | bool                         | False if the run itself failed (e.g. the config couldn't be read).    |
| `error`        | string \| null               | Message for the failure when `ok = false`.                            |
| `started_at`   | string (ISO 8601 UTC)        |                                                                       |
| `finished_at`  | string (ISO 8601 UTC)        | Basis for freshness and `next_check_at`.                              |
| `duration_ms`  | int                          | Used to check the performance budget.                                 |
| `fingerprint`  | string (hex SHA-256)         | Hash of the sorted `uuid|address` pairs of the enabled hosts at run time. |
| `results`      | map `uuid → {state, latency_ms, last_checked, error_message}` | Results for the enabled hosts only. |

**Freshness rule**, implemented in `SnapshotFreshness`, a pure class:

```text
fresh  ⇔ ok ∧ schema = 1 ∧ (now − finished_at) < 55 s ∧ fingerprint = currentFingerprint
cooldown ⇔ (now − finished_at) < 5 s          (no new probe run starts during the cooldown, whether forced
                                             or not, and whether the last run succeeded or failed)
```

**Check-run lifecycle** (inside the configd script):

```text
Idle ──request(stale|force)──> WaitingForLock ──lock──> Recheck
Recheck ──fresh (normal request) | cooldown (any request)──> ReturnExisting ──> Idle
Recheck ──otherwise──> Probing ──all done | 20 s run cap──> Writing ──rename──> Idle
Probing|Writing ──fatal error──> Failed (snapshot written with ok=false, previous results kept) ──> Idle
```

---

## 5. `DashboardStatusSummary` (ephemeral, computed by the API for each response)

| Field              | Type                    | Derivation                                                      |
|--------------------|-------------------------|-----------------------------------------------------------------|
| `total_hosts`      | int                     | The number of enabled hosts in the current config.              |
| `up_hosts`         | int                     | The number of results with `state = up`.                        |
| `down_hosts`       | int                     | The number of results with `state = down`.                      |
| `unresolved_hosts` | int                     | The number of results with `state = cannot_resolve`.            |
| `pending_hosts`    | int                     | The number of results with `state = pending`.                   |
| `last_run`         | string \| null          | The snapshot's `finished_at`.                                   |
| `next_check_at`    | string \| null          | `finished_at + 60 s` (research R-08).                           |
| `subsystem_status` | enum `ok` \| `error`    | See research R-11.                                              |
| `subsystem_error`  | string \| null          | A non-sensitive message for the banner.                         |

The UI's summary line is `"{up_hosts} up out of {total_hosts} hosts"` (FR-009).

---

## 6. Ordering (`HostOrdering`, pure class) – FR-006

The sort key for each row is the tuple:

```text
(groupRank(group), groupRank = 3 ? lower(group) : "", sort_order, lower(name), uuid)
groupRank: network → 0, nodes → 1, apps → 2, anything else → 3   (case-insensitive)
```

The API returns `groups[]` already in this order, with the hosts inside each group in order too.
The UI renders them exactly as received.

---

## 7. Relationships

```text
MonitoredHost (config, 0..N) 1 ──── 0..1 HostStatusResult (per snapshot, joined by uuid)
StatusSnapshot 1 ──── 0..N HostStatusResult
DashboardStatusSummary = f(current enabled MonitoredHosts, latest StatusSnapshot)
```

# Contract: Settings API (administrator)

**Controller**: `PRSWS\CasaDelta\Api\SettingsController` (extends
`OPNsense\Base\ApiMutableModelControllerBase`, `$internalModelName = 'casadelta'`,
`$internalModelClass = 'PRSWS\CasaDelta\CasaDelta'`)
**Privilege**: `page-casadelta-settings` (see [acl-menu.md](./acl-menu.md))
**Data definitions**: [data-model.md](../data-model.md) §1

This controller covers the spec's `HostController` role (FR-004). No `ServiceController` is
needed: the plugin has no service to reconfigure, so a saved change takes effect at the next status
request (the fingerprint changes and the snapshot is invalidated).

All mutating endpoints use **POST** and save `config.xml` through the framework, which creates a
configuration history entry. Each handler is a thin wrapper around the matching base helper
(`searchBase`, `getBase`, `addBase`, `setBase`, `delBase`, `toggleBase`) on path `hosts.host`.

| Method | Endpoint                                         | Purpose                         |
|--------|--------------------------------------------------|---------------------------------|
| GET/POST | `/api/casadelta/settings/searchHost`           | Grid listing (UIBootgrid)       |
| GET    | `/api/casadelta/settings/getHost[/{uuid}]`       | Get one host, or blank defaults |
| POST   | `/api/casadelta/settings/addHost`                | Create                          |
| POST   | `/api/casadelta/settings/setHost/{uuid}`         | Update                          |
| POST   | `/api/casadelta/settings/delHost/{uuid}`         | Delete                          |
| POST   | `/api/casadelta/settings/toggleHost/{uuid}[/{0\|1}]` | Enable/disable              |

---

## Payload (add / set)

```json
{
  "host": {
    "enabled": "1",
    "name": "Proxmox Node 1",
    "address": "pve.lan:8006",
    "group": "Nodes",
    "sort_order": "10",
    "description": "Primary hypervisor"
  }
}
```

## Responses

**Saved**

```json
{ "result": "saved", "uuid": "a2d4…" }
```

**Validation failure.** Nothing is persisted (SC-002):

```json
{
  "result": "failed",
  "validations": {
    "host.name": "A host with this display name already exists.",
    "host.address": "Port must be between 1 and 65535."
  }
}
```

**searchHost**

```json
{
  "rows": [
    { "uuid": "a2d4…", "enabled": "1", "name": "Proxmox Node 1", "address": "pve.lan:8006",
      "group": "Nodes", "sort_order": "10", "description": "Primary hypervisor" }
  ],
  "rowCount": 1, "total": 1, "current": 1
}
```

Grid columns: `enabled`, `group`, `sort_order`, `name`, `address` and `description`. The default
sort is `group`, then `sort_order`, then `name`.

**delHost**: `{"result":"deleted"}` | `{"result":"not found"}`
**toggleHost**: `{"result":"Enabled"}` | `{"result":"Disabled"}` | `{"result":"failed"}`

## Validation matrix (from FR-002, FR-003)

| Input `address`        | Result                                                  |
|------------------------|---------------------------------------------------------|
| `192.168.1.10`         | accepted                                                |
| `192.168.1.10:8006`    | accepted                                                |
| `fe80::1`              | accepted                                                |
| `[2001:db8::1]:8006`   | accepted                                                |
| `pve`, `pve.lan`, `pve.lan:8006` | accepted                                      |
| `192.168.1`            | rejected: "Invalid IPv4 address."                       |
| `192.168.1.300`        | rejected: "Invalid IPv4 address."                       |
| `pve.lan:0`, `pve.lan:65536`, `pve.lan:08006` | rejected: "Port must be between 1 and 65535." |
| `2001:db8::1:8006` with brackets missing | accepted as a bare IPv6 address (no port) |
| `[2001:db8::1]`        | accepted (brackets without a port are allowed)          |
| `[192.168.1.1]:80`     | rejected: "Only IPv6 addresses may be bracketed."       |
| `-pve.lan`, `pve_lan`, `a..b` | rejected: "Invalid hostname."                    |
| `http://pve.lan`       | rejected: "Enter a host, not a URL."                    |
| `fe80::1%igb0`         | rejected: "IPv6 zone identifiers are not supported."    |
| empty                  | rejected: "Address is required."                        |

## Errors

| Condition                            | Response                       |
|--------------------------------------|--------------------------------|
| Caller lacks settings privilege      | HTTP 403 (ACL layer)           |
| Mutating call via GET                | `{"result":"failed"}`, no change |
| Unknown UUID on set/del/toggle       | `{"result":"failed"}` / `{"result":"not found"}` |

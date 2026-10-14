# Contract: UI pages

Both pages are Volt views rendered by UI controllers that extend `OPNsense\Base\IndexController`.
They use only assets shipped by the OPNsense GUI (jQuery, Bootstrap, OPNsense JS helpers). There
are no CDN references and no extra libraries (FR-017, Principles III and VII).

## Status page: `/ui/casadelta/status`

- **Controller**: `PRSWS\CasaDelta\StatusController::indexAction`
- **View**: `views/PRSWS/CasaDelta/status.volt`
- **Data**: [status-api.md](./status-api.md)

| Element                | Behaviour                                                                                       |
|------------------------|-------------------------------------------------------------------------------------------------|
| Summary line           | `"{up_hosts} up out of {total_hosts} hosts"`, plus "last checked HH:MM:SS" in browser local time. |
| Subsystem banner       | `alert alert-warning`, shown only when `subsystem_status = "error"`, with `subsystem_error` text. Host rows stay visible, and stale rows are dimmed with a "stale" hint. |
| Empty state            | "No monitored hosts configured or enabled." when `total_hosts = 0`. Users with the settings privilege also see a link to Host Settings. |
| Group panels           | One panel per entry in `groups[]`, in the order received.                                       |
| Host row               | Name, address (as configured, including the port), state badge, latency (`0.42 ms` when up, otherwise `—`), and last checked (local time). `error_message` appears as a tooltip. |
| State badges           | `up` uses `label-success` "up"; `down` uses `label-danger` "down"; `cannot_resolve` uses `label-warning` "cannot resolve"; `pending` uses `label-default` "pending". |
| Auto refresh           | The first fetch runs on load. The next fetch is scheduled with `setTimeout` at `clamp(next_check_at − now + 2 s, 30 s, 65 s)`, or 60 s if that is unknown. Rows are updated in place by `host_uuid`, and the page is never fully reloaded. Only one timer runs at a time. |
| Refresh now            | `POST /api/casadelta/status/refresh`. The button is disabled with a countdown for `max(5, retry_after_s)` s and the auto-refresh timer is rescheduled afterwards. |
| Request failure (network/HTTP) | The same banner shows "Status could not be loaded; retrying." The last rendered rows are kept, and the next attempt happens on the normal schedule. |
| Escaping               | All values are inserted as text (`.text()`), never as HTML.                                    |

## Settings page: `/ui/casadelta/settings`

- **Controller**: `PRSWS\CasaDelta\SettingsController::indexAction`, which passes
  `formDialogHost = $this->getForm('dialogHost')`.
- **View**: `views/PRSWS/CasaDelta/settings.volt`
- **Form**: `controllers/PRSWS/CasaDelta/forms/dialogHost.xml`
- **Data**: [settings-api.md](./settings-api.md)

| Element      | Behaviour                                                                                         |
|--------------|---------------------------------------------------------------------------------------------------|
| Grid         | `UIBootgrid` bound to `searchHost`, `getHost`, `setHost`, `addHost`, `delHost` and `toggleHost`. Columns are enabled (toggle), group, sort order, name, address and description. It supports search, add, edit, clone, delete and toggle. |
| Dialog fields| `host.enabled` (checkbox, default checked), `host.name` (text), `host.address` (text, with help text giving the IPv4/IPv6/hostname and optional `:port` / `[v6]:port` examples), `host.group` (text, with help text listing `Network`, `Nodes` and `Apps` as the fixed-order groups), `host.sort_order` (text/number, default 0, with help text "lower first, ties sorted by name"), `host.description` (text). |
| Validation   | Server messages appear next to the matching field, and the dialog stays open until the save succeeds. |
| Apply        | None is needed. Changes take effect at the next status poll.                                     |

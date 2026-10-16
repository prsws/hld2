# Contract: Firewall panel (on the Host Status page)

**Page**: `/ui/casadelta/status` — the slice 001 page, extended (no new URL).
**View**: `src/opnsense/mvc/app/views/PRSWS/CasaDelta/status.volt` (extended).
**Controller**: `PRSWS\CasaDelta\StatusController::indexAction` (unchanged).
**Data**: [firewall-api.md](./firewall-api.md)
**Privilege**: `page-casadelta-status` (unchanged — the panel inherits it, FR-014).

Only assets shipped with the OPNsense GUI (jQuery, Bootstrap) are used — no CDN, no extra
library, no build step (Principles III, VII). Every value is inserted as text (`.text()`), never
as HTML. This panel is a plain Bootstrap panel; it is not a widget-framework widget.

---

## Layout

The Firewall panel is the **first** content block, above the summary line and all host group
panels:

```text
[ #cd-banner (slice 001, unchanged) ]

[ ┌ Firewall ────────────────────────────────────────────┐  ]  ← NEW, at the top
[ │  host name   OPNsense version   last boot (local)    │  ]
[ │  Uptime 29d 12h · Load 0.31/0.48/0.52 · CPU 12%      │  ]
[ │  Memory 1.1 GiB / 15.6 GiB (7%)  Disk 3.6 GiB / 35 G │  ]
[ │  CPU 48.5 °C   (only when the hardware reports it)   │  ]
[ └ ────────────────────────────────────────────────────┘  ]

[ summary line · Refresh now ]      (slice 001, unchanged)
[ host group panels ]               (slice 001, unchanged)
```

The panel is a single `panel panel-default` with a heading reading "Firewall" and a compact
read-only grid of label/value pairs (not an editable form). It renders even when `total_hosts = 0`
and all hosts are `down` (FR-017) — in the zero-host case it is the primary content of the page.

## Element behaviour

| Element | Behaviour |
|---------|-----------|
| Heading | Fixed text "Firewall". Always present; the panel is never removed from the DOM after first render. |
| All-unavailable notice | When `all_unavailable` is `true` — an in-process read that failed to produce a value
  (`sysctl` timeout, non-zero exit, or every read `null`; FR-013): a single discreet inline notice
  ("Firewall telemetry is currently unavailable.") replaces the value grid. The panel box and
  heading remain, and the host groups below are unaffected (FR-017). Clears automatically on the
  next successful read — no user action. |
| Hostname / version | `hostname` and `version` text. Each renders "unavailable" independently when `null`. |
| Uptime + last boot | `uptime_s` rendered as `Xd Xh Xm Xs` (days omitted when zero); `last_boot` rendered in the viewer's **browser-local** time (`toLocaleString()`), matching the slice 001 last-checked convention (FR-003). Each "unavailable" when `null`. |
| Load | `1m / 5m / 15m` with 2 decimals, e.g. `0.31 / 0.48 / 0.52`. The whole cell warns when `"load" ∈ warnings`. |
| CPU usage | `cpu_usage_percent` with 1 decimal and `%`. `null` → "unavailable". No fixed warning threshold (FR-008 lists only memory/disk/load). |
| Memory | Used/total in human-readable units (e.g. `1.1 GiB / 15.6 GiB`) with the percentage `(7%)`. The cell (value + badge) gets the warning highlight when `"memory" ∈ warnings`. `null` → "unavailable", never highlighted. |
| Disk | Same presentation as memory for the root disk. Warning highlight when `"disk" ∈ warnings`. |
| CPU temperature | Rendered **only** when `cpu_temperature_present` is `true` and the value is present. When the flag is `false` the whole row/cell is **removed** — not "unavailable", not a warning, not a placeholder (FR-007, SC-003). When the flag is `true` but the value is `null` (a transient read failure), the cell shows "unavailable". |
| Warning highlight | A distinct, glanceable treatment (Bootstrap `label label-warning`-class badge plus a tinted cell background via a small inline class) on exactly the cells named in `warnings`. Cleared automatically when the value returns under threshold on the next cycle (US2). Highlights are applied by the view reading `warnings`; a `null` value is never highlighted. |
| Freshness / collected_at | A small "as of HH:MM:SS" (browser-local, from `collected_at`) in the panel footer, matching the slice 001 timestamp convention. |

## Update behaviour (inherited — no new timers)

- The panel is updated **in place** by the slice 001 polling loop: `poll()` (`ajaxGet` on
  `get`) and the "Refresh now" handler (`ajaxCall` on `refresh`) both call `render(data)`, and
  `render()` gains a step that updates the Firewall panel cells from `data.firewall`. There is **no
  new timer and no separate request**: the panel refreshes on the exact same ~60 s cycle and the
  same "Refresh now" button as the hosts (FR-010).
- Because the `firewall` object is freshly read in-process on every request (R-02) and the host
  part of the response comes from slice 001's snapshot as usual, both panels update in the same
  response (US1-AS3).
- On a request failure (network/HTTP) the slice 001 banner shows and the last rendered panel is
  kept, exactly as the host rows are — the panel never blanks on a transient error.
- Cell updates use the last rendered values as the baseline; a `null` in a new response changes
  that one cell to "unavailable" without touching siblings (FR-012).
- Escaping: all values, including hostnames and version strings, are inserted with `.text()`.

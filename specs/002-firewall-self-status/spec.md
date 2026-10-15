# Feature Specification: Firewall Self-Status

**Feature Branch**: `002-firewall-self-status`

**Created**: 2026-10-15

**Status**: Draft

**Input**: User description: "Firewall self-status — the second slice of Casa Delta Dashboard v2. What the viewer sees: a 'Firewall' panel at the top of the existing Host Status page, above the host groups, showing the health of the firewall itself: hostname and OPNsense version; uptime, and date/time of last boot; load average (1, 5, 15 minutes) and current CPU usage; memory used and total; disk used and total for the system disk; CPU temperature, only when the hardware reports it (hidden otherwise, not shown as an error). Each value that crosses a fixed threshold is highlighted as a warning (memory or disk above 90%, load above the number of CPU cores). Thresholds are fixed in this slice, not configurable. Behavior: the panel refreshes on the same cycle as host status (about every 60 seconds, plus the existing Refresh now button). The values come from the firewall itself; this slice sends no probes to other hosts. The panel appears even when no hosts are configured or every host is down. If a value cannot be read, that value shows as unavailable and the rest of the panel still renders. Access: viewing the panel uses the existing view privilege from slice 001; no new privilege and no settings page in this slice. Why: v1 showed firewall uptime at the top of the dashboard. During power or internet outages, the firewall's own health is the first thing to check, before the hosts behind it. Out of scope: gateway / WAN status and dual-WAN failover, WAN and VPN IP addresses, interface traffic, history or graphs, alerts, configurable thresholds."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Checking the Firewall Itself First (Priority: P1)

As a Viewer (including read-only operator accounts), I want to see the health of the firewall itself — its hostname, OPNsense version, uptime, last boot, load, CPU, memory, disk, and (when the hardware reports it) CPU temperature — in a "Firewall" panel at the very top of the Host Status page, so that I can confirm the appliance itself is healthy before I worry about the hosts behind it.

**Why this priority**: This is the slice's core capability and the reason it exists. In v1, firewall uptime at the top of the dashboard was the first thing checked during outages; this slice restores and generalizes that. It is the top of the page, the first thing the eye lands on.

**Independent Test**: Can be fully tested on a test OPNsense instance with the Host Status page from slice 001 installed: the Firewall panel renders above all host groups with all values populated from the firewall's own system, and updates on the same ~60 s cycle and via the existing "Refresh now" button. Works with zero configured hosts and with all hosts down.

**Acceptance Scenarios**:

1. **Given** the Host Status page from slice 001 is open in a browser, **When** the page loads, **Then** a "Firewall" panel is displayed at the top of the page, above all host groups, showing at minimum: the firewall's hostname, the installed OPNsense version, current uptime, and the date and time of the last boot.
2. **Given** the Firewall panel is displayed, **When** the viewer reads it, **Then** load average (1, 5, and 15 minute values) and current CPU usage are shown alongside memory used and total, and disk used and total for the system disk.
3. **Given** the host status refresh cycle elapses (~60 s), or the viewer clicks the existing "Refresh now" button, **When** the page refreshes in the background, **Then** the Firewall panel values update in-place without a full page reload, in sync with the host status update.
4. **Given** no monitored hosts are configured, or all monitored hosts are down, **When** the Host Status page is opened, **Then** the Firewall panel is still displayed in full at the top of the page and remains fully functional.

---

### User Story 2 - Spotting a Problem at a Glance (Priority: P2)

As a Viewer, I want any firewall value that crosses a fixed warning threshold to be visually highlighted, so that abnormal values pop out of the panel without me having to read and interpret every number.

**Why this priority**: The raw numbers are only useful if the abnormal ones pop. This is the difference between "glance and know" and "read every row." Thresholds are fixed in this slice (memory > 90%, disk > 90%, 5-minute load > core count); configurability is explicitly out of scope.

**Independent Test**: On a test instance, artificially push a metric above its fixed threshold (e.g., fill a file to push system disk usage above 90%, or hold sustained CPU load above the core count with a bounded load generator), refresh the panel, and verify that value — and only that value — renders with the warning highlight; then remove the pressure and verify the highlight clears on the next cycle.

**Acceptance Scenarios**:

1. **Given** the firewall's used memory exceeds 90% of total memory, **When** the panel refreshes, **Then** the memory value is displayed with a warning highlight and a warning indicator; all other in-threshold values render normally.
2. **Given** the system disk usage exceeds 90% of its total capacity, **When** the panel refreshes, **Then** the disk value is displayed with a warning highlight; memory and all other in-threshold values render normally.
3. **Given** the 5-minute load average exceeds the number of CPU cores on the appliance, **When** the panel refreshes, **Then** the load value is displayed with a warning highlight.
4. **Given** any value previously highlighted as a warning returns below its threshold, **When** the next refresh occurs, **Then** the warning highlight is cleared and the value renders in its normal style.
5. **Given** multiple values simultaneously cross their thresholds, **When** the panel refreshes, **Then** each crossing value is independently highlighted, and non-crossing values are unaffected.

---

### User Story 3 - Unreadable Values Degrade Gracefully (Priority: P3)

As a Viewer, I want any single firewall value that cannot be read (such as CPU temperature on hardware that reports none) to show as "unavailable" while the rest of the panel renders normally, so that one missing sensor never blanks or breaks the panel.

**Why this priority**: Different hardware exposes different sensors; the panel must be trustworthy on any appliance, including minimal or virtualized ones. Graceful degradation keeps the panel useful during exactly the outage scenarios it exists for (Constitution Principle III — Self-Sufficient).

**Independent Test**: On a test instance that exposes no CPU temperature sensor (e.g., a VM or a platform without thermal reporting), load the page and verify the temperature row is absent (never shown as an error), every other value renders, and no error or blank panel appears; then observe a transient read failure of any other value (e.g., a metric momentarily unreadable) and verify only that value shows "unavailable."

**Acceptance Scenarios**:

1. **Given** the appliance hardware does not report a CPU temperature, **When** the panel renders, **Then** the temperature value is simply not shown (not as an error, warning, or placeholder row).
2. **Given** the appliance hardware does report a CPU temperature, **When** the panel renders, **Then** the temperature is displayed.
3. **Given** any single telemetry value fails to be read during a refresh, **When** the panel updates, **Then** that value displays as "unavailable" and every other value renders normally with its real data.
4. **Given** multiple values fail to be read, **When** the panel updates, **Then** each failed value individually shows "unavailable" and the panel remains fully rendered with the remaining values.

---

### Edge Cases

- **All values unavailable**: If every telemetry value fails to read in one refresh, the panel renders with all values showing "unavailable" and a single discreet notice that firewall telemetry is currently unavailable; the host status groups below remain fully functional, and the panel recovers automatically on the next successful refresh.
- **Freshly rebooted firewall**: Immediately after a reboot, low uptime and reset load averages are valid values and render normally with no special handling; uptime is monotonically increasing and resets to a small value after each boot, with the last boot timestamp updating accordingly.
- **Zero-configured hosts**: With no hosts configured (or all disabled), the page is not empty: the Firewall panel is the primary content of the page and hosts section shows the slice 001 empty state ("No monitored hosts configured or enabled").
- **All hosts down**: No change to the Firewall panel; host rows show `down` as in slice 001 while the firewall telemetry displays normally.
- **Large ZFS cache**: A healthy firewall with a large ZFS ARC cache (reclaimable memory) MUST NOT trigger a memory warning; only memory not available to applications counts toward the 90% threshold.
- **Warning highlight with unavailable values**: A value that is "unavailable" never receives a warning highlight; highlights apply only to successfully read values that cross a threshold.
- **Clock changes**: Uptime and last boot are derived from the system clock at read time; no history is kept, so a wall-clock adjustment is simply reflected in the next displayed values without spurious warnings.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST display a "Firewall" panel at the top of the existing Host Status page (slice 001), positioned above all host groups, presenting the health of the firewall appliance itself.
- **FR-002**: System MUST display, in the Firewall panel: the firewall's hostname and the installed OPNsense version.
- **FR-003**: System MUST display current uptime and the date and time of the last boot, rendered in the viewer's local time.
- **FR-004**: System MUST display load average (1, 5, and 15 minute values) and current CPU usage.
- **FR-005**: System MUST display memory used and total, presented in human-readable units with the used/total percentage. The used amount MUST be defined as memory not available to applications: reclaimable memory (ZFS ARC cache, inactive and laundry pages) MUST NOT be counted as used, and the percentage MUST be computed on that definition.
- **FR-006**: System MUST display disk used and total for the system (root) disk, presented in human-readable units with the used/total percentage.
- **FR-007**: System MUST display CPU temperature only when the hardware reports it; on hardware that does not report it, the value MUST be omitted from the panel entirely and MUST NOT be shown as an error, warning, or "unavailable" placeholder.
- **FR-008**: System MUST highlight as a warning, with a distinct visual treatment, any successfully read value that crosses one of the following fixed thresholds:
  - Memory used percentage greater than 90%.
  - System disk used percentage greater than 90%.
  - 5-minute load average greater than the number of CPU cores.
- **FR-009**: These thresholds MUST be fixed for this slice; no configuration option for them exists in this slice.
- **FR-010**: The panel MUST refresh on the same ~60-second cycle as the host status in slice 001 and in response to the existing "Refresh now" button, in the background without a full page reload.
- **FR-011**: All Firewall panel values MUST be read from the firewall's own operating system; this slice MUST NOT send probes or requests to any other host to produce them.
- **FR-012**: If any individual telemetry value cannot be read, that value MUST be displayed as "unavailable" and all other values MUST render normally; the panel MUST remain visible and correctly laid out in all cases.
- **FR-013**: If all values fail to read in one refresh, the panel MUST still render, showing "unavailable" for each value plus a single discreet notice that telemetry is temporarily unavailable, and MUST recover automatically on the next successful refresh without user action.
- **FR-014**: Viewing the Firewall panel MUST use the existing dashboard view privilege introduced in slice 001 (`page-casadelta-status`); this slice adds no new privilege and no settings page.
- **FR-015**: Firewall telemetry MUST be ephemeral and read on demand per refresh cycle; this slice MUST NOT persist telemetry history and MUST NOT add any permanently running daemon.
- **FR-016**: Telemetry reads MUST be bounded (strict timeout per value) so that an unresponsive source cannot stall the shared refresh cycle or the host status display.
- **FR-017**: The panel MUST render and update independently of host status availability: it MUST appear and function even when no hosts are configured or every host is down.

### Key Entities

- **FirewallTelemetry**: Ephemeral runtime snapshot of the firewall's own health, taken per refresh cycle and not persisted.
  - `hostname` (string): The firewall's system hostname.
  - `version` (string): Installed OPNsense version.
  - `uptime` (duration): Continuous time since last boot.
  - `last_boot` (timestamp): Date and time of the last boot.
  - `load_avg_1m`, `load_avg_5m`, `load_avg_15m` (float): Load averages over 1, 5, and 15 minutes.
  - `cpu_cores` (integer): Number of logical CPU cores (the basis for the load warning threshold).
  - `cpu_usage_percent` (float): Current CPU usage percentage.
  - `memory_used` / `memory_total` (size) and `memory_used_percent` (float): Memory consumption; `memory_used` is memory not available to applications — reclaimable memory (ZFS ARC, inactive and laundry pages) is excluded from it.
  - `disk_used` / `disk_total` (size) and `disk_used_percent` (float): System disk consumption.
  - `cpu_temperature` (float, optional): Present only when the hardware reports it; absent otherwise.
  - `warnings` (set of metric names): The metrics currently exceeding their fixed thresholds.
  - `available` (per-metric flag): Whether each metric was successfully read; unreadable metrics render as "unavailable."
  - `collected_at` (timestamp): When the snapshot was taken.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: During an outage, a viewer can confirm the firewall itself is healthy in a single glance at the top of the Host Status page: the panel loads with complete values within 3 seconds of page load on baseline hardware, and all core values (hostname, version, uptime, load, memory, disk) are present without scrolling.
- **SC-002**: A metric crossing a fixed warning threshold (memory used > 90%, disk used > 90%, 5-minute load average > core count) is visually distinguishable from normal values within the same refresh cycle — no manual refresh required; in the normal case, zero false-positive warnings occur (a highlight implies the threshold was actually crossed).
- **SC-003**: On hardware without a CPU temperature sensor, 100% of page loads render the panel fully with no error, warning, or placeholder for temperature, and no console or page-level errors.
- **SC-004**: With zero hosts configured and with all hosts down, the page is fully usable: the Firewall panel renders and updates in every case, and the host section degrades to the slice 001 empty/down state without affecting the panel.
- **SC-005**: Each telemetry read uses only local sources on the firewall (short-lived local commands such as sysctl are acceptable), completes within the per-value timeout of FR-016, and adds no noticeable delay to the host status refresh.
- **SC-006**: View-only accounts with the slice 001 dashboard view privilege can read the complete Firewall panel; accounts without that privilege get no access to it, and no configuration surface for the panel exists.

## Assumptions

- **Host Status page exists**: Slice 001 (`001-host-status-monitoring`) is installed and provides the Host Status page, its ~60 s background refresh, its "Refresh now" button, and its `page-casadelta-status` view privilege. This slice extends that page; it does not modify host checking behavior.
- **Target Appliance Environment**: OPNsense 26.7 on FreeBSD 15 (per the project Constitution); system telemetry is available from standard OS sources on that platform.
- **Time presentation**: Uptime and last boot render in the viewer's browser-local time, consistent with the last-checked timestamps of slice 001.
- **Privilege reuse**: The existing `page-casadelta-status` view privilege from slice 001 gates the whole page, so the panel inherits access control with no new ACL definition.
- **No persistence**: Firewall telemetry is ephemeral, read on demand per refresh cycle; no history is stored and no daemon is added (consistent with slice 001's view-driven execution and Constitution Principle II).
- **No settings surface**: Fixed thresholds mean no configuration state; per Constitution Principle VI the slice is a complete user-visible capability without a settings page.
- **Scope Boundary**: Gateway/WAN status and dual-WAN failover, WAN and VPN IP addresses, interface traffic, telemetry history or graphs, alerts, and configurable thresholds are explicitly deferred to future vertical slices.

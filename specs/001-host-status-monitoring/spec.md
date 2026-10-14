# Feature Specification: Host Status Monitoring

**Feature Branch**: `001-host-status-monitoring`

**Created**: 2026-10-14

**Status**: Clarified

**Input**: User description: "Host status monitoring — the first slice of Casa Delta Dashboard v2. Users: Administrator (maintains list), Viewer (looks at status). Admin capabilities: add, edit, disable, delete hosts (display name [unique], address [IP/hostname], group, optional description, enabled flag). Invalid addresses rejected at save. Viewer view: status page listing enabled hosts grouped in stable order with state (up, down, cannot resolve), latency when up, time of last check. 60s auto-refresh without full reload. Down detected within one cycle. DNS failure shows as 'cannot resolve'. Summary line shows up/total. Checks run only when viewed, results shared across viewers. System failure explicitly shown. Disabled hosts not checked/shown. Why: replaces v1 hardcoded PHP list, proves vertical plugin slice end-to-end. Out of scope: firewall uptime, WAN/VPN IP, clock, theming, service checks, alerts, history/graphs, v1 import."

## Clarification Summary

The following design decisions were explicitly clarified and integrated into this specification:
1. **Group Hierarchy & Sorting**: Groups follow a fixed top-level hierarchy (`Network` → `Nodes` → `Apps` → any additional custom groups sorted alphabetically). Hosts within each group are sorted by an integer sort order column (ascending, defaulting to 0 if not specified), with secondary sorting alphabetically by display name.
2. **Address & Port Formats**: The address field accepts IPv4, IPv6, or hostnames with an optional port suffix (e.g., `192.168.1.10:8006`, `pve.lan:8006`, `[2001:db8::1]:8006`) to accommodate multiple containers/VMs sharing a single IP. Reachability checks strip the port to run ICMP ping against the target host while preserving the full address:port in the UI.
3. **Manual Refresh**: In addition to ~60s background polling, the UI provides an on-demand "Refresh now" button with a brief rate-limiting cooldown (e.g., 5 seconds) to prevent backend check flooding.
4. **ICMP Probe Strategy**: Checks transmit 2 ICMP echo request packets per host with bounded timeout to absorb transient LAN jitter and prevent false-positive `down` alerts. If at least one packet replies, the host is marked `up` with recorded round-trip latency.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Viewing Live Host Status Dashboard (Priority: P1)

As a Viewer (including read-only operator accounts), I want to view a grouped, automatically refreshing status dashboard of all enabled monitored hosts showing their reachability state, latency, and last check time, with the ability to trigger a manual refresh on demand, so that I have instant visibility into homelab infrastructure health without placing unnecessary load on the firewall.

**Why this priority**: This represents the core MVP capability and primary user-facing value proposition of the Casa Delta Dashboard plugin. Delivering this story proves the end-to-end telemetry pipeline (model → configd backend action → API controller → Volt view/auto-refresh).

**Independent Test**: Can be fully tested on a test OPNsense instance by configuring sample hosts and opening the status page. Verifies grouped layout following the fixed category hierarchy (`Network` → `Nodes` → `Apps` → custom groups A–Z), state badge indicators (`up`, `down`, `cannot resolve`), round-trip latency display for reachable hosts, summary counter, on-demand refresh button, and periodic background refresh (~60s) without full page reload.

**Acceptance Scenarios**:

1. **Given** multiple enabled hosts configured across distinct groups (e.g., "Network", "Nodes", "Apps", "CustomGroup"), **When** the viewer navigates to the Host Status page, **Then** all enabled hosts are displayed grouped according to the fixed hierarchy (`Network` first, `Nodes` second, `Apps` third, followed by custom groups in alphabetical order) and sorted within each group by their integer sort order column (ascending, defaulting to 0 if not specified, with tie-breaking alphabetically by display name), showing display name, address (including optional port), state (`up`, `down`, or `cannot resolve`), round-trip latency in milliseconds (when `up`), and timestamp of the last check.
2. **Given** the status page is open in a browser, **When** approximately 60 seconds elapse, **Then** the page automatically fetches updated status in the background and updates the UI in-place without a full page reload or UI flicker.
3. **Given** the status page is open in a browser, **When** the viewer clicks the "Refresh now" button, **Then** the UI triggers an immediate status check and disables the button temporarily with a visual cooldown indicator (e.g., 5s) to prevent spamming.
4. **Given** a monitored host that is currently `up`, **When** the host ceases answering ICMP checks across both probe packets, **Then** within one refresh cycle (~60s), the status page transitions the host state to `down`.
5. **Given** a host configured with a hostname that fails DNS resolution, **When** status checks execute, **Then** the host state is displayed as `cannot resolve` (explicitly differentiated from `down`).
6. **Given** multiple viewers viewing the status page concurrently, **When** polling requests arrive, **Then** cached/recent check results are returned to prevent multiplying backend check execution or spawning duplicate firewall processes.
7. **Given** a backend check mechanism failure (e.g., configd timeout or script execution error), **When** status is requested, **Then** the UI displays an explicit check subsystem error banner rather than falsely marking all hosts as `down`.
8. **Given** the status page is loaded, **When** looking at the page header, **Then** an aggregate summary line displays the total count of operational hosts (e.g., "Summary: 8 up out of 10 hosts").

---

### User Story 2 - Managing Monitored Hosts in Firewall Configuration (Priority: P2)

As an Administrator, I want to add, edit, disable, and delete monitored host records from a dedicated settings page with validation rules supporting optional port suffixes, so that the monitored host inventory (including virtual machines or containers sharing an IP) is maintained directly within the firewall's XML configuration without manual file editing.

**Why this priority**: Enables self-service administrative lifecycle management of monitored hosts, directly eliminating the v1 deficiency where hosts were hardcoded in PHP files on the firewall filesystem.

**Independent Test**: Can be tested independently by logging in as an administrator, performing CRUD operations via the settings UI, and verifying that records persist correctly in OPNsense's central configuration XML, survive service reloads, and enforce uniqueness and address syntax validation (including IP:port and hostname:port syntax).

**Acceptance Scenarios**:

1. **Given** an administrator on the Host Settings page, **When** the admin submits a new host with a unique display name, valid address (IPv4, IPv6, or hostname, with or without an optional port suffix such as `192.168.1.10:8006` or `pve.lan:8006`), group name, optional sort order integer (defaulting to 0), optional description, and enabled flag set to true, **Then** the host is saved to the firewall configuration and appears in the host management grid.
2. **Given** an administrator creating or editing a host, **When** the admin enters a display name that matches an existing host, **Then** the save operation is rejected with a clear validation message indicating display names must be unique.
3. **Given** an administrator creating or editing a host, **When** the admin enters an invalid address format (e.g., malformed IP string, invalid port, or invalid hostname syntax), **Then** the save operation is rejected with a clear validation message.
4. **Given** an existing monitored host, **When** the administrator toggles its enabled flag to disabled (false), **Then** the host record remains in the settings grid but is immediately excluded from backend checks and omitted from the viewer status page.
5. **Given** an existing monitored host, **When** the administrator deletes the host record, **Then** it is permanently removed from the configuration and no longer checked or displayed.

---

### User Story 3 - Role-Based Access Control & Privilege Separation (Priority: P3)

As an Administrator / Security Officer, I want viewing dashboard status and modifying host configuration to be gated by separate, distinct OPNsense ACL privileges, so that read-only operator accounts can monitor homelab status without having permission to alter firewall settings.

**Why this priority**: Required by Constitution Principle V (Firewall Identity and Least Privilege) to maintain firewall security boundaries.

**Independent Test**: Can be independently tested by assigning a test user an ACL privilege granting only dashboard viewing rights, verifying successful access to the status page and read API, and confirming HTTP 403 / ACL denial when attempting to access the settings UI or mutate host configuration.

**Acceptance Scenarios**:

1. **Given** a user account with only the dashboard viewing ACL privilege, **When** the user accesses the status dashboard, **Then** access is granted and status telemetry is visible; **When** the user attempts to load the settings page or send POST/PUT/DELETE requests to configuration endpoints, **Then** access is strictly denied by the firewall ACL system.
2. **Given** an administrator account with dashboard configuration ACL privileges, **When** the administrator accesses the settings page and mutation API endpoints, **Then** access and full CRUD operations are authorized.

---

### Edge Cases

- **Zero Enabled Hosts**: When no hosts are configured or all hosts are disabled, the status page displays a helpful empty state ("No monitored hosts configured or enabled") rather than an error or blank screen.
- **Mixed Address Types & Port Suffixes**: System correctly resolves and tests IPv4 addresses (e.g. `192.168.1.10`, `192.168.1.10:8006`), IPv6 addresses (e.g. `fe80::1`, `[2001:db8::1]:8006`), and standard hostnames/FQDNs (e.g. `pve.lan`, `pve.lan:8006`). Address validation accepts valid port numbers (1–65535), and the reachability probe cleanly strips the port suffix prior to hostname resolution and ICMP execution.
- **Slow / Unresponsive Targets**: When one or more hosts do not respond, checks execute with strict per-host timeouts (≤1-2s per probe) and bounded concurrency so that unresponsive hosts do not delay status reporting for responsive hosts or exceed the polling window.
- **Transient Jitter Handling (Two Probes)**: If a host drops the first ICMP packet due to local network jitter or ARP resolution delay but answers the second packet, it is accurately reported as `up`.
- **View-Driven Execution (No Zombie Daemons)**: When no users have the status page open in a browser, backend checks are not triggered; no persistent background daemon runs continuously.
- **Multiple Concurrent Viewers**: Status queries from multiple concurrent browser sessions reuse the shared recent result cache (e.g., results younger than 55 s, shared between viewers; see data-model.md §4) to avoid redundant configd/ICMP load spikes on the firewall CPU.
- **Rapid Manual Refresh Cooldown**: When a user rapidly clicks "Refresh now", the frontend and backend enforce a rate-limiting cooldown (5 seconds) to prevent CPU resource starvation on the firewall.
- **Transient DNS Lookup Failure**: Hostnames failing DNS resolution are flagged specifically as `cannot resolve` with diagnostic context, preserving distinction from reachable network hosts that are actively dropping ICMP packets.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST store all monitored host configuration parameters (UUID, display name, address, group, sort order, description, enabled flag) in OPNsense's configuration XML using an OPNsense XML-defined MVC Model (`PRSWS\CasaDelta\CasaDelta`, mounted at `//PRSWS/CasaDelta`), as entries of its `hosts.host` container. This is the plugin's single main model; later slices add their own containers next to `hosts` in the same model instead of creating new mount points.
- **FR-002**: System MUST enforce that host display names are unique across all configured hosts at validation time before persisting to configuration.
- **FR-003**: System MUST validate address fields to accept valid IPv4, valid IPv6, or valid hostname/FQDN strings, optionally followed by a standard port suffix (`:port` for IPv4/hostname or `[IPv6]:port` for IPv6, with port in range 1–65535), rejecting malformed inputs with descriptive error messages.
- **FR-004**: System MUST provide an administrative settings Volt view and REST API controller (`Api\SettingsController`, no service controller needed) providing full CRUD capabilities (list, get, add, update, delete, toggle enabled) using native OPNsense UI form helpers and data grids.
- **FR-005**: System MUST provide a viewer status Volt view and read-only status API endpoint (`StatusController`) rendering all enabled hosts categorized by their configured group.
- **FR-006**: System MUST present host groups according to a fixed top-level category hierarchy: `Network` first, `Nodes` second, `Apps` third, followed by any custom groups sorted alphabetically (A–Z). Within each group, hosts MUST be presented sorted by an integer sort order column (ascending, defaulting to 0 if not specified), with secondary sorting alphabetically by display name.
- **FR-007**: System MUST classify each enabled host into one of three distinct runtime states:
  - `up`: Host answered at least 1 of 2 ICMP echo request packets within timeout; round-trip latency in milliseconds is recorded and displayed.
  - `down`: Address resolved or was valid IP, but host failed to respond to both ICMP echo request packets within timeout.
  - `cannot resolve`: Hostname failed DNS resolution.
- **FR-008**: System MUST display the timestamp of the last status evaluation alongside each host result.
- **FR-009**: System MUST display an aggregate summary counter in the status dashboard view indicating the count of `up` hosts relative to the total number of enabled hosts (e.g., "X up out of Y total").
- **FR-010**: System MUST automatically refresh status telemetry in the client browser at approximately 60-second intervals via background AJAX/Fetch requests without a full page reload, and MUST provide an on-demand "Refresh now" button with a 5-second rate-limiting cooldown.
- **FR-011**: System MUST execute reachability checks on-demand through an OPNsense `configd` action (`casadelta check_hosts`) triggered only when an active viewer requests status or clicks manual refresh.
- **FR-012**: System MUST implement a caching mechanism for check results (with a validity window aligned with the ~60s refresh cycle) so that multiple simultaneous viewers share recent results rather than spawning duplicate check processes.
- **FR-013**: System MUST execute host checks by sending 2 ICMP echo request packets per host with bounded concurrency and strict per-host timeouts (≤1-2 seconds per probe) to prevent CPU/memory starvation on baseline appliance hardware (4-core Intel N6005).
- **FR-014**: System MUST explicitly detect check execution failures (such as backend script crashes or configd timeouts) and display a dedicated subsystem warning banner on the dashboard rather than reporting all hosts as `down`.
- **FR-015**: System MUST exclude disabled hosts (`enabled = false`) from all reachability checks and omit them from the viewer status dashboard.
- **FR-016**: System MUST define distinct OPNsense ACL privileges:
  - `Dashboard Status (View)` (key `page-casadelta-status`): Grants read-only access to the status view (`/ui/casadelta/status`) and status API endpoints (`/api/casadelta/status/*`).
  - `Dashboard Settings (Manage)` (key `page-casadelta-settings`): Grants access to the settings view (`/ui/casadelta/settings`) and configuration CRUD API endpoints (`/api/casadelta/settings/*`).
- **FR-017**: System MUST bundle all stylesheets, JavaScript, and assets locally; zero external CDN, remote font, or third-party web dependencies are permitted.
- **FR-018**: System MUST remain strictly read-only with respect to all core firewall networking rules, routing tables, NAT configurations, and interface assignments.

### Key Entities

- **MonitoredHost**: Persisted configuration entity representing a target host.
  - `uuid` (string, generated): OPNsense model internal identifier.
  - `name` (string, required, unique): Display label for the host (e.g. "Primary Gateway", "Home Assistant", "Proxmox Node 1").
  - `address` (string, required): IP address (v4/v6) or resolvable hostname, with optional port suffix (e.g. `192.168.1.1`, `192.168.1.10:8006`, `pve.lan:8006`, `[2001:db8::1]:8006`).
  - `group` (string, required): Logical category group name (e.g. "Network", "Nodes", "Apps", or custom category).
  - `sort_order` (integer, optional, default 0): Display sequence order within the assigned group (ascending).
  - `description` (string, optional): Human-readable operational notes or purpose.
  - `enabled` (boolean, default true): Flag controlling whether the host is actively monitored and displayed.

- **HostStatusResult**: Ephemeral runtime telemetry evaluated for an enabled host.
  - `host_uuid` (string): Reference to the monitored host UUID.
  - `name` (string): Host display name.
  - `address` (string): Configured address (including optional port for display).
  - `group` (string): Host group name.
  - `sort_order` (integer, default 0): Integer display sequence.
  - `state` (enum: `up`, `down`, `cannot_resolve`): Current reachability state.
  - `latency_ms` (float/int, optional): Round-trip ICMP time in milliseconds (present only when state is `up`).
  - `last_checked` (string/ISO8601): Timestamp of check completion.
  - `error_message` (string, optional): Diagnostic detail for unresolvable hostnames or execution anomalies.

- **DashboardStatusSummary**: High-level aggregated telemetry payload.
  - `total_hosts` (integer): Total count of enabled hosts.
  - `up_hosts` (integer): Count of hosts currently in `up` state.
  - `down_hosts` (integer): Count of hosts currently in `down` state.
  - `unresolved_hosts` (integer): Count of hosts currently in `cannot_resolve` state.
  - `last_run` (string/ISO8601): Timestamp of the most recent evaluation run.
  - `subsystem_status` (enum: `ok`, `error`): Health of the check execution backend.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An administrator can add, validate, and save a new monitored host (including entries with optional port numbers) in under 20 seconds through the native OPNsense settings interface.
- **SC-002**: 100% of invalid addresses, malformed ports, and duplicate host display names are rejected at form submission time with clear error indicators, preventing corrupt configuration.
- **SC-003**: The status dashboard renders initial telemetry in under 2 seconds when cached results exist, and within ≤5 seconds on a cold check run.
- **SC-004**: Host state changes (e.g., host disconnected or recovered) are accurately reflected on the viewer dashboard within one refresh cycle (60 seconds ± 5s) or immediately upon clicking "Refresh now".
- **SC-005**: Simultaneous viewing by up to 10 concurrent browser sessions generates no more than 1 backend check execution per 60-second window, maintaining negligible CPU utilization (<2% on baseline 4-core N6005).
- **SC-006**: View-only accounts with dashboard view privilege can inspect complete status telemetry with zero access to settings or configuration mutation endpoints.
- **SC-007**: When all external LAN hosts and WAN connectivity are offline, the dashboard status view loads and renders locally without hanging, asset timeouts, or broken layouts.

## Assumptions

- **Target Appliance Environment**: OPNsense 26.7 running on FreeBSD 15 with standard PHP 8 runtime and native OPNsense Phalcon MVC framework.
- **Protocol**: Reachability monitoring uses standard ICMP ping (`/sbin/ping` with `-4`/`-6`, executed as root through configd) sending 2 probe packets per host, with system DNS resolution via standard OS resolver (`getaddrinfo` / `gethostbyname`). Ports specified in addresses are parsed out and preserved for display while ICMP reachability checks target the extracted IP or hostname.
- **Network Permissions**: Firewall local system has routing/firewall policy allowing ICMP traffic to monitored LAN/VLAN subnets.
- **Asset Standards**: UI views leverage standard OPNsense Volt templates, Bootstrap/jQuery, and native OPNsense UI components provided by the base firewall web UI.
- **Scope Boundary**: Advanced capabilities such as TCP port/HTTP service checks, notification alerts, uptime time-series graphs, and legacy v1 configuration migration tools are explicitly deferred to future vertical slices.

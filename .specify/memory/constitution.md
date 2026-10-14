<!--
### Sync Impact Report
- Version change: 1.0.0 → 2.0.0
- Modified principles:
  - I. Library-First & Modularity → I. Firewall-Native
  - II. Strict Interface Contracts → II. The Firewall Must Not Be Harmed
  - III. Test-First Quality Gates (NON-NEGOTIABLE) → III. Self-Sufficient
  - IV. Integration & Boundary Verification → IV. Configuration Lives in the Firewall
  - V. Observability & Simplicity (YAGNI) → V. Firewall Identity and Least Privilege
- Added sections:
  - VI. Thin Vertical Slices
  - VII. Use What the Platform Provides
  - VIII. Never Develop Against Production
  - Technical Constraints & Standards
  - Development Workflow & Quality Gates
- Removed sections: None
- Follow-up TODOs: None
-->

# Casa Delta Dashboard v2 Constitution

## Core Principles

### I. Firewall-Native
The dashboard MUST ship strictly as an installable OPNsense plugin package and MUST be built exclusively with OPNsense's native plugin framework: XML-defined models, API controllers, Volt views, menu and ACL definitions, and configd actions for backend execution. Standalone web roots, manual edits to the firewall's web server configuration, modifications to base PHP files, or alterations to system files are strictly PROHIBITED. Uninstalling the plugin MUST leave no residue on the filesystem or operating system, preserving only its designated configuration section.
*Rationale*: Leveraging native plugin architecture prevents system corruption, simplifies upgrades, eliminates packaging friction, and ensures clean lifecycle management without compromising system integrity.

### II. The Firewall Must Not Be Harmed
Routing, packet filtering, and network availability represent the primary, non-negotiable functions of the appliance; dashboard telemetry is always secondary. The plugin MUST be strictly read-only toward all firewall networking configuration, including packet filtering rules, interface assignments, static/dynamic routes, and NAT tables. Every status check MUST execute with a strict timeout, bounded concurrency, and an enforced minimum polling interval. Permanently running daemons are PROHIBITED unless explicitly justified and approved in the implementation plan's Complexity Tracking. Aggregate CPU and memory overhead MUST remain negligible on baseline hardware (4-core Intel N6005).
*Rationale*: Protecting the firewall from resource starvation, race conditions, or configuration degradation prevents network outages and maximizes overall homelab reliability and utility.

### III. Self-Sufficient
The dashboard MUST remain fully functional and responsive even when every other host across the homelab infrastructure is offline. All static UI assets, scripts, stylesheets, and fonts MUST be bundled locally with zero dependencies on external CDNs or other LAN servers. Features that inherently require external internet connectivity (such as public WAN IP lookup) MUST fail gracefully and display clear degraded-state indicators rather than breaking the UI, hanging requests, or crashing pages.
*Rationale*: An operational visibility tool provides the highest utility precisely during catastrophic local network outages and must never fail due to cascading external dependencies.

### IV. Configuration Lives in the Firewall
All configuration parameters, including the monitored-host inventory and third-party service credentials, MUST be persisted via plugin models directly within OPNsense's central configuration XML. All settings MUST be managed exclusively through the native GUI or REST API, survive reboots and firmware upgrades, and be fully included in standard firewall configuration backups and restores. The use of `.env` files or storing secrets in web-accessible directories is strictly PROHIBITED. Secrets MUST never be written to logs, MUST never be displayed in plain text after initial persistence, and MUST never be returned in plain text by the API.
*Rationale*: Centralizing configuration within the native appliance schema guarantees automated backup integration, atomic state management, and robust protection against credential leaks.

### V. Firewall Identity and Least Privilege
User authentication and authorization MUST integrate directly with OPNsense's existing user database, group hierarchies, and privilege ACLs with no separate login system or redundant credential store. Viewing status metrics and modifying plugin configuration MUST be gated behind distinct, granular privileges, ensuring read-only operator accounts can inspect dashboard telemetry without permission to alter settings.
*Rationale*: Reusing the appliance identity layer reduces operational friction, eliminates redundant authentication attack surfaces, and enforces least-privilege security.

### VI. Thin Vertical Slices
Each feature specification MUST deliver an independently testable, user-visible capability that operates end-to-end across all architectural layers: XML model, settings interface, backend configd action, API controller, Volt view/widget, and ACL definition. Every slice MUST be installable, functional, and demonstrable on its own; horizontal, "framework-first" specifications lacking direct end-user utility are PROHIBITED.
*Rationale*: Delivering functional vertical slices produces immediate verifiable value, shortens feedback loops, and avoids unvalidated, dead architectural complexity.

### VII. Use What the Platform Provides
Implementations MUST prioritize OPNsense's built-in UI components, form helpers, data grids, and dashboard widget framework over external libraries and custom frameworks. Introducing any third-party UI library, runtime dependency, or custom asset compilation build step MUST be explicitly justified and documented in the plan's Complexity Tracking section.
*Rationale*: Standardizing on platform primitives reduces bundle bloat, prevents visual divergence, eliminates dependency vulnerabilities, and minimizes long-term maintenance burden.

### VIII. Never Develop Against Production
All development, integration testing, and validation MUST occur on a dedicated non-production OPNsense instance (such as a local or virtualized test appliance). Production firewalls MUST only receive packaged release artifacts that have successfully passed comprehensive verification on the test instance. Every feature specification MUST include both automated tests (for models, controllers, and backend scripts where supported) and an explicit manual verification checklist validated on the test instance.
*Rationale*: Isolating development to non-production environments prevents accidental disruption of active perimeter security, ensuring safe deployment and maximum service continuity.

## Technical Constraints & Standards

- **Target Platform**: OPNsense 26.7 running on FreeBSD 15.
- **Convention Adherence**: Code structure, file naming, and directory layout MUST strictly follow official OPNsense plugin conventions (e.g., standard `opnsense/mvc/app/...` hierarchies).
- **Version Compatibility**: Each plugin release MUST explicitly declare its minimum supported OPNsense version in metadata.
- **Security & Validation**: Strict input sanitization, CSRF protection, and output encoding MUST be applied across all API endpoints and Volt templates.

## Development Workflow & Quality Gates

- **Specification Gate**: Feature work requires an approved specification detailing UI contracts, configd backend actions, and ACL mappings prior to implementation.
- **Verification Gate**: Features require dual verification: automated unit/integration validation where test harnesses permit, accompanied by a completed manual test checklist on a test VM instance.
- **Complexity Tracking**: Any deviations from native components, additions of background routines, or introduced third-party libraries require explicit entries in the plan's Complexity Tracking section.

## Governance

This Constitution supersedes all specs, implementation plans, and ad-hoc conventions for Casa Delta Dashboard v2. Every implementation plan MUST include a dedicated Constitution Check verifying strict adherence to these principles; any conflict MUST either be eliminated or formally justified within Complexity Tracking.

Amendments to this Constitution require an explicit modification, documented rationale, and Semantic Versioning updates:
- **MAJOR**: Removing, restructuring, or redefining existing core principles.
- **MINOR**: Adding new principles or materially expanding governance sections.
- **PATCH**: Editorial clarifications, typo corrections, and non-semantic refinements.

All modifications MUST be tracked in the Sync Impact Report at the top of this document.

**Version**: 2.0.0 | **Ratified**: 2026-10-14 | **Last Amended**: 2026-10-14

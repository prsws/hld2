# Contract: `casadelta check_firewall` — **REMOVED**

> **Status: removed in this revision of the plan.** This document previously defined the configd
> action `casadelta check_firewall` and its `scripts/CasaDelta/firewall_status.php` script.
>
> The redesign reads firewall telemetry **in-process** in `Api\StatusController` via the pure
> `PRSWS\CasaDelta\Lib\FirewallTelemetryReader` class. Therefore this slice adds:
>
> - **no configd action** — `service/conf/actions.d/actions_casadelta.conf` keeps only the slice 001
>   `[check_hosts]` entry, unchanged;
> - **no backend script** — there is no `scripts/CasaDelta/firewall_status.php`;
> - **no `/tmp` files** — no snapshot, no lock, no lock/tmp artifacts; `+POST_DEINSTALL.post` stays
>   unchanged (slice 001);
> - **no parallel `proc_open` pool** — the only launched processes are the bounded `/sbin/sysctl -i`
>   calls (full OID set + short `kern.cp_time` delta, serial, 2 s deadline each) inside the web
>   request.
>
> Do **not** implement from this file. See [research R-02](../research.md#r-02-telemetry-read-in-process--no-configd-action-no-script-no-snapshot)
> and [firewall-api.md](./firewall-api.md) for the current design.

# Firewall telemetry fixtures (slice 002)

Used by `tests/unit/FirewallTelemetryReaderTest.php` and `FirewallStatusAssemblerTest.php` (tasks.md T004, T006, T015, T019).

## Captured on the test instance (192.168.1.1), 2026-10-16

OPNsense 26.7.7, UFS root (`/dev/gpt/rootfs`), Intel Pentium Silver N6005 (4 cores), `coretemp` loaded. Both `sysctl` calls exited 0. Call 2 was taken 0.5 s after call 1.

| File | Command |
|------|---------|
| `sysctl_call1_ufs.txt` | `/sbin/sysctl -i kern.hostname kern.boottime vm.loadavg hw.ncpu kern.cp_time hw.physmem hw.pagesize vm.stats.vm.v_page_count vm.stats.vm.v_free_count vm.stats.vm.v_inactive_count vm.stats.vm.v_laundry_count kstat.zfs.misc.arcstats.size` — 11 lines: the ARC OID is absent on UFS and `-i` skips it silently |
| `sysctl_call2.txt` | `/sbin/sysctl -i kern.cp_time dev.cpu.0.temperature dev.cpu.1.temperature dev.cpu.2.temperature dev.cpu.3.temperature` |
| `version_core.json` | `cat /usr/local/opnsense/version/core` — expected display `OPNsense 26.7.7` |

Derived values for assertions: UFS memory used = (4024592 − 3578749 − 81055 − 0) × 4096 = 1494171648 bytes (8.8% of `hw.physmem` 16904163328). CPU between the two samples: Δ = (0, 0, 1, 0, 244) → 0.41%. All four cores read 54.0C, so use `sysctl_call2_mixed.txt` to test the maximum.

## Synthetic (hand-made, not captured)

| File | How it was made | Purpose |
|------|-----------------|---------|
| `sysctl_call1_zfs.txt` | `sysctl_call1_ufs.txt` with `v_free_count` lowered to 30000 and `kstat.zfs.misc.arcstats.size: 10737418240` (10 GiB) appended | Large-ARC case: raw used 94.8% of RAM, used minus ARC 31.3% → no memory warning (Edge Case "Large ZFS cache", T015) |
| `sysctl_call2_nosensor.txt` | `sysctl_call2.txt` with every `dev.cpu.N.temperature` line removed | No-sensor case: `cpu_temperature_present = false` (FR-007, T019) |
| `sysctl_call2_mixed.txt` | `sysctl_call2.txt` with temperatures 67.0C, 56.0C, 61.0C, 58.0C | Maximum across cores = 67.0 (R-05, T006) |

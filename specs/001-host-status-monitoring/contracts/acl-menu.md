# Contract: ACL privileges and menu

**ACL file**: `src/opnsense/mvc/app/models/PRSWS/CasaDelta/ACL/ACL.xml`
**Menu file**: `src/opnsense/mvc/app/models/PRSWS/CasaDelta/Menu/Menu.xml`
**Requirements**: FR-016, Principle V

## Privileges

| Privilege key                | Display name (System → Access → Groups/Users) | URL patterns                                         |
|------------------------------|----------------------------------------------|------------------------------------------------------|
| `page-casadelta-status`      | Casa Delta: Host Status (View)               | `ui/casadelta/status*`, `api/casadelta/status/*`     |
| `page-casadelta-settings`    | Casa Delta: Host Settings (Manage)           | `ui/casadelta/settings*`, `api/casadelta/settings/*` |

## Access matrix

| Request                                        | View only | Settings only | Both | Neither |
|------------------------------------------------|-----------|---------------|------|---------|
| `GET /ui/casadelta/status`                     | 200       | 403           | 200  | 403     |
| `GET /api/casadelta/status/get`                | 200       | 403           | 200  | 403     |
| `POST /api/casadelta/status/refresh`           | 200       | 403           | 200  | 403     |
| `GET /ui/casadelta/settings`                   | 403       | 200           | 200  | 403     |
| `* /api/casadelta/settings/*`                  | 403       | 200           | 200  | 403     |

Members of the `admins` group (`page-all`) have both.

## Menu

```text
Casa Delta                 (top-level, icon fa-heartbeat)
├── Host Status            → /ui/casadelta/status      (visible with the status privilege)
└── Host Settings          → /ui/casadelta/settings    (visible with the settings privilege)
```

OPNsense hides menu entries whose URL the user can't access, so a view-only user sees only
"Host Status".

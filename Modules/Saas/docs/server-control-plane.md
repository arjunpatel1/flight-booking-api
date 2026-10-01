# SaaS Server Control Plane

The SaaS admin panel can preview and apply web-server/SSL setup without logging in to the server.

## Supported Web Servers

- Apache: `Modules/Saas/deploy/apache-saas-ssl.sh`
- Nginx: `Modules/Saas/deploy/nginx-saas-ssl.sh`

Dry-run preview is always safe. Apply mode is blocked unless both values are enabled:

```env
SAAS_SERVER_AUTOMATION_ENABLED=true
SAAS_SERVER_AUTOMATION_ALLOW_APPLY=true
```

## Important Env Values

```env
SAAS_SERVER_AUTOMATION_WEB_SERVER=apache # apache or nginx
SAAS_SERVER_AUTOMATION_USE_SUDO=false
SAAS_SERVER_AUTOMATION_TIMEOUT=300
SAAS_SUPERVISORCTL_PATH=/usr/bin/supervisorctl
SAAS_REVERB_SUPERVISOR_PROGRAM=ghee-dosa-api-reverb
SAAS_WORKER_SUPERVISOR_PROGRAM=ghee-dosa-api-worker:*
SAAS_RESTORE_EXECUTION_ENABLED=false
SAAS_RESTORE_ALLOWED_ENVIRONMENTS=local,staging
```

## Health API

`GET /api/v1/saas/server/health`

Returns readiness for web-server scripts, Supervisor, Reverb config, queues, billing gateways, delivery webhook, alert recipient, and restore safety.

## Restore Safety

Restore execution is intentionally blocked in production by default. Run it only on a staging copy after setting:

```env
APP_ENV=staging
SAAS_RESTORE_EXECUTION_ENABLED=true
```


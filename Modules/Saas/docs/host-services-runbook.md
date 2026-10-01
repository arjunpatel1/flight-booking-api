# NexDine host services runbook

The application health center expects Redis, Supervisor, Reverb, queue workers, and the Laravel scheduler to be continuously available. The bundled Supervisor configuration also runs the scheduled `saas:backup` job (03:15 daily) and communication campaign dispatcher.

## Install and activate

Run on the Ubuntu host as a sudo-capable operator:

```bash
sudo apt-get update
sudo apt-get install -y redis-server supervisor php-redis
sudo systemctl enable --now redis-server supervisor
sudo cp Modules/Saas/deploy/nexdine-supervisor.conf /etc/supervisor/conf.d/nexdine.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart nexdine:*
php artisan optimize:clear
```

Use `CACHE_STORE=redis`, `QUEUE_CONNECTION=database`, `BROADCAST_CONNECTION=reverb`, and `REVERB_HOST=127.0.0.1` in production. Database queues are retained deliberately so backlog inspection and safe replay remain available during Redis incidents.

## Verify

```bash
redis-cli ping
sudo supervisorctl status nexdine:*
php artisan queue:monitor high,default,notifications,printing --max=100
php artisan schedule:list
php artisan saas:backup
```

Before deleting queue or print records, export the affected rows into `storage/app/private/saas/operations-archive`. Never use `queue:flush` against an unreviewed production backlog. Failed print work must be checked for a valid restaurant, branch, agent, and printer route before replay.

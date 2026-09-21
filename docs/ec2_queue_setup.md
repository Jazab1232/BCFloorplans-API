# Setting up Laravel Queue Worker on EC2 with Supervisor (Database Driver)

Since we are using the **Database** queue driver, you do need to keep the `queue:work` process running permanently in the background. We use **Supervisor** for this.

## 1. Install Supervisor
Connect to your EC2 instance via SSH and run:

```bash
sudo apt-get update
sudo apt-get install supervisor
```

## 2. Configure the Worker
Create a new configuration file for your queue worker.

```bash
sudo nano /etc/supervisor/conf.d/bcf-worker.conf
```

Paste the following content (adjust paths if your project is not at `/var/www/bcf-api`):

```ini
[program:bcf-worker]
process_name=%(program_name)s_%(process_num)02d
# Command to run the queue worker
command=php /var/www/bcf-api/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/bcf-api/storage/logs/worker.log
stopwaitsecs=3600
```

> **Note**: `numprocs=1` means one worker. Increase this if you need to process photos in parallel (e.g., `numprocs=2`).

## 3. Start the Worker
Register the new configuration and start the process:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start bcf-worker:*
```

## 4. Verify Status
Check if the worker is running:

```bash
sudo supervisorctl status
```

You should see something like:
`bcf-worker:bcf-worker_00   RUNNING   pid 12345, uptime 0:00:05`

## 5. Deployment Note
Whenever you deploy new code (pull from git), you must restart the queue worker so it picks up the changes:

```bash
php artisan queue:restart
``` 
(This sends a signal to the worker to gracefully die and Supervisor will restart it effectively reloading the code).

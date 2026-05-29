#!/usr/bin/env sh

set -eu

workers="${QUEUE_WORKERS:-3}"
memory_limit="${PHP_MEMORY_LIMIT:-512M}"
config_path="/tmp/supervisord-queue.conf"

cat > "$config_path" <<'EOF'
[supervisord]
nodaemon=true
logfile=/dev/null
logfile_maxbytes=0
pidfile=/tmp/supervisord-queue.pid
EOF

i=1
while [ "$i" -le "$workers" ]; do
cat >> "$config_path" <<EOF

[program:queue-worker-$i]
directory=/var/www/html
command=run-php artisan queue:work --tries=1 --timeout=0 --sleep=1
autostart=true
autorestart=true
startsecs=0
stopasgroup=true
killasgroup=true
stdout_logfile=/dev/fd/1
stdout_logfile_maxbytes=0
stderr_logfile=/dev/fd/2
stderr_logfile_maxbytes=0
EOF
    i=$((i + 1))
done

exec supervisord -c "$config_path" -n

#!/usr/bin/env bash
set -euo pipefail

port="${1:?HTTP port required}"
script="${2:?Certification script path required}"
scratch="$(mktemp -d /tmp/webrick-fpm.XXXXXXXX)"
fpm_pid=''
nginx_pid=''
cleanup() {
    local pid
    for pid in "$nginx_pid" "$fpm_pid"; do
        if [[ -n "$pid" ]]; then kill -TERM "$pid" 2>/dev/null || true; fi
    done
    for pid in "$nginx_pid" "$fpm_pid"; do
        if [[ -n "$pid" ]]; then wait "$pid" 2>/dev/null || true; fi
    done
    rm -rf -- "$scratch"
}
trap cleanup EXIT
trap 'exit 0' TERM INT

ln -s -- "$script" "$scratch/cert-server.php"
cat > "$scratch/fpm.conf" <<FPM
[global]
error_log = /proc/self/fd/2
[certification]
listen = $scratch/fpm.sock
pm = static
pm.max_children = 2
clear_env = yes
env[WEBRICK_CERT_ROOT] = \$WEBRICK_CERT_ROOT
env[WEBRICK_CERT_ADMISSION] = \$WEBRICK_CERT_ADMISSION
env[IC_REDIS_HOST] = \$IC_REDIS_HOST
env[IC_REDIS_PORT] = \$IC_REDIS_PORT
catch_workers_output = yes
FPM
cat > "$scratch/nginx.conf" <<NGINX
daemon off;
master_process off;
error_log stderr warn;
pid $scratch/nginx.pid;
events {
    worker_connections 512;
}
http {
    access_log off;
    client_body_buffer_size 32k;
    client_body_temp_path $scratch/client-body;
    proxy_temp_path $scratch/proxy;
    fastcgi_temp_path $scratch/fastcgi;
    uwsgi_temp_path $scratch/uwsgi;
    scgi_temp_path $scratch/scgi;
    server {
        listen 127.0.0.1:$port;
        location / {
            fastcgi_pass unix:$scratch/fpm.sock;
            fastcgi_buffering off;
            fastcgi_param SCRIPT_FILENAME $scratch/cert-server.php;
            fastcgi_param REQUEST_METHOD \$request_method;
            fastcgi_param REQUEST_URI \$request_uri;
            fastcgi_param QUERY_STRING \$query_string;
            fastcgi_param CONTENT_TYPE \$content_type;
            fastcgi_param CONTENT_LENGTH \$content_length;
            fastcgi_param SERVER_PROTOCOL \$server_protocol;
            fastcgi_param SERVER_NAME \$server_name;
            fastcgi_param SERVER_PORT \$server_port;
            fastcgi_param REMOTE_ADDR \$remote_addr;
            fastcgi_param REDIRECT_STATUS 200;
        }
    }
}
NGINX

php-fpm --nodaemonize --fpm-config "$scratch/fpm.conf" -d opcache.enable=1 &
fpm_pid=$!
for _ in {1..100}; do
    [[ ! -S "$scratch/fpm.sock" ]] || break
    kill -0 "$fpm_pid"
    sleep 0.02
done
[[ -S "$scratch/fpm.sock" ]]
nginx -p "$scratch/" -c "$scratch/nginx.conf" &
nginx_pid=$!
wait -n "$fpm_pid" "$nginx_pid"

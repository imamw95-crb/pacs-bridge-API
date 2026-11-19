#!/usr/bin/env bash
# Deploy / update Bridge SIMRS <-> Orthanc PACS di server ini.
# Pakai:  bash deploy/deploy.sh /tmp/pacs-bridge-deploy.tar.gz
# Isi paket: seluruh aplikasi (src/, public/, Dockerfile, ...), TANPA data/settings.json
# Sekalian memasang cron tarikan study tiap menit (/etc/cron.d/pacs-pull).
set -euo pipefail

APP=/opt/pacs-bridge
PKG=${1:?pakai: deploy.sh /tmp/pacs-bridge-deploy.tar.gz}

sudo mkdir -p "$APP"
if [ -f "$APP/setting.txt" ]; then
    TS=$(date +%Y%m%d-%H%M%S)
    sudo tar czf "/opt/pacs-bridge-backup-$TS.tar.gz" -C /opt pacs-bridge
    echo "backup aplikasi lama: /opt/pacs-bridge-backup-$TS.tar.gz"
fi

sudo tar xzf "$PKG" -C "$APP"

# data/ dan sessions/ dipertahankan dari host (settings.json tidak ikut paket)
sudo mkdir -p "$APP/data" "$APP/sessions"
sudo chown -R 33:33 "$APP/data" "$APP/sessions"
sudo chmod 775 "$APP/data" "$APP/sessions"

cd "$APP"
echo "== build image =="
sudo docker build -q -t pacs-bridge:1 .

echo "== (re)start container =="
sudo docker rm -f pacs-bridge >/dev/null 2>&1 || true
sudo docker run -d --name pacs-bridge --restart unless-stopped \
    -p 127.0.0.1:8090:80 \
    -v "$APP/data":/var/www/html/data \
    -v "$APP/sessions":/var/lib/php/sessions \
    pacs-bridge:1 >/dev/null
sleep 4
sudo docker ps --filter name=pacs-bridge --format '{{.Names}} | {{.Status}} | {{.Ports}}'
curl -s -o /dev/null -w "cek /dashboard (port 80) -> HTTP %{http_code}\n" http://127.0.0.1/dashboard

echo "== pasang cron tarikan study (tiap menit) =="
sudo install -m 644 "$APP/deploy/cron-pacs-pull" /etc/cron.d/pacs-pull
sudo touch /var/log/pacs-pull.log
sudo chmod 644 /var/log/pacs-pull.log
echo "terpasang: /etc/cron.d/pacs-pull (log: /var/log/pacs-pull.log)"

# cron lama 30 menit harus dimatikan manual supaya tidak menarik dobel
OLD_SYSTEM=$(sudo grep -Hin "santesrv\|pull-santesrv" /etc/crontab /etc/cron.d/* 2>/dev/null \
             | grep -v "/etc/cron.d/pacs-pull" || true)
OLD_USER=$(crontab -l 2>/dev/null | grep -in "santesrv\|pull-santesrv" || true)
if [ -n "$OLD_SYSTEM$OLD_USER" ]; then
    echo "PERINGATAN: masih ada cron lama yang menarik SANTESRV1, hapus manual:"
    [ -n "$OLD_SYSTEM" ] && echo "$OLD_SYSTEM"
    [ -n "$OLD_USER" ] && echo "crontab (user $(whoami)): $OLD_USER"
fi

# Deploy Bridge ke server 10.4.0.10

Ringkasan operasional (detail lengkap ada di [`../AGENTS.md`](../AGENTS.md) §13).

| Item | Nilai |
|---|---|
| URL | `http://10.4.0.10/` |
| Container | `pacs-bridge` (image `pacs-bridge:1`) |
| Kode | `/opt/pacs-bridge` |
| Data | `/opt/pacs-bridge/data` (settings.json), `/opt/pacs-bridge/sessions` |
| Port | `127.0.0.1:8090` → container `:80`, publik lewat lighttpd (port 80) |
| Proxy | `/etc/lighttpd/conf-enabled/50-pacs-bridge.conf` |

## Update

```powershell
# Windows
$tgz = "$env:TEMP\pacs-bridge-deploy.tar.gz"
tar -czf $tgz src public tools integrations postman deploy setting.txt Dockerfile .dockerignore AGENTS.md API.md PACS-ORTHANC.md
& 'C:\Program Files\PuTTY\pscp.exe' -pw <PASSWORD> $tgz pacs@10.4.0.10:/tmp/pacs-bridge-deploy.tar.gz
```

```bash
# Server
sudo tar xzf /tmp/pacs-bridge-deploy.tar.gz -C /opt/pacs-bridge
bash /opt/pacs-bridge/deploy/deploy.sh /tmp/pacs-bridge-deploy.tar.gz
```

## Perintah harian (di server)

```bash
sudo docker ps --filter name=pacs-bridge           # status
sudo docker logs --tail 50 pacs-bridge             # log Apache
sudo docker restart pacs-bridge                    # restart
curl -s -H 'X-API-Key: <KEY>' http://127.0.0.1/health
```

## Rollback

```bash
ls -1 /opt/pacs-bridge-backup-*.tar.gz            # pilih backup
sudo rm -rf /opt/pacs-bridge
sudo tar xzf /opt/pacs-bridge-backup-<ts>.tar.gz -C /opt
cd /opt/pacs-bridge
sudo docker build -q -t pacs-bridge:1 .
sudo docker rm -f pacs-bridge
sudo docker run -d --name pacs-bridge --restart unless-stopped \
  -p 127.0.0.1:8090:80 \
  -v /opt/pacs-bridge/data:/var/www/html/data \
  -v /opt/pacs-bridge/sessions:/var/lib/php/sessions \
  pacs-bridge:1
```

## Callback study baru (opsional)

Pasang `deploy/cron-pacs-notify` ke `/etc/cron.d/pacs-notify` (tiap menit) dan isi
**Callback URL** + **Callback key** di Pengaturan. Log: `/var/log/pacs-notify.log`
(ringkasan) dan `/opt/pacs-bridge/data/callback.log` (per pengiriman).

## Catatan

- **Verifikasi deploy:** `/health` butuh login, jadi versi dibaca dari dashboard (publik):
  `curl -s http://127.0.0.1/dashboard | grep -o 'bridge-version" content="[^"]*'` harus menampilkan
  versi terbaru (`1.2.0`). Lalu `curl -s http://127.0.0.1/audit` harus `401` dengan `"code":"UNAUTHORIZED"`
  (respons ber-indentasi, jadi cocokkan setelah spasi dibuang). `deploy.sh` mencetak hasil kedua cek ini otomatis.
- Kode di-bake ke image (`COPY . /var/www/html`) — mengekstrak tar ke `/opt/pacs-bridge` **tanpa**
  `docker build` + `docker rm -f`/`docker run` (yaitu tanpa `deploy.sh`) **tidak** mengubah yang berjalan.

- `data/settings.json` tidak ikut paket, jadi konfigurasi di server aman saat update.
- File `.sh` dan `Dockerfile` harus berakhiran **LF** (bukan CRLF).
- `deploy/nginx-pacs-bridge.conf` hanya dipakai bila host memakai nginx; server 10.4.0.10 memakai lighttpd.

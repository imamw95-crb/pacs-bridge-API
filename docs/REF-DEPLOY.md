# REF Deploy — server 10.4.0.10

> Dipisah dari `AGENTS.md` agar konteks agent tetap kecil. Lembar operasional singkat: `deploy/README.md`.

## 13. Deploy (server 10.4.0.10)

Bridge berjalan sebagai **container Docker** di server PACS, tampil di port 80 lewat reverse proxy
lighttpd yang sudah ada (dump1090 & /ftp tetap jalan).

| Item | Nilai |
|---|---|
| URL dashboard | `http://10.4.0.10/` (login default `admin` / `admin`) |
| URL API | `http://10.4.0.10/health`, `/worklists`, `/studies/...` |
| Container | `pacs-bridge` (image `pacs-bridge:1`, Apache + PHP 8.3, restart `unless-stopped`) |
| Port internal | `127.0.0.1:8090` → container `:80` (hanya localhost, publik lewat lighttpd) |
| Kode di server | `/opt/pacs-bridge` |
| Data persisten | `/opt/pacs-bridge/data` (settings.json), `/opt/pacs-bridge/sessions` (sesi PHP) |
| Proxy lighttpd | `/etc/lighttpd/conf-enabled/50-pacs-bridge.conf` (kecuali `/dump1090`, `/javascript`, `/ftp`) |

### Update kode ke server

```powershell
# 1) dari Windows: paket + upload
cd C:\laragon\www\PACSPROJEC
$tgz = "$env:TEMP\pacs-bridge-deploy.tar.gz"
tar -czf $tgz src public tools integrations postman docs deploy setting.txt Dockerfile .dockerignore AGENTS.md API.md PACS-ORTHANC.md
& 'C:\Program Files\PuTTY\pscp.exe' -pw <PASSWORD> $tgz pacs@10.4.0.10:/tmp/pacs-bridge-deploy.tar.gz

# 2) build + restart (di server)
ssh pacs@10.4.0.10
sudo tar xzf /tmp/pacs-bridge-deploy.tar.gz -C /opt/pacs-bridge
bash /opt/pacs-bridge/deploy/deploy.sh /tmp/pacs-bridge-deploy.tar.gz
```

`deploy.sh` otomatis: backup aplikasi lama ke `/opt/pacs-bridge-backup-<ts>.tar.gz`,
build image, recreate container, cek `/dashboard`, memasang **`/etc/cron.d/pacs-pull`**
(tarikan study tiap menit dari `SANTESRV1`) dan **`/etc/cron.d/pacs-notify`** (callback),
lalu mencetak `bridge_version` + cek bahwa API baru (`/audit` → `401 "code":"UNAUTHORIZED"`)
sudah aktif.

> **Kode di-bake ke image** (`COPY . /var/www/html`). Mengekstrak tar ke `/opt/pacs-bridge`
> tanpa `docker build` + recreate container **tidak** mengubah versi yang berjalan.
> Verifikasi (dashboard publik, karena `/health` butuh login):
> `curl -s http://127.0.0.1/dashboard | grep -o 'bridge-version" content="[^"]*'` (harus `1.2.0`).

Script juga memperingatkan bila cron lama (30 menit, `orthanc-sync.sh`) masih ada — hapus manual
agar tidak menarik dobel.

### Catatan deploy

- `data/settings.json` **tidak** ikut paket → konfigurasi & password admin di server tidak tertimpa.
- Sesi login tetap hidup setelah container restart (folder `sessions/` dipersist).
- Jangan `docker rm` container tanpa menjalankan `deploy.sh` lagi (port 80 akan mati).
- File `.sh`/`Dockerfile` **wajib LF** (CRLF membuat `set -o pipefail` gagal).
- Kalau host memakai nginx (bukan lighttpd), pakai `deploy/nginx-pacs-bridge.conf`.

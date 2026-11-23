# REF Lingkungan — peta file lengkap & fakta server/alat

> Dipisah dari `AGENTS.md` agar konteks agent tetap kecil. Versi ringkas ada di `AGENTS.md` §2–§3.

## 2. Peta File

| Path | Fungsi |
|---|---|
| `setting.txt` | konfigurasi client (IP/AET/port). **Dibaca langsung oleh `src/Config.php`** |
| `src/Config.php` | parser `setting.txt` + override env |
| `src/OrthancClient.php` | HTTP client REST Orthanc (cURL, tanpa dependency) |
| `src/OrthancException.php` | exception + status/body dari Orthanc |
| `src/PatientName.php` | normalisasi PatientName (aturan tanpa koma) |
| `src/WorklistService.php` | CRUD worklist (create/update/delete/list/findByAccession) |
| `src/StudyService.php` | cari study, render PNG/JPEG, ambil DICOM/ZIP |
| `src/ZipWriter.php` | pembuat arsip ZIP minimal (store, tanpa ekstensi zip) untuk ZIP berisi JPG |
| `src/WatermarkService.php` | watermark identitas pasien pada JPG (GD + FreeType, teks orange); dilewati untuk modalitas US & ECG |
| `src/AuditLogger.php` | audit JSON Lines (`data/audit.log`): setiap request + event aksi penting, rotasi 5 MB |
| `src/WorklistConflictException.php` | konflik AccessionNumber (HTTP 409 `ACCESSION_CONFLICT`) |
| `src/Bridge.php` | facade (`Bridge::fromSettingFile()`) |
| `src/BridgeApiClient.php` | HTTP client untuk **Bridge API** (pakai header `X-API-Key`) |
| `src/BridgeApiException.php` | exception khusus Bridge API (401/403/5xx) |
| `src/autoload.php` | autoloader sederhana namespace `Simrs\Pacs` |
| `public/index.php` | REST API bridge (router) |
| `public/dashboard.html` | **dashboard web** (login, worklist, study, gambar, pengaturan) |
| `public/assets/logo-ris.png` + `favicon.png` | logo program **RIS-HLS PATROL** (header & halaman login) dan ikon tab browser |
| `src/Auth.php` | sesi login dashboard (PHP session) |
| `src/Settings.php` | pengaturan dari dashboard (`data/settings.json`), password admin, daftar alat worklist |
| `src/ModalityService.php` | pendaftaran modality (`DicomModalities`) ke Orthanc lewat REST (`PUT /modalities/{id}`) + C-ECHO |
| `data/settings.json` | runtime: hasil edit pengaturan & hash password admin (jangan di-commit) |
| `public/.htaccess` | rewrite untuk Apache/Laragon |
| `public/router.php` | router server dev (`php -S ... public/router.php`) — meniru rewrite Apache, wajib agar path ber-titik (UID DICOM) tidak 404 |
| `tools/selftest.php` | self-test end-to-end (aman, tidak meninggalkan data) |
| `tools/pull-santesrv1.php` | penarik study terjadwal dari PACS sumber (`SANTESRV1`) → Orthanc (inkremental, `--quiet` untuk cron) |
| `deploy/cron-pacs-pull` | jadwal cron **tiap menit** untuk `tools/pull-santesrv1.php` (pasang di `/etc/cron.d/pacs-pull`) |
| `tools/pull-santesrv1.cmd` + `tools/pull-santesrv1-hidden.vbs` | wrapper Windows Task Scheduler (tanpa jendela console, log ke `data/pull-windows.log`) |
| `tools/test-ci-integration.php` | self-test library integrasi CI3 & CI4 |
| `tools/test-api-key.php` | self-test API key pada Bridge API (`BridgeApiClient`) |
| `tools/test-order-flow.php` | self-test idempotensi order, 409 konflik, status order, 404, dan audit |
| `tools/notify-studies.php` | worker **callback study baru** ke SIMRS (`/changes` → POST + HMAC, inkremental) |
| `deploy/cron-pacs-notify` | jadwal cron tiap menit untuk `tools/notify-studies.php` |
| `tools/test-users.php` | self-test peran user dashboard (manager vs operator), membuat & menghapus user uji |
| `postman/` | **Postman collection + environment** untuk mencoba Bridge API secara manual (19 request) |
| `API.md` | **dokumentasi REST API**: kemampuan, autentikasi, contoh respons, contoh konek (curl/PHP/CI/Python/Postman) |
| `docs/API-KEY-SIMRS.pdf` | **dokumentasi API key siap cetak untuk tim SIMRS**: cara dapat key, header, tabel endpoint (✅/❌), mode integrasi CI (PacsApi/Pacs_api vs langsung), error, keamanan, checklist go-live. Sumber: `docs/API-KEY-SIMRS.html` |
| `integrations/codeigniter/` | library + config + controller untuk SIMRS (CI3 & CI4), lihat `README.md` di dalamnya |
| `dicom-probe.py` | alat uji DICOM: C-ECHO, MWL C-FIND, Study Root C-FIND |
| `Dockerfile` | image produksi (php:8.3-apache, DocumentRoot `public/`, ekstensi mbstring) |
| `.dockerignore` | mengecualikan `data/settings.json`, arsip, `.git` dari build |
| `deploy/deploy.sh` | skrip deploy/update di server (extract → build → restart container) |
| `deploy/nginx-pacs-bridge.conf` | contoh reverse proxy (untuk host yang memakai nginx; di 10.4.0.10 dipakai lighttpd) |
| `PACS-ORTHANC.md` | dokumentasi teknis server PACS (temuan lengkap) |

---

## 3. Fakta Lingkungan (jangan diasumsikan lain)

| Item | Nilai |
|---|---|
| Server Orthanc | `10.4.0.10` (Docker container `orthanc`, Orthanc 1.12.11) |
| DICOM SCP | port **4242**, AET **`ORTHANC`** |
| REST / DICOMweb | port **8042** |
| AET client | **`LEAD_CLIENT`** (modality `DX`, alat di `192.168.10.110`) |
| IP client (alat DX) | **`192.168.10.110`** (Rontgen, DICOM port `104`); `192.168.200.230:11112` = laptop kerja, bukan alat. `Host` modality `LEAD_CLIENT` di Orthanc masih placeholder `10.4.0.10:11112` |
| Config aktif di server | `/tmp/orthanc.json` — **di-generate** dari `/etc/orthanc/*.json` |
| `DicomModalitiesInDatabase` | **belum diset** (= `false`) → modality yang didaftarkan via REST hanya di memori dan **hilang saat Orthanc restart**; set `"DicomModalitiesInDatabase": true` di `/etc/orthanc/orthanc.json` + `docker restart orthanc` agar permanen |
| Port 11112 di server | milik container `dicom-router` (AET `DCMROUTER`) = **router Kemenkes**, **bukan** Orthanc; C-FIND MWL lewat sini selalu 0 hasil |
| PACS sumber (ditarik cron) | **`192.168.10.15:11121`**, AET `SANTESRV1`, `RetrieveMethod = C-GET` |
| Alat DX (Rontgen) | IP **`192.168.10.110`** — mesin Windows (IIS 10) dengan DICOM di port `104`; menarik MWL dari `10.4.0.10:4242` AET `ORTHANC` dengan calling AET `LEAD_CLIENT` (harus terdaftar di `DicomModalities`, kalau tidak → 0 hasil). **Menolak** AET pemanggil yang tidak terdaftar (`reason 0x03`) — jangan dipakai sebagai sumber tarikan |
| Alat USG (GE Versana Balance) | IP `192.168.10.130`, AET **`VersanaB-000000`**, modality `US`, StationName `DESKTOP-I6P3F1V`; study dikirim **tanpa AccessionNumber**. Terdaftar di `DicomModalities` (AllowFindWorklist + AllowStore) sejak 17 Nov 2026 |
| Alat EKG (Philips, ECG cart) | IP **`172.16.200.137`**, AET **`LEAD_PHILIP`**, modality `ECG`; MWL diarahkan ke `10.4.0.10:4242`. Terdaftar di `DicomModalities` (AllowFindWorklist + AllowStore) 17 Nov 2026. **Setelan alat**: `Local AE` harus `LEAD_PHILIP` (bukan `ORTHANC`) dan AE server MWL `ORTHANC` — kalau tertukar, C-FIND MWL ditolak karena AET `ORTHANC` bukan modality terdaftar |
| Alat panoramic gigi (Myer / Meyer) | Manufacturer `MEYER`, modality **`PX`**, SeriesDescription `_myct__type=PAN_` (mis. study `1.9.0.415.0.519.551.…`). Mengirim **C-STORE** ke Orthanc (tanpa MWL); AccessionNumber = tanggal+jam dari alat (`20261114120308`), `PatientName` memakai garis bawah (`NEYSHA_RULIA_WATI_AN`). Citra **tidak** memuat identitas pasien, jadi JPG-nya **tetap diberi watermark** |
| CT scan (Siemens Healthineers) | IP **`192.168.10.19`**, AET **`CT140643`** (port DICOM `104`), StationName `CT140643`, SeriesDescription `AX` (mis. study `1.3.12.2.1107.5.1.7.140643.…`). Terdaftar di `DicomModalities` (**AllowFindWorklist** + AllowStore + AllowFind + AllowEcho) 18 Nov 2026, jadi MWL bisa ditarik CT. Study dikirim **tanpa AccessionNumber** (dashboard memakai ID Orthanc sebagai kunci) dan berisi **puluhan slice** (contoh: 87 instance) → thumbnail = slice pertama, `ZIP (JPG)` merender semua slice (±3 detik / 2,8 MB), viewer penuh pakai **Stone WebViewer**. Citra tidak memuat identitas pasien → **tetap ber-watermark** |

Override runtime (opsional, via environment):

```
PACS_SERVER_IP, PACS_HTTP_PORT, PACS_DICOM_PORT,
PACS_CALLED_AET, PACS_CALLING_AET, PACS_MODALITY,
PACS_HTTP_USER, PACS_HTTP_PASS, PACS_BRIDGE_KEY, PACS_TIMEOUT, PACS_TIMEZONE (default Asia/Jakarta)
```

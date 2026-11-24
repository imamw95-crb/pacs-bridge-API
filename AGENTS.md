# AGENTS.md — Bridge SIMRS (PHP) ↔ Orthanc PACS

Menghubungkan SIMRS (PHP) dengan Orthanc PACS: kirim order ke **MWL** (ditarik alat radiologi)
dan ambil hasil study (DICOM/JPG/PNG/ZIP). Modality utama `DX`, AET client `LEAD_CLIENT`.

> **File ini sengaja ringkas** (±170 baris, ±10 KB). Detail per topik ada di
> `docs/REF-*.md` dan dibaca **hanya** bila topik itu disentuh. Jangan menambah penjelasan
> panjang di sini — taruh di `docs/REF-*` lalu tambahkan satu baris di §8.

## 0. Disiplin konteks (wajib)

| Kebutuhan | Cara murah |
|---|---|
| Cari kelas/method | `php tools/agent-map.php [file]` → kelas, method, nomor baris; lalu `view` rentang itu saja (default hanya `src` + router; tambahkan `tools`/`integrations` bila perlu) |
| `public/dashboard.html` (±2.000 baris), `public/index.php` (±815 baris) | grep dulu (`id="`, `/studies/`, `case '`), baru baca ±40 baris |
| Daftar endpoint | `php tools/agent-map.php --routes` |
| Output panjang (lint, test, log, docker) | batasi: `-First 20`, `-Tail 50`, `--quiet` |
| `PACS-ORTHANC.md` (472 baris), `API.md` (1004 baris) | jangan dimuat utuh — grep kata kunci dulu |
| Edit | pakai `edit` (jangan tulis ulang file besar) |

## 1. Alur data

```
SIMRS --POST /worklists--> Orthanc --(MWL C-FIND, ditarik alat)--> alat DX/US/CT/ECG/PX
alat --C-STORE--> Orthanc --GET /studies/{acc}/(jpg|png|zip)--> SIMRS / viewer
```

- Worklist: alat yang **menarik**, bukan server push. Order auto-hapus setelah study stabil diterima.
- Gambar: alat yang **mengirim** (C-STORE, realtime).
- Detail: `docs/REF-API.md` §5.

## 2. Peta file

Ringkas di bawah; tabel lengkap per file: `docs/REF-LINGKUNGAN.md` §2.

| Path | Isi |
|---|---|
| `src/` | SDK `Simrs\Pacs` tanpa Composer: `Config`, `OrthancClient`, `WorklistService`, `StudyService`, `Settings`, `Auth`, `WatermarkService`, `AuditLogger`, `ModalityService`, `ZipWriter`, `Bridge`, `BridgeApiClient`, `OllamaExpertise`, `OllamaException`, `autoload.php` |
| `public/index.php` | router REST API (semua endpoint + otorisasi) |
| `public/dashboard.html` | dashboard web (login, worklist, study, pengaturan) — tanpa build step |
| `public/router.php` | router `php -S` (path ber-titik/UID DICOM tidak 404) |
| `tools/` | `selftest`, `test-api-key`, `test-order-flow`, `test-users`, `test-ci-integration`, `pull-santesrv1`, `notify-studies`, `agent-map` |
| `deploy/` | `deploy.sh`, `cron-pacs-pull`, `cron-pacs-notify`, `nginx-pacs-bridge.conf`, `README.md`; `orthanc/` = stack compose Orthanc di server (container `orthanc` + `orthanc-proxy`) + unit systemd `orthanc-compose.service` & watchdog tiap 2 menit |
| `integrations/codeigniter/` | adapter CI3 + CI4 (mode A Bridge API / mode B Orthanc) |
| `docs/` | `REF-*.md` (detail AGENTS.md), `API-KEY-SIMRS.html/pdf` |
| `API.md` / `PACS-ORTHANC.md` | referensi REST API / temuan server (besar — grep dulu) |
| `setting.txt`, `data/settings.json` | config client (dibaca `src/Config.php`) / runtime dashboard |
| `dicom-probe.py` | C-ECHO, MWL C-FIND, Study Root C-FIND |
| `Dockerfile`, `postman/`, `bridge.http` | image produksi, collection Postman, REST client |

## 3. Fakta lingkungan (jangan diasumsikan lain)

Detail tiap alat (format accession PX, slice CT, alat IIS `192.168.10.110`): `docs/REF-LINGKUNGAN.md` §3.

| Item | Nilai |
|---|---|
| Orthanc | `10.4.0.10`, container `orthanc` (dikelola `docker compose` di `/home/pacs/orthanc/docker-compose.yml`); DICOM **4242** AET `ORTHANC`, REST/DICOMweb **8042** |
| Client | AET `LEAD_CLIENT` (calling AET **dan** `ScheduledStationAETitle` DX), alat DX = `192.168.10.110` (DICOM `104`); MWL wajib ke `10.4.0.10:4242` AET `ORTHANC` — modality wajib terdaftar |
| Port 11112 server | milik `dicom-router` (AET `DCMROUTER`) = **router Kemenkes**, bukan Orthanc; C-FIND MWL lewat sini selalu 0 hasil |
| Config server | `/tmp/orthanc.json` (generate dari `/etc/orthanc/*.json`) — jangan diedit; `DicomModalitiesInDatabase: true` → modality config di-seed ke DB tiap start |
| PACS sumber (cron) | `192.168.10.15:11121`, AET `SANTESRV1`, `RetrieveMethod = C-GET` |
| Bridge produksi | `http://10.4.0.10/`, container `pacs-bridge`, kode `/opt/pacs-bridge` |

Alat terdaftar (`DicomModalities` + `worklist_devices`):

| Alat | AET | Modality | IP |
|---|---|---|---|
| Rontgen | `LEAD_CLIENT` | `DX` | `192.168.10.110` |
| USG GE Versana | `VersanaB-000000` | `US` | `192.168.10.130` |
| EKG Philips | `LEAD_PHILIP` | `ECG` | `172.16.200.137` |
| CT Siemens | `CT140643` | `CT` | `192.168.10.19:104` |
| Panoramic Meyer | (C-STORE saja) | `PX` | — |

`DCMROUTER` (router Kemenkes) dan `SANTESRV1` (PACS sumber cron) juga ada di
`DicomModalities`, tetapi **bukan alat** — jangan dipakai sebagai tujuan MWL.

Override env: `PACS_SERVER_IP`, `PACS_HTTP_PORT`, `PACS_DICOM_PORT`, `PACS_CALLED_AET`,
`PACS_CALLING_AET`, `PACS_MODALITY`, `PACS_HTTP_USER`, `PACS_HTTP_PASS`, `PACS_BRIDGE_KEY`,
`PACS_TIMEOUT`, `PACS_TIMEZONE` (default `Asia/Jakarta`), `PACS_WATERMARK_FONT`,
`PACS_CALLBACK_URL`, `PACS_CALLBACK_KEY`, `PACS_OLLAMA_URL`, `PACS_OLLAMA_MODEL`,
`PACS_OLLAMA_ENABLED`, `PACS_OLLAMA_TIMEOUT`.

## 4. Aturan wajib

### Worklist / MWL

- Payload **`{"Tags": {...}}`** (format `{"Content": ...}` = `400`).
- `ScheduledProcedureStepStartDate` = hari ini `Ymd`; TZ di-set di `src/autoload.php`.
- `ScheduledStationAETitle` = AET alat **apa adanya** (case-sensitive: `VERSANAB-000000` ≠ `VersanaB-000000`).
- `ScheduledProcedureStepStatus = SCHEDULED` (`0040,0020`) — Siemens `CT140643` memfilternya; tanpa itu order tak muncul.
- `modality` per order **tidak dibatasi DX** (DX/CT/US/ECG/PX) + `station_aet`; `MODALITY` di config hanya default.
- Alat baru: daftarkan di Orthanc (`DicomModalities` + `AllowFindWorklist`, lewat dashboard → lihat `docs/REF-DASHBOARD.md` §6.9) **dan** di `worklist_devices`.
- Plugin MWL hanya melayani **calling AET yang terdaftar di `DicomModalities`**; AET tak terdaftar tetap dapat `0x0000` tapi **0 order** (order tetap tampil di dashboard karena `GET /worklists` itu REST). Jadi **jangan hapus modality** yang dipakai menarik MWL.
- Sumber MWL DX saat ini **DB bridge** (`POST /worklists`). Integrasi SIMRS (`worklist_dx.py` via `PythonScript`) **belum aktif**: path script salah & endpoint `…/api_worklist_dx/mwl_today` masih 404 — lihat `PACS-ORTHANC.md` §3.2c.
- Pastikan AET alat dengan `dicom-probe.py` (`AC` = benar, `RJ reason=0x03/0x07` = salah); jangan menebak dari `StationName`.
- `PatientName` **tanpa koma** (`PatientName::safe()`): `TARYA BIN WARDI TN`.
- `POST /worklists` idempotent per `accession_number` (201 baru / 200 sama / 409 `ACCESSION_CONFLICT` milik pasien lain).
- Status order: `GET /worklists/{acc}/status` (`sent`/`taken`/`completed`/`cancelled`) — jangan menyuruh SIMRS menebak dari polling gambar.
- Jangan kirim `QueryRetrieveLevel` pada query MWL → Orthanc balas 0 hasil.
- Order uji **selalu dihapus** setelah uji.

### Study & gambar

- Retrieval DICOM pakai **C-MOVE**, jangan **C-GET** (`0xA702`).
- Render: `/instances/{id}/rendered?width=` (PNG) atau DICOMweb `.../rendered?quality=` (JPEG). `width` di DICOMweb **diabaikan**.
- Daftar study terbaru: kirim **`OrderBy`** di `POST /tools/find`; jangan `Limit` lalu urutkan di PHP (data terbaru bisa hilang).
- Nama file di ZIP JPG harus unik (`StudyService::uniqueFileName()`).
- Thumbnail & "Lihat gambar" memakai **satu** instance (`StudyService::firstInstance()`); untuk `US` dipilih instance **berwarna** (Color/Power Doppler, `SamplesPerPixel` ≥ 3 via QIDO `includefield=00280002,00280004`) lebih dulu supaya tidak selalu tampil B-mode — jangan "diperbaiki" jadi instance pertama lagi.
- Watermark: `src/WatermarkService.php` (GD + FreeType, orange). **Dilewati** untuk `US`/`ECG`; `PX` & `CT` tetap di-watermark.
- Modality study diambil dari seri (`StudyService::attachModalities()`), bukan `MainDicomTags` study.
- Study bisa **tanpa AccessionNumber** → `findOneByKey()` (acc → ID Orthanc → UID); dashboard memakai ID Orthanc.
- Semua request dicatat `AuditLogger` (`data/audit.log`, rotasi 5 MB); `GET /audit` hanya manager.
- Callback study baru: `tools/notify-studies.php` → `POST study.received` + HMAC `X-Bridge-Signature`, at-least-once (penerima wajib idempotent per `study_id`).
- Auto expertise: `src/OllamaExpertise.php` (`POST /studies/{key}/expertise`) — render PNG 1024px **tanpa watermark** → model vision Ollama LAN (`format=json`, **`think=false`**), hasil = draf wajib verifikasi dokter, disimpan `data/expertise/{key}.json`. Nonaktif kecuali `ollama_enabled=true`.

### Jangan

- ❌ `docker rm` container tanpa `deploy.sh`; ❌ edit `/tmp/orthanc.json` (hilang saat restart).
- ❌ Menjalankan cron host lama `/home/pacs/orthanc-sync.sh` bersamaan dengan `/etc/cron.d/pacs-pull` (tarikan dobel, `lock aktif`).
- ❌ Menyimpan kredensial/API key di repo, log, atau dokumentasi.
- ❌ Mengisi `CLIENT IP` di `setting.txt` dengan IP server.
- ❌ Menganggap JPG/PNG render sebagai citra diagnostik (lossy, 8-bit).
- ❌ Menambah dependency Composer untuk hal yang bisa dilakukan cURL.
- ❌ Menguji C-STORE gambar palsu ke PACS produksi tanpa izin + rencana hapus.

## 5. Perintah

```powershell
php tools/agent-map.php                 # peta kelas/method (hemat token)
php tools/agent-map.php --routes        # daftar endpoint index.php
php tools/selftest.php                  # 8 pass (buat lalu hapus entry uji)
php tools/test-api-key.php http://127.0.0.1:8081 <API_KEY>
php tools/test-order-flow.php http://127.0.0.1:8081 <API_KEY>     # 8 pass
php tools/test-users.php http://127.0.0.1:8081 admin admin        # 19 pass
php tools/test-expertise.php http://127.0.0.1:8081 <API_KEY>     # 7 pass (butuh Ollama + ollama_enabled)
php tools/test-ci-integration.php       # 9 pass (12 pass bila env PACS_BRIDGE_TEST_* diisi)
php -S 0.0.0.0:8081 -t public public/router.php      # dev: http://127.0.0.1:8081/ (admin/admin)
php tools/pull-santesrv1.php --dry-run               # tarikan SANTESRV1
php tools/notify-studies.php --latest --dry-run      # pratinjau callback
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
python dicom-probe.py 10.4.0.10 4242 ORTHANC <AET_ALAT>
```

Deploy, rollback, cron, dan verifikasi versi: `deploy/README.md` + `docs/REF-DEPLOY.md`.
Di Laragon: docroot → folder `public/`.

## 6. Validasi setelah perubahan

1. `php -l` bersih untuk file yang diubah.
2. Perubahan inti/SDK → `php tools/selftest.php` **8 pass, 0 fail**.
3. Order/status/audit → `tools/test-order-flow.php` **8 pass** (idempotensi, 409, `sent`→`cancelled`, 404, `/audit` 403 untuk key).
4. Auth/user → `tools/test-users.php` **19 pass**; integrasi CI → `tools/test-ci-integration.php` **9 pass**.
5. Auto expertise/Ollama → `tools/test-expertise.php <baseUrl> <API_KEY>` **7 pass** (draf dihapus otomatis).
6. Endpoint: `/health` 200, `/studies/{acc}/jpg` → `image/jpeg`.
7. Tidak meninggalkan order/user uji. Detail: `docs/REF-OPERASIONAL.md` §8.

## 7. Konvensi & keamanan

- PHP **8.2+**, `declare(strict_types=1)`, namespace `Simrs\Pacs`, tanpa framework/Composer; ekstensi `curl`, `json`, `mbstring` (+`gd` untuk watermark).
- Docblock tipe array untuk method publik; error: `OrthancException` (kode HTTP) / `InvalidArgumentException` (validasi).
- Nama variabel & komentar **Bahasa Indonesia**; adapter CI hanya mapping (logika tetap di `src/`).
- REST Orthanc **tanpa autentikasi** & `RemoteAccessAllowed = true` → batasi LAN/VPN, pakai `PACS_BRIDGE_KEY` + HTTPS bila lintas jaringan.
- Endpoint menampilkan data pasien; otorisasi level aplikasi tetap di SIMRS.

## 8. Rujukan (baca sesuai kebutuhan)

| Topik | File |
|---|---|
| Peta file lengkap & fakta server/alat | `docs/REF-LINGKUNGAN.md` |
| Endpoint REST, library, API key, peran user | `docs/REF-API.md` |
| Dashboard (UI, tab modalitas, daftar alat ke Orthanc) | `docs/REF-DASHBOARD.md` |
| Postman, tarikan `SANTESRV1`, callback, perintah, checklist | `docs/REF-OPERASIONAL.md` |
| Integrasi CodeIgniter CI3/CI4 | `docs/REF-CI.md`, `integrations/codeigniter/README.md` |
| Deploy ke `10.4.0.10` | `docs/REF-DEPLOY.md`, `deploy/README.md` |
| Referensi REST API lengkap | `API.md` |
| Temuan/quirk server PACS | `PACS-ORTHANC.md` |
| Dokumentasi API key untuk tim SIMRS | `docs/API-KEY-SIMRS.pdf` (sumber `.html`) |

# AGENTS.md — Bridge SIMRS (PHP) ↔ Orthanc PACS

Panduan kerja untuk AI agent / developer pada repo ini. Baca ini **sebelum** mengubah kode.

---

## 1. Tujuan Proyek

Menghubungkan **SIMRS berbasis PHP** dengan server **Orthanc PACS**:

1. Mengirim order pasien ke **worklist (MWL)** Orthanc → ditarik alat radiologi (DX).
2. Mengambil **study/gambar** hasil pemeriksaan dari Orthanc (DICOM, JPG, PNG, ZIP).

Modality yang dipakai: **DX** (Digital Radiography), AET `LEAD_CLIENT`.

---

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
| AET client | **`LEAD_CLIENT`** (modality `DX`) |
| IP client | `192.168.200.230` (port lokal `11112` untuk C-MOVE) |
| Config aktif di server | `/tmp/orthanc.json` — **di-generate** dari `/etc/orthanc/*.json` |
| Port 11112 di server | milik container `dicom-router` (AET `DCMROUTER`), **bukan** Orthanc |
| PACS sumber (ditarik cron) | **`192.168.10.15:11121`**, AET `SANTESRV1`, `RetrieveMethod = C-GET` |
| `192.168.10.110` | mesin Windows (IIS 10) dengan DICOM di port 104; **menolak** AET pemanggil yang tidak terdaftar (`reason 0x03`) — belum bisa dipakai sebagai sumber |
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

---

## 4. Aturan Wajib (hasil troubleshooting nyata)

### DO

- Pakai format payload **`{"Tags": {...}}`** saat membuat worklist.
- Set `ScheduledProcedureStepStartDate` = **tanggal hari ini** (`Ymd`), kalau tidak order tidak terlihat oleh alat.
  Zona waktu PHP di-set di `src/autoload.php` (`PACS_TIMEZONE`, default `Asia/Jakarta`) supaya tanggal/jam
  worklist & nomor accession tidak memakai UTC.
- Isi `ScheduledStationAETitle` = AET alat (`LEAD_CLIENT`, `VersanaB-000000`, …).
- `modality` (`0008,0060` pada ScheduledProcedureStep) **tidak dibatasi DX**: `MODALITY` di
  `setting.txt`/config hanya **default**; kirim `modality` per order (DX/CT/US/ECG/PX) + `station_aet`
  alat. Alat baru tetap harus didaftarkan di Orthanc (`DicomModalities`) **dan** di `worklist_devices`.
- Sertakan **`ScheduledProcedureStepStatus = SCHEDULED`** (`0040,0020`). Alat Siemens
  (syngo CT View&GO, AET `CT140643`) memfilter MWL dengan status ini; tanpa tag tersebut
  order **tidak muncul** di "Scan Worklist" walau C-FIND dari server tetap mengembalikan hasil
  (ditemukan 19 Nov 2026 — sudah diisi otomatis oleh `WorklistService::buildTags()`).
- Pertahankan **huruf asli AE Title** alat (jangan di-`strtoupper`): pencocokan
  `ScheduledStationAETitle` di MWL bersifat **case-sensitive** — order berisi
  `VERSANAB-000000` **tidak** ditemukan alat yang query dengan `VersanaB-000000`.
  Sudah ditangani `Settings::validateDevices()` + dashboard (AET disimpan apa adanya).
- Alat baru harus **didaftarkan di Orthanc** (`/etc/orthanc/orthanc.json` →
  `DicomModalities`, butuh `AllowFindWorklist` untuk MWL) — tanpa itu C-FIND MWL
  ditolak: `This AET is not listed in configuration option "DicomModalities"`.
- Tambahkan juga alat baru ke `worklist_devices` (Pengaturan dashboard) supaya muncul
  di pilihan **Alat / Station tujuan**.
- Sebelum mendaftarkan alat, **pastikan AET-nya** — kirim A-ASSOCIATE-RQ dengan called AET kandidat
  (lihat `dicom-probe.py` / `raw_reject_reason()`): jawaban `AC` = AET benar, `RJ reason=0x07` =
  called AE tidak dikenal. Jangan menebak dari `StationName`; contoh CT Siemens: called AET
  `CT140643` diterima, `CT`/`SOMATOM`/`ORTHANC` ditolak.
- Setelah AET terdaftar, uji MWL tanpa alat memakai `python dicom-probe.py 10.4.0.10 4242 ORTHANC <AET_ALAT>`
  (atau C-FIND MWL dengan `ScheduledStationAETitle` = AET alat) lalu **hapus order uji**.
- Format PatientName **tanpa koma**: `TARYA BIN WARDI TN` (pakai `PatientName::safe()`).
- Untuk retrieval DICOM, gunakan **C-MOVE** (bukan C-GET).
- Ambil gambar via REST: `/instances/{id}/rendered` (PNG, support `width`) atau DICOMweb `.../rendered?quality=` (JPEG).
- Untuk resize, pakai `rendered?width=` (PNG). Parameter `width` pada endpoint DICOMweb **diabaikan**.
- Untuk daftar "study terbaru", kirim **`OrderBy`** di `POST /tools/find` (ExtendedFind, Orthanc ≥ 1.12.5).
  Tanpa `OrderBy` Orthanc mengembalikan urutan internal DB, jadi `Limit` bisa membuang study terbaru.
  Sudah ditangani `StudyService::recent()` / `findByPatientId()` (fallback: ambil semua, urutkan di PHP).
- Nama file di ZIP JPG harus **unik** (banyak study multi-series punya `SeriesNumber` sama). Sudah ditangani
  `StudyService::uniqueFileName()`.
- Watermark JPG memakai `src/WatermarkService.php` (GD + FreeType, teks orange). Image Docker sudah
  memasang `gd` + `fonts-dejavu-core`; font alternatif bisa lewat env `PACS_WATERMARK_FONT`.
- Watermark **dilewati otomatis untuk modalitas `US` dan `ECG`** (`StudyService::NO_WATERMARK_MODALITIES`):
  citra USG/EKG sudah memuat identitas pasien dari alat, jadi JPG dikirim apa adanya
  (berlaku untuk `/jpg?watermark=1` maupun `/zip-jpg`). Modalitas lain tetap diberi watermark —
  termasuk `PX` (panoramic gigi) dan `CT`, karena citra panoramic/slice CT tidak memuat identitas pasien.
- Modalitas study **tidak** ada di `MainDicomTags` level Study — ambil dari seri
  (`POST /tools/find` level `Series`). `StudyService::attachModalities()` mengisi
  `MainDicomTags.Modality` (bisa gabungan `DX/US`) untuk semua respons daftar study.
- Study bisa datang **tanpa AccessionNumber** (mis. workstation USG). Resolusi kunci study
  lewat `StudyService::findOneByKey()` (accession → ID Orthanc → DICOM UID) dan dashboard
  memakai `ID` Orthanc sebagai kunci cadangan supaya gambar/ZIP tetap bisa dibuka.
- `POST /worklists` **idempotent per `accession_number`**: kirim ulang (retry timeout) mengembalikan
  order yang sama (`200`, `created:false`); accession milik pasien lain → `409 ACCESSION_CONFLICT`.
- Status order untuk petugas memakai `GET /worklists/{acc}/status` (`sent`/`taken`/`completed`/`cancelled`),
  dihitung dari worklist + study + audit — jangan menyuruh SIMRS menebak dari polling gambar.
- Semua request dicatat `AuditLogger` (`data/audit.log`); aksi penting (create/update/delete order,
  settings/user, login) dicatat sebagai `event`. Akses riwayat: `GET /audit` (manager).
- Callback study baru: worker `tools/notify-studies.php` (cron `deploy/cron-pacs-notify`) memantau
  `/changes` Orthanc dan POST `study.received` + HMAC (`X-Bridge-Signature`) ke `callback_url`.

### DON'T

- ❌ Memanggil `POST /tools/find` dengan `Limit` lalu mengurutkan hasilnya di PHP — Orthanc sudah memotong
  data sebelum diurutkan, sehingga study terbaru bisa hilang.
- ❌ Memberi nama file ZIP berdasarkan `SeriesNumber`-`InstanceNumber` saja tanpa cek duplikat.
- ❌ Membiarkan cron host lama (`/home/pacs/orthanc-sync.sh`, tiap 30 menit + C-MOVE) berjalan
  bersama cron bridge (`/etc/cron.d/pacs-pull`, tiap menit) — menyebabkan `lock aktif` dan
  tarikan dobel. Sudah dinonaktifkan 16 Nov 2026 (backup: `/home/pacs/crontab-backup-*.txt`).
- ❌ Menganggap `lewati: lock aktif` di log sebagai hal normal — itu tanda dua jadwal bertabrakan.
- ❌ Mengirim `QueryRetrieveLevel` di query MWL dari alat → Orthanc membalas **0 hasil**.
- ❌ Mengedit `/tmp/orthanc.json` di container (hilang saat restart).
- ❌ Memakai format payload lama `{"Content": "..."}` → `400 Bad Request`.
- ❌ Memakai **C-GET** → Orthanc menolak (`0xA702`).
- ❌ Menyimpan kredensial server (SSH/password) di repo.
- ❌ Mengisi `CLIENT IP` di `setting.txt` dengan IP server.
- ❌ Menganggap JPG/PNG hasil render sebagai citra diagnostik (lossy, 8-bit).
- ❌ Menambah dependency Composer untuk hal yang bisa dilakukan cURL.

---

## 5. Alur Data

```
HIS/SIMRS --(bridge PHP: POST /worklists)--> Orthanc --(MWL C-FIND, ditarik)--> Alat DX
                                                                                  |
                          Orthanc <--(C-STORE gambar + data pasien)----------------
                             |
                             +--(bridge PHP: GET /studies/{acc}/jpg)--> Aplikasi/Viewer
```

- **Worklist**: Orthanc menyimpan order; alat yang **menarik** (C-FIND), bukan server push.
- **Gambar**: alat yang **mengirim** (C-STORE) ke Orthanc.
- **Auto-hapus order**: Orthanc menghapus entry worklist setelah study dengan `StudyInstanceUID` yang sama diterima & stabil.

---

## 6. Cara Pakai Bridge

### 6.1 Sebagai library (di dalam SIMRS)

```php
require_once '/path/ke/PACSPROJEC/src/autoload.php';

use Simrs\Pacs\Bridge;

$bridge = Bridge::fromSettingFile();

// kirim order ke worklist
$order = $bridge->worklist()->create([
    'patient_name'     => 'TARYA BIN WARDI',
    'title'            => 'Tn.',
    'patient_id'       => 'A048919',
    'accession_number' => 'A261105038',
    'procedure'        => 'THORAX PA',
]);

// cari study hasil pemeriksaan
$studies = $bridge->studies()->findByAccession('A261105038');

// ambil gambar JPG
$img = $bridge->studies()->imageByAccession('A261105038', 'jpg', 90);
if ($img !== null) {
    file_put_contents(storage_path('app/x.jpg'), $img['content']);
}
```

### 6.2 Sebagai REST API (untuk dipanggil SIMRS lain / front-end)

| Method | Endpoint | Keterangan |
|---|---|---|
| GET | `/` atau `/dashboard` | **dashboard web** (login user: manager / operator) |
| POST | `/login` | login (`{username,password}`) → sesi PHP + `role` |
| POST | `/logout` | keluar |
| GET | `/me` | status sesi + `role` + `is_admin` |
| GET/PUT | `/settings` | baca pengaturan (semua user) / ubah (**PUT hanya manager**) |
| POST | `/settings/password` | ganti password akun sendiri (`{current_password,new_password}`) |
| GET | `/users` | daftar user dashboard (**manager**) |
| POST | `/users` | tambah user `{username,password,role}` (**manager**) |
| PUT | `/users/{username}` | ubah peran / reset password (**manager**) |
| DELETE | `/users/{username}` | hapus user — akun sendiri & manager terakhir ditolak (**manager**) |
| GET | `/health` | status Orthanc + status worklist (`worklist.reachable`, jumlah order) + jumlah study |
| GET | `/accession` | nomor AccessionNumber baru mengikuti format alat Orthanc (dipakai form order) |
| GET | `/worklists` | daftar order |
| POST | `/worklists` | buat order (JSON) — **idempotent** per `accession_number` (`201` baru / `200` sudah ada / `409` accession milik pasien lain) |
| GET | `/worklists/{id}` | detail order |
| GET | `/worklists/{acc}/status` | status order untuk petugas: `sent` / `taken` / `completed` / `cancelled` + timeline dari audit |
| PUT | `/worklists/{id}` | ubah order |
| DELETE | `/worklists/{id}` | hapus order |
| GET | `/audit?limit=50&accession=...` | riwayat audit (request + event) — **manager** |
| GET | `/studies?accession=...` | cari study |
| GET | `/studies?patient_id=...` | cari study per pasien |
| GET | `/studies?name=...` | cari study per **nama pasien** (parsial, wildcard `*KATA*KATA*`, maks 50 study terbaru; alias `patient_name`) |
| GET | `/studies/recent?date=20261113&limit=12&modality=US` | study per tanggal (`date=today\|all\|YYYYMMDD`); `modality` = kode DICOM (`US` = USG, `DX` = rontgen, `CT` = CT scan, `ECG` = EKG, `PX` = panoramic gigi, kosong/`all` = semua); default tanggal dari `study_filter` |
| GET | `/studies/{acc}/jpg?quality=90` | gambar JPEG (fallback PNG bila SOPInstanceUID ambigu); `&watermark=1` = ber-watermark (dilewati untuk modalitas US) |
| GET | `/studies/{acc}/png?width=1024` | gambar PNG (bisa resize) |
| GET | `/studies/{acc}/zip` | ZIP seluruh study (DICOM) |
| GET | `/studies/{acc}/zip-jpg?quality=90` | ZIP berisi JPG **ber-watermark**, folder = No. RM (`watermark=0` untuk mematikan; modalitas US selalu tanpa watermark) |
| GET | `/studies/{acc}/images` | daftar instance/gambar study |
| GET | `/instances/{id}/jpg?quality=90` | render 1 instance sebagai JPEG |
| GET | `/instances/{id}/file` | 1 file DICOM |

Catatan: pada semua endpoint study, `{acc}` boleh berisi **AccessionNumber**, **ID Orthanc**
(`142ca2db-e3ae40d0-...`), atau **StudyInstanceUID**. Alat tertentu (mis. workstation USG)
mengirim study **tanpa AccessionNumber**; dashboard memakai ID Orthanc untuk study seperti
itu supaya gambar/ZIP tetap bisa diambil.

Contoh body POST `/worklists`:

```json
{
  "patient_name": "TARYA BIN WARDI",
  "title": "Tn.",
  "patient_id": "A048919",
  "accession_number": "A261105038",
  "procedure": "THORAX PA",
  "modality": "DX",
  "station_aet": "LEAD_CLIENT"
}
```

### 6.3 Dashboard web

`public/dashboard.html` — halaman siap pakai (tanpa build step), otomatis tampil di `/` dan `/dashboard`:

- **Login user** dengan 2 peran: **manager** (`admin`, akses penuh) dan **operator** (tanpa Pengaturan).
  Default pertama kali: `admin` / `admin`; user dikelola dari Pengaturan → panel **Pengguna**
  (`users[]` di `data/settings.json`, password disimpan sebagai hash). Semua user bisa ganti
  password sendiri lewat tombol **Akun** di header.
- kartu statistik (study/pasien/instance/worklist),
- **dua indikator status di header**: `Orthanc terhubung` (REST) dan `Worklist terhubung · N order`
  (service MWL/plugin `orthanc-worklists`; jadi merah bila plugin mati meski Orthanc hidup),
- **tata letak**: **sidebar Worklist** (±360px, sticky saat halaman digulir) + panel **Study & Gambar**
  yang lebih lebar; di bawah 1180px otomatis satu kolom. Toolbar pencarian/tanggal study **sticky**
  di bawah header (offset dihitung dari tinggi header lewat CSS variable `--header-h`).
  Kartu study: thumbnail 178px, tombol biru **Lihat gambar** (selebar kartu), lalu
  **ZIP (JPG)** / **ZIP (DICOM)** / **Viewer** sebaris; badge modalitas diisi modalitas
  sebenarnya dari seri (mis. `US`, bukan selalu `DX`); thumbnail yang gagal dimuat menampilkan
  teks "gambar belum tersedia" (bukan kotak kosong),
- daftar worklist + form kirim order + tombol hapus (form punya pilihan **Alat / Station tujuan**
  dari daftar alat di Pengaturan, dan **Accession Number terisi otomatis** dari `GET /accession`
  — format alat Orthanc — dengan tombol ⟳ untuk nomor baru; saat kosong muncul tombol
  "⚙ Atur / tambah alat worklist"),
- **panel Alat Worklist** di Pengaturan: daftar alat (Nama, AE Title, Modality) dengan tombol
  **＋ Tambah alat** / **Hapus**, disimpan sebagai `worklist_devices` di `data/settings.json`
  (server 10.4.0.10 saat ini: `LEAD_CLIENT · DX`, `USG · VersanaB-000000 · US`,
  `EKG · LEAD_PHILIP · ECG`, dan `CT Scan · CT140643 · CT`),
  AE Title disimpan **apa adanya** (huruf besar/kecil berpengaruh pada MWL),
- **tab modalitas** di atas toolbar study: **Semua / Ronsen (DX) / CT Scan (CT) / USG (US) / EKG (ECG) / Panoramic (PX)**
  (`GET /studies/recent?modality=US|DX|CT|ECG|PX`; ikut menyaring daftar "di luar filter tanggal").
  Tab dibuat otomatis dari peta `MODALITIES` di `public/dashboard.html` — tambah modalitas baru
  cukup di peta itu (label tab + label kalimat ikut berubah),
- **filter study default "Hari ini"** (+ tombol "Hari ini" / "Semua tanggal" / pilih tanggal),
  dan bila ada study bertanggal **setelah** hari ini (jam alat maju) muncul catatan kuning
  "⚠ N study bertanggal di luar filter ini" + kartu study tersebut tetap ditampilkan di bawah
  (kunci gambar/ZIP memakai ID Orthanc bila study tanpa accession),
- pencarian study by accession / No. RM / **nama pasien** (parsial, mis. `adi watik`), grid thumbnail (PNG 320px), tombol **Lihat**
  (viewer memakai gambar **ber-watermark dari server**: kiri `NAMA, SEX, DD/MM/YYYY` /
  `Pemeriksaan, DD/MM/YYYY` / `No. RM`, kanan `InstitutionName` + `ReferringPhysicianName`,
  tanpa nomor accession — **kecuali modalitas US/USG & ECG/EKG yang tampil tanpa watermark**),
  plus **Unduh JPG** (`jpg?watermark=1`), **Unduh ZIP (JPG)** (`/studies/{acc}/zip-jpg`),
  dan **ZIP DICOM**); pratinjau **hampir penuh layar** (`width: min(1680px,98vw)`, `height: min(94vh,1080px)`,
  header & caption tipis supaya area gambar maksimal) dan bisa **di-zoom**: scroll/pinch atau tombol −/+/Fit
  (100%–800%), geser untuk pan saat diperbesar, dobel klik & tombol `0` = fit, tombol **Full** / `F` =
  layar penuh, `Esc` menutup (sekaligus keluar dari fullscreen), label persen di kiri bawah,
  **Viewer** (Stone WebViewer), **ZIP (JPG)** lalu **ZIP (DICOM)**
  (tombol ZIP (JPG) selalu tampil di samping ZIP (DICOM) pada kartu study),
- **modal Pengaturan** (hanya manager): server IP, port REST/DICOM, timeout, AE Title, modality, client IP,
  filter default, **daftar alat worklist** (`worklist_devices`), API key (dengan tombol
  **Generate**), dan panel **Pengguna** (tambah user, ubah peran, reset password, hapus),
- **modal Akun** (semua user, tombol **Akun** di header): ganti password akun sendiri,
- badge peran di header: `manager` / `operator`; tombol **⚙ Pengaturan** disembunyikan untuk operator,
- daftar study menampilkan **jumlah** ("Menampilkan 20 study tanggal …") + tombol **Muat lebih banyak**
  (+24 per klik; API membatasi maksimum 300). Kalau semua sudah tampil, muncul "Total N … (semua sudah tampil)",
- **panel "API Bridge — cara konek & kemampuan"** di dalam Pengaturan (langsung terbuka): status
  API key aktif/belum, **API key yang sedang aktif** (tombol Sembunyikan/Salin), Base URL aktif,
  tabel 25 endpoint dengan kolom **X-API-Key** (✅ boleh pakai key, ❌ harus sesi login), contoh kode
  curl / PHP (`BridgeApiClient`) / Python, tombol **Salin** per blok,
  serta tombol **Generate** / **Hapus** API key,
- auto-refresh status tiap 30 detik.

Catatan implementasi:

- **Tema**: terang/profesional (background `#f4f6f9`, kartu putih, border `#e3e8ef`, aksen biru `#1565c0`).
  Semua warna memakai CSS variable di `:root` (`--bg`, `--panel`, `--line`, `--accent`, `--ok`, `--danger`) —
  ubah di situ, jangan menambah warna hardcode baru. Hindari gradient/glow/animasi berlebih.
- Dashboard memakai endpoint yang sama dengan API (tidak ada endpoint khusus),
  jadi perubahan API harus dijaga kompatibel.
- Semua endpoint (kecuali `/login`, `/logout`, `/me`) memerlukan **sesi login** atau header `X-API-Key`
  (bila `api_key` diisi). Tanpa keduanya → HTTP 401.
- Pengaturan tersimpan di `data/settings.json`, menimpa `setting.txt`; environment `PACS_*` tetap prioritas tertinggi.
- Dokumentasi API lengkap ada di `API.md` (rujukan utama saat menjawab pertanyaan "API bisa apa saja").

---

### 6.4 API key — akses program ke Bridge API

Dipakai saat SIMRS / aplikasi lain memanggil **Bridge API** (bukan Orthanc langsung) tanpa login manusia.

**Mengisi API key** (pilih salah satu):

1. Dashboard → ⚙ **Pengaturan** → panel *API Bridge* → field **API Key** → tombol **Generate**
   (48 karakter acak) → **Simpan Pengaturan**. Tombol **Hapus** untuk mematikan key.
   Menyimpan pengaturan **tanpa** mengisi field API Key tidak menghapus key yang sudah ada.
2. Environment variable `PACS_BRIDGE_KEY` (prioritas tertinggi, tidak menulis file).

Key yang tersimpan dikembalikan **ter-mask** oleh `GET /settings` (`0123abcd...beef`).
Untuk melihat nilai utuhnya: dashboard → ⚙ Pengaturan → panel *API Bridge* → baris
**"API key yang sedang aktif"** (tombol **Sembunyikan** / **Salin**), atau
`GET /settings/api-key` yang hanya bisa dipanggil dengan sesi login (403 bila pakai API key).

Generate key yang aman:

```powershell
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

**Cara pakai:** header `X-API-Key: <key>` pada setiap request — **hanya lewat header**
(query string/body/cookie diabaikan). Semua endpoint data menerima key
(`/health`, `/accession`, `/worklists*`, `/studies*`, `/instances*`, `GET /settings`);
4 kelompok administratif **menolak** key dengan **403**: `GET /settings/api-key`,
`PUT /settings`, `POST /settings/password`, `/users*`. Daftar per endpoint:
[`API.md` §2](./API.md).

```bash
curl -H "X-API-Key: $PACS_BRIDGE_KEY" http://pacs-bridge.test/health
curl -H "X-API-Key: $PACS_BRIDGE_KEY" http://pacs-bridge.test/worklists

curl -X POST http://pacs-bridge.test/worklists \
  -H "X-API-Key: $PACS_BRIDGE_KEY" -H "Content-Type: application/json" \
  -d '{"patient_name":"TARYA BIN WARDI","title":"Tn.","patient_id":"A048919",
       "accession_number":"A261105038","procedure":"THORAX PA"}'

curl -H "X-API-Key: $PACS_BRIDGE_KEY" \
  "http://pacs-bridge.test/studies/A261105038/jpg?quality=90" -o hasil.jpg
```

**Hak akses (matriks):**

| Aksi | Sesi **manager** | Sesi **operator** | `X-API-Key` |
|---|---|---|---|
| `/health`, `/worklists` (GET/POST/PUT/DELETE) | ✅ | ✅ | ✅ |
| `/studies`, `/studies/recent`, gambar/zip/DICOM | ✅ | ✅ | ✅ |
| `GET /settings` | ✅ | ✅ (tanpa info API key) | ✅ |
| **`PUT /settings`** (ubah IP/port/AET) | ✅ | ❌ **403** | ❌ **403** |
| **`GET /settings/api-key`** | ✅ | ❌ **403** | ❌ **403** |
| **`GET /audit`** (riwayat audit) | ✅ | ❌ **403** | ❌ **403** |
| **`POST /settings/password`** (akun sendiri) | ✅ | ✅ | ❌ **403** |
| **`/users`** (kelola user) | ✅ | ❌ **403** | ❌ **403** |
| `/login`, `/logout`, `/me` | publik | publik | publik |

Alasan: API key untuk **operasional data** (worklist & gambar), perubahan konfigurasi server +
kelola user hanya untuk **manager** yang login, sedangkan **operator** fokus ke pekerjaan harian
(order worklist, lihat/unduh gambar) tanpa bisa mengubah pengaturan.

**Di SIMRS (PHP/CodeIgniter):**

```php
use Simrs\Pacs\BridgeApiClient;

$api = new BridgeApiClient('http://pacs-bridge.test', getenv('PACS_BRIDGE_KEY'));

$api->health();                                   // status Orthanc
$api->createWorklist([...]);                      // kirim order
$img = $api->jpegByAccession('A261105038', 90);   // ['content','mime','filename']
```

**Keamanan:**

- Key = kredensial penuh untuk data pasien → simpan di env/secret manager, **jangan** di source code.
- Wajib HTTPS bila diakses lewat jaringan (bukan LAN/localhost).
- Rotasi: ganti nilai di Pengaturan (atau env), lalu restart pemanggil.
- Kosongkan field API Key untuk kembali ke mode **sesi login saja**.

Kapan pakai apa:

| Kebutuhan | Pakai |
|---|---|
| SIMRS jadi SCU DICOM (MWL/C-STORE) | `Bridge` + `WorklistService`/`StudyService` → **Orthanc langsung** |
| SIMRS/aplikasi lain butuh data via HTTP (JSON/gambar) | **Bridge API** + `X-API-Key` |
| Operator manusia (cek/ganti order, lihat gambar) | **Dashboard** (sesi login) |

### 6.5 Postman (coba API manual)

`postman/` berisi 2 file:

| File | Isi |
|---|---|
| `PACS-Bridge.postman_collection.json` | 19 request dalam 5 folder: Auth, Health, Worklist, Studies & Gambar, Settings |
| `PACS-Bridge.postman_environment.json` | environment **Local** — `baseUrl` `http://127.0.0.1:8081`, variabel: `apiKey`, `username`, `password`, `patientName`, `patientTitle`, `accession`, `patientId`, `worklistId`, `studyDate` |
| `PACS-Bridge-Live.postman_environment.json` | environment **Live** — `baseUrl` `http://10.4.0.10` (server produksi), variabel sama; `apiKey` & `password` dikosongkan (diisi di Postman, tipe `secret`) |

Cara pakai:

1. Postman → **Import** → pilih collection + salah satu environment (Local **atau** Live).
2. Pilih environment di kanan atas: **PACS Bridge - Live** untuk `http://10.4.0.10`,
   **PACS Bridge - Local** untuk `http://127.0.0.1:8081`.
3. Isi `apiKey` = nilai API Key dari dashboard. Collection sudah set auth level-collection
   `API Key` → key `X-API-Key`, value `{{apiKey}}`, jadi **tidak perlu** menambah header manual.
4. Untuk request di folder **Settings** (butuh sesi): jalankan **Auth → Login** dulu
   (Postman menyimpan cookie sesi otomatis), baru `Update settings` / `Ganti password`.
   Kalau dipanggil hanya dengan `X-API-Key` hasilnya **403** (memang disengaja).

Catatan:

- API key itu **hanya header HTTP biasa** (`X-API-Key`). Bisa juga diisi lewat tab
  **Authorization → API Key** dengan *Add to: Header*, bukan query param.
- Request `Create worklist` mengembalikan `id`; untuk `Get/Update/Delete worklist`
  isi `worklistId` dengan id tersebut (atau ambil dari **Worklist → List**).
- `POST /worklists` bersifat *idempotent-ish*: membuat StudyInstanceUID baru, jadi
  **selalu hapus** worklist uji setelah mencoba agar server tetap bersih.

### 6.6 Penarikan otomatis dari PACS lain (`SANTESRV1`)

Di server `10.4.0.10` ada cron yang menarik study dari PACS Sante (`SANTESRV1`)
ke Orthanc lewat C-GET (`DicomGetScu`, `RemoteAet=SANTESRV1`) — sekarang dijadwalkan
**tiap 1 menit** (near-realtime) dengan script di repo, menggantikan cron 30 menit lama.
Gambar dari **alat DX tidak lewat cron** — itu sudah realtime via C-STORE.

Versi yang dipakai ada di **`tools/pull-santesrv1.php`** (inkremental: study yang sudah ada
di Orthanc dilewati, `data/pull-santesrv1.lock` mencegah run bertumpuk) dan jadwalnya di
**`deploy/cron-pacs-pull`**.

Lock **ditunggu** (maks 30 detik), bukan langsung dilewati: cron `* * * * *` dan `5 * * * *`
bisa berbunyi pada detik yang sama, sedangkan satu tarikan hanya butuh < 3 detik. Sebelumnya
jadwal susulan (jam ke-5) selalu tercatat "lewati: lock aktif" sehingga study kemarin tidak
pernah tersusul.

```powershell
php tools/pull-santesrv1.php --dry-run      # daftar yang belum ditarik
php tools/pull-santesrv1.php --date=today   # tarik (aman diulang, tidak menggandakan)
php tools/pull-santesrv1.php --quiet        # mode cron: log hanya bila ada aktivitas
```

Rincian alur, jadwal cron, dan opsi realtime (push dari Sante / Lua `OnStableStudy` /
polling `/changes`): **`PACS-ORTHANC.md` §14**.

Status pemasangan saat ini: cron 1 menit **sudah** terpasang di server (`/etc/cron.d/pacs-pull`,
log `/var/log/pacs-pull.log`) dan cron host lama `orthanc-sync.sh` (30 menit) sudah
**dinonaktifkan** 16 Nov 2026 agar tidak menarik dobel. Di PC ini masih ada
**Windows Task Scheduler `PACS Pull SANTESRV1`** (interval 1 menit, log `data/pull-windows.log`);
matikan task tersebut bila tidak lagi diperlukan supaya tidak tarik dobel dari dua mesin.

### 6.7 User dashboard: manager & operator

Dua peran (disimpan di `data/settings.json` → `users[]`):

| Peran | Boleh | Tidak boleh |
|---|---|---|
| **manager** (`admin`) | semua: worklist, study/gambar, **Pengaturan**, **kelola user**, API key | — |
| **operator** | worklist (buat/ubah/hapus order), cari study, lihat & unduh gambar/ZIP, ganti password sendiri | buka/ubah **Pengaturan**, lihat **API key**, kelola user |

- Kelola user: dashboard → ⚙ **Pengaturan** → panel **Pengguna** (tambah user, ubah peran,
  reset password, hapus). Endpoint: `GET/POST /users`, `PUT/DELETE /users/{username}`.
- Semua user mengganti password sendiri lewat tombol **Akun** di header (`POST /settings/password`).
- Pengaman server: `PUT /settings`, `GET /settings/api-key`, dan seluruh `/users` → **403** untuk
  operator maupun `X-API-Key`; peran dibaca ulang tiap request sehingga perubahan langsung berlaku.
- Tidak bisa menghapus akun yang sedang dipakai login, dan **manager terakhir** tidak bisa
  dihapus/diturunkan (cegah lockout).
- Instalasi lama (hanya `admin_user` + `admin_password_hash`) otomatis dimigrasikan menjadi
  `users[]` saat user pertama kali dikelola; password admin lama tetap berlaku.

### 6.8 Callback study baru (bridge → SIMRS)

SIMRS tidak perlu polling. Isi **Callback URL** + **Callback key** di Pengaturan
(`callback_url`, `callback_key` di `data/settings.json`; key dikembalikan ter-mask).

- Worker: `tools/notify-studies.php` — memantau `GET /changes` Orthanc (sequence terakhir di
  `data/notify-state.json`), mengirim `POST study.received` ke `callback_url`.
- Header: `X-Bridge-Event`, `X-Bridge-Key`, `X-Bridge-Signature: sha256=HMAC(body, callback_key)`.
- At-least-once: retry 3× lalu berhenti (posisi tidak dimajukan) → diproses pada jadwal berikutnya.
  **Penerima wajib idempotent** per `study_id`.
- Batas per eksekusi `--max=200`; dedupe `study_id` per run; log `data/callback.log`.
- Cron: `deploy/cron-pacs-notify` (tiap menit, dijalankan **di dalam container** sebagai
  `www-data` — host 10.4.0.10 tidak punya `php`; log `/var/log/pacs-notify.log`).
  Mode `--quiet` hanya menulis satu baris ringkasan bila ada callback yang diproses.
  Uji: `--latest`, `--dry-run`, `--since=SEQ`.
- Env override untuk uji: `PACS_CALLBACK_URL`, `PACS_CALLBACK_KEY`.

```powershell
php tools/notify-studies.php --latest --dry-run     # lihat payload
php tools/notify-studies.php --latest               # kirim ulang study terbaru (uji)
php tools/notify-studies.php --quiet                # mode cron
```

---

## 7. Perintah

```powershell
# self-test bridge (aman: entry uji dibuat lalu dihapus)
php tools/selftest.php

# self-test integrasi CodeIgniter (CI3 + CI4)
php tools/test-ci-integration.php

# self-test API key pada Bridge API
php tools/test-api-key.php http://127.0.0.1:8081 <API_KEY>

# self-test idempotensi order, status, 409, dan audit (Bridge API)
php tools/test-order-flow.php http://127.0.0.1:8081 <API_KEY>

# pratinjau/kirim callback study baru
php tools/notify-studies.php --latest --dry-run
php tools/notify-studies.php --latest

# self-test peran user (manager vs operator) — membuat & menghapus user uji
php tools/test-users.php http://127.0.0.1:8081 admin admin

# jalankan API dev (router wajib: path ber-titik seperti UID DICOM)
php -S 0.0.0.0:8081 -t public public/router.php
# -> http://127.0.0.1:8081/  (dashboard, login admin/admin)

# tarik study baru dari PACS sumber (SANTESRV1) — dipakai cron 30 menit
php tools/pull-santesrv1.php --dry-run       # lihat yang belum ditarik
php tools/pull-santesrv1.php --date=today    # tarik (inkremental, aman diulang)

# lint semua file PHP
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }

# cetak ulang PDF dokumentasi API key untuk SIMRS (butuh Chrome/Edge)
& 'C:\Program Files\Google\Chrome\Application\chrome.exe' --headless=new --disable-gpu `
  --no-pdf-header-footer --print-to-pdf="docs\API-KEY-SIMRS.pdf" `
  "file:///C:/laragon/www/PACSPROJEC/docs/API-KEY-SIMRS.html"

# uji DICOM (C-ECHO / MWL / C-FIND)
python dicom-probe.py 10.4.0.10 4242 ORTHANC LEAD_CLIENT
```

Di Laragon: arahkan docroot ke folder `public/`.

---

## 8. Checklist Validasi Setelah Perubahan

1. `php -l` bersih untuk semua file yang diubah.
2. `php tools/selftest.php` → **8 pass, 0 fail**.
3. Endpoint API merespons: `/health` 200, `/studies/{acc}/jpg` mengembalikan `image/jpeg`.
4. Perubahan pada order/status/audit: `php tools/test-order-flow.php <baseUrl> <API_KEY>` → **8 pass, 0 fail**
   (idempotensi, 409, status `sent`→`cancelled`, 404, `/audit` 403 untuk key).
5. Callback: `php tools/notify-studies.php --latest --dry-run` menampilkan payload; dengan receiver aktif,
   `--latest` → `[OK] … HTTP 200` dan `data/callback.log` berisi entri `ok:true`.
6. Entry worklist yang dibuat **selalu dihapus** di akhir pengujian (jangan tinggalkan order palsu di server produksi).
7. Jangan menguji C-STORE gambar palsu ke PACS produksi tanpa izin + rencana penghapusan.
8. Perubahan pada autentikasi/user: `php tools/test-users.php <baseUrl> <manager> <password>` → **19 pass, 0 fail**
   (tool ini membuat user uji lalu menghapusnya sendiri).

---

## 9. Konvensi Kode

- PHP **8.2+**, `declare(strict_types=1);`, namespace `Simrs\Pacs`.
- Tanpa framework & tanpa Composer; hanya ekstensi `curl`, `json`, `mbstring`.
- Method publik diberi docblock tipe array (`@return array<string, mixed>`).
- Error: lempar `OrthancException` (kode HTTP Orthanc tersimpan) atau `InvalidArgumentException` untuk validasi.
- Nama variabel/komentar: **Bahasa Indonesia** agar konsisten dengan kode SIMRS.

---

## 10. Keamanan

- REST Orthanc di server saat ini **tanpa autentikasi** dan `RemoteAccessAllowed = true` → siapa pun yang bisa menjangkau port 8042 dapat membuat/menghapus worklist dan **menghapus study**. Jika bridge diekspos ke jaringan lebih luas, aktifkan `PACS_BRIDGE_KEY` dan/atau autentikasi Orthanc.
- Jangan pernah menulis password/kredensial ke repo, log, atau dokumentasi.
- Endpoint bridge menampilkan data pasien (nama, ID, gambar). Batasi akses (LAN/VPN) dan tambahkan otorisasi di level aplikasi SIMRS.

---

## 11. Referensi

- Temuan lengkap server, quirk, dan hasil uji: [PACS-ORTHANC.md](./PACS-ORTHANC.md)
- Integrasi CodeIgniter (CI3 & CI4): [integrations/codeigniter/README.md](./integrations/codeigniter/README.md)
- Spesifikasi DICOM MWL: `1.2.840.10008.5.1.4.31`
- DX Image Storage: `1.2.840.10008.5.1.4.1.1.1.1`

---

## 12. Integrasi CodeIgniter (SIMRS)

SIMRS berbasis CodeIgniter. **Dua mode** disiapkan tanpa mengubah SDK inti:

| Mode | CI4 | CI3 | Alur / auth |
|---|---|---|---|
| **A. Bridge API** (disarankan) | `App\Libraries\PacsApi` | `Pacs_api` | HTTP ke `http://10.4.0.10/` + `X-API-Key`; Orthanc **tidak** diakses SIMRS |
| **B. Langsung Orthanc** | `App\Libraries\Pacs` | `Pacs_bridge` | REST `10.4.0.10:8042`, tanpa auth (batasi LAN) |

Keduanya membungkus fitur terbaru: `nextAccession()`, `recentStudies()`,
`findStudiesByPatientId()/ByName()`, `imageList()`, `zipJpgByAccession()` (folder = No. RM),
dan `zipByAccession()`. Semua wrapper hanya memanggil service di `src/` (tidak ada logika HTTP
baru di adapter), sehingga perilakunya sama dengan REST API. Mode A memakai wrapper tipis di atas
`Simrs\Pacs\BridgeApiClient` (bukan menulis cURL sendiri).

### Aturan saat menyentuh kode integrasi

1. **Jangan duplikasi logika HTTP** — mode B lewat `src/OrthancClient.php` + service `src/`;
   mode A lewat `src/BridgeApiClient.php`. Adapter tidak boleh membuat request cURL sendiri.
2. Perubahan perilaku wajib di `src/` (bukan di adapter CI), agar CI3/CI4/REST konsisten.
3. Adapter CI hanya berisi: resolusi path SDK, mapping config framework → array `Bridge::fromArray()`
   (mode B) atau `BridgeApiClient` (mode A), dan konversi response.
4. CI3: tangkap error sebagai `false` + `error_message()` (konvensi CI3), bukan exception.
5. CI4: lempar `Throwable` dan biarkan controller yang mengubahnya jadi HTTP status.
6. Nama method mengikuti konvensi framework (CI4 camelCase, CI3 snake_case) — jangan diseragamkan.

### Instalasi singkat

```
Mode A (Bridge API, tanpa akses Orthanc):
CI4: app/Libraries/PacsApi.php + app/Config/Pacs.php (bridgeUrl, apiKey, bridgeTimeout)
CI3: application/libraries/Pacs_api.php + application/config/pacs.php (bridge_url, api_key)

Mode B (langsung Orthanc):
CI4: <SIMRS>/app/ThirdParty/pacs/src/ + app/Libraries/Pacs.php, app/Config/Pacs.php
CI3: application/third_party/pacs/src/ + application/libraries/Pacs_bridge.php, config/pacs.php
```

Config framework → SDK (mode B):

```php
Bridge::fromArray([
    'server_ip' => '10.4.0.10', 'http_port' => 8042, 'dicom_port' => 4242,
    'called_aet' => 'ORTHANC', 'calling_aet' => 'LEAD_CLIENT', 'modality' => 'DX',
]);
```

### Verifikasi

```powershell
php tools/test-ci-integration.php     # 9 pass, 0 fail (mode B)

# termasuk mode A (butuh bridge berjalan + key)
$env:PACS_BRIDGE_TEST_URL='http://127.0.0.1:8081'
$env:PACS_BRIDGE_TEST_KEY='<API_KEY>'
php tools/test-ci-integration.php     # 12 pass, 0 fail
```

---

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
tar -czf $tgz src public tools integrations postman deploy setting.txt Dockerfile .dockerignore AGENTS.md API.md PACS-ORTHANC.md
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

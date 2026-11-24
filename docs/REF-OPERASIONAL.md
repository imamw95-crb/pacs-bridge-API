# REF Operasional — Postman, tarikan SANTESRV1, callback, perintah, checklist

> Dipisah dari `AGENTS.md` agar konteks agent tetap kecil. Baca hanya bila menyentuh topik ini.

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

# self-test auto expertise Ollama (membuat draf lalu menghapusnya; butuh ollama_enabled=true)
php tools/test-expertise.php http://127.0.0.1:8081 <API_KEY>
php tools/test-expertise.php http://127.0.0.1:8081 <API_KEY> <ACCESSION> --keep

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
9. Perubahan pada pendaftaran alat/modality: di Pengaturan tambah alat uji (mis. `TESTG2`, IP dummy) →
   **Daftarkan ke Orthanc** → badge berubah "Terdaftar di Orthanc" dan `GET /modalities` menampilkan AET itu.
   **Wajib bersih-bersih:** `curl -X DELETE http://10.4.0.10:8042/modalities/TESTG2`, hapus baris alat
   di Pengaturan, dan pastikan `GET /modalities` kembali ke daftar asli.
10. Perubahan pada auto expertise/Ollama: `php tools/test-expertise.php <baseUrl> <API_KEY>` → **7 pass**
    (`GET /ollama/status` reachable + model vision, `POST` menghasilkan draf berisi kesan/temuan,
    draf tersimpan bisa dibaca ulang, kunci tanpa draf → `null`/404). Draf uji dihapus otomatis
    kecuali `--keep`; jangan tinggalkan file di `data/expertise/` untuk study pasien sungguhan.

# API.md — Bridge SIMRS ↔ Orthanc PACS

Dokumentasi **REST API Bridge** (PHP, tanpa framework): apa saja yang bisa dilakukan,
cara autentikasi, contoh koneksi (curl / PHP / CodeIgniter / Postman / browser),
serta contoh respons nyata dari server.

Untuk konteks server PACS (Orthanc, DICOM, MWL) lihat [PACS-ORTHANC.md](./PACS-ORTHANC.md).
Untuk aturan kerja developer/agent lihat [AGENTS.md](./AGENTS.md).

---

## 1. Ringkasan

| Item | Nilai |
|---|---|
| Base URL (dev) | `http://127.0.0.1:8081/` |
| Base URL (produksi) | `http://<ip-server-bridge>/` (mis. `http://10.4.0.10:8080/`) |
| Format | JSON (kecuali gambar & ZIP → binary) |
| Autentikasi | header `X-API-Key: <key>` **atau** cookie sesi login |
| Backend | Orthanc REST (`http://10.4.0.10:8042`) |
| CORS | `Access-Control-Allow-Origin: *` (GET/POST/PUT/DELETE/OPTIONS) |

Semua endpoint menerima `OPTIONS` (preflight) dan mengembalikan `204`.

Gambar/dokumen tersedia dalam 3 bentuk: **JPEG** (untuk viewer/EMR), **PNG** (resize,
untuk thumbnail), **ZIP** (semua DICOM satu study).

---

## 2. Autentikasi

> **Versi siap cetak untuk tim SIMRS:** [`docs/API-KEY-SIMRS.pdf`](./docs/API-KEY-SIMRS.pdf)
> (PDF siap cetak) — cara memperoleh key, tabel endpoint yang butuh key, mode integrasi CI
> (`PacsApi`/`Pacs_api` lewat Bridge API vs library langsung ke Orthanc),
> penanganan error, keamanan/rotasi, dan checklist go-live.
> Sumber HTML: [`docs/API-KEY-SIMRS.html`](./docs/API-KEY-SIMRS.html).

Ada 2 cara, pilih sesuai pemanggil:

| Pemanggil | Cara | Header/Cookie |
|---|---|---|
| Program (SIMRS, service, cron, Postman) | **API key** | `X-API-Key: <key>` |
| Manusia di dashboard (browser) | **Sesi login** | cookie `PHPSESSID` hasil `POST /login` |

API key diisi di **dashboard → ⚙ Pengaturan → API Key** (disimpan di `data/settings.json`)
atau lewat environment variable `PACS_BRIDGE_KEY` (prioritas tertinggi).

Generate key:

```powershell
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

### Matriks hak akses

| Aksi | `X-API-Key` | Sesi **manager** | Sesi **operator** |
|---|---|---|---|
| `GET /health` | ✅ | ✅ | ✅ |
| `/worklists` (GET/POST/PUT/DELETE) | ✅ | ✅ | ✅ |
| `/studies*`, gambar, ZIP, DICOM | ✅ | ✅ | ✅ |
| `/studies/{key}/expertise` (draf AI) | ✅ | ✅ | ✅ |
| `GET /settings` | ✅ | ✅ | ✅ (tanpa info API key) |
| `GET /settings/api-key` (tampilkan key utuh) | ❌ **403** | ✅ | ❌ **403** |
| `PUT /settings` (ubah IP/port/AET) | ❌ **403** | ✅ | ❌ **403** |
| `POST /settings/password` (password sendiri) | ❌ **403** | ✅ | ✅ |
| `/users` — daftar/tambah/ubah/hapus user | ❌ **403** | ✅ | ❌ **403** |
| `GET /modalities` (status modality Orthanc) | ❌ **403** | ✅ | ❌ **403** |
| `PUT /modalities/{aet}` (daftarkan alat) | ❌ **403** | ✅ | ❌ **403** |
| `POST /modalities/{aet}/echo` (C-ECHO) | ❌ **403** | ✅ | ❌ **403** |
| `POST /login`, `POST /logout`, `GET /me` | publik | publik | publik |

> Alasan: API key dipakai untuk **operasional data** (order & gambar). Perubahan
> konfigurasi server, pengelolaan user, dan API key hanya untuk **manager**;
> **operator** menjalankan pekerjaan harian tanpa bisa mengubah Pengaturan.

Tanpa kredensial sama sekali → **401** `{"error":"Unauthorized", ...}`.
Kalau API key **belum diset** di server, sesi login masih bisa dipakai (mode default).

### Endpoint mana yang perlu mengirim `X-API-Key`

Key dikirim **hanya lewat header HTTP**. Tidak diterima di query string (`?api_key=...`),
body JSON, maupun cookie — kalau ditaruh di tempat lain, request dianggap **tanpa kredensial**
(`401`). Nama header tidak peka huruf (`x-api-key` boleh), tetapi **nilainya dibandingkan
persis** (jangan menambah spasi/kutip). Preflight `OPTIONS` tidak perlu key (selalu `204`),
jadi panggilan `fetch`/XHR dari browser tidak terblokir CORS.

| Endpoint | `X-API-Key` | Catatan |
|---|---|---|
| `OPTIONS *` | – | preflight CORS → `204` |
| `GET /`, `GET /dashboard` | – | halaman dashboard (publik; login lewat form) |
| `POST /login`, `POST /logout` | – | publik |
| `GET /me` | – | publik; tanpa kredensial → `authenticated:false`, dengan key → `via:"api-key"` |
| `GET /health` | ✅ | |
| `GET /accession` | ✅ | |
| `GET /worklists` | ✅ | |
| `POST /worklists` | ✅ | |
| `GET /worklists/{id}` | ✅ | |
| `PUT /worklists/{id}` | ✅ | |
| `DELETE /worklists/{id}` | ✅ | |
| `GET /studies?accession=...` | ✅ | |
| `GET /studies?patient_id=...` | ✅ | |
| `GET /studies?name=...` | ✅ | |
| `GET /studies/recent` | ✅ | |
| `GET /studies/{acc}/jpg` | ✅ | gambar biner |
| `GET /studies/{acc}/png` | ✅ | gambar biner |
| `GET /studies/{acc}/images` | ✅ | |
| `GET /studies/{acc}/zip` | ✅ | arsip biner |
| `GET /studies/{acc}/zip-jpg` | ✅ | arsip biner |
| `GET /studies/{acc}/expertise` | ✅ | draf AI terakhir |
| `POST /studies/{acc}/expertise` | ✅ | panggil model vision Ollama |
| `GET /ollama/status` | ✅ | status server AI |
| `GET /instances/{id}/jpg` | ✅ | |
| `GET /instances/{id}/file` | ✅ | file DICOM |
| `GET /settings` | ✅ | **tanpa** field `api_key` / `has_api_key` (hanya manager yang melihat) |
| `GET /settings/api-key` | ❌ `403` | key utuh → sesi **manager** saja |
| `PUT /settings` | ❌ `403` | sesi **manager** saja |
| `POST /settings/password` | ❌ `403` | sesi login (manager/operator) |
| `GET /users`, `POST /users` | ❌ `403` | sesi **manager** saja |
| `PUT /users/{username}`, `DELETE /users/{username}` | ❌ `403` | sesi **manager** saja |
| `GET /modalities` | ❌ `403` | sesi **manager** saja |
| `PUT /modalities/{aet}` | ❌ `403` | sesi **manager** saja |
| `POST /modalities/{aet}/echo` | ❌ `403` | sesi **manager** saja |

Ringkasnya: **semua endpoint data** (`/health`, `/accession`, `/worklists*`, `/studies*`,
`/instances*`, `GET /settings`) bisa dipanggil dengan key. **Hanya kelompok administratif**
yang menolak key: `GET /settings/api-key`, `PUT /settings`, `POST /settings/password`,
`/users*`, dan `/modalities*`.

Contoh respons bila key tidak dikirim / salah / ditaruh di query string:

```json
{ "error": "Unauthorized", "message": "Silakan login di dashboard" }
```

→ **401**. Bila key benar tetapi endpoint hanya untuk sesi login, jawabannya **403**:

| Endpoint | `error` |
|---|---|
| `GET /settings/api-key` | `API key hanya bisa dilihat lewat sesi login` |
| `PUT /settings` | `Perubahan pengaturan harus melalui sesi login` |
| `POST /settings/password` | `Harus login` |
| `/users*` | `Hanya akun manager yang boleh mengelola user` |
| `/modalities*` | `Status dan pendaftaran modality hanya untuk sesi login manager` |

Mengosongkan API Key di Pengaturan **tidak** membuka akses anonim: tanpa key **dan** tanpa
sesi selalu `401`, karena API ini memang tidak punya endpoint data terbuka.

---

## 3. Kemampuan API (bisa apa saja)

| # | Kemampuan | Endpoint | Dipakai untuk |
|---|---|---|---|
| 1 | Cek koneksi & statistik PACS | `GET /health` | indikator status Orthanc **dan** worklist di SIMRS (`worklist.reachable` + jumlah order) |
| 2 | **Kirim order ke worklist (MWL)** | `POST /worklists` | modalitas (DX/CT/US) otomatis menerima nama pasien, No. RM, accession, prosedur saat pasien didaftarkan di SIMRS |
| 3 | Lihat daftar order | `GET /worklists` | monitor order yang belum/sudah dikerjakan |
| 4 | Lihat detail satu order | `GET /worklists/{id}` | cek data yang benar-benar diterima modalitas |
| 4b | **Nomor accession otomatis** (format alat Orthanc) | `GET /accession` | isi Accession Number tanpa mengetik manual |
| 5 | Ubah order | `PUT /worklists/{id}` | salah ketik prosedur/nama, jadwal berubah |
| 6 | Hapus order | `DELETE /worklists/{id}` | order batal / duplikat |
| 7 | Cari study by **Accession Number** | `GET /studies?accession=...` | menghubungkan hasil radiologi ke order SIMRS |
| 8 | Cari study by **No. RM** | `GET /studies?patient_id=...` | riwayat radiologi pasien |
| 8b | Cari study by **nama pasien** (parsial) | `GET /studies?name=...` | pencarian cepat saat No. RM/accession tidak diketahui |
| 9 | Study terbaru (hari ini / semua / per modalitas) | `GET /studies/recent?date=today\|all&modality=US\|DX\|CT\|ECG\|PX` | daftar kerja radiografer, dashboard |
| 10 | **Ambil gambar JPEG** (1 gambar representatif, bisa ber-watermark) | `GET /studies/{acc}/jpg?quality=&watermark=1` | thumbnail/lampiran di EMR, laporan (USG/EKG: tanpa watermark) |
| 11 | **Ambil gambar PNG** (bisa di-resize) | `GET /studies/{acc}/png?width=` | thumbnail cepat, preview kecil |
| 12 | **Unduh ZIP study** (semua DICOM) | `GET /studies/{acc}/zip` | arsip, second opinion, kirim ke RS lain |
| 12b | **Unduh ZIP berisi JPG ber-watermark** (satu folder No. RM) | `GET /studies/{acc}/zip-jpg?quality=` | lampiran EMR/laporan yang hanya bisa membuka JPG |
| 12c | Daftar gambar (instance) satu study | `GET /studies/{acc}/images` | ambil semua gambar (mis. untuk ZIP/PDF sendiri) |
| 13 | Unduh 1 instance DICOM | `GET /instances/{id}/file` | integrasi viewer DICOM |
| 14 | Baca konfigurasi aktif | `GET /settings` | audit/verifikasi arah koneksi |
| 14b | Lihat API key aktif (utuh, sesi login) | `GET /settings/api-key` | ambil key untuk dikonfigurasi di SIMRS |
| 15 | Ubah konfigurasi (khusus manager login) | `PUT /settings` | pindah IP/port/AET tanpa edit file |
| 16 | Ganti password akun sendiri | `POST /settings/password` | keamanan |
| 17 | **Kelola user dashboard** (manager/operator) | `GET/POST /users`, `PUT/DELETE /users/{username}` | buat akun operator tanpa akses Pengaturan |
| 18 | **Status order untuk petugas** | `GET /worklists/{acc}/status` | tampilkan "terkirim / diproses / gambar masuk / dibatalkan" tanpa polling gambar |
| 19 | **Audit log** (siapa, kapan, order apa) | `GET /audit` | telusuri pembuatan/penghapusan order & perubahan konfigurasi (manager) |
| 20 | **Callback/webhook study baru** | `tools/notify-studies.php` + `callback_url` | SIMRS menerima `POST study.received` (HMAC) tanpa polling |
| 21 | **Daftarkan alat baru ke Orthanc** (DicomModalities) | `GET /modalities`, `PUT /modalities/{aet}`, `POST /modalities/{aet}/echo` | alat baru (mis. USG G2) boleh menarik MWL & mengirim gambar tanpa edit `orthanc.json` (manager) |
| 22 | **Draf expertise AI** (model vision Ollama, LAN) | `POST /studies/{acc}/expertise`, `GET /studies/{acc}/expertise`, `GET /ollama/status` | draf bacaan untuk diverifikasi dokter; citra tanpa watermark, tidak keluar LAN |

Catatan penting: bridge **tidak menyimpan gambar** — semua endpoint gambar mengambil
langsung dari Orthanc saat dipanggil (stateless).

> **Kunci study (`{acc}`) fleksibel.** Pada semua endpoint study, `{acc}` boleh berisi
> **AccessionNumber**, **ID Orthanc** (mis. `142ca2db-e3ae40d0-e9b328a0-6a77ec35-80702d8c`),
> **StudyInstanceUID** (`1.2.840.113619...`), atau **No. RM / PatientID** (mis. `A050109`).
> Ini perlu karena sebagian alat (mis. CT scan & workstation USG) mengirim study **tanpa
> AccessionNumber**; dashboard memakai ID Orthanc, sedangkan SIMRS bisa memakai No. RM.
> Bila satu No. RM punya beberapa study, yang diambil adalah **study terbaru** (urut
> StudyDate + StudyTime); pakai AccessionNumber/StudyInstanceUID untuk study tertentu.

---

## 4. Referensi endpoint

### 4.1 Auth (publik)

#### `POST /login`

```json
{ "username": "admin", "password": "admin" }
```

Respons `200`:

```json
{ "authenticated": true, "user": "admin" }
```

Password salah → `401` `{"error":"Username atau password salah"}`.

Cookie sesi (`PHPSESSID`) otomatis dipakai untuk request berikutnya (Postman/browser
menyimpannya sendiri).

#### `GET /me`

```json
{
  "authenticated": true,
  "via": "session",
  "user": "operator1",
  "role": "operator",
  "is_admin": false,
  "default_credentials": false
}
```

`via` bernilai `session` (cookie), `api-key` (`X-API-Key`), atau `none`.
`role` = `admin` (manager) atau `operator`; `is_admin` = `true` hanya untuk manager.
`default_credentials=true` = belum ada password yang diubah (segera ganti).

#### `POST /logout`

```json
{ "authenticated": false }
```

---

### 4.2 Health

#### `GET /health`

Respons `200` (contoh nyata dari server produksi):

```json
{
    "reachable": true,
    "base_url": "http://10.4.0.10:8042",
    "version": "1.12.11",
    "dicom_aet": "ORTHANC",
    "dicom_port": 4242,
    "count_studies": 16143,
    "count_patients": 12184,
    "count_instances": 24981,
    "worklist": {
        "reachable": true,
        "count": 3,
        "error": null
    },
    "bridge_version": "1.3.3",
    "server_time": "2026-11-19T11:55:29+07:00"
}
```

`bridge_version` = versi kode bridge yang sedang berjalan — pakai field ini untuk memastikan
hasil deploy sudah aktif (dashboard juga memuat `<meta name="bridge-version">`).

Field `worklist` = status **service Modality Worklist** (plugin `orthanc-worklists`), terpisah dari
status Orthanc: Orthanc bisa hidup tapi plugin worklist mati → `worklist.reachable = false`
dengan pesan di `worklist.error`. `worklist.count` = jumlah order yang sedang menunggu.

Kalau Orthanc mati/timeout: `502` dengan `{"error":"Orthanc error","message":"..."}`.

---

### 4.3 Worklist (kirim order ke modalitas)

#### `GET /accession`

Nomor **AccessionNumber baru** mengikuti format alat di Orthanc (tanggal + jam tanpa nol di depan,
mis. `1411202654722880`; maksimal 16 karakter sesuai VR `SH`). Nomor yang sudah dipakai worklist
otomatis dilewati.

```json
{ "accession_number": "14112026135949" }
```

Dashboard memakai endpoint ini untuk mengisi field **Accession Number (otomatis)** pada form order
(tombol ⟳ mengambil nomor baru). Zona waktu generator mengikuti `PACS_TIMEZONE`
(default `Asia/Jakarta`).

#### `POST /worklists`

Field **wajib**: `patient_name`, `patient_id`, `accession_number`, `procedure`.
Field **opsional**: `title`, `modality`, `station_aet`, `start_date` (`YYYYMMDD`),
`start_time` (`HHMMSS`), `sps_id`, `study_instance_uid`, `birth_date`, `sex`,
`requested_procedure_id`.

> `modality` (tag `0008,0060` pada ScheduledProcedureStep) **tidak dibatasi DX**.
> Bila tidak dikirim, dipakai nilai default dari Pengaturan (`PACS_MODALITY`, bawaan `DX`).
> Order CT/USG/EKG cukup mengisi `modality` + `station_aet` sesuai alat — alatnya tetap harus
> terdaftar di `DicomModalities` Orthanc dan di daftar **Alat Worklist** dashboard.

```json
{
  "patient_name": "BUDI SANTOSO",
  "title": "Tn.",
  "patient_id": "A000001",
  "accession_number": "A260100001",
  "procedure": "THORAX PA",
  "modality": "DX"
}
```

Respons `201` (order baru dibuat):

```json
{
  "id": "5b16e282-d4e2-439e-9fab-07d6b9e90675",
  "patient_name": "BUDI SANTOSO TN.",
  "accession_number": "A260100001",
  "created": true,
  "duplicate": false
}
```

**Idempotent (aman dikirim ulang).** Kuncinya `accession_number`. Bila SIMRS mengirim order yang
sama dua kali (mis. karena timeout lalu retry), bridge **tidak** membuat worklist ganda —
order yang sudah ada dikembalikan dengan **HTTP `200`** dan `created: false`:

```json
{
  "id": "5b16e282-d4e2-439e-9fab-07d6b9e90675",
  "patient_name": "BUDI SANTOSO TN.",
  "accession_number": "A260100001",
  "created": false,
  "duplicate": true
}
```

Bila accession sama tetapi **No. RM berbeda**, bridge menolak dengan **`409`** (bukan menimpa data pasien lain):

```json
{
  "error": "Konflik accession",
  "code": "ACCESSION_CONFLICT",
  "message": "AccessionNumber A260100001 sudah dipakai pasien lain (No. RM A000001)."
}
```

Untuk mengubah order yang sudah ada, gunakan `PUT /worklists/{id}`.

**Aturan nama (penting):** modalitas memotong `PatientName` pada karakter koma.
Karena itu bridge menormalkan nama menjadi `NAMA GELAR` (tanpa koma):

| Input | Yang terkirim |
|---|---|
| `patient_name="AMANAH BINTI KASDIM, Ny."` | `AMANAH BINTI KASDIM NY.` |
| `patient_name="TARYA BIN WARDI"` + `title="Tn."` | `TARYA BIN WARDI TN.` |

Tanggal/jam order default = hari ini (waktu server Bridge).

Validasi gagal (field wajib kosong) → `400`:

```json
{ "error": "Validasi gagal", "code": "VALIDATION_FAILED", "message": "patient_name, patient_id, accession_number, dan procedure wajib diisi" }
```

#### `GET /worklists`

```json
[
  {
    "ID": "5b16e282-d4e2-439e-9fab-07d6b9e90675",
    "Tags": {
      "AccessionNumber": "A260100001",
      "PatientID": "A000001",
      "PatientName": "BUDI SANTOSO TN.",
      "RequestedProcedureDescription": "THORAX PA",
      "ScheduledProcedureStepSequence": [
        {
          "Modality": "DX",
          "ScheduledProcedureStepDescription": "THORAX PA",
          "ScheduledProcedureStepID": "SPS-A260100001",
          "ScheduledProcedureStepStartDate": "20261113",
          "ScheduledProcedureStepStartTime": "040009",
          "ScheduledStationAETitle": "LEAD_CLIENT"
        }
      ],
      "SpecificCharacterSet": "ISO_IR 192",
      "StudyInstanceUID": "1.2.276.0.7230010.3.1.2.942814513.1.1794542410.986404"
    }
  }
]
```

Server kosong → `[]`.

#### `GET /worklists/{id}`

Sama seperti elemen di atas. **404** bila tidak ada:

```json
{
  "error": "Data tidak ditemukan",
  "code": "NOT_FOUND",
  "message": "Data yang diminta belum ada di PACS (order/study/gambar).",
  "orthanc_status": 404
}
```

#### `GET /worklists/{acc}/status`

Status order untuk ditampilkan ke petugas. Kuncinya **AccessionNumber**
(bukan ID Orthanc). Tidak butuh polling gambar: status dihitung dari worklist,
study, dan audit log.

```json
{
  "accession_number": "A261105038",
  "status": "completed",
  "label": "Gambar sudah masuk",
  "worklist": { "present": false, "id": null },
  "studies": {
    "count": 1,
    "modalities": ["DX"],
    "images": 1,
    "ids": ["cdf3bb70-123baeec-0bde07e1-607b6f43-b1b795ba"]
  },
  "timeline": {
    "created_at": "2026-11-19T11:40:12+07:00",
    "deleted_at": "2026-11-19T11:41:02+07:00",
    "last_event": { "event": "worklist.delete", "ts": "2026-11-19T11:41:02+07:00" }
  },
  "checked_at": "2026-11-19T11:41:05+07:00"
}
```

| `status` | `label` | Arti |
|---|---|---|
| `sent` | Order terkirim ke alat | Entry worklist masih ada, study belum masuk |
| `taken` | Ditarik / diproses alat | Entry worklist sudah hilang, study belum stabil masuk |
| `completed` | Gambar sudah masuk | Study (dan gambar) sudah ada di PACS |
| `cancelled` | Order dibatalkan | Entry dihapus lewat `DELETE /worklists/{id}` (tercatat di audit) |

Accession tidak dikenal (tidak ada worklist, study, atau riwayat) → **404**:

```json
{
  "error": "Data tidak ditemukan",
  "code": "ORDER_NOT_FOUND",
  "message": "Order dengan AccessionNumber A260100001 tidak ditemukan."
}
```

#### `PUT /worklists/{id}`

Body sama dengan create (kirim lengkap — tag yang tidak dikirim akan hilang):

```json
{
  "patient_name": "BUDI SANTOSO",
  "title": "Tn.",
  "patient_id": "A000001",
  "accession_number": "A260100001",
  "procedure": "THORAX PA LATERAL"
}
```

Respons `200`:

```json
{
  "id": "5b16e282-d4e2-439e-9fab-07d6b9e90675",
  "patient_name": "BUDI SANTOSO TN.",
  "accession_number": "A260100001"
}
```

#### `DELETE /worklists/{id}`

```json
{ "deleted": "5b16e282-d4e2-439e-9fab-07d6b9e90675" }
```

---

### 4.4 Study & gambar

#### `GET /studies?accession=<accession>`

```json
[
  {
    "ID": "cdf3bb70-123baeec-0bde07e1-607b6f43-b1b795ba",
    "IsStable": true,
    "LastUpdate": "20261113T033008",
    "MainDicomTags": {
      "AccessionNumber": "A261105038",
      "InstitutionName": "RS. MITRA PLUMBON PATROL",
      "StudyDate": "20261113",
      "StudyDescription": "THORAX PA",
      "StudyID": "1311202609561724",
      "StudyInstanceUID": "1.2.276.0.7230010.3.1.2.942814513.1.1794539655.986356",
      "StudyTime": "095630.000000"
    },
    "ParentPatient": "29cc8c43-ad976663-cfc50779-7a6f2bb4-ea10149c",
    "PatientMainDicomTags": {
      "PatientID": "A048919",
      "PatientName": "NAMA PASIEN",
      "PatientSex": "F"
    },
    "Series": ["f4767ac9-fe66a490-dee6bafd-..."]
  }
]
```

Tidak ditemukan → `[]` (bukan 404).

#### `GET /studies?patient_id=<no_rm>`

Bentuk respons sama; mengembalikan **semua** study pasien tersebut.

#### `GET /studies?name=<nama_pasien>`

Cari study berdasarkan **nama pasien secara parsial** (tidak perlu lengkap, tidak peka
huruf besar/kecil, maksimal 50 study terbaru). Kata dipisah spasi/koma/`^`/`_` lalu
dicocokkan dengan wildcard Orthanc, jadi `adi watik` menemukan `ADI WATIK, NY`,
`ADI^WATIK^NY`, maupun `ADI_WATIK_NY`. Alias parameter: `patient_name`.

```bash
curl -H "X-API-Key: $KEY" "$BASE/studies?name=adi%20watik"
```

#### `GET /studies/recent?date=today|all&limit=12[&modality=US]`

`limit` 1–300 (default 12). `date` default = `today` (juga menerima `YYYYMMDD`).
`modality` opsional = kode modalitas DICOM: `US` (USG), `DX` (rontgen), `CT` (CT scan),
`ECG` (EKG), `PX` (panoramic gigi / dental); kosong/`all` = semua.
Penyaringan dilakukan sebelum `limit` dipotong, jadi hasil selalu penuh.
Field `count` = jumlah item yang benar-benar dikembalikan (bisa < `limit`), `modality` =
filter yang dipakai (`"all"` bila tidak menyaring).

```json
{
  "date": "20261113",
  "limit": 12,
  "modality": "DX",
  "count": 20,
  "studies": [
    {
      "ID": "09912538-c450a159-7fd8e13a-83441917-8b446e80",
      "MainDicomTags": {
        "AccessionNumber": "131120269225293",
        "StudyDate": "20261113",
        "StudyDescription": "Chest AP",
        "StudyTime": "092345.000000",
        "Modality": "DX"
      },
      "PatientMainDicomTags": {
        "PatientID": "A050834",
        "PatientName": "NAMA PASIEN",
        "PatientSex": "M"
      }
    }
  ]
}
```

`MainDicomTags.Modality` diisi bridge dari seri-seri study (Orthanc tidak menyimpan
Modality di level Study). Nilainya bisa gabungan, mis. `DX/US`, untuk study multi-modalitas.

Bila `date` = **hari ini** dan ada study bertanggal **setelah** hari ini (jam alat maju),
respons menambahkan field `outside` (tidak ada bila kosong):

```json
{
  "outside": {
    "count": 1,
    "studies": [ { "ID": "142ca2db-...", "MainDicomTags": { "StudyDate": "20261118", "Modality": "US" } } ]
  }
}
```

Dashboard menampilkannya di bawah catatan "di luar filter" supaya study tetap bisa dibuka,
padahal tanggalnya (menurut alat) bukan hari ini.

`date=all` → semua tanggal (diurutkan tanggal study menurun).

Pengurutan dilakukan **di Orthanc** (`OrderBy` pada `POST /tools/find`, butuh
ExtendedFind → Orthanc ≥ 1.12.5) memakai `StudyDate` lalu `StudyTime` menurun,
sehingga `limit` memotong data yang sudah terurut. Bila Orthanc lebih lama /
backend tanpa ExtendedFind, bridge mengambil seluruh hasil lalu mengurutkan
sendiri di PHP. Ini penting: Orthanc tanpa `OrderBy` mengembalikan urutan
internal database, jadi study terbaru bisa **tidak muncul** di halaman pertama.

#### `GET /studies/{accession}/jpg?quality=90[&watermark=1]`

`Content-Type: image/jpeg`. `quality` 1–100 (default 90).
Sumber: DICOMweb `/rendered`. Bila study punya SOPInstanceUID duplikat server
menolak DICOMweb, bridge otomatis **fallback** ke PNG native Orthanc.

`watermark=1` (atau `true`) menambahkan watermark identitas pasien di atas gambar
(orange): kiri `NAMA, SEX, DD/MM/YYYY` / `Pemeriksaan, DD/MM/YYYY` / `No. RM: <nomor>`;
kanan `InstitutionName` + `ReferringPhysicianName`. Nomor accession **tidak** ikut
dicetak di watermark. Watermark butuh ekstensi PHP
**GD + FreeType** (sudah ada di image Docker); bila tidak tersedia endpoint membalas
**503**.

> **Modalitas `US` (USG) dan `ECG` (EKG) tidak pernah diberi watermark** — citra USG/EKG
> sudah memuat identitas pasien dari alat, jadi `watermark=1` diabaikan dan gambar dikirim
> apa adanya. Modalitas lain (termasuk `PX` panoramic dan `CT`) tetap diberi watermark.

#### `GET /studies/{accession}/png?width=800`

`Content-Type: image/png`. `width` opsional (px). Cocok untuk thumbnail cepat.
Lebih stabil dibanding JPEG karena memakai renderer native Orthanc.

#### `GET /studies/{accession}/zip`

`Content-Type: application/zip` + `Content-Disposition: inline; filename="<accession|No. RM>.zip"`.
Isi: seluruh instance DICOM study tersebut. Nama file memakai AccessionNumber; bila study
tidak punya accession (mis. CT), dipakai **No. RM** pasien, lalu ID Orthanc sebagai
fallback terakhir.

Arsip **dialirkan langsung** dari Orthanc ke klien (bukan ditampung di memori PHP) dan
tanpa batas `max_execution_time`, karena study CT bisa berisi ratusan slice (>100 MB).
Jangan mengubahnya menjadi `archiveStudy()` (menampung seluruh ZIP di string) — dengan
`memory_limit` default 128 MB unduhan CT akan gagal `Allowed memory size exhausted`.

#### `GET /studies/{accession}/zip-jpg?quality=90[&watermark=0]`

`Content-Type: application/zip` + `Content-Disposition: inline; filename="<No. RM>.zip"`.
Isi: **semua gambar study sebagai JPG ber-watermark**, dibungkus satu folder bernama
**No. RM pasien**:

```
A050956/
  ├─ 1-001.jpg
  ├─ 1-002.jpg
  └─ 1-001-s2-003.jpg
```

- Nama file `<SeriesNumber>-<InstanceNumber>.jpg`; render PNG native dikonversi ke JPG
  agar watermark seragam.
- Bila nama bentrok (study multi-series: `SeriesNumber` sering sama dan `InstanceNumber`
  berulang antar seri), akhiran `-s<seri>-<urutan>` ditambahkan supaya **setiap gambar
  tetap ada** saat diekstrak (tanpa ini extractor menimpa file sebelumnya).
- `quality` 1–100 (default 90).
- **Watermark aktif secara default.** Kirim `watermark=0` (atau `false`) untuk ZIP tanpa
  watermark. Untuk study **modalitas US (USG) dan ECG (EKG)** watermark selalu dilewati,
  karena citra sudah memuat identitas pasien dari alat.
- Study CT besar (ratusan slice) perlu merender + memberi watermark semua gambar, bisa
  **>30 detik**; endpoint ini menonaktifkan `max_execution_time` agar tidak gagal di tengah
  jalan. Batas waktu proxy di host (nginx/lighttpd) harus ≥ 120 detik.
- Arsip dibuat di memori oleh bridge (`src/ZipWriter.php`, metode *store*, tanpa
  ekstensi PHP `zip`/`zlib`); watermark memakai `src/WatermarkService.php` (GD + FreeType).

#### `GET /studies/{accession}/images`

Daftar gambar (instance) study, urut sesuai seri Orthanc:

```json
[
  {"instance_id": "caee3762-…", "series_number": "1", "instance_number": "1"}
]
```

#### `GET /instances/{id}/jpg?quality=90`

`Content-Type: image/jpeg` untuk 1 instance (fallback `image/png` bila renderer
DICOMweb menolak karena SOPInstanceUID ambigu).

#### `GET /instances/{id}/file`

`Content-Type: application/dicom`, 1 file `.dcm` (untuk viewer DICOM).
Butuh Orthanc `instance` ID (dari `Series`/tool Orthanc), bukan accession.

---

### 4.5 Settings

#### `GET /settings`

```json
{
  "settings": {
    "server_ip": "10.4.0.10",
    "called_aet": "ORTHANC",
    "calling_aet": "LEAD_CLIENT",
    "modality": "DX",
    "client_ip": "10.4.0.10",
    "http_port": 8042,
    "dicom_port": 4242,
    "timeout": 30,
    "study_filter": "today",
    "admin_user": "admin",
    "has_password": true,
    "has_api_key": true,
    "api_key": "0123abcd...beef"
  },
  "effective": {
    "base_url": "http://10.4.0.10:8042",
    "called_aet": "ORTHANC",
    "calling_aet": "LEAD_CLIENT",
    "modality": "DX"
  }
}
```

`has_api_key=false` artinya mode **sesi login saja** (belum ada key untuk program).
Kalau key ada, `api_key` dikirim **ter-mask** (`8 karakter awal...4 karakter akhir`).

#### `GET /settings/api-key` — **butuh sesi**

Menampilkan key **utuh** untuk dibaca/dicopy admin (dipakai tombol "Sembunyikan / Salin" di dashboard).
Tidak bisa dipanggil dengan API key (403) supaya key tidak bisa membocorkan dirinya sendiri.

```json
{ "has_api_key": true, "api_key": "0123456789abcdef0123456789abcdef0123456789abcdef" }
```

#### `PUT /settings` — **butuh sesi**

```json
{
  "server_ip": "10.4.0.10",
  "http_port": 8042,
  "dicom_port": 4242,
  "timeout": 30,
  "called_aet": "ORTHANC",
  "calling_aet": "LEAD_CLIENT",
  "modality": "DX",
  "client_ip": "10.4.0.10",
  "study_filter": "today",
  "auto_modality_tabs": true,
  "api_key": "opsional — kosongkan untuk mematikan API key",
  "worklist_devices": [
    { "name": "DX-1", "aet": "LEAD_CLIENT", "modality": "DX", "host": "", "port": 104 },
    { "name": "USG", "aet": "VersanaB-000000", "modality": "US", "host": "192.168.10.130", "port": 104 },
    { "name": "EKG", "aet": "LEAD_PHILIP", "modality": "ECG", "host": "172.16.200.137", "port": 104 },
    { "name": "CT Scan", "aet": "CT140643", "modality": "CT", "host": "192.168.10.19", "port": 104 }
  ]
}
```

- `worklist_devices` = daftar **alat/station worklist** yang bisa dipilih saat mengirim order.
  `aet` dipakai sebagai `ScheduledStationAETitle`, `modality` sebagai `Modality` pada
  `ScheduledProcedureStepSequence`. `host`/`port` = alamat DICOM alat, dipakai tombol
  **Daftarkan ke Orthanc** (§4.8). Kirim `[]` untuk kembali memakai AE Title/Modality default
  dari konfigurasi. Dashboard menampilkannya di **Pengaturan → Alat Worklist (modality)**.
- `auto_modality_tabs` (default `true`) = tab modalitas di panel **Study & Gambar** ikut
  menambahkan modalitas yang benar-benar muncul di study. Nonaktif (`false`) → tab hanya dari
  `worklist_devices`. Hanya berpengaruh ke tampilan dashboard.

- Field `api_key` **boleh tidak dikirim**: kalau tidak ada di body, key yang sudah tersimpan
  **tetap dipakai** (tidak terhapus). Ini perilaku dashboard: simpan pengaturan tanpa mengisi
  field API Key tidak akan menghapus key.
- Kirim `"api_key": ""` untuk **menghapus** API key (tombol **Hapus** di dashboard).

Dengan API key saja → `403`:

```json
{ "error": "Perubahan pengaturan harus melalui sesi login" }
```

Respons sukses: `{"saved": true, "settings": { ... }}`.

#### `POST /settings/password` — **butuh sesi**

Mengganti password **akun yang sedang login** (manager maupun operator).

```json
{ "current_password": "admin", "new_password": "RahasiaBaru123" }
```

| Kondisi | Respons |
|---|---|
| sukses | `200` `{"saved": true}` |
| password lama salah | `400` `{"error":"Password lama salah"}` |
| password baru < 4 karakter | `400` `{"error":"Password baru minimal 4 karakter"}` |
| pakai API key (bukan sesi) | `403` `{"error":"Harus login"}` |

#### `/users` — **khusus manager**

| Method | Path | Body | Keterangan |
|---|---|---|---|
| GET | `/users` | — | `{"users":[{username,role,is_self,has_password}],"roles":["admin","operator"]}` |
| POST | `/users` | `{"username","password","role"}` | tambah user (`201`); username 3–32 karakter |
| PUT | `/users/{username}` | `{"role"?, "password"?}` | ubah peran / reset password |
| DELETE | `/users/{username}` | — | hapus user |

Aturan pengaman: operator & API key → `403`; tidak bisa menghapus akun yang sedang dipakai
login (`400`); **manager terakhir** tidak bisa dihapus/diturunkan (`400`).

```bash
# buat akun operator
curl -X POST "$BASE/users" -b cookies.txt -H "Content-Type: application/json" \
  -d '{"username":"operator1","password":"RahasiaOperator1","role":"operator"}'
```

---

### 4.6 Audit log

#### `GET /audit` — **khusus sesi manager**

Membaca `data/audit.log` (JSON Lines). Setiap request HTTP dicatat otomatis
(metode, path, status, durasi, IP, kredensial) dan aksi penting dicatat sebagai event
(`worklist.create`, `worklist.update`, `worklist.delete`, `settings.update`,
`user.*`, `auth.login`, `auth.login_failed`, `auth.logout`, `api_key.view`).

| Query | Arti |
|---|---|
| `limit` | jumlah entri terakhir (1–500, default 50) |
| `event` | filter event, mis. `worklist.delete` |
| `accession` | filter satu order |
| `method`, `status`, `user`, `auth` | filter tambahan |

```json
{
  "count": 2,
  "filters": { "accession": "A261105038" },
  "entries": [
    {
      "ts": "2026-11-19T11:40:12+07:00",
      "auth": "api-key", "user": null, "role": "api-key", "ip": "10.4.0.12",
      "event": "worklist.create", "accession": "A261105038",
      "worklist_id": "0b0cb295-366c-405f-868c-3c23845e5966",
      "patient_id": "A048919", "modality": "DX", "duplicate": false
    },
    {
      "ts": "2026-11-19T11:40:12+07:00",
      "auth": "api-key", "event": "request", "method": "POST",
      "path": "/worklists", "status": 201, "duration_ms": 118,
      "accession": "A261105038"
    }
  ]
}
```

- API key & operator → **403** (audit hanya untuk manager).
- File dirotasi otomatis ke `audit.log.1` saat melewati 5 MB.
- Nilai API key **tidak pernah** ditulis ke log.

```bash
curl -b cookies.txt "$BASE/audit?limit=20&event=worklist.delete"
curl -b cookies.txt "$BASE/audit?accession=A261105038"
```

---

### 4.7 Callback (webhook) study baru

SIMRS tidak perlu polling `GET /studies` terus-menerus. Isi **Callback URL** (dan **Callback key**)
di Pengaturan; worker `tools/notify-studies.php` (cron tiap menit) memantau `/changes` Orthanc
dan mengirim `POST` ke URL tersebut saat ada study stabil baru.

**Aktifkan:**

```bash
# via API (sesi manager)
curl -X PUT "$BASE/settings" -b cookies.txt -H "Content-Type: application/json" \
  -d '{"callback_url":"https://simrs.local/api/pacs/callback","callback_key":"<CALLBACK_KEY>"}'
```

`callback_key` dikembalikan **ter-mask** di `GET /settings` (`has_callback_key`, `callback_key: "abcd1234...wxyz"`).

**Request dari bridge ke SIMRS:**

```http
POST /api/pacs/callback HTTP/1.1
Content-Type: application/json
X-Bridge-Event: study.received
X-Bridge-Key: <CALLBACK_KEY>
X-Bridge-Signature: sha256=9f2c...e1
```

```json
{
  "event": "study.received",
  "sent_at": "2026-11-19T11:40:57+07:00",
  "accession_number": "A261105038",
  "patient_id": "A048919",
  "patient_name": "TARYA BIN WARDI TN.",
  "study_id": "9060f064-48cfbfa5-63c41030-bf2967f1-e70f790e",
  "study_instance_uid": "1.2.840.114257.1.1.11232.191827.7270194.100",
  "study_date": "20261119",
  "modalities": ["DX"],
  "images": 1,
  "status": "completed"
}
```

**Verifikasi tanda tangan (wajib):**

```php
$raw = file_get_contents('php://input');
$expect = 'sha256=' . hash_hmac('sha256', $raw, getenv('PACS_CALLBACK_KEY'));
if (!hash_equals($expect, $_SERVER['HTTP_X_BRIDGE_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
$event = json_decode($raw, true);   // proses order/gambar
http_response_code(200);            // wajib 2xx agar tidak dikirim ulang
```

Catatan operasional:

- Bridge mencoba **3×** (backoff 0,5 detik; 1,5 detik). Bila tetap gagal, posisi diproses ulang
  pada jadwal berikutnya (at-least-once) — SIMRS **wajib idempotent** terhadap `study_id`.
- Posisi terakhir disimpan di `data/notify-state.json`; log pengiriman di `data/callback.log`.
- Cron (di server): lihat `deploy/cron-pacs-notify` — tiap menit, dijalankan **di dalam container**
  (`docker exec -u www-data pacs-bridge php tools/notify-studies.php --quiet`); log ringkas
  `/var/log/pacs-notify.log`, detail per kiriman `data/callback.log`.
- Uji manual: `php tools/notify-studies.php --latest` / `--dry-run`.
- Callback tidak aktif bila `callback_url` kosong.

---

### 4.8 Modality — daftarkan alat baru ke Orthanc

Alat radiologi baru (mis. **USG G2**) tidak akan bisa menarik MWL atau mengirim gambar
(C-STORE) sebelum AE Title-nya terdaftar di Orthanc (`DicomModalities`). Endpoint ini
melakukannya **dari dashboard** memakai data alat di **Pengaturan → Alat Worklist**
(`worklist_devices` di `data/settings.json`), tanpa mengedit `/etc/orthanc/orthanc.json`.

**Sesi manager saja** — `X-API-Key` dan sesi operator ditolak `403`.

#### `GET /modalities`

```json
{
  "reachable": true,
  "error": null,
  "modalities": [
    {"id": "VersanaB-000000", "aet": "VersanaB-000000", "host": "192.168.10.130", "port": 104,
     "allow_find_worklist": true, "allow_store": true}
  ],
  "devices": [
    {"name": "USG", "aet": "VersanaB-000000", "modality": "US", "host": "192.168.10.130", "port": 104,
     "registered": true, "orthanc": {"id": "VersanaB-000000", "host": "192.168.10.130", "port": 104}}
  ]
}
```

`devices[]` = alat di Pengaturan + status `registered` (sudah ada di Orthanc atau belum).

#### `PUT /modalities/{aet}`

Mendaftarkan / memperbarui modality Orthanc (memanggil `PUT /modalities/{aet}` milik Orthanc).

| Field body (opsional) | Keterangan |
|---|---|
| `host` | menimpa IP/host yang tersimpan di Pengaturan |
| `port` | menimpa port DICOM (default 104) |

```bash
curl -X PUT "$BASE/modalities/USG-G2" -b cookies.txt -H "Content-Type: application/json" -d '{}'
```

```json
{
  "registered": true,
  "aet": "USG-G2",
  "configuration": {"AET": "USG-G2", "Host": "192.168.10.199", "Port": 104,
                    "AllowFindWorklist": true, "AllowStore": true, "AllowFind": true, "AllowEcho": true},
  "message": "Alat USG-G2 terdaftar di Orthanc (MWL + C-STORE)."
}
```

Flag yang dipasang: `AllowEcho`, `AllowFind`, `AllowFindWorklist`, `AllowStore`, `AllowGet`,
`AllowMove`. Konfigurasi lain (mis. `Timeout`, `RetrieveMethod`) dipertahankan bila modality
sudah ada. `404 DEVICE_NOT_FOUND` bila AE Title belum ada di Pengaturan (simpan alatnya dulu),
`400` bila host kosong/tidak valid.

> **Penting (persistensi):** perubahan via REST hanya di memori Orthanc. Agar bertahan setelah
> container Orthanc di-restart, jalankan Orthanc dengan `"DicomModalitiesInDatabase": true`
> (lihat AGENTS.md §3 dan `docs/REF-DASHBOARD.md` §6.9). Tanpa itu, alat yang didaftarkan dari dashboard **hilang** saat
> Orthanc restart — walau tetap ada di daftar alat Pengaturan (tinggal tekan Daftarkan lagi).

#### `POST /modalities/{aet}/echo`

C-ECHO untuk memastikan IP/port/AET alat benar (alat harus menyala).

```json
{"aet": "USG-G2", "echo": true, "result": {}}
```

Bila alat tidak terjangkau → **502** `{"code":"ECHO_FAILED","message":"C-ECHO gagal — alat belum terjangkau atau AET/IP/port salah."}`.

---

### 4.9 Auto expertise — draf bacaan AI (Ollama)

Membuat draf bacaan dari **satu gambar** study (render PNG 1024px, **tanpa watermark** supaya
identitas pasien tidak ikut ke model) memakai model vision di server Ollama jaringan lokal.
Hasil **selalu draf** — wajib diverifikasi dokter/radiolog.

Aktifkan di **Pengaturan → Auto Expertise (Ollama)**: `ollama_enabled`, `ollama_url`
(default dari `data/integrasiollama/ip ollama`), `ollama_model` (default `qwen3.5:9b`),
`ollama_timeout` (5–600 detik). Override env: `PACS_OLLAMA_URL`, `PACS_OLLAMA_MODEL`,
`PACS_OLLAMA_ENABLED`, `PACS_OLLAMA_TIMEOUT`.

#### `GET /ollama/status`

```json
{
  "enabled": true,
  "reachable": true,
  "base_url": "http://192.168.0.9:11434",
  "version": "0.33.1",
  "model": "qwen3.5:9b",
  "model_ready": true,
  "vision_models": ["qwen3.5:9b", "gemma4:12b"],
  "models": [{"name": "mistral-nemo:latest", "vision": false}, {"name": "qwen3.5:9b", "vision": true}]
}
```

Server tidak terjangkau → tetap `200` dengan `reachable:false` + `error` (dipakai tombol
**Tes koneksi** di dashboard).

#### `POST /studies/{key}/expertise`

`{key}` = AccessionNumber, ID Orthanc, StudyInstanceUID, atau No. RM (study terbaru). Body opsional:
`{"model":"gemma4:12b"}` (default `ollama_model`).

`201`:

```json
{
  "study_key": "A261124001",
  "study_id": "cfc94834-...",
  "accession": "A261124001",
  "modality": "DX",
  "study_description": "Chest AP",
  "study_date": "20261124",
  "model": "qwen3.5:9b",
  "prompt_version": "v1",
  "generated_at": "2026-11-24T08:44:12+07:00",
  "duration_ms": 2683,
  "eval_count": 239,
  "parsed": true,
  "draft": {
    "kualitas": "cukup baik",
    "temuan_utama": "tidak dapat dinilai dengan pasti pada satu gambar ini",
    "temuan_lain": "tulang tampak utuh tanpa fraktur jelas",
    "kesan": "tidak ada temuan akut yang jelas terlihat pada satu gambar ini",
    "rekomendasi": "bila klinis mencurigakan, lanjutkan dengan CT toraks",
    "keyakinan": "rendah"
  },
  "disclaimer": "DRAF AI — wajib diverifikasi dokter/radiolog sebelum dipakai."
}
```

Draf disimpan di `data/expertise/{key}.json`. `GET` pada path yang sama mengembalikan draf
terakhir (`404 EXPERTISE_NOT_FOUND` bila belum ada).

Error: `503 EXPERTISE_DISABLED` (saklar mati), `404` study/gambar belum ada, `503 OLLAMA_ERROR`
server Ollama tidak terjangkau, `400 OLLAMA_MODEL_NOT_FOUND` model tidak ada, `502` error 5xx
dari Ollama.

> **Catatan model:** prompt memakai aturan konservatif + `format=json` dan **`think=false`**.
> Tanpa `think=false`, token `num_predict` habis di bagian thinking sehingga `response` kosong.
> Latensi ±3–15 detik per study (load pertama ±8 detik). Uji: `php tools/test-expertise.php <baseUrl> <API_KEY>`.

---

## 5. Contoh koneksi

### 5.1 curl

```bash
BASE="http://127.0.0.1:8081"
KEY="0123456789abcdef0123456789abcdef"

# 1) cek koneksi
curl -H "X-API-Key: $KEY" "$BASE/health"

# 2) kirim order ke worklist (modalitas akan menerima nama pasien)
curl -X POST "$BASE/worklists" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"patient_name":"BUDI SANTOSO","title":"Tn.","patient_id":"A000001",
       "accession_number":"A260100001","procedure":"THORAX PA"}'

# 3) daftar order
curl -H "X-API-Key: $KEY" "$BASE/worklists"

# 4) cari study hasil foto
curl -H "X-API-Key: $KEY" "$BASE/studies?accession=A260100001"

# 5) ambil gambar (JPEG / PNG)
curl -H "X-API-Key: $KEY" "$BASE/studies/A260100001/jpg?quality=90" -o hasil.jpg
curl -H "X-API-Key: $KEY" "$BASE/studies/A260100001/png?width=400" -o thumb.png

# 6) unduh semua DICOM study
curl -H "X-API-Key: $KEY" "$BASE/studies/A260100001/zip" -o study.zip

# 7) unduh semua gambar study sebagai JPG ber-watermark (folder = No. RM)
curl -H "X-API-Key: $KEY" "$BASE/studies/A260100001/zip-jpg?quality=90" -o A000001.zip

# 8) gambar tunggal ber-watermark
curl -H "X-API-Key: $KEY" "$BASE/studies/A260100001/jpg?watermark=1" -o ber-watermark.jpg
```

### 5.2 PHP native (tanpa framework)

```php
<?php
$base = 'http://127.0.0.1:8081';
$key  = getenv('PACS_BRIDGE_KEY');

function bridge(string $url, string $key, string $method = 'GET', ?array $body = null): array
{
    $ch = curl_init($url);
    $headers = ['X-API-Key: ' . $key, 'Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string) $raw, true);
    if ($code >= 400) {
        throw new RuntimeException("Bridge HTTP $code: " . ($data['message'] ?? $raw));
    }

    return is_array($data) ? $data : [];
}

$health = bridge("$base/health", $key);
echo $health['version'];                            // 1.12.11

bridge("$base/worklists", $key, 'POST', [
    'patient_name'     => 'BUDI SANTOSO',
    'title'            => 'Tn.',
    'patient_id'       => 'A000001',
    'accession_number' => 'A260100001',
    'procedure'        => 'THORAX PA',
]);
```

### 5.3 PHP — pakai library bawaan (`BridgeApiClient`)

```php
require __DIR__ . '/src/autoload.php';

use Simrs\Pacs\BridgeApiClient;

$api = new BridgeApiClient('http://127.0.0.1:8081', getenv('PACS_BRIDGE_KEY'));

$api->health();                                        // array
$api->createWorklist([
    'patient_name'     => 'BUDI SANTOSO',
    'title'            => 'Tn.',
    'patient_id'       => 'A000001',
    'accession_number' => 'A260100001',
    'procedure'        => 'THORAX PA',
]);
$api->recentStudies('today', 12);
$api->findStudiesByAccession('A260100001');
$jpg = $api->jpegByAccession('A260100001', 90);        // ['content','mime','filename'] | null
$png = $api->pngByAccession('A260100001', 400);
$zip = $api->zipByAccession('A260100001');             // string biner ZIP | null
```

Kalau gagal, melempar `Simrs\Pacs\BridgeApiException` (berisi `statusCode`).

### 5.4 CodeIgniter (SIMRS)

Library adapter siap pakai ada di [`integrations/codeigniter/`](./integrations/codeigniter/README.md)
(CI3 **dan** CI4). Contoh di controller:

```php
// CI4: app/Config/Pacs.php  -> baseUrl + apiKey dari .env
$pacs = new \App\Libraries\Pacs();

$pacs->createWorklist([
    'patient_name'     => $pasien['nama'],
    'title'            => $pasien['gelar'],
    'patient_id'       => $pasien['no_rm'],
    'accession_number' => $order['accession'],
    'procedure'        => $order['pemeriksaan'],
]);

return $this->response
    ->setHeader('Content-Type', 'image/jpeg')
    ->setBody($pacs->jpegByAccession($order['accession'], 90)['content']);
```

### 5.5 Python / Node.js (service lain)

```python
import os, requests
BASE = "http://127.0.0.1:8081"
H = {"X-API-Key": os.environ["PACS_BRIDGE_KEY"]}

print(requests.get(f"{BASE}/health", headers=H).json())
requests.post(f"{BASE}/worklists", headers=H, json={
    "patient_name": "BUDI SANTOSO", "title": "Tn.", "patient_id": "A000001",
    "accession_number": "A260100001", "procedure": "THORAX PA"}, timeout=30)

img = requests.get(f"{BASE}/studies/A260100001/jpg?quality=90", headers=H, timeout=60)
open("hasil.jpg", "wb").write(img.content)
```

### 5.6 Postman

Import `postman/PACS-Bridge.postman_collection.json` + salah satu environment:

| File | baseUrl | Pakai untuk |
|---|---|---|
| `PACS-Bridge.postman_environment.json` | `http://127.0.0.1:8081` | server dev lokal (`php -S`) |
| `PACS-Bridge-Live.postman_environment.json` | `http://10.4.0.10` | server produksi (lewat lighttpd port 80) |

Pilih **PACS Bridge - Live** (atau **- Local**), lalu isi variabel `apiKey` dengan key dari
dashboard → Pengaturan → panel *API Bridge* (tipe variabel sudah `secret`, nilainya tidak
disimpan di repo). Collection sudah menyetel auth level-collection `API Key → X-API-Key = {{apiKey}}`.

Folder **Settings** butuh sesi: jalankan **Auth → Login** dulu (cookie disimpan Postman).

### 5.7 Browser / tag `<img>` di EMR

Karena butuh header API key, gambar **tidak bisa** langsung `<img src="...">`.
Dua pilihan aman:

1. SIMRS mem-proxy: halaman PHP SIMRS memanggil bridge (server-side) lalu
   mengirim biner ke `<img src="/radiologi/gambar/A260100001">`.
2. Dashboard bridge dipakai apa adanya (sudah login) → endpoint `/studies/{acc}/png` jalan
   karena memakai cookie sesi.

---

## 6. Kode status & error

| HTTP | Arti | Contoh penyebab |
|---|---|---|
| `200` | OK | request berhasil; `POST /worklists` saat order sudah ada (**idempotent**) |
| `201` | Created | `POST /worklists` untuk order baru |
| `204` | No Content | preflight `OPTIONS` |
| `400` | Validasi gagal | field wajib kosong, parameter tidak dikenal |
| `401` | Unauthorized | tanpa API key & tanpa sesi (termasuk key yang ditaruh di query/body); password salah saat login |
| `403` | Forbidden | key dipakai untuk endpoint administratif (`/settings/api-key`, `PUT /settings`, `POST /settings/password`, `/users*`, `/audit`) atau operator membuka Pengaturan |
| `404` | Tidak ditemukan | study/order belum ada (`code: STUDY_NOT_FOUND` / `ORDER_NOT_FOUND`), gambar belum tersedia (`IMAGE_NOT_FOUND`), endpoint salah (`ENDPOINT_NOT_FOUND`) |
| `405` | Method tidak didukung | `PATCH /worklists` |
| `409` | Konflik accession | `POST /worklists` dengan `accession_number` milik **pasien lain** (`code: ACCESSION_CONFLICT`) |
| `500` | Internal error | bug/IO (mis. folder `data/` tidak writable) |
| `502` | Orthanc tidak bisa dipakai | Orthanc mati, timeout, error 5xx dari Orthanc (`code: ORTHANC_ERROR`) |
| `503` | Fitur tidak tersedia | watermark diminta tetapi GD/FreeType tidak ada (`WATERMARK_UNAVAILABLE`) |

Setiap error memuat `error` (label singkat), **`code`** (machine-readable), dan `message`
(kalimat untuk petugas):

```json
{
  "error": "Orthanc error",
  "code": "ORTHANC_ERROR",
  "message": "Koneksi ke Orthanc gagal (http://10.4.0.10:8042/tools/find): Connection timed out after 10011 milliseconds",
  "orthanc_status": 0,
  "orthanc_body": ""
}
```

Daftar `code` yang dipakai SIMRS:

| `code` | HTTP | Kapan |
|---|---|---|
| `UNAUTHORIZED` / `INVALID_CREDENTIALS` | 401 | key/sesi tidak valid, login salah |
| `FORBIDDEN` | 403 | endpoint administratif dengan API key/operator |
| `VALIDATION_FAILED` | 400 | field wajib kosong / parameter salah |
| `ACCESSION_CONFLICT` | 409 | accession sudah dipakai pasien lain |
| `ORDER_NOT_FOUND` | 404 | accession belum pernah dibuat / sudah dihapus tanpa jejak |
| `STUDY_NOT_FOUND` | 404 | study belum masuk ke PACS |
| `IMAGE_NOT_FOUND` | 404 | study ada, gambar belum tersedia |
| `NOT_FOUND` | 404 | resource Orthanc tidak ada (fallback) |
| `METHOD_NOT_ALLOWED` | 405 | metode HTTP tidak didukung |
| `ORTHANC_ERROR` | 502 | Orthanc error/timeout |
| `WATERMARK_UNAVAILABLE` | 503 | GD/FreeType tidak aktif |

---

## 7. Catatan operasional (hasil uji nyata)

1. **Timeout gambar/ZIP lebih lama dari JSON.** JPEG ±1,4 s, PNG ±0,5 s, ZIP study 6,7 MB
   ±5 s untuk 1 study. Set timeout klien ≥ 60 s untuk ZIP.
2. **Default `CURLOPT_CONNECTTIMEOUT` bridge = 10 s.** Bila Orthanc sedang sibuk
   me-render banyak gambar paralel, sebagian request bisa `502` "Connection timed out".
   Solusi: kurangi paralelisme (maks 3–4 gambar sekaligus) atau naikkan `timeout` di Pengaturan.
3. **`/studies?accession=` lebih cepat & stabil daripada `/studies?patient_id=`.**
   Kalau punya accession dari order, pakai itu.
4. **Zona waktu & jam modalitas.** `StudyTime` bisa 18–30 menit lebih awal dari jam server
   (jam alat belum disinkron). Jangan pakai jam study untuk validasi ketat.
5. **Accession Number yang dipakai bridge tidak mengubah StudyInstanceUID.** Order worklist
   membuat UID baru; study dari modalitas tetap memakai UID milik modalitas. Pencarian
   lewat accession tetap menemukan keduanya.
6. **Worklist kosong = `[]`, bukan error.** Modalitas hanya menerima order yang
   tanggalnya cocok (default hari ini).
6b. **"Hari ini" = tag StudyDate**, bukan waktu data masuk. Volume harian wajar berbeda:
   contoh nyata 13 Nov 2026 pukul 11.00 baru **20 study**, sedangkan sehari penuh 12 Nov **88 study**
   (sampai jam 11.00 di hari-hari sebelumnya 26–32 study). Jadi angka kecil di pagi hari itu normal.
   Study yang tanggalnya **setelah** hari ini (jam alat maju) tidak masuk hitungan `count`,
   tetapi dikembalikan terpisah di field `outside` agar tetap bisa dibuka dari dashboard.
6c. **Batas tampilan**: dashboard meminta `limit=24` lalu menambah 24 setiap klik
   "Muat lebih banyak" (maksimum 300 di sisi API). `GET /studies/recent` mengembalikan
   `count` supaya klien tahu apakah masih ada sisa.
7. **Order uji harus dihapus** setelah dicoba (`DELETE /worklists/{id}`) supaya daftar
   modalitas bersih.
8. **Jangan menyimpan API key di source code SIMRS** — pakai `.env` / secret manager.
9. **Matikan API key** dengan mengosongkan field di Pengaturan bila ingin kembali ke
   mode sesi login saja (semua request program akan `401`).
10. **`POST /worklists` idempotent per AccessionNumber.** Kirim ulang saat timeout; jangan
    membuat accession baru untuk order yang sama, karena itu akan membuat worklist ganda.
11. **Callback bersifat at-least-once.** SIMRS wajib mengabaikan `study_id` yang sudah pernah
    diproses (idempotent di sisi penerima).
12. **Audit log** (`data/audit.log`) tumbuh ±1 baris per request; rotasi otomatis pada 5 MB.
    Endpoint `GET /audit` membaca 512 KB terakhir, jadi untuk audit jangka panjang
    arsipkan file lama ke penyimpanan terpisah.

---

## 8. Uji cepat (self-test)

```powershell
php tools/selftest.php          # 8 uji end-to-end (create worklist → render → hapus)
php tools/test-api-key.php http://127.0.0.1:8081 <API_KEY>       # 8 uji API key
php tools/test-order-flow.php http://127.0.0.1:8081 <API_KEY>    # 8 uji idempotensi/status/409/audit
php tools/test-ci-integration.php                                # 9 uji (mode SDK)
php tools/notify-studies.php --dry-run                           # pratinjau callback
```

Jalankan API dev:

```powershell
php -S 127.0.0.1:8081 -t public public/router.php
# dashboard: http://127.0.0.1:8081/  (login admin/admin)
```

`public/router.php` wajib dipakai di server dev: server built-in PHP membalas 404
untuk path yang memuat titik (mis. `/studies/1.2.840.../jpg`) sebelum sampai ke
`index.php`. Di Apache/Laragon hal ini sudah ditangani `public/.htaccess`.

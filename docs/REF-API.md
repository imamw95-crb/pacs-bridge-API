# REF API — alur, library, REST, API key, user

> Dipisah dari `AGENTS.md` agar konteks agent tetap kecil. Baca hanya bila menyentuh topik ini.

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
| GET | `/modalities` | modality Orthanc + status tiap alat di Pengaturan (`registered`) — **manager** |
| PUT | `/modalities/{aet}` | daftarkan/ubah alat di Orthanc (`DicomModalities`: MWL + C-STORE) — **manager** |
| POST | `/modalities/{aet}/echo` | C-ECHO ke alat (uji IP/port/AET) — **manager** |
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
| GET | `/studies/{acc}/expertise` | draf expertise AI terakhir (`data/expertise/{key}.json`; `404` bila belum ada) |
| POST | `/studies/{acc}/expertise` | buat draf expertise AI (model vision Ollama; body opsional `{"model":"qwen3.5:9b"}`; `503` bila `ollama_enabled=false`) |
| GET | `/ollama/status` | status server Ollama: `reachable`, `version`, `vision_models`, `model_ready` |
| GET | `/instances/{id}/jpg?quality=90` | render 1 instance sebagai JPEG |
| GET | `/instances/{id}/file` | 1 file DICOM |

Catatan: pada semua endpoint study, `{acc}` boleh berisi **AccessionNumber**, **ID Orthanc**
(`142ca2db-e3ae40d0-...`), atau **StudyInstanceUID**. Alat tertentu (mis. workstation USG)
mengirim study **tanpa AccessionNumber**; dashboard memakai ID Orthanc untuk study seperti
itu supaya gambar/ZIP tetap bisa diambil.

### 6.10 Auto expertise (draf AI lewat Ollama)

Draf bacaan dari **satu gambar** study (render PNG 1024px, **tanpa watermark** — identitas pasien
tidak ikut ke model) memakai model vision di server Ollama jaringan lokal. Hasil **selalu draf**:
wajib diverifikasi dokter/radiolog, bukan diagnosis final.

- Aktifkan di **Pengaturan → Auto Expertise (Ollama)** (`ollama_enabled`), isi `ollama_url`,
  `ollama_model` (default `qwen3.5:9b`), dan `ollama_timeout` (default 180 detik).
- URL bawaan dibaca dari `data/integrasiollama/ip ollama` (isi mis. `server Ollama 192.168.0.9`
  → `http://192.168.0.9:11434`). Override env: `PACS_OLLAMA_URL`, `PACS_OLLAMA_MODEL`,
  `PACS_OLLAMA_ENABLED`, `PACS_OLLAMA_TIMEOUT`.
- Model wajib punya capability `vision` (`qwen3.5:9b`, `gemma4:12b` di server 192.168.0.9);
  `GET /ollama/status` menampilkan `vision_models` dan `model_ready`. Model tanpa vision
  menghasilkan draf kosong.
- `POST /studies/{key}/expertise` → `201` dengan `{study_key, model, prompt_version, duration_ms,
  parsed, draft:{kualitas, temuan_utama, temuan_lain, kesan, rekomendasi, keyakinan}, disclaimer}`.
  Draf disimpan di `data/expertise/{key}.json`; `GET` mengembalikan draf terakhir.
- Prompt memakai aturan konservatif (tidak menyebut temuan yang tidak jelas terlihat) dengan
  `format=json` dan **`think=false`** — tanpa itu token habis di bagian thinking dan respons
  bisa kosong.
- Latensi ±3–15 detik per study setelah model termuat (pemuatan pertama ±8 detik). Citra tidak
  pernah keluar dari LAN; audit hanya mencatat `study_id`, model, dan durasi.

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
| **`/modalities*`** (status & daftar alat ke Orthanc) | ✅ | ❌ **403** | ❌ **403** |
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

---

## Referensi DICOM

- Modality Worklist (MWL): `1.2.840.10008.5.1.4.31`
- DX Image Storage: `1.2.840.10008.5.1.4.1.1.1.1`
- Studi server & quirk lain: `PACS-ORTHANC.md`

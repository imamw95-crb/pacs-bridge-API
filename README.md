# Bridge SIMRS ↔ Orthanc PACS

Bridge PHP tanpa framework untuk menghubungkan **SIMRS** dengan server **Orthanc PACS**
(Modality Worklist DICOM + retrieval study/gambar). Dipakai untuk modalitas **DX**, **US**, dan **ECG**.

Alur lengkap (diagram Mermaid, otomatis dirender GitHub): lihat [Alur program](#alur-program).

## Fitur

| Fitur | Endpoint utama |
|---|---|
| Kirim order pasien ke **worklist (MWL)** | `POST /worklists` |
| Nomor `AccessionNumber` otomatis (format alat) | `GET /accession` |
| Cari study (accession / No. RM / nama pasien) | `GET /studies`, `GET /studies/recent` |
| Gambar **JPEG/PNG** (opsional ber-watermark identitas) | `/studies/{acc}/jpg`, `/png` |
| **ZIP** DICOM & ZIP berisi JPG (folder = No. RM) | `/studies/{acc}/zip`, `/zip-jpg` |
| Dashboard web: worklist, study, viewer, pengaturan | `/` (`public/dashboard.html`) |
| Autentikasi dashboard (manager/operator) + **API key** untuk program | `X-API-Key` |

## Alur program

Ringkasnya: **SIMRS mengirim order → Orthanc menyimpannya sebagai worklist → alat menarik order (C-FIND MWL)
→ alat mengirim gambar (C-STORE) → SIMRS mengambil render/ZIP lewat bridge.**

### 1. Peta komponen & dua cara memanggil

```mermaid
flowchart TB
    subgraph SIMRS["SIMRS / aplikasi (PHP)"]
        REG["Pendaftaran dan EMR"]
        SDK["Pacs SDK — src/"]
        CLI["BridgeApiClient — X-API-Key"]
    end

    subgraph BRIDGE["Bridge API — public/index.php"]
        AUTH["Auth: sesi login / X-API-Key"]
        SVC["WorklistService · StudyService · Settings"]
    end

    subgraph PACS["Orthanc PACS — 10.4.0.10"]
        REST["REST :8042"]
        MWL["Modality Worklist"]
        IMG[("Study dan instance DICOM")]
    end

    MOD["Alat radiologi DX / US / ECG"]

    REG --> SDK
    REG --> CLI
    SDK -->|"REST langsung"| REST
    CLI -->|"HTTP + X-API-Key"| AUTH
    AUTH --> SVC
    SVC --> REST
    REST --- MWL
    REST --- IMG
    MWL <-->|"MWL C-FIND"| MOD
    MOD -->|"C-STORE gambar"| IMG
```

### 2. Alur kirim order (worklist MWL)

```mermaid
sequenceDiagram
    autonumber
    participant S as SIMRS
    participant B as Bridge API
    participant O as Orthanc
    participant M as Alat DX / US / ECG

    S->>B: POST /worklists (X-API-Key)
    B->>B: validasi + PatientName tanpa koma
    B->>O: buat worklist (Tags DICOM, tanggal = hari ini)
    O-->>B: id worklist
    B-->>S: 201 id + accession_number
    M->>O: C-FIND Modality Worklist (AET alat)
    O-->>M: data pasien + prosedur
    M->>O: C-STORE gambar + StudyInstanceUID yang sama
    O->>O: entry worklist dihapus otomatis
```

### 3. Alur ambil hasil (polling study → gambar)

```mermaid
sequenceDiagram
    autonumber
    participant S as SIMRS
    participant B as Bridge API
    participant O as Orthanc

    loop sampai study muncul
        S->>B: GET /studies?accession=...
        B->>O: cari study (ExtendedFind, urut terbaru)
        O-->>B: daftar study + modalitas
        B-->>S: JSON study
    end
    S->>B: GET /studies/{acc}/jpg?watermark=1
    B->>O: render JPEG (DICOMweb / instances)
    O-->>B: JPEG
    B->>B: watermark identitas (dilewati untuk US dan ECG)
    B-->>S: image/jpeg
    S->>B: GET /studies/{acc}/zip-jpg
    B->>O: render semua instance pada study
    B-->>S: application/zip (folder = No. RM)
```

### 4. Catatan alur

- Worklist bersifat **tarik** (alat C-FIND), bukan push dari server.
- Alat mengirim gambar via C-STORE; entry worklist dihapus Orthanc setelah study stabil diterima.
- Bridge **stateless** untuk gambar — render/arsip selalu diambil ulang dari Orthanc.
- Kunci `{acc}` fleksibel: AccessionNumber, ID Orthanc, atau StudyInstanceUID (berguna untuk study USG tanpa accession).
- Endpoint data butuh **sesi login** atau **`X-API-Key`**; lihat [docs/API-KEY-SIMRS.pdf](docs/API-KEY-SIMRS.pdf).

## Kebutuhan

- PHP **8.2+** dengan ekstensi `curl`, `json`, `mbstring` (opsional `gd` + FreeType untuk watermark)
- Tanpa Composer / tanpa framework
- Orthanc PACS yang dapat dijangkau (REST + DICOM MWL)
- Opsional: Docker (lihat `Dockerfile` & `deploy/`)

## Mulai cepat

```powershell
# 1) konfigurasi koneksi
notepad setting.txt        # IP/AET/port server Orthanc

# 2) jalankan API (router wajib: path ber-titik seperti UID DICOM)
php -S 0.0.0.0:8081 -t public public/router.php
#    buka http://127.0.0.1:8081/  (login awal: admin / admin)
```

Di Laragon: arahkan docroot ke folder `public/`.

Konfigurasi bisa dioverride lewat environment:
`PACS_SERVER_IP`, `PACS_HTTP_PORT`, `PACS_DICOM_PORT`, `PACS_CALLED_AET`, `PACS_CALLING_AET`,
`PACS_MODALITY`, `PACS_HTTP_USER`, `PACS_HTTP_PASS`, `PACS_BRIDGE_KEY`, `PACS_TIMEOUT`, `PACS_TIMEZONE`.

## Dokumentasi

| Dokumen | Isi |
|---|---|
| [`API.md`](API.md) | referensi REST API: autentikasi, endpoint, contoh respons, contoh konek |
| [`docs/API-KEY-SIMRS.pdf`](docs/API-KEY-SIMRS.pdf) | **panduan API key untuk tim SIMRS** (cetak): tabel endpoint ✅/❌, contoh CI3/CI4, error, checklist go-live |
| [`integrations/codeigniter/README.md`](integrations/codeigniter/README.md) | library CodeIgniter 3 & 4 |
| [`PACS-ORTHANC.md`](PACS-ORTHANC.md) | catatan teknis server PACS (temuan & quirk) |
| [`AGENTS.md`](AGENTS.md) | aturan kerja developer/agent |
| [`deploy/README.md`](deploy/README.md) | deploy container + cron tarikan study |

## Contoh singkat (program)

```bash
KEY="<API_KEY>"
curl -H "X-API-Key: $KEY" http://127.0.0.1:8081/health

curl -X POST http://127.0.0.1:8081/worklists \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"patient_name":"TARYA BIN WARDI","title":"Tn.","patient_id":"A048919",
       "accession_number":"A261105038","procedure":"THORAX PA","station_aet":"LEAD_CLIENT"}'

curl -H "X-API-Key: $KEY" "http://127.0.0.1:8081/studies/A261105038/jpg?quality=90" -o hasil.jpg
```

Semua endpoint **kecuali** `/login`, `/logout`, `/me`, dan halaman dashboard memerlukan
**sesi login** atau header **`X-API-Key`**. Endpoint konfigurasi/user hanya untuk manager.

## Uji

```powershell
php tools/selftest.php                    # end-to-end (membuat lalu menghapus entry uji)
php tools/test-ci-integration.php         # library CI3 + CI4
php tools/test-api-key.php http://127.0.0.1:8081 <API_KEY>
php tools/test-users.php   http://127.0.0.1:8081 admin admin
python dicom-probe.py 10.4.0.10 4242 ORTHANC LEAD_CLIENT   # C-ECHO / MWL / C-FIND
```

## Keamanan

- **Jangan commit kredensial.** `data/settings.json` (hash password + API key) dan
  `http-client.env.json` sudah dikecualikan di `.gitignore`.
- **Aktifkan API key** di dashboard (⚙ Pengaturan → panel *API Bridge*) sebelum bridge diekspos
  ke jaringan: tanpa key atau sesi, endpoint data menjawab `401`.
- Batasi akses ke LAN/VPN dan gunakan **HTTPS** bila melewati jaringan luar.
- Orthanc pada instalasi internal bisa berjalan tanpa autentikasi REST — batasi portnya di level jaringan.
- Gambar JPG/PNG hasil render adalah **preview** (lossy, 8-bit), bukan citra diagnostik.

## Struktur

```
public/        REST API (index.php), dashboard, router dev, aset
src/           SDK: Config, OrthancClient, WorklistService, StudyService, Watermark, ZipWriter, Auth, Settings
integrations/  adapter CodeIgniter 3 & 4
tools/         self-test, penarik study terjadwal, uji API key/user
docs/          dokumentasi API key (PDF + HTML)
deploy/        Dockerfile, skrip deploy, cron
setting.txt    konfigurasi client (IP/AET/port)
```

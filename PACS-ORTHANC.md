# Integrasi PACS / Orthanc — Catatan Teknis

Dokumen ini merangkum hasil audit, perbaikan, dan cara pakai server PACS (Orthanc)
yang dipakai proyek ini. Semua angka di sini **hasil uji nyata** pada 13 Nov 2026.

---

## 1. Ringkasan Lingkungan

| Item | Nilai |
|---|---|
| Server PACS | `10.4.0.10` (Linux Ubuntu 6.8, hostname `pacs`) |
| Perangkat lunak | **Orthanc 1.12.11** (DCMTK 3.7.0) di dalam **Docker** |
| Container | `orthanc` (`orthancteam/orthanc:latest`) |
| DICOM SCP | port **4242**, AET **`ORTHANC`** |
| REST / DICOMweb | port **8042** (HTTP) |
| Proxy web | container `orthanc-proxy` (nginx) → `:8088` |
| DICOM router | container `dicom-router-17apr2026` (**milik Kemenkes**) → port **11112**, AET `DCMROUTER`; **bukan** proxy MWL (C-FIND lewat sini = 0 hasil) |
| Portainer | `:8000`, `:9443` |
| Jumlah data | 16.131 study / 12.178 pasien / 24.945 series |
| Modality terdaftar | `DCMROUTER` (router Kemenkes), `SANTESRV1` (PACS sumber), **`LEAD_CLIENT`** (DX `192.168.10.110`), `VersanaB-000000` (USG), `CT140643`, `LEAD_PHILIP` |

Konfigurasi aktif di server (dipakai proses yang berjalan) ada di **`/tmp/orthanc.json`
di dalam container**, dan file itu **di-generate ulang setiap start** oleh
`/startup/generateConfiguration.py` dari sumber:

- `/etc/orthanc/orthanc.json` (bind-mount `→ /etc/orthanc`)
- `/etc/orthanc/worklists.json`
- default plugin di dalam image

> Mengedit `/tmp/orthanc.json` langsung **tidak permanen** — perubahan hilang saat restart.
> Edit selalu file di `/etc/orthanc/`.

---

## 2. Konfigurasi Client (`setting.txt`)

```
MODALITY: DX
SERVER NAME: LEAD_SERVER
CLIENT IP: 10.4.0.10            # placeholder 21 Nov 2026 (IP alat belum diketahui; dulu 192.168.200.230 = laptop)
SERVER IP: 10.4.0.10            # IP Orthanc
SERVER PORT: 4242               # port DICOM Orthanc
CALLED AE TITLE: ORTHANC        # AET server
CALLING AE TITLE: LEAD_CLIENT   # AET client
```

Aturan penting:

- `CLIENT IP` **tidak dipakai** untuk koneksi keluar (MWL/C-STORE hanya butuh IP+port server + AET).
  Nilainya baru penting kalau server harus menghubungi client (C-ECHO balik, **C-MOVE**, storage commitment).
- `CLIENT IP` = IP yang **didaftarkan di server** sebagai `Host` modality.
- Jangan isi `CLIENT IP` dengan IP server: sudah diuji → C-ECHO dari Orthanc ke "client" gagal (HTTP 500),
  karena `10.4.0.10:11112` milik container **dicom-router** (hanya menerima AET `DCMROUTER`; router ini **milik Kemenkes**, bukan proxy MWL).
- **Pengecualian 21 Nov 2026**: karena IP alat DX belum diketahui, `CLIENT IP` + `Host` modality
  `LEAD_CLIENT` diisi `10.4.0.10` sebagai *placeholder* (lihat §3.3). Akibatnya C-ECHO ke
  `LEAD_CLIENT` memang 500 — itu wajar, bukan indikator alat mati. **22 Nov 2026:** IP alat DX
  diketahui `192.168.10.110` (DICOM `104`) → `Host` perlu diubah ke nilai itu.

---

## 3. Perubahan yang Sudah Diterapkan di Server

### 3.1 Persisten (di `/etc/orthanc/`, bertahan restart & recreate container)

| File | Perubahan |
|---|---|
| `orthanc.json` | tambah modality `LEAD_CLIENT` (AET `LEAD_CLIENT`, Host `192.168.200.230`, Port `11112`, Allow* = true) — **dihapus lalu didaftarkan ulang 21 Nov 2026, lihat §3.3** |
| `orthanc.json` | tambah `"Worklists": { "Enable": true, "Database": "/var/lib/orthanc/worklists" }` |
| `orthanc.json` | tambah `DicomAlwaysAllowEcho/Find/Store: true` (untuk masa uji) |
| `worklists.json` | `"Enable": false` → `true` |

Backup: `orthanc.json.bak-20261113-093050`, `worklists.json.bak-20261113-093050`

### 3.2 Hanya di filesystem container (sebelum ada compose)

```bash
# matikan plugin MWL versi sample agar tidak bentrok dengan orthanc-worklists
docker exec orthanc mv /usr/share/orthanc/plugins/libModalityWorklists.so \
                         /usr/share/orthanc/plugins/libModalityWorklists.so.disabled

# folder database worklist
docker exec orthanc mkdir -p /var/lib/orthanc/worklists

# agar entrypoint bisa menulis config saat start (mencegah restart-loop)
docker exec orthanc chmod 666 /tmp/orthanc.json

docker restart orthanc
```

> **Wajib diulang** setiap kali container dibuat ulang / image di-update.
> Kalau tidak: plugin sample mengambil alih MWL → `/worklists` jadi 404 dan
> plugin `orthanc-worklists` gagal register (`Can only register one plugin to handle modality worklists`).

### 3.2a Dikelola `docker compose` (sejak 20 Nov 2026)

Container `orthanc` + `orthanc-proxy` sekarang dibuat dari compose, bukan `docker run`.
File di repo: [`deploy/orthanc/docker-compose.yml`](./deploy/orthanc/docker-compose.yml);
di server dipasang sebagai `/home/pacs/orthanc/docker-compose.yml`.

| Hal | Sebelum (`docker run`) | Sekarang (compose) |
|---|---|---|
| Plugin sample `libModalityWorklists.so` | di-`docker exec mv` manual, hilang saat recreate | di-rename otomatis oleh `entrypoint` |
| `/var/lib/orthanc/worklists` | di filesystem container, hilang saat recreate | volume `orthanc-worklists` |
| Image | `orthancteam/orthanc:latest` (bisa naik versi saat pull) | dipin ke digest `sha256:fdb2bd7c…` (1.12.11) |
| Health | tidak ada | `healthcheck` python3 → `GET /system` (`docker compose ps` = `healthy`) |

```bash
cd /home/pacs/orthanc
sudo docker compose ps          # orthanc harus "Up (healthy)"
sudo docker compose up -d       # terapkan perubahan compose (recreate, ±10 detik)
sudo docker logs --tail 50 orthanc
```

Data study tidak terpengaruh: volume `orthanc-storage` dipakai sebagai `external: true`,
jadi `docker compose down` **tidak** menghapusnya. Container lama dicatat di
`/home/pacs/orthanc/rollback-docker-run.txt` bila perlu kembali ke `docker run`.

#### Pengaman systemd (sejak 20 Nov 2026)

PACS pernah mati ~40 detik karena `docker compose down` dijalankan manual di
`/home/pacs/orthanc` (13:41:52, TTY `pts/3`). Supaya tidak terulang:

| Unit | Fungsi |
|---|---|
| `orthanc-compose.service` | `docker compose up -d` saat boot (`enabled`, `oneshot` + `RemainAfterExit`) |
| `orthanc-compose-watchdog.timer` | memicu `orthanc-compose-watchdog.service` **tiap 2 menit** |
| `orthanc-compose-watchdog.service` | `docker compose up -d --remove-orphans` — idempotent, membuat ulang container bila terhapus |

```bash
systemctl status orthanc-compose.service
systemctl list-timers 'orthanc*'          # NEXT harus ≤ 2 menit
systemctl start orthanc-compose-watchdog.service   # pulihkan manual tanpa menunggu timer
```

Sudah diuji: `docker compose down` → container hilang & `/system` mati →
watchdog dipicu → `orthanc (healthy)` + `/system` 200 dalam ±20 detik.

> **Jangan** `docker compose down -v` — opsi `-v` menghapus volume `orthanc-worklists`
> (DB worklist). Volume study `orthanc-storage` aman karena `external: true`.
> Untuk maintenance yang memang ingin mematikan Orthanc:
> `sudo systemctl stop orthanc-compose-watchdog.timer` dulu.

### 3.2b Compose di server pernah **tidak valid** (diperbaiki 21 Nov 2026)

Perubahan 20 Nov (env SIMRS + `PYTHON_PLUGIN_ENABLED`) **tidak pernah aktif**: file
`/home/pacs/orthanc/docker-compose.yml` mencampur list-of-map dan string sehingga
`docker compose up -d` gagal:

```
services.orthanc.environment.[0]: unexpected type map[string]interface {}
```

Perbaikan: `environment` jadi mapping, kredensial SIMRS dipindah ke
`/home/pacs/orthanc/.env` (`chmod 600`, tidak masuk repo):

```bash
SIMRS_AUTH_USER=...
SIMRS_AUTH_PASS=...
```

Setelah `sudo docker compose up -d` (21 Nov 10:16 WIB): container recreate,
`healthy` dalam ±9 detik, `/system` 200, 6 modality utuh. Backup:
`orthanc.json.bak-20261121-101543`, `docker-compose.yml.bak-20261121-101614`.

### 3.2c Worklist SIMRS (Python) — **belum diaktifkan**

`/etc/orthanc/orthanc.json` sempat memuat `"PythonScript": "/etc/orthanc/worklist_dx.py"`
yang akan menarik MWL langsung dari SIMRS (`radiologi/api_worklist_dx/mwl_today`,
filter per Calling AET). Dua masalah:

| Masalah | Bukti |
|---|---|
| Path script salah | `/etc/orthanc/worklist_dx.py` **tidak ada**; file asli `/etc/orthanc/scripts/worklist_dx.py` |
| Endpoint SIMRS belum ada | `GET …/radiologi/api_worklist_dx/mwl_today?aetitle=LEAD_CLIENT&today=1` → **HTTP 404** (CodeIgniter) |

Karena callback Python **menimpa** sumber worklist, mengaktifkannya sekarang membuat alat
DX dapat **0 order**. Jadi `PythonScript` dicabut dari config; MWL DX tetap dilayani
plugin `orthanc-worklists` (DB `/var/lib/orthanc/worklists` → `POST /worklists` bridge).
Env `SIMRS_*` sudah terpasang di container, siap dipakai begitu dua masalah di atas selesai.

> Catatan: `"DicomModalitiesInDatabase": true` → modality dari `/etc/orthanc/orthanc.json`
> di-seed ke DB tiap start, jadi `DELETE /modalities/{aet}` via REST **kembali** setelah
> restart. Untuk menghapus permanen, hapus dari file config lalu recreate.

### 3.3 Modality `LEAD_CLIENT` — permanen di config (21 Nov 2026)

Awalnya terdaftar dengan Host `192.168.200.230:11112`:

```json
"LEAD_CLIENT": {
  "AET": "LEAD_CLIENT", "Host": "192.168.200.230", "Port": 11112,
  "AllowEcho": true, "AllowFind": true, "AllowFindWorklist": true,
  "AllowGet": true, "AllowMove": true, "AllowNAction": true,
  "AllowStore": true, "AllowTranscoding": true
}
```

`192.168.200.230` ternyata **laptop kerja**, bukan alat (host hidup, port 445 terbuka,
tetapi **11112 tertutup** → C-ECHO dari Orthanc selalu `TCP Initialization Error`),
jadi entry itu dihapus:

```bash
curl -X DELETE http://10.4.0.10:8042/modalities/LEAD_CLIENT   # HTTP 200
```

`DELETE` dijawab **200** (bukan 400 "defined in the configuration file") karena entry
itu memang hanya ada di DB pada saat itu, sedangkan `/etc/orthanc/orthanc.json` yang
memuat `LEAD_CLIENT` (mtime 20 Nov 14:29) **belum pernah diterapkan** ke container yang
berjalan (start 20 Nov 13:15) — lihat §3.2b.

#### ⚠️ Efek samping: MWL jadi kosong untuk alat DX

Sesudah dihapus, alat DX yang menarik worklist **tetap dapat `0x0000` tapi 0 order**.
Penyebabnya: plugin `orthanc-worklists` hanya melayani **calling AE Title yang terdaftar
di `DicomModalities`**. Hasil uji 21 Nov 2026 (1 order DX `SCHEDULED` di DB):

| Calling AET | Hasil C-FIND MWL |
|---|---|
| `LEAD_CLIENT` (sudah dihapus) | **0** |
| `UNKNOWN_AE` | **0** |
| `VersanaB-000000`, `CT140643`, `DCMROUTER` (terdaftar) | **1** |
| `TESTAET` (didaftarkan sementara) | **1** |

Jadi **menghapus modality = mematikan MWL untuk AET itu**. Order tetap terlihat di
dashboard (`GET /worklists`) karena itu REST, bukan DICOM.

#### Keadaan sekarang

Permanen di `/etc/orthanc/orthanc.json` (bukan hanya DB), Host bukan laptop:

```json
"LEAD_CLIENT": { "AET": "LEAD_CLIENT", "Host": "10.4.0.10", "Port": 11112, ... }
```

Sudah diverifikasi setelah `docker compose up -d` (21 Nov 10:16 WIB):

- `GET /modalities/LEAD_CLIENT/configuration` → Host `10.4.0.10`, Port `11112`.
- `POST /worklists` (DX, station `LEAD_CLIENT`) → 201; C-FIND MWL sebagai
  `LEAD_CLIENT` → `AC` + order muncul; order uji lalu dihapus.
- Host/Port hanya dipakai untuk koneksi **keluar** (C-ECHO balik / C-MOVE) — MWL tidak
  terpengaruh. Ganti nilainya begitu IP alat DX sebenarnya diketahui.
- **Hati-hati**: menyimpan Pengaturan / klik **Daftarkan ke Orthanc** dari dashboard
  menulis ulang `Host` modality sesuai isi kolom "IP / host alat". Pada 21 Nov 10:18
  simpanan lama (masih `192.168.200.230`) sempat menimpa Orthanc; sudah dikembalikan ke
  `10.4.0.10` dan `data/settings.json` produksi ikut diperbaiki.
- `POST /modalities/LEAD_CLIENT/echo` sekarang **500** (`DUL Association Rejected`,
  dicom-router hanya menerima AET `DCMROUTER`). Itu wajar untuk placeholder ini —
  jangan dipakai sebagai penilaian "alat terjangkau".
- MWL lewat dicom-router (`10.4.0.10:11112`) tetap **0 hasil** untuk semua calling AET
  → router itu **milik Kemenkes** dan bukan proxy MWL ke Orthanc; alat harus tarik MWL ke `ORTHANC:4242`.
- **22 Nov 2026**: IP alat DX diketahui **`192.168.10.110`** (DICOM `104`). `Host`/`Port`
  modality `LEAD_CLIENT` di Orthanc masih placeholder `10.4.0.10:11112` → ubah via Pengaturan
  (isi IP alat) lalu **Daftarkan ke Orthanc** supaya C-ECHO/C-MOVE ke alat berfungsi.
  MWL tidak terpengaruh selama calling AET tetap `LEAD_CLIENT` dan terdaftar.

---

## 4. Alur Kerja (flow)

```
[HIS/RIS atau input manual]
        |
        v
  Orthanc 10.4.0.10:4242  (AET ORTHANC)
  + plugin Worklists (folder /var/lib/orthanc/worklists)
        |  (1) C-FIND MWL  (server kirim data pasien)
        v
  Modalitas DX (AET LEAD_CLIENT)
        |  (2) operator pilih order & ekspos
        v
  (3) C-STORE gambar + data pasien  ->  Orthanc 4242
        |
        v
  (4) worklist order otomatis terhapus (DeleteWorklistsOnStableStudy default true)
        |
        v
  (5) Aplikasi/viewer ambil gambar: C-MOVE ke alat, atau REST/WADO (JPG/PNG/DICOM)
```

### Service class yang dipakai

| Fase | Arah | SOP Class |
|---|---|---|
| Verifikasi koneksi | client → server | `1.2.840.10008.1.1` (C-ECHO) |
| Ambil data pasien | client → server | `1.2.840.10008.5.1.4.31` (MWL C-FIND) |
| Kirim gambar | client → server | DX Image Storage `1.2.840.10008.5.1.4.1.1.1.1` |
| Ambil gambar (push) | server → client | C-MOVE, tujuan AET `LEAD_CLIENT` — Host kini placeholder `10.4.0.10:11112` dan router menolak AET `LEAD_CLIENT`, jadi **belum tersedia** (§3.3) |
| Cari study | client → server | Study Root C-FIND `1.2.840.10008.5.1.4.1.2.2.1` |

---

## 5. Setting di Alat DX

| Field | Nilai |
|---|---|
| Local / Calling AE Title | `LEAD_CLIENT` |
| Remote / Called AE Title | `ORTHANC` |
| PACS IP | `10.4.0.10` |
| PACS Port | `4242` |
| Local port (untuk C-MOVE/C-GET inbound) | `11112` |
| Transfer Syntax | Explicit VR LE (`1.2.840.10008.1.2.1`) |
| MWL | enabled, `Modality=DX`, `ScheduledStationAETitle=LEAD_CLIENT`, tanggal hari ini |
| Retrieval method | **C-MOVE** (jangan C-GET) |

---

## 6. Menambah Order ke Worklist (REST)

Endpoint: `POST http://10.4.0.10:8042/worklists/create`
Body **wajib** memakai field `"Tags"` (format `"Content"` lama tidak dipakai).

```powershell
$body = @{ Tags = @{
  '0008,0050' = 'A261105038'                                  # AccessionNumber
  '0010,0010' = 'TARYA BIN WARDI TN'                          # PatientName
  '0010,0020' = 'A048919'                                     # PatientID
  '0032,1060' = 'THORAX PA'                                   # RequestedProcedureDescription
  '0040,0100' = @(@{
      '0008,0060' = 'DX'                                      # Modality
      '0040,0001' = 'LEAD_CLIENT'                             # ScheduledStationAETitle
      '0040,0002' = '20261113'                                # StartDate (hari ini!)
      '0040,0003' = '102200'                                  # StartTime
      '0040,0007' = 'THORAX PA'                               # Description
      '0040,0009' = 'SPS-A261105038'                          # SPS ID
  })
}} | ConvertTo-Json -Depth 6 -Compress

Invoke-RestMethod -Method Post -Uri http://10.4.0.10:8042/worklists/create `
  -Body $body -ContentType 'application/json'
```

Operasi lain:

```powershell
Invoke-RestMethod http://10.4.0.10:8042/worklists                        # daftar
Invoke-RestMethod http://10.4.0.10:8042/worklists/{id}                   # detail
Invoke-RestMethod -Method Put    http://10.4.0.10:8042/worklists/{id} -Body $body -ContentType 'application/json'
Invoke-RestMethod -Method Delete http://10.4.0.10:8042/worklists/{id}
```

Aturan pencocokan (sudah diuji):

- `ScheduledProcedureStepStartDate` **harus tanggal hari ini** — kalau tidak, query alat = 0 hasil.
- `Modality` harus cocok (`DX`).
- `ScheduledStationAETitle` = AET alat (`LEAD_CLIENT`) atau kosong (cocok semua).

---

## 7. Quirk & Pelajaran Penting (hasil troubleshooting)

| # | Temuan | Dampak | Solusi |
|---|---|---|---|
| 1 | Orthanc jalan di Docker; config aktif = `/tmp/orthanc.json` (generated) | Edit `/etc/orthanc/orthanc.json` saja tidak langsung berlaku; edit `/tmp` hilang saat restart | Edit `/etc/orthanc/*.json` + restart container |
| 2 | Dua plugin MWL terpasang (`worklists` sample + `orthanc-worklists`) | REST `/worklists` 404, MWL tidak sinkron | Nonaktifkan plugin sample (rename `.so`) |
| 3 | Folder `/var/lib/orthanc/worklists` belum ada | `Cannot write to file` saat create worklist | `mkdir` di container |
| 4 | Query MWL yang menyertakan **`QueryRetrieveLevel`** | Orthanc balas **0 hasil** meski data ada | Jangan kirim `QueryRetrieveLevel` di query MWL (bisa dipaksa via Lua `IncomingWorklistRequestFilter`) |
| 5 | Nilai universal match: string kosong `''` | Plugin butuh wildcard `*` untuk beberapa tag | Pakai `*` (mis. `AccessionNumber='*'`) |
| 6 | `PatientName` memakai **koma** (`NAMA, NY`) | Alat memotong nama setelah koma → gelar hilang | Hilangkan koma: `NAMA NY`; alternatif suffix `NAMA^^^^NY` |
| 7 | `docker cp` config ke container → file milik root | Entrypoint gagal menulis `/tmp/orthanc.json` → **restart-loop** | `docker exec orthanc chmod 666 /tmp/orthanc.json` |
| 8 | Orthanc sebagai SCP menolak **C-GET** | Status `0xA702` (refused) | Pakai **C-MOVE** atau HTTP/WADO |
| 9 | Parameter `size=`/`viewport=`/`window-center=` di WADO rendered | Diabaikan (gambar tetap full size) | Resize pakai native `/rendered?width=` (PNG), atau resize di aplikasi |
| 10 | AET salah / client belum terdaftar | A-ASSOCIATE-RJ `reason=0x03` (calling AE not recognized) | Daftarkan AET client di `DicomModalities` |
| 11 | Study dengan **SOPInstanceUID duplikat** → DICOMweb menolak `Multiple Instance found for WADO-RS` (HTTP 404) | `.../dicom-web/.../rendered` gagal walau study ada | Pakai render native `/instances/{orthancId}/rendered` (PNG); `StudyService::imageByAccession()` sudah otomatis fallback |
| 12 | `POST /modalities/{id}/get` (C-GET, Orthanc ≥ 1.12.6) menolak body `{"Level","Query"}` → **HTTP 400 tanpa pesan** | Tarik study dari PACS lain gagal | Kirim `{"Level":"Study","Resources":[{"StudyInstanceUID":"…"}],"Synchronous":false}` → dapat ID job |
| 13 | Path REST **tidak** menerima DICOM UID di Orthanc 1.12.11: `GET /studies/1.2.840…` → **404** (hanya ID Orthanc yang diterima) | Link/endpoint berbasis StudyInstanceUID gagal | Petakan dulu lewat `POST /tools/lookup` (body = UID) → ambil `ID`, lihat `OrthancClient::lookup()` & `StudyService::findOneByKey()` |
| 14 | `Modality` (0008,0060) tidak ada di `MainDicomTags` level **Study** (hanya level Series) | Semua study tampil sebagai `DX` | Ambil dari `POST /tools/find` level `Series` (`StudyDate` → peta `ParentStudy` ⇒ `Modality`) |

Catatan kode reject DICOM (PS3.8 tabel 9-21):

- `reason 0x03` = **calling** AE title not recognized
- `reason 0x07` = **called** AE title not recognized

---

## 8. Mengambil Gambar

### 8.1 Hasil uji render (study DX 2568×2449)

| Endpoint | Format | Ukuran | Dimensi |
|---|---|---|---|
| `/instances/{id}/rendered` | PNG | 3,13 MB | 2568×2449 |
| `/instances/{id}/rendered?width=1024` | PNG | 599 KB | 1024×977 |
| `/instances/{id}/rendered?width=512` | PNG | 159 KB | 512×488 |
| `/instances/{id}/rendered?height=512` | PNG | 175 KB | 537×512 |
| `/instances/{id}/preview` | PNG | 3,12 MB | 2568×2449 |
| `/dicom-web/studies/{st}/series/{se}/instances/{in}/rendered` | JPEG | 1,33 MB | 2568×2449 |
| `...rendered?quality=70` | JPEG | 692 KB | 2568×2449 |
| `...rendered?quality=80&width=800` | JPEG | 877 KB | 2568×2449 (width diabaikan) |

### 8.2 Alur ambil JPG setelah pemeriksaan

```powershell
$base = "http://10.4.0.10:8042"

$body = @{ Level='Study'; Query=@{ AccessionNumber='A261105038' } } | ConvertTo-Json -Compress
$studyId = (Invoke-RestMethod -Method Post -Uri "$base/tools/find" -Body $body -ContentType 'application/json')[0]

$study = Invoke-RestMethod "$base/studies/$studyId"
$ser   = Invoke-RestMethod "$base/series/" + $study.Series[0]
$inst  = Invoke-RestMethod "$base/instances/" + $ser.Instances[0]

$url = "$base/dicom-web/studies/$($study.MainDicomTags.StudyInstanceUID)/series/$($ser.MainDicomTags.SeriesInstanceUID)/instances/$($inst.MainDicomTags.SOPInstanceUID)/rendered?quality=90"
Invoke-WebRequest $url -OutFile "C:\temp\hasil.jpg"
```

### 8.3 Endpoint lain

| Kebutuhan | Endpoint |
|---|---|
| DICOM asli 1 gambar | `GET /instances/{id}/file` |
| ZIP 1 study (DICOM) | `GET /studies/{id}/archive` |
| Cari study | `GET /tools/find` (POST) atau QIDO `GET /dicom-web/studies?PatientID=...` |
| Viewer interaktif | `GET /stone-webviewer/index.html?study=<StudyInstanceUID>` |
| Query DICOM (C-FIND) | Study Root C-FIND ke `4242` |

> **JPG/PNG = non-diagnostik** (8-bit, lossy, windowing sudah diterapkan).
> Untuk bacaan dokter/cetak tetap gunakan DICOM asli atau viewer.

### 8.4 C-MOVE (retrieval DICOM ke alat)

- Sudah diuji (13 Nov 2026, saat modality masih menunjuk laptop): Orthanc menghubungi `192.168.200.230:11112` (AET `LEAD_CLIENT`) dan mengirim 1 gambar DX 12,5 MB — status `0x0000`. Setelah didaftarkan ulang 21 Nov 2026 (§3.3) C-MOVE ke `LEAD_CLIENT` belum bisa: Host placeholder `10.4.0.10:11112` dan router menolak AET itu.
- Syarat: alat **listen** di port `11112` dan port itu terbuka di firewall; modality terdaftar dengan `AllowMove/AllowStore`.
- **C-GET ditolak** Orthanc (`0xA702`) → jangan dipakai.

---

## 9. Tool Uji

`dicom-probe.py` (butuh `pip install pynetdicom pydicom`):

```powershell
python dicom-probe.py 10.4.0.10 4242 ORTHANC LEAD_CLIENT
```

Menjalankan 3 uji: C-ECHO, MWL C-FIND (per Modality), Study Root C-FIND.
Contoh keluaran sehat:

```
[1] C-ECHO   -> ACCEPTED, 0x0000 Success
[2] MWL DX   -> ACCEPTED, 1 result(s)
    [01] PatientName=AMANAH BINTI KASDIM NY | PatientID=A027308 | Accession=A261105018
[3] Study Root -> ACCEPTED, 16131 result(s)
```

---

## 10. Checklist Troubleshooting

1. `Test-NetConnection 10.4.0.10 -Port 4242` → harus `TcpTestSucceeded: True`
2. C-ECHO gagal `reason=0x03` → AET client belum terdaftar (`DicomModalities`)
3. C-ECHO gagal `reason=0x07` → AET server salah (`ORTHANC`)
4. C-ECHO OK tapi MWL kosong → cek query alat (jangan kirim `QueryRetrieveLevel`), tanggal order, Modality, Station AET
5. `GET /worklists` 404 → plugin `orthanc-worklists` belum aktif (konflik plugin sample)
6. Create worklist `Cannot write to file` → folder `/var/lib/orthanc/worklists` belum ada
7. Container restart-loop `PermissionError: /tmp/orthanc.json` → `chmod 666` file itu
8. C-MOVE gagal → client tidak listen di 11112 / firewall

Diagnosa server (SSH sebagai user `pacs`, sudo):

```bash
docker ps --format "{{.Names}} | {{.Status}} | {{.Ports}}"
docker logs --tail 50 orthanc
docker exec orthanc ls -la /var/lib/orthanc/worklists
docker exec orthanc ls /usr/share/orthanc/plugins | grep -i worklist
curl -s http://localhost:8042/system | head -40
curl -s http://localhost:8042/worklists
```

---

## 11. Catatan Keamanan

- Orthanc: `RemoteAccessAllowed = true` dan `AuthenticationEnabled = false`
  → REST API (termasuk buat/hapus worklist dan **hapus study**) terbuka bagi siapa pun
  yang bisa menjangkau `10.4.0.10:8042`. Disarankan segera mengaktifkan autentikasi
  atau membatasi akses via firewall/VPN.
- Kredensial server **tidak** disimpan di repo ini.

---

## 12. Riwayat Uji (13 Nov 2026)

| Uji | Hasil |
|---|---|
| C-ECHO `LEAD_CLIENT → ORTHANC` | ✅ 0x0000 |
| MWL C-FIND (order uji) | ✅ data pasien diterima |
| C-STORE gambar ke Orthanc | ✅ 0x0000, study masuk, lalu dihapus kembali |
| C-MOVE ke client `:11112` | ✅ 0x0000, 1 gambar 12,5 MB diterima |
| C-GET | ❌ 0xA702 (tidak didukung) |
| Study Root C-FIND | ✅ 16.131 study |
| Render PNG/JPG | ✅ (lihat tabel §8.1) |

Data uji (`TEST-STORE-001`, order worklist uji) sudah dihapus; statistik PACS tetap
**16.131 study / 12.178 pasien**.

---

## 13. Bridge SIMRS (PHP)

Aplikasi SIMRS dihubungkan ke Orthanc lewat service PHP tanpa dependency:

| File | Fungsi |
|---|---|
| `src/Config.php` | baca `setting.txt` + override env |
| `src/OrthancClient.php` | HTTP client REST Orthanc |
| `src/WorklistService.php` | CRUD worklist |
| `src/StudyService.php` | cari study, render PNG/JPEG, DICOM/ZIP |
| `src/Bridge.php` | facade `Bridge::fromSettingFile()` |
| `public/index.php` | REST API (`/health`, `/worklists`, `/studies/{acc}/jpg`, …) |
| `tools/selftest.php` | self-test end-to-end |

Panduan pemakaian & aturan kerja agent: **[AGENTS.md](./AGENTS.md)**.

Verifikasi terakhir (`php tools/selftest.php`): **8 pass, 0 fail** — CRUD worklist uji
dibuat lalu dihapus, pencarian study + render PNG berhasil, urutan
`/studies/recent` terverifikasi menurun (tanggal + jam study), dan nama file
ZIP (JPG) terbukti unik pada study multi-series.

Endpoint REST yang sudah diuji: `/health`, `/worklists` (GET/POST/PUT/DELETE),
`/studies?accession=`, `/studies?patient_id=`, `/studies/recent`, `/studies/{acc}/png`,
`/studies/{acc}/jpg` (+ watermark), `/studies/{acc}/zip`, `/studies/{acc}/zip-jpg`,
`/studies/{acc}/images`, `/instances/{id}/jpg`, `/instances/{id}/file`, `/accession`.

### Dashboard web

Tersedia dashboard siap pakai: **`public/dashboard.html`** — otomatis disajikan di `/` dan `/dashboard`
oleh `public/index.php`. Isi: **login admin** (default `admin`/`admin`), statistik PACS, manajemen
worklist (kirim/hapus order), pencarian study dengan **filter default "hari ini"**, grid thumbnail
(PNG 320px), tombol Lihat (JPEG), Viewer (Stone WebViewer), ZIP DICOM, serta **modal Pengaturan**
(IP/port/AE Title/modality/filter default/API key dan ganti password admin).

Pengaturan disimpan di `data/settings.json` (menimpa `setting.txt`); semua endpoint data memerlukan
sesi login atau header `X-API-Key`.

Uji terakhir: login `admin/admin` → dashboard memuat 17 study hari ini (0 gambar rusak),
form kirim order + hapus order berjalan, simpan pengaturan berhasil, ganti password
(`admin` → `admin123` → `admin`) berhasil dan password lama ditolak.

---

## 14. Penarikan Otomatis dari SANTESRV1 (cron, near-realtime 1 menit)

### 14.1 Yang berjalan di server (observasi 13 Nov 2026)

Selain C-STORE dari alat DX (yang sudah realtime), ada **penarikan DICOM terjadwal** dari
PACS lain, `SANTESRV1`, ke Orthanc:

| Waktu (UTC) | Job | Remote AET | Hasil |
|---|---|---|---|
| 04:30 – 08:30, tiap `:00` dan `:30` | `DicomGetScu` | `SANTESRV1` | `Success`, `ErrorCode 0`, 11–17 detik |

Ciri-ciri job (dari `GET /jobs?expand`):

| Field | Nilai | Arti |
|---|---|---|
| `Type` | `DicomGetScu` | Orthanc jadi **SCU** dan menarik lewat **C-GET** (bukan C-MOVE) |
| `Content.Description` | `REST API` | Job dibuat oleh **pemanggil REST**, bukan scheduler internal Orthanc |
| `Content.RemoteAet` | `SANTESRV1` | Sumber gambar = PACS Sante |
| `Content.Details` | 36 → 49 study per run | Jumlah study **kumulatif hari itu** (naik terus) |
| `Content.Details[].Query` | `0008,0050`, `0010,0020`, `0020,000d`, `0008,0052` | C-FIND dulu (dapat accession/patient/UID), lalu C-GET per study |

Kesimpulan: pemicunya **cron/script di server** (bukan fitur bawaan Orthanc, bukan kode di
repo PHP ini). Script aslinya tidak tersimpan di repo, jadi versi yang bisa direproduksi
dan dipasang ulang ada di [`tools/pull-santesrv1.php`](./tools/pull-santesrv1.php).

Alur yang diamati (sama dengan script referensi):

```
1. C-FIND Study Root ke SANTESRV1 (StudyDate = hari ini)   -> daftar study
2. untuk tiap study: C-GET SANTESRV1 -> Orthanc (per StudyInstanceUID)
3. study/instance yang sudah ada tidak berubah
   (OverwriteInstances = false -> instance kembar dibuang, bukan digandakan)
```

> **Catatan**: cron lama menarik **semua** study hari itu setiap 30 menit (36–49 study
> per job, padahal yang baru hanya 1–5). Tidak berbahaya karena Orthanc membuang instance
> kembar, tetapi boros: tiap run membuka 36–49 asosiasi DICOM ke SANTESRV1.
> Penggantinya (§14.3) menarik **tiap 1 menit secara inkremental** dan hanya membuka
> 1 asosiasi C-FIND per run.

### 14.2 Script referensi `tools/pull-santesrv1.php`

Sama seperti cron di atas, tetapi **inkremental**: study yang sudah ada di Orthanc dilewati
sebelum C-GET, jadi 1 run biasanya hanya menarik 0–2 study.

```powershell
php tools/pull-santesrv1.php                  # study hari ini
php tools/pull-santesrv1.php --date=yesterday  # susulan study yang lewat tengah malam
php tools/pull-santesrv1.php --dry-run         # hanya daftar "belum ditarik"
php tools/pull-santesrv1.php --limit=10        # batasi jumlah study per run
```

| Opsi | Default | Keterangan |
|---|---|---|
| `--date=` | `today` | `today` \| `yesterday` \| `YYYYMMDD` |
| `--source=` | `SANTESRV1` (atau env `PACS_PULL_SOURCE`) | modality sumber di Orthanc |
| `--limit=` | `0` (semua) | batas study baru per run |
| `--timeout=` | `120` | detik menunggu tiap job C-GET selesai |
| `--quiet` | mati | hanya menulis log bila ada study baru / gagal (dipakai cron tiap menit) |
| `--dry-run` | mati | hanya melaporkan, tidak menarik |

Perilaku:

- memakai `setting.txt`/env `PACS_*` (lewat `Bridge::fromSettingFile()`), jadi tidak ada IP yang di-hardcode;
- **lock file** `data/pull-santesrv1.lock` → run kedua yang tumpang-tindih langsung berhenti (`lewati: … lock aktif`);
- log satu baris per kejadian + baris `ringkasan:` (cocok untuk cron/logrotate);
- exit code `0` = semua sukses, `1` = ada study gagal;
- gagal satu study tidak menghentikan study lain.

Bukti uji (13 Nov 2026, 15:33–15:35 WIB):

```
SANTESRV1 melaporkan 50 study pada 20261113
ditarik: acc=1311202615151798 pid=A050928 instance=1
ringkasan: tanggal=20261113 sumber=SANTESRV1 baru=1 dilewati=49 gagal=0 instance=1
run berikutnya -> baru=0 dilewati=50 gagal=0        (idempoten, tidak ada duplikat)
```

Total study Orthanc naik 16.168 → 16.169 (hanya study baru yang ditambahkan).

Uji jadwal 1 menit lewat Windows Task Scheduler (`13 Nov 15:49:56`):

```
ditarik: acc=1311202615104475 pid=A047715 instance=1
ringkasan: tanggal=20261113 sumber=SANTESRV1 baru=1 dilewati=50 gagal=0 instance=1 durasi=3.9s
```

Study itu baru muncul di Sante sekitar pukul 15:49 dan **sudah masuk Orthanc pada menit
yang sama** (jadwal 30 menit sebelumnya baru akan menariknya pukul 16:00). Total study
16.169 → 16.170.

### 14.3 Memasang sebagai cron (tiap menit, near-realtime)

Berkas siap pakai: [`deploy/cron-pacs-pull`](./deploy/cron-pacs-pull) (jadwal **`* * * * *`**).

```bash
# di server 10.4.0.10
sudo cp /opt/pacs-bridge/deploy/cron-pacs-pull /etc/cron.d/pacs-pull
sudo chown root:root /etc/cron.d/pacs-pull && sudo chmod 644 /etc/cron.d/pacs-pull

# WAJIB: matikan cron lama 30 menit supaya tidak menarik dobel
crontab -l | grep -i pull
sudo grep -rl "pull\|SANTESRV" /etc/cron.d /etc/crontab /etc/cron.daily 2>/dev/null
```

Isi `deploy/cron-pacs-pull`:

```bash
# tiap menit: study baru hari ini (--quiet: hanya log kalau ada study masuk / gagal)
* * * * * root /usr/bin/docker exec -u www-data pacs-bridge /usr/local/bin/php /var/www/html/tools/pull-santesrv1.php --date=today --quiet >> /var/log/pacs-pull.log 2>&1

# tiap jam menit ke-5: susulan study yang selesai lewat tengah malam
5 * * * * root /usr/bin/docker exec -u www-data pacs-bridge /usr/local/bin/php /var/www/html/tools/pull-santesrv1.php --date=yesterday --quiet >> /var/log/pacs-pull.log 2>&1
```

Memakai **PHP di dalam container** (`docker exec`) supaya tidak perlu memasang `php-cli` di host.
Kalau host punya PHP, ganti perintahnya jadi
`pacs /usr/bin/php /opt/pacs-bridge/tools/pull-santesrv1.php --date=today --quiet`.

Beban per run (diukur): **~2,5 detik**, 1 asosiasi C-FIND ke SANTESRV1 + pemeriksaan 50
StudyInstanceUID lokal. Aman untuk interval 1 menit; lock file mencegah run bertumpuk
kalau sesekali butuh lebih lama (mis. menarik study besar).

Log rotasi (`/etc/logrotate.d/pacs-pull`):

```
/var/log/pacs-pull.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
}
```

Cek hasil: `tail -f /var/log/pacs-pull.log`, atau `GET http://10.4.0.10:8042/jobs?expand`.

Config alternatif untuk Windows (Task Scheduler) — **dipakai sementara** selama cron server
belum terpasang:

```powershell
$action   = New-ScheduledTaskAction -Execute 'wscript.exe' `
              -Argument '"C:\laragon\www\PACSPROJEC\tools\pull-santesrv1-hidden.vbs"' `
              -WorkingDirectory 'C:\laragon\www\PACSPROJEC'
$trigger  = New-ScheduledTaskTrigger -Once -At ((Get-Date).AddSeconds(30)) `
              -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
              -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -StartWhenAvailable

Register-ScheduledTask -TaskName 'PACS Pull SANTESRV1' -Action $action -Trigger $trigger `
  -Settings $settings -Description 'Tarik study SANTESRV1 -> Orthanc tiap 1 menit' -Force
```

- [`tools/pull-santesrv1-hidden.vbs`](./tools/pull-santesrv1-hidden.vbs) menjalankan
  [`tools/pull-santesrv1.cmd`](./tools/pull-santesrv1.cmd) **tanpa jendela console**
  dan meneruskan exit code ke Task Scheduler (`LastTaskResult` = `0` berarti sukses).
- Log: `data/pull-windows.log` (hanya terisi bila ada study baru / gagal).
- Task hanya jalan saat user Windows **login** — mati kalau PC shutdown/logoff.
  Karena itu cron server tetap menjadi solusi permanen.

Cek status:

```powershell
Get-ScheduledTaskInfo -TaskName 'PACS Pull SANTESRV1' | Format-List LastRunTime, LastTaskResult, NextRunTime
Get-Content C:\laragon\www\PACSPROJEC\data\pull-windows.log -Tail 20
```

### 14.4 Bisakah realtime?

**Bisa.** Tapi perlu dipisahkan dulu: gambar dari **alat DX ke Orthanc sudah realtime**
(modality yang C-STORE, begitu ekspos selesai gambar langsung masuk). Cron di atas hanya
menutupi jalur **antar-PACS** (SANTESRV1 → Orthanc).

**Keputusan yang dipakai sekarang: opsi D — cron tiap 1 menit** (`deploy/cron-pacs-pull`),
latensi ± 1 menit, tanpa mengubah server Orthanc maupun server Sante.

| Opsi | Latensi | Perubahan yang perlu | Catatan |
|---|---|---|---|
| **A. SANTESRV1 push (auto-route / C-STORE)** | ~detik | config di server Sante | **paling ideal** — Orthanc tidak poll, tidak ada asosiasi berulang. Ganti cron dengan ini kalau vendor Sante mendukung auto-send/auto-route |
| **B. Lua `OnStableStudy` → HTTP ke Bridge** | ~detik | `LuaScripts` di `/etc/orthanc/orthanc.json` + endpoint webhook baru di `public/index.php` | event-driven murni; perlu restart Orthanc dan tetap butuh sumber data (Lua hanya memberi tahu study baru yang **sudah ada** di Orthanc) |
| **C. Poller `/changes?last=N&timeout=10`** | ≤ 10 detik | service/cron `* * * * *` di bridge | tanpa ubah Orthanc; `GET /changes`+ long-poll, reaksi pada `NewStudy`/`StableStudy` |
| **D. Cron tiap 1–2 menit (dipakai)** | 60–120 detik | ubah jadwal cron saja | **paling murah**; pakai script inkremental + `--quiet` supaya beban C-FIND ke Sante tetap kecil |
| **E. Dashboard auto-refresh 30 detik (sudah ada)** | 30 detik | — | hanya menyegarkan status/tampilan, bukan menarik data |

Rekomendasi:

1. Kalau SANTESRV1 bisa **mengirim** study otomatis → opsi **A** (realtime sesungguhnya, tanpa poll).
2. Kalau tidak bisa, dan ingin near-realtime tanpa menyentuh server Orthanc → opsi **C**
   (`/changes` long-poll) atau **D** (cron 1 menit) — keduanya memakai script yang sama,
   jadi tidak perlu mengubah `src/`.
3. Opsi **B** hanya memberi notifikasi; data tetap harus ditarik lewat C-GET/C-MOVE.

Contoh cek cepat bahwa `/changes` bisa dipakai untuk realtime (polling 10 detik):

```powershell
$last = (Invoke-RestMethod 'http://10.4.0.10:8042/changes?last=1').Last
# ulangi dengan ?last=$last&timeout=10 -> Orthanc menahan request sampai ada perubahan baru
Invoke-RestMethod "http://10.4.0.10:8042/changes?last=$last&timeout=10"
```

Saat ini `/changes` **diam** (seq tidak bertambah) kecuali ada study baru masuk, jadi aman
dipoll terus-menerus.

### 14.5 Sumber data: `SANTESRV1` (192.168.10.15) vs alat `192.168.10.110`

Konfigurasi modality di Orthanc (dibaca lewat `GET /modalities/SANTESRV1/configuration`):

| Field | Nilai |
|---|---|
| AET | `SANTESRV1` |
| Host | **`192.168.10.15`** |
| Port | **`11121`** |
| RetrieveMethod | **`C-GET`** |

Jadi PACS yang ditarik cron **bukan** `192.168.10.110`, melainkan `192.168.10.15:11121`.

Hasil uji langsung dari workstation (`192.168.200.230`, AET `LEAD_CLIENT`) pada 13 Nov 2026:

| Target | Port | Hasil |
|---|---|---|
| `192.168.10.15` | 11121 | ✅ C-ECHO `0x0000`, C-FIND Study Root hari ini = **50 study** (sama dengan yang dilaporkan cron) |
| `192.168.10.110` | 104 | ❌ asosiasi **ditolak**: *Calling AE title not recognised* (reason `0x03`) |
| `192.168.10.110` | 104 (dari Orthanc `10.4.0.10`, AET `ORTHANC`) | ❌ `DUL Association Rejected` — sama, ditolak |

Ciri `192.168.10.110`: ping OK (~5 ms), port **104** (DICOM SCP), **80** (Microsoft-IIS/10.0,
semua path 404), **139/445** (SMB, akses ditolak), reverse DNS `DESKTOP-LQHRGU3`
→ mesin Windows yang menjalankan service DICOM dengan **whitelist AET pemanggil**.

Artinya:

- study **tetap bisa diambil langsung** (tanpa lewat Orthanc) dari `192.168.10.15:11121`
  memakai AET pemanggil `LEAD_CLIENT` — sudah diuji dengan C-FIND;
- `192.168.10.110:104` baru bisa dipakai kalau AET pemanggil (`LEAD_CLIENT`) **dan** IP
  `192.168.200.230` didaftarkan di menu DICOM/peer configuration alat itu. Tanpa itu,
  semua asosiasi (termasuk dari Orthanc) ditolak permanen.

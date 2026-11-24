# REF Dashboard — UI & pendaftaran alat

> Dipisah dari `AGENTS.md` agar konteks agent tetap kecil. Baca hanya bila menyentuh `public/dashboard.html` atau modality.

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
- daftar worklist + form kirim order + tombol hapus: order dirender sebagai **kartu bertumpuk**
  (`div.wl-list` → `.wl-item`: nama pasien + tombol **Hapus** di kanan atas, lalu No. RM/accession,
  pemeriksaan + `Modality · AET`, dan jadwal) — **bukan tabel 5 kolom**, karena panel sidebar hanya
  ±360px sehingga kolom terakhir dulu terpotong `overflow: hidden` milik `.panel`
  (form punya pilihan **Alat / Station tujuan**
  dari daftar alat di Pengaturan, dan **Accession Number terisi otomatis** dari `GET /accession`
  — format alat Orthanc — dengan tombol ⟳ untuk nomor baru; saat kosong muncul tombol
  "⚙ Atur / tambah alat worklist"),
- **panel Alat Worklist** di Pengaturan: daftar alat (Nama, AE Title, Modality, **IP/host alat**, **Port**)
  dengan tombol **＋ Tambah alat** / **Hapus**, disimpan sebagai `worklist_devices` di `data/settings.json`
  (server 10.4.0.10 saat ini: `LEAD_CLIENT · DX`, `USG · VersanaB-000000 · US`,
  `EKG · LEAD_PHILIP · ECG`, dan `CT Scan · CT140643 · CT`),
  AE Title disimpan **apa adanya** (huruf besar/kecil berpengaruh pada MWL).
  Tiap baris alat punya status **(Belum) Terdaftar di Orthanc** (`GET /modalities`) plus tombol
  **Daftarkan ke Orthanc** dan **Tes C-ECHO** — jadi menambah alat baru (mis. **USG G2**) cukup:
  isi Nama/AE Title/Modality/IP/Port → **Daftarkan ke Orthanc** (alat otomatis disimpan dulu ke
  Pengaturan). Untuk alat EKG/USG baru, minta teknisi mengecek AET alatnya (jangan menebak
  dari `StationName`) dan tekan **Tes C-ECHO** saat alat menyala,
- **tab modalitas** di atas toolbar study: **Semua / Rontgen (DX) / CT Scan (CT) / USG (US) / EKG (ECG) / Panoramic (PX)**
  (`GET /studies/recent?modality=US|DX|CT|ECG|PX`; ikut menyaring daftar "di luar filter tanggal").
  Tab = `DEFAULT_MODALITIES` di `public/dashboard.html` **+ modality tiap alat di Pengaturan**
  (`worklist_devices`) **+ modalitas yang benar-benar ada di study** (`noteStudyModalities()` dari
  `MainDicomTags.Modality`) — jadi alat baru yang mengirim gambar tanpa didaftarkan pun langsung
  punya tab (saklar **Pengaturan → Tampilan & akses → “Tambah tab otomatis …”**,
  `auto_modality_tabs`, default aktif; tombol **⚙ Atur** di toolbar Study & Gambar membuka bagian
  itu, terlihat hanya untuk manager).
  (label dari `MODALITY_LABELS`, kode tak dikenal tampil apa adanya). Tab dibangun ulang oleh
  `renderModalityTabs()` setiap `loadDevices()` (boot & setelah **Simpan Pengaturan**) dan setiap
  modalitas baru terdeteksi dari study; bila tab yang
  sedang aktif hilang (alatnya dihapus), filter kembali ke "Semua" dan daftar study dimuat ulang.
  Backend tidak perlu diubah: `/studies/recent` menerima kode modalitas DICOM apa pun
  (`^[A-Z0-9]{1,16}$` di `public/index.php`),
- **filter study default "Hari ini"** (+ tombol "Hari ini" / "Semua tanggal" / pilih tanggal),
  dan bila ada study bertanggal **setelah** hari ini (jam alat maju) muncul catatan kuning
  "⚠ N study bertanggal di luar filter ini" + kartu study tersebut tetap ditampilkan di bawah
  (kunci gambar/ZIP memakai ID Orthanc bila study tanpa accession),
- pencarian study by accession / No. RM / **nama pasien** (parsial, mis. `adi watik`), grid thumbnail (PNG 320px), tombol **Lihat**
  (viewer memakai gambar **ber-watermark dari server**: kiri `NAMA, SEX, DD/MM/YYYY` /
  `Pemeriksaan, DD/MM/YYYY` / `No. RM`, kanan `InstitutionName` + `ReferringPhysicianName`,
  tanpa nomor accession — **kecuali modalitas US/USG & ECG/EKG yang tampil tanpa watermark**;
  untuk **US/USG** yang dipilih adalah instance **berwarna** (Color/Power Doppler, `SamplesPerPixel` ≥ 3)
  pertama bila ada, jadi thumbnail/"Lihat gambar" tidak selalu menampilkan B-mode),
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

### 6.9 Mendaftarkan alat baru dari dashboard (`DicomModalities`)

Dulu alat baru wajib diedit manual di `/etc/orthanc/orthanc.json` + restart Orthanc. Sekarang
cukup dari **Pengaturan → Alat Worklist** (sesi **manager**):

1. **＋ Tambah alat** → isi **Nama** (mis. `USG G2`), **AE Title** (AET asli alat — peka huruf),
   **Modality** (`US`), **IP / host alat**, **Port** (umumnya `104`).
2. Klik **Daftarkan ke Orthanc** → dashboard menyimpan alat ke `worklist_devices` lalu memanggil
   `PUT /modalities/{aet}`; bridge meneruskannya ke Orthanc `PUT /modalities/{id}` dengan flag
   `AllowFindWorklist` + `AllowStore` (+ `AllowFind`, `AllowEcho`, `AllowGet`, `AllowMove`).
   Badge berubah jadi **Terdaftar di Orthanc · IP:port · MWL · C-STORE**.
3. Saat alat menyala, klik **Tes C-ECHO** → harus muncul "C-ECHO OK". Gagal = IP/port/AET salah
   atau alat belum terhubung (bukan masalah MWL/SIMRS).
4. Kirim order uji dari form worklist lalu hapus ordernya.

Endpoint (sesi manager, `X-API-Key` **ditolak 403**):

```powershell
curl -H "Content-Type: application/json" -X PUT http://10.4.0.10/modalities/USG-G2 -b cookies.txt -d '{}'
curl -X POST http://10.4.0.10/modalities/USG-G2/echo -b cookies.txt
curl http://10.4.0.10/modalities -b cookies.txt      # status semua modality + alat di Pengaturan
```

Hal yang mudah salah:

- **AET harus AET alat yang sebenarnya**, bukan nama tampilan (`StationName`). Kalau alat menolak
  assosiasi (`RJ reason=0x03/0x07`), AET-nya salah — pakai `dicom-probe.py`.
- `Modality` di baris alat **hanya** label/tab dashboard; yang menentukan MWL adalah AET.
- **Persistensi:** tanpa `"DicomModalitiesInDatabase": true` di `/etc/orthanc/orthanc.json`,
  modality hasil pendaftaran hilang setelah container Orthanc di-restart (daftar alat di
  Pengaturan tetap ada — tinggal klik **Daftarkan ke Orthanc** lagi).
- **`DCMROUTER` dan `SANTESRV1` bukan alat**: `DCMROUTER` = **router Kemenkes**
  (`10.4.0.10:11112`) dan `SANTESRV1` = PACS sumber cron. Keduanya muncul di daftar modality
  Orthanc; jangan dimasukkan ke `worklist_devices` atau dipakai sebagai tujuan MWL
  (C-FIND lewat `:11112` = 0 hasil). Alat DX (`LEAD_CLIENT`) menarik MWL ke
  `10.4.0.10:4242` AET `ORTHANC`.
- Jangan arahkan `Host` ke IP bridge/server sendiri; itu harus IP alat.
- Tanpa pendaftaran, C-FIND MWL ditolak: `This AET is not listed in configuration option "DicomModalities"`.
  Pendaftaran manual di `/etc/orthanc/orthanc.json` tetap valid — wajib bila Orthanc tidak bisa
  dihubungi dari bridge.
- Membiarkan alat terdaftar tetapi **tidak** ada di `worklist_devices` membuat alat tidak muncul
  di pilihan **Alat / Station tujuan** form worklist.

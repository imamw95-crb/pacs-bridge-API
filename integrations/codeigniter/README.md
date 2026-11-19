# Integrasi CodeIgniter — Bridge SIMRS ↔ Orthanc PACS

Ada **dua mode**. Pilih salah satu — keduanya memakai SDK `src/` yang sama
(wrapper hanya meneruskan ke service, tanpa logika HTTP baru di adapter).

| Mode | Library | Alur | Autentikasi | Kapan dipakai |
|---|---|---|---|---|
| **A. Lewat Bridge API** *(disarankan)* | `App\Libraries\PacsApi` (CI4) · `Pacs_api` (CI3) | SIMRS → `http://10.4.0.10/` (program bridge) → Orthanc | `X-API-Key` | Orthanc **tidak** diakses langsung; validasi, watermark, ZIP, filter tanggal, aturan terpusat |
| **B. Langsung ke Orthanc** | `App\Libraries\Pacs` (CI4) · `Pacs_bridge` (CI3) | SIMRS → `http://10.4.0.10:8042` | tanpa auth (batasi LAN/VPN) | butuh akses REST penuh ke Orthanc, atau bridge tidak dipakai |

```
integrations/codeigniter/
├── ci4/app/
│   ├── Libraries/Pacs.php                  # mode B — SDK langsung ke Orthanc
│   ├── Libraries/PacsApi.php               # mode A — Bridge API + X-API-Key
│   ├── Config/Pacs.php                     # konfigurasi (override via .env)
│   ├── Config/PacsService.php              # contoh service('pacs') & service('pacsApi')
│   ├── Controllers/PacsController.php      # contoh endpoint mode B (/pacs/...)
│   └── Controllers/PacsApiController.php   # contoh endpoint mode A (/pacs-api/...)
└── ci3/application/
    ├── libraries/Pacs_bridge.php           # mode B
    ├── libraries/Pacs_api.php              # mode A
    ├── config/pacs.php                     # konfigurasi
    ├── controllers/Pacs.php                # contoh endpoint mode B
    └── controllers/Pacs_api.php            # contoh endpoint mode A
```

---

## A. Salin SDK (wajib untuk kedua mode)

```
CI4 : <SIMRS>/app/ThirdParty/pacs/src/        <- isi folder src/ dari repo ini
CI3 : <SIMRS>/application/third_party/pacs/src/
```

---

## B. Mode API — lewat Bridge API (`X-API-Key`)

SIMRS **tidak** membuka koneksi ke Orthanc; semua permintaan lewat program bridge
(`public/index.php`) yang menjalankan validasi, render JPG/PNG, watermark, dan ZIP.

### B.1 Salin file (CI4)

| Dari | Ke |
|---|---|
| `ci4/app/Libraries/PacsApi.php` | `app/Libraries/PacsApi.php` |
| `ci4/app/Config/Pacs.php` | `app/Config/Pacs.php` |
| `ci4/app/Controllers/PacsApiController.php` | `app/Controllers/PacsApiController.php` |
| `ci4/app/Config/PacsService.php` | *salin method-nya* ke `app/Config/Services.php` |

### B.2 Konfigurasi (`.env`)

```ini
pacs.bridgeUrl                = http://10.4.0.10
pacs.bridgeTimeout            = 60
pacs.apiKey                   = <API_KEY>

# opsional (dipakai mode B saja)
# pacs.serverIp   = 10.4.0.10
# pacs.httpPort   = 8042
# pacs.dicomPort  = 4242
# pacs.calledAet  = ORTHANC
# pacs.callingAet = LEAD_CLIENT
```

`apiKey` diambil dari dashboard bridge → ⚙ **Pengaturan** → panel *API Bridge* → **Generate**
(atau environment `PACS_BRIDGE_KEY` di server bridge). Jangan commit nilainya.

### B.3 Routes (`app/Config/Routes.php`)

```php
$routes->get('pacs-api/health', 'PacsApiController::health');
$routes->get('pacs-api/accession', 'PacsApiController::accession');
$routes->get('pacs-api/worklists', 'PacsApiController::worklists');
$routes->post('pacs-api/worklists', 'PacsApiController::createWorklist');
$routes->delete('pacs-api/worklists/(:segment)', 'PacsApiController::deleteWorklist/$1');
$routes->get('pacs-api/studies/recent', 'PacsApiController::recent');          // sebelum (:segment)
$routes->get('pacs-api/studies/(:segment)/jpg', 'PacsApiController::jpg/$1');
$routes->get('pacs-api/studies/(:segment)/png', 'PacsApiController::png/$1');
$routes->get('pacs-api/studies/(:segment)/zip-jpg', 'PacsApiController::zipJpg/$1');
$routes->get('pacs-api/studies/(:segment)/zip', 'PacsApiController::zip/$1');
```

### B.4 Pemakaian (CI4)

```php
$api = new \App\Libraries\PacsApi(config('Pacs'));
// atau: $api = service('pacsApi');

$api->health();                                   // status Orthanc + worklist

$api->createWorklist([
    'patient_name'     => 'TARYA BIN WARDI',
    'title'            => 'Tn.',
    'patient_id'       => 'A048919',
    'accession_number' => 'A261105038',
    'procedure'        => 'THORAX PA',
    'modality'         => 'DX',
    'station_aet'      => 'LEAD_CLIENT',
]);

$jpg = $api->jpegByAccession('A261105038', 90, true);       // + watermark
$zip = $api->zipJpgByAccession('A261105038');               // ZIP JPG (folder = No. RM)
```

### B.5 Salin file & pemakaian (CI3)

| Dari | Ke |
|---|---|
| `ci3/application/libraries/Pacs_api.php` | `application/libraries/Pacs_api.php` |
| `ci3/application/config/pacs.php` | `application/config/pacs.php` |
| `ci3/application/controllers/Pacs_api.php` | `application/controllers/Pacs_api.php` |

```php
// application/config/pacs.php
$config['bridge_url']     = 'http://10.4.0.10';
$config['bridge_timeout'] = 60;
$config['api_key']        = '<API_KEY>';   // jangan di-commit; bisa dari env PACS_BRIDGE_KEY
```

```php
// routes.php
$route['pacs-api/health']                  = 'pacs_api/health';
$route['pacs-api/accession']               = 'pacs_api/accession';
$route['pacs-api/worklists']               = 'pacs_api/worklists';
$route['pacs-api/worklists/create']        = 'pacs_api/create_worklist';
$route['pacs-api/worklists/(:any)/delete'] = 'pacs_api/delete_worklist/$1';
$route['pacs-api/studies/recent']          = 'pacs_api/recent';          // sebelum (:any)
$route['pacs-api/studies/(:any)/jpg']      = 'pacs_api/jpg/$1';
$route['pacs-api/studies/(:any)/png']      = 'pacs_api/png/$1';
$route['pacs-api/studies/(:any)/zip-jpg']  = 'pacs_api/zip_jpg/$1';
$route['pacs-api/studies/(:any)/zip']      = 'pacs_api/zip_dicom/$1';
```

```php
$this->load->library('pacs_api');

$created = $this->pacs_api->create_worklist([...]);
if ($created === false) {
    log_message('error', $this->pacs_api->error_message());
}

$image = $this->pacs_api->jpeg_by_accession('A261105038', 90, true);
```

---

## C. Mode langsung ke Orthanc (tanpa Bridge API)

SIMRS memanggil REST Orthanc (`:8042`). Tidak ada API key — batasi akses di level jaringan.

### C.1 CI4

| Dari | Ke |
|---|---|
| `ci4/app/Libraries/Pacs.php` | `app/Libraries/Pacs.php` |
| `ci4/app/Controllers/PacsController.php` | `app/Controllers/PacsController.php` |

```php
$pacs = new \App\Libraries\Pacs(config('Pacs'));
$pacs->createOrder([...]);
$image = $pacs->jpegByAccession('A261105038', 90);
```

### C.2 CI3

| Dari | Ke |
|---|---|
| `ci3/application/libraries/Pacs_bridge.php` | `application/libraries/Pacs_bridge.php` |
| `ci3/application/controllers/Pacs.php` | `application/controllers/Pacs.php` |

```php
$this->load->library('pacs_bridge');
$created = $this->pacs_bridge->create_order([...]);
$image   = $this->pacs_bridge->jpeg_by_accession('A261105038', 90);
```

Routes mode langsung memakai prefix `pacs/...` (lihat docblock di controller contoh).

---

## D. Daftar method

### D.1 Mode API — `PacsApi` / `Pacs_api`

| Method | Keterangan |
|---|---|
| `health()`, `me()` | status Orthanc + verifikasi kredensial (`via: api-key`) |
| `nextAccession()` / `next_accession()` | nomor AccessionNumber baru |
| `worklists()`, `worklist($id)` | daftar / detail order |
| `createWorklist(array)` / `create_worklist()` | kirim order |
| `updateWorklist($id, array)` / `update_worklist()` | ubah order |
| `deleteWorklist($id)` / `delete_worklist()` | hapus order |
| `findStudiesByAccession()` · `findStudiesByPatientId()` · `findStudiesByName()` | cari study |
| `recentStudies($date, $limit, $modality)` / `recent_studies()` | study terbaru + filter modalitas |
| `imageList($kunci)` / `image_list()` | daftar instance/gambar satu study |
| `jpegByAccession($acc, $quality, $watermark)` / `jpeg_by_accession()` | JPEG (watermark opsional) |
| `pngByAccession($acc, $width)` / `png_by_accession()` | PNG (resize) |
| `zipJpgByAccession($acc, $quality, $watermark)` / `zip_jpg_by_accession()` | ZIP JPG (folder = No. RM) |
| `zipByAccession($acc)` / `zip_by_accession()` | ZIP DICOM |
| `dicomInstance($id)` / `dicom_instance()` | 1 file DICOM |
| `settings()` | konfigurasi aktif bridge |

### D.2 Mode langsung — `Pacs` / `Pacs_bridge`

| Method | Keterangan |
|---|---|
| `health()` | status Orthanc + jumlah study |
| `createOrder()` / `create_order()` · `updateOrder()` / `update_order()` · `deleteOrder()` / `delete_order()` | CRUD order |
| `orders()` | daftar order |
| `nextAccession()` / `next_accession()` | nomor accession baru |
| `findStudies()` / `find_studies()` · `findStudiesByPatientId()` · `findStudiesByName()` | cari study |
| `recentStudies()` / `recent_studies()` | study terbaru + modalitas |
| `imageList()` / `image_list()` | daftar gambar |
| `jpegByAccession()` / `jpeg_by_accession()` | JPEG (watermark opsional) |
| `pngByAccession()` / `png_by_accession()` | PNG |
| `zipJpgByAccession()` / `zip_jpg_by_accession()` | ZIP JPG |
| `zipByAccession()` / `zip_by_accession()` | ZIP DICOM |
| `worklist()`, `studies()`, `client()` | akses service tingkat rendah |

Nama method beda hanya konvensi: CI4 = camelCase, CI3 = snake_case.
CI4 melempar `Throwable`; CI3 mengembalikan `false` / `[]` / `null` + `error_message()`.

Watermark membutuhkan ekstensi **GD + FreeType**. Di mode A, server yang perlu GD adalah
server bridge; di mode B, server SIMRS. Bila tidak tersedia, panggil dengan `$watermark = false`.

---

## E. Catatan penting

- **`modality` hanya default.** Nilai di config (`pacs.modality` / `$config['modality']`) dipakai
  bila order tidak mengisi `modality`. Order lain (CT, US, ECG, PX, …) cukup mengirim
  `'modality' => 'CT'` + `'station_aet' => '<AET alat>'` — tidak perlu mengubah config.
  Alatnya tetap harus terdaftar di `DicomModalities` Orthanc dan di daftar **Alat Worklist** dashboard.
- **PatientName tanpa koma** (`TARYA BIN WARDI TN`) — mode B: `PatientName::safe()`; mode A: bridge yang merapikan.
- Order harus **tanggal hari ini**; kalau tidak, alat tidak melihatnya (mode A: bridge menangani).
- **AE Title case-sensitive** — kirim huruf asli (`VersanaB-000000`).
- Sebagian alat (USG) mengirim study **tanpa accession** → pakai ID Orthanc sebagai kunci gambar/ZIP.
- JPG/PNG hasil render adalah **preview** (lossy), bukan citra diagnostik.
- Endpoint contoh di folder ini **belum berautentikasi** — pasang di belakang auth SIMRS.
  Mode A tetap memakai API key ke bridge, tapi endpoint SIMRS-nya sendiri harus dilindungi.

---

## F. Uji

```powershell
# mode langsung (butuh akses Orthanc)
php tools/test-ci-integration.php                     # 9 pass, 0 fail

# termasuk mode API (jalankan bridge lokal: php -S 127.0.0.1:8081 -t public public/router.php)
$env:PACS_BRIDGE_TEST_URL = 'http://127.0.0.1:8081'
$env:PACS_BRIDGE_TEST_KEY = '<API_KEY>'
php tools/test-ci-integration.php                     # 12 pass, 0 fail

# uji khusus API key pada bridge
php tools/test-api-key.php http://127.0.0.1:8081 <API_KEY>
```

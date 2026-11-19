# Integrasi CodeIgniter — Bridge SIMRS ↔ Orthanc PACS

Dua varian tersedia. Pilih sesuai versi CodeIgniter di SIMRS.

```
integrations/codeigniter/
├── ci4/
│   └── app/
│       ├── Libraries/Pacs.php            # library (namespace App\Libraries)
│       ├── Config/Pacs.php               # konfigurasi (override via .env)
│       ├── Config/PacsService.php        # contoh registrasi service('pacs')
│       └── Controllers/PacsController.php # contoh endpoint
└── ci3/
    └── application/
        ├── libraries/Pacs_bridge.php     # library ($this->load->library)
        ├── config/pacs.php               # konfigurasi
        └── controllers/Pacs.php          # contoh endpoint
```

SDK inti ada di folder `src/` (PHP murni, tanpa dependency) dan dipakai bersama oleh kedua varian.

---

## A. CodeIgniter 4

### 1. Salin SDK

```
<SIMRS>/app/ThirdParty/pacs/src/        <- salin isi folder src/ dari repo ini
```

### 2. Salin file integrasi

| Dari | Ke |
|---|---|
| `ci4/app/Libraries/Pacs.php` | `app/Libraries/Pacs.php` |
| `ci4/app/Config/Pacs.php` | `app/Config/Pacs.php` |
| `ci4/app/Controllers/PacsController.php` | `app/Controllers/PacsController.php` |

### 3. Daftarkan service (opsional)

Tambahkan method berikut ke `app/Config/Services.php` (lihat `ci4/app/Config/PacsService.php`):

```php
public static function pacs(bool $getShared = true): \App\Libraries\Pacs
{
    if ($getShared) {
        return static::getSharedInstance('pacs');
    }

    return new \App\Libraries\Pacs(config('Pacs'));
}
```

### 4. Routes (`app/Config/Routes.php`)

```php
$routes->get('pacs/health', 'PacsController::health');
$routes->get('pacs/worklists', 'PacsController::worklists');
$routes->post('pacs/worklists', 'PacsController::createWorklist');
$routes->delete('pacs/worklists/(:segment)', 'PacsController::deleteWorklist/$1');
$routes->get('pacs/studies/(:segment)/jpg', 'PacsController::jpg/$1');
$routes->get('pacs/studies/(:segment)/png', 'PacsController::png/$1');
```

### 5. Konfigurasi (`.env`)

```ini
pacs.serverIp   = 10.4.0.10
pacs.httpPort   = 8042
pacs.dicomPort  = 4242
pacs.calledAet  = ORTHANC
pacs.callingAet = LEAD_CLIENT
pacs.modality   = DX
# pacs.user     = pacs
# pacs.password = ******
```

### 6. Pemakaian

```php
$pacs = new \App\Libraries\Pacs(config('Pacs'));
// atau: $pacs = service('pacs');

$pacs->createOrder([
    'patient_name'     => 'TARYA BIN WARDI',
    'title'            => 'Tn.',
    'patient_id'       => 'A048919',
    'accession_number' => 'A261105038',
    'procedure'        => 'THORAX PA',
]);

$image = $pacs->jpegByAccession('A261105038', 90);
if ($image !== null) {
    file_put_contents(WRITEPATH . 'uploads/' . $image['filename'], $image['content']);
}
```

---

## B. CodeIgniter 3

### 1. Salin SDK

```
<SIMRS>/application/third_party/pacs/src/     <- salin isi folder src/
```

### 2. Salin file integrasi

| Dari | Ke |
|---|---|
| `ci3/application/libraries/Pacs_bridge.php` | `application/libraries/Pacs_bridge.php` |
| `ci3/application/config/pacs.php` | `application/config/pacs.php` |
| `ci3/application/controllers/Pacs.php` | `application/controllers/Pacs.php` |

### 3. Routes (`application/config/routes.php`)

```php
$route['pacs/health']                   = 'pacs/health';
$route['pacs/worklists']                = 'pacs/worklists';
$route['pacs/worklists/create']         = 'pacs/create_worklist';
$route['pacs/worklists/(:any)/delete']  = 'pacs/delete_worklist/$1';
$route['pacs/studies/(:any)/jpg']       = 'pacs/jpg/$1';
$route['pacs/studies/(:any)/png']       = 'pacs/png/$1';
```

### 4. Pemakaian di controller/model

```php
$this->load->library('pacs_bridge');

$created = $this->pacs_bridge->create_order([
    'patient_name'     => 'TARYA BIN WARDI',
    'title'            => 'Tn.',
    'patient_id'       => 'A048919',
    'accession_number' => 'A261105038',
    'procedure'        => 'THORAX PA',
]);

if ($created === false) {
    log_message('error', $this->pacs_bridge->error_message());
}

$image = $this->pacs_bridge->jpeg_by_accession('A261105038', 90);
```

---

## C. API library (sama untuk CI3 & CI4)

| Method | Keterangan |
|---|---|
| `health()` | status Orthanc + jumlah study |
| `createOrder(array)` / `create_order(array)` | kirim order ke worklist |
| `updateOrder(id, array)` / `update_order(id, array)` | ubah order |
| `deleteOrder(id)` / `delete_order(id)` | hapus order |
| `orders()` | daftar order |
| `findStudies($accession)` / `find_studies()` | cari study |
| `jpegByAccession($acc, $quality)` / `jpeg_by_accession()` | gambar JPEG |
| `pngByAccession($acc, $width)` / `png_by_accession()` | gambar PNG (resize) |
| `worklist()`, `studies()`, `client()` | akses service tingkat rendah |

Nama method beda hanya karena konvensi: CI4 = camelCase, CI3 = snake_case.

---

## D. Dua cara menghubungkan SIMRS ke PACS

Pilih sesuai kebutuhan — keduanya memakai SDK yang sama (`src/`).

### E.1 Langsung ke Orthanc (dipakai library CI di folder ini)

```
SIMRS (CI) --DICOM/REST--> Orthanc 10.4.0.10
```

- Library `App\Libraries\Pacs` (CI4) / `Pacs_bridge` (CI3) — tanpa HTTP hop tambahan.
- Cocok bila SIMRS **menggantikan alat** (kirim C-STORE, ambil MWL) atau butuh akses penuh.
- Tanpa autentikasi (Orthanc saat ini `AuthenticationEnabled=false`) → batasi di level jaringan.

### E.2 Lewat Bridge API (pakai `X-API-Key`)

```
SIMRS (CI) --HTTP + X-API-Key--> Bridge API (public/index.php) --HTTP--> Orthanc
```

- Client: `Simrs\Pacs\BridgeApiClient` (sudah ada di SDK, tanpa dependency).
- Cocok bila yang memanggil adalah aplikasi lain/front-end, atau ingin aturan terpusat
  (validasi, nama tanpa koma, filter tanggal, render JPG/PNG, logging).
- API key diisi di dashboard (⚙ Pengaturan → API Key) atau env `PACS_BRIDGE_KEY`.

```php
use Simrs\Pacs\BridgeApiClient;

$api = new BridgeApiClient('http://pacs-bridge.test', getenv('PACS_BRIDGE_KEY'));

$api->health();
$api->createWorklist([
    'patient_name'     => 'TARYA BIN WARDI',
    'title'            => 'Tn.',
    'patient_id'       => 'A048919',
    'accession_number' => 'A261105038',
    'procedure'        => 'THORAX PA',
]);
$img = $api->jpegByAccession('A261105038', 90);
```

| Aksi | Sesi login | `X-API-Key` |
|---|---|---|
| Baca data (worklist, study, gambar) | ✅ | ✅ |
| Kirim/ubah/hapus worklist | ✅ | ✅ |
| `PUT /settings`, ganti password | ✅ | ❌ 403 |

Uji: `php tools/test-api-key.php http://127.0.0.1:8081 <API_KEY>` → **8 pass**.

---

## E. Catatan penting

- **PatientName tanpa koma.** `PatientName::safe()` mengubah `TARYA BIN WARDI, Tn.` → `TARYA BIN WARDI TN`
  karena modalitas memotong nama pada koma.
- **Tanggal order = hari ini** (`Ymd`); kalau tidak, alat tidak melihat order.
- **Jangan kirim `QueryRetrieveLevel`** pada query MWL (dari sisi alat).
- JPG/PNG hasil render **bukan citra diagnostik** — untuk bacaan gunakan DICOM asli.
- REST Orthanc saat ini tanpa autentikasi; batasi akses jaringan dan/atau isi `apiKey`.

---

## F. Uji integrasi

```powershell
# uji library CI3 & CI4 tanpa perlu install framework
php tools/test-ci-integration.php
```

Harness ini mensimulasikan `APPPATH` + config array dan memverifikasi:
konstruksi library, `health()`, `createOrder`/`create_order`, dan render PNG.

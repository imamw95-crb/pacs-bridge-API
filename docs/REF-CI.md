# REF Integrasi CodeIgniter (SIMRS)

> Dipisah dari `AGENTS.md` agar konteks agent tetap kecil. Ringkasan pemakaian: `integrations/codeigniter/README.md`.

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

> **Belum dibungkus adapter CI:** auto expertise dan pendaftaran modality. Bila dibutuhkan,
> panggil `BridgeApiClient::expertiseStatus()`, `expertise()`, `createExpertise()` langsung dari
> controller/service SIMRS (atau `POST /studies/{key}/expertise` via mode A).

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

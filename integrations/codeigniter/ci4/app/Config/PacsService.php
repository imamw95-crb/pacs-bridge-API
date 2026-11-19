<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\Pacs;
use App\Libraries\PacsApi;
use CodeIgniter\Config\BaseService;

/**
 * Registrasi service agar bisa dipakai: service('pacs')->createOrder([...]);
 * dan service('pacsApi')->createWorklist([...]);
 *
 * Salin method di bawah ke app/Config/Services.php milik proyek SIMRS.
 */
class PacsService extends BaseService
{
    public static function pacs(bool $getShared = true): Pacs
    {
        if ($getShared) {
            return static::getSharedInstance('pacs');
        }

        return new Pacs(config('Pacs'));
    }

    /** Mode API: lewat Bridge API + `X-API-Key` (Orthanc tidak diakses langsung). */
    public static function pacsApi(bool $getShared = true): PacsApi
    {
        if ($getShared) {
            return static::getSharedInstance('pacsApi');
        }

        return new PacsApi(config('Pacs'));
    }
}

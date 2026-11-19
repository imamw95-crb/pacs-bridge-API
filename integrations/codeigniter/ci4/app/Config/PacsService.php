<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\Pacs;
use CodeIgniter\Config\BaseService;

/**
 * Registrasi service agar bisa dipakai: service('pacs')->createOrder([...]);
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
}

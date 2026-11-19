<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\PacsApi;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Simrs\Pacs\BridgeApiException;
use Throwable;

/**
 * Contoh controller CodeIgniter 4 yang memanggil **Bridge API** (X-API-Key) —
 * SIMRS tidak mengakses Orthanc langsung.
 *
 * Routes (app/Config/Routes.php):
 *   $routes->get('pacs-api/health', 'PacsApiController::health');
 *   $routes->get('pacs-api/accession', 'PacsApiController::accession');
 *   $routes->get('pacs-api/worklists', 'PacsApiController::worklists');
 *   $routes->post('pacs-api/worklists', 'PacsApiController::createWorklist');
 *   $routes->delete('pacs-api/worklists/(:segment)', 'PacsApiController::deleteWorklist/$1');
 *   $routes->get('pacs-api/studies/recent', 'PacsApiController::recent');
 *   $routes->get('pacs-api/studies/(:segment)/jpg', 'PacsApiController::jpg/$1');
 *   $routes->get('pacs-api/studies/(:segment)/png', 'PacsApiController::png/$1');
 *   $routes->get('pacs-api/studies/(:segment)/zip-jpg', 'PacsApiController::zipJpg/$1');
 *   $routes->get('pacs-api/studies/(:segment)/zip', 'PacsApiController::zip/$1');
 */
class PacsApiController extends Controller
{
    private PacsApi $pacs;

    public function __construct()
    {
        $this->pacs = new PacsApi(config('Pacs'));
    }

    public function health(): ResponseInterface
    {
        try {
            return $this->response->setJSON($this->pacs->health());
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    public function accession(): ResponseInterface
    {
        try {
            return $this->response->setJSON(['accession_number' => $this->pacs->nextAccession()]);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    public function worklists(): ResponseInterface
    {
        try {
            return $this->response->setJSON($this->pacs->worklists());
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    public function createWorklist(): ResponseInterface
    {
        try {
            $result = $this->pacs->createWorklist($this->request->getJSON(true) ?? []);

            return $this->response->setStatusCode(201)->setJSON($result);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    public function deleteWorklist(string $id): ResponseInterface
    {
        try {
            $this->pacs->deleteWorklist($id);

            return $this->response->setJSON(['deleted' => $id]);
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    public function recent(): ResponseInterface
    {
        try {
            $limit = (int) ($this->request->getGet('limit') ?? 12);

            return $this->response->setJSON($this->pacs->recentStudies(
                (string) ($this->request->getGet('date') ?? 'today'),
                $limit,
                (string) ($this->request->getGet('modality') ?? '')
            ));
        } catch (Throwable $e) {
            return $this->error($e);
        }
    }

    public function jpg(string $accession): ResponseInterface
    {
        try {
            $file = $this->pacs->jpegByAccession(
                $accession,
                (int) ($this->request->getGet('quality') ?? 90),
                (string) ($this->request->getGet('watermark') ?? '0') === '1'
            );
        } catch (Throwable $e) {
            return $this->error($e);
        }

        return $this->sendFile($file, 'Gambar tidak ditemukan untuk accession ' . $accession);
    }

    public function png(string $accession): ResponseInterface
    {
        $width = $this->request->getGet('width');

        try {
            $file = $this->pacs->pngByAccession($accession, $width !== null ? (int) $width : null);
        } catch (Throwable $e) {
            return $this->error($e);
        }

        return $this->sendFile($file, 'Gambar tidak ditemukan untuk accession ' . $accession);
    }

    public function zipJpg(string $accession): ResponseInterface
    {
        $watermark = ! in_array(
            strtolower((string) ($this->request->getGet('watermark') ?? '1')),
            ['0', 'false', 'no', 'off'],
            true
        );

        try {
            $file = $this->pacs->zipJpgByAccession(
                $accession,
                (int) ($this->request->getGet('quality') ?? 90),
                $watermark
            );
        } catch (Throwable $e) {
            return $this->error($e);
        }

        return $this->sendFile($file, 'Tidak ada gambar untuk ' . $accession, true);
    }

    public function zip(string $accession): ResponseInterface
    {
        try {
            $content = $this->pacs->zipByAccession($accession);
        } catch (Throwable $e) {
            return $this->error($e);
        }

        if ($content === null) {
            return $this->response->setStatusCode(404)->setJSON([
                'error' => 'Study tidak ditemukan untuk ' . $accession,
            ]);
        }

        return $this->response
            ->setHeader('Content-Type', 'application/zip')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $accession . '.zip"')
            ->setBody($content);
    }

    /**
     * @param array{content?: string, mime?: string, filename?: string}|null $file
     */
    private function sendFile(?array $file, string $notFound, bool $attachment = false): ResponseInterface
    {
        if ($file === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $notFound]);
        }

        return $this->response
            ->setHeader('Content-Type', (string) ($file['mime'] ?? 'application/octet-stream'))
            ->setHeader(
                'Content-Disposition',
                ($attachment ? 'attachment' : 'inline') . '; filename="' . ($file['filename'] ?? 'download.bin') . '"'
            )
            ->setBody((string) $file['content']);
    }

    private function error(Throwable $e): ResponseInterface
    {
        $status = $e instanceof BridgeApiException && $e->statusCode >= 400 ? $e->statusCode : 502;

        return $this->response->setStatusCode($status)->setJSON([
            'error'   => 'Bridge API gagal',
            'message' => $e->getMessage(),
        ]);
    }
}

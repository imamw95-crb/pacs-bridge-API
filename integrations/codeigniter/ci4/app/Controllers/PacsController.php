<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Pacs;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Contoh controller endpoint PACS untuk SIMRS (CodeIgniter 4).
 *
 * Routes (app/Config/Routes.php):
 *   $routes->get('pacs/health', 'PacsController::health');
 *   $routes->get('pacs/worklists', 'PacsController::worklists');
 *   $routes->post('pacs/worklists', 'PacsController::createWorklist');
 *   $routes->delete('pacs/worklists/(:segment)', 'PacsController::deleteWorklist/$1');
 *   $routes->get('pacs/studies/(:segment)/jpg', 'PacsController::jpg/$1');
 *   $routes->get('pacs/studies/(:segment)/png', 'PacsController::png/$1');
 */
class PacsController extends Controller
{
    private Pacs $pacs;

    public function __construct()
    {
        $this->pacs = new Pacs(config('Pacs'));
    }

    public function health(): ResponseInterface
    {
        try {
            return $this->response->setJSON($this->pacs->health());
        } catch (Throwable $e) {
            return $this->response->setStatusCode(502)->setJSON([
                'error'   => 'PACS tidak dapat dihubungi',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function worklists(): ResponseInterface
    {
        return $this->response->setJSON($this->pacs->orders());
    }

    public function createWorklist(): ResponseInterface
    {
        $input = $this->request->getJSON(true) ?? [];

        try {
            $result = $this->pacs->createOrder($input);

            return $this->response->setStatusCode(201)->setJSON($result);
        } catch (Throwable $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'error'   => 'Gagal membuat order',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function deleteWorklist(string $id): ResponseInterface
    {
        $this->pacs->deleteOrder($id);

        return $this->response->setJSON(['deleted' => $id]);
    }

    public function jpg(string $accession): ResponseInterface
    {
        $quality = (int) ($this->request->getGet('quality') ?? 90);
        $image = $this->pacs->jpegByAccession($accession, $quality);

        if ($image === null) {
            return $this->response->setStatusCode(404)->setJSON([
                'error' => 'Gambar tidak ditemukan untuk accession ' . $accession,
            ]);
        }

        return $this->response
            ->setHeader('Content-Type', $image['mime'])
            ->setHeader('Content-Disposition', 'inline; filename="' . $image['filename'] . '"')
            ->setBody($image['content']);
    }

    public function png(string $accession): ResponseInterface
    {
        $width = $this->request->getGet('width');
        $image = $this->pacs->pngByAccession($accession, $width !== null ? (int) $width : null);

        if ($image === null) {
            return $this->response->setStatusCode(404)->setJSON([
                'error' => 'Gambar tidak ditemukan untuk accession ' . $accession,
            ]);
        }

        return $this->response
            ->setHeader('Content-Type', $image['mime'])
            ->setHeader('Content-Disposition', 'inline; filename="' . $image['filename'] . '"')
            ->setBody($image['content']);
    }
}

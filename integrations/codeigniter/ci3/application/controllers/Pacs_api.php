<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Contoh controller CodeIgniter 3 yang memanggil **Bridge API** (X-API-Key) —
 * SIMRS tidak mengakses Orthanc langsung.
 *
 * routes.php:
 *   $route['pacs-api/health']                  = 'pacs_api/health';
 *   $route['pacs-api/accession']               = 'pacs_api/accession';
 *   $route['pacs-api/worklists']               = 'pacs_api/worklists';
 *   $route['pacs-api/worklists/create']        = 'pacs_api/create_worklist';
 *   $route['pacs-api/worklists/(:any)/delete'] = 'pacs_api/delete_worklist/$1';
 *   $route['pacs-api/studies/recent']          = 'pacs_api/recent';
 *   $route['pacs-api/studies/(:any)/jpg']      = 'pacs_api/jpg/$1';
 *   $route['pacs-api/studies/(:any)/png']      = 'pacs_api/png/$1';
 *   $route['pacs-api/studies/(:any)/zip-jpg']  = 'pacs_api/zip_jpg/$1';
 *   $route['pacs-api/studies/(:any)/zip']      = 'pacs_api/zip_dicom/$1';
 */
class Pacs_api extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('pacs_api');
    }

    public function health()
    {
        $this->json($this->pacs_api->health(), $this->pacs_api->error_message() === '' ? 200 : 502);
    }

    public function accession()
    {
        $accession = $this->pacs_api->next_accession();
        if ($accession === false) {
            $this->json(['error' => $this->pacs_api->error_message()], 502);

            return;
        }

        $this->json(['accession_number' => $accession]);
    }

    public function worklists()
    {
        $this->json($this->pacs_api->worklists());
    }

    public function create_worklist()
    {
        $input = json_decode((string) $this->input->raw_input_stream, true);
        if (!is_array($input)) {
            $input = $this->input->post() ?: [];
        }

        $result = $this->pacs_api->create_worklist($input);
        if ($result === false) {
            $this->json(['error' => 'Gagal membuat order', 'message' => $this->pacs_api->error_message()], 400);

            return;
        }

        $this->json($result, 201);
    }

    public function delete_worklist($id)
    {
        $ok = $this->pacs_api->delete_worklist($id);

        $this->json(
            $ok ? ['deleted' => $id] : ['error' => $this->pacs_api->error_message()],
            $ok ? 200 : 400
        );
    }

    public function recent()
    {
        $date = (string) ($this->input->get('date') ?: 'today');
        $limit = (int) ($this->input->get('limit') ?: 12);
        $modality = (string) ($this->input->get('modality') ?: '');

        $data = $this->pacs_api->recent_studies($date, $limit, $modality);
        $this->json($data, $this->pacs_api->error_message() === '' ? 200 : 502);
    }

    public function jpg($accession)
    {
        $quality = (int) ($this->input->get('quality') ?: 90);
        $watermark = (string) ($this->input->get('watermark') ?? '0') === '1';
        $file = $this->pacs_api->jpeg_by_accession($accession, $quality, $watermark);

        $this->send_file($file, $accession);
    }

    public function png($accession)
    {
        $width = $this->input->get('width');
        $file = $this->pacs_api->png_by_accession($accession, $width !== null ? (int) $width : null);

        $this->send_file($file, $accession);
    }

    public function zip_jpg($accession)
    {
        $quality = (int) ($this->input->get('quality') ?: 90);
        $watermark = ! in_array(
            strtolower((string) ($this->input->get('watermark') ?? '1')),
            ['0', 'false', 'no', 'off'],
            true
        );

        $file = $this->pacs_api->zip_jpg_by_accession($accession, $quality, $watermark);

        $this->send_file($file, $accession, true);
    }

    public function zip_dicom($accession)
    {
        $content = $this->pacs_api->zip_by_accession($accession);

        if ($content === null) {
            $this->json([
                'error'   => 'Study tidak ditemukan untuk ' . $accession,
                'message' => $this->pacs_api->error_message(),
            ], 404);

            return;
        }

        $this->output
            ->set_content_type('application/zip')
            ->set_header('Content-Disposition: attachment; filename="' . $accession . '.zip"')
            ->set_output($content);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): void
    {
        $this->output
            ->set_status_header($status)
            ->set_content_type('application/json')
            ->set_output(json_encode($data));
    }

    /**
     * @param array<string, mixed>|null $file
     */
    private function send_file($file, string $accession, bool $attachment = false): void
    {
        if ($file === null) {
            $this->json([
                'error'   => 'Gambar tidak ditemukan untuk accession ' . $accession,
                'message' => $this->pacs_api->error_message(),
            ], 404);

            return;
        }

        $this->output
            ->set_content_type((string) ($file['mime'] ?? 'application/octet-stream'))
            ->set_header(
                'Content-Disposition: '
                . ($attachment ? 'attachment' : 'inline')
                . '; filename="' . ($file['filename'] ?? 'download.bin') . '"'
            )
            ->set_output((string) $file['content']);
    }
}

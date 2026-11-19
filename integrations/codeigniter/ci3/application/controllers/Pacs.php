<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Contoh controller endpoint PACS untuk SIMRS (CodeIgniter 3).
 *
 * routes.php:
 *   $route['pacs/health']                  = 'pacs/health';
 *   $route['pacs/accession']               = 'pacs/accession';
 *   $route['pacs/worklists']               = 'pacs/worklists';
 *   $route['pacs/worklists/create']        = 'pacs/create_worklist';
 *   $route['pacs/worklists/(:any)/delete'] = 'pacs/delete_worklist/$1';
 *   $route['pacs/studies/recent']          = 'pacs/recent';
 *   $route['pacs/studies/(:any)/jpg']      = 'pacs/jpg/$1';
 *   $route['pacs/studies/(:any)/png']      = 'pacs/png/$1';
 *   $route['pacs/studies/(:any)/zip-jpg']  = 'pacs/zip_jpg/$1';
 *   $route['pacs/studies/(:any)/zip']      = 'pacs/zip_dicom/$1';
 */
class Pacs extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('pacs_bridge');
    }

    public function health()
    {
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($this->pacs_bridge->health()));
    }

    public function worklists()
    {
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($this->pacs_bridge->orders()));
    }

    public function accession()
    {
        $accession = $this->pacs_bridge->next_accession();

        if ($accession === false) {
            $this->output
                ->set_status_header(502)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => $this->pacs_bridge->error_message()]));

            return;
        }

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['accession_number' => $accession]));
    }

    public function recent()
    {
        $date = (string) ($this->input->get('date') ?: 'today');
        $limit = (int) ($this->input->get('limit') ?: 12);
        $modality = (string) ($this->input->get('modality') ?: '');

        $studies = $this->pacs_bridge->recent_studies($date, $limit, $modality);
        if ($this->pacs_bridge->error_message() !== '') {
            $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => $this->pacs_bridge->error_message()]));

            return;
        }

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'date'     => $date,
                'limit'    => $limit,
                'modality' => $modality === '' ? 'all' : strtoupper($modality),
                'count'    => count($studies),
                'studies'  => $studies,
            ]));
    }

    public function create_worklist()
    {
        $input = json_decode($this->input->raw_input_stream, true);
        if (!is_array($input)) {
            $input = $this->input->post() ?: [];
        }

        $result = $this->pacs_bridge->create_order($input);
        if ($result === false) {
            $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error'   => 'Gagal membuat order',
                    'message' => $this->pacs_bridge->error_message(),
                ]));

            return;
        }

        $this->output
            ->set_status_header(201)
            ->set_content_type('application/json')
            ->set_output(json_encode($result));
    }

    public function delete_worklist($id)
    {
        $ok = $this->pacs_bridge->delete_order($id);

        $this->output
            ->set_status_header($ok ? 200 : 400)
            ->set_content_type('application/json')
            ->set_output(json_encode($ok
                ? ['deleted' => $id]
                : ['error' => $this->pacs_bridge->error_message()]));
    }

    public function jpg($accession)
    {
        $quality = (int) ($this->input->get('quality') ?: 90);
        $image = $this->pacs_bridge->jpeg_by_accession($accession, $quality);

        if ($image === null) {
            $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error'   => 'Gambar tidak ditemukan untuk accession ' . $accession,
                    'message' => $this->pacs_bridge->error_message(),
                ]));

            return;
        }

        $this->output
            ->set_content_type($image['mime'])
            ->set_header('Content-Disposition: inline; filename="' . $image['filename'] . '"')
            ->set_output($image['content']);
    }

    public function png($accession)
    {
        $width = $this->input->get('width');
        $image = $this->pacs_bridge->png_by_accession($accession, $width !== null ? (int) $width : null);

        if ($image === null) {
            $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error'   => 'Gambar tidak ditemukan untuk accession ' . $accession,
                    'message' => $this->pacs_bridge->error_message(),
                ]));

            return;
        }

        $this->output
            ->set_content_type($image['mime'])
            ->set_header('Content-Disposition: inline; filename="' . $image['filename'] . '"')
            ->set_output($image['content']);
    }

    public function zip_jpg($accession)
    {
        $quality = (int) ($this->input->get('quality') ?: 90);
        $watermark = ! in_array(
            strtolower((string) ($this->input->get('watermark') ?? '1')),
            ['0', 'false', 'no', 'off'],
            true
        );

        $archive = $this->pacs_bridge->zip_jpg_by_accession($accession, $quality, $watermark);

        if ($archive === null) {
            $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error'   => 'Tidak ada gambar untuk ' . $accession,
                    'message' => $this->pacs_bridge->error_message(),
                ]));

            return;
        }

        $this->output
            ->set_content_type('application/zip')
            ->set_header('Content-Disposition: attachment; filename="' . $archive['filename'] . '"')
            ->set_output($archive['content']);
    }

    public function zip_dicom($accession)
    {
        $content = $this->pacs_bridge->zip_by_accession($accession);

        if ($content === null) {
            $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error'   => 'Study tidak ditemukan untuk ' . $accession,
                    'message' => $this->pacs_bridge->error_message(),
                ]));

            return;
        }

        $this->output
            ->set_content_type('application/zip')
            ->set_header('Content-Disposition: attachment; filename="' . $accession . '.zip"')
            ->set_output($content);
    }
}

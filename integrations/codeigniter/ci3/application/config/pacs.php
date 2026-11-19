<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Konfigurasi bridge PACS (CodeIgniter 3).
 * Salin ke application/config/pacs.php
 */
$config['pacs_root']   = '';             // kosong = application/third_party/pacs
$config['server_ip']   = '10.4.0.10';
$config['http_port']   = 8042;
$config['dicom_port']  = 4242;
$config['called_aet']  = 'ORTHANC';
$config['calling_aet'] = 'LEAD_CLIENT';
$config['modality']    = 'DX';
$config['timeout']     = 30;

// Basic auth Orthanc (biarkan null bila autentikasi server mati)
$config['user']        = null;
$config['password']    = null;

// Bila diisi, controller bridge mewajibkan header X-API-Key
$config['api_key']     = '';

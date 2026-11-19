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

// Base URL program Bridge API (mode API — SIMRS tidak mengakses Orthanc langsung)
$config['bridge_url']     = 'http://10.4.0.10';
$config['bridge_timeout'] = 60;

// API key Bridge API (dipakai Pacs_api / Simrs\Pacs\BridgeApiClient).
// Library Pacs_bridge memanggil REST Orthanc langsung, jadi tidak memakai key ini.
$config['api_key']     = '';

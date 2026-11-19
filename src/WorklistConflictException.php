<?php

declare(strict_types=1);

namespace Simrs\Pacs;

/**
 * Konflik data order — mis. AccessionNumber sudah dipakai pasien lain.
 *
 * Dilempar WorklistService::create() dan diterjemahkan router menjadi HTTP 409
 * dengan `code: ACCESSION_CONFLICT`.
 */
final class WorklistConflictException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'ACCESSION_CONFLICT')
    {
        parent::__construct($message);
    }
}

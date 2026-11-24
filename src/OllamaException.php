<?php

declare(strict_types=1);

namespace Simrs\Pacs;

use RuntimeException;

/**
 * Kegagalan saat memanggil server Ollama (koneksi, timeout, atau respons error).
 * `statusCode` = 0 bila server tidak bisa dihubungi sama sekali.
 */
final class OllamaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly string $responseBody = '',
    ) {
        parent::__construct($message, $statusCode);
    }
}

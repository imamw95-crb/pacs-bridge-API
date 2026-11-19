<?php

declare(strict_types=1);

namespace Simrs\Pacs;

final class BridgeApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly string $responseBody = '',
    ) {
        parent::__construct($message, $statusCode);
    }
}

<?php

declare(strict_types=1);

namespace Cms\Mcp;

use RuntimeException;

final class RpcError extends RuntimeException
{
    public function __construct(int $code, string $message, public readonly mixed $data = null)
    {
        parent::__construct($message, $code);
    }
}

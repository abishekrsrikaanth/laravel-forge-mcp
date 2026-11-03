<?php

declare(strict_types=1);

namespace App\Forge;

use RuntimeException;

class ForgeNotConnectedException extends RuntimeException
{
    public function __construct(string $message = 'Forge is not connected. Add your Forge API token in the Forge MCP dashboard.')
    {
        parent::__construct($message);
    }
}

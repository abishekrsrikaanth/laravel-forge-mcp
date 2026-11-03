<?php

use App\Mcp\Servers\ForgeServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', ForgeServer::class)->middleware(['auth:sanctum', 'abilities:mcp', 'throttle:mcp']);

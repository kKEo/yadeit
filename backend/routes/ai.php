<?php

use App\Http\Middleware\EnterAgentWorkspace;
use App\Mcp\YadeitServer;
use Laravel\Mcp\Facades\Mcp;

// The MCP endpoint for agents. An agent sends its access token as a bearer token;
// the workspace is the agent's own, so it is not part of the address.
Mcp::web('/mcp', YadeitServer::class)
    ->middleware(['auth:sanctum', 'throttle:mcp', EnterAgentWorkspace::class]);

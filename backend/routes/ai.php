<?php

use App\Http\Middleware\ActAsConnectedAgent;
use App\Http\Middleware\AuthenticateMcp;
use App\Http\Middleware\EnterAgentWorkspace;
use App\Http\Middleware\RequireOAuthKeys;
use App\Mcp\YadeitServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// What a remote MCP client needs to find out how to sign in, and to register itself (OAuth 2.1 with PKCE).
// Without signing keys there is no OAuth here, and these addresses answer 404.
Route::middleware([RequireOAuthKeys::class, 'throttle:30,1'])->group(fn () => Mcp::oauthRoutes());

// The MCP endpoint for agents. An agent sends its access token as a bearer token, or an app
// connected over OAuth sends its own and works as the agent its user chose. The workspace is
// the agent's own, so it is not part of the address.
Mcp::web('/mcp', YadeitServer::class)
    ->middleware([AuthenticateMcp::class, 'throttle:mcp', ActAsConnectedAgent::class, 'active', EnterAgentWorkspace::class]);

<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToSetup
{
    /**
     * Send page requests to the first-run setup screen until an owner exists.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldRedirect($request)) {
            return $next($request);
        }

        return redirect()->route('setup');
    }

    private function shouldRedirect(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        if ($request->routeIs('setup') || $request->is('livewire*', 'flux/*', 'up')) {
            return false;
        }

        return ! User::ownerExists();
    }
}

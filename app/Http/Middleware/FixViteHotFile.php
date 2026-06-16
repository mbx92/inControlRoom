<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FixViteHotFile
{
    /**
     * Ensure Vite hot file always uses IPv4 localhost.
     * Prevents broken asset URLs like http://[::1]:5173 in the browser.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // localhost and 127.0.0.1 are different origins — redirect to APP_URL host to avoid Vite CORS errors.
        if (app()->environment('local') && $request->getHost() === 'localhost') {
            $target = rtrim(config('app.url'), '/').$request->getRequestUri();

            return redirect()->to($target);
        }

        $hotFile = public_path('hot');

        if (is_file($hotFile)) {
            $url = trim((string) file_get_contents($hotFile));

            if (str_contains($url, '[::1]') || str_contains($url, 'localhost')) {
                file_put_contents($hotFile, 'http://127.0.0.1:5173');
            }
        }

        $response = $next($request);

        if (app()->environment('local')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}

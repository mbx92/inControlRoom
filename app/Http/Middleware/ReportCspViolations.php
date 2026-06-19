<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportCspViolations
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            $response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only')
        ) {
            return $response;
        }

        $endpoint = trim((string) config('glitchtip.security_endpoint', ''));
        $policy = trim((string) config('glitchtip.csp_policy', ''));

        if ($endpoint === '' || $policy === '') {
            return $response;
        }

        $headerName = config('glitchtip.csp_report_only', true)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        if (! str_contains($policy, 'report-uri')) {
            $policy = rtrim($policy, '; ').'; report-uri '.$endpoint.';';
        }

        $response->headers->set($headerName, $policy);

        return $response;
    }
}

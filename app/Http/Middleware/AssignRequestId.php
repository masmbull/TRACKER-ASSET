<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Give every request a correlation id so all ActivityLog rows written while
 * handling it can be tied together. An inbound X-Request-ID is accepted only
 * when it looks like a plain token - anything else is discarded, because this
 * value is written straight into the audit log and must not carry CRLF or
 * markup (OWASP log injection).
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-ID', '');
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', substr($incoming, 0, 64)) ?: '';

        $request->headers->set('X-Request-ID', $clean !== '' ? $clean : (string) Str::uuid());

        return $next($request);
    }
}

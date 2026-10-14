<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conditional GET for the JSON API: every successful GET carries an ETag, and a request that sends the
 * same ETag back (If-None-Match) gets an empty 304. The browser then reuses its stored copy, so unchanged
 * data costs almost nothing to re-check. Responses are private (per login) and must be revalidated.
 */
class ConditionalGet
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$request->isMethod('GET') || $response->getStatusCode() !== 200 || !($response instanceof JsonResponse)) {
            return $response;
        }

        $etag = '"' . md5((string) $response->getContent()) . '"';
        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
        $response->headers->set('Vary', 'Authorization, Accept, Origin');

        $sent = array_map('trim', explode(',', (string) $request->header('If-None-Match')));
        if (in_array($etag, $sent, true)) {
            $response->setNotModified();
        }

        return $response;
    }
}
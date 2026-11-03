<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In production, redirects requests that arrive on another host name
 * (e.g. waveteam.lk/app instead of www.waveteam.lk/app) to the APP_URL host,
 * so sign-in cookies and email links always use one address.
 */
class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonical = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (app()->isProduction() && $canonical && strcasecmp($request->getHost(), $canonical) !== 0
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            $root = rtrim((string) config('app.url'), '/');

            return redirect()->away($root.substr($request->getRequestUri(), strlen($request->getBaseUrl())), 301);
        }

        return $next($request);
    }
}

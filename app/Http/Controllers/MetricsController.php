<?php

namespace App\Http\Controllers;

use App\Services\Metrics\MetricsExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * GET /metrics (ADR-022): the Prometheus scrape endpoint.
 *
 * Off (404) unless QUERYPROXY_METRICS_TOKEN or QUERYPROXY_METRICS_ALLOWED_IPS
 * is set. Once on, a request passes with the right Bearer token or from an
 * allowed address; anything else gets 401. The route runs outside the web
 * group: a scrape needs no session, cookies or CSRF.
 */
class MetricsController extends Controller
{
    public function __invoke(Request $request, MetricsExporter $exporter): Response
    {
        $token = (string) config('queryproxy.metrics.token');
        $allowedIps = array_values(array_filter((array) config('queryproxy.metrics.allowed_ips')));

        abort_if($token === '' && $allowedIps === [], 404);

        if (! $this->mayScrape($request, $token, $allowedIps)) {
            return response('Unauthorized', 401, array_filter([
                'Content-Type' => 'text/plain; charset=utf-8',
                'WWW-Authenticate' => $token !== '' ? 'Bearer realm="metrics"' : null,
                'Cache-Control' => 'no-store',
            ]));
        }

        return response($exporter->render(), 200, [
            'Content-Type' => MetricsExporter::CONTENT_TYPE,
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @param  list<string>  $allowedIps  addresses or CIDR ranges
     */
    private function mayScrape(Request $request, string $token, array $allowedIps): bool
    {
        if ($token !== '') {
            $given = (string) $request->bearerToken();

            // Hashed first so the comparison does not depend on the token's length.
            if ($given !== '' && hash_equals(hash('sha256', $token), hash('sha256', $given))) {
                return true;
            }
        }

        $ip = $request->ip();

        return $allowedIps !== [] && $ip !== null && IpUtils::checkIp($ip, $allowedIps);
    }
}

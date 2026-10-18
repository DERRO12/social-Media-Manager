<?php

namespace App\Services\Seo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches public web pages for the SEO checker without letting users reach private or internal
 * addresses (this server, its database, the cloud metadata service, the local network):
 * only http/https, every hostname resolved and checked, the checked IP pinned for the request,
 * and redirects followed manually so each hop is checked again.
 */
class SafeFetcher
{
    private const MAX_REDIRECTS = 5;

    private const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @return array{response: Response, url: string, seconds: float}
     */
    public function get(string $url): array
    {
        $started = microtime(true);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = $this->resolve($url);

            try {
                $response = Http::withoutRedirecting()
                    ->timeout(15)
                    ->connectTimeout(8)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; '.config('app.name').' SEO checker)', 'Accept' => 'text/html,*/*'])
                    // Connect to the IP we just checked, so DNS can't change between check and request.
                    ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])
                    ->get($url);
            } catch (ConnectionException $e) {
                throw new RuntimeException('We couldn’t connect to that website. Check the address and that the site is online.');
            }

            if ($response->redirect() && ($location = $response->header('Location'))) {
                $url = $this->absolute($location, $url);

                continue;
            }

            if (strlen($response->body()) > self::MAX_BYTES) {
                throw new RuntimeException('That page is too large to check (over 5 MB).');
            }

            return ['response' => $response, 'url' => $url, 'seconds' => round(microtime(true) - $started, 2)];
        }

        throw new RuntimeException('That address redirects too many times.');
    }

    /** @return array{0: string, 1: int, 2: string} host, port, a safe public IP */
    private function resolve(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Enter a full website address starting with http:// or https://');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Addresses with a username or password can’t be checked.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443, 8080, 8443], true)) {
            throw new RuntimeException('Only websites on standard ports can be checked.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->lookup($host);

        if (! $ips) {
            throw new RuntimeException('We couldn’t find that website. Check the address.');
        }

        foreach ($ips as $ip) {
            if (! $this->isPublic($ip)) {
                throw new RuntimeException('That address points to a private network and can’t be checked.');
            }
        }

        return [$host, $port, $ips[0]];
    }

    /** @return string[] */
    protected function lookup(string $host): array
    {
        $ips = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        return array_values(array_filter($ips));
    }

    public function isPublic(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            // Also block carrier-grade NAT and IPv6 unique-local/link-local, which the flags above may miss.
            && ! preg_match('/^(100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|0\.|fc|fd|fe80:)/i', $ip);
    }

    private function absolute(string $location, string $base): string
    {
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }

        $b = parse_url($base);
        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');

        if (str_starts_with($location, '//')) {
            return $b['scheme'].':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = isset($b['path']) ? preg_replace('~/[^/]*$~', '/', $b['path']) : '/';

        return $origin.$dir.$location;
    }
}

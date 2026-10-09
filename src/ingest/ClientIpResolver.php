<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

/**
 * Resolves the visitor's IP behind proxies (Cloudflare Tunnel, Kubernetes
 * ingress, Docker Desktop's NAT), in this order:
 *
 *   1. CF-Connecting-IP   (Cloudflare / cloudflared)
 *   2. X-Forwarded-For    (leftmost valid address)
 *   3. X-Real-IP
 *   4. the socket's remote address
 *
 * Headers are only consulted when the deployment says a trusted proxy sits in
 * front (SNOWPRINT_TRUST_PROXY=true); otherwise any visitor could forge them.
 * Invalid header values are skipped, never used.
 *
 * Privacy: the IP is only returned to the caller (visitor hash, GeoIP) and is
 * never logged; only the source it came from is reported.
 */
final class ClientIpResolver {

    public const SOURCE_CLOUDFLARE = 'cf-connecting-ip';
    public const SOURCE_FORWARDED_FOR = 'x-forwarded-for';
    public const SOURCE_REAL_IP = 'x-real-ip';
    public const SOURCE_SOCKET = 'socket';
    public const SOURCE_NONE = 'none';

    /**
     * @param \Closure(string): ?string $header header lookup by name
     * @return array{ip: string, source: string, private: bool}
     */
    public static function resolve(\Closure $header, ?string $remoteAddr, bool $trustProxy): array {
        if ($trustProxy) {
            $candidates = [
                self::SOURCE_CLOUDFLARE => self::single($header('CF-Connecting-IP')),
                self::SOURCE_FORWARDED_FOR => self::leftmost($header('X-Forwarded-For')),
                self::SOURCE_REAL_IP => self::single($header('X-Real-IP')),
            ];
            foreach ($candidates as $source => $ip) {
                if ($ip !== null) {
                    return ['ip' => $ip, 'source' => $source, 'private' => self::isPrivate($ip)];
                }
            }
        }
        $socket = self::single($remoteAddr);
        if ($socket !== null) {
            return ['ip' => $socket, 'source' => self::SOURCE_SOCKET, 'private' => self::isPrivate($socket)];
        }
        return ['ip' => '', 'source' => self::SOURCE_NONE, 'private' => false];
    }

    /** Loopback, private (RFC 1918 / ULA) and reserved addresses: a local or internal hop. */
    public static function isPrivate(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** First valid address of a comma-separated list ("client, proxy1, proxy2"). */
    private static function leftmost(?string $value): ?string {
        if ($value === null) {
            return null;
        }
        foreach (explode(',', $value) as $part) {
            $ip = self::single($part);
            if ($ip !== null) {
                return $ip;
            }
        }
        return null;
    }

    /** One address, trimmed; tolerates "1.2.3.4:5678" and "[2001:db8::1]:443". */
    private static function single(?string $value): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $value, $m)) {
            $value = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $value, $m)) {
            $value = $m[1];
        }
        return filter_var($value, FILTER_VALIDATE_IP) !== false ? strtolower($value) : null;
    }
}

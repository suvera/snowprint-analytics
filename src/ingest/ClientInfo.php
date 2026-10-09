<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\ingest;

use dev\winterframework\web\http\HttpHeaders;
use dev\winterframework\web\http\HttpRequest;

/**
 * Request facts used for hashing and filtering. Lives only for the request;
 * never logged or stored.
 */
final class ClientInfo {

    public function __construct(
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly bool $doNotTrack,
        public readonly string $ipSource = ClientIpResolver::SOURCE_SOCKET,
        public readonly bool $privateIp = false,
    ) {
    }

    /**
     * @param bool $trustProxy read CF-Connecting-IP / X-Forwarded-For / X-Real-IP
     *   (only safe behind a proxy that sets them, e.g. cloudflared or an ingress)
     */
    public static function from(HttpRequest $request, bool $trustProxy): self {
        $resolved = ClientIpResolver::resolve(
            static fn(string $name): ?string => $request->getFirstHeader($name),
            $request->getRemoteAddr(),
            $trustProxy,
        );
        return new self(
            $resolved['ip'],
            mb_substr($request->getFirstHeader(HttpHeaders::USER_AGENT) ?? '', 0, 1024),
            $request->getFirstHeader('Dnt') === '1',
            $resolved['source'],
            $resolved['private'],
        );
    }
}

<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use erikwang2013\apidoc\protocol\debug\Support\HttpClient;

/**
 * HTTP/2 中继:https 走 ALPN,http 走 h2c prior knowledge;curl 无 HTTP/2 时明确失败
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class Http2Driver extends HttpRelayDriver
{
    public function name(): string
    {
        return 'http2';
    }

    public static function available(): bool
    {
        return HttpClient::http2Available();
    }

    protected function httpOptions(string $scheme): array
    {
        return ['httpVersion' => 2, 'http2PriorKnowledge' => $scheme === 'http'];
    }

    protected function unavailableError(string $scheme): string
    {
        return static::available() ? '' : 'HTTP/2 unavailable: curl was built without HTTP/2 support';
    }
}

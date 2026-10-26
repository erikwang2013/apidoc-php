<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use erikwang2013\apidoc\protocol\debug\Support\HttpClient;

/**
 * HTTP/3 中继:仅 https(curl 用 CURL_HTTP_VERSION_3);curl 无 HTTP/3 时明确失败
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class Http3Driver extends HttpRelayDriver
{
    public function name(): string
    {
        return 'http3';
    }

    public static function available(): bool
    {
        return HttpClient::http3Available();
    }

    protected function httpOptions(string $scheme): array
    {
        return ['httpVersion' => 3];
    }

    protected function unavailableError(string $scheme): string
    {
        if ($scheme !== 'https') {
            return 'HTTP/3 requires an https:// target';
        }
        return static::available() ? '' : 'HTTP/3 unavailable: curl was built without HTTP/3 support';
    }
}

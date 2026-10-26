<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\debug\Driver;

use InvalidArgumentException;
use erikwang2013\apidoc\protocol\debug\DebugDriverInterface;
use erikwang2013\apidoc\protocol\debug\DebugTarget;
use erikwang2013\apidoc\protocol\debug\Support\HttpClient;
use erikwang2013\apidoc\protocol\debug\Support\TargetGuard;

/**
 * HLS 驱动:GET m3u8 并解析 master/media playlist,可选只取分片大小与哈希
 * @package erikwang2013\apidoc\protocol\debug\Driver
 */
class HlsDriver implements DebugDriverInterface
{
    public function name(): string
    {
        return 'hls';
    }

    public function mode(): string
    {
        return 'relay-unary';
    }

    public function fields(array $context): array
    {
        return [
            ['name' => 'fetchSegment', 'in' => 'meta', 'type' => 'boolean', 'require' => false, 'default' => false, 'desc' => 'Fetch the first segment and return size/sha256 instead of media bytes'],
        ];
    }

    /**
     * 解析 m3u8:master 只看 #EXT-X-STREAM-INF,media 的 duration 与 segments 按下标对齐
     * @return array{master: bool, variants: array, segments: string[], durations: array, endlist: bool}
     */
    public function parsePlaylist(string $text): array
    {
        $master = false;
        $variants = [];
        $segments = [];
        $durations = [];
        $endlist = false;
        $pendingDuration = null;
        $pendingVariant = null;
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (strpos($line, '#EXT-X-STREAM-INF:') === 0) {
                $master = true;
                $attributes = $this->parseAttributes(substr($line, strlen('#EXT-X-STREAM-INF:')));
                $pendingVariant = [
                    'name' => isset($attributes['NAME']) ? $attributes['NAME'] : null,
                    'resolution' => isset($attributes['RESOLUTION']) ? $attributes['RESOLUTION'] : null,
                    'bandwidth' => isset($attributes['BANDWIDTH']) && is_numeric($attributes['BANDWIDTH']) ? (int)$attributes['BANDWIDTH'] : null,
                    'uri' => null,
                ];
                continue;
            }
            if (strpos($line, '#EXTINF:') === 0) {
                $value = substr($line, strlen('#EXTINF:'));
                $comma = strpos($value, ',');
                if ($comma !== false) {
                    $value = substr($value, 0, $comma);
                }
                $pendingDuration = is_numeric(trim($value)) ? (float)trim($value) : null;
                continue;
            }
            if ($line === '#EXT-X-ENDLIST') {
                $endlist = true;
                continue;
            }
            if ($line[0] === '#') {
                continue;
            }
            if ($pendingVariant !== null) {
                $pendingVariant['uri'] = $line;
                $variants[] = $pendingVariant;
                $pendingVariant = null;
                continue;
            }
            $segments[] = $line;
            $durations[] = $pendingDuration;
            $pendingDuration = null;
        }
        return [
            'master' => $master,
            'variants' => $variants,
            'segments' => $segments,
            'durations' => $durations,
            'endlist' => $endlist,
        ];
    }

    public function send(array $input, array $context): array
    {
        $start = microtime(true);
        try {
            $target = DebugTarget::parse((string)($context['target'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!in_array($target['scheme'], ['http', 'https'], true)) {
            return ['ok' => false, 'error' => 'hls 驱动仅支持 http/https 目标'];
        }
        $fetchSegment = $this->boolField($input['fetchSegment'] ?? false);
        if ($fetchSegment === null) {
            return ['ok' => false, 'error' => 'fetchSegment 必须是布尔值'];
        }
        $timeout = (float)($context['timeout'] ?? 5);
        if ($timeout <= 0) {
            $timeout = 5.0;
        }
        $maxBytes = max(1, (int)($context['maxBytes'] ?? 1048576));
        $url = trim((string)$context['target']);
        $playlist = HttpClient::request(array_merge([
            'url' => $url,
            'method' => 'GET',
            'timeout' => $timeout,
            'maxBytes' => $maxBytes,
        ], TargetGuard::httpOptions($target, $context)));
        if (isset($playlist['error'])) {
            return ['ok' => false, 'error' => $playlist['error']];
        }
        $status = (int)($playlist['status'] ?? 0);
        if ($status < 200 || $status >= 400) {
            return ['ok' => false, 'error' => 'hls playlist request failed: HTTP ' . $status];
        }
        $parsed = $this->parsePlaylist((string)($playlist['body'] ?? ''));
        $json = json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return ['ok' => false, 'error' => 'failed to encode hls playlist'];
        }
        $meta = [
            'durationMs' => (int)round((microtime(true) - $start) * 1000),
            'bytes' => strlen((string)($playlist['body'] ?? '')),
            'status' => $status,
            'master' => $parsed['master'],
            'variants' => count($parsed['variants']),
            'segments' => count($parsed['segments']),
            'endlist' => $parsed['endlist'],
        ];
        // master playlist 不取 variant;media playlist 只取首个分片的大小/哈希
        if ($fetchSegment && $parsed['segments'] !== []) {
            $segmentUrl = $this->resolveUrl($url, $parsed['segments'][0]);
            if (!HttpClient::sameOrigin($url, $segmentUrl)) {
                return ['ok' => false, 'error' => 'cross-host segment blocked'];
            }
            $segment = HttpClient::request(array_merge([
                'url' => $segmentUrl,
                'method' => 'GET',
                'timeout' => $timeout,
                'maxBytes' => $maxBytes,
            ], TargetGuard::httpOptions($target, $context)));
            if (isset($segment['error'])) {
                return ['ok' => false, 'error' => 'hls segment fetch failed: ' . $segment['error']];
            }
            $segmentStatus = (int)($segment['status'] ?? 0);
            if ($segmentStatus < 200 || $segmentStatus >= 400) {
                return ['ok' => false, 'error' => 'hls segment fetch failed: HTTP ' . $segmentStatus];
            }
            $segmentBody = (string)($segment['body'] ?? '');
            $declared = isset($segment['headers']['content-length']) ? (int)$segment['headers']['content-length'] : 0;
            // shortcut: 超过 max_bytes 时只哈希截断前缀,truncated 标记提示
            $meta['segment'] = [
                'uri' => $segmentUrl,
                'bytes' => strlen($segmentBody),
                'sha256' => hash('sha256', $segmentBody),
                'truncated' => $declared > strlen($segmentBody),
            ];
        }
        return ['ok' => true, 'body' => $json, 'meta' => $meta];
    }

    /**
     * @return array<string, string>
     */
    protected function parseAttributes(string $text): array
    {
        $attributes = [];
        if (preg_match_all('/([A-Za-z0-9-]+)=("[^"]*"|[^,]*)/', $text, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $value = $match[2];
                if (strlen($value) >= 2 && $value[0] === '"') {
                    $value = substr($value, 1, -1);
                }
                $attributes[strtoupper($match[1])] = trim($value);
            }
        }
        return $attributes;
    }

    protected function resolveUrl(string $base, string $uri): string
    {
        if (preg_match('#^https?://#i', $uri) === 1) {
            return $uri;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $uri;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (strpos($uri, '//') === 0) {
            return $parts['scheme'] . ':' . $uri;
        }
        if (strpos($uri, '/') === 0) {
            return $origin . $uri;
        }
        $path = isset($parts['path']) ? (string)$parts['path'] : '/';
        return $origin . substr($path, 0, (int)strrpos($path, '/') + 1) . $uri;
    }

    protected function boolField($raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if ($raw === 0 || $raw === '0' || $raw === 'false') {
            return false;
        }
        if ($raw === 1 || $raw === '1' || $raw === 'true') {
            return true;
        }
        return null;
    }
}

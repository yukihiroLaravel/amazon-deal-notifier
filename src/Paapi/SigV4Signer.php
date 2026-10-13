<?php

declare(strict_types=1);

namespace DealRadar\Paapi;

use DateTimeImmutable;
use DateTimeZone;

/**
 * AWS Signature Version 4 の署名処理。
 *
 * PA-API 5.0 は AWS SigV4 で認証する。SDK に頼らず実装し、
 * AWS 公式テストスイート（tests/fixtures/aws4_testsuite）で正しさを検証している。
 */
final class SigV4Signer
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service,
        private readonly ?string $sessionToken = null,
    ) {
    }

    /**
     * @param list<array{0: string, 1: string}> $headers 同名ヘッダーの重複を表現できるよう [名前, 値] の配列で受け取る
     */
    public function sign(
        string $method,
        string $path,
        string $query,
        array $headers,
        string $payload,
        DateTimeImmutable $time,
    ): SignedRequest {
        $utc = $time->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $utc->format('Ymd\THis\Z');
        $date = $utc->format('Ymd');

        if (!self::hasHeader($headers, 'x-amz-date')) {
            $headers[] = ['X-Amz-Date', $amzDate];
        }
        if ($this->sessionToken !== null && !self::hasHeader($headers, 'x-amz-security-token')) {
            $headers[] = ['X-Amz-Security-Token', $this->sessionToken];
        }

        [$canonicalHeaders, $signedHeaders] = self::canonicalHeaders($headers);
        $canonicalRequest = implode("\n", [
            strtoupper($method),
            self::canonicalUri($path),
            self::canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $payload),
        ]);

        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKey,
            $scope,
            $signedHeaders,
            $signature,
        );

        $headers[] = ['Authorization', $authorization];

        return new SignedRequest($canonicalRequest, $stringToSign, $signature, $authorization, $headers);
    }

    private function signingKey(string $date): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /** パスを正規化（. / .. / 連続スラッシュを解決）し、セグメントごとに RFC 3986 でエンコードする */
    public static function canonicalUri(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = rawurlencode($segment);
        }

        $uri = '/' . implode('/', $segments);
        $endsWithSlash = str_ends_with($path, '/') || str_ends_with($path, '/.') || str_ends_with($path, '/..');
        if ($endsWithSlash && $uri !== '/') {
            $uri .= '/';
        }

        return $uri;
    }

    public static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($key)), rawurlencode(rawurldecode($value))];
        }

        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn (array $p): string => $p[0] . '=' . $p[1], $pairs));
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     * @return array{0: string, 1: string} [正規化ヘッダー, 署名対象ヘッダー名]
     */
    public static function canonicalHeaders(array $headers): array
    {
        $merged = [];
        foreach ($headers as [$name, $value]) {
            $key = strtolower(trim($name));
            $normalized = trim((string) preg_replace('/\s+/', ' ', $value));
            $merged[$key] = isset($merged[$key]) ? $merged[$key] . ',' . $normalized : $normalized;
        }
        ksort($merged, SORT_STRING);

        $lines = '';
        foreach ($merged as $key => $value) {
            $lines .= "{$key}:{$value}\n";
        }

        return [$lines, implode(';', array_keys($merged))];
    }

    /** @param list<array{0: string, 1: string}> $headers */
    private static function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as [$headerName]) {
            if (strtolower($headerName) === $name) {
                return true;
            }
        }

        return false;
    }
}

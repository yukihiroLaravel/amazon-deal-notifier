<?php

declare(strict_types=1);

namespace DealRadar\Paapi;

use DateTimeImmutable;
use DateTimeZone;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * AWS が公開している SigV4 テストスイートを読み込み、SigV4Signer の出力と突き合わせる。
 *
 * 各ベクターは .req（元リクエスト）と、期待値の .creq（正規リクエスト）／
 * .sts（署名対象文字列）／.authz（Authorization ヘッダー）で構成される。
 */
final class SigV4TestSuite
{
    // テストスイート共通の認証情報（AWS が公開している例示用の値）
    public const ACCESS_KEY = 'AKIDEXAMPLE';
    public const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    public const REGION = 'us-east-1';
    public const SERVICE = 'service';
    public const SESSION_TOKEN = '6e86291e8372ff2a2260956d9b8aae1d763fbf315fa00fa31553b73ebf194267';

    public function __construct(private readonly string $directory)
    {
    }

    public static function default(): self
    {
        return new self(dirname(__DIR__, 2) . '/tests/fixtures/aws4_testsuite');
    }

    /** @return array<string, string> ベクター名 => ディレクトリ */
    public function vectors(): array
    {
        $vectors = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'req') {
                continue;
            }
            $name = $file->getBasename('.req');
            $vectors[$name] = $file->getPath();
        }
        ksort($vectors);

        return $vectors;
    }

    /** @return list<SigV4VectorResult> */
    public function run(): array
    {
        $results = [];
        foreach ($this->vectors() as $name => $dir) {
            $results[] = $this->runVector($name, $dir);
        }

        return $results;
    }

    public function runVector(string $name, string $dir): SigV4VectorResult
    {
        $raw = (string) file_get_contents("{$dir}/{$name}.req");
        [$method, $path, $query, $headers, $body] = self::parseRequest($raw);

        $signer = new SigV4Signer(
            self::ACCESS_KEY,
            self::SECRET_KEY,
            self::REGION,
            self::SERVICE,
            $name === 'get-vanilla-with-session-token' ? self::SESSION_TOKEN : null,
        );
        $signed = $signer->sign($method, $path, $query, $headers, $body, new DateTimeImmutable('2015-08-30T12:36:00', new DateTimeZone('UTC')));

        $expect = static fn (string $ext): string => (string) file_get_contents("{$dir}/{$name}.{$ext}");

        return new SigV4VectorResult(
            name: $name,
            group: basename(dirname($dir)) === 'aws4_testsuite' ? 'basic' : basename(dirname($dir)),
            canonicalRequestOk: $signed->canonicalRequest === $expect('creq'),
            stringToSignOk: $signed->stringToSign === $expect('sts'),
            authorizationOk: $signed->authorization === $expect('authz'),
            expectedAuthorization: $expect('authz'),
            actualAuthorization: $signed->authorization,
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: string}>, 4: string}
     */
    public static function parseRequest(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        [$head, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');
        $lines = explode("\n", $head);

        // リクエスト行はパスに空白を含むことがあるため、先頭と末尾のトークンで分割する
        $requestLine = array_shift($lines);
        $firstSpace = strpos($requestLine, ' ');
        $lastSpace = strrpos($requestLine, ' ');
        $method = substr($requestLine, 0, $firstSpace);
        $target = substr($requestLine, $firstSpace + 1, $lastSpace - $firstSpace - 1);
        [$path, $query] = array_pad(explode('?', $target, 2), 2, '');

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $headers !== []) {
                // 折り返されたヘッダー値は直前のヘッダーに連結する
                $headers[array_key_last($headers)][1] .= ' ' . trim($line);
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[] = [$name, $value];
        }

        return [$method, $path, $query, $headers, $body];
    }
}

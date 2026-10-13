<?php

declare(strict_types=1);

namespace DealRadar\Http;

final class CurlTransport implements Transport
{
    public function __construct(private readonly int $timeoutSeconds = 20)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $responseHeaders = [];
        $ch = curl_init($request->url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => array_map(static fn (array $h): string => "{$h[0]}: {$h[1]}", $request->headers),
            CURLOPT_POSTFIELDS => $request->body !== '' ? $request->body : null,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            throw new TransportException("通信に失敗しました: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return new HttpResponse($status, (string) $body, $responseHeaders);
    }
}

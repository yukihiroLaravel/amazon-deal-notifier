<?php

declare(strict_types=1);

namespace DealRadar\Http;

interface Transport
{
    /** @throws TransportException 通信自体に失敗した場合 */
    public function send(HttpRequest $request): HttpResponse;
}

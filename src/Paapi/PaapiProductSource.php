<?php

declare(strict_types=1);

namespace DealRadar\Paapi;

use DateTimeImmutable;
use DealRadar\Domain\Product;
use DealRadar\Domain\Rule;

/** ルールに従って SearchItems をページ送りしながら呼び、商品一覧を返す */
final class PaapiProductSource
{
    public function __construct(
        private readonly PaapiClient $client,
        private readonly PaapiResponseParser $parser,
        private readonly bool $demo,
    ) {
    }

    /** @return list<Product> */
    public function fetch(Rule $rule, int $maxPages): array
    {
        $products = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->client->searchItems($rule, $page);
            $batch = $this->parser->parse($response, $rule->searchIndex, new DateTimeImmutable());
            array_push($products, ...$batch);

            $total = (int) ($response['SearchResult']['TotalResultCount'] ?? 0);
            if (count($batch) < PaapiClient::ITEMS_PER_PAGE || $page * PaapiClient::ITEMS_PER_PAGE >= $total) {
                break;
            }
        }

        return $products;
    }

    public function label(): string
    {
        return $this->demo ? 'PA-API 5.0（デモデータ）' : 'PA-API 5.0';
    }

    public function isDemo(): bool
    {
        return $this->demo;
    }

    /** @return array<string, mixed>|null */
    public function lastExchange(): ?array
    {
        return $this->client->lastExchange();
    }
}

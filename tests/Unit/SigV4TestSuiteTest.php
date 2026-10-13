<?php

declare(strict_types=1);

namespace DealRadar\Tests\Unit;

use DealRadar\Paapi\SigV4TestSuite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class SigV4TestSuiteTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function vectors(): iterable
    {
        foreach (SigV4TestSuite::default()->vectors() as $name => $dir) {
            yield $name => [$name, $dir];
        }
    }

    #[DataProvider('vectors')]
    #[TestDox('AWS 公式テストベクター $name と一致する')]
    public function testMatchesOfficialVector(string $name, string $dir): void
    {
        $result = SigV4TestSuite::default()->runVector($name, $dir);

        self::assertTrue($result->canonicalRequestOk, '正規リクエストが一致しない');
        self::assertTrue($result->stringToSignOk, '署名対象文字列が一致しない');
        self::assertSame($result->expectedAuthorization, $result->actualAuthorization);
    }

    public function testSuiteIsNotEmpty(): void
    {
        self::assertGreaterThanOrEqual(25, count(SigV4TestSuite::default()->vectors()));
    }
}

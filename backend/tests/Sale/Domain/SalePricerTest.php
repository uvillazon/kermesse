<?php

declare(strict_types=1);

namespace App\Tests\Sale\Domain;

use App\Sale\Domain\CatalogOffer;
use App\Sale\Domain\RequestedSaleItem;
use App\Sale\Domain\SalePricer;
use App\Sale\Domain\SaleRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SalePricerTest extends TestCase
{
    public function testPricesFromCatalogAndKeepsProductSnapshots(): void
    {
        $sale = (new SalePricer())->price([
            $this->offer('product-1', 'PLATO', 'Plato principal', 2500, 5, version: 3),
            $this->offer('product-2', 'BEBIDA', 'Bebida', 800, 10, version: 2),
        ], [
            new RequestedSaleItem('product-1', 2),
            new RequestedSaleItem('product-2', 1),
        ]);

        self::assertSame('BOB', $sale->currency);
        self::assertSame(5800, $sale->subtotalMinor);
        self::assertSame(0, $sale->discountTotalMinor);
        self::assertSame(5800, $sale->totalMinor);
        self::assertCount(2, $sale->items);
        self::assertSame('Plato principal', $sale->items[0]->productNameSnapshot);
        self::assertSame(2500, $sale->items[0]->unitPriceMinorSnapshot);
        self::assertSame(5000, $sale->items[0]->lineTotalMinor);
        self::assertSame(3, $sale->items[0]->catalogVersion);
    }

    /** @return iterable<string, array{list<CatalogOffer>, list<RequestedSaleItem>, string}> */
    public static function invalidSales(): iterable
    {
        $active = new CatalogOffer('product-1', 'P1', 'Product 1', 1000, 'BOB', 2, true, 1);

        yield 'empty sale' => [[$active], [], 'empty_sale'];
        yield 'duplicate product' => [[$active], [new RequestedSaleItem('product-1', 1), new RequestedSaleItem('product-1', 1)], 'duplicate_product'];
        yield 'unknown product' => [[$active], [new RequestedSaleItem('missing', 1)], 'product_not_found'];
        yield 'inactive product' => [[new CatalogOffer('product-1', 'P1', 'Product 1', 1000, 'BOB', 2, false, 1)], [new RequestedSaleItem('product-1', 1)], 'product_not_active'];
        yield 'insufficient availability' => [[$active], [new RequestedSaleItem('product-1', 3)], 'insufficient_availability'];
        yield 'mixed currency' => [[$active, new CatalogOffer('product-2', 'P2', 'Product 2', 1000, 'USD', 2, true, 1)], [new RequestedSaleItem('product-1', 1), new RequestedSaleItem('product-2', 1)], 'mixed_currency'];
    }

    /** @param list<CatalogOffer> $offers @param list<RequestedSaleItem> $items */
    #[DataProvider('invalidSales')]
    public function testRejectsInvalidSale(array $offers, array $items, string $reason): void
    {
        try {
            (new SalePricer())->price($offers, $items);
            self::fail('Expected sale rule violation.');
        } catch (SaleRuleViolation $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    private function offer(
        string $id,
        string $code,
        string $name,
        int $priceMinor,
        int $available,
        bool $active = true,
        int $version = 1,
    ): CatalogOffer {
        return new CatalogOffer($id, $code, $name, $priceMinor, 'BOB', $available, $active, $version);
    }
}

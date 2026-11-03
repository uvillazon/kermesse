<?php

declare(strict_types=1);

namespace App\Sale\Domain;

final class SalePricer
{
    /**
     * @param list<CatalogOffer> $offers
     * @param non-empty-list<RequestedSaleItem> $requestedItems
     */
    public function price(array $offers, array $requestedItems): PricedSale
    {
        if ([] === $requestedItems) {
            throw new SaleRuleViolation('empty_sale', 'Sale must contain at least one item.');
        }

        $offersById = [];
        foreach ($offers as $offer) {
            $offersById[$offer->productId] = $offer;
        }

        $seen = [];
        $currency = null;
        $subtotal = 0;
        $pricedItems = [];

        foreach ($requestedItems as $requested) {
            if (isset($seen[$requested->productId])) {
                throw new SaleRuleViolation('duplicate_product', 'Each product may appear only once in a sale.');
            }
            $seen[$requested->productId] = true;

            $offer = $offersById[$requested->productId] ?? null;
            if (null === $offer) {
                throw new SaleRuleViolation('product_not_found', 'A requested product is not available in this event.');
            }
            if (!$offer->active) {
                throw new SaleRuleViolation('product_not_active', 'A requested product is not active.');
            }
            if ($requested->quantity > $offer->availableQuantity) {
                throw new SaleRuleViolation('insufficient_availability', 'Requested quantity exceeds current availability.');
            }
            if (null !== $currency && $currency !== $offer->currency) {
                throw new SaleRuleViolation('mixed_currency', 'All sale items must use the same currency.');
            }
            $currency ??= $offer->currency;

            $lineTotal = $this->checkedMultiply($offer->unitPriceMinor, $requested->quantity);
            $subtotal = $this->checkedAdd($subtotal, $lineTotal);
            $pricedItems[] = new PricedSaleItem(
                $offer->productId,
                $offer->code,
                $offer->name,
                $offer->unitPriceMinor,
                $requested->quantity,
                $lineTotal,
                $offer->version,
            );
        }

        return new PricedSale($pricedItems, $currency ?? throw new \LogicException('Currency was not resolved.'), $subtotal, 0, $subtotal);
    }

    private function checkedMultiply(int $price, int $quantity): int
    {
        if (0 !== $price && $quantity > intdiv(PHP_INT_MAX, $price)) {
            throw new SaleRuleViolation('amount_overflow', 'Sale amount exceeds the supported range.');
        }

        return $price * $quantity;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($left > PHP_INT_MAX - $right) {
            throw new SaleRuleViolation('amount_overflow', 'Sale amount exceeds the supported range.');
        }

        return $left + $right;
    }
}

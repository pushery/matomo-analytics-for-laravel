<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

/**
 * A cart update — the current cart contents and grand total (Matomo idgoal=0,
 * no order id). Track it whenever the cart changes.
 */
final readonly class EcommerceCartUpdate implements Hit
{
    /**
     * @param  list<EcommerceItem>  $items
     */
    public function __construct(
        public float $grandTotal,
        public array $items = [],
    ) {}

    public function toParams(): array
    {
        return [
            'idgoal' => 0,
            'revenue' => $this->grandTotal,
            'ec_items' => EcommerceItem::encode($this->items),
        ];
    }
}

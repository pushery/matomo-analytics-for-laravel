<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

/**
 * A completed ecommerce order (Matomo idgoal=0 with an order id).
 */
final readonly class EcommerceOrder implements Hit
{
    /**
     * @param  list<EcommerceItem>  $items
     */
    public function __construct(
        public string $orderId,
        public float $grandTotal,
        public array $items = [],
        public ?float $subTotal = null,
        public ?float $tax = null,
        public ?float $shipping = null,
        public ?float $discount = null,
    ) {}

    public function toParams(): array
    {
        $params = [
            'idgoal' => 0,
            'ec_id' => $this->orderId,
            'revenue' => $this->grandTotal,
            'ec_items' => EcommerceItem::encode($this->items),
        ];

        if ($this->subTotal !== null) {
            $params['ec_st'] = $this->subTotal;
        }

        if ($this->tax !== null) {
            $params['ec_tx'] = $this->tax;
        }

        if ($this->shipping !== null) {
            $params['ec_sh'] = $this->shipping;
        }

        if ($this->discount !== null) {
            $params['ec_dt'] = $this->discount;
        }

        return $params;
    }
}

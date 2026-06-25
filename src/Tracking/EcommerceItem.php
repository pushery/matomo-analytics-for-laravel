<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

/**
 * A single line item in an ecommerce order or cart, serialised to Matomo's
 * positional ec_items shape: [sku, name, category, price, quantity].
 */
final readonly class EcommerceItem
{
    public function __construct(
        public string $sku,
        public ?string $name = null,
        public ?string $category = null,
        public ?float $price = null,
        public int $quantity = 1,
    ) {}

    /**
     * @return array{0: string, 1: string, 2: string, 3: float, 4: int}
     */
    public function toArray(): array
    {
        return [$this->sku, $this->name ?? '', $this->category ?? '', $this->price ?? 0.0, $this->quantity];
    }

    /**
     * JSON-encode a list of items for the ec_items tracking parameter.
     *
     * @param  list<self>  $items
     */
    public static function encode(array $items): string
    {
        return json_encode(
            array_map(static fn (self $item): array => $item->toArray(), $items),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}

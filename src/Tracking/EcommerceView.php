<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

/**
 * An ecommerce product or category view — a page view carrying the viewed
 * product's details (_pks/_pkn/_pkc/_pkp). For a category view, pass only the
 * category.
 */
final readonly class EcommerceView implements Hit
{
    public function __construct(
        public ?string $sku = null,
        public ?string $name = null,
        public ?string $category = null,
        public ?float $price = null,
        public ?string $title = null,
        public ?string $url = null,
    ) {}

    public function toParams(): array
    {
        $params = [];

        if ($this->title !== null) {
            $params['action_name'] = $this->title;
        }

        if ($this->url !== null) {
            $params['url'] = $this->url;
        }

        if ($this->sku !== null) {
            $params['_pks'] = $this->sku;
        }

        if ($this->name !== null) {
            $params['_pkn'] = $this->name;
        }

        if ($this->category !== null) {
            $params['_pkc'] = $this->category;
        }

        if ($this->price !== null) {
            $params['_pkp'] = $this->price;
        }

        return $params;
    }
}

<?php

declare(strict_types=1);

namespace MatomoAnalytics\Reporting;

use MatomoAnalytics\Contracts\ReportClient;
use MatomoAnalytics\Support\Config;

/**
 * Fluent builder over a single Reporting API method: layer on a segment and the
 * standard Matomo report filters (limit / offset / sort / column / pattern /
 * flat / expanded / truncate / show|hideColumns), then get() runs it through the
 * client's normal cache + resilience path.
 *
 *   MatomoReports::query('Actions.getPageUrls')
 *       ->period('month')->date('2026-01')
 *       ->segment('mobile')          // a named segment, or a raw definition / Segment
 *       ->sortBy('nb_visits')->limit(10)->flat()
 *       ->get();
 */
final class ReportQuery
{
    /** @var array<string, scalar> */
    private array $params = [];

    public function __construct(
        private readonly ReportClient $client,
        private readonly string $method,
    ) {}

    public function period(string $period): self
    {
        $this->params['period'] = $period;

        return $this;
    }

    public function date(string $date): self
    {
        $this->params['date'] = $date;

        return $this;
    }

    /**
     * A named segment (resolved via the reporting.segments registry), a raw
     * segment definition, or a Segment builder.
     */
    public function segment(string|Segment $segment): self
    {
        $this->params['segment'] = $segment instanceof Segment
            ? $segment->definition()
            : $this->resolveSegment($segment);

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->params['filter_limit'] = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->params['filter_offset'] = $offset;

        return $this;
    }

    public function sortBy(string $column, string $order = 'desc'): self
    {
        $this->params['filter_sort_column'] = $column;
        $this->params['filter_sort_order'] = $order;

        return $this;
    }

    public function search(string $pattern, ?string $column = null): self
    {
        $this->params['filter_pattern'] = $pattern;

        if ($column !== null) {
            $this->params['filter_column'] = $column;
        }

        return $this;
    }

    public function flat(bool $flat = true): self
    {
        return $this->toggle('flat', $flat);
    }

    public function expanded(bool $expanded = true): self
    {
        return $this->toggle('expanded', $expanded);
    }

    public function truncate(int $rows): self
    {
        $this->params['filter_truncate'] = $rows;

        return $this;
    }

    /**
     * @param  list<string>  $columns
     */
    public function showColumns(array $columns): self
    {
        $this->params['showColumns'] = implode(',', $columns);

        return $this;
    }

    /**
     * @param  list<string>  $columns
     */
    public function hideColumns(array $columns): self
    {
        $this->params['hideColumns'] = implode(',', $columns);

        return $this;
    }

    /**
     * Merge any raw Reporting API parameters the builder does not model.
     *
     * @param  array<string, scalar>  $params
     */
    public function params(array $params): self
    {
        $this->params = array_merge($this->params, $params);

        return $this;
    }

    /**
     * @return array<string, scalar>
     */
    public function toArray(): array
    {
        return $this->params;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function get(): ?array
    {
        return $this->client->get($this->method, $this->params);
    }

    private function toggle(string $key, bool $on): self
    {
        if ($on) {
            $this->params[$key] = '1';
        } else {
            unset($this->params[$key]);
        }

        return $this;
    }

    private function resolveSegment(string $nameOrDefinition): string
    {
        $value = Config::scalarMap('matomo-analytics.reporting.segments')[$nameOrDefinition] ?? null;

        return is_string($value) ? $value : $nameOrDefinition;
    }
}

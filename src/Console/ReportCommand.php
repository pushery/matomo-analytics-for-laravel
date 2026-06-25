<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use MatomoAnalytics\Contracts\ReportClient;

final class ReportCommand extends Command
{
    protected $signature = 'matomo:report
        {method : Reporting API method, e.g. VisitsSummary.get}
        {--period= : day|week|month|year|range}
        {--date= : today|yesterday|YYYY-MM-DD|lastN}
        {--segment= : a Matomo segment definition}';

    protected $description = 'Fetch a Matomo Reporting API method and print the JSON result.';

    public function handle(ReportClient $reports): int
    {
        $method = $this->argument('method');

        $data = $reports->get(is_string($method) ? $method : '', $this->params());

        if ($data === null) {
            $this->error($reports->lastError() ?? 'Matomo reporting returned no data.');

            return self::FAILURE;
        }

        $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * @return array<string, scalar>
     */
    private function params(): array
    {
        $params = [];

        foreach (['period', 'date', 'segment'] as $option) {
            $value = $this->option($option);
            if (is_string($value) && $value !== '') {
                $params[$option] = $value;
            }
        }

        return $params;
    }
}

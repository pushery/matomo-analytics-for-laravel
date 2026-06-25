<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use MatomoAnalytics\Contracts\GdprClient;

final class ForgetCommand extends Command
{
    protected $signature = 'matomo:forget
        {segment : Segment identifying the data subject, e.g. userId==alice@example.com}
        {--site= : idSite to search (default: the configured site_id; "all" for every site)}
        {--export : Export the data subject\'s data instead of deleting it}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Erase (or export) a data subject\'s data via Matomo GDPR tools.';

    public function handle(GdprClient $gdpr): int
    {
        $segmentArg = $this->argument('segment');
        $segment = is_string($segmentArg) ? $segmentArg : '';
        $site = $this->site();

        $found = $gdpr->findDataSubjects($segment, $site);
        if ($found === null) {
            $this->error($gdpr->lastError() ?? 'Matomo GDPR lookup failed.');

            return self::FAILURE;
        }

        $count = count($found);
        if ($count === 0) {
            $this->info("No data subjects matched the segment [{$segment}].");

            return self::SUCCESS;
        }

        if ($this->option('export') === true) {
            $data = $gdpr->export($segment, $site);
            if ($data === null) {
                $this->error($gdpr->lastError() ?? 'Matomo GDPR export failed.');

                return self::FAILURE;
            }

            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($this->option('force') !== true
            && ! $this->confirm("Permanently erase {$count} matched visit(s) for [{$segment}]? This cannot be undone.")) {
            $this->info('Aborted — nothing was deleted.');

            return self::SUCCESS;
        }

        $result = $gdpr->forget($segment, $site);
        if ($result === null) {
            $this->error($gdpr->lastError() ?? 'Matomo GDPR erasure failed.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Erased %d matched visit(s); deleted %d record(s) across %d storage area(s).',
            $count,
            array_sum($result),
            count($result),
        ));

        return self::SUCCESS;
    }

    private function site(): ?string
    {
        $site = $this->option('site');

        return is_string($site) && $site !== '' ? $site : null;
    }
}

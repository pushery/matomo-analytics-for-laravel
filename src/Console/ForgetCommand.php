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
        $segment = $this->stringArgument('segment');
        $site = $this->site();

        $found = $gdpr->findDataSubjects($segment, $site);
        if ($found === null) {
            $this->error($gdpr->lastError() ?? 'Matomo GDPR lookup failed.');

            return self::FAILURE;
        }

        $count = count($found);

        if ($this->option('export') === true) {
            if ($count === 0) {
                $this->info("No data subjects matched the segment [{$segment}].");

                return self::SUCCESS;
            }

            $data = $gdpr->export($segment, $site);
            if ($data === null) {
                $this->error($gdpr->lastError() ?? 'Matomo GDPR export failed.');

                return self::FAILURE;
            }

            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        // With no match in Matomo the erasure still has work: the hits Matomo never received,
        // waiting in the buffer or parked as dead letters, exist only here.
        $question = $count > 0
            ? "Permanently erase {$count} matched visit(s) for [{$segment}]? This cannot be undone."
            : "No visit matched [{$segment}] in Matomo. Erase this person's hits from the local buffer and dead letters? This cannot be undone.";

        if ($this->option('force') !== true) {
            // Nobody can answer the prompt under -n, -q or --silent, where it takes its default, no.
            // Such a run fails rather than reporting an abort, so a job that runs a deletion
            // request never reads it as done while nothing was deleted.
            if (! $this->input->isInteractive()) {
                $this->error('Refusing to erase without --force in a non-interactive run — nothing was deleted.');

                return self::FAILURE;
            }

            if (! $this->confirm($question)) {
                $this->info('Aborted — nothing was deleted.');

                return self::SUCCESS;
            }
        }

        $result = $gdpr->forget($segment, $site);
        if ($result === null) {
            $this->error($gdpr->lastError() ?? 'Matomo GDPR erasure failed.');

            return self::FAILURE;
        }

        // Matomo's counts and this application's are different systems, and the local keys are
        // booleans as well as counts, so the two halves are reported apart.
        $matomo = array_filter(
            $result,
            static fn (bool|int $value, string $key): bool => is_int($value) && ! str_starts_with($key, 'local_'),
            ARRAY_FILTER_USE_BOTH,
        );

        $this->info($count > 0
            ? sprintf('Matomo: erased %d matched visit(s); deleted %d record(s) across %d storage area(s).', $count, array_sum($matomo), count($matomo))
            : 'Matomo: no visit matched.');

        return $this->reportLocal($result);
    }

    /**
     * Report the local half of the erasure, and fail when it could not run at all.
     *
     * A segment the local half cannot evaluate leaves the buffer and the dead letters untouched,
     * so the request is not fulfilled and the exit code says so. A store it could not search is
     * named, with what to do about it; the erasure of everything else still counts.
     *
     * @param  array<string, bool|int>  $result
     */
    private function reportLocal(array $result): int
    {
        $understood = $result['local_segment_understood'] ?? null;

        if ($understood === false) {
            $this->warn('Local: the buffer and the dead letters were NOT searched. Only userId==<value> and visitIp==<value> can be matched here, so this person\'s undelivered hits may remain. Run the command again with one of those segments.');

            return self::FAILURE;
        }

        if ($understood !== true) {
            $this->warn('Local: the bound GDPR client does not say whether it searched the buffer and the dead letters.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Local: removed %d buffered hit(s) and %d dead-lettered hit(s).',
            (int) ($result['local_buffer'] ?? 0),
            (int) ($result['local_dead_letters'] ?? 0),
        ));

        if (($result['local_buffer_searched'] ?? true) === false) {
            $this->warn('Local: the hit buffer your application bound cannot be searched (it does not implement ErasableHitBuffer), so hits waiting in it were not erased.');
        }

        if (($result['local_queue_searched'] ?? true) === false) {
            $this->warn('Local: in the queue mode, hits still waiting in the queue, and jobs that ran out of attempts in failed_jobs, cannot be searched. Run this again once the queue has drained, and prune the failed jobs.');
        }

        return self::SUCCESS;
    }

    private function site(): ?string
    {
        $site = $this->option('site');

        return is_string($site) && $site !== '' ? $site : null;
    }

    /**
     * A required argument, read as the string it always is.
     *
     * By name through a parameter, so the check stays a check on every Laravel this package
     * supports: with the package booted, Larastan types a call with a literal name from the
     * signature as a string, and on the Laravel 12 leg it types the same call as anything a
     * console input can hold. A name it cannot resolve reads the same on both.
     */
    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }
}

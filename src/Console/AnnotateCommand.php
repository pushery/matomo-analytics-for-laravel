<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use MatomoAnalytics\Contracts\AnnotationsClient;
use MatomoAnalytics\Support\Config;

final class AnnotateCommand extends Command
{
    protected $signature = 'matomo:annotate
        {note? : The annotation text (omit with --release to mark a deployment)}
        {--date= : The annotation date (YYYY-MM-DD; default: today)}
        {--release : Annotate a deployment ("<prefix> <version>"); gated by the annotations.release config}
        {--app-version= : The version for --release (default: config app.version)}
        {--starred : Star the annotation}
        {--site= : idSite (default: the configured site_id)}';

    protected $description = 'Add an annotation (or a deployment marker) to your Matomo reports timeline.';

    public function handle(AnnotationsClient $annotations): int
    {
        if ($this->option('release') === true) {
            return $this->annotateRelease($annotations);
        }

        $note = $this->argument('note');
        if (! is_string($note) || $note === '') {
            $this->error('Provide a note, or use --release to mark a deployment.');

            return self::FAILURE;
        }

        return $this->report(
            $annotations,
            $annotations->add($note, $this->stringOption('date'), $this->option('starred') === true, $this->stringOption('site')),
        );
    }

    private function annotateRelease(AnnotationsClient $annotations): int
    {
        if (! Config::bool('matomo-analytics.annotations.release', false)) {
            $this->info('Release annotations are disabled (set annotations.release to enable). Skipping.');

            return self::SUCCESS;
        }

        return $this->report(
            $annotations,
            $annotations->annotateRelease($this->stringOption('app-version'), $this->stringOption('date')),
        );
    }

    /**
     * @param  array<array-key, mixed>|null  $result
     */
    private function report(AnnotationsClient $annotations, ?array $result): int
    {
        if ($result === null) {
            $this->error($annotations->lastError() ?? 'Matomo annotation failed.');

            return self::FAILURE;
        }

        $this->info('Annotation added.');

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use MatomoAnalytics\Buffer\BufferFlusher;
use MatomoAnalytics\Buffer\BufferManager;
use MatomoAnalytics\Buffer\ConsecutiveFailures;
use MatomoAnalytics\Buffer\DeadLetterStore;
use MatomoAnalytics\Contracts\Sender;
use MatomoAnalytics\PayloadBuilder;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\Reporter;
use MatomoAnalytics\Tracking\PageView;
use MatomoAnalytics\Transport\NullSender;

/**
 * Fires N synthetic hits through the real build -> buffer -> flush pipeline and
 * reports throughput, Bulk POST count and peak memory — an operator tool for
 * sizing a deployment. By default it discards the sends (NullSender), so nothing
 * reaches Matomo; --against=real exercises the configured instance end to end.
 */
final class LoadSimCommand extends Command
{
    protected $signature = 'matomo:load-sim
        {--hits=1000 : Number of synthetic hits to enqueue and drain}
        {--driver= : Buffer driver to exercise (array|database|redis|file; default: the configured batch driver)}
        {--batch= : Bulk batch size (default: the configured batch.size)}
        {--against=fake : "fake" discards the sends (measures the client), "real" sends to the configured Matomo}';

    protected $description = 'Simulate load through the real buffer + flush pipeline and report throughput.';

    public function handle(BufferManager $buffers, PayloadBuilder $builder, Reporter $reporter, DeadLetterStore $deadLetters, ConsecutiveFailures $failures): int
    {
        $hits = max(1, $this->intOption('hits', 1000));

        $batch = $this->intOption('batch', 0);
        if ($batch > 0) {
            config()->set('matomo-analytics.batch.size', $batch);
        }

        // A sim can enqueue a lot; don't flood the event bus while measuring.
        config()->set('matomo-analytics.events', false);

        $buffer = $buffers->driver($this->stringOption('driver'));
        $sender = $this->stringOption('against') === 'real' ? app(Sender::class) : new NullSender;

        $this->info(sprintf('Enqueuing %s synthetic hits…', number_format($hits)));

        $template = $builder->build(
            new PageView('Load sim'),
            Request::create('/matomo-load-sim', 'GET', server: ['HTTP_USER_AGENT' => 'matomo-load-sim']),
        );

        $enqueueStart = microtime(true);
        for ($i = 0; $i < $hits; $i++) {
            $buffer->push($template);
        }
        $enqueueSeconds = microtime(true) - $enqueueStart;

        $flusher = new BufferFlusher($buffer, $sender, $reporter, $deadLetters, $failures);

        $flushStart = microtime(true);
        $delivered = 0;
        while ($buffer->size() > 0) {
            $before = $buffer->size();
            $delivered += $flusher->flush();

            if ($buffer->size() >= $before) {
                $this->warn('Flush made no progress — stopping (a real endpoint may be unreachable).');

                break;
            }
        }
        $flushSeconds = microtime(true) - $flushStart;

        $posts = $sender instanceof NullSender
            ? $sender->posts
            : (int) ceil($delivered / max(1, Config::int('matomo-analytics.batch.size', 50)));

        $this->info(sprintf('Delivered %s hit(s) in %s bulk POST(s).', number_format($delivered), number_format($posts)));
        $this->render($hits, $delivered, $posts, $enqueueSeconds, $flushSeconds);

        return self::SUCCESS;
    }

    private function render(int $hits, int $delivered, int $posts, float $enqueueSeconds, float $flushSeconds): void
    {
        $this->table(['Metric', 'Value'], [
            ['Hits enqueued', number_format($hits)],
            ['Hits delivered', number_format($delivered)],
            ['Bulk POSTs', number_format($posts)],
            ['Enqueue throughput', $this->rate($hits, $enqueueSeconds)],
            ['Flush throughput', $this->rate($delivered, $flushSeconds)],
            ['Peak memory', sprintf('%.1f MB', memory_get_peak_usage(true) / 1048576)],
        ]);
    }

    private function rate(int $count, float $seconds): string
    {
        $perSecond = $seconds > 0.0 ? $count / $seconds : 0.0;

        return sprintf('%s hits/s', number_format($perSecond));
    }

    private function intOption(string $key, int $default): int
    {
        $value = $this->option($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

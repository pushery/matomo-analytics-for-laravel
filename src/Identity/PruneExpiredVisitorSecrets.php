<?php

declare(strict_types=1);

namespace MatomoAnalytics\Identity;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Date;
use MatomoAnalytics\Support\Config;

/**
 * Deletes the expired secrets of the cookieless visitor id from a database or a file cache store.
 *
 * The secret of a period expires a minute after the period ends, and the package never asks for the key of a past
 * period again. Both stores delete an expired key only when that key is read again, so on either store the secret of
 * every past period stays, a row in the table of a database store or a file under the directory of a file store, and
 * whoever reads them can still work out which address and browser an id of that period belonged to. Scheduled every
 * hour, this deletes each of them within the hour after its period:
 *
 *     Schedule::call(PruneExpiredVisitorSecrets::class)->hourly();
 *
 * It prunes the store `visitor.secret_store` names, or the default store, and does nothing where that is neither a
 * database nor a file store. A file store names a file by the hash of its key, so there the expired file of any key
 * goes, which the store already treats as missing. A database store is pruned a thousand rows to a statement, so a
 * table that went unpruned for a while is emptied in short statements rather than in one long one.
 */
final readonly class PruneExpiredVisitorSecrets
{
    /** The most rows one statement deletes, so a large table is pruned in short statements. */
    private const int CHUNK = 1000;

    public function __construct(
        private Repository $config,
        private ConnectionResolverInterface $connections,
    ) {}

    /** Delete every expired secret, and return how many rows or files went. */
    public function __invoke(): int
    {
        $store = $this->store();

        if ($store === null) {
            return 0;
        }

        if ($store['driver'] === 'file') {
            return $this->pruneFiles($store['path']);
        }

        return $this->pruneRows($store['connection'], $store['table'], $store['prefix']);
    }

    /** Delete the expired secrets from the table of a database store, and return how many rows went. */
    private function pruneRows(?string $connection, string $table, string $prefix): int
    {
        $pattern = $this->secretsLike($prefix);
        $now = Date::now()->getTimestamp();
        $deleted = 0;

        do {
            $chunk = $this->connections->connection($connection)->table($table)
                ->where('key', 'like', $pattern)
                ->where('expiration', '<=', $now)
                ->limit(self::CHUNK)
                ->delete();

            $deleted += $chunk;
        } while ($chunk === self::CHUNK);

        return $deleted;
    }

    /**
     * Delete every expired file of a file store, and return how many went.
     *
     * The store keeps a key in a file named by its hash, two directories below its own, and writes the moment it
     * expires as the first ten characters. A key that never expires carries 9999999999 and stays.
     */
    private function pruneFiles(string $directory): int
    {
        $now = Date::now()->getTimestamp();
        $deleted = 0;

        foreach (glob($directory.'/*/*/*', GLOB_NOSORT) ?: [] as $path) {
            $expiration = is_file($path) ? (string) file_get_contents($path, length: 10) : '';

            if (preg_match('/\A\d{10}\z/', $expiration) === 1 && (int) $expiration <= $now && unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * What pruning the store of the secrets takes: for a database store its table, its connection and its key prefix,
     * for a file store its directory, and null for any other store.
     *
     * @return array{driver: 'database', table: string, connection: ?string, prefix: string}|array{driver: 'file', path: string}|null
     */
    private function store(): ?array
    {
        $name = Config::nullableString('matomo-analytics.visitor.secret_store') ?? $this->config->get('cache.default');
        $stores = $this->config->get('cache.stores');
        $store = is_array($stores) && is_string($name) ? ($stores[$name] ?? null) : null;

        if (! is_array($store)) {
            return null;
        }

        $driver = $store['driver'] ?? null;

        if ($driver === 'file') {
            $path = $store['path'] ?? null;

            return is_string($path) && $path !== '' ? ['driver' => 'file', 'path' => rtrim($path, '/')] : null;
        }

        if ($driver !== 'database') {
            return null;
        }

        $table = $store['table'] ?? null;
        $connection = $store['connection'] ?? null;
        // As Laravel builds the store: its own prefix, or the one every store shares.
        $prefix = $store['prefix'] ?? $this->config->get('cache.prefix');

        if (! is_string($table) || $table === '') {
            return null;
        }

        return [
            'driver' => 'database',
            'table' => $table,
            'connection' => is_string($connection) && $connection !== '' ? $connection : null,
            'prefix' => is_string($prefix) ? $prefix : '',
        ];
    }

    /**
     * The keys of the secrets as a LIKE pattern.
     *
     * A character of the prefix that LIKE reads as more than itself on one of the engines, `%`, `\` and `[`, stands
     * as `_`, any one character, so the pattern means the same on every engine without an escape clause. It can then
     * match a key beside these, and so can a LIKE that ignores case, as SQLite's does and MySQL's under its default
     * collation. Such a key goes only once it has expired, and the store already treats it as missing then.
     */
    private function secretsLike(string $prefix): string
    {
        return str_replace(['%', '\\', '['], '_', $prefix).'matomo-analytics:visitor-secret:%';
    }
}

<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Date;

/**
 * Deletes the expired entries this package keeps in a database, a file or a storage cache store.
 *
 * These stores delete an expired entry only when its key is read again. A key written for one
 * request, one visitor or one record is rarely read again once that has passed, so on these stores
 * it stays for good: a row in the table of a database store, a file under the directory of a file
 * store or on the disk of a storage store. Scheduled once a day, this deletes them, the rows a
 * thousand to a statement:
 *
 *     Schedule::call(PruneExpiredCacheKeys::class)->daily();
 *
 * It prunes the default store and the store of the rate limiter, `cache.limiter`, each once, and in
 * place of a failover store the stores it writes to. It does nothing for a store that expires its
 * entries itself or keeps none. In a database store only the keys of this package go, those under
 * `matomo-analytics:`, and only once they have expired, when the store already treats them as
 * missing. A file store and a storage store name a file by the hash of its key, so there the expired
 * file of any key goes, which the store already treats as missing too.
 *
 * Part of the public API, because a host schedules it in its own scheduler.
 */
final readonly class PruneExpiredCacheKeys
{
    /** The most rows one statement deletes, so a large table is pruned in short statements. */
    private const int CHUNK = 1000;

    public function __construct(
        private Repository $config,
        private ConnectionResolverInterface $connections,
        private Filesystems $filesystems,
    ) {}

    /** Delete every expired entry of this package, and return how many rows or files went. */
    public function __invoke(): int
    {
        $deleted = 0;

        foreach ($this->stores() as $store) {
            if ($store['driver'] === 'file') {
                $deleted += $this->pruneFiles($store['path']);

                continue;
            }

            if ($store['driver'] === 'storage') {
                $deleted += $this->pruneDisk($store['disk'], $store['path']);

                continue;
            }

            $deleted += $this->pruneRows($store['connection'], $store['table'], $store['prefix']);
        }

        return $deleted;
    }

    /**
     * The stores pruning reaches, among the default store and the store of the rate limiter, each
     * once: two names of one table, or a limiter left to the default store, are pruned once. A
     * failover store writes each entry into the first of its stores that answers, so its stores are
     * read in its place, each name once, which also ends a failover that names itself.
     *
     * @return list<array{driver: 'database', table: string, connection: ?string, prefix: string}|array{driver: 'file', path: string}|array{driver: 'storage', disk: ?string, path: string}>
     */
    private function stores(): array
    {
        $names = [];

        foreach (['cache.default', 'cache.limiter'] as $key) {
            $name = $this->config->get($key);

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        $read = [];
        $stores = [];

        while ($names !== []) {
            $name = array_shift($names);

            if (in_array($name, $read, true)) {
                continue;
            }

            $read[] = $name;
            $store = $this->store($name);

            if ($store === null) {
                continue;
            }

            if ($store['driver'] === 'failover') {
                $names = [...$names, ...$store['stores']];

                continue;
            }

            if (! in_array($store, $stores, true)) {
                $stores[] = $store;
            }
        }

        return $stores;
    }

    /**
     * What pruning a store takes: for a database store its table, its connection and its key
     * prefix, as Laravel builds the store, for a file store its directory, for a storage store its
     * disk and its directory there, for a failover store the names of its stores, and null for a
     * store of any other driver, a name no store is configured under, and a store without a table,
     * a directory or a store to fail over to.
     *
     * @return array{driver: 'database', table: string, connection: ?string, prefix: string}|array{driver: 'file', path: string}|array{driver: 'storage', disk: ?string, path: string}|array{driver: 'failover', stores: list<string>}|null
     */
    private function store(string $name): ?array
    {
        $stores = $this->config->get('cache.stores');
        $store = is_array($stores) ? ($stores[$name] ?? null) : null;

        if (! is_array($store)) {
            return null;
        }

        return match ($store['driver'] ?? null) {
            'database' => $this->databaseStore($store),
            'file' => $this->fileStore($store),
            'storage' => $this->storageStore($store),
            'failover' => $this->failoverStore($store),
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $store
     * @return array{driver: 'database', table: string, connection: ?string, prefix: string}|null
     */
    private function databaseStore(array $store): ?array
    {
        $table = $store['table'] ?? null;

        if (! is_string($table) || $table === '') {
            return null;
        }

        $connection = $store['connection'] ?? null;
        // Its own prefix, or the one every store shares.
        $prefix = $store['prefix'] ?? $this->config->get('cache.prefix');

        return [
            'driver' => 'database',
            'table' => $table,
            'connection' => is_string($connection) && $connection !== '' ? $connection : null,
            'prefix' => is_string($prefix) ? $prefix : '',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $store
     * @return array{driver: 'file', path: string}|null
     */
    private function fileStore(array $store): ?array
    {
        $path = $store['path'] ?? null;

        return is_string($path) && $path !== '' ? ['driver' => 'file', 'path' => rtrim($path, '/')] : null;
    }

    /**
     * Laravel takes the default disk where the store names none, and the root of the disk where it
     * names no directory.
     *
     * @param  array<array-key, mixed>  $store
     * @return array{driver: 'storage', disk: ?string, path: string}
     */
    private function storageStore(array $store): array
    {
        $disk = $store['disk'] ?? null;
        $path = $store['path'] ?? null;

        return [
            'driver' => 'storage',
            'disk' => is_string($disk) && $disk !== '' ? $disk : null,
            'path' => is_string($path) ? trim($path, '/') : '',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $store
     * @return array{driver: 'failover', stores: list<string>}|null
     */
    private function failoverStore(array $store): ?array
    {
        $listed = $store['stores'] ?? null;
        $names = [];

        foreach (is_array($listed) ? $listed : [] as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names === [] ? null : ['driver' => 'failover', 'stores' => $names];
    }

    /** Delete the expired rows of this package from the table of a database store, and return how many went. */
    private function pruneRows(?string $connection, string $table, string $prefix): int
    {
        $pattern = $this->keysLike($prefix);
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
     * The store keeps a key in a file named by its hash, two directories below its own, and writes
     * the moment it expires as the first ten characters. A key that never expires carries
     * 9999999999 and stays.
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
     * Delete every expired file of a storage store, and return how many went.
     *
     * The store lays its files out on its disk as a file store does in its directory: two
     * directories named by the first four characters of the hash of the key, the file by the whole
     * hash, the moment the key expires as its first ten characters. Only a file laid out so is read,
     * and only those ten characters of it, so a file on the disk that the store does not own stays,
     * and a large value is not read to learn its age.
     */
    private function pruneDisk(?string $disk, string $directory): int
    {
        $filesystem = $this->filesystems->disk($disk);
        $owned = '#\A'.($directory === '' ? '' : preg_quote($directory, '#').'/').'([0-9a-f]{2})/([0-9a-f]{2})/\1\2[0-9a-f]{36}\z#';
        $now = Date::now()->getTimestamp();
        $deleted = 0;

        foreach ($filesystem->allFiles($directory) as $path) {
            if (preg_match($owned, $path) === 1 && $this->expiredOnDisk($filesystem, $path, $now) && $filesystem->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Whether a file of a storage store names a moment that has passed in its first ten characters. */
    private function expiredOnDisk(Filesystem $filesystem, string $path, int $now): bool
    {
        $stream = $filesystem->readStream($path);
        $expiration = is_resource($stream) ? (string) fread($stream, 10) : '';

        if (is_resource($stream)) {
            fclose($stream);
        }

        return preg_match('/\A\d{10}\z/', $expiration) === 1 && (int) $expiration <= $now;
    }

    /**
     * The keys of this package as a LIKE pattern.
     *
     * A character of the prefix that LIKE reads as more than itself on one of the engines, `%`, `\`
     * and `[`, stands as `_`, any one character, so the pattern means the same on every engine
     * without an escape clause. It can then match a key beside these, and so can a LIKE that ignores
     * case, as SQLite's does and MySQL's under its default collation. Such a key goes only once it
     * has expired, and the store already treats it as missing then.
     */
    private function keysLike(string $prefix): string
    {
        return str_replace(['%', '\\', '['], '_', $prefix).'matomo-analytics:%';
    }
}

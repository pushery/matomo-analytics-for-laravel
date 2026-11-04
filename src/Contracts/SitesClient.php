<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

use InvalidArgumentException;

/**
 * The sites of the Matomo instance, through its SitesManager API: create one, change its name or
 * its addresses, delete it. For a host that gives each tenant a site of its own and reads that
 * site's reports through ReportClient::forSite().
 *
 * The token needs the rights the API asks for: a superuser's to create or delete a site, admin
 * access to a site to change it.
 */
interface SitesClient
{
    /**
     * Create a site and answer its id; null when Matomo refused or could not be reached, with the
     * reason in lastError().
     *
     * @param  list<string>  $urls  the addresses the site is reached under, the main one first
     */
    public function create(string $name, array $urls): ?int;

    /**
     * Give a site another name or other addresses, and leave a part given as null as it is. True
     * when Matomo confirmed the change.
     *
     * @param  list<string>|null  $urls  replaces every address the site had
     *
     * @throws InvalidArgumentException for an id below 1
     */
    public function update(int $siteId, ?string $name = null, ?array $urls = null): bool;

    /**
     * Delete a site and every visit Matomo recorded for it. True when Matomo confirmed it.
     *
     * @throws InvalidArgumentException for an id below 1
     */
    public function delete(int $siteId): bool;

    /**
     * The reason the last call failed; null after one that succeeded.
     */
    public function lastError(): ?string;
}

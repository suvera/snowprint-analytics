<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\site;

/**
 * Resolves tracked domains to site ids.
 */
interface SiteDirectory {

    /** Site id for $domain (lower-case, no "www."), or null when not tracked. */
    public function findSiteId(string $domain): ?int;
}

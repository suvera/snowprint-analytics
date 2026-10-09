<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\boot;

/**
 * The four Snowprint roles (PRD §9.0). `all` runs web, ingest and worker in
 * one process; the importer is a one-shot job added with SP-059.
 */
final class Roles {
    public const ALL = 'all';
    public const WEB = 'web';
    public const INGEST = 'ingest';
    public const WORKER = 'worker';

    /** @return class-string<BaseApplication> */
    public static function starterFor(string $role): string {
        return match ($role) {
            self::ALL => AllRolesApplication::class,
            self::WEB => WebApplication::class,
            self::INGEST => IngestApplication::class,
            self::WORKER => WorkerApplication::class,
            default => throw new \InvalidArgumentException(
                'Unknown SNOWPRINT_ROLE "' . $role . '"; use all, web, ingest or worker'
            ),
        };
    }
}

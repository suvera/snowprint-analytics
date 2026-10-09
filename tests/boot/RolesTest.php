<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\boot;

use dev\suvera\snowprint\boot\AllRolesApplication;
use dev\suvera\snowprint\boot\IngestApplication;
use dev\suvera\snowprint\boot\Roles;
use dev\suvera\snowprint\boot\WebApplication;
use dev\suvera\snowprint\boot\WorkerApplication;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase {

    public function testEachRoleMapsToItsStarter(): void {
        self::assertSame(AllRolesApplication::class, Roles::starterFor('all'));
        self::assertSame(WebApplication::class, Roles::starterFor('web'));
        self::assertSame(IngestApplication::class, Roles::starterFor('ingest'));
        self::assertSame(WorkerApplication::class, Roles::starterFor('worker'));
    }

    public function testUnknownRoleIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        Roles::starterFor('importer');
    }
}

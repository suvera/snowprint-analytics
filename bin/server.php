<?php
declare(strict_types=1);

use dev\suvera\snowprint\boot\Roles;

require dirname(__DIR__) . '/vendor/autoload.php';

// SNOWPRINT_ROLE picks the role: all (default) | web | ingest | worker.
// Winter Boot parses argv itself, so the role travels in the environment.
$role = getenv('SNOWPRINT_ROLE') ?: Roles::ALL;
Roles::starterFor($role)::main();

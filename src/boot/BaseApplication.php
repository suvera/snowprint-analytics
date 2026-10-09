<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\boot;

use dev\winterframework\core\app\WinterWebSwooleApplication;

/**
 * Shared Winter Boot starter base. Each role keeps its
 * own #[WinterBootApplication] attribute (scanNamespaces differ per role) and
 * extends this class; main() boots `static::class`.
 *
 * Plain abstract helper, never a bean.
 */
abstract class BaseApplication {
    final public static function main(): void {
        (new WinterWebSwooleApplication())->run(static::class);
    }
}

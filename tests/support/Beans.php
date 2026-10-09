<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\tests\support;

/**
 * Sets #[Autowired] private fields on beans under test, the way the
 * Winter Boot container does after construction.
 */
final class Beans {

    public static function inject(object $bean, string $field, mixed $value): object {
        // Private fields live on the declaring class, which may be a parent.
        for ($class = new \ReflectionClass($bean); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($field)) {
                $class->getProperty($field)->setValue($bean, $value);
                return $bean;
            }
        }
        throw new \LogicException('No field ' . $field . ' on ' . $bean::class);
    }
}

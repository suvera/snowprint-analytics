<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\mcp;

/**
 * Typed access to tools/call arguments; wrong types become ToolErrors the
 * model can read and correct.
 */
final class ToolArgs {

    /** @param array<string, mixed> $args */
    public function __construct(private readonly array $args) {
    }

    public function string(string $name, ?string $default = null): string {
        $value = $this->args[$name] ?? $default;
        if (!is_string($value) || trim($value) === '') {
            throw new ToolError("argument \"$name\" must be a non-empty string");
        }
        return trim($value);
    }

    public function optionalString(string $name): ?string {
        return isset($this->args[$name]) ? $this->string($name) : null;
    }

    public function int(string $name, int $default, int $min, int $max): int {
        $value = $this->args[$name] ?? $default;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new ToolError("argument \"$name\" must be an integer");
        }
        return max($min, min($max, (int) $value));
    }

    public function float(string $name, float $default): float {
        $value = $this->args[$name] ?? $default;
        if (!is_int($value) && !is_float($value)) {
            throw new ToolError("argument \"$name\" must be a number");
        }
        return (float) $value;
    }

    public function bool(string $name, bool $default): bool {
        $value = $this->args[$name] ?? $default;
        if (!is_bool($value)) {
            throw new ToolError("argument \"$name\" must be true or false");
        }
        return $value;
    }

    /** @return array<string, mixed>|null */
    public function object(string $name): ?array {
        $value = $this->args[$name] ?? null;
        if ($value === null || $value === []) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new ToolError("argument \"$name\" must be an object");
        }
        return $value;
    }
}

<?php

namespace Modules\Mcp\Server;

/**
 * Argument readers for tool handlers. Every one throws a ToolException
 * phrased for the model ("`aspect_ratio` must be one of …"), so a bad call
 * comes back as something it can fix instead of a stack trace.
 */
final class Args
{
    public function __construct(private array $args)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->args) && $this->args[$key] !== null;
    }

    public function raw(string $key): mixed
    {
        return $this->args[$key] ?? null;
    }

    public function string(string $key, bool $required = false, int $max = 2000, ?string $default = null): ?string
    {
        if (!$this->has($key)) {
            if ($required) {
                throw new ToolException("`{$key}` is required.");
            }

            return $default;
        }
        $value = $this->args[$key];
        if (!is_scalar($value)) {
            throw new ToolException("`{$key}` must be a string.");
        }
        $value = trim((string) $value);
        if ($required && $value === '') {
            throw new ToolException("`{$key}` must not be empty.");
        }
        if (mb_strlen($value) > $max) {
            throw new ToolException("`{$key}` is too long (max {$max} characters).");
        }

        return $value;
    }

    /** @param  string[]  $allowed */
    public function enum(string $key, array $allowed, ?string $default = null, bool $required = false): ?string
    {
        $value = $this->string($key, $required, 200, $default);
        if ($value === null) {
            return null;
        }
        if (!in_array($value, $allowed, true)) {
            throw new ToolException("`{$key}` must be one of: " . implode(', ', $allowed) . ".");
        }

        return $value;
    }

    public function number(string $key, ?float $min = null, ?float $max = null, ?float $default = null, bool $required = false): ?float
    {
        if (!$this->has($key)) {
            if ($required) {
                throw new ToolException("`{$key}` is required.");
            }

            return $default;
        }
        $value = $this->args[$key];
        if (!is_numeric($value)) {
            throw new ToolException("`{$key}` must be a number.");
        }
        $value = (float) $value;
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            throw new ToolException("`{$key}` must be between {$min} and {$max}.");
        }

        return $value;
    }

    public function int(string $key, ?int $min = null, ?int $max = null, ?int $default = null, bool $required = false): ?int
    {
        $value = $this->number($key, $min, $max, $default === null ? null : (float) $default, $required);

        return $value === null ? null : (int) round($value);
    }

    public function bool(string $key, ?bool $default = null): ?bool
    {
        if (!$this->has($key)) {
            return $default;
        }
        $value = $this->args[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, ['true', '1', 1], true)) {
            return true;
        }
        if (in_array($value, ['false', '0', 0], true)) {
            return false;
        }
        throw new ToolException("`{$key}` must be true or false.");
    }

    public function array(string $key, bool $required = false, int $maxItems = 500): ?array
    {
        if (!$this->has($key)) {
            if ($required) {
                throw new ToolException("`{$key}` is required.");
            }

            return null;
        }
        $value = $this->args[$key];
        // Some clients send nested objects as JSON strings.
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        if (!is_array($value)) {
            throw new ToolException("`{$key}` must be an " . ($maxItems > 0 ? 'array/object' : 'object') . '.');
        }
        if (count($value) > $maxItems) {
            throw new ToolException("`{$key}` has too many items (max {$maxItems}).");
        }

        return $value;
    }

    public function videoId(): int
    {
        $raw = $this->raw('video_id');
        if (!is_numeric($raw) || (int) $raw <= 0) {
            throw new ToolException('`video_id` is required (the number create_video returned; list_videos shows yours).');
        }

        return (int) $raw;
    }
}

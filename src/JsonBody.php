<?php

declare(strict_types=1);

namespace Hotels;

use Closure;
use Generator;

/**
 * Offsets into already validated JSON. Walking one value at a time keeps
 * the import from expanding the entire HTTP body into PHP arrays.
 */
final class JsonBody
{
    public function __construct(
        private readonly string $json,
        private readonly ?Closure $checkpoint = null,
    )
    {
    }

    public function fields(int $start, array $names): array
    {
        $fields = [];
        $cursor = $this->space($start + 1);
        for ($i = 0; $this->json[$cursor] !== '}'; ++$i) {
            $this->checkpointAt($i);
            $keyEnd = $this->stringEnd($cursor);
            $key = $this->decode($cursor, $keyEnd);
            $value = $this->space($this->space($keyEnd) + 1);
            $end = $this->valueEnd($value);
            if (isset($names[$key])) {
                $fields[$key] = [$value, $end];
            }
            $cursor = $this->space($end);
            if ($this->json[$cursor] === ',') {
                $cursor = $this->space($cursor + 1);
            }
        }
        return $fields;
    }

    public function elements(int $start): Generator
    {
        $cursor = $this->space($start + 1);
        while ($this->json[$cursor] !== ']') {
            $end = $this->valueEnd($cursor);
            yield [$cursor, $end];
            $cursor = $this->space($end);
            if ($this->json[$cursor] === ',') {
                $cursor = $this->space($cursor + 1);
            }
        }
    }

    public function stringArray(int $start, int $end): ?string
    {
        if ($this->type($start) !== '[') {
            return null;
        }
        $cursor = $this->space($start + 1);
        for ($i = 0; $this->type($cursor) === '"'; ++$i) {
            $this->checkpointAt($i);
            $cursor = $this->space($this->stringEnd($cursor));
            if ($this->type($cursor) !== ',') {
                break;
            }
            $cursor = $this->space($cursor + 1);
        }
        return $this->type($cursor) === ']' ? substr($this->json, $start, $end - $start) : null;
    }

    public function decode(int $start, int $end): mixed
    {
        return json_decode(substr($this->json, $start, $end - $start), flags: JSON_THROW_ON_ERROR);
    }

    public function root(): int
    {
        return $this->space(0);
    }

    public function type(int $offset): string
    {
        return $this->json[$offset];
    }

    private function space(int $offset): int
    {
        return $offset + strspn($this->json, " \t\r\n", $offset);
    }

    private function stringEnd(int $offset): int
    {
        ++$offset;
        for ($i = 0; ; ++$i) {
            $this->checkpointAt($i);
            $offset += strcspn($this->json, "\"\\", $offset);
            if ($this->json[$offset] === '"') {
                return $offset + 1;
            }
            $offset += 2;
        }
    }

    private function valueEnd(int $offset): int
    {
        $first = $this->type($offset);
        if ($first === '"') {
            return $this->stringEnd($offset);
        }
        if ($first !== '[' && $first !== '{') {
            return $offset + strcspn($this->json, ",]} \t\r\n", $offset);
        }

        $depth = 1;
        for ($i = 0; $depth > 0; ++$i) {
            $this->checkpointAt($i);
            ++$offset;
            $offset += strcspn($this->json, "{}[]\"", $offset);
            switch ($this->json[$offset]) {
                case '"':
                    $offset = $this->stringEnd($offset) - 1;
                    break;
                case '[':
                case '{':
                    ++$depth;
                    break;
                case ']':
                case '}':
                    --$depth;
                    break;
            }
        }
        return $offset + 1;
    }

    private function checkpointAt(int $iteration): void
    {
        if (($iteration & 8191) === 8191 && $this->checkpoint !== null) {
            ($this->checkpoint)();
        }
    }
}

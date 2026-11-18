<?php

declare(strict_types=1);

namespace Hotels;

final class Hotel
{
    private const FIELDS = [
        'id' => true, 'name' => true, 'city' => true, 'price' => true,
        'price_minor' => true, 'stars' => true, 'address' => true, 'amenities' => true,
    ];

    public static function supplierId(string $id): bool
    {
        return preg_match('/\Ah-[0-9]{7}\z/', $id) === 1;
    }

    public static function contractId(string $id): bool
    {
        return preg_match('/\Ac-[0-9]{1,12}\z/', $id) === 1;
    }

    public static function city(mixed $city): bool
    {
        return is_string($city) && preg_match('/\A[A-Z]{3}\z/', $city) === 1;
    }

    public static function priceMinor(mixed $price): ?int
    {
        if (!is_string($price) || !preg_match('/\A([0-9]+)(?:\.([0-9]{1,2}))?\z/', $price, $parts)) {
            return null;
        }
        $digits = ltrim($parts[1] . str_pad($parts[2] ?? '', 2, '0'), '0');
        $minor = self::unsigned($digits);
        return $minor > 0 ? $minor : null;
    }

    public static function unsigned(mixed $text): ?int
    {
        if (!is_string($text) || $text === '' || !ctype_digit($text)) {
            return null;
        }
        $digits = ltrim($text, '0');
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            return null;
        }
        return (int) $digits;
    }

    public static function read(JsonBody $body, int $start, ?string $contractId = null): ?array
    {
        if ($body->type($start) !== '{') {
            return null;
        }
        $fields = $body->fields($start, self::FIELDS);
        $id = $contractId ?? self::scalar($body, $fields['id'] ?? null);
        $name = self::scalar($body, $fields['name'] ?? null);
        $city = self::scalar($body, $fields['city'] ?? null);
        if (!is_string($id) || ($contractId === null && !self::supplierId($id))
            || !is_string($name) || $name === '' || !self::city($city)) {
            return null;
        }

        if ($contractId === null) {
            $price = self::priceMinor(self::scalar($body, $fields['price'] ?? null));
            if ($price === null) {
                return null;
            }
        } else {
            $price = self::scalar($body, $fields['price_minor'] ?? null);
            if (!is_int($price) || $price < 1 || $price > 100_000_000 || self::length($name) > 200) {
                return null;
            }
        }

        $stars = self::scalar($body, $fields['stars'] ?? null);
        $address = self::scalar($body, $fields['address'] ?? null);
        $amenities = isset($fields['amenities']) ? $body->stringArray(...$fields['amenities']) : '[]';
        if ((isset($fields['stars']) && (!is_int($stars) || $stars < 0 || $stars > 5))
            || (isset($fields['address']) && (!is_string($address)
                || ($contractId !== null && self::length($address) > 500)))
            || $amenities === null) {
            return null;
        }

        return [
            'id' => $id, 'name' => $name, 'city' => $city, 'price_minor' => $price,
            'stars' => $stars, 'address' => $address, 'amenities' => $amenities,
        ];
    }

    public static function json(array $row): string
    {
        $amenities = $row['amenities'];
        $row['source'] = self::contractId($row['id']) ? 'contract' : 'supplier';
        unset($row['amenities'], $row['fresh_at']);

        return substr(json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 0, -1)
            . ',"amenities":' . $amenities . '}';
    }

    private static function scalar(JsonBody $body, ?array $span): mixed
    {
        if ($span === null || in_array($body->type($span[0]), ['[', '{'], true)) {
            return null;
        }
        return $body->decode(...$span);
    }

    private static function length(string $text): int
    {
        return strlen($text) - strlen((string) preg_replace('/[^\x80-\xBF]/', '', $text));
    }
}

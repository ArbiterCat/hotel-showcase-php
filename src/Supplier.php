<?php

declare(strict_types=1);

namespace Hotels;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class Supplier
{
    private const MAX_IN_FLIGHT = 128;

    private Browser $client;
    private ?string $url = null;
    private int $version = 0;
    private float $retryAt = 0.0;
    private array $pending = [];
    private int $requests = 0;

    public function __construct()
    {
        $this->client = (new Browser())
            ->withTimeout(0.85)
            ->withResponseBuffer(1024 * 1024)
            ->withFollowRedirects(false)
            ->withRejectErrorResponse(false);
    }

    public function setSource(string $url): void
    {
        $this->url = rtrim($url, '/');
        $this->retryAt = 0.0;
        ++$this->version;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function requests(): int
    {
        return $this->requests;
    }

    public function retryAfter(): int
    {
        return (int) max(1, ceil($this->retryAt - self::now()));
    }

    public static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function quote(string $id, int $catalogVersion): ?PromiseInterface
    {
        $sourceVersion = $this->version;
        $key = "$sourceVersion:$catalogVersion:$id";
        if (isset($this->pending[$key])) {
            return $this->pending[$key];
        }
        if ($this->url === null || self::now() < $this->retryAt
            || count($this->pending) >= self::MAX_IN_FLIGHT) {
            return null;
        }

        try {
            $request = $this->client->get($this->url . '/hotel?id=' . rawurlencode($id));
        } catch (\InvalidArgumentException) {
            return resolve(['status' => 'unavailable']);
        }
        ++$this->requests;

        $promise = $request->then(
            fn (ResponseInterface $response) => $this->parse($response, $id, $sourceVersion),
            static fn () => ['status' => 'unavailable']
        );
        // A finally callback can run synchronously for an already-settled request.
        $this->pending[$key] = $promise;
        return $promise->finally(function () use ($key): void {
            unset($this->pending[$key]);
        });
    }

    private function parse(ResponseInterface $response, string $id, int $sourceVersion): array
    {
        if ($response->getStatusCode() === 503 && $sourceVersion === $this->version) {
            $delay = Hotel::unsigned(trim($response->getHeaderLine('Retry-After'))) ?? 1;
            $this->retryAt = max($this->retryAt, self::now() + $delay);
        }
        if ($response->getStatusCode() !== 200) {
            return ['status' => 'unavailable'];
        }

        $flags = LIBXML_NONET | (defined('LIBXML_NO_XXE') ? LIBXML_NO_XXE : 0);
        $xml = @simplexml_load_string((string) $response->getBody(), options: $flags);
        if ($xml === false || $xml->getName() !== 'HotelResponse') {
            return ['status' => 'unavailable'];
        }
        if ((string) ($xml->Error['code'] ?? '') === 'HOTEL_NOT_FOUND') {
            return ['status' => 'missing'];
        }

        $hotel = $xml->Hotel;
        $city = (string) $hotel['city'];
        $name = (string) $hotel->Name;
        $price = Hotel::priceMinor(trim((string) $hotel->Price));
        if ((string) $hotel['id'] !== $id || !Hotel::city($city) || $name === '' || $price === null) {
            return ['status' => 'unavailable'];
        }

        $row = ['id' => $id, 'name' => $name, 'city' => $city, 'price_minor' => $price];
        if (isset($hotel['stars'])) {
            $stars = Hotel::unsigned((string) $hotel['stars']);
            if ($stars !== null && $stars <= 5) {
                $row['stars'] = $stars;
            }
        }
        if (isset($hotel->Address)) {
            $row['address'] = (string) $hotel->Address;
        }
        if (isset($hotel->Amenities)) {
            $amenities = [];
            foreach ($hotel->Amenities->Amenity as $amenity) {
                $amenities[] = (string) $amenity;
            }
            $row['amenities'] = json_encode($amenities, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        return ['status' => 'ok', 'hotel' => $row, 'fresh_at' => self::now()];
    }
}

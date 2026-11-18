<?php

declare(strict_types=1);

namespace Hotels;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;

use function React\Promise\resolve;

final class App
{
    private const FRESH_SECONDS = 50;
    private const MAX_BODY = 15 * 1024 * 1024;
    private const BODY_BUDGET = 2 * self::MAX_BODY;

    private Import $import;
    private int $bodyBytes = 0;
    private int $servedLocal = 0;
    private int $servedFromSupplier = 0;

    public function __construct(private readonly Catalog $catalog, private readonly Supplier $supplier)
    {
        $this->import = new Import($catalog);
    }

    public function __invoke(ServerRequestInterface $request): Response|PromiseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        if ($method === 'POST' && $path === '/import') {
            return $this->withBody($request, $this->import->enqueue(...));
        }
        if ($method === 'POST' && $path === '/supplier/source') {
            return $this->withBody($request, $this->setSource(...));
        }
        if ($method === 'GET') {
            switch ($path) {
                case '/health':
                    return self::json(200, ['status' => 'healthy']);
                case '/stats':
                    return self::json(200, [
                        'served_local' => $this->servedLocal, 'served_from_supplier' => $this->servedFromSupplier,
                        'supplier_requests' => $this->supplier->requests(), 'hotels' => $this->catalog->count(),
                    ]);
                case '/hotels':
                    return $this->search($request->getQueryParams());
            }
        }
        if (!preg_match('~\A/hotels/([^/]*)\z~', $path, $matched)) {
            return self::error(404, 'not found');
        }

        $id = rawurldecode($matched[1]);
        return match ($method) {
            'PUT' => Hotel::contractId($id)
                ? $this->withBody($request, fn (?string $body) => $this->put($id, $body))
                : self::error(422, 'invalid contract hotel id', ['Connection' => 'close']),
            'DELETE' => $this->catalog->delete($id)
                ? self::json(200, ['deleted' => true])
                : self::error(404, 'not found'),
            'GET' => $this->card($id),
            default => self::error(404, 'not found'),
        };
    }

    private function setSource(?string $body): Response
    {
        $document = self::objectBody($body);
        $span = $document?->fields($document->root(), ['url' => true])['url'] ?? null;
        if ($span === null || $document->type($span[0]) !== '"') {
            return self::error(422, 'invalid supplier url');
        }
        $url = $document->decode(...$span);
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return self::error(422, 'invalid supplier url');
        }
        $this->supplier->setSource($url);
        return self::json(200, ['source' => $url]);
    }

    private function put(string $id, ?string $body): Response
    {
        $document = self::objectBody($body);
        $hotel = $document === null ? null : Hotel::read($document, $document->root(), $id);
        if ($hotel === null) {
            return self::error(422, 'invalid contract hotel');
        }
        $this->catalog->putContract($hotel);
        return self::raw(200, Hotel::json($hotel));
    }

    private function search(array $params): Response
    {
        $city = $params['city'] ?? null;
        if (!Hotel::city($city)) {
            return self::error(422, 'invalid city');
        }

        $values = [];
        foreach ([
            'max_price' => [1, PHP_INT_MAX, PHP_INT_MAX], 'stars_min' => [0, 5, 0],
            'limit' => [1, 100, 20], 'offset' => [0, PHP_INT_MAX, 0],
        ] as $name => [$min, $max, $default]) {
            if (!array_key_exists($name, $params)) {
                $values[] = $default;
                continue;
            }
            $raw = $params[$name];
            $value = Hotel::unsigned($raw);
            if ($value === null && $max === PHP_INT_MAX && is_string($raw) && ctype_digit($raw)) {
                $value = PHP_INT_MAX;
            }
            if ($value === null || $value < $min || $value > $max) {
                return self::error(422, "invalid $name");
            }
            $values[] = $value;
        }
        return self::raw(200, $this->catalog->search($city, ...$values));
    }

    private function card(string $id, bool $retryOnChange = true): Response|PromiseInterface
    {
        if ((!Hotel::supplierId($id) && !Hotel::contractId($id)) || $this->catalog->isHidden($id)) {
            return self::error(404, 'not found');
        }
        $cached = $this->catalog->get($id);
        if (Hotel::contractId($id)) {
            return $cached === null ? self::error(404, 'not found') : $this->served($cached, false);
        }
        if ($cached !== null && Supplier::now() < $cached['fresh_at'] + self::FRESH_SECONDS) {
            return $this->served($cached, false);
        }

        $catalogVersion = $this->catalog->version();
        $sourceVersion = $this->supplier->version();
        $quote = $this->supplier->quote($id, $catalogVersion);
        if ($quote === null) {
            return $this->fallback($cached, false);
        }

        return $quote->then(function (array $result) use ($id, $catalogVersion, $sourceVersion, $retryOnChange, $cached) {
            if ($this->catalog->isHidden($id)) {
                return self::error(404, 'not found');
            }
            $current = $this->catalog->get($id);
            if ($catalogVersion !== $this->catalog->version()) {
                if ($current !== null) {
                    return $this->served($current, true);
                }
                if ($cached !== null) {
                    return self::error(404, 'not found');
                }
            }
            if ($catalogVersion !== $this->catalog->version() || $sourceVersion !== $this->supplier->version()) {
                return $retryOnChange ? $this->card($id, false) : $this->fallback($current, true);
            }
            if ($result['status'] === 'missing') {
                $this->catalog->removeSupplier($id);
                return self::error(404, 'not found');
            }
            if ($result['status'] === 'ok') {
                $hotel = $result['hotel'] + ($current ?? []) + ['stars' => null, 'address' => null, 'amenities' => '[]'];
                $this->catalog->putQuote($hotel, $result['fresh_at']);
                return $this->served($hotel, true);
            }
            return $this->fallback($current, true);
        });
    }

    private function served(array $hotel, bool $consulted): Response
    {
        if ($consulted) {
            ++$this->servedFromSupplier;
        } else {
            ++$this->servedLocal;
        }
        return self::raw(200, Hotel::json($hotel), ['X-Source' => $consulted ? 'SUPPLIER' : 'LOCAL']);
    }

    private function fallback(?array $cached, bool $consulted): Response
    {
        return $cached === null
            ? self::error(503, 'supplier unavailable', ['Retry-After' => (string) $this->supplier->retryAfter()])
            : $this->served($cached, $consulted);
    }

    private function withBody(ServerRequestInterface $request, callable $handle): Response|PromiseInterface
    {
        $body = $request->getBody();
        $reserved = Hotel::unsigned($request->getHeaderLine('Content-Length')) ?? self::MAX_BODY;
        if ($reserved > self::MAX_BODY) {
            return resolve($handle(null))->then(fn (Response $response) => $response->withHeader('Connection', 'close'));
        }
        if ($this->bodyBytes + $reserved > self::BODY_BUDGET) {
            return self::error(503, 'request body capacity reached', ['Retry-After' => '1', 'Connection' => 'close']);
        }

        $this->bodyBytes += $reserved;
        return $this->readBody($body, $reserved)->then($handle)->finally(function () use ($reserved): void {
            $this->bodyBytes -= $reserved;
        });
    }

    private function readBody(mixed $body, int $limit): PromiseInterface
    {
        if (!$body instanceof ReadableStreamInterface) {
            return resolve((string) $body);
        }

        $received = '';
        $deferred = new Deferred();
        $body->on('data', static function (string $chunk) use (&$received, $limit): void {
            if ($received !== null) {
                $received = strlen($received) + strlen($chunk) > $limit ? null : $received . $chunk;
            }
        });
        $body->on('end', static function () use ($deferred, &$received): void {
            $deferred->resolve($received);
        });
        $body->on('close', static function () use ($deferred): void {
            $deferred->resolve(null);
        });
        return $deferred->promise();
    }

    private static function objectBody(?string $body): ?JsonBody
    {
        if ($body === null || !json_validate($body)) {
            return null;
        }
        $document = new JsonBody($body);
        return $document->type($document->root()) === '{' ? $document : null;
    }

    public static function error(int $status, string $detail, array $headers = []): Response
    {
        return self::json($status, ['detail' => $detail], $headers);
    }

    public static function json(int $status, array $payload, array $headers = []): Response
    {
        return self::raw($status, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $headers);
    }

    private static function raw(int $status, string $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers, $body);
    }
}

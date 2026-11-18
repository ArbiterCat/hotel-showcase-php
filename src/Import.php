<?php

declare(strict_types=1);

namespace Hotels;

use Fiber;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\resolve;

final class Import
{
    private ?PromiseInterface $tail = null;

    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function enqueue(?string $body): PromiseInterface
    {
        if ($body === null || !json_validate($body)) {
            return resolve(App::error(400, 'invalid import body'));
        }

        $receivedAt = Supplier::now();
        $run = fn () => $this->schedule($body, $receivedAt);
        $promise = $this->tail === null ? $run() : $this->tail->then($run, $run);
        $this->tail = $promise;
        return $promise->finally(function () use ($promise): void {
            if ($this->tail === $promise) {
                $this->tail = null;
            }
        });
    }

    private function schedule(string $body, float $receivedAt): PromiseInterface
    {
        $deferred = new Deferred();
        $work = new Fiber(fn () => $this->import($body, $receivedAt));
        $step = function () use ($deferred, $work, &$step): void {
            try {
                if ($work->isStarted()) {
                    $work->resume();
                } else {
                    $work->start();
                }
                if (!$work->isTerminated()) {
                    Loop::futureTick($step);
                    return;
                }

                $step = null;
                $deferred->resolve($work->getReturn());
            } catch (Throwable $error) {
                $step = null;
                $deferred->reject($error);
            }
        };
        Loop::futureTick($step);
        return $deferred->promise();
    }

    private function import(string $body, float $receivedAt): Response
    {
        $document = new JsonBody($body, Fiber::suspend(...));
        $root = $document->root();
        $array = $document->type($root) === '{' ? ($document->fields($root, ['hotels' => true])['hotels'] ?? null) : null;
        if ($array === null || $document->type($array[0]) !== '[') {
            return App::error(400, 'hotels must be an array');
        }

        $this->catalog->beginImport();
        try {
            $batch = [];
            $skipped = 0;
            $processed = 0;
            foreach ($document->elements($array[0]) as [$start]) {
                $hotel = Hotel::read($document, $start);
                if ($hotel === null) {
                    ++$skipped;
                } else {
                    $batch[] = $hotel;
                }

                if (++$processed === 256) {
                    $this->catalog->stageBatch($batch, $receivedAt);
                    $batch = [];
                    $processed = 0;
                    Fiber::suspend();
                }
            }
            if ($batch !== []) {
                $this->catalog->stageBatch($batch, $receivedAt);
            }

            $counts = $this->catalog->publishImport();
            return App::json(200, ['imported' => $counts['imported'], 'skipped' => $skipped, 'dropped' => $counts['dropped']]);
        } catch (Throwable $error) {
            $this->catalog->discardImport();
            throw $error;
        }
    }
}

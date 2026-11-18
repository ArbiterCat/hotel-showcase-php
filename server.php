<?php

declare(strict_types=1);

use Hotels\App;
use Hotels\Catalog;
use Hotels\Supplier;
use React\Http\HttpServer;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\SocketServer;

require __DIR__ . '/vendor/autoload.php';

$server = new HttpServer(new StreamingRequestMiddleware(), new App(new Catalog(), new Supplier()));
$server->on('error', static function (Throwable $error): void {
    fwrite(STDERR, (string) ($error->getPrevious() ?? $error) . PHP_EOL);
});
$socket = new SocketServer('0.0.0.0:8000');
$server->listen($socket);

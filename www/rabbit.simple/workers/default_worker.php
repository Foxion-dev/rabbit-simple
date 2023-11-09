<?php
require_once  __DIR__ . '/../vendor/autoload.php';

use App\RabbitMQ\Client;

include_once __DIR__ . '/config.php';
sleep(5);

try {
    $rabbit = new Client('default');
    $rabbit->processing();
} catch (Throwable $e) {
    // TODO обработка ошибок, в том числе фатальных
    echo $e->getMessage();
}

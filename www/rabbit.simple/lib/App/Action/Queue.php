<?php

namespace App\Action;

use App\RabbitMQ\Client;
use PhpAmqpLib\Exception\AMQPRuntimeException;

class Queue extends BaseAction
{
    public function add()
    {
        try {
            $rabbit = new Client('default');
            return $rabbit->addItemToQueue($this->postData);
        } catch (AMQPRuntimeException | \Exception $e) {
            return [
                'status' => 'error',
                'errors' => $e->getMessage()
            ];
        }
    }

    public function update()
    {
        try {
            $rabbit = new Client('default');
            return $rabbit->addItemToQueue($this->postData);
        } catch (AMQPRuntimeException | \Exception $e) {
            return [
                'status' => 'error',
                'errors' => $e->getMessage()
            ];
        }
    }
}
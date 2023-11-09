<?php

namespace App\RabbitMQ;

use App\Helper;
use Dotenv\Dotenv;
use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPIOWaitException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class Client
{
    protected static string $USER;
    protected static string $PASSWORD;
    protected static string $HOST;
    protected static int $PORT;
    protected static string $VHOST;
    protected const TIMEOUT = 120000;
    protected const COUNT_RETRY = 5;

    protected AMQPStreamConnection $connection;
    protected $channel;

    protected string $exchange;
    protected string $queue;
    protected string $exchange_error;
    protected string $queue_error;
    protected array $errors;
    protected string $queue_retry;
    protected string $exchange_retry;

    /**
     * @throws Exception
     * @throws AMQPRuntimeException
     */
    public function __construct($name = '')
    {
        try {
            $this->setConfig();
            $this->connection = $this->getConnection();
            $this->channel = $this->getChannel();
            $this->channel->basic_qos(null, 1, null);

            if (!empty($name)) {
                $this->setNames($name);
                $this->setErrorQueue();
                $this->setRetryQueue();
                $this->setDefaultQueue();
                $this->setBinds();
            }

            if (extension_loaded('pcntl')) {
                declare(ticks=1);
                pcntl_signal(SIGTERM, [$this, 'signalHandler']);
                pcntl_signal(SIGHUP, [$this, 'signalHandler']);
                pcntl_signal(SIGINT, [$this, 'signalHandler']);
                pcntl_signal(SIGQUIT, [$this, 'signalHandler']);
                pcntl_signal(SIGALRM, [$this, 'alarmHandler']);
                echo 'Start signal handling...' . PHP_EOL;
            }

        } catch (AMQPRuntimeException|Exception $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    /**
     * @throws Exception
     */
    public function addItemToQueue(array $data): array
    {
        try {
            $msg = new AMQPMessage(json_encode($data, JSON_UNESCAPED_UNICODE), ['delivery_mode' => 2]);
            $this->channel->basic_publish($msg, $this->exchange, $this->queue);
            $this->close();

            return [
                'status' => 'success',
            ];
        } catch (Throwable $t) {
            return [
                'status' => 'error',
                'errors' => $t->getMessage()
            ];
        }
    }

    /**
     * @throws Exception
     */
    public function processing()
    {
        try {
            $callback = function ($msg) {
                Helper::logger('message default');
                Helper::logger($msg->body);

                // TODO какие-то действия, которые вернут result
                $statuses = ['success', 'error'];
                $result = [
                    'status' => $statuses[rand(0,1)],
                    'data' => []
                ];

                if ($result['status'] === 'success') {
                    $msg->ack();
                } else {
                    if (self::canRetry($msg->get_properties())) {
                        $msg->reject(false);
                    } else {
                        self::setNotification($msg->body, []);
                        $this->channel->basic_publish($msg, $this->exchange_error, $this->queue_error);
                        $msg->ack();
                    }
                }
            };

            $this->consume($callback);

        } catch (Exception | AMQPIOWaitException  $e ) {
            Helper::logger("Ошибка при обработке очереди  \n" . $e->getMessage());
        }
    }

    /**
     * @throws Exception
     */
    public function getConnection(): AMQPStreamConnection
    {
        return AMQPStreamConnection::create_connection(
            [
                [
                    'host' => static::$HOST,
                    'port' => static::$PORT,
                    'user' => static::$USER,
                    'password' => static::$PASSWORD,
                    'vhost' => static::$VHOST
                ]
            ],
            [
                'insist' => false,
                'login_method' => 'AMQPLAIN',
                'login_response' => null,
                'locale' => 'en_US',
                'connection_timeout' => 3.0,
                'read_write_timeout' => 10.0,
                'context' => null,
                'keepalive' => false,
                'heartbeat' => 50
            ]
        );
    }

    /**
     * @throws Exception
     */
    protected function getChannel()
    {
        return $this->connection->channel();
    }

    /**
     * @throws Exception
     */
    protected function close()
    {
        $this->channel->close();
        $this->connection->close();
    }

    protected function setDefaultQueue()
    {
        $this->channel->queue_declare(
            $this->queue,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-queue-type' => 'classic',
                'x-dead-letter-exchange' => $this->exchange_retry,
                'x-dead-letter-routing-key' => $this->queue_retry,
            ]),
        );

        $this->channel->exchange_declare(
            $this->exchange,
            'direct',
            false,
            true,
            false,
        );
    }

    protected function setRetryQueue()
    {
        $this->channel->queue_declare(
            $this->queue_retry,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-queue-type' => 'classic',
                'x-dead-letter-exchange' => $this->exchange,
                'x-dead-letter-routing-key' => $this->queue,
                'x-message-ttl' => self::TIMEOUT,
            ]),
        );

        $this->channel->exchange_declare(
            $this->exchange_retry,
            'direct',
            false,
            true,
            false
        );
    }

    protected function setErrorQueue()
    {
        $this->channel->queue_declare(
            $this->queue_error,
            false,
            true,
            false,
            false,
        );

        $this->channel->exchange_declare(
            $this->exchange_error,
            'direct',
            false,
            true,
            false
        );
    }

    protected function setBinds()
    {
        $this->channel->queue_bind($this->queue, $this->exchange, $this->queue);
        $this->channel->queue_bind($this->queue_error, $this->exchange_error, $this->queue_error);
        $this->channel->queue_bind($this->queue_retry, $this->exchange_retry, $this->queue_retry);
    }

    protected static function canRetry($props): bool
    {
        $death = $props['application_headers']['x-death'] ?? [];
        Helper::logger('Попытка #' . ((int)($death[0]['count'] ?? 0) + 1));

        if(!$death) return true;
        if($death[0]['count'] < self::COUNT_RETRY)  return true;

        return false;
    }


    /**
     * @throws \ErrorException
     */
    protected function consume($callback)
    {
        if ($this->channel) {
            $this->channel->basic_consume(
                $this->queue,
                '',
                false,
                false,
                false,
                false,
                $callback
            );
            $this->channel->consume();
        }
    }

    /**
     * @throws Exception
     */
    public function signalHandler($signalNumber)
    {
        echo 'Handling signal: #' . $signalNumber . PHP_EOL;

        switch ($signalNumber) {
            case SIGTERM:  // 15 : supervisor default stop
            case SIGQUIT:  // 3  : kill -s QUIT
            case SIGINT:   // 2  : ctrl+c
            case SIGHUP:   // 1  : kill -s HUP
                $this->close();
                break;
            default:
                break;
        }
    }

    public function alarmHandler($signalNumber)
    {
        echo 'Handling alarm: #' . $signalNumber . PHP_EOL;

        echo memory_get_usage(true) . PHP_EOL;
    }

    protected static function setNotification (string $message, array $result)
    {
        Helper::logger("Сообщение не отработало трижды \n" . $message);
//        $logFile = www\rabbit.simple\lib\App\Helper::logger(print_r($result, true));

//        $tg = new \App\TG\Client();
//        $tg->sendMessage("Сообщение с структурой не отработало трижды \n" . $logFile);
    }

    private function setNames($name)
    {
        $this->exchange = $name;
        $this->queue = $name;
        $this->exchange_retry = $name.'_retry';
        $this->queue_retry = $name.'_retry';
        $this->exchange_error = $name.'_error';
        $this->queue_error = $name.'_error';
    }

    private function setConfig()
    {
        $dotenv = Dotenv::createImmutable(ROOT_DIR);
        $dotenv->load();

        self::$HOST = $_ENV['RABBIT_HOST'];
        self::$PORT = $_ENV['RABBIT_PORT'];
        self::$VHOST = $_ENV['RABBIT_VHOST'];
        self::$USER = $_ENV['RABBIT_USER'];
        self::$PASSWORD = $_ENV['RABBIT_PASSWORD'];
    }
}
<?php

declare(strict_types=1);


namespace Denismitr\LaraRabbit\Queue;

use ErrorException;
use Exception;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Denismitr\LaraRabbit\Queue\Jobs\RabbitMQJob;

class RabbitMQQueue extends Queue implements QueueContract
{
    /**
     * The RabbitMQ connection instance.
     *
     * @var AbstractConnection
     */
    protected $connection;

    /**
     * The RabbitMQ channel instance.
     *
     * @var AMQPChannel
     */
    protected $channel;

    /**
     * The name of the default queue.
     *
     * @var string
     */
    protected $default;

    /**
     * List of already declared exchanges.
     *
     * @var array
     */
    protected $exchanges = [];

    /**
     * List of already declared queues.
     *
     * @var array
     */
    protected $queues = [];

    /**
     * List of already bound queues to exchanges.
     *
     * @var array
     */
    protected $boundQueues = [];

    /**
     * Current job being processed.
     *
     * @var RabbitMQJob
     */
    protected $currentJob;

    public function __construct(
        AbstractConnection $connection,
        string $default
    ) {
        $this->connection = $connection;
        $this->channel = $connection->channel();
        $this->default = $default;
    }

    /**
     * {@inheritdoc}
     *
     * @throws AMQPProtocolChannelException
     */
    public function size($queue = null): int
    {
        $queue = $this->getQueueName($queue);

        if (! $this->isQueueExists($queue)) {
            return 0;
        }

        // create a temporary channel, so the main channel will not be closed on exception
        $channel = $this->connection->channel();
        [, $size] = $channel->queue_declare($queue, true);
        $channel->close();

        return $size;
    }

    /**
     * {@inheritdoc}
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue, []);
    }

    /**
     * {@inheritdoc}
     */
    public function pushRaw($payload, $queueName = null, array $options = [])
    {
        $queueName = $this->getQueueName($queueName);

        $this->declareExchange($queueName);
        $this->declareQueue($queueName, true, false, [
            'x-dead-letter-exchange' => $queueName,
            'x-dead-letter-routing-key' => $queueName,
        ]);
        $this->bindQueue($queueName, $queueName, $queueName);

        [$message, $correlationId] = $this->createMessage($payload);

        $this->channel->basic_publish($message, $queueName, $queueName, true, false);

        return $correlationId;
    }

    /**
     * {@inheritdoc}
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->laterRaw(
            $delay,
            $this->createPayload($job, $queue, $data),
            $queue
        );
    }

    public function laterRaw($delay, $payload, $queue = null, $attempts = 0)
    {
        $ttl = $this->secondsUntil($delay) * 1000;

        if ($ttl < 0) {
            return $this->pushRaw($payload, $queue, []);
        }

        $destinationQueue = $this->getQueueName($queue);
        $delayedQueue = $this->getQueueName($queue).'.delay.'.$ttl;

        $this->declareExchange($destinationQueue);
        $this->declareQueue($destinationQueue, true, false, [
            'x-dead-letter-exchange' => $destinationQueue,
            'x-dead-letter-routing-key' => $destinationQueue,
        ]);
        $this->declareQueue($delayedQueue, true, false, [
            'x-dead-letter-exchange' => $destinationQueue,
            'x-dead-letter-routing-key' => $destinationQueue,
            'x-message-ttl' => $ttl,
        ]);
        $this->bindQueue($destinationQueue, $destinationQueue, $destinationQueue);

        [$message, $correlationId] = $this->createMessage($payload, $attempts);

        $this->channel->basic_publish($message, null, $delayedQueue, true, false);

        return $correlationId;
    }

    /**
     * {@inheritdoc}
     */
    public function bulk($jobs, $data = '', $queue = null): void
    {
        $queue = $this->getQueueName($queue);

        foreach ((array) $jobs as $job) {
            [$message] = $this->createMessage(
                $this->createPayload($job, $queue, $data)
            );

            $this->declareExchange($queue);
            $this->declareQueue($queue, true, false, [
                'x-dead-letter-exchange' => $queue,
                'x-dead-letter-routing-key' => $queue,
            ]);
            $this->bindQueue($queue, $queue, $queue);

            $this->channel->batch_basic_publish($message, $queue, $queue);
        }

        $this->channel->publish_batch();
    }

    /**
     * {@inheritdoc}
     *
     * @throws Exception
     */
    public function pop($queue = null)
    {
        try {
            $queue = $this->getQueueName($queue);

            /** @var AMQPMessage|null $message */
            if ($message = $this->channel->basic_get($queue)) {
                return $this->currentJob = new RabbitMQJob(
                    $this->container,
                    $this,
                    $message,
                    $this->connectionName,
                    $queue
                );
            }
        } catch (AMQPProtocolChannelException $exception) {
            // if there is not exchange or queue AMQP will throw exception with code 404
            // we need to catch it and return null
            if ($exception->amqp_reply_code === 404) {
                return null;
            }

            throw $exception;
        }

        return null;
    }

    public function getConnection(): AbstractConnection
    {
        return $this->connection;
    }

    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    public function getQueueName($queue = null): string
    {
        return $queue ?: $this->default;
    }

    /**
     * @param string $exchange
     * @return bool
     * @throws AMQPProtocolChannelException
     */
    public function isExchangeExists(string $exchange): bool
    {
        try {
            // create a temporary channel, so the main channel will not be closed on exception
            $channel = $this->connection->channel();
            $channel->exchange_declare($exchange, '', true);
            $channel->close();

            return true;
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->amqp_reply_code === 404) {
                return false;
            }

            throw $exception;
        }
    }

    public function declareExchange(
        string $name,
        string $type = AMQPExchangeType::DIRECT,
        bool $durable = true,
        bool $autoDelete = false
    ): void {
        if (in_array($name, $this->exchanges, true)) {
            return;
        }

        $this->channel->exchange_declare(
            $name,
            $type,
            false,
            $durable,
            $autoDelete,
            false,
            true
        );
    }

    /**
     * @param string $name
     * @return bool
     * @throws AMQPProtocolChannelException
     */
    public function isQueueExists(?string $name = null): bool
    {
        try {
            $name = $this->getQueueName($name);

            // create a temporary channel, so the main channel will not be closed on exception
            $channel = $this->connection->channel();
            $channel->queue_declare($name, true);
            $channel->close();

            return true;
        } catch (AMQPProtocolChannelException $exception) {
            if ($exception->amqp_reply_code === 404) {
                return false;
            }

            throw $exception;
        }
    }

    public function declareQueue(string $name, bool $durable = true, bool $autoDelete = false, array $arguments = []): void
    {
        if (in_array($name, $this->queues, true)) {
            return;
        }

        $this->channel->queue_declare(
            $name,
            false,
            $durable,
            false,
            $autoDelete,
            false,
            new AMQPTable($arguments)
        );
    }

    public function bindQueue(string $queue, string $exchange, string $routingKey = ''): void
    {
        if (in_array(
            implode('', compact('queue', 'exchange', 'routingKey')),
            $this->boundQueues,
            true
        )) {
            return;
        }

        $this->channel->queue_bind($queue, $exchange, $routingKey);
    }

    public function purge($queue = null): void
    {
        // create a temporary channel, so the main channel will not be closed on exception
        $channel = $this->connection->channel();
        $channel->queue_purge($this->getQueueName($queue));
        $channel->close();
    }

    public function ack(RabbitMQJob $job): void
    {
        $this->channel->basic_ack($job->getRabbitMQMessage()->getDeliveryTag());
    }

    public function reject(RabbitMQJob $job, bool $requeue = false): void
    {
        $this->channel->basic_reject($job->getRabbitMQMessage()->getDeliveryTag(), $requeue);
    }

    protected function createMessage($payload, int $attempts = 0): array
    {
        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        if ($correlationId = json_decode($payload, true)['id'] ?? null) {
            $properties['correlation_id'] = $correlationId;
        }

        $message = new AMQPMessage($payload, $properties);

        $message->set('application_headers', new AMQPTable([
            'laravel' => [
                'attempts' => $attempts,
            ],
        ]));

        return [
            $message,
            $correlationId,
        ];
    }

    protected function createPayloadArray($job, $queue, $data = '')
    {
        return array_merge(parent::createPayloadArray($job, $queue, $data), [
            'id' => $this->getRandomId(),
        ]);
    }

    /**
     * Get a random ID string.
     *
     * @return string
     */
    protected function getRandomId(): string
    {
        return Str::random(32);
    }

    /**
     * @throws Exception
     */
    public function close(): void
    {
        if ($this->currentJob && ! $this->currentJob->isDeletedOrReleased()) {
            $this->reject($this->currentJob);
        }

        try {
            $this->connection->close();
        } catch (ErrorException $exception) {
            // Ignore the exception
        }
    }
}

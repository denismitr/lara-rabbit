<?php

namespace Denismitr\LaraRabbit\Queue\Connectors;

use Exception;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use Denismitr\LaraRabbit\Horizon\Listeners\RabbitMQFailedEvent;
use Denismitr\LaraRabbit\Horizon\RabbitMQQueue as HorizonRabbitMQQueue;
use Denismitr\LaraRabbit\Queue\RabbitMQQueue;

class RabbitMQConnector implements ConnectorInterface
{
    /**
     * @var Dispatcher
     */
    private $dispatcher;

    public function __construct(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Establish a queue connection.
     *
     * @param array $config
     *
     * @return RabbitMQQueue
     * @throws Exception
     */
    public function connect(array $config): Queue
    {
        $connection = $this->createConnection($config);

        $queue = $this->createQueue(
            Arr::get($config, 'worker', 'default'),
            $connection,
            $config['queue']
        );

        if (! $queue instanceof RabbitMQQueue) {
            throw new InvalidArgumentException('Invalid worker.');
        }

        if ($queue instanceof HorizonRabbitMQQueue) {
            $this->dispatcher->listen(JobFailed::class, RabbitMQFailedEvent::class);
        }

        $this->dispatcher->listen(WorkerStopping::class, static function () use ($queue): void {
            $queue->close();
        });

        return $queue;
    }

    /**
     * @param array $config
     * @return AbstractConnection
     * @throws Exception
     */
    protected function createConnection(array $config): AbstractConnection
    {
        /** @var AbstractConnection $connection */
        $connection = Arr::get($config, 'connection', AMQPLazyConnection::class);

        $hosts = Arr::shuffle(Arr::get($config, 'hosts', []));

        // manually disable heartbeat so long-running tasks will not fail
        $config['options']['heartbeat'] = 0;

        return $connection::create_connection(
            $hosts,
            $this->filter(Arr::get($config, 'options', []))
        );
    }

    protected function createQueue(string $worker, AbstractConnection $connection, string $queue)
    {
        switch ($worker) {
            case 'default':
                return new RabbitMQQueue($connection, $queue);
            case 'horizon':
                return new HorizonRabbitMQQueue($connection, $queue);
            default:
                return new $worker($connection, $queue);
        }
    }

    private function filter(array $array): array
    {
        foreach ($array as $index => &$value) {
            if (is_array($value)) {
                $value = $this->filter($value);
                continue;
            }

            // If the value is null then remove it.
            if ($value === null) {
                unset($array[$index]);
                continue;
            }
        }

        return $array;
    }
}

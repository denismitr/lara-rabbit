<?php

namespace Denismitr\LaraRabbit\Console;

use Exception;
use Illuminate\Console\Command;
use Denismitr\LaraRabbit\Queue\Connectors\RabbitMQConnector;

class QueueScaffoldCommand extends Command
{
    protected $signature = 'rabbitmq:scaffold                          
                           {queue? : The name of the queue to declare}
                           {exchange? : The name of exchange to create}
                           {routing-key? : The name of the routing key to use in binding}
                           {connection=rabbitmq : The name of the queue connection to use}
                            ';

    protected $description = 'Scaffold rabbitMQ queue and exchange';

    /**
     * @param RabbitMQConnector $connector
     * @throws Exception
     */
    public function handle(RabbitMQConnector $connector): void
    {
        $config = $this->laravel['config']->get('queue.connections.'.$this->argument('connection'));

        $queue = $connector->connect($config);

        $queueName = $this->argument('queue') ?: $config['queue'];
        $exchangeName = $this->argument('exchange') ?: $queueName;
        $routingKey = $this->argument('routing-key') ?: $queueName;

        $queue->declareExchange($exchangeName);
        $queue->declareQueue($queueName, true, false, [
            'x-dead-letter-exchange' => $exchangeName,
            'x-dead-letter-routing-key' => $routingKey,
        ]);

        $queue->bindQueue($queueName, $exchangeName, $routingKey);

        $this->info(
            "Queue {$queueName} bound to exchange ${exchangeName} with routing key ${routingKey} successfully."
        );
    }
}

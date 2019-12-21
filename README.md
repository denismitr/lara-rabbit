Laravel RabbitMQ driver for queues
======================

Forked from `https://github.com/vyuldashev/laravel-queue-rabbitmq`

## Installation

You can install this package via composer using this command:

```
composer require denismitr/lara-rabbit
```

The package will automatically register itself.

Add connection to `config/queue.php`:

```php
'connections' => [
    // ...

    'rabbitmq' => [
    
       'driver' => 'rabbitmq',
       'queue' => env('RABBITMQ_QUEUE', 'default'),
       'connection' => PhpAmqpLib\Connection\AMQPLazyConnection::class,
   
       'hosts' => [
           [
               'host' => env('RABBITMQ_HOST', '127.0.0.1'),
               'port' => env('RABBITMQ_PORT', 5672),
               'user' => env('RABBITMQ_USER', 'guest'),
               'password' => env('RABBITMQ_PASSWORD', 'guest'),
               'vhost' => env('RABBITMQ_VHOST', '/'),
           ],
       ],
   
       'options' => [
           'ssl_options' => [
               'cafile' => env('RABBITMQ_SSL_CAFILE', null),
               'local_cert' => env('RABBITMQ_SSL_LOCALCERT', null),
               'local_key' => env('RABBITMQ_SSL_LOCALKEY', null),
               'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
               'passphrase' => env('RABBITMQ_SSL_PASSPHRASE', null),
           ],

           'exchange' => env('RABBITMQ_EXCHANGE', 'lara_rabbit_exchange'),
       ],        
    ],

    // ...    
],
```

## Laravel Usage

Once you completed the configuration you can use Laravel Queue API. If you used other queue drivers you do not need to change anything else. If you do not know how to use Queue API, please refer to the official Laravel documentation: http://laravel.com/docs/queues

## Laravel Horizon Usage

Starting with 8.0, this package supports [Laravel Horizon](http://horizon.laravel.com) out of the box. Firstly, install Horizon and then set `RABBITMQ_WORKER` to `horizon`.

## Lumen Usage

For Lumen usage the service provider should be registered manually as follow in `bootstrap/app.php`:

```php
$app->register(Denismitr\LaraRabbit\LaraRabbitServiceProvider::class);
```

## Consuming Messages

There are two ways of consuming messages. 

1. `queue:work` command which is Laravel's built-in command. This command utilizes `basic_get`.

2. `rabbitmq:consume` command which is provided by this package. This command utilizes `basic_consume` and is more performant than `basic_get` by ~2x.

## Testing

Setup RabbitMQ using `docker-compose`:

```bash
docker-compose up -d rabbitmq
```

To run the test suite you can use the following commands:

```bash
# To run both style and unit tests.
composer test

# To run only style tests.
composer test:style

# To run only unit tests.
composer test:unit
```

If you receive any errors from the style tests, you can automatically fix most,
if not all of the issues with the following command:

```bash
composer fix:style
```

<?php

namespace Denismitr\LaraRabbit\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use Denismitr\LaraRabbit\Queue\Jobs\RabbitMQJob;
use Denismitr\LaraRabbit\Tests\Mocks\TestJob;
use Denismitr\LaraRabbit\Tests\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @throws AMQPProtocolChannelException
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->connection()->isQueueExists()) {
            $this->connection()->purge();
        }
    }

    /**
     * @throws AMQPProtocolChannelException
     */
    protected function tearDown(): void
    {
        if ($this->connection()->isQueueExists()) {
            $this->connection()->purge();
        }

        $this->assertSame(0, Queue::size());

        parent::tearDown();
    }

    public function testSizeDoesNotThrowExceptionOnUnknownQueue(): void
    {
        $this->assertEmpty(0, Queue::size(Str::random()));
    }

    public function testPopNothing(): void
    {
        $this->assertNull(Queue::pop('foo'));
    }

    public function testPushRaw(): void
    {
        Queue::pushRaw($payload = Str::random());

        sleep(1);

        $this->assertSame(1, Queue::size());
        $this->assertNotNull($job = Queue::pop());
        $this->assertSame(0, $job->attempts());
        $this->assertInstanceOf(RabbitMQJob::class, $job);
        $this->assertSame($payload, $job->getRawBody());

        $this->assertNull($job->getJobId());

        $job->delete();

        $this->assertSame(0, Queue::size());
    }

    public function testPush(): void
    {
        Queue::push(new TestJob());

        sleep(1);

        $this->assertSame(1, Queue::size());
        $this->assertNotNull($job = Queue::pop());
        $this->assertSame(0, $job->attempts());
        $this->assertInstanceOf(RabbitMQJob::class, $job);
        $this->assertSame(TestJob::class, $job->resolveName());
        $this->assertNotNull($job->getJobId());

        $payload = $job->payload();

        $this->assertSame(TestJob::class, $payload['displayName']);
        $this->assertSame('Illuminate\Queue\CallQueuedHandler@call', $payload['job']);
        $this->assertNull($payload['maxTries']);
        $this->assertNull($payload['delay']);
        $this->assertNull($payload['timeout']);
        $this->assertNull($payload['timeoutAt']);
        $this->assertSame($job->getJobId(), $payload['id']);
    }

    public function testLaterRaw(): void
    {
        $payload = Str::random();
        $data = [Str::random() => Str::random()];

        Queue::later(3, $payload, $data);

        sleep(1);

        $this->assertSame(0, Queue::size());
        $this->assertNull(Queue::pop());

        sleep(3);

        $this->assertSame(1, Queue::size());
        $this->assertNotNull($job = Queue::pop());

        $this->assertInstanceOf(RabbitMQJob::class, $job);
        $this->assertSame($payload, $job->getName());

        $body = json_decode($job->getRawBody(), true);

        $this->assertSame($payload, $body['displayName']);
        $this->assertSame($payload, $body['job']);
        $this->assertSame($data, $body['data']);

        $job->delete();

        $this->assertSame(0, Queue::size());
    }

    public function testLater(): void
    {
        Queue::later(3, new TestJob());

        sleep(1);

        $this->assertSame(0, Queue::size());
        $this->assertNull(Queue::pop());

        sleep(3);

        $this->assertSame(1, Queue::size());
        $this->assertNotNull($job = Queue::pop());

        $this->assertInstanceOf(RabbitMQJob::class, $job);

        $body = json_decode($job->getRawBody(), true);

        $this->assertSame(TestJob::class, $body['displayName']);
        $this->assertSame('Illuminate\Queue\CallQueuedHandler@call', $body['job']);
        $this->assertSame(TestJob::class, $body['data']['commandName']);
        $this->assertNotNull($job->getJobId());

        $job->delete();

        $this->assertSame(0, Queue::size());
    }

    public function testBulk(): void
    {
        $count = 100;
        $jobs = [];

        for ($i = 0; $i < $count; $i++) {
            $jobs[$i] = new TestJob($i);
        }

        Queue::bulk($jobs);

        sleep(1);

        $this->assertSame($count, Queue::size());
    }

    public function testReleaseRaw(): void
    {
        Queue::pushRaw($payload = Str::random());

        sleep(1);

        $this->assertSame(1, Queue::size());
        $this->assertNotNull($job = Queue::pop());
        $this->assertSame(0, $job->attempts());

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $job->release();

            sleep(1);

            $this->assertSame(1, Queue::size());

            $job = Queue::pop();

            $this->assertSame($attempt, $job->attempts());
        }
    }

    public function testRelease(): void
    {
        Queue::push(new TestJob());

        sleep(1);

        $this->assertSame(1, Queue::size());
        $this->assertNotNull($job = Queue::pop());
        $this->assertSame(0, $job->attempts());

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $job->release();

            sleep(1);

            $this->assertSame(1, Queue::size());

            $job = Queue::pop();

            $this->assertSame($attempt, $job->attempts());
        }
    }

    public function testReleaseWithDelayRaw(): void
    {
        Queue::pushRaw($payload = Str::random());

        sleep(1);

        $this->assertSame(1, Queue::size());
        $this->assertNotNull($job = Queue::pop());
        $this->assertSame(0, $job->attempts());

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $job->release(4);

            sleep(1);

            $this->assertSame(0, Queue::size());
            $this->assertNull(Queue::pop());

            sleep(4);

            $this->assertSame(1, Queue::size());

            $job = Queue::pop();

            $this->assertSame($attempt, $job->attempts());
        }
    }

    public function testReleaseInThePast(): void
    {
        Queue::push(new TestJob());

        $job = Queue::pop();
        $job->release(-3);

        sleep(1);

        $this->assertInstanceOf(RabbitMQJob::class, Queue::pop());
    }

    public function testReleaseAndReleaseWithDelayAttempts(): void
    {
        Queue::push(new TestJob());

        sleep(1);

        $this->assertSame(1, Queue::size());
        $this->assertNotNull($job = Queue::pop());

        $job->release();

        sleep(1);

        $this->assertNotNull($job = Queue::pop());
        $this->assertSame(1, $job->attempts());

        $job->release(3);

        sleep(4);

        $this->assertNotNull($job = Queue::pop());

        $this->assertSame(2, $job->attempts());
    }

    public function testDelete(): void
    {
        Queue::push(new TestJob());

        $job = Queue::pop();

        $job->delete();

        sleep(1);

        $this->assertSame(0, Queue::size());
        $this->assertNull(Queue::pop());
    }
}

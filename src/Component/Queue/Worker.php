<?php

declare(strict_types=1);

namespace Strux\Component\Queue;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use Strux\Component\Console\Output;
use Throwable;

class Worker implements WorkerInterface
{
    protected int $maxAttempts = 3;

    public function __construct(
        protected QueueInterface     $queue,
        protected LoggerInterface    $logger,
        protected ContainerInterface $container
    )
    {
    }

    public function process(string $queueName = 'default'): void
    {
        Output::info("Worker started processing queue: [{$queueName}]");

        while (true) {
            $jobRecord = $this->queue->pop($queueName);

            if ($jobRecord) {
                $this->executeJob($jobRecord);
            } else {
                sleep(1);
            }
        }
    }

    protected function executeJob(object $jobRecord): void
    {
        Output::info("Processing job ID: {$jobRecord->id} (Attempt: {$jobRecord->attempts})");
        try {
            $payload = json_decode($jobRecord->payload, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Failed to decode JSON payload.");
            }

            $jobInstance = unserialize($payload['data']['command']);

            if (!method_exists($jobInstance, 'handle')) {
                throw new \RuntimeException("Job missing handle() method.");
            }

            $dependencies = $this->resolveJobDependencies($jobInstance);
            $jobInstance->handle(...$dependencies);

            $this->queue->delete($jobRecord->id);
            Output::info("Processed job ID: {$jobRecord->id} ({$payload['displayName']})");

        } catch (Throwable $e) {
            Output::error("Job ID {$jobRecord->id} failed: " . $e->getMessage());

            if ($jobRecord->attempts >= $this->maxAttempts) {
                Output::error("Job ID {$jobRecord->id} exceeded max attempts. Moving to failed_jobs.");
                $this->queue->fail($jobRecord, $e);
                $this->queue->delete($jobRecord->id);
            } else {
                $this->queue->release($jobRecord->id, 60);
            }
        }
    }

    /**
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function resolveJobDependencies(object $jobInstance): array
    {
        $dependencies = [];
        $reflectionMethod = new ReflectionMethod($jobInstance, 'handle');

        foreach ($reflectionMethod->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $this->container->get($type->getName());
            }
        }

        return $dependencies;
    }
}
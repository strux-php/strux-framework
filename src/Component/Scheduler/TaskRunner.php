<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Strux\Component\Cache\Cache;
use Strux\Component\Queue\QueueInterface;
use Strux\Component\Queue\ShouldQueue;
use Strux\Component\Scheduler\Events\TaskFailed;
use Strux\Component\Scheduler\Events\TaskSkipped;
use Strux\Component\Scheduler\Events\TaskStarting;
use Strux\Component\Scheduler\Events\TaskSuccess;
use Throwable;

class TaskRunner
{
	public function __construct(
		private ContainerInterface $container,
		private EventDispatcherInterface $events,
		private LoggerInterface $logger,
		private Cache $cache,
		private ?QueueInterface $queue = null
	) {}

	public function run(Task $task): void
	{
		$id = $task->getIdentifier();

		if (!$this->evaluateRunWhenConditions($task, $id)) {
			return;
		}

		// Handle Mutex / WithoutOverlapping
		$mutexKey = 'schedule_mutex_' . md5($id);
		if ($task->withoutOverlapping) {
			if ($this->cache->has($mutexKey)) {
				$this->logger->info("Task {$id} skipped. Mutex lock exists.");
				$this->events->dispatch(new TaskSkipped($task, 'Task overlaps with a running instance.'));
				return;
			}
			$this->cache->set($mutexKey, true, $task->withoutOverlapping->expiresAfter * 60);
		}

		$this->events->dispatch(new TaskStarting($task));
		$this->logger->info("Running scheduled task: {$id}");

		$startTime = microtime(true);

		try {
			$this->executeTask($task, $id);

			$duration = round((microtime(true) - $startTime) * 1000, 2);
			$peakMemory = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
			$this->logger->info("Task {$id} completed in {$duration}ms, peak memory: {$peakMemory}MB");
		} catch (Throwable $e) {
			$duration = round((microtime(true) - $startTime) * 1000, 2);
			$this->logger->error("Task {$id} failed after {$duration}ms: " . $e->getMessage(), ['exception' => $e]);
			$this->events->dispatch(new TaskFailed($task, $e));
		} finally {
			if ($task->withoutOverlapping) {
				$this->cache->delete($mutexKey);
			}
		}
	}

	private function evaluateRunWhenConditions(Task $task, string $id): bool
	{
		foreach ($task->runWhens as $runWhen) {
			$condition = $runWhen->condition;
			$shouldRun = false;

			if ($condition instanceof Closure) {
				$dependencies = [];
				$reflectionFunc = new \ReflectionFunction($condition);
				foreach ($reflectionFunc->getParameters() as $param) {
					$type = $param->getType();
					if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
						$dependencies[] = $this->container->get($type->getName());
					}
				}
				$shouldRun = $condition(...$dependencies);
			} elseif (is_array($condition) && count($condition) === 2 && is_string($condition[0]) && is_string($condition[1])) {
				$instance = $this->container->get($condition[0]);
				$shouldRun = $instance->{$condition[1]}();
			} elseif (is_string($condition) && str_contains($condition, '@')) {
				[$service, $method] = explode('@', $condition, 2);
				$instance = $this->container->get($service);
				$shouldRun = $instance->{$method}();
			} elseif (is_callable($condition)) {
				$shouldRun = call_user_func($condition);
			}

			if (!$shouldRun) {
				$this->logger->debug("Task {$id} skipped by RunWhen condition.");
				$this->events->dispatch(new TaskSkipped($task, 'Condition evaluated to false.'));
				return false;
			}
		}
		return true;
	}

	private function executeTask(Task $task, string $id): void
	{
		// Capture output if requested
		$capturing = $task->sendOutputTo !== null;
		if ($capturing) {
			ob_start();
		}

		$instance = $this->resolveInstance($task);

		if ($instance instanceof ShouldQueue && $this->queue) {
			$this->queue->push($instance);
			$this->logger->info("Task {$id} pushed to queue.");
			$this->events->dispatch(new TaskSuccess($task, 'Pushed to queue'));
			return;
		}

		$method = $task->methodName ?? 'handle';

		if ($instance instanceof Closure) {
			$result = $this->executeCallable($instance, $id);
		} else {
			if (!method_exists($instance, $method)) {
				throw new \RuntimeException("Method {$method} does not exist on {$task->className}");
			}
			$result = $this->executeMethod($instance, $method);
		}

		if ($capturing) {
			$output = ob_get_clean();
			$this->writeOutput($task, $output);
		}

		$this->logger->info("Task {$id} completed successfully.");
		$this->events->dispatch(new TaskSuccess($task, $result));
	}

	private function resolveInstance(Task $task): mixed
	{
		if ($task->className === 'Closure' || $task->className === 'Command') {
			return $task->extra;
		}
		return $this->container->get($task->className);
	}

	private function executeCallable(Closure $callable, string $id): mixed
	{
		$dependencies = [];
		$reflectionFunc = new \ReflectionFunction($callable);
		foreach ($reflectionFunc->getParameters() as $param) {
			$type = $param->getType();
			if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
				$dependencies[] = $this->container->get($type->getName());
			}
		}
		return $callable(...$dependencies);
	}

	private function executeMethod(object $instance, string $method): mixed
	{
		$dependencies = [];
		$reflectionMethod = new \ReflectionMethod($instance, $method);
		foreach ($reflectionMethod->getParameters() as $param) {
			$type = $param->getType();
			if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
				$dependencies[] = $this->container->get($type->getName());
			}
		}
		return $instance->{$method}(...$dependencies);
	}

	private function writeOutput(Task $task, string $output): void
	{
		$dir = dirname($task->sendOutputTo->path);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		$flags = $task->sendOutputTo->append ? FILE_APPEND : 0;
		file_put_contents($task->sendOutputTo->path, $output, $flags);
	}
}

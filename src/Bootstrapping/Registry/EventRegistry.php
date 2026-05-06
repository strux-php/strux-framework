<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use Strux\Component\Config\Config;
use Strux\Component\Events\Attributes\Listener;
use Strux\Component\Events\CallQueuedListener;
use Strux\Component\Events\EventDispatcher;
use Strux\Component\Events\ListenerProvider;
use Strux\Component\Queue\QueueInterface;
use Strux\Component\Queue\ShouldQueue;
use Strux\Foundation\Application;
use Strux\Support\ClassFinder;

class EventRegistry extends ServiceRegistry
{
    public function build(): void
    {
        $this->container->singleton(
            ListenerProviderInterface::class,
            static fn() => new ListenerProvider()
        );

        $this->container->singleton(
            EventDispatcher::class,
            static fn(ContainerInterface $c) => new EventDispatcher(
                listenerProvider: $c->get(ListenerProviderInterface::class)
            )
        );

        $this->container->bind(
            EventDispatcherInterface::class,
            EventDispatcher::class
        );
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public function init(Application $app): void
    {
        $container = $app->getContainer();

        /** @var EventDispatcher $dispatcher */
        $dispatcher = $container->get(EventDispatcher::class);

        /** @var LoggerInterface $logger */
        $logger = $container->get(LoggerInterface::class);

        /** @var QueueInterface|null $queue */
        $queue = $container->has(QueueInterface::class) ? $container->get(QueueInterface::class) : null;

        /** @var Config $config */
        $config = $container->get(Config::class);
        $mode = $config->get('app.mode', 'standard');

        $this->discoverListeners($container, $dispatcher, $queue, $logger, $mode, $app->getRootPath());
    }

    /**
     * Scans the appropriate directory and registers listeners based on attributes or type-hints.
     */
    protected function discoverListeners(
        ContainerInterface $container,
        EventDispatcher $dispatcher,
        ?QueueInterface $queue,
        LoggerInterface $logger,
        string $mode,
        string $rootPath
    ): void {
        if ($mode === 'domain') {
            $listenersDir = $rootPath . '/src/Domain';
        } else {
            $listenersDir = $rootPath . '/src/Listener';
        }

        if (!is_dir($listenersDir)) {
            return;
        }

        $classes = ClassFinder::findClasses($listenersDir, 'App');

        foreach ($classes as $className) {
            if (!class_exists($className)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);
            } catch (ReflectionException $e) {
                continue;
            }

            if ($reflection->isAbstract()) {
                continue;
            }

            $classAttributes = $reflection->getAttributes(Listener::class);
            $methodAttributes = [];
            
            if ($reflection->hasMethod('handle')) {
                $methodAttributes = $reflection->getMethod('handle')->getAttributes(Listener::class);
            }
            
            $attributes = array_merge($classAttributes, $methodAttributes);

            if (!empty($attributes)) {
                foreach ($attributes as $attribute) {
                    /** @var Listener $listenerAttribute */
                    $listenerAttribute = $attribute->newInstance();

                    $eventClass = $listenerAttribute->event;
                    $methodName = $listenerAttribute->method ?? 'handle';

                    if ($eventClass === null) {
                        if ($reflection->hasMethod($methodName)) {
                            $eventClass = $this->getEventClassFromMethod($reflection->getMethod($methodName));
                        }
                    }

                    if ($eventClass) {
                        $this->registerListener($container, $dispatcher, $queue, $logger, $eventClass, $className, $methodName);
                    }
                }
                continue;
            }

            if ($reflection->hasMethod('handle')) {
                $eventClass = $this->getEventClassFromMethod($reflection->getMethod('handle'));
                if ($eventClass) {
                    $this->registerListener($container, $dispatcher, $queue, $logger, $eventClass, $className, 'handle');
                }
            }
        }
    }

    /**
     * Helper to extract Event class from method type hint.
     */
    protected function getEventClassFromMethod(\ReflectionMethod $method): ?string
    {
        $parameters = $method->getParameters();

        if (count($parameters) !== 1) {
            return null;
        }

        $type = $parameters[0]->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        return $type->getName();
    }

    /**
     * Registers a single listener, applying queue logic if needed.
     */
    protected function registerListener(
        ContainerInterface $container,
        EventDispatcher $dispatcher,
        ?QueueInterface $queue,
        LoggerInterface $logger,
        string $eventClass,
        string $listenerClass,
        string $methodName = 'handle'
    ): void {
        $callableListener = function (object $event) use ($container, $listenerClass, $methodName, $queue, $logger) {
            $listenerInstance = $container->get($listenerClass);

            if ($listenerInstance instanceof ShouldQueue && $queue) {
                $job = new CallQueuedListener($listenerClass, $event, $methodName);
                $queue->push($job);
                $logger->info("[EventRegistry] Queued listener {$listenerClass}::{$methodName}");
            } else {
                if (method_exists($listenerInstance, $methodName)) {
                    $listenerInstance->$methodName($event);
                } elseif ($methodName === '__invoke' && is_callable($listenerInstance)) {
                    $listenerInstance($event);
                }
            }
        };

        $dispatcher->addListener($eventClass, $callableListener);
    }
}
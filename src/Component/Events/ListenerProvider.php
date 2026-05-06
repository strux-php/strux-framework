<?php

declare(strict_types=1);

namespace Strux\Component\Events;

use Psr\EventDispatcher\ListenerProviderInterface;

class ListenerProvider implements ListenerProviderInterface
{
    /**
     * @var array<string, callable[]> An associative array where keys are event class names
     * and values are arrays of callables (listeners).
     */
    private array $listeners = [];

    /**
     * Adds a listener for a specific event.
     *
     * @param string $eventClass The fully qualified class name of the event.
     * @param callable $listener The listener to be executed.
     */
    public function addListener(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    /**
     * @param object $event An event for which to return the relevant listeners.
     * @return iterable<callable> An iterable (array) of listeners for the given event.
     */
    public function getListenersForEvent(object $event): iterable
    {
        // Find listeners for the specific event class
        $eventClass = get_class($event);
        if (isset($this->listeners[$eventClass])) {
            foreach ($this->listeners[$eventClass] as $listener) {
                yield $listener;
            }
        }

        // Optional: Support inheritance (listeners for parent classes/interfaces)
        foreach ($this->listeners as $type => $listeners) {
            if ($type !== $eventClass && $event instanceof $type) {
                foreach ($listeners as $listener) {
                    yield $listener;
                }
            }
        }

        return [];
    }
}
<?php

declare(strict_types=1);

namespace Nolviko\Core\Container;

use Closure;

/**
 * Lazy, memoized services from a validated ContainerBuilder. Nothing is built until first requested.
 *
 * Composition-root use only: application code receives its dependencies through factories, never this
 * object (no service locator).
 */
final class Container
{
    /** @var array<string, mixed> */
    private array $instances;

    /** @var array<string, true> IDs currently being built, in order. */
    private array $resolving = [];

    /**
     * @param array<string, array{0: Closure, 1: list<string|Lazy>}> $factories
     * @param array<string, mixed> $values
     */
    public function __construct(
        private readonly array $factories,
        array $values,
    ) {
        $this->instances = $values;
    }

    /**
     * @throws ContainerException For an unknown ID or a cycle reached through a Lazy dependency.
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new ContainerException(['container_service_missing:' . $id]);
        }

        if (isset($this->resolving[$id])) {
            $cycle = [...array_keys($this->resolving), $id];

            throw new ContainerException(['container_circular_dependency:' . implode('>', $cycle)]);
        }

        $this->resolving[$id] = true;

        try {
            [$factory, $dependencies] = $this->factories[$id];
            $arguments = array_map($this->argument(...), $dependencies);

            return $this->instances[$id] = $factory(...$arguments);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * Typed access for class or interface IDs.
     *
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     *
     * @throws ContainerException When the service is not an instance of $id.
     */
    public function service(string $id): object
    {
        $service = $this->get($id);

        if (!$service instanceof $id) {
            throw new ContainerException(['container_service_type_mismatch:' . $id]);
        }

        return $service;
    }

    /**
     * Whether the service exists already (values always do); used to prove laziness.
     */
    public function initialized(string $id): bool
    {
        return array_key_exists($id, $this->instances);
    }

    private function argument(string|Lazy $dependency): mixed
    {
        if ($dependency instanceof Lazy) {
            return fn(): mixed => $this->get($dependency->id);
        }

        return $this->get($dependency);
    }
}

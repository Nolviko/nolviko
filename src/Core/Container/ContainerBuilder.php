<?php

declare(strict_types=1);

namespace Nolviko\Core\Container;

use Closure;

/**
 * Collects hand-written factories with explicitly declared dependencies and validates the graph on build.
 *
 * No reflection or autowiring: a factory receives its declared dependencies as arguments, in order, so the
 * graph checked here is exactly the graph used at run time.
 */
final class ContainerBuilder
{
    private const VISITING = 1;

    private const DONE = 2;

    /** @var array<string, array{0: Closure, 1: list<string|Lazy>}> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var list<string> */
    private array $problems = [];

    /**
     * Register an already-built value (parameter, external object).
     */
    public function value(string $id, mixed $value): void
    {
        $this->claim($id);
        $this->values[$id] = $value;
    }

    /**
     * @param list<string|Lazy> $dependencies Service IDs passed to $factory in this order.
     */
    public function factory(string $id, Closure $factory, array $dependencies = []): void
    {
        $this->claim($id);
        $this->factories[$id] = [$factory, $dependencies];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->values) || isset($this->factories[$id]);
    }

    /**
     * @throws ContainerException With every missing, duplicate or circular dependency found.
     */
    public function build(): Container
    {
        $problems = [...$this->problems, ...$this->missingDependencies(), ...$this->cycles()];

        if ($problems !== []) {
            throw new ContainerException($problems);
        }

        return new Container($this->factories, $this->values);
    }

    private function claim(string $id): void
    {
        if ($this->has($id)) {
            $this->problems[] = 'container_service_duplicate:' . $id;
        }
    }

    /**
     * @return list<string>
     */
    private function missingDependencies(): array
    {
        $missing = [];

        foreach ($this->factories as $id => [, $dependencies]) {
            foreach ($dependencies as $dependency) {
                $dependencyId = $dependency instanceof Lazy ? $dependency->id : $dependency;

                if (!$this->has($dependencyId)) {
                    $missing[] = 'container_service_missing:' . $id . ':' . $dependencyId;
                }
            }
        }

        return $missing;
    }

    /**
     * Depth-first search over eager edges; each cycle is reported once as `a>b>a`.
     *
     * @return list<string>
     */
    private function cycles(): array
    {
        $state = [];
        $cycles = [];

        foreach (array_keys($this->factories) as $id) {
            $this->visit($id, [], $state, $cycles);
        }

        return $cycles;
    }

    /**
     * @param list<string> $path
     * @param array<string, int> $state
     * @param list<string> $cycles
     */
    private function visit(string $id, array $path, array &$state, array &$cycles): void
    {
        if (($state[$id] ?? 0) === self::DONE || !isset($this->factories[$id])) {
            return;
        }

        if (($state[$id] ?? 0) === self::VISITING) {
            $cycle = [...array_slice($path, (int) array_search($id, $path, true)), $id];
            $cycles[] = 'container_circular_dependency:' . implode('>', $cycle);

            return;
        }

        $state[$id] = self::VISITING;

        foreach ($this->factories[$id][1] as $dependency) {
            if (is_string($dependency)) {
                $this->visit($dependency, [...$path, $id], $state, $cycles);
            }
        }

        $state[$id] = self::DONE;
    }
}

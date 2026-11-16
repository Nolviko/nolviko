<?php

declare(strict_types=1);

namespace Nolviko\Core\Routing;

use InvalidArgumentException;
use Symfony\Component\Routing\Generator\Dumper\CompiledUrlGeneratorDumper;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RouteCollection;

/**
 * Matcher and generator tables compiled from route definitions; plain arrays, so they can be exported as PHP.
 */
final class CompiledRoutes
{
    /** Bump when the exported shape changes, so older cache files are recompiled. */
    public const FORMAT = 1;

    /**
     * @param array<int, mixed> $matcher
     * @param array<string, mixed> $generator
     */
    public function __construct(
        public readonly array $matcher,
        public readonly array $generator,
    ) {
    }

    /**
     * @param list<RouteDefinition> $definitions
     *
     * @throws InvalidArgumentException When two definitions share a name.
     */
    public static function compile(array $definitions): self
    {
        $collection = new RouteCollection();

        foreach ($definitions as $definition) {
            if ($collection->get($definition->name) !== null) {
                throw new InvalidArgumentException('route_name_duplicate:' . $definition->name);
            }

            $collection->add($definition->name, $definition->toRoute());
        }

        return new self(
            (new CompiledUrlMatcherDumper($collection))->getCompiledRoutes(),
            (new CompiledUrlGeneratorDumper($collection))->getCompiledRoutes(),
        );
    }

    /**
     * PHP source of a cache file for one release.
     */
    public function export(string $releaseId): string
    {
        $data = [
            'format' => self::FORMAT,
            'release' => $releaseId,
            'matcher' => $this->matcher,
            'generator' => $this->generator,
        ];

        return '<?php' . "\n\n" . '// Generated route cache; safe to delete.' . "\n\n"
            . 'return ' . var_export($data, true) . ';' . "\n";
    }

    /**
     * Rebuild from the value a cache file returned, or null when it is not a cache of this release and format.
     */
    public static function fromExport(mixed $data, string $releaseId): ?self
    {
        if (
            !is_array($data)
            || ($data['format'] ?? null) !== self::FORMAT
            || ($data['release'] ?? null) !== $releaseId
            || !is_array($data['matcher'] ?? null)
            || !is_array($data['generator'] ?? null)
        ) {
            return null;
        }

        return new self($data['matcher'], $data['generator']);
    }
}

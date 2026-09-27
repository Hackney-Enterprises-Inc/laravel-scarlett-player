<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor;

use Hei\ScarlettPlayer\Exceptions\InvalidDoctorCheckException;
use Illuminate\Contracts\Container\Container;

/**
 * The checks scarlett:doctor runs, in registration order. The service provider
 * fills it from its per-module doctorChecks() methods.
 */
class CheckRegistry
{
    /** @var list<class-string<Check>> */
    private array $classes = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Register check classes. A class already registered is not added twice.
     *
     * @param  class-string<Check>  ...$classes
     */
    public function add(string ...$classes): self
    {
        foreach ($classes as $class) {
            if (! in_array($class, $this->classes, true)) {
                $this->classes[] = $class;
            }
        }

        return $this;
    }

    /**
     * @return list<class-string<Check>>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    /**
     * The registered checks, resolved from the container.
     *
     * @return list<Check>
     *
     * @throws InvalidDoctorCheckException when a registered class is not a Check.
     */
    public function checks(): array
    {
        return array_map(function (string $class): Check {
            $check = $this->container->make($class);

            if (! $check instanceof Check) {
                throw InvalidDoctorCheckException::notACheck($class);
            }

            return $check;
        }, $this->classes);
    }
}

<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Drivers;

use Atannex\NwehMundani\Core\Domain\Identifier;
use Atannex\NwehMundani\Core\Drivers\Contracts\CalendarDriver;
use Atannex\NwehMundani\Core\Exceptions\DriverNotFound;
use Atannex\NwehMundani\Core\Exceptions\UnsafeRegistration;
use Illuminate\Contracts\Container\Container;

/**
 * Executable drivers, registered by trusted code (service providers and
 * config). Definitions only ever name a driver by key; a key or class name
 * coming from the database is never instantiated.
 */
final class DriverRegistry
{
    /** @var array<string, CalendarDriver> */
    private array $drivers = [];

    public function __construct(private readonly ?Container $container = null) {}

    /**
     * @param  CalendarDriver|class-string<CalendarDriver>  $driver
     *
     * @throws UnsafeRegistration
     */
    public function register(CalendarDriver|string $driver, bool $replace = false): CalendarDriver
    {
        if (is_string($driver)) {
            if (! class_exists($driver) || ! is_subclass_of($driver, CalendarDriver::class)) {
                throw UnsafeRegistration::because("[{$driver}] is not a class implementing ".CalendarDriver::class.'.');
            }

            $driver = $this->container?->make($driver) ?? new $driver;
        }

        $key = $driver->key();

        if (! Identifier::isValid($key)) {
            throw UnsafeRegistration::because("driver key [{$key}] is not a valid identifier.");
        }

        if (isset($this->drivers[$key]) && ! $replace && $driver::class !== $this->drivers[$key]::class) {
            throw UnsafeRegistration::because("driver key [{$key}] is already taken by ".$this->drivers[$key]::class.'. Pass $replace = true to swap it deliberately.');
        }

        return $this->drivers[$key] = $driver;
    }

    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    /** @throws DriverNotFound */
    public function get(string $key): CalendarDriver
    {
        return $this->drivers[$key] ?? throw DriverNotFound::forKey($key);
    }

    /** @return array<string, CalendarDriver> */
    public function all(): array
    {
        return $this->drivers;
    }
}

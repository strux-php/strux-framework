<?php

declare(strict_types=1);

namespace Strux\Component\Scheduler;

class SchedulerConfig
{
    /** @var string[] */
    private array $directories = [];

    private ?string $defaultTimezone = null;

    /** @var string[] */
    private array $environments = [];

    private bool $skipInMaintenanceMode = true;

    public function __construct(array $config = [])
    {
        $this->directories = $config['directories'] ?? [];
        $this->defaultTimezone = $config['timezone'] ?? null;
        $this->environments = $config['environments'] ?? [];
        $this->skipInMaintenanceMode = $config['skip_in_maintenance'] ?? true;
    }

    /** @return string[] */
    public function getDirectories(): array
    {
        return $this->directories;
    }

    public function getDefaultTimezone(): ?string
    {
        return $this->defaultTimezone;
    }

    /** @return string[] */
    public function getEnvironments(): array
    {
        return $this->environments;
    }

    public function skipInMaintenanceMode(): bool
    {
        return $this->skipInMaintenanceMode;
    }
}

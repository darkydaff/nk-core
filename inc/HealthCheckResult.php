<?php

final class HealthCheckResult
{
    public readonly bool $healthy;
    public readonly array $checks;
    public readonly array $warnings;

    public function __construct(bool $healthy, array $checks = [], array $warnings = [])
    {
        $this->healthy = $healthy;
        $this->checks = $checks;
        $this->warnings = $warnings;
    }
}

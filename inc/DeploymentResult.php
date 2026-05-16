<?php

final class DeploymentResult
{
    public readonly bool $success;
    public readonly string $status;
    public readonly ?string $failedStep;
    public readonly ?string $errorMessage;
    public readonly ?string $exceptionClass;
    public readonly array $timings;
    public readonly array $metadata;
    public readonly array $warnings;
    public readonly bool $rollbackExecuted;

    private function __construct(
        bool $success,
        string $status,
        ?string $failedStep,
        ?string $errorMessage,
        ?string $exceptionClass,
        array $timings,
        array $metadata,
        array $warnings,
        bool $rollbackExecuted
    ) {
        $this->success = $success;
        $this->status = $status;
        $this->failedStep = $failedStep;
        $this->errorMessage = $errorMessage;
        $this->exceptionClass = $exceptionClass;
        $this->timings = $timings;
        $this->metadata = $metadata;
        $this->warnings = $warnings;
        $this->rollbackExecuted = $rollbackExecuted;
    }

    public static function success(array $timings = [], array $metadata = [], array $warnings = []): self
    {
        return new self(
            true,
            'success',
            null,
            null,
            null,
            $timings,
            $metadata,
            $warnings,
            false
        );
    }

    public static function failure(
        string $failedStep,
        string $errorMessage,
        ?Throwable $exception = null,
        array $timings = [],
        array $metadata = [],
        array $warnings = [],
        bool $rollbackExecuted = false
    ): self {
        return new self(
            false,
            'failure',
            $failedStep,
            $errorMessage,
            $exception ? get_class($exception) : null,
            $timings,
            $metadata,
            $warnings,
            $rollbackExecuted
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'failed_step' => $this->failedStep,
            'error_message' => $this->errorMessage,
            'exception_class' => $this->exceptionClass,
            'timings' => $this->timings,
            'metadata' => $this->metadata,
            'warnings' => $this->warnings,
            'rollback_executed' => $this->rollbackExecuted
        ];
    }
}

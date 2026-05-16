<?php
declare(strict_types=1);

final class SshConnectionConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly ?string $password,
        public readonly ?string $privateKey,
    ) {}
}

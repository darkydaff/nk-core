<?php

enum ServerStatus: string implements JsonSerializable {
    case DEPLOYING = 'deploying';
    case DELETING = 'deleting';
    case ACTIVE = 'active';
    case STOPPED = 'stopped';
    case ERROR = 'error';
    case DELETED = 'deleted';

    public function jsonSerialize(): string {
        return $this->value;
    }

    public function canTransitionTo(ServerStatus $newStatus): bool {
        if ($this === $newStatus) return true;
        return match($this) {
            self::DEPLOYING => in_array($newStatus, [self::ACTIVE, self::ERROR, self::DELETED]),
            self::DELETING => in_array($newStatus, [self::STOPPED, self::DELETED, self::ERROR]),
            self::ACTIVE => in_array($newStatus, [self::DEPLOYING, self::STOPPED, self::ERROR, self::DELETED, self::DELETING]),
            self::STOPPED => in_array($newStatus, [self::ACTIVE, self::DELETED, self::DELETING]),
            self::ERROR => in_array($newStatus, [self::DEPLOYING, self::DELETED, self::DELETING]),
            self::DELETED => false,
        };
    }
}

enum ClientStatus: string {
    case PROVISIONING = 'provisioning';
    case VERIFYING = 'verifying';
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
    case DELETING = 'deleting';
    case ERROR = 'error';
    case DELETED = 'deleted';

    public function canTransitionTo(ClientStatus $newStatus): bool {
        if ($this === $newStatus) return true;
        return match($this) {
            self::PROVISIONING => in_array($newStatus, [self::VERIFYING, self::ACTIVE, self::ERROR, self::DELETED, self::DELETING]),
            self::VERIFYING => in_array($newStatus, [self::ACTIVE, self::ERROR, self::DELETED, self::DELETING]),
            self::ACTIVE => in_array($newStatus, [self::VERIFYING, self::DISABLED, self::DELETED, self::ERROR, self::DELETING]),
            self::DISABLED => in_array($newStatus, [self::PROVISIONING, self::ACTIVE, self::DELETED, self::DELETING]),
            self::DELETING => in_array($newStatus, [self::DELETED, self::ERROR]),
            self::ERROR => in_array($newStatus, [self::PROVISIONING, self::DELETED, self::DELETING]),
            self::DELETED => false,
        };
    }
}

enum UserRole: string {
    case ADMIN = 'admin';
    case USER = 'user';
}

enum ProxyType: string {
    case SOCKS5 = 'socks5';
    case HTTP = 'http';
}

enum ProxyStatus: string {
    case ACTIVE  = 'active';
    case PAUSED  = 'paused';
    case ERROR   = 'error';
    case DELETED = 'deleted';

    public function canTransitionTo(ProxyStatus $newStatus): bool {
        if ($this === $newStatus) return true;
        return match($this) {
            self::ACTIVE => in_array($newStatus, [self::PAUSED, self::DELETED, self::ERROR]),
            self::PAUSED => in_array($newStatus, [self::ACTIVE, self::DELETED, self::ERROR]),
            self::ERROR => in_array($newStatus, [self::ACTIVE, self::DELETED]),
            self::DELETED => false,
        };
    }
}

enum JobFailureType: string {
    case TRANSIENT = 'transient';
    case PERMANENT = 'permanent';
    case VALIDATION = 'validation';
}

class JobException extends \Exception {
    public function __construct(
        string $message, 
        public JobFailureType $failureType = JobFailureType::PERMANENT,
        int $code = 0, 
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}

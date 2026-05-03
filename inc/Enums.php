<?php

enum ServerStatus: string {
    case DEPLOYING = 'deploying';
    case ACTIVE = 'active';
    case STOPPED = 'stopped';
    case ERROR = 'error';
    case DELETED = 'deleted';

    public function canTransitionTo(ServerStatus $newStatus): bool {
        return match($this) {
            self::DEPLOYING => in_array($newStatus, [self::ACTIVE, self::ERROR, self::DELETED]),
            self::ACTIVE => in_array($newStatus, [self::STOPPED, self::ERROR, self::DELETED]),
            self::STOPPED => in_array($newStatus, [self::ACTIVE, self::DELETED]),
            self::ERROR => in_array($newStatus, [self::DEPLOYING, self::DELETED]),
            self::DELETED => false,
        };
    }
}

enum ClientStatus: string {
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
    case DELETED = 'deleted';

    public function canTransitionTo(ClientStatus $newStatus): bool {
        return match($this) {
            self::ACTIVE => in_array($newStatus, [self::DISABLED, self::DELETED]),
            self::DISABLED => in_array($newStatus, [self::ACTIVE, self::DELETED]),
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

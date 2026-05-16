<?php
declare(strict_types=1);

class SshException extends \RuntimeException {}

class SshConnectionException extends SshException {}

class RemoteCommandException extends SshException {}

class SshAuthenticationException extends SshException {}

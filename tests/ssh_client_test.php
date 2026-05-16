<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/SshClient.php';
require_once __DIR__ . '/../inc/SshException.php';

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        echo "[FAIL] $message\n";
        exit(1);
    }
    echo "[PASS] $message\n";
}

function assert_false(bool $condition, string $message): void {
    assert_true(!$condition, $message);
}

function assert_equals($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        echo "[FAIL] $message (Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . ")\n";
        exit(1);
    }
    echo "[PASS] $message\n";
}

$host = getenv('TEST_SSH_HOST') ?: null;
$port = (int)(getenv('TEST_SSH_PORT') ?: 22);
$username = getenv('TEST_SSH_USER') ?: 'root';
$password = getenv('TEST_SSH_PASS') ?: null;
$privateKey = getenv('TEST_SSH_KEY') ?: null;
$allowDestructive = (bool)getenv('TEST_SSH_ALLOW_DESTRUCTIVE');

if (!$host) {
    echo "Skipping SSH integration tests. Set TEST_SSH_HOST to run.\n";
    exit(0);
}

$config = new SshConnectionConfig($host, $port, $username, $password, $privateKey);

echo "Running tests against $username@$host:$port...\n";

// 1. Connection test
$client = new SshClient($config);
try {
    $client->testConnection();
    assert_true(true, "testConnection() succeeded");
} catch (\Exception $e) {
    assert_true(false, "testConnection() threw exception: " . $e->getMessage());
}

// 2. Command execution & exit code
$output = $client->executeCommand("echo 'hello world'");
assert_equals('hello world', trim($output), "Command execution works");

try {
    $client->executeCommand("exit 42", false, true);
    assert_true(false, "Should have thrown RemoteCommandException");
} catch (RemoteCommandException $e) {
    assert_true(true, "RemoteCommandException thrown on exit code 42");
    assert_true(strpos($e->getMessage(), 'exit 42') !== false, "Exception message contains exit code");
}

// 3. Timeout behavior
try {
    $client->executeCommand("sleep 3", false, true, false, 1);
    assert_true(false, "Should have thrown RemoteCommandException for timeout");
} catch (RemoteCommandException $e) {
    assert_true(true, "Timeout threw RemoteCommandException");
}

// 4. Mux reuse and stale mux recovery
$client2 = new SshClient($config);
$client2->executeCommand("echo 'mux 1'");
$muxSocket = '/tmp/ssh_mux/nk_' . md5($host . ':' . $port);
assert_true(file_exists($muxSocket), "Mux socket was created");

// Manually kill the master process to simulate stale mux socket
$lsofOut = shell_exec("lsof -t " . escapeshellarg($muxSocket));
if ($lsofOut) {
    shell_exec("kill -9 " . trim($lsofOut));
} else {
    // If lsof fails or isn't available, try to use fuser
    $fuserOut = shell_exec("fuser " . escapeshellarg($muxSocket) . " 2>/dev/null");
    if ($fuserOut) {
        shell_exec("kill -9 " . trim($fuserOut));
    }
}
// Even if we couldn't kill it by PID, let's artificially break the socket file connection
// Actually, SSH master exiting leaves a dead socket file. Let's just exit it cleanly.
shell_exec("ssh -o ControlPath=" . escapeshellarg($muxSocket) . " -O exit dummy 2>/dev/null");
// Recreate a dummy file at the socket path to explicitly simulate a stale crashed socket
@touch($muxSocket);

$output = $client2->executeCommand("echo 'recovery'");
assert_equals('recovery', trim($output), "Successfully recovered from stale/dead mux socket");

// 5. Destructive/sudo tests
if ($allowDestructive) {
    echo "\nRunning destructive/sudo tests...\n";
    if ($password) {
        $output = $client2->executeCommand("whoami", true);
        assert_equals('root', trim($output), "Sudo execution works");
    } else {
        echo "Skipping sudo tests because no password provided.\n";
    }
} else {
    echo "\nSkipping destructive tests (TEST_SSH_ALLOW_DESTRUCTIVE != 1)\n";
}

echo "All tests passed!\n";

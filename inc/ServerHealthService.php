<?php

require_once __DIR__ . '/HealthCheckResult.php';

class ServerHealthService
{
    private SshClient $ssh;

    public function __construct(SshClient $ssh)
    {
        $this->ssh = $ssh;
    }

    /**
     * Perform a comprehensive health check on the VPN server
     */
    public function getFullHealthCheck(string $containerName): HealthCheckResult
    {
        $checks = [];
        $warnings = [];
        $isHealthy = true;

        try {
            $checks['docker_daemon'] = $this->checkDockerDaemon();
            if (!$checks['docker_daemon']) {
                $warnings[] = "Docker daemon is not running.";
                $isHealthy = false;
            } else {
                $checks['container_running'] = $this->checkContainerHealth($containerName);
                if (!$checks['container_running']) {
                    $warnings[] = "VPN container '{$containerName}' is not running.";
                    $isHealthy = false;
                } else {
                    $checks['wg_interface'] = $this->checkWireguardInterface($containerName);
                    if (!$checks['wg_interface']) {
                        $warnings[] = "WireGuard interface (wg0) is down or missing inside container.";
                        $isHealthy = false;
                    }
                }
            }

            $checks['kernel_module'] = $this->checkKernelModuleStatus();
            if (!$checks['kernel_module']) {
                $warnings[] = "AmneziaWG kernel module is not loaded. Falling back to userspace implementation.";
                // This is a warning, not a fatal health issue.
            }

        } catch (SshException $e) {
            $isHealthy = false;
            $warnings[] = "SSH connection failed: " . $e->getMessage();
            $checks['ssh_connection'] = false;
        } catch (Exception $e) {
            $isHealthy = false;
            $warnings[] = "Health check failed: " . $e->getMessage();
        }

        return new HealthCheckResult($isHealthy, $checks, $warnings);
    }

    /**
     * Check if Docker daemon is running
     */
    public function checkDockerDaemon(): bool
    {
        $output = $this->ssh->executeCommand('systemctl is-active docker 2>/dev/null || echo "inactive"', true);
        return trim($output) === 'active';
    }

    /**
     * Check if the specific VPN container is running
     */
    public function checkContainerHealth(string $containerName): bool
    {
        $output = $this->ssh->executeCommand("docker inspect -f '{{.State.Running}}' {$containerName} 2>/dev/null || echo false", true);
        return trim($output) === 'true';
    }

    /**
     * Check if wg0 interface is active inside the container
     */
    public function checkWireguardInterface(string $containerName): bool
    {
        $output = $this->ssh->executeCommand("docker exec {$containerName} ip link show wg0 2>/dev/null | grep -c wg0 || echo 0", true);
        return (int)trim($output) > 0;
    }

    /**
     * Check if AmneziaWG kernel module is loaded
     */
    public function checkKernelModuleStatus(): bool
    {
        $output = $this->ssh->executeCommand('lsmod | grep -c amneziawg 2>/dev/null || echo 0', true);
        return (int)trim($output) > 0;
    }
}

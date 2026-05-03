<?php

class DeploymentService {
    
    /**
     * Deploy or Re-deploy a Docker container on a remote server idempotently.
     * 
     * @param object $server The server object (VpnServer or ProxyServer)
     * @param string $containerName Name of the container
     * @param string $image Image to use
     * @param string $runOptions Additional options for docker run
     * @return bool Success status
     */
    public static function deployDockerContainer($server, string $containerName, string $image, string $runOptions): bool {
        // 1. Stop and remove existing container if it exists
        $server->executeCommand("docker stop {$containerName} 2>/dev/null || true", true);
        $server->executeCommand("docker rm {$containerName} 2>/dev/null || true", true);
        
        // 2. Run new container
        $cmd = "docker run -d --restart always --name {$containerName} {$runOptions} {$image} 2>&1";
        $output = $server->executeCommand($cmd, true, true);
        
        // 3. Verify it's running
        sleep(2);
        $state = trim($server->executeCommand("docker inspect --format='{{.State.Running}}' {$containerName} 2>/dev/null", true));
        
        if ($state !== 'true') {
            $logs = $server->executeCommand("docker logs --tail 30 {$containerName} 2>&1", true);
            throw new Exception("Container {$containerName} failed to start. Logs: " . $logs);
        }
        
        return true;
    }
}

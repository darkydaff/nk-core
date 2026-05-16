<?php
$code = file_get_contents("inc/VpnServer.php");
$tokens = token_get_all($code);

$methods_to_keep = [
    "deploy",
    "stepTestConnection",
    "tryAdoptExistingConfig",
    "finalizeDeployment",
    "getDeploymentResult",
    "prepareSystem",
    "installKernelModule",
    "detectRemoteEnvironment",
    "installKernelModuleApt",
    "installKernelHeadersApt",
    "setupAmneziaRepoDeb",
    "installKernelModuleDnf",
    "installDocker",
    "findFreeUdpPort",
    "createDockerfile",
    "createStartScript",
    "buildDockerImage",
    "runContainer",
    "initializeServerConfig",
    "getDynamicQuicPayloads",
    "generateNonOverlappingHeaderRanges",
    "getMimicryPreset",
    "detectAndImportExistingConfig",
    "importClientsFromContainer"
];

$out = "<?php\ndeclare(strict_types=1);\n\nclass VpnProvisioner\n{\n    private VpnServer \$server;\n\n";
$out .= "    private function getData(): array\n    {\n        return \$this->server->getData() ?? [];\n    }\n\n";
$out .= "    private function getId(): ?int\n    {\n        return \$this->getData()[\"id\"] ?? null;\n    }\n\n";

$braceLevel = 0;
$inClass = false;
$classBraceLevel = -1;

$inMethod = false;
$methodName = "";
$methodBody = "";

for ($i = 0; $i < count($tokens); $i++) {
    $token = $tokens[$i];
    $isString = is_string($token);
    $text = $isString ? $token : $token[1];
    
    if ($text === '{') {
        $braceLevel++;
        if ($inClass && $classBraceLevel === -1) {
            $classBraceLevel = $braceLevel;
        }
    } elseif ($text === '}') {
        if ($inMethod && $braceLevel === $classBraceLevel + 1) {
            $inMethod = false;
            $methodBody .= "}";
            
            if (in_array($methodName, $methods_to_keep)) {
                if ($methodName === "deploy") {
                    $methodBody = str_replace("public function deploy(bool \$forceRebuild = false): array", "public function deploy(VpnServer \$server, bool \$forceRebuild = false): array", $methodBody);
                    $methodBody = preg_replace("/\{\s*/", "{\n        \$this->server = \$server;\n        \$serverData = \$this->getData();\n        ", $methodBody, 1);
                } else {
                    $methodBody = preg_replace("/\{\s*/", "{\n        \$serverData = \$this->getData();\n        ", $methodBody, 1);
                }
                
                $methodBody = str_replace("\$this->data", "\$serverData", $methodBody);
                $methodBody = str_replace("\$this->executeCommand", "\$this->server->executeCommand", $methodBody);
                $methodBody = str_replace("\$this->runStep", "\$this->server->runStep", $methodBody);
                $methodBody = str_replace("\$this->setStatus", "\$this->server->setStatus", $methodBody);
                $methodBody = str_replace("\$this->testConnection", "\$this->server->testConnection", $methodBody);
                $methodBody = str_replace("\$this->serverId", "\$this->getId()", $methodBody);
                $methodBody = str_replace("\$this->currentJob", "\$this->server->getJob()", $methodBody);
                
                $out .= $methodBody . "\n\n";
            }
            $methodName = "";
            $methodBody = "";
            $braceLevel--;
            continue;
        }
        $braceLevel--;
    }
    
    if (!$isString && $token[0] === T_CLASS) {
        $inClass = true;
    }
    
    if (!$inMethod && $inClass && $braceLevel === $classBraceLevel && !$isString && $token[0] === T_FUNCTION) {
        $name = "";
        for ($j = $i + 1; $j < $i + 10; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $name = $tokens[$j][1];
                break;
            } elseif (is_string($tokens[$j]) && $tokens[$j] === '(') {
                break;
            }
        }
        
        if ($name !== "") {
            $inMethod = true;
            $methodName = $name;
            
            $startIdx = $i;
            for ($j = $i - 1; $j >= 0; $j--) {
                if (is_array($tokens[$j])) {
                    if (in_array($tokens[$j][0], [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_WHITESPACE, T_DOC_COMMENT])) {
                        $startIdx = $j;
                        if ($tokens[$j][0] === T_DOC_COMMENT) break;
                    } else {
                        break;
                    }
                } elseif (trim($tokens[$j]) === "") {
                    $startIdx = $j;
                } else {
                    break;
                }
            }
            
            for ($j = $startIdx; $j < $i; $j++) {
                $methodBody .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            }
        }
    }
    
    if ($inMethod) {
        $methodBody .= $text;
    }
}

$out .= "}\n";
file_put_contents("inc/VpnProvisioner.php", $out);
echo "Done";

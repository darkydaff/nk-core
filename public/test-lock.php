<?php
session_name(getenv('SESSION_NAME') ?: 'amnezia_panel_session');
$start = microtime(true);
session_start();
$end = microtime(true);
echo "Waited " . ($end - $start) . " seconds for lock";

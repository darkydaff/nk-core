<?php
session_name(getenv('SESSION_NAME') ?: 'amnezia_panel_session');
session_start();
session_write_close(); // Unlock
sleep(10);
echo "Done";

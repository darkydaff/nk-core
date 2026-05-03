-- Insert missing English translations
-- Keys that don't exist yet in the database (for fallback)

-- Common
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('en', 'common', 'name', 'Name'),
('en', 'common', 'actions', 'Actions'),
('en', 'common', 'search', 'Search'),
('en', 'common', 'mb', 'MB'),
('en', 'common', 'gb', 'GB'),
('en', 'common', 'kb', 'KB'),
('en', 'common', 'uploaded', 'Uploaded'),
('en', 'common', 'downloaded', 'Downloaded');

-- Clients
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('en', 'clients', 'title', 'Client'),
('en', 'clients', 'name', 'Name'),
('en', 'clients', 'client_name', 'Client Name'),
('en', 'clients', 'ip', 'IP Address'),
('en', 'clients', 'settings', 'Client Settings'),
('en', 'clients', 'configuration', 'Configuration'),
('en', 'clients', 'download_config', 'Download Config'),
('en', 'clients', 'copy_config', 'Copy Config'),
('en', 'clients', 'copied', 'Copied!'),
('en', 'clients', 'copy_failed', 'Failed to copy'),
('en', 'clients', 'sync_stats', 'Sync Stats'),
('en', 'clients', 'syncing', 'Syncing...'),
('en', 'clients', 'sync_failed', 'Failed to sync'),
('en', 'clients', 'delete', 'Delete Client'),
('en', 'clients', 'delete_confirm', 'Delete this client permanently?'),
('en', 'clients', 'restore', 'Restore Client'),
('en', 'clients', 'last_handshake', 'Last Handshake'),
('en', 'clients', 'never', 'Never'),
('en', 'clients', 'traffic', 'Traffic'),
('en', 'clients', 'created', 'Created'),
('en', 'clients', 'created_at', 'Created'),
('en', 'clients', 'no_results', 'No clients found'),
('en', 'clients', 'no_clients', 'No clients yet'),
('en', 'clients', 'no_clients_hint', 'Create your first client to get started'),
('en', 'clients', 'name_hint', 'Spaces become underscores. Cyrillic OK.'),
('en', 'clients', 'add', 'Add Client'),
('en', 'clients', 'create', 'Create'),
('en', 'clients', 'create_client', 'Create Client'),
('en', 'clients', 'view', 'View');

-- Servers
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('en', 'servers', 'vpn_port', 'VPN Port'),
('en', 'servers', 'subnet', 'Subnet'),
('en', 'servers', 'protocol', 'Protocol'),
('en', 'servers', 'new_client', 'New Client'),
('en', 'servers', 'sync', 'Sync'),
('en', 'servers', 'delete_confirm', 'Delete this server?'),
('en', 'servers', 'delete', 'Delete'),
('en', 'servers', 'handshake', 'Handshake'),
('en', 'servers', 'never', 'Never'),
('en', 'servers', 'active', 'Active'),
('en', 'servers', 'offline', 'Offline'),
('en', 'servers', 'deploying', 'Deploying'),
('en', 'servers', 'traffic', 'Traffic'),
('en', 'servers', 'deployed', 'Deployed');

-- Form
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('en', 'form', 'save', 'Save'),
('en', 'form', 'select', 'Select');

-- Message
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('en', 'message', 'connection_error', 'Connection error'),
('en', 'message', 'unknown_error', 'Unknown error'),
('en', 'message', 'success', 'Success'),
('en', 'message', 'error', 'Error');

-- Status
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('en', 'status', 'active', 'Active'),
('en', 'status', 'offline', 'Offline'),
('en', 'status', 'deploying', 'Deploying'),
('en', 'status', 'online', 'Online');

-- Users
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES
('en', 'users', 'you', 'You');

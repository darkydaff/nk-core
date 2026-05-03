-- Add SSH key authentication support to vpn_servers
ALTER TABLE vpn_servers
    ADD COLUMN ssh_private_key TEXT NULL COMMENT 'SSH private key for key-based authentication (optional, alternative to password)' AFTER password,
    ADD COLUMN ssh_public_key TEXT NULL COMMENT 'SSH public key (for convenience / display)' AFTER ssh_private_key;

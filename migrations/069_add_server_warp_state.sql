-- Migration 069: Add Cloudflare WARP state columns to vpn_servers
ALTER TABLE vpn_servers
  ADD COLUMN warp_status ENUM(
      'not_installed',
      'installing',
      'installed',
      'initializing',
      'connected',
      'degraded',
      'error'
  ) NOT NULL DEFAULT 'not_installed' AFTER status,
  ADD COLUMN warp_installed TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER warp_status,
  ADD COLUMN warp_initialized TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER warp_installed,
  ADD COLUMN warp_connected TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER warp_initialized,
  
  ADD COLUMN warp_interface VARCHAR(32) NOT NULL DEFAULT 'wg-warp' AFTER warp_connected,
  ADD COLUMN warp_version VARCHAR(50) NULL DEFAULT NULL AFTER warp_interface,
  ADD COLUMN warp_account_id VARCHAR(255) NULL DEFAULT NULL AFTER warp_version,
  ADD COLUMN warp_backend VARCHAR(50) NOT NULL DEFAULT 'cloudflare' AFTER warp_account_id,
  
  ADD COLUMN warp_host_gateway VARCHAR(50) NULL DEFAULT NULL AFTER warp_backend,
  ADD COLUMN warp_host_interface VARCHAR(32) NULL DEFAULT NULL AFTER warp_host_gateway,
  ADD COLUMN warp_client_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER warp_host_interface,

  ADD COLUMN warp_last_check_status VARCHAR(255) NULL DEFAULT NULL AFTER warp_client_count,
  ADD COLUMN warp_last_error TEXT NULL DEFAULT NULL AFTER warp_last_check_status,
  ADD COLUMN warp_cloudflare_ip VARCHAR(50) NULL DEFAULT NULL AFTER warp_last_error,

  ADD COLUMN warp_last_check_at TIMESTAMP NULL DEFAULT NULL AFTER warp_cloudflare_ip,
  ADD COLUMN warp_last_repair_at TIMESTAMP NULL DEFAULT NULL AFTER warp_last_check_at,
  ADD COLUMN warp_last_repair_result VARCHAR(255) NULL DEFAULT NULL AFTER warp_last_repair_at,
  ADD COLUMN warp_initialized_at TIMESTAMP NULL DEFAULT NULL AFTER warp_last_repair_result;

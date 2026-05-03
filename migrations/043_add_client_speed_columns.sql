-- Migration 043: Add live speed columns to vpn_clients
ALTER TABLE vpn_clients 
ADD COLUMN speed_up_kbps DECIMAL(10, 2) DEFAULT 0.00 AFTER bytes_received,
ADD COLUMN speed_down_kbps DECIMAL(10, 2) DEFAULT 0.00 AFTER speed_up_kbps;

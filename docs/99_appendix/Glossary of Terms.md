[[../index|Global Index]] → [[index|99 Appendix]] → [[Glossary of Terms]]

# Glossary of Terms

Definitions of key terms and concepts used throughout the NK-Core ecosystem.

## 📡 VPN Concepts

### AmneziaWG (AWG)
A custom fork of WireGuard designed to evade Deep Packet Inspection (DPI) by using randomized headers and mimicry payloads.

### Mimicry
The technique of making VPN traffic appear as another protocol (e.g., QUIC, DNS, STUN) to bypass firewalls.

### Last Handshake
The timestamp of the last successful cryptographic exchange between a client and the server. This indicates that the client is actively connected.

### Jc, Jmin, Jmax
Parameters in AmneziaWG that control the amount of "junk" data added to packets to obfuscate their length.

### S1, S2, S3, S4
Secret parameters in AmneziaWG used to transform the standard WireGuard packet headers.

### H1, H2, H3, H4
Hash-based parameters used for packet integrity and identification in AmneziaWG.

### I1, I2, I3, I4, I5
Initialization parameters for the AWG protocol state machine.

### Never Connected
A status assigned to clients who have been provisioned but have not yet completed a successful handshake with the server.

## 🏗️ Architecture Terms

### Master Panel
The central server running the NK-Core PHP application and database.

### Worker Node
A remote server managed by the Master Panel that hosts VPN or Proxy services.

### SSH Multiplexing (`ControlMaster`)
A feature of OpenSSH that allows multiple SSH sessions to share a single TCP connection, drastically reducing latency for repeated commands.

### S3 (Simple Storage Service)
An API-based storage standard used for storing backups in the cloud.

### Beszel
A lightweight system monitoring tool integrated into NK-Core to provide real-time CPU, RAM, and bandwidth metrics for worker nodes.

## 🛡️ Security Terms

### CSRF (Cross-Site Request Forgery)
An attack that forces an authenticated user to execute unwanted actions on a web application. NK-Core prevents this using unique tokens validated via `X-CSRF-Token` or form fields.

### JWT (JSON Web Token)
A compact, URL-safe means of representing claims to be transferred between two parties. Used for API authentication via the `Authorization: Bearer` header.

### RBAC (Role-Based Access Control)
A method of regulating access based on roles (`admin` vs `user`).

### Disabled / Revoked
A state where a user or client exists in the database but has been blocked from accessing the system or VPN network.

---
- ⬅️ Previous: [[index|99 Appendix]]
- ➡️ Next: [[../index|Global Index]]
- 📍 Parent: [[index|99 Appendix]]
- 🔗 Related:
  - [[../index|Global Hub]]
  - [[../00_overview/Project Overview|Project Overview]]
  - [[../07_security/Security Model|Security Model]]

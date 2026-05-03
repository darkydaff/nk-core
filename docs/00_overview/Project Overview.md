[[../index|Global Index]] → [[index|00 Overview]] → [[Project Overview]]

# Project Overview

NK-Core (also known as Amnezia Web Panel) is a production-grade VPN management system designed to orchestrate large fleets of VPN and Proxy servers. It provides a centralized dashboard for administrators to provision servers, manage clients, monitor traffic, and ensure high availability of VPN infrastructure.

## 🎯 Primary Objectives
- **Centralized Orchestration**: Manage multiple VPN nodes from a single interface.
- **Client Management**: Automated provisioning, revocation, and traffic limiting for VPN users.
- **Observability**: Real-time monitoring of server health and client activity.
- **Security**: Hardened access control, JWT-based APIs, and secure deployment pipelines.

## 🛠️ Technology Stack
- **Language**: PHP 8.1+ (targeting 8.5)
- **Database**: MariaDB / MySQL
- **Templating**: Twig 3.x
- **Frontend**: Vanilla JS, Modern CSS (no Tailwind defaults), custom components.
- **Deployment**: Docker, Nginx, PHP-FPM.
- **Integrations**: S3 (backups), Telegram (notifications), Beszel (monitoring).

## 🚀 Key Features
- **Multi-Protocol Support**: WireGuard, AmneziaWG, and Proxy services.
- **Dynamic Deployment**: One-click deployment to fresh Linux servers via SSH.
- **Automated Backups**: Scheduled state exports to S3-compatible storage.
- **Multilingual**: Built-in translation engine for global deployments.
- **Geo-Awareness**: IP-based geolocation for clients and servers.

## 🏛️ Repository Structure
- `/bin`: Command-line scripts and maintenance tools.
- `/controllers`: Request handlers for web and API routes.
- `/docs`: This documentation wiki.
- `/inc`: Core business logic, service classes, and utilities.
- `/migrations`: Database schema versioning.
- `/public`: Publicly accessible entry point and assets.
- `/templates`: UI layout and component definitions.

---
- ⬅️ Previous: [[index|00 Overview]]
- ➡️ Next: [[Developer Guide]]
- 📍 Parent: [[index|00 Overview]]
- 🔗 Related:
  - [[../01_architecture/System Architecture|System Architecture]]
  - [[Developer Guide]]


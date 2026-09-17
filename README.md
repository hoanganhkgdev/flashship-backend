# Flashship Backend

Backend platform for **Flashship**, a multi-service local delivery ecosystem connecting customers, drivers, shops, and operations teams.

This repository powers the core APIs, business rules, administration, realtime integrations, authentication, permissions, pricing, orders, and payment-related workflows used by the Flashship applications.

## System Overview

Flashship is organized around multiple client applications and a central Laravel backend:

- **Customer App** — customers create and manage service orders.
- **Driver App** — drivers receive and process assigned orders.
- **Shop App** — partner shops manage delivery operations.
- **Admin / Operations** — internal management powered by Filament.
- **Backend API** — shared business logic, authentication, pricing, realtime services, and integrations.

## Tech Stack

- PHP 8.2+
- Laravel 12
- Filament 3
- Laravel Sanctum
- Laravel Reverb
- Redis / Predis
- Firebase Admin SDK
- Spatie Laravel Permission
- PayOS
- Google Gemini integration
- PHPUnit

## Modular Architecture

The backend uses a modular structure to keep major business domains separated and maintainable.

```text
Modules/
├── Admin/
├── Core/
├── Customer/
├── Driver/
├── Order/
├── Pricing/
└── Shop/
```

### Main Domains

**Admin**  
Administration and operational management features.

**Core**  
Shared application infrastructure and common domain functionality.

**Customer**  
Customer-facing business logic and API functionality.

**Driver**  
Driver operations and driver-specific workflows.

**Order**  
Order lifecycle and related business processes.

**Pricing**  
Pricing rules and calculation-related functionality.

**Shop**  
Partner shop functionality and workflows.

## Key Engineering Areas

The project demonstrates work across several production-oriented areas:

- REST API development with Laravel
- Token-based authentication with Laravel Sanctum
- Role and permission management
- Modular domain architecture
- Firebase integration
- Realtime application capabilities
- Queue-ready Laravel architecture
- Redis integration
- Payment integration with PayOS
- Filament-based administration
- Automated testing support with PHPUnit

## Local Development

### Requirements

- PHP 8.2+
- Composer
- Node.js / npm
- A supported database

### Setup

```bash
git clone <repository-url>
cd flashship-backend
composer run setup
```

Configure environment-specific services in `.env`, including database, Firebase, Redis, payment, and other external integrations as required.

### Development

```bash
composer run dev
```

The development command starts the Laravel server, queue listener, application logs, and Vite development process.

### Tests

```bash
composer test
```

## Related Flashship Applications

The Flashship ecosystem also includes separate Flutter applications for customers, drivers, and partner shops.

## Project Context

Flashship is an actively developed real-world delivery platform rather than a tutorial or framework demonstration. The repository is structured around the operational requirements of a multi-role delivery system and continues to evolve as the product grows.

## Security

Credentials and production secrets must never be committed to this repository. Environment-specific values should be configured through `.env` and the deployment environment.

---

**Flashship** — Local delivery and on-demand services platform.

# ITP HRM — Backend

Separate Laravel API for ITP HRM, backed by PostgreSQL and Redis. Modular monolith with audit trails, effective-dated HR records, role/scope authorization and private documents.

## Initial setup
Run `composer create-project laravel/laravel .` in an empty checkout bootstrap directory, then integrate the tracked application modules and configuration described in `docs/ARCHITECTURE.md`. Do not overwrite existing committed files. Actual framework bootstrap and package locking are pending a compatible local PHP/Composer environment.

Never commit `.env`, real employee data, encryption keys or unredacted legacy exports. See `docs/ARCHITECTURE.md`, `docs/DATA-MODEL.md`, and `docs/ROADMAP.md`.

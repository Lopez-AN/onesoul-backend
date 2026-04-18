# AGENTS Guide for OneSoul Backend
Use this guide when working in `C:\dev\git\OneSoul\Backend`.
Favor existing repo patterns over generic framework defaults.

## 1) Stack and Architecture
- PHP backend using Slim 4.
- Routing entrypoint: `public/index.php`.
- Core layers:
  - Routes: `src/Routes/*.php`
  - Controllers: `src/Controllers/*.php`
  - Models: `src/Models/*.php`
  - Workers: `src/Workers/*.php`
- Persistence and infra:
  - MySQL via PDO (`src/Core/Database.php`)
  - Redis via Predis (`predis/predis`)

## 2) Setup and Run
- Install dependencies: `composer install`
- Config file required: `config/config.json`
- Serve app (local simple): `php -S 0.0.0.0:8080 -t public`

## 3) Validation Commands
- Validate PHP syntax for touched files:
  - `php -l src/Controllers/FooController.php`
  - `php -l src/Models/Foo.php`
- For worker changes, also validate worker entrypoint:
  - `php -l src/Workers/SomeWorker.php`

## 4) Code Organization Rules
- Keep route files thin: validate auth/middleware wiring and delegate to controller.
- Keep controllers focused on request flow, validation, authorization and orchestration.
- Keep SQL and persistence behavior in models.
- Reuse existing utilities (`ParameterValidator`, helpers, enums) before adding new abstractions.

## 5) Error Handling and API Semantics
- Preserve existing response style (`error.code`, `error.desc`) and HTTP semantics.
- Handle expected failure branches explicitly (404/403/409/etc).
- Avoid silent catches; if fallback is intentional, make it explicit.
- Avoid changing endpoint contracts unless requested.
- When validating numeric values, avoid truthy/falsy checks (`if (!$value)`) if `0` is valid; use explicit null/undefined checks.

## 6) Workers and Concurrency
- Worker bootstrapping should go through `src/Workers/initWorker.php`.
- Any token refresh or shared mutable state flow must guard against race conditions.
- Prefer Redis atomic lock (`SET key value NX EX ttl`) over `GET`+`SET` patterns.
- Always release lock in `finally` when lock was acquired.

## 7) Simplicity (KISS)
- Keep implementations as simple as possible (KISS).
- Avoid creating helper functions that only wrap trivial one-liners and do not improve readability or reuse.
- Prefer direct, local expressions when behavior is obvious and used once.
- Extract a function only when it removes real duplication, encapsulates business logic, or improves testability.

## 8) Do / Do Not
Do:
- Keep edits scoped and architecture-consistent.
- Reuse existing models/controllers/services before adding new layers.
- Keep schema-sensitive changes aligned with `database/*.sql` when applicable.

Do not:
- Introduce new frameworks/infrastructure unless requested.
- Perform broad refactors unrelated to the task.
- Commit secrets or environment-specific credentials.

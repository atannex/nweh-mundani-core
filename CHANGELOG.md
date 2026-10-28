# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added (application layer)

- `CalendarQueryService` with frontend read models (`Application\Data`): day, range, month, year, compare and compare-range, cycles, periods, occurrences, event search, history, definition versions, translations, diagnostics.
- `Calendar::month()`, `year()`, `day()`, `days()`, `summary()`; bulk `CalendarViewBuilder` (observances loaded once per view, rule contexts reused).
- `CalendarWorkflowService`: create calendar, drafts from published versions, draft edits (cycles, positions, anchors, anchor corrections, overrides, sources), submit, approve (separate approver), reject, publish (approval required by default), suspend, archive, reactivate.
- `ObservanceManagementService`: create, update, publish, unpublish, cancel; cancel, reschedule and restore single occurrences (overrides, no per-date rows).
- `TranslationManagementService` and tenant-aware `TranslationCatalog`.
- Calendar status (`active`/`suspended`/`archived`), version states `under-review`/`approved`, observance status, source visibility.
- 12 new after-commit events; stable `errorKey()`/`toArray()` on exceptions; query limits.
- Migration `2026_10_29_000000_add_workflow_to_nweh_mundani_tables` (additive).
- Docs: application services, frontend integration, workflows, events, caching, upgrading, audit.

### Added (drivers)

- `solar` (Gregorian, Julian), `lunar-tabular` (tabular Hijri, civil/astronomical epoch), `lunisolar-hebrew` drivers with reverse conversion, leap rules, arithmetic and month/year periods; composite calendars (arithmetic plus cycles); `parameters` and `names` on definitions; `Calendar::nativeMonth()` / `CalendarQueryService::nativeMonth()`; optional reference definitions.
- Migration `2026_10_29_000100_add_driver_settings_to_nweh_mundani_tables` (additive).

### Fixed

- A cached stored version kept its old status after submit, approve or reject; every workflow write now invalidates the calendar's cache entries.

### Changed

- Publication requires an approved version (`workflow.require_review`, default true).
- A tenant can no longer silently shadow a global calendar id (`tenancy.allow_shadowing`, default false).
- `observancesOn()` honours publication state, cancellations and reschedules.
- Explicit `$fillable` on every model.
- Immutability guards moved from model events into Eloquent's write methods (cannot be disabled by `Event::fake()`/`withoutEvents()`).
- Cache misses are cached; translation memo is request-scoped.
- Code-registered calendars can be suspended and archived.
- `RelativeHandler` memoises resolved targets (year views with relative rules ~15× faster).

### Added (initial build)

- Calendar kernel: `CalendarManager` (facade `NwehMundani`), registry, resolver with tenant-aware precedence, factory, validator, diagnostics.
- Immutable domain model: definitions, identities, versions, cycles, positions, anchors with effective ranges, per-date overrides, epochs, metadata with assumptions, capabilities.
- Civil-date arithmetic on integer day numbers (proleptic Gregorian, astronomical years).
- Cyclic driver: any positive length, several cycles per calendar, conflicting-anchor detection, certainty labels, next/previous occurrence, cycle runs.
- Driver capability contracts for reverse conversion, periods, cycle queries, leap rules, arithmetic and validation.
- Rule engine with `fixed-date`, `occurrences`, `cycle-position`, `recurrence` and `relative` handlers; observances with server-side visibility.
- Unicode-exact terms with alternates, translations, pronunciation, audio and localization providers.
- Sources, verification workflow, append-only revision log.
- Eloquent persistence (12 `nmc_` tables) with draft → publish → supersede, immutable published versions, transactional writes; in-memory adapters.
- Generation-keyed cache for stored definitions and observances.
- Artisan commands: `list`, `show`, `date`, `compare`, `validate`, `diagnose`, `seed-examples`.
- Reference datasets: Nweh, Mundae (Wabane), Mmouck and Mmock (eight-day cycles anchored on 2026-10-01; continuous repetition assumed).

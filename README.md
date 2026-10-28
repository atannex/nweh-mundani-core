# Nweh Mundani Core

A headless calendar engine for Laravel. It registers, calculates, compares and governs calendar systems, including indigenous calendars that do not fit the Gregorian mould.

The engine does not assume seven-day weeks, twelve months, years, a single epoch, a single language or a single tradition. It ships four verified calculation drivers. **cyclic** handles repeating cycles of any positive length, with as many independent cycles per calendar as you need. **solar** covers Gregorian and Julian. **lunar-tabular** is the arithmetic Hijri calendar, and **lunisolar-hebrew** the arithmetic Hebrew calendar. Year/month/day calendars can also carry cycles. Around the drivers the package provides frontend-ready read models, a review-and-publish workflow, versioned and auditable definitions, safe rule-based observances with cancellable and reschedulable occurrences, terminology kept exactly as written in Unicode, provenance and verification tracking, and tenant isolation.

## Development

```bash
composer install
composer test        # Pest (unit, feature, persistence on in-memory SQLite)
composer analyse     # PHPStan / Larastan, level 8
composer format      # Laravel Pint
composer check       # all of the above plus composer validate
```

## License

MIT. See [LICENSE.md](LICENSE.md).

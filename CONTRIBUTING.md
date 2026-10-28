# Contributing

Thank you for helping. A few rules keep this package trustworthy:

1. **No invented cultural data.** Day names, spellings, anchors, observances and meanings must come with a source (`docs/provenance.md`). If something is not yet verified, mark it with the right verification status or as an assumption. Do not present it as fact.
2. **No fake algorithms.** A driver is merged only when it is complete and tested against published reference correspondences. Approximations belong in applications, not here.
3. **Keep the engine generic.** Nothing in `src/` may assume a cycle length, a community or a language. Calendar data belongs in definitions.
4. **Preserve Unicode exactly.** Never normalise, trim or transliterate a `Term::$original`.
5. **Tenant safety and authorisation** apply to every new write path. Route it through `CalendarAdministration`.

## Workflow

```bash
composer install
composer check        # validate, pint --test, phpstan (level 8), pest
```

- Add tests for every behaviour change. Calculation changes need property-style tests as well as examples.
- Update `CHANGELOG.md` under *Unreleased*.
- Bump `CyclicDriver::version()` (or your driver's) when results change, so cache keys change too.
- Public API changes follow semantic versioning: breaking changes only in a new major version.

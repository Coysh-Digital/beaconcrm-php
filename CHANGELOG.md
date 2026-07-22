# Release Notes for Beacon CRM PHP

## 1.0.0 - 2026-07-22

Every endpoint below has been exercised against a live Beacon account, apart
from delete and the export endpoints — see the README's endpoint confidence
table.

### Added

- Initial release.
- `BeaconClient` for reading an account's schema and creating, reading,
  updating, upserting, listing and deleting records.
- Schema objects (`EntityType`, `Field`, `FieldType`) built from the account's
  own `entity_types` response, so custom record types and `c_*` fields need no
  configuration.
- `EntityPayload` and `ValueShaper`, which convert plain PHP values into the
  JSON shapes Beacon expects for each field type.
- Typed exceptions, with Beacon's `error.raw` detail surfaced rather than
  swallowed.
- Retries with exponential backoff and jitter for rate limits and transient
  server errors, honouring `Retry-After`.
- Unsent `Request` objects, for hosts that need to do their own sending.
- CSV export triggering and polling.
- Paged listing via `list()` and `each()`, which walks every page for you.

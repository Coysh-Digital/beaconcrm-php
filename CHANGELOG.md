# Release Notes for Beacon CRM PHP

## 1.1.0 - 2026-08-05

### Added

- Location fields can now be written. Beacon models an address as a contact
  point — a list of objects with `is_primary`, exactly like emails and phones —
  rather than the single object previously assumed. Verified against a live
  account.
- Addresses can be set whole, as a list or a bare object, or one part at a time
  through flat handles such as `address:city`, which lets a mapping UI fill one
  in. `Field::LOCATION_PARTS` lists the writable keys.
- `Field::isLocation()`, `Field::parts()` and `FieldType::isContactPoint()`.
- `Field::partHandles()` now covers addresses as well as person names.

### Fixed

- A bare address object is wrapped in a list before sending. Beacon answers the
  unwrapped form with an HTTP 500 carrying a leaked backend error, `Cannot
  assign to read only property '0' of object '[object String]'`, which reads
  like an outage rather than a payload problem.
- Location fields are no longer excluded from `mappableFields()`, and the README
  no longer claims they cannot be written.

## 1.0.0 - 2026-07-22

Every endpoint has been exercised against a live Beacon account, apart from the
export endpoints. See the README's endpoint confidence table.

### Added

- Initial release.
- `BeaconClient` for reading an account's schema and creating, reading,
  updating, upserting and listing records.
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

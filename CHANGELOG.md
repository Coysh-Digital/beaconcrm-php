# Release Notes for Beacon CRM PHP

## 1.2.0 - 2026-08-17

Linking one record to another, verified against a live account.

### Added

- `Entities::link()` and `unlink()` add and remove record links without
  disturbing the links already there. A write to a link field **replaces** the
  whole list rather than appending to it, so adding one link with a plain
  `update()` silently drops every other. Both return null when the links were
  already as asked and nothing was sent.
- `Entities::links()` reads a link field's current record IDs, and `setLinks()`
  replaces them outright.
- `Entities::resolveId()` turns a value into a record ID in one request, via
  upsert, creating the record if nothing matches — the practical way to get from
  a name you hold to the ID a link field needs.
- `Entities::findBy()` finds a record by field value without creating anything.
  Beacon has no search endpoint, so it pages and compares client-side; the
  docblock and README are explicit about the cost. Comparison is
  case-insensitive, and looks inside contact points, since a stored email is
  `[{"email": …}]` rather than a bare string.
- `ValueShaper::referenceIds()` normalises a link value read back off a record.
- `Field::linksTo()` and `linksToIds()` report the record types a link may point
  at. Beacon names them by numeric ID, so `EntityType` now exposes its `id` and
  `listFromResponse()` resolves those IDs to keys in a second pass.
- `Field::isReference()`.

### Notes

- **Beacon's Relationships feature has no API.** Types such as Employee and
  Trustee, their reciprocal sides and their dates cannot be read or written.
  `relationships`, `relationship_types`, `entity_relationships`,
  `entity_type_relationship_blocks` and the per-record paths were all probed
  against a live account, and none is a route. Use a point-to-another-record
  field instead; the README explains the distinction.
- An unknown top-level path does not answer with a 404. Beacon accepts the
  connection and never replies, so the request times out — which, with retries
  on, looks like an outage rather than a missing endpoint.

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

# Beacon CRM for PHP

A PHP client for the [Beacon CRM](https://beaconcrm.org) API.

Beacon generates its API documentation from each account's own database
configuration, so no two accounts look alike. This library leans into that:
it reads your schema at runtime and shapes values against it, rather than
hard-coding a set of record types and fields that would only ever fit one
account.

```php
use CoyshDigital\Beacon\BeaconClient;

$beacon = BeaconClient::make(getenv('BEACON_ACCOUNT_ID'), getenv('BEACON_API_KEY'));

$people = $beacon->entitiesWithSchema('person');

$id = $people->create(
    $people->payload()
        ->set('name:first', 'Alex')
        ->set('name:last', 'Rivera')
        ->set('emails', 'alex@example.org')
        ->set('c_monthly_gift', 25.5)
)->entityId();
```

## Features

- **Schema-driven.** Record types, fields, drop-down options and cardinality all
  come from your account. Custom types and `c_*` fields work with no extra code.
- **Correct shapes, automatically.** Beacon wants a different JSON shape for
  almost every field type, and is not always loud about getting one it dislikes.
  Values are converted for you — see [Field shaping](#field-shaping).
- **Useful errors.** Beacon hides the real cause of a validation failure in a
  nested `error.raw` property behind a generic message. It is unpacked into
  typed exceptions.
- **Sensible retries.** Rate limits and transient server errors are retried with
  exponential backoff and jitter, honouring `Retry-After`. Validation failures
  never are.
- **Embeddable.** Every call can be described without being sent, so a framework
  can do its own HTTP and keep its own events, logging and test modes.

## Requirements

- PHP 8.2+
- Guzzle 7.5+ (installed with the package)

## Installation

```bash
composer require coyshdigital/beaconcrm-php
```

## Authentication

In Beacon, go to **Settings → API keys** and create a key. Two things to know:

- Only an administrator can create one.
- The key is shown **once**, when you create it. Copy it immediately. If you
  lose it, revoke it and make a new one.

Your account ID is the number in your Beacon API URL:

```
https://api.beaconcrm.org/v1/account/12345
                                     ^^^^^
```

Keep both out of your code:

```bash
BEACON_ACCOUNT_ID=12345
BEACON_API_KEY=your-secret-key
```

```php
$beacon = BeaconClient::make(getenv('BEACON_ACCOUNT_ID'), getenv('BEACON_API_KEY'));

if (!$beacon->ping()) {
    // Wrong account ID, or the key was revoked or mistyped.
}
```

A Beacon key grants full access to the account, so it belongs in an environment
variable or a secrets manager — never in code or in version-controlled config.

## Discovering the schema

```php
foreach ($beacon->entityTypes()->all() as $type) {
    echo $type->key . ' — ' . $type->label . PHP_EOL;

    foreach ($type->mappableFields() as $field) {
        echo '  ' . $field->key . ' (' . $field->rawType . ')' . PHP_EOL;

        if ($field->options()) {
            echo '    options: ' . implode(', ', $field->options()) . PHP_EOL;
        }
    }
}
```

`EntityType` gives you:

| Method | What you get |
| --- | --- |
| `fields()` | Every field, keyed by field key |
| `field('emails')` | One field; a part handle such as `name:first` resolves to its parent |
| `writableFields()` | Fields Beacon will accept a write to |
| `mappableFields()` | Fields writable from a single plain value — the set worth showing in a mapping UI |

and `Field` gives you `label`, `type` (a `FieldType`), `rawType`, `options()`,
`allowsMultiple()`, `includesTime()`, `isWritable()` and `isMappable()`.

## Creating records

Build the body with `payload()`, which shapes each value against the field it is
going to:

```php
$people = $beacon->entitiesWithSchema('person');

$response = $people->create(
    $people->payload()
        ->set('name:first', 'Alex')
        ->set('name:last', 'Rivera')
        ->set('emails', 'alex@example.org')
        ->set('phone_numbers', '+441234567890')
        ->set('c_tier', 'Gold')          // single-select: sent as ["Gold"]
        ->set('organisation', 4812)      // record link: sent as [4812]
        ->set('c_monthly_gift', 25.5)    // currency: sent as {"value": 25.5}
);

$response->entityId();   // 4100
$response->entity();     // the full record Beacon stored
$response->references(); // linked-record data, when populated
```

`entitiesWithSchema()` fetches the record type's schema once and caches it for
the life of the client. If you already have the `EntityType`, pass it to
`entities()` instead and nothing is fetched. `entities('person')` with no schema
still works — values are then sent exactly as given, which is what you want if
you are shaping them yourself.

Arrays are fine anywhere Beacon takes multiple values:

```php
$people->payload()
    ->set('emails', ['work@example.org', 'home@example.org'])  // first is primary
    ->set('c_channels', ['Email', 'SMS']);
```

## Reading records

```php
$response = $people->read(1988);

// Skip linked-record data — much faster for large exports.
$response = $people->read(1988, populate: false);

// Include archived records.
$response = $people->read(1988, archived: true);
```

## Updating and upserting

Upsert creates a record, or updates the one whose lookup field matches. It is
the documented way to avoid duplicates from repeat submissions:

```php
$people->upsert('emails', $people->payload()
    ->set('emails', 'alex@example.org')
    ->set('c_tier', 'Gold'));
```

The lookup field must be genuinely unique in your account, and must carry a
value in the payload — otherwise nothing can match and every call creates
another record. That case is caught here, before the request goes out:

```php
use CoyshDigital\Beacon\Exception\InvalidPayloadException;

try {
    $people->upsert('emails', $people->payload()->set('c_tier', 'Gold'));
} catch (InvalidPayloadException $e) {
    // Upsert key "emails" has no value in the person payload…
}
```

An email address is the obvious key for people, but it is also mutable. For
migrations and repeatable imports, a stable legacy or external ID makes a far
better one.

There is also a direct `update()`, but see
[Endpoint confidence](#endpoint-confidence) before relying on it.

## Listing, filtering and deleting

```php
use CoyshDigital\Beacon\Filter\FilterBuilder;

$response = $people->list(['page' => 2], populate: false);

$response = $people->search(
    FilterBuilder::all()
        ->where('c_tier', 'eq', 'Gold')
        ->toArray()
);

$people->delete(1988);
```

These four are the least certain part of the library — again, see
[Endpoint confidence](#endpoint-confidence).

## Exports

Trigger a CSV export from a saved export template, poll until it finishes, then
download from the signed URL:

```php
$exportId = $beacon->exports()->trigger($templateId)->entity()['id'] ?? null;

$status = $beacon->exports()->status($exportId)->results()[0] ?? [];

$status['status'];   // in_progress | finished
$status['progress']; // 0–100
```

Download URLs expire after an hour, though the data is kept for seven days —
poll again for a fresh one. Beacon describes these endpoints as early access
and does not list them in its main developer documentation.

## Field shaping

Beacon expects a different JSON shape for each field type. `EntityPayload`
converts your values so you do not have to think about it:

| Beacon field type | What gets sent |
| --- | --- |
| Short text, long text, URL | The value as-is |
| Person name | An object of name parts, with `full` derived if you set only the parts |
| Email | `[{"email": "…", "is_primary": true}]` |
| Phone | `[{"number": "…", "is_primary": true}]` |
| Drop-down | An array of values, **even for single-select fields** |
| Record link | An array of integer Beacon record IDs, even for single links |
| Checkbox | A JSON boolean |
| Number, percent, rating | A JSON number |
| Currency | An object, `{"value": 25.5}` |
| Date | An ISO date, such as `2026-07-21` |

Person names are addressed one part at a time — `name:full`, `name:first`,
`name:last`, `name:middle`, `name:prefix` — and reassembled into a single object
when the payload is built. Set only `first` and `last` and `full` is derived,
which matters because `full` is what Beacon shows throughout its UI.

> **Currency fields do not take a plain number.**
> Beacon wants an object, and amounts are in **major units**: `25.5` means
> £25.50, not 25.5 pence. Send a bare number and Beacon accepts the request with
> a success response and then stores nothing — the amount vanishes with no error
> anywhere. This library always sends the object form, but it is worth knowing
> if you write to Beacon from anywhere else.

The currency code is left off deliberately, so Beacon applies your account's
default. Its response echoes the value back with the currency filled in:

```json
{ "currency": "GBP", "value": 25.5, "base_value": 25.5 }
```

### Empty values

Empty values (`null`, `''`, `[]`) are skipped rather than sent, so a blank
optional field cannot overwrite data Beacon already holds. `0` and `false` are
real values, not empty ones, and are sent.

### Fields you cannot write

`mappableFields()` leaves out fields that cannot be written from a plain value:

- **File uploads.** Beacon requires a separate signed-upload handshake that
  cannot happen inside a record payload. File fields can be *read* — you get a
  signed download URL, valid for 60 minutes.
- **Locations and addresses.** Beacon expects a structured address object.
- **Beacon users.** These refer to user accounts rather than data.
- **Anything Beacon calculates.** Smart fields, rollups and auto-increments are
  computed by Beacon and rejected on write. That covers a lot of useful-looking
  fields — totals, counts, percentages — derived from other records.

## Error handling

Every failure is a `BeaconException`. The subclasses tell you what to do about
it:

| Exception | When | Retried |
| --- | --- | --- |
| `AuthenticationException` | 401/403 — key revoked, mistyped, or wrong account | No |
| `ValidationException` | 400, or a 5xx carrying Beacon's `error.raw` detail | No |
| `NotFoundException` | 404 — no such account, record type or record | No |
| `RateLimitException` | 429 — over the rate limit | Yes |
| `ServerException` | A bare 5xx | Yes |
| `TransportException` | No HTTP response at all: DNS, timeout, TLS | Yes |
| `InvalidPayloadException` | Caught here, before sending. Nothing was written | n/a |
| `ConfigurationException` | Missing account ID or API key | n/a |

```php
use CoyshDigital\Beacon\Exception\ApiException;

try {
    $people->create($payload);
} catch (ApiException $e) {
    $e->getStatus();     // 500
    $e->getErrorCode();  // unknown_error
    $e->getMessage();    // Oh shoot! An unknown error occurred.
    $e->getRaw();        // Validation error: "emails": 0   ← the useful one
    $e->getPayload();    // what was sent, credentials redacted
    $e->getSummary();    // all of the above on one line, for a log
}
```

> **Why a 500 can be a validation failure.**
> Beacon reports many validation problems with a 500 status and the real cause
> buried in `error.raw`, while `message` stays generic. Those requests will never
> succeed as sent, and retrying a create would duplicate the record — so a 5xx
> carrying `error.raw` is classified as a `ValidationException` and never
> retried. A bare 5xx, with no such detail, is treated as transient.

## Rate limits and retries

Beacon allows 300 requests a minute, or 60 for bulk operations, and answers a
breach with a 429. The default policy makes 3 attempts with exponential backoff
and jitter, honouring `Retry-After` when Beacon sends it:

```php
use CoyshDigital\Beacon\Http\RetryPolicy;

$beacon = BeaconClient::make($accountId, $apiKey, new RetryPolicy(
    maxAttempts: 5,
    baseDelayMs: 1000,
));

// Or turn retries off and handle them yourself.
$beacon = BeaconClient::make($accountId, $apiKey, RetryPolicy::none());
```

## Describing a request without sending it

Every method that sends has a `…Request()` twin returning an unsent `Request`:

```php
$request = $people->createRequest($payload);

$request->method; // POST
$request->path;   // entity/person
$request->uri();  // entity/person?populate=false
$request->body;   // the shaped entity
```

This exists for host frameworks that wrap HTTP in their own events, logging,
proxy settings or test modes. The
[Formie Beacon CRM plugin for Craft CMS](https://github.com/Coysh-Digital/craft-formie-beacon)
uses it to shape payloads here while letting Formie do the sending, so its
integration logging and payload events keep working.

## Anything else

Beacon generates its API per account, so your account may expose endpoints this
library does not model. Paths are relative to `…/v1/account/{accountId}/`:

```php
$response = $beacon->request('GET', 'some_other_endpoint', ['page' => 2]);
$response->toArray();
```

## Endpoint confidence

Beacon's full API documentation is generated per account and sits behind a
login, so not every endpoint here could be verified against it.

| Endpoint | Status |
| --- | --- |
| `GET entity_types` | Verified |
| `POST entity/{type}` | Verified |
| `GET entity/{type}/{id}` | Verified |
| `PUT entity/{type}/upsert` | Verified |
| `POST entity_export/trigger`, `GET entity_exports` | Documented by Beacon as early access |
| `PUT entity/{type}/{id}` (update) | **Inferred** from REST convention |
| `DELETE entity/{type}/{id}` | **Inferred**; Beacon's own model is archiving, not deletion |
| `GET entity/{type}` (list) | **Inferred**, including its pagination parameters |
| `POST entity/{type}/search` | **Inferred**, including the filter body shape |

The inferred ones are marked `@experimental` in the source. Check them against
your account's own documentation at
[developers.beaconcrm.org](https://developers.beaconcrm.org) before relying on
them, and use `request()` in the meantime. Corrections are very welcome.

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The test suite runs entirely against mocked HTTP, using an invented schema — no
real account's field keys or option values appear in this repository.

## Contributing

Issues and pull requests are welcome at
[Coysh-Digital/beaconcrm-php](https://github.com/Coysh-Digital/beaconcrm-php).
If you can confirm or correct one of the inferred endpoints above against your
own account documentation, that is especially useful.

## License

MIT. See [LICENSE.md](LICENSE.md).

This is an independent library and is not affiliated with or endorsed by Beacon.

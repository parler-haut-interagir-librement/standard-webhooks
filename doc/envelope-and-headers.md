# Envelope and headers

**State: implemented and tested.** Classes under `Phil\StandardWebhooks`.

The library follows the [Standard Webhooks](https://github.com/standard-webhooks/standard-webhooks) specification,
version 1.0.0. A delivery is an HTTP request with three headers and a JSON body.

## The three headers: `WebhookHeaders`

```text
webhook-id: msg_2KWPBgLlAfxdpx2AI54pPJ85f4W
webhook-timestamp: 1614265330
webhook-signature: v1a,<base64> v1,<base64>
```

`WebhookHeaders` holds the three parsed values as public readonly properties `WebhookId $id`,
`WebhookTimestamp $timestamp` and `SignatureList $signatures`. Its constructor is public (the `Signer` builds
one); constants `ID`, `TIMESTAMP` and `SIGNATURE` hold the lowercase header names.

| Method | Description |
|---|---|
| `fromStrings(?string $id, ?string $timestamp, ?string $signature): self` | Reads the headers as received. A `null` header throws `InvalidWebhookValue` (`The "webhook-id" header is missing.`); each value is then parsed by the classes below. |
| `toArray(): array` | `['webhook-id' => ..., 'webhook-timestamp' => ..., 'webhook-signature' => ...]`, ready to send. |

```php
$headers = WebhookHeaders::fromStrings(
    $request->headers->get(WebhookHeaders::ID),
    $request->headers->get(WebhookHeaders::TIMESTAMP),
    $request->headers->get(WebhookHeaders::SIGNATURE),
);
```

## `webhook-id`: `WebhookId`

The message identifier, **the same on every attempt of one delivery**, which is what lets a receiver de-duplicate
retries.

The specification gives no syntax, only that the id must not contain a full stop, since the full stop delimits
the signed content. `WebhookId::fromString()` also refuses what has no place in a header or a storage key, and
throws `InvalidWebhookValue` for:

- an empty value;
- more than `MAX_LENGTH` (255) bytes;
- anything outside printable ASCII without space and full stop (`0x21` to `0x2D` and `0x2F` to `0x7E`): spaces,
  tabs, line breaks, NUL, non-ASCII characters and `.` are refused.

`$value` holds the id; `equals()` compares exactly. The specification's example
`msg_2KWPBgLlAfxdpx2AI54pPJ85f4W` is accepted.

## `webhook-timestamp`: `WebhookTimestamp`

Integer seconds since the Unix epoch, as the sender's clock saw the attempt.

| Method | Description |
|---|---|
| `fromHeader(string $header): self` | **Strict**: decimal digits only, no sign, no leading zero (`0` itself is accepted), no fraction, no surrounding whitespace, at most twelve digits (past the year 30000, far from integer overflow). Milliseconds (`1614265330000`) and ISO 8601 dates are refused. The header is part of the signed content, so a lenient parse would accept a string the sender never signed in that form. |
| `fromDateTime(DateTimeImmutable $at): self` | For the sender. A date before the epoch throws `InvalidWebhookValue`. |
| `skewFrom(DateTimeImmutable $now): int` | The absolute distance to the receiver's clock, in seconds. A timestamp from the future is as suspect as one from the past. |
| `isWithin(Tolerance $tolerance, DateTimeImmutable $now): bool` | `skewFrom($now) <= $tolerance->seconds`: the bound is inclusive on both sides. |
| `toHeader(): string` | The decimal string. |

`$seconds` holds the integer.

## The tolerance: `Tolerance`

How far `webhook-timestamp` may be from the receiver's clock, in either direction.
`Tolerance::ofSeconds(int $seconds)` accepts any positive integer and throws `InvalidWebhookValue` for zero or a
negative value; `$seconds` holds it.

**The specification names no number, and neither does the library.** Choose a value above the clock drift you
tolerate and **below the window for which processed `webhook-id`s are kept**, or a captured delivery replays
once its id is forgotten.

## `webhook-signature`: `SignatureList` and `Signature`

A space-delimited list of `{version},{base64 signature}` entries, so that two keys overlap during a rotation.
Details of each scheme are in [signatures.md](signatures.md).

`Signature` (one entry) has public `Scheme $scheme` and `string $bytes`:

| Method | Description |
|---|---|
| `of(Scheme $scheme, string $bytes): self` | Throws `InvalidWebhookValue` unless the length matches the scheme: 32 bytes for `v1`, 64 for `v1a`. |
| `tryFromHeaderEntry(string $entry): ?self` | Returns `null`, rather than throwing, for an entry that cannot verify: an unknown version, a missing or second comma, a payload that is not strict padded base64, or a signature of the wrong length. |
| `toHeaderEntry(): string` | `v1,<base64>` or `v1a,<base64>`. |

`SignatureList` has a public `list<Signature> $signatures` and `MAX_ENTRIES = 8`:

| Method | Description |
|---|---|
| `of(Signature ...$signatures): self` | For the sender. Throws `InvalidWebhookValue` for zero or more than eight signatures. Keys of a spread array are dropped. |
| `fromHeader(string $header): self` | For the receiver. Splits on single spaces and ignores empty entries (repeated spaces are harmless). **More than eight entries throws `InvalidWebhookValue` before any entry is read.** Unreadable entries are dropped, so the list may be empty: a header with nothing the receiver can verify is a delivery that fails verification, not a malformed one. |
| `toHeader(): string` | The entries joined by single spaces. |

**Why the cap:** every entry is a verification the receiver performs before it knows whether the sender is
genuine, so an unbounded list is work an attacker makes it do for free. **Why unknown versions are dropped
rather than fatal:** a sender may add a scheme before every receiver knows it; an unknown version is ignored,
never accepted.

## The body: `Envelope`

The JSON payload: the `type`, `timestamp` and `data` that Standard Webhooks recommends, plus an optional
`audience`. **Read it only after the signature verified, from the same raw body** (`VerifiedWebhook::envelope()`
does exactly that).

```json
{"type":"invoice.paid","timestamp":"2026-10-31T12:00:00Z","data":{"id":"abc"},"audience":"receiver-1"}
```

`Envelope::fromJson(string $rawBody)` returns an envelope with public `string $type`, `?string $timestamp`,
`array $data` and `?string $audience`, and throws `InvalidWebhookValue` when:

- the body is not JSON, or nests deeper than 64 levels (the object itself included);
- the body is not a JSON object (a non-empty list or a scalar);
- `type` is missing, empty or not a string; **only `type` is required**;
- `timestamp` is present and not a string (it is kept as given, not parsed);
- `data` is present and not an object (a scalar or a non-empty list); a missing `data` is `[]`;
- `audience` is present and not a string.

Decoded to PHP arrays, `{}` and `[]` are the same value: an empty `data` is accepted as an empty object, and an
empty body fails on its missing `type`. Any other member of the body is ignored.

### `audience`

`audience` is an extension to the recommended envelope. When one key signs for many receivers, a delivery
received by one receiver still verifies at another for as long as the timestamp tolerance lasts; an audience under
the signature tells them apart. `assertAddressedTo(string $audience)` compares **strictly** (an audience is an
identifier, never a pattern) and throws `WebhookRejected` with reason `AudienceMismatch` when the envelope names
another audience or none. `WebhookReceiver` calls it when you configure an audience.

## `VerifiedWebhook`

What the receiver returns once timestamp and signature verified: public `WebhookId $id`,
`WebhookTimestamp $timestamp`, `string $rawBody`, and `envelope(): Envelope`, which decodes the raw body on each
call. **Only this, never the raw request, is handed to the code that processes the event.** Its constructor is
public, so tests can build one directly.

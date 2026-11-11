# Testing

## What the library's own tests cover

The unit suite lives in `tests/Unit` and runs with PHPUnit 13. It needs `ext-sodium`, and no database, kernel or
network. Times are fixed (`@1793448000`), never read from the system clock.

| Test class | What it pins |
|---|---|
| `SignerAndVerifierTest` | The signed content `msg_1.1614265330.{"a":1}`; `v1a` and `v1` round trips; either key verifying during a rotation; a body changed by one byte failing; another id or timestamp failing; a signature of another scheme never accepted; no trusted key meaning `NoValidSignature`; a genuine but stale delivery rejected for its timestamp, with its exact message. |
| `Ed25519KeyTest` | **The RFC 8032 vector**; the public key verifying its secret key's signature and nothing else; `whsk_` and `whpk_` round trips; a seed and its 64-byte form being the same key; a mismatched public half refused; lengths and prefixes; the redacted dump. |
| `HmacSecretTest` | **The Standard Webhooks `v1` vector**; 32-byte generation; verification of its own signatures only; the inclusive 24 and 64 byte bounds; prefixes; the redacted dump. |
| `Base64Test` | Both codecs encode the same padded alphabet, round-trip, and refuse the same inputs. |
| `SignatureListTest` | Both schemes in one header; unreadable entries dropped; repeated spaces; the cap of eight, enforced before any entry is read; at least one signature on building. |
| `WebhookIdTest`, `WebhookTimestampTest`, `ToleranceTest`, `WebhookHeadersTest` | Character sets and bounds, strict Unix seconds, the inclusive two-sided tolerance, missing headers. |
| `EnvelopeTest` | The recommended fields; only `type` required; empty `data`; malformed bodies; the 64-level nesting bound; audience match and mismatch messages. |
| `WebhookReceiverTest` | A cached key verifying without reload; an unknown key triggering exactly one reload; a forged delivery rejected after one reload; a stale delivery never reloading; audience acceptance and rejection; an empty expected audience refused. Uses the `CountingKeyProvider` fixture. |
| `KeysTest` | Keys read by their prefix, others refused; `FixedKeys` giving its keys back on refresh and keeping them out of a stack trace; generated ids, random and valid. |

### Reference vectors

The two vectors every implementation must reproduce are listed in full in
[signatures.md](signatures.md#reference-vectors): RFC 8032 section 7.1 test 1 for Ed25519, and the `v1` example
of the Standard Webhooks reference libraries for HMAC-SHA256. A change that breaks either is a change of
algorithm, not a refactoring.

## Running the tests

From the root of the monorepo:

```bash
vendor/bin/phpunit packages/standard-webhooks/tests
```

Installed on its own, in a fresh copy of the library:

```bash
cd standard-webhooks
composer install
vendor/bin/phpunit --no-configuration tests
```

Infection keeps the library at 100 % of mutants killed.

## Testing your adapters and your endpoint

**`VerificationKeyProvider`**: assert that `keys()` does not fetch, that `refresh()` fetches, that a second
`refresh()` within your budget returns the cached keys without fetching, and that a failing source leaves the
cached keys in place. A counting fixture like the library's `CountingKeyProvider` is enough to test the receiver
around it:

```php
final class CountingKeyProvider implements VerificationKeyProvider
{
    public int $refreshes = 0;

    public function __construct(private readonly array $cached, private readonly array $reloaded) {}

    public function keys(): array { return $this->cached; }

    public function refresh(): array { ++$this->refreshes; return $this->reloaded; }
}
```

**`ProcessedWebhookRepository`**, against the real database: the first `claim()` returns `true`, the second
`false`; a claim inside a transaction that rolls back leaves the id claimable; two connections claiming the same id
concurrently yield exactly one `true`.

**Your endpoint**: sign test deliveries with the library's `Signer` and a generated key, then send them through
your controller:

```php
$key = Ed25519SecretKey::generate();
$body = '{"type":"invoice.paid","data":{"id":"abc"},"audience":"receiver-1"}';
$headers = new Signer()->sign(WebhookId::fromString('msg_1'), WebhookTimestamp::fromDateTime($now), $body, $key)->toArray();

$client->request('POST', '/webhooks', server: [
    'HTTP_WEBHOOK_ID' => $headers['webhook-id'],
    'HTTP_WEBHOOK_TIMESTAMP' => $headers['webhook-timestamp'],
    'HTTP_WEBHOOK_SIGNATURE' => $headers['webhook-signature'],
], content: $body);
```

Cover at least: a valid delivery answered `2xx` and processed once; the same delivery again answered `2xx` and not
processed; a body with one byte changed refused; a timestamp one second past the tolerance refused; a delivery for
another audience refused; an unknown event type not answered `2xx`.

To test a receiver written without the library, use the fixed `v1a` example in
[signatures.md](signatures.md#reference-vectors) with `signatureVerifies()`-style logic, or the `v1` vector.

## Benchmarks

The phpbench benchmarks in `tests/Benchmark` measure the hot paths at several sizes. From the root of the monorepo:

```sh
composer bench -- packages/standard-webhooks/tests/Benchmark
```

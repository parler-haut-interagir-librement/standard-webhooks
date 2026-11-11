# Reference

Every public type of the library, by topic. All live in `Phil\StandardWebhooks`, the exceptions in
`Phil\StandardWebhooks\Exception`. Value objects are immutable (`final readonly`), with private constructors behind
named constructors unless noted.

## Receiving

| Type | Role | State |
|---|---|---|
| `WebhookReceiver` | `receive(headers, rawBody, now)`: timestamp, signature under cached keys, one reload, audience. Public constructor `(VerificationKeyProvider, Tolerance, ?string $audience = null, Verifier = new Verifier())`. | Implemented and tested |
| `VerificationKeyProvider` | Port: `keys()` and `refresh()`, the receiver's trusted keys. | Port |
| `FixedKeys` | `VerificationKeyProvider` for keys known in advance: `fromStrings()`, a public constructor; `refresh()` gives them back. | Implemented and tested |

## Signing and verifying

| Type | Role | State |
|---|---|---|
| `Scheme` | String-backed enum: `V1` (`v1`, HMAC-SHA256, 32-byte signatures) and `V1a` (`v1a`, Ed25519, 64-byte signatures); `signatureLength()`. | Implemented and tested |
| `SignedContent` | `of(id, timestamp, rawBody)`: the bytes both schemes sign, `{id}.{timestamp}.{body}`. | Implemented and tested |
| `Signer` | `sign(id, timestamp, rawBody, key, ...moreKeys)`: the `WebhookHeaders` of one attempt. | Implemented and tested |
| `Verifier` | `verify()` (timestamp first, then signatures) and `signatureVerifies()`. | Implemented and tested |
| `SigningKey` | Interface: `scheme()`, `sign()`. | Interface, implemented by `Ed25519SecretKey` and `HmacSecret` |
| `VerificationKey` | Interface: `scheme()`, `verifies()`. | Interface, implemented by `Ed25519PublicKey` and `HmacSecret` |
| `Base64` | Strict padded base64: constant-time `encodeSecret()`/`decodeSecret()`, native `encodePublic()`/`decodePublic()`. | Implemented and tested |

## Values

| Type | Role | State |
|---|---|---|
| `WebhookHeaders` | The three headers: `fromStrings()`, `toArray()`, constants `ID`, `TIMESTAMP`, `SIGNATURE`; public constructor. | Implemented and tested |
| `WebhookId` | `webhook-id`: printable ASCII without space or full stop, at most 255 bytes; `fromString()`, `generate()`, `equals()`. | Implemented and tested |
| `WebhookTimestamp` | `webhook-timestamp`: strict Unix seconds; `fromHeader()`, `fromDateTime()`, `skewFrom()`, `isWithin()`, `toHeader()`. | Implemented and tested |
| `Tolerance` | Positive number of seconds, either direction; `ofSeconds()`. | Implemented and tested |
| `Signature` | One `{version},{base64}` entry: `of()`, `tryFromHeaderEntry()`, `toHeaderEntry()`. | Implemented and tested |
| `SignatureList` | `webhook-signature`, at most eight entries: `of()`, `fromHeader()`, `toHeader()`. | Implemented and tested |
| `Ed25519SecretKey` | `whsk_` key, signs `v1a`: `generate()`, `fromString()`, `fromBytes()` (seed or 64 bytes), `toString()`, `publicKey()`. | Implemented and tested against RFC 8032 |
| `Ed25519PublicKey` | `whpk_` key, verifies `v1a`: `fromString()`, `fromBytes()`, `toString()`, `equals()`. | Implemented and tested against RFC 8032 |
| `Keys` | `signing()` and `verification()`: a key read by its prefix. | Implemented and tested |
| `HmacSecret` | `whsec_` secret of 24 to 64 bytes, signs and verifies `v1`: `generate()`, `fromString()`, `toString()`. | Implemented and tested against the Standard Webhooks `v1` vector |
| `Envelope` | The JSON body: `type` (required), `timestamp`, `data`, `audience`; `fromJson()`, `assertAddressedTo()`. | Implemented and tested |
| `VerifiedWebhook` | A delivery whose timestamp and signature verified: `id`, `timestamp`, `rawBody`, `envelope()`; public constructor. | Implemented and tested |

## De-duplication

| Type | Role | State |
|---|---|---|
| `ProcessedWebhookRepository` | Port: atomic `claim(id, processedAt)` inside the processing transaction. | Port |

## Exception

| Type | Role | State |
|---|---|---|
| `WebhookException` | Marker interface extending `Throwable`: one `catch` at the HTTP boundary. | Implemented |
| `InvalidWebhookValue` | `InvalidArgumentException`: malformed input, typically `400`; `because(string)`. | Implemented and tested |
| `WebhookRejected` | `RuntimeException`: a well-formed delivery refused; public `RejectionReason $reason`; `timestampOutsideTolerance()`, `noValidSignature()`, `audienceMismatch()`. | Implemented and tested |
| `RejectionReason` | String-backed enum: `TimestampOutsideTolerance`, `NoValidSignature`, `AudienceMismatch`. | Implemented and tested |

## Not in the library

- An HTTP layer: a controller, a request parser, an outbound client, a retry schedule or a dead-letter queue.
- A storage for the processed ids.

`phil/standard-webhooks-bundle` brings the Symfony wiring, the controller argument, a sender over Symfony's HTTP
client and a Doctrine table.

# Signatures

**State: implemented and tested.** Both schemes of Standard Webhooks 1.0.0, Ed25519 (RFC 8032) through
libsodium and HMAC-SHA256 through `hash_hmac()`.

## The signed content: `SignedContent`

Both schemes sign the same bytes:

```text
{webhook-id}.{webhook-timestamp}.{body}
```

`SignedContent::of(WebhookId $id, WebhookTimestamp $timestamp, string $rawBody): string`. For example,
`msg_1`, `1614265330` and `{"a":1}` give `msg_1.1614265330.{"a":1}`.

- **The body is the raw body as received, byte for byte**, verified before any decoding. A body decoded and
  re-encoded before verification is a different string as soon as a key order, an escape or a whitespace
  differs, and the signature fails for a reason nobody can see. A test pins that one added space, or one space
  inside the JSON, makes a genuine signature fail.
- The full stop is unambiguous only because `WebhookId` refuses one and a timestamp is digits.
- **This layout is the interoperability contract.** Changing it breaks every receiver and every sender.

## The schemes: `Scheme`

| Case | Label | Algorithm | Raw signature | Key prefix |
|---|---|---|---|---|
| `Scheme::V1a` | `v1a` | Ed25519, asymmetric: the sender signs with a secret key, the receiver verifies with the public key | 64 bytes | `whsk_` (secret), `whpk_` (public) |
| `Scheme::V1` | `v1` | HMAC-SHA256 under a secret shared by one sender and one receiver | 32 bytes | `whsec_` |

`Scheme::tryFrom()` returns `null` for any other label, and `signatureLength()` returns the raw length above.

### Which one

- **`v1a` wherever the receiver can fetch a public key.** The receiver holds nothing that forges a delivery, so
  nothing secret is handed over, rotated or revoked at its end.
- **`v1` for receivers that cannot be asked to fetch a key**, with **one secret per destination, never shared**,
  so that a leak at one receiver forges nothing at another.

## The keys

All three key types are `final readonly` classes with private constructors. The two
secret types redact themselves in `var_dump()` and dumps through `__debugInfo()` (`[redacted]`), and mark their
secret parameters `#[SensitiveParameter]`, so they do not appear in stack traces.

### `Ed25519SecretKey` (signs `v1a`, implements `SigningKey`)

| Method | Description |
|---|---|
| `generate(): self` | A new key pair from libsodium. |
| `fromString(string $encoded): self` | `whsk_` followed by base64 (standard alphabet, padding required). |
| `fromBytes(string $bytes): self` | Either the **32-byte seed** or the **64-byte libsodium secret key** (seed followed by public key). The specification does not say which bytes are encoded, so both common forms are read. For the 64-byte form, the second half must be the public key the seed derives, or `InvalidWebhookValue` is thrown. Any other length is refused. |
| `toString(): string` | Always the **64-byte** form, `whsk_` + base64, encoded in constant time. |
| `publicKey(): Ed25519PublicKey` | The public half. |
| `scheme(): Scheme` | `Scheme::V1a`. |
| `sign(string $signedContent): Signature` | A detached Ed25519 signature. |

### `Ed25519PublicKey` (verifies `v1a`, implements `VerificationKey`)

| Method | Description |
|---|---|
| `fromString(string $encoded): self` | `whpk_` followed by the 32 key bytes in base64. |
| `fromBytes(string $bytes): self` | Exactly 32 bytes. |
| `toString(): string` | `whpk_` + base64. |
| `equals(self $other): bool` | Constant-time comparison. |
| `scheme(): Scheme` | `Scheme::V1a`. |
| `verifies(string $signedContent, Signature $signature): bool` | `false` for a signature of another scheme; otherwise libsodium's detached verification. |

`$bytes` (the raw public key) is public: it is not a secret.

### `HmacSecret` (signs and verifies `v1`, implements both interfaces)

| Method | Description |
|---|---|
| `generate(): self` | 32 random bytes from `random_bytes()`. |
| `fromString(string $encoded): self` | `whsec_` followed by base64 of **24 to 64 bytes** inclusive (`MIN_BYTES`, `MAX_BYTES`), as the specification requires. |
| `toString(): string` | `whsec_` + base64, encoded in constant time. |
| `scheme(): Scheme` | `Scheme::V1`. |
| `sign(string $signedContent): Signature` | Raw HMAC-SHA256. |
| `verifies(string $signedContent, Signature $signature): bool` | `false` for a signature of another scheme; otherwise recomputes and compares with `hash_equals()`, **in constant time**: a comparison that stops at the first differing byte lets a forger measure, byte by byte, how close a guess is. |
| `verifiesAny(string $signedContent, SignatureList $signatures): bool` | Whether any `v1` signature of the list verifies. The HMAC is computed **once** for the whole list, and each signature compared with `hash_equals()`, in constant time. `Verifier` calls it for each `HmacSecret`, so a header of eight forged signatures costs one HMAC per key, not eight. |

### Key formats at a glance

```text
whsk_<base64 of 64 bytes: seed || public key>      Ed25519 secret key (32-byte seed also accepted on reading)
whpk_<base64 of 32 bytes>                          Ed25519 public key
whsec_<base64 of 24 to 64 bytes>                   HMAC secret
```

All three use standard, padded base64. Generating keys:

```php
$secret = Ed25519SecretKey::generate();
$secret->toString();               // whsk_..., keep it in your secret store
$secret->publicKey()->toString();  // whpk_..., publish it to receivers

HmacSecret::generate()->toString(); // whsec_..., one per destination
```

## Base64: `Base64`

`Base64` holds two strict codecs over the standard, padded alphabet, **chosen by what passes
through them**:

| Methods | Codec | Used for |
|---|---|---|
| `encodeSecret()`, `decodeSecret()` | libsodium's constant-time codec | Secret keys (`whsk_`, `whsec_`): the time an encoding takes says nothing about the bytes. |
| `encodePublic()`, `decodePublic()` | PHP's native codec in strict mode, plus a re-encoding check | Signatures and public keys, which are published anyway; faster on the path every delivery takes. |

Both decoders return `null` (never throw) for the same inputs: missing padding, a character outside the alphabet,
the URL-safe alphabet, non-canonical trailing bits, whitespace.

## Signing and verifying

`Signer::sign(WebhookId $id, WebhookTimestamp $timestamp, string $rawBody, SigningKey $key, SigningKey ...$moreKeys): WebhookHeaders`
signs one attempt with one or more keys; see [sending.md](sending.md).

`Verifier` checks a received delivery against a set of trusted keys:

| Method | Description |
|---|---|
| `verify(WebhookHeaders $headers, string $rawBody, iterable $keys, Tolerance $tolerance, DateTimeImmutable $now): VerifiedWebhook` | Checks the timestamp first, then the signatures; throws `WebhookRejected` with `TimestampOutsideTolerance` or `NoValidSignature`. |
| `signatureVerifies(WebhookHeaders $headers, string $rawBody, iterable $keys): bool` | The signature check alone. |

**The header carries no key id**, so a delivery is accepted when **any** of its signatures verifies under **any**
trusted key **of the same scheme**. A signature of a scheme no trusted key has is ignored, never accepted: a `v1`
signature computed with a public key's bytes as an HMAC secret does not pass as `v1a` (a test pins this).

## Rotation

The signature header is a list, so keys overlap: during a rotation the sender signs with the new and the old
key, and a receiver that trusts only one of them still accepts the delivery. The receiver reloads its keys once
when no cached key verifies ([receiving.md](receiving.md#reload-once-before-refusing)), so a sender's rotation is
picked up without a redeploy.

## Reference vectors

These values are pinned by the test suite; any implementation must reproduce them.

**Ed25519, RFC 8032 section 7.1, test 1** (`Ed25519KeyTest`):

```text
seed (hex)        9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60
public key (hex)  d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a
message           (empty)
signature (hex)   e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b
```

**HMAC-SHA256, the `v1` example published by the Standard Webhooks reference libraries** (`HmacSecretTest`):

```text
secret            whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw
signed content    msg_p5jXN8AQM9LWM0D4loKWxJek.1614265330.{"test": 2432232314}
signature entry   v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=
```

**A `v1a` example computed with this library** from the RFC 8032 seed above and the same message as the `v1`
example. It is deterministic (Ed25519 signatures are), but it is not pinned by a test; use it to check a receiver
written without the library:

```text
secret key        whsk_nWGxne/9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2DXWpgBgrEKt9VL/tPJZAc6DuFy89qmIyWvAhpo9wdRGg==
public key        whpk_11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo=
webhook-id        msg_p5jXN8AQM9LWM0D4loKWxJek
webhook-timestamp 1614265330
body              {"test": 2432232314}
webhook-signature v1a,fldxM4gAKugP6nnt1hdz3sgGfZ6d99nzrMFnZOELIxbzEHoVmAb2ADpkJK7zgPePmPsle0zV9jSeGlHFG2NVAw==
```

This example's timestamp is far in the past: `Verifier::verify()` rejects it for its timestamp unless `$now` is
within the tolerance of `1614265330`. Use `signatureVerifies()` to check the signature alone.

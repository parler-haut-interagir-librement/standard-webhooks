# Changelog

All notable changes to `phil/standard-webhooks` will be documented in this file.

Updates should follow the [Keep a CHANGELOG](https://keepachangelog.com/) principles.

## NEXT - YYYY-MM-DD

### Added

- Headers and timestamps: `WebhookId` with `generate()`, `WebhookTimestamp`, `Tolerance`, `WebhookHeaders`.
  `WebhookTimestamp::fromHeader()` shows a refused header through `InvalidWebhookValue::shown()`, cut to 32 bytes and
  with control, quote, backslash and non-ASCII bytes escaped, never the unverified value whole.
- Signature headers: `Signature`, `SignatureList`.
- Envelope: `Envelope`, `VerifiedWebhook`.
- Keys: the interfaces `SigningKey` and `VerificationKey`; `Ed25519SecretKey`, `Ed25519PublicKey`, `HmacSecret` —
  tested against the RFC 8032 vector (Ed25519) and the Standard Webhooks `v1` vector (HMAC) — and `Keys`, which reads
  a key by its prefix.
- Signing and verifying: `Signer`, `Verifier`, `SignedContent`, `Scheme`, `Base64`. `HmacSecret::verifiesAny()`
  computes the HMAC once for a whole signature list, and `Verifier` uses it for each HMAC key.
- Receiving: `WebhookReceiver`.
- Trusted keys: the port `VerificationKeyProvider` and `FixedKeys`, whose `fromStrings()` keeps the keys out of a
  stack trace.
- De-duplication: the port `ProcessedWebhookRepository`.
- The exceptions under `Exception\`: `WebhookException`, `InvalidWebhookValue`, `WebhookRejected`,
  `RejectionReason`.
- The documentation in `doc/`, one file per topic, indexed by the README.
- `CONTRIBUTING.md`, `SECURITY.md` and `CODE_OF_CONDUCT.md`.

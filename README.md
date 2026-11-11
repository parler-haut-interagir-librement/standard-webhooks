# phil/standard-webhooks

**Sign and verify webhooks that any Standard Webhooks library can send or check.**

phil/standard-webhooks implements the [Standard Webhooks](https://github.com/standard-webhooks/standard-webhooks)
1.0.0 format: the `webhook-id`, `webhook-timestamp` and `webhook-signature` headers, signed with the asymmetric `v1a`
scheme (Ed25519) or the symmetric `v1` scheme (HMAC-SHA256). It parses and writes the three headers strictly, and
signs `{webhook-id}.{webhook-timestamp}.{body}` over the raw body with one or more keys. It does not send HTTP
requests, retry, or store anything.

Verification checks the timestamp first, against a tolerance you set. It then accepts any signature under any
trusted key of the same scheme, ignores unknown versions and caps the list. It reloads the keys once before refusing,
and refuses an envelope that does not name the expected `audience`. The package also reads keys by their prefix,
generates ids, and defines an atomic de-duplication port on `webhook-id`, so a delivery is acknowledged only after
it is processed.

Use it when your application sends webhooks to others or receives them, and you want a format other libraries
already speak. A receiver built on it talks to any Standard Webhooks sender, and its deliveries verify with any
Standard Webhooks library. It is a plain PHP library, usable in any PHP code, that needs only `ext-sodium`. Trusted
keys come through the `VerificationKeyProvider` port, and de-duplication through `ProcessedWebhookRepository`. In a
Symfony application, `phil/standard-webhooks-bundle` does the wiring, the controller argument, the sending and the
storage.

## Status

| Area | Types | State |
|---|---|---|
| Headers and timestamps | `WebhookId`, `WebhookTimestamp`, `Tolerance`, `WebhookHeaders` | Implemented and tested |
| Signature headers | `Signature`, `SignatureList` | Implemented and tested |
| Keys | `Ed25519SecretKey`, `Ed25519PublicKey`, `HmacSecret`, `Keys` | Implemented and tested, against the RFC 8032 vector (Ed25519) and the Standard Webhooks `v1` vector (HMAC) |
| Envelope | `Envelope`, `VerifiedWebhook` | Implemented and tested |
| Signing and verifying | `Signer`, `Verifier`, `SignedContent`, `Scheme`, `Base64` | Implemented and tested |
| Key interfaces | `SigningKey`, `VerificationKey` | Interfaces, implemented by the key types |
| Receiving | `WebhookReceiver` | Implemented and tested |
| Trusted keys | `VerificationKeyProvider`, `FixedKeys` | A port, and its adapter for keys known in advance |
| De-duplication | `ProcessedWebhookRepository` | Port only |

## Documentation

| Document | What it covers |
|---|---|
| [Installation](doc/installation.md) | Requirements, `ext-sodium` included. |
| [Envelope and headers](doc/envelope-and-headers.md) | `webhook-id`, `webhook-timestamp`, `webhook-signature`, the tolerance, the JSON envelope and its `audience`, every rule and its reason. |
| [Signatures](doc/signatures.md) | The signed content, `v1a` and `v1`, key formats (`whsk_`, `whpk_`, `whsec_`), base64, rotation, and the reference vectors. |
| [Receiving](doc/receiving.md) | The verification order, the single reload, failures and reasons, de-duplication, and a complete endpoint that answers `2xx` only after processing. |
| [Sending](doc/sending.md) | What the library provides and what the application provides, the rules of an attempt, and an example. |
| [Extension points](doc/extension-points.md) | `VerificationKeyProvider`, `ProcessedWebhookRepository` and the key interfaces, with example adapters. |
| [Testing](doc/testing.md) | What the suite pins, and how to test your adapters. |
| [Reference](doc/reference.md) | Every public type, with its role. |

## Quick start

```php
use Phil\StandardWebhooks\{Exception\WebhookException, FixedKeys, Tolerance, WebhookHeaders, WebhookReceiver};

$receiver = new WebhookReceiver(FixedKeys::fromStrings(['whpk_…']), Tolerance::ofSeconds(300), 'receiver-1');

try {
    $verified = $receiver->receive(
        WebhookHeaders::fromStrings($headers['webhook-id'] ?? null, $headers['webhook-timestamp'] ?? null, $headers['webhook-signature'] ?? null),
        $rawBody,
        new DateTimeImmutable(),
    );
} catch (WebhookException) {
    // 400 or 401: permanent, never 2xx
}

// Claim $verified->id with your ProcessedWebhookRepository and process $verified->envelope()
// in one transaction, then answer 2xx.
```

## Requirements

PHP 8.5 or later with `ext-sodium`. Nothing else.

## Contributing

Contributions are welcome on the PHIL monorepo: read the [contribution guide](CONTRIBUTING.md) and the
[code of conduct](CODE_OF_CONDUCT.md). Report a vulnerability privately, as the [security policy](SECURITY.md)
explains, never in a public issue.

## License

MIT, © Philippe Gamache, doing business as Le Gnome Archiviste; a Parler Haut Interagir Librement project. See [LICENSE](LICENSE).

# Sending

## What exists and what the application provides

| Piece | State |
|---|---|
| Building the three headers for one attempt, signed with one or more keys: `Signer` | Implemented and tested |
| Key types and key generation: `Ed25519SecretKey`, `HmacSecret` | Implemented and tested |
| The envelope a receiver expects (`type`, `timestamp`, `data`, `audience`) | Read by `Envelope`; the sender writes the JSON itself |
| Generating the `webhook-id`: `WebhookId::generate()` | Implemented and tested |
| Reading a key by its prefix: `Keys::signing()` | Implemented and tested |
| Custody of the secret keys, and publishing the public keys to receivers | Application |
| The outbound HTTP client, the timeout, the retry schedule, the dead-letter handling | Application |
| A transport for `symfony/webhook` | Not yet in the skeleton |

## `Signer`

```php
public function sign(WebhookId $id, WebhookTimestamp $timestamp, string $rawBody, SigningKey $key, SigningKey ...$moreKeys): WebhookHeaders
```

It computes the signed content `{webhook-id}.{webhook-timestamp}.{body}`, signs it with every key given, and
returns the `WebhookHeaders` to send (`toArray()` gives the three header lines). Several keys yield several
signatures in one header, which is how a rotation overlaps: sign with the new and the old key until every
receiver trusts the new one. The list is capped at eight signatures (`SignatureList::MAX_ENTRIES`).

## The rules of an attempt

- **Every attempt of a delivery reuses its `webhook-id`**, so the receiver's de-duplication absorbs retries.
  Generate the id once, when the delivery is created, and store it with the delivery.
- **Only the timestamp and the signatures are recomputed** on each attempt: a retry signed with the original
  timestamp would fall outside the receiver's tolerance.
- **Sign the exact bytes you send.** Encode the body once, sign that string, and send that string.
- The id must not contain a full stop, a space or non-ASCII characters, and is at most 255 bytes
  (`WebhookId::fromString()` refuses anything else).

## Example

```php
use Phil\StandardWebhooks\Signer;
use Phil\StandardWebhooks\{Ed25519SecretKey, WebhookId, WebhookTimestamp};

// When the delivery is created, once:
$delivery = new Delivery(
    id: WebhookId::generate()->value,
    body: json_encode([
        'type' => 'invoice.paid',
        'timestamp' => $clock->now()->format(DATE_RFC3339),
        'data' => ['id' => 'abc'],
        'audience' => 'receiver-1',
    ], JSON_THROW_ON_ERROR),
);

// On each attempt:
$headers = new Signer()->sign(
    WebhookId::fromString($delivery->id),
    WebhookTimestamp::fromDateTime($clock->now()),
    $delivery->body,
    Ed25519SecretKey::fromString($secrets->get('WEBHOOK_SIGNING_KEY')),
);

$response = $httpClient->request('POST', $endpoint, [
    'headers' => $headers->toArray() + ['Content-Type' => 'application/json'],
    'body' => $delivery->body,
    'timeout' => 10,
]);
```

## Interpreting the receiver's answer

The library does not send, so it does not retry; in a Symfony application, `phil/standard-webhooks-bundle`'s
`WebhookSender` sends one attempt. The behaviour receivers built on this library are documented to
expect:

- `2xx` means processed (the receiver answers only after committing);
- any `4xx` is permanent: a malformed or refused delivery fails the same way on retry; stop and alert;
- a timeout, a network error or a `5xx` is worth retrying, with the same `webhook-id`, on your schedule;
- your retry schedule must fit within the receiver's de-duplication window.

## Choosing and publishing keys

Prefer `v1a` and publish the `whpk_` public key wherever receivers can fetch it; reserve `v1` for receivers that
cannot, with one `whsec_` secret per destination (see [signatures.md](signatures.md#which-one)). Standard Webhooks
defines no distribution format for public keys: the document or endpoint that publishes them is the
application's. When one key signs for many receivers, put the receiver's identifier in the envelope's `audience`
so that a delivery for one receiver cannot be replayed at another within the tolerance.

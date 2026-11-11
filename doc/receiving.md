# Receiving

**State:** `WebhookReceiver` and `Verifier` are implemented and tested; `VerificationKeyProvider` and
`ProcessedWebhookRepository` are ports, with `FixedKeys` for keys known in advance. The library has no HTTP layer:
the application calls `WebhookReceiver` from its own endpoint — or lets `phil/standard-webhooks-bundle` do it in a
Symfony application.

## The order of operations

A receiver does exactly this, in this order:

1. **Parse the headers** with `WebhookHeaders::fromStrings()`. A missing header, a malformed id or timestamp,
   or more than eight signature entries throws `InvalidWebhookValue`: the delivery is malformed.
2. **Check the timestamp** against the tolerance, in both directions. It costs nothing, and **a genuine signature
   does not rescue a stale delivery**: that is exactly the replay the tolerance exists to stop.
3. **Check the signatures** against the cached trusted keys, over the **raw body as received**.
4. **If no signature verifies, reload the keys once** and check again.
5. **If an audience is configured**, decode the envelope and require it to name this receiver.
6. **Claim the `webhook-id` and process the event in one transaction**, then answer `2xx` **only after it
   commits**.

Steps 2 to 5 are `WebhookReceiver::receive()`. Step 6 is the application's, with the
`ProcessedWebhookRepository` port.

## `WebhookReceiver`

```php
final readonly class WebhookReceiver
{
    public function __construct(
        VerificationKeyProvider $keys,
        Tolerance $tolerance,
        ?string $audience = null,       // '' throws InvalidWebhookValue; null accepts any audience
        Verifier $verifier = new Verifier(),
    );

    /** @throws WebhookException */
    public function receive(WebhookHeaders $headers, string $rawBody, DateTimeImmutable $now): VerifiedWebhook;
}
```

Pass `$now` from your clock, so that tests can control it.

### Reload once before refusing

When verification under `$keys->keys()` fails with `NoValidSignature`, the receiver calls `$keys->refresh()`
**exactly once** and verifies again under the reloaded keys. A sender's key rotation is then picked up without a
redeploy, and **a forged delivery costs at most one reload**. The provider bounds how often a reload actually
fetches anything ([extension-points.md](extension-points.md#verificationkeyprovider)).

- A stale timestamp never triggers a reload: new keys do not make it fresher.
- A delivery whose signature header holds no readable entry (all unknown versions, for instance) fails as
  `NoValidSignature`, and therefore also triggers the one reload.
- An audience mismatch is checked after a successful verification and never triggers a reload.

### Audience

With an expected audience, `receive()` calls `$verified->envelope()->assertAddressedTo($audience)` after the
signature verified. This decodes the body, so a body that is not a valid envelope throws `InvalidWebhookValue` at
this point. Without an audience, the body is not decoded by `receive()`.

## Failures

Everything the library throws implements `Phil\StandardWebhooks\Exception\WebhookException`, so one
`catch` covers it at the HTTP boundary. There are two kinds, **both permanent**: the same bytes fail the same
way, so a sender must not retry them.

| Exception | Meaning | Typical answer |
|---|---|---|
| `InvalidWebhookValue` (`InvalidArgumentException`) | Malformed: a missing or malformed header, too many signature entries, a body that is not a valid envelope. | `400` |
| `WebhookRejected` (`RuntimeException`) | Well-formed but refused. Its public `RejectionReason $reason` says why. Nothing in it may be processed, and the response is never `2xx`. | `400` or `401`, as your contract says |

| `RejectionReason` | Value | When |
|---|---|---|
| `TimestampOutsideTolerance` | `timestamp_outside_tolerance` | The timestamp is further from the receiver's clock than the tolerance allows. Message: `The webhook timestamp is 301 seconds from the receiver clock; the tolerance is 300.` |
| `NoValidSignature` | `no_valid_signature` | No signature verifies under any trusted key, after the reload. |
| `AudienceMismatch` | `audience_mismatch` | The envelope names another audience, or none. |

The library does not decide the status code: map each reason to your response and to your metrics. The backed
values are stable strings suitable for a metric label.

## De-duplication on `webhook-id`

Every attempt of a delivery carries the same id, so a retried delivery must be **acknowledged without being
processed twice**. The rules, and why:

- **Claim the id in the same transaction as the processing, and answer `2xx` only after it commits.** An id
  recorded in a transaction of its own, before processing, turns a crash into a lost event; an acknowledgement sent
  before processing turns it into one the sender will never retry.
- **The claim is atomic.** Two attempts of the same delivery can arrive together (a sender retries after a timeout
  while the first attempt is still being processed). A separate "has it been seen?" read lets both through, so the
  port offers none. `claim()` is an insert-if-absent on a unique index on the id: the second attempt waits on the
  first transaction's row and gets `false` once it commits, or claims the id itself if the first one rolled back.
- **The retention window must outlast both the timestamp tolerance and the sender's whole retry schedule.** A
  shorter window processes a retried delivery twice.

## A complete endpoint

```php
use Phil\StandardWebhooks\WebhookReceiver;
use Phil\StandardWebhooks\Exception\{InvalidWebhookValue, WebhookRejected};
use Phil\StandardWebhooks\ProcessedWebhookRepository;
use Phil\StandardWebhooks\WebhookHeaders;

#[Route('/webhooks', methods: ['POST'])]
public function __invoke(Request $request): Response
{
    try {
        $headers = WebhookHeaders::fromStrings(
            $request->headers->get(WebhookHeaders::ID),
            $request->headers->get(WebhookHeaders::TIMESTAMP),
            $request->headers->get(WebhookHeaders::SIGNATURE),
        );
        $verified = $this->receiver->receive($headers, $request->getContent(), $this->clock->now());
        $envelope = $verified->envelope();
    } catch (InvalidWebhookValue) {
        return new Response(null, 400);
    } catch (WebhookRejected $rejected) {
        $this->metrics->increment('webhook_rejected', ['reason' => $rejected->reason->value]);

        return new Response(null, 401);
    }

    if (!$this->handlers->supports($envelope->type)) {
        return new Response(null, 422);   // a type this receiver does not process: never 2xx
    }

    $this->connection->transactional(function () use ($verified, $envelope): void {
        if (!$this->processed->claim($verified->id, $this->clock->now())) {
            return;                        // already processed: acknowledge without processing again
        }

        $this->handlers->handle($envelope->type, $envelope->data);
    });

    return new Response(null, 204);        // only after the transaction committed
}
```

`$request->getContent()` is the raw body: never pass a decoded and re-encoded body to `receive()`.

## Using `Verifier` directly

`WebhookReceiver` is a thin layer over `Verifier`. Call `Verifier::verify()` yourself when your keys are a fixed
list (for example a single `HmacSecret` from configuration) and you need no reload:

```php
$verified = new Verifier()->verify($headers, $rawBody, [HmacSecret::fromString($secret)], Tolerance::ofSeconds(300), $now);
```

## Not in the library

A controller, a request parser, a routing file, a Messenger handler, and a storage for the processed ids.
`phil/standard-webhooks-bundle` brings the controller argument and a Doctrine table to Symfony applications.

# Extension points

The library defines two ports and two key interfaces. Of the adapters, it ships only `FixedKeys`, a
`VerificationKeyProvider` for keys known in advance; `phil/standard-webhooks-bundle` adds a Doctrine
`ProcessedWebhookRepository` for Symfony applications.

| Interface | Kind | Implemented by |
|---|---|---|
| `Phil\StandardWebhooks\VerificationKeyProvider` | Port | The application |
| `Phil\StandardWebhooks\ProcessedWebhookRepository` | Port | The application |
| `Phil\StandardWebhooks\VerificationKey` | Key interface | `Ed25519PublicKey`, `HmacSecret` |
| `Phil\StandardWebhooks\SigningKey` | Key interface | `Ed25519SecretKey`, `HmacSecret` |

## `VerificationKeyProvider`

Where a receiver's trusted keys come from: a published key document, a configured secret, or both. The adapter
belongs to the application, with its cache lifetime and its source.

| Method | Contract |
|---|---|
| `keys(): array` | `list<VerificationKey>`, the cached keys. Called on every delivery: it must not fetch. |
| `refresh(): array` | `list<VerificationKey>`. Reloads the keys from their source, **or returns the cached ones if the adapter's reload budget is spent**. `WebhookReceiver` calls it at most once per delivery, when no cached key verifies. |

### Invariants

- **The adapter bounds how often it actually reloads**, so that a stream of forged deliveries cannot turn into a
  stream of fetches: a minimum interval between fetches, a rate limiter, or both.
- A failed fetch returns the cached keys rather than an empty list or an exception: a source outage must not turn
  every delivery into a refusal for longer than the keys would have been cached anyway.
- Keys of both schemes may be mixed in one list; each key verifies only signatures of its own scheme.

### Example adapter

A provider for a published key document of the form `{"keys": [{"key": "whpk_..."}]}`, cached ten minutes, with
at most one forced reload per minute:

```php
use Phil\StandardWebhooks\VerificationKeyProvider;
use Phil\StandardWebhooks\Ed25519PublicKey;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\{CacheInterface, ItemInterface};
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PublishedKeyProvider implements VerificationKeyProvider
{
    private ?DateTimeImmutable $lastRefresh = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly string $url,
    ) {}

    public function keys(): array
    {
        return $this->cache->get('webhook_keys', function (ItemInterface $item): array {
            $item->expiresAfter(600);

            return $this->fetch();
        });
    }

    public function refresh(): array
    {
        $now = $this->clock->now();

        if (null !== $this->lastRefresh && $this->lastRefresh > $now->modify('-60 seconds')) {
            return $this->keys();                        // reload budget spent
        }

        $this->lastRefresh = $now;

        try {
            $keys = $this->fetch();
        } catch (Throwable) {
            return $this->keys();                        // source down: keep what we have
        }

        $this->cache->delete('webhook_keys');
        $this->cache->get('webhook_keys', function (ItemInterface $item) use ($keys): array {
            $item->expiresAfter(600);

            return $keys;
        });

        return $keys;
    }

    /** @return list<Ed25519PublicKey> */
    private function fetch(): array
    {
        $document = $this->http->request('GET', $this->url)->toArray();

        return array_map(static fn (array $entry): Ed25519PublicKey => Ed25519PublicKey::fromString($entry['key']), $document['keys']);
    }
}
```

In a multi-process deployment, keep the reload budget in a shared store (a cache item or a rate limiter) rather
than in a property, which lives only as long as the process.

Keys known in advance — a configured `v1` secret, public keys written down — have nothing to reload: `FixedKeys`
gives them back on `refresh()`.

```php
$keys = FixedKeys::fromStrings([$secret, 'whpk_…']);   // or new FixedKeys(HmacSecret::fromString($secret))
```

## `ProcessedWebhookRepository`

The `webhook-id`s a receiver has already processed, so that a retried delivery is acknowledged without being
processed twice.

| Method | Contract |
|---|---|
| `claim(WebhookId $id, DateTimeImmutable $processedAt): bool` | Records the id as processed, **atomically, within the caller's processing transaction**. Returns `true` if this attempt claimed the id and must process the delivery; `false` if the id is already processed, and the delivery is acknowledged without being processed again. |

### Invariants

- **Insert-if-absent on a unique index on the id.** Never a read followed by a write: two attempts of one delivery
  can arrive together, and a separate read lets both through. The port deliberately offers no "has it been seen?"
  method.
- **Same connection and transaction as the processing.** The second attempt waits on the first transaction's row
  and gets `false` once it commits, or claims the id itself if the first one rolled back.
- **Retention outlasts the timestamp tolerance and the sender's whole retry schedule.** A purge may delete ids
  older than that window, never younger.

### Example adapter (Doctrine DBAL, PostgreSQL)

```php
use Doctrine\DBAL\Connection;
use Phil\StandardWebhooks\ProcessedWebhookRepository;
use Phil\StandardWebhooks\WebhookId;

// CREATE TABLE processed_webhook (id varchar(255) PRIMARY KEY, processed_at timestamptz NOT NULL);
final readonly class DbalProcessedWebhookRepository implements ProcessedWebhookRepository
{
    public function __construct(private Connection $connection) {}

    public function claim(WebhookId $id, DateTimeImmutable $processedAt): bool
    {
        return 1 === $this->connection->executeStatement(
            'INSERT INTO processed_webhook (id, processed_at) VALUES (?, ?) ON CONFLICT (id) DO NOTHING',
            [$id->value, $processedAt->format(DATE_ATOM)],
        );
    }
}
```

On MySQL, `INSERT IGNORE` (or `INSERT ... ON DUPLICATE KEY UPDATE id = id`, checking the affected rows) plays the
same role.

## `VerificationKey` and `SigningKey`

You rarely implement these: the three key classes cover both schemes. They exist so that `Verifier` and `Signer`
stay independent of the key types, and so that an application can wrap a key kept in an HSM or a KMS.

| Interface | Methods | Contract |
|---|---|---|
| `SigningKey` | `scheme(): Scheme`, `sign(string $signedContent): Signature` | Returns a signature of its own scheme over exactly the bytes given. |
| `VerificationKey` | `scheme(): Scheme`, `verifies(string $signedContent, Signature $signature): bool` | Verifies only signatures of its own scheme; any other is `false`. The library's implementations also never throw for a bad signature and compare in constant time; a custom implementation should do the same. |

`Verifier` also checks that the key's scheme equals the signature's scheme before calling `verifies()`, but an
implementation must not rely on it.

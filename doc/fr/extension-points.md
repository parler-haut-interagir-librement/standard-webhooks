<!-- phil-dev source-sha256=073c6be5605683461b33cc41cf66f3c2825f553a651645fdeb9ef7b631019fea -->
# Points d'extension

La bibliothèque définit deux ports et deux interfaces de clés. Parmi les adaptateurs, elle ne fournit que
`FixedKeys`, un `VerificationKeyProvider` pour des clés connues d'avance ; `phil/standard-webhooks-bundle` ajoute un
`ProcessedWebhookRepository` Doctrine pour les applications Symfony.

| Interface | Genre | Implémentée par |
|---|---|---|
| `Phil\StandardWebhooks\VerificationKeyProvider` | Port | L'application |
| `Phil\StandardWebhooks\ProcessedWebhookRepository` | Port | L'application |
| `Phil\StandardWebhooks\VerificationKey` | Interface de clé | `Ed25519PublicKey`, `HmacSecret` |
| `Phil\StandardWebhooks\SigningKey` | Interface de clé | `Ed25519SecretKey`, `HmacSecret` |

## `VerificationKeyProvider`

D'où viennent les clés de confiance d'un récepteur : un document de clés publié, un secret configuré, ou les deux.
L'adaptateur appartient à l'application, avec sa durée de cache et sa source.

| Méthode | Contrat |
|---|---|
| `keys(): array` | `list<VerificationKey>`, les clés en cache. Appelée à chaque livraison : elle ne doit rien aller chercher. |
| `refresh(): array` | `list<VerificationKey>`. Recharge les clés depuis leur source, **ou retourne celles en cache si le budget de rechargement de l'adaptateur est épuisé**. `WebhookReceiver` l'appelle au plus une fois par livraison, quand aucune clé en cache ne vérifie. |

### Invariants

- **L'adaptateur borne la fréquence à laquelle il recharge réellement**, pour qu'un flot de livraisons falsifiées ne
  devienne pas un flot de récupérations : un intervalle minimal entre les récupérations, un limiteur de débit, ou les
  deux.
- Une récupération qui échoue retourne les clés en cache plutôt qu'une liste vide ou une exception : une panne de la
  source ne doit pas changer chaque livraison en refus plus longtemps que les clés seraient restées en cache de toute
  façon.
- Des clés des deux schémas peuvent se mêler dans une même liste ; chaque clé ne vérifie que les signatures de son
  propre schéma.

### Exemple d'adaptateur

Un fournisseur pour un document de clés publié de la forme `{"keys": [{"key": "whpk_..."}]}`, mis en cache dix
minutes, avec au plus un rechargement forcé par minute :

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

Dans un déploiement à plusieurs processus, gardez le budget de rechargement dans un stockage partagé (un élément de
cache ou un limiteur de débit) plutôt que dans une propriété, qui ne vit que le temps du processus.

Des clés connues d'avance — un secret `v1` configuré, des clés publiques écrites en dur — n'ont rien à recharger :
`FixedKeys` les redonne à `refresh()`.

```php
$keys = FixedKeys::fromStrings([$secret, 'whpk_…']);   // or new FixedKeys(HmacSecret::fromString($secret))
```

## `ProcessedWebhookRepository`

Les `webhook-id` qu'un récepteur a déjà traités, pour qu'une livraison réessayée soit confirmée sans être traitée
deux fois.

| Méthode | Contrat |
|---|---|
| `claim(WebhookId $id, DateTimeImmutable $processedAt): bool` | Enregistre l'identifiant comme traité, **de façon atomique, dans la transaction de traitement de l'appelant**. Retourne `true` si cette tentative a revendiqué l'identifiant et doit traiter la livraison ; `false` si l'identifiant est déjà traité, et la livraison est confirmée sans être traitée de nouveau. |

### Invariants

- **Une insertion si absent sur un index unique de l'identifiant.** Jamais une lecture suivie d'une écriture : deux
  tentatives d'une même livraison peuvent arriver ensemble, et une lecture séparée les laisse passer toutes les deux.
  Le port n'offre volontairement aucune méthode « l'a-t-on déjà vu ? ».
- **La même connexion et la même transaction que le traitement.** La seconde tentative attend la ligne de la première
  transaction et reçoit `false` une fois celle-ci validée, ou revendique elle-même l'identifiant si la première a fait
  un retour arrière.
- **La rétention dure plus longtemps que la tolérance d'horodatage et que tout le calendrier de nouvelles tentatives
  de l'expéditeur.** Une purge peut supprimer les identifiants plus vieux que cette fenêtre, jamais les plus récents.

### Exemple d'adaptateur (Doctrine DBAL, PostgreSQL)

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

Sur MySQL, `INSERT IGNORE` (ou `INSERT ... ON DUPLICATE KEY UPDATE id = id`, en vérifiant les lignes touchées) joue
le même rôle.

## `VerificationKey` et `SigningKey`

Vous les implémentez rarement : les trois classes de clés couvrent les deux schémas. Elles existent pour que
`Verifier` et `Signer` restent indépendants des types de clés, et pour qu'une application puisse envelopper une clé
gardée dans un HSM ou un KMS.

| Interface | Méthodes | Contrat |
|---|---|---|
| `SigningKey` | `scheme(): Scheme`, `sign(string $signedContent): Signature` | Retourne une signature de son propre schéma sur exactement les octets donnés. |
| `VerificationKey` | `scheme(): Scheme`, `verifies(string $signedContent, Signature $signature): bool` | Ne vérifie que les signatures de son propre schéma ; toute autre donne `false`. Les implémentations de la bibliothèque ne lancent jamais d'exception pour une mauvaise signature et comparent à temps constant ; une implémentation sur mesure devrait faire de même. |

`Verifier` vérifie aussi que le schéma de la clé est égal à celui de la signature avant d'appeler `verifies()`, mais
une implémentation ne doit pas compter là-dessus.

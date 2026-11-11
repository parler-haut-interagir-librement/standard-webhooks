<!-- phil-dev source-sha256=f96c5a37d337171ca9e2ad5de6d02ebe7f8ba78c1b23d92b8ab9d11cfcb65408 -->
# Réception

**État :** `WebhookReceiver` et `Verifier` sont implémentés et testés ; `VerificationKeyProvider` et
`ProcessedWebhookRepository` sont des ports, avec `FixedKeys` pour des clés connues d'avance. La bibliothèque n'a
pas de couche HTTP : l'application appelle `WebhookReceiver` depuis son propre point de terminaison — ou laisse
`phil/standard-webhooks-bundle` le faire dans une application Symfony.

## L'ordre des opérations

Un récepteur fait exactement ceci, dans cet ordre :

1. **Analyser les en-têtes** avec `WebhookHeaders::fromStrings()`. Un en-tête absent, un identifiant ou un
   horodatage mal formé, ou plus de huit entrées de signature lance `InvalidWebhookValue` : la livraison est mal
   formée.
2. **Vérifier l'horodatage** selon la tolérance, dans les deux sens. Cela ne coûte rien, et **une signature
   authentique ne sauve pas une livraison périmée** : c'est exactement le rejeu que la tolérance existe pour arrêter.
3. **Vérifier les signatures** contre les clés de confiance en cache, sur le **corps brut tel que reçu**.
4. **Si aucune signature ne se vérifie, recharger les clés une fois** et vérifier de nouveau.
5. **Si une audience est configurée**, décoder l'enveloppe et exiger qu'elle nomme ce récepteur.
6. **Revendiquer le `webhook-id` et traiter l'événement dans une même transaction**, puis répondre `2xx`
   **seulement après sa validation**.

Les étapes 2 à 5 sont `WebhookReceiver::receive()`. L'étape 6 revient à l'application, avec le port
`ProcessedWebhookRepository`.

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

Passez `$now` depuis votre horloge, pour que les tests puissent le contrôler.

### Recharger une fois avant de refuser

Quand la vérification sous `$keys->keys()` échoue avec `NoValidSignature`, le récepteur appelle `$keys->refresh()`
**exactement une fois** et vérifie de nouveau sous les clés rechargées. La rotation des clés d'un expéditeur est
alors prise en compte sans redéploiement, et **une livraison falsifiée coûte au plus un rechargement**. Le
fournisseur borne la fréquence à laquelle un rechargement va réellement chercher quelque chose
([extension-points.md](extension-points.md#verificationkeyprovider)).

- Un horodatage périmé ne déclenche jamais de rechargement : de nouvelles clés ne le rendent pas plus frais.
- Une livraison dont l'en-tête de signature ne contient aucune entrée lisible (toutes de versions inconnues, par
  exemple) échoue en `NoValidSignature`, et déclenche donc aussi le rechargement unique.
- Une audience qui ne correspond pas est vérifiée après une vérification réussie et ne déclenche jamais de
  rechargement.

### Audience

Avec une audience attendue, `receive()` appelle `$verified->envelope()->assertAddressedTo($audience)` une fois la
signature vérifiée. Cela décode le corps : un corps qui n'est pas une enveloppe valide lance donc
`InvalidWebhookValue` à ce moment. Sans audience, `receive()` ne décode pas le corps.

## Échecs

Tout ce que lance la bibliothèque implémente `Phil\StandardWebhooks\Exception\WebhookException` : un seul `catch` le
couvre à la frontière HTTP. Il y en a deux genres, **tous deux permanents** : les mêmes octets échouent de la même
façon, et un expéditeur ne doit donc pas réessayer.

| Exception | Sens | Réponse typique |
|---|---|---|
| `InvalidWebhookValue` (`InvalidArgumentException`) | Mal formée : un en-tête absent ou mal formé, trop d'entrées de signature, un corps qui n'est pas une enveloppe valide. | `400` |
| `WebhookRejected` (`RuntimeException`) | Bien formée mais refusée. Sa propriété publique `RejectionReason $reason` dit pourquoi. Rien en elle ne peut être traité, et la réponse n'est jamais `2xx`. | `400` ou `401`, selon votre contrat |

| `RejectionReason` | Valeur | Quand |
|---|---|---|
| `TimestampOutsideTolerance` | `timestamp_outside_tolerance` | L'horodatage est plus loin de l'horloge du récepteur que ne le permet la tolérance. Message : `The webhook timestamp is 301 seconds from the receiver clock; the tolerance is 300.` |
| `NoValidSignature` | `no_valid_signature` | Aucune signature ne se vérifie sous aucune clé de confiance, après le rechargement. |
| `AudienceMismatch` | `audience_mismatch` | L'enveloppe nomme une autre audience, ou aucune. |

La bibliothèque ne décide pas du code d'état : associez chaque raison à votre réponse et à vos métriques. Les valeurs
de l'enum sont des chaînes stables qui conviennent comme étiquette de métrique.

## Déduplication sur `webhook-id`

Chaque tentative d'une livraison porte le même identifiant : une livraison réessayée doit donc être **confirmée sans
être traitée deux fois**. Les règles, et pourquoi :

- **Revendiquez l'identifiant dans la même transaction que le traitement, et ne répondez `2xx` qu'après sa
  validation.** Un identifiant enregistré dans une transaction à lui, avant le traitement, transforme une panne en
  événement perdu ; une confirmation envoyée avant le traitement en fait un événement que l'expéditeur ne réessaiera
  jamais.
- **La revendication est atomique.** Deux tentatives de la même livraison peuvent arriver ensemble (un expéditeur
  réessaie après un délai dépassé pendant que la première tentative est encore en traitement). Une lecture séparée
  « l'a-t-on déjà vu ? » les laisse passer toutes les deux : le port n'en offre donc aucune. `claim()` est une
  insertion si absent sur un index unique de l'identifiant : la seconde tentative attend la ligne de la première
  transaction et reçoit `false` une fois celle-ci validée, ou revendique elle-même l'identifiant si la première a
  fait un retour arrière.
- **La fenêtre de rétention doit durer plus longtemps que la tolérance d'horodatage et que tout le calendrier de
  nouvelles tentatives de l'expéditeur.** Une fenêtre plus courte traite deux fois une livraison réessayée.

## Un point de terminaison complet

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

`$request->getContent()` est le corps brut : ne passez jamais à `receive()` un corps décodé puis réencodé.

## Utiliser `Verifier` directement

`WebhookReceiver` est une mince couche sur `Verifier`. Appelez vous-même `Verifier::verify()` quand vos clés sont une
liste fixe (par exemple un seul `HmacSecret` tiré de la configuration) et que vous n'avez besoin d'aucun
rechargement :

```php
$verified = new Verifier()->verify($headers, $rawBody, [HmacSecret::fromString($secret)], Tolerance::ofSeconds(300), $now);
```

## Hors de la bibliothèque

Un contrôleur, un analyseur de requêtes, un fichier de routage, un handler de Messenger, et un stockage des
identifiants traités. `phil/standard-webhooks-bundle` apporte l'argument de contrôleur et une table Doctrine aux
applications Symfony.

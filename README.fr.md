<!-- phil-dev source-sha256=6480ca973d02810f7914c9cbf2f37137e2a18a7518b0b76a8d686ed9a9f94869 -->
# phil/standard-webhooks

**Signez et vérifiez des webhooks que n'importe quelle bibliothèque Standard Webhooks peut envoyer ou vérifier.**

phil/standard-webhooks implémente le format [Standard Webhooks](https://github.com/standard-webhooks/standard-webhooks)
1.0.0 : les en-têtes `webhook-id`, `webhook-timestamp` et `webhook-signature`, signés avec le schéma asymétrique
`v1a` (Ed25519) ou le schéma symétrique `v1` (HMAC-SHA256). Il analyse et écrit strictement les trois en-têtes, et
signe `{webhook-id}.{webhook-timestamp}.{body}` sur le corps brut avec une ou plusieurs clés. Il n'envoie pas de
requêtes HTTP, ne fait pas de nouvelles tentatives et ne stocke rien.

La vérification contrôle d'abord l'horodatage, selon une tolérance que vous fixez. Elle accepte ensuite toute
signature sous toute clé de confiance du même schéma, ignore les versions inconnues et plafonne la liste. Elle
recharge les clés une fois avant de refuser, et refuse une enveloppe qui ne nomme pas l'`audience` attendue. Le
paquet lit aussi les clés par leur préfixe, génère des identifiants, et définit un port de déduplication atomique sur
`webhook-id` : une livraison n'est confirmée qu'après son traitement.

Utilisez-le quand votre application envoie des webhooks à d'autres ou en reçoit, et que vous voulez un format que
d'autres bibliothèques parlent déjà. Un récepteur construit sur lui dialogue avec n'importe quel expéditeur Standard
Webhooks, et ses livraisons se vérifient avec n'importe quelle bibliothèque Standard Webhooks. C'est une bibliothèque
PHP simple, utilisable dans n'importe quel code PHP, qui n'a besoin que de `ext-sodium`. Les clés de confiance
arrivent par le port `VerificationKeyProvider`, et la déduplication par `ProcessedWebhookRepository`. Dans une
application Symfony, `phil/standard-webhooks-bundle` s'occupe du câblage, de l'argument de contrôleur, de l'envoi et
du stockage.

## État

| Domaine | Types | État |
|---|---|---|
| En-têtes et horodatages | `WebhookId`, `WebhookTimestamp`, `Tolerance`, `WebhookHeaders` | Implémenté et testé |
| En-têtes de signature | `Signature`, `SignatureList` | Implémenté et testé |
| Clés | `Ed25519SecretKey`, `Ed25519PublicKey`, `HmacSecret`, `Keys` | Implémenté et testé, contre le vecteur de la RFC 8032 (Ed25519) et le vecteur `v1` de Standard Webhooks (HMAC) |
| Enveloppe | `Envelope`, `VerifiedWebhook` | Implémenté et testé |
| Signature et vérification | `Signer`, `Verifier`, `SignedContent`, `Scheme`, `Base64` | Implémenté et testé |
| Interfaces de clés | `SigningKey`, `VerificationKey` | Interfaces, implémentées par les types de clés |
| Réception | `WebhookReceiver` | Implémenté et testé |
| Clés de confiance | `VerificationKeyProvider`, `FixedKeys` | Un port, et son adaptateur pour des clés connues d'avance |
| Déduplication | `ProcessedWebhookRepository` | Port seulement |

## Documentation

| Document | Ce qu'il couvre |
|---|---|
| [Installation](doc/fr/installation.md) | Prérequis, `ext-sodium` compris. |
| [Enveloppe et en-têtes](doc/fr/envelope-and-headers.md) | `webhook-id`, `webhook-timestamp`, `webhook-signature`, la tolérance, l'enveloppe JSON et son `audience`, chaque règle et sa raison. |
| [Signatures](doc/fr/signatures.md) | Le contenu signé, `v1a` et `v1`, les formats de clés (`whsk_`, `whpk_`, `whsec_`), le base64, la rotation, et les vecteurs de référence. |
| [Réception](doc/fr/receiving.md) | L'ordre de vérification, le rechargement unique, les échecs et leurs raisons, la déduplication, et un point de terminaison complet qui ne répond `2xx` qu'après le traitement. |
| [Envoi](doc/fr/sending.md) | Ce que fournit la bibliothèque et ce que fournit l'application, les règles d'une tentative, et un exemple. |
| [Points d'extension](doc/fr/extension-points.md) | `VerificationKeyProvider`, `ProcessedWebhookRepository` et les interfaces de clés, avec des exemples d'adaptateurs. |
| [Tests](doc/fr/testing.md) | Ce que la suite fige, et comment tester vos adaptateurs. |
| [Référence](doc/fr/reference.md) | Chaque type public, avec son rôle. |

## Démarrage rapide

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

## Prérequis

PHP 8.5 ou plus récent avec `ext-sodium`. Rien d'autre.

## Contribuer

Les contributions sont les bienvenues sur le monodépôt PHIL : lisez le [guide de contribution](CONTRIBUTING.md) et le
[code de conduite](CODE_OF_CONDUCT.md). Signalez une vulnérabilité en privé, comme l'explique la
[politique de sécurité](SECURITY.md), jamais dans un ticket public.

## Licence

MIT, © Philippe Gamache, doing business as Le Gnome Archiviste; a Parler Haut Interagir Librement project. Voir
[LICENSE](LICENSE).

<!-- phil-dev source-sha256=e25537fab252a74a01261159e0a00996849ba745f5314ab06e80cc223d65da31 -->
# Envoi

## Ce qui existe et ce que fournit l'application

| Pièce | État |
|---|---|
| Construire les trois en-têtes d'une tentative, signés avec une ou plusieurs clés : `Signer` | Implémenté et testé |
| Les types de clés et la génération de clés : `Ed25519SecretKey`, `HmacSecret` | Implémenté et testé |
| L'enveloppe qu'attend un récepteur (`type`, `timestamp`, `data`, `audience`) | Lue par `Envelope` ; l'expéditeur écrit lui-même le JSON |
| Générer le `webhook-id` : `WebhookId::generate()` | Implémenté et testé |
| Lire une clé par son préfixe : `Keys::signing()` | Implémenté et testé |
| La garde des clés secrètes, et la publication des clés publiques aux récepteurs | Application |
| Le client HTTP sortant, le délai d'attente, le calendrier des nouvelles tentatives, le traitement des lettres mortes | Application |
| Un transport pour `symfony/webhook` | Pas encore dans le squelette |

## `Signer`

```php
public function sign(WebhookId $id, WebhookTimestamp $timestamp, string $rawBody, SigningKey $key, SigningKey ...$moreKeys): WebhookHeaders
```

Il calcule le contenu signé `{webhook-id}.{webhook-timestamp}.{body}`, le signe avec chaque clé donnée, et retourne
les `WebhookHeaders` à envoyer (`toArray()` donne les trois lignes d'en-tête). Plusieurs clés donnent plusieurs
signatures dans un même en-tête : c'est ainsi qu'une rotation se chevauche. Signez avec la nouvelle et l'ancienne clé
jusqu'à ce que chaque récepteur fasse confiance à la nouvelle. La liste est plafonnée à huit signatures
(`SignatureList::MAX_ENTRIES`).

## Les règles d'une tentative

- **Chaque tentative d'une livraison réutilise son `webhook-id`**, pour que la déduplication du récepteur absorbe
  les nouvelles tentatives. Générez l'identifiant une fois, à la création de la livraison, et stockez-le avec elle.
- **Seuls l'horodatage et les signatures sont recalculés** à chaque tentative : une nouvelle tentative signée avec
  l'horodatage d'origine tomberait hors de la tolérance du récepteur.
- **Signez exactement les octets que vous envoyez.** Encodez le corps une fois, signez cette chaîne, et envoyez cette
  chaîne.
- L'identifiant ne doit contenir ni point, ni espace, ni caractère non ASCII, et compte au plus 255 octets
  (`WebhookId::fromString()` refuse tout le reste).

## Exemple

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

## Interpréter la réponse du récepteur

La bibliothèque n'envoie pas : elle ne fait donc pas de nouvelles tentatives. Dans une application Symfony, le
`WebhookSender` de `phil/standard-webhooks-bundle` envoie une tentative. Le comportement auquel s'attendent,
d'après leur documentation, les récepteurs construits sur cette bibliothèque :

- `2xx` veut dire traité (le récepteur ne répond qu'après avoir validé la transaction) ;
- tout `4xx` est permanent : une livraison mal formée ou refusée échoue de la même façon à la nouvelle tentative ;
  arrêtez et alertez ;
- un délai dépassé, une erreur réseau ou un `5xx` vaut une nouvelle tentative, avec le même `webhook-id`, selon votre
  calendrier ;
- votre calendrier de nouvelles tentatives doit tenir dans la fenêtre de déduplication du récepteur.

## Choisir et publier les clés

Préférez `v1a` et publiez la clé publique `whpk_` là où les récepteurs peuvent la chercher ; réservez `v1` aux
récepteurs qui ne le peuvent pas, avec un secret `whsec_` par destination (voyez
[signatures.md](signatures.md#laquelle-choisir)). Standard Webhooks ne définit aucun format de distribution des clés
publiques : le document ou le point de terminaison qui les publie appartient à l'application. Quand une clé signe
pour plusieurs récepteurs, placez l'identifiant du récepteur dans l'`audience` de l'enveloppe, pour qu'une livraison
destinée à un récepteur ne puisse pas être rejouée chez un autre pendant la tolérance.

<!-- phil-dev source-sha256=1f2c5eb91215ad68bdff09790a36800fa2cc9b1bfbfde96194c7021d3ba694d2 -->
# Enveloppe et en-têtes

**État : implémenté et testé.** Classes sous `Phil\StandardWebhooks`.

La bibliothèque suit la spécification [Standard Webhooks](https://github.com/standard-webhooks/standard-webhooks),
version 1.0.0. Une livraison est une requête HTTP avec trois en-têtes et un corps JSON.

## Les trois en-têtes : `WebhookHeaders`

```text
webhook-id: msg_2KWPBgLlAfxdpx2AI54pPJ85f4W
webhook-timestamp: 1614265330
webhook-signature: v1a,<base64> v1,<base64>
```

`WebhookHeaders` contient les trois valeurs analysées sous forme de propriétés publiques en lecture seule
`WebhookId $id`, `WebhookTimestamp $timestamp` et `SignatureList $signatures`. Son constructeur est public (le
`Signer` en construit un) ; les constantes `ID`, `TIMESTAMP` et `SIGNATURE` contiennent les noms d'en-têtes en
minuscules.

| Méthode | Description |
|---|---|
| `fromStrings(?string $id, ?string $timestamp, ?string $signature): self` | Lit les en-têtes tels que reçus. Un en-tête `null` lance `InvalidWebhookValue` (`The "webhook-id" header is missing.`) ; chaque valeur est ensuite analysée par les classes ci-dessous. |
| `toArray(): array` | `['webhook-id' => ..., 'webhook-timestamp' => ..., 'webhook-signature' => ...]`, prêt à envoyer. |

```php
$headers = WebhookHeaders::fromStrings(
    $request->headers->get(WebhookHeaders::ID),
    $request->headers->get(WebhookHeaders::TIMESTAMP),
    $request->headers->get(WebhookHeaders::SIGNATURE),
);
```

## `webhook-id` : `WebhookId`

L'identifiant du message, **le même à chaque tentative d'une livraison** : c'est ce qui permet à un récepteur de
dédupliquer les nouvelles tentatives.

La spécification ne donne aucune syntaxe, seulement que l'identifiant ne doit pas contenir de point, puisque le point
délimite le contenu signé. `WebhookId::fromString()` refuse aussi ce qui n'a pas sa place dans un en-tête ou une clé
de stockage, et lance `InvalidWebhookValue` pour :

- une valeur vide ;
- plus de `MAX_LENGTH` (255) octets ;
- tout ce qui sort de l'ASCII imprimable sans espace ni point (`0x21` à `0x2D` et `0x2F` à `0x7E`) : les espaces, les
  tabulations, les sauts de ligne, NUL, les caractères non ASCII et `.` sont refusés.

`$value` contient l'identifiant ; `equals()` compare exactement. L'exemple de la spécification
`msg_2KWPBgLlAfxdpx2AI54pPJ85f4W` est accepté.

## `webhook-timestamp` : `WebhookTimestamp`

Un nombre entier de secondes depuis l'époque Unix, tel que l'horloge de l'expéditeur a vu la tentative.

| Méthode | Description |
|---|---|
| `fromHeader(string $header): self` | **Strict** : chiffres décimaux seulement, sans signe, sans zéro en tête (`0` lui-même est accepté), sans fraction, sans espace autour, au plus douze chiffres (au-delà de l'an 30000, loin d'un dépassement d'entier). Les millisecondes (`1614265330000`) et les dates ISO 8601 sont refusées. L'en-tête fait partie du contenu signé : une analyse tolérante accepterait une chaîne que l'expéditeur n'a jamais signée sous cette forme. |
| `fromDateTime(DateTimeImmutable $at): self` | Pour l'expéditeur. Une date avant l'époque lance `InvalidWebhookValue`. |
| `skewFrom(DateTimeImmutable $now): int` | La distance absolue à l'horloge du récepteur, en secondes. Un horodatage venu du futur est aussi suspect qu'un horodatage du passé. |
| `isWithin(Tolerance $tolerance, DateTimeImmutable $now): bool` | `skewFrom($now) <= $tolerance->seconds` : la borne est inclusive des deux côtés. |
| `toHeader(): string` | La chaîne décimale. |

`$seconds` contient l'entier.

## La tolérance : `Tolerance`

L'écart permis entre `webhook-timestamp` et l'horloge du récepteur, dans un sens ou dans l'autre.
`Tolerance::ofSeconds(int $seconds)` accepte tout entier positif et lance `InvalidWebhookValue` pour zéro ou une
valeur négative ; `$seconds` la contient.

**La spécification ne donne aucun nombre, et la bibliothèque non plus.** Choisissez une valeur au-dessus de la
dérive d'horloge que vous tolérez et **sous la fenêtre pendant laquelle les `webhook-id` traités sont gardés**, sinon
une livraison capturée se rejoue une fois son identifiant oublié.

## `webhook-signature` : `SignatureList` et `Signature`

Une liste d'entrées `{version},{base64 signature}` séparées par des espaces, pour que deux clés se chevauchent
pendant une rotation. Le détail de chaque schéma est dans [signatures.md](signatures.md).

`Signature` (une entrée) a les propriétés publiques `Scheme $scheme` et `string $bytes` :

| Méthode | Description |
|---|---|
| `of(Scheme $scheme, string $bytes): self` | Lance `InvalidWebhookValue` sauf si la longueur correspond au schéma : 32 octets pour `v1`, 64 pour `v1a`. |
| `tryFromHeaderEntry(string $entry): ?self` | Retourne `null`, au lieu de lancer une exception, pour une entrée qui ne peut pas se vérifier : une version inconnue, une virgule absente ou en double, une charge utile qui n'est pas du base64 strict avec remplissage, ou une signature de la mauvaise longueur. |
| `toHeaderEntry(): string` | `v1,<base64>` ou `v1a,<base64>`. |

`SignatureList` a une propriété publique `list<Signature> $signatures` et `MAX_ENTRIES = 8` :

| Méthode | Description |
|---|---|
| `of(Signature ...$signatures): self` | Pour l'expéditeur. Lance `InvalidWebhookValue` pour zéro ou plus de huit signatures. Les clés d'un tableau déployé sont abandonnées. |
| `fromHeader(string $header): self` | Pour le récepteur. Découpe sur les espaces simples et ignore les entrées vides (les espaces répétés sont sans effet). **Plus de huit entrées lance `InvalidWebhookValue` avant qu'aucune entrée ne soit lue.** Les entrées illisibles sont abandonnées : la liste peut donc être vide. Un en-tête qui ne contient rien que le récepteur puisse vérifier est une livraison qui échoue à la vérification, pas une livraison mal formée. |
| `toHeader(): string` | Les entrées jointes par des espaces simples. |

**Pourquoi le plafond :** chaque entrée est une vérification que le récepteur effectue avant de savoir si
l'expéditeur est authentique ; une liste sans borne est donc du travail qu'un attaquant lui fait faire gratuitement.
**Pourquoi les versions inconnues sont abandonnées plutôt que fatales :** un expéditeur peut ajouter un schéma avant
que chaque récepteur le connaisse ; une version inconnue est ignorée, jamais acceptée.

## Le corps : `Envelope`

La charge utile JSON : le `type`, le `timestamp` et le `data` que recommande Standard Webhooks, plus une `audience`
facultative. **Ne la lisez qu'une fois la signature vérifiée, à partir du même corps brut**
(`VerifiedWebhook::envelope()` fait exactement cela).

```json
{"type":"invoice.paid","timestamp":"2026-10-31T12:00:00Z","data":{"id":"abc"},"audience":"receiver-1"}
```

`Envelope::fromJson(string $rawBody)` retourne une enveloppe avec les propriétés publiques `string $type`,
`?string $timestamp`, `array $data` et `?string $audience`, et lance `InvalidWebhookValue` quand :

- le corps n'est pas du JSON, ou s'imbrique sur plus de 64 niveaux (l'objet lui-même compris) ;
- le corps n'est pas un objet JSON (une liste non vide ou un scalaire) ;
- `type` est absent, vide ou n'est pas une chaîne ; **seul `type` est obligatoire** ;
- `timestamp` est présent et n'est pas une chaîne (il est gardé tel quel, sans analyse) ;
- `data` est présent et n'est pas un objet (un scalaire ou une liste non vide) ; un `data` absent vaut `[]` ;
- `audience` est présent et n'est pas une chaîne.

Décodés en tableaux PHP, `{}` et `[]` sont la même valeur : un `data` vide est accepté comme objet vide, et un corps
vide échoue sur son `type` absent. Tout autre membre du corps est ignoré.

### `audience`

`audience` est une extension de l'enveloppe recommandée. Quand une clé signe pour plusieurs récepteurs, une livraison
reçue par un récepteur se vérifie encore chez un autre tant que dure la tolérance d'horodatage ; une audience sous la
signature les distingue. `assertAddressedTo(string $audience)` compare **strictement** (une audience est un
identifiant, jamais un motif) et lance `WebhookRejected` avec la raison `AudienceMismatch` quand l'enveloppe nomme
une autre audience ou n'en nomme aucune. `WebhookReceiver` l'appelle quand vous configurez une audience.

## `VerifiedWebhook`

Ce que retourne le récepteur une fois l'horodatage et la signature vérifiés : les propriétés publiques
`WebhookId $id`, `WebhookTimestamp $timestamp`, `string $rawBody`, et `envelope(): Envelope`, qui décode le corps
brut à chaque appel. **Seul cet objet, jamais la requête brute, est remis au code qui traite l'événement.** Son
constructeur est public : les tests peuvent en construire un directement.

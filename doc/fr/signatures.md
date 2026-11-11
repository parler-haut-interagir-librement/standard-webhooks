<!-- phil-dev source-sha256=e08d5ab197dead71ddb1f2000acad23e6bd72022bdeb4d12ce8309a493ab3921 -->
# Signatures

**État : implémenté et testé.** Les deux schémas de Standard Webhooks 1.0.0, Ed25519 (RFC 8032) par libsodium et
HMAC-SHA256 par `hash_hmac()`.

## Le contenu signé : `SignedContent`

Les deux schémas signent les mêmes octets :

```text
{webhook-id}.{webhook-timestamp}.{body}
```

`SignedContent::of(WebhookId $id, WebhookTimestamp $timestamp, string $rawBody): string`. Par exemple, `msg_1`,
`1614265330` et `{"a":1}` donnent `msg_1.1614265330.{"a":1}`.

- **Le corps est le corps brut tel que reçu, octet pour octet**, vérifié avant tout décodage. Un corps décodé puis
  réencodé avant la vérification devient une autre chaîne dès qu'un ordre de clés, un échappement ou un espacement
  diffère, et la signature échoue pour une raison que personne ne voit. Un test fige qu'un seul espace ajouté, ou un
  seul espace dans le JSON, fait échouer une signature authentique.
- Le point n'est sans ambiguïté que parce que `WebhookId` le refuse et qu'un horodatage n'est fait que de chiffres.
- **Cette disposition est le contrat d'interopérabilité.** La changer brise chaque récepteur et chaque expéditeur.

## Les schémas : `Scheme`

| Cas | Libellé | Algorithme | Signature brute | Préfixe de clé |
|---|---|---|---|---|
| `Scheme::V1a` | `v1a` | Ed25519, asymétrique : l'expéditeur signe avec une clé secrète, le récepteur vérifie avec la clé publique | 64 octets | `whsk_` (secrète), `whpk_` (publique) |
| `Scheme::V1` | `v1` | HMAC-SHA256 sous un secret partagé par un expéditeur et un récepteur | 32 octets | `whsec_` |

`Scheme::tryFrom()` retourne `null` pour tout autre libellé, et `signatureLength()` retourne la longueur brute
ci-dessus.

### Laquelle choisir

- **`v1a` partout où le récepteur peut chercher une clé publique.** Le récepteur ne détient rien qui permette de
  falsifier une livraison : rien de secret n'est donc remis, renouvelé ni révoqué de son côté.
- **`v1` pour les récepteurs à qui on ne peut pas demander de chercher une clé**, avec **un secret par destination,
  jamais partagé**, pour qu'une fuite chez un récepteur ne permette de rien falsifier chez un autre.

## Les clés

Les trois types de clés sont des classes `final readonly` à constructeurs privés. Les deux types secrets se masquent
dans `var_dump()` et dans les vidages par `__debugInfo()` (`[redacted]`), et marquent leurs paramètres secrets
`#[SensitiveParameter]`, pour qu'ils n'apparaissent pas dans les traces de pile.

### `Ed25519SecretKey` (signe `v1a`, implémente `SigningKey`)

| Méthode | Description |
|---|---|
| `generate(): self` | Une nouvelle paire de clés de libsodium. |
| `fromString(string $encoded): self` | `whsk_` suivi de base64 (alphabet standard, remplissage obligatoire). |
| `fromBytes(string $bytes): self` | Soit le **germe de 32 octets**, soit la **clé secrète libsodium de 64 octets** (le germe suivi de la clé publique). La spécification ne dit pas quels octets sont encodés : les deux formes courantes sont donc lues. Pour la forme de 64 octets, la seconde moitié doit être la clé publique que dérive le germe, sinon `InvalidWebhookValue` est lancée. Toute autre longueur est refusée. |
| `toString(): string` | Toujours la forme de **64 octets**, `whsk_` + base64, encodée à temps constant. |
| `publicKey(): Ed25519PublicKey` | La moitié publique. |
| `scheme(): Scheme` | `Scheme::V1a`. |
| `sign(string $signedContent): Signature` | Une signature Ed25519 détachée. |

### `Ed25519PublicKey` (vérifie `v1a`, implémente `VerificationKey`)

| Méthode | Description |
|---|---|
| `fromString(string $encoded): self` | `whpk_` suivi des 32 octets de la clé en base64. |
| `fromBytes(string $bytes): self` | Exactement 32 octets. |
| `toString(): string` | `whpk_` + base64. |
| `equals(self $other): bool` | Comparaison à temps constant. |
| `scheme(): Scheme` | `Scheme::V1a`. |
| `verifies(string $signedContent, Signature $signature): bool` | `false` pour une signature d'un autre schéma ; sinon, la vérification détachée de libsodium. |

`$bytes` (la clé publique brute) est public : ce n'est pas un secret.

### `HmacSecret` (signe et vérifie `v1`, implémente les deux interfaces)

| Méthode | Description |
|---|---|
| `generate(): self` | 32 octets aléatoires de `random_bytes()`. |
| `fromString(string $encoded): self` | `whsec_` suivi du base64 de **24 à 64 octets** inclusivement (`MIN_BYTES`, `MAX_BYTES`), comme l'exige la spécification. |
| `toString(): string` | `whsec_` + base64, encodé à temps constant. |
| `scheme(): Scheme` | `Scheme::V1`. |
| `sign(string $signedContent): Signature` | HMAC-SHA256 brut. |
| `verifies(string $signedContent, Signature $signature): bool` | `false` pour une signature d'un autre schéma ; sinon, recalcule et compare avec `hash_equals()`, **à temps constant** : une comparaison qui s'arrête au premier octet différent permet à un faussaire de mesurer, octet par octet, à quel point une tentative est proche. |
| `verifiesAny(string $signedContent, SignatureList $signatures): bool` | Si l'une des signatures `v1` de la liste se vérifie. Le HMAC est calculé **une fois** pour toute la liste, et chaque signature comparée avec `hash_equals()`, à temps constant. `Verifier` l'appelle pour chaque `HmacSecret` : un en-tête de huit signatures falsifiées coûte un HMAC par clé, pas huit. |

### Les formats de clés d'un coup d'œil

```text
whsk_<base64 of 64 bytes: seed || public key>      Ed25519 secret key (32-byte seed also accepted on reading)
whpk_<base64 of 32 bytes>                          Ed25519 public key
whsec_<base64 of 24 to 64 bytes>                   HMAC secret
```

Les trois utilisent le base64 standard avec remplissage. Générer des clés :

```php
$secret = Ed25519SecretKey::generate();
$secret->toString();               // whsk_..., keep it in your secret store
$secret->publicKey()->toString();  // whpk_..., publish it to receivers

HmacSecret::generate()->toString(); // whsec_..., one per destination
```

## Base64 : `Base64`

`Base64` contient deux codecs stricts sur l'alphabet standard avec remplissage, **choisis selon ce qui passe par
eux** :

| Méthodes | Codec | Sert à |
|---|---|---|
| `encodeSecret()`, `decodeSecret()` | le codec à temps constant de libsodium | Les clés secrètes (`whsk_`, `whsec_`) : le temps que prend un encodage ne dit rien des octets. |
| `encodePublic()`, `decodePublic()` | le codec natif de PHP en mode strict, plus une vérification par réencodage | Les signatures et les clés publiques, publiées de toute façon ; plus rapide sur le chemin que prend chaque livraison. |

Les deux décodeurs retournent `null` (sans jamais lancer d'exception) pour les mêmes entrées : un remplissage
absent, un caractère hors de l'alphabet, l'alphabet compatible avec les URL, des bits finaux non canoniques, des
espaces.

## Signature et vérification

`Signer::sign(WebhookId $id, WebhookTimestamp $timestamp, string $rawBody, SigningKey $key, SigningKey ...$moreKeys): WebhookHeaders`
signe une tentative avec une ou plusieurs clés ; voyez [sending.md](sending.md).

`Verifier` vérifie une livraison reçue contre un ensemble de clés de confiance :

| Méthode | Description |
|---|---|
| `verify(WebhookHeaders $headers, string $rawBody, iterable $keys, Tolerance $tolerance, DateTimeImmutable $now): VerifiedWebhook` | Vérifie d'abord l'horodatage, puis les signatures ; lance `WebhookRejected` avec `TimestampOutsideTolerance` ou `NoValidSignature`. |
| `signatureVerifies(WebhookHeaders $headers, string $rawBody, iterable $keys): bool` | La vérification de signature seule. |

**L'en-tête ne porte aucun identifiant de clé** : une livraison est donc acceptée quand **l'une** de ses signatures se
vérifie sous **l'une** des clés de confiance **du même schéma**. Une signature d'un schéma qu'aucune clé de confiance
n'a est ignorée, jamais acceptée : une signature `v1` calculée avec les octets d'une clé publique comme secret HMAC ne
passe pas pour `v1a` (un test le fige).

## Rotation

L'en-tête de signature est une liste : les clés se chevauchent donc. Pendant une rotation, l'expéditeur signe avec la
nouvelle et l'ancienne clé, et un récepteur qui ne fait confiance qu'à l'une d'elles accepte quand même la livraison.
Le récepteur recharge ses clés une fois quand aucune clé en cache ne vérifie
([receiving.md](receiving.md#recharger-une-fois-avant-de-refuser)) : la rotation d'un expéditeur est ainsi prise en
compte sans redéploiement.

## Vecteurs de référence

Ces valeurs sont figées par la suite de tests ; toute implémentation doit les reproduire.

**Ed25519, RFC 8032 section 7.1, test 1** (`Ed25519KeyTest`) :

```text
seed (hex)        9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60
public key (hex)  d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a
message           (empty)
signature (hex)   e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b
```

**HMAC-SHA256, l'exemple `v1` publié par les bibliothèques de référence de Standard Webhooks** (`HmacSecretTest`) :

```text
secret            whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw
signed content    msg_p5jXN8AQM9LWM0D4loKWxJek.1614265330.{"test": 2432232314}
signature entry   v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=
```

**Un exemple `v1a` calculé avec cette bibliothèque** à partir du germe de la RFC 8032 ci-dessus et du même message
que l'exemple `v1`. Il est déterministe (les signatures Ed25519 le sont), mais aucun test ne le fige ; utilisez-le
pour vérifier un récepteur écrit sans la bibliothèque :

```text
secret key        whsk_nWGxne/9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2DXWpgBgrEKt9VL/tPJZAc6DuFy89qmIyWvAhpo9wdRGg==
public key        whpk_11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo=
webhook-id        msg_p5jXN8AQM9LWM0D4loKWxJek
webhook-timestamp 1614265330
body              {"test": 2432232314}
webhook-signature v1a,fldxM4gAKugP6nnt1hdz3sgGfZ6d99nzrMFnZOELIxbzEHoVmAb2ADpkJK7zgPePmPsle0zV9jSeGlHFG2NVAw==
```

L'horodatage de cet exemple est loin dans le passé : `Verifier::verify()` le rejette pour son horodatage, sauf si
`$now` est dans la tolérance de `1614265330`. Utilisez `signatureVerifies()` pour vérifier la signature seule.

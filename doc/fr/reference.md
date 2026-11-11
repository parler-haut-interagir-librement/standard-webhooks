<!-- phil-dev source-sha256=831836cef2a4da53f6560b41e09df780082806558db96171789c954b7cb062e0 -->
# Référence

Chaque type public de la bibliothèque, par sujet. Tous vivent dans `Phil\StandardWebhooks`, les exceptions dans
`Phil\StandardWebhooks\Exception`. Les objets-valeurs sont immuables (`final readonly`), avec des constructeurs
privés derrière des constructeurs nommés, sauf mention contraire.

## Réception

| Type | Rôle | État |
|---|---|---|
| `WebhookReceiver` | `receive(headers, rawBody, now)` : horodatage, signature sous les clés en cache, un rechargement, audience. Constructeur public `(VerificationKeyProvider, Tolerance, ?string $audience = null, Verifier = new Verifier())`. | Implémenté et testé |
| `VerificationKeyProvider` | Port : `keys()` et `refresh()`, les clés de confiance du récepteur. | Port |
| `FixedKeys` | `VerificationKeyProvider` pour des clés connues d'avance : `fromStrings()`, un constructeur public ; `refresh()` les redonne. | Implémenté et testé |

## Signature et vérification

| Type | Rôle | État |
|---|---|---|
| `Scheme` | Enum adossée à des chaînes : `V1` (`v1`, HMAC-SHA256, signatures de 32 octets) et `V1a` (`v1a`, Ed25519, signatures de 64 octets) ; `signatureLength()`. | Implémenté et testé |
| `SignedContent` | `of(id, timestamp, rawBody)` : les octets que signent les deux schémas, `{id}.{timestamp}.{body}`. | Implémenté et testé |
| `Signer` | `sign(id, timestamp, rawBody, key, ...moreKeys)` : les `WebhookHeaders` d'une tentative. | Implémenté et testé |
| `Verifier` | `verify()` (l'horodatage d'abord, puis les signatures) et `signatureVerifies()`. | Implémenté et testé |
| `SigningKey` | Interface : `scheme()`, `sign()`. | Interface, implémentée par `Ed25519SecretKey` et `HmacSecret` |
| `VerificationKey` | Interface : `scheme()`, `verifies()`. | Interface, implémentée par `Ed25519PublicKey` et `HmacSecret` |
| `Base64` | Base64 strict avec remplissage : `encodeSecret()`/`decodeSecret()` à temps constant, `encodePublic()`/`decodePublic()` natifs. | Implémenté et testé |

## Valeurs

| Type | Rôle | État |
|---|---|---|
| `WebhookHeaders` | Les trois en-têtes : `fromStrings()`, `toArray()`, les constantes `ID`, `TIMESTAMP`, `SIGNATURE` ; constructeur public. | Implémenté et testé |
| `WebhookId` | `webhook-id` : ASCII imprimable sans espace ni point, au plus 255 octets ; `fromString()`, `generate()`, `equals()`. | Implémenté et testé |
| `WebhookTimestamp` | `webhook-timestamp` : secondes Unix strictes ; `fromHeader()`, `fromDateTime()`, `skewFrom()`, `isWithin()`, `toHeader()`. | Implémenté et testé |
| `Tolerance` | Un nombre positif de secondes, dans les deux sens ; `ofSeconds()`. | Implémenté et testé |
| `Signature` | Une entrée `{version},{base64}` : `of()`, `tryFromHeaderEntry()`, `toHeaderEntry()`. | Implémenté et testé |
| `SignatureList` | `webhook-signature`, au plus huit entrées : `of()`, `fromHeader()`, `toHeader()`. | Implémenté et testé |
| `Ed25519SecretKey` | Clé `whsk_`, signe `v1a` : `generate()`, `fromString()`, `fromBytes()` (germe ou 64 octets), `toString()`, `publicKey()`. | Implémenté et testé contre la RFC 8032 |
| `Ed25519PublicKey` | Clé `whpk_`, vérifie `v1a` : `fromString()`, `fromBytes()`, `toString()`, `equals()`. | Implémenté et testé contre la RFC 8032 |
| `Keys` | `signing()` et `verification()` : une clé lue par son préfixe. | Implémenté et testé |
| `HmacSecret` | Secret `whsec_` de 24 à 64 octets, signe et vérifie `v1` : `generate()`, `fromString()`, `toString()`. | Implémenté et testé contre le vecteur `v1` de Standard Webhooks |
| `Envelope` | Le corps JSON : `type` (obligatoire), `timestamp`, `data`, `audience` ; `fromJson()`, `assertAddressedTo()`. | Implémenté et testé |
| `VerifiedWebhook` | Une livraison dont l'horodatage et la signature ont été vérifiés : `id`, `timestamp`, `rawBody`, `envelope()` ; constructeur public. | Implémenté et testé |

## Déduplication

| Type | Rôle | État |
|---|---|---|
| `ProcessedWebhookRepository` | Port : `claim(id, processedAt)` atomique, dans la transaction du traitement. | Port |

## Exception

| Type | Rôle | État |
|---|---|---|
| `WebhookException` | Interface marqueur qui étend `Throwable` : un seul `catch` à la frontière HTTP. | Implémenté |
| `InvalidWebhookValue` | `InvalidArgumentException` : une entrée mal formée, typiquement `400` ; `because(string)`. | Implémenté et testé |
| `WebhookRejected` | `RuntimeException` : une livraison bien formée refusée ; `RejectionReason $reason` public ; `timestampOutsideTolerance()`, `noValidSignature()`, `audienceMismatch()`. | Implémenté et testé |
| `RejectionReason` | Enum adossée à des chaînes : `TimestampOutsideTolerance`, `NoValidSignature`, `AudienceMismatch`. | Implémenté et testé |

## Hors de la bibliothèque

- Une couche HTTP : un contrôleur, un analyseur de requêtes, un client sortant, un calendrier de nouvelles tentatives
  ou une file de lettres mortes.
- Un stockage des identifiants traités.

`phil/standard-webhooks-bundle` apporte le câblage Symfony, l'argument de contrôleur, un expéditeur sur le client
HTTP de Symfony et une table Doctrine.

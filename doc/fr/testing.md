<!-- phil-dev source-sha256=86415c99a70758e0bfb3755ce09d9360fe3e15ca3979cd97fdd5fb9268ee9a07 -->
# Tests

## Ce que couvrent les tests propres à la bibliothèque

La suite unitaire vit dans `tests/Unit` et s'exécute avec PHPUnit 13. Elle a besoin de `ext-sodium`, et d'aucune base
de données, d'aucun noyau ni d'aucun réseau. Les heures sont fixes (`@1793448000`), jamais lues dans l'horloge du
système.

| Classe de test | Ce qu'elle fige |
|---|---|
| `SignerAndVerifierTest` | Le contenu signé `msg_1.1614265330.{"a":1}` ; les allers-retours `v1a` et `v1` ; l'une ou l'autre clé qui vérifie pendant une rotation ; un corps changé d'un octet qui échoue ; un autre identifiant ou un autre horodatage qui échoue ; une signature d'un autre schéma jamais acceptée ; aucune clé de confiance, ce qui donne `NoValidSignature` ; une livraison authentique mais périmée rejetée pour son horodatage, avec son message exact. |
| `Ed25519KeyTest` | **Le vecteur de la RFC 8032** ; la clé publique qui vérifie la signature de sa clé secrète et rien d'autre ; les allers-retours `whsk_` et `whpk_` ; un germe et sa forme de 64 octets qui sont la même clé ; une moitié publique qui ne correspond pas, refusée ; les longueurs et les préfixes ; le vidage masqué. |
| `HmacSecretTest` | **Le vecteur `v1` de Standard Webhooks** ; la génération de 32 octets ; la vérification de ses propres signatures seulement ; les bornes inclusives de 24 et 64 octets ; les préfixes ; le vidage masqué. |
| `Base64Test` | Les deux codecs encodent le même alphabet avec remplissage, font l'aller-retour et refusent les mêmes entrées. |
| `SignatureListTest` | Les deux schémas dans un même en-tête ; les entrées illisibles abandonnées ; les espaces répétés ; le plafond de huit, appliqué avant qu'aucune entrée ne soit lue ; au moins une signature à la construction. |
| `WebhookIdTest`, `WebhookTimestampTest`, `ToleranceTest`, `WebhookHeadersTest` | Les jeux de caractères et les bornes, les secondes Unix strictes, la tolérance inclusive dans les deux sens, les en-têtes absents. |
| `EnvelopeTest` | Les champs recommandés ; seul `type` obligatoire ; un `data` vide ; les corps mal formés ; la borne d'imbrication de 64 niveaux ; les messages d'audience qui correspond ou non. |
| `WebhookReceiverTest` | Une clé en cache qui vérifie sans rechargement ; une clé inconnue qui déclenche exactement un rechargement ; une livraison falsifiée rejetée après un rechargement ; une livraison périmée qui ne recharge jamais ; l'acceptation et le rejet selon l'audience ; une audience attendue vide refusée. Utilise le jeu de données d'essai `CountingKeyProvider`. |
| `KeysTest` | Les clés lues par leur préfixe, les autres refusées ; `FixedKeys` qui redonne ses clés au rechargement et les tient hors des traces de pile ; les identifiants générés, aléatoires et valides. |

### Vecteurs de référence

Les deux vecteurs que toute implémentation doit reproduire sont donnés en entier dans
[signatures.md](signatures.md#vecteurs-de-référence) : le test 1 de la section 7.1 de la RFC 8032 pour Ed25519, et
l'exemple `v1` des bibliothèques de référence de Standard Webhooks pour HMAC-SHA256. Un changement qui brise l'un ou
l'autre est un changement d'algorithme, pas une refactorisation.

## Lancer les tests

Depuis la racine du monodépôt :

```bash
vendor/bin/phpunit packages/standard-webhooks/tests
```

Installée seule, dans une copie fraîche de la bibliothèque :

```bash
cd standard-webhooks
composer install
vendor/bin/phpunit --no-configuration tests
```

Infection tient la bibliothèque à 100 % de mutants tués.

## Tester vos adaptateurs et votre point de terminaison

**`VerificationKeyProvider`** : vérifiez que `keys()` ne va rien chercher, que `refresh()` va chercher, qu'un second
`refresh()` dans votre budget retourne les clés en cache sans rien aller chercher, et qu'une source en échec laisse
les clés en cache en place. Un jeu de données d'essai qui compte, comme le `CountingKeyProvider` de la bibliothèque,
suffit pour tester le récepteur autour :

```php
final class CountingKeyProvider implements VerificationKeyProvider
{
    public int $refreshes = 0;

    public function __construct(private readonly array $cached, private readonly array $reloaded) {}

    public function keys(): array { return $this->cached; }

    public function refresh(): array { ++$this->refreshes; return $this->reloaded; }
}
```

**`ProcessedWebhookRepository`**, contre la vraie base de données : le premier `claim()` retourne `true`, le second
`false` ; une revendication dans une transaction qui fait un retour arrière laisse l'identifiant revendicable ; deux
connexions qui revendiquent le même identifiant en même temps donnent exactement un `true`.

**Votre point de terminaison** : signez des livraisons de test avec le `Signer` de la bibliothèque et une clé
générée, puis envoyez-les à votre contrôleur :

```php
$key = Ed25519SecretKey::generate();
$body = '{"type":"invoice.paid","data":{"id":"abc"},"audience":"receiver-1"}';
$headers = new Signer()->sign(WebhookId::fromString('msg_1'), WebhookTimestamp::fromDateTime($now), $body, $key)->toArray();

$client->request('POST', '/webhooks', server: [
    'HTTP_WEBHOOK_ID' => $headers['webhook-id'],
    'HTTP_WEBHOOK_TIMESTAMP' => $headers['webhook-timestamp'],
    'HTTP_WEBHOOK_SIGNATURE' => $headers['webhook-signature'],
], content: $body);
```

Couvrez au moins : une livraison valide à laquelle on répond `2xx` et traitée une fois ; la même livraison de nouveau,
à laquelle on répond `2xx` sans la traiter ; un corps changé d'un octet, refusé ; un horodatage une seconde au-delà de
la tolérance, refusé ; une livraison pour une autre audience, refusée ; un type d'événement inconnu, auquel on ne
répond pas `2xx`.

Pour tester un récepteur écrit sans la bibliothèque, utilisez l'exemple `v1a` fixe de
[signatures.md](signatures.md#vecteurs-de-référence) avec une logique à la `signatureVerifies()`, ou le vecteur `v1`.

## Bancs d'essai

Les bancs d'essai phpbench de `tests/Benchmark` mesurent les chemins critiques à plusieurs tailles. Depuis la racine
du monodépôt :

```sh
composer bench -- packages/standard-webhooks/tests/Benchmark
```

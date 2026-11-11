<!-- phil-dev source-sha256=acfeac87e7c36ec841ba0c491d7f00b0eb644d65f3442450984ab43584d537c6 -->
# Installation

| Prérequis | Contrainte | Pourquoi |
|---|---|---|
| PHP | `>=8.5` | Le minimum déclaré, la version sur laquelle la bibliothèque est testée. |
| `ext-sodium` | `*` | La signature et la vérification Ed25519 (`sodium_crypto_sign_*`), le codec base64 à temps constant des clés secrètes, et les identifiants générés. Fournie avec PHP, mais certaines distributions l'empaquettent à part. |

HMAC-SHA256 utilise `hash_hmac()` de PHP, qui n'a besoin d'aucune extension. La bibliothèque ne dépend d'aucun
cadriciel : les en-têtes et les corps sont passés sous forme de chaînes, si bien qu'elle se place derrière n'importe
quelle couche HTTP.

```bash
php -r 'var_dump(extension_loaded("sodium"));'
composer require phil/standard-webhooks:^0.1@dev
```

Dans une application Symfony, requérez plutôt `phil/standard-webhooks-bundle` : il requiert la bibliothèque.

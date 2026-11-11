# Installation

| Requirement | Constraint | Why |
|---|---|---|
| PHP | `>=8.5` | The declared minimum, the version the library is tested on. |
| `ext-sodium` | `*` | Ed25519 signing and verification (`sodium_crypto_sign_*`), the constant-time base64 codec of secret keys, and generated ids. Bundled with PHP, but some distributions package it separately. |

HMAC-SHA256 uses PHP's `hash_hmac()`, which needs no extension. The library depends on no framework: headers and
bodies are passed in as strings, so it sits behind any HTTP layer.

```bash
php -r 'var_dump(extension_loaded("sodium"));'
composer require phil/standard-webhooks:^0.1@dev
```

In a Symfony application, require `phil/standard-webhooks-bundle` instead: it requires the library.

# Contributing

This package is one of the open packages of [Parler Haut Interagir Librement](https://github.com/parler-haut-interagir-librement)
(PHIL). Its code is developed in the PHIL monorepo, with the other `phil/*` packages, and its own repository is a
read-only copy split from it: **open issues and pull requests on the monorepo**, not on the split repository.

## Reporting a bug

- Check first that the bug is not already reported.
- Give the version of the package, of PHP and of Symfony, the configuration involved, and the smallest code that
  shows the bug.
- A security vulnerability is not a bug report: follow the [security policy](SECURITY.md) instead.

## Proposing a change

Open an issue before a large change, so that its design can be agreed first. Then:

1. **Write the code from scratch.** No code is copied from another project, even under a compatible licence:
   another project may serve as a reference to read, never as a source to copy, translate or port. A third-party
   licence enters a package only as a declared Composer dependency.
2. **Keep the package independent of any application.** Its code, comments and documentation never name an
   application that uses it; what is specific to one application is configuration.
3. **Follow the layers.** A bundle keeps `Domain` (plain PHP, value objects and rules, no container and no I/O),
   `Application` (the services and the ports the application implements) and `Infrastructure` (the adapters to
   Symfony, Doctrine, Twig…) apart; the bundle class only wires them.
4. **Test it.** Every change comes with its tests. The domain layer is held to 100 % mutation coverage: a mutant
   that survives is a missing test, or code that nothing can reach.
5. **Document it, in English.** Update the package's `doc/` and, when a topic is added, the documentation table of
   its README.
6. **Pass the checks.** From the root of the monorepo, the quality checks run on every package — PHP-CS-Fixer,
   Rector, PHPStan at its maximum level, deptrac, Arkitect, PHPUnit and Infection — and `composer packages` installs
   each package on its own, checks its declared dependencies and runs its tests. A pull request is merged when
   every check passes.

To check a package on its own, from its directory:

```sh
composer install
vendor/bin/phpunit --no-configuration tests
```

## Licence

By contributing, you agree to license your contribution under the MIT licence of the package, whose holder its
[LICENSE](LICENSE) names.

## Code of conduct

Everyone taking part follows the [code of conduct](CODE_OF_CONDUCT.md).

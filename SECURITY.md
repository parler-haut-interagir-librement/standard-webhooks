# Security Policy

## Reporting a vulnerability

**Do not report a vulnerability in a public issue, pull request or discussion.** Write to
[security@ph-il.ca](mailto:security@ph-il.ca) instead, with:

- a description of the vulnerability and of what an attacker can do with it;
- the package and the version or commit affected;
- the steps to reproduce it, or a proof of concept, if you have one;
- anything else that helps: logs, the configuration involved, a suggested fix.

You will get an acknowledgement within 48 hours, and news of the fix as it progresses. Please keep the
vulnerability private until a fixed version is published; we will agree with you on the date of the disclosure and
credit you in the advisory, unless you prefer not to be named.

## How a vulnerability is fixed

- A fix is aimed for within 7 to 14 days of the acknowledgement, sooner for a severe vulnerability.
- The fix is released as a new version of the package, and announced in its release notes and in a GitHub security
  advisory on the package's repository.
- A commit that fixes a vulnerability is prefixed with `security:`, so that it can be found and applied by those who
  cannot upgrade at once.

## Supported versions

Only the latest version of each package, on its main branch, receives security fixes. The packages are at `0.x`:
upgrade to the latest release to get a fix, or apply its `security:` commit yourself.

## Dependencies

A vulnerability in a dependency is fixed by raising the dependency's constraint once a fixed release exists. If you
find a dependency of a package with a known advisory (`composer audit` reports them), you may report it the same way.

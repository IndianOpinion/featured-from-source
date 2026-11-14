# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

Email **hello@indianopinion.org** with "featured-from-source security" in the
subject. Please include the version, a description, and steps to reproduce.
Do not open a public issue for a security problem.

We acknowledge reports within 3 working days and aim to ship a fix, or a
clear plan, within 30 days. We credit reporters in the release notes unless
they ask us not to.

## Scope

The plugin fetches remote pages and images on the server, so server-side
request forgery, file type confusion, path handling in sideloads, output
escaping in admin screens and capability or nonce checks are all in scope.

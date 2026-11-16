# Developer guide

## Runtime boundaries

The application folder is self-contained. Production ships a no-development vendor tree, compiled assets and legal notices. Build tools, tests, private configuration and workspace evidence do not belong in a deployed ZIP. Use the pinned Composer lock; do not install development packages on the host.

Keep HTTP handlers thin. Put business policy in application/domain services, database-specific operations behind infrastructure adapters, and deferred side effects in the transactional outbox. Enforce current permissions on every list, search, download and direct link. Cache entries cannot authorize requests.

## Extensions

Extensions execute trusted server code; they are not sandboxed. Declare the supported API/version range, capabilities, routes, permission keys and migration requirements in the extension manifest. Validate compatibility before enabling. Do not patch core source or mutate global request state. Password re-authentication is the core baseline; TOTP and CAPTCHA belong to the separate plugin backlog.

Use the shipped extension manifest schema and the extension loader's compatibility checks as the executable reference. For an initial extension, start with a manifest that declares no routes or capabilities, validate it, then add one named route and its explicit permission check. Supply positive and denied-access tests before exposing content.

## Themes and translations

Use namespaced Twig overrides with the default theme as fallback. Keep autoescaping enabled and pass only supported view values. SafeHtml is reserved for content already processed by the trusted renderer. Use validated CSS tokens and the native draft/preview/publish flow; preview caches must remain private. Never accept arbitrary PHP from an administrator.

Store English message IDs and reference Turkish translations in catalogs. Use ICU parameters rather than concatenating user-facing sentences. Cover empty, error, success and long-text states. Verify keyboard focus, labels, reduced motion, mixed-direction content and small screens. Pseudo-RTL fixtures exercise layout; they are not a complete Arabic translation or a manual screen-reader certification.

## Schema and delivery

Add a new checksummed migration; never rewrite an applied one. Automatic upgrades accept expand-only operations and use maintenance, checked staging, frozen recovery and bounded work. Test upgrade from an actual prior archive in addition to fresh installation. Use isolated synthetic databases for destructive test setup and scale measurements.

SEO metadata is per request and participates in render-cache identity. Sitemaps use completed opaque generations, bounded primary-key batches and current guest visibility on every response. Do not cache an authorization result across permission-version changes or derive canonical origins from client-supplied hosts.

## Minimal manifests

A metadata-only extension can begin with this extension.json. It declares no executable providers, permissions, routes or migrations. The engine range describes the extension engine contract, not the beta ZIP version. Keep its directory identity consistent with the id.

```json
{
  "id": "example/hello",
  "name": "Example hello",
  "version": "1.0.0",
  "api": 1,
  "engine": "^1.0",
  "php": "~8.4.27 || ~8.5.12",
  "license": "MIT"
}
```

A child theme's theme.json can inherit all default templates and assets. Add overrides one at a time through the documented theme pipeline; this manifest alone does not execute PHP or modify the active theme.

```json
{
  "id": "example_theme",
  "parent": "default",
  "version": "1.0.0",
  "engine": 1,
  "minimum_php": "8.4.27",
  "assets": [],
  "slots": {},
  "upstream": {}
}
```

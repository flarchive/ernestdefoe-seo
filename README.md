# SEO for Flarum 2

[![Floxum](https://floxum.com/extension/ernestdefoe/seo/badge/name)](https://floxum.com/extension/ernestdefoe/seo)
[![Version](https://floxum.com/extension/ernestdefoe/seo/badge/highest-version)](https://floxum.com/extension/ernestdefoe/seo)
[![Downloads](https://floxum.com/extension/ernestdefoe/seo/badge/downloads)](https://floxum.com/extension/ernestdefoe/seo)
[![Review](https://floxum.com/extension/ernestdefoe/seo/badge/review)](https://floxum.com/extension/ernestdefoe/seo)
[![License](https://floxum.com/extension/ernestdefoe/seo/badge/license)](https://floxum.com/extension/ernestdefoe/seo)

[![Flarum 2.0](https://img.shields.io/badge/Flarum-%5E2.0-orange)](https://flarum.org/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE.md)

SEO meta tags, OpenGraph, Twitter Cards, JSON-LD structured data, a
bundled `sitemap.xml` generator, and search-console verification for
**Flarum 2** — a maintained, v2-compatible continuation of
[`v17development/flarum-seo`](https://github.com/v17development/flarum-seo).

## What's different from the upstream

The upstream extension targets Flarum 1.x and the partial Flarum 2 port
in `v17development/flarum-seo` left the API layer broken — it won't
even boot on Flarum 2 because four core classes (`AbstractSerializer`,
`AbstractListController`, `AbstractShowController`, `AbstractDeleteController`,
`BasicDiscussionSerializer`, `ForumSerializer`) were removed when the
core API was rewritten around `Resource` + `Endpoint` + `Schema`.

This fork:

- **Boots and runs on Flarum 2.x.** Full v2-API migration: the v1
  serializer + 4 controllers became one `SeoMetaResource` with declarative
  `Endpoint::make()` + `Schema\*` fields + writable allowlist. v1
  `ApiSerializer` + `ApiController` extenders replaced with
  `ApiResource(...)->fields()` callbacks mounting fields onto
  `DiscussionResource` and `ForumResource`. Eager-load via
  `endpoint(...)->eagerLoad('seoMeta')` to prevent N+1 on the discussion list.
- **Migration bug fix.** Upstream's
  `2023_02_01_create_seo_meta_table.php` used
  `$table->string('object_type', 65535)` which silently coerces to
  `MEDIUMTEXT` and crashes the subsequent unique-index migration with
  "BLOB/TEXT column used in key specification without a key length".
  Reduced to `varchar(60)` so the unique index lands on first run.
- **Bundled `sitemap.xml` generator.** Auto-detects `fof/sitemap` — if
  installed, our controller 404s and defers to fof's. Otherwise generates
  a single-file sitemap (capped at 50k URLs per the protocol limit) with
  `<lastmod>` from `last_posted_at`, `<changefreq>` derived from
  posting recency (hourly→yearly), and `<priority>` weighted by
  comment count + recency. Visibility-scoped to `Guest` so restricted
  tags / private groups never leak into the public sitemap. 6-hour
  cache via the framework cache.
- **Search-console verification meta tags.** Four admin-configurable
  settings: Google Search Console, Bing Webmaster, Yandex Webmaster,
  Pinterest. Tokens are charset-restricted before interpolation so an
  admin can't (intentionally or by typo) inject markup into the head.
  Defensive try/catch around the injector so a verification-tag bug
  can never block a page render.
- **`robots.txt` always references the sitemap.** Upstream only added
  the `Sitemap:` directive when `fof-sitemap` was enabled. Since we
  now always serve a sitemap (ours or theirs), the reference is
  unconditional.
- **Hardened `UploadSocialMediaImageController`.** Full §11 upload
  pipeline: size cap (4 MB), extension allowlist (png/jpg/jpeg/webp),
  `finfo`-based MIME re-detection, server-generated filename, PSR-3
  logger wrapped in try/catch.
- **`SeoMetaResource::find()` handles dash-separated polymorphic
  lookups.** The admin frontend sends `GET /api/seo_meta/discussions-42`
  to find-or-create the SeoMeta row for a subject; the v2 Resource's
  Show endpoint normally requires a numeric id, so `find()` intercepts
  the `{type}-{id}` form and short-circuits to
  `SeoMeta::findByObjectTypeOrCreate`. Allowlist restricts type to
  `discussions`/`users`/`tags`/`pages`.

## What it does on every page

| Surface | What gets emitted |
|---|---|
| **OpenGraph** | `og:site_name`, `og:type`, `og:title`, `og:description`, `og:url`, `og:image` |
| **Twitter Card** | `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`, `twitter:url` |
| **Robots directives** | `index/noindex`, `follow/nofollow`, `noarchive`, `noimageindex`, `nosnippet` (per-route + per-row override via SeoMeta) |
| **JSON-LD Schema.org** | `DiscussionForumPosting` (with `publisher` Organization + `author` Person), `BreadcrumbList`, `WebSite` (with `SearchAction` for the sitelinks search box). `QAPage` instead of `DiscussionForumPosting` when `fof/best-answer` flags the thread answered. |
| **Article metadata** | `article:published_time`, `article:updated_time` |
| **Canonical** | `<link rel="canonical">` on every route |
| **Verification** | `google-site-verification`, `msvalidate.01`, `yandex-verification`, `p:domain_verify` (when admin has configured the token) |

## Quick start

```bash
composer require ernestdefoe/seo
php flarum migrate
php flarum cache:clear
```

Enable from **Admin → Extensions → SEO**. Open the SEO settings tab to
configure:

- **Default fallback meta** — site description, keywords, default
  social-media image.
- **Per-row overrides** — open any discussion / tag / page and click
  the SEO drawer to override the auto-generated title / description /
  image / robots directives for that specific subject.
- **Search Console Verification** — paste the verification tokens from
  Google / Bing / Yandex / Pinterest; meta tags appear on every page
  on save.

## Composer suggests

- `flarum/tags` — when present, the `Tags` page renderer emits proper
  category metadata + breadcrumb schema.
- `fof/pages` — when present, the `Pages` page renderer treats pages
  as `WebPage` schema with publisher attribution.
- `fof/best-answer` — when present, discussions flagged as answered
  switch from `DiscussionForumPosting` to `QAPage` schema, which
  search engines render as a Q&A rich snippet.
- `fof/sitemap` — when present, owns `/sitemap.xml` with sitemap-index
  pagination for forums larger than 50k URLs. We auto-defer to it.
- `ernestdefoe/og-image` — when present, auto-generates per-discussion
  social images.

## Compatibility & status

- **Flarum**: 2.0+ (requires `flarum/core: ^2.0`).
- **PHP**: 8.3+.
- **MySQL / MariaDB**: any version that supports `utf8mb4_unicode_ci`
  (every version Flarum supports).
- **License**: MIT.

## Support

Questions, bug reports, and feature requests:

- **Support forum:** https://ernestdefoe.online
- **Issues:** https://github.com/ernestdefoe/seo/issues

## License & credits

[MIT](LICENSE.md). Upstream credit:
[`v17development/flarum-seo`](https://github.com/v17development/flarum-seo)
— this extension is a maintained Flarum 2 fork. Core architecture
(SeoMeta table, Page drivers, Content injector, Schema.org payload
shape) preserved from upstream.

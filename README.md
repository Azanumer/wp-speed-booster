# WP Speed Booster

A lightweight WordPress plugin with the five frontend speed tweaks that actually matter — no page-builder lock-in, no "premium upsell", no admin bloat.

## What it does

| Tweak | How |
|---|---|
| **Defer scripts** | Adds `defer="defer"` to frontend `<script>` tags (jQuery core/migrate and your excluded handles are never deferred) |
| **Async CSS** | Loads stylesheets via `rel="preload"` + onload swap, with a `<noscript>` fallback so no-JS visitors still get styled |
| **Lazy iframes** | Adds `loading="lazy"` to `<iframe>` tags in content and text widgets (maps, embeds, videos) |
| **Strip `?ver=`** | Removes the `ver` query arg from static asset URLs so CDNs and browser caches hit on the canonical URL |
| **Preconnect** | Outputs `<link rel="preconnect">` for the hosts you list (your CDN, font provider, etc.) |

Everything applies to the **frontend only** — admin, login page, AJAX, cron and the customizer are untouched.

## Install

1. Copy `wp-speed-booster.php` and `uninstall.php` into `wp-content/plugins/wp-speed-booster/`
2. Activate the plugin
3. Go to **Settings → Speed Booster**, toggle the tweaks, add your CDN host to *Preconnect hosts*

## Developer hooks

| Hook | Type | What it does |
|---|---|---|
| `wsb_excluded_handles` | filter `(array $excluded, string $handle)` | Add handles to the never-optimize list |
| `wsb_preconnect_hosts` | filter `(array $hosts)` | Add preconnect hosts in code |

```php
// Example: never async-load the critical above-the-fold stylesheet.
add_filter( 'wsb_excluded_handles', function ( $excluded, $handle ) {
    $excluded[] = 'my-critical-css';
    return $excluded;
}, 10, 2 );
```

## Files

- `wp-speed-booster.php` — the plugin
- `uninstall.php` — removes the `wsb_settings` option

MIT licensed.

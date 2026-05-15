# PerryLabs Cookie Notice

Granular cookie consent for WordPress with category-based opt-in, **Google Consent Mode v2**, WordPress script gating, geo-targeting, and a server-side audit log. No external dependencies.

Internal codename: **Cookie Monster**.

## What it actually does

A consent banner that just sets a cookie does nothing to stop third-party scripts from running before the user consents — which is the actual GDPR violation. This plugin **blocks** scripts until consent is granted:

- **Registered scripts** (GA4, GTM, FB Pixel, etc.) are injected client-side only after consent.
- **Enqueued WP scripts** that other plugins/themes register are rewritten to `type="text/plain"` and reactivated after consent.
- **Google Consent Mode v2** signals are emitted in `<head>` so Google tags respect consent even if they happen to load before our JS.

## Features

- 4-category consent: Strictly Necessary, Analytics, Marketing, Other
- Equal-prominence **Accept All / Reject All** buttons (2022+ EU compliance)
- **Iframe / embed blocker** — YouTube, Vimeo, Maps, X, Facebook, Instagram, SoundCloud, Spotify
- Preferences modal with per-category toggles, focus trap, ESC handling
- Light / Dark / Auto theme (follows `prefers-color-scheme`)
- 5 admin-managed Quick Add presets (GA4, GTM, Facebook Pixel, LinkedIn Insight, HubSpot) with **smart ID substitution**
- Gate any `wp_enqueue_script` / `wp_enqueue_style` handle from the admin or via API
- Geo-targeted compliance: GDPR (EU/UK), CCPA (California), or both
- Server-side audit log with hashed IP/UA
- Policy versioning — bump to force every visitor to re-consent
- Shortcodes: `[plcn_settings_link]`, `[plcn_cookie_policy]`, `[plcn_ccpa_optout]`
- Native WP color picker, "Reset my consent" testing button
- Adopts the shared **PerryLabs branding tokens** — consistent look across all PerryLabs plugins
- ~9KB JS, vanilla, no dependencies
- GPL-2.0-or-later

## Installation

### As a Git submodule (recommended for managed projects)

```bash
cd your-wordpress-project/
git submodule add https://github.com/Jasonmperry/perrylabs-cookie-notice.git wp-content/plugins/perrylabs-cookie-notice
git commit -m "Add PerryLabs Cookie Notice plugin as submodule"
```

After cloning a project that includes this submodule:

```bash
git submodule update --init --recursive
```

### Manual installation

1. Download or clone this repository into `wp-content/plugins/perrylabs-cookie-notice/`.
2. Activate the plugin from the WordPress admin (Plugins > Installed Plugins).
3. Configure under Settings > Cookie Notice.

## Developer API

### Template tags

```php
// Conditional rendering / logic.
if ( plcn_has_consent( 'analytics' ) ) {
    // Run code that depends on analytics consent.
}

// Register a script/pixel programmatically (alternative to admin UI).
plcn_register_script( 'my-pixel', array(
    'label'    => 'My Tracking Pixel',
    'category' => 'marketing',
    'src'      => 'https://example.com/pixel.js?id=ABC123',
    'attrs'    => array( 'async' ),
    'load_in'  => 'head',
) );

// Gate a script that another plugin/theme enqueues.
add_action( 'wp_enqueue_scripts', function () {
    plcn_gate_script( 'some-analytics-handle', 'analytics' );
    plcn_gate_style( 'fancy-tracker-styles', 'marketing' );
}, 100 );
```

### Shortcodes

| Shortcode | Use |
|-----------|-----|
| `[plcn_settings_link text="Cookie Settings"]` | Button that re-opens the preferences modal. Drop it in your footer. |
| `[plcn_cookie_policy]` | Auto-generated table of registered scripts grouped by category. Good for your privacy / cookie policy page. |
| `[plcn_ccpa_optout text="Do Not Sell My Personal Information"]` | CCPA-required opt-out link. Opens preferences pre-set to reject marketing. |

### CSS hook

Any element with class `plcn-open-preferences` will trigger the preferences modal on click — useful for theme footer menus.

## Compliance modes

| Mode  | Behavior |
|-------|----------|
| `none` | Simple notice. All scripts load. Use only if you have no analytics/marketing pixels. |
| `gdpr` | Explicit opt-in. Optional scripts blocked until user consents. |
| `ccpa` | Implicit opt-in (opt-out model). Scripts load by default; user can reject. |
| `both` | Detect visitor region via IP and apply the right mode. |

## Google Consent Mode v2

When enabled, the plugin emits:

1. **Default signal in `<head>`** (before any other scripts) — sets all storage to `denied` under GDPR, `granted` under CCPA/none, or the user's actual choice if they've already decided.
2. **Update signal** when the user makes a choice — granular grants for `ad_storage`, `ad_user_data`, `ad_personalization`, `analytics_storage`, `personalization_storage`.

This is required for Google Ads in the EEA and recommended whenever you use Google Analytics.

## Audit log

The `wp_plcn_consent_log` table stores every consent decision:

| Field | Notes |
|-------|-------|
| `recorded_at` | UTC timestamp |
| `policy_version` | Active policy version when consent was given |
| `categories` | JSON-encoded grants by category |
| `geo_region` | `eu`, `california`, `other` |
| `ip_hash` | SHA-256(`IP + wp_salt('nonce')`) |
| `ua_hash` | SHA-256(`UA + wp_salt('nonce')`) |
| `action` | `accept-all`, `reject-all`, `save-preferences` |

Raw IP/UA are never stored. Disable the toggle in Settings → General if you don't want any record kept.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) (or the GNU site) for the full text.

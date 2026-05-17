=== PerryLabs Cookie Notice ===
Contributors: perrylabs
Tags: cookies, consent, gdpr, ccpa, privacy, google consent mode, cookie banner
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 3.4.0
Tested up to: 6.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Granular cookie consent with category-based opt-in, Google Consent Mode v2, WordPress script gating, geo-targeting, and a server-side audit log. No external dependencies.

== Description ==

PerryLabs Cookie Notice ("Cookie Monster") is a lightweight, no-bloat consent banner that actually blocks third-party scripts until consent is granted — the way GDPR requires.

= What it does =

* **Category-based consent** — Strictly Necessary, Analytics, Marketing, Other. Per-category toggles in a preferences modal.
* **Iframe / embed blocking** — YouTube, Vimeo, Google Maps, X/Twitter, Facebook, Instagram, SoundCloud, Spotify embeds get a click-to-load placeholder until consent.
* **Equal-prominence Accept / Reject buttons** — both styled as primary actions (compliant with 2022+ EU rulings against dark patterns).
* **Google Consent Mode v2** — emits the `default` signal in <head> before any other Google tags load, then sends `update` after the user decides. Required for Google Ads in the EEA.
* **Gated WordPress assets** — gate any `wp_enqueue_script` / `wp_enqueue_style` handle behind a consent category. The plugin rewrites the rendered `<script>` tag to `type="text/plain"` so the browser does not execute it until consent is given.
* **Script & pixel registry** — paste GA4, GTM, Facebook Pixel, LinkedIn Insight, HubSpot, or any custom script directly in the admin. One-click presets included.
* **Geo-targeted compliance** — set "Both" mode and we'll apply GDPR in the EU/UK, CCPA in California, neither elsewhere. Uses a cached IP-API lookup.
* **Server-side audit log** — every consent decision recorded with timestamp, policy version, region, and hashed IP/UA. Defensible record for compliance audits.
* **Policy versioning** — bump the version when your cookies change and every visitor is re-prompted on their next visit.
* **Shortcodes** — `[plcn_settings_link]`, `[plcn_cookie_policy]`, `[plcn_ccpa_optout]`.
* **Accessible** — focus trap, ESC closes modal, ARIA labels, keyboard-friendly.
* **No external dependencies** — vanilla JS, ~7KB minified equivalent.

= Developer hooks =

PHP template tags:

`if ( plcn_has_consent( 'analytics' ) ) { /* run analytics-dependent code */ }`

`plcn_register_script( 'my-pixel', array( 'category' => 'marketing', 'src' => '...' ) );`

`plcn_gate_script( 'wp-handle-from-another-plugin', 'analytics' );`

`plcn_gate_style( 'some-style-handle', 'marketing' );`

== Installation ==

1. Upload the plugin to `/wp-content/plugins/perrylabs-cookie-notice/` or install via the WordPress plugin directory.
2. Activate via Plugins > Installed Plugins.
3. Visit Settings > Cookie Notice to configure.

== Frequently Asked Questions ==

= Do I need Google Consent Mode v2? =

If you run Google Ads to EEA users, yes — Google requires it. Enable the toggle on the General tab. If you only run Google Analytics, it's still strongly recommended for cookieless measurement when consent is denied.

= How is this different from a banner that just sets a cookie? =

A banner that sets a cookie does nothing to stop third-party scripts from running before the user consents — which is the actual GDPR violation. This plugin rewrites script tags to `type="text/plain"` so the browser will not execute them, then activates them after consent. It also emits Google Consent Mode signals before any Google tag has a chance to run.

= How do I gate a script that another plugin enqueues? =

Either use the "Gated Handles" tab in admin (enter the WordPress handle and pick a category) or call `plcn_gate_script( $handle, $category )` from your theme's `functions.php` or a small mu-plugin.

= What about CCPA "Do Not Sell"? =

Use the `[plcn_ccpa_optout]` shortcode to render the required link. It opens the preferences modal pre-set to reject marketing.

= Does it work with caching plugins? =

Yes. The default state is rendered server-side (in `<head>`) so cached pages stay valid. The consent decision lives in a cookie, which most page caches correctly key on or ignore for the consent JS.

= Where is the audit log stored? =

Custom table `wp_plcn_consent_log` (prefix-aware). IP and user agent are SHA-256 hashed with `wp_salt('nonce')` — you can audit without exposing raw PII. Disable the toggle if you don't want any record kept.

== Screenshots ==

1. Banner with equal-prominence Accept All / Reject All / Customize.
2. Preferences modal with per-category toggles.
3. Admin General tab — compliance mode, Google Consent Mode, audit log.
4. Scripts & Pixels — Quick Add presets for GA4, GTM, Facebook, LinkedIn, HubSpot.
5. Gated Handles — gate WP-enqueued scripts behind consent.
6. Consent Log — audit trail of consent decisions.

== Changelog ==

= 3.4.0 =
* New: **Bundled logo asset** — the admin-screen logo now ships with the plugin (`includes/branding/assets/perrylabs-logomark.png`). The S3-hosted logo is used only as a fallback if the bundled file is somehow missing. Plugin no longer has an external runtime asset dependency.
* New: **Branding is opt-in.** Default install shows a neutral "Cookie Notice Settings" header and a compact "Plugin by PerryLabs" attribution line. Toggle "Admin branding" on the General tab to enable the full PerryLabs logo header + footer (useful on PerryLabs-managed sites).
* Updated tested-up-to to 6.8.

= 3.3.1 =
* New: `wp plcn scan` WP-CLI command (--format=json|csv|pretty).
* New: per-script "Cookies" field in the Scripts admin form — one cookie per line as `name | purpose | duration | provider`. Pre-filled from the known-cookie DB when picking a preset.

= 3.3.0 =
* New: **Cookie scanner** — crawl the home page (and any additional URLs you list) and surface every cookie that gets set, matched against a known-cookie database for automatic service/category attribution. Detects tracker signatures (gtag, fbevents, GTM, HubSpot, Stripe.js, etc.) in HTML even when cookies are set client-side.
* New: **Per-cookie metadata** — each registered script now carries an explicit list of cookies (name, purpose, duration, provider) which the `[plcn_cookie_policy]` shortcode renders as a per-cookie table.
* New: **Known-cookie database** (`PLCN_Cookie_DB`) — GA4, GTM, Facebook Pixel, LinkedIn Insight, HubSpot, Stripe, Cloudflare, YouTube, Vimeo, WordPress core. Regex name patterns supported.
* New: **Privacy Policy page generator** — Tools tab button creates (or refreshes) a draft "Cookie Policy" WP page populated with the cookie list, settings link, CCPA opt-out, and DNT/GPC disclosure.
* New: **Custom categories** — admins can define additional consent categories beyond the built-in Strictly Necessary / Analytics / Marketing / Other (e.g. Personalization, Social, Functional).
* New: **Translation-ready** — `load_plugin_textdomain`, seed `.pot` file in `languages/`, and `tools/make-pot.sh` for regenerating from source via WP-CLI.
* New: Stripe.js added to Quick Add presets (category: required).

= 3.2.0 =
* New: every user-facing string is admin-customizable on a dedicated **Messages** tab (banner title, message, button labels, category labels, modal copy, embed placeholder, CCPA opt-out) — good defaults preserved.
* New: **Privacy Policy URL** setting auto-injects a styled "privacy policy" link into the banner message.
* New: honor **Do Not Track** and **Global Privacy Control** signals — banner is skipped, scripts blocked, Google Consent Mode set to denied. On by default.
* New: **URL skip patterns** — exclude the banner from `/checkout/*`, `/login/*`, etc. via a textarea on the Advanced tab.
* New: **Custom CSS** textarea for theming without forking the plugin.
* New: **REST API** at `/wp-json/plcn/v1/` — `GET/POST /consent`, `GET /policy`, `GET /scripts` (admin). For headless WP.
* New: **WP-CLI** commands — `wp plcn settings export|import`, `wp plcn policy bump`, `wp plcn log export|clear`.
* New: **Settings JSON export/import** in admin (Tools tab). Clone configuration across staging/production.
* New: **CSV export** of the consent log (Tools/Log tab and via WP-CLI).
* New: **Live banner preview** on the General tab — see colors/messages before saving.
* New: **Capability filter** `plcn_manage_capability` lets admins delegate plugin management to a custom role.
* New: **String override filter** `plcn_string` for programmatic overrides without touching admin.

= 3.1.1 =
* New: "Skip for admins" setting (default on) — hides the banner from logged-in admin users, since the WP login flow doesn't preserve front-end cookies and admins otherwise re-see the banner on every login. Non-admin logged-in users still see it.

= 3.1.0 =
* New: Embed blocker for YouTube, Vimeo, Google Maps, X/Twitter, Facebook, Instagram, SoundCloud, Spotify. Replaces iframes with a click-to-load placeholder until consent is granted; auto-loads once the relevant category is consented to.
* New: Smart preset ID field — one "Your service ID" input substitutes into the preset snippet on save. No more hand-editing `%s` placeholders.
* New: WordPress native color picker for background and accent colors.
* New: Light / Dark / Auto theme for the preferences modal (Auto follows `prefers-color-scheme`).
* New: "Reset my consent" button on a Tools tab for testing.
* New: Adopts the shared PerryLabs branding tokens (color, type, logos) — same look across all PerryLabs plugins.
* Changed: Admin reorganized into General / Scripts & Pixels / Gated Handles / Embed Blocker / Consent Log / Tools tabs.
* Fixed: Background and accent colors now hex-validated via `sanitize_hex_color()`.

= 3.0.0 =
* New: Google Consent Mode v2 support (default + update signals).
* New: Gate WordPress-enqueued scripts and styles via `plcn_gate_script()` / `plcn_gate_style()` and the new admin tab.
* New: Server-side audit log (custom DB table, hashed IP/UA).
* New: Policy versioning — bump to force re-consent across all visitors.
* New: Shortcodes `[plcn_settings_link]`, `[plcn_cookie_policy]`, `[plcn_ccpa_optout]`.
* New: Focus trap, ESC key handling, ARIA improvements.
* Changed: "Reject Optional" → "Reject All" with primary-button styling for equal prominence (EU compliance).
* Changed: Default message reworded for clarity.

= 2.0.0 =
* Multi-category consent (Required / Analytics / Marketing / Other).
* Script & pixel registry with presets (GA4, GTM, Facebook, LinkedIn, HubSpot).
* Geo-targeted compliance mode (GDPR / CCPA / Both).
* Preferences modal with per-category toggles.

= 1.0.0 =
* Initial release — simple implied-consent notice.

== Upgrade Notice ==

= 3.4.0 =
Bundles the admin logo locally (no external runtime dependency) and makes PerryLabs branding opt-in via a new General-tab toggle. Existing installs that want the branded look can enable it under Settings → Cookie Notice → General → Admin branding.

= 3.3.0 =
Adds the cookie scanner, per-cookie metadata, privacy policy page generator, custom categories, and translation readiness. Settings preserved.

= 3.2.0 =
Enterprise hardening: every string customizable, REST API, WP-CLI, CSV log export, JSON settings export/import, DNT/GPC honor, URL skip patterns, custom CSS, capability filter, live admin preview. Settings preserved.

= 3.1.1 =
Adds a "skip for admins" toggle so logged-in admins don't see the banner on every login (WP's login flow doesn't preserve front-end cookies).

= 3.1.0 =
Adds iframe embed blocking (YouTube/Vimeo/Maps/X/FB/IG/SoundCloud/Spotify), smart preset ID substitution, WP color picker, light/dark theme, and a Tools tab. Settings are preserved.

= 3.0.0 =
Major release adding Google Consent Mode v2, WP script gating, audit log, and equal-prominence Accept/Reject buttons (a 2022+ EU compliance fix). Settings are preserved.

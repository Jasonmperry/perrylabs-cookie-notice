# PerryLabs Cookie Notice

Lightweight cookie consent banner for WordPress with implied consent. No bloat, no external dependencies.

## Features

- Inline CSS/JS -- zero additional HTTP requests
- Admin settings page (Settings > Cookie Notice)
- Configurable message, button text, colors, position, and cookie expiry
- Accessible (`role="dialog"`, `aria-live`)
- Mobile-responsive
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

## Settings

| Option | Default |
|--------|---------|
| Enable/Disable | Enabled |
| Message | "We use cookies..." |
| Button Text | "Got it" |
| Cookie Expiry | 365 days |
| Background Color | `#111` |
| Button Color | `#ffb25d` |
| Position | Bottom |

## Filters

- `plcn_message` -- Override the notice message programmatically.
- `plcn_button_text` -- Override the button label programmatically.

## License

GPL-2.0-or-later

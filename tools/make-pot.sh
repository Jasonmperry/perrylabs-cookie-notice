#!/usr/bin/env bash
#
# Regenerate languages/perrylabs-cookie-notice.pot using WP-CLI i18n.
#
# Requirements:
#   - WP-CLI installed (https://wp-cli.org/) with the i18n-command package.
#     Install: wp package install wp-cli/i18n-command:^2
#
# Usage:
#   bash tools/make-pot.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if ! command -v wp >/dev/null 2>&1; then
    echo "wp-cli not found in PATH. Install from https://wp-cli.org/" >&2
    exit 1
fi

mkdir -p languages

wp i18n make-pot . languages/perrylabs-cookie-notice.pot \
    --slug=perrylabs-cookie-notice \
    --domain=perrylabs-cookie-notice \
    --exclude=tools,languages,node_modules,vendor \
    --headers='{"X-Domain":"perrylabs-cookie-notice"}'

echo "Wrote languages/perrylabs-cookie-notice.pot"

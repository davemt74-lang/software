#!/usr/bin/env bash
set -euo pipefail

# Each workflow installs its pinned Playwright package before calling this.
playwright=./node_modules/.bin/playwright
"$playwright" install chromium

check_chromium_launch() {
    node --input-type=module <<'JS'
import { chromium } from 'playwright';

// Most tests use the headless shell. Extension tests use full Chromium.
for (const options of [{}, { channel: 'chromium' }]) {
    const browser = await chromium.launch({ headless: true, timeout: 30_000, ...options });
    try {
        const page = await browser.newPage();
        await page.goto('about:blank');
    } finally {
        await browser.close();
    }
}
JS
}

# Hosted runners usually have the necessary libraries. Use apt if a launch
# shows that the runner needs additional browser dependencies.
if ! check_chromium_launch; then
    "$playwright" install-deps chromium
    check_chromium_launch
fi

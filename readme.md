# Flinkform Pro

Paid add-on for the free [Flinkform](https://wordpress.org/plugins/flinkform/)
form plugin. Separate plugin, **not** distributed via WordPress.org — sold with a
license key and auto-updated from a dedicated endpoint.

## Architecture

Flinkform Pro docks onto the free core through its **bridge layer** (see
`includes/Bridge/README.md` in the free core). It never modifies core files; it
only hooks the published, frozen extension points:

| Hook | Purpose |
|------|---------|
| `flinkform_pro_features` (filter) | Advertises Pro capabilities so the core's `Features` façade flips on |
| `flinkform_register_modules` (action) | Wires Pro subsystems once the core has booted |
| `flinkform_block_dirs` (filter) | Registers Pro blocks / field types from this plugin's own build dir |
| `flinkform_spam_providers` (filter) | Registers external CAPTCHA providers (Turnstile, hCaptcha, reCAPTCHA) |

The hard dependency on the free core is enforced two ways:
1. `Requires Plugins: flinkform` header (WordPress 6.5+).
2. A runtime version guard (`FLINKFORM_PRO_MIN_CORE`) that pauses Pro and shows an
   admin notice if the core is missing or too old to expose the bridge.

## Status

- **M-b (current):** near-empty scaffold. Proves the dock + dependency check
  work end-to-end. No modules moved yet.
- **M-c (next):** move Conditional Logic, Multi-Step, Webhooks, CSV Export and
  SMTP out of the free core into this add-on, wired via the bridge.

## Capabilities (per the Free/Pro matrix)

Conditional logic · Multi-step forms · Webhooks · Submissions CSV export ·
SMTP (Basic Auth + OAuth2) · External CAPTCHA providers.

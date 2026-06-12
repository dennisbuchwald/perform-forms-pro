# Flinkform Pro

Paid add-on for the free [Flinkform](https://wordpress.org/plugins/flinkform/)
form plugin. Separate plugin, **not** distributed via WordPress.org — sold with a
license key and auto-updated from a dedicated endpoint (licensing integration:
planned via Freemius, not yet wired).

## Features (v0.4.0)

- **SMTP delivery** — route all `wp_mail()` through a configured SMTP provider.
  7 provider presets (Gmail, Outlook, SendGrid, Mailgun, Brevo, Postmark,
  Amazon SES), AES-256-encrypted credentials, conflict detection with other
  SMTP plugins, test-email diagnostics.
- **SMTP send log** — per-mail history (recipient, subject, sent/failed with
  the exact PHPMailer error). GDPR-lean: no mail bodies, configurable
  retention (default 30 days), covered by the personal-data eraser.
- **Webhooks** — per-form webhooks with JSON/form-encoded payloads, custom
  headers, field mapping, conditions, cron-driven dispatch with retries and a
  full delivery log. SSRF-hardened.
- **CSV export** — export filtered submissions from the admin list.
- **Custom CSS** — per-form CSS panel in the editor.
- **File Upload field** — per-field type allow-list (ext+content sniffing),
  size cap, randomised storage in a script-execution-blocked uploads
  subdirectory, automatic file deletion with the submission (GDPR cascade).
- **Newsletter integrations** — Brevo, Mailchimp and CleverReach signups
  with a mandatory consent-field gate, async dispatch and double opt-in.

### Roadmap (next)

- External CAPTCHA providers (Turnstile, hCaptcha) for operators who want them
- SMTP OAuth2 (Google Workspace, Microsoft 365)
- Stripe payments
- Licensing + update delivery via Freemius

## Architecture

Flinkform Pro docks onto the free core through its **bridge layer** (see
`includes/Bridge/README.md` in the free core). It never modifies core files; it
only hooks the published, frozen extension points:

| Hook | Purpose |
|------|---------|
| `flinkform_pro_features` (filter) | Advertises Pro capabilities so the core's `Features` façade flips on |
| `flinkform_register_modules` (action) | Wires Pro subsystems once the core has booted |
| `flinkform_block_dirs` (filter) | Registers Pro blocks / field types from this plugin's own build dir |
| `flinkform_field_blocks` + `flinkform_process_submission` (filters) | Register add-on field types incl. file handling |
| `flinkform_spam_providers` (filter) | Registers external CAPTCHA providers |

The hard dependency on the free core is enforced two ways:
1. `Requires Plugins: flinkform` header (WordPress 6.5+).
2. A runtime version guard (`FLINKFORM_PRO_MIN_CORE`, currently 0.4.0) that
   pauses Pro and shows an admin notice if the core is missing or too old to
   expose the bridge.

## Development

```bash
npm install   # install dependencies
npm run build # production build of the editor bundle
```

PHP classes follow PSR-4 under `includes/` (namespace `FlinkformPro\`).
Pro database tables (webhooks, webhook deliveries, mail log) are created via
`Database\Schema` and dropped only on uninstall — never on deactivation, so a
license lapse never destroys customer data.

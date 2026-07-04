# ARK Telemetry Server

This repository contains the backend server used by the ARK Plugin for OJS.

It provides NAAN validation, statistics collection, and a public dashboard for the ARK Plugin ecosystem.

---

## Purpose

This server supports the ARK Plugin by:

- Validating NAANs against the n2t.net registry
- Collecting anonymous usage statistics from OJS journals
- Displaying aggregate data on a public dashboard
- Providing an API endpoint for badges

---

## How It Is Used

The server is installed at:

https://revistacarnaubais.com.br/ark-telemetry/

It receives requests from the ARK Plugin installed on OJS journals:

1. Journals validate their NAAN via /validate.php
2. Journals send anonymous statistics via /collect.php
3. The public dashboard displays aggregate data at /stats.php
4. The badge API is available at /api.php

---

## Endpoints

- POST /validate.php - NAAN validation and token generation
- POST /collect.php - Statistics collection from OJS journals
- GET /stats.php - Public dashboard with charts
- GET /api.php - JSON endpoint for badges (24h cache)

---

## Security

- Rate limiting with exponential backoff
- Temporary tokens (5-minute expiry, one-time use)
- identity.txt verification
- Private key authentication
- Consent audit trail (LGPD/GDPR)

---

## License

GNU General Public License v2.0

---

## Links

- ARK Plugin: https://github.com/lurymorais/ark-plugin
- Public Dashboard: https://revistacarnaubais.com.br/ark-telemetry/stats.php

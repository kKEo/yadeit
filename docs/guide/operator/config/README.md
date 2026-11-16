# Part III: Configuration reference

Every setting an operator can change, with its default and what happens when
it is wrong. Settings are environment variables, read by the files in
`backend/config/`. In Docker they come from `.env.production`; on a host,
from `backend/.env`.

> **Note.** Configuration is cached when the container starts
> (`php artisan config:cache`). After changing a value, restart the services
> that read it: `dc up -d` recreates the changed ones. Values starting with
> `VITE_`, plus `OPERATOR_NAME` and `OPERATOR_EMAIL`, are built into the web app:
> rebuild the image with `dc up -d --build`.

- [Environment variables](environment.md): the complete list, grouped by area.
- [Optional features](features.md): AI, paid plans, realtime, OAuth apps, webhooks, the Traffic lab.
- [Limits and plans](limits.md): plan limits, upload size, rate limits and other fixed numbers.

# WebAnyOne Plugin Manager

Open-source Mautic plugin manager with signed catalogues, exact compatibility certification, asynchronous Composer jobs, native administration screens, OAuth2 API and optional community features.

**Development build, not production-qualified.** Native integration is checked only against the exact Mautic/PHP tuples in [the qualification report](docs/QUALIFICATION.md). Initial Composer installation of the Manager is verified on three fresh instances. Deployment of other plugins, coherent restoration and representative Plesk hosting remain unqualified. The initial signed catalogue has no certified plugin releases.

Install using Composer only in a disposable Composer-managed Mautic project. Before Packagist publication:

```sh
composer config repositories.webanyone-manager vcs https://github.com/WebAnyOne/plugin-manager
composer require webanyone/mautic-plugin-manager:dev-main --no-scripts
php bin/console mautic:plugins:reload --env=prod --no-debug
php bin/console cache:clear --env=prod --no-debug
```

Run Composer from the project root and the console from the application root (the Composer project root in recommended-project layouts). Add a single every-minute cron invoking `wao:plugins:process-queue`. Installation buttons stay disabled without a verified restore provider, a recent worker heartbeat and an exactly certified release. Automatic updates default to approved patches and also require deterministic rollout eligibility. Manager self-updates remain manual.

Telemetry starts disabled. Its consent is independent of newsletter subscription. Neither declining nor lacking an account limits open-source features. No arbitrary repositories, shell commands, Composer options or ZIP uploads are accepted. Source distribution archives used internally by Composer are signature-authorized and hashed first.

[Installation](docs/INSTALLATION.md) · [Security](docs/SECURITY.md) · [API](docs/API.md) · [Recovery](docs/RECOVERY.md) · [Telemetry](docs/TELEMETRY.md) · [Publishing](docs/PUBLISHING.md).

GPL-3.0-only, © 2026 WebAnyOne.

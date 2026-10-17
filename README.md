# WebAnyOne Plugin Manager

Gestionnaire open source de plugins Mautic : catalogue signé, certification des combinaisons réellement testées, diagnostic technique, opérations Composer différées, API OAuth2 et fonctionnalités communautaires facultatives.

**État : version de développement `0.1.0-dev`. Le socle est vérifié sur les laboratoires Mautic 4.4.13, 5.2.11 et 6.0.9. L’installation initiale du Manager par Composer est vérifiée sur trois instances vierges ; le déploiement d’autres plugins, la restauration complète et Plesk ne sont pas qualifiés. Ne pas utiliser en production.** Aucune release de plugin n'est présentée comme certifiée dans le catalogue initial. Les autres versions mineures ne sont pas supportées par déduction.

Aucun compte WebAnyOne, abonnement ou paiement n'est nécessaire. Pas d'installation par ZIP, de dépôt libre ni de commande Composer fournie par l'API. La télémétrie est désactivée avant consentement explicite.

## Installation de développement

Le package n'est pas encore publié sur Packagist. Dans une instance jetable **gérée par Composer**, enregistrer ce dépôt puis demander sa branche de développement :

```sh
composer config repositories.webanyone-manager vcs https://github.com/WebAnyOne/plugin-manager
composer require webanyone/mautic-plugin-manager:dev-main --no-scripts
php bin/console mautic:plugins:reload --env=prod --no-debug
php bin/console cache:clear --env=prod --no-debug
```

Utiliser la racine du projet pour Composer et la racine de l'application pour `bin/console` ; avec `recommended-project`, `bin/console` se trouve à la racine Composer, au-dessus de `docroot`. Choisir le PHP correspondant à l'instance. Mautic 4 : PHP 7.4.33 testé ; Mautic 5/6 : PHP 8.1.31 et 8.3.14 examinés selon la matrice livrée. Le plugin ne transforme pas une installation ZIP de Mautic en projet Composer.

Le menu **WebAnyOne Plugin Manager** est disponible aux administrateurs. Le catalogue signé embarqué est immédiatement consultable ; il contient des fiches descriptives sans releases certifiées. L'assistant facultatif comporte exactement deux écrans. Les fonctions restent accessibles via les onglets.

## Une seule tâche planifiée

```cron
* * * * * /chemin/absolu/php /chemin/application/bin/console wao:plugins:process-queue --env=prod --no-debug
```

Le worker synchronise le catalogue, vérifie les capacités locales et traite la file. La consultation n'exécute jamais Composer. Les boutons d'installation restent désactivés sans heartbeat récent, certification exacte et restauration vérifiée. Sur Plesk, utiliser une tâche « Exécuter une commande » et le binaire `/opt/plesk/php/<version>/bin/php` adapté.

## Documentation

- [Étude préalable et différences Mautic 4/5/6](docs/TECHNICAL-STUDY.md)
- [Installation, configuration et Plesk](docs/INSTALLATION.md)
- [Qualification et limites actuelles](docs/QUALIFICATION.md)
- [Sécurité, clés et modèle de confiance](docs/SECURITY.md)
- [Sauvegarde, restauration et incidents](docs/RECOVERY.md)
- [API OAuth2](docs/API.md) et [contrat OpenAPI](docs/openapi.json)
- [Télémétrie et définition des indicateurs](docs/TELEMETRY.md)
- [Publication catalogue, Packagist et Marketplace](docs/PUBLISHING.md)
- [English overview](README-EN.md)

Licence : GPL-3.0-only. Copyright © 2026 WebAnyOne. [Contribuer](CONTRIBUTING.md) · [Changelog](CHANGELOG.md).

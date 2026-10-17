# Installation et configuration

## État du package
Aucune publication Packagist ou admission Marketplace n'est présumée. Utiliser la branche de développement uniquement sur une instance jetable, avec sauvegarde préalable. Les contraintes Composer sont volontairement exactes : `4.4.13 || 5.2.11 || 6.0.9`. Elles délimitent l'étude et le socle, pas une qualification de déploiement.

## PHP et chemins

La V1 cible des systèmes POSIX avec `flock`, `posix` et un utilisateur de site limité. Composer CLI 2.5.5 ou supérieur est requis pour la résolution et l’audit du lock.

| Mautic | Binaire de laboratoire | Chemin console courant |
|---|---|---|
| 4.4.13 | PHP 7.4.33 | `bin/console` |
| 5.2.11 | PHP 8.1.31 / 8.3.14 | `bin/console` à la racine Composer |
| 6.0.9 | PHP 8.1.31 / 8.3.14 | `bin/console` à la racine Composer |

Depuis la racine Composer :

```sh
composer config repositories.webanyone-manager vcs https://github.com/WebAnyOne/plugin-manager
composer require webanyone/mautic-plugin-manager:dev-main --no-scripts
```

Depuis la racine de l'application :

```sh
php bin/console mautic:plugins:reload --env=prod --no-debug
php bin/console cache:clear --env=prod --no-debug
php bin/console wao:plugins:process-queue --env=prod --no-debug
```

Le Manager découvre le projet Composer en remontant jusqu'à trois parents depuis le chemin Mautic. Il exige `composer.json`, `composer.lock`, `vendor` et une dépendance core Mautic. Le laboratoire 4 existant n'a pas de projet racine : le diagnostic le signale, la consultation reste disponible.

## Paramètres opérateur
Les préférences fonctionnelles sont disponibles dans Paramètres. Les paramètres d'infrastructure suivants se placent dans la configuration locale Mautic (`app/config/local.php` en 4/5, `config/local.php` selon le déploiement en 6), sous la clé `parameters` déjà existante :

```php
'wao_plugin_manager_storage' => '/chemin/prive/plugin-manager',
'wao_plugin_manager_composer' => '/usr/local/bin/composer',
'wao_plugin_manager_restore_provider' => '/chemin/prive/restore-provider',
```

Ne pas dupliquer la clé `parameters`. Vider le cache après modification. Les chemins sont absolus, non modifiables depuis l'API. Le stockage par défaut est un dossier `wao-private/plugin-manager-<empreinte>` à côté de la racine applicative, jamais sous celle-ci. Précréer ce dossier sous l'identité de l'instance si le parent n'est pas inscriptible. Permissions : dossier `0700`, état `0600`, volume local avec `flock` fiable. Un système de fichiers distribué sans verrou fiable n'est pas pris en charge. Conserver ce stockage lors des restaurations pour garder la file, les incidents et le compteur anti-retour.

La source par défaut est `https://raw.githubusercontent.com/WebAnyOne/plugin-manager/main/catalogue/manifest.json`, avec racines publiques épinglées dans le package. Une installation ne nécessite pas de secret API WebAnyOne. Le catalogue signé embarqué permet la consultation initiale ; son chargement local n'est jamais compté comme un test HTTPS réussi.

`wao_plugin_manager_telemetry_url` est vide jusqu'à disponibilité et qualification du collecteur ; seul un endpoint HTTPS sur `go.webanyone.net` est autorisé. Aucun inventaire ne quitte l'instance tant que le consentement est faux.

## Cron et Plesk
Programmer une seule commande **chaque minute** avec le PHP identique au serveur web. Exemple à adapter :

```cron
* * * * * /opt/plesk/php/8.3/bin/php /var/www/vhosts/example.com/httpdocs/bin/console wao:plugins:process-queue --env=prod --no-debug
```

Exécuter sous l'utilisateur système du site, jamais root. Le Manager ne crée pas de cron système et n'infère pas un worker actif de la présence de Messenger. Le heartbeat expire après cinq minutes. Une exécution peut durer plus d'une minute : les suivantes quittent avec `worker_busy`. Le diagnostic ne lance aucun processus HTTP et ne confirme que les capacités réellement sondées par le worker.

## Limites conservatrices du déploiement V1
Les projets contenant des repositories personnalisés, des chemins vendor/plugins non standards, des dépendances de développement verrouillées, une divergence entre inventaire et lock ou des modifications de dépendances hors cible nécessitent une revue opérateur et sont bloqués. Cela inclut le repository VCS utilisé pour l'installation de développement du Manager : le moteur sera qualifié avec le package publié via Packagist, pas en contournant cette restriction. Les plugins doivent fournir une sonde fonctionnelle publique `wao.plugin_probe.<identifiant>` implémentant `HealthProbeInterface` pour réussir la vérification finale. Aucun plugin n'est déclaré sain sur la seule présence de ses fichiers.

## Communauté
Les demandes de support, suggestions et retours passent par la messagerie native de l'instance, après prévisualisation et validation. Sans transport fonctionnel, télécharger le diagnostic nettoyé puis écrire à `support@webanyone.net`. Les coordonnées ne sont conservées que temporairement par la session Mautic pour la prévisualisation. Les URL newsletter FR/EN attendent leur fourniture et leur publication dans le catalogue signé ; aucune inscription n'est simulée.

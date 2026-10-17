# Étude technique — étape A — 17 octobre 2026

## Conclusion et périmètre
Un seul package Composer de type `mautic-plugin` peut partager un cœur PHP 7.4 avec trois adaptateurs. Le worker CLI fait la résolution hors HTTP ; il ne faut pas réutiliser le contrôleur natif du Marketplace pour installer. Les lignes de versions séparées ne sont pas nécessaires pour le socle étudié. Cette conclusion de faisabilité ne certifie aucune opération de déploiement.

| Cible examinée | PHP de laboratoire | Symfony | DBAL / ORM | Courrier | Templates / contrôleurs | Qualification à ce stade |
|---|---|---|---|---|---|---|
| 4.4.13 | 7.4.33 | 4.4 | 2.13 / 2.12 | MailHelper / SwiftMailer | PHP, notation bundle:controller:action | À tester |
| 5.2.11 | 8.1.31, 8.3.14 | 5.4 | 3.7 / 2.18 | MailHelper / Symfony Mailer | Twig, FQCN et services | À tester |
| 6.0.9 | 8.1.31, 8.3.14 | 6.4 | 3.7 / 2.18 | MailHelper / Symfony Mailer | Twig, FQCN et services | À tester |

Les numéros des dépendances proviennent des laboratoires existants ; ceux-ci sont des distributions avec vendor déjà présent, et non des preuves de résolution Composer vierge. Aucune autre mineure n'est déclarée compatible. Mautic 4.4.13 n'a pas de projet Composer racine dans le laboratoire existant : consultation possible, installation bloquée.

Le tableau ci-dessus décrit l’état de l’étude préalable ; les résultats des tests du socle, des installations Composer vierges et des audits sont dans [QUALIFICATION.md](QUALIFICATION.md).

## Points comparés
- **PHP / Symfony** : syntaxe commune PHP 7.4, pas de propriétés promues ni d'attributs obligatoires. Commande Symfony Console explicite, arguments de processus sous forme de tableaux.
- **Doctrine** : utiliser DBAL `executeStatement`, `fetchAssociative`, `fetchAllAssociative` disponibles en DBAL 2.13 et 3.7. Stockage privé transactionnel via fichier local verrouillé pour la file V1 ; pas de migrations implicites de la base Mautic par le Manager. L'installation d'un plugin peut toutefois modifier cette base : restauration complète obligatoire.
- **Plugins / services** : `PluginBundleBase`, `Config/config.php`, services nommés ; en 5/6, contrôleurs enregistrés par extension DI. Rechargement via `mautic:plugins:reload` dans un processus neuf.
- **Routes** : `main` pour le firewall authentifié de l'interface ; `api` pour le firewall natif OAuth. Différence notation historique / FQCN adaptée à l'exécution.
- **Permissions** : `AbstractPermissions`, quatre bits view/install/update/configure ; contrôle côté serveur et absence de droits attribués aux rôles existants. L'API exige en plus le token OAuth authentifié natif ; Basic et session refusés.
- **Authentification** : réutiliser le serveur OAuth2 Mautic et vérifier le type du token authentifié, pas la présence d'un en-tête Bearer.
- **Courrier** : `mautic.helper.mailer` fournit une façade commune ; reset après chaque envoi ; destinataires fixes, réponse explicitement fournie.
- **CLI / jobs** : Symfony Messenger existe en 5/6 mais ne fournit pas à lui seul un consommateur lancé ; le plugin ne peut ni déduire un cron de sa simple présence, ni installer un cron système. Une unique tâche `wao:plugins:process-queue` est nécessaire. Heartbeat réel et expiration des workers.
- **Composer** : découvrir le projet en remontant depuis le chemin Mautic, ce qui couvre `recommended-project/docroot`. Exiger lock, core-lib, instal­ler existant, PHP CLI concordant et accès en écriture. La demande HTTP crée un job après précontrôles locaux ; la simulation Composer appartient ensuite au job CLI, car la demander avant sa création contredirait l’interdiction de Composer en HTTP. Un HTTP 202 ne garantit donc pas la résolution. Simulation avant mutation ; toute modification hors du plugin explicitement ciblé est refusée en V1. Scripts Composer désactivés, seul `composer/installers` autorisé, artefact signé téléchargé et contrôlé avant installation.
- **Marketplace** : ses commandes ne certifient pas les tuples plugin/Mautic/PHP et ne garantissent pas de restauration. Notre catalogue est complémentaire ; préparation Packagist / allowlist séparée.
- **Design** : enveloppes natives PHP en 4 et Twig en 5/6 ; classes de formulaires, tableaux, alertes et navigation natives ; traductions INI FR/EN ; pas de SPA autonome.
- **Licence** : sources Mautic déclarées GPL-3.0 ; choix GPL-3.0-only pour ce plugin, distribution avec texte de licence Mautic. Pas de dépendance propriétaire.

## Blocages de publication et de certification
Le dépôt public `WebAnyOne/plugin-manager` a été fourni en cours de réalisation ; une racine publique de signature et un catalogue initial sans certification ont été préparés, avec clé privée hors dépôt. Les URL exactes des formulaires newsletter ne sont pas fournies. Aucune URL fictive ni clé de développement ne sera traitée comme une source officielle. Le catalogue embarqué descriptif reste consultable et non certifié. La publication Packagist / Marketplace nécessite comptes et admission éditeur. La qualification Plesk, les snapshots code/base cohérents et les installations Composer vierges restent nécessaires. Les mises à jour du Manager sont exclusivement manuelles tant que la récupération indépendante n'est pas qualifiée.

## Sources primaires
- [Mautic 4.4.13](https://github.com/mautic/mautic/tree/4.4.13)
- [Mautic 5.2.11 et migration 5](https://github.com/mautic/mautic/blob/5.2.11/UPGRADE-5.0.md)
- [Mautic 6.0.9 et migration 6](https://github.com/mautic/mautic/blob/6.0.9/UPGRADE-6.0.md)
- [Listing Marketplace](https://devdocs.mautic.org/en/7.1/marketplace/listing.html)
- [Composer : scripts et plugins](https://getcomposer.org/doc/03-cli.md)
- Empreintes des sources inspectées : `source-audit.json`.

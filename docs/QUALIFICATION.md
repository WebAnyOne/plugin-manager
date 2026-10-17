# Qualification du 17 octobre 2026

**Résultat global : INCOMPLET — aucune certification de production, aucune release de plugin installable dans le catalogue initial.** Le code est une version de développement `0.1.0-dev`.

## Socle natif vérifié

| Mautic exact | PHP exact | Instance | Assertions natives | Rendus FR/EN |
|---|---|---|---:|---:|
| 4.4.13 | 7.4.33 | existante | 102 | 20 |
| 5.2.11 | 8.1.31 | existante | 102 | 20 |
| 5.2.11 | 8.3.14 | existante | 102 | 20 |
| 6.0.9 | 8.1.31 | existante | 102 | 20 |
| 6.0.9 | 8.3.14 | existante | 102 | 20 |
| 4.4.13 | 7.4.33 | vierge, Composer | 102 | 20 |
| 5.2.11 | 8.1.31 | vierge, Composer | 102 | 20 |
| 6.0.9 | 8.3.14 | vierge, Composer | 102 | 20 |

Les instances existantes réutilisent leurs dépendances préinstallées. Les trois instances vierges proviennent de `mautic/recommended-project` aux versions exactes demandées, d'une résolution Composer neuve, d'une installation du Manager **par Composer**, puis d'une base locale jetable initialisée par l'installateur natif. Les dépendances fraîchement résolues diffèrent des laboratoires existants : ces résultats ne certifient pas toutes les combinaisons possibles.

Sur chaque ligne, les tests couvrent chargement du bundle, découverte des services et des dix routes, permissions administrateur/non-administrateur, installation native du bundle, vingt vues traduites, assistant de deux écrans, refus CSRF, absence de télémétrie avant consentement, export expurgé, API et prévisualisation de support. L'API a aussi été testée séparément via le pipeline HTTP complet et le firewall OAuth2 natif sur les trois instances neuves : 401 sans jeton ou jeton invalide, 403 sans permission, 200 avec jeton administrateur, 400 pour paramètre de repository arbitraire, et PATCH de consentement false. Les jetons et clients de test ont été supprimés ; les paramètres API des laboratoires ont été restaurés.

## Cœur, concurrence et signatures

- Tests de sécurité du cœur passés sous PHP 7.4.33, 8.1.31 et 8.3.14 : 20 055 assertions chacun. La majorité vérifie les propriétés des 10 000 groupes de diffusion ; ce nombre n'est pas un nombre de scénarios de déploiement.
- Signatures, altération, expiration, retour de séquence, révocation/rotation, schéma fermé, contraintes Composer invalides, certificats exacts, dérive de dépendances et intégrité des références contrôlés.
- Vérification des mêmes groupes de diffusion : 484/10 000 à 5 %, 2 443/10 000 à 25 %, 10 000/10 000 à 100 % ; aucune transmission de l'identifiant pour ce calcul.
- Six processus concurrents réalisent 240 écritures sans perte et obtiennent un seul job pour la même clé d'idempotence.
- Le superviseur garde son verrou après interruption du parent, refuse une opération concurrente et laisse reprendre après la fin de l'enfant. Timeout et codes de sortie testés.
- Réducteur de métriques : mises à jour distinctes des nouvelles instances, événements dédupliqués, silence non assimilé à une désinstallation.
- Syntaxe des 39 fichiers PHP vérifiée sur les trois interpréteurs ; métadonnées Composer validées avec `--strict`.

## Récupération locale sur fixtures

La suite `Tests/recovery.php` utilise le kernel natif et la méthode réelle `Manager::recover` sur les trois projets Composer vierges : Mautic 4.4.13 / PHP 7.4.33, 5.2.11 / PHP 8.1.31 et 6.0.9 / PHP 8.3.14. Chaque exécution couvre 31 assertions sur un petit projet temporaire et une base MySQL distincte à table unique, supprimés à la fin. Elle ne modifie pas les enregistrements Mautic.

Les scénarios couvrent : aller-retour initial de sauvegarde/restauration réellement exécuté avant d'enregistrer la date de test ; expiration de cette date ; incident après modification des fichiers, du schéma et des données ; échec de restauration ; échec de vérification ; point de restauration incorrect ; sauvegarde corrompue ; arrêt brutal du parent ; refus d'une récupération concurrente ; reprise explicite et déblocage de la file seulement après restauration vérifiée. Les preuves sont `native-recovery-4.json`, `native-recovery-5.json` et `native-recovery-6.json`.

Ces essais ont conduit à protéger aussi les fournisseurs par le superviseur indépendant. Le test du superviseur reproduit désormais un enfant qui écrit sur stdout/stderr après la mort du parent ; la gestion des canaux fermés évite les notices de pipe cassé et permet le nettoyage de la métadonnée sous PHP 8.3.

**Limite :** les fichiers et dépendances sont des fixtures, le schéma contient une table, et aucune écriture concurrente n'est autorisée. Il ne s'agit ni d'une restauration complète de Mautic, ni d'un déploiement Composer d'un plugin certifié. Le fournisseur de test ne peut pas servir en production. Aucun environnement Plesk n'est disponible ; le critère `plesk` reste obligatoire et non validé.

## Résolution et sécurité des graphes obtenus

Composer CLI 2.5.5 a résolu et installé le Manager dans le répertoire natif des trois recommended-project. Les scripts ont été désactivés lors de la résolution et de l'installation de contrôle. Les assets natifs des cores 5/6 ont ensuite été préparés par leurs procédures frontend ; le plugin n'ajoute aucune application JavaScript autonome. Le bootstrap CLI se trouve à la racine Composer, hors de docroot dans cette disposition : cette différence est couverte par le diagnostic et l'exécuteur.

| Graphe frais | Résolution / installation Manager | Avis de sécurité de l'audit |
|---|---|---|
| 4.4.13 / PHP 7.4.33 | Réussies | 37 avis concernant 16 packages |
| 5.2.11 / PHP 8.1.31 | Réussies | Aucun avis retourné lors de ce contrôle |
| 6.0.9 / PHP 8.3.14 | Réussies | 3 avis concernant un package |

Ces nombres concernent les **locks obtenus le jour du test**, pas tous les déploiements de ces versions de Mautic. Ils empêchent toute certification globale. Le moteur exige désormais un audit propre du candidat avant mutation ; il ne met pas le cœur à jour pour contourner les contraintes. Un graphe sans avis ne suffit pas à certifier le déploiement. Voir les JSON `dependency-audit-*` et les sources des avis, notamment [Symfony Security HTTP](https://symfony.com/cve-2026-48489) et [Guzzle OAuth subscriber](https://github.com/guzzle/oauth-subscriber/security/advisories/GHSA-237r-r8m4-4q88).

## État des étapes du brief

| Étape | État |
|---|---|
| A — étude | Réalisée, faisabilité d'un package commun et installation initiale Composer démontrées localement |
| B — socle | Implémenté et vérifié dans les lignes précises ci-dessus |
| C — déploiements | Moteur implémenté et gardes testées ; déploiement réel d'un plugin certifié et rollback cohérent non qualifiés |
| D — API | Contrat et endpoints implémentés ; firewall natif vérifié ; cycle de job de déploiement accepté puis réussi encore à qualifier |
| E — communauté | Interface, consentement, file, métriques et courrier natif implémentés ; collecteur, formulaires newsletter et transport e-mail réel à valider |
| F — qualification | Partielle : socle local et récupération sur fixtures vérifiés ; restauration Mautic complète et Plesk non qualifiés |

## Critères empêchant une V1 de production

Restent obligatoires : fournisseur de snapshot/restauration code+base réellement qualifié ; installation d'un plugin approuvé de bout en bout ; mise à jour depuis une version précédente ; migrations et échecs réels avec récupération ; désactivation/reprise ; absence d'erreurs critiques et coexistence fonctionnelle ; environnement Plesk représentatif ; parcours indépendant de récupération du Manager ; newsletters FR/EN (confirmation/désinscription/confidentialité) et collecteur consentant ; test du transport de support ; publication Packagist et admission Marketplace avec le compte éditeur.

Le modèle `qualification-template.json`, la liste `Tests/qualification-plan.json` et le workflow `qualification-gate.yml` refusent une certification tant que toutes les preuves ne sont pas présentes. Le rapport incomplet livré a été effectivement refusé par ce garde-fou. Aucune réussite de Composer ou du socle n'est présentée comme une qualification d'installation de plugins.

## Reproduction et preuves

Les rapports finaux et journaux de résolution sont dans [`qualification/2026-10-17`](../qualification/2026-10-17/). Les copies de configurations, identifiants de test, bases et clé privée n'en font pas partie. `SUMMARY.json` liste les preuves sélectionnées et leurs empreintes. Les tests natifs doivent uniquement viser des bases jetables `apimonitor_lab_*`.

Les trois aperçus [`docs/screenshots`](screenshots/) proviennent des templates natifs réellement rendus dans les laboratoires existants. Ils documentent l'assistant FR de Mautic 4, les paramètres EN de Mautic 5 et le diagnostic FR de Mautic 6 ; ce sont des aperçus de validation, sans installation certifiée simulée.

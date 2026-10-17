# Contribuer

Ouvrir une proposition décrivant le problème, les versions exactes et le comportement attendu. Aucun compte payant n'est nécessaire. Signaler les problèmes de sécurité par e-mail au support.

Le cœur reste compatible avec la syntaxe PHP 7.4. Utiliser les services Mautic/Symfony et les adaptateurs pour les différences ; pas de SPA. Toutes les chaînes d'interface sont traduites FR/EN. Ne jamais ajouter de commandes libres, dépôts saisis par l'utilisateur, consentement présélectionné, appel réseau avant consentement pour la télémétrie, ou faux état sain.

Exécuter `Tests/unit.php` avec `MAUTIC_AUTOLOAD` pointant sur l'autoload d'une instance Mautic (ou un autoload contenant composer/semver). Exécuter `Tests/metrics.py` et `Tests/process-guard.py` et `Tests/concurrency.py`. Les tests natifs doivent utiliser une instance jetable et une base nommée `apimonitor_lab_*` ; ils refusent une autre base avant toute mutation de test. Installer le bundle dans son dossier natif puis lancer `Tests/integration.php /chemin/instance`. Vérifier les rendus FR/EN. Ne pas inclure les configs locales, bases, secrets, clés privées ou données de clients dans Git.

Les tests CI du socle et les tests natifs ne certifient pas à eux seuls le déploiement. Une certification ajoute résolution Composer propre, installation/update, schéma, désactivation/reprise, cas d'échec, restauration et coexistence. Joindre les rapports de qualification plutôt qu'élargir une contrainte par numéro majeur.

Les changements du schéma de catalogue nécessitent des cas de refus adverses et un validateur runtime correspondant. Toute rotation de clé doit conserver une chaîne de confiance et une procédure pour les clients restés hors ligne. Les correctifs automatiques sont exclusivement sémantiques patch, certifiés, non bloqués et éligibles à leur campagne.

# Sauvegarde et restauration

Le Manager ne peut garantir un snapshot universel sur tous les hébergements. Il fournit une interface de fournisseur local et **bloque les mutations si elle n'est pas configurée et vérifiée**. Le plugin Mautic Backup existant n'est pas considéré compatible automatiquement : son éventuel adaptateur doit être qualifié avec ses droits réels.

## Protocole du fournisseur
Configurer un exécutable local absolu, appartenant à l'opérateur, non inscriptible par groupe/autres. Il est appelé sans shell :

```text
restore-provider <probe|snapshot|verify|restore> <racine-composer> <job-id-32-hex>
```

Code de sortie zéro et un seul objet JSON sur stdout :

```json
{"protocol":1,"ok":true,"project":"/chemin/absolu/projet","code_and_database":true,"restore_tested_at":1792195200,"checkpoint":"snapshot-reference"}
```

`restore_tested_at` doit être un vrai test de restauration de moins de trente jours, jamais une valeur produite artificiellement à chaque appel. `checkpoint` identifie le même point exploitable associé au job ; le fournisseur garde lui-même la correspondance pour `restore` et `verify`. `probe` n'a aucun effet de bord et reçoit l'identifiant nul. Le Manager valide le protocole, pas la véracité d'une promesse de l'hébergeur : qualifier le fournisseur de bout en bout est obligatoire.

Le fournisseur doit couvrir ensemble le code, `vendor`, plugins, fichiers nécessaires et la base Mautic ; vérifier la disponibilité, l'intégrité, la capacité et les droits de restauration. Il doit assurer la cohérence face aux écritures HTTP/cron et aux autres opérateurs (maintenance/quiescence ou snapshot transactionnel). Préserver le stockage privé du Manager et son compteur de manifeste lors d'une restauration. Ne jamais considérer les seules copies `composer.before.json` / `composer.before.lock` comme un rollback de migrations.

`verify` confirme que la restauration reste exploitable et que le système est cohérent ; un échec après mutation est un incident. La V1 ne restaure pas automatiquement en cas d'échec : elle laisse le système en état explicite de récupération et bloque les autres déploiements. Conserver les points jusqu'à validation opérateur et appliquer une rétention hors de ce plugin.

Les appels `snapshot`, `restore` et `verify` passent par le superviseur indépendant qui conserve `execution.lock` jusqu'à la fin du fournisseur, même si le processus Mautic parent est tué. Une récupération concurrente est refusée (`worker_busy` ou `child_process_still_running`). Si le parent meurt, le job reste `recovery_required` ; relancer explicitement la récupération après la fin du fournisseur pour obtenir une nouvelle vérification avant de débloquer la file.

## Test local reproductible

`php -d memory_limit=1G Tests/recovery.php /chemin/du/laboratoire` démarre le kernel natif et appelle la vraie méthode de récupération du Manager. Le bootstrap refuse les bases autres que `apimonitor_lab_*`. Le test crée puis supprime une base MySQL distincte `apimonitor_lab_pm_recovery_*` et un petit projet sous `/tmp/wao-manager-recovery-*` ; le compte MySQL du laboratoire doit pouvoir créer cette base. Aucun enregistrement de la base Mautic n'est modifié.

Le fournisseur dans `Tests/fixtures/restore-provider.php` est réservé à ce petit projet et sa table unique. Il démontre le retour des fichiers, des dépendances factices, du schéma et des lignes, les refus en cas d'échec/corruption et l'exclusion après arrêt brutal du parent. Sa date de test est enregistrée seulement après un premier aller-retour réel. **Ce fournisseur ne doit pas être configuré sur une installation Mautic** : il ne traite ni base Mautic complète, ni écritures concurrentes, ni Plesk. Ces résultats ne qualifient pas une restauration d'hébergement.

## États
`queued → running → succeeded` seulement après Composer, nouveau démarrage Mautic, rechargement natif, vérification du bundle et sonde fonctionnelle. Une erreur avant mutation produit `failed`. Après mutation ou interruption incertaine : `recovery_required`. Une demande non traitée expire après 24 heures. Les tentatives sont conservées dans l'historique.

Après correction d'un prérequis avant mutation, créer une nouvelle tentative depuis l'historique ou l'API avec une **nouvelle** clé d'idempotence ; la même clé retourne toujours la demande d'origine. Aucun replay automatique d'une mutation interrompue.

## Procédure d'incident
1. Examiner le job, sa phase et son code d'erreur. Suspendre les tâches externes et les accès concurrents ; ne pas lancer un autre Composer.
2. Vérifier les processus encore vivants. Le superviseur protège l'enfant si seul le worker meurt. Si le superviseur a aussi été tué, arrêter proprement tous les descendants avant toute restauration. Ne pas supprimer un verrou pour forcer une seconde installation.
3. Avec le même utilisateur limité : `php bin/console wao:plugins:recover <job-id> --env=prod --no-debug`. La commande utilise le fournisseur qualifié pour restaurer le code et la base, puis vérifier le résultat. Le job passe à `restored` seulement si les deux appels réussissent.
4. Si Mautic ne démarre plus, restaurer directement par l'outil d'hébergement. Les références et copies Composer sont dans le stockage privé `operations/<job-id>`. Redémarrer les services nécessaires, vérifier schéma, routes, services et fonctionnalités. Le fournisseur doit permettre cette récupération sans le Manager.
5. Reprendre les tâches et refaire le diagnostic. En cas de doute, télécharger le diagnostic nettoyé et contacter le support. Ne pas déclarer le produit remis en service uniquement parce que Composer a terminé.

Les archives locales vérifiées présentes dans les locks restent dans le stockage privé : les conserver pour les réinstallations. Leur suppression sans réécriture qualifiée des références de distribution casserait la reproductibilité.

## Manager lui-même
Aucune mise à jour du Manager n'est acceptée par sa propre file, même explicitement. Sauvegarder code et base, arrêter le worker, attendre ses descendants, puis utiliser **un processus Composer externe** avec une version exacte approuvée et les contrôles opérateur. Recharger les plugins, vider le cache et vérifier avant de réactiver le cron. Conserver un moyen de restauration hébergeur indépendant. Les mises à jour automatiques du Manager restent désactivées jusqu'à qualification complète de ce scénario.

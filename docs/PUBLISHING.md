# Publication et maintenance éditeur

## Dépôt
Le dépôt fourni est [WebAnyOne/plugin-manager](https://github.com/WebAnyOne/plugin-manager). Le package et le catalogue peuvent partager ce dépôt ; aucun client ne peut en ajouter un autre depuis l'interface. Le nom Composer est `webanyone/mautic-plugin-manager`, type `mautic-plugin`, bundle `WebAnyOnePluginManagerBundle`. Le code public n'inclut aucun service commercial propriétaire.

## Catalogue signé
Le catalogue initial courant (séquence 2) `catalogue/manifest.json` est signé avec la racine publique de `trusted-keys.json`. Il contient uniquement des fiches descriptives, aucune release certifiée. Sa source lisible est `manifest.source.json`. La clé privée de signature est livrée séparément hors du package et ne doit jamais entrer dans Git.

1. Modifier la source avec des packages réellement autorisés, versions sémantiques explicites, commit Git complet immuable, archive HTTPS directe et SHA-256 vérifié.
2. Exécuter la qualification complète pour chaque tuple exact plugin/Mautic/PHP/dépendances. Publier un rapport versionné et son empreinte. L'absence de rapport valide ne peut être remplacée par une contrainte Composer large.
3. Augmenter `sequence`, fixer `issued_at` et `expires_at` (maximum sept jours). Ne jamais republier un autre contenu sous la même séquence. Un manifeste expiré bloque les opérations.
4. Signer avec `php bin/catalogue-sign.php <source.json> <chemin-prive-cle> <key-id> <sortie.json>` ; valider ensuite sa signature et son schéma dans CI.
5. Le workflow `qualification-gate.yml` refuse un rapport incomplet ou sans preuves vérifiables. Conserver les manifestes dans des publications immuables et mettre à jour la copie courante au chemin par défaut. Renouveler avant expiration. Garder la clé hors ligne ou dans un secret de CI protégé ; ne jamais l'inclure dans un artefact public.

Déploiement : 5 % puis 25 % au moins 24 h après ouverture, puis 100 % après une nouvelle observation suffisante. `expand_after` doit respecter cette politique ; l'éditeur valide explicitement chaque élargissement et conserve la campagne stable. Le Manager calcule localement le bucket 0–9 999 à partir de l'identifiant d'installation, plugin, version et campagne, sans l'envoyer. Suspendre ou bloquer la release dans un nouveau manifeste arrête les nouvelles opérations ; les opérations déjà mutantes finissent ou entrent en récupération selon leur phase. Aucun déploiement n'est annulé rétroactivement.

Les URL exactes `newsletter_fr` / `newsletter_en` sur `go.webanyone.net` doivent être fournies et testées avec confirmation, désinscription et confidentialité. Les segments ne sont pas créés dans le Mautic du client. Les liens commerciaux pointent vers des pages WebAnyOne officielles et restent discrets.

## Packagist
Après qualification : compléter les métadonnées support et les contraintes exactes validées, exécuter `composer validate --strict`, retirer toute mention de développement dans les métadonnées fonctionnelles et créer un tag signé de version stable cohérent avec le changelog. Soumettre l'URL GitHub dans le compte Packagist autorisé, configurer le webhook et vérifier la résolution depuis une instance Composer vierge. Ne pas publier une version stable ou élargir la contrainte Mautic tant que la matrice n'est pas verte. Composer installera le bundle par `extra.install-directory-name` ; ne pas ajouter une dépendance obsolète à `mautic/composer-plugin`.

## Marketplace
Vérifier les exigences du [guide officiel](https://devdocs.mautic.org/en/7.1/marketplace/listing.html) au moment de la candidature : package sur Packagist, `type`, licence, contraintes PHP/extensions/Mautic limitées aux versions testées, nom du bundle exact, README et support. Candidater à l'allowlist officielle avec le compte éditeur et les rapports. L'admission ne peut pas être simulée localement et le catalogue WebAnyOne ne la remplace pas.

## Approbation de production
Exiger les scénarios de `QUALIFICATION.md`, y compris Plesk et restauration indépendante. Aucun résultat du socle ni compteur de tests unitaires ne constitue seul une certification. Les rapports livrés signalent les étapes non exécutées.

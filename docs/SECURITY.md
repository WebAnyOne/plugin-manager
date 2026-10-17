# Sécurité et confiance

## Frontières
L'interface utilise le firewall Mautic authentifié, les permissions `webanyonepluginmanager:manager:view|install|update|configure` et un jeton CSRF pour chaque POST. Les administrateurs ont les droits natifs ; aucun rôle existant n'est modifié. L'API exige un token OAuth2 authentifié natif : une session, HTTP Basic ou un simple en-tête Bearer ne suffit pas. Tous les endpoints contrôlent leurs permissions côté serveur. Les erreurs renvoient des codes techniques, jamais la sortie brute de Composer.

L'API ne peut choisir ni repository, ni clé, ni chemin système, ni option Composer, ni commande, ni destinataire d'e-mail. Les tableaux d'arguments de processus sont créés côté serveur. Composer est exécuté seulement en CLI et refuse root. Aucun contrôleur HTTP ne résout ni n'exécute Composer.

## Catalogue
`schema.json` est un schéma fermé JSON Schema 2020-12. Le validateur du runtime implémente le sous-ensemble exact utilisé : objets fermés, types, champs obligatoires, propriétés de dictionnaires, enums, bornes, expressions régulières, tableaux et références locales. Les objets inconnus et champs exécutables sont refusés. Toute extension du schéma nécessite un test du validateur ; les mécanismes JSON Schema non implémentés ne doivent pas être ajoutés silencieusement.

L'enveloppe transporte en base64 les octets UTF-8 exacts du manifeste et une ou plusieurs signatures Ed25519. La signature porte sur ces octets, sans recanonicalisation. Le catalogue contient la liste autorisée des packages, versions, références immuables et SHA-256 des archives. Les liens externes et sources passent une liste de domaines HTTPS fermée, sans credentials, port alternatif ni redirection. Le client refuse les adresses privées et épingle l'adresse IPv4 validée pour éviter le rebinding DNS. Les proxies implicites sont désactivés.

Le numéro `sequence` ne peut pas reculer ; le même numéro avec un autre digest est refusé. La validité maximale est sept jours. Un cache expiré reste visible mais n'autorise aucune nouvelle opération. Le worker exige en plus une synchronisation avant chaque opération et avant la mutation. Un échec réseau n'autorise pas un déploiement depuis une ancienne information de sécurité.

## Rotation
Ajouter une nouvelle clé publique dans `next_keys` et publier un manifeste de séquence supérieure signé par l'ancienne clé. Pendant le chevauchement, signer avec les deux clés pour les installations restées hors ligne. Révoquer ensuite l'ancienne dans `revoked_keys`, uniquement après diffusion de la nouvelle racine dans une release du Manager. Une révocation est persistante et ne peut pas être annulée par un manifeste ultérieur. Une rotation supprimant toute racine valide est refusée. Les installations ayant manqué toutes les étapes de transition ont besoin d'une mise à jour du package contenant la nouvelle racine ; ce cas n'est jamais réparé en acceptant une clé distante non approuvée.

Le fichier de signature privé de l'éditeur ne fait pas partie du dépôt. Le script de signature refuse un chemin de clé situé dans le package. Sauvegarder la clé hors ligne, protéger les accès et renouveler le manifeste avant expiration. Une compromission de clé nécessite une réponse éditeur et la mise à jour des racines de confiance.

## Déploiement
Résolution dans un dossier privé, scripts et plugins désactivés pendant la simulation. Chaque package verrouillé autre que la cible doit rester strictement identique. Un audit Composer du lock candidat doit réussir sans suppression d’avis de sécurité, avant le snapshot et la mutation. Les graphes vulnérables sont bloqués ; le Manager ne corrige pas automatiquement le cœur pour les rendre admissibles. Le candidat doit être le bundle attendu, à la version et à la référence approuvées. L'archive est téléchargée par HTTPS, vérifiée par SHA-256 et passée à Composer depuis un chemin local privé ; un second téléchargement distant de la cible n'est donc pas utilisé. Pendant l'installation réelle, seul `composer/installers` déjà présent est autorisé, les scripts restent désactivés. Les dépôts personnalisés et projets de développement sont bloqués.

Un snapshot cohérent et une restauration testée sont obligatoires. Un superviseur CLI distinct garde le verrou d'exécution du processus enfant même si le worker Mautic est interrompu. Le marqueur de mutation est écrit avant la première modification du projet. Une interruption entraîne `recovery_required`, jamais un faux succès ou un retry automatique. La récupération refuse un enfant encore actif. Un `SIGKILL` du superviseur lui-même demande de vérifier et arrêter manuellement les descendants ; le fichier `active-process.json` garde le PID connu et bloque la récupération tant qu'il vit.

Le verrou interne ne peut empêcher un administrateur de lancer un autre Composer hors du Manager. L'opérateur doit interdire ces exécutions concurrentes et le fournisseur de snapshot doit assurer la quiescence cohérente de l'application. Le plugin compare les empreintes juste avant mutation, sans prétendre verrouiller tous les outils externes du serveur.

## Données
Stockage privé hors webroot, permissions restrictives, écriture atomique sous `flock`, audit borné, file persistante, expiration à 24 h des demandes non exécutées. Aucun secret ni log brut n'est enregistré dans les jobs ou envoyé en support. La télémétrie est limitée par une liste positive de champs. La révocation purge la file d'événements ; son verrou évite qu'une transmission démarre après retour de la révocation.

Le diagnostic de support est prévisualisé exactement et ne contient par défaut ni URL de l'instance, ni log, ni identifiant de suivi. L'effacement requiert une action explicite distincte révélant l'identifiant au support. Les envois communautaires sont limités à un par utilisateur toutes les cinq minutes.

Signaler une vulnérabilité à `support@webanyone.net` sans joindre de secret.

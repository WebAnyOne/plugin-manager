# Télémétrie, confidentialité et indicateurs

La valeur initiale de `consent` est `false`. Aucun événement, inventaire, identifiant de suivi ni état de santé ne quitte l'instance avant un accord explicite. Le premier écran ne présélectionne aucun choix ; passer sans répondre conserve le refus implicite. La newsletter dispose d'un second écran indépendant et ne reprend pas l'adresse administrateur.

Après accord, les événements contiennent un ID unique, l'ID aléatoire stable de l'installation, versions Manager/Mautic/PHP, date UTC, origine UI/API/automatisation et champs techniques autorisés. Événements : `manager_registered`, `inventory_snapshot`, `plugin_detected`, `plugin_installed`, `plugin_updated`, `plugin_removed`, `plugin_install_failed`, `plugin_update_failed`, `plugin_health_changed`. Les contacts, campagnes, e-mails, utilisateurs, domaines, URL de navigation, logs bruts et secrets sont exclus.

Un inventaire observé part au plus une fois par jour avec décalage de 0 à 3 600 secondes. Le worker traite une file bornée de 500 événements pendant au plus sept jours, au plus cinq tentatives avec backoff exponentiel plafonné à un jour. Chaque requête conserve le même ID d'événement : le collecteur doit dédupliquer par cet ID et valider son propre schéma. La désactivation purge la file, réinitialise les observations locales et empêche les futurs envois ; une transmission déjà partie ne peut pas être rappelée.

L'endpoint collecteur est volontairement non configuré jusqu'à sa mise en service et qualification. Activer le consentement seul ne crée pas de service distant fictif. La file expire localement si aucun collecteur n'est disponible. Une demande d'effacement séparée est proposée par e-mail au support, avec prévisualisation explicite de l'identifiant concerné. L'exécution de cet effacement côté collecteur doit être traitée et confirmée par WebAnyOne ; le client ne prétend pas l'avoir effectuée automatiquement.

## Définitions de comptage

- **Installations enregistrées cumulées** : nombre d'identifiants distincts ayant émis `manager_registered` ; plusieurs consentements ne multiplient pas le compteur.
- **Actives 7/30 jours** : dernière observation `inventory_snapshot` de l'installation dans la fenêtre UTC considérée. L'événement de mise à jour seul n'invente pas un inventaire.
- **Instances équipées observées** : identifiants distincts dont le dernier inventaire récent contient le plugin. Un inventaire de plus de trente jours devient non confirmé ; le silence n'est pas une désinstallation.
- **Premières installations observées cumulées** : couples distincts (installation, plugin) vus par détection, installation ou inventaire. Une mise à jour et une réinstallation ne créent pas un nouveau couple.
- **Mises à jour réussies cumulées** : événements dédupliqués `plugin_updated` seulement.
- **Opérations réussies cumulées** : `plugin_installed` + `plugin_updated`, dédupliqués. Ce compteur n'est jamais affiché comme un nombre d'instances.
- **Désinstallations détectées** : événements explicites `plugin_removed` sur différence d'inventaire ; aucune déduction à partir du silence.
- **Répartitions** : versions de plugin/Mautic/Manager dans les derniers inventaires récents uniquement.
- **Taux d'échec observé** : échecs / (installations réussies + mises à jour réussies + échecs) ; valeur absente si dénominateur nul.

Le réducteur de référence `tools/telemetry_metrics.py` calcule ces indicateurs sur un export JSONL ; il ne crée aucun serveur exposé sur Internet. `Tests/metrics.py` vérifie notamment déduplication, mise à jour distincte d'une nouvelle instance et absence de désinstallation déduite du silence.

Les statistiques ne couvrent que les personnes consentantes ; l'historique antérieur n'est pas reconstruit. Le catalogue ne doit publier des agrégats que si leur représentativité a été examinée (`adoption.representative`) et leur taille ne permet pas de réidentifier une instance. Le client n'invente ni compteurs ni seuil de représentativité arbitraire.

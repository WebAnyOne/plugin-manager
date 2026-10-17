# API REST OAuth2

Base : `/api/webanyone/plugins`. Utiliser OAuth2 natif Mautic et des identifiants clients créés par l'administrateur de l'instance. Le Manager ne crée pas de serveur d'authentification ou de token alternatif. Une session Mautic et HTTP Basic sont refusés. HTTPS doit être imposé par l'hébergement pour l'API entrante.

| Méthode et chemin | Permission | Résultat |
|---|---|---|
| `GET /catalogue` | view | Releases fraîches, certifiées et actives seulement |
| `GET /installed` | view | Inventaire local et santé observée |
| `GET /jobs` | view | 100 opérations les plus récentes |
| `POST /jobs` | view + install ou update | HTTP 202 et identifiant persistant |
| `GET /jobs/{id}` | view | État, phase, tentatives, dates et erreur catégorisée |
| `GET /diagnostic` | view | Diagnostic nettoyé |
| `GET /settings` | view + configure | Préférences fonctionnelles |
| `PATCH /settings` | view + configure | Modification validée des préférences |

Demande :

```json
{"plugin":"identifiant-du-catalogue","version":"1.2.3","operation":"install","idempotency_key":"unique-client-request-0001"}
```

Les identifiants de l'exemple ne représentent pas une release certifiée. Le corps JSON est limité à 4 096 octets et les champs inconnus sont refusés. Aucune URL de repository, commande ou option Composer n'est acceptée. Réutiliser la même clé, le même utilisateur et la même origine retourne le job initial, même terminé ; changer son contenu avec la même clé est un conflit. Après un échec pré-mutation corrigé, une nouvelle tentative nécessite une nouvelle clé. Les versions non certifiées ne sont jamais installables par l'API standard.

Préférences modifiables : `consent`, `auto_patch`, `notifications` (booléens JSON), `check_hours` (entier 1–168). Les adresses de services, clés, exécutables et chemins restent dans la configuration opérateur. La désactivation du consentement purge les événements en attente.

Codes : 200 consultation ; 202 demande acceptée ; 400 corps invalide ; 401/403 authentification ou autorisation refusée par le firewall/contrôleur ; 404 job absent ; 409 prérequis manquant, release bloquée ou conflit d'idempotence. La réponse `202` n'indique jamais la réussite de l'installation. Consulter le job jusqu'à un état terminal ; `recovery_required` nécessite une intervention.

Le contrat machine complet est dans `openapi.json`. L'API ne transmet pas les clés d'idempotence dans les réponses de jobs, ne renvoie pas de sorties brutes et utilise `Cache-Control: private, no-store`.

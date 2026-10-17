# Changelog

## 0.1.0-dev — 2026-10-17

- Étude préalable de Mautic 4.4.13, 5.2.11 et 6.0.9 ; cœur commun et trois adaptateurs.
- Catalogue fermé signé Ed25519, expiration, anti-retour et rotation de clés ; fiches initiales sans certification de déploiement.
- File persistante, idempotence, verrouillage, superviseur CLI, plan Composer conservateur et intégrité SHA-256 de l'artefact.
- Protocole de snapshot et restauration cohérents, incidents explicites, sonde fonctionnelle obligatoire avant succès.
- Fournisseur de restauration supervisé même après arrêt brutal du parent ; canaux de sortie fermés traités sans empêcher le nettoyage sous PHP 8.3.
- Récupération native testée sur fixtures code, schéma et données MySQL pour Mautic 4/5/6 ; ces fixtures ne constituent pas un fournisseur d'hébergement.
- Interface native FR/EN, diagnostic, historique, préférences et assistant facultatif de deux écrans.
- API OAuth2, permissions serveur, CSRF, support et suggestions avec prévisualisation.
- Télémétrie sur consentement, file bornée et définition reproductible des indicateurs.
- Qualification de déploiement réel, fournisseur de restauration, Plesk, collecteur, formulaires newsletter et publication stable encore nécessaires.

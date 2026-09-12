# Réalisations — SP_Build (SportPress Calendar PRO)

Journal daté de ce qui a été **réellement fait** sur ce plugin. Contrairement à `sp-compta` et `tkd-cotisations`, ce plugin a un historique Git local complet (36 commits) depuis le 01/09/2026 : cette page en reprend la chronologie, reformulée en langage clair. Pour le détail technique exact d'un point, voir le commit correspondant (`git log`) dans le dépôt `sp_build`.

> Rappel de contexte (voir `CLAUDE.md`) : ce dépôt Git est un **filet de sécurité local**, pas un outil de collaboration — jusqu'ici sans aucun `remote`. Voir [../JOURNAL.md](../JOURNAL.md) pour la situation Git générale et le plan de rattrapage.

## 01/09/2026

- **Point de départ** : sauvegarde de l'état du plugin v10.17c avant traitement des doléances (filet de sécurité avant modifications).
- Formulaire d'adhésion public : représentants légaux en blocs répétables, documents obligatoires par discipline, mensurations obligatoires, popup règlement intérieur, section « repartir seul / avec représentant » conditionnée à la majorité de l'élève.
- Correctif d'alignement (paddings) sur les blocs représentant/urgence et les champs de dépôt de document.

## 02/09/2026

- Lieu de naissance, nationalité et adresse rendus obligatoires sur le formulaire d'adhésion.
- Ajout de la fonctionnalité **renouvellement de saison** : lancement depuis l'admin, relances bureau, désactivation et pré-remplissage du formulaire pour les adhérents existants.
- Correctif : la page « Demandes d'adhésion » s'affichait blanche quand une colonne récente manquait en base (auto-guérison du schéma).
- Ajout de `CLAUDE.md` pour orienter les futures sessions de développement assisté sur ce dépôt.

## 03/09/2026

- Ajout d'un champ Pass'Sport déclaratif au formulaire d'adhésion.

## 07/09/2026

- Correction du bug de soumission du formulaire d'adhésion ; ajout du statut « Conjoint/e » et du dépôt du bon CAF.
- Ajout de la date du certificat médical avec alerte de péremption non bloquante (conformité règlement FFTDA).
- Durée de validité du certificat médical rendue configurable ; correction du suivi médical Renfo.
- Remplacement de la veille réglementaire automatique par un simple bouton pense-bête dans les Réglages.
- Filtre par saison (élèves) rendu toujours visible + modèle imprimable de l'attestation Renfo.
- Filtre par saison également rendu visible sur la page admin Adhésions.
- Ajout d'un champ de dépôt de photo au formulaire d'adhésion public.

## 08/09/2026

- Correctif : erreur technique bloquant l'inscription quand une colonne manquait en base.
- Correctif : le check d'auto-guérison du schéma ne vérifiait qu'une seule colonne (renforcé).
- Ajout d'un diagnostic pour les emails de confirmation adhérent non reçus.
- Correctif : l'anti-doublon du formulaire d'adhésion bloquait à tort des familles entières.
- Clarification des libellés des délais de relance sur le formulaire de lancement du renouvellement.
- Renouvellement de saison : abandon du déclenchement automatique par cron, tout devient manuel (fiabilité).
- Clarification : le bouton de relance bureau n'envoie rien directement aux adhérents.
- Le lancement du renouvellement notifie désormais directement les adhérents (changement de comportement confirmé avec l'utilisateur).

## 09/09/2026

- Ajout sur la fiche admin élève des champs manquants par rapport au formulaire public.
- Ajout de la relance individuelle pour les adhérents sans email au moment du lancement.
- Ajout d'un rôle **Secrétaire** avec accès wp-admin partiel (gestion des adhésions uniquement).
- Ajout d'un lien Planning en wp-admin + filtre des anniversaires aux membres actifs.
- Correctif : la page Planning bouclait sur elle-même pour un accès restreint.
- Ajout d'une PWA « Disponibilités » pour les entraîneurs (lien personnel par token).
- Suppression du sous-menu Planning devenu redondant avec SP Calendar.
- Ajout d'un diagnostic pour le bouton d'envoi du lien PWA qui restait bloqué.
- Correction du bouton de pointage entraîneurs (appelait une méthode privée d'une autre classe).

## 10/09/2026

- Correction du scan QR muet dans la PWA de pointage : le CDN `cdnjs` était bloqué par l'hébergeur OVH.
- Ajout d'un diagnostic complémentaire pour le scan QR toujours muet.
- Désactivation temporaire de `BarcodeDetector`, bascule forcée sur `jsQR` (solution pragmatique en attendant mieux).
- Page Adhérents : renommage + ajout d'un formulaire d'ajout dans une fenêtre modale.

## 12/09/2026

- Correctif `update_member_renouvellement()` (`class-admin-adhesions.php`) : la validation d'une demande de **renouvellement** forçait `actif = 1` immédiatement, sans repasser par la case "Actif" de la fiche membre — contrairement à une première adhésion (`create_member()`), qui elle attend bien cette activation manuelle après vérification des pièces (certificat médical, etc.). Mis en cohérence : les deux flux exigent désormais la même activation manuelle, décidée avec l'utilisateur suite à une doléance sur l'affichage de la Vue globale de `tkd-cotisations` à la bascule de saison.
- Correctif erreur base de données lors de la validation d'une demande d'adhésion/renouvellement (`❌ Erreur base de données lors de la création de l'élève`) : les colonnes `taille_tshirt` et `taille_pantalon` de `sp_cal_eleves` étaient en `varchar(10)`, trop court pour une saisie comme « 14 ans / xs » (11 caractères) — MySQL en mode strict rejette l'INSERT plutôt que de tronquer. Colonnes élargies à `varchar(20)` (`includes/class-db.php`), avec une migration (`v10.19` dans `maybe_upgrade()`) qui élargit aussi les colonnes déjà créées sur les bases existantes (test + prod, au prochain chargement d'une page d'admin).

---

*Dernière mise à jour de ce fichier : 12/09/2026. À compléter à chaque nouvelle session de travail — une ligne datée suffit.*

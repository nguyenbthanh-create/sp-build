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
- **Retour sur la décision ci-dessus** : après coup, l'utilisateur a précisé que le workflow d'adhésion avait été mal évalué — la validation d'une demande par le bureau constitue déjà la vérification administrative (pièces, certificat médical, etc.), il n'y a donc pas d'étape de contrôle séparée à faire ensuite sur la fiche membre. `create_member()` et `update_member_renouvellement()` remettent donc `actif = 1` automatiquement dès la validation (nouvelle adhésion **et** renouvellement), ce qui fait aussi réapparaître directement l'adhérent dans la Vue globale de `tkd-cotisations` (`WHERE e.actif = 1`) sans manipulation manuelle supplémentaire.

## 24/09/2026

- **Correctif bug date de naissance** (repéré via un bug remonté par l'utilisateur sur les fiches Ingrid IUNG et Thomas MOLAS) : `create_member()` et `update_member_renouvellement()` (`class-admin-adhesions.php`) recopiaient la date de naissance complète au format SQL (`AAAA-MM-JJ`, telle que stockée dans `sp_adhesions_pending`) directement dans le champ `sp_cal_eleves.date_naissance`, qui n'est censé contenir que le jour/mois (`JJ/MM`, l'année étant dans une colonne séparée `annee_naissance` — cf. `class-admin-members.php`). Ajout d'une méthode `split_date_naissance()` qui reconvertit correctement, aux deux points d'entrée (nouvelle adhésion et renouvellement).
- **Correctif associé** : le pré-remplissage du formulaire de renouvellement front-end (`class-front-adhesion.php::build_prefill_from_eleve()`) appelait `to_iso_date()` uniquement sur `date_naissance` (« JJ/MM » seul, sans année) — aucun des formats reconnus ne correspond à une date sans année, donc le champ ressortait toujours vide au renouvellement. Nouvelle méthode `ddn_eleve_to_iso()` qui recombine `date_naissance` + `annee_naissance` avant conversion.
- **Reste à faire** : les fiches déjà abîmées par le bug (créées/renouvelées avant ce correctif) ne sont pas corrigées automatiquement — nettoyage SQL à faire à la main sur les bases test + prod (requête fournie à l'utilisateur en dehors de ce dépôt, hors phpMyAdmin non accessible depuis cet environnement).
- **Correctif compteur « Adhérents (N) » non mis à jour au filtrage par saison** (`class-admin-members.php::page_eleves()`) : ce nombre était un `count($eleves)` PHP figé au chargement de la page, jamais recalculé par le filtrage JS côté client (`applyFilter()`, qui ne fait que masquer/afficher des lignes). Ajout d'une fonction `updateTotal()` qui recompte les lignes visibles et met à jour un `<span id="sp-eleves-total">` dans le titre, appelée à chaque changement de filtre (nom, catégorie, discipline, saison).

## 25/09/2026

- **Refonte visuelle du planning hebdomadaire front-end** (`templates/planning.php`, shortcode `[sp_cal_planning]`), pour l'aligner sur le nouvel habillage de tkdclaira.fr : fond blanc, texte `#222`, titres Lato, rouge `#D4000F` en accent unique (semaine en cours, jour courant, survol) à la place du bleu marine. Pastilles de cours en teinte claire de la couleur de catégorie + liseré coloré (variable CSS `--c`, au lieu d'un aplat saturé à texte blanc), texte agrandi (10 → 12 px), légende allégée. Tous les styles sont préfixés `#sp-planning-wrapper` (l'ID l'emporte sur les styles de table du thème, qui rendaient les cellules vides en gris foncé) et ne touchent pas `calendar.css`, dont le calendrier partage les classes du bandeau de navigation. Non visualisé sur le vrai site (pas de runtime PHP ici) : à valider sur le site de test.
- **Gestion des doboks — V1** (nouveau fichier `includes/class-dobok.php` + `assets/css/dobok-admin.css`, branché dans `class-admin.php`) : nouvelle page admin « 🥋 Doboks » (capacité `sp_gestion_adhesions`, donc bureau + secrétaire). Stock tenu par un **journal de mouvements** (achat, remise, restitution avec état — un dobok « à réformer » ne rentre pas en stock —, non rendu, ajustement d'inventaire) dans deux nouvelles tables `sp_cal_dobok_lots` et `sp_cal_dobok_mouvements`, créées automatiquement au premier chargement admin. Onglets : **Stock** (grille modèle × taille, prêtés, seuil d'alerte, saisie d'inventaire qui sert aussi au stock de départ), **Adhérents** (actifs de la saison avec dobok détenu, modèle et taille attendus, alertes — col noir requis, a grandi, nouveau modèle possible, données manquantes, plus d'un échange de taille — et panneau « Gérer » pour remettre / échanger / restituer / confirmer ; vue « Doboks à récupérer » pour les anciens adhérents ; bouton d'attribution présumée en masse pour le démarrage), **Achats** (lots), **Journal** (filtrable, suppression d'une saisie erronée), **Réglages** (gamme de tailles, marge de croissance, seuil, bornes d'âge des catégories). Spécification et écarts : `EVOLUTION.md` du 25/09/2026. Non testé sur un vrai WordPress (pas de runtime PHP ici) : à valider sur le site de test.
- **Gestion des doboks — V2** (`includes/class-dobok.php`, hook ajouté dans `class-token.php`) : les adhérents peuvent demander depuis leur fiche personnelle (lien `?token=`, nouveau bloc « Mes doboks » avec mensurations, doboks détenus, taille conseillée) un changement de taille ou de modèle, un remplacement (abîmé), une restitution ou un premier dobok. Nouvelle table `sp_cal_dobok_demandes` ; le dobok est **réservé automatiquement** si le stock disponible le permet, sinon la demande part en liste d'attente, réservée automatiquement à l'arrivée d'un lot ou d'un retour. Nouvel onglet admin **« Demandes / distribution »** (cartes utilisables sur téléphone : « Échange fait », « Garde son dobok actuel », « Refuser »), stock enrichi (disponible, réservés, retours attendus, en attente, encadré « À commander »), pastille du nombre de demandes dans le menu, mails au bureau et aux familles (désactivables dans les Réglages). Non testé sur un vrai WordPress : à valider sur le site de test.
- **Doboks : adhérents du renforcement musculaire exclus du circuit** (précision de l'utilisateur) : repérés par leur discipline (`categorie_saisie` = `RENFO` ou ancien libellé « Renfo… »), ils n'apparaissent plus dans la liste Adhérents ni dans l'attribution présumée, et n'ont pas de bloc « Mes doboks » sur leur fiche. Exception : s'ils ont encore un dobok du club (ancien pratiquant de taekwondo), ils apparaissent dans la vue « Doboks à récupérer » et leur fiche ne propose que de le rendre.

## 26/09/2026

- **Doboks : fenêtre « Règles des doboks »** (onglet Adhérents de la page Doboks, bouton ⓘ à côté des filtres) : rappel de la règle du club appliquée par le module, et référence World Taekwondo (tenue de poomsae par catégorie, âge, grade ; dobok blanc standard par grade), avec les écarts assumés par le club et les sources (WT Guidelines on Identifications 2022 ; FFTDA, règlement des compétitions Poomsae de sept. 2025, art. 2.6 « Tenue », qui remplace la référence British Taekwondo d'abord utilisée, à la demande de l'utilisateur). Fenêtre modale native `<dialog>`, rien d'affiché en permanence. Âge master par défaut passé de 50 à **51 ans** (valeur WT) ; sans effet si le réglage a déjà été enregistré.
- Cahier des charges du module Doboks pour la refonte ajouté dans `EVOLUTION.md` (le fichier `../md/03-proposition-refonte.md` n'étant pas sur cet ordinateur).

- **Doboks : col rouge et noir pour les Poom** (précision de l'utilisateur) : nouveau modèle blanc « Blanc col rouge et noir » (`col_poom`). Le col du dobok blanc suit désormais le grade : blanc (Keup), rouge et noir (Poom), noir (Dan) ; une « ceinture noire » sans précision est classée Poom avant 15 ans, Dan après. L'alerte de l'onglet Adhérents devient « Col à changer » dès que le col détenu ne correspond pas au grade. Le col du dobok couleur reste fixé par le modèle (catégorie), quelle que soit la ceinture. Fenêtre des règles et spécification mises à jour.

---

*Dernière mise à jour de ce fichier : 26/09/2026. À compléter à chaque nouvelle session de travail — une ligne datée suffit.*

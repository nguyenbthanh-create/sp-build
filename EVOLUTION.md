# Évolution — SP_Build (SportPress Calendar PRO)

Pistes d'évolution connues, ce qui est prévu mais pas encore fait. Contrairement à `REALISATION.md` (ce qui a été fait), ce fichier liste ce qui **reste à faire ou à décider**, avec la date à laquelle chaque piste a été identifiée.

## Constat au 11/09/2026

D'après `CLAUDE.md`, ce plugin est actuellement le **legacy** (l'existant), patché au fil des doléances. Une refonte complète est prévue mais n'a pas commencé :

- **Refonte complète planifiée, non démarrée** — le plan et l'architecture cible sont censés être décrits dans `../md/03-proposition-refonte.md`. *Ce fichier n'est pas présent sur cet ordinateur* (voir [../JOURNAL.md](../JOURNAL.md) — dossier `../md/` manquant, probablement resté sur l'autre ordinateur). À récupérer avant de reprendre ce chantier.
- **Backlog de demandes ("doléances")** normalement suivi dans `../md/doleances.md` — également absent ici pour la même raison. Ne pas repartir de zéro sans l'avoir récupéré : il peut contenir des demandes non encore traitées.
- **Journal des modifications légataire** normalement tenu dans `../md/04-journal-modifications.md` — ce fichier `REALISATION.md` peut désormais prendre le relais pour les prochaines sessions, mais l'historique antérieur au 01/09/2026 (avant la mise en place de Git) n'existe probablement que dans ce fichier manquant.

## Pistes techniques ouvertes (relevées dans le code / CLAUDE.md)

- **Doublons d'implémentation** à consolider un jour : le scan QR et le calcul des scores de jury existent chacun en plusieurs implémentations indépendantes dans le code — source d'incohérences à surveiller, cible naturelle pour la refonte plutôt qu'un correctif ponctuel.
- **API REST dupliquée** : des routes `spcal/v1` sont enregistrées à la fois dans `class-admin.php` et `class-ajax.php` (chevauchement documenté dans `02-etat-des-lieux.md`, non récupéré ici) — à nettoyer.
- **`class-jury-mobile.php` en double** (racine + `includes/`) — la copie à la racine est morte, à supprimer un jour pour éviter toute confusion future.
- **Scan QR (10/09/2026)** : la bascule sur `jsQR` (au lieu de `BarcodeDetector` natif, bloqué par le CDN OVH) est qualifiée de « piste pragmatique » dans le commit du 10/09/2026 — solution de contournement, pas une solution définitive. À revisiter (par ex. héberger la librairie en local plutôt que sur un CDN externe).

## 24/09/2026

- **Notification push PWA au bureau** : demandé par l'utilisateur comme piste pour la refonte du plugin, en lien avec le rappel de dépôt de chèques ajouté côté `tkd-cotisations` (email quotidien pour l'instant, cf. son `REALISATION.md`). Idée : remplacer/compléter ce rappel email par une notification push sur la PWA bureau (`render_pwa_app()`), qui a déjà une infra `sp_cal_push_subs` pour les abonnements push (cf. `class-admin.php`) — à vérifier si elle est réutilisable telle quelle ou si elle est propre à un autre usage. Non implémenté, à mettre dans un coin pour la refonte, pas pour un correctif du legacy.

## 25/09/2026 — Gestion des doboks (spécification validée, V1 + V2 implémentées)

Le club **prête** (pas un don) à chaque adhérent un dobok blanc et un dobok couleur. Objectif : gérer le stock d'une saison à l'autre — achats, restitutions, échanges de taille, changements de modèle — et savoir à tout moment qui a quoi.

### Règles métier

- **Tailles de 10 en 10 cm** (gamme paramétrable, ex. 100 → 200). Taille suggérée = `taille_cm` arrondie à la dizaine supérieure, plus une marge de croissance paramétrable (ex. 126 cm + marge 5 → 140). C'est une **suggestion**, jamais imposée.
- **Blanc** : un seul modèle pour tous les âges (pas de gamme enfant/adulte). Col **noir** si ceinture noire, sinon col **blanc** — déduit automatiquement du champ `grade`, jamais choisi à la main.
- **Couleur** : acheté **par ensemble** (veste + pantalon). Modèle déduit de la catégorie de compétition + sexe (`extra_data['sexe']`) :

  | Modèle | Catégorie | Col veste | Pantalon |
  |---|---|---|---|
  | Cadet G | ≤ 14 ans, garçon | Rouge et noir | Bleu |
  | Cadet F | ≤ 14 ans, fille | Rouge et noir | Rouge |
  | Junior/Senior H | 15-49 ans, homme | Bleu foncé / noir | Bleu foncé / noir |
  | Junior/Senior F | 15-49 ans, femme | Bleu foncé / noir | Bleu clair |
  | Master | 50+ | Bleu foncé | Bleu foncé |

  - Moins de 12 ans : même modèle que les cadets (interprétation de la réponse du 25/09 — **à confirmer**).
  - Les **Baby** sont dotés eux aussi (blanc + couleur), avec les mêmes règles que tous les adhérents.
  - Catégorie calculée **selon la règle de compétition** (année de naissance), à distinguer de la colonne `categorie_age` existante (Baby / Enfant / Ado-adulte) qui ne correspond pas. Bornes d'âge et année de référence paramétrables (le tableau fourni hésite entre 50+ et 51+ pour les masters).
  - La veste master spécifique (dorée/jaune selon niveau de compétition) est hors périmètre.
  - Sexe non renseigné → modèle indéterminable → anomalie affichée au bureau.
- **Échange de taille** : une **possibilité, jamais une obligation** (beaucoup gardent l'ancien, pour les deux types). Au plus **1 échange de taille par saison et par type** ; au-delà, **non bloquant** mais signalé au bureau (badge + alerte mail).
- **Changement de modèle** (passage ceinture noire → col noir ; changement de catégorie, ex. cadet → junior) : demande **générée automatiquement**, validée par le bureau, **ne compte pas** comme échange de taille.
- **Prêt** : le dobok reste rattaché à l'adhérent jusqu'à restitution. Départ / non-renouvellement → retour attendu, liste « Doboks à récupérer », statut « non restitué » visible sur la fiche (y compris si l'adhérent revient une saison ultérieure).

### Modèle de données

Principe : **aucun compteur de stock stocké** — tout est calculé depuis un journal de mouvements (historique complet, stock impossible à désynchroniser, nombre d'échanges gratuit). Nouvelle classe singleton (pattern `get_instance()` des classes récentes), tables dédiées créées avec une garde de version de schéma (comme `SP_Front_Adhesion::create_table()`), conçues pour être reprises telles quelles par la refonte.

- `sp_cal_dobok_refs` — référence = type (blanc / couleur) × modèle (col_blanc, col_noir, cadet_g, cadet_f, js_h, js_f, master) × taille ; seuil d'alerte ; actif.
- `sp_cal_dobok_lots` — achats : ref, quantité, date d'achat, prix unitaire, fournisseur, statut (commandé / reçu). La date d'achat est portée par le lot (pas de suivi individuel numéroté ; ajoutable plus tard si besoin de suivre l'usure).
- `sp_cal_dobok_mouvements` — ref, élève (nullable), type (réception achat, remise, restitution, réforme, ajustement inventaire), état à la restitution (bon / usé / à réformer — un dobok à réformer ne rentre pas en stock disponible), saison, demande liée, date, auteur (utilisateur WP ou « lien adhérent »), drapeau « à confirmer » (dotations présumées du démarrage).
- `sp_cal_dobok_demandes` — élève, nature (échange de taille, changement de modèle, restitution, remplacement abîmé, dotation initiale), ref rendue, ref souhaitée, statut (demandée → réservée → remise ; ou en attente de stock, refusée, annulée), origine (adhérent / bureau / automatique), hors règle (bool), dates.

Indicateurs calculés par référence : **effectif** (physiquement au club), **réservé** (promis, pas remis), **retours attendus**, **disponible** (effectif − réservé), **potentiel** (disponible + attendus). La réservation se fait **à l'acceptation** de la demande, pour ne pas promettre deux fois le même dobok. Dotation actuelle d'un adhérent = déduite des mouvements (remises − restitutions).

### Parcours adhérent (fiche ouverte par le lien personnel `?token=`)

- Bloc « Mensurations » + bloc « Mes doboks » (ce qu'il a, taille, depuis quand) + taille suggérée.
- Actions : « Changer de taille », « Je rends mon dobok », « Il est abîmé ». Si stock insuffisant → **liste d'attente** + mail automatique quand un lot arrive et que le dobok est réservé.
- Au **renouvellement** (moment où les mensurations sont remises à jour) : question « Le dobok est-il encore à la bonne taille ? » avec la taille suggérée pré-remplie — c'est là que doivent naître la plupart des demandes.
- Garde-fous (le lien permet désormais d'agir) : étape de confirmation, une seule demande en cours à la fois, annulable tant que non traitée, aucune action si adhérent inactif, action journalisée. La régénération du lien (bouton « Renvoyer » existant, `SpCalPro_Token::resend_token_ajax`) invalide l'ancien.
- Le bureau peut créer une demande à la place de la famille.

### Côté bureau

- **Écran mobile de distribution** (séance de rentrée au dojo) : liste des remises/échanges prévus, boutons « Rendu ✓ » (avec état) / « Remis ✓ » qui convertissent retours attendus et réservations en mouvements réels. Emplacement à trancher en V2 : PWA bureau (`render_pwa_app()`) ou page admin responsive.
- **wp-admin** :
  1. Tableau de stock type × modèle × taille (effectif / réservé / attendu / disponible, seuil d'alerte).
  2. Adhérents actifs de la saison et leurs dotations, avec anomalies détectées : ceinture noire en col blanc ; changement de catégorie compétition ; `taille_cm` nettement au-dessus de la taille du dobok ; sexe ou taille manquants ; sans dobok ; plus d'un échange de taille dans la saison ; dobok non restitué.
  3. File des demandes par statut.
  4. Journal des mouvements (filtrable, export CSV).
  5. Achats : commande puis réception d'un lot (qui entre alors en stock).
  6. Réglages : gamme de tailles, marge de croissance, modèles, seuils, bornes d'âge, règle « 1 échange / saison / type ».
- **Aide à la commande** : besoins = demandes en attente + nouveaux adhérents prévus + changements de catégorie de la saison − disponible → « commander 4 blancs 130, 2 Cadet F 140 ».
- **Début de saison** : détection en masse (« 9 adhérents changent de catégorie ») → demandes automatiques en un clic.
- **Mails** : récapitulatif quotidien au bureau plutôt qu'un mail par demande ; mail immédiat seulement pour les alertes (échange hors règle, stock épuisé). Côté famille : accusé de réception, puis confirmation de l'échange / de la mise en attente.
- Sécurité : nonce + capability sur chaque handler, à l'image de `class-admin-inscriptions.php`.

### Démarrage (reprise de l'existant)

- Écran d'inventaire initial : quantités par référence.
- Attribution **présumée en masse** aux adhérents actuels (taille déduite de `taille_cm`, modèle déduit du grade / de la catégorie / du sexe), marquée « à confirmer » — confirmée ou corrigée par la famille au renouvellement ou par le bureau à la distribution.

### Découpage

- **V1** : références, lots, journal des mouvements, tableau de stock, liste adhérents/dotations avec anomalies, inventaire initial + attribution présumée.
- **V2** : demandes adhérent (fiche token + renouvellement), réservations, écran mobile de distribution, mails.
- **V3** : aide à la commande, demandes automatiques (ceinture noire, changement de catégorie), inventaire annuel avec ajustements, liste d'attente notifiée.

### Décisions complémentaires (25/09/2026)

- Moins de 12 ans = modèle cadet : **confirmé**.
- Changement de catégorie : l'adhérent **peut garder** l'ancien modèle (signalé en information, pas imposé).
- Catégorie calculée selon la règle de compétition : les valeurs exactes (année de référence de la saison, borne master 50 ou 51) sont réglables dans l'onglet Réglages — à relever dans le règlement fédéral de la saison.

### V1 réalisée le 25/09/2026 (`includes/class-dobok.php`, page admin « 🥋 Doboks »)

Écarts assumés par rapport au modèle de données ci-dessus, pour simplifier :
- **Pas de table `refs`** : une référence est simplement le couple `modele` × `taille`, porté directement par les lots et les mouvements. Le seuil d'alerte est **global** (un seul réglage) plutôt que par référence.
- Chaque mouvement stocke son effet précalculé (`mvt_stock`, `mvt_eleve`) : stock = `SUM(mvt_stock)`, dotation d'un adhérent = `SUM(mvt_eleve)`.
- Un mouvement `dotation_initiale` (doboks déjà chez les adhérents au démarrage) n'a aucun effet sur le stock ; `a_confirmer = 1` pour les attributions présumées en masse.
- Le décompte « 1 échange de taille par saison » s'appuie sur le `motif` des restitutions (`taille`, déduit automatiquement lors d'un échange quand seule la taille change) : un changement de modèle ou un remplacement ne compte pas.

### V2 réalisée le 25/09/2026

Nouvelle table `sp_cal_dobok_demandes` (schéma v2). Bloc « Mes doboks » dans la fiche adhérent (`?token=`, via le hook `sp_cal_fiche_membre_apres_grade` ajouté dans `class-token.php`), onglet admin « Demandes / distribution », mails bureau + familles, pastille de menu. Écarts / choix par rapport à la spécification :
- **Réservation automatique à la création** de la demande (si disponible > 0), plutôt qu'à l'acceptation par le bureau : premier arrivé, premier servi, sans clic du bureau. Le bureau peut toujours refuser ou clore.
- **Une demande en cours par type de dobok** (blanc / couleur), pas une seule au total : un adhérent peut avoir besoin des deux.
- Nouvelle nature « premier dobok » pour un adhérent qui n'en a encore aucun d'enregistré.
- **Écran de distribution dans wp-admin** (onglet responsive, cartes et gros boutons) plutôt que dans la PWA : réutilise la connexion et les droits existants, sans toucher au code de la PWA.
- **Mails immédiats** (un par demande) au lieu d'un récapitulatif quotidien : le projet a abandonné wp-cron (non fiable, cf. renouvellement) ; désactivable dans les Réglages.
- Liste d'attente : réservée automatiquement (ordre d'arrivée) à chaque lot reçu, retour, inventaire ou demande close, famille prévenue par mail. Encadré « À commander » dans l'onglet Stock (besoins des demandes en attente).
- Une action directe du bureau dans l'onglet Adhérents solde la demande ouverte qu'elle satisfait.

**Précision du 25/09/2026** : les adhérents du **renforcement musculaire** (`categorie_saisie` RENFO) ne sont pas concernés par les doboks — exclus de la liste, de l'attribution présumée et des demandes, sauf pour rendre un dobok qu'ils auraient encore (`SP_Cal_Dobok::est_concerne()`).

**Reporté (V3)** : question « le dobok est-il encore à la bonne taille ? » dans le formulaire de renouvellement — touche le flux d'adhésion (table `sp_adhesions_pending`, validation), le plus sensible du plugin ; en attendant, le lien vers la fiche (et donc le bloc « Mes doboks ») est déjà envoyé aux familles. Également en V3 : demandes automatiques (ceinture noire, changement de catégorie), aide à la commande plus complète (prévision nouveaux adhérents).

## Comment tenir ce fichier à jour

Ajouter une entrée datée dès qu'une idée d'amélioration ou une demande non traitée apparaît, même si elle n'est pas urgente — c'est le rôle de ce fichier de ne pas perdre ces idées entre deux sessions.

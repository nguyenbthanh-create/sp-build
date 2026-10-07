# Évolution — SP_Build (SportPress Calendar PRO)

Pistes d'évolution connues, ce qui est prévu mais pas encore fait. Contrairement à `REALISATION.md` (ce qui a été fait), ce fichier liste ce qui **reste à faire ou à décider**, avec la date à laquelle chaque piste a été identifiée.

## Constat au 11/09/2026

D'après `CLAUDE.md`, ce plugin est actuellement le **legacy** (l'existant), patché au fil des doléances. Une refonte complète est prévue mais n'a pas commencé :

- **Refonte complète planifiée, non démarrée** — le plan et l'architecture cible sont censés être décrits dans `../md/03-proposition-refonte.md`. *Ce fichier n'est pas présent sur cet ordinateur* (voir [../JOURNAL.md](../JOURNAL.md) — dossier `../md/` manquant, probablement resté sur l'autre ordinateur). À récupérer avant de reprendre ce chantier.
- **Backlog de demandes ("doléances")** normalement suivi dans `../md/doleances.md` — également absent ici pour la même raison. Ne pas repartir de zéro sans l'avoir récupéré : il peut contenir des demandes non encore traitées.
- **Journal des modifications légataire** normalement tenu dans `../md/04-journal-modifications.md` — ce fichier `REALISATION.md` peut désormais prendre le relais pour les prochaines sessions, mais l'historique antérieur au 01/09/2026 (avant la mise en place de Git) n'existe probablement que dans ce fichier manquant.

## Pistes techniques ouvertes (relevées dans le code / CLAUDE.md)

- **Doublons d'implémentation** à consolider un jour : le scan QR existe en plusieurs implémentations indépendantes dans le code — source d'incohérences à surveiller, cible naturelle pour la refonte plutôt qu'un correctif ponctuel.
- **API REST dupliquée** : des routes `spcal/v1` sont enregistrées à la fois dans `class-admin.php` et `class-ajax.php` (chevauchement documenté dans `02-etat-des-lieux.md`, non récupéré ici) — à nettoyer.
- **Pointage QR déclaré trois fois en production (constaté le 03/10/2026)** : sur le site, les quatre routes `spcal/v1/pointage/cours`, `/scan`, `/cours_eleve` et `/lot` ont chacune **3 déclarations** (index `/wp-json/spcal/v1`) : (1) `SpCalPro_Admin::register_pointage_rest()` dans `class-admin.php`, (2) `SpCalPro_Ajax::register_pointage_rest()` dans `class-ajax.php`, (3) l'extension autonome **« SP Pointage QR »** installée à part dans `wp-content/plugins/` (copies `sp-pointage.php` et `sp-pointage/sp-pointage.php` ; l'une est active, probablement une version ancienne sans la route `grade_contenu`). WordPress sert la **première** déclaration : c'est l'extension, chargée avant sp-build (ordre alphabétique `sp-pointage` < `sp_build`) — la réponse porte l'en-tête `Allow: POST`, que seule sa version renvoie (celles de sp-build sortent par `exit`). **Conséquences** : une correction du pointage faite dans sp-build ne s'applique pas tant que l'extension est active ; l'extension lit les tables avec un préfixe écrit en dur (`mod237_`). **À faire** (de préférence dans la refonte) : garder une seule déclaration dans sp-build, vérifier que le pointage fonctionne avec elle, puis désactiver et supprimer l'extension « SP Pointage QR ». **Ne pas désactiver l'extension avant cette vérification.** La copie morte rangée dans `sp-build/includes/sp-pointage.php` (jamais chargée) a été supprimée le 03/10/2026.
- ~~**`class-jury-mobile.php` en double**~~ — réglé le 03/10/2026 : tout l'ancien module Jury a été supprimé (voir la section Passages de grade).
- **Scan QR (10/09/2026)** : la bascule sur `jsQR` (au lieu de `BarcodeDetector` natif, bloqué par le CDN OVH) est qualifiée de « piste pragmatique » dans le commit du 10/09/2026 — solution de contournement, pas une solution définitive. À revisiter (par ex. héberger la librairie en local plutôt que sur un CDN externe).

## 24/09/2026

- **Notification push PWA au bureau** : demandé par l'utilisateur comme piste pour la refonte du plugin, en lien avec le rappel de dépôt de chèques ajouté côté `tkd-cotisations` (email quotidien pour l'instant, cf. son `REALISATION.md`). Idée : remplacer/compléter ce rappel email par une notification push sur la PWA bureau (`render_pwa_app()`), qui a déjà une infra `sp_cal_push_subs` pour les abonnements push (cf. `class-admin.php`) — à vérifier si elle est réutilisable telle quelle ou si elle est propre à un autre usage. Non implémenté, à mettre dans un coin pour la refonte, pas pour un correctif du legacy.

- **Complément du 06/10/2026 — notifications push pour tous (familles, entraîneurs, bureau), avec pastille rouge** : un adhérent qui n'a pas réglé les notifications de sa messagerie ne voit pas les emails du club ; l'utilisateur voudrait les mêmes notifications qu'une application (avec le chiffre sur l'icône). **Faisable (Web Push), non codé — à reprendre plus tard.**
  - **État constaté du code** : la moitié *réception* existe — application `/app/` installable, service worker (`get_pwa_sw_code()` dans `class-admin.php` : événements `push` et `notificationclick`), abonnement côté navigateur (`spCalSetupPush()`), table `sp_cal_push_subs`, routes REST `spcal/v1/push/vapid-public`, `/push/subscribe`, `/push/unsubscribe`. La moitié *envoi* manque : la classe `SpCalPro_WebPush` (clés VAPID, envoi) est appelée mais n'existe nulle part — donc pas de clé publique, l'application ne s'abonne jamais et rien ne part.
  - **À faire** : (1) moteur d'envoi Web Push en PHP sans bibliothèque externe (signature VAPID ES256 + chiffrement aes128gcm, RFC 8291/8292, via OpenSSL), nettoyage des abonnements expirés (réponses 404/410) ; (2) bouton « Activer les notifications » dans l'application (l'iPhone exige un geste de l'utilisateur) + guide « ajoutez d'abord l'application à l'écran d'accueil » sur iPhone ; (3) boîte de messages lus / non lus dans l'application, qui alimente le chiffre (`navigator.setAppBadge`), remis à zéro à l'ouverture ; (4) brancher les événements, en priorité **l'annulation de cours**, puis invitations, rappels entraîneurs, sondage, renouvellement, rappel bureau (chèques) ; (5) **garder l'email en parallèle** (le push complète, ne remplace pas).
  - **Limites** : iPhone = iOS 16.4+ et application **ajoutée à l'écran d'accueil** obligatoirement (sinon aucune notification web) ; Android = notifications sans installation, pastille souvent un simple point selon le téléphone ; un abonnement par téléphone et par fiche (un parent de deux enfants : deux activations, ou regrouper les fratries) ; tag `spcal-notif` du service worker actuel = une notification remplace la précédente (à revoir) ; tests sur un vrai iPhone et un vrai Android avant d'annoncer. Gratuit, sans prestataire (services push d'Apple / Google), hors quota Brevo.

## 25/09/2026 — Gestion des doboks (spécification validée, V1 + V2 implémentées)

Le club **prête** (pas un don) à chaque adhérent un dobok blanc et un dobok couleur. Objectif : gérer le stock d'une saison à l'autre — achats, restitutions, échanges de taille, changements de modèle — et savoir à tout moment qui a quoi.

### Règles métier

- **Tailles de 10 en 10 cm** (gamme paramétrable, ex. 100 → 200). Taille suggérée = `taille_cm` arrondie à la dizaine supérieure, plus une marge de croissance paramétrable (ex. 126 cm + marge 5 → 140). C'est une **suggestion**, jamais imposée.
- **Blanc** : un seul modèle pour tous les âges (pas de gamme enfant/adulte). Col déduit automatiquement du champ `grade` : **blanc** (Keup), **rouge et noir** (Poom), **noir** (Dan) — précision du 26/09/2026, jamais choisi à la main.
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
- **Changement de modèle** (passage Poom → col rouge et noir, passage Dan → col noir ; changement de catégorie, ex. cadet → junior) : demande **générée automatiquement**, validée par le bureau, **ne compte pas** comme échange de taille.
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
  2. Adhérents actifs de la saison et leurs dotations, avec anomalies détectées : col du blanc ne correspondant pas au grade ; changement de catégorie compétition ; `taille_cm` nettement au-dessus de la taille du dobok ; sexe ou taille manquants ; sans dobok ; plus d'un échange de taille dans la saison ; dobok non restitué.
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

**Retours de test du 28/09/2026** (schéma v3 : colonnes `mesures_maj`, `mesures_maj_at` sur les demandes) :
- Bloc « Mes doboks » : pointure affichée avec les autres mesures ; bouton ✏️ pour les **modifier en cours d'année** (auparavant seulement à l'inscription / au renouvellement, ou par le bureau dans wp-admin). Enregistré tout de suite sur la fiche, et aussi sur un renouvellement en attente de validation (sinon la validation écraserait la mise à jour).
- **Mesures changées alors qu'une demande est en cours** : la demande n'est pas modifiée automatiquement (le bureau décide) ; elle est annotée (« 📏 Mesures modifiées le … : Taille 128 → 135 cm », avec la nouvelle taille conseillée si elle diffère de la taille demandée), sur la carte de l'onglet Demandes, et le bureau reçoit un mail. Sans demande en cours : pas de mail, l'alerte « A grandi » de l'onglet Adhérents suffit, et l'adhérent est invité à faire une demande si son dobok est trop petit.
- Onglet Demandes : tri par nom (A → Z) en plus de l'ordre d'arrivée (la recherche par nom existait déjà).

**Reporté (V3)** : question « le dobok est-il encore à la bonne taille ? » dans le formulaire de renouvellement — touche le flux d'adhésion (table `sp_adhesions_pending`, validation), le plus sensible du plugin ; en attendant, le lien vers la fiche (et donc le bloc « Mes doboks ») est déjà envoyé aux familles. Également en V3 : demandes automatiques (ceinture noire, changement de catégorie), aide à la commande plus complète (prévision nouveaux adhérents).

## 26/09/2026 — Refonte : cahier des charges du module Doboks

> À reporter dans `../md/03-proposition-refonte.md` (cahier des charges de la refonte) dès que le dossier `md/` est récupéré depuis l'autre ordinateur — il n'existe pas sur celui-ci (cf. [../JOURNAL.md](../JOURNAL.md), étape 7). Ce qui suit est la version de référence en attendant.

Le module legacy (`includes/class-dobok.php`, V1 + V2 du 25/09/2026) sert de **prototype validé** : garder ses règles métier et son principe de journal de mouvements, mais le reconstruire proprement dans la nouvelle architecture.

### Règles métier à conserver

- Prêt (pas don) d'un dobok **blanc** + d'un dobok **couleur** à chaque adhérent **hors renforcement musculaire**. Restitution en cas de départ.
- Blanc : un seul modèle ; col déduit du grade, **conforme à WT** : blanc (Keup), rouge et noir (Poom), noir (Dan). Couleur : col fixé par le modèle (catégorie), quelle que soit la couleur de ceinture.
- Couleur : ensemble veste + pantalon, modèle = catégorie de compétition × sexe, calqué sur la tenue de poomsae FFTDA / WT (règlement FFTDA des compétitions Poomsae, sept. 2025, art. 2.6, reprenant WT) : Cadet 12-14 (col rouge et noir, pantalon bleu garçon / rouge fille), Junior/Senior 15-50 (col noir, pantalon « T-Black » homme / bleu clair femme), Master 51+ (WT : veste dorée ; club : bleu foncé). Moins de 12 ans → modèle cadet (choix du club : pas de catégorie poomsae FFTDA avant 12 ans). Couleur prêté à tous, ceintures de couleur comprises (écart assumé : WT le réserve aux Poom/Dan en compétition).
- Âge de catégorie calculé sur l'année de naissance, bornes et année de référence **paramétrables** (valeur WT par défaut, à revérifier chaque saison).
- Tailles de 10 en 10 cm, taille suggérée = taille arrondie à la dizaine supérieure + marge paramétrable.
- Un adhérent peut garder son ancien modèle / sa taille ; **1 échange de taille par saison et par dobok**, non bloquant (alerte bureau). Changement de modèle et remplacement ne comptent pas.
- Rappel des règles consultable depuis l'écran de gestion (fenêtre « Règles des doboks »), avec sources FFTDA et WT datées.

### Données (à normaliser dans le nouveau schéma)

- Journal de mouvements comme **seule source de vérité** (effets précalculés stock / adhérent) ; lots d'achat ; demandes avec statuts (réservée, en attente, terminée, refusée, annulée) et réservation automatique à la création.
- Prérequis sur la fiche adhérent, aujourd'hui mal rangés dans le legacy : **sexe** en vraie colonne (legacy : `extra_data['sexe']`), **date de naissance complète** (legacy : `date_naissance` JJ/MM + `annee_naissance`), **discipline** en valeur contrôlée TKD / RENFO (legacy : `categorie_saisie` libre, reconnu par motif « renfo »), **taille en cm numérique** avec date de mesure (legacy : texte libre).
- Seuil d'alerte **par référence** (modèle × taille) au lieu d'un seuil global.
- Capacité dédiée (ex. `sp_gestion_doboks`) plutôt que de réutiliser `sp_gestion_adhesions`.

### Fonctions reportées du legacy, à prévoir dans la refonte

- Question « le dobok est-il encore à la bonne taille ? » dans le **formulaire de renouvellement**, avec création de la demande à la validation. Forme retenue le 28/09/2026 (reportée à la refonte, saison déjà entamée) : une seule question par dobok (blanc / couleur) — « Oui / Trop petit / Abîmé / Je n'en ai plus besoin » —, taille déduite des mesures saisies dans le même formulaire (taille conseillée), demande créée à la validation par le bureau (échange, remplacement ou restitution) avec réservation si stock. Option : demande « premier dobok » automatique à la validation d'une première inscription.
- **Demandes automatiques** : passage Poom ou Dan (résultat d'examen) → nouveau col du blanc ; changement de catégorie en début de saison (proposé, l'adhérent peut garder l'ancien).
- **Aide à la commande** : besoins = demandes en attente + nouveaux adhérents prévus + changements de catégorie − disponible.
- Notifications **push PWA** (bureau et familles) à la place / en plus des mails (cf. entrée du 24/09/2026), et écran de distribution intégré à la PWA bureau.
- Récapitulatif quotidien au bureau si un planificateur fiable existe (le legacy envoie un mail par demande, faute de cron fiable).
- Lien optionnel avec la comptabilité (coût des lots d'achat → sp-compta).

## 26/09/2026 — Refonte : une seule interface adhérent (décision validée)

> À reporter dans `../md/03-proposition-refonte.md` dès que le dossier `md/` est récupéré.

Constat (retour de test de l'utilisateur) : l'adhérent reçoit aujourd'hui deux accès au contenu différent — la **fiche de suivi** (`?token=`, rendue par `SpCalPro_Token::render_membre_fiche()` avec l'habillage du thème : carte, éligibilité Dan, grade, doboks, historiques) et l'**application PWA** (`[sp_cal_app]`, `SpCalPro_Admin::render_pwa_app()` : prochains cours, carte, calendrier, inscriptions, notifications push). Deux interfaces nées séparément, sans justification fonctionnelle.

**Décision** : dans la refonte, **une seule interface adhérent, la PWA** (installable, notifications, utilisable aussi sur ordinateur), qui reprend tout le contenu de la fiche (grades, éligibilité, présences, doboks et demandes, historiques). Un seul lien personnel par adhérent. L'onglet « Événements » doit afficher les événements à venir **et** les inscriptions ouvertes (aujourd'hui : inscriptions ouvertes seulement, d'où un onglet souvent vide). **Fait dans le legacy le 26/09/2026** : agenda 3 mois hors cours et anniversaires, badges « Vous concerne » / « Inscription ouverte », filtre « Tout / Me concerne » (`SpCalPro_DB::get_agenda_eleve()`), à reprendre tel quel. **Style** : fiche en clair (style du site) ; l'application garde son thème sombre — à trancher définitivement pour l'interface unique de la refonte (clair aux couleurs du site recommandé si elle doit aussi servir sur ordinateur, ou thème au choix).

**Étape 1 réalisée dans le legacy le 26/09/2026** (sans fusion, trop lourde dans le code actuel) : mail d'accès avec **un seul lien** (l'application si la page existe, sinon la fiche), bouton « Ouvrir l'application » sur la fiche, bouton « Ma fiche complète » dans l'application, onglet « Événements » de l'application renommé « Inscriptions ».

## 03/10/2026 — Passages de grade : nouveau module de notation (spécification validée, terminée : phases 1, 2 et 3)

> **Avancement** : les trois phases sont faites le 03/10/2026 — phase 1 (`includes/class-passages.php` : Préparer, Noter, Valider, épreuves transverses), phase 2 (carte « Mon prochain grade » et retour d'examen dans l'application adhérent), phase 3 (suppression de l'ancien module Jury, voir « Ancien module » ci-dessous) ; détail dans `REALISATION.md`. PHP à tester sur le site de test. **Reste en option** : supprimer en base les anciennes tables du jury devenues inutiles (`sp_cal_exam_sessions`, `_tables`, `_juges`, `_affectations`, `_epreuves`, `_grade_contenu`, `_zemita_*`, `sp_jury_notes`) et leurs migrations dans `maybe_upgrade()` — à faire de préférence dans la refonte, après sauvegarde.

Constat : le module Jury actuel (`class-admin-jury.php`, `class-jury.php`, `class-jury-mobile.php`, templates `jury-*.php`) n'a **jamais été utilisé** en vrai. Trop de manipulations d'onglet en onglet : 7 étapes (Paramètres → Juges → Candidats → Lancer → Suivi live → Transcription → Grades), plus trois écrans à préparer ailleurs (événement « examen » du calendrier, référentiel `exam_epreuves`, table `exam_grade_progression` à libellés exacts). Trois sources de vérité qui ne se parlent pas : le programme des grades vit dans TKD Parcours, les épreuves sont par catégorie d'âge, le grade suivant est saisi à la main. Notation +1/−1 sur 5–10 additionnée sur tous les juges et toutes les épreuves puis comparée à un seuil : opaque pour le jury, sans retour utile pour l'élève. Grade jamais écrit sur la fiche (étape « Transcription » manuelle). Calcul des scores en plusieurs exemplaires (cf. pistes techniques ci-dessus).

Inspirations (tour du web du 03/10/2026) : boucle complète candidats → notes → promotion depuis la même page (Kicksite) ; notation technique par technique sur mobile avec remarques et résultat envoyé à l'élève (Martialytics) ; interface juge linéaire sans aucune fonction d'admin, grosses zones tactiles, précédent / actuel / suivant, juge-président qui tranche (logiciels de jury de gymnastique : Turnfix, Acro-Companion) ; critères nommés plutôt qu'une note globale (grilles poomsae ABFT).

### Principe directeur

**Le programme du grade visé dans TKD Parcours est la grille d'examen.** Un candidat est évalué sur le poomsae, la technique de bras et la technique de jambes de la fiche du grade visé (`_claira_tkd_poomsae`, `_claira_tkd_tech_bras`, `_claira_tkd_tech_jambes`), sans saisie. Grade visé = grade suivant dans la chaîne du Parcours (`get_grades_claira()`), sans table de progression ; un saut exceptionnel reste saisissable à la main. À ces critères s'ajoutent des **épreuves transverses libres** (voir plus bas).

### Moment 1 — Préparer (un seul écran)

- Le passage se crée depuis une date ; il crée lui-même son événement d'examen au calendrier (plus de prérequis).
- **Candidats** : tous les élèves actifs sont sélectionnables. L'âge minimum du grade visé (`_claira_tkd_min_age`) n'est **jamais bloquant** : simple **alerte** quand l'élève est trop jeune, avec l'écart en années (« ⚠️ Trop jeune de 2 ans pour la 10e jaune (âge conseillé : 9 ans) »). L'alerte reste visible jusqu'à l'écran de validation.
- **Les Dan sont hors périmètre** : l'examen Dan ne se déroule pas au club, un Dan ne peut pas être choisi comme grade visé.
- **Juges** : proposés parmi les entraîneurs (ceux qui ont mis ✅ sur la date dans *Mes dispos* en tête) **et** les adhérents de grade Poom ou Dan (repérés depuis leur fiche). C'est l'entraîneur qui choisit, personne n'est ajouté d'office. Un juge est désigné **président de jury**. Accès juge par lien ou QR code, sans compte WordPress.
- Aires facultatives (une seule par défaut).
- Bouton unique « Ouvrir le passage ».

### Moment 2 — Noter (téléphone du juge)

- **Chaque juge peut noter n'importe quel candidat** : pas d'affectation candidat → aire obligatoire, le juge fait défiler la liste (précédent / suivant).
- Un écran par candidat : ceinture actuelle → ceinture visée, puis un bloc par critère (programme du Parcours + épreuves transverses applicables), remarque facultative, verdict proposé.
- **Grade visé keup** : trois niveaux par critère — **Acquis / À revoir / Non acquis**.
- **Grade visé Poom** : **note sur 10** par épreuve.
- Aucune fonction d'admin visible pour le juge.
- Enregistrement immédiat, file d'envois ordonnée (même principe que *Mes dispos*), **fonctionnement hors ligne** : les saisies restent sur le téléphone et repartent d'elles-mêmes.

### Épreuves transverses libres

Un écran unique pour créer des épreuves en plus du programme du Parcours :
- nom ;
- portée : tous les candidats, une catégorie (Baby / Enfant / Ado-adulte) ou une tranche de grades ;
- type : **3 niveaux**, **note**, ou **mesure** (chiffre relevé, ex. nombre de coups, converti par des seuils — généralisation du ZEMITA actuel).

Elles comblent aussi les grades sans programme technique dans le Parcours (Baby, Il Poom ado/adulte).

### Règles de décision

- **Plusieurs juges, keup** : pour chaque critère, l'avis **majoritaire** des juges ; en cas d'**égalité**, la ligne est surlignée et le **président tranche**. Les remarques de tous les juges sont conservées pour le retour à l'élève.
- **Admission keup** : admis s'il n'y a **aucun « Non acquis »** et **au plus un « À revoir »**.
- **Admission Poom** : **moyenne des juges** par épreuve ; admis si la **moyenne générale est d'au moins 5/10** et qu'**aucune épreuve n'est sous le plancher** (4/10 par défaut). Seuil et plancher réglables.
- Le verdict calculé est une **proposition** : le président décide (Admis / Ajourné).
- Calcul du verdict dans **une seule fonction**, seule source de vérité.

### Moment 3 — Valider (président, un seul écran)

- Une ligne par candidat : verdict proposé, désaccords entre juges surlignés, alerte d'âge éventuelle, choix final Admis / Ajourné.
- Bouton unique **« Valider les grades »** : écrit le nouveau grade sur la fiche avec l'historique, déclenche la demande de changement de col du dobok (Poom), publie le résultat et le retour dans l'application de l'adhérent.
- Passage **verrouillé** après validation, réouverture par l'admin uniquement.

### Côté adhérent (PWA)

Carte **« Mon prochain grade »** : programme à préparer avec les vidéos du Parcours ; après le passage, résultat et points « À revoir » reliés à leur vidéo.

### Stabilité

- États simples : Préparation → Ouvert → Validé ; rien n'est écrit sur les fiches avant la validation.
- Une ligne par candidat × critère × juge, mise à jour sur place (renvoyer deux fois la même saisie ne casse rien).
- Pas d'actualisation automatique qui se met en pause ou écrase une saisie en cours.

### Ancien module

Jamais utilisé : **supprimé** au moment du développement (7 étapes, `exam_epreuves`, `exam_grade_progression`, Transcription, doublon `class-jury-mobile.php` à la racine). Seule l'idée des seuils ZEMITA est reprise, sous la forme du type d'épreuve « mesure ». **À vérifier avant suppression** (relevé le 03/10/2026) : `class-pdf.php` lit `get_exam_epreuves()` (3 endroits, impressions d'examen) et `sp-pointage.php` lit la table `exam_grade_contenu` (ressources par grade) ; la progression des examens est aussi recalculée par l'harmonisation des grades de `class-admin-members.php` (commit e367ea6).

## 05/10/2026 — IK : dispos modifiées après coup sur un mois déjà payé (FAIT le 05/10/2026, voir REALISATION.md : clôture des IK)

Les interventions (base des IK, récapitulatif mensuel et module IK de sp-compta via le filtre `sp_cal_interventions_par_trainer`) sont **recalculées à chaque fois** depuis les dispos (`get_interventions_par_trainer()`). Une dispo ajoutée ou retirée sur un mois déjà récapitulé ou payé change donc le total sans que personne ne le voie, et le mail récapitulatif déjà envoyé n'est plus juste. Pistes : verrouiller les dispos d'un mois une fois le récapitulatif envoyé (réouverture par l'admin), ou au minimum signaler l'écart. Reporté à la demande de l'utilisateur.

## 06/10/2026 — Envoi des emails : panne réglée, délivrabilité Gmail à améliorer (À REPRENDRE)

> **Résumé pour reprendre le travail sur un autre ordinateur.**

### Ce qui s'est passé (réglé)
- **Panne** : plus aucun email du site (rappels entraîneurs, relances en lot, vœux d'anniversaire, tkd-cotisations…). **Cause** : le mot de passe de la boîte OVH `contact@tkdclaira.fr` avait été changé, WP Mail SMTP utilisait l'ancien. Le journal **WP Mail SMTP → Outils → Évènements de débogage** montre ~60 erreurs « SMTP Error: Could not authenticate » du **30/09 (15h29) au 05/10 (20h21)**. **Correction** : nouveau mot de passe saisi dans WP Mail SMTP → Réglages → Général (champ « Mot de passe SMTP » : bouton « Supprimer le mot de passe » puis ressaisir). Plus aucune erreur depuis.
- **Les emails non partis pendant la panne ne repartent pas seuls** : relance en lot à relancer à la main ; rappel entraîneur du jour perdu ; vœux d'anniversaire de la période marqués « faits » (anti-doublon) même en échec → voir le journal de la page 🎂 Anniversaires si besoin de les envoyer à la main.

### Configuration vérifiée le 06/10/2026 (tout est correct)
- **WP Mail SMTP** (version gratuite, pas de journal des emails) : mailer « Autre SMTP », `ssl0.ovh.net`, port 465, SSL, authentification, utilisateur et expéditeur `contact@tkdclaira.fr` (forcé, nom « TAEKWONDO CLAIRA » forcé), return-path aligné. Onglet Divers : « Ne pas envoyer », « Optimiser l'envoi » et « Limitation du nombre d'e-mails » **désactivés**. Option « Débogage de l'envoi d'e-mails » activée quelques minutes puis **désactivée** (la version gratuite ne garde pas la conversation SMTP).
- **OVH** (espace client → Web Cloud → Emails → MX Plan → `tkdclaira.fr`) : service actif, MX valides, SPF valide, **DKIM actif** (badge « DKIM » de la rubrique Diagnostique — ⚠️ cliquer dessus ouvre « Désactiver DKIM » : toujours **Annuler**). DNS : SPF `v=spf1 include:mx.ovh.com -all`, DMARC `p=none; rua=mailto:contact@tkdclaira.fr`.
- **Test réel** (Gmail → « Afficher l'original ») : **SPF PASS** (IP OVH 46.105.58.83), **DKIM PASS** (tkdclaira.fr), **DMARC PASS**. Un email envoyé à un `@cd66.fr` arrive en boîte de réception.

### Problème restant : Gmail classe les emails du club en spam
L'authentification est parfaite : c'est une question de **réputation d'envoi** — serveurs d'envoi OVH partagés (`ssl0.ovh.net`, réputation variable selon les autres clients) et/ou pics d'envois groupés récents (relance en lot, annulation de cours par lot du 05/10). Les familles et entraîneurs en `@gmail.com` risquent de ne pas voir les emails.

### À faire, dans l'ordre recommandé
1. **Tout de suite (sans code)** : message aux familles (WhatsApp / affichage au dojang) — « ajoutez `contact@tkdclaira.fr` à vos contacts ; si un mail du club arrive en spam, cliquez sur "Non spam" ». Chaque « Non spam » améliore la réputation.
2. **Passer l'envoi par un service spécialisé — Brevo recommandé** (ex-Sendinblue, français, gratuit jusqu'à 300 emails/jour) : dans WP Mail SMTP → Réglages → Général, choisir le mailer **Brevo** et coller la clé API ; garder l'expéditeur `contact@tkdclaira.fr` ; ajouter chez OVH (zone DNS de `tkdclaira.fr`) les enregistrements d'authentification demandés par Brevo (code de vérification, DKIM Brevo, ajout de Brevo au SPF). Avantages : serveurs à bonne réputation, journal de chaque email (délivré / ouvert / rejeté). Claude peut guider pas à pas et saisir les DNS dans la console OVH si l'utilisateur y est connecté (navigateur intégré de l'application).
   **FAIT le 06/10/2026 (prod)** : compte Brevo gratuit « TKD Claira » (connexion `nguyen.bthanh@gmail.com`, 300 emails/jour). Domaine `tkdclaira.fr` authentifié en mode manuel : 3 entrées ajoutées dans la zone DNS OVH — TXT racine `brevo-code:…`, CNAME `brevo1._domainkey` → `b1.tkdclaira-fr.dkim.brevo.com.` et `brevo2._domainkey` → `b2.tkdclaira-fr.dkim.brevo.com.`. **SPF et DMARC inchangés** (Brevo ne demande pas d'ajout au SPF ; notre DMARC `p=none` est accepté), DKIM OVH conservé. Expéditeur `TAEKWONDO CLAIRA <contact@tkdclaira.fr>` vérifié. Blocage des IP inconnues pour les clés API : désactivé (Brevo → Sécurité → IPs autorisées). WP Mail SMTP : mailer **Brevo**, clé API `site tkdclaira`, domaine d'envoi `tkdclaira.fr`, expéditeur toujours forcé. Plugin WordPress « Brevo » (`mailin`) installé mais **désactivé** — le laisser ainsi (doublon avec WP Mail SMTP). Email de test vers Gmail : envoyé, délivré, ouvert (journal Brevo → Transactionnel → Logs), **arrivé en boîte de réception** ; « Afficher l'original » : SPF PASS (IP Brevo 77.32.148.26), DKIM PASS (tkdclaira.fr), DMARC PASS. **Retour arrière** : remettre le mailer « Autre SMTP » dans WP Mail SMTP (réglages OVH mémorisés). Lien de suivi personnalisé (sous-domaine de marque) non configuré, optionnel.
3. **Étaler les envois en lot dans sp-build** (ex. 20 emails/minute au lieu de tout d'un coup) : relances de renouvellement, annulation de cours par lot, notifications de masse — évite les pics qui dégradent la réputation chez Gmail et le blocage OVH pour envoi massif. **CODÉ le 06/10/2026** (file d'envoi, page « 📨 Envois », voir REALISATION.md) — à tester sur le site de test avant la prod.
4. **Soigner le contenu des emails aux adhérents** : HTML + alternative texte, ligne « pourquoi vous recevez ce mail », adresse du club en pied de message.
5. *(Optionnel)* **Google Postmaster Tools** (gratuit, vérification par un enregistrement DNS TXT) pour suivre la réputation du domaine chez Gmail.

### À surveiller depuis le passage à Brevo (06/10/2026)
- **Adresses `@cd66.fr` (messagerie du Département)** : les emails envoyés par Brevo sont « Délivrés » (acceptés par leur serveur) et leurs liens ouverts aussitôt par une passerelle de sécurité, mais **rien n'arrive dans la boîte** (ni réception ni spam) — sondage de 09:33 et email de test de 10:02. Du temps d'OVH, ces emails arrivaient. **Confirmé le 06/10/2026 : la messagerie du CD66 les avait mis en quarantaine** ; l'utilisateur les a libérés depuis la quarantaine. **Mémo** : tout destinataire en `@cd66.fr` (et plus généralement une messagerie d'administration ou d'entreprise) peut voir les emails du club retenus en quarantaine depuis le passage à Brevo — penser à vérifier la quarantaine / le rapport de quarantaine, et si ça se répète, demander au service informatique du CD66 de mettre `contact@tkdclaira.fr` en liste blanche, ou utiliser une adresse personnelle sur les fiches concernées.
- **`sandrafonteneau66@gmail.com`** : boîte Gmail pleine (« soft bounce » 452 4.2.2 sur le rappel du jour du 06/10) — la prévenir, elle ne reçoit plus les emails du club.

### Divers relevés dans le journal WP Mail SMTP
- ~~Adresses d'adhérents mal saisies qui font échouer leurs envois : **`may_278@msn.comm`** (un « m » de trop) et **`noe@lens-group.fp`** (probablement `.fr`) — à corriger sur les fiches.~~ **Clos le 06/10/2026** : plus aucune des 116 fiches (adresse de l'adhérent et du parent) ne les contient. `noe@lens-group.fp` : erreur unique du 19/03/2026 (plugin 10.16.4) — très probablement Noé PETIT DE MIRBECK, dont la fiche porte aujourd'hui une adresse Gmail. `may_278@msn.comm` : deux échecs le 22/09/2026, sans nom associé (probablement l'accusé de réception d'un formulaire d'adhésion / renouvellement) ; aucune fiche ne contient `may_278` — adresse corrigée à la validation ou demande sans suite.

## 06/10/2026 (suite) — Trésorerie : projets, saisie rapide, onglet « Saisie » (résumé pour reprendre)

> Détail de ce qui a été fait : `sp-build/REALISATION.md` (onglet Saisie) et `sp-build-addons/Developpement plugin TKD Claude/sp-compta/REALISATION.md` + `CLAUDE.md` (incréments 13 et 14). Tout est commité, poussé et **en production** (tkdclaira.fr).

### Fait et vérifié en ligne
- **Projets de la saison** (sp-compta, onglet « Projets ») : comptabilité analytique par projet (fête de Noël, matériel, stage…), budget prévu facultatif, bilan prévu / réalisé / écart, séparation « fonctionnement courant / projets » dans le **rapport AG**, colonne « Projet » dans l'export CSV, rattachement en lot des mouvements déjà saisis. Champ « Projet (facultatif) » dans Dépense / Recette et la saisie rapide — **n'apparaît qu'à partir d'un premier projet en cours** (choix confirmé le 06/10 : on garde ce comportement).
- **Date de fin de la saison active corrigée** dans Trésorerie → Paramètres : 2026-08-31 → **2027-08-31** (faute de saisie antérieure).
- **Saisie rapide sans déconnexion** (sp-compta) : session de **1 an** pour les comptes trésorerie, écran « Connexion nécessaire » qui ramène au formulaire, saisie gardée sur le téléphone, jeton de formulaire renouvelé avant l'envoi.
- **Onglet « 💶 Saisie » dans l'application entraîneur** (sp-build), visible pour les seuls membres au rôle « bureau » en lien personnel : ouvre la saisie rapide dans une fenêtre à part. **Confirmé par l'utilisateur.**

### Reste à faire / à vérifier
1. **Chaque membre du bureau doit se reconnecter une fois** dans la saisie rapide installée sur son téléphone (sur iPhone : dans l'application installée, pas dans Safari) pour recevoir la session d'un an.
2. Vérifier que l'onglet « Saisie » **n'apparaît pas** chez un entraîneur qui n'est pas au bureau.
3. **Lancer les tests PHPUnit de sp-compta** (projets, session longue, saisie rapide) et `outils\verifier.ps1` sur le PC du travail — écrits mais jamais exécutés (pas de PHP sur le PC de la maison).
4. **Créer les premiers vrais projets** de la saison (ex. « Fête de Noël 2026 » avec budget) et rattacher les dépenses déjà saisies depuis la fiche du projet ; contrôler le rapport AG.
5. Pistes non faites (à décider) : filtre par projet dans l'écran Solde ; insertion de la saisie rapide *dans* l'application entraîneur sans fenêtre à part (demanderait une authentification par lien personnel côté trésorerie — compromis de sécurité discuté le 06/10, écarté pour l'instant).
6. Rappel : le **pointage QR reste servi par l'extension « SP Pointage QR »** (voir « Pistes techniques ouvertes ») — ne pas la désactiver avant d'avoir rebranché le pointage sur sp-build seul.

## 07/10/2026 — Suites de l'audit de sécurité (restructuration, étape 1)

Bilan complet dans `../JOURNAL.md` (section 7). À traiter, par ordre d'importance :
- **PIN du pointage trop faible et sans limite d'essais** : 4 chiffres tous différents (`substr( str_shuffle( '0123456789' ), 0, 4 )`) = 5 040 combinaisons, aucune limitation des tentatives. Il protège le pointage QR (`/pointage/*`, actions `sp_pointage_*`), le calendrier du club et la liste des anniversaires de l'application (`/anniversaires` : prénoms et âges). Un robot peut le trouver en quelques minutes. **Réglé le 07/10/2026 : blocage de l'adresse IP 15 minutes après 10 PIN faux** (`class-pin-garde.php`, cf. REALISATION.md) — l'application et le PIN ne changent pas (l'application en mode PIN sert à tout au club : ne pas la modifier). Reste : l'extension séparée « SP Pointage QR » (hors dépôt) n'est pas couverte ; le code de génération du PIN est **copié à 4 endroits** (`class-admin.php` ×2, `class-admin-members.php`, `class-ajax.php`) → une seule fonction, lors du ménage.
- **Calendrier en shortcode (copie du calendrier admin)** : créé pour la saisie des dispos des entraîneurs, désormais remplacé par leur application (retour de l'utilisateur du 07/10/2026). À retirer, ainsi que l'ouverture publique (`wp_ajax_nopriv_sp_cal_get_events`) si plus aucune page publique ne l'utilise — vérifier d'abord les pages du site.
- **Code mort** : `class-pdf.php`, impression `?sp_cal_print=liste_groupe` → `render_liste_groupe()` inexistante (aucun bouton ne l'utilise) — à supprimer.

## Comment tenir ce fichier à jour

Ajouter une entrée datée dès qu'une idée d'amélioration ou une demande non traitée apparaît, même si elle n'est pas urgente — c'est le rôle de ce fichier de ne pas perdre ces idées entre deux sessions.

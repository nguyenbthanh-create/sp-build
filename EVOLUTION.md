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

## Comment tenir ce fichier à jour

Ajouter une entrée datée dès qu'une idée d'amélioration ou une demande non traitée apparaît, même si elle n'est pas urgente — c'est le rôle de ce fichier de ne pas perdre ces idées entre deux sessions.

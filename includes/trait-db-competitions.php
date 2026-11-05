<?php
/**
 * Requêtes « compétitions et palmarès » : épreuves, résultats, participations, médailles, points.
 *
 * Méthodes de SpCalPro_DB déplacées telles quelles depuis class-db.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_DB_Competitions; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_DB_Competitions {


    /* ══════════════════════════════════════════════════════════
       COMPÉTITIONS — ÉPREUVES & RÉSULTATS
    ══════════════════════════════════════════════════════════ */

    /** Retourne les épreuves d'une compétition (event). */
    public function get_comp_epreuves( $event_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_comp_epreuves()} WHERE event_id=%d ORDER BY ordre ASC",
            intval($event_id)
        ) );
    }

    /**
     * Sauvegarde les épreuves d'une compétition.
     * $epreuves = array of ['id'=>0, 'nom'=>'...', 'modalite'=>'...', 'ordre'=>1]
     * Les épreuves avec id=0 sont créées ; les existantes mises à jour.
     * Les épreuves supprimées côté client (non présentes) sont effacées.
     */
    public function save_comp_epreuves( $event_id, $epreuves ) {
        global $wpdb;
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $event_id = intval($event_id);
        $keep_ids = array();

        foreach ( $epreuves as $ep ) {
            $id       = intval($ep['id'] ?? 0);
            $nom      = sanitize_text_field($ep['nom']      ?? '');
            $modalite = sanitize_text_field($ep['modalite'] ?? '');
            $ordre    = intval($ep['ordre'] ?? 1);
            if ( ! $nom ) continue;
            if ( $id ) {
                $wpdb->update( $tce, compact('nom','modalite','ordre'), array('id'=>$id,'event_id'=>$event_id) );
                $keep_ids[] = $id;
            } else {
                $wpdb->insert( $tce, array('event_id'=>$event_id,'nom'=>$nom,'modalite'=>$modalite,'ordre'=>$ordre) );
                $keep_ids[] = $wpdb->insert_id;
            }
        }

        // Supprimer les épreuves retirées (et leurs résultats)
        $existing = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $tce WHERE event_id=%d", $event_id
        ) );
        foreach ( $existing as $eid ) {
            if ( ! in_array( intval($eid), $keep_ids ) ) {
                $wpdb->delete( $tcr, array('epreuve_id'=>intval($eid)) );
                $wpdb->delete( $tce, array('id'=>intval($eid)) );
            }
        }
        return $keep_ids;
    }

    /**
     * Résultats d'une compétition complète.
     * Retourne array indexé epreuve_id => [ eleve_id => {medaille, score} ]
     */
    public function get_comp_resultats_by_event( $event_id ) {
        global $wpdb;
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.epreuve_id, r.eleve_id, r.medaille, r.score
             FROM $tcr r
             INNER JOIN $tce e ON e.id = r.epreuve_id
             WHERE e.event_id = %d",
            intval($event_id)
        ) );
        $map = array();
        foreach ( $rows as $r ) {
            $map[ intval($r->epreuve_id) ][ intval($r->eleve_id) ] = array(
                'medaille' => $r->medaille,
                'score'    => $r->score,
            );
        }
        return $map;
    }

    /**
     * Sauvegarde les résultats.
     * $resultats = array of [ 'epreuve_id'=>int, 'eleve_id'=>int, 'medaille'=>'or|argent|bronze|', 'score'=>'...' ]
     */
    public function save_comp_resultats( $resultats ) {
        global $wpdb;
        $tcr = $this->table_comp_resultats();
        foreach ( $resultats as $r ) {
            $eid = intval($r['epreuve_id'] ?? 0);
            $lid = intval($r['eleve_id']   ?? 0);
            if ( ! $eid || ! $lid ) continue;
            $data = array(
                'medaille' => in_array($r['medaille']??'', array('or','argent','bronze')) ? $r['medaille'] : '',
                'score'    => sanitize_text_field($r['score'] ?? ''),
            );
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $tcr WHERE epreuve_id=%d AND eleve_id=%d", $eid, $lid
            ) );
            if ( $exists ) {
                $wpdb->update( $tcr, $data, array('id'=>intval($exists)) );
            } else {
                $wpdb->insert( $tcr, array_merge($data, array('epreuve_id'=>$eid,'eleve_id'=>$lid)) );
            }
        }
    }

    /**
     * Retourne toutes les compétitions (événements type='competition'), triées date DESC.
     * Optionnel : filtrer par saison (année de la date, ex. '2024-2025').
     */
public function get_all_competitions( $annee = '' ) {
    global $wpdb;
    $te    = $this->table_events();
    $where = "WHERE type = 'competition'";
    $p     = array();
    if ( $annee ) {
        $where .= ' AND YEAR(date) = %d';
        $p[]    = intval($annee);
    }
    $sql = "SELECT * FROM $te $where ORDER BY
        CASE niveau
            WHEN 'international' THEN 1
            WHEN 'national'      THEN 2
            WHEN 'regional'      THEN 3
            ELSE 4
        END ASC,
        date DESC";
    return $p ? $wpdb->get_results( $wpdb->prepare($sql, $p) ) : $wpdb->get_results($sql);
}

    /**
     * Années distinctes des compétitions (pour le sélecteur de filtre).
     */
    public function get_competitions_annees() {
        global $wpdb;
        $te = $this->table_events();
        return $wpdb->get_col(
            "SELECT DISTINCT YEAR(date) AS annee FROM $te WHERE type='competition' ORDER BY annee DESC"
        );
    }

    /**
     * Palmarès global : tous les résultats de compétition, toutes les médailles,
     * jointé avec élèves et compétitions.
     * Si $event_id fourni, filtre sur cette compétition.
     * Si $eleve_id fourni, filtre sur cet élève.
     * Retourne rows : eleve_id, nom, prenom, categorie_age, grade,
     *                 event_id, comp_titre, date,
     *                 epreuve_id, epreuve_nom, medaille, score
     */
    public function get_palmares_global( $event_id = 0, $eleve_id = 0 ) {
        global $wpdb;
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $te  = $this->table_events();
        $tel = $this->table_eleves();

        $where = array( "e.type = 'competition'" );
        $p     = array();
        if ( $event_id ) { $where[] = 'e.id = %d';       $p[] = intval($event_id); }
        if ( $eleve_id ) { $where[] = 'r.eleve_id = %d'; $p[] = intval($eleve_id); }
        $sql_where = implode( ' AND ', $where );

        $sql = "SELECT r.eleve_id, el.nom, el.prenom, el.categorie_age, el.grade,
                       el.droit_image,
                       e.id AS event_id, e.titre AS comp_titre, e.date,
                       ep.id AS epreuve_id, ep.nom AS epreuve_nom, ep.ordre AS epreuve_ordre,
                       r.medaille, r.score
                FROM $tcr r
                INNER JOIN $tce ep ON ep.id = r.epreuve_id
                INNER JOIN $te  e  ON e.id  = ep.event_id
                INNER JOIN $tel el ON el.id = r.eleve_id
                WHERE $sql_where
                ORDER BY e.date DESC, ep.ordre ASC, el.categorie_age ASC, el.nom ASC";

        return $p ? $wpdb->get_results( $wpdb->prepare($sql, $p) ) : $wpdb->get_results($sql);
    }

    /**
     * Résumé des médailles par élève (pour la vue "par élève").
     * Retourne : eleve_id, nom, prenom, categorie_age, grade, nb_or, nb_argent, nb_bronze, nb_total_comp
     */
    public function get_palmares_resume_eleves() {
        global $wpdb;
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $te  = $this->table_events();
        $tel = $this->table_eleves();

        return $wpdb->get_results(
            "SELECT r.eleve_id, el.nom, el.prenom, el.categorie_age, el.grade,
                    el.droit_image,
                    SUM(CASE WHEN r.medaille='or'     THEN 1 ELSE 0 END) AS nb_or,
                    SUM(CASE WHEN r.medaille='argent' THEN 1 ELSE 0 END) AS nb_argent,
                    SUM(CASE WHEN r.medaille='bronze' THEN 1 ELSE 0 END) AS nb_bronze,
                    COUNT(DISTINCT ep.event_id)                           AS nb_total_comp
             FROM $tcr r
             INNER JOIN $tce ep ON ep.id = r.epreuve_id
             INNER JOIN $te  e  ON e.id  = ep.event_id AND e.type = 'competition'
             INNER JOIN $tel el ON el.id = r.eleve_id
             WHERE r.medaille != ''
             GROUP BY r.eleve_id
             ORDER BY nb_or DESC, nb_argent DESC, nb_bronze DESC, el.nom ASC"
        );
    }

    /**
     * Tous les palmarès d'un élève pour la fiche personnelle.
     * Source : participations explicites (table comp_participations).
     * Retourne une ligne par épreuve — LEFT JOIN résultats (peut être vide).
     */
    public function get_palmares_eleve( $eleve_id ) {
        global $wpdb;
        $tcp = $this->table_comp_participations();
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $te  = $this->table_events();
        $eleve_id = intval($eleve_id);
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                COALESCE(r.medaille,'') AS medaille,
                COALESCE(r.score,'')   AS score,
                ep.nom      AS epreuve_nom,
                ep.modalite AS epreuve_modalite,
                ep.ordre    AS epreuve_ordre,
                e.id        AS event_id,
                e.date,
                e.titre     AS comp_titre,
                e.categorie
             FROM $te e
             INNER JOIN $tcp p  ON p.event_id = e.id AND p.eleve_id = %d
             INNER JOIN $tce ep ON ep.event_id = e.id
             LEFT  JOIN $tcr r  ON r.epreuve_id = ep.id AND r.eleve_id = %d
             WHERE e.type = 'competition'
             ORDER BY e.date DESC, ep.ordre ASC",
            $eleve_id, $eleve_id
        ) );
    }

    /* ══════════════════════════════════════════════════════════
       PARTICIPATIONS AUX COMPÉTITIONS (lien élève ↔ compétition)
    ══════════════════════════════════════════════════════════ */

    /**
     * Toutes les compétitions auxquelles un élève est lié (participation explicite
     * OU résultats existants — les deux sources sont fusionnées).
     * Retourne des rows event : id, titre, date, categorie
     */
    public function get_comps_eleve( $eleve_id ) {
        global $wpdb;
        $tcp = $this->table_comp_participations();
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $te  = $this->table_events();
        $eleve_id = intval($eleve_id);
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT e.id, e.titre, e.date, e.categorie
             FROM $te e
             WHERE e.type = 'competition'
               AND (
                   EXISTS (
                       SELECT 1 FROM $tcp p WHERE p.event_id = e.id AND p.eleve_id = %d
                   )
                   OR EXISTS (
                       SELECT 1 FROM $tcr r
                       INNER JOIN $tce ep ON ep.id = r.epreuve_id AND ep.event_id = e.id
                       WHERE r.eleve_id = %d
                   )
               )
             ORDER BY e.date DESC",
            $eleve_id, $eleve_id
        ) );
    }

    /**
     * Compétitions disponibles pour un élève (pas encore liées).
     * Retourne : id, titre, date, categorie
     */
    public function get_comps_disponibles( $eleve_id ) {
        global $wpdb;
        $tcp = $this->table_comp_participations();
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $te  = $this->table_events();
        $eleve_id = intval($eleve_id);
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.titre, e.date, e.categorie
             FROM $te e
             WHERE e.type = 'competition'
               AND e.id NOT IN (
                   SELECT p.event_id FROM $tcp p WHERE p.eleve_id = %d
               )
               AND e.id NOT IN (
                   SELECT ep.event_id FROM $tcr r
                   INNER JOIN $tce ep ON ep.id = r.epreuve_id
                   WHERE r.eleve_id = %d
               )
             ORDER BY e.date DESC",
            $eleve_id, $eleve_id
        ) );
    }

    /**
     * Lie un élève à une compétition (upsert).
     */
    public function link_comp( $event_id, $eleve_id ) {
        global $wpdb;
        $tcp = $this->table_comp_participations();
        $event_id = intval($event_id);
        $eleve_id = intval($eleve_id);
        if ( ! $event_id || ! $eleve_id ) return false;
        // Vérifier que l'event est bien une compétition
        $te = $this->table_events();
        $ok = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $te WHERE id=%d AND type='competition'", $event_id
        ) );
        if ( ! $ok ) return false;
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO $tcp (event_id, eleve_id) VALUES (%d, %d)",
            $event_id, $eleve_id
        ) );
        return true;
    }

    /**
     * Délie un élève d'une compétition.
     * Supprime aussi ses résultats pour cette compétition.
     */
    public function unlink_comp( $event_id, $eleve_id ) {
        global $wpdb;
        $tcp = $this->table_comp_participations();
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $event_id = intval($event_id);
        $eleve_id = intval($eleve_id);
        if ( ! $event_id || ! $eleve_id ) return;
        // Supprimer la participation
        $wpdb->delete( $tcp, array( 'event_id' => $event_id, 'eleve_id' => $eleve_id ) );
        // Supprimer les résultats liés
        $ep_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $tce WHERE event_id=%d", $event_id
        ) );
        if ( $ep_ids ) {
            $in = implode( ',', array_map( 'intval', $ep_ids ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM $tcr WHERE epreuve_id IN ($in) AND eleve_id=%d",
                $eleve_id
            ) );
        }
    }

    /* ══════════════════════════════════════════════════════════
       CONVENTION MÉDAILLE → POINTS
    ══════════════════════════════════════════════════════════ */

    /**
     * Retourne la valeur en points de chaque type de médaille.
     * Configurable via les options WordPress (page Paramètres).
     * Défauts : Or=3, Argent=2, Bronze=1.
     */
    public function get_medal_points() {
        return array(
            'or'     => intval( get_option( 'sp_cal_pts_or',     3 ) ),
            'argent' => intval( get_option( 'sp_cal_pts_argent', 2 ) ),
            'bronze' => intval( get_option( 'sp_cal_pts_bronze', 1 ) ),
        );
    }

    /**
     * Calcule le total de points palmarès d'un élève.
     * Utilisé dans la fiche élève et le récapitulatif des grades.
     *
     * @param int $eleve_id
     * @return array  [ 'total_pts' => int, 'nb_or' => int, 'nb_argent' => int, 'nb_bronze' => int ]
     */
    public function get_palmares_points_eleve( $eleve_id ) {
        global $wpdb;
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $te  = $this->table_events();
        $pts = $this->get_medal_points();

        $counts = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN r.medaille='or'     THEN 1 ELSE 0 END) AS nb_or,
                SUM(CASE WHEN r.medaille='argent' THEN 1 ELSE 0 END) AS nb_argent,
                SUM(CASE WHEN r.medaille='bronze' THEN 1 ELSE 0 END) AS nb_bronze
             FROM $tcr r
             INNER JOIN $tce ep ON ep.id = r.epreuve_id
             INNER JOIN $te  e  ON e.id  = ep.event_id AND e.type = 'competition'
             WHERE r.eleve_id = %d AND r.medaille != ''",
            intval($eleve_id)
        ) );

        $nb_or     = intval( $counts->nb_or     ?? 0 );
        $nb_argent = intval( $counts->nb_argent ?? 0 );
        $nb_bronze = intval( $counts->nb_bronze ?? 0 );
        $total_pts = $nb_or * $pts['or'] + $nb_argent * $pts['argent'] + $nb_bronze * $pts['bronze'];

        return compact( 'total_pts', 'nb_or', 'nb_argent', 'nb_bronze' );
    }
}

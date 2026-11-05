<?php
/**
 * Requêtes « statistiques de présence ».
 *
 * Méthodes de SpCalPro_DB déplacées telles quelles depuis class-db.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_DB_Stats; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_DB_Stats {


    /**
     * Taux de présence par catégorie d'âge, filtrable par saison.
     * Retourne : categorie_age, nb_eleves, nb_presents, nb_absents, nb_cours
     */
    public function get_stats_par_groupe( $saison = '' ) {
        global $wpdb;
        $tel = $this->table_eleves();
        $tpe = $this->table_presences_eleves();
        $te  = $this->table_events();
        $where_saison = $saison ? $wpdb->prepare( "AND el.saison = %s", $saison ) : '';
        return $wpdb->get_results(
            "SELECT
                el.categorie_age,
                COUNT(DISTINCT el.id)                                           AS nb_eleves,
                SUM(CASE WHEN pe.present=1 THEN 1 ELSE 0 END)                  AS nb_presents,
                SUM(CASE WHEN pe.present=0 THEN 1 ELSE 0 END)                  AS nb_absents,
                COUNT(DISTINCT e.id)                                            AS nb_cours
             FROM $tel el
             LEFT JOIN $tpe pe ON pe.eleve_id = el.id
             LEFT JOIN $te  e  ON e.id = pe.event_id AND e.type NOT IN ('examen','anniversaire')
             WHERE el.categorie_age != '' $where_saison
             GROUP BY el.categorie_age
             ORDER BY MIN(el.rang) ASC, el.categorie_age ASC"
        );
    }

    /**
     * Taux de présence par catégorie saisie (TKD, RENFO…).
     */
    public function get_stats_par_saisie( $saison = '' ) {
        global $wpdb;
        $tel = $this->table_eleves();
        $tpe = $this->table_presences_eleves();
        $te  = $this->table_events();
        $where_saison = $saison ? $wpdb->prepare( "AND el.saison = %s", $saison ) : '';
        return $wpdb->get_results(
            "SELECT
                el.categorie_saisie,
                COUNT(DISTINCT el.id)                                           AS nb_eleves,
                SUM(CASE WHEN pe.present=1 THEN 1 ELSE 0 END)                  AS nb_presents,
                SUM(CASE WHEN pe.present=0 THEN 1 ELSE 0 END)                  AS nb_absents
             FROM $tel el
             LEFT JOIN $tpe pe ON pe.eleve_id = el.id
             LEFT JOIN $te  e  ON e.id = pe.event_id AND e.type NOT IN ('examen','anniversaire')
             WHERE el.categorie_saisie != '' $where_saison
             GROUP BY el.categorie_saisie
             ORDER BY el.categorie_saisie ASC"
        );
    }

    /**
     * Évolution mensuelle du nombre de présences (tous élèves).
     */
    public function get_stats_evolution_mensuelle( $saison = '' ) {
        global $wpdb;
        $tel = $this->table_eleves();
        $tpe = $this->table_presences_eleves();
        $te  = $this->table_events();
        $where_saison = $saison ? $wpdb->prepare( "AND el.saison = %s", $saison ) : '';
        return $wpdb->get_results(
            "SELECT
                DATE_FORMAT(e.date, '%Y-%m')        AS mois,
                COUNT(DISTINCT e.id)                AS nb_cours,
                SUM(CASE WHEN pe.present=1 THEN 1 ELSE 0 END) AS nb_presents,
                COUNT(pe.id)                        AS nb_total
             FROM $te e
             INNER JOIN $tpe pe ON pe.event_id = e.id
             INNER JOIN $tel el ON el.id = pe.eleve_id
             WHERE e.type NOT IN ('examen','anniversaire') $where_saison
             GROUP BY DATE_FORMAT(e.date, '%Y-%m')
             ORDER BY mois ASC"
        );
    }

    /**
     * Classement individuel des élèves par taux de présence.
     * $order : 'desc' (meilleurs) ou 'asc' (moins assidus)
     */
    public function get_stats_classement( $saison = '', $limit = 20, $order = 'desc' ) {
        global $wpdb;
        $tel = $this->table_eleves();
        $tpe = $this->table_presences_eleves();
        $te  = $this->table_events();
        $where_saison = $saison ? $wpdb->prepare( "AND el.saison = %s", $saison ) : '';
        $dir = $order === 'asc' ? 'ASC' : 'DESC';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                el.id, el.nom, el.prenom, el.categorie_age, el.categorie_saisie, el.grade,
                COUNT(pe.id)                                                        AS nb_total,
                SUM(CASE WHEN pe.present=1 THEN 1 ELSE 0 END)                      AS nb_presents,
                ROUND(SUM(CASE WHEN pe.present=1 THEN 1 ELSE 0 END)*100/COUNT(pe.id)) AS taux
             FROM $tel el
             INNER JOIN $tpe pe ON pe.eleve_id = el.id
             INNER JOIN $te  e  ON e.id = pe.event_id AND e.type NOT IN ('examen','anniversaire')
             WHERE 1=1 $where_saison
             GROUP BY el.id
             HAVING nb_total >= 1
             ORDER BY taux $dir, nb_presents $dir
             LIMIT %d",
            intval($limit)
        ) );
    }
}

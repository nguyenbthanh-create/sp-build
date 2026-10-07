<?php
/**
 * Requêtes « inscriptions aux événements » et agenda de l'adhérent.
 *
 * Méthodes de SpCalPro_DB déplacées telles quelles depuis class-db.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_DB_Inscriptions; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_DB_Inscriptions {


    /**
     * Toutes les inscriptions d'un événement, avec nom/email de l'élève.
     */
    public function get_inscriptions_event( $event_id ) {
        global $wpdb;
        $ti  = $this->table_event_inscriptions();
        $tel = $this->table_eleves();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, e.prenom, e.nom, e.email, e.categorie_age, e.categorie_saisie
             FROM $ti i
             LEFT JOIN $tel e ON e.id = i.eleve_id
             WHERE i.event_id = %d
             ORDER BY e.nom, e.prenom",
            intval( $event_id )
        ) );
    }

    /**
     * Inscription d'un élève précis pour un événement.
     */
    public function get_inscription_eleve( $event_id, $eleve_id ) {
        global $wpdb;
        $ti = $this->table_event_inscriptions();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $ti WHERE event_id=%d AND eleve_id=%d",
            intval( $event_id ), intval( $eleve_id )
        ) );
    }

    /**
     * Crée ou met à jour une inscription (statut + commentaire).
     */
    public function save_inscription( $event_id, $eleve_id, $statut, $commentaire = '' ) {
        global $wpdb;
        $ti       = $this->table_event_inscriptions();
        $existing = $this->get_inscription_eleve( $event_id, $eleve_id );

        $data = array(
            'event_id'     => intval( $event_id ),
            'eleve_id'     => intval( $eleve_id ),
            'statut'       => sanitize_text_field( $statut ),
            'commentaire'  => sanitize_textarea_field( $commentaire ),
            'date_reponse' => date( 'Y-m-d H:i:s' ),
        );

        if ( $existing ) {
            // Incrémenter nb_changements seulement si le statut change réellement
            if ( $existing->statut !== $statut ) {
                $current_nb = isset( $existing->nb_changements ) ? intval( $existing->nb_changements ) : 0;
                $data['nb_changements'] = $current_nb + 1;
            }
            return $wpdb->update( $ti, $data, array( 'id' => intval( $existing->id ) ) ) !== false;
        }

        // Première réponse : nb_changements = 0
        $data['nb_changements'] = 0;
        return $wpdb->insert( $ti, $data ) !== false;
    }

    /**
     * Événements avec inscriptions ouvertes (deadline non dépassée)
     * auxquels l'élève n'a pas encore répondu, pour affichage sur la fiche membre.
     */
    /**
     * Agenda d'un adhérent (fiche ?token= et PWA, décision du 26/09/2026) : TOUS les
     * événements du club des $jours prochains jours — hors cours, anniversaires et
     * annulations de cours — avec pour chacun :
     *   - concerne    : l'événement vise sa discipline et sa tranche d'âge (ou tout le club),
     *                   ou il est éligible à ses inscriptions ;
     *   - inscription : inscriptions ouvertes (activées + envoyées) ET adhérent éligible ;
     *   - statut_insc : sa réponse (inscrit / refuse / en_attente).
     * L'éligibilité reprend la logique de SpCalPro_Admin::rest_pwa_eleve_evenements()
     * (18/09/2026) : discipline ET tranche d'âge ciblées, ou sélection manuelle exclusive.
     */
    public function get_agenda_eleve( $eleve_id, $jours = 92 ) {
        global $wpdb;
        $te  = $this->table_events();
        $ti  = $this->table_event_inscriptions();
        $eid = intval( $eleve_id );

        $eleve = $wpdb->get_row( $wpdb->prepare(
            "SELECT categorie_saisie, categorie_age FROM {$this->table_eleves()} WHERE id = %d", $eid
        ) );
        $cat_saisie = trim( $eleve->categorie_saisie ?? '' );
        $cat_age    = trim( $eleve->categorie_age    ?? '' );

        $debut = current_time( 'Y-m-d' );
        $fin   = date( 'Y-m-d', strtotime( $debut . ' +' . intval( $jours ) . ' days' ) );
        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, COALESCE(i.statut, 'en_attente') AS statut_insc
             FROM $te e
             LEFT JOIN $ti i ON i.event_id = e.id AND i.eleve_id = %d
             WHERE e.date BETWEEN %s AND %s
               AND e.type NOT IN ('cours','anniversaire','annulation')
             ORDER BY e.date ASC, e.heure_debut ASC",
            $eid, $debut, $fin
        ) );

        foreach ( (array) $events as $ev ) {
            $insc_ouverte = ! empty( $ev->inscriptions_actives ) && ! empty( $ev->inscriptions_envoye );
            $ev->inscription = $insc_ouverte && $this->eleve_eligible_inscription( $ev, $eid, $cat_saisie, $cat_age );
            $ev->concerne    = $ev->inscription || $this->evenement_concerne( $ev, $cat_saisie, $cat_age );
        }
        return (array) $events;
    }

    /** Éligibilité aux inscriptions d'un événement (cf. rest_pwa_eleve_evenements()). */
    private function eleve_eligible_inscription( $ev, $eid, $cat_saisie, $cat_age ) {
        $cats  = array_filter( array_map( 'trim', explode( ',', $ev->inscriptions_categories     ?? '' ) ) );
        $ages  = array_filter( array_map( 'trim', explode( ',', $ev->inscriptions_age_categories ?? '' ) ) );
        $extra = array_filter( array_map( 'intval', explode( ',', $ev->inscriptions_extra_eleves ?? '' ) ) );
        if ( in_array( intval( $eid ), $extra, true ) ) return true;
        if ( empty( $cats ) && empty( $ages ) && ! empty( $extra ) ) return false; // ciblage exclusivement manuel
        $disc_ok = empty( $cats ) || ( $cat_saisie !== '' && in_array( $cat_saisie, $cats, true ) );
        $age_ok  = empty( $ages ) || ( $cat_age    !== '' && in_array( $cat_age,    $ages, true ) );
        return $disc_ok && $age_ok;
    }

    public function get_events_inscriptions_ouvertes_eleve( $eleve_id ) {
        global $wpdb;
        $te    = $this->table_events();
        $ti    = $this->table_event_inscriptions();
        $tel   = $this->table_eleves();
        $today = date( 'Y-m-d' );

        // Récupérer la catégorie de l'élève pour filtrer
        $eleve = $wpdb->get_row( $wpdb->prepare(
            "SELECT categorie_age, categorie_saisie FROM $tel WHERE id=%d",
            intval( $eleve_id )
        ) );
        $cat_age    = $eleve ? trim( $eleve->categorie_age    ?? '' ) : '';
        $cat_saisie = $eleve ? trim( $eleve->categorie_saisie ?? '' ) : '';

        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, COALESCE(i.statut, 'en_attente') AS statut_insc
             FROM $te e
             LEFT JOIN $ti i ON i.event_id = e.id AND i.eleve_id = %d
             WHERE e.inscriptions_actives = 1
               AND e.inscriptions_envoye  = 1
               AND e.date >= %s
             ORDER BY e.date ASC",
            intval( $eleve_id ), $today
        ) );

        // Filtrer côté PHP : si inscriptions_public est renseigné,
        // vérifier que la catégorie de l'élève est dans la liste
        return array_values( array_filter( $events, function( $ev ) use ( $cat_age, $cat_saisie ) {
            $public = trim( $ev->inscriptions_public ?? '' );
            if ( ! $public || $public === '[]' ) return true; // ouvert à tous
            $cats = array_map( 'trim', explode( ',', $public ) );
            // Vérifier catégorie_age OU catégorie_saisie
            foreach ( $cats as $cat ) {
                if ( $cat === '' ) continue;
                if ( $cat_age    && stripos( $cat_age,    $cat ) !== false ) return true;
                if ( $cat_saisie && stripos( $cat_saisie, $cat ) !== false ) return true;
                if ( $cat_age    && stripos( $cat,        $cat_age    ) !== false ) return true;
                if ( $cat_saisie && stripos( $cat,        $cat_saisie ) !== false ) return true;
            }
            return false;
        } ) );
    }

    /**
     * Élèves actifs avec email, filtrés par catégorie si inscriptions_public renseigné.
     */
    public function get_eleves_pour_inscription( $event ) {
        global $wpdb;
        $tel      = $this->table_eleves();
        $ti       = $wpdb->prefix . 'sp_cal_event_inscriptions';
        $event_id = intval( $event->id );
        $public   = trim( isset( $event->inscriptions_public ) ? $event->inscriptions_public : '' );

        // Sous-requête : exclure les élèves ayant déjà une réponse (oui ou non)
        $exclude_sql = "AND id NOT IN (
            SELECT eleve_id FROM $ti
            WHERE event_id = $event_id
            AND reponse IN ('oui','non')
        )";

        if ( ! $public || $public === '[]' ) {
            return $wpdb->get_results(
                "SELECT id, nom, prenom, email, token, categorie_age
                 FROM $tel
                 WHERE actif=1 AND email != ''
                 $exclude_sql
                 ORDER BY nom, prenom"
            );
        }

        $cats = array_filter( array_map( 'trim', explode( ',', $public ) ) );
        if ( empty( $cats ) ) return array();

        $placeholders = implode( ',', array_fill( 0, count( $cats ), '%s' ) );
        $sql  = "SELECT id, nom, prenom, email, token, categorie_age
                 FROM $tel
                 WHERE actif=1 AND email != ''
                 AND categorie_age IN ($placeholders)
                 $exclude_sql
                 ORDER BY nom, prenom";
        $args = array_merge( array( $sql ), $cats );
        return $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), $args ) );
    }
}

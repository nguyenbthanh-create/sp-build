<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_Ajax' ) ) :

class SpCalPro_Ajax {

    private $db;

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;
        add_action( 'wp_ajax_sp_cal_get_events',              array( $this, 'get_events' ) );
        add_action( 'wp_ajax_nopriv_sp_cal_get_events',       array( $this, 'get_events' ) );
        add_action( 'wp_ajax_sp_cal_save_event',              array( $this, 'save_event' ) );
        add_action( 'wp_ajax_sp_cal_delete_event',            array( $this, 'delete_event' ) );
        add_action( 'wp_ajax_sp_cal_get_presences',           array( $this, 'get_presences' ) );
        add_action( 'wp_ajax_sp_cal_save_presences',          array( $this, 'save_presences' ) );
        add_action( 'wp_ajax_sp_cal_apply_grades',            array( $this, 'apply_grades' ) );
        add_action( 'wp_ajax_sp_cal_link_examen',             array( $this, 'link_examen' ) );
        add_action( 'wp_ajax_sp_cal_unlink_examen',           array( $this, 'unlink_examen' ) );
        add_action( 'wp_ajax_sp_cal_delete_csv_grade',        array( $this, 'delete_csv_grade' ) );
        add_action( 'wp_ajax_sp_cal_edit_csv_grade',          array( $this, 'edit_csv_grade' ) );
        add_action( 'wp_ajax_sp_cal_import_csv',              array( $this, 'import_csv' ) );
        add_action( 'wp_ajax_sp_cal_confirm_famille_lien',    array( $this, 'confirm_famille_lien' ) );
        add_action( 'wp_ajax_sp_cal_delete_famille_lien',     array( $this, 'delete_famille_lien' ) );
        add_action( 'wp_ajax_sp_cal_save_km_excep',           array( $this, 'save_km_excep' ) );
        add_action( 'wp_ajax_sp_cal_delete_km_excep',         array( $this, 'delete_km_excep' ) );
        add_action( 'wp_ajax_sp_cal_get_km_excep',            array( $this, 'get_km_excep' ) );
        add_action( 'wp_ajax_sp_cal_import_sp',               array( $this, 'import_sportpress' ) );
        add_action( 'wp_ajax_sp_cal_get_trainer_dispos',      array( $this, 'get_trainer_dispos' ) );
        add_action( 'wp_ajax_nopriv_sp_cal_get_trainer_dispos', array( $this, 'get_trainer_dispos_public' ) );
        add_action( 'wp_ajax_sp_cal_save_trainer_dispo',      array( $this, 'save_trainer_dispo' ) );
        add_action( 'wp_ajax_sp_cal_save_dispo_remplacant',   array( $this, 'save_dispo_remplacant' ) );
        add_action( 'wp_ajax_sp_cal_notify_bureau_dispos',    array( $this, 'notify_bureau_dispos' ) );
        add_action( 'wp_ajax_sp_cal_get_comp',                array( $this, 'get_comp' ) );
        add_action( 'wp_ajax_sp_cal_save_comp_epreuves',      array( $this, 'save_comp_epreuves' ) );
        add_action( 'wp_ajax_sp_cal_save_comp_resultats',     array( $this, 'save_comp_resultats' ) );
        add_action( 'wp_ajax_sp_cal_link_comp',               array( $this, 'link_comp' ) );
        add_action( 'wp_ajax_sp_cal_unlink_comp',             array( $this, 'unlink_comp' ) );
        add_action( 'wp_ajax_sp_cal_send_annul_groupee',     array( $this, 'send_annul_groupee' ) );
        add_action( 'wp_ajax_sp_cal_ping',                    array( $this, 'ping' ) );
        add_action( 'wp_ajax_sp_cal_upload_event_doc',        array( $this, 'upload_event_doc' ) );
        add_action( 'wp_ajax_sp_cal_delete_event_doc',        array( $this, 'delete_event_doc' ) );
        // Inscriptions aux événements
        add_action( 'wp_ajax_sp_inscription_envoyer',         array( $this, 'inscription_envoyer' ) );
        add_action( 'wp_ajax_sp_inscription_liste',           array( $this, 'inscription_liste' ) );
        add_action( 'wp_ajax_nopriv_sp_inscription_repondre', array( $this, 'inscription_repondre' ) );
        add_action( 'wp_ajax_sp_inscription_repondre',        array( $this, 'inscription_repondre' ) );
        add_action( 'wp_ajax_sp_inscription_bulk',            array( $this, 'inscription_bulk' ) );
		add_action( 'wp_ajax_sp_load_sondage',           array( $this, 'ajax_load_sondage' ) );
        add_action( 'wp_ajax_sp_save_sondage_questions', array( $this, 'ajax_save_sondage_questions' ) );
        add_action( 'wp_ajax_sp_envoyer_sondage',        array( $this, 'ajax_envoyer_sondage' ) );
		
        // REST API pointage — accessible sans login, bypass blocage /wp-admin/
        add_action( 'rest_api_init', array( $this, 'register_pointage_rest' ) );
    }

    public function register_pointage_rest() {
        foreach ( array( 'cours', 'scan', 'cours_eleve', 'lot' ) as $r )
            register_rest_route( 'spcal/v1', '/pointage/' . $r, array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_pointage_' . $r ),
                'permission_callback' => '__return_true',
            ) );
    }
    private function rest_to_post( WP_REST_Request $req ) {
        foreach ( $req->get_params() as $k => $v ) { $_POST[ $k ] = $v; }
    }
    private function ptg_pin() {
        $p = get_option( 'sp_cal_pointage_pin', '' );
        if ( ! $p ) { $p = substr( str_shuffle( '0123456789' ), 0, 4 ); update_option( 'sp_cal_pointage_pin', $p ); }
        return $p;
    }
    private function mat_occ( $slot_id, $date ) {
        global $wpdb;
        $te  = $this->db->table_events();
        $tsl = $this->db->table_slots();
        $ex  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $te WHERE slot_id=%d AND date=%s AND type!='annulation' LIMIT 1", $slot_id, $date ) );
        if ( $ex ) return intval( $ex );
        $slot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tsl WHERE id=%d", $slot_id ) );
        if ( ! $slot ) return 0;
        $wpdb->insert( $te, array( 'date' => $date, 'heure_debut' => $slot->heure_debut, 'heure_fin' => $slot->heure_fin, 'titre' => $slot->label, 'categorie' => $slot->categorie ?: 'General', 'type' => 'cours', 'slot_id' => $slot_id ) );
        return intval( $wpdb->insert_id );
    }
    public function rest_pointage_cours( WP_REST_Request $req ) {
        $this->rest_to_post( $req );
        $pin  = sanitize_text_field( $_POST['pin']  ?? '' );
        $date = sanitize_text_field( $_POST['date'] ?? date( 'Y-m-d' ) );
        if ( $pin !== $this->ptg_pin() ) { wp_send_json_error( 'PIN invalide', 403 ); exit; }
        $occs  = $this->db->get_slot_occurrences( $date, $date );
        $cours = array();
        foreach ( $occs as $occ ) {
            if ( $occ['annul_id'] ) continue;
            $cours[] = array( 'slot_id' => $occ['slot_id'], 'mat_id' => $occ['mat_id'], 'date' => $occ['date'], 'titre' => $occ['titre'], 'heure_debut' => $occ['heure_debut'], 'heure_fin' => $occ['heure_fin'], 'categorie' => $occ['categorie'] );
        }
        wp_send_json_success( $cours ); exit;
    }
    public function rest_pointage_scan( WP_REST_Request $req ) {
        $this->rest_to_post( $req ); global $wpdb;
        $pin     = sanitize_text_field( $_POST['pin']     ?? '' );
        $token   = sanitize_text_field( $_POST['token']   ?? '' );
        $slot_id = intval(              $_POST['slot_id'] ?? 0  );
        $date    = sanitize_text_field( $_POST['date']    ?? '' );
        if ( $pin !== $this->ptg_pin() )        { wp_send_json_error( 'PIN invalide', 403 );       exit; }
        if ( ! $token || ! $slot_id || ! $date ) { wp_send_json_error( 'Donnees manquantes', 400 ); exit; }
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare( "SELECT id,nom,prenom FROM $tel WHERE token=%s AND actif=1 LIMIT 1", $token ) );
        if ( ! $el ) { wp_send_json_error( 'Eleve non trouve', 404 ); exit; }
        $eid = $this->mat_occ( $slot_id, $date );
        if ( ! $eid ) { wp_send_json_error( 'Creneau introuvable', 404 ); exit; }
        $tpe = $this->db->table_presences_eleves();
        $ex  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tpe WHERE event_id=%d AND eleve_id=%d", $eid, $el->id ) );
        if ( $ex ) { wp_send_json_success( array( 'nom' => $el->prenom . ' ' . mb_strtoupper( $el->nom ), 'status' => 'already', 'msg' => 'Deja pointe' ) ); exit; }
        $wpdb->insert( $tpe, array( 'event_id' => $eid, 'eleve_id' => $el->id, 'present' => 1 ) );
        wp_send_json_success( array( 'nom' => $el->prenom . ' ' . mb_strtoupper( $el->nom ), 'status' => 'ok', 'msg' => 'Present' ) ); exit;
    }
    public function rest_pointage_cours_eleve( WP_REST_Request $req ) {
        $this->rest_to_post( $req ); global $wpdb;
        $pin   = sanitize_text_field( $_POST['pin']   ?? '' );
        $token = sanitize_text_field( $_POST['token'] ?? '' );
        if ( $pin !== $this->ptg_pin() ) { wp_send_json_error( 'PIN invalide', 403 ); exit; }
        $tel = $this->db->table_eleves(); $tpe = $this->db->table_presences_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare( "SELECT id,nom,prenom FROM $tel WHERE token=%s AND actif=1 LIMIT 1", $token ) );
        if ( ! $el ) { wp_send_json_error( 'Eleve non trouve', 404 ); exit; }
        $occs  = $this->db->get_slot_occurrences( date( 'Y-m-d', strtotime( '-30 days' ) ), date( 'Y-m-d' ) );
        $cours = array();
        foreach ( array_reverse( $occs ) as $occ ) {
            if ( $occ['annul_id'] ) continue;
            $deja = $occ['mat_id'] ? (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tpe WHERE event_id=%d AND eleve_id=%d LIMIT 1", $occ['mat_id'], $el->id ) ) : false;
            $cours[] = array( 'slot_id' => $occ['slot_id'], 'mat_id' => $occ['mat_id'], 'date' => $occ['date'], 'titre' => $occ['titre'], 'heure_debut' => $occ['heure_debut'], 'heure_fin' => $occ['heure_fin'], 'categorie' => $occ['categorie'], 'deja_pointe' => $deja ? 1 : 0 );
        }
        wp_send_json_success( array( 'eleve' => array( 'nom' => $el->prenom . ' ' . mb_strtoupper( $el->nom ) ), 'cours' => $cours ) ); exit;
    }
    public function rest_pointage_lot( WP_REST_Request $req ) {
        $this->rest_to_post( $req ); global $wpdb;
        $pin   = sanitize_text_field( $_POST['pin']   ?? '' );
        $token = sanitize_text_field( $_POST['token'] ?? '' );
        $items = $_POST['items'] ?? array();
        if ( $pin !== $this->ptg_pin() )  { wp_send_json_error( 'PIN invalide', 403 );       exit; }
        if ( ! $token || empty( $items ) ) { wp_send_json_error( 'Donnees manquantes', 400 ); exit; }
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare( "SELECT id,nom,prenom FROM $tel WHERE token=%s AND actif=1 LIMIT 1", $token ) );
        if ( ! $el ) { wp_send_json_error( 'Eleve non trouve', 404 ); exit; }
        $tpe = $this->db->table_presences_eleves(); $nb = 0;
        foreach ( $items as $item ) {
            $sid  = intval( $item['slot_id'] ?? 0 );
            $date = sanitize_text_field( $item['date'] ?? '' );
            if ( ! $sid || ! $date ) continue;
            $eid = $this->mat_occ( $sid, $date ); if ( ! $eid ) continue;
            if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tpe WHERE event_id=%d AND eleve_id=%d", $eid, $el->id ) ) ) {
                $wpdb->insert( $tpe, array( 'event_id' => $eid, 'eleve_id' => $el->id, 'present' => 1 ) ); $nb++;
            }
        }
        wp_send_json_success( array( 'nom' => $el->prenom . ' ' . mb_strtoupper( $el->nom ), 'nb' => $nb ) ); exit;
    }

    private function nonce()         { check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' ); }
    private function require_admin() { if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Accès refusé', 403 ); }

    // Élargit l'accès à la capacité "gestion adhésions" (rôle Secrétaire, cf. class-roles.php
    // et doleances.md 09/09/2026), sans toucher require_admin() qui reste manage_options-only
    // pour tous les autres points d'entrée AJAX sans rapport (jury, examens, pointage...).
    private function require_gestion_adhesions() { if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_send_json_error( 'Accès refusé', 403 ); }

    /* ══════════════════════════════════════════════════════════
       GET EVENTS — calendrier mensuel (events ponctuels + occurrences slots + anniversaires)
    ══════════════════════════════════════════════════════════ */

    public function get_events() {
        $year  = intval( $_POST['year']  ?? date( 'Y' ) );
        $month = intval( $_POST['month'] ?? date( 'm' ) );

        $start = sprintf( '%04d-%02d-01', $year, $month );
        $end   = date( 'Y-m-t', strtotime( $start ) );

        global $wpdb;
        $te  = $this->db->table_events();
        $out = array();

        /* 1 — Events ponctuels VRAIS (slot_id IS NULL = pas une matérialisation de créneau récurrent) */
        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $te WHERE date BETWEEN %s AND %s AND type NOT IN ('annulation') AND slot_id IS NULL ORDER BY date ASC, heure_debut ASC",
            $start, $end
        ) );
        foreach ( $events as $e ) {
            $out[] = array(
                'id'           => intval( $e->id ),
                'slot_id'      => null,
                'annul_id'     => null,
                'date'         => $e->date,
                'heure_debut'  => $e->heure_debut,
                'heure_fin'    => $e->heure_fin,
                'titre'        => wp_unslash( $e->titre ),
                'categorie'    => wp_unslash( $e->categorie ),
                'couleur'      => $e->couleur,
                'type'         => $e->type,
				'niveau'       => $e->niveau ?? 'departemental',
                'description'  => wp_unslash( $e->description ),
                'document_url' => $e->document_url ?? '',
                'document_nom' => $e->document_nom ?? '',
                'recurrent'    => false,
                'inscriptions_actives'     => intval( $e->inscriptions_actives     ?? 0 ),
                'inscriptions_public'      => $e->inscriptions_public      ?? '',
                'inscriptions_deadline'    => $e->inscriptions_deadline    ?? null,
                'inscriptions_envoye'      => intval( $e->inscriptions_envoye      ?? 0 ),
                'inscriptions_message'     => $e->inscriptions_message     ?? '',
                'inscriptions_categories'     => $e->inscriptions_categories     ?? '',
                'inscriptions_age_categories' => $e->inscriptions_age_categories ?? '',
            );
        }

        /* 2 — Occurrences des créneaux récurrents */
        $occurrences = $this->db->get_slot_occurrences( $start, $end );

        // Charger les données des matérialisations pour récupérer doc/description
        $mat_ids = array_values( array_filter( array_column( $occurrences, 'mat_id' ), function($v){ return is_numeric($v) && intval($v) > 0; } ) );
        $mat_data = array();
        if ( ! empty($mat_ids) ) {
            // Clause IN avec entiers : intval() suffit, pas besoin de prepare()
            $ids_safe = implode( ',', array_map( 'intval', $mat_ids ) );
            $rows = $wpdb->get_results(
                "SELECT id, document_url, document_nom, description FROM $te WHERE id IN ($ids_safe)"
            );
            foreach ( $rows as $r ) {
                $mat_data[ intval($r->id) ] = $r;
            }
        }

        foreach ( $occurrences as $occ ) {
            $type   = $occ['annul_id'] ? 'annulation' : 'cours_recurrent';
            $mat_id = $occ['mat_id'] ?? null;
            $mat    = $mat_id ? ( $mat_data[$mat_id] ?? null ) : null;

            // Si une matérialisation existe, utiliser son vrai ID entier
            // → le JS sait qu'il n'a pas besoin de re-créer l'event
            $ev_id = $mat_id
                ? $mat_id
                : ( 'slot_' . $occ['slot_id'] . '_' . str_replace( '-', '', $occ['date'] ) );

            $out[] = array(
                'id'           => $ev_id,
                'slot_id'      => $occ['slot_id'],
                'annul_id'     => $occ['annul_id'],
                'date'         => $occ['date'],
                'heure_debut'  => $occ['heure_debut'],
                'heure_fin'    => $occ['heure_fin'],
                'titre'        => $occ['titre'],
                'categorie'    => $occ['categorie'],
                'couleur'      => '',
                'type'         => $type,
                'description'  => $mat ? ( $mat->description ?? '' ) : '',
                'document_url' => $mat ? ( $mat->document_url ?? '' ) : '',
                'document_nom' => $mat ? ( $mat->document_nom ?? '' ) : '',
                'recurrent'    => true,
                'mat_id'       => $mat_id,  // toujours présent pour que JS sache
            );
        }

        /* 3 — Anniversaires depuis CSV élèves */
        $birthdays = $this->db->get_birthdays_for_month( $year, $month );
        foreach ( $birthdays as $b ) {
            $parts = explode( '/', $b->date_naissance );
            if ( count( $parts ) < 2 ) continue;
            $day = intval( $parts[0] );
            if ( $day < 1 || $day > 31 ) continue;
            $date_str = sprintf( '%04d-%02d-%02d', $year, $month, $day );
            $age      = $b->annee_naissance ? ( $year - intval( $b->annee_naissance ) ) : null;
            $out[] = array(
                'id'          => 'bday_' . $date_str . '_' . crc32( $b->nom . $b->prenom ),
                'slot_id'     => null,
                'annul_id'    => null,
                'date'        => $date_str,
                'heure_debut' => '',
                'heure_fin'   => '',
                'titre'       => trim( $b->prenom . ' ' . $b->nom ) . ( $age ? ' (' . $age . ' ans)' : '' ),
                'categorie'   => 'anniversaire',
                'couleur'     => '#F59E0B',
                'type'        => 'anniversaire',
                'description' => '',
                'recurrent'   => false,
            );
        }

        /* Tri global */
        usort( $out, function( $a, $b ) {
            $c = strcmp( $a['date'], $b['date'] );
            return $c !== 0 ? $c : strcmp( $a['heure_debut'], $b['heure_debut'] );
        } );

        wp_send_json_success( $out );
    }

    /* ══════════════════════════════════════════════════════════
       SAVE EVENT (cours ponctuel | evenement | annulation)
    ══════════════════════════════════════════════════════════ */

    public function save_event() {
        $this->nonce();
        $this->require_admin();

        global $wpdb;
        $te   = $this->db->table_events();
        $type = sanitize_text_field( $_POST['type'] ?? 'evenement' );
        if ( ! in_array( $type, array( 'cours', 'evenement', 'annulation', 'examen', 'competition' ) ) ) $type = 'evenement';

        $slot_id = intval( $_POST['slot_id'] ?? 0 ) ?: null;
        $date    = sanitize_text_field( $_POST['date'] ?? '' );

        if ( empty( $date ) ) wp_send_json_error( 'Date requise.' );

        // Pour une annulation : vérifier qu'il n'en existe pas déjà une
        if ( $type === 'annulation' && $slot_id ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $te WHERE type='annulation' AND slot_id=%d AND date=%s", $slot_id, $date
            ) );
            if ( $exists ) wp_send_json_success( array( 'id' => intval($exists), 'action' => 'already_exists' ) );
        }

        $data = array(
            'date'        => $date,
            'heure_debut' => sanitize_text_field( wp_unslash( $_POST['heure_debut'] ?? '' ) ),
            'heure_fin'   => sanitize_text_field( wp_unslash( $_POST['heure_fin']   ?? '' ) ),
            'titre'       => sanitize_text_field( wp_unslash( $_POST['titre']       ?? ( $type === 'annulation' ? 'Annulation' : '' ) ) ),
            'categorie'   => sanitize_text_field( wp_unslash( $_POST['categorie']   ?? 'Général' ) ),
            'couleur'     => sanitize_hex_color(  $_POST['couleur']     ?? '#3B82F6' ) ?: '#3B82F6',
            'type'        => $type,
			'niveau'      => in_array( $type, array('competition') ) ? sanitize_text_field( wp_unslash( $_POST['niveau'] ?? 'departemental' ) ) : 'departemental',
            'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'slot_id'     => $slot_id,
        );
        // Inscriptions — seulement si les colonnes existent (migration SQL faite)
        if ( $type !== 'annulation' && $type !== 'cours_recurrent' ) {
            $data['inscriptions_actives']    = intval( $_POST['inscriptions_actives']  ?? 0 );
            $data['inscriptions_public']     = sanitize_text_field( wp_unslash( $_POST['inscriptions_public']   ?? '' ) );
            $deadline = sanitize_text_field( wp_unslash( $_POST['inscriptions_deadline'] ?? '' ) );
            $data['inscriptions_deadline']   = ( $deadline && preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) ) ? $deadline : null;
            $data['inscriptions_message']    = sanitize_textarea_field( wp_unslash( $_POST['inscriptions_message'] ?? '' ) );
            // Catégories cibles : tableau → CSV normalisé
            $cats_raw = $_POST['inscriptions_categories'] ?? '';
            if ( is_array( $cats_raw ) ) {
                $cats_clean = array_map( 'sanitize_text_field', array_map( 'wp_unslash', $cats_raw ) );
                $cats_clean = array_filter( $cats_clean );
                $data['inscriptions_categories'] = implode( ',', $cats_clean );
            } else {
                $data['inscriptions_categories'] = sanitize_text_field( wp_unslash( $cats_raw ) );
            }
            // Tranches d'âge ciblées
            $ages_raw = $_POST['inscriptions_age_categories'] ?? '';
            if ( is_array( $ages_raw ) ) {
                $ages_clean = array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $ages_raw ) ) );
                $data['inscriptions_age_categories'] = implode( ',', $ages_clean );
            } else {
                $data['inscriptions_age_categories'] = sanitize_text_field( wp_unslash( $ages_raw ) );
            }
        }

        if ( $type !== 'annulation' && empty( $data['titre'] ) ) wp_send_json_error( 'Titre requis.' );

        $id = intval( $_POST['event_id'] ?? 0 );

        // Si event_id=0 mais qu'on a un slot_id + date, vérifier si une matérialisation
        // existe déjà pour ce créneau à cette date — et la réutiliser plutôt que d'en créer une nouvelle.
        if ( ! $id && $slot_id && $date && in_array($type, array('cours','evenement')) ) {
            $existing_mat = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $te WHERE slot_id=%d AND date=%s AND type NOT IN ('annulation') ORDER BY id ASC LIMIT 1",
                $slot_id, $date
            ) );
            if ( $existing_mat ) $id = intval( $existing_mat );
        }

        if ( $id ) {
            $wpdb->update( $te, $data, array( 'id' => $id ) );
            $action = 'updated';
            wp_send_json_success( array( 'id' => $id, 'action' => $action ) );
        } else {
            $wpdb->insert( $te, $data );
            $new_id = $wpdb->insert_id;
            $action = 'inserted';

            // ── Notifications annulation cours ──────────────────────────────
            if ( $type === 'annulation' && $slot_id ) {
                $notifier = intval( $_POST['annul_notifier'] ?? 0 );
                $grouper  = intval( $_POST['annul_grouper']  ?? 0 );

                if ( $notifier ) {
                    // Récupérer le slot pour avoir categorie + horaires
                    $slot = $wpdb->get_row( $wpdb->prepare(
                        "SELECT * FROM {$this->db->table_slots()} WHERE id = %d", $slot_id
                    ) );

                    $annul_item = array(
                        'event_id'    => $new_id,
                        'slot_id'     => $slot_id,
                        'date'        => $date,
                        'titre'       => $data['titre'] !== 'Annulation' ? $data['titre'] : ( $slot ? $slot->label : 'Cours' ),
                        'heure_debut' => $slot ? $slot->heure_debut : ( $data['heure_debut'] ?? '' ),
                        'heure_fin'   => $slot ? $slot->heure_fin   : ( $data['heure_fin']   ?? '' ),
                        'categorie'   => $slot ? $slot->categorie   : ( $data['categorie']   ?? '' ),
                        'motif'       => $data['description'] ?? '',
                    );

                    if ( $grouper ) {
                        // Empiler dans wp_options
                        $pending   = get_option( 'sp_cal_annul_pending', array() );
                        $pending[] = $annul_item;
                        update_option( 'sp_cal_annul_pending', $pending, false );
                        wp_send_json_success( array(
                            'id'            => $new_id,
                            'action'        => $action,
                            'pending_count' => count( $pending ),
                        ) );
                        return;
                    } else {
                        // Envoi immédiat
                        $notif = new SpCalPro_Notifications( $this->db );
                        $sent  = $notif->send_annulation_cours( array( $annul_item ) );
                        wp_send_json_success( array(
                            'id'     => $new_id,
                            'action' => $action,
                            'sent'   => $sent,
                        ) );
                        return;
                    }
                }
            }
            // ── Fin notifications annulation ────────────────────────────────

            // Notifier le bureau (événements non-annulation)
            if ( $type !== 'annulation' ) {
                $notif  = new SpCalPro_Notifications( $this->db );
                $author = wp_get_current_user()->display_name ?: '';
                $notif->notify_bureau_on_calendar_change( 'created', $data, $author );
            }
            wp_send_json_success( array( 'id' => $new_id, 'action' => $action ) );
        }
    }

    /* ══════════════════════════════════════════════════════════
       DELETE EVENT (+ annulation cleanup)
    ══════════════════════════════════════════════════════════ */

    public function delete_event() {
        $this->nonce();
        $this->require_admin();

        global $wpdb;
        $id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'ID manquant.' );

        $wpdb->delete( $this->db->table_presences_eleves(), array( 'event_id' => $id ) );
        $wpdb->delete( $this->db->table_events(),           array( 'id'       => $id ) );
        wp_send_json_success();
    }

    /* ══════════════════════════════════════════════════════════
       PRÉSENCES
    ══════════════════════════════════════════════════════════ */

    public function get_presences() {
        $this->nonce();
        $this->require_admin();

        global $wpdb;
        $te  = $this->db->table_events();

        $event_id = intval( $_POST['event_id'] ?? 0 );

        // ── Gestion des cours récurrents (event_id = 0 / fictif) ─────────────
        // Le JS peut passer slot_id + date pour un cours récurrent non encore
        // matérialisé dans la table events. On crée alors l'event à la volée.
        if ( ! $event_id ) {
            $slot_id = intval( $_POST['slot_id'] ?? 0 );
            $date    = sanitize_text_field( $_POST['date'] ?? '' );
            if ( $slot_id && $date ) {
                // Chercher si un event existe déjà pour ce slot+date (quel que soit le type, hors annulation)
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $te WHERE slot_id=%d AND date=%s AND type NOT IN ('annulation') ORDER BY id ASC LIMIT 1",
                    $slot_id, $date
                ) );
                if ( $existing ) {
                    $event_id = intval($existing);
                } else {
                    // Récupérer le créneau pour préremplir
                    $slot = $wpdb->get_row( $wpdb->prepare(
                        "SELECT * FROM {$this->db->table_slots()} WHERE id=%d", $slot_id
                    ) );
                    if ( $slot ) {
                        $wpdb->insert( $te, array(
                            'date'        => $date,
                            'heure_debut' => $slot->heure_debut,
                            'heure_fin'   => $slot->heure_fin,
                            'titre'       => $slot->label,
                            'categorie'   => $slot->categorie,
                            'couleur'     => '#3B82F6',
                            'type'        => 'cours',
                            'description' => '',
                            'slot_id'     => $slot_id,
                        ) );
                        $event_id = intval( $wpdb->insert_id );
                    }
                }
            }
        }

        if ( ! $event_id ) wp_send_json_error( 'event_id manquant.' );

        $eleves   = $this->db->get_eleves( array( 'actif' => 1 ) );
        $pres_map = array(); // eleve_id => ['present'=>0/1, 'note'=>'']
        foreach ( $this->db->get_presences_event( $event_id ) as $p ) {
            $pres_map[ intval($p->eleve_id) ] = array(
                'present' => intval( $p->present ),
                'note'    => $p->note ?? '',
            );
        }

        $list           = array();
        $cats_saisie    = array();   // pour peupler le filtre côté JS
        foreach ( $eleves as $el ) {
            $pres = $pres_map[ intval($el->id) ] ?? null;
            $list[] = array(
                'id'               => intval( $el->id ),
                'nom'              => $el->nom,
                'prenom'           => $el->prenom,
                'categorie_age'    => $el->categorie_age,
                'categorie_saisie' => $el->categorie_saisie,
                'grade'            => $el->grade,
                'present'          => $pres !== null ? $pres['present'] : null,
                'note'             => $pres !== null ? $pres['note']    : '',
            );
            if ( $el->categorie_saisie && ! in_array( $el->categorie_saisie, $cats_saisie ) )
                $cats_saisie[] = $el->categorie_saisie;
        }

        // Grouper par catégorie d'âge (pour l'affichage principal)
        $grouped = array();
        foreach ( $list as $el ) {
            $cat = $el['categorie_age'] ?: 'Sans catégorie';
            $grouped[ $cat ][] = $el;
        }
        ksort( $grouped );
        sort( $cats_saisie );

        wp_send_json_success( array(
            'event_id'     => $event_id,
            'eleves'       => $list,
            'grouped'      => $grouped,
            'cats_saisie'  => $cats_saisie,
        ) );
    }

    public function save_presences() {
        $this->nonce();
        $this->require_admin();

        global $wpdb;
        $tpe      = $this->db->table_presences_eleves();
        $event_id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) wp_send_json_error( 'event_id manquant.' );

        // presences : {eleve_id: 0/1}
        $presences = json_decode( wp_unslash( $_POST['presences'] ?? '{}' ), true );
        // notes     : {eleve_id: 'grade string'} — optionnel (examens)
        $notes     = json_decode( wp_unslash( $_POST['notes']     ?? '{}' ), true );
        if ( ! is_array( $presences ) ) wp_send_json_error( 'Format invalide.' );

        $wpdb->delete( $tpe, array( 'event_id' => $event_id ) );
        $count = 0;
        foreach ( $presences as $eleve_id => $present ) {
            $eleve_id = intval( $eleve_id );
            if ( ! $eleve_id ) continue;
            $wpdb->insert( $tpe, array(
                'event_id' => $event_id,
                'eleve_id' => $eleve_id,
                'present'  => intval( $present ) ? 1 : 0,
                'note'     => isset( $notes[$eleve_id] ) ? sanitize_text_field( $notes[$eleve_id] ) : null,
            ) );
            $count++;
        }
        wp_send_json_success( array( 'saved' => $count ) );
    }

    /* ══════════════════════════════════════════════════════════
       APPLIQUER LES GRADES (examen → mise à jour élèves)
    ══════════════════════════════════════════════════════════ */

    public function apply_grades() {
        $this->nonce();
        $this->require_admin();

        global $wpdb;
        $tel      = $this->db->table_eleves();
        $tpe      = $this->db->table_presences_eleves();
        $event_id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) wp_send_json_error( 'event_id manquant.' );

        // Récupérer les présences avec note pour cet événement
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT eleve_id, note FROM $tpe WHERE event_id=%d AND note IS NOT NULL AND note != ''",
            $event_id
        ) );

        $updated = 0;
        foreach ( $rows as $r ) {
            $grade = sanitize_text_field( $r->note );
            if ( $wpdb->update( $tel, array( 'grade' => $grade ), array( 'id' => intval($r->eleve_id) ) ) !== false )
                $updated++;
        }

        wp_send_json_success( array(
            'updated' => $updated,
            'msg'     => $updated . ' grade(s) appliqué(s) aux fiches élèves.',
        ) );
    }

    /* ══════════════════════════════════════════════════════════
       LIER / DÉLIER UN ÉLÈVE D'UN EXAMEN (depuis la fiche)
    ══════════════════════════════════════════════════════════ */

    public function link_examen() {
        $this->nonce();
        $this->require_admin();
        global $wpdb;
        $tpe      = $this->db->table_presences_eleves();
        $eleve_id = intval( $_POST['eleve_id']  ?? 0 );
        $event_id = intval( $_POST['event_id']  ?? 0 );
        $note     = sanitize_text_field( $_POST['note'] ?? '' );
        if ( ! $eleve_id || ! $event_id ) wp_send_json_error( 'Paramètres manquants.' );

        // Vérifier que c'est bien un examen
        $te    = $this->db->table_events();
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT id, date, titre, categorie FROM $te WHERE id=%d AND type='examen'", $event_id ) );
        if ( ! $event ) wp_send_json_error( 'Événement introuvable ou pas de type examen.' );

        // Upsert
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $tpe WHERE event_id=%d AND eleve_id=%d", $event_id, $eleve_id
        ) );
        if ( $exists ) {
            $wpdb->update( $tpe, array( 'present' => 1, 'note' => $note ?: null ), array( 'id' => intval($exists) ) );
        } else {
            $wpdb->insert( $tpe, array( 'event_id' => $event_id, 'eleve_id' => $eleve_id, 'present' => 1, 'note' => $note ?: null ) );
        }
        // Si une note est fournie, mettre à jour le grade de l'élève
        if ( $note ) {
            $wpdb->update( $this->db->table_eleves(), array( 'grade' => $note ), array( 'id' => $eleve_id ) );
        }
        wp_send_json_success( array(
            'event_id'  => $event->id,
            'date'      => $event->date,
            'titre'     => $event->titre,
            'categorie' => $event->categorie,
            'note'      => $note,
        ) );
    }

    public function unlink_examen() {
        $this->nonce();
        $this->require_admin();
        global $wpdb;
        $tpe      = $this->db->table_presences_eleves();
        $eleve_id = intval( $_POST['eleve_id'] ?? 0 );
        $event_id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $eleve_id || ! $event_id ) wp_send_json_error( 'Paramètres manquants.' );
        $wpdb->delete( $tpe, array( 'event_id' => $event_id, 'eleve_id' => $eleve_id ) );
        wp_send_json_success();
    }

    /* ══════════════════════════════════════════════════════════
       ÉDITER / SUPPRIMER UN GRADE CSV (extra_data)
    ══════════════════════════════════════════════════════════ */

    public function delete_csv_grade() {
        $this->nonce();
        $this->require_gestion_adhesions();
        global $wpdb;
        $tel      = $this->db->table_eleves();
        $eleve_id = intval( $_POST['eleve_id'] ?? 0 );
        $date_key = sanitize_text_field( $_POST['date_key'] ?? '' );
        if ( ! $eleve_id || ! $date_key ) wp_send_json_error( 'Paramètres manquants.' );

        $el = $wpdb->get_row( $wpdb->prepare( "SELECT extra_data, grade FROM $tel WHERE id=%d", $eleve_id ) );
        if ( ! $el ) wp_send_json_error( 'Élève introuvable.' );

        $extra  = $el->extra_data ? ( json_decode( $el->extra_data, true ) ?: array() ) : array();
        $grades = $extra['grades'] ?? array();
        if ( ! isset( $grades[$date_key] ) ) wp_send_json_error( 'Grade introuvable.' );

        $deleted_grade = $grades[$date_key];
        unset( $grades[$date_key] );
        $extra['grades'] = $grades;

        // Recalculer grade actuel si nécessaire
        $new_grade = $el->grade;
        if ( $el->grade === $deleted_grade ) {
            if ( ! empty($grades) ) {
                $sorted = $grades;
                uksort($sorted, function($a, $b) {
                    $p = function($d){ $x=explode('/',$d); return count($x)===3?mktime(0,0,0,intval($x[1]),intval($x[0]),intval($x[2])):0; };
                    return $p($b) - $p($a);
                });
                $new_grade = reset($sorted);
            } else {
                $new_grade = '';
            }
        }

        $wpdb->update( $tel, array( 'extra_data' => wp_json_encode($extra), 'grade' => $new_grade ), array( 'id' => $eleve_id ) );
        wp_send_json_success( array( 'new_grade' => $new_grade ) );
    }

    public function edit_csv_grade() {
        $this->nonce();
        $this->require_gestion_adhesions();
        global $wpdb;
        $tel       = $this->db->table_eleves();
        $eleve_id  = intval( $_POST['eleve_id']  ?? 0 );
        $date_key  = sanitize_text_field( $_POST['date_key']  ?? '' );
        $new_date  = sanitize_text_field( $_POST['new_date']  ?? '' );
        $new_grade = sanitize_text_field( $_POST['new_grade'] ?? '' );
        if ( ! $eleve_id || ! $date_key || ! $new_grade ) wp_send_json_error( 'Paramètres manquants.' );

        $el = $wpdb->get_row( $wpdb->prepare( "SELECT extra_data, grade FROM $tel WHERE id=%d", $eleve_id ) );
        if ( ! $el ) wp_send_json_error( 'Élève introuvable.' );

        $extra  = $el->extra_data ? ( json_decode( $el->extra_data, true ) ?: array() ) : array();
        $grades = $extra['grades'] ?? array();

        unset( $grades[$date_key] );
        $final_date        = $new_date ?: $date_key;
        $grades[$final_date] = $new_grade;
        $extra['grades']   = $grades;

        // Grade actuel = le plus récent dans tout l'historique
        $sorted = $grades;
        uksort($sorted, function($a, $b) {
            $p = function($d){ $x=explode('/',$d); return count($x)===3?mktime(0,0,0,intval($x[1]),intval($x[0]),intval($x[2])):0; };
            return $p($b) - $p($a);
        });
        $current_grade = reset($sorted) ?: $el->grade;

        $wpdb->update( $tel, array( 'extra_data' => wp_json_encode($extra), 'grade' => $current_grade ), array( 'id' => $eleve_id ) );
        wp_send_json_success( array(
            'old_date'      => $date_key,
            'new_date'      => $final_date,
            'new_grade'     => $new_grade,
            'current_grade' => $current_grade,
        ) );
    }

    public function import_csv() {
        $this->nonce();
        $this->require_gestion_adhesions();
        if ( empty( $_FILES['sp_csv_file']['tmp_name'] ) ) wp_send_json_error( 'Aucun fichier reçu.' );

        $raw = file_get_contents( $_FILES['sp_csv_file']['tmp_name'] );
        if ( ! mb_detect_encoding( $raw, 'UTF-8', true ) )
            $raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
        $raw   = preg_replace( '/\r\n|\r/', "\n", $raw );
        $lines = array_values( array_filter( explode( "\n", $raw ), function($l){ return trim($l) !== ''; } ) );
        if ( empty($lines) ) wp_send_json_error( 'Fichier vide.' );

        $sample    = implode( "\n", array_slice($lines, 0, 5) );
        $delimiter = ( substr_count($sample, ';') >= substr_count($sample, ',') ) ? ';' : ',';

        $rows = array();
        foreach ( $lines as $line ) {
            $h = fopen('php://memory','r+'); fwrite($h,$line); rewind($h);
            $r = fgetcsv($h, 0, $delimiter); fclose($h);
            if ($r !== false) $rows[] = $r;
        }
        if ( empty($rows) ) wp_send_json_error( 'Impossible de parser.' );

        // ── Trouver la ligne d'en-tête (contient "NOM") ──────
        $header_row = null; $header_idx = 0;
        foreach ( $rows as $ri => $row ) {
            foreach ( $row as $cell ) {
                if ( mb_strtoupper(trim($cell)) === 'NOM' ) { $header_row = $row; $header_idx = $ri; break 2; }
            }
        }
        if ( ! $header_row ) wp_send_json_error( 'Colonne NOM introuvable.' );

        // ── Mapper les colonnes ───────────────────────────────
        $col = array(
            'nom'               => false,
            'prenom'            => false,
            'ddn'               => false,
            'licence'           => false,
            'rang'              => false,
            'cat_age'           => false,
            'cat_saisie'        => false,
            'saison'            => false,
            'palmares'          => false,
            'licence_expiration'=> false,
            'actif'             => false,
            // Contacts & coordonnées
            'email'                  => false,
            'email_parent'           => false,
            'telephone'              => false,
            'representant_nom'       => false,
            'representant_prenom'    => false,
            'representant_telephone' => false,
            'urgence_nom'            => false,
            'urgence_prenom'         => false,
            'urgence_telephone'      => false,
            'urgence_email'          => false,
            // Contact générique (mappé selon âge au moment du parse)
            'contact_nom'            => false,
            'contact_prenom'         => false,
            'contact_telephone'      => false,
            // Champs RENFO
            'lieu_naissance'    => false,
            'nationalite'       => false,
            'adresse'           => false,
            'taille_cm'         => false,
            'poids_kg'          => false,
            'pointure'          => false,
            'taille_tshirt'     => false,
            'taille_pantalon'   => false,
            'droit_image'       => false,
        );
        $grade_cols = array(); // clé = index colonne, valeur = label (ex: "21/06/2025")

        foreach ( $header_row as $i => $h ) {
            $hu = mb_strtoupper(trim($h));
            // ASCII translitéré pour comparaisons accentuées
            $ha = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $hu);

            if     ( $ha === 'NOM' )                                                      $col['nom']               = $i;
            elseif ( strpos($ha,'PRENOM') !== false )                                     $col['prenom']            = $i;
            // Anniversaire OU Date naissance (JJ/MM/AAAA)
            elseif ( strpos($ha,'ANNIV') !== false )                                      $col['ddn']               = $i;
            elseif ( strpos($ha,'DATE') !== false && strpos($ha,'NAISS') !== false )      $col['ddn']               = $i;
            // Actif / Statut
            elseif ( $ha === 'ACTIF' || $ha === 'STATUT' || $ha === 'STATUS' )           $col['actif']             = $i;
            // Licence expiration : "Date expiration", "Expiration", "Fin licence"
            elseif ( strpos($ha,'EXPIR') !== false )                                      $col['licence_expiration']= $i;
            elseif ( strpos($ha,'FIN') !== false && strpos($ha,'LICEN') !== false )       $col['licence_expiration']= $i;
            elseif ( strpos($ha,'LICEN') !== false && strpos($ha,'DATE') !== false )      $col['licence_expiration']= $i;
            elseif ( strpos($ha,'LICEN') !== false )                                      $col['licence']           = $i;
            elseif ( $ha === 'RANG' )                                                     $col['rang']       = $i;
            // Catégorie d'âge : contient "CAT" + ("AGE" ou "D") mais PAS "SAISI"
            elseif ( strpos($ha,'CAT') !== false && strpos($ha,'SAISI') === false
                     && ( strpos($ha,'AGE') !== false || $ha === 'CATEGORIE' || $ha === 'CATEGORI' ) )
                                                                                          $col['cat_age']    = $i;
            // Catégorie saisie : contient "SAISI"
            elseif ( strpos($ha,'SAISI') !== false )                                      $col['cat_saisie'] = $i;
            elseif ( $ha === 'SAISON' )                                                   $col['saison']     = $i;
            elseif ( strpos($ha,'PALMARES') !== false || strpos($ha,'PALMARES') !== false
                     || $ha === 'PALMARES' || strpos($ha,'PALMAR') !== false )            $col['palmares']   = $i;
            // Contacts & coordonnées
            elseif ( $ha === 'TELEPHONE' || $ha === 'TEL' || strpos($ha,'TELEPH') !== false
                     || strpos($ha,'MOBILE') !== false || strpos($ha,'PORTABLE') !== false ) {
                if ( $col['telephone'] === false ) $col['telephone'] = $i;
            }
            elseif ( strpos($ha,'EMAIL') !== false && ( strpos($ha,'PARENT') !== false
                     || strpos($ha,'TUTEUR') !== false || strpos($ha,'FAMILLE') !== false ) )
                                                                                              $col['email_parent']      = $i;
            elseif ( $ha === 'EMAIL' || $ha === 'MAIL' || $ha === 'COURRIEL'
                     || ( strpos($ha,'EMAIL') !== false && $col['email'] === false ) )        $col['email']             = $i;
            elseif ( strpos($ha,'REPRES') !== false || strpos($ha,'TUTEUR') !== false
                     || strpos($ha,'PARENT') !== false )                                      $col['representant_nom']  = $i;
            elseif ( ( strpos($ha,'URGENCE') !== false || strpos($ha,'URGENC') !== false )
                     && ( strpos($ha,'TEL') !== false || strpos($ha,'MOBILE') !== false ) )   $col['urgence_telephone'] = $i;
            elseif ( ( strpos($ha,'URGENCE') !== false || strpos($ha,'URGENC') !== false )
                     && ( strpos($ha,'EMAIL') !== false || strpos($ha,'MAIL') !== false ) )   $col['urgence_email']     = $i;
            elseif ( ( strpos($ha,'URGENCE') !== false || strpos($ha,'URGENC') !== false )
                     && strpos($ha,'PRENOM') !== false )                                       $col['urgence_prenom']    = $i;
            elseif ( strpos($ha,'URGENCE') !== false || strpos($ha,'URGENC') !== false )      $col['urgence_nom']       = $i;
            // Contact générique (sera réparti selon majorité de l'élève au moment du parse)
            elseif ( $ha === 'CONTACT NOM'    || ( strpos($ha,'CONTACT') !== false && strpos($ha,'NOM') !== false && strpos($ha,'PRENOM') === false ) )
                                                                                               $col['contact_nom']       = $i;
            elseif ( $ha === 'CONTACT PRENOM' || ( strpos($ha,'CONTACT') !== false && strpos($ha,'PRENOM') !== false ) )
                                                                                               $col['contact_prenom']    = $i;
            elseif ( $ha === 'CONTACT TELEPHONE' || ( strpos($ha,'CONTACT') !== false && ( strpos($ha,'TEL') !== false || strpos($ha,'MOBILE') !== false ) ) )
                                                                                               $col['contact_telephone'] = $i;
            // Champs RENFO
            elseif ( strpos($ha,'LIEU') !== false && strpos($ha,'NAISS') !== false )      $col['lieu_naissance']  = $i;
            elseif ( strpos($ha,'NATIONAL') !== false )                                   $col['nationalite']     = $i;
            elseif ( $ha === 'ADRESSE' || strpos($ha,'ADRESSE') !== false )               $col['adresse']         = $i;
            elseif ( strpos($ha,'TAILLE') !== false && strpos($ha,'CM') !== false )       $col['taille_cm']       = $i;
            elseif ( strpos($ha,'POIDS') !== false )                                      $col['poids_kg']        = $i;
            elseif ( strpos($ha,'POINTURE') !== false )                                   $col['pointure']        = $i;
            elseif ( strpos($ha,'T-SHIRT') !== false || strpos($ha,'TSHIRT') !== false )  $col['taille_tshirt']   = $i;
            elseif ( strpos($ha,'PANTALON') !== false )                                   $col['taille_pantalon'] = $i;
            elseif ( strpos($ha,'DROIT') !== false && strpos($ha,'IMAGE') !== false )     $col['droit_image']     = $i;
            // Colonnes grades : soit contient "GRADE", soit ressemble à une date (JJ/MM/AAAA ou DD/MM/YYYY)
            elseif ( strpos($hu,'GRADE') !== false
                     || preg_match('/^\d{2}\/\d{2}\/\d{4}$/', trim($h))
                     || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($h)) ) {
                $grade_cols[$i] = trim($h);
            }
        }

        global $wpdb;
        $tel = $this->db->table_eleves();
        $added = 0; $updated = 0; $skip = 0; $errors = array();

        for ( $ri = $header_idx + 1; $ri < count($rows); $ri++ ) {
            $row  = $rows[$ri];
            $nom  = $col['nom']    !== false ? trim($row[$col['nom']]    ?? '') : '';
            $pren = $col['prenom'] !== false ? trim($row[$col['prenom']] ?? '') : '';
            // Ignorer lignes vides ou lignes de total
            if ( empty($nom) || preg_match('#^(NOMBRE|TOTAL|ACTIF|INACTIF)$#i', $nom) ) { $skip++; continue; }

            // ── Date de naissance ─────────────────────────────
            $raw_ddn = $col['ddn'] !== false ? trim($row[$col['ddn']] ?? '') : '';
            $ddn = ''; $annee = '';
            if ( $raw_ddn ) {
                // Format JJ/MM/AAAA
                if ( preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw_ddn, $m) ) {
                    $ddn = $m[1] . '/' . $m[2];
                    $annee = $m[3];
                // Format AAAA-MM-JJ
                } elseif ( preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw_ddn, $m) ) {
                    $ddn = $m[3] . '/' . $m[2];
                    $annee = $m[1];
                // Fallback strtotime
                } elseif ( $ts = strtotime($raw_ddn) ) {
                    $ddn = date('d/m', $ts);
                    $annee = date('Y', $ts);
                }
            }

            // ── Grades (colonnes dates) ───────────────────────
            // Le dernier grade non-vide devient le grade courant
            $grade = ''; $extra_grades = array();
            foreach ( $grade_cols as $ci => $glabel ) {
                $gv = trim($row[$ci] ?? '');
                if ( $gv && $gv !== '/' && $gv !== '-' ) {
                    $grade = $gv;                    // sera écrasé à chaque itération → dernier grade valide
                    $extra_grades[$glabel] = $gv;
                }
            }

            // ── Date expiration licence ───────────────────────
            $licence_exp = null;
            if ( $col['licence_expiration'] !== false ) {
                $raw_exp = trim($row[$col['licence_expiration']] ?? '');
                if ( $raw_exp ) {
                    // Formats acceptés : JJ/MM/AAAA, AAAA-MM-JJ, JJ-MM-AAAA
                    if ( preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw_exp, $m) )
                        $licence_exp = $m[3] . '-' . $m[2] . '-' . $m[1];
                    elseif ( preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw_exp) )
                        $licence_exp = $raw_exp;
                    elseif ( $ts = strtotime($raw_exp) )
                        $licence_exp = date('Y-m-d', $ts);
                }
            }

            // ── Assemblage de la ligne ────────────────────────

            // Calcul majorité : mineur si annee_naissance connue et âge < 18
            $est_mineur = false;
            if ( $annee && intval($annee) > 1900 ) {
                $age_approx = intval(date('Y')) - intval($annee);
                $est_mineur = ( $age_approx < 18 );
            }

            // Résoudre les colonnes Contact generiques selon majorité
            $c_nom  = $col['contact_nom']       !== false ? sanitize_text_field( trim($row[$col['contact_nom']]       ?? '') ) : '';
            $c_pren = $col['contact_prenom']     !== false ? sanitize_text_field( trim($row[$col['contact_prenom']]   ?? '') ) : '';
            $c_tel  = $col['contact_telephone']  !== false ? sanitize_text_field( trim($row[$col['contact_telephone']] ?? '') ) : '';

            // Champs directs (hors Contact générique)
            $rep_nom  = $col['representant_nom']       !== false ? sanitize_text_field( trim($row[$col['representant_nom']]       ?? '') ) : '';
            $rep_pren = $col['representant_prenom']     !== false ? sanitize_text_field( trim($row[$col['representant_prenom']]   ?? '') ) : '';
            $rep_tel  = $col['representant_telephone']  !== false ? sanitize_text_field( trim($row[$col['representant_telephone']] ?? '') ) : '';
            $urg_nom  = $col['urgence_nom']             !== false ? sanitize_text_field( trim($row[$col['urgence_nom']]           ?? '') ) : '';
            $urg_pren = $col['urgence_prenom']          !== false ? sanitize_text_field( trim($row[$col['urgence_prenom']]        ?? '') ) : '';
            $urg_tel  = $col['urgence_telephone']       !== false ? sanitize_text_field( trim($row[$col['urgence_telephone']]     ?? '') ) : '';

            // Appliquer le Contact générique selon majorité (ne remplace que si le champ direct est vide)
            if ( $c_nom || $c_pren || $c_tel ) {
                if ( $est_mineur ) {
                    // Mineur → Contact = représentant légal
                    if ( ! $rep_nom  ) $rep_nom  = $c_nom;
                    if ( ! $rep_pren ) $rep_pren = $c_pren;
                    if ( ! $rep_tel  ) $rep_tel  = $c_tel;
                } else {
                    // Majeur → Contact = premier contact d'urgence
                    if ( ! $urg_nom  ) $urg_nom  = $c_nom;
                    if ( ! $urg_pren ) $urg_pren = $c_pren;
                    if ( ! $urg_tel  ) $urg_tel  = $c_tel;
                }
            }

            $data = array(
                'nom'                => $nom,
                'prenom'             => $pren,
                'date_naissance'     => $ddn,
                'annee_naissance'    => $annee,
                'grade'              => $grade,
                'categorie_age'      => $col['cat_age']    !== false ? trim($row[$col['cat_age']]    ?? '') : '',
                'categorie_saisie'   => $col['cat_saisie'] !== false ? trim($row[$col['cat_saisie']] ?? '') : '',
                'saison'             => $col['saison']     !== false ? trim($row[$col['saison']]     ?? '') : '',
                'rang'               => $col['rang']       !== false ? intval($row[$col['rang']]     ?? 0)  : 0,
                'palmares'           => $col['palmares']   !== false ? trim($row[$col['palmares']]   ?? '') : '',
                'licence'            => $col['licence']    !== false ? trim($row[$col['licence']]    ?? '') : '',
                'actif'              => $col['actif'] !== false
                    ? ( in_array(strtolower(trim($row[$col['actif']] ?? '')), array('0','non','no','false','inactif')) ? 0 : 1 )
                    : 1,
                'motif_inactif'      => '',
                // Contacts & coordonnées
                'email'                  => $col['email']        !== false ? sanitize_email( $row[$col['email']]        ?? '' ) : '',
                'email_parent'           => $col['email_parent'] !== false ? sanitize_email( $row[$col['email_parent']] ?? '' ) : '',
                'telephone'              => $col['telephone']    !== false ? sanitize_text_field( trim($row[$col['telephone']] ?? '') ) : '',
                'representant_nom'       => $rep_nom,
                'representant_prenom'    => $rep_pren,
                'representant_telephone' => $rep_tel,
                'urgence_nom'            => $urg_nom,
                'urgence_prenom'         => $urg_pren,
                'urgence_telephone'      => $urg_tel,
                'urgence_email'          => $col['urgence_email'] !== false ? sanitize_email( $row[$col['urgence_email']] ?? '' ) : '',
                // Champs RENFO
                'lieu_naissance'    => $col['lieu_naissance']    !== false ? sanitize_text_field( trim($row[$col['lieu_naissance']]    ?? '') ) : '',
                'nationalite'       => $col['nationalite']       !== false ? sanitize_text_field( trim($row[$col['nationalite']]       ?? '') ) : '',
                'adresse'           => $col['adresse']           !== false ? sanitize_text_field( trim($row[$col['adresse']]           ?? '') ) : '',
                'taille_cm'         => $col['taille_cm']         !== false ? sanitize_text_field( trim($row[$col['taille_cm']]         ?? '') ) : '',
                'poids_kg'          => $col['poids_kg']          !== false ? sanitize_text_field( trim($row[$col['poids_kg']]          ?? '') ) : '',
                'pointure'          => $col['pointure']          !== false ? sanitize_text_field( trim($row[$col['pointure']]          ?? '') ) : '',
                'taille_tshirt'     => $col['taille_tshirt']     !== false ? sanitize_text_field( trim($row[$col['taille_tshirt']]     ?? '') ) : '',
                'taille_pantalon'   => $col['taille_pantalon']   !== false ? sanitize_text_field( trim($row[$col['taille_pantalon']]   ?? '') ) : '',
                'droit_image'       => $col['droit_image']       !== false
                    ? ( in_array(strtolower(trim($row[$col['droit_image']] ?? '')), array('1','oui','yes','true')) ? 1 : 0 )
                    : 0,
                'extra_data'         => wp_json_encode( array(
                    'grades'   => $extra_grades,
                    'source'   => 'csv',
                    'imported' => date('Y-m-d H:i:s'),
                ) ),
            );


            // ── Upsert ───────────────────────────────────────
            $licence = $data['licence'];
            if ( $licence )
                $exists = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $tel WHERE licence=%s", $licence ) );
            else
                $exists = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $tel WHERE nom=%s AND prenom=%s", $nom, $pren ) );

            if ( $exists )     { $wpdb->update( $tel, $data, array( 'id' => $exists->id ) ); $updated++; }
            elseif ( $wpdb->insert( $tel, $data ) !== false ) { $added++; }
            else               { $errors[] = $nom . ' ' . $pren; }
        }

        // ── Résumé ────────────────────────────────────────────
        $detected = array();
        foreach ( array(
            'saison'             => 'Saison',
            'rang'               => 'Rang',
            'cat_saisie'         => 'Catégorie saisie',
            'cat_age'            => 'Catégorie d\'âge',
            'ddn'                => 'Anniversaire',
            'licence'            => 'Licence',
            'licence_expiration' => 'Date expiration licence',
            'palmares'           => 'Palmarès',
            'actif'              => 'Statut actif/inactif',
            'email'              => 'Email élève',
            'email_parent'       => 'Email parent/tuteur',
            'telephone'          => 'Téléphone',
            'representant_nom'       => 'Représentant légal (nom)',
            'representant_prenom'    => 'Représentant légal (prénom)',
            'representant_telephone' => 'Représentant légal (tél.)',
            'urgence_nom'            => 'Contact urgence (nom)',
            'urgence_prenom'         => 'Contact urgence (prénom)',
            'urgence_telephone'      => 'Contact urgence (tél.)',
            'urgence_email'          => 'Contact urgence (email)',
            'contact_nom'            => 'Contact générique (nom)',
            'contact_prenom'         => 'Contact générique (prénom)',
            'contact_telephone'      => 'Contact générique (tél.)',
            'lieu_naissance'     => 'Lieu de naissance',
            'nationalite'        => 'Nationalité',
            'adresse'            => 'Adresse',
            'taille_cm'          => 'Taille (cm)',
            'poids_kg'           => 'Poids (kg)',
            'pointure'           => 'Pointure',
            'taille_tshirt'      => 'Taille t-shirt',
            'taille_pantalon'    => 'Taille pantalon',
            'droit_image'        => 'Droit à l\'image',
        ) as $k => $label ) {
            if ( $col[$k] !== false ) $detected[] = $label;
        }
        if ( $grade_cols ) $detected[] = count($grade_cols) . ' colonne(s) de grades';

        // ── Détection liens famille ────────────────────────────────────────
        // Critères (OR) :
        //   1. Même email_parent (email du tuteur/famille, pas l'email personnel de l'élève)
        //   2. Même nom de famille (nom), à condition qu'ils aient tous les deux un urgence_email
        //      ou email_parent non vide (évite les faux positifs sur des homonymes sans lien visible)
        $famille_detected = 0;
        $tfl = $this->db->table_famille_liens();
        $candidats_pairs = array(); // set de paires "id1_id2" déjà traitées

        $helper_insert_lien = function( $ida, $idb ) use ( $wpdb, $tfl, &$famille_detected, &$candidats_pairs ) {
            $id1 = min($ida, $idb);
            $id2 = max($ida, $idb);
            $key = $id1 . '_' . $id2;
            if ( isset($candidats_pairs[$key]) ) return;
            $candidats_pairs[$key] = true;
            $exists_lien = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $tfl WHERE eleve_id_1=%d AND eleve_id_2=%d", $id1, $id2
            ) );
            if ( ! $exists_lien ) {
                $wpdb->insert( $tfl, array(
                    'eleve_id_1' => $id1,
                    'eleve_id_2' => $id2,
                    'type_lien'  => 'famille',
                    'confirme'   => 0,
                ) );
                $famille_detected++;
            }
        };

        // Critère 1 : même email_parent (email de la famille, pas l'adresse personnelle de l'élève)
        $email_parent_map = array(); // email_parent => [ids]
        $all = $wpdb->get_results( "SELECT id, nom, email_parent, urgence_email FROM $tel WHERE email_parent != '' AND actif=1" );
        foreach ( $all as $row2 ) {
            $em = strtolower( trim( $row2->email_parent ) );
            if ( $em ) $email_parent_map[$em][] = intval($row2->id);
        }
        foreach ( $email_parent_map as $em => $ids ) {
            $ids = array_unique($ids);
            if ( count($ids) < 2 ) continue;
            for ( $a = 0; $a < count($ids); $a++ )
                for ( $b = $a + 1; $b < count($ids); $b++ )
                    $helper_insert_lien( $ids[$a], $ids[$b] );
        }

        // Critère 2 : même nom de famille, à condition que les deux aient un email famille non vide
        // (email_parent OU urgence_email) — réduit les faux positifs sur homonymes
        $nom_map = array(); // nom_normalized => [ids]
        $all2 = $wpdb->get_results( "SELECT id, nom, email_parent, urgence_email FROM $tel WHERE actif=1 AND (email_parent != '' OR urgence_email != '')" );
        foreach ( $all2 as $row2 ) {
            $nom_key = mb_strtolower( trim( iconv('UTF-8','ASCII//TRANSLIT//IGNORE', $row2->nom) ) );
            if ( $nom_key ) $nom_map[$nom_key][] = intval($row2->id);
        }
        foreach ( $nom_map as $nom_key => $ids ) {
            $ids = array_unique($ids);
            if ( count($ids) < 2 ) continue;
            for ( $a = 0; $a < count($ids); $a++ )
                for ( $b = $a + 1; $b < count($ids); $b++ )
                    $helper_insert_lien( $ids[$a], $ids[$b] );
        }

        $msg  = '<strong>Import terminé.</strong><br>';
        $msg .= '👤 ' . $added . ' ajouté(s), ' . $updated . ' mis à jour, ' . $skip . ' ignoré(s).';
        $msg .= '<br>📋 Colonnes détectées : ' . ( $detected ? implode(', ', $detected) : '(aucune colonne optionnelle)' );
        if ( $famille_detected ) $msg .= '<br>👨‍👩‍👧 ' . $famille_detected . ' lien(s) famille détecté(s) à confirmer (même email famille ou même nom).';
        if ( $errors ) $msg .= '<br>⚠️ Erreurs : ' . implode(', ', array_map('esc_html', $errors));
        wp_send_json_success($msg);
    }

    /* ══════════════════════════════════════════════════════════
       LIENS FAMILLE
    ══════════════════════════════════════════════════════════ */

    public function confirm_famille_lien() {
        $this->nonce(); $this->require_admin();
        $lien_id   = intval( $_POST['lien_id']   ?? 0 );
        $type_lien = sanitize_text_field( $_POST['type_lien'] ?? 'famille' );
        if ( ! $lien_id ) wp_send_json_error( 'ID lien manquant.' );
        global $wpdb;
        $tfl = $this->db->table_famille_liens();
        $wpdb->update( $tfl, array( 'confirme' => 1, 'type_lien' => $type_lien ), array( 'id' => $lien_id ) );
        wp_send_json_success( 'Lien confirmé.' );
    }

    public function delete_famille_lien() {
        $this->nonce(); $this->require_admin();
        $lien_id = intval( $_POST['lien_id'] ?? 0 );
        if ( ! $lien_id ) wp_send_json_error( 'ID lien manquant.' );
        global $wpdb;
        $tfl = $this->db->table_famille_liens();
        $wpdb->delete( $tfl, array( 'id' => $lien_id ) );
        wp_send_json_success( 'Lien supprimé.' );
    }

    /* ══════════════════════════════════════════════════════════
       KM EXCEPTIONNELS
    ══════════════════════════════════════════════════════════ */

    public function save_km_excep() {
        $this->nonce(); $this->require_admin();
        $trainer_id  = intval(   $_POST['trainer_id']  ?? 0 );
        $date        = sanitize_text_field( $_POST['date']        ?? '' );
        $description = sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) );
        $km          = floatval( str_replace(',','.',$_POST['km'] ?? '0') );
        if ( ! $trainer_id || ! $date || $km <= 0 ) wp_send_json_error( 'Données manquantes ou km invalides.' );
        if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ) wp_send_json_error( 'Format de date invalide.' );
        global $wpdb;
        $tkm = $this->db->table_km_exceptionnels();
        $wpdb->insert( $tkm, array(
            'trainer_id'  => $trainer_id,
            'date'        => $date,
            'description' => $description,
            'km'          => $km,
            'created_by'  => get_current_user_id(),
        ) );
        $new_id = $wpdb->insert_id;
        if ( ! $new_id ) wp_send_json_error( 'Erreur lors de l\'enregistrement.' );
        wp_send_json_success( array( 'id' => $new_id, 'message' => 'Déplacement enregistré.' ) );
    }

    public function delete_km_excep() {
        $this->nonce(); $this->require_admin();
        $id = intval( $_POST['km_id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'ID manquant.' );
        global $wpdb;
        $tkm = $this->db->table_km_exceptionnels();
        $wpdb->delete( $tkm, array( 'id' => $id ) );
        wp_send_json_success( 'Supprimé.' );
    }

    public function get_km_excep() {
        $this->nonce(); $this->require_admin();
        $year  = intval( $_POST['year']  ?? date('Y') );
        $month = intval( $_POST['month'] ?? date('m') );
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));
        global $wpdb;
        $tkm = $this->db->table_km_exceptionnels();
        $tt  = $this->db->table_trainers();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT k.*, t.nom AS trainer_nom
             FROM $tkm k
             INNER JOIN $tt t ON t.id = k.trainer_id
             WHERE k.date BETWEEN %s AND %s
             ORDER BY k.date ASC, k.id ASC",
            $start, $end
        ) );
        wp_send_json_success( $rows );
    }

    /* ══════════════════════════════════════════════════════════
       IMPORT SPORTPRESS
    ══════════════════════════════════════════════════════════ */

    public function import_sportpress() {
        $this->nonce(); $this->require_admin();
        $post_types=array('sp_player','sp_staff');
        foreach(get_post_types(array(),'names') as $pt){ if(strpos($pt,'sp_')===0&&!in_array($pt,$post_types))$post_types[]=$pt; }

        $players=array();
        foreach($post_types as $pt){
            $q=new WP_Query(array('post_type'=>$pt,'posts_per_page'=>-1,'post_status'=>'publish'));
            if($q->have_posts()){while($q->have_posts()){$q->the_post();$players[]=array('id'=>get_the_ID(),'name'=>get_the_title());}}
        }
        wp_reset_postdata();
        if(empty($players)) wp_send_json_error('Aucun joueur SportPress trouvé.');

        global $wpdb; $tel=$this->db->table_eleves(); $added=0; $updated=0;
        $bday_keys=array('sp_birthday','_sp_birthday','birthday','sp_dob','date_of_birth','dob');
        foreach($players as $pl){
            $name=sanitize_text_field($pl['name']); if(!$name) continue;
            $raw_bday=''; foreach($bday_keys as $k){$v=get_post_meta($pl['id'],$k,true);if($v){$raw_bday=$v;break;}}
            $ddn=''; $annee='';
            if($raw_bday&&($ts=strtotime($raw_bday))){$ddn=date('d/m',$ts);$annee=date('Y',$ts);}
            $parts=explode(' ',$name,2);
            $data=array('nom'=>mb_strtoupper($parts[0]??$name),'prenom'=>$parts[1]??'','date_naissance'=>$ddn,'annee_naissance'=>$annee,'extra_data'=>wp_json_encode(array('source'=>'sportpress')));
            $exists=$wpdb->get_row($wpdb->prepare("SELECT id FROM $tel WHERE nom=%s AND prenom=%s",$data['nom'],$data['prenom']));
            if($exists){$wpdb->update($tel,$data,array('id'=>$exists->id));$updated++;}
            else{$wpdb->insert($tel,$data);$added++;}
        }
        wp_send_json_success(count($players).' trouvé(s). '.$added.' ajouté(s), '.$updated.' mis à jour.');
    }

    /* ══════════════════════════════════════════════════════════
       DISPONIBILITÉS ENTRAÎNEURS
    ══════════════════════════════════════════════════════════ */

    /**
     * Récupère les dispos + liste des entraîneurs pour un mois.
     * Appelé au chargement du calendrier (même requête get_events ou séparément).
     */
    public function get_trainer_dispos() {
        $this->nonce();
        $year  = intval( $_POST['year']  ?? date('Y') );
        $month = intval( $_POST['month'] ?? date('m') );

        $trainers = $this->db->get_trainers_entraineurs( true ); // actifs + rôle entraineur uniquement (hors bureau seul)
        $dispos   = $this->db->get_dispos_for_month( $year, $month );

        // Formater la liste des entraîneurs
        $trainers_out = array();
        foreach ( $trainers as $t ) {
            $trainers_out[] = array(
                'id'         => intval( $t->id ),
                'nom'        => $t->nom,
                'nom_public' => $t->nom_public ?: $t->nom,
            );
        }

        wp_send_json_success( array(
            'trainers' => $trainers_out,
            'dispos'   => $dispos,
        ) );
    }

    /**
     * Version publique (non connecté) : retourne uniquement les entraîneurs sans les dispos détaillées.
     * Permet au JS de s'initialiser sans bloquer le rendu pour les visiteurs.
     */
    public function get_trainer_dispos_public() {
        $this->nonce();
        $trainers = $this->db->get_trainers_entraineurs( true ); // actifs + rôle entraineur uniquement (hors bureau seul)
        $trainers_out = array();
        foreach ( $trainers as $t ) {
            $trainers_out[] = array(
                'id'         => intval( $t->id ),
                'nom'        => $t->nom,
                'nom_public' => $t->nom_public ?: $t->nom,
            );
        }
        wp_send_json_success( array(
            'trainers' => $trainers_out,
            'dispos'   => array(),
        ) );
    }


    /**
     * Sauvegarde la disponibilité d'un entraîneur pour une date.
     * POST : trainer_id, date, disponible (1/0/null=effacer), note
     */
    public function save_trainer_dispo() {
        $this->nonce();
        $this->require_admin();

        $trainer_id    = intval( $_POST['trainer_id'] ?? 0 );
        $date          = sanitize_text_field( $_POST['date'] ?? '' );
        $dispo_raw     = $_POST['disponible'] ?? '';
        $note          = sanitize_text_field( $_POST['note'] ?? '' );
        $remplacant_id = intval( $_POST['remplacant_id'] ?? 0 ) ?: null;

        if ( ! $trainer_id || ! $date ) wp_send_json_error( 'Paramètres manquants.' );

        $disponible = ( $dispo_raw === '' ) ? null : intval( $dispo_raw );

        $this->db->save_dispo( $trainer_id, $date, $disponible, $note, $remplacant_id );

        wp_send_json_success( array(
            'trainer_id'    => $trainer_id,
            'date'          => $date,
            'disponible'    => $disponible,
            'note'          => $note,
            'remplacant_id' => $remplacant_id,
        ) );
    }

    /**
     * Enregistre uniquement le remplaçant pour une dispo existante.
     * POST : trainer_id, date, remplacant_id (0 = effacer)
     */
    public function save_dispo_remplacant() {
        $this->nonce();
        $this->require_admin();

        $trainer_id    = intval( $_POST['trainer_id']    ?? 0 );
        $date          = sanitize_text_field( $_POST['date'] ?? '' );
        $remplacant_id = intval( $_POST['remplacant_id'] ?? 0 ) ?: null;

        if ( ! $trainer_id || ! $date ) wp_send_json_error( 'Paramètres manquants.' );

        global $wpdb;
        $tdispos = $this->db->table_trainer_dispos();

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $tdispos WHERE trainer_id=%d AND date=%s", $trainer_id, $date
        ) );

        if ( ! $exists ) {
            wp_send_json_error( 'Aucune dispo existante pour ce trainer/date.' );
        }

        $wpdb->update(
            $tdispos,
            array( 'remplacant_id' => $remplacant_id ),
            array( 'id' => intval($exists) )
        );

        wp_send_json_success( array(
            'trainer_id'    => $trainer_id,
            'date'          => $date,
            'remplacant_id' => $remplacant_id,
        ) );
    }

    /**
     * Envoie immédiatement la notification bureau avec les changements
     * passés depuis le JS au moment du clic sur "Envoyer au bureau".
     * POST : date, changements (JSON array)
     */
    public function notify_bureau_dispos() {
        $this->nonce();
        $this->require_admin();

        $date       = sanitize_text_field( $_POST['date'] ?? '' );
        $raw        = wp_unslash( $_POST['changements'] ?? '[]' );
        $changements = json_decode( $raw, true );

        if ( ! $date || empty($changements) ) wp_send_json_error( 'Données manquantes.' );

        // Sanitiser les données reçues
        $clean = array();
        foreach ( $changements as $chg ) {
            $clean[] = array(
                'trainer_nom'   => sanitize_text_field( $chg['trainer_nom']   ?? '' ),
                'trainer_email' => sanitize_email(      $chg['trainer_email'] ?? '' ),
                'avant'         => isset($chg['avant'])  ? intval($chg['avant'])  : null,
                'apres'         => isset($chg['apres'])  ? intval($chg['apres'])  : null,
                'note'          => sanitize_text_field( $chg['note'] ?? '' ),
            );
        }

        $notif = new SpCalPro_Notifications( $this->db );
        $sent  = $notif->notify_bureau_dispo_changes_grouped( $date, $clean );

        wp_send_json_success( array(
            'sent' => $sent,
            'msg'  => $sent > 0 ? '✅ Notification envoyée au bureau.' : '⚠️ Aucun membre bureau avec email configuré.',
        ) );
    }

    /* ══════════════════════════════════════════════════════════
       COMPÉTITIONS
    ══════════════════════════════════════════════════════════ */

    /**
     * Lie un élève à une compétition depuis la fiche élève.
     * POST : event_id, eleve_id
     */
    public function link_comp() {
        $this->nonce();
        $this->require_admin();
        $event_id = intval( $_POST['event_id'] ?? 0 );
        $eleve_id = intval( $_POST['eleve_id'] ?? 0 );
        if ( ! $event_id || ! $eleve_id ) wp_send_json_error( 'Paramètres manquants.' );
        $ok = $this->db->link_comp( $event_id, $eleve_id );
        if ( ! $ok ) wp_send_json_error( 'Compétition introuvable ou non valide.' );
        wp_send_json_success( array( 'event_id' => $event_id ) );
    }

    /**
     * Délie un élève d'une compétition depuis la fiche élève.
     * Supprime aussi ses résultats pour cette compétition.
     * POST : event_id, eleve_id
     */
    public function unlink_comp() {
        $this->nonce();
        $this->require_admin();
        $event_id = intval( $_POST['event_id'] ?? 0 );
        $eleve_id = intval( $_POST['eleve_id'] ?? 0 );
        if ( ! $event_id || ! $eleve_id ) wp_send_json_error( 'Paramètres manquants.' );
        $this->db->unlink_comp( $event_id, $eleve_id );
        wp_send_json_success( 'Délié.' );
    }

    /**
     * Charge les épreuves + résultats + liste élèves pour une compétition.
     */
    public function get_comp() {
        $this->nonce();
        $this->require_admin();
        $event_id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) wp_send_json_error( 'event_id manquant.' );

        $epreuves  = $this->db->get_comp_epreuves( $event_id );
        $resultats = $this->db->get_comp_resultats_by_event( $event_id );
        $eleves    = $this->db->get_eleves();

        // Formater élèves (id, nom_complet, categorie_age, categorie_saisie)
        $eleves_out = array();
        foreach ( $eleves as $el ) {
            $eleves_out[] = array(
                'id'               => intval($el->id),
                'nom'              => $el->nom . ' ' . $el->prenom,
                'categorie_age'    => $el->categorie_age,
                'categorie_saisie' => $el->categorie_saisie,
            );
        }

        // Formater épreuves avec leurs résultats
        $epreuves_out = array();
        foreach ( $epreuves as $ep ) {
            $epid  = intval($ep->id);
            $epreuves_out[] = array(
                'id'        => $epid,
                'nom'       => $ep->nom,
                'modalite'  => $ep->modalite ?? '',
                'ordre'     => intval($ep->ordre),
                'resultats' => $resultats[$epid] ?? array(),
            );
        }

        wp_send_json_success( array(
            'epreuves' => $epreuves_out,
            'eleves'   => $eleves_out,
        ) );
    }

    /**
     * Sauvegarde la liste des épreuves (avant la compétition).
     * POST : event_id, epreuves (JSON array ['id','nom','modalite','ordre'])
     */
    public function save_comp_epreuves() {
        $this->nonce();
        $this->require_admin();
        $event_id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) wp_send_json_error( 'event_id manquant.' );

        $epreuves = json_decode( wp_unslash( $_POST['epreuves'] ?? '[]' ), true );
        if ( ! is_array($epreuves) ) wp_send_json_error( 'Format invalide.' );
        if ( count($epreuves) > 6 ) wp_send_json_error( 'Maximum 6 épreuves par compétition.' );

        $ids = $this->db->save_comp_epreuves( $event_id, $epreuves );
        wp_send_json_success( array( 'saved' => count($ids), 'ids' => $ids ) );
    }

    /**
     * Sauvegarde les résultats (après la compétition).
     * POST : event_id, resultats (JSON array)
     */
    public function save_comp_resultats() {
        $this->nonce();
        $this->require_admin();
        $event_id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) wp_send_json_error( 'event_id manquant.' );

        $resultats = json_decode( wp_unslash( $_POST['resultats'] ?? '[]' ), true );
        if ( ! is_array($resultats) ) wp_send_json_error( 'Format invalide.' );

        $this->db->save_comp_resultats( $resultats );

        // Mettre à jour le palmarès (champ palmares) sur les élèves médaillés
        global $wpdb;
        $tel = $this->db->table_eleves();
        foreach ( $resultats as $r ) {
            if ( empty($r['medaille']) ) continue;
            $eleve_id = intval($r['eleve_id'] ?? 0);
            if ( ! $eleve_id ) continue;
            // Récupérer l'event pour le titre
            $te    = $this->db->table_events();
            $event = $wpdb->get_row( $wpdb->prepare( "SELECT titre, date FROM $te WHERE id=%d", $event_id ) );
            if ( ! $event ) continue;
            // Ajouter au champ palmares texte (résumé lisible)
            $el = $wpdb->get_row( $wpdb->prepare( "SELECT palmares FROM $tel WHERE id=%d", $eleve_id ) );
            if ( ! $el ) continue;
            $medal_label = array('or'=>'🥇','argent'=>'🥈','bronze'=>'🥉')[$r['medaille']] ?? '';
            $line = $medal_label . ' ' . $event->titre . ' — ' . ($r['epreuve_nom'] ?? '') . ' (' . date('Y', strtotime($event->date)) . ')';
            $existing = $el->palmares ?: '';
            // Ne pas dupliquer
            if ( strpos($existing, $event->titre) === false ) {
                $new_palmares = $existing ? $existing . "\n" . $line : $line;
                $wpdb->update( $tel, array('palmares'=>$new_palmares), array('id'=>$eleve_id) );
            }
        }

        wp_send_json_success( array(
            'saved'        => count($resultats),
            'redirect_url' => admin_url( 'admin.php?page=sp-cal-palmares&vue=competition&event_id=' . $event_id ),
        ) );
    }

    /* ══════════════════════════════════════════════════════════
       UPLOAD DOCUMENT ÉVÉNEMENT
    ══════════════════════════════════════════════════════════ */

    /**
     * Upload d'un document joint à un événement ponctuel.
     * Retourne document_url et document_nom.
     */
    public function upload_event_doc() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        $this->require_admin();

        $event_id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) wp_send_json_error( 'event_id manquant.' );

        if ( empty( $_FILES['document']['tmp_name'] ) )
            wp_send_json_error( 'Aucun fichier reçu.' );

        // Types autorisés : PDF, images, Word
        $allowed_types = array(
            'application/pdf',
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload( $_FILES['document'], array(
            'test_form' => false,
            'mimes'     => array(
                'pdf'  => 'application/pdf',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'doc'  => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ),
        ) );

        if ( isset($upload['error']) )
            wp_send_json_error( $upload['error'] );

        global $wpdb;
        $te  = $this->db->table_events();
        $nom = sanitize_file_name( $_FILES['document']['name'] );

        // Supprimer l'ancien fichier si présent
        $old = $wpdb->get_row( $wpdb->prepare( "SELECT document_url FROM $te WHERE id=%d", $event_id ) );
        if ( $old && $old->document_url ) {
            $old_path = str_replace( wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $old->document_url );
            if ( file_exists($old_path) ) @unlink($old_path);
        }

        $wpdb->update( $te,
            array( 'document_url' => $upload['url'], 'document_nom' => $nom ),
            array( 'id' => $event_id )
        );

        wp_send_json_success( array( 'url' => $upload['url'], 'nom' => $nom ) );
    }

    public function delete_event_doc() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        $this->require_admin();

        $event_id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) wp_send_json_error( 'event_id manquant.' );

        global $wpdb;
        $te  = $this->db->table_events();
        $old = $wpdb->get_row( $wpdb->prepare( "SELECT document_url FROM $te WHERE id=%d", $event_id ) );
        if ( $old && $old->document_url ) {
            $path = str_replace( wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $old->document_url );
            if ( file_exists($path) ) @unlink($path);
        }
        $wpdb->update( $te, array( 'document_url' => null, 'document_nom' => null ), array( 'id' => $event_id ) );
        wp_send_json_success();
    }
    /* ══════════════════════════════════════════════════════════
       INSCRIPTIONS AUX ÉVÉNEMENTS
    ══════════════════════════════════════════════════════════ */

    /**
     * Envoie les invitations pour un événement (admin).
     * POST : event_id, nonce
     */
    public function inscription_envoyer() {
        $this->nonce();
        $this->require_admin();

        $event_id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) wp_send_json_error( 'event_id manquant.' );

        global $wpdb;
        $te    = $this->db->table_events();
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $te WHERE id=%d", $event_id ) );
        if ( ! $event )                       wp_send_json_error( 'Événement introuvable.' );
        if ( ! $event->inscriptions_actives ) wp_send_json_error( 'Inscriptions non activées pour cet événement.' );

        $eleves = $this->db->get_eleves_pour_inscription( $event );
        if ( empty( $eleves ) ) wp_send_json_error( 'Aucun élève éligible trouvé.' );

        $notif = new SpCalPro_Notifications( $this->db );
        $sent  = $notif->send_invitation_inscription( $event, $eleves );

        $wpdb->update( $te, array( 'inscriptions_envoye' => 1 ), array( 'id' => $event_id ) );

        wp_send_json_success( array( 'sent' => $sent, 'total' => count( $eleves ) ) );
    }

    /**
     * Retourne la liste des inscriptions pour un événement (admin).
     * POST : event_id, nonce
     */
    public function inscription_liste() {
        $this->nonce();
        $this->require_admin();

        $event_id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) wp_send_json_error( 'event_id manquant.' );

        wp_send_json_success( $this->db->get_inscriptions_event( $event_id ) );
    }

    /**
     * Enregistre la réponse d'un élève (oui/non).
     * POST : token, event_id, reponse (oui|non), commentaire (optionnel)
     * Accessible sans login (nopriv).
     */
    public function inscription_repondre() {
        $token       = sanitize_text_field( wp_unslash( $_POST['token']       ?? '' ) );
        $event_id    = intval(                           $_POST['event_id']    ?? 0   );
        $reponse     = sanitize_text_field( wp_unslash( $_POST['reponse']      ?? '' ) );
        $commentaire = sanitize_textarea_field( wp_unslash( $_POST['commentaire'] ?? '' ) );

        if ( ! $token || ! $event_id || ! in_array( $reponse, array( 'oui', 'non' ), true ) ) {
            wp_send_json_error( 'Données invalides.' );
        }

        global $wpdb;
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $tel WHERE token=%s AND actif=1 LIMIT 1", $token
        ) );
        if ( ! $el ) wp_send_json_error( 'Élève introuvable.' );

        $statut = ( $reponse === 'oui' ) ? 'inscrit' : 'refuse';
        $saved  = $this->db->save_inscription( $event_id, intval( $el->id ), $statut, $commentaire );
        if ( ! $saved ) wp_send_json_error( 'Erreur enregistrement.' );

        wp_send_json_success( array(
            'statut' => $statut,
            'nom'    => $el->prenom . ' ' . mb_strtoupper( $el->nom ),
        ) );
    }

    /**
     * Actions groupées sur les inscriptions (admin uniquement).
     * POST : event_id, nonce, bulk_action, eleve_ids[]
     * bulk_action : renvoi | inscrit | refuse | en_attente
     */
    public function inscription_bulk() {
        $this->nonce();
        $this->require_admin();

        $event_id    = intval( $_POST['event_id']    ?? 0 );
        $bulk_action = sanitize_text_field( wp_unslash( $_POST['bulk_action'] ?? '' ) );
        $eleve_ids   = array_map( 'intval', $_POST['eleve_ids'] ?? array() );

        if ( ! $event_id || ! $bulk_action || empty( $eleve_ids ) ) {
            wp_send_json_error( 'Données manquantes.' );
        }

        $actions_ok = array( 'renvoi', 'inscrit', 'refuse', 'en_attente' );
        if ( ! in_array( $bulk_action, $actions_ok, true ) ) {
            wp_send_json_error( 'Action non reconnue.' );
        }

        global $wpdb;
        $te    = $this->db->table_events();
        $tel   = $this->db->table_eleves();
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $te WHERE id=%d", $event_id ) );
        if ( ! $event ) wp_send_json_error( 'Événement introuvable.' );

        $count = 0;

        if ( $bulk_action === 'renvoi' ) {
            // Renvoyer l'invitation individuellement à chaque élève coché
            $notif = new SpCalPro_Notifications( $this->db );
            foreach ( $eleve_ids as $eleve_id ) {
                $eleve = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM $tel WHERE id=%d AND actif=1", $eleve_id
                ) );
                if ( ! $eleve ) continue;
                $dest = $eleve->email ?: $eleve->email_parent;
                if ( ! $dest || ! is_email($dest) ) continue;
                $sent = $notif->send_invitation_inscription( $event, array( $eleve ) );
                if ( $sent > 0 ) $count++;
            }
            wp_send_json_success( array(
                'message' => $count . ' invitation(s) renvoyée(s).',
                'count'   => $count,
            ) );
        } else {
            // Changer le statut de chaque élève coché
            foreach ( $eleve_ids as $eleve_id ) {
                $saved = $this->db->save_inscription( $event_id, $eleve_id, $bulk_action, '' );
                if ( $saved ) $count++;
            }
            $labels = array(
                'inscrit'    => 'inscrit(e)',
                'refuse'     => 'décliné',
                'en_attente' => 'remis en attente',
            );
            wp_send_json_success( array(
                'message' => $count . ' élève(s) marqué(s) ' . ( $labels[$bulk_action] ?? $bulk_action ) . '.',
                'count'   => $count,
            ) );
        }
    }

    /* ══════════════════════════════════════════════════════════
       SONDAGE POST-ÉVÉNEMENT
    ══════════════════════════════════════════════════════════ */

    public function ajax_load_sondage(): void {
        $this->nonce();
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Non autorisé' );
        }
        $event_id = isset( $_POST['event_id'] ) ? (int) wp_unslash( $_POST['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_send_json_error( 'event_id manquant' );
        }
        global $wpdb;
        $sondage   = $this->db->get_or_create_sondage( $event_id );
        $questions = $this->db->get_sondage_questions( (int) $sondage['id'] );
        $resultats = (bool) $sondage['envoye']
            ? $this->db->get_sondage_resultats( (int) $sondage['id'] )
            : [];

        // Infos inscriptions pour pré-cocher les destinataires
        $event = $wpdb->get_row( $wpdb->prepare(
            "SELECT inscriptions_actives FROM {$wpdb->prefix}sp_cal_events WHERE id = %d",
            $event_id
        ) );
        $inscriptions_actives = $event ? intval( $event->inscriptions_actives ) : 0;

        // Map eleve_id => reponse si inscriptions actives
        $insc_map = [];
        if ( $inscriptions_actives ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT eleve_id, reponse FROM {$wpdb->prefix}sp_cal_event_inscriptions WHERE event_id = %d",
                $event_id
            ) );
            foreach ( $rows as $r ) {
                $insc_map[ intval( $r->eleve_id ) ] = $r->reponse;
            }
        }

        wp_send_json_success( [
            'sondage_id'           => (int) $sondage['id'],
            'envoye'               => (bool) $sondage['envoye'],
            'questions'            => $questions,
            'resultats'            => $resultats,
            'inscriptions_actives' => $inscriptions_actives,
            'insc_map'             => $insc_map,
        ] );
    }

    public function ajax_save_sondage_questions(): void {
        $this->nonce();
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Non autorisé' );
        }
        $sondage_id = isset( $_POST['sondage_id'] ) ? (int) wp_unslash( $_POST['sondage_id'] ) : 0;
        $raw        = isset( $_POST['questions'] )  ? wp_unslash( $_POST['questions'] )         : '[]';
        $questions  = json_decode( $raw, true );
        if ( ! $sondage_id || ! is_array( $questions ) ) {
            wp_send_json_error( 'Données invalides' );
        }
        $ok = $this->db->save_sondage_questions( $sondage_id, $questions );
        if ( $ok ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( 'Sondage déjà envoyé ou introuvable' );
        }
    }

    public function ajax_envoyer_sondage(): void {
        $this->nonce();
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Non autorisé' );
        }
        $event_id  = isset( $_POST['event_id'] )   ? (int) wp_unslash( $_POST['event_id'] )   : 0;
        $raw_ids   = isset( $_POST['eleve_ids'] )  ? wp_unslash( $_POST['eleve_ids'] )         : '[]';
        $eleve_ids = json_decode( $raw_ids, true );

        if ( ! $event_id ) {
            wp_send_json_error( 'event_id manquant' );
        }
        if ( ! is_array( $eleve_ids ) ) {
            $eleve_ids = [];
        }

        $notif  = new SpCalPro_Notifications( $this->db );
        $result = $notif->send_sondage_event( $event_id, $eleve_ids );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( [ 'sent' => $result ] );
    }


    /* ══════════════════════════════════════════════════════════
       ENVOI RÉCAP ANNULATIONS GROUPÉES
    ══════════════════════════════════════════════════════════ */

    public function send_annul_groupee() {
        $this->nonce();
        $this->require_admin();

        $pending = get_option( 'sp_cal_annul_pending', array() );
        if ( empty( $pending ) ) {
            wp_send_json_success( array( 'sent' => 0, 'message' => 'Aucune annulation en attente.' ) );
        }

        $notif = new SpCalPro_Notifications( $this->db );
        $sent  = $notif->send_annulation_cours( $pending );

        // Vider la file
        delete_option( 'sp_cal_annul_pending' );

        wp_send_json_success( array( 'sent' => $sent ) );
    }

    /* ══════════════════════════════════════════════════════════
       PING — Santé du plugin
       Accessible via : admin-ajax.php?action=sp_cal_ping
       Retourne : version plugin, PHP, WP, état DB
    ══════════════════════════════════════════════════════════ */

    public function ping() {
        // Ping lecture seule — pas de nonce requis, capacité admin suffisante
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Accès refusé', 403 );
        }

        global $wpdb;

        // Test DB : une requête simple sur une table du plugin
        $db_ok = false;
        try {
            $result = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->db->table_events()}" );
            $db_ok  = ( $wpdb->last_error === '' );
        } catch ( Exception $e ) {
            $db_ok = false;
        }

        wp_send_json_success( array(
            'plugin_version' => defined( 'SP_CAL_PRO_VERSION' ) ? SP_CAL_PRO_VERSION : 'inconnue',
            'php_version'    => PHP_VERSION,
            'wp_version'     => get_bloginfo( 'version' ),
            'db_status'      => $db_ok ? 'ok' : 'erreur — ' . $wpdb->last_error,
            'timestamp'      => current_time( 'mysql' ),
            'prefixe_tables' => $wpdb->prefix . 'sp_cal_*',
        ) );
    }

} // class SpCalPro_Ajax

endif; // class_exists SpCalPro_Ajax

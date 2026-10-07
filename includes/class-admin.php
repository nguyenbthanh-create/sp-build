<?php 
if ( ! defined( 'ABSPATH' ) ) exit;
require_once plugin_dir_path( __FILE__ ) . 'class-schema.php';
require_once plugin_dir_path( __FILE__ ) . 'class-admin-inscriptions.php';
require_once plugin_dir_path( __FILE__ ) . 'class-admin-exam.php';
require_once plugin_dir_path( __FILE__ ) . 'class-admin-members.php';
require_once plugin_dir_path( __FILE__ ) . 'class-front-adhesion.php';
require_once plugin_dir_path( __FILE__ ) . 'class-admin-adhesions.php';
require_once plugin_dir_path( __FILE__ ) . 'class-renouvellement.php';
require_once plugin_dir_path( __FILE__ ) . 'class-roles.php';
require_once plugin_dir_path( __FILE__ ) . 'class-trainer-app.php';
require_once plugin_dir_path( __FILE__ ) . 'class-dobok.php';
require_once plugin_dir_path( __FILE__ ) . 'class-anniversaires.php';
require_once plugin_dir_path( __FILE__ ) . 'class-passages.php';
require_once plugin_dir_path( __FILE__ ) . 'class-ik-cloture.php';
require_once plugin_dir_path( __FILE__ ) . 'class-pin-garde.php';
require_once plugin_dir_path( __FILE__ ) . 'class-mail-queue.php';
require_once plugin_dir_path( __FILE__ ) . 'class-sondage-front.php';


if ( ! function_exists( 'ordinal_fr' ) ) {
    /** Retourne "1er", "2", "3", … pour l'affichage du jour d'envoi. */
    function ordinal_fr( $n ) {
        return $n === 1 ? '1<sup>er</sup>' : intval( $n );
    }
}

require_once plugin_dir_path( __FILE__ ) . 'trait-admin-reglages.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-admin-equipe.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-admin-sondages.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-admin-formulaires.php';
if ( ! class_exists( 'SpCalPro_Admin' ) ) :

class SpCalPro_Admin {
    use SpCalPro_Admin_Formulaires; // trait-admin-formulaires.php

    use SpCalPro_Admin_Sondages; // trait-admin-sondages.php

    use SpCalPro_Admin_Equipe; // trait-admin-equipe.php

    use SpCalPro_Admin_Reglages; // trait-admin-reglages.php


    private $db;
    private $exam;
    private $members;
    private $inscriptions;
    private $token;

    public function __construct( SpCalPro_DB $db, $token = null ) {
        $this->db    = $db;
        $this->token = $token;
        add_action( 'admin_menu',            array( $this, 'add_menu' ) );
        add_action( 'admin_init',            array( $this, 'handle_requests' ) );
        add_action( 'wp_ajax_nopriv_sp_pointage_scan',   array( $this, 'ajax_pointage_scan' ) );
        add_action( 'wp_ajax_sp_pointage_scan',          array( $this, 'ajax_pointage_scan' ) );
        add_action( 'wp_ajax_nopriv_sp_pointage_cours',  array( $this, 'ajax_pointage_cours' ) );
        add_action( 'wp_ajax_sp_pointage_cours',         array( $this, 'ajax_pointage_cours' ) );
        add_action( 'wp_ajax_nopriv_sp_pointage_lot',    array( $this, 'ajax_pointage_lot' ) );
        add_action( 'wp_ajax_sp_pointage_lot',           array( $this, 'ajax_pointage_lot' ) );
        add_action( 'wp_ajax_nopriv_sp_pointage_cours_eleve', array( $this, 'ajax_pointage_cours_eleve' ) );
        add_action( 'wp_ajax_sp_pointage_cours_eleve',        array( $this, 'ajax_pointage_cours_eleve' ) );
        // Tables : vérifiées par le module central (class-schema.php), une fois après chaque déploiement.
        SP_Cal_Schema::enregistrer( 'Base principale (class-db)', array( $this->db, 'maybe_upgrade' ) );
        add_action( 'admin_init',            array( $this, 'handle_dates_grades_actions' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        // REST API publique pour le pointage (bypass blocage /wp-admin/)
        add_action( 'rest_api_init', array( $this, 'register_pointage_rest' ) );
        // PWA Phase 1 — API REST élèves
        add_action( 'rest_api_init', array( $this, 'register_pwa_rest' ) );
        // PWA Phase 2 — Assets + shortcode
        add_shortcode( 'sp_cal_app', array( $this, 'render_pwa_app' ) );
        add_action( 'template_redirect', array( $this, 'maybe_serve_pwa_assets' ) );
        add_action( 'wp_head', array( $this, 'pwa_head_tags' ) );
        // PWA Phase 3 — Table push subscriptions
        SP_Cal_Schema::enregistrer( 'Notifications push', array( $this, 'maybe_create_push_table' ) );
        // Envoi lien app entraîneur
        // Inscriptions événements
        // Catégories saisie pour la modale

        // Module inscriptions
        $this->inscriptions = new SP_Cal_Inscriptions( $this->db );

        // Module dates des grades (import CSV) + synchronisation des vacances scolaires
        $this->exam = new SP_Cal_Exam( $this->db );

        // Module membres (élèves, palmares, cartes, fiche, licences, stats)
        $this->members = new SP_Cal_Members( $this->db, $this->token );

		SP_Front_Adhesion::get_instance();
        SP_Admin_Adhesions::get_instance();
        SP_Cal_Renouvellement::get_instance( $this->db );
        SP_Cal_Roles::get_instance();
        SP_Cal_Trainer_App::get_instance( $this->db );
        SP_Cal_Dobok::get_instance( $this->db );
        SP_Cal_Anniversaires::get_instance( $this->db );
        SP_Cal_Passages::get_instance( $this->db );
        SP_Cal_IK_Cloture::get_instance( $this->db );
        SP_Cal_Mail_Queue::get_instance();
        SP_Cal_Sondage_Front::get_instance( $this->db );
    }

    /* ══════════════════════════════════════════════════════════
       MENU
    ══════════════════════════════════════════════════════════ */

    public function add_menu() {
        add_menu_page(
            'SP Calendar PRO', 'SP Calendar', SP_Cal_Roles::CAP_VOIR_PLANNING,
            'sp-cal-pro', array( $this, 'page_main' ),
            'dashicons-calendar-alt', 30
        );
        add_submenu_page( 'sp-cal-pro', 'Entraîneurs & Bureau', 'Entraîneurs & Bureau', 'manage_options', 'sp-cal-trainers', array( $this, 'page_trainers' ) );
        add_submenu_page( 'sp-cal-pro', 'Créneaux',       'Créneaux',       'manage_options', 'sp-cal-slots',       array( $this, 'page_slots' ) );
        add_submenu_page( 'sp-cal-pro', 'Adhérents','Adhérents',SP_Cal_Roles::CAP_GESTION_ADHESIONS, 'sp-cal-eleves',      array( $this, 'page_eleves' ) );
        add_submenu_page( 'sp-cal-pro', '🪪 Adhésions',   '🪪 Adhésions',   SP_Cal_Roles::CAP_GESTION_ADHESIONS, 'sp-cal-licences',    array( $this, 'page_licences' ) );
        add_submenu_page( 'sp-cal-pro', '🖨️ Cartes membres','🖨️ Cartes membres','manage_options', 'sp-cal-print-cartes', array( $this, 'page_print_cartes' ) );
        add_submenu_page( 'sp-cal-pro', 'Statistiques',   '📊 Statistiques','manage_options', 'sp-cal-stats',       array( $this, 'page_stats' ) );
        add_submenu_page( 'sp-cal-pro', 'Palmarès',       '🏆 Palmarès',    'manage_options', 'sp-cal-palmares',    array( $this, 'page_palmares' ) );
        add_submenu_page( 'sp-cal-pro', 'Paramètres',     'Paramètres',     'manage_options', 'sp-cal-settings',    array( $this, 'page_settings' ) );
        add_submenu_page( 'sp-cal-pro', '📅 Dates grades','📅 Dates grades','manage_options', 'sp-cal-dates-grades',array( $this, 'page_dates_grades' ) );
        add_submenu_page( 'sp-cal-pro', '📡 Pointage QR','📡 Pointage QR', 'manage_options', 'sp-cal-pointage',    array( $this, 'page_pointage' ) );
        // Fiche élève : sous-page masquée (pas dans le menu, accessible via URL)
        add_submenu_page( null, 'Fiche élève', 'Fiche élève', SP_Cal_Roles::CAP_GESTION_ADHESIONS, 'sp-cal-fiche-eleve', array( $this, 'page_fiche_eleve' ) );
        // Impression en lot des cartes : accessible via menu Élèves
        // Inscriptions événements : sous-page masquée
        add_submenu_page( 'sp-cal-pro', 'Inscriptions', '📋 Inscriptions', 'manage_options', 'sp-cal-inscriptions', array( $this, 'page_inscriptions' ) );
        add_submenu_page( null, 'Sondages', 'Sondages', 'manage_options', 'sp-cal-sondages', array( $this, 'page_sondages' ) );
    }

    /* ══════════════════════════════════════════════════════════
       ASSETS
    ══════════════════════════════════════════════════════════ */

public function enqueue( $hook ) {
    if ( strpos( $hook, 'sp-cal' ) === false ) return;
    wp_enqueue_style( 'dashicons' );
    wp_enqueue_style(  'sp-cal-admin',    SP_CAL_PRO_URL . 'assets/css/admin.css',  array(), sp_cal_asset_ver( 'assets/css/admin.css' ) );
    wp_enqueue_script( 'sp-cal-admin-js', SP_CAL_PRO_URL . 'assets/js/admin.js',   array( 'jquery' ), sp_cal_asset_ver( 'assets/js/admin.js' ), true );
    wp_localize_script( 'sp-cal-admin-js', 'SpCalAdmin', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'sp_cal_admin_nonce' ),
    ) );
    if ( strpos( $hook, 'sp-cal-trainers' ) !== false || strpos( $hook, 'sp-cal-eleves' ) !== false || strpos( $hook, 'sp-cal-fiche-eleve' ) !== false ) {
        wp_enqueue_media();
    }
}

    /* ══════════════════════════════════════════════════════════
       HANDLE POST / GET REQUESTS
    ══════════════════════════════════════════════════════════ */

    // Enregistre les routes REST de pointage (accessibles sans login)
    public function register_pointage_rest() {
        $routes = array( 'cours', 'scan', 'cours_eleve', 'lot' );
        foreach ( $routes as $r ) {
            register_rest_route( 'spcal/v1', '/pointage/' . $r, array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_pointage_' . $r ),
                'permission_callback' => '__return_true',
            ) );
        }
    }
    private function rest_params_to_post( WP_REST_Request $req ) {
        foreach ( $req->get_params() as $k => $v ) { $_POST[ $k ] = $v; }
        // Support JSON body
        $json = $req->get_json_params();
        if ( $json ) { foreach ( $json as $k => $v ) { $_POST[ $k ] = $v; } }
    }
    public function rest_pointage_cours( WP_REST_Request $req ) {
        $this->rest_params_to_post( $req ); $this->ajax_pointage_cours(); exit;
    }
    public function rest_pointage_scan( WP_REST_Request $req ) {
        $this->rest_params_to_post( $req ); $this->ajax_pointage_scan(); exit;
    }
    public function rest_pointage_cours_eleve( WP_REST_Request $req ) {
        $this->rest_params_to_post( $req ); $this->ajax_pointage_cours_eleve(); exit;
    }
    public function rest_pointage_lot( WP_REST_Request $req ) {
        $this->rest_params_to_post( $req ); $this->ajax_pointage_lot(); exit;
    }

    /* ══════════════════════════════════════════════════════════════════════
       PWA — PHASE 1 : API REST
       POST /wp-json/spcal/v1/auth
       GET  /wp-json/spcal/v1/eleve/me
       GET  /wp-json/spcal/v1/calendrier
    ══════════════════════════════════════════════════════════════════════ */

    public function register_pwa_rest() {
        register_rest_route( 'spcal/v1', '/auth', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_pwa_auth' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'spcal/v1', '/eleve/evenements', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_pwa_eleve_evenements' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'spcal/v1', '/eleve/me', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_pwa_eleve_me' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'spcal/v1', '/calendrier', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_pwa_calendrier' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'spcal/v1', '/calendrier/club', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_pwa_calendrier_club' ),
            'permission_callback' => '__return_true',
        ) );
        // Push subscriptions
        register_rest_route( 'spcal/v1', '/push/vapid-public', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_pwa_vapid_public' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'spcal/v1', '/push/subscribe', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_pwa_push_subscribe' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'spcal/v1', '/push/unsubscribe', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'rest_pwa_push_unsubscribe' ),
            'permission_callback' => '__return_true',
        ) );
    }

    private function jwt_secret() : string {
        return defined( 'SP_CAL_JWT_SECRET' ) ? SP_CAL_JWT_SECRET : wp_salt( 'auth' );
    }
    private function jwt_encode( array $payload ) : string {
        $h = $this->b64u( json_encode( array( 'typ' => 'JWT', 'alg' => 'HS256' ) ) );
        $p = $this->b64u( json_encode( $payload ) );
        $s = $this->b64u( hash_hmac( 'sha256', "$h.$p", $this->jwt_secret(), true ) );
        return "$h.$p.$s";
    }
    private function jwt_decode( string $token ) {
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 3 ) return false;
        $h = $parts[0]; $p = $parts[1]; $sig = $parts[2];
        $expected = $this->b64u( hash_hmac( 'sha256', "$h.$p", $this->jwt_secret(), true ) );
        if ( ! hash_equals( $expected, $sig ) ) return false;
        $data = json_decode( $this->b64u_decode( $p ), true );
        if ( ! is_array( $data ) || empty( $data['exp'] ) ) return false;
        if ( $data['exp'] < time() ) return false;
        return $data;
    }
    private function b64u( string $data ) : string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
    private function b64u_decode( string $data ) : string {
        $pad = ( 4 - strlen( $data ) % 4 ) % 4;
        return base64_decode( strtr( $data, '-_', '+/' ) . str_repeat( '=', $pad ) );
    }
    private function pwa_rate_limit( string $key, int $max, int $window ) : bool {
        $tk  = 'sp_rl_' . md5( $key );
        $val = get_transient( $tk );
        if ( $val === false ) { set_transient( $tk, 1, $window ); return true; }
        if ( intval( $val ) >= $max ) return false;
        set_transient( $tk, intval( $val ) + 1, $window );
        return true;
    }
    private function pwa_require_auth( WP_REST_Request $req ) {
        $auth = $req->get_header( 'Authorization' ) ?? '';
        $raw_jwt = strncmp( $auth, 'Bearer ', 7 ) === 0
            ? substr( $auth, 7 )
            : sanitize_text_field( wp_unslash( $req->get_param( 'jwt' ) ?? '' ) );
        if ( ! $raw_jwt ) return new WP_Error( 'spcal_no_auth', 'Authentification requise.', array( 'status' => 401 ) );
        $payload = $this->jwt_decode( $raw_jwt );
        if ( ! $payload || empty( $payload['eid'] ) ) return new WP_Error( 'spcal_invalid_jwt', 'Session expirée. Reconnectez-vous.', array( 'status' => 401 ) );
        return intval( $payload['eid'] );
    }

    public function rest_pwa_auth( WP_REST_Request $req ) {
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        if ( ! $this->pwa_rate_limit( 'auth_' . $ip, 10, 60 ) )
            return new WP_Error( 'spcal_rate_limit', 'Trop de tentatives. Réessayez dans une minute.', array( 'status' => 429 ) );
        $json      = $req->get_json_params();
        $raw_token = $json['token'] ?? sanitize_text_field( wp_unslash( $req->get_param( 'token' ) ?? '' ) );
        $raw_token = sanitize_text_field( wp_unslash( $raw_token ) );
        if ( ! $raw_token || ! preg_match( '/^[0-9a-f]{64}$/', $raw_token ) )
            return new WP_Error( 'spcal_invalid_token', 'Token invalide.', array( 'status' => 401 ) );
        global $wpdb;
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare( "SELECT id, nom, prenom, actif FROM $tel WHERE token = %s LIMIT 1", $raw_token ) );
        if ( ! $el ) return new WP_Error( 'spcal_not_found', 'Token invalide ou compte introuvable.', array( 'status' => 401 ) );
        if ( ! intval( $el->actif ) ) return new WP_Error( 'spcal_inactive', 'Compte inactif. Contactez votre club.', array( 'status' => 403 ) );
        $iat = time(); $exp = $iat + DAY_IN_SECONDS;
        $jwt = $this->jwt_encode( array( 'sub' => $raw_token, 'eid' => intval( $el->id ), 'iat' => $iat, 'exp' => $exp ) );
        return rest_ensure_response( array(
            'success' => true, 'jwt' => $jwt, 'expires_at' => $exp, 'ttl' => DAY_IN_SECONDS,
            'eleve'   => array( 'id' => intval( $el->id ), 'prenom' => $el->prenom, 'nom' => mb_strtoupper( $el->nom ) ),
        ) );
    }

    public function rest_pwa_eleve_me( WP_REST_Request $req ) {
        $eid = $this->pwa_require_auth( $req );
        if ( is_wp_error( $eid ) ) return $eid;
        global $wpdb;
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tel WHERE id = %d AND actif = 1 LIMIT 1", $eid ) );
        if ( ! $el ) return new WP_Error( 'spcal_not_found', 'Profil introuvable.', array( 'status' => 404 ) );
        $presences  = $this->db->get_presences_eleve( intval( $el->id ) );
        $pres_cours = array_values( array_filter( $presences, static function( $p ) {
            return ! in_array( $p->type ?? '', array( 'examen', 'anniversaire' ), true );
        } ) );
        $nb_present = count( array_filter( $pres_cours, static function( $p ) { return intval( $p->present ) === 1; } ) );
        $nb_total   = count( $pres_cours );
        $taux       = $nb_total > 0 ? round( $nb_present / $nb_total * 100 ) : null;
        // Statut d'après la saison de la fiche (SpCalPro_DB::statut_saison_eleve) ; codes de l'API inchangés.
        $statut_saison = SpCalPro_DB::statut_saison_eleve( $el );
        $fin_saison    = SpCalPro_DB::fin_saison_prochaine();
        $jours         = $statut_saison['jours'];
        $adhesion      = array( 'a_renouveler' => 'expire', 'bientot' => 'expire_bientot' )[ $statut_saison['code'] ] ?? 'actif';
        $grade_vise = $this->db->get_grade_vise_eleve( $el );
        return rest_ensure_response( array(
            'id' => intval( $el->id ), 'prenom' => $el->prenom, 'nom' => mb_strtoupper( $el->nom ),
            'grade' => $el->grade ?? '', 'categorie_age' => $el->categorie_age ?? '',
            'categorie_saisie' => $el->categorie_saisie ?? '', 'saison' => $el->saison ?? '',
            'photo_url' => $el->photo_url ?? '', 'licence' => $el->licence ?? '',
            'date_naissance' => $el->date_naissance ?? '', 'annee_naissance' => $el->annee_naissance ?? '',
            'num_passeport' => $el->num_passeport ?? '', 'urgence_telephone' => $el->urgence_telephone ?? '',
            'nb_licences' => intval( $el->nb_licences ?? 0 ),
            'adhesion'   => array( 'statut' => $adhesion, 'jours' => $jours, 'fin' => $fin_saison ),
            'assiduite'  => array( 'present' => $nb_present, 'total' => $nb_total, 'taux' => $taux ),
            'grade_vise' => $grade_vise ?: '',
            // Carte « Mon prochain grade » : programme du Parcours + retour du dernier passage (class-passages.php).
            'parcours'   => SP_Cal_Passages::get_instance()->donnees_eleve( $el ),
            // Passerelle vers la fiche complète (grades, présences, doboks) — unification fiche / PWA, étape 1.
            'fiche_url'  => ( $this->token && ! empty( $el->token ) ) ? $this->token->get_fiche_url( $el->token ) : '',
            'carte' => array(
                'qr_url'        => home_url( '/' ) . '?token=' . rawurlencode( $el->token ?? '' ),
                'club_nom'      => get_option( 'blogname', '' ),
                'club_num'      => get_option( 'sp_cal_club_num', '' ),
                'club_affil'    => get_option( 'sp_cal_club_affiliation', '' ),
                'club_ligue'    => get_option( 'sp_cal_club_ligue', '' ),
                'club_labelise' => intval( get_option( 'sp_cal_club_labelise', 0 ) ),
                'logo_url'      => get_option( 'sp_cal_logo_url', '' ),
            ),
        ) );
    }

    public function rest_pwa_eleve_evenements( WP_REST_Request $req ) {
        $eid = $this->pwa_require_auth( $req );
        if ( is_wp_error( $eid ) ) return $eid;

        // Agenda complet sur 3 mois (hors cours et anniversaires), avec ce qui concerne
        // l'adhérent et ses inscriptions ouvertes — calcul partagé avec la fiche ?token=
        // (SpCalPro_DB::get_agenda_eleve(), décision du 26/09/2026).
        $out = array();
        foreach ( $this->db->get_agenda_eleve( intval( $eid ), 92 ) as $ev ) {
            $out[] = array(
                'id'                    => intval( $ev->id ),
                'titre'                 => $ev->titre                 ?? '',
                'date'                  => $ev->date                  ?? '',
                'heure_debut'           => $ev->heure_debut           ?? '',
                'type'                  => $ev->type                  ?? '',
                'inscriptions_deadline' => $ev->inscriptions_deadline ?? null,
                'inscriptions_message'  => $ev->inscriptions_message  ?? '',
                'statut_insc'           => $ev->statut_insc           ?? 'en_attente',
                'concerne'              => (bool) $ev->concerne,
                'inscription'           => (bool) $ev->inscription,
                // Compatibilité avec l'ancien client : « eligible » = peut répondre.
                'eligible'              => (bool) $ev->inscription,
            );
        }
        return rest_ensure_response( array( 'evenements' => $out ) );
    }

    public function rest_pwa_calendrier_club( WP_REST_Request $req ) {
        $pin = sanitize_text_field( wp_unslash( $req->get_param( 'pin' ) ?? '' ) );
        if ( ! SP_Cal_Pin_Garde::verifier( (string) $pin, SP_Cal_Pin_Garde::pin() ) ) {
            return new WP_Error( 'spcal_invalid_pin', SP_Cal_Pin_Garde::message(), array( 'status' => 401 ) );
        }
        $from_raw = sanitize_text_field( wp_unslash( $req->get_param( 'from' ) ?? date( 'Y-m-d' ) ) );
        $nb       = min( 30, max( 1, intval( $req->get_param( 'nb' ) ?? 20 ) ) );
        $from     = ( preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $from_raw ) && strtotime( $from_raw ) ) ? $from_raw : date( 'Y-m-d' );
        $to       = date( 'Y-m-d', strtotime( $from . ' +90 days' ) );
        $occs     = $this->db->get_slot_occurrences( $from, $to );
        $cours    = array();
        $encadrement_par_date = array();
        foreach ( $occs as $occ ) {
            if ( $occ['annul_id'] ) continue;
            if ( ! isset( $encadrement_par_date[ $occ['date'] ] ) ) {
                $encadrement_par_date[ $occ['date'] ] = $this->encadrement_du_jour( $occ['date'] );
            }
            $cours[] = array(
                'slot_id'     => intval( $occ['slot_id'] ),
                'mat_id'      => $occ['mat_id'] ? intval( $occ['mat_id'] ) : null,
                'date'        => $occ['date'],
                'heure_debut' => $occ['heure_debut'] ?? '',
                'heure_fin'   => $occ['heure_fin']   ?? '',
                'titre'       => $occ['titre']        ?? '',
                'categorie'   => $occ['categorie']    ?? '',
                'encadrement' => $encadrement_par_date[ $occ['date'] ],
            );
            if ( count( $cours ) >= $nb ) break;
        }
        return rest_ensure_response( array( 'from' => $from, 'nb' => count( $cours ), 'cours' => $cours ) );
    }

    /** Encadrement d'une journée : voir SpCalPro_DB::get_encadrement_du_jour() (règle partagée avec le mail au bureau). */
    private function encadrement_du_jour( $date ) {
        return $this->db->get_encadrement_du_jour( $date );
    }

    public function rest_pwa_calendrier( WP_REST_Request $req ) {
        $eid = $this->pwa_require_auth( $req );
        if ( is_wp_error( $eid ) ) return $eid;
        global $wpdb;
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare( "SELECT categorie_saisie, categorie_age FROM $tel WHERE id = %d AND actif = 1 LIMIT 1", $eid ) );
        if ( ! $el ) return new WP_Error( 'spcal_not_found', 'Élève introuvable.', array( 'status' => 404 ) );
        $from_raw = sanitize_text_field( wp_unslash( $req->get_param( 'from' ) ?? date( 'Y-m-d' ) ) );
        $nb       = min( 30, max( 1, intval( $req->get_param( 'nb' ) ?? 20 ) ) );
        $from     = ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from_raw ) && strtotime( $from_raw ) ) ? $from_raw : date( 'Y-m-d' );
        $to       = date( 'Y-m-d', strtotime( $from . ' +90 days' ) );
        $occs     = $this->db->get_slot_occurrences( $from, $to );
        $cat      = trim( (string) ( $el->categorie_saisie ?? '' ) );
        $cat_age  = trim( (string) ( $el->categorie_age ?? '' ) );
        $cours    = array();
        foreach ( $occs as $occ ) {
            if ( $occ['annul_id'] ) continue;
            // Même règle que le mail d'annulation (SpCalPro_DB::creneau_concerne()) : discipline ×
            // tranche d'âge du créneau, ou ancien texte libre tant qu'il n'est pas reclassé.
            // Avant : seule la discipline de l'adhérent comparée au texte libre — un créneau
            // « Enfant » n'apparaissait pas à un adhérent « TKD ».
            if ( ( $cat !== '' || $cat_age !== '' ) && ! $this->db->creneau_concerne( $occ, $cat, $cat_age ) ) continue;
            $cours[] = array(
                'slot_id' => intval( $occ['slot_id'] ), 'mat_id' => $occ['mat_id'] ? intval( $occ['mat_id'] ) : null,
                'date' => $occ['date'], 'heure_debut' => $occ['heure_debut'] ?? '', 'heure_fin' => $occ['heure_fin'] ?? '',
                'titre' => $occ['titre'] ?? '', 'categorie' => $occ['categorie'] ?? '',
            );
            if ( count( $cours ) >= $nb ) break;
        }
        return rest_ensure_response( array( 'from' => $from, 'nb' => count( $cours ), 'cours' => $cours ) );
    }

    /* ══════════════════════════════════════════════════════════════════════
       PWA — PHASE 2 : APPLICATION MOBILE INSTALLABLE
       Shortcode : [sp_cal_app]
       SW        : /?spcal_sw=1
       Manifest  : /?spcal_manifest=1
    ══════════════════════════════════════════════════════════════════════ */

    /** Injecte les balises PWA dans <head> uniquement sur la page du shortcode. */
    public function pwa_head_tags() {
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'sp_cal_app' ) ) return;
        $manifest_url = esc_url( home_url( '/?spcal_manifest=1' ) );
        $logo_url     = esc_url( get_option( 'sp_cal_logo_url', '' ) );
        $club         = esc_attr( get_option( 'blogname', 'TKD' ) );
        echo "<link rel=\"manifest\" href=\"$manifest_url\">\n";
        echo "<meta name=\"theme-color\" content=\"#0f70b7\">\n";
        echo "<meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n";
        echo "<meta name=\"apple-mobile-web-app-status-bar-style\" content=\"black-translucent\">\n";
        echo "<meta name=\"apple-mobile-web-app-title\" content=\"$club\">\n";
        if ( $logo_url ) echo "<link rel=\"apple-touch-icon\" href=\"$logo_url\">\n";
    }

    /** Sert le Service Worker et le manifest via template_redirect. */
    public function maybe_serve_pwa_assets() {
        // ── Service Worker ──────────────────────────────────────
        if ( isset( $_GET['spcal_sw'] ) ) {
            $app_url = esc_url( home_url( '/app/' ) );
            header( 'Content-Type: application/javascript; charset=utf-8' );
            header( 'Cache-Control: no-cache, no-store, must-revalidate' );
            header( 'Service-Worker-Allowed: /' );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $this->get_pwa_sw_code( $app_url );
            exit;
        }
        // ── Manifest ────────────────────────────────────────────
        if ( isset( $_GET['spcal_manifest'] ) ) {
            header( 'Content-Type: application/manifest+json; charset=utf-8' );
            header( 'Cache-Control: no-cache' );
            $icon_url = get_option( 'sp_cal_logo_url', '' );
            $icons    = array();
            if ( $icon_url ) {
                $icons[] = array( 'src' => $icon_url, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable' );
                $icons[] = array( 'src' => $icon_url, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' );
            }
            $manifest = array(
                'name'             => get_option( 'blogname', 'TKD' ),
                'short_name'       => get_option( 'blogname', 'TKD' ),
                'description'      => 'Suivi membre — ' . get_option( 'blogname', '' ),
                'start_url'        => home_url( '/app/?source=pwa' ),
                'scope'            => home_url( '/' ),
                'display'          => 'standalone',
                'background_color' => '#111111',
                'theme_color'      => '#0f70b7',
                'lang'             => 'fr',
                'icons'            => $icons,
            );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            exit;
        }
    }

    /** Code du Service Worker : réseau d'abord pour la seule page de l'application, rien d'autre n'est intercepté. */
    private function get_pwa_sw_code( string $app_url ) : string {
        return <<<'SWJS'
const CACHE_NAME = 'spcal-pwa-v2';
const SHELL_URLS = [self.registration.scope + 'app/'];

/* ── Événements push ─────────────────────────────────────────── */
self.addEventListener('push', function(e) {
    var data = {};
    try { data = e.data ? e.data.json() : {}; } catch(err) {}
    var title = data.title || 'TKD Club';
    var body  = data.body  || 'Nouveau message de votre club';
    var icon  = data.icon  || '/favicon.ico';
    e.waitUntil(
        self.registration.showNotification(title, {
            body: body, icon: icon, badge: icon,
            tag: 'spcal-notif', requireInteraction: false,
        })
    );
});

self.addEventListener('notificationclick', function(e) {
    e.notification.close();
    e.waitUntil( clients.openWindow(self.registration.scope + 'app/') );
});

self.addEventListener('install', function(e) {
    e.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(SHELL_URLS).catch(function(){});
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', function(e) {
    e.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(keys.filter(function(k){ return k !== CACHE_NAME; }).map(function(k){ return caches.delete(k); }));
        }).then(function(){ return clients.claim(); })
    );
});

// Correctif du 27/09/2026 : l'ancienne version interceptait TOUTES les requêtes GET du site
// (scope « / ») en « cache d'abord » — pages, CSS, et même l'admin : tout navigateur ayant
// ouvert l'application une fois revoyait d'anciennes versions (ex. ancien en-tête de la fiche
// adhérent après sa mise à jour). Désormais seule la page de l'application est mise en
// cache, en « réseau d'abord » (cache = secours hors ligne) ; le reste du site n'est jamais
// intercepté. CACHE_NAME changé : l'activation supprime l'ancien cache.
self.addEventListener('fetch', function(e) {
    if (e.request.method !== 'GET' || e.request.mode !== 'navigate') return;
    var url = new URL(e.request.url);
    if (url.origin !== self.location.origin) return;
    var estApp = SHELL_URLS.some(function(s){ return new URL(s).pathname === url.pathname; });
    if (!estApp) return;

    e.respondWith(
        fetch(e.request).then(function(response) {
            if (response.ok) {
                var clone = response.clone();
                caches.open(CACHE_NAME).then(function(cache){ cache.put(SHELL_URLS[0], clone); });
            }
            return response;
        }).catch(function() {
            return caches.match(SHELL_URLS[0]);
        })
    );
});
SWJS;
    }

    /** Shortcode [sp_cal_app] — rendu de la PWA complète. */
    public function render_pwa_app() {
        $api_base  = rest_url( 'spcal/v1' );
        $sw_url    = home_url( '/?spcal_sw=1' );
        $logo_url  = esc_url( get_option( 'sp_cal_logo_url', '' ) );
        $club_nom  = esc_js( get_option( 'blogname', 'TKD' ) );

        // Clé publique VAPID pour le push
        if ( class_exists( 'SpCalPro_WebPush' ) ) {
            $vapid_keys = SpCalPro_WebPush::get_or_create_keys();
            $vapid_pub  = $vapid_keys['public_b64u'];
        } else {
            $vapid_pub = get_option( 'sp_cal_vapid_public', '' );
        }
        $config_js = wp_json_encode( array(
            'apiBase'     => $api_base,
            'swUrl'       => $sw_url,
            'clubNom'     => get_option( 'blogname', 'TKD' ),
            'logoUrl'     => get_option( 'sp_cal_logo_url', '' ),
            'vapidPublic' => $vapid_pub,
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
        ) );

        ob_start();
        ?>
<script>window.SPCAL = <?php echo $config_js; // phpcs:ignore ?>;</script>
<script>
// Diagnostic temporaire (doléances 10/09/2026) : le scan QR reste muet sur iPhone/Firefox
// sans aucune erreur visible, et les devtools ne sont pas facilement accessibles sur iOS --
// ce filet affiche à l'écran toute erreur JS non interceptée ailleurs, pour obtenir la
// vraie cause au prochain test plutôt que deviner une nouvelle fois. À retirer une fois
// la cause confirmée.
window.addEventListener('error', function(e) {
    if (window.__spCalErrShown) return;
    window.__spCalErrShown = true;
    alert('Erreur JS : ' + e.message + '\n' + (e.filename || '') + ':' + (e.lineno || '?'));
});
</script>

<style>
/* ── RESET & BASE PWA ───────────────────────────────────────── */
#spcal-pwa *,#spcal-pwa *::before,#spcal-pwa *::after{box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
#spcal-pwa{position:fixed;inset:0;background:#111;color:#fff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;display:flex;flex-direction:column;z-index:9999;overflow:hidden;}

/* ── LOADING ─────────────────────────────────────────────────── */
#spcal-loading{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:16px;}
#spcal-loading img{width:72px;height:72px;object-fit:contain;border-radius:12px;background:#fff;padding:6px;}
.spcal-spinner{width:36px;height:36px;border:3px solid rgba(255,255,255,.15);border-top-color:#0f70b7;border-radius:50%;animation:spcal-spin .7s linear infinite;}
@keyframes spcal-spin{to{transform:rotate(360deg)}}
#spcal-loading p{color:rgba(255,255,255,.5);font-size:13px;margin:0;}

/* ── ERROR ───────────────────────────────────────────────────── */
#spcal-error{display:none;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:12px;padding:32px;text-align:center;}
#spcal-error .spcal-err-ico{font-size:48px;}
#spcal-error h2{margin:0;font-size:18px;}
#spcal-error p{margin:0;font-size:14px;color:rgba(255,255,255,.5);line-height:1.6;}

/* ── APP SHELL ───────────────────────────────────────────────── */
#spcal-app{display:none;flex-direction:column;height:100%;}

/* ── HEADER ──────────────────────────────────────────────────── */
#spcal-header{flex-shrink:0;background:#111;border-bottom:1px solid rgba(255,255,255,.08);padding:12px 16px;display:flex;align-items:center;gap:12px;padding-top:max(12px, env(safe-area-inset-top));}
#spcal-header-logo{width:36px;height:36px;border-radius:8px;background:#fff;object-fit:contain;padding:3px;flex-shrink:0;}
#spcal-header-logo-placeholder{width:36px;height:36px;border-radius:8px;background:#0f70b7;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;flex-shrink:0;}
#spcal-header-text{flex:1;min-width:0;}
#spcal-header-club{font-size:11px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.8px;}
#spcal-header-name{font-size:15px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
#spcal-grade-pill{background:#0f70b7;color:#fff;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;white-space:nowrap;flex-shrink:0;max-width:120px;overflow:hidden;text-overflow:ellipsis;}

/* ── MAIN CONTENT ────────────────────────────────────────────── */
#spcal-main{flex:1;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;}
.spcal-screen{display:none;padding:16px;}
.spcal-screen.active{display:block;}
/* Onglet « Mes dispos » (lien personnel entraîneur) : page des dispos en ambiance sombre dans un cadre */
#spcal-dispos{padding:0;}
.spcal-saisie-carte{background:#1a1a1a;border-radius:12px;padding:20px 18px;text-align:center;}
.spcal-saisie-ico{font-size:40px;line-height:1;margin-bottom:8px;}
.spcal-saisie-carte p{font-size:14px;color:rgba(255,255,255,.75);margin:0 0 16px;line-height:1.45;}
.spcal-saisie-carte strong{color:#fff;font-size:16px;}
.spcal-saisie-btn{display:block;background:#0f70b7;color:#fff;text-decoration:none;font-weight:700;font-size:16px;padding:14px;border-radius:10px;margin-bottom:14px;}
.spcal-saisie-carte .spcal-saisie-aide{font-size:12px;color:rgba(255,255,255,.5);margin:0;}

/* ── BOTTOM NAV ──────────────────────────────────────────────── */
#spcal-nav{flex-shrink:0;background:#1a1a1a;border-top:1px solid rgba(255,255,255,.08);display:flex;padding-bottom:max(8px,env(safe-area-inset-bottom));}
.spcal-nav-btn{flex:1;background:none;border:none;color:rgba(255,255,255,.4);padding:10px 4px 6px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:3px;font-size:10px;font-weight:500;transition:color .2s;}
.spcal-nav-btn .spcal-nav-ico{font-size:22px;line-height:1;}
.spcal-nav-btn.active{color:#0f70b7;}
.spcal-nav-btn.active .spcal-nav-ico{filter:drop-shadow(0 0 6px #0f70b7);}

/* ── ACCUEIL ─────────────────────────────────────────────────── */
.spcal-section-title{font-size:12px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.8px;margin:20px 0 10px;}
.spcal-section-title:first-child{margin-top:0;}
#spcal-greeting{font-size:22px;font-weight:700;margin-bottom:6px;}
#spcal-greeting-sub{font-size:14px;color:rgba(255,255,255,.5);margin-bottom:20px;}

.spcal-assiduite{background:#1a1a1a;border-radius:12px;padding:14px 16px;margin-bottom:20px;}
.spcal-assiduite-label{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:8px;}
.spcal-assiduite-bar{height:6px;background:rgba(255,255,255,.1);border-radius:3px;overflow:hidden;margin-bottom:6px;}
.spcal-assiduite-fill{height:100%;border-radius:3px;transition:width .8s ease;}
.spcal-assiduite-nums{font-size:13px;color:rgba(255,255,255,.6);}
.spcal-fiche-link{display:flex;align-items:center;gap:12px;background:#1a1a1a;border-radius:12px;padding:14px 16px;margin-bottom:20px;color:#fff;text-decoration:none;}
.spcal-fiche-link .spcal-fl-ico{font-size:22px;}
.spcal-fiche-link small{display:block;color:rgba(255,255,255,.5);font-size:12px;margin-top:2px;}
.spcal-fiche-link .spcal-fl-go{margin-left:auto;font-size:20px;color:rgba(255,255,255,.4);}

.spcal-adhesion-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;margin-bottom:16px;}
.spcal-adhesion-actif{background:rgba(34,197,94,.15);color:#4ade80;}
.spcal-adhesion-warn{background:rgba(245,158,11,.15);color:#fbbf24;}
.spcal-adhesion-ko{background:rgba(239,68,68,.15);color:#f87171;}

/* Cours cards */
.spcal-cours-card{background:#1a1a1a;border-radius:12px;padding:14px 16px;margin-bottom:10px;border-left:3px solid #0f70b7;display:flex;justify-content:space-between;align-items:center;gap:12px;}
.spcal-cours-card.today{border-left-color:#22c55e;}
.spcal-cours-card.tomorrow{border-left-color:#f59e0b;}
.spcal-cours-left{flex:1;min-width:0;}
.spcal-cours-titre{font-size:15px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.spcal-cours-cat{font-size:12px;color:rgba(255,255,255,.4);margin-top:2px;}
.spcal-cours-encadr{font-size:12px;color:rgba(255,255,255,.75);margin-top:4px;}
.spcal-cours-encadr.vide{color:#f59e0b;}
.spcal-cours-right{text-align:right;flex-shrink:0;}
.spcal-cours-heure{font-size:14px;font-weight:600;color:#0f70b7;}
.spcal-cours-card.today .spcal-cours-heure{color:#22c55e;}
.spcal-cours-date{font-size:11px;color:rgba(255,255,255,.7);margin-top:2px;}
.spcal-cours-badge{font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;margin-top:4px;display:inline-block;}
.spcal-badge-today{background:#22c55e;color:#fff;}
.spcal-badge-tomorrow{background:#f59e0b;color:#111;}
.spcal-empty{text-align:center;padding:40px 20px;color:rgba(255,255,255,.3);font-size:14px;}

/* ── ÉVÉNEMENTS & INSCRIPTIONS ───────────────────────────────── */
.spcal-evt-card{background:#1a1a1a;border-radius:12px;padding:14px 16px;margin-bottom:12px;border-left:3px solid #0f70b7;}
.spcal-evt-card.statut-inscrit{border-left-color:#22c55e;}
.spcal-evt-card.statut-refuse{border-left-color:#ef4444;opacity:.7;}
.spcal-evt-titre{font-size:15px;font-weight:600;margin-bottom:4px;}
.spcal-evt-meta{font-size:12px;color:rgba(255,255,255,.45);margin-bottom:10px;}
.spcal-evt-deadline{font-size:11px;color:#f59e0b;margin-bottom:10px;}
.spcal-evt-msg{font-size:12px;color:rgba(255,255,255,.55);margin-bottom:10px;line-height:1.4;}
.spcal-evt-actions{display:flex;gap:8px;flex-wrap:wrap;}
.spcal-evt-btn{border:none;border-radius:8px;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer;flex:1;min-width:120px;}
.spcal-evt-btn-oui{background:#16a34a;color:#fff;}
.spcal-evt-btn-non{background:#1a1a1a;color:#ef4444;border:2px solid #ef4444;}
.spcal-evt-btn-annuler{background:#1a1a1a;color:rgba(255,255,255,.5);border:1px solid rgba(255,255,255,.15);font-size:11px;padding:7px 12px;min-width:auto;flex:0;}
.spcal-evt-badge{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;}
.spcal-evt-badge-ok{background:rgba(34,197,94,.15);color:#4ade80;}
.spcal-evt-badge-ko{background:rgba(239,68,68,.15);color:#f87171;}
.spcal-evt-badge-wait{background:rgba(245,158,11,.15);color:#fbbf24;}
.spcal-evt-badge-info{background:rgba(255,255,255,.08);color:rgba(255,255,255,.4);}
.spcal-evt-card.statut-info{border-left-color:rgba(255,255,255,.15);opacity:.75;}
.spcal-evt-card.statut-moi{border-left-color:#0f70b7;}
.spcal-evt-card.statut-insc{border-left-color:#f59e0b;box-shadow:0 0 0 1px rgba(245,158,11,.35);}
.spcal-evt-tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;}
.spcal-evt-tag{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.spcal-evt-tag-moi{background:rgba(15,112,183,.2);color:#7cc0f5;}
.spcal-evt-tag-insc{background:#f59e0b;color:#111;}
.spcal-evt-filtre{display:flex;gap:6px;margin-bottom:14px;}
.spcal-evt-filtre button{flex:1;background:#1a1a1a;color:rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:8px 10px;font-size:13px;font-weight:600;cursor:pointer;}
.spcal-evt-filtre button.active{background:#0f70b7;border-color:#0f70b7;color:#fff;}
.spcal-evt-loading{font-size:12px;color:rgba(255,255,255,.4);font-style:italic;}

/* ── PROCHAIN GRADE ───────────────────────────────────────────── */
#spcal-grade-progression{margin-top:20px;}
.spcal-grade-section-title{font-size:12px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;}
.spcal-grade-next{font-size:13px;color:rgba(255,255,255,.7);}
.spcal-grade-next strong{color:#0f70b7;}
.spcal-pg-card{background:#1a1a1a;border-radius:12px;padding:14px 16px;margin-bottom:20px;border-left:3px solid #0f70b7;}
.spcal-pg-card.admis{border-left-color:#22c55e;}
.spcal-pg-card.ajourne{border-left-color:#f59e0b;}
.spcal-pg-res{font-size:16px;font-weight:700;margin-bottom:4px;}
.spcal-pg-sub{font-size:12px;color:rgba(255,255,255,.55);margin:6px 0;}
.spcal-pg-belt{display:inline-flex;align-items:center;gap:7px;}
.spcal-pg-belt i{display:inline-block;width:24px;height:10px;border-radius:3px;border:1px solid rgba(255,255,255,.35);}
.spcal-pg-crits{list-style:none;margin:8px 0 0;padding:0;}
.spcal-pg-crits li{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-top:1px solid rgba(255,255,255,.07);font-size:13px;}
.spcal-pg-crits small{display:block;color:rgba(255,255,255,.55);font-size:12px;margin-top:2px;}
.spcal-pg-badge{flex-shrink:0;font-size:11px;font-weight:700;padding:3px 9px;border-radius:12px;background:rgba(255,255,255,.08);color:rgba(255,255,255,.75);}
.spcal-pg-badge.ok{background:rgba(34,197,94,.15);color:#4ade80;}
.spcal-pg-badge.rev{background:rgba(245,158,11,.15);color:#fbbf24;}
.spcal-pg-badge.non{background:rgba(239,68,68,.15);color:#f87171;}
.spcal-pg-revoir{margin-top:10px;font-size:13px;color:#fcd34d;line-height:1.45;}
.spcal-pg-rem{margin-top:8px;font-size:13px;font-style:italic;color:rgba(255,255,255,.7);}
.spcal-pg-prevu{font-size:13px;color:#7cc0f5;margin:6px 0;}
.spcal-pg-video{display:inline-block;margin-top:10px;background:#0f70b7;color:#fff;text-decoration:none;font-size:13px;font-weight:600;padding:8px 14px;border-radius:20px;}
.spcal-pg-rappel{width:100%;border:0;font:inherit;text-align:left;cursor:pointer;}

/* ── CARTE ───────────────────────────────────────────────────── */
#spcal-carte{padding:20px 16px;}
.spcal-card-scene{perspective:1200px;width:340px;max-width:100%;margin:0 auto 16px;}
.spcal-card-inner{position:relative;width:340px;max-width:100%;height:215px;transition:transform .6s cubic-bezier(.4,.2,.2,1);transform-style:preserve-3d;cursor:pointer;}
.spcal-card-inner.flipped{transform:rotateY(180deg);}
.sp-carte-recto,.sp-carte-verso{position:absolute;top:0;left:0;width:100%;height:100%;-webkit-backface-visibility:hidden;backface-visibility:hidden;}
.sp-carte-verso{transform:rotateY(180deg);}

/* Réutilise les styles de class-token.php */
.sp-carte-recto{background:#111;border-radius:12px;overflow:hidden;}
.sp-carte-band-blue{position:absolute;right:0;top:0;width:88px;height:215px;background:#0f70b7;}
.sp-carte-band-yellow{position:absolute;right:84px;top:0;width:4px;height:215px;background:#ffdd0e;}
.sp-carte-band-red{position:absolute;bottom:0;left:0;width:252px;height:5px;background:#e30613;}
.sp-carte-logo-wrap{position:absolute;top:10px;left:12px;width:30px;height:30px;border-radius:4px;background:#fff;display:flex;align-items:center;justify-content:center;}
.sp-carte-logo{width:28px;height:28px;object-fit:contain;}
.sp-carte-club-name{position:absolute;top:12px;left:50px;color:#fff;font-size:10px;font-weight:500;letter-spacing:1.5px;}
.sp-carte-club-sub{position:absolute;top:25px;left:50px;color:#ffdd0e;font-size:8px;letter-spacing:1px;}
.sp-carte-sep{position:absolute;top:46px;left:12px;width:240px;height:.5px;background:rgba(255,255,255,.12);}
.sp-carte-nom{position:absolute;top:54px;left:12px;right:100px;color:#fff;font-size:13px;font-weight:500;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sp-carte-meta{position:absolute;top:72px;left:12px;right:100px;color:rgba(255,255,255,.4);font-size:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sp-carte-infos{position:absolute;top:88px;left:12px;right:100px;display:flex;flex-direction:column;gap:4px;}
.sp-carte-info-row{display:flex;gap:6px;align-items:center;}
.sp-carte-info-k{color:rgba(255,255,255,.38);font-size:8px;width:50px;text-transform:uppercase;letter-spacing:.4px;flex-shrink:0;}
.sp-carte-info-v{color:#fff;font-size:9px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sp-carte-info-urgence{color:#e30613!important;}
.sp-carte-qr-zone{position:absolute;top:8px;right:2px;width:86px;display:flex;flex-direction:column;align-items:center;gap:2px;}
.sp-carte-qr-box{width:72px;height:72px;background:#fff;border-radius:6px;overflow:hidden;display:flex;align-items:center;justify-content:center;}
.sp-carte-qr-box img,.sp-carte-qr-box canvas{max-width:72px;max-height:72px;}
.sp-carte-stars{display:flex;gap:1px;margin-top:3px;}
.sp-carte-labelise-txt{color:rgba(255,255,255,.35);font-size:7px;letter-spacing:.3px;}
.sp-carte-qr-scan{color:rgba(255,255,255,.3);font-size:7px;text-align:center;}
.sp-carte-url{position:absolute;bottom:8px;right:6px;color:rgba(255,255,255,.2);font-size:7px;}
.sp-carte-photo-wrap{position:absolute;bottom:12px;left:12px;width:46px;height:46px;border-radius:50%;overflow:hidden;border:2px solid rgba(255,255,255,.18);background:#222;}
.sp-carte-photo{width:100%;height:100%;object-fit:cover;display:block;}
.sp-carte-verso{background:#111;display:flex;flex-direction:column;border-radius:12px;overflow:hidden;}
.sp-carte-verso-header{height:36px;padding:0 12px;display:flex;align-items:center;gap:8px;border-bottom:1px solid rgba(255,255,255,.1);flex-shrink:0;}
.sp-carte-verso-logo-wrap{width:22px;height:22px;border-radius:3px;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sp-carte-verso-logo{width:20px;height:20px;object-fit:contain;}
.sp-carte-verso-title{color:rgba(255,255,255,.75);font-size:10px;font-weight:500;letter-spacing:.8px;}
.sp-carte-verso-badge{margin-left:auto;background:#ffdd0e;border-radius:8px;padding:2px 8px;font-size:8px;font-weight:500;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;}
.sp-carte-verso-footer{height:24px;padding:0 12px;border-top:1px solid rgba(255,255,255,.1);display:flex;justify-content:space-between;align-items:center;font-size:8px;color:rgba(255,255,255,.3);flex-shrink:0;}
.sp-carte-verso-body{flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:6px;padding:12px;}
.sp-carte-taegeuk{color:rgba(255,255,255,.15);font-size:40px;line-height:1;user-select:none;font-family:'Song Myung',cursive;letter-spacing:3px;}
.sp-carte-officiel{color:rgba(255,255,255,.35);font-size:9px;letter-spacing:2px;text-transform:uppercase;}

.spcal-card-hint{text-align:center;font-size:12px;color:rgba(255,255,255,.3);margin:0 0 20px;}
.spcal-install-hint{background:#1a1a1a;border-radius:12px;padding:14px 16px;font-size:13px;color:rgba(255,255,255,.5);display:flex;align-items:flex-start;gap:10px;}
.spcal-install-hint .spcal-ih-ico{font-size:20px;flex-shrink:0;margin-top:1px;}

/* ── POINTAGE ────────────────────────────────────────────────── */
.spcal-ptg-cours-btn{width:100%;background:#1a1a1a;border:none;border-radius:12px;padding:14px 16px;margin-bottom:10px;color:#fff;text-align:left;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:12px;border-left:3px solid #0f70b7;}
/* Cadre de scan des présences : ligne de balayage (lecture active) + éclair vert / orange / rouge au résultat */
.spcal-scan-frame{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:200px;height:200px;border:2px solid rgba(15,112,183,.8);border-radius:12px;box-shadow:0 0 0 9999px rgba(0,0,0,.45);overflow:hidden;transition:border-color .2s,box-shadow .2s;}
.spcal-scan-line{position:absolute;left:8%;right:8%;top:8%;height:2px;border-radius:2px;background:#38bdf8;box-shadow:0 0 10px 2px rgba(56,189,248,.7);animation:spcal-scanline 2.2s ease-in-out infinite;}
@keyframes spcal-scanline{0%,100%{top:8%}50%{top:92%}}
.spcal-scan-frame.flash-ok{border-color:#22c55e;box-shadow:0 0 0 9999px rgba(0,0,0,.45),0 0 22px 6px rgba(34,197,94,.9);}
.spcal-scan-frame.flash-already{border-color:#f59e0b;box-shadow:0 0 0 9999px rgba(0,0,0,.45),0 0 22px 6px rgba(245,158,11,.9);}
.spcal-scan-frame.flash-err{border-color:#ef4444;box-shadow:0 0 0 9999px rgba(0,0,0,.45),0 0 22px 6px rgba(239,68,68,.9);}
.spcal-scan-frame.flash-ok .spcal-scan-line,.spcal-scan-frame.flash-already .spcal-scan-line,.spcal-scan-frame.flash-err .spcal-scan-line{opacity:0;}
@media (prefers-reduced-motion:reduce){.spcal-scan-line{animation:none;top:50%;}}
.spcal-scan-overlay{position:absolute;inset:0;z-index:2;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:16px;text-align:center;color:#fff;cursor:pointer;background:rgba(15,23,42,.85);}
.spcal-scan-overlay.ok{background:rgba(22,163,74,.92);}
.spcal-scan-overlay.already{background:rgba(217,119,6,.92);}
.spcal-scan-overlay.err{background:rgba(220,38,38,.92);}
.spcal-scan-ov-nom{font-size:22px;font-weight:800;line-height:1.2;word-break:break-word;}
.spcal-scan-ov-msg{font-size:15px;font-weight:600;opacity:.95;}
.spcal-scan-ov-hint{font-size:11px;opacity:.75;margin-top:8px;}
.spcal-scan-overlay.wait .spcal-scan-ov-hint{display:none;}
/* Anniversaires du mois (onglet Pointage) */
.spcal-anniv{background:#1a1a1a;border-radius:12px;padding:12px 14px;margin:0 0 4px;border-left:3px solid #D4000F;}
.spcal-anniv-titre{font-size:13px;font-weight:700;margin-bottom:8px;}
.spcal-anniv-cat{font-size:11px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.8px;margin:10px 0 4px;}
.spcal-anniv-ligne{display:flex;align-items:center;gap:10px;padding:5px 0;font-size:14px;}
.spcal-anniv-jour{flex-shrink:0;width:28px;height:28px;border-radius:50%;background:#D4000F;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;}
.spcal-anniv-ligne.aujourdhui .spcal-anniv-nom{font-weight:700;}
.spcal-anniv-age{margin-left:auto;font-size:12px;color:rgba(255,255,255,.5);}
.spcal-anniv-vide{font-size:13px;color:rgba(255,255,255,.4);}
.spcal-ptg-cours-btn:active{opacity:.7;}
.spcal-ptg-cours-left{flex:1;min-width:0;}
.spcal-ptg-cours-btn-titre{font-size:15px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.spcal-ptg-cours-btn-cat{font-size:12px;color:rgba(255,255,255,.4);margin-top:2px;}
.spcal-ptg-cours-heure{font-size:14px;font-weight:600;color:#0f70b7;flex-shrink:0;}
.spcal-ptg-log-entry{background:#1a1a1a;border-radius:10px;padding:10px 14px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;}
.spcal-ptg-log-nom{font-size:14px;font-weight:600;}
.spcal-ptg-log-time{font-size:11px;color:rgba(255,255,255,.35);}
.spcal-scan-ok{background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);}
.spcal-scan-already{background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.3);}
.spcal-scan-err{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);}

/* ── CALENDRIER ──────────────────────────────────────────────── */
.spcal-cal-month{font-size:13px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.8px;margin:20px 0 10px;}
.spcal-cal-month:first-child{margin-top:0;}
</style>

<div id="spcal-pwa">

    <!-- Loading -->
    <div id="spcal-loading">
        <?php if ( $logo_url ) : ?>
        <img src="<?php echo esc_url( $logo_url ); ?>" alt="">
        <?php else : ?>
        <div style="font-size:48px;">🥋</div>
        <?php endif; ?>
        <div class="spcal-spinner"></div>
        <p>Chargement en cours…</p>
    </div>

    <!-- Error -->
    <div id="spcal-error">
        <div class="spcal-err-ico">🔒</div>
        <h2>Accès invalide</h2>
        <p id="spcal-error-msg">Lien expiré ou invalide.<br>Contactez votre club pour recevoir un nouveau lien.</p>
    </div>

    <!-- App -->
    <div id="spcal-app">

        <!-- Header -->
        <header id="spcal-header">
            <img id="spcal-header-logo" src="" alt="" style="display:none">
            <div id="spcal-header-logo-placeholder"></div>
            <div id="spcal-header-text">
                <div id="spcal-header-club"><?php echo esc_html( get_option( 'blogname', '' ) ); ?></div>
                <div id="spcal-header-name">Chargement…</div>
            </div>
            <div id="spcal-grade-pill" style="display:none"></div>
        </header>

        <!-- Main -->
        <main id="spcal-main">

            <!-- Accueil -->
            <section id="spcal-accueil" class="spcal-screen active">
                <div id="spcal-greeting"></div>
                <div id="spcal-greeting-sub"></div>
                <div id="spcal-adhesion-wrap"></div>
                <div id="spcal-assiduite-wrap"></div>
                <!-- Rappel « passage de grade à venir » — renderGradeRappel() -->
                <div id="spcal-grade-rappel"></div>
                <div id="spcal-fiche-wrap"></div>
                <div class="spcal-section-title">Prochains cours</div>
                <div id="spcal-prochains-cours"><div class="spcal-empty">Chargement…</div></div>
            </section>

            <!-- Carte -->
            <section id="spcal-carte" class="spcal-screen">
                <div class="spcal-section-title">Carte de membre</div>
                <div class="spcal-card-scene">
                    <div class="spcal-card-inner" id="spcal-card-inner" onclick="spCalFlipCard()">
                        <div class="sp-carte-recto" id="spcal-recto"></div>
                        <div class="sp-carte-verso" id="spcal-verso"></div>
                    </div>
                </div>
                <p class="spcal-card-hint">Appuyez sur la carte pour retourner</p>
                <div class="spcal-install-hint" id="spcal-install-hint" style="display:none">
                    <span class="spcal-ih-ico">📲</span>
                    <span id="spcal-install-text"></span>
                </div>
                <!-- Progression de grade — injecté par renderGradeProgression() -->
                <div id="spcal-grade-progression"></div>
            </section>

            <!-- Calendrier -->
            <section id="spcal-calendrier" class="spcal-screen">
                <div class="spcal-section-title">Calendrier</div>
                <div id="spcal-cal-list"><div class="spcal-empty">Chargement…</div></div>
            </section>

            <!-- Événements & Inscriptions -->
            <section id="spcal-evenements" class="spcal-screen">
                <div class="spcal-section-title">Événements — 3 prochains mois</div>
                <div id="spcal-evt-list"><div class="spcal-empty">Chargement…</div></div>
            </section>

            <!-- Pointage entraîneurs -->
            <section id="spcal-pointage" class="spcal-screen">
                <!-- Écran PIN -->
                <div id="spcal-pin-screen">
                    <div class="spcal-section-title">Accès entraîneur</div>
                    <p style="font-size:13px;color:rgba(255,255,255,.5);margin-bottom:16px;">Entrez votre code PIN pour accéder au pointage.</p>
                    <div style="display:flex;gap:10px;align-items:center;margin-bottom:12px;">
                        <input id="spcal-pin-input" type="password" inputmode="numeric" maxlength="8"
                            placeholder="Code PIN"
                            style="flex:1;background:#1a1a1a;border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:12px 16px;color:#fff;font-size:18px;letter-spacing:4px;outline:none;">
                        <button onclick="spCalPinSubmit()"
                            style="background:#0f70b7;border:none;border-radius:10px;padding:12px 20px;color:#fff;font-size:15px;font-weight:600;cursor:pointer;">OK</button>
                    </div>
                    <div id="spcal-pin-error" style="display:none;color:#f87171;font-size:13px;margin-bottom:8px;"></div>
                </div>
                <!-- Dashboard pointage (affiché après PIN valide) -->
                <div id="spcal-pointage-dashboard" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <div class="spcal-section-title" style="margin:0;">Pointage du <span id="spcal-ptg-date"></span></div>
                        <button id="spcal-ptg-logout" onclick="spCalPinLogout()" style="background:none;border:none;color:rgba(255,255,255,.3);font-size:12px;cursor:pointer;">Déconnexion</button>
                    </div>
                    <!-- Sélecteur cours -->
                    <div id="spcal-cours-list-wrap">
                        <p style="font-size:13px;color:rgba(255,255,255,.5);">Sélectionnez un cours :</p>
                        <div id="spcal-ptg-cours-list"></div>
                    </div>
                    <!-- Zone scan (affichée après sélection cours) -->
                    <div id="spcal-scan-wrap" style="display:none;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                            <button onclick="spCalBackToCours()" style="background:#1a1a1a;border:none;border-radius:8px;padding:8px 12px;color:rgba(255,255,255,.6);font-size:13px;cursor:pointer;">← Cours</button>
                            <div id="spcal-ptg-cours-title" style="font-size:14px;font-weight:600;"></div>
                        </div>
                        <!-- Caméra QR -->
                        <div style="position:relative;width:100%;max-width:320px;margin:0 auto 16px;border-radius:12px;overflow:hidden;background:#000;">
                            <video id="spcal-qr-video" style="width:100%;display:block;" playsinline autoplay muted></video>
                            <canvas id="spcal-qr-canvas" style="display:none;"></canvas>
                            <div style="position:absolute;inset:0;pointer-events:none;">
                                <div id="spcal-scan-frame" class="spcal-scan-frame"><div class="spcal-scan-line"></div></div>
                            </div>
                            <!-- Résultat affiché dans l'image (04/10/2026) : la lecture est en pause pendant
                                 l'affichage puis reprend seule ; toucher le bandeau reprend tout de suite. -->
                            <div id="spcal-scan-overlay" class="spcal-scan-overlay" onclick="spCalScanReprendre()" style="display:none;">
                                <div id="spcal-scan-ov-nom" class="spcal-scan-ov-nom"></div>
                                <div id="spcal-scan-ov-msg" class="spcal-scan-ov-msg"></div>
                                <div class="spcal-scan-ov-hint">Toucher pour scanner l'élève suivant</div>
                            </div>
                        </div>
                        <p style="text-align:center;font-size:13px;color:rgba(255,255,255,.4);margin-bottom:4px;">Scannez la carte QR de l'élève</p>
                        <p id="spcal-scan-etat" style="text-align:center;font-size:11px;color:rgba(255,255,255,.3);margin-bottom:16px;"></p>
                        <!-- Feedback scan -->
                        <div id="spcal-scan-feedback" style="display:none;border-radius:12px;padding:14px 16px;text-align:center;margin-bottom:12px;">
                            <div id="spcal-scan-nom" style="font-size:17px;font-weight:700;"></div>
                            <div id="spcal-scan-msg" style="font-size:13px;margin-top:4px;"></div>
                        </div>
                        <!-- Log des présences -->
                        <div class="spcal-section-title" style="margin-top:8px;">Présences enregistrées</div>
                        <div id="spcal-ptg-log"></div>
                    </div>
                </div>
            </section>

            <!-- Anniversaires du mois (class-anniversaires.php, route /anniversaires) : onglet 🎂 à part,
                 pour ne pas mêler ces prénoms aux présences du pointage (choix de l'utilisateur, 01/10/2026) -->
            <section id="spcal-anniv" class="spcal-screen">
                <div id="spcal-ptg-anniv"><div class="spcal-empty">Chargement…</div></div>
            </section>

            <!-- Mes dispos (mode entraîneur par lien personnel) — chargé à la première ouverture -->
            <section id="spcal-dispos" class="spcal-screen">
                <div id="spcal-dispos-box"><div class="spcal-empty">Chargement…</div></div>
            </section>

            <!-- Saisie rapide de la trésorerie (membres du bureau, lien personnel) : ouverte dans une
                 fenêtre à part — pas insérée ici (un cadre bloquait le défilement pour « Mes dispos »,
                 et l'envoi du formulaire ferait quitter l'application). -->
            <section id="spcal-saisie" class="spcal-screen">
                <div class="spcal-section-title">Trésorerie</div>
                <div class="spcal-saisie-carte">
                    <div class="spcal-saisie-ico">💶</div>
                    <p><strong>Saisie rapide</strong><br>Une dépense ou une recette en quelques secondes, avec la photo du justificatif.</p>
                    <a id="spcal-saisie-lien" class="spcal-saisie-btn" href="#" target="_blank" rel="noopener">Ouvrir la saisie rapide</a>
                    <p class="spcal-saisie-aide">S'ouvre dans une fenêtre à part : fermez-la (« OK » / « Terminé ») pour revenir ici. La première fois, connectez-vous avec votre compte du site : la connexion est ensuite gardée un an.</p>
                </div>
            </section>

        </main>

        <!-- Bottom nav -->
        <nav id="spcal-nav">
            <button class="spcal-nav-btn active" onclick="spCalNav('accueil',this)">
                <span class="spcal-nav-ico">🏠</span>
                <span>Accueil</span>
            </button>
            <button class="spcal-nav-btn" id="spcal-nav-carte" onclick="spCalNav('carte',this)">
                <span class="spcal-nav-ico">🪪</span>
                <span>Carte</span>
            </button>
            <button class="spcal-nav-btn" id="spcal-nav-calendrier" onclick="spCalNav('calendrier',this)">
                <span class="spcal-nav-ico">📅</span>
                <span>Calendrier</span>
            </button>
            <button class="spcal-nav-btn" id="spcal-nav-evenements" onclick="spCalNav('evenements',this)">
                <span class="spcal-nav-ico">🎯</span>
                <span>Événements</span>
            </button>
            <button class="spcal-nav-btn" id="spcal-nav-pointage" style="display:none" onclick="spCalNav('pointage',this)">
                <span class="spcal-nav-ico">📡</span>
                <span>Pointage</span>
            </button>
            <button class="spcal-nav-btn" id="spcal-nav-anniv" style="display:none" onclick="spCalNav('anniv',this)">
                <span class="spcal-nav-ico">🎂</span>
                <span>Anniversaires</span>
            </button>
            <button class="spcal-nav-btn" id="spcal-nav-saisie" style="display:none" onclick="spCalNav('saisie',this)">
                <span class="spcal-nav-ico">💶</span>
                <span>Saisie</span>
            </button>
        </nav>

    </div><!-- /#spcal-app -->

</div><!-- /#spcal-pwa -->

<link href="https://fonts.googleapis.com/css2?family=Song+Myung&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function(){
'use strict';

/* ── Config PHP ─────────────────────────────────────────────── */
var CFG = window.SPCAL || {};
var API = CFG.apiBase || '/wp-json/spcal/v1';

/* ── Helpers ────────────────────────────────────────────────── */
function esc(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(ymd) {
    if (!ymd) return '';
    var p = ymd.split('-');
    return p[2]+'/'+p[1]+'/'+p[0];
}
/* Date d'un cours précédée du jour de la semaine (« Lundi 05/10/2026 ») : la date seule ne
   suffit pas à se situer dans la semaine. Date construite en local (pas new Date('Y-m-d'),
   lue en UTC) pour ne pas décaler le jour. */
var JOURS_SEMAINE = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
function fmtDateJour(ymd) {
    if (!ymd) return '';
    var p = ymd.split('-');
    var d = new Date(+p[0], +p[1] - 1, +p[2]);
    return JOURS_SEMAINE[d.getDay()] + ' ' + fmtDate(ymd);
}
function fmtHeure(h) { return h ? h.replace(':','h').substring(0,5) : ''; }
function isToday(ymd)    { return ymd === new Date().toISOString().slice(0,10); }
function isTomorrow(ymd) {
    var t = new Date(); t.setDate(t.getDate()+1);
    return ymd === t.toISOString().slice(0,10);
}
function monthLabel(ymd) {
    if (!ymd) return '';
    var d = new Date(ymd);
    return d.toLocaleDateString('fr-FR',{month:'long',year:'numeric'});
}
function show(id) { var el=document.getElementById(id); if(el) el.style.display='flex'; }
function hide(id) { var el=document.getElementById(id); if(el) el.style.display='none'; }
function showBlock(id){ var el=document.getElementById(id); if(el) el.style.display='block'; }

/* ── Storage ────────────────────────────────────────────────── */
var STORE = {
    get: function(k){ try{ return localStorage.getItem(k); }catch(e){ return null; } },
    set: function(k,v){ try{ localStorage.setItem(k,v); }catch(e){} },
    del: function(k){ try{ localStorage.removeItem(k); }catch(e){} }
};

/* ── Auth ────────────────────────────────────────────────────── */
function getToken() {
    // 1. URL param ?token=
    var params = new URLSearchParams(location.search);
    var t = params.get('token');
    if (t) { STORE.set('spcal_token', t); return t; }
    // 2. Stockage local
    return STORE.get('spcal_token');
}

function getJwt() { return STORE.get('spcal_jwt'); }
function jwtExpired(jwt) {
    try {
        var p = JSON.parse(atob(jwt.split('.')[1].replace(/-/g,'+').replace(/_/g,'/')));
        return !p.exp || p.exp < Math.floor(Date.now()/1000) + 60;
    } catch(e){ return true; }
}

function auth(token) {
    return fetch(API+'/auth', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({token: token})
    }).then(function(r){ return r.json(); });
}

/* ── API calls ───────────────────────────────────────────────── */
function apiFetch(path) {
    var jwt = getJwt();
    return fetch(API+path, { headers: { 'Authorization': 'Bearer '+jwt } }).then(function(r){ return r.json(); });
}

/* ── Initialisation ──────────────────────────────────────────── */
var gEleve = null, gCours = [];

function init() {
    // Entraîneur : ?pin= dans l'URL → ouvrir directement l'onglet pointage
    var urlParams = new URLSearchParams(location.search);
    var pinFromUrl = urlParams.get('pin');
    if (pinFromUrl) {
        STORE.set('spcal_pin', pinFromUrl);
        // Nettoyer le PIN de l'URL sans recharger
        urlParams.delete('pin');
        var cleanUrl = location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        history.replaceState(null, '', cleanUrl);
        initEntraineur(pinFromUrl);
        return;
    }

    // Entraîneur par son lien personnel de dispos (?entraineur=, redirigé depuis la page
    // des dispos par class-trainer-app.php). Clé distincte de spcal_token (élève).
    var trainerFromUrl = urlParams.get('entraineur');
    if (trainerFromUrl) {
        STORE.set('spcal_trainer_token', trainerFromUrl);
        urlParams.delete('entraineur');
        history.replaceState(null, '', location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : ''));
    }
    var trainerTok = trainerFromUrl || (!urlParams.get('token') && STORE.get('spcal_trainer_token'));
    if (trainerTok) {
        initEntraineurToken(trainerTok);
        return;
    }

    initEleve();
}

function initEleve() {
    var token = getToken();
    if (!token) {
        showError('Aucun lien d\'accès trouvé.<br>Utilisez le lien reçu par email.');
        return;
    }

    var jwt = getJwt();
    var needAuth = !jwt || jwtExpired(jwt);

    var authPromise = needAuth
        ? auth(token).then(function(r) {
            if (!r.success) throw new Error(r.message || 'Authentification échouée');
            STORE.set('spcal_jwt', r.jwt);
          })
        : Promise.resolve();

    authPromise
        .then(function() {
            return Promise.all([
                apiFetch('/eleve/me'),
                apiFetch('/calendrier?nb=20')
            ]);
        })
        .then(function(results) {
            var eleve = results[0], cal = results[1];
            if (eleve.code) throw new Error(eleve.message || 'Profil introuvable');
            gEleve = eleve;
            gCours = (cal.cours || []);
            renderApp();
            registerSW();
            spCalSetupPush();
        })
        .catch(function(err) {
            STORE.del('spcal_jwt');
            showError(esc(err.message || 'Erreur de connexion'));
        });
}

/* ── Render ──────────────────────────────────────────────────── */
/* ── Mode entraîneur ────────────────────────────────────────────── */
/* Lien personnel : jeton entraîneur → nom + PIN du pointage + adresse de ses dispos. */
function initEntraineurToken(tt) {
    fetch(API + '/entraineur/session', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({token: tt})
    })
    .then(function(r){ return r.json(); })
    .then(function(r) {
        if (!r || !r.success) { STORE.del('spcal_trainer_token'); initEleve(); return; }
        initEntraineur(r.pin, {nom: r.nom, dispos_url: r.dispos_url, saisie_url: r.saisie_url || ''});
    })
    .catch(function() { showError('Erreur de connexion. Réessayez.'); });
}

var gEntr = null; // {nom, dispos_url} en mode lien personnel
function initEntraineur(pin, opts) {
    var today = new Date().toISOString().slice(0,10);
    fetch(CFG.apiBase + '/pointage/cours', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({pin: pin, date: today})
    })
    .then(function(r){ return r.json(); })
    .then(function(r) {
        if (!r.success) {
            showError('PIN invalide ou expir&eacute;.<br>V&eacute;rifiez votre lien d&#39;acc&egrave;s.');
            return;
        }
        gPin = pin;
        hide('spcal-loading');
        show('spcal-app');
        // Mode entraineur : tous les onglets sauf Carte
        var navPtg2   = document.getElementById('spcal-nav-pointage');
        var navCarte  = document.getElementById('spcal-nav-carte');
        var navEvt    = document.getElementById('spcal-nav-evenements');
        if (navPtg2)  navPtg2.style.display  = 'flex';
        if (navCarte) navCarte.style.display  = 'none';
        if (navEvt)   navEvt.style.display    = 'none';
        document.querySelectorAll('.spcal-nav-btn').forEach(function(b){
            // « Saisie » : jamais affiché par défaut, seulement pour le bureau (ci-dessous).
            if (b.id !== 'spcal-nav-carte' && b.id !== 'spcal-nav-evenements' && b.id !== 'spcal-nav-saisie') b.style.display = 'flex';
        });
        // Membre du bureau (lien personnel) : onglet « 💶 Saisie » vers la saisie rapide de la trésorerie.
        if (opts && opts.saisie_url) {
            var navSaisie = document.getElementById('spcal-nav-saisie');
            var lienSaisie = document.getElementById('spcal-saisie-lien');
            if (navSaisie && lienSaisie) {
                lienSaisie.href = opts.saisie_url;
                navSaisie.style.display = 'flex';
            }
        }
        // Lien personnel : « Calendrier » devient « Mes dispos », pas de déconnexion (lien permanent)
        if (opts && opts.dispos_url) {
            gEntr = opts;
            var navCal = document.getElementById('spcal-nav-calendrier');
            if (navCal) {
                navCal.setAttribute('onclick', "spCalNav('dispos',this)");
                navCal.querySelector('.spcal-nav-ico').textContent = '🗓️';
                navCal.querySelectorAll('span')[1].textContent = 'Mes dispos';
            }
            hide('spcal-ptg-logout');
        }
        // Header entraineur
        document.getElementById('spcal-header-name').textContent = gEntr && gEntr.nom ? gEntr.nom : 'Espace entraîneur';
        document.getElementById('spcal-header-club').textContent = CFG.clubNom || '';
        if (CFG.logoUrl) {
            var img = document.getElementById('spcal-header-logo');
            img.src = CFG.logoUrl; img.style.display = 'block';
            hide('spcal-header-logo-placeholder');
        } else {
            document.getElementById('spcal-header-logo-placeholder').textContent = '📡';
            document.getElementById('spcal-header-logo-placeholder').style.display = 'flex';
        }
        spCalShowPointageDashboard(r.data, today);
        // Lien PIN : directement sur le pointage ; lien personnel : accueil
        if (gEntr) spCalNav('accueil', document.querySelector('.spcal-nav-btn'));
        else spCalNav('pointage', document.getElementById('spcal-nav-pointage'));
        spCalLoadCalendrierClub(pin);
    })
    .catch(function() { showError('Erreur de connexion. Réessayez.'); });
}

function renderApp() {
    hide('spcal-loading');
    show('spcal-app');
    // Mode eleve : onglet pointage masque
    var navPtg = document.getElementById('spcal-nav-pointage');
    if (navPtg) navPtg.style.display = 'none';
    renderHeader();
    renderAccueil();
    renderCarte();
    renderCalendrier();
    renderEvenements();
}

function renderHeader() {
    var el = gEleve;
    document.getElementById('spcal-header-name').textContent = el.prenom + ' ' + el.nom;
    // Logo
    if (el.carte && el.carte.logo_url) {
        var img = document.getElementById('spcal-header-logo');
        img.src = el.carte.logo_url;
        img.style.display = 'block';
        hide('spcal-header-logo-placeholder');
    } else {
        document.getElementById('spcal-header-logo-placeholder').textContent = '🥋';
        hide('spcal-header-logo');
        show('spcal-header-logo-placeholder');
    }
    // Grade
    if (el.grade) {
        var pill = document.getElementById('spcal-grade-pill');
        pill.textContent = el.grade;
        pill.style.display = 'block';
    }
}

function renderAccueil() {
    var el = gEleve;
    var now = new Date();
    var h = now.getHours();
    var salut = h < 12 ? 'Bonjour' : (h < 18 ? 'Bon après-midi' : 'Bonsoir');
    document.getElementById('spcal-greeting').textContent = salut + ' ' + el.prenom + ' 👋';
    document.getElementById('spcal-greeting-sub').textContent = el.grade ? '🥋 ' + el.grade : '';

    // Badge adhésion
    var adh = el.adhesion || {};
    var adhHtml = '';
    if (adh.statut === 'expire') {
        adhHtml = '<div class="spcal-adhesion-badge spcal-adhesion-ko">❌ Renouvellement à faire</div>';
    } else if (adh.statut === 'expire_bientot') {
        adhHtml = '<div class="spcal-adhesion-badge spcal-adhesion-warn">⚠️ Fin de saison dans '+adh.jours+' jours</div>';
    } else {
        adhHtml = '<div class="spcal-adhesion-badge spcal-adhesion-actif">✅ Adhésion active</div>';
    }
    document.getElementById('spcal-adhesion-wrap').innerHTML = adhHtml;

    // Assiduité
    var ass = el.assiduite || {};
    if (ass.total > 0) {
        var color = ass.taux >= 75 ? '#22c55e' : (ass.taux >= 50 ? '#f59e0b' : '#ef4444');
        document.getElementById('spcal-assiduite-wrap').innerHTML =
            '<div class="spcal-assiduite">'+
            '<div class="spcal-assiduite-label">Assiduité saison</div>'+
            '<div class="spcal-assiduite-bar"><div class="spcal-assiduite-fill" style="width:'+ass.taux+'%;background:'+color+'"></div></div>'+
            '<div class="spcal-assiduite-nums">'+ass.present+' présences sur '+ass.total+' cours ('+ass.taux+'%)</div>'+
            '</div>';
    }

    // Fiche complète (grades, présences, doboks)
    var ficheWrap = document.getElementById('spcal-fiche-wrap');
    if (ficheWrap && el.fiche_url) {
        var a = document.createElement('a');
        a.className = 'spcal-fiche-link';
        a.href = el.fiche_url;
        a.innerHTML = '<span class="spcal-fl-ico">📋</span><span><strong>Ma fiche complète</strong><small>Grades, présences, doboks</small></span><span class="spcal-fl-go">›</span>';
        ficheWrap.innerHTML = '';
        ficheWrap.appendChild(a);
    }

    // Prochains cours
    var html = '';
    var shown = gCours.slice(0, 5);
    if (!shown.length) {
        html = '<div class="spcal-empty">Aucun cours à venir</div>';
    } else {
        shown.forEach(function(c) {
            var today    = isToday(c.date);
            var tomorrow = isTomorrow(c.date);
            var cls      = today ? ' today' : (tomorrow ? ' tomorrow' : '');
            var badge    = today ? '<span class="spcal-cours-badge spcal-badge-today">Aujourd\'hui</span>'
                         : (tomorrow ? '<span class="spcal-cours-badge spcal-badge-tomorrow">Demain</span>' : '');
            html += '<div class="spcal-cours-card'+cls+'">'+
                '<div class="spcal-cours-left">'+
                '<div class="spcal-cours-titre">'+esc(c.titre)+'</div>'+
                (c.categorie ? '<div class="spcal-cours-cat">'+esc(c.categorie)+'</div>' : '')+spCalEncadrementHtml(c)+
                '</div>'+
                '<div class="spcal-cours-right">'+
                '<div class="spcal-cours-heure">'+esc(fmtHeure(c.heure_debut))+'</div>'+
                '<div class="spcal-cours-date">'+esc(fmtDateJour(c.date))+'</div>'+
                badge+
                '</div>'+
                '</div>';
        });
    }
    document.getElementById('spcal-prochains-cours').innerHTML = html;
}

function renderCarte() {
    var el = gEleve;
    var carte = el.carte || {};

    // ── Recto ──────────────────────────────────────────────────
    var metaParts = [
        carte.club_affil ? 'FFTDA '+(carte.club_affil||'') : '',
        carte.club_ligue || '',
        carte.club_num   ? 'Club '+carte.club_num : ''
    ].filter(Boolean).join(' · ');

    var infosHtml = '';
    if (el.licence)
        infosHtml += '<div class="sp-carte-info-row"><span class="sp-carte-info-k">Licence</span><span class="sp-carte-info-v">'+esc(el.licence)+'</span></div>';
    if (el.date_naissance && el.annee_naissance)
        infosHtml += '<div class="sp-carte-info-row"><span class="sp-carte-info-k">Né(e) le</span><span class="sp-carte-info-v">'+esc(el.date_naissance+'/'+el.annee_naissance)+'</span></div>';
    if (el.num_passeport)
        infosHtml += '<div class="sp-carte-info-row"><span class="sp-carte-info-k">Passeport</span><span class="sp-carte-info-v">'+esc(el.num_passeport)+'</span></div>';
    if (el.urgence_telephone)
        infosHtml += '<div class="sp-carte-info-row"><span class="sp-carte-info-k">Urgence</span><span class="sp-carte-info-v sp-carte-info-urgence">'+esc(el.urgence_telephone)+'</span></div>';

    var starsHtml = '';
    if (carte.club_labelise > 0) {
        for (var i=1; i<=5; i++)
            starsHtml += '<span style="color:'+(i<=carte.club_labelise?'#ffdd0e':'rgba(255,255,255,0.2)')+';font-size:9px;">★</span>';
        starsHtml = '<div class="sp-carte-stars">'+starsHtml+'</div><div class="sp-carte-labelise-txt">Club labellisé</div>';
    }

    var photoHtml = el.photo_url
        ? '<div class="sp-carte-photo-wrap"><img src="'+esc(el.photo_url)+'" class="sp-carte-photo" alt=""></div>'
        : '';

    document.getElementById('spcal-recto').innerHTML =
        '<div class="sp-carte-band-blue"></div>'+
        '<div class="sp-carte-band-yellow"></div>'+
        '<div class="sp-carte-band-red"></div>'+
        '<div class="sp-carte-logo-wrap">'+
            (carte.logo_url ? '<img src="'+esc(carte.logo_url)+'" class="sp-carte-logo" onerror="this.style.display=\'none\'">' : '')+
        '</div>'+
        '<div class="sp-carte-club-name">'+esc((carte.club_nom||'').toUpperCase())+'</div>'+
        '<div class="sp-carte-club-sub">CARTE DE MEMBRE</div>'+
        '<div class="sp-carte-sep"></div>'+
        '<div class="sp-carte-nom">'+esc(el.prenom+' '+el.nom)+'</div>'+
        '<div class="sp-carte-meta">'+esc(metaParts)+'</div>'+
        '<div class="sp-carte-infos">'+infosHtml+'</div>'+
        '<div class="sp-carte-qr-zone">'+
            '<div id="spcal-qr-box" class="sp-carte-qr-box"></div>'+
            starsHtml+
            '<div class="sp-carte-qr-scan">Scanner pour pointer</div>'+
        '</div>'+
        photoHtml+
        '<div class="sp-carte-url">'+esc((carte.club_nom||'').toLowerCase().replace(/\s/g,'')+'.fr')+'</div>';

    // QR code
    function genQR() {
        if (typeof QRCode === 'undefined') { setTimeout(genQR, 100); return; }
        var box = document.getElementById('spcal-qr-box');
        if (box) new QRCode(box, { text: carte.qr_url||location.href, width:68, height:68, colorDark:'#111', colorLight:'#fff', correctLevel:QRCode.CorrectLevel.M });
    }
    genQR();

    // ── Verso ──────────────────────────────────────────────────
    var versoStars = '';
    if (carte.club_labelise > 0) {
        for (var j=1; j<=5; j++)
            versoStars += '<span style="color:'+(j<=carte.club_labelise?'#e30613':'rgba(255,255,255,0.12)')+';font-size:8px;">★</span>';
    }
    var footerParts = [carte.club_affil, carte.club_num ? 'Club '+carte.club_num : '', (carte.club_nom||'').toLowerCase().replace(/\s/g,'')+'.fr'].filter(Boolean).join(' · ');

    document.getElementById('spcal-verso').innerHTML =
        '<div class="sp-carte-verso-header">'+
            '<div class="sp-carte-verso-logo-wrap">'+
                (carte.logo_url ? '<img src="'+esc(carte.logo_url)+'" class="sp-carte-verso-logo" onerror="this.style.display=\'none\'">' : '')+
            '</div>'+
            '<span class="sp-carte-verso-title">'+esc((carte.club_nom||'').toUpperCase())+'</span>'+
            '<span class="sp-carte-verso-badge">'+esc(el.prenom+' '+el.nom)+'</span>'+
        '</div>'+
        '<div class="sp-carte-verso-body">'+
            '<div class="sp-carte-taegeuk">태권도</div>'+
            '<div class="sp-carte-officiel">Carte de membre officielle</div>'+
        '</div>'+
        '<div class="sp-carte-verso-footer">'+
            '<span>'+esc(footerParts)+'</span>'+
            (versoStars ? '<span>'+versoStars+'</span>' : '')+
        '</div>';

    // Hint installation
    var isIOS     = /iphone|ipad|ipod/i.test(navigator.userAgent);
    var isAndroid = /android/i.test(navigator.userAgent);
    var hint = document.getElementById('spcal-install-hint');
    var txt  = document.getElementById('spcal-install-text');
    if (isIOS) {
        txt.innerHTML = 'Pour installer\u00a0: appuyez sur <strong>Partager \u2b06\ufe0e</strong> puis <strong>Sur l\u2019\u00e9cran d\u2019accueil</strong>.';
        hint.style.display = 'flex';
    } else if (isAndroid) {
        txt.innerHTML = 'Pour installer\u00a0: appuyez sur le menu \u22ee de Chrome puis <strong>Ajouter \u00e0 l\u2019\u00e9cran d\u2019accueil</strong>.';
        hint.style.display = 'flex';
    }
    // Progression de grade
    renderGradeProgression();
}

function renderCalendrier() {
    if (!gCours.length) {
        document.getElementById('spcal-cal-list').innerHTML = '<div class="spcal-empty">Aucun cours à venir</div>';
        return;
    }
    var html = '';
    var lastMonth = '';
    gCours.forEach(function(c) {
        var mois = monthLabel(c.date);
        if (mois !== lastMonth) {
            html += '<div class="spcal-cal-month">'+esc(mois)+'</div>';
            lastMonth = mois;
        }
        var today    = isToday(c.date);
        var tomorrow = isTomorrow(c.date);
        var cls      = today ? ' today' : (tomorrow ? ' tomorrow' : '');
        var badge    = today ? '<span class="spcal-cours-badge spcal-badge-today">Aujourd\'hui</span>'
                     : (tomorrow ? '<span class="spcal-cours-badge spcal-badge-tomorrow">Demain</span>' : '');
        html +=
            '<div class="spcal-cours-card'+cls+'">'+
            '<div class="spcal-cours-left">'+
            '<div class="spcal-cours-titre">'+esc(c.titre)+'</div>'+
            (c.categorie ? '<div class="spcal-cours-cat">'+esc(c.categorie)+'</div>' : '')+spCalEncadrementHtml(c)+
            '</div>'+
            '<div class="spcal-cours-right">'+
            '<div class="spcal-cours-heure">'+esc(fmtHeure(c.heure_debut))+'</div>'+
            '<div class="spcal-cours-date">'+esc(fmtDateJour(c.date))+'</div>'+
            badge+
            '</div>'+
            '</div>';
    });
    document.getElementById('spcal-cal-list').innerHTML = html;
}

/* ── Mon prochain grade (onglet Carte) ───────────────────────────
   Données : gEleve.parcours (SP_Cal_Passages::donnees_eleve()) — programme du grade suivant
   dans TKD Parcours, passage à venir, retour du dernier passage validé. Sans TKD Parcours,
   repli sur l'ancien affichage (grade_vise de la table de progression). */
var SPCAL_NIV = { 2: ['Acquis', 'ok'], 1: ['À revoir', 'rev'], 0: ['Non acquis', 'non'] };
var SPCAL_COUL = { blanche:'#f8fafc', jaune:'#facc15', orange:'#fb923c', verte:'#22c55e', violette:'#8b5cf6', bleue:'#3b82f6', rouge:'#ef4444', noire:'#111' };

function spCalCeinture(grade) {
    var g = String(grade || '').toLowerCase(), cols = [];
    if (/poom/.test(g)) cols = [SPCAL_COUL.rouge, SPCAL_COUL.noire];
    else if (/\bdan\b/.test(g)) cols = [SPCAL_COUL.noire];
    else g.replace(/\*/g, '').split(/[\s\/]+/).forEach(function(m){ if (SPCAL_COUL[m]) cols.push(SPCAL_COUL[m]); });
    if (!cols.length) cols = ['#475569'];
    var fond = cols.length > 1 ? 'linear-gradient(90deg,' + cols[0] + ' 50%,' + cols[1] + ' 50%)' : cols[0];
    return '<span class="spcal-pg-belt"><i style="background:' + fond + '"></i>' + esc(grade) + '</span>';
}
function spCalNote(n) { return (Math.round(n * 100) / 100).toString().replace('.', ','); }
function spCalLienVideo(url, txt) {
    return /^https?:\/\//.test(url || '') ? '<a class="spcal-pg-video" href="' + esc(url) + '" target="_blank" rel="noopener">▶ ' + esc(txt) + '</a>' : '';
}

function renderGradeProgression() {
    var el   = gEleve;
    var wrap = document.getElementById('spcal-grade-progression');
    if (!wrap) return;
    renderGradeRappel();
    var pc = el.parcours;
    if (!pc) {
        wrap.innerHTML = el.grade_vise ? '<div class="spcal-grade-section-title">Prochain grade</div>' +
            '<div class="spcal-grade-next"><strong>' + esc(el.grade_vise) + '</strong></div>' : '';
        return;
    }
    var h = '', r = pc.retour, p = pc.prochain;

    // Retour du dernier passage
    if (r) {
        var admis = r.decision === 'admis';
        h += '<div class="spcal-grade-section-title">Dernier passage de grade</div>'
           + '<div class="spcal-pg-card ' + (admis ? 'admis' : 'ajourne') + '">'
           + '<div class="spcal-pg-res">' + (admis ? '🎉 Admis(e) : ' + spCalCeinture(r.grade_obtenu) : 'Pas encore validé : ' + spCalCeinture(r.grade_vise)) + '</div>'
           + '<div class="spcal-pg-sub">' + esc(r.date_fr) + (r.moyenne != null ? ' · moyenne ' + spCalNote(r.moyenne) + '/10' : '') + '</div>'
           + '<ul class="spcal-pg-crits">';
        r.criteres.forEach(function(c) {
            var badge = c.note != null ? '<span class="spcal-pg-badge">' + spCalNote(c.note) + '/10</span>'
                      : c.niveau != null ? '<span class="spcal-pg-badge ' + SPCAL_NIV[c.niveau][1] + '">' + SPCAL_NIV[c.niveau][0] + '</span>' : '';
            h += '<li><span><strong>' + esc(c.libelle) + '</strong>' + (c.detail ? '<small>' + esc(c.detail) + '</small>' : '') + '</span>' + badge + '</li>';
        });
        h += '</ul>';
        if (r.a_revoir.length) {
            h += '<div class="spcal-pg-revoir"><strong>À travailler :</strong> ' + r.a_revoir.map(esc).join(', ')
               + (admis ? '' : '<br>Courage : retravaillez ces points pour le prochain passage.') + '</div>'
               + spCalLienVideo(r.video_url, 'Revoir la vidéo du programme');
        }
        r.remarques.forEach(function(t) { h += '<div class="spcal-pg-rem">« ' + esc(t) + ' »</div>'; });
        h += '</div>';
    }

    // Prochain grade et son programme
    if (p) {
        h += '<div class="spcal-grade-section-title">Mon prochain grade</div><div class="spcal-pg-card">'
           + '<div class="spcal-pg-res">' + spCalCeinture(p.grade) + '</div>';
        if (pc.prevu) h += '<div class="spcal-pg-prevu">📅 Inscrit(e) au passage de grade du ' + esc(pc.prevu.date_fr) + '</div>';
        if (p.dan) {
            h += '<p class="spcal-pg-sub">L\'examen de ceinture noire (Dan) se passe hors du club : parlez-en avec votre entraîneur.</p>';
        } else {
            if (p.programme && p.programme.length) {
                h += '<div class="spcal-pg-sub">Programme à préparer</div><ul class="spcal-pg-crits">';
                p.programme.forEach(function(c) {
                    h += '<li><span><strong>' + esc(c.libelle) + '</strong><small>' + esc(c.detail) + '</small></span></li>';
                });
                h += '</ul>';
            }
            if (p.ans_avant > 0) h += '<p class="spcal-pg-sub">Âge conseillé pour ce grade : ' + esc(p.min_age) + ' ans.</p>';
            h += spCalLienVideo(p.video_url, 'Voir la vidéo du programme');
        }
        h += '</div>';
    } else if (pc.prevu) {
        h += '<div class="spcal-grade-section-title">Mon prochain grade</div><div class="spcal-pg-card">'
           + '<div class="spcal-pg-prevu">📅 Inscrit(e) au passage de grade du ' + esc(pc.prevu.date_fr) + (pc.prevu.grade_vise ? ' : ' + spCalCeinture(pc.prevu.grade_vise) : '') + '</div></div>';
    }
    wrap.innerHTML = h;
}

/* Rappel sur l'Accueil quand l'adhérent est inscrit à un passage à venir (renvoie vers l'onglet Carte). */
function renderGradeRappel() {
    var wrap = document.getElementById('spcal-grade-rappel');
    if (!wrap) return;
    var pc = gEleve && gEleve.parcours;
    if (!pc || !pc.prevu) { wrap.innerHTML = ''; return; }
    wrap.innerHTML = '<button class="spcal-fiche-link spcal-pg-rappel" onclick="spCalNav(\'carte\', document.getElementById(\'spcal-nav-carte\'))">'
        + '<span class="spcal-fl-ico">🥋</span><span><strong>Passage de grade</strong><small>' + esc(pc.prevu.date_fr)
        + (pc.prevu.grade_vise ? ' · objectif ' + esc(pc.prevu.grade_vise) : '') + ' — voir le programme</small></span><span class="spcal-fl-go">›</span></button>';
}

/* ── Événements & Inscriptions ───────────────────────────────── */
function renderEvenements() {
    var listEl = document.getElementById('spcal-evt-list');
    if (!listEl) return;

    // Agenda du club sur 3 mois (hors cours et anniversaires) : « Vous concerne » pour ce qui
    // vise l'adhérent, « Inscription ouverte » quand il peut répondre (décision du 26/09/2026).
    apiFetch('/eleve/evenements')
    .then(function(r) {
        var evts   = r.evenements || [];
        var filtre = STORE.get('spcal_evt_filtre') === 'moi' ? 'moi' : 'tout';
        var shown  = filtre === 'moi' ? evts.filter(function(ev){ return ev.concerne; }) : evts;

        var html = '<div class="spcal-evt-filtre">' +
            '<button class="' + (filtre === 'tout' ? 'active' : '') + '" onclick="pwaEvtFiltre(\'tout\')">Tout</button>' +
            '<button class="' + (filtre === 'moi'  ? 'active' : '') + '" onclick="pwaEvtFiltre(\'moi\')">Me concerne</button>' +
            '</div>';

        if (!shown.length) {
            listEl.innerHTML = html + '<div class="spcal-empty">' +
                (filtre === 'moi' ? 'Aucun événement ne vous concerne dans les 3 prochains mois' : 'Aucun événement dans les 3 prochains mois') +
                '</div>';
            return;
        }
        shown.forEach(function(ev) {
            var statut  = ev.statut_insc || 'en_attente';
            var insc    = !!ev.inscription;
            var dlOk    = !ev.inscriptions_deadline || ev.inscriptions_deadline >= new Date().toISOString().slice(0,10);
            var dateStr = ev.date ? ('\ud83d\udcc5 ' + fmtDate(ev.date) + (ev.heure_debut ? ' \u00b7 ' + fmtHeure(ev.heure_debut) : '')) : '';
            var tags    = '';
            if (ev.concerne) tags += '<span class="spcal-evt-tag spcal-evt-tag-moi">\ud83c\udfaf Vous concerne</span>';
            if (insc)        tags += '<span class="spcal-evt-tag spcal-evt-tag-insc">\ud83d\udcdd Inscription ouverte</span>';
            var dlStr   = (insc && ev.inscriptions_deadline && dlOk)
                ? '<div class="spcal-evt-deadline">\u23f0 R\u00e9pondre avant le ' + fmtDate(ev.inscriptions_deadline) + '</div>' : '';
            var msgStr  = (insc && ev.inscriptions_message)
                ? '<div class="spcal-evt-msg">' + esc(ev.inscriptions_message) + '</div>' : '';

            var actionsHtml = '';
            if (insc && !dlOk) {
                var badgeCls = statut === 'inscrit' ? 'spcal-evt-badge-ok'
                             : statut === 'refuse'  ? 'spcal-evt-badge-ko'
                             :                        'spcal-evt-badge-wait';
                var badgeLbl = statut === 'inscrit' ? '\u2705 Inscrit(e)'
                             : statut === 'refuse'  ? '\u274c D\u00e9clin\u00e9'
                             :                        '\u23f3 Sans r\u00e9ponse';
                actionsHtml = '<span class="spcal-evt-badge ' + badgeCls + '">' + badgeLbl + '</span>' +
                              '<div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:6px;">\u23f0 D\u00e9lai d\u00e9pass\u00e9</div>';
            } else if (insc && statut === 'inscrit') {
                actionsHtml =
                    '<span class="spcal-evt-badge spcal-evt-badge-ok">\u2705 Inscrit(e)</span>' +
                    '<button class="spcal-evt-btn spcal-evt-btn-annuler" onclick="pwaInscRepondre(' + ev.id + ',\'non\',this)">\u274c Annuler</button>';
            } else if (insc && statut === 'refuse') {
                actionsHtml =
                    '<span class="spcal-evt-badge spcal-evt-badge-ko">\u274c D\u00e9clin\u00e9</span>' +
                    '<button class="spcal-evt-btn spcal-evt-btn-oui" onclick="pwaInscRepondre(' + ev.id + ',\'oui\',this)">Je participe finalement</button>';
            } else if (insc) {
                actionsHtml =
                    '<button class="spcal-evt-btn spcal-evt-btn-oui" onclick="pwaInscRepondre(' + ev.id + ',\'oui\',this)">\u2705 Je participe</button>' +
                    '<button class="spcal-evt-btn spcal-evt-btn-non" onclick="pwaInscRepondre(' + ev.id + ',\'non\',this)">\u274c Je ne peux pas</button>';
            }

            var cardCls = !ev.concerne ? ' statut-info'
                        : !insc ? ' statut-moi'
                        : statut === 'inscrit' ? ' statut-inscrit'
                        : statut === 'refuse'  ? ' statut-refuse' : ' statut-insc';
            html +=
                '<div class="spcal-evt-card' + cardCls + '" id="spcal-evt-' + ev.id + '">' +
                (tags ? '<div class="spcal-evt-tags">' + tags + '</div>' : '') +
                '<div class="spcal-evt-titre">' + esc(ev.titre) + '</div>' +
                '<div class="spcal-evt-meta">' + dateStr + '</div>' +
                dlStr + msgStr +
                (actionsHtml ? '<div class="spcal-evt-actions">' + actionsHtml + '</div>' : '') +
                '</div>';
        });
        listEl.innerHTML = html;
    })
    .catch(function() {
        listEl.innerHTML = '<div class="spcal-empty">Erreur de chargement</div>';
    });
}

window.pwaEvtFiltre = function(f) {
    STORE.set('spcal_evt_filtre', f === 'moi' ? 'moi' : 'tout');
    renderEvenements();
};

window.pwaInscRepondre = function(eventId, reponse, btn) {
    var token = STORE.get('spcal_token');
    if (!token) return;
    var card  = document.getElementById('spcal-evt-' + eventId);
    var actEl = card ? card.querySelector('.spcal-evt-actions') : null;
    if (actEl) actEl.innerHTML = '<span class="spcal-evt-loading">Enregistrement\u2026</span>';

    var fd = new FormData();
    fd.append('action',   'sp_inscription_repondre');
    fd.append('token',    token);
    fd.append('event_id', eventId);
    fd.append('reponse',  reponse);

    fetch(CFG.ajaxurl || '/wp-admin/admin-ajax.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(r) {
        if (!r.success) {
            if (actEl) actEl.innerHTML = '<span class="spcal-evt-loading">Erreur\u2014 r\u00e9essayez.</span>';
            return;
        }
        var ok  = r.data.statut === 'inscrit';
        if (card) card.className = 'spcal-evt-card ' + (ok ? 'statut-inscrit' : 'statut-refuse');
        if (actEl) {
            actEl.innerHTML = ok
                ? '<span class="spcal-evt-badge spcal-evt-badge-ok">\u2705 Inscrit(e)</span>' +
                  '<button class="spcal-evt-btn spcal-evt-btn-annuler" onclick="pwaInscRepondre(' + eventId + ',\'non\',this)">\u274c Annuler</button>'
                : '<span class="spcal-evt-badge spcal-evt-badge-ko">\u274c D\u00e9clin\u00e9</span>' +
                  '<button class="spcal-evt-btn spcal-evt-btn-oui" onclick="pwaInscRepondre(' + eventId + ',\'oui\',this)">Je participe finalement</button>';
        }
    })
    .catch(function() {
        if (actEl) actEl.innerHTML = '<span class="spcal-evt-loading">Erreur r\u00e9seau.</span>';
    });
}

/* ── Navigation ─────────────────────────────────────────────── */
window.spCalNav = function(screen, btn) {
    document.querySelectorAll('.spcal-screen').forEach(function(s){ s.classList.remove('active'); });
    document.querySelectorAll('.spcal-nav-btn').forEach(function(b){ b.classList.remove('active'); });
    var s = document.getElementById('spcal-'+screen);
    if (s) s.classList.add('active');
    if (btn) btn.classList.add('active');
};

/* ── Flip carte ──────────────────────────────────────────────── */
window.spCalFlipCard = function() {
    var inner = document.getElementById('spcal-card-inner');
    if (inner) inner.classList.toggle('flipped');
};

/* ── Erreur ──────────────────────────────────────────────────── */
function showError(msg) {
    hide('spcal-loading');
    hide('spcal-app');
    var err = document.getElementById('spcal-error');
    if (err) err.style.display = 'flex';
    var msgEl = document.getElementById('spcal-error-msg');
    if (msgEl) msgEl.innerHTML = msg;
}

/* ── Service Worker ──────────────────────────────────────────── */
function registerSW() {
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.register(CFG.swUrl || '/?spcal_sw=1', { scope: '/' })
        .catch(function(e){ console.warn('[PWA] SW non enregistré:', e); });
}

/* ── Démarrage ───────────────────────────────────────────────── */
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}


/* ── POINTAGE ENTRAÎNEURS ────────────────────────────────────── */
var gPin = null;
var gPtgCours = null; // cours sélectionné pour le scan
var gScanActive = false;
var gScanStream = null;
var gPtgLog = [];

window.spCalPinSubmit = function() {
    var pin = (document.getElementById('spcal-pin-input').value || '').trim();
    if (!pin) return;
    document.getElementById('spcal-pin-error').style.display = 'none';

    var today = new Date().toISOString().slice(0,10);
    fetch(CFG.apiBase + '/pointage/cours', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({pin: pin, date: today})
    })
    .then(function(r){ return r.json(); })
    .then(function(r) {
        if (!r.success) {
            var errEl = document.getElementById('spcal-pin-error');
            errEl.textContent = r.data || 'PIN invalide';
            errEl.style.display = 'block';
            return;
        }
        gPin = pin;
        STORE.set('spcal_pin', pin);
        spCalShowPointageDashboard(r.data, today);
    })
    .catch(function() {
        var errEl = document.getElementById('spcal-pin-error');
        errEl.textContent = 'Erreur de connexion';
        errEl.style.display = 'block';
    });
};

// Permettre la touche Entrée sur le champ PIN
document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('spcal-pin-input');
    if (input) input.addEventListener('keydown', function(e){ if(e.key==='Enter') spCalPinSubmit(); });
    // Essayer de restaurer le PIN depuis le stockage local
    var savedPin = STORE.get('spcal_pin');
    if (savedPin) {
        var today = new Date().toISOString().slice(0,10);
        fetch(CFG.apiBase + '/pointage/cours', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({pin: savedPin, date: today})
        }).then(function(r){ return r.json(); }).then(function(r){
            if (r.success) { gPin = savedPin; spCalShowPointageDashboard(r.data, today); }
            else { STORE.del('spcal_pin'); }
        }).catch(function(){});
    }
});

function spCalShowPointageDashboard(cours, date) {
    document.getElementById('spcal-pin-screen').style.display = 'none';
    document.getElementById('spcal-pointage-dashboard').style.display = 'block';
    // Date lisible
    var d = new Date(date);
    document.getElementById('spcal-ptg-date').textContent =
        d.toLocaleDateString('fr-FR', {weekday:'long', day:'numeric', month:'long'});
    // Liste des cours
    var html = '';
    if (!cours || !cours.length) {
        html = '<div class="spcal-empty">Aucun cours aujourd&#39;hui</div>';
    } else {
        cours.forEach(function(c) {
            html += '<button class="spcal-ptg-cours-btn" onclick="spCalSelectCours('+JSON.stringify(c).replace(/"/g,'&quot;')+')">'+
                '<div class="spcal-ptg-cours-left">'+
                '<div class="spcal-ptg-cours-btn-titre">'+esc(c.titre)+'</div>'+
                (c.categorie ? '<div class="spcal-ptg-cours-btn-cat">'+esc(c.categorie)+'</div>' : '')+
                '</div>'+
                '<div class="spcal-ptg-cours-heure">'+esc(fmtHeure(c.heure_debut))+'</div>'+
                '</button>';
        });
    }
    document.getElementById('spcal-ptg-cours-list').innerHTML = html;
    spCalLoadAnniv(date);
}

/* ── Anniversaires du mois (class-anniversaires.php) ─────────────
   Pour que l'entraîneur sache QUI on fête à la fin du cours. Liste complète sous les
   cours (par catégorie), et rappel limité à la catégorie du cours une fois choisi. */
var gAnniv = null;
function spCalAnnivNorm(s) {
    return (s || '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z]/g, '');
}
function spCalAnnivMemeCategorie(catEleve, catCours) {
    var a = spCalAnnivNorm(catEleve), b = spCalAnnivNorm(catCours);
    if (!a || !b) return false;
    if (a.indexOf(b) !== -1 || b.indexOf(a) !== -1) return true;
    var adulte = /ado|adulte/;
    return adulte.test(a) && adulte.test(b);
}
function spCalAnnivHtml(liste, titre) {
    var h = '<div class="spcal-anniv"><div class="spcal-anniv-titre">' + titre + '</div>';
    var cat = null;
    liste.forEach(function(a) {
        if (titre.indexOf('du mois') !== -1 && a.categorie !== cat) {
            cat = a.categorie;
            h += '<div class="spcal-anniv-cat">' + esc(cat || 'Sans catégorie') + '</div>';
        }
        h += '<div class="spcal-anniv-ligne' + (a.passe ? ' passe' : '') + (a.aujourdhui ? ' aujourdhui' : '') + '">' +
             '<span class="spcal-anniv-jour">' + a.jour + '</span>' +
             '<span class="spcal-anniv-nom">' + esc(a.nom) + (a.aujourdhui ? ' — aujourd&#39;hui !' : '') + '</span>' +
             (a.age ? '<span class="spcal-anniv-age">' + a.age + ' ans</span>' : '') +
             '</div>';
    });
    return h + '</div>';
}
function spCalLoadAnniv(date) {
    var box = document.getElementById('spcal-ptg-anniv');
    if (!box || !gPin) return;
    fetch(CFG.apiBase + '/anniversaires', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({pin: gPin, date: date})
    })
    .then(function(r){ return r.json(); })
    .then(function(r) {
        if (!r || !r.success) { box.innerHTML = ''; return; }
        gAnniv = r;
        if (!r.data.length) {
            box.innerHTML = '<div class="spcal-anniv"><div class="spcal-anniv-titre">🎂 Anniversaires du mois</div><div class="spcal-anniv-vide">Aucun anniversaire en ' + esc(r.mois) + '.</div></div>';
            return;
        }
        var parCat = r.data.slice().sort(function(a, b) {
            return (a.categorie || '').localeCompare(b.categorie || '') || a.jour - b.jour;
        });
        box.innerHTML = spCalAnnivHtml(parCat, '🎂 Anniversaires du mois (' + esc(r.mois) + ')');
    })
    .catch(function(){ box.innerHTML = ''; });
}
function spCalShowAnnivCours(cours) {
    var box = document.getElementById('spcal-ptg-anniv-cours');
    if (!box) return;
    if (!gAnniv || !gAnniv.data || !cours || !cours.categorie) { box.innerHTML = ''; return; }
    var liste = gAnniv.data.filter(function(a) { return spCalAnnivMemeCategorie(a.categorie, cours.categorie); });
    box.innerHTML = liste.length
        ? spCalAnnivHtml(liste, '🎂 À fêter dans ce cours (' + esc(gAnniv.mois) + ')')
        : '';
}

window.spCalSelectCours = function(cours) {
    gPtgCours = cours;
    gPtgLog = [];
    document.getElementById('spcal-cours-list-wrap').style.display = 'none';
    document.getElementById('spcal-scan-wrap').style.display = 'block';
    document.getElementById('spcal-ptg-cours-title').textContent =
        cours.titre + (cours.heure_debut ? ' · ' + fmtHeure(cours.heure_debut) : '');
    spCalShowAnnivCours(cours);
    document.getElementById('spcal-ptg-log').innerHTML = '';
    document.getElementById('spcal-scan-feedback').style.display = 'none';
    // Nouveau cours : aucune carte encore traitée, pas de pause en cours.
    gScanVus = {};
    gLastScan = '';
    gScanEssai++; // une vérification encore en route pour l'ancien cours sera ignorée
    clearTimeout(gScanRepriseTimer);
    gScanPause = false;
    var ov = document.getElementById('spcal-scan-overlay');
    if (ov) ov.style.display = 'none';
    spCalStartScan();
};

window.spCalBackToCours = function() {
    spCalStopScan();
    document.getElementById('spcal-scan-wrap').style.display = 'none';
    document.getElementById('spcal-cours-list-wrap').style.display = 'block';
    gPtgCours = null;
};

window.spCalPinLogout = function() {
    spCalStopScan();
    gPin = null;
    STORE.del('spcal_pin');
    document.getElementById('spcal-pin-input').value = '';
    document.getElementById('spcal-pin-error').style.display = 'none';
    document.getElementById('spcal-pointage-dashboard').style.display = 'none';
    document.getElementById('spcal-scan-wrap').style.display = 'none';
    document.getElementById('spcal-cours-list-wrap').style.display = 'block';
    document.getElementById('spcal-pin-screen').style.display = 'block';
};

/* ── Scanner QR (API BarcodeDetector ou jsQR, repli multi-CDN) ──
   jsQR depuis cdnjs.cloudflare.com est bloqué silencieusement par l'hébergeur
   OVH (cf. md/SPCalendarPRO(sp-build)—Bug.md, 17/04/2026 — même bug déjà
   résolu sur le scanner /pointage/?pin= de class-token.php, jamais reporté
   ici) : on charge depuis jsdelivr puis unpkg en repli, jamais cdnjs. ── */
function spCalLoadJsQR(cb) {
    if (typeof jsQR !== 'undefined') { cb(); return; }
    var cdns = [
        // Copie locale du plugin d'abord (si présente), puis CDN de repli.
        '<?php echo esc_js( SP_CAL_PRO_URL . 'assets/js/jsQR.min.js' ); ?>',
        'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js',
        'https://unpkg.com/jsqr@1.4.0/dist/jsQR.js'
    ];
    var idx = 0;
    function tryLoad() {
        if (idx >= cdns.length) { cb(); return; }
        var s = document.createElement('script');
        s.src = cdns[idx++];
        s.onload = cb;
        s.onerror = tryLoad;
        document.head.appendChild(s);
    }
    tryLoad();
}

function spCalScanShowError(titre, detail) {
    var fb = document.getElementById('spcal-scan-feedback');
    fb.className = 'spcal-scan-err';
    fb.style.display = 'block';
    document.getElementById('spcal-scan-nom').textContent = titre;
    document.getElementById('spcal-scan-msg').textContent = detail;
}

function spCalStartScan() {
    if (gScanActive) return;
    gScanActive = true;
    var video = document.getElementById('spcal-qr-video');
    // Diagnostic temporaire (doléances 10/09/2026) : navigator.mediaDevices peut être
    // absent selon le contexte iOS/WebKit -- appeler .getUserMedia() dessus lèverait
    // alors une exception synchrone AVANT la promesse, donc jamais interceptée par le
    // .catch() ci-dessous -- d'où le "rien ne se passe" sans la moindre erreur visible.
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        spCalScanShowError('📷 API caméra indisponible', 'navigator.mediaDevices absent sur ce navigateur/contexte.');
        return;
    }
    try {
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        .then(function(stream) {
            gScanStream = stream;
            video.srcObject = stream;
            video.play();
            // 01/10/2026 — même ordre que le scanner d'identification (class-token.php), qui
            // fonctionne sur les téléphones du club : lecteur intégré du navigateur
            // (BarcodeDetector) d'abord, jsQR seulement s'il est absent. Le forçage de jsQR
            // seul (10/09/2026) laissait la caméra « muette » (cadre vert, aucune réaction).
            // 01/10/2026 (2e retour terrain : « tourne dans le vide ») : sur certains
            // téléphones le lecteur intégré existe mais ne détecte rien ou renvoie des
            // erreurs ignorées. Les deux lecteurs tournent donc en parallèle : le premier
            // qui lit gagne (le doublon est filtré par spCalHandleScanResult).
            gScanLecteurs = { natif: typeof BarcodeDetector !== 'undefined', jsqr: false };
            if (gScanLecteurs.natif) spCalScanWithBarcodeDetector(video);
            spCalScanEtat();
            spCalLoadJsQR(function(){
                if (!gScanActive) return;
                if (typeof jsQR !== 'undefined') {
                    gScanLecteurs.jsqr = true;
                    spCalScanEtat();
                    spCalScanWithJsQR(video);
                } else if (!gScanLecteurs.natif) {
                    spCalScanShowError('📷 Lecteur QR non chargé', 'Ni le lecteur intégré du navigateur ni jsQR ne sont disponibles. Vérifiez la connexion puis rechargez la page.');
                }
            });
        })
        .catch(function(e) {
            spCalScanShowError('📷 Caméra inaccessible', (e && (e.name + ' : ' + e.message)) || 'Autorisez l\'accès à la caméra.');
        });
    } catch (e) {
        spCalScanShowError('📷 Erreur scan', (e && (e.name + ' : ' + e.message)) || String(e));
    }
}

function spCalStopScan() {
    gScanActive = false;
    if (gScanStream) {
        gScanStream.getTracks().forEach(function(t){ t.stop(); });
        gScanStream = null;
    }
}

var gScanLecteurs = { natif: false, jsqr: false };

/* Petite ligne d'état sous la caméra : quel lecteur analyse l'image (aide au diagnostic sur le terrain). */
function spCalScanEtat() {
    var el = document.getElementById('spcal-scan-etat');
    if (!el) return;
    var l = [];
    if (gScanLecteurs.natif) l.push('lecteur du téléphone');
    if (gScanLecteurs.jsqr)  l.push('jsQR');
    el.textContent = l.length ? 'Lecture active : ' + l.join(' + ') : 'Démarrage de la lecture…';
}

function spCalScanWithBarcodeDetector(video) {
    var detector, echecs = 0;
    try { detector = new BarcodeDetector({ formats: ['qr_code'] }); }
    catch (e) { gScanLecteurs.natif = false; spCalScanEtat(); return; } // jsQR prend le relais
    function tick() {
        if (!gScanActive || !gScanLecteurs.natif) return;
        // iOS : readyState n'atteint pas toujours HAVE_ENOUGH_DATA (4), >=2 suffit
        if (video.readyState >= 2 && video.videoWidth > 0) {
            detector.detect(video).then(function(codes) {
                echecs = 0;
                if (codes.length > 0) spCalHandleScanResult(codes[0].rawValue);
            }).catch(function() {
                // Lecteur intégré défaillant sur cet appareil : on l'arrête, jsQR continue seul.
                if (++echecs >= 5) { gScanLecteurs.natif = false; spCalScanEtat(); }
            });
        }
        setTimeout(tick, 300);
    }
    tick();
}

function spCalScanWithJsQR(video) {
    var canvas = document.getElementById('spcal-qr-canvas');
    var ctx = canvas.getContext('2d', { willReadFrequently: true });
    function tick() {
        if (!gScanActive) return;
        if (video.readyState >= 2 && video.videoWidth > 0 && typeof jsQR !== 'undefined') {
            // Image réduite (800 px max) : analyser la vidéo en pleine résolution à chaque
            // image saturait le téléphone, sans détection. Même réglage d'inversion que le
            // scanner d'identification (par défaut : codes clairs sur fond sombre aussi).
            var echelle = Math.min(1, 800 / video.videoWidth);
            canvas.width  = Math.round(video.videoWidth * echelle);
            canvas.height = Math.round(video.videoHeight * echelle);
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = jsQR(img.data, img.width, img.height);
            if (code && code.data) spCalHandleScanResult(code.data);
        }
        setTimeout(tick, 150);
    }
    tick();
}

var gLastScan = '';
var gLastScanTime = 0;
/* 04/10/2026 (retour terrain : le nom s'affichait sous la caméra, hors écran, et une carte laissée
   devant l'objectif répétait « déjà relevé »). Le résultat s'affiche désormais DANS l'image, la
   lecture est en pause pendant l'affichage puis reprend seule, et une carte déjà traitée pour ce
   cours est ignorée sans message. La caméra et les deux lecteurs ne sont jamais arrêtés pour
   cette pause (leur démarrage est la partie fragile) : seules les lectures sont ignorées. */
var gScanPause = false;        // résultat affiché : lectures ignorées jusqu'à la reprise
var gScanVus = {};             // cartes déjà traitées pour le cours en cours (jeton ou contenu lu)
var gScanRepriseTimer = null;
var gScanEssai = 0;            // numéro de la carte en cours de vérification (réponses tardives ignorées)
var SPCAL_SCAN_REPRISE_MS = 2000;

function spCalHandleScanResult(raw) {
    if (gScanPause) return;
    // Dédupliquer : ignorer si même token dans les 3 dernières secondes
    var now = Date.now();
    if (raw === gLastScan && now - gLastScanTime < 3000) return;
    gLastScan = raw;
    gLastScanTime = now;

    // Extraire le token depuis l'URL ?token=xxx ou valeur brute 64hex
    var token = '';
    if (/^[0-9a-f]{64}$/.test(raw)) {
        token = raw;
    } else {
        try {
            var u = new URL(raw);
            token = u.searchParams.get('token') || '';
        } catch(e) { token = ''; }
    }
    // Carte déjà traitée pour ce cours : ignorée sans message (plus de « déjà relevé » en boucle).
    var cle = token || raw;
    if (gScanVus[cle]) return;
    // Ne plus jamais rester muet : un QR lu mais inattendu le dit à l'écran.
    if (!token) { gScanVus[cle] = true; spCalShowFeedback('QR code non reconnu', 'Ce n\'est pas une carte de membre du club.', 'err'); return; }
    if (!gPtgCours || !gPin) { spCalShowFeedback('Aucun cours choisi', 'Revenez à la liste et sélectionnez le cours.', 'err'); return; }

    gScanPause = true;
    spCalScanOverlay('⏳ Lecture de la carte…', '', 'wait');
    // Réseau très lent : ne jamais laisser le scanner bloqué sur « Lecture… ». Une réponse
    // arrivée après ce délai est ignorée (sinon elle remettrait en pause le scan de l'élève
    // suivant) : la carte rescannée affichera « déjà relevé » si la présence est passée.
    var essai = ++gScanEssai;
    var attente = setTimeout(function() {
        gScanEssai++;
        spCalShowFeedback('Pas de réponse du serveur', 'Rescannez la carte.', 'err');
    }, 8000);
    fetch(CFG.apiBase + '/pointage/scan', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            pin:     gPin,
            token:   token,
            slot_id: gPtgCours.slot_id,
            date:    gPtgCours.date
        })
    })
    .then(function(r){ return r.json(); })
    .then(function(r) {
        var nom = (r.data && r.data.nom) ? r.data.nom : '?';
        var status = (r.data && r.data.status) ? r.data.status : (r.success ? 'ok' : 'err');
        var msg  = (r.data && r.data.msg)  ? r.data.msg  : (r.success ? 'Présent' : (r.data || 'Erreur'));
        if (essai !== gScanEssai) return; // réponse trop tardive ou cours quitté
        clearTimeout(attente);
        gScanVus[token] = true; // le serveur a répondu : cette carte est traitée pour ce cours
        spCalShowFeedback(nom, msg, status);
        if (r.success) spCalAddLog(nom, status);
    })
    // Erreur réseau : la carte n'est pas marquée, on pourra la rescanner.
    .catch(function() {
        if (essai !== gScanEssai) return;
        clearTimeout(attente);
        spCalShowFeedback('Erreur réseau', 'Rescannez la carte.', 'err');
    });
}

/* Résultat affiché dans l'image de la caméra, lecture en pause, reprise automatique. */
function spCalShowFeedback(nom, msg, status) {
    var st = status === 'ok' ? 'ok' : (status === 'already' ? 'already' : 'err');
    spCalScanOverlay((st === 'ok' ? '✅ ' : (st === 'already' ? '⚠️ ' : '❌ ')) + nom, msg, st);
    spCalScanFlash(status);
    gScanPause = true;
    clearTimeout(gScanRepriseTimer);
    gScanRepriseTimer = setTimeout(spCalScanReprendre, SPCAL_SCAN_REPRISE_MS);
}

function spCalScanOverlay(nom, msg, cls) {
    var ov = document.getElementById('spcal-scan-overlay');
    if (!ov) return;
    ov.className = 'spcal-scan-overlay ' + cls;
    document.getElementById('spcal-scan-ov-nom').textContent = nom;
    document.getElementById('spcal-scan-ov-msg').textContent = msg;
    ov.style.display = 'flex';
}

/* Fin de la pause (automatique, ou en touchant le bandeau) : les lectures sont de nouveau prises en compte. */
window.spCalScanReprendre = function() {
    // Pendant la vérification d'une carte (bandeau « Lecture… »), on attend la réponse.
    var ov = document.getElementById('spcal-scan-overlay');
    if (ov && ov.className.indexOf('wait') !== -1 && gScanPause) return;
    clearTimeout(gScanRepriseTimer);
    gScanPause = false;
    if (ov) ov.style.display = 'none';
};

/* Éclair sur le cadre + courte vibration (si le téléphone le permet) à chaque résultat. */
function spCalScanFlash(status) {
    var frame = document.getElementById('spcal-scan-frame');
    var cls = status === 'ok' ? 'flash-ok' : (status === 'already' ? 'flash-already' : 'flash-err');
    if (frame) {
        frame.classList.remove('flash-ok', 'flash-already', 'flash-err');
        void frame.offsetWidth; // relance l'effet même si le résultat précédent était identique
        frame.classList.add(cls);
        clearTimeout(frame._timer);
        frame._timer = setTimeout(function(){ frame.classList.remove(cls); }, 900);
    }
    try { if (navigator.vibrate) navigator.vibrate(status === 'ok' ? 80 : [60, 60, 60]); } catch (e) {}
}

function spCalAddLog(nom, status) {
    var now = new Date();
    var heure = now.toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
    var entry = {nom: nom, heure: heure, status: status};
    gPtgLog.unshift(entry);
    var html = gPtgLog.map(function(e) {
        return '<div class="spcal-ptg-log-entry">'+
            '<span class="spcal-ptg-log-nom">'+(e.status==='ok'?'✅ ':'⚠️ ')+esc(e.nom)+'</span>'+
            '<span class="spcal-ptg-log-time">'+esc(e.heure)+'</span>'+
            '</div>';
    }).join('');
    document.getElementById('spcal-ptg-log').innerHTML = html;
}

/* Stopper la caméra quand on quitte l'onglet pointage */
/* Mode entraineur : calendrier club */
/* Encadrement du jour (mode entraîneur, route /calendrier/club) : entraîneurs disponibles d'après
   leurs disponibilités, ou alerte si personne ne s'est encore déclaré. Absent pour les adhérents. */
function spCalEncadrementHtml(c) {
    if (!c || !Array.isArray(c.encadrement)) return '';
    if (!c.encadrement.length) return '<div class="spcal-cours-encadr vide">⚠️ Encadrement non renseigné</div>';
    return '<div class="spcal-cours-encadr">👤 ' + c.encadrement.map(esc).join(', ') + '</div>';
}

function spCalLoadCalendrierClub(pin) {
    var apiBase = CFG.apiBase || '/wp-json/spcal/v1';
    fetch(apiBase + '/calendrier/club?pin=' + encodeURIComponent(pin) + '&nb=20')
        .then(function(r){ return r.json(); })
        .then(function(r) {
            if (!r.cours) return;
            gCours = r.cours;
            var greet = document.getElementById('spcal-greeting');
            var sub   = document.getElementById('spcal-greeting-sub');
            var h = new Date().getHours();
            if (greet) greet.textContent = gEntr && gEntr.nom ? (h < 12 ? 'Bonjour ' : (h < 18 ? 'Bon après-midi ' : 'Bonsoir ')) + gEntr.nom.split(' ')[0] + ' 👋' : 'Prochains cours';
            if (sub)   sub.textContent   = 'Calendrier du club';
            var adh = document.getElementById('spcal-adhesion-wrap');
            var ass = document.getElementById('spcal-assiduite-wrap');
            if (adh) adh.style.display = 'none';
            if (ass) ass.style.display = 'none';
            var html = '';
            gCours.slice(0, 8).forEach(function(c) {
                var today    = isToday(c.date);
                var tomorrow = isTomorrow(c.date);
                var cls   = today ? ' today' : (tomorrow ? ' tomorrow' : '');
                var badge = today
                    ? '<span class="spcal-cours-badge spcal-badge-today">Aujourd&#39;hui</span>'
                    : (tomorrow ? '<span class="spcal-cours-badge spcal-badge-tomorrow">Demain</span>' : '');
                html += '<div class="spcal-cours-card'+cls+'">'
                      + '<div class="spcal-cours-left">'
                      + '<div class="spcal-cours-titre">'+esc(c.titre)+'</div>'
                      + (c.categorie ? '<div class="spcal-cours-cat">'+esc(c.categorie)+'</div>' : '')+spCalEncadrementHtml(c)
                      + '</div>'
                      + '<div class="spcal-cours-right">'
                      + '<div class="spcal-cours-heure">'+esc(fmtHeure(c.heure_debut))+'</div>'
                      + '<div class="spcal-cours-date">'+esc(fmtDateJour(c.date))+'</div>'
                      + badge
                      + '</div></div>';
            });
            if (!html) html = '<div class="spcal-empty">Aucun cours &agrave; venir</div>';
            var el = document.getElementById('spcal-prochains-cours');
            if (el) el.innerHTML = html;
            renderCalendrier();
        })
        .catch(function(){});
}

var _origSpCalNav = window.spCalNav;
window.spCalNav = function(screen, btn) {
    if (screen !== 'pointage') spCalStopScan();
    // Retour sur l'onglet Pointage : toujours la liste des cours du jour, jamais l'écran de
    // scan du dernier cours ouvert (caméra coupée en quittant l'onglet → image noire, et
    // impression qu'il n'y a qu'un seul cours). Retour terrain du 01/10/2026.
    else if (gPtgCours) spCalBackToCours();
    if (screen === 'dispos' && gEntr) spCalLoadDispos();
    _origSpCalNav(screen, btn);
};

/* Page des dispos insérée directement dans l'onglet (pas de cadre iframe) : sur téléphone, un
   doigt posé dans un cadre ne fait pas défiler l'application — une fois le panneau du jour ouvert,
   le cadre couvrait l'écran et tout restait bloqué. Retours terrain du 01/10/2026. */
var gDisposCharge = false;
function spCalLoadDispos() {
    var box = document.getElementById('spcal-dispos-box');
    if (!box || gDisposCharge) return;
    gDisposCharge = true;
    fetch(gEntr.dispos_url, { credentials: 'same-origin' })
        .then(function(r){ if (!r.ok) throw new Error(); return r.text(); })
        .then(function(html) {
            var doc  = new DOMParser().parseFromString(html, 'text/html');
            var app  = doc.getElementById('sp-dispo-app');
            if (!app) throw new Error();
            var scripts = [].slice.call(doc.body.querySelectorAll('script'));
            scripts.forEach(function(s){ s.parentNode.removeChild(s); });
            box.innerHTML = '';
            box.appendChild(document.importNode(app, true));
            // Les scripts insérés par innerHTML ne s'exécutent pas : on les recrée.
            scripts.forEach(function(s) {
                var n = document.createElement('script');
                n.textContent = s.textContent;
                box.appendChild(n);
            });
        })
        .catch(function() {
            gDisposCharge = false;
            box.innerHTML = '<div class="spcal-empty">Impossible de charger vos disponibilités. Réessayez.</div>';
        });
}


/* ── Push subscription ───────────────────────────────────────── */
function spCalSetupPush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    if (!CFG.vapidPublic) return;
    navigator.serviceWorker.ready.then(function(reg) {
        return reg.pushManager.getSubscription().then(function(sub) {
            return sub || reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: spCalUrlB64ToUint8(CFG.vapidPublic)
            });
        });
    }).then(function(sub) {
        if (!sub) return;
        var jwt = STORE.get('spcal_jwt');
        if (!jwt) return;
        fetch(CFG.apiBase + '/push/subscribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + jwt },
            body: JSON.stringify({
                endpoint: sub.endpoint,
                p256dh:   spCalBufToB64u(sub.getKey('p256dh')),
                auth:     spCalBufToB64u(sub.getKey('auth'))
            })
        }).catch(function(){});
    }).catch(function(){});
}

function spCalUrlB64ToUint8(b64) {
    var pad = '='.repeat((4 - b64.length % 4) % 4);
    var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}
function spCalBufToB64u(buf) {
    return btoa(String.fromCharCode.apply(null, new Uint8Array(buf)))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

})();
</script>
        <?php
        return ob_get_clean();
    }


    /* ── Push table ──────────────────────────────────────────────── */

    public function maybe_create_push_table() {
        if ( get_option( 'sp_cal_push_db_v1' ) ) return;
        global $wpdb;
        $table   = $wpdb->prefix . 'sp_cal_push_subs';
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            eleve_id bigint(20) UNSIGNED NOT NULL,
            endpoint text NOT NULL,
            p256dh varchar(128) NOT NULL,
            auth varchar(64) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY eleve_id (eleve_id),
            UNIQUE KEY endpoint_hash (endpoint(200))
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'sp_cal_push_db_v1', 1 );
    }

    /* ── Push REST handlers ───────────────────────────────────────── */

    /** GET /wp-json/spcal/v1/push/vapid-public — retourne la clé publique VAPID. */
    public function rest_pwa_vapid_public( WP_REST_Request $req ) {
        $pub = get_option( 'sp_cal_vapid_public', '' );
        if ( ! $pub && class_exists( 'SpCalPro_WebPush' ) ) {
            $keys = SpCalPro_WebPush::get_or_create_keys();
            $pub  = $keys['public_b64u'];
        }
        return rest_ensure_response( array( 'public_key' => $pub ) );
    }

    /** POST /wp-json/spcal/v1/push/subscribe — enregistre un abonnement push. */
    public function rest_pwa_push_subscribe( WP_REST_Request $req ) {
        $eid = $this->pwa_require_auth( $req );
        if ( is_wp_error( $eid ) ) return $eid;

        $json     = $req->get_json_params() ?: array();
        $endpoint = sanitize_text_field( wp_unslash( $json['endpoint'] ?? '' ) );
        $p256dh   = sanitize_text_field( wp_unslash( $json['p256dh']   ?? '' ) );
        $auth     = sanitize_text_field( wp_unslash( $json['auth']     ?? '' ) );

        if ( ! $endpoint || ! $p256dh || ! $auth ) {
            return new WP_Error( 'spcal_push_invalid', 'Données de subscription manquantes.', array( 'status' => 400 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sp_cal_push_subs';

        // Upsert : si l'endpoint existe déjà, on met à jour
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE endpoint = %s LIMIT 1", $endpoint
        ) );
        if ( $existing ) {
            $wpdb->update( $table,
                array( 'eleve_id' => $eid, 'p256dh' => $p256dh, 'auth' => $auth ),
                array( 'id' => intval( $existing ) )
            );
        } else {
            $wpdb->insert( $table, array(
                'eleve_id' => $eid, 'endpoint' => $endpoint,
                'p256dh'   => $p256dh, 'auth' => $auth,
            ) );
        }

        return rest_ensure_response( array( 'success' => true ) );
    }

    /** DELETE /wp-json/spcal/v1/push/unsubscribe — supprime un abonnement. */
    public function rest_pwa_push_unsubscribe( WP_REST_Request $req ) {
        $eid = $this->pwa_require_auth( $req );
        if ( is_wp_error( $eid ) ) return $eid;

        $json     = $req->get_json_params() ?: array();
        $endpoint = sanitize_text_field( wp_unslash( $json['endpoint'] ?? '' ) );
        if ( ! $endpoint ) return new WP_Error( 'spcal_push_invalid', 'Endpoint manquant.', array( 'status' => 400 ) );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'sp_cal_push_subs',
            array( 'eleve_id' => $eid, 'endpoint' => $endpoint )
        );
        return rest_ensure_response( array( 'success' => true ) );
    }

    /* ══════════════════════════════════════════════════════════
       PAGE : Calendrier principal
    ══════════════════════════════════════════════════════════ */

    // Page unique pour tout le monde ayant au moins un droit de consultation du
    // planning (manage_options, secrétaire ou trésorière) — cf. doleances.md
    // 09/09/2026 : il y avait auparavant un sous-menu "📅 Planning" séparé, mais pour
    // un compte n'ayant accès qu'à une seule page, le menu parent "SP Calendar" et ce
    // sous-menu pointaient de toute façon vers la même destination (comportement
    // standard de wp-admin) — doublon inutile dans la barre latérale, supprimé.
    public function page_main() {
        if ( ! current_user_can( SP_Cal_Roles::CAP_VOIR_PLANNING ) ) wp_die( 'Accès refusé' );

        // Injecter le compteur d'annulations groupées en attente dans SpCal
        $pending_count = count( get_option( 'sp_cal_annul_pending', array() ) );
        echo '<script>window._spCalAnnulPending = ' . intval( $pending_count ) . ';</script>';

        echo '<div class="wrap sp-cal-wrap"><h1>Calendrier de gestion</h1>';

        // Bandeau adhésions — uniquement pour les profils qui gèrent réellement les
        // adhésions (le lien "Gérer les adhésions" serait un cul-de-sac "Accès refusé"
        // pour une trésorière qui n'a que le droit de consultation du planning).
        if ( current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) {
            $saison_active = get_option( 'sp_cal_saison', '' );
            $adh = $this->db->get_adhesions_counts( $saison_active );
            $adh_url = admin_url('admin.php?page=sp-cal-licences');
            $banner_parts = array();
            if ( $adh['inactif'] > 0 )
                $banner_parts[] = '<strong style="color:#b91c1c;">' . $adh['inactif'] . ' adhérent(s) inactif(s)</strong>';
            $adh_alerte = intval( get_option( 'sp_cal_alerte_jours', 60 ) );
            if ( $adh['jours_fin'] !== null && $adh['jours_fin'] <= $adh_alerte && $adh['jours_fin'] >= 0 )
                $banner_parts[] = '<span style="color:#b45309;">Fin de saison dans <strong>' . $adh['jours_fin'] . ' jours</strong> (' . date('d/m/Y', strtotime($adh['fin_saison'])) . ')</span>';
            elseif ( $adh['jours_fin'] !== null && $adh['jours_fin'] < 0 )
                $banner_parts[] = '<strong style="color:#b91c1c;">Saison terminée depuis ' . abs($adh['jours_fin']) . ' jours</strong>';
            if ( ! empty($banner_parts) ) {
                echo '<div class="sp-lic-banner">';
                echo '<span class="sp-lic-banner-icon">🪪</span>';
                echo implode(' · ', $banner_parts);
                echo ' — <a href="' . esc_url($adh_url) . '">Gérer les adhésions →</a>';
                echo '</div>';
            }
        }

        echo do_shortcode( '[sp_cal_calendar]' );
        echo '</div>';
    }

    public function page_eleves() { $this->members->page_eleves(); }

    /* ══════════════════════════════════════════════════════════
       PAGE : Paramètres
    ══════════════════════════════════════════════════════════ */

    /* ══════════════════════════════════════════════════════════
       PAGE PALMARÈS
    ══════════════════════════════════════════════════════════ */

    public function page_palmares() { $this->members->page_palmares(); }

    /* ══════════════════════════════════════════════════════════
       AJAX — Synchronisation vacances scolaires Zone C
    ══════════════════════════════════════════════════════════ */

    public function ajax_sync_vacances() { $this->exam->ajax_sync_vacances(); }

    
    public function handle_dates_grades_actions() { $this->exam->handle_dates_grades_actions(); }

    public function page_dates_grades() { $this->exam->page_dates_grades(); }

    /* ══════════════════════════════════════════════════════════
       POINTAGE QR CODE
    ══════════════════════════════════════════════════════════ */

    // Code PIN pointage : SP_Cal_Pin_Garde::pin() (class-pin-garde.php) depuis le 07/10/2026.

    // ── Helper : matérialise une occurrence slot+date dans events si besoin ──
    // Retourne l'event_id (existant ou nouvellement créé)
    private function materialiser_occurrence( $slot_id, $date ) {
        global $wpdb;
        $te  = $this->db->table_events();
        $tsl = $this->db->table_slots();

        // Déjà matérialisé ?
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $te WHERE slot_id = %d AND date = %s AND type != 'annulation' LIMIT 1",
            $slot_id, $date
        ) );
        if ( $existing ) return intval( $existing );

        // Récupérer le slot
        $slot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tsl WHERE id = %d", $slot_id ) );
        if ( ! $slot ) return 0;

        $wpdb->insert( $te, array(
            'date'        => $date,
            'heure_debut' => $slot->heure_debut,
            'heure_fin'   => $slot->heure_fin,
            'titre'       => $slot->label,
            'categorie'   => $slot->categorie ?: 'Général',
            'type'        => 'cours',
            'slot_id'     => $slot_id,
        ) );
        return intval( $wpdb->insert_id );
    }

    // AJAX : cours d'un jour (depuis les slots récurrents)
    public function ajax_pointage_cours() {
        $pin  = sanitize_text_field( wp_unslash( $_POST['pin']  ?? '' ) );
        $date = sanitize_text_field( wp_unslash( $_POST['date'] ?? date('Y-m-d' ) ) );
        if ( ! SP_Cal_Pin_Garde::verifier( (string) $pin, SP_Cal_Pin_Garde::pin() ) ) {
            wp_send_json_error( SP_Cal_Pin_Garde::message(), 403 ); return;
        }
        // Utiliser get_slot_occurrences pour avoir les cours récurrents
        $occs = $this->db->get_slot_occurrences( $date, $date );
        $cours = array();
        foreach ( $occs as $occ ) {
            if ( $occ['annul_id'] ) continue; // cours annulé
            $cours[] = array(
                'slot_id'     => $occ['slot_id'],
                'mat_id'      => $occ['mat_id'],   // null si pas encore matérialisé
                'date'        => $occ['date'],
                'titre'       => $occ['titre'],
                'heure_debut' => $occ['heure_debut'],
                'heure_fin'   => $occ['heure_fin'],
                'categorie'   => $occ['categorie'],
            );
        }
        wp_send_json_success( $cours );
    }

    // AJAX : cours récents non pointés pour un élève (mode rétroactif)
    public function ajax_pointage_cours_eleve() {
        $pin   = sanitize_text_field( wp_unslash( $_POST['pin']   ?? '' ) );
        $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        if ( ! SP_Cal_Pin_Garde::verifier( (string) $pin, SP_Cal_Pin_Garde::pin() ) ) {
            wp_send_json_error( SP_Cal_Pin_Garde::message(), 403 ); return;
        }
        global $wpdb;
        $tel   = $this->db->table_eleves();
        $tpe   = $this->db->table_presences_eleves();
        $eleve = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom, prenom FROM $tel WHERE token = %s AND actif = 1 LIMIT 1", $token
        ) );
        if ( ! $eleve ) { wp_send_json_error( 'Élève non trouvé', 404 ); return; }

        // Occurrences des 30 derniers jours
        $date_fin   = date('Y-m-d');
        $date_debut = date('Y-m-d', strtotime('-30 days'));
        $occs = $this->db->get_slot_occurrences( $date_debut, $date_fin );

        $cours = array();
        foreach ( array_reverse( $occs ) as $occ ) {
            if ( $occ['annul_id'] ) continue;
            // Vérifier si déjà pointé (nécessite mat_id)
            $deja = false;
            if ( $occ['mat_id'] ) {
                $deja = (bool) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $tpe WHERE event_id = %d AND eleve_id = %d LIMIT 1",
                    $occ['mat_id'], $eleve->id
                ) );
            }
            $cours[] = array(
                'slot_id'      => $occ['slot_id'],
                'mat_id'       => $occ['mat_id'],
                'date'         => $occ['date'],
                'titre'        => $occ['titre'],
                'heure_debut'  => $occ['heure_debut'],
                'heure_fin'    => $occ['heure_fin'],
                'categorie'    => $occ['categorie'],
                'deja_pointe'  => $deja ? 1 : 0,
            );
        }
        wp_send_json_success( array(
            'eleve' => array( 'nom' => $eleve->prenom . ' ' . mb_strtoupper( $eleve->nom ) ),
            'cours' => $cours,
        ) );
    }

    // AJAX : enregistrer une présence au scan
    public function ajax_pointage_scan() {
        global $wpdb;
        $pin      = sanitize_text_field( wp_unslash( $_POST['pin']      ?? '' ) );
        $token    = sanitize_text_field( wp_unslash( $_POST['token']    ?? '' ) );
        $slot_id  = intval( $_POST['slot_id']  ?? 0 );
        $date     = sanitize_text_field( wp_unslash( $_POST['date']     ?? '' ) );
        if ( ! SP_Cal_Pin_Garde::verifier( (string) $pin, SP_Cal_Pin_Garde::pin() ) ) {
            wp_send_json_error( SP_Cal_Pin_Garde::message(), 403 ); return;
        }
        if ( ! $token || ! $slot_id || ! $date ) {
            wp_send_json_error( 'Données manquantes', 400 ); return;
        }
        // Trouver l'élève
        $tel   = $this->db->table_eleves();
        $eleve = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom, prenom FROM $tel WHERE token = %s AND actif = 1 LIMIT 1", $token
        ) );
        if ( ! $eleve ) { wp_send_json_error( 'Élève non trouvé', 404 ); return; }

        // Matérialiser l'occurrence si besoin → obtenir event_id
        $event_id = $this->materialiser_occurrence( $slot_id, $date );
        if ( ! $event_id ) { wp_send_json_error( 'Créneau introuvable', 404 ); return; }

        // Enregistrer la présence
        $tpe      = $this->db->table_presences_eleves();
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $tpe WHERE event_id = %d AND eleve_id = %d", $event_id, $eleve->id
        ) );
        if ( $existing ) {
            wp_send_json_success( array(
                'nom' => $eleve->prenom . ' ' . mb_strtoupper( $eleve->nom ),
                'status' => 'already', 'msg' => 'Déjà pointé',
            ) ); return;
        }
        $wpdb->insert( $tpe, array( 'event_id' => $event_id, 'eleve_id' => $eleve->id, 'present' => 1 ) );
        wp_send_json_success( array(
            'nom' => $eleve->prenom . ' ' . mb_strtoupper( $eleve->nom ),
            'status' => 'ok', 'msg' => 'Présent',
        ) );
    }

    // AJAX : enregistrer plusieurs présences en lot
    public function ajax_pointage_lot() {
        global $wpdb;
        $pin      = sanitize_text_field( wp_unslash( $_POST['pin']   ?? '' ) );
        $token    = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        $items    = $_POST['items'] ?? array(); // array de {slot_id, date}
        if ( ! SP_Cal_Pin_Garde::verifier( (string) $pin, SP_Cal_Pin_Garde::pin() ) ) {
            wp_send_json_error( SP_Cal_Pin_Garde::message(), 403 ); return;
        }
        if ( ! $token || empty( $items ) ) {
            wp_send_json_error( 'Données manquantes', 400 ); return;
        }
        $tel   = $this->db->table_eleves();
        $eleve = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom, prenom FROM $tel WHERE token = %s AND actif = 1 LIMIT 1", $token
        ) );
        if ( ! $eleve ) { wp_send_json_error( 'Élève non trouvé', 404 ); return; }

        $tpe = $this->db->table_presences_eleves();
        $nb  = 0;
        foreach ( $items as $item ) {
            $sid  = intval( $item['slot_id'] ?? 0 );
            $date = sanitize_text_field( $item['date'] ?? '' );
            if ( ! $sid || ! $date ) continue;
            $eid = $this->materialiser_occurrence( $sid, $date );
            if ( ! $eid ) continue;
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $tpe WHERE event_id=%d AND eleve_id=%d", $eid, $eleve->id
            ) );
            if ( ! $exists ) {
                $wpdb->insert( $tpe, array( 'event_id' => $eid, 'eleve_id' => $eleve->id, 'present' => 1 ) );
                $nb++;
            }
        }
        wp_send_json_success( array(
            'nom' => $eleve->prenom . ' ' . mb_strtoupper( $eleve->nom ),
            'nb'  => $nb,
        ) );
    }


    // Page admin pointage
    public function page_pointage() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé');
        $pin = SP_Cal_Pin_Garde::pin();
        $pointage_url = home_url('/pointage/?pin=' . $pin);

        // Régénérer le PIN si demandé
        if (isset($_POST['regenerer_pin']) && check_admin_referer('sp_pointage_regen')) {
            SP_Cal_Pin_Garde::regenerer();
            wp_redirect(admin_url('admin.php?page=sp-cal-pointage&pin_ok=1')); exit;
        }
        ?>
        <div class="wrap" style="max-width:700px;">
            <h1>📡 Pointage QR Code</h1>

            <?php if(isset($_GET['pin_ok'])): ?>
            <div class="updated is-dismissible"><p>✅ Nouveau PIN généré.</p></div>
            <?php endif; ?>

            <div class="sp-box" style="margin-bottom:18px;">
                <h2 style="margin-top:0;">🔐 Accès entraîneur</h2>
                <p>Partagez cette URL avec les entraîneurs — elle donne accès au scanner de présences :</p>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
                    <code style="background:#f1f5f9;padding:8px 14px;border-radius:6px;font-size:14px;flex:1;">
                        <?php echo esc_html($pointage_url); ?>
                    </code>
                    <button onclick="navigator.clipboard.writeText('<?php echo esc_js($pointage_url); ?>').then(()=>this.textContent='✅ Copié!')" class="button">📋 Copier</button>
                </div>
                <p style="color:#64748b;font-size:13px;">
                    PIN actuel : <strong style="font-size:20px;letter-spacing:4px;"><?php echo esc_html($pin); ?></strong>
                </p>
                <form method="post">
                    <?php wp_nonce_field('sp_pointage_regen'); ?>
                    <input type="submit" name="regenerer_pin" class="button button-secondary" value="🔄 Générer un nouveau PIN"
                           onclick="return confirm('Changer le PIN rendra l'ancienne URL inutilisable. Continuer ?')">
                </form>
            </div>

            <!-- QR Code de l'URL de pointage -->
            <div class="sp-box">
                <h2 style="margin-top:0;">📱 QR Code de la page pointage</h2>
                <p style="color:#64748b;font-size:13px;">Imprimez et affichez ce QR Code en salle — les entraîneurs le scannent pour ouvrir la page directement.</p>
                <div id="sp-pointage-qr" style="display:inline-block;padding:12px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;"></div>
            </div>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
        (function(){
            function init() {
                if (typeof QRCode === 'undefined') { setTimeout(init, 100); return; }
                new QRCode(document.getElementById('sp-pointage-qr'), {
                    text: '<?php echo esc_js($pointage_url); ?>',
                    width: 180, height: 180,
                    colorDark: '#111', colorLight: '#fff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
            init();
        })();
        </script>
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       PAGE : Fiche élève (lecture)
    ══════════════════════════════════════════════════════════ */

    /**
     * Intercepte ?page=sp-cal-print-cartes&print=1 AVANT que WordPress
     * sorte son HTML admin — produit une page propre sans chrome.
     */
    public function maybe_print_cartes(): void { $this->members->maybe_print_cartes(); }

    public function page_print_cartes() { $this->members->page_print_cartes(); }

    public function page_fiche_eleve() { $this->members->page_fiche_eleve(); }

    /* ══════════════════════════════════════════════════════════
       PAGE : Licences
    ══════════════════════════════════════════════════════════ */

    public function page_licences() { $this->members->page_licences(); }


    /* ══════════════════════════════════════════════════════════
       PAGE : Statistiques
    ══════════════════════════════════════════════════════════ */

    public function page_stats() { $this->members->page_stats(); }

    /* ══════════════════════════════════════════════════════════
       HELPERS PRIVÉS
    ══════════════════════════════════════════════════════════ */

    private function notice_flash( $key, $message ) {
        if ( isset( $_GET[ $key ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }
    }

    /* ══════════════════════════════════════════════════════════
       PAGE INSCRIPTIONS AUX ÉVÉNEMENTS
       Accès : admin.php?page=sp-cal-inscriptions[&event_id=XX]
    ══════════════════════════════════════════════════════════ */

    public function page_inscriptions() { $this->inscriptions->page_inscriptions(); }

    /* ══════════════════════════════════════════════════════════
       EXPORT CSV INSCRIPTIONS
    ══════════════════════════════════════════════════════════ */

    /* ══════════════════════════════════════════════════════════
       INSCRIPTIONS ÉVÉNEMENTS — AJAX HANDLERS
    ══════════════════════════════════════════════════════════ */

    /**
     * Envoie les invitations aux élèves actifs ciblés par catégorie.
     * Utilise send_invitation_inscription() de class-notifications (email HTML avec boutons ✅/❌).
     * Si inscriptions_envoye = 1 : renvoie uniquement aux non-répondants (statut en_attente).
     */
    public function ajax_inscription_envoyer() { $this->inscriptions->ajax_inscription_envoyer(); }

    /**
     * Action groupée sur les inscriptions : renvoi, changement de statut.
     */
    public function ajax_inscription_bulk() { $this->inscriptions->ajax_inscription_bulk(); }

    /** AJAX : envoie le lien application PWA à un entraîneur. */
    public function ajax_send_trainer_app_link() { $this->members->ajax_send_trainer_app_link(); }

    /** AJAX : retourne les catégories saisie + âge distinctes des élèves actifs (pour la modale calendar). */
    public function ajax_get_cats_saisie() { $this->inscriptions->ajax_get_cats_saisie(); }

    /** AJAX : compte les élèves ciblés par les filtres discipline + âge.
     *  Si l'événement a déjà été envoyé (inscriptions_envoye=1), ne compte que
     *  les non-répondants : élèves en_attente OU sans ligne dans la table inscriptions. */
    public function ajax_inscription_count_cibles() { $this->inscriptions->ajax_inscription_count_cibles(); }

    /* ══════════════════════════════════════════════════════════
       LISTE DES ÉLÈVES CIBLÉS (public cible nominatif)
    ══════════════════════════════════════════════════════════ */
    public function ajax_inscription_list_cibles() { $this->inscriptions->ajax_inscription_list_cibles(); }

}

endif; // class_exists SpCalPro_Admin

<?php 
if ( ! defined( 'ABSPATH' ) ) exit;
require_once plugin_dir_path( __FILE__ ) . 'class-admin-inscriptions.php';
require_once plugin_dir_path( __FILE__ ) . 'class-admin-exam.php';
require_once plugin_dir_path( __FILE__ ) . 'class-admin-members.php';
require_once plugin_dir_path( __FILE__ ) . 'class-admin-jury.php';
require_once plugin_dir_path( __FILE__ ) . 'class-front-adhesion.php';
require_once plugin_dir_path( __FILE__ ) . 'class-admin-adhesions.php';
require_once plugin_dir_path( __FILE__ ) . 'class-renouvellement.php';
require_once plugin_dir_path( __FILE__ ) . 'class-roles.php';
require_once plugin_dir_path( __FILE__ ) . 'class-trainer-app.php';
require_once plugin_dir_path( __FILE__ ) . 'class-dobok.php';


if ( ! function_exists( 'ordinal_fr' ) ) {
    /** Retourne "1er", "2", "3", … pour l'affichage du jour d'envoi. */
    function ordinal_fr( $n ) {
        return $n === 1 ? '1<sup>er</sup>' : intval( $n );
    }
}

if ( ! class_exists( 'SpCalPro_Admin' ) ) :

class SpCalPro_Admin {

    private $db;
    private $exam;
    private $members;
    private $jury;
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
        add_action( 'admin_init',            array( $this->db, 'maybe_upgrade' ) );
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
        add_action( 'admin_init', array( $this, 'maybe_create_push_table' ) );
        // Envoi lien app entraîneur
        // Inscriptions événements
        // Catégories saisie pour la modale
        // Chantier 5 — Saisie notes jury via QR mobile

        // Module inscriptions
        $this->inscriptions = new SP_Cal_Inscriptions( $this->db );

        // Module examens / pédagogie / dates grades
        $this->exam = new SP_Cal_Exam( $this->db );

        // Module membres (élèves, palmares, cartes, fiche, licences, stats)
        $this->members = new SP_Cal_Members( $this->db, $this->token );

        // Module jury d'examen
        // Module jury d'examen
$this->jury = new SP_Cal_Jury( $this->db );
		SP_Front_Adhesion::get_instance();
        SP_Admin_Adhesions::get_instance();
        SP_Cal_Renouvellement::get_instance( $this->db );
        SP_Cal_Roles::get_instance();
        SP_Cal_Trainer_App::get_instance( $this->db );
        SP_Cal_Dobok::get_instance( $this->db );
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
        add_submenu_page( 'sp-cal-pro', 'Examens',    '🎓 Examens',     'manage_options', 'sp-cal-examens',     array( $this, 'page_examens' ) );
        add_submenu_page( 'sp-cal-pro', 'Jury examen','⚖️ Jury',        'manage_options', 'sp-cal-jury',        array( $this, 'page_jury' ) );
        add_submenu_page( 'sp-cal-pro', 'Guide jury',  '📖 Guide jury',  'manage_options', 'sp-cal-jury-guide',  array( $this, 'page_jury_guide' ) );
        add_submenu_page( 'sp-cal-pro', 'Paramètres',     'Paramètres',     'manage_options', 'sp-cal-settings',    array( $this, 'page_settings' ) );
        add_submenu_page( 'sp-cal-pro', '📚 Pédagogie',   '📚 Pédagogie',   'manage_options', 'sp-cal-pedagogie',   array( $this, 'page_pedagogie' ) );
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
    wp_enqueue_style(  'sp-cal-admin',    SP_CAL_PRO_URL . 'assets/css/admin.css',  array(), SP_CAL_PRO_VERSION );
    wp_enqueue_script( 'sp-cal-admin-js', SP_CAL_PRO_URL . 'assets/js/admin.js',   array( 'jquery' ), SP_CAL_PRO_VERSION, true );
    wp_localize_script( 'sp-cal-admin-js', 'SpCalAdmin', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'sp_cal_admin_nonce' ),
    ) );
    if ( strpos( $hook, 'sp-cal-trainers' ) !== false || strpos( $hook, 'sp-cal-eleves' ) !== false || strpos( $hook, 'sp-cal-fiche-eleve' ) !== false ) {
        wp_enqueue_media();
    }
    if ( strpos( $hook, 'sp-cal-jury' ) !== false ) {
        $this->jury->enqueue_scripts( $hook );
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
        $fin_saison = get_option( 'sp_cal_fin_saison', '' );
        $alerte     = intval( get_option( 'sp_cal_alerte_jours', 60 ) );
        $adhesion   = 'actif'; $jours = null;
        if ( $fin_saison ) {
            $jr = intval( ceil( ( strtotime( $fin_saison ) - time() ) / 86400 ) );
            $jours = $jr;
            if ( $jr < 0 ) $adhesion = 'expire';
            elseif ( $jr <= $alerte ) $adhesion = 'expire_bientot';
        }
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

        global $wpdb;
        $tel    = $this->db->table_eleves();
        $eleve  = $wpdb->get_row( $wpdb->prepare(
            "SELECT categorie_saisie, categorie_age FROM $tel WHERE id=%d AND actif=1 LIMIT 1",
            intval( $eid )
        ) );
        $cat_saisie = trim( $eleve->categorie_saisie ?? '' );
        $cat_age    = trim( $eleve->categorie_age    ?? '' );

        $events = $this->db->get_events_inscriptions_ouvertes_eleve( intval( $eid ) );

        $out = array();
        foreach ( $events as $ev ) {
            // Calcul éligibilité : discipline ET tranche d'âge doivent correspondre.
            // Si les champs sont vides ET qu'aucune sélection manuelle n'existe → ouvert à tous.
            // Si les champs sont vides MAIS qu'une sélection manuelle existe → ciblage exclusif
            // sur cette sélection (même logique que l'envoi, cf. ajax_inscription_envoyer()) —
            // sinon la case "Autre" laissée vide rendrait tout le monde éligible dans l'appli
            // alors que l'email n'a été envoyé qu'aux élèves choisis à la main (18/09/2026).
            $cats  = array_filter( array_map( 'trim', explode( ',', $ev->inscriptions_categories     ?? '' ) ) );
            $ages  = array_filter( array_map( 'trim', explode( ',', $ev->inscriptions_age_categories ?? '' ) ) );
            $extra = array_filter( array_map( 'intval', explode( ',', $ev->inscriptions_extra_eleves ?? '' ) ) );
            $has_cat_filter = ! empty( $cats ) || ! empty( $ages );

            if ( $has_cat_filter || empty( $extra ) ) {
                $disc_ok  = empty( $cats ) || ( $cat_saisie !== '' && in_array( $cat_saisie, $cats, true ) );
                $age_ok   = empty( $ages ) || ( $cat_age    !== '' && in_array( $cat_age,    $ages, true ) );
                $eligible_categorie = $disc_ok && $age_ok;
            } else {
                $eligible_categorie = false; // ciblage exclusivement manuel
            }
            $eligible = $eligible_categorie || in_array( intval( $eid ), $extra, true );

            $out[] = array(
                'id'                    => intval( $ev->id ),
                'titre'                 => $ev->titre                 ?? '',
                'date'                  => $ev->date                  ?? '',
                'heure_debut'           => $ev->heure_debut           ?? '',
                'inscriptions_deadline' => $ev->inscriptions_deadline ?? null,
                'inscriptions_message'  => $ev->inscriptions_message  ?? '',
                'statut_insc'           => $ev->statut_insc           ?? 'en_attente',
                'eligible'              => $eligible,
            );
        }
        return rest_ensure_response( array( 'evenements' => $out ) );
    }

    public function rest_pwa_calendrier_club( WP_REST_Request $req ) {
        $pin = sanitize_text_field( wp_unslash( $req->get_param( 'pin' ) ?? '' ) );
        if ( ! $pin || $pin !== $this->pointage_pin() ) {
            return new WP_Error( 'spcal_invalid_pin', 'PIN invalide.', array( 'status' => 401 ) );
        }
        $from_raw = sanitize_text_field( wp_unslash( $req->get_param( 'from' ) ?? date( 'Y-m-d' ) ) );
        $nb       = min( 30, max( 1, intval( $req->get_param( 'nb' ) ?? 20 ) ) );
        $from     = ( preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $from_raw ) && strtotime( $from_raw ) ) ? $from_raw : date( 'Y-m-d' );
        $to       = date( 'Y-m-d', strtotime( $from . ' +90 days' ) );
        $occs     = $this->db->get_slot_occurrences( $from, $to );
        $cours    = array();
        foreach ( $occs as $occ ) {
            if ( $occ['annul_id'] ) continue;
            $cours[] = array(
                'slot_id'     => intval( $occ['slot_id'] ),
                'mat_id'      => $occ['mat_id'] ? intval( $occ['mat_id'] ) : null,
                'date'        => $occ['date'],
                'heure_debut' => $occ['heure_debut'] ?? '',
                'heure_fin'   => $occ['heure_fin']   ?? '',
                'titre'       => $occ['titre']        ?? '',
                'categorie'   => $occ['categorie']    ?? '',
            );
            if ( count( $cours ) >= $nb ) break;
        }
        return rest_ensure_response( array( 'from' => $from, 'nb' => count( $cours ), 'cours' => $cours ) );
    }

    public function rest_pwa_calendrier( WP_REST_Request $req ) {
        $eid = $this->pwa_require_auth( $req );
        if ( is_wp_error( $eid ) ) return $eid;
        global $wpdb;
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare( "SELECT categorie_saisie FROM $tel WHERE id = %d AND actif = 1 LIMIT 1", $eid ) );
        if ( ! $el ) return new WP_Error( 'spcal_not_found', 'Élève introuvable.', array( 'status' => 404 ) );
        $from_raw = sanitize_text_field( wp_unslash( $req->get_param( 'from' ) ?? date( 'Y-m-d' ) ) );
        $nb       = min( 30, max( 1, intval( $req->get_param( 'nb' ) ?? 20 ) ) );
        $from     = ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from_raw ) && strtotime( $from_raw ) ) ? $from_raw : date( 'Y-m-d' );
        $to       = date( 'Y-m-d', strtotime( $from . ' +90 days' ) );
        $occs     = $this->db->get_slot_occurrences( $from, $to );
        $cat      = $el->categorie_saisie ?? '';
        $cours    = array();
        foreach ( $occs as $occ ) {
            if ( $occ['annul_id'] ) continue;
            // La catégorie d'un événement peut lister plusieurs groupes séparés par des virgules
            // (ex "Enfant, Ado/adulte, Renfo") depuis qu'elle a une vraie case à cocher multiple —
            // comparaison insensible à la casse car ces libellés restent tapés à la main.
            if ( $cat !== '' && ! empty( $occ['categorie'] ) ) {
                $cat_tokens = array_map( function( $t ) { return mb_strtoupper( trim( $t ) ); }, explode( ',', $occ['categorie'] ) );
                if ( ! in_array( mb_strtoupper( $cat ), $cat_tokens, true ) ) continue;
            }
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

    /** Code du Service Worker (cache-first pour le shell, network-first pour l'API). */
    private function get_pwa_sw_code( string $app_url ) : string {
        return <<<'SWJS'
const CACHE_NAME = 'spcal-pwa-v1';
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

self.addEventListener('fetch', function(e) {
    var url = e.request.url;
    // Ne pas intercepter les appels API ni les requêtes non-GET
    if (e.request.method !== 'GET') return;
    if (url.indexOf('/wp-json/') !== -1) return;
    if (url.indexOf('admin-ajax') !== -1) return;
    if (url.indexOf('wp-login') !== -1) return;

    e.respondWith(
        caches.match(e.request).then(function(cached) {
            var networkFetch = fetch(e.request).then(function(response) {
                if (response.ok) {
                    var clone = response.clone();
                    caches.open(CACHE_NAME).then(function(cache){ cache.put(e.request, clone); });
                }
                return response;
            });
            return cached || networkFetch;
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
.spcal-cours-right{text-align:right;flex-shrink:0;}
.spcal-cours-heure{font-size:14px;font-weight:600;color:#0f70b7;}
.spcal-cours-card.today .spcal-cours-heure{color:#22c55e;}
.spcal-cours-date{font-size:11px;color:rgba(255,255,255,.35);margin-top:2px;}
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
.spcal-evt-loading{font-size:12px;color:rgba(255,255,255,.4);font-style:italic;}

/* ── PROCHAIN GRADE ───────────────────────────────────────────── */
#spcal-grade-progression{margin-top:20px;}
.spcal-grade-section-title{font-size:12px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;}
.spcal-grade-next{font-size:13px;color:rgba(255,255,255,.7);}
.spcal-grade-next strong{color:#0f70b7;}

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
                <div class="spcal-section-title">Événements</div>
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
                        <button onclick="spCalPinLogout()" style="background:none;border:none;color:rgba(255,255,255,.3);font-size:12px;cursor:pointer;">Déconnexion</button>
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
                                <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:200px;height:200px;border:2px solid rgba(15,112,183,.8);border-radius:12px;box-shadow:0 0 0 9999px rgba(0,0,0,.45);"></div>
                            </div>
                        </div>
                        <p style="text-align:center;font-size:13px;color:rgba(255,255,255,.4);margin-bottom:16px;">Scannez la carte QR de l'élève</p>
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
            <button class="spcal-nav-btn" onclick="spCalNav('calendrier',this)">
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
function initEntraineur(pin) {
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
            if (b.id !== 'spcal-nav-carte' && b.id !== 'spcal-nav-evenements') b.style.display = 'flex';
        });
        // Header entraineur
        document.getElementById('spcal-header-name').textContent = 'Espace entraîneur';
        document.getElementById('spcal-header-club').textContent = CFG.clubNom || '';
        if (CFG.logoUrl) {
            var img = document.getElementById('spcal-header-logo');
            img.src = CFG.logoUrl; img.style.display = 'block';
            hide('spcal-header-logo-placeholder');
        } else {
            document.getElementById('spcal-header-logo-placeholder').textContent = '📡';
            document.getElementById('spcal-header-logo-placeholder').style.display = 'flex';
        }
        // Aller directement sur l'onglet pointage
        spCalNav('pointage', document.getElementById('spcal-nav-pointage'));
        spCalShowPointageDashboard(r.data, today);
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
        adhHtml = '<div class="spcal-adhesion-badge spcal-adhesion-ko">❌ Adhésion expirée</div>';
    } else if (adh.statut === 'expire_bientot') {
        adhHtml = '<div class="spcal-adhesion-badge spcal-adhesion-warn">⚠️ Expire dans '+adh.jours+' jours</div>';
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
                (c.categorie ? '<div class="spcal-cours-cat">'+esc(c.categorie)+'</div>' : '')+
                '</div>'+
                '<div class="spcal-cours-right">'+
                '<div class="spcal-cours-heure">'+esc(fmtHeure(c.heure_debut))+'</div>'+
                '<div class="spcal-cours-date">'+esc(fmtDate(c.date))+'</div>'+
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
            (c.categorie ? '<div class="spcal-cours-cat">'+esc(c.categorie)+'</div>' : '')+
            '</div>'+
            '<div class="spcal-cours-right">'+
            '<div class="spcal-cours-heure">'+esc(fmtHeure(c.heure_debut))+'</div>'+
            '<div class="spcal-cours-date">'+esc(fmtDate(c.date))+'</div>'+
            badge+
            '</div>'+
            '</div>';
    });
    document.getElementById('spcal-cal-list').innerHTML = html;
}

/* ── Prochain grade (onglet Carte) ───────────────────────────── */
function renderGradeProgression() {
    var el   = gEleve;
    var wrap = document.getElementById('spcal-grade-progression');
    if (!wrap) return;
    if (!el.grade_vise) { wrap.innerHTML = ''; return; }
    wrap.innerHTML = '<div class="spcal-grade-section-title">Prochain grade</div>' +
        '<div class="spcal-grade-next"><strong>' + esc(el.grade_vise) + '</strong></div>';
}

/* ── Événements & Inscriptions ───────────────────────────────── */
function renderEvenements() {
    var listEl = document.getElementById('spcal-evt-list');
    if (!listEl) return;

    apiFetch('/eleve/evenements')
    .then(function(r) {
        var evts = r.evenements || [];
        if (!evts.length) {
            listEl.innerHTML = '<div class="spcal-empty">Aucun \u00e9v\u00e9nement en cours d\u2019inscription</div>';
            return;
        }
        var html = '';
        evts.forEach(function(ev) {
            var statut   = ev.statut_insc || 'en_attente';
            var eligible = !!ev.eligible;
            var dlOk     = !ev.inscriptions_deadline || ev.inscriptions_deadline >= new Date().toISOString().slice(0,10);
            var dateStr  = ev.date ? ('\ud83d\udcc5 ' + fmtDate(ev.date) + (ev.heure_debut ? ' \u00b7 ' + fmtHeure(ev.heure_debut) : '')) : '';
            var dlStr    = (ev.inscriptions_deadline && dlOk && eligible)
                ? '<div class="spcal-evt-deadline">\u23f0 R\u00e9pondre avant le ' + fmtDate(ev.inscriptions_deadline) + '</div>' : '';
            var msgStr   = ev.inscriptions_message
                ? '<div class="spcal-evt-msg">' + esc(ev.inscriptions_message) + '</div>' : '';

            var actionsHtml = '';
            if (!eligible) {
                // Visible mais non concerné — pour info seulement
                actionsHtml = '<span class="spcal-evt-badge spcal-evt-badge-info">\ud83d\udc40 Pour info</span>';
            } else if (!dlOk) {
                var badgeCls = statut === 'inscrit' ? 'spcal-evt-badge-ok'
                             : statut === 'refuse'  ? 'spcal-evt-badge-ko'
                             :                        'spcal-evt-badge-wait';
                var badgeLbl = statut === 'inscrit' ? '\u2705 Inscrit(e)'
                             : statut === 'refuse'  ? '\u274c D\u00e9clin\u00e9'
                             :                        '\u23f3 Sans r\u00e9ponse';
                actionsHtml = '<span class="spcal-evt-badge ' + badgeCls + '">' + badgeLbl + '</span>' +
                              '<div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:6px;">\u23f0 D\u00e9lai d\u00e9pass\u00e9</div>';
            } else if (statut === 'inscrit') {
                actionsHtml =
                    '<span class="spcal-evt-badge spcal-evt-badge-ok">\u2705 Inscrit(e)</span>' +
                    '<button class="spcal-evt-btn spcal-evt-btn-annuler" onclick="pwaInscRepondre(' + ev.id + ',\'non\',this)">\u274c Annuler</button>';
            } else if (statut === 'refuse') {
                actionsHtml =
                    '<span class="spcal-evt-badge spcal-evt-badge-ko">\u274c D\u00e9clin\u00e9</span>' +
                    '<button class="spcal-evt-btn spcal-evt-btn-oui" onclick="pwaInscRepondre(' + ev.id + ',\'oui\',this)">Je participe finalement</button>';
            } else {
                actionsHtml =
                    '<button class="spcal-evt-btn spcal-evt-btn-oui" onclick="pwaInscRepondre(' + ev.id + ',\'oui\',this)">\u2705 Je participe</button>' +
                    '<button class="spcal-evt-btn spcal-evt-btn-non" onclick="pwaInscRepondre(' + ev.id + ',\'non\',this)">\u274c Je ne peux pas</button>';
            }

            var cardCls = !eligible ? ' statut-info'
                        : statut === 'inscrit' ? ' statut-inscrit'
                        : statut === 'refuse'  ? ' statut-refuse' : '';
            html +=
                '<div class="spcal-evt-card' + cardCls + '" id="spcal-evt-' + ev.id + '">' +
                '<div class="spcal-evt-titre">' + esc(ev.titre) + '</div>' +
                '<div class="spcal-evt-meta">' + dateStr + '</div>' +
                dlStr + msgStr +
                '<div class="spcal-evt-actions">' + actionsHtml + '</div>' +
                '</div>';
        });
        listEl.innerHTML = html;
    })
    .catch(function() {
        listEl.innerHTML = '<div class="spcal-empty">Erreur de chargement</div>';
    });
}

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
}

window.spCalSelectCours = function(cours) {
    gPtgCours = cours;
    gPtgLog = [];
    document.getElementById('spcal-cours-list-wrap').style.display = 'none';
    document.getElementById('spcal-scan-wrap').style.display = 'block';
    document.getElementById('spcal-ptg-cours-title').textContent =
        cours.titre + (cours.heure_debut ? ' · ' + fmtHeure(cours.heure_debut) : '');
    document.getElementById('spcal-ptg-log').innerHTML = '';
    document.getElementById('spcal-scan-feedback').style.display = 'none';
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
            // BarcodeDetector désactivé temporairement (doléances 10/09/2026) : présent mais
            // potentiellement peu fiable selon la version WebKit -- jsQR est la méthode déjà
            // éprouvée en production sur le scanner /pointage/?pin= (class-token.php) depuis
            // avril 2026. À réactiver (remettre la condition BarcodeDetector) une fois confirmé
            // que ce n'était pas la cause.
            spCalLoadJsQR(function(){ spCalScanWithJsQR(video); });
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

function spCalScanWithBarcodeDetector(video) {
    var detector;
    try { detector = new BarcodeDetector({ formats: ['qr_code'] }); }
    catch (e) { spCalLoadJsQR(function(){ spCalScanWithJsQR(video); }); return; }
    function tick() {
        if (!gScanActive) return;
        // iOS : readyState n'atteint pas toujours HAVE_ENOUGH_DATA (4), >=2 suffit
        if (video.readyState >= 2 && video.videoWidth > 0) {
            detector.detect(video).then(function(codes) {
                if (codes.length > 0) spCalHandleScanResult(codes[0].rawValue);
            }).catch(function(){});
        }
        setTimeout(tick, 300);
    }
    tick();
}

function spCalScanWithJsQR(video) {
    var canvas = document.getElementById('spcal-qr-canvas');
    var ctx = canvas.getContext('2d');
    function tick() {
        if (!gScanActive) return;
        // typeof jsQR !== 'undefined' : si les deux CDN de repli ont aussi échoué,
        // on évite une ReferenceError silencieuse qui arrêterait la boucle sans erreur visible
        if (video.readyState >= 2 && video.videoWidth > 0 && typeof jsQR !== 'undefined') {
            canvas.height = video.videoHeight;
            canvas.width  = video.videoWidth;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
            if (code) spCalHandleScanResult(code.data);
        }
        requestAnimationFrame(tick);
    }
    tick();
}

var gLastScan = '';
var gLastScanTime = 0;

function spCalHandleScanResult(raw) {
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
    if (!token) return;

    if (!gPtgCours || !gPin) return;

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
        spCalShowFeedback(nom, msg, status);
        if (r.success) spCalAddLog(nom, status);
    })
    .catch(function() { spCalShowFeedback('?', 'Erreur réseau', 'err'); });
}

function spCalShowFeedback(nom, msg, status) {
    var fb = document.getElementById('spcal-scan-feedback');
    fb.className = 'spcal-scan-' + (status === 'ok' ? 'ok' : (status === 'already' ? 'already' : 'err'));
    fb.style.display = 'block';
    document.getElementById('spcal-scan-nom').textContent =
        (status === 'ok' ? '✅ ' : (status === 'already' ? '⚠️ ' : '❌ ')) + nom;
    document.getElementById('spcal-scan-msg').textContent = msg;
    // Effacer après 3s
    clearTimeout(fb._timer);
    fb._timer = setTimeout(function(){ fb.style.display = 'none'; }, 3000);
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
function spCalLoadCalendrierClub(pin) {
    var apiBase = CFG.apiBase || '/wp-json/spcal/v1';
    fetch(apiBase + '/calendrier/club?pin=' + encodeURIComponent(pin) + '&nb=20')
        .then(function(r){ return r.json(); })
        .then(function(r) {
            if (!r.cours) return;
            gCours = r.cours;
            var greet = document.getElementById('spcal-greeting');
            var sub   = document.getElementById('spcal-greeting-sub');
            if (greet) greet.textContent = 'Prochains cours';
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
                      + (c.categorie ? '<div class="spcal-cours-cat">'+esc(c.categorie)+'</div>' : '')
                      + '</div>'
                      + '<div class="spcal-cours-right">'
                      + '<div class="spcal-cours-heure">'+esc(fmtHeure(c.heure_debut))+'</div>'
                      + '<div class="spcal-cours-date">'+esc(fmtDate(c.date))+'</div>'
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
    _origSpCalNav(screen, btn);
};


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

    /* ══ FIN BLOC PWA ══ */

    public function handle_requests() {

        if ( ! current_user_can( 'manage_options' ) ) return;

        // ── Export CSV inscriptions ──────────────────────────────────────
        $this->inscriptions->handle_request();
        global $wpdb;

        $tt  = $this->db->table_trainers();
        $tsl = $this->db->table_slots();
        $tel = $this->db->table_eleves();

        /* ── Date fin de saison ── */
        if ( isset( $_POST['sp_save_fin_saison'] ) && check_admin_referer( 'sp_cal_fin_saison' ) ) {
            $date   = sanitize_text_field( wp_unslash( $_POST['sp_fin_saison']    ?? '' ) );
            $jours  = max( 1, intval( $_POST['sp_alerte_jours'] ?? 60 ) );
            update_option( 'sp_cal_fin_saison',    $date );
            update_option( 'sp_cal_alerte_jours',  $jours );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&saved=1' ) ); exit;
        }

        /* ── Durée de validité du certificat médical (doléance certificat médical, 03/09/2026) ── */
        if ( isset( $_POST['sp_save_certif_medical'] ) && check_admin_referer( 'sp_cal_certif_medical' ) ) {
            $mois = max( 1, min( 60, intval( $_POST['sp_certif_medical_mois'] ?? 12 ) ) );
            update_option( 'sp_cal_certif_medical_mois', $mois );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&certif_saved=1' ) ); exit;
        }

        /* ── Convention médaille → points ── */
        if ( isset( $_POST['sp_save_medal_pts'] ) && check_admin_referer( 'sp_cal_medal_pts' ) ) {
            update_option( 'sp_cal_pts_or',     max( 0, intval( $_POST['sp_pts_or']     ?? 3 ) ) );
            update_option( 'sp_cal_pts_argent', max( 0, intval( $_POST['sp_pts_argent'] ?? 2 ) ) );
            update_option( 'sp_cal_pts_bronze', max( 0, intval( $_POST['sp_pts_bronze'] ?? 1 ) ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&pts_saved=1' ) ); exit;
        }
		
		/* ── Coefficients niveaux compétition ── */
        if ( isset( $_POST['sp_save_coef_niveaux'] ) && check_admin_referer( 'sp_cal_coef_niveaux' ) ) {
            update_option( 'sp_cal_coef_departemental',  max( 0.1, floatval( $_POST['sp_coef_dep'] ?? 1.0 ) ) );
            update_option( 'sp_cal_coef_regional',       max( 0.1, floatval( $_POST['sp_coef_reg'] ?? 1.5 ) ) );
            update_option( 'sp_cal_coef_national',       max( 0.1, floatval( $_POST['sp_coef_nat'] ?? 2.0 ) ) );
            update_option( 'sp_cal_coef_international',  max( 0.1, floatval( $_POST['sp_coef_int'] ?? 3.0 ) ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&coef_saved=1' ) ); exit;
        }

        /* ── URL fiche membre (sauvegarde dédiée) ── */
        if ( isset( $_POST['sp_save_fiche_url'] ) && check_admin_referer( 'sp_cal_fiche_url' ) ) {
            update_option( 'sp_cal_fiche_membre_url', esc_url_raw( $_POST['sp_cal_fiche_membre_url'] ?? '' ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&saved=1' ) ); exit;
        }

        /* ── URL page palmarès public (sauvegarde dédiée) ── */
        if ( isset( $_POST['sp_save_palmares_url'] ) && check_admin_referer( 'sp_cal_palmares_url' ) ) {
            update_option( 'sp_cal_palmares_url', esc_url_raw( $_POST['sp_cal_palmares_url'] ?? '' ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&saved=1' ) ); exit;
        }

        /* ── Création automatique page fiche membre ── */
        if ( isset( $_POST['sp_create_fiche_page'] ) && check_admin_referer( 'sp_cal_create_fiche_page' ) ) {
            // Vérifier qu'elle n'existe pas déjà
            global $wpdb;
            $existing = $wpdb->get_var(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_status='publish' AND post_type='page'
                 AND post_content LIKE '%sp_cal_fiche_membre%' LIMIT 1"
            );
            if ( $existing ) {
                $page_url = get_permalink( $existing );
            } else {
                $page_id = wp_insert_post( array(
                    'post_title'   => 'Ma fiche',
                    'post_name'    => 'ma-fiche',
                    'post_content' => '[sp_cal_fiche_membre]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ) );
                $page_url = $page_id ? get_permalink( $page_id ) : '';
            }
            if ( $page_url ) update_option( 'sp_cal_fiche_membre_url', $page_url );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&fiche_created=1' ) ); exit;
        }

        /* ── Paramètres généraux ── */
        // Vacances — couleurs WE / vacances scolaires
        if ( isset( $_POST['sp_cal_save_vacances'] ) && check_admin_referer( 'sp_cal_vacances' ) ) {
            update_option( 'sp_cal_we_color',  sanitize_hex_color( $_POST['sp_cal_we_color']  ?? '#f3f4f6' ) ?: '#f3f4f6' );
            update_option( 'sp_cal_vac_color', sanitize_hex_color( $_POST['sp_cal_vac_color'] ?? '#fef9c3' ) ?: '#fef9c3' );
            // Périodes manuelles
            $periodes = array();
            $labels = $_POST['vac_label'] ?? array();
            $starts = $_POST['vac_start'] ?? array();
            $ends   = $_POST['vac_end']   ?? array();
            foreach ( $starts as $i => $start ) {
                $start = sanitize_text_field( $start );
                $end   = sanitize_text_field( $ends[$i] ?? '' );
                if ( $start && $end && $start <= $end ) {
                    $periodes[] = array(
                        'label' => sanitize_text_field( $labels[$i] ?? '' ),
                        'start' => $start,
                        'end'   => $end,
                    );
                }
            }
            update_option( 'sp_cal_vacances_zoneC', wp_json_encode( $periodes ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&vac_saved=1' ) ); exit;
        }

        if ( isset( $_POST['sp_cal_save_settings'] ) && check_admin_referer( 'sp_cal_settings' ) ) {
            update_option( 'sp_cal_public_link', esc_url_raw( $_POST['sp_cal_public_link'] ?? '' ) );
            // Informations club
            update_option( 'sp_cal_club_num',          sanitize_text_field( wp_unslash( $_POST['sp_cal_club_num']          ?? '' ) ) );
            update_option( 'sp_cal_club_affiliation',  sanitize_text_field( wp_unslash( $_POST['sp_cal_club_affiliation']  ?? '' ) ) );
            update_option( 'sp_cal_club_ligue',        sanitize_text_field( wp_unslash( $_POST['sp_cal_club_ligue']        ?? '' ) ) );
            update_option( 'sp_cal_club_labelise',     intval(              $_POST['sp_cal_club_labelise']                 ?? 0  ) );
            // NE PAS toucher sp_cal_fiche_membre_url ici — géré par son propre formulaire
            // Notifications
            update_option( 'sp_cal_notif_email',           sanitize_email( wp_unslash( $_POST['sp_cal_notif_email']           ?? get_option('admin_email' ) ) ) );
            update_option( 'sp_cal_notif_anniv',           isset($_POST['sp_cal_notif_anniv'])    ? '1' : '0' );
            update_option( 'sp_cal_notif_anniv_jours',     intval(          $_POST['sp_cal_notif_anniv_jours']     ?? 7 ) );
            update_option( 'sp_cal_notif_absences',        isset($_POST['sp_cal_notif_absences']) ? '1' : '0' );
            update_option( 'sp_cal_notif_absences_seuil',  intval(          $_POST['sp_cal_notif_absences_seuil']  ?? 3 ) );
            // Couleurs catégories
            $colors = array();
            if ( ! empty( $_POST['cat_color'] ) ) {
                // cat_name[md5(cat)] = nom réel de la catégorie (champ caché dans le formulaire)
                $cat_names = $_POST['cat_name'] ?? array();
                foreach ( $_POST['cat_color'] as $key => $color ) {
                    $cat = sanitize_text_field( $cat_names[ $key ] ?? '' );
                    if ( ! $cat ) continue;
                    $colors[ $cat ] = array(
                        'color' => sanitize_hex_color( $color ) ?: '#3B82F6',
                        'icon'  => sanitize_text_field( $_POST['cat_icon'][ $key ] ?? '' ),
                    );
                }
            }
            update_option( 'sp_cal_cat_colors', wp_json_encode( $colors ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&saved=1' ) ); exit;
        }

        /* ── Règlement intérieur : enregistrer (popup formulaire d'adhésion) ── */
        if ( isset( $_POST['sp_cal_save_reglement'] ) && check_admin_referer( 'sp_cal_reglement' ) ) {
            update_option( 'sp_cal_reglement_interieur', wp_kses_post( wp_unslash( $_POST['sp_cal_reglement_interieur'] ?? '' ) ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&reglement_saved=1' ) ); exit;
        }

        /* ── Veille réglementaire : pense-bête, pas d'appel automatique (cf. doleances.md) ── */
        if ( isset( $_POST['sp_veille_reglementaire_demandee'] ) && check_admin_referer( 'sp_cal_veille_reglementaire' ) ) {
            update_option( 'sp_cal_veille_reglementaire_demandee_le', current_time( 'Y-m-d' ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&veille_demandee=1' ) ); exit;
        }

        /* ── Récapitulatif mensuel : enregistrer ── */
        if ( isset( $_POST['sp_cal_save_recap'] ) && check_admin_referer( 'sp_cal_recap_settings' ) ) {
            update_option( 'sp_cal_recap_actif',   isset( $_POST['sp_cal_recap_actif'] ) ? '1' : '0' );
            update_option( 'sp_cal_recap_jour',    max( 1, min( 28, intval( $_POST['sp_cal_recap_jour']  ?? 1 ) ) ) );
            update_option( 'sp_cal_tarif_km',      floatval( str_replace( ',', '.', $_POST['sp_cal_tarif_km'] ?? '0' ) ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&recap_saved=1' ) ); exit;
        }

        /* ── Reset events ── */
        if ( isset( $_POST['sp_reset_events'] ) && check_admin_referer( 'sp_reset_events' ) ) {
            $wpdb->query( "TRUNCATE TABLE {$this->db->table_events()}" );
            $wpdb->query( "TRUNCATE TABLE {$this->db->table_presences_eleves()}" );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&events_reset=1' ) ); exit;
        }

        /* ── Nettoyage events parasites (doublons de matérialisation) ── */
        if ( isset( $_POST['sp_cleanup_mats'] ) && check_admin_referer( 'sp_cleanup_mats' ) ) {
            global $wpdb;
            $te = $this->db->table_events();
            // Supprimer les events type='cours' avec slot_id NOT NULL qui sont en doublon
            // (garder le plus ancien pour chaque slot+date, supprimer les autres)
            $wpdb->query(
                "DELETE e1 FROM $te e1
                 INNER JOIN $te e2
                 ON e1.slot_id = e2.slot_id AND e1.date = e2.date AND e1.type = 'cours' AND e2.type = 'cours'
                 WHERE e1.id > e2.id AND e1.slot_id IS NOT NULL"
            );
            // Supprimer aussi les events type='cours' avec slot_id NULL créés par erreur
            // (ceux qui ont le même titre/date/heure qu'un créneau récurrent)
            $tsl = $this->db->table_slots();
            $wpdb->query(
                "DELETE e FROM $te e
                 INNER JOIN $tsl s ON s.label = e.titre AND e.slot_id IS NULL AND e.type = 'cours'
                 WHERE NOT EXISTS (
                     SELECT 1 FROM {$this->db->table_presences_eleves()} pe WHERE pe.event_id = e.id
                 )"
            );
            $cleaned = $wpdb->rows_affected;
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&cleaned=' . $cleaned ) ); exit;
        }

        /* ── Migration Discipline / Pour qui des événements (ex-champ "categorie" libre) ── */
        if ( isset( $_POST['sp_migrer_cours_categories'] ) && check_admin_referer( 'sp_migrer_cours_categories' ) ) {
            $dry = isset( $_POST['sp_migrer_preview'] );
            if ( $dry ) {
                $preview = $this->db->preview_migration_cours_categories();
                set_transient( 'sp_migrer_cours_cat_preview_' . get_current_user_id(), $preview, 300 );
                wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&migration_cours_preview=1' ) ); exit;
            }
            $total = $this->db->appliquer_migration_cours_categories();
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&migration_cours_done=1&nb=' . $total ) ); exit;
        }

        /* ── Reset élèves ── */
        if ( isset( $_POST['sp_reset_eleves'] ) && check_admin_referer( 'sp_reset_eleves' ) ) {
            $wpdb->query( "TRUNCATE TABLE {$this->db->table_eleves()}" );
            $wpdb->query( "TRUNCATE TABLE {$this->db->table_presences_eleves()}" );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&eleves_reset=1' ) ); exit;
        }

        /* ── Trainer : sauvegarder ── */
        if ( isset( $_POST['sp_cal_save_trainer'] ) && check_admin_referer( 'sp_cal_save_trainer' ) ) {
            $data = array(
                'nom'             => sanitize_text_field( wp_unslash( $_POST['trainer_nom']      ?? '' ) ),
                'nom_public'      => sanitize_text_field( wp_unslash( $_POST['trainer_pubnom']   ?? '' ) ),
                'roles'           => sanitize_text_field( wp_unslash( $_POST['trainer_roles']    ?? '' ) ),
                'telephone'       => sanitize_text_field( wp_unslash( $_POST['trainer_tel']      ?? '' ) ),
                'email'           => sanitize_email( wp_unslash( $_POST['trainer_email']    ?? '' ) ),
                'ordre'           => intval(                          $_POST['trainer_ordre']    ?? 0    ),
                'actif'           => intval(                          $_POST['trainer_actif']    ?? 1    ),
                'km_aller_retour' => floatval( str_replace( ',', '.', $_POST['trainer_km'] ?? '0' ) ),
                'photo_url'       => esc_url_raw( wp_unslash( $_POST['trainer_photo_url'] ?? '' ) ),
                'fonction'        => sanitize_text_field( wp_unslash( $_POST['trainer_fonction']        ?? '' ) ),
                'fonction_bureau' => sanitize_text_field( wp_unslash( $_POST['trainer_fonction_bureau'] ?? '' ) ),
            );
            $id = intval( $_POST['trainer_id'] ?? 0 );
            if ( $id ) $wpdb->update( $tt, $data, array( 'id' => $id ) );
            else        $wpdb->insert( $tt, $data );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-trainers&saved=1' ) ); exit;
        }

        /* ── Trainer : supprimer ── */
        if ( isset( $_GET['sp_delete_trainer'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'], 'sp_delete_trainer_' . intval( $_GET['sp_delete_trainer'] ) ) ) {
                $wpdb->delete( $tt, array( 'id' => intval( $_GET['sp_delete_trainer'] ) ) );
                wp_redirect( admin_url( 'admin.php?page=sp-cal-trainers&deleted=1' ) ); exit;
            }
        }

        /* ── Slot : sauvegarder ── */
        if ( isset( $_POST['sp_cal_save_slot'] ) && check_admin_referer( 'sp_cal_save_slot' ) ) {
            $rec = sanitize_text_field( wp_unslash( $_POST['slot_recurrence'] ?? 'weekly' ) );
            $dd  = sanitize_text_field( wp_unslash( $_POST['slot_date_debut'] ?? '' ) );
            $df  = sanitize_text_field( wp_unslash( $_POST['slot_date_fin']   ?? '' ) );
            $data = array(
                'jour'       => intval( $_POST['slot_jour']      ?? 1 ),
                'label'      => sanitize_text_field( wp_unslash( $_POST['slot_label']    ?? '' ) ),
                'heure_debut'=> sanitize_text_field( wp_unslash( $_POST['slot_debut_h']  ?? '08' ) ) . 'h' . sanitize_text_field( wp_unslash( $_POST['slot_debut_m'] ?? '00' ) ),
                'heure_fin'  => sanitize_text_field( wp_unslash( $_POST['slot_fin_h']    ?? '09' ) ) . 'h' . sanitize_text_field( wp_unslash( $_POST['slot_fin_m']   ?? '00' ) ),
                'categorie'  => sanitize_text_field( wp_unslash( $_POST['slot_categorie'] ?? '' ) ),
                'ordre'      => intval( $_POST['slot_ordre'] ?? 0 ),
                'recurrence' => in_array( $rec, array('weekly','biweekly','3weekly','monthly_1','monthly_2','monthly_3','monthly_4','monthly_5','monthly_6','monthly_7','monthly_8','monthly_9') ) ? $rec : 'weekly',
                'date_debut' => $dd ?: null,
                'date_fin'   => $df ?: null,
            );
            $id = intval( $_POST['slot_id'] ?? 0 );
            if ( $id ) $wpdb->update( $tsl, $data, array( 'id' => $id ) );
            else        $wpdb->insert( $tsl, $data );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-slots&saved=1' ) ); exit;
        }

        /* ── Slot : supprimer ── */
        if ( isset( $_GET['sp_delete_slot'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'], 'sp_delete_slot_' . intval( $_GET['sp_delete_slot'] ) ) ) {
                $wpdb->delete( $tsl, array( 'id' => intval( $_GET['sp_delete_slot'] ) ) );
                wp_redirect( admin_url( 'admin.php?page=sp-cal-slots&deleted=1' ) ); exit;
            }
        }

        // Module membres — dispatch déplacé sur son propre hook admin_init avec sa propre
        // capacité (cf. SP_Cal_Members::__construct(), doleances.md 09/09/2026) : ce
        // dispatcher-ci reste manage_options uniquement, mais la Secrétaire doit pouvoir
        // sauvegarder une fiche élève sans avoir cette capacité globale.

        // Module exam — dispatch form submissions
        $this->exam->handle_request();
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

    /* ══════════════════════════════════════════════════════════
       PAGE : Entraîneurs
    ══════════════════════════════════════════════════════════ */

    public function page_trainers() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        global $wpdb;
        $tt       = $this->db->table_trainers();
        // Liste principale : uniquement les personnes ayant le rôle "entraineur"
        // (les membres purement "bureau" sont affichés dans la section dédiée ci-dessous)
        $trainers = $this->db->get_trainers_entraineurs();

        $edit = null;
        if ( isset( $_GET['sp_edit_trainer'] ) ) {
            $edit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tt WHERE id=%d", intval( $_GET['sp_edit_trainer'] ) ) );
        }

        $jours_labels = array( 1=>'Lun', 2=>'Mar', 3=>'Mer', 4=>'Jeu', 5=>'Ven', 6=>'Sam', 7=>'Dim' );
        ?>
        <div class="wrap sp-cal-wrap">
        <h1>Entraîneurs &amp; Bureau</h1>

        <?php $this->notice_flash( 'saved', 'Entraîneur enregistré.' ); ?>
        <?php $this->notice_flash( 'deleted', 'Entraîneur supprimé.' ); ?>

        <div class="sp-box">
            <h2><?php echo $edit ? '✏️ Modifier' : '➕ Ajouter un entraîneur'; ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_save_trainer' ); ?>
                <input type="hidden" name="trainer_id" value="<?php echo $edit ? intval( $edit->id ) : 0; ?>">
                <table class="form-table" style="max-width:640px;">
                    <tr><th>Nom</th><td><input type="text" name="trainer_nom" class="regular-text" value="<?php echo esc_attr( $edit->nom ?? '' ); ?>" required></td></tr>
                    <tr><th>Nom public</th><td><input type="text" name="trainer_pubnom" class="regular-text" value="<?php echo esc_attr( $edit->nom_public ?? '' ); ?>"></td></tr>
                    <tr><th>Fonction sportif</th><td>
                        <input type="text" name="trainer_fonction" class="regular-text"
                               value="<?php echo esc_attr( $edit->fonction ?? '' ); ?>"
                               placeholder="Ex: Coach principal, Entraîneur…">
                        <p class="description" style="margin-top:4px;">Rôle sportif — affiché si renseigné.</p>
                    </td></tr>
                    <tr><th>Fonction bureau</th><td>
                        <input type="text" name="trainer_fonction_bureau" class="regular-text"
                               value="<?php echo esc_attr( $edit->fonction_bureau ?? '' ); ?>"
                               placeholder="Ex: Président, Trésorière, Secrétaire…">
                        <p class="description" style="margin-top:4px;">Rôle au bureau — affiché si renseigné.</p>
                    </td></tr>
                    <tr><th>Rôles</th><td>
                        <?php
                        $roles_val  = $edit->roles ?? '';
                        $roles_list = array( 'entraineur' => '🥋 Entraîneur', 'bureau' => '🏛️ Bureau', 'eleve' => '🎓 Élève' );
                        foreach ( $roles_list as $rv => $rl ) {
                            $checked = ( strpos($roles_val, $rv) !== false ) ? ' checked' : '';
                            echo '<label style="display:inline-flex;align-items:center;gap:5px;margin-right:14px;cursor:pointer;">';
                            echo '<input type="checkbox" name="trainer_roles_cb[]" value="' . $rv . '"' . $checked . '> ' . $rl;
                            echo '</label>';
                        }
                        ?>
                        <input type="hidden" name="trainer_roles" id="trainer_roles_hidden" value="<?php echo esc_attr($roles_val); ?>">
                        <script>
                        (function(){
                            var cbs = document.querySelectorAll('input[name="trainer_roles_cb[]"]');
                            function sync(){ document.getElementById('trainer_roles_hidden').value = Array.from(cbs).filter(function(c){return c.checked;}).map(function(c){return c.value;}).join(','); }
                            cbs.forEach(function(c){ c.addEventListener('change', sync); });
                        })();
                        </script>
                    </td></tr>
                    <tr><th>Téléphone</th><td><input type="text" name="trainer_tel" class="regular-text" value="<?php echo esc_attr( $edit->telephone ?? '' ); ?>"></td></tr>
                    <tr><th>Email</th><td><input type="email" name="trainer_email" class="regular-text" value="<?php echo esc_attr( $edit->email ?? '' ); ?>"></td></tr>
                    <tr>
                        <th>📍 Km aller-retour</th>
                        <td>
                            <input type="number" name="trainer_km" min="0" max="999" step="0.5"
                                   value="<?php echo esc_attr( number_format( floatval( $edit->km_aller_retour ?? 0 ), 1, '.', '' ) ); ?>"
                                   style="width:90px;"> km
                            <p class="description" style="margin-top:4px;">Distance domicile → salle (aller-retour). Utilisée dans le récapitulatif mensuel.</p>
                        </td>
                    </tr>
                    <tr><th>Ordre d'affichage</th><td><input type="number" name="trainer_ordre" value="<?php echo intval( $edit->ordre ?? 0 ); ?>" style="width:80px;"></td></tr>
                    <tr><th>Actif</th><td><input type="checkbox" name="trainer_actif" value="1" <?php checked( $edit->actif ?? 1 ); ?>></td></tr>
                    <tr>
                        <th>📷 Photo de profil</th>
                        <td>
                            <div style="display:flex;align-items:flex-start;gap:16px;">
                                <div id="sp-trainer-photo-preview" style="width:80px;height:80px;border-radius:50%;overflow:hidden;background:#e5e7eb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <?php if ( ! empty($edit->photo_url) ) : ?>
                                        <img src="<?php echo esc_url($edit->photo_url); ?>" style="width:100%;height:100%;object-fit:cover;" id="sp-trainer-photo-img">
                                    <?php else : ?>
                                        <span id="sp-trainer-photo-placeholder" style="font-size:28px;color:#9ca3af;">👤</span>
                                        <img src="" style="width:100%;height:100%;object-fit:cover;display:none;" id="sp-trainer-photo-img">
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <input type="hidden" name="trainer_photo_url" id="trainer_photo_url" value="<?php echo esc_attr($edit->photo_url ?? ''); ?>">
                                    <button type="button" class="button" id="sp-trainer-photo-btn">📁 Choisir une photo</button>
                                    <button type="button" class="button" id="sp-trainer-photo-clear" style="margin-left:6px;color:#dc2626;<?php echo empty($edit->photo_url) ? 'display:none;' : ''; ?>">✕ Supprimer</button>
                                    <p class="description" style="margin-top:6px;">Recommandé : carré, minimum 200×200 px.</p>
                                </div>
                            </div>
                            <script>
                            (function($){
                                var frame;
                                $('#sp-trainer-photo-btn').on('click', function(e){
                                    e.preventDefault();
                                    if (frame) { frame.open(); return; }
                                    frame = wp.media({ title: 'Choisir une photo', button: { text: 'Utiliser cette photo' }, multiple: false, library: { type: 'image' } });
                                    frame.on('select', function(){
                                        var att = frame.state().get('selection').first().toJSON();
                                        $('#trainer_photo_url').val(att.url);
                                        $('#sp-trainer-photo-img').attr('src', att.url).show();
                                        $('#sp-trainer-photo-placeholder').hide();
                                        $('#sp-trainer-photo-clear').show();
                                    });
                                    frame.open();
                                });
                                $('#sp-trainer-photo-clear').on('click', function(){
                                    $('#trainer_photo_url').val('');
                                    $('#sp-trainer-photo-img').attr('src','').hide();
                                    $('#sp-trainer-photo-placeholder').show();
                                    $(this).hide();
                                });
                            })(jQuery);
                            </script>
                        </td>
                    </tr>
                </table>
                <p>
                    <input type="submit" name="sp_cal_save_trainer" class="button button-primary" value="<?php echo $edit ? 'Mettre à jour' : 'Ajouter'; ?>">
                    <?php if ( $edit ) echo '<a href="' . admin_url( 'admin.php?page=sp-cal-trainers' ) . '" class="button" style="margin-left:8px;">Annuler</a>'; ?>
                </p>
            </form>
        </div>

        <div class="sp-box">
            <h2>Liste des entraîneurs (<?php echo count( $trainers ); ?> entraîneur<?php echo count( $trainers ) > 1 ? 's' : ''; ?>)</h2>
            <?php if ( empty( $trainers ) ) : ?>
                <p class="sp-muted">Aucun entraîneur configuré.</p>
            <?php else :
            $role_badges = array(
                'entraineur' => array('label'=>'Entraîneur', 'bg'=>'#1e3a5f','color'=>'#fff'),
                'bureau'     => array('label'=>'Bureau',     'bg'=>'#7c3aed','color'=>'#fff'),
                'eleve'      => array('label'=>'Élève',      'bg'=>'#15803d','color'=>'#fff'),
            );
            ?>
            <table class="wp-list-table widefat striped">
                <thead><tr>
                    <th style="width:50px;">Ordre</th>
                    <th>Nom</th>
                    <th>Rôles</th>
                    <th>Téléphone</th>
                    <th>Email</th>
                    <th style="width:80px;" title="Distance domicile → salle (aller-retour), utilisée dans le récap mensuel">📍 Km A/R</th>
                    <th style="width:80px;">Actif</th>
                    <th style="width:110px;">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $trainers as $t ) :
                    $del      = wp_nonce_url( admin_url( 'admin.php?page=sp-cal-trainers&sp_delete_trainer=' . $t->id ), 'sp_delete_trainer_' . $t->id );
                    $edit_url = admin_url( 'admin.php?page=sp-cal-trainers&sp_edit_trainer=' . $t->id );
                    $t_roles  = array_filter( array_map( 'trim', explode(',', $t->roles) ) );
                ?>
                <tr>
                    <td style="text-align:center;"><?php echo intval( $t->ordre ); ?></td>
                    <td>
                        <strong><?php echo esc_html( $t->nom ); ?></strong>
                        <?php if ( $t->nom_public && $t->nom_public !== $t->nom ) echo ' <em class="sp-muted">(' . esc_html( $t->nom_public ) . ')</em>'; ?>
                        <?php if ( ! empty($t->fonction) ) echo '<br><span style="font-size:11px;color:#64748b;">🥋 ' . esc_html($t->fonction) . '</span>'; ?>
                        <?php if ( ! empty($t->fonction_bureau) ) echo '<br><span style="font-size:11px;color:#64748b;">🏛️ ' . esc_html($t->fonction_bureau) . '</span>'; ?>
                    </td>
                    <td><?php
                        foreach ( $t_roles as $rv ) {
                            $rb = $role_badges[$rv] ?? null;
                            if ( $rb ) echo '<span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:700;background:'.esc_attr($rb['bg']).';color:'.esc_attr($rb['color']).';margin-right:4px;">'.esc_html($rb['label']).'</span>';
                            else echo '<span class="sp-muted">'.esc_html($rv).'</span> ';
                        }
                    ?></td>
                    <td><?php echo esc_html( $t->telephone ); ?></td>
                    <td><?php echo esc_html( $t->email ); ?></td>
                    <td style="text-align:center;"><?php
                        $km = floatval($t->km_aller_retour ?? 0);
                        echo $km > 0 ? '<strong>' . number_format($km, 1, ',', '') . '</strong> km' : '<span class="sp-muted">—</span>';
                    ?></td>
                    <td style="text-align:center;"><?php echo $t->actif ? '<span style="color:#15803d;font-weight:700;">✔</span>' : '<span style="color:#aaa;">–</span>'; ?></td>
                    <td>
                        <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">✏️</a>
                        <?php if ( ! empty( $t->email ) ) : ?>
                        <button type="button" class="button button-small sp-btn-send-trainer-app"
                            data-id="<?php echo intval( $t->id ); ?>"
                            data-nom="<?php echo esc_attr( $t->nom ); ?>"
                            title="Envoyer le lien application pointage">📲</button>
                        <button type="button" class="button button-small sp-btn-send-dispo-app"
                            data-id="<?php echo intval( $t->id ); ?>"
                            data-nom="<?php echo esc_attr( $t->nom ); ?>"
                            title="Envoyer le lien Disponibilités">🗓️</button>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( $del ); ?>" class="button button-small sp-btn-del" onclick="return confirm('Supprimer cet entraîneur ?')">🗑️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <script>
        (function($){
            $('.sp-btn-send-trainer-app').on('click', function() {
                var id  = $(this).data('id');
                var nom = $(this).data('nom');
                if (!confirm('Envoyer le lien application à ' + nom + ' ?')) return;
                var $btn = $(this).prop('disabled', true).text('⏳');
                $.post(ajaxurl, {
                    action:     'sp_cal_send_trainer_app',
                    trainer_id: id,
                    nonce: '<?php echo wp_create_nonce("sp_cal_admin_nonce"); ?>'
                }, function(r) {
                    if (r.success) {
                        $btn.text('✅').css('color','#15803d');
                        setTimeout(function(){ $btn.prop('disabled',false).text('📲').css('color',''); }, 3000);
                    } else {
                        alert('Erreur : ' + (r.data || 'Envoi échoué'));
                        $btn.prop('disabled',false).text('📲');
                    }
                });
            });
            $('.sp-btn-send-dispo-app').on('click', function() {
                var id  = $(this).data('id');
                var nom = $(this).data('nom');
                if (!confirm('Envoyer le lien Disponibilités à ' + nom + ' ?')) return;
                var $btn = $(this).prop('disabled', true).text('⏳');
                $.post(ajaxurl, {
                    action:     'sp_cal_send_dispo_app_link',
                    trainer_id: id,
                    nonce: '<?php echo wp_create_nonce("sp_cal_admin_nonce"); ?>'
                })
                .done(function(r) {
                    if (r && r.success) {
                        $btn.text('✅').css('color','#15803d');
                        setTimeout(function(){ $btn.prop('disabled',false).text('🗓️').css('color',''); }, 3000);
                    } else {
                        alert('Erreur : ' + (r && r.data ? r.data : 'Envoi échoué'));
                        $btn.prop('disabled',false).text('🗓️');
                    }
                })
                // Sans ce .fail(), une requête en échec (erreur PHP fatale, timeout...) laissait
                // le bouton bloqué indéfiniment sur "⏳" sans aucun message — bug signalé le
                // 09/09/2026, cause exacte jamais confirmée faute de retour visible.
                .fail(function(xhr) {
                    alert('Erreur réseau/serveur (HTTP ' + xhr.status + ') — voir le journal du site pour le détail.');
                    $btn.prop('disabled',false).text('🗓️');
                });
            });
        })(jQuery);
        </script>

        <!-- MEMBRES DU BUREAU -->
        <?php $bureau = $this->db->get_bureau_members(); ?>
        <div class="sp-box" style="margin-top:18px;">
            <h2>🏛️ Membres du bureau (<?php echo count($bureau); ?>)</h2>
            <?php if ( empty($bureau) ) : ?>
                <p class="sp-muted">Aucun membre ayant le rôle "Bureau". Cochez "Bureau" dans les rôles d'un entraîneur pour l'y faire apparaître.</p>
            <?php else : ?>
            <table class="wp-list-table widefat striped">
                <thead><tr>
                    <th>Nom</th><th>Autres rôles</th><th>Téléphone</th><th>Email</th><th style="width:110px;">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $bureau as $m ) :
                    $other_roles = array_filter( array_map('trim', explode(',', $m->roles)), function($r){ return $r !== 'bureau'; } );
                    $edit_url    = admin_url( 'admin.php?page=sp-cal-trainers&sp_edit_trainer=' . $m->id );
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($m->nom); ?></strong>
                        <?php if ($m->nom_public && $m->nom_public !== $m->nom) echo ' <em class="sp-muted">('.esc_html($m->nom_public).')</em>'; ?>
                        <?php if ( ! empty($m->fonction_bureau) ) echo '<br><span style="font-size:11px;color:#64748b;">🏛️ ' . esc_html($m->fonction_bureau) . '</span>'; ?>
                        <?php if ( ! empty($m->fonction) ) echo '<br><span style="font-size:11px;color:#64748b;">🥋 ' . esc_html($m->fonction) . '</span>'; ?>
                    </td>
                    <td class="sp-muted"><?php echo esc_html(implode(', ', $other_roles)); ?></td>
                    <td><?php echo esc_html($m->telephone); ?></td>
                    <td><?php echo esc_html($m->email); ?></td>
                    <td><a href="<?php echo esc_url($edit_url); ?>" class="button button-small">✏️</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════════════════════════════════════
             BLOC : Déplacements kilométriques exceptionnels
        ════════════════════════════════════════════════════ -->
        <?php
        $trainers_e = $this->db->get_trainers_entraineurs( true );
        // Déplacements du mois en cours
        $tkm2  = $this->db->table_km_exceptionnels();
        $tt2   = $this->db->table_trainers();
        $cur_y = intval(date('Y')); $cur_m = intval(date('m'));
        $start_m = sprintf('%04d-%02d-01', $cur_y, $cur_m);
        $end_m   = date('Y-m-t', strtotime($start_m));
        $km_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT k.*, t.nom AS trainer_nom FROM $tkm2 k
             INNER JOIN $tt2 t ON t.id = k.trainer_id
             WHERE k.date BETWEEN %s AND %s ORDER BY k.date ASC, k.id ASC",
            $start_m, $end_m
        ) );
        ?>
        <div class="sp-box">
            <h2>🚗 Déplacements kilométriques exceptionnels</h2>
            <p class="description">Saisissez un déplacement hors trajet habituel (compétition, stage, etc.). Les km s'ajoutent au récapitulatif mensuel de l'entraîneur concerné.</p>

            <!-- Formulaire de saisie -->
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;max-width:900px;margin-bottom:16px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Entraîneur</label>
                    <select id="km-excep-trainer" style="min-width:160px;">
                        <option value="">— Choisir —</option>
                        <?php foreach($trainers_e as $t): ?>
                        <option value="<?php echo intval($t->id); ?>"><?php echo esc_html($t->nom); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Date</label>
                    <input type="date" id="km-excep-date" value="<?php echo esc_attr(date('Y-m-d')); ?>" style="width:150px;">
                </div>
                <div style="flex:1;min-width:180px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Description</label>
                    <input type="text" id="km-excep-desc" placeholder="Ex : Coupe de Claira" style="width:100%;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Km aller-retour</label>
                    <input type="number" id="km-excep-km" min="1" step="0.5" placeholder="84" style="width:90px;">
                </div>
                <div>
                    <button class="button button-primary" id="km-excep-add-btn">➕ Ajouter</button>
                </div>
            </div>
            <div id="km-excep-result" style="font-size:13px;margin-bottom:10px;display:none;"></div>

            <!-- Liste du mois en cours -->
            <div id="km-excep-table-wrap">
            <?php if ( $km_rows ) : ?>
            <h3 style="font-size:13px;font-weight:600;margin-bottom:8px;">
                📅 Déplacements de <?php echo esc_html( (new DateTime($start_m))->format('F Y') ); ?>
            </h3>
            <table class="wp-list-table widefat fixed striped" style="max-width:860px;">
                <thead><tr>
                    <th>Entraîneur</th><th>Date</th><th>Description</th><th>Km A/R</th><th style="width:80px;"></th>
                </tr></thead>
                <tbody id="km-excep-tbody">
                <?php foreach($km_rows as $kr): ?>
                <tr id="km-excep-row-<?php echo intval($kr->id); ?>">
                    <td><?php echo esc_html($kr->trainer_nom); ?></td>
                    <td><?php echo esc_html( date('d/m/Y', strtotime($kr->date)) ); ?></td>
                    <td><?php echo esc_html($kr->description); ?></td>
                    <td><?php echo number_format(floatval($kr->km),1,',',' '); ?> km</td>
                    <td>
                        <button class="button button-small sp-km-excep-delete" style="color:#dc2626;"
                                data-id="<?php echo intval($kr->id); ?>"
                                onclick="spKmExcepDelete(<?php echo intval($kr->id); ?>)">🗑️</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p id="km-excep-empty" class="sp-muted" style="font-size:13px;">Aucun déplacement exceptionnel ce mois.</p>
            <?php endif; ?>
            </div>
        </div>

        <script>
        (function($){
            var nonce = '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>';
            $('#km-excep-add-btn').on('click', function(){
                var trainer = $('#km-excep-trainer').val();
                var date    = $('#km-excep-date').val();
                var desc    = $('#km-excep-desc').val().trim();
                var km      = $('#km-excep-km').val();
                var $res    = $('#km-excep-result');
                if (!trainer) { $res.show().css('color','#dc2626').text('⚠️ Choisissez un entraîneur.'); return; }
                if (!date)    { $res.show().css('color','#dc2626').text('⚠️ Date manquante.'); return; }
                if (!km || parseFloat(km) <= 0) { $res.show().css('color','#dc2626').text('⚠️ Km invalides.'); return; }
                var $btn = $(this).prop('disabled',true).text('…');
                $.post(ajaxurl,{
                    action:'sp_cal_save_km_excep', nonce:nonce,
                    trainer_id:trainer, date:date, description:desc, km:km
                }, function(res){
                    $btn.prop('disabled',false).text('➕ Ajouter');
                    if (!res.success) { $res.show().css('color','#dc2626').text('❌ '+res.data); return; }
                    $res.show().css('color','#15803d').text('✅ Déplacement enregistré.');
                    // Recharger la page pour rafraîchir le tableau
                    setTimeout(function(){ location.reload(); }, 800);
                });
            });
        }(jQuery));
        function spKmExcepDelete(id){
            if (!confirm('Supprimer ce déplacement ?')) return;
            jQuery.post(ajaxurl,{action:'sp_cal_delete_km_excep',nonce:'<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',km_id:id},function(res){
                if (res.success) jQuery('#km-excep-row-'+id).fadeOut(300, function(){ jQuery(this).remove(); });
            });
        }
        </script>

        </div><!-- /.wrap -->
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       PAGE : Créneaux horaires
    ══════════════════════════════════════════════════════════ */

    public function page_slots() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        global $wpdb;
        $tsl   = $this->db->table_slots();
        $slots = $this->db->get_slots();

        $edit = null;
        if ( isset( $_GET['sp_edit_slot'] ) ) {
            $edit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tsl WHERE id=%d", intval( $_GET['sp_edit_slot'] ) ) );
        }

        $jours = array( 1=>'Lundi', 2=>'Mardi', 3=>'Mercredi', 4=>'Jeudi', 5=>'Vendredi', 6=>'Samedi', 7=>'Dimanche' );
        $jours_court = array( 1=>'Lun', 2=>'Mar', 3=>'Mer', 4=>'Jeu', 5=>'Ven', 6=>'Sam', 7=>'Dim' );

        $rec_options = array(
            'weekly'    => '🔄 Chaque semaine',
            'biweekly'  => '🔄 1 semaine / 2',
            '3weekly'   => '🔄 1 semaine / 3',
            'monthly_1' => '📅 Chaque mois',
            'monthly_2' => '📅 Tous les 2 mois',
            'monthly_3' => '📅 Tous les 3 mois',
            'monthly_4' => '📅 Tous les 4 mois',
            'monthly_5' => '📅 Tous les 5 mois',
            'monthly_6' => '📅 Tous les 6 mois',
            'monthly_7' => '📅 Tous les 7 mois',
            'monthly_8' => '📅 Tous les 8 mois',
            'monthly_9' => '📅 Tous les 9 mois',
        );

        $ed = array( 'jour'=>1, 'label'=>'', 'cat'=>'', 'ordre'=>0, 'dh'=>'08', 'dm'=>'00', 'fh'=>'09', 'fm'=>'00', 'rec'=>'weekly', 'dd'=>'', 'df'=>'' );
        if ( $edit ) {
            $ed['jour']  = intval( $edit->jour );
            $ed['label'] = $edit->label;
            $ed['cat']   = $edit->categorie;
            $ed['ordre'] = intval( $edit->ordre );
            $ed['rec']   = $edit->recurrence ?? 'weekly';
            $ed['dd']    = $edit->date_debut ?? '';
            $ed['df']    = $edit->date_fin   ?? '';
            if ( preg_match( '/^(\d{2})h(\d{2})$/', $edit->heure_debut, $m ) ) { $ed['dh'] = $m[1]; $ed['dm'] = $m[2]; }
            if ( preg_match( '/^(\d{2})h(\d{2})$/', $edit->heure_fin,   $m ) ) { $ed['fh'] = $m[1]; $ed['fm'] = $m[2]; }
        }

        $by_jour = array();
        foreach ( $slots as $s ) $by_jour[ intval($s->jour) ][] = $s;
        ?>
        <div class="wrap sp-cal-wrap">
        <h1>Créneaux horaires</h1>
        <?php $this->notice_flash( 'saved', 'Créneau enregistré.' ); ?>
        <?php $this->notice_flash( 'deleted', 'Créneau supprimé.' ); ?>

        <div class="sp-two-col">

        <!-- FORMULAIRE -->
        <div class="sp-box">
            <h2><?php echo $edit ? '✏️ Modifier le créneau' : '➕ Nouveau créneau'; ?></h2>
            <p class="description">Un même intitulé peut exister sur plusieurs jours avec des cours différents.</p>
            <form method="post" style="margin-top:12px;">
                <?php wp_nonce_field( 'sp_cal_save_slot' ); ?>
                <input type="hidden" name="slot_id" value="<?php echo $edit ? intval($edit->id) : 0; ?>">
                <table class="form-table">
                    <tr>
                        <th>Jour</th>
                        <td><select name="slot_jour" class="sp-select">
                            <?php foreach($jours as $n=>$l) echo '<option value="'.  $n .'"'. selected($ed['jour'],$n,false) .'>'. $l .'</option>'; ?>
                        </select></td>
                    </tr>
                    <tr>
                        <th>Intitulé du cours</th>
                        <td><input type="text" name="slot_label" class="regular-text" value="<?php echo esc_attr($ed['label']); ?>" placeholder="ex: Cours TKD adultes" required></td>
                    </tr>
                    <tr>
                        <th>Heure de début</th>
                        <td class="sp-time-row">
                            <?php echo $this->time_select('slot_debut_h',$ed['dh'],1,24); ?> h
                            <?php echo $this->time_select('slot_debut_m',$ed['dm']); ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Heure de fin</th>
                        <td class="sp-time-row">
                            <?php echo $this->time_select('slot_fin_h',$ed['fh'],1,24); ?> h
                            <?php echo $this->time_select('slot_fin_m',$ed['fm']); ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Catégorie</th>
                        <td><input type="text" name="slot_categorie" class="regular-text" value="<?php echo esc_attr($ed['cat']); ?>" placeholder="TKD, Boxe, Renfo…"></td>
                    </tr>
                    <tr>
                        <th>🔄 Récurrence</th>
                        <td>
                            <select name="slot_recurrence" class="sp-select" id="slot-rec-sel">
                                <?php foreach($rec_options as $rv=>$rl) echo '<option value="'. $rv .'"'. selected($ed['rec'],$rv,false) .'>'. $rl .'</option>'; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Date de début</th>
                        <td>
                            <input type="date" name="slot_date_debut" id="slot-date-debut" value="<?php echo esc_attr($ed['dd']); ?>" class="sp-input" style="width:160px;">
                            <p class="description">Requise pour biweekly, 3weekly et mensuel. Définit la semaine de référence.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Date de fin <small>(optionnel)</small></th>
                        <td><input type="date" name="slot_date_fin" value="<?php echo esc_attr($ed['df']); ?>" class="sp-input" style="width:160px;"></td>
                    </tr>
                    <tr>
                        <th>Ordre</th>
                        <td><input type="number" name="slot_ordre" value="<?php echo $ed['ordre']; ?>" style="width:80px;"></td>
                    </tr>
                </table>
                <p>
                    <input type="submit" name="sp_cal_save_slot" class="button button-primary" value="<?php echo $edit ? 'Mettre à jour' : 'Ajouter le créneau'; ?>">
                    <?php if($edit) echo '<a href="'. admin_url('admin.php?page=sp-cal-slots') .'" class="button" style="margin-left:8px;">Annuler</a>'; ?>
                </p>
            </form>
            <script>
            document.getElementById('slot-rec-sel').addEventListener('change', function(){
                var needsDate = (this.value !== 'weekly');
                document.getElementById('slot-date-debut').style.borderColor = needsDate ? '#f59e0b' : '';
            });
            </script>
        </div>

        <!-- APERÇU PLANNING -->
        <div class="sp-box">
            <h2>📅 Aperçu planning hebdo (<?php echo count($slots); ?> créneaux)</h2>
            <?php if ( empty($slots) ) : ?>
                <p class="sp-muted">Aucun créneau configuré.</p>
            <?php else : ?>
            <div style="overflow-x:auto;">
            <table class="sp-planning-preview">
                <thead><tr>
                    <?php foreach($jours_court as $n=>$l) echo '<th>'. $l .'</th>'; ?>
                </tr></thead>
                <tbody><tr>
                <?php foreach($jours as $num=>$label) : ?>
                <td>
                    <?php if(isset($by_jour[$num])) foreach($by_jour[$num] as $s) :
                        $rec_label = $rec_options[$s->recurrence ?? 'weekly'] ?? 'weekly';
                        $is_rec    = ($s->recurrence ?? 'weekly') === 'weekly';
                        $icon      = strpos($s->recurrence ?? '','monthly') !== false ? '📅' : '🔄';
                    ?>
                    <div class="sp-slot-preview">
                        <div style="font-size:11px;margin-bottom:3px;"><?php echo $icon; ?> <strong><?php echo esc_html($s->label); ?></strong></div>
                        <span class="sp-muted"><?php echo esc_html($s->heure_debut.' – '.$s->heure_fin); ?></span><br>
                        <?php if($s->categorie) echo '<span class="sp-badge-blue">'. esc_html($s->categorie) .'</span>'; ?>
                        <div style="margin-top:4px;font-size:10px;color:#6b7280;"><?php echo esc_html($rec_label); ?></div>
                        <?php if($s->date_debut) echo '<div style="font-size:10px;color:#888;">Du '. esc_html($s->date_debut) . ($s->date_fin ? ' au '. esc_html($s->date_fin) : '') .'</div>'; ?>
                        <div style="margin-top:5px;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-slots&sp_edit_slot='.$s->id)); ?>" class="button button-small">✏️</a>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=sp-cal-slots&sp_delete_slot='.$s->id),'sp_delete_slot_'.$s->id)); ?>" class="button button-small sp-btn-del" onclick="return confirm('Supprimer ?')">🗑️</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </td>
                <?php endforeach; ?>
                </tr></tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        </div><!-- .sp-two-col -->
        </div>
        <?php
    }

    public function page_eleves() { $this->members->page_eleves(); }

    /* ══════════════════════════════════════════════════════════
       PAGE : Paramètres
    ══════════════════════════════════════════════════════════ */

    /* ══════════════════════════════════════════════════════════
       PAGE PALMARÈS
    ══════════════════════════════════════════════════════════ */

    public function page_palmares() { $this->members->page_palmares(); }

    public function page_examens() { $this->exam->page_examens(); }

    /* ══════════════════════════════════════════════════════════
       AJAX — Synchronisation vacances scolaires Zone C
    ══════════════════════════════════════════════════════════ */

    public function ajax_sync_vacances() { $this->exam->ajax_sync_vacances(); }

    /* ══════════════════════════════════════════════════════════
       PAGE PÉDAGOGIE — Contenu par grade
    ══════════════════════════════════════════════════════════ */

    public function page_pedagogie() { $this->exam->page_pedagogie(); }

    
    public function handle_dates_grades_actions() { $this->exam->handle_dates_grades_actions(); }

    public function page_dates_grades() { $this->exam->page_dates_grades(); }

    /* ══════════════════════════════════════════════════════════
       POINTAGE QR CODE
    ══════════════════════════════════════════════════════════ */

    // Code PIN pointage
    private function pointage_pin() {
        $pin = get_option('sp_cal_pointage_pin', '');
        if (!$pin) {
            $pin = substr(str_shuffle('0123456789'), 0, 4);
            update_option('sp_cal_pointage_pin', $pin);
        }
        return $pin;
    }

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
        if ( $pin !== $this->pointage_pin() ) {
            wp_send_json_error( 'PIN invalide', 403 ); return;
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
        if ( $pin !== $this->pointage_pin() ) {
            wp_send_json_error( 'PIN invalide', 403 ); return;
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
        if ( $pin !== $this->pointage_pin() ) {
            wp_send_json_error( 'PIN invalide', 403 ); return;
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
        if ( $pin !== $this->pointage_pin() ) {
            wp_send_json_error( 'PIN invalide', 403 ); return;
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
        $pin = $this->pointage_pin();
        $pointage_url = home_url('/pointage/?pin=' . $pin);

        // Régénérer le PIN si demandé
        if (isset($_POST['regenerer_pin']) && check_admin_referer('sp_pointage_regen')) {
            update_option('sp_cal_pointage_pin', substr(str_shuffle('0123456789'), 0, 4));
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

    public function page_settings() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        $pub             = get_option( 'sp_cal_public_link', '' );
        $fiche_membre_url= get_option( 'sp_cal_fiche_membre_url', '' );
        $api_key    = get_option( 'sp_cal_api_key', '' );
        $cat_colors = json_decode( get_option( 'sp_cal_cat_colors', '{}' ), true ) ?: array();

        global $wpdb;
        $cats = array_merge(
            $this->db->get_categories_eleves(),
            $wpdb->get_col( "SELECT DISTINCT categorie FROM {$this->db->table_events()} WHERE categorie != '' ORDER BY categorie" )
        );
        $cats = array_unique( $cats );
        sort( $cats );
        ?>
        <div class="wrap sp-cal-wrap">
        <h1>Paramètres SP Calendar PRO</h1>

        <?php $this->notice_flash( 'saved', 'Paramètres enregistrés.' ); ?>
        <?php if (isset($_GET['vac_saved'])) echo '<div class="notice notice-success is-dismissible"><p>✅ Couleurs calendrier enregistrées.</p></div>'; ?>
        <?php if (isset($_GET['pts_saved'])) echo '<div class="notice notice-success is-dismissible"><p>✅ Convention médaille → points enregistrée.</p></div>'; ?>
        <?php if (isset($_GET['fiche_created'])) echo '<div class="notice notice-success is-dismissible"><p>✅ Page "Ma fiche" créée et URL configurée automatiquement.</p></div>'; ?>
        <?php if (isset($_GET['reglement_saved'])) echo '<div class="notice notice-success is-dismissible"><p>✅ Règlement intérieur enregistré.</p></div>'; ?>
        <?php if (isset($_GET['events_reset'])) echo '<div class="notice notice-success is-dismissible"><p>✅ Cours et événements réinitialisés.</p></div>'; ?>
        <?php if (isset($_GET['eleves_reset'])) echo '<div class="notice notice-success is-dismissible"><p>✅ Élèves et présences réinitialisés.</p></div>'; ?>
        <?php $medal_pts = $this->db->get_medal_points(); ?>

        <!-- INFORMATIONS CLUB -->
        <div class="sp-box" style="margin-bottom:18px;">
            <h2>🏛️ Informations du club</h2>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_settings' ); ?>
                <input type="hidden" name="sp_cal_save_settings" value="1">
                <table class="form-table">
                    <tr>
                        <th>N° de club</th>
                        <td><input type="text" name="sp_cal_club_num" class="regular-text"
                                   value="<?php echo esc_attr(get_option('sp_cal_club_num','')); ?>"
                                   placeholder="Ex: 066001"></td>
                    </tr>
                    <tr>
                        <th>N° d'affiliation FFTDA</th>
                        <td><input type="text" name="sp_cal_club_affiliation" class="regular-text"
                                   value="<?php echo esc_attr(get_option('sp_cal_club_affiliation','')); ?>"
                                   placeholder="N° affiliation fédérale"></td>
                    </tr>
                    <tr>
                        <th>Ligue</th>
                        <td><input type="text" name="sp_cal_club_ligue" class="regular-text"
                                   value="<?php echo esc_attr(get_option('sp_cal_club_ligue','')); ?>"
                                   placeholder="Ex: Ligue Occitanie"></td>
                    </tr>
                    <tr>
                        <th>Club labellisé</th>
                        <td>
                            <select name="sp_cal_club_labelise" style="padding:5px 8px;border:1px solid #ddd;border-radius:4px;">
                                <?php
                                $labelise = intval(get_option('sp_cal_club_labelise', 0));
                                $options = array(0=>'Non labellisé', 1=>'⭐ 1 étoile', 2=>'⭐⭐ 2 étoiles', 3=>'⭐⭐⭐ 3 étoiles', 4=>'⭐⭐⭐⭐ 4 étoiles', 5=>'⭐⭐⭐⭐⭐ 5 étoiles');
                                foreach ($options as $v => $l) echo '<option value="'.$v.'"'.selected($labelise,$v,false).'>'.$l.'</option>';
                                ?>
                            </select>
                            <p class="description">Affiché discrètement sur la carte de membre.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" name="sp_cal_save_settings" class="button button-primary" value="Enregistrer les informations club"></p>
            </form>
        </div>

        <!-- GÉNÉRAL -->
        <div class="sp-box">
            <h2>⚙️ Général</h2>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th>URL planning public</th>
                        <td><input type="url" name="sp_cal_public_link" class="large-text" value="<?php echo esc_attr($pub); ?>"></td>
                    </tr>
                    <tr>
                        <th>Clé API</th>
                        <td><code><?php echo esc_html($api_key); ?></code></td>
                    </tr>
                    <tr>
                        <th>Shortcodes</th>
                        <td>
                            Calendrier : <code>[sp_cal_calendar]</code><br>
                            Planning hebdo : <code>[sp_cal_planning]</code><br>
                            Fiche membre : <code>[sp_cal_fiche_membre]</code><br>
                            Liste des élèves : <code>[sp_cal_eleves]</code><br>
                            Palmarès public : <code>[sp_cal_palmares]</code>
                        </td>
                    </tr>
                </table>

                <!-- Couleurs catégories -->
                <h3>🎨 Couleurs &amp; icônes des catégories</h3>
                <?php if ( empty($cats) ) : ?>
                    <p class="sp-muted">Aucune catégorie trouvée (importez d'abord des élèves ou créez des événements).</p>
                <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-bottom:16px;">
                    <thead><tr>
                        <th style="width:200px;">Catégorie</th>
                        <th style="width:120px;">Couleur</th>
                        <th style="width:180px;">Icône</th>
                        <th>Aperçu</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $cats as $cat ) :
                        $key   = md5($cat);  // md5 = [0-9a-f] uniquement, safe pour ID HTML et jQuery
                        $saved = $cat_colors[$cat] ?? array();
                        $color = $saved['color'] ?? '#2271b1';
                        $icon  = $saved['icon']  ?? '';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($cat); ?></strong></td>
                        <!-- Champ caché pour retrouver le vrai nom de catégorie côté PHP -->
                        <input type="hidden" name="cat_name[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($cat); ?>">
                        <td><input type="color" name="cat_color[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($color); ?>"
                            oninput="document.getElementById('prev_<?php echo esc_attr($key); ?>').style.background=this.value"></td>
                        <td>
                            <input type="hidden" name="cat_icon[<?php echo esc_attr($key); ?>]" id="icon_input_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($icon); ?>">
                            <button type="button" class="sp-emoji-btn button" data-key="<?php echo esc_attr($key); ?>" style="font-size:18px;min-width:44px;"><?php echo esc_html($icon ?: '＋'); ?></button>
                            <?php if ($icon) echo '<button type="button" class="sp-emoji-clear button" data-key="' . esc_attr($key) . '" style="margin-left:4px;color:#b91c1c;">✕</button>'; ?>
                        </td>
                        <td>
                            <span id="prev_<?php echo esc_attr($key); ?>" style="display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:20px;font-size:12px;color:#fff;background:<?php echo esc_attr($color); ?>;">
                                <span id="prev_icon_<?php echo esc_attr($key); ?>"><?php echo $icon ? esc_html($icon) . ' ' : ''; ?></span><?php echo esc_html($cat); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <input type="submit" name="sp_cal_save_settings" class="button button-primary" value="Enregistrer les paramètres">
            </form>
        </div>

        <!-- RÈGLEMENT INTÉRIEUR (popup formulaire d'adhésion public) -->
        <div class="sp-box" style="margin-top:18px;">
            <h2>📄 Règlement intérieur</h2>
            <p class="description" style="margin-bottom:12px;">
                Ce texte est affiché dans la fenêtre popup du formulaire d'adhésion public (<code>[sp_inscription_adhesion]</code>)
                lorsque l'adhérent clique sur « règlement intérieur ». Quelques balises HTML simples sont acceptées (paragraphes, gras, listes, liens).
            </p>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_reglement' ); ?>
                <input type="hidden" name="sp_cal_save_reglement" value="1">
                <textarea name="sp_cal_reglement_interieur" rows="14" style="width:100%;max-width:900px;font-family:monospace;font-size:13px;"><?php
                    echo esc_textarea( get_option( 'sp_cal_reglement_interieur', '' ) );
                ?></textarea>
                <p class="submit"><input type="submit" name="sp_cal_save_reglement" class="button button-primary" value="Enregistrer le règlement intérieur"></p>
            </form>
        </div>

        <!-- VEILLE RÉGLEMENTAIRE (pense-bête, pas d'appel automatique) -->
        <div class="sp-box" style="margin-top:18px;">
            <h2>📋 Veille réglementaire</h2>
            <?php if ( isset( $_GET['veille_demandee'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Demande de veille enregistrée. Pensez à ouvrir une conversation avec Claude Code et à lui demander de lancer la veille réglementaire pour ce plugin.</p></div>
            <?php endif; ?>
            <p class="description" style="margin-bottom:10px;">
                Le plugin s'appuie sur 3 hypothèses réglementaires codées en dur, à revérifier de temps en temps auprès des sources officielles :
            </p>
            <ul style="margin:0 0 12px 20px;list-style:disc;font-size:13px;color:#374151;">
                <li>Certificat médical Taekwondo (FFTDA) : renouvellement chaque année (12 mois par défaut, réglable ci-dessus dans 🪪 Adhésions).</li>
                <li>Renforcement musculaire : certificat médical seulement en 1ère inscription adulte, sinon questionnaire de santé QS-Sport.</li>
                <li>Pass'Sport : aide de 50€, code déclaratif, aucune API de vérification connue côté Compte Asso.</li>
            </ul>
            <p class="description" style="margin-bottom:10px;">
                Ce bouton n'interroge rien automatiquement — il enregistre simplement la date de votre demande, comme un pense-bête. La vérification elle-même se fait en demandant à Claude Code de « lancer la veille réglementaire » dans une conversation : il consultera les sources officielles et vous fera un rapport.
            </p>
            <?php $veille_demandee_le = get_option( 'sp_cal_veille_reglementaire_demandee_le', '' ); ?>
            <p style="margin-bottom:10px;font-size:13px;">
                Dernière demande de veille :
                <strong><?php echo $veille_demandee_le ? esc_html( date( 'd/m/Y', strtotime( $veille_demandee_le ) ) ) : 'jamais'; ?></strong>
            </p>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_veille_reglementaire' ); ?>
                <input type="hidden" name="sp_veille_reglementaire_demandee" value="1">
                <input type="submit" class="button button-primary" value="🔍 Demander une veille réglementaire">
            </form>
        </div>

        <!-- COULEURS CALENDRIER — WEEKENDS & VACANCES ZONE C -->
        <div class="sp-box">
            <h2>📅 Fonds de couleur — Calendrier &amp; Planning</h2>
            <p class="description" style="margin-bottom:16px;">
                Coloration des cellules week-end et des périodes de vacances scolaires Zone C sur le calendrier admin et le planning public.
            </p>

            <!-- Couleurs -->
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_vacances' ); ?>
                <table class="form-table" style="max-width:480px;">
                    <tr>
                        <th style="width:200px;">🎨 Fond week-end</th>
                        <td>
                            <input type="color" name="sp_cal_we_color" value="<?php echo esc_attr( get_option('sp_cal_we_color','#f3f4f6') ); ?>" style="width:60px;height:32px;cursor:pointer;">
                            <span class="description" style="margin-left:8px;">Par défaut : gris clair</span>
                        </td>
                    </tr>
                    <tr>
                        <th>🌻 Fond vacances scolaires</th>
                        <td>
                            <input type="color" name="sp_cal_vac_color" value="<?php echo esc_attr( get_option('sp_cal_vac_color','#fef9c3') ); ?>" style="width:60px;height:32px;cursor:pointer;">
                            <span class="description" style="margin-left:8px;">Par défaut : jaune pâle</span>
                        </td>
                    </tr>
                </table>

                <!-- Saisie manuelle des vacances Zone C -->
                <h3>🗓️ Périodes de vacances scolaires Zone C</h3>
                <p class="description" style="margin-bottom:12px;">
                    Saisissez les périodes de vacances. Les dates de début et de fin sont <strong>incluses</strong>.<br>
                    Source officielle : <a href="https://www.education.gouv.fr/calendrier-scolaire-100148" target="_blank">education.gouv.fr/calendrier-scolaire</a>
                </p>

                <?php $vac_stored = json_decode( get_option('sp_cal_vacances_zoneC','[]'), true ) ?: array(); ?>

                <table class="wp-list-table widefat fixed" id="vac-table" style="max-width:720px;margin-bottom:10px;">
                    <thead><tr>
                        <th style="width:220px;">Libellé (ex: Toussaint 2025)</th>
                        <th style="width:160px;">Début</th>
                        <th style="width:160px;">Fin</th>
                        <th style="width:40px;"></th>
                    </tr></thead>
                    <tbody id="vac-rows">
                    <?php
                    $rows_to_show = ! empty($vac_stored) ? $vac_stored : array( array('label'=>'','start'=>'','end'=>'') );
                    foreach ( $rows_to_show as $v ) : ?>
                    <tr class="vac-row">
                        <td><input type="text"  name="vac_label[]" value="<?php echo esc_attr($v['label']); ?>" placeholder="Toussaint 2025" class="regular-text" style="width:100%;"></td>
                        <td><input type="date"  name="vac_start[]" value="<?php echo esc_attr($v['start']); ?>" style="width:100%;"></td>
                        <td><input type="date"  name="vac_end[]"   value="<?php echo esc_attr($v['end']);   ?>" style="width:100%;"></td>
                        <td><button type="button" class="button vac-remove" title="Supprimer" style="color:#dc2626;padding:2px 6px;">✕</button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <button type="button" id="vac-add" class="button" style="margin-bottom:16px;">+ Ajouter une période</button><br>

                <input type="submit" name="sp_cal_save_vacances" class="button button-primary" value="💾 Enregistrer couleurs &amp; périodes">
            </form>

            <script>
            (function($){
                $('#vac-add').on('click', function(){
                    $('#vac-rows').append(
                        '<tr class="vac-row">'
                        + '<td><input type="text" name="vac_label[]" placeholder="Ex: Printemps 2026" class="regular-text" style="width:100%;"></td>'
                        + '<td><input type="date" name="vac_start[]" style="width:100%;"></td>'
                        + '<td><input type="date" name="vac_end[]"   style="width:100%;"></td>'
                        + '<td><button type="button" class="button vac-remove" title="Supprimer" style="color:#dc2626;padding:2px 6px;">✕</button></td>'
                        + '</tr>'
                    );
                });
                $(document).on('click', '.vac-remove', function(){
                    $(this).closest('tr').remove();
                });
            })(jQuery);
            </script>
        </div>

        <!-- CONVENTION MÉDAILLE → POINTS -->
        <div class="sp-box">
            <h2>🏅 Convention médaille → points</h2>
            <p class="description" style="margin-bottom:14px;">
                Ces valeurs définissent combien de <strong>points</strong> rapporte chaque type de médaille.
                Le total de points est affiché sur la fiche élève et peut être utilisé comme critère pour les passages de grade.
            </p>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_medal_pts' ); ?>
                <table class="form-table" style="max-width:480px;">
                    <tr>
                        <th style="width:180px;">
                            <span style="font-size:22px;vertical-align:middle;">🥇</span>
                            Médaille d'Or
                        </th>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input type="number" name="sp_pts_or" min="0" max="99" value="<?php echo esc_attr($medal_pts['or']); ?>"
                                       style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                                <span style="color:#6b7280;">points</span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <span style="font-size:22px;vertical-align:middle;">🥈</span>
                            Médaille d'Argent
                        </th>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input type="number" name="sp_pts_argent" min="0" max="99" value="<?php echo esc_attr($medal_pts['argent']); ?>"
                                       style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                                <span style="color:#6b7280;">points</span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <span style="font-size:22px;vertical-align:middle;">🥉</span>
                            Médaille de Bronze
                        </th>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input type="number" name="sp_pts_bronze" min="0" max="99" value="<?php echo esc_attr($medal_pts['bronze']); ?>"
                                       style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                                <span style="color:#6b7280;">points</span>
                            </div>
                        </td>
                    </tr>
                </table>

                <!-- Aperçu de la convention actuelle -->
                <div id="sp-medal-preview" style="margin:14px 0 18px;padding:12px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;display:inline-flex;gap:20px;flex-wrap:wrap;align-items:center;">
                    <span style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Aperçu actuel :</span>
                    <span>🥇 = <strong id="prev_or"><?php echo $medal_pts['or']; ?></strong> pt</span>
                    <span>🥈 = <strong id="prev_argent"><?php echo $medal_pts['argent']; ?></strong> pt</span>
                    <span>🥉 = <strong id="prev_bronze"><?php echo $medal_pts['bronze']; ?></strong> pt</span>
                    <span style="color:#6b7280;">→ 🥇+🥈+🥉 = <strong id="prev_total"><?php echo $medal_pts['or'] + $medal_pts['argent'] + $medal_pts['bronze']; ?></strong> pts max</span>
                </div>
                <script>
                (function(){
                    var inputs = { or: document.querySelector('[name=sp_pts_or]'), argent: document.querySelector('[name=sp_pts_argent]'), bronze: document.querySelector('[name=sp_pts_bronze]') };
                    function update() {
                        var o=parseInt(inputs.or.value)||0, a=parseInt(inputs.argent.value)||0, b=parseInt(inputs.bronze.value)||0;
                        document.getElementById('prev_or').textContent     = o;
                        document.getElementById('prev_argent').textContent = a;
                        document.getElementById('prev_bronze').textContent = b;
                        document.getElementById('prev_total').textContent  = o+a+b;
                    }
                    inputs.or.addEventListener('input', update);
                    inputs.argent.addEventListener('input', update);
                    inputs.bronze.addEventListener('input', update);
                })();
                </script>

                <input type="submit" name="sp_save_medal_pts" class="button button-primary" value="Enregistrer la convention">
            </form>
        </div>
		<?php
        $coef_dep = floatval( get_option( 'sp_cal_coef_departemental', 1.0 ) );
        $coef_reg = floatval( get_option( 'sp_cal_coef_regional',      1.5 ) );
        $coef_nat = floatval( get_option( 'sp_cal_coef_national',      2.0 ) );
        $coef_int = floatval( get_option( 'sp_cal_coef_international', 3.0 ) );
        ?>
        <!-- COEFFICIENTS NIVEAUX COMPÉTITION -->
        <div class="sp-box">
            <h2>🏆 Coefficients niveaux de compétition</h2>
            <p class="description" style="margin-bottom:14px;">
                Ces coefficients multiplient les points de médaille selon le niveau de la compétition.
            </p>
            <?php if ( isset($_GET['coef_saved']) ) : ?>
            <div class="notice notice-success inline"><p>✅ Coefficients enregistrés.</p></div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_coef_niveaux' ); ?>
                <table class="form-table" style="max-width:480px;">
                    <tr>
                        <th style="width:180px;">🏘️ Départemental</th>
                        <td><div style="display:flex;align-items:center;gap:10px;">
                            <input type="number" name="sp_coef_dep" min="0.1" max="10" step="0.1"
                                   value="<?php echo esc_attr($coef_dep); ?>"
                                   style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                            <span style="color:#6b7280;">× points médaille</span>
                        </div></td>
                    </tr>
                    <tr>
                        <th>🌍 Régional</th>
                        <td><div style="display:flex;align-items:center;gap:10px;">
                            <input type="number" name="sp_coef_reg" min="0.1" max="10" step="0.1"
                                   value="<?php echo esc_attr($coef_reg); ?>"
                                   style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                            <span style="color:#6b7280;">× points médaille</span>
                        </div></td>
                    </tr>
                    <tr>
                        <th>🇫🇷 National</th>
                        <td><div style="display:flex;align-items:center;gap:10px;">
                            <input type="number" name="sp_coef_nat" min="0.1" max="10" step="0.1"
                                   value="<?php echo esc_attr($coef_nat); ?>"
                                   style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                            <span style="color:#6b7280;">× points médaille</span>
                        </div></td>
                    </tr>
                    <tr>
                        <th>🌐 International</th>
                        <td><div style="display:flex;align-items:center;gap:10px;">
                            <input type="number" name="sp_coef_int" min="0.1" max="10" step="0.1"
                                   value="<?php echo esc_attr($coef_int); ?>"
                                   style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                            <span style="color:#6b7280;">× points médaille</span>
                        </div></td>
                    </tr>
                </table>
                <input type="submit" name="sp_save_coef_niveaux" class="button button-primary" value="Enregistrer les coefficients">
            </form>
        </div>

        <!-- NOTIFICATIONS EMAIL -->
        <?php
        $notif_email          = get_option('sp_cal_notif_email',          get_option('admin_email'));
        $notif_anniv          = get_option('sp_cal_notif_anniv',          '0') === '1';
        $notif_anniv_jours    = intval(get_option('sp_cal_notif_anniv_jours',    7));
        $notif_absences       = get_option('sp_cal_notif_absences',       '0') === '1';
        $notif_absences_seuil = intval(get_option('sp_cal_notif_absences_seuil', 3));
        $last_run             = get_option('sp_cal_notif_last_run', null);
        ?>
        <div class="sp-box">
            <h2>📧 Notifications email</h2>
            <p class="description">Les notifications sont envoyées automatiquement chaque matin à 8h via le cron WordPress.</p>

            <form method="post">
                <?php wp_nonce_field('sp_cal_settings'); ?>
                <table class="form-table" style="max-width:640px;">
                    <tr>
                        <th>Email destinataire</th>
                        <td>
                            <input type="email" name="sp_cal_notif_email" class="regular-text"
                                   value="<?php echo esc_attr($notif_email); ?>">
                            <p class="description">Reçoit toutes les notifications du club.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>🎂 Anniversaires</th>
                        <td>
                            <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                <input type="checkbox" name="sp_cal_notif_anniv" value="1" <?php checked($notif_anniv); ?>>
                                Activer les rappels d'anniversaires
                            </label>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span>Prévenir</span>
                                <input type="number" name="sp_cal_notif_anniv_jours" min="1" max="30"
                                       value="<?php echo esc_attr($notif_anniv_jours); ?>"
                                       style="width:60px;height:28px;border:1px solid #8c8f94;border-radius:4px;padding:0 6px;">
                                <span>jours à l'avance</span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>⚠️ Absences répétées</th>
                        <td>
                            <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                <input type="checkbox" name="sp_cal_notif_absences" value="1" <?php checked($notif_absences); ?>>
                                Activer les alertes d'absences consécutives
                            </label>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span>Alerter après</span>
                                <input type="number" name="sp_cal_notif_absences_seuil" min="2" max="20"
                                       value="<?php echo esc_attr($notif_absences_seuil); ?>"
                                       style="width:60px;height:28px;border:1px solid #8c8f94;border-radius:4px;padding:0 6px;">
                                <span>absences consécutives</span>
                            </div>
                        </td>
                    </tr>
                </table>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:12px;">
                    <input type="submit" name="sp_cal_save_settings" class="button button-primary" value="Enregistrer">
                    <button type="button" id="sp-notif-test-anniv" class="button"
                            data-type="anniv" <?php echo !$notif_anniv ? 'disabled title="Activez d\'abord les anniversaires"' : ''; ?>>
                        🎂 Tester anniversaires
                    </button>
                    <button type="button" id="sp-notif-test-absences" class="button"
                            data-type="absences" <?php echo !$notif_absences ? 'disabled title="Activez d\'abord les absences"' : ''; ?>>
                        ⚠️ Tester absences
                    </button>
                    <span id="sp-notif-result" style="font-size:13px;color:#15803d;display:none;"></span>
                </div>
            </form>

            <?php if ($last_run): ?>
            <p class="sp-muted" style="margin-top:12px;font-size:12px;">
                Dernier envoi : <?php echo esc_html($last_run['date']); ?>
                <?php
                $s = $last_run['sent'] ?? array();
                $parts = array();
                if (isset($s['anniv']))    $parts[] = $s['anniv'] . ' anniv.';
                if (isset($s['absences'])) $parts[] = $s['absences'] . ' absences';
                if ($parts) echo '— ' . implode(', ', $parts) . ' email(s) envoyé(s)';
                ?>
            </p>
            <?php endif; ?>
        </div>

        <!-- RÉCAPITULATIF MENSUEL ENTRAÎNEURS -->
        <?php
        $recap_actif = get_option('sp_cal_recap_actif', '0') === '1';
        $recap_jour  = intval( get_option('sp_cal_recap_jour', 1) );
        $tarif_km    = floatval( get_option('sp_cal_tarif_km', 0) );
        $recap_last  = get_option('sp_cal_recap_last_run', null);
        ?>
        <div class="sp-box">
            <h2>📊 Récapitulatif mensuel entraîneurs</h2>
            <p class="description">
                Le <strong><?php echo esc_html(ordinal_fr($recap_jour)); ?> de chaque mois</strong>,
                un email est envoyé aux membres du bureau avec le nombre d'interventions
                et le montant calculé pour chaque entraîneur.<br>
                <strong>Formule :</strong> tarif €/km &times; km aller-retour &times; nombre d'interventions.
                Les km sont configurés sur la fiche de chaque entraîneur.
            </p>
            <?php $this->notice_flash('recap_saved', 'Paramètres du récapitulatif enregistrés.'); ?>
            <form method="post">
                <?php wp_nonce_field('sp_cal_recap_settings'); ?>
                <table class="form-table" style="max-width:640px;">
                    <tr>
                        <th>Activer</th>
                        <td>
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" name="sp_cal_recap_actif" value="1" <?php checked($recap_actif); ?>>
                                Envoyer automatiquement le récapitulatif mensuel
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Jour d'envoi</th>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span>Le</span>
                                <input type="number" name="sp_cal_recap_jour" min="1" max="28"
                                       value="<?php echo esc_attr($recap_jour); ?>"
                                       style="width:60px;height:28px;border:1px solid #8c8f94;border-radius:4px;padding:0 6px;">
                                <span>de chaque mois <em style="color:#888;">(max. 28 pour éviter les mois courts)</em></span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>💶 Tarif kilométrique</th>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <input type="number" name="sp_cal_tarif_km" min="0" max="5" step="0.01"
                                       value="<?php echo esc_attr(number_format($tarif_km, 2, '.', '')); ?>"
                                       style="width:80px;height:28px;border:1px solid #8c8f94;border-radius:4px;padding:0 6px;">
                                <span>€/km</span>
                            </div>
                            <p class="description" style="margin-top:4px;">
                                Taux légal de remboursement kilométrique. Exemple : 0.42 €/km.
                            </p>
                        </td>
                    </tr>
                </table>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:12px;">
                    <input type="submit" name="sp_cal_save_recap" class="button button-primary" value="Enregistrer">
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <?php
                        $mois_labels_fr = array(
                            1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
                            7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre',
                        );
                        $cur_year  = intval(date('Y'));
                        $cur_month = intval(date('n'));
                        // Mois proposés : les 12 derniers
                        $prev_month = $cur_month - 1 ?: 12;
                        $prev_year  = $cur_month - 1 ? $cur_year : $cur_year - 1;
                        ?>
                        <select id="sp-recap-month" style="height:30px;border:1px solid #8c8f94;border-radius:4px;padding:0 4px;">
                            <?php foreach ( $mois_labels_fr as $num => $label ) :
                                $y = ( $num <= $cur_month && $num >= 1 ) ? $cur_year : $cur_year - 1;
                                // Ajuster : si num > cur_month c'est l'année précédente
                                if ( $num > $cur_month ) $y = $cur_year - 1;
                                else $y = $cur_year;
                                $selected = ( $num === $prev_month ) ? ' selected' : '';
                            ?>
                            <option value="<?php echo $num; ?>" data-year="<?php echo ($num > $cur_month ? $cur_year - 1 : $cur_year); ?>"<?php echo $selected; ?>>
                                <?php echo $label . ' ' . ($num > $cur_month ? $cur_year - 1 : $cur_year); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="sp-recap-test-now" class="button"
                                title="Envoie le récapitulatif du mois sélectionné aux membres bureau">
                            📊 Envoyer maintenant
                        </button>
                    </div>
                    <span id="sp-recap-result" style="font-size:13px;color:#15803d;display:none;"></span>
                </div>
            </form>
            <?php if ($recap_last): ?>
            <p class="sp-muted" style="margin-top:12px;font-size:12px;">
                Dernier envoi : <?php echo esc_html($recap_last['date']); ?>
                — mois concerné : <?php echo esc_html($recap_last['mois'] ?? '—'); ?>
                — <?php echo intval($recap_last['sent'] ?? 0); ?> email(s) envoyé(s)
            </p>
            <?php endif; ?>
            <script>
            (function($){
                $('#sp-recap-test-now').on('click', function(){
                    var btn   = $(this).prop('disabled', true).text('Envoi…');
                    var sel   = $('#sp-recap-month option:selected');
                    var month = parseInt(sel.val(), 10);
                    var year  = parseInt(sel.data('year'), 10);
                    $.post(ajaxurl, {
                        action:       'sp_cal_send_recap_now',
                        nonce:        '<?php echo wp_create_nonce("sp_cal_admin_nonce"); ?>',
                        recap_month:  month,
                        recap_year:   year
                    }, function(res){
                        btn.prop('disabled', false).text('📊 Envoyer maintenant');
                        var $r = $('#sp-recap-result');
                        $r.css('color', res.success ? '#15803d' : '#b91c1c').text(res.success ? res.data : '❌ ' + res.data).show();
                        setTimeout(function(){ $r.fadeOut(); }, 8000);
                    });
                });
            })(jQuery);
            </script>
        </div>

        <!-- TOKENS FICHE MEMBRE -->
        <?php
        global $wpdb;
        $tel           = $this->db->table_eleves();
        $nb_with_token = intval($wpdb->get_var("SELECT COUNT(*) FROM $tel WHERE actif=1 AND token IS NOT NULL AND token!=''"));
        $nb_without    = intval($wpdb->get_var("SELECT COUNT(*) FROM $tel WHERE actif=1 AND (token IS NULL OR token='')"));
        $fiche_url_ex    = '';
        if ($this->token) $fiche_url_ex = $this->token->get_fiche_url('EXEMPLE');
        $palmares_url    = get_option( 'sp_cal_palmares_url', '' );
        ?>
        <div class="sp-box">
            <h2>🔗 Fiche de suivi membre (accès par token)</h2>
            <p class="description">
                Chaque adhérent actif reçoit automatiquement un lien personnel par email lors de son activation.
                Ce lien donne accès à sa fiche de suivi : grades, présences, statut adhésion.
            </p>

            <!-- URL dédiée — formulaire séparé pour éviter les conflits -->
            <form method="post" style="margin:12px 0 16px;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
                <?php wp_nonce_field('sp_cal_fiche_url'); ?>
                <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">
                    URL de la page "Fiche membre" <span style="color:#c00;">*</span>
                </label>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="url" name="sp_cal_fiche_membre_url" class="large-text"
                           value="<?php echo esc_attr($fiche_membre_url); ?>"
                           placeholder="https://votresite.fr/ma-fiche/"
                           style="max-width:400px;">
                    <input type="submit" name="sp_save_fiche_url" class="button button-primary" value="Enregistrer l'URL">
                    <?php if ($fiche_membre_url): ?>
                    <a href="<?php echo esc_url(add_query_arg('token','TEST',$fiche_membre_url)); ?>"
                       target="_blank" class="button" title="Tester le lien">🔗 Tester</a>
                    <?php endif; ?>
                </div>
                <p class="description" style="margin-top:6px;">
                    Page WordPress contenant le shortcode <code>[sp_cal_fiche_membre]</code> — <strong>pas</strong> <code>[sp_fiche_eleve]</code>.
                    <?php
                    global $wpdb;
                    $real_url = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='sp_cal_fiche_membre_url'");
                    if ( ! $real_url ): ?>
                    <br><strong style="color:#b45309;">⚠️ Non configurée — les tokens envoient vers la page d'accueil !</strong>
                    <?php else: ?>
                    <br>✅ URL en base : <code><?php echo esc_html($real_url); ?></code>
                    <?php endif; ?>
                </p>
            </form>
            <!-- Créer la page automatiquement -->
            <?php if ( ! $fiche_membre_url ) : ?>
            <form method="post" style="margin:10px 0 0;padding:10px 14px;background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;display:inline-flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <?php wp_nonce_field('sp_cal_create_fiche_page'); ?>
                <span style="font-size:13px;color:#92400e;">💡 Aucune page configurée — créez-la automatiquement :</span>
                <input type="submit" name="sp_create_fiche_page" class="button" value="✨ Créer la page « Ma fiche »">
            </form>
            <?php endif; ?>
            <table class="form-table" style="max-width:640px;">
                <tr>
                    <th>Shortcode</th>
                    <td>
                        Créez une page WordPress et insérez-y : <code>[sp_cal_fiche_membre]</code><br>
                        <span class="description">Les tokens dans les emails renvoient vers cette page.</span>
                    </td>
                </tr>
                <?php if($fiche_url_ex): ?>
                <tr>
                    <th>Exemple de lien</th>
                    <td><code style="font-size:11px;"><?php echo esc_html(str_replace('EXEMPLE','xxxxxxxx',$fiche_url_ex)); ?></code></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Tokens générés</th>
                    <td>
                        <strong><?php echo $nb_with_token; ?></strong> élèves actifs ont un token
                        <?php if($nb_without > 0): ?>
                        — <strong style="color:#b45309;"><?php echo $nb_without; ?></strong> élèves actifs sans token
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php if($nb_without > 0): ?>
            <button type="button" id="sp-btn-generate-all-tokens" class="button button-primary" style="margin-top:8px;">
                📧 Générer et envoyer les tokens manquants (<?php echo $nb_without; ?> élèves)
            </button>
            <span id="sp-all-tokens-result" style="margin-left:12px;font-size:13px;color:#15803d;display:none;"></span>
            <?php endif; ?>
        </div>

        <!-- URL PAGE PALMARÈS PUBLIC -->
        <div class="sp-box">
            <h2>🏆 Page palmarès public</h2>
            <p class="description">
                Indiquez l'URL de la page WordPress contenant le shortcode <code>[sp_cal_palmares]</code>.<br>
                Cette URL est utilisée par le planning public pour rediriger vers les résultats d'une compétition passée.
            </p>
            <form method="post" style="margin:12px 0 0;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
                <?php wp_nonce_field('sp_cal_palmares_url'); ?>
                <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">
                    URL de la page «&nbsp;Palmarès&nbsp;» <span style="color:#c00;">*</span>
                </label>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="url" name="sp_cal_palmares_url" class="large-text"
                           value="<?php echo esc_attr($palmares_url); ?>"
                           placeholder="https://votresite.fr/palmares/"
                           style="max-width:400px;">
                    <input type="submit" name="sp_save_palmares_url" class="button button-primary" value="Enregistrer l'URL">
                    <?php if ($palmares_url): ?>
                    <a href="<?php echo esc_url($palmares_url); ?>" target="_blank" class="button">🔗 Voir la page</a>
                    <?php endif; ?>
                </div>
                <p class="description" style="margin-top:6px;">
                    <?php if ( ! $palmares_url ): ?>
                    <strong style="color:#b45309;">⚠️ Non configurée — le lien 🏆 sur le planning ne sera pas actif.</strong>
                    <?php else: ?>
                    ✅ URL enregistrée : <code><?php echo esc_html($palmares_url); ?></code>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <!-- MIGRATION DISCIPLINE / POUR QUI DES ÉVÉNEMENTS -->
        <?php
        if ( isset($_GET['migration_cours_done']) ) {
            $nb_mig = intval($_GET['nb']);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Migration effectuée — <strong>' . $nb_mig . '</strong> événement(s) mis à jour.</p></div>';
        }
        if ( isset($_GET['migration_cours_preview']) ) {
            $preview_mig = get_transient( 'sp_migrer_cours_cat_preview_' . get_current_user_id() );
            if ( $preview_mig !== false ) :
        ?>
        <div class="notice notice-warning" style="padding:16px;">
            <?php if ( empty($preview_mig) ) : ?>
            <p><strong>👁️ Rien à migrer</strong> — tous les événements ont déjà une Discipline renseignée.</p>
            <?php else : ?>
            <p><strong>👁️ Aperçu de la migration — <?php echo count($preview_mig); ?> événement(s) concerné(s)</strong></p>
            <table class="wp-list-table widefat fixed striped" style="max-width:800px;margin:10px 0;">
                <thead><tr><th>Événement</th><th>Ancienne catégorie</th><th>Discipline</th><th>Pour qui</th></tr></thead>
                <tbody>
                <?php foreach ( array_slice($preview_mig, 0, 50) as $m ): ?>
                <tr>
                    <td><?php echo esc_html($m['titre']); ?></td>
                    <td><span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html($m['ancienne']); ?></span></td>
                    <td><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html($m['discipline'] !== '' ? str_replace(',', ', ', $m['discipline']) : '—'); ?></span></td>
                    <td><span style="background:#dbeafe;color:#1e3a8a;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html($m['age'] !== '' ? str_replace(',', ', ', $m['age']) : '—'); ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ( count($preview_mig) > 50 ): ?>
            <p style="color:#64748b;font-size:12px;">… et <?php echo count($preview_mig) - 50; ?> de plus (non affichés, mais bien pris en compte à la confirmation).</p>
            <?php endif; ?>
            <form method="post" style="margin-top:8px;">
                <?php wp_nonce_field('sp_migrer_cours_categories'); ?>
                <input type="hidden" name="sp_migrer_cours_categories" value="1">
                <input type="submit" class="button button-primary" value="✅ Confirmer la migration">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-settings')); ?>" class="button" style="margin-left:8px;">Annuler</a>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; } ?>

        <div class="sp-box" style="border-left:4px solid #e5e7eb;margin-bottom:18px;">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div style="flex:1;">
                    <strong>🎯 Migrer les événements vers Discipline / Pour qui</strong>
                    <p style="margin:4px 0 0;color:#64748b;font-size:13px;">
                        Convertit l'ancien champ "Catégorie" en texte libre (TKD, RENFO, Général, Prépa CN, Sortie…)
                        des événements existants vers les deux nouveaux menus du calendrier — <strong>Discipline</strong>
                        (Taekwondo / Renforcement musculaire / Autre) et <strong>Pour qui</strong> (tranche d'âge).
                        Les valeurs non reconnues (Général, Prépa CN, Sortie…) basculent en Discipline "Autre".
                        Sans effet sur les événements déjà migrés.
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field('sp_migrer_cours_categories'); ?>
                        <input type="hidden" name="sp_migrer_cours_categories" value="1">
                        <input type="hidden" name="sp_migrer_preview" value="1">
                        <input type="submit" class="button" value="👁️ Prévisualiser">
                    </form>
                </div>
            </div>
        </div>

        <!-- NETTOYAGE DOUBLONS -->
        <div class="sp-box" style="border-left:4px solid #f59e0b;">
            <h2 style="color:#92400e;">🧹 Nettoyage des événements parasites</h2>
            <?php if (isset($_GET['cleaned'])): ?>
            <div class="notice notice-success is-dismissible" style="margin:0 0 12px;"><p>✅ Nettoyage effectué — <?php echo intval($_GET['cleaned']); ?> événement(s) parasite(s) supprimé(s).</p></div>
            <?php endif; ?>
            <p>Supprime les <strong>événements en double</strong> créés accidentellement lors des saisies de présences (cours récurrents matérialisés plusieurs fois pour le même créneau/date).<br>
            <strong style="color:#15803d;">Les présences déjà enregistrées sont conservées.</strong></p>
            <form method="post">
                <?php wp_nonce_field('sp_cleanup_mats'); ?>
                <input type="submit" name="sp_cleanup_mats" class="button" style="background:#f59e0b;color:#fff;border-color:#d97706;"
                    value="🧹 Nettoyer les événements parasites" onclick="return confirm('Supprimer les événements en double ? Les présences enregistrées sont conservées.')">
            </form>
        </div>

        <!-- ZONE DANGER -->
        <div class="sp-box" style="border-left:4px solid #dc2626;">
            <h2 style="color:#dc2626;">⚠️ Zone de réinitialisation</h2>
            <div style="display:flex;gap:20px;flex-wrap:wrap;">
                <div>
                    <p><strong>Cours &amp; événements</strong><br>Supprime tous les cours, événements et présences élèves.</p>
                    <form method="post">
                        <?php wp_nonce_field('sp_reset_events'); ?>
                        <input type="submit" name="sp_reset_events" class="button" style="background:#dc2626;color:#fff;border-color:#dc2626;"
                            value="🗑️ Vider cours &amp; événements" onclick="return confirm('Supprimer TOUS les cours, événements et présences ? Irréversible.')">
                    </form>
                </div>
                <div>
                    <p><strong>Élèves</strong><br>Supprime tous les élèves et leurs présences.</p>
                    <form method="post">
                        <?php wp_nonce_field('sp_reset_eleves'); ?>
                        <input type="submit" name="sp_reset_eleves" class="button" style="background:#dc2626;color:#fff;border-color:#dc2626;"
                            value="🗑️ Vider les élèves" onclick="return confirm('Supprimer TOUS les élèves et présences ? Irréversible.')">
                    </form>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════
             DIAGNOSTIC PLUGIN
        ════════════════════════════════════════════════════ -->
        <div class="sp-box" style="margin-top:24px;">
            <h2 style="margin-top:0;">🔍 Diagnostic plugin</h2>
            <p style="color:#6b7280;font-size:13px;margin-bottom:16px;">
                Vérifie que le plugin, PHP, WordPress et la base de données fonctionnent correctement.<br>
                À utiliser après chaque mise à jour WordPress ou en cas de comportement inattendu.
            </p>
            <button id="sp-ping-btn" class="button button-secondary" style="font-size:14px;padding:6px 18px;">
                🔍 Lancer le diagnostic
            </button>
            <div id="sp-ping-result" style="display:none;margin-top:16px;padding:16px 20px;border-radius:10px;font-size:13px;line-height:2;"></div>
        </div>
        <script>
        document.getElementById('sp-ping-btn').addEventListener('click', function() {
            var btn = this;
            var box = document.getElementById('sp-ping-result');
            btn.disabled = true;
            btn.textContent = '⏳ Vérification en cours…';
            box.style.display = 'none';

            jQuery.post(ajaxurl, {
                action: 'sp_cal_ping',
            }, function(res) {
                btn.disabled = false;
                btn.textContent = '🔍 Lancer le diagnostic';
                if (res && res.success) {
                    var d = res.data;
                    var dbOk  = d.db_status === 'ok';
                    var color = dbOk ? '#f0fdf4' : '#fef2f2';
                    var bord  = dbOk ? '#bbf7d0' : '#fecaca';
                    var icon  = dbOk ? '✅' : '❌';
                    box.style.background = color;
                    box.style.border     = '1px solid ' + bord;
                    box.innerHTML =
                        '<strong style="font-size:15px;">' + icon + ' ' + (dbOk ? 'Tout fonctionne correctement' : 'Problème détecté') + '</strong><br>'
                        + '📦 Plugin&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <strong>' + d.plugin_version + '</strong><br>'
                        + '🐘 PHP&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <strong>' + d.php_version + '</strong><br>'
                        + '🔵 WordPress&nbsp;: <strong>' + d.wp_version + '</strong><br>'
                        + '🗄️ Base de données&nbsp;: <strong style="color:' + (dbOk ? '#16a34a' : '#dc2626') + ';">' + d.db_status + '</strong><br>'
                        + '🕐 Vérifié le&nbsp;: <strong>' + d.timestamp + '</strong>';
                } else {
                    var msg = (res && res.data) ? res.data : 'Réponse inattendue du serveur.';
                    box.style.background = '#fef2f2';
                    box.style.border     = '1px solid #fecaca';
                    box.innerHTML = '❌ <strong>Erreur&nbsp;:</strong> ' + msg
                        + '<br><small style="color:#9ca3af;">Si le problème persiste, désactivez puis réactivez le plugin.</small>';
                }
                box.style.display = 'block';
            }, 'json').fail(function(xhr) {
                btn.disabled = false;
                btn.textContent = '🔍 Lancer le diagnostic';
                box.style.background = '#fef2f2';
                box.style.border     = '1px solid #fecaca';
                box.innerHTML = '❌ <strong>Impossible de contacter le serveur.</strong>'
                    + '<br>Code HTTP&nbsp;: <strong>' + xhr.status + '</strong>'
                    + '<br>Réponse&nbsp;: <code style="font-size:11px;">' + (xhr.responseText ? xhr.responseText.substring(0,200) : 'vide') + '</code>'
                    + '<br><small style="color:#9ca3af;">💡 Désactivez puis réactivez le plugin, puis réessayez.</small>';
                box.style.display = 'block';
            });
        });
        </script>

        </div>
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

    private function time_select( $name, $selected, $from = null, $to = null ) {
        if ( $from !== null ) {
            // Sélecteur heures
            $html = '<select name="' . esc_attr($name) . '" class="sp-time-sel">';
            for ( $h = $from; $h <= $to; $h++ ) {
                $hh = sprintf('%02d', $h);
                $html .= '<option value="' . $hh . '"' . selected($selected, $hh, false) . '>' . $hh . '</option>';
            }
            $html .= '</select>';
        } else {
            // Sélecteur minutes
            $html = '<select name="' . esc_attr($name) . '" class="sp-time-sel">';
            foreach ( array('00','15','30','45') as $mm ) {
                $html .= '<option value="' . $mm . '"' . selected($selected, $mm, false) . '>' . $mm . '</option>';
            }
            $html .= '</select>';
        }
        return $html;
    }

    /* ══════════════════════════════════════════════════════════
       PAGE JURY D'EXAMEN
    ══════════════════════════════════════════════════════════ */

    /* ══════════════════════════════════════════════════════════
       PAGE JURY D'EXAMEN — Passe 1.5 (stepper)
    ══════════════════════════════════════════════════════════ */

    public function page_jury() { $this->jury->page_jury(); }

    /* ══════════════════════════════════════════════════════════
       CHANTIER 5 — Formulaire mobile saisie jury (via QR code)
       Accès : admin.php?page=sp-cal-jury&jury_saisie=1&event_id=X&eleve_id=Y&nonce=Z
    ══════════════════════════════════════════════════════════ */



    public function ajax_jury_save_notes_mobile(): void { $this->jury->ajax_jury_save_notes_mobile(); }

    public function page_jury_guide() { $this->jury->page_jury_guide(); }

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



    /* ══════════════════════════════════════════════════════════
       PAGE SONDAGES — RÉSULTATS
    ══════════════════════════════════════════════════════════ */

    public function page_sondages(): void {
        global $wpdb;

        $event_id = intval( $_GET['event_id'] ?? 0 );
        $ts       = $wpdb->prefix . 'sp_cal_sondages';
        $te       = $this->db->table_events();

        // ── VUE LISTE ────────────────────────────────────────────────────────
        if ( ! $event_id ) {
            $sondages = $wpdb->get_results(
                "SELECT s.*, e.titre, e.date as event_date,
                        (SELECT COUNT(*) FROM {$wpdb->prefix}sp_cal_sondage_questions q WHERE q.sondage_id = s.id) as nb_questions,
                        (SELECT COUNT(DISTINCT r.eleve_id) FROM {$wpdb->prefix}sp_cal_sondage_reponses r WHERE r.sondage_id = s.id) as nb_repondants
                 FROM $ts s
                 INNER JOIN $te e ON e.id = s.event_id
                 ORDER BY e.date DESC
                 LIMIT 100"
            );

            echo '<div class="wrap"><h1>🗳️ Sondages post-événement</h1>';

            if ( empty( $sondages ) ) {
                echo '<p>Aucun sondage créé. Ouvrez un événement dans le calendrier et créez des questions dans l\'onglet Sondage.</p>';
                echo '</div>';
                return;
            }

            echo '<table class="widefat striped"><thead><tr>
                <th>Événement</th>
                <th>Date</th>
                <th>Questions</th>
                <th>Statut envoi</th>
                <th>Répondants</th>
                <th>Actions</th>
            </tr></thead><tbody>';

            foreach ( $sondages as $s ) {
                $url      = admin_url( 'admin.php?page=sp-cal-sondages&event_id=' . intval( $s->event_id ) );
                $date_fmt = $s->event_date ? date_create( $s->event_date )->format( 'd/m/Y' ) : '—';
                $envoye   = intval( $s->envoye );
                $statut   = $envoye
                    ? '<span style="color:#15803d;">✅ Envoyé le ' . esc_html( $s->date_envoi ? date_create($s->date_envoi)->format('d/m/Y') : '—' ) . '</span>'
                    : '<span style="color:#92400e;">⏳ Non envoyé</span>';

                echo '<tr>';
                echo '<td><a href="' . esc_url( $url ) . '"><strong>' . esc_html( $s->titre ) . '</strong></a></td>';
                echo '<td>' . esc_html( $date_fmt ) . '</td>';
                echo '<td style="text-align:center;">' . intval( $s->nb_questions ) . '</td>';
                echo '<td>' . $statut . '</td>';
                echo '<td style="text-align:center;">' . ( $envoye ? '<strong>' . intval( $s->nb_repondants ) . '</strong>' : '—' ) . '</td>';
                echo '<td>';
                if ( $envoye && intval( $s->nb_repondants ) > 0 ) {
                    echo '<a href="' . esc_url( $url ) . '" class="button button-small button-primary">📊 Voir résultats</a>';
                } elseif ( ! $envoye ) {
                    echo '<span style="color:#9ca3af;font-size:12px;">Sondage non encore envoyé</span>';
                } else {
                    echo '<span style="color:#9ca3af;font-size:12px;">Aucune réponse</span>';
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
            return;
        }

        // ── VUE DÉTAIL ───────────────────────────────────────────────────────
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $te WHERE id = %d", $event_id ) );
        if ( ! $event ) {
            echo '<div class="wrap"><p>Événement introuvable.</p></div>';
            return;
        }

        $sondage = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $ts WHERE event_id = %d", $event_id
        ), ARRAY_A );
        if ( ! $sondage ) {
            echo '<div class="wrap"><p>Sondage introuvable pour cet événement.</p></div>';
            return;
        }

        $sondage_id   = intval( $sondage['id'] );
        $resultats    = $this->db->get_sondage_resultats( $sondage_id );
        $date_fmt     = $event->date ? date_create( $event->date )->format( 'd/m/Y' ) : '—';
        $back_url     = admin_url( 'admin.php?page=sp-cal-sondages' );

        // Taux de participation
        $nb_invites   = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT eleve_id) FROM {$wpdb->prefix}sp_cal_sondage_reponses WHERE sondage_id = %d",
            $sondage_id
        ) );
        ?>
        <div class="wrap">
            <h1>🗳️ Sondage — <?php echo esc_html( $event->titre ); ?></h1>
            <p>
                <a href="<?php echo esc_url( $back_url ); ?>" class="button">← Retour</a>
                &nbsp;
                <strong>📅 <?php echo esc_html( $date_fmt ); ?></strong>
                &nbsp;&nbsp;
                <strong><?php echo intval( $nb_invites ); ?> répondant(s)</strong>
                <?php if ( $sondage['date_envoi'] ) : ?>
                &nbsp;&nbsp;
                <span style="color:#6b7280;">Envoyé le <?php echo esc_html( date_create($sondage['date_envoi'])->format('d/m/Y') ); ?></span>
                <?php endif; ?>
            </p>
            <hr>

            <?php if ( empty( $resultats ) ) : ?>
                <p style="color:#9ca3af;">Aucune réponse enregistrée.</p>
            <?php else : ?>
                <?php foreach ( $resultats as $res ) :
                    $q = $res['question'];
                    ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin-bottom:20px;">

                    <h3 style="margin:0 0 4px;font-size:15px;">
                        <?php echo esc_html( $q['question'] ); ?>
                        <span style="font-size:12px;color:#9ca3af;font-weight:normal;margin-left:8px;">
                            <?php echo $q['type'] === 'note' ? '⭐ Note 1-5' : '💬 Texte libre'; ?>
                        </span>
                    </h3>
                    <p style="margin:0 0 12px;color:#6b7280;font-size:13px;"><?php echo intval( $res['nb_reponses'] ); ?> réponse(s)</p>

                    <?php if ( $q['type'] === 'note' && $res['moyenne'] !== null ) :
                        $moy = (float) $res['moyenne'];
                        $pct = round( ( $moy / 5 ) * 100 );
                        // Distribution des notes
                        $distrib = $wpdb->get_results( $wpdb->prepare(
                            "SELECT reponse_note as note, COUNT(*) as nb
                             FROM {$wpdb->prefix}sp_cal_sondage_reponses
                             WHERE question_id = %d AND reponse_note IS NOT NULL
                             GROUP BY reponse_note ORDER BY reponse_note DESC",
                            intval( $q['id'] )
                        ) );
                        $total = array_sum( array_column( (array) $distrib, 'nb' ) );
                        ?>
                        <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
                            <div style="font-size:36px;font-weight:700;color:#1d4ed8;"><?php echo number_format( $moy, 1 ); ?></div>
                            <div>
                                <div style="font-size:20px;">
                                    <?php for ( $i = 1; $i <= 5; $i++ ) echo $i <= round($moy) ? '⭐' : '☆'; ?>
                                </div>
                                <div style="font-size:12px;color:#6b7280;">sur 5</div>
                            </div>
                        </div>
                        <?php foreach ( $distrib as $d ) :
                            $bar_pct = $total > 0 ? round( ( $d->nb / $total ) * 100 ) : 0;
                            ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;font-size:13px;">
                                <span style="width:20px;text-align:right;color:#6b7280;"><?php echo intval($d->note); ?>★</span>
                                <div style="flex:1;background:#f3f4f6;border-radius:4px;height:14px;overflow:hidden;">
                                    <div style="width:<?php echo $bar_pct; ?>%;background:#1d4ed8;height:100%;border-radius:4px;"></div>
                                </div>
                                <span style="width:40px;color:#6b7280;"><?php echo intval($d->nb); ?> (<?php echo $bar_pct; ?>%)</span>
                            </div>
                        <?php endforeach; ?>

                    <?php elseif ( $q['type'] === 'texte' ) : ?>
                        <?php if ( empty( $res['textes'] ) ) : ?>
                            <p style="color:#9ca3af;font-style:italic;">Aucun commentaire.</p>
                        <?php else : ?>
                            <div style="display:grid;gap:8px;">
                                <?php foreach ( $res['textes'] as $t ) : ?>
                                <div style="background:#f9fafb;border-left:3px solid #1d4ed8;padding:10px 14px;border-radius:0 6px 6px 0;font-size:13px;color:#374151;">
                                    <?php echo esc_html( $t ); ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
        <?php
    }

}

endif; // class_exists SpCalPro_Admin

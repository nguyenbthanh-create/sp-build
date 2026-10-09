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
require_once plugin_dir_path( __FILE__ ) . 'class-docs-adhesion.php';
require_once plugin_dir_path( __FILE__ ) . 'class-adherents-a-trier.php';


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
require_once plugin_dir_path( __FILE__ ) . 'trait-admin-pwa.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-admin-pointage.php';
if ( ! class_exists( 'SpCalPro_Admin' ) ) :

class SpCalPro_Admin {
    use SpCalPro_Admin_Pointage; // trait-admin-pointage.php

    use SpCalPro_Admin_Pwa; // trait-admin-pwa.php

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

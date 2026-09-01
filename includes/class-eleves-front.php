<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_ElevesFront' ) ) :

class SpCalPro_ElevesFront {

    private $db;
    private $token;

    public function __construct( SpCalPro_DB $db, SpCalPro_Token $token ) {
        $this->db    = $db;
        $this->token = $token;
        add_shortcode( 'sp_cal_eleves', array( $this, 'render' ) );
        add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_front' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
    }

    public function enqueue_front() {
        if ( ( defined('REST_REQUEST') && REST_REQUEST ) ||
             ( defined('DOING_AJAX')   && DOING_AJAX   ) ) {
            return;
        }
        $this->do_enqueue();
    }

    public function enqueue_admin( $hook ) {
        if ( strpos( $hook, 'sp-cal' ) === false ) return;
        $this->do_enqueue();
    }

    private function do_enqueue() {
        wp_enqueue_style(
            'sp-cal-front',
            SP_CAL_PRO_URL . 'assets/css/calendar.css',
            array(),
            SP_CAL_PRO_VERSION
        );
    }

    public function render( $atts ) {
        if ( ( defined('REST_REQUEST') && REST_REQUEST ) ||
             ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) ||
             ( defined('DOING_AJAX') && DOING_AJAX ) ) {
            return '';
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return '<p style="color:#c0392b;font-weight:bold;">⛔ Accès réservé aux administrateurs.</p>';
        }

        if ( ! wp_style_is( 'sp-cal-front', 'enqueued' ) ) {
            $this->do_enqueue();
        }

        global $wpdb;
        $tel    = $this->db->table_eleves();
        $eleves = $wpdb->get_results(
            "SELECT id, nom, prenom, annee_naissance, grade, categorie_age, token
             FROM {$tel}
             WHERE actif = 1
             ORDER BY nom ASC, prenom ASC"
        );

        ob_start();
        include SP_CAL_PRO_PATH . 'templates/eleves-front.php';
        return ob_get_clean();
    }

    /**
     * Calcule l'âge depuis l'année de naissance.
     * Retourne un entier ou null si annee_naissance est vide / invalide.
     */
    public static function calcul_age( $annee_naissance ) {
        $annee = intval( $annee_naissance );
        if ( $annee < 1900 || $annee > intval( date('Y') ) ) return null;
        return intval( date('Y') ) - $annee;
    }
}

endif; // class_exists SpCalPro_ElevesFront

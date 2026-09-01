<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_BureauFront' ) ) :

class SpCalPro_BureauFront {

    private $db;

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;
        add_shortcode( 'sp_cal_bureau', array( $this, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    public function enqueue() {
        if ( ( defined('REST_REQUEST') && REST_REQUEST ) ||
             ( defined('DOING_AJAX')   && DOING_AJAX   ) ) return;
        wp_enqueue_style( 'sp-cal-front', SP_CAL_PRO_URL . 'assets/css/calendar.css', array(), SP_CAL_PRO_VERSION );
    }

    public function render( $atts ) {
        if ( ( defined('REST_REQUEST') && REST_REQUEST ) ||
             ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) ||
             ( defined('DOING_AJAX') && DOING_AJAX ) ) return '';

        $atts = shortcode_atts( array(
            'roles'        => 'bureau',
            'titre'        => '',
            'colonnes'     => '3',
            'taille_photo' => '110',
            'couleur_nom'  => '#1a1a1a',
            'couleur_fn'   => '#666666',
        ), $atts, 'sp_cal_bureau' );

        $roles_filter = array_map( 'trim', explode( ',', $atts['roles'] ) );
        $membres = $this->db->get_membres_pour_front( $roles_filter );

        ob_start();
        include SP_CAL_PRO_PATH . 'templates/bureau-front.php';
        return ob_get_clean();
    }
}

endif;

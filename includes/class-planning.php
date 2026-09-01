<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_Planning' ) ) :

class SpCalPro_Planning {

    private $db;

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;
        add_shortcode( 'sp_cal_planning', array( $this, 'render' ) );
    }

    public function render( $atts ) {
        // Ne pas rendre pendant les requêtes REST (Gutenberg save/preview)
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return '<p class="sp-planning-placeholder">[Planning des cours]</p>';
        }

        // Passer $sp_cal_db comme variable locale pour le template
        $sp_cal_db = $this->db;

        ob_start();
        include SP_CAL_PRO_PATH . 'templates/planning.php';
        return ob_get_clean();
    }
}

endif; // class_exists SpCalPro_Planning

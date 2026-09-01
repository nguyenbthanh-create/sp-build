<?php
/**
 * Plugin Name: SportPress Calendar PRO
 * Description: Calendrier de gestion, planning hebdo, présences élèves et import CSV.
 * Version:     10.17c
 * Author:      Club
 * Text Domain: sp-cal-pro
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SP_CAL_PRO_VERSION', '10.17c' );
define( 'SP_CAL_PRO_PATH',    plugin_dir_path( __FILE__ ) );
define( 'SP_CAL_PRO_URL',     plugin_dir_url( __FILE__ ) );

require_once SP_CAL_PRO_PATH . 'includes/class-db.php';
require_once SP_CAL_PRO_PATH . 'includes/class-admin.php';
require_once SP_CAL_PRO_PATH . 'includes/class-ajax.php';
require_once SP_CAL_PRO_PATH . 'includes/class-calendar.php';
require_once SP_CAL_PRO_PATH . 'includes/class-planning.php';
require_once SP_CAL_PRO_PATH . 'includes/class-api.php';
require_once SP_CAL_PRO_PATH . 'includes/class-notifications.php';
require_once SP_CAL_PRO_PATH . 'includes/class-pdf.php';
require_once SP_CAL_PRO_PATH . 'includes/class-token.php';
require_once SP_CAL_PRO_PATH . 'includes/class-updater.php';
require_once SP_CAL_PRO_PATH . 'includes/class-eleves-front.php';
require_once SP_CAL_PRO_PATH . 'includes/class-palmares-front.php';
require_once SP_CAL_PRO_PATH . 'includes/class-bureau-front.php';
require_once SP_CAL_PRO_PATH . 'includes/class-jury.php';
require_once SP_CAL_PRO_PATH . 'includes/class-admin-jury.php';
require_once SP_CAL_PRO_PATH . 'includes/class-jury-mobile.php';
require_once SP_CAL_PRO_PATH . 'includes/class-top5-front.php';

if ( ! class_exists( 'SpCalPro' ) ) {
    class SpCalPro {
        private static $instance = null;

        public static function instance() {
            if ( null === self::$instance ) self::$instance = new self();
            return self::$instance;
        }

        private function __construct() {
            $db    = new SpCalPro_DB();
            $token = new SpCalPro_Token( $db );
            new SpCalPro_Admin( $db, $token );
            new SpCalPro_Ajax( $db );
            new SpCalPro_Calendar( $db );
            new SpCalPro_Planning( $db );
            new SpCalPro_API( $db );
            new SpCalPro_Notifications( $db );
            new SpCalPro_PDF( $db );
            new SpCalPro_ElevesFront( $db, $token );
            new SpCalPro_PalmaresFront( $db );
            new SpCalPro_BureauFront( $db );
            new SpCalPro_Jury( $db );
            new SpCalPro_Updater( SP_CAL_PRO_VERSION );
			new SpCalPro_Top5Front( $db );


            register_activation_hook( __FILE__, function() use ( $db ) {
                $db->activate();
                $db->create_jury_tables();
                if ( ! get_option( 'sp_cal_api_key' ) ) {
                    update_option( 'sp_cal_api_key', wp_generate_password( 32, false ) );
                }
            } );
        }
    }

    SpCalPro::instance();
}

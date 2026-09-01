<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_API' ) ) :

class SpCalPro_API {

    private $db;

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( 'sp-cal/v1', '/events', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_events' ),
            'permission_callback' => array( $this, 'check_api_key' ),
        ) );
        register_rest_route( 'sp-cal/v1', '/planning', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_planning' ),
            'permission_callback' => array( $this, 'check_api_key' ),
        ) );
    }

    public function check_api_key( $request ) {
        $key = $request->get_header('X-SP-Cal-Key') ?: $request->get_param('api_key');
        return $key && $key === get_option('sp_cal_api_key','');
    }

    public function get_events( $request ) {
        $year  = intval( $request->get_param('year')  ?: date('Y') );
        $month = intval( $request->get_param('month') ?: date('m') );
        return rest_ensure_response( $this->db->get_events_by_month( $year, $month ) );
    }

    public function get_planning( $request ) {
        return rest_ensure_response( $this->db->get_slots() );
    }
}

endif; // class_exists SpCalPro_API

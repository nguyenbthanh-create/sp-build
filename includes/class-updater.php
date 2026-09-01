<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Système de mise à jour autonome pour SP Calendar PRO
 * Hébergement sur votre propre serveur — aucune dépendance externe
 *
 * Sur votre serveur tkdclaira.fr, créez le dossier :
 *   /wp-content/sp-cal-updates/
 * Et déposez-y :
 *   - sportpress-calendar-pro.zip  (le plugin à jour)
 *   - update-info.json             (les métadonnées de version)
 *
 * Format de update-info.json :
 * {
 *   "version": "9.12.0",
 *   "download_url": "https://tkdclaira.fr/wp-content/sp-cal-updates/sportpress-calendar-pro.zip",
 *   "last_updated": "2026-03-12",
 *   "requires": "5.8",
 *   "tested": "6.5",
 *   "changelog": "<h4>9.12.0</h4><ul><li>Système de mise à jour automatique</li></ul>"
 * }
 */
if ( ! class_exists( 'SpCalPro_Updater' ) ) :

class SpCalPro_Updater {

    const PLUGIN_SLUG = 'sportpress-calendar-pro/sportpress-calendar-pro.php';
    const OPTION_KEY  = 'sp_cal_update_url';
    const CHECK_KEY   = 'sp_cal_update_cache';

    private $current_version;
    private $update_url;

    public function __construct( $current_version ) {
        $this->current_version = $current_version;
        $this->update_url      = get_option( self::OPTION_KEY, '' );

        if ( empty( $this->update_url ) ) return;

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api',                           array( $this, 'plugin_info' ), 20, 3 );
        add_action( 'upgrader_process_complete',             array( $this, 'clear_cache' ), 10, 2 );
        add_action( 'admin_notices',                         array( $this, 'admin_notice' ) );
    }

    /**
     * Récupère les infos de mise à jour depuis le JSON distant
     */
    private function get_remote_info() {
        $cached = get_transient( self::CHECK_KEY );
        if ( $cached !== false ) return $cached;

        $response = wp_remote_get( $this->update_url, array(
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; SP-Cal-Updater',
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            // En cas d'erreur, on recache 1h pour ne pas spammer
            set_transient( self::CHECK_KEY, null, HOUR_IN_SECONDS );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        $info = json_decode( $body );

        if ( empty( $info ) || empty( $info->version ) ) {
            set_transient( self::CHECK_KEY, null, HOUR_IN_SECONDS );
            return null;
        }

        // Cache 12h
        set_transient( self::CHECK_KEY, $info, 12 * HOUR_IN_SECONDS );
        return $info;
    }

    /**
     * Hook principal : injecte la mise à jour dans le système WordPress
     */
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $info = $this->get_remote_info();
        if ( ! $info ) return $transient;

        if ( version_compare( $this->current_version, $info->version, '<' ) ) {
            $transient->response[ self::PLUGIN_SLUG ] = (object) array(
                'slug'        => 'sportpress-calendar-pro',
                'plugin'      => self::PLUGIN_SLUG,
                'new_version' => $info->version,
                'url'         => $info->details_url ?? '',
                'package'     => $info->download_url ?? '',
                'tested'      => $info->tested       ?? '',
                'requires'    => $info->requires     ?? '',
            );
        }

        return $transient;
    }

    /**
     * Affiche les détails du plugin dans la popup "Voir les détails"
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ! isset( $args->slug ) || $args->slug !== 'sportpress-calendar-pro' ) return $result;

        $info = $this->get_remote_info();
        if ( ! $info ) return $result;

        return (object) array(
            'name'          => 'SportPress Calendar PRO',
            'slug'          => 'sportpress-calendar-pro',
            'version'       => $info->version,
            'author'        => 'Club',
            'requires'      => $info->requires     ?? '5.8',
            'tested'        => $info->tested       ?? '6.5',
            'last_updated'  => $info->last_updated ?? '',
            'download_link' => $info->download_url ?? '',
            'sections'      => array(
                'description' => 'Calendrier et planning hebdomadaire avec gestion des membres, grades et import CSV.',
                'changelog'   => $info->changelog  ?? 'Voir les notes de version.',
            ),
        );
    }

    /**
     * Vide le cache après une mise à jour
     */
    public function clear_cache( $upgrader, $options ) {
        if ( $options['action'] === 'update' && $options['type'] === 'plugin' ) {
            delete_transient( self::CHECK_KEY );
        }
    }

    /**
     * Notice admin si mise à jour disponible (en complément de la notice WordPress standard)
     */
    public function admin_notice() {
        $info = $this->get_remote_info();
        if ( ! $info ) return;
        if ( ! version_compare( $this->current_version, $info->version, '<' ) ) return;
        if ( ! current_user_can( 'update_plugins' ) ) return;

        $update_url = wp_nonce_url(
            admin_url( 'update.php?action=upgrade-plugin&plugin=' . urlencode( self::PLUGIN_SLUG ) ),
            'upgrade-plugin_' . self::PLUGIN_SLUG
        );
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>SP Calendar PRO</strong> — Une mise à jour est disponible : ';
        echo '<strong>v' . esc_html( $info->version ) . '</strong> ';
        echo '(version installée : v' . esc_html( $this->current_version ) . '). ';
        echo '<a href="' . esc_url( $update_url ) . '" class="button button-primary" style="margin-left:8px;">Mettre à jour maintenant</a></p>';
        echo '</div>';
    }

    /**
     * Méthode statique utilitaire : force la vérification immédiate
     */
    public static function force_check() {
        delete_transient( self::CHECK_KEY );
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();
    }
}

endif; // class_exists SpCalPro_Updater

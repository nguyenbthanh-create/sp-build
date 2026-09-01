<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_PalmaresFront' ) ) :

class SpCalPro_PalmaresFront {

    private $db;

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;
        add_shortcode( 'sp_cal_palmares', array( $this, 'render' ) );
        add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_front' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
    }

    public function enqueue_front() {
        if ( ( defined('REST_REQUEST') && REST_REQUEST ) ||
             ( defined('DOING_AJAX')   && DOING_AJAX   ) ) return;
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
        // Ne pas exécuter pendant les requêtes REST (sauvegarde Gutenberg) ou AJAX
        if ( ( defined('REST_REQUEST') && REST_REQUEST ) ||
             ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) ||
             ( defined('DOING_AJAX') && DOING_AJAX ) ) {
            return '';
        }
        if ( ! wp_style_is( 'sp-cal-front', 'enqueued' ) ) $this->do_enqueue();

        global $wpdb;

        // Toutes les compétitions avec résultats, groupées par année
        $competitions = $this->db->get_all_competitions();
        $annees       = $this->db->get_competitions_annees();

        // Pré-charger tous les résultats + épreuves en masse (évite N requêtes)
        $all_palmares = $this->db->get_palmares_global(); // tous events confondus
        $all_epreuves = array(); // event_id => array of epreuves

        // Indexer palmares par event_id > epreuve_id > eleve_id
        $palmares_idx = array();
        foreach ( $all_palmares as $row ) {
            $eid = intval($row->event_id);
            $epid = intval($row->epreuve_id);
            $lid  = intval($row->eleve_id);
            if ( ! isset($palmares_idx[$eid]) ) $palmares_idx[$eid] = array();
            if ( ! isset($palmares_idx[$eid][$epid]) ) $palmares_idx[$eid][$epid] = array();
            $palmares_idx[$eid][$epid][$lid] = array(
                'medaille'    => $row->medaille,
                'score'       => $row->score,
                'nom'         => $row->nom,
                'prenom'      => $row->prenom,
                'cat'         => $row->categorie_age,
                'droit_image' => intval($row->droit_image ?? 1),
            );
            // Stocker les infos épreuve
            if ( ! isset($all_epreuves[$eid]) ) $all_epreuves[$eid] = array();
            $all_epreuves[$eid][$epid] = $row->epreuve_nom;
        }

        // Participants par event (pour avoir les élèves sans médaille)
        // On prend seulement ceux avec au moins une médaille pour la vitrine publique
        // → le public ne voit que les médaillés

        ob_start();
        include SP_CAL_PRO_PATH . 'templates/palmares-front.php';
        return ob_get_clean();
    }

    /**
     * Retourne l'URL de la page contenant [sp_cal_palmares].
     * Utilisé par le planning pour rediriger vers le palmarès.
     */
    public static function get_page_url( $event_id = 0 ) {
        $page_url = get_option( 'sp_cal_palmares_url', '' );
        if ( ! $page_url ) {
            // Fallback : chercher une page contenant le shortcode (compatible Gutenberg et classique)
            global $wpdb;
            $pid = $wpdb->get_var(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type   = 'page'
                   AND ( post_content LIKE '%sp_cal_palmares%'
                      OR post_content LIKE '%[sp_cal_palmares]%' )
                 ORDER BY ID DESC
                 LIMIT 1"
            );
            if ( $pid ) {
                $page_url = get_permalink( $pid );
                update_option( 'sp_cal_palmares_url', $page_url );
            }
        }
        if ( ! $page_url ) return '';
        // Nettoyer tout ancre existant avant d'ajouter le nôtre
        $base = preg_replace('/#.*$/', '', rtrim( $page_url, '/' ) );
        if ( $event_id ) {
            return $base . '#competition-' . intval($event_id);
        }
        return $base;
    }
}

endif;

<?php
/**
 * SP_Cal — Dates des grades (import / export CSV) et synchronisation des vacances scolaires
 * Fichier : class-admin-exam.php
 *
 * Contient : ajax_sync_vacances, handle_dates_grades_actions, page_dates_grades.
 * Les anciennes pages « Examens » (référentiel des épreuves) et « Pédagogie » (contenu
 * par grade) ont été retirées le 03/10/2026 avec l'ancien module Jury : les passages de
 * grade passent par class-passages.php et le programme des grades par TKD Parcours.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SP_Cal_Exam' ) ) :

class SP_Cal_Exam {

    private $db;

    public function __construct( $db ) {
        $this->db = $db;

        // Hooks propres au module (retirés de SpCalPro_Admin::__construct)
        add_action( 'admin_init',                   array( $this, 'handle_dates_grades_actions' ) );
        add_action( 'wp_ajax_sp_cal_sync_vacances', array( $this, 'ajax_sync_vacances' ) );
    }

    /* ══════════════════════════════════════════════════════════
       AJAX — Vacances scolaires d'une zone (calendrier officiel)
    ══════════════════════════════════════════════════════════ */

    /**
     * Propose les périodes de vacances d'une zone (A, B ou C) à partir du calendrier officiel
     * (data.education.gouv.fr). N'ENREGISTRE RIEN : la page Paramètres > Calendrier & vacances
     * remplit ses lignes avec la réponse, l'utilisateur corrige puis enregistre lui-même.
     */
    public function ajax_sync_vacances() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Accès refusé' );

        $zone = strtoupper( sanitize_key( $_POST['zone'] ?? 'C' ) );
        if ( ! in_array( $zone, array( 'A', 'B', 'C' ), true ) ) wp_send_json_error( 'Zone inconnue.' );

        // À partir de l'année scolaire en cours (rentrée de septembre) ; les lignes « Enseignants »
        // (prérentrée…) ne concernent pas les élèves.
        $annee  = intval( current_time( 'Y' ) ) - ( intval( current_time( 'n' ) ) < 9 ? 1 : 0 );
        $params = array(
            'limit'    => 100,
            'timezone' => 'Europe/Paris',
            'refine'   => 'zones:Zone ' . $zone,
            'where'    => "end_date >= date'" . $annee . "-09-01' and population != 'Enseignants'",
            // Une ligne par académie de la zone : regroupées, sinon la limite de 100 lignes coupe la liste.
            'group_by' => 'description,start_date,end_date',
            'order_by' => 'start_date asc',
        );
        $url = 'https://data.education.gouv.fr/api/explore/v2.1/catalog/datasets/fr-en-calendrier-scolaire/records?'
             . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

        $resp = wp_remote_get( $url, array( 'timeout' => 15 ) );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( 'Erreur réseau : ' . $resp->get_error_message() );
        }

        $body     = json_decode( wp_remote_retrieve_body( $resp ), true );
        $periodes = self::periodes_depuis_calendrier( is_array( $body['results'] ?? null ) ? $body['results'] : array() );
        // Les vacances d'été précédentes (finies la veille de la rentrée) ne servent plus.
        $rentree  = $annee . '-09-01';
        $periodes = array_values( array_filter( $periodes, static function ( $p ) use ( $rentree ) { return $p['end'] >= $rentree; } ) );
        if ( ! $periodes ) {
            wp_send_json_error( 'Aucune donnée reçue depuis data.education.gouv.fr' );
        }
        wp_send_json_success( array( 'nb' => count( $periodes ), 'zone' => $zone, 'periodes' => $periodes ) );
    }

    /**
     * Lignes du calendrier officiel → périodes [ label, start, end ] aux dates INCLUSES.
     * Le calendrier donne en fin de période le jour de la REPRISE des cours : on retient la veille.
     * Une même période est répétée pour chaque académie de la zone (dédoublonnée) ; une ligne
     * d'un seul jour « Début des vacances d'été » (fin pas encore publiée) est ignorée.
     *
     * @param array $lignes Champ « results » de la réponse.
     * @return array<int,array{label:string,start:string,end:string}>
     */
    public static function periodes_depuis_calendrier( array $lignes ): array {
        $periodes = array();
        foreach ( $lignes as $r ) {
            $start = substr( (string) ( $r['start_date'] ?? '' ), 0, 10 );
            $end   = substr( (string) ( $r['end_date'] ?? '' ), 0, 10 );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) continue;
            $titre = trim( (string) ( $r['description'] ?? '' ) );
            if ( $end > $start ) {
                $end = gmdate( 'Y-m-d', strtotime( $end . ' 12:00:00 UTC' ) - DAY_IN_SECONDS );
            } elseif ( stripos( $titre, 'Début' ) === 0 ) {
                continue;
            }
            $periodes[ $start . '|' . $end ] = array(
                'label' => trim( $titre . ' ' . substr( $start, 0, 4 ) ),
                'start' => $start,
                'end'   => $end,
            );
        }
        ksort( $periodes );
        return array_values( $periodes );
    }

    /* ══════════════════════════════════════════════════════════
       DATES GRADES — Hook admin_init (export/modèle CSV)
    ══════════════════════════════════════════════════════════ */

    public function handle_dates_grades_actions() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'sp-cal-dates-grades' ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( isset( $_GET['action'] ) && $_GET['action'] === 'modele' ) {
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="modele_dates_grades.csv"' );
            echo "licence,grade,date\n0519675,POOM,21/06/2025\n0519675,1er rouge**,15/03/2024\n";
            exit;
        }

        if ( isset( $_GET['action'] ) && $_GET['action'] === 'export' ) {
            global $wpdb;
            $eleves = $wpdb->get_results( "SELECT licence,nom,prenom,extra_data FROM {$wpdb->prefix}sp_cal_eleves WHERE actif=1 ORDER BY nom" );
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="dates_grades_export.csv"' );
            echo "licence,nom,prenom,grade,date\n";
            foreach ( $eleves as $e ) {
                if ( ! $e->extra_data ) continue;
                $extra = json_decode( $e->extra_data, true );
                if ( empty( $extra['grades'] ) ) continue;
                foreach ( $extra['grades'] as $date_str => $grade ) {
                    echo implode( ',', array(
                        $e->licence,
                        '"' . str_replace( '"', '""', $e->nom ) . '"',
                        '"' . str_replace( '"', '""', $e->prenom ) . '"',
                        '"' . str_replace( '"', '""', $grade ) . '"',
                        $date_str,
                    ) ) . "\n";
                }
            }
            exit;
        }
    }

    /* ══════════════════════════════════════════════════════════
       PAGE DATES GRADES — Import CSV
    ══════════════════════════════════════════════════════════ */

    public function page_dates_grades() {
        global $wpdb;
        $msg = ''; $nb_ok = 0; $nb_err = 0; $details = array();

        if ( isset( $_POST['import_dates'] ) && check_admin_referer( 'sp_import_dates' ) ) {
            if ( ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
                $handle = fopen( $_FILES['csv_file']['tmp_name'], 'r' );
                fgetcsv( $handle );
                while ( ( $row = fgetcsv( $handle ) ) !== false ) {
                    if ( count( $row ) < 3 ) { $nb_err++; continue; }
                    $licence  = trim( $row[0] );
                    $grade    = trim( $row[1] );
                    $date_str = trim( $row[2] );
                    $parts    = explode( '/', $date_str );
                    if ( count( $parts ) !== 3 ) { $nb_err++; $details[] = "Date invalide : $date_str"; continue; }
                    $eleve = $wpdb->get_row( $wpdb->prepare(
                        "SELECT id,nom,prenom,extra_data FROM {$wpdb->prefix}sp_cal_eleves WHERE licence=%s LIMIT 1",
                        $licence
                    ) );
                    if ( ! $eleve ) { $nb_err++; $details[] = "Licence introuvable : $licence"; continue; }
                    $extra = $eleve->extra_data ? json_decode( $eleve->extra_data, true ) : array();
                    if ( ! isset( $extra['grades'] ) ) $extra['grades'] = array();
                    $extra['grades'][$date_str] = $grade;
                    $wpdb->update( $wpdb->prefix . 'sp_cal_eleves', array( 'extra_data' => json_encode( $extra, JSON_UNESCAPED_UNICODE ) ), array( 'id' => $eleve->id ) );
                    $nb_ok++;
                    $details[] = "✅ {$eleve->nom} {$eleve->prenom} — $grade le $date_str";
                }
                fclose( $handle );
                $msg = "$nb_ok grade(s) importé(s)" . ( $nb_err ? ", $nb_err erreur(s)" : '' );
            }
        }
        ?>
        <div class="wrap">
            <h1>🥋 Passages de grade</h1>
            <?php SP_Cal_Menu::onglets_grades( 'dates' ); ?>
            <h2>📅 Dates des grades — Import / Export</h2>
            <?php if ( $msg ) : ?>
            <div class="<?php echo $nb_err ? 'notice notice-warning' : 'updated'; ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p>
                <?php if ( ! empty( $details ) ) : ?>
                <ul style="margin:8px 0 0 16px;font-size:12px;">
                    <?php foreach ( array_slice( $details, 0, 20 ) as $d ) : ?>
                    <li><?php echo esc_html( $d ); ?></li>
                    <?php endforeach; ?>
                    <?php if ( count( $details ) > 20 ) : ?><li>... et <?php echo count( $details ) - 20; ?> autres</li><?php endif; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sp-cal-dates-grades&action=modele' ) ); ?>" class="button button-secondary">⬇️ Télécharger le modèle CSV</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sp-cal-dates-grades&action=export' ) ); ?>" class="button button-secondary">⬇️ Exporter les dates actuelles</a>
            </div>

            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;max-width:600px;">
                <h2 style="margin-top:0;">📂 Importer un fichier CSV</h2>
                <p style="color:#666;font-size:13px;">Format : <code>licence,grade,date</code> — Date au format <code>JJ/MM/AAAA</code>.</p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'sp_import_dates' ); ?>
                    <input type="file" name="csv_file" accept=".csv" style="margin-bottom:12px;display:block;">
                    <input type="submit" name="import_dates" class="button button-primary" value="📥 Importer">
                </form>
            </div>
        </div>
        <?php
    }
}

endif;

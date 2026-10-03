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
       AJAX — Synchronisation vacances scolaires Zone C
    ══════════════════════════════════════════════════════════ */

    public function ajax_sync_vacances() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Accès refusé' );

        $url = 'https://data.education.gouv.fr/api/explore/v2.1/catalog/datasets/fr-en-calendrier-scolaire/records'
             . '?limit=100'
             . '&lang=fr'
             . '&timezone=Europe%2FParis'
             . '&refine=zones%3A%22Zone%20C%22'
             . '&refine=population%3A%22El%C3%A8ves%22'
             . '&order_by=start_date%20asc';

        $resp = wp_remote_get( $url, array( 'timeout' => 15 ) );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( 'Erreur réseau : ' . $resp->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $body['results'] ) ) {
            wp_send_json_error( 'Aucune donnée reçue depuis data.education.gouv.fr' );
        }

        $periodes = array();
        $seen     = array();
        foreach ( $body['results'] as $r ) {
            $start = isset( $r['start_date'] ) ? substr( $r['start_date'], 0, 10 ) : '';
            $end   = isset( $r['end_date'] )   ? substr( $r['end_date'],   0, 10 ) : '';
            if ( ! $start || ! $end ) continue;
            $key = $start . '|' . $end;
            if ( isset( $seen[$key] ) ) continue;
            $seen[$key]  = true;
            $periodes[]  = array(
                'label' => sanitize_text_field( $r['description'] ?? '' ),
                'start' => $start,
                'end'   => $end,
            );
        }

        update_option( 'sp_cal_vacances_zoneC', wp_json_encode( $periodes ) );
        wp_send_json_success( array( 'nb' => count( $periodes ), 'periodes' => $periodes ) );
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
            <h1>📅 Dates des grades — Import / Export</h1>
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

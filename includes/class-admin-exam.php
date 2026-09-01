<?php
/**
 * SP_Cal — Module Examens / Pédagogie / Dates grades
 * Fichier : class-admin-exam.php
 *
 * Contient : page_examens, ajax_sync_vacances, page_pedagogie,
 *            handle_dates_grades_actions, page_dates_grades
 *
 * ── Intégration dans class-admin.php ─────────────────────────────────────
 * Voir fichier diffs-class-admin-exam.txt
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
       DISPATCH — appelé depuis SpCalPro_Admin::handle_requests()
    ══════════════════════════════════════════════════════════ */

    public function handle_request() {
        /* ── Épreuve examen : sauvegarder ── */
        if ( isset( $_POST['sp_cal_save_exam_epreuve'] ) && check_admin_referer( 'sp_cal_save_exam_epreuve' ) ) {
            $id = intval( $_POST['exam_epreuve_id'] ?? 0 );
            $this->db->save_exam_epreuve( array(
                'nom'           => $_POST['exam_nom']           ?? '',
                'categorie_age' => $_POST['exam_categorie_age'] ?? '',
                'description'   => $_POST['exam_description']   ?? '',
                'ordre'         => $_POST['exam_ordre']         ?? 0,
                'actif'         => isset( $_POST['exam_actif'] ) ? 1 : 0,
            ), $id );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-examens&saved=1' ) ); exit;
        }

        /* ── Épreuve examen : supprimer ── */
        if ( isset( $_GET['sp_delete_exam_epreuve'] ) && isset( $_GET['_wpnonce'] ) ) {
            $del_id = intval( $_GET['sp_delete_exam_epreuve'] );
            if ( wp_verify_nonce( $_GET['_wpnonce'], 'sp_delete_exam_epreuve_' . $del_id ) ) {
                $this->db->delete_exam_epreuve( $del_id );
                wp_redirect( admin_url( 'admin.php?page=sp-cal-examens&deleted=1' ) ); exit;
            }
        }
    }

    /* ══════════════════════════════════════════════════════════
       PAGE EXAMENS — Référentiel des épreuves
    ══════════════════════════════════════════════════════════ */

    public function page_examens() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        global $wpdb;

        $epreuves = $this->db->get_exam_epreuves( null, false );
        $cats     = $this->db->get_exam_categories();
        $cats_el  = $this->db->get_categories_eleves();
        $all_cats = array_unique( array_merge( $cats, $cats_el ) );
        sort( $all_cats );

        $edit = null;
        if ( isset( $_GET['sp_edit_exam_epreuve'] ) ) {
            $t    = $this->db->table_exam_epreuves();
            $edit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id=%d", intval( $_GET['sp_edit_exam_epreuve'] ) ) );
        }
        ?>
        <div class="wrap sp-cal-wrap">
        <h1>🎓 Référentiel des épreuves d'examen</h1>
        <?php $this->notice_flash( 'saved', 'Épreuve enregistrée.' ); ?>
        <?php $this->notice_flash( 'deleted', 'Épreuve supprimée.' ); ?>

        <div class="sp-box" style="max-width:600px;margin-bottom:24px;">
            <h2><?php echo $edit ? 'Modifier' : 'Nouvelle épreuve'; ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_save_exam_epreuve' ); ?>
                <input type="hidden" name="exam_epreuve_id" value="<?php echo $edit ? intval( $edit->id ) : 0; ?>">
                <table class="form-table" style="width:100%;">
                    <tr>
                        <th style="width:160px;">Intitulé *</th>
                        <td><input type="text" name="exam_nom" class="regular-text" style="width:100%;" required
                                   value="<?php echo esc_attr( $edit->nom ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th>Catégorie d'âge *</th>
                        <td>
                            <input type="text" name="exam_categorie_age" class="regular-text" style="width:100%;" required
                                   list="sp-exam-cats" value="<?php echo esc_attr( $edit->categorie_age ?? '' ); ?>">
                            <datalist id="sp-exam-cats">
                                <?php foreach ( $all_cats as $c ) echo '<option value="' . esc_attr( $c ) . '">'; ?>
                            </datalist>
                        </td>
                    </tr>
                    <tr>
                        <th>Description</th>
                        <td><textarea name="exam_description" rows="3" class="large-text" style="width:100%;"><?php echo esc_textarea( $edit->description ?? '' ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th>Ordre</th>
                        <td><input type="number" name="exam_ordre" value="<?php echo intval( $edit->ordre ?? 0 ); ?>" style="width:80px;"></td>
                    </tr>
                    <tr>
                        <th>Active</th>
                        <td><label><input type="checkbox" name="exam_actif" value="1" <?php checked( intval( $edit->actif ?? 1 ), 1 ); ?>> Proposer lors des examens</label></td>
                    </tr>
                </table>
                <p>
                    <input type="submit" name="sp_cal_save_exam_epreuve" class="button button-primary"
                           value="<?php echo $edit ? 'Mettre à jour' : 'Ajouter'; ?>">
                    <?php if ( $edit ) echo '<a href="' . esc_url( admin_url( 'admin.php?page=sp-cal-examens' ) ) . '" class="button" style="margin-left:8px;">Annuler</a>'; ?>
                </p>
            </form>
        </div>

        <div class="sp-box">
            <h2>Catalogue (<?php echo count( $epreuves ); ?> épreuves)</h2>
            <?php if ( empty( $epreuves ) ) : ?>
                <p class="sp-muted">Aucune épreuve. Utilisez le formulaire ci-dessus.</p>
            <?php else : ?>
            <table class="wp-list-table widefat striped">
                <thead><tr>
                    <th>#</th><th>Intitulé</th><th>Catégorie d'âge</th><th>Active</th><th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $epreuves as $ep ) :
                    $eu = admin_url( 'admin.php?page=sp-cal-examens&sp_edit_exam_epreuve=' . $ep->id );
                    $du = wp_nonce_url( admin_url( 'admin.php?page=sp-cal-examens&sp_delete_exam_epreuve=' . $ep->id ), 'sp_delete_exam_epreuve_' . $ep->id );
                ?>
                <tr>
                    <td><?php echo intval( $ep->ordre ); ?></td>
                    <td><strong><?php echo esc_html( $ep->nom ); ?></strong></td>
                    <td><?php echo esc_html( $ep->categorie_age ); ?></td>
                    <td><?php echo intval( $ep->actif ) ? '✅' : '📦'; ?></td>
                    <td>
                        <a href="<?php echo esc_url( $eu ); ?>" class="button button-small">✏️</a>
                        <a href="<?php echo esc_url( $du ); ?>" class="button button-small sp-btn-del"
                           onclick="return confirm('Supprimer ?')">🗑️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        </div>
        <?php
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
       PAGE PÉDAGOGIE — Contenu par grade
    ══════════════════════════════════════════════════════════ */

    public function page_pedagogie() {
        global $wpdb;
        if ( ! current_user_can('manage_options') ) wp_die('Accès refusé');

        $tgp       = $this->db->table_exam_grade_progression();
        $grad_prog = $wpdb->get_results( "
            SELECT DISTINCT grade_actuel, categorie_age FROM $tgp
            ORDER BY FIELD(categorie_age,'Baby','Enfant','Ado/adulte','Adulte') ASC, id ASC
        " );
        $ajaxurl = admin_url('admin-ajax.php');
        $nonce   = wp_create_nonce('sp_cal_admin_nonce');

        $grade_sel = sanitize_text_field( $_GET['grade_ped'] ?? '' );
        $row_ped   = $grade_sel ? $this->db->get_grade_contenu($grade_sel) : null;
        $desc_ped  = $row_ped ? $row_ped->description : '';
        $liens_ped = ($row_ped && $row_ped->liens) ? (json_decode($row_ped->liens, true) ?: []) : [];

        if ( isset($_POST['ped_save']) && check_admin_referer('sp_ped_save') ) {
            $g    = sanitize_text_field( wp_unslash( $_POST['ped_grade'] ?? '' ) );
            $desc = wp_kses_post( wp_unslash( $_POST['ped_desc'] ?? '' ) );
            $liens_raw = $_POST['ped_liens'] ?? [];
            $liens = [];
            if ( is_array($liens_raw) ) {
                foreach ( $liens_raw as $l ) {
                    $url   = esc_url_raw( wp_unslash( $l['url']   ?? '' ) );
                    $label = sanitize_text_field( wp_unslash( $l['label'] ?? '' ) );
                    $type  = in_array($l['type'] ?? '', ['lien','video','image','pdf']) ? $l['type'] : 'lien';
                    if ( $url ) $liens[] = compact('url','label','type');
                }
            }
            $this->db->save_grade_contenu( $g, $desc, json_encode($liens) );
            wp_redirect( admin_url('admin.php?page=sp-cal-pedagogie&grade_ped=' . urlencode($g) . '&saved=1') );
            exit;
        }

        $saved = isset($_GET['saved']);
        ?>
        <div class="wrap" style="max-width:900px;">
            <h1>📚 Contenu pédagogique par grade</h1>
            <?php if ($saved): ?>
            <div class="updated is-dismissible"><p>✅ Contenu sauvegardé.</p></div>
            <?php endif; ?>
            <p style="color:#64748b;font-size:14px;margin-bottom:20px;">
                Ce contenu s'affiche sur la page de l'élève quand il clique sur une pastille de son parcours.
            </p>

            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin-bottom:24px;">
                <form method="get" action="">
                    <input type="hidden" name="page" value="sp-cal-pedagogie">
                    <label style="font-weight:700;font-size:14px;margin-right:10px;">Grade :</label>
                    <select name="grade_ped" style="border:1px solid #d1d5db;border-radius:6px;padding:8px 12px;font-size:14px;min-width:220px;">
                        <option value="">— Sélectionner un grade —</option>
                        <?php
                        $current_cat = null;
                        foreach ($grad_prog as $row):
                            if ($row->categorie_age !== $current_cat):
                                if ($current_cat !== null) echo '</optgroup>';
                                $current_cat = $row->categorie_age;
                                echo '<optgroup label="' . esc_attr($current_cat) . '">';
                            endif;
                        ?>
                        <option value="<?php echo esc_attr($row->grade_actuel); ?>"
                            <?php selected($grade_sel, $row->grade_actuel); ?>>
                            <?php echo esc_html($row->grade_actuel); ?>
                        </option>
                        <?php endforeach; if ($current_cat !== null) echo '</optgroup>'; ?>
                    </select>
                    <button type="submit" class="button button-primary" style="margin-left:8px;">Charger</button>
                </form>
            </div>

            <?php if ($grade_sel): ?>
            <form method="post" action="">
                <?php wp_nonce_field('sp_ped_save'); ?>
                <input type="hidden" name="ped_save" value="1">
                <input type="hidden" name="ped_grade" value="<?php echo esc_attr($grade_sel); ?>">

                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:16px;">
                    <h3 style="margin:0 0 12px;font-size:15px;">📝 Description / Programme
                        <span style="font-size:12px;color:#64748b;font-weight:400;margin-left:8px;">
                            Grade : <strong><?php echo esc_html($grade_sel); ?></strong>
                        </span>
                    </h3>
                    <?php wp_editor( $desc_ped, 'ped_desc', array(
                        'textarea_name' => 'ped_desc',
                        'media_buttons' => true,
                        'textarea_rows' => 10,
                        'tinymce'       => array('toolbar1'=>'bold,italic,underline,bullist,numlist,link,unlink,undo,redo'),
                    ) ); ?>
                </div>

                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:16px;">
                    <h3 style="margin:0 0 6px;font-size:15px;">🎬 Vidéos YouTube</h3>
                    <p style="font-size:12px;color:#64748b;margin:0 0 12px;">URL YouTube (ex: https://youtu.be/xxxxx)</p>
                    <div id="ped-yt-wrap">
                    <?php foreach ( array_filter($liens_ped, function($l){ return ($l['type']??'') === 'video'; }) as $i => $l ): ?>
                        <div class="ped-yt-row" style="display:flex;gap:10px;align-items:center;margin-bottom:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px;">
                            <?php
                            $ytId = '';
                            if (preg_match('/(?:v=|youtu\.be\/)([^&?\/]+)/', $l['url'], $m)) $ytId = $m[1];
                            ?>
                            <div class="ped-yt-preview">
                                <?php if ($ytId): ?>
                                <img src="https://img.youtube.com/vi/<?php echo esc_attr($ytId); ?>/mqdefault.jpg"
                                     style="width:90px;height:51px;object-fit:cover;border-radius:6px;border:1px solid #ddd;">
                                <?php else: ?>
                                <div style="width:90px;height:51px;background:#f1f5f9;border-radius:6px;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;font-size:22px;">🎬</div>
                                <?php endif; ?>
                            </div>
                            <div style="flex:1;display:flex;flex-direction:column;gap:6px;">
                                <input type="hidden" name="ped_liens[<?php echo $i;?>][type]" value="video">
                                <input type="text" name="ped_liens[<?php echo $i;?>][label]" class="regular-text"
                                       placeholder="Libellé" value="<?php echo esc_attr($l['label']??''); ?>">
                                <input type="text" name="ped_liens[<?php echo $i;?>][url]" class="regular-text"
                                       placeholder="URL YouTube" value="<?php echo esc_attr($l['url']??''); ?>">
                            </div>
                            <button type="button" class="button ped-del-row" style="color:#dc2626;border-color:#dc2626;">✕</button>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <button type="button" class="button" id="ped-add-yt">+ Ajouter une vidéo</button>
                </div>

                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:20px;">
                    <h3 style="margin:0 0 6px;font-size:15px;">🔗 Autres ressources</h3>
                    <div id="ped-liens-wrap">
                    <?php
                    $autres = array_values(array_filter($liens_ped, function($l){ return ($l['type']??'') !== 'video'; }));
                    $offset = count(array_filter($liens_ped, function($l){ return ($l['type']??'') === 'video'; }));
                    foreach ( $autres as $i => $l ):
                        $ri = $i + $offset;
                    ?>
                        <div class="ped-lien-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                            <select name="ped_liens[<?php echo $ri;?>][type]" style="border:1px solid #d1d5db;border-radius:5px;padding:6px 8px;font-size:13px;">
                                <option value="lien"  <?php selected($l['type']??'','lien'); ?>>🔗 Lien</option>
                                <option value="image" <?php selected($l['type']??'','image'); ?>>🖼️ Image</option>
                                <option value="pdf"   <?php selected($l['type']??'','pdf'); ?>>📄 PDF</option>
                            </select>
                            <input type="text" name="ped_liens[<?php echo $ri;?>][label]" class="regular-text"
                                   placeholder="Libellé" value="<?php echo esc_attr($l['label']??''); ?>" style="width:180px;">
                            <input type="text" name="ped_liens[<?php echo $ri;?>][url]" class="regular-text"
                                   placeholder="URL" value="<?php echo esc_attr($l['url']??''); ?>" style="flex:1;">
                            <button type="button" class="button ped-del-row" style="color:#dc2626;border-color:#dc2626;">✕</button>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <button type="button" class="button" id="ped-add-lien">+ Ajouter une ressource</button>
                </div>

                <button type="submit" class="button button-primary button-large">💾 Sauvegarder</button>
            </form>
            <?php endif; ?>
        </div>

        <script>
        (function($){
            var pedIdx = <?php echo count($liens_ped); ?>;

            function pedMakeYtRow(idx) {
                return '<div class="ped-yt-row" style="display:flex;gap:10px;align-items:center;margin-bottom:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px;">'
                    + '<div class="ped-yt-preview"><div style="width:90px;height:51px;background:#f1f5f9;border-radius:6px;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;font-size:22px;">🎬</div></div>'
                    + '<div style="flex:1;display:flex;flex-direction:column;gap:6px;">'
                    + '<input type="hidden" name="ped_liens['+idx+'][type]" value="video">'
                    + '<input type="text" name="ped_liens['+idx+'][label]" class="regular-text" placeholder="Libellé">'
                    + '<input type="text" name="ped_liens['+idx+'][url]" class="regular-text" placeholder="URL YouTube">'
                    + '</div>'
                    + '<button type="button" class="button ped-del-row" style="color:#dc2626;border-color:#dc2626;">✕</button>'
                    + '</div>';
            }

            function pedMakeLienRow(idx) {
                return '<div class="ped-lien-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">'
                    + '<select name="ped_liens['+idx+'][type]" style="border:1px solid #d1d5db;border-radius:5px;padding:6px 8px;font-size:13px;">'
                    + '<option value="lien">🔗 Lien</option>'
                    + '<option value="image">🖼️ Image</option>'
                    + '<option value="pdf">📄 PDF</option>'
                    + '</select>'
                    + '<input type="text" name="ped_liens['+idx+'][label]" class="regular-text" placeholder="Libellé" style="width:180px;">'
                    + '<input type="text" name="ped_liens['+idx+'][url]" class="regular-text" placeholder="URL" style="flex:1;">'
                    + '<button type="button" class="button ped-del-row" style="color:#dc2626;border-color:#dc2626;">✕</button>'
                    + '</div>';
            }

            $(document).on('input', 'input[name*="[url]"]', function(){
                var url = $(this).val();
                var m = url.match(/(?:v=|youtu\.be\/)([^&?\/]+)/);
                var row = $(this).closest('.ped-yt-row');
                if (!row.length) return;
                var preview = row.find('.ped-yt-preview');
                preview.html(m
                    ? '<img src="https://img.youtube.com/vi/'+m[1]+'/mqdefault.jpg" style="width:90px;height:51px;object-fit:cover;border-radius:6px;border:1px solid #ddd;">'
                    : '<div style="width:90px;height:51px;background:#f1f5f9;border-radius:6px;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;font-size:22px;">🎬</div>');
            });

            $('#ped-add-yt').on('click',   function(){ $('#ped-yt-wrap').append(pedMakeYtRow(pedIdx++)); });
            $('#ped-add-lien').on('click',  function(){ $('#ped-liens-wrap').append(pedMakeLienRow(pedIdx++)); });
            $(document).on('click', '.ped-del-row', function(){ $(this).closest('div[class*="ped-"]').remove(); });
        })(jQuery);
        </script>
        <?php
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

    /* ══════════════════════════════════════════════════════════
       UTILITAIRE
    ══════════════════════════════════════════════════════════ */

    private function notice_flash( $key, $message ) {
        if ( isset( $_GET[ $key ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }
    }
}

endif;

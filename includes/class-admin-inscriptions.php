<?php
/**
 * SP_Cal — Module Options Inscriptions (v2)
 * Fichier  : class-admin-inscriptions.php
 *
 * Colonnes épreuves intégrées dans le tableau existant des participants.
 * Cases cochables pour les inscrits confirmés, grisées pour les autres.
 * Champ de saisie des libellés dans le panneau vert des invitations.
 *
 * ── Intégration dans class-admin.php (6 touches) ─────────────────────────
 * Voir fichier diffs-class-admin.txt
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SP_Cal_Inscriptions_Options' ) ) :

class SP_Cal_Inscriptions_Options {

    private $db;
    private $current_options  = array();
    private $current_reponses = array();
    private $current_event_id = 0;
    private $current_nonce    = '';

    public function __construct( $db ) {
        $this->db = $db;
        add_action( 'wp_ajax_sp_insc_options_save',        array( $this, 'ajax_options_save' ) );
        add_action( 'wp_ajax_sp_insc_options_labels_save', array( $this, 'ajax_options_labels_save' ) );
        add_action( 'sp_cal_inscriptions_panel_extra', array( $this, 'render_panel_field' ),  10, 3 );
        add_action( 'sp_cal_inscriptions_th',          array( $this, 'render_th' ),           10, 2 );
        add_action( 'sp_cal_inscriptions_td',          array( $this, 'render_td' ),           10, 2 );
        add_action( 'sp_cal_inscriptions_extra',       array( $this, 'render_save_button' ),  10, 2 );
        add_filter( 'sp_insc_extra_cols',       array( $this, 'filter_extra_cols' ),       10, 2 );
        add_filter( 'sp_insc_extra_row_values', array( $this, 'filter_extra_row_values' ), 10, 3 );
        add_action( 'admin_init', array( $this, 'maybe_migrate' ) );
    }

    /* ── Migration DB ───────────────────────────────────────────────────── */

    public function maybe_migrate() {
        global $wpdb;
        $te = $this->db->table_events();
        $ti = $this->db->table_event_inscriptions();

        if ( ! in_array( 'inscriptions_options', $wpdb->get_col( "SHOW COLUMNS FROM $te" ), true ) ) {
            $wpdb->query( "ALTER TABLE $te ADD COLUMN inscriptions_options TEXT NULL COMMENT 'Libellés épreuves séparés par virgule'" );
        }
        if ( ! in_array( 'options_reponse', $wpdb->get_col( "SHOW COLUMNS FROM $ti" ), true ) ) {
            $wpdb->query( "ALTER TABLE $ti ADD COLUMN options_reponse TEXT NULL COMMENT 'JSON {\"libellé\": bool}'" );
        }
    }

    /* ── Panneau vert — champ saisie libellés ───────────────────────────── */

    public function render_panel_field( $event_id, $event, $nonce ) {
        $this->current_nonce    = $nonce;
        $this->current_event_id = $event_id;
        ?>
        <div style="margin-top:12px;padding-top:12px;padding-bottom:12px;border-top:1px solid rgba(0,0,0,0.1);">
            <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:6px;">
                🏆 Épreuves disponibles
                <span style="font-weight:400;color:#6b7280;">(séparées par virgule — laisser vide si aucune option)</span>
            </label>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <input type="text"
                       id="sp-opts-labels-input"
                       value="<?php echo esc_attr( $event->inscriptions_options ?? '' ); ?>"
                       placeholder="Ex : Combat, Poomsae ind., Duotang, Poomsae équipe"
                       style="flex:1;min-width:260px;max-width:480px;height:30px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:13px;">
                <button type="button" id="sp-opts-labels-save" class="button"
                        data-event="<?php echo intval( $event_id ); ?>"
                        data-nonce="<?php echo esc_attr( $nonce ); ?>"
                        style="height:30px;white-space:nowrap;">
                    💾 Enregistrer
                </button>
                <span id="sp-opts-labels-msg" style="font-size:12px;color:#6b7280;"></span>
            </div>
        </div>
        <script>
        (function(){
            var btn = document.getElementById('sp-opts-labels-save');
            if ( ! btn ) return;
            btn.addEventListener('click', function(){
                var fd = new FormData();
                fd.append('action',   'sp_insc_options_labels_save');
                fd.append('nonce',    btn.dataset.nonce);
                fd.append('event_id', btn.dataset.event);
                fd.append('labels',   document.getElementById('sp-opts-labels-input').value.trim());
                var msg = document.getElementById('sp-opts-labels-msg');
                btn.disabled = true; msg.textContent = 'Enregistrement…'; msg.style.color = '#6b7280';
                fetch(ajaxurl, { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if ( r.success ) {
                            msg.style.color = '#15803d'; msg.textContent = '✅ Enregistré — rechargement…';
                            setTimeout(function(){ location.reload(); }, 600);
                        } else {
                            msg.style.color = '#b91c1c'; msg.textContent = '❌ ' + (r.data || 'Erreur');
                            btn.disabled = false;
                        }
                    })
                    .catch(function(){ msg.style.color = '#b91c1c'; msg.textContent = '❌ Erreur réseau'; btn.disabled = false; });
            });
        })();
        </script>
        <?php
    }

    /* ── Tableau — en-têtes colonnes options ────────────────────────────── */

    public function render_th( $event_id, $event ) {
        global $wpdb;
        $this->current_options  = $this->parse_options( $event->inscriptions_options ?? '' );
        $this->current_event_id = $event_id;
        if ( empty( $this->current_options ) ) return;

        // Pré-charger options_reponse en 1 requête
        $ti   = $this->db->table_event_inscriptions();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT eleve_id, options_reponse FROM $ti WHERE event_id = %d",
            intval( $event_id )
        ) );
        $this->current_reponses = array();
        foreach ( $rows as $row ) {
            $this->current_reponses[ intval( $row->eleve_id ) ] =
                json_decode( $row->options_reponse ?? '{}', true ) ?: array();
        }

        foreach ( $this->current_options as $opt ) {
            echo '<th style="text-align:center;white-space:nowrap;font-size:12px;padding:8px 10px;min-width:80px;">'
               . esc_html( $opt ) . '</th>';
        }
    }

    /* ── Tableau — cellules options par ligne ───────────────────────────── */

    public function render_td( $event_id, $insc ) {
        if ( empty( $this->current_options ) ) return;

        $eleve_id    = intval( $insc->eleve_id );
        $reponses    = $this->current_reponses[ $eleve_id ] ?? array();
        $est_inscrit = ( $insc->statut === 'inscrit' );

        foreach ( $this->current_options as $opt ) {
            $checked = isset( $reponses[ $opt ] ) && $reponses[ $opt ] === true;
            if ( $est_inscrit ) {
                echo '<td style="text-align:center;padding:6px 10px;">'
                   . '<input type="checkbox" class="sp-opt-cb"'
                   . ' data-opt="' . esc_attr( $opt ) . '"'
                   . ( $checked ? ' checked' : '' )
                   . ' style="width:17px;height:17px;cursor:pointer;accent-color:#2563eb;"></td>';
            } else {
                // Non-inscrit : tiret centré, fond légèrement coloré selon statut
                $bg = $insc->statut === 'refuse' ? '#fff1f1' : '#f8fafc';
                echo '<td style="text-align:center;padding:6px 10px;background:' . $bg . ';'
                   . 'color:#d1d5db;font-size:14px;font-weight:300;">—</td>';
            }
        }
    }

    /* ── Bouton sauvegarde + JS (après le tableau) ──────────────────────── */

    public function render_save_button( $event_id, $event ) {
        if ( empty( $this->current_options ) ) return;
        $nonce = $this->current_nonce ?: wp_create_nonce( 'sp_cal_admin_nonce' );
        ?>
        <div style="margin-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button type="button" id="sp-opts-save-all" class="button button-primary"
                    data-event="<?php echo intval( $event_id ); ?>"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>"
                    style="height:30px;">
                💾 Enregistrer les épreuves cochées
            </button>
            <button type="button" id="sp-opts-select-all" class="button" style="height:30px;">✅ Tout cocher</button>
            <button type="button" id="sp-opts-clear-all"  class="button" style="height:30px;">⬜ Tout décocher</button>
            <span id="sp-opts-save-msg" style="font-size:13px;color:#6b7280;"></span>
        </div>
        <script>
        (function(){
            document.getElementById('sp-opts-select-all')?.addEventListener('click', function(){
                document.querySelectorAll('#sp-insc-table .sp-opt-cb').forEach(function(c){ c.checked = true; });
            });
            document.getElementById('sp-opts-clear-all')?.addEventListener('click', function(){
                document.querySelectorAll('#sp-insc-table .sp-opt-cb').forEach(function(c){ c.checked = false; });
            });
            var saveBtn = document.getElementById('sp-opts-save-all');
            if ( ! saveBtn ) return;
            saveBtn.addEventListener('click', function(){
                var payload = [];
                document.querySelectorAll('#sp-insc-table tbody tr[data-eleve]').forEach(function(row){
                    var cbs = row.querySelectorAll('.sp-opt-cb');
                    if ( ! cbs.length ) return;
                    var opts = {};
                    cbs.forEach(function(cb){ opts[cb.dataset.opt] = cb.checked; });
                    payload.push({ eleve_id: row.dataset.eleve, opts: opts });
                });
                var msg = document.getElementById('sp-opts-save-msg');
                saveBtn.disabled = true; msg.textContent = 'Enregistrement…'; msg.style.color = '#6b7280';
                var fd = new FormData();
                fd.append('action',   'sp_insc_options_save');
                fd.append('nonce',    saveBtn.dataset.nonce);
                fd.append('event_id', saveBtn.dataset.event);
                fd.append('payload',  JSON.stringify(payload));
                fetch(ajaxurl, { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        saveBtn.disabled = false;
                        if ( r.success ) {
                            msg.style.color = '#15803d';
                            msg.textContent = '✅ ' + r.data.saved + ' participant(s) enregistré(s)';
                        } else {
                            msg.style.color = '#b91c1c';
                            msg.textContent = '❌ ' + (r.data || 'Erreur');
                        }
                    })
                    .catch(function(){ saveBtn.disabled = false; msg.style.color = '#b91c1c'; msg.textContent = '❌ Erreur réseau'; });
            });
        })();
        </script>
        <?php
    }

    /* ── AJAX — Libellés ────────────────────────────────────────────────── */

    public function ajax_options_labels_save() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé', 403 );
        global $wpdb;
        $event_id = intval( $_POST['event_id'] ?? 0 );
        $labels   = sanitize_text_field( wp_unslash( $_POST['labels'] ?? '' ) );
        if ( ! $event_id ) wp_send_json_error( 'event_id manquant' );
        $te = $this->db->table_events();
        $ok = $wpdb->update( $te, array( 'inscriptions_options' => $labels ), array( 'id' => $event_id ), array( '%s' ), array( '%d' ) );
        $ok !== false ? wp_send_json_success( array( 'labels' => $labels ) ) : wp_send_json_error( $wpdb->last_error );
    }

    /* ── AJAX — Grille cases à cocher ───────────────────────────────────── */

    public function ajax_options_save() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé', 403 );
        global $wpdb;
        $event_id = intval( $_POST['event_id'] ?? 0 );
        $payload  = json_decode( wp_unslash( $_POST['payload'] ?? '[]' ), true );
        if ( ! $event_id || ! is_array( $payload ) ) wp_send_json_error( 'Paramètres invalides' );
        $ti = $this->db->table_event_inscriptions();
        $saved = 0;
        foreach ( $payload as $item ) {
            $eleve_id = intval( $item['eleve_id'] ?? 0 );
            $opts     = $item['opts'] ?? array();
            if ( ! $eleve_id || ! is_array( $opts ) ) continue;
            $opts_clean = array();
            foreach ( $opts as $label => $val ) {
                $opts_clean[ sanitize_text_field( $label ) ] = (bool) $val;
            }
            $ok = $wpdb->update( $ti, array( 'options_reponse' => wp_json_encode( $opts_clean, JSON_UNESCAPED_UNICODE ) ), array( 'event_id' => $event_id, 'eleve_id' => $eleve_id ), array( '%s' ), array( '%d', '%d' ) );
            if ( $ok !== false ) $saved++;
        }
        wp_send_json_success( array( 'saved' => $saved ) );
    }

    /* ── Filters export CSV ─────────────────────────────────────────────── */

    public function filter_extra_cols( $extra_cols, $event ) {
        foreach ( $this->parse_options( $event->inscriptions_options ?? '' ) as $opt ) {
            $extra_cols[ 'opt_' . md5( $opt ) ] = $opt;
        }
        return $extra_cols;
    }

    public function filter_extra_row_values( $values, $row, $event ) {
        $options = $this->parse_options( $event->inscriptions_options ?? '' );
        if ( empty( $options ) ) return $values;
        $reponses = ! empty( $row->options_reponse ) ? ( json_decode( $row->options_reponse, true ) ?: array() ) : array();
        foreach ( $options as $opt ) {
            $values[ 'opt_' . md5( $opt ) ] = ( isset( $reponses[$opt] ) && $reponses[$opt] === true ) ? 'Oui' : '';
        }
        return $values;
    }

    /* ── Utilitaire ─────────────────────────────────────────────────────── */

    private function parse_options( $raw ) {
        return array_values( array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) ) );
    }
}

endif;


/* ══════════════════════════════════════════════════════════════════════════
   SP_Cal_Inscriptions — Classe principale du module inscription
   (page, AJAX, export CSV)
   SP_Cal_Inscriptions_Options reste ci-dessus pour les options/épreuves.
══════════════════════════════════════════════════════════════════════════ */

if ( ! class_exists( 'SP_Cal_Inscriptions' ) ) :

class SP_Cal_Inscriptions {

    private $db;
    /** @var SP_Cal_Inscriptions_Options */
    private $options_module;

    public function __construct( $db ) {
        $this->db = $db;

        // Hooks AJAX inscription (retirés de SpCalPro_Admin::__construct)
        add_action( 'wp_ajax_sp_inscription_envoyer',        array( $this, 'ajax_inscription_envoyer' ) );
        add_action( 'wp_ajax_sp_inscription_bulk',           array( $this, 'ajax_inscription_bulk' ) );
        add_action( 'wp_ajax_sp_inscription_count_cibles',   array( $this, 'ajax_inscription_count_cibles' ) );
        add_action( 'wp_ajax_sp_inscription_list_cibles',    array( $this, 'ajax_inscription_list_cibles' ) );
        add_action( 'wp_ajax_sp_cal_get_cats_saisie',        array( $this, 'ajax_get_cats_saisie' ) );

        // Instancier le module options
        $this->options_module = new SP_Cal_Inscriptions_Options( $db );
    }

    /* ── handle_request : export CSV (appelé depuis handle_requests) ──── */

    public function handle_request() {
        global $wpdb;
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'sp-cal-inscriptions'
             && isset( $_GET['action'] ) && $_GET['action'] === 'export_csv'
             && isset( $_GET['event_id'] ) && check_admin_referer( 'sp_insc_export' ) ) {
            $this->export_inscriptions_csv();
            exit;
        }
    }


    /* ── page_inscriptions ── */

    public function page_inscriptions() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        global $wpdb;
        $event_id = intval( $_GET['event_id'] ?? 0 );
        $te       = $this->db->table_events();

        // Vérifier que la table existe (migration SQL faite)
        if ( ! method_exists( $this->db, 'table_event_inscriptions' ) ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>⚠️ La table des inscriptions n&#39;existe pas encore. Veuillez exécuter le SQL de migration.</p></div></div>';
            return;
        }

        // ── Liste des événements avec inscriptions activées ───────────────────
        if ( ! $event_id ) {
            $events = $wpdb->get_results(
                "SELECT * FROM $te WHERE inscriptions_actives=1 ORDER BY date DESC LIMIT 50"
            );
            $ti = $this->db->table_event_inscriptions();
            echo '<div class="wrap"><h1>📋 Inscriptions aux événements</h1>';
            if ( empty( $events ) ) {
                echo '<p>Aucun événement avec inscriptions activées. Ouvrez un événement dans le calendrier et cochez "Inscriptions actives".</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr><th>Événement</th><th>Date</th><th>Deadline</th><th>Envoyé</th><th>✅ Inscrits</th><th>❌ Refus</th><th>⏳ En attente</th><th>Actions</th></tr></thead><tbody>';
                foreach ( $events as $ev ) {
                    $url    = admin_url( 'admin.php?page=sp-cal-inscriptions&event_id=' . intval($ev->id) );
                    $nb_ok  = intval($wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ti WHERE event_id=%d AND statut='inscrit'",    intval($ev->id) ) ));
                    $nb_ko  = intval($wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ti WHERE event_id=%d AND statut='refuse'",     intval($ev->id) ) ));
                    $nb_att = intval($wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ti WHERE event_id=%d AND statut='en_attente'", intval($ev->id) ) ));
                    $d_fmt  = $ev->date ? date_create($ev->date)->format('d/m/Y') : '—';
                    echo '<tr>';
                    echo '<td><a href="' . esc_url($url) . '"><strong>' . esc_html($ev->titre) . '</strong></a></td>';
                    echo '<td>' . esc_html($d_fmt) . '</td>';
                    echo '<td>' . esc_html($ev->inscriptions_deadline ?: '—') . '</td>';
                    echo '<td>' . ( $ev->inscriptions_envoye ? '<span style="color:#15803d;">✅ Oui</span>' : '<span style="color:#92400e;">⏳ Non</span>' ) . '</td>';
                    echo '<td style="text-align:center;">' . $nb_ok  . '</td>';
                    echo '<td style="text-align:center;">' . $nb_ko  . '</td>';
                    echo '<td style="text-align:center;">' . $nb_att . '</td>';
                    echo '<td><a href="' . esc_url($url) . '" class="button button-small">Gérer</a></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';
            return;
        }

        // ── Détail d'un événement ─────────────────────────────────────────────
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $te WHERE id=%d", $event_id ) );
        if ( ! $event ) { echo '<div class="wrap"><p>Événement introuvable.</p></div>'; return; }

        $date_fmt  = $event->date ? date_create($event->date)->format('d/m/Y') : '—';
        $ti        = $this->db->table_event_inscriptions();
        $tel_cats  = $this->db->table_eleves();

        // Catégories configurées sur l'event
        $cats_event = array_filter( array_map( 'trim', explode( ',', $event->inscriptions_categories     ?? '' ) ) );
        $ages_event = array_filter( array_map( 'trim', explode( ',', $event->inscriptions_age_categories ?? '' ) ) );

        // ── Requête fusionnée : tous les élèves ciblés + leur statut d'inscription ──
        $where_parts = array( "e.actif = 1", "( e.email != '' OR e.email_parent != '' )" );
        $where_args  = array( $event_id );

        if ( ! empty( $cats_event ) ) {
            $ph            = implode( ',', array_fill( 0, count( $cats_event ), '%s' ) );
            $where_parts[] = "e.categorie_saisie IN ($ph)";
            $where_args    = array_merge( $where_args, array_values( $cats_event ) );
        }
        if ( ! empty( $ages_event ) ) {
            $ph            = implode( ',', array_fill( 0, count( $ages_event ), '%s' ) );
            $where_parts[] = "e.categorie_age IN ($ph)";
            $where_args    = array_merge( $where_args, array_values( $ages_event ) );
        }
        $where         = implode( ' AND ', $where_parts );
        $sql_cibles    = "SELECT e.id as eleve_id, e.nom, e.prenom, e.categorie_age, e.categorie_saisie,
                                 e.email, e.email_parent,
                                 i.statut, i.date_reponse, i.commentaire,
                                 COALESCE(i.nb_changements, 0) as nb_changements
                          FROM $tel_cats e
                          LEFT JOIN $ti i ON i.event_id = %d AND i.eleve_id = e.id
                          WHERE $where
                          ORDER BY e.nom, e.prenom";
        $eleves_cibles = $wpdb->get_results( $wpdb->prepare( $sql_cibles, ...$where_args ) );

        $nb_inscrits = 0; $nb_refuses = 0; $nb_attente = 0; $nb_non_invite = 0;
        foreach ( $eleves_cibles as $ec ) {
            if      ( $ec->statut === 'inscrit' )    $nb_inscrits++;
            elseif  ( $ec->statut === 'refuse' )     $nb_refuses++;
            elseif  ( $ec->statut === 'en_attente' ) $nb_attente++;
            else                                     $nb_non_invite++;
        }
        $back_url = admin_url( 'admin.php?page=sp-cal-inscriptions' );
        $nonce    = wp_create_nonce( 'sp_cal_admin_nonce' );
        ?>
        <div class="wrap">
            <h1>📋 Inscriptions — <?php echo esc_html( $event->titre ); ?></h1>
            <p>
                <a href="<?php echo esc_url($back_url); ?>" class="button">← Retour</a>
                &nbsp;
                <strong>📅 <?php echo esc_html($date_fmt); ?></strong>
                &nbsp;&nbsp;
                <span style="color:#15803d;">✅ <?php echo $nb_inscrits; ?> inscrits</span>
                &nbsp;&nbsp;
                <span style="color:#b91c1c;">❌ <?php echo $nb_refuses; ?> refus</span>
                &nbsp;&nbsp;
                <span style="color:#92400e;">⏳ <?php echo $nb_attente; ?> en attente</span>
                &nbsp;&nbsp;
                <span style="color:#6b7280;">⬜ <?php echo $nb_non_invite; ?> non invité(s)</span>
            </p>

            <!-- ── Export CSV ─────────────────────────────────────────── -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin-bottom:16px;">
                <form method="get" action="<?php echo esc_url( admin_url('admin.php') ); ?>" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:16px;">
                    <?php wp_nonce_field( 'sp_insc_export' ); ?>
                    <input type="hidden" name="page"     value="sp-cal-inscriptions">
                    <input type="hidden" name="action"   value="export_csv">
                    <input type="hidden" name="event_id" value="<?php echo intval($event_id); ?>">

                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">📋 Profil d'export</label>
                        <select name="profil" style="height:30px;border:1px solid #8c8f94;border-radius:4px;padding:0 6px;font-size:13px;">
                            <option value="feuille_appel">📝 Feuille d'appel</option>
                            <option value="competition">🏆 Compétition FFTDA</option>
                            <option value="complet">📦 Complet (toutes colonnes)</option>
                        </select>
                    </div>

                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Inclure les statuts</label>
                        <div style="display:flex;gap:12px;align-items:center;height:30px;">
                            <label style="font-size:13px;cursor:pointer;display:flex;align-items:center;gap:4px;">
                                <input type="checkbox" name="statuts[]" value="inscrit" checked> ✅ Inscrits
                            </label>
                            <label style="font-size:13px;cursor:pointer;display:flex;align-items:center;gap:4px;">
                                <input type="checkbox" name="statuts[]" value="en_attente"> ⏳ En attente
                            </label>
                            <label style="font-size:13px;cursor:pointer;display:flex;align-items:center;gap:4px;">
                                <input type="checkbox" name="statuts[]" value="refuse"> ❌ Refus
                            </label>
                        </div>
                    </div>

                    <div>
                        <button type="submit" class="button button-primary" style="height:30px;">
                            📥 Exporter CSV
                        </button>
                    </div>
                </form>
            </div>
            <!-- ── Fin Export CSV ──────────────────────────────────────── -->

            <?php
            // Catégories saisie disponibles en base
            $tel_cats = $this->db->table_eleves();
            $cats_disponibles = $wpdb->get_col(
                "SELECT DISTINCT categorie_saisie FROM $tel_cats WHERE categorie_saisie != '' AND actif = 1 ORDER BY categorie_saisie"
            );
            // Catégories déjà configurées sur l'event
            $cats_event     = array_filter( array_map( 'trim', explode( ',', $event->inscriptions_categories     ?? '' ) ) );
            $ages_event     = array_filter( array_map( 'trim', explode( ',', $event->inscriptions_age_categories ?? '' ) ) );

            // Catégories d'âge disponibles
            $ages_disponibles = $wpdb->get_col(
                "SELECT DISTINCT categorie_age FROM $tel_cats WHERE categorie_age != '' AND actif = 1 ORDER BY categorie_age"
            );
            ?>
            <div style="background:<?php echo $event->inscriptions_envoye ? '#f0fdf4' : '#fef9c3'; ?>;border:1px solid <?php echo $event->inscriptions_envoye ? '#86efac' : '#fbbf24'; ?>;border-radius:8px;padding:16px 18px;margin-bottom:16px;">

                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
                    <?php if ( ! $event->inscriptions_envoye ) : ?>
                        <strong>⚠️ Invitations non encore envoyées.</strong>
                    <?php else : ?>
                        <strong>✅ Invitations déjà envoyées.</strong>
                    <?php endif; ?>
                </div>

                <?php if ( ! empty( $cats_disponibles ) ) : ?>
                <div style="margin-bottom:12px;">
                    <label style="font-size:12px;font-weight:700;display:block;margin-bottom:6px;color:#374151;">
                        🎯 Catégories ciblées
                        <span style="font-weight:400;color:#6b7280;"> — sélectionnez les catégories à inviter (vide = tous les élèves actifs)</span>
                    </label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;" id="sp-cats-wrap">
                        <?php foreach ( $cats_disponibles as $cat ) :
                            $checked = empty( $cats_event ) || in_array( $cat, $cats_event, true );
                        ?>
                        <label style="display:flex;align-items:center;gap:5px;background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:5px 10px;cursor:pointer;font-size:13px;">
                            <input type="checkbox" class="sp-insc-cat-cb" value="<?php echo esc_attr($cat); ?>"
                                <?php checked($checked); ?>>
                            <?php echo esc_html($cat); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p style="font-size:12px;color:#6b7280;margin:6px 0 0;">
                        <a href="#" id="sp-insc-cat-all" style="font-size:12px;">Tout sélectionner</a>
                        <a href="#" id="sp-insc-cat-none" style="margin-left:8px;font-size:12px;">Tout désélectionner</a>
                    </p>
                </div>

                <!-- Groupe tranches d'âge -->
                <?php if ( ! empty( $ages_disponibles ) ) : ?>
                <div style="margin-bottom:12px;">
                    <label style="font-size:12px;font-weight:700;display:block;margin-bottom:6px;color:#374151;">
                        👶→🧑 Tranches d'âge
                        <span style="font-weight:400;color:#6b7280;"> — vide = toutes les tranches</span>
                    </label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;" id="sp-ages-wrap">
                        <?php foreach ( $ages_disponibles as $age ) :
                            $age_checked = empty( $ages_event ) || in_array( $age, $ages_event, true );
                        ?>
                        <label style="display:flex;align-items:center;gap:5px;background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:5px 10px;cursor:pointer;font-size:13px;">
                            <input type="checkbox" class="sp-insc-age-cb" value="<?php echo esc_attr($age); ?>"
                                <?php checked($age_checked); ?>>
                            <?php echo esc_html($age); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p style="font-size:12px;color:#6b7280;margin:6px 0 0;">
                        <a href="#" id="sp-insc-age-all" style="font-size:12px;">Tout sélectionner</a>
                        <a href="#" id="sp-insc-age-none" style="margin-left:8px;font-size:12px;">Tout désélectionner</a>
                    </p>
                </div>
                <?php endif; ?>

                <p style="font-size:12px;color:#6b7280;margin:0 0 12px;">
                    🎯 <span id="sp-insc-nb-cibles">—</span> élève(s) ciblé(s)
                    <span style="color:#9ca3af;"> — Discipline ET tranche d'âge doivent correspondre</span>
                </p>
                <?php endif; ?>

                <?php do_action( 'sp_cal_inscriptions_panel_extra', $event_id, $event, $nonce ); ?>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <button id="sp-insc-envoyer-btn" class="button button-primary"
                            data-event="<?php echo intval($event_id); ?>"
                            data-nonce="<?php echo esc_attr($nonce); ?>">
                        <?php echo $event->inscriptions_envoye ? '🔁 Renvoyer aux non-répondants' : '📨 Envoyer les invitations'; ?>
                    </button>
                    <span id="sp-insc-envoyer-msg" style="font-size:13px;color:#6b7280;"></span>
                </div>
            </div>

            <!-- ── Barre d'actions groupées ── -->
            <div id="sp-insc-bulk-bar" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <label style="font-size:13px;font-weight:600;cursor:pointer;">
                    <input type="checkbox" id="sp-insc-check-all"> Tout sélectionner
                </label>
                <span id="sp-insc-sel-count" style="font-size:12px;color:#6b7280;display:none;"></span>
                <select id="sp-insc-bulk-action" style="height:30px;border:1px solid #8c8f94;border-radius:4px;padding:0 6px;font-size:13px;">
                    <option value="">— Action groupée —</option>
                    <option value="renvoi">📨 Renvoyer l'invitation</option>
                    <option value="inscrit">✅ Marquer comme inscrit(e)</option>
                    <option value="refuse">❌ Marquer comme décliné</option>
                    <option value="en_attente">🔄 Remettre en attente</option>
                </select>
                <button id="sp-insc-bulk-apply" class="button button-primary" style="height:30px;" disabled>Appliquer</button>
                <span id="sp-insc-bulk-msg" style="font-size:13px;color:#6b7280;"></span>
            </div>

            <?php if ( empty( $eleves_cibles ) ) : ?>
                <p style="color:#6b7280;">Aucun élève dans le public ciblé (vérifiez les catégories configurées sur l'événement).</p>
            <?php else : ?>
            <table class="widefat striped" id="sp-insc-table">
                <thead><tr>
                    <th style="width:32px;"></th>
                    <th>Nom</th><th>Catégorie</th><th>Email</th><th>Statut</th><th>Répondu le</th><th>Commentaire</th><th style="width:60px;text-align:center;" title="Nombre de changements de statut">🔄</th>
                    <?php do_action( 'sp_cal_inscriptions_th', $event_id, $event ); ?>
                </tr></thead>
                <tbody>
                <?php foreach ( $eleves_cibles as $insc ) :
                    $badges = array(
                        'inscrit'    => '<span style="background:#dcfce7;color:#15803d;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;">✅ Inscrit(e)</span>',
                        'refuse'     => '<span style="background:#fee2e2;color:#b91c1c;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;">❌ Décliné</span>',
                        'en_attente' => '<span style="background:#fef9c3;color:#92400e;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;">⏳ En attente</span>',
                    );
                    $badge = isset($badges[$insc->statut]) ? $badges[$insc->statut]
                           : '<span style="background:#f3f4f6;color:#6b7280;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;">⬜ Pas encore invité</span>';
                    $email_affiche = ! empty( $insc->email ) ? $insc->email
                                   : ( ! empty( $insc->email_parent ) ? $insc->email_parent . ' <span style="font-size:11px;color:#9ca3af;">(parent)</span>' : '—' );
                ?>
                <tr data-eleve="<?php echo intval($insc->eleve_id); ?>">
                    <td><input type="checkbox" class="sp-insc-cb" value="<?php echo intval($insc->eleve_id); ?>"
                        <?php echo is_null($insc->statut) ? 'disabled title="Non encore invité"' : ''; ?>></td>
                    <td><strong><?php echo esc_html( $insc->prenom . ' ' . mb_strtoupper($insc->nom) ); ?></strong></td>
                    <td><?php echo esc_html( $insc->categorie_age ?: $insc->categorie_saisie ?: '—' ); ?></td>
                    <td style="font-size:12px;"><?php echo $email_affiche; ?></td>
                    <td id="sp-statut-<?php echo intval($insc->eleve_id); ?>"><?php echo $badge; ?></td>
                    <td><?php echo $insc->date_reponse ? esc_html( date_create($insc->date_reponse)->format('d/m/Y H:i') ) : '—'; ?></td>
                    <td><?php echo esc_html( $insc->commentaire ?: '' ); ?></td>
                    <td style="text-align:center;">
                        <?php
                        $nb = intval( $insc->nb_changements );
                        if ( $nb > 0 ) {
                            echo '<span title="A changé d\'avis ' . $nb . ' fois" style="'
                               . 'background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:12px;'
                               . 'font-size:11px;font-weight:700;cursor:default;">🔄 ×' . $nb . '</span>';
                        }
                        ?>
                    </td>
                    <?php do_action( 'sp_cal_inscriptions_td', $event_id, $insc ); ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <?php do_action( 'sp_cal_inscriptions_extra', $event_id, $event ); ?>
        </div>

        <script>
        (function(){
            var btn = document.getElementById('sp-insc-envoyer-btn');
            var nonce = '<?php echo esc_js( $nonce ); ?>';
            var event_id = <?php echo intval($event_id); ?>;

            // Données pour comptage en temps réel
            var totalEleves = <?php echo intval( $wpdb->get_var( "SELECT COUNT(*) FROM $tel_cats WHERE actif = 1 AND (email != '' OR email_parent != '')" ) ); ?>;
            // 1 si les invitations ont déjà été envoyées (mode "non-répondants")
            var dejaEnvoye  = <?php echo intval( $event->inscriptions_envoye ); ?>;

            function updateCibleCount() {
                var cats = Array.from(document.querySelectorAll('.sp-insc-cat-cb:checked')).map(function(c){ return c.value; });
                var ages = Array.from(document.querySelectorAll('.sp-insc-age-cb:checked')).map(function(c){ return c.value; });
                var allCats = document.querySelectorAll('.sp-insc-cat-cb').length;
                var allAges = document.querySelectorAll('.sp-insc-age-cb').length;
                var el = document.getElementById('sp-insc-nb-cibles');
                var elTitle = document.getElementById('sp-cibles-count-title');
                if (!el) return;
                // Shortcut uniquement en mode "premier envoi" (dejaEnvoye=0) pour éviter un appel AJAX inutile.
                // En mode "renvoyer aux non-répondants" on doit toujours interroger le serveur
                // pour obtenir le vrai décompte des élèves en_attente.
                if (!dejaEnvoye && (!allCats || cats.length === allCats) && (!allAges || ages.length === allAges)) {
                    el.textContent = totalEleves;
                    if (elTitle) elTitle.textContent = totalEleves;
                    // Si la liste est ouverte, la recharger
                    if (document.getElementById('sp-cibles-list') && document.getElementById('sp-cibles-list').style.display !== 'none') {
                        loadCiblesList(cats, ages);
                    }
                    return;
                }
                var fd = new FormData();
                fd.append('action', 'sp_inscription_count_cibles');
                fd.append('nonce', '<?php echo esc_js($nonce); ?>');
                fd.append('event_id', '<?php echo intval($event_id); ?>');
                cats.forEach(function(c){ fd.append('categories[]', c); });
                ages.forEach(function(a){ fd.append('age_categories[]', a); });
                fetch(ajaxurl, { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (r.success) {
                            el.textContent = r.data.nb;
                            if (elTitle) elTitle.textContent = r.data.nb;
                            // Si la liste est ouverte, la recharger
                            if (document.getElementById('sp-cibles-list') && document.getElementById('sp-cibles-list').style.display !== 'none') {
                                loadCiblesList(cats, ages);
                            }
                        }
                    })
                    .catch(function(){});
            }

            // ── Panneau public ciblé ─────────────────────────────────────────
            var ciblesListOpen = false;

            function loadCiblesList(cats, ages) {
                var listDiv = document.getElementById('sp-cibles-list');
                if (!listDiv) return;
                listDiv.innerHTML = '<span style="font-size:13px;color:#9ca3af;">Chargement…</span>';
                var fd = new FormData();
                fd.append('action', 'sp_inscription_list_cibles');
                fd.append('nonce', '<?php echo esc_js($nonce); ?>');
                fd.append('event_id', '<?php echo intval($event_id); ?>');
                (cats || []).forEach(function(c){ fd.append('categories[]', c); });
                (ages || []).forEach(function(a){ fd.append('age_categories[]', a); });
                fetch(ajaxurl, { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (!r.success || !r.data.eleves.length) {
                            listDiv.innerHTML = '<span style="font-size:13px;color:#9ca3af;">Aucun élève correspondant.</span>';
                            return;
                        }
                        var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
                            + '<thead><tr style="background:#e2e8f0;">'
                            + '<th style="padding:4px 8px;text-align:left;">Nom</th>'
                            + '<th style="padding:4px 8px;text-align:left;">Discipline</th>'
                            + '<th style="padding:4px 8px;text-align:left;">Tranche d\'âge</th>'
                            + '<th style="padding:4px 8px;text-align:center;" title="A une adresse email">✉️</th>'
                            + '</tr></thead><tbody>';
                        r.data.eleves.forEach(function(el, i){
                            var bg = i % 2 === 0 ? '#fff' : '#f8fafc';
                            html += '<tr style="background:' + bg + ';">'
                                + '<td style="padding:4px 8px;">' + el.prenom + ' <strong>' + el.nom.toUpperCase() + '</strong></td>'
                                + '<td style="padding:4px 8px;">' + (el.categorie_saisie || '—') + '</td>'
                                + '<td style="padding:4px 8px;">' + (el.categorie_age    || '—') + '</td>'
                                + '<td style="padding:4px 8px;text-align:center;">' + (el.has_email ? '✅' : '⚠️') + '</td>'
                                + '</tr>';
                        });
                        html += '</tbody></table>';
                        listDiv.innerHTML = html;
                    })
                    .catch(function(){ listDiv.innerHTML = '<span style="color:#b91c1c;font-size:13px;">Erreur de chargement.</span>'; });
            }

            var toggleBtn = document.getElementById('sp-cibles-toggle');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function(){
                    var listDiv = document.getElementById('sp-cibles-list');
                    if (!listDiv) return;
                    ciblesListOpen = !ciblesListOpen;
                    if (ciblesListOpen) {
                        listDiv.style.display = 'block';
                        toggleBtn.textContent = 'Masquer la liste ▴';
                        var cats = Array.from(document.querySelectorAll('.sp-insc-cat-cb:checked')).map(function(c){ return c.value; });
                        var ages = Array.from(document.querySelectorAll('.sp-insc-age-cb:checked')).map(function(c){ return c.value; });
                        loadCiblesList(cats, ages);
                    } else {
                        listDiv.style.display = 'none';
                        toggleBtn.textContent = 'Afficher la liste ▾';
                    }
                });
            }
            // ── Fin panneau public ciblé ─────────────────────────────────────
            document.querySelectorAll('.sp-insc-cat-cb, .sp-insc-age-cb').forEach(function(cb){
                cb.addEventListener('change', updateCibleCount);
            });
            updateCibleCount();

            var lnkAll  = document.getElementById('sp-insc-cat-all');
            var lnkNone = document.getElementById('sp-insc-cat-none');
            if (lnkAll)  lnkAll.addEventListener('click',  function(e){ e.preventDefault(); document.querySelectorAll('.sp-insc-cat-cb').forEach(function(c){ c.checked=true;  }); updateCibleCount(); });
            if (lnkNone) lnkNone.addEventListener('click', function(e){ e.preventDefault(); document.querySelectorAll('.sp-insc-cat-cb').forEach(function(c){ c.checked=false; }); updateCibleCount(); });
            var lnkAgeAll  = document.getElementById('sp-insc-age-all');
            var lnkAgeNone = document.getElementById('sp-insc-age-none');
            if (lnkAgeAll)  lnkAgeAll.addEventListener('click',  function(e){ e.preventDefault(); document.querySelectorAll('.sp-insc-age-cb').forEach(function(c){ c.checked=true;  }); updateCibleCount(); });
            if (lnkAgeNone) lnkAgeNone.addEventListener('click', function(e){ e.preventDefault(); document.querySelectorAll('.sp-insc-age-cb').forEach(function(c){ c.checked=false; }); updateCibleCount(); });

            if (btn) {
                btn.addEventListener('click', function(){
                    var cats = Array.from(document.querySelectorAll('.sp-insc-cat-cb:checked')).map(function(c){ return c.value; });
                    var ages = Array.from(document.querySelectorAll('.sp-insc-age-cb:checked')).map(function(c){ return c.value; });
                    var nbCibles = parseInt(document.getElementById('sp-insc-nb-cibles').textContent) || 0;
                    if ( nbCibles === 0 ) { alert('Aucun élève ciblé. Vérifiez les filtres.'); return; }
                    if (!confirm('Envoyer les invitations à ' + nbCibles + ' élève(s) ?')) return;
                    btn.disabled = true;
                    var msg = document.getElementById('sp-insc-envoyer-msg');
                    msg.style.color = '#6b7280';
                    msg.textContent = 'Envoi en cours…';
                    var fd = new FormData();
                    fd.append('action',   'sp_inscription_envoyer');
                    fd.append('nonce',    btn.getAttribute('data-nonce'));
                    fd.append('event_id', btn.getAttribute('data-event'));
                    cats.forEach(function(c){ fd.append('categories[]', c); });
                    ages.forEach(function(a){ fd.append('age_categories[]', a); });
                    fetch(ajaxurl, { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        btn.disabled = false;
                        if (r.success) {
                            msg.style.color = '#15803d';
                            msg.textContent = '✅ ' + r.data.sent + ' invitation(s) envoyée(s) sur ' + r.data.total + ' élève(s).'
                                + ( r.data.skipped > 0 ? ' (' + r.data.skipped + ' ignoré(s)).' : '' );
                            setTimeout(function(){ location.reload(); }, 3000);
                        } else {
                            msg.style.color = '#b91c1c';
                            msg.textContent = '❌ ' + (r.data || 'Erreur inconnue');
                        }
                    })
                    .catch(function(){ btn.disabled=false; msg.style.color='#b91c1c'; msg.textContent='❌ Erreur réseau'; });
                });
            }

            // ── Checkboxes ──
            var checkAll = document.getElementById('sp-insc-check-all');
            var bulkSel  = document.getElementById('sp-insc-bulk-action');
            var bulkBtn  = document.getElementById('sp-insc-bulk-apply');
            var bulkMsg  = document.getElementById('sp-insc-bulk-msg');
            var selCount = document.getElementById('sp-insc-sel-count');

            function getChecked() {
                return Array.from(document.querySelectorAll('.sp-insc-cb:checked')).map(function(cb){ return cb.value; });
            }
            function updateBar() {
                var ids = getChecked();
                var n = ids.length;
                bulkBtn.disabled = (n === 0 || !bulkSel.value);
                if (n > 0) { selCount.style.display='inline'; selCount.textContent = n + ' sélectionné(s)'; }
                else        { selCount.style.display='none'; }
            }

            if (checkAll) {
                checkAll.addEventListener('change', function(){
                    document.querySelectorAll('.sp-insc-cb').forEach(function(cb){ cb.checked = checkAll.checked; });
                    updateBar();
                });
            }
            document.querySelectorAll('.sp-insc-cb').forEach(function(cb){
                cb.addEventListener('change', function(){
                    var all = document.querySelectorAll('.sp-insc-cb');
                    var checked = document.querySelectorAll('.sp-insc-cb:checked');
                    if (checkAll) checkAll.checked = (all.length === checked.length);
                    updateBar();
                });
            });
            if (bulkSel) bulkSel.addEventListener('change', updateBar);

            // ── Appliquer l'action groupée ──
            if (bulkBtn) {
                bulkBtn.addEventListener('click', function(){
                    var ids    = getChecked();
                    var action = bulkSel.value;
                    if (!ids.length || !action) return;

                    var labels = {
                        'renvoi':     'Renvoyer les invitations',
                        'inscrit':    'Marquer comme inscrit(e)',
                        'refuse':     'Marquer comme décliné',
                        'en_attente': 'Remettre en attente'
                    };
                    if (!confirm(labels[action] + ' pour ' + ids.length + ' élève(s) ?')) return;

                    bulkBtn.disabled = true;
                    bulkMsg.style.color = '#6b7280';
                    bulkMsg.textContent = 'Traitement en cours…';

                    var fd = new FormData();
                    fd.append('action',   'sp_inscription_bulk');
                    fd.append('nonce',    nonce);
                    fd.append('event_id', event_id);
                    fd.append('bulk_action', action);
                    ids.forEach(function(id){ fd.append('eleve_ids[]', id); });

                    fetch(ajaxurl, { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        bulkBtn.disabled = false;
                        if (r.success) {
                            bulkMsg.style.color = '#15803d';
                            bulkMsg.textContent = '✅ ' + r.data.message;
                            if (action !== 'renvoi') {
                                // Mettre à jour les badges en live
                                var badgeMap = {
                                    'inscrit':    '<span style="background:#dcfce7;color:#15803d;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;">✅ Inscrit(e)</span>',
                                    'refuse':     '<span style="background:#fee2e2;color:#b91c1c;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;">❌ Décliné</span>',
                                    'en_attente': '<span style="background:#fef9c3;color:#92400e;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;">⏳ En attente</span>',
                                };
                                ids.forEach(function(id){
                                    var cell = document.getElementById('sp-statut-' + id);
                                    if (cell && badgeMap[action]) cell.innerHTML = badgeMap[action];
                                });
                            }
                            // Décocher
                            document.querySelectorAll('.sp-insc-cb').forEach(function(cb){ cb.checked = false; });
                            if (checkAll) checkAll.checked = false;
                            updateBar();
                        } else {
                            bulkMsg.style.color = '#b91c1c';
                            bulkMsg.textContent = '❌ ' + (r.data || 'Erreur');
                        }
                    });
                });
            }

        })();
        </script>
        <?php
    }

    /* ── ajax_inscription_envoyer ── */

    public function ajax_inscription_envoyer() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Accès refusé', 403 );

        $event_id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) wp_send_json_error( 'event_id manquant', 400 );

        global $wpdb;
        $te  = $this->db->table_events();
        $tel = $this->db->table_eleves();
        $ti  = $this->db->table_event_inscriptions();

        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $te WHERE id = %d LIMIT 1", $event_id ) );
        if ( ! $event ) wp_send_json_error( 'Événement introuvable', 404 );

        // Catégories sélectionnées dans l'UI (POST categories[] ou vide = tous)
        $cats_post = isset( $_POST['categories'] ) && is_array( $_POST['categories'] )
            ? array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['categories'] ) )
            : array();
        $cats_post = array_filter( $cats_post );

        // Construction de la requête élèves
        $where_parts = array( "actif = 1", "( email != '' OR email_parent != '' )" );
        $where_args  = array();

        if ( ! empty( $cats_post ) ) {
            $placeholders  = implode( ',', array_fill( 0, count( $cats_post ), '%s' ) );
            $where_parts[] = "categorie_saisie IN ($placeholders)";
            $where_args    = array_merge( $where_args, $cats_post );
        }

        // Filtre tranches d'âge (AND avec les disciplines)
        $ages_post = isset( $_POST['age_categories'] ) && is_array( $_POST['age_categories'] )
            ? array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $_POST['age_categories'] ) ) )
            : array();

        if ( ! empty( $ages_post ) ) {
            $placeholders_age = implode( ',', array_fill( 0, count( $ages_post ), '%s' ) );
            $where_parts[]    = "categorie_age IN ($placeholders_age)";
            $where_args       = array_merge( $where_args, array_values( $ages_post ) );
        }

        $where_sql = implode( ' AND ', $where_parts );
        $sql = "SELECT id, nom, prenom, email, email_parent, token, categorie_age, categorie_saisie
                FROM $tel WHERE $where_sql ORDER BY nom, prenom";

        $eleves = empty( $where_args )
            ? $wpdb->get_results( $sql )
            : $wpdb->get_results( $wpdb->prepare( $sql, ...$where_args ) );

        if ( empty( $eleves ) ) {
            wp_send_json_error( 'Aucun élève trouvé pour les catégories sélectionnées.' );
        }

        $deja_envoye = intval( $event->inscriptions_envoye );
        $eleves_a_inviter = array();
        $skipped = 0;

        foreach ( $eleves as $el ) {
            // Vérifier si l'élève a déjà répondu (pas juste en_attente)
            if ( $deja_envoye ) {
                $existing = $wpdb->get_row( $wpdb->prepare(
                    "SELECT statut FROM $ti WHERE event_id = %d AND eleve_id = %d LIMIT 1",
                    $event_id, intval( $el->id )
                ) );
                if ( $existing && $existing->statut !== 'en_attente' ) {
                    $skipped++; continue;
                }
            }
            // Normaliser l'email : préférer email_parent
            $el->email = ! empty( $el->email_parent ) ? $el->email_parent : $el->email;
            if ( ! $el->email || ! is_email( $el->email ) ) { $skipped++; continue; }

            $eleves_a_inviter[] = $el;
        }

        $total = count( $eleves_a_inviter );

        if ( $total === 0 ) {
            wp_send_json_error(
                $deja_envoye
                    ? 'Tous les élèves sélectionnés ont déjà répondu.'
                    : 'Aucun élève éligible (vérifiez les emails et catégories).'
            );
        }

        // Utiliser send_invitation_inscription() (email HTML avec boutons ✅/❌)
        $sent = 0;
        // Charger class-notifications si pas encore disponible
        if ( ! class_exists( 'SpCalPro_Notifications' ) ) {
            $notif_file = plugin_dir_path( __FILE__ ) . 'class-notifications.php';
            if ( file_exists( $notif_file ) ) require_once $notif_file;
        }
        if ( class_exists( 'SpCalPro_Notifications' ) ) {
            $notif = new SpCalPro_Notifications( $this->db );
            $sent  = $notif->send_invitation_inscription( $event, $eleves_a_inviter );
        } else {
            // Fallback : email texte simple si la classe notifications n'est pas chargée
            $club   = get_option( 'blogname', 'Club' );
            $dt_fmt = $event->date ? date_create( $event->date )->format('d/m/Y') : '';
            foreach ( $eleves_a_inviter as $el ) {
                $subject = '[' . $club . '] Inscription - ' . $event->titre;
                $body    = "Bonjour " . $el->prenom . ",

"
                         . $club . " vous invite a vous inscrire a " . $event->titre
                         . ( $dt_fmt ? " du " . $dt_fmt : '' ) . ".

-- 
" . $club;
                if ( wp_mail( sanitize_email( $el->email ), $subject, $body,
                    array( 'Content-Type: text/plain; charset=UTF-8' ) ) ) $sent++;
            }
        }

        // Sauvegarder les catégories ciblées sur l'event
        $update_data = array( 'inscriptions_envoye' => 1 );
        if ( ! empty( $cats_post ) ) {
            $update_data['inscriptions_categories'] = implode( ',', $cats_post );
        }
        if ( ! empty( $ages_post ) ) {
            $update_data['inscriptions_age_categories'] = implode( ',', array_values( $ages_post ) );
        }
        $wpdb->update( $te, $update_data, array( 'id' => $event_id ) );

        wp_send_json_success( array(
            'sent'    => $sent,
            'total'   => $total,
            'skipped' => $skipped,
        ) );
    }

    /* ── ajax_inscription_bulk ── */

    public function ajax_inscription_bulk() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Accès refusé', 403 );

        $event_id   = intval( $_POST['event_id']   ?? 0 );
        $action_key = sanitize_text_field( wp_unslash( $_POST['bulk_action'] ?? '' ) );
        $eleve_ids  = array_map( 'intval', (array) ( $_POST['eleve_ids'] ?? array() ) );

        if ( ! $event_id || ! $action_key || empty( $eleve_ids ) ) {
            wp_send_json_error( 'Paramètres manquants', 400 );
        }

        global $wpdb;
        $te  = $this->db->table_events();
        $tel = $this->db->table_eleves();
        $ti  = $this->db->table_event_inscriptions();

        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $te WHERE id = %d LIMIT 1", $event_id ) );
        if ( ! $event ) wp_send_json_error( 'Événement introuvable', 404 );

        $statuts_valides = array( 'inscrit', 'refuse', 'en_attente' );
        $done = 0;

        foreach ( $eleve_ids as $eid ) {
            if ( ! $eid ) continue;

            $el = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, nom, prenom, email, email_parent, token FROM $tel WHERE id = %d AND actif = 1 LIMIT 1", $eid
            ) );
            if ( ! $el ) continue;

            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, statut, nb_changements FROM $ti WHERE event_id = %d AND eleve_id = %d LIMIT 1",
                $event_id, $eid
            ) );

            if ( $action_key === 'renvoi' ) {
                // Renvoyer l'invitation — email HTML avec boutons via SpCalPro_Notifications
                $el->email = ! empty( $el->email_parent ) ? $el->email_parent : $el->email;
                if ( ! $el->email || ! is_email( $el->email ) ) continue;
                if ( ! class_exists( 'SpCalPro_Notifications' ) ) {
                    $notif_file = plugin_dir_path( __FILE__ ) . 'class-notifications.php';
                    if ( file_exists( $notif_file ) ) require_once $notif_file;
                }
                if ( class_exists( 'SpCalPro_Notifications' ) ) {
                    $notif = new SpCalPro_Notifications( $this->db );
                    if ( $notif->send_invitation_inscription( $event, array( $el ) ) ) $done++;
                }

            } elseif ( in_array( $action_key, $statuts_valides, true ) ) {
                // Changement de statut
                if ( $existing ) {
                    $nb = intval( $existing->nb_changements ?? 0 );
                    if ( $existing->statut !== $action_key ) $nb++;
                    $wpdb->update( $ti,
                        array( 'statut' => $action_key, 'nb_changements' => $nb, 'date_reponse' => current_time('mysql') ),
                        array( 'id' => intval( $existing->id ) )
                    );
                } else {
                    $wpdb->insert( $ti, array(
                        'event_id'       => $event_id,
                        'eleve_id'       => $eid,
                        'statut'         => $action_key,
                        'date_reponse'   => current_time('mysql'),
                        'nb_changements' => 0,
                    ) );
                }
                $done++;
            }
        }

        wp_send_json_success( array(
            'done'    => $done,
            'action'  => $action_key,
            'message' => $done . ' élève(s) traité(s).',
        ) );
    }

    /* ── ajax_get_cats_saisie ── */

    public function ajax_get_cats_saisie() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Accès refusé', 403 );
        global $wpdb;
        $tel       = $this->db->table_eleves();
        $cats      = $wpdb->get_col( "SELECT DISTINCT categorie_saisie FROM $tel WHERE categorie_saisie != '' AND actif = 1 ORDER BY categorie_saisie" );
        $cats_age  = $wpdb->get_col( "SELECT DISTINCT categorie_age  FROM $tel WHERE categorie_age  != '' AND actif = 1 ORDER BY categorie_age" );
        wp_send_json_success( array(
            'discipline' => array_values( $cats ),
            'age'        => array_values( $cats_age ),
        ) );
    }

    /* ── ajax_inscription_count_cibles ── */

    public function ajax_inscription_count_cibles() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Accès refusé', 403 );

        global $wpdb;
        $tel       = $this->db->table_eleves();
        $ti        = $this->db->table_event_inscriptions();
        $event_id  = intval( $_POST['event_id'] ?? 0 );
        $cats_post = array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['categories']    ?? array() ) ) ) );
        $ages_post = array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['age_categories'] ?? array() ) ) ) );

        // Détecter si l'event est en mode "renvoyer aux non-répondants"
        $deja_envoye = 0;
        if ( $event_id ) {
            $te          = $this->db->table_events();
            $deja_envoye = intval( $wpdb->get_var( $wpdb->prepare( "SELECT inscriptions_envoye FROM $te WHERE id = %d LIMIT 1", $event_id ) ) );
        }

        $where_parts = array( "e.actif = 1", "( e.email != '' OR e.email_parent != '' )" );
        $where_args  = array();

        if ( ! empty( $cats_post ) ) {
            $ph            = implode( ',', array_fill( 0, count( $cats_post ), '%s' ) );
            $where_parts[] = "e.categorie_saisie IN ($ph)";
            $where_args    = array_merge( $where_args, array_values( $cats_post ) );
        }
        if ( ! empty( $ages_post ) ) {
            $ph            = implode( ',', array_fill( 0, count( $ages_post ), '%s' ) );
            $where_parts[] = "e.categorie_age IN ($ph)";
            $where_args    = array_merge( $where_args, array_values( $ages_post ) );
        }

        if ( $deja_envoye && $event_id ) {
            // Mode non-répondants : élèves en_attente OU sans ligne pour cet event
            $where_parts[] = "( i.statut IS NULL OR i.statut = 'en_attente' )";
            $where_args[]  = $event_id;
            $where  = implode( ' AND ', $where_parts );
            $sql    = "SELECT COUNT(*) FROM $tel e
                       LEFT JOIN $ti i ON i.eleve_id = e.id AND i.event_id = %d
                       WHERE $where";
            // %d pour event_id est ajouté en dernier dans where_args ci-dessus,
            // mais il doit figurer en position du LEFT JOIN : on le met en tête.
            array_pop( $where_args );
            $nb = intval( $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $event_id ), $where_args ) ) ) );
        } else {
            $where = implode( ' AND ', $where_parts );
            $sql   = "SELECT COUNT(*) FROM $tel e WHERE $where";
            $nb    = empty( $where_args )
                ? intval( $wpdb->get_var( $sql ) )
                : intval( $wpdb->get_var( $wpdb->prepare( $sql, ...$where_args ) ) );
        }

        wp_send_json_success( array( 'nb' => $nb ) );
    }

    /* ── ajax_inscription_list_cibles ── */

    public function ajax_inscription_list_cibles() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Accès refusé', 403 );

        global $wpdb;
        $tel       = $this->db->table_eleves();
        $cats_post = array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['categories']     ?? array() ) ) ) );
        $ages_post = array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['age_categories']  ?? array() ) ) ) );

        $where_parts = array( "actif = 1", "( email != '' OR email_parent != '' )" );
        $where_args  = array();

        if ( ! empty( $cats_post ) ) {
            $ph            = implode( ',', array_fill( 0, count( $cats_post ), '%s' ) );
            $where_parts[] = "categorie_saisie IN ($ph)";
            $where_args    = array_merge( $where_args, array_values( $cats_post ) );
        }
        if ( ! empty( $ages_post ) ) {
            $ph            = implode( ',', array_fill( 0, count( $ages_post ), '%s' ) );
            $where_parts[] = "categorie_age IN ($ph)";
            $where_args    = array_merge( $where_args, array_values( $ages_post ) );
        }

        $where = implode( ' AND ', $where_parts );
        $sql   = "SELECT id, nom, prenom, categorie_saisie, categorie_age, email, email_parent
                  FROM $tel WHERE $where ORDER BY nom, prenom LIMIT 500";
        $rows  = empty( $where_args )
            ? $wpdb->get_results( $sql )
            : $wpdb->get_results( $wpdb->prepare( $sql, ...$where_args ) );

        $out = array();
        foreach ( $rows as $r ) {
            $out[] = array(
                'nom'              => wp_unslash( $r->nom ),
                'prenom'           => wp_unslash( $r->prenom ),
                'categorie_saisie' => wp_unslash( $r->categorie_saisie ),
                'categorie_age'    => wp_unslash( $r->categorie_age ),
                'has_email'        => ( ! empty( $r->email ) || ! empty( $r->email_parent ) ) ? 1 : 0,
            );
        }

        wp_send_json_success( array( 'eleves' => $out, 'nb' => count( $out ) ) );
    }

    /* ── export_inscriptions_csv ── */

    private function export_inscriptions_csv() {
        global $wpdb;

        $event_id = intval( $_GET['event_id'] ?? 0 );
        $profil   = sanitize_text_field( $_GET['profil']  ?? 'complet' );
        $statuts  = isset( $_GET['statuts'] ) && is_array( $_GET['statuts'] )
                    ? array_map( 'sanitize_text_field', $_GET['statuts'] )
                    : array( 'inscrit' );

        $statuts_ok = array( 'inscrit', 'refuse', 'en_attente' );
        $statuts    = array_filter( $statuts, function( $s ) use ( $statuts_ok ) {
            return in_array( $s, $statuts_ok, true );
        });
        if ( empty( $statuts ) ) $statuts = array( 'inscrit' );

        if ( ! $event_id ) wp_die( 'event_id manquant.' );

        $te    = $this->db->table_events();
        $ti    = $this->db->table_event_inscriptions();
        $tel   = $this->db->table_eleves();

        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $te WHERE id=%d", $event_id ) );
        if ( ! $event ) wp_die( 'Événement introuvable.' );

        // ── Requête croisée inscriptions + fiche élève ───────────────────
        $ph   = implode( ',', array_fill( 0, count($statuts), '%s' ) );
        $args = array_merge( array( $event_id ), $statuts );
        $sql  = $wpdb->prepare(
            "SELECT i.statut, i.date_reponse, i.commentaire,
                    e.nom, e.prenom, e.date_naissance, e.annee_naissance,
                    e.categorie_age, e.categorie_saisie, e.grade, e.licence,
                    e.email, e.email_parent, e.telephone,
                    e.representant_nom, e.representant_prenom, e.representant_telephone,
                    e.urgence_nom, e.urgence_prenom, e.urgence_telephone, e.urgence_email,
                    e.taille_cm, e.poids_kg, e.pointure,
                    e.taille_tshirt, e.taille_pantalon, e.droit_image,
                    e.adresse, e.nationalite, e.lieu_naissance
             FROM $ti i
             INNER JOIN $tel e ON e.id = i.eleve_id
             WHERE i.event_id = %d AND i.statut IN ($ph)
             ORDER BY e.nom, e.prenom",
            ...$args
        );
        $rows = $wpdb->get_results( $sql );

        // ── Définition des colonnes selon profil ─────────────────────────
        $cols_feuille_appel = array(
            'nom'           => 'Nom',
            'prenom'        => 'Prénom',
            'categorie_age' => 'Catégorie âge',
            'grade'         => 'Grade',
            'statut'        => 'Statut',
            'urgence_nom'   => 'Contact urgence',
            'urgence_telephone' => 'Tél. urgence',
            'email_parent'  => 'Email famille',
        );
        $cols_competition = array(
            'nom'            => 'Nom',
            'prenom'         => 'Prénom',
            'date_naissance' => 'Date naissance',
            'annee_naissance'=> 'Année naissance',
            'licence'        => 'Licence',
            'grade'          => 'Grade',
            'categorie_age'  => 'Catégorie âge',
            'poids_kg'       => 'Poids (kg)',
            'taille_cm'      => 'Taille (cm)',
            'email'          => 'Email',
            'statut'         => 'Statut',
        );
        $cols_complet = array(
            'nom'                    => 'Nom',
            'prenom'                 => 'Prénom',
            'date_naissance'         => 'Date naissance',
            'annee_naissance'        => 'Année',
            'categorie_age'          => 'Catégorie âge',
            'categorie_saisie'       => 'Catégorie saisie',
            'grade'                  => 'Grade',
            'licence'                => 'Licence',
            'email'                  => 'Email',
            'email_parent'           => 'Email famille',
            'telephone'              => 'Téléphone',
            'representant_nom'       => 'Représentant nom',
            'representant_prenom'    => 'Représentant prénom',
            'representant_telephone' => 'Représentant tél.',
            'urgence_nom'            => 'Urgence nom',
            'urgence_prenom'         => 'Urgence prénom',
            'urgence_telephone'      => 'Urgence tél.',
            'urgence_email'          => 'Urgence email',
            'taille_cm'              => 'Taille (cm)',
            'poids_kg'               => 'Poids (kg)',
            'pointure'               => 'Pointure',
            'taille_tshirt'          => 'T-shirt',
            'taille_pantalon'        => 'Pantalon',
            'droit_image'            => 'Droit image',
            'adresse'                => 'Adresse',
            'nationalite'            => 'Nationalité',
            'lieu_naissance'         => 'Lieu naissance',
            'statut'                 => 'Statut inscription',
            'date_reponse'           => 'Date réponse',
            'commentaire'            => 'Commentaire',
        );

        $cols = $profil === 'feuille_appel' ? $cols_feuille_appel
              : ( $profil === 'competition' ? $cols_competition : $cols_complet );

        // ── Nom du fichier ───────────────────────────────────────────────
        $titre_safe = sanitize_file_name( wp_unslash( $event->titre ) );
        $date_safe  = $event->date ? str_replace( '-', '', $event->date ) : date('Ymd');
        $profil_safe = array( 'feuille_appel'=>'appel', 'competition'=>'competition', 'complet'=>'complet' )[$profil] ?? 'export';
        $filename   = 'inscriptions_' . $titre_safe . '_' . $date_safe . '_' . $profil_safe . '.csv';

        // ── Envoi ────────────────────────────────────────────────────────
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );

        // BOM UTF-8 pour Excel
        echo "ï»¿";

        $out = fopen( 'php://output', 'w' );

        // En-têtes
        fputcsv( $out, array_values( $cols ), ';' );

        $statut_labels = array(
            'inscrit'    => 'Inscrit(e)',
            'refuse'     => 'Décliné',
            'en_attente' => 'En attente',
        );

        foreach ( $rows as $row ) {
            $line = array();
            foreach ( array_keys( $cols ) as $key ) {
                $val = '';
                if ( $key === 'statut' ) {
                    $val = $statut_labels[ $row->statut ] ?? $row->statut;
                } elseif ( $key === 'date_reponse' ) {
                    $val = $row->date_reponse ? date_create($row->date_reponse)->format('d/m/Y H:i') : '';
                } elseif ( $key === 'droit_image' ) {
                    $val = intval( $row->droit_image ?? 0 ) ? 'Oui' : 'Non';
                } elseif ( $key === 'date_naissance' && isset( $row->date_naissance ) && isset( $row->annee_naissance ) && $profil !== 'complet' ) {
                    $val = $row->date_naissance . '/' . $row->annee_naissance;
                } else {
                    $val = wp_unslash( $row->$key ?? '' );
                }
                $line[] = $val;
            }
            fputcsv( $out, $line, ';' );
        }
        fclose( $out );
    }

}

endif; // SP_Cal_Inscriptions

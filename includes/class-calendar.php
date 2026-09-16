<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_Calendar' ) ) :

class SpCalPro_Calendar {

    private $db;

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;
        add_shortcode( 'sp_cal_calendar',     array( $this, 'render' ) );
        add_shortcode( 'sp_cal_evenements',   array( $this, 'render_evenements' ) );
        add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_front' ) );
        // Admin : uniquement sur les pages sp-cal-* pour ne pas polluer les autres pages admin
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
    }

    /**
     * Scripts/styles frontend.
     * Bloqués pendant les requêtes REST (sauvegarde Gutenberg, API WP)
     * pour éviter que wp_localize_script n'injecte du HTML dans la réponse JSON.
     */
    public function enqueue_front() {
        if ( ( defined('REST_REQUEST') && REST_REQUEST ) ||
             ( defined('DOING_AJAX')   && DOING_AJAX   ) ) {
            return;
        }
        $this->do_enqueue();
    }

    /**
     * Scripts/styles admin : uniquement sur les pages du plugin.
     */
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
        wp_enqueue_script(
            'sp-cal-front',
            SP_CAL_PRO_URL . 'assets/js/calendar.js',
            array( 'jquery' ),
            SP_CAL_PRO_VERSION,
            true
        );
        wp_localize_script( 'sp-cal-front', 'SpCal', array(
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'sp_cal_admin_nonce' ),
            'isAdmin'     => current_user_can( 'manage_options' ) ? '1' : '0',
            'catColors'   => get_option( 'sp_cal_cat_colors', '{}' ),
            'catsEvents'  => wp_json_encode( $this->db->get_categories_events() ),
            'palmaresUrl' => class_exists('SpCalPro_PalmaresFront')
                             ? SpCalPro_PalmaresFront::get_page_url()
                             : get_option( 'sp_cal_palmares_url', '' ),
            'weColor'     => get_option( 'sp_cal_we_color',  '#f3f4f6' ),
            'vacColor'    => get_option( 'sp_cal_vac_color', '#fef9c3' ),
            'vacances'    => get_option( 'sp_cal_vacances_zoneC', '[]' ),
        ) );
    }

    public function render( $atts ) {
        // S'assurer que les assets sont chargés même si enqueue_front n'a pas tourné
        if ( ! wp_script_is( 'sp-cal-front', 'enqueued' ) ) {
            $this->do_enqueue();
        }
        ob_start();
        include SP_CAL_PRO_PATH . 'templates/calendar.php';
        return ob_get_clean();
    }
    /* ══════════════════════════════════════════════════════════
       SHORTCODE [sp_cal_evenements]
       Liste publique des événements à venir (hors cours récurrents).
       Paramètres :
         nb        = nombre d'événements (défaut 10, 0 = tous)
         type      = filtrer par type : evenement|competition|examen|stage
         categorie = filtrer par catégorie
         passes    = 1 pour inclure aussi les événements passés récents (défaut 0)
    ══════════════════════════════════════════════════════════ */

    public function render_evenements( $atts ) {
        global $wpdb;

        $atts = shortcode_atts( array(
            'nb'        => 10,
            'type'      => '',
            'categorie' => '',
            'passes'    => 0,
        ), $atts, 'sp_cal_evenements' );

        $nb        = intval( $atts['nb'] );
        $type_flt  = sanitize_text_field( $atts['type'] );
        $cat_flt   = sanitize_text_field( $atts['categorie'] );
        $avec_passes = intval( $atts['passes'] );

        $te    = $this->db->table_events();
        $today = date( 'Y-m-d' );
        // Si passes=1 : afficher aussi les événements des 30 derniers jours
        $depuis = $avec_passes ? date( 'Y-m-d', strtotime( '-30 days' ) ) : $today;

        // Types affichés : uniquement événements ponctuels hors cours
        $types_autorises = array( 'evenement', 'competition', 'examen', 'stage' );
        $limit_sql = $nb > 0 ? "LIMIT " . intval( $nb ) : "";

        // Construction directe sans call_user_func_array
        $extra = '';
        if ( $type_flt && in_array( $type_flt, $types_autorises, true ) ) {
            $extra .= $wpdb->prepare( " AND e.type = %s", $type_flt );
        }
        if ( $cat_flt ) {
            $extra .= $wpdb->prepare( " AND e.categorie = %s", $cat_flt );
        }

        $sql = $wpdb->prepare(
            "SELECT e.* FROM $te e
             WHERE e.date >= %s
               AND e.type IN ('evenement','competition','examen','stage')
             " . $extra . "
             ORDER BY e.date ASC, e.heure_debut ASC
             $limit_sql",
            $depuis
        );
        $events = $wpdb->get_results( $sql );

        // Récupérer le token de l'URL si présent (fiche membre)
        $token     = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
        $eleve_id  = 0;
        if ( $token ) {
            $tel = $this->db->table_eleves();
            $el  = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM $tel WHERE token=%s AND actif=1 LIMIT 1", $token
            ) );
            if ( $el ) $eleve_id = intval( $el->id );
        }

        $type_labels = array(
            'evenement'   => array( 'label' => 'Événement',   'color' => '#3B82F6', 'icon' => '📅' ),
            'competition' => array( 'label' => 'Compétition', 'color' => '#b8860b', 'icon' => '🏆' ),
            'examen'      => array( 'label' => 'Examen',      'color' => '#7c3aed', 'icon' => '🎓' ),
            'stage'       => array( 'label' => 'Stage',       'color' => '#0891b2', 'icon' => '⭐' ),
        );

        $mois_fr = array(
            '01'=>'janvier','02'=>'février','03'=>'mars','04'=>'avril',
            '05'=>'mai','06'=>'juin','07'=>'juillet','08'=>'août',
            '09'=>'septembre','10'=>'octobre','11'=>'novembre','12'=>'décembre',
        );
        $jours_fr = array( 'Sunday'=>'dimanche','Monday'=>'lundi','Tuesday'=>'mardi',
            'Wednesday'=>'mercredi','Thursday'=>'jeudi','Friday'=>'vendredi','Saturday'=>'samedi' );

        ob_start();
        ?>
        <div class="spcal-evts-wrap">

        <?php if ( empty( $events ) ) : ?>
            <p class="spcal-evts-empty">Aucun événement à venir pour le moment.</p>
        <?php else : ?>

        <?php
        $current_month = '';
        foreach ( $events as $ev ) :
            $date_obj  = $ev->date ? date_create( $ev->date ) : null;
            $ev_month  = $date_obj ? $date_obj->format('Y-m') : '';
            $ev_month_label = $date_obj
                ? ucfirst( $jours_fr[ $date_obj->format('l') ] ?? $date_obj->format('l') ) . ' ' .
                  intval( $date_obj->format('d') ) . ' ' .
                  $mois_fr[ $date_obj->format('m') ] . ' ' . $date_obj->format('Y')
                : '';
            $month_label = $date_obj
                ? ucfirst( $mois_fr[ $date_obj->format('m') ] ) . ' ' . $date_obj->format('Y')
                : '';

            // Séparateur mensuel
            if ( $ev_month !== $current_month ) :
                $current_month = $ev_month;
                ?>
                <div class="spcal-evts-month"><?php echo esc_html( $month_label ); ?></div>
            <?php endif;

            $cfg      = $type_labels[ $ev->type ] ?? $type_labels['evenement'];
            $is_past  = $ev->date && $ev->date < $today;
            $heure    = '';
            if ( $ev->heure_debut ) {
                $heure = substr( $ev->heure_debut, 0, 5 );
                if ( $ev->heure_fin ) $heure .= ' – ' . substr( $ev->heure_fin, 0, 5 );
            }

            // Lien document ou palmarès
            $doc_url   = '';
            $doc_label = '';
            if ( $ev->document_url ) {
                if ( $is_past && $ev->type === 'competition' ) {
                    $palmares_base = get_option( 'sp_cal_palmares_url', '' );
                    if ( $palmares_base ) {
                        $doc_url   = rtrim( $palmares_base, '#' ) . '#competition-' . intval( $ev->id );
                        $doc_label = '🏆 Voir les résultats';
                    } else {
                        $doc_url   = $ev->document_url;
                        $doc_label = '📄 ' . ( $ev->document_nom ?: 'Document' );
                    }
                } else {
                    $doc_url   = $ev->document_url;
                    $doc_label = '📄 ' . ( $ev->document_nom ?: 'Document' );
                }
            }

            // Inscriptions
            $insc_actives  = intval( $ev->inscriptions_actives ?? 0 );
            $insc_envoye   = intval( $ev->inscriptions_envoye  ?? 0 );
            $insc_deadline = $ev->inscriptions_deadline ?? null;
            $show_insc     = $insc_actives && ! $is_past;
        ?>

        <div class="spcal-evt-card<?php echo $is_past ? ' spcal-evt-past' : ''; ?>">
            <!-- Bande couleur type -->
            <div class="spcal-evt-stripe" style="background:<?php echo esc_attr( $cfg['color'] ); ?>;"></div>

            <!-- Bloc date façon calendrier -->
            <div class="spcal-evt-date-block">
                <div class="spcal-evt-date-day"><?php echo $date_obj ? intval($date_obj->format('d')) : ''; ?></div>
                <div class="spcal-evt-date-month"><?php echo $date_obj ? ($mois_fr[$date_obj->format('m')] ?? '') : ''; ?></div>
            </div>

            <div class="spcal-evt-body">
                <!-- En-tête : badges + heure -->
                <div class="spcal-evt-head">
                    <div class="spcal-evt-meta">
                        <span class="spcal-evt-badge" style="background:<?php echo esc_attr( $cfg['color'] ); ?>;color:#fff;">
                            <?php echo $cfg['icon']; ?> <?php echo esc_html( $cfg['label'] ); ?>
                        </span>
                        <?php if ( $ev->categorie && $ev->categorie !== 'Général' ) : ?>
                        <span class="spcal-evt-cat"><?php echo esc_html( $ev->categorie ); ?></span>
                        <?php endif; ?>
                        <?php if ( $is_past ) : ?>
                        <span class="spcal-evt-past-badge">Passé</span>
                        <?php endif; ?>
                    </div>
                    <?php if ( $heure ) : ?>
                    <span class="spcal-evt-heure-badge">🕐 <?php echo esc_html( $heure ); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Titre -->
                <div class="spcal-evt-titre"><?php echo esc_html( wp_unslash( $ev->titre ) ); ?></div>

                <!-- Description -->
                <?php if ( $ev->description ) : ?>
                <div class="spcal-evt-desc"><?php echo nl2br( esc_html( wp_unslash( $ev->description ) ) ); ?></div>
                <?php endif; ?>
                <!-- Message inscription -->
                <?php if ( ! empty( $ev->inscriptions_message ) ) : ?>
                <div class="spcal-evt-insc-note">💬 <?php echo nl2br( esc_html( wp_unslash( $ev->inscriptions_message ) ) ); ?></div>
                <?php endif; ?>

                <!-- Actions : document + inscription -->
                <?php if ( $doc_url || $show_insc ) : ?>
                <div class="spcal-evt-actions">
                    <?php if ( $doc_url ) : ?>
                    <a href="<?php echo esc_url( $doc_url ); ?>"
                       class="spcal-evt-btn spcal-evt-btn-doc"
                       target="<?php echo ( ! $is_past ) ? '_blank' : '_self'; ?>"
                       rel="noopener">
                        <?php echo esc_html( $doc_label ); ?>
                    </a>
                    <?php endif; ?>

                    <?php if ( $show_insc ) : ?>
                        <?php if ( $eleve_id ) :
                            // Élève connecté — vérifier son statut
                            $ti     = method_exists( $this->db, 'table_event_inscriptions' ) ? $this->db->table_event_inscriptions() : '';
                            $statut = '';
                            if ( $ti ) {
                                $row = $wpdb->get_row( $wpdb->prepare(
                                    "SELECT statut FROM $ti WHERE event_id=%d AND eleve_id=%d",
                                    intval($ev->id), $eleve_id
                                ) );
                                $statut = $row ? $row->statut : '';
                            }
                            if ( $statut === 'inscrit' ) : ?>
                                <span class="spcal-evt-insc-ok">✅ Vous êtes inscrit(e)</span>
                            <?php elseif ( $statut === 'refuse' ) : ?>
                                <span class="spcal-evt-insc-ko">❌ Inscription déclinée</span>
                            <?php else :
                                // En attente ou pas encore répondu
                                $dl_txt = '';
                                if ( $insc_deadline ) {
                                    $dl_obj = date_create( $insc_deadline );
                                    if ( $dl_obj ) $dl_txt = 'avant le ' . $dl_obj->format('d/m/Y');
                                }
                            ?>
                                <div class="spcal-evt-insc-wrap"
                                     data-event="<?php echo intval($ev->id); ?>"
                                     data-token="<?php echo esc_attr( $token ); ?>">
                                    <button class="spcal-evt-btn spcal-evt-btn-oui spcal-insc-rep" data-rep="oui">✅ Je participe</button>
                                    <button class="spcal-evt-btn spcal-evt-btn-non spcal-insc-rep" data-rep="non">❌ Je ne peux pas</button>
                                    <?php if ( $dl_txt ) : ?>
                                    <span class="spcal-evt-insc-dl">⏰ <?php echo esc_html( $dl_txt ); ?></span>
                                    <?php endif; ?>
                                    <span class="spcal-evt-insc-msg" style="display:none;"></span>
                                </div>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="spcal-evt-insc-public">🔒 Événement réservé aux adhérents — <a href="<?php echo esc_url( home_url('/contact/') ); ?>">Contacter le club</a> si vous êtes intéressé(e).</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <style>
        /* ── Wrapper — pleine largeur, cohérent avec le calendrier ── */
        .spcal-evts-wrap {
            width:100%;
            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
            background:#f1f5f9;
            padding:0;
        }

        /* ── Message vide ── */
        .spcal-evts-empty {
            text-align:center; padding:48px 0;
            color:#6b7280; font-size:15px; font-style:italic;
        }

        /* ── Séparateur mensuel — style "header semaine" du calendrier ── */
        .spcal-evts-month {
            display:flex; align-items:center; gap:10px;
            background:#1e3a5f;
            color:#fff;
            font-size:14px; font-weight:700; letter-spacing:.5px;
            padding:12px 20px;
            border-radius:10px 10px 0 0;
            margin-top:24px;
        }
        .spcal-evts-month:first-child { margin-top:0; }
        .spcal-evts-month::before { content:'📅'; font-size:16px; }

        /* ── Carte événement ── */
        .spcal-evt-card {
            display:flex;
            background:#fff;
            border-radius:0;
            overflow:hidden;
            border-bottom:1px solid #e2e8f0;
            transition:background .15s;
        }
        .spcal-evt-card:last-of-type,
        .spcal-evts-month + .spcal-evt-card:last-child {
            border-radius:0 0 10px 10px;
        }
        /* Grouper les cartes sous leur header mensuel */
        .spcal-evts-group { border-radius:0 0 10px 10px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); margin-bottom:8px; }

        .spcal-evt-card:hover { background:#f8fafc; }
        .spcal-evt-past { opacity:.6; }

        /* Bande couleur latérale */
        .spcal-evt-stripe { width:6px; flex-shrink:0; }

        /* Bloc date — style pastille calendrier */
        .spcal-evt-date-block {
            flex-shrink:0;
            width:64px;
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            background:#1e3a5f;
            color:#fff;
            padding:12px 0;
        }
        .spcal-evt-date-day   { font-size:26px; font-weight:800; line-height:1; }
        .spcal-evt-date-month { font-size:10px; text-transform:uppercase; letter-spacing:1px; opacity:.75; margin-top:3px; }

        /* Corps de la carte */
        .spcal-evt-body { flex:1; padding:14px 18px; min-width:0; }

        .spcal-evt-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; flex-wrap:wrap; gap:6px; }
        .spcal-evt-meta { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }

        /* Badge type — style "pill" du calendrier */
        .spcal-evt-badge {
            display:inline-flex; align-items:center; gap:4px;
            padding:3px 10px; border-radius:20px;
            font-size:11px; font-weight:700; white-space:nowrap;
            text-transform:uppercase; letter-spacing:.4px;
        }
        .spcal-evt-cat {
            display:inline-block; padding:3px 10px; border-radius:20px;
            font-size:11px; font-weight:500;
            background:#e2e8f0; color:#475569;
        }
        .spcal-evt-past-badge {
            display:inline-block; padding:3px 10px; border-radius:20px;
            font-size:11px; background:#f1f5f9; color:#94a3b8;
        }

        /* Heure — à droite du head */
        .spcal-evt-heure-badge {
            font-size:12px; font-weight:600; color:#1e3a5f;
            background:#e0e7ff; padding:3px 10px; border-radius:20px;
            white-space:nowrap;
        }

        /* Titre */
        .spcal-evt-titre {
            font-size:16px; font-weight:700; color:#0f172a;
            margin-bottom:4px; line-height:1.3;
        }

        /* Description */
        .spcal-evt-desc {
            font-size:13px; color:#475569; line-height:1.6;
            margin-bottom:10px; margin-top:4px;
        }

        /* Actions */
        .spcal-evt-actions {
            display:flex; flex-wrap:wrap; gap:8px; align-items:center;
            margin-top:10px; padding-top:10px;
            border-top:1px solid #f1f5f9;
        }
        .spcal-evt-btn {
            display:inline-flex; align-items:center; gap:5px;
            padding:7px 16px; border-radius:6px;
            font-size:13px; font-weight:600;
            border:none; cursor:pointer; text-decoration:none;
            transition:opacity .15s, transform .1s;
        }
        .spcal-evt-btn:hover { opacity:.88; transform:translateY(-1px); text-decoration:none; }
        .spcal-evt-btn-doc { background:#1e3a5f; color:#fff; }
        .spcal-evt-btn-oui { background:#16a34a; color:#fff; }
        .spcal-evt-btn-non { background:#fff; color:#dc2626; border:2px solid #dc2626; }

        .spcal-evt-insc-ok  { font-size:13px; font-weight:700; color:#15803d; background:#dcfce7; padding:5px 12px; border-radius:6px; }
        .spcal-evt-insc-ko  { font-size:13px; font-weight:700; color:#b91c1c; background:#fee2e2; padding:5px 12px; border-radius:6px; }
        .spcal-evt-insc-dl  { font-size:12px; color:#f59e0b; font-weight:500; }
        .spcal-evt-insc-msg { font-size:13px; }
        .spcal-evt-insc-wrap { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .spcal-evt-insc-public {
            font-size:12px; color:#64748b;
            background:#f8fafc; border:1px solid #e2e8f0;
            padding:6px 12px; border-radius:6px;
        }
        .spcal-evt-insc-public a { color:#1e3a5f; font-weight:600; }
        .spcal-evt-insc-note { font-size:13px; color:#374151; background:#fff8e1; border-left:3px solid #f59e0b; padding:8px 12px; border-radius:4px; margin-bottom:8px; line-height:1.5; }

        /* ── Responsive mobile ── */
        @media (max-width:540px) {
            .spcal-evt-date-block { width:52px; }
            .spcal-evt-date-day   { font-size:20px; }
            .spcal-evt-titre      { font-size:14px; }
            .spcal-evts-month     { font-size:13px; padding:10px 14px; }
        }
        </style>

        <script>
        (function(){
            document.querySelectorAll('.spcal-insc-rep').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var wrap  = btn.closest('.spcal-evt-insc-wrap');
                    var evId  = wrap.getAttribute('data-event');
                    var token = wrap.getAttribute('data-token');
                    var rep   = btn.getAttribute('data-rep');
                    var msg   = wrap.querySelector('.spcal-evt-insc-msg');
                    wrap.querySelectorAll('.spcal-insc-rep').forEach(function(b){ b.disabled = true; });
                    if (msg) { msg.style.display='inline'; msg.style.color='#6b7280'; msg.textContent='Enregistrement…'; }
                    var fd = new FormData();
                    fd.append('action',   'sp_inscription_repondre');
                    fd.append('token',    token);
                    fd.append('event_id', evId);
                    fd.append('reponse',  rep);
                    fetch((typeof SpCal !== 'undefined' ? SpCal.ajaxurl : '/wp-admin/admin-ajax.php'), { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (r.success) {
                            var ok  = r.data.statut === 'inscrit';
                            wrap.innerHTML = ok
                                ? '<span class="spcal-evt-insc-ok">✅ Vous êtes inscrit(e)</span>'
                                : '<span class="spcal-evt-insc-ko">❌ Inscription déclinée</span>';
                        } else {
                            if (msg) { msg.style.color='#b91c1c'; msg.textContent='Erreur, réessayez.'; }
                            wrap.querySelectorAll('.spcal-insc-rep').forEach(function(b){ b.disabled = false; });
                        }
                    })
                    .catch(function(){
                        if (msg) { msg.style.color='#b91c1c'; msg.textContent='Erreur réseau.'; }
                        wrap.querySelectorAll('.spcal-insc-rep').forEach(function(b){ b.disabled = false; });
                    });
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

}

endif; // class_exists SpCalPro_Calendar

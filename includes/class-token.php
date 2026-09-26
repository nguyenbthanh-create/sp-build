<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_Token' ) ) :

class SpCalPro_Token {

    private $db;

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;
        add_shortcode( 'sp_cal_fiche_membre', array( $this, 'render_public_fiche' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
        // Intercepter ?token= sur N'IMPORTE quelle page — pas besoin de configurer une URL
        add_action( 'template_redirect', array( $this, 'maybe_render_fiche' ) );
        add_action( 'wp_ajax_sp_cal_resend_token',        array( $this, 'resend_token_ajax' ) );
        add_action( 'wp_ajax_sp_cal_generate_all_tokens', array( $this, 'generate_all_tokens_ajax' ) );
    }

    /**
     * Si ?token= est présent dans l'URL, afficher la fiche et arrêter WordPress.
     * Fonctionne même si aucune page avec le shortcode n'est configurée.
     */
    public function maybe_render_fiche() {
        // Réponse rapide inscription depuis lien email (?sp_insc_token=…)
        if ( isset( $_GET['sp_insc_token'] ) && isset( $_GET['sp_insc_event'] ) && isset( $_GET['sp_insc_rep'] ) ) {
            $this->handle_quick_reply_inscription();
            exit;
        }
        // Page de pointage QR Code
        // Ne pas intercepter si on est sur la page PWA (le JS gère le ?pin=)
        if ( isset( $_GET['pin'] ) && ! is_admin() ) {
            $on_pwa = false;
            if ( is_page() ) {
                global $post;
                if ( $post && has_shortcode( $post->post_content, 'sp_cal_app' ) ) $on_pwa = true;
            }
            if ( ! $on_pwa ) {
                $this->render_pointage_page();
                exit;
            }
        }
        if ( empty( $_GET['token'] ) || is_admin() ) return;

        // Ne pas intercepter la page PWA — le shortcode [sp_cal_app] gère l'auth côté JS
        if ( is_page() ) {
            global $post;
            if ( $post && has_shortcode( $post->post_content, 'sp_cal_app' ) ) return;
        }

        $token = sanitize_text_field( $_GET['token'] );

        global $wpdb;
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tel WHERE token = %s", $token ) );
        if ( ! $el ) return; // Token invalide → 404 normale de WordPress

        // Token valide → afficher la fiche avec l'habillage du thème
        wp_enqueue_style( 'sp-cal-calendar', SP_CAL_PRO_URL . 'assets/css/calendar.css', array(), SP_CAL_PRO_VERSION );
        get_header();
        echo '<div style="max-width:860px;margin:30px auto;padding:0 16px;">';
        echo $this->render_membre_fiche( $el );
        echo '</div>'; // ferme max-width wrapper
        get_footer();
        exit;
    }

    public function enqueue() {
        if ( isset( $_GET['token'] ) )
            wp_enqueue_style( 'sp-cal-calendar', SP_CAL_PRO_URL . 'assets/css/calendar.css', array(), SP_CAL_PRO_VERSION );
    }

    /* ══════════════════════════════════════════════════════════
       GÉNÉRATION & ENVOI TOKEN
    ══════════════════════════════════════════════════════════ */

    /**
     * Génère un token unique pour un élève et l'envoie par email.
     * Appelé quand actif passe à 1.
     * Retourne true si email envoyé, false sinon.
     */
    public function generate_and_send( $eleve_id, $force = false ) {
        global $wpdb;
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tel WHERE id=%d", intval($eleve_id) ) );
        if ( ! $el ) return false;

        // Ne pas renvoyer si déjà envoyé et pas forcé
        if ( ! $force && $el->token && $el->token_sent_at ) return false;

        // Générer le token
        $token = bin2hex( random_bytes(32) ); // 64 chars hex
        $wpdb->update( $tel, array(
            'token'         => $token,
            'token_sent_at' => current_time('mysql'),
        ), array('id' => intval($eleve_id)) );
        $el->token = $token;

        // Envoyer l'email
        return $this->send_token_email( $el );
    }

    /**
     * Envoie l'email de token à l'élève ou au parent.
     * Gère le regroupement par parent (plusieurs enfants).
     */
    public function send_token_email( $el ) {
        $dest = $el->email_parent ?: $el->email;
        if ( ! $dest || ! is_email($dest) ) return false;

        $club     = get_option('blogname', 'Club');
        $fiche_url= $this->get_fiche_url( $el->token );
        $prenom   = $el->prenom;
        $nom      = mb_strtoupper($el->nom);

        // Chercher d'autres enfants avec le même email parent pour les regrouper
        $fratrie = array();
        if ( $el->email_parent ) {
            global $wpdb;
            $tel = $this->db->table_eleves();
            $fratrie = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, prenom, nom, token FROM $tel
                 WHERE email_parent = %s AND actif = 1 AND id != %d AND token IS NOT NULL",
                $el->email_parent, intval($el->id)
            ) );
        }

        $subject = '[' . $club . '] 🏅 Accès à la fiche de suivi de ' . $prenom . ' ' . $nom;

        // Un seul lien, valable sur téléphone comme sur ordinateur (étape 1 de l'unification
        // fiche / application, 26/09/2026) : l'application si la page [sp_cal_app] existe —
        // elle mène à la fiche complète via « Ma fiche complète » —, sinon la fiche.
        $app_url = $this->get_app_url( $el->token );
        $lien    = $app_url ?: $fiche_url;

        $body  = "Bonjour,\n\n";
        $body .= "L'adhésion de $prenom $nom a été activée au sein de $club.\n\n";
        $body .= "Son espace membre, sur téléphone comme sur ordinateur :\n";
        $body .= "➜ " . $lien . "\n\n";

        if ( $app_url ) {
            $body .= "Vous y trouverez les prochains cours, le calendrier, la carte de membre, les inscriptions aux événements et les alertes d'annulation. ";
            $body .= "Le bouton « Ma fiche complète » donne accès aux grades, aux présences et aux doboks.\n\n";
            $body .= "📱 Pour l'installer sur un téléphone :\n";
            $body .= "Sur iPhone : bouton Partager ⬆︎ → \"Sur l'écran d'accueil\".\n";
            $body .= "Sur Android : menu ⋮ de Chrome → \"Ajouter à l'écran d'accueil\".\n\n";
        } else {
            $body .= "Grades, présences, statut d'adhésion, doboks, historique.\n\n";
        }

        $body .= "Ce lien est personnel et unique. Ne pas le partager.\n";

        // Ajouter les liens des frères/sœurs si applicable
        if ( ! empty($fratrie) ) {
            $body .= "\nAccès à l'espace des autres membres de la famille :\n";
            foreach ($fratrie as $fr) {
                $fr_url = $this->get_app_url( $fr->token ) ?: $this->get_fiche_url( $fr->token );
                $body  .= "• " . $fr->prenom . ' ' . mb_strtoupper($fr->nom) . " : " . $fr_url . "\n";
            }
        }

        $body .= "\n-- \n" . $club;

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        return wp_mail($dest, $subject, $body, $headers);
    }

    /** URL de l'application (page contenant [sp_cal_app]) avec le lien personnel, ou '' si pas de page. */
    public function get_app_url( $token ) {
        global $wpdb;
        $page = $wpdb->get_row(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status='publish' AND post_type='page'
             AND post_content LIKE '%sp_cal_app%' LIMIT 1"
        );
        return ( $page && $token ) ? add_query_arg( 'token', $token, trailingslashit( get_permalink( $page->ID ) ) ) : '';
    }

    public function get_fiche_url( $token ) {
        // Avec template_redirect, n'importe quelle URL du site fonctionne.
        // On préfère la page configurée ou la page avec le shortcode, sinon la home.
        wp_cache_delete( 'sp_cal_fiche_membre_url', 'options' );
        $page_url = get_option( 'sp_cal_fiche_membre_url', '' );

        if ( ! $page_url ) {
            global $wpdb;
            $page = $wpdb->get_row(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_status='publish' AND post_type='page'
                 AND post_content LIKE '%sp_cal_fiche_membre%' LIMIT 1"
            );
            $page_url = $page ? get_permalink( $page->ID ) : home_url('/');
        }

        return add_query_arg( 'token', $token, trailingslashit( $page_url ) );
    }

    /* ══════════════════════════════════════════════════════════
       PAGE PUBLIQUE — shortcode [sp_cal_fiche_membre]
    ══════════════════════════════════════════════════════════ */

    public function render_public_fiche( $atts ) {
        $token = sanitize_text_field( $_GET['token'] ?? '' );
        if ( ! $token ) return $this->render_no_token();

        global $wpdb;
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $tel WHERE token = %s", $token
        ) );
        if ( ! $el ) return '<div class="sp-membre-error">🔒 Lien invalide ou expiré. Contactez votre club.</div>';

        return $this->render_membre_fiche($el);
    }

    private function render_no_token() {
        return '<div class="sp-membre-error">
            <p>🔒 Cette page nécessite un lien d\'accès personnel.</p>
            <p>Contactez votre club pour recevoir votre lien de suivi.</p>
        </div>';
    }

    private function render_membre_fiche( $el ) {
        // Données
        $extra       = $el->extra_data ? (json_decode($el->extra_data, true) ?: array()) : array();
        $grades_hist = $extra['grades'] ?? array();
        $examens     = $this->db->get_examens_eleve($el->id);
        $presences   = $this->db->get_presences_eleve($el->id);
        $pres_cours  = array_values(array_filter($presences, function($p){ return !in_array($p->type, array('examen','anniversaire')); }));
        $palmares    = $this->db->get_palmares_eleve($el->id);

        // Saison courante
        $toutes_saisons  = $this->db->get_saisons();
        $saison_courante = $el->saison ?: ($toutes_saisons[0] ?? '');

        // Grouper présences par saison calculée depuis la date
        $pres_by_saison = array();
        foreach ($pres_cours as $p) {
            $yr = $p->date ? intval(date('Y', strtotime($p->date))) : 0;
            $mo = $p->date ? intval(date('n', strtotime($p->date))) : 0;
            $s  = $yr ? (($mo >= 8) ? $yr.'-'.($yr+1) : ($yr-1).'-'.$yr) : 'Inconnue';
            $pres_by_saison[$s][] = $p;
        }
        krsort($pres_by_saison);

        // Stats globales
        $nb_p = count(array_filter($pres_cours, function($p){ return intval($p->present); }));
        $nb_t = count($pres_cours);
        $taux = $nb_t ? round($nb_p / $nb_t * 100) : null;

        // Statut adhésion
        $actif = intval($el->actif ?? 1);
        $fin_s  = get_option('sp_cal_fin_saison','');
        $alerte = intval(get_option('sp_cal_alerte_jours', 60));
        $adhesion_label = 'Actif';
        $adhesion_class = 'sp-membre-badge-ok';
        if (!$actif) {
            $adhesion_label = 'Inactif'; $adhesion_class = 'sp-membre-badge-ko';
        } elseif ($fin_s) {
            $jr = intval(ceil((strtotime($fin_s) - time()) / 86400));
            if ($jr < 0)          { $adhesion_label = 'Saison terminée';                   $adhesion_class = 'sp-membre-badge-warn'; }
            elseif ($jr <= $alerte){ $adhesion_label = 'Actif — expire dans '.$jr.' j.'; $adhesion_class = 'sp-membre-badge-warn'; }
        }

        // Chronologie grades — séparer courant vs archives
        $timeline_all = array();
        foreach ($examens as $ex) {
            $timeline_all[] = array(
                'date_ts'=>$ex->date?strtotime($ex->date):0,
                'date_fmt'=>$ex->date?date_create($ex->date)->format('d/m/Y'):'—',
                'label'=>$ex->titre, 'grade'=>$ex->note,
                'saison'=>$ex->date?$this->date_to_saison($ex->date):'',
            );
        }
        foreach ($grades_hist as $d => $g) {
            $pts=explode('/',$d);
            $ts=count($pts)===3?mktime(0,0,0,intval($pts[1]),intval($pts[0]),intval($pts[2])):0;
            $timeline_all[]=array('date_ts'=>$ts,'date_fmt'=>$d,'label'=>'Import CSV','grade'=>$g,'saison'=>$ts?$this->date_to_saison(date('Y-m-d',$ts)):'');
        }
        usort($timeline_all, function($a,$b){ return $b['date_ts']-$a['date_ts']; });

        $grades_courants = array_filter($timeline_all, function($r) use($saison_courante){ return !$saison_courante||$r['saison']===$saison_courante; });
        $grades_archives = array();
        if ($saison_courante) {
            foreach (array_filter($timeline_all, function($r) use($saison_courante){ return $r['saison']!==$saison_courante; }) as $r)
                $grades_archives[$r['saison']][] = $r;
            krsort($grades_archives);
        }

        // Palmarès groupé + séparé
        $comps_grouped = array();
        foreach ($palmares as $row) {
            $eid = intval($row->event_id);
            if (!isset($comps_grouped[$eid])) $comps_grouped[$eid]=array('titre'=>$row->comp_titre,'date'=>$row->date,'categorie'=>$row->categorie,'epreuves'=>array(),'saison'=>$row->date?$this->date_to_saison($row->date):'');
            $comps_grouped[$eid]['epreuves'][] = $row;
        }
        $palmares_courants = array_filter($comps_grouped, function($c) use($saison_courante){ return !$saison_courante||$c['saison']===$saison_courante; });
        $palmares_archives = array();
        if ($saison_courante) {
            foreach (array_filter($comps_grouped, function($c) use($saison_courante){ return $c['saison']!==$saison_courante; }) as $eid=>$comp)
                $palmares_archives[$comp['saison']][$eid]=$comp;
            krsort($palmares_archives);
        }

        $medal_cfg = array(
            'or'     => array('emoji'=>'🥇','label'=>'Or',    'bg'=>'#fef9c3','border'=>'#fbbf24','color'=>'#92400e'),
            'argent' => array('emoji'=>'🥈','label'=>'Argent','bg'=>'#f1f5f9','border'=>'#94a3b8','color'=>'#334155'),
            'bronze' => array('emoji'=>'🥉','label'=>'Bronze','bg'=>'#fef3c7','border'=>'#d97706','color'=>'#78350f'),
        );

        $club = esc_html(get_option('blogname','Club'));
        // Événements avec inscriptions ouvertes pour cet élève
        $events_inscrip = method_exists( $this->db, 'get_events_inscriptions_ouvertes_eleve' )
            ? $this->db->get_events_inscriptions_ouvertes_eleve( intval( $el->id ) )
            : array();
        ob_start();
        ?>
        <div class="sp-membre-wrap">

            <!-- En-tête -->
            <div class="sp-membre-header">
                <div class="sp-membre-avatar">
                    <?php if ( ! empty($el->photo_url) ) : ?>
                        <img src="<?php echo esc_url($el->photo_url); ?>"
                             style="width:100%;height:100%;object-fit:cover;border-radius:50%;"
                             alt="">
                    <?php else : ?>
                        <?php echo esc_html(mb_strtoupper(mb_substr($el->prenom,0,1).mb_substr($el->nom,0,1))); ?>
                    <?php endif; ?>
                </div>
                <div class="sp-membre-identity">
                    <h1><?php echo esc_html($el->prenom . ' ' . mb_strtoupper($el->nom)); ?></h1>
                    <div class="sp-membre-meta">
                        <?php if($el->categorie_saisie): ?><span class="sp-membre-tag sp-membre-tag-saisie"><?php echo esc_html( $this->db->label_discipline($el->categorie_saisie) ); ?></span><?php endif; ?>
                        <?php if($el->categorie_age):   ?><span class="sp-membre-tag sp-membre-tag-age"><?php echo esc_html($el->categorie_age); ?></span><?php endif; ?>
                        <?php if($saison_courante):     ?><span class="sp-membre-saison"><?php echo esc_html($saison_courante); ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="sp-membre-right">
                    <span class="sp-membre-badge <?php echo $adhesion_class; ?>"><?php echo esc_html($adhesion_label); ?></span>
                    <?php if($taux!==null): ?>
                    <div class="sp-membre-assiduity">
                        <div class="sp-membre-bar-track"><div class="sp-membre-bar-fill" style="width:<?php echo $taux; ?>%;background:<?php echo $taux>=75?'#22c55e':($taux>=50?'#f59e0b':'#ef4444'); ?>;"></div></div>
                        <span><?php echo $taux; ?>% assiduité (<?php echo $nb_p; ?>/<?php echo $nb_t; ?> cours)</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php // Passerelle vers l'application (étape 1 de l'unification fiche / PWA, 26/09/2026)
            $app_url = $el->token ? $this->get_app_url( $el->token ) : '';
            if ( $app_url ) : ?>
            <a href="<?php echo esc_url( $app_url ); ?>" style="display:flex;align-items:center;gap:10px;margin:0 0 18px;padding:12px 16px;border-radius:10px;background:#111;color:#fff;text-decoration:none;font-size:14px;">
                <span style="font-size:22px;">📱</span>
                <span><strong>Ouvrir l'application</strong><br><small style="opacity:.75;">Prochains cours, calendrier, inscriptions, notifications — installable sur votre téléphone</small></span>
                <span style="margin-left:auto;font-size:20px;">›</span>
            </a>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════
                 CARTE DE MEMBRE NUMÉRIQUE
                 ══════════════════════════════════════════ -->
            <?php
            $club_num        = get_option('sp_cal_club_num','');
            $club_affil      = get_option('sp_cal_club_affiliation','');
            $club_ligue      = get_option('sp_cal_club_ligue','');
            $club_labelise   = intval(get_option('sp_cal_club_labelise',0));
            $token_url       = home_url('/fiche-membre/?token=' . rawurlencode($el->token ?? ''));
            ?>
            <div class="sp-membre-section sp-carte-membre" id="sp-carte-<?php echo intval($el->id); ?>">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
                    <h2 style="margin:0;">🪪 Carte de membre</h2>
                    <button onclick="spCarteImprimer()" style="background:#111;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;">🖨️ Imprimer</button>
                    <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#6b7280;cursor:pointer;margin-left:10px;vertical-align:middle;">
                        <input type="checkbox" id="sp-mode-imprimeur-indiv">
                        Mode imprimeur <small style="color:#94a3b8;">(débord 2mm · angles droits)</small>
                    </label>
                </div>

                <!-- RECTO -->
                <div class="sp-carte-recto" id="sp-carte-recto-<?php echo intval($el->id); ?>">
                    <!-- Bandes déco -->
                    <div class="sp-carte-band-blue"></div>
                    <div class="sp-carte-band-yellow"></div>
                    <div class="sp-carte-band-red"></div>

                    <!-- Logo -->
                    <div class="sp-carte-logo-wrap">
                        <img src="<?php echo esc_url(get_option('sp_cal_logo_url', 'https://tkdclaira.fr/wp-content/uploads/2026/04/LogoSansLettre.png')); ?>"
                             class="sp-carte-logo"
                             onerror="this.style.display='none'">
                    </div>
                    <div class="sp-carte-club-name"><?php echo esc_html(strtoupper($club)); ?></div>
                    <div class="sp-carte-club-sub">CARTE DE MEMBRE</div>

                    <!-- Séparateur -->
                    <div class="sp-carte-sep"></div>

                    <!-- Identité -->
                    <div class="sp-carte-nom"><?php echo esc_html($el->prenom . ' ' . mb_strtoupper($el->nom)); ?></div>
                    <div class="sp-carte-meta">
                        <?php
                        $meta_parts = array_filter([
                            $club_affil ? 'FFTDA' . ($club_affil ? ' '.$club_affil : '') : '',
                            $club_ligue,
                            $club_num ? 'Club '.$club_num : '',
                        ]);
                        echo esc_html(implode(' · ', $meta_parts));
                        ?>
                    </div>

                    <!-- Infos -->
                    <div class="sp-carte-infos">
                        <?php if ($el->licence): ?>
                        <div class="sp-carte-info-row">
                            <span class="sp-carte-info-k">Licence</span>
                            <span class="sp-carte-info-v"><?php echo esc_html($el->licence); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($el->date_naissance && $el->annee_naissance): ?>
                        <div class="sp-carte-info-row">
                            <span class="sp-carte-info-k">Né(e) le</span>
                            <span class="sp-carte-info-v"><?php echo esc_html($el->date_naissance.'/'.$el->annee_naissance); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($el->num_passeport)): ?>
                        <div class="sp-carte-info-row">
                            <span class="sp-carte-info-k">Passeport</span>
                            <span class="sp-carte-info-v"><?php echo esc_html($el->num_passeport); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($el->urgence_telephone)): ?>
                        <div class="sp-carte-info-row">
                            <span class="sp-carte-info-k">Urgence</span>
                            <span class="sp-carte-info-v sp-carte-info-urgence"><?php echo esc_html($el->urgence_telephone); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- QR Code -->
                    <div class="sp-carte-qr-zone">
                        <div id="sp-qr-<?php echo intval($el->id); ?>" class="sp-carte-qr-box"></div>
                        <?php if ($club_labelise > 0): ?>
                        <div class="sp-carte-stars">
                            <?php for($i=1;$i<=5;$i++) echo '<span style="color:'.($i<=$club_labelise?'#ffdd0e':'rgba(255,255,255,0.2)').';font-size:9px;">★</span>'; ?>
                        </div>
                        <div class="sp-carte-labelise-txt">Club labellisé</div>
                        <?php endif; ?>
                        <div class="sp-carte-qr-scan">Scanner pour pointer</div>
                    </div>

                    <?php if ( ! empty($el->photo_url) ) : ?>
                    <div class="sp-carte-photo-wrap">
                        <img src="<?php echo esc_url($el->photo_url); ?>"
                             class="sp-carte-photo"
                             alt="">
                    </div>
                    <?php endif; ?>

                    <div class="sp-carte-url">tkdclaira.fr</div>
                </div>

                <!-- VERSO -->
                <div class="sp-carte-verso" id="sp-carte-verso-<?php echo intval($el->id); ?>">
                    <div class="sp-carte-verso-header">
                        <div class="sp-carte-verso-logo-wrap">
                            <img src="<?php echo esc_url(get_option('sp_cal_logo_url', 'https://tkdclaira.fr/wp-content/uploads/2026/04/LogoSansLettre.png')); ?>"
                                 class="sp-carte-verso-logo"
                                 onerror="this.style.display='none'">
                        </div>
                        <span class="sp-carte-verso-title"><?php echo esc_html(strtoupper($club)); ?></span>
                        <span class="sp-carte-verso-badge"><?php echo esc_html($el->prenom . ' ' . mb_strtoupper($el->nom)); ?></span>
                    </div>
                    <div style="flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:6px;padding:12px;">
                        <div style="color:rgba(255,255,255,0.15);font-size:40px;line-height:1;user-select:none;font-family:'Song Myung',cursive;letter-spacing:3px;">태권도</div>
                        <div style="color:rgba(255,255,255,0.35);font-size:9px;letter-spacing:2px;text-transform:uppercase;">Carte de membre officielle</div>
                    </div>
                    <div class="sp-carte-verso-footer">
                        <span><?php
                            $footer_parts = array_filter([$club_affil, $club_num ? 'Club '.$club_num : '', 'tkdclaira.fr']);
                            echo esc_html(implode(' · ', $footer_parts));
                        ?></span>
                        <?php if ($club_labelise > 0): ?>
                        <span>
                            <?php for($i=1;$i<=5;$i++) echo '<span style="color:'.($i<=$club_labelise?'#e30613':'rgba(255,255,255,0.12)').';font-size:8px;">★</span>'; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- PAGE IMPRESSION A4 — 8 cartes -->
            <div id="sp-carte-print-<?php echo intval($el->id); ?>" style="display:none;"></div>

            <!-- Éligibilité 1e Dan -->
            <?php if ( in_array($el->categorie_age ?? '', ['Ado/adulte', 'Adulte']) ) :
                $saison_elig     = get_option('tkd_saison_courante', '2025/2026');
                $annee_fin_elig  = intval( explode('/', $saison_elig)[1] ?? date('Y') );
                $ts_ref_elig     = mktime(0, 0, 0, 6, 30, $annee_fin_elig);
                $nb_lic          = intval($el->nb_licences ?? 0);
                $cond_lic        = $nb_lic >= 3;
                $date_elig_txt   = '';
                $cond_age_elig   = false;
                $atteint_saison  = false;
                if ( ! empty($el->date_naissance) && ! empty($el->annee_naissance) ) {
                    $parts_elig = explode('/', $el->date_naissance);
                    if ( count($parts_elig) >= 2 ) {
                        $ts_naiss_elig = mktime(0,0,0, intval($parts_elig[1]), intval($parts_elig[0]), intval($el->annee_naissance));
                        $ts_14ans_elig = mktime(0,0,0, intval($parts_elig[1]), intval($parts_elig[0]), intval($el->annee_naissance)+14);
                        $cond_age_elig = ($ts_14ans_elig <= $ts_ref_elig);
                        $date_elig_txt = date('d/m/Y', $ts_14ans_elig);
                        $atteint_saison = $ts_14ans_elig <= $ts_ref_elig
                            && $ts_14ans_elig > mktime(0,0,0,9,1,$annee_fin_elig-1);
                    }
                }
                $auto_elig = $cond_age_elig && $cond_lic;
            ?>
            <div class="sp-membre-section">
                <h2>🥋 Éligibilité 1e Dan</h2>
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:16px 20px;">
                    <!-- Licences -->
                    <div style="margin-bottom:10px;font-size:14px;">
                        <?php if ($cond_lic): ?>
                            <span style="color:green;">✅ <?php echo $nb_lic; ?> licences — condition remplie</span>
                        <?php else: ?>
                            <span style="color:#dc2626;">❌ <?php echo $nb_lic; ?> licence(s) sur 3 requises</span>
                        <?php endif; ?>
                    </div>
                    <!-- Âge -->
                    <div style="margin-bottom:12px;font-size:14px;">
                        <?php if ( ! $date_elig_txt ): ?>
                            <span style="color:#999;">Date de naissance manquante</span>
                        <?php elseif ($cond_age_elig && !$atteint_saison): ?>
                            <span style="color:green;">✅ 14 ans révolus depuis le <?php echo $date_elig_txt; ?></span>
                        <?php elseif ($cond_age_elig && $atteint_saison): ?>
                            <span style="color:#f0a500;">⚠️ Éligible à partir du <?php echo $date_elig_txt; ?> — vérifiez la date de l'examen</span>
                        <?php else: ?>
                            <span style="color:#dc2626;">❌ Éligible à partir du <?php echo $date_elig_txt; ?></span>
                        <?php endif; ?>
                    </div>
                    <!-- Résultat -->
                    <div style="padding:10px 14px;border-radius:8px;font-weight:700;font-size:14px;
                                background:<?php echo $auto_elig ? '#f0fdf4' : '#fef2f2'; ?>;
                                border:1px solid <?php echo $auto_elig ? '#86efac' : '#fca5a5'; ?>;
                                color:<?php echo $auto_elig ? 'green' : '#dc2626'; ?>;">
                        <?php if ($auto_elig): ?>
                            → Éligible au 1e Dan cette saison
                        <?php elseif (!$cond_lic): ?>
                            → Non éligible (licences insuffisantes)
                        <?php elseif (!$cond_age_elig): ?>
                            → Non éligible cette saison
                        <?php else: ?>
                            → Données manquantes
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Grade actuel / prochain grade -->
            <div class="sp-membre-section">
                <h2>🥋 Grade</h2>
                <?php if($el->grade): ?>
                <div style="display:flex;gap:32px;flex-wrap:wrap;">
                    <div>
                        <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Grade actuel</div>
                        <div class="sp-membre-grade-current"><?php echo esc_html($el->grade); ?></div>
                    </div>
                    <?php $grade_vise = $this->db->get_grade_vise_eleve($el); if ( $grade_vise ) : ?>
                    <div>
                        <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Prochain grade</div>
                        <div class="sp-membre-grade-current" style="color:#0f70b7;"><?php echo esc_html($grade_vise); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?><p class="sp-membre-muted">Aucun grade renseigné.</p><?php endif; ?>
            </div>

            <?php // Bloc « Mes doboks » (SP_Cal_Dobok::render_bloc_adherent) ?>
            <?php do_action( 'sp_cal_fiche_membre_apres_grade', $el ); ?>

            <!-- Grades saison courante -->
            <?php if(!empty($grades_courants)): ?>
            <div class="sp-membre-section">
                <h2>🎓 Passages de grade<?php echo $saison_courante?' — '.esc_html($saison_courante):''; ?></h2>
                <?php echo $this->render_grades_table($grades_courants, $el->grade); ?>
            </div>
            <?php endif; ?>

            <!-- Grades archivés -->
            <?php if(!empty($grades_archives)): ?>
            <div class="sp-membre-section">
                <details class="sp-archive-details">
                    <summary class="sp-archive-summary">📦 Historique grades — saisons précédentes</summary>
                    <?php foreach($grades_archives as $sais=>$rows): ?>
                    <div class="sp-archive-saison">
                        <div class="sp-archive-saison-label"><?php echo esc_html($sais?:'Saison inconnue'); ?></div>
                        <?php echo $this->render_grades_table($rows,''); ?>
                    </div>
                    <?php endforeach; ?>
                </details>
            </div>
            <?php endif; ?>

            <!-- Palmarès saison courante -->
            <?php if(!empty($palmares_courants)): ?>
            <div class="sp-membre-section">
                <h2>🏆 Palmarès<?php echo $saison_courante?' — '.esc_html($saison_courante):''; ?></h2>
                <?php echo $this->render_palmares_section($palmares_courants, $medal_cfg); ?>
            </div>
            <?php endif; ?>

            <!-- Palmarès archivés -->
            <?php if(!empty($palmares_archives)): ?>
            <div class="sp-membre-section">
                <details class="sp-archive-details">
                    <summary class="sp-archive-summary">📦 Palmarès — saisons précédentes</summary>
                    <?php foreach($palmares_archives as $sais=>$comps): ?>
                    <div class="sp-archive-saison">
                        <div class="sp-archive-saison-label"><?php echo esc_html($sais?:'Saison inconnue'); ?></div>
                        <?php echo $this->render_palmares_section($comps, $medal_cfg); ?>
                    </div>
                    <?php endforeach; ?>
                </details>
            </div>
            <?php endif; ?>

            <!-- Présences par saison -->
            <?php if(!empty($pres_by_saison)): $first=true; ?>
            <?php foreach($pres_by_saison as $sais=>$pres_s):
                $nok=$nb_tot2=0;
                foreach($pres_s as $p){ if(intval($p->present)) $nok++; $nb_tot2++; }
                $tx=($nb_tot2?round($nok/$nb_tot2*100):0);
                $is_cur=($first); $first=false; ?>
            <?php if($is_cur): ?>
            <div class="sp-membre-section">
                <h2>📅 Présences — <?php echo esc_html($sais); ?>
                    <span style="font-size:13px;font-weight:500;color:#6b7280;margin-left:8px;"><?php echo $nok.'/'.$nb_tot2; ?> cours (<?php echo $tx; ?>%)</span>
                </h2>
                <?php echo $this->render_presences_grid($pres_s); ?>
            </div>
            <?php else: ?>
            <div class="sp-membre-section">
                <details class="sp-archive-details">
                    <summary class="sp-archive-summary">📦 Présences — <?php echo esc_html($sais); ?>
                        <span class="sp-archive-stats"><?php echo $nok.'/'.$nb_tot2; ?> · <?php echo $tx; ?>%</span>
                    </summary>
                    <?php echo $this->render_presences_grid($pres_s); ?>
                </details>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════
                 ÉVÉNEMENTS — INSCRIPTIONS OUVERTES
                 ══════════════════════════════════════════ -->
            <?php if ( ! empty( $events_inscrip ) ) : ?>
            <div class="sp-membre-section">
                <h2>📅 Événements — Répondre à l'invitation</h2>
                <?php foreach ( $events_inscrip as $evt ) :
                    // statut_insc est maintenant inclus directement dans la requête SQL
                    $statut_insc = isset($evt->statut_insc) ? $evt->statut_insc : 'en_attente';
                    $deadline_ok = ! $evt->inscriptions_deadline || $evt->inscriptions_deadline >= date('Y-m-d');
                    $d_evt       = $evt->date ? date_create( $evt->date )->format( 'd/m/Y' ) : '';
                    $dl_evt      = $evt->inscriptions_deadline ? date_create( $evt->inscriptions_deadline )->format( 'd/m/Y' ) : '';
                ?>
                <div class="sp-insc-event" id="sp-insc-<?php echo intval( $evt->id ); ?>">
                    <div class="sp-insc-info">
                        <div class="sp-insc-titre"><?php echo esc_html( $evt->titre ); ?></div>
                        <div class="sp-insc-meta">
                            📅 <?php echo esc_html( $d_evt );
                            if ( $evt->heure_debut ) echo ' · ' . esc_html( substr( $evt->heure_debut, 0, 5 ) );
                            if ( $dl_evt )           echo ' &nbsp;⏰ avant le ' . esc_html( $dl_evt ); ?>
                        </div>
                    </div>
                    <div class="sp-insc-actions">
                        <?php if ( ! $deadline_ok ) : ?>
                            <?php if ( $statut_insc === 'inscrit' ) : ?>
                                <span class="sp-insc-badge sp-insc-ok">✅ Inscrit(e)</span>
                            <?php elseif ( $statut_insc === 'refuse' ) : ?>
                                <span class="sp-insc-badge sp-insc-ko">❌ Décliné</span>
                            <?php else : ?>
                                <span class="sp-insc-badge" style="background:#f3f4f6;color:#6b7280;">⏳ Sans réponse</span>
                            <?php endif; ?>
                            <span style="font-size:11px;color:#9ca3af;display:block;margin-top:4px;">⏰ Délai dépassé — contacter le club pour modifier</span>
                        <?php else : ?>
                            <?php if ( $statut_insc === 'inscrit' ) : ?>
                                <span class="sp-insc-badge sp-insc-ok">✅ Inscrit(e)</span>
                                <button class="sp-insc-btn sp-insc-btn-non"
                                        data-event="<?php echo intval( $evt->id ); ?>"
                                        data-token="<?php echo esc_attr( $el->token ); ?>"
                                        data-rep="non"
                                        style="font-size:11px;padding:5px 10px;background:#fff;color:#dc2626;border:2px solid #dc2626;">Annuler</button>
                            <?php elseif ( $statut_insc === 'refuse' ) : ?>
                                <span class="sp-insc-badge sp-insc-ko">❌ Décliné</span>
                                <button class="sp-insc-btn sp-insc-btn-oui"
                                        data-event="<?php echo intval( $evt->id ); ?>"
                                        data-token="<?php echo esc_attr( $el->token ); ?>"
                                        data-rep="oui"
                                        style="font-size:11px;padding:5px 10px;">Je participe finalement</button>
                            <?php else : ?>
                                <button class="sp-insc-btn sp-insc-btn-oui"
                                        data-event="<?php echo intval( $evt->id ); ?>"
                                        data-token="<?php echo esc_attr( $el->token ); ?>"
                                        data-rep="oui">✅ Je participe</button>
                                <button class="sp-insc-btn sp-insc-btn-non"
                                        data-event="<?php echo intval( $evt->id ); ?>"
                                        data-token="<?php echo esc_attr( $el->token ); ?>"
                                        data-rep="non">❌ Je ne peux pas</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div><!-- /.sp-insc-event -->
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="sp-membre-footer"><?php echo $club; ?> — Fiche de suivi personnelle</div>
        </div>

        <!-- Styles carte de membre -->
        <style>
        /* ── DIMENSIONS FIXES (format carte bancaire ×2.5) ── */
        .sp-carte-recto,
        .sp-carte-verso {
            width: 340px;
            height: 215px;
            border-radius: 12px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            position: relative;
            overflow: hidden;
        }

        /* ── RECTO ── */
        .sp-carte-recto { background: #111; margin-bottom: 16px; }
        .sp-carte-band-blue   { position:absolute; right:0; top:0; width:88px; height:215px; background:#0f70b7; }
        .sp-carte-band-yellow { position:absolute; right:84px; top:0; width:4px; height:215px; background:#ffdd0e; }
        .sp-carte-band-red    { position:absolute; bottom:0; left:0; width:252px; height:5px; background:#e30613; }

        /* Logo couleur sur fond blanc petit carré */
        .sp-carte-logo-wrap {
            position:absolute; top:10px; left:12px;
            width:30px; height:30px; border-radius:4px;
            background:#fff;
            display:flex; align-items:center; justify-content:center;
        }
        .sp-carte-logo { width:28px; height:28px; object-fit:contain; }

        .sp-carte-club-name { position:absolute; top:12px; left:50px; color:#fff; font-size:10px; font-weight:500; letter-spacing:1.5px; }
        .sp-carte-club-sub  { position:absolute; top:25px; left:50px; color:#ffdd0e; font-size:8px; letter-spacing:1px; }
        .sp-carte-sep       { position:absolute; top:46px; left:12px; width:240px; height:0.5px; background:rgba(255,255,255,0.12); }

        /* Zone texte strictement limitée à gauche du trait jaune */
        .sp-carte-nom  { position:absolute; top:54px; left:12px; right:100px; color:#fff; font-size:13px; font-weight:500; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sp-carte-meta { position:absolute; top:72px; left:12px; right:100px; color:rgba(255,255,255,0.4); font-size:8px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sp-carte-infos    { position:absolute; top:88px; left:12px; right:100px; display:flex; flex-direction:column; gap:4px; }
        .sp-carte-info-row { display:flex; gap:6px; align-items:center; }
        .sp-carte-info-k   { color:rgba(255,255,255,0.38); font-size:8px; width:50px; text-transform:uppercase; letter-spacing:0.4px; flex-shrink:0; }
        .sp-carte-info-v   { color:#fff; font-size:9px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sp-carte-info-urgence { color:#e30613 !important; }

        /* QR dans bande bleue — strictement dans les 88px */
        .sp-carte-qr-zone {
            position:absolute; top:8px; right:2px; width:86px;
            display:flex; flex-direction:column; align-items:center; gap:2px;
        }
        .sp-carte-qr-box { width:72px; height:72px; background:#fff; border-radius:6px; overflow:hidden; display:flex; align-items:center; justify-content:center; }
        .sp-carte-qr-box img, .sp-carte-qr-box canvas { max-width:72px; max-height:72px; }
        .sp-carte-stars        { display:flex; gap:1px; margin-top:3px; }
        .sp-carte-labelise-txt { color:rgba(255,255,255,0.35); font-size:7px; letter-spacing:0.3px; }
        .sp-carte-qr-scan      { color:rgba(255,255,255,0.3); font-size:7px; text-align:center; }
        .sp-carte-url          { position:absolute; bottom:8px; right:6px; color:rgba(255,255,255,0.2); font-size:7px; }

        /* Photo de profil — coin bas-gauche du recto */
        .sp-carte-photo-wrap {
            position:absolute; bottom:12px; left:12px;
            width:46px; height:46px;
            border-radius:50%;
            overflow:hidden;
            border:2px solid rgba(255,255,255,0.18);
            background:#222;
        }
        .sp-carte-photo { width:100%; height:100%; object-fit:cover; display:block; }

        /* ── VERSO — fond noir fixe même hauteur que recto ── */
        .sp-carte-verso { background:#111; display:flex; flex-direction:column; }
        .sp-carte-verso-header {
            height:36px; padding:0 12px;
            display:flex; align-items:center; gap:8px;
            border-bottom:1px solid rgba(255,255,255,0.1);
            flex-shrink:0;
        }
        .sp-carte-verso-logo-wrap {
            width:22px; height:22px; border-radius:3px;
            background:#fff;
            display:flex; align-items:center; justify-content:center; flex-shrink:0;
        }
        .sp-carte-verso-logo   { width:20px; height:20px; object-fit:contain; }
        .sp-carte-verso-title  { color:rgba(255,255,255,0.75); font-size:10px; font-weight:500; letter-spacing:0.8px; }
        .sp-carte-verso-badge  { margin-left:auto; background:#ffdd0e; border-radius:8px; padding:2px 8px; font-size:8px; font-weight:500; color:#111; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px; }

        .sp-carte-verso-grades { padding:6px 12px; display:flex; flex-direction:column; gap:0; flex:1; }
        .sp-carte-verso-row {
            display:flex; justify-content:space-between; align-items:center;
            font-size:11px; padding:4px 8px; border-radius:4px; border-left:3px solid transparent;
            height:26px; flex-shrink:0;
        }
        .sp-carte-verso-row-first { border-left:3px solid #e30613; }
        .sp-carte-verso-row:nth-child(odd)  { background:rgba(255,255,255,0.04); }
        .sp-carte-verso-grade { font-weight:500; color:rgba(255,255,255,0.85); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sp-carte-verso-date  { color:rgba(255,255,255,0.4); font-size:10px; flex-shrink:0; margin-left:8px; }

        .sp-carte-verso-footer {
            height:24px; padding:0 12px;
            border-top:1px solid rgba(255,255,255,0.1);
            display:flex; justify-content:space-between; align-items:center;
            font-size:8px; color:rgba(255,255,255,0.3);
            flex-shrink:0;
        }
        /* Remplir les lignes vides pour maintenir la hauteur */
        .sp-carte-verso-row-empty {
            height:26px; border-left:3px solid transparent;
        }

        /* ── Impression ── */
        @media print {
            /* Cacher tout le thème WordPress */
            header, footer, nav, aside,
            #wpadminbar,
            .site-header, .site-footer, .site-nav,
            .entry-header, .entry-footer,
            .widget-area, .sidebar,
            /* Cacher les sections SP sauf la carte */
            .sp-membre-header,
            .sp-membre-section:not(.sp-carte-membre),
            .sp-membre-footer,
            button, .button { display:none !important; }
            /* Supprimer marges/padding de la page */
            body, html { margin:0 !important; padding:0 !important; background:#fff !important; }
            /* La carte s'affiche correctement */
            .sp-carte-membre { margin:0 !important; padding:0 !important; border:none !important; }
            .sp-carte-recto, .sp-carte-verso {
                -webkit-print-color-adjust:exact;
                print-color-adjust:exact;
            }
        }
        @media (max-width:400px) {
            .sp-carte-recto, .sp-carte-verso { width:100% !important; height:auto !important; }
        }
        /* ── Inscriptions événements ── */
        .sp-insc-event {
            display:flex; align-items:center; justify-content:space-between;
            flex-wrap:wrap; gap:12px;
            padding:14px 16px; border-radius:10px;
            background:#f8fafc; border:1px solid #e2e8f0;
            margin-bottom:10px;
        }
        .sp-insc-info { flex:1; min-width:0; }
        .sp-insc-titre { font-size:15px; font-weight:600; color:#111; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sp-insc-meta  { font-size:12px; color:#6b7280; margin-top:3px; }
        .sp-insc-actions { display:flex; gap:8px; flex-shrink:0; flex-wrap:wrap; }
        .sp-insc-btn { padding:8px 14px; border-radius:8px; border:none; cursor:pointer; font-size:13px; font-weight:600; }
        .sp-insc-btn-oui { background:#16a34a; color:#fff; }
        .sp-insc-btn-non { background:#dc2626; color:#fff; }
        .sp-insc-badge { display:inline-block; padding:6px 14px; border-radius:8px; font-size:13px; font-weight:600; }
        .sp-insc-ok { background:#dcfce7; color:#15803d; }
        .sp-insc-ko { background:#fee2e2; color:#b91c1c; }
        .sp-insc-loading { color:#6b7280; font-size:13px; font-style:italic; }
        </style>

        <!-- QR Code -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <link href="https://fonts.googleapis.com/css2?family=Song+Myung&display=swap" rel="stylesheet">
        <script>
        (function(){
            var qrEl = document.getElementById('sp-qr-<?php echo intval($el->id); ?>');
            if (!qrEl) return;
            function initQR() {
                if (typeof QRCode === 'undefined') { setTimeout(initQR, 100); return; }
                var url = '<?php echo esc_js($token_url); ?>';
                new QRCode(qrEl, { text:url, width:68, height:68, colorDark:'#111111', colorLight:'#ffffff', correctLevel:QRCode.CorrectLevel.M });
            }
            initQR();
        })();
        function spCarteImprimer() {
            var carteEl = document.getElementById('sp-carte-<?php echo intval($el->id); ?>');
            if (!carteEl) return;

            // Récupérer le CSS de la carte
            var css = '';
            var sheets = document.styleSheets;
            for (var i = 0; i < sheets.length; i++) {
                try {
                    var rules = sheets[i].cssRules || sheets[i].rules;
                    for (var j = 0; j < rules.length; j++) {
                        var sel = rules[j].selectorText || '';
                        if (sel.indexOf('sp-carte') !== -1 || sel.indexOf('sp-membre-section') !== -1) {
                            css += rules[j].cssText + '\n';
                        }
                    }
                } catch(e) {}
            }

            var win = window.open('', '_blank', 'width=500,height=600');
            // ── Mode imprimeur : débord 2mm, angles droits, traits de coupe ──
            var proMode = document.getElementById('sp-mode-imprimeur-indiv');
            if (proMode && proMode.checked) {
                css += '.sp-carte-recto,.sp-carte-verso{'
                     +   'width:89.6mm!important;height:58mm!important;'
                     +   'border-radius:0!important;'
                     + '}'
                     + '.sp-carte-recto{margin-bottom:4mm!important;}'
                     + '.sp-pro-wrap{position:relative;display:inline-block;}'
                     + '.sp-cm{position:absolute;background:rgba(255,255,255,.55);display:block;pointer-events:none;z-index:99;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
                     + '.sp-cm.h{width:1.5mm;height:.4pt;}'
                     + '.sp-cm.v{width:.4pt;height:1.5mm;}'
                     + '.sp-cm.tl-h{top:2mm;left:0;}.sp-cm.tl-v{top:0;left:2mm;}'
                     + '.sp-cm.tr-h{top:2mm;right:0;}.sp-cm.tr-v{top:0;right:2mm;}'
                     + '.sp-cm.bl-h{bottom:2mm;left:0;}.sp-cm.bl-v{bottom:0;left:2mm;}'
                     + '.sp-cm.br-h{bottom:2mm;right:0;}.sp-cm.br-v{bottom:0;right:2mm;}'
                     /* Zone de sécurité : recul des éléments de bord (≥ 3mm du bord aggrandi) */
                     /* Bandes : hauteur 58mm + bleue élargie à 25mm pour couvrir QR décalé */
                     + '.sp-carte-band-blue{height:58mm!important;width:25mm!important;}'
                     + '.sp-carte-band-yellow{height:58mm!important;right:24mm!important;}'
                     + '.sp-carte-band-red{width:64.5mm!important;height:3.5mm!important;bottom:0!important;}'
                     + '.sp-carte-logo-wrap{top:4.5mm!important;left:5mm!important;}'
                     + '.sp-carte-club-name{top:5mm!important;left:15.5mm!important;}'
                     + '.sp-carte-club-sub{top:9mm!important;left:15.5mm!important;}'
                     + '.sp-carte-qr-zone{top:4mm!important;right:2.5mm!important;}'
                     + '.sp-carte-url{bottom:4mm!important;right:4mm!important;}'
                     + '.sp-carte-photo-wrap{bottom:6.5mm!important;left:5mm!important;}'
                     + '.sp-carte-verso-header{padding-left:5mm!important;padding-right:5mm!important;}'
                     + '.sp-carte-verso-footer{padding-left:5mm!important;padding-right:5mm!important;}';
            }
            var cropMarks = '<div class="sp-cm h tl-h"></div><div class="sp-cm v tl-v"></div>'
                          + '<div class="sp-cm h tr-h"></div><div class="sp-cm v tr-v"></div>'
                          + '<div class="sp-cm h bl-h"></div><div class="sp-cm v bl-v"></div>'
                          + '<div class="sp-cm h br-h"></div><div class="sp-cm v br-v"></div>';
            var wrapOpen  = (proMode && proMode.checked) ? '<div class="sp-pro-wrap">' + cropMarks : '';
            var wrapClose = (proMode && proMode.checked) ? '</div>' : '';
            win.document.write('<!DOCTYPE html><html><head><meta charset="utf-8">'
                + '<title>Carte de membre</title>'
                + '<style>'
                + 'body{margin:20px;padding:0;background:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}'
                + css
                + '@media print{'
                + '  body{margin:0;}'
                + '  button{display:none!important;}'
                + '  .sp-carte-recto,.sp-carte-verso{-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
                + '}'
                + '</style></head><body>'
                + wrapOpen + carteEl.querySelector('.sp-carte-recto').outerHTML + wrapClose
                + '<br>'
                + wrapOpen + carteEl.querySelector('.sp-carte-verso').outerHTML + wrapClose
                + '<script>window.onload=function(){window.print();window.onafterprint=function(){window.close();};}<\/script>'
                + '</body></html>');
            win.document.close();
        }
        </script>


        <script>
        (function(){
            var btns = document.querySelectorAll('.sp-insc-btn');
            if (!btns.length) return;
            function spInscRepondre(btn) {
                var eventId = btn.getAttribute('data-event');
                var token   = btn.getAttribute('data-token');
                var rep     = btn.getAttribute('data-rep');
                var wrap    = btn.closest ? btn.closest('.sp-insc-actions') : btn.parentNode;
                wrap.innerHTML = '<span class="sp-insc-loading">Enregistrement…</span>';
                var fd = new FormData();
                fd.append('action',   'sp_inscription_repondre');
                fd.append('token',    token);
                fd.append('event_id', eventId);
                fd.append('reponse',  rep);
                fetch('<?php echo esc_js( admin_url('admin-ajax.php') ); ?>', { method:'POST', body:fd })
                .then(function(r){ return r.json(); })
                .then(function(r){
                    if (r.success) {
                        var ok  = r.data.statut === 'inscrit';
                        var lbl = ok ? '✅ Inscrit(e)' : '❌ Décliné';
                        var cls = ok ? 'sp-insc-badge sp-insc-ok' : 'sp-insc-badge sp-insc-ko';
                        wrap.innerHTML = '<span class="' + cls + '">' + lbl + '</span>';
                    } else {
                        wrap.innerHTML = '<span class="sp-insc-loading">Erreur, réessayez.</span>';
                    }
                })
                .catch(function(){
                    wrap.innerHTML = '<span class="sp-insc-loading">Erreur réseau.</span>';
                });
            }
            btns.forEach(function(btn){
                btn.addEventListener('click', function(){ spInscRepondre(btn); });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    private function date_to_saison( $date_str ) {
        $yr = intval(date('Y', strtotime($date_str)));
        $mo = intval(date('n', strtotime($date_str)));
        return $mo >= 8 ? $yr.'-'.($yr+1) : ($yr-1).'-'.$yr;
    }

    private function render_grades_table( $rows, $grade_actuel ) {
        ob_start(); ?>
        <table class="sp-membre-table">
            <thead><tr><th>Date</th><th>Événement</th><th>Grade obtenu</th></tr></thead>
            <tbody>
            <?php foreach($rows as $row):
                $is_cur=($grade_actuel && ($row['grade']??'')===$grade_actuel); ?>
            <tr<?php echo $is_cur?' class="sp-membre-tr-current"':''; ?>>
                <td><?php echo esc_html($row['date_fmt']??''); ?></td>
                <td><?php echo esc_html($row['label']??''); ?></td>
                <td><strong><?php echo esc_html($row['grade']??'—'); ?></strong>
                    <?php if($is_cur) echo ' <span class="sp-membre-badge-small">actuel</span>'; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php return ob_get_clean();
    }

    private function render_palmares_section( $comps, $medal_cfg ) {
        ob_start();
        foreach ($comps as $comp): ?>
        <div class="sp-palmares-comp">
            <div class="sp-palmares-comp-header">
                <span class="sp-palmares-comp-titre"><?php echo esc_html($comp['titre']); ?></span>
                <span class="sp-palmares-comp-date"><?php echo $comp['date']?date_create($comp['date'])->format('d/m/Y'):''; ?></span>
                <?php if($comp['categorie']): ?><span class="sp-palmares-comp-cat"><?php echo esc_html($comp['categorie']); ?></span><?php endif; ?>
            </div>
            <div class="sp-palmares-epreuves">
                <?php foreach($comp['epreuves'] as $ep):
                    $cfg=$medal_cfg[$ep->medaille??'']??null; ?>
                <div class="sp-palmares-card<?php echo $cfg?' sp-palmares-card-medal':''; ?>"
                     <?php echo $cfg?'style="background:'.$cfg['bg'].';border-color:'.$cfg['border'].';color:'.$cfg['color'].';"':''; ?>>
                    <div class="sp-palmares-medal-emoji"><?php echo $cfg?$cfg['emoji']:'🎖️'; ?></div>
                    <div class="sp-palmares-medal-label"><?php echo esc_html($cfg?$cfg['label']:'Participé'); ?></div>
                    <div class="sp-palmares-ep-nom"><?php echo esc_html($ep->epreuve_nom); ?></div>
                    <?php if($ep->score): ?><div class="sp-palmares-score"><?php echo esc_html($ep->score); ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach;
        return ob_get_clean();
    }

    private function render_presences_grid( $pres_list ) {
        ob_start(); ?>
        <div class="sp-membre-pres-grid">
        <?php foreach($pres_list as $p):
            $present=intval($p->present);
            $d=$p->date?date_create($p->date)->format('d/m'):'—'; ?>
        <div class="sp-membre-pres-item <?php echo $present?'sp-pres-ok':'sp-pres-ko'; ?>">
            <span class="sp-pres-icon"><?php echo $present?'✅':'❌'; ?></span>
            <span class="sp-pres-date"><?php echo esc_html($d); ?></span>
            <span class="sp-pres-label"><?php echo esc_html(mb_substr($p->titre,0,20)); ?></span>
        </div>
        <?php endforeach; ?>
        </div>
        <?php return ob_get_clean();
    }

        /* ══════════════════════════════════════════════════════════
       AJAX ADMIN : renvoyer / générer tokens
    ══════════════════════════════════════════════════════════ */

    public function resend_token_ajax() {
        check_ajax_referer('sp_cal_admin_nonce','nonce');
        if (!current_user_can(SP_Cal_Roles::CAP_GESTION_ADHESIONS)) wp_send_json_error('Accès refusé',403);
        $id   = intval($_POST['eleve_id'] ?? 0);
        $sent = $this->generate_and_send($id, true);
        wp_send_json_success($sent ? 'Email envoyé.' : 'Envoi impossible (email manquant ?).');
    }

    public function generate_all_tokens_ajax() {
        check_ajax_referer('sp_cal_admin_nonce','nonce');
        if (!current_user_can(SP_Cal_Roles::CAP_GESTION_ADHESIONS)) wp_send_json_error('Accès refusé',403);
        global $wpdb;
        $tel    = $this->db->table_eleves();
        $eleves = $wpdb->get_results("SELECT id FROM $tel WHERE actif=1 AND (token IS NULL OR token='')");
        $sent   = 0;
        foreach ($eleves as $el) {
            if ($this->generate_and_send($el->id)) $sent++;
        }
        wp_send_json_success($sent . ' token(s) générés et envoyés.');
    }

    /* ──────────────────────────────────────────────
       PAGE DE POINTAGE PUBLIQUE
    ────────────────────────────────────────────── */
    private function render_pointage_page() {
        $pin_saisi = sanitize_text_field($_GET['pin'] ?? '');
        $pin_valide = get_option('sp_cal_pointage_pin', '');
        if ($pin_saisi !== $pin_valide || !$pin_valide) {
            wp_die('Accès non autorisé — PIN invalide.', 'Pointage TKD', array('response'=>403));
        }
        $ajaxurl = admin_url('admin-ajax.php');
        get_header();
        ?>
        <style>
        .spt-wrap *,.spt-wrap *::before,.spt-wrap *::after{box-sizing:border-box;}
        .spt-page-bg{background:#f1f5f9;}
        .spt-wrap{max-width:480px;margin:0 auto;padding:12px 16px 40px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}
        /* En-tête */
        .spt-header{text-align:center;padding:16px 0 10px;}
        .spt-header h1{font-size:20px;font-weight:700;margin:4px 0 2px;color:#111;}
        .spt-header p{color:#64748b;font-size:13px;margin:0;}
        /* Sélecteur cours */
        .spt-box{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px;margin-bottom:14px;}
        .spt-box-title{font-weight:700;font-size:13px;color:#374151;margin-bottom:12px;}
        .spt-input{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 12px;font-size:14px;outline:none;background:#fff;}
        .spt-input:focus{border-color:#0f70b7;}
        .spt-cours-list{display:flex;flex-direction:column;gap:6px;margin-top:8px;}
        .spt-cours-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border:2px solid #e5e7eb;border-radius:10px;cursor:pointer;transition:all .15s;}
        .spt-cours-item:hover{border-color:#0f70b7;background:#f0f7ff;}
        .spt-cours-item.active{border-color:#0f70b7;background:#eff6ff;}
        .spt-cours-item.en-cours{border-color:#16a34a;background:#f0fdf4;}
        .spt-cours-heure{font-size:12px;font-weight:700;color:#64748b;width:80px;flex-shrink:0;}
        .spt-cours-item.en-cours .spt-cours-heure{color:#16a34a;}
        .spt-cours-titre{font-size:14px;font-weight:600;color:#111;flex:1;}
        .spt-cours-cat{font-size:11px;color:#64748b;}
        .spt-badge-live{background:#16a34a;color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;letter-spacing:.3px;}
        .spt-badge-retro{background:#f59e0b;color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;}
        /* Scanner */
        .spt-scanner-wrap{background:#111;border-radius:14px;overflow:hidden;margin-bottom:14px;}
        .spt-scanner-head{padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;justify-content:space-between;}
        .spt-scanner-head span{color:#fff;font-weight:600;font-size:14px;}
        .spt-scanner-cours{color:#ffdd0e;font-size:12px;max-width:200px;text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .spt-video-wrap{position:relative;}
        .spt-video-wrap video{width:100%;display:block;}
        .spt-crosshair{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;}
        .spt-crosshair-box{width:200px;height:200px;border:3px solid #ffdd0e;border-radius:12px;box-shadow:0 0 0 9999px rgba(0,0,0,.45);}
        .spt-scanner-foot{padding:10px 16px;display:flex;align-items:center;justify-content:space-between;gap:8px;}
        /* Boutons */
        .spt-btn{border:none;border-radius:10px;padding:14px;font-size:15px;font-weight:700;cursor:pointer;width:100%;display:block;text-align:center;}
        .spt-btn-blue{background:#0f70b7;color:#fff;}
        .spt-btn-blue:disabled{background:#93c5fd;cursor:not-allowed;}
        .spt-btn-ghost{background:rgba(255,255,255,.1);color:#fff;font-size:13px;padding:7px 14px;width:auto;}
        .spt-btn-sm{background:#f1f5f9;color:#374151;font-size:13px;padding:8px 14px;width:auto;border-radius:8px;}
        /* Résultat scan */
        .spt-result{border-radius:12px;padding:16px;text-align:center;font-size:18px;font-weight:700;margin-bottom:14px;display:none;}
        .spt-result-sub{font-size:13px;font-weight:400;margin-top:4px;}
        .spt-result-time{font-size:11px;font-weight:400;opacity:.7;margin-top:2px;}
        /* Historique */
        .spt-hist-title{font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;}
        .spt-hist-row{display:flex;justify-content:space-between;align-items:center;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:8px 12px;font-size:14px;margin-bottom:6px;}
        .spt-hist-nom{font-weight:600;color:#166534;}
        .spt-hist-heure{color:#4ade80;font-size:12px;}
        /* Modal rétroactif */
        .spt-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;display:flex;align-items:flex-end;justify-content:center;}
        .spt-modal{background:#fff;border-radius:18px 18px 0 0;width:100%;max-width:480px;max-height:85vh;display:flex;flex-direction:column;padding-bottom:env(safe-area-inset-bottom);}
        .spt-modal-head{padding:16px 20px 12px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
        .spt-modal-title{font-size:16px;font-weight:700;color:#111;}
        .spt-modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:#9ca3af;padding:0 4px;}
        .spt-modal-body{overflow-y:auto;flex:1;padding:12px 20px;}
        .spt-modal-foot{padding:12px 20px;border-top:1px solid #f1f5f9;flex-shrink:0;}
        .spt-retro-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f1f5f9;}
        .spt-retro-row:last-child{border-bottom:none;}
        .spt-retro-chk{width:20px;height:20px;accent-color:#0f70b7;cursor:pointer;flex-shrink:0;}
        .spt-retro-info{flex:1;}
        .spt-retro-date{font-size:11px;color:#64748b;}
        .spt-retro-titre{font-size:14px;font-weight:600;color:#111;}
        .spt-retro-heure{font-size:12px;color:#9ca3af;}
        .spt-retro-done{opacity:.4;}
        .spt-retro-done-badge{font-size:10px;color:#16a34a;font-weight:600;}
        </style>

        <div class="spt-page-bg"><div class="spt-wrap">
            <div class="spt-header">
                <div style="font-size:28px;">📡</div>
                <h1>Pointage des présences</h1>
                <p><?php echo esc_html(get_option('blogname','')); ?></p>
            </div>

            <!-- Sélecteur de cours -->
            <div class="spt-box" id="spt-cours-box">
                <div class="spt-box-title">📅 Sélectionner un cours</div>
                <input type="date" id="spt-date" class="spt-input" value="<?php echo date('Y-m-d'); ?>"
                       style="margin-bottom:10px;">
                <div id="spt-cours-list" class="spt-cours-list">
                    <div style="color:#9ca3af;font-size:13px;text-align:center;padding:8px;">Chargement…</div>
                </div>
            </div>

            <!-- Bouton démarrer (visible une fois un cours sélectionné) -->
            <button class="spt-btn spt-btn-blue" id="spt-start-btn" onclick="sptStart()" style="display:none;margin-bottom:14px;">
                📷 Démarrer le scanner
            </button>

            <!-- Scanner -->
            <div class="spt-scanner-wrap" id="spt-scanner" style="display:none;">
                <div class="spt-scanner-head">
                    <span>📷 Scanner un QR Code</span>
                    <span class="spt-scanner-cours" id="spt-scanner-label"></span>
                </div>
                <div class="spt-video-wrap">
                    <video id="spt-video" playsinline autoplay></video>
                    <canvas id="spt-canvas" style="display:none;"></canvas>
                    <div class="spt-crosshair"><div class="spt-crosshair-box"></div></div>
                </div>
                <div class="spt-scanner-foot">
                    <span style="color:rgba(255,255,255,.4);font-size:12px;" id="spt-scan-count">0 pointé(s)</span>
                    <button class="spt-btn spt-btn-ghost" onclick="sptStop()">⏹ Arrêter</button>
                </div>
            </div>

            <!-- Résultat scan -->
            <div class="spt-result" id="spt-result"></div>

            <!-- Historique -->
            <div id="spt-hist" style="display:none;">
                <div class="spt-hist-title">Présences enregistrées</div>
                <div id="spt-hist-list"></div>
            </div>
        </div>

        <!-- Modal rétroactif -->
        <div class="spt-modal-overlay" id="spt-modal" style="display:none;" onclick="sptModalClose(event)">
            <div class="spt-modal">
                <div class="spt-modal-head">
                    <span class="spt-modal-title" id="spt-modal-title">Saisie rétroactive</span>
                    <button class="spt-modal-close" onclick="sptCloseModal()">✕</button>
                </div>
                <div class="spt-modal-body" id="spt-modal-body">
                    <div style="text-align:center;padding:20px;color:#9ca3af;">Chargement…</div>
                </div>
                <div class="spt-modal-foot">
                    <button class="spt-btn spt-btn-blue" id="spt-modal-submit" onclick="sptLotSubmit()">
                        ✅ Enregistrer la sélection
                    </button>
                </div>
            </div>
        </div>

        <script>
(function() {
    var cdns = [
        'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js',
        'https://unpkg.com/jsqr@1.4.0/dist/jsQR.js'
    ];
    var idx = 0;
    function tryLoad() {
        if (idx >= cdns.length) return;
        var s = document.createElement('script');
        s.src = cdns[idx++];
        s.onerror = tryLoad;
        document.head.appendChild(s);
    }
    tryLoad();
})();
</script>
        <script>
        var SPT = {
            pin:        '<?php echo esc_js($pin_saisi); ?>',
            ajaxurl:    '<?php echo esc_js($ajaxurl); ?>',
            slotId:     0,
            slotDate:   '',
            eventLabel: '',
            isLive:     false,   // cours en cours à l'heure actuelle
            scanning:   false,
            stream:     null,
            scanned:    {},      // token → nom (anti-doublon session)
            scanCount:  0,
            timer:      null,
            retroToken: null,    // token en attente de saisie rétroactive
        };

        /* ── Helpers ─────────────────────────────── */
        function sptNow() {
            var d = new Date();
            return d.getHours() * 60 + d.getMinutes(); // minutes depuis minuit
        }
        function sptHHMM(str) {
            // "18h30" → 18*60+30
            if (!str) return null;
            var m = str.match(/(\d+)h(\d*)/);
            if (!m) return null;
            return parseInt(m[1]) * 60 + (parseInt(m[2]) || 0);
        }
        function sptFmtHeure(str) {
            if (!str) return '';
            return str.replace('h', 'h');
        }
        function sptFmtDate(ymd) {
            if (!ymd) return '';
            var p = ymd.split('-');
            return p[2] + '/' + p[1] + '/' + p[0];
        }
        function sptTimeNow() {
            var d = new Date();
            return d.getHours().toString().padStart(2,'0') + ':' + d.getMinutes().toString().padStart(2,'0');
        }

        /* ── Chargement des cours ─────────────────── */
        function sptLoadCours() {
            var date = document.getElementById('spt-date').value;
            var list = document.getElementById('spt-cours-list');
            list.innerHTML = '<div style="color:#9ca3af;font-size:13px;text-align:center;padding:8px;">Chargement…</div>';
            document.getElementById('spt-start-btn').style.display = 'none';
            SPT.eventId = 0;

            var fd = new FormData();
            fd.append('action', 'sp_pointage_cours');
            fd.append('pin', SPT.pin);
            fd.append('date', date);

            fetch(SPT.ajaxurl, {method:'POST', body:fd})
            .then(function(r){return r.json();})
            .then(function(r){
                list.innerHTML = '';
                if (!r.success || !r.data || !r.data.length) {
                    list.innerHTML = '<div style="color:#9ca3af;font-size:13px;text-align:center;padding:8px;">Aucun cours ce jour</div>';
                    return;
                }
                var now = sptNow();
                var isToday = (date === new Date().toISOString().slice(0,10));
                var autoId = 0, autoLabel = '', autoLive = false;

                r.data.forEach(function(c) {
                    var debut = sptHHMM(c.heure_debut);
                    var fin   = sptHHMM(c.heure_fin);
                    var live  = isToday && debut !== null && fin !== null && now >= debut && now <= fin;
                    var retro = isToday && debut !== null && now > (fin || debut);
                    // Favoriser le cours en cours, sinon le plus proche
                    if (live && !autoLive) { autoId = c.slot_id; autoLabel = c.heure_debut + ' ' + c.titre; autoLive = true; }
                    if (!autoLive && !autoId) { autoId = c.slot_id; autoLabel = c.heure_debut + ' ' + c.titre; }

                    var item = document.createElement('div');
                    item.className = 'spt-cours-item' + (live ? ' en-cours' : '');
                    item.dataset.slotId = c.slot_id;
                    item.dataset.date   = c.date;
                    item.dataset.live   = live ? '1' : '0';
                    item.dataset.label  = (c.heure_debut||'') + ' ' + c.titre + (c.categorie ? ' ('+c.categorie+')' : '');
                    item.innerHTML =
                        '<div class="spt-cours-heure">' + (sptFmtHeure(c.heure_debut)||'—') + '</div>'
                        + '<div style="flex:1;">'
                        +   '<div class="spt-cours-titre">' + c.titre + '</div>'
                        +   (c.categorie ? '<div class="spt-cours-cat">' + c.categorie + '</div>' : '')
                        + '</div>'
                        + (live  ? '<span class="spt-badge-live">EN COURS</span>'  : '')
                        + (retro && !live ? '<span class="spt-badge-retro">PASSÉ</span>' : '');
                    item.addEventListener('click', function(){ sptSelectCours(this); });
                    list.appendChild(item);
                });

                // Auto-sélection
                if (autoId) {
                    var toSelect = list.querySelector('[data-slot-id="'+autoId+'"]');
                    if (toSelect) sptSelectCours(toSelect);
                }
            });
        }

        function sptSelectCours(el) {
            document.querySelectorAll('.spt-cours-item').forEach(function(i){ i.classList.remove('active'); });
            el.classList.add('active');
            SPT.slotId     = parseInt(el.dataset.slotId);
            SPT.slotDate   = el.dataset.date;
            SPT.eventLabel = el.dataset.label;
            SPT.isLive     = el.dataset.live === '1';
            document.getElementById('spt-start-btn').style.display = 'block';
            document.getElementById('spt-scanner-label').textContent = el.dataset.label;
        }

        /* ── Scanner ──────────────────────────────── */
        function sptStart() {
            if (!SPT.slotId) return;
            document.getElementById('spt-scanner').style.display = 'block';
            document.getElementById('spt-start-btn').style.display = 'none';
            var video = document.getElementById('spt-video');
            navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}})
            .then(function(stream){
                SPT.stream = stream;
                video.srcObject = stream;
                SPT.scanning = true;
                sptTick();
            })
            .catch(function(){ alert('Impossible d\'accéder à la caméra.'); });
        }

        function sptStop() {
            SPT.scanning = false;
            if (SPT.stream) SPT.stream.getTracks().forEach(function(t){t.stop();});
            document.getElementById('spt-scanner').style.display = 'none';
            document.getElementById('spt-start-btn').style.display = SPT.slotId ? 'block' : 'none';
        }

        // BarcodeDetector natif (iOS 17+, Chrome Android) ou jsQR en fallback
        var sptDetector = null;
        if (typeof BarcodeDetector !== 'undefined') {
            try { sptDetector = new BarcodeDetector({formats:['qr_code']}); } catch(e) {}
        }

        function sptTick() {
            if (!SPT.scanning) return;
            var video  = document.getElementById('spt-video');
            var canvas = document.getElementById('spt-canvas');
            // iOS : readyState >= 2 suffit
            if (video.readyState < 2 || video.videoWidth === 0) {
                requestAnimationFrame(sptTick); return;
            }
            // Priorité : BarcodeDetector natif (iOS 17+)
            if (sptDetector) {
                sptDetector.detect(video).then(function(codes) {
                    if (!SPT.scanning) return;
                    if (codes.length > 0) { sptHandleQR(codes[0].rawValue); }
                    else { requestAnimationFrame(sptTick); }
                }).catch(function() { requestAnimationFrame(sptTick); });
                return;
            }
            // Fallback jsQR
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            if (typeof jsQR !== 'undefined' && img.width > 0) {
                var code = jsQR(img.data, img.width, img.height);
                if (code && code.data) { sptHandleQR(code.data); return; }
            }
            requestAnimationFrame(sptTick);
        }

        /* ── Traitement QR ────────────────────────── */
        function sptHandleQR(url) {
            var m = url.match(/[?&]token=([^&]+)/);
            if (!m) {
                sptShowResult('❓ QR Code non reconnu', '#f59e0b', '', '');
                setTimeout(sptTick, 2000); return;
            }
            var token = decodeURIComponent(m[1]);

            // Anti-doublon session
            if (SPT.scanned[token]) {
                sptShowResult('⚠️ ' + SPT.scanned[token], '#f59e0b', 'Déjà scanné cette session', '');
                setTimeout(sptTick, 1500); return;
            }

            var fd = new FormData();
            fd.append('action',   'sp_pointage_scan');
            fd.append('pin',      SPT.pin);
            fd.append('token',    token);
            fd.append('slot_id',  SPT.slotId);
            fd.append('date',     SPT.slotDate);

            fetch(SPT.ajaxurl, {method:'POST', body:fd})
            .then(function(r){return r.json();})
            .then(function(r){
                if (!r.success) {
                    sptShowResult('❌ Élève non trouvé', '#dc2626', '', '');
                    setTimeout(sptTick, 2000); return;
                }
                var nom    = r.data.nom;
                var status = r.data.status;
                SPT.scanned[token] = nom;

                if (status === 'already') {
                    sptShowResult('⚠️ ' + nom, '#f59e0b', 'Déjà pointé pour ce cours', sptTimeNow());
                    setTimeout(sptTick, 2000); return;
                }

                // Présence enregistrée
                SPT.scanCount++;
                document.getElementById('spt-scan-count').textContent = SPT.scanCount + ' pointé(s)';

                if (SPT.isLive) {
                    // Cours en cours — confirmation simple
                    sptShowResult('✅ ' + nom, '#16a34a', 'Présent · ' + SPT.eventLabel, sptTimeNow());
                    sptAddHistory(nom, '✅');
                } else {
                    // Hors horaire — proposer saisie rétroactive supplémentaire
                    sptShowResult('✅ ' + nom, '#16a34a', 'Pointé (hors horaire) · ' + SPT.eventLabel, sptTimeNow());
                    sptAddHistory(nom, '🕐');
                    // Proposer d'autres cours rétroactifs
                    sptOpenRetro(token, nom);
                    return; // ne pas relancer le ticker, le modal prend le relais
                }
                setTimeout(sptTick, 2000);
            })
            .catch(function(){
                sptShowResult('❌ Erreur réseau', '#dc2626', '', '');
                setTimeout(sptTick, 2000);
            });
        }

        /* ── Affichage résultat ───────────────────── */
        function sptShowResult(titre, color, sub, heure) {
            var el = document.getElementById('spt-result');
            el.style.display      = 'block';
            el.style.background   = color + '18';
            el.style.border       = '2px solid ' + color;
            el.style.color        = color;
            el.innerHTML = titre
                + (sub   ? '<div class="spt-result-sub">'  + sub   + '</div>' : '')
                + (heure ? '<div class="spt-result-time">' + heure + '</div>' : '');
            clearTimeout(SPT.timer);
            SPT.timer = setTimeout(function(){ el.style.display = 'none'; }, 3500);
        }

        function sptAddHistory(nom, icon) {
            var hist = document.getElementById('spt-hist');
            var list = document.getElementById('spt-hist-list');
            hist.style.display = 'block';
            var row = document.createElement('div');
            row.className = 'spt-hist-row';
            row.innerHTML = '<span class="spt-hist-nom">' + (icon||'✅') + ' ' + nom + '</span>'
                          + '<span class="spt-hist-heure">' + sptTimeNow() + '</span>';
            list.prepend(row);
        }

        /* ── Modal rétroactif ─────────────────────── */
        function sptOpenRetro(token, nom) {
            SPT.retroToken = token;
            document.getElementById('spt-modal-title').textContent = '🕐 Saisie rétroactive — ' + nom;
            document.getElementById('spt-modal-body').innerHTML = '<div style="text-align:center;padding:20px;color:#9ca3af;">Chargement…</div>';
            document.getElementById('spt-modal').style.display = 'flex';

            var fd = new FormData();
            fd.append('action', 'sp_pointage_cours_eleve');
            fd.append('pin',    SPT.pin);
            fd.append('token',  token);

            fetch(SPT.ajaxurl, {method:'POST', body:fd})
            .then(function(r){return r.json();})
            .then(function(r){
                if (!r.success) {
                    document.getElementById('spt-modal-body').innerHTML = '<p style="color:#dc2626;">Erreur de chargement.</p>';
                    return;
                }
                var cours = r.data.cours;
                if (!cours || !cours.length) {
                    document.getElementById('spt-modal-body').innerHTML = '<p style="color:#9ca3af;text-align:center;padding:16px;">Aucun cours disponible.</p>';
                    return;
                }
                var html = '<p style="font-size:12px;color:#64748b;margin:0 0 12px;">Cochez les cours supplémentaires à pointer :</p>';
                cours.forEach(function(c, i) {
                    var done = !!c.deja_pointe;
                    html += '<label class="spt-retro-row' + (done?' spt-retro-done':'') + '" for="spt-rc-'+i+'">'
                        + '<input type="checkbox" class="spt-retro-chk" id="spt-rc-'+i+'" data-slot-id="'+c.slot_id+'" data-date="'+c.date+'"'
                        + (done ? ' disabled checked' : '') + '>'
                        + '<div class="spt-retro-info">'
                        +   '<div class="spt-retro-date">' + sptFmtDate(c.date) + (done ? ' <span class="spt-retro-done-badge">✓ déjà pointé</span>' : '') + '</div>'
                        +   '<div class="spt-retro-titre">' + c.titre + '</div>'
                        +   '<div class="spt-retro-heure">' + (c.heure_debut||'') + (c.heure_fin?' → '+c.heure_fin:'') + (c.categorie?' · '+c.categorie:'') + '</div>'
                        + '</div>'
                        + '</label>';
                });
                document.getElementById('spt-modal-body').innerHTML = html;
            });
        }

        function sptCloseModal() {
            document.getElementById('spt-modal').style.display = 'none';
            SPT.retroToken = null;
            // Reprendre le scanner
            setTimeout(sptTick, 500);
        }

        function sptModalClose(e) {
            if (e.target === document.getElementById('spt-modal')) sptCloseModal();
        }

        function sptLotSubmit() {
            var checked = document.querySelectorAll('.spt-retro-chk:checked:not(:disabled)');
            if (!checked.length) { sptCloseModal(); return; }
            var items = [];
            checked.forEach(function(c){
                items.push({ slot_id: c.dataset.slotId, date: c.dataset.date });
            });

            var btn = document.getElementById('spt-modal-submit');
            btn.disabled = true; btn.textContent = 'Enregistrement…';

            var fd = new FormData();
            fd.append('action', 'sp_pointage_lot');
            fd.append('pin',    SPT.pin);
            fd.append('token',  SPT.retroToken);
            items.forEach(function(it, i){
                fd.append('items['+i+'][slot_id]', it.slot_id);
                fd.append('items['+i+'][date]',    it.date);
            });

            fetch(SPT.ajaxurl, {method:'POST', body:fd})
            .then(function(r){return r.json();})
            .then(function(r){
                btn.disabled = false;
                if (r.success) {
                    btn.textContent = '✅ ' + r.data.nb + ' présence(s) enregistrée(s)';
                    setTimeout(sptCloseModal, 1200);
                } else {
                    btn.textContent = '✅ Enregistrer la sélection';
                    alert('Erreur : ' + (r.data||''));
                }
            });
        }

        /* ── Init ─────────────────────────────────── */
        document.getElementById('spt-date').addEventListener('change', sptLoadCours);
        sptLoadCours();
        </script>
        </div></div><!-- /.spt-wrap /.spt-page-bg -->
        <?php
        get_footer();
    }



    /**
     * Traite le lien de réponse rapide envoyé par email.
     * URL : /?sp_insc_token=XXX&sp_insc_event=YY&sp_insc_rep=oui|non
     */
    private function handle_quick_reply_inscription() {
        $token    = sanitize_text_field( wp_unslash( $_GET['sp_insc_token'] ?? '' ) );
        $event_id = intval( $_GET['sp_insc_event'] ?? 0 );
        $reponse  = sanitize_text_field( wp_unslash( $_GET['sp_insc_rep']   ?? '' ) );

        if ( ! $token || ! $event_id || ! in_array( $reponse, array( 'oui', 'non' ), true ) ) {
            wp_die( 'Lien invalide.', 'Inscription', array( 'response' => 400 ) );
        }

        global $wpdb;
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $tel WHERE token=%s AND actif=1 LIMIT 1", $token
        ) );
        if ( ! $el ) wp_die( 'Élève introuvable.', 'Inscription', array( 'response' => 404 ) );

        $te    = $this->db->table_events();
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $te WHERE id=%d", $event_id ) );
        if ( ! $event ) wp_die( 'Événement introuvable.', 'Inscription', array( 'response' => 404 ) );

        if ( ! method_exists( $this->db, 'save_inscription' ) ) {
            wp_die( 'Système d&#39;inscription non disponible.', 'Inscription', array( 'response' => 500 ) );
        }

        $statut = ( $reponse === 'oui' ) ? 'inscrit' : 'refuse';
        $this->db->save_inscription( $event_id, intval( $el->id ), $statut, '' );

        $nom_club = get_option( 'blogname', 'Club' );
        $date_obj = $event->date ? date_create( $event->date ) : null;
        $date_fmt = $date_obj ? $date_obj->format( 'd/m/Y' ) : $event->date;
        $ico      = ( $statut === 'inscrit' ) ? '✅' : '❌';
        $msg      = ( $statut === 'inscrit' ) ? 'Vous êtes inscrit(e) !' : 'Vous avez décliné.';
        $fiche_url = home_url( '/?token=' . rawurlencode( $el->token ) );

        wp_enqueue_style( 'sp-cal-calendar', SP_CAL_PRO_URL . 'assets/css/calendar.css', array(), SP_CAL_PRO_VERSION );
        get_header();
        echo '<div style="max-width:520px;margin:60px auto;padding:0 20px;text-align:center;font-family:sans-serif;">';
        echo '<div style="font-size:60px;line-height:1;margin-bottom:16px;">' . $ico . '</div>';
        echo '<h2 style="margin:0 0 8px;font-size:22px;">' . esc_html( $msg ) . '</h2>';
        echo '<p style="color:#6b7280;margin:0 0 6px;">' . esc_html( $event->titre ) . '</p>';
        echo '<p style="color:#9ca3af;font-size:14px;margin:0 0 28px;">📅 ' . esc_html( $date_fmt ) . '</p>';
        echo '<a href="' . esc_url( $fiche_url ) . '" style="display:inline-block;background:#111;color:#fff;text-decoration:none;border-radius:8px;padding:12px 24px;font-weight:600;font-size:14px;">Voir ma fiche membre</a>';
        echo '<p style="margin-top:24px;font-size:12px;color:#d1d5db;">' . esc_html( $nom_club ) . '</p>';
        echo '</div>';
        get_footer();
    }

} // end class SpCalPro_Token

endif; // class_exists SpCalPro_Token

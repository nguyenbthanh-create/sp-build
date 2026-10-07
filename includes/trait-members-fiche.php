<?php
/**
 * Fiche élève dans l'administration (page « Fiche élève »).
 *
 * Méthodes de SP_Cal_Members déplacées telles quelles depuis class-admin-members.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SP_Cal_Members_Fiche; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SP_Cal_Members_Fiche {


    /* ══════════════════════════════════════════════════════════
       PAGE FICHE ELEVE
    ══════════════════════════════════════════════════════════ */

    public function page_fiche_eleve() {
        if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé' );

        global $wpdb;
        $tel      = $this->db->table_eleves();
        $eleve_id = intval( $_GET['eleve_id'] ?? 0 );
        if ( ! $eleve_id ) wp_die( 'Élève introuvable.' );

        $el = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tel WHERE id=%d", $eleve_id ) );
        if ( ! $el ) wp_die( 'Élève introuvable.' );

        // Données calculées
        $extra       = $el->extra_data ? ( json_decode( $el->extra_data, true ) ?: array() ) : array();
        $grades_hist = $extra['grades'] ?? array();   // [ 'JJ/MM/AAAA' => 'grade' ] — import CSV
        $presences   = $this->db->get_presences_eleve( $eleve_id );
        $examens             = $this->db->get_examens_eleve( $eleve_id );
        $examens_disponibles = $this->db->get_examens_disponibles( $eleve_id );

        // Séparer présences normales et examens
        $presences_cours = array_filter( $presences, function($p){ return $p->type !== 'examen'; } );

        $nb_present = 0; $nb_absent = 0;
        foreach ( $presences_cours as $p ) {
            if ( intval($p->present) ) $nb_present++; else $nb_absent++;
        }
        $nb_total = $nb_present + $nb_absent;
        $taux     = $nb_total ? round( $nb_present / $nb_total * 100 ) : null;

        // Age calculé
        $age = null;
        if ( $el->annee_naissance && $el->date_naissance ) {
            $parts = explode( '/', $el->date_naissance );
            if ( count($parts) === 2 ) {
                $bday = DateTime::createFromFormat( 'd/m/Y', $parts[0].'/'.$parts[1].'/'.$el->annee_naissance );
                if ( $bday ) $age = $bday->diff( new DateTime() )->y;
            }
        }

        // Palmarès structuré depuis la DB
        $palmares_rows      = $this->db->get_palmares_eleve( $eleve_id );
        $palmares_stats     = $this->db->get_palmares_points_eleve( $eleve_id );
        $comps_disponibles  = $this->db->get_comps_disponibles( $eleve_id );
        $medal_pts          = $this->db->get_medal_points();

        // Grouper par compétition (event_id)
        $palmares_by_comp = array();
        foreach ( $palmares_rows as $pr ) {
            $eid = intval($pr->event_id);
            if ( ! isset( $palmares_by_comp[$eid] ) ) {
                $palmares_by_comp[$eid] = array(
                    'titre'    => $pr->comp_titre,
                    'date'     => $pr->date,
                    'categorie'=> $pr->categorie,
                    'epreuves' => array(),
                );
            }
            $palmares_by_comp[$eid]['epreuves'][] = $pr;
        }
        ?>
        <?php
        // URLs
        $back_url  = admin_url( 'admin.php?page=sp-cal-eleves' );
        $edit_url  = admin_url( 'admin.php?page=sp-cal-eleves&sp_edit_eleve=' . $eleve_id );

        // Couleur catégorie saisie
        $cat_colors = json_decode( get_option( 'sp_cal_cat_colors', '{}' ), true ) ?: array();
        $cat_color  = $cat_colors[ $el->categorie_saisie ]['color'] ?? '#2271b1';
        $cat_icon   = $cat_colors[ $el->categorie_saisie ]['icon']  ?? '';
        ?>
        <div class="wrap sp-cal-wrap sp-fiche-wrap">

        <!-- Barre de navigation -->
        <div class="sp-fiche-nav">
            <a href="<?php echo esc_url($back_url); ?>" class="button">
                ← Retour à la liste
            </a>
            <div class="sp-fiche-nav-title">
                <?php if($el->categorie_saisie): ?>
                <span class="sp-fiche-pill" style="background:<?php echo esc_attr($cat_color); ?>;">
                    <?php echo esc_html( ($cat_icon ? $cat_icon . ' ' : '') . $this->db->label_discipline($el->categorie_saisie) ); ?>
                </span>
                <?php endif; ?>
                <?php if($el->categorie_age): ?>
                <span class="sp-fiche-pill sp-fiche-pill-age"><?php echo esc_html($el->categorie_age); ?></span>
                <?php endif; ?>
                <?php if($el->saison): ?>
                <span class="sp-muted" style="font-size:13px;"><?php echo esc_html($el->saison); ?></span>
                <?php endif; ?>
            </div>
            <a href="<?php echo esc_url($edit_url); ?>" class="button button-primary">
                ✏️ Modifier
            </a>
            <a href="<?php echo esc_url( wp_nonce_url(
                add_query_arg(array('sp_cal_print'=>'fiche_eleve','eleve_id'=>$eleve_id), home_url('/') ),
                'sp_cal_print'
            ) ); ?>" target="_blank" class="button">
                🖨️ Imprimer la fiche
            </a>
            <?php if($el->licence || $el->email || $el->email_parent): ?>
            <button type="button" id="sp-btn-resend-token" class="button"
                    data-eleve-id="<?php echo intval($eleve_id); ?>">
                📧 Renvoyer le lien d'accès
            </button>
            <span id="sp-token-result" style="font-size:12px;color:#15803d;display:none;"></span>
            <?php endif; ?>
        </div>

        <!-- En-tête identité -->
        <div class="sp-fiche-header">
            <div class="sp-fiche-avatar">
                <?php echo esc_html( mb_strtoupper( mb_substr($el->prenom, 0, 1) . mb_substr($el->nom, 0, 1) ) ); ?>
            </div>
            <div class="sp-fiche-identity">
                <h1><?php echo esc_html( $el->prenom . ' ' . mb_strtoupper($el->nom) ); ?></h1>
                <div class="sp-fiche-meta">
                    <?php if($el->date_naissance): ?>
                    <span>🎂 <?php
                        echo esc_html( $el->date_naissance . ($el->annee_naissance ? '/' . $el->annee_naissance : '') );
                        if($age) echo ' <span class="sp-muted">(' . $age . ' ans)</span>';
                    ?></span>
                    <?php endif; ?>
                    <?php if($el->licence): ?>
                    <span>🪪 <?php
                        echo esc_html($el->licence);
                        if ( ! intval($el->actif ?? 1) ) {
                            echo ' <span class="sp-lic-badge sp-lic-expired">❌ Inactif</span>';
                            if ($el->motif_inactif) echo ' <span class="sp-muted" style="font-size:11px;">— ' . esc_html($el->motif_inactif) . '</span>';
                        } else {
                            echo ' <span class="sp-lic-badge sp-lic-ok">✅ Actif</span>';
                            // Alerte saison sur la fiche (SpCalPro_DB::statut_saison_eleve)
                            $st_saison = SpCalPro_DB::statut_saison_eleve($el);
                            if ($st_saison['code'] === 'a_renouveler')
                                echo ' <span class="sp-lic-badge sp-lic-expired" title="' . esc_attr($st_saison['libelle']) . '">Renouvellement à faire</span>';
                            elseif ($st_saison['code'] === 'bientot')
                                echo ' <span class="sp-lic-badge sp-lic-expiring" title="Fin de saison dans '.$st_saison['jours'].' jours">⚠️ '.$st_saison['jours'].'j</span>';
                        }
                    ?></span>
                    <?php endif; ?>
                    <?php if($el->rang): ?>
                    <span class="sp-muted">Rang <?php echo intval($el->rang); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if($nb_total > 0): ?>
            <div class="sp-fiche-taux">
                <div class="sp-taux-circle" style="--pct:<?php echo $taux; ?>">
                    <svg viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="#e5e7eb" stroke-width="3"/>
                        <circle cx="18" cy="18" r="15.9" fill="none"
                            stroke="<?php echo $taux >= 75 ? '#22c55e' : ($taux >= 50 ? '#f59e0b' : '#ef4444'); ?>"
                            stroke-width="3"
                            stroke-dasharray="<?php echo round($taux * 1.0, 1); ?> 100"
                            stroke-dashoffset="25"
                            stroke-linecap="round"
                            transform="rotate(-90 18 18)"/>
                    </svg>
                    <span><?php echo $taux; ?>%</span>
                </div>
                <div class="sp-taux-label">
                    Présence<br>
                    <span class="sp-muted"><?php echo $nb_present; ?>/<?php echo $nb_total; ?> cours</span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Grille info + grades -->
        <div class="sp-fiche-grid">

            <!-- Colonne gauche : Informations -->
            <div class="sp-box">
                <h2>📋 Informations</h2>
                <table class="sp-fiche-info-table">
                    <?php
                    $row_info = function($label, $val, $link=false) {
                        if ( empty($val) ) return;
                        echo '<tr><th>' . $label . '</th><td>';
                        if ($link) echo '<a href="' . esc_attr($link) . '">' . esc_html($val) . '</a>';
                        else       echo esc_html($val);
                        echo '</td></tr>';
                    };
                    $row_info( 'Téléphone',    $el->telephone,    $el->telephone    ? 'tel:'    . $el->telephone    : false );
                    $row_info( 'Email',        $el->email,        $el->email        ? 'mailto:' . $el->email        : false );
                    $row_info( 'Email parent', $el->email_parent, $el->email_parent ? 'mailto:' . $el->email_parent : false );
                    ?>
                </table>
                <?php if ( empty($el->telephone) && empty($el->email) && empty($el->email_parent) ): ?>
                    <p class="sp-muted">Aucun contact renseigné.</p>
                <?php endif; ?>
            </div>

            <!-- Colonne droite : Grades -->
            <div class="sp-box">
                <h2>🥋 Grade actuel</h2>
                <?php if($el->grade): ?>
                <div class="sp-fiche-grade-current">
                    <span class="sp-fiche-grade-badge"><?php echo esc_html($el->grade); ?></span>
                </div>
                <p class="sp-muted" style="font-size:12px;margin-top:8px;">
                    L'historique complet des passages de grade est affiché ci-dessous.
                </p>
                <?php else: ?>
                    <p class="sp-muted">Aucun grade renseigné.</p>
                <?php endif; ?>
            </div>

        </div><!-- .sp-fiche-grid -->

        <?php
        // Préparer données palmarès une seule fois
        $has_palmares_db  = ! empty( $palmares_by_comp );
        $has_palmares_txt = ! empty( $el->palmares ) && ! $has_palmares_db;
        $med_icons  = array( 'or' => '🥇', 'argent' => '🥈', 'bronze' => '🥉' );
        $med_labels = array( 'or' => 'Or',  'argent' => 'Argent',  'bronze' => 'Bronze' );

        // Récupérer la liste de toutes les compétitions liées (source de vérité pour le bloc unifié)
        $comps_liees_unified = $this->db->get_comps_eleve( $eleve_id );
        ?>

        <!-- ══ BLOC UNIFIÉ : Compétitions & Palmarès ══════════ -->
        <div class="sp-box" id="sp-fiche-comp-link-box" data-eleve-id="<?php echo intval($eleve_id); ?>">

            <!-- En-tête avec titre + badge points + lien global -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
                <h2 style="margin:0;">🏆 Compétitions &amp; Palmarès</h2>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <?php if ( $palmares_stats['total_pts'] > 0 ) : ?>
                    <div class="sp-palmares-fiche-pts-badge">
                        <span class="sp-palmares-pts-total">🏅 <?php echo $palmares_stats['total_pts']; ?> pt<?php echo $palmares_stats['total_pts'] > 1 ? 's' : ''; ?></span>
                        <span class="sp-palmares-pts-detail sp-muted">
                            <?php
                            $badge_parts = array();
                            if ($palmares_stats['nb_or'])     $badge_parts[] = '🥇×' . $palmares_stats['nb_or'];
                            if ($palmares_stats['nb_argent']) $badge_parts[] = '🥈×' . $palmares_stats['nb_argent'];
                            if ($palmares_stats['nb_bronze']) $badge_parts[] = '🥉×' . $palmares_stats['nb_bronze'];
                            echo implode(' ', $badge_parts);
                            ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-palmares')); ?>"
                       style="font-size:12px;color:#6b7280;">→ Voir le palmarès global</a>
                </div>
            </div>

            <?php if ( empty($comps_liees_unified) ) : ?>
            <p class="sp-muted" id="sp-comp-link-empty-msg">Aucune compétition liée à cet élève.</p>

            <?php else : ?>

            <?php foreach ( $comps_liees_unified as $comp_row ) :
                $cid      = intval($comp_row->id);
                $d_obj    = $comp_row->date ? date_create($comp_row->date) : null;
                $date_f   = $d_obj ? $d_obj->format('d/m/Y') : $comp_row->date;
                $comp_url = admin_url('admin.php?page=sp-cal-palmares&vue=competition&event_id=' . $cid);
                // Épreuves + résultats pour cette compétition (depuis palmares_by_comp déjà calculé)
                $comp_data = $palmares_by_comp[$cid] ?? null;
                // Points de cette compétition
                $comp_pts = 0;
                if ($comp_data) {
                    foreach ($comp_data['epreuves'] as $ep_r) {
                        if ($ep_r->medaille && isset($medal_pts[$ep_r->medaille]))
                            $comp_pts += $medal_pts[$ep_r->medaille];
                    }
                }
            ?>
            <div class="sp-fiche-comp-unified" id="sp-comp-row-<?php echo $cid; ?>">

                <!-- Ligne de titre compétition -->
                <div class="sp-fiche-comp-unified-header">
                    <div style="display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap;">
                        <span style="font-size:12px;color:#6b7280;white-space:nowrap;">📅 <?php echo esc_html($date_f); ?></span>
                        <a href="<?php echo esc_url($comp_url); ?>" style="font-weight:700;color:#1e3a5f;text-decoration:none;font-size:14px;">
                            <?php echo esc_html($comp_row->titre); ?>
                        </a>
                        <?php if ($comp_row->categorie) : ?>
                        <span class="sp-badge-blue"><?php echo esc_html($comp_row->categorie); ?></span>
                        <?php endif; ?>
                        <?php if ($comp_pts > 0) : ?>
                        <span class="sp-palmares-fiche-comp-pts">+<?php echo $comp_pts; ?> pt<?php echo $comp_pts > 1 ? 's' : ''; ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="button button-small sp-btn-unlink-comp sp-btn-del"
                            data-event-id="<?php echo $cid; ?>"
                            title="Retirer cet élève de cette compétition">✕</button>
                </div>

                <!-- Tableau des épreuves + résultats -->
                <?php if ($comp_data && ! empty($comp_data['epreuves'])) : ?>
                <table class="sp-palmares-compact-table" style="margin-top:8px;">
                    <thead><tr>
                        <th>Épreuve</th>
                        <th style="width:100px;">Modalité</th>
                        <th style="width:120px;">Résultat</th>
                        <th style="width:45px;text-align:right;">Pts</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($comp_data['epreuves'] as $ep_row) :
                        $med    = $ep_row->medaille ?: '';
                        $ep_pts = ($med && isset($medal_pts[$med])) ? $medal_pts[$med] : 0;
                    ?>
                    <tr class="<?php echo $med ? 'sp-palm-ep-medal' : ''; ?>">
                        <td><strong><?php echo esc_html($ep_row->epreuve_nom); ?></strong></td>
                        <td>
                            <?php if ($ep_row->epreuve_modalite) : ?>
                                <span class="sp-muted" style="font-size:11px;"><?php echo esc_html($ep_row->epreuve_modalite); ?></span>
                            <?php else : ?>
                                <span class="sp-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($med) : ?>
                                <span class="sp-palm-medal-chip sp-palm-medal-<?php echo esc_attr($med); ?>">
                                    <?php echo $med_icons[$med]; ?> <?php echo $med_labels[$med]; ?>
                                </span>
                                <?php if ($ep_row->score) echo '<span class="sp-muted" style="font-size:10px;display:block;margin-top:1px;">' . esc_html($ep_row->score) . '</span>'; ?>
                            <?php elseif ($ep_row->score) : ?>
                                <span class="sp-muted" style="font-size:12px;"><?php echo esc_html($ep_row->score); ?></span>
                            <?php else : ?>
                                <span class="sp-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;font-weight:700;font-size:13px;color:<?php echo $ep_pts > 0 ? '#15803d' : '#9ca3af'; ?>;">
                            <?php echo $ep_pts > 0 ? '+' . $ep_pts : '—'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else : ?>
                <p class="sp-muted" style="font-size:12px;margin:6px 0 0 4px;">
                    Aucune épreuve enregistrée pour cette compétition.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-palmares&vue=competition&event_id=' . $cid)); ?>" style="font-size:11px;">Saisir les résultats →</a>
                </p>
                <?php endif; ?>

            </div><!-- .sp-fiche-comp-unified -->
            <?php endforeach; ?>

            <?php if ( $has_palmares_txt ) : ?>
            <div style="padding:10px;background:#f9fafb;border-radius:6px;font-size:13px;line-height:1.7;margin-bottom:12px;">
                <?php echo nl2br(esc_html($el->palmares)); ?>
            </div>
            <p class="sp-muted" style="font-size:11px;">💡 Palmarès texte libre (ancien format). Utilisez les compétitions du calendrier pour un palmarès structuré.</p>
            <?php endif; ?>

            <?php endif; // comps_liees_unified not empty ?>

            <!-- ── Lier à une compétition ─────────────────────── -->
            <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f1;">
                <strong style="font-size:13px;">➕ Lier à une compétition</strong>
                <?php if ( empty($comps_disponibles) ) : ?>
                    <p class="sp-muted" style="margin:6px 0 0;">
                        Aucune compétition disponible.
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-pro')); ?>">Créer un événement "🏆 Compétition" dans le calendrier</a>, puis revenez sur cette fiche.
                    </p>
                <?php else : ?>
                    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;align-items:center;">
                        <select id="sp-link-comp-select" class="sp-input" style="min-width:260px;height:32px;">
                            <option value="">— Choisir une compétition —</option>
                            <?php foreach ( $comps_disponibles as $cv ) :
                                $cv_date = $cv->date ? date_create($cv->date) : null;
                                $cv_lbl  = esc_html($cv->titre) . ' (' . ($cv_date ? $cv_date->format('d/m/Y') : '') . ')';
                            ?>
                            <option value="<?php echo intval($cv->id); ?>"><?php echo $cv_lbl; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button button-primary" id="sp-link-comp-btn"
                                data-eleve-id="<?php echo intval($eleve_id); ?>">Lier</button>
                        <span id="sp-comp-link-result" style="font-size:12px;color:#15803d;display:none;"></span>
                    </div>
                <?php endif; ?>
            </div>
        </div><!-- #sp-fiche-comp-link-box -->

        <!-- Grade actuel / prochain grade -->
        <div class="sp-box">
            <h2>🥋 Grade</h2>
            <div style="display:flex;gap:32px;flex-wrap:wrap;">
                <div>
                    <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Grade actuel</div>
                    <div style="font-size:20px;font-weight:700;"><?php echo $el->grade ? esc_html($el->grade) : '—'; ?></div>
                </div>
                <?php $grade_vise = $this->db->get_grade_vise_eleve($el); if ( $grade_vise ) : ?>
                <div>
                    <div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Prochain grade</div>
                    <div style="font-size:20px;font-weight:700;color:#0f70b7;"><?php echo esc_html($grade_vise); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Passages de grade -->
        <div class="sp-box" id="sp-fiche-examens-box" data-eleve-id="<?php echo intval($eleve_id); ?>">
            <h2>🎓 Passages de grade</h2>

            <?php
            // Chronologie unifiée : examens calendrier + historique CSV
            $timeline = array();
            foreach ( $examens as $ex ) {
                $timeline[] = array(
                    'date_raw'  => $ex->date,
                    'date_ts'   => $ex->date ? strtotime($ex->date) : 0,
                    'source'    => 'calendrier',
                    'titre'     => $ex->titre,
                    'categorie' => $ex->categorie,
                    'grade'     => $ex->note,
                    'event_id'  => $ex->event_id,
                );
            }
            foreach ( $grades_hist as $date_ex => $grade_val ) {
                $parts = explode('/', $date_ex);
                $ts    = count($parts) === 3 ? mktime(0,0,0, intval($parts[1]), intval($parts[0]), intval($parts[2])) : 0;
                $timeline[] = array(
                    'date_raw'  => $date_ex,
                    'date_ts'   => $ts,
                    'source'    => 'csv',
                    'titre'     => '',
                    'categorie' => '',
                    'grade'     => $grade_val,
                    'event_id'  => null,
                );
            }
            usort($timeline, function($a, $b){ return $b['date_ts'] - $a['date_ts']; });
            ?>

            <?php if( ! empty($timeline) ): ?>
            <table class="sp-fiche-grades-table" id="sp-fiche-examens-table">
                <thead><tr>
                    <th style="width:100px;">Date</th>
                    <th>Événement</th>
                    <th>Grade obtenu</th>
                    <th style="width:40px;text-align:center;"></th>
                    <th style="width:40px;"></th>
                </tr></thead>
                <tbody>
                <?php foreach( $timeline as $trow ):
                    if ( $trow['source'] === 'calendrier' ) {
                        $d_obj     = $trow['date_raw'] ? date_create($trow['date_raw']) : null;
                        $date_disp = $d_obj ? $d_obj->format('d/m/Y') : $trow['date_raw'];
                    } else {
                        $date_disp = $trow['date_raw']; // déjà JJ/MM/AAAA
                    }
                    $grade      = $trow['grade'];
                    $is_current = ($grade !== '' && $grade !== null && $grade === $el->grade);
                ?>
                <tr <?php echo $is_current ? 'style="background:#f0fdf4;"' : ''; ?>
                    <?php if($trow['event_id']) echo 'id="sp-exam-row-' . intval($trow['event_id']) . '"'; ?>>
                    <td><strong><?php echo esc_html($date_disp); ?></strong></td>
                    <td>
                        <?php if( $trow['source'] === 'calendrier' ): ?>
                            <?php echo esc_html($trow['titre']); ?>
                            <?php if($trow['categorie'] && $trow['categorie'] !== $trow['titre']): ?>
                                <span class="sp-badge-blue"><?php echo esc_html($trow['categorie']); ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="sp-muted" style="font-size:11px;">📋 Import CSV</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($grade): ?>
                            <span class="sp-fiche-grade-badge" style="font-size:12px;padding:3px 10px;"><?php echo esc_html($grade); ?></span>
                            <?php if($is_current) echo ' <span class="sp-badge-green" style="margin-left:4px;">actuel</span>'; ?>
                        <?php elseif($trow['source'] === 'calendrier'): ?>
                            <span class="sp-muted">Candidat</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php echo $trow['source'] === 'calendrier' ? '📅' : '📋'; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <?php if($trow['source'] === 'calendrier' && $trow['event_id']): ?>
                        <button type="button" class="button button-small sp-btn-edit-exam"
                                data-event-id="<?php echo intval($trow['event_id']); ?>"
                                data-grade="<?php echo esc_attr($trow['grade'] ?? ''); ?>"
                                title="Modifier le grade">✏️</button>
                        <button type="button" class="button button-small sp-btn-unlink-exam sp-btn-del"
                                data-event-id="<?php echo intval($trow['event_id']); ?>"
                                data-grade-actuel="<?php echo esc_attr($trow['grade'] ?? ''); ?>"
                                data-is-current="<?php echo $is_current ? '1' : '0'; ?>"
                                title="Supprimer ce passage">🗑️</button>
                        <?php elseif($trow['source'] === 'csv'): ?>
                        <button type="button" class="button button-small sp-btn-edit-csv-grade"
                                data-date-key="<?php echo esc_attr($trow['date_raw']); ?>"
                                data-grade="<?php echo esc_attr($trow['grade']); ?>"
                                title="Modifier">✏️</button>
                        <button type="button" class="button button-small sp-btn-del sp-btn-delete-csv-grade"
                                data-date-key="<?php echo esc_attr($trow['date_raw']); ?>"
                                title="Supprimer">🗑️</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="sp-muted" id="sp-examens-empty-msg">Aucun passage de grade enregistré pour cet élève.</p>
            <?php endif; ?>

            <!-- Form inline édition grade passage calendrier -->
            <div id="sp-edit-exam-form" style="display:none;margin-top:12px;padding:12px 16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;">
                <strong style="font-size:13px;">✏️ Modifier le grade obtenu</strong>
                <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap;">
                    <input type="hidden" id="sp-edit-exam-event-id">
                    <input type="text" id="sp-edit-exam-grade" placeholder="Grade obtenu (ex: 7° bleu)"
                           style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;width:200px;">
                    <button type="button" id="sp-edit-exam-save" class="button button-primary"
                            data-eleve-id="<?php echo intval($eleve_id); ?>"
                            style="height:32px;">💾 Sauvegarder</button>
                    <button type="button" id="sp-edit-exam-cancel" class="button"
                            style="height:32px;">Annuler</button>
                    <span id="sp-edit-exam-msg" style="font-size:12px;color:#15803d;"></span>
                </div>
            </div>

            <!-- Lier à un examen existant -->
            <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f1;">
                <strong style="font-size:13px;">➕ Lier à un examen</strong>
                <?php if( empty($examens_disponibles) ): ?>
                    <p class="sp-muted" style="margin:6px 0 0;">
                        Aucun examen disponible.
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-pro')); ?>">
                            Créer un événement de type "🎓 Examen" dans le calendrier
                        </a>, puis revenez sur cette fiche.
                    </p>
                <?php else: ?>
                <div style="display:flex;gap:8px;align-items:flex-end;margin-top:8px;flex-wrap:wrap;">
                    <div>
                        <label style="display:block;font-size:11px;color:#6b7280;margin-bottom:3px;">Examen</label>
                        <select id="sp-link-exam-select" style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;min-width:260px;">
                            <option value="">— Choisir un examen —</option>
                            <?php foreach($examens_disponibles as $ev):
                                $d = $ev->date ? date_create($ev->date) : null;
                                $dl= $d ? $d->format('d/m/Y') : $ev->date;
                            ?>
                            <option value="<?php echo intval($ev->id); ?>">
                                <?php echo esc_html($dl . ' — ' . $ev->titre . ($ev->categorie && $ev->categorie !== $ev->titre ? ' ('.$ev->categorie.')' : '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:#6b7280;margin-bottom:3px;">Grade obtenu <small>(optionnel)</small></label>
                        <input type="text" id="sp-link-exam-grade" placeholder="ex: 6° bleue"
                               style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;width:160px;">
                    </div>
                    <button type="button" id="sp-link-exam-btn" class="button button-primary"
                            data-eleve-id="<?php echo intval($eleve_id); ?>"
                            style="height:32px;">Lier</button>
                    <span id="sp-link-exam-msg" style="font-size:12px;color:#15803d;display:none;"></span>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- #sp-fiche-examens-box -->

        <!-- Historique présences -->
        <div class="sp-box">
            <h2>📅 Historique des présences
                <?php if($nb_total): ?>
                <span class="sp-muted" style="font-size:13px;font-weight:400;">
                    — <?php echo $nb_present; ?> présence<?php echo $nb_present > 1 ? 's' : ''; ?>
                    · <?php echo $nb_absent; ?> absence<?php echo $nb_absent > 1 ? 's' : ''; ?>
                    sur <?php echo $nb_total; ?> cours enregistrés
                </span>
                <?php endif; ?>
            </h2>

            <?php if(empty($presences_cours)): ?>
                <p class="sp-muted">Aucune présence enregistrée pour cet élève.</p>
            <?php else: ?>

            <!-- Mini filtre présences -->
            <div class="sp-filter-bar" style="margin-bottom:12px;">
                <select id="sp-fiche-flt-pres">
                    <option value="">— Tout afficher —</option>
                    <option value="1">✅ Présent seulement</option>
                    <option value="0">❌ Absent seulement</option>
                </select>
                <input type="text" id="sp-fiche-flt-cours" placeholder="🔍 Filtrer par cours…" style="width:200px;">
            </div>

            <div style="overflow-x:auto;">
            <table class="wp-list-table widefat striped" id="sp-fiche-pres-table">
                <thead><tr>
                    <th style="width:110px;">Date</th>
                    <th style="width:120px;">Horaire</th>
                    <th>Cours</th>
                    <th style="width:80px;text-align:center;">Présence</th>
                </tr></thead>
                <tbody>
                <?php foreach($presences_cours as $p):
                    $present   = intval($p->present);
                    $date_fr   = $p->date ? date_create($p->date) : null;
                    $date_disp = $date_fr ? $date_fr->format('d/m/Y') : $p->date;
                    $jours_fr  = array('','Lun','Mar','Mer','Jeu','Ven','Sam','Dim');
                    $dow       = $date_fr ? intval($date_fr->format('N')) : 0;
                    $jour_lbl  = $dow ? $jours_fr[$dow] : '';
                ?>
                <tr data-present="<?php echo $present; ?>"
                    data-cours="<?php echo esc_attr(mb_strtolower($p->titre . ' ' . $p->categorie)); ?>">
                    <td>
                        <?php if($jour_lbl) echo '<span class="sp-muted" style="font-size:11px;">' . $jour_lbl . ' </span>'; ?>
                        <strong><?php echo esc_html($date_disp); ?></strong>
                    </td>
                    <td class="sp-muted">
                        <?php echo esc_html( $p->heure_debut . ($p->heure_fin ? ' – ' . $p->heure_fin : '') ); ?>
                    </td>
                    <td>
                        <?php echo esc_html($p->titre); ?>
                        <?php if($p->categorie && $p->categorie !== $p->titre): ?>
                            <span class="sp-badge-blue" style="margin-left:4px;"><?php echo esc_html($p->categorie); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-size:18px;">
                        <?php echo $present ? '✅' : '❌'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <script>
            (function(){
                var fPres  = document.getElementById('sp-fiche-flt-pres');
                var fCours = document.getElementById('sp-fiche-flt-cours');
                var rows   = document.querySelectorAll('#sp-fiche-pres-table tbody tr');
                function filter() {
                    var pres  = fPres.value;
                    var cours = fCours.value.toLowerCase();
                    rows.forEach(function(r){
                        var ok = ( pres  === '' || r.dataset.present === pres )
                              && ( cours === '' || r.dataset.cours.indexOf(cours) !== -1 );
                        r.style.display = ok ? '' : 'none';
                    });
                }
                fPres.addEventListener('change', filter);
                fCours.addEventListener('input',  filter);
            })();
            </script>

            <?php endif; ?>
        </div>

        </div><!-- .sp-fiche-wrap -->
        <?php
    }
}

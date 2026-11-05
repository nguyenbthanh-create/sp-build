<?php
/**
 * Page d'administration « Palmarès » (compétitions, résultats, médailles).
 *
 * Méthodes de SP_Cal_Members déplacées telles quelles depuis class-admin-members.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SP_Cal_Members_Palmares; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SP_Cal_Members_Palmares {


    /* ══════════════════════════════════════════════════════════
       PAGE PALMARES
    ══════════════════════════════════════════════════════════ */

    public function page_palmares() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );

        global $wpdb;

        // ── Paramètres URL ───────────────────────────────────
        $vue      = in_array( $_GET['vue'] ?? '', array('competition','eleves') ) ? $_GET['vue'] : 'competition';
        $event_id = intval( $_GET['event_id'] ?? 0 );
        $annee    = sanitize_text_field( $_GET['annee'] ?? '' );

        // ── Données ──────────────────────────────────────────
        $annees       = $this->db->get_competitions_annees();
        $competitions = $this->db->get_all_competitions( $annee );

        // Si pas de filtre d'année mais des compétitions existent, pré-sélectionner la plus récente
        if ( ! $annee && ! empty($annees) ) {
            $annee = $annees[0];
            $competitions = $this->db->get_all_competitions( $annee );
        }

        // Compétition sélectionnée
        $comp_courante = null;
        if ( $event_id ) {
            $te = $this->db->table_events();
            $comp_courante = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $te WHERE id=%d AND type='competition'", $event_id
            ) );
            if ( ! $comp_courante ) $event_id = 0;
        }
        // Pré-sélectionner la première compétition si aucune choisie
        if ( ! $event_id && ! empty($competitions) ) {
            $event_id      = intval($competitions[0]->id);
            $comp_courante = $competitions[0];
        }

        // Données selon la vue
        $palmares_global  = array();
        $palmares_eleves  = array();
        $epreuves         = array();
        $participants     = array(); // eleve_id => row élève (présences liées à cette compétition)

        if ( $vue === 'competition' && $event_id ) {
            $palmares_global = $this->db->get_palmares_global( $event_id );
            $epreuves        = $this->db->get_comp_epreuves( $event_id );

            // Participants : source = comp_participations (v10.16.1+)
            // + fallback : élèves ayant des résultats dans comp_resultats
            $tcp = $this->db->table_comp_participations();
            $tce = $this->db->table_comp_epreuves();
            $tcr = $this->db->table_comp_resultats();
            $tel = $this->db->table_eleves();
            $rows_participants = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT el.id, el.nom, el.prenom, el.categorie_age, el.grade
                 FROM $tel el
                 WHERE el.id IN (
                     SELECT p.eleve_id FROM $tcp p WHERE p.event_id = %d
                     UNION
                     SELECT r.eleve_id FROM $tcr r
                     INNER JOIN $tce ep ON ep.id = r.epreuve_id AND ep.event_id = %d
                 )
                 ORDER BY el.categorie_age ASC, el.nom ASC",
                $event_id, $event_id
            ) );
            foreach ( $rows_participants as $p ) {
                $participants[ intval($p->id) ] = $p;
            }

            // Organiser les résultats : epreuve_id => eleve_id => {medaille, score}
            foreach ( $palmares_global as $row ) {
                $palmares_comp[ intval($row->epreuve_id) ][ intval($row->eleve_id) ] = array(
                    'medaille' => $row->medaille,
                    'score'    => $row->score,
                );
            }
        }

        if ( $vue === 'eleves' ) {
            $palmares_eleves = $this->db->get_palmares_resume_eleves();
        }

        // Helper médaille → emoji + couleur
        $medal_info = function( $med ) {
            $map = array(
                'or'     => array('emoji'=>'🥇','label'=>'Or',     'color'=>'#b7791f','bg'=>'#fefce8'),
                'argent' => array('emoji'=>'🥈','label'=>'Argent', 'color'=>'#6b7280','bg'=>'#f9fafb'),
                'bronze' => array('emoji'=>'🥉','label'=>'Bronze', 'color'=>'#92400e','bg'=>'#fff7ed'),
            );
            return $map[$med] ?? array('emoji'=>'—', 'label'=>'', 'color'=>'#9ca3af','bg'=>'transparent');
        };

        $url_base = admin_url('admin.php?page=sp-cal-palmares');
        ?>
        <div class="wrap sp-cal-wrap">
        <h1>🏆 Palmarès des élèves</h1>

        <?php if ( empty($annees) ) : ?>
        <div class="sp-box" style="text-align:center;padding:40px;">
            <p style="font-size:16px;color:#6b7280;">Aucune compétition enregistrée.</p>
            <p class="sp-muted">
                Créez des événements de type <strong>Compétition</strong> dans le calendrier,
                puis saisissez les épreuves et résultats depuis le calendrier.
            </p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-pro')); ?>" class="button button-primary">
                Ouvrir le calendrier
            </a>
        </div>
        <?php else : ?>

        <!-- ── Barre de navigation ─────────────────────────── -->
        <div class="sp-palmares-toolbar">

            <!-- Filtre Année -->
            <div class="sp-palmares-filter-group">
                <span class="sp-palmares-filter-label">Année :</span>
                <?php foreach ( $annees as $a ) :
                    $active = ($a == $annee);
                    $url_a  = add_query_arg( array('page'=>'sp-cal-palmares','vue'=>$vue,'annee'=>$a,'event_id'=>0), admin_url('admin.php') );
                ?>
                <a href="<?php echo esc_url($url_a); ?>"
                   class="button <?php echo $active ? 'button-primary' : ''; ?>" style="min-width:60px;">
                    <?php echo esc_html($a); ?>
                </a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url(add_query_arg(array('page'=>'sp-cal-palmares','vue'=>$vue,'annee'=>'','event_id'=>0), admin_url('admin.php'))); ?>"
                   class="button <?php echo !$annee ? 'button-primary' : ''; ?>">Toutes</a>
            </div>

            <!-- Onglets Vue -->
            <div class="sp-palmares-tabs">
                <?php
                $tabs = array(
                    'competition' => '🏅 Par compétition',
                    'eleves'      => '👤 Par élève',
                );
                foreach ( $tabs as $t_key => $t_label ) :
                    $active = ($vue === $t_key);
                    $url_t  = add_query_arg( array('page'=>'sp-cal-palmares','vue'=>$t_key,'annee'=>$annee,'event_id'=>($t_key==='competition'?$event_id:0)), admin_url('admin.php') );
                ?>
                <a href="<?php echo esc_url($url_t); ?>"
                   class="sp-palmares-tab <?php echo $active ? 'sp-palmares-tab-active' : ''; ?>">
                    <?php echo $t_label; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             VUE : PAR COMPÉTITION
        ══════════════════════════════════════════════════ -->
        <?php if ( $vue === 'competition' ) : ?>

        <div class="sp-two-col sp-palmares-layout" style="align-items:start;">

            <!-- Colonne gauche : liste des compétitions -->
            <div class="sp-box sp-palmares-list-box">
                <h2 style="margin-top:0;">📅 Compétitions</h2>
                <?php if ( empty($competitions) ) : ?>
                    <p class="sp-muted">Aucune compétition pour cette année.</p>
                <?php else : ?>
                <ul class="sp-palmares-comp-list">
                    <?php foreach ( $competitions as $comp ) :
                        $is_sel = (intval($comp->id) === $event_id);
                        $url_c  = add_query_arg( array('page'=>'sp-cal-palmares','vue'=>'competition','annee'=>$annee,'event_id'=>$comp->id), admin_url('admin.php') );
                        $date_f = date_i18n( 'd/m/Y', strtotime($comp->date) );

                        // Compter les médailles de cette compétition
                        $tce = $this->db->table_comp_epreuves();
                        $tcr = $this->db->table_comp_resultats();
                        $nb_med = intval($wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM $tcr r
                             INNER JOIN $tce ep ON ep.id=r.epreuve_id
                             WHERE ep.event_id=%d AND r.medaille != ''",
                            intval($comp->id)
                        )));
                    ?>
                    <li class="sp-palmares-comp-item <?php echo $is_sel ? 'sp-palmares-comp-active' : ''; ?>">
                        <a href="<?php echo esc_url($url_c); ?>" class="sp-palmares-comp-link">
                            <div class="sp-palmares-comp-title"><?php echo esc_html($comp->titre); ?></div>
							<?php
							$niveau_badges = array(
								'international' => array('label'=>'🌐 International','bg'=>'#1e3a5f','color'=>'#fff'),
								'national'      => array('label'=>'🇫🇷 National',    'bg'=>'#2563eb','color'=>'#fff'),
								'regional'      => array('label'=>'🌍 Régional',     'bg'=>'#7c3aed','color'=>'#fff'),
								'departemental' => array('label'=>'🏘️ Dép.',         'bg'=>'#e5e7eb','color'=>'#374151'),
							);
							$nb = $niveau_badges[$comp->niveau ?? 'departemental'] ?? $niveau_badges['departemental'];
							echo '<span style="display:inline-block;margin-top:3px;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:700;background:'.esc_attr($nb['bg']).';color:'.esc_attr($nb['color']).';">'.esc_html($nb['label']).'</span>';
							?>
                            <div class="sp-palmares-comp-meta">
                                📅 <?php echo esc_html($date_f); ?>
                                <?php if($nb_med): ?>
                                <span class="sp-palmares-med-count">🏅 <?php echo $nb_med; ?> médaille<?php echo $nb_med>1?'s':''; ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <button type="button"
                                class="button button-small sp-btn-saisir-resultats"
                                data-event-id="<?php echo intval($comp->id); ?>"
                                data-event-titre="<?php echo esc_attr($comp->titre); ?>"
                                title="Saisir / modifier les résultats"
                                style="margin-top:6px;width:100%;justify-content:center;">
                            ✏️ Saisir les résultats
                        </button>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <!-- Colonne droite : résultats de la compétition sélectionnée -->
            <div class="sp-palmares-results-col">

            <?php if ( ! $comp_courante ) : ?>
            <div class="sp-box">
                <p class="sp-muted" style="text-align:center;padding:20px;">Sélectionnez une compétition.</p>
            </div>
            <?php else : ?>

            <!-- En-tête compétition -->
            <div class="sp-box sp-palmares-comp-header">
                <div class="sp-palmares-comp-header-inner">
                    <div>
                        <h2 style="margin:0 0 6px 0;color:#1e3a5f;"><?php echo esc_html($comp_courante->titre); ?></h2>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <span style="color:#374151;font-size:13px;">
								📅 <?php echo date_i18n('l j F Y', strtotime($comp_courante->date)); ?>
							</span>
							<?php
							$niveau_badges = array(
								'international' => array('label'=>'🌐 International','bg'=>'#1e3a5f','color'=>'#fff'),
								'national'      => array('label'=>'🇫🇷 National',    'bg'=>'#2563eb','color'=>'#fff'),
								'regional'      => array('label'=>'🌍 Régional',     'bg'=>'#7c3aed','color'=>'#fff'),
								'departemental' => array('label'=>'🏘️ Départemental','bg'=>'#e5e7eb','color'=>'#374151'),
							);
							$nb = $niveau_badges[$comp_courante->niveau ?? 'departemental'] ?? $niveau_badges['departemental'];
							echo '<span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:'.esc_attr($nb['bg']).';color:'.esc_attr($nb['color']).';">'.esc_html($nb['label']).'</span>';
							?>
                            <?php if($comp_courante->categorie && $comp_courante->categorie !== 'Général'): ?>
                            <span class="sp-fiche-pill" style="background:#7c3aed;">
                                <?php echo esc_html($comp_courante->categorie); ?>
                            </span>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-pro')); ?>"
                               style="font-size:12px;color:#6b7280;">
                                ✏️ Modifier les résultats dans le calendrier
                            </a>
                        </div>
                    </div>
                    <?php
                    $nb_or2 = 0; $nb_arg2 = 0; $nb_bro2 = 0;
                    foreach ($palmares_global as $rr) {
                        if($rr->medaille==='or')     $nb_or2++;
                        if($rr->medaille==='argent') $nb_arg2++;
                        if($rr->medaille==='bronze') $nb_bro2++;
                    }
                    if ($nb_or2 + $nb_arg2 + $nb_bro2 > 0) : ?>
                    <div class="sp-palmares-medals-summary">
                        <?php if($nb_or2):  ?><span class="sp-palm-med-badge sp-palm-or">🥇 <?php echo $nb_or2; ?></span><?php endif; ?>
                        <?php if($nb_arg2): ?><span class="sp-palm-med-badge sp-palm-argent">🥈 <?php echo $nb_arg2; ?></span><?php endif; ?>
                        <?php if($nb_bro2): ?><span class="sp-palm-med-badge sp-palm-bronze">🥉 <?php echo $nb_bro2; ?></span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if($comp_courante->description): ?>
                <p style="margin:8px 0 0;color:#374151;font-size:13px;"><?php echo nl2br(esc_html($comp_courante->description)); ?></p>
                <?php endif; ?>
            </div>

            <?php if ( empty($epreuves) ) : ?>
            <div class="sp-box">
                <p class="sp-muted" style="text-align:center;padding:16px;">
                    Aucune épreuve saisie pour cette compétition.<br>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-pro')); ?>">
                        Ouvrir le calendrier pour saisir les résultats
                    </a>
                </p>
            </div>
            <?php else : ?>

            <!-- Tableau des résultats par épreuve -->
            <?php
            // Construire le tableau : lignes = participants, colonnes = épreuves
            // Participants = tous les élèves inscrits à la compétition (présences) + ceux avec des résultats
            $all_eleve_ids = array_keys($participants);
            foreach ($palmares_global as $rr) {
                $lid = intval($rr->eleve_id);
                if (!in_array($lid, $all_eleve_ids)) $all_eleve_ids[] = $lid;
            }

            // Grouper par categorie_age pour en-têtes de groupe
            $by_cat = array();
            foreach ($all_eleve_ids as $lid) {
                $elv = $participants[$lid] ?? null;
                if (!$elv) {
                    // Trouver dans palmares_global
                    foreach ($palmares_global as $rr) {
                        if (intval($rr->eleve_id) === $lid) { $elv = $rr; break; }
                    }
                }
                $cat = $elv ? ($elv->categorie_age ?? '') : '';
                $by_cat[$cat][$lid] = $elv;
            }
            ksort($by_cat);
            ?>

            <?php foreach ($by_cat as $cat_label => $eleves_cat) : ?>
            <div class="sp-box sp-palmares-epreuve-box">
                <?php if($cat_label): ?>
                <h3 class="sp-palmares-cat-header">👥 <?php echo esc_html($cat_label); ?></h3>
                <?php endif; ?>

                <div class="sp-palmares-table-wrap">
                <table class="wp-list-table widefat sp-palmares-table">
                    <thead>
                        <tr>
                            <th class="sp-palm-col-eleve">Élève</th>
                            <th class="sp-palm-col-grade">Grade</th>
                            <?php foreach ($epreuves as $ep) : ?>
                            <th class="sp-palm-col-epreuve" title="<?php echo esc_attr($ep->nom); ?>">
                                <?php echo esc_html($ep->nom); ?>
                            </th>
                            <?php endforeach; ?>
                            <th class="sp-palm-col-total">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $no_result_row = 0;
                        foreach ($eleves_cat as $lid => $elv) :
                            $nb_med_row = 0;
                            $cells = array();
                            foreach ($epreuves as $ep) {
                                $res = $palmares_comp[ intval($ep->id) ][ $lid ] ?? null;
                                $mi  = $medal_info($res['medaille'] ?? '');
                                if ($res && $res['medaille']) $nb_med_row++;
                                $cells[] = array('mi'=>$mi, 'score'=>$res['score'] ?? '', 'has'=>!!$res);
                            }
                            $nom_affiche = $elv ? (esc_html($elv->prenom . ' ' . mb_strtoupper($elv->nom))) : '#' . $lid;
                            $grade_affiche = $elv ? esc_html($elv->grade ?? '') : '';
                            $fiche_url = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . $lid);
                        ?>
                        <tr class="<?php echo $nb_med_row ? 'sp-palm-row-medal' : ''; ?>">
                            <td class="sp-palm-col-eleve">
                                <a href="<?php echo esc_url($fiche_url); ?>" class="sp-palm-eleve-link">
                                    <?php echo $nom_affiche; ?>
                                </a>
                            </td>
                            <td class="sp-palm-col-grade sp-muted"><?php echo $grade_affiche; ?></td>
                            <?php foreach ($cells as $cell) : ?>
                            <td class="sp-palm-col-epreuve sp-palm-cell"
                                style="background:<?php echo $cell['mi']['bg']; ?>">
                                <?php if ($cell['has']) : ?>
                                <span class="sp-palm-med-emoji" title="<?php echo esc_attr($cell['mi']['label']); ?>">
                                    <?php echo $cell['mi']['emoji']; ?>
                                </span>
                                <?php if ($cell['score']) : ?>
                                <span class="sp-palm-score"><?php echo esc_html($cell['score']); ?></span>
                                <?php endif; ?>
                                <?php else : ?>
                                <span class="sp-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="sp-palm-col-total">
                                <?php if ($nb_med_row) : ?>
                                <strong style="color:#b7791f;"><?php echo $nb_med_row; ?> 🏅</strong>
                                <?php else : ?>
                                <span class="sp-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; // has epreuves ?>

            <?php endif; // has comp_courante ?>

            </div><!-- .sp-palmares-results-col -->

        </div><!-- .sp-two-col -->

        <?php endif; // vue === competition ?>

        <!-- ══════════════════════════════════════════════════
             VUE : PAR ÉLÈVE
        ══════════════════════════════════════════════════ -->
        <?php if ( $vue === 'eleves' ) : ?>

        <?php if ( empty($palmares_eleves) ) : ?>
        <div class="sp-box" style="text-align:center;padding:30px;">
            <p class="sp-muted" style="font-size:15px;">
                Aucune médaille enregistrée pour le moment.<br>
                Saisissez les résultats depuis la vue <strong>Par compétition</strong>.
            </p>
        </div>
        <?php else : ?>

        <!-- KPIs globaux -->
        <?php
        $total_or     = array_sum(array_column($palmares_eleves, 'nb_or'));
        $total_argent = array_sum(array_column($palmares_eleves, 'nb_argent'));
        $total_bronze = array_sum(array_column($palmares_eleves, 'nb_bronze'));
        $nb_medailles = count($palmares_eleves);
        ?>
        <div class="sp-stats-kpi-row">
            <div class="sp-stats-kpi" style="border-top:3px solid #b7791f;">
                <div class="sp-stats-kpi-val" style="color:#b7791f;">🥇 <?php echo intval($total_or); ?></div>
                <div class="sp-stats-kpi-lbl">Médailles d'or</div>
            </div>
            <div class="sp-stats-kpi" style="border-top:3px solid #6b7280;">
                <div class="sp-stats-kpi-val" style="color:#6b7280;">🥈 <?php echo intval($total_argent); ?></div>
                <div class="sp-stats-kpi-lbl">Médailles d'argent</div>
            </div>
            <div class="sp-stats-kpi" style="border-top:3px solid #92400e;">
                <div class="sp-stats-kpi-val" style="color:#92400e;">🥉 <?php echo intval($total_bronze); ?></div>
                <div class="sp-stats-kpi-lbl">Médailles de bronze</div>
            </div>
            <div class="sp-stats-kpi" style="border-top:3px solid #2271b1;">
                <div class="sp-stats-kpi-val" style="color:#2271b1;">👤 <?php echo $nb_medailles; ?></div>
                <div class="sp-stats-kpi-lbl">Élèves médaillés</div>
            </div>
        </div>

        <!-- Tableau palmarès élèves -->
        <div class="sp-box">
            <h2 style="margin-top:0;">🏆 Classement des élèves médaillés</h2>
            <div class="sp-palmares-table-wrap">
            <table class="wp-list-table widefat striped sp-palmares-eleves-table">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>Élève</th>
                        <th>Groupe</th>
                        <th>Grade</th>
                        <th style="text-align:center;">🥇 Or</th>
                        <th style="text-align:center;">🥈 Argent</th>
                        <th style="text-align:center;">🥉 Bronze</th>
                        <th style="text-align:center;">🏟 Compétitions</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rang_i = 1;
                    $prev   = null;
                    foreach ( $palmares_eleves as $row ) :
                        $nb_or_r  = intval($row->nb_or);
                        $nb_ar_r  = intval($row->nb_argent);
                        $nb_br_r  = intval($row->nb_bronze);
                        $fiche_url2 = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . intval($row->eleve_id));
                        $is_podium = ($rang_i <= 3);
                        $podium_icon = ($rang_i === 1) ? '🥇' : (($rang_i === 2) ? '🥈' : (($rang_i === 3) ? '🥉' : ''));
                    ?>
                    <tr class="<?php echo $is_podium ? 'sp-palm-row-podium' : ''; ?>">
                        <td style="font-weight:bold;color:#6b7280;">
                            <?php echo $podium_icon ?: $rang_i; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($fiche_url2); ?>" class="sp-palm-eleve-link">
                                <?php echo esc_html($row->prenom . ' ' . mb_strtoupper($row->nom)); ?>
                            </a>
                        </td>
                        <td class="sp-muted"><?php echo esc_html($row->categorie_age ?? ''); ?></td>
                        <td class="sp-muted"><?php echo esc_html($row->grade ?? ''); ?></td>
                        <td style="text-align:center;font-weight:bold;color:<?php echo $nb_or_r ? '#b7791f' : '#d1d5db'; ?>;">
                            <?php echo $nb_or_r ?: '—'; ?>
                        </td>
                        <td style="text-align:center;font-weight:bold;color:<?php echo $nb_ar_r ? '#6b7280' : '#d1d5db'; ?>;">
                            <?php echo $nb_ar_r ?: '—'; ?>
                        </td>
                        <td style="text-align:center;font-weight:bold;color:<?php echo $nb_br_r ? '#92400e' : '#d1d5db'; ?>;">
                            <?php echo $nb_br_r ?: '—'; ?>
                        </td>
                        <td style="text-align:center;color:#6b7280;"><?php echo intval($row->nb_total_comp); ?></td>
                        <td>
                            <a href="<?php echo esc_url($fiche_url2); ?>" class="button button-small">
                                Fiche
                            </a>
                        </td>
                    </tr>
                    <?php $rang_i++; endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- Légende détail par compétition pour chaque élève -->
            <div class="sp-palmares-eleves-detail">
                <h3>📋 Détail par élève</h3>
                <div class="sp-palmares-accordion">
                <?php foreach ( $palmares_eleves as $row ) :
                    $detail = $this->db->get_palmares_eleve( intval($row->eleve_id) );
                    if ( empty($detail) ) continue;
                    // Grouper par compétition
                    $by_comp = array();
                    foreach ($detail as $dr) {
                        $by_comp[$dr->event_id][] = $dr;
                    }
                    $fiche_url3 = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . intval($row->eleve_id));
                ?>
                <details class="sp-palmares-accordion-item">
                    <summary class="sp-palmares-accordion-summary">
                        <span class="sp-palm-eleve-name">
                            <?php echo esc_html($row->prenom . ' ' . mb_strtoupper($row->nom)); ?>
                        </span>
                        <?php if($row->categorie_age): ?>
                        <span class="sp-fiche-pill sp-fiche-pill-age" style="font-size:11px;">
                            <?php echo esc_html($row->categorie_age); ?>
                        </span>
                        <?php endif; ?>
                        <span class="sp-palm-accordion-medals">
                            <?php if($row->nb_or):     echo '🥇 ' . intval($row->nb_or)     . ' '; endif; ?>
                            <?php if($row->nb_argent): echo '🥈 ' . intval($row->nb_argent) . ' '; endif; ?>
                            <?php if($row->nb_bronze): echo '🥉 ' . intval($row->nb_bronze)        ; endif; ?>
                        </span>
                    </summary>
                    <div class="sp-palm-accordion-body">
                        <?php foreach ($by_comp as $eid => $rows_comp) :
                            $comp_date  = date_i18n('d/m/Y', strtotime($rows_comp[0]->date));
                            $comp_title = $rows_comp[0]->comp_titre;
                            $url_comp   = add_query_arg(array('page'=>'sp-cal-palmares','vue'=>'competition','event_id'=>$eid,'annee'=>date('Y', strtotime($rows_comp[0]->date))), admin_url('admin.php'));
                        ?>
                        <div class="sp-palm-comp-block">
                            <div class="sp-palm-comp-block-title">
                                📅 <a href="<?php echo esc_url($url_comp); ?>"><?php echo esc_html($comp_title); ?></a>
                                <span class="sp-muted"><?php echo $comp_date; ?></span>
                            </div>
                            <ul class="sp-palm-epreuves-list">
                                <?php foreach ($rows_comp as $dr) :
                                    $mi = $medal_info($dr->medaille);
                                ?>
                                <li>
                                    <span style="color:<?php echo $mi['color']; ?>;"><?php echo $mi['emoji']; ?></span>
                                    <strong><?php echo esc_html($dr->epreuve_nom); ?></strong>
                                    <?php if($dr->score): ?>
                                    <span class="sp-muted">— <?php echo esc_html($dr->score); ?></span>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endforeach; ?>
                        <a href="<?php echo esc_url($fiche_url3); ?>" class="button button-small" style="margin-top:6px;">
                            Voir la fiche complète
                        </a>
                    </div>
                </details>
                <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php endif; // palmares_eleves not empty ?>

        <?php
        // ── Encart correspondance pseudo / identité — uniquement si des élèves ont droit_image=0
        $eleves_sans_droit = array_filter( $palmares_eleves ?? array(), function($r){ return intval($r->droit_image ?? 1) === 0; } );
        if ( ! empty($eleves_sans_droit) ) :
            $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $idx = 0;
        ?>
        <div class="sp-box" style="border:2px solid #f59e0b;background:#fffbeb;">
            <h2 style="margin-top:0;color:#92400e;">🔒 Correspondance pseudo / identité réelle</h2>
            <p class="description" style="margin-bottom:12px;">
                Ces élèves apparaissent sous pseudonyme dans le palmarès public (droit à l'image non accordé).
            </p>
            <table class="wp-list-table widefat fixed striped" style="max-width:480px;">
                <thead><tr>
                    <th style="width:140px;">Pseudo public</th>
                    <th>Identité réelle</th>
                    <th style="width:80px;">Fiche</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $eleves_sans_droit as $row ) :
                    $pseudo    = 'Élève ' . $alphabet[ $idx % 26 ];
                    $fiche_url = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . intval($row->eleve_id));
                    $idx++;
                ?>
                <tr>
                    <td><strong style="color:#1e3a5f;"><?php echo esc_html($pseudo); ?></strong></td>
                    <td><?php echo esc_html($row->prenom . ' ' . mb_strtoupper($row->nom)); ?></td>
                    <td><a href="<?php echo esc_url($fiche_url); ?>" class="button button-small">Fiche</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php endif; // vue === eleves ?>

        <?php endif; // annees not empty ?>

        <!-- ══════════════════════════════════════════════════
             POPUP SAISIE RÉSULTATS (page Palmarès)
        ══════════════════════════════════════════════════ -->
        <div id="sp-palmares-comp-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99998;"></div>
        <div id="sp-palmares-comp-popup" style="
            display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
            z-index:99999;width:min(96vw,960px);max-height:88vh;overflow-y:auto;
            background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.35);
            padding:0;
        ">
            <!-- En-tête popup -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;
                        border-bottom:1px solid #e5e7eb;background:#f8fafc;border-radius:12px 12px 0 0;position:sticky;top:0;z-index:2;">
                <div>
                    <h2 id="sp-comp-popup-titre" style="margin:0;font-size:16px;color:#1e3a5f;">Saisie des résultats</h2>
                    <p class="sp-muted" style="margin:2px 0 0;font-size:12px;">Épreuves et médailles — la page se rafraîchira après enregistrement.</p>
                </div>
                <button type="button" id="sp-comp-popup-close" style="
                    background:none;border:none;font-size:22px;cursor:pointer;color:#6b7280;
                    line-height:1;padding:4px 8px;border-radius:6px;
                " title="Fermer">✕</button>
            </div>

            <!-- Corps popup -->
            <div style="padding:20px;">
                <div id="sp-comp-popup-loading" style="text-align:center;padding:30px;color:#6b7280;">
                    Chargement…
                </div>

                <!-- Épreuves -->
                <div id="sp-comp-popup-epreuves-wrap" style="display:none;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                        <strong style="font-size:13px;">📋 Épreuves (max 6)</strong>
                        <button type="button" id="sp-comp-popup-add-ep" class="button button-small">+ Ajouter une épreuve</button>
                    </div>
                    <!-- en-tête colonnes -->
                    <div class="sp-comp-ep-header">
                        <span class="sp-comp-ep-ordre">#</span>
                        <span style="flex:1.4;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Nom de l'épreuve</span>
                        <span style="flex:1;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Modalité</span>
                        <span style="width:28px;"></span>
                    </div>
                    <div id="sp-comp-popup-ep-list"></div>
                    <div style="display:flex;align-items:center;gap:10px;margin-top:10px;">
                        <button type="button" id="sp-comp-popup-save-ep" class="button" style="background:#f0fdf4;border-color:#86efac;color:#15803d;">
                            💾 Enregistrer les épreuves
                        </button>
                        <span id="sp-comp-popup-ep-msg" style="font-size:12px;color:#15803d;display:none;"></span>
                    </div>
                </div>

                <hr style="margin:18px 0;border:none;border-top:1px solid #e5e7eb;">

                <!-- Résultats -->
                <div id="sp-comp-popup-resultats-wrap" style="display:none;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                        <strong style="font-size:13px;">🥇 Résultats par épreuve</strong>
                        <select id="sp-comp-popup-flt-saisie" class="sp-input" style="height:30px;min-width:140px;">
                            <option value="">— Tous</option>
                        </select>
                    </div>
                    <div style="overflow-x:auto;">
                        <div id="sp-comp-popup-res-list"></div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;margin-top:14px;">
                        <button type="button" id="sp-comp-popup-save-res" class="button button-primary">
                            💾 Enregistrer les résultats
                        </button>
                        <span id="sp-comp-popup-res-msg" style="font-size:12px;color:#15803d;display:none;"></span>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function($){
            // ── État local du popup ───────────────────────────────────
            var PAL = {
                eventId:    0,
                epreuves:   [],
                eleves:     [],
                resultats:  {},
                nonce:      '<?php echo wp_create_nonce("sp_cal_admin_nonce"); ?>',
                ajaxurl:    '<?php echo admin_url("admin-ajax.php"); ?>',
            };

            function escHtml(s){ return $('<div>').text(s||'').html(); }

            // ── Ouverture popup ───────────────────────────────────────
            $(document).on('click', '.sp-btn-saisir-resultats', function(){
                PAL.eventId = parseInt($(this).data('event-id'), 10);
                var titre   = $(this).data('event-titre') || 'Compétition';
                $('#sp-comp-popup-titre').text('✏️ ' + titre);
                // Reset affichage
                $('#sp-comp-popup-loading').show();
                $('#sp-comp-popup-epreuves-wrap, #sp-comp-popup-resultats-wrap').hide();
                $('#sp-comp-popup-ep-list').empty();
                $('#sp-comp-popup-res-list').empty();
                // Ouvrir
                $('#sp-palmares-comp-overlay, #sp-palmares-comp-popup').show();
                $('body').css('overflow','hidden');
                // Charger données
                palLoadComp();
            });

            function closePalPopup(){
                $('#sp-palmares-comp-overlay, #sp-palmares-comp-popup').hide();
                $('body').css('overflow','');
            }
            $('#sp-comp-popup-close, #sp-palmares-comp-overlay').on('click', closePalPopup);

            // ── Charger épreuves + résultats ─────────────────────────
            function palLoadComp(){
                $.post(PAL.ajaxurl, {
                    action: 'sp_cal_get_comp', nonce: PAL.nonce, event_id: PAL.eventId
                }, function(res){
                    $('#sp-comp-popup-loading').hide();
                    if (!res.success){ alert('Erreur : ' + res.data); return; }
                    PAL.epreuves  = res.data.epreuves || [];
                    PAL.eleves    = res.data.eleves   || [];
                    PAL.resultats = {};
                    PAL.epreuves.forEach(function(ep){
                        PAL.resultats[ep.id] = JSON.parse(JSON.stringify(ep.resultats || {}));
                    });
                    // Filtre discipline pratiquée
                    var discLabels = { TKD: 'Taekwondo', RENFO: 'Renforcement musculaire' };
                    var cats = {}; PAL.eleves.forEach(function(el){ if(el.categorie_saisie) cats[el.categorie_saisie]=1; });
                    var opts = '<option value="">— Tous</option>';
                    Object.keys(cats).sort().forEach(function(c){ opts += '<option value="'+escHtml(c)+'">'+escHtml(discLabels[c] || c)+'</option>'; });
                    $('#sp-comp-popup-flt-saisie').html(opts);
                    $('#sp-comp-popup-epreuves-wrap, #sp-comp-popup-resultats-wrap').show();
                    palRenderEpreuves();
                    palRenderResultats();
                });
            }

            // ── Rendu épreuves ────────────────────────────────────────
            function palRenderEpreuves(){
                var html = '';
                if (!PAL.epreuves.length){
                    html = '<div style="color:#9ca3af;font-size:12px;padding:6px 0;">Aucune épreuve. Ajoutez-en jusqu\'à 6.</div>';
                } else {
                    PAL.epreuves.forEach(function(ep, i){
                        html += '<div class="sp-comp-ep-row" data-idx="'+i+'">'
                            + '<span class="sp-comp-ep-ordre">'+(i+1)+'</span>'
                            + '<input type="text" class="sp-input sp-pal-ep-nom" value="'+escHtml(ep.nom)+'" placeholder="ex : Combat, Kata…" data-idx="'+i+'" style="flex:1.4;height:30px;">'
                            + '<input type="text" class="sp-input sp-pal-ep-mod" value="'+escHtml(ep.modalite||'')+'" placeholder="ex : Individuel, Duo…" data-idx="'+i+'" style="flex:1;height:30px;">'
                            + '<span class="sp-comp-ep-del" role="button" data-idx="'+i+'" style="cursor:pointer;color:#ef4444;font-size:16px;padding:0 4px;" title="Supprimer">✕</span>'
                            + '</div>';
                    });
                }
                var $list = $('#sp-comp-popup-ep-list').html(html);
                $list.off('input').on('input', '.sp-pal-ep-nom', function(){
                    var idx = parseInt($(this).data('idx'),10);
                    if (PAL.epreuves[idx]) PAL.epreuves[idx].nom = $(this).val();
                }).on('input', '.sp-pal-ep-mod', function(){
                    var idx = parseInt($(this).data('idx'),10);
                    if (PAL.epreuves[idx]) PAL.epreuves[idx].modalite = $(this).val();
                }).off('click').on('click', '.sp-comp-ep-del', function(){
                    var idx = parseInt($(this).data('idx'),10);
                    PAL.epreuves.splice(idx,1);
                    PAL.epreuves.forEach(function(ep,i){ ep.ordre=i+1; });
                    palRenderEpreuves(); palRenderResultats();
                });
            }

            $('#sp-comp-popup-add-ep').on('click', function(){
                if (PAL.epreuves.length >= 6){ alert('Maximum 6 épreuves.'); return; }
                PAL.epreuves.push({id:0, nom:'', modalite:'', ordre: PAL.epreuves.length+1});
                palRenderEpreuves();
            });

            $('#sp-comp-popup-save-ep').on('click', function(){
                // Sync inputs → PAL.epreuves
                $('#sp-comp-popup-ep-list .sp-comp-ep-row').each(function(){
                    var idx = parseInt($(this).data('idx'),10);
                    if (PAL.epreuves[idx]){
                        PAL.epreuves[idx].nom     = $(this).find('.sp-pal-ep-nom').val().trim();
                        PAL.epreuves[idx].modalite= $(this).find('.sp-pal-ep-mod').val().trim();
                    }
                });
                var payload = PAL.epreuves.filter(function(ep){ return ep.nom; })
                    .map(function(ep,i){ return {id:ep.id||0, nom:ep.nom, modalite:ep.modalite||'', ordre:i+1}; });
                var btn = $(this).prop('disabled',true);
                $.post(PAL.ajaxurl, {
                    action: 'sp_cal_save_comp_epreuves', nonce: PAL.nonce,
                    event_id: PAL.eventId, epreuves: JSON.stringify(payload),
                }, function(res){
                    btn.prop('disabled',false);
                    if (!res.success){ alert('Erreur : '+res.data); return; }
                    $('#sp-comp-popup-ep-msg').text('✅ Épreuves enregistrées.').show();
                    setTimeout(function(){ $('#sp-comp-popup-ep-msg').fadeOut(); }, 2500);
                    palLoadComp();
                });
            });

            // ── Rendu tableau résultats ───────────────────────────────
            function palRenderResultats(){
                var saisie = $('#sp-comp-popup-flt-saisie').val() || '';
                var eleves = saisie ? PAL.eleves.filter(function(el){ return el.categorie_saisie === saisie; }) : PAL.eleves;
                if (!PAL.epreuves.length){
                    $('#sp-comp-popup-res-list').html('<div style="color:#9ca3af;font-size:12px;padding:8px 0;">Définissez d\'abord les épreuves.</div>');
                    return;
                }
                if (!eleves.length){
                    $('#sp-comp-popup-res-list').html('<div style="color:#9ca3af;font-size:12px;padding:8px 0;">Aucun élève.</div>');
                    return;
                }
                var html = '<table class="sp-comp-table"><thead><tr><th class="sp-comp-th-name">Élève</th>';
                PAL.epreuves.forEach(function(ep){
                    var sub = ep.modalite ? '<br><span class="sp-comp-ep-mod">'+escHtml(ep.modalite)+'</span>' : '';
                    html += '<th class="sp-comp-th-ep">'+escHtml(ep.nom)+sub+'</th>';
                });
                html += '</tr></thead><tbody>';
                eleves.forEach(function(el){
                    html += '<tr><td class="sp-comp-td-name">'+escHtml(el.nom)+'<br><span class="sp-comp-cat">'+escHtml(el.categorie_age||'')+'</span></td>';
                    PAL.epreuves.forEach(function(ep){
                        if (!ep.id){ html += '<td class="sp-comp-td-res"><span style="color:#ccc;font-size:11px;">—</span></td>'; return; }
                        var res  = (PAL.resultats[ep.id] && PAL.resultats[ep.id][el.id]) || {};
                        var med  = res.medaille || '';
                        var score= res.score    || '';
                        html += '<td class="sp-comp-td-res">'
                            + '<div class="sp-comp-med-btns">'
                            + '<span class="sp-comp-med'+(med==='or'?' active':'')+'" role="button" data-ep="'+ep.id+'" data-el="'+el.id+'" data-val="or" title="Or">🥇</span>'
                            + '<span class="sp-comp-med'+(med==='argent'?' active':'')+'" role="button" data-ep="'+ep.id+'" data-el="'+el.id+'" data-val="argent" title="Argent">🥈</span>'
                            + '<span class="sp-comp-med'+(med==='bronze'?' active':'')+'" role="button" data-ep="'+ep.id+'" data-el="'+el.id+'" data-val="bronze" title="Bronze">🥉</span>'
                            + '<span class="sp-comp-med'+(med===''?' active':'')+'" role="button" data-ep="'+ep.id+'" data-el="'+el.id+'" data-val="" title="Effacer">✕</span>'
                            + '</div>'
                            + '<input type="text" class="sp-comp-score sp-input" value="'+escHtml(score)+'" placeholder="Score…" data-ep="'+ep.id+'" data-el="'+el.id+'">'
                            + '</td>';
                    });
                    html += '</tr>';
                });
                html += '</tbody></table>';
                var $res = $('#sp-comp-popup-res-list').html(html);
                $res.off('click').on('click', '.sp-comp-med', function(){
                    var epId = parseInt($(this).data('ep'),10);
                    var elId = parseInt($(this).data('el'),10);
                    var val  = $(this).data('val');
                    if (!PAL.resultats[epId]) PAL.resultats[epId]={};
                    if (!PAL.resultats[epId][elId]) PAL.resultats[epId][elId]={medaille:'',score:''};
                    PAL.resultats[epId][elId].medaille = val;
                    $(this).closest('.sp-comp-med-btns').find('.sp-comp-med').removeClass('active');
                    $(this).addClass('active');
                }).off('input').on('input', '.sp-comp-score', function(){
                    var epId = parseInt($(this).data('ep'),10);
                    var elId = parseInt($(this).data('el'),10);
                    if (!PAL.resultats[epId]) PAL.resultats[epId]={};
                    if (!PAL.resultats[epId][elId]) PAL.resultats[epId][elId]={medaille:'',score:''};
                    PAL.resultats[epId][elId].score = $(this).val();
                });
            }

            $('#sp-comp-popup-flt-saisie').on('change', palRenderResultats);

            // ── Enregistrer résultats ─────────────────────────────────
            $('#sp-comp-popup-save-res').on('click', function(){
                var payload = [];
                var epNames = {};
                PAL.epreuves.forEach(function(ep){ epNames[ep.id]=ep.nom; });
                Object.keys(PAL.resultats).forEach(function(epId){
                    Object.keys(PAL.resultats[epId]).forEach(function(elId){
                        var r = PAL.resultats[epId][elId];
                        if (!r.medaille && !r.score) return;
                        payload.push({
                            epreuve_id: parseInt(epId,10), eleve_id: parseInt(elId,10),
                            medaille: r.medaille||'', score: r.score||'',
                            epreuve_nom: epNames[epId]||'',
                        });
                    });
                });
                var btn = $(this).prop('disabled',true).text('Enregistrement…');
                $.post(PAL.ajaxurl, {
                    action: 'sp_cal_save_comp_resultats', nonce: PAL.nonce,
                    event_id: PAL.eventId, resultats: JSON.stringify(payload),
                }, function(res){
                    btn.prop('disabled',false).text('💾 Enregistrer les résultats');
                    if (!res.success){ alert('Erreur : '+res.data); return; }
                    $('#sp-comp-popup-res-msg').text('✅ Résultats enregistrés.').show();
                    setTimeout(function(){
                        $('#sp-comp-popup-res-msg').fadeOut();
                        // Rafraîchir la page pour mettre à jour les compteurs de médailles
                        location.reload();
                    }, 1500);
                });
            });

        }(jQuery));
        </script>

        </div><!-- .wrap -->

        <?php
    }
}

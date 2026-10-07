<?php
/**
 * Pages d'administration « Licences » et « Statistiques ».
 *
 * Méthodes de SP_Cal_Members déplacées telles quelles depuis class-admin-members.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SP_Cal_Members_Licences_Stats; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SP_Cal_Members_Licences_Stats {


    /* ══════════════════════════════════════════════════════════
       PAGE LICENCES
    ══════════════════════════════════════════════════════════ */

    public function page_licences() {
        if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé' );

        $saisons       = $this->db->get_saisons();
        $saison        = sanitize_text_field( $_GET['saison'] ?? '' );
        $status_filter = sanitize_text_field( $_GET['statut'] ?? '' );
        $adh           = $this->db->get_adhesions_counts( $saison );
        $eleves        = $this->db->get_adhesions_all( $saison, $status_filter );
        $fin_saison    = get_option( 'sp_cal_fin_saison', '' );
        $alerte_jours  = intval( get_option( 'sp_cal_alerte_jours', 60 ) );

        $actif_count   = count( $this->db->get_adhesions_all( $saison, 'actif' ) );
        $inactif_count = count( $this->db->get_adhesions_all( $saison, 'inactif' ) );
        $total_count   = $actif_count + $inactif_count;

        $statut_labels = array(
            ''        => '— Tous (' . $total_count . ') —',
            'actif'   => '✅ Actifs (' . $actif_count . ')',
            'inactif' => '❌ Inactifs (' . $inactif_count . ')',
        );
        ?>
        <div class="wrap sp-cal-wrap">
        <h1>🪪 Adhésions</h1>
        <?php $this->notice_flash('saved', 'Date de fin de saison enregistrée.'); ?>

        <!-- Date fin de saison -->
        <div class="sp-box" style="margin-bottom:20px;">
            <h2>📅 Fin de saison</h2>
            <form method="post" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
                <?php wp_nonce_field('sp_cal_fin_saison'); ?>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">
                        Date de fin de la saison sportive
                    </label>
                    <input type="date" name="sp_fin_saison"
                           value="<?php echo esc_attr($fin_saison); ?>"
                           style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">
                        Alerter à partir de
                    </label>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <input type="number" name="sp_alerte_jours" min="1" max="365"
                               value="<?php echo esc_attr($alerte_jours); ?>"
                               style="height:32px;width:70px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;">
                        <span style="font-size:13px;color:#374151;">jours avant la fin de saison</span>
                    </div>
                </div>
                <input type="submit" name="sp_save_fin_saison" class="button button-primary" value="Enregistrer">
            </form>
            <p class="description" style="margin-top:8px;">
                Seuls le jour et le mois comptent : la date est reconduite automatiquement chaque année.
                Le statut de chaque adhérent dépend de la saison de sa fiche (renouvellement fait ou non).
            </p>
            <?php $fin_prochaine = SpCalPro_DB::fin_saison_prochaine();
            if ( $fin_prochaine ) :
                $jours = SpCalPro_DB::jours_avant_fin_saison();
                $color = $jours <= 30 ? '#b45309' : '#15803d';
            ?>
            <p style="margin-top:8px;font-size:13px;">
                Saison en cours : <strong><?php echo esc_html( SpCalPro_DB::saison_en_cours() ); ?></strong>
                — fin le <strong><?php echo date('d/m/Y', strtotime($fin_prochaine)); ?></strong>
                — <strong style="color:<?php echo $color; ?>;"><?php echo $jours; ?> jours restants</strong>
            </p>
            <?php endif; ?>
        </div>

        <div class="sp-box" style="margin-bottom:20px;">
            <h2>🩺 Certificat médical</h2>
            <?php if ( isset( $_GET['certif_saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Durée de validité enregistrée.</p></div>
            <?php endif; ?>
            <p class="description" style="margin-bottom:10px;">
                Durée au-delà de laquelle le certificat médical déposé au Taekwondo (date renseignée sur le formulaire d'adhésion) est signalé comme à renouveler — alerte informative uniquement, elle ne bloque jamais l'envoi du formulaire ni la validation d'une demande.
                Par défaut 12 mois, conformément au règlement FFTDA pour le Taekwondo en compétition.
            </p>
            <form method="post" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
                <?php wp_nonce_field('sp_cal_certif_medical'); ?>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">
                        Durée de validité (mois)
                    </label>
                    <input type="number" name="sp_certif_medical_mois" min="1" max="60"
                           value="<?php echo esc_attr( intval( get_option( 'sp_cal_certif_medical_mois', 12 ) ) ); ?>"
                           style="height:32px;width:70px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;">
                </div>
                <input type="submit" name="sp_save_certif_medical" class="button button-primary" value="Enregistrer">
            </form>
        </div>

        <?php SP_Cal_Renouvellement::get_instance( $this->db )->render_box(); ?>

        <!-- KPIs -->
        <div class="sp-stats-kpi-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
            <?php
            $kpis = array(
                array('val'=>$total_count,   'lbl'=>'Adhérents total', 'color'=>'#2271b1', 'filter'=>''),
                array('val'=>$actif_count,   'lbl'=>'Actifs',          'color'=>'#15803d', 'filter'=>'actif'),
                array('val'=>$inactif_count, 'lbl'=>'Inactifs',        'color'=>'#b91c1c', 'filter'=>'inactif'),
            );
            foreach($kpis as $k):
                $active = ($status_filter === $k['filter']);
                $url    = add_query_arg(array('page'=>'sp-cal-licences','statut'=>$k['filter'],'saison'=>$saison), admin_url('admin.php'));
            ?>
            <a href="<?php echo esc_url($url); ?>" style="text-decoration:none;">
            <div class="sp-stats-kpi" style="border-top:3px solid <?php echo $k['color']; ?>;<?php echo $active?'box-shadow:0 0 0 2px '.$k['color'].';':''; ?>cursor:pointer;">
                <div class="sp-stats-kpi-val" style="color:<?php echo $k['color']; ?>;"><?php echo intval($k['val']); ?></div>
                <div class="sp-stats-kpi-lbl"><?php echo esc_html($k['lbl']); ?></div>
            </div>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Filtres -->
        <div class="sp-filter-bar" style="margin-bottom:16px;flex-wrap:wrap;gap:8px;">
            <select onchange="location.href=this.value">
                <?php foreach($statut_labels as $val=>$lbl):
                    $url = add_query_arg(array('page'=>'sp-cal-licences','statut'=>$val,'saison'=>$saison), admin_url('admin.php'));
                ?>
                <option value="<?php echo esc_attr($url); ?>" <?php selected($status_filter,$val); ?>><?php echo esc_html($lbl); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if(!empty($saisons)): ?>
            <select onchange="location.href=this.value">
                <option value="<?php echo esc_attr(add_query_arg(array('page'=>'sp-cal-licences','statut'=>$status_filter,'saison'=>''), admin_url('admin.php'))); ?>"
                    <?php selected($saison,''); ?>>— Toutes les saisons —</option>
                <?php foreach($saisons as $s):
                    $url = add_query_arg(array('page'=>'sp-cal-licences','statut'=>$status_filter,'saison'=>$s), admin_url('admin.php'));
                ?>
                <option value="<?php echo esc_attr($url); ?>" <?php selected($saison,$s); ?>><?php echo esc_html($s); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="text" id="sp-lic-search" placeholder="🔍 Nom…"
                   style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;width:200px;">
        </div>

        <!-- Tableau -->
        <?php if(empty($eleves)): ?>
        <div class="sp-box">
            <p class="sp-muted" style="text-align:center;padding:20px;">Aucun adhérent trouvé pour ces filtres.</p>
        </div>
        <?php else: ?>
        <div class="sp-box" style="padding:0;">
        <table class="wp-list-table widefat striped" id="sp-lic-table">
            <thead><tr>
                <th>Élève</th>
                <th>Catégorie</th>
                <th>N° Licence</th>
                <th style="width:110px;text-align:center;">Statut</th>
                <th>Motif d'inactivité</th>
                <th style="width:60px;"></th>
            </tr></thead>
            <tbody>
            <?php foreach($eleves as $el):
                $actif     = intval($el->actif ?? 1);
                $fiche_url = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id='.$el->id);
                $edit_url  = admin_url('admin.php?page=sp-cal-eleves&sp_edit_eleve='.$el->id);
            ?>
            <tr data-name="<?php echo esc_attr(mb_strtolower($el->nom.' '.$el->prenom)); ?>"
                style="<?php echo !$actif ? 'opacity:.7;' : ''; ?>">
                <td>
                    <a href="<?php echo esc_url($fiche_url); ?>" style="font-weight:600;">
                        <?php echo esc_html($el->prenom.' '.mb_strtoupper($el->nom)); ?>
                    </a>
                    <?php if($el->saison) echo '<br><span class="sp-muted" style="font-size:11px;">'.esc_html($el->saison).'</span>'; ?>
                </td>
                <td><?php if($el->categorie_age) echo '<span class="sp-badge-blue">'.esc_html($el->categorie_age).'</span>'; ?></td>
                <td class="sp-mono"><?php echo esc_html($el->licence ?: '—'); ?></td>
                <td style="text-align:center;">
                    <?php echo $actif
                        ? '<span class="sp-lic-badge sp-lic-ok">✅ Actif</span>'
                        : '<span class="sp-lic-badge sp-lic-expired">❌ Inactif</span>'; ?>
                </td>
                <td class="sp-muted"><?php echo esc_html($el->motif_inactif); ?></td>
                <td><a href="<?php echo esc_url($edit_url); ?>" class="button button-small" title="Modifier">✏️</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <script>
        document.getElementById('sp-lic-search').addEventListener('input', function(){
            var q = this.value.toLowerCase();
            document.querySelectorAll('#sp-lic-table tbody tr').forEach(function(r){
                r.style.display = r.dataset.name.indexOf(q) !== -1 ? '' : 'none';
            });
        });
        </script>
        <?php endif; ?>

        </div>
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       PAGE STATS
    ══════════════════════════════════════════════════════════ */

    public function page_stats() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );

        $saisons = $this->db->get_saisons();
        $saison  = sanitize_text_field( $_GET['saison'] ?? ( $saisons[0] ?? '' ) );

        $stats_groupe  = $this->db->get_stats_par_groupe( $saison );
        $stats_saisie  = $this->db->get_stats_par_saisie( $saison );
        $stats_evol    = $this->db->get_stats_evolution_mensuelle( $saison );
        $top_assidus   = $this->db->get_stats_classement( $saison, 15, 'desc' );
        $top_absents   = $this->db->get_stats_classement( $saison, 10, 'asc' );

        // Totaux globaux
        $total_eleves  = 0; $total_presents = 0; $total_total = 0;
        foreach ( $stats_groupe as $r ) {
            $total_eleves  += intval($r->nb_eleves);
            $total_presents+= intval($r->nb_presents);
            $total_total   += intval($r->nb_presents) + intval($r->nb_absents);
        }
        $taux_global = $total_total ? round($total_presents / $total_total * 100) : null;

        $mois_fr = array('01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Jun',
                         '07'=>'Jul','08'=>'Aoû','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc');

        ?>
        <div class="wrap sp-cal-wrap">
        <h1>📊 Statistiques de présence</h1>

        <!-- Filtre saison -->
        <?php if ( count($saisons) > 1 ) : ?>
        <div style="margin-bottom:20px;display:flex;align-items:center;gap:12px;">
            <strong>Saison :</strong>
            <?php foreach ( $saisons as $s ) :
                $active = ($s === $saison);
                $url    = admin_url('admin.php?page=sp-cal-stats&saison=' . urlencode($s));
            ?>
            <a href="<?php echo esc_url($url); ?>"
               class="button <?php echo $active ? 'button-primary' : ''; ?>">
                <?php echo esc_html($s); ?>
            </a>
            <?php endforeach; ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-stats&saison=')); ?>"
               class="button <?php echo !$saison ? 'button-primary' : ''; ?>">Toutes</a>
        </div>
        <?php endif; ?>

        <?php if ( ! $total_total ) : ?>
        <div class="sp-box">
            <p class="sp-muted" style="text-align:center;padding:20px;">
                Aucune présence enregistrée<?php echo $saison ? ' pour la saison ' . esc_html($saison) : ''; ?>.<br>
                Utilisez l'onglet 👥 Présences sur les événements du calendrier pour commencer.
            </p>
        </div>
        <?php else : ?>

        <!-- ── Résumé global ── -->
        <div class="sp-stats-kpi-row">
            <?php
            $kpis = array(
                array( 'val' => $total_eleves,  'lbl' => 'élèves actifs',       'color' => '#2271b1', 'icon' => '👤' ),
                array( 'val' => count($stats_evol), 'lbl' => 'mois avec cours', 'color' => '#6d28d9', 'icon' => '📅' ),
                array( 'val' => $total_presents,'lbl' => 'présences totales',   'color' => '#15803d', 'icon' => '✅' ),
                array( 'val' => ($taux_global !== null ? $taux_global.'%' : '—'), 'lbl' => 'taux global', 'color' => ($taux_global >= 75 ? '#15803d' : ($taux_global >= 50 ? '#b45309' : '#b91c1c')), 'icon' => '📈' ),
            );
            foreach ( $kpis as $k ) : ?>
            <div class="sp-stats-kpi" style="border-top:3px solid <?php echo esc_attr($k['color']); ?>;">
                <div class="sp-stats-kpi-val" style="color:<?php echo esc_attr($k['color']); ?>;">
                    <?php echo esc_html($k['icon'] . ' ' . $k['val']); ?>
                </div>
                <div class="sp-stats-kpi-lbl"><?php echo esc_html($k['lbl']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Deux colonnes : par groupe / par discipline ── -->
        <div class="sp-two-col" style="align-items:start;">

        <!-- Taux par catégorie d'âge -->
        <div class="sp-box">
            <h2>👥 Par groupe d'âge</h2>
            <?php foreach ( $stats_groupe as $r ) :
                $nb_p  = intval($r->nb_presents);
                $nb_t  = $nb_p + intval($r->nb_absents);
                $taux  = $nb_t ? round($nb_p / $nb_t * 100) : 0;
                $color = $taux >= 75 ? '#22c55e' : ($taux >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <div class="sp-stats-bar-row">
                <div class="sp-stats-bar-label">
                    <span><?php echo esc_html($r->categorie_age); ?></span>
                    <span class="sp-muted"><?php echo intval($r->nb_eleves); ?> élèves</span>
                </div>
                <div class="sp-stats-bar-track">
                    <div class="sp-stats-bar-fill" style="width:<?php echo $taux; ?>%;background:<?php echo $color; ?>;"></div>
                </div>
                <div class="sp-stats-bar-pct" style="color:<?php echo $color; ?>;"><?php echo $taux; ?>%</div>
                <div class="sp-muted" style="font-size:11px;min-width:70px;text-align:right;">
                    <?php echo $nb_p; ?>/<?php echo $nb_t; ?> prés.
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Taux par catégorie saisie -->
        <div class="sp-box">
            <h2>🥋 Par discipline</h2>
            <?php if ( empty($stats_saisie) ) : ?>
                <p class="sp-muted">Aucune catégorie saisie configurée.</p>
            <?php else :
                foreach ( $stats_saisie as $r ) :
                    $nb_p  = intval($r->nb_presents);
                    $nb_t  = $nb_p + intval($r->nb_absents);
                    $taux  = $nb_t ? round($nb_p / $nb_t * 100) : 0;
                    $color = $taux >= 75 ? '#22c55e' : ($taux >= 50 ? '#f59e0b' : '#ef4444');
                ?>
                <div class="sp-stats-bar-row">
                    <div class="sp-stats-bar-label">
                        <span><?php echo esc_html( $this->db->label_discipline($r->categorie_saisie) ); ?></span>
                        <span class="sp-muted"><?php echo intval($r->nb_eleves); ?> élèves</span>
                    </div>
                    <div class="sp-stats-bar-track">
                        <div class="sp-stats-bar-fill" style="width:<?php echo $taux; ?>%;background:<?php echo $color; ?>;"></div>
                    </div>
                    <div class="sp-stats-bar-pct" style="color:<?php echo $color; ?>;"><?php echo $taux; ?>%</div>
                    <div class="sp-muted" style="font-size:11px;min-width:70px;text-align:right;">
                        <?php echo $nb_p; ?>/<?php echo $nb_t; ?> prés.
                    </div>
                </div>
                <?php endforeach;
            endif; ?>
        </div>

        </div><!-- .sp-two-col -->

        <!-- ── Évolution mensuelle ── -->
        <?php if ( ! empty($stats_evol) ) : ?>
        <div class="sp-box">
            <h2>📈 Évolution mensuelle</h2>
            <?php
            $max_presents = max( array_map(function($r){ return intval($r->nb_presents); }, $stats_evol) ) ?: 1;
            ?>
            <div class="sp-stats-evol-wrap">
                <?php foreach ( $stats_evol as $r ) :
                    $parts  = explode('-', $r->mois);
                    $lbl    = ($mois_fr[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
                    $nb_p   = intval($r->nb_presents);
                    $nb_t   = intval($r->nb_total);
                    $taux   = $nb_t ? round($nb_p / $nb_t * 100) : 0;
                    $height = round($nb_p / $max_presents * 100);
                    $color  = $taux >= 75 ? '#22c55e' : ($taux >= 50 ? '#f59e0b' : '#ef4444');
                ?>
                <div class="sp-stats-evol-col" title="<?php echo esc_attr($lbl . ' : ' . $nb_p . ' présences (' . $taux . '%)'); ?>">
                    <div class="sp-stats-evol-bar-wrap">
                        <div class="sp-stats-evol-bar" style="height:<?php echo $height; ?>%;background:<?php echo $color; ?>;"></div>
                    </div>
                    <div class="sp-stats-evol-val"><?php echo $nb_p; ?></div>
                    <div class="sp-stats-evol-lbl"><?php echo esc_html($lbl); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Classements ── -->
        <div class="sp-two-col" style="align-items:start;">

        <!-- Top assidus -->
        <div class="sp-box">
            <h2>🏆 Les plus assidus</h2>
            <table class="wp-list-table widefat striped">
                <thead><tr>
                    <th style="width:30px;">#</th>
                    <th>Élève</th>
                    <th>Groupe</th>
                    <th style="width:80px;text-align:center;">Taux</th>
                    <th style="width:70px;text-align:right;">Présences</th>
                </tr></thead>
                <tbody>
                <?php foreach ($top_assidus as $i => $r) :
                    $taux  = intval($r->taux);
                    $color = $taux >= 75 ? '#15803d' : ($taux >= 50 ? '#b45309' : '#b91c1c');
                    $fiche = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . intval($r->id));
                ?>
                <tr>
                    <td class="sp-muted"><?php echo $i+1; ?></td>
                    <td>
                        <a href="<?php echo esc_url($fiche); ?>" style="font-weight:600;">
                            <?php echo esc_html($r->prenom . ' ' . $r->nom); ?>
                        </a>
                        <?php if($r->grade) echo '<br><span class="sp-muted" style="font-size:11px;">' . esc_html($r->grade) . '</span>'; ?>
                    </td>
                    <td>
                        <?php if($r->categorie_age) echo '<span class="sp-badge-blue">' . esc_html($r->categorie_age) . '</span>'; ?>
                    </td>
                    <td style="text-align:center;">
                        <strong style="color:<?php echo $color; ?>;"><?php echo $taux; ?>%</strong>
                    </td>
                    <td style="text-align:right;color:#6b7280;font-size:12px;">
                        <?php echo intval($r->nb_presents); ?>/<?php echo intval($r->nb_total); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Moins assidus -->
        <div class="sp-box">
            <h2>⚠️ Absences fréquentes</h2>
            <table class="wp-list-table widefat striped">
                <thead><tr>
                    <th style="width:30px;">#</th>
                    <th>Élève</th>
                    <th>Groupe</th>
                    <th style="width:80px;text-align:center;">Taux</th>
                    <th style="width:70px;text-align:right;">Présences</th>
                </tr></thead>
                <tbody>
                <?php foreach ($top_absents as $i => $r) :
                    $taux  = intval($r->taux);
                    $color = $taux >= 75 ? '#15803d' : ($taux >= 50 ? '#b45309' : '#b91c1c');
                    $fiche = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . intval($r->id));
                ?>
                <tr>
                    <td class="sp-muted"><?php echo $i+1; ?></td>
                    <td>
                        <a href="<?php echo esc_url($fiche); ?>" style="font-weight:600;">
                            <?php echo esc_html($r->prenom . ' ' . $r->nom); ?>
                        </a>
                        <?php if($r->grade) echo '<br><span class="sp-muted" style="font-size:11px;">' . esc_html($r->grade) . '</span>'; ?>
                    </td>
                    <td>
                        <?php if($r->categorie_age) echo '<span class="sp-badge-blue">' . esc_html($r->categorie_age) . '</span>'; ?>
                    </td>
                    <td style="text-align:center;">
                        <strong style="color:<?php echo $color; ?>;"><?php echo $taux; ?>%</strong>
                    </td>
                    <td style="text-align:right;color:#6b7280;font-size:12px;">
                        <?php echo intval($r->nb_presents); ?>/<?php echo intval($r->nb_total); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        </div><!-- .sp-two-col -->
        <?php endif; ?>

        </div><!-- .wrap -->
        <?php
    }
}

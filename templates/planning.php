<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// $sp_cal_db est injecté par SpCalPro_Planning::render()
if ( ! isset( $sp_cal_db ) || ! ( $sp_cal_db instanceof SpCalPro_DB ) ) {
    echo '<p style="color:#c00;">Erreur : contexte SpCalPro_DB manquant.</p>';
    return;
}

/* ── Paramètres mois ─────────────────────────────────────────── */
$p_month = isset($_GET['plan_m']) ? intval($_GET['plan_m']) : intval(date('n'));
$p_year  = isset($_GET['plan_y']) ? intval($_GET['plan_y']) : intval(date('Y'));
if ( $p_month < 1 || $p_month > 12 ) $p_month = intval(date('n'));

$prev_m = $p_month === 1  ? 12 : $p_month - 1;
$prev_y = $p_month === 1  ? $p_year - 1 : $p_year;
$next_m = $p_month === 12 ? 1  : $p_month + 1;
$next_y = $p_month === 12 ? $p_year + 1 : $p_year;

// URL de base de la page courante (safe même en REST)
$base_url = function_exists('get_permalink') && get_the_ID() ? get_permalink() : ( isset($_SERVER['REQUEST_URI']) ? strtok( esc_url_raw( $_SERVER['REQUEST_URI'] ), '?' ) : '/' );

$months_fr = array(1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
                   7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre');
$days_court= array(1=>'LUN',2=>'MAR',3=>'MER',4=>'JEU',5=>'VEN',6=>'SAM',7=>'DIM');

$today    = date('Y-m-d');
$start_m  = sprintf('%04d-%02d-01', $p_year, $p_month);
// Étendre end_m jusqu'au dimanche de la dernière semaine du mois
// pour couvrir les semaines à cheval sur deux mois (ex: 27/04→03/05)
$end_m_raw  = date('Y-m-t', strtotime($start_m));
$dow_last   = intval( date('N', strtotime($end_m_raw)) ); // 1=lun … 7=dim
$end_m      = date('Y-m-d', strtotime($end_m_raw . ' +' . (7 - $dow_last) . ' days'));
// Étendre start_m au lundi de la première semaine (semaines à cheval en début de mois)
$dow_first  = intval( date('N', strtotime($start_m)) ); // 1=lun … 7=dim
$start_m    = $dow_first > 1
    ? date('Y-m-d', strtotime($start_m . ' -' . ($dow_first - 1) . ' days'))
    : $start_m;

/* ── Données ─────────────────────────────────────────────────── */
$cat_colors   = json_decode(get_option('sp_cal_cat_colors','{}'), true) ?: array();
$sp_we_color  = get_option('sp_cal_we_color',  '#f3f4f6');
$sp_vac_color = get_option('sp_cal_vac_color', '#fef9c3');
$sp_vacances  = json_decode(get_option('sp_cal_vacances_zoneC','[]'), true) ?: array();

if ( ! function_exists('sp_cal_bg_day') ) :
function sp_cal_bg_day( $ds, $we_color, $vac_color, $vacances ) {
    // Vacances Zone C — priorité sur WE
    foreach ( $vacances as $v ) {
        if ( $ds >= $v['start'] && $ds <= $v['end'] ) return $vac_color;
    }
    // Week-end (6=sam, 0=dim)
    $dow = intval(date('w', strtotime($ds)));
    if ( $dow === 0 || $dow === 6 ) return $we_color;
    return '';
}
endif;

$all_occ = $sp_cal_db->get_slot_occurrences($start_m, $end_m);

global $wpdb;
$te       = $sp_cal_db->table_events();
// Ponctuels VRAIS uniquement (slot_id IS NULL = pas une matérialisation de créneau récurrent)
$ponctuels= $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $te WHERE date BETWEEN %s AND %s AND type IN ('cours','evenement','examen','competition') AND slot_id IS NULL ORDER BY date ASC, heure_debut ASC",
    $start_m, $end_m
));

// Pour les créneaux récurrents matérialisés : récupérer les données enrichies (document, description)
// et remplacer l'occurrence "vide" par les données réelles de la matérialisation
$mat_keys = array();
foreach ($all_occ as $occ) {
    if (!empty($occ['mat_id'])) $mat_keys[$occ['mat_id']] = true;
}
$mat_rows = array();
if (!empty($mat_keys)) {
    $mat_ids_list = implode(',', array_map('intval', array_keys($mat_keys)));
    $rows = $wpdb->get_results("SELECT id, document_url, document_nom FROM $te WHERE id IN ($mat_ids_list)");
    foreach ($rows as $r) $mat_rows[intval($r->id)] = $r;
}

// Grouper tout par date
$occ_by_date = array();
foreach ($all_occ as $occ) {
    $mat = !empty($occ['mat_id']) ? ($mat_rows[$occ['mat_id']] ?? null) : null;
    $occ_by_date[$occ['date']][] = array_merge($occ, array(
        'ponctuel'     => false,
        'document_url' => $mat ? ($mat->document_url ?? '') : '',
        'document_nom' => $mat ? ($mat->document_nom ?? '') : '',
    ));
}
foreach ($ponctuels as $ev) {
    $occ_by_date[$ev->date][] = array(
        'slot_id'=>null,'date'=>$ev->date,'heure_debut'=>$ev->heure_debut,'heure_fin'=>$ev->heure_fin,
        'titre'=>$ev->titre,'categorie'=>$ev->categorie,'recurrence'=>null,'annul_id'=>null,'ponctuel'=>true,
        'type'  => $ev->type ?? '',
        'id'    => $ev->id   ?? 0,
        'document_url' => $ev->document_url ?? '', 'document_nom' => $ev->document_nom ?? '',
    );
}

// Normalisation des heures : convertit HH:MM → HHhMM pour uniformiser avec les créneaux récurrents
if ( ! function_exists('sp_normalize_time') ) {
    function sp_normalize_time($t) {
        if (!$t) return $t;
        return preg_replace('/^(\d{2}):(\d{2})$/', '$1h$2', $t);
    }
}

// Normaliser les heures dans occ_by_date (les ponctuels sont stockés HH:MM par le formulaire)
foreach ($occ_by_date as $date => &$items) {
    foreach ($items as &$item) {
        $item['heure_debut'] = sp_normalize_time($item['heure_debut']);
        $item['heure_fin']   = sp_normalize_time($item['heure_fin']);
    }
}
unset($items, $item);

// Jours actifs — toujours afficher les 7 jours pour une grille homogène
$active_dows = array(1=>true,2=>true,3=>true,4=>true,5=>true,6=>true,7=>true);

// Clés horaires — uniquement depuis les occurrences (les ponctuels ont déjà été normalisés ci-dessus)
$time_keys = array();
foreach ($all_occ as $occ) {
    $k = sp_normalize_time($occ['heure_debut']).'|'.sp_normalize_time($occ['heure_fin']);
    if (!in_array($k,$time_keys)) $time_keys[] = $k;
}
foreach ($ponctuels as $ev) {
    $k = sp_normalize_time($ev->heure_debut).'|'.sp_normalize_time($ev->heure_fin);
    if (!in_array($k,$time_keys)) $time_keys[] = $k;
}
sort($time_keys);

// Semaines du mois
$first     = new DateTime($start_m);
$dow_first = intval($first->format('N'));
$monday    = clone $first;
if ($dow_first > 1) $monday->modify('-'.($dow_first-1).' days');

$weeks = array();
for ($w=0;$w<6;$w++) {
    $ws = clone $monday; $ws->modify('+'.($w*7).' days');
    $we = clone $ws;     $we->modify('+6 days');
    $mid= clone $ws;     $mid->modify('+3 days');
    if (intval($mid->format('n')) === $p_month) {
        $weeks[] = array('start'=>$ws,'end'=>$we,'is_past'=>$we->format('Y-m-d') < $today);
    }
}
?>

<div id="sp-planning-wrapper">

    <!-- Navigation mois -->
    <div class="sp-planning-nav">
        <a href="<?php echo esc_url( $base_url . '?plan_m=' . $prev_m . '&plan_y=' . $prev_y ); ?>" class="sp-plan-nav-btn">&#8249;</a>
        <h2 class="sp-planning-title"><?php echo $months_fr[$p_month].' '.$p_year; ?></h2>
        <a href="<?php echo esc_url( $base_url . '?plan_m=' . $next_m . '&plan_y=' . $next_y ); ?>" class="sp-plan-nav-btn">&#8250;</a>
        <button class="sp-plan-toggle-past button" onclick="spTogglePast(this)" style="margin-left:16px;">
            Masquer semaines passées
        </button>
    </div>

    <?php if (empty($weeks)): ?>
        <p style="text-align:center;color:#888;padding:30px;">Aucune semaine à afficher.</p>
    <?php else: ?>

    <?php foreach ($weeks as $wi => $week):
        $ws_str = $week['start']->format('Y-m-d');
        $we_str = $week['end']->format('Y-m-d');
        $is_past = $week['is_past'];
        $is_current = ($today >= $ws_str && $today <= $we_str);

        // Vérifier si cette semaine a du contenu
        $has_content = false;
        foreach ($active_dows as $dow => $_) {
            $day_dt  = clone $week['start'];
            $day_diff= ($dow - intval($week['start']->format('N')) + 7) % 7;
            if ($day_diff === 0 && $dow !== intval($week['start']->format('N'))) $day_diff = 7;
            $day_dt->modify("+$day_diff days");
            $ds = $day_dt->format('Y-m-d');
            if (!empty($occ_by_date[$ds])) { $has_content = true; break; }
        }
    ?>

    <div class="sp-week-block<?php echo $is_past?' sp-week-past':''; ?><?php echo $is_current?' sp-week-current':''; ?>">

        <div class="sp-week-header">
            <span class="sp-week-label">
                <?php echo $is_current ? '📍 ' : ''; ?>
                Semaine du <?php
                    $ws_disp = $week['start']->format('d/m');
                    $we_disp = $week['end']->format('d/m');
                    echo esc_html("$ws_disp au $we_disp");
                ?>
            </span>
            <?php if (!$has_content): ?>
            <span class="sp-week-empty">Aucun cours cette semaine</span>
            <?php endif; ?>
        </div>

        <?php if ($has_content): ?>
        <div class="sp-planning-scroll">
        <table class="sp-planning-table">
            <thead>
            <tr>
                <th class="sp-th-time">Horaire</th>
                <?php foreach ($active_dows as $dow => $_):
                    $day_dt  = clone $week['start'];
                    $diff    = ($dow - intval($week['start']->format('N')) + 7) % 7;
                    if ($diff === 0 && $dow !== intval($week['start']->format('N'))) $diff = 7;
                    $day_dt->modify("+$diff days");
                    $ds      = $day_dt->format('Y-m-d');
                    $is_today= ($ds === $today);
                ?>
                <th class="sp-th-day<?php echo $is_today?' sp-col-today':''; ?>"<?php
                    $bg = sp_cal_bg_day($ds, $sp_we_color, $sp_vac_color, $sp_vacances);
                    if ($bg && !$is_today) echo ' style="background:'.esc_attr($bg).';"';
                ?>>
                    <?php echo $days_court[$dow]; ?><br>
                    <span style="font-size:9px;font-weight:400;opacity:.8;"><?php echo $day_dt->format('d/m'); ?></span>
                </th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($time_keys as $tk):
                list($h_debut, $h_fin) = explode('|', $tk);
                // Vérifier si cette ligne a du contenu pour cette semaine
                $row_has = false;
                foreach ($active_dows as $dow => $_) {
                    $day_dt = clone $week['start'];
                    $diff   = ($dow - intval($week['start']->format('N')) + 7) % 7;
                    $day_dt->modify("+$diff days");
                    $ds = $day_dt->format('Y-m-d');
                    if (!empty($occ_by_date[$ds])) {
                        foreach ($occ_by_date[$ds] as $item) {
                            if ($item['heure_debut'] === $h_debut && $item['heure_fin'] === $h_fin) { $row_has=true; break 2; }
                        }
                    }
                }
                if (!$row_has) continue;
            ?>
            <tr>
                <td class="sp-td-time">
                    <?php
                    // Format heure "08h30 – 09h30" ou "08:30 – 09:30"
                    echo esc_html($h_debut.($h_fin?' – '.$h_fin:''));
                    ?>
                </td>
                <?php foreach ($active_dows as $dow => $_):
                    $day_dt = clone $week['start'];
                    $diff   = ($dow - intval($week['start']->format('N')) + 7) % 7;
                    $day_dt->modify("+$diff days");
                    $ds     = $day_dt->format('Y-m-d');
                    $is_today = ($ds === $today);
                ?>
                <td class="sp-td-slot<?php echo $is_today?' sp-col-today':''; ?>"<?php
                    $bg = sp_cal_bg_day($ds, $sp_we_color, $sp_vac_color, $sp_vacances);
                    if ($bg && !$is_today) echo ' style="background:'.esc_attr($bg).';"';
                ?>>
                    <?php
                    if (!empty($occ_by_date[$ds])) {
                        foreach ($occ_by_date[$ds] as $item) {
                            if ($item['heure_debut'] !== $h_debut || $item['heure_fin'] !== $h_fin) continue;
                            $cat   = $item['categorie'] ?? '';
                            $saved = isset($cat_colors[$cat]) ? $cat_colors[$cat] : array();
                            $color = $saved['color'] ?? '#3B82F6';
                            $icon  = $saved['icon']  ?? '';
                            $is_annule = !empty($item['annul_id']);
                            $is_ponct  = !empty($item['ponctuel']);
                            $class = $is_annule ? 'sp-plan-event sp-plan-annule' : ($is_ponct ? 'sp-plan-event sp-plan-ponctuel' : 'sp-plan-event');
                            $style = $is_annule
                                ? 'border-left:4px solid #ef4444;background:#fef2f2;color:#991b1b;'
                                : "background:{$color};color:#fff;";
                    ?>
                    <div class="<?php echo $class; ?>" style="<?php echo esc_attr($style); ?>">
                        <?php if ($is_annule): ?>
                            <span style="color:#ef4444;font-size:9px;">🚫 Annulé</span>
                            <span style="text-decoration:line-through;opacity:.5;font-size:10px;"><?php echo esc_html($item['titre']); ?></span>
                        <?php else: ?>
                            <?php if ($icon) echo '<span class="sp-plan-icon">'.esc_html($icon).'</span> '; ?>
                            <?php if ($is_ponct): ?>
                                <span class="sp-plan-label">⚡ <?php echo esc_html($item['titre']); ?></span>
                            <?php else: ?>
                                <span class="sp-plan-label"><?php echo esc_html($item['titre']); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty($item['document_url']) ) :
                                // Compétition passée avec résultats → lien palmarès
                                $is_comp_passee = ( $item['type'] ?? '' ) === 'competition'
                                    && isset($item['date']) && $item['date'] < $today
                                    && ! empty( $item['id'] ?? 0 );
                                $palm_url = '';
                                if ( $is_comp_passee && class_exists('SpCalPro_PalmaresFront') ) {
                                    $eid      = intval( $item['id'] );
                                    $palm_url = SpCalPro_PalmaresFront::get_page_url( $eid );
                                }
                                if ( $palm_url ) : ?>
                                <a href="<?php echo esc_url($palm_url); ?>"
                                   class="sp-plan-doc-link" title="Voir les résultats">🏆</a>
                                <?php else : ?>
                                <a href="<?php echo esc_url($item['document_url']); ?>" target="_blank" download
                                   class="sp-plan-doc-link" title="<?php echo esc_attr($item['document_nom'] ?: 'Télécharger le document'); ?>">📄</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php
                        } // foreach item
                    } // if occ_by_date
                    ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; // time_keys ?>
            </tbody>
        </table>
        </div><!-- .sp-planning-scroll -->

        <!-- Légende catégories -->
        <?php
        $cats_week = array();
        foreach ($active_dows as $dow => $_) {
            $d2 = clone $week['start']; $d2->modify( '+' . ( ( $dow - intval( $week['start']->format('N') ) + 7 ) % 7 ) . ' days' );
            $ds = $d2->format('Y-m-d');
            if (!empty($occ_by_date[$ds])) foreach ($occ_by_date[$ds] as $item) { if ($item['categorie'] && empty($item['annul_id'])) $cats_week[$item['categorie']]=true; }
        }
        if ($cats_week): ?>
        <div class="sp-planning-legend">
            <?php foreach (array_keys($cats_week) as $cat):
                $saved = isset($cat_colors[$cat]) ? $cat_colors[$cat] : array();
                $color = $saved['color'] ?? '#3B82F6';
                $icon  = $saved['icon']  ?? '';
            ?>
            <span class="sp-legend-item">
                <span class="sp-legend-dot" style="background:<?php echo esc_attr($color); ?>;"></span>
                <?php echo ($icon?esc_html($icon).' ':'').esc_html($cat); ?>
            </span>
            <?php endforeach; ?>
            <?php if ( $sp_we_color ) : ?>
            <span class="sp-legend-item" style="margin-left:8px;border-left:1px solid #e2e8f0;padding-left:12px;">
                <span class="sp-legend-dot" style="background:<?php echo esc_attr($sp_we_color); ?>;border:1px solid #d1d5db;border-radius:2px;width:14px;height:14px;"></span>
                Week-end
            </span>
            <?php endif; ?>
            <?php if ( $sp_vac_color && ! empty($sp_vacances) ) : ?>
            <span class="sp-legend-item">
                <span class="sp-legend-dot" style="background:<?php echo esc_attr($sp_vac_color); ?>;border:1px solid #d1d5db;border-radius:2px;width:14px;height:14px;"></span>
                Vacances scolaires
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; // has_content ?>

    </div><!-- .sp-week-block -->
    <?php endforeach; // weeks ?>
    <?php endif; ?>

</div><!-- #sp-planning-wrapper -->

<style>
/* Règles propres au template planning — le bandeau nav (.sp-planning-nav, .sp-planning-title,
   .sp-plan-nav-btn) est géré par calendar.css (v10.14) et ne doit PAS être redéfini ici. */
#sp-planning-wrapper { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; max-width:100%; }

.sp-week-block { margin-bottom:24px; border-radius:10px; overflow:hidden; border:1px solid #e2e8f0; background:#fff; }
.sp-week-current { border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,.15); }
.sp-week-past { opacity:.7; }
.sp-week-header { background:#1e3a5f; padding:10px 16px; display:flex; align-items:center; gap:12px; border-bottom:1px solid rgba(255,255,255,.15); }
.sp-week-label { font-weight:700; font-size:13px; color:#fff; }
.sp-week-empty { font-size:12px; color:rgba(255,255,255,.6); margin-left:auto; }

.sp-planning-scroll { overflow-x:auto; }
.sp-planning-table { width:100%; border-collapse:collapse; min-width:500px; }
.sp-th-time { background:#1e3a5f; color:#fff; padding:7px 8px; font-size:10px; text-transform:uppercase; width:72px; text-align:center; border-right:1px solid rgba(255,255,255,.2); }
.sp-th-day { background:#1e3a5f; color:#fff; padding:7px 6px; font-size:10px; text-transform:uppercase; text-align:center; min-width:100px; border-right:1px solid rgba(255,255,255,.2); }
.sp-th-day.sp-col-today { background:#2563eb; }
.sp-td-time { background:#f8fafc; color:#374151; font-weight:700; font-size:10px; text-align:center; padding:7px 5px; border-bottom:1px solid #e2e8f0; border-right:1px solid #e2e8f0; white-space:nowrap; }
.sp-td-slot { padding:5px 6px; border-bottom:1px solid #e2e8f0; border-right:1px solid #e2e8f0; vertical-align:top; min-height:36px; }
.sp-td-slot.sp-col-today { background:#eff6ff; }
.sp-plan-annule { opacity:.7; }

.sp-planning-legend { display:flex !important; flex-wrap:wrap; gap:10px; padding:10px 16px; border-top:1px solid #f0f0f0; background:#fafafa !important; visibility:visible !important; opacity:1 !important; }
.sp-legend-item { display:flex !important; align-items:center; gap:5px; font-size:12px; color:#374151; font-weight:600; }
.sp-legend-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
</style>

<script>
function spTogglePast(btn){
    var hiding = btn.dataset.hiding !== '1';
    btn.dataset.hiding = hiding ? '1' : '0';
    btn.textContent = hiding ? 'Afficher semaines passées' : 'Masquer semaines passées';
    document.querySelectorAll('.sp-week-past').forEach(function(el){
        el.style.display = hiding ? 'none' : '';
    });
}
// Toutes les semaines du mois sont visibles par défaut — le bouton permet de
// masquer les semaines déjà passées pour qui préfère un affichage plus court.
</script>

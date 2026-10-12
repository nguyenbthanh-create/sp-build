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
$sp_vacances  = SpCalPro_DB::get_vacances();

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

// Cours NON hebdomadaires (1 fois par mois, 1 semaine sur 2…) : leur fréquence est écrite sur la
// pastille, un clic ouvre leurs prochaines dates, et un bloc sous le planning les rappelle même les
// semaines où ils n'ont pas lieu (12/10/2026). Toujours calculé à partir d'aujourd'hui, quel que
// soit le mois affiché.
$sp_seances = array();
foreach ( $sp_cal_db->prochaines_seances_creneaux( $today ) as $sp_c ) {
    $sp_c['couleur']   = SpCalPro_DB::style_categorie( $sp_c['categorie'], $cat_colors )['color'];
    $sp_c['prochaine'] = '';
    foreach ( $sp_c['seances'] as $sp_i => $sp_s ) {
        $sp_c['seances'][ $sp_i ]['libelle'] = date_i18n( 'l j F Y', strtotime( $sp_s['date'] . ' 12:00:00' ) );
        if ( $sp_c['prochaine'] === '' && ! $sp_s['annule'] ) $sp_c['prochaine'] = $sp_c['seances'][ $sp_i ]['libelle'];
    }
    $sp_seances[ $sp_c['slot_id'] ] = $sp_c;
}

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
        'couleur' => $ev->couleur ?? '',
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
                    <span class="sp-th-date"><?php echo $day_dt->format('d/m'); ?></span>
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
                            // Même règle que le calendrier de gestion : couleur de la catégorie pour un cours,
                            // couleur du type pour un examen ou une compétition, couleur propre pour un événement.
                            $saved = SpCalPro_DB::style_element( $item['type'] ?? '', $cat, $item['couleur'] ?? '', $cat_colors );
                            $color = $saved['color'];
                            $icon  = $saved['icon'];
                            $is_annule = !empty($item['annul_id']);
                            $is_ponct  = !empty($item['ponctuel']);
                            $class = $is_annule ? 'sp-plan-event sp-plan-annule' : ($is_ponct ? 'sp-plan-event sp-plan-ponctuel' : 'sp-plan-event');
                            // Couleur de catégorie exposée en variable CSS : le style sobre (teinte claire +
                            // liseré coloré) est défini dans la feuille du template ci-dessous.
                            $style = $is_annule ? '' : '--c:' . $color . ';';
                            // Cours non hebdomadaire : fréquence affichée, et pastille cliquable s'il a des séances à venir.
                            $freq  = ( $is_ponct || $is_annule ) ? '' : SpCalPro_DB::libelle_frequence( $item['recurrence'] ?? '' );
                            $attrs = '';
                            $id_cr = intval( $item['slot_id'] ?? 0 );
                            if ( $freq !== '' && isset( $sp_seances[ $id_cr ] ) ) {
                                $class .= ' sp-plan-cliquable';
                                $attrs  = ' role="button" tabindex="0" data-sp-seances="' . $id_cr . '" title="Voir les prochaines dates"';
                            }
                    ?>
                    <div class="<?php echo $class; ?>" style="<?php echo esc_attr($style); ?>"<?php echo $attrs; ?>>
                        <?php if ($is_annule): ?>
                            <span class="sp-plan-annule-tag">🚫 Annulé</span>
                            <span class="sp-plan-annule-titre"><?php echo esc_html($item['titre']); ?></span>
                        <?php else: ?>
                            <?php if ($icon) echo '<span class="sp-plan-icon">'.esc_html($icon).'</span> '; ?>
                            <?php if ($is_ponct): ?>
                                <span class="sp-plan-label">⚡ <?php echo esc_html($item['titre']); ?></span>
                            <?php else: ?>
                                <span class="sp-plan-label"><?php echo esc_html($item['titre']); ?></span>
                                <?php if ( $freq !== '' ) echo '<span class="sp-plan-freq">' . esc_html( $freq ) . '</span>'; ?>
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
        $cats_week = array(); $types_week = array();
        foreach ($active_dows as $dow => $_) {
            $d2 = clone $week['start']; $d2->modify( '+' . ( ( $dow - intval( $week['start']->format('N') ) + 7 ) % 7 ) . ' days' );
            $ds = $d2->format('Y-m-d');
            if (!empty($occ_by_date[$ds])) foreach ($occ_by_date[$ds] as $item) {
                if ( ! empty($item['annul_id']) ) continue;
                $type_item = $item['type'] ?? '';
                // La légende des catégories ne concerne que les cours ; examens et compétitions ont la leur.
                if ( isset( SpCalPro_DB::COULEURS_TYPES[ $type_item ] ) ) $types_week[ $type_item ] = true;
                elseif ( $type_item !== 'evenement' && $item['categorie'] ) $cats_week[$item['categorie']]=true;
            }
        }
        if ($cats_week || $types_week): ?>
        <div class="sp-planning-legend">
            <?php foreach ( array( 'examen' => 'Examen', 'competition' => 'Compétition' ) as $type_leg => $lib_leg ) : if ( empty( $types_week[ $type_leg ] ) ) continue; ?>
            <span class="sp-legend-item">
                <span class="sp-legend-dot" style="background:<?php echo esc_attr( SpCalPro_DB::COULEURS_TYPES[ $type_leg ] ); ?>;"></span>
                <?php echo esc_html( $lib_leg ); ?>
            </span>
            <?php endforeach; ?>
            <?php foreach (array_keys($cats_week) as $cat):
                $saved = SpCalPro_DB::style_categorie( $cat, $cat_colors );
                $color = $saved['color'];
                $icon  = $saved['icon'];
            ?>
            <span class="sp-legend-item">
                <span class="sp-legend-dot" style="background:<?php echo esc_attr($color); ?>;"></span>
                <?php echo ($icon?esc_html($icon).' ':'').esc_html($cat); ?>
            </span>
            <?php endforeach; ?>
            <?php if ( $sp_we_color ) : ?>
            <span class="sp-legend-item sp-legend-sep">
                <span class="sp-legend-dot sp-legend-swatch" style="background:<?php echo esc_attr($sp_we_color); ?>;"></span>
                Week-end
            </span>
            <?php endif; ?>
            <?php if ( $sp_vac_color && ! empty($sp_vacances) ) : ?>
            <span class="sp-legend-item">
                <span class="sp-legend-dot sp-legend-swatch" style="background:<?php echo esc_attr($sp_vac_color); ?>;"></span>
                Vacances scolaires
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; // has_content ?>

    </div><!-- .sp-week-block -->
    <?php endforeach; // weeks ?>
    <?php endif; ?>

    <?php if ( $sp_seances ) : ?>
    <!-- Cours non hebdomadaires : prochaine séance de chacun, toujours visible -->
    <div class="sp-plan-speciaux">
        <h3 class="sp-plan-speciaux-titre">Cours qui n'ont pas lieu chaque semaine</h3>
        <ul class="sp-plan-speciaux-liste">
        <?php foreach ( $sp_seances as $sp_c ) : ?>
            <li style="--c:<?php echo esc_attr( $sp_c['couleur'] ); ?>;">
                <span class="sp-plan-speciaux-nom"><?php echo esc_html( $sp_c['titre'] ); ?></span>
                <span class="sp-plan-speciaux-info"><?php echo esc_html( $sp_c['frequence'] . ( $sp_c['horaire'] !== '' ? ' · ' . $sp_c['horaire'] : '' ) ); ?></span>
                <span class="sp-plan-speciaux-date"><?php echo $sp_c['prochaine'] !== '' ? 'Prochaine séance : <strong>' . esc_html( $sp_c['prochaine'] ) . '</strong>' : 'Aucune séance maintenue à venir'; ?></span>
                <button type="button" class="sp-plan-speciaux-btn" data-sp-seances="<?php echo intval( $sp_c['slot_id'] ); ?>">Toutes les dates</button>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>

    <div id="sp-plan-modal" hidden>
        <div class="sp-plan-modal-boite" role="dialog" aria-modal="true" aria-labelledby="sp-plan-modal-titre">
            <button type="button" class="sp-plan-modal-fermer" aria-label="Fermer">&times;</button>
            <h3 id="sp-plan-modal-titre"></h3>
            <p class="sp-plan-modal-info"></p>
            <ul class="sp-plan-modal-dates"></ul>
            <p class="sp-plan-modal-note">Dates données à titre indicatif : une séance peut être annulée ou déplacée, le planning fait foi.</p>
        </div>
    </div>
    <script type="application/json" id="sp-plan-seances"><?php echo wp_json_encode( $sp_seances, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?></script>
    <?php endif; ?>

</div><!-- #sp-planning-wrapper -->

<style>
/* Habillage sobre du planning, aligné sur tkdclaira.fr : fond blanc, texte #222, titres Lato,
   rouge #D4000F en accent unique. Tout est préfixé #sp-planning-wrapper pour l'emporter sur
   les styles de table du thème sans toucher au calendrier (calendar.css partage .sp-plan-nav-btn). */
#sp-planning-wrapper {
    --sp-red:#D4000F; --sp-ink:#222; --sp-muted:#6b6b6b; --sp-line:#e8e5e2; --sp-soft:#f7f5f3;
    font-family:inherit; color:var(--sp-ink); max-width:100%;
}

/* Navigation mois */
#sp-planning-wrapper .sp-planning-nav {
    background:transparent; color:var(--sp-ink); border-radius:0; padding:0 0 14px; margin-bottom:22px;
    border-bottom:1px solid var(--sp-line); gap:14px; flex-wrap:wrap;
}
#sp-planning-wrapper .sp-planning-title {
    font-family:Lato,sans-serif; font-size:22px; font-weight:400; letter-spacing:.14em; color:var(--sp-ink);
    text-transform:uppercase; position:relative; padding-bottom:10px;
}
#sp-planning-wrapper .sp-planning-title::after {
    content:''; position:absolute; left:50%; bottom:0; width:36px; height:2px; margin-left:-18px; background:var(--sp-red);
}
#sp-planning-wrapper .sp-plan-nav-btn {
    background:#fff; color:var(--sp-ink); border:1px solid #d9d5d1; border-radius:50%; width:36px; height:36px;
    font-size:20px; font-weight:400; transition:border-color .15s,color .15s;
}
#sp-planning-wrapper .sp-plan-nav-btn:hover { background:#fff; border-color:var(--sp-red); color:var(--sp-red); }
#sp-planning-wrapper .sp-plan-toggle-past {
    background:transparent; color:var(--sp-muted); border:1px solid #d9d5d1; border-radius:20px; padding:6px 14px;
    font-size:12px; letter-spacing:.03em; margin-left:0 !important; transition:border-color .15s,color .15s;
}
#sp-planning-wrapper .sp-plan-toggle-past:hover { border-color:var(--sp-red); color:var(--sp-red); background:transparent; }

/* Bloc semaine */
#sp-planning-wrapper .sp-week-block { margin-bottom:26px; border:1px solid var(--sp-line); border-radius:6px; overflow:hidden; background:#fff; box-shadow:none; }
#sp-planning-wrapper .sp-week-current { border-color:var(--sp-line); box-shadow:inset 3px 0 0 var(--sp-red); }
#sp-planning-wrapper .sp-week-past { opacity:.6; }
#sp-planning-wrapper .sp-week-header { background:#fff; padding:12px 18px; border-bottom:1px solid var(--sp-line); }
#sp-planning-wrapper .sp-week-label { font-family:Lato,sans-serif; font-size:12px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--sp-ink); }
#sp-planning-wrapper .sp-week-current .sp-week-label { color:var(--sp-red); }
#sp-planning-wrapper .sp-week-empty { font-size:12px; color:var(--sp-muted); font-style:italic; }

/* Tableau */
#sp-planning-wrapper .sp-planning-scroll { overflow-x:auto; }
#sp-planning-wrapper .sp-planning-table { width:100%; border-collapse:collapse; min-width:640px; margin:0; border:0; table-layout:fixed; }
#sp-planning-wrapper .sp-planning-table th,
#sp-planning-wrapper .sp-planning-table td { border:0; border-bottom:1px solid #eeebe8; border-right:1px solid #f1eeeb; }
#sp-planning-wrapper .sp-planning-table th:last-child,
#sp-planning-wrapper .sp-planning-table td:last-child { border-right:0; }
#sp-planning-wrapper .sp-planning-table tr:last-child td { border-bottom:0; }
#sp-planning-wrapper .sp-th-time,
#sp-planning-wrapper .sp-th-day {
    background:var(--sp-soft); color:var(--sp-ink); font-family:Lato,sans-serif; font-size:11px; font-weight:700;
    letter-spacing:.1em; padding:10px 6px; border-bottom:1px solid var(--sp-line);
}
#sp-planning-wrapper .sp-th-time { width:96px; color:var(--sp-muted); }
#sp-planning-wrapper .sp-th-date { display:block; font-size:11px; font-weight:400; letter-spacing:.02em; color:var(--sp-muted); margin-top:2px; }
#sp-planning-wrapper .sp-th-day.sp-col-today { background:var(--sp-soft); color:var(--sp-red); box-shadow:inset 0 -2px 0 var(--sp-red); }
#sp-planning-wrapper .sp-th-day.sp-col-today .sp-th-date { color:var(--sp-red); }
#sp-planning-wrapper .sp-td-time { background:#fcfbfa; color:var(--sp-muted); font-size:12px; font-weight:600; padding:10px 6px; white-space:nowrap; }
#sp-planning-wrapper .sp-td-slot { background:#fff; padding:6px; vertical-align:top; }
#sp-planning-wrapper .sp-td-slot.sp-col-today { background:#fdf6f6; }

/* Pastilles de cours : teinte claire + liseré à la couleur de la catégorie */
#sp-planning-wrapper .sp-plan-event {
    --c:#8a8a8a; background:#f4f4f4; color:var(--sp-ink); border-left:3px solid var(--c); border-radius:3px;
    padding:5px 8px; margin-bottom:4px; font-size:12px; line-height:1.35; overflow:hidden;
}
@supports (background:color-mix(in srgb, red 10%, white)) {
    #sp-planning-wrapper .sp-plan-event { background:color-mix(in srgb, var(--c) 14%, #fff); }
}
#sp-planning-wrapper .sp-plan-label { font-size:12px; font-weight:600; }
#sp-planning-wrapper .sp-plan-icon { font-size:12px; }
#sp-planning-wrapper .sp-plan-doc-link { color:var(--sp-ink); }
#sp-planning-wrapper .sp-plan-event.sp-plan-annule { --c:var(--sp-red); background:#fdf2f2; color:#8a1c1c; opacity:1; }
#sp-planning-wrapper .sp-plan-annule-tag { color:var(--sp-red); font-size:11px; font-weight:700; margin-right:4px; }
#sp-planning-wrapper .sp-plan-annule-titre { text-decoration:line-through; opacity:.55; font-size:12px; }

/* Légende */
#sp-planning-wrapper .sp-planning-legend { display:flex !important; flex-wrap:wrap; gap:8px 18px; padding:12px 18px; border-top:1px solid var(--sp-line); background:#fff !important; visibility:visible !important; opacity:1 !important; }
#sp-planning-wrapper .sp-legend-item { display:flex !important; align-items:center; gap:6px; font-size:12px; color:var(--sp-muted); font-weight:400; }
#sp-planning-wrapper .sp-legend-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
#sp-planning-wrapper .sp-legend-swatch { width:14px; height:14px; border-radius:2px; border:1px solid #d9d5d1; }
#sp-planning-wrapper .sp-legend-sep { margin-left:6px; padding-left:18px; border-left:1px solid var(--sp-line); }

/* Cours non hebdomadaires : fréquence sur la pastille, bloc sous le planning, fenêtre des dates */
#sp-planning-wrapper .sp-plan-freq { display:block; font-size:10px; font-weight:400; color:var(--sp-muted); letter-spacing:.02em; margin-top:1px; }
#sp-planning-wrapper .sp-plan-cliquable { cursor:pointer; }
#sp-planning-wrapper .sp-plan-cliquable:hover,
#sp-planning-wrapper .sp-plan-cliquable:focus { outline:1px solid var(--c); outline-offset:1px; }
#sp-planning-wrapper .sp-plan-speciaux { border:1px solid var(--sp-line); border-radius:6px; background:#fff; padding:16px 18px; margin-bottom:26px; }
#sp-planning-wrapper .sp-plan-speciaux-titre { font-family:Lato,sans-serif; font-size:12px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--sp-ink); margin:0 0 10px; }
#sp-planning-wrapper .sp-plan-speciaux-liste { list-style:none; margin:0; padding:0; }
#sp-planning-wrapper .sp-plan-speciaux-liste li { --c:#8a8a8a; display:flex; flex-wrap:wrap; align-items:center; gap:4px 14px; border-left:3px solid var(--c); padding:8px 0 8px 12px; margin:0 0 6px; font-size:13px; }
#sp-planning-wrapper .sp-plan-speciaux-nom { font-weight:700; }
#sp-planning-wrapper .sp-plan-speciaux-info { color:var(--sp-muted); font-size:12px; }
#sp-planning-wrapper .sp-plan-speciaux-date { flex:1 1 220px; }
#sp-planning-wrapper .sp-plan-speciaux-btn { background:transparent; color:var(--sp-muted); border:1px solid #d9d5d1; border-radius:20px; padding:5px 12px; font-size:12px; cursor:pointer; }
#sp-planning-wrapper .sp-plan-speciaux-btn:hover { border-color:var(--sp-red); color:var(--sp-red); background:transparent; }
#sp-plan-modal { position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,.5); display:flex; align-items:center; justify-content:center; padding:16px; }
#sp-plan-modal[hidden] { display:none; }
#sp-plan-modal .sp-plan-modal-boite { position:relative; background:#fff; color:#222; border-radius:8px; width:420px; max-width:100%; max-height:88vh; overflow:auto; padding:22px 24px; box-shadow:0 8px 40px rgba(0,0,0,.25); border-top:4px solid var(--c,#D4000F); }
#sp-plan-modal h3 { margin:0 28px 4px 0; font-size:18px; }
#sp-plan-modal .sp-plan-modal-info { margin:0 0 14px; font-size:13px; color:#6b6b6b; }
#sp-plan-modal .sp-plan-modal-dates { list-style:none; margin:0 0 14px; padding:0; }
#sp-plan-modal .sp-plan-modal-dates li { padding:8px 0; border-bottom:1px solid #eeebe8; font-size:14px; }
#sp-plan-modal .sp-plan-modal-dates li::first-letter { text-transform:uppercase; }
#sp-plan-modal .sp-plan-modal-dates li.sp-annulee { color:#8a1c1c; }
#sp-plan-modal .sp-plan-modal-dates li.sp-annulee span { text-decoration:line-through; opacity:.6; }
#sp-plan-modal .sp-plan-modal-note { margin:0; font-size:11px; color:#6b6b6b; }
#sp-plan-modal .sp-plan-modal-fermer { position:absolute; top:10px; right:12px; background:none; border:0; font-size:26px; line-height:1; color:#6b6b6b; cursor:pointer; padding:4px 8px; }

@media (max-width:640px) {
    #sp-planning-wrapper .sp-planning-title { font-size:17px; letter-spacing:.1em; }
    #sp-planning-wrapper .sp-week-header { padding:10px 14px; }
}
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

// Fenêtre « prochaines dates » d'un cours non hebdomadaire (pastille ou bouton du bloc).
(function(){
    var source = document.getElementById('sp-plan-seances'), modal = document.getElementById('sp-plan-modal');
    if (!source || !modal) return;
    var cours = {};
    try { cours = JSON.parse(source.textContent || '{}'); } catch (e) { return; }
    document.body.appendChild(modal); // hors des blocs du thème, pour que la fenêtre couvre bien tout l'écran
    var dernier = null;
    function ouvrir(id, origine) {
        var c = cours[id];
        if (!c) return;
        dernier = origine || null;
        modal.querySelector('.sp-plan-modal-boite').style.setProperty('--c', c.couleur || '#D4000F');
        modal.querySelector('#sp-plan-modal-titre').textContent = c.titre;
        modal.querySelector('.sp-plan-modal-info').textContent = [c.frequence, c.horaire, c.public ? 'Pour : ' + c.public : ''].filter(Boolean).join(' · ');
        var ul = modal.querySelector('.sp-plan-modal-dates');
        ul.textContent = '';
        (c.seances || []).forEach(function(s){
            var li = document.createElement('li'), sp = document.createElement('span');
            sp.textContent = s.libelle || s.date;
            li.appendChild(sp);
            if (s.annule) { li.className = 'sp-annulee'; li.appendChild(document.createTextNode(' — annulée')); }
            ul.appendChild(li);
        });
        modal.hidden = false;
        modal.querySelector('.sp-plan-modal-fermer').focus();
    }
    function fermer() { modal.hidden = true; if (dernier && dernier.focus) dernier.focus(); }
    document.addEventListener('click', function(e){
        if (!e.target.closest) return;
        if (e.target.closest('.sp-plan-doc-link')) return; // lien du document : comportement normal
        var el = e.target.closest('[data-sp-seances]');
        if (el) { ouvrir(el.getAttribute('data-sp-seances'), el); return; }
        if (e.target === modal || e.target.closest('.sp-plan-modal-fermer')) fermer();
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && !modal.hidden) { fermer(); return; }
        var el = e.target.closest ? e.target.closest('.sp-plan-cliquable') : null;
        if (el && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); ouvrir(el.getAttribute('data-sp-seances'), el); }
    });
})();
</script>

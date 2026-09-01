<?php
/**
 * Template public : Palmarès — shortcode [sp_cal_palmares]
 * Variables : $competitions, $annees, $palmares_idx, $all_epreuves
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Helper médaille
if ( ! function_exists('sp_palm_front_medal') ) :
function sp_palm_front_medal( $med ) {
    switch ( $med ) {
        case 'or':     return array( 'emoji' => '🥇', 'label' => 'Or',     'bg' => '#fef9c3', 'color' => '#854d0e' );
        case 'argent': return array( 'emoji' => '🥈', 'label' => 'Argent', 'bg' => '#f1f5f9', 'color' => '#334155' );
        case 'bronze': return array( 'emoji' => '🥉', 'label' => 'Bronze', 'bg' => '#fef3c7', 'color' => '#92400e' );
        default:       return array( 'emoji' => '',   'label' => '',       'bg' => 'transparent', 'color' => '#9ca3af' );
    }
}
endif;

// Table de correspondance pseudo → identité (pour remplacer les noms sans droit image)
$sp_pseudo_map   = array(); // eleve_id → 'Élève X'
$sp_pseudo_count = 0;
$sp_alphabet     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
foreach ( $palmares_idx as $epid_arr ) {
    foreach ( $epid_arr as $lid_arr ) {
        foreach ( $lid_arr as $lid => $r ) {
            if ( empty($r['droit_image']) && ! isset($sp_pseudo_map[$lid]) ) {
                $sp_pseudo_map[$lid] = 'Élève ' . $sp_alphabet[ $sp_pseudo_count % 26 ];
                $sp_pseudo_count++;
            }
        }
    }
}

// Filtrer : garder uniquement les compétitions avec au moins 1 médaille
$comps_avec_resultats = array();
foreach ( $competitions as $comp ) {
    if ( ! empty( $palmares_idx[ intval($comp->id) ] ) ) {
        $comps_avec_resultats[] = $comp;
    }
}
?>
<div class="sp-palm-front-wrap">

    <!-- ── En-tête ── -->
    <div class="sp-cal-toolbar" style="margin-bottom:24px;">
        <span style="font-size:17px;font-weight:700;">🏆 Palmarès du club</span>
        <span style="font-size:13px;opacity:.8;"><?php echo count($comps_avec_resultats); ?> compétition<?php echo count($comps_avec_resultats) !== 1 ? 's' : ''; ?></span>
    </div>

    <?php if ( empty($comps_avec_resultats) ) : ?>
        <p style="color:#6b7280;font-style:italic;padding:16px;">Aucun résultat de compétition disponible pour le moment.</p>
    <?php else : ?>

    <!-- ── Filtre années ── -->
    <?php if ( count($annees) > 1 ) : ?>
    <div class="sp-palm-front-years">
        <button class="sp-palm-year-btn active" data-year="" onclick="spPalmSetYear(this,'')">Toutes</button>
        <?php foreach ( $annees as $a ) : ?>
        <button class="sp-palm-year-btn" data-year="<?php echo esc_attr($a); ?>" onclick="spPalmSetYear(this,'<?php echo esc_js($a); ?>')"><?php echo esc_html($a); ?></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Compétitions ── -->
    <?php foreach ( $comps_avec_resultats as $comp ) :
        $cid      = intval( $comp->id );
        $date_f   = date_i18n( 'l j F Y', strtotime($comp->date) );
        $annee_c  = date( 'Y', strtotime($comp->date) );
        $epreuves = $all_epreuves[$cid] ?? array(); // epid => nom
        $palm     = $palmares_idx[$cid] ?? array();  // epid => lid => data

        // Compter médailles globales pour cet event
        $nb_or = $nb_arg = $nb_bro = 0;
        foreach ( $palm as $epid => $eleves_ep ) {
            foreach ( $eleves_ep as $lid => $r ) {
                if ( $r['medaille'] === 'or' )     $nb_or++;
                if ( $r['medaille'] === 'argent' ) $nb_arg++;
                if ( $r['medaille'] === 'bronze' ) $nb_bro++;
            }
        }

        // Construire liste des médaillés groupés par catégorie
        // Structure : cat => eleve_key => { nom, prenom, medailles par epreuve }
        $by_cat = array();
        foreach ( $palm as $epid => $eleves_ep ) {
            $ep_nom = $epreuves[$epid] ?? '';
            foreach ( $eleves_ep as $lid => $r ) {
                if ( ! $r['medaille'] ) continue; // public = médaillés seulement
                $cat = $r['cat'] ?: 'Général';
                $key = $lid;
                if ( ! isset($by_cat[$cat][$key]) ) {
                    $by_cat[$cat][$key] = array(
                        'eleve_id'    => $lid,
                        'nom'         => $r['nom'],
                        'prenom'      => $r['prenom'],
                        'droit_image' => $r['droit_image'] ?? 1,
                        'ep'          => array(),
                    );
                }
                $by_cat[$cat][$key]['ep'][$ep_nom] = $r['medaille'];
            }
        }
        ksort($by_cat);
    ?>
    <div class="sp-palm-front-block" id="competition-<?php echo $cid; ?>" data-year="<?php echo esc_attr($annee_c); ?>">

        <!-- En-tête compétition -->
        <div class="sp-palm-front-comp-header">
            <div class="sp-palm-front-comp-left">
                <h2 class="sp-palm-front-comp-titre">
					<?php echo esc_html($comp->titre); ?>
					<?php
					$niv_labels = array(
						'international' => '🌐 International',
						'national'      => '🇫🇷 National',
						'regional'      => '🌍 Régional',
						'departemental' => '🏘️ Départemental',
					);
					$niv = $niv_labels[$comp->niveau ?? 'departemental'] ?? '🏘️ Départemental';
					echo '<span style="font-size:11px;font-weight:600;opacity:.75;margin-left:8px;vertical-align:middle;">(' . esc_html($niv) . ')</span>';
					?>
				</h2>
                <div class="sp-palm-front-comp-meta">
                    📅 <?php echo esc_html($date_f); ?>
                    <?php if ( $comp->lieu ?? '' ) : ?>
                        &nbsp;·&nbsp; 📍 <?php echo esc_html($comp->lieu); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sp-palm-front-medals-summary">
                <?php if ($nb_or)  : ?><span class="sp-palm-front-badge sp-palm-badge-or">🥇 <?php echo $nb_or; ?></span><?php endif; ?>
                <?php if ($nb_arg) : ?><span class="sp-palm-front-badge sp-palm-badge-argent">🥈 <?php echo $nb_arg; ?></span><?php endif; ?>
                <?php if ($nb_bro) : ?><span class="sp-palm-front-badge sp-palm-badge-bronze">🥉 <?php echo $nb_bro; ?></span><?php endif; ?>
            </div>
        </div>

        <!-- Résultats par catégorie -->
        <?php foreach ( $by_cat as $cat_label => $medailles_cat ) :
            // Trier : or en premier, puis argent, puis bronze
            uasort( $medailles_cat, function($a, $b) {
                $order = array('or'=>0,'argent'=>1,'bronze'=>2,''=>3);
                $ma = min(array_map(fn($m) => $order[$m] ?? 3, $a['ep']));
                $mb = min(array_map(fn($m) => $order[$m] ?? 3, $b['ep']));
                return $ma !== $mb ? $ma - $mb : strcmp($a['nom'], $b['nom']);
            });
        ?>
        <div class="sp-palm-front-cat-block">
            <h3 class="sp-palm-front-cat-title">👥 <?php echo esc_html($cat_label); ?></h3>
            <table class="sp-palm-front-table">
                <thead>
                    <tr>
                        <th class="sp-palm-th-nom">Élève</th>
                        <?php
                        // Colonnes épreuves présentes pour cette catégorie
                        $ep_noms_cat = array();
                        foreach ( $medailles_cat as $row ) {
                            foreach ( array_keys($row['ep']) as $epn ) {
                                if ( ! in_array($epn, $ep_noms_cat) ) $ep_noms_cat[] = $epn;
                            }
                        }
                        foreach ( $ep_noms_cat as $epn ) : ?>
                        <th class="sp-palm-th-ep"><?php echo esc_html($epn); ?></th>
                        <?php endforeach; ?>
                        <th class="sp-palm-th-total">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $medailles_cat as $row ) :
                    $nb_med = count(array_filter($row['ep']));
                ?>
                    <tr class="sp-palm-front-row<?php echo $nb_med ? ' sp-palm-row-medal' : ''; ?>">
                        <td class="sp-palm-td-nom">
                            <?php
                            $lid_row = $row['eleve_id'] ?? 0;
                            if ( empty($row['droit_image']) && isset($sp_pseudo_map[$lid_row]) ) {
                                echo esc_html( $sp_pseudo_map[$lid_row] );
                            } else {
                                echo esc_html( $row['prenom'] . ' ' . mb_strtoupper($row['nom']) );
                            }
                            ?>
                        </td>
                        <?php foreach ( $ep_noms_cat as $epn ) :
                            $med = $row['ep'][$epn] ?? '';
                            $mi  = sp_palm_front_medal($med);
                        ?>
                        <td class="sp-palm-td-ep" style="background:<?php echo $mi['bg']; ?>;">
                            <?php echo $mi['emoji'] ?: '<span style="color:#d1d5db;">—</span>'; ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="sp-palm-td-total">
                            <?php if ($nb_med) : ?>
                                <strong style="color:#b45309;"><?php echo $nb_med; ?> 🏅</strong>
                            <?php else : ?>
                                <span style="color:#d1d5db;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

    </div><!-- .sp-palm-front-block -->
    <?php endforeach; ?>

    <?php endif; ?>

</div><!-- .sp-palm-front-wrap -->

<style>
.sp-palm-front-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:960px;color:#1f2937}
/* Filtre années */
.sp-palm-front-years{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px}
.sp-palm-year-btn{padding:5px 14px;border:2px solid #d1d5db;border-radius:20px;background:#fff;color:#374151;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s}
.sp-palm-year-btn:hover{border-color:#1e3a5f;color:#1e3a5f}
.sp-palm-year-btn.active{background:#1e3a5f!important;border-color:#1e3a5f!important;color:#fff!important}
/* Bloc compétition */
.sp-palm-front-block{margin-bottom:32px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
.sp-palm-front-block[data-hidden="1"]{display:none}
/* En-tête compétition */
.sp-palm-front-comp-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 20px;background:linear-gradient(135deg,#1e3a5f 0%,#2d5a9e 100%);flex-wrap:wrap}
.sp-palm-front-comp-titre{margin:0 0 4px;font-size:17px;font-weight:700;color:#fff}
.sp-palm-front-comp-meta{font-size:13px;color:rgba(255,255,255,.8)}
.sp-palm-front-medals-summary{display:flex;gap:8px;flex-shrink:0}
.sp-palm-front-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:20px;font-size:14px;font-weight:700}
.sp-palm-badge-or{background:#fef9c3;color:#854d0e}
.sp-palm-badge-argent{background:#f1f5f9;color:#334155}
.sp-palm-badge-bronze{background:#fef3c7;color:#92400e}
/* Catégorie */
.sp-palm-front-cat-block{padding:0 20px 16px}
.sp-palm-front-cat-title{font-size:14px;font-weight:700;color:#1e3a5f;margin:16px 0 10px;padding-bottom:6px;border-bottom:2px solid #e2e8f0}
/* Tableau */
.sp-palm-front-table{width:100%;border-collapse:collapse;font-size:14px;background:#fff}
.sp-palm-front-table thead tr{background:#f8fafc}
.sp-palm-front-table thead th{padding:8px 12px;text-align:left;font-size:12px;font-weight:700;color:#6b7280!important;background:#f8fafc!important;border-bottom:2px solid #e2e8f0!important;border-top:none!important;border-left:none!important;border-right:none!important;white-space:nowrap}
.sp-palm-th-nom{min-width:160px}
.sp-palm-th-ep{text-align:center!important;min-width:80px}
.sp-palm-th-total{text-align:center!important;min-width:60px}
.sp-palm-front-table tbody tr{border-bottom:1px solid #f1f5f9!important;background:#fff!important;transition:background .1s}
.sp-palm-front-table tbody tr:last-child{border-bottom:none!important}
.sp-palm-front-table tbody tr:hover{background:#f8fafc!important}
.sp-palm-row-medal{background:#fffbeb!important}
.sp-palm-row-medal:hover{background:#fef9c3!important}
.sp-palm-front-table td{padding:9px 12px;vertical-align:middle;border:none!important;color:#1f2937!important;background:transparent!important}
.sp-palm-td-nom{font-weight:700;color:#1e3a5f!important}
.sp-palm-td-ep{text-align:center;font-size:18px}
.sp-palm-td-total{text-align:center}
@media(max-width:600px){
    .sp-palm-front-comp-header{padding:12px 14px}
    .sp-palm-front-cat-block{padding:0 12px 12px}
    .sp-palm-th-ep,.sp-palm-td-ep{min-width:50px}
}
</style>

<script>
(function(){
    window.spPalmSetYear = function(btn, year) {
        document.querySelectorAll('.sp-palm-year-btn').forEach(function(b){
            b.classList.toggle('active', b === btn);
        });
        document.querySelectorAll('.sp-palm-front-block').forEach(function(block){
            var show = !year || block.getAttribute('data-year') === year;
            block.setAttribute('data-hidden', show ? '0' : '1');
        });
    };
})();
</script>

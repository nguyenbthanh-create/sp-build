<?php
/**
 * Template public : Palmarès — shortcode [sp_cal_palmares]
 * Variables : $competitions, $annees, $palmares_idx, $all_epreuves
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Helper médaille : pastille colorée (styles dans assets/css/calendar.css, section « Palmarès »)
if ( ! function_exists('sp_palm_front_medal') ) :
function sp_palm_front_medal( $med ) {
    $labels = array( 'or' => 'Or', 'argent' => 'Argent', 'bronze' => 'Bronze' );
    if ( ! isset( $labels[ $med ] ) ) return '';
    return '<span class="sp-palm-med sp-palm-med-' . $med . '" title="' . $labels[ $med ] . '" aria-label="' . $labels[ $med ] . '"></span>';
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
    <div class="sp-palm-front-head">
        <h2 class="sp-palm-front-titre">Palmarès du club</h2>
        <div class="sp-palm-front-count"><?php echo count($comps_avec_resultats); ?> compétition<?php echo count($comps_avec_resultats) !== 1 ? 's' : ''; ?></div>
    </div>

    <?php if ( empty($comps_avec_resultats) ) : ?>
        <p class="sp-palm-front-empty">Aucun résultat de compétition disponible pour le moment.</p>
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
						'international' => 'International',
						'national'      => 'National',
						'regional'      => 'Régional',
						'departemental' => 'Départemental',
					);
					$niv = $niv_labels[$comp->niveau ?? 'departemental'] ?? 'Départemental';
					echo '<span class="sp-palm-niveau">' . esc_html($niv) . '</span>';
					?>
				</h2>
                <div class="sp-palm-front-comp-meta">
                    <?php echo esc_html($date_f); ?>
                    <?php if ( $comp->lieu ?? '' ) : ?>
                        &nbsp;·&nbsp; <?php echo esc_html($comp->lieu); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sp-palm-front-medals-summary">
                <?php if ($nb_or)  : ?><span class="sp-palm-front-badge"><?php echo sp_palm_front_medal('or');     ?> <?php echo $nb_or; ?></span><?php endif; ?>
                <?php if ($nb_arg) : ?><span class="sp-palm-front-badge"><?php echo sp_palm_front_medal('argent'); ?> <?php echo $nb_arg; ?></span><?php endif; ?>
                <?php if ($nb_bro) : ?><span class="sp-palm-front-badge"><?php echo sp_palm_front_medal('bronze'); ?> <?php echo $nb_bro; ?></span><?php endif; ?>
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
            <h3 class="sp-palm-front-cat-title"><?php echo esc_html($cat_label); ?></h3>
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
                            $pastille = sp_palm_front_medal( $row['ep'][$epn] ?? '' );
                        ?>
                        <td class="sp-palm-td-ep">
                            <?php echo $pastille ?: '<span class="sp-palm-vide">—</span>'; ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="sp-palm-td-total">
                            <?php if ($nb_med) : ?>
                                <strong><?php echo $nb_med; ?></strong>
                            <?php else : ?>
                                <span class="sp-palm-vide">—</span>
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

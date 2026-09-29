<?php
/**
 * SP_Cal — Top 5 élèves de la saison
 * Fichier : class-top5-front.php
 *
 * Shortcode : [sp_top5_saison]
 * Attributs optionnels :
 *   - nb            : nombre d'élèves affichés (défaut 5)
 *   - saison        : ex "2025-2026" (défaut : saison en cours)
 *   - titre         : titre affiché (défaut : "Top 5 de la saison" ; émoji de tête retiré)
 *   - categorie_age : filtrer par tranche d'âge
 *   - discipline    : filtrer par nom d'épreuve (ex "Renfo")
 *
 * RÈGLES :
 *   - Sans discipline → exclut automatiquement les épreuves RENFO (classement TKD pur)
 *   - discipline="Renfo" → inclut uniquement les épreuves RENFO
 *   - droit_image=0 → affiche "Élève A", "Élève B" etc. à la place du nom réel
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_Top5Front' ) ) :

class SpCalPro_Top5Front {

    private $db;
    const RENFO_KEYWORD = 'Renfo';

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;
        add_shortcode( 'sp_top5_saison', array( $this, 'render_shortcode' ) );
    }

    private function get_saison_courante() {
        $fin = SpCalPro_DB::fin_saison_prochaine();   // jour/mois reconduits chaque année
        if ( $fin ) {
            $annee_fin = intval( substr( $fin, 0, 4 ) );
            return ( $annee_fin - 1 ) . '-' . $annee_fin;
        }
        $mois  = intval( date( 'n' ) );
        $annee = intval( date( 'Y' ) );
        return $mois >= 9 ? $annee . '-' . ( $annee + 1 ) : ( $annee - 1 ) . '-' . $annee;
    }

    private function saison_to_dates( $saison ) {
        $parts = explode( '-', $saison );
        if ( count( $parts ) !== 2 ) return null;
        return array(
            'debut' => $parts[0] . '-09-01',
            'fin'   => $parts[1] . '-08-31',
        );
    }

    private function normalise_categorie( $cat ) {
        switch ( strtolower( trim( $cat ) ) ) {
            case 'renfo': case 'renforcé': case 'renforce':
                return array( 'RENFO' );
            case 'enfant': case 'enfants':
                return array( 'Enfant', 'Enfants' );
            case 'baby':
                return array( 'Baby' );
            case 'ado/adulte': case 'ado': case 'adulte': case 'ado adulte':
                return array( 'Ado/adulte' );
            default:
                return array( $cat );
        }
    }

    private function get_top( $saison, $nb, $categorie_age = '', $discipline = '' ) {
        global $wpdb;

        $dates = $this->saison_to_dates( $saison );
        if ( ! $dates ) return array();

        $pts_or     = intval( get_option( 'sp_cal_pts_or',     3 ) );
        $pts_argent = intval( get_option( 'sp_cal_pts_argent', 2 ) );
        $pts_bronze = intval( get_option( 'sp_cal_pts_bronze', 1 ) );
        $coef_dep   = floatval( get_option( 'sp_cal_coef_departemental', 1.0 ) );
        $coef_reg   = floatval( get_option( 'sp_cal_coef_regional',      1.5 ) );
        $coef_nat   = floatval( get_option( 'sp_cal_coef_national',      2.0 ) );
        $coef_int   = floatval( get_option( 'sp_cal_coef_international', 3.0 ) );

        $tcr = $wpdb->prefix . 'sp_cal_comp_resultats';
        $tce = $wpdb->prefix . 'sp_cal_comp_epreuves';
        $te  = $wpdb->prefix . 'sp_cal_events';
        $tel = $wpdb->prefix . 'sp_cal_eleves';

        $extra_filters = '';
        $extra_params  = array();

        // Filtre catégorie d'âge
        if ( $categorie_age !== '' ) {
            $cats = $this->normalise_categorie( $categorie_age );
            if ( count( $cats ) === 1 ) {
                $extra_filters  .= ' AND el.categorie_age = %s';
                $extra_params[]  = $cats[0];
            } else {
                $placeholders    = implode( ',', array_fill( 0, count($cats), '%s' ) );
                $extra_filters  .= ' AND el.categorie_age IN (' . $placeholders . ')';
                $extra_params    = array_merge( $extra_params, $cats );
            }
        }

        // Filtre discipline
        if ( $discipline !== '' ) {
            $extra_filters  .= ' AND ce.nom LIKE %s';
            $extra_params[]  = '%' . $wpdb->esc_like( $discipline ) . '%';
        } else {
            // Mode TKD : exclure les épreuves RENFO
            $extra_filters  .= ' AND ce.nom NOT LIKE %s';
            $extra_params[]  = '%' . $wpdb->esc_like( self::RENFO_KEYWORD ) . '%';
        }

        $sql = "SELECT
                el.id,
                el.prenom,
                el.nom,
                el.categorie_age,
                COALESCE(el.droit_image, 1) AS droit_image,
                SUM(
                    (
                        CASE cr.medaille
                            WHEN 'or'     THEN %f
                            WHEN 'argent' THEN %f
                            WHEN 'bronze' THEN %f
                            ELSE 0
                        END
                    ) * (
                        CASE ev.niveau
                            WHEN 'regional'      THEN %f
                            WHEN 'national'      THEN %f
                            WHEN 'international' THEN %f
                            ELSE %f
                        END
                    )
                ) AS total_points,
                SUM( cr.medaille = 'or' )     AS nb_or,
                SUM( cr.medaille = 'argent' ) AS nb_argent,
                SUM( cr.medaille = 'bronze' ) AS nb_bronze
            FROM $tcr cr
            INNER JOIN $tce ce ON ce.id = cr.epreuve_id
            INNER JOIN $te  ev ON ev.id = ce.event_id
            INNER JOIN $tel el ON el.id = cr.eleve_id
            WHERE ev.date BETWEEN %s AND %s
              AND cr.medaille IN ('or','argent','bronze')
              AND el.actif = 1
              $extra_filters
            GROUP BY cr.eleve_id
            ORDER BY total_points DESC, nb_or DESC, nb_argent DESC
            LIMIT %d";

        $params = array_merge(
            array(
                $pts_or, $pts_argent, $pts_bronze,
                $coef_reg, $coef_nat, $coef_int, $coef_dep,
                $dates['debut'], $dates['fin'],
            ),
            $extra_params,
            array( $nb )
        );

        return $wpdb->get_results(
            call_user_func_array(
                array( $wpdb, 'prepare' ),
                array_merge( array( $sql ), $params )
            )
        );
    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'nb'            => 5,
            'saison'        => '',
            'titre'         => 'Top 5 de la saison',
            'categorie_age' => '',
            'discipline'    => '',
        ), $atts, 'sp_top5_saison' );

        $nb            = max( 1, intval( $atts['nb'] ) );
        $saison        = $atts['saison'] ?: $this->get_saison_courante();
        // Émoji éventuel en tête du titre (ex. « 🏆 Top 5 Baby ») retiré : charte sobre du site
        $titre         = esc_html( preg_replace( '/^[^\p{L}\p{N}]+/u', '', $atts['titre'] ) );
        $categorie_age = sanitize_text_field( $atts['categorie_age'] );
        $discipline    = sanitize_text_field( $atts['discipline'] );

        $pts_or     = intval( get_option( 'sp_cal_pts_or',     3 ) );
        $pts_argent = intval( get_option( 'sp_cal_pts_argent', 2 ) );
        $pts_bronze = intval( get_option( 'sp_cal_pts_bronze', 1 ) );
        $coef_dep   = floatval( get_option( 'sp_cal_coef_departemental', 1.0 ) );
        $coef_reg   = floatval( get_option( 'sp_cal_coef_regional',      1.5 ) );
        $coef_nat   = floatval( get_option( 'sp_cal_coef_national',      2.0 ) );
        $coef_int   = floatval( get_option( 'sp_cal_coef_international', 3.0 ) );

        $top = $this->get_top( $saison, $nb, $categorie_age, $discipline );

        $sous_titre_parts = array();
        if ( $categorie_age ) $sous_titre_parts[] = esc_html( $categorie_age );
        if ( $discipline )    $sous_titre_parts[] = esc_html( $discipline );
        $sous_titre = implode( ' · ', $sous_titre_parts );

        // Alphabet pour les pseudos droit_image=0
        $alphabet    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $pseudo_idx  = 0;
        // Pré-calculer les pseudos pour garder la cohérence dans la page
        $pseudos = array();
        foreach ( $top as $el ) {
            if ( intval( $el->droit_image ) === 0 ) {
                $pseudos[ $el->id ] = 'Élève ' . $alphabet[ $pseudo_idx % 26 ];
                $pseudo_idx++;
            }
        }

        // Styles : section « Top 5 » de assets/css/calendar.css (charte du site, comme le Palmarès)
        if ( ! wp_style_is( 'sp-cal-front', 'enqueued' ) ) {
            wp_enqueue_style( 'sp-cal-front', SP_CAL_PRO_URL . 'assets/css/calendar.css', array(), sp_cal_asset_ver( 'assets/css/calendar.css' ) );
        }
        // Pastilles médailles, mêmes classes que le Palmarès
        $med_or     = '<span class="sp-palm-med sp-palm-med-or" title="Or"></span>';
        $med_argent = '<span class="sp-palm-med sp-palm-med-argent" title="Argent"></span>';
        $med_bronze = '<span class="sp-palm-med sp-palm-med-bronze" title="Bronze"></span>';

        ob_start();
        ?>
        <div class="sp-top5-wrap">

            <div class="sp-top5-head">
                <h3 class="sp-top5-titre"><?php echo $titre; ?></h3>
                <div class="sp-top5-saison">
                    Saison <?php echo esc_html( $saison ); ?>
                    <?php echo $sous_titre ? ' · ' . $sous_titre : ''; ?>
                </div>
            </div>

            <!-- Légende coefficients -->
            <div class="sp-top5-legende">
                <div class="sp-top5-legende-title">Coefficients de niveau appliqués</div>
                <?php
                $niveaux = array(
                    'Départemental' => $coef_dep,
                    'Régional'      => $coef_reg,
                    'National'      => $coef_nat,
                    'International' => $coef_int,
                );
                foreach ( $niveaux as $niv_label => $coef ) : ?>
                <span class="sp-top5-legende-item">
                    <?php echo esc_html( $niv_label ); ?>
                    <span class="sp-top5-legende-pts">×<?php echo number_format( $coef, 1, ',', '' ); ?>
                        (<?php echo $med_or; ?> <?php echo number_format( $pts_or * $coef, 1, ',', '' ); ?>
                        · <?php echo $med_argent; ?> <?php echo number_format( $pts_argent * $coef, 1, ',', '' ); ?>
                        · <?php echo $med_bronze; ?> <?php echo number_format( $pts_bronze * $coef, 1, ',', '' ); ?> pts)</span>
                </span>
                <?php endforeach; ?>
            </div>

            <?php if ( empty( $top ) ) : ?>
            <div class="sp-top5-vide">
                Aucune médaille enregistrée<?php
                $ctx = array_filter( array( $categorie_age, $discipline ) );
                echo $ctx ? ' pour ' . esc_html( implode( ' / ', $ctx ) ) : '';
                ?> pour la saison <?php echo esc_html( $saison ); ?>.
            </div>
            <?php else : ?>
            <div class="sp-top5-liste">
            <?php foreach ( $top as $i => $el ) :
                $rang        = $i + 1;
                $rank_class  = $rang <= 3 ? ' rank-' . $rang : '';
                $pts_fmt     = number_format( floatval( $el->total_points ), 1, ',', '' );
                $has_droit   = intval( $el->droit_image ) === 1;
                $nom_affiche = $has_droit
                    ? esc_html( $el->prenom . ' ' . mb_strtoupper( $el->nom ) )
                    : esc_html( $pseudos[ $el->id ] ?? 'Élève ?' );
                $nom_class   = $has_droit ? '' : ' anonymous';
            ?>
                <div class="sp-top5-row<?php echo $rank_class; ?>">
                    <div class="sp-top5-rang<?php echo $rank_class; ?>"><?php echo $rang; ?></div>
                    <div class="sp-top5-info">
                        <div class="sp-top5-nom<?php echo $nom_class; ?>"><?php echo $nom_affiche; ?></div>
                        <?php if ( $el->categorie_age ) : ?>
                        <div class="sp-top5-cat"><?php echo esc_html( $el->categorie_age ); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="sp-top5-medailles">
                        <?php if ( $el->nb_or > 0 ) : ?>
                        <span class="sp-top5-badge"><?php echo $med_or; ?> <?php echo intval( $el->nb_or ); ?></span>
                        <?php endif; ?>
                        <?php if ( $el->nb_argent > 0 ) : ?>
                        <span class="sp-top5-badge"><?php echo $med_argent; ?> <?php echo intval( $el->nb_argent ); ?></span>
                        <?php endif; ?>
                        <?php if ( $el->nb_bronze > 0 ) : ?>
                        <span class="sp-top5-badge"><?php echo $med_bronze; ?> <?php echo intval( $el->nb_bronze ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="sp-top5-points">
                        <?php echo $pts_fmt; ?>
                        <span>pts</span>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

endif;
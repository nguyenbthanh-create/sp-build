<?php
/**
 * SP_Cal — Top 5 élèves de la saison
 * Fichier : class-top5-front.php
 *
 * Shortcode : [sp_top5_saison]
 * Attributs optionnels :
 *   - nb            : nombre d'élèves affichés (défaut 5)
 *   - saison        : ex "2025-2026" (défaut : saison en cours)
 *   - titre         : titre affiché (défaut : "🏆 Top 5 de la saison")
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
        $fin = get_option( 'sp_cal_fin_saison', '' );
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
            'titre'         => '🏆 Top 5 de la saison',
            'categorie_age' => '',
            'discipline'    => '',
        ), $atts, 'sp_top5_saison' );

        $nb            = max( 1, intval( $atts['nb'] ) );
        $saison        = $atts['saison'] ?: $this->get_saison_courante();
        $titre         = esc_html( $atts['titre'] );
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

        ob_start();
        ?>
        <div class="sp-top5-wrap">
            <style>
            .sp-top5-wrap {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                max-width: 100%;
                width: 100%;
                margin-bottom: 32px;
            }
            .sp-top5-titre { font-size: 18px; font-weight: 800; color: #1e3a5f; margin-bottom: 4px; }
            .sp-top5-saison { font-size: 12px; color: #94a3b8; margin-bottom: 12px; text-transform: uppercase; letter-spacing: .05em; }
            .sp-top5-legende {
                display: flex; flex-wrap: wrap; gap: 8px;
                margin-bottom: 18px; padding: 12px 16px;
                background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
            }
            .sp-top5-legende-title {
                width: 100%; font-size: 11px; font-weight: 700; color: #94a3b8;
                text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px;
            }
            .sp-top5-legende-item {
                display: inline-flex; align-items: center; gap: 5px;
                background: #fff; border: 1px solid #e2e8f0; border-radius: 20px;
                padding: 3px 10px; font-size: 12px; font-weight: 600; color: #1e3a5f;
            }
            .sp-top5-legende-pts { font-size: 11px; color: #94a3b8; font-weight: 400; }
            .sp-top5-liste { display: flex; flex-direction: column; gap: 8px; }
            .sp-top5-row {
                display: flex; align-items: center; gap: 12px;
                background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
                padding: 12px 16px; box-shadow: 0 1px 3px rgba(0,0,0,.04);
                width: 100%; box-sizing: border-box;
            }
            .sp-top5-row.rank-1 { border-left: 4px solid #f59e0b; background: #fffbeb; }
            .sp-top5-row.rank-2 { border-left: 4px solid #94a3b8; background: #f8fafc; }
            .sp-top5-row.rank-3 { border-left: 4px solid #b45309; background: #fdf8f0; }
            .sp-top5-rang { font-size: 18px; font-weight: 900; color: #64748b; min-width: 28px; text-align: center; flex-shrink: 0; }
            .sp-top5-rang.rank-1 { color: #f59e0b; }
            .sp-top5-rang.rank-2 { color: #94a3b8; }
            .sp-top5-rang.rank-3 { color: #b45309; }
            .sp-top5-info { flex: 1; min-width: 0; }
            .sp-top5-nom { font-size: 15px; font-weight: 700; color: #0f172a; }
            .sp-top5-nom.anonymous { color: #94a3b8; font-style: italic; }
            .sp-top5-cat { font-size: 11px; color: #94a3b8; margin-top: 1px; }
            .sp-top5-medailles { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
            .sp-top5-badge { display: flex; align-items: center; gap: 3px; padding: 3px 9px; border-radius: 20px; font-weight: 700; font-size: 12px; }
            .sp-top5-badge.or     { background: #fef3c7; color: #92400e; }
            .sp-top5-badge.argent { background: #f1f5f9; color: #475569; }
            .sp-top5-badge.bronze { background: #fdf4e7; color: #92400e; }
            .sp-top5-points { font-size: 18px; font-weight: 900; color: #1e3a5f; min-width: 56px; text-align: right; flex-shrink: 0; }
            .sp-top5-points span { font-size: 11px; font-weight: 400; color: #94a3b8; display: block; text-align: right; }
            .sp-top5-vide { color: #94a3b8; font-size: 13px; padding: 16px 0; }
            @media (max-width: 480px) {
                .sp-top5-nom { font-size: 13px; }
                .sp-top5-points { font-size: 15px; min-width: 44px; }
                .sp-top5-badge { font-size: 11px; padding: 2px 7px; }
            }
            </style>

            <div class="sp-top5-titre"><?php echo $titre; ?></div>
            <div class="sp-top5-saison">
                Saison <?php echo esc_html( $saison ); ?>
                <?php echo $sous_titre ? ' · ' . $sous_titre : ''; ?>
            </div>

            <!-- Légende coefficients -->
            <div class="sp-top5-legende">
                <div class="sp-top5-legende-title">Coefficients de niveau appliqués</div>
                <span class="sp-top5-legende-item">
                    🏘️ Départemental
                    <span class="sp-top5-legende-pts">×<?php echo number_format($coef_dep,1,',',''); ?> (🥇=<?php echo number_format($pts_or*$coef_dep,1,',',''); ?> · 🥈=<?php echo number_format($pts_argent*$coef_dep,1,',',''); ?> · 🥉=<?php echo number_format($pts_bronze*$coef_dep,1,',',''); ?> pts)</span>
                </span>
                <span class="sp-top5-legende-item">
                    🌍 Régional
                    <span class="sp-top5-legende-pts">×<?php echo number_format($coef_reg,1,',',''); ?> (🥇=<?php echo number_format($pts_or*$coef_reg,1,',',''); ?> · 🥈=<?php echo number_format($pts_argent*$coef_reg,1,',',''); ?> · 🥉=<?php echo number_format($pts_bronze*$coef_reg,1,',',''); ?> pts)</span>
                </span>
                <span class="sp-top5-legende-item">
                    🇫🇷 National
                    <span class="sp-top5-legende-pts">×<?php echo number_format($coef_nat,1,',',''); ?> (🥇=<?php echo number_format($pts_or*$coef_nat,1,',',''); ?> · 🥈=<?php echo number_format($pts_argent*$coef_nat,1,',',''); ?> · 🥉=<?php echo number_format($pts_bronze*$coef_nat,1,',',''); ?> pts)</span>
                </span>
                <span class="sp-top5-legende-item">
                    🌐 International
                    <span class="sp-top5-legende-pts">×<?php echo number_format($coef_int,1,',',''); ?> (🥇=<?php echo number_format($pts_or*$coef_int,1,',',''); ?> · 🥈=<?php echo number_format($pts_argent*$coef_int,1,',',''); ?> · 🥉=<?php echo number_format($pts_bronze*$coef_int,1,',',''); ?> pts)</span>
                </span>
            </div>

            <?php if ( empty( $top ) ) : ?>
            <div class="sp-top5-vide">
                Aucune médaille enregistrée<?php
                $ctx = array_filter( array( $categorie_age, $discipline ) );
                echo $ctx ? ' pour ' . esc_html( implode( ' / ', $ctx ) ) : '';
                ?>.
            </div>
            <?php else : ?>
            <div class="sp-top5-liste">
            <?php foreach ( $top as $i => $el ) :
                $rang        = $i + 1;
                $rank_class  = $rang <= 3 ? ' rank-' . $rang : '';
                $emoji       = array( 1 => '🥇', 2 => '🥈', 3 => '🥉' )[$rang] ?? $rang . '.';
                $pts_fmt     = number_format( floatval( $el->total_points ), 1, ',', '' );
                $has_droit   = intval( $el->droit_image ) === 1;
                $nom_affiche = $has_droit
                    ? esc_html( $el->prenom . ' ' . mb_strtoupper( $el->nom ) )
                    : esc_html( $pseudos[ $el->id ] ?? 'Élève ?' );
                $nom_class   = $has_droit ? '' : ' anonymous';
            ?>
                <div class="sp-top5-row<?php echo $rank_class; ?>">
                    <div class="sp-top5-rang<?php echo $rank_class; ?>"><?php echo $emoji; ?></div>
                    <div class="sp-top5-info">
                        <div class="sp-top5-nom<?php echo $nom_class; ?>"><?php echo $nom_affiche; ?></div>
                        <?php if ( $el->categorie_age ) : ?>
                        <div class="sp-top5-cat"><?php echo esc_html( $el->categorie_age ); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="sp-top5-medailles">
                        <?php if ( $el->nb_or > 0 ) : ?>
                        <span class="sp-top5-badge or">🥇 <?php echo intval( $el->nb_or ); ?></span>
                        <?php endif; ?>
                        <?php if ( $el->nb_argent > 0 ) : ?>
                        <span class="sp-top5-badge argent">🥈 <?php echo intval( $el->nb_argent ); ?></span>
                        <?php endif; ?>
                        <?php if ( $el->nb_bronze > 0 ) : ?>
                        <span class="sp-top5-badge bronze">🥉 <?php echo intval( $el->nb_bronze ); ?></span>
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
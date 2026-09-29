<?php
/**
 * Template public : Bureau & Entraîneurs — shortcode [sp_cal_bureau]
 *
 * Attributs shortcode :
 *   roles          = "bureau"
 *   titre          = ""
 *   colonnes       = "3"           2 ou 3
 *   taille_photo   = "130"         px
 *   couleur_nom, couleur_fn       ignorés depuis le 29/09/2026 : le style suit la charte du
 *                                  site (styles dans assets/css/calendar.css, section « Bureau »)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$cols = min( 4, max( 1, intval( $atts['colonnes'] ?? 3 ) ) );
$sz   = max( 70, intval( $atts['taille_photo'] ?? 130 ) );

$role_labels = array( 'bureau' => 'Bureau', 'entraineur' => 'Entraîneur' );
?>
<div class="sp-bureau-wrap" style="--spb-cols:<?php echo $cols; ?>;--spb-photo:<?php echo $sz; ?>px;">

    <?php if ( ! empty( $atts['titre'] ) ) : ?>
    <h2 class="sp-bureau-titre"><?php echo esc_html( $atts['titre'] ); ?></h2>
    <?php endif; ?>

    <?php if ( empty( $membres ) ) : ?>
    <p class="sp-bureau-empty">Aucun membre à afficher.</p>

    <?php else : ?>
    <div class="sp-bureau-grid">
    <?php foreach ( $membres as $m ) :

        // Fonctions : sportif et/ou bureau affichés séparément si renseignés
        $fonction_sport  = ! empty( $m->fonction )         ? $m->fonction         : '';
        $fonction_bureau = ! empty( $m->fonction_bureau )  ? $m->fonction_bureau  : '';

        // Fallback sur les rôles si aucune fonction renseignée
        if ( ! $fonction_sport && ! $fonction_bureau ) {
            $m_roles  = array_filter( array_map( 'trim', explode( ',', $m->roles ) ) );
            $fn_parts = array();
            foreach ( $m_roles as $rv ) {
                if ( isset( $role_labels[$rv] ) ) $fn_parts[] = $role_labels[$rv];
            }
            $fonction_sport = implode( ' / ', array_unique( $fn_parts ) );
        }

        // Initiales fallback
        $initiales = '';
        foreach ( explode( ' ', $m->nom ) as $p ) {
            if ( $p ) $initiales .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
            if ( mb_strlen( $initiales ) >= 2 ) break;
        }
    ?>
    <div class="sp-bureau-card">
        <div class="sp-bureau-avatar">
            <?php if ( ! empty( $m->photo_url ) ) : ?>
                <img src="<?php echo esc_url( $m->photo_url ); ?>"
                     alt="<?php echo esc_attr( $m->nom ); ?>"
                     class="sp-bureau-photo" loading="lazy">
            <?php else : ?>
                <div class="sp-bureau-initiales"><?php echo esc_html( $initiales ); ?></div>
            <?php endif; ?>
        </div>
        <div class="sp-bureau-nom"><?php echo esc_html( $m->nom ); ?></div>
        <?php if ( $fonction_bureau ) : ?>
        <div class="sp-bureau-fn"><?php echo esc_html( $fonction_bureau ); ?></div>
        <?php endif; ?>
        <?php if ( $fonction_sport ) : ?>
        <div class="sp-bureau-fn<?php echo $fonction_bureau ? ' sp-bureau-fn2' : ''; ?>"><?php echo esc_html( $fonction_sport ); ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

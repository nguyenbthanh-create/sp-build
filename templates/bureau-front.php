<?php
/**
 * Template public : Bureau & Entraîneurs — shortcode [sp_cal_bureau]
 *
 * Attributs shortcode :
 *   roles          = "bureau"
 *   titre          = ""
 *   colonnes       = "3"           2 ou 3
 *   taille_photo   = "110"         px
 *   couleur_nom    = "#1a1a1a"
 *   couleur_fn     = "#666666"
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$c_nom  = esc_attr( $atts['couleur_nom']  ?? '#1a1a1a' );
$c_fn   = esc_attr( $atts['couleur_fn']   ?? '#666666' );
$cols   = intval(   $atts['colonnes']     ?? 3 );
$sz     = max( 70, intval( $atts['taille_photo'] ?? 110 ) );
$uid    = 'spb-' . substr( md5( serialize($atts) ), 0, 6 );

$role_labels = array( 'bureau' => 'Bureau', 'entraineur' => 'Entraîneur' );
?>
<div class="sp-bureau-wrap <?php echo esc_attr($uid); ?>">

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
                     class="sp-bureau-photo">
            <?php else : ?>
                <div class="sp-bureau-initiales"><?php echo esc_html( $initiales ); ?></div>
            <?php endif; ?>
        </div>
        <div class="sp-bureau-nom"><?php echo esc_html( $m->nom ); ?></div>
        <?php if ( $fonction_bureau ) : ?>
        <div class="sp-bureau-fn"><?php echo esc_html( $fonction_bureau ); ?></div>
        <?php endif; ?>
        <?php if ( $fonction_sport ) : ?>
        <div class="sp-bureau-fn" style="font-size:12px;opacity:0.8;"><?php echo esc_html( $fonction_sport ); ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<style>
.<?php echo $uid; ?>.sp-bureau-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:900px;}
.<?php echo $uid; ?> .sp-bureau-titre{font-size:20px;font-weight:700;color:<?php echo $c_nom;?>;margin-bottom:24px;}
.<?php echo $uid; ?> .sp-bureau-grid{display:grid;grid-template-columns:repeat(<?php echo $cols;?>,1fr);gap:32px 24px;}
.<?php echo $uid; ?> .sp-bureau-card{display:flex;flex-direction:column;align-items:center;text-align:center;padding:24px 16px;border-radius:14px;transition:transform .18s,box-shadow .18s;cursor:default;}
.<?php echo $uid; ?> .sp-bureau-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.10);}
.<?php echo $uid; ?> .sp-bureau-avatar{width:<?php echo $sz;?>px;height:<?php echo $sz;?>px;border-radius:50%;overflow:hidden;margin-bottom:14px;flex-shrink:0;box-shadow:0 2px 10px rgba(0,0,0,.12);}
.<?php echo $uid; ?> .sp-bureau-photo{width:100%;height:100%;object-fit:cover;display:block;}
.<?php echo $uid; ?> .sp-bureau-initiales{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:<?php echo round($sz*.33);?>px;font-weight:700;color:#fff;background:linear-gradient(135deg,#1e3a5f 0%,#7c3aed 100%);}
.<?php echo $uid; ?> .sp-bureau-nom{font-size:16px;font-weight:600;color:<?php echo $c_nom;?>;margin-bottom:4px;line-height:1.3;}
.<?php echo $uid; ?> .sp-bureau-fn{font-size:13px;color:<?php echo $c_fn;?>;font-weight:400;}
.<?php echo $uid; ?> .sp-bureau-empty{color:#6b7280;font-style:italic;}
@media(max-width:600px){
    .<?php echo $uid; ?> .sp-bureau-grid{grid-template-columns:repeat(2,1fr);gap:20px 16px;}
}
@media(max-width:360px){
    .<?php echo $uid; ?> .sp-bureau-grid{grid-template-columns:1fr;}
}
</style>

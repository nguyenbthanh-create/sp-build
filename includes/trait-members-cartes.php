<?php
/**
 * Cartes de membre : page « Cartes membres » et impression (maybe_print_cartes).
 *
 * Méthodes de SP_Cal_Members déplacées telles quelles depuis class-admin-members.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SP_Cal_Members_Cartes; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SP_Cal_Members_Cartes {


    /* ══════════════════════════════════════════════════════════
       MAYBE PRINT CARTES
    ══════════════════════════════════════════════════════════ */

    public function maybe_print_cartes(): void {
        if (
            ! isset( $_GET['page'], $_GET['print'] )
            || $_GET['page'] !== 'sp-cal-print-cartes'
            || ! current_user_can( 'manage_options' )
        ) return;

        global $wpdb;
        $tel       = $this->db->table_eleves();
        $cat_sel   = sanitize_text_field( wp_unslash( $_GET['cat']    ?? '' ) );
        $saison    = sanitize_text_field( wp_unslash( $_GET['saison']  ?? '' ) );
        $pro       = isset( $_GET['pro'] ) && $_GET['pro'] === '1';

        $args = array( 'actif' => 1 );
        if ( $cat_sel ) $args['categorie_age'] = $cat_sel;
        if ( $saison )  $args['saison']        = $saison;
        $eleves = $this->db->get_eleves( $args );

        $club          = get_option( 'blogname', 'Club' );
        $club_num      = get_option( 'sp_cal_club_num', '' );
        $club_affil    = get_option( 'sp_cal_club_affiliation', '' );
        $club_ligue    = get_option( 'sp_cal_club_ligue', '' );
        $club_labelise = intval( get_option( 'sp_cal_club_labelise', 0 ) );
        $logo_url      = get_option( 'sp_cal_logo_url', '' );
        $meta_parts    = array_filter( [
            $club_affil ? 'FFTDA ' . $club_affil : '',
            $club_ligue,
            $club_num ? 'Club ' . $club_num : '',
        ] );
        $meta_str = implode( ' · ', $meta_parts );

        // Vider tout buffer existant et sortir une page HTML propre
        while ( ob_get_level() ) ob_end_clean();

        header( 'Content-Type: text/html; charset=UTF-8' );

        $nom_club = esc_html( strtoupper( $club ) );
        ?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Cartes membres — <?php echo esc_html( $cat_sel ?: 'Tous' ); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Song+Myung&display=swap" rel="stylesheet">
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#f3f4f6; padding:10mm; }

/* ── Même classes que la carte token ── */
.sp-carte-recto,
.sp-carte-verso {
    width:340px; height:215px; border-radius:12px;
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
    position:relative; overflow:hidden;
    -webkit-print-color-adjust:exact; print-color-adjust:exact;
}
.sp-carte-recto { background:#111; }
.sp-carte-band-blue   { position:absolute;right:0;top:0;width:88px;height:215px;background:#0f70b7; }
.sp-carte-band-yellow { position:absolute;right:84px;top:0;width:4px;height:215px;background:#ffdd0e; }
.sp-carte-band-red    { position:absolute;bottom:0;left:0;width:252px;height:5px;background:#e30613; }
.sp-carte-logo-wrap   { position:absolute;top:10px;left:12px;width:30px;height:30px;border-radius:4px;background:#fff;display:flex;align-items:center;justify-content:center; }
.sp-carte-logo        { width:28px;height:28px;object-fit:contain; }
.sp-carte-club-name   { position:absolute;top:12px;left:50px;color:#fff;font-size:10px;font-weight:600;letter-spacing:1.5px; }
.sp-carte-club-sub    { position:absolute;top:25px;left:50px;color:#ffdd0e;font-size:8px;letter-spacing:1px; }
.sp-carte-sep         { position:absolute;top:46px;left:12px;width:240px;height:0.5px;background:rgba(255,255,255,.12); }
.sp-carte-nom         { position:absolute;top:54px;left:12px;right:100px;color:#fff;font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.sp-carte-meta        { position:absolute;top:72px;left:12px;right:100px;color:rgba(255,255,255,.4);font-size:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.sp-carte-infos       { position:absolute;top:88px;left:12px;right:100px;display:flex;flex-direction:column;gap:4px; }
.sp-carte-info-row    { display:flex;gap:6px;align-items:center; }
.sp-carte-info-k      { color:rgba(255,255,255,.38);font-size:8px;width:50px;text-transform:uppercase;letter-spacing:.4px;flex-shrink:0; }
.sp-carte-info-v      { color:#fff;font-size:9px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.sp-carte-qr-zone     { position:absolute;top:8px;right:2px;width:86px;display:flex;flex-direction:column;align-items:center;gap:2px; }
.sp-carte-qr-box      { width:72px;height:72px;background:#fff;border-radius:6px;overflow:hidden;display:flex;align-items:center;justify-content:center; }
.sp-carte-qr-box img  { max-width:72px;max-height:72px; }
.sp-carte-stars       { display:flex;gap:1px;margin-top:3px; }
.sp-carte-labelise-txt{ color:rgba(255,255,255,.35);font-size:7px;letter-spacing:.3px; }
.sp-carte-qr-scan     { color:rgba(255,255,255,.35);font-size:7px;text-align:center; }
.sp-carte-url         { position:absolute;bottom:8px;right:6px;color:rgba(255,255,255,.25);font-size:7px; }
.sp-carte-photo-wrap  { position:absolute;bottom:12px;left:12px;width:46px;height:46px;border-radius:50%;overflow:hidden;border:2px solid rgba(255,255,255,.18);background:#222; }
.sp-carte-photo       { width:100%;height:100%;object-fit:cover;display:block; }
/* Verso */
.sp-carte-verso        { background:#111;display:flex;flex-direction:column; }
.sp-carte-verso-header { height:36px;padding:0 12px;display:flex;align-items:center;gap:8px;border-bottom:1px solid rgba(255,255,255,.1);flex-shrink:0; }
.sp-carte-verso-logo-wrap { width:22px;height:22px;border-radius:3px;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.sp-carte-verso-logo   { width:20px;height:20px;object-fit:contain; }
.sp-carte-verso-title  { color:rgba(255,255,255,.75);font-size:10px;font-weight:500;letter-spacing:.8px; }
.sp-carte-verso-badge  { margin-left:auto;background:#ffdd0e;border-radius:8px;padding:2px 8px;font-size:8px;font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px; }
.sp-carte-verso-body   { flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:6px;padding:12px; }
.sp-carte-verso-tkd    { color:rgba(255,255,255,.15);font-size:42px;line-height:1;user-select:none;font-family:'Song Myung',cursive;letter-spacing:4px; }
.sp-carte-verso-sub    { color:rgba(255,255,255,.35);font-size:9px;letter-spacing:2px;text-transform:uppercase; }
.sp-carte-verso-footer { height:24px;padding:0 12px;border-top:1px solid rgba(255,255,255,.1);display:flex;justify-content:space-between;align-items:center;font-size:8px;color:rgba(255,255,255,.3);flex-shrink:0; }

/* ── Grille écran : recto | verso côte à côte ── */
.cartes-grid { display:flex;flex-direction:column;gap:20px;width:fit-content;margin:0 auto; }
.carte-wrap  { display:flex;flex-direction:row;gap:16px;break-inside:avoid; }

/* ── Impression : redimensionner 340px → 85.6mm ── */
@media print {
    @page { size:A4 portrait;margin:8mm; }
    body { background:#fff;padding:0;width:177.2mm; }
    .cartes-grid { display:flex;flex-direction:column;gap:6mm;width:177.2mm; }
    .carte-wrap  { display:flex;flex-direction:row;gap:6mm;break-inside:avoid; }
    .sp-carte-recto,
    .sp-carte-verso { width:85.6mm;height:54mm;border-radius:0; }
    .sp-carte-recto { margin-bottom:0; }
    .sp-carte-band-blue   { width:22mm;height:54mm; }
    .sp-carte-band-yellow { right:21mm;width:1mm;height:54mm; }
    .sp-carte-band-red    { width:63mm;height:1.5mm; }
    .sp-carte-logo-wrap   { top:2.5mm;left:3mm;width:8mm;height:8mm;border-radius:1mm; }
    .sp-carte-logo        { width:7mm;height:7mm; }
    /* ── Nom club & sous-titre ── */
    .sp-carte-club-name   { top:3mm;left:14mm;font-size:7pt;color:#fff !important; }
    .sp-carte-club-sub    { top:7.5mm;left:14mm;font-size:5pt;color:#ffdd0e !important; }
    .sp-carte-sep         { top:13mm;left:3mm;width:60mm; }
    /* ── Nom membre — plus grand, gras ── */
    .sp-carte-nom         { top:15mm;left:3mm;right:25mm;font-size:9.5pt;font-weight:700;color:#fff !important; }
    /* ── Méta affiliation — blanc lisible ── */
    .sp-carte-meta        { top:21mm;left:3mm;right:25mm;font-size:5pt;color:rgba(255,255,255,.85) !important; }
    /* ── Bloc infos ── */
    .sp-carte-infos       { top:25.5mm;left:3mm;right:25mm;gap:1.5mm; }
    .sp-carte-info-k      { font-size:5pt;width:13mm;color:rgba(255,255,255,.8) !important; }
    .sp-carte-info-v      { font-size:6pt;color:#fff !important; }
    /* ── Zone QR ── */
    .sp-carte-qr-zone     { top:2mm;right:0.5mm;width:22mm; }
    .sp-carte-qr-box      { width:18mm;height:18mm;border-radius:1.5mm; }
    .sp-carte-qr-box img  { max-width:18mm;max-height:18mm; }
    .sp-carte-stars       { gap:0.3mm;margin-top:1mm; }
    .sp-carte-labelise-txt{ font-size:4pt;color:rgba(255,255,255,.8) !important; }
    .sp-carte-qr-scan     { font-size:4pt;color:rgba(255,255,255,.8) !important; }
    /* ── URL bas ── */
    .sp-carte-url         { bottom:2mm;right:2mm;font-size:4.5pt;color:rgba(255,255,255,.7) !important; }
    /* ── Photo ── */
    .sp-carte-photo-wrap  { bottom:3mm;left:3mm;width:12mm;height:12mm;border-width:0.5mm; }
    /* ── Verso ── */
    .sp-carte-verso-header{ height:10mm; }
    .sp-carte-verso-logo-wrap { width:6mm;height:6mm; }
    .sp-carte-verso-logo  { width:5.5mm;height:5.5mm; }
    .sp-carte-verso-title { font-size:7pt;color:#fff !important; }
    .sp-carte-verso-badge { font-size:6pt;padding:1mm 2.5mm;border-radius:2mm; }
    .sp-carte-verso-tkd   { font-size:22pt;color:rgba(255,255,255,.55) !important; }
    .sp-carte-verso-sub   { font-size:5pt;color:rgba(255,255,255,.85) !important;letter-spacing:1.5px; }
    .sp-carte-verso-footer{ height:7mm;font-size:5pt;color:rgba(255,255,255,.75) !important; }
}
</style>
<?php if ( $pro ) : ?>
<style>
/* ═══════════════════════════════════════════════════════════════
   MODE IMPRIMEUR — débord 2mm, angles droits, traits de coupe
   Carte nette  : 85.6 × 54 mm
   Carte imprimée : 89.6 × 58 mm (+2 mm chaque côté)
   Le prestataire coupe le long des traits de coupe.
═══════════════════════════════════════════════════════════════ */

/* Dimensions bleed */
body.sp-print-pro .sp-carte-recto,
body.sp-print-pro .sp-carte-verso {
    width:89.6mm !important;
    height:58mm !important;
    border-radius:0 !important;
}
body.sp-print-pro .sp-carte-recto { margin-bottom:4mm !important; }

/* Ajuste la grille pour les cartes plus larges */
@media print {
    body.sp-print-pro .carte-wrap {
        gap:10mm;
    }
    body.sp-print-pro .sp-carte-recto { margin-bottom:0 !important; }
}

/* ── Wrapper portant les traits de coupe ── */
.sp-pro-wrap {
    position:relative;
    display:inline-block;
    -webkit-print-color-adjust:exact;
    print-color-adjust:exact;
}

/* ── Traits de coupe ──
   Ligne blanche semi-transparente placée à 2 mm du bord de la carte (= ligne de coupe)
   Longueur 1.5 mm dans la zone de débord, épaisseur 0.4 pt.
   Sur fond sombre : blanc 55 % d'opacité, lisible à la lumière.           */
.sp-cm {
    position:absolute;
    background:rgba(255,255,255,.55);
    display:block;
    pointer-events:none;
    z-index:99;
    -webkit-print-color-adjust:exact;
    print-color-adjust:exact;
}
.sp-cm.h { width:1.5mm; height:.4pt; } /* horizontal */
.sp-cm.v { width:.4pt; height:1.5mm; } /* vertical   */

/* Coin haut-gauche */
.sp-cm.tl-h { top:2mm;  left:0;    }
.sp-cm.tl-v { top:0;    left:2mm;  }
/* Coin haut-droit */
.sp-cm.tr-h { top:2mm;  right:0;   }
.sp-cm.tr-v { top:0;    right:2mm; }
/* Coin bas-gauche */
.sp-cm.bl-h { bottom:2mm; left:0;    }
.sp-cm.bl-v { bottom:0;   left:2mm;  }
/* Coin bas-droit */
.sp-cm.br-h { bottom:2mm; right:0;   }
.sp-cm.br-v { bottom:0;   right:2mm; }

@media print {
    /* ── Bandes : étendre à la hauteur bleed + élargir bleue pour couvrir QR décalé ──
       QR zone : right:2.5mm + width:22mm → commence à 65.1mm du bord gauche
       Bande bleue : width:25mm → commence à 64.6mm ✓ couvre le QR
       Bande jaune : suit la bleue à right:24mm
       Bande rouge : rejoint la bande jaune → width:64.5mm                          */
    body.sp-print-pro .sp-carte-band-blue   { height:58mm !important; width:25mm   !important; }
    body.sp-print-pro .sp-carte-band-yellow { height:58mm !important; right:24mm   !important; }
    /* Bande rouge : height:3.5mm = 1.5mm visible après coupe + 2mm de débord en bas */
    body.sp-print-pro .sp-carte-band-red    { width:64.5mm !important; height:3.5mm !important; bottom:0 !important; }
    /* Logo + nom club — coin haut-gauche */
    body.sp-print-pro .sp-carte-logo-wrap { top:4.5mm !important; left:5mm !important; }
    body.sp-print-pro .sp-carte-club-name { top:5mm   !important; left:15.5mm !important; }
    body.sp-print-pro .sp-carte-club-sub  { top:9mm   !important; left:15.5mm !important; }
    /* QR code — coin haut-droit */
    body.sp-print-pro .sp-carte-qr-zone   { top:4mm   !important; right:2.5mm !important; }
    /* URL — coin bas-droit */
    body.sp-print-pro .sp-carte-url       { bottom:4mm !important; right:4mm   !important; }
    /* Photo — remontée de 2mm pour sortir de la zone de débord */
    body.sp-print-pro .sp-carte-photo-wrap { bottom:6.5mm !important; left:5mm !important; }
    /* Verso header & footer — padding latéraux */
    body.sp-print-pro .sp-carte-verso-header { padding-left:5mm !important; padding-right:5mm !important; }
    body.sp-print-pro .sp-carte-verso-footer { padding-left:5mm !important; padding-right:5mm !important; }
}
</style>
<?php endif; ?>
</head>
<body<?php if ( $pro ) echo ' class="sp-print-pro"'; ?>>
<div class="cartes-grid">
<?php foreach ( $eleves as $el ) :
    $token_url = home_url( '/fiche-membre/?token=' . rawurlencode( $el->token ?? '' ) );
    $qr_url    = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode( $token_url );
    $nom       = esc_html( $el->prenom . ' ' . mb_strtoupper( $el->nom ) );
    $footer_parts = array_filter( [ $club_affil, $club_num ? 'Club ' . $club_num : '', 'tkdclaira.fr' ] );
    $footer_str   = esc_html( implode( ' · ', $footer_parts ) );
?>
<div class="carte-wrap">
<?php if ( $pro ) : ?>
<div class="sp-pro-wrap">
    <span class="sp-cm h tl-h"></span><span class="sp-cm v tl-v"></span>
    <span class="sp-cm h tr-h"></span><span class="sp-cm v tr-v"></span>
    <span class="sp-cm h bl-h"></span><span class="sp-cm v bl-v"></span>
    <span class="sp-cm h br-h"></span><span class="sp-cm v br-v"></span>
<?php endif; ?>
    <div class="sp-carte-recto">
        <div class="sp-carte-band-blue"></div>
        <div class="sp-carte-band-yellow"></div>
        <div class="sp-carte-band-red"></div>
        <?php if ( $logo_url ) : ?>
        <div class="sp-carte-logo-wrap"><img src="<?php echo esc_url($logo_url); ?>" class="sp-carte-logo"></div>
        <?php endif; ?>
        <div class="sp-carte-club-name"><?php echo $nom_club; ?></div>
        <div class="sp-carte-club-sub">CARTE DE MEMBRE</div>
        <div class="sp-carte-sep"></div>
        <div class="sp-carte-nom"><?php echo $nom; ?></div>
        <div class="sp-carte-meta"><?php echo esc_html( $meta_str ); ?></div>
        <div class="sp-carte-infos">
            <?php if ( $el->licence ) : ?>
            <div class="sp-carte-info-row"><span class="sp-carte-info-k">Licence</span><span class="sp-carte-info-v"><?php echo esc_html($el->licence); ?></span></div>
            <?php endif; ?>
            <?php if ( $el->date_naissance && $el->annee_naissance ) : ?>
            <div class="sp-carte-info-row"><span class="sp-carte-info-k">Né(e) le</span><span class="sp-carte-info-v"><?php echo esc_html($el->date_naissance.'/'.$el->annee_naissance); ?></span></div>
            <?php endif; ?>
            <?php if ( ! empty( $el->urgence_telephone ) ) : ?>
            <div class="sp-carte-info-row"><span class="sp-carte-info-k">Urgence</span><span class="sp-carte-info-v" style="color:#e30613;"><?php echo esc_html($el->urgence_telephone); ?></span></div>
            <?php endif; ?>
        </div>
        <?php if ( ! empty( $el->photo_url ) ) : ?>
        <div class="sp-carte-photo-wrap"><img src="<?php echo esc_url($el->photo_url); ?>" class="sp-carte-photo"></div>
        <?php endif; ?>
        <div class="sp-carte-qr-zone">
            <div class="sp-carte-qr-box"><img src="<?php echo esc_url($qr_url); ?>" alt="QR"></div>
            <?php if ( $club_labelise > 0 ) : ?>
            <div class="sp-carte-stars">
                <?php for($i=1;$i<=5;$i++) echo '<span style="color:'.($i<=$club_labelise?'#ffdd0e':'rgba(255,255,255,0.2)').';font-size:10px;">★</span>'; ?>
            </div>
            <div class="sp-carte-labelise-txt">Club labellisé</div>
            <?php endif; ?>
            <div class="sp-carte-qr-scan">Scanner pour pointer</div>
        </div>
        <div class="sp-carte-url">tkdclaira.fr</div>
    </div><!-- .sp-carte-recto -->
<?php if ( $pro ) : ?>
</div><!-- .sp-pro-wrap recto -->
<div class="sp-pro-wrap">
    <span class="sp-cm h tl-h"></span><span class="sp-cm v tl-v"></span>
    <span class="sp-cm h tr-h"></span><span class="sp-cm v tr-v"></span>
    <span class="sp-cm h bl-h"></span><span class="sp-cm v bl-v"></span>
    <span class="sp-cm h br-h"></span><span class="sp-cm v br-v"></span>
<?php endif; ?>
    <div class="sp-carte-verso">
        <div class="sp-carte-verso-header">
            <?php if ( $logo_url ) : ?>
            <div class="sp-carte-verso-logo-wrap"><img src="<?php echo esc_url($logo_url); ?>" class="sp-carte-verso-logo"></div>
            <?php endif; ?>
            <span class="sp-carte-verso-title"><?php echo $nom_club; ?></span>
            <span class="sp-carte-verso-badge"><?php echo $nom; ?></span>
        </div>
        <div class="sp-carte-verso-body">
            <div class="sp-carte-verso-tkd">태권도</div>
            <div class="sp-carte-verso-sub">Carte de membre officielle</div>
        </div>
        <div class="sp-carte-verso-footer">
            <span><?php echo $footer_str; ?></span>
            <?php if ( $club_labelise > 0 ) : ?>
            <span><?php for($i=1;$i<=5;$i++) echo '<span style="color:'.($i<=$club_labelise?'#e30613':'rgba(255,255,255,.12)').';font-size:9px;">★</span>'; ?></span>
            <?php endif; ?>
        </div>
    </div><!-- .sp-carte-verso -->
<?php if ( $pro ) : ?>
</div><!-- .sp-pro-wrap verso -->
<?php endif; ?>
</div><!-- .carte-wrap -->
<?php endforeach; ?>
</div>
<script>window.onload=function(){ window.print(); window.onafterprint=function(){ window.close(); }; };</script>
</body>
</html>
<?php
        exit;
    }

    /* ══════════════════════════════════════════════════════════
       PAGE PRINT CARTES
    ══════════════════════════════════════════════════════════ */

    public function page_print_cartes() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        global $wpdb;

        $tel       = $this->db->table_eleves();
        $cats      = $this->db->get_categories_eleves();
        $cat_sel   = sanitize_text_field( wp_unslash( $_GET['cat']    ?? '' ) );
        $saison    = sanitize_text_field( wp_unslash( $_GET['saison']  ?? '' ) );
        $do_print  = isset( $_GET['print'] );

        // Récupérer les élèves
        $args = array( 'actif' => 1 );
        if ( $cat_sel )  $args['categorie_age']    = $cat_sel;
        if ( $saison )   $args['saison']            = $saison;
        $eleves = $this->db->get_eleves( $args );

        // Options club pour la carte
        $club          = get_option('blogname','Club');
        $club_num      = get_option('sp_cal_club_num','');
        $club_affil    = get_option('sp_cal_club_affiliation','');
        $club_ligue    = get_option('sp_cal_club_ligue','');
        $club_labelise = intval(get_option('sp_cal_club_labelise',0));
        $logo_url      = get_option('sp_cal_logo_url','');

        $meta_parts = array_filter([
            $club_affil ? 'FFTDA ' . $club_affil : '',
            $club_ligue,
            $club_num ? 'Club '.$club_num : '',
        ]);
        $meta_str = implode(' · ', $meta_parts);

        if ( $do_print ) :
        // ── MODE IMPRESSION : page pleine, sans chrome admin ──────────────
        ?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Cartes membres — <?php echo esc_html($cat_sel ?: 'Tous'); ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
@page { size: A4 portrait; margin: 8mm; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#fff; }
.page-a4 { width:194mm; }
.cartes-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6mm;
}
/* Carte bancaire ×2.5 */
.carte-wrap { page-break-inside: avoid; }
.carte-recto, .carte-verso {
    width: 85.6mm;
    height: 54mm;
    border-radius: 4mm;
    position: relative;
    overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.carte-recto { background:#111; margin-bottom:3mm; }
.carte-band-blue   { position:absolute;right:0;top:0;width:22mm;height:54mm;background:#0f70b7; }
.carte-band-yellow { position:absolute;right:21mm;top:0;width:1mm;height:54mm;background:#ffdd0e; }
.carte-band-red    { position:absolute;bottom:0;left:0;width:63mm;height:1.5mm;background:#e30613; }
.carte-logo-wrap   { position:absolute;top:2.5mm;left:3mm;width:8mm;height:8mm;border-radius:1mm;background:#fff;display:flex;align-items:center;justify-content:center; }
.carte-logo        { width:7mm;height:7mm;object-fit:contain; }
/* ── Nom club & sous-titre ── */
.carte-club-name   { position:absolute;top:3mm;left:13mm;color:#fff;font-size:7pt;font-weight:600;letter-spacing:1px; }
.carte-club-sub    { position:absolute;top:6.5mm;left:13mm;color:#ffdd0e;font-size:5pt;letter-spacing:0.8px; }
.carte-sep         { position:absolute;top:12mm;left:3mm;width:60mm;height:0.2mm;background:rgba(255,255,255,.3); }
/* ── Nom membre ── */
.carte-nom         { position:absolute;top:14mm;left:3mm;right:25mm;color:#fff;font-size:9pt;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
/* ── Méta ── */
.carte-meta        { position:absolute;top:19mm;left:3mm;right:25mm;color:rgba(255,255,255,.85);font-size:5pt;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
/* ── Infos ── */
.carte-infos       { position:absolute;top:23mm;left:3mm;right:25mm;display:flex;flex-direction:column;gap:1mm; }
.carte-info-row    { display:flex;gap:1.5mm;align-items:center; }
.carte-info-k      { color:rgba(255,255,255,.8);font-size:5pt;width:12mm;text-transform:uppercase;flex-shrink:0; }
.carte-info-v      { color:#fff;font-size:6pt;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
/* ── Photo ── */
.carte-photo-wrap  { position:absolute;bottom:3mm;left:3mm;width:12mm;height:12mm;border-radius:50%;overflow:hidden;border:0.5mm solid rgba(255,255,255,.18);background:#222; }
.carte-photo       { width:100%;height:100%;object-fit:cover; }
/* ── Zone QR ── */
.carte-qr-zone     { position:absolute;top:2mm;right:0.5mm;width:22mm;display:flex;flex-direction:column;align-items:center;gap:0.5mm; }
.carte-qr-box      { width:18mm;height:18mm;background:#fff;border-radius:1.5mm;overflow:hidden;display:flex;align-items:center;justify-content:center; }
.carte-qr-box img  { width:100%;height:100%; }
.carte-stars       { display:flex;gap:0.3mm; }
.carte-qr-scan     { color:rgba(255,255,255,.8);font-size:4pt;text-align:center; }
.carte-url         { position:absolute;bottom:2mm;right:1.5mm;color:rgba(255,255,255,.7);font-size:4.5pt; }
/* ── Verso ── */
.carte-verso       { background:#111; }
.carte-verso-hd    { height:9mm;padding:0 3mm;display:flex;align-items:center;gap:2mm;border-bottom:0.3mm solid rgba(255,255,255,.2); }
.carte-verso-logo  { width:5.5mm;height:5.5mm;object-fit:contain; }
.carte-verso-title { color:#fff;font-size:7pt;font-weight:600;letter-spacing:0.5px; }
.carte-verso-badge { margin-left:auto;background:#ffdd0e;border-radius:2mm;padding:0.5mm 2mm;font-size:5pt;font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:30mm; }
.carte-verso-body  { flex:1;display:flex;align-items:center;justify-content:center;padding:3mm; }
.carte-verso-ft    { height:6mm;padding:0 3mm;border-top:0.3mm solid rgba(255,255,255,.2);display:flex;justify-content:space-between;align-items:center;font-size:5pt;color:rgba(255,255,255,.75); }
</style>
</head>
<body>
<div class="page-a4">
<div class="cartes-grid">
<?php foreach ( $eleves as $el ) :
    $token_url = home_url('/fiche-membre/?token=' . rawurlencode($el->token ?? ''));
    $qr_url    = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($token_url);
?>
<div class="carte-wrap">
    <!-- RECTO -->
    <div class="carte-recto">
        <div class="carte-band-blue"></div>
        <div class="carte-band-yellow"></div>
        <div class="carte-band-red"></div>
        <?php if ($logo_url): ?>
        <div class="carte-logo-wrap">
            <img src="<?php echo esc_url($logo_url); ?>" class="carte-logo">
        </div>
        <?php endif; ?>
        <div class="carte-club-name"><?php echo esc_html(strtoupper($club)); ?></div>
        <div class="carte-club-sub">CARTE DE MEMBRE</div>
        <div class="carte-sep"></div>
        <div class="carte-nom"><?php echo esc_html($el->prenom . ' ' . mb_strtoupper($el->nom)); ?></div>
        <div class="carte-meta"><?php echo esc_html($meta_str); ?></div>
        <div class="carte-infos">
            <?php if ($el->licence): ?>
            <div class="carte-info-row">
                <span class="carte-info-k">Licence</span>
                <span class="carte-info-v"><?php echo esc_html($el->licence); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($el->date_naissance && $el->annee_naissance): ?>
            <div class="carte-info-row">
                <span class="carte-info-k">Né(e) le</span>
                <span class="carte-info-v"><?php echo esc_html($el->date_naissance.'/'.$el->annee_naissance); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($el->urgence_telephone)): ?>
            <div class="carte-info-row">
                <span class="carte-info-k">Urgence</span>
                <span class="carte-info-v" style="color:#e30613;"><?php echo esc_html($el->urgence_telephone); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($el->photo_url)): ?>
        <div class="carte-photo-wrap">
            <img src="<?php echo esc_url($el->photo_url); ?>" class="carte-photo">
        </div>
        <?php endif; ?>
        <div class="carte-qr-zone">
            <div class="carte-qr-box">
                <img src="<?php echo esc_url($qr_url); ?>" alt="QR">
            </div>
            <?php if ($club_labelise > 0): ?>
            <div class="carte-stars">
                <?php for($i=1;$i<=5;$i++) echo '<span style="color:'.($i<=$club_labelise?'#ffdd0e':'rgba(255,255,255,0.2)').';font-size:3pt;">★</span>'; ?>
            </div>
            <?php endif; ?>
            <div class="carte-qr-scan">Scanner pour pointer</div>
        </div>
        <div class="carte-url">tkdclaira.fr</div>
    </div>
    <!-- VERSO -->
    <div class="carte-verso">
        <div class="carte-verso-hd">
            <?php if ($logo_url): ?>
            <img src="<?php echo esc_url($logo_url); ?>" class="carte-verso-logo">
            <?php endif; ?>
            <span class="carte-verso-title"><?php echo esc_html(strtoupper($club)); ?></span>
            <span class="carte-verso-badge"><?php echo esc_html($el->prenom . ' ' . mb_strtoupper($el->nom)); ?></span>
        </div>
        <div class="carte-verso-body">
            <div style="color:rgba(255,255,255,.06);font-size:20pt;user-select:none;">🥋</div>
        </div>
        <div class="carte-verso-ft">
            <span><?php echo esc_html($meta_str ?: 'tkdclaira.fr'); ?></span>
            <?php if ($club_labelise > 0): ?>
            <span><?php for($i=1;$i<=5;$i++) echo '<span style="color:'.($i<=$club_labelise?'#e30613':'rgba(255,255,255,.12)').';font-size:3pt;">★</span>'; ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div><!-- .cartes-grid -->
</div><!-- .page-a4 -->
<script>window.onload = function(){ window.print(); }</script>
</body>
</html>
<?php
        return;
        endif;

        // ── MODE SÉLECTION : interface admin ──────────────────────────────
        $saisons = $this->db->get_saisons();
        ?>
        <div class="wrap" style="max-width:780px;">

        <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);border-radius:12px;padding:28px 32px;margin-bottom:24px;display:flex;align-items:center;gap:24px;">
            <?php if ( $logo_url ) : ?>
            <div style="width:56px;height:56px;border-radius:10px;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <img src="<?php echo esc_url($logo_url); ?>" style="width:48px;height:48px;object-fit:contain;">
            </div>
            <?php endif; ?>
            <div>
                <h1 style="color:#fff;font-size:20px;font-weight:700;margin:0 0 4px;">Cartes de membre</h1>
                <p style="color:rgba(255,255,255,.5);font-size:13px;margin:0;">Impression en lot · Format carte bancaire 85,6 × 54 mm · Recto + Verso</p>
            </div>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px 28px;margin-bottom:20px;">
            <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;margin-bottom:16px;">Filtres</div>
            <form method="get" action="">
                <input type="hidden" name="page" value="sp-cal-print-cartes">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                    <div>
                        <label style="display:block;font-weight:600;font-size:12px;color:#374151;margin-bottom:6px;">Catégorie d'âge</label>
                        <select name="cat" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:9px 12px;font-size:14px;background:#fff;color:#111;">
                            <option value="">— Toutes les catégories —</option>
                            <?php foreach ( $cats as $c ) : ?>
                            <option value="<?php echo esc_attr($c); ?>" <?php selected($cat_sel,$c); ?>><?php echo esc_html($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;font-size:12px;color:#374151;margin-bottom:6px;">Saison</label>
                        <select name="saison" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:9px 12px;font-size:14px;background:#fff;color:#111;">
                            <option value="">— Toutes les saisons —</option>
                            <?php foreach ( $saisons as $s ) : ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($saison,$s); ?>><?php echo esc_html($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="button" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 18px;font-size:13px;cursor:pointer;">🔍 Filtrer</button>
            </form>
        </div>

        <?php if ( $eleves ) : ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px 28px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
                <div>
                    <span style="font-size:28px;font-weight:700;color:#0f172a;"><?php echo count($eleves); ?></span>
                    <span style="font-size:14px;color:#64748b;margin-left:6px;">carte(s) à imprimer</span>
                    <?php if ( $cat_sel || $saison ) : ?>
                    <div style="margin-top:4px;">
                        <?php if ( $cat_sel ) echo '<span style="display:inline-block;background:#eff6ff;color:#1d4ed8;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600;margin-right:4px;">' . esc_html($cat_sel) . '</span>'; ?>
                        <?php if ( $saison )  echo '<span style="display:inline-block;background:#f0fdf4;color:#15803d;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600;">' . esc_html($saison) . '</span>'; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <a id="sp-print-btn"
                   href="<?php echo esc_url( add_query_arg( array(
                       'page'   => 'sp-cal-print-cartes',
                       'cat'    => $cat_sel,
                       'saison' => $saison,
                       'print'  => '1',
                   ), admin_url('admin.php') ) ); ?>"
                   target="_blank"
                   style="display:inline-flex;align-items:center;gap:8px;background:#0f70b7;color:#fff;border-radius:8px;padding:11px 22px;font-size:14px;font-weight:600;text-decoration:none;">
                    🖨️ Lancer l'impression
                </a>
            </div>

            <div style="border-top:1px solid #f1f5f9;padding-top:16px;">
                <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;">Options d'impression</div>
                <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 16px;">
                    <input type="checkbox" id="sp-mode-imprimeur" value="1">
                    <div>
                        <div style="font-size:13px;font-weight:600;color:#374151;">🖨️ Mode imprimeur</div>
                        <div style="font-size:11px;color:#94a3b8;margin-top:1px;">Débord 2 mm · angles droits · traits de coupe</div>
                    </div>
                </label>
            </div>
        </div>
        <?php else : ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:40px 28px;text-align:center;">
            <div style="font-size:32px;margin-bottom:8px;">🔍</div>
            <div style="font-size:14px;color:#64748b;">Aucun élève actif trouvé pour cette sélection.</div>
        </div>
        <?php endif; ?>

        </div>
        <script>
        document.getElementById('sp-mode-imprimeur').addEventListener('change', function () {
            var btn  = document.getElementById('sp-print-btn');
            var base = btn.href.replace(/&pro=1/, '');
            btn.href = this.checked ? base + '&pro=1' : base;
        });
        </script>
        <?php
    }
}

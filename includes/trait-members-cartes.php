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
        // Dessin unique de la carte (class-carte.php, refonte du 13/10/2026) : mêmes recto et verso
        // que sur la fiche personnelle, aux dimensions réelles (85,6 × 54 mm, + 2 mm de débord et
        // traits de coupe en mode imprimeur).
        ?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Cartes membres — <?php echo esc_html( $cat_sel ?: 'Tous' ); ?></title>
<style>
* { box-sizing:border-box; }
body { margin:0; padding:8mm; background:#fff; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
<?php echo SP_Cal_Carte::css(); ?>
.cartes-grid { display:flex; flex-direction:column; gap:6mm; width:fit-content; margin:0 auto; }
.carte-wrap  { display:flex; flex-direction:row; gap:<?php echo $pro ? '8mm' : '6mm'; ?>; break-inside:avoid; page-break-inside:avoid; }
@media print {
    @page { size:A4 portrait; margin:8mm; }
    body { padding:0; }
}
</style>
</head>
<body>
<div class="cartes-grid">
<?php foreach ( $eleves as $el ) :
    $token_url = home_url( '/fiche-membre/?token=' . rawurlencode( $el->token ?? '' ) );
    $qr_url    = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode( $token_url );
    $carte     = SP_Cal_Carte::html( $el, array(
        'qr'         => '<img src="' . esc_url( $qr_url ) . '" alt="QR">',
        'discipline' => $this->db->label_discipline( $el->categorie_saisie ?? '' ),
        'impression' => true,
        'imprimeur'  => $pro,
    ) );
?>
<div class="carte-wrap"><?php echo $carte['recto'] . $carte['verso']; ?></div>
<?php endforeach; ?>
</div>
<script>
window.onload = function () {
    var go = function () { window.print(); window.onafterprint = function () { window.close(); }; };
    if ( document.fonts && document.fonts.ready ) { document.fonts.ready.then( go ); } else { go(); }
};
</script>
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

        <?php if ( $eleves ) :
            // Photos telles qu'elles apparaîtront sur la carte (13/10/2026). Le navigateur repère celles
            // qui sont en largeur et n'ont jamais été recadrées : ce sont en général des photos scannées,
            // dont le visage n'est pas au centre. « Recadrer » ouvre la fiche sur l'outil de cadrage.
        ?>
        <div id="sp-photos-cartes" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px 28px;margin-top:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
                <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;">Photos des cartes</div>
                <label style="font-size:13px;"><input type="checkbox" id="sp-photos-filtre"> Seulement celles à revoir</label>
            </div>
            <p style="font-size:13px;color:#64748b;margin:0 0 14px;">
                Chaque photo est montrée dans le cadre de la carte. <strong id="sp-photos-compte">…</strong>
            </p>
            <div id="sp-photos-grille" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:14px;">
            <?php foreach ( $eleves as $el ) :
                $extra_ph   = json_decode( (string) ( $el->extra_data ?? '' ), true );
                $recadree   = is_array( $extra_ph ) && ! empty( $extra_ph['photo_cadrage'] );
                $a_photo    = ! empty( $el->photo_url );
            ?>
                <div class="sp-photo-carte" data-photo="<?php echo $a_photo ? '1' : '0'; ?>" data-recadree="<?php echo $recadree ? '1' : '0'; ?>" style="text-align:center;font-size:12px;">
                    <div style="width:<?php echo intval( SP_Cal_Carte::PHOTO_L ); ?>px;height:<?php echo intval( SP_Cal_Carte::PHOTO_H ); ?>px;margin:0 auto 6px;border-radius:6px;overflow:hidden;border:2px solid #111;background:#1f2937;display:flex;align-items:center;justify-content:center;">
                        <?php if ( $a_photo ) : ?>
                        <img src="<?php echo esc_url( $el->photo_url ); ?>" alt="" loading="lazy" style="width:100%;height:100%;display:block;<?php echo esc_attr( SP_Cal_Carte::style_photo( $el ) ); ?>">
                        <?php else : ?>
                        <span style="color:#fff;font-size:28px;font-weight:600;"><?php echo esc_html( SP_Cal_Carte::initiales( $el ) ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="font-weight:600;color:#0f172a;line-height:1.25;"><?php echo esc_html( $el->prenom . ' ' . mb_strtoupper( $el->nom ) ); ?></div>
                    <div class="sp-photo-etat" style="min-height:18px;margin:2px 0;color:#64748b;"><?php echo $a_photo ? '' : 'Sans photo'; ?></div>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sp-cal-eleves&sp_edit_eleve=' . intval( $el->id ) ) . '#sp-cadrage' ); ?>"><?php echo $a_photo ? 'Recadrer' : 'Ajouter une photo'; ?></a>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <script>
        (function(){
            var grille = document.getElementById('sp-photos-grille'), filtre = document.getElementById('sp-photos-filtre'), compte = document.getElementById('sp-photos-compte');
            if (!grille) return;
            var tuiles = Array.prototype.slice.call(grille.querySelectorAll('.sp-photo-carte'));
            function marquer(t, texte) {
                t.dataset.revoir = '1';
                var e = t.querySelector('.sp-photo-etat');
                e.textContent = texte; e.style.color = '#b45309'; e.style.fontWeight = '600';
            }
            function compter() {
                var n = tuiles.filter(function(t){ return t.dataset.revoir === '1'; }).length;
                var sans = tuiles.filter(function(t){ return t.dataset.photo === '0'; }).length;
                compte.textContent = n + ' photo(s) à recadrer, ' + sans + ' adhérent(s) sans photo, sur ' + tuiles.length + (restantes > 0 ? ' — lecture des photos en cours…' : '.');
            }
            function bilan() {
                compter();
                // Celles à revoir d'abord, puis les « sans photo », puis les autres.
                tuiles.slice().sort(function(a, b){
                    var ra = a.dataset.revoir === '1' ? 0 : (a.dataset.photo === '0' ? 1 : 2), rb = b.dataset.revoir === '1' ? 0 : (b.dataset.photo === '0' ? 1 : 2);
                    return ra - rb;
                }).forEach(function(t){ grille.appendChild(t); });
                appliquer();
            }
            function appliquer() {
                tuiles.forEach(function(t){ t.style.display = (!filtre.checked || t.dataset.revoir === '1' || t.dataset.photo === '0') ? '' : 'none'; });
            }
            filtre.addEventListener('change', appliquer);
            var restantes = grille.querySelectorAll('img').length;
            tuiles.forEach(function(t){
                var img = t.querySelector('img');
                if (!img) return;
                function fini() {
                    if (img.naturalWidth && img.naturalWidth > img.naturalHeight && t.dataset.recadree !== '1') marquer(t, 'Photo en largeur : à recadrer');
                    if (!img.naturalWidth) marquer(t, 'Photo illisible');
                    // Le compte avance photo par photo ; le tri n'est fait qu'une fois toutes lues.
                    if (--restantes === 0) bilan(); else compter();
                }
                // Chargement différé : on force le chargement pour pouvoir mesurer toutes les photos.
                img.loading = 'eager';
                if (img.complete) fini(); else { img.addEventListener('load', fini); img.addEventListener('error', fini); }
            });
            if (!restantes) bilan();
        })();
        </script>
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

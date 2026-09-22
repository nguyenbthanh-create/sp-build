<?php
/**
 * Template : Parcours de progression — Chemin de ceinture
 *
 * Variables attendues :
 *   $parcours  array   — résultat de get_parcours_eleve()
 *   $eleve     object  — ligne table eleves
 *   $uid       string  — identifiant unique pour éviter conflits si plusieurs fiches sur la page
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( empty($parcours) || empty($parcours['steps']) ) {
    echo '<p style="color:#9ca3af;font-size:13px;">Configurez la progression des grades dans le menu <strong>⚖️ Jury → 🎯 Grades</strong> pour afficher le parcours.</p>';
    return;
}

$uid        = $uid ?? 'el' . intval($eleve->id ?? 0);
$steps      = $parcours['steps'];
$pct        = $parcours['pct'];
$nb_passes  = $parcours['nb_passes'];
$nb_total   = $parcours['nb_total'];
$objectif   = $parcours['objectif'];
$prochain   = $parcours['prochain'];
$grade_courant = $parcours['grade_courant'];
$epreuves_prochain = $parcours['epreuves_prochain'] ?? array();

// Couleurs des ceintures taekwondo selon le nom du grade
function sp_belt_color( $grade ) {
    $g = mb_strtolower($grade);
    // Dan (noir)
    if ( strpos($g, 'dan') !== false )
        return array('bg'=>'#1a1a1a','text'=>'#fff','border'=>'#000','emoji'=>'🖤','bicolor'=>false);
    // POOM (noir/rouge)
    if ( strpos($g, 'poom') !== false )
        return array('bg'=>'#1a1a1a','text'=>'#ef4444','border'=>'#991b1b','emoji'=>'🖤','bicolor'=>true,'bg2'=>'#dc2626');
    // Rouge
    if ( strpos($g, 'rouge') !== false )
        return array('bg'=>'#dc2626','text'=>'#fff','border'=>'#991b1b','emoji'=>'🔴','bicolor'=>false);
    // Bleue** (bleu foncé)
    if ( strpos($g, 'bleu') !== false && strpos($g, '**') !== false )
        return array('bg'=>'#1d4ed8','text'=>'#fff','border'=>'#1e3a8a','emoji'=>'🔵','bicolor'=>false);
    // Bleue* (bleu moyen)
    if ( strpos($g, 'bleu') !== false && strpos($g, '*') !== false )
        return array('bg'=>'#2563eb','text'=>'#fff','border'=>'#1d4ed8','emoji'=>'🔵','bicolor'=>false);
    // Bleue simple
    if ( strpos($g, 'bleu') !== false )
        return array('bg'=>'#3b82f6','text'=>'#fff','border'=>'#2563eb','emoji'=>'🔵','bicolor'=>false);
    // Violette* ou violette
    if ( strpos($g, 'violet') !== false )
        return array('bg'=>'#7c3aed','text'=>'#fff','border'=>'#6d28d9','emoji'=>'🟣','bicolor'=>false);
    // Orange* ou orange
    if ( strpos($g, 'orange') !== false )
        return array('bg'=>'#ea580c','text'=>'#fff','border'=>'#c2410c','emoji'=>'🟠','bicolor'=>false);
    // Blanche/jaune (bicolore)
    if ( strpos($g, 'blanche') !== false && strpos($g, 'jaune') !== false )
        return array('bg'=>'#f3f4f6','text'=>'#374151','border'=>'#d1d5db','emoji'=>'⚪','bicolor'=>true,'bg2'=>'#facc15');
    // Jaune** 
    if ( strpos($g, 'jaune') !== false && strpos($g, '**') !== false )
        return array('bg'=>'#facc15','text'=>'#1a1a1a','border'=>'#ca8a04','emoji'=>'🟡','bicolor'=>false);
    // Jaune*
    if ( strpos($g, 'jaune') !== false && strpos($g, '*') !== false )
        return array('bg'=>'#fbbf24','text'=>'#1a1a1a','border'=>'#f59e0b','emoji'=>'🟡','bicolor'=>false);
    // Jaune simple
    if ( strpos($g, 'jaune') !== false )
        return array('bg'=>'#facc15','text'=>'#1a1a1a','border'=>'#ca8a04','emoji'=>'🟡','bicolor'=>false);
    // Blanc / débutant
    return array('bg'=>'#f3f4f6','text'=>'#374151','border'=>'#d1d5db','emoji'=>'⚪','bicolor'=>false);
}

$couleur_courant = sp_belt_color($grade_courant ?: '');
$couleur_prochain = $prochain ? sp_belt_color($prochain) : null;
?>
<style>
#sp-parcours-<?php echo $uid; ?> {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

/* ── Barre de progression ── */
.spp-progress-wrap {
    margin-bottom: 20px;
}
.spp-progress-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
    flex-wrap: wrap;
    gap: 8px;
}
.spp-pct-badge {
    font-size: 22px;
    font-weight: 900;
    color: #1e3a5f;
}
.spp-pct-label {
    font-size: 12px;
    color: #64748b;
    margin-top: 1px;
}
.spp-objectif-chip {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
    background: #1a1a1a;
    color: #fff;
}
.spp-bar-track {
    height: 12px;
    background: #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
}
.spp-bar-fill {
    height: 100%;
    border-radius: 10px;
    background: linear-gradient(90deg, #ca8a04, #ea580c, #16a34a, #2563eb, #dc2626, #1a1a1a);
    background-size: 200% 100%;
    transition: width 1.2s cubic-bezier(.4,0,.2,1);
    width: 0%;
}
.spp-bar-label {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: #9ca3af;
    margin-top: 4px;
}

/* ── Carte prochain grade ── */
.spp-next-card {
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.spp-next-belt {
    width: 48px;
    height: 10px;
    border-radius: 5px;
    flex-shrink: 0;
}
.spp-next-title {
    font-size: 14px;
    font-weight: 800;
    flex: 1;
}
.spp-next-epreuves {
    font-size: 12px;
    color: #374151;
    margin-top: 3px;
}
.spp-ep-chip {
    display: inline-block;
    background: rgba(255,255,255,.6);
    border-radius: 4px;
    padding: 1px 7px;
    margin: 2px 3px 2px 0;
    font-size: 11px;
    font-weight: 600;
}

/* ── Timeline horizontale scrollable ── */
.spp-timeline-wrap {
    overflow-x: auto;
    padding-bottom: 8px;
    margin-bottom: 4px;
}
.spp-timeline {
    display: flex;
    align-items: flex-start;
    gap: 0;
    min-width: max-content;
    padding: 8px 4px 16px;
    position: relative;
}
/* Ligne de connexion */
.spp-timeline::before {
    content: '';
    position: absolute;
    top: 28px;
    left: 20px;
    right: 20px;
    height: 3px;
    background: #e5e7eb;
    z-index: 0;
}

.spp-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    position: relative;
    z-index: 1;
    min-width: 64px;
    cursor: pointer;
    transition: transform .15s;
}
.spp-step:hover { transform: translateY(-2px); }

.spp-dot {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 3px solid;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 800;
    transition: box-shadow .2s;
    position: relative;
}
.spp-step.passe   .spp-dot { box-shadow: 0 0 0 2px rgba(0,0,0,.1); }
.spp-step.actuel  .spp-dot { box-shadow: 0 0 0 4px rgba(37,99,235,.3), 0 0 12px rgba(37,99,235,.2); animation: spp-pulse 2s infinite; }
.spp-step.futur   .spp-dot { opacity: .75; }
.spp-step.objectif .spp-dot { box-shadow: 0 0 0 4px rgba(0,0,0,.2); }

@keyframes spp-pulse {
    0%,100% { box-shadow: 0 0 0 4px rgba(37,99,235,.3); }
    50%      { box-shadow: 0 0 0 7px rgba(37,99,235,.15); }
}

.spp-dot-check {
    position: absolute;
    top: -4px; right: -4px;
    width: 14px; height: 14px;
    background: #16a34a;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 8px; color: #fff; font-weight: 700;
}

.spp-step-label {
    font-size: 10px;
    font-weight: 600;
    text-align: center;
    line-height: 1.2;
    max-width: 60px;
    color: #374151;
}
.spp-step.futur .spp-step-label { color: #9ca3af; }
.spp-step.actuel .spp-step-label { color: #1e3a5f; font-weight: 800; }

.spp-step-date {
    font-size: 9px;
    color: #16a34a;
    text-align: center;
    font-weight: 600;
}

/* Connecteur entre steps */
.spp-connector {
    width: 20px;
    height: 3px;
    margin-top: 16px;
    flex-shrink: 0;
    z-index: 1;
}
.spp-connector.passe   { background: linear-gradient(90deg,#22c55e,#22c55e); }
.spp-connector.mixte   { background: linear-gradient(90deg,#22c55e,#e5e7eb); }
.spp-connector.futur   { background: #e5e7eb; }

/* ── Popup détail grade (au clic) ── */
.spp-detail {
    display: none;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 14px 18px;
    margin-top: 12px;
    font-size: 13px;
    animation: spp-fadein .15s ease;
}
@keyframes spp-fadein { from { opacity:0; transform:translateY(-4px); } to { opacity:1; transform:translateY(0); } }
.spp-detail h4 { margin: 0 0 8px; font-size: 14px; font-weight: 800; }
.spp-detail-ep { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.spp-detail-ep-item {
    background: #f3f4f6;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 600;
    color: #374151;
}
.spp-detail-close {
    float: right;
    background: none;
    border: none;
    cursor: pointer;
    color: #9ca3af;
    font-size: 16px;
    line-height: 1;
    padding: 0;
}

/* ── Contenu pédagogique dans le popup ── */
.spp-pedagogique-desc {
    font-size: 14px;
    line-height: 1.7;
    color: #374151;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid #f0f0f0;
}
.spp-ressources-titre {
    font-size: 12px;
    font-weight: 800;
    color: #1e3a5f;
    margin: 16px 0 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.spp-videos-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}
.spp-video-btn {
    position: relative;
    width: 180px;
    background: #111;
    border: none;
    border-radius: 10px;
    overflow: hidden;
    cursor: pointer;
    padding: 0;
    text-decoration: none;
    display: block;
    transition: transform 0.2s;
}
.spp-video-btn:hover { transform: scale(1.03); }
.spp-video-thumb {
    display: block;
    width: 100%;
    height: 100px;
    background-size: cover;
    background-position: center;
    opacity: 0.8;
    transition: opacity 0.2s;
}
.spp-video-btn:hover .spp-video-thumb { opacity: 0.6; }
.spp-video-play {
    position: absolute;
    top: 38px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 26px;
    color: #fff;
    text-shadow: 0 2px 8px rgba(0,0,0,0.6);
    pointer-events: none;
}
.spp-video-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: #fff;
    text-align: center;
    padding: 6px 8px;
    background: rgba(0,0,0,0.75);
    line-height: 1.3;
}
.spp-ressources-liste {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 16px;
}
.spp-ressource-lien {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    text-decoration: none;
    color: #1e40af;
    font-size: 13px;
    font-weight: 600;
    transition: background 0.15s;
}
.spp-ressource-lien:hover { background: #eff6ff; border-color: #bfdbfe; }
.spp-ressource-fleche { color: #94a3b8; font-size: 16px; }
.spp-ressource-image img {
    max-width: 100%;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    margin-top: 6px;
}
.spp-ressource-label {
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 4px;
}
.spp-aucune-ressource {
    color: #9ca3af;
    font-size: 13px;
    font-style: italic;
    text-align: center;
    padding: 20px 0;
}

/* ── Lecteur vidéo flottant ── */
.spp-video-player {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999999;
    background: rgba(0,0,0,0.88);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    align-items: center;
    justify-content: center;
    flex-direction: column;
    padding: 20px;
}
.spp-video-player-inner {
    position: relative;
    width: 100%;
    max-width: 840px;
}
.spp-video-player-inner iframe {
    width: 100%;
    aspect-ratio: 16/9;
    border: none;
    border-radius: 14px;
    box-shadow: 0 30px 80px rgba(0,0,0,0.7);
}
.spp-video-player-title {
    color: rgba(255,255,255,0.9);
    font-size: 15px;
    font-weight: 700;
    text-align: center;
    margin-bottom: 14px;
}
.spp-video-player-close {
    position: absolute;
    top: -44px;
    right: 0;
    background: rgba(255,255,255,0.15);
    border: none;
    color: #fff;
    font-size: 20px;
    cursor: pointer;
    border-radius: 50%;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
    line-height: 1;
}
.spp-video-player-close:hover { background: rgba(255,255,255,0.3); }
</style>

<div id="sp-parcours-<?php echo $uid; ?>">

    <!-- Barre de progression -->
    <div class="spp-progress-wrap">
        <div class="spp-progress-header">
            <div>
                <div class="spp-pct-badge"><?php echo $pct; ?>%</div>
                <div class="spp-pct-label"><?php echo $nb_passes; ?> grade<?php echo $nb_passes>1?'s':''; ?> passé<?php echo $nb_passes>1?'s':''; ?> sur <?php echo $nb_total - 1; ?></div>
            </div>
            <?php if ($objectif): $col_obj = sp_belt_color($objectif); ?>
            <div class="spp-objectif-chip">
                <?php echo $col_obj['emoji']; ?> Objectif : <?php echo esc_html($objectif); ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="spp-bar-track">
            <div class="spp-bar-fill" id="spp-fill-<?php echo $uid; ?>" style="width:0%"></div>
        </div>
        <div class="spp-bar-label">
            <span>Début</span>
            <span><?php echo esc_html($objectif ?: 'Fin'); ?></span>
        </div>
    </div>

    <!-- Prochain grade -->
    <?php if ($prochain && $couleur_prochain): ?>
    <div class="spp-next-card" style="background:<?php echo $couleur_prochain['bg']; ?>1a;border:1px solid <?php echo $couleur_prochain['border']; ?>40;">
        <div class="spp-next-belt" style="background:<?php echo $couleur_prochain['bg']; ?>;border:1px solid <?php echo $couleur_prochain['border']; ?>;"></div>
        <div style="flex:1;">
            <div class="spp-next-title" style="color:<?php echo $couleur_prochain['bg']; ?>;">
                🎯 Prochain : <?php echo esc_html($prochain); ?>
            </div>
            <?php if (!empty($epreuves_prochain)): ?>
            <div class="spp-next-epreuves">
                Épreuves requises :
                <div style="margin-top:4px;">
                <?php foreach ($epreuves_prochain as $ep): ?>
                <span class="spp-ep-chip" style="background:<?php echo $couleur_prochain['bg']; ?>22;border:1px solid <?php echo $couleur_prochain['border']; ?>44;">
                    <?php echo esc_html($ep->nom); ?>
                </span>
                <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="spp-next-epreuves" style="color:#64748b;">Aucune épreuve configurée pour ce grade.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Instruction -->
    <p style="font-size:12px; color:#9ca3af; text-align:center; margin:0 0 8px; font-style:italic;">
        👆 Cliquez sur les pastilles pour afficher du contenu pédagogique
    </p>

    <!-- Timeline horizontale -->
    <div class="spp-timeline-wrap">
    <div class="spp-timeline" id="spp-tl-<?php echo $uid; ?>">
    <?php foreach ($steps as $idx => $step):
        $col = sp_belt_color($step['grade']);
        $cls = $step['statut'];
        if ($step['is_objectif']) $cls .= ' objectif';
        $label = $step['grade'];
        // Raccourcir si trop long
        if (mb_strlen($label) > 10) $label = mb_substr($label,0,9).'…';
        $has_epreuves = !empty($step['epreuves']);
        // Connecteur avant chaque step sauf le premier
        if ($idx > 0):
            $prev_statut = $steps[$idx-1]['statut'];
            $cur_statut  = $step['statut'];
            $conn_cls = ($prev_statut==='passe' && $cur_statut==='passe') ? 'passe'
                      : ($prev_statut==='passe' && $cur_statut==='actuel' ? 'mixte' : 'futur');
        ?>
        <div class="spp-connector <?php echo $conn_cls; ?>"></div>
        <?php endif; ?>

        <div class="spp-step <?php echo $cls; ?>"
             data-idx="<?php echo $idx; ?>"
             data-uid="<?php echo $uid; ?>"
             onclick="sppToggle('<?php echo $uid; ?>',<?php echo $idx; ?>)"
             title="<?php echo esc_attr($step['grade']); ?><?php echo $step['date']?' — '.date('d/m/Y',strtotime($step['date'])):''; ?>">

            <?php
                $bg_style = (!empty($col['bicolor']) && !empty($col['bg2']))
                    ? "background:linear-gradient(to right, {$col['bg']} 50%, {$col['bg2']} 50%)"
                    : "background:{$col['bg']}";
            ?>
            <div class="spp-dot"
                 style="<?php echo $bg_style; ?>;color:<?php echo $col['text']; ?>;border-color:<?php echo $col['border']; ?>;">
                <?php if ($step['statut']==='passe'): ?>
                    ✓
                    <div class="spp-dot-check">✓</div>
                <?php elseif ($step['statut']==='actuel'): ?>
                    ★
                <?php else: ?>
                    <?php echo $step['is_objectif'] ? '🏆' : '·'; ?>
                <?php endif; ?>
            </div>

            <div class="spp-step-label"><?php echo esc_html($label); ?></div>

            <?php if ($step['date']): ?>
            <div class="spp-step-date"><?php echo date('m/Y',strtotime($step['date'])); ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
    </div>

    <!-- Détail grade (affiché au clic, un seul à la fois) -->
    <div class="spp-detail" id="spp-detail-<?php echo $uid; ?>">
        <button class="spp-detail-close" onclick="sppClose('<?php echo $uid; ?>')">✕</button>
        <h4 id="spp-detail-title-<?php echo $uid; ?>"></h4>
        <div id="spp-detail-content-<?php echo $uid; ?>"></div>
    </div>

<!-- Modal contenu grade -->
<div id="spp-modal-<?php echo $uid; ?>" style="display:none;position:fixed;inset:0;z-index:99999;
     background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:14px;max-width:600px;width:100%;max-height:85vh;
         overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div id="spp-modal-hd-<?php echo $uid; ?>" style="padding:18px 22px;border-bottom:1px solid #e5e7eb;
             display:flex;align-items:center;gap:12px;position:sticky;top:0;background:#fff;z-index:1;">
            <div id="spp-modal-belt-<?php echo $uid; ?>" style="width:40px;height:8px;border-radius:4px;"></div>
            <div style="flex:1;">
                <div id="spp-modal-title-<?php echo $uid; ?>" style="font-size:17px;font-weight:800;"></div>
                <div id="spp-modal-statut-<?php echo $uid; ?>" style="font-size:12px;color:#64748b;"></div>
            </div>
            <button onclick="sppCloseModal('<?php echo $uid; ?>')" style="background:none;border:none;
                    cursor:pointer;font-size:22px;color:#9ca3af;line-height:1;">✕</button>
        </div>
        <div id="spp-modal-body-<?php echo $uid; ?>" style="padding:20px 22px;"></div>
    </div>
</div>

</div><!-- #sp-parcours -->

<?php if ( ! empty($parcours['historique']) ) :
    // Afficher dans l'ordre inversé : catégorie la plus récente en premier
    $hist_reversed = array_reverse($parcours['historique']);
?>
<div id="sp-historique-<?php echo $uid; ?>" style="margin-top:24px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
        <span style="font-size:13px;font-weight:800;color:#6b7280;text-transform:uppercase;letter-spacing:1px;">📖 Historique</span>
        <div style="flex:1;height:1px;background:#e5e7eb;"></div>
    </div>
    <?php foreach ( $hist_reversed as $hist ) :
        // Ne garder que les steps à partir du grade de transition
        // (le dernier grade de cette catégorie = premier step du suivant)
        $steps_hist = $hist['steps'];
        // Trouver le point de départ : grade qui correspond à une transition
        // On cherche depuis quel step l'élève a réellement progressé
        // Simple : on garde tous les steps qui ont une date OU à partir du premier avec date
        $first_dated = -1;
        foreach ($steps_hist as $si => $hs) {
            if ($hs['date']) { $first_dated = $si; break; }
        }
        // Si on a des dates, on part du début (tout le parcours est intéressant)
        // Sinon on affiche quand même tout
    ?>
    <div style="margin-bottom:12px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <div style="flex:1;height:1px;background:#e5e7eb;"></div>
            <span style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:1px;white-space:nowrap;">
                📚 <?php echo esc_html($hist['categorie']); ?>
            </span>
            <div style="flex:1;height:1px;background:#e5e7eb;"></div>
        </div>
        <div style="overflow-x:auto;padding-bottom:4px;">
        <div style="display:flex;flex-wrap:nowrap;align-items:flex-start;gap:0;min-width:max-content;padding:4px 2px 10px;opacity:0.65;">
            <?php foreach ( $steps_hist as $hidx => $hs ) :
                $hcol  = sp_belt_color($hs['grade']);
                $hlabel = mb_strlen($hs['grade']) > 10 ? mb_substr($hs['grade'],0,9).'…' : $hs['grade'];
                // Bicolore POOM
                $is_poom = (strtoupper(trim($hs['grade'])) === 'POOM');
                if ($is_poom) {
                    $hbg_style = 'background:linear-gradient(to right,#1a1a1a 50%,#dc2626 50%)';
                } elseif (!empty($hcol['bicolor']) && !empty($hcol['bg2'])) {
                    $hbg_style = "background:linear-gradient(to right,{$hcol['bg']} 50%,{$hcol['bg2']} 50%)";
                } else {
                    $hbg_style = "background:{$hcol['bg']}";
                }
                if ($hidx > 0) : ?>
                <div style="width:14px;height:2px;background:#d1d5db;margin-top:14px;flex-shrink:0;"></div>
                <?php endif; ?>
                <div style="display:flex;flex-direction:column;align-items:center;gap:3px;min-width:48px;">
                    <div style="width:30px;height:30px;border-radius:50%;
                                <?php echo $hbg_style; ?>;
                                border:2px solid <?php echo $hcol['border']; ?>;
                                display:flex;align-items:center;justify-content:center;
                                font-size:10px;color:<?php echo $is_poom ? '#fff' : $hcol['text']; ?>;
                                filter:grayscale(20%) brightness(0.9);">
                        ✓
                    </div>
                    <div style="font-size:9px;font-weight:600;color:#6b7280;text-align:center;line-height:1.2;max-width:48px;">
                        <?php echo esc_html($hlabel); ?>
                    </div>
                    <?php if ( !empty($hs['date']) ) : ?>
                    <div style="font-size:8px;color:#10b981;font-weight:700;text-align:center;">
                        <?php echo date('m/Y', strtotime($hs['date'])); ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Lecteur vidéo flottant -->
<div id="spp-videoplayer-<?php echo $uid; ?>" class="spp-video-player"
     onclick="if(event.target===this)sppCloseVideo('<?php echo $uid; ?>')">
    <div class="spp-video-player-inner">
        <button class="spp-video-player-close" onclick="sppCloseVideo('<?php echo $uid; ?>')">✕</button>
        <div id="spp-vp-title-<?php echo $uid; ?>" class="spp-video-player-title"></div>
        <iframe id="spp-vp-iframe-<?php echo $uid; ?>" src="" allowfullscreen
                allow="autoplay; encrypted-media"></iframe>
    </div>
</div>

<script>
(function(){
    // Données des steps pour le JS (sur window pour accès global)
    window['SPP_STEPS_<?php echo $uid; ?>'] = <?php
        $js_steps = array_map(function($s) {
            return array(
                'grade'    => $s['grade'],
                'statut'   => $s['statut'],
                'date'     => $s['date'] ? date('d/m/Y', strtotime($s['date'])) : null,
                'examen'   => $s['examen'],
                'epreuves' => array_map(function($e){ return $e->nom; }, $s['epreuves']),
            );
        }, $steps);
        echo json_encode($js_steps);
    ?>;

    // Animer la barre au chargement
    window.addEventListener('load', function(){
        setTimeout(function(){
            var fill = document.getElementById('spp-fill-<?php echo $uid; ?>');
            if (fill) fill.style.width = '<?php echo $pct; ?>%';
        }, 200);
    });

    // Scroll automatique vers le grade actuel dans la timeline
    setTimeout(function(){
        var tl = document.getElementById('spp-tl-<?php echo $uid; ?>');
        if (!tl) return;
        var actuel = tl.querySelector('.spp-step.actuel');
        if (actuel) {
            var wrap = tl.parentElement;
            var offset = actuel.offsetLeft - wrap.offsetWidth / 2 + actuel.offsetWidth / 2;
            wrap.scrollLeft = Math.max(0, offset);
        }
    }, 300);
})();

function sppCloseModal(uid) {
    var m = document.getElementById('spp-modal-' + uid);
    if (m) m.style.display = 'none';
}
// Fermer modal en cliquant le fond
document.addEventListener('click', function(e){
    var modals = document.querySelectorAll('[id^="spp-modal-"]');
    modals.forEach(function(m){
        if (e.target === m) m.style.display = 'none';
    });
});

function sppToggle(uid, idx) {
    var steps  = window['SPP_STEPS_' + uid];
    var detail = document.getElementById('spp-detail-' + uid);
    var title  = document.getElementById('spp-detail-title-' + uid);
    var cont   = document.getElementById('spp-detail-content-' + uid);
    if (!steps || !detail) return;

    var step = steps[idx];
    var modal     = document.getElementById('spp-modal-' + uid);
    var modalTitle= document.getElementById('spp-modal-title-' + uid);
    var modalSt   = document.getElementById('spp-modal-statut-' + uid);
    var modalBody = document.getElementById('spp-modal-body-' + uid);
    var modalBelt = document.getElementById('spp-modal-belt-' + uid);
    if (!modal) return;

    // En-tête modal
    var statusIcon = step.statut === 'passe' ? '✅ ' : (step.statut === 'actuel' ? '⭐ ' : '🔜 ');
    var statusTxt  = step.statut === 'passe' ? 'Grade obtenu' : (step.statut === 'actuel' ? 'Votre grade actuel' : 'Grade à venir');
    modalTitle.textContent = step.grade;
    modalSt.textContent    = statusIcon + statusTxt + (step.date ? ' — obtenu le ' + step.date : '');

    // Contenu de base (épreuves connues)
    var baseHtml = '';
    if (step.date) {
        baseHtml += '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#15803d;">'
            + '📅 Obtenu le ' + step.date + (step.examen ? ' — ' + step.examen : '') + '</div>';
    }
    if (step.epreuves && step.epreuves.length) {
        baseHtml += '<div style="font-size:13px;font-weight:700;color:#374151;margin-bottom:8px;">📋 Épreuves requises :</div>'
            + '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px;">';
        step.epreuves.forEach(function(ep) {
            baseHtml += '<div style="background:#f3f4f6;border-radius:6px;padding:5px 12px;font-size:12px;font-weight:600;color:#374151;">📋 ' + ep + '</div>';
        });
        baseHtml += '</div>';
    }
    baseHtml += '<div id="spp-modal-extra-' + uid + '"><div style="color:#9ca3af;font-size:12px;">Chargement des ressources…</div></div>';
    modalBody.innerHTML = baseHtml;
    modal.style.display = 'flex';

    // Charger le contenu pédagogique depuis le serveur
    // ajaxurl passé directement depuis PHP — fonctionne en frontend public (page token)
    var ajaxurl = '<?php echo esc_js( admin_url("admin-ajax.php") ); ?>';
    var nonce   = '';  // get_grade_contenu est public, pas de nonce requis
    fetch(ajaxurl, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=sp_jury_get_grade_contenu&grade_actuel=' + encodeURIComponent(step.grade)
    }).then(function(r){ return r.json(); }).then(function(r){
        var extra = document.getElementById('spp-modal-extra-' + uid);
        if (!extra) return;
        if (!r.success) { extra.innerHTML = ''; return; }
        var d = r.data;
        var html = '';

        if (d.description) {
            html += '<div class="spp-pedagogique-desc">' + d.description + '</div>';
        }

        if (d.liens && d.liens.length) {
            // Séparer vidéos et autres ressources
            var videos = d.liens.filter(function(l){ return l.type === 'video'; });
            var autres = d.liens.filter(function(l){ return l.type !== 'video'; });

            if (videos.length) {
                html += '<div class="spp-ressources-titre">🎬 Vidéos</div>';
                html += '<div class="spp-videos-grid">';
                videos.forEach(function(l) {
                    var label = l.label || 'Voir la vidéo';
                    // Détecter YouTube
                    var ytMatch = l.url.match(/(?:v=|youtu\.be\/)([^&?\/]+)/);
                    if (ytMatch) {
                        // Lien qui ouvre le lecteur vidéo flottant
                        html += '<button class="spp-video-btn" onclick="sppOpenVideo('' + uid + '','' + encodeURIComponent(l.url) + '','' + encodeURIComponent(label) + '')">'
                              + '<span class="spp-video-thumb" style="background-image:url(https://img.youtube.com/vi/' + ytMatch[1] + '/mqdefault.jpg)"></span>'
                              + '<span class="spp-video-play">▶</span>'
                              + '<span class="spp-video-label">' + label + '</span>'
                              + '</button>';
                    } else {
                        html += '<a href="' + l.url + '" target="_blank" rel="noopener" class="spp-video-btn">'
                              + '<span class="spp-video-icon">🎬</span>'
                              + '<span class="spp-video-label">' + label + '</span>'
                              + '</a>';
                    }
                });
                html += '</div>';
            }

            if (autres.length) {
                html += '<div class="spp-ressources-titre">📎 Ressources</div>';
                html += '<div class="spp-ressources-liste">';
                autres.forEach(function(l) {
                    var icon = l.type==='image'?'🖼️':l.type==='pdf'?'📄':'🔗';
                    var label = l.label || l.url;
                    if (l.type === 'image') {
                        html += '<div class="spp-ressource-image">'
                              + '<div class="spp-ressource-label">' + icon + ' ' + label + '</div>'
                              + '<img src="' + l.url + '" alt="' + label + '">'
                              + '</div>';
                    } else {
                        html += '<a href="' + l.url + '" target="_blank" rel="noopener" class="spp-ressource-lien">'
                              + '<span>' + icon + ' ' + label + '</span>'
                              + '<span class="spp-ressource-fleche">↗</span>'
                              + '</a>';
                    }
                });
                html += '</div>';
            }
        }

        extra.innerHTML = html || '<p class="spp-aucune-ressource">Aucune ressource configurée pour ce grade.</p>';
    }).catch(function(){
        var extra = document.getElementById('spp-modal-extra-' + uid);
        if (extra) extra.innerHTML = '<p class="spp-aucune-ressource">Impossible de charger les ressources.</p>';
    });
}

function sppClose(uid) {
    var detail = document.getElementById('spp-detail-' + uid);
    if (detail) { detail.style.display = 'none'; detail.dataset.open = ''; }
}

function sppOpenVideo(uid, encodedUrl, encodedLabel) {
    var url   = decodeURIComponent(encodedUrl);
    var label = decodeURIComponent(encodedLabel);
    var player = document.getElementById('spp-videoplayer-' + uid);
    var iframe = document.getElementById('spp-vp-iframe-' + uid);
    var title  = document.getElementById('spp-vp-title-' + uid);
    if (!player || !iframe) return;

    // Convertir URL YouTube en embed
    var ytMatch = url.match(/(?:v=|youtu\.be\/)([^&?\/]+)/);
    var embedUrl = ytMatch
        ? 'https://www.youtube.com/embed/' + ytMatch[1] + '?autoplay=1&rel=0'
        : url;

    title.textContent = label;
    iframe.src = embedUrl;
    player.style.display = 'flex';
}

function sppCloseVideo(uid) {
    var player = document.getElementById('spp-videoplayer-' + uid);
    var iframe = document.getElementById('spp-vp-iframe-' + uid);
    if (iframe) iframe.src = '';  // Stopper la lecture
    if (player) player.style.display = 'none';
}
</script>

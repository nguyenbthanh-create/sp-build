<?php
/**
 * Template : Vue agrégée table (token URL ou admin)
 *
 * Variables disponibles depuis render_table_page() :
 *   $event_id    int
 *   $table_id    int
 *   $session     stdClass
 *   $table       stdClass|null
 *   $juges       array    — juges de cette table
 *   $candidats   array    — élèves affectés
 *   $epreuves    array    — épreuves applicables
 *   $notes_idx   array    — [eleve_id][epreuve_id][juge_id] = note
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$note_min = intval( $session->note_min );
$note_max = intval( $session->note_max );
$seuil    = floatval( $session->seuil_admission );
$club     = esc_html( get_option('blogname','Club') );
$label_t  = $table ? esc_html($table->label ?: 'Table '.$table->numero) : 'Table';

/**
 * Moyenne des notes d'un élève pour une épreuve donnée (sur tous les juges).
 */
function sp_jury_avg( $notes_ep ) {
    if ( empty($notes_ep) ) return null;
    $notes = array_values($notes_ep);
    return array_sum($notes) / count($notes);
}
?>
<style>
:root {
    --jury-green:  #16a34a;
    --jury-red:    #dc2626;
    --jury-blue:   #2563eb;
    --jury-gray:   #6b7280;
    --jury-border: #e5e7eb;
    --jury-bg:     #f9fafb;
}
.sp-jury-wrap           { max-width:1100px; margin:28px auto; padding:0 16px; font-family:system-ui,sans-serif; }
.sp-jury-tbl-header     { background:#fff; border:1px solid var(--jury-border); border-radius:10px; padding:20px 24px; margin-bottom:18px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
.sp-jury-tbl-header h1  { margin:0; font-size:20px; flex:1; }
.sp-jury-tbl-meta       { color:var(--jury-gray); font-size:13px; margin-top:4px; }
.sp-jury-juges-list     { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
.sp-jury-juge-chip      { font-size:12px; padding:3px 10px; border-radius:20px; background:#f0f9ff; color:#0369a1; border:1px solid #bae6fd; }
.sp-jury-badge-statut   { font-size:12px; font-weight:700; padding:4px 10px; border-radius:20px; text-transform:uppercase; }
.sp-jury-badge-prep     { background:#fef9c3; color:#854d0e; }
.sp-jury-badge-encours  { background:#dcfce7; color:#14532d; }
.sp-jury-badge-termine  { background:#fee2e2; color:#7f1d1d; }
.sp-jury-grid-wrap      { overflow-x:auto; }
.sp-jury-grid           { width:100%; border-collapse:collapse; font-size:13px; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.07); }
.sp-jury-grid th        { background:var(--jury-bg); border:1px solid var(--jury-border); padding:8px 12px; font-weight:700; text-align:center; white-space:nowrap; font-size:12px; }
.sp-jury-grid th.left   { text-align:left; }
.sp-jury-grid td        { border:1px solid var(--jury-border); padding:7px 10px; vertical-align:middle; }
.sp-jury-grid tr:hover td { background:#fafafa; }
.sp-jury-eleve-cell     { min-width:160px; font-weight:600; }
.sp-jury-note-cell      { text-align:center; min-width:52px; }
.sp-jury-avg-cell       { text-align:center; font-weight:700; min-width:52px; }
.sp-jury-score-cell     { text-align:center; font-weight:800; min-width:60px; font-size:14px; }
.sp-jury-admis          { background:#dcfce7 !important; color:var(--jury-green); }
.sp-jury-ajourne        { background:#fee2e2 !important; color:var(--jury-red); }
.sp-jury-absent-row td  { opacity:.5; font-style:italic; }
.sp-jury-tag            { display:inline-block; font-size:10px; padding:1px 7px; border-radius:10px; background:#e0f2fe; color:#0369a1; font-weight:600; margin-left:5px; }
.sp-jury-tag-grade      { background:#f3e8ff; color:#7e22ce; }
.sp-jury-seuil-note     { font-size:10px; color:var(--jury-gray); font-weight:400; display:block; }
.sp-jury-empty          { text-align:center; padding:40px 0; color:var(--jury-gray); }
.sp-jury-poll-bar       { height:3px; background:var(--jury-border); border-radius:2px; margin-bottom:18px; overflow:hidden; }
.sp-jury-poll-fill      { height:100%; background:var(--jury-blue); border-radius:2px; }
.sp-jury-footer         { text-align:center; color:var(--jury-gray); font-size:12px; margin-top:24px; }
.sp-jury-sub            { font-size:11px; color:var(--jury-gray); font-weight:400; display:block; }
.sp-jury-legend         { display:flex; gap:12px; margin-bottom:14px; flex-wrap:wrap; font-size:12px; align-items:center; color:var(--jury-gray); }
.sp-jury-legend-item    { display:flex; align-items:center; gap:5px; }
.sp-jury-legend-sq      { width:14px; height:14px; border-radius:3px; }
</style>

<div class="sp-jury-wrap" id="sp-jury-table-app">

    <!-- En-tête -->
    <div class="sp-jury-tbl-header">
        <div style="flex:1 1 auto;">
            <h1>📋 <?php echo $label_t; ?></h1>
            <div class="sp-jury-tbl-meta">
                <?php echo esc_html( $session->mode === 'par_categorie' ? 'Mode par catégorie' : 'Mode par épreuve' ); ?>
                &nbsp;·&nbsp; Notation <?php echo $note_min; ?>–<?php echo $note_max; ?>
                &nbsp;·&nbsp; Seuil admission : <strong><?php echo $seuil; ?></strong>
            </div>
            <?php if ( ! empty($juges) ): ?>
            <div class="sp-jury-juges-list">
                <?php foreach ($juges as $j): ?>
                <span class="sp-jury-juge-chip">⚖️ <?php echo esc_html($j->prenom . ' ' . $j->nom); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        $badge_class = match($session->statut) { 'en_cours'=>'sp-jury-badge-encours','termine'=>'sp-jury-badge-termine',default=>'sp-jury-badge-prep' };
        $badge_label = match($session->statut) { 'en_cours'=>'🟢 En cours','termine'=>'🔴 Terminé',default=>'🟡 Préparation' };
        ?>
        <span class="sp-jury-badge-statut <?php echo $badge_class; ?>"><?php echo $badge_label; ?></span>
    </div>

    <!-- Barre de polling -->
    <div class="sp-jury-poll-bar"><div class="sp-jury-poll-fill" id="sp-tbl-poll-fill" style="width:0%"></div></div>

    <!-- Légende -->
    <div class="sp-jury-legend">
        <span>Légende :</span>
        <span class="sp-jury-legend-item"><span class="sp-jury-legend-sq" style="background:#dcfce7;border:1px solid #86efac;"></span> Admis (≥ seuil)</span>
        <span class="sp-jury-legend-item"><span class="sp-jury-legend-sq" style="background:#fee2e2;border:1px solid #fca5a5;"></span> Ajourné</span>
        <span class="sp-jury-legend-item"><span class="sp-jury-legend-sq" style="background:#f3f4f6;border:1px solid #d1d5db;"></span> En attente</span>
    </div>

    <?php if ( empty($candidats) ): ?>
    <div class="sp-jury-empty">Aucun candidat affecté à cette table.</div>
    <?php else: ?>
    <div class="sp-jury-grid-wrap">
    <table class="sp-jury-grid" id="sp-tbl-grid">
        <thead>
        <tr>
            <th class="left" rowspan="2">Candidat</th>
            <?php foreach ($epreuves as $ep): ?>
            <th colspan="<?php echo max(1, count($juges)); ?>">
                <?php echo esc_html($ep->nom); ?>
                <span class="sp-jury-seuil-note"><?php echo count($juges); ?> juge<?php echo count($juges)>1?'s':''; ?></span>
            </th>
            <th rowspan="2" style="min-width:55px;">Moy.<br><span class="sp-jury-sub"><?php echo esc_html($ep->nom); ?></span></th>
            <?php endforeach; ?>
            <th rowspan="2" style="min-width:65px;">Score total</th>
            <th rowspan="2" style="min-width:80px;">Verdict</th>
        </tr>
        <tr>
            <?php foreach ($epreuves as $ep): ?>
            <?php foreach ($juges as $juge): ?>
            <th title="<?php echo esc_attr($juge->prenom.' '.$juge->nom); ?>">
                <?php echo esc_html(mb_substr($juge->prenom,0,1).'.'.mb_substr($juge->nom,0,1).'.'); ?>
            </th>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody id="sp-tbl-body">
        <?php
        // Grouper par catégorie_age
        $groupes = array();
        foreach ($candidats as $c) {
            $groupes[$c->categorie_age ?? ''][] = $c;
        }
        foreach ($groupes as $cat => $membres):
            if (count($groupes) > 1):
        ?>
        <tr>
            <td colspan="<?php echo 1 + count($epreuves) * (count($juges)+1) + 2; ?>"
                style="background:var(--jury-bg);font-weight:700;font-size:12px;padding:6px 12px;color:var(--jury-gray);">
                <?php echo esc_html($cat ?: 'Sans catégorie'); ?>
            </td>
        </tr>
        <?php endif;
        foreach ($membres as $c):
            $eid    = intval($c->id);
            $absent = ! intval($c->present ?? 1);

            // Calcul score total (moyenne par épreuve × nb épreuves)
            $score_total = 0;
            $has_any     = false;
            $avgs        = array();
            foreach ($epreuves as $ep) {
                $epid    = intval($ep->id);
                $ep_notes = $notes_idx[$eid][$epid] ?? array();
                $avg     = sp_jury_avg($ep_notes);
                $avgs[$epid] = $avg;
                if ($avg !== null) { $score_total += $avg; $has_any = true; }
            }

            $score_cls = '';
            $verdict   = '—';
            if ($has_any) {
                if ($score_total >= $seuil) { $score_cls = 'sp-jury-admis'; $verdict = '✅ Admis'; }
                else                        { $score_cls = 'sp-jury-ajourne'; $verdict = '❌ Ajourné'; }
            }
        ?>
        <tr class="<?php echo $absent ? 'sp-jury-absent-row' : ''; ?>">
            <td class="sp-jury-eleve-cell">
                <?php echo esc_html($c->prenom . ' ' . mb_strtoupper($c->nom)); ?>
                <?php if ($c->grade): ?><span class="sp-jury-tag sp-jury-tag-grade"><?php echo esc_html($c->grade); ?></span><?php endif; ?>
                <?php if ($absent): ?><span class="sp-jury-tag">Absent</span><?php endif; ?>
            </td>
            <?php foreach ($epreuves as $ep):
                $epid    = intval($ep->id);
                $ep_notes = $notes_idx[$eid][$epid] ?? array();
            ?>
            <?php foreach ($juges as $juge):
                $jid  = intval($juge->id);
                $note = $ep_notes[$jid] ?? null;
            ?>
            <td class="sp-jury-note-cell">
                <span id="n-<?php echo $eid;?>-<?php echo $epid;?>-<?php echo $jid;?>">
                    <?php echo $note !== null ? number_format($note, 0) : '<span style="color:#d1d5db">·</span>'; ?>
                </span>
            </td>
            <?php endforeach; ?>
            <td class="sp-jury-avg-cell">
                <span id="avg-<?php echo $eid;?>-<?php echo $epid;?>">
                    <?php echo $avgs[$epid] !== null ? number_format($avgs[$epid],1) : '—'; ?>
                </span>
            </td>
            <?php endforeach; ?>
            <td class="sp-jury-score-cell <?php echo $score_cls; ?>" id="score-<?php echo $eid; ?>">
                <?php echo $has_any ? number_format($score_total,1) : '—'; ?>
            </td>
            <td class="sp-jury-score-cell <?php echo $score_cls; ?>" id="verdict-<?php echo $eid; ?>">
                <?php echo $verdict; ?>
            </td>
        </tr>
        <?php endforeach; endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <div class="sp-jury-footer"><?php echo $club; ?> — Vue table (lecture seule) · Actualisation automatique</div>
</div>

<script>
(function($){
    var POLL_MS  = (typeof SpJury !== 'undefined') ? SpJury.poll_ms : 15000;
    var TOKEN    = (typeof SpJury !== 'undefined') ? SpJury.jury_token : '';
    var AJAXURL  = (typeof SpJury !== 'undefined') ? SpJury.ajaxurl : '';
    var EVENT_ID = (typeof SpJury !== 'undefined') ? SpJury.event_id : 0;
    var TABLE_ID = (typeof SpJury !== 'undefined') ? SpJury.table_id : 0;
    var SEUIL    = <?php echo $seuil; ?>;
    var NOTE_MAX = <?php echo $note_max; ?>;
    var NB_EP    = <?php echo count($epreuves); ?>;
    var JUGES    = <?php echo json_encode(array_map(function($j){ return intval($j->id); }, $juges)); ?>;

    var pollStart = Date.now();

    function animatePoll() {
        var elapsed = Date.now() - pollStart;
        var pct = Math.min(100, elapsed / POLL_MS * 100);
        $('#sp-tbl-poll-fill').css('width', pct + '%');
        if (elapsed < POLL_MS) requestAnimationFrame(animatePoll);
    }

    function updateFromData(data) {
        var notes  = data.notes  || {};
        var scores = data.scores || {};

        $.each(notes, function(eid, epMap){
            var totalScore = 0, hasAny = false;
            $.each(epMap, function(epid, jugeMap){
                var sum = 0, cnt = 0;
                $.each(jugeMap, function(jid, note){
                    $('#n-'+eid+'-'+epid+'-'+jid).text(Math.round(parseFloat(note)));
                    sum += parseFloat(note); cnt++;
                });
                var avg = cnt ? sum/cnt : null;
                $('#avg-'+eid+'-'+epid).text(avg !== null ? avg.toFixed(1) : '—');
                if (avg !== null) { totalScore += avg; hasAny = true; }
            });
            var $score   = $('#score-'+eid);
            var $verdict = $('#verdict-'+eid);
            if (hasAny) {
                $score.text(totalScore.toFixed(1));
                $verdict.text(totalScore >= SEUIL ? '✅ Admis' : '❌ Ajourné');
                var cls = totalScore >= SEUIL ? 'sp-jury-admis' : 'sp-jury-ajourne';
                $score.attr('class','sp-jury-score-cell ' + cls);
                $verdict.attr('class','sp-jury-score-cell ' + cls);
            }
        });
    }

    function poll() {
        pollStart = Date.now();
        animatePoll();
        if (!TOKEN && !EVENT_ID) return;
        var params = {
            action:  'sp_jury_get_table_state',
            event_id: EVENT_ID,
            table_id: TABLE_ID,
        };
        if (TOKEN) params.jury_token = TOKEN;
        $.post(AJAXURL, params, function(r){
            if (r && r.success) updateFromData(r.data);
        });
        setTimeout(poll, POLL_MS);
    }
    setTimeout(poll, POLL_MS);
    animatePoll();
})(jQuery);
</script>

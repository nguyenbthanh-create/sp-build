<?php
/**
 * Template : Interface juge (accès par token URL)
 *
 * Variables disponibles depuis render_juge_page() :
 *   $juge        stdClass  — enregistrement exam_juges
 *   $session     stdClass  — exam_sessions
 *   $table       stdClass|null
 *   $candidats   array     — élèves affectés à cette table
 *   $epreuves    array     — épreuves applicables
 *   $notes_idx   array     — [eleve_id][epreuve_id] = note (float)
 *   $label_table string
 *   $statut      string    preparation|en_cours|termine
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$note_min   = intval( $session->note_min );
$note_max   = intval( $session->note_max );
$seuil      = floatval( $session->seuil_admission );
$locked     = $statut === 'termine';
$club       = esc_html( get_option('blogname','Club') );

// Calcul scores par élève pour cet juge
$scores_juge = array();
foreach ( $candidats as $c ) {
    $total = 0;
    $has   = false;
    foreach ( $epreuves as $ep ) {
        if ( isset($notes_idx[ intval($c->id) ][ intval($ep->id) ]) ) {
            $total += floatval($notes_idx[ intval($c->id) ][ intval($ep->id) ]);
            $has    = true;
        }
    }
    $scores_juge[ intval($c->id) ] = $has ? $total : null;
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
.sp-jury-wrap          { max-width:900px; margin:28px auto; padding:0 16px; font-family:system-ui,sans-serif; }
.sp-jury-header        { background:#fff; border:1px solid var(--jury-border); border-radius:10px; padding:20px 24px; margin-bottom:20px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
.sp-jury-badge-statut  { font-size:12px; font-weight:700; padding:4px 10px; border-radius:20px; text-transform:uppercase; letter-spacing:.5px; }
.sp-jury-badge-prep    { background:#fef9c3; color:#854d0e; }
.sp-jury-badge-encours { background:#dcfce7; color:#14532d; }
.sp-jury-badge-termine { background:#fee2e2; color:#7f1d1d; }
.sp-jury-header h1     { margin:0; font-size:20px; flex:1; }
.sp-jury-meta          { color:var(--jury-gray); font-size:14px; margin:4px 0 0; }
.sp-jury-alert         { padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:14px; }
.sp-jury-alert-warn    { background:#fef9c3; border:1px solid #fbbf24; color:#854d0e; }
.sp-jury-alert-locked  { background:#fee2e2; border:1px solid #f87171; color:#7f1d1d; }
.sp-jury-card          { background:#fff; border:1px solid var(--jury-border); border-radius:10px; margin-bottom:14px; overflow:hidden; }
.sp-jury-card-header   { padding:12px 16px; background:var(--jury-bg); border-bottom:1px solid var(--jury-border); display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.sp-jury-eleve-nom     { font-weight:700; font-size:15px; flex:1; }
.sp-jury-tag           { font-size:11px; padding:2px 8px; border-radius:12px; background:#e0f2fe; color:#0369a1; font-weight:600; }
.sp-jury-tag-grade     { background:#f3e8ff; color:#7e22ce; }
.sp-jury-score-badge   { font-size:13px; font-weight:700; padding:3px 10px; border-radius:20px; }
.sp-jury-score-admis   { background:#dcfce7; color:var(--jury-green); }
.sp-jury-score-ajourne { background:#fee2e2; color:var(--jury-red); }
.sp-jury-score-neutre  { background:#f3f4f6; color:var(--jury-gray); }
.sp-jury-epreuves      { padding:12px 16px; display:flex; flex-direction:column; gap:10px; }
.sp-jury-ep-row        { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.sp-jury-ep-nom        { flex:1; font-size:14px; color:#374151; min-width:120px; }
.sp-jury-btn-group     { display:flex; align-items:center; gap:6px; }
.sp-jury-btn           { border:none; border-radius:6px; padding:6px 14px; font-size:15px; font-weight:700; cursor:pointer; transition:opacity .15s; line-height:1; }
.sp-jury-btn:disabled  { opacity:.4; cursor:not-allowed; }
.sp-jury-btn-minus     { background:#fee2e2; color:var(--jury-red); }
.sp-jury-btn-plus      { background:#dcfce7; color:var(--jury-green); }
.sp-jury-note-val      { min-width:42px; text-align:center; font-size:16px; font-weight:800; padding:4px 8px; border-radius:6px; background:var(--jury-bg); border:1px solid var(--jury-border); }
.sp-jury-sync          { font-size:11px; color:var(--jury-gray); margin-left:6px; }
.sp-jury-sync-ok       { color:var(--jury-green); }
.sp-jury-sync-err      { color:var(--jury-red); }
.sp-jury-poll-bar      { height:3px; background:var(--jury-border); border-radius:2px; margin-bottom:18px; overflow:hidden; }
.sp-jury-poll-fill     { height:100%; background:var(--jury-blue); border-radius:2px; transition:width linear; }
.sp-jury-footer        { text-align:center; color:var(--jury-gray); font-size:12px; margin-top:24px; }
.sp-jury-absent-tag    { font-size:11px; padding:2px 8px; border-radius:12px; background:#f3f4f6; color:#9ca3af; }
</style>

<div class="sp-jury-wrap" id="sp-jury-app">

    <!-- En-tête -->
    <div class="sp-jury-header">
        <div style="flex:1 1 auto;">
            <h1>⚖️ Interface juge</h1>
            <div class="sp-jury-meta">
                <?php echo esc_html( $juge->prenom . ' ' . $juge->nom ); ?>
                &nbsp;·&nbsp; <?php echo esc_html( $label_table ); ?>
                &nbsp;·&nbsp; <?php echo esc_html( $session->mode === 'par_categorie' ? 'Mode par catégorie' : 'Mode par épreuve' ); ?>
            </div>
        </div>
        <?php
        $badge_class = match($statut) {
            'en_cours' => 'sp-jury-badge-encours',
            'termine'  => 'sp-jury-badge-termine',
            default    => 'sp-jury-badge-prep',
        };
        $badge_label = match($statut) {
            'en_cours' => '🟢 En cours',
            'termine'  => '🔴 Terminé',
            default    => '🟡 Préparation',
        };
        ?>
        <span class="sp-jury-badge-statut <?php echo $badge_class; ?>"><?php echo $badge_label; ?></span>
        <div>
            <div style="font-size:12px;color:var(--jury-gray);">Notation <?php echo $note_min; ?>–<?php echo $note_max; ?></div>
            <div style="font-size:12px;color:var(--jury-gray);">Seuil admission : <?php echo $seuil; ?></div>
        </div>
    </div>

    <?php if ( $statut === 'preparation' ): ?>
    <div class="sp-jury-alert sp-jury-alert-warn">⏳ La session est en préparation — la saisie sera activée dès le démarrage.</div>
    <?php elseif ( $locked ): ?>
    <div class="sp-jury-alert sp-jury-alert-locked">🔒 La session est terminée. Les notes sont en lecture seule.</div>
    <?php endif; ?>

    <!-- Barre de polling -->
    <div class="sp-jury-poll-bar"><div class="sp-jury-poll-fill" id="sp-poll-fill" style="width:0%"></div></div>

    <?php if ( empty($candidats) ): ?>
    <div class="sp-jury-alert sp-jury-alert-warn">Aucun candidat affecté à cette table pour le moment.</div>
    <?php else: ?>

    <!-- Candidats -->
    <div id="sp-jury-candidats">
    <?php foreach ( $candidats as $c ):
        $eid       = intval($c->id);
        $absent    = ! intval($c->present ?? 1);
        $score     = $scores_juge[$eid] ?? null;
        $score_cls = '';
        if ( $score !== null ) {
            $score_cls = $score >= $seuil ? 'sp-jury-score-admis' : 'sp-jury-score-ajourne';
        } else {
            $score_cls = 'sp-jury-score-neutre';
        }
    ?>
    <div class="sp-jury-card" data-eleve="<?php echo $eid; ?>" <?php echo $absent ? 'style="opacity:.55"' : ''; ?>>
        <div class="sp-jury-card-header">
            <span class="sp-jury-eleve-nom">
                <?php echo esc_html( $c->prenom . ' ' . mb_strtoupper($c->nom) ); ?>
            </span>
            <?php if ( $c->categorie_age ): ?>
            <span class="sp-jury-tag"><?php echo esc_html($c->categorie_age); ?></span>
            <?php endif; ?>
            <?php if ( $c->grade ): ?>
            <span class="sp-jury-tag sp-jury-tag-grade"><?php echo esc_html($c->grade); ?></span>
            <?php endif; ?>
            <?php if ( $absent ): ?>
            <span class="sp-jury-absent-tag">Absent</span>
            <?php endif; ?>
            <span class="sp-jury-score-badge <?php echo $score_cls; ?>">
                <?php echo $score !== null ? number_format($score, 1) : '—'; ?> / <?php echo count($epreuves) * $note_max; ?>
            </span>
        </div>

        <?php if ( ! $absent ): ?>
        <div class="sp-jury-epreuves">
        <?php foreach ( $epreuves as $ep ):
            $epid  = intval($ep->id);
            $note  = $notes_idx[$eid][$epid] ?? $note_min;
            $at_min = floatval($note) <= $note_min;
            $at_max = floatval($note) >= $note_max;
        ?>
        <div class="sp-jury-ep-row" data-ep="<?php echo $epid; ?>">
            <span class="sp-jury-ep-nom"><?php echo esc_html($ep->nom); ?></span>
            <div class="sp-jury-btn-group">
                <button class="sp-jury-btn sp-jury-btn-minus"
                        data-eleve="<?php echo $eid; ?>"
                        data-ep="<?php echo $epid; ?>"
                        data-delta="-1"
                        <?php echo ( $locked || $at_min ) ? 'disabled' : ''; ?>>
                    −
                </button>
                <span class="sp-jury-note-val" id="note-<?php echo $eid; ?>-<?php echo $epid; ?>">
                    <?php echo number_format(floatval($note), 0); ?>
                </span>
                <button class="sp-jury-btn sp-jury-btn-plus"
                        data-eleve="<?php echo $eid; ?>"
                        data-ep="<?php echo $epid; ?>"
                        data-delta="1"
                        <?php echo ( $locked || $at_max ) ? 'disabled' : ''; ?>>
                    +
                </button>
                <span class="sp-jury-sync" id="sync-<?php echo $eid; ?>-<?php echo $epid; ?>"></span>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="sp-jury-footer"><?php echo $club; ?> — Interface juge · Synchronisation automatique</div>
</div>

<script>
(function($){
    var POLL_MS   = (typeof SpJury !== 'undefined') ? SpJury.poll_ms : 20000;
    var TOKEN     = (typeof SpJury !== 'undefined') ? SpJury.jury_token : '';
    var AJAXURL   = (typeof SpJury !== 'undefined') ? SpJury.ajaxurl : '';
    var NOTE_MIN  = <?php echo $note_min; ?>;
    var NOTE_MAX  = <?php echo $note_max; ?>;
    var SEUIL     = <?php echo $seuil; ?>;
    var NB_EP     = <?php echo count($epreuves); ?>;
    var LOCKED    = <?php echo $locked ? 'true' : 'false'; ?>;

    // State local : {eleve_id: {epreuve_id: note}}
    var localNotes = {};
    <?php foreach ( $candidats as $c ):
        $eid = intval($c->id);
        foreach ( $epreuves as $ep ):
            $epid = intval($ep->id);
            $n    = $notes_idx[$eid][$epid] ?? $note_min;
    ?>
    if (!localNotes[<?php echo $eid;?>]) localNotes[<?php echo $eid;?>] = {};
    localNotes[<?php echo $eid;?>][<?php echo $epid;?>] = <?php echo floatval($n); ?>;
    <?php endforeach; endforeach; ?>

    function updateNoteDisplay(eid, epid, note) {
        $('#note-' + eid + '-' + epid).text(Math.round(note));
        // Recalc score badge
        var total = 0, has = false;
        $.each(localNotes[eid] || {}, function(_, v){ total += v; has = true; });
        var $badge = $('[data-eleve="'+eid+'"] .sp-jury-score-badge');
        if (has) {
            $badge.text(total.toFixed(1) + ' / ' + (NB_EP * NOTE_MAX));
            if (total >= SEUIL) {
                $badge.attr('class','sp-jury-score-badge sp-jury-score-admis');
            } else {
                $badge.attr('class','sp-jury-score-badge sp-jury-score-ajourne');
            }
        }
        // Mettre à jour disabled state des boutons
        $('[data-eleve="'+eid+'"][data-ep="'+epid+'"][data-delta="-1"]').prop('disabled', LOCKED || note <= NOTE_MIN);
        $('[data-eleve="'+eid+'"][data-ep="'+epid+'"][data-delta="1"]').prop('disabled',  LOCKED || note >= NOTE_MAX);
    }

    // Clic +/-
    $(document).on('click', '.sp-jury-btn[data-delta]', function(){
        if (LOCKED) return;
        var $btn    = $(this);
        var eid     = parseInt($btn.data('eleve'));
        var epid    = parseInt($btn.data('ep'));
        var delta   = parseInt($btn.data('delta'));

        if (!localNotes[eid]) localNotes[eid] = {};
        var cur   = localNotes[eid][epid] !== undefined ? localNotes[eid][epid] : NOTE_MIN;
        var next  = Math.max(NOTE_MIN, Math.min(NOTE_MAX, cur + delta));
        localNotes[eid][epid] = next;
        updateNoteDisplay(eid, epid, next);

        var $sync = $('#sync-' + eid + '-' + epid);
        $sync.text('⏳').removeClass('sp-jury-sync-ok sp-jury-sync-err');

        $.post(AJAXURL, {
            action:     'sp_jury_save_note',
            jury_token: TOKEN,
            eleve_id:   eid,
            epreuve_id: epid,
            delta:      delta
        }, function(r){
            if (r && r.success) {
                var srv = parseFloat(r.data.note);
                if (srv !== next) {
                    localNotes[eid][epid] = srv;
                    updateNoteDisplay(eid, epid, srv);
                }
                $sync.text('✓').addClass('sp-jury-sync-ok');
            } else {
                // Rollback
                localNotes[eid][epid] = cur;
                updateNoteDisplay(eid, epid, cur);
                $sync.text('✗').addClass('sp-jury-sync-err');
            }
            setTimeout(function(){ $sync.text(''); }, 2000);
        }).fail(function(){
            localNotes[eid][epid] = cur;
            updateNoteDisplay(eid, epid, cur);
            $sync.text('✗').addClass('sp-jury-sync-err');
            setTimeout(function(){ $sync.text(''); }, 2000);
        });
    });

    // Polling barre de progression
    var pollStart = Date.now();
    function animatePoll() {
        var elapsed = Date.now() - pollStart;
        var pct     = Math.min(100, elapsed / POLL_MS * 100);
        $('#sp-poll-fill').css('width', pct + '%');
        if (elapsed < POLL_MS) {
            requestAnimationFrame(animatePoll);
        }
    }
    function poll() {
        pollStart = Date.now();
        animatePoll();
        if (!TOKEN) return;
        $.post(AJAXURL, { action:'sp_jury_get_juge_state', jury_token: TOKEN }, function(r){
            if (!r || !r.success) return;
            var data = r.data;
            if (data.session && data.session.statut === 'termine' && !LOCKED) {
                location.reload(); return;
            }
            // Maj notes depuis serveur (merge : priorité état local si différent)
            $.each(data.notes || {}, function(eid, eps){
                $.each(eps, function(epid, note){
                    var n = parseFloat(note);
                    if (!localNotes[eid]) localNotes[eid] = {};
                    localNotes[eid][epid] = n;
                    updateNoteDisplay(parseInt(eid), parseInt(epid), n);
                });
            });
        });
        setTimeout(poll, POLL_MS);
    }
    setTimeout(poll, POLL_MS);
    animatePoll();

})(jQuery);
</script>

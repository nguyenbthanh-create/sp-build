<?php
/**
 * Template jury-aire.php — Passe 1.6
 * Variables : $aire, $session, $juges, $candidats, $epreuves, $event_id, $aire_id
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. On identifie le type d'aire en premier
$aire_type_verdict = $aire->type_verdict ?? 'seuil_fixe';
$is_zemita_aire    = ( $aire_type_verdict === 'zemita' );

// 2. On adapte le plafond par défaut (9999 pour le Zemita pour ne pas brider la frappe, 10 sinon)
$default_max = $is_zemita_aire ? 9999 : 10;

// 3. Assignation sécurisée
$note_min   = intval( $aire->note_min ?: ( $session->note_min ?: 0 ) );
$note_max   = intval( $aire->note_max ?: ( $session->note_max ?: $default_max ) );

$seuil      = floatval( $aire->seuil_admission ?? $session->seuil_admission );
$locked     = $session->statut === 'termine';
$not_started= $session->statut === 'preparation';
$label_aire = esc_html( $aire->label ?: 'Aire ' . $aire->numero );
$club       = esc_html( get_option('blogname','Club') );
$nb_candidats = count($candidats);
?>
<style>
:root{--j-ink:#0f172a;--j-muted:#64748b;--j-border:#e2e8f0;--j-bg:#f8fafc;
--j-blue:#2563eb;--j-green:#16a34a;--j-red:#dc2626;--j-orange:#ea580c;--j-white:#fff;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
#sp-jury-app{min-height:100vh;padding:0;}

/* ── Écran sélection juge ── */
#sj-select-screen{
    display:flex;flex-direction:column;align-items:center;justify-content:center;
    min-height:100vh;padding:24px;background:var(--j-ink);
}
#sj-select-screen h1{color:#fff;font-size:22px;margin-bottom:4px;text-align:center;}
#sj-select-screen .sub{color:#94a3b8;font-size:14px;margin-bottom:32px;text-align:center;}
.sj-juge-btn{
    width:100%;max-width:320px;padding:18px 24px;
    background:var(--j-white);border:none;border-radius:12px;
    font-size:17px;font-weight:700;cursor:pointer;margin-bottom:12px;
    display:flex;align-items:center;gap:14px;
    color:var(--j-ink);
    box-shadow:0 2px 8px rgba(0,0,0,.15);transition:transform .1s;
}
.sj-juge-btn:active{transform:scale(.97);}
.sj-juge-btn .avatar{
    width:44px;height:44px;border-radius:50%;background:var(--j-blue);
    color:#fff;font-size:16px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.sj-juge-btn .name{text-align:left;}
.sj-juge-btn .name small{display:block;font-size:12px;font-weight:400;color:var(--j-muted);}

/* ── Interface de saisie ── */
#sj-scoring-screen{display:none;flex-direction:column;min-height:100vh;}

/* Header sticky */
.sj-header{
    position:sticky;top:0;z-index:100;
    background:var(--j-white);border-bottom:2px solid var(--j-border);
    padding:12px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;
}
.sj-header-left{flex:1;}
.sj-header h2{font-size:16px;font-weight:800;margin:0;}
.sj-juge-chip{
    display:inline-flex;align-items:center;gap:6px;
    font-size:13px;font-weight:700;color:#fff;
    background:var(--j-blue);
    padding:3px 10px;border-radius:20px;margin-top:4px;
}
.sj-statut-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;text-transform:uppercase;}
.sj-statut-en_cours   {background:#dcfce7;color:#14532d;}
.sj-statut-termine    {background:#fee2e2;color:#7f1d1d;}
.sj-statut-preparation{background:#fef9c3;color:#854d0e;}

/* Navigation candidat */
.sj-candidat-nav{
    background:var(--j-blue);color:#fff;
    padding:12px 16px;display:flex;align-items:center;gap:12px;
}
.sj-candidat-nav .sj-name{flex:1;font-size:18px;font-weight:800;}
.sj-candidat-nav .sj-pos{font-size:12px;opacity:.8;}
.sj-nav-btn{
    background:rgba(255,255,255,.2);border:none;color:#fff;
    border-radius:8px;padding:8px 16px;font-size:14px;font-weight:700;cursor:pointer;
}
.sj-nav-btn:hover{background:rgba(255,255,255,.3);}

/* Zone notes */
.sj-notes-zone{flex:1;padding:16px;max-width:600px;margin:0 auto;width:100%;}
.sj-epreuve-block{
    background:var(--j-white);border:1px solid var(--j-border);border-radius:12px;
    padding:16px;margin-bottom:12px;
}
.sj-epreuve-label{font-size:13px;font-weight:700;color:var(--j-muted);margin-bottom:14px;text-transform:uppercase;letter-spacing:.5px;}

/* Boutons +/- */
.sj-note-row{display:flex;align-items:center;justify-content:center;gap:16px;}
.sj-btn-pm{
    width:72px;height:72px;border:none;border-radius:50%;
    font-size:32px;font-weight:700;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    transition:transform .1s,box-shadow .1s;
    -webkit-tap-highlight-color:transparent;
}
.sj-btn-pm:active{transform:scale(.9);}
.sj-btn-minus{background:#fee2e2;color:var(--j-red);}
.sj-btn-plus {background:#dcfce7;color:var(--j-green);}
.sj-btn-pm:disabled{opacity:.3;cursor:not-allowed;}
.sj-note-display{
    min-width:80px;text-align:center;
    font-size:48px;font-weight:900;color:var(--j-ink);line-height:1;
}
.sj-note-range{font-size:12px;color:var(--j-muted);text-align:center;margin-top:4px;}

/* Sync */
.sj-sync{font-size:13px;text-align:center;height:20px;margin-top:6px;}
.sj-sync-ok {color:var(--j-green);}
.sj-sync-err{color:var(--j-red);}

/* Score */
.sj-score-bar{
    background:var(--j-white);border:1px solid var(--j-border);border-radius:12px;
    padding:14px 16px;margin-bottom:12px;display:flex;align-items:center;gap:12px;
}
.sj-score-label{flex:1;font-size:13px;color:var(--j-muted);}
.sj-score-val{font-size:22px;font-weight:900;}
.sj-verdict-badge{font-size:13px;font-weight:700;padding:4px 12px;border-radius:20px;}
.sj-verdict-admis  {background:#dcfce7;color:var(--j-green);}
.sj-verdict-ajourne{background:#fee2e2;color:var(--j-red);}
.sj-verdict-wait   {background:#f3f4f6;color:var(--j-muted);}

/* Vue maître */
.sj-maitre-panel{background:var(--j-bg);border-top:2px solid var(--j-border);padding:16px;}
.sj-maitre-title{font-size:13px;font-weight:700;color:var(--j-muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px;}
.sj-maitre-row{
    display:flex;align-items:center;gap:10px;padding:8px 12px;
    background:var(--j-white);border:1px solid var(--j-border);border-radius:8px;margin-bottom:6px;
    cursor:pointer;transition:border-color .15s;
}
.sj-maitre-row.active{border-color:var(--j-blue);background:#eff6ff;}
.sj-maitre-row .mn{flex:1;font-size:13px;font-weight:600;}
.sj-maitre-row .ms{font-size:13px;font-weight:800;}
.sj-maitre-row .mv{font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;}

/* Alertes */
.sj-alert{padding:14px 16px;border-radius:8px;font-size:14px;margin:16px;}
.sj-alert-warn  {background:#fef9c3;border:1px solid #fbbf24;color:#854d0e;}
.sj-alert-locked{background:#fee2e2;border:1px solid #f87171;color:#7f1d1d;}

/* Poll bar */
.sj-poll-bar{height:3px;background:var(--j-border);}
.sj-poll-fill{height:3px;background:var(--j-blue);width:0%;transition:width linear;}

/* Bouton changer de juge */
.sj-change-btn{
    position:fixed;bottom:16px;right:16px;z-index:200;
    background:var(--j-ink);color:#fff;border:none;border-radius:24px;
    padding:10px 18px;font-size:12px;font-weight:700;cursor:pointer;
    box-shadow:0 4px 12px rgba(0,0,0,.3);
}
</style>

<!-- ══ ÉCRAN 1 : Sélection du juge ══ -->
<div id="sj-select-screen">
    <h1>⚖️ <?php echo $label_aire; ?></h1>
    <div class="sub"><?php echo $club; ?> · <?php echo $nb_candidats; ?> candidat<?php echo $nb_candidats>1?'s':''; ?> · <?php echo $note_min; ?>–<?php echo $note_max; ?></div>

    <?php if ( $not_started ): ?>
    <div class="sj-alert sj-alert-warn" style="max-width:320px;margin-bottom:24px;">⏳ L'examen n'a pas encore démarré.</div>
    <?php elseif ( $locked ): ?>
    <div class="sj-alert sj-alert-locked" style="max-width:320px;margin-bottom:24px;">🔒 L'examen est terminé.</div>
    <?php endif; ?>

    <?php if ( empty($juges) ): ?>
    <div class="sj-alert sj-alert-warn" style="max-width:320px;">Aucun juge configuré pour cette aire.</div>
    <?php else: ?>
    <?php foreach ($juges as $j):
        $initiales = mb_strtoupper(mb_substr($j->prenom,0,1).mb_substr($j->nom,0,1));
    ?>
    <button class="sj-juge-btn" onclick="sjSelectJuge(<?php echo intval($j->id); ?>, '<?php echo esc_js(trim($j->prenom.' '.$j->nom)); ?>')">
        <div class="avatar"><?php echo esc_html($initiales); ?></div>
        <div class="name">
            <?php echo esc_html(trim($j->prenom.' '.$j->nom)); ?>
            <small>Juge · <?php echo $label_aire; ?></small>
        </div>
    </button>
    <?php endforeach; ?>

    <?php if (!$locked && !$not_started): ?>
    <button class="sj-juge-btn" onclick="sjSelectJuge(0,'Maître (vue globale)')" style="background:#f0f9ff;border:2px dashed #bae6fd;">
        <div class="avatar" style="background:#0369a1;">👁</div>
        <div class="name">Vue maître — tous les candidats<small>Lecture seule + navigation</small></div>
    </button>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ══ ÉCRAN 2 : Saisie notes ══ -->
<div id="sj-scoring-screen">

    <div class="sj-header">
        <div class="sj-header-left">
            <h2>⚖️ <?php echo $label_aire; ?></h2>
            <div class="sj-juge-chip" id="sj-juge-label" style="display:none;">⚖️ —</div>
        </div>
        <?php
        $st = $session->statut;
        $sl = $st === 'en_cours' ? 'EN COURS' : ($st === 'termine' ? 'TERMINÉ' : 'PRÉPARATION');
        ?>
        <span class="sj-statut-badge sj-statut-<?php echo $st; ?>"><?php echo $sl; ?></span>
        <div style="font-size:11px;color:var(--j-muted);text-align:right;">
            Seuil : <strong><?php echo $seuil; ?></strong><br>
            <span id="sj-poll-ts"></span>
        </div>
    </div><!-- /.sj-header -->

    <div class="sj-poll-bar">
        <div class="sj-poll-fill" id="sj-poll-fill"></div>
    </div>

    <?php if ($not_started): ?>
    <div class="sj-alert sj-alert-warn">⏳ L'examen n'a pas encore démarré.</div>
    <?php elseif ($locked): ?>
    <div class="sj-alert sj-alert-locked">🔒 Terminé — notes en lecture seule.</div>
    <?php endif; ?>

    <?php if (empty($candidats)): ?>
    <div class="sj-alert sj-alert-warn" style="margin:16px;">
        ⚠️ Aucun candidat affecté à cette aire. L'administrateur doit les assigner depuis l'interface centrale.
    </div>
    <?php else: ?>

    <!-- Navigation candidat -->
    <div class="sj-candidat-nav">
        <button class="sj-nav-btn" onclick="sjNavCandidatDirect('prev')">◀ Préc.</button>
        <div style="flex:1;text-align:center;">
            <div class="sj-name" id="sj-cand-name">—</div>
            <div class="sj-pos" id="sj-cand-info"></div>
        </div>
        <button class="sj-nav-btn" onclick="sjNavCandidatDirect('next')">Suiv. ▶</button>
    </div>

    <!-- Message fin de liste -->
    <div id="sj-end-msg" style="display:none;margin:12px 16px;padding:14px 18px;
         background:#f0fdf4;border:1px solid #86efac;border-radius:10px;
         text-align:center;font-size:14px;font-weight:700;color:#14532d;">
        ✅ Tous les candidats de cette aire ont été évalués.<br>
        <span style="font-size:12px;font-weight:400;color:#16a34a;margin-top:4px;display:block;">
            Votre aire est en attente de validation par l'administrateur.
        </span>
    </div>

    <!-- Zone notes -->
    <div class="sj-notes-zone">

        <!-- Groupe ZEMITA -->
        <div id="sj-zemita-groupe-wrap" style="display:none;margin-bottom:12px;">
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;">
                <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">
                    ⚖️ Groupe ZEMITA
                    <span style="font-size:10px;font-weight:400;text-transform:none;margin-left:4px;">(pré-rempli selon le grade, modifiable)</span>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;" id="sj-zemita-btns"></div>
                <div style="font-size:11px;color:#94a3b8;margin-top:8px;" id="sj-zemita-seuils-info"></div>
                <div style="font-size:11px;height:16px;" id="sj-zemita-sync"></div>
            </div>
        </div>

        <!-- Score + verdict -->
        <div class="sj-score-bar">
            <div class="sj-score-label">Score total · seuil <?php echo $seuil; ?></div>
            <div class="sj-score-val" id="sj-score-val">—</div>
            <div class="sj-verdict-badge sj-verdict-wait" id="sj-verdict">En attente</div>
        </div>

        <!-- Blocs épreuves -->
        <?php foreach ($epreuves as $ep):
            $epid = intval($ep->id);
        ?>
        <div class="sj-epreuve-block" data-ep="<?php echo $epid; ?>">
            <div class="sj-epreuve-label"><?php echo esc_html($ep->nom); ?></div>
            <?php if ($is_zemita_aire): ?>
            <div class="sj-note-row" style="justify-content:center;">
                <div style="text-align:center;">
                    <input type="number"
                           class="sj-note-input-manual"
                           id="nd-<?php echo $epid; ?>"
                           data-ep="<?php echo $epid; ?>"
                           min="<?php echo $note_min; ?>"
                           max="<?php echo $note_max; ?>"
                           value="<?php echo $note_min; ?>"
                           <?php echo $locked ? 'disabled' : ''; ?>
                           style="width:120px;font-size:48px;font-weight:900;text-align:center;
                                  border:2px solid #e2e8f0;border-radius:12px;padding:8px;
                                  color:#0f172a;background:#fff;">
                    <div class="sj-note-range"><?php echo $note_min; ?> – <?php echo $note_max; ?></div>
                    <div class="sj-sync" id="sync-<?php echo $epid; ?>"></div>
                </div>
            </div>
            <?php else: ?>
            <div class="sj-note-row">
                <button class="sj-btn-pm sj-btn-minus" data-ep="<?php echo $epid; ?>" data-delta="-1" <?php echo $locked ? 'disabled' : ''; ?>>−</button>
                <div>
                    <div class="sj-note-display" id="nd-<?php echo $epid; ?>"><?php echo $note_min; ?></div>
                    <div class="sj-note-range"><?php echo $note_min; ?> – <?php echo $note_max; ?></div>
                    <div class="sj-sync" id="sync-<?php echo $epid; ?>"></div>
                </div>
                <button class="sj-btn-pm sj-btn-plus" data-ep="<?php echo $epid; ?>" data-delta="1" <?php echo $locked ? 'disabled' : ''; ?>>+</button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

    </div><!-- /.sj-notes-zone -->

    <!-- Vue maître -->
    <div class="sj-maitre-panel" id="sj-maitre-panel" style="display:none;">
        <div class="sj-maitre-title">📋 Tous les candidats — <?php echo $label_aire; ?></div>
        <div id="sj-maitre-list"></div>
    </div>

    <?php endif; // candidats ?>

</div><!-- /#sj-scoring-screen -->

<!-- Bouton changer de juge -->
<button class="sj-change-btn" id="sj-change-btn" style="display:none;" onclick="sjBackToSelect()">
    ↩ Changer de juge
</button>

<script>
(function($){
    /* ── Variables globales ── */
    var AJAXURL  = '<?php echo admin_url('admin-ajax.php'); ?>';
    var NONCE    = '<?php echo wp_create_nonce("sp_jury_nonce"); ?>';
    var EVENT_ID = <?php echo intval($event_id); ?>;
    var AIRE_ID  = <?php echo intval($aire_id); ?>;
    var NOTE_MIN = <?php echo $note_min; ?>;
    var NOTE_MAX = <?php echo $note_max; ?>;
    var SEUIL    = <?php echo $seuil; ?>;
    var LOCKED   = <?php echo ($locked || $not_started) ? 'true' : 'false'; ?>;
    var POLL_MS  = 4000;

    var zemitaMode          = <?php echo $session->zemita_mode ? 'true' : 'false'; ?>;
    var zemitaGrid          = {};
    var zemitaGroupes       = ['Débutant','Intermédiaire','Confirmé'];
    var currentZemitaGroupe = '';

    var currentJugeId  = 0;
    var currentJugeNom = '';
    var currentEleveId = 0;
    var currentPos     = 0;
    var isMaitre       = false;
    var pollTimer      = null;
    var pollStart      = 0;
    var inputFocused   = false;  // ← protège la saisie contre l'écrasement par le poll
	var savePending  = false;
    var localNotes = {};

    var candidatsList = <?php
        $clist = array_map(function($c){ return array(
            'id'            => intval($c->eleve_id),
            'nom'           => $c->nom,
            'prenom'        => $c->prenom,
            'grade'         => $c->grade,
            'categorie_age' => $c->categorie_age ?? '',
            'zemita_groupe' => $c->zemita_groupe  ?? '',
        ); }, $candidats);
        echo json_encode($clist);
    ?>;

    /* ── Sélection juge ── */
    window.sjSelectJuge = function(jugeId, jugeNom) {
        currentJugeId  = jugeId;
        currentJugeNom = jugeNom;
        isMaitre       = (jugeId === 0);
        $('#sj-juge-label').text('⚖️ ' + jugeNom).show();
        $('#sj-select-screen').hide();
        $('#sj-scoring-screen').css('display','flex');
        $('#sj-change-btn').show();
        if (isMaitre) $('#sj-maitre-panel').show();
        else          $('#sj-maitre-panel').hide();
        // Premier affichage déclenché par sjPoll() via firstPollDone
        sjStartPoll();
    };

    window.sjBackToSelect = function() {
        clearInterval(pollTimer);
        $('#sj-scoring-screen').hide();
        $('#sj-select-screen').show();
        $('#sj-change-btn').hide();
    };

    /* ── Navigation candidats ── */
    function sjGoToCandidat(pos) {
        if (pos < 0) pos = 0;
        if (pos >= candidatsList.length) pos = candidatsList.length - 1;
        currentPos     = pos;
        currentEleveId = parseInt(candidatsList[pos].id);
        var c = candidatsList[pos];
        $('#sj-cand-name').text(c.prenom + ' ' + c.nom.toUpperCase());
        $('#sj-cand-info').text((pos+1) + ' / ' + candidatsList.length + (c.grade ? ' · ' + c.grade : ''));
        if (zemitaMode) {
            currentZemitaGroupe = c.zemita_groupe || '';
            $('#sj-zemita-groupe-wrap').show();
            sjRenderZemitaGroupes();
        }
        sjDisplayNotes(currentEleveId);
    }

    window.sjNavCandidatDirect = function(dir) {
        if (dir === 'next' && currentPos >= candidatsList.length - 1) {
            $('#sj-end-msg').fadeIn(200);
            return;
        }
        $('#sj-end-msg').hide();
        sjGoToCandidat(dir === 'next' ? currentPos + 1 : currentPos - 1);
    };

    /* ── Affichage notes ── */
    function sjDisplayNotes(eleveId) {
        var notes = localNotes[eleveId] || {};
        $('[data-ep]').each(function(){
            var epid = parseInt($(this).data('ep') || $(this).attr('data-ep'));
            if (!epid) return;
            var note = notes[epid] !== undefined ? notes[epid] : NOTE_MIN;
            var $manual = $('#nd-' + epid + '.sj-note-input-manual');
            if ($manual.length) {
                // Ne pas écraser si le juge est en train de saisir
				if (!inputFocused && !savePending) {
					$manual.val(note);
				}
            } else {
                $('#nd-' + epid).text(Math.round(note));
                $('[data-ep="'+epid+'"][data-delta="-1"]').prop('disabled', LOCKED || note <= NOTE_MIN);
                $('[data-ep="'+epid+'"][data-delta="1"]' ).prop('disabled', LOCKED || note >= NOTE_MAX);
            }
        });
        sjUpdateScore(eleveId);
    }

    /* ── Calcul score + verdict ── */
    function sjUpdateScore(eleveId) {
        var notes = localNotes[eleveId] || {};
        var total = 0, has = false;
        $.each(notes, function(ep, val){
            if (typeof val === 'object') {
                $.each(val, function(j, n){ total += parseFloat(n); has = true; });
            } else {
                total += parseFloat(val); has = true;
            }
        });
        if (!has) {
            $('#sj-score-val').text('—');
            $('#sj-verdict').attr('class','sj-verdict-badge sj-verdict-wait').text('En attente');
            return;
        }
        $('#sj-score-val').text(total.toFixed(1));

        var admis = false, excellence = false;
        if (zemitaMode && currentZemitaGroupe) {
            var cat = '';
            candidatsList.forEach(function(c){ if (parseInt(c.id) === eleveId) cat = c.categorie_age || ''; });
            var seuils = zemitaGrid[currentZemitaGroupe] && zemitaGrid[currentZemitaGroupe][cat];
            if (seuils) {
                admis     = total >= parseFloat(seuils.seuil_moyen);
                excellence= total >= parseFloat(seuils.seuil_performance);
            }
        } else {
            admis = total >= SEUIL;
        }

        if (excellence) {
            $('#sj-verdict').attr('class','sj-verdict-badge sj-verdict-admis').text('⭐ Excellence');
        } else if (admis) {
            $('#sj-verdict').attr('class','sj-verdict-badge sj-verdict-admis').text('✅ Admis');
        } else {
            $('#sj-verdict').attr('class','sj-verdict-badge sj-verdict-ajourne').text('❌ Ajourné');
        }
    }

    /* ── Focus / blur sur saisie manuelle ── */
    $(document).on('focus', '.sj-note-input-manual', function(){ inputFocused = true;  });
    $(document).on('blur',  '.sj-note-input-manual', function(){ inputFocused = false; });

    /* ── Clic +/- ── */
    $(document).on('click', '.sj-btn-pm[data-delta]', function(){
        if (LOCKED || !currentJugeId || isMaitre) {
            if (LOCKED) {
                var $sync = $('#sync-' + $(this).data('ep'));
                $sync.text('⏳ Examen non lancé').removeClass('sj-sync-ok').addClass('sj-sync-err');
                setTimeout(function(){ $sync.text('').removeClass('sj-sync-err'); }, 2500);
            }
            return;
        }
        var $btn  = $(this);
        var epid  = parseInt($btn.data('ep'));
        var delta = parseInt($btn.data('delta'));
        if (!localNotes[currentEleveId]) localNotes[currentEleveId] = {};
        var cur  = localNotes[currentEleveId][epid] !== undefined ? localNotes[currentEleveId][epid] : NOTE_MIN;
        var next = Math.max(NOTE_MIN, Math.min(NOTE_MAX, cur + delta));
        localNotes[currentEleveId][epid] = next;
        $('#nd-' + epid).text(Math.round(next));
        $('[data-ep="'+epid+'"][data-delta="-1"]').prop('disabled', next <= NOTE_MIN);
        $('[data-ep="'+epid+'"][data-delta="1"]' ).prop('disabled', next >= NOTE_MAX);
        sjUpdateScore(currentEleveId);
        var $sync = $('#sync-' + epid);
        $sync.text('⏳').removeClass('sj-sync-ok sj-sync-err');
		savePending = true;
        $.post(AJAXURL, {
            action:'sp_jury_save_note',
            event_id:EVENT_ID, aire_id:AIRE_ID,
            juge_id:currentJugeId, eleve_id:currentEleveId, epreuve_id:epid, delta:delta
        }, function(r){
			savePending = false;
            if (r && r.success) {
                var srv = parseFloat(r.data.note);
                if (srv !== next) {
                    localNotes[currentEleveId][epid] = srv;
                    $('#nd-' + epid).text(Math.round(srv));
                    sjUpdateScore(currentEleveId);
                }
                $sync.text('✓').addClass('sj-sync-ok');
            } else {
                localNotes[currentEleveId][epid] = cur;
                $('#nd-' + epid).text(Math.round(cur));
                $sync.text('✗').addClass('sj-sync-err');
            }
            setTimeout(function(){ $sync.text(''); }, 2000);
        }).fail(function(){
            localNotes[currentEleveId][epid] = cur;
            $('#nd-' + epid).text(Math.round(cur));
            $('#sync-' + epid).text('✗').addClass('sj-sync-err');
        });
    });

    /* ── Saisie manuelle ZEMITA ── */
    $(document).on('change', '.sj-note-input-manual', function(){
        if (LOCKED || !currentJugeId || isMaitre) return;
        var $inp  = $(this);
        var epid  = parseInt($inp.data('ep'));
        var val   = Math.max(NOTE_MIN, Math.min(NOTE_MAX, parseFloat($inp.val()) || NOTE_MIN));
        $inp.val(val);
        if (!localNotes[currentEleveId]) localNotes[currentEleveId] = {};
        localNotes[currentEleveId][epid] = val;
        sjUpdateScore(currentEleveId);
        var $sync = $('#sync-' + epid);
        $sync.text('⏳').removeClass('sj-sync-ok sj-sync-err');
		
		savePending = true;
        $.post(AJAXURL, {
            action:'sp_jury_save_note',
            event_id:EVENT_ID, aire_id:AIRE_ID,
            juge_id:currentJugeId, eleve_id:currentEleveId, epreuve_id:epid, note_val:val
        }, function(r){
			savePending = false;
            if (r && r.success) {
                $sync.text('✓').addClass('sj-sync-ok');
            } else {
                $sync.text('✗').addClass('sj-sync-err');
            }
            setTimeout(function(){ $sync.text(''); }, 2000);
        }).fail(function(){
            $('#sync-' + epid).text('✗').addClass('sj-sync-err');
        });
    });

    /* ── Erreur AJAX 403 ── */
    $(document).ajaxError(function(event, jqxhr, settings){
        if (settings.url && settings.url.indexOf('admin-ajax.php') !== -1 && jqxhr.status === 403) {
            try {
                var resp = JSON.parse(jqxhr.responseText);
                if (resp && resp.data) {
                    $('[id^="sync-"]').first().text(resp.data).addClass('sj-sync-err');
                }
            } catch(e) {}
        }
    });

    /* ── Polling ── */
    var firstPollDone = false;

    function sjStartPoll() {
        firstPollDone = false;
        sjPoll();
        pollTimer = setInterval(sjPoll, POLL_MS);
    }

    function sjPoll() {
        pollStart = Date.now();
        animPoll();
        $.post(AJAXURL, {
            action  : 'sp_jury_get_aire_state',
            event_id: EVENT_ID,
            aire_id : AIRE_ID,
            juge_id : currentJugeId
        }, function(r){
            if (!r || !r.success) return;
            var d = r.data;

            $('#sj-poll-ts').text(new Date().toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'}));

// Bornes depuis l'aire
if (d.note_min !== undefined) NOTE_MIN = parseInt(d.note_min);
if (d.note_max !== undefined) {
    NOTE_MAX = parseInt(d.note_max);
    // On force le plafond à 9999 si on est sur une aire Zemita
    if (zemitaMode || d.zemita_mode) {
        NOTE_MAX = 9999;
    }
}
if (d.note_min !== undefined) $('.sj-note-input-manual').attr('min', NOTE_MIN);
if (NOTE_MAX !== undefined)   $('.sj-note-input-manual').attr('max', NOTE_MAX);

            // Seuil
            if (d.aire && d.aire.seuil_admission !== undefined) SEUIL = parseFloat(d.aire.seuil_admission);

            // Statut locked
            if (d.locked !== undefined) LOCKED = d.locked;

            // ZEMITA
            if (d.zemita_mode) {
                zemitaMode = true;
                zemitaGrid = d.zemita_grid || {};
            }

            // Premier poll : afficher le premier candidat maintenant que NOTE_MAX est correct
            if (!firstPollDone) {
                firstPollDone = true;
                if (candidatsList.length > 0) sjGoToCandidat(0);
            }

            // Groupe ZEMITA du candidat courant
            if (zemitaMode && d.candidats && currentEleveId) {
                d.candidats.forEach(function(c){
                    if (parseInt(c.eleve_id || c.id) === currentEleveId) {
                        if (c.zemita_groupe && c.zemita_groupe !== currentZemitaGroupe) {
                            currentZemitaGroupe = c.zemita_groupe;
                            sjRenderZemitaGroupes();
                        }
                    }
                });
            }

            // Fusionner notes depuis serveur
            if (d.notes) {
                $.each(d.notes, function(eleveId, epMap){
                    if (!localNotes[eleveId]) localNotes[eleveId] = {};
                    $.each(epMap, function(epid, jugeMap){
                        if (typeof jugeMap === 'object') {
                            if (isMaitre) {
                                var sum = 0, cnt = 0;
                                $.each(jugeMap, function(_, n){ sum += parseFloat(n); cnt++; });
                                if (cnt) localNotes[eleveId][epid] = sum;
                            } else if (currentJugeId && jugeMap[currentJugeId] !== undefined) {
                                localNotes[eleveId][epid] = parseFloat(jugeMap[currentJugeId]);
                            }
                        }
                    });
                });
            }

            // Mise à jour affichage candidat courant
            if (currentEleveId) sjDisplayNotes(currentEleveId);

            // Vue maître
            if (isMaitre && d.candidats) sjRenderMaitre(d.candidats, d.scores || {});
        });
    }

    function animPoll() {
        var pct = Math.min(100, (Date.now() - pollStart) / POLL_MS * 100);
        $('#sj-poll-fill').css('width', pct + '%');
        if (pct < 100) requestAnimationFrame(animPoll);
    }

    /* ── Vue maître ── */
    function sjRenderMaitre(candidats, scores) {
        var html = '';
        candidats.forEach(function(c, i){
            var sc    = scores[c.id] || {};
            var score = (sc.score !== null && sc.score !== undefined) ? parseFloat(sc.score).toFixed(1) : '—';
            var vCls  = sc.admis === true ? 'sj-verdict-admis' : (sc.admis === false ? 'sj-verdict-ajourne' : 'sj-verdict-wait');
            var vTxt  = sc.admis === true ? '✅' : (sc.admis === false ? '❌' : '⏳');
            var active= (c.id == currentEleveId) ? ' active' : '';
            html += '<div class="sj-maitre-row' + active + '" onclick="sjGoToCandidat(' + i + ')">'
                  + '<div class="mn">' + esc(c.prenom) + ' ' + esc(c.nom.toUpperCase()) + '</div>'
                  + '<div class="ms">' + score + '</div>'
                  + '<div class="mv sj-verdict-badge ' + vCls + '">' + vTxt + '</div>'
                  + '</div>';
        });
        $('#sj-maitre-list').html(html);
    }

    function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    /* ── ZEMITA : boutons groupe ── */
    function sjRenderZemitaGroupes() {
        if (!zemitaMode) return;
        var html = '';
        zemitaGroupes.forEach(function(g){
            var active = (g === currentZemitaGroupe);
            html += '<button onclick="sjSetZemitaGroupe(\'' + g + '\')" style="'
                  + 'padding:6px 14px;border-radius:20px;border:2px solid ' + (active ? '#2563eb' : '#e2e8f0') + ';'
                  + 'background:' + (active ? '#eff6ff' : '#fff') + ';'
                  + 'color:' + (active ? '#1d4ed8' : '#374151') + ';'
                  + 'font-size:12px;font-weight:' + (active ? '700' : '500') + ';cursor:pointer;">'
                  + g + '</button>';
        });
        $('#sj-zemita-btns').html(html);
        sjUpdateZemitaInfo();
    }

    function sjUpdateZemitaInfo() {
        if (!zemitaMode || !currentZemitaGroupe) return;
        var cat = '';
        candidatsList.forEach(function(c){
            if (parseInt(c.id || c.eleve_id) === currentEleveId) cat = c.categorie_age || '';
        });
        var seuils = zemitaGrid[currentZemitaGroupe] && zemitaGrid[currentZemitaGroupe][cat];
        if (seuils) {
            $('#sj-zemita-seuils-info').html(
                'Seuil Moyen : <strong>' + seuils.seuil_moyen + '</strong>'
                + ' &nbsp;|&nbsp; Seuil Performance : <strong>' + seuils.seuil_performance + '</strong>'
            );
        } else {
            $('#sj-zemita-seuils-info').text('Seuils non configurés pour ce groupe/catégorie.');
        }
    }

    window.sjSetZemitaGroupe = function(groupe) {
        if (LOCKED || !currentEleveId) return;
        currentZemitaGroupe = groupe;
        sjRenderZemitaGroupes();
        $('#sj-zemita-sync').text('⏳').css('color','#94a3b8');
        $.post(AJAXURL, {
            action:'sp_jury_save_zemita_groupe', nonce:NONCE,
            event_id:EVENT_ID, eleve_id:currentEleveId, groupe:groupe
        }, function(r){
            if (r && r.success) {
                $('#sj-zemita-sync').text('✓ Groupe sauvegardé').css('color','#16a34a');
                setTimeout(function(){ $('#sj-zemita-sync').text(''); }, 2000);
                sjUpdateScore(currentEleveId);
            } else {
                $('#sj-zemita-sync').text('✗ Erreur').css('color','#dc2626');
            }
        });
    };

    // Init si 1 seul juge : sélection automatique
    <?php if (count($juges) === 1): $j = $juges[0]; ?>
    $(function(){ sjSelectJuge(<?php echo intval($j->id); ?>, '<?php echo esc_js(trim($j->prenom.' '.$j->nom)); ?>'); });
    <?php endif; ?>

})(jQuery);
</script>

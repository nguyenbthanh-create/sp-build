/**
 * SP_Cal — Module Jury d'examen
 * Fichier : assets/js/jury-admin.js
 *
 * JS extrait de class-admin-jury.php
 * Dépend de jQuery (chargé par WordPress admin)
 */
(function ($) {

    /* ════════════════════════════════════════════════════════
       VARIABLES GLOBALES (injectées par wp_localize_script)
       spJuryAdmin.ajaxurl
       spJuryAdmin.nonce
       spJuryAdmin.eid          (event_id)
       spJuryAdmin.statut
       spJuryAdmin.modeSession
       spJuryAdmin.supportNotation
       spJuryAdmin.noteMin
       spJuryAdmin.noteMax
       spJuryAdmin.seuil
       spJuryAdmin.epreuves     (JSON array)
       spJuryAdmin.trainers     (JSON array)
       spJuryAdmin.airesData    (JSON array)
       spJuryAdmin.seuilsGrid   (JSON object)
       spJuryAdmin.epreuvesType (JSON object)
       spJuryAdmin.aireCount    (int)
    ════════════════════════════════════════════════════════ */

    if ( typeof spJuryAdmin === 'undefined' ) return;

    var AJAXURL         = spJuryAdmin.ajaxurl;
    var NONCE           = spJuryAdmin.nonce;
    var EID             = spJuryAdmin.eid;
    var SEUIL           = spJuryAdmin.seuil;
    var NOTE_MIN        = spJuryAdmin.noteMin;
    var NOTE_MAX        = spJuryAdmin.noteMax;
    var modeSession     = spJuryAdmin.modeSession;
    var supportNotation = spJuryAdmin.supportNotation;
    var statutInitial   = spJuryAdmin.statut;
    var EPREUVES        = spJuryAdmin.epreuves     || [];
    var AIRES_DATA      = spJuryAdmin.airesData    || [];
    var SEUILS_GRID     = spJuryAdmin.seuilsGrid   || {};
    var EPREUVES_TYPE   = spJuryAdmin.epreuvesType || {};
    var TRAINERS        = spJuryAdmin.trainers     || [];
    var aireCount       = spJuryAdmin.aireCount    || 0;
    var centraleTimer   = null;

    /* ════════════════════════════════════════════════════════
       UTILITAIRES
    ════════════════════════════════════════════════════════ */

    function esc(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /* ════════════════════════════════════════════════════════
       ÉTAPE 1 — PARAMÈTRES
    ════════════════════════════════════════════════════════ */

    function sjaModeUI() {
        var mode = $('#sja-mode').val();
        $('.sja-aire-epreuve').toggle(mode === 'par_epreuve');
    }

    function sjaVerdictUI() {
        $('.sja-aire-row').each(function () {
            var isZemita = $(this).find('.sja-aire-type-verdict').val() === 'zemita';
            $(this).find('.sja-aire-seuil-label').toggle(!isZemita);
            $(this).find('.sja-aire-seuil').toggle(!isZemita);
        });
    }

    if ($('#sja-mode').length) {
        $('#sja-mode').on('change', sjaModeUI);
        sjaModeUI();
        $(document).on('change', '.sja-aire-type-verdict', sjaVerdictUI);
        sjaVerdictUI();
    }

    // Ajouter une aire
    $('#sja-add-aire').on('click', function () {
        aireCount++;
        var epreuvesOptions = '<option value="">— Épreuve —</option>';
        EPREUVES.forEach(function (ep) {
            epreuvesOptions += '<option value="' + ep.id + '">' + esc(ep.nom) + '</option>';
        });
        var sel = '<select class="sja-aire-epreuve" style="border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;">'
            + epreuvesOptions + '</select>';
        $('#sja-aires-wrap').append(
            '<div class="sja-aire-row" style="flex-wrap:wrap;gap:8px;padding:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;">'
            + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;width:100%;">'
            + '<input type="text" class="sja-aire-label" placeholder="Libellé (ex: Aire A)" style="width:160px;border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;">'
            + sel
            + '<span style="font-size:12px;color:#9ca3af;">Aire ' + aireCount + '</span>'
            + '<button class="sja-btn sja-btn-red sja-btn-sm sja-del-aire" onclick="jQuery(this).closest(\'.sja-aire-row\').remove()">✕</button>'
            + '</div>'
            + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px;">'
            + '<label style="font-size:12px;color:#374151;font-weight:600;">Note min</label>'
            + '<input type="number" class="sja-aire-note-min" min="0" max="999" value="0" style="width:65px;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:13px;">'
            + '<label style="font-size:12px;color:#374151;font-weight:600;">Note max</label>'
            + '<input type="number" class="sja-aire-note-max" min="1" max="999" value="10" style="width:65px;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:13px;">'
            + '<label style="font-size:12px;color:#374151;font-weight:600;">Type verdict</label>'
            + '<select class="sja-aire-type-verdict" style="border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:13px;">'
            + '<option value="seuil_fixe">Seuil fixe</option>'
            + '<option value="zemita">ZEMITA</option>'
            + '</select>'
            + '<label style="font-size:12px;color:#374151;font-weight:600;" class="sja-aire-seuil-label">Seuil admission</label>'
            + '<input type="number" class="sja-aire-seuil" min="0" step="0.5" value="0" style="width:75px;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:13px;">'
            + '</div>'
            + '</div>'
        );
        sjaModeUI();
        sjaVerdictUI();
    });

    // Sauvegarder paramètres
    $('#sja-save-params').on('click', function () {
        var aires = [];
        var num = 1;
        $('.sja-aire-row').each(function () {
            aires.push({
                numero:          num++,
                label:           $(this).find('.sja-aire-label').val(),
                epreuve_id:      $(this).find('.sja-aire-epreuve').val() || '',
                note_min:        $(this).find('.sja-aire-note-min').val() || 0,
                note_max:        $(this).find('.sja-aire-note-max').val() || 10,
                type_verdict:    $(this).find('.sja-aire-type-verdict').val() || 'seuil_fixe',
                seuil_admission: $(this).find('.sja-aire-seuil').val() || 0,
            });
        });
        $.post(AJAXURL, {
            action:           'sp_jury_save_session',
            nonce:            NONCE,
            event_id:         EID,
            mode:             $('#sja-mode').val(),
            support_notation: $('#sja-support-notation').val(),
            note_min:         $('#sja-note-min').val(),
            note_max:         $('#sja-note-max').val(),
            seuil_admission:  $('#sja-seuil').val(),
            zemita_mode:      $('#sja-zemita-toggle').is(':checked') ? 1 : 0,
            statut:           statutInitial,
            aires:            aires,
        }, function (r) {
            if (r.success) {
                $('#sja-params-msg').text('✓ Sauvegardé').addClass('sja-msg-ok');
                setTimeout(function () {
                    location.href = '?page=sp-cal-jury&event_id=' + EID + '&step=2';
                }, 600);
            } else {
                $('#sja-params-msg').text('✗ Erreur').addClass('sja-msg-err');
            }
        });
    });

    // ZEMITA toggle
    if ($('#sja-zemita-toggle').length) {
        $('#sja-zemita-toggle').on('change', function () {
            $('#sja-zemita-config').toggle(this.checked);
        });
        $('#sja-zemita-config').toggle($('#sja-zemita-toggle').is(':checked'));
    }

    // Sauvegarder barème ZEMITA
    $('#sja-save-zemita-seuils').on('click', function () {
        var seuils = {};
        $('.sja-zemita-cell').each(function () {
            var g = $(this).data('groupe');
            var c = $(this).data('cat');
            var t = $(this).data('type');
            if (!seuils[g]) seuils[g] = {};
            if (!seuils[g][c]) seuils[g][c] = {};
            seuils[g][c][t] = $(this).val();
        });
        var $btn = $(this).prop('disabled', true).text('…');
        $.post(AJAXURL, { action: 'sp_jury_save_zemita_seuils', nonce: NONCE, event_id: EID, seuils: seuils, epreuve_id: 3 }, function (r) {
            $btn.prop('disabled', false).text('💾 Sauvegarder le barème ZEMITA');
            if (r.success) {
                $('#sja-zemita-msg').text('✓ Barème ZEMITA sauvegardé').addClass('sja-msg-ok');
                setTimeout(function () { $('#sja-zemita-msg').text('').removeClass('sja-msg-ok'); }, 3000);
            } else { $('#sja-zemita-msg').text('✗ Erreur').addClass('sja-msg-err'); }
        });
    });

    // Sauvegarder barème Réactivité
    $('#sja-save-reactivite-seuils').on('click', function () {
        var seuils = {};
        $('.sja-reactivite-cell').each(function () {
            var g = $(this).data('groupe');
            var c = $(this).data('cat');
            var t = $(this).data('type');
            if (!seuils[g]) seuils[g] = {};
            if (!seuils[g][c]) seuils[g][c] = {};
            seuils[g][c][t] = $(this).val();
        });
        var $btn = $(this).prop('disabled', true).text('…');
        $.post(AJAXURL, { action: 'sp_jury_save_zemita_seuils', nonce: NONCE, event_id: EID, seuils: seuils, epreuve_id: 4 }, function (r) {
            $btn.prop('disabled', false).text('💾 Sauvegarder le barème Réactivité');
            if (r.success) {
                $('#sja-reactivite-msg').text('✓ Barème Réactivité sauvegardé').addClass('sja-msg-ok');
                setTimeout(function () { $('#sja-reactivite-msg').text('').removeClass('sja-msg-ok'); }, 3000);
            } else { $('#sja-reactivite-msg').text('✗ Erreur').addClass('sja-msg-err'); }
        });
    });

    // Sauvegarder mapping ZEMITA
    $('#sja-save-zemita-mapping').on('click', function () {
        var mapping = {};
        $('.sja-zemita-map-select').each(function () {
            var grade = $(this).data('grade');
            var groupe = $(this).val();
            if (grade && groupe) mapping[grade] = groupe;
        });
        var $btn = $(this).prop('disabled', true).text('…');
        $.post(AJAXURL, { action: 'sp_jury_save_zemita_mapping', nonce: NONCE, mapping: mapping }, function (r) {
            $btn.prop('disabled', false).text('💾 Sauvegarder le mapping');
            if (r.success) {
                $('#sja-mapping-msg').text('✓ Mapping sauvegardé').addClass('sja-msg-ok');
                setTimeout(function () { $('#sja-mapping-msg').text('').removeClass('sja-msg-ok'); }, 3000);
            } else { $('#sja-mapping-msg').text('✗ Erreur').addClass('sja-msg-err'); }
        });
    });

    /* ════════════════════════════════════════════════════════
       ÉTAPE 2 — JUGES
    ════════════════════════════════════════════════════════ */

    $(document).on('click', '.sja-add-juge', function () {
        var aid = $(this).data('aire-id');
        var trainerOptions = '<option value="">— Externe (saisie libre) —</option>';
        TRAINERS.forEach(function (tr) {
            trainerOptions += '<option value="' + tr.id + '" data-nom="' + esc(tr.nom) + '">' + esc(tr.nom) + '</option>';
        });
        var sel = '<select class="sja-juge-trainer" style="border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;">'
            + trainerOptions + '</select>';
        $('.sja-juges-aire[data-aire-id="' + aid + '"]').append(
            '<div class="sja-juge-row">'
            + '<input type="hidden" class="sja-juge-id" value="">'
            + '<input type="hidden" class="sja-juge-aire" value="' + aid + '">'
            + '<input type="text" class="sja-juge-prenom" placeholder="Prénom" style="width:100px;border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;">'
            + '<input type="text" class="sja-juge-nom" placeholder="Nom" style="width:120px;border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;">'
            + sel
            + '<button class="sja-btn sja-btn-red sja-btn-sm sja-del-juge">✕</button></div>'
        );
    });

    $(document).on('click', '.sja-del-juge', function () { $(this).closest('.sja-juge-row').remove(); });

    $(document).on('change', '.sja-juge-trainer', function () {
        var nom = $(this).find('option:selected').data('nom') || '';
        var parts = nom.split(' ');
        $(this).closest('.sja-juge-row').find('.sja-juge-prenom').val(parts[0] || '');
        $(this).closest('.sja-juge-row').find('.sja-juge-nom').val(parts.slice(1).join(' ') || nom);
    });

    $('#sja-save-juges').on('click', function () {
        var juges = [];
        $('.sja-juge-row').each(function () {
            juges.push({
                id:         $(this).find('.sja-juge-id').val(),
                aire_id:    $(this).find('.sja-juge-aire').val(),
                prenom:     $(this).find('.sja-juge-prenom').val(),
                nom:        $(this).find('.sja-juge-nom').val(),
                trainer_id: $(this).find('.sja-juge-trainer').val(),
            });
        });
        $.post(AJAXURL, { action: 'sp_jury_save_juges', nonce: NONCE, event_id: EID, juges: juges }, function (r) {
            if (r.success) {
                $('#sja-juges-msg').text('✓ Sauvegardé').addClass('sja-msg-ok');
                setTimeout(function () { location.href = '?page=sp-cal-jury&event_id=' + EID + '&step=3'; }, 600);
            } else { $('#sja-juges-msg').text('✗ Erreur').addClass('sja-msg-err'); }
        });
    });

    /* ════════════════════════════════════════════════════════
       ÉTAPE 3 — CANDIDATS
    ════════════════════════════════════════════════════════ */

    $('#sja-check-all-na').on('change', function () {
        $('.sja-cand-check').prop('checked', this.checked);
    });

    $('#sja-bulk-assign-cand').on('click', function () {
        var aire_id = modeSession === 'par_epreuve' ? 0 : $('#sja-bulk-aire').val();
        if (modeSession !== 'par_epreuve' && !aire_id) { alert('Choisissez une aire.'); return; }
        var eids = [];
        $('.sja-cand-check:checked').each(function () { eids.push(parseInt($(this).data('eleve'), 10)); });
        if (!eids.length) { alert('Cochez au moins un élève.'); return; }
        $('.sja-unassign-cand').each(function () {
            var eid = parseInt($(this).data('eleve'), 10);
            if (eids.indexOf(eid) === -1) eids.push(eid);
        });
        $.post(AJAXURL, { action: 'sp_jury_save_affectations', nonce: NONCE, event_id: EID, aire_id: aire_id, eleve_ids: eids }, function (r) {
            if (r.success) {
                var url = location.href.replace(/([?&])_ts=[^&]*/, '');
                var sep = url.indexOf('?') !== -1 ? '&' : '?';
                location.href = url + sep + '_ts=' + Date.now();
            }
        });
    });


    $(document).on('click', '.sja-unassign-cand', function () {
        var eid = $(this).data('eleve');
        if (!confirm('Retirer ce candidat de l\'examen ?')) return;
        var $btn = $(this).prop('disabled', true).text('…');
        $.post(AJAXURL, { action: 'sp_jury_remove_candidat', nonce: NONCE, event_id: EID, eleve_id: eid }, function (r) {
            if (r.success) {
                var url = location.href.replace(/([?&])_ts=[^&]*/, '');
                var sep = url.indexOf('?') !== -1 ? '&' : '?';
                location.href = url + sep + '_ts=' + Date.now();
            } else {
                alert('Erreur lors de la suppression.');
                $btn.prop('disabled', false).text('✕');
            }
        });
    });

    $('#sja-reset-candidats').on('click', function () {
        if (!confirm('⚠️ Retirer TOUS les candidats de cet examen ?\nCette action est irréversible.')) return;
        var $btn = $(this).prop('disabled', true).text('…');
        $.post(AJAXURL, { action: 'sp_jury_reset_affectations', nonce: NONCE, event_id: EID }, function (r) {
            if (r.success) {
                var url = location.href.replace(/([?&])_ts=[^&]*/, '');
                var sep = url.indexOf('?') !== -1 ? '&' : '?';
                location.href = url + sep + '_ts=' + Date.now();
            } else {
                alert('Erreur lors de la réinitialisation.');
                $btn.prop('disabled', false).text('🗑️ Réinitialiser tous les candidats');
            }
        });
    });

    /* ════════════════════════════════════════════════════════
       ÉTAPE 4 — STATUT + QR
    ════════════════════════════════════════════════════════ */

    window.sjaSetStatut = function (statut) {
        if (!confirm('Changer le statut vers "' + statut + '" ?')) return;
        $.post(AJAXURL, { action: 'sp_jury_set_statut', nonce: NONCE, event_id: EID, statut: statut }, function (r) {
            if (r.success) location.reload();
        });
    };

    window.sjaLoadQR = function (eid, aid) {
        $.post(AJAXURL, { action: 'sp_jury_get_qr_url', nonce: NONCE, event_id: eid, aire_id: aid }, function (r) {
            if (!r.success) return;
            $('#qr-' + aid).html('<img src="' + r.data.qr + '" style="width:120px;height:120px;border-radius:6px;"><div class="sja-qr-url">' + r.data.url + '</div>');
        });
    };

    /* ════════════════════════════════════════════════════════
       ÉTAPE 5 — CENTRALE LIVE
    ════════════════════════════════════════════════════════ */

    function loadCentrale() {
        var overrides = {};
        $('.sja-override-grade').each(function () {
            var v = $.trim($(this).val());
            if (v) overrides[$(this).data('eleve')] = v;
        });
        $.post(AJAXURL, { action: 'sp_jury_get_centrale', nonce: NONCE, event_id: EID, grade_overrides: overrides }, function (r) {
            if (!r || !r.success) return;
            $('#sja-centrale-ts').text('Actualisé ' + new Date().toLocaleTimeString('fr-FR'));
            renderCentrale(r.data);
        });
    }

    function renderCentrale(d) {
        var aff    = d.affectations || [];
        var scores = d.scores       || {};
        var aires  = d.aires        || [];
        var notes  = d.notes        || {};
        if (!aff.length) { $('#sja-centrale-body').html('<p style="color:#9ca3af;">Aucun candidat affecté.</p>'); return; }
        var aireMap = {};
        aires.forEach(function (a) { aireMap[a.id] = a.label || 'Aire ' + a.numero; });
        var html = '';

        if (modeSession === 'par_epreuve') {
            var vusIds = {};
            var affUniq = aff.filter(function (c) {
                if (vusIds[c.eleve_id]) return false;
                vusIds[c.eleve_id] = true; return true;
            });
            html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">'
                + '<thead style="background:#f8fafc;"><tr>'
                + '<th style="padding:8px 12px;text-align:left;border:1px solid #e5e7eb;">Candidat</th>'
                + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Catégorie</th>'
                + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Grade actuel</th>';
            aires.forEach(function (a) {
                html += '<th style="padding:8px 12px;border:1px solid #e5e7eb;">' + esc(a.label || 'Aire ' + a.numero) + '</th>';
            });
            html += '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Score</th>'
                + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Grade visé</th>'
                + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Verdict</th>'
                + '</tr></thead><tbody>';
            affUniq.forEach(function (c) {
                var sc    = scores[c.eleve_id] || {};
                var score = (sc.score !== null && sc.score !== undefined) ? parseFloat(sc.score).toFixed(1) : '—';
                var cls   = sc.admis === true ? 'admis' : (sc.admis === false ? 'ajourne' : '');
                var vTxt  = sc.admis === true ? '✅ Admis' : (sc.admis === false ? '❌ Ajourné' : '—');
                var ga    = esc(sc.grade_auto || '—');
                var gv    = esc(sc.grade_vise || ga);
                html += '<tr style="border-bottom:1px solid #f1f5f9;' + (cls === 'admis' ? 'background:#f0fdf4;' : cls === 'ajourne' ? 'background:#fef2f2;' : '') + '">'
                    + '<td style="padding:8px 12px;border:1px solid #f1f5f9;font-weight:600;">' + esc(c.prenom + ' ' + c.nom.toUpperCase()) + '</td>'
                    + '<td style="padding:8px 12px;border:1px solid #f1f5f9;color:#64748b;">' + esc(c.categorie_age || '') + '</td>'
                    + '<td style="padding:8px 12px;border:1px solid #f1f5f9;">' + esc(c.grade || '—') + '</td>';
                aires.forEach(function (a) {
                    var epId    = a.epreuve_id;
                    var notesEp = (notes[c.eleve_id] || {})[epId] || {};
                    var vals    = Object.values(notesEp).map(Number);
                    var noteAff = vals.length ? (vals.reduce(function (s, v) { return s + v; }, 0) / vals.length).toFixed(1) : '—';
                    var color   = vals.length ? 'color:#111;' : 'color:#9ca3af;';
                    html += '<td style="padding:8px 12px;border:1px solid #f1f5f9;text-align:center;' + color + 'font-weight:600;">' + noteAff + '</td>';
                });
                html += '<td style="padding:8px 12px;border:1px solid #f1f5f9;font-weight:800;text-align:center;">' + score + '</td>'
                    + '<td style="padding:8px 12px;border:1px solid #f1f5f9;">'
                    + '<span title="Auto: ' + ga + '">' + gv + (sc.overridden ? ' ✏️' : '') + '</span><br>'
                    + '<input type="text" class="sja-override-grade" data-eleve="' + c.eleve_id + '" value="' + (sc.overridden ? gv : '') + '" placeholder="' + ga + '" style="width:90px;font-size:11px;border:1px dashed #d1d5db;border-radius:4px;padding:2px 5px;">'
                    + '</td>'
                    + '<td style="padding:8px 12px;border:1px solid #f1f5f9;font-weight:700;">' + vTxt + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table></div>';
        } else {
            html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;">'
                + '<thead style="background:#f8fafc;"><tr>'
                + '<th style="padding:8px 12px;text-align:left;border:1px solid #e5e7eb;">Candidat</th>'
                + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Catégorie</th>'
                + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Grade actuel</th>'
                + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Aire</th>'
                + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Score</th>'
                + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Grade visé</th>'
                + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Verdict</th>'
                + '</tr></thead><tbody>';
            aff.forEach(function (c) {
                var sc    = scores[c.eleve_id] || {};
                var score = (sc.score !== null && sc.score !== undefined) ? parseFloat(sc.score).toFixed(1) : '—';
                var cls   = sc.admis === true ? 'admis' : (sc.admis === false ? 'ajourne' : '');
                var vTxt  = sc.admis === true ? '✅ Admis' : (sc.admis === false ? '❌ Ajourné' : '—');
                var aire  = esc(aireMap[c.aire_id] || '—');
                var ga    = esc(sc.grade_auto || '—');
                var gv    = esc(sc.grade_vise || ga);
                html += '<tr style="border-bottom:1px solid #f1f5f9;' + (cls === 'admis' ? 'background:#f0fdf4;' : cls === 'ajourne' ? 'background:#fef2f2;' : '') + '">'
                    + '<td style="padding:8px 12px;border:1px solid #f1f5f9;font-weight:600;">' + esc(c.prenom + ' ' + c.nom.toUpperCase()) + '</td>'
                    + '<td style="padding:8px 12px;border:1px solid #f1f5f9;color:#64748b;">' + esc(c.categorie_age || '') + '</td>'
                    + '<td style="padding:8px 12px;border:1px solid #f1f5f9;">' + esc(c.grade || '—') + '</td>'
                    + '<td style="padding:8px 12px;border:1px solid #f1f5f9;">' + aire + '</td>'
                    + '<td style="padding:8px 12px;border:1px solid #f1f5f9;font-weight:800;text-align:center;">' + score + '</td>'
                    + '<td style="padding:8px 12px;border:1px solid #f1f5f9;">'
                    + '<span title="Auto: ' + ga + '">' + gv + (sc.overridden ? ' ✏️' : '') + '</span><br>'
                    + '<input type="text" class="sja-override-grade" data-eleve="' + c.eleve_id + '" value="' + (sc.overridden ? gv : '') + '" placeholder="' + ga + '" style="width:90px;font-size:11px;border:1px dashed #d1d5db;border-radius:4px;padding:2px 5px;">'
                    + '</td>'
                    + '<td style="padding:8px 12px;border:1px solid #f1f5f9;font-weight:700;">' + vTxt + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table></div>';
        }

        if (d.non_affectes && d.non_affectes.length) {
            html += '<div style="margin-top:12px;padding:10px 14px;background:#fef9c3;border:1px solid #fbbf24;border-radius:8px;font-size:12px;color:#854d0e;">'
                + '⚠️ ' + d.non_affectes.length + ' élève(s) non affecté(s) à une aire.</div>';
        }
        $('#sja-centrale-body').html(html);
    }

    if ($('#sja-refresh-centrale').length) {
        $('#sja-refresh-centrale').on('click', loadCentrale);
        loadCentrale();
        if (supportNotation === 'numerique' && statutInitial === 'en_cours') {
            centraleTimer = setInterval(loadCentrale, 5000);
            $('#sja-auto-centrale').prop('checked', true);
        } else {
            $('#sja-auto-centrale').prop('checked', false);
            if (statutInitial === 'termine') {
                $('#sja-centrale-ts').text('Examen terminé — données figées.');
            }
        }
    }

    $('#sja-auto-centrale').on('change', function () {
        clearInterval(centraleTimer);
        if (this.checked) centraleTimer = setInterval(loadCentrale, 5000);
    });

    /* ════════════════════════════════════════════════════════
       ÉTAPE 5 — SAISIE RAPIDE PAR CANDIDAT (mode papier)
    ════════════════════════════════════════════════════════ */

    var currentCandidat = null;
    var currentAire     = null;
    var notesLocales    = {};
    var coupsLocaux     = {};
    var searchTimer     = null;

    // Onglets
    $('.sja-tab-btn').on('click', function () {
        var tab = $(this).data('tab');
        $('.sja-tab-btn').css({ 'color': '#64748b', 'border-bottom-color': 'transparent', 'font-weight': '600' });
        $(this).css({ 'color': '#2563eb', 'border-bottom': '2px solid #2563eb', 'margin-bottom': '-2px', 'font-weight': '700' });
        $('.sja-tab-content').hide();
        $('#tab-' + tab).show();
    });

    // Recherche candidat
    $('#sja-cand-search').on('input', function () {
        clearTimeout(searchTimer);
        var q = $(this).val().trim();
        if (q.length < 2) { $('#sja-search-results').hide(); return; }
        searchTimer = setTimeout(function () {
            $.post(AJAXURL, {
                action:   'sp_jury_search_candidat',
                nonce:    NONCE,
                event_id: EID,
                q:        q,
                aire_id:  $('#sja-aire-filter').val() || 0,
            }, function (r) {
                if (!r || !r.success || !r.data.length) {
                    $('#sja-search-results').show().html('<div class="sja-search-item" style="color:#94a3b8;cursor:default;">Aucun résultat</div>');
                    return;
                }
                var html = '';
                r.data.forEach(function (c) {
                    html += '<div class="sja-search-item" data-id="' + c.id + '"'
                        + ' data-json=\'' + JSON.stringify(c).replace(/\'/g, "&#39;") + '\'>'
                        + '<div class="si-name">' + esc(c.prenom) + ' ' + esc(c.nom.toUpperCase()) + '</div>'
                        + '<div class="si-cat">' + esc(c.categorie_age) + '</div>'
                        + '<div class="si-grade">' + esc(c.grade) + '</div>'
                        + '</div>';
                });
                $('#sja-search-results').show().html(html);
            });
        }, 300);
    });

    $(document).on('click', '.sja-search-item[data-id]', function () {
        var c = JSON.parse($(this).attr('data-json').replace(/&#39;/g, "'"));
        selectCandidat(c);
        $('#sja-search-results').hide();
        $('#sja-cand-search').val('');
    });

    function selectCandidat(c) {
        currentCandidat = c;
        notesLocales    = {};
        coupsLocaux     = {};

        currentAire = null;
        AIRES_DATA.forEach(function (a) {
            if (parseInt(a.id) === parseInt(c.aire_id)) currentAire = a;
        });
        if (!currentAire && AIRES_DATA.length) currentAire = AIRES_DATA[0];

        var initiales = ((c.prenom || '').charAt(0) + (c.nom || '').charAt(0)).toUpperCase();
        var html = '<div class="sja-candidat-card">'
            + '<div class="cc-avatar">' + esc(initiales) + '</div>'
            + '<div class="cc-info">'
            + '<div class="cc-name">' + esc(c.prenom) + ' ' + esc(c.nom.toUpperCase()) + '</div>'
            + '<div class="cc-meta">' + esc(c.categorie_age) + ' · ' + esc(c.grade) + '</div>'
            + '</div>'
            + (currentAire ? '<div class="cc-aire">' + esc(currentAire.label) + '</div>' : '')
            + (c.zemita_groupe ? '<div class="cc-groupe">⚖️ ' + esc(c.zemita_groupe) + '</div>' : '')
            + '</div>';
        $('#sja-candidat-card').show().html(html);
        renderEpreuves();
        $('#sja-score-bar-rs, #sja-actions-rs').show();
        updateScore();
    }

    function renderEpreuves() {
        if (!currentCandidat || !currentAire) return;
        var cat    = currentCandidat.categorie_age || '';
        var groupe = currentCandidat.zemita_groupe || '';
        var html   = '';
        EPREUVES.forEach(function (ep) {
            var isCoups = (EPREUVES_TYPE[ep.id] === 'coups');
            var seuils  = null;
            if (isCoups && groupe && SEUILS_GRID[ep.id] && SEUILS_GRID[ep.id][groupe] && SEUILS_GRID[ep.id][groupe][cat]) {
                seuils = SEUILS_GRID[ep.id][groupe][cat];
            }
            var typeClass = isCoups ? 'ep-zemita' : 'ep-standard';
            html += '<div class="sja-ep-row ' + typeClass + '" data-epid="' + ep.id + '">'
                + '<div class="sja-ep-name">' + esc(ep.nom)
                + '<span class="sja-ep-badge ' + (isCoups ? 'badge-zemita' : 'badge-standard') + '">'
                + (isCoups ? 'Coups' : '/' + currentAire.note_max)
                + '</span></div>';
            if (isCoups) {
                html += '<div class="sja-coups-wrap">'
                    + '<label>Coups :</label>'
                    + '<input type="number" class="sja-coups-input" data-epid="' + ep.id + '" min="0" step="1" placeholder="0">'
                    + '<div class="sja-coups-result" id="sja-result-' + ep.id + '">—</div>'
                    + '</div>';
                html += seuils
                    ? '<div class="sja-coups-seuils">Ref: ' + seuils.coups_ref + ' coups = 10/10</div>'
                    : '<div class="sja-coups-seuils" style="color:#dc2626;">Seuils non configurés</div>';
            } else {
                html += '<div class="sja-note-wrap">'
                    + '<label>Note :</label>'
                    + '<input type="number" class="sja-note-input-rs" data-epid="' + ep.id + '"'
                    + ' min="' + currentAire.note_min + '" max="' + currentAire.note_max + '" step="0.5" placeholder="0">'
                    + '<span class="sja-note-max">/' + currentAire.note_max + '</span>'
                    + '</div>';
            }
            html += '</div>';
        });
        $('#sja-epreuves-grid').show().html(html);
    }

    function convertCoups(coups, s) {
        var ref      = parseFloat(s.coups_ref    || 0);
        var plancher = parseFloat(s.note_plancher || 3);
        if (ref <= 0) return plancher;
        if (coups <= 0) return 0;
        return Math.round(Math.min(10, (coups / ref) * 10) * 100) / 100;
    }

    $(document).on('input', '.sja-coups-input', function () {
        var epid   = parseInt($(this).data('epid'));
        var coups  = parseFloat($(this).val()) || 0;
        var cat    = currentCandidat ? currentCandidat.categorie_age : '';
        var groupe = currentCandidat ? currentCandidat.zemita_groupe : '';
        var s      = (groupe && SEUILS_GRID[epid] && SEUILS_GRID[epid][groupe] && SEUILS_GRID[epid][groupe][cat])
                     ? SEUILS_GRID[epid][groupe][cat] : null;
        var note   = s ? convertCoups(coups, s) : 0;
        notesLocales[epid] = note;
        coupsLocaux[epid]  = coups;
        $('#sja-result-' + epid).text(note.toFixed(1) + '/10');
        updateScore();
    });

    // Saisie note directe
    $(document).on('input', '.sja-note-input-rs', function () {
        var epid = parseInt($(this).data('epid'));
        var val  = parseFloat($(this).val()) || 0;
        notesLocales[epid] = val;
        updateScore();
    });

    function updateScore() {
        var vals = Object.values(notesLocales);
        if (!vals.length) {
            $('#sja-sp-val').text('—');
            $('#sja-sp-verdict').attr('class', 'sp-verdict sp-verdict-wait').text('En attente');
            return;
        }
        var moy = vals.reduce(function (a, b) { return a + b; }, 0) / vals.length;
        $('#sja-sp-val').text(moy.toFixed(2) + '/10');
        var sa = currentAire ? currentAire.seuil_admission : SEUIL;
        if (moy >= sa) {
            $('#sja-sp-verdict').attr('class', 'sp-verdict sp-verdict-admis').text('✅ Admis');
        } else {
            $('#sja-sp-verdict').attr('class', 'sp-verdict sp-verdict-ajourne').text('❌ Ajourné');
        }
    }

    $('#sja-btn-save').on('click', function () {
        if (!currentCandidat || !Object.keys(notesLocales).length) {
            showMsg('Aucune note saisie.', false); return;
        }
        var $btn = $(this).prop('disabled', true).text('…');
        var reqs = Object.keys(notesLocales).map(function (epid) {
            return $.post(AJAXURL, {
                action:     'sp_jury_save_note_admin',
                nonce:      NONCE,
                event_id:   EID,
                eleve_id:   currentCandidat.id,
                epreuve_id: epid,
                note_val:   notesLocales[epid],
                coups_val:  coupsLocaux[epid] !== undefined ? coupsLocaux[epid] : '',
            });
        });
        $.when.apply($, reqs).done(function () {
            $btn.prop('disabled', false).text('💾 Enregistrer');
            showMsg('✓ Notes enregistrées.', true);
        }).fail(function () {
            $btn.prop('disabled', false).text('💾 Enregistrer');
            showMsg('✗ Erreur.', false);
        });
    });

    $('#sja-btn-reset').on('click', function () {
        currentCandidat = null; currentAire = null; notesLocales = {}; coupsLocaux = {};
        $('#sja-candidat-card,#sja-epreuves-grid,#sja-score-bar-rs,#sja-actions-rs').hide();
        $('#sja-candidat-card,#sja-epreuves-grid').html('');
        $('#sja-save-msg').hide();
        $('#sja-cand-search').val('').focus();
    });

    function showMsg(txt, ok) {
        $('#sja-save-msg').text(txt)
            .attr('class', ok ? 'sja-msg-ok' : 'sja-msg-err')
            .show();
        if (ok) setTimeout(function () { $('#sja-save-msg').fadeOut(); }, 3000);
    }

    /* ════════════════════════════════════════════════════════
       ÉTAPE 5 — VUE TABLEAU (mode papier)
    ════════════════════════════════════════════════════════ */

    var saisieTimer = null;

    $(document).on('change', '.sja-note-input', function () {
        var $inp       = $(this);
        var eleve_id   = $inp.data('eleve');
        var epreuve_id = $inp.data('epreuve');
        var seuil      = parseFloat($inp.data('seuil')) || SEUIL;
        var note_val   = parseFloat($inp.val());
        if (isNaN(note_val)) return;
        note_val = Math.max(NOTE_MIN, Math.min(NOTE_MAX, note_val));
        $inp.val(note_val);

        var $msg = $('#sja-saisie-msg');
        $msg.css('color', '#9ca3af').text('Sauvegarde…');

        $.post(AJAXURL, {
            action:     'sp_jury_save_note_admin',
            nonce:      NONCE,
            event_id:   EID,
            eleve_id:   eleve_id,
            epreuve_id: epreuve_id,
            note_val:   note_val,
        }, function (r) {
            if (r.success) {
                $msg.css('color', '#15803d').text('✓ Note sauvegardée');
                var totalScore = 0; var hasAll = true;
                $('#sja-row-' + eleve_id + ' .sja-note-input').each(function () {
                    var v = parseFloat($(this).val());
                    if (isNaN(v)) { hasAll = false; } else { totalScore += v; }
                });
                if (hasAll) {
                    $('#sja-score-' + eleve_id).text(totalScore.toFixed(1));
                    if (totalScore >= seuil) {
                        $('#sja-verdict-' + eleve_id).html('<span style="color:#15803d;">✅ Admis</span>');
                        $('#sja-row-' + eleve_id).css('background', '#f0fdf4');
                    } else {
                        $('#sja-verdict-' + eleve_id).html('<span style="color:#b91c1c;">❌ Ajourné</span>');
                        $('#sja-row-' + eleve_id).css('background', '#fef2f2');
                    }
                } else {
                    $('#sja-score-' + eleve_id).text('—');
                    $('#sja-verdict-' + eleve_id).html('—');
                    $('#sja-row-' + eleve_id).css('background', '');
                }
                clearTimeout(saisieTimer);
                saisieTimer = setTimeout(function () {
                    $msg.css('color', '#9ca3af').text('Notes sauvegardées automatiquement.');
                }, 3000);
            } else {
                $msg.css('color', '#b91c1c').text('❌ ' + (r.data || 'Erreur'));
            }
        }).fail(function () {
            $msg.css('color', '#b91c1c').text('❌ Erreur réseau');
        });
    });

    /* ════════════════════════════════════════════════════════
       ÉTAPE 7 — PROGRESSION DES GRADES
    ════════════════════════════════════════════════════════ */

    function sgpMakeRow(actuel, suivant) {
        return '<tr>'
            + '<td><input type="text" class="sgp-actuel" value="' + esc(actuel) + '" placeholder="Grade actuel" style="width:140px;border:1px solid #d1d5db;border-radius:5px;padding:4px 8px;"></td>'
            + '<td><input type="text" class="sgp-suivant" value="' + esc(suivant) + '" placeholder="Grade visé" style="width:140px;border:1px solid #d1d5db;border-radius:5px;padding:4px 8px;"></td>'
            + '<td style="text-align:center;"><span style="color:#9ca3af;font-size:11px;">—</span></td>'
            + '<td><button class="sja-btn sja-btn-red sja-btn-sm sgp-del">✕</button></td>'
            + '</tr>';
    }

    window.sgpAddRow = function (grade) {
        $('#sja-prog-body').append(sgpMakeRow(grade || '', ''));
        $('#sja-prog-body tr:last td:first input').focus();
    };

    $('#sgp-add').on('click', function () { window.sgpAddRow(''); });
    $(document).on('click', '.sgp-del', function () { $(this).closest('tr').remove(); });

    $('#sgp-save').on('click', function () {
        var rows = [];
        $('#sja-prog-body tr').each(function () {
            var ga = $.trim($(this).find('.sgp-actuel').val());
            var gs = $.trim($(this).find('.sgp-suivant').val());
            if (ga && gs) rows.push({ grade_actuel: ga, grade_suivant: gs });
        });
        $.post(AJAXURL, { action: 'sp_jury_save_grade_progression', nonce: NONCE, event_id: EID, rows: rows }, function (r) {
            $('#sgp-msg').text(r.success ? '✓ Sauvegardé' : '✗ Erreur')
                .toggleClass('sja-msg-ok', r.success)
                .toggleClass('sja-msg-err', !r.success);
        });
    });

    /* ════════════════════════════════════════════════════════
       ÉTAPE 6 — TRANSCRIPTION DES GRADES
    ════════════════════════════════════════════════════════ */

    var transcriptionData     = null;
    var transcriptionOverrides = {};

    function renderPreview(data) {
        var rows = data.preview || [];
        if (!rows.length) { $('#sja-preview-result').html('<p style="color:#9ca3af;">Aucun candidat.</p>'); return; }

        var html = '<div style="overflow-x:auto;margin-bottom:16px;">'
            + '<p style="font-size:12px;color:#64748b;margin-bottom:8px;">'
            + '💡 Le grade à écrire est pré-rempli automatiquement. Modifiez-le directement si nécessaire (cas exceptionnel).'
            + '</p>'
            + '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
            + '<thead style="background:#f8fafc;"><tr>'
            + '<th style="padding:8px 12px;border:1px solid #e5e7eb;text-align:left;">Candidat</th>'
            + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Grade actuel</th>'
            + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Score</th>'
            + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Verdict</th>'
            + '<th style="padding:8px 12px;border:1px solid #e5e7eb;">Grade à écrire <span style="font-weight:400;font-size:11px;">(modifiable)</span></th>'
            + '</tr></thead><tbody>';

        rows.forEach(function (r) {
            var rowBg   = r.admis ? '#f0fdf4' : '#fef2f2';
            var verdict = r.admis
                ? '<span style="color:#16a34a;font-weight:700;">✅ Admis</span>'
                : '<span style="color:#dc2626;font-weight:700;">❌ Ajourné</span>';
            if (r.score === null) verdict = '<span style="color:#9ca3af;">— Sans note</span>';

            var curOverride = transcriptionOverrides[r.eleve_id];
            var fieldVal    = curOverride !== undefined ? curOverride : (r.ecrire ? esc(r.grade_nouveau) : '');
            var fieldStyle  = 'width:140px;font-size:13px;border:1px solid;border-radius:6px;padding:5px 9px;font-weight:700;';
            if (r.admis && fieldVal)       { fieldStyle += 'border-color:#86efac;background:#f0fdf4;color:#16a34a;'; }
            else if (!r.admis && fieldVal) { fieldStyle += 'border-color:#f59e0b;background:#fffbeb;color:#92400e;'; }
            else if (!r.admis)             { fieldStyle += 'border-color:#fca5a5;background:#fef2f2;color:#9ca3af;'; }
            else                           { fieldStyle += 'border-color:#d1d5db;background:#fff;'; }

            var modifiedBadge = (curOverride !== undefined && curOverride !== '' && curOverride !== r.grade_auto)
                ? ' <span style="font-size:10px;background:#fef9c3;color:#854d0e;padding:1px 5px;border-radius:4px;">✏️ modifié</span>'
                : '';
            var forcingBadge = (!r.admis && fieldVal)
                ? ' <span style="font-size:10px;background:#fef9c3;color:#854d0e;padding:1px 5px;border-radius:4px;">⚡ forcing</span>'
                : '';
            var placeholder = r.admis ? esc(r.grade_auto || '—') : 'Forcing (optionnel)';
            var ficheUrl    = AJAXURL.replace('admin-ajax.php', 'admin.php')
                            + '?page=sp-cal-eleves&sp_edit_eleve=' + r.eleve_id;

            html += '<tr style="border-bottom:1px solid #f1f5f9;background:' + rowBg + '">'
                + '<td style="padding:8px 12px;border:1px solid #f1f5f9;font-weight:600;">'
                + '<a href="' + ficheUrl + '" target="_blank" style="color:inherit;text-decoration:none;border-bottom:1px dashed #94a3b8;" title="Ouvrir la fiche élève">'
                + esc(r.prenom + ' ' + r.nom.toUpperCase())
                + '</a> ' + modifiedBadge + forcingBadge + '</td>'
                + '<td style="padding:8px 12px;border:1px solid #f1f5f9;text-align:center;">' + esc(r.grade_actuel || '—') + '</td>'
                + '<td style="padding:8px 12px;border:1px solid #f1f5f9;text-align:center;font-weight:700;">'
                + (r.score !== null ? parseFloat(r.score).toFixed(1) : '—') + '</td>'
                + '<td style="padding:8px 12px;border:1px solid #f1f5f9;text-align:center;">' + verdict + '</td>'
                + '<td style="padding:8px 12px;border:1px solid #f1f5f9;">'
                + '<input type="text" class="sja-override-input" data-eleve="' + r.eleve_id
                + '" data-grade-auto="' + esc(r.grade_auto || '') + '"'
                + ' data-admis="' + (r.admis ? '1' : '0') + '"'
                + ' value="' + fieldVal + '"'
                + ' placeholder="' + placeholder + '"'
                + ' style="' + fieldStyle + '">'
                + '</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';

        var nbAdmis    = rows.filter(function (r) { return r.admis; }).length;
        var nbAjourne  = rows.filter(function (r) { return !r.admis && r.score !== null; }).length;
        var nbSansNote = rows.filter(function (r) { return r.score === null; }).length;
        html += '<div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">'
            + '<div style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;padding:10px 16px;font-size:13px;font-weight:700;color:#14532d;">✅ ' + nbAdmis + ' admis</div>'
            + '<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 16px;font-size:13px;font-weight:700;color:#7f1d1d;">❌ ' + nbAjourne + ' ajourné(s)</div>'
            + (nbSansNote ? '<div style="background:#f3f4f6;border:1px solid #d1d5db;border-radius:8px;padding:10px 16px;font-size:13px;color:#6b7280;">⚠️ ' + nbSansNote + ' sans note</div>' : '')
            + '</div>';

        if (data.snapshot) {
            html += '<details style="margin-bottom:16px;">'
                + '<summary style="cursor:pointer;font-size:12px;font-weight:700;color:#64748b;padding:6px 0;">🗄️ Snapshot SQL (sauvegarde des grades actuels — à conserver)</summary>'
                + '<textarea readonly style="width:100%;height:120px;font-family:monospace;font-size:11px;border:1px solid #d1d5db;border-radius:6px;padding:8px;background:#f8fafc;margin-top:6px;">'
                + esc(data.snapshot) + '</textarea></details>';
        }

        html += '<div style="background:#fff3cd;border:2px solid #ffc107;border-radius:10px;padding:16px;text-align:center;">'
            + '<p style="margin:0 0 12px;font-size:13px;font-weight:700;color:#856404;">'
            + '⚠️ Une fois écrit, le grade ne se modifie pas automatiquement — mais vous pouvez toujours le corriger manuellement depuis la fiche individuelle de chaque élève.'
            + ' Les élèves ajournés avec un grade saisi (forcing) seront également mis à jour.'
            + ' Le snapshot SQL ci-dessus permet une restauration complète si besoin.</p>'
            + '<label style="display:inline-flex;align-items:center;gap:8px;margin-bottom:12px;font-size:13px;cursor:pointer;">'
            + '<input type="checkbox" id="sja-confirm-write"> Je confirme vouloir écrire les grades en fiche élève</label><br>'
            + '<button class="sja-btn sja-btn-green" id="sja-write-grades" disabled style="font-size:15px;padding:10px 28px;">💾 Écrire les grades en fiche</button>'
            + '<span class="sja-msg" id="sja-write-msg" style="display:block;margin-top:8px;"></span>'
            + '</div>';

        $('#sja-preview-result').html(html);
        transcriptionData = data;
    }

    function collectOverrides() {
        var ov = {};
        $('.sja-override-input').each(function () {
            var v   = $.trim($(this).val());
            var eid = $(this).data('eleve');
            if (v !== undefined) ov[eid] = v;
        });
        return ov;
    }

    $('#sja-preview-grades').on('click', function () {
        transcriptionOverrides = collectOverrides();
        var $btn = $(this).prop('disabled', true).text('Chargement…');
        $.post(AJAXURL, {
            action:    'sp_jury_preview_grades',
            nonce:     NONCE,
            event_id:  EID,
            overrides: transcriptionOverrides,
        }, function (r) {
            $btn.prop('disabled', false).text('🔍 Prévisualiser');
            if (r.success) renderPreview(r.data);
            else alert('Erreur : ' + (r.data || ''));
        });
    });

    $(document).on('input', '.sja-override-input', function () {
        var $inp  = $(this);
        var val   = $.trim($inp.val());
        var auto  = $inp.data('grade-auto') || '';
        var admis = $inp.data('admis') === '1' || $inp.data('admis') === 1;
        var $name = $inp.closest('tr').find('td:first');
        $name.find('.sja-mod-badge,.sja-forcing-badge').remove();
        if (!admis && val) {
            $name.append('<span class="sja-forcing-badge" style="font-size:10px;background:#fef9c3;color:#854d0e;padding:1px 5px;border-radius:4px;margin-left:4px;">⚡ forcing</span>');
            $inp.css({ 'border-color': '#f59e0b', 'background': '#fffbeb', 'color': '#92400e' });
        } else if (val && val !== auto) {
            $name.append('<span class="sja-mod-badge" style="font-size:10px;background:#fef9c3;color:#854d0e;padding:1px 5px;border-radius:4px;margin-left:4px;">✏️ modifié</span>');
            $inp.css({ 'border-color': '#fbbf24', 'background': '#fffbeb', 'color': '#92400e' });
        } else if (admis) {
            $inp.css({ 'border-color': '#86efac', 'background': '#f0fdf4', 'color': '#16a34a' });
        } else {
            $inp.css({ 'border-color': '#fca5a5', 'background': '#fef2f2', 'color': '#9ca3af' });
        }
        transcriptionOverrides = collectOverrides();
    });

    $(document).on('change', '#sja-confirm-write', function () {
        $('#sja-write-grades').prop('disabled', !this.checked);
    });

    $(document).on('click', '#sja-write-grades', function () {
        if (!$('#sja-confirm-write').is(':checked')) return;
        transcriptionOverrides = collectOverrides();
        var $btn = $(this).prop('disabled', true).text('Écriture en cours…');
        $.post(AJAXURL, {
            action:    'sp_jury_write_grades',
            nonce:     NONCE,
            event_id:  EID,
            confirmed: 1,
            overrides: transcriptionOverrides,
        }, function (r) {
            if (r.success) {
                $btn.text('✅ Fait');
                $('#sja-write-msg').html(
                    '<strong style="color:#16a34a;">' + r.data.msg + '</strong>'
                    + ' — <a href="?page=sp-cal-jury&event_id=' + EID + '&step=6">Rafraîchir</a>'
                );
                $('#sja-confirm-write').prop('disabled', true);
            } else {
                $btn.prop('disabled', false).text('💾 Écrire les grades en fiche');
                $('#sja-write-msg').html('<span style="color:#dc2626;">✗ ' + JSON.stringify(r.data || 'Erreur inconnue') + '</span>');
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false).text('💾 Écrire les grades en fiche');
            $('#sja-write-msg').html('<span style="color:#dc2626;">✗ Erreur HTTP ' + xhr.status + ' : ' + xhr.responseText.substring(0, 200) + '</span>');
        });
    });

    /* ════════════════════════════════════════════════════════
       ÉTAPE 7 — RESSOURCES PÉDAGOGIQUES
    ════════════════════════════════════════════════════════ */

    function sgcMakeLienRow(l) {
        l = l || {};
        return '<div class="sgc-lien-row" style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap;">'
            + '<select class="sgc-lien-type" style="border:1px solid #d1d5db;border-radius:5px;padding:4px 6px;font-size:12px;">'
            + '<option value="lien"'  + (l.type === 'lien'  ? ' selected' : '') + '>🔗 Lien</option>'
            + '<option value="video"' + (l.type === 'video' ? ' selected' : '') + '>🎥 Vidéo</option>'
            + '<option value="image"' + (l.type === 'image' ? ' selected' : '') + '>🖼️ Image</option>'
            + '<option value="pdf"'   + (l.type === 'pdf'   ? ' selected' : '') + '>📄 PDF</option>'
            + '</select>'
            + '<input type="text" class="sgc-lien-label" placeholder="Libellé" value="' + esc(l.label || '') + '" style="width:140px;border:1px solid #d1d5db;border-radius:5px;padding:4px 8px;font-size:12px;">'
            + '<input type="text" class="sgc-lien-url" placeholder="URL" value="' + esc(l.url || '') + '" style="flex:1;min-width:200px;border:1px solid #d1d5db;border-radius:5px;padding:4px 8px;font-size:12px;">'
            + '<button class="sja-btn sja-btn-red sja-btn-sm sgc-del-lien">✕</button>'
            + '</div>';
    }

    $('#sgc-add-lien').on('click', function () { $('#sgc-liens-wrap').append(sgcMakeLienRow()); });
    $(document).on('click', '.sgc-del-lien', function () { $(this).closest('.sgc-lien-row').remove(); });

    $('#sgc-load').on('click', function () {
        var grade = $('#sgc-grade-sel').val();
        if (!grade) { alert('Sélectionnez un grade.'); return; }
        $.post(AJAXURL, { action: 'sp_jury_get_grade_contenu', nonce: NONCE, grade_actuel: grade }, function (r) {
            if (!r.success) return;
            var d = r.data;
            $('#sgc-desc').val(d.description || '');
            $('#sgc-liens-wrap').empty();
            (d.liens || []).forEach(function (l) { $('#sgc-liens-wrap').append(sgcMakeLienRow(l)); });
            $('#sgc-editor').show();
            $('#sgc-msg').text('');
        });
    });

    $('#sgc-save').on('click', function () {
        var grade = $('#sgc-grade-sel').val();
        if (!grade) return;
        var liens = [];
        $('.sgc-lien-row').each(function () {
            var url = $.trim($(this).find('.sgc-lien-url').val());
            if (!url) return;
            liens.push({
                type:  $(this).find('.sgc-lien-type').val(),
                label: $.trim($(this).find('.sgc-lien-label').val()),
                url:   url,
            });
        });
        $.post(AJAXURL, {
            action:       'sp_jury_save_grade_contenu',
            nonce:        NONCE,
            grade_actuel: grade,
            description:  $('#sgc-desc').val(),
            liens:        liens,
        }, function (r) {
            $('#sgc-msg').text(r.success ? '✓ Sauvegardé' : '✗ Erreur')
                .css('color', r.success ? '#16a34a' : '#dc2626');
        });
    });

}(jQuery));

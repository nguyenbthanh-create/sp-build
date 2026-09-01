/**
 * SP_Cal — Jury Step 6 : Transcription des grades
 * Fichier : assets/js/jury-step6.js
 */
(function ($) {

    var AJAXURL  = window.spJury6 ? spJury6.ajaxurl  : '';
    var NONCE    = window.spJury6 ? spJury6.nonce     : '';
    var EID      = window.spJury6 ? spJury6.eid       : 0;

    var transcriptionOverrides = {};

    /* ── Utilitaire ── */
    function esc(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /* ── Collecter les overrides ── */
    function collectOverrides() {
        var ov = {};
        $('.sja-override-input').each(function () {
            var v   = $.trim($(this).val());
            var eid = $(this).data('eleve');
            if (v !== undefined) ov[eid] = v;
        });
        return ov;
    }

    /* ── Rendu du tableau de prévisualisation ── */
    function renderPreview(data) {
        var rows = data.preview || [];
        if (!rows.length) {
            $('#sja-preview-result').html('<p style="color:#9ca3af;">Aucun candidat.</p>');
            return;
        }

        var html = '<div style="overflow-x:auto;margin-bottom:16px;">'
            + '<p style="font-size:12px;color:#64748b;margin-bottom:8px;">'
            + '💡 Le grade à écrire est pré-rempli automatiquement. Modifiez-le si nécessaire.'
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
                + '<a href="' + ficheUrl + '" target="_blank" style="color:inherit;text-decoration:none;border-bottom:1px dashed #94a3b8;">'
                + esc(r.prenom + ' ' + r.nom.toUpperCase())
                + '</a> ' + modifiedBadge + forcingBadge + '</td>'
                + '<td style="padding:8px 12px;border:1px solid #f1f5f9;text-align:center;">' + esc(r.grade_actuel || '—') + '</td>'
                + '<td style="padding:8px 12px;border:1px solid #f1f5f9;text-align:center;font-weight:700;">'
                + (r.score !== null ? parseFloat(r.score).toFixed(1) : '—') + '</td>'
                + '<td style="padding:8px 12px;border:1px solid #f1f5f9;text-align:center;">' + verdict + '</td>'
                + '<td style="padding:8px 12px;border:1px solid #f1f5f9;">'
                + '<input type="text" class="sja-override-input"'
                + ' data-eleve="' + r.eleve_id + '"'
                + ' data-grade-auto="' + esc(r.grade_auto || '') + '"'
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
                + '<summary style="cursor:pointer;font-size:12px;font-weight:700;color:#64748b;padding:6px 0;">🗄️ Snapshot SQL</summary>'
                + '<textarea readonly style="width:100%;height:120px;font-family:monospace;font-size:11px;border:1px solid #d1d5db;border-radius:6px;padding:8px;background:#f8fafc;margin-top:6px;">'
                + esc(data.snapshot) + '</textarea></details>';
        }

        html += '<div style="background:#fff3cd;border:2px solid #ffc107;border-radius:10px;padding:16px;text-align:center;">'
            + '<p style="margin:0 0 12px;font-size:13px;font-weight:700;color:#856404;">'
            + '⚠️ Une fois écrit, le grade ne se modifie pas automatiquement. Les élèves ajournés avec un grade saisi (forcing) seront également mis à jour.'
            + '</p>'
            + '<label style="display:inline-flex;align-items:center;gap:8px;margin-bottom:12px;font-size:13px;cursor:pointer;">'
            + '<input type="checkbox" id="sja-confirm-write"> Je confirme vouloir écrire les grades en fiche élève</label><br>'
            + '<button class="sja-btn sja-btn-green" id="sja-write-grades" disabled style="font-size:15px;padding:10px 28px;">💾 Écrire les grades en fiche</button>'
            + '<span class="sja-msg" id="sja-write-msg" style="display:block;margin-top:8px;"></span>'
            + '</div>';

        $('#sja-preview-result').html(html);
    }

    /* ── Bouton Prévisualiser ── */
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

    /* ── Override input : feedback visuel ── */
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

    /* ── Confirmation écriture ── */
    $(document).on('change', '#sja-confirm-write', function () {
        $('#sja-write-grades').prop('disabled', !this.checked);
    });

    /* ── Bouton Écrire les grades ── */
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
                $('#sja-write-msg').html('<span style="color:#dc2626;">✗ ' + JSON.stringify(r.data || 'Erreur') + '</span>');
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false).text('💾 Écrire les grades en fiche');
            $('#sja-write-msg').html('<span style="color:#dc2626;">✗ Erreur HTTP ' + xhr.status + '</span>');
        });
    });

}(jQuery));

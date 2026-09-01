/* global SpCalAdmin, jQuery */
(function ($) {
    'use strict';

    /* ── CSV Import ──────────────────────────────────── */
    $('#sp-csv-btn').on('click', function (e) {
        e.preventDefault();
        var file = document.getElementById('sp-csv-file');
        if (!file || !file.files.length) { alert('Veuillez sélectionner un fichier CSV.'); return; }

        var btn  = $(this);
        var form = new FormData();
        form.append('action', 'sp_cal_import_csv');
        form.append('nonce',  SpCalAdmin.nonce);
        form.append('sp_csv_file', file.files[0]);

        btn.prop('disabled', true).text('Import en cours…');

        $.ajax({
            url: SpCalAdmin.ajaxurl, type: 'POST',
            data: form, processData: false, contentType: false,
            success: function (res) {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-database-import"></span> Importer');
                var box = $('#sp-csv-result');
                box.show().html(res.success ? res.data : '❌ ' + res.data);
                if (res.success) setTimeout(function(){ location.reload(); }, 2500);
            },
            error: function () {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-database-import"></span> Importer');
                $('#sp-csv-result').show().html('❌ Erreur serveur.');
            }
        });
    });

    /* ── Sync SportPress ─────────────────────────────── */
    $('#sp-sync-btn').on('click', function () {
        var btn = $(this);
        btn.prop('disabled', true).text('Synchronisation…');
        $.post(SpCalAdmin.ajaxurl, { action: 'sp_cal_import_sp', nonce: SpCalAdmin.nonce }, function (res) {
            btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Lancer la synchronisation');
            $('#sp-sync-result').show().html(res.success ? '✅ ' + res.data : '❌ ' + res.data);
            if (res.success) setTimeout(function(){ location.reload(); }, 2500);
        });
    });

    /* ── Onglets personnes ───────────────────────────── */
    $(document).on('click', '.sp-ptab-btn', function () {
        var target = $(this).data('tab');
        $('.sp-ptab-btn').removeClass('active');
        $(this).addClass('active');
        $('.sp-ptab-content').removeClass('active');
        $('#' + target).addClass('active');
    });

    /* ── Fiche élève : lier à un examen ─────────────── */
    $(document).on('click', '#sp-link-exam-btn', function () {
        var eventId = $('#sp-link-exam-select').val();
        var grade   = $('#sp-link-exam-grade').val().trim();
        var eleveId = $(this).data('eleve-id');
        if (!eventId) { alert('Choisissez un examen.'); return; }

        var btn = $(this).prop('disabled', true).text('…');
        $.post(SpCalAdmin.ajaxurl, {
            action:   'sp_cal_link_examen',
            nonce:    SpCalAdmin.nonce,
            eleve_id: eleveId,
            event_id: eventId,
            note:     grade,
        }, function (res) {
            if (!res.success) {
                btn.prop('disabled', false).text('Lier');
                alert('Erreur : ' + res.data);
                return;
            }
            // Recharger pour rafraîchir grade actuel + historique unifié
            btn.text('✅ Lien ajouté, rechargement…');
            location.reload();
        });
    });

    /* ── Fiche élève : modifier le grade d'un passage calendrier ── */
    $(document).on('click', '.sp-btn-edit-exam', function () {
        var eventId = $(this).data('event-id');
        var grade   = $(this).data('grade') || '';
        $('#sp-edit-exam-event-id').val(eventId);
        $('#sp-edit-exam-grade').val(grade);
        $('#sp-edit-exam-msg').text('');
        $('#sp-edit-exam-form').slideDown(150);
        $('#sp-edit-exam-grade').focus();
    });

    $(document).on('click', '#sp-edit-exam-cancel', function () {
        $('#sp-edit-exam-form').slideUp(150);
    });

    $(document).on('click', '#sp-edit-exam-save', function () {
        var eventId = $('#sp-edit-exam-event-id').val();
        var grade   = $('#sp-edit-exam-grade').val().trim();
        var eleveId = $(this).data('eleve-id');
        if (!eventId) { alert('Passage introuvable.'); return; }
        var $btn = $(this).prop('disabled', true).text('…');
        $.post(SpCalAdmin.ajaxurl, {
            action:   'sp_cal_link_examen',
            nonce:    SpCalAdmin.nonce,
            eleve_id: eleveId,
            event_id: eventId,
            note:     grade,
        }, function (res) {
            if (!res.success) {
                $btn.prop('disabled', false).text('💾 Sauvegarder');
                alert('Erreur : ' + res.data);
                return;
            }
            $('#sp-edit-exam-msg').text('✅ Grade mis à jour, rechargement…');
            setTimeout(function () { location.reload(); }, 800);
        });
    });

    /* ── Fiche élève : délier un examen ──────────────── */
    $(document).on('click', '.sp-btn-unlink-exam', function () {
        var isCurrent = $(this).data('is-current') === 1 || $(this).data('is-current') === '1';
        var gradeActuel = $(this).data('grade-actuel') || '';
        var msg = 'Supprimer ce passage de grade ?';
        if (isCurrent && gradeActuel) {
            msg += '\n\n⚠️ Attention : le grade "' + gradeActuel + '" est le grade actuel de cet élève. '
                 + 'Supprimer ce passage ne modifie pas automatiquement son grade en fiche.';
        }
        if (!confirm(msg)) return;
        var eventId = $(this).data('event-id');
        // eleve_id stocké sur le container, toujours présent quelle que soit la page
        var eleveId = $(this).closest('[data-eleve-id]').data('eleve-id');
        if (!eleveId) { alert('Impossible de trouver l\'identifiant élève.'); return; }
        var btn = $(this).prop('disabled', true);
        $.post(SpCalAdmin.ajaxurl, {
            action:   'sp_cal_unlink_examen',
            nonce:    SpCalAdmin.nonce,
            eleve_id: eleveId,
            event_id: eventId,
        }, function (res) {
            if (!res.success) { btn.prop('disabled', false); alert('Erreur.'); return; }
            location.reload();
        });
    });

    /* ── Fiche élève : supprimer un grade CSV ────────── */
    $(document).on('click', '.sp-btn-delete-csv-grade', function () {
        var dateKey = $(this).data('date-key');
        if (!confirm('Supprimer le grade du ' + dateKey + ' ? Cette action est irréversible.')) return;
        var eleveId = $(this).closest('[data-eleve-id]').data('eleve-id');
        if (!eleveId) { alert('Identifiant élève introuvable.'); return; }
        var btn = $(this).prop('disabled', true);
        $.post(SpCalAdmin.ajaxurl, {
            action:   'sp_cal_delete_csv_grade',
            nonce:    SpCalAdmin.nonce,
            eleve_id: eleveId,
            date_key: dateKey,
        }, function (res) {
            if (!res.success) { btn.prop('disabled', false); alert('Erreur : ' + res.data); return; }
            location.reload();
        });
    });

    /* ── Fiche élève : modifier un grade CSV ─────────── */
    $(document).on('click', '.sp-btn-edit-csv-grade', function () {
        var dateKey  = $(this).data('date-key');
        var gradeVal = $(this).data('grade');
        var eleveId  = $(this).closest('[data-eleve-id]').data('eleve-id');
        if (!eleveId) { alert('Identifiant élève introuvable.'); return; }

        var newGrade = prompt('Grade obtenu (' + dateKey + ') :', gradeVal);
        if (newGrade === null || newGrade.trim() === '') return;

        var btn = $(this).prop('disabled', true);
        $.post(SpCalAdmin.ajaxurl, {
            action:    'sp_cal_edit_csv_grade',
            nonce:     SpCalAdmin.nonce,
            eleve_id:  eleveId,
            date_key:  dateKey,
            new_date:  dateKey,          // date inchangée
            new_grade: newGrade.trim(),
        }, function (res) {
            if (!res.success) { btn.prop('disabled', false); alert('Erreur : ' + res.data); return; }
            location.reload();
        });
    });

    /* ── Fiche élève : lier à une compétition ───────── */
    $(document).on('click', '#sp-link-comp-btn', function () {
        var eventId = $('#sp-link-comp-select').val();
        var eleveId = $(this).data('eleve-id');
        if (!eventId) { alert('Choisissez une compétition.'); return; }

        var btn = $(this).prop('disabled', true).text('…');
        $.post(SpCalAdmin.ajaxurl, {
            action:   'sp_cal_link_comp',
            nonce:    SpCalAdmin.nonce,
            eleve_id: eleveId,
            event_id: eventId,
        }, function (res) {
            if (!res.success) {
                btn.prop('disabled', false).text('Lier');
                alert('Erreur : ' + res.data);
                return;
            }
            btn.text('✅ Lié, rechargement…');
            location.reload();
        });
    });

    /* ── Fiche élève : délier d'une compétition ──────── */
    $(document).on('click', '.sp-btn-unlink-comp', function () {
        if (!confirm('Retirer cet élève de cette compétition ? Ses résultats seront supprimés.')) return;
        var eventId = $(this).data('event-id');
        var eleveId = $(this).closest('[data-eleve-id]').data('eleve-id');
        if (!eleveId) { alert('Identifiant élève introuvable.'); return; }
        var btn = $(this).prop('disabled', true);
        $.post(SpCalAdmin.ajaxurl, {
            action:   'sp_cal_unlink_comp',
            nonce:    SpCalAdmin.nonce,
            eleve_id: eleveId,
            event_id: eventId,
        }, function (res) {
            if (!res.success) { btn.prop('disabled', false); alert('Erreur.'); return; }
            location.reload();
        });
    });

    /* ── Token fiche membre : renvoyer ──────────────── */
    $(document).on('click', '#sp-btn-resend-token', function () {
        var eleveId = $(this).data('eleve-id');
        var btn = $(this).prop('disabled', true).text('Envoi…');
        $.post(SpCalAdmin.ajaxurl, {
            action:   'sp_cal_resend_token',
            nonce:    SpCalAdmin.nonce,
            eleve_id: eleveId,
        }, function (res) {
            btn.prop('disabled', false).text('📧 Renvoyer le lien d\'accès');
            $('#sp-token-result').text(res.success ? '✅ ' + res.data : '❌ ' + res.data).show();
            setTimeout(function(){ $('#sp-token-result').fadeOut(); }, 5000);
        });
    });

    /* ── Token fiche membre : générer tous ───────────── */
    $(document).on('click', '#sp-btn-generate-all-tokens', function () {
        if (!confirm('Générer et envoyer les tokens à tous les élèves actifs sans lien ?')) return;
        var btn = $(this).prop('disabled', true).text('Envoi en cours…');
        $.post(SpCalAdmin.ajaxurl, {
            action: 'sp_cal_generate_all_tokens',
            nonce:  SpCalAdmin.nonce,
        }, function (res) {
            btn.prop('disabled', false).text('📧 Générer et envoyer les tokens manquants');
            $('#sp-all-tokens-result').text(res.success ? '✅ ' + res.data : '❌ ' + res.data).show();
        });
    });

    /* ── Notifications : tester maintenant ──────────── */
    $(document).on('click', '#sp-notif-test-anniv, #sp-notif-test-absences', function () {
        var type = $(this).data('type');
        var $res = $('#sp-notif-result');
        $(this).prop('disabled', true).text('Envoi…');
        var self = this;
        $.post(SpCalAdmin.ajaxurl, {
            action: 'sp_cal_send_notif_now',
            nonce:  SpCalAdmin.nonce,
            type:   type,
        }, function (res) {
            $(self).prop('disabled', false).text(type === 'anniv' ? '🎂 Tester anniversaires' : '⚠️ Tester absences');
            $res.text(res.success ? '✅ ' + res.data : '❌ ' + res.data).show();
            setTimeout(function(){ $res.fadeOut(); }, 5000);
        });
    });

    /* ── Filtre recherche trainers ───────────────────── */
    $('#sp-flt-trainer').on('input', function () {
        var q = $(this).val().toLowerCase();
        $('#sp-trainers-table tbody tr').each(function () {
            $(this).toggle($(this).data('name').indexOf(q) !== -1);
        });
    });

    /* ── Sélection globale (checkboxes) ─────────────── */
    $(document).on('change', '.sp-check-all', function () {
        var checked = $(this).prop('checked');
        $(this).closest('form').find('input[name="sp_bulk_ids[]"]').prop('checked', checked);
    });

    /* ── Emoji Picker (paramètres) ───────────────────── */
    var EMOJIS = [
        '🥋','🏆','⭐','🎂','🎯','💪','👊','🔵','🟣','🟠','🟢','🔴','⚫','🤼','🏅',
        '🥇','🥈','🥉','🎖️','🏋️','🤸','🧘','🏃','⚡','🔥','💥','🌟','✨','🎪','🎭',
        '👦','👧','👨','👩','👴','👵','🧒','🧑','👶','🧑‍🎓','🧑‍🏫',
        '🦁','🐯','🐻','🦊','🐺','🦅','🦋','🌸','🌺','🏠','🏟️','⛩️','🎌','🇫🇷',
        '1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','🅰️','🅱️','🆒','🆕','🆙'
    ];
    var activeEmojiKey = null;

    // Construire la popup avec délégation d'événements (plus robuste que les bindings directs)
    var $popup = $('<div id="sp-emoji-popup" style="display:none;position:fixed;z-index:999999;background:#fff;border:1px solid #ddd;border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:12px;width:300px;"></div>');
    var $grid  = $('<div style="display:flex;flex-wrap:wrap;gap:5px;max-height:220px;overflow-y:auto;"></div>');

    EMOJIS.forEach(function (em) {
        // Stocker l'emoji en data attribute — pas de binding direct sur chaque bouton
        var $b = $('<button type="button" class="sp-emoji-pick-btn" style="font-size:20px;background:none;border:1px solid #eee;border-radius:4px;padding:4px;cursor:pointer;width:36px;height:36px;line-height:1;"></button>')
            .attr('data-em', em)
            .text(em);
        $grid.append($b);
    });

    $popup.append($grid)
          .append('<button type="button" id="sp-emoji-close" class="button" style="margin-top:8px;width:100%;">Fermer</button>');
    $('body').append($popup);

    function closeEmojiPopup() { $popup.hide(); activeEmojiKey = null; }

    // Délégation unique sur le popup — immune aux interférences WordPress admin
    $popup.on('click', '.sp-emoji-pick-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        if (!activeEmojiKey) return;
        var em = $(this).attr('data-em');
        $('#icon_input_' + activeEmojiKey).val(em);
        $('.sp-emoji-btn[data-key="' + activeEmojiKey + '"]').text(em);
        $('#prev_icon_' + activeEmojiKey).text(em + ' ');
        // Afficher/cacher le bouton ✕ selon si une icône est choisie
        var $clearBtn = $('.sp-emoji-clear[data-key="' + activeEmojiKey + '"]');
        if ($clearBtn.length) { $clearBtn.show(); } else {
            $('.sp-emoji-btn[data-key="' + activeEmojiKey + '"]')
                .after('<button type="button" class="sp-emoji-clear button" data-key="' + activeEmojiKey + '" style="margin-left:4px;color:#b91c1c;">✕</button>');
        }
        closeEmojiPopup();
    });

    $popup.on('click', '#sp-emoji-close', function (e) {
        e.preventDefault();
        e.stopPropagation();
        closeEmojiPopup();
    });

    // Hover via délégation (pas d'états hover inline nécessaires)
    $popup.on('mouseenter', '.sp-emoji-pick-btn', function () { $(this).css('background','#f0f0f0'); });
    $popup.on('mouseleave', '.sp-emoji-pick-btn', function () { $(this).css('background','none'); });

    // Gestion ouverture/fermeture via document — stopImmediatePropagation pour bloquer WP admin
    $(document).on('click.emojiPicker', function (e) {
        var $emojiBtn = $(e.target).closest('.sp-emoji-btn');
        var $clearBtn = $(e.target).closest('.sp-emoji-clear');
        var $inPopup  = $(e.target).closest('#sp-emoji-popup');

        if ($emojiBtn.length) {
            e.stopImmediatePropagation();
            activeEmojiKey = $emojiBtn.data('key');
            var rect = $emojiBtn[0].getBoundingClientRect();
            var top  = rect.bottom + 6;
            if (top + 280 > window.innerHeight) top = rect.top - 280 - 6;
            var left = Math.min(rect.left, window.innerWidth - 310);
            $popup.css({ display: 'block', top: top + 'px', left: left + 'px' });
        } else if ($clearBtn.length) {
            e.stopImmediatePropagation();
            var k = $clearBtn.data('key');
            $('#icon_input_' + k).val('');
            $('.sp-emoji-btn[data-key="' + k + '"]').text('＋');
            $('#prev_icon_' + k).text('');
            $clearBtn.remove();
        } else if (!$inPopup.length && $popup.is(':visible')) {
            closeEmojiPopup();
        }
    });

}(jQuery));

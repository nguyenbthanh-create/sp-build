/* global SpCal, jQuery */
/* SP Calendar PRO – calendar.js  v10.15.0 — sondage post-événement */

(function ($) {
    'use strict';

    var CAL = {
        year:   new Date().getFullYear(),
        month:  new Date().getMonth() + 1,
        events: [],
        catColors: {},
        todayStr: (function(){ var t=new Date(); return t.getFullYear()+'-'+String(t.getMonth()+1).padStart(2,'0')+'-'+String(t.getDate()).padStart(2,'0'); })(),

        currentDate:   '',
        editingEvent:  null,
        activeTab:     'details',
        presences:     {},
        notes:         {},      // {eleve_id: 'grade saisi'} — examens
        elevesAll:     [],
        elevesByGroup: {},
        catsSaisie:    [],      // categories saisie pour filtres presences/examen
        catsSaisieAge: [],      // tranches age (cache modale)
        elevesPourCiblage: null, // [{id, nom}] — cache lazy pour "Ajouter des élèves spécifiques"
        inscExtraIds:  [],      // IDs élèves ajoutés manuellement au ciblage de l'événement en cours

        // Disponibilités entraîneurs
        trainersList:  [],      // [{id, nom, nom_public}]
        disposByDate:  {},      // {'YYYY-MM-DD': {trainer_id: {disponible:1/0, note:''}}}
        disposLoaded:  false,   // flag : dispos chargées pour le mois courant
        pendingDispoChanges: {}, // {'YYYY-MM-DD': { trainer_id: {trainer_nom, avant, apres, note} }}

        // Couleurs calendrier — weekends & vacances Zone C
        weColor:  '#f3f4f6',
        vacColor: '#fef9c3',
        vacances: [],           // [{label, start, end}] — périodes vacances Zone C

        // Compétition
        compEpreuves:  [],      // [{id, nom, ordre, resultats:{eleve_id:{medaille,score}}}]
        compEleves:    [],      // [{id, nom, categorie_age, categorie_saisie}]
        compResultats: {},      // {epreuve_id: {eleve_id: {medaille,score}}} — édition en cours

        /* ═══════════════════════════════ INIT */
        init: function () {
            try { CAL.catColors = JSON.parse(SpCal.catColors || '{}'); } catch(e) {}
            try { CAL.vacances  = JSON.parse(SpCal.vacances  || '[]'); } catch(e) {}
            CAL.weColor  = SpCal.weColor  || '#f3f4f6';
            CAL.vacColor = SpCal.vacColor || '#fef9c3';
            var stored = sessionStorage.getItem('sp_cal_ym');
            if (stored) { var p = stored.split('-'); CAL.year = +p[0]; CAL.month = +p[1]; }
            CAL.updateTodayLabel();
            CAL.loadMonth();
            CAL.bindNav();
            if (SpCal.isAdmin === '1') {
                CAL.bindAdmin();
                // Afficher le badge si des annulations groupées sont en attente
                var pending = parseInt(SpCal.annulPending || window._spCalAnnulPending || 0);
                if (pending > 0) CAL.updatePendingBadge(pending);
            }
        },

        /* ═══════════════════════════════ NAVIGATION */
        bindNav: function () {
            $('#cal-prev').on('click', function(){ CAL.navigate(-1); });
            $('#cal-next').on('click', function(){ CAL.navigate(1);  });
            $('#cal-today-btn').on('click', function(){
                CAL.year = new Date().getFullYear(); CAL.month = new Date().getMonth()+1; CAL.loadMonth();
            });
        },
        navigate: function (d) {
            CAL.month += d;
            if (CAL.month > 12){ CAL.month=1; CAL.year++; }
            if (CAL.month < 1) { CAL.month=12; CAL.year--; }
            sessionStorage.setItem('sp_cal_ym', CAL.year+'-'+CAL.month);
            CAL.loadMonth();
        },
        updateTodayLabel: function () {
            $('#cal-today-label').text(new Date().toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long'}));
        },

        /* ═══════════════════════════════ CHARGEMENT */
        loadMonth: function () {
            var M=['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
            $('#cal-title').text(M[CAL.month-1]+' '+CAL.year);
            $('#cal-loading').show(); $('#cal-grid-wrap').hide();
            CAL.disposLoaded = false;
            var evDone = false, dispoDone = false;
            function tryRender() {
                if (evDone && dispoDone) { $('#cal-loading').hide(); $('#cal-grid-wrap').show(); CAL.renderGrid(); }
            }
            $.post(SpCal.ajaxurl, {action:'sp_cal_get_events', nonce:SpCal.nonce, year:CAL.year, month:CAL.month}, function(res){
                if (res.success) { CAL.events = res.data; }
                evDone = true; tryRender();
            }).fail(function(){ evDone = true; tryRender(); });
            $.post(SpCal.ajaxurl, {action:'sp_cal_get_trainer_dispos', nonce:SpCal.nonce, year:CAL.year, month:CAL.month}, function(res){
                if (res.success) {
                    CAL.trainersList = res.data.trainers || [];
                    CAL.disposByDate = res.data.dispos   || {};
                    CAL.disposLoaded = true;
                }
                dispoDone = true; tryRender();
            }).fail(function(){ dispoDone = true; tryRender(); });
        },

        /* ═══════════════════════════════ GRILLE */
        renderGrid: function () {
            var DAYS = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
            var today = new Date();
            var tStr  = today.getFullYear()+'-'+String(today.getMonth()+1).padStart(2,'0')+'-'+String(today.getDate()).padStart(2,'0');
            var firstDow = (new Date(CAL.year,CAL.month-1,1).getDay()+6)%7;
            var lastDay  = new Date(CAL.year,CAL.month,0).getDate();
            var total    = Math.ceil((firstDow+lastDay)/7)*7;

            var header = '<div class="sp-cal-header">'+DAYS.map(function(d){return '<div>'+d+'</div>';}).join('')+'</div>';
            var grid   = '<div class="sp-cal-grid">';
            for (var c=0; c<total; c++) {
                var day   = c-firstDow+1;
                var other = day<1||day>lastDay;
                var ds    = other ? '' : CAL.year+'-'+String(CAL.month).padStart(2,'0')+'-'+String(day).padStart(2,'0');
                var cls   = 'sp-cal-day'+(other?' other-month':'')+(ds===tStr?' today':'');
                // Fond week-end et vacances Zone C
                var cellBg = '';
                if ( !other && ds ) {
                    var dow = new Date(CAL.year, CAL.month-1, day).getDay(); // 0=dim, 6=sam
                    if ( CAL.isVacance(ds) )       { cellBg = CAL.vacColor; }
                    else if ( dow===0 || dow===6 )  { cellBg = CAL.weColor;  }
                }
                grid += '<div class="'+cls+'" data-date="'+ds+'"'+(cellBg?' style="background:'+cellBg+';"':'')+'>'; 

                if (!other) {
                    grid += '<div class="sp-day-num">'+day+'</div>';

                    // ── 1. Entraîneurs : empilés verticalement — uniquement les DISPONIBLES
                    if (CAL.trainersList.length > 0) {
                        var dayDispos = CAL.disposByDate[ds] || {};
                        var trainerHtml = '';
                        CAL.trainersList.forEach(function(t){
                            var d = dayDispos[t.id];
                            // N'afficher que les disponibles (disponible===1) ; masquer indispo et NR
                            if (!d || d.disponible !== 1) return;
                            var nom = (t.nom_public || t.nom);
                            var displayName = nom.length > 12 ? nom.substring(0,11)+'…' : nom;
                            var title = nom + (d.note ? ' — ' + d.note : '');
                            trainerHtml += '<div class="sp-trainer-row sp-trainer-ok" title="'+CAL.esc(title)+'">'
                                +'<span class="sp-trainer-icon">👤</span>'
                                +'<span class="sp-trainer-name">'+CAL.esc(displayName)+'</span>'
                                +(d.note?'<span class="sp-trainer-note-dot" title="'+CAL.esc(d.note)+'">●</span>':'')
                                +'</div>';
                        });
                        if (trainerHtml) grid += '<div class="sp-dispo-block">'+trainerHtml+'</div>';
                    }

                    // ── 2. Événements (cours, events, examens, annulations) ──
                    var eventsHtml = '';
                    var bdayHtml   = '';
                    var shown_annul = {};
                    CAL.events.forEach(function(ev){
                        if (ev.date !== ds) return;
                        var color, prefix, cls2;
                        if      (ev.type==='cours_recurrent') { color=CAL.resolveColor(ev.categorie,'#3B82F6'); prefix=CAL.resolveIcon(ev.categorie,'🔄 '); cls2='sp-evt-pill sp-pill-recurrent'; }
                        else if (ev.type==='annulation')      { if(shown_annul[ev.slot_id])return; shown_annul[ev.slot_id]=true; color='#ef4444'; prefix='🚫 '; cls2='sp-evt-pill sp-pill-annulation'; }
                        else if (ev.type==='cours')           { color=CAL.resolveColor(ev.categorie,'#7c3aed'); prefix=CAL.resolveIcon(ev.categorie,'⚡ '); cls2='sp-evt-pill sp-pill-ponctuel'; }
                        else if (ev.type==='examen')          { color=CAL.resolveColor(ev.categorie,'#b45309'); prefix='🎓 '; cls2='sp-evt-pill sp-evt-pill-examen'; }
                        else if (ev.type==='competition')     { color=CAL.resolveColor(ev.categorie,'#b8860b'); prefix='🏆 '; cls2='sp-evt-pill sp-pill-competition'; }
                        else if (ev.type==='anniversaire')    {
                            // Anniversaires → séparés, traités en bas
                            bdayHtml += '<span class="sp-pill-bday" data-id="'+CAL.esc(String(ev.id))+'">🎂 '+CAL.esc(ev.titre)+'</span>';
                            return;
                        }
                        else                                  { color=CAL.resolveColor(ev.categorie,ev.couleur||'#3B82F6'); prefix='📅 '; cls2='sp-evt-pill'; }
                        var time  = ev.heure_debut ? ev.heure_debut+' ' : '';
                        var label = ev.type==='annulation' ? prefix+CAL.esc(ev.titre) : prefix+time+CAL.esc(ev.titre);
                        eventsHtml += '<span class="'+cls2+'" style="background:'+color+';" data-id="'+CAL.esc(String(ev.id))+'">'+label+'</span>';
                    });
                    grid += eventsHtml;

                    // ── 3. Anniversaires — poussés en bas via wrapper flex ──
                    if (bdayHtml) grid += '<div class="sp-bday-block">'+bdayHtml+'</div>';
                }
                grid += '</div>';
            }
            grid += '</div>';
            $('#cal-grid-wrap').html(header+grid);
        },

        resolveColor: function (cat, fallback) {
            if (cat && CAL.catColors[cat] && CAL.catColors[cat].color) return CAL.catColors[cat].color;
            return fallback || '#3B82F6';
        },

        resolveIcon: function (cat, defaultIcon) {
            if (cat && CAL.catColors[cat] && CAL.catColors[cat].icon) return CAL.catColors[cat].icon + ' ';
            return defaultIcon || '';
        },

        isVacance: function (ds) {
            if (!ds || !CAL.vacances || !CAL.vacances.length) return false;
            for (var i=0; i<CAL.vacances.length; i++) {
                if (ds >= CAL.vacances[i].start && ds <= CAL.vacances[i].end) return true;
            }
            return false;
        },

        /* ═══════════════════════════════ ADMIN BINDINGS */
        bindAdmin: function () {
            $(document).on('click', '.sp-cal-day:not(.other-month)', function(e){
                if ($(e.target).closest('.sp-evt-pill').length) return;
                var date=$(this).data('date'); if(date) CAL.openModalDay(date);
            });
            $(document).on('click', '.sp-evt-pill', function(e){
                e.stopPropagation();
                CAL.openModalDay($(this).closest('.sp-cal-day').data('date'));
            });
            $('#sp-modal-close').on('click', CAL.closeModal);
            $('#sp-event-modal').on('click', function(e){ if($(e.target).is('#sp-event-modal')) CAL.closeModal(); });
            $('#btn-new-event').on('click', function(){ CAL.openModalForm(CAL.currentDate, null); });
            $('#btn-quick-presence').on('click', function(){
                var dayEvs = CAL.events.filter(function(ev){
                    return ev.date === CAL.currentDate && (ev.type==='cours_recurrent' || ev.type==='cours');
                });
                if (dayEvs.length === 0) {
                    // Aucun cours : message inline sans alert()
                    $('#pick-list').html('<p style="color:#b45309;font-size:13px;padding:10px 0;">⚠️ Aucun cours ce jour. Ajoutez d\'abord un cours ou créez un créneau récurrent.</p>');
                    CAL.showView('pick');
                    $('#sp-event-modal').addClass('open');
                } else if (dayEvs.length === 1) {
                    CAL.openPresencesForEvent(dayEvs[0]);
                } else {
                    // Plusieurs cours : afficher le sélecteur intégré dans la modale
                    CAL.showPickView(dayEvs);
                }
            });
            $('#btn-pick-back').on('click', function(){ CAL.showView('day'); });
            $('#btn-back-day').on('click',  function(){ CAL.showView('day'); });
            $(document).on('click','.sp-tab-btn:not(:disabled)', function(){ CAL.switchTab($(this).data('tab')); });
            // Présences
            $('#pres-all').on('click',         function(){ $('#pres-list input[type="checkbox"]').prop('checked',true).trigger('change'); });
            $('#pres-none').on('click',        function(){ $('#pres-list input[type="checkbox"]').prop('checked',false).trigger('change'); });
            $('#pres-search').on('input',      function(){ CAL.applyPresFilter(); });
            $('#pres-flt-saisie').on('change', function(){ CAL.applyPresFilter(); });
            // Examen
            $('#exam-search').on('input',      function(){ CAL.applyExamFilter(); });
            $('#exam-flt-saisie').on('change', function(){ CAL.applyExamFilter(); });
            $('#exam-select-all').on('click',  function(){
                $('#exam-list .sp-exam-row:visible .sp-exam-chk').prop('checked',true).trigger('change');
            });
            $('#btn-apply-grades').on('click', CAL.applyGrades);
            $('#btn-save-event').on('click',   CAL.saveEvent);
			$('#ev-type').on('change', function(){
			$('#ev-niveau-wrap').toggle($(this).val() === 'competition');
			});
            $('#btn-delete-event').on('click', CAL.deleteEvent);
            // Compétition
            $(document).on('click', '#btn-add-epreuve', function(){
                if (CAL.compEpreuves.length >= 6) { alert('Maximum 6 épreuves.'); return; }
                CAL.compEpreuves.push({ id: 0, nom: '', modalite: '', ordre: CAL.compEpreuves.length + 1 });
                CAL.renderCompEpreuves();
            });
            $(document).on('click', '#btn-save-epreuves',  CAL.saveCompEpreuves);
            $(document).on('click', '#btn-save-resultats', CAL.saveCompResultats);
            $(document).on('change', '#comp-flt-saisie',   function(){ CAL.renderCompResultats(); });
            // Sondage
            $(document).on('click', '#btn-sondage-add-note', function(){
                var idx = $('#sondage-questions-list .sp-sondage-q-row').length;
                $('#sondage-questions-list').append(CAL.sondageQuestionRow(idx, 'note', '', false));
            });
            $(document).on('click', '#btn-sondage-add-texte', function(){
                var idx = $('#sondage-questions-list .sp-sondage-q-row').length;
                $('#sondage-questions-list').append(CAL.sondageQuestionRow(idx, 'texte', '', false));
            });
            $(document).on('click', '.sp-sondage-q-del', function(){
                $(this).closest('.sp-sondage-q-row').remove();
            });
            $(document).on('click', '#btn-sondage-save', function(){
                var sondageId = $(this).data('sondage-id');
                var questions = [];
                $('#sondage-questions-list .sp-sondage-q-row').each(function(){
                    questions.push({
                        type:        $(this).find('.sp-q-type').val(),
                        question:    $(this).find('.sp-q-libelle').val(),
                        obligatoire: $(this).find('.sp-q-obligatoire').is(':checked') ? 1 : 0,
                    });
                });
                var $msg = $('#sondage-save-msg').hide();
                $.post(SpCal.ajaxurl, {
                    action:     'sp_save_sondage_questions',
                    nonce:      SpCal.nonce,
                    sondage_id: sondageId,
                    questions:  JSON.stringify(questions),
                }, function(resp) {
                    $msg.show()
                        .css('color', resp.success ? '#15803d' : '#c00')
                        .text(resp.success ? '✅ Enregistré' : '❌ ' + (resp.data || 'Erreur'));
                });
            });

            // Sondage — envoyer par email (manuel)
            $(document).on('click', '#btn-sondage-envoyer', function(){
                var eventId = CAL.editingEvent ? CAL.editingEvent.id : 0;
                var eleveIds = [];
                $('#sondage-eleves-list .sp-sondage-eleve-chk:checked').each(function(){
                    eleveIds.push($(this).val());
                });
                if (!eleveIds.length) {
                    alert('Cochez au moins un élève.');
                    return;
                }
                if (!confirm('Envoyer le sondage à ' + eleveIds.length + ' élève(s) ? Cette action est irréversible.')) return;
                var $msg = $('#sondage-envoyer-msg').hide();
                var $btn = $(this).prop('disabled', true).css('opacity', '0.6');
                $.post(SpCal.ajaxurl, {
                    action:    'sp_envoyer_sondage',
                    nonce:     SpCal.nonce,
                    event_id:  eventId,
                    eleve_ids: JSON.stringify(eleveIds),
                }, function(resp) {
                    $btn.prop('disabled', false).css('opacity', '1');
                    $msg.show();
                    if (resp.success) {
                        $msg.css('color', '#15803d').text('✅ ' + resp.data.sent + ' email(s) envoyé(s)');
                        setTimeout(function(){ CAL.loadSondage(); }, 1500);
                    } else {
                        $msg.css('color', '#c00').text('❌ ' + (resp.data || 'Erreur'));
                    }
                });
            });
            // Notification bureau dispos
            $(document).on('click', '#btn-send-dispo-notif', function(){
                var date = CAL.currentDate;
                if (!date || !CAL.pendingDispoChanges[date]) return;
                var changes = Object.values(CAL.pendingDispoChanges[date]);
                if (!changes.length) return;
                var btn = $(this).prop('disabled', true).text('Envoi…');
                $.post(SpCal.ajaxurl, {
                    action:      'sp_cal_notify_bureau_dispos',
                    nonce:       SpCal.nonce,
                    date:        date,
                    changements: JSON.stringify(changes),
                }, function(res) {
                    btn.prop('disabled', false).text('✉️ Envoyer la notification au bureau');
                    if (res.success) {
                        $('#dispo-notif-result').text(res.data.msg).show();
                        // Effacer les changements en attente pour cette date
                        delete CAL.pendingDispoChanges[date];
                        setTimeout(function(){
                            $('#dispo-notif-bar').hide();
                            $('#dispo-notif-result').hide();
                        }, 4000);
                    } else {
                        $('#dispo-notif-result').text('❌ Erreur : ' + res.data).show();
                    }
                });
            });
            // Upload document : handler change sur l'input file
            $(document).on('change', '#ev-document', function(){
                var file = this.files[0];
                if (!file) return;
                var evId = CAL.editingEvent ? CAL.editingEvent.id : 0;
                if (!evId) { alert('Enregistrez d\'abord l\'événement avant d\'ajouter un document.'); $(this).val(''); return; }
                CAL.uploadDocument(evId, file);
            });
            $(document).on('click', '#btn-delete-doc', function(){
                if (!CAL.editingEvent || !confirm('Supprimer le document joint ?')) return;
                CAL.deleteDocument(CAL.editingEvent.id);
            });
            // Dispos : délégation sur les spans role=button (évite styles WP admin sur <button>)
            $(document).on('click', '.sp-dispo-act:not(.sp-dispo-act-note)', function(){
                var tid  = parseInt($(this).data('trainer-id'), 10);
                var date = $(this).data('date');
                var val  = $(this).data('val');
                CAL.saveDispo(tid, date, val, '');
            });
            $(document).on('click', '.sp-dispo-act-note', function(){
                var tid  = parseInt($(this).data('trainer-id'), 10);
                var date = $(this).data('date');
                var cur  = $(this).data('note') || '';
                var name = $(this).data('name') || '';
                var note = prompt('Note pour ' + name + ' :', cur);
                if (note === null) return;
                var cur_dispo = CAL.getDispoVal(tid, date);
                CAL.saveDispo(tid, date, cur_dispo !== null ? cur_dispo : 1, note);
            });
        },

        /* ═══════════════════════════════ VUE JOUR */
        openModalDay: function (date) {
            CAL.currentDate = date;
            var d     = new Date(date+'T00:00:00');
            var label = d.toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
            $('#sp-modal-title').text(label.charAt(0).toUpperCase()+label.slice(1));

            var dayEv    = CAL.events.filter(function(ev){ return ev.date===date; });
            var recArr   = dayEv.filter(function(ev){ return ev.type==='cours_recurrent'; });
            var annArr   = dayEv.filter(function(ev){ return ev.type==='annulation'; });
            var ponctArr = dayEv.filter(function(ev){ return ev.type==='cours'||ev.type==='evenement'||ev.type==='examen'||ev.type==='competition'; });
            var bdayArr  = dayEv.filter(function(ev){ return ev.type==='anniversaire'; });

            // Récurrents + annulations
            var recHtml = '';
            recArr.forEach(function(ev){
                var time  = ev.heure_debut?(ev.heure_debut+(ev.heure_fin?' – '+ev.heure_fin:''))+' ':' ';
                var color = CAL.resolveColor(ev.categorie,'#3B82F6');
                recHtml += '<div class="sp-day-occ-item">'
                    +'<div class="sp-day-event-dot" style="background:'+color+';"></div>'
                    +'<div class="sp-day-event-info"><div class="sp-day-event-title">'+CAL.esc(ev.titre)+'</div>'
                    +'<div class="sp-day-event-time">🔄 '+time+'</div></div>'
                    +'<span class="sp-occ-btn sp-occ-btn-cancel" role="button" tabindex="0"'
                    +' data-slot-id="'+ev.slot_id+'" data-date="'+date+'"'
                    +' data-titre="'+CAL.esc(ev.titre)+'"'
                    +' data-heure-debut="'+(ev.heure_debut||'')+'"'
                    +' data-heure-fin="'+(ev.heure_fin||'')+'"'
                    +' data-categorie="'+CAL.esc(ev.categorie||'')+'"'
                    +'>🚫 Annuler</span>'
                    +'</div>';
            });
            annArr.forEach(function(ev){
                recHtml += '<div class="sp-day-occ-item sp-occ-annule">'
                    +'<div class="sp-day-event-dot" style="background:#ef4444;"></div>'
                    +'<div class="sp-day-event-info"><div class="sp-day-event-title" style="text-decoration:line-through;opacity:.6;">'+CAL.esc(ev.titre)+'</div>'
                    +'<div class="sp-day-event-time" style="color:#ef4444;">🚫 Cours annulé</div></div>'
                    +'<span class="sp-occ-btn sp-occ-btn-restore" role="button" tabindex="0" data-annul-id="'+ev.annul_id+'">✅ Rétablir</span>'
                    +'</div>';
            });
            if (recHtml) { $('#list-recurrents').html(recHtml); $('#section-recurrents').show(); }
            else         { $('#section-recurrents').hide(); }

            // Ponctuels + examens
            var ponctHtml = '';
            ponctArr.forEach(function(ev){
                var color = CAL.resolveColor(ev.categorie, ev.couleur||'#3B82F6');
                var icon  = ev.type==='cours' ? '⚡' : ev.type==='examen' ? '🎓' : '📅';
                var time  = ev.heure_debut?(ev.heure_debut+(ev.heure_fin?' – '+ev.heure_fin:'')):'' ;
                ponctHtml += '<div class="sp-day-event-item" data-id="'+ev.id+'">'
                    +'<div class="sp-day-event-dot" style="background:'+color+';"></div>'
                    +'<div class="sp-day-event-info"><div class="sp-day-event-title">'+icon+' '+CAL.esc(ev.titre)+'</div>'
                    +(time?'<div class="sp-day-event-time">'+time+'</div>':'')+'</div>'
                    +'<button class="button button-small sp-btn-edit-event" data-id="'+ev.id+'">✏️</button>'
                    +'</div>';
            });
            if (ponctHtml) { $('#list-ponctuels').html(ponctHtml); $('#section-ponctuels').show(); }
            else           { $('#section-ponctuels').hide(); }

            // Anniversaires
            var bdayHtml = '';
            bdayArr.forEach(function(ev){ bdayHtml += '<div class="sp-bday-item">🎂 '+CAL.esc(ev.titre)+'</div>'; });
            if (bdayHtml) { $('#list-bdays').html(bdayHtml); $('#section-bdays').show(); }
            else          { $('#section-bdays').hide(); }

            $('#no-day-content').toggle(!recArr.length&&!annArr.length&&!ponctArr.length&&!bdayArr.length);

            // Disponibilités entraîneurs
            CAL.renderDispos(date);

            $('#list-recurrents').off('click')
                .on('click','.sp-occ-btn-cancel', function(){
                    var $btn = $(this);
                    CAL.openAnnulModal(
                        $btn.data('slot-id'),
                        $btn.data('date'),
                        $btn.data('titre'),
                        $btn.data('heure-debut'),
                        $btn.data('heure-fin'),
                        $btn.data('categorie')
                    );
                })
                .on('click','.sp-occ-btn-restore', function(){
                    if(confirm('Rétablir ce cours ?')) CAL.restoreSlot($(this).data('annul-id'));
                });
            $('#list-ponctuels').off('click')
                .on('click','.sp-btn-edit-event', function(){
                    var id=$(this).data('id'), ev=CAL.events.find(function(x){return x.id==id;});
                    if(ev) CAL.openModalForm(date,ev);
                })
                .on('click','.sp-day-event-item', function(e){
                    if($(e.target).closest('button').length) return;
                    var id=$(this).data('id'), ev=CAL.events.find(function(x){return x.id==id;});
                    if(ev) CAL.openModalForm(date,ev);
                });

            CAL.showView('day');
            $('#sp-event-modal').addClass('open');
        },

        /* ═══════════════════════════════ ANNULER / RÉTABLIR */

        /* Injecte la mini-modale d'annulation une seule fois dans le DOM */
        initAnnulModal: function() {
            if ($('#sp-annul-modal').length) return;
            $('body').append(
                '<div id="sp-annul-modal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;">'
                +'<div style="background:#fff;border-radius:14px;padding:28px 32px;width:460px;max-width:92vw;box-shadow:0 8px 40px rgba(0,0,0,.22);">'
                +'<h3 id="sp-annul-modal-title" style="margin:0 0 6px;font-size:17px;color:#111;">🚫 Annuler un cours</h3>'
                +'<p id="sp-annul-modal-info" style="margin:0 0 18px;font-size:13px;color:#6b7280;"></p>'
                +'<label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Motif de l\'annulation</label>'
                +'<textarea id="sp-annul-motif" rows="4" style="width:100%;box-sizing:border-box;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;font-size:13px;color:#111;resize:vertical;line-height:1.5;"></textarea>'
                +'<label style="display:flex;align-items:center;gap:8px;margin-top:14px;font-size:13px;cursor:pointer;">'
                +'<input type="checkbox" id="sp-annul-notifier" checked> <span>📧 Notifier les élèves par email</span></label>'
                +'<div id="sp-annul-grouper-wrap" style="margin-top:10px;padding:10px 14px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">'
                +'<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">'
                +'<input type="checkbox" id="sp-annul-grouper"> <span>📦 Grouper avec d\'autres annulations <small style="color:#94a3b8;">(envoyer un récap unique plus tard)</small></span></label>'
                +'</div>'
                +'<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;">'
                +'<button id="sp-annul-cancel-btn" style="background:#f1f5f9;border:none;border-radius:8px;padding:9px 20px;font-size:13px;cursor:pointer;color:#374151;">Annuler</button>'
                +'<button id="sp-annul-confirm-btn" style="background:#ef4444;color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:600;cursor:pointer;">🚫 Confirmer l\'annulation</button>'
                +'</div>'
                +'</div></div>'
            );
            // Afficher/masquer "Grouper" selon la checkbox Notifier
            $(document).on('change','#sp-annul-notifier', function(){
                $('#sp-annul-grouper-wrap').toggle(this.checked);
                if (!this.checked) $('#sp-annul-grouper').prop('checked', false);
            });
            $(document).on('click','#sp-annul-cancel-btn', function(){ CAL.closeAnnulModal(); });
            $(document).on('click','#sp-annul-modal', function(e){
                if ($(e.target).is('#sp-annul-modal')) CAL.closeAnnulModal();
            });
        },

        openAnnulModal: function(slotId, date, titre, heureDebut, heureFin, categorie) {
            CAL.initAnnulModal();
            // Infos du cours
            var dateFmt = date ? date.split('-').reverse().join('/') : date;
            var horaire = heureDebut ? heureDebut.substring(0,5) + (heureFin ? ' – '+heureFin.substring(0,5) : '') : '';
            $('#sp-annul-modal-title').text('🚫 Annuler — ' + titre);
            $('#sp-annul-modal-info').text('📅 ' + dateFmt + (horaire ? '  ·  🕐 ' + horaire : ''));
            // Texte générique pré-rempli
            var motifDefault = 'Le cours ' + titre + ' du ' + dateFmt
                + (horaire ? ' (' + horaire + ')' : '')
                + ' est annulé. Nous nous en excusons et vous retrouverons au prochain cours.';
            $('#sp-annul-motif').val(motifDefault);
            $('#sp-annul-notifier').prop('checked', true);
            $('#sp-annul-grouper').prop('checked', false);
            $('#sp-annul-grouper-wrap').show();
            // Stocker les données dans la modale
            $('#sp-annul-modal').data({
                slotId: slotId, date: date, titre: titre,
                heureDebut: heureDebut, heureFin: heureFin, categorie: categorie
            }).css('display','flex');
            // Bind confirm
            $('#sp-annul-confirm-btn').off('click').on('click', function(){
                var d   = $('#sp-annul-modal').data();
                var motif   = $('#sp-annul-motif').val();
                var notifier = $('#sp-annul-notifier').is(':checked') ? 1 : 0;
                var grouper  = (notifier && $('#sp-annul-grouper').is(':checked')) ? 1 : 0;
                CAL.closeAnnulModal();
                CAL.cancelSlot(d.slotId, d.date, motif, notifier, grouper);
            });
            setTimeout(function(){ $('#sp-annul-motif').focus(); }, 80);
        },

        closeAnnulModal: function() {
            $('#sp-annul-modal').css('display','none');
        },

        cancelSlot: function (slotId, date, motif, notifier, grouper) {
            motif    = motif    || '';
            notifier = notifier || 0;
            grouper  = grouper  || 0;
            $.post(SpCal.ajaxurl, {
                action:      'sp_cal_save_event',
                nonce:       SpCal.nonce,
                event_id:    0,
                date:        date,
                slot_id:     slotId,
                type:        'annulation',
                titre:       'Annulation',
                description: motif,
                annul_notifier: notifier,
                annul_grouper:  grouper,
            }, function(res){
                if (res.success) {
                    CAL.closeModal();
                    CAL.loadMonth();
                    // Badge pending si groupé
                    if (grouper && notifier && res.data && res.data.pending_count > 0) {
                        CAL.updatePendingBadge(res.data.pending_count);
                    }
                } else {
                    alert('Erreur : ' + res.data);
                }
            });
        },
        restoreSlot: function (annulId) {
            $.post(SpCal.ajaxurl,{action:'sp_cal_delete_event',nonce:SpCal.nonce,event_id:annulId},function(res){
                if(res.success){CAL.closeModal();CAL.loadMonth();} else alert('Erreur.');
            });
        },

        /* Badge "X annulation(s) en attente" dans le header calendrier */
        updatePendingBadge: function(count) {
            var $badge = $('#sp-annul-pending-badge');
            if (!count) { $badge.remove(); return; }
            if (!$badge.length) {
                $badge = $('<div id="sp-annul-pending-badge" style="'
                    +'display:inline-flex;align-items:center;gap:8px;'
                    +'background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;'
                    +'padding:6px 14px;font-size:13px;color:#92400e;margin-left:12px;">'
                    +'<span id="sp-annul-pending-label"></span>'
                    +'<button id="sp-annul-send-groupee" style="background:#f59e0b;color:#fff;border:none;'
                    +'border-radius:6px;padding:4px 12px;font-size:12px;font-weight:600;cursor:pointer;">'
                    +'📨 Envoyer le récap</button>'
                    +'</div>');
                // Insérer dans le header du calendrier
                var $nav = $('#sp-cal-nav, .sp-cal-header, #sp-calendar-header').first();
                if ($nav.length) $nav.append($badge);
                else $('h1.wp-heading-inline').after($badge);
                $(document).on('click','#sp-annul-send-groupee', function(){
                    CAL.sendAnnulGroupee();
                });
            }
            $('#sp-annul-pending-label').text('📦 ' + count + ' annulation' + (count > 1 ? 's' : '') + ' en attente');
        },

        sendAnnulGroupee: function() {
            if (!confirm('Envoyer le récapitulatif des annulations groupées aux élèves concernés ?')) return;
            $.post(SpCal.ajaxurl, {
                action: 'sp_cal_send_annul_groupee',
                nonce:  SpCal.nonce,
            }, function(res){
                if (res.success) {
                    alert('✅ ' + (res.data.sent || 0) + ' email(s) envoyé(s).');
                    CAL.updatePendingBadge(0);
                } else {
                    alert('Erreur : ' + res.data);
                }
            });
        },

        /* ═══════════════════════════════ FORMULAIRE ÉVÉNEMENT */
        openModalForm: function (date, ev) {
            CAL.currentDate=date; CAL.editingEvent=ev; CAL.presences={}; CAL.notes={};
            CAL.compEpreuves=[]; CAL.compEleves=[]; CAL.compResultats={};
            var isExamen      = ev && ev.type==='examen';
            var isCompetition = ev && ev.type==='competition';
            $('#sp-modal-title').text(ev ? 'Modifier l\'événement' : 'Ajouter un événement');
            $('#ev-titre').val(ev?ev.titre:'');
            $('#ev-date').val(date);
            // Normaliser le type : 'cours_recurrent' n'est pas une option du select → forcer 'cours'
            var displayType = ev ? (ev.type === 'cours_recurrent' ? 'cours' : ev.type) : 'cours';
            $('#ev-type').val(displayType);
			// Niveau compétition (visible uniquement pour type=competition)
			$('#ev-niveau').val(ev ? (ev.niveau || 'departemental') : 'departemental');
			$('#ev-niveau-wrap').toggle(displayType === 'competition');
            $('#ev-debut').val(ev?CAL.toTimeInput(ev.heure_debut):'');
            $('#ev-fin').val(ev?CAL.toTimeInput(ev.heure_fin):'');
            CAL.renderCoursCategorieWrap(
                ev && ev.cours_discipline     ? ev.cours_discipline.split(',').map(function(s){ return s.trim(); }).filter(Boolean)     : [],
                ev && ev.cours_age_categories ? ev.cours_age_categories.split(',').map(function(s){ return s.trim(); }).filter(Boolean) : []
            );
            $('#ev-couleur').val(ev?ev.couleur:'#3B82F6');
            $('#ev-description').val(ev?(ev.description||''):'');
            $('#btn-delete-event').toggle(!!ev);
            // Document joint — si compétition passée avec palmarès configuré → lien palmarès
            if (ev && ev.document_url) {
                var palmaresBase = (SpCal && SpCal.palmaresUrl) ? SpCal.palmaresUrl : '';
                var isCompPast   = ev.type === 'competition' && ev.date < CAL.todayStr;
                var linkUrl      = (isCompPast && palmaresBase)
                                   ? palmaresBase.replace(/#.*$/, '') + '#competition-' + ev.id
                                   : ev.document_url;
                var linkLabel    = (isCompPast && palmaresBase)
                                   ? '🏆 Voir les résultats'
                                   : '📄 ' + (ev.document_nom || 'document');
                $('#doc-current-link').attr('href', linkUrl)
                    .attr('download', (isCompPast && palmaresBase) ? null : '')
                    .attr('target', (isCompPast && palmaresBase) ? '_self' : '_blank')
                    .text(linkLabel);
                $('#doc-current').show();
            } else {
                $('#doc-current').hide();
            }
            $('#ev-document').val('');
            // Tabs : visibilité selon le type
            // Présences : actif pour cours ponctuel ET cours récurrent (materialisé à la volée par PHP)
            var presDisabled = !ev || isExamen || isCompetition;
            $('#tab-presences-btn').prop('disabled', presDisabled).toggle(!isExamen && !isCompetition);
            // Si l'event a déjà un vrai ID entier (ponctuel ou récurrent matérialisé), on peut accéder aux présences immédiatement
            if (ev && !isExamen && !isCompetition) {
                var hasRealId = Number.isInteger(ev.id) || (typeof ev.id === 'number' && ev.id > 0);
                $('#tab-presences-btn').prop('disabled', false);
            }
            $('#tab-examen-btn').prop('disabled', !ev||!isExamen).toggle(!!isExamen);
            $('#tab-competition-btn').prop('disabled', !ev||!isCompetition).toggle(!!isCompetition);
            // Sondage : visible pour tout événement existant non-cours
            var showSondage = !!ev && ev.type !== 'cours' && ev.type !== 'cours_recurrent' && ev.type !== 'annulation';
            $('#tab-sondage-btn').prop('disabled', !showSondage).toggle(showSondage);
            $('#exam-apply-bar').hide(); $('#exam-apply-msg').hide();
            // ── Champs inscription ──────────────────────────────────────
            CAL.injectInscriptionFields();
            var showInsc = (displayType !== 'cours' && displayType !== 'annulation' && displayType !== 'cours_recurrent');
            $('#insc-section').toggle(showInsc);
            if (showInsc) {
                var inscActif = ev && ev.inscriptions_actives ? true : false;
                $('#ev-insc-actives').prop('checked', inscActif);
                $('#insc-options').toggle(inscActif);
                var ages_saved = (ev && ev.inscriptions_age_categories) ? ev.inscriptions_age_categories.split(',').map(function(s){return s.trim();}).filter(Boolean) : [];
                var disc_saved = (ev && ev.inscriptions_categories)     ? ev.inscriptions_categories.split(',').map(function(s){return s.trim();}).filter(Boolean) : [];
                CAL.populateInscCats({discipline: CAL.catsSaisie, age: CAL.catsSaisieAge||[]}, disc_saved, ages_saved);
                CAL.inscExtraIds = (ev && ev.inscriptions_extra_eleves)
                    ? ev.inscriptions_extra_eleves.split(',').map(function(s){ return parseInt(s.trim(), 10); }).filter(function(n){ return !isNaN(n); })
                    : [];
                $('#ev-insc-extra-search').val('');
                $('#ev-insc-extra-results').hide().empty();
                if (CAL.elevesPourCiblage === null) CAL.loadElevesPourCiblage(); else CAL.renderExtraChips();
                $('#ev-insc-deadline').val(ev && ev.inscriptions_deadline ? ev.inscriptions_deadline : '');
                $('#ev-insc-message').val(ev && ev.inscriptions_message ? ev.inscriptions_message : '');
                // Lien liste + bouton envoi : visibles si inscriptions activées ET event sauvegardé
                var evId = ev ? (Number.isInteger(ev.id) || (typeof ev.id === 'number') ? ev.id : 0) : 0;
                var hasId = evId > 0;
                if (inscActif && hasId) {
                    var inscUrl = (typeof ajaxurl !== 'undefined' ? ajaxurl.replace('admin-ajax.php', '') : '') + 'admin.php?page=sp-cal-inscriptions&event_id=' + evId;
                    $('#insc-voir-link').attr('href', inscUrl).show();
                    $('#insc-send-wrap').show();
                    $('#insc-send-btn').data('event-id', evId);
                    if (ev.inscriptions_envoye) {
                        $('#insc-send-btn').text('🔁 Renvoyer aux non-répondants');
                    } else {
                        $('#insc-send-btn').text('📨 Envoyer les invitations');
                    }
                    $('#insc-send-result').hide().text('');
                } else {
                    $('#insc-voir-link').hide();
                    $('#insc-send-wrap').hide();
                    $('#insc-send-result').hide().text('');
                }
            }
            // ── Fin champs inscription ───────────────────────────────────────
            CAL.switchTab('details');
            CAL.showView('form');
            $('#sp-event-modal').addClass('open');
        },

        /* ═══════════════════════════════ TABS */
        switchTab: function (tab) {
            CAL.activeTab = tab;
            $('.sp-tab-btn').removeClass('active').filter('[data-tab="'+tab+'"]').addClass('active');
            $('.sp-tab-content').removeClass('active');
            $('#tab-'+tab).addClass('active');
            if (tab==='presences'   && CAL.editingEvent) CAL.loadPresences();
            if (tab==='examen'      && CAL.editingEvent) CAL.loadExamen();
            if (tab==='competition' && CAL.editingEvent) CAL.loadComp();
            if (tab==='sondage'     && CAL.editingEvent) CAL.loadSondage();
        },

        /* ═══════════════════════════════ RACCOURCI PRÉSENCES */

        /**
         * Ouvre directement l'onglet Présences pour un cours donné.
         * Fonctionne pour les cours récurrents (non encore en DB) ET les cours ponctuels.
         */
        openPresencesForEvent: function (ev) {
            CAL.openModalForm(CAL.currentDate, ev);
            // Attendre que la modale soit prête puis switcher sur l'onglet présences
            var tries = 0;
            var wait = setInterval(function(){
                tries++;
                if (!$('#tab-presences-btn').prop('disabled') || tries > 20) {
                    clearInterval(wait);
                    CAL.switchTab('presences');
                }
            }, 80);
        },

        /**
         * Affiche la vue de sélection de cours (pick view) avec les cours du jour.
         * L'utilisateur clique sur un cours → ouverture directe des présences.
         */
        showPickView: function (dayEvs) {
            var html = '';
            dayEvs.forEach(function(ev){
                var heure = ev.heure_debut ? (ev.heure_debut + (ev.heure_fin ? ' – ' + ev.heure_fin : '')) : '';
                var icon  = ev.type === 'cours_recurrent' ? '🔄' : '⚡';
                var color = CAL.resolveColor(ev.categorie, ev.type === 'cours_recurrent' ? '#3B82F6' : '#7c3aed');
                html += '<div class="sp-pick-item" data-ev-id="' + CAL.esc(String(ev.id)) + '" style="border-left:4px solid ' + color + ';">'
                    + '<div class="sp-pick-icon">' + icon + '</div>'
                    + '<div class="sp-pick-info">'
                    +   '<div class="sp-pick-titre">' + CAL.esc(ev.titre) + '</div>'
                    +   (heure ? '<div class="sp-pick-heure">' + CAL.esc(heure) + '</div>' : '')
                    +   (ev.categorie ? '<div class="sp-pick-cat">' + CAL.esc(ev.categorie) + '</div>' : '')
                    + '</div>'
                    + '<div class="sp-pick-arrow">→</div>'
                    + '</div>';
            });
            $('#pick-list').html(html);

            // Délégation : clic sur un cours de la pick-list
            $('#pick-list').off('click.pick').on('click.pick', '.sp-pick-item', function(){
                var evId = $(this).data('ev-id');
                var ev   = CAL.events.find(function(e){ return String(e.id) === String(evId); });
                if (ev) CAL.openPresencesForEvent(ev);
            });

            CAL.showView('pick');
            $('#sp-event-modal').addClass('open');
        },

        /* ═══════════════════════════════ PRÉSENCES */
        loadPresences: function () {
            if (!CAL.editingEvent) return;
            $('#pres-loading').show(); $('#pres-list').empty();

            // Déterminer si on a déjà un vrai ID entier (matérialisation existante ou event ponctuel)
            // ou si on doit passer slot_id+date pour que PHP crée l'event à la volée.
            var evId     = CAL.editingEvent.id;
            var hasRealId = Number.isInteger(evId) || (typeof evId === 'number' && evId > 0);

            var postData = { action:'sp_cal_get_presences', nonce:SpCal.nonce, event_id: hasRealId ? evId : 0 };
            if (!hasRealId && CAL.editingEvent.slot_id) {
                postData.slot_id = CAL.editingEvent.slot_id;
                postData.date    = CAL.editingEvent.date;
            }

            $.post(SpCal.ajaxurl, postData, function(res){
                $('#pres-loading').hide();
                if (!res.success) return;
                // Si PHP a créé l'event à la volée, mettre à jour l'id local
                if (res.data.event_id && !hasRealId) {
                    CAL.editingEvent.id     = res.data.event_id;
                    CAL.editingEvent.mat_id = res.data.event_id;
                    $('#tab-presences-btn').prop('disabled', false);
                }
                CAL.elevesAll=res.data.eleves; CAL.elevesByGroup=res.data.grouped; CAL.catsSaisie=res.data.cats_saisie||[];
                res.data.eleves.forEach(function(el){ if(el.present!==null) CAL.presences[el.id]=el.present; });
                var opts='<option value="">— Tous les groupes —</option>';
                CAL.catsSaisie.forEach(function(c){ opts+='<option value="'+CAL.esc(c)+'">'+CAL.esc(CAL.discLabel(c))+'</option>'; });
                $('#pres-flt-saisie').html(opts).val('');
                CAL.renderPresences();
            });
        },
        renderPresences: function () {
            var html='';
            Object.keys(CAL.elevesByGroup).sort().forEach(function(cat){
                html+='<div class="sp-pres-group-title">'+CAL.esc(cat)+' ('+CAL.elevesByGroup[cat].length+')</div>';
                CAL.elevesByGroup[cat].forEach(function(el){
                    var ch=CAL.presences[el.id]===1?'checked':'', fn=el.nom+' '+el.prenom, saisie=el.categorie_saisie||'';
                    html+='<label class="sp-pres-row" data-name="'+CAL.esc(fn.toLowerCase())+'" data-saisie="'+CAL.esc(saisie)+'">'
                        +'<input type="checkbox" value="'+el.id+'" '+ch+'>'
                        +'<span class="sp-pres-name">'+CAL.esc(fn)+'</span>'
                        +(saisie?'<span class="sp-pres-saisie-badge">'+CAL.esc(CAL.discLabel(saisie))+'</span>':'')
                        +(el.grade?'<span class="sp-pres-grade">'+CAL.esc(el.grade)+'</span>':'')
                        +'</label>';
                });
            });
            if (!html) html='<p style="text-align:center;color:#888;padding:20px;">Aucun élève.</p>';
            $('#pres-list').html(html);
            CAL.updatePresCount();
            $('#pres-list').off('change').on('change','input[type="checkbox"]',function(){
                CAL.presences[+$(this).val()]=$(this).prop('checked')?1:0; CAL.updatePresCount();
            });
        },
        applyPresFilter: function () {
            var q=$('#pres-search').val().toLowerCase(), saisie=$('#pres-flt-saisie').val();
            $('#pres-list .sp-pres-row').each(function(){
                $(this).toggle((!q||$(this).data('name').indexOf(q)!==-1)&&(!saisie||$(this).data('saisie')===saisie));
            });
            $('#pres-list .sp-pres-group-title').each(function(){
                $(this).toggle($(this).nextUntil('.sp-pres-group-title').filter(':visible').length>0);
            });
        },
        updatePresCount: function () {
            var n=Object.values(CAL.presences).filter(function(v){return v===1;}).length, t=CAL.elevesAll.length;
            if(t>0) $('#pres-count').show().text(n+'/'+t);
        },

        /* ═══════════════════════════════ EXAMEN / PASSAGE DE GRADE */
        loadExamen: function () {
            if (!CAL.editingEvent) return;
            $('#exam-loading').show(); $('#exam-list').empty();
            $.post(SpCal.ajaxurl,{action:'sp_cal_get_presences',nonce:SpCal.nonce,event_id:CAL.editingEvent.id},function(res){
                $('#exam-loading').hide();
                if (!res.success) return;
                CAL.elevesAll=res.data.eleves; CAL.catsSaisie=res.data.cats_saisie||[];
                res.data.eleves.forEach(function(el){
                    if(el.present!==null) CAL.presences[el.id]=el.present;
                    if(el.note)          CAL.notes[el.id]=el.note;
                });
                var opts='<option value="">— Tous les groupes —</option>';
                CAL.catsSaisie.forEach(function(c){ opts+='<option value="'+CAL.esc(c)+'">'+CAL.esc(CAL.discLabel(c))+'</option>'; });
                $('#exam-flt-saisie').html(opts).val('');
                CAL.renderExamen();
            });
        },
        renderExamen: function () {
            var html='', grouped={};
            CAL.elevesAll.forEach(function(el){
                var cat=el.categorie_age||'Sans catégorie';
                if(!grouped[cat]) grouped[cat]=[];
                grouped[cat].push(el);
            });
            Object.keys(grouped).sort().forEach(function(cat){
                html+='<div class="sp-pres-group-title">'+CAL.esc(cat)+'</div>';
                grouped[cat].forEach(function(el){
                    var ch=CAL.presences[el.id]===1?'checked':'', noteVal=CAL.notes[el.id]||'';
                    var fn=el.nom+' '+el.prenom, saisie=el.categorie_saisie||'';
                    html+='<div class="sp-exam-row" data-name="'+CAL.esc(fn.toLowerCase())+'" data-saisie="'+CAL.esc(saisie)+'">'
                        +'<input type="checkbox" class="sp-exam-chk" value="'+el.id+'" '+ch+' title="Candidat">'
                        +'<span class="sp-pres-name">'+CAL.esc(fn)+'</span>'
                        +(saisie?'<span class="sp-pres-saisie-badge">'+CAL.esc(CAL.discLabel(saisie))+'</span>':'')
                        +'<span class="sp-pres-grade sp-exam-grade-current" title="Grade actuel">'+CAL.esc(el.grade||'—')+'</span>'
                        +'<span class="sp-exam-arrow">→</span>'
                        +'<input type="text" class="sp-exam-note sp-input" value="'+CAL.esc(noteVal)+'" placeholder="Grade obtenu…" data-id="'+el.id+'">'
                        +'</div>';
                });
            });
            if (!html) html='<p style="text-align:center;color:#888;padding:20px;">Aucun élève.</p>';
            $('#exam-list').html(html);
            CAL.updateExamCount();
            $('#exam-list').off('change input')
                .on('change','.sp-exam-chk',function(){
                    CAL.presences[+$(this).val()]=$(this).prop('checked')?1:0; CAL.updateExamCount();
                })
                .on('input','.sp-exam-note',function(){
                    var id=+$(this).data('id'), v=$(this).val().trim();
                    CAL.notes[id]=v;
                    if(v){ CAL.presences[id]=1; $(this).closest('.sp-exam-row').find('.sp-exam-chk').prop('checked',true); CAL.updateExamCount(); }
                    $('#exam-apply-bar').toggle(Object.values(CAL.notes).some(function(n){return n&&n.trim();}));
                });
        },
        applyExamFilter: function () {
            var q=$('#exam-search').val().toLowerCase(), saisie=$('#exam-flt-saisie').val();
            $('#exam-list .sp-exam-row').each(function(){
                $(this).toggle((!q||$(this).data('name').indexOf(q)!==-1)&&(!saisie||$(this).data('saisie')===saisie));
            });
            $('#exam-list .sp-pres-group-title').each(function(){
                $(this).toggle($(this).nextUntil('.sp-pres-group-title').filter(':visible').length>0);
            });
        },
        updateExamCount: function () {
            var n=Object.values(CAL.presences).filter(function(v){return v===1;}).length;
            if(n>0) $('#exam-count').show().text(n); else $('#exam-count').hide();
        },
        applyGrades: function () {
            if (!CAL.editingEvent) return;
            if (!Object.values(CAL.notes).some(function(n){return n&&n.trim();})) { alert('Aucun grade saisi.'); return; }
            if (!confirm('Appliquer les grades saisis aux fiches élèves ?\nCette action met à jour le grade actuel dans la base.')) return;
            var btn=$('#btn-apply-grades').prop('disabled',true).text('Sauvegarde…');
            CAL.savePresencesWithNotes(CAL.editingEvent.id, function(){
                $.post(SpCal.ajaxurl,{action:'sp_cal_apply_grades',nonce:SpCal.nonce,event_id:CAL.editingEvent.id},function(res){
                    btn.prop('disabled',false).text('✅ Appliquer les grades aux fiches élèves');
                    if(res.success){ $('#exam-apply-msg').text(res.data.msg).show(); setTimeout(function(){$('#exam-apply-msg').fadeOut();},4000); }
                    else alert('Erreur : '+res.data);
                });
            });
        },

        /* ═══════════════════════════════ SAUVEGARDER ÉVÉNEMENT */
        saveEvent: function () {
            var titre=$('#ev-titre').val().trim(), date=$('#ev-date').val().trim();
            if(!titre||!date){alert('Titre et date requis.');return;}

            // Récupérer le vrai event_id entier si disponible
            // Pour un cours récurrent : l'id peut être une string "slot_X_YYYYMMDD"
            // Dans ce cas on passe event_id=0 + slot_id + date pour que PHP retrouve
            // ou crée la matérialisation sans en recréer une deuxième.
            var ev       = CAL.editingEvent;
            var rawId    = ev ? ev.id : 0;
            var hasRealId = rawId && (typeof rawId === 'number' || /^\d+$/.test(String(rawId)));
            var eventId  = hasRealId ? parseInt(rawId, 10) : 0;
            var slotId   = (ev && ev.slot_id) ? parseInt(ev.slot_id, 10) : 0;

            var coursDiscipline = []; $('#ev-categorie-wrap .sp-ev-disc-cb:checked').each(function(){ coursDiscipline.push($(this).val()); });
            var coursAge        = []; $('#ev-categorie-wrap .sp-ev-age-cb:checked').each(function(){ coursAge.push($(this).val()); });

            var btn=$('#btn-save-event').prop('disabled',true).text('Enregistrement…');
            $.post(SpCal.ajaxurl,{
                action:'sp_cal_save_event', nonce:SpCal.nonce,
                event_id:  eventId,
                slot_id:   slotId,
                date:      date,
                heure_debut: $('#ev-debut').val(),
                heure_fin:   $('#ev-fin').val(),
                titre:       titre,
                cours_discipline:     coursDiscipline.join(','),
                cours_age_categories: coursAge.join(','),
                couleur:     $('#ev-couleur').val(),
                type:        $('#ev-type').val(),
                description: $('#ev-description').val().trim(),
                inscriptions_actives:  ($('#ev-insc-actives').length && $('#ev-insc-actives').prop('checked')) ? 1 : 0,
                inscriptions_categories: (function(){
                    var checked = [];
                    $('#ev-insc-cats-wrap .sp-insc-disc-cb:checked').each(function(){ checked.push($(this).val()); });
                    return checked.join(',');
                })(),
                inscriptions_age_categories: (function(){
                    var checked = [];
                    $('#ev-insc-cats-wrap .sp-insc-age-cb:checked').each(function(){ checked.push($(this).val()); });
                    return checked.join(',');
                })(),
                inscriptions_public:   '',
                inscriptions_extra_eleves: CAL.inscExtraIds.join(','),
                inscriptions_deadline: $('#ev-insc-deadline').length ? $('#ev-insc-deadline').val()       : '',
                inscriptions_message:  $('#ev-insc-message').length  ? $('#ev-insc-message').val().trim() : ''
            },function(res){
                btn.prop('disabled',false).text('Enregistrer');
                if(!res.success){alert('Erreur : '+res.data);return;}
                var evId=(res.data&&res.data.id)||(ev&&ev.id);
                // Mettre à jour l'id local avec le vrai id retourné par PHP
                if(res.data&&res.data.id) {
                    if(ev) ev.id = res.data.id;
                }
                // Mettre a jour ev en memoire pour la prochaine ouverture
                if(ev) {
                    var _disc = []; $('#ev-insc-cats-wrap .sp-insc-disc-cb:checked').each(function(){ _disc.push($(this).val()); });
                    var _age  = []; $('#ev-insc-cats-wrap .sp-insc-age-cb:checked').each(function(){ _age.push($(this).val()); });
                    ev.inscriptions_categories     = _disc.join(',');
                    ev.inscriptions_age_categories = _age.join(',');
                    ev.inscriptions_deadline       = $('#ev-insc-deadline').val();
                    ev.inscriptions_message        = $('#ev-insc-message').val().trim();
                    ev.inscriptions_actives        = $('#ev-insc-actives').prop('checked') ? 1 : 0;
                }
                // Après save : si inscriptions actives, afficher lien + bouton sans fermer
                var inscActif = $('#ev-insc-actives').length && $('#ev-insc-actives').prop('checked');
                if (evId && inscActif) {
                    var inscUrl = (typeof ajaxurl !== 'undefined' ? ajaxurl.replace('admin-ajax.php','') : '') + 'admin.php?page=sp-cal-inscriptions&event_id=' + evId;
                    $('#insc-voir-link').attr('href', inscUrl).show();
                    $('#insc-send-btn').data('event-id', evId).show();
                    $('#insc-send-wrap').show();
                }
                var hasData=Object.keys(CAL.presences).length>0||Object.keys(CAL.notes).length>0;
                if(evId&&hasData){ CAL.savePresencesWithNotes(evId,function(){CAL.closeModal();CAL.loadMonth();}); }
                else { CAL.closeModal(); CAL.loadMonth(); }
            }).fail(function(){ btn.prop('disabled',false).text('Enregistrer'); });
        },
        savePresencesWithNotes: function (evId, cb) {
            $.post(SpCal.ajaxurl,{
                action:'sp_cal_save_presences',nonce:SpCal.nonce,event_id:evId,
                presences:JSON.stringify(CAL.presences),notes:JSON.stringify(CAL.notes)
            },function(){ if(cb) cb(); });
        },

        /* ═══════════════════════════════ SUPPRIMER ÉVÉNEMENT */
        deleteEvent: function () {
            if(!CAL.editingEvent) return;
            if(!confirm('Supprimer cet événement ?')) return;
            $.post(SpCal.ajaxurl,{action:'sp_cal_delete_event',nonce:SpCal.nonce,event_id:CAL.editingEvent.id},function(res){
                if(res.success){CAL.closeModal();CAL.loadMonth();}
            });
        },

        /* ═══════════════════════════════ COMPÉTITION */

        loadComp: function () {
            if (!CAL.editingEvent) return;
            $('#comp-loading').show();
            $('#comp-epreuves-list').empty();
            $('#comp-resultats-list').empty();
            $.post(SpCal.ajaxurl, {
                action: 'sp_cal_get_comp', nonce: SpCal.nonce,
                event_id: CAL.editingEvent.id
            }, function(res) {
                $('#comp-loading').hide();
                if (!res.success) return;
                CAL.compEleves    = res.data.eleves   || [];
                CAL.compEpreuves  = res.data.epreuves || [];
                // Copier les résultats existants dans l'état d'édition
                CAL.compResultats = {};
                CAL.compEpreuves.forEach(function(ep){
                    CAL.compResultats[ep.id] = JSON.parse(JSON.stringify(ep.resultats || {}));
                });
                // Filtre catégorie saisie
                var cats = [];
                CAL.compEleves.forEach(function(el){
                    if (el.categorie_saisie && cats.indexOf(el.categorie_saisie)===-1) cats.push(el.categorie_saisie);
                });
                var opts = '<option value="">— Tous —</option>';
                cats.forEach(function(c){ opts += '<option value="'+CAL.esc(c)+'">'+CAL.esc(CAL.discLabel(c))+'</option>'; });
                $('#comp-flt-saisie').html(opts);
                CAL.renderCompEpreuves();
                CAL.renderCompResultats();
            });
        },

        renderCompEpreuves: function () {
            var html = '';
            if (!CAL.compEpreuves.length) {
                html = '<div style="color:#9ca3af;font-size:12px;padding:8px 0;">Aucune épreuve. Ajoutez-en jusqu\'à 6.</div>';
            } else {
                // En-tête colonnes
                html += '<div class="sp-comp-ep-header">'
                    + '<span class="sp-comp-ep-ordre">#</span>'
                    + '<span style="flex:1.4;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Nom de l\'épreuve</span>'
                    + '<span style="flex:1;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Modalité</span>'
                    + '<span style="width:28px;"></span>'
                    + '</div>';
                CAL.compEpreuves.forEach(function(ep, i){
                    html += '<div class="sp-comp-ep-row" data-idx="'+i+'">'
                        +'<span class="sp-comp-ep-ordre">'+(i+1)+'</span>'
                        +'<input type="text" class="sp-input sp-comp-ep-nom" value="'+CAL.esc(ep.nom)+'" placeholder="ex : Combat, Kata…" data-idx="'+i+'" style="flex:1.4;height:30px;">'
                        +'<input type="text" class="sp-input sp-comp-ep-modalite" value="'+CAL.esc(ep.modalite||'')+'" placeholder="ex : Individuel, Duo…" data-idx="'+i+'" style="flex:1;height:30px;">'
                        +'<span class="sp-comp-ep-del" role="button" data-idx="'+i+'" title="Supprimer" style="cursor:pointer;color:#ef4444;font-size:16px;padding:0 4px;">✕</span>'
                        +'</div>';
                });
            }
            $('#comp-epreuves-list').html(html)
                .off('input').on('input', '.sp-comp-ep-nom', function(){
                    var idx = parseInt($(this).data('idx'), 10);
                    if (CAL.compEpreuves[idx]) CAL.compEpreuves[idx].nom = $(this).val();
                })
                .on('input', '.sp-comp-ep-modalite', function(){
                    var idx = parseInt($(this).data('idx'), 10);
                    if (CAL.compEpreuves[idx]) CAL.compEpreuves[idx].modalite = $(this).val();
                })
                .off('click').on('click', '.sp-comp-ep-del', function(){
                    var idx = parseInt($(this).data('idx'), 10);
                    CAL.compEpreuves.splice(idx, 1);
                    CAL.compEpreuves.forEach(function(ep,i){ ep.ordre = i+1; });
                    CAL.renderCompEpreuves();
                    CAL.renderCompResultats();
                });
        },

        renderCompResultats: function () {
            if (!CAL.compEpreuves.length) {
                $('#comp-resultats-list').html('<div style="color:#9ca3af;font-size:12px;padding:8px 0;">Définissez d\'abord les épreuves.</div>');
                return;
            }
            var saisie = $('#comp-flt-saisie').val() || '';
            var eleves = saisie ? CAL.compEleves.filter(function(el){ return el.categorie_saisie === saisie; }) : CAL.compEleves;
            if (!eleves.length) { $('#comp-resultats-list').html('<div style="color:#9ca3af;font-size:12px;padding:8px 0;">Aucun élève.</div>'); return; }

            // En-tête colonnes épreuves
            var html = '<table class="sp-comp-table"><thead><tr>'
                +'<th class="sp-comp-th-name">Élève</th>';
            CAL.compEpreuves.forEach(function(ep){
                var sub = ep.modalite ? '<br><span class="sp-comp-ep-mod">'+CAL.esc(ep.modalite)+'</span>' : '';
                html += '<th class="sp-comp-th-ep">'+CAL.esc(ep.nom)+sub+'</th>';
            });
            html += '</tr></thead><tbody>';

            eleves.forEach(function(el){
                html += '<tr><td class="sp-comp-td-name">'+CAL.esc(el.nom)+'<br><span class="sp-comp-cat">'+CAL.esc(el.categorie_age||'')+'</span></td>';
                CAL.compEpreuves.forEach(function(ep){
                    if (!ep.id) { html += '<td class="sp-comp-td-res"><span style="color:#ccc;font-size:11px;">—</span></td>'; return; }
                    var res  = (CAL.compResultats[ep.id] && CAL.compResultats[ep.id][el.id]) || {};
                    var med  = res.medaille || '';
                    var score= res.score    || '';
                    html += '<td class="sp-comp-td-res">'
                        +'<div class="sp-comp-med-btns">'
                        +'<span class="sp-comp-med'+(med==='or'?' active':'')+'" role="button" data-ep="'+ep.id+'" data-el="'+el.id+'" data-val="or" title="Or">🥇</span>'
                        +'<span class="sp-comp-med'+(med==='argent'?' active':'')+'" role="button" data-ep="'+ep.id+'" data-el="'+el.id+'" data-val="argent" title="Argent">🥈</span>'
                        +'<span class="sp-comp-med'+(med==='bronze'?' active':'')+'" role="button" data-ep="'+ep.id+'" data-el="'+el.id+'" data-val="bronze" title="Bronze">🥉</span>'
                        +'<span class="sp-comp-med'+(med===''?' active':'')+'" role="button" data-ep="'+ep.id+'" data-el="'+el.id+'" data-val="" title="Effacer">✕</span>'
                        +'</div>'
                        +'<input type="text" class="sp-comp-score sp-input" value="'+CAL.esc(score)+'" placeholder="Score…" data-ep="'+ep.id+'" data-el="'+el.id+'">'
                        +'</td>';
                });
                html += '</tr>';
            });
            html += '</tbody></table>';

            $('#comp-resultats-list').html(html)
                .off('click').on('click', '.sp-comp-med', function(){
                    var epId = parseInt($(this).data('ep'), 10);
                    var elId = parseInt($(this).data('el'), 10);
                    var val  = $(this).data('val');
                    if (!CAL.compResultats[epId]) CAL.compResultats[epId] = {};
                    if (!CAL.compResultats[epId][elId]) CAL.compResultats[epId][elId] = {medaille:'', score:''};
                    CAL.compResultats[epId][elId].medaille = val;
                    // Mettre à jour l'affichage de la ligne
                    $(this).closest('.sp-comp-med-btns').find('.sp-comp-med').removeClass('active');
                    $(this).addClass('active');
                })
                .off('input').on('input', '.sp-comp-score', function(){
                    var epId = parseInt($(this).data('ep'), 10);
                    var elId = parseInt($(this).data('el'), 10);
                    if (!CAL.compResultats[epId]) CAL.compResultats[epId] = {};
                    if (!CAL.compResultats[epId][elId]) CAL.compResultats[epId][elId] = {medaille:'', score:''};
                    CAL.compResultats[epId][elId].score = $(this).val();
                });
        },

        /* ═══════════════════════════════ SONDAGE POST-ÉVÉNEMENT */
        loadSondage: function () {
            var ev = CAL.editingEvent;
            if (!ev) return;
            $('#sondage-loading').show();
            $('#sondage-wrap').hide();
            $.post(SpCal.ajaxurl, {
                action:   'sp_load_sondage',
                nonce:    SpCal.nonce,
                event_id: ev.id,
            }, function(resp) {
                $('#sondage-loading').hide();
                if (!resp.success) return;
                var data = resp.data;
                if (data.envoye) {
                    $('#sondage-edit-zone').hide();
                    $('#sondage-resultats-zone').show();
                    CAL.renderSondageResultats(data.resultats);
                    $('#sondage-wrap').show();
                } else {
                    $('#sondage-edit-zone').show();
                    $('#sondage-resultats-zone').hide();
                    $('#sondage-questions-list').empty();
                    (data.questions || []).forEach(function(q, i) {
                        $('#sondage-questions-list').append(CAL.sondageQuestionRow(i, q.type, q.question, q.obligatoire));
                    });
                    $('#btn-sondage-save').data('sondage-id', data.sondage_id);
                    // Stocker pour loadSondageEleves
                    CAL.sondageInscActives = data.inscriptions_actives || 0;
                    CAL.sondageInscMap     = data.insc_map || {};
                    CAL.loadSondageEleves();
                }
            });
        },

        loadSondageEleves: function () {
            var ev = CAL.editingEvent;
            $('#sondage-eleves-loading').show();
            $('#sondage-eleves-list').empty();
            $.post(SpCal.ajaxurl, {
                action:   'sp_cal_get_presences',
                nonce:    SpCal.nonce,
                event_id: ev.id,
            }, function(res) {
                $('#sondage-eleves-loading').hide();
                if (!res.success || !res.data.eleves || !res.data.eleves.length) {
                    $('#sondage-eleves-list').html('<p style="color:#9ca3af;font-size:12px;">Aucun élève trouvé.</p>');
                    $('#sondage-wrap').show();
                    return;
                }

                var eleves          = res.data.eleves;
                var inscActives     = CAL.sondageInscActives || 0;
                var inscMap         = CAL.sondageInscMap     || {};

                var html = '<div style="display:flex;gap:8px;margin-bottom:6px;">'
                    + '<span id="btn-sondage-check-all"  role="button" style="font-size:11px;color:#1d4ed8;cursor:pointer;text-decoration:underline;">Tout cocher</span>'
                    + ' · '
                    + '<span id="btn-sondage-check-none" role="button" style="font-size:11px;color:#1d4ed8;cursor:pointer;text-decoration:underline;">Tout décocher</span>'
                    + '</div>';

                eleves.forEach(function(el) {
                    var nom      = CAL.esc((el.nom || '') + ' ' + (el.prenom || ''));
                    var reponse  = inscMap[ el.id ] || null;
                    // Pré-coché : oui si pas d'inscriptions, ou si réponse = 'oui'
                    var checked  = inscActives ? (reponse === 'oui') : true;
                    // Badge statut
                    var badge = '';
                    if ( inscActives ) {
                        if      ( reponse === 'oui' )         badge = '<span style="font-size:10px;background:#dcfce7;color:#15803d;border-radius:4px;padding:1px 5px;margin-left:4px;">✓ inscrit</span>';
                        else if ( reponse === 'non' )          badge = '<span style="font-size:10px;background:#fee2e2;color:#b91c1c;border-radius:4px;padding:1px 5px;margin-left:4px;">✗ décliné</span>';
                        else if ( reponse === 'en_attente' )   badge = '<span style="font-size:10px;background:#fef9c3;color:#a16207;border-radius:4px;padding:1px 5px;margin-left:4px;">⏳ en attente</span>';
                        else                                   badge = '<span style="font-size:10px;background:#f3f4f6;color:#9ca3af;border-radius:4px;padding:1px 5px;margin-left:4px;">— non invité</span>';
                    }
                    html += '<label style="display:flex;align-items:center;gap:6px;padding:3px 0;font-size:13px;cursor:pointer;">'
                        + '<input type="checkbox" class="sp-sondage-eleve-chk" value="' + el.id + '" ' + (checked ? 'checked' : '') + '>'
                        + '<span>' + nom + '</span>'
                        + (el.grade ? '<span style="font-size:11px;color:#9ca3af;">' + CAL.esc(el.grade) + '</span>' : '')
                        + badge
                        + '</label>';
                });

                $('#sondage-eleves-list').html(html);
                $('#sondage-wrap').show();

                $('#sondage-eleves-list').on('click', '#btn-sondage-check-all', function(){
                    $('#sondage-eleves-list .sp-sondage-eleve-chk').prop('checked', true);
                });
                $('#sondage-eleves-list').on('click', '#btn-sondage-check-none', function(){
                    $('#sondage-eleves-list .sp-sondage-eleve-chk').prop('checked', false);
                });
            });
        },

        sondageQuestionRow: function (idx, type, libelle, obligatoire) {
            libelle     = libelle    || '';
            var checked = obligatoire ? 'checked' : '';
            var noteSel  = type === 'note'  ? 'selected' : '';
            var texteSel = type === 'texte' ? 'selected' : '';
            return '<div class="sp-sondage-q-row" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:6px 8px;">'
                + '<select class="sp-q-type" style="height:30px;border:1px solid #ccc;border-radius:4px;font-size:12px;">'
                +   '<option value="note"  '  + noteSel  + '>⭐ Note 1-5</option>'
                +   '<option value="texte" ' + texteSel + '>💬 Texte libre</option>'
                + '</select>'
                + '<input type="text" class="sp-q-libelle sp-input" value="' + CAL.esc(libelle) + '" placeholder="Votre question…" style="flex:1;height:30px;font-size:13px;">'
                + '<label style="white-space:nowrap;font-size:12px;">'
                +   '<input type="checkbox" class="sp-q-obligatoire" value="1" ' + checked + ' style="margin-right:3px;">Obligatoire'
                + '</label>'
                + '<button type="button" onclick="this.parentElement.remove();" style="background:none;border:none;cursor:pointer;color:#ef4444;font-size:18px;line-height:1;padding:0 6px;flex-shrink:0;" title="Supprimer cette question">✕</button>'
                + '</div>';
        },

        renderSondageResultats: function (resultats) {
            var $list = $('#sondage-resultats-list').empty();
            // Lien vers page dédiée
            var eventId = CAL.editingEvent ? CAL.editingEvent.id : 0;
            var pageUrl = SpCal.ajaxurl.replace('admin-ajax.php', '') + 'admin.php?page=sp-cal-sondages&event_id=' + eventId;
            $list.append('<p style="margin:0 0 12px;">'
                + '<a href="' + pageUrl + '" target="_blank" class="button button-small">📊 Voir les résultats complets</a>'
                + '</p>');
            if (!resultats || !resultats.length) {
                $list.html('<p style="color:#9ca3af;font-style:italic;">Aucune réponse pour l\'instant.</p>');
                return;
            }
            resultats.forEach(function(res) {
                var q    = res.question;
                var html = '<div style="margin-bottom:14px;padding:10px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;">'
                    + '<strong style="font-size:13px;">' + CAL.esc(q.question) + '</strong>'
                    + ' <em style="font-size:11px;color:#9ca3af;">' + (q.type === 'note' ? 'Note 1-5' : 'Texte libre') + '</em>'
                    + '<br><small style="color:#6b7280;">' + res.nb_reponses + ' réponse(s)</small>';
                if (q.type === 'note' && res.moyenne !== null) {
                    var stars = '';
                    for (var i = 1; i <= 5; i++) { stars += i <= Math.round(res.moyenne) ? '⭐' : '☆'; }
                    html += '<div style="margin-top:6px;">Moyenne : <strong>' + res.moyenne + ' / 5</strong> ' + stars + '</div>';
                } else if (q.type === 'texte' && res.textes && res.textes.length) {
                    html += '<ul style="margin:6px 0 0;padding-left:16px;">';
                    res.textes.forEach(function(t) {
                        html += '<li style="font-style:italic;color:#4b5563;font-size:12px;">"' + CAL.esc(t) + '"</li>';
                    });
                    html += '</ul>';
                } else {
                    html += '<p style="color:#9ca3af;font-style:italic;font-size:12px;">Aucune réponse.</p>';
                }
                html += '</div>';
                $list.append(html);
            });
        },

        saveCompEpreuves: function () {
            if (!CAL.editingEvent) return;
            // Collecter nom + modalite depuis les inputs
            $('#comp-epreuves-list .sp-comp-ep-row').each(function(){
                var idx      = parseInt($(this).data('idx'), 10);
                var nom      = $(this).find('.sp-comp-ep-nom').val().trim();
                var modalite = $(this).find('.sp-comp-ep-modalite').val().trim();
                if (CAL.compEpreuves[idx]) {
                    CAL.compEpreuves[idx].nom      = nom;
                    CAL.compEpreuves[idx].modalite = modalite;
                }
            });
            var payload = CAL.compEpreuves.filter(function(ep){ return ep.nom; })
                .map(function(ep,i){ return {id: ep.id||0, nom: ep.nom, modalite: ep.modalite||'', ordre: i+1}; });
            var btn = $('#btn-save-epreuves').css('opacity','0.5').css('pointer-events','none');
            $.post(SpCal.ajaxurl, {
                action: 'sp_cal_save_comp_epreuves', nonce: SpCal.nonce,
                event_id: CAL.editingEvent.id,
                epreuves: JSON.stringify(payload),
            }, function(res) {
                btn.css('opacity','').css('pointer-events','');
                if (!res.success) { alert('Erreur : '+res.data); return; }
                $('#comp-epreuves-msg').text('✅ Épreuves enregistrées.').show();
                setTimeout(function(){ $('#comp-epreuves-msg').fadeOut(); }, 3000);
                // Recharger pour avoir les IDs
                CAL.loadComp();
            });
        },

        saveCompResultats: function () {
            if (!CAL.editingEvent) return;
            var payload = [];
            var epNames = {};
            CAL.compEpreuves.forEach(function(ep){ epNames[ep.id] = ep.nom; });
            Object.keys(CAL.compResultats).forEach(function(epId){
                Object.keys(CAL.compResultats[epId]).forEach(function(elId){
                    var r = CAL.compResultats[epId][elId];
                    if (!r.medaille && !r.score) return;
                    payload.push({
                        epreuve_id:  parseInt(epId, 10),
                        eleve_id:    parseInt(elId, 10),
                        medaille:    r.medaille || '',
                        score:       r.score    || '',
                        epreuve_nom: epNames[epId] || '',
                    });
                });
            });
            var btn = $('#btn-save-resultats').css('opacity','0.5').css('pointer-events','none');
            $.post(SpCal.ajaxurl, {
                action: 'sp_cal_save_comp_resultats', nonce: SpCal.nonce,
                event_id: CAL.editingEvent.id,
                resultats: JSON.stringify(payload),
            }, function(res) {
                btn.css('opacity','').css('pointer-events','');
                if (!res.success) { alert('Erreur : '+res.data); return; }
                // Redirection vers la page palmarès
                if (res.data && res.data.redirect_url) {
                    $('#comp-resultats-msg').html('✅ Résultats enregistrés. <span style="color:#6b7280;">Redirection vers le palmarès…</span>').show();
                    setTimeout(function(){
                        window.location.href = res.data.redirect_url;
                    }, 1200);
                } else {
                    $('#comp-resultats-msg').text('✅ Résultats enregistrés.').show();
                    setTimeout(function(){ $('#comp-resultats-msg').fadeOut(); }, 3000);
                }
            });
        },

        /* ═══════════════════════════════ DISPONIBILITÉS ENTRAÎNEURS */

        getDispoVal: function (trainer_id, date) {
            var dayDispos = CAL.disposByDate[date] || {};
            var entry     = dayDispos[trainer_id];
            if (!entry) return null;
            return entry.disponible;
        },

        renderDispos: function (date) {
            if (!CAL.trainersList || !CAL.trainersList.length) {
                $('#section-dispos').hide(); return;
            }
            $('#section-dispos').show();
            var dayDispos = CAL.disposByDate[date] || {};
            var html      = '';
            var nb_ok = 0, nb_ko = 0, nb_nr = 0;

            CAL.trainersList.forEach(function(t) {
                var entry      = dayDispos[t.id];
                var dispo      = entry ? entry.disponible : null;
                var note       = entry ? (entry.note || '') : '';
                var nomDisplay = CAL.esc(t.nom_public || t.nom);

                if (dispo === 1)      nb_ok++;
                else if (dispo === 0) nb_ko++;
                else                  nb_nr++;

                // Utilise des <span role="button"> pour éviter les styles WP admin sur <button>
                var btnOk   = '<span class="sp-dispo-act'+(dispo===1?' sp-act-on-ok':'')+'" role="button" tabindex="0" data-trainer-id="'+t.id+'" data-date="'+date+'" data-val="1"   title="Disponible">✅</span>';
                var btnKo   = '<span class="sp-dispo-act'+(dispo===0?' sp-act-on-ko':'')+'" role="button" tabindex="0" data-trainer-id="'+t.id+'" data-date="'+date+'" data-val="0"   title="Indisponible">❌</span>';
                var btnClr  = '<span class="sp-dispo-act sp-dispo-act-clr'+(dispo===null?' sp-act-on-nr':'')+'" role="button" tabindex="0" data-trainer-id="'+t.id+'" data-date="'+date+'" data-val="" title="Effacer">⬜</span>';
                var btnNote = '<span class="sp-dispo-act sp-dispo-act-note" role="button" tabindex="0" data-trainer-id="'+t.id+'" data-date="'+date+'" data-note="'+CAL.esc(note)+'" data-name="'+nomDisplay+'" title="'+(note?'Note : '+CAL.esc(note):'Ajouter une note')+'">✏️</span>';

                var rowCls = dispo===1 ? 'sp-dispo-row sp-dispo-row-ok' : dispo===0 ? 'sp-dispo-row sp-dispo-row-ko' : 'sp-dispo-row sp-dispo-row-nr';
                html += '<div class="'+rowCls+'" id="dispo-row-'+t.id+'">'
                    +'<span class="sp-dispo-avatar">'+(t.nom_public||t.nom).charAt(0).toUpperCase()+'</span>'
                    +'<span class="sp-dispo-name">'+nomDisplay+'</span>'
                    +(note ? '<span class="sp-dispo-note-badge" title="'+CAL.esc(note)+'">'+CAL.esc(note)+'</span>' : '')
                    +'<span class="sp-dispo-acts">'+btnOk+btnKo+btnClr+btnNote+'</span>'
                    +'</div>';
            });

            $('#list-dispos').html(html);

            // Résumé dans le titre
            var sumHtml = '';
            if (nb_ok   > 0) sumHtml += '<span class="sp-dispo-sum-ok">'+nb_ok+'✅</span>';
            if (nb_ko   > 0) sumHtml += '<span class="sp-dispo-sum-ko">'+nb_ko+'❌</span>';
            if (nb_nr   > 0) sumHtml += '<span class="sp-dispo-sum-nr">'+nb_nr+'⬜</span>';
            $('#dispo-summary').html(sumHtml);
        },

        /* ═══════════════════════════════ DOCUMENT UPLOAD */
        uploadDocument: function (evId, file) {
            var fd = new FormData();
            fd.append('action',   'sp_cal_upload_event_doc');
            fd.append('nonce',    SpCal.nonce);
            fd.append('event_id', evId);
            fd.append('document', file);
            $('#doc-upload-progress').show();
            $.ajax({
                url:         SpCal.ajaxurl,
                type:        'POST',
                data:        fd,
                processData: false,
                contentType: false,
                success: function(res) {
                    $('#doc-upload-progress').hide();
                    if (res.success) {
                        if (CAL.editingEvent) { CAL.editingEvent.document_url = res.data.url; CAL.editingEvent.document_nom = res.data.nom; }
                        $('#doc-current-link').attr('href', res.data.url);
                        $('#doc-current-name').text(res.data.nom);
                        $('#doc-current').show();
                        $('#ev-document').val('');
                    } else {
                        alert('Erreur upload : ' + res.data);
                    }
                },
                error: function() { $('#doc-upload-progress').hide(); alert('Erreur réseau lors de l\'upload.'); }
            });
        },

        deleteDocument: function (evId) {
            $.post(SpCal.ajaxurl, {action:'sp_cal_delete_event_doc', nonce:SpCal.nonce, event_id:evId}, function(res){
                if (res.success) {
                    if (CAL.editingEvent) { CAL.editingEvent.document_url = ''; CAL.editingEvent.document_nom = ''; }
                    $('#doc-current').hide();
                    $('#ev-document').val('');
                }
            });
        },

        saveDispo: function (trainer_id, date, disponible, note) {
            // Mémoriser l'état AVANT le changement (pour le récap bureau)
            var beforeState = CAL.disposByDate[date] && CAL.disposByDate[date][trainer_id]
                ? CAL.disposByDate[date][trainer_id].disponible
                : null;

            var btn = $('.sp-dispo-act[data-trainer-id="'+trainer_id+'"][data-date="'+date+'"]').css('opacity','0.4').css('pointer-events','none');
            $.post(SpCal.ajaxurl, {
                action:     'sp_cal_save_trainer_dispo',
                nonce:      SpCal.nonce,
                trainer_id: trainer_id,
                date:       date,
                disponible: disponible,
                note:       note,
            }, function(res) {
                btn.css('opacity','').css('pointer-events','');
                if (!res.success) { alert('Erreur : ' + res.data); return; }

                // Mettre à jour le cache local
                if (!CAL.disposByDate[date]) CAL.disposByDate[date] = {};
                if (disponible === '' || disponible === null) {
                    delete CAL.disposByDate[date][trainer_id];
                } else {
                    CAL.disposByDate[date][trainer_id] = { disponible: parseInt(disponible, 10), note: note };
                }

                // ── Suivre les changements pour la notification bureau (J-3) ──
                var dateTs   = new Date(date).getTime();
                var nowTs    = new Date().setHours(0,0,0,0);
                var diffDays = Math.ceil((dateTs - nowTs) / 86400000);
                var afterVal = (disponible === '' || disponible === null) ? null : parseInt(disponible, 10);

                if (diffDays >= 0 && diffDays <= 7 && beforeState !== afterVal) {
                    // Trouver le nom de l'entraîneur
                    var trainerInfo = CAL.trainersList.find(function(t){ return t.id === trainer_id; });
                    var nom = trainerInfo ? (trainerInfo.nom_public || trainerInfo.nom) : '';

                    if (!CAL.pendingDispoChanges[date]) CAL.pendingDispoChanges[date] = {};
                    CAL.pendingDispoChanges[date][trainer_id] = {
                        trainer_nom:   nom,
                        trainer_email: '',
                        avant:         beforeState,
                        apres:         afterVal,
                        note:          note,
                    };
                    CAL.updateDispoNotifBar(date);
                }

                // Re-render la section dispos + indicateur grille
                CAL.renderDispos(date);
                CAL.refreshDayIndicator(date);
            });
        },

        updateDispoNotifBar: function (date) {
            var changes = CAL.pendingDispoChanges[date];
            if (!changes || !Object.keys(changes).length) {
                $('#dispo-notif-bar').hide(); return;
            }
            // Construire le résumé
            var lines = [];
            Object.values(changes).forEach(function(chg){
                var libApres = chg.apres === 1 ? 'disponible ✅' : chg.apres === 0 ? 'indisponible ❌' : 'non renseigné ⬜';
                lines.push(chg.trainer_nom + ' → ' + libApres);
            });
            $('#dispo-notif-summary').text('Modifications à notifier : ' + lines.join(' · '));
            $('#dispo-notif-bar').show();
            $('#dispo-notif-result').hide();
        },

        refreshDayIndicator: function (date) {
            var $cell = $('.sp-cal-day[data-date="'+date+'"]');
            if (!$cell.length) return;
            var dayDispos = CAL.disposByDate[date] || {};
            $cell.find('.sp-dispo-block').remove();
            var trainerHtml = '';
            CAL.trainersList.forEach(function(t){
                var d = dayDispos[t.id];
                // N'afficher que les disponibles
                if (!d || d.disponible !== 1) return;
                var nom = (t.nom_public || t.nom);
                var displayName = nom.length > 12 ? nom.substring(0,11)+'…' : nom;
                var title = nom + (d.note ? ' — ' + d.note : '');
                trainerHtml += '<div class="sp-trainer-row sp-trainer-ok" title="'+CAL.esc(title)+'">'
                    +'<span class="sp-trainer-icon">👤</span>'
                    +'<span class="sp-trainer-name">'+CAL.esc(displayName)+'</span>'
                    +(d.note?'<span class="sp-trainer-note-dot" title="'+CAL.esc(d.note)+'">●</span>':'')
                    +'</div>';
            });
            if (trainerHtml) $cell.find('.sp-day-num').after('<div class="sp-dispo-block">'+trainerHtml+'</div>');
        },

        /* ═══════════════════════════════ INSCRIPTIONS — INJECTION CHAMPS */
        /* Peuple les cases à cocher catégories dans la modale inscription */
        populateInscCats: function (data, selectedDisciplines, selectedAges) {
            var wrap = $('#ev-insc-cats-wrap');
            if (!wrap.length) return;

            var discipline = (data && data.discipline) ? data.discipline : (Array.isArray(data) ? data : CAL.catsSaisie);
            var age        = (data && data.age) ? data.age : [];

            if (!discipline.length || !age.length) {
                $.post(SpCal.ajaxurl, { action: 'sp_cal_get_cats_saisie', nonce: SpCal.nonce }, function(res) {
                    if (res.success) {
                        CAL.catsSaisie    = res.data.discipline || [];
                        CAL.catsSaisieAge = res.data.age || [];
                        CAL.populateInscCats(res.data, selectedDisciplines, selectedAges);
                    } else {
                        wrap.html('<span style="font-size:12px;color:#9ca3af;">Aucune cat&eacute;gorie d&eacute;finie</span>');
                    }
                });
                return;
            }

            var html = '';

            if (discipline.length) {
                html += '<div style="width:100%;margin-bottom:4px;"><span style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.5px;">Discipline</span></div>';
                discipline.forEach(function(cat) {
                    var chk = (!selectedDisciplines || !selectedDisciplines.length || selectedDisciplines.indexOf(cat) !== -1) ? ' checked' : '';
                    html += '<label style="display:flex;align-items:center;gap:4px;background:#fff;border:1px solid #d1d5db;border-radius:5px;padding:3px 8px;cursor:pointer;font-size:12px;border-left:3px solid #0f70b7;">'
                          + '<input type="checkbox" class="sp-insc-disc-cb" value="' + CAL.esc(cat) + '"' + chk + '>'
                          + CAL.esc(CAL.discLabel(cat)) + '</label>';
                });
            }

            if (age.length) {
                html += '<div style="width:100%;margin-top:6px;margin-bottom:4px;"><span style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.5px;">Tranche d&apos;&acirc;ge</span></div>';
                age.forEach(function(a) {
                    var chk = (!selectedAges || !selectedAges.length || selectedAges.indexOf(a) !== -1) ? ' checked' : '';
                    html += '<label style="display:flex;align-items:center;gap:4px;background:#fff;border:1px solid #d1d5db;border-radius:5px;padding:3px 8px;cursor:pointer;font-size:12px;border-left:3px solid #16a34a;">'
                          + '<input type="checkbox" class="sp-insc-age-cb" value="' + CAL.esc(a) + '"' + chk + '>'
                          + CAL.esc(a) + '</label>';
                });
            }

            if (!html) { html = '<span style="font-size:12px;color:#9ca3af;">Aucune cat&eacute;gorie disponible</span>'; }
            wrap.html(html);
        },

        /* Charge une fois la liste (id, nom) des élèves actifs pour la recherche du ciblage
           manuel — mise en cache dans CAL.elevesPourCiblage (null = pas encore chargé). */
        loadElevesPourCiblage: function () {
            $.post(SpCal.ajaxurl, { action: 'sp_cal_get_eleves_pour_ciblage', nonce: SpCal.nonce }, function(res){
                if (res.success) {
                    CAL.elevesPourCiblage = res.data.eleves || [];
                    CAL.renderExtraChips(); // résout les noms des IDs déjà en attente (ouverture d'un event existant)
                }
            });
        },

        /* Affiche les "chips" (nom + croix pour retirer) des élèves ajoutés manuellement au
           ciblage de l'événement en cours d'édition (CAL.inscExtraIds). */
        renderExtraChips: function () {
            var wrap = $('#ev-insc-extra-chips').empty();
            if (!CAL.elevesPourCiblage) return;
            var byId = {};
            CAL.elevesPourCiblage.forEach(function(el){ byId[el.id] = el.nom; });
            CAL.inscExtraIds.forEach(function(id){
                var nom = byId[id] || ('#' + id);
                wrap.append(
                    '<span style="display:inline-flex;align-items:center;gap:5px;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:12px;padding:3px 6px 3px 10px;font-size:12px;">'
                    + CAL.esc(nom)
                    + ' <span class="sp-insc-extra-remove" data-id="' + id + '" style="cursor:pointer;font-weight:700;padding:0 4px;">×</span>'
                    + '</span>'
                );
            });
        },

        injectInscriptionFields: function () {
            if ($('#insc-section').length) return; // déjà injecté
            var html = '<div id="insc-section" style="margin-top:14px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">'
                + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">'
                +   '<span style="font-weight:600;font-size:13px;">📋 Inscriptions</span>'
                +   '<a id="insc-voir-link" href="#" target="_blank" style="font-size:12px;color:#0073aa;display:none;">📄 Voir la liste →</a>'
                + '</div>'
                + '<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-bottom:8px;">'
                +   '<input type="checkbox" id="ev-insc-actives"> Activer les inscriptions pour cet événement'
                + '</label>'
                + '<div id="insc-options" style="display:none;padding-top:8px;border-top:1px solid #e2e8f0;margin-top:4px;">'
                +   '<div style="margin-bottom:8px;">'
                +     '<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:3px;">🎯 Public ciblé pour l\'email d\'inscription <span style="font-weight:400;">(aucune case cochée = tous les élèves actifs — sans lien avec le "Groupe / catégorie du cours" ci-dessus)</span></label>'
                +     '<div id="ev-insc-cats-wrap" style="display:flex;flex-wrap:wrap;gap:6px;min-height:26px;margin-bottom:4px;"><span style="font-size:12px;color:#9ca3af;font-style:italic;">Chargement…</span></div>'
                +     '<input type="hidden" id="ev-insc-public" value="">'
                +   '</div>'
                +   '<div style="margin-bottom:10px;">'
                +     '<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:3px;">➕ Ajouter des élèves spécifiques <span style="font-weight:400;">(en plus des cases ci-dessus — pour un cas ponctuel, ex : "tous les combattants" indépendamment de l\'âge)</span></label>'
                +     '<input type="text" id="ev-insc-extra-search" class="sp-input" placeholder="Rechercher un élève par nom…" style="width:100%;height:30px;" autocomplete="off">'
                +     '<div id="ev-insc-extra-results" style="display:none;position:relative;z-index:5;border:1px solid #d1d5db;border-radius:5px;background:#fff;max-height:160px;overflow-y:auto;margin-top:2px;"></div>'
                +     '<div id="ev-insc-extra-chips" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;"></div>'
                +   '</div>'
                +   '<div style="margin-bottom:10px;">'
                +     '<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:3px;">Date limite de réponse <span style="font-weight:400;">(optionnel)</span></label>'
                +     '<input type="date" id="ev-insc-deadline" style="height:30px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:13px;">'
                +   '</div>'
                +   '<div style="margin-bottom:4px;">'
                +     '<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:3px;">Message complémentaire <span style="font-weight:400;">(optionnel — inclus dans l\'email d\'invitation)</span></label>'
                +     '<textarea id="ev-insc-message" rows="3" placeholder="Ex : Prévoir repas tiré du sac, tenue de compétition obligatoire…" style="width:100%;border:1px solid #8c8f94;border-radius:4px;padding:6px 8px;font-size:13px;resize:vertical;"></textarea>'
                +   '</div>'
                +   '<div id="insc-send-wrap" style="display:none;padding-top:8px;border-top:1px solid #e2e8f0;">'
                +     '<button type="button" id="insc-send-btn" class="button button-primary" style="margin-right:8px;">📨 Envoyer les invitations</button>'
                +     '<span id="insc-send-result" style="font-size:13px;display:none;"></span>'
                +   '</div>'
                + '</div>'
                + '</div>';
            $('#tab-details').append(html);

            // Toggle options quand on coche/décoche
            $(document).on('change', '#ev-insc-actives', function(){
                var checked = $(this).prop('checked');
                $('#insc-options').toggle(checked);
                if (!checked) {
                    $('#insc-voir-link').hide();
                    $('#insc-send-wrap').hide();
                    $('#insc-send-result').hide();
                }
            });

            // Recherche élèves pour le ciblage manuel ("Ajouter des élèves spécifiques")
            $(document).on('input', '#ev-insc-extra-search', function(){
                var q = $(this).val().trim().toLowerCase();
                var results = $('#ev-insc-extra-results').empty();
                if (!q || !CAL.elevesPourCiblage) { results.hide(); return; }
                var matches = CAL.elevesPourCiblage.filter(function(el){
                    return el.nom.toLowerCase().indexOf(q) !== -1 && CAL.inscExtraIds.indexOf(el.id) === -1;
                }).slice(0, 8);
                if (!matches.length) { results.hide(); return; }
                matches.forEach(function(el){
                    var row = $('<div></div>')
                        .text(el.nom)
                        .css({ padding:'6px 10px', cursor:'pointer', fontSize:'13px' })
                        .on('mouseenter', function(){ $(this).css('background', '#f3f4f6'); })
                        .on('mouseleave', function(){ $(this).css('background', '#fff'); })
                        .on('mousedown', function(e){ e.preventDefault(); }) // évite le blur avant le click
                        .on('click', function(){
                            CAL.inscExtraIds.push(el.id);
                            CAL.renderExtraChips();
                            $('#ev-insc-extra-search').val('');
                            $('#ev-insc-extra-results').hide().empty();
                        });
                    results.append(row);
                });
                results.show();
            });
            $(document).on('blur', '#ev-insc-extra-search', function(){
                setTimeout(function(){ $('#ev-insc-extra-results').hide(); }, 150);
            });
            $(document).on('click', '.sp-insc-extra-remove', function(){
                var id = parseInt($(this).data('id'), 10);
                CAL.inscExtraIds = CAL.inscExtraIds.filter(function(i){ return i !== id; });
                CAL.renderExtraChips();
            });

            // Bouton envoi invitation depuis la modale
            $(document).on('click', '#insc-send-btn', function(){
                var evId = $(this).data('event-id');
                if (!evId) {
                    $('#insc-send-result').css('color','#b91c1c').text('⚠️ Enregistrez d\'abord l\'événement.').show();
                    return;
                }
                if (!confirm('Envoyer les invitations aux élèves éligibles ?')) return;
                var btn = $(this).prop('disabled', true).text('Envoi…');
                var $res = $('#insc-send-result').hide();
                $.post(SpCal.ajaxurl, {
                    action:   'sp_inscription_envoyer',
                    nonce:    SpCal.nonce,
                    event_id: evId,
                    categories: (function(){
                        var checked = [];
                        $('#ev-insc-cats-wrap .sp-insc-disc-cb:checked').each(function(){ checked.push($(this).val()); });
                        return checked;
                    })(),
                    age_categories: (function(){
                        var checked = [];
                        $('#ev-insc-cats-wrap .sp-insc-age-cb:checked').each(function(){ checked.push($(this).val()); });
                        return checked;
                    })(),
                    extra_eleve_ids: CAL.inscExtraIds
                }, function(res){
                    btn.prop('disabled', false).text('🔁 Renvoyer aux non-répondants');
                    if (res.success) {
                        $res.css('color','#15803d').text('✅ ' + res.data.sent + ' invitation(s) envoyée(s) sur ' + res.data.total + ' élève(s).').show();
                        // Mettre à jour le lien vers la liste
                        var inscUrl = (typeof ajaxurl !== 'undefined' ? ajaxurl.replace('admin-ajax.php','') : '') + 'admin.php?page=sp-cal-inscriptions&event_id=' + evId;
                        $('#insc-voir-link').attr('href', inscUrl).show();
                    } else {
                        $res.css('color','#b91c1c').text('❌ ' + (res.data || 'Erreur')).show();
                        btn.text('📨 Envoyer les invitations');
                    }
                }).fail(function(){
                    btn.prop('disabled', false).text('📨 Envoyer les invitations');
                    $res.css('color','#b91c1c').text('❌ Erreur réseau.').show();
                });
            });
        },

        /* ═══════════════════════════════ UTILITAIRES */
        showView: function (v) {
            $('#modal-view-day').toggle(v==='day');
            $('#modal-view-pick').toggle(v==='pick');
            $('#modal-view-form').toggle(v==='form');
        },
        closeModal: function () {
            $('#sp-event-modal').removeClass('open');
            CAL.editingEvent=null; CAL.presences={}; CAL.notes={}; CAL.elevesAll=[]; CAL.elevesByGroup={}; CAL.catsSaisieAge=[];
            $('#pres-count').hide(); $('#exam-count').hide();
            $('#dispo-notif-bar').hide(); $('#dispo-notif-result').hide();
        },
        toTimeInput: function (s) {
            if(!s) return '';
            var m;
            if((m=String(s).match(/^(\d{2})h(\d{2})$/))) return m[1]+':'+m[2];
            if((m=String(s).match(/^(\d{2}):(\d{2})/)))  return m[1]+':'+m[2];
            return s;
        },
        intval: function (v) { return parseInt(v, 10) || 0; },
        esc: function (s) {
            return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        },
        // Libellé lisible d'un code de discipline (TKD/RENFO) — cf. SpCalPro_DB::label_discipline()
        discLabel: function (code) {
            var labels = { TKD: 'Taekwondo', RENFO: 'Renforcement musculaire' };
            return labels[code] || code;
        },
        // Discipline × Pour qui (tranche d'âge) — deux axes fixes remplaçant l'ancien champ texte
        // libre (cf. échange du 18/09/2026). "Autre" couvre les sorties/fêtes qui ne relèvent pas
        // de l'objet sportif du club.
        COURS_DISCIPLINES: ['Taekwondo', 'Renforcement musculaire', 'Autre'],
        COURS_AGES:        ['Baby', 'Enfant', 'Ado/adulte', 'Adulte', 'Tout âge'],

        renderCoursCategorieWrap: function (selectedDiscipline, selectedAge) {
            var html = '';

            html += '<div style="width:100%;margin-bottom:4px;"><span style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.5px;">Discipline</span></div>';
            CAL.COURS_DISCIPLINES.forEach(function(d) {
                var chk = (selectedDiscipline.indexOf(d) !== -1) ? ' checked' : '';
                html += '<label style="display:flex;align-items:center;gap:4px;background:#fff;border:1px solid #d1d5db;border-radius:5px;padding:3px 8px;cursor:pointer;font-size:12px;border-left:3px solid #0f70b7;">'
                      + '<input type="checkbox" class="sp-ev-disc-cb" value="' + CAL.esc(d) + '"' + chk + '>'
                      + CAL.esc(d) + '</label>';
            });

            html += '<div style="width:100%;margin-top:6px;margin-bottom:4px;"><span style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.5px;">Pour qui</span></div>';
            CAL.COURS_AGES.forEach(function(a) {
                var chk = (selectedAge.indexOf(a) !== -1) ? ' checked' : '';
                html += '<label style="display:flex;align-items:center;gap:4px;background:#fff;border:1px solid #d1d5db;border-radius:5px;padding:3px 8px;cursor:pointer;font-size:12px;border-left:3px solid #16a34a;">'
                      + '<input type="checkbox" class="sp-ev-age-cb" value="' + CAL.esc(a) + '"' + chk + '>'
                      + CAL.esc(a) + '</label>';
            });

            $('#ev-categorie-wrap').html(html);
        },
    };

    $(document).ready(function(){
        if($('#sp-cal-container').length) CAL.init();
    });

}(jQuery));

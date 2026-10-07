/* Application mobile [sp_cal_app] — script principal, sorti tel quel de render_pwa_app() (trait-admin-pwa.php) le 07/10/2026 (restructuration, étape 3, phase B).
   Configuration (adresses, clé push, logo…) : objet window.SPCAL, écrit par render_pwa_app() avant ce fichier. */

(function(){
'use strict';

/* ── Config PHP ─────────────────────────────────────────────── */
var CFG = window.SPCAL || {};
var API = CFG.apiBase || '/wp-json/spcal/v1';

/* ── Helpers ────────────────────────────────────────────────── */
function esc(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(ymd) {
    if (!ymd) return '';
    var p = ymd.split('-');
    return p[2]+'/'+p[1]+'/'+p[0];
}
/* Date d'un cours précédée du jour de la semaine (« Lundi 05/10/2026 ») : la date seule ne
   suffit pas à se situer dans la semaine. Date construite en local (pas new Date('Y-m-d'),
   lue en UTC) pour ne pas décaler le jour. */
var JOURS_SEMAINE = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
function fmtDateJour(ymd) {
    if (!ymd) return '';
    var p = ymd.split('-');
    var d = new Date(+p[0], +p[1] - 1, +p[2]);
    return JOURS_SEMAINE[d.getDay()] + ' ' + fmtDate(ymd);
}
function fmtHeure(h) { return h ? h.replace(':','h').substring(0,5) : ''; }
function isToday(ymd)    { return ymd === new Date().toISOString().slice(0,10); }
function isTomorrow(ymd) {
    var t = new Date(); t.setDate(t.getDate()+1);
    return ymd === t.toISOString().slice(0,10);
}
function monthLabel(ymd) {
    if (!ymd) return '';
    var d = new Date(ymd);
    return d.toLocaleDateString('fr-FR',{month:'long',year:'numeric'});
}
function show(id) { var el=document.getElementById(id); if(el) el.style.display='flex'; }
function hide(id) { var el=document.getElementById(id); if(el) el.style.display='none'; }
function showBlock(id){ var el=document.getElementById(id); if(el) el.style.display='block'; }

/* ── Storage ────────────────────────────────────────────────── */
var STORE = {
    get: function(k){ try{ return localStorage.getItem(k); }catch(e){ return null; } },
    set: function(k,v){ try{ localStorage.setItem(k,v); }catch(e){} },
    del: function(k){ try{ localStorage.removeItem(k); }catch(e){} }
};

/* ── Auth ────────────────────────────────────────────────────── */
function getToken() {
    // 1. URL param ?token=
    var params = new URLSearchParams(location.search);
    var t = params.get('token');
    if (t) { STORE.set('spcal_token', t); return t; }
    // 2. Stockage local
    return STORE.get('spcal_token');
}

function getJwt() { return STORE.get('spcal_jwt'); }
function jwtExpired(jwt) {
    try {
        var p = JSON.parse(atob(jwt.split('.')[1].replace(/-/g,'+').replace(/_/g,'/')));
        return !p.exp || p.exp < Math.floor(Date.now()/1000) + 60;
    } catch(e){ return true; }
}

function auth(token) {
    return fetch(API+'/auth', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({token: token})
    }).then(function(r){ return r.json(); });
}

/* ── API calls ───────────────────────────────────────────────── */
function apiFetch(path) {
    var jwt = getJwt();
    return fetch(API+path, { headers: { 'Authorization': 'Bearer '+jwt } }).then(function(r){ return r.json(); });
}

/* ── Initialisation ──────────────────────────────────────────── */
var gEleve = null, gCours = [];

function init() {
    // Entraîneur : ?pin= dans l'URL → ouvrir directement l'onglet pointage
    var urlParams = new URLSearchParams(location.search);
    var pinFromUrl = urlParams.get('pin');
    if (pinFromUrl) {
        STORE.set('spcal_pin', pinFromUrl);
        // Nettoyer le PIN de l'URL sans recharger
        urlParams.delete('pin');
        var cleanUrl = location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        history.replaceState(null, '', cleanUrl);
        initEntraineur(pinFromUrl);
        return;
    }

    // Entraîneur par son lien personnel de dispos (?entraineur=, redirigé depuis la page
    // des dispos par class-trainer-app.php). Clé distincte de spcal_token (élève).
    var trainerFromUrl = urlParams.get('entraineur');
    if (trainerFromUrl) {
        STORE.set('spcal_trainer_token', trainerFromUrl);
        urlParams.delete('entraineur');
        history.replaceState(null, '', location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : ''));
    }
    var trainerTok = trainerFromUrl || (!urlParams.get('token') && STORE.get('spcal_trainer_token'));
    if (trainerTok) {
        initEntraineurToken(trainerTok);
        return;
    }

    initEleve();
}

function initEleve() {
    var token = getToken();
    if (!token) {
        showError('Aucun lien d\'accès trouvé.<br>Utilisez le lien reçu par email.');
        return;
    }

    var jwt = getJwt();
    var needAuth = !jwt || jwtExpired(jwt);

    var authPromise = needAuth
        ? auth(token).then(function(r) {
            if (!r.success) throw new Error(r.message || 'Authentification échouée');
            STORE.set('spcal_jwt', r.jwt);
          })
        : Promise.resolve();

    authPromise
        .then(function() {
            return Promise.all([
                apiFetch('/eleve/me'),
                apiFetch('/calendrier?nb=20')
            ]);
        })
        .then(function(results) {
            var eleve = results[0], cal = results[1];
            if (eleve.code) throw new Error(eleve.message || 'Profil introuvable');
            gEleve = eleve;
            gCours = (cal.cours || []);
            renderApp();
            registerSW();
            spCalSetupPush();
        })
        .catch(function(err) {
            STORE.del('spcal_jwt');
            showError(esc(err.message || 'Erreur de connexion'));
        });
}

/* ── Render ──────────────────────────────────────────────────── */
/* ── Mode entraîneur ────────────────────────────────────────────── */
/* Lien personnel : jeton entraîneur → nom + PIN du pointage + adresse de ses dispos. */
function initEntraineurToken(tt) {
    fetch(API + '/entraineur/session', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({token: tt})
    })
    .then(function(r){ return r.json(); })
    .then(function(r) {
        if (!r || !r.success) { STORE.del('spcal_trainer_token'); initEleve(); return; }
        initEntraineur(r.pin, {nom: r.nom, dispos_url: r.dispos_url, saisie_url: r.saisie_url || ''});
    })
    .catch(function() { showError('Erreur de connexion. Réessayez.'); });
}

var gEntr = null; // {nom, dispos_url} en mode lien personnel
function initEntraineur(pin, opts) {
    var today = new Date().toISOString().slice(0,10);
    fetch(CFG.apiBase + '/pointage/cours', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({pin: pin, date: today})
    })
    .then(function(r){ return r.json(); })
    .then(function(r) {
        if (!r.success) {
            showError('PIN invalide ou expir&eacute;.<br>V&eacute;rifiez votre lien d&#39;acc&egrave;s.');
            return;
        }
        gPin = pin;
        hide('spcal-loading');
        show('spcal-app');
        // Mode entraineur : tous les onglets sauf Carte
        var navPtg2   = document.getElementById('spcal-nav-pointage');
        var navCarte  = document.getElementById('spcal-nav-carte');
        var navEvt    = document.getElementById('spcal-nav-evenements');
        if (navPtg2)  navPtg2.style.display  = 'flex';
        if (navCarte) navCarte.style.display  = 'none';
        if (navEvt)   navEvt.style.display    = 'none';
        document.querySelectorAll('.spcal-nav-btn').forEach(function(b){
            // « Saisie » : jamais affiché par défaut, seulement pour le bureau (ci-dessous).
            if (b.id !== 'spcal-nav-carte' && b.id !== 'spcal-nav-evenements' && b.id !== 'spcal-nav-saisie') b.style.display = 'flex';
        });
        // Membre du bureau (lien personnel) : onglet « 💶 Saisie » vers la saisie rapide de la trésorerie.
        if (opts && opts.saisie_url) {
            var navSaisie = document.getElementById('spcal-nav-saisie');
            var lienSaisie = document.getElementById('spcal-saisie-lien');
            if (navSaisie && lienSaisie) {
                lienSaisie.href = opts.saisie_url;
                navSaisie.style.display = 'flex';
            }
        }
        // Lien personnel : « Calendrier » devient « Mes dispos », pas de déconnexion (lien permanent)
        if (opts && opts.dispos_url) {
            gEntr = opts;
            var navCal = document.getElementById('spcal-nav-calendrier');
            if (navCal) {
                navCal.setAttribute('onclick', "spCalNav('dispos',this)");
                navCal.querySelector('.spcal-nav-ico').textContent = '🗓️';
                navCal.querySelectorAll('span')[1].textContent = 'Mes dispos';
            }
            hide('spcal-ptg-logout');
        }
        // Header entraineur
        document.getElementById('spcal-header-name').textContent = gEntr && gEntr.nom ? gEntr.nom : 'Espace entraîneur';
        document.getElementById('spcal-header-club').textContent = CFG.clubNom || '';
        if (CFG.logoUrl) {
            var img = document.getElementById('spcal-header-logo');
            img.src = CFG.logoUrl; img.style.display = 'block';
            hide('spcal-header-logo-placeholder');
        } else {
            document.getElementById('spcal-header-logo-placeholder').textContent = '📡';
            document.getElementById('spcal-header-logo-placeholder').style.display = 'flex';
        }
        spCalShowPointageDashboard(r.data, today);
        // Lien PIN : directement sur le pointage ; lien personnel : accueil
        if (gEntr) spCalNav('accueil', document.querySelector('.spcal-nav-btn'));
        else spCalNav('pointage', document.getElementById('spcal-nav-pointage'));
        spCalLoadCalendrierClub(pin);
    })
    .catch(function() { showError('Erreur de connexion. Réessayez.'); });
}

function renderApp() {
    hide('spcal-loading');
    show('spcal-app');
    // Mode eleve : onglet pointage masque
    var navPtg = document.getElementById('spcal-nav-pointage');
    if (navPtg) navPtg.style.display = 'none';
    renderHeader();
    renderAccueil();
    renderCarte();
    renderCalendrier();
    renderEvenements();
}

function renderHeader() {
    var el = gEleve;
    document.getElementById('spcal-header-name').textContent = el.prenom + ' ' + el.nom;
    // Logo
    if (el.carte && el.carte.logo_url) {
        var img = document.getElementById('spcal-header-logo');
        img.src = el.carte.logo_url;
        img.style.display = 'block';
        hide('spcal-header-logo-placeholder');
    } else {
        document.getElementById('spcal-header-logo-placeholder').textContent = '🥋';
        hide('spcal-header-logo');
        show('spcal-header-logo-placeholder');
    }
    // Grade
    if (el.grade) {
        var pill = document.getElementById('spcal-grade-pill');
        pill.textContent = el.grade;
        pill.style.display = 'block';
    }
}

function renderAccueil() {
    var el = gEleve;
    var now = new Date();
    var h = now.getHours();
    var salut = h < 12 ? 'Bonjour' : (h < 18 ? 'Bon après-midi' : 'Bonsoir');
    document.getElementById('spcal-greeting').textContent = salut + ' ' + el.prenom + ' 👋';
    document.getElementById('spcal-greeting-sub').textContent = el.grade ? '🥋 ' + el.grade : '';

    // Badge adhésion
    var adh = el.adhesion || {};
    var adhHtml = '';
    if (adh.statut === 'expire') {
        adhHtml = '<div class="spcal-adhesion-badge spcal-adhesion-ko">❌ Renouvellement à faire</div>';
    } else if (adh.statut === 'expire_bientot') {
        adhHtml = '<div class="spcal-adhesion-badge spcal-adhesion-warn">⚠️ Fin de saison dans '+adh.jours+' jours</div>';
    } else {
        adhHtml = '<div class="spcal-adhesion-badge spcal-adhesion-actif">✅ Adhésion active</div>';
    }
    document.getElementById('spcal-adhesion-wrap').innerHTML = adhHtml;

    // Assiduité
    var ass = el.assiduite || {};
    if (ass.total > 0) {
        var color = ass.taux >= 75 ? '#22c55e' : (ass.taux >= 50 ? '#f59e0b' : '#ef4444');
        document.getElementById('spcal-assiduite-wrap').innerHTML =
            '<div class="spcal-assiduite">'+
            '<div class="spcal-assiduite-label">Assiduité saison</div>'+
            '<div class="spcal-assiduite-bar"><div class="spcal-assiduite-fill" style="width:'+ass.taux+'%;background:'+color+'"></div></div>'+
            '<div class="spcal-assiduite-nums">'+ass.present+' présences sur '+ass.total+' cours ('+ass.taux+'%)</div>'+
            '</div>';
    }

    // Fiche complète (grades, présences, doboks)
    var ficheWrap = document.getElementById('spcal-fiche-wrap');
    if (ficheWrap && el.fiche_url) {
        var a = document.createElement('a');
        a.className = 'spcal-fiche-link';
        a.href = el.fiche_url;
        a.innerHTML = '<span class="spcal-fl-ico">📋</span><span><strong>Ma fiche complète</strong><small>Grades, présences, doboks</small></span><span class="spcal-fl-go">›</span>';
        ficheWrap.innerHTML = '';
        ficheWrap.appendChild(a);
    }

    // Prochains cours
    var html = '';
    var shown = gCours.slice(0, 5);
    if (!shown.length) {
        html = '<div class="spcal-empty">Aucun cours à venir</div>';
    } else {
        shown.forEach(function(c) {
            var today    = isToday(c.date);
            var tomorrow = isTomorrow(c.date);
            var cls      = today ? ' today' : (tomorrow ? ' tomorrow' : '');
            var badge    = today ? '<span class="spcal-cours-badge spcal-badge-today">Aujourd\'hui</span>'
                         : (tomorrow ? '<span class="spcal-cours-badge spcal-badge-tomorrow">Demain</span>' : '');
            html += '<div class="spcal-cours-card'+cls+'">'+
                '<div class="spcal-cours-left">'+
                '<div class="spcal-cours-titre">'+esc(c.titre)+'</div>'+
                (c.categorie ? '<div class="spcal-cours-cat">'+esc(c.categorie)+'</div>' : '')+spCalEncadrementHtml(c)+
                '</div>'+
                '<div class="spcal-cours-right">'+
                '<div class="spcal-cours-heure">'+esc(fmtHeure(c.heure_debut))+'</div>'+
                '<div class="spcal-cours-date">'+esc(fmtDateJour(c.date))+'</div>'+
                badge+
                '</div>'+
                '</div>';
        });
    }
    document.getElementById('spcal-prochains-cours').innerHTML = html;
}

function renderCarte() {
    var el = gEleve;
    var carte = el.carte || {};

    // ── Recto ──────────────────────────────────────────────────
    var metaParts = [
        carte.club_affil ? 'FFTDA '+(carte.club_affil||'') : '',
        carte.club_ligue || '',
        carte.club_num   ? 'Club '+carte.club_num : ''
    ].filter(Boolean).join(' · ');

    var infosHtml = '';
    if (el.licence)
        infosHtml += '<div class="sp-carte-info-row"><span class="sp-carte-info-k">Licence</span><span class="sp-carte-info-v">'+esc(el.licence)+'</span></div>';
    if (el.date_naissance && el.annee_naissance)
        infosHtml += '<div class="sp-carte-info-row"><span class="sp-carte-info-k">Né(e) le</span><span class="sp-carte-info-v">'+esc(el.date_naissance+'/'+el.annee_naissance)+'</span></div>';
    if (el.num_passeport)
        infosHtml += '<div class="sp-carte-info-row"><span class="sp-carte-info-k">Passeport</span><span class="sp-carte-info-v">'+esc(el.num_passeport)+'</span></div>';
    if (el.urgence_telephone)
        infosHtml += '<div class="sp-carte-info-row"><span class="sp-carte-info-k">Urgence</span><span class="sp-carte-info-v sp-carte-info-urgence">'+esc(el.urgence_telephone)+'</span></div>';

    var starsHtml = '';
    if (carte.club_labelise > 0) {
        for (var i=1; i<=5; i++)
            starsHtml += '<span style="color:'+(i<=carte.club_labelise?'#ffdd0e':'rgba(255,255,255,0.2)')+';font-size:9px;">★</span>';
        starsHtml = '<div class="sp-carte-stars">'+starsHtml+'</div><div class="sp-carte-labelise-txt">Club labellisé</div>';
    }

    var photoHtml = el.photo_url
        ? '<div class="sp-carte-photo-wrap"><img src="'+esc(el.photo_url)+'" class="sp-carte-photo" alt=""></div>'
        : '';

    document.getElementById('spcal-recto').innerHTML =
        '<div class="sp-carte-band-blue"></div>'+
        '<div class="sp-carte-band-yellow"></div>'+
        '<div class="sp-carte-band-red"></div>'+
        '<div class="sp-carte-logo-wrap">'+
            (carte.logo_url ? '<img src="'+esc(carte.logo_url)+'" class="sp-carte-logo" onerror="this.style.display=\'none\'">' : '')+
        '</div>'+
        '<div class="sp-carte-club-name">'+esc((carte.club_nom||'').toUpperCase())+'</div>'+
        '<div class="sp-carte-club-sub">CARTE DE MEMBRE</div>'+
        '<div class="sp-carte-sep"></div>'+
        '<div class="sp-carte-nom">'+esc(el.prenom+' '+el.nom)+'</div>'+
        '<div class="sp-carte-meta">'+esc(metaParts)+'</div>'+
        '<div class="sp-carte-infos">'+infosHtml+'</div>'+
        '<div class="sp-carte-qr-zone">'+
            '<div id="spcal-qr-box" class="sp-carte-qr-box"></div>'+
            starsHtml+
            '<div class="sp-carte-qr-scan">Scanner pour pointer</div>'+
        '</div>'+
        photoHtml+
        '<div class="sp-carte-url">'+esc((carte.club_nom||'').toLowerCase().replace(/\s/g,'')+'.fr')+'</div>';

    // QR code
    function genQR() {
        if (typeof QRCode === 'undefined') { setTimeout(genQR, 100); return; }
        var box = document.getElementById('spcal-qr-box');
        if (box) new QRCode(box, { text: carte.qr_url||location.href, width:68, height:68, colorDark:'#111', colorLight:'#fff', correctLevel:QRCode.CorrectLevel.M });
    }
    genQR();

    // ── Verso ──────────────────────────────────────────────────
    var versoStars = '';
    if (carte.club_labelise > 0) {
        for (var j=1; j<=5; j++)
            versoStars += '<span style="color:'+(j<=carte.club_labelise?'#e30613':'rgba(255,255,255,0.12)')+';font-size:8px;">★</span>';
    }
    var footerParts = [carte.club_affil, carte.club_num ? 'Club '+carte.club_num : '', (carte.club_nom||'').toLowerCase().replace(/\s/g,'')+'.fr'].filter(Boolean).join(' · ');

    document.getElementById('spcal-verso').innerHTML =
        '<div class="sp-carte-verso-header">'+
            '<div class="sp-carte-verso-logo-wrap">'+
                (carte.logo_url ? '<img src="'+esc(carte.logo_url)+'" class="sp-carte-verso-logo" onerror="this.style.display=\'none\'">' : '')+
            '</div>'+
            '<span class="sp-carte-verso-title">'+esc((carte.club_nom||'').toUpperCase())+'</span>'+
            '<span class="sp-carte-verso-badge">'+esc(el.prenom+' '+el.nom)+'</span>'+
        '</div>'+
        '<div class="sp-carte-verso-body">'+
            '<div class="sp-carte-taegeuk">태권도</div>'+
            '<div class="sp-carte-officiel">Carte de membre officielle</div>'+
        '</div>'+
        '<div class="sp-carte-verso-footer">'+
            '<span>'+esc(footerParts)+'</span>'+
            (versoStars ? '<span>'+versoStars+'</span>' : '')+
        '</div>';

    // Hint installation
    var isIOS     = /iphone|ipad|ipod/i.test(navigator.userAgent);
    var isAndroid = /android/i.test(navigator.userAgent);
    var hint = document.getElementById('spcal-install-hint');
    var txt  = document.getElementById('spcal-install-text');
    if (isIOS) {
        txt.innerHTML = 'Pour installer\u00a0: appuyez sur <strong>Partager \u2b06\ufe0e</strong> puis <strong>Sur l\u2019\u00e9cran d\u2019accueil</strong>.';
        hint.style.display = 'flex';
    } else if (isAndroid) {
        txt.innerHTML = 'Pour installer\u00a0: appuyez sur le menu \u22ee de Chrome puis <strong>Ajouter \u00e0 l\u2019\u00e9cran d\u2019accueil</strong>.';
        hint.style.display = 'flex';
    }
    // Progression de grade
    renderGradeProgression();
}

function renderCalendrier() {
    if (!gCours.length) {
        document.getElementById('spcal-cal-list').innerHTML = '<div class="spcal-empty">Aucun cours à venir</div>';
        return;
    }
    var html = '';
    var lastMonth = '';
    gCours.forEach(function(c) {
        var mois = monthLabel(c.date);
        if (mois !== lastMonth) {
            html += '<div class="spcal-cal-month">'+esc(mois)+'</div>';
            lastMonth = mois;
        }
        var today    = isToday(c.date);
        var tomorrow = isTomorrow(c.date);
        var cls      = today ? ' today' : (tomorrow ? ' tomorrow' : '');
        var badge    = today ? '<span class="spcal-cours-badge spcal-badge-today">Aujourd\'hui</span>'
                     : (tomorrow ? '<span class="spcal-cours-badge spcal-badge-tomorrow">Demain</span>' : '');
        html +=
            '<div class="spcal-cours-card'+cls+'">'+
            '<div class="spcal-cours-left">'+
            '<div class="spcal-cours-titre">'+esc(c.titre)+'</div>'+
            (c.categorie ? '<div class="spcal-cours-cat">'+esc(c.categorie)+'</div>' : '')+spCalEncadrementHtml(c)+
            '</div>'+
            '<div class="spcal-cours-right">'+
            '<div class="spcal-cours-heure">'+esc(fmtHeure(c.heure_debut))+'</div>'+
            '<div class="spcal-cours-date">'+esc(fmtDateJour(c.date))+'</div>'+
            badge+
            '</div>'+
            '</div>';
    });
    document.getElementById('spcal-cal-list').innerHTML = html;
}

/* ── Mon prochain grade (onglet Carte) ───────────────────────────
   Données : gEleve.parcours (SP_Cal_Passages::donnees_eleve()) — programme du grade suivant
   dans TKD Parcours, passage à venir, retour du dernier passage validé. Sans TKD Parcours,
   repli sur l'ancien affichage (grade_vise de la table de progression). */
var SPCAL_NIV = { 2: ['Acquis', 'ok'], 1: ['À revoir', 'rev'], 0: ['Non acquis', 'non'] };
var SPCAL_COUL = { blanche:'#f8fafc', jaune:'#facc15', orange:'#fb923c', verte:'#22c55e', violette:'#8b5cf6', bleue:'#3b82f6', rouge:'#ef4444', noire:'#111' };

function spCalCeinture(grade) {
    var g = String(grade || '').toLowerCase(), cols = [];
    if (/poom/.test(g)) cols = [SPCAL_COUL.rouge, SPCAL_COUL.noire];
    else if (/\bdan\b/.test(g)) cols = [SPCAL_COUL.noire];
    else g.replace(/\*/g, '').split(/[\s\/]+/).forEach(function(m){ if (SPCAL_COUL[m]) cols.push(SPCAL_COUL[m]); });
    if (!cols.length) cols = ['#475569'];
    var fond = cols.length > 1 ? 'linear-gradient(90deg,' + cols[0] + ' 50%,' + cols[1] + ' 50%)' : cols[0];
    return '<span class="spcal-pg-belt"><i style="background:' + fond + '"></i>' + esc(grade) + '</span>';
}
function spCalNote(n) { return (Math.round(n * 100) / 100).toString().replace('.', ','); }
function spCalLienVideo(url, txt) {
    return /^https?:\/\//.test(url || '') ? '<a class="spcal-pg-video" href="' + esc(url) + '" target="_blank" rel="noopener">▶ ' + esc(txt) + '</a>' : '';
}

function renderGradeProgression() {
    var el   = gEleve;
    var wrap = document.getElementById('spcal-grade-progression');
    if (!wrap) return;
    renderGradeRappel();
    var pc = el.parcours;
    if (!pc) {
        wrap.innerHTML = el.grade_vise ? '<div class="spcal-grade-section-title">Prochain grade</div>' +
            '<div class="spcal-grade-next"><strong>' + esc(el.grade_vise) + '</strong></div>' : '';
        return;
    }
    var h = '', r = pc.retour, p = pc.prochain;

    // Retour du dernier passage
    if (r) {
        var admis = r.decision === 'admis';
        h += '<div class="spcal-grade-section-title">Dernier passage de grade</div>'
           + '<div class="spcal-pg-card ' + (admis ? 'admis' : 'ajourne') + '">'
           + '<div class="spcal-pg-res">' + (admis ? '🎉 Admis(e) : ' + spCalCeinture(r.grade_obtenu) : 'Pas encore validé : ' + spCalCeinture(r.grade_vise)) + '</div>'
           + '<div class="spcal-pg-sub">' + esc(r.date_fr) + (r.moyenne != null ? ' · moyenne ' + spCalNote(r.moyenne) + '/10' : '') + '</div>'
           + '<ul class="spcal-pg-crits">';
        r.criteres.forEach(function(c) {
            var badge = c.note != null ? '<span class="spcal-pg-badge">' + spCalNote(c.note) + '/10</span>'
                      : c.niveau != null ? '<span class="spcal-pg-badge ' + SPCAL_NIV[c.niveau][1] + '">' + SPCAL_NIV[c.niveau][0] + '</span>' : '';
            h += '<li><span><strong>' + esc(c.libelle) + '</strong>' + (c.detail ? '<small>' + esc(c.detail) + '</small>' : '') + '</span>' + badge + '</li>';
        });
        h += '</ul>';
        if (r.a_revoir.length) {
            h += '<div class="spcal-pg-revoir"><strong>À travailler :</strong> ' + r.a_revoir.map(esc).join(', ')
               + (admis ? '' : '<br>Courage : retravaillez ces points pour le prochain passage.') + '</div>'
               + spCalLienVideo(r.video_url, 'Revoir la vidéo du programme');
        }
        r.remarques.forEach(function(t) { h += '<div class="spcal-pg-rem">« ' + esc(t) + ' »</div>'; });
        h += '</div>';
    }

    // Prochain grade et son programme
    if (p) {
        h += '<div class="spcal-grade-section-title">Mon prochain grade</div><div class="spcal-pg-card">'
           + '<div class="spcal-pg-res">' + spCalCeinture(p.grade) + '</div>';
        if (pc.prevu) h += '<div class="spcal-pg-prevu">📅 Inscrit(e) au passage de grade du ' + esc(pc.prevu.date_fr) + '</div>';
        if (p.dan) {
            h += '<p class="spcal-pg-sub">L\'examen de ceinture noire (Dan) se passe hors du club : parlez-en avec votre entraîneur.</p>';
        } else {
            if (p.programme && p.programme.length) {
                h += '<div class="spcal-pg-sub">Programme à préparer</div><ul class="spcal-pg-crits">';
                p.programme.forEach(function(c) {
                    h += '<li><span><strong>' + esc(c.libelle) + '</strong><small>' + esc(c.detail) + '</small></span></li>';
                });
                h += '</ul>';
            }
            if (p.ans_avant > 0) h += '<p class="spcal-pg-sub">Âge conseillé pour ce grade : ' + esc(p.min_age) + ' ans.</p>';
            h += spCalLienVideo(p.video_url, 'Voir la vidéo du programme');
        }
        h += '</div>';
    } else if (pc.prevu) {
        h += '<div class="spcal-grade-section-title">Mon prochain grade</div><div class="spcal-pg-card">'
           + '<div class="spcal-pg-prevu">📅 Inscrit(e) au passage de grade du ' + esc(pc.prevu.date_fr) + (pc.prevu.grade_vise ? ' : ' + spCalCeinture(pc.prevu.grade_vise) : '') + '</div></div>';
    }
    wrap.innerHTML = h;
}

/* Rappel sur l'Accueil quand l'adhérent est inscrit à un passage à venir (renvoie vers l'onglet Carte). */
function renderGradeRappel() {
    var wrap = document.getElementById('spcal-grade-rappel');
    if (!wrap) return;
    var pc = gEleve && gEleve.parcours;
    if (!pc || !pc.prevu) { wrap.innerHTML = ''; return; }
    wrap.innerHTML = '<button class="spcal-fiche-link spcal-pg-rappel" onclick="spCalNav(\'carte\', document.getElementById(\'spcal-nav-carte\'))">'
        + '<span class="spcal-fl-ico">🥋</span><span><strong>Passage de grade</strong><small>' + esc(pc.prevu.date_fr)
        + (pc.prevu.grade_vise ? ' · objectif ' + esc(pc.prevu.grade_vise) : '') + ' — voir le programme</small></span><span class="spcal-fl-go">›</span></button>';
}

/* ── Événements & Inscriptions ───────────────────────────────── */
function renderEvenements() {
    var listEl = document.getElementById('spcal-evt-list');
    if (!listEl) return;

    // Agenda du club sur 3 mois (hors cours et anniversaires) : « Vous concerne » pour ce qui
    // vise l'adhérent, « Inscription ouverte » quand il peut répondre (décision du 26/09/2026).
    apiFetch('/eleve/evenements')
    .then(function(r) {
        var evts   = r.evenements || [];
        var filtre = STORE.get('spcal_evt_filtre') === 'moi' ? 'moi' : 'tout';
        var shown  = filtre === 'moi' ? evts.filter(function(ev){ return ev.concerne; }) : evts;

        var html = '<div class="spcal-evt-filtre">' +
            '<button class="' + (filtre === 'tout' ? 'active' : '') + '" onclick="pwaEvtFiltre(\'tout\')">Tout</button>' +
            '<button class="' + (filtre === 'moi'  ? 'active' : '') + '" onclick="pwaEvtFiltre(\'moi\')">Me concerne</button>' +
            '</div>';

        if (!shown.length) {
            listEl.innerHTML = html + '<div class="spcal-empty">' +
                (filtre === 'moi' ? 'Aucun événement ne vous concerne dans les 3 prochains mois' : 'Aucun événement dans les 3 prochains mois') +
                '</div>';
            return;
        }
        shown.forEach(function(ev) {
            var statut  = ev.statut_insc || 'en_attente';
            var insc    = !!ev.inscription;
            var dlOk    = !ev.inscriptions_deadline || ev.inscriptions_deadline >= new Date().toISOString().slice(0,10);
            var dateStr = ev.date ? ('\ud83d\udcc5 ' + fmtDate(ev.date) + (ev.heure_debut ? ' \u00b7 ' + fmtHeure(ev.heure_debut) : '')) : '';
            var tags    = '';
            if (ev.concerne) tags += '<span class="spcal-evt-tag spcal-evt-tag-moi">\ud83c\udfaf Vous concerne</span>';
            if (insc)        tags += '<span class="spcal-evt-tag spcal-evt-tag-insc">\ud83d\udcdd Inscription ouverte</span>';
            var dlStr   = (insc && ev.inscriptions_deadline && dlOk)
                ? '<div class="spcal-evt-deadline">\u23f0 R\u00e9pondre avant le ' + fmtDate(ev.inscriptions_deadline) + '</div>' : '';
            var msgStr  = (insc && ev.inscriptions_message)
                ? '<div class="spcal-evt-msg">' + esc(ev.inscriptions_message) + '</div>' : '';

            var actionsHtml = '';
            if (insc && !dlOk) {
                var badgeCls = statut === 'inscrit' ? 'spcal-evt-badge-ok'
                             : statut === 'refuse'  ? 'spcal-evt-badge-ko'
                             :                        'spcal-evt-badge-wait';
                var badgeLbl = statut === 'inscrit' ? '\u2705 Inscrit(e)'
                             : statut === 'refuse'  ? '\u274c D\u00e9clin\u00e9'
                             :                        '\u23f3 Sans r\u00e9ponse';
                actionsHtml = '<span class="spcal-evt-badge ' + badgeCls + '">' + badgeLbl + '</span>' +
                              '<div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:6px;">\u23f0 D\u00e9lai d\u00e9pass\u00e9</div>';
            } else if (insc && statut === 'inscrit') {
                actionsHtml =
                    '<span class="spcal-evt-badge spcal-evt-badge-ok">\u2705 Inscrit(e)</span>' +
                    '<button class="spcal-evt-btn spcal-evt-btn-annuler" onclick="pwaInscRepondre(' + ev.id + ',\'non\',this)">\u274c Annuler</button>';
            } else if (insc && statut === 'refuse') {
                actionsHtml =
                    '<span class="spcal-evt-badge spcal-evt-badge-ko">\u274c D\u00e9clin\u00e9</span>' +
                    '<button class="spcal-evt-btn spcal-evt-btn-oui" onclick="pwaInscRepondre(' + ev.id + ',\'oui\',this)">Je participe finalement</button>';
            } else if (insc) {
                actionsHtml =
                    '<button class="spcal-evt-btn spcal-evt-btn-oui" onclick="pwaInscRepondre(' + ev.id + ',\'oui\',this)">\u2705 Je participe</button>' +
                    '<button class="spcal-evt-btn spcal-evt-btn-non" onclick="pwaInscRepondre(' + ev.id + ',\'non\',this)">\u274c Je ne peux pas</button>';
            }

            var cardCls = !ev.concerne ? ' statut-info'
                        : !insc ? ' statut-moi'
                        : statut === 'inscrit' ? ' statut-inscrit'
                        : statut === 'refuse'  ? ' statut-refuse' : ' statut-insc';
            html +=
                '<div class="spcal-evt-card' + cardCls + '" id="spcal-evt-' + ev.id + '">' +
                (tags ? '<div class="spcal-evt-tags">' + tags + '</div>' : '') +
                '<div class="spcal-evt-titre">' + esc(ev.titre) + '</div>' +
                '<div class="spcal-evt-meta">' + dateStr + '</div>' +
                dlStr + msgStr +
                (actionsHtml ? '<div class="spcal-evt-actions">' + actionsHtml + '</div>' : '') +
                '</div>';
        });
        listEl.innerHTML = html;
    })
    .catch(function() {
        listEl.innerHTML = '<div class="spcal-empty">Erreur de chargement</div>';
    });
}

window.pwaEvtFiltre = function(f) {
    STORE.set('spcal_evt_filtre', f === 'moi' ? 'moi' : 'tout');
    renderEvenements();
};

window.pwaInscRepondre = function(eventId, reponse, btn) {
    var token = STORE.get('spcal_token');
    if (!token) return;
    var card  = document.getElementById('spcal-evt-' + eventId);
    var actEl = card ? card.querySelector('.spcal-evt-actions') : null;
    if (actEl) actEl.innerHTML = '<span class="spcal-evt-loading">Enregistrement\u2026</span>';

    var fd = new FormData();
    fd.append('action',   'sp_inscription_repondre');
    fd.append('token',    token);
    fd.append('event_id', eventId);
    fd.append('reponse',  reponse);

    fetch(CFG.ajaxurl || '/wp-admin/admin-ajax.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(r) {
        if (!r.success) {
            if (actEl) actEl.innerHTML = '<span class="spcal-evt-loading">Erreur\u2014 r\u00e9essayez.</span>';
            return;
        }
        var ok  = r.data.statut === 'inscrit';
        if (card) card.className = 'spcal-evt-card ' + (ok ? 'statut-inscrit' : 'statut-refuse');
        if (actEl) {
            actEl.innerHTML = ok
                ? '<span class="spcal-evt-badge spcal-evt-badge-ok">\u2705 Inscrit(e)</span>' +
                  '<button class="spcal-evt-btn spcal-evt-btn-annuler" onclick="pwaInscRepondre(' + eventId + ',\'non\',this)">\u274c Annuler</button>'
                : '<span class="spcal-evt-badge spcal-evt-badge-ko">\u274c D\u00e9clin\u00e9</span>' +
                  '<button class="spcal-evt-btn spcal-evt-btn-oui" onclick="pwaInscRepondre(' + eventId + ',\'oui\',this)">Je participe finalement</button>';
        }
    })
    .catch(function() {
        if (actEl) actEl.innerHTML = '<span class="spcal-evt-loading">Erreur r\u00e9seau.</span>';
    });
}

/* ── Navigation ─────────────────────────────────────────────── */
window.spCalNav = function(screen, btn) {
    document.querySelectorAll('.spcal-screen').forEach(function(s){ s.classList.remove('active'); });
    document.querySelectorAll('.spcal-nav-btn').forEach(function(b){ b.classList.remove('active'); });
    var s = document.getElementById('spcal-'+screen);
    if (s) s.classList.add('active');
    if (btn) btn.classList.add('active');
};

/* ── Flip carte ──────────────────────────────────────────────── */
window.spCalFlipCard = function() {
    var inner = document.getElementById('spcal-card-inner');
    if (inner) inner.classList.toggle('flipped');
};

/* ── Erreur ──────────────────────────────────────────────────── */
function showError(msg) {
    hide('spcal-loading');
    hide('spcal-app');
    var err = document.getElementById('spcal-error');
    if (err) err.style.display = 'flex';
    var msgEl = document.getElementById('spcal-error-msg');
    if (msgEl) msgEl.innerHTML = msg;
}

/* ── Service Worker ──────────────────────────────────────────── */
function registerSW() {
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.register(CFG.swUrl || '/?spcal_sw=1', { scope: '/' })
        .catch(function(e){ console.warn('[PWA] SW non enregistré:', e); });
}

/* ── Démarrage ───────────────────────────────────────────────── */
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}


/* ── POINTAGE ENTRAÎNEURS ────────────────────────────────────── */
var gPin = null;
var gPtgCours = null; // cours sélectionné pour le scan
var gScanActive = false;
var gScanStream = null;
var gPtgLog = [];

window.spCalPinSubmit = function() {
    var pin = (document.getElementById('spcal-pin-input').value || '').trim();
    if (!pin) return;
    document.getElementById('spcal-pin-error').style.display = 'none';

    var today = new Date().toISOString().slice(0,10);
    fetch(CFG.apiBase + '/pointage/cours', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({pin: pin, date: today})
    })
    .then(function(r){ return r.json(); })
    .then(function(r) {
        if (!r.success) {
            var errEl = document.getElementById('spcal-pin-error');
            errEl.textContent = r.data || 'PIN invalide';
            errEl.style.display = 'block';
            return;
        }
        gPin = pin;
        STORE.set('spcal_pin', pin);
        spCalShowPointageDashboard(r.data, today);
    })
    .catch(function() {
        var errEl = document.getElementById('spcal-pin-error');
        errEl.textContent = 'Erreur de connexion';
        errEl.style.display = 'block';
    });
};

// Permettre la touche Entrée sur le champ PIN
document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('spcal-pin-input');
    if (input) input.addEventListener('keydown', function(e){ if(e.key==='Enter') spCalPinSubmit(); });
    // Essayer de restaurer le PIN depuis le stockage local
    var savedPin = STORE.get('spcal_pin');
    if (savedPin) {
        var today = new Date().toISOString().slice(0,10);
        fetch(CFG.apiBase + '/pointage/cours', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({pin: savedPin, date: today})
        }).then(function(r){ return r.json(); }).then(function(r){
            if (r.success) { gPin = savedPin; spCalShowPointageDashboard(r.data, today); }
            else { STORE.del('spcal_pin'); }
        }).catch(function(){});
    }
});

function spCalShowPointageDashboard(cours, date) {
    document.getElementById('spcal-pin-screen').style.display = 'none';
    document.getElementById('spcal-pointage-dashboard').style.display = 'block';
    // Date lisible
    var d = new Date(date);
    document.getElementById('spcal-ptg-date').textContent =
        d.toLocaleDateString('fr-FR', {weekday:'long', day:'numeric', month:'long'});
    // Liste des cours
    var html = '';
    if (!cours || !cours.length) {
        html = '<div class="spcal-empty">Aucun cours aujourd&#39;hui</div>';
    } else {
        cours.forEach(function(c) {
            html += '<button class="spcal-ptg-cours-btn" onclick="spCalSelectCours('+JSON.stringify(c).replace(/"/g,'&quot;')+')">'+
                '<div class="spcal-ptg-cours-left">'+
                '<div class="spcal-ptg-cours-btn-titre">'+esc(c.titre)+'</div>'+
                (c.categorie ? '<div class="spcal-ptg-cours-btn-cat">'+esc(c.categorie)+'</div>' : '')+
                '</div>'+
                '<div class="spcal-ptg-cours-heure">'+esc(fmtHeure(c.heure_debut))+'</div>'+
                '</button>';
        });
    }
    document.getElementById('spcal-ptg-cours-list').innerHTML = html;
    spCalLoadAnniv(date);
}

/* ── Anniversaires du mois (class-anniversaires.php) ─────────────
   Pour que l'entraîneur sache QUI on fête à la fin du cours. Liste complète sous les
   cours (par catégorie), et rappel limité à la catégorie du cours une fois choisi. */
var gAnniv = null;
function spCalAnnivNorm(s) {
    return (s || '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z]/g, '');
}
function spCalAnnivMemeCategorie(catEleve, catCours) {
    var a = spCalAnnivNorm(catEleve), b = spCalAnnivNorm(catCours);
    if (!a || !b) return false;
    if (a.indexOf(b) !== -1 || b.indexOf(a) !== -1) return true;
    var adulte = /ado|adulte/;
    return adulte.test(a) && adulte.test(b);
}
function spCalAnnivHtml(liste, titre) {
    var h = '<div class="spcal-anniv"><div class="spcal-anniv-titre">' + titre + '</div>';
    var cat = null;
    liste.forEach(function(a) {
        if (titre.indexOf('du mois') !== -1 && a.categorie !== cat) {
            cat = a.categorie;
            h += '<div class="spcal-anniv-cat">' + esc(cat || 'Sans catégorie') + '</div>';
        }
        h += '<div class="spcal-anniv-ligne' + (a.passe ? ' passe' : '') + (a.aujourdhui ? ' aujourdhui' : '') + '">' +
             '<span class="spcal-anniv-jour">' + a.jour + '</span>' +
             '<span class="spcal-anniv-nom">' + esc(a.nom) + (a.aujourdhui ? ' — aujourd&#39;hui !' : '') + '</span>' +
             (a.age ? '<span class="spcal-anniv-age">' + a.age + ' ans</span>' : '') +
             '</div>';
    });
    return h + '</div>';
}
function spCalLoadAnniv(date) {
    var box = document.getElementById('spcal-ptg-anniv');
    if (!box || !gPin) return;
    fetch(CFG.apiBase + '/anniversaires', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({pin: gPin, date: date})
    })
    .then(function(r){ return r.json(); })
    .then(function(r) {
        if (!r || !r.success) { box.innerHTML = ''; return; }
        gAnniv = r;
        if (!r.data.length) {
            box.innerHTML = '<div class="spcal-anniv"><div class="spcal-anniv-titre">🎂 Anniversaires du mois</div><div class="spcal-anniv-vide">Aucun anniversaire en ' + esc(r.mois) + '.</div></div>';
            return;
        }
        var parCat = r.data.slice().sort(function(a, b) {
            return (a.categorie || '').localeCompare(b.categorie || '') || a.jour - b.jour;
        });
        box.innerHTML = spCalAnnivHtml(parCat, '🎂 Anniversaires du mois (' + esc(r.mois) + ')');
    })
    .catch(function(){ box.innerHTML = ''; });
}
function spCalShowAnnivCours(cours) {
    var box = document.getElementById('spcal-ptg-anniv-cours');
    if (!box) return;
    if (!gAnniv || !gAnniv.data || !cours || !cours.categorie) { box.innerHTML = ''; return; }
    var liste = gAnniv.data.filter(function(a) { return spCalAnnivMemeCategorie(a.categorie, cours.categorie); });
    box.innerHTML = liste.length
        ? spCalAnnivHtml(liste, '🎂 À fêter dans ce cours (' + esc(gAnniv.mois) + ')')
        : '';
}

window.spCalSelectCours = function(cours) {
    gPtgCours = cours;
    gPtgLog = [];
    document.getElementById('spcal-cours-list-wrap').style.display = 'none';
    document.getElementById('spcal-scan-wrap').style.display = 'block';
    document.getElementById('spcal-ptg-cours-title').textContent =
        cours.titre + (cours.heure_debut ? ' · ' + fmtHeure(cours.heure_debut) : '');
    spCalShowAnnivCours(cours);
    document.getElementById('spcal-ptg-log').innerHTML = '';
    document.getElementById('spcal-scan-feedback').style.display = 'none';
    // Nouveau cours : aucune carte encore traitée, pas de pause en cours.
    gScanVus = {};
    gLastScan = '';
    gScanEssai++; // une vérification encore en route pour l'ancien cours sera ignorée
    clearTimeout(gScanRepriseTimer);
    gScanPause = false;
    var ov = document.getElementById('spcal-scan-overlay');
    if (ov) ov.style.display = 'none';
    spCalStartScan();
};

window.spCalBackToCours = function() {
    spCalStopScan();
    document.getElementById('spcal-scan-wrap').style.display = 'none';
    document.getElementById('spcal-cours-list-wrap').style.display = 'block';
    gPtgCours = null;
};

window.spCalPinLogout = function() {
    spCalStopScan();
    gPin = null;
    STORE.del('spcal_pin');
    document.getElementById('spcal-pin-input').value = '';
    document.getElementById('spcal-pin-error').style.display = 'none';
    document.getElementById('spcal-pointage-dashboard').style.display = 'none';
    document.getElementById('spcal-scan-wrap').style.display = 'none';
    document.getElementById('spcal-cours-list-wrap').style.display = 'block';
    document.getElementById('spcal-pin-screen').style.display = 'block';
};

/* ── Scanner QR (API BarcodeDetector ou jsQR, repli multi-CDN) ──
   jsQR depuis cdnjs.cloudflare.com est bloqué silencieusement par l'hébergeur
   OVH (cf. md/SPCalendarPRO(sp-build)—Bug.md, 17/04/2026 — même bug déjà
   résolu sur le scanner /pointage/?pin= de class-token.php, jamais reporté
   ici) : on charge depuis jsdelivr puis unpkg en repli, jamais cdnjs. ── */
function spCalLoadJsQR(cb) {
    if (typeof jsQR !== 'undefined') { cb(); return; }
    var cdns = [
        // Copie locale du plugin d'abord (si présente), puis CDN de repli.
        (window.SPCAL || {}).jsqrUrl || 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js', // copie locale du plugin (SPCAL.jsqrUrl)
        'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js',
        'https://unpkg.com/jsqr@1.4.0/dist/jsQR.js'
    ];
    var idx = 0;
    function tryLoad() {
        if (idx >= cdns.length) { cb(); return; }
        var s = document.createElement('script');
        s.src = cdns[idx++];
        s.onload = cb;
        s.onerror = tryLoad;
        document.head.appendChild(s);
    }
    tryLoad();
}

function spCalScanShowError(titre, detail) {
    var fb = document.getElementById('spcal-scan-feedback');
    fb.className = 'spcal-scan-err';
    fb.style.display = 'block';
    document.getElementById('spcal-scan-nom').textContent = titre;
    document.getElementById('spcal-scan-msg').textContent = detail;
}

function spCalStartScan() {
    if (gScanActive) return;
    gScanActive = true;
    var video = document.getElementById('spcal-qr-video');
    // Diagnostic temporaire (doléances 10/09/2026) : navigator.mediaDevices peut être
    // absent selon le contexte iOS/WebKit -- appeler .getUserMedia() dessus lèverait
    // alors une exception synchrone AVANT la promesse, donc jamais interceptée par le
    // .catch() ci-dessous -- d'où le "rien ne se passe" sans la moindre erreur visible.
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        spCalScanShowError('📷 API caméra indisponible', 'navigator.mediaDevices absent sur ce navigateur/contexte.');
        return;
    }
    try {
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        .then(function(stream) {
            gScanStream = stream;
            video.srcObject = stream;
            video.play();
            // 01/10/2026 — même ordre que le scanner d'identification (class-token.php), qui
            // fonctionne sur les téléphones du club : lecteur intégré du navigateur
            // (BarcodeDetector) d'abord, jsQR seulement s'il est absent. Le forçage de jsQR
            // seul (10/09/2026) laissait la caméra « muette » (cadre vert, aucune réaction).
            // 01/10/2026 (2e retour terrain : « tourne dans le vide ») : sur certains
            // téléphones le lecteur intégré existe mais ne détecte rien ou renvoie des
            // erreurs ignorées. Les deux lecteurs tournent donc en parallèle : le premier
            // qui lit gagne (le doublon est filtré par spCalHandleScanResult).
            gScanLecteurs = { natif: typeof BarcodeDetector !== 'undefined', jsqr: false };
            if (gScanLecteurs.natif) spCalScanWithBarcodeDetector(video);
            spCalScanEtat();
            spCalLoadJsQR(function(){
                if (!gScanActive) return;
                if (typeof jsQR !== 'undefined') {
                    gScanLecteurs.jsqr = true;
                    spCalScanEtat();
                    spCalScanWithJsQR(video);
                } else if (!gScanLecteurs.natif) {
                    spCalScanShowError('📷 Lecteur QR non chargé', 'Ni le lecteur intégré du navigateur ni jsQR ne sont disponibles. Vérifiez la connexion puis rechargez la page.');
                }
            });
        })
        .catch(function(e) {
            spCalScanShowError('📷 Caméra inaccessible', (e && (e.name + ' : ' + e.message)) || 'Autorisez l\'accès à la caméra.');
        });
    } catch (e) {
        spCalScanShowError('📷 Erreur scan', (e && (e.name + ' : ' + e.message)) || String(e));
    }
}

function spCalStopScan() {
    gScanActive = false;
    if (gScanStream) {
        gScanStream.getTracks().forEach(function(t){ t.stop(); });
        gScanStream = null;
    }
}

var gScanLecteurs = { natif: false, jsqr: false };

/* Petite ligne d'état sous la caméra : quel lecteur analyse l'image (aide au diagnostic sur le terrain). */
function spCalScanEtat() {
    var el = document.getElementById('spcal-scan-etat');
    if (!el) return;
    var l = [];
    if (gScanLecteurs.natif) l.push('lecteur du téléphone');
    if (gScanLecteurs.jsqr)  l.push('jsQR');
    el.textContent = l.length ? 'Lecture active : ' + l.join(' + ') : 'Démarrage de la lecture…';
}

function spCalScanWithBarcodeDetector(video) {
    var detector, echecs = 0;
    try { detector = new BarcodeDetector({ formats: ['qr_code'] }); }
    catch (e) { gScanLecteurs.natif = false; spCalScanEtat(); return; } // jsQR prend le relais
    function tick() {
        if (!gScanActive || !gScanLecteurs.natif) return;
        // iOS : readyState n'atteint pas toujours HAVE_ENOUGH_DATA (4), >=2 suffit
        if (video.readyState >= 2 && video.videoWidth > 0) {
            detector.detect(video).then(function(codes) {
                echecs = 0;
                if (codes.length > 0) spCalHandleScanResult(codes[0].rawValue);
            }).catch(function() {
                // Lecteur intégré défaillant sur cet appareil : on l'arrête, jsQR continue seul.
                if (++echecs >= 5) { gScanLecteurs.natif = false; spCalScanEtat(); }
            });
        }
        setTimeout(tick, 300);
    }
    tick();
}

function spCalScanWithJsQR(video) {
    var canvas = document.getElementById('spcal-qr-canvas');
    var ctx = canvas.getContext('2d', { willReadFrequently: true });
    function tick() {
        if (!gScanActive) return;
        if (video.readyState >= 2 && video.videoWidth > 0 && typeof jsQR !== 'undefined') {
            // Image réduite (800 px max) : analyser la vidéo en pleine résolution à chaque
            // image saturait le téléphone, sans détection. Même réglage d'inversion que le
            // scanner d'identification (par défaut : codes clairs sur fond sombre aussi).
            var echelle = Math.min(1, 800 / video.videoWidth);
            canvas.width  = Math.round(video.videoWidth * echelle);
            canvas.height = Math.round(video.videoHeight * echelle);
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = jsQR(img.data, img.width, img.height);
            if (code && code.data) spCalHandleScanResult(code.data);
        }
        setTimeout(tick, 150);
    }
    tick();
}

var gLastScan = '';
var gLastScanTime = 0;
/* 04/10/2026 (retour terrain : le nom s'affichait sous la caméra, hors écran, et une carte laissée
   devant l'objectif répétait « déjà relevé »). Le résultat s'affiche désormais DANS l'image, la
   lecture est en pause pendant l'affichage puis reprend seule, et une carte déjà traitée pour ce
   cours est ignorée sans message. La caméra et les deux lecteurs ne sont jamais arrêtés pour
   cette pause (leur démarrage est la partie fragile) : seules les lectures sont ignorées. */
var gScanPause = false;        // résultat affiché : lectures ignorées jusqu'à la reprise
var gScanVus = {};             // cartes déjà traitées pour le cours en cours (jeton ou contenu lu)
var gScanRepriseTimer = null;
var gScanEssai = 0;            // numéro de la carte en cours de vérification (réponses tardives ignorées)
var SPCAL_SCAN_REPRISE_MS = 2000;

function spCalHandleScanResult(raw) {
    if (gScanPause) return;
    // Dédupliquer : ignorer si même token dans les 3 dernières secondes
    var now = Date.now();
    if (raw === gLastScan && now - gLastScanTime < 3000) return;
    gLastScan = raw;
    gLastScanTime = now;

    // Extraire le token depuis l'URL ?token=xxx ou valeur brute 64hex
    var token = '';
    if (/^[0-9a-f]{64}$/.test(raw)) {
        token = raw;
    } else {
        try {
            var u = new URL(raw);
            token = u.searchParams.get('token') || '';
        } catch(e) { token = ''; }
    }
    // Carte déjà traitée pour ce cours : ignorée sans message (plus de « déjà relevé » en boucle).
    var cle = token || raw;
    if (gScanVus[cle]) return;
    // Ne plus jamais rester muet : un QR lu mais inattendu le dit à l'écran.
    if (!token) { gScanVus[cle] = true; spCalShowFeedback('QR code non reconnu', 'Ce n\'est pas une carte de membre du club.', 'err'); return; }
    if (!gPtgCours || !gPin) { spCalShowFeedback('Aucun cours choisi', 'Revenez à la liste et sélectionnez le cours.', 'err'); return; }

    gScanPause = true;
    spCalScanOverlay('⏳ Lecture de la carte…', '', 'wait');
    // Réseau très lent : ne jamais laisser le scanner bloqué sur « Lecture… ». Une réponse
    // arrivée après ce délai est ignorée (sinon elle remettrait en pause le scan de l'élève
    // suivant) : la carte rescannée affichera « déjà relevé » si la présence est passée.
    var essai = ++gScanEssai;
    var attente = setTimeout(function() {
        gScanEssai++;
        spCalShowFeedback('Pas de réponse du serveur', 'Rescannez la carte.', 'err');
    }, 8000);
    fetch(CFG.apiBase + '/pointage/scan', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            pin:     gPin,
            token:   token,
            slot_id: gPtgCours.slot_id,
            date:    gPtgCours.date
        })
    })
    .then(function(r){ return r.json(); })
    .then(function(r) {
        var nom = (r.data && r.data.nom) ? r.data.nom : '?';
        var status = (r.data && r.data.status) ? r.data.status : (r.success ? 'ok' : 'err');
        var msg  = (r.data && r.data.msg)  ? r.data.msg  : (r.success ? 'Présent' : (r.data || 'Erreur'));
        if (essai !== gScanEssai) return; // réponse trop tardive ou cours quitté
        clearTimeout(attente);
        gScanVus[token] = true; // le serveur a répondu : cette carte est traitée pour ce cours
        spCalShowFeedback(nom, msg, status);
        if (r.success) spCalAddLog(nom, status);
    })
    // Erreur réseau : la carte n'est pas marquée, on pourra la rescanner.
    .catch(function() {
        if (essai !== gScanEssai) return;
        clearTimeout(attente);
        spCalShowFeedback('Erreur réseau', 'Rescannez la carte.', 'err');
    });
}

/* Résultat affiché dans l'image de la caméra, lecture en pause, reprise automatique. */
function spCalShowFeedback(nom, msg, status) {
    var st = status === 'ok' ? 'ok' : (status === 'already' ? 'already' : 'err');
    spCalScanOverlay((st === 'ok' ? '✅ ' : (st === 'already' ? '⚠️ ' : '❌ ')) + nom, msg, st);
    spCalScanFlash(status);
    gScanPause = true;
    clearTimeout(gScanRepriseTimer);
    gScanRepriseTimer = setTimeout(spCalScanReprendre, SPCAL_SCAN_REPRISE_MS);
}

function spCalScanOverlay(nom, msg, cls) {
    var ov = document.getElementById('spcal-scan-overlay');
    if (!ov) return;
    ov.className = 'spcal-scan-overlay ' + cls;
    document.getElementById('spcal-scan-ov-nom').textContent = nom;
    document.getElementById('spcal-scan-ov-msg').textContent = msg;
    ov.style.display = 'flex';
}

/* Fin de la pause (automatique, ou en touchant le bandeau) : les lectures sont de nouveau prises en compte. */
window.spCalScanReprendre = function() {
    // Pendant la vérification d'une carte (bandeau « Lecture… »), on attend la réponse.
    var ov = document.getElementById('spcal-scan-overlay');
    if (ov && ov.className.indexOf('wait') !== -1 && gScanPause) return;
    clearTimeout(gScanRepriseTimer);
    gScanPause = false;
    if (ov) ov.style.display = 'none';
};

/* Éclair sur le cadre + courte vibration (si le téléphone le permet) à chaque résultat. */
function spCalScanFlash(status) {
    var frame = document.getElementById('spcal-scan-frame');
    var cls = status === 'ok' ? 'flash-ok' : (status === 'already' ? 'flash-already' : 'flash-err');
    if (frame) {
        frame.classList.remove('flash-ok', 'flash-already', 'flash-err');
        void frame.offsetWidth; // relance l'effet même si le résultat précédent était identique
        frame.classList.add(cls);
        clearTimeout(frame._timer);
        frame._timer = setTimeout(function(){ frame.classList.remove(cls); }, 900);
    }
    try { if (navigator.vibrate) navigator.vibrate(status === 'ok' ? 80 : [60, 60, 60]); } catch (e) {}
}

function spCalAddLog(nom, status) {
    var now = new Date();
    var heure = now.toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
    var entry = {nom: nom, heure: heure, status: status};
    gPtgLog.unshift(entry);
    var html = gPtgLog.map(function(e) {
        return '<div class="spcal-ptg-log-entry">'+
            '<span class="spcal-ptg-log-nom">'+(e.status==='ok'?'✅ ':'⚠️ ')+esc(e.nom)+'</span>'+
            '<span class="spcal-ptg-log-time">'+esc(e.heure)+'</span>'+
            '</div>';
    }).join('');
    document.getElementById('spcal-ptg-log').innerHTML = html;
}

/* Stopper la caméra quand on quitte l'onglet pointage */
/* Mode entraineur : calendrier club */
/* Encadrement du jour (mode entraîneur, route /calendrier/club) : entraîneurs disponibles d'après
   leurs disponibilités, ou alerte si personne ne s'est encore déclaré. Absent pour les adhérents. */
function spCalEncadrementHtml(c) {
    if (!c || !Array.isArray(c.encadrement)) return '';
    if (!c.encadrement.length) return '<div class="spcal-cours-encadr vide">⚠️ Encadrement non renseigné</div>';
    return '<div class="spcal-cours-encadr">👤 ' + c.encadrement.map(esc).join(', ') + '</div>';
}

function spCalLoadCalendrierClub(pin) {
    var apiBase = CFG.apiBase || '/wp-json/spcal/v1';
    fetch(apiBase + '/calendrier/club?pin=' + encodeURIComponent(pin) + '&nb=20')
        .then(function(r){ return r.json(); })
        .then(function(r) {
            if (!r.cours) return;
            gCours = r.cours;
            var greet = document.getElementById('spcal-greeting');
            var sub   = document.getElementById('spcal-greeting-sub');
            var h = new Date().getHours();
            if (greet) greet.textContent = gEntr && gEntr.nom ? (h < 12 ? 'Bonjour ' : (h < 18 ? 'Bon après-midi ' : 'Bonsoir ')) + gEntr.nom.split(' ')[0] + ' 👋' : 'Prochains cours';
            if (sub)   sub.textContent   = 'Calendrier du club';
            var adh = document.getElementById('spcal-adhesion-wrap');
            var ass = document.getElementById('spcal-assiduite-wrap');
            if (adh) adh.style.display = 'none';
            if (ass) ass.style.display = 'none';
            var html = '';
            gCours.slice(0, 8).forEach(function(c) {
                var today    = isToday(c.date);
                var tomorrow = isTomorrow(c.date);
                var cls   = today ? ' today' : (tomorrow ? ' tomorrow' : '');
                var badge = today
                    ? '<span class="spcal-cours-badge spcal-badge-today">Aujourd&#39;hui</span>'
                    : (tomorrow ? '<span class="spcal-cours-badge spcal-badge-tomorrow">Demain</span>' : '');
                html += '<div class="spcal-cours-card'+cls+'">'
                      + '<div class="spcal-cours-left">'
                      + '<div class="spcal-cours-titre">'+esc(c.titre)+'</div>'
                      + (c.categorie ? '<div class="spcal-cours-cat">'+esc(c.categorie)+'</div>' : '')+spCalEncadrementHtml(c)
                      + '</div>'
                      + '<div class="spcal-cours-right">'
                      + '<div class="spcal-cours-heure">'+esc(fmtHeure(c.heure_debut))+'</div>'
                      + '<div class="spcal-cours-date">'+esc(fmtDateJour(c.date))+'</div>'
                      + badge
                      + '</div></div>';
            });
            if (!html) html = '<div class="spcal-empty">Aucun cours &agrave; venir</div>';
            var el = document.getElementById('spcal-prochains-cours');
            if (el) el.innerHTML = html;
            renderCalendrier();
        })
        .catch(function(){});
}

var _origSpCalNav = window.spCalNav;
window.spCalNav = function(screen, btn) {
    if (screen !== 'pointage') spCalStopScan();
    // Retour sur l'onglet Pointage : toujours la liste des cours du jour, jamais l'écran de
    // scan du dernier cours ouvert (caméra coupée en quittant l'onglet → image noire, et
    // impression qu'il n'y a qu'un seul cours). Retour terrain du 01/10/2026.
    else if (gPtgCours) spCalBackToCours();
    if (screen === 'dispos' && gEntr) spCalLoadDispos();
    _origSpCalNav(screen, btn);
};

/* Page des dispos insérée directement dans l'onglet (pas de cadre iframe) : sur téléphone, un
   doigt posé dans un cadre ne fait pas défiler l'application — une fois le panneau du jour ouvert,
   le cadre couvrait l'écran et tout restait bloqué. Retours terrain du 01/10/2026. */
var gDisposCharge = false;
function spCalLoadDispos() {
    var box = document.getElementById('spcal-dispos-box');
    if (!box || gDisposCharge) return;
    gDisposCharge = true;
    fetch(gEntr.dispos_url, { credentials: 'same-origin' })
        .then(function(r){ if (!r.ok) throw new Error(); return r.text(); })
        .then(function(html) {
            var doc  = new DOMParser().parseFromString(html, 'text/html');
            var app  = doc.getElementById('sp-dispo-app');
            if (!app) throw new Error();
            var scripts = [].slice.call(doc.body.querySelectorAll('script'));
            scripts.forEach(function(s){ s.parentNode.removeChild(s); });
            box.innerHTML = '';
            box.appendChild(document.importNode(app, true));
            // Les scripts insérés par innerHTML ne s'exécutent pas : on les recrée.
            scripts.forEach(function(s) {
                var n = document.createElement('script');
                n.textContent = s.textContent;
                box.appendChild(n);
            });
        })
        .catch(function() {
            gDisposCharge = false;
            box.innerHTML = '<div class="spcal-empty">Impossible de charger vos disponibilités. Réessayez.</div>';
        });
}


/* ── Push subscription ───────────────────────────────────────── */
function spCalSetupPush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    if (!CFG.vapidPublic) return;
    navigator.serviceWorker.ready.then(function(reg) {
        return reg.pushManager.getSubscription().then(function(sub) {
            return sub || reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: spCalUrlB64ToUint8(CFG.vapidPublic)
            });
        });
    }).then(function(sub) {
        if (!sub) return;
        var jwt = STORE.get('spcal_jwt');
        if (!jwt) return;
        fetch(CFG.apiBase + '/push/subscribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + jwt },
            body: JSON.stringify({
                endpoint: sub.endpoint,
                p256dh:   spCalBufToB64u(sub.getKey('p256dh')),
                auth:     spCalBufToB64u(sub.getKey('auth'))
            })
        }).catch(function(){});
    }).catch(function(){});
}

function spCalUrlB64ToUint8(b64) {
    var pad = '='.repeat((4 - b64.length % 4) % 4);
    var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}
function spCalBufToB64u(buf) {
    return btoa(String.fromCharCode.apply(null, new Uint8Array(buf)))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

})();

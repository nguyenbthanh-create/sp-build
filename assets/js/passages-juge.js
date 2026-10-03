/**
 * Passages de grade — page du juge et écran de validation (class-passages.php).
 *
 * Juge (lien personnel ?sp_passage=JETON) : liste des candidats → une fiche par candidat,
 * un bloc par critère (keup : Acquis / À revoir / Non acquis ; Poom : note sur 10 ; mesure :
 * chiffre relevé). Chaque geste est enregistré tout de suite à l'écran puis envoyé au serveur
 * par une file d'attente, une requête à la fois et dans l'ordre (même principe que « Mes
 * dispos ») ; la file et les données sont gardées sur le téléphone : sans réseau, la saisie
 * continue et repart d'elle-même au retour de la connexion.
 *
 * Président (ou admin, ?sp_passage_admin=ID) : onglet Résultats. Les verdicts viennent du
 * serveur (une seule fonction de calcul, SP_Cal_Passages::resultat_candidat()) ; cette page
 * n'en calcule aucun.
 */
(function () {
	'use strict';

	var CFG  = window.SP_PASSAGE || {};
	var CLE  = 'sp_pg_' + (CFG.token || ('admin' + CFG.passage)) + '_';
	var root = document.getElementById('pg-app');

	var NIV = [
		{ v: 2, lib: 'Acquis',     cls: 'ok' },
		{ v: 1, lib: 'À revoir',   cls: 'rev' },
		{ v: 0, lib: 'Non acquis', cls: 'non' }
	];
	var NIV_LIB = { 2: 'Acquis', 1: 'À revoir', 0: 'Non acquis' };

	var S = {
		data: null, erreur: '',
		vue: 'liste', idx: 0, cat: '', recherche: '',
		res: null, resErreur: '', resChargement: false, ouverts: {}, decisions: {},
		file: [], envoi: false, horsLigne: false, toast: ''
	};

	// ─── Stockage local (peut être indisponible : navigation privée) ───────────
	function lire(k) { try { return JSON.parse(localStorage.getItem(CLE + k)); } catch (e) { return null; } }
	function ecrire(k, v) { try { localStorage.setItem(CLE + k, JSON.stringify(v)); } catch (e) {} }

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function nb(v) { return (Math.round(v * 100) / 100).toString().replace('.', ','); }

	// ─── Serveur ────────────────────────────────────────────────────────────────
	function post(action, params) {
		var body = new URLSearchParams();
		body.append('action', 'sp_passage_' + action);
		if (CFG.token) body.append('token', CFG.token);
		else { body.append('passage', CFG.passage); body.append('nonce', CFG.nonce); }
		Object.keys(params || {}).forEach(function (k) { body.append(k, params[k] == null ? '' : params[k]); });
		return fetch(CFG.ajax, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
		}).then(function (r) {
			return r.json().catch(function () { return { success: false, data: 'Réponse inattendue du serveur (' + r.status + ').' }; });
		}).then(function (j) {
			return { ok: !!j.success, data: j.data, reseau: false };
		}).catch(function () {
			return { ok: false, data: 'Pas de connexion.', reseau: true };
		});
	}

	function charger() {
		return post('data').then(function (r) {
			if (r.ok) {
				S.data = r.data; S.horsLigne = false; S.erreur = '';
				S.file.forEach(appliquer); // gestes pas encore envoyés : toujours prioritaires à l'écran
				ecrire('d', S.data);
			} else if (r.reseau) {
				var c = lire('d');
				if (c) { S.data = c; S.horsLigne = true; S.file.forEach(appliquer); }
				else S.erreur = 'Pas de connexion, et ce téléphone n\'a pas encore chargé le passage. Réessayez avec du réseau.';
			} else {
				S.erreur = r.data || 'Erreur de chargement.';
			}
			rendre();
		});
	}

	// ─── Évaluations du juge ───────────────────────────────────────────────────
	function mesEvals(cid) {
		var m = S.data.mes_evals;
		if (Array.isArray(m)) m = S.data.mes_evals = {};
		return m[cid] || (m[cid] = {});
	}

	function appliquer(op) {
		var ev = mesEvals(op.candidat);
		if (op.critere === '_remarque') {
			if (op.remarque) ev._remarque = { remarque: op.remarque }; else delete ev._remarque;
		} else if (op.valeur === '' || op.valeur == null) {
			delete ev[op.critere];
		} else {
			ev[op.critere] = op.mode === 'niveau' ? { niveau: +op.valeur, valeur: null } : { niveau: null, valeur: +op.valeur };
		}
	}

	function noter(op) {
		appliquer(op);
		// Un geste plus récent sur le même critère remplace celui qui attend encore.
		S.file = S.file.filter(function (o, i) { return i === 0 && S.envoi ? true : !(o.candidat === op.candidat && o.critere === op.critere); });
		S.file.push(op);
		ecrire('f', S.file);
		ecrire('d', S.data);
		vider();
	}

	function vider() {
		if (S.envoi || !S.file.length) { majSync(); return; }
		S.envoi = true; majSync();
		var op = S.file[0];
		var params = { candidat: op.candidat, critere: op.critere };
		if (op.critere === '_remarque') params.remarque = op.remarque || ''; else params.valeur = op.valeur;
		post('noter', params).then(function (r) {
			S.envoi = false;
			if (r.reseau) { S.horsLigne = true; majSync(); return; } // on retentera
			S.file.shift(); ecrire('f', S.file);
			S.horsLigne = false;
			if (!r.ok) { afficherToast('⚠️ ' + (r.data || 'Note refusée')); charger(); }
			vider();
		});
	}

	window.addEventListener('online', vider);
	setInterval(vider, 15000);

	// ─── Affichage ──────────────────────────────────────────────────────────────
	var COUL = { blanche: '#f8fafc', blanc: '#f8fafc', jaune: '#facc15', orange: '#fb923c', verte: '#22c55e', vert: '#22c55e',
		violette: '#8b5cf6', bleue: '#3b82f6', bleu: '#3b82f6', rouge: '#ef4444', noire: '#111111', noir: '#111111' };

	function ceinture(grade) {
		if (!grade) return '<span class="pg-belt pg-belt-vide">—</span>';
		var g = grade.toLowerCase(), cols = [];
		if (/poom/.test(g)) cols = [COUL.rouge, COUL.noire];
		else if (/\bdan\b/.test(g)) cols = [COUL.noire];
		else g.replace(/\*/g, '').split(/[\s\/]+/).forEach(function (m) { if (COUL[m]) cols.push(COUL[m]); });
		if (!cols.length) cols = ['#475569'];
		var fond = cols.length > 1 ? 'linear-gradient(90deg,' + cols[0] + ' 50%,' + cols[1] + ' 50%)' : cols[0];
		return '<span class="pg-belt"><i style="background:' + fond + '"></i>' + esc(grade) + '</span>';
	}

	function lecture() { return !S.data.moi || S.data.passage.statut !== 'ouvert'; }

	function noteMode(c, crit) { return c.mode === 'poom' || crit.type !== 'niveaux'; }

	function progres(c) {
		var ev = mesEvals(c.id), n = 0;
		c.criteres.forEach(function (k) { if (ev[k.cle]) n++; });
		return n;
	}

	function rendre() {
		if (S.erreur && !S.data) { root.innerHTML = '<div class="pg-erreur">' + esc(S.erreur) + '</div>'; return; }
		if (!S.data) { root.innerHTML = '<p class="pg-chargement">Chargement…</p>'; return; }
		if (!S.data.moi) S.vue = 'resultats';
		var p = S.data.passage, html = '';

		html += '<header class="pg-top"><div class="pg-top-txt"><div class="pg-titre">' + esc(p.titre) + '</div>'
			+ '<div class="pg-sous">' + esc(p.date_fr) + (S.data.moi ? ' · ' + esc(S.data.moi.nom) + (S.data.moi.president ? ' · président' : '') : ' · administration') + '</div></div>'
			+ '<div id="pg-sync" class="pg-sync"></div></header>';

		if (S.data.moi && S.data.peut_valider) {
			html += '<nav class="pg-onglets"><button data-act="vue" data-v="liste" class="' + (S.vue !== 'resultats' ? 'on' : '') + '">Noter</button>'
				+ '<button data-act="vue" data-v="resultats" class="' + (S.vue === 'resultats' ? 'on' : '') + '">Résultats</button></nav>';
		}
		if (p.statut === 'preparation') html += '<div class="pg-bandeau">⏸ La notation n\'est pas encore ouverte (ou a été suspendue) par l\'organisateur.</div>';
		if (p.statut === 'valide') html += '<div class="pg-bandeau pg-bandeau-ok">✅ Passage validé : les notes ne sont plus modifiables.</div>';

		if (S.vue === 'resultats') html += vueResultats();
		else if (S.vue === 'fiche') html += vueFiche();
		else html += vueListe();

		html += '<div id="pg-toast" class="pg-toast' + (S.toast ? ' on' : '') + '">' + esc(S.toast) + '</div>';
		root.innerHTML = html;
		majSync();
	}

	function majSync() {
		var el = document.getElementById('pg-sync');
		if (!el) return;
		var n = S.file.length;
		if (S.horsLigne) { el.className = 'pg-sync warn'; el.textContent = n ? '📴 ' + n + ' en attente' : '📴 Hors ligne'; }
		else if (n) { el.className = 'pg-sync wait'; el.textContent = '⏳ ' + n; }
		else { el.className = 'pg-sync ok'; el.textContent = '✓ À jour'; }
	}

	var toastTimer = null;
	function afficherToast(t) {
		S.toast = t;
		var el = document.getElementById('pg-toast');
		if (el) { el.textContent = t; el.className = 'pg-toast on'; }
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { S.toast = ''; var e = document.getElementById('pg-toast'); if (e) e.className = 'pg-toast'; }, 3500);
	}

	// ─── Liste des candidats ────────────────────────────────────────────────────
	function candidatsFiltres() {
		var q = S.recherche.toLowerCase().trim();
		return S.data.candidats.filter(function (c) {
			if (S.cat && c.categorie !== S.cat) return false;
			return !q || (c.prenom + ' ' + c.nom).toLowerCase().indexOf(q) !== -1;
		});
	}

	function vueListe() {
		var cands = S.data.candidats;
		if (!cands.length) return '<p class="pg-vide">Aucun candidat dans ce passage.</p>';
		var cats = [];
		cands.forEach(function (c) { if (c.categorie && cats.indexOf(c.categorie) === -1) cats.push(c.categorie); });
		var html = '<div class="pg-filtres">';
		if (cats.length > 1) {
			html += '<div class="pg-chips"><button data-act="cat" data-v="" class="' + (!S.cat ? 'on' : '') + '">Tous</button>';
			cats.forEach(function (k) { html += '<button data-act="cat" data-v="' + esc(k) + '" class="' + (S.cat === k ? 'on' : '') + '">' + esc(k) + '</button>'; });
			html += '</div>';
		}
		html += '<input type="search" id="pg-recherche" placeholder="Rechercher…" value="' + esc(S.recherche) + '"></div><ul class="pg-liste">';
		candidatsFiltres().forEach(function (c) {
			var n = progres(c), t = c.criteres.length;
			html += '<li><button data-act="ouvrir" data-id="' + c.id + '">'
				+ '<span class="pg-nom">' + esc(c.prenom) + ' ' + esc(c.nom.toUpperCase()) + (c.alerte_age ? ' <span class="pg-alerte-mini">⚠</span>' : '') + '</span>'
				+ '<span class="pg-grades">' + ceinture(c.grade_actuel) + ' → ' + ceinture(c.grade_vise) + '</span>'
				+ '<span class="pg-prog' + (t && n === t ? ' fini' : '') + '">' + (S.data.moi ? n + '/' + t : '') + '</span>'
				+ '</button></li>';
		});
		return html + '</ul>';
	}

	// ─── Fiche d'un candidat ────────────────────────────────────────────────────
	function vueFiche() {
		var liste = candidatsFiltres();
		if (!liste.length) { S.vue = 'liste'; return vueListe(); }
		if (S.idx >= liste.length) S.idx = liste.length - 1;
		var c = liste[S.idx], ev = mesEvals(c.id), ro = lecture();
		var html = '<div class="pg-fiche">'
			+ '<div class="pg-nav"><button data-act="prec" ' + (S.idx === 0 ? 'disabled' : '') + '>◀</button>'
			+ '<button data-act="vue" data-v="liste" class="pg-nav-liste">Liste · ' + (S.idx + 1) + '/' + liste.length + '</button>'
			+ '<button data-act="suiv" ' + (S.idx >= liste.length - 1 ? 'disabled' : '') + '>▶</button></div>'
			+ '<h2 class="pg-cand">' + esc(c.prenom) + ' ' + esc(c.nom.toUpperCase()) + '</h2>'
			+ '<div class="pg-grades pg-grades-xl">' + ceinture(c.grade_actuel) + ' → ' + ceinture(c.grade_vise)
			+ (c.mode === 'poom' ? ' <span class="pg-tag">notes /10</span>' : '') + '</div>';
		if (c.alerte_age) html += '<div class="pg-alerte">⚠ Trop jeune de ' + c.alerte_age + ' an' + (c.alerte_age > 1 ? 's' : '') + ' pour ce grade (âge conseillé du Parcours).</div>';
		if (!c.criteres.length) html += '<div class="pg-bandeau">Aucun critère : le grade visé n\'est pas dans TKD Parcours et aucune épreuve transverse ne s\'applique.</div>';

		c.criteres.forEach(function (k) {
			var e = ev[k.cle];
			html += '<section class="pg-crit"><div class="pg-crit-lib"><strong>' + esc(k.libelle) + '</strong>'
				+ (k.detail ? '<span>' + esc(k.detail) + '</span>' : '') + '</div>';
			if (k.type === 'mesure') {
				var mv = e && e.valeur != null ? e.valeur : '';
				html += '<div class="pg-mesure"><input type="number" inputmode="decimal" min="0" step="any" data-act="mesure" data-crit="' + esc(k.cle) + '" value="' + esc(mv) + '"' + (ro ? ' disabled' : '') + '>'
					+ '<span>' + esc(k.unite || '') + '</span></div>'
					+ (k.seuil_acquis != null ? '<div class="pg-aide">' + (c.mode === 'poom' ? nb(k.seuil_acquis) + ' ' + esc(k.unite) + ' = 10/10' : 'Acquis ≥ ' + nb(k.seuil_acquis) + (k.seuil_revoir != null ? ' · à revoir ≥ ' + nb(k.seuil_revoir) : '')) + '</div>' : '');
			} else if (noteMode(c, k)) {
				var nv = e && e.valeur != null ? e.valeur : null;
				html += '<div class="pg-note"><button data-act="note" data-crit="' + esc(k.cle) + '" data-d="-0.5"' + (ro ? ' disabled' : '') + '>−</button>'
					+ '<span class="pg-note-val">' + (nv == null ? '—' : nb(nv)) + '<small>/10</small></span>'
					+ '<button data-act="note" data-crit="' + esc(k.cle) + '" data-d="0.5"' + (ro ? ' disabled' : '') + '>+</button>'
					// Toujours présent (masqué sans note) : son apparition ne doit pas décaler le « + ».
					+ '<button class="pg-effacer" data-act="effacer" data-crit="' + esc(k.cle) + '"' + (nv == null || ro ? ' disabled style="visibility:hidden"' : '') + ' aria-label="Effacer la note">✕</button></div>';
			} else {
				var cur = e && e.niveau != null ? e.niveau : null;
				html += '<div class="pg-niv">';
				NIV.forEach(function (n) {
					html += '<button data-act="niv" data-crit="' + esc(k.cle) + '" data-v="' + n.v + '" class="' + n.cls + (cur === n.v ? ' on' : '') + '"' + (ro ? ' disabled' : '') + '>' + n.lib + '</button>';
				});
				html += '</div>';
			}
			html += '</section>';
		});

		var rem = ev._remarque ? ev._remarque.remarque : '';
		html += '<section class="pg-crit"><div class="pg-crit-lib"><strong>Remarque</strong><span>Facultatif — transmise à l\'élève avec son résultat</span></div>'
			+ '<textarea id="pg-remarque" rows="3"' + (ro ? ' disabled' : '') + ' placeholder="Points forts, points à travailler…">' + esc(rem) + '</textarea></section>';

		html += '<div class="pg-bas"><span id="pg-resume">' + resume(c) + '</span>'
			+ (S.idx < liste.length - 1 ? '<button data-act="suiv" class="pg-btn">Candidat suivant →</button>' : '<button data-act="vue" data-v="liste" class="pg-btn">Retour à la liste</button>') + '</div>';
		return html + '</div>';
	}

	/** Récapitulatif des notes de CE juge (pas un verdict : le verdict est calculé côté serveur). */
	function resume(c) {
		var ev = mesEvals(c.id), restant = 0, compte = { 2: 0, 1: 0, 0: 0 }, notes = [];
		c.criteres.forEach(function (k) {
			var e = ev[k.cle];
			if (!e) { restant++; return; }
			if (e.niveau != null) compte[e.niveau]++;
			else if (e.valeur != null && k.type !== 'mesure') notes.push(e.valeur);
		});
		var t = [];
		if (c.mode === 'poom') { if (notes.length) t.push('moyenne de vos notes ' + nb(notes.reduce(function (a, b) { return a + b; }, 0) / notes.length) + '/10'); }
		else {
			if (compte[2]) t.push(compte[2] + ' acquis');
			if (compte[1]) t.push(compte[1] + ' à revoir');
			if (compte[0]) t.push(compte[0] + ' non acquis');
		}
		if (restant) t.push(restant + ' à noter');
		return t.length ? 'Vos notes : ' + t.join(' · ') : '';
	}

	// ─── Résultats et validation (président / admin) ────────────────────────────
	function chargerResultats() {
		S.resChargement = true; rendre();
		post('resultats').then(function (r) {
			S.resChargement = false;
			if (r.ok) { S.res = r.data; S.resErreur = ''; S.data.passage = r.data.passage; majDecisions(); }
			else S.resErreur = r.reseau ? 'Pas de connexion : les résultats ont besoin du réseau.' : (r.data || 'Erreur');
			rendre();
		});
	}

	/** Décision par défaut = proposition du calcul, tant que le président ne l'a pas changée. */
	function majDecisions() {
		S.res.candidats.forEach(function (c) {
			var d = S.decisions[c.id];
			if (c.decision) { S.decisions[c.id] = { decision: c.decision, grade: c.grade_obtenu || c.grade_vise, manuel: true }; return; }
			if (d && d.manuel) return;
			var prop = c.resultat.proposition;
			S.decisions[c.id] = prop === 'admis' || prop === 'ajourne' ? { decision: prop, grade: c.grade_vise, manuel: false } : { decision: '', grade: c.grade_vise, manuel: false };
		});
	}

	var PROP = { admis: ['Admis', 'ok'], ajourne: ['Ajourné', 'non'], incomplet: ['Incomplet', 'rev'] };

	function vueResultats() {
		if (S.resErreur) return '<div class="pg-erreur">' + esc(S.resErreur) + ' <button data-act="actualiser" class="pg-btn">Réessayer</button></div>';
		if (!S.res) return '<p class="pg-chargement">Chargement des résultats…</p>';
		var p = S.data.passage, valide = p.statut === 'valide', ouvert = p.statut === 'ouvert';
		var nA = 0, nJ = 0, nI = 0, sansDecision = 0;
		S.res.candidats.forEach(function (c) {
			var pr = c.resultat.proposition;
			if (pr === 'admis') nA++; else if (pr === 'ajourne') nJ++; else nI++;
			if (!(S.decisions[c.id] || {}).decision) sansDecision++;
		});

		var html = '<div class="pg-res-tete"><div>Proposés : <b class="ok">' + nA + ' admis</b> · <b class="non">' + nJ + ' ajourné' + (nJ > 1 ? 's' : '') + '</b>'
			+ (nI ? ' · <b class="rev">' + nI + ' incomplet' + (nI > 1 ? 's' : '') + '</b>' : '') + '</div>'
			+ '<button data-act="actualiser" class="pg-btn pg-btn-sec">' + (S.resChargement ? '…' : '↻ Actualiser') + '</button></div>'
			+ '<p class="pg-aide">Juges : ' + S.res.juges.map(function (j) { return esc(j.nom) + (j.president ? ' (président)' : ''); }).join(', ')
			+ '. Keup : avis majoritaire par critère, égalité tranchée par le président ; admis sans « Non acquis » et avec au plus un « À revoir ». Poom : moyenne ≥ ' + nb(p.poom_seuil) + '/10 et aucune épreuve sous ' + nb(p.poom_plancher) + '/10.</p>';

		S.res.candidats.forEach(function (c) {
			var r = c.resultat, d = S.decisions[c.id] || {}, ouv = !!S.ouverts[c.id];
			var desacc = r.criteres.some(function (l) { return l.desaccord || l.statut === 'egalite'; });
			html += '<div class="pg-res' + (r.proposition === 'incomplet' ? ' incomplet' : '') + '">'
				+ '<button class="pg-res-ligne" data-act="deplier" data-id="' + c.id + '">'
				+ '<span class="pg-nom">' + esc(c.prenom) + ' ' + esc(c.nom.toUpperCase()) + (c.alerte_age ? ' <span class="pg-alerte-mini">⚠ ' + c.alerte_age + ' an' + (c.alerte_age > 1 ? 's' : '') + '</span>' : '') + (desacc ? ' <span class="pg-desacc">≠</span>' : '') + '</span>'
				+ '<span class="pg-grades">' + ceinture(c.grade_actuel) + ' → ' + ceinture(c.grade_vise) + '</span>'
				+ '<span class="pg-prop ' + PROP[r.proposition][1] + '">' + PROP[r.proposition][0] + (r.moyenne != null ? ' · ' + nb(r.moyenne) : '') + '</span></button>';

			if (ouv) {
				html += '<div class="pg-res-detail">' + (r.raison ? '<p class="pg-aide">' + esc(r.raison) + '</p>' : '');
				r.criteres.forEach(function (l) {
					var votes = l.votes.map(function (v) {
						return esc(v.juge) + ' : ' + (c.mode === 'poom' ? nb(v.valeur) : NIV_LIB[v.valeur]) + (v.brut != null && c.mode !== 'poom' && l.cle.indexOf('e:') === 0 ? ' (' + nb(v.brut) + ')' : '');
					}).join(' · ');
					var agr = l.statut === 'manquant' ? '<span class="rev">pas encore noté</span>'
						: l.statut === 'egalite' ? '<span class="rev">égalité</span>'
						: c.mode === 'poom' ? '<b>' + nb(l.moyenne) + '/10</b>'
						: '<b class="' + ({ 2: 'ok', 1: 'rev', 0: 'non' })[l.niveau] + '">' + NIV_LIB[l.niveau] + '</b>' + (l.arbitre ? ' <small>(tranché)</small>' : '');
					html += '<div class="pg-res-crit' + (l.desaccord || l.statut === 'egalite' ? ' desacc' : '') + '"><div><strong>' + esc(l.libelle) + '</strong>' + (l.detail ? ' <small>' + esc(l.detail) + '</small>' : '') + '</div>'
						+ '<div class="pg-res-agr">' + agr + '</div>' + (votes ? '<div class="pg-res-votes">' + votes + '</div>' : '');
					if (c.mode !== 'poom' && ouvert && (l.statut === 'egalite' || l.arbitre)) {
						html += '<div class="pg-niv pg-niv-sm">';
						NIV.forEach(function (n) {
							html += '<button data-act="arb" data-id="' + c.id + '" data-crit="' + esc(l.cle) + '" data-v="' + n.v + '" class="' + n.cls + (l.arbitre && l.niveau === n.v ? ' on' : '') + '">' + n.lib + '</button>';
						});
						if (l.arbitre) html += '<button data-act="arb" data-id="' + c.id + '" data-crit="' + esc(l.cle) + '" data-v="" class="pg-effacer">✕</button>';
						html += '</div>';
					}
					html += '</div>';
				});
				if (r.remarques.length) {
					html += '<div class="pg-res-rem"><strong>Remarques</strong>' + r.remarques.map(function (m) { return '<p><b>' + esc(m.juge) + ' :</b> ' + esc(m.texte) + '</p>'; }).join('') + '</div>';
				}
				html += '</div>';
			}

			// Décision finale
			html += '<div class="pg-decision">';
			if (valide) {
				html += c.decision === 'admis' ? '<b class="ok">✅ Admis — ' + esc(c.grade_obtenu) + '</b>' : '<b class="non">Ajourné</b>';
			} else if (ouvert) {
				html += '<button data-act="dec" data-id="' + c.id + '" data-v="admis" class="ok' + (d.decision === 'admis' ? ' on' : '') + '">Admis</button>'
					+ '<button data-act="dec" data-id="' + c.id + '" data-v="ajourne" class="non' + (d.decision === 'ajourne' ? ' on' : '') + '">Ajourné</button>';
				if (d.decision === 'admis') html += '<input type="text" data-act="grade" data-id="' + c.id + '" value="' + esc(d.grade || '') + '" title="Grade obtenu (modifiable pour un saut)">';
			}
			html += '</div></div>';
		});

		if (ouvert) {
			html += '<div class="pg-valider"><button data-act="valider" class="pg-btn pg-btn-xl"' + (sansDecision ? ' disabled' : '') + '>Valider les grades</button>'
				+ '<p class="pg-aide">' + (sansDecision ? sansDecision + ' candidat' + (sansDecision > 1 ? 's' : '') + ' sans décision.' : 'Les grades des admis seront écrits sur leur fiche ; le passage sera ensuite verrouillé.') + '</p></div>';
		}
		return html;
	}

	// ─── Événements ─────────────────────────────────────────────────────────────
	function ficheCourante() { return candidatsFiltres()[S.idx]; }

	function critDe(c, cle) { for (var i = 0; i < c.criteres.length; i++) if (c.criteres[i].cle === cle) return c.criteres[i]; return null; }

	root.addEventListener('click', function (e) {
		var b = e.target.closest('[data-act]');
		if (!b || b.disabled) return;
		var act = b.getAttribute('data-act'), v = b.getAttribute('data-v'), c, crit, ev;

		if (act === 'vue') { S.vue = v; if (v === 'resultats') chargerResultats(); rendre(); window.scrollTo(0, 0); return; }
		if (act === 'cat') { S.cat = v; rendre(); return; }
		if (act === 'ouvrir') {
			var id = +b.getAttribute('data-id');
			S.idx = candidatsFiltres().findIndex(function (x) { return x.id === id; });
			S.vue = 'fiche'; rendre(); window.scrollTo(0, 0); return;
		}
		if (act === 'prec') { S.idx--; rendre(); window.scrollTo(0, 0); return; }
		if (act === 'suiv') { S.idx++; rendre(); window.scrollTo(0, 0); return; }

		if (act === 'niv' || act === 'note' || act === 'effacer') {
			c = ficheCourante(); if (!c || lecture()) return;
			crit = b.getAttribute('data-crit'); ev = mesEvals(c.id)[crit];
			if (act === 'niv') {
				var n = +v, deja = ev && ev.niveau === n;
				noter({ candidat: c.id, critere: crit, mode: 'niveau', valeur: deja ? '' : n }); // retoucher le même bouton l'efface
			} else if (act === 'note') {
				var cur = ev && ev.valeur != null ? ev.valeur : null;
				var nv = cur == null ? 5 : Math.max(0, Math.min(10, cur + parseFloat(b.getAttribute('data-d'))));
				noter({ candidat: c.id, critere: crit, mode: 'valeur', valeur: nv });
			} else {
				noter({ candidat: c.id, critere: crit, mode: 'valeur', valeur: '' });
			}
			rendre(); return;
		}

		if (act === 'actualiser') { chargerResultats(); return; }
		if (act === 'deplier') { var k = b.getAttribute('data-id'); S.ouverts[k] = !S.ouverts[k]; rendre(); return; }
		if (act === 'dec') {
			var d = S.decisions[b.getAttribute('data-id')] || (S.decisions[b.getAttribute('data-id')] = { grade: '' });
			d.decision = v; d.manuel = true; rendre(); return;
		}
		if (act === 'arb') {
			b.disabled = true;
			post('arbitrer', { candidat: b.getAttribute('data-id'), critere: b.getAttribute('data-crit'), niveau: v }).then(function (r) {
				if (r.ok) { S.res.candidats = r.data.candidats; majDecisions(); }
				else afficherToast('⚠️ ' + (r.data || 'Erreur'));
				rendre();
			});
			return;
		}
		if (act === 'valider') {
			var dec = {}, nA = 0, nJ = 0;
			S.res.candidats.forEach(function (x) {
				var dd = S.decisions[x.id];
				dec[x.id] = { decision: dd.decision, grade: dd.grade || '' };
				if (dd.decision === 'admis') nA++; else nJ++;
			});
			if (!window.confirm('Valider le passage ?\n\n' + nA + ' admis, ' + nJ + ' ajourné(s).\nLes grades des admis seront écrits sur leur fiche et le passage sera verrouillé.')) return;
			b.disabled = true; b.textContent = 'Validation…';
			post('valider', { decisions: JSON.stringify(dec) }).then(function (r) {
				if (r.ok) { S.res.candidats = r.data.candidats; S.data.passage = r.data.passage; majDecisions(); ecrire('d', S.data); afficherToast('✅ Grades validés.'); }
				else afficherToast('⚠️ ' + (r.data || 'Erreur'));
				rendre();
			});
		}
	});

	root.addEventListener('change', function (e) {
		var t = e.target, c;
		if (t.id === 'pg-remarque') {
			c = ficheCourante(); if (!c || lecture()) return;
			noter({ candidat: c.id, critere: '_remarque', remarque: t.value.trim() });
			majSync(); return;
		}
		if (t.getAttribute('data-act') === 'mesure') {
			c = ficheCourante(); if (!c || lecture()) return;
			var val = t.value.trim() === '' ? '' : Math.max(0, parseFloat(t.value.replace(',', '.')) || 0);
			noter({ candidat: c.id, critere: t.getAttribute('data-crit'), mode: 'valeur', valeur: val });
			// Pas de rendre() : l'écran reconstruit volerait le champ que le juge vient de toucher.
			var r = document.getElementById('pg-resume');
			if (r) r.textContent = resume(c);
			return;
		}
		if (t.getAttribute('data-act') === 'grade') {
			var d = S.decisions[t.getAttribute('data-id')];
			if (d) { d.grade = t.value.trim(); d.manuel = true; }
		}
	});

	root.addEventListener('input', function (e) {
		if (e.target.id === 'pg-recherche') {
			S.recherche = e.target.value;
			var pos = e.target.selectionStart;
			rendre();
			var el = document.getElementById('pg-recherche');
			if (el) { el.focus(); try { el.setSelectionRange(pos, pos); } catch (x) {} }
		}
	});

	// ─── Démarrage ──────────────────────────────────────────────────────────────
	S.file = lire('f') || [];
	if (!CFG.token) S.vue = 'resultats';
	charger().then(function () {
		if (S.vue === 'resultats' && S.data) chargerResultats();
		vider();
	});
})();

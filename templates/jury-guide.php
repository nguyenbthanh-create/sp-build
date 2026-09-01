<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<style>
.spg-wrap { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #0f172a; line-height: 1.6; padding: 10px 0 60px; }
.spg-hero { background:#0f172a; color:#fff; border-radius:12px; padding:28px 32px; margin-bottom:28px; position:relative; overflow:hidden; }
.spg-hero::before { content:'⚖️'; position:absolute; right:28px; top:16px; font-size:64px; opacity:.1; }
.spg-hero h1 { font-size:22px; font-weight:700; margin:0 0 6px; }
.spg-hero p  { color:#94a3b8; font-size:14px; margin:0; max-width:520px; }
.spg-hero .spg-v { display:inline-block; margin-top:12px; font-family:monospace; font-size:11px; background:rgba(255,255,255,.1); padding:2px 10px; border-radius:20px; color:#cbd5e1; }

.spg-section { background:#fff; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:16px; overflow:hidden; }
.spg-sec-hd  { display:flex; align-items:center; gap:12px; padding:14px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
.spg-num     { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; color:#fff; flex-shrink:0; }
.spg-sec-hd h2   { font-size:15px; font-weight:700; margin:0; }
.spg-sec-hd .sub { font-size:12px; color:#64748b; margin:0; }
.spg-body    { padding:18px 20px; }

/* Steps */
.spg-step { display:flex; gap:14px; padding:12px 0; border-bottom:1px solid #f1f5f9; align-items:flex-start; }
.spg-step:last-child { border-bottom:none; }
.spg-sico  { width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; background:#f8fafc; border:1px solid #e2e8f0; }
.spg-step h3 { font-size:13px; font-weight:700; margin:0 0 3px; }
.spg-step p  { font-size:12px; color:#64748b; margin:0; }
.spg-step code { background:#f1f5f9; padding:1px 5px; border-radius:3px; font-size:11px; }

/* Actors */
.spg-actors { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.spg-actor  { border:1px solid #e2e8f0; border-radius:8px; padding:14px; }
.spg-actor .ico { font-size:24px; margin-bottom:6px; }
.spg-actor h3   { font-size:13px; font-weight:700; margin:0 0 4px; }
.spg-actor p    { font-size:12px; color:#64748b; margin:0 0 8px; }
.spg-access     { display:inline-block; font-size:10px; font-family:monospace; padding:2px 8px; border-radius:4px; font-weight:600; }

/* Modes */
.spg-modes { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.spg-mode  { border:2px solid #e2e8f0; border-radius:8px; padding:14px; }
.spg-mode.on { border-color:#2563eb; background:#eff6ff; }
.spg-mode h3  { font-size:13px; font-weight:700; margin:0 0 6px; }
.spg-mode ul  { padding-left:16px; font-size:12px; color:#64748b; margin:0; }
.spg-mode ul li { margin-bottom:3px; }
.spg-mbadge { display:inline-block; font-size:10px; font-weight:700; padding:2px 8px; border-radius:10px; margin-bottom:6px; text-transform:uppercase; }

/* Tips */
.spg-tip  { display:flex; gap:8px; padding:10px 14px; border-radius:7px; font-size:12px; margin-top:12px; }
.spg-tip p { margin:0; }
.spg-tip.info { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; }
.spg-tip.warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
.spg-tip.ok   { background:#f0fdf4; border:1px solid #bbf7d0; color:#14532d; }

/* Mock */
.spg-mock { background:#f8fafc; border:1px solid #e2e8f0; border-radius:7px; padding:12px; font-size:12px; margin-top:10px; }
.spg-mhd  { font-weight:700; font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; margin-bottom:8px; }
.spg-mrow { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; padding:7px 10px; background:#fff; border:1px solid #e2e8f0; border-radius:5px; margin-bottom:5px; }
.spg-mrow:last-child { margin-bottom:0; }
.spg-mname  { font-weight:600; }
.spg-mgrade { color:#64748b; font-size:11px; }
.spg-mbtns  { display:flex; gap:5px; align-items:center; }
.spg-minus { background:#fee2e2; color:#dc2626; border:none; border-radius:4px; padding:3px 9px; font-weight:700; }
.spg-val   { background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; padding:3px 9px; font-weight:800; }
.spg-plus  { background:#dcfce7; color:#16a34a; border:none; border-radius:4px; padding:3px 9px; font-weight:700; }
.spg-ok    { background:#dcfce7; color:#16a34a; padding:2px 8px; border-radius:10px; font-weight:700; font-size:11px; }
.spg-ko    { background:#fee2e2; color:#dc2626; padding:2px 8px; border-radius:10px; font-weight:700; font-size:11px; }
.spg-wait  { background:#f3f4f6; color:#6b7280; padding:2px 8px; border-radius:10px; font-weight:700; font-size:11px; }

/* Score table */
.spg-tbl   { width:100%; border-collapse:collapse; font-size:12px; border-radius:7px; overflow:hidden; }
.spg-tbl th { background:#f1f5f9; padding:7px 12px; text-align:left; color:#64748b; font-size:11px; border-bottom:1px solid #e2e8f0; }
.spg-tbl td { padding:7px 12px; border-bottom:1px solid #f1f5f9; }
.spg-tbl tr:last-child td { border-bottom:none; font-weight:700; background:#f8fafc; }

/* Scope */
.spg-scope { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.spg-scol  { border-radius:8px; padding:14px; font-size:12px; }
.spg-scol h4 { font-size:12px; font-weight:700; margin:0 0 8px; }
.spg-scol ul { padding-left:16px; margin:0; }
.spg-scol li { margin-bottom:3px; color:#64748b; }

/* Flow */
.spg-flow { display:flex; align-items:center; flex-wrap:wrap; gap:4px; margin-bottom:12px; }
.spg-fn   { padding:5px 12px; border-radius:6px; font-size:12px; font-weight:600; white-space:nowrap; }
.spg-fa   { color:#94a3b8; font-size:14px; }

/* Progression table */
.spg-prog-tbl { width:auto; border-collapse:collapse; font-size:12px; margin-bottom:10px; }
.spg-prog-tbl th { background:#f1f5f9; padding:6px 14px; text-align:left; color:#64748b; font-size:11px; border:1px solid #e2e8f0; }
.spg-prog-tbl td { padding:6px 14px; border:1px solid #e2e8f0; }

.spg-calc { background:#f8fafc; border:1px solid #e2e8f0; border-radius:7px; padding:14px; font-size:12px; margin-bottom:12px; }
.spg-calc code { display:block; font-family:monospace; font-size:11px; line-height:1.8; color:#374151; }
</style>

<div class="spg-wrap">

  <!-- Hero -->
  <div class="spg-hero">
    <h1>Guide — Jury d'examen</h1>
    <p>Organisation complète d'une session de passage de grade, de la configuration à la saisie des notes en temps réel.</p>
    <span class="spg-v">v10.17c · Passe 1 · Lecture seule sur la fiche élève</span>
  </div>

  <!-- 1. Acteurs -->
  <div class="spg-section">
    <div class="spg-sec-hd">
      <div class="spg-num" style="background:#7c3aed">1</div>
      <div><h2>Les 3 acteurs</h2><p class="sub">Qui accède à quoi</p></div>
    </div>
    <div class="spg-body">
      <div class="spg-actors">
        <div class="spg-actor">
          <div class="ico">🛠️</div>
          <h3>Administrateur</h3>
          <p>Crée la session, configure les tables et paramètres, gère les juges, suit la centrale en direct et réaffecte les candidats.</p>
          <span class="spg-access" style="background:#eff6ff;color:#2563eb;">WP Admin → ⚖️ Jury</span>
        </div>
        <div class="spg-actor">
          <div class="ico">⚖️</div>
          <h3>Juge</h3>
          <p>Saisit ses notes via un lien personnel. Aucun compte WordPress requis. Fonctionne sur mobile.</p>
          <span class="spg-access" style="background:#f0fdf4;color:#16a34a;">?jury_token=xxxx</span>
        </div>
        <div class="spg-actor">
          <div class="ico">📋</div>
          <h3>Observateur table</h3>
          <p>Vue agrégée d'une table : toutes les notes de tous les juges, scores et verdicts. Lecture seule.</p>
          <span class="spg-access" style="background:#fefce8;color:#ca8a04;">?jury_table_token=xxxx</span>
        </div>
      </div>
      <div class="spg-tip info">
        <span>ℹ️</span>
        <p>Les juges reçoivent leur lien par email (si entraîneur avec email renseigné) ou par copier-coller. Le lien fonctionne sans connexion WP.</p>
      </div>
    </div>
  </div>

  <!-- 2. Processus -->
  <div class="spg-section">
    <div class="spg-sec-hd">
      <div class="spg-num" style="background:#2563eb">2</div>
      <div><h2>Processus complet — dans l'ordre</h2><p class="sub">7 étapes de la préparation au résultat</p></div>
    </div>
    <div class="spg-body">
      <div>
        <div class="spg-step">
          <div class="spg-sico">📅</div>
          <div>
            <h3>1 — Créer l'événement examen</h3>
            <p>Calendrier → créer un événement de type <code>examen</code>. Seuls les événements de ce type apparaissent dans le menu Jury.</p>
          </div>
        </div>
        <div class="spg-step">
          <div class="spg-sico">⚙️</div>
          <div>
            <h3>2 — Configurer la session (onglet Configuration)</h3>
            <p>Sélectionner l'examen · Choisir le <strong>mode</strong> · Saisir <strong>Note min / Note max</strong> (ex: 5–10) · Saisir le <strong>Seuil d'admission</strong> (score total minimum, ex: 5,5) · Créer les <strong>tables</strong> et leur affecter une épreuve si mode par épreuve · <strong>Sauvegarder</strong>.</p>
          </div>
        </div>
        <div class="spg-step">
          <div class="spg-sico">👤</div>
          <div>
            <h3>3 — Affecter les juges (onglet Juges)</h3>
            <p>Pour chaque table : ajouter les juges (entraîneur du club → auto-fill, ou externe → saisie libre). Sauvegarder, puis cliquer <strong>📧 Générer token</strong> par juge. Copier le lien si pas d'email.</p>
          </div>
        </div>
        <div class="spg-step">
          <div class="spg-sico">📊</div>
          <div>
            <h3>4 — Assigner les candidats aux tables (onglet Centrale live)</h3>
            <p>Tous les élèves actifs du club apparaissent. Cocher les candidats → choisir une table → cliquer <strong>Assigner</strong>. L'auto-actualisation se met en pause pendant la sélection.</p>
          </div>
        </div>
        <div class="spg-step">
          <div class="spg-sico">🟢</div>
          <div>
            <h3>5 — Démarrer la session (onglet Configuration)</h3>
            <p>Bouton <strong>🟢 Démarrer</strong> → statut passe à <code>en_cours</code>. Déverrouille la saisie sur les interfaces juge. Avant ce clic, les juges voient l'interface mais ne peuvent pas saisir.</p>
          </div>
        </div>
        <div class="spg-step">
          <div class="spg-sico">✏️</div>
          <div>
            <h3>6 — Saisie des notes par les juges</h3>
            <p>Chaque juge ouvre son lien. Il voit ses candidats avec les boutons <strong>+1 / −1</strong> par épreuve. Chaque clic est enregistré immédiatement (✓ vert = sync OK, ✗ rouge = erreur réseau). Sync automatique toutes les 20s.</p>
          </div>
        </div>
        <div class="spg-step">
          <div class="spg-sico">🔴</div>
          <div>
            <h3>7 — Terminer + lire les résultats</h3>
            <p>Bouton <strong>🔴 Terminer</strong> → interfaces juge en lecture seule. La Centrale affiche les verdicts finaux et le grade visé. Pour un saut exceptionnel, saisir le grade dans le champ pointillé colonne "Grade visé".</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 3. Modes -->
  <div class="spg-section">
    <div class="spg-sec-hd">
      <div class="spg-num" style="background:#ea580c">3</div>
      <div><h2>Les deux modes de notation</h2><p class="sub">À choisir avant de créer les tables</p></div>
    </div>
    <div class="spg-body">
      <div class="spg-modes">
        <div class="spg-mode">
          <div class="spg-mbadge" style="background:#eff6ff;color:#2563eb;">Par épreuve</div>
          <h3>Mode par épreuve</h3>
          <ul>
            <li>Chaque table = <strong>une seule épreuve</strong> (ex: Table 1 = Poomsae)</li>
            <li>Les candidats <strong>se déplacent</strong> de table en table</li>
            <li>Le juge note uniquement son épreuve</li>
            <li>Pour grands groupes avec rotation</li>
          </ul>
        </div>
        <div class="spg-mode on">
          <div class="spg-mbadge" style="background:#dcfce7;color:#16a34a;">Par catégorie</div>
          <h3>Mode par catégorie</h3>
          <ul>
            <li>Chaque table = <strong>un groupe de candidats</strong> fixe</li>
            <li>Les juges évaluent <strong>toutes les épreuves</strong> de leur groupe</li>
            <li>Pas d'épreuve assignée à la table</li>
            <li>Pour petits groupes ou passages individuels</li>
          </ul>
        </div>
      </div>
      <div class="spg-tip warn">
        <span>⚠️</span>
        <p>Le mode est défini avant la création des tables. Si vous changez de mode après coup, sauvegardez et rechargez la page.</p>
      </div>
    </div>
  </div>

  <!-- 4. Interface juge -->
  <div class="spg-section">
    <div class="spg-sec-hd">
      <div class="spg-num" style="background:#16a34a">4</div>
      <div><h2>Interface juge — ce que voit le juge</h2><p class="sub">Accès via son lien personnel</p></div>
    </div>
    <div class="spg-body">
      <div class="spg-flow">
        <div class="spg-fn" style="background:#eff6ff;color:#2563eb;">Email reçu</div>
        <span class="spg-fa">→</span>
        <div class="spg-fn" style="background:#f0fdf4;color:#16a34a;">Clic lien</div>
        <span class="spg-fa">→</span>
        <div class="spg-fn" style="background:#fefce8;color:#ca8a04;">Page juge</div>
        <span class="spg-fa">→</span>
        <div class="spg-fn" style="background:#f0fdf4;color:#16a34a;">Saisie +1/−1</div>
        <span class="spg-fa">→</span>
        <div class="spg-fn" style="background:#f3f4f6;color:#374151;">Sync 20s</div>
      </div>
      <div class="spg-mock">
        <div class="spg-mhd">⚖️ Table 1 · Poomsae · En cours · Notation 5–10 · Seuil 5,5</div>
        <div class="spg-mrow">
          <span class="spg-mname">Léa BARBÉ</span>
          <span class="spg-mgrade">POOM · Ado/adulte</span>
          <div class="spg-mbtns">
            <button class="spg-minus">−</button>
            <span class="spg-val">7</span>
            <button class="spg-plus">+</button>
          </div>
          <span class="spg-ok">7 / 10 ✅</span>
        </div>
        <div class="spg-mrow">
          <span class="spg-mname">Guillem CABANÉ</span>
          <span class="spg-mgrade">8° jaune · Ado/adulte</span>
          <div class="spg-mbtns">
            <button class="spg-minus">−</button>
            <span class="spg-val">5</span>
            <button class="spg-plus">+</button>
          </div>
          <span class="spg-ko">5 / 10 ❌</span>
        </div>
        <div class="spg-mrow">
          <span class="spg-mname">Cassandre BARBIER</span>
          <span class="spg-mgrade">1° keup · Ado/adulte</span>
          <div class="spg-mbtns">
            <button class="spg-minus">−</button>
            <span class="spg-val">5</span>
            <button class="spg-plus">+</button>
          </div>
          <span class="spg-wait">en attente</span>
        </div>
      </div>
      <div class="spg-tip ok">
        <span>✅</span>
        <p>Chaque clic est sauvegardé <strong>immédiatement</strong>. Pas de bouton "Valider" — la saisie est continue. En cas d'erreur réseau, la note locale est annulée automatiquement (✗ rouge).</p>
      </div>
    </div>
  </div>

  <!-- 5. Calcul -->
  <div class="spg-section">
    <div class="spg-sec-hd">
      <div class="spg-num" style="background:#ca8a04">5</div>
      <div><h2>Calcul du score et admission</h2><p class="sub">Comment le verdict est déterminé</p></div>
    </div>
    <div class="spg-body">
      <p style="font-size:13px;margin-bottom:12px;">Le score total = <strong>somme de toutes les notes de tous les juges sur toutes les épreuves</strong>, comparé au <strong>seuil d'admission</strong>.</p>
      <div class="spg-calc">
        <strong style="font-size:12px;">Exemple : 2 juges · 3 épreuves · notation 5–10 · seuil 16</strong>
        <code>
Juge A : Poomsae 7 + Combat 6 + Zemita 5 = 18
Juge B : Poomsae 6 + Combat 7 + Zemita 6 = 19
Score total = 18 + 19 = 37 ≥ 16 → ✅ Admis
        </code>
      </div>
      <p style="font-size:12px;color:#64748b;margin-bottom:10px;"><strong style="color:#0f172a;">Grade visé</strong> — calculé automatiquement depuis la table Progression grades (grade actuel → grade suivant). Pour un saut exceptionnel (+2 keup), l'admin saisit le grade manuellement dans le champ pointillé colonne "Grade visé" de la Centrale.</p>
      <div class="spg-tip warn">
        <span>⚠️</span>
        <p><strong>Passe 1 :</strong> le grade n'est <strong>jamais écrit automatiquement</strong> sur la fiche élève. L'admin lit les résultats dans la Centrale et met à jour manuellement. Écriture automatique prévue en Passe 2.</p>
      </div>
    </div>
  </div>

  <!-- 6. Progression grades -->
  <div class="spg-section">
    <div class="spg-sec-hd">
      <div class="spg-num" style="background:#7c3aed">6</div>
      <div><h2>Configurer la progression des grades</h2><p class="sub">Onglet "Progression grades" — à faire une seule fois</p></div>
    </div>
    <div class="spg-body">
      <p style="font-size:13px;margin-bottom:12px;">Tableau linéaire : pour chaque grade actuel, quel est le grade visé en cas de réussite. Unique, indépendant de l'âge.</p>
      <table class="spg-prog-tbl">
        <thead><tr><th>Grade actuel</th><th>Grade visé</th></tr></thead>
        <tbody>
          <tr><td>15° keup</td><td>14° keup</td></tr>
          <tr><td>14° keup</td><td>13° keup</td></tr>
          <tr><td>8° jaune</td><td>7° bleu</td></tr>
          <tr><td>1° keup</td><td>1er Dan</td></tr>
          <tr><td style="color:#94a3b8;">...</td><td style="color:#94a3b8;">...</td></tr>
        </tbody>
      </table>
      <div class="spg-tip info">
        <span>ℹ️</span>
        <p>Les libellés doivent correspondre <strong>exactement</strong> aux grades saisis sur les fiches élèves, casse incluse. Ex : si la fiche contient <code>8° jaune *</code>, saisir <code>8° jaune *</code> ici.</p>
      </div>
    </div>
  </div>

  <!-- 7. Périmètre -->
  <div class="spg-section">
    <div class="spg-sec-hd">
      <div class="spg-num" style="background:#64748b">7</div>
      <div><h2>Périmètre Passe 1</h2><p class="sub">Ce qui est inclus et ce qui ne l'est pas encore</p></div>
    </div>
    <div class="spg-body">
      <div class="spg-scope">
        <div class="spg-scol" style="background:#f0fdf4;border:1px solid #bbf7d0;">
          <h4 style="color:#16a34a;">✅ Inclus dans Passe 1</h4>
          <ul>
            <li>Configuration session (mode, notes, seuil)</li>
            <li>Tables et juges (entraîneurs + externes)</li>
            <li>Tokens juges + envoi email</li>
            <li>Interface juge +1/−1 par épreuve</li>
            <li>Vue table agrégée (lecture seule)</li>
            <li>Interface centrale admin en direct</li>
            <li>Assignation groupée de candidats</li>
            <li>Réaffectation individuelle en cours d'examen</li>
            <li>Calcul score + verdict admis/ajourné</li>
            <li>Grade visé auto + override manuel</li>
            <li>Mapping progression des grades</li>
          </ul>
        </div>
        <div class="spg-scol" style="background:#fef2f2;border:1px solid #fecaca;">
          <h4 style="color:#dc2626;">🔜 Passe 2 — pas encore</h4>
          <ul>
            <li>Écriture automatique du grade sur la fiche élève</li>
            <li>Envoi groupé des résultats aux candidats/parents</li>
            <li>Interface mobile optimisée</li>
            <li>Impression palmarès d'examen (PDF)</li>
            <li>Historique des sessions passées</li>
          </ul>
        </div>
      </div>
    </div>
  </div>

</div>

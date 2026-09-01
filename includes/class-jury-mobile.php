<?php
/**
 * SP_Cal — Interface juge mobile (QR code)
 * Fichier : class-jury-mobile.php
 *
 * Gère l'interface mobile des juges via QR code par aire.
 * URL : ?page=sp-cal-jury&jury_mobile=1&event_id=X&aire_id=Y&token=Z
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_JuryMobile' ) ) :

class SpCalPro_JuryMobile {

    private $db;

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;
    }

    /* ══════════════════════════════════════════════════════════
       POINT D'ENTRÉE — appelé depuis class-jury.php si jury_mobile=1
    ══════════════════════════════════════════════════════════ */

    public function render(): void {
        $event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;
        $aire_id  = isset( $_GET['aire_id']  ) ? (int) $_GET['aire_id']  : 0;
        $token    = isset( $_GET['token']    ) ? sanitize_text_field( $_GET['token'] ) : '';

        // Validation paramètres
        if ( ! $event_id || ! $aire_id ) {
            wp_die( 'Paramètres manquants.', 'Erreur', array( 'response' => 400 ) );
        }

        // Token HMAC par aire (ne dépend pas de la session, ne expire jamais)
        $token_attendu = $this->generate_token( $event_id, $aire_id );
        if ( ! hash_equals( $token_attendu, $token ) ) {
            wp_die( 'QR code invalide. Régénérez les QR codes depuis l\'interface admin.', 'Sécurité', array( 'response' => 403 ) );
        }

        // Récupérer les données
        global $wpdb;
        $te      = $this->db->table_events();
        $tt      = $this->db->table_exam_tables();
        $session = $this->db->get_jury_session( $event_id );

        if ( ! $session ) {
            wp_die( 'Session jury introuvable.', 'Erreur', array( 'response' => 404 ) );
        }

        if ( $session->statut !== 'en_cours' ) {
            $this->render_locked( $session->statut );
            return;
        }

        $event = $wpdb->get_row( $wpdb->prepare( "SELECT titre, date FROM $te WHERE id = %d LIMIT 1", $event_id ) );
        $aire  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tt WHERE id = %d AND event_id = %d LIMIT 1", $aire_id, $event_id ) );

        if ( ! $aire ) {
            wp_die( 'Aire introuvable.', 'Erreur', array( 'response' => 404 ) );
        }

        // Candidats de cette aire
        $candidats = $this->db->get_affectations_aire( $event_id, $aire_id );

        // Épreuves avec type_comptage
        $epreuves = $this->db->get_exam_epreuves( null, true );
        // Épreuves avec type_comptage (Filtrage selon le mode d'examen)
        if ( $session->mode === 'par_epreuve' && ! empty( $aire->epreuve_id ) ) {
            $tep = $this->db->table_exam_epreuves();
            $epreuves = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tep WHERE id = %d", intval( $aire->epreuve_id ) ) );
        } else {
            $epreuves = $this->db->get_exam_epreuves( null, true );
        }

        // Seuils ZEMITA et Réactivité
        $zemita_mode      = ! empty( $session->zemita_mode );
        $seuils_zemita    = $zemita_mode ? $this->db->get_zemita_seuils( $event_id, 3 ) : array();
        $seuils_reactivite = $zemita_mode ? $this->db->get_zemita_seuils( $event_id, 4 ) : array();

        // Notes déjà saisies (pour pré-remplir)
        $tn = $this->db->table_jury_notes();
        $notes_existantes = array();
        if ( ! empty( $candidats ) ) {
            $eleve_ids = array_map( fn($c) => intval($c->eleve_id), $candidats );
            $ids_safe  = implode( ',', $eleve_ids );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT eleve_id, epreuve_id, note, coups_val FROM $tn WHERE event_id = %d AND eleve_id IN ($ids_safe)",
                $event_id
            ) );
            foreach ( $rows as $r ) {
                $notes_existantes[ intval($r->eleve_id) ][ intval($r->epreuve_id) ] = array(
                    'note'      => floatval( $r->note ),
                    'coups_val' => $r->coups_val !== null ? intval( $r->coups_val ) : null,
                );
            }
        }

        // Données JS
        $js_data = array(
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'sp_jury_mobile_nonce' ),
            'eventId'         => $event_id,
            'aireId'          => $aire_id,
            'noteMin'         => intval( $aire->note_min ?? $session->note_min ),
            'noteMax'         => intval( $aire->note_max ?? $session->note_max ),
            'zemitaMode'      => $zemita_mode,
            'seuilsGrid'      => array(
                3 => $seuils_zemita,
                4 => $seuils_reactivite,
            ),
            'epreuves'        => array_map( fn($ep) => array(
                'id'            => intval( $ep->id ),
                'nom'           => $ep->nom,
                'type_comptage' => $ep->type_comptage ?? 'note_directe',
            ), $epreuves ),
            'candidats'       => array_map( fn($c) => array(
                'id'            => intval( $c->eleve_id ),
                'nom'           => $c->nom,
                'prenom'        => $c->prenom,
                'grade'         => $c->grade ?? '',
                'categorie_age' => $c->categorie_age ?? '',
                'zemita_groupe' => $c->zemita_groupe ?? '',
                'notes'         => $notes_existantes[ intval($c->eleve_id) ] ?? array(),
            ), $candidats ),
        );

        $this->render_html( $event, $aire, $js_data );
        exit;
    }

    /* ══════════════════════════════════════════════════════════
       GÉNÉRATION TOKEN
    ══════════════════════════════════════════════════════════ */

    public function generate_token( int $event_id, int $aire_id ): string {
        return substr( hash_hmac( 'sha256', 'jury_mobile|' . $event_id . '|' . $aire_id, wp_salt('auth') ), 0, 32 );
    }

    /* ══════════════════════════════════════════════════════════
       RENDU HTML
    ══════════════════════════════════════════════════════════ */

    private function render_locked( string $statut ): void {
        $msg = $statut === 'preparation'
            ? 'L\'examen n\'a pas encore démarré.'
            : 'L\'examen est terminé. La saisie est clôturée.';
        ?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Jury TKD</title>
    <style>
        body { font-family: -apple-system, sans-serif; display: flex; align-items: center;
               justify-content: center; min-height: 100vh; margin: 0; background: #0f172a; }
        .msg { text-align: center; color: #fff; padding: 2rem; }
        .msg h1 { font-size: 1.5rem; margin-bottom: 1rem; }
        .msg p  { color: #94a3b8; }
    </style>
</head>
<body>
    <div class="msg">
        <h1>⚖️ Jury TKD Claira</h1>
        <p><?php echo esc_html( $msg ); ?></p>
    </div>
</body>
</html><?php
        exit;
    }

    private function render_html( $event, $aire, array $js_data ): void {
        $titre_aire  = esc_html( $aire->label ?: 'Aire ' . $aire->numero );
        $titre_event = esc_html( $event->titre ?? 'Examen' );
        $date_event  = $event->date ? date_create( $event->date )->format('d/m/Y') : '';
        $plugin_url  = plugin_dir_url( dirname( __FILE__ ) );
        ?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="theme-color" content="#0f172a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Jury — <?php echo $titre_aire; ?></title>
    <style>
        /* ── Reset & base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg:        #0f172a;
            --bg2:       #1e293b;
            --bg3:       #334155;
            --border:    #475569;
            --text:      #f1f5f9;
            --text2:     #94a3b8;
            --accent:    #3b82f6;
            --accent2:   #1d4ed8;
            --green:     #22c55e;
            --green2:    #15803d;
            --orange:    #f59e0b;
            --red:       #ef4444;
            --radius:    12px;
            --radius-sm: 8px;
        }
        body {
            font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overscroll-behavior: none;
        }

        /* ── Header ── */
        .jm-header {
            background: var(--bg2);
            border-bottom: 1px solid var(--border);
            padding: 14px 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .jm-header-back {
            width: 36px; height: 36px;
            background: var(--bg3);
            border: none; border-radius: 50%;
            color: var(--text); font-size: 18px;
            cursor: pointer; display: flex;
            align-items: center; justify-content: center;
            flex-shrink: 0;
            transition: background .15s;
        }
        .jm-header-back:hover { background: var(--border); }
        .jm-header-info { flex: 1; min-width: 0; }
        .jm-header-title { font-size: 15px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .jm-header-sub   { font-size: 12px; color: var(--text2); margin-top: 1px; }
        .jm-header-badge {
            font-size: 11px; font-weight: 700;
            padding: 4px 10px; border-radius: 20px;
            background: var(--green2); color: #fff;
            flex-shrink: 0;
        }

        /* ── Vue liste candidats ── */
        #view-liste { padding: 16px; }
        .jm-section-title {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .08em; color: var(--text2);
            margin-bottom: 10px; padding: 0 2px;
        }
        .jm-candidat-card {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 14px;
            cursor: pointer;
            transition: border-color .15s, background .15s;
            -webkit-tap-highlight-color: transparent;
        }
        .jm-candidat-card:active { background: var(--bg3); border-color: var(--accent); }
        .jm-candidat-card.done  { border-color: var(--green2); }
        .jm-candidat-card.done .jm-card-status { color: var(--green); }
        .jm-card-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            background: var(--accent2); color: #fff;
            font-size: 15px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .jm-card-avatar.done-avatar { background: var(--green2); }
        .jm-card-info { flex: 1; min-width: 0; }
        .jm-card-name { font-size: 15px; font-weight: 700; }
        .jm-card-meta { font-size: 12px; color: var(--text2); margin-top: 2px; }
        .jm-card-status { font-size: 12px; font-weight: 600; color: var(--text2); flex-shrink: 0; }
        .jm-card-chevron { color: var(--text2); font-size: 18px; flex-shrink: 0; }

        /* ── Vue saisie candidat ── */
        #view-saisie { display: none; padding: 0 0 100px; }
        .jm-candidat-banner {
            background: linear-gradient(135deg, var(--accent2), var(--accent));
            padding: 20px 16px;
            margin-bottom: 16px;
        }
        .jm-banner-name { font-size: 20px; font-weight: 800; }
        .jm-banner-meta { font-size: 13px; opacity: .8; margin-top: 4px; }
        .jm-banner-groupe {
            display: inline-block;
            margin-top: 8px;
            font-size: 11px; font-weight: 700;
            padding: 3px 10px; border-radius: 20px;
            background: rgba(255,255,255,.2); color: #fff;
        }

        /* ── Épreuves ── */
        .jm-epreuves { padding: 0 16px; }
        .jm-ep-block {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 12px;
        }
        .jm-ep-block.ep-coups { border-left: 4px solid var(--orange); }
        .jm-ep-block.ep-note  { border-left: 4px solid var(--accent); }
        .jm-ep-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
        .jm-ep-name   { font-size: 15px; font-weight: 700; flex: 1; }
        .jm-ep-badge  {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            padding: 3px 8px; border-radius: 6px;
        }
        .badge-coups { background: rgba(245,158,11,.2); color: var(--orange); }
        .badge-note  { background: rgba(59,130,246,.2); color: var(--accent); }

        /* Input coups */
        .jm-coups-row { display: flex; align-items: center; gap: 12px; }
        .jm-coups-label { font-size: 13px; color: var(--text2); font-weight: 600; white-space: nowrap; }
        .jm-coups-input {
            width: 90px; padding: 12px;
            background: var(--bg3); border: 2px solid var(--border);
            border-radius: var(--radius-sm); color: var(--text);
            font-size: 22px; font-weight: 900; text-align: center;
            outline: none; transition: border-color .15s;
            -moz-appearance: textfield;
        }
        .jm-coups-input::-webkit-inner-spin-button,
        .jm-coups-input::-webkit-outer-spin-button { -webkit-appearance: none; }
        .jm-coups-input:focus { border-color: var(--orange); }
        .jm-coups-result {
            flex: 1; text-align: center;
            background: var(--bg3); border-radius: var(--radius-sm);
            padding: 10px; font-size: 18px; font-weight: 800;
            color: var(--green);
        }
        .jm-coups-ref { font-size: 11px; color: var(--text2); margin-top: 6px; }

        /* Input note directe */
        .jm-note-row { display: flex; align-items: center; gap: 12px; }
        .jm-note-input {
            width: 90px; padding: 12px;
            background: var(--bg3); border: 2px solid var(--border);
            border-radius: var(--radius-sm); color: var(--text);
            font-size: 22px; font-weight: 900; text-align: center;
            outline: none; transition: border-color .15s;
            -moz-appearance: textfield;
        }
        .jm-note-input::-webkit-inner-spin-button,
        .jm-note-input::-webkit-outer-spin-button { -webkit-appearance: none; }
        .jm-note-input:focus { border-color: var(--accent); }
        .jm-note-max { font-size: 16px; color: var(--text2); font-weight: 600; }

        /* ── Score bar ── */
        .jm-score-bar {
            margin: 16px 16px 0;
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 18px;
            display: flex; align-items: center; gap: 16px;
        }
        .jm-score-label { font-size: 13px; color: var(--text2); flex: 1; }
        .jm-score-val   { font-size: 28px; font-weight: 900; }
        .jm-score-verdict {
            font-size: 12px; font-weight: 700;
            padding: 5px 14px; border-radius: 20px;
        }
        .verdict-wait    { background: var(--bg3); color: var(--text2); }
        .verdict-admis   { background: var(--green2); color: #fff; }
        .verdict-ajourne { background: #7f1d1d; color: #fca5a5; }

        /* ── Bouton valider ── */
        .jm-footer {
            position: fixed; bottom: 0; left: 0; right: 0;
            padding: 16px; background: var(--bg);
            border-top: 1px solid var(--border);
        }
        .jm-btn-valider {
            width: 100%; padding: 16px;
            background: var(--green2); color: #fff;
            border: none; border-radius: var(--radius);
            font-size: 16px; font-weight: 800;
            cursor: pointer; transition: background .15s;
            -webkit-tap-highlight-color: transparent;
        }
        .jm-btn-valider:active   { background: #166534; }
        .jm-btn-valider:disabled { background: var(--bg3); color: var(--text2); cursor: not-allowed; }

        /* ── Toast ── */
        .jm-toast {
            position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%);
            padding: 10px 20px; border-radius: 20px;
            font-size: 14px; font-weight: 700;
            pointer-events: none; opacity: 0;
            transition: opacity .3s;
            white-space: nowrap; z-index: 200;
        }
        .jm-toast.show { opacity: 1; }
        .jm-toast.ok  { background: var(--green2); color: #fff; }
        .jm-toast.err { background: #7f1d1d; color: #fca5a5; }

        /* ── Empty state ── */
        .jm-empty {
            text-align: center; padding: 48px 16px;
            color: var(--text2);
        }
        .jm-empty-icon { font-size: 48px; margin-bottom: 16px; }
    </style>
</head>
<body>

<!-- Header -->
<div class="jm-header" id="jm-header">
    <button class="jm-header-back" id="btn-back" style="display:none;" aria-label="Retour">←</button>
    <div class="jm-header-info">
        <div class="jm-header-title" id="header-title"><?php echo $titre_aire; ?></div>
        <div class="jm-header-sub"><?php echo $titre_event; ?><?php echo $date_event ? ' · ' . $date_event : ''; ?></div>
    </div>
    <div class="jm-header-badge">🟢 En cours</div>
</div>

<!-- Vue 1 : Liste des candidats -->
<div id="view-liste">
    <div style="padding:16px 16px 8px;">
        <div class="jm-section-title" id="liste-count">Chargement…</div>
    </div>
    <div id="liste-candidats" style="padding:0 16px 16px;"></div>
</div>

<!-- Vue 2 : Saisie pour un candidat -->
<div id="view-saisie">
    <div class="jm-candidat-banner" id="saisie-banner"></div>
    <div class="jm-epreuves" id="saisie-epreuves"></div>
    <div class="jm-score-bar" id="saisie-score" style="display:none;">
        <div class="jm-score-label">Moyenne</div>
        <div class="jm-score-val" id="score-val">—</div>
        <div class="jm-score-verdict verdict-wait" id="score-verdict">En attente</div>
    </div>
    <div class="jm-footer">
        <button class="jm-btn-valider" id="btn-valider" disabled>💾 Valider les notes</button>
    </div>
</div>

<!-- Toast -->
<div class="jm-toast" id="toast"></div>

<script>
(function() {
    'use strict';

    /* ── Données injectées par PHP ── */
    const DATA = <?php echo json_encode( $js_data ); ?>;

    /* ── État ── */
    let currentCandidatId = null;
    let notesLocales      = {};  // { epid: note_finale }
    let coupsLocaux       = {};  // { epid: coups_bruts }
    let saisiesTerminees  = {};  // { eleve_id: true }

    /* ── Éléments DOM ── */
    const viewListe   = document.getElementById('view-liste');
    const viewSaisie  = document.getElementById('view-saisie');
    const btnBack     = document.getElementById('btn-back');
    const btnValider  = document.getElementById('btn-valider');
    const headerTitle = document.getElementById('header-title');
    const listeCount  = document.getElementById('liste-count');
    const listeCands  = document.getElementById('liste-candidats');
    const saisieBanner  = document.getElementById('saisie-banner');
    const saisieEpreuves = document.getElementById('saisie-epreuves');
    const saisieScore   = document.getElementById('saisie-score');
    const scoreVal      = document.getElementById('score-val');
    const scoreVerdict  = document.getElementById('score-verdict');
    const toast         = document.getElementById('toast');

    /* ── Init ── */
    function init() {
        // Détecter les candidats déjà notés
        DATA.candidats.forEach(c => {
            const hasNotes = Object.keys(c.notes).length > 0;
            if (hasNotes) saisiesTerminees[c.id] = true;
        });
        renderListe();
        btnBack.addEventListener('click', showListe);
        btnValider.addEventListener('click', validerNotes);
    }

    /* ══════════════════════════════════════════
       VUE LISTE
    ══════════════════════════════════════════ */

    function renderListe() {
        const nb     = DATA.candidats.length;
        const nbDone = Object.keys(saisiesTerminees).length;
        listeCount.textContent = `${nb} candidat${nb > 1 ? 's' : ''} — ${nbDone} noté${nbDone > 1 ? 's' : ''}`;

        if (!nb) {
            listeCands.innerHTML = '<div class="jm-empty"><div class="jm-empty-icon">🙋</div><p>Aucun candidat affecté à cette aire.</p></div>';
            return;
        }

        listeCands.innerHTML = DATA.candidats.map(c => {
            const done     = !!saisiesTerminees[c.id];
            const initials = ((c.prenom||'').charAt(0) + (c.nom||'').charAt(0)).toUpperCase();
            return `
            <div class="jm-candidat-card${done ? ' done' : ''}" data-id="${c.id}">
                <div class="jm-card-avatar${done ? ' done-avatar' : ''}">${done ? '✓' : esc(initials)}</div>
                <div class="jm-card-info">
                    <div class="jm-card-name">${esc(c.prenom)} ${esc(c.nom.toUpperCase())}</div>
                    <div class="jm-card-meta">${esc(c.categorie_age)}${c.grade ? ' · ' + esc(c.grade) : ''}${c.zemita_groupe ? ' · ' + esc(c.zemita_groupe) : ''}</div>
                </div>
                <div class="jm-card-status">${done ? '✅ Noté' : '—'}</div>
                <div class="jm-card-chevron">›</div>
            </div>`;
        }).join('');

        listeCands.querySelectorAll('.jm-candidat-card').forEach(el => {
            el.addEventListener('click', () => showSaisie(parseInt(el.dataset.id)));
        });
    }

    /* ══════════════════════════════════════════
       VUE SAISIE
    ══════════════════════════════════════════ */

    function showSaisie(eleveId) {
        const c = DATA.candidats.find(x => x.id === eleveId);
        if (!c) return;

        currentCandidatId = eleveId;
        notesLocales      = {};
        coupsLocaux       = {};

        // Header
        headerTitle.textContent = `${c.prenom} ${c.nom.toUpperCase()}`;
        btnBack.style.display   = 'flex';

        // Banner
        saisieBanner.innerHTML = `
            <div class="jm-banner-name">${esc(c.prenom)} ${esc(c.nom.toUpperCase())}</div>
            <div class="jm-banner-meta">${esc(c.categorie_age)}${c.grade ? ' · ' + esc(c.grade) : ''}</div>
            ${c.zemita_groupe ? `<span class="jm-banner-groupe">⚖️ ${esc(c.zemita_groupe)}</span>` : ''}
        `;

        // Épreuves
        saisieEpreuves.innerHTML = DATA.epreuves.map(ep => {
            const isCoups = ep.type_comptage === 'coups';
            const existant = c.notes[ep.id] || null;

            // Trouver les seuils pour cette épreuve
            let seuils = null;
            if (isCoups && c.zemita_groupe && DATA.seuilsGrid[ep.id]) {
                const grid = DATA.seuilsGrid[ep.id];
                if (grid[c.zemita_groupe] && grid[c.zemita_groupe][c.categorie_age]) {
                    seuils = grid[c.zemita_groupe][c.categorie_age];
                }
            }

            const prevCoups = existant?.coups_val ?? '';
            const prevNote  = existant?.note ?? '';

            if (isCoups) {
                const noteCalc = prevCoups !== '' && seuils ? convertCoups(prevCoups, seuils) : null;
                const noteDisplay = noteCalc !== null ? noteCalc.toFixed(1) + '/10' : '—';
                const refInfo = seuils
                    ? `Ref: ${seuils.coups_ref} coups = 10/10`
                    : '<span style="color:var(--red)">Seuils non configurés</span>';

                return `
                <div class="jm-ep-block ep-coups" data-epid="${ep.id}">
                    <div class="jm-ep-header">
                        <div class="jm-ep-name">${esc(ep.nom)}</div>
                        <span class="jm-ep-badge badge-coups">Coups</span>
                    </div>
                    <div class="jm-coups-row">
                        <div class="jm-coups-label">Coups :</div>
                        <input type="number" class="jm-coups-input" data-epid="${ep.id}"
                               min="0" step="1" placeholder="0"
                               value="${prevCoups !== '' ? prevCoups : ''}">
                        <div class="jm-coups-result" id="result-${ep.id}">${noteDisplay}</div>
                    </div>
                    <div class="jm-coups-ref">${refInfo}</div>
                </div>`;
            } else {
                return `
                <div class="jm-ep-block ep-note" data-epid="${ep.id}">
                    <div class="jm-ep-header">
                        <div class="jm-ep-name">${esc(ep.nom)}</div>
                        <span class="jm-ep-badge badge-note">/${DATA.noteMax}</span>
                    </div>
                    <div class="jm-note-row">
                        <input type="number" class="jm-note-input" data-epid="${ep.id}"
                               min="${DATA.noteMin}" max="${DATA.noteMax}" step="0.5"
                               placeholder="0" value="${prevNote !== '' ? prevNote : ''}">
                        <div class="jm-note-max">/ ${DATA.noteMax}</div>
                    </div>
                </div>`;
            }
        }).join('');

        // Pré-remplir notesLocales avec les valeurs existantes
        DATA.epreuves.forEach(ep => {
            const existant = c.notes[ep.id] || null;
            if (existant) {
                if (ep.type_comptage === 'coups' && existant.coups_val !== null) {
                    coupsLocaux[ep.id] = existant.coups_val;
                    notesLocales[ep.id] = existant.note;
                } else if (ep.type_comptage !== 'coups' && existant.note !== null) {
                    notesLocales[ep.id] = existant.note;
                }
            }
        });

        // Attacher les événements
        saisieEpreuves.querySelectorAll('.jm-coups-input').forEach(inp => {
            inp.addEventListener('input', onCoupsInput);
        });
        saisieEpreuves.querySelectorAll('.jm-note-input').forEach(inp => {
            inp.addEventListener('input', onNoteInput);
        });

        updateScore();
        updateBtnValider();

        // Afficher la vue saisie
        viewListe.style.display  = 'none';
        viewSaisie.style.display = 'block';
        window.scrollTo(0, 0);
    }

    function showListe() {
        currentCandidatId = null;
        headerTitle.textContent  = '<?php echo esc_js($titre_aire); ?>';
        btnBack.style.display    = 'none';
        viewSaisie.style.display = 'none';
        viewListe.style.display  = 'block';
        renderListe();
        window.scrollTo(0, 0);
    }

    /* ── Handlers input ── */

    function onCoupsInput(e) {
        const epid  = parseInt(e.target.dataset.epid);
        const coups = parseFloat(e.target.value) || 0;
        const c     = DATA.candidats.find(x => x.id === currentCandidatId);
        const ep    = DATA.epreuves.find(x => x.id === epid);

        let seuils = null;
        if (c && DATA.seuilsGrid[epid]) {
            const grid = DATA.seuilsGrid[epid];
            if (grid[c.zemita_groupe] && grid[c.zemita_groupe][c.categorie_age]) {
                seuils = grid[c.zemita_groupe][c.categorie_age];
            }
        }

        const note = seuils ? convertCoups(coups, seuils) : 0;
        notesLocales[epid] = note;
        coupsLocaux[epid]  = coups;

        const resultEl = document.getElementById('result-' + epid);
        if (resultEl) resultEl.textContent = note.toFixed(1) + '/10';

        updateScore();
        updateBtnValider();
    }

    function onNoteInput(e) {
        const epid = parseInt(e.target.dataset.epid);
        let val    = parseFloat(e.target.value) || 0;
        val = Math.max(DATA.noteMin, Math.min(DATA.noteMax, val));
        notesLocales[epid] = val;
        updateScore();
        updateBtnValider();
    }

    /* ── Score ── */

    function updateScore() {
        const vals = Object.values(notesLocales);
        if (!vals.length) {
            saisieScore.style.display = 'none';
            return;
        }
        saisieScore.style.display = 'flex';
        const moy = vals.reduce((a, b) => a + b, 0) / vals.length;
        scoreVal.textContent = moy.toFixed(2) + '/10';

        const seuil = DATA.noteMin; // seuil admission depuis session
        if (moy >= 5) {
            scoreVerdict.textContent = '✅ Admis';
            scoreVerdict.className   = 'jm-score-verdict verdict-admis';
        } else {
            scoreVerdict.textContent = '❌ Ajourné';
            scoreVerdict.className   = 'jm-score-verdict verdict-ajourne';
        }
    }

    function updateBtnValider() {
        const hasNotes = Object.keys(notesLocales).length > 0;
        btnValider.disabled = !hasNotes;
    }

    /* ── Valider ── */

    function validerNotes() {
        if (!currentCandidatId || !Object.keys(notesLocales).length) return;

        btnValider.disabled    = true;
        btnValider.textContent = 'Envoi…';

        const payload = new FormData();
        payload.append('action',   'sp_jury_mobile_save_notes');
        payload.append('nonce',    DATA.nonce);
        payload.append('event_id', DATA.eventId);
        payload.append('aire_id',  DATA.aireId);
        payload.append('eleve_id', currentCandidatId);
        payload.append('notes',    JSON.stringify(
            Object.keys(notesLocales).map(epid => ({
                epreuve_id : parseInt(epid),
                note_val   : notesLocales[epid],
                coups_val  : coupsLocaux[epid] !== undefined ? coupsLocaux[epid] : null,
            }))
        ));

        fetch(DATA.ajaxUrl, { method: 'POST', body: payload })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    saisiesTerminees[currentCandidatId] = true;
                    // Mettre à jour les notes locales dans DATA
                    const c = DATA.candidats.find(x => x.id === currentCandidatId);
                    if (c) {
                        Object.keys(notesLocales).forEach(epid => {
                            c.notes[epid] = {
                                note      : notesLocales[epid],
                                coups_val : coupsLocaux[epid] ?? null,
                            };
                        });
                    }
                    showToast('✓ Notes enregistrées', 'ok');
                    setTimeout(showListe, 800);
                } else {
                    showToast('✗ Erreur : ' + (res.data || 'inconnue'), 'err');
                    btnValider.disabled    = false;
                    btnValider.textContent = '💾 Valider les notes';
                }
            })
            .catch(() => {
                showToast('✗ Erreur réseau', 'err');
                btnValider.disabled    = false;
                btnValider.textContent = '💾 Valider les notes';
            });
    }

    /* ── Conversion coups → note ── */

    function convertCoups(coups, s) {
        const ref      = parseFloat(s.coups_ref    || 0);
        const plancher = parseFloat(s.note_plancher || 3);
        if (ref <= 0)    return plancher;
        if (coups <= 0)  return 0;
        return Math.round(Math.min(10, (coups / ref) * 10) * 100) / 100;
    }

    /* ── Toast ── */

    function showToast(msg, type) {
        toast.textContent = msg;
        toast.className   = `jm-toast ${type} show`;
        setTimeout(() => { toast.className = 'jm-toast'; }, 2500);
    }

    /* ── Escape HTML ── */

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    /* ── Lancement ── */
    init();

}());
</script>
</body>
</html><?php
    }
}

endif; // class_exists SpCalPro_JuryMobile

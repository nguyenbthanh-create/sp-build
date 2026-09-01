<?php
/**
 * SP_Cal — Module Jury d'examen
 * Fichier : class-admin-jury.php
 *
 * Contient : page_jury, render_jury_saisie_mobile,
 *            ajax_jury_save_notes_mobile, page_jury_guide
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SP_Cal_Jury' ) ) :

class SP_Cal_Jury {

    private $db;

public function __construct( $db ) {
    $this->db = $db;
    add_action( 'wp_ajax_sp_jury_save_notes_mobile', array( $this, 'ajax_jury_save_notes_mobile' ) );
}

public function enqueue_scripts( $hook ) {
    if ( strpos( $hook, 'sp-cal-jury' ) === false ) return;
    $event_id = intval( $_GET['event_id'] ?? 0 );
    wp_enqueue_script(
    'sp-jury-admin-v2',
    SP_CAL_PRO_URL . 'assets/js/jury-admin.js',
    array( 'jquery' ),
    SP_CAL_PRO_VERSION . '-' . time(),
    true
);
	error_log('Script enregistré: ' . (wp_script_is('sp-jury-admin-v2', 'enqueued') ? 'OUI' : 'NON'));
    $session  = $this->db->get_jury_session( $event_id );
    $aires    = $this->db->get_jury_tables( $event_id );
    $epreuves = $this->db->get_exam_epreuves( null, true );

    $trainers_raw = $this->db->get_trainers( true );
    $trainers = array_map( function( $tr ) {
        return array(
            'id'  => intval( $tr->id ),
            'nom' => $tr->nom_public ?: $tr->nom,
        );
    }, $trainers_raw );

    $aires_data = array();
    foreach ( $aires as $a ) {
        $aires_data[] = array(
            'id'              => intval( $a->id ),
            'label'           => $a->label ?: 'Aire ' . $a->numero,
            'type_verdict'    => $a->type_verdict    ?? 'seuil_fixe',
            'note_min'        => intval( $a->note_min  ?? 0 ),
            'note_max'        => intval( $a->note_max  ?? 10 ),
            'seuil_admission' => floatval( $a->seuil_admission ?? 0 ),
        );
    }

    $epreuves_type = array();
    $epreuves_js   = array();
    foreach ( $epreuves as $ep ) {
        $epreuves_type[ intval( $ep->id ) ] = $ep->type_comptage ?? 'note_directe';
        $epreuves_js[] = array( 'id' => intval( $ep->id ), 'nom' => $ep->nom );
    }

    wp_localize_script( 'sp-jury-admin-v2', 'spJuryAdmin', array(
        'ajaxurl'         => admin_url( 'admin-ajax.php' ),
        'nonce'           => wp_create_nonce( 'sp_cal_admin_nonce' ),
        'eid'             => $event_id,
        'statut'          => $session->statut          ?? 'preparation',
        'modeSession'     => $session->mode             ?? 'par_categorie',
        'supportNotation' => $session->support_notation ?? 'numerique',
        'noteMin'         => intval( $session->note_min ?? 0 ),
        'noteMax'         => intval( $session->note_max ?? 10 ),
        'seuil'           => floatval( $session->seuil_admission ?? 0 ),
        'epreuves'        => $epreuves_js,
        'epreuvesType'    => $epreuves_type,
        'airesData'       => $aires_data,
        'seuilsGrid'      => array(
            3 => $this->db->get_zemita_seuils( $event_id, 3 ),
            4 => $this->db->get_zemita_seuils( $event_id, 4 ),
        ),
        'trainers'        => $trainers,
        'aireCount'       => count( $aires ),
    ) );
}

    /* ══════════════════════════════════════════════════════════
       PAGE JURY
    ══════════════════════════════════════════════════════════ */

    public function page_jury() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );

        // ── Chantier 5 : formulaire mobile saisie jury via QR ──────────────
        if ( ! empty( $_GET['jury_saisie'] ) && $_GET['jury_saisie'] === '1' ) {
            $this->render_jury_saisie_mobile();
            return;
        }
        // ───────────────────────────────────────────────────────────────────

        global $wpdb;

        $te       = $this->db->table_events();
        $events   = $wpdb->get_results( "SELECT id,titre,date FROM $te WHERE type='examen' ORDER BY date DESC LIMIT 60" );
        $trainers = $wpdb->get_results( "SELECT id,nom,nom_public FROM {$this->db->table_trainers()} WHERE actif=1 ORDER BY nom ASC" );
        foreach ($trainers as $tr) $tr->nom = $tr->nom_public ?: $tr->nom;

        $event_id = intval($_GET['event_id'] ?? 0);
        $step     = intval($_GET['step']     ?? 1);
        $session  = $event_id ? $this->db->get_jury_session($event_id)        : null;
        $aires    = $event_id ? $this->db->get_jury_tables($event_id)          : array();
        $juges    = $event_id ? $this->db->get_jury_juges($event_id)           : array();
        $epreuves = $this->db->get_exam_epreuves(null, false);
        $grad_prog= $this->db->get_grade_progression_all();
        $nonce    = wp_create_nonce('sp_cal_admin_nonce');
        $statut   = $session->statut ?? 'preparation';

        // URL base
        $base_url = admin_url('admin.php?page=sp-cal-jury&event_id='.$event_id);

        // Étapes
        $steps_def = array(
            1 => array('icon'=>'⚙️', 'label'=>'Paramètres',   'desc'=>'Mode, notation, aires'),
            2 => array('icon'=>'👤', 'label'=>'Juges',         'desc'=>'Affecter les juges aux aires'),
            3 => array('icon'=>'🙋', 'label'=>'Candidats',     'desc'=>'Assigner les élèves aux aires'),
            4 => array('icon'=>'🚀', 'label'=>'Lancer',         'desc'=>'Démarrer l\'examen'),
            5 => array('icon'=>'📊', 'label'=>'Suivi live',    'desc'=>'Centrale en temps réel'),
            6 => array('icon'=>'📝', 'label'=>'Transcription', 'desc'=>'Écrire les grades en fiche'),
            7 => array('icon'=>'🎯', 'label'=>'Grades',        'desc'=>'Progression des grades'),
        );
        if (!$event_id) $step = 0;

        ?>
<div class="wrap" id="sp-jury-admin">
	<style>
        #sp-jury-admin{max-width:1000px;}
        .sja-top{display:flex;align-items:center;gap:16px;margin-bottom:20px;flex-wrap:wrap;}
        .sja-top h1{margin:0;font-size:22px;flex:1;}
        .sja-statut{font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;text-transform:uppercase;}
        .sja-statut-preparation{background:#fef9c3;color:#854d0e;}
        .sja-statut-en_cours   {background:#dcfce7;color:#14532d;}
        .sja-statut-termine    {background:#fee2e2;color:#7f1d1d;}
        .sja-layout{display:grid;grid-template-columns:200px 1fr;gap:0;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;}
        /* Stepper */
        .sja-stepper{border-right:1px solid #e2e8f0;padding:12px 0;background:#f8fafc;}
        .sja-step-item{display:flex;align-items:center;gap:10px;padding:10px 16px;cursor:pointer;border-left:3px solid transparent;transition:all .15s;text-decoration:none;color:inherit;}
        .sja-step-item:hover{background:#eff6ff;}
        .sja-step-item.active{border-left-color:#2563eb;background:#eff6ff;}
        .sja-step-item.locked{opacity:.4;cursor:not-allowed;pointer-events:none;}
        .sja-step-icon{font-size:18px;flex-shrink:0;}
        .sja-step-text .sl{font-size:13px;font-weight:700;display:block;}
        .sja-step-text .sd{font-size:11px;color:#64748b;}
        .sja-step-done{margin-left:auto;color:#16a34a;font-size:14px;}
        /* Content */
        .sja-content{padding:24px;}
        .sja-content h2{font-size:17px;font-weight:800;margin:0 0 18px;}
        /* Form elements */
        .sja-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:16px;}
        .sja-field label{font-weight:600;font-size:12px;display:block;margin-bottom:4px;color:#374151;}
        .sja-field select,.sja-field input[type=number],.sja-field input[type=text]{
            border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:14px;width:100%;
        }
        /* Boutons */
        .sja-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;border:none;cursor:pointer;font-size:13px;font-weight:700;}
        .sja-btn-primary{background:#2563eb;color:#fff;}
        .sja-btn-green  {background:#16a34a;color:#fff;}
		.sja-btn-red:hover{background:#2563eb;color:#fff;text-decoration:none;}
        .sja-btn-red    {background:#dc2626;color:#fff;text-decoration:none}
        .sja-btn-gray   {background:#6b7280;color:#fff;}
        .sja-btn-sm     {padding:4px 10px;font-size:12px;}
        .sja-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;}
        /* Aire rows */
        .sja-aire-row{display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;}
        /* Juges */
        .sja-juge-row{display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;
            background:#f9fafb;padding:8px 12px;border-radius:8px;border:1px solid #e5e7eb;}
        /* Candidats panel */
        .sja-cand-panel{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        .sja-cand-col{border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
        .sja-cand-col-hd{padding:10px 14px;background:#f8fafc;font-weight:700;font-size:13px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;}
        .sja-cand-list{max-height:380px;overflow-y:auto;}
        .sja-cand-item{display:flex;align-items:center;gap:8px;padding:8px 14px;border-bottom:1px solid #f1f5f9;font-size:13px;cursor:pointer;}
        .sja-cand-item:hover{background:#eff6ff;}
        .sja-cand-item input[type=checkbox]{cursor:pointer;}
        .sja-cand-tag{font-size:10px;padding:1px 6px;border-radius:8px;background:#e0f2fe;color:#0369a1;font-weight:600;}
        /* Centrale */
        .sja-centrale-row{display:flex;align-items:center;gap:10px;padding:8px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:7px;margin-bottom:6px;font-size:13px;}
        .sja-centrale-row.admis  {background:#f0fdf4;border-color:#86efac;}
        .sja-centrale-row.ajourne{background:#fef2f2;border-color:#fca5a5;}
        /* QR code popup */
        .sja-qr-wrap{text-align:center;padding:16px;}
        .sja-qr-wrap img{border:4px solid #fff;box-shadow:0 2px 12px rgba(0,0,0,.15);border-radius:8px;}
        .sja-qr-url{font-size:11px;word-break:break-all;color:#64748b;margin-top:8px;font-family:monospace;}
        /* Lancer */
        .sja-launch-box{background:linear-gradient(135deg,#1e3a5f,#2563eb);color:#fff;border-radius:12px;padding:32px;text-align:center;margin-bottom:16px;}
        .sja-launch-box h3{font-size:20px;margin:0 0 8px;}
        .sja-launch-box p{opacity:.8;font-size:14px;margin:0 0 24px;}
        .sja-launch-big{font-size:18px;padding:14px 36px;border-radius:10px;border:none;cursor:pointer;font-weight:800;}
        .sja-recap{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;}
        .sja-recap-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;text-align:center;}
        .sja-recap-card .n{font-size:28px;font-weight:900;color:#2563eb;}
        .sja-recap-card .l{font-size:12px;color:#64748b;margin-top:2px;}
        /* Msg */
        .sja-msg{font-size:13px;margin-left:10px;}
        .sja-msg-ok{color:#16a34a;}
        .sja-msg-err{color:#dc2626;}
        /* Prog grades */
        .sja-prog-tbl{width:auto;border-collapse:collapse;font-size:13px;}
        .sja-prog-tbl th{background:#f1f5f9;padding:7px 14px;border:1px solid #e5e7eb;font-size:12px;text-align:left;}
        .sja-prog-tbl td{border:1px solid #e5e7eb;padding:6px 10px;}
        </style>
	<div class="sja-top">
		<h1>⚖️ Jury d'examen</h1>
            <?php if ($session): ?>
		<span class="sja-statut sja-statut-<?php echo $statut; ?>">
                <?php echo $statut==='en_cours'?'🟢 En cours':($statut==='termine'?'🔴 Terminé':'🟡 Préparation'); ?>
		</span>
            <?php endif; ?>
	</div>
	<!-- Sélecteur événement -->
	<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
		<label style="font-weight:700;font-size:13px;">📅 Examen :</label>
		<select id="sja-event-sel" style="border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;font-size:13px;min-width:260px;">
			<option value="">— Choisir un examen —</option>
                <?php foreach ($events as $ev): ?>
			<option value="<?php echo intval($ev->id); ?>"<?php selected($event_id,$ev->id); ?>>
                    <?php echo esc_html($ev->titre.' — '.date_create($ev->date)->format('d/m/Y')); ?>
		</option>
                <?php endforeach; ?>
	</select>
	<button class="sja-btn sja-btn-primary" onclick="location.href='?page=sp-cal-jury&event_id='+document.getElementById('sja-event-sel').value+'&step=1'">
                Ouvrir
            </button>
</div>

        <?php if (!$event_id): ?>
<p style="color:#64748b;font-size:14px;padding:8px;">Sélectionnez un examen pour gérer le jury.</p>
        <?php else: ?>
<div class="sja-layout">
	<!-- Stepper vertical -->
	<div class="sja-stepper">
                <?php foreach ($steps_def as $n => $s):
                    $active  = ($step===$n)?'active':'';
                    $locked  = (!$event_id&&$n>1)?'locked':'';
                    $done    = '';
                    if ($n===1 && $session) $done='✓';
                    if ($n===2 && !empty($juges)) $done='✓';
                    if ($n===5 && $session && $session->statut==='en_cours') $done='●';
            if ($n===6 && $session && $session->statut==='termine') $done='→';
                ?>
		<a class="sja-step-item <?php echo $active.' '.$locked; ?>" href="<?php echo $locked?'#':esc_url($base_url.'&step='.$n); ?>">
			<span class="sja-step-icon"><?php echo $s['icon']; ?>
			</span>
			<span class="sja-step-text">
				<span class="sl"><?php echo esc_html($s['label']); ?>
				</span>
				<span class="sd"><?php echo esc_html($s['desc']); ?>
				</span>
			</span>
                    <?php if ($done): ?>
			<span class="sja-step-done"><?php echo $done; ?>
			</span><?php endif; ?>
		</a>
                <?php endforeach; ?>
	</div>
	<!-- Contenu par étape -->
	<div class="sja-content">

            <?php if ($step===1): /* ── ÉTAPE 1 : Paramètres ── */ ?>
		<h2>⚙️ Paramètres de la session</h2>
		<div class="sja-grid">
			<div class="sja-field">
				<label>Mode de notation</label>
				<select id="sja-mode">
					<option value="par_categorie"<?php selected($session->mode??'','par_categorie'); ?>>Par catégorie (groupes)</option>
				<option value="par_epreuve"<?php selected($session->mode??'','par_epreuve'); ?>>Par épreuve (rotation)</option>
		</select>
	</div>
	<div class="sja-field">
		<label>📋 Support de notation</label>
		<select id="sja-support-notation">
			<option value="numerique"<?php selected($session->support_notation??'numerique','numerique'); ?>>📱 Numérique (QR code juge)</option>
		<option value="papier"<?php selected($session->support_notation??'numerique','papier'); ?>>🖊️ Papier (fiches A5 + saisie admin)</option>
</select>
</div>
<div class="sja-field">
	<label>Note minimum</label>
	<input type="number" id="sja-note-min" min="0" max="99" value="<?php echo intval($session->note_min??0); ?>">
                </div>
	<div class="sja-field">
		<label>Note maximum</label>
		<input type="number" id="sja-note-max" min="1" max="100" value="<?php echo intval($session->note_max??10); ?>">
                </div>
		<div class="sja-field">
			<label>Seuil admission (score absolu)</label>
			<input type="number" id="sja-seuil" min="0" step="0.5" value="<?php echo floatval($session->seuil_admission??0); ?>">
                </div>
		</div>
		<h3 style="font-size:14px;font-weight:700;margin:16px 0 10px;">🎯 Aires de jury</h3>
		<div id="sja-aires-wrap">
            <?php foreach ($aires as $i=>$a): ?>
			<div class="sja-aire-row" style="flex-wrap:wrap;gap:8px;padding:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;">
				<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;width:100%;">
					<input type="text" class="sja-aire-label" placeholder="Libellé (ex: Aire A)" value="<?php echo esc_attr($a->label); ?>" style="width:160px;border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;">
						<select class="sja-aire-epreuve" style="border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;">
							<option value="">— Épreuve (mode par épreuve) —</option>
            <?php foreach ($epreuves as $ep): ?>
							<option value="<?php echo $ep->id; ?>"<?php selected($a->epreuve_id,$ep->id); ?>><?php echo esc_html($ep->nom); ?>
						</option>
            <?php endforeach; ?>
					</select>
					<span style="font-size:12px;color:#9ca3af;">Aire <?php echo $i+1; ?>
					</span>
					<button class="sja-btn sja-btn-red sja-btn-sm sja-del-aire" onclick="jQuery(this).closest('.sja-aire-row').remove()">✕</button>
				</div>
				<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px;">
					<label style="font-size:12px;color:#374151;font-weight:600;">Note min</label>
					<input type="number" class="sja-aire-note-min" min="0" max="999" value="<?php echo intval($a->note_min ?? 0); ?>" style="width:65px;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:13px;">
						<label style="font-size:12px;color:#374151;font-weight:600;">Note max</label>
						<input type="number" class="sja-aire-note-max" min="1" max="999" value="<?php echo intval($a->note_max ?? 10); ?>" style="width:65px;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:13px;">
							<label style="font-size:12px;color:#374151;font-weight:600;">Type verdict</label>
							<select class="sja-aire-type-verdict" style="border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:13px;">
								<option value="seuil_fixe"<?php selected($a->type_verdict ?? 'seuil_fixe','seuil_fixe'); ?>>Seuil fixe</option>
							<option value="zemita"<?php selected($a->type_verdict ?? 'seuil_fixe','zemita'); ?>>ZEMITA</option>
					</select>
					<label style="font-size:12px;color:#374151;font-weight:600;" class="sja-aire-seuil-label">Seuil admission</label>
					<input type="number" class="sja-aire-seuil" min="0" step="0.5" value="<?php echo floatval($a->seuil_admission ?? 0); ?>" style="width:75px;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:13px;">
    </div>
				</div>
            <?php endforeach; ?>
			</div>
			<button class="sja-btn sja-btn-gray sja-btn-sm" id="sja-add-aire" style="margin-bottom:16px;">+ Ajouter une aire</button>
			<br>
				<button class="sja-btn sja-btn-primary" id="sja-save-params">💾 Sauvegarder et continuer →</button>
				<span class="sja-msg" id="sja-params-msg"/>
				
				<!-- ══ SECTION ZEMITA ══════════════════════════════════════════════ -->
            <?php
            $zemita_mode    = ! empty( $session->zemita_mode );
            $zemita_seuils      = $this->db->get_zemita_seuils( $event_id, 3 );
			$reactivite_seuils  = $this->db->get_zemita_seuils( $event_id, 4 );
            $zemita_mapping = $this->db->get_zemita_mapping();
            $zemita_groupes = array( 'Débutant', 'Intermédiaire', 'Confirmé' );
            $zemita_cats    = array( 'Baby', 'Enfant', 'Ado/adulte' );
            ?>
				<div style="margin-top:28px;border-top:2px solid #e2e8f0;padding-top:20px;">
					<div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;flex-wrap:wrap;">
						<h3 style="margin:0;font-size:15px;font-weight:700;">⚖️ Barème ZEMITA</h3>
						<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;
                                  background:<?php echo $zemita_mode?'#f0fdf4':'#f8fafc'; ?>;
                                  border:1px solid <?php echo $zemita_mode?'#86efac':'#e2e8f0'; ?>;
                                  padding:5px 12px;border-radius:20px;">
							<input type="checkbox" id="sja-zemita-toggle"<?php checked($zemita_mode); ?>>
                        Activer le mode ZEMITA pour cet examen
                    </label>
						<span class="sja-msg" id="sja-zemita-msg"/>
					</div>
					<div id="sja-zemita-config" style="display:<?php echo $zemita_mode?'block':'none'; ?>">
						<p style="font-size:12px;color:#64748b;margin-bottom:14px;">
        Définissez les seuils pour chaque combinaison groupe × catégorie d'âge.
    </p>
						<!-- Grille coups_ref — commune ZEMITA (ep3) et Réactivité (ep4) -->
						<div style="overflow-x:auto;margin-bottom:20px;">
							<table style="border-collapse:collapse;font-size:12px;min-width:500px;">
								<thead>
									<tr style="background:#f1f5f9;">
										<th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:left;width:110px;">Catégorie</th>
                <?php foreach ($zemita_groupes as $g): ?>
										<th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:center;min-width:160px;">
                    <?php echo esc_html($g); ?>
											<div style="font-size:10px;font-weight:400;color:#94a3b8;">Coups ref (=10/10) / Note plancher</div>
										</th>
                <?php endforeach; ?>
									</tr>
								</thead>
								<tbody>
        <?php foreach ($zemita_cats as $cat): ?>
									<tr>
										<td style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:700;color:#374151;background:#f8fafc;">
                <?php echo esc_html($cat); ?>
										</td>
            <?php foreach ($zemita_groupes as $g):
                $v_ref      = $zemita_seuils[$g][$cat]['coups_ref']     ?? '';
                $v_plancher = $zemita_seuils[$g][$cat]['note_plancher'] ?? '';
            ?>
										<td style="padding:6px 8px;border:1px solid #e2e8f0;">
											<div style="display:flex;flex-direction:column;gap:4px;">
												<div style="display:flex;gap:4px;align-items:center;">
													<span style="font-size:10px;color:#94a3b8;width:80px;">🎯 Ref coups</span>
													<input type="number" min="0" step="1" class="sja-zemita-cell" data-groupe="<?php echo esc_attr($g); ?>" data-cat="<?php echo esc_attr($cat); ?>" data-type="coups_ref" value="<?php echo esc_attr($v_ref); ?>" placeholder="ex: 30" style="width:65px;border:1px solid #d1d5db;border-radius:4px;padding:3px 5px;font-size:12px;">
                    </div>
													<div style="display:flex;gap:4px;align-items:center;">
														<span style="font-size:10px;color:#94a3b8;width:80px;">📉 Plancher</span>
														<input type="number" min="0" max="10" step="0.5" class="sja-zemita-cell" data-groupe="<?php echo esc_attr($g); ?>" data-cat="<?php echo esc_attr($cat); ?>" data-type="note_plancher" value="<?php echo esc_attr($v_plancher); ?>" placeholder="ex: 3" style="width:65px;border:1px solid #d1d5db;border-radius:4px;padding:3px 5px;font-size:12px;">
															<span style="font-size:10px;color:#94a3b8;">/10</span>
														</div>
													</div>
												</td>
            <?php endforeach; ?>
											</tr>
        <?php endforeach; ?>
										</tbody>
									</table>
								</div>
								<button class="sja-btn sja-btn-primary sja-btn-sm" id="sja-save-zemita-seuils">
    💾 Sauvegarder le barème ZEMITA
</button>
								<!-- Grille Réactivité -->
								<div style="margin-top:24px;border-top:1px solid #e2e8f0;padding-top:16px;">
									<h4 style="font-size:13px;font-weight:700;margin-bottom:10px;">⚡ Barème Réactivité</h4>
									<div style="overflow-x:auto;margin-bottom:12px;">
										<table style="border-collapse:collapse;font-size:12px;min-width:500px;">
											<thead>
												<tr style="background:#f1f5f9;">
													<th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:left;width:110px;">Catégorie</th>
                    <?php foreach ($zemita_groupes as $g): ?>
													<th style="padding:8px 12px;border:1px solid #e2e8f0;text-align:center;min-width:160px;">
                        <?php echo esc_html($g); ?>
														<div style="font-size:10px;font-weight:400;color:#94a3b8;">Coups ref (=10/10) / Note plancher</div>
													</th>
                    <?php endforeach; ?>
												</tr>
											</thead>
											<tbody>
            <?php foreach ($zemita_cats as $cat): ?>
												<tr>
													<td style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:700;color:#374151;background:#f8fafc;">
                    <?php echo esc_html($cat); ?>
													</td>
                <?php foreach ($zemita_groupes as $g):
                    $r_ref      = $reactivite_seuils[$g][$cat]['coups_ref']     ?? '';
                    $r_plancher = $reactivite_seuils[$g][$cat]['note_plancher'] ?? '';
                ?>
													<td style="padding:6px 8px;border:1px solid #e2e8f0;">
														<div style="display:flex;flex-direction:column;gap:4px;">
															<div style="display:flex;gap:4px;align-items:center;">
																<span style="font-size:10px;color:#94a3b8;width:80px;">🎯 Ref coups</span>
																<input type="number" min="0" step="1" class="sja-reactivite-cell" data-groupe="<?php echo esc_attr($g); ?>" data-cat="<?php echo esc_attr($cat); ?>" data-type="coups_ref" value="<?php echo esc_attr($r_ref); ?>" placeholder="ex: 20" style="width:65px;border:1px solid #d1d5db;border-radius:4px;padding:3px 5px;font-size:12px;">
                        </div>
																<div style="display:flex;gap:4px;align-items:center;">
																	<span style="font-size:10px;color:#94a3b8;width:80px;">📉 Plancher</span>
																	<input type="number" min="0" max="10" step="0.5" class="sja-reactivite-cell" data-groupe="<?php echo esc_attr($g); ?>" data-cat="<?php echo esc_attr($cat); ?>" data-type="note_plancher" value="<?php echo esc_attr($r_plancher); ?>" placeholder="ex: 3" style="width:65px;border:1px solid #d1d5db;border-radius:4px;padding:3px 5px;font-size:12px;">
																		<span style="font-size:10px;color:#94a3b8;">/10</span>
																	</div>
																</div>
															</td>
                <?php endforeach; ?>
														</tr>
            <?php endforeach; ?>
													</tbody>
												</table>
											</div>
											<button class="sja-btn sja-btn-primary sja-btn-sm" id="sja-save-reactivite-seuils">
        💾 Sauvegarder le barème Réactivité
    </button>
											<span class="sja-msg" id="sja-reactivite-msg"/>
										</div>
										<!-- Mapping grade → groupe -->
										<div style="margin-top:24px;border-top:1px solid #e2e8f0;padding-top:16px;">
											<h4 style="font-size:13px;font-weight:700;margin-bottom:10px;">
                            🗂 Mapping grade → groupe
                            <span style="font-size:11px;font-weight:400;color:#64748b;margin-left:8px;">
                                (global — utilisé pour pré-remplir le groupe de chaque candidat)
                            </span>
											</h4>
											<div id="sja-zemita-mapping-wrap">
<?php
// Afficher les grades connus + mapping actuel
$all_grades = $this->db->get_exam_epreuves();
global $wpdb;
$grades_by_cat = $wpdb->get_results(
    'SELECT grade, categorie_age'
    . ' FROM ' . $this->db->table_eleves()
    . ' WHERE grade != \'\' AND actif = 1'
    . ' GROUP BY grade, categorie_age'
    . ' ORDER BY categorie_age ASC, grade ASC',
    OBJECT
);
$grades_grouped = array();
foreach ($grades_by_cat as $row) {
    $cat = $row->categorie_age ?: 'Sans catégorie';
    $grades_grouped[$cat][$row->grade] = true;
}
?>
<?php foreach ($grades_grouped as $cat => $grades): ?>
<div style="margin-bottom:14px;">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:6px 12px;margin-bottom:6px;">
        <?php echo esc_html($cat); ?>
    </div>
    <?php foreach (array_keys($grades) as $grade): ?>
    <div class="sja-zemita-map-row" style="display:flex;align-items:center;gap:10px;margin-bottom:6px;padding-left:12px;">
        <span style="flex:1;font-size:13px;font-weight:600;"><?php echo esc_html($grade); ?></span>
        <select class="sja-zemita-map-select" data-grade="<?php echo esc_attr($grade); ?>" style="border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:12px;max-width:150px;">
            <option value="">— Non mappé —</option>
            <?php foreach ($zemita_groupes as $g): ?>
            <option value="<?php echo esc_attr($g); ?>"<?php selected($zemita_mapping[$grade]??'',$g); ?>>
                <?php echo esc_html($g); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
                        <?php if (empty($grades_eleves)): ?>
											<p style="font-size:12px;color:#94a3b8;">Aucun grade trouvé dans la base élèves.</p>
                        <?php endif; ?>
										</div>
										<button class="sja-btn sja-btn-primary sja-btn-sm" id="sja-save-zemita-mapping" style="margin-top:10px;">
                            💾 Sauvegarder le mapping
                        </button>
										<span class="sja-msg" id="sja-mapping-msg"/>
									</div>
								</div>
							</div>
			<script>
(function($){
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
        var $btn = $(this).prop('disabled', true).text('…');
        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action:           'sp_jury_save_session',
            nonce:            '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',
            event_id:         <?php echo intval($event_id); ?>,
            mode:             $('#sja-mode').val(),
            support_notation: $('#sja-support-notation').val(),
            note_min:         $('#sja-note-min').val(),
            note_max:         $('#sja-note-max').val(),
            seuil_admission:  $('#sja-seuil').val(),
            zemita_mode:      $('#sja-zemita-toggle').is(':checked') ? 1 : 0,
            statut:           '<?php echo esc_js($statut); ?>',
            aires:            aires,
        }, function (r) {
            if (r.success) {
                $('#sja-params-msg').text('✓ Sauvegardé').addClass('sja-msg-ok');
                setTimeout(function () {
                    location.href = '?page=sp-cal-jury&event_id=<?php echo intval($event_id); ?>&step=2';
                }, 600);
            } else {
                $btn.prop('disabled', false).text('💾 Sauvegarder et continuer →');
                $('#sja-params-msg').text('✗ Erreur').addClass('sja-msg-err');
            }
        });
    });

    // ZEMITA toggle
    $('#sja-zemita-toggle').on('change', function () {
        $('#sja-zemita-config').toggle(this.checked);
    });

    // Mode UI
    function sjaModeUI() {
        var mode = $('#sja-mode').val();
        $('.sja-aire-epreuve').toggle(mode === 'par_epreuve');
    }
    $('#sja-mode').on('change', sjaModeUI);
    sjaModeUI();

    // Verdict UI
    function sjaVerdictUI() {
        $('.sja-aire-row').each(function () {
            var isZemita = $(this).find('.sja-aire-type-verdict').val() === 'zemita';
            $(this).find('.sja-aire-seuil-label').toggle(!isZemita);
            $(this).find('.sja-aire-seuil').toggle(!isZemita);
        });
    }
    $(document).on('change', '.sja-aire-type-verdict', sjaVerdictUI);
    sjaVerdictUI();

}(jQuery));
</script>

<script>
// Barème ZEMITA
document.getElementById('sja-save-zemita-seuils').onclick = function() {
    var seuils = {};
    document.querySelectorAll('.sja-zemita-cell').forEach(function(el) {
        var g = el.dataset.groupe;
        var c = el.dataset.cat;
        var t = el.dataset.type;
        if (!seuils[g]) seuils[g] = {};
        if (!seuils[g][c]) seuils[g][c] = {};
        seuils[g][c][t] = el.value;
    });
    var btn = this;
    btn.disabled = true; btn.textContent = '…';
    var fd = new FormData();
    fd.append('action', 'sp_jury_save_zemita_seuils');
    fd.append('nonce', '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>');
    fd.append('event_id', '<?php echo intval($event_id); ?>');
    fd.append('epreuve_id', '3');
    fd.append('seuils', JSON.stringify(seuils));
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(r){
            btn.disabled = false; btn.textContent = '💾 Sauvegarder le barème ZEMITA';
            var msg = document.getElementById('sja-zemita-msg');
            msg.textContent = r.success ? '✓ Sauvegardé' : '✗ Erreur';
            msg.style.color = r.success ? '#16a34a' : '#dc2626';
            setTimeout(function(){ msg.textContent = ''; }, 3000);
        });
};

// Barème Réactivité
document.getElementById('sja-save-reactivite-seuils').onclick = function() {
    var seuils = {};
    document.querySelectorAll('.sja-reactivite-cell').forEach(function(el) {
        var g = el.dataset.groupe;
        var c = el.dataset.cat;
        var t = el.dataset.type;
        if (!seuils[g]) seuils[g] = {};
        if (!seuils[g][c]) seuils[g][c] = {};
        seuils[g][c][t] = el.value;
    });
    var btn = this;
    btn.disabled = true; btn.textContent = '…';
    var fd = new FormData();
    fd.append('action', 'sp_jury_save_zemita_seuils');
    fd.append('nonce', '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>');
    fd.append('event_id', '<?php echo intval($event_id); ?>');
    fd.append('epreuve_id', '4');
    fd.append('seuils', JSON.stringify(seuils));
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(r){
            btn.disabled = false; btn.textContent = '💾 Sauvegarder le barème Réactivité';
            var msg = document.getElementById('sja-reactivite-msg');
            msg.textContent = r.success ? '✓ Sauvegardé' : '✗ Erreur';
            msg.style.color = r.success ? '#16a34a' : '#dc2626';
            setTimeout(function(){ msg.textContent = ''; }, 3000);
        });
};

// Mapping grade → groupe
document.getElementById('sja-save-zemita-mapping').onclick = function() {
    var mapping = {};
    document.querySelectorAll('.sja-zemita-map-select').forEach(function(el) {
        var grade = el.dataset.grade;
        var groupe = el.value;
        if (grade && groupe) mapping[grade] = groupe;
    });
    var btn = this;
    btn.disabled = true; btn.textContent = '…';
    var fd = new FormData();
    fd.append('action', 'sp_jury_save_zemita_mapping');
    fd.append('nonce', '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>');
    fd.append('mapping', JSON.stringify(mapping));
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(r){
            btn.disabled = false; btn.textContent = '💾 Sauvegarder le mapping';
            var msg = document.getElementById('sja-mapping-msg');
            msg.textContent = r.success ? '✓ Mapping sauvegardé' : '✗ Erreur';
            msg.style.color = r.success ? '#16a34a' : '#dc2626';
            setTimeout(function(){ msg.textContent = ''; }, 3000);
        });
};

// Ajouter une aire — garde jQuery pour celui-là car il fonctionne
(function($){
    $('#sja-add-aire').on('click', function () {
        var aireCount = $('.sja-aire-row').length + 1;
        var epreuvesOptions = '<option value="">— Épreuve —</option>';
        <?php foreach ($epreuves as $ep): ?>
        epreuvesOptions += '<option value="<?php echo intval($ep->id); ?>"><?php echo esc_js($ep->nom); ?></option>';
        <?php endforeach; ?>
        $('#sja-aires-wrap').append(
            '<div class="sja-aire-row" style="flex-wrap:wrap;gap:8px;padding:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;">'
            + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;width:100%;">'
            + '<input type="text" class="sja-aire-label" placeholder="Libellé (ex: Aire A)" style="width:160px;border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;">'
            + '<select class="sja-aire-epreuve" style="border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;">' + epreuvesOptions + '</select>'
            + '<span style="font-size:12px;color:#9ca3af;">Aire ' + aireCount + '</span>'
            + '<button class="sja-btn sja-btn-red sja-btn-sm sja-del-aire" onclick="jQuery(this).closest(\'.sja-aire-row\').remove()">✕</button>'
            + '</div>'
            + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px;">'
            + '<label style="font-size:12px;color:#374151;font-weight:600;">Note min</label>'
            + '<input type="number" class="sja-aire-note-min" min="0" max="999" value="0" style="width:65px;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:13px;">'
            + '<label style="font-size:12px;color:#374151;font-weight:600;">Note max</label>'
            + '<input type="number" class="sja-aire-note-max" min="1" max="999" value="10" style="width:65px;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:13px;">'
            + '<label style="font-size:12px;color:#374151;font-weight:600;">Type verdict</label>'
            + '<select class="sja-aire-type-verdict" style="border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:13px;"><option value="seuil_fixe">Seuil fixe</option><option value="zemita">ZEMITA</option></select>'
            + '<label style="font-size:12px;color:#374151;font-weight:600;" class="sja-aire-seuil-label">Seuil admission</label>'
            + '<input type="number" class="sja-aire-seuil" min="0" step="0.5" value="0" style="width:75px;border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:13px;">'
            + '</div></div>'
        );
    });
})(jQuery);
</script>
            <?php elseif ($step===2): /* ── ÉTAPE 2 : Juges ── */ ?>
							<h2>👤 Juges par aire</h2>
            <?php
            $juges_by_aire = array();
            foreach ($juges as $j) $juges_by_aire[intval($j->table_id)][] = $j;
            ?>
            <?php foreach ($aires as $a):
                $aid = intval($a->id);
                $label_a = esc_html($a->label ?: 'Aire '.$a->numero);
            ?>
							<div style="margin-bottom:20px;">
								<div style="font-weight:700;font-size:13px;margin-bottom:8px;color:#374151;">📋 <?php echo $label_a; ?>
								</div>
								<div class="sja-juges-aire" data-aire-id="<?php echo $aid; ?>">
                <?php foreach ($juges_by_aire[$aid]??[] as $j): ?>
									<div class="sja-juge-row">
										<input type="hidden" class="sja-juge-id" value="<?php echo intval($j->id); ?>">
											<input type="hidden" class="sja-juge-aire" value="<?php echo $aid; ?>">
												<input type="text" class="sja-juge-prenom" placeholder="Prénom" value="<?php echo esc_attr($j->prenom); ?>" style="width:100px;border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;">
													<input type="text" class="sja-juge-nom" placeholder="Nom" value="<?php echo esc_attr($j->nom); ?>" style="width:120px;border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;">
														<select class="sja-juge-trainer" style="border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;">
															<option value="">— Externe (saisie libre) —</option>
                        <?php foreach ($trainers as $tr): ?>
															<option value="<?php echo intval($tr->id); ?>" data-nom="<?php echo esc_attr($tr->nom); ?>"<?php selected($j->trainer_id,$tr->id); ?>>
                            <?php echo esc_html($tr->nom); ?>
														</option>
                        <?php endforeach; ?>
													</select>
													<button class="sja-btn sja-btn-red sja-btn-sm sja-del-juge">✕</button>
												</div>
                <?php endforeach; ?>
											</div>
											<button class="sja-btn sja-btn-gray sja-btn-sm sja-add-juge" data-aire-id="<?php echo $aid; ?>" style="margin-top:6px;">+ Ajouter juge</button>
										</div>
            <?php endforeach; ?>
										<button class="sja-btn sja-btn-primary" id="sja-save-juges">💾 Sauvegarder les juges →</button>
										<span class="sja-msg" id="sja-juges-msg"/>
			<script>
(function($){
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
        var $btn = $(this).prop('disabled', true).text('…');
        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action:   'sp_jury_save_juges',
            nonce:    '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',
            event_id: <?php echo intval($event_id); ?>,
            juges:    juges
        }, function (r) {
            if (r.success) {
                $('#sja-juges-msg').text('✓ Sauvegardé').css('color','#16a34a');
                setTimeout(function () {
                    location.href = '?page=sp-cal-jury&event_id=<?php echo intval($event_id); ?>&step=3';
                }, 600);
            } else {
                $btn.prop('disabled', false).text('💾 Sauvegarder les juges →');
                $('#sja-juges-msg').text('✗ Erreur').css('color','#dc2626');
            }
        });
    });

    $(document).on('change', '.sja-juge-trainer', function () {
        var nom = $(this).find('option:selected').data('nom') || '';
        var parts = nom.split(' ');
        $(this).closest('.sja-juge-row').find('.sja-juge-prenom').val(parts[0] || '');
        $(this).closest('.sja-juge-row').find('.sja-juge-nom').val(parts.slice(1).join(' ') || nom);
    });

    $(document).on('click', '.sja-del-juge', function () {
        $(this).closest('.sja-juge-row').remove();
    });

    $(document).on('click', '.sja-add-juge', function () {
        var aid = $(this).data('aire-id');
        var trainerOptions = '<option value="">— Externe (saisie libre) —</option>';
        <?php foreach ($trainers as $tr): ?>
        trainerOptions += '<option value="<?php echo intval($tr->id); ?>" data-nom="<?php echo esc_js($tr->nom); ?>"><?php echo esc_js($tr->nom); ?></option>';
        <?php endforeach; ?>
        $('.sja-juges-aire[data-aire-id="' + aid + '"]').append(
            '<div class="sja-juge-row">'
            + '<input type="hidden" class="sja-juge-id" value="">'
            + '<input type="hidden" class="sja-juge-aire" value="' + aid + '">'
            + '<input type="text" class="sja-juge-prenom" placeholder="Prénom" style="width:100px;border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;">'
            + '<input type="text" class="sja-juge-nom" placeholder="Nom" style="width:120px;border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;">'
            + '<select class="sja-juge-trainer" style="border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;"><option value="">— Externe —</option>' + trainerOptions + '</select>'
            + '<button class="sja-btn sja-btn-red sja-btn-sm sja-del-juge">✕</button>'
            + '</div>'
        );
    });
}(window.jQuery));
</script>

            <?php elseif ($step===3): /* ── ÉTAPE 3 : Candidats ── */
            $mode_session = $session->mode ?? 'par_categorie';
            $is_par_epreuve = ($mode_session === 'par_epreuve');
            ?>
										<h2><?php echo $is_par_epreuve ? '🙋 Ajouter les candidats à l\'examen' : '🙋 Affecter les candidats aux aires'; ?>
										</h2>

            <?php if ($is_par_epreuve): ?>
										<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#1e40af;">
                Mode <strong>par épreuve</strong> — chaque candidat ajouté sera automatiquement affecté à toutes les aires.
            </div>
										<div style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
											<button class="sja-btn sja-btn-primary sja-btn-sm" id="sja-bulk-assign-cand">✅ Ajouter à l'examen</button>
											<span class="sja-msg" id="sja-cand-msg"/>
										</div>

			
            <?php else: ?>
										<div style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
											<label style="font-size:13px;font-weight:700;">Affecter la sélection vers :</label>
											<select id="sja-bulk-aire" style="border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;font-size:13px;">
												<option value="">— Choisir une aire —</option>
                    <?php foreach ($aires as $a): ?>
												<option value="<?php echo intval($a->id); ?>"><?php echo esc_html($a->label?:'Aire '.$a->numero); ?>
												</option>
                    <?php endforeach; ?>
											</select>
											<button class="sja-btn sja-btn-primary sja-btn-sm" id="sja-bulk-assign-cand">✅ Assigner</button>
											<span class="sja-msg" id="sja-cand-msg"/>
										</div>

            <?php endif; // fin if/else $is_par_epreuve ?>

            <?php
            $affectations  = $this->db->get_affectations($event_id);
            $non_affectes  = $this->db->get_eleves_non_affectes($event_id);
            $aff_by_aire   = array();
            // En par_epreuve, dédupliquer par eleve_id pour le comptage
            $eleves_affectes_ids = array_unique( array_column( (array)$affectations, 'eleve_id' ) );
            $nb_affectes_step3   = count($eleves_affectes_ids);
            foreach ($affectations as $a2) $aff_by_aire[intval($a2->aire_id)][] = $a2;
            ?>
										<div class="sja-cand-panel">
											<!-- Non affectés -->
											<div class="sja-cand-col">
												<div class="sja-cand-col-hd">
													<span>Élèves disponibles</span>
													<label style="font-size:12px;font-weight:400;">
														<input type="checkbox" id="sja-check-all-na"> Tout</label>
													</div>
													<div class="sja-cand-list" id="sja-non-affectes">
                    <?php foreach ($non_affectes as $el): ?>
														<div class="sja-cand-item" data-eleve="<?php echo intval($el->id); ?>">
															<input type="checkbox" class="sja-cand-check" data-eleve="<?php echo $el->id; ?>">
																<span><?php echo esc_html($el->prenom.' '.mb_strtoupper($el->nom)); ?>
																</span>
                        <?php if ($el->categorie_age): ?>
																<span class="sja-cand-tag"><?php echo esc_html($el->categorie_age); ?>
																</span><?php endif; ?>
                        <?php if ($el->grade): ?>
																<span class="sja-cand-tag" style="background:#f3e8ff;color:#7e22ce;"><?php echo esc_html($el->grade); ?>
																</span><?php endif; ?>
															</div>
                    <?php endforeach; ?>
                    <?php if (empty($non_affectes)): ?>
															<div style="padding:20px;color:#9ca3af;font-size:13px;text-align:center;">Tous les élèves sont affectés ✓</div><?php endif; ?>
														</div>
													</div>
													<!-- Affectés : liste plate (par_epreuve) ou par aire (par_categorie) -->
													<div class="sja-cand-col">
														<div class="sja-cand-col-hd">
															<span><?php echo $is_par_epreuve ? 'Candidats dans l\'examen' : 'Candidats par aire'; ?>
															</span>
														</div>
														<div class="sja-cand-list">
                    <?php
                    // Résultats officiels (post step 6) — 1 seule requête, commun aux deux modes
                    $passages = $wpdb->get_results( $wpdb->prepare(
                        "SELECT eleve_id, recu AS admis FROM {$wpdb->prefix}sp_cal_exam_passages WHERE event_id = %d",
                        $event_id
                    ), OBJECT_K );
                    if ( ! function_exists( 'sja_dot' ) ) {
                        function sja_dot( $eleve_id, $passages ) {
                            if ( ! isset( $passages[ $eleve_id ] ) ) {
                                return '<span title="Pas encore noté" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#9ca3af;margin-right:6px;flex-shrink:0;"></span>';
                            }
                            return $passages[ $eleve_id ]->admis
                                ? '<span title="Admis"    style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#16a34a;margin-right:6px;flex-shrink:0;"></span>'
                                : '<span title="Ajourné"  style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#dc2626;margin-right:6px;flex-shrink:0;"></span>';
                        }
                    }
                    ?>
                    <?php if ($is_par_epreuve): ?>
                        <?php
                        // Afficher chaque candidat une seule fois (dédupliqué)
                        $vus = array();
                        foreach ($affectations as $c):
                            if (in_array($c->eleve_id, $vus)) continue;
                            $vus[] = $c->eleve_id;
                        ?>
															<div class="sja-cand-item" style="cursor:default;">
                            <?php echo sja_dot( $c->eleve_id, $passages ); ?>
																<span style="flex:1;"><?php echo esc_html($c->prenom.' '.mb_strtoupper($c->nom)); ?>
																</span>
                            <?php if ($c->categorie_age): ?>
																<span class="sja-cand-tag"><?php echo esc_html($c->categorie_age); ?>
																</span><?php endif; ?>
                            <?php if ($c->grade): ?>
																<span class="sja-cand-tag" style="background:#f3e8ff;color:#7e22ce;"><?php echo esc_html($c->grade); ?>
																</span><?php endif; ?>
																<button class="sja-btn sja-btn-red sja-btn-sm sja-unassign-cand" data-eleve="<?php echo intval($c->eleve_id); ?>" data-aire="0" style="font-size:10px;padding:2px 6px;">✕</button>
															</div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($aires as $a): $aid = intval($a->id); ?>
															<div style="padding:6px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;background:#f8fafc;border-bottom:1px solid #f1f5f9;">
                            <?php echo esc_html($a->label ?: 'Aire '.$a->numero); ?> (<?php echo count($aff_by_aire[$aid] ?? []); ?>)
                        </div>
                        <?php foreach ($aff_by_aire[$aid] ?? [] as $c): ?>
															<div class="sja-cand-item" style="cursor:default;">
                            <?php echo sja_dot( $c->eleve_id, $passages ); ?>
																<span style="flex:1;"><?php echo esc_html($c->prenom.' '.mb_strtoupper($c->nom)); ?>
																</span>
                            <?php if ($c->grade): ?>
																<span class="sja-cand-tag" style="background:#f3e8ff;color:#7e22ce;"><?php echo esc_html($c->grade); ?>
																</span><?php endif; ?>
																<button class="sja-btn sja-btn-red sja-btn-sm sja-unassign-cand" data-eleve="<?php echo intval($c->eleve_id); ?>" data-aire="<?php echo intval($c->aire_id); ?>" style="font-size:10px;padding:2px 6px;">✕</button>
															</div>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (empty($affectations)): ?>
															<div style="padding:20px;color:#9ca3af;font-size:13px;text-align:center;">Aucun candidat ajouté</div>
                    <?php endif; ?>
														</div>
													</div>
												</div>
												<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <?php if ( $nb_affectes_step3 > 0 ) : ?>
                <?php
                $nonce_print = wp_create_nonce('sp_cal_print');
                $url_fiches  = admin_url('admin-ajax.php?action=sp_cal_print_jury_fiches&event_id='.$event_id.'&_wpnonce='.$nonce_print);
                ?>
													<a class="sja-btn sja-btn-outline" href="<?php echo esc_url($url_fiches); ?>" target="_blank">
                    🖨️ Imprimer fiches A5
                </a>
													<button class="sja-btn sja-btn-red sja-btn-sm" id="sja-reset-candidats" style="margin-left:auto;">
                    🗑️ Réinitialiser tous les candidats
                </button>
                <?php endif; ?>
													<a class="sja-btn sja-btn-primary" href="<?php echo esc_url($base_url.'&step=4'); ?>">Continuer → Lancer l'examen</a>
												</div>
<script>
(function($){
    $('#sja-bulk-assign-cand').on('click', function () {
        var aire_id = '<?php echo esc_js($mode_session); ?>' === 'par_epreuve' ? 0 : $('#sja-bulk-aire').val();
        if ('<?php echo esc_js($mode_session); ?>' !== 'par_epreuve' && !aire_id) { alert('Choisissez une aire.'); return; }
        var eids = [];
        $('.sja-cand-check:checked').each(function () { eids.push(parseInt($(this).data('eleve'), 10)); });
        if (!eids.length) { alert('Cochez au moins un élève.'); return; }
        $('.sja-unassign-cand').each(function () {
            var eid = parseInt($(this).data('eleve'), 10);
            if (eids.indexOf(eid) === -1) eids.push(eid);
        });
        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action:    'sp_jury_save_affectations',
            nonce:     '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',
            event_id:  <?php echo intval($event_id); ?>,
            aire_id:   aire_id,
            eleve_ids: eids
        }, function (r) {
            if (r.success) {
                var url = location.href.replace(/([?&])_ts=[^&]*/, '');
                var sep = url.indexOf('?') !== -1 ? '&' : '?';
                location.href = url + sep + '_ts=' + Date.now();
            }
        });
    });

    $('#sja-check-all-na').on('change', function () {
        $('.sja-cand-check').prop('checked', this.checked);
    });

    $(document).on('click', '.sja-unassign-cand', function () {
        var eid = $(this).data('eleve');
        if (!confirm('Retirer ce candidat de l\'examen ?')) return;
        var $btn = $(this).prop('disabled', true).text('…');
        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action:   'sp_jury_remove_candidat',
            nonce:    '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',
            event_id: <?php echo intval($event_id); ?>,
            eleve_id: eid
        }, function (r) {
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
        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action:   'sp_jury_reset_affectations',
            nonce:    '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',
            event_id: <?php echo intval($event_id); ?>
        }, function (r) {
            if (r.success) {
                var url = location.href.replace(/([?&])_ts=[^&]*/, '');
                var sep = url.indexOf('?') !== -1 ? '&' : '?';
                location.href = url + sep + '_ts=' + Date.now();
            } else {
                alert('Erreur.');
                $btn.prop('disabled', false).text('🗑️ Réinitialiser tous les candidats');
            }
        });
    });
})(jQuery);
</script>
            <?php elseif ($step===4): /* ── ÉTAPE 4 : Lancer ── */ ?>
            <?php
            $nb_juges    = count($juges);
            $nb_affectes = count($this->db->get_affectations($event_id));
            $nb_aires    = count($aires);
            ?>
												<h2>🚀 Lancer l'examen</h2>
												<div class="sja-recap">
													<div class="sja-recap-card">
														<div class="n"><?php echo $nb_aires; ?>
														</div>
														<div class="l">Aires</div>
													</div>
													<div class="sja-recap-card">
														<div class="n"><?php echo $nb_juges; ?>
														</div>
														<div class="l">Juges</div>
													</div>
													<div class="sja-recap-card">
														<div class="n"><?php echo $nb_affectes; ?>
														</div>
														<div class="l">Candidats</div>
													</div>
												</div>

            <?php if ($statut==='preparation'): ?>
												<div class="sja-launch-box">
													<h3>Tout est prêt ?</h3>
													<p>Les juges scannent le QR code de leur aire sur leurs téléphones. Cliquez pour démarrer.</p>
													<button class="sja-launch-big sja-btn-green" onclick="sjaSetStatut('en_cours')">
                    🟢 Démarrer l'examen
                </button>
												</div>
            <?php elseif ($statut==='en_cours'): ?>
												<div style="background:#dcfce7;border:1px solid #86efac;border-radius:10px;padding:20px;margin-bottom:16px;text-align:center;">
													<div style="font-size:20px;font-weight:800;color:#14532d;">🟢 Examen en cours</div>
													<p style="color:#15803d;margin:8px 0 16px;">Les juges peuvent saisir les notes.</p>
													<button class="sja-btn sja-btn-red" onclick="sjaSetStatut('termine')">🔴 Terminer l'examen</button>
												</div>
            <?php else: ?>
												<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:20px;margin-bottom:16px;text-align:center;">
													<div style="font-size:20px;font-weight:800;color:#7f1d1d;">🔴 Examen terminé</div>
													<p style="color:#991b1b;margin:8px 0 16px;">Les notes sont en lecture seule.</p>
													<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
														<button class="sja-btn sja-btn-green" onclick="sjaSetStatut('en_cours')">
                        🟢 Rouvrir l'examen
                    </button>
														<button class="sja-btn sja-btn-gray" onclick="sjaSetStatut('preparation')">
                        🟡 Repasser en préparation
                    </button>
													</div>
													<p style="font-size:11px;color:#9ca3af;margin-top:10px;">Rouvrir permet d'ajouter des candidats oubliés ou corriger des notes. Les données existantes sont conservées.</p>
												</div>
            <?php endif; ?>
												<!-- QR codes par aire (mode numérique) ou rappel mode papier -->
            <?php if ( ($session->support_notation ?? 'numerique') === 'numerique' ) : ?>
												<h3 style="font-size:14px;font-weight:700;margin:16px 0 10px;">📱 QR codes des aires</h3>
												<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">
            <?php foreach ($aires as $a): ?>
													<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;text-align:center;">
														<div style="font-weight:700;font-size:13px;margin-bottom:12px;"><?php echo esc_html($a->label?:'Aire '.$a->numero); ?>
														</div>
														<div id="qr-<?php echo $a->id; ?>" style="height:120px;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:12px;">
															<button class="sja-btn sja-btn-outline sja-btn-sm" onclick="sjaLoadQR(<?php echo $event_id; ?>,<?php echo $a->id; ?>)">Afficher QR</button>
														</div>
													</div>
            <?php endforeach; ?>
            <?php else : /* Mode papier */ ?>
													<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:16px;margin-top:16px;">
														<div style="font-weight:700;font-size:14px;color:#1d4ed8;margin-bottom:8px;">🖊️ Mode papier activé</div>
														<p style="font-size:13px;color:#1e40af;margin-bottom:12px;">
                    Les juges utilisent les fiches A5 imprimées à l'étape précédente.<br>
                    Les notes seront saisies dans l'onglet <strong>Suivi / Saisie</strong> après la fin de l'examen.
                </p>
                <?php
                $nonce_print2 = wp_create_nonce('sp_cal_print');
                $url_fiches2  = admin_url('admin-ajax.php?action=sp_cal_print_jury_fiches&event_id='.$event_id.'&_wpnonce='.$nonce_print2);
                ?>
															<a class="sja-btn sja-btn-outline sja-btn-sm" href="<?php echo esc_url($url_fiches2); ?>" target="_blank">🖨️ Réimprimer les fiches A5</a>
														</div>
            <?php endif; ?>
													</div>
													<div style="margin-top:14px;">
														<a class="sja-btn sja-btn-primary" href="<?php echo esc_url($base_url.'&step=5'); ?>">📊 Voir le suivi live →</a>
													</div>
													<script>
function sjaSetStatut(statut) {
    if (!confirm('Changer le statut vers "' + statut + '" ?')) return;
    jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
        action:   'sp_jury_set_statut',
        nonce:    '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',
        event_id: <?php echo intval($event_id); ?>,
        statut:   statut
    }, function(r) { if (r.success) location.reload(); });
}
function sjaLoadQR(eid, aid) {
    jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
        action:   'sp_jury_get_qr_url',
        nonce:    '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',
        event_id: eid,
        aire_id:  aid
    }, function(r) {
        if (!r.success) return;
        document.getElementById('qr-' + aid).innerHTML =
            '<img src="' + r.data.qr + '" style="width:120px;height:120px;border-radius:6px;">'
            + '<div style="font-size:11px;word-break:break-all;color:#64748b;margin-top:8px;">' + r.data.url + '</div>';
    });
}
</script>
													
<?php elseif ($step===5): /* ── ÉTAPE 5 : Suivi / Saisie ── */ ?>
<?php
$support            = $session->support_notation ?? 'numerique';
$snap_existant      = get_option('sp_jury_snapshot_' . $event_id, '');
$deja_transcrit     = !empty($snap_existant);
$nonce_csv          = wp_create_nonce('sp_cal_print');
$url_csv            = admin_url('admin-ajax.php?action=sp_cal_print_jury_csv&event_id='.$event_id.'&_wpnonce='.$nonce_csv);
$epreuves_step5     = $this->db->get_exam_epreuves(null, true);
$affectations_step5 = $this->db->get_affectations($event_id);
$zemita_grid_step5      = $this->db->get_zemita_seuils($event_id, 3);
$reactivite_grid_step5  = $this->db->get_zemita_seuils($event_id, 4);
$seuil5             = floatval($session->seuil_admission ?? 0);
global $wpdb;
$tn5   = $this->db->table_jury_notes();
$rows5 = $wpdb->get_results($wpdb->prepare(
    "SELECT eleve_id, epreuve_id, note FROM $tn5 WHERE event_id=%d", $event_id
));
$notes_idx_step5 = array();
foreach ($rows5 as $r5) {
    $notes_idx_step5[intval($r5->eleve_id)][intval($r5->epreuve_id)] = floatval($r5->note);
}
?>
<!-- ══ STEP 5 : Saisie admin (papier ET numérique) ══ -->
                    <h2>📋 Saisie des notes</h2>

                    <?php if ($support === 'numerique'): ?>
                    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#1e40af;">
                        Mode <strong>numérique</strong> — les juges saisissent via QR code. Vous pouvez également saisir ou corriger des notes manuellement ci-dessous.
                    </div>
                    <?php endif; ?>

                    <!-- Onglets -->
                    <div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #e2e8f0;">
                        <button class="sja-tab-btn active" data-tab="saisie-rapide" style="padding:10px 20px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:700;color:#2563eb;border-bottom:2px solid #2563eb;margin-bottom:-2px;">
                            🎯 Saisie par candidat
                        </button>
                        <button class="sja-tab-btn" data-tab="saisie-tableau" style="padding:10px 20px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;color:#64748b;">
                            📊 Vue tableau
                        </button>
                    </div>

                    <!-- ── ONGLET 1 : Saisie par candidat ── -->
                    <div id="tab-saisie-rapide" class="sja-tab-content">
                        <style>
                        .sja-cat-section{margin-bottom:16px;}
                        .sja-cat-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:6px 12px;margin-bottom:6px;}
                        .sja-cand-btn{display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-size:13px;margin-bottom:4px;text-align:left;transition:background .1s;}
                        .sja-cand-btn:hover{background:#eff6ff;border-color:#bfdbfe;}
                        .sja-cand-btn.active{background:#eff6ff;border-color:#2563eb;}
                        .sja-cand-btn.noted{border-left:4px solid #16a34a;}
                        .sja-cand-btn .cb-name{flex:1;font-weight:600;}
                        .sja-cand-btn .cb-grade{font-size:11px;color:#7e22ce;background:#f3e8ff;padding:1px 6px;border-radius:8px;}
                        .sja-cand-btn .cb-status{font-size:11px;font-weight:700;}
                        .sja-candidat-card{background:#fff;border:2px solid #2563eb;border-radius:12px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
                        .cc-avatar{width:44px;height:44px;border-radius:50%;background:#2563eb;color:#fff;font-size:15px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
                        .cc-info{flex:1;}
                        .cc-name{font-size:16px;font-weight:800;color:#0f172a;}
                        .cc-meta{font-size:12px;color:#64748b;margin-top:2px;}
                        .cc-aire{font-size:12px;font-weight:700;background:#eff6ff;color:#2563eb;padding:3px 10px;border-radius:20px;}
                        .sja-ep-row{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px;}
                        .sja-ep-row.ep-zemita{border-left:4px solid #f59e0b;}
                        .sja-ep-row.ep-standard{border-left:4px solid #2563eb;}
                        .sja-ep-name{flex:1;font-size:13px;font-weight:700;color:#374151;}
                        .sja-ep-badge{font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:10px;margin-left:6px;}
                        .badge-zemita{background:#fef3c7;color:#92400e;}
                        .badge-standard{background:#dbeafe;color:#1e40af;}
                        .sja-note-wrap,.sja-coups-wrap{display:flex;align-items:center;gap:8px;}
                        .sja-note-wrap label,.sja-coups-wrap label{font-size:12px;color:#64748b;font-weight:600;}
                        .sja-note-input-rs,.sja-coups-input{width:80px;padding:7px 10px;border:2px solid #e2e8f0;border-radius:8px;font-size:17px;font-weight:900;text-align:center;color:#0f172a;outline:none;}
                        .sja-note-input-rs:focus{border-color:#2563eb;}
                        .sja-coups-input:focus{border-color:#f59e0b;}
                        .sja-coups-result{font-size:13px;font-weight:700;color:#16a34a;background:#f0fdf4;padding:5px 10px;border-radius:8px;min-width:55px;text-align:center;}
                        .sja-note-max{font-size:12px;color:#94a3b8;}
                        .sja-score-bar-rs{background:linear-gradient(135deg,#1e3a5f,#2563eb);color:#fff;border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:14px;margin-bottom:16px;flex-wrap:wrap;}
                        .sp-label{font-size:13px;opacity:.8;flex:1;}
                        .sp-val{font-size:26px;font-weight:900;}
                        .sp-verdict{font-size:12px;font-weight:700;padding:5px 14px;border-radius:20px;}
                        .sp-verdict-admis{background:#16a34a;color:#fff;}
                        .sp-verdict-ajourne{background:#dc2626;color:#fff;}
                        .sp-verdict-wait{background:rgba(255,255,255,.2);color:#fff;}
                        .sja-actions-rs{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px;}
                        .sja-msg-ok{font-size:13px;background:#f0fdf4;color:#16a34a;border:1px solid #86efac;padding:6px 12px;border-radius:8px;}
                        .sja-msg-err{font-size:13px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;padding:6px 12px;border-radius:8px;}
                        </style>

                        <div style="display:grid;grid-template-columns:260px 1fr;gap:16px;">
                            <!-- Colonne gauche : liste candidats -->
                            <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                                <div style="padding:10px 14px;background:#f8fafc;font-weight:700;font-size:13px;border-bottom:1px solid #e5e7eb;">
                                    Candidats (<?php echo count(array_unique(array_column((array)$affectations_step5, 'eleve_id'))); ?>)
                                </div>
                                <div style="max-height:500px;overflow-y:auto;padding:8px;">
                                <?php
                                // Grouper par catégorie d'âge
                                $cands_by_cat = array();
                                $vus5 = array();
                                foreach ($affectations_step5 as $c5) {
                                    $eid5 = intval($c5->eleve_id ?? $c5->id);
                                    if (in_array($eid5, $vus5)) continue;
                                    $vus5[] = $eid5;
                                    $cat5 = $c5->categorie_age ?: 'Sans catégorie';
                                    $cands_by_cat[$cat5][] = $c5;
                                }
                                ksort($cands_by_cat);
                                foreach ($cands_by_cat as $cat5 => $cands5):
                                ?>
                                <div class="sja-cat-section">
                                    <div class="sja-cat-title"><?php echo esc_html($cat5); ?></div>
                                    <?php foreach ($cands5 as $c5):
                                        $eid5 = intval($c5->eleve_id ?? $c5->id);
                                        $has_notes = !empty($notes_idx_step5[$eid5]);
                                        $noted_class = $has_notes ? ' noted' : '';
                                        $noted_label = $has_notes ? '<span class="cb-status" style="color:#16a34a;">✓</span>' : '';
                                        // Préparer données JSON pour JS
                                        $c5_json = json_encode(array(
                                            'id'            => $eid5,
                                            'nom'           => $c5->nom,
                                            'prenom'        => $c5->prenom,
                                            'grade'         => $c5->grade ?? '',
                                            'categorie_age' => $c5->categorie_age ?? '',
                                            'aire_id'       => intval($c5->aire_id ?? 0),
                                            'zemita_groupe' => $c5->zemita_groupe ?? '',
                                            'notes'         => $notes_idx_step5[$eid5] ?? (object)[],
                                        ));
                                    ?>
                                    <button class="sja-cand-btn<?php echo $noted_class; ?>"
                                            data-json='<?php echo esc_attr($c5_json); ?>'
                                            onclick="sja5SelectCandidat(this)">
                                        <span class="cb-name"><?php echo esc_html($c5->prenom.' '.mb_strtoupper($c5->nom)); ?></span>
                                        <?php if ($c5->grade): ?><span class="cb-grade"><?php echo esc_html($c5->grade); ?></span><?php endif; ?>
                                        <?php echo $noted_label; ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($affectations_step5)): ?>
                                <p style="color:#9ca3af;font-size:13px;padding:12px;">Aucun candidat affecté.</p>
                                <?php endif; ?>
                                </div>
                            </div>

                            <!-- Colonne droite : formulaire saisie -->
                            <div>
                                <div id="sja5-placeholder" style="color:#9ca3af;font-size:13px;padding:20px;text-align:center;border:2px dashed #e5e7eb;border-radius:10px;">
                                    ← Sélectionnez un candidat
                                </div>
                                <div id="sja-candidat-card" style="display:none;"></div>
                                <div id="sja-epreuves-grid" style="display:none;"></div>
                                <div class="sja-score-bar-rs" id="sja-score-bar-rs" style="display:none;">
                                    <div class="sp-label">Moyenne calculée</div>
                                    <div class="sp-val" id="sja-sp-val">—</div>
                                    <div class="sp-verdict sp-verdict-wait" id="sja-sp-verdict">En attente</div>
                                </div>
                                <div class="sja-actions-rs" id="sja-actions-rs" style="display:none;">
                                    <button class="sja-btn sja-btn-green" id="sja-btn-save">💾 Enregistrer</button>
                                    <button class="sja-btn sja-btn-outline" id="sja-btn-reset">✕ Désélectionner</button>
                                    <span id="sja-save-msg" style="display:none;"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- /#tab-saisie-rapide -->

                    <!-- ── ONGLET 2 : Vue tableau ── -->
                    <div id="tab-saisie-tableau" class="sja-tab-content" style="display:none;">
                    <?php if (empty($affectations_step5)): ?>
                        <p style="color:#9ca3af;">Aucun candidat affecté.</p>
                    <?php else: ?>
                        <p style="font-size:13px;color:#64748b;margin-bottom:14px;">
                            Vue globale — notes bornées entre <?php echo intval($session->note_min??0); ?> et <?php echo intval($session->note_max??10); ?>.
                        </p>
                        <div style="overflow-x:auto;">
                            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                                <thead style="background:#f8fafc;">
                                    <tr>
                                        <th style="padding:8px 10px;text-align:left;border:1px solid #e5e7eb;min-width:160px;">Candidat</th>
                                        <th style="padding:8px 10px;border:1px solid #e5e7eb;">Grade</th>
                                        <?php foreach ($epreuves_step5 as $ep): ?>
                                        <th style="padding:8px 10px;border:1px solid #e5e7eb;font-size:11px;min-width:90px;"><?php echo esc_html($ep->nom); ?></th>
                                        <?php endforeach; ?>
                                        <th style="padding:8px 10px;border:1px solid #e5e7eb;min-width:70px;">Score</th>
                                        <th style="padding:8px 10px;border:1px solid #e5e7eb;">Verdict</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $vus_tbl = array();
                                foreach ($affectations_step5 as $c5):
                                    $eid5 = intval($c5->eleve_id ?? $c5->id);
                                    if (in_array($eid5, $vus_tbl)) continue;
                                    $vus_tbl[] = $eid5;
                                    $score5 = 0; $has_note5 = false;
                                    foreach ($epreuves_step5 as $ep5) {
                                        if (isset($notes_idx_step5[$eid5][intval($ep5->id)])) {
                                            $score5 += $notes_idx_step5[$eid5][intval($ep5->id)];
                                            $has_note5 = true;
                                        }
                                    }
                                ?>
                                <tr id="sja-row-<?php echo $eid5; ?>">
                                    <td style="padding:6px 10px;border:1px solid #f1f5f9;font-weight:600;"><?php echo esc_html($c5->prenom.' '.mb_strtoupper($c5->nom)); ?></td>
                                    <td style="padding:6px 10px;border:1px solid #f1f5f9;font-size:12px;color:#64748b;"><?php echo esc_html($c5->grade??'—'); ?></td>
                                    <?php foreach ($epreuves_step5 as $ep5):
                                        $v5 = $notes_idx_step5[$eid5][intval($ep5->id)] ?? '';
                                    ?>
                                    <td style="padding:4px 6px;border:1px solid #f1f5f9;text-align:center;">
                                        <input type="number" class="sja-note-input"
                                               data-eleve="<?php echo $eid5; ?>"
                                               data-epreuve="<?php echo intval($ep5->id); ?>"
                                               data-seuil="<?php echo $seuil5; ?>"
                                               min="<?php echo intval($session->note_min??0); ?>"
                                               max="<?php echo intval($session->note_max??10); ?>"
                                               step="0.5"
                                               value="<?php echo $v5 !== '' ? esc_attr($v5) : ''; ?>"
                                               placeholder="—"
                                               style="width:70px;border:1px solid #d1d5db;border-radius:5px;padding:4px 6px;text-align:center;font-size:13px;">
                                    </td>
                                    <?php endforeach; ?>
                                    <td style="padding:6px 10px;border:1px solid #f1f5f9;font-weight:800;text-align:center;" id="sja-score-<?php echo $eid5; ?>">
                                        <?php echo $has_note5 ? number_format($score5,1,'.','') : '—'; ?>
                                    </td>
                                    <td style="padding:6px 10px;border:1px solid #f1f5f9;font-weight:700;" id="sja-verdict-<?php echo $eid5; ?>">
                                        <?php
                                        if (!$has_note5) echo '—';
                                        elseif ($score5 >= $seuil5) echo '<span style="color:#15803d;">✅ Admis</span>';
                                        else echo '<span style="color:#b91c1c;">❌ Ajourné</span>';
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="margin-top:12px;">
                            <span id="sja-saisie-msg" style="font-size:13px;color:#9ca3af;">Les notes sont sauvegardées à la saisie.</span>
                        </div>
                    <?php endif; ?>
                    </div>
                    <!-- /#tab-saisie-tableau -->

<!-- Export CSV + Impression résultats -->
<div style="margin-top:16px;border-top:1px solid #e2e8f0;padding-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <a class="sja-btn sja-btn-outline sja-btn-sm" href="<?php echo esc_url($url_csv); ?>" target="_blank">📥 Exporter CSV</a>
    <?php
    $nonce_res = wp_create_nonce('sp_cal_print');
    $url_res   = admin_url('admin-ajax.php?action=sp_cal_print_jury_fiches_resultats&event_id='.$event_id.'&_wpnonce='.$nonce_res);
    ?>
    <a class="sja-btn sja-btn-outline sja-btn-sm" href="<?php echo esc_url($url_res); ?>" target="_blank">🖨️ Imprimer résultats</a>
</div>

                    <!-- Lien transcription -->
                    <?php if ($statut === 'termine'): ?>
                    <div style="margin-top:12px;border-top:2px solid #e2e8f0;padding-top:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <?php if ($deja_transcrit): ?>
                        <span style="background:#dcfce7;color:#16a34a;font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;">✅ Grades déjà transcrits</span>
                        <?php endif; ?>
                        <a class="sja-btn sja-btn-primary" href="<?php echo esc_url($base_url.'&step=6'); ?>">
                            📝 Aller à la transcription des grades →
                        </a>
                    </div>
                    <?php endif; ?>
					<?php $zemita_mapping_step5 = $this->db->get_zemita_mapping(); ?>
                    <script>
(function($){

    var AJAXURL   = '<?php echo admin_url('admin-ajax.php'); ?>';
    var NONCE     = '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>';
    var EVENT_ID  = <?php echo intval($event_id); ?>;
    var SEUIL     = <?php echo $seuil5; ?>;
    var NOTE_MIN  = <?php echo intval($session->note_min ?? 0); ?>;
    var NOTE_MAX  = <?php echo intval($session->note_max ?? 10); ?>;

    var SEUILS_GRID = {
        <?php
        $ep_ids = array_column((array)$epreuves_step5, 'id');
        $idx3 = array_search(3, $ep_ids);
        $idx4 = array_search(4, $ep_ids);
        $id3 = intval($idx3 !== false ? $epreuves_step5[$idx3]->id : 3);
        $id4 = intval($idx4 !== false ? $epreuves_step5[$idx4]->id : 4);
        echo $id3 . ': ' . json_encode($zemita_grid_step5) . ',';
        echo $id4 . ': ' . json_encode($reactivite_grid_step5);
        ?>
    };

    var EPREUVES_TYPE = <?php
        $et = array();
        foreach ($epreuves_step5 as $ep) {
            $et[intval($ep->id)] = $ep->type_comptage ?? 'note_directe';
        }
        echo json_encode($et);
    ?>;

    var AIRES_DATA = <?php
        $ad = array();
        foreach ($aires as $a) {
            $ad[] = array(
                'id'              => intval($a->id),
                'label'           => $a->label ?: 'Aire '.$a->numero,
                'note_min'        => intval($a->note_min ?? 0),
                'note_max'        => intval($a->note_max ?? 10),
                'seuil_admission' => floatval($a->seuil_admission ?? 0),
            );
        }
        echo json_encode($ad);
    ?>;

    var EPREUVES = <?php
        $el = array();
        foreach ($epreuves_step5 as $ep) {
            $el[] = array('id' => intval($ep->id), 'nom' => $ep->nom);
        }
        echo json_encode($el);
    ?>;
	var ZEMITA_MAPPING = <?php echo json_encode($zemita_mapping_step5 ?? []); ?>;
    var currentCandidat = null;
    var currentAire     = null;
    var notesLocales    = {};
    var coupsLocaux     = {};

    function esc(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /* ── Onglets ── */
    $('.sja-tab-btn').on('click', function() {
        var tab = $(this).data('tab');
        $('.sja-tab-btn').css({'color':'#64748b','border-bottom-color':'transparent','font-weight':'600'});
        $(this).css({'color':'#2563eb','border-bottom':'2px solid #2563eb','margin-bottom':'-2px','font-weight':'700'});
        $('.sja-tab-content').hide();
        $('#tab-' + tab).show();
    });

    /* ── Sélection candidat depuis liste ── */
    window.sja5SelectCandidat = function(btn) {
		window._sja5Debug = JSON.parse($(btn).attr('data-json'));
console.log('candidat:', window._sja5Debug);
console.log('SEUILS_GRID:', SEUILS_GRID);
        $('.sja-cand-btn').removeClass('active');
        $(btn).addClass('active');
        var c = JSON.parse($(btn).attr('data-json'));
        selectCandidat(c);
    };

    function selectCandidat(c) {
        currentCandidat = c;
		if (!c.zemita_groupe && c.grade && ZEMITA_MAPPING[c.grade]) {
    c.zemita_groupe = ZEMITA_MAPPING[c.grade];
}
        notesLocales    = {};
        coupsLocaux     = {};

        currentAire = null;
        AIRES_DATA.forEach(function(a) {
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
            + '</div>';
        $('#sja5-placeholder').hide();
        $('#sja-candidat-card').show().html(html);
        renderEpreuves(c.notes || {});
        $('#sja-score-bar-rs, #sja-actions-rs').show();
        updateScore();
    }

    function renderEpreuves(existingNotes) {
        if (!currentCandidat || !currentAire) return;
        var cat    = currentCandidat.categorie_age || '';
        var groupe = currentCandidat.zemita_groupe || '';
        var html   = '';

        EPREUVES.forEach(function(ep) {
            var isCoups = (EPREUVES_TYPE[ep.id] === 'coups');
            var seuils  = null;
            if (isCoups && groupe && SEUILS_GRID[ep.id] && SEUILS_GRID[ep.id][groupe] && SEUILS_GRID[ep.id][groupe][cat]) {
                seuils = SEUILS_GRID[ep.id][groupe][cat];
            }
            var typeClass = isCoups ? 'ep-zemita' : 'ep-standard';

            // Récupérer note existante
            var existVal = (existingNotes && existingNotes[ep.id] !== undefined) ? existingNotes[ep.id] : '';
            if (existVal !== '') {
                notesLocales[ep.id] = parseFloat(existVal);
            }

            html += '<div class="sja-ep-row ' + typeClass + '" data-epid="' + ep.id + '">'
                + '<div class="sja-ep-name">' + esc(ep.nom)
                + '<span class="sja-ep-badge ' + (isCoups ? 'badge-zemita' : 'badge-standard') + '">'
                + (isCoups ? 'Coups' : '/' + currentAire.note_max)
                + '</span></div>';

            if (isCoups) {
                var cRef = seuils ? seuils.coups_ref : '';
                // Retrouver coups depuis note existante si possible
                var existCoups = '';
                if (existVal !== '' && seuils && seuils.coups_ref > 0) {
                    existCoups = Math.round(parseFloat(existVal) / 10 * seuils.coups_ref);
                    coupsLocaux[ep.id] = existCoups;
                }
html += '<div style="display:flex;flex-direction:column;gap:4px;">'
    + '<div class="sja-coups-wrap">'
    + '<label>Coups :</label>'
    + '<input type="number" class="sja-coups-input" data-epid="' + ep.id + '" min="0" step="1" placeholder="0" value="' + (existCoups || '') + '">'
    + '<div class="sja-coups-result" id="sja-result-' + ep.id + '">'
    + (existVal !== '' ? parseFloat(existVal).toFixed(1) + '/10' : '—')
    + '</div>'
    + '</div>'
    + '<div style="font-size:10px;color:#94a3b8;padding-left:52px;">'
    + (seuils ? 'Ref: ' + seuils.coups_ref + ' coups = 10/10' : '<span style="color:#dc2626;">Seuils non configurés</span>')
    + '</div>'
    + '</div>';
            } else {
                html += '<div class="sja-note-wrap">'
                    + '<label>Note :</label>'
                    + '<input type="number" class="sja-note-input-rs" data-epid="' + ep.id + '"'
                    + ' min="' + currentAire.note_min + '" max="' + currentAire.note_max + '" step="0.5"'
                    + ' value="' + (existVal !== '' ? existVal : '') + '" placeholder="0">'
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

    $(document).on('input', '.sja-coups-input', function() {
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

    $(document).on('input', '.sja-note-input-rs', function() {
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
        var moy = vals.reduce(function(a, b) { return a + b; }, 0) / vals.length;
        $('#sja-sp-val').text(moy.toFixed(2) + '/10');
        var sa = currentAire ? currentAire.seuil_admission : SEUIL;
        if (moy >= sa) {
            $('#sja-sp-verdict').attr('class', 'sp-verdict sp-verdict-admis').text('✅ Admis');
        } else {
            $('#sja-sp-verdict').attr('class', 'sp-verdict sp-verdict-ajourne').text('❌ Ajourné');
        }
    }

    $('#sja-btn-save').on('click', function() {
        if (!currentCandidat || !Object.keys(notesLocales).length) {
            showMsg('Aucune note saisie.', false); return;
        }
        var $btn = $(this).prop('disabled', true).text('…');
        var reqs = Object.keys(notesLocales).map(function(epid) {
            return $.post(AJAXURL, {
                action:     'sp_jury_save_note_admin',
                nonce:      NONCE,
                event_id:   EVENT_ID,
                eleve_id:   currentCandidat.id,
                epreuve_id: epid,
                note_val:   notesLocales[epid],
                coups_val:  coupsLocaux[epid] !== undefined ? coupsLocaux[epid] : '',
            });
        });
        $.when.apply($, reqs).done(function() {
            $btn.prop('disabled', false).text('💾 Enregistrer');
            showMsg('✓ Notes enregistrées.', true);
            // Marquer le bouton candidat comme noté
            $('.sja-cand-btn.active').addClass('noted')
                .find('.cb-status').remove();
            $('.sja-cand-btn.active').append('<span class="cb-status" style="color:#16a34a;">✓</span>');
        }).fail(function() {
            $btn.prop('disabled', false).text('💾 Enregistrer');
            showMsg('✗ Erreur réseau.', false);
        });
    });

    $('#sja-btn-reset').on('click', function() {
        currentCandidat = null; currentAire = null; notesLocales = {}; coupsLocaux = {};
        $('.sja-cand-btn').removeClass('active');
        $('#sja-candidat-card, #sja-epreuves-grid, #sja-score-bar-rs, #sja-actions-rs').hide();
        $('#sja5-placeholder').show();
        $('#sja-save-msg').hide();
    });

    function showMsg(txt, ok) {
        $('#sja-save-msg').text(txt)
            .attr('class', ok ? 'sja-msg-ok' : 'sja-msg-err')
            .show();
        if (ok) setTimeout(function() { $('#sja-save-msg').fadeOut(); }, 3000);
    }

    /* ── Vue tableau : sauvegarde à la saisie ── */
    $(document).on('change', '.sja-note-input', function() {
        var $inp       = $(this);
        var eleve_id   = $inp.data('eleve');
        var epreuve_id = $inp.data('epreuve');
        var seuil      = parseFloat($inp.data('seuil')) || SEUIL;
        var note_val   = parseFloat($inp.val());
        if (isNaN(note_val)) return;
        note_val = Math.max(NOTE_MIN, Math.min(NOTE_MAX, note_val));
        $inp.val(note_val);
        $('#sja-saisie-msg').css('color', '#9ca3af').text('Sauvegarde…');
        $.post(AJAXURL, {
            action:     'sp_jury_save_note_admin',
            nonce:      NONCE,
            event_id:   EVENT_ID,
            eleve_id:   eleve_id,
            epreuve_id: epreuve_id,
            note_val:   note_val,
        }, function(r) {
            if (r.success) {
                var score = 0; var has = true;
                $('#sja-row-' + eleve_id + ' .sja-note-input').each(function() {
                    var v = parseFloat($(this).val());
                    if (isNaN(v)) { has = false; } else { score += v; }
                });
                if (has) {
                    $('#sja-score-' + eleve_id).text(score.toFixed(1));
                    if (score >= seuil) {
                        $('#sja-verdict-' + eleve_id).html('<span style="color:#15803d;">✅ Admis</span>');
                        $('#sja-row-' + eleve_id).css('background', '#f0fdf4');
                    } else {
                        $('#sja-verdict-' + eleve_id).html('<span style="color:#b91c1c;">❌ Ajourné</span>');
                        $('#sja-row-' + eleve_id).css('background', '#fef2f2');
                    }
                }
                $('#sja-saisie-msg').css('color', '#16a34a').text('✓ Sauvegardé');
                setTimeout(function() {
                    $('#sja-saisie-msg').css('color', '#9ca3af').text('Les notes sont sauvegardées à la saisie.');
                }, 2000);
            } else {
                $('#sja-saisie-msg').css('color', '#b91c1c').text('✗ Erreur');
            }
        });
    });

})(jQuery);
</script>
            <?php elseif ($step===6): /* ── ÉTAPE 6 : Transcription ── */ ?>
															<h2>📝 Transcription des grades</h2>
            <?php if ($statut !== 'termine'): ?>
															<div style="background:#fef9c3;border:1px solid #fbbf24;border-radius:8px;padding:14px 18px;font-size:14px;color:#854d0e;">
                ⚠️ La session doit être <strong>terminée</strong> avant de transcrire les grades.
                <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
																	<a class="sja-btn sja-btn-red" href="<?php echo esc_url($base_url.'&step=4'); ?>">🔴 Terminer l'examen → Step 4</a>
																</div>
															</div>
            <?php else: ?>
															<p style="font-size:13px;color:#64748b;margin-bottom:16px;">
                Prévisualisez les grades à écrire, saisissez les éventuels overrides (cas exceptionnels), puis validez. Un snapshot SQL est généré automatiquement avant toute écriture.
            </p>
															<!-- Override grades -->
															<div id="sja-transcription-wrap">
																<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
																	<button class="sja-btn sja-btn-primary" id="sja-preview-grades">🔍 Prévisualiser</button>
																	<span style="font-size:12px;color:#64748b;">Vérifiez les grades avant d'écrire. Les overrides sont pris en compte dans la prévisualisation.</span>
																</div>
																<div id="sja-preview-result"/>
															</div>
            <?php endif; ?>
<script>
var spJury6 = {
    ajaxurl: '<?php echo admin_url('admin-ajax.php'); ?>',
    nonce:   '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',
    eid:     <?php echo intval($event_id); ?>
};
</script>
<script src="<?php echo SP_CAL_PRO_URL; ?>assets/js/jury-step6.js?v=<?php echo SP_CAL_PRO_VERSION; ?>"></script>
            <?php elseif ($step===7): /* ── ÉTAPE 7 : Grades ── */ ?>
															<h2>🎯 Progression des grades</h2>
															<p style="font-size:13px;color:#64748b;margin-bottom:12px;">Définissez ici la progression des grades de votre club : pour chaque grade actuel, quel grade l'élève obtient s'il est admis. Ce calcul est <strong>individuel</strong> — chaque élève se voit proposer le grade suivant le sien, pas un objectif commun.</p>

            <?php
            // Charger tous les grades distincts des élèves actifs pour vérification
            $tel = $this->db->table_eleves();
            $grades_eleves_raw = $wpdb->get_results(
                "SELECT grade, COUNT(*) as nb FROM $tel WHERE actif=1 AND grade!='' GROUP BY grade ORDER BY grade ASC"
            );
            // Index [grade] => nb_eleves
            $grade_count = array();
            foreach ($grades_eleves_raw as $g) $grade_count[$g->grade] = intval($g->nb);
            // Index des grades mappés
            $grades_mappes = array_column((array)$grad_prog, 'grade_actuel');
            // Grades présents chez des élèves mais absent du mapping
            $grades_sans_mapping = array();
            foreach ($grade_count as $g => $nb) {
                if (!in_array($g, $grades_mappes)) $grades_sans_mapping[$g] = $nb;
            }
            ?>

            <?php if (!empty($grades_sans_mapping)):
                // Charger les noms des élèves par grade pour l'affichage
                $eleves_par_grade = array();
                foreach (array_keys($grades_sans_mapping) as $g) {
                    $noms = $wpdb->get_results( $wpdb->prepare(
                        "SELECT prenom, nom FROM $tel WHERE actif=1 AND grade=%s ORDER BY nom ASC", $g
                    ) );
                    $eleves_par_grade[$g] = $noms;
                }
            ?>
			<script>
(function($){

    // Ajouter une ligne de progression
    window.sgpAddRow = function(grade) {
        $('#sja-prog-body').append(
            '<tr>'
            + '<td><input type="text" class="sgp-actuel" value="' + (grade||'') + '" placeholder="Grade actuel" style="width:140px;border:1px solid #d1d5db;border-radius:5px;padding:4px 8px;"></td>'
            + '<td><input type="text" class="sgp-suivant" value="" placeholder="Grade visé" style="width:140px;border:1px solid #d1d5db;border-radius:5px;padding:4px 8px;"></td>'
            + '<td style="text-align:center;"><span style="color:#9ca3af;font-size:11px;">—</span></td>'
            + '<td><button class="sja-btn sja-btn-red sja-btn-sm sgp-del">✕</button></td>'
            + '</tr>'
        );
        $('#sja-prog-body tr:last td:first input').focus();
    };

    $(document).on('click', '#sgp-add', function () { window.sgpAddRow(''); });

    $(document).on('click', '.sgp-del', function () {
        $(this).closest('tr').remove();
    });

    $(document).on('click', '#sgp-save', function () {
        var rows = [];
        $('#sja-prog-body tr').each(function () {
            var ga = $.trim($(this).find('.sgp-actuel').val());
            var gs = $.trim($(this).find('.sgp-suivant').val());
            if (ga && gs) rows.push({ grade_actuel: ga, grade_suivant: gs });
        });
        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action:   'sp_jury_save_grade_progression',
            nonce:    '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',
            event_id: <?php echo intval($event_id); ?>,
            rows:     rows
        }, function (r) {
            $('#sgp-msg').text(r.success ? '✓ Sauvegardé' : '✗ Erreur')
                .css('color', r.success ? '#16a34a' : '#dc2626');
        });
    });

    // Ressources pédagogiques
    function sgcMakeLienRow(l) {
        l = l || {};
        return '<div class="sgc-lien-row" style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap;">'
            + '<select class="sgc-lien-type" style="border:1px solid #d1d5db;border-radius:5px;padding:4px 6px;font-size:12px;">'
            + '<option value="lien"'  + (l.type==='lien'  ? ' selected':'') + '>🔗 Lien</option>'
            + '<option value="video"' + (l.type==='video' ? ' selected':'') + '>🎥 Vidéo</option>'
            + '<option value="image"' + (l.type==='image' ? ' selected':'') + '>🖼️ Image</option>'
            + '<option value="pdf"'   + (l.type==='pdf'   ? ' selected':'') + '>📄 PDF</option>'
            + '</select>'
            + '<input type="text" class="sgc-lien-label" placeholder="Libellé" value="' + (l.label||'') + '" style="width:140px;border:1px solid #d1d5db;border-radius:5px;padding:4px 8px;font-size:12px;">'
            + '<input type="text" class="sgc-lien-url" placeholder="URL" value="' + (l.url||'') + '" style="flex:1;min-width:200px;border:1px solid #d1d5db;border-radius:5px;padding:4px 8px;font-size:12px;">'
            + '<button class="sja-btn sja-btn-red sja-btn-sm sgc-del-lien">✕</button>'
            + '</div>';
    }

    $('#sgc-add-lien').on('click', function () {
        $('#sgc-liens-wrap').append(sgcMakeLienRow());
    });

    $(document).on('click', '.sgc-del-lien', function () {
        $(this).closest('.sgc-lien-row').remove();
    });

    $(document).on('click', '#sgc-load', function () {
        var grade = $('#sgc-grade-sel').val();
        if (!grade) { alert('Sélectionnez un grade.'); return; }
        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action:       'sp_jury_get_grade_contenu',
            nonce:        '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',
            grade_actuel: grade
        }, function (r) {
            if (!r.success) return;
            var d = r.data;
            $('#sgc-desc').val(d.description || '');
            $('#sgc-liens-wrap').empty();
            (d.liens || []).forEach(function (l) {
                $('#sgc-liens-wrap').append(sgcMakeLienRow(l));
            });
            $('#sgc-editor').show();
            $('#sgc-msg').text('');
        });
    });

    $(document).on('click', '#sgc-save', function () {
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
        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action:       'sp_jury_save_grade_contenu',
            nonce:        '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',
            grade_actuel: grade,
            description:  $('#sgc-desc').val(),
            liens:        liens,
        }, function (r) {
            $('#sgc-msg').text(r.success ? '✓ Sauvegardé' : '✗ Erreur')
                .css('color', r.success ? '#16a34a' : '#dc2626');
        });
    });

})(jQuery);
</script>
															<div style="background:#fef9c3;border:1px solid #fbbf24;border-radius:8px;padding:14px 18px;margin-bottom:14px;font-size:13px;color:#854d0e;">
																<strong>⚠️ <?php echo count($grades_sans_mapping); ?> grade(s) non encore associé(s) à un grade suivant :</strong>
																<p style="margin:4px 0 10px;font-size:12px;">Les élèves ci-dessous ont un grade en fiche, mais ce grade n'a pas encore de "grade suivant" défini dans le tableau. Cliquez sur un grade pour l'ajouter automatiquement.</p>
																<div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach ($grades_sans_mapping as $g => $nb):
                    $noms_el = $eleves_par_grade[$g] ?? array();
                    $noms_str = implode(', ', array_map(function($e){ return $e->prenom.' '.mb_strtoupper($e->nom); }, $noms_el));
                ?>
																	<div style="background:#fff;border:1px solid #fbbf24;border-radius:8px;padding:8px 12px;cursor:pointer;max-width:300px;" onclick="sgpAddRow('<?php echo esc_js($g); ?>')">
																		<div style="font-weight:700;font-size:13px;margin-bottom:3px;">
                        <?php echo esc_html($g); ?>
																			<span style="font-size:11px;color:#9ca3af;font-weight:400;">(<?php echo $nb; ?> élève<?php echo $nb>1?'s':''; ?>)</span>
																		</div>
                    <?php if ($noms_str): ?>
																		<div style="font-size:11px;color:#78350f;"><?php echo esc_html($noms_str); ?>
																		</div>
                    <?php endif; ?>
																		<div style="font-size:10px;color:#2563eb;margin-top:4px;">+ Cliquer pour ajouter au tableau</div>
																	</div>
                <?php endforeach; ?>
																</div>
															</div>
            <?php endif; ?>
															<div style="display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start;">
																<div>
																	<table class="sja-prog-tbl">
																		<thead>
																			<tr>
																				<th>Grade actuel de l'élève</th>
																				<th>Grade obtenu si admis</th>
																				<th style="width:80px;text-align:center;">Élèves concernés</th>
																				<th/>
																			</tr>
																		</thead>
																		<tbody id="sja-prog-body">
                <?php foreach ($grad_prog as $row):
                    $nb_el = $grade_count[$row->grade_actuel] ?? 0;
                    $match = $nb_el > 0;
                ?>
																			<tr>
																				<td>
																					<input type="text" class="sgp-actuel" value="<?php echo esc_attr($row->grade_actuel); ?>" style="width:140px;border:1px solid #d1d5db;border-radius:5px;padding:4px 8px;<?php echo $match?'border-color:#86efac;':'' ?>">
                    </td>
																					<td>
																						<input type="text" class="sgp-suivant" value="<?php echo esc_attr($row->grade_suivant); ?>" style="width:140px;border:1px solid #d1d5db;border-radius:5px;padding:4px 8px;">
                    </td>
																						<td style="text-align:center;">
                        <?php if ($match): ?>
																							<span style="background:#dcfce7;color:#16a34a;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;"><?php echo $nb_el; ?>
																							</span>
                        <?php else: ?>
																							<span style="color:#9ca3af;font-size:11px;">0</span>
                        <?php endif; ?>
																						</td>
																						<td>
																							<button class="sja-btn sja-btn-red sja-btn-sm sgp-del">✕</button>
																						</td>
																					</tr>
                <?php endforeach; ?>
																				</tbody>
																			</table>
																			<div style="display:flex;gap:10px;margin-top:10px;align-items:center;">
																				<button class="sja-btn sja-btn-gray sja-btn-sm" id="sgp-add">+ Ajouter ligne</button>
																				<button class="sja-btn sja-btn-primary" id="sgp-save">💾 Sauvegarder</button>
																				<span class="sja-msg" id="sgp-msg"/>
																			</div>
																		</div>
																		<!-- Légende -->
																		<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:14px;font-size:12px;min-width:180px;">
																			<div style="font-weight:700;margin-bottom:10px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;">Légende</div>
																			<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
																				<span style="background:#dcfce7;color:#16a34a;padding:1px 8px;border-radius:8px;font-size:11px;font-weight:700;">N</span>
																				<span style="color:#374151;">N élève(s) actuellement à ce grade</span>
																			</div>
																			<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
																				<span style="color:#9ca3af;font-size:11px;">0</span>
																				<span style="color:#374151;">Aucun élève à ce grade en ce moment</span>
																			</div>
																			<div style="display:flex;align-items:center;gap:6px;">
																				<span style="display:inline-block;width:14px;height:14px;background:transparent;border:1px solid #86efac;border-radius:2px;"/>
																				<span style="color:#374151;">Encadré vert = des élèves ont ce grade</span>
																			</div>
																			<hr style="margin:10px 0;border:none;border-top:1px solid #e5e7eb;">
																				<div style="color:#64748b;">
                    Le nom du grade doit être écrit <strong>exactement comme sur la fiche de l'élève</strong> — même majuscules, même symboles.
                    <br>
																						<br>Exemple : si la fiche dit <code>8° jaune *</code>, écrire <code>8° jaune *</code>, pas <code>8° jaune</code>.
                </div>
																					</div>
																				</div>
            <?php endif; ?>
																				<!-- Ressources pédagogiques par grade -->
																				<div style="margin-top:24px;padding-top:20px;border-top:1px solid #e5e7eb;">
																					<h3 style="font-size:15px;font-weight:700;margin:0 0 6px;">📚 Ressources pédagogiques par grade</h3>
																					<p style="font-size:13px;color:#64748b;margin:0 0 16px;">Pour chaque grade, définissez une description et des liens (vidéos, PDF, images). Ces contenus s'affichent dans le modal du Chemin de ceinture quand l'élève ou le parent clique sur un cercle.</p>
																					<div style="display:flex;gap:10px;margin-bottom:14px;align-items:center;flex-wrap:wrap;">
																						<select id="sgc-grade-sel" style="border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;font-size:13px;min-width:200px;">
																							<option value="">— Sélectionner un grade —</option>
                        <?php
                        $current_cat = null;
                        foreach ($grad_prog as $row):
                            if ($row->categorie_age !== $current_cat):
                                if ($current_cat !== null) echo '</optgroup>';
                                $current_cat = $row->categorie_age;
                                $cat_label = $current_cat ?: 'Sans catégorie';
                                echo '<optgroup label="' . esc_attr($cat_label) . '">';
                            endif;
                        ?>
																							<option value="<?php echo esc_attr($row->grade_actuel); ?>"><?php echo esc_html($row->grade_actuel); ?>
																							</option>
                        <?php endforeach; ?>
                        <?php if ($current_cat !== null) echo '</optgroup>'; ?>
																						</select>
																						<button class="sja-btn sja-btn-primary sja-btn-sm" id="sgc-load">Charger</button>
																						<span class="sja-msg" id="sgc-msg"/>
																					</div>
																					<div id="sgc-editor" style="display:none;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:16px;">
																						<div class="sja-field" style="margin-bottom:14px;">
																							<label>Description (texte libre, HTML autorisé)</label>
																							<textarea id="sgc-desc" rows="4" style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:8px;font-size:13px;resize:vertical;"/>
																						</div>
																						<div style="font-weight:600;font-size:13px;margin-bottom:8px;">🔗 Liens et ressources</div>
																						<div id="sgc-liens-wrap" style="margin-bottom:10px;"/>
																						<button class="sja-btn sja-btn-gray sja-btn-sm" id="sgc-add-lien">+ Ajouter un lien</button>
																						<div style="margin-top:14px;">
																							<button class="sja-btn sja-btn-primary" id="sgc-save">💾 Sauvegarder ce grade</button>
																						</div>
																					</div>
																				</div>
																			</div>
																			<!-- /sja-content -->
																		</div>
																		<!-- /sja-layout -->
        <?php endif; // event_id ?>
																	</div>
																	<!-- /wrap -->

        <?php
    }

    /* ══════════════════════════════════════════════════════════
       RENDER JURY SAISIE MOBILE
    ══════════════════════════════════════════════════════════ */

    private function render_jury_saisie_mobile(): void {

        // ── Validation des paramètres ─────────────────────────────────────
        $event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;
        $eleve_id = isset( $_GET['eleve_id'] ) ? (int) $_GET['eleve_id'] : 0;
        $token    = isset( $_GET['token'] )    ? sanitize_text_field( $_GET['token'] ) : '';

        if ( ! $event_id || ! $eleve_id ) {
            wp_die( 'Paramètres manquants.', 'Erreur', array( 'response' => 400 ) );
        }

        // Token HMAC statique — ne depend pas de la session, ne expire jamais
        $token_attendu = substr( hash_hmac( 'sha256', 'jury_qr|' . $event_id . '|' . $eleve_id, wp_salt('auth') ), 0, 32 );
        if ( ! hash_equals( $token_attendu, $token ) ) {
            wp_die( 'Token invalide. Regenerez les fiches jury.', 'Securite', array( 'response' => 403 ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Acces reserve aux administrateurs.', 'Acces refuse', array( 'response' => 403 ) );
        }

        // ── Récupération des données ──────────────────────────────────────
        global $wpdb;
        $tel   = $this->db->table_eleves();
        $te    = $this->db->table_events();

        $eleve = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $tel WHERE id = %d LIMIT 1",
            $eleve_id
        ) );
        if ( ! $eleve ) {
            wp_die( 'Candidat introuvable.', 'Erreur', array( 'response' => 404 ) );
        }

        $event    = $wpdb->get_row( $wpdb->prepare( "SELECT titre FROM $te WHERE id = %d LIMIT 1", $event_id ) );
        $epreuves = $this->db->get_exam_epreuves( null, true );
        $session  = $this->db->get_jury_session( $event_id );
        $note_min = (float) ( $session->note_min ?? 0 );
        $note_max = (float) ( $session->note_max ?? 20 );

        // Notes existantes pour pré-remplir le formulaire
        $notes_existantes = array();
        $pfx = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT epreuve_id, note FROM {$pfx}sp_jury_notes WHERE event_id = %d AND eleve_id = %d",
            $event_id, $eleve_id
        ) );
        foreach ( $rows as $r ) {
            $notes_existantes[ (int) $r->epreuve_id ] = $r->note;
        }

        // ── Document HTML autonome optimisé mobile ────────────────────────
        ?>
																	<!DOCTYPE html>
																	<html lang="fr">
																		<head>
																			<meta charset="UTF-8">
																				<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
																					<title>Saisie jury — <?php echo esc_html( $eleve->prenom . ' ' . mb_strtoupper( $eleve->nom ) ); ?>
																					</title>
																					<style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
               background: #f0f2f5; min-height: 100vh; padding-bottom: 2rem; }
        .sp-header { background: #1e3a5f; color: #fff;
                     padding: 1rem 1.25rem; position: sticky; top: 0; z-index: 10; }
        .sp-header h1 { font-size: 1.05rem; font-weight: 700; }
        .sp-header p  { font-size: 0.8rem; opacity: .75; margin-top: .2rem; }
        .sp-card { background: #fff; margin: 1rem .75rem .5rem;
                   border-radius: 12px; padding: 1rem 1.25rem;
                   box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .sp-card h2 { font-size: .8rem; color: #666; text-transform: uppercase;
                      letter-spacing: .05em; margin-bottom: .75rem; }
        .sp-epreuve { display: flex; align-items: center; justify-content: space-between;
                      padding: .65rem 0; border-bottom: 1px solid #f0f0f0; }
        .sp-epreuve:last-child { border-bottom: none; }
        .sp-epreuve label { font-size: .95rem; font-weight: 500; flex: 1; }
        .sp-epreuve input[type=number] {
            width: 78px; padding: .5rem .6rem; text-align: center;
            border: 2px solid #e0e0e0; border-radius: 8px; font-size: 1rem;
            transition: border-color .2s; -moz-appearance: textfield; }
        .sp-epreuve input[type=number]::-webkit-inner-spin-button,
        .sp-epreuve input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; }
        .sp-epreuve input[type=number]:focus { outline: none; border-color: #1e3a5f; }
        .sp-btn { display: block; width: calc(100% - 1.5rem); margin: 1.25rem .75rem 0;
                  padding: 1rem; background: #1e3a5f; color: #fff; border: none;
                  border-radius: 12px; font-size: 1rem; font-weight: 700;
                  cursor: pointer; transition: background .2s; }
        .sp-btn:active  { background: #0d1f36; }
        .sp-btn:disabled { background: #9ca3af; cursor: not-allowed; }
        #sp-feedback { margin: .75rem .75rem 0; padding: .85rem 1rem;
                       border-radius: 10px; font-size: .9rem; display: none; }
        #sp-feedback.ok  { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; display: block; }
        #sp-feedback.err { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; display: block; }
    </style>
																				</head>
																				<body>
																					<div class="sp-header">
																						<h1><?php echo esc_html( $eleve->prenom . ' ' . mb_strtoupper( $eleve->nom ) ); ?>
																						</h1>
																						<p>
        <?php echo $event ? esc_html( $event->titre ) : 'Examen #' . $event_id; ?>
        &nbsp;·&nbsp;
        <?php echo esc_html( $eleve->grade ?? '—' ); ?>
																						</p>
																					</div>
																					<div class="sp-card">
																						<h2>Notes (<?php echo $note_min; ?> – <?php echo $note_max; ?>)</h2>
    <?php foreach ( $epreuves as $ep ) : ?>
																						<div class="sp-epreuve">
																							<label for="note_<?php echo (int) $ep->id; ?>"><?php echo esc_html( $ep->nom ); ?>
																							</label>
																							<input type="number" id="note_<?php echo (int) $ep->id; ?>" data-epreuve="<?php echo (int) $ep->id; ?>" min="<?php echo $note_min; ?>" max="<?php echo $note_max; ?>" step="0.5" value="<?php echo isset( $notes_existantes[ (int) $ep->id ] )
                   ? esc_attr( $notes_existantes[ (int) $ep->id ] ) : ''; ?>" placeholder="—">
    </div>
    <?php endforeach; ?>
																						</div>
																						<div id="sp-feedback"/>
																						<button class="sp-btn" id="sp-save" type="button">Enregistrer les notes</button>
																						<script>
(function () {
    var btn      = document.getElementById('sp-save');
    var feedback = document.getElementById('sp-feedback');
    var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
    var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'sp_jury_save_notes_mobile' ) ); ?>;
    var eventId  = <?php echo (int) $event_id; ?>;
    var eleveId  = <?php echo (int) $eleve_id; ?>;

    btn.addEventListener('click', function () {
        var inputs = document.querySelectorAll('input[data-epreuve]');
        var notes  = [];
        inputs.forEach(function (inp) {
            var v = inp.value.trim();
            if (v !== '') {
                notes.push({ epreuve_id: parseInt(inp.dataset.epreuve, 10), note: parseFloat(v) });
            }
        });
        if (!notes.length) {
            feedback.textContent = 'Saisissez au moins une note.';
            feedback.className = 'err';
            return;
        }
        btn.disabled = true;
        btn.textContent = 'Enregistrement…';
        feedback.className = '';
        feedback.style.display = 'none';

        var fd = new FormData();
        fd.append('action',   'sp_jury_save_notes_mobile');
        fd.append('nonce',    nonce);
        fd.append('event_id', eventId);
        fd.append('eleve_id', eleveId);
        fd.append('notes',    JSON.stringify(notes));

        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    feedback.textContent = '✅ Notes enregistrées avec succès.';
                    feedback.className = 'ok';
                    btn.textContent = 'Enregistré ✓';
                } else {
                    feedback.textContent = 'Erreur : ' + (res.data || 'inconnue');
                    feedback.className = 'err';
                    btn.disabled = false;
                    btn.textContent = 'Enregistrer les notes';
                }
            })
            .catch(function () {
                feedback.textContent = 'Erreur réseau. Réessayez.';
                feedback.className = 'err';
                btn.disabled = false;
                btn.textContent = 'Enregistrer les notes';
            });
    });
}());
</script>
																					</body>
																				</html>
        <?php
        exit;
    }

    /* ══════════════════════════════════════════════════════════
       AJAX JURY SAVE NOTES MOBILE
    ══════════════════════════════════════════════════════════ */

    public function ajax_jury_save_notes_mobile(): void {

        if ( ! check_ajax_referer( 'sp_jury_save_notes_mobile', 'nonce', false ) ) {
            wp_send_json_error( 'Nonce invalide', 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Accès refusé', 403 );
        }

        $event_id  = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;
        $eleve_id  = isset( $_POST['eleve_id'] ) ? (int) $_POST['eleve_id'] : 0;
        $notes_raw = isset( $_POST['notes'] )    ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '';

        if ( ! $event_id || ! $eleve_id || ! $notes_raw ) {
            wp_send_json_error( 'Données manquantes' );
        }

        $notes = json_decode( $notes_raw, true );
        if ( ! is_array( $notes ) || empty( $notes ) ) {
            wp_send_json_error( 'Format de notes invalide' );
        }

        $session  = $this->db->get_jury_session( $event_id );
        $note_min = (float) ( $session->note_min ?? 0 );
        $note_max = (float) ( $session->note_max ?? 20 );

        global $wpdb;
        $table = $wpdb->prefix . 'sp_jury_notes';

        foreach ( $notes as $entry ) {
            $epreuve_id = isset( $entry['epreuve_id'] ) ? (int)   $entry['epreuve_id'] : 0;
            $note       = isset( $entry['note'] )       ? (float) $entry['note']       : null;

            if ( ! $epreuve_id || $note === null || $note < $note_min || $note > $note_max ) {
                continue;
            }

            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table WHERE event_id = %d AND eleve_id = %d AND epreuve_id = %d LIMIT 1",
                $event_id, $eleve_id, $epreuve_id
            ) );

            if ( $existing ) {
                $wpdb->update(
                    $table,
                    array( 'note' => $note, 'updated_at' => current_time( 'mysql' ) ),
                    array( 'id'   => (int) $existing ),
                    array( '%f', '%s' ),
                    array( '%d' )
                );
            } else {
                $wpdb->insert(
                    $table,
                    array(
                        'event_id'   => $event_id,
                        'eleve_id'   => $eleve_id,
                        'epreuve_id' => $epreuve_id,
                        'note'       => $note,
                        'created_at' => current_time( 'mysql' ),
                        'updated_at' => current_time( 'mysql' ),
                    ),
                    array( '%d', '%d', '%d', '%f', '%s', '%s' )
                );
            }
        }

        wp_send_json_success( 'Notes enregistrées' );
    }

    /* ══════════════════════════════════════════════════════════
       PAGE JURY GUIDE
    ══════════════════════════════════════════════════════════ */

    public function page_jury_guide() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        $guide_path = SP_CAL_PRO_PATH . 'templates/jury-guide.php';
        if ( file_exists( $guide_path ) ) {
            echo '<div class="wrap" style="max-width:900px;">';
            include $guide_path;
            echo '</div>';
        } else {
            echo '<div class="wrap"><div class="notice notice-error"><p>Fichier guide introuvable : ' . esc_html( $guide_path ) . '</p></div></div>';
        }
    }


}

endif;

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_PDF' ) ) :

class SpCalPro_PDF {

    private $db;

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;
        add_action( 'init', array( $this, 'handle_print_request' ) );
        // Fiches jury A5 et export CSV — accessibles via admin-ajax.php
        add_action( 'wp_ajax_sp_cal_print_jury_fiches', array( $this, 'handle_jury_fiches' ) );
        add_action( 'wp_ajax_sp_cal_print_jury_csv',    array( $this, 'handle_jury_csv' ) );
		add_action( 'wp_ajax_sp_cal_print_jury_fiches_resultats', array( $this, 'handle_jury_fiches_resultats' ) );
    }

    public function handle_print_request() {
        if ( ! isset( $_GET['sp_cal_print'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'sp_cal_print' ) ) wp_die( 'Nonce invalide' );

        $type = sanitize_text_field( $_GET['sp_cal_print'] );

        if ( $type === 'liste_appel' ) {
            $this->render_liste_appel();
        } elseif ( $type === 'fiche_eleve' ) {
            $this->render_fiche_eleve( intval( $_GET['eleve_id'] ?? 0 ) );
        } elseif ( $type === 'liste_groupe' ) {
            $this->render_liste_groupe();
        }
        exit;
    }
// v2
    /* ══════════════════════════════════════════════════════════
       STYLES COMMUNS IMPRESSION
    ══════════════════════════════════════════════════════════ */

    private function print_head( $title ) {
        $club = get_option('blogname', 'Club');
        ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?php echo esc_html($title . ' — ' . $club); ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 11pt; color: #111; background: #fff; }

/* ── En-tête ── */
.print-header {
    display: flex; align-items: flex-end; justify-content: space-between;
    border-bottom: 2px solid #1e3a5f; padding-bottom: 8px; margin-bottom: 16px;
}
.print-header-left h1 { font-size: 16pt; color: #1e3a5f; }
.print-header-left p  { font-size: 9pt; color: #666; margin-top: 2px; }
.print-header-right   { font-size: 9pt; color: #666; text-align: right; }

/* ── Tableau liste d'appel ── */
.attendance-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
.attendance-table th {
    background: #1e3a5f; color: #fff;
    padding: 6px 8px; font-size: 9pt; text-align: left;
    border: 1px solid #1e3a5f;
}
.attendance-table td {
    padding: 5px 8px; border: 1px solid #ccc; font-size: 10pt; vertical-align: middle;
}
.attendance-table tr:nth-child(even) td { background: #f7f9fc; }
.attendance-table .check-col { width: 24px; text-align: center; }
.attendance-table .num-col   { width: 30px; text-align: center; color: #888; font-size: 9pt; }
.check-box {
    display: inline-block; width: 16px; height: 16px;
    border: 1.5px solid #555; border-radius: 3px;
}

/* ── Badge catégorie ── */
.cat-badge {
    display: inline-block; padding: 1px 6px; border-radius: 3px;
    font-size: 8pt; font-weight: 700; background: #e8f0fe; color: #1a56db;
}
.cat-badge-saisie { background: #ede9fe; color: #6d28d9; }

/* ── Section groupe ── */
.group-section { margin-bottom: 24px; }
.group-title {
    background: #1e3a5f; color: #fff;
    padding: 6px 12px; font-size: 12pt; font-weight: 700;
    margin-bottom: 0; border-radius: 4px 4px 0 0;
}
.group-subtitle { font-size: 9pt; font-weight: 400; opacity: .8; margin-left: 8px; }

/* ── Fiche élève ── */
.fiche-grid   { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.fiche-box    { border: 1px solid #ccc; border-radius: 4px; padding: 10px 12px; }
.fiche-box h3 { font-size: 10pt; color: #1e3a5f; border-bottom: 1px solid #eee; padding-bottom: 4px; margin-bottom: 8px; }
.fiche-row    { display: flex; gap: 8px; margin-bottom: 4px; font-size: 10pt; }
.fiche-label  { min-width: 120px; color: #666; font-size: 9pt; }
.fiche-val    { font-weight: 600; }
.fiche-avatar {
    width: 48px; height: 48px; border-radius: 50%;
    background: #1e3a5f; color: #fff;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 16pt; font-weight: 800; margin-right: 12px; float: left;
}
.grade-table  { width: 100%; border-collapse: collapse; font-size: 9pt; }
.grade-table th { background: #f0f4f8; padding: 4px 6px; text-align: left; border: 1px solid #ddd; }
.grade-table td { padding: 4px 6px; border: 1px solid #ddd; }
.grade-current td { background: #f0fdf4; font-weight: 700; }
.status-ok      { color: #15803d; font-weight: 700; }
.status-inactif { color: #b91c1c; font-weight: 700; }

/* ── Pied de page ── */
.print-footer {
    border-top: 1px solid #ccc; margin-top: 20px; padding-top: 6px;
    font-size: 8pt; color: #888; display: flex; justify-content: space-between;
}

/* ── Contrôles (masqués à l'impression) ── */
.no-print { margin-bottom: 16px; }
.btn-print {
    background: #1e3a5f; color: #fff; border: none; padding: 8px 18px;
    border-radius: 4px; cursor: pointer; font-size: 11pt; margin-right: 8px;
}
.btn-close { background: #eee; color: #333; border: 1px solid #ccc; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-size: 11pt; }

@media print {
    .no-print { display: none !important; }
    body { font-size: 10pt; }
    .group-section { page-break-inside: avoid; }
    .fiche-grid { page-break-inside: avoid; }
}
</style>
</head>
<body>
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨️ Imprimer / Enregistrer en PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Fermer</button>
</div>
        <?php
    }

    private function print_footer( $info = '' ) {
        $club = get_option('blogname', 'Club');
        echo '<div class="print-footer">';
        echo '<span>' . esc_html($club) . ' — ' . esc_html($info) . '</span>';
        echo '<span>Imprimé le ' . date('d/m/Y') . '</span>';
        echo '</div></body></html>';
    }

    /* ══════════════════════════════════════════════════════════
       LISTE D'APPEL (tous les groupes ou filtré)
    ══════════════════════════════════════════════════════════ */

    private function render_liste_appel() {
        $saison        = sanitize_text_field( $_GET['saison']   ?? '' );
        $cat_saisie    = sanitize_text_field( $_GET['saisie']   ?? '' );
        $cat_age       = sanitize_text_field( $_GET['cat_age']  ?? '' );
        $date_cours    = sanitize_text_field( $_GET['date']     ?? date('d/m/Y') );

        $args = array();
        if ($saison)     $args['saison']           = $saison;
        if ($cat_saisie) $args['categorie_saisie'] = $cat_saisie;
        if ($cat_age)    $args['categorie_age']     = $cat_age;
        $eleves = $this->db->get_eleves($args);

        // Filtrer uniquement les actifs
        $eleves = array_filter($eleves, function($e){ return intval($e->actif ?? 1); });

        // Grouper par catégorie d'âge
        $grouped = array();
        foreach ($eleves as $el) {
            $cat = $el->categorie_age ?: 'Sans catégorie';
            $grouped[$cat][] = $el;
        }
        ksort($grouped);

        $title = 'Liste d\'appel' . ($date_cours ? ' — ' . $date_cours : '');
        $this->print_head($title);
        ?>
        <div class="print-header">
            <div class="print-header-left">
                <h1>📋 Liste d'appel</h1>
                <p>
                    <?php echo esc_html($date_cours); ?>
                    <?php if ($cat_saisie) echo ' &mdash; ' . esc_html($cat_saisie); ?>
                    <?php if ($saison)     echo ' &mdash; Saison ' . esc_html($saison); ?>
                </p>
            </div>
            <div class="print-header-right">
                <?php echo esc_html(get_option('blogname','Club')); ?><br>
                <?php echo count($eleves); ?> élève(s) actif(s)
            </div>
        </div>
        <?php foreach ($grouped as $cat => $membres) : ?>
        <div class="group-section">
            <div class="group-title">
                <?php echo esc_html($cat); ?>
                <span class="group-subtitle">(<?php echo count($membres); ?> élèves)</span>
            </div>
            <table class="attendance-table">
                <thead><tr>
                    <th class="num-col">#</th>
                    <th>Nom &amp; Prénom</th>
                    <th>Grade</th>
                    <th>Naissance</th>
                    <th class="check-col">P</th>
                    <th class="check-col">A</th>
                    <th style="width:80px;">Note</th>
                </tr></thead>
                <tbody>
                <?php foreach (array_values($membres) as $i => $el) : ?>
                <tr>
                    <td class="num-col"><?php echo $i+1; ?></td>
                    <td>
                        <strong><?php echo esc_html(mb_strtoupper($el->nom) . ' ' . $el->prenom); ?></strong>
                        <?php if ($el->categorie_saisie): ?>
                            <span class="cat-badge-saisie"><?php echo esc_html($el->categorie_saisie); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($el->grade); ?></td>
                    <td style="font-size:9pt;color:#555;"><?php
                        echo esc_html($el->date_naissance . ($el->annee_naissance ? '/' . $el->annee_naissance : ''));
                    ?></td>
                    <td class="check-col"><span class="check-box"></span></td>
                    <td class="check-col"><span class="check-box"></span></td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
        <?php $this->print_footer('Liste d\'appel — ' . $date_cours); ?>
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       FICHE INDIVIDUELLE IMPRIMABLE
    ══════════════════════════════════════════════════════════ */

    private function render_fiche_eleve( $eleve_id ) {
        if ( ! $eleve_id ) wp_die('ID manquant.');
        global $wpdb;
        $el = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->db->table_eleves()} WHERE id=%d", $eleve_id
        ) );
        if ( ! $el ) wp_die('Élève introuvable.');

        $extra       = $el->extra_data ? (json_decode($el->extra_data, true) ?: array()) : array();
        $grades_hist = $extra['grades'] ?? array();
        $examens     = $this->db->get_examens_eleve($eleve_id);
        $presences   = $this->db->get_presences_eleve($eleve_id);

        // Stats présences
        $pres_cours = array_filter($presences, function($p){ return $p->type !== 'examen'; });
        $nb_p = count(array_filter($pres_cours, function($p){ return intval($p->present); }));
        $nb_t = count($pres_cours);
        $taux = $nb_t ? round($nb_p / $nb_t * 100) : null;

        // Age
        $age = null;
        if ($el->annee_naissance && $el->date_naissance) {
            $pts = explode('/', $el->date_naissance);
            if (count($pts) === 2) {
                $bd = DateTime::createFromFormat('d/m/Y', $pts[0].'/'.$pts[1].'/'.$el->annee_naissance);
                if ($bd) $age = $bd->diff(new DateTime())->y;
            }
        }

        $fin_s  = get_option('sp_cal_fin_saison','');
        $alerte = intval(get_option('sp_cal_alerte_jours', 60));
        $statut_adhesion = 'Actif';
        $statut_class    = 'status-ok';
        if (!intval($el->actif ?? 1)) {
            $statut_adhesion = 'Inactif' . ($el->motif_inactif ? ' — ' . $el->motif_inactif : '');
            $statut_class    = 'status-inactif';
        } elseif ($fin_s) {
            $jr = intval(ceil((strtotime($fin_s) - time()) / 86400));
            if ($jr < 0)          $statut_adhesion = 'Actif — Saison expirée';
            elseif ($jr <= $alerte) $statut_adhesion = 'Actif — Fin de saison dans ' . $jr . ' jours';
        }

        $this->print_head('Fiche — ' . $el->prenom . ' ' . mb_strtoupper($el->nom));
        ?>
        <div class="print-header">
            <div class="print-header-left">
                <div style="display:flex;align-items:center;">
                    <div class="fiche-avatar"><?php echo esc_html(mb_strtoupper(mb_substr($el->prenom,0,1).mb_substr($el->nom,0,1))); ?></div>
                    <div>
                        <h1><?php echo esc_html($el->prenom . ' ' . mb_strtoupper($el->nom)); ?></h1>
                        <p>
                            <?php if($el->categorie_saisie) echo '<span class="cat-badge-saisie">'.esc_html($el->categorie_saisie).'</span> '; ?>
                            <?php if($el->categorie_age)    echo '<span class="cat-badge">'.esc_html($el->categorie_age).'</span> '; ?>
                            <?php if($el->saison)           echo esc_html($el->saison); ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="print-header-right">
                <?php if($taux !== null) echo 'Assiduité : <strong>' . $taux . '%</strong> (' . $nb_p . '/' . $nb_t . ')<br>'; ?>
                <span class="<?php echo $statut_class; ?>"><?php echo esc_html($statut_adhesion); ?></span>
            </div>
        </div>

        <div class="fiche-grid">
            <!-- Informations personnelles -->
            <div class="fiche-box">
                <h3>👤 Informations</h3>
                <?php
                $rows = array(
                    'Naissance'   => $el->date_naissance . ($el->annee_naissance ? '/' . $el->annee_naissance : '') . ($age ? ' (' . $age . ' ans)' : ''),
                    'Licence'     => $el->licence ?: '—',
                    'Téléphone'   => $el->telephone ?: '—',
                    'Email'       => $el->email ?: '—',
                    'Email parent'=> $el->email_parent ?: '—',
                );
                foreach ($rows as $lbl => $val) :
                    if (!$val || $val === '—') continue;
                ?>
                <div class="fiche-row">
                    <span class="fiche-label"><?php echo esc_html($lbl); ?></span>
                    <span class="fiche-val"><?php echo esc_html($val); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Grade actuel -->
            <div class="fiche-box">
                <h3>🥋 Grade actuel</h3>
                <?php if($el->grade): ?>
                <p style="font-size:14pt;font-weight:800;color:#1e3a5f;margin-bottom:8px;"><?php echo esc_html($el->grade); ?></p>
                <?php else: ?>
                <p style="color:#888;">Aucun grade renseigné.</p>
                <?php endif; ?>
                <?php if($el->palmares): ?>
                <h3 style="margin-top:8px;">🏆 Palmarès</h3>
                <p style="font-size:9pt;color:#444;white-space:pre-wrap;"><?php echo esc_html($el->palmares); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Historique grades -->
        <?php
        // Chronologie unifiée examens + CSV
        $timeline = array();
        foreach ($examens as $ex) {
            $timeline[] = array(
                'date_ts' => $ex->date ? strtotime($ex->date) : 0,
                'date_fmt'=> $ex->date ? date_create($ex->date)->format('d/m/Y') : '—',
                'source'  => 'Examen',
                'label'   => $ex->titre,
                'grade'   => $ex->note,
            );
        }
        foreach ($grades_hist as $d => $g) {
            $pts = explode('/', $d);
            $ts  = count($pts) === 3 ? mktime(0,0,0,intval($pts[1]),intval($pts[0]),intval($pts[2])) : 0;
            $timeline[] = array('date_ts'=>$ts, 'date_fmt'=>$d, 'source'=>'CSV', 'label'=>'Import CSV', 'grade'=>$g);
        }
        usort($timeline, function($a,$b){ return $b['date_ts'] - $a['date_ts']; });
        ?>
        <?php if (!empty($timeline)) : ?>
        <div class="fiche-box" style="margin-bottom:16px;">
            <h3>🎓 Passages de grade</h3>
            <table class="grade-table">
                <thead><tr><th>Date</th><th>Événement</th><th>Grade obtenu</th><th>Source</th></tr></thead>
                <tbody>
                <?php foreach ($timeline as $row) :
                    $is_current = ($row['grade'] === $el->grade && $row['grade']);
                ?>
                <tr class="<?php echo $is_current ? 'grade-current' : ''; ?>">
                    <td><?php echo esc_html($row['date_fmt']); ?></td>
                    <td><?php echo esc_html($row['label']); ?></td>
                    <td><?php echo esc_html($row['grade'] ?: '—'); ?><?php if($is_current) echo ' ★'; ?></td>
                    <td style="font-size:8pt;color:#888;"><?php echo esc_html($row['source']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- 10 dernières présences -->
        <?php if (!empty($pres_cours)) :
            $recent = array_slice(array_values($pres_cours), 0, 10);
        ?>
        <div class="fiche-box">
            <h3>📅 10 derniers cours</h3>
            <table class="grade-table">
                <thead><tr><th>Date</th><th>Cours</th><th style="text-align:center;">Présence</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $p) :
                    $d = $p->date ? date_create($p->date)->format('d/m/Y') : $p->date;
                ?>
                <tr>
                    <td><?php echo esc_html($d); ?></td>
                    <td><?php echo esc_html($p->titre); ?></td>
                    <td style="text-align:center;"><?php echo intval($p->present) ? '✓' : '✗'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php $this->print_footer('Fiche de ' . $el->prenom . ' ' . mb_strtoupper($el->nom)); ?>
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       FICHES JURY A5 — impression par candidat
    ══════════════════════════════════════════════════════════ */

    public function handle_jury_fiches() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'sp_cal_print' ) ) wp_die( 'Nonce invalide' );
        $event_id = intval( $_GET['event_id'] ?? 0 );
        if ( ! $event_id ) wp_die( 'event_id manquant' );
        $this->render_fiche_jury( $event_id );
        exit;
    }

    private function render_fiche_jury( $event_id ) {
        global $wpdb;
        $session      = $this->db->get_jury_session( $event_id );
        if ( ! $session ) wp_die( 'Session jury introuvable' );
        $affectations = $this->db->get_affectations( $event_id );
        if ( empty( $affectations ) ) wp_die( 'Aucun candidat affecté' );
        $aires        = $this->db->get_jury_tables( $event_id );
        $epreuves     = $this->db->get_exam_epreuves( null, true );
        $note_min     = intval( $session->note_min ?? 0 );
        $note_max     = intval( $session->note_max ?? 10 );
        $is_par_ep    = ( ($session->mode ?? '') === 'par_epreuve' );

        $te    = $this->db->table_events();
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT titre, date FROM $te WHERE id=%d LIMIT 1", $event_id ) );
        $club  = get_option( 'blogname', 'Club' );
        $dt    = $event && $event->date ? (new DateTime($event->date))->format('d/m/Y') : '';

        $aire_map = array();
        foreach ( $aires as $a ) $aire_map[ intval($a->id) ] = $a->label ?: 'Aire ' . $a->numero;

        // Map epreuve_id → nom pour le mode par_epreuve
        $ep_map = array();
        foreach ( $epreuves as $ep ) $ep_map[ intval($ep->id) ] = $ep->nom;

        // Mode par_epreuve : liste ordonnée des épreuves par aire (pour fiche globale)
        $epreuves_fiche = array();
        if ( $is_par_ep ) {
            foreach ( $aires as $a ) {
                $ep_nom = $ep_map[ intval($a->epreuve_id) ] ?? ( $a->label ?: 'Aire ' . $a->numero );
                $epreuves_fiche[] = array(
                    'aire_label'  => $a->label ?: 'Aire ' . $a->numero,
                    'epreuve_nom' => $ep_nom,
                );
            }
        }

        $this->print_head( 'Fiches jury — ' . ( $event->titre ?? '' ) );
        ?>
        <style>
        @page { size: A4 portrait; margin: 5mm; }
        body  { font-size: 9pt; }

        /* ── Mode par_epreuve : 4 fiches globales par page (2×2) ── */
        .jury-page-global {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 4mm;
            height: 277mm;
            page-break-after: always;
        }
        .jury-page-global:last-child { page-break-after: auto; }

        /* ── Mode par_categorie : 4 fiches par page (2×2) ── */
        .jury-page {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 4mm;
            height: 277mm;
            page-break-after: always;
        }
        .jury-page:last-child { page-break-after: auto; }

        .jury-fiche {
            border: 1.5px solid #1e3a5f;
            border-radius: 4px;
            padding: 3mm;
            break-inside: avoid;
            overflow: hidden;
        }
        .jury-fiche-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid #1e3a5f;
            padding-bottom: 2mm;
            margin-bottom: 2mm;
        }
        .jury-fiche-header-left h2 { font-size: 10pt; font-weight: 800; color: #1e3a5f; margin: 0; }
        .jury-fiche-header-left p  { font-size: 7pt; color: #555; margin: 0.5mm 0 0; }
        .jury-fiche-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1mm 3mm;
            font-size: 8pt;
            margin-bottom: 2mm;
        }
        .jury-fiche-meta-item  { display: flex; flex-direction: column; }
        .jury-fiche-meta-label { font-size: 6.5pt; color: #888; text-transform: uppercase; letter-spacing: .03em; }
        .jury-fiche-meta-val   { font-weight: 700; color: #111; }
        .jury-epreuves-table   { width: 100%; border-collapse: collapse; font-size: 8pt; }
        .jury-epreuves-table th {
            background: #1e3a5f; color: #fff;
            padding: 1.5mm 2mm; text-align: left;
            border: 1px solid #1e3a5f; font-size: 7pt;
        }
        .jury-epreuves-table td {
            padding: 2mm 2mm;
            border: 1px solid #ccc;
            vertical-align: middle;
        }
        .jury-score-box {
            border: 2px solid #1e3a5f; border-radius: 3px;
            width: 22mm; height: 8mm;
            display: inline-block;
        }
        .jury-verdict-zone {
            margin-top: 2mm;
            display: flex;
            flex-wrap: nowrap;
            gap: 4mm;
            align-items: center;
            font-size: 8pt;
            overflow: visible;
        }
        .jury-verdict-case {
            display: flex; align-items: center; gap: 1.5mm; font-weight: 700;
            white-space: nowrap; flex-shrink: 0;
        }
        .jury-case { display: inline-block; width: 5mm; height: 5mm; border: 1.5px solid #333; border-radius: 2px; flex-shrink: 0; }
        .jury-qr   { width: 20mm; height: 20mm; flex-shrink: 0; }
        .jury-score-total {
            margin-top: 2mm;
            padding: 1.5mm 2mm;
            background: #f0f4f8;
            border-radius: 3px;
            font-size: 8pt;
            display: flex;
            gap: 4mm;
            align-items: center;
        }
        .jury-score-total strong { font-size: 9pt; }
        @media print {
            .no-print { display: none !important; }
            .jury-fiche { page-break-inside: avoid; }
        }
        </style>
        <?php

        // ── Génération QR HMAC (admin mobile) ────────────────────
        $tel2 = $this->db->table_eleves();

        if ( $is_par_ep ) {
            // ════════════════════════════════════════════════════════
            // MODE PAR ÉPREUVE : une fiche globale par candidat
            // ════════════════════════════════════════════════════════

            // Dédupliquer les candidats (N lignes par aire → 1 seule)
            $vus   = array();
            $cands = array();
            foreach ( $affectations as $a ) {
                $eid = intval( property_exists($a,'eleve_id') ? $a->eleve_id : $a->id );
                if ( ! isset($vus[$eid]) ) {
                    $vus[$eid] = true;
                    $cands[]   = $a;
                }
            }

            $chunks = array_chunk( $cands, 4 ); // 4 fiches par page
            foreach ( $chunks as $chunk ) :
            ?>
            <div class="jury-page-global">
            <?php foreach ( $chunk as $c ) :
                $eid        = intval( property_exists($c,'eleve_id') ? $c->eleve_id : $c->id );
                $grade_vise = $this->db->get_grade_vise_eleve( $c ) ?: '—';
                // QR HMAC admin
                $hmac_token = substr( hash_hmac( 'sha256', 'jury_qr|' . $event_id . '|' . $eid, wp_salt('auth') ), 0, 32 );
                $dest_url   = admin_url( 'admin.php?page=sp-cal-jury&jury_saisie=1&event_id=' . $event_id . '&eleve_id=' . $eid . '&token=' . $hmac_token );
                $qr_url     = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($dest_url);
            ?>
            <div class="jury-fiche">
                <div class="jury-fiche-header">
                    <div class="jury-fiche-header-left">
                        <h2><?php echo esc_html( $c->prenom . ' ' . mb_strtoupper($c->nom) ); ?></h2>
                        <p><?php echo esc_html( $club ); ?> — <?php echo esc_html( $event->titre ?? '' ); ?> — <?php echo esc_html( $dt ); ?></p>
                    </div>
                    <img src="<?php echo esc_url($qr_url); ?>" class="jury-qr" alt="QR" loading="eager">
                </div>

                <div class="jury-fiche-meta">
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Catégorie</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $c->categorie_age ?? '—' ); ?></span>
                    </div>
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Grade actuel</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $c->grade ?? '—' ); ?></span>
                    </div>
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Grade visé</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $grade_vise ); ?></span>
                    </div>
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Seuil admission</span>
                        <span class="jury-fiche-meta-val"><?php echo floatval($session->seuil_admission ?? 0); ?></span>
                    </div>
                </div>

                <table class="jury-epreuves-table">
                    <thead>
                        <tr>
                            <th style="width:40%;">Épreuve</th>
                            <th style="width:35%;text-align:center;">Note (<?php echo $note_min; ?>–<?php echo $note_max; ?>)</th>
                            <th style="width:25%;text-align:center;">Obs.</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $epreuves_fiche as $ef ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $ef['epreuve_nom'] ); ?></strong></td>
                        <td style="text-align:center;"><span class="jury-score-box"></span></td>
                        <td></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="jury-score-total">
                    <span>Score total :</span>
                    <span class="jury-score-box" style="width:28mm;"></span>
                    <strong>/ <?php echo count($epreuves_fiche) * $note_max; ?></strong>
                </div>

                <div class="jury-verdict-zone" style="margin-top:3mm;">
                    <strong style="white-space:nowrap;">Verdict&nbsp;:</strong>
                    <div class="jury-verdict-case"><span class="jury-case"></span>&nbsp;Admis(e)</div>
                    <div class="jury-verdict-case"><span class="jury-case"></span>&nbsp;Ajourné(e)</div>
                </div>
            </div>
            <?php endforeach; ?>
            </div><!-- /jury-page-global -->
            <?php endforeach;

        } else {
            // ════════════════════════════════════════════════════════
            // MODE PAR CATÉGORIE : comportement original (4 fiches/page)
            // ════════════════════════════════════════════════════════
            $chunks = array_chunk( $affectations, 4 );
            foreach ( $chunks as $chunk ) :
            ?>
            <div class="jury-page">
            <?php foreach ( $chunk as $c ) :
                $eid        = intval( property_exists($c,'eleve_id') ? $c->eleve_id : $c->id );
                $aire_label = $aire_map[ intval($c->aire_id) ] ?? '—';
                $grade_vise = $this->db->get_grade_vise_eleve( $c ) ?: '—';
                $token_eleve = $wpdb->get_var( $wpdb->prepare( "SELECT token FROM $tel2 WHERE id=%d LIMIT 1", $eid ) );
                $qr_url = '';
                if ( $token_eleve ) {
                    $dest_url = home_url('/') . '?token=' . rawurlencode($token_eleve);
                    $qr_url   = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($dest_url);
                }
            ?>
            <div class="jury-fiche">
                <div class="jury-fiche-header">
                    <div class="jury-fiche-header-left">
                        <h2><?php echo esc_html( $c->prenom . ' ' . mb_strtoupper($c->nom) ); ?></h2>
                        <p><?php echo esc_html( $club ); ?> — <?php echo esc_html( $event->titre ?? '' ); ?> — <?php echo esc_html( $dt ); ?></p>
                    </div>
                    <?php if ( $qr_url ) : ?>
                    <img src="<?php echo esc_url($qr_url); ?>" class="jury-qr" alt="QR" loading="eager">
                    <?php endif; ?>
                </div>

                <div class="jury-fiche-meta">
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Catégorie</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $c->categorie_age ?? '—' ); ?></span>
                    </div>
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Aire</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $aire_label ); ?></span>
                    </div>
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Grade actuel</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $c->grade ?? '—' ); ?></span>
                    </div>
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Grade visé</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $grade_vise ); ?></span>
                    </div>
                </div>

                <table class="jury-epreuves-table">
                    <thead>
                        <tr>
                            <th style="width:50%;">Épreuve</th>
                            <th style="width:25%;text-align:center;">Note (<?php echo $note_min; ?>–<?php echo $note_max; ?>)</th>
                            <th style="width:25%;text-align:center;">Obs.</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $epreuves as $ep ) : ?>
                    <tr>
                        <td><?php echo esc_html( $ep->nom ); ?></td>
                        <td style="text-align:center;"><span class="jury-score-box"></span></td>
                        <td></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="jury-verdict-zone">
                    <strong style="white-space:nowrap;">Verdict&nbsp;:</strong>
                    <div class="jury-verdict-case"><span class="jury-case"></span>&nbsp;Admis(e)</div>
                    <div class="jury-verdict-case"><span class="jury-case"></span>&nbsp;Ajourné(e)</div>
                    <div style="margin-left:auto;font-size:8.5pt;color:#888;white-space:nowrap;">Seuil&nbsp;: <?php echo floatval($session->seuil_admission??0); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            </div><!-- /jury-page -->
            <?php endforeach;
        } // fin if/else mode

        $this->print_footer( 'Fiches jury — ' . ($event->titre ?? '') );
    }
/* ══════════════════════════════════════════════════════════
       FICHES JURY RÉSULTATS — impression post-examen
    ══════════════════════════════════════════════════════════ */

    public function handle_jury_fiches_resultats() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'sp_cal_print' ) ) wp_die( 'Nonce invalide' );
        $event_id = intval( $_GET['event_id'] ?? 0 );
        if ( ! $event_id ) wp_die( 'event_id manquant' );
        $this->render_fiche_jury_resultats( $event_id );
        exit;
    }

    private function render_fiche_jury_resultats( $event_id ) {
        global $wpdb;
        $session      = $this->db->get_jury_session( $event_id );
        if ( ! $session ) wp_die( 'Session jury introuvable' );
        $affectations = $this->db->get_affectations( $event_id );
        if ( empty( $affectations ) ) wp_die( 'Aucun candidat affecté' );
        $aires        = $this->db->get_jury_tables( $event_id );
        $epreuves     = $this->db->get_exam_epreuves( null, true );
        $note_min     = intval( $session->note_min ?? 0 );
        $note_max     = intval( $session->note_max ?? 10 );
        $is_par_ep    = ( ($session->mode ?? '') === 'par_epreuve' );
        $seuil        = floatval( $session->seuil_admission ?? 0 );

        $te    = $this->db->table_events();
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT titre, date FROM $te WHERE id=%d LIMIT 1", $event_id ) );
        $club  = get_option( 'blogname', 'Club' );
        $dt    = $event && $event->date ? (new DateTime($event->date))->format('d/m/Y') : '';

        $aire_map = array();
        foreach ( $aires as $a ) $aire_map[ intval($a->id) ] = $a->label ?: 'Aire ' . $a->numero;

        $ep_map = array();
        foreach ( $epreuves as $ep ) $ep_map[ intval($ep->id) ] = $ep->nom;

        // Charger toutes les notes
        $tn    = $this->db->table_jury_notes();
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT eleve_id, epreuve_id, AVG(note) as note FROM $tn WHERE event_id=%d GROUP BY eleve_id, epreuve_id",
            $event_id
        ) );
        $notes_idx = array();
        foreach ( $rows as $r ) {
            $notes_idx[ intval($r->eleve_id) ][ intval($r->epreuve_id) ] = round( floatval($r->note), 2 );
        }

        // Charger les passages (verdict officiel)
        $tp       = $wpdb->prefix . 'sp_cal_exam_passages';
        $passages = $wpdb->get_results( $wpdb->prepare(
            "SELECT eleve_id, recu, nouveau_grade FROM $tp WHERE event_id=%d", $event_id
        ), OBJECT_K );

        // Mode par_epreuve : épreuves par aire
        $epreuves_fiche = array();
        if ( $is_par_ep ) {
            foreach ( $aires as $a ) {
                $ep_nom = $ep_map[ intval($a->epreuve_id) ] ?? ( $a->label ?: 'Aire ' . $a->numero );
                $epreuves_fiche[] = array(
                    'aire_label'  => $a->label ?: 'Aire ' . $a->numero,
                    'epreuve_nom' => $ep_nom,
                    'epreuve_id'  => intval( $a->epreuve_id ),
                );
            }
        }

        $this->print_head( 'Résultats jury — ' . ( $event->titre ?? '' ) );
        ?>
        <style>
        @page { size: A4 portrait; margin: 5mm; }
        body  { font-size: 9pt; }
        .jury-page-global, .jury-page {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 4mm;
            height: 277mm;
            page-break-after: always;
        }
        .jury-page-global:last-child, .jury-page:last-child { page-break-after: auto; }
        .jury-fiche {
            border: 1.5px solid #1e3a5f;
            border-radius: 4px;
            padding: 3mm;
            break-inside: avoid;
            overflow: hidden;
        }
        .jury-fiche.admis   { border-color: #16a34a; }
        .jury-fiche.ajourne { border-color: #dc2626; }
        .jury-fiche-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid #1e3a5f;
            padding-bottom: 2mm;
            margin-bottom: 2mm;
        }
        .jury-fiche-header-left h2 { font-size: 10pt; font-weight: 800; color: #1e3a5f; margin: 0; }
        .jury-fiche-header-left p  { font-size: 7pt; color: #555; margin: 0.5mm 0 0; }
        .jury-fiche-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1mm 3mm;
            font-size: 8pt;
            margin-bottom: 2mm;
        }
        .jury-fiche-meta-item  { display: flex; flex-direction: column; }
        .jury-fiche-meta-label { font-size: 6.5pt; color: #888; text-transform: uppercase; letter-spacing: .03em; }
        .jury-fiche-meta-val   { font-weight: 700; color: #111; }
        .jury-epreuves-table   { width: 100%; border-collapse: collapse; font-size: 8pt; }
        .jury-epreuves-table th {
            background: #1e3a5f; color: #fff;
            padding: 1.5mm 2mm; text-align: left;
            border: 1px solid #1e3a5f; font-size: 7pt;
        }
        .jury-epreuves-table td {
            padding: 2mm 2mm;
            border: 1px solid #ccc;
            vertical-align: middle;
        }
        .jury-note-val {
            font-weight: 800;
            font-size: 10pt;
            text-align: center;
            display: block;
        }
        .jury-verdict-zone {
            margin-top: 2mm;
            padding: 2mm 3mm;
            border-radius: 3px;
            font-size: 9pt;
            font-weight: 800;
            text-align: center;
        }
        .jury-verdict-admis   { background: #dcfce7; color: #16a34a; border: 1.5px solid #16a34a; }
        .jury-verdict-ajourne { background: #fee2e2; color: #dc2626; border: 1.5px solid #dc2626; }
        .jury-verdict-vide    { background: #f3f4f6; color: #9ca3af; border: 1.5px solid #d1d5db; }
        .jury-score-zone {
            margin-top: 2mm;
            display: flex;
            gap: 4mm;
            align-items: center;
            font-size: 8pt;
        }
        .jury-score-val { font-size: 12pt; font-weight: 900; color: #1e3a5f; }
        .jury-nouveau-grade {
            margin-top: 2mm;
            font-size: 8pt;
            color: #374151;
        }
        .jury-nouveau-grade strong { color: #1e3a5f; }
        @media print {
            .no-print { display: none !important; }
            .jury-fiche { page-break-inside: avoid; }
        }
        </style>
        <?php

        if ( $is_par_ep ) {
            $vus   = array();
            $cands = array();
            foreach ( $affectations as $a ) {
                $eid = intval( property_exists($a,'eleve_id') ? $a->eleve_id : $a->id );
                if ( ! isset($vus[$eid]) ) { $vus[$eid] = true; $cands[] = $a; }
            }

            $chunks = array_chunk( $cands, 4 );
            foreach ( $chunks as $chunk ) :
            ?>
            <div class="jury-page-global">
            <?php foreach ( $chunk as $c ) :
                $eid        = intval( property_exists($c,'eleve_id') ? $c->eleve_id : $c->id );
                $grade_vise = $this->db->get_grade_vise_eleve( $c ) ?: '—';
                $passage    = $passages[ $eid ] ?? null;
                $admis      = $passage ? intval($passage->recu) : null;
                $nouveau_grade = $passage ? $passage->nouveau_grade : '';

                // Calcul score
                $score = 0; $nb = 0;
                foreach ( $epreuves_fiche as $ef ) {
                    $ep_id = $ef['epreuve_id'];
                    if ( isset($notes_idx[$eid][$ep_id]) ) { $score += $notes_idx[$eid][$ep_id]; $nb++; }
                }
                $score_moy = $nb ? round($score / $nb, 2) : null;

                $fiche_class = $admis === 1 ? 'admis' : ($admis === 0 ? 'ajourne' : '');
            ?>
            <div class="jury-fiche <?php echo $fiche_class; ?>">
                <div class="jury-fiche-header">
                    <div class="jury-fiche-header-left">
                        <h2><?php echo esc_html( $c->prenom . ' ' . mb_strtoupper($c->nom) ); ?></h2>
                        <p><?php echo esc_html( $club ); ?> — <?php echo esc_html( $event->titre ?? '' ); ?> — <?php echo esc_html( $dt ); ?></p>
                    </div>
                </div>

                <div class="jury-fiche-meta">
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Catégorie</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $c->categorie_age ?? '—' ); ?></span>
                    </div>
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Grade actuel</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $c->grade ?? '—' ); ?></span>
                    </div>
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Grade visé</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $grade_vise ); ?></span>
                    </div>
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Seuil admission</span>
                        <span class="jury-fiche-meta-val"><?php echo $seuil; ?></span>
                    </div>
                </div>

                <table class="jury-epreuves-table">
                    <thead>
                        <tr>
                            <th style="width:50%;">Épreuve</th>
                            <th style="width:50%;text-align:center;">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $epreuves_fiche as $ef ) :
                        $ep_id = $ef['epreuve_id'];
                        $note  = $notes_idx[$eid][$ep_id] ?? null;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $ef['epreuve_nom'] ); ?></strong></td>
                        <td style="text-align:center;">
                            <span class="jury-note-val">
                                <?php echo $note !== null ? number_format($note, 1, '.', '') . ' / ' . $note_max : '—'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="jury-score-zone">
                    <span>Moyenne :</span>
                    <span class="jury-score-val"><?php echo $score_moy !== null ? number_format($score_moy, 2, '.', '') . ' / 10' : '—'; ?></span>
                </div>

                <?php if ( $admis === 1 ) : ?>
                <div class="jury-verdict-zone jury-verdict-admis">✅ ADMIS(E)</div>
                <?php if ( $nouveau_grade ) : ?>
                <div class="jury-nouveau-grade">Nouveau grade : <strong><?php echo esc_html($nouveau_grade); ?></strong></div>
                <?php endif; ?>
                <?php elseif ( $admis === 0 ) : ?>
                <div class="jury-verdict-zone jury-verdict-ajourne">❌ AJOURNÉ(E)</div>
                <?php else : ?>
                <div class="jury-verdict-zone jury-verdict-vide">— Sans verdict —</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endforeach;

        } else {
            $chunks = array_chunk( $affectations, 4 );
            foreach ( $chunks as $chunk ) :
            ?>
            <div class="jury-page">
            <?php foreach ( $chunk as $c ) :
                $eid        = intval( property_exists($c,'eleve_id') ? $c->eleve_id : $c->id );
                $aire_label = $aire_map[ intval($c->aire_id) ] ?? '—';
                $grade_vise = $this->db->get_grade_vise_eleve( $c ) ?: '—';
                $passage    = $passages[ $eid ] ?? null;
                $admis      = $passage ? intval($passage->recu) : null;
                $nouveau_grade = $passage ? $passage->nouveau_grade : '';

                $score = 0; $nb = 0;
                foreach ( $epreuves as $ep ) {
                    $ep_id = intval($ep->id);
                    if ( isset($notes_idx[$eid][$ep_id]) ) { $score += $notes_idx[$eid][$ep_id]; $nb++; }
                }
                $score_moy  = $nb ? round($score / $nb, 2) : null;
                $fiche_class = $admis === 1 ? 'admis' : ($admis === 0 ? 'ajourne' : '');
            ?>
            <div class="jury-fiche <?php echo $fiche_class; ?>">
                <div class="jury-fiche-header">
                    <div class="jury-fiche-header-left">
                        <h2><?php echo esc_html( $c->prenom . ' ' . mb_strtoupper($c->nom) ); ?></h2>
                        <p><?php echo esc_html( $club ); ?> — <?php echo esc_html( $event->titre ?? '' ); ?> — <?php echo esc_html( $dt ); ?></p>
                    </div>
                </div>

                <div class="jury-fiche-meta">
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Catégorie</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $c->categorie_age ?? '—' ); ?></span>
                    </div>
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Aire</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $aire_label ); ?></span>
                    </div>
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Grade actuel</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $c->grade ?? '—' ); ?></span>
                    </div>
                    <div class="jury-fiche-meta-item">
                        <span class="jury-fiche-meta-label">Grade visé</span>
                        <span class="jury-fiche-meta-val"><?php echo esc_html( $grade_vise ); ?></span>
                    </div>
                </div>

                <table class="jury-epreuves-table">
                    <thead>
                        <tr>
                            <th style="width:55%;">Épreuve</th>
                            <th style="width:45%;text-align:center;">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $epreuves as $ep ) :
                        $ep_id = intval($ep->id);
                        $note  = $notes_idx[$eid][$ep_id] ?? null;
                    ?>
                    <tr>
                        <td><?php echo esc_html( $ep->nom ); ?></td>
                        <td style="text-align:center;">
                            <span class="jury-note-val">
                                <?php echo $note !== null ? number_format($note, 1, '.', '') . ' / ' . $note_max : '—'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="jury-score-zone">
                    <span>Moyenne :</span>
                    <span class="jury-score-val"><?php echo $score_moy !== null ? number_format($score_moy, 2, '.', '') . ' / 10' : '—'; ?></span>
                    <span style="color:#888;font-size:7.5pt;">(seuil : <?php echo $seuil; ?>)</span>
                </div>

                <?php if ( $admis === 1 ) : ?>
                <div class="jury-verdict-zone jury-verdict-admis">✅ ADMIS(E)</div>
                <?php if ( $nouveau_grade ) : ?>
                <div class="jury-nouveau-grade">Nouveau grade : <strong><?php echo esc_html($nouveau_grade); ?></strong></div>
                <?php endif; ?>
                <?php elseif ( $admis === 0 ) : ?>
                <div class="jury-verdict-zone jury-verdict-ajourne">❌ AJOURNÉ(E)</div>
                <?php else : ?>
                <div class="jury-verdict-zone jury-verdict-vide">— Sans verdict —</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endforeach;
        }

        $this->print_footer( 'Résultats jury — ' . ($event->titre ?? '') );
    }
    /* ══════════════════════════════════════════════════════════
       EXPORT CSV JURY
    ══════════════════════════════════════════════════════════ */

    public function handle_jury_csv() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'sp_cal_print' ) ) wp_die( 'Nonce invalide' );
        $event_id = intval( $_GET['event_id'] ?? 0 );
        if ( ! $event_id ) wp_die( 'event_id manquant' );

        global $wpdb;
        $session      = $this->db->get_jury_session( $event_id );
        $affectations = $this->db->get_affectations( $event_id );
        $epreuves     = $this->db->get_exam_epreuves( null, true );
        $aires        = $this->db->get_jury_tables( $event_id );
        $te           = $this->db->table_events();
        $tn           = $this->db->table_jury_notes();
        $event        = $wpdb->get_row( $wpdb->prepare( "SELECT titre, date FROM $te WHERE id=%d LIMIT 1", $event_id ) );

        $aire_map = array();
        foreach ( $aires as $a ) $aire_map[ intval($a->id) ] = $a->label ?: 'Aire ' . $a->numero;

        // Charger toutes les notes de cet event
        $rows_csv = $wpdb->get_results( $wpdb->prepare(
            "SELECT eleve_id, epreuve_id, note FROM $tn WHERE event_id=%d", $event_id
        ) );
        $notes_csv = array();
        foreach ( $rows_csv as $r ) {
            $notes_csv[ intval($r->eleve_id) ][ intval($r->epreuve_id) ] = floatval($r->note);
        }

        $seuil  = floatval( $session->seuil_admission ?? 0 );
        $nom_ev = sanitize_file_name( $event->titre ?? 'jury' );
        $date_f = date('Ymd');

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="jury_' . $nom_ev . '_' . $date_f . '.csv"' );
        header( 'Pragma: no-cache' );
        echo "\xEF\xBB\xBF"; // BOM UTF-8 pour Excel

        $out = fopen( 'php://output', 'w' );

        // En-tête
        $header = array( 'Nom', 'Prénom', 'Catégorie âge', 'Grade actuel', 'Grade visé', 'Aire' );
        foreach ( $epreuves as $ep ) $header[] = $ep->nom;
        $header[] = 'Score total';
        $header[] = 'Verdict';
        fputcsv( $out, $header, ';' );

        foreach ( $affectations as $c ) {
            $eid       = intval( $c->eleve_id );
            $score     = 0;
            $has_notes = false;
            $ep_vals   = array();
            foreach ( $epreuves as $ep ) {
                $v = $notes_csv[ $eid ][ intval($ep->id) ] ?? null;
                $ep_vals[] = $v !== null ? number_format( $v, 1, '.', '' ) : '';
                if ( $v !== null ) { $score += $v; $has_notes = true; }
            }
            $grade_vise = $this->db->get_grade_vise_eleve( $c ) ?: '';
            $verdict    = ! $has_notes ? '' : ( $score >= $seuil ? 'Admis' : 'Ajourné' );
            $row = array_merge(
                array(
                    mb_strtoupper( $c->nom ),
                    $c->prenom,
                    $c->categorie_age ?? '',
                    $c->grade ?? '',
                    $grade_vise,
                    $aire_map[ intval($c->aire_id) ] ?? '',
                ),
                $ep_vals,
                array(
                    $has_notes ? number_format( $score, 1, '.', '' ) : '',
                    $verdict,
                )
            );
            fputcsv( $out, $row, ';' );
        }
        fclose( $out );
        exit;
    }
}

endif; // class_exists SpCalPro_PDF

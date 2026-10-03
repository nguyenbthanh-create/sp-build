<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_PDF' ) ) :

class SpCalPro_PDF {

    private $db;

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;
        add_action( 'init', array( $this, 'handle_print_request' ) );
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
                            <span class="cat-badge-saisie"><?php echo esc_html( $this->db->label_discipline($el->categorie_saisie) ); ?></span>
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

        $statut_saison   = SpCalPro_DB::statut_saison_eleve($el);
        $statut_adhesion = $statut_saison['libelle'];
        $statut_class    = 'status-ok';
        if ($statut_saison['code'] === 'inactif') {
            $statut_adhesion = 'Inactif' . ($el->motif_inactif ? ' — ' . $el->motif_inactif : '');
            $statut_class    = 'status-inactif';
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
                            <?php if($el->categorie_saisie) echo '<span class="cat-badge-saisie">'.esc_html( $this->db->label_discipline($el->categorie_saisie) ).'</span> '; ?>
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
}

endif; // class_exists SpCalPro_PDF

<?php
/**
 * Page d'administration « Adhérents » (liste, ajout / modification d'un élève, import, export CSV).
 *
 * Méthodes de SP_Cal_Members déplacées telles quelles depuis class-admin-members.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SP_Cal_Members_Eleves; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SP_Cal_Members_Eleves {


    /**
     * Champs disponibles pour l'export CSV des adhérents — clé colonne DB => libellé affiché.
     * Point unique partagé entre le panneau de sélection (page_eleves) et l'export (export_eleves_csv).
     */
    private function csv_eleves_champs() {
        return array(
            'nom'                    => 'Nom',
            'prenom'                 => 'Prénom',
            'categorie_saisie'       => 'Discipline pratiquée',
            'categorie_age'          => 'Catégorie âge',
            'grade'                  => 'Grade',
            'date_naissance'         => 'Date de naissance',
            'saison'                 => 'Saison',
            'licence'                => 'N° Licence',
            'num_passeport'          => 'Passeport FFTDA',
            'actif'                  => 'Statut adhésion',
            'email'                  => 'Email',
            'email_parent'           => 'Email parent',
            'telephone'              => 'Téléphone',
            'representant_nom'       => 'Représentant nom',
            'representant_prenom'    => 'Représentant prénom',
            'representant_telephone' => 'Représentant téléphone',
            'urgence_nom'            => 'Urgence nom',
            'urgence_prenom'         => 'Urgence prénom',
            'urgence_telephone'      => 'Urgence téléphone',
            'urgence_email'          => 'Urgence email',
            'adresse'                => 'Adresse',
            'nationalite'            => 'Nationalité',
            'lieu_naissance'         => 'Lieu de naissance',
            'taille_cm'              => 'Taille (cm)',
            'poids_kg'               => 'Poids (kg)',
            'pointure'               => 'Pointure',
            'taille_tshirt'          => 'T-shirt',
            'taille_pantalon'        => 'Pantalon',
        );
    }

    /**
     * Génère et envoie le CSV des adhérents avec uniquement les colonnes cochées dans le panneau
     * d'export (cf. doléance 09/2026 : pouvoir choisir point par point ce qu'on retrouve dans le
     * tableau, plutôt qu'un export figé).
     */
    private function export_eleves_csv() {
        $champs_dispo = $this->csv_eleves_champs();
        $champs = array_values( array_intersect(
            array_map( 'sanitize_text_field', wp_unslash( $_GET['champs'] ?? array() ) ),
            array_keys( $champs_dispo )
        ) );
        if ( empty( $champs ) ) {
            wp_redirect( admin_url( 'admin.php?page=sp-cal-eleves&csv_error=1' ) );
            return;
        }

        $eleves = $this->db->get_eleves();

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="adherents_' . date( 'Ymd' ) . '.csv"' );
        header( 'Pragma: no-cache' );

        echo "\xEF\xBB\xBF"; // BOM UTF-8 pour Excel

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array_map( function( $k ) use ( $champs_dispo ) { return $champs_dispo[ $k ]; }, $champs ), ';' );

        foreach ( $eleves as $el ) {
            $line = array();
            foreach ( $champs as $key ) {
                if ( $key === 'categorie_saisie' ) {
                    $line[] = $this->db->label_discipline( $el->categorie_saisie );
                } elseif ( $key === 'date_naissance' ) {
                    $line[] = trim( $el->date_naissance . ( $el->annee_naissance ? '/' . $el->annee_naissance : '' ), '/' );
                } elseif ( $key === 'actif' ) {
                    $line[] = intval( $el->actif ) ? 'Actif' : 'Inactif';
                } else {
                    $line[] = wp_unslash( $el->$key ?? '' );
                }
            }
            fputcsv( $out, $line, ';' );
        }
        fclose( $out );
    }


    /* ══════════════════════════════════════════════════════════
       PAGE ELEVES
    ══════════════════════════════════════════════════════════ */

    public function page_eleves() {
        if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé' );
        global $wpdb;
        $tel       = $this->db->table_eleves();
        $eleves    = $this->db->get_eleves();
        $cats      = $this->db->get_categories_eleves();
        $cats_saisie = $this->db->get_categories_saisie();
        $saisons   = $this->db->get_saisons();
        $grades_ref = $this->db->get_grades_referentiel();
        $te_examens = $this->db->table_events();
        $examens_events = $wpdb->get_results(
            "SELECT id, titre, date FROM $te_examens WHERE type='examen' ORDER BY date DESC LIMIT 50"
        );

        $edit = null;
        if ( isset( $_GET['sp_edit_eleve'] ) ) {
            $edit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tel WHERE id=%d", intval( $_GET['sp_edit_eleve'] ) ) );
        }

        // Décodage de extra_data — sert à la fois au palmarès des anciennes fiches (migration
        // affichage) et aux champs ajoutés sur le formulaire public mais absents jusqu'ici de
        // cette fiche admin (Pass'Sport, documents, sexe...) — cf. doleances.md 09/09/2026.
        $extra = array();
        $extra_grades = array();
        if ( $edit && $edit->extra_data ) {
            $decoded_extra = json_decode( $edit->extra_data, true );
            $extra         = is_array( $decoded_extra ) ? $decoded_extra : array();
            $extra_grades  = $extra['grades'] ?? array();
        }
        $extra_representants = $extra['representants_legaux'] ?? array();
        $extra_urgence       = $extra['contact_urgence']      ?? array();
        $extra_documents     = $extra['documents']            ?? array();
        ?>
        <div class="wrap sp-cal-wrap">
        <h1>Adhérents</h1>
        <a href="<?php echo esc_url( admin_url('admin.php?page=sp-cal-print-cartes') ); ?>"
           class="button" style="margin-bottom:16px;display:inline-flex;align-items:center;gap:6px;">
            🖨️ Impression en lot des cartes
        </a>

        <?php $this->notice_flash( 'saved', 'Élève enregistré.' ); ?>
        <?php $this->notice_flash( 'deleted', 'Élève(s) supprimé(s).' ); ?>
        <?php if ( isset( $_GET['passages_done'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>✅ Passage de grade enregistré pour <strong><?php echo intval($_GET['passages_done']); ?></strong> élève(s).</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['csv_error'] ) ) : ?>
        <div class="notice notice-error is-dismissible"><p>⚠️ Sélectionnez au moins une colonne avant d'exporter.</p></div>
        <?php endif; ?>
        <?php
        // ── Bascule catégories septembre ──────────────────────────────────
        $mois_courant = intval(date('n'));
        $bascule_active = ($mois_courant >= 6 && $mois_courant <= 9);

        if ( isset($_GET['bascule_done']) ) {
            $nb = intval($_GET['nb']);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Bascule effectuée — <strong>' . $nb . '</strong> élève(s) mis à jour.</p></div>';
        }
        if ( isset($_GET['bascule_preview']) ) {
            $preview = get_transient( 'sp_bascule_preview_' . get_current_user_id() );
            if ( $preview ) :
        ?>
        <div class="notice notice-warning" style="padding:16px;">
            <p><strong>👁️ Aperçu de la bascule — <?php echo count($preview); ?> élève(s) concerné(s)</strong></p>
            <table class="wp-list-table widefat fixed striped" style="max-width:600px;margin:10px 0;">
                <thead><tr><th>Élève</th><th>Né(e) en</th><th>Avant</th><th>Après</th></tr></thead>
                <tbody>
                <?php foreach($preview as $p): ?>
                <tr>
                    <td><?php echo esc_html($p['nom']); ?></td>
                    <td><?php echo intval( $p['annee'] ?? 0 ); ?></td>
                    <td><span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html($p['avant']); ?></span></td>
                    <td><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html($p['apres']); ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" style="margin-top:8px;">
                <?php wp_nonce_field('sp_bascule_categories'); ?>
                <input type="hidden" name="sp_bascule_categories" value="1">
                <input type="submit" class="button button-primary" value="✅ Confirmer la bascule">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-eleves')); ?>" class="button" style="margin-left:8px;">Annuler</a>
            </form>
        </div>
        <?php endif; } ?>

        <div class="sp-box" style="border-left:4px solid <?php echo $bascule_active ? '#f59e0b' : '#e5e7eb'; ?>;margin-bottom:18px;">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div style="flex:1;">
                    <strong>🗓️ Bascule des catégories d'âge</strong>
                    <p style="margin:4px 0 0;color:#64748b;font-size:13px;">
                        Recalcule la catégorie de chaque élève selon sa classe à la rentrée <?php echo intval( SpCalPro_DB::annee_saison_categories() ); ?> (année de naissance, comme à l'école) :
                        Baby = maternelle (né en <?php echo intval( SpCalPro_DB::annee_saison_categories() - 5 ); ?> ou après) · Enfant = primaire (<?php echo intval( SpCalPro_DB::annee_saison_categories() - 10 ); ?>–<?php echo intval( SpCalPro_DB::annee_saison_categories() - 6 ); ?>) · Ado/adulte = collège et plus
                        — n'affecte pas les élèves en Renforcement musculaire (catégorie "Tout âge" fixe).
                    </p>
                    <?php if ( ! $bascule_active ) : ?>
                    <p style="margin:6px 0 0;color:#92400e;font-size:12px;background:#fef9c3;padding:4px 8px;border-radius:4px;display:inline-block;">
                        ⚠️ Hors période de rentrée — utilisez la bascule pour les retours tardifs ou cas individuels.
                    </p>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field('sp_bascule_categories'); ?>
                        <input type="hidden" name="sp_bascule_categories" value="1">
                        <input type="hidden" name="sp_bascule_preview" value="1">
                        <input type="submit" class="button" value="👁️ Prévisualiser">
                    </form>
                </div>
            </div>
        </div>

        <?php
        // ── Harmonisation des grades avec TKD Parcours ──────────────────────
        // La liste « Grade actuel » suit TKD Parcours (schéma des grades du site) : les fiches
        // encore écrites avec l'ancienne numérotation Baby / Enfant sont proposées à la conversion.
        if ( isset( $_GET['grades_harmo_done'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Grades harmonisés — <strong>' . intval( $_GET['nb'] ) . '</strong> élève(s) mis à jour.</p></div>';
        }
        if ( $this->db->claira_grades_actif() && $grades_ref ) :
            $harmo          = $this->db->preview_harmonisation_grades();
            // Le grade suivant vient de TKD Parcours (get_grade_vise_eleve(), 03/10/2026) : l'ancienne
            // table de progression ne sert plus que de secours si le Parcours est désactivé.
            if ( $harmo ) :
                $voir_harmo = isset( $_GET['sp_grades_harmo'] );
                $nb_auto    = count( array_filter( $harmo, function( $h ) { return $h['propose'] !== ''; } ) );
        ?>
        <div class="sp-box" style="border-left:4px solid #f59e0b;margin-bottom:18px;">
            <strong>🥋 Grades à harmoniser avec TKD Parcours</strong>
            <p style="margin:4px 0 0;color:#64748b;font-size:13px;">
                La liste « Grade actuel » suit désormais le schéma des grades du site (TKD Parcours).
                <?php if ( $harmo ) : ?>
                <strong><?php echo count( $harmo ); ?></strong> élève(s) ont un grade écrit avec l'ancienne numérotation
                (<?php echo $nb_auto; ?> correspondance(s) trouvée(s) automatiquement, <?php echo count( $harmo ) - $nb_auto; ?> à choisir).
                <?php endif; ?>
            </p>
            <?php if ( ! $voir_harmo ) : ?>
            <p style="margin:10px 0 0;">
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sp-cal-eleves&sp_grades_harmo=1' ) ); ?>">👁️ Voir et corriger</a>
            </p>
            <?php else : ?>
            <form method="post" style="margin-top:12px;">
                <?php wp_nonce_field( 'sp_harmoniser_grades' ); ?>
                <input type="hidden" name="sp_harmoniser_grades" value="1">
                <?php if ( $harmo ) : ?>
                <table class="wp-list-table widefat fixed striped" style="max-width:860px;margin-bottom:10px;">
                    <thead><tr><th>Élève</th><th style="width:110px;">Catégorie</th><th style="width:150px;">Grade actuel</th><th>Nouveau grade</th></tr></thead>
                    <tbody>
                    <?php foreach ( $harmo as $h ) :
                        // Liste de la catégorie de l'élève en premier, puis les autres
                        $groupes = $grades_ref;
                        if ( $h['cat_ref'] ) $groupes = array( $h['cat_ref'] => $grades_ref[ $h['cat_ref'] ] ) + $grades_ref;
                    ?>
                    <tr<?php echo $h['propose'] === '' ? ' style="background:#fef9c3;"' : ''; ?>>
                        <td><?php echo esc_html( $h['nom'] ); ?></td>
                        <td><?php echo esc_html( $h['cat'] ?: '—' ); ?></td>
                        <td><span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html( $h['actuel'] ); ?></span></td>
                        <td>
                            <select name="sp_grade_choix[<?php echo intval( $h['id'] ); ?>]" style="width:100%;">
                                <option value="">— ne pas changer —</option>
                                <?php foreach ( $groupes as $cat_g => $chain_g ) : ?>
                                <optgroup label="<?php echo esc_attr( $cat_g ); ?>">
                                    <?php foreach ( $chain_g as $g ) : ?>
                                    <option value="<?php echo esc_attr( $g ); ?>" <?php selected( $h['propose'], $g ); ?>><?php echo esc_html( $g ); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin:0 0 10px;color:#64748b;font-size:12px;">
                    Lignes en jaune : pas de correspondance sûre (ex. POOM → Il / Yi / Sam Poom), à choisir à la main ou à laisser inchangées.
                </p>
                <?php endif; ?>
                <p style="margin:0 0 10px;color:#64748b;font-size:12px;">
                    Le grade visé aux passages de grade est ensuite le suivant dans TKD Parcours.
                    À faire de préférence en dehors d'un passage de grade en préparation.
                </p>
                <input type="submit" class="button button-primary" value="✅ Appliquer">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sp-cal-eleves' ) ); ?>" class="button" style="margin-left:8px;">Annuler</a>
            </form>
            <?php endif; ?>
        </div>
        <?php
            endif;
        endif;

        // ── Normalisation écriture catégories d'âge ─────────────────────────
        if ( isset($_GET['normalisation_done']) ) {
            $nb = intval($_GET['nb']);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Normalisation effectuée — <strong>' . $nb . '</strong> élève(s) mis à jour.</p></div>';
        }
        if ( isset($_GET['normalisation_preview']) ) {
            $preview_norm = get_transient( 'sp_normaliser_cat_preview_' . get_current_user_id() );
            if ( $preview_norm ) :
                $changements   = $preview_norm['changements'];
                $non_reconnues = $preview_norm['non_reconnues'];
        ?>
        <div class="notice notice-warning" style="padding:16px;">
            <?php if ( empty($changements) ) : ?>
            <p><strong>👁️ Aucune écriture à corriger</strong> — toutes les catégories d'âge en base correspondent déjà aux 5 libellés officiels.</p>
            <?php else : ?>
            <p><strong>👁️ Aperçu de la normalisation — <?php echo count($changements); ?> écriture(s) à corriger</strong></p>
            <table class="wp-list-table widefat fixed striped" style="max-width:600px;margin:10px 0;">
                <thead><tr><th>Avant</th><th>Après</th><th>Élèves concernés</th></tr></thead>
                <tbody>
                <?php foreach($changements as $c): ?>
                <tr>
                    <td><span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html($c['avant']); ?></span></td>
                    <td><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html($c['apres']); ?></span></td>
                    <td><?php echo intval($c['nb']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" style="margin-top:8px;">
                <?php wp_nonce_field('sp_normaliser_categories_age'); ?>
                <input type="hidden" name="sp_normaliser_categories_age" value="1">
                <input type="submit" class="button button-primary" value="✅ Confirmer la normalisation">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-eleves')); ?>" class="button" style="margin-left:8px;">Annuler</a>
            </form>
            <?php endif; ?>
            <?php if ( ! empty($non_reconnues) ) : ?>
            <p style="margin-top:14px;"><strong>⚠️ <?php echo count($non_reconnues); ?> écriture(s) non reconnue(s)</strong> — à corriger à la main sur la fiche de chaque élève concerné (liste déroulante Catégorie d'âge → "Autre") :</p>
            <ul style="margin:4px 0 0 20px;list-style:disc;">
                <?php foreach($non_reconnues as $nr): ?>
                <li><span style="background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html($nr['valeur']); ?></span> — <?php echo intval($nr['nb']); ?> élève(s)</li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; } ?>

        <div class="sp-box" style="border-left:4px solid #e5e7eb;margin-bottom:18px;">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div style="flex:1;">
                    <strong>✏️ Normaliser l'écriture des catégories d'âge</strong>
                    <p style="margin:4px 0 0;color:#64748b;font-size:13px;">
                        Uniformise l'écriture vers les 5 libellés officiels — <code>Baby</code>, <code>Enfant</code>,
                        <code>Ado/adulte</code>, <code>Adulte</code>, <code>Tout âge</code> (Renforcement musculaire,
                        non subdivisé par âge) — sans changer la catégorie de qui que ce soit (seulement corrige des
                        variantes comme "babies" ou "BABY" en "Baby", ou "RENFO" en "Tout âge"). Utile car le ciblage
                        des emails d'inscription événements compare des chaînes exactes.
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field('sp_normaliser_categories_age'); ?>
                        <input type="hidden" name="sp_normaliser_categories_age" value="1">
                        <input type="hidden" name="sp_normaliser_preview" value="1">
                        <input type="submit" class="button" value="👁️ Prévisualiser">
                    </form>
                </div>
            </div>
        </div>
        <?php
        if ( isset($_GET['tokens_sent']) ) {
            $sent    = intval($_GET['tokens_sent']);
            $skipped = intval($_GET['tokens_skipped'] ?? 0);
            $msg = '📧 ' . $sent . ' lien' . ($sent > 1 ? 's' : '') . ' d\'accès envoyé' . ($sent > 1 ? 's' : '');
            if ($skipped) $msg .= ' &nbsp;·&nbsp; <strong>' . $skipped . '</strong> élève' . ($skipped > 1 ? 's' : '') . ' ignoré' . ($skipped > 1 ? 's' : '') . ' (aucun email renseigné)';
            echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
        }
        ?>

        <!-- IMPORT CSV -->
        <div class="sp-box">
            <h2><span class="dashicons dashicons-upload"></span> Import CSV</h2>
            <p class="description">Colonnes reconnues automatiquement : <code>Saison, Rang, Catégorie saisie, Catégorie d'âge, Nom, Prénom, Anniversaire, N° licence, Palmarès</code> + colonnes de grades (dates <code>JJ/MM/AAAA</code> en en-tête).</p>
            <div class="sp-inline-fields">
                <input type="file" id="sp-csv-file" name="sp_csv_file" accept=".csv">
                <button class="button button-primary" id="sp-csv-btn">
                    <span class="dashicons dashicons-database-import"></span> Importer
                </button>
            </div>
            <div id="sp-csv-result" class="sp-result-box" style="display:none;"></div>
        </div>

        <?php
        // ── Liens famille en attente de confirmation ─────────────────
        $tfl = $this->db->table_famille_liens();
        $tel2 = $this->db->table_eleves();
        $liens_pending = $wpdb->get_results(
            "SELECT fl.id, fl.type_lien,
                    e1.id AS id1, e1.nom AS nom1, e1.prenom AS prenom1,
                    e2.id AS id2, e2.nom AS nom2, e2.prenom AS prenom2
             FROM $tfl fl
             INNER JOIN $tel2 e1 ON e1.id = fl.eleve_id_1
             INNER JOIN $tel2 e2 ON e2.id = fl.eleve_id_2
             WHERE fl.confirme = 0
             ORDER BY fl.id DESC LIMIT 50"
        );
        if ( $liens_pending ) : ?>
        <div class="sp-box" style="border-left:4px solid #f59e0b;">
            <h2>👨‍👩‍👧 Liens famille à confirmer <span class="sp-badge" style="background:#f59e0b;color:#fff;"><?php echo count($liens_pending); ?></span></h2>
            <p class="description">Ces élèves partagent le même email. Confirmez ou rejetez le lien famille.</p>
            <table class="wp-list-table widefat fixed striped" style="max-width:860px;">
                <thead><tr><th>Élève 1</th><th>Élève 2</th><th>Type de lien</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ( $liens_pending as $lien ) : ?>
                <tr id="sp-lien-row-<?php echo intval($lien->id); ?>">
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id='.$lien->id1)); ?>"><?php echo esc_html($lien->prenom1.' '.$lien->nom1); ?></a></td>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id='.$lien->id2)); ?>"><?php echo esc_html($lien->prenom2.' '.$lien->nom2); ?></a></td>
                    <td>
                        <select class="sp-lien-type" data-lien-id="<?php echo intval($lien->id); ?>" style="width:130px;">
                            <option value="famille">Famille</option>
                            <option value="fratrie">Fratrie</option>
                            <option value="parent_enfant">Parent / Enfant</option>
                        </select>
                    </td>
                    <td>
                        <button class="button button-small sp-btn-confirm-lien" data-lien-id="<?php echo intval($lien->id); ?>">✅ Confirmer</button>
                        <button class="button button-small" style="margin-left:6px;color:#dc2626;" onclick="spRejectLien(<?php echo intval($lien->id); ?>)">✕ Rejeter</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div id="sp-lien-result" style="margin-top:8px;font-size:13px;color:#15803d;"></div>
        </div>
        <script>
        (function($){
            $(document).on('click','.sp-btn-confirm-lien', function(){
                var id = $(this).data('lien-id');
                var type = $('#sp-lien-row-'+id+' .sp-lien-type').val();
                $.post(ajaxurl,{action:'sp_cal_confirm_famille_lien',nonce:'<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',lien_id:id,type_lien:type},function(res){
                    if(res.success){$('#sp-lien-row-'+id).fadeOut();$('#sp-lien-result').text('✅ Lien confirmé.').show();}
                });
            });
        }(jQuery));
        function spRejectLien(id){
            if(!confirm('Rejeter ce lien famille ?')) return;
            jQuery.post(ajaxurl,{action:'sp_cal_delete_famille_lien',nonce:'<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',lien_id:id},function(res){
                if(res.success) jQuery('#sp-lien-row-'+id).fadeOut();
            });
        }
        </script>
        <?php endif; ?>

        <!-- FORMULAIRE ÉLÈVE : masqué derrière un bouton + fenêtre modale (10/09/2026) pour que
             la page affiche d'abord la liste des adhérents, pas un énorme formulaire de saisie. -->
        <?php if ( ! $edit ) : ?>
        <p>
            <button type="button" id="sp-eleve-add-btn" class="button button-primary button-hero">➕ Ajouter un adhérent</button>
        </p>
        <?php endif; ?>

        <div id="sp-eleve-form-modal-overlay" class="sp-modal-overlay" style="<?php echo $edit ? '' : 'display:none;'; ?>">
        <div class="sp-modal-panel">
        <button type="button" id="sp-eleve-form-modal-close" class="sp-modal-close" aria-label="Fermer">✕</button>
        <div class="sp-box" style="max-width:900px;margin:0 auto;">
            <h2><?php echo $edit ? '✏️ Modifier l\'élève' : '➕ Ajouter un élève'; ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_save_eleve' ); ?>
                <input type="hidden" name="eleve_id" value="<?php echo $edit ? intval( $edit->id ) : 0; ?>">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px;max-width:860px;">
                <?php
                $f = function( $label, $name, $val='', $type='text', $placeholder='', $required=false ) {
                    echo '<div style="margin-bottom:12px;">';
                    echo '<label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">' . $label . ( $required ? ' <span style="color:#c00;">*</span>' : '' ) . '</label>';
                    echo '<input type="' . $type . '" name="' . $name . '" value="' . esc_attr($val) . '" placeholder="' . esc_attr($placeholder) . '" class="regular-text" style="width:100%;"' . ( $required ? ' required' : '' ) . '>';
                    echo '</div>';
                };

                // Colonne gauche
                $f( 'Nom',                    'eleve_nom',          $edit->nom             ?? '', 'text', '',       true );
                $f( 'Prénom',                  'eleve_prenom',       $edit->prenom          ?? '' );
                ?>
                <!-- Grade actuel — liste déroulante groupée par catégorie d'âge (référentiel
                     TKD Parcours, ou ancienne table de secours), pour harmoniser la saisie. "Autre" en secours si le grade
                     ne figure pas dans le référentiel (cf. doleances.md). -->
                <div style="margin-bottom:12px;">
                    <?php $grade_edit = $edit->grade ?? ''; $grade_in_ref = false; ?>
                    <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">Grade actuel</label>
                    <select name="eleve_grade" id="sp-eleve-grade-select" class="regular-text" style="width:100%;">
                        <option value="">—</option>
                        <?php foreach ( $grades_ref as $cat => $chain ) : ?>
                        <optgroup label="<?php echo esc_attr($cat); ?>">
                            <?php foreach ( $chain as $g ) :
                                if ( $g === $grade_edit ) $grade_in_ref = true;
                            ?>
                            <option value="<?php echo esc_attr($g); ?>" <?php selected( $grade_edit, $g ); ?>><?php echo esc_html($g); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                        <option value="__autre__" <?php selected( $grade_edit && ! $grade_in_ref, true ); ?>>Autre…</option>
                    </select>
                    <input type="text" name="eleve_grade_autre" id="sp-eleve-grade-autre"
                           value="<?php echo ( $grade_edit && ! $grade_in_ref ) ? esc_attr($grade_edit) : ''; ?>"
                           placeholder="Préciser le grade"
                           class="regular-text"
                           style="width:100%;margin-top:6px;<?php echo ( $grade_edit && ! $grade_in_ref ) ? '' : 'display:none;'; ?>">
                    <script>
                    (function(){
                        var sel = document.getElementById('sp-eleve-grade-select');
                        var txt = document.getElementById('sp-eleve-grade-autre');
                        if (!sel || !txt) return;
                        sel.addEventListener('change', function(){
                            txt.style.display = sel.value === '__autre__' ? '' : 'none';
                        });
                    })();
                    </script>
                </div>
                <?php
                $f( 'N° Licence',              'eleve_licence',      $edit->licence         ?? '' );
                ?>
                <!-- Statut adhésion -->
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-weight:600;font-size:12px;margin-bottom:6px;">Statut adhésion</label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="eleve_actif" value="1"
                               <?php checked( intval($edit->actif ?? 1), 1 ); ?>>
                        <span><?php echo intval($edit->actif ?? 1) ? '<span class="sp-lic-badge sp-lic-ok">✅ Actif</span>' : '<span class="sp-lic-badge sp-lic-expired">❌ Inactif</span>'; ?></span>
                    </label>
                </div>
                <!-- Motif inactivité (affiché si inactif) -->
                <div style="margin-bottom:12px;" id="sp-motif-row" <?php echo intval($edit->actif ?? 1) ? 'style="display:none;"' : ''; ?>>
                    <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">Motif d'inactivité</label>
                    <input type="text" name="eleve_motif"
                           value="<?php echo esc_attr($edit->motif_inactif ?? ''); ?>"
                           placeholder="Impayé, départ, blessure…"
                           class="regular-text" style="width:100%;">
                </div>
                <script>
                (function(){
                    var chk = document.querySelector('input[name="eleve_actif"]');
                    var row = document.getElementById('sp-motif-row');
                    if (!chk || !row) return;
                    row.style.display = chk.checked ? 'none' : '';
                    chk.addEventListener('change', function(){ row.style.display = this.checked ? 'none' : ''; });
                })();
                </script>
                <?php
                $f( 'Passeport FFTDA',         'eleve_num_passeport', $edit->num_passeport   ?? '' );
                $f( 'Saison',                  'eleve_saison',       $edit->saison          ?? '', 'text', '2025/2026' );
                ?>
                    <!-- Discipline pratiquée (droite col 1) — mêmes codes que le formulaire public
                         d'adhésion (TKD/RENFO, cf. SP_Front_Adhesion::DISCIPLINES) pour que la fiche
                         admin et les inscriptions en ligne alimentent la même colonne sans divergence. -->
                    <div style="margin-bottom:12px;">
                        <?php $disc_edit = $edit->categorie_saisie ?? ''; $disc_connue = in_array( $disc_edit, array('TKD','RENFO'), true ); ?>
                        <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">Discipline pratiquée</label>
                        <select name="eleve_cat_saisie" id="sp-eleve-disc-select" class="regular-text" style="width:100%;">
                            <option value="">—</option>
                            <option value="TKD"   <?php selected( $disc_edit, 'TKD' ); ?>>Taekwondo</option>
                            <option value="RENFO" <?php selected( $disc_edit, 'RENFO' ); ?>>Renforcement musculaire</option>
                            <option value="__autre__" <?php selected( $disc_edit && ! $disc_connue, true ); ?>>Autre…</option>
                        </select>
                        <input type="text" name="eleve_cat_saisie_autre" id="sp-eleve-disc-autre"
                               value="<?php echo ( $disc_edit && ! $disc_connue ) ? esc_attr($disc_edit) : ''; ?>"
                               placeholder="Préciser la discipline"
                               class="regular-text"
                               style="width:100%;margin-top:6px;<?php echo ( $disc_edit && ! $disc_connue ) ? '' : 'display:none;'; ?>">
                        <script>
                        (function(){
                            var sel = document.getElementById('sp-eleve-disc-select');
                            var txt = document.getElementById('sp-eleve-disc-autre');
                            if (!sel || !txt) return;
                            sel.addEventListener('change', function(){
                                txt.style.display = sel.value === '__autre__' ? '' : 'none';
                            });
                        })();
                        </script>
                    </div>
                <?php
                // Colonne droite
                ?>
                <!-- Date + Année naissance sur une seule ligne (span 2 colonnes) -->
                <div style="grid-column:1 / -1; margin-bottom:12px;">
                    <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">Date de naissance</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="text" name="eleve_ddn"
                               value="<?php echo esc_attr($edit->date_naissance  ?? ''); ?>"
                               placeholder="25/06"
                               class="regular-text"
                               style="width:90px;"
                               maxlength="5">
                        <span style="color:#6b7280;font-size:13px;">/</span>
                        <input type="text" name="eleve_annee"
                               value="<?php echo esc_attr($edit->annee_naissance ?? ''); ?>"
                               placeholder="2010"
                               class="regular-text"
                               style="width:80px;"
                               maxlength="4">
                        <span style="color:#9ca3af;font-size:11px;">JJ/MM &nbsp;/&nbsp; AAAA</span>
                    </div>
                </div>
                    <!-- Sexe (ajouté 09/09/2026 — présent sur le formulaire public, absent jusqu'ici de la fiche admin) -->
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">Sexe</label>
                        <div style="display:flex;gap:14px;">
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                                <input type="radio" name="eleve_sexe" value="M" <?php checked( ($extra['sexe'] ?? '') === 'M' ); ?>> Masculin
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                                <input type="radio" name="eleve_sexe" value="F" <?php checked( ($extra['sexe'] ?? '') === 'F' ); ?>> Féminin
                            </label>
                        </div>
                    </div>
                    <!-- Catégorie d'âge — liste déroulante sourcée sur les 5 catégories officielles
                         (toujours proposées, même si aucun élève n'en a encore une en base — sinon
                         "Tout âge" n'apparaîtrait qu'une fois qu'un premier élève l'aurait déjà,
                         problème de l'oeuf et la poule) + le référentiel des grades + les
                         valeurs déjà utilisées sur d'autres fiches, pour couvrir tout cas hérité. -->
                    <div style="margin-bottom:12px;">
                        <?php
                        $cat_edit       = $edit->categorie_age ?? '';
                        $cats_officiel  = array( 'Baby', 'Enfant', 'Ado/adulte', 'Adulte', 'Tout âge' );
                        $cats_dispo     = array_unique( array_merge( $cats_officiel, array_keys( $grades_ref ), $cats ) );
                        $cat_connue     = in_array( $cat_edit, $cats_dispo, true );
                        ?>
                        <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">Catégorie d'âge</label>
                        <select name="eleve_cat" id="sp-eleve-cat-select" class="regular-text" style="width:100%;">
                            <option value="">—</option>
                            <?php foreach ( $cats_dispo as $c ) : ?>
                            <option value="<?php echo esc_attr($c); ?>" <?php selected( $cat_edit, $c ); ?>><?php echo esc_html($c); ?></option>
                            <?php endforeach; ?>
                            <option value="__autre__" <?php selected( $cat_edit && ! $cat_connue, true ); ?>>Autre…</option>
                        </select>
                        <input type="text" name="eleve_cat_autre" id="sp-eleve-cat-autre"
                               value="<?php echo ( $cat_edit && ! $cat_connue ) ? esc_attr($cat_edit) : ''; ?>"
                               placeholder="Préciser la catégorie"
                               class="regular-text"
                               style="width:100%;margin-top:6px;<?php echo ( $cat_edit && ! $cat_connue ) ? '' : 'display:none;'; ?>">
                        <script>
                        (function(){
                            var sel = document.getElementById('sp-eleve-cat-select');
                            var txt = document.getElementById('sp-eleve-cat-autre');
                            if (!sel || !txt) return;
                            sel.addEventListener('change', function(){
                                txt.style.display = sel.value === '__autre__' ? '' : 'none';
                            });
                        })();
                        </script>
                    </div>
                    <!-- Rang -->
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">Rang <small style="font-weight:400;color:#666;">(ordre dans la liste)</small></label>
                        <input type="number" name="eleve_rang" value="<?php echo intval($edit->rang ?? 0); ?>"
                               min="0" style="width:100px;">
                    </div>
                <?php
                $f( 'Téléphone',               'eleve_tel',          $edit->telephone       ?? '' );
                $f( 'Email',                   'eleve_email',        $edit->email           ?? '', 'email' );
                $f( 'Email parent',            'eleve_email_parent', $edit->email_parent    ?? '', 'email' );
                ?>

                <!-- Représentant(s) légaux / Contact d'urgence -->
                <?php
                $annee_naiss = intval( $edit->annee_naissance ?? 0 );
                $est_mineur  = $annee_naiss > 0 && ( intval(date('Y')) - $annee_naiss ) < 18;

                // Champs texte/email d'un bloc "personne"
                $f2 = function( $label, $name, $value, $type = 'text', $required = false ) use ( $est_mineur ) {
                    $req_attr  = $required && $est_mineur ? ' required' : '';
                    $req_label = $required && $est_mineur ? ' <span style="color:#dc2626;">*</span>' : '';
                    echo '<div style="margin-bottom:10px;">';
                    echo '<label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">' . esc_html($label) . $req_label . '</label>';
                    echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text" style="width:100%;"' . $req_attr . '>';
                    echo '</div>';
                };
                // Menu déroulant "lien avec l'adhérent" — mêmes options que le formulaire public
                $f_statut = function( $name, $selected ) {
                    echo '<div style="margin-bottom:10px;">';
                    echo '<label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">Lien avec l\'adhérent</label>';
                    echo '<select name="' . esc_attr($name) . '" class="regular-text" style="width:100%;">';
                    echo '<option value="">—</option>';
                    foreach ( self::STATUTS_CONTACT as $key => $label ) {
                        echo '<option value="' . esc_attr($key) . '"' . selected( $selected, $key, false ) . '>' . esc_html($label) . '</option>';
                    }
                    echo '</select></div>';
                };

                $repres2 = $extra_representants[1] ?? array();
                ?>
                <div style="max-width:860px;margin:18px 0 6px;">

                    <!-- ── Représentant légal 1 ── -->
                    <div style="padding:14px 16px;border:2px solid <?php echo $est_mineur ? '#dc2626' : '#e5e7eb'; ?>;border-radius:8px 8px 0 0;background:<?php echo $est_mineur ? '#fff5f5' : '#f9fafb'; ?>;border-bottom:none;">
                        <p style="margin:0 0 12px;font-weight:700;font-size:13px;color:<?php echo $est_mineur ? '#dc2626' : '#374151'; ?>;">
                            👨‍👩‍👧 Représentant légal
                            <?php if ( $est_mineur ) echo ' &nbsp;<span style="background:#dc2626;color:#fff;font-size:11px;padding:1px 7px;border-radius:10px;font-weight:600;">Élève mineur</span>'; ?>
                        </p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px;">
                        <?php
                        $f_statut( 'eleve_repres1_statut', $extra_representants[0]['statut'] ?? '' );
                        $f2( 'Nom',                'eleve_repres1_nom',       $edit->representant_nom       ?? '', 'text', true );
                        $f2( 'Prénom',             'eleve_repres1_prenom',    $edit->representant_prenom    ?? '' );
                        $f2( 'Téléphone',          'eleve_repres1_telephone', $edit->representant_telephone ?? '' );
                        $f2( 'Email',              'eleve_repres1_email',     $extra_representants[0]['email'] ?? '', 'email' );
                        ?>
                        </div>
                    </div>

                    <!-- ── Représentant légal 2 (optionnel) ── -->
                    <div style="padding:14px 16px;border:1px solid #e5e7eb;background:#fff;">
                        <p style="margin:0 0 12px;font-weight:700;font-size:13px;color:#6b7280;">
                            👨‍👩‍👧 2<sup>e</sup> représentant légal <span style="font-weight:400;font-size:11px;">(facultatif)</span>
                        </p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px;">
                        <?php
                        $f_statut( 'eleve_repres2_statut', $repres2['statut'] ?? '' );
                        $f2( 'Nom',       'eleve_repres2_nom',       $repres2['nom']       ?? '' );
                        $f2( 'Prénom',    'eleve_repres2_prenom',    $repres2['prenom']    ?? '' );
                        $f2( 'Téléphone', 'eleve_repres2_telephone', $repres2['telephone'] ?? '' );
                        $f2( 'Email',     'eleve_repres2_email',     $repres2['email']     ?? '', 'email' );
                        ?>
                        </div>
                    </div>

                    <!-- ── Contact urgence ── -->
                    <div style="padding:14px 16px;border:2px solid <?php echo $est_mineur ? '#dc2626' : '#e5e7eb'; ?>;border-radius:0 0 8px 8px;background:#f9fafb;">
                        <p style="margin:0 0 12px;font-weight:700;font-size:13px;color:#374151;">
                            🚨 Contact d'urgence
                            <span style="font-weight:400;font-size:11px;color:#888;margin-left:6px;">(peut être différent du/des représentant(s) légal/légaux)</span>
                        </p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px;">
                        <?php
                        $f_statut( 'eleve_urgence_statut', $extra_urgence['statut'] ?? '' );
                        $f2( 'Nom',       'eleve_urgence_nom',       $edit->urgence_nom       ?? '' );
                        $f2( 'Prénom',    'eleve_urgence_prenom',    $edit->urgence_prenom    ?? '' );
                        $f2( 'Téléphone', 'eleve_urgence_telephone', $edit->urgence_telephone ?? '' );
                        $f2( 'Email',     'eleve_urgence_email',     $edit->urgence_email     ?? '', 'email' );
                        ?>
                        </div>
                    </div>

                </div>
                </div>

                <!-- Champs RENFO (pleine largeur) -->
                <div style="max-width:860px;margin:18px 0 6px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
                    <p style="margin:0 0 12px;font-weight:700;font-size:13px;color:#374151;">🏋️ Informations physiques &amp; administratives</p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px;">
                    <?php
                    $f3 = function($label,$name,$val,$type='text') {
                        echo '<div style="margin-bottom:10px;">';
                        echo '<label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">'.esc_html($label).'</label>';
                        echo '<input type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($val).'" class="regular-text" style="width:100%;">';
                        echo '</div>';
                    };
                    $f3('Lieu de naissance', 'eleve_lieu_naissance', $edit->lieu_naissance ?? '');
                    $f3('Nationalité',        'eleve_nationalite',    $edit->nationalite    ?? '');
                    $f3('Taille (cm)',        'eleve_taille_cm',      $edit->taille_cm      ?? '');
                    $f3('Poids (kg)',          'eleve_poids_kg',       $edit->poids_kg       ?? '');
                    $f3('Pointure',            'eleve_pointure',       $edit->pointure       ?? '');
                    $f3('Taille t-shirt',     'eleve_taille_tshirt',  $edit->taille_tshirt  ?? '');
                    $f3('Taille pantalon',    'eleve_taille_pantalon',$edit->taille_pantalon?? '');
                    ?>
                    </div><!-- fin grille -->

                    <!-- Champs éligibilité Dan (hors grille) -->
                    <?php if ( in_array($edit->categorie_age ?? '', ['Ado/adulte', 'Adulte']) ) :
                        $saison_dan = get_option('tkd_saison_courante','2025/2026');
                        // Récupérer toutes les dates d'examen Dan du calendrier pour la saison
                        // Calcul éligibilité : 14 ans révolus au 30 juin de l'année de fin de saison
                        // Saison ex: 2025/2026 → date référence = 30/06/2026
                        $annee_fin_saison = intval( explode('/', $saison_dan)[1] ?? date('Y') );
                        $ts_ref = mktime(0, 0, 0, 6, 30, $annee_fin_saison); // 30 juin

                        $cond_age_ok     = false;
                        $date_eligible   = ''; // date à laquelle l'élève aura 14 ans
                        if ( ! empty($edit->date_naissance) && ! empty($edit->annee_naissance) ) {
                            $parts = explode('/', $edit->date_naissance);
                            if ( count($parts) >= 2 ) {
                                $ts_naiss = mktime(0, 0, 0, intval($parts[1]), intval($parts[0]), intval($edit->annee_naissance));
                                $age_au_30juin = (int) floor(($ts_ref - $ts_naiss) / (365.25 * 24 * 3600));
                                $cond_age_ok = ($age_au_30juin >= 14);
                                // Date à laquelle l'élève aura 14 ans
                                $ts_14ans = mktime(0, 0, 0, intval($parts[1]), intval($parts[0]), intval($edit->annee_naissance) + 14);
                                $date_eligible = date('d/m/Y', $ts_14ans);
                            }
                        }
                        $cond_licences = intval($edit->nb_licences ?? 0) >= 3;
                        $auto_eligible = $cond_age_ok && $cond_licences;
                    ?>
                    <div class="sp-field-row" style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;margin-top:8px;">
                        <div style="font-weight:700;font-size:13px;margin-bottom:12px;">🥋 Éligibilité 1e Dan</div>

                        <!-- Nb licences -->
                        <div style="margin-bottom:10px;">
                            <label style="font-size:13px;">
                                Nb de licences :
                                <input type="number" name="eleve_nb_licences" min="0" max="99"
                                       value="<?php echo intval($edit->nb_licences ?? 0); ?>"
                                       style="width:60px;margin-left:6px;padding:4px 8px;border:1px solid #ddd;border-radius:4px;">
                            </label>
                            <span style="margin-left:10px;font-size:12px;">
                                <?php echo $cond_licences
                                    ? '<span style="color:green;">✅ '.intval($edit->nb_licences).' licences ≥ 3 requises</span>'
                                    : '<span style="color:#dc2626;">❌ '.intval($edit->nb_licences ?? 0).' licence(s) sur 3 requises</span>'; ?>
                            </span>
                        </div>

                        <!-- Éligibilité âge -->
                        <div style="margin-bottom:10px;font-size:12px;">
                            <?php
                            // Comparer date_eligible avec 30 juin pour déterminer le cas
                            $ts_14ans_val = isset($ts_14ans) ? $ts_14ans : 0;
                            $est_eligible_cette_saison = $cond_age_ok; // 14 ans avant le 30 juin
                            $atteint_cette_saison = $ts_14ans_val > 0
                                && $ts_14ans_val <= $ts_ref
                                && $ts_14ans_val > mktime(0,0,0,9,1,$annee_fin_saison - 1);
                            ?>
                            <?php if ( ! $date_eligible ) : ?>
                                <span style="color:#999;">Date de naissance manquante</span>
                            <?php elseif ( $est_eligible_cette_saison && ! $atteint_cette_saison ) : ?>
                                <span style="color:green;">✅ 14 ans révolus depuis le <?php echo $date_eligible; ?> — Éligible</span>
                            <?php elseif ( $est_eligible_cette_saison && $atteint_cette_saison ) : ?>
                                <span style="color:#f0a500;">⚠️ Éligible à partir du <?php echo $date_eligible; ?> — Vérifiez la date de l'examen</span>
                            <?php else : ?>
                                <span style="color:#dc2626;">❌ Éligible à partir du <?php echo $date_eligible; ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Résultat automatique -->
                        <div style="background:<?php echo $auto_eligible ? '#f0fdf4' : '#fef2f2'; ?>;border:1px solid <?php echo $auto_eligible ? '#86efac' : '#fca5a5'; ?>;border-radius:6px;padding:8px 12px;margin-bottom:10px;font-size:12px;">
                            <?php if ($auto_eligible) : ?>
                                <strong style="color:green;">→ Éligible au 1e Dan cette saison</strong>
                            <?php elseif (!$cond_licences) : ?>
                                <strong style="color:#dc2626;">→ Non éligible (licences insuffisantes)</strong>
                            <?php elseif (!$cond_age_ok) : ?>
                                <strong style="color:#dc2626;">→ Non éligible (âge insuffisant)</strong>
                            <?php else : ?>
                                <strong style="color:#999;">→ Données manquantes</strong>
                            <?php endif; ?>
                        </div>

                        <!-- Override manuel -->
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
                            <input type="checkbox" name="eleve_eligible_dan" value="1"
                                   <?php checked(!empty($edit->eligible_dan)); ?>>
                            <span>✅ <strong>Éligible 1e Dan</strong> <span style="font-weight:normal;color:#666;">(modifiable manuellement)</span></span>
                        </label>
                    </div>
                    <?php endif; ?>
                    <!-- Adresse (pleine largeur) -->
                    <div style="margin-bottom:10px;">
                        <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">Adresse</label>
                        <input type="text" name="eleve_adresse" value="<?php echo esc_attr($edit->adresse ?? ''); ?>" class="regular-text" style="width:100%;">
                    </div>
                    <!-- Autorisation photos/vidéos (prise de vue — distincte de la diffusion ci-dessous) -->
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;margin-bottom:8px;">
                        <input type="checkbox" name="eleve_autorisation_photo" value="1" <?php checked( ! empty( $extra['autorisation_photo'] ) ); ?>>
                        <span>📷 Autorise la prise de photos/vidéos lors des activités du club</span>
                    </label>
                    <!-- Droit à l'image (diffusion) -->
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;margin-bottom:8px;">
                        <input type="checkbox" name="eleve_droit_image" value="1" <?php checked(intval($edit->droit_image ?? 1), 1); ?>>
                        <span>✅ Droit à l'image accordé <span style="font-weight:400;color:#888;">(diffusion sur les supports du club)</span></span>
                    </label>
                    <!-- Autorisation repartir seul(e) -->
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
                        <input type="checkbox" name="eleve_autorisation_seul" value="1" <?php checked(intval($edit->autorisation_seul ?? 0), 1); ?>>
                        <span>🚶 Autorisé(e) à repartir seul(e) après les entraînements</span>
                    </label>
                </div>

                <!-- Palmarès (pleine largeur) -->
                <div style="max-width:860px;margin-bottom:12px;">
                    <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">Palmarès</label>
                    <textarea name="eleve_palmares" rows="3" style="width:100%;font-family:inherit;"
                              placeholder="Compétitions, podiums, titres…"><?php echo esc_textarea($edit->palmares ?? ''); ?></textarea>
                </div>

                <?php if ( $extra_grades ) : ?>
                <!-- Historique des grades (lecture seule, issu de l'import CSV) -->
                <div style="max-width:860px;margin-bottom:16px;">
                    <label style="display:block;font-weight:600;font-size:12px;margin-bottom:6px;">📋 Historique grades (import CSV)</label>
                    <table class="wp-list-table widefat fixed striped" style="width:auto;min-width:400px;">
                        <thead><tr><th>Date examen</th><th>Grade obtenu</th></tr></thead>
                        <tbody>
                        <?php foreach ( $extra_grades as $date => $grade_val ) : ?>
                            <tr><td class="sp-muted"><?php echo esc_html($date); ?></td><td><strong><?php echo esc_html($grade_val); ?></strong></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Photo de profil -->
                <div style="max-width:860px;margin:18px 0 12px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
                    <p style="margin:0 0 12px;font-weight:700;font-size:13px;color:#374151;">🖼️ Photo de profil (carte de membre)</p>
                    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                        <div id="sp-eleve-photo-preview" style="width:80px;height:80px;border-radius:50%;overflow:hidden;background:#e5e7eb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <?php if ( ! empty($edit->photo_url) ) : ?>
                                <img src="<?php echo esc_url($edit->photo_url); ?>" style="width:100%;height:100%;object-fit:cover;" id="sp-eleve-photo-img">
                            <?php else : ?>
                                <span id="sp-eleve-photo-placeholder" style="font-size:28px;color:#9ca3af;">👤</span>
                                <img src="" style="width:100%;height:100%;object-fit:cover;display:none;" id="sp-eleve-photo-img">
                            <?php endif; ?>
                        </div>
                        <div>
                            <input type="hidden" name="eleve_photo_url" id="eleve_photo_url" value="<?php echo esc_attr($edit->photo_url ?? ''); ?>">
                            <button type="button" class="button" id="sp-eleve-photo-btn">📁 Choisir une photo</button>
                            <button type="button" class="button" id="sp-eleve-photo-clear"
                                    style="margin-left:6px;color:#dc2626;<?php echo empty($edit->photo_url) ? 'display:none;' : ''; ?>">
                                ✕ Supprimer
                            </button>
                        </div>
                    </div>
                    <script>
                    (function($){
                        var frame;
                        $('#sp-eleve-photo-btn').on('click', function(e){
                            e.preventDefault();
                            if (frame) { frame.open(); return; }
                            frame = wp.media({ title: 'Choisir une photo', button: { text: 'Utiliser cette photo' }, multiple: false, library: { type: 'image' } });
                            frame.on('select', function(){
                                var att = frame.state().get('selection').first().toJSON();
                                $('#eleve_photo_url').val(att.url);
                                $('#sp-eleve-photo-img').attr('src', att.url).show();
                                $('#sp-eleve-photo-placeholder').hide();
                                $('#sp-eleve-photo-clear').show();
                            });
                            frame.open();
                        });
                        $('#sp-eleve-photo-clear').on('click', function(){
                            $('#eleve_photo_url').val('');
                            $('#sp-eleve-photo-img').attr('src','').hide();
                            $('#sp-eleve-photo-placeholder').show();
                            $(this).hide();
                        });
                    }(jQuery));
                    </script>
                </div>

                <!-- Pass'Sport, CAF & Documents — ajouté 09/09/2026, présents sur le formulaire
                     public depuis début septembre mais jusqu'ici invisibles/non modifiables ici -->
                <div style="max-width:860px;margin:18px 0 12px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
                    <p style="margin:0 0 12px;font-weight:700;font-size:13px;color:#374151;">💳 Pass'Sport, CAF &amp; documents</p>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px;margin-bottom:6px;">
                        <div style="margin-bottom:10px;">
                            <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">Code Pass'Sport</label>
                            <input type="text" name="eleve_pass_sport_code" value="<?php echo esc_attr( $extra['pass_sport_code'] ?? '' ); ?>" class="regular-text" style="width:100%;">
                        </div>
                        <div style="margin-bottom:10px;">
                            <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">Date du certificat médical</label>
                            <input type="date" name="eleve_date_certificat_medical" value="<?php echo esc_attr( $extra['date_certificat_medical'] ?? '' ); ?>" style="width:100%;">
                        </div>
                    </div>

                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;margin-bottom:14px;">
                        <input type="checkbox" name="eleve_qs_sport_confirme" value="1" <?php checked( ! empty( $extra['qs_sport_confirme'] ) ); ?>>
                        <span>🩺 Questionnaire de santé QS-Sport confirmé <span style="font-weight:400;color:#888;">(Renforcement musculaire, réponses négatives à toutes les questions)</span></span>
                    </label>

                    <?php
                    // Un champ "document" = hidden URL + bouton médiathèque + lien "voir" + bouton
                    // supprimer, sur le même principe que la photo de profil ci-dessus, mais sans
                    // aperçu image (souvent des PDF) — juste un lien de consultation.
                    $doc_field = function( $label, $field_id, $post_name, $url ) {
                        ?>
                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;"><?php echo esc_html( $label ); ?></label>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <input type="hidden" name="<?php echo esc_attr( $post_name ); ?>" id="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $url ); ?>">
                                <button type="button" class="button sp-eleve-doc-btn" data-target="<?php echo esc_attr( $field_id ); ?>">📁 <?php echo $url ? 'Remplacer' : 'Choisir un fichier'; ?></button>
                                <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" class="sp-eleve-doc-link" id="<?php echo esc_attr( $field_id ); ?>_link" style="<?php echo $url ? '' : 'display:none;'; ?>">📄 Voir le document</a>
                                <button type="button" class="button sp-eleve-doc-clear" data-target="<?php echo esc_attr( $field_id ); ?>" style="color:#dc2626;<?php echo $url ? '' : 'display:none;'; ?>">✕ Supprimer</button>
                            </div>
                        </div>
                        <?php
                    };
                    $doc_field( 'Certificat médical',                 'eleve_doc_certificat_medical', 'eleve_doc_certificat_medical', $extra_documents['certificat_medical'] ?? '' );
                    $doc_field( "Attestation de responsabilité civile", 'eleve_doc_attestation_rc',     'eleve_doc_attestation_rc',     $extra_documents['attestation_rc']     ?? '' );
                    $doc_field( "Décharge sur l'honneur",              'eleve_doc_decharge_honneur',   'eleve_doc_decharge_honneur',   $extra_documents['decharge_honneur']   ?? '' );
                    $doc_field( 'Bon CAF',                             'eleve_doc_bon_caf',            'eleve_doc_bon_caf',            $extra_documents['bon_caf']            ?? '' );
                    ?>
                    <script>
                    (function($){
                        var frame;
                        $('.sp-eleve-doc-btn').on('click', function(e){
                            e.preventDefault();
                            var targetId = $(this).data('target');
                            var $input   = $('#' + targetId);
                            var $link    = $('#' + targetId + '_link');
                            var $clear   = $('.sp-eleve-doc-clear[data-target="' + targetId + '"]');
                            var $btn     = $(this);
                            var f = wp.media({ title: 'Choisir un document', button: { text: 'Utiliser ce fichier' }, multiple: false });
                            f.on('select', function(){
                                var att = f.state().get('selection').first().toJSON();
                                $input.val(att.url);
                                $link.attr('href', att.url).show();
                                $clear.show();
                                $btn.text('📁 Remplacer');
                            });
                            f.open();
                        });
                        $('.sp-eleve-doc-clear').on('click', function(){
                            var targetId = $(this).data('target');
                            $('#' + targetId).val('');
                            $('#' + targetId + '_link').hide();
                            $('.sp-eleve-doc-btn[data-target="' + targetId + '"]').text('📁 Choisir un fichier');
                            $(this).hide();
                        });
                    }(jQuery));
                    </script>
                </div>

                <p>
                    <input type="submit" name="sp_cal_save_eleve" class="button button-primary"
                           value="<?php echo $edit ? 'Mettre à jour' : 'Ajouter'; ?>">
                    <?php if ( $edit ) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-eleves')); ?>" class="button" style="margin-left:8px;">Annuler</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . intval($edit->id))); ?>" class="button" style="margin-left:8px;">👁️ Voir la fiche complète</a>
                        <?php if ( ! empty($edit->token) && $this->token ) : ?>
                        <a href="<?php echo esc_url( $this->token->get_fiche_url( $edit->token ) ); ?>" class="button" target="_blank" style="margin-left:8px;">🌐 Prévisualiser page élève</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </form>

            <?php if ( $edit ) :
                // Examens liés à cet élève
                $edit_examens     = $this->db->get_examens_eleve( intval($edit->id) );
                $edit_exam_dispo  = $this->db->get_examens_disponibles( intval($edit->id) );
            ?>
            <!-- Passages de grade (visible depuis l'édition) -->
            <?php
            // Récupérer l'historique CSV de l'élève en édition
            $edit_extra      = $edit->extra_data ? ( json_decode( $edit->extra_data, true ) ?: array() ) : array();
            $edit_grades_hist= $edit_extra['grades'] ?? array();
            // Chronologie unifiée
            $edit_timeline = array();
            foreach ( $edit_examens as $ex ) {
                $edit_timeline[] = array(
                    'date_raw'  => $ex->date,
                    'date_ts'   => $ex->date ? strtotime($ex->date) : 0,
                    'source'    => 'calendrier',
                    'titre'     => $ex->titre,
                    'categorie' => $ex->categorie,
                    'grade'     => $ex->note,
                    'event_id'  => $ex->event_id,
                );
            }
            foreach ( $edit_grades_hist as $date_ex => $grade_val ) {
                $parts = explode('/', $date_ex);
                $ts    = count($parts) === 3 ? mktime(0,0,0, intval($parts[1]), intval($parts[0]), intval($parts[2])) : 0;
                $edit_timeline[] = array(
                    'date_raw'  => $date_ex,
                    'date_ts'   => $ts,
                    'source'    => 'csv',
                    'titre'     => '',
                    'categorie' => '',
                    'grade'     => $grade_val,
                    'event_id'  => null,
                );
            }
            usort($edit_timeline, function($a, $b){ return $b['date_ts'] - $a['date_ts']; });
            ?>
            <div style="border-top:1px solid #f0f0f1;padding-top:16px;margin-top:4px;"
                 id="sp-fiche-examens-box"
                 data-eleve-id="<?php echo intval($edit->id); ?>">
                <h3 style="margin:0 0 12px;font-size:14px;">🎓 Passages de grade</h3>

                <?php if ( ! empty($edit_timeline) ) : ?>
                <table class="wp-list-table widefat striped" id="sp-fiche-examens-table" style="margin-bottom:12px;">
                    <thead><tr>
                        <th style="width:100px;">Date</th>
                        <th>Événement</th>
                        <th>Grade obtenu</th>
                        <th style="width:40px;text-align:center;"></th>
                        <th style="width:40px;"></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($edit_timeline as $trow) :
                        if ( $trow['source'] === 'calendrier' ) {
                            $d_obj = $trow['date_raw'] ? date_create($trow['date_raw']) : null;
                            $dl    = $d_obj ? $d_obj->format('d/m/Y') : $trow['date_raw'];
                        } else {
                            $dl = $trow['date_raw'];
                        }
                        $tgrade     = $trow['grade'];
                        $is_current = ($tgrade !== '' && $tgrade !== null && $tgrade === $edit->grade);
                    ?>
                    <tr <?php echo $is_current ? 'style="background:#f0fdf4;"' : ''; ?>
                        <?php if($trow['event_id']) echo 'id="sp-exam-row-' . intval($trow['event_id']) . '"'; ?>>
                        <td><strong><?php echo esc_html($dl); ?></strong></td>
                        <td>
                            <?php if ( $trow['source'] === 'calendrier' ) : ?>
                                <?php echo esc_html($trow['titre']); ?>
                                <?php if($trow['categorie'] && $trow['categorie'] !== $trow['titre']): ?>
                                    <span class="sp-badge-blue"><?php echo esc_html($trow['categorie']); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="sp-muted" style="font-size:11px;">📋 Import CSV</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tgrade) : ?>
                                <span class="sp-fiche-grade-badge" style="font-size:12px;padding:3px 10px;"><?php echo esc_html($tgrade); ?></span>
                                <?php if($is_current) echo ' <span class="sp-badge-green" style="margin-left:4px;">actuel</span>'; ?>
                            <?php elseif ($trow['source'] === 'calendrier') : ?>
                                <span class="sp-muted">Candidat</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;font-size:13px;"><?php echo $trow['source'] === 'calendrier' ? '📅' : '📋'; ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($trow['source'] === 'calendrier' && $trow['event_id']) : ?>
                            <button type="button" class="button button-small sp-btn-unlink-exam sp-btn-del"
                                    data-event-id="<?php echo intval($trow['event_id']); ?>"
                                    title="Délier">✕</button>
                            <?php elseif ($trow['source'] === 'csv') : ?>
                            <button type="button" class="button button-small sp-btn-edit-csv-grade"
                                    data-date-key="<?php echo esc_attr($trow['date_raw']); ?>"
                                    data-grade="<?php echo esc_attr($trow['grade']); ?>"
                                    title="Modifier">✏️</button>
                            <button type="button" class="button button-small sp-btn-del sp-btn-delete-csv-grade"
                                    data-date-key="<?php echo esc_attr($trow['date_raw']); ?>"
                                    title="Supprimer">🗑️</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else : ?>
                <p class="sp-muted" id="sp-examens-empty-msg">Aucun passage de grade lié à cet élève.</p>
                <?php endif; ?>

                <!-- Lier à un examen -->
                <?php if ( ! empty($edit_exam_dispo) ) : ?>
                <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:8px;">
                    <div>
                        <label style="display:block;font-size:11px;color:#6b7280;margin-bottom:3px;">Examen disponible</label>
                        <select id="sp-link-exam-select" style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;min-width:260px;">
                            <option value="">— Choisir —</option>
                            <?php foreach ($edit_exam_dispo as $ev) :
                                $d2 = $ev->date ? date_create($ev->date) : null;
                                $dl2= $d2 ? $d2->format('d/m/Y') : $ev->date;
                            ?>
                            <option value="<?php echo intval($ev->id); ?>">
                                <?php echo esc_html($dl2 . ' — ' . $ev->titre . ($ev->categorie && $ev->categorie !== $ev->titre ? ' ('.$ev->categorie.')' : '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:#6b7280;margin-bottom:3px;">Grade obtenu <small>(optionnel)</small></label>
                        <input type="text" id="sp-link-exam-grade" placeholder="ex: 6° bleue"
                               style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;width:150px;">
                    </div>
                    <button type="button" id="sp-link-exam-btn" class="button button-primary"
                            data-eleve-id="<?php echo intval($edit->id); ?>"
                            style="height:32px;">Lier</button>
                    <span id="sp-link-exam-msg" style="font-size:12px;color:#15803d;display:none;"></span>
                </div>
                <?php else : ?>
                <p class="sp-muted" style="margin-top:6px;">
                    Tous les examens existants sont déjà liés, ou aucun examen n'a été créé.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-pro')); ?>">Créer un événement "🎓 Examen" dans le calendrier →</a>
                </p>
                <?php endif; ?>
            </div>

            <!-- Résultats de compétitions (visible depuis l'édition) -->
            <?php
            $edit_palmares = $this->db->get_palmares_eleve( intval($edit->id) );
            $edit_medal_pts= $this->db->get_medal_points();
            $edit_med_icons = array('or'=>'🥇','argent'=>'🥈','bronze'=>'🥉');
            $edit_pal_by_comp = array();
            foreach ( $edit_palmares as $pr ) {
                $cid = intval($pr->event_id);
                if ( ! isset($edit_pal_by_comp[$cid]) ) {
                    $edit_pal_by_comp[$cid] = array(
                        'titre'    => $pr->comp_titre,
                        'date'     => $pr->date,
                        'epreuves' => array(),
                    );
                }
                $edit_pal_by_comp[$cid]['epreuves'][] = $pr;
            }
            ?>
            <?php if ( ! empty($edit_pal_by_comp) ) : ?>
            <div style="border-top:1px solid #f0f0f1;padding-top:16px;margin-top:16px;">
                <h3 style="margin:0 0 10px;font-size:14px;">🏆 Palmarès compétitions</h3>
                <div style="overflow-x:auto;">
                <table class="wp-list-table widefat striped sp-palmares-compact-table" style="font-size:12px;">
                    <thead><tr>
                        <th style="width:90px;">Date</th>
                        <th>Compétition</th>
                        <th>Épreuve</th>
                        <th style="width:80px;">Modalité</th>
                        <th style="width:90px;">Résultat</th>
                        <th style="width:40px;text-align:right;">Pts</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $edit_pal_by_comp as $cid => $comp_data ) :
                        $nb_ep  = count($comp_data['epreuves']);
                        $ep_idx = 0;
                        $cd_obj = $comp_data['date'] ? date_create($comp_data['date']) : null;
                        $cd_f   = $cd_obj ? $cd_obj->format('d/m/Y') : '';
                        foreach ( $comp_data['epreuves'] as $ep_r ) :
                            $med    = $ep_r->medaille ?: '';
                            $ep_pts = ($med && isset($edit_medal_pts[$med])) ? $edit_medal_pts[$med] : 0;
                    ?>
                    <tr>
                        <?php if ($ep_idx === 0) : ?>
                        <td rowspan="<?php echo $nb_ep; ?>" style="vertical-align:top;padding-top:9px;">
                            <strong style="font-size:11px;"><?php echo esc_html($cd_f); ?></strong>
                        </td>
                        <td rowspan="<?php echo $nb_ep; ?>" style="vertical-align:top;padding-top:9px;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-palmares&vue=competition&event_id='.$cid)); ?>"
                               style="font-weight:600;font-size:12px;" target="_blank">
                                <?php echo esc_html($comp_data['titre']); ?>
                            </a>
                        </td>
                        <?php endif; ?>
                        <td><?php echo esc_html($ep_r->epreuve_nom); ?></td>
                        <td class="sp-muted" style="font-size:11px;"><?php echo $ep_r->epreuve_modalite ? esc_html($ep_r->epreuve_modalite) : '—'; ?></td>
                        <td>
                            <?php if ($med) : ?>
                                <span class="sp-palm-medal-chip sp-palm-medal-<?php echo esc_attr($med); ?>"
                                      style="font-size:11px;padding:1px 7px;">
                                    <?php echo $edit_med_icons[$med]; ?> <?php echo ucfirst($med); ?>
                                </span>
                            <?php elseif ($ep_r->score) : ?>
                                <span class="sp-muted"><?php echo esc_html($ep_r->score); ?></span>
                            <?php else : ?>
                                <span class="sp-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;font-weight:700;color:<?php echo $ep_pts > 0 ? '#15803d' : '#9ca3af'; ?>;">
                            <?php echo $ep_pts > 0 ? '+' . $ep_pts : '—'; ?>
                        </td>
                    </tr>
                    <?php $ep_idx++; endforeach; endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p style="margin:6px 0 0;font-size:11px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-palmares')); ?>" style="color:#6b7280;">
                        → Gérer le palmarès
                    </a>
                </p>
            </div>
            <?php endif; ?>

            <?php endif; // $edit ?>
        </div><!-- /.sp-box formulaire élève -->
        </div><!-- /.sp-modal-panel -->
        </div><!-- /.sp-modal-overlay -->

        <style>
        .sp-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto;}
        .sp-modal-panel{position:relative;background:#fff;border-radius:10px;max-width:920px;width:100%;padding:8px;box-shadow:0 20px 60px rgba(0,0,0,.3);}
        .sp-modal-close{position:absolute;top:10px;right:10px;background:#fff;border:1px solid #d1d5db;border-radius:50%;width:32px;height:32px;font-size:16px;line-height:1;cursor:pointer;z-index:2;}
        .sp-modal-close:hover{background:#f3f4f6;}
        </style>
        <script>
        (function(){
            var overlay  = document.getElementById('sp-eleve-form-modal-overlay');
            var openBtn  = document.getElementById('sp-eleve-add-btn');
            var closeBtn = document.getElementById('sp-eleve-form-modal-close');
            if ( openBtn ) openBtn.addEventListener('click', function(){ overlay.style.display = 'flex'; });
            if ( closeBtn ) closeBtn.addEventListener('click', function(){ overlay.style.display = 'none'; });
            overlay.addEventListener('click', function(e){ if ( e.target === overlay ) overlay.style.display = 'none'; });
            document.addEventListener('keydown', function(e){ if ( e.key === 'Escape' && overlay.style.display !== 'none' ) overlay.style.display = 'none'; });
        })();
        </script>

        <!-- LISTE ÉLÈVES -->
        <div class="sp-box">
            <h2><span class="dashicons dashicons-list-view"></span> Adhérents (<span id="sp-eleves-total"><?php echo count( $eleves ); ?></span>)
                <a href="<?php echo esc_url( wp_nonce_url(
                    add_query_arg(array('sp_cal_print'=>'liste_appel','saison'=>($saisons[0]??'')), home_url('/') ),
                    'sp_cal_print'
                ) ); ?>" target="_blank" class="button button-small" style="margin-left:12px;font-size:12px;">
                    🖨️ Liste d'appel PDF
                </a>
                <button type="button" id="sp-csv-export-toggle" class="button button-small" style="margin-left:6px;font-size:12px;">
                    ⬇️ Export CSV
                </button>
            </h2>

            <!-- Export CSV — sélection des champs -->
            <?php
            $csv_champs = $this->csv_eleves_champs();
            $csv_defaut = array( 'nom', 'prenom', 'categorie_saisie', 'categorie_age', 'grade', 'date_naissance', 'licence' );
            ?>
            <div id="sp-csv-export-panel" class="sp-box" style="display:none;background:#f8fafc;margin-bottom:14px;">
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                    <input type="hidden" name="page" value="sp-cal-eleves">
                    <input type="hidden" name="sp_export_eleves_csv" value="1">
                    <?php wp_nonce_field( 'sp_cal_export_eleves_csv' ); ?>
                    <p style="margin-top:0;font-weight:600;font-size:13px;">Choisir les colonnes à exporter :</p>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:6px 16px;">
                        <?php foreach ( $csv_champs as $key => $label ) : ?>
                        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="champs[]" value="<?php echo esc_attr($key); ?>" <?php checked( in_array($key, $csv_defaut, true) ); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p style="margin-bottom:0;">
                        <a href="#" id="sp-csv-all">Tout cocher</a>
                        <a href="#" id="sp-csv-none" style="margin-left:10px;">Tout décocher</a>
                        <button type="submit" class="button button-primary button-small" style="margin-left:16px;">⬇️ Générer le CSV</button>
                    </p>
                </form>
            </div>
            <script>
            (function(){
                var toggle = document.getElementById('sp-csv-export-toggle');
                var panel  = document.getElementById('sp-csv-export-panel');
                if (toggle && panel) toggle.addEventListener('click', function(){
                    panel.style.display = panel.style.display === 'none' ? '' : 'none';
                });
                var all  = document.getElementById('sp-csv-all');
                var none = document.getElementById('sp-csv-none');
                function setAll(checked){
                    document.querySelectorAll('#sp-csv-export-panel input[type=checkbox]').forEach(function(c){ c.checked = checked; });
                }
                if (all)  all.addEventListener('click',  function(e){ e.preventDefault(); setAll(true); });
                if (none) none.addEventListener('click', function(e){ e.preventDefault(); setAll(false); });
            })();
            </script>

            <!-- Filtres -->
            <div class="sp-filter-bar" style="flex-wrap:wrap;gap:8px;">
                <input type="text" id="sp-flt-name" placeholder="🔍 Nom / Prénom…" style="width:200px;">
                <select id="sp-flt-cat">
                    <option value="">— Catégorie d'âge —</option>
                    <?php foreach ( $cats as $c ) echo '<option value="' . esc_attr($c) . '">' . esc_html($c) . '</option>'; ?>
                </select>
                <select id="sp-flt-cat-saisie">
                    <option value="">— Discipline pratiquée —</option>
                    <?php foreach ( $cats_saisie as $c ) echo '<option value="' . esc_attr($c) . '">' . esc_html( $this->db->label_discipline($c) ) . '</option>'; ?>
                </select>
                <?php if ( ! empty( $saisons ) ) : ?>
                <select id="sp-flt-saison">
                    <option value="">— Toutes les saisons —</option>
                    <?php foreach ( $saisons as $s ) echo '<option value="' . esc_attr($s) . '">' . esc_html($s) . '</option>'; ?>
                </select>
                <?php endif; ?>
            </div>

            <?php if ( empty( $eleves ) ) : ?>
                <p class="sp-muted">Aucun élève. Utilisez l'import CSV ou ajoutez-en un ci-dessus.</p>
            <?php else : ?>
            <form method="post" id="sp-eleves-form">
                <?php wp_nonce_field( 'sp_cal_bulk_eleves' ); ?>

                <!-- Barre d'actions groupées (haut) — style natif WP -->
                <div class="sp-bulk-bar sp-bulk-bar-top">
                    <div class="sp-bulk-actions">
                        <select id="sp-bulk-action-top" class="sp-input" style="height:30px;min-width:200px;">
                            <option value="">— Actions groupées —</option>
                            <option value="send_token">📧 Envoyer le lien d'accès</option>
                            <option value="admis">✅ Admis(e) — passage de grade</option>
                            <option value="ajourne">➖ Ajourné(e)</option>
                            <option value="delete">🗑️ Supprimer</option>
                        </select>
                        <select id="sp-bulk-event-top" class="sp-input sp-bulk-event-select" style="height:30px;min-width:220px;display:none;">
                            <option value="">— Choisir l'examen —</option>
                            <?php foreach ( $examens_events as $ev ) :
                                $ev_lbl = ($ev->date ? date_create($ev->date)->format('d/m/Y') . ' — ' : '') . $ev->titre;
                            ?>
                            <option value="<?php echo intval($ev->id); ?>"><?php echo esc_html($ev_lbl); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button sp-bulk-apply-btn" data-target="top">
                            Appliquer
                        </button>
                        <span class="sp-bulk-count sp-muted" id="sp-bulk-count-top" style="font-size:12px;display:none;"></span>
                    </div>
                    <span class="sp-muted" style="font-size:12px;margin-left:auto;">
                        <?php echo count($eleves); ?> élève<?php echo count($eleves) > 1 ? 's' : ''; ?>
                    </span>
                </div>

                <!-- Champ caché : action finale soumise au PHP (rempli par JS avant submit) -->
                <input type="hidden" name="sp_bulk_action" id="sp-bulk-action-final" value="">

                <div style="overflow-x:auto;">
                <table class="wp-list-table widefat striped" id="sp-eleves-table">
                    <thead><tr>
                        <td class="check-column" style="width:36px;">
                            <input type="checkbox" id="sp-check-all-top" title="Tout sélectionner / désélectionner">
                        </td>
                        <th id="sp-sort-nom" style="cursor:pointer;user-select:none;" title="Trier par ordre alphabétique">Nom <span id="sp-sort-nom-ico" style="color:#9ca3af;">↕</span></th>
                        <th>Prénom</th>
                        <th>Discipline pratiquée</th>
                        <th>Cat. âge</th>
                        <th>Grade</th>
                        <th>Naissance</th>
                        <th>Saison</th>
                        <th>Licence</th>
                        <th style="width:110px;" title="Date du dernier envoi du lien d'accès">Lien envoyé</th>
                        <th style="width:80px;">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $eleves as $el ) :
                        $del      = wp_nonce_url( admin_url('admin.php?page=sp-cal-eleves&sp_delete_eleve='.$el->id), 'sp_delete_eleve_'.$el->id );
                        $edit_url = admin_url('admin.php?page=sp-cal-eleves&sp_edit_eleve='.$el->id);
                        $fiche_url = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id='.$el->id);
                        $has_palmares = ! empty( $el->palmares );
                        $nb_grades = 0;
                        if ( $el->extra_data ) {
                            $xd = json_decode($el->extra_data, true);
                            $nb_grades = count( $xd['grades'] ?? array() );
                        }
                        // Calcul dernier envoi token
                        $token_label = '<span class="sp-muted" style="font-size:11px;">jamais</span>';
                        $token_class = '';
                        if ( ! empty($el->token_sent_at) ) {
                            $sent_ts   = strtotime($el->token_sent_at);
                            $days_ago  = floor( (time() - $sent_ts) / 86400 );
                            if ($days_ago === 0)       $token_label = '<span style="color:#15803d;font-size:11px;font-weight:600;">Aujourd\'hui</span>';
                            elseif ($days_ago === 1)   $token_label = '<span style="color:#15803d;font-size:11px;">Hier</span>';
                            elseif ($days_ago <= 7)    $token_label = '<span style="color:#b45309;font-size:11px;">Il y a ' . $days_ago . 'j</span>';
                            else                       $token_label = '<span class="sp-muted" style="font-size:11px;">' . date_i18n('d/m/Y', $sent_ts) . '</span>';
                        }
                        // Peut recevoir un token ?
                        $has_email = ! empty($el->email) || ! empty($el->email_parent);
                    ?>
                    <tr data-name="<?php echo esc_attr(mb_strtolower($el->nom.' '.$el->prenom)); ?>"
                        data-cat="<?php echo esc_attr($el->categorie_age); ?>"
                        data-cat-saisie="<?php echo esc_attr($el->categorie_saisie); ?>"
                        data-saison="<?php echo esc_attr($el->saison); ?>"
                        data-has-email="<?php echo $has_email ? '1' : '0'; ?>">
                        <th class="check-column">
                            <input type="checkbox" name="sp_bulk_ids[]" value="<?php echo intval($el->id); ?>"
                                   <?php if (!$has_email) echo 'title="Aucun email — ne peut pas recevoir de lien" class=\'sp-chk-no-email\''; ?>>
                        </th>
                        <td><strong><?php echo esc_html($el->nom); ?></strong></td>
                        <td><?php echo esc_html($el->prenom); ?></td>
                        <td><?php if($el->categorie_saisie) echo '<span class="sp-badge-blue">' . esc_html( $this->db->label_discipline($el->categorie_saisie) ) . '</span>'; ?></td>
                        <td><?php if($el->categorie_age) echo '<span class="sp-badge-blue" style="background:#6366f1;color:#fff;">' . esc_html($el->categorie_age) . '</span>'; ?></td>
                        <td>
                            <?php echo esc_html($el->grade); ?>
                            <?php if($nb_grades) echo ' <span class="sp-muted" style="font-size:11px;" title="' . $nb_grades . ' examens importés">(' . $nb_grades . ' 📋)</span>'; ?>
                        </td>
                        <td class="sp-muted"><?php
                            echo esc_html( $el->date_naissance . ($el->annee_naissance ? '/' . $el->annee_naissance : '') );
                        ?></td>
                        <td class="sp-muted"><?php echo esc_html($el->saison); ?></td>
                        <td class="sp-mono"><?php
                            echo esc_html($el->licence);
                            if ( ! intval($el->actif ?? 1) ) {
                                echo ' <span class="sp-lic-badge sp-lic-expired" title="' . esc_attr($el->motif_inactif ?: 'Inactif') . '">❌</span>';
                            }
                        ?></td>
                        <td><?php echo $token_label; ?></td>
                        <td>
                            <a href="<?php echo esc_url($fiche_url); ?>" class="button button-small" title="Fiche">👁️</a>
                            <a href="<?php echo esc_url($edit_url); ?>" class="button button-small" title="Modifier">✏️</a>
                            <a href="<?php echo esc_url($del); ?>" class="button button-small sp-btn-del"
                               onclick="return confirm('Supprimer <?php echo esc_js($el->prenom . ' ' . $el->nom); ?> ?')" title="Supprimer">🗑️</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr>
                        <td class="check-column" style="width:36px;">
                            <input type="checkbox" id="sp-check-all-bottom" title="Tout sélectionner / désélectionner">
                        </td>
                        <th colspan="10">
                            <div class="sp-bulk-bar sp-bulk-bar-bottom" style="margin:0;border:none;padding:8px 0 0;">
                                <div class="sp-bulk-actions">
                                    <select id="sp-bulk-action-bottom" class="sp-input" style="height:30px;min-width:200px;">
                                        <option value="">— Actions groupées —</option>
                                        <option value="send_token">📧 Envoyer le lien d'accès</option>
                                        <option value="admis">✅ Admis(e) — passage de grade</option>
                                        <option value="ajourne">➖ Ajourné(e)</option>
                                        <option value="delete">🗑️ Supprimer</option>
                                    </select>
                                    <select id="sp-bulk-event-bottom" class="sp-input sp-bulk-event-select" style="height:30px;min-width:220px;display:none;">
                                        <option value="">— Choisir l'examen —</option>
                                        <?php foreach ( $examens_events as $ev ) :
                                            $ev_lbl = ($ev->date ? date_create($ev->date)->format('d/m/Y') . ' — ' : '') . $ev->titre;
                                        ?>
                                        <option value="<?php echo intval($ev->id); ?>"><?php echo esc_html($ev_lbl); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="button sp-bulk-apply-btn" data-target="bottom">
                                        Appliquer
                                    </button>
                                    <span class="sp-bulk-count sp-muted" id="sp-bulk-count-bottom" style="font-size:12px;display:none;"></span>
                                </div>
                            </div>
                        </th>
                    </tr></tfoot>
                </table>
                </div>

                <!-- Champs hidden pour soumission via JS -->
                <input type="hidden" name="sp_bulk_delete_eleves_trigger" id="sp-bulk-delete-trigger" value="">
                <input type="hidden" name="sp_bulk_event_id" id="sp-bulk-event-id-final" value="">
            </form>
            <?php endif; ?>
        </div>

        <script>
        (function(){
            /* ── Filtres ─────────────────────────────────────────── */
            var fName    = document.getElementById('sp-flt-name');
            var fCat     = document.getElementById('sp-flt-cat');
            var fCatS    = document.getElementById('sp-flt-cat-saisie');
            var fSaison  = document.getElementById('sp-flt-saison');
            var rows     = document.querySelectorAll('#sp-eleves-table tbody tr');

            function updateTotal() {
                var el = document.getElementById('sp-eleves-total');
                if (!el) return;
                var n = Array.prototype.filter.call(rows, function(r){ return r.style.display !== 'none'; }).length;
                el.textContent = n;
            }

            function applyFilter() {
                var name  = fName  ? fName.value.toLowerCase()  : '';
                var cat   = fCat   ? fCat.value                 : '';
                var catS  = fCatS  ? fCatS.value                : '';
                var saison= fSaison? fSaison.value              : '';
                rows.forEach(function(r){
                    var ok = ( !name   || r.dataset.name.indexOf(name)    !== -1 )
                          && ( !cat    || r.dataset.cat          === cat   )
                          && ( !catS   || r.dataset.catSaisie    === catS  )
                          && ( !saison || r.dataset.saison       === saison);
                    r.style.display = ok ? '' : 'none';
                });
                updateTotal();
                updateCount();
            }
            if(fName)   fName.addEventListener('input',   applyFilter);
            if(fCat)    fCat.addEventListener('change',   applyFilter);
            if(fCatS)   fCatS.addEventListener('change',  applyFilter);
            if(fSaison) fSaison.addEventListener('change',applyFilter);

            /* ── Tri alphabétique (clic sur l'en-tête "Nom") ──────── */
            var sortTh  = document.getElementById('sp-sort-nom');
            var sortIco = document.getElementById('sp-sort-nom-ico');
            var sortDir = null; // null = ordre par défaut (rang), 'asc', 'desc'
            if (sortTh) {
                sortTh.addEventListener('click', function(){
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                    sortIco.textContent = sortDir === 'asc' ? '▲' : '▼';
                    var tbody   = document.querySelector('#sp-eleves-table tbody');
                    var sorted  = Array.prototype.slice.call(rows).sort(function(a, b){
                        var cmp = a.dataset.name.localeCompare(b.dataset.name, 'fr');
                        return sortDir === 'asc' ? cmp : -cmp;
                    });
                    sorted.forEach(function(r){ tbody.appendChild(r); });
                });
            }

            /* ── Cases à cocher ─────────────────────────────────── */
            function getVisibleCheckboxes(){
                return Array.from(document.querySelectorAll('#sp-eleves-table tbody tr'))
                    .filter(function(r){ return r.style.display !== 'none'; })
                    .map(function(r){ return r.querySelector('input[type=checkbox]'); })
                    .filter(Boolean);
            }

            function syncCheckAll(){
                var cbs    = getVisibleCheckboxes();
                var checked= cbs.filter(function(c){ return c.checked; }).length;
                ['sp-check-all-top','sp-check-all-bottom'].forEach(function(id){
                    var ca = document.getElementById(id);
                    if(!ca) return;
                    ca.checked       = checked > 0 && checked === cbs.length;
                    ca.indeterminate = checked > 0 && checked < cbs.length;
                });
                updateCount();
            }

            function updateCount(){
                var n = getVisibleCheckboxes().filter(function(c){ return c.checked; }).length;
                ['sp-bulk-count-top','sp-bulk-count-bottom'].forEach(function(id){
                    var el = document.getElementById(id);
                    if(!el) return;
                    if(n > 0){
                        el.textContent = n + ' sélectionné' + (n > 1 ? 's' : '');
                        el.style.display = '';
                    } else {
                        el.style.display = 'none';
                    }
                });
            }

            ['sp-check-all-top','sp-check-all-bottom'].forEach(function(id){
                var ca = document.getElementById(id);
                if(!ca) return;
                ca.addEventListener('change', function(){
                    getVisibleCheckboxes().forEach(function(c){ c.checked = ca.checked; });
                    // Synchroniser l'autre checkbox d'en-tête
                    ['sp-check-all-top','sp-check-all-bottom'].forEach(function(oid){
                        var o = document.getElementById(oid);
                        if(o && o !== ca) o.checked = ca.checked;
                    });
                    updateCount();
                });
            });

            document.querySelectorAll('#sp-eleves-table tbody input[type=checkbox]').forEach(function(c){
                c.addEventListener('change', syncCheckAll);
            });

            /* ── Afficher le sélecteur d'examen pour admis/ajourné ── */
            ['top','bottom'].forEach(function(target){
                var actionSel = document.getElementById('sp-bulk-action-' + target);
                var eventSel  = document.getElementById('sp-bulk-event-' + target);
                if (!actionSel || !eventSel) return;
                actionSel.addEventListener('change', function(){
                    eventSel.style.display = (actionSel.value === 'admis' || actionSel.value === 'ajourne') ? '' : 'none';
                });
            });

            /* ── Boutons "Appliquer" ─────────────────────────────── */
            document.querySelectorAll('.sp-bulk-apply-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var target  = btn.dataset.target;
                    var selId   = target === 'top' ? 'sp-bulk-action-top' : 'sp-bulk-action-bottom';
                    var action  = document.getElementById(selId).value;
                    if(!action){ alert('Choisissez une action dans le menu déroulant.'); return; }

                    var checked = getVisibleCheckboxes().filter(function(c){ return c.checked; });
                    if(!checked.length){ alert('Sélectionnez au moins un élève.'); return; }

                    if(action === 'send_token'){
                        var noEmail = checked.filter(function(c){
                            return c.closest('tr').dataset.hasEmail === '0';
                        });
                        var msg = '📧 Envoyer le lien d\'accès à ' + checked.length + ' élève' + (checked.length>1?'s':'') + ' ?';
                        if(noEmail.length){
                            msg += '\n\n⚠️ ' + noEmail.length + ' n\'ont pas d\'email et seront ignorés.';
                        }
                        if(!confirm(msg)) return;
                        // Le champ hidden sp_bulk_action est la seule valeur lue par PHP
                        document.getElementById('sp-bulk-action-final').value = 'send_token';
                        document.getElementById('sp-eleves-form').submit();

                    } else if(action === 'admis' || action === 'ajourne'){
                        var eventSelId = target === 'top' ? 'sp-bulk-event-top' : 'sp-bulk-event-bottom';
                        var eventId    = document.getElementById(eventSelId).value;
                        if(!eventId){ alert('Choisissez l\'examen concerné dans le menu déroulant.'); return; }
                        var label = action === 'admis' ? 'Admis(e)' : 'Ajourné(e)';
                        var msg   = 'Marquer ' + checked.length + ' élève' + (checked.length>1?'s':'') + ' « ' + label + ' » pour cet examen ?';
                        if(action === 'admis') msg += '\n\nLe grade suivant (calculé depuis le référentiel) sera appliqué automatiquement.';
                        if(!confirm(msg)) return;
                        document.getElementById('sp-bulk-action-final').value  = action;
                        document.getElementById('sp-bulk-event-id-final').value = eventId;
                        document.getElementById('sp-eleves-form').submit();

                    } else if(action === 'delete'){
                        if(!confirm('Supprimer ' + checked.length + ' élève' + (checked.length>1?'s':'') + ' ? Cette action est irréversible.')) return;
                        var hidden = document.createElement('input');
                        hidden.type  = 'hidden';
                        hidden.name  = 'sp_bulk_delete_eleves';
                        hidden.value = '1';
                        document.getElementById('sp-eleves-form').appendChild(hidden);
                        document.getElementById('sp-eleves-form').submit();
                    }
                });
            });

        })();
        </script>
        </div>
        <?php
    }
}

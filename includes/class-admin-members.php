<?php
/**
 * SP_Cal — Module Membres
 * Fichier : class-admin-members.php
 *
 * Contient : page_eleves, page_palmares, maybe_print_cartes,
 *            page_print_cartes, page_fiche_eleve, page_licences,
 *            page_stats, ajax_send_trainer_app_link
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SP_Cal_Members' ) ) :

class SP_Cal_Members {

    // Même liste que SP_Front_Adhesion::STATUTS_CONTACT (formulaire public) — dupliquée ici
    // volontairement plutôt que de rendre la constante de l'autre classe publique, pour ne
    // pas coupler les deux classes pour 6 lignes. Garder synchronisée si la liste évolue.
    private const STATUTS_CONTACT = [
        'pere'               => 'Père',
        'mere'               => 'Mère',
        'conjoint'           => 'Conjoint/e',
        'famille_accueil'    => "Famille d'accueil",
        'representant_legal' => 'Représentant légal',
        'autre'              => 'Autre',
    ];

    private $db;
    private $token;

    public function __construct( $db, $token = null ) {
        $this->db    = $db;
        $this->token = $token;

        // Hooks propres au module (retirés de SpCalPro_Admin::__construct)
        add_action( 'admin_init',                        array( $this, 'maybe_print_cartes' ) );
        add_action( 'wp_ajax_sp_cal_send_trainer_app',   array( $this, 'ajax_send_trainer_app_link' ) );
        // handle_request() (sauvegarde/suppression de fiches élève) tourne désormais sur son
        // propre hook admin_init avec sa propre capacité — cf. doleances.md 09/09/2026. Avant,
        // c'était SpCalPro_Admin::handle_requests() qui l'appelait, mais ce dispatcher est gardé
        // par un simple manage_options global qui aurait aussi bloqué le rôle Secrétaire : plutôt
        // que d'ouvrir ce gros dispatcher partagé (jury, examens, réglages...) à cette capacité,
        // on isole ce module comme le font déjà SP_Front_Adhesion/SP_Admin_Adhesions/SP_Cal_Renouvellement.
        add_action( 'admin_init', array( $this, 'handle_request' ) );
    }

    /* ══════════════════════════════════════════════════════════
       DISPATCH — sur son propre hook admin_init (voir constructeur)
    ══════════════════════════════════════════════════════════ */

    public function handle_request() {
        if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) return;

        global $wpdb;
        $tel = $this->db->table_eleves();

        /* ── Élève : sauvegarder ── */
        if ( isset( $_POST['sp_cal_save_eleve'] ) && check_admin_referer( 'sp_cal_save_eleve' ) ) {
            $id = intval( $_POST['eleve_id'] ?? 0 );

            // Fusionne avec l'extra_data existant plutôt que de l'écraser — même précaution
            // que SP_Admin_Adhesions::update_member_renouvellement(), pour ne jamais perdre un
            // historique sans rapport (ex : grades importés en CSV, cf. class-admin-members.php).
            $extra_existant = array();
            if ( $id ) {
                $raw_extra = $wpdb->get_var( $wpdb->prepare( "SELECT extra_data FROM $tel WHERE id=%d", $id ) );
                $decoded   = $raw_extra ? json_decode( $raw_extra, true ) : null;
                $extra_existant = is_array( $decoded ) ? $decoded : array();
            }

            // Représentants légaux — jusqu'à 2, mêmes statuts que le formulaire public
            // (cf. SP_Front_Adhesion::STATUTS_CONTACT). Le 1er alimente aussi les colonnes à
            // plat historiques (representant_nom/prenom/telephone) pour tout code qui les lit
            // encore directement ; l'email et le 2e représentant n'existent qu'en JSON (pas de
            // colonne dédiée sur sp_cal_eleves pour ces cas).
            $representants = array();
            for ( $i = 1; $i <= 2; $i++ ) {
                $r = array(
                    'statut'    => sanitize_text_field( wp_unslash( $_POST["eleve_repres{$i}_statut"]    ?? '' ) ),
                    'nom'       => sanitize_text_field( wp_unslash( $_POST["eleve_repres{$i}_nom"]       ?? '' ) ),
                    'prenom'    => sanitize_text_field( wp_unslash( $_POST["eleve_repres{$i}_prenom"]    ?? '' ) ),
                    'telephone' => sanitize_text_field( wp_unslash( $_POST["eleve_repres{$i}_telephone"] ?? '' ) ),
                    'email'     => sanitize_email( wp_unslash( $_POST["eleve_repres{$i}_email"] ?? '' ) ),
                );
                if ( $r === array( 'statut' => '', 'nom' => '', 'prenom' => '', 'telephone' => '', 'email' => '' ) ) continue;
                $representants[] = $r;
            }
            $repres1 = $representants[0] ?? array( 'nom' => '', 'prenom' => '', 'telephone' => '' );

            $urgence_statut = sanitize_text_field( wp_unslash( $_POST['eleve_urgence_statut'] ?? '' ) );

            // Grade / Catégorie d'âge / Discipline pratiquée : ces 3 listes déroulantes envoient
            // "__autre__" quand la valeur n'est pas dans leurs options — on utilise alors le champ
            // texte libre associé (cf. formulaire ci-dessous).
            $grade_saisi = sanitize_text_field( wp_unslash( $_POST['eleve_grade'] ?? '' ) );
            if ( $grade_saisi === '__autre__' ) {
                $grade_saisi = sanitize_text_field( wp_unslash( $_POST['eleve_grade_autre'] ?? '' ) );
            }
            $cat_age_saisi = sanitize_text_field( wp_unslash( $_POST['eleve_cat'] ?? '' ) );
            if ( $cat_age_saisi === '__autre__' ) {
                $cat_age_saisi = sanitize_text_field( wp_unslash( $_POST['eleve_cat_autre'] ?? '' ) );
            }
            $cat_saisie_saisi = sanitize_text_field( wp_unslash( $_POST['eleve_cat_saisie'] ?? '' ) );
            if ( $cat_saisie_saisi === '__autre__' ) {
                $cat_saisie_saisi = sanitize_text_field( wp_unslash( $_POST['eleve_cat_saisie_autre'] ?? '' ) );
            }

            $extra = array_merge( $extra_existant, array(
                'sexe'                    => sanitize_text_field( wp_unslash( $_POST['eleve_sexe'] ?? '' ) ),
                'autorisation_photo'      => isset( $_POST['eleve_autorisation_photo'] ) ? 1 : 0,
                'pass_sport_code'         => sanitize_text_field( wp_unslash( $_POST['eleve_pass_sport_code'] ?? '' ) ),
                'qs_sport_confirme'       => isset( $_POST['eleve_qs_sport_confirme'] ) ? 1 : 0,
                'date_certificat_medical' => sanitize_text_field( wp_unslash( $_POST['eleve_date_certificat_medical'] ?? '' ) ),
                'representants_legaux'    => $representants,
                'contact_urgence'         => array(
                    'statut'    => $urgence_statut,
                    'nom'       => sanitize_text_field( wp_unslash( $_POST['eleve_urgence_nom']       ?? '' ) ),
                    'prenom'    => sanitize_text_field( wp_unslash( $_POST['eleve_urgence_prenom']    ?? '' ) ),
                    'telephone' => sanitize_text_field( wp_unslash( $_POST['eleve_urgence_telephone'] ?? '' ) ),
                    'email'     => sanitize_email( wp_unslash( $_POST['eleve_urgence_email'] ?? '' ) ),
                ),
                'documents' => array(
                    'certificat_medical' => esc_url_raw( wp_unslash( $_POST['eleve_doc_certificat_medical'] ?? '' ) ),
                    'attestation_rc'     => esc_url_raw( wp_unslash( $_POST['eleve_doc_attestation_rc']     ?? '' ) ),
                    'decharge_honneur'   => esc_url_raw( wp_unslash( $_POST['eleve_doc_decharge_honneur']   ?? '' ) ),
                    'bon_caf'            => esc_url_raw( wp_unslash( $_POST['eleve_doc_bon_caf']            ?? '' ) ),
                ),
            ) );

            $data = array(
                'nom'              => sanitize_text_field( wp_unslash( $_POST['eleve_nom']          ?? '' ) ),
                'prenom'           => sanitize_text_field( wp_unslash( $_POST['eleve_prenom']       ?? '' ) ),
                'date_naissance'   => sanitize_text_field( wp_unslash( $_POST['eleve_ddn']          ?? '' ) ),
                'annee_naissance'  => sanitize_text_field( wp_unslash( $_POST['eleve_annee']        ?? '' ) ),
                'grade'            => $this->db->normaliser_grade( $grade_saisi, $cat_age_saisi ),
                'categorie_age'    => $cat_age_saisi,
                'categorie_saisie' => $cat_saisie_saisi,
                'saison'           => sanitize_text_field( wp_unslash( $_POST['eleve_saison']       ?? '' ) ),
                'rang'             => intval(              $_POST['eleve_rang']          ?? 0  ),
                'palmares'         => sanitize_textarea_field( wp_unslash( $_POST['eleve_palmares'] ?? '' ) ),
                'licence'          => sanitize_text_field( wp_unslash( $_POST['eleve_licence']      ?? '' ) ),
                'actif'            => isset($_POST['eleve_actif']) ? 1 : 0,
                'motif_inactif'    => sanitize_text_field( wp_unslash( $_POST['eleve_motif']         ?? '' ) ),
                'telephone'        => sanitize_text_field( wp_unslash( $_POST['eleve_tel']          ?? '' ) ),
                'email'            => sanitize_email( wp_unslash( $_POST['eleve_email']        ?? '' ) ),
                'email_parent'     => sanitize_email( wp_unslash( $_POST['eleve_email_parent']    ?? '' ) ),
                'representant_nom'       => sanitize_text_field( wp_unslash( $repres1['nom']       ?? '' ) ),
                'representant_prenom'    => sanitize_text_field( wp_unslash( $repres1['prenom']    ?? '' ) ),
                'representant_telephone' => sanitize_text_field( wp_unslash( $repres1['telephone'] ?? '' ) ),
                'urgence_nom'            => sanitize_text_field( wp_unslash( $_POST['eleve_urgence_nom']            ?? '' ) ),
                'urgence_prenom'         => sanitize_text_field( wp_unslash( $_POST['eleve_urgence_prenom']         ?? '' ) ),
                'urgence_telephone'      => sanitize_text_field( wp_unslash( $_POST['eleve_urgence_telephone']      ?? '' ) ),
                'urgence_email'          => sanitize_email( wp_unslash( $_POST['eleve_urgence_email']               ?? '' ) ),
                // Champs RENFO
                'lieu_naissance'    => sanitize_text_field( wp_unslash( $_POST['eleve_lieu_naissance']   ?? '' ) ),
                'nationalite'       => sanitize_text_field( wp_unslash( $_POST['eleve_nationalite']       ?? '' ) ),
                'num_passeport'     => sanitize_text_field( wp_unslash( $_POST['eleve_num_passeport'] ?? '' ) ),
                'adresse'           => sanitize_text_field( wp_unslash( $_POST['eleve_adresse']           ?? '' ) ),
                'taille_cm'         => sanitize_text_field( wp_unslash( $_POST['eleve_taille_cm']         ?? '' ) ),
                'poids_kg'          => sanitize_text_field( wp_unslash( $_POST['eleve_poids_kg']           ?? '' ) ),
                'pointure'          => sanitize_text_field( wp_unslash( $_POST['eleve_pointure']           ?? '' ) ),
                'taille_tshirt'     => sanitize_text_field( wp_unslash( $_POST['eleve_taille_tshirt']     ?? '' ) ),
                'taille_pantalon'   => sanitize_text_field( wp_unslash( $_POST['eleve_taille_pantalon']   ?? '' ) ),
                'droit_image'       => isset($_POST['eleve_droit_image'])    ? 1 : 0,
                'autorisation_seul' => isset($_POST['eleve_autorisation_seul']) ? 1 : 0,
                'nb_licences'       => intval( $_POST['eleve_nb_licences']  ?? 0 ),
                'eligible_dan'      => isset($_POST['eleve_eligible_dan']) ? 1 : 0,
                'photo_url'         => esc_url_raw( wp_unslash( $_POST['eleve_photo_url'] ?? '' ) ),
                'extra_data'        => wp_json_encode( $extra ),
            );

            // Filtrage défensif contre le schéma réel — même correctif que sur le formulaire
            // d'adhésion public (cf. doleances.md 08/09/2026) : une colonne pas encore migrée
            // sur cet hébergement ne doit plus faire échouer toute la sauvegarde de la fiche.
            $existing_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$tel}" );
            $filtered_data = array();
            foreach ( $data as $col => $val ) {
                if ( in_array( $col, $existing_cols, true ) ) {
                    $filtered_data[ $col ] = $val;
                } else {
                    error_log( "[SP_Build] Colonne '{$col}' absente de {$tel} — ignorée à l'enregistrement de la fiche élève. Vérifier les droits ALTER TABLE de l'utilisateur MySQL sur cet hébergement." );
                }
            }

            if ( $id ) {
                // Détecter si l'élève passe de inactif → actif pour envoyer le token
                $was_actif = intval( $wpdb->get_var( $wpdb->prepare( "SELECT actif FROM $tel WHERE id=%d", $id ) ) );
                $wpdb->update( $tel, $filtered_data, array( 'id' => $id ) );
                if ( ! $was_actif && $data['actif'] && $this->token ) {
                    $this->token->generate_and_send( $id );
                }
                // Rediriger en conservant l'ID pour réafficher la fiche correctement
                wp_redirect( admin_url( 'admin.php?page=sp-cal-eleves&saved=1&sp_edit_eleve=' . $id ) ); exit;
            } else {
                $wpdb->insert( $tel, $filtered_data );
                $new_id = $wpdb->insert_id;
                if ( $data['actif'] && $this->token ) {
                    $this->token->generate_and_send( $new_id );
                }
                // Rediriger vers la fiche du nouvel élève
                wp_redirect( admin_url( 'admin.php?page=sp-cal-eleves&saved=1&sp_edit_eleve=' . $new_id ) ); exit;
            }
        }

        /* ── Élève : supprimer ── */
        if ( isset( $_GET['sp_delete_eleve'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'], 'sp_delete_eleve_' . intval( $_GET['sp_delete_eleve'] ) ) ) {
                $wpdb->delete( $tel, array( 'id' => intval( $_GET['sp_delete_eleve'] ) ) );
                wp_redirect( admin_url( 'admin.php?page=sp-cal-eleves&deleted=1' ) ); exit;
            }
        }

        /* ── Bascule catégories septembre ── */
        if ( isset( $_POST['sp_bascule_categories'] ) && check_admin_referer( 'sp_bascule_categories' ) ) {
            $dry = isset( $_POST['sp_bascule_preview'] );
            $result = $this->db->bascule_categories_septembre( $dry );
            $redirect = admin_url( 'admin.php?page=sp-cal-eleves&bascule_done=1&nb=' . $result['updated'] );
            if ( $dry ) {
                // Stocker le détail en session transient pour affichage
                set_transient( 'sp_bascule_preview_' . get_current_user_id(), $result['details'], 300 );
                $redirect = admin_url( 'admin.php?page=sp-cal-eleves&bascule_preview=1' );
            }
            wp_redirect( $redirect ); exit;
        }

        /* ── Normalisation écriture catégories d'âge ── */
        if ( isset( $_POST['sp_normaliser_categories_age'] ) && check_admin_referer( 'sp_normaliser_categories_age' ) ) {
            $dry = isset( $_POST['sp_normaliser_preview'] );
            if ( $dry ) {
                $preview = $this->db->preview_normalisation_categorie_age();
                set_transient( 'sp_normaliser_cat_preview_' . get_current_user_id(), $preview, 300 );
                wp_redirect( admin_url( 'admin.php?page=sp-cal-eleves&normalisation_preview=1' ) ); exit;
            }
            $total = $this->db->appliquer_normalisation_categorie_age();
            wp_redirect( admin_url( 'admin.php?page=sp-cal-eleves&normalisation_done=1&nb=' . $total ) ); exit;
        }

        /* ── Bulk delete élèves ── */
        if ( isset( $_POST['sp_bulk_delete_eleves'] ) && check_admin_referer( 'sp_cal_bulk_eleves' ) ) {
            $ids = array_map( 'intval', $_POST['sp_bulk_ids'] ?? array() );
            if ( $ids ) {
                $in = implode( ',', $ids );
                $wpdb->query( "DELETE FROM $tel WHERE id IN ($in)" );
            }
            wp_redirect( admin_url( 'admin.php?page=sp-cal-eleves&deleted=1' ) ); exit;
        }

        /* ── Bulk send tokens ── */
        if ( isset( $_POST['sp_bulk_action'] ) && $_POST['sp_bulk_action'] === 'send_token'
             && check_admin_referer( 'sp_cal_bulk_eleves' ) ) {
            $ids     = array_map( 'intval', $_POST['sp_bulk_ids'] ?? array() );
            $sent    = 0;
            $skipped = 0;
            foreach ( $ids as $eid ) {
                if ( ! $eid ) continue;
                // Récupérer l'élève — sans filtre actif (le gestionnaire choisit qui envoyer)
                $eleve_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, email, email_parent FROM $tel WHERE id=%d", $eid
                ) );
                if ( ! $eleve_row ) continue;
                // Vérifier qu'au moins un email est disponible
                $dest = ! empty($eleve_row->email_parent) ? $eleve_row->email_parent : $eleve_row->email;
                if ( ! $dest || ! is_email($dest) ) {
                    $skipped++;
                    continue;
                }
                if ( $this->token ) {
                    // $force = true : renvoyer même si un token existe déjà
                    $this->token->generate_and_send( $eid, true );
                    $sent++;
                }
            }
            wp_redirect( admin_url( 'admin.php?page=sp-cal-eleves&tokens_sent=' . $sent . '&tokens_skipped=' . $skipped ) ); exit;
        }

        /* ── Bulk passage de grade : Admis(e) / Ajourné(e) ──
           Rattaché à un examen (event_id) pour garder la traçabilité de la date de passage
           dans l'historique de la fiche/token — un club a 2-3 sessions de passage par saison,
           il faut pouvoir les distinguer (cf. échange du 16/09/2026). */
        if ( isset( $_POST['sp_bulk_action'] ) && in_array( $_POST['sp_bulk_action'], array( 'admis', 'ajourne' ), true )
             && check_admin_referer( 'sp_cal_bulk_eleves' ) ) {
            $ids      = array_map( 'intval', $_POST['sp_bulk_ids'] ?? array() );
            $event_id = intval( $_POST['sp_bulk_event_id'] ?? 0 );
            $recu     = ( $_POST['sp_bulk_action'] === 'admis' ) ? 1 : 0;
            $done     = 0;
            if ( $event_id ) {
                foreach ( $ids as $eid ) {
                    if ( ! $eid ) continue;
                    $el = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tel WHERE id=%d", $eid ) );
                    if ( ! $el ) continue;
                    // Grade suivant calculé automatiquement depuis le référentiel (Jury → Grades) ;
                    // si l'élève n'a pas de correspondance dans le référentiel, on garde son grade actuel.
                    $nouveau_grade = $recu ? ( $this->db->get_grade_vise_eleve( $el ) ?: $el->grade ) : '';
                    $this->db->save_exam_passage( $event_id, $eid, $recu, $nouveau_grade );
                    $done++;
                }
            }
            wp_redirect( admin_url( 'admin.php?page=sp-cal-eleves&passages_done=' . $done ) ); exit;
        }

        /* ── Export CSV — colonnes choisies point par point ──
           GET (pas POST) pour que le lien de téléchargement fonctionne comme un simple clic ;
           hooké sur admin_init comme le reste de handle_request() pour pouvoir envoyer les
           en-têtes CSV avant que la page admin ne commence à écrire du HTML. */
        if ( isset( $_GET['sp_export_eleves_csv'] ) && check_admin_referer( 'sp_cal_export_eleves_csv' ) ) {
            $this->export_eleves_csv();
            exit;
        }
    }

    /* ══════════════════════════════════════════════════════════
       UTILITAIRE
    ══════════════════════════════════════════════════════════ */

    private function notice_flash( $key, $message ) {
        if ( isset( $_GET[ $key ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }
    }

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
                <thead><tr><th>Élève</th><th>Âge au 1/09</th><th>Avant</th><th>Après</th></tr></thead>
                <tbody>
                <?php foreach($preview as $p): ?>
                <tr>
                    <td><?php echo esc_html($p['nom']); ?></td>
                    <td><?php echo intval($p['age']); ?> ans</td>
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
                        Recalcule la catégorie de chaque élève selon son âge au 1<sup>er</sup> septembre.
                        Baby (&lt; 6 ans) · Enfant (6-10 ans) · Ado/adulte (11-14 ans) · Adulte (≥ 15 ans)
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
                     Jury → Grades), pour harmoniser la saisie. "Autre" en secours si le grade
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
                         problème de l'oeuf et la poule) + le référentiel Jury → Grades + les
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
            <h2><span class="dashicons dashicons-list-view"></span> Adhérents (<?php echo count( $eleves ); ?>)
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

    /* ══════════════════════════════════════════════════════════
       PAGE PALMARES
    ══════════════════════════════════════════════════════════ */

    public function page_palmares() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );

        global $wpdb;

        // ── Paramètres URL ───────────────────────────────────
        $vue      = in_array( $_GET['vue'] ?? '', array('competition','eleves') ) ? $_GET['vue'] : 'competition';
        $event_id = intval( $_GET['event_id'] ?? 0 );
        $annee    = sanitize_text_field( $_GET['annee'] ?? '' );

        // ── Données ──────────────────────────────────────────
        $annees       = $this->db->get_competitions_annees();
        $competitions = $this->db->get_all_competitions( $annee );

        // Si pas de filtre d'année mais des compétitions existent, pré-sélectionner la plus récente
        if ( ! $annee && ! empty($annees) ) {
            $annee = $annees[0];
            $competitions = $this->db->get_all_competitions( $annee );
        }

        // Compétition sélectionnée
        $comp_courante = null;
        if ( $event_id ) {
            $te = $this->db->table_events();
            $comp_courante = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $te WHERE id=%d AND type='competition'", $event_id
            ) );
            if ( ! $comp_courante ) $event_id = 0;
        }
        // Pré-sélectionner la première compétition si aucune choisie
        if ( ! $event_id && ! empty($competitions) ) {
            $event_id      = intval($competitions[0]->id);
            $comp_courante = $competitions[0];
        }

        // Données selon la vue
        $palmares_global  = array();
        $palmares_eleves  = array();
        $epreuves         = array();
        $participants     = array(); // eleve_id => row élève (présences liées à cette compétition)

        if ( $vue === 'competition' && $event_id ) {
            $palmares_global = $this->db->get_palmares_global( $event_id );
            $epreuves        = $this->db->get_comp_epreuves( $event_id );

            // Participants : source = comp_participations (v10.16.1+)
            // + fallback : élèves ayant des résultats dans comp_resultats
            $tcp = $this->db->table_comp_participations();
            $tce = $this->db->table_comp_epreuves();
            $tcr = $this->db->table_comp_resultats();
            $tel = $this->db->table_eleves();
            $rows_participants = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT el.id, el.nom, el.prenom, el.categorie_age, el.grade
                 FROM $tel el
                 WHERE el.id IN (
                     SELECT p.eleve_id FROM $tcp p WHERE p.event_id = %d
                     UNION
                     SELECT r.eleve_id FROM $tcr r
                     INNER JOIN $tce ep ON ep.id = r.epreuve_id AND ep.event_id = %d
                 )
                 ORDER BY el.categorie_age ASC, el.nom ASC",
                $event_id, $event_id
            ) );
            foreach ( $rows_participants as $p ) {
                $participants[ intval($p->id) ] = $p;
            }

            // Organiser les résultats : epreuve_id => eleve_id => {medaille, score}
            foreach ( $palmares_global as $row ) {
                $palmares_comp[ intval($row->epreuve_id) ][ intval($row->eleve_id) ] = array(
                    'medaille' => $row->medaille,
                    'score'    => $row->score,
                );
            }
        }

        if ( $vue === 'eleves' ) {
            $palmares_eleves = $this->db->get_palmares_resume_eleves();
        }

        // Helper médaille → emoji + couleur
        $medal_info = function( $med ) {
            $map = array(
                'or'     => array('emoji'=>'🥇','label'=>'Or',     'color'=>'#b7791f','bg'=>'#fefce8'),
                'argent' => array('emoji'=>'🥈','label'=>'Argent', 'color'=>'#6b7280','bg'=>'#f9fafb'),
                'bronze' => array('emoji'=>'🥉','label'=>'Bronze', 'color'=>'#92400e','bg'=>'#fff7ed'),
            );
            return $map[$med] ?? array('emoji'=>'—', 'label'=>'', 'color'=>'#9ca3af','bg'=>'transparent');
        };

        $url_base = admin_url('admin.php?page=sp-cal-palmares');
        ?>
        <div class="wrap sp-cal-wrap">
        <h1>🏆 Palmarès des élèves</h1>

        <?php if ( empty($annees) ) : ?>
        <div class="sp-box" style="text-align:center;padding:40px;">
            <p style="font-size:16px;color:#6b7280;">Aucune compétition enregistrée.</p>
            <p class="sp-muted">
                Créez des événements de type <strong>Compétition</strong> dans le calendrier,
                puis saisissez les épreuves et résultats depuis le calendrier.
            </p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-pro')); ?>" class="button button-primary">
                Ouvrir le calendrier
            </a>
        </div>
        <?php else : ?>

        <!-- ── Barre de navigation ─────────────────────────── -->
        <div class="sp-palmares-toolbar">

            <!-- Filtre Année -->
            <div class="sp-palmares-filter-group">
                <span class="sp-palmares-filter-label">Année :</span>
                <?php foreach ( $annees as $a ) :
                    $active = ($a == $annee);
                    $url_a  = add_query_arg( array('page'=>'sp-cal-palmares','vue'=>$vue,'annee'=>$a,'event_id'=>0), admin_url('admin.php') );
                ?>
                <a href="<?php echo esc_url($url_a); ?>"
                   class="button <?php echo $active ? 'button-primary' : ''; ?>" style="min-width:60px;">
                    <?php echo esc_html($a); ?>
                </a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url(add_query_arg(array('page'=>'sp-cal-palmares','vue'=>$vue,'annee'=>'','event_id'=>0), admin_url('admin.php'))); ?>"
                   class="button <?php echo !$annee ? 'button-primary' : ''; ?>">Toutes</a>
            </div>

            <!-- Onglets Vue -->
            <div class="sp-palmares-tabs">
                <?php
                $tabs = array(
                    'competition' => '🏅 Par compétition',
                    'eleves'      => '👤 Par élève',
                );
                foreach ( $tabs as $t_key => $t_label ) :
                    $active = ($vue === $t_key);
                    $url_t  = add_query_arg( array('page'=>'sp-cal-palmares','vue'=>$t_key,'annee'=>$annee,'event_id'=>($t_key==='competition'?$event_id:0)), admin_url('admin.php') );
                ?>
                <a href="<?php echo esc_url($url_t); ?>"
                   class="sp-palmares-tab <?php echo $active ? 'sp-palmares-tab-active' : ''; ?>">
                    <?php echo $t_label; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             VUE : PAR COMPÉTITION
        ══════════════════════════════════════════════════ -->
        <?php if ( $vue === 'competition' ) : ?>

        <div class="sp-two-col sp-palmares-layout" style="align-items:start;">

            <!-- Colonne gauche : liste des compétitions -->
            <div class="sp-box sp-palmares-list-box">
                <h2 style="margin-top:0;">📅 Compétitions</h2>
                <?php if ( empty($competitions) ) : ?>
                    <p class="sp-muted">Aucune compétition pour cette année.</p>
                <?php else : ?>
                <ul class="sp-palmares-comp-list">
                    <?php foreach ( $competitions as $comp ) :
                        $is_sel = (intval($comp->id) === $event_id);
                        $url_c  = add_query_arg( array('page'=>'sp-cal-palmares','vue'=>'competition','annee'=>$annee,'event_id'=>$comp->id), admin_url('admin.php') );
                        $date_f = date_i18n( 'd/m/Y', strtotime($comp->date) );

                        // Compter les médailles de cette compétition
                        $tce = $this->db->table_comp_epreuves();
                        $tcr = $this->db->table_comp_resultats();
                        $nb_med = intval($wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM $tcr r
                             INNER JOIN $tce ep ON ep.id=r.epreuve_id
                             WHERE ep.event_id=%d AND r.medaille != ''",
                            intval($comp->id)
                        )));
                    ?>
                    <li class="sp-palmares-comp-item <?php echo $is_sel ? 'sp-palmares-comp-active' : ''; ?>">
                        <a href="<?php echo esc_url($url_c); ?>" class="sp-palmares-comp-link">
                            <div class="sp-palmares-comp-title"><?php echo esc_html($comp->titre); ?></div>
							<?php
							$niveau_badges = array(
								'international' => array('label'=>'🌐 International','bg'=>'#1e3a5f','color'=>'#fff'),
								'national'      => array('label'=>'🇫🇷 National',    'bg'=>'#2563eb','color'=>'#fff'),
								'regional'      => array('label'=>'🌍 Régional',     'bg'=>'#7c3aed','color'=>'#fff'),
								'departemental' => array('label'=>'🏘️ Dép.',         'bg'=>'#e5e7eb','color'=>'#374151'),
							);
							$nb = $niveau_badges[$comp->niveau ?? 'departemental'] ?? $niveau_badges['departemental'];
							echo '<span style="display:inline-block;margin-top:3px;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:700;background:'.esc_attr($nb['bg']).';color:'.esc_attr($nb['color']).';">'.esc_html($nb['label']).'</span>';
							?>
                            <div class="sp-palmares-comp-meta">
                                📅 <?php echo esc_html($date_f); ?>
                                <?php if($nb_med): ?>
                                <span class="sp-palmares-med-count">🏅 <?php echo $nb_med; ?> médaille<?php echo $nb_med>1?'s':''; ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <button type="button"
                                class="button button-small sp-btn-saisir-resultats"
                                data-event-id="<?php echo intval($comp->id); ?>"
                                data-event-titre="<?php echo esc_attr($comp->titre); ?>"
                                title="Saisir / modifier les résultats"
                                style="margin-top:6px;width:100%;justify-content:center;">
                            ✏️ Saisir les résultats
                        </button>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <!-- Colonne droite : résultats de la compétition sélectionnée -->
            <div class="sp-palmares-results-col">

            <?php if ( ! $comp_courante ) : ?>
            <div class="sp-box">
                <p class="sp-muted" style="text-align:center;padding:20px;">Sélectionnez une compétition.</p>
            </div>
            <?php else : ?>

            <!-- En-tête compétition -->
            <div class="sp-box sp-palmares-comp-header">
                <div class="sp-palmares-comp-header-inner">
                    <div>
                        <h2 style="margin:0 0 6px 0;color:#1e3a5f;"><?php echo esc_html($comp_courante->titre); ?></h2>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <span style="color:#374151;font-size:13px;">
								📅 <?php echo date_i18n('l j F Y', strtotime($comp_courante->date)); ?>
							</span>
							<?php
							$niveau_badges = array(
								'international' => array('label'=>'🌐 International','bg'=>'#1e3a5f','color'=>'#fff'),
								'national'      => array('label'=>'🇫🇷 National',    'bg'=>'#2563eb','color'=>'#fff'),
								'regional'      => array('label'=>'🌍 Régional',     'bg'=>'#7c3aed','color'=>'#fff'),
								'departemental' => array('label'=>'🏘️ Départemental','bg'=>'#e5e7eb','color'=>'#374151'),
							);
							$nb = $niveau_badges[$comp_courante->niveau ?? 'departemental'] ?? $niveau_badges['departemental'];
							echo '<span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:'.esc_attr($nb['bg']).';color:'.esc_attr($nb['color']).';">'.esc_html($nb['label']).'</span>';
							?>
                            <?php if($comp_courante->categorie && $comp_courante->categorie !== 'Général'): ?>
                            <span class="sp-fiche-pill" style="background:#7c3aed;">
                                <?php echo esc_html($comp_courante->categorie); ?>
                            </span>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-pro')); ?>"
                               style="font-size:12px;color:#6b7280;">
                                ✏️ Modifier les résultats dans le calendrier
                            </a>
                        </div>
                    </div>
                    <?php
                    $nb_or2 = 0; $nb_arg2 = 0; $nb_bro2 = 0;
                    foreach ($palmares_global as $rr) {
                        if($rr->medaille==='or')     $nb_or2++;
                        if($rr->medaille==='argent') $nb_arg2++;
                        if($rr->medaille==='bronze') $nb_bro2++;
                    }
                    if ($nb_or2 + $nb_arg2 + $nb_bro2 > 0) : ?>
                    <div class="sp-palmares-medals-summary">
                        <?php if($nb_or2):  ?><span class="sp-palm-med-badge sp-palm-or">🥇 <?php echo $nb_or2; ?></span><?php endif; ?>
                        <?php if($nb_arg2): ?><span class="sp-palm-med-badge sp-palm-argent">🥈 <?php echo $nb_arg2; ?></span><?php endif; ?>
                        <?php if($nb_bro2): ?><span class="sp-palm-med-badge sp-palm-bronze">🥉 <?php echo $nb_bro2; ?></span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if($comp_courante->description): ?>
                <p style="margin:8px 0 0;color:#374151;font-size:13px;"><?php echo nl2br(esc_html($comp_courante->description)); ?></p>
                <?php endif; ?>
            </div>

            <?php if ( empty($epreuves) ) : ?>
            <div class="sp-box">
                <p class="sp-muted" style="text-align:center;padding:16px;">
                    Aucune épreuve saisie pour cette compétition.<br>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-pro')); ?>">
                        Ouvrir le calendrier pour saisir les résultats
                    </a>
                </p>
            </div>
            <?php else : ?>

            <!-- Tableau des résultats par épreuve -->
            <?php
            // Construire le tableau : lignes = participants, colonnes = épreuves
            // Participants = tous les élèves inscrits à la compétition (présences) + ceux avec des résultats
            $all_eleve_ids = array_keys($participants);
            foreach ($palmares_global as $rr) {
                $lid = intval($rr->eleve_id);
                if (!in_array($lid, $all_eleve_ids)) $all_eleve_ids[] = $lid;
            }

            // Grouper par categorie_age pour en-têtes de groupe
            $by_cat = array();
            foreach ($all_eleve_ids as $lid) {
                $elv = $participants[$lid] ?? null;
                if (!$elv) {
                    // Trouver dans palmares_global
                    foreach ($palmares_global as $rr) {
                        if (intval($rr->eleve_id) === $lid) { $elv = $rr; break; }
                    }
                }
                $cat = $elv ? ($elv->categorie_age ?? '') : '';
                $by_cat[$cat][$lid] = $elv;
            }
            ksort($by_cat);
            ?>

            <?php foreach ($by_cat as $cat_label => $eleves_cat) : ?>
            <div class="sp-box sp-palmares-epreuve-box">
                <?php if($cat_label): ?>
                <h3 class="sp-palmares-cat-header">👥 <?php echo esc_html($cat_label); ?></h3>
                <?php endif; ?>

                <div class="sp-palmares-table-wrap">
                <table class="wp-list-table widefat sp-palmares-table">
                    <thead>
                        <tr>
                            <th class="sp-palm-col-eleve">Élève</th>
                            <th class="sp-palm-col-grade">Grade</th>
                            <?php foreach ($epreuves as $ep) : ?>
                            <th class="sp-palm-col-epreuve" title="<?php echo esc_attr($ep->nom); ?>">
                                <?php echo esc_html($ep->nom); ?>
                            </th>
                            <?php endforeach; ?>
                            <th class="sp-palm-col-total">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $no_result_row = 0;
                        foreach ($eleves_cat as $lid => $elv) :
                            $nb_med_row = 0;
                            $cells = array();
                            foreach ($epreuves as $ep) {
                                $res = $palmares_comp[ intval($ep->id) ][ $lid ] ?? null;
                                $mi  = $medal_info($res['medaille'] ?? '');
                                if ($res && $res['medaille']) $nb_med_row++;
                                $cells[] = array('mi'=>$mi, 'score'=>$res['score'] ?? '', 'has'=>!!$res);
                            }
                            $nom_affiche = $elv ? (esc_html($elv->prenom . ' ' . mb_strtoupper($elv->nom))) : '#' . $lid;
                            $grade_affiche = $elv ? esc_html($elv->grade ?? '') : '';
                            $fiche_url = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . $lid);
                        ?>
                        <tr class="<?php echo $nb_med_row ? 'sp-palm-row-medal' : ''; ?>">
                            <td class="sp-palm-col-eleve">
                                <a href="<?php echo esc_url($fiche_url); ?>" class="sp-palm-eleve-link">
                                    <?php echo $nom_affiche; ?>
                                </a>
                            </td>
                            <td class="sp-palm-col-grade sp-muted"><?php echo $grade_affiche; ?></td>
                            <?php foreach ($cells as $cell) : ?>
                            <td class="sp-palm-col-epreuve sp-palm-cell"
                                style="background:<?php echo $cell['mi']['bg']; ?>">
                                <?php if ($cell['has']) : ?>
                                <span class="sp-palm-med-emoji" title="<?php echo esc_attr($cell['mi']['label']); ?>">
                                    <?php echo $cell['mi']['emoji']; ?>
                                </span>
                                <?php if ($cell['score']) : ?>
                                <span class="sp-palm-score"><?php echo esc_html($cell['score']); ?></span>
                                <?php endif; ?>
                                <?php else : ?>
                                <span class="sp-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="sp-palm-col-total">
                                <?php if ($nb_med_row) : ?>
                                <strong style="color:#b7791f;"><?php echo $nb_med_row; ?> 🏅</strong>
                                <?php else : ?>
                                <span class="sp-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; // has epreuves ?>

            <?php endif; // has comp_courante ?>

            </div><!-- .sp-palmares-results-col -->

        </div><!-- .sp-two-col -->

        <?php endif; // vue === competition ?>

        <!-- ══════════════════════════════════════════════════
             VUE : PAR ÉLÈVE
        ══════════════════════════════════════════════════ -->
        <?php if ( $vue === 'eleves' ) : ?>

        <?php if ( empty($palmares_eleves) ) : ?>
        <div class="sp-box" style="text-align:center;padding:30px;">
            <p class="sp-muted" style="font-size:15px;">
                Aucune médaille enregistrée pour le moment.<br>
                Saisissez les résultats depuis la vue <strong>Par compétition</strong>.
            </p>
        </div>
        <?php else : ?>

        <!-- KPIs globaux -->
        <?php
        $total_or     = array_sum(array_column($palmares_eleves, 'nb_or'));
        $total_argent = array_sum(array_column($palmares_eleves, 'nb_argent'));
        $total_bronze = array_sum(array_column($palmares_eleves, 'nb_bronze'));
        $nb_medailles = count($palmares_eleves);
        ?>
        <div class="sp-stats-kpi-row">
            <div class="sp-stats-kpi" style="border-top:3px solid #b7791f;">
                <div class="sp-stats-kpi-val" style="color:#b7791f;">🥇 <?php echo intval($total_or); ?></div>
                <div class="sp-stats-kpi-lbl">Médailles d'or</div>
            </div>
            <div class="sp-stats-kpi" style="border-top:3px solid #6b7280;">
                <div class="sp-stats-kpi-val" style="color:#6b7280;">🥈 <?php echo intval($total_argent); ?></div>
                <div class="sp-stats-kpi-lbl">Médailles d'argent</div>
            </div>
            <div class="sp-stats-kpi" style="border-top:3px solid #92400e;">
                <div class="sp-stats-kpi-val" style="color:#92400e;">🥉 <?php echo intval($total_bronze); ?></div>
                <div class="sp-stats-kpi-lbl">Médailles de bronze</div>
            </div>
            <div class="sp-stats-kpi" style="border-top:3px solid #2271b1;">
                <div class="sp-stats-kpi-val" style="color:#2271b1;">👤 <?php echo $nb_medailles; ?></div>
                <div class="sp-stats-kpi-lbl">Élèves médaillés</div>
            </div>
        </div>

        <!-- Tableau palmarès élèves -->
        <div class="sp-box">
            <h2 style="margin-top:0;">🏆 Classement des élèves médaillés</h2>
            <div class="sp-palmares-table-wrap">
            <table class="wp-list-table widefat striped sp-palmares-eleves-table">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>Élève</th>
                        <th>Groupe</th>
                        <th>Grade</th>
                        <th style="text-align:center;">🥇 Or</th>
                        <th style="text-align:center;">🥈 Argent</th>
                        <th style="text-align:center;">🥉 Bronze</th>
                        <th style="text-align:center;">🏟 Compétitions</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rang_i = 1;
                    $prev   = null;
                    foreach ( $palmares_eleves as $row ) :
                        $nb_or_r  = intval($row->nb_or);
                        $nb_ar_r  = intval($row->nb_argent);
                        $nb_br_r  = intval($row->nb_bronze);
                        $fiche_url2 = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . intval($row->eleve_id));
                        $is_podium = ($rang_i <= 3);
                        $podium_icon = ($rang_i === 1) ? '🥇' : (($rang_i === 2) ? '🥈' : (($rang_i === 3) ? '🥉' : ''));
                    ?>
                    <tr class="<?php echo $is_podium ? 'sp-palm-row-podium' : ''; ?>">
                        <td style="font-weight:bold;color:#6b7280;">
                            <?php echo $podium_icon ?: $rang_i; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($fiche_url2); ?>" class="sp-palm-eleve-link">
                                <?php echo esc_html($row->prenom . ' ' . mb_strtoupper($row->nom)); ?>
                            </a>
                        </td>
                        <td class="sp-muted"><?php echo esc_html($row->categorie_age ?? ''); ?></td>
                        <td class="sp-muted"><?php echo esc_html($row->grade ?? ''); ?></td>
                        <td style="text-align:center;font-weight:bold;color:<?php echo $nb_or_r ? '#b7791f' : '#d1d5db'; ?>;">
                            <?php echo $nb_or_r ?: '—'; ?>
                        </td>
                        <td style="text-align:center;font-weight:bold;color:<?php echo $nb_ar_r ? '#6b7280' : '#d1d5db'; ?>;">
                            <?php echo $nb_ar_r ?: '—'; ?>
                        </td>
                        <td style="text-align:center;font-weight:bold;color:<?php echo $nb_br_r ? '#92400e' : '#d1d5db'; ?>;">
                            <?php echo $nb_br_r ?: '—'; ?>
                        </td>
                        <td style="text-align:center;color:#6b7280;"><?php echo intval($row->nb_total_comp); ?></td>
                        <td>
                            <a href="<?php echo esc_url($fiche_url2); ?>" class="button button-small">
                                Fiche
                            </a>
                        </td>
                    </tr>
                    <?php $rang_i++; endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- Légende détail par compétition pour chaque élève -->
            <div class="sp-palmares-eleves-detail">
                <h3>📋 Détail par élève</h3>
                <div class="sp-palmares-accordion">
                <?php foreach ( $palmares_eleves as $row ) :
                    $detail = $this->db->get_palmares_eleve( intval($row->eleve_id) );
                    if ( empty($detail) ) continue;
                    // Grouper par compétition
                    $by_comp = array();
                    foreach ($detail as $dr) {
                        $by_comp[$dr->event_id][] = $dr;
                    }
                    $fiche_url3 = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . intval($row->eleve_id));
                ?>
                <details class="sp-palmares-accordion-item">
                    <summary class="sp-palmares-accordion-summary">
                        <span class="sp-palm-eleve-name">
                            <?php echo esc_html($row->prenom . ' ' . mb_strtoupper($row->nom)); ?>
                        </span>
                        <?php if($row->categorie_age): ?>
                        <span class="sp-fiche-pill sp-fiche-pill-age" style="font-size:11px;">
                            <?php echo esc_html($row->categorie_age); ?>
                        </span>
                        <?php endif; ?>
                        <span class="sp-palm-accordion-medals">
                            <?php if($row->nb_or):     echo '🥇 ' . intval($row->nb_or)     . ' '; endif; ?>
                            <?php if($row->nb_argent): echo '🥈 ' . intval($row->nb_argent) . ' '; endif; ?>
                            <?php if($row->nb_bronze): echo '🥉 ' . intval($row->nb_bronze)        ; endif; ?>
                        </span>
                    </summary>
                    <div class="sp-palm-accordion-body">
                        <?php foreach ($by_comp as $eid => $rows_comp) :
                            $comp_date  = date_i18n('d/m/Y', strtotime($rows_comp[0]->date));
                            $comp_title = $rows_comp[0]->comp_titre;
                            $url_comp   = add_query_arg(array('page'=>'sp-cal-palmares','vue'=>'competition','event_id'=>$eid,'annee'=>date('Y', strtotime($rows_comp[0]->date))), admin_url('admin.php'));
                        ?>
                        <div class="sp-palm-comp-block">
                            <div class="sp-palm-comp-block-title">
                                📅 <a href="<?php echo esc_url($url_comp); ?>"><?php echo esc_html($comp_title); ?></a>
                                <span class="sp-muted"><?php echo $comp_date; ?></span>
                            </div>
                            <ul class="sp-palm-epreuves-list">
                                <?php foreach ($rows_comp as $dr) :
                                    $mi = $medal_info($dr->medaille);
                                ?>
                                <li>
                                    <span style="color:<?php echo $mi['color']; ?>;"><?php echo $mi['emoji']; ?></span>
                                    <strong><?php echo esc_html($dr->epreuve_nom); ?></strong>
                                    <?php if($dr->score): ?>
                                    <span class="sp-muted">— <?php echo esc_html($dr->score); ?></span>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endforeach; ?>
                        <a href="<?php echo esc_url($fiche_url3); ?>" class="button button-small" style="margin-top:6px;">
                            Voir la fiche complète
                        </a>
                    </div>
                </details>
                <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php endif; // palmares_eleves not empty ?>

        <?php
        // ── Encart correspondance pseudo / identité — uniquement si des élèves ont droit_image=0
        $eleves_sans_droit = array_filter( $palmares_eleves ?? array(), function($r){ return intval($r->droit_image ?? 1) === 0; } );
        if ( ! empty($eleves_sans_droit) ) :
            $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $idx = 0;
        ?>
        <div class="sp-box" style="border:2px solid #f59e0b;background:#fffbeb;">
            <h2 style="margin-top:0;color:#92400e;">🔒 Correspondance pseudo / identité réelle</h2>
            <p class="description" style="margin-bottom:12px;">
                Ces élèves apparaissent sous pseudonyme dans le palmarès public (droit à l'image non accordé).
            </p>
            <table class="wp-list-table widefat fixed striped" style="max-width:480px;">
                <thead><tr>
                    <th style="width:140px;">Pseudo public</th>
                    <th>Identité réelle</th>
                    <th style="width:80px;">Fiche</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $eleves_sans_droit as $row ) :
                    $pseudo    = 'Élève ' . $alphabet[ $idx % 26 ];
                    $fiche_url = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . intval($row->eleve_id));
                    $idx++;
                ?>
                <tr>
                    <td><strong style="color:#1e3a5f;"><?php echo esc_html($pseudo); ?></strong></td>
                    <td><?php echo esc_html($row->prenom . ' ' . mb_strtoupper($row->nom)); ?></td>
                    <td><a href="<?php echo esc_url($fiche_url); ?>" class="button button-small">Fiche</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php endif; // vue === eleves ?>

        <?php endif; // annees not empty ?>

        <!-- ══════════════════════════════════════════════════
             POPUP SAISIE RÉSULTATS (page Palmarès)
        ══════════════════════════════════════════════════ -->
        <div id="sp-palmares-comp-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99998;"></div>
        <div id="sp-palmares-comp-popup" style="
            display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
            z-index:99999;width:min(96vw,960px);max-height:88vh;overflow-y:auto;
            background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.35);
            padding:0;
        ">
            <!-- En-tête popup -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;
                        border-bottom:1px solid #e5e7eb;background:#f8fafc;border-radius:12px 12px 0 0;position:sticky;top:0;z-index:2;">
                <div>
                    <h2 id="sp-comp-popup-titre" style="margin:0;font-size:16px;color:#1e3a5f;">Saisie des résultats</h2>
                    <p class="sp-muted" style="margin:2px 0 0;font-size:12px;">Épreuves et médailles — la page se rafraîchira après enregistrement.</p>
                </div>
                <button type="button" id="sp-comp-popup-close" style="
                    background:none;border:none;font-size:22px;cursor:pointer;color:#6b7280;
                    line-height:1;padding:4px 8px;border-radius:6px;
                " title="Fermer">✕</button>
            </div>

            <!-- Corps popup -->
            <div style="padding:20px;">
                <div id="sp-comp-popup-loading" style="text-align:center;padding:30px;color:#6b7280;">
                    Chargement…
                </div>

                <!-- Épreuves -->
                <div id="sp-comp-popup-epreuves-wrap" style="display:none;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                        <strong style="font-size:13px;">📋 Épreuves (max 6)</strong>
                        <button type="button" id="sp-comp-popup-add-ep" class="button button-small">+ Ajouter une épreuve</button>
                    </div>
                    <!-- en-tête colonnes -->
                    <div class="sp-comp-ep-header">
                        <span class="sp-comp-ep-ordre">#</span>
                        <span style="flex:1.4;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Nom de l'épreuve</span>
                        <span style="flex:1;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;">Modalité</span>
                        <span style="width:28px;"></span>
                    </div>
                    <div id="sp-comp-popup-ep-list"></div>
                    <div style="display:flex;align-items:center;gap:10px;margin-top:10px;">
                        <button type="button" id="sp-comp-popup-save-ep" class="button" style="background:#f0fdf4;border-color:#86efac;color:#15803d;">
                            💾 Enregistrer les épreuves
                        </button>
                        <span id="sp-comp-popup-ep-msg" style="font-size:12px;color:#15803d;display:none;"></span>
                    </div>
                </div>

                <hr style="margin:18px 0;border:none;border-top:1px solid #e5e7eb;">

                <!-- Résultats -->
                <div id="sp-comp-popup-resultats-wrap" style="display:none;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                        <strong style="font-size:13px;">🥇 Résultats par épreuve</strong>
                        <select id="sp-comp-popup-flt-saisie" class="sp-input" style="height:30px;min-width:140px;">
                            <option value="">— Tous</option>
                        </select>
                    </div>
                    <div style="overflow-x:auto;">
                        <div id="sp-comp-popup-res-list"></div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;margin-top:14px;">
                        <button type="button" id="sp-comp-popup-save-res" class="button button-primary">
                            💾 Enregistrer les résultats
                        </button>
                        <span id="sp-comp-popup-res-msg" style="font-size:12px;color:#15803d;display:none;"></span>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function($){
            // ── État local du popup ───────────────────────────────────
            var PAL = {
                eventId:    0,
                epreuves:   [],
                eleves:     [],
                resultats:  {},
                nonce:      '<?php echo wp_create_nonce("sp_cal_admin_nonce"); ?>',
                ajaxurl:    '<?php echo admin_url("admin-ajax.php"); ?>',
            };

            function escHtml(s){ return $('<div>').text(s||'').html(); }

            // ── Ouverture popup ───────────────────────────────────────
            $(document).on('click', '.sp-btn-saisir-resultats', function(){
                PAL.eventId = parseInt($(this).data('event-id'), 10);
                var titre   = $(this).data('event-titre') || 'Compétition';
                $('#sp-comp-popup-titre').text('✏️ ' + titre);
                // Reset affichage
                $('#sp-comp-popup-loading').show();
                $('#sp-comp-popup-epreuves-wrap, #sp-comp-popup-resultats-wrap').hide();
                $('#sp-comp-popup-ep-list').empty();
                $('#sp-comp-popup-res-list').empty();
                // Ouvrir
                $('#sp-palmares-comp-overlay, #sp-palmares-comp-popup').show();
                $('body').css('overflow','hidden');
                // Charger données
                palLoadComp();
            });

            function closePalPopup(){
                $('#sp-palmares-comp-overlay, #sp-palmares-comp-popup').hide();
                $('body').css('overflow','');
            }
            $('#sp-comp-popup-close, #sp-palmares-comp-overlay').on('click', closePalPopup);

            // ── Charger épreuves + résultats ─────────────────────────
            function palLoadComp(){
                $.post(PAL.ajaxurl, {
                    action: 'sp_cal_get_comp', nonce: PAL.nonce, event_id: PAL.eventId
                }, function(res){
                    $('#sp-comp-popup-loading').hide();
                    if (!res.success){ alert('Erreur : ' + res.data); return; }
                    PAL.epreuves  = res.data.epreuves || [];
                    PAL.eleves    = res.data.eleves   || [];
                    PAL.resultats = {};
                    PAL.epreuves.forEach(function(ep){
                        PAL.resultats[ep.id] = JSON.parse(JSON.stringify(ep.resultats || {}));
                    });
                    // Filtre discipline pratiquée
                    var discLabels = { TKD: 'Taekwondo', RENFO: 'Renforcement musculaire' };
                    var cats = {}; PAL.eleves.forEach(function(el){ if(el.categorie_saisie) cats[el.categorie_saisie]=1; });
                    var opts = '<option value="">— Tous</option>';
                    Object.keys(cats).sort().forEach(function(c){ opts += '<option value="'+escHtml(c)+'">'+escHtml(discLabels[c] || c)+'</option>'; });
                    $('#sp-comp-popup-flt-saisie').html(opts);
                    $('#sp-comp-popup-epreuves-wrap, #sp-comp-popup-resultats-wrap').show();
                    palRenderEpreuves();
                    palRenderResultats();
                });
            }

            // ── Rendu épreuves ────────────────────────────────────────
            function palRenderEpreuves(){
                var html = '';
                if (!PAL.epreuves.length){
                    html = '<div style="color:#9ca3af;font-size:12px;padding:6px 0;">Aucune épreuve. Ajoutez-en jusqu\'à 6.</div>';
                } else {
                    PAL.epreuves.forEach(function(ep, i){
                        html += '<div class="sp-comp-ep-row" data-idx="'+i+'">'
                            + '<span class="sp-comp-ep-ordre">'+(i+1)+'</span>'
                            + '<input type="text" class="sp-input sp-pal-ep-nom" value="'+escHtml(ep.nom)+'" placeholder="ex : Combat, Kata…" data-idx="'+i+'" style="flex:1.4;height:30px;">'
                            + '<input type="text" class="sp-input sp-pal-ep-mod" value="'+escHtml(ep.modalite||'')+'" placeholder="ex : Individuel, Duo…" data-idx="'+i+'" style="flex:1;height:30px;">'
                            + '<span class="sp-comp-ep-del" role="button" data-idx="'+i+'" style="cursor:pointer;color:#ef4444;font-size:16px;padding:0 4px;" title="Supprimer">✕</span>'
                            + '</div>';
                    });
                }
                var $list = $('#sp-comp-popup-ep-list').html(html);
                $list.off('input').on('input', '.sp-pal-ep-nom', function(){
                    var idx = parseInt($(this).data('idx'),10);
                    if (PAL.epreuves[idx]) PAL.epreuves[idx].nom = $(this).val();
                }).on('input', '.sp-pal-ep-mod', function(){
                    var idx = parseInt($(this).data('idx'),10);
                    if (PAL.epreuves[idx]) PAL.epreuves[idx].modalite = $(this).val();
                }).off('click').on('click', '.sp-comp-ep-del', function(){
                    var idx = parseInt($(this).data('idx'),10);
                    PAL.epreuves.splice(idx,1);
                    PAL.epreuves.forEach(function(ep,i){ ep.ordre=i+1; });
                    palRenderEpreuves(); palRenderResultats();
                });
            }

            $('#sp-comp-popup-add-ep').on('click', function(){
                if (PAL.epreuves.length >= 6){ alert('Maximum 6 épreuves.'); return; }
                PAL.epreuves.push({id:0, nom:'', modalite:'', ordre: PAL.epreuves.length+1});
                palRenderEpreuves();
            });

            $('#sp-comp-popup-save-ep').on('click', function(){
                // Sync inputs → PAL.epreuves
                $('#sp-comp-popup-ep-list .sp-comp-ep-row').each(function(){
                    var idx = parseInt($(this).data('idx'),10);
                    if (PAL.epreuves[idx]){
                        PAL.epreuves[idx].nom     = $(this).find('.sp-pal-ep-nom').val().trim();
                        PAL.epreuves[idx].modalite= $(this).find('.sp-pal-ep-mod').val().trim();
                    }
                });
                var payload = PAL.epreuves.filter(function(ep){ return ep.nom; })
                    .map(function(ep,i){ return {id:ep.id||0, nom:ep.nom, modalite:ep.modalite||'', ordre:i+1}; });
                var btn = $(this).prop('disabled',true);
                $.post(PAL.ajaxurl, {
                    action: 'sp_cal_save_comp_epreuves', nonce: PAL.nonce,
                    event_id: PAL.eventId, epreuves: JSON.stringify(payload),
                }, function(res){
                    btn.prop('disabled',false);
                    if (!res.success){ alert('Erreur : '+res.data); return; }
                    $('#sp-comp-popup-ep-msg').text('✅ Épreuves enregistrées.').show();
                    setTimeout(function(){ $('#sp-comp-popup-ep-msg').fadeOut(); }, 2500);
                    palLoadComp();
                });
            });

            // ── Rendu tableau résultats ───────────────────────────────
            function palRenderResultats(){
                var saisie = $('#sp-comp-popup-flt-saisie').val() || '';
                var eleves = saisie ? PAL.eleves.filter(function(el){ return el.categorie_saisie === saisie; }) : PAL.eleves;
                if (!PAL.epreuves.length){
                    $('#sp-comp-popup-res-list').html('<div style="color:#9ca3af;font-size:12px;padding:8px 0;">Définissez d\'abord les épreuves.</div>');
                    return;
                }
                if (!eleves.length){
                    $('#sp-comp-popup-res-list').html('<div style="color:#9ca3af;font-size:12px;padding:8px 0;">Aucun élève.</div>');
                    return;
                }
                var html = '<table class="sp-comp-table"><thead><tr><th class="sp-comp-th-name">Élève</th>';
                PAL.epreuves.forEach(function(ep){
                    var sub = ep.modalite ? '<br><span class="sp-comp-ep-mod">'+escHtml(ep.modalite)+'</span>' : '';
                    html += '<th class="sp-comp-th-ep">'+escHtml(ep.nom)+sub+'</th>';
                });
                html += '</tr></thead><tbody>';
                eleves.forEach(function(el){
                    html += '<tr><td class="sp-comp-td-name">'+escHtml(el.nom)+'<br><span class="sp-comp-cat">'+escHtml(el.categorie_age||'')+'</span></td>';
                    PAL.epreuves.forEach(function(ep){
                        if (!ep.id){ html += '<td class="sp-comp-td-res"><span style="color:#ccc;font-size:11px;">—</span></td>'; return; }
                        var res  = (PAL.resultats[ep.id] && PAL.resultats[ep.id][el.id]) || {};
                        var med  = res.medaille || '';
                        var score= res.score    || '';
                        html += '<td class="sp-comp-td-res">'
                            + '<div class="sp-comp-med-btns">'
                            + '<span class="sp-comp-med'+(med==='or'?' active':'')+'" role="button" data-ep="'+ep.id+'" data-el="'+el.id+'" data-val="or" title="Or">🥇</span>'
                            + '<span class="sp-comp-med'+(med==='argent'?' active':'')+'" role="button" data-ep="'+ep.id+'" data-el="'+el.id+'" data-val="argent" title="Argent">🥈</span>'
                            + '<span class="sp-comp-med'+(med==='bronze'?' active':'')+'" role="button" data-ep="'+ep.id+'" data-el="'+el.id+'" data-val="bronze" title="Bronze">🥉</span>'
                            + '<span class="sp-comp-med'+(med===''?' active':'')+'" role="button" data-ep="'+ep.id+'" data-el="'+el.id+'" data-val="" title="Effacer">✕</span>'
                            + '</div>'
                            + '<input type="text" class="sp-comp-score sp-input" value="'+escHtml(score)+'" placeholder="Score…" data-ep="'+ep.id+'" data-el="'+el.id+'">'
                            + '</td>';
                    });
                    html += '</tr>';
                });
                html += '</tbody></table>';
                var $res = $('#sp-comp-popup-res-list').html(html);
                $res.off('click').on('click', '.sp-comp-med', function(){
                    var epId = parseInt($(this).data('ep'),10);
                    var elId = parseInt($(this).data('el'),10);
                    var val  = $(this).data('val');
                    if (!PAL.resultats[epId]) PAL.resultats[epId]={};
                    if (!PAL.resultats[epId][elId]) PAL.resultats[epId][elId]={medaille:'',score:''};
                    PAL.resultats[epId][elId].medaille = val;
                    $(this).closest('.sp-comp-med-btns').find('.sp-comp-med').removeClass('active');
                    $(this).addClass('active');
                }).off('input').on('input', '.sp-comp-score', function(){
                    var epId = parseInt($(this).data('ep'),10);
                    var elId = parseInt($(this).data('el'),10);
                    if (!PAL.resultats[epId]) PAL.resultats[epId]={};
                    if (!PAL.resultats[epId][elId]) PAL.resultats[epId][elId]={medaille:'',score:''};
                    PAL.resultats[epId][elId].score = $(this).val();
                });
            }

            $('#sp-comp-popup-flt-saisie').on('change', palRenderResultats);

            // ── Enregistrer résultats ─────────────────────────────────
            $('#sp-comp-popup-save-res').on('click', function(){
                var payload = [];
                var epNames = {};
                PAL.epreuves.forEach(function(ep){ epNames[ep.id]=ep.nom; });
                Object.keys(PAL.resultats).forEach(function(epId){
                    Object.keys(PAL.resultats[epId]).forEach(function(elId){
                        var r = PAL.resultats[epId][elId];
                        if (!r.medaille && !r.score) return;
                        payload.push({
                            epreuve_id: parseInt(epId,10), eleve_id: parseInt(elId,10),
                            medaille: r.medaille||'', score: r.score||'',
                            epreuve_nom: epNames[epId]||'',
                        });
                    });
                });
                var btn = $(this).prop('disabled',true).text('Enregistrement…');
                $.post(PAL.ajaxurl, {
                    action: 'sp_cal_save_comp_resultats', nonce: PAL.nonce,
                    event_id: PAL.eventId, resultats: JSON.stringify(payload),
                }, function(res){
                    btn.prop('disabled',false).text('💾 Enregistrer les résultats');
                    if (!res.success){ alert('Erreur : '+res.data); return; }
                    $('#sp-comp-popup-res-msg').text('✅ Résultats enregistrés.').show();
                    setTimeout(function(){
                        $('#sp-comp-popup-res-msg').fadeOut();
                        // Rafraîchir la page pour mettre à jour les compteurs de médailles
                        location.reload();
                    }, 1500);
                });
            });

        }(jQuery));
        </script>

        </div><!-- .wrap -->

        <?php
    }

    /* ══════════════════════════════════════════════════════════
       MAYBE PRINT CARTES
    ══════════════════════════════════════════════════════════ */

    public function maybe_print_cartes(): void {
        if (
            ! isset( $_GET['page'], $_GET['print'] )
            || $_GET['page'] !== 'sp-cal-print-cartes'
            || ! current_user_can( 'manage_options' )
        ) return;

        global $wpdb;
        $tel       = $this->db->table_eleves();
        $cat_sel   = sanitize_text_field( wp_unslash( $_GET['cat']    ?? '' ) );
        $saison    = sanitize_text_field( wp_unslash( $_GET['saison']  ?? '' ) );
        $pro       = isset( $_GET['pro'] ) && $_GET['pro'] === '1';

        $args = array( 'actif' => 1 );
        if ( $cat_sel ) $args['categorie_age'] = $cat_sel;
        if ( $saison )  $args['saison']        = $saison;
        $eleves = $this->db->get_eleves( $args );

        $club          = get_option( 'blogname', 'Club' );
        $club_num      = get_option( 'sp_cal_club_num', '' );
        $club_affil    = get_option( 'sp_cal_club_affiliation', '' );
        $club_ligue    = get_option( 'sp_cal_club_ligue', '' );
        $club_labelise = intval( get_option( 'sp_cal_club_labelise', 0 ) );
        $logo_url      = get_option( 'sp_cal_logo_url', '' );
        $meta_parts    = array_filter( [
            $club_affil ? 'FFTDA ' . $club_affil : '',
            $club_ligue,
            $club_num ? 'Club ' . $club_num : '',
        ] );
        $meta_str = implode( ' · ', $meta_parts );

        // Vider tout buffer existant et sortir une page HTML propre
        while ( ob_get_level() ) ob_end_clean();

        header( 'Content-Type: text/html; charset=UTF-8' );

        $nom_club = esc_html( strtoupper( $club ) );
        ?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Cartes membres — <?php echo esc_html( $cat_sel ?: 'Tous' ); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Song+Myung&display=swap" rel="stylesheet">
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#f3f4f6; padding:10mm; }

/* ── Même classes que la carte token ── */
.sp-carte-recto,
.sp-carte-verso {
    width:340px; height:215px; border-radius:12px;
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
    position:relative; overflow:hidden;
    -webkit-print-color-adjust:exact; print-color-adjust:exact;
}
.sp-carte-recto { background:#111; }
.sp-carte-band-blue   { position:absolute;right:0;top:0;width:88px;height:215px;background:#0f70b7; }
.sp-carte-band-yellow { position:absolute;right:84px;top:0;width:4px;height:215px;background:#ffdd0e; }
.sp-carte-band-red    { position:absolute;bottom:0;left:0;width:252px;height:5px;background:#e30613; }
.sp-carte-logo-wrap   { position:absolute;top:10px;left:12px;width:30px;height:30px;border-radius:4px;background:#fff;display:flex;align-items:center;justify-content:center; }
.sp-carte-logo        { width:28px;height:28px;object-fit:contain; }
.sp-carte-club-name   { position:absolute;top:12px;left:50px;color:#fff;font-size:10px;font-weight:600;letter-spacing:1.5px; }
.sp-carte-club-sub    { position:absolute;top:25px;left:50px;color:#ffdd0e;font-size:8px;letter-spacing:1px; }
.sp-carte-sep         { position:absolute;top:46px;left:12px;width:240px;height:0.5px;background:rgba(255,255,255,.12); }
.sp-carte-nom         { position:absolute;top:54px;left:12px;right:100px;color:#fff;font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.sp-carte-meta        { position:absolute;top:72px;left:12px;right:100px;color:rgba(255,255,255,.4);font-size:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.sp-carte-infos       { position:absolute;top:88px;left:12px;right:100px;display:flex;flex-direction:column;gap:4px; }
.sp-carte-info-row    { display:flex;gap:6px;align-items:center; }
.sp-carte-info-k      { color:rgba(255,255,255,.38);font-size:8px;width:50px;text-transform:uppercase;letter-spacing:.4px;flex-shrink:0; }
.sp-carte-info-v      { color:#fff;font-size:9px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.sp-carte-qr-zone     { position:absolute;top:8px;right:2px;width:86px;display:flex;flex-direction:column;align-items:center;gap:2px; }
.sp-carte-qr-box      { width:72px;height:72px;background:#fff;border-radius:6px;overflow:hidden;display:flex;align-items:center;justify-content:center; }
.sp-carte-qr-box img  { max-width:72px;max-height:72px; }
.sp-carte-stars       { display:flex;gap:1px;margin-top:3px; }
.sp-carte-labelise-txt{ color:rgba(255,255,255,.35);font-size:7px;letter-spacing:.3px; }
.sp-carte-qr-scan     { color:rgba(255,255,255,.35);font-size:7px;text-align:center; }
.sp-carte-url         { position:absolute;bottom:8px;right:6px;color:rgba(255,255,255,.25);font-size:7px; }
.sp-carte-photo-wrap  { position:absolute;bottom:12px;left:12px;width:46px;height:46px;border-radius:50%;overflow:hidden;border:2px solid rgba(255,255,255,.18);background:#222; }
.sp-carte-photo       { width:100%;height:100%;object-fit:cover;display:block; }
/* Verso */
.sp-carte-verso        { background:#111;display:flex;flex-direction:column; }
.sp-carte-verso-header { height:36px;padding:0 12px;display:flex;align-items:center;gap:8px;border-bottom:1px solid rgba(255,255,255,.1);flex-shrink:0; }
.sp-carte-verso-logo-wrap { width:22px;height:22px;border-radius:3px;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.sp-carte-verso-logo   { width:20px;height:20px;object-fit:contain; }
.sp-carte-verso-title  { color:rgba(255,255,255,.75);font-size:10px;font-weight:500;letter-spacing:.8px; }
.sp-carte-verso-badge  { margin-left:auto;background:#ffdd0e;border-radius:8px;padding:2px 8px;font-size:8px;font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px; }
.sp-carte-verso-body   { flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:6px;padding:12px; }
.sp-carte-verso-tkd    { color:rgba(255,255,255,.15);font-size:42px;line-height:1;user-select:none;font-family:'Song Myung',cursive;letter-spacing:4px; }
.sp-carte-verso-sub    { color:rgba(255,255,255,.35);font-size:9px;letter-spacing:2px;text-transform:uppercase; }
.sp-carte-verso-footer { height:24px;padding:0 12px;border-top:1px solid rgba(255,255,255,.1);display:flex;justify-content:space-between;align-items:center;font-size:8px;color:rgba(255,255,255,.3);flex-shrink:0; }

/* ── Grille écran : recto | verso côte à côte ── */
.cartes-grid { display:flex;flex-direction:column;gap:20px;width:fit-content;margin:0 auto; }
.carte-wrap  { display:flex;flex-direction:row;gap:16px;break-inside:avoid; }

/* ── Impression : redimensionner 340px → 85.6mm ── */
@media print {
    @page { size:A4 portrait;margin:8mm; }
    body { background:#fff;padding:0;width:177.2mm; }
    .cartes-grid { display:flex;flex-direction:column;gap:6mm;width:177.2mm; }
    .carte-wrap  { display:flex;flex-direction:row;gap:6mm;break-inside:avoid; }
    .sp-carte-recto,
    .sp-carte-verso { width:85.6mm;height:54mm;border-radius:0; }
    .sp-carte-recto { margin-bottom:0; }
    .sp-carte-band-blue   { width:22mm;height:54mm; }
    .sp-carte-band-yellow { right:21mm;width:1mm;height:54mm; }
    .sp-carte-band-red    { width:63mm;height:1.5mm; }
    .sp-carte-logo-wrap   { top:2.5mm;left:3mm;width:8mm;height:8mm;border-radius:1mm; }
    .sp-carte-logo        { width:7mm;height:7mm; }
    /* ── Nom club & sous-titre ── */
    .sp-carte-club-name   { top:3mm;left:14mm;font-size:7pt;color:#fff !important; }
    .sp-carte-club-sub    { top:7.5mm;left:14mm;font-size:5pt;color:#ffdd0e !important; }
    .sp-carte-sep         { top:13mm;left:3mm;width:60mm; }
    /* ── Nom membre — plus grand, gras ── */
    .sp-carte-nom         { top:15mm;left:3mm;right:25mm;font-size:9.5pt;font-weight:700;color:#fff !important; }
    /* ── Méta affiliation — blanc lisible ── */
    .sp-carte-meta        { top:21mm;left:3mm;right:25mm;font-size:5pt;color:rgba(255,255,255,.85) !important; }
    /* ── Bloc infos ── */
    .sp-carte-infos       { top:25.5mm;left:3mm;right:25mm;gap:1.5mm; }
    .sp-carte-info-k      { font-size:5pt;width:13mm;color:rgba(255,255,255,.8) !important; }
    .sp-carte-info-v      { font-size:6pt;color:#fff !important; }
    /* ── Zone QR ── */
    .sp-carte-qr-zone     { top:2mm;right:0.5mm;width:22mm; }
    .sp-carte-qr-box      { width:18mm;height:18mm;border-radius:1.5mm; }
    .sp-carte-qr-box img  { max-width:18mm;max-height:18mm; }
    .sp-carte-stars       { gap:0.3mm;margin-top:1mm; }
    .sp-carte-labelise-txt{ font-size:4pt;color:rgba(255,255,255,.8) !important; }
    .sp-carte-qr-scan     { font-size:4pt;color:rgba(255,255,255,.8) !important; }
    /* ── URL bas ── */
    .sp-carte-url         { bottom:2mm;right:2mm;font-size:4.5pt;color:rgba(255,255,255,.7) !important; }
    /* ── Photo ── */
    .sp-carte-photo-wrap  { bottom:3mm;left:3mm;width:12mm;height:12mm;border-width:0.5mm; }
    /* ── Verso ── */
    .sp-carte-verso-header{ height:10mm; }
    .sp-carte-verso-logo-wrap { width:6mm;height:6mm; }
    .sp-carte-verso-logo  { width:5.5mm;height:5.5mm; }
    .sp-carte-verso-title { font-size:7pt;color:#fff !important; }
    .sp-carte-verso-badge { font-size:6pt;padding:1mm 2.5mm;border-radius:2mm; }
    .sp-carte-verso-tkd   { font-size:22pt;color:rgba(255,255,255,.55) !important; }
    .sp-carte-verso-sub   { font-size:5pt;color:rgba(255,255,255,.85) !important;letter-spacing:1.5px; }
    .sp-carte-verso-footer{ height:7mm;font-size:5pt;color:rgba(255,255,255,.75) !important; }
}
</style>
<?php if ( $pro ) : ?>
<style>
/* ═══════════════════════════════════════════════════════════════
   MODE IMPRIMEUR — débord 2mm, angles droits, traits de coupe
   Carte nette  : 85.6 × 54 mm
   Carte imprimée : 89.6 × 58 mm (+2 mm chaque côté)
   Le prestataire coupe le long des traits de coupe.
═══════════════════════════════════════════════════════════════ */

/* Dimensions bleed */
body.sp-print-pro .sp-carte-recto,
body.sp-print-pro .sp-carte-verso {
    width:89.6mm !important;
    height:58mm !important;
    border-radius:0 !important;
}
body.sp-print-pro .sp-carte-recto { margin-bottom:4mm !important; }

/* Ajuste la grille pour les cartes plus larges */
@media print {
    body.sp-print-pro .carte-wrap {
        gap:10mm;
    }
    body.sp-print-pro .sp-carte-recto { margin-bottom:0 !important; }
}

/* ── Wrapper portant les traits de coupe ── */
.sp-pro-wrap {
    position:relative;
    display:inline-block;
    -webkit-print-color-adjust:exact;
    print-color-adjust:exact;
}

/* ── Traits de coupe ──
   Ligne blanche semi-transparente placée à 2 mm du bord de la carte (= ligne de coupe)
   Longueur 1.5 mm dans la zone de débord, épaisseur 0.4 pt.
   Sur fond sombre : blanc 55 % d'opacité, lisible à la lumière.           */
.sp-cm {
    position:absolute;
    background:rgba(255,255,255,.55);
    display:block;
    pointer-events:none;
    z-index:99;
    -webkit-print-color-adjust:exact;
    print-color-adjust:exact;
}
.sp-cm.h { width:1.5mm; height:.4pt; } /* horizontal */
.sp-cm.v { width:.4pt; height:1.5mm; } /* vertical   */

/* Coin haut-gauche */
.sp-cm.tl-h { top:2mm;  left:0;    }
.sp-cm.tl-v { top:0;    left:2mm;  }
/* Coin haut-droit */
.sp-cm.tr-h { top:2mm;  right:0;   }
.sp-cm.tr-v { top:0;    right:2mm; }
/* Coin bas-gauche */
.sp-cm.bl-h { bottom:2mm; left:0;    }
.sp-cm.bl-v { bottom:0;   left:2mm;  }
/* Coin bas-droit */
.sp-cm.br-h { bottom:2mm; right:0;   }
.sp-cm.br-v { bottom:0;   right:2mm; }

@media print {
    /* ── Bandes : étendre à la hauteur bleed + élargir bleue pour couvrir QR décalé ──
       QR zone : right:2.5mm + width:22mm → commence à 65.1mm du bord gauche
       Bande bleue : width:25mm → commence à 64.6mm ✓ couvre le QR
       Bande jaune : suit la bleue à right:24mm
       Bande rouge : rejoint la bande jaune → width:64.5mm                          */
    body.sp-print-pro .sp-carte-band-blue   { height:58mm !important; width:25mm   !important; }
    body.sp-print-pro .sp-carte-band-yellow { height:58mm !important; right:24mm   !important; }
    /* Bande rouge : height:3.5mm = 1.5mm visible après coupe + 2mm de débord en bas */
    body.sp-print-pro .sp-carte-band-red    { width:64.5mm !important; height:3.5mm !important; bottom:0 !important; }
    /* Logo + nom club — coin haut-gauche */
    body.sp-print-pro .sp-carte-logo-wrap { top:4.5mm !important; left:5mm !important; }
    body.sp-print-pro .sp-carte-club-name { top:5mm   !important; left:15.5mm !important; }
    body.sp-print-pro .sp-carte-club-sub  { top:9mm   !important; left:15.5mm !important; }
    /* QR code — coin haut-droit */
    body.sp-print-pro .sp-carte-qr-zone   { top:4mm   !important; right:2.5mm !important; }
    /* URL — coin bas-droit */
    body.sp-print-pro .sp-carte-url       { bottom:4mm !important; right:4mm   !important; }
    /* Photo — remontée de 2mm pour sortir de la zone de débord */
    body.sp-print-pro .sp-carte-photo-wrap { bottom:6.5mm !important; left:5mm !important; }
    /* Verso header & footer — padding latéraux */
    body.sp-print-pro .sp-carte-verso-header { padding-left:5mm !important; padding-right:5mm !important; }
    body.sp-print-pro .sp-carte-verso-footer { padding-left:5mm !important; padding-right:5mm !important; }
}
</style>
<?php endif; ?>
</head>
<body<?php if ( $pro ) echo ' class="sp-print-pro"'; ?>>
<div class="cartes-grid">
<?php foreach ( $eleves as $el ) :
    $token_url = home_url( '/fiche-membre/?token=' . rawurlencode( $el->token ?? '' ) );
    $qr_url    = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode( $token_url );
    $nom       = esc_html( $el->prenom . ' ' . mb_strtoupper( $el->nom ) );
    $footer_parts = array_filter( [ $club_affil, $club_num ? 'Club ' . $club_num : '', 'tkdclaira.fr' ] );
    $footer_str   = esc_html( implode( ' · ', $footer_parts ) );
?>
<div class="carte-wrap">
<?php if ( $pro ) : ?>
<div class="sp-pro-wrap">
    <span class="sp-cm h tl-h"></span><span class="sp-cm v tl-v"></span>
    <span class="sp-cm h tr-h"></span><span class="sp-cm v tr-v"></span>
    <span class="sp-cm h bl-h"></span><span class="sp-cm v bl-v"></span>
    <span class="sp-cm h br-h"></span><span class="sp-cm v br-v"></span>
<?php endif; ?>
    <div class="sp-carte-recto">
        <div class="sp-carte-band-blue"></div>
        <div class="sp-carte-band-yellow"></div>
        <div class="sp-carte-band-red"></div>
        <?php if ( $logo_url ) : ?>
        <div class="sp-carte-logo-wrap"><img src="<?php echo esc_url($logo_url); ?>" class="sp-carte-logo"></div>
        <?php endif; ?>
        <div class="sp-carte-club-name"><?php echo $nom_club; ?></div>
        <div class="sp-carte-club-sub">CARTE DE MEMBRE</div>
        <div class="sp-carte-sep"></div>
        <div class="sp-carte-nom"><?php echo $nom; ?></div>
        <div class="sp-carte-meta"><?php echo esc_html( $meta_str ); ?></div>
        <div class="sp-carte-infos">
            <?php if ( $el->licence ) : ?>
            <div class="sp-carte-info-row"><span class="sp-carte-info-k">Licence</span><span class="sp-carte-info-v"><?php echo esc_html($el->licence); ?></span></div>
            <?php endif; ?>
            <?php if ( $el->date_naissance && $el->annee_naissance ) : ?>
            <div class="sp-carte-info-row"><span class="sp-carte-info-k">Né(e) le</span><span class="sp-carte-info-v"><?php echo esc_html($el->date_naissance.'/'.$el->annee_naissance); ?></span></div>
            <?php endif; ?>
            <?php if ( ! empty( $el->urgence_telephone ) ) : ?>
            <div class="sp-carte-info-row"><span class="sp-carte-info-k">Urgence</span><span class="sp-carte-info-v" style="color:#e30613;"><?php echo esc_html($el->urgence_telephone); ?></span></div>
            <?php endif; ?>
        </div>
        <?php if ( ! empty( $el->photo_url ) ) : ?>
        <div class="sp-carte-photo-wrap"><img src="<?php echo esc_url($el->photo_url); ?>" class="sp-carte-photo"></div>
        <?php endif; ?>
        <div class="sp-carte-qr-zone">
            <div class="sp-carte-qr-box"><img src="<?php echo esc_url($qr_url); ?>" alt="QR"></div>
            <?php if ( $club_labelise > 0 ) : ?>
            <div class="sp-carte-stars">
                <?php for($i=1;$i<=5;$i++) echo '<span style="color:'.($i<=$club_labelise?'#ffdd0e':'rgba(255,255,255,0.2)').';font-size:10px;">★</span>'; ?>
            </div>
            <div class="sp-carte-labelise-txt">Club labellisé</div>
            <?php endif; ?>
            <div class="sp-carte-qr-scan">Scanner pour pointer</div>
        </div>
        <div class="sp-carte-url">tkdclaira.fr</div>
    </div><!-- .sp-carte-recto -->
<?php if ( $pro ) : ?>
</div><!-- .sp-pro-wrap recto -->
<div class="sp-pro-wrap">
    <span class="sp-cm h tl-h"></span><span class="sp-cm v tl-v"></span>
    <span class="sp-cm h tr-h"></span><span class="sp-cm v tr-v"></span>
    <span class="sp-cm h bl-h"></span><span class="sp-cm v bl-v"></span>
    <span class="sp-cm h br-h"></span><span class="sp-cm v br-v"></span>
<?php endif; ?>
    <div class="sp-carte-verso">
        <div class="sp-carte-verso-header">
            <?php if ( $logo_url ) : ?>
            <div class="sp-carte-verso-logo-wrap"><img src="<?php echo esc_url($logo_url); ?>" class="sp-carte-verso-logo"></div>
            <?php endif; ?>
            <span class="sp-carte-verso-title"><?php echo $nom_club; ?></span>
            <span class="sp-carte-verso-badge"><?php echo $nom; ?></span>
        </div>
        <div class="sp-carte-verso-body">
            <div class="sp-carte-verso-tkd">태권도</div>
            <div class="sp-carte-verso-sub">Carte de membre officielle</div>
        </div>
        <div class="sp-carte-verso-footer">
            <span><?php echo $footer_str; ?></span>
            <?php if ( $club_labelise > 0 ) : ?>
            <span><?php for($i=1;$i<=5;$i++) echo '<span style="color:'.($i<=$club_labelise?'#e30613':'rgba(255,255,255,.12)').';font-size:9px;">★</span>'; ?></span>
            <?php endif; ?>
        </div>
    </div><!-- .sp-carte-verso -->
<?php if ( $pro ) : ?>
</div><!-- .sp-pro-wrap verso -->
<?php endif; ?>
</div><!-- .carte-wrap -->
<?php endforeach; ?>
</div>
<script>window.onload=function(){ window.print(); window.onafterprint=function(){ window.close(); }; };</script>
</body>
</html>
<?php
        exit;
    }

    /* ══════════════════════════════════════════════════════════
       PAGE PRINT CARTES
    ══════════════════════════════════════════════════════════ */

    public function page_print_cartes() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        global $wpdb;

        $tel       = $this->db->table_eleves();
        $cats      = $this->db->get_categories_eleves();
        $cat_sel   = sanitize_text_field( wp_unslash( $_GET['cat']    ?? '' ) );
        $saison    = sanitize_text_field( wp_unslash( $_GET['saison']  ?? '' ) );
        $do_print  = isset( $_GET['print'] );

        // Récupérer les élèves
        $args = array( 'actif' => 1 );
        if ( $cat_sel )  $args['categorie_age']    = $cat_sel;
        if ( $saison )   $args['saison']            = $saison;
        $eleves = $this->db->get_eleves( $args );

        // Options club pour la carte
        $club          = get_option('blogname','Club');
        $club_num      = get_option('sp_cal_club_num','');
        $club_affil    = get_option('sp_cal_club_affiliation','');
        $club_ligue    = get_option('sp_cal_club_ligue','');
        $club_labelise = intval(get_option('sp_cal_club_labelise',0));
        $logo_url      = get_option('sp_cal_logo_url','');

        $meta_parts = array_filter([
            $club_affil ? 'FFTDA ' . $club_affil : '',
            $club_ligue,
            $club_num ? 'Club '.$club_num : '',
        ]);
        $meta_str = implode(' · ', $meta_parts);

        if ( $do_print ) :
        // ── MODE IMPRESSION : page pleine, sans chrome admin ──────────────
        ?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Cartes membres — <?php echo esc_html($cat_sel ?: 'Tous'); ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
@page { size: A4 portrait; margin: 8mm; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#fff; }
.page-a4 { width:194mm; }
.cartes-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6mm;
}
/* Carte bancaire ×2.5 */
.carte-wrap { page-break-inside: avoid; }
.carte-recto, .carte-verso {
    width: 85.6mm;
    height: 54mm;
    border-radius: 4mm;
    position: relative;
    overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.carte-recto { background:#111; margin-bottom:3mm; }
.carte-band-blue   { position:absolute;right:0;top:0;width:22mm;height:54mm;background:#0f70b7; }
.carte-band-yellow { position:absolute;right:21mm;top:0;width:1mm;height:54mm;background:#ffdd0e; }
.carte-band-red    { position:absolute;bottom:0;left:0;width:63mm;height:1.5mm;background:#e30613; }
.carte-logo-wrap   { position:absolute;top:2.5mm;left:3mm;width:8mm;height:8mm;border-radius:1mm;background:#fff;display:flex;align-items:center;justify-content:center; }
.carte-logo        { width:7mm;height:7mm;object-fit:contain; }
/* ── Nom club & sous-titre ── */
.carte-club-name   { position:absolute;top:3mm;left:13mm;color:#fff;font-size:7pt;font-weight:600;letter-spacing:1px; }
.carte-club-sub    { position:absolute;top:6.5mm;left:13mm;color:#ffdd0e;font-size:5pt;letter-spacing:0.8px; }
.carte-sep         { position:absolute;top:12mm;left:3mm;width:60mm;height:0.2mm;background:rgba(255,255,255,.3); }
/* ── Nom membre ── */
.carte-nom         { position:absolute;top:14mm;left:3mm;right:25mm;color:#fff;font-size:9pt;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
/* ── Méta ── */
.carte-meta        { position:absolute;top:19mm;left:3mm;right:25mm;color:rgba(255,255,255,.85);font-size:5pt;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
/* ── Infos ── */
.carte-infos       { position:absolute;top:23mm;left:3mm;right:25mm;display:flex;flex-direction:column;gap:1mm; }
.carte-info-row    { display:flex;gap:1.5mm;align-items:center; }
.carte-info-k      { color:rgba(255,255,255,.8);font-size:5pt;width:12mm;text-transform:uppercase;flex-shrink:0; }
.carte-info-v      { color:#fff;font-size:6pt;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
/* ── Photo ── */
.carte-photo-wrap  { position:absolute;bottom:3mm;left:3mm;width:12mm;height:12mm;border-radius:50%;overflow:hidden;border:0.5mm solid rgba(255,255,255,.18);background:#222; }
.carte-photo       { width:100%;height:100%;object-fit:cover; }
/* ── Zone QR ── */
.carte-qr-zone     { position:absolute;top:2mm;right:0.5mm;width:22mm;display:flex;flex-direction:column;align-items:center;gap:0.5mm; }
.carte-qr-box      { width:18mm;height:18mm;background:#fff;border-radius:1.5mm;overflow:hidden;display:flex;align-items:center;justify-content:center; }
.carte-qr-box img  { width:100%;height:100%; }
.carte-stars       { display:flex;gap:0.3mm; }
.carte-qr-scan     { color:rgba(255,255,255,.8);font-size:4pt;text-align:center; }
.carte-url         { position:absolute;bottom:2mm;right:1.5mm;color:rgba(255,255,255,.7);font-size:4.5pt; }
/* ── Verso ── */
.carte-verso       { background:#111; }
.carte-verso-hd    { height:9mm;padding:0 3mm;display:flex;align-items:center;gap:2mm;border-bottom:0.3mm solid rgba(255,255,255,.2); }
.carte-verso-logo  { width:5.5mm;height:5.5mm;object-fit:contain; }
.carte-verso-title { color:#fff;font-size:7pt;font-weight:600;letter-spacing:0.5px; }
.carte-verso-badge { margin-left:auto;background:#ffdd0e;border-radius:2mm;padding:0.5mm 2mm;font-size:5pt;font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:30mm; }
.carte-verso-body  { flex:1;display:flex;align-items:center;justify-content:center;padding:3mm; }
.carte-verso-ft    { height:6mm;padding:0 3mm;border-top:0.3mm solid rgba(255,255,255,.2);display:flex;justify-content:space-between;align-items:center;font-size:5pt;color:rgba(255,255,255,.75); }
</style>
</head>
<body>
<div class="page-a4">
<div class="cartes-grid">
<?php foreach ( $eleves as $el ) :
    $token_url = home_url('/fiche-membre/?token=' . rawurlencode($el->token ?? ''));
    $qr_url    = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($token_url);
?>
<div class="carte-wrap">
    <!-- RECTO -->
    <div class="carte-recto">
        <div class="carte-band-blue"></div>
        <div class="carte-band-yellow"></div>
        <div class="carte-band-red"></div>
        <?php if ($logo_url): ?>
        <div class="carte-logo-wrap">
            <img src="<?php echo esc_url($logo_url); ?>" class="carte-logo">
        </div>
        <?php endif; ?>
        <div class="carte-club-name"><?php echo esc_html(strtoupper($club)); ?></div>
        <div class="carte-club-sub">CARTE DE MEMBRE</div>
        <div class="carte-sep"></div>
        <div class="carte-nom"><?php echo esc_html($el->prenom . ' ' . mb_strtoupper($el->nom)); ?></div>
        <div class="carte-meta"><?php echo esc_html($meta_str); ?></div>
        <div class="carte-infos">
            <?php if ($el->licence): ?>
            <div class="carte-info-row">
                <span class="carte-info-k">Licence</span>
                <span class="carte-info-v"><?php echo esc_html($el->licence); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($el->date_naissance && $el->annee_naissance): ?>
            <div class="carte-info-row">
                <span class="carte-info-k">Né(e) le</span>
                <span class="carte-info-v"><?php echo esc_html($el->date_naissance.'/'.$el->annee_naissance); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($el->urgence_telephone)): ?>
            <div class="carte-info-row">
                <span class="carte-info-k">Urgence</span>
                <span class="carte-info-v" style="color:#e30613;"><?php echo esc_html($el->urgence_telephone); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($el->photo_url)): ?>
        <div class="carte-photo-wrap">
            <img src="<?php echo esc_url($el->photo_url); ?>" class="carte-photo">
        </div>
        <?php endif; ?>
        <div class="carte-qr-zone">
            <div class="carte-qr-box">
                <img src="<?php echo esc_url($qr_url); ?>" alt="QR">
            </div>
            <?php if ($club_labelise > 0): ?>
            <div class="carte-stars">
                <?php for($i=1;$i<=5;$i++) echo '<span style="color:'.($i<=$club_labelise?'#ffdd0e':'rgba(255,255,255,0.2)').';font-size:3pt;">★</span>'; ?>
            </div>
            <?php endif; ?>
            <div class="carte-qr-scan">Scanner pour pointer</div>
        </div>
        <div class="carte-url">tkdclaira.fr</div>
    </div>
    <!-- VERSO -->
    <div class="carte-verso">
        <div class="carte-verso-hd">
            <?php if ($logo_url): ?>
            <img src="<?php echo esc_url($logo_url); ?>" class="carte-verso-logo">
            <?php endif; ?>
            <span class="carte-verso-title"><?php echo esc_html(strtoupper($club)); ?></span>
            <span class="carte-verso-badge"><?php echo esc_html($el->prenom . ' ' . mb_strtoupper($el->nom)); ?></span>
        </div>
        <div class="carte-verso-body">
            <div style="color:rgba(255,255,255,.06);font-size:20pt;user-select:none;">🥋</div>
        </div>
        <div class="carte-verso-ft">
            <span><?php echo esc_html($meta_str ?: 'tkdclaira.fr'); ?></span>
            <?php if ($club_labelise > 0): ?>
            <span><?php for($i=1;$i<=5;$i++) echo '<span style="color:'.($i<=$club_labelise?'#e30613':'rgba(255,255,255,.12)').';font-size:3pt;">★</span>'; ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div><!-- .cartes-grid -->
</div><!-- .page-a4 -->
<script>window.onload = function(){ window.print(); }</script>
</body>
</html>
<?php
        return;
        endif;

        // ── MODE SÉLECTION : interface admin ──────────────────────────────
        $saisons = $this->db->get_saisons();
        ?>
        <div class="wrap" style="max-width:780px;">

        <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);border-radius:12px;padding:28px 32px;margin-bottom:24px;display:flex;align-items:center;gap:24px;">
            <?php if ( $logo_url ) : ?>
            <div style="width:56px;height:56px;border-radius:10px;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <img src="<?php echo esc_url($logo_url); ?>" style="width:48px;height:48px;object-fit:contain;">
            </div>
            <?php endif; ?>
            <div>
                <h1 style="color:#fff;font-size:20px;font-weight:700;margin:0 0 4px;">Cartes de membre</h1>
                <p style="color:rgba(255,255,255,.5);font-size:13px;margin:0;">Impression en lot · Format carte bancaire 85,6 × 54 mm · Recto + Verso</p>
            </div>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px 28px;margin-bottom:20px;">
            <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;margin-bottom:16px;">Filtres</div>
            <form method="get" action="">
                <input type="hidden" name="page" value="sp-cal-print-cartes">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                    <div>
                        <label style="display:block;font-weight:600;font-size:12px;color:#374151;margin-bottom:6px;">Catégorie d'âge</label>
                        <select name="cat" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:9px 12px;font-size:14px;background:#fff;color:#111;">
                            <option value="">— Toutes les catégories —</option>
                            <?php foreach ( $cats as $c ) : ?>
                            <option value="<?php echo esc_attr($c); ?>" <?php selected($cat_sel,$c); ?>><?php echo esc_html($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;font-size:12px;color:#374151;margin-bottom:6px;">Saison</label>
                        <select name="saison" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:9px 12px;font-size:14px;background:#fff;color:#111;">
                            <option value="">— Toutes les saisons —</option>
                            <?php foreach ( $saisons as $s ) : ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($saison,$s); ?>><?php echo esc_html($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="button" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 18px;font-size:13px;cursor:pointer;">🔍 Filtrer</button>
            </form>
        </div>

        <?php if ( $eleves ) : ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px 28px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
                <div>
                    <span style="font-size:28px;font-weight:700;color:#0f172a;"><?php echo count($eleves); ?></span>
                    <span style="font-size:14px;color:#64748b;margin-left:6px;">carte(s) à imprimer</span>
                    <?php if ( $cat_sel || $saison ) : ?>
                    <div style="margin-top:4px;">
                        <?php if ( $cat_sel ) echo '<span style="display:inline-block;background:#eff6ff;color:#1d4ed8;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600;margin-right:4px;">' . esc_html($cat_sel) . '</span>'; ?>
                        <?php if ( $saison )  echo '<span style="display:inline-block;background:#f0fdf4;color:#15803d;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600;">' . esc_html($saison) . '</span>'; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <a id="sp-print-btn"
                   href="<?php echo esc_url( add_query_arg( array(
                       'page'   => 'sp-cal-print-cartes',
                       'cat'    => $cat_sel,
                       'saison' => $saison,
                       'print'  => '1',
                   ), admin_url('admin.php') ) ); ?>"
                   target="_blank"
                   style="display:inline-flex;align-items:center;gap:8px;background:#0f70b7;color:#fff;border-radius:8px;padding:11px 22px;font-size:14px;font-weight:600;text-decoration:none;">
                    🖨️ Lancer l'impression
                </a>
            </div>

            <div style="border-top:1px solid #f1f5f9;padding-top:16px;">
                <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;">Options d'impression</div>
                <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 16px;">
                    <input type="checkbox" id="sp-mode-imprimeur" value="1">
                    <div>
                        <div style="font-size:13px;font-weight:600;color:#374151;">🖨️ Mode imprimeur</div>
                        <div style="font-size:11px;color:#94a3b8;margin-top:1px;">Débord 2 mm · angles droits · traits de coupe</div>
                    </div>
                </label>
            </div>
        </div>
        <?php else : ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:40px 28px;text-align:center;">
            <div style="font-size:32px;margin-bottom:8px;">🔍</div>
            <div style="font-size:14px;color:#64748b;">Aucun élève actif trouvé pour cette sélection.</div>
        </div>
        <?php endif; ?>

        </div>
        <script>
        document.getElementById('sp-mode-imprimeur').addEventListener('change', function () {
            var btn  = document.getElementById('sp-print-btn');
            var base = btn.href.replace(/&pro=1/, '');
            btn.href = this.checked ? base + '&pro=1' : base;
        });
        </script>
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       PAGE FICHE ELEVE
    ══════════════════════════════════════════════════════════ */

    public function page_fiche_eleve() {
        if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé' );

        global $wpdb;
        $tel      = $this->db->table_eleves();
        $eleve_id = intval( $_GET['eleve_id'] ?? 0 );
        if ( ! $eleve_id ) wp_die( 'Élève introuvable.' );

        $el = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tel WHERE id=%d", $eleve_id ) );
        if ( ! $el ) wp_die( 'Élève introuvable.' );

        // Données calculées
        $extra       = $el->extra_data ? ( json_decode( $el->extra_data, true ) ?: array() ) : array();
        $grades_hist = $extra['grades'] ?? array();   // [ 'JJ/MM/AAAA' => 'grade' ] — import CSV
        $presences   = $this->db->get_presences_eleve( $eleve_id );
        $examens             = $this->db->get_examens_eleve( $eleve_id );
        $examens_disponibles = $this->db->get_examens_disponibles( $eleve_id );

        // Séparer présences normales et examens
        $presences_cours = array_filter( $presences, function($p){ return $p->type !== 'examen'; } );

        $nb_present = 0; $nb_absent = 0;
        foreach ( $presences_cours as $p ) {
            if ( intval($p->present) ) $nb_present++; else $nb_absent++;
        }
        $nb_total = $nb_present + $nb_absent;
        $taux     = $nb_total ? round( $nb_present / $nb_total * 100 ) : null;

        // Age calculé
        $age = null;
        if ( $el->annee_naissance && $el->date_naissance ) {
            $parts = explode( '/', $el->date_naissance );
            if ( count($parts) === 2 ) {
                $bday = DateTime::createFromFormat( 'd/m/Y', $parts[0].'/'.$parts[1].'/'.$el->annee_naissance );
                if ( $bday ) $age = $bday->diff( new DateTime() )->y;
            }
        }

        // Palmarès structuré depuis la DB
        $palmares_rows      = $this->db->get_palmares_eleve( $eleve_id );
        $palmares_stats     = $this->db->get_palmares_points_eleve( $eleve_id );
        $comps_disponibles  = $this->db->get_comps_disponibles( $eleve_id );
        $medal_pts          = $this->db->get_medal_points();

        // Grouper par compétition (event_id)
        $palmares_by_comp = array();
        foreach ( $palmares_rows as $pr ) {
            $eid = intval($pr->event_id);
            if ( ! isset( $palmares_by_comp[$eid] ) ) {
                $palmares_by_comp[$eid] = array(
                    'titre'    => $pr->comp_titre,
                    'date'     => $pr->date,
                    'categorie'=> $pr->categorie,
                    'epreuves' => array(),
                );
            }
            $palmares_by_comp[$eid]['epreuves'][] = $pr;
        }
        ?>
        <?php
        // URLs
        $back_url  = admin_url( 'admin.php?page=sp-cal-eleves' );
        $edit_url  = admin_url( 'admin.php?page=sp-cal-eleves&sp_edit_eleve=' . $eleve_id );

        // Couleur catégorie saisie
        $cat_colors = json_decode( get_option( 'sp_cal_cat_colors', '{}' ), true ) ?: array();
        $cat_color  = $cat_colors[ $el->categorie_saisie ]['color'] ?? '#2271b1';
        $cat_icon   = $cat_colors[ $el->categorie_saisie ]['icon']  ?? '';
        ?>
        <div class="wrap sp-cal-wrap sp-fiche-wrap">

        <!-- Barre de navigation -->
        <div class="sp-fiche-nav">
            <a href="<?php echo esc_url($back_url); ?>" class="button">
                ← Retour à la liste
            </a>
            <div class="sp-fiche-nav-title">
                <?php if($el->categorie_saisie): ?>
                <span class="sp-fiche-pill" style="background:<?php echo esc_attr($cat_color); ?>;">
                    <?php echo esc_html( ($cat_icon ? $cat_icon . ' ' : '') . $this->db->label_discipline($el->categorie_saisie) ); ?>
                </span>
                <?php endif; ?>
                <?php if($el->categorie_age): ?>
                <span class="sp-fiche-pill sp-fiche-pill-age"><?php echo esc_html($el->categorie_age); ?></span>
                <?php endif; ?>
                <?php if($el->saison): ?>
                <span class="sp-muted" style="font-size:13px;"><?php echo esc_html($el->saison); ?></span>
                <?php endif; ?>
            </div>
            <a href="<?php echo esc_url($edit_url); ?>" class="button button-primary">
                ✏️ Modifier
            </a>
            <a href="<?php echo esc_url( wp_nonce_url(
                add_query_arg(array('sp_cal_print'=>'fiche_eleve','eleve_id'=>$eleve_id), home_url('/') ),
                'sp_cal_print'
            ) ); ?>" target="_blank" class="button">
                🖨️ Imprimer la fiche
            </a>
            <?php if($el->licence || $el->email || $el->email_parent): ?>
            <button type="button" id="sp-btn-resend-token" class="button"
                    data-eleve-id="<?php echo intval($eleve_id); ?>">
                📧 Renvoyer le lien d'accès
            </button>
            <span id="sp-token-result" style="font-size:12px;color:#15803d;display:none;"></span>
            <?php endif; ?>
        </div>

        <!-- En-tête identité -->
        <div class="sp-fiche-header">
            <div class="sp-fiche-avatar">
                <?php echo esc_html( mb_strtoupper( mb_substr($el->prenom, 0, 1) . mb_substr($el->nom, 0, 1) ) ); ?>
            </div>
            <div class="sp-fiche-identity">
                <h1><?php echo esc_html( $el->prenom . ' ' . mb_strtoupper($el->nom) ); ?></h1>
                <div class="sp-fiche-meta">
                    <?php if($el->date_naissance): ?>
                    <span>🎂 <?php
                        echo esc_html( $el->date_naissance . ($el->annee_naissance ? '/' . $el->annee_naissance : '') );
                        if($age) echo ' <span class="sp-muted">(' . $age . ' ans)</span>';
                    ?></span>
                    <?php endif; ?>
                    <?php if($el->licence): ?>
                    <span>🪪 <?php
                        echo esc_html($el->licence);
                        if ( ! intval($el->actif ?? 1) ) {
                            echo ' <span class="sp-lic-badge sp-lic-expired">❌ Inactif</span>';
                            if ($el->motif_inactif) echo ' <span class="sp-muted" style="font-size:11px;">— ' . esc_html($el->motif_inactif) . '</span>';
                        } else {
                            echo ' <span class="sp-lic-badge sp-lic-ok">✅ Actif</span>';
                            // Alerte fin de saison sur la fiche
                            $fin_s   = get_option('sp_cal_fin_saison','');
                            $alerte  = intval(get_option('sp_cal_alerte_jours', 60));
                            if ($fin_s) {
                                $jours_r = intval(ceil((strtotime($fin_s) - time()) / 86400));
                                if ($jours_r < 0)
                                    echo ' <span class="sp-lic-badge sp-lic-expired" title="Saison terminée">Saison expirée</span>';
                                elseif ($jours_r <= $alerte)
                                    echo ' <span class="sp-lic-badge sp-lic-expiring" title="Fin de saison dans '.$jours_r.' jours">⚠️ '.$jours_r.'j</span>';
                            }
                        }
                    ?></span>
                    <?php endif; ?>
                    <?php if($el->rang): ?>
                    <span class="sp-muted">Rang <?php echo intval($el->rang); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if($nb_total > 0): ?>
            <div class="sp-fiche-taux">
                <div class="sp-taux-circle" style="--pct:<?php echo $taux; ?>">
                    <svg viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="#e5e7eb" stroke-width="3"/>
                        <circle cx="18" cy="18" r="15.9" fill="none"
                            stroke="<?php echo $taux >= 75 ? '#22c55e' : ($taux >= 50 ? '#f59e0b' : '#ef4444'); ?>"
                            stroke-width="3"
                            stroke-dasharray="<?php echo round($taux * 1.0, 1); ?> 100"
                            stroke-dashoffset="25"
                            stroke-linecap="round"
                            transform="rotate(-90 18 18)"/>
                    </svg>
                    <span><?php echo $taux; ?>%</span>
                </div>
                <div class="sp-taux-label">
                    Présence<br>
                    <span class="sp-muted"><?php echo $nb_present; ?>/<?php echo $nb_total; ?> cours</span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Grille info + grades -->
        <div class="sp-fiche-grid">

            <!-- Colonne gauche : Informations -->
            <div class="sp-box">
                <h2>📋 Informations</h2>
                <table class="sp-fiche-info-table">
                    <?php
                    $row_info = function($label, $val, $link=false) {
                        if ( empty($val) ) return;
                        echo '<tr><th>' . $label . '</th><td>';
                        if ($link) echo '<a href="' . esc_attr($link) . '">' . esc_html($val) . '</a>';
                        else       echo esc_html($val);
                        echo '</td></tr>';
                    };
                    $row_info( 'Téléphone',    $el->telephone,    $el->telephone    ? 'tel:'    . $el->telephone    : false );
                    $row_info( 'Email',        $el->email,        $el->email        ? 'mailto:' . $el->email        : false );
                    $row_info( 'Email parent', $el->email_parent, $el->email_parent ? 'mailto:' . $el->email_parent : false );
                    ?>
                </table>
                <?php if ( empty($el->telephone) && empty($el->email) && empty($el->email_parent) ): ?>
                    <p class="sp-muted">Aucun contact renseigné.</p>
                <?php endif; ?>
            </div>

            <!-- Colonne droite : Grades -->
            <div class="sp-box">
                <h2>🥋 Grade actuel</h2>
                <?php if($el->grade): ?>
                <div class="sp-fiche-grade-current">
                    <span class="sp-fiche-grade-badge"><?php echo esc_html($el->grade); ?></span>
                </div>
                <p class="sp-muted" style="font-size:12px;margin-top:8px;">
                    L'historique complet des passages de grade est affiché ci-dessous.
                </p>
                <?php else: ?>
                    <p class="sp-muted">Aucun grade renseigné.</p>
                <?php endif; ?>
            </div>

        </div><!-- .sp-fiche-grid -->

        <?php
        // Préparer données palmarès une seule fois
        $has_palmares_db  = ! empty( $palmares_by_comp );
        $has_palmares_txt = ! empty( $el->palmares ) && ! $has_palmares_db;
        $med_icons  = array( 'or' => '🥇', 'argent' => '🥈', 'bronze' => '🥉' );
        $med_labels = array( 'or' => 'Or',  'argent' => 'Argent',  'bronze' => 'Bronze' );

        // Récupérer la liste de toutes les compétitions liées (source de vérité pour le bloc unifié)
        $comps_liees_unified = $this->db->get_comps_eleve( $eleve_id );
        ?>

        <!-- ══ BLOC UNIFIÉ : Compétitions & Palmarès ══════════ -->
        <div class="sp-box" id="sp-fiche-comp-link-box" data-eleve-id="<?php echo intval($eleve_id); ?>">

            <!-- En-tête avec titre + badge points + lien global -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
                <h2 style="margin:0;">🏆 Compétitions &amp; Palmarès</h2>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <?php if ( $palmares_stats['total_pts'] > 0 ) : ?>
                    <div class="sp-palmares-fiche-pts-badge">
                        <span class="sp-palmares-pts-total">🏅 <?php echo $palmares_stats['total_pts']; ?> pt<?php echo $palmares_stats['total_pts'] > 1 ? 's' : ''; ?></span>
                        <span class="sp-palmares-pts-detail sp-muted">
                            <?php
                            $badge_parts = array();
                            if ($palmares_stats['nb_or'])     $badge_parts[] = '🥇×' . $palmares_stats['nb_or'];
                            if ($palmares_stats['nb_argent']) $badge_parts[] = '🥈×' . $palmares_stats['nb_argent'];
                            if ($palmares_stats['nb_bronze']) $badge_parts[] = '🥉×' . $palmares_stats['nb_bronze'];
                            echo implode(' ', $badge_parts);
                            ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-palmares')); ?>"
                       style="font-size:12px;color:#6b7280;">→ Voir le palmarès global</a>
                </div>
            </div>

            <?php if ( empty($comps_liees_unified) ) : ?>
            <p class="sp-muted" id="sp-comp-link-empty-msg">Aucune compétition liée à cet élève.</p>

            <?php else : ?>

            <?php foreach ( $comps_liees_unified as $comp_row ) :
                $cid      = intval($comp_row->id);
                $d_obj    = $comp_row->date ? date_create($comp_row->date) : null;
                $date_f   = $d_obj ? $d_obj->format('d/m/Y') : $comp_row->date;
                $comp_url = admin_url('admin.php?page=sp-cal-palmares&vue=competition&event_id=' . $cid);
                // Épreuves + résultats pour cette compétition (depuis palmares_by_comp déjà calculé)
                $comp_data = $palmares_by_comp[$cid] ?? null;
                // Points de cette compétition
                $comp_pts = 0;
                if ($comp_data) {
                    foreach ($comp_data['epreuves'] as $ep_r) {
                        if ($ep_r->medaille && isset($medal_pts[$ep_r->medaille]))
                            $comp_pts += $medal_pts[$ep_r->medaille];
                    }
                }
            ?>
            <div class="sp-fiche-comp-unified" id="sp-comp-row-<?php echo $cid; ?>">

                <!-- Ligne de titre compétition -->
                <div class="sp-fiche-comp-unified-header">
                    <div style="display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap;">
                        <span style="font-size:12px;color:#6b7280;white-space:nowrap;">📅 <?php echo esc_html($date_f); ?></span>
                        <a href="<?php echo esc_url($comp_url); ?>" style="font-weight:700;color:#1e3a5f;text-decoration:none;font-size:14px;">
                            <?php echo esc_html($comp_row->titre); ?>
                        </a>
                        <?php if ($comp_row->categorie) : ?>
                        <span class="sp-badge-blue"><?php echo esc_html($comp_row->categorie); ?></span>
                        <?php endif; ?>
                        <?php if ($comp_pts > 0) : ?>
                        <span class="sp-palmares-fiche-comp-pts">+<?php echo $comp_pts; ?> pt<?php echo $comp_pts > 1 ? 's' : ''; ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="button button-small sp-btn-unlink-comp sp-btn-del"
                            data-event-id="<?php echo $cid; ?>"
                            title="Retirer cet élève de cette compétition">✕</button>
                </div>

                <!-- Tableau des épreuves + résultats -->
                <?php if ($comp_data && ! empty($comp_data['epreuves'])) : ?>
                <table class="sp-palmares-compact-table" style="margin-top:8px;">
                    <thead><tr>
                        <th>Épreuve</th>
                        <th style="width:100px;">Modalité</th>
                        <th style="width:120px;">Résultat</th>
                        <th style="width:45px;text-align:right;">Pts</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($comp_data['epreuves'] as $ep_row) :
                        $med    = $ep_row->medaille ?: '';
                        $ep_pts = ($med && isset($medal_pts[$med])) ? $medal_pts[$med] : 0;
                    ?>
                    <tr class="<?php echo $med ? 'sp-palm-ep-medal' : ''; ?>">
                        <td><strong><?php echo esc_html($ep_row->epreuve_nom); ?></strong></td>
                        <td>
                            <?php if ($ep_row->epreuve_modalite) : ?>
                                <span class="sp-muted" style="font-size:11px;"><?php echo esc_html($ep_row->epreuve_modalite); ?></span>
                            <?php else : ?>
                                <span class="sp-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($med) : ?>
                                <span class="sp-palm-medal-chip sp-palm-medal-<?php echo esc_attr($med); ?>">
                                    <?php echo $med_icons[$med]; ?> <?php echo $med_labels[$med]; ?>
                                </span>
                                <?php if ($ep_row->score) echo '<span class="sp-muted" style="font-size:10px;display:block;margin-top:1px;">' . esc_html($ep_row->score) . '</span>'; ?>
                            <?php elseif ($ep_row->score) : ?>
                                <span class="sp-muted" style="font-size:12px;"><?php echo esc_html($ep_row->score); ?></span>
                            <?php else : ?>
                                <span class="sp-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;font-weight:700;font-size:13px;color:<?php echo $ep_pts > 0 ? '#15803d' : '#9ca3af'; ?>;">
                            <?php echo $ep_pts > 0 ? '+' . $ep_pts : '—'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else : ?>
                <p class="sp-muted" style="font-size:12px;margin:6px 0 0 4px;">
                    Aucune épreuve enregistrée pour cette compétition.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-palmares&vue=competition&event_id=' . $cid)); ?>" style="font-size:11px;">Saisir les résultats →</a>
                </p>
                <?php endif; ?>

            </div><!-- .sp-fiche-comp-unified -->
            <?php endforeach; ?>

            <?php if ( $has_palmares_txt ) : ?>
            <div style="padding:10px;background:#f9fafb;border-radius:6px;font-size:13px;line-height:1.7;margin-bottom:12px;">
                <?php echo nl2br(esc_html($el->palmares)); ?>
            </div>
            <p class="sp-muted" style="font-size:11px;">💡 Palmarès texte libre (ancien format). Utilisez les compétitions du calendrier pour un palmarès structuré.</p>
            <?php endif; ?>

            <?php endif; // comps_liees_unified not empty ?>

            <!-- ── Lier à une compétition ─────────────────────── -->
            <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f1;">
                <strong style="font-size:13px;">➕ Lier à une compétition</strong>
                <?php if ( empty($comps_disponibles) ) : ?>
                    <p class="sp-muted" style="margin:6px 0 0;">
                        Aucune compétition disponible.
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-pro')); ?>">Créer un événement "🏆 Compétition" dans le calendrier</a>, puis revenez sur cette fiche.
                    </p>
                <?php else : ?>
                    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;align-items:center;">
                        <select id="sp-link-comp-select" class="sp-input" style="min-width:260px;height:32px;">
                            <option value="">— Choisir une compétition —</option>
                            <?php foreach ( $comps_disponibles as $cv ) :
                                $cv_date = $cv->date ? date_create($cv->date) : null;
                                $cv_lbl  = esc_html($cv->titre) . ' (' . ($cv_date ? $cv_date->format('d/m/Y') : '') . ')';
                            ?>
                            <option value="<?php echo intval($cv->id); ?>"><?php echo $cv_lbl; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button button-primary" id="sp-link-comp-btn"
                                data-eleve-id="<?php echo intval($eleve_id); ?>">Lier</button>
                        <span id="sp-comp-link-result" style="font-size:12px;color:#15803d;display:none;"></span>
                    </div>
                <?php endif; ?>
            </div>
        </div><!-- #sp-fiche-comp-link-box -->

        <!-- Parcours de progression -->
        <div class="sp-box">
            <h2>🥋 Chemin de ceinture</h2>
            <?php
            $parcours_data = $this->db->get_parcours_eleve($el);
            $uid     = 'admin' . intval($eleve_id);
            $eleve   = $el;
            $parcours = $parcours_data;
            include SP_CAL_PRO_PATH . 'templates/parcours-progression.php';
            ?>
        </div>

        <!-- Passages de grade -->
        <div class="sp-box" id="sp-fiche-examens-box" data-eleve-id="<?php echo intval($eleve_id); ?>">
            <h2>🎓 Passages de grade</h2>

            <?php
            // Chronologie unifiée : examens calendrier + historique CSV
            $timeline = array();
            foreach ( $examens as $ex ) {
                $timeline[] = array(
                    'date_raw'  => $ex->date,
                    'date_ts'   => $ex->date ? strtotime($ex->date) : 0,
                    'source'    => 'calendrier',
                    'titre'     => $ex->titre,
                    'categorie' => $ex->categorie,
                    'grade'     => $ex->note,
                    'event_id'  => $ex->event_id,
                );
            }
            foreach ( $grades_hist as $date_ex => $grade_val ) {
                $parts = explode('/', $date_ex);
                $ts    = count($parts) === 3 ? mktime(0,0,0, intval($parts[1]), intval($parts[0]), intval($parts[2])) : 0;
                $timeline[] = array(
                    'date_raw'  => $date_ex,
                    'date_ts'   => $ts,
                    'source'    => 'csv',
                    'titre'     => '',
                    'categorie' => '',
                    'grade'     => $grade_val,
                    'event_id'  => null,
                );
            }
            usort($timeline, function($a, $b){ return $b['date_ts'] - $a['date_ts']; });
            ?>

            <?php if( ! empty($timeline) ): ?>
            <table class="sp-fiche-grades-table" id="sp-fiche-examens-table">
                <thead><tr>
                    <th style="width:100px;">Date</th>
                    <th>Événement</th>
                    <th>Grade obtenu</th>
                    <th style="width:40px;text-align:center;"></th>
                    <th style="width:40px;"></th>
                </tr></thead>
                <tbody>
                <?php foreach( $timeline as $trow ):
                    if ( $trow['source'] === 'calendrier' ) {
                        $d_obj     = $trow['date_raw'] ? date_create($trow['date_raw']) : null;
                        $date_disp = $d_obj ? $d_obj->format('d/m/Y') : $trow['date_raw'];
                    } else {
                        $date_disp = $trow['date_raw']; // déjà JJ/MM/AAAA
                    }
                    $grade      = $trow['grade'];
                    $is_current = ($grade !== '' && $grade !== null && $grade === $el->grade);
                ?>
                <tr <?php echo $is_current ? 'style="background:#f0fdf4;"' : ''; ?>
                    <?php if($trow['event_id']) echo 'id="sp-exam-row-' . intval($trow['event_id']) . '"'; ?>>
                    <td><strong><?php echo esc_html($date_disp); ?></strong></td>
                    <td>
                        <?php if( $trow['source'] === 'calendrier' ): ?>
                            <?php echo esc_html($trow['titre']); ?>
                            <?php if($trow['categorie'] && $trow['categorie'] !== $trow['titre']): ?>
                                <span class="sp-badge-blue"><?php echo esc_html($trow['categorie']); ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="sp-muted" style="font-size:11px;">📋 Import CSV</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($grade): ?>
                            <span class="sp-fiche-grade-badge" style="font-size:12px;padding:3px 10px;"><?php echo esc_html($grade); ?></span>
                            <?php if($is_current) echo ' <span class="sp-badge-green" style="margin-left:4px;">actuel</span>'; ?>
                        <?php elseif($trow['source'] === 'calendrier'): ?>
                            <span class="sp-muted">Candidat</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php echo $trow['source'] === 'calendrier' ? '📅' : '📋'; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <?php if($trow['source'] === 'calendrier' && $trow['event_id']): ?>
                        <button type="button" class="button button-small sp-btn-edit-exam"
                                data-event-id="<?php echo intval($trow['event_id']); ?>"
                                data-grade="<?php echo esc_attr($trow['grade'] ?? ''); ?>"
                                title="Modifier le grade">✏️</button>
                        <button type="button" class="button button-small sp-btn-unlink-exam sp-btn-del"
                                data-event-id="<?php echo intval($trow['event_id']); ?>"
                                data-grade-actuel="<?php echo esc_attr($trow['grade'] ?? ''); ?>"
                                data-is-current="<?php echo $is_current ? '1' : '0'; ?>"
                                title="Supprimer ce passage">🗑️</button>
                        <?php elseif($trow['source'] === 'csv'): ?>
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
            <?php else: ?>
            <p class="sp-muted" id="sp-examens-empty-msg">Aucun passage de grade enregistré pour cet élève.</p>
            <?php endif; ?>

            <!-- Form inline édition grade passage calendrier -->
            <div id="sp-edit-exam-form" style="display:none;margin-top:12px;padding:12px 16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;">
                <strong style="font-size:13px;">✏️ Modifier le grade obtenu</strong>
                <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap;">
                    <input type="hidden" id="sp-edit-exam-event-id">
                    <input type="text" id="sp-edit-exam-grade" placeholder="Grade obtenu (ex: 7° bleu)"
                           style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;width:200px;">
                    <button type="button" id="sp-edit-exam-save" class="button button-primary"
                            data-eleve-id="<?php echo intval($eleve_id); ?>"
                            style="height:32px;">💾 Sauvegarder</button>
                    <button type="button" id="sp-edit-exam-cancel" class="button"
                            style="height:32px;">Annuler</button>
                    <span id="sp-edit-exam-msg" style="font-size:12px;color:#15803d;"></span>
                </div>
            </div>

            <!-- Lier à un examen existant -->
            <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f1;">
                <strong style="font-size:13px;">➕ Lier à un examen</strong>
                <?php if( empty($examens_disponibles) ): ?>
                    <p class="sp-muted" style="margin:6px 0 0;">
                        Aucun examen disponible.
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-pro')); ?>">
                            Créer un événement de type "🎓 Examen" dans le calendrier
                        </a>, puis revenez sur cette fiche.
                    </p>
                <?php else: ?>
                <div style="display:flex;gap:8px;align-items:flex-end;margin-top:8px;flex-wrap:wrap;">
                    <div>
                        <label style="display:block;font-size:11px;color:#6b7280;margin-bottom:3px;">Examen</label>
                        <select id="sp-link-exam-select" style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;min-width:260px;">
                            <option value="">— Choisir un examen —</option>
                            <?php foreach($examens_disponibles as $ev):
                                $d = $ev->date ? date_create($ev->date) : null;
                                $dl= $d ? $d->format('d/m/Y') : $ev->date;
                            ?>
                            <option value="<?php echo intval($ev->id); ?>">
                                <?php echo esc_html($dl . ' — ' . $ev->titre . ($ev->categorie && $ev->categorie !== $ev->titre ? ' ('.$ev->categorie.')' : '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:#6b7280;margin-bottom:3px;">Grade obtenu <small>(optionnel)</small></label>
                        <input type="text" id="sp-link-exam-grade" placeholder="ex: 6° bleue"
                               style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;width:160px;">
                    </div>
                    <button type="button" id="sp-link-exam-btn" class="button button-primary"
                            data-eleve-id="<?php echo intval($eleve_id); ?>"
                            style="height:32px;">Lier</button>
                    <span id="sp-link-exam-msg" style="font-size:12px;color:#15803d;display:none;"></span>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- #sp-fiche-examens-box -->

        <!-- Historique présences -->
        <div class="sp-box">
            <h2>📅 Historique des présences
                <?php if($nb_total): ?>
                <span class="sp-muted" style="font-size:13px;font-weight:400;">
                    — <?php echo $nb_present; ?> présence<?php echo $nb_present > 1 ? 's' : ''; ?>
                    · <?php echo $nb_absent; ?> absence<?php echo $nb_absent > 1 ? 's' : ''; ?>
                    sur <?php echo $nb_total; ?> cours enregistrés
                </span>
                <?php endif; ?>
            </h2>

            <?php if(empty($presences_cours)): ?>
                <p class="sp-muted">Aucune présence enregistrée pour cet élève.</p>
            <?php else: ?>

            <!-- Mini filtre présences -->
            <div class="sp-filter-bar" style="margin-bottom:12px;">
                <select id="sp-fiche-flt-pres">
                    <option value="">— Tout afficher —</option>
                    <option value="1">✅ Présent seulement</option>
                    <option value="0">❌ Absent seulement</option>
                </select>
                <input type="text" id="sp-fiche-flt-cours" placeholder="🔍 Filtrer par cours…" style="width:200px;">
            </div>

            <div style="overflow-x:auto;">
            <table class="wp-list-table widefat striped" id="sp-fiche-pres-table">
                <thead><tr>
                    <th style="width:110px;">Date</th>
                    <th style="width:120px;">Horaire</th>
                    <th>Cours</th>
                    <th style="width:80px;text-align:center;">Présence</th>
                </tr></thead>
                <tbody>
                <?php foreach($presences_cours as $p):
                    $present   = intval($p->present);
                    $date_fr   = $p->date ? date_create($p->date) : null;
                    $date_disp = $date_fr ? $date_fr->format('d/m/Y') : $p->date;
                    $jours_fr  = array('','Lun','Mar','Mer','Jeu','Ven','Sam','Dim');
                    $dow       = $date_fr ? intval($date_fr->format('N')) : 0;
                    $jour_lbl  = $dow ? $jours_fr[$dow] : '';
                ?>
                <tr data-present="<?php echo $present; ?>"
                    data-cours="<?php echo esc_attr(mb_strtolower($p->titre . ' ' . $p->categorie)); ?>">
                    <td>
                        <?php if($jour_lbl) echo '<span class="sp-muted" style="font-size:11px;">' . $jour_lbl . ' </span>'; ?>
                        <strong><?php echo esc_html($date_disp); ?></strong>
                    </td>
                    <td class="sp-muted">
                        <?php echo esc_html( $p->heure_debut . ($p->heure_fin ? ' – ' . $p->heure_fin : '') ); ?>
                    </td>
                    <td>
                        <?php echo esc_html($p->titre); ?>
                        <?php if($p->categorie && $p->categorie !== $p->titre): ?>
                            <span class="sp-badge-blue" style="margin-left:4px;"><?php echo esc_html($p->categorie); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-size:18px;">
                        <?php echo $present ? '✅' : '❌'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <script>
            (function(){
                var fPres  = document.getElementById('sp-fiche-flt-pres');
                var fCours = document.getElementById('sp-fiche-flt-cours');
                var rows   = document.querySelectorAll('#sp-fiche-pres-table tbody tr');
                function filter() {
                    var pres  = fPres.value;
                    var cours = fCours.value.toLowerCase();
                    rows.forEach(function(r){
                        var ok = ( pres  === '' || r.dataset.present === pres )
                              && ( cours === '' || r.dataset.cours.indexOf(cours) !== -1 );
                        r.style.display = ok ? '' : 'none';
                    });
                }
                fPres.addEventListener('change', filter);
                fCours.addEventListener('input',  filter);
            })();
            </script>

            <?php endif; ?>
        </div>

        </div><!-- .sp-fiche-wrap -->
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       PAGE LICENCES
    ══════════════════════════════════════════════════════════ */

    public function page_licences() {
        if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé' );

        $saisons       = $this->db->get_saisons();
        $saison        = sanitize_text_field( $_GET['saison'] ?? '' );
        $status_filter = sanitize_text_field( $_GET['statut'] ?? '' );
        $adh           = $this->db->get_adhesions_counts( $saison );
        $eleves        = $this->db->get_adhesions_all( $saison, $status_filter );
        $fin_saison    = get_option( 'sp_cal_fin_saison', '' );
        $alerte_jours  = intval( get_option( 'sp_cal_alerte_jours', 60 ) );

        $actif_count   = count( $this->db->get_adhesions_all( $saison, 'actif' ) );
        $inactif_count = count( $this->db->get_adhesions_all( $saison, 'inactif' ) );
        $total_count   = $actif_count + $inactif_count;

        $statut_labels = array(
            ''        => '— Tous (' . $total_count . ') —',
            'actif'   => '✅ Actifs (' . $actif_count . ')',
            'inactif' => '❌ Inactifs (' . $inactif_count . ')',
        );
        ?>
        <div class="wrap sp-cal-wrap">
        <h1>🪪 Adhésions</h1>
        <?php $this->notice_flash('saved', 'Date de fin de saison enregistrée.'); ?>

        <!-- Date fin de saison -->
        <div class="sp-box" style="margin-bottom:20px;">
            <h2>📅 Fin de saison</h2>
            <form method="post" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
                <?php wp_nonce_field('sp_cal_fin_saison'); ?>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">
                        Date de fin de la saison sportive
                    </label>
                    <input type="date" name="sp_fin_saison"
                           value="<?php echo esc_attr($fin_saison); ?>"
                           style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">
                        Alerter à partir de
                    </label>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <input type="number" name="sp_alerte_jours" min="1" max="365"
                               value="<?php echo esc_attr($alerte_jours); ?>"
                               style="height:32px;width:70px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;">
                        <span style="font-size:13px;color:#374151;">jours avant la fin de saison</span>
                    </div>
                </div>
                <input type="submit" name="sp_save_fin_saison" class="button button-primary" value="Enregistrer">
            </form>
            <?php if ( $fin_saison ) :
                $jours = intval(ceil((strtotime($fin_saison) - time()) / 86400));
                $color = $jours < 0 ? '#b91c1c' : ($jours <= 30 ? '#b45309' : '#15803d');
            ?>
            <p style="margin-top:8px;font-size:13px;">
                Fin de saison : <strong><?php echo date('d/m/Y', strtotime($fin_saison)); ?></strong>
                — <strong style="color:<?php echo $color; ?>;">
                    <?php echo $jours < 0 ? 'terminée depuis ' . abs($jours) . ' jours' : $jours . ' jours restants'; ?>
                </strong>
            </p>
            <?php endif; ?>
        </div>

        <div class="sp-box" style="margin-bottom:20px;">
            <h2>🩺 Certificat médical</h2>
            <?php if ( isset( $_GET['certif_saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Durée de validité enregistrée.</p></div>
            <?php endif; ?>
            <p class="description" style="margin-bottom:10px;">
                Durée au-delà de laquelle le certificat médical déposé au Taekwondo (date renseignée sur le formulaire d'adhésion) est signalé comme à renouveler — alerte informative uniquement, elle ne bloque jamais l'envoi du formulaire ni la validation d'une demande.
                Par défaut 12 mois, conformément au règlement FFTDA pour le Taekwondo en compétition.
            </p>
            <form method="post" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
                <?php wp_nonce_field('sp_cal_certif_medical'); ?>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">
                        Durée de validité (mois)
                    </label>
                    <input type="number" name="sp_certif_medical_mois" min="1" max="60"
                           value="<?php echo esc_attr( intval( get_option( 'sp_cal_certif_medical_mois', 12 ) ) ); ?>"
                           style="height:32px;width:70px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;">
                </div>
                <input type="submit" name="sp_save_certif_medical" class="button button-primary" value="Enregistrer">
            </form>
        </div>

        <?php SP_Cal_Renouvellement::get_instance( $this->db )->render_box(); ?>

        <!-- KPIs -->
        <div class="sp-stats-kpi-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
            <?php
            $kpis = array(
                array('val'=>$total_count,   'lbl'=>'Adhérents total', 'color'=>'#2271b1', 'filter'=>''),
                array('val'=>$actif_count,   'lbl'=>'Actifs',          'color'=>'#15803d', 'filter'=>'actif'),
                array('val'=>$inactif_count, 'lbl'=>'Inactifs',        'color'=>'#b91c1c', 'filter'=>'inactif'),
            );
            foreach($kpis as $k):
                $active = ($status_filter === $k['filter']);
                $url    = add_query_arg(array('page'=>'sp-cal-licences','statut'=>$k['filter'],'saison'=>$saison), admin_url('admin.php'));
            ?>
            <a href="<?php echo esc_url($url); ?>" style="text-decoration:none;">
            <div class="sp-stats-kpi" style="border-top:3px solid <?php echo $k['color']; ?>;<?php echo $active?'box-shadow:0 0 0 2px '.$k['color'].';':''; ?>cursor:pointer;">
                <div class="sp-stats-kpi-val" style="color:<?php echo $k['color']; ?>;"><?php echo intval($k['val']); ?></div>
                <div class="sp-stats-kpi-lbl"><?php echo esc_html($k['lbl']); ?></div>
            </div>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Filtres -->
        <div class="sp-filter-bar" style="margin-bottom:16px;flex-wrap:wrap;gap:8px;">
            <select onchange="location.href=this.value">
                <?php foreach($statut_labels as $val=>$lbl):
                    $url = add_query_arg(array('page'=>'sp-cal-licences','statut'=>$val,'saison'=>$saison), admin_url('admin.php'));
                ?>
                <option value="<?php echo esc_attr($url); ?>" <?php selected($status_filter,$val); ?>><?php echo esc_html($lbl); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if(!empty($saisons)): ?>
            <select onchange="location.href=this.value">
                <option value="<?php echo esc_attr(add_query_arg(array('page'=>'sp-cal-licences','statut'=>$status_filter,'saison'=>''), admin_url('admin.php'))); ?>"
                    <?php selected($saison,''); ?>>— Toutes les saisons —</option>
                <?php foreach($saisons as $s):
                    $url = add_query_arg(array('page'=>'sp-cal-licences','statut'=>$status_filter,'saison'=>$s), admin_url('admin.php'));
                ?>
                <option value="<?php echo esc_attr($url); ?>" <?php selected($saison,$s); ?>><?php echo esc_html($s); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="text" id="sp-lic-search" placeholder="🔍 Nom…"
                   style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;width:200px;">
        </div>

        <!-- Tableau -->
        <?php if(empty($eleves)): ?>
        <div class="sp-box">
            <p class="sp-muted" style="text-align:center;padding:20px;">Aucun adhérent trouvé pour ces filtres.</p>
        </div>
        <?php else: ?>
        <div class="sp-box" style="padding:0;">
        <table class="wp-list-table widefat striped" id="sp-lic-table">
            <thead><tr>
                <th>Élève</th>
                <th>Catégorie</th>
                <th>N° Licence</th>
                <th style="width:110px;text-align:center;">Statut</th>
                <th>Motif d'inactivité</th>
                <th style="width:60px;"></th>
            </tr></thead>
            <tbody>
            <?php foreach($eleves as $el):
                $actif     = intval($el->actif ?? 1);
                $fiche_url = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id='.$el->id);
                $edit_url  = admin_url('admin.php?page=sp-cal-eleves&sp_edit_eleve='.$el->id);
            ?>
            <tr data-name="<?php echo esc_attr(mb_strtolower($el->nom.' '.$el->prenom)); ?>"
                style="<?php echo !$actif ? 'opacity:.7;' : ''; ?>">
                <td>
                    <a href="<?php echo esc_url($fiche_url); ?>" style="font-weight:600;">
                        <?php echo esc_html($el->prenom.' '.mb_strtoupper($el->nom)); ?>
                    </a>
                    <?php if($el->saison) echo '<br><span class="sp-muted" style="font-size:11px;">'.esc_html($el->saison).'</span>'; ?>
                </td>
                <td><?php if($el->categorie_age) echo '<span class="sp-badge-blue">'.esc_html($el->categorie_age).'</span>'; ?></td>
                <td class="sp-mono"><?php echo esc_html($el->licence ?: '—'); ?></td>
                <td style="text-align:center;">
                    <?php echo $actif
                        ? '<span class="sp-lic-badge sp-lic-ok">✅ Actif</span>'
                        : '<span class="sp-lic-badge sp-lic-expired">❌ Inactif</span>'; ?>
                </td>
                <td class="sp-muted"><?php echo esc_html($el->motif_inactif); ?></td>
                <td><a href="<?php echo esc_url($edit_url); ?>" class="button button-small" title="Modifier">✏️</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <script>
        document.getElementById('sp-lic-search').addEventListener('input', function(){
            var q = this.value.toLowerCase();
            document.querySelectorAll('#sp-lic-table tbody tr').forEach(function(r){
                r.style.display = r.dataset.name.indexOf(q) !== -1 ? '' : 'none';
            });
        });
        </script>
        <?php endif; ?>

        </div>
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       PAGE STATS
    ══════════════════════════════════════════════════════════ */

    public function page_stats() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );

        $saisons = $this->db->get_saisons();
        $saison  = sanitize_text_field( $_GET['saison'] ?? ( $saisons[0] ?? '' ) );

        $stats_groupe  = $this->db->get_stats_par_groupe( $saison );
        $stats_saisie  = $this->db->get_stats_par_saisie( $saison );
        $stats_evol    = $this->db->get_stats_evolution_mensuelle( $saison );
        $top_assidus   = $this->db->get_stats_classement( $saison, 15, 'desc' );
        $top_absents   = $this->db->get_stats_classement( $saison, 10, 'asc' );

        // Totaux globaux
        $total_eleves  = 0; $total_presents = 0; $total_total = 0;
        foreach ( $stats_groupe as $r ) {
            $total_eleves  += intval($r->nb_eleves);
            $total_presents+= intval($r->nb_presents);
            $total_total   += intval($r->nb_presents) + intval($r->nb_absents);
        }
        $taux_global = $total_total ? round($total_presents / $total_total * 100) : null;

        $mois_fr = array('01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Jun',
                         '07'=>'Jul','08'=>'Aoû','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc');

        ?>
        <div class="wrap sp-cal-wrap">
        <h1>📊 Statistiques de présence</h1>

        <!-- Filtre saison -->
        <?php if ( count($saisons) > 1 ) : ?>
        <div style="margin-bottom:20px;display:flex;align-items:center;gap:12px;">
            <strong>Saison :</strong>
            <?php foreach ( $saisons as $s ) :
                $active = ($s === $saison);
                $url    = admin_url('admin.php?page=sp-cal-stats&saison=' . urlencode($s));
            ?>
            <a href="<?php echo esc_url($url); ?>"
               class="button <?php echo $active ? 'button-primary' : ''; ?>">
                <?php echo esc_html($s); ?>
            </a>
            <?php endforeach; ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-stats&saison=')); ?>"
               class="button <?php echo !$saison ? 'button-primary' : ''; ?>">Toutes</a>
        </div>
        <?php endif; ?>

        <?php if ( ! $total_total ) : ?>
        <div class="sp-box">
            <p class="sp-muted" style="text-align:center;padding:20px;">
                Aucune présence enregistrée<?php echo $saison ? ' pour la saison ' . esc_html($saison) : ''; ?>.<br>
                Utilisez l'onglet 👥 Présences sur les événements du calendrier pour commencer.
            </p>
        </div>
        <?php else : ?>

        <!-- ── Résumé global ── -->
        <div class="sp-stats-kpi-row">
            <?php
            $kpis = array(
                array( 'val' => $total_eleves,  'lbl' => 'élèves actifs',       'color' => '#2271b1', 'icon' => '👤' ),
                array( 'val' => count($stats_evol), 'lbl' => 'mois avec cours', 'color' => '#6d28d9', 'icon' => '📅' ),
                array( 'val' => $total_presents,'lbl' => 'présences totales',   'color' => '#15803d', 'icon' => '✅' ),
                array( 'val' => ($taux_global !== null ? $taux_global.'%' : '—'), 'lbl' => 'taux global', 'color' => ($taux_global >= 75 ? '#15803d' : ($taux_global >= 50 ? '#b45309' : '#b91c1c')), 'icon' => '📈' ),
            );
            foreach ( $kpis as $k ) : ?>
            <div class="sp-stats-kpi" style="border-top:3px solid <?php echo esc_attr($k['color']); ?>;">
                <div class="sp-stats-kpi-val" style="color:<?php echo esc_attr($k['color']); ?>;">
                    <?php echo esc_html($k['icon'] . ' ' . $k['val']); ?>
                </div>
                <div class="sp-stats-kpi-lbl"><?php echo esc_html($k['lbl']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Deux colonnes : par groupe / par discipline ── -->
        <div class="sp-two-col" style="align-items:start;">

        <!-- Taux par catégorie d'âge -->
        <div class="sp-box">
            <h2>👥 Par groupe d'âge</h2>
            <?php foreach ( $stats_groupe as $r ) :
                $nb_p  = intval($r->nb_presents);
                $nb_t  = $nb_p + intval($r->nb_absents);
                $taux  = $nb_t ? round($nb_p / $nb_t * 100) : 0;
                $color = $taux >= 75 ? '#22c55e' : ($taux >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <div class="sp-stats-bar-row">
                <div class="sp-stats-bar-label">
                    <span><?php echo esc_html($r->categorie_age); ?></span>
                    <span class="sp-muted"><?php echo intval($r->nb_eleves); ?> élèves</span>
                </div>
                <div class="sp-stats-bar-track">
                    <div class="sp-stats-bar-fill" style="width:<?php echo $taux; ?>%;background:<?php echo $color; ?>;"></div>
                </div>
                <div class="sp-stats-bar-pct" style="color:<?php echo $color; ?>;"><?php echo $taux; ?>%</div>
                <div class="sp-muted" style="font-size:11px;min-width:70px;text-align:right;">
                    <?php echo $nb_p; ?>/<?php echo $nb_t; ?> prés.
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Taux par catégorie saisie -->
        <div class="sp-box">
            <h2>🥋 Par discipline</h2>
            <?php if ( empty($stats_saisie) ) : ?>
                <p class="sp-muted">Aucune catégorie saisie configurée.</p>
            <?php else :
                foreach ( $stats_saisie as $r ) :
                    $nb_p  = intval($r->nb_presents);
                    $nb_t  = $nb_p + intval($r->nb_absents);
                    $taux  = $nb_t ? round($nb_p / $nb_t * 100) : 0;
                    $color = $taux >= 75 ? '#22c55e' : ($taux >= 50 ? '#f59e0b' : '#ef4444');
                ?>
                <div class="sp-stats-bar-row">
                    <div class="sp-stats-bar-label">
                        <span><?php echo esc_html( $this->db->label_discipline($r->categorie_saisie) ); ?></span>
                        <span class="sp-muted"><?php echo intval($r->nb_eleves); ?> élèves</span>
                    </div>
                    <div class="sp-stats-bar-track">
                        <div class="sp-stats-bar-fill" style="width:<?php echo $taux; ?>%;background:<?php echo $color; ?>;"></div>
                    </div>
                    <div class="sp-stats-bar-pct" style="color:<?php echo $color; ?>;"><?php echo $taux; ?>%</div>
                    <div class="sp-muted" style="font-size:11px;min-width:70px;text-align:right;">
                        <?php echo $nb_p; ?>/<?php echo $nb_t; ?> prés.
                    </div>
                </div>
                <?php endforeach;
            endif; ?>
        </div>

        </div><!-- .sp-two-col -->

        <!-- ── Évolution mensuelle ── -->
        <?php if ( ! empty($stats_evol) ) : ?>
        <div class="sp-box">
            <h2>📈 Évolution mensuelle</h2>
            <?php
            $max_presents = max( array_map(function($r){ return intval($r->nb_presents); }, $stats_evol) ) ?: 1;
            ?>
            <div class="sp-stats-evol-wrap">
                <?php foreach ( $stats_evol as $r ) :
                    $parts  = explode('-', $r->mois);
                    $lbl    = ($mois_fr[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
                    $nb_p   = intval($r->nb_presents);
                    $nb_t   = intval($r->nb_total);
                    $taux   = $nb_t ? round($nb_p / $nb_t * 100) : 0;
                    $height = round($nb_p / $max_presents * 100);
                    $color  = $taux >= 75 ? '#22c55e' : ($taux >= 50 ? '#f59e0b' : '#ef4444');
                ?>
                <div class="sp-stats-evol-col" title="<?php echo esc_attr($lbl . ' : ' . $nb_p . ' présences (' . $taux . '%)'); ?>">
                    <div class="sp-stats-evol-bar-wrap">
                        <div class="sp-stats-evol-bar" style="height:<?php echo $height; ?>%;background:<?php echo $color; ?>;"></div>
                    </div>
                    <div class="sp-stats-evol-val"><?php echo $nb_p; ?></div>
                    <div class="sp-stats-evol-lbl"><?php echo esc_html($lbl); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Classements ── -->
        <div class="sp-two-col" style="align-items:start;">

        <!-- Top assidus -->
        <div class="sp-box">
            <h2>🏆 Les plus assidus</h2>
            <table class="wp-list-table widefat striped">
                <thead><tr>
                    <th style="width:30px;">#</th>
                    <th>Élève</th>
                    <th>Groupe</th>
                    <th style="width:80px;text-align:center;">Taux</th>
                    <th style="width:70px;text-align:right;">Présences</th>
                </tr></thead>
                <tbody>
                <?php foreach ($top_assidus as $i => $r) :
                    $taux  = intval($r->taux);
                    $color = $taux >= 75 ? '#15803d' : ($taux >= 50 ? '#b45309' : '#b91c1c');
                    $fiche = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . intval($r->id));
                ?>
                <tr>
                    <td class="sp-muted"><?php echo $i+1; ?></td>
                    <td>
                        <a href="<?php echo esc_url($fiche); ?>" style="font-weight:600;">
                            <?php echo esc_html($r->prenom . ' ' . $r->nom); ?>
                        </a>
                        <?php if($r->grade) echo '<br><span class="sp-muted" style="font-size:11px;">' . esc_html($r->grade) . '</span>'; ?>
                    </td>
                    <td>
                        <?php if($r->categorie_age) echo '<span class="sp-badge-blue">' . esc_html($r->categorie_age) . '</span>'; ?>
                    </td>
                    <td style="text-align:center;">
                        <strong style="color:<?php echo $color; ?>;"><?php echo $taux; ?>%</strong>
                    </td>
                    <td style="text-align:right;color:#6b7280;font-size:12px;">
                        <?php echo intval($r->nb_presents); ?>/<?php echo intval($r->nb_total); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Moins assidus -->
        <div class="sp-box">
            <h2>⚠️ Absences fréquentes</h2>
            <table class="wp-list-table widefat striped">
                <thead><tr>
                    <th style="width:30px;">#</th>
                    <th>Élève</th>
                    <th>Groupe</th>
                    <th style="width:80px;text-align:center;">Taux</th>
                    <th style="width:70px;text-align:right;">Présences</th>
                </tr></thead>
                <tbody>
                <?php foreach ($top_absents as $i => $r) :
                    $taux  = intval($r->taux);
                    $color = $taux >= 75 ? '#15803d' : ($taux >= 50 ? '#b45309' : '#b91c1c');
                    $fiche = admin_url('admin.php?page=sp-cal-fiche-eleve&eleve_id=' . intval($r->id));
                ?>
                <tr>
                    <td class="sp-muted"><?php echo $i+1; ?></td>
                    <td>
                        <a href="<?php echo esc_url($fiche); ?>" style="font-weight:600;">
                            <?php echo esc_html($r->prenom . ' ' . $r->nom); ?>
                        </a>
                        <?php if($r->grade) echo '<br><span class="sp-muted" style="font-size:11px;">' . esc_html($r->grade) . '</span>'; ?>
                    </td>
                    <td>
                        <?php if($r->categorie_age) echo '<span class="sp-badge-blue">' . esc_html($r->categorie_age) . '</span>'; ?>
                    </td>
                    <td style="text-align:center;">
                        <strong style="color:<?php echo $color; ?>;"><?php echo $taux; ?>%</strong>
                    </td>
                    <td style="text-align:right;color:#6b7280;font-size:12px;">
                        <?php echo intval($r->nb_presents); ?>/<?php echo intval($r->nb_total); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        </div><!-- .sp-two-col -->
        <?php endif; ?>

        </div><!-- .wrap -->
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       AJAX SEND TRAINER APP LINK
    ══════════════════════════════════════════════════════════ */

    public function ajax_send_trainer_app_link() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Acces refuse', 403 );

        $trainer_id = intval( $_POST['trainer_id'] ?? 0 );
        if ( ! $trainer_id ) wp_send_json_error( 'ID manquant', 400 );

        global $wpdb;
        $tt = $this->db->table_trainers();
        $t  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tt WHERE id = %d LIMIT 1", $trainer_id ) );
        if ( ! $t || empty( $t->email ) ) wp_send_json_error( 'Entraineur ou email introuvable', 404 );

        $pin = get_option( 'sp_cal_pointage_pin', '' );
        if ( ! $pin ) {
            $pin = substr( str_shuffle( '0123456789' ), 0, 4 );
            update_option( 'sp_cal_pointage_pin', $pin );
        }
        $app_url = add_query_arg( 'pin', $pin, trailingslashit( home_url( '/app/' ) ) );
        $club    = get_option( 'blogname', 'Club' );

        $subject = '[' . $club . '] Application pointage';

        $lines_body = array(
            'Bonjour ' . $t->nom . ',',
            '',
            'Voici votre lien pour acceder a l application de pointage de ' . $club . ' :',
            '',
            'APPLICATION POINTAGE :',
            $app_url,
            '',
            'Ce lien ouvre directement l interface de pointage QR.',
            'Sur iPhone : bouton Partager puis "Sur l ecran d accueil".',
            'Sur Android : menu de Chrome puis "Ajouter a l ecran d accueil".',
            '',
            'Ce lien contient votre code PIN - ne pas le partager.',
            '',
            '-- ',
            $club,
        );
        $body = implode( "\n", $lines_body );

        $sent = wp_mail( sanitize_email( $t->email ), $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );

        if ( $sent ) {
            wp_send_json_success( 'Lien envoye a ' . $t->email );
        } else {
            wp_send_json_error( 'Echec envoi email' );
        }
    }

}

endif;

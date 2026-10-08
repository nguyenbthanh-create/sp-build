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

require_once plugin_dir_path( __FILE__ ) . 'trait-members-eleves.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-members-palmares.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-members-fiche.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-members-cartes.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-members-licences-stats.php';
if ( ! class_exists( 'SP_Cal_Members' ) ) :

class SP_Cal_Members {
    use SP_Cal_Members_Licences_Stats; // trait-members-licences-stats.php

    use SP_Cal_Members_Cartes; // trait-members-cartes.php

    use SP_Cal_Members_Fiche; // trait-members-fiche.php

    use SP_Cal_Members_Palmares; // trait-members-palmares.php

    use SP_Cal_Members_Eleves; // trait-members-eleves.php


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
                // Document choisi dans la médiathèque (publique) : recopié dans le dossier protégé
                // et retiré de la médiathèque (class-docs-adhesion.php, 08/10/2026).
                'documents' => array(
                    'certificat_medical' => SP_Cal_Docs_Adhesion::securiser_url( esc_url_raw( wp_unslash( $_POST['eleve_doc_certificat_medical'] ?? '' ) ), 'certificat_medical' ),
                    'attestation_rc'     => SP_Cal_Docs_Adhesion::securiser_url( esc_url_raw( wp_unslash( $_POST['eleve_doc_attestation_rc']     ?? '' ) ), 'attestation_rc' ),
                    'decharge_honneur'   => SP_Cal_Docs_Adhesion::securiser_url( esc_url_raw( wp_unslash( $_POST['eleve_doc_decharge_honneur']   ?? '' ) ), 'decharge_honneur' ),
                    'bon_caf'            => SP_Cal_Docs_Adhesion::securiser_url( esc_url_raw( wp_unslash( $_POST['eleve_doc_bon_caf']            ?? '' ) ), 'bon_caf' ),
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

        /* ── Harmonisation des grades avec TKD Parcours ── */
        if ( isset( $_POST['sp_harmoniser_grades'] ) && check_admin_referer( 'sp_harmoniser_grades' ) ) {
            $choix = ( isset( $_POST['sp_grade_choix'] ) && is_array( $_POST['sp_grade_choix'] ) ) ? wp_unslash( $_POST['sp_grade_choix'] ) : array();
            $nb    = $this->db->appliquer_harmonisation_grades( $choix );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-eleves&grades_harmo_done=1&nb=' . $nb ) ); exit;
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
                    // Grade suivant calculé automatiquement depuis TKD Parcours (get_grade_vise_eleve()) ;
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

        $pin     = SP_Cal_Pin_Garde::pin();
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

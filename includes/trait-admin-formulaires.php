<?php
/**
 * Traitement des formulaires des pages d'administration (handle_requests(), branché sur admin_init) : entraîneurs, créneaux, paramètres…
 *
 * Méthodes de SpCalPro_Admin déplacées telles quelles depuis class-admin.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_Admin_Formulaires; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_Admin_Formulaires {


    /* ══ FIN BLOC PWA ══ */

    public function handle_requests() {

        if ( ! current_user_can( 'manage_options' ) ) return;

        // ── Export CSV inscriptions ──────────────────────────────────────
        $this->inscriptions->handle_request();
        global $wpdb;

        $tt  = $this->db->table_trainers();
        $tsl = $this->db->table_slots();
        $tel = $this->db->table_eleves();

        /* ── Date fin de saison ── */
        if ( isset( $_POST['sp_save_fin_saison'] ) && check_admin_referer( 'sp_cal_fin_saison' ) ) {
            $date   = sanitize_text_field( wp_unslash( $_POST['sp_fin_saison']    ?? '' ) );
            $jours  = max( 1, intval( $_POST['sp_alerte_jours'] ?? 60 ) );
            update_option( 'sp_cal_fin_saison',    $date );
            update_option( 'sp_cal_alerte_jours',  $jours );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&saved=1' ) ); exit;
        }

        /* ── Durée de validité du certificat médical (doléance certificat médical, 03/09/2026) ── */
        if ( isset( $_POST['sp_save_certif_medical'] ) && check_admin_referer( 'sp_cal_certif_medical' ) ) {
            $mois = max( 1, min( 60, intval( $_POST['sp_certif_medical_mois'] ?? 12 ) ) );
            update_option( 'sp_cal_certif_medical_mois', $mois );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&certif_saved=1' ) ); exit;
        }

        /* ── Convention médaille → points ── */
        if ( isset( $_POST['sp_save_medal_pts'] ) && check_admin_referer( 'sp_cal_medal_pts' ) ) {
            update_option( 'sp_cal_pts_or',     max( 0, intval( $_POST['sp_pts_or']     ?? 3 ) ) );
            update_option( 'sp_cal_pts_argent', max( 0, intval( $_POST['sp_pts_argent'] ?? 2 ) ) );
            update_option( 'sp_cal_pts_bronze', max( 0, intval( $_POST['sp_pts_bronze'] ?? 1 ) ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&pts_saved=1' ) ); exit;
        }
		
		/* ── Coefficients niveaux compétition ── */
        if ( isset( $_POST['sp_save_coef_niveaux'] ) && check_admin_referer( 'sp_cal_coef_niveaux' ) ) {
            update_option( 'sp_cal_coef_departemental',  max( 0.1, floatval( $_POST['sp_coef_dep'] ?? 1.0 ) ) );
            update_option( 'sp_cal_coef_regional',       max( 0.1, floatval( $_POST['sp_coef_reg'] ?? 1.5 ) ) );
            update_option( 'sp_cal_coef_national',       max( 0.1, floatval( $_POST['sp_coef_nat'] ?? 2.0 ) ) );
            update_option( 'sp_cal_coef_international',  max( 0.1, floatval( $_POST['sp_coef_int'] ?? 3.0 ) ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&coef_saved=1' ) ); exit;
        }

        /* ── URL fiche membre (sauvegarde dédiée) ── */
        if ( isset( $_POST['sp_save_fiche_url'] ) && check_admin_referer( 'sp_cal_fiche_url' ) ) {
            update_option( 'sp_cal_fiche_membre_url', esc_url_raw( $_POST['sp_cal_fiche_membre_url'] ?? '' ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&saved=1' ) ); exit;
        }

        /* ── URL page palmarès public (sauvegarde dédiée) ── */
        if ( isset( $_POST['sp_save_palmares_url'] ) && check_admin_referer( 'sp_cal_palmares_url' ) ) {
            update_option( 'sp_cal_palmares_url', esc_url_raw( $_POST['sp_cal_palmares_url'] ?? '' ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&saved=1' ) ); exit;
        }

        /* ── Création automatique page fiche membre ── */
        if ( isset( $_POST['sp_create_fiche_page'] ) && check_admin_referer( 'sp_cal_create_fiche_page' ) ) {
            // Vérifier qu'elle n'existe pas déjà
            global $wpdb;
            $existing = $wpdb->get_var(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_status='publish' AND post_type='page'
                 AND post_content LIKE '%sp_cal_fiche_membre%' LIMIT 1"
            );
            if ( $existing ) {
                $page_url = get_permalink( $existing );
            } else {
                $page_id = wp_insert_post( array(
                    'post_title'   => 'Ma fiche',
                    'post_name'    => 'ma-fiche',
                    'post_content' => '[sp_cal_fiche_membre]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ) );
                $page_url = $page_id ? get_permalink( $page_id ) : '';
            }
            if ( $page_url ) update_option( 'sp_cal_fiche_membre_url', $page_url );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&fiche_created=1' ) ); exit;
        }

        /* ── Paramètres généraux ── */
        // Vacances — couleurs WE / vacances scolaires
        if ( isset( $_POST['sp_cal_save_vacances'] ) && check_admin_referer( 'sp_cal_vacances' ) ) {
            update_option( 'sp_cal_we_color',  sanitize_hex_color( $_POST['sp_cal_we_color']  ?? '#f3f4f6' ) ?: '#f3f4f6' );
            update_option( 'sp_cal_vac_color', sanitize_hex_color( $_POST['sp_cal_vac_color'] ?? '#fef9c3' ) ?: '#fef9c3' );
            // Périodes manuelles
            $periodes = array();
            // wp_unslash : sans lui, « Vacances d'Hiver » prenait un « \ » de plus à chaque enregistrement.
            $labels = wp_unslash( (array) ( $_POST['vac_label'] ?? array() ) );
            $starts = wp_unslash( (array) ( $_POST['vac_start'] ?? array() ) );
            $ends   = wp_unslash( (array) ( $_POST['vac_end']   ?? array() ) );
            foreach ( $starts as $i => $start ) {
                $start = sanitize_text_field( $start );
                $end   = sanitize_text_field( $ends[$i] ?? '' );
                if ( $start && $end && $start <= $end ) {
                    $periodes[] = array(
                        'label' => sanitize_text_field( $labels[$i] ?? '' ),
                        'start' => $start,
                        'end'   => $end,
                    );
                }
            }
            update_option( 'sp_cal_vacances_zoneC', wp_json_encode( $periodes ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&vac_saved=1' ) ); exit;
        }

        if ( isset( $_POST['sp_cal_save_settings'] ) && check_admin_referer( 'sp_cal_settings' ) ) {
            update_option( 'sp_cal_public_link', esc_url_raw( $_POST['sp_cal_public_link'] ?? '' ) );
            // Informations club
            update_option( 'sp_cal_club_num',          sanitize_text_field( wp_unslash( $_POST['sp_cal_club_num']          ?? '' ) ) );
            update_option( 'sp_cal_club_affiliation',  sanitize_text_field( wp_unslash( $_POST['sp_cal_club_affiliation']  ?? '' ) ) );
            update_option( 'sp_cal_club_ligue',        sanitize_text_field( wp_unslash( $_POST['sp_cal_club_ligue']        ?? '' ) ) );
            update_option( 'sp_cal_club_labelise',     intval(              $_POST['sp_cal_club_labelise']                 ?? 0  ) );
            // NE PAS toucher sp_cal_fiche_membre_url ici — géré par son propre formulaire
            // Notifications
            update_option( 'sp_cal_notif_email',           sanitize_email( wp_unslash( $_POST['sp_cal_notif_email']           ?? get_option('admin_email' ) ) ) );
            update_option( 'sp_cal_notif_anniv',           isset($_POST['sp_cal_notif_anniv'])    ? '1' : '0' );
            update_option( 'sp_cal_notif_anniv_jours',     intval(          $_POST['sp_cal_notif_anniv_jours']     ?? 7 ) );
            update_option( 'sp_cal_notif_absences',        isset($_POST['sp_cal_notif_absences']) ? '1' : '0' );
            update_option( 'sp_cal_notif_absences_seuil',  intval(          $_POST['sp_cal_notif_absences_seuil']  ?? 3 ) );
            // Couleurs catégories
            $colors = array();
            if ( ! empty( $_POST['cat_color'] ) ) {
                // cat_name[md5(cat)] = nom réel de la catégorie (champ caché dans le formulaire)
                $cat_names = wp_unslash( (array) ( $_POST['cat_name'] ?? array() ) );
                foreach ( $_POST['cat_color'] as $key => $color ) {
                    $cat = sanitize_text_field( $cat_names[ $key ] ?? '' );
                    if ( ! $cat ) continue;
                    $colors[ $cat ] = array(
                        'color' => sanitize_hex_color( $color ) ?: '#3B82F6',
                        'icon'  => sanitize_text_field( wp_unslash( $_POST['cat_icon'][ $key ] ?? '' ) ),
                    );
                }
            }
            update_option( 'sp_cal_cat_colors', wp_json_encode( $colors ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&saved=1' ) ); exit;
        }

        /* ── Règlement intérieur : enregistrer (popup formulaire d'adhésion) ── */
        if ( isset( $_POST['sp_cal_save_reglement'] ) && check_admin_referer( 'sp_cal_reglement' ) ) {
            update_option( 'sp_cal_reglement_interieur', wp_kses_post( wp_unslash( $_POST['sp_cal_reglement_interieur'] ?? '' ) ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&reglement_saved=1' ) ); exit;
        }

        /* ── Veille réglementaire : pense-bête, pas d'appel automatique (cf. doleances.md) ── */
        if ( isset( $_POST['sp_veille_reglementaire_demandee'] ) && check_admin_referer( 'sp_cal_veille_reglementaire' ) ) {
            update_option( 'sp_cal_veille_reglementaire_demandee_le', current_time( 'Y-m-d' ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&veille_demandee=1' ) ); exit;
        }

        /* ── Récapitulatif mensuel : enregistrer ── */
        if ( isset( $_POST['sp_cal_save_recap'] ) && check_admin_referer( 'sp_cal_recap_settings' ) ) {
            update_option( 'sp_cal_recap_actif',   isset( $_POST['sp_cal_recap_actif'] ) ? '1' : '0' );
            update_option( 'sp_cal_recap_jour',    max( 1, min( 28, intval( $_POST['sp_cal_recap_jour']  ?? 1 ) ) ) );
            update_option( 'sp_cal_tarif_km',      floatval( str_replace( ',', '.', $_POST['sp_cal_tarif_km'] ?? '0' ) ) );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&recap_saved=1' ) ); exit;
        }

        /* ── Reset events ── */
        if ( isset( $_POST['sp_reset_events'] ) && check_admin_referer( 'sp_reset_events' ) ) {
            $wpdb->query( "TRUNCATE TABLE {$this->db->table_events()}" );
            $wpdb->query( "TRUNCATE TABLE {$this->db->table_presences_eleves()}" );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&events_reset=1' ) ); exit;
        }

        /* ── Nettoyage events parasites (doublons de matérialisation) ── */
        if ( isset( $_POST['sp_cleanup_mats'] ) && check_admin_referer( 'sp_cleanup_mats' ) ) {
            global $wpdb;
            $te = $this->db->table_events();
            // Supprimer les events type='cours' avec slot_id NOT NULL qui sont en doublon
            // (garder le plus ancien pour chaque slot+date, supprimer les autres)
            $wpdb->query(
                "DELETE e1 FROM $te e1
                 INNER JOIN $te e2
                 ON e1.slot_id = e2.slot_id AND e1.date = e2.date AND e1.type = 'cours' AND e2.type = 'cours'
                 WHERE e1.id > e2.id AND e1.slot_id IS NOT NULL"
            );
            // Supprimer aussi les events type='cours' avec slot_id NULL créés par erreur
            // (ceux qui ont le même titre/date/heure qu'un créneau récurrent)
            $tsl = $this->db->table_slots();
            $wpdb->query(
                "DELETE e FROM $te e
                 INNER JOIN $tsl s ON s.label = e.titre AND e.slot_id IS NULL AND e.type = 'cours'
                 WHERE NOT EXISTS (
                     SELECT 1 FROM {$this->db->table_presences_eleves()} pe WHERE pe.event_id = e.id
                 )"
            );
            $cleaned = $wpdb->rows_affected;
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&cleaned=' . $cleaned ) ); exit;
        }

        /* ── Migration Discipline / Pour qui des événements (ex-champ "categorie" libre) ── */
        if ( isset( $_POST['sp_migrer_cours_categories'] ) && check_admin_referer( 'sp_migrer_cours_categories' ) ) {
            $dry = isset( $_POST['sp_migrer_preview'] );
            if ( $dry ) {
                $preview = $this->db->preview_migration_cours_categories();
                set_transient( 'sp_migrer_cours_cat_preview_' . get_current_user_id(), $preview, 300 );
                wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&migration_cours_preview=1' ) ); exit;
            }
            $total = $this->db->appliquer_migration_cours_categories();
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&migration_cours_done=1&nb=' . $total ) ); exit;
        }

        /* ── Reset élèves ── */
        if ( isset( $_POST['sp_reset_eleves'] ) && check_admin_referer( 'sp_reset_eleves' ) ) {
            $wpdb->query( "TRUNCATE TABLE {$this->db->table_eleves()}" );
            $wpdb->query( "TRUNCATE TABLE {$this->db->table_presences_eleves()}" );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-settings&eleves_reset=1' ) ); exit;
        }

        /* ── Trainer : sauvegarder ── */
        if ( isset( $_POST['sp_cal_save_trainer'] ) && check_admin_referer( 'sp_cal_save_trainer' ) ) {
            $data = array(
                'nom'             => sanitize_text_field( wp_unslash( $_POST['trainer_nom']      ?? '' ) ),
                'nom_public'      => sanitize_text_field( wp_unslash( $_POST['trainer_pubnom']   ?? '' ) ),
                'roles'           => sanitize_text_field( wp_unslash( $_POST['trainer_roles']    ?? '' ) ),
                'telephone'       => sanitize_text_field( wp_unslash( $_POST['trainer_tel']      ?? '' ) ),
                'email'           => sanitize_email( wp_unslash( $_POST['trainer_email']    ?? '' ) ),
                'ordre'           => intval(                          $_POST['trainer_ordre']    ?? 0    ),
                'actif'           => intval(                          $_POST['trainer_actif']    ?? 1    ),
                'km_aller_retour' => floatval( str_replace( ',', '.', $_POST['trainer_km'] ?? '0' ) ),
                'photo_url'       => esc_url_raw( wp_unslash( $_POST['trainer_photo_url'] ?? '' ) ),
                'fonction'        => sanitize_text_field( wp_unslash( $_POST['trainer_fonction']        ?? '' ) ),
                'fonction_bureau' => sanitize_text_field( wp_unslash( $_POST['trainer_fonction_bureau'] ?? '' ) ),
            );
            $id = intval( $_POST['trainer_id'] ?? 0 );
            if ( $id ) $wpdb->update( $tt, $data, array( 'id' => $id ) );
            else        $wpdb->insert( $tt, $data );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-trainers&saved=1' ) ); exit;
        }

        /* ── Trainer : supprimer ── */
        if ( isset( $_GET['sp_delete_trainer'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'], 'sp_delete_trainer_' . intval( $_GET['sp_delete_trainer'] ) ) ) {
                $wpdb->delete( $tt, array( 'id' => intval( $_GET['sp_delete_trainer'] ) ) );
                wp_redirect( admin_url( 'admin.php?page=sp-cal-trainers&deleted=1' ) ); exit;
            }
        }

        /* ── Slot : sauvegarder ── */
        if ( isset( $_POST['sp_cal_save_slot'] ) && check_admin_referer( 'sp_cal_save_slot' ) ) {
            $rec = sanitize_text_field( wp_unslash( $_POST['slot_recurrence'] ?? 'weekly' ) );
            $dd  = sanitize_text_field( wp_unslash( $_POST['slot_date_debut'] ?? '' ) );
            $df  = sanitize_text_field( wp_unslash( $_POST['slot_date_fin']   ?? '' ) );
            $data = array(
                'jour'       => intval( $_POST['slot_jour']      ?? 1 ),
                'label'      => sanitize_text_field( wp_unslash( $_POST['slot_label']    ?? '' ) ),
                'heure_debut'=> sanitize_text_field( wp_unslash( $_POST['slot_debut_h']  ?? '08' ) ) . 'h' . sanitize_text_field( wp_unslash( $_POST['slot_debut_m'] ?? '00' ) ),
                'heure_fin'  => sanitize_text_field( wp_unslash( $_POST['slot_fin_h']    ?? '09' ) ) . 'h' . sanitize_text_field( wp_unslash( $_POST['slot_fin_m']   ?? '00' ) ),
                'ordre'      => intval( $_POST['slot_ordre'] ?? 0 ),
                'recurrence' => in_array( $rec, array('weekly','biweekly','3weekly','monthly_1','monthly_2','monthly_3','monthly_4','monthly_5','monthly_6','monthly_7','monthly_8','monthly_9') ) ? $rec : 'weekly',
                'date_debut' => $dd ?: null,
                'date_fin'   => $df ?: null,
            );
            // Discipline × Pour qui (cases à cocher, mêmes listes que les événements) — remplace
            // l'ancien texte libre « Catégorie » (05/10/2026). "categorie" = Discipline, comme pour
            // les événements (couleur du calendrier, planning, pointage).
            $axe = static function ( $cle, array $permis ) {
                $v = array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST[ $cle ] ?? array() ) ) );
                return array_values( array_intersect( $permis, $v ) );
            };
            $disc = $axe( 'slot_discipline', SpCalPro_DB::COURS_DISCIPLINES );
            $ages = $axe( 'slot_ages', SpCalPro_DB::COURS_AGES );
            $data['cours_discipline']     = implode( ',', $disc );
            $data['cours_age_categories'] = implode( ',', $ages );
            $data['categorie']            = implode( ',', $disc );
            $id = intval( $_POST['slot_id'] ?? 0 );
            if ( $id ) $wpdb->update( $tsl, $data, array( 'id' => $id ) );
            else        $wpdb->insert( $tsl, $data );
            wp_redirect( admin_url( 'admin.php?page=sp-cal-slots&saved=1' ) ); exit;
        }

        /* ── Slots : reclasser l'ancien texte libre « Catégorie » en Discipline × Pour qui ── */
        if ( isset( $_POST['sp_migrer_creneaux_categories'] ) && check_admin_referer( 'sp_migrer_creneaux_categories' ) ) {
            $nb = $this->db->appliquer_migration_creneaux_categories();
            wp_redirect( admin_url( 'admin.php?page=sp-cal-slots&reclasses=' . intval( $nb ) ) ); exit;
        }

        /* ── Slot : supprimer ── */
        if ( isset( $_GET['sp_delete_slot'] ) && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'], 'sp_delete_slot_' . intval( $_GET['sp_delete_slot'] ) ) ) {
                $wpdb->delete( $tsl, array( 'id' => intval( $_GET['sp_delete_slot'] ) ) );
                wp_redirect( admin_url( 'admin.php?page=sp-cal-slots&deleted=1' ) ); exit;
            }
        }

        // Module membres — dispatch déplacé sur son propre hook admin_init avec sa propre
        // capacité (cf. SP_Cal_Members::__construct(), doleances.md 09/09/2026) : ce
        // dispatcher-ci reste manage_options uniquement, mais la Secrétaire doit pouvoir
        // sauvegarder une fiche élève sans avoir cette capacité globale.
    }
}

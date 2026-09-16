<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_Notifications' ) ) :

class SpCalPro_Notifications {

    private $db;

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;

        // Enregistrer le cron event personnalisé
        add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
        add_action( 'sp_cal_daily_notif',          array( $this, 'run_all' ) );
        add_action( 'sp_cal_trainer_reminder_day', array( $this, 'send_trainer_reminders' ) ); // matin 8h — jour J uniquement
        add_action( 'sp_cal_send_dispo_notif',     array( $this, 'send_dispo_notif_debounced' ) ); // différé 2min
        add_action( 'sp_cal_recap_mensuel',        array( $this, 'send_recap_mensuel_auto' ) );    // 1x/jour — vérifie le bon jour

        // Planifier le cron général si pas déjà fait
        if ( ! wp_next_scheduled( 'sp_cal_daily_notif' ) ) {
            wp_schedule_event( strtotime('today 08:00:00'), 'daily', 'sp_cal_daily_notif' );
        }
        // Rappel entraîneurs : matin 8h jour J uniquement
        if ( ! wp_next_scheduled( 'sp_cal_trainer_reminder_day' ) ) {
            wp_schedule_event( strtotime('today 08:00:00'), 'daily', 'sp_cal_trainer_reminder_day' );
        }
        // Supprimer l'ancien cron veille s'il est encore planifié
        $ts_eve = wp_next_scheduled( 'sp_cal_trainer_reminder_eve' );
        if ( $ts_eve ) {
            wp_unschedule_event( $ts_eve, 'sp_cal_trainer_reminder_eve' );
        }
        // Récapitulatif mensuel : tourne chaque jour à 9h, se déclenche seulement le bon jour du mois
        if ( ! wp_next_scheduled( 'sp_cal_recap_mensuel' ) ) {
            wp_schedule_event( strtotime('today 09:00:00'), 'daily', 'sp_cal_recap_mensuel' );
        }

        // Handler AJAX "Envoyer maintenant"
        add_action( 'wp_ajax_sp_cal_send_notif_now',            array( $this, 'send_now_ajax' ) );
        add_action( 'wp_ajax_sp_cal_send_trainer_reminder_now', array( $this, 'send_trainer_reminder_now_ajax' ) );
        add_action( 'wp_ajax_sp_cal_send_recap_now',            array( $this, 'send_recap_now_ajax' ) );

        // Point d'intégration documenté pour le module IK de sp-compta (trésorerie) — évite de
        // dupliquer l'algorithme d'expansion des créneaux récurrents + disponibilités dans un
        // autre plugin. sp-compta lit directement les tables trainers/km_exceptionnels/l'option
        // tarif_km (simples valeurs), mais appelle ce filtre pour le nombre d'AR/mois, seul calcul
        // non trivial — garantit que le montant affiché en trésorerie correspond toujours à celui
        // de l'email récapitulatif mensuel (cf. échange du 16/09/2026).
        add_filter( 'sp_cal_interventions_par_trainer', array( $this, 'filter_interventions_par_trainer' ), 10, 3 );
    }

    /**
     * @param array $default  Valeur par défaut (tableau vide) si le filtre n'est pas surchargé.
     * @param int   $year
     * @param int   $month
     * @return array  [ trainer_id => nombre d'interventions ] pour le mois donné.
     */
    public function filter_interventions_par_trainer( $default, $year, $month ) {
        return $this->db->get_interventions_par_trainer( intval( $year ), intval( $month ) );
    }

    public function add_schedules( $schedules ) {
        if ( ! isset( $schedules['daily'] ) ) {
            $schedules['daily'] = array( 'interval' => DAY_IN_SECONDS, 'display' => 'Daily' );
        }
        return $schedules;
    }

    /* ══════════════════════════════════════════════════════════
       POINT D'ENTRÉE CRON
    ══════════════════════════════════════════════════════════ */

    public function run_all() {
        $sent = array();
        if ( get_option('sp_cal_notif_anniv', '0') === '1' )   $sent['anniv']   = $this->send_birthdays();
        if ( get_option('sp_cal_notif_absences', '0') === '1' ) $sent['absences'] = $this->send_absences();
        update_option( 'sp_cal_notif_last_run', array(
            'date' => date('Y-m-d H:i:s'),
            'sent' => $sent,
        ) );
        return $sent;
    }

    /* ══════════════════════════════════════════════════════════
       ANNIVERSAIRES
    ══════════════════════════════════════════════════════════ */

    /**
     * Envoie un récap des anniversaires des N prochains jours.
     * N = sp_cal_notif_anniv_jours (défaut 7)
     */
    public function send_birthdays() {
        $jours = intval( get_option( 'sp_cal_notif_anniv_jours', 7 ) );

        // ── Construire la liste des destinataires : entraîneurs actifs + bureau actif ──
        $destinataires = array();
        // Entraîneurs (rôle 'entraineur')
        foreach ( $this->db->get_trainers_entraineurs( true ) as $t ) {
            if ( ! empty($t->email) ) $destinataires[ strtolower(trim($t->email)) ] = true;
        }
        // Membres du bureau (rôle 'bureau')
        foreach ( $this->db->get_bureau_members() as $t ) {
            if ( ! empty($t->email) ) $destinataires[ strtolower(trim($t->email)) ] = true;
        }
        // Fallback sur l'adresse générique si aucun destinataire trouvé
        if ( empty($destinataires) ) {
            $fallback = get_option( 'sp_cal_notif_email', get_option('admin_email') );
            if ( $fallback ) $destinataires[ strtolower(trim($fallback)) ] = true;
        }
        if ( empty($destinataires) ) return 0;

        $eleves = $this->get_upcoming_birthdays( $jours );
        if ( empty($eleves) ) return 0;

        $nom_club = get_option('blogname', 'Club');
        $subject  = '[' . $nom_club . '] 🎂 ' . count($eleves) . ' anniversaire(s) dans les ' . $jours . ' prochains jours';

        $body  = "Bonjour,\n\n";
        $body .= "Voici les anniversaires à venir dans les $jours prochains jours :\n\n";
        foreach ( $eleves as $el ) {
            $age = $el->annee_naissance ? ( date('Y') - intval($el->annee_naissance) ) : null;
            $body .= '🎂 ' . $el->prenom . ' ' . mb_strtoupper($el->nom)
                . ' — ' . $el->date_anniversaire_fmt
                . ( $age ? ' (' . $age . ' ans)' : '' )
                . ( $el->categorie_age ? ' — ' . $el->categorie_age : '' )
                . "\n";
        }
        $body .= "\n-- \n" . $nom_club;

        $emails = array_keys($destinataires);
        wp_mail( $emails, $subject, $body );
        return count($eleves);
    }

    private function get_upcoming_birthdays( $jours ) {
        global $wpdb;
        $tel = $this->db->table_eleves();
        $out = array();

        for ( $i = 0; $i <= $jours; $i++ ) {
            $day_ts  = strtotime( "+$i days" );
            $day_str = date('d/m', $day_ts); // format stocké : "JJ/MM"
            $rows    = $wpdb->get_results( $wpdb->prepare(
                "SELECT nom, prenom, date_naissance, annee_naissance, categorie_age
                 FROM $tel WHERE date_naissance = %s AND actif = 1",
                $day_str
            ) );
            foreach ( $rows as $r ) {
                $r->date_anniversaire_fmt = date('d/m', $day_ts);
                $r->jours_restants = $i;
                $out[] = $r;
            }
        }
        // Trier par date
        usort($out, function($a,$b){ return $a->jours_restants - $b->jours_restants; });
        return $out;
    }

    /* ══════════════════════════════════════════════════════════
       ABSENCES RÉPÉTÉES
    ══════════════════════════════════════════════════════════ */

    /**
     * Envoie un récap des élèves absents N fois de suite.
     * N = sp_cal_notif_absences_seuil (défaut 3)
     */
    public function send_absences() {
        $seuil  = intval( get_option( 'sp_cal_notif_absences_seuil', 3 ) );
        $dest   = get_option( 'sp_cal_notif_email', get_option('admin_email') );
        if ( ! $dest ) return 0;

        $eleves = $this->get_eleves_absences_repetees( $seuil );
        if ( empty($eleves) ) return 0;

        $nom_club = get_option('blogname', 'Club');
        $subject  = '[' . $nom_club . '] ⚠️ ' . count($eleves) . ' élève(s) absent(s) ' . $seuil . ' fois de suite';

        $body  = "Bonjour,\n\n";
        $body .= "Les élèves suivants ont été absents $seuil fois consécutives :\n\n";
        foreach ( $eleves as $el ) {
            $body .= '⚠️ ' . $el->prenom . ' ' . mb_strtoupper($el->nom)
                . ' — ' . $el->nb_absences . ' absences consécutives'
                . ( $el->categorie_age ? ' (' . $el->categorie_age . ')' : '' )
                . ( $el->email_parent ? ' — parent : ' . $el->email_parent : '' )
                . "\n";
        }
        $body .= "\n-- \n" . $nom_club;

        wp_mail( $dest, $subject, $body );
        return count($eleves);
    }

    private function get_eleves_absences_repetees( $seuil ) {
        global $wpdb;
        $tel = $this->db->table_eleves();
        $tpe = $this->db->table_presences_eleves();
        $te  = $this->db->table_events();

        $eleves = $wpdb->get_results(
            "SELECT id, nom, prenom, categorie_age, email, email_parent
             FROM $tel WHERE actif = 1"
        );

        $alertes = array();
        foreach ( $eleves as $el ) {
            // Récupérer les N dernières présences de cet élève (hors examens)
            $presences = $wpdb->get_results( $wpdb->prepare(
                "SELECT pe.present
                 FROM $tpe pe
                 INNER JOIN $te e ON e.id = pe.event_id
                 WHERE pe.eleve_id = %d
                   AND e.type NOT IN ('examen','anniversaire')
                 ORDER BY e.date DESC, e.heure_debut DESC
                 LIMIT %d",
                intval($el->id), intval($seuil)
            ) );

            if ( count($presences) < $seuil ) continue; // pas assez de données

            // Vérifier si toutes sont des absences
            $all_absent = true;
            foreach ( $presences as $p ) {
                if ( intval($p->present) !== 0 ) { $all_absent = false; break; }
            }
            if ( $all_absent ) {
                $el->nb_absences = $seuil;
                $alertes[] = $el;
            }
        }
        return $alertes;
    }

    /* ══════════════════════════════════════════════════════════
       AJAX "ENVOYER MAINTENANT"
    ══════════════════════════════════════════════════════════ */

    public function send_now_ajax() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        if ( ! current_user_can('manage_options') ) wp_send_json_error('Accès refusé', 403);

        $type = sanitize_text_field( $_POST['type'] ?? 'all' );
        $sent = array();

        if ( $type === 'anniv' || $type === 'all' )
            $sent['anniv']   = $this->send_birthdays();
        if ( $type === 'absences' || $type === 'all' )
            $sent['absences'] = $this->send_absences();

        update_option( 'sp_cal_notif_last_run', array(
            'date' => date('Y-m-d H:i:s'),
            'sent' => $sent,
        ) );

        $msg = array();
        if ( isset($sent['anniv']) )    $msg[] = $sent['anniv']    . ' email(s) anniversaires envoyé(s)';
        if ( isset($sent['absences']) ) $msg[] = $sent['absences'] . ' email(s) absences envoyé(s)';

        wp_send_json_success( implode(' · ', $msg) ?: 'Aucun email à envoyer.' );
    }

    /* ══════════════════════════════════════════════════════════
       RAPPEL ENTRAÎNEURS DU JOUR
    ══════════════════════════════════════════════════════════ */

    /**
     * Envoie un rappel personnalisé à chaque entraîneur AFFECTÉ ce jour
     * (présent sur au moins un slot dans presences_trainers pour cette date).
     * Si aucune affectation n'existe, repli sur les disponibles (dispo=1).
     */
    public function send_trainer_reminders() {
        $hour       = intval( date('G') );
        $is_evening = $hour >= 18;
        $target_date = $is_evening
            ? date('Y-m-d', strtotime('tomorrow'))
            : date('Y-m-d');
        return $this->do_send_trainer_reminders( $target_date, $is_evening );
    }

    private function do_send_trainer_reminders( $date_str, $is_veille = false ) {
        global $wpdb;

        $trainers = $this->db->get_trainers( true );
        if ( empty($trainers) ) return 0;

        $dispos   = $this->db->get_dispos_for_date( $date_str );
        $slots    = $this->db->get_slot_occurrences( $date_str, $date_str );
        $nom_club = get_option( 'blogname', 'Club' );
        $date_fmt = date_create( $date_str )->format('d/m/Y');
        $dow_labels = array(1=>'lundi',2=>'mardi',3=>'mercredi',4=>'jeudi',5=>'vendredi',6=>'samedi',7=>'dimanche');
        $dow        = intval( date_create($date_str)->format('N') );
        $jour_label = $dow_labels[$dow] ?? $date_str;

        // Filtrer les slots non annulés
        $slots_actifs = array_filter( $slots, function($sl){ return ! $sl['annul_id']; } );

        // Aucun cours ce jour → inutile d'envoyer des rappels
        if ( empty($slots_actifs) ) return 0;

        // Récupérer les affectations entraîneurs sur les créneaux de ce jour
        $tpt = $this->db->table_presences_trainers();
        $assigned_ids    = array();
        $slots_by_trainer = array();

        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tpt'" ) ) {
            $aff_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT trainer_id, slot_id FROM $tpt WHERE date = %s", $date_str
            ) );
            foreach ( $aff_rows as $row ) {
                $tid = intval($row->trainer_id);
                $sid = intval($row->slot_id);
                if ( ! in_array($tid, $assigned_ids) ) $assigned_ids[] = $tid;
                foreach ( $slots_actifs as $sl ) {
                    if ( intval($sl['slot_id']) === $sid ) {
                        $slots_by_trainer[$tid][] = $sl;
                    }
                }
            }
        }

        // Si aucune affectation en DB → repli sur les entraîneurs disponibles (dispo=1)
        $use_fallback = empty($assigned_ids);

        $sent = 0;
        foreach ( $trainers as $trainer ) {
            $dest = $trainer->email;
            if ( ! $dest || ! is_email($dest) ) continue;

            $tid   = intval($trainer->id);
            $dispo = isset($dispos[$tid]) ? $dispos[$tid]['disponible'] : null;

            // Mode affectation : notifier uniquement les entraîneurs assignés ce jour
            if ( ! $use_fallback && ! in_array($tid, $assigned_ids) ) continue;

            // Mode fallback : notifier les disponibles uniquement (pas les indisponibles explicites)
            if ( $use_fallback && $dispo !== 1 ) continue;

            $prenom_nom   = trim($trainer->nom);
            $label_dispo  = $dispo === 1 ? '✅ Disponibilité confirmée.' : '⬜ Disponibilité non encore renseignée.';

            // Cours assignés à cet entraîneur (ou tous si fallback)
            $trainer_slots = $use_fallback ? array_values($slots_actifs) : ( $slots_by_trainer[$tid] ?? array() );
            if ( empty($trainer_slots) && ! $use_fallback ) continue; // affecté mais aucun slot trouvé

            $cours_lines = array();
            foreach ( $trainer_slots as $sl ) {
                $cours_lines[] = '  • ' . $sl['heure_debut'] . ' – ' . $sl['heure_fin'] . ' — ' . $sl['titre']
                    . ( $sl['categorie'] ? ' (' . $sl['categorie'] . ')' : '' );
            }

            $prefix  = $is_veille ? 'Rappel pour demain' : 'Rappel du jour';
            $subject = '[' . $nom_club . '] 🔔 ' . $prefix . ' — ' . $jour_label . ' ' . $date_fmt;

            $body  = "Bonjour $prenom_nom,\n\n";
            $body .= "Rappel de votre intervention au $nom_club :\n";
            $body .= "📅 " . ucfirst($jour_label) . " " . $date_fmt . "\n\n";
            $body .= "Cours prévus :\n" . implode("\n", $cours_lines) . "\n\n";
            $body .= $label_dispo . "\n\n";
            $body .= "-- \n" . $nom_club;

            if ( wp_mail($dest, $subject, $body) ) $sent++;
        }
        return $sent;
    }

    public function send_trainer_reminder_now_ajax() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        if ( ! current_user_can('manage_options') ) wp_send_json_error('Accès refusé', 403);
        $date = sanitize_text_field( $_POST['date'] ?? date('Y-m-d') );
        $sent = $this->do_send_trainer_reminders( $date, false );
        wp_send_json_success( $sent . ' rappel(s) envoyé(s) aux entraîneurs.' );
    }

    /* ══════════════════════════════════════════════════════════
       NOTIFICATION BUREAU — MODIFICATION CALENDRIER
    ══════════════════════════════════════════════════════════ */

    /**
     * Envoie un email aux membres du bureau lors d'une modification calendrier.
     * $action : 'created' | 'updated' | 'deleted'
     * $event  : array avec date, titre, type, categorie
     * $author : nom WordPress de l'admin qui a effectué l'action
     */
    public function notify_bureau_on_calendar_change( $action, $event, $author = '' ) {
        $bureau = $this->db->get_bureau_members();
        if ( empty($bureau) ) return 0;

        $nom_club = get_option( 'blogname', 'Club' );
        $labels   = array(
            'created' => 'créé',
            'updated' => 'modifié',
            'deleted' => 'supprimé',
        );
        $label   = $labels[$action] ?? $action;
        $date_fmt = ! empty($event['date']) ? date_create($event['date'])->format('d/m/Y') : '—';
        $type_labels = array(
            'cours'       => 'Cours',
            'evenement'   => 'Événement',
            'examen'      => 'Examen',
            'competition' => 'Compétition',
            'annulation'  => 'Annulation',
        );
        $type_label = $type_labels[$event['type'] ?? ''] ?? ucfirst($event['type'] ?? '');

        $subject = '[' . $nom_club . '] 📅 Calendrier ' . $label . ' — ' . ($event['titre'] ?? '(sans titre)') . ' · ' . $date_fmt;

        $body  = "Bonjour,\n\n";
        $body .= "Une modification a été apportée au calendrier du club :\n\n";
        $body .= "  Action    : " . mb_strtoupper($label) . "\n";
        $body .= "  Événement : " . ($event['titre'] ?? '—') . "\n";
        $body .= "  Date      : " . $date_fmt . "\n";
        $body .= "  Type      : " . $type_label . "\n";
        if ( ! empty($event['categorie']) ) $body .= "  Catégorie : " . $event['categorie'] . "\n";
        if ( $author )                      $body .= "  Par       : " . $author . "\n";
        $body .= "\n";

        $admin_url = admin_url('admin.php?page=sp-cal-pro');
        $body .= "Voir le calendrier : " . $admin_url . "\n\n";
        $body .= "-- \n" . $nom_club . " (notification automatique)";

        $sent = 0;
        foreach ( $bureau as $m ) {
            if ( ! $m->email || ! is_email($m->email) ) continue;
            if ( wp_mail($m->email, $subject, $body) ) $sent++;
        }
        return $sent;
    }

    /* ══════════════════════════════════════════════════════════
       NOTIFICATION BUREAU — CHANGEMENT DISPO ENTRAÎNEUR J-3
    ══════════════════════════════════════════════════════════ */

    /**
     * Notifie les membres du bureau quand la disponibilité d'un entraîneur
     * change dans les 3 jours à venir (J, J-1, J-2, J-3).
     */
    /* ══════════════════════════════════════════════════════════
       ENVOI DIFFÉRÉ (debouncé) — appelé par WP-Cron 2 min après
    ══════════════════════════════════════════════════════════ */

    /**
     * Lit le transient de date, construit un seul mail récapitulatif
     * avec tous les changements de disponibilité du jour, puis l'envoie.
     */
    public function send_dispo_notif_debounced( $transient_key ) {
        $data = get_transient( $transient_key );
        if ( ! $data ) return;
        delete_transient( $transient_key );

        $this->notify_bureau_dispo_changes_grouped(
            $data['date'],
            $data['changements'] ?? array()
        );
    }

    /* ══════════════════════════════════════════════════════════
       NOTIFICATION BUREAU — GROUPE DE CHANGEMENTS DISPOS J-3
    ══════════════════════════════════════════════════════════ */

    /**
     * Envoie UN seul mail récapitulatif au bureau pour tous les changements
     * de disponibilité sur une même date.
     *
     * @param string $date_str     Date concernée (YYYY-MM-DD)
     * @param array  $changements  [ trainer_id => { trainer_nom, avant, apres, note } ]
     */
    public function notify_bureau_dispo_changes_grouped( $date_str, $changements ) {
        if ( empty($changements) ) return 0;

        $bureau = $this->db->get_bureau_members();
        if ( empty($bureau) ) return 0;

        $nom_club    = get_option( 'blogname', 'Club' );
        $date_fmt    = date_create( $date_str )->format('d/m/Y');
        $dow_labels  = array(1=>'lundi',2=>'mardi',3=>'mercredi',4=>'jeudi',5=>'vendredi',6=>'samedi',7=>'dimanche');
        $dow         = intval( date_create($date_str)->format('N') );
        $jour_label  = ucfirst( $dow_labels[$dow] ?? $date_str );

        $jours_restants = intval( ceil( ( strtotime($date_str) - time() ) / 86400 ) );
        if ( $jours_restants === 0 )      $urgence = "⚠️ AUJOURD'HUI";
        elseif ( $jours_restants === 1 )  $urgence = '⚠️ DEMAIN';
        else                              $urgence = "dans $jours_restants jours";

        // ── Construire les lignes de changements ─────────────────
        $lignes_indispo  = array();
        $lignes_dispo    = array();
        $lignes_autres   = array();

        foreach ( $changements as $chg ) {
            $nom   = $chg['trainer_nom'] ?? 'Inconnu';
            $avant = $chg['avant'];
            $apres = $chg['apres'];
            $note  = $chg['note'] ?? '';

            $suffix = $note ? " (note : $note)" : '';

            if ( $apres === 0 || $apres === null ) {
                // Devient indisponible ou non renseigné
                $remplacant_nom = isset( $chg['remplacant_nom'] ) && $chg['remplacant_nom'] !== ''
                    ? ' → remplaçant : ' . sanitize_text_field( $chg['remplacant_nom'] )
                    : '';
                $lignes_indispo[] = "  🚨 $nom : indisponible$remplacant_nom$suffix";
            } elseif ( $apres === 1 ) {
                // Devient disponible
                if ( $avant === 0 || $avant === null ) {
                    $lignes_dispo[] = "  ✅ $nom : disponible — remplacement confirmé$suffix";
                } else {
                    $lignes_dispo[] = "  ✅ $nom : disponible$suffix";
                }
            }
        }

        // ── Résumé en langage naturel ─────────────────────────────
        $nb_indispo = count($lignes_indispo);
        $nb_dispo   = count($lignes_dispo);

        if ( $nb_indispo > 0 && $nb_dispo > 0 ) {
            $intro = "Modification des disponibilités $urgence — $jour_label $date_fmt :";
        } elseif ( $nb_indispo > 0 ) {
            $intro = "$nb_indispo entraîneur(s) indisponible(s) $urgence — $jour_label $date_fmt :";
        } else {
            $intro = "Mise à jour des disponibilités $urgence — $jour_label $date_fmt :";
        }

        $subject = '[' . $nom_club . '] ⚠️ Disponibilités entraîneurs — ' . $jour_label . ' ' . $date_fmt;

        $body  = "Bonjour,\n\n";
        $body .= $intro . "\n\n";

        // Indisponibles d'abord
        if ( ! empty($lignes_indispo) ) {
            $body .= implode("\n", $lignes_indispo) . "\n";
        }
        // Disponibles ensuite (remplaçants)
        if ( ! empty($lignes_dispo) ) {
            $body .= implode("\n", $lignes_dispo) . "\n";
        }
        if ( ! empty($lignes_autres) ) {
            $body .= implode("\n", $lignes_autres) . "\n";
        }
        $body .= "\n";

        // Cours prévus ce jour
        $slots        = $this->db->get_slot_occurrences( $date_str, $date_str );
        $cours_actifs = array_filter( $slots, function($sl){ return ! $sl['annul_id']; } );
        if ( ! empty($cours_actifs) ) {
            $body .= "Cours prévus ce jour :\n";
            foreach ( $cours_actifs as $sl ) {
                $body .= '  • ' . $sl['heure_debut'] . ' – ' . $sl['heure_fin']
                    . ' — ' . $sl['titre']
                    . ( $sl['categorie'] ? ' (' . $sl['categorie'] . ')' : '' ) . "\n";
            }
            $body .= "\n";
        }

        $body .= "Consulter le calendrier : " . admin_url('admin.php?page=sp-cal-pro') . "\n\n";
        $body .= "-- \n" . $nom_club . " (notification automatique)";

        $sent = 0;
        foreach ( $bureau as $m ) {
            if ( ! $m->email || ! is_email($m->email) ) continue;
            if ( wp_mail($m->email, $subject, $body) ) $sent++;
        }
        return $sent;
    }

    /**
     * Compatibilité : garde l'ancienne signature pour les appels directs éventuels.
     * Redirige vers la version groupée avec un seul changement.
     */
    public function notify_bureau_dispo_change( $trainer, $date_str, $avant, $apres, $note = '' ) {
        $nom = $trainer ? trim($trainer->nom) : '';
        return $this->notify_bureau_dispo_changes_grouped( $date_str, array(
            array( 'trainer_nom' => $nom, 'avant' => $avant, 'apres' => $apres, 'note' => $note ),
        ) );
    }

    /* ══════════════════════════════════════════════════════════
       RÉCAPITULATIF MENSUEL ENTRAÎNEURS
    ══════════════════════════════════════════════════════════ */

    /**
     * Appelé chaque jour à 9h par le cron sp_cal_recap_mensuel.
     * Ne fait rien sauf si aujourd'hui = jour d'envoi configuré ET pas déjà envoyé ce mois-ci.
     */
    public function send_recap_mensuel_auto() {
        if ( get_option('sp_cal_recap_actif', '0') !== '1' ) return;

        $jour_envoi = intval( get_option('sp_cal_recap_jour', 1) );
        $today      = intval( date('j') );          // jour du mois courant (1-31)
        $today_ym   = date('Y-m');                  // "2026-03" — clé anti-doublon

        if ( $today !== $jour_envoi ) return;

        // Anti-doublon : ne pas renvoyer si déjà envoyé ce mois-ci
        $last = get_option('sp_cal_recap_last_run', array());
        if ( isset($last['mois_envoi']) && $last['mois_envoi'] === $today_ym ) return;

        // Le récap porte sur le mois PRÉCÉDENT
        $ts       = strtotime('first day of last month');
        $year     = intval( date('Y', $ts) );
        $month    = intval( date('m', $ts) );

        $sent = $this->do_send_recap_mensuel( $year, $month );

        update_option('sp_cal_recap_last_run', array(
            'date'       => date('d/m/Y H:i'),
            'mois'       => date('F Y', $ts),
            'mois_envoi' => $today_ym,
            'sent'       => $sent,
        ));
    }

    /**
     * AJAX — "Tester : envoyer maintenant" depuis la page paramètres.
     * Accepte year/month optionnels, sinon repli sur le mois précédent.
     */
    public function send_recap_now_ajax() {
        check_ajax_referer('sp_cal_admin_nonce', 'nonce');
        if ( ! current_user_can('manage_options') ) wp_send_json_error('Accès refusé', 403);

        $year  = intval( $_POST['recap_year']  ?? 0 );
        $month = intval( $_POST['recap_month'] ?? 0 );

        // Valider — si invalides, repli sur le mois précédent
        if ( $year < 2000 || $year > 2100 || $month < 1 || $month > 12 ) {
            $ts    = strtotime('first day of last month');
            $year  = intval( date('Y', $ts) );
            $month = intval( date('m', $ts) );
        }

        $mois_labels = array(
            1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',
            7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre',
        );
        $mois_label = $mois_labels[$month] . ' ' . $year;

        $sent = $this->do_send_recap_mensuel( $year, $month );

        if ( $sent === 0 ) {
            wp_send_json_error('⚠️ Aucun membre bureau avec email configuré, ou aucune donnée pour ' . $mois_label . '.');
        }

        // Détecter les entraîneurs actifs sans km configuré pour avertir dans l'admin
        $trainers  = $this->db->get_trainers( true );
        $sans_km   = array();
        foreach ( $trainers as $t ) {
            if ( strpos($t->roles, 'entraineur') === false ) continue;
            if ( floatval($t->km_aller_retour ?? 0) == 0.0 ) $sans_km[] = trim($t->nom);
        }
        $msg = '✅ ' . $sent . ' email(s) envoyé(s) — récap de ' . $mois_label . '.';
        if ( ! empty($sans_km) ) {
            $msg .= ' ⚠️ Km manquants : ' . implode(', ', $sans_km) . ' (à configurer sur leur fiche).';
        }
        wp_send_json_success( $msg );
    }

    /**
     * Construit et envoie le récapitulatif mensuel aux membres du bureau.
     *
     * @param  int $year   Année du mois à récapituler
     * @param  int $month  Mois à récapituler (1-12)
     * @return int         Nombre d'emails envoyés
     */
    private function do_send_recap_mensuel( $year, $month ) {
        $bureau = $this->db->get_bureau_members();
        if ( empty($bureau) ) return 0;

        $trainers = $this->db->get_trainers( true );
        if ( empty($trainers) ) return 0;

        $tarif_km    = floatval( get_option('sp_cal_tarif_km', 0) );
        $nom_club    = get_option('blogname', 'Club');
        $mois_labels = array(
            1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',
            7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre',
        );
        $mois_label = $mois_labels[$month] . ' ' . $year;

        // ── Compter les interventions par entraîneur ─────────────────────────
        $interventions = $this->db->get_interventions_par_trainer( $year, $month );

        // ── Km exceptionnels du mois par entraîneur ────────────────────────────
        $tkm_exc  = $this->db->table_km_exceptionnels();
        $start_km = sprintf('%04d-%02d-01', $year, $month);
        $end_km   = date('Y-m-t', strtotime($start_km));
        $km_exc_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT trainer_id, SUM(km) AS total_km, GROUP_CONCAT(CONCAT(description,' (',DATE_FORMAT(date,'%d/%m'),'): ',km,' km') ORDER BY date SEPARATOR ' | ') AS detail
             FROM $tkm_exc WHERE date BETWEEN %s AND %s GROUP BY trainer_id",
            $start_km, $end_km
        ) );
        $km_excep_by_trainer = array();
        foreach ( $km_exc_rows as $r ) {
            $km_excep_by_trainer[ intval($r->trainer_id) ] = array(
                'total'  => floatval($r->total_km),
                'detail' => $r->detail,
            );
        }

        // ── Construire les lignes (entraîneurs actifs ayant eu au moins 1 cours) ──
        $lignes        = array();
        $total_global  = 0.0;
        $nb_actifs     = 0;
        $sans_km       = array(); // entraîneurs avec cours mais km = 0

        foreach ( $trainers as $t ) {
            if ( strpos($t->roles, 'entraineur') === false ) continue;

            $tid     = intval($t->id);
            $nb      = intval( $interventions[$tid] ?? 0 );
            $has_km_excep = isset($km_excep_by_trainer[$tid]);
            if ( $nb === 0 && ! $has_km_excep ) continue; // exclure sans activité ET sans km excep

            $km          = isset($t->km_aller_retour) ? floatval($t->km_aller_retour) : 0.0;
            $km_excep    = $has_km_excep ? $km_excep_by_trainer[$tid]['total'] : 0.0;
            $excep_detail= $has_km_excep ? $km_excep_by_trainer[$tid]['detail'] : '';
            $montant_hab = ( $tarif_km > 0 && $km > 0 && $nb > 0 ) ? round($tarif_km * $km * $nb, 2) : 0.0;
            $montant_exc = ( $tarif_km > 0 && $km_excep > 0 ) ? round($tarif_km * $km_excep, 2) : 0.0;
            $montant     = $montant_hab + $montant_exc;

            $total_global += $montant;
            $nb_actifs++;
            if ( $km === 0.0 && $nb > 0 ) $sans_km[] = trim($t->nom);

            $lignes[] = array(
                'nom'          => trim($t->nom),
                'nb'           => $nb,
                'km'           => $km,
                'km_excep'     => $km_excep,
                'excep_detail' => $excep_detail,
                'montant'      => $montant,
            );
        }

        if ( empty($lignes) ) return 0;

        $total_str = number_format($total_global, 2, ',', ' ') . ' €';

        // ── Sujet ────────────────────────────────────────────────────────────
        $subject = '[' . $nom_club . '] 📊 Récapitulatif entraîneurs — ' . $mois_label;

        // ── Email HTML ───────────────────────────────────────────────────────
        $rows_html = '';
        foreach ( $lignes as $i => $l ) {
            $bg      = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
            $km_str  = $l['km'] > 0
                ? number_format($l['km'], 1, ',', '') . ' km'
                : '<span style="color:#94a3b8;">—</span>';
            // Km exceptionnels
            if ( ! empty($l['km_excep']) && $l['km_excep'] > 0 ) {
                $km_str .= '<br><span style="font-size:11px;color:#7c3aed;">+ ' . number_format($l['km_excep'],1,',','') . ' km excep.</span>';
                if ( $l['excep_detail'] ) {
                    $km_str .= '<br><span style="font-size:10px;color:#9ca3af;">' . esc_html($l['excep_detail']) . '</span>';
                }
            }
            $m_str   = $l['montant'] > 0
                ? '<strong>' . number_format($l['montant'], 2, ',', ' ') . ' €</strong>'
                : '<span style="color:#94a3b8;">0,00 €</span>';
            $rows_html .= '
            <tr style="background:' . $bg . ';">
                <td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;">' . esc_html($l['nom']) . '</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;">' . $l['nb'] . ' cours</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:center;">' . $km_str . '</td>
                <td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;text-align:right;">' . $m_str . '</td>
            </tr>';
        }

        // Ligne total
        $rows_html .= '
            <tr style="background:#1e3a5f;color:#ffffff;">
                <td style="padding:11px 14px;font-weight:700;" colspan="3">Total à régler</td>
                <td style="padding:11px 14px;text-align:right;font-weight:700;font-size:15px;">' . $total_str . '</td>
            </tr>';

        // Bloc alerte km manquants
        $alerte_km = '';
        if ( ! empty($sans_km) ) {
            $alerte_km = '
            <div style="margin:20px 0;padding:12px 16px;background:#fffbeb;border-left:4px solid #f59e0b;border-radius:4px;font-size:13px;color:#92400e;">
                ⚠️ <strong>Km non configurés</strong> pour : ' . esc_html(implode(', ', $sans_km)) . '.<br>
                Montant calculé à 0 € — veuillez renseigner la distance sur leur fiche entraîneur.
            </div>';
        }

        // Bloc tarif
        if ( $tarif_km > 0 ) {
            $tarif_html = '
            <div style="margin-bottom:20px;padding:10px 14px;background:#f0f9ff;border-left:4px solid #0ea5e9;border-radius:4px;font-size:13px;color:#0c4a6e;">
                💶 Tarif : <strong>' . number_format($tarif_km, 2, ',', ' ') . ' €/km</strong>
                &nbsp;·&nbsp; Formule : tarif × km A/R × interventions
            </div>';
        } else {
            $tarif_html = '
            <div style="margin-bottom:20px;padding:10px 14px;background:#fef2f2;border-left:4px solid #ef4444;border-radius:4px;font-size:13px;color:#7f1d1d;">
                ⚠️ Aucun tarif kilométrique configuré — les montants sont à 0 €.<br>
                Renseignez le tarif dans <em>Paramètres → Récapitulatif mensuel</em>.
            </div>';
        }

        $admin_url = admin_url('admin.php?page=sp-cal-pro');

        $body = '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);">

      <!-- En-tête -->
      <tr>
        <td style="background:#1e3a5f;padding:28px 32px;">
          <p style="margin:0;font-size:13px;color:#94a3b8;letter-spacing:.5px;text-transform:uppercase;">Récapitulatif mensuel</p>
          <h1 style="margin:6px 0 0;font-size:22px;color:#ffffff;font-weight:700;">📊 ' . esc_html($mois_label) . '</h1>
          <p style="margin:4px 0 0;font-size:13px;color:#93c5fd;">' . esc_html($nom_club) . '</p>
        </td>
      </tr>

      <!-- Corps -->
      <tr>
        <td style="padding:28px 32px;">

          <p style="margin:0 0 20px;font-size:15px;color:#334155;">
            Voici le bilan des interventions des entraîneurs pour le mois de <strong>' . esc_html($mois_label) . '</strong>.
          </p>

          ' . $tarif_html . '

          <!-- Tableau -->
          <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;font-size:14px;">
            <thead>
              <tr style="background:#1e3a5f;color:#ffffff;">
                <th style="padding:11px 14px;text-align:left;font-weight:700;">Entraîneur</th>
                <th style="padding:11px 14px;text-align:center;font-weight:700;">Interventions</th>
                <th style="padding:11px 14px;text-align:center;font-weight:700;">Km A/R</th>
                <th style="padding:11px 14px;text-align:right;font-weight:700;">Montant</th>
              </tr>
            </thead>
            <tbody>
              ' . $rows_html . '
            </tbody>
          </table>

          <!-- Résumé -->
          <div style="margin-top:16px;font-size:14px;color:#475569;">
            Entraîneurs actifs ce mois : <strong>' . $nb_actifs . '</strong>
          </div>

          ' . $alerte_km . '

          <!-- Note -->
          <p style="margin:20px 0 0;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:16px;">
            Une intervention = une date où l\'entraîneur était disponible ✅ et où au moins un cours était prévu.
          </p>

          <!-- Bouton -->
          <div style="margin-top:24px;text-align:center;">
            <a href="' . esc_url($admin_url) . '" style="display:inline-block;padding:12px 28px;background:#1e3a5f;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:700;">
              Consulter le calendrier →
            </a>
          </div>

        </td>
      </tr>

      <!-- Pied de page -->
      <tr>
        <td style="background:#f8fafc;padding:16px 32px;text-align:center;border-top:1px solid #e2e8f0;">
          <p style="margin:0;font-size:12px;color:#94a3b8;">' . esc_html($nom_club) . ' · Récapitulatif automatique mensuel</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>';

        // ── Envoi ────────────────────────────────────────────────────────────
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $sent    = 0;
        foreach ( $bureau as $m ) {
            if ( ! $m->email || ! is_email($m->email) ) continue;
            if ( wp_mail( $m->email, $subject, $body, $headers ) ) $sent++;
        }
        return $sent;
    }

    /* ══════════════════════════════════════════════════════════
       INSCRIPTIONS AUX ÉVÉNEMENTS
    ══════════════════════════════════════════════════════════ */

    /**
     * Envoie les emails d'invitation pour un événement.
     * Crée les entrées 'en_attente' si elles n'existent pas encore.
     *
     * @param  object $event   Ligne de la table events
     * @param  array  $eleves  Tableau d'objets (id, nom, prenom, email, token)
     * @return int             Nombre d'emails envoyés
     */
    public function send_invitation_inscription( $event, $eleves ) {
        $nom_club = get_option( 'blogname', 'Club' );
        $date_obj = $event->date ? date_create( $event->date ) : null;
        $date_fmt = $date_obj ? $date_obj->format( 'd/m/Y' ) : $event->date;
        $deadline = '';
        if ( $event->inscriptions_deadline ) {
            $dl_obj   = date_create( $event->inscriptions_deadline );
            $deadline = $dl_obj ? $dl_obj->format( 'd/m/Y' ) : '';
        }
        $heure   = $event->heure_debut ? ' &middot; ' . esc_html( substr( $event->heure_debut, 0, 5 ) ) : '';
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $sent    = 0;

        foreach ( $eleves as $el ) {
            if ( ! $el->email || ! is_email( $el->email ) ) continue;

            // Initialise l'inscription si elle n'existe pas
            $existing = $this->db->get_inscription_eleve( intval( $event->id ), intval( $el->id ) );
            if ( ! $existing ) {
                $this->db->save_inscription( intval( $event->id ), intval( $el->id ), 'en_attente', '' );
            }

            $url_oui = add_query_arg( array(
                'sp_insc_token' => $el->token,
                'sp_insc_event' => intval( $event->id ),
                'sp_insc_rep'   => 'oui',
            ), home_url( '/' ) );
            $url_non = add_query_arg( array(
                'sp_insc_token' => $el->token,
                'sp_insc_event' => intval( $event->id ),
                'sp_insc_rep'   => 'non',
            ), home_url( '/' ) );

            $subject = '[' . $nom_club . '] Inscription — ' . $event->titre . ' du ' . $date_fmt;

            $body  = '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#f8fafc;margin:0;padding:20px;">';
            $body .= '<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;margin:0 auto;box-shadow:0 2px 8px rgba(0,0,0,.08);">';
            $body .= '<tr><td style="background:#111;padding:24px 32px;">';
            $body .= '<h1 style="margin:0;font-size:20px;color:#fff;">' . esc_html( $nom_club ) . '</h1>';
            $body .= '<p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.5);">Inscription à un événement</p>';
            $body .= '</td></tr>';
            $body .= '<tr><td style="padding:32px;">';
            $body .= '<p style="font-size:16px;margin:0 0 8px;">Bonjour <strong>' . esc_html( $el->prenom ) . '</strong>,</p>';
            $body .= '<p style="color:#374151;margin:0 0 24px;">Vous êtes invité(e) à vous inscrire à l&#39;événement suivant&nbsp;:</p>';
            $body .= '<div style="background:#f1f5f9;border-radius:8px;padding:16px 20px;margin-bottom:24px;">';
            $body .= '<div style="font-size:18px;font-weight:600;color:#111;margin-bottom:4px;">' . esc_html( wp_unslash( $event->titre ) ) . '</div>';
            $body .= '<div style="color:#6b7280;font-size:14px;">📅 ' . esc_html( $date_fmt ) . $heure . '</div>';
            if ( $deadline ) {
                $body .= '<div style="color:#f59e0b;font-size:13px;margin-top:8px;">⏰ Répondre avant le ' . esc_html( $deadline ) . '</div>';
            }
            // Description de l'événement
            if ( ! empty( $event->description ) ) {
                $body .= '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #e2e8f0;font-size:13px;color:#374151;line-height:1.6;">' . nl2br( esc_html( wp_unslash( $event->description ) ) ) . '</div>';
            }
            // Message complémentaire spécifique aux inscriptions
            if ( ! empty( $event->inscriptions_message ) ) {
                $body .= '<div style="margin-top:8px;padding:10px 14px;background:#fff8e1;border-left:3px solid #f59e0b;border-radius:4px;font-size:13px;color:#374151;line-height:1.6;">' . nl2br( esc_html( wp_unslash( $event->inscriptions_message ) ) ) . '</div>';
            }
            $body .= '</div>';
            $body .= '<table width="100%" cellpadding="0" cellspacing="0"><tr>';
            $body .= '<td style="padding-right:8px;"><a href="' . esc_url( $url_oui ) . '" style="display:block;text-align:center;background:#16a34a;color:#fff;text-decoration:none;border-radius:8px;padding:14px 20px;font-weight:600;font-size:15px;">✅ Je participe</a></td>';
            $body .= '<td style="padding-left:8px;"><a href="' . esc_url( $url_non ) . '" style="display:block;text-align:center;background:#dc2626;color:#fff;text-decoration:none;border-radius:8px;padding:14px 20px;font-weight:600;font-size:15px;">❌ Je ne peux pas</a></td>';
            $body .= '</tr></table>';
            $body .= '</td></tr>';
            $body .= '<tr><td style="background:#f8fafc;padding:16px 32px;text-align:center;border-top:1px solid #e2e8f0;">';
            $body .= '<p style="margin:0;font-size:12px;color:#94a3b8;">' . esc_html( $nom_club ) . ' · Gestion des inscriptions</p>';
            $body .= '</td></tr></table></body></html>';

            if ( wp_mail( $el->email, $subject, $body, $headers ) ) $sent++;
        }
        return $sent;
    }

    /**
     * Envoie un rappel de deadline aux élèves encore en_attente.
     */
    public function send_deadline_inscription( $event ) {
        global $wpdb;
        $ti  = $this->db->table_event_inscriptions();
        $tel = $this->db->table_eleves();

        $eleves = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.eleve_id AS id, e.prenom, e.nom, e.email, e.token
             FROM $ti i
             LEFT JOIN $tel e ON e.id = i.eleve_id
             WHERE i.event_id = %d AND i.statut = 'en_attente' AND e.actif = 1",
            intval( $event->id )
        ) );
        if ( empty( $eleves ) ) return 0;

        return $this->send_invitation_inscription( $event, $eleves );
    }

    /**
     * Cron quotidien : envoie un rappel J-2 avant la deadline.
     * Appelé uniquement si configuré — hook optionnel.
     */
    public function check_deadlines_inscriptions() {
        global $wpdb;
        $te    = $this->db->table_events();
        $cible = date( 'Y-m-d', strtotime( '+2 days' ) );
        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $te
             WHERE inscriptions_actives = 1
               AND inscriptions_envoye  = 1
               AND inscriptions_deadline = %s",
            $cible
        ) );
        foreach ( $events as $event ) {
            $this->send_deadline_inscription( $event );
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       NOTIFICATION ANNULATION COURS
       $annulations = tableau d'items [ event_id, slot_id, date, titre,
                       heure_debut, heure_fin, categorie, motif ]
       Un seul email par élève, listant tous ses cours annulés.
    ══════════════════════════════════════════════════════════════════════ */
    public function send_annulation_cours( array $annulations ) {
        if ( empty( $annulations ) ) return 0;

        global $wpdb;
        $tel      = $this->db->table_eleves();
        $nom_club = get_option( 'blogname', 'Club' );
        $headers  = array( 'Content-Type: text/html; charset=UTF-8' );
        $sent     = 0;

        // ── Construire la map : eleve_id → liste des annulations qui le concernent ──
        $map = array();

        foreach ( $annulations as $item ) {
            $categorie = $item['categorie'] ?? '';

            if ( $categorie ) {
                $eleves = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, prenom, nom, email, email_parent
                     FROM $tel
                     WHERE actif = 1
                       AND categorie_saisie = %s
                       AND ( email != '' OR email_parent != '' )",
                    $categorie
                ) );
            } else {
                $eleves = $wpdb->get_results(
                    "SELECT id, prenom, nom, email, email_parent
                     FROM $tel
                     WHERE actif = 1 AND ( email != '' OR email_parent != '' )"
                );
            }

            foreach ( $eleves as $el ) {
                $dest = ! empty( $el->email_parent ) ? $el->email_parent : $el->email;
                if ( ! $dest || ! is_email( $dest ) ) continue;
                if ( ! isset( $map[ $el->id ] ) ) {
                    $map[ $el->id ] = array( 'eleve' => $el, 'dest' => $dest, 'items' => array() );
                }
                // Déduplication : un même cours ne doit apparaître qu'une fois par élève
                $already = false;
                foreach ( $map[ $el->id ]['items'] as $existing ) {
                    if ( $existing['event_id'] === $item['event_id'] ) { $already = true; break; }
                }
                if ( ! $already ) $map[ $el->id ]['items'][] = $item;
            }
        }

        // ── Envoyer un email par élève ──────────────────────────────────────
        foreach ( $map as $entry ) {
            $el    = $entry['eleve'];
            $dest  = $entry['dest'];
            $items = $entry['items'];
            $nb    = count( $items );

            $subject = '[' . $nom_club . '] Annulation de cours — '
                     . ( $nb > 1 ? $nb . ' cours annulés' : esc_html( $items[0]['titre'] ) );

            $body  = '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#f8fafc;margin:0;padding:20px;">';
            $body .= '<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;margin:0 auto;box-shadow:0 2px 8px rgba(0,0,0,.08);">';
            $body .= '<tr><td style="background:#111;padding:24px 32px;">';
            $body .= '<h1 style="margin:0;font-size:20px;color:#fff;">' . esc_html( $nom_club ) . '</h1>';
            $body .= '<p style="margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.5);">Information — Annulation de cours</p>';
            $body .= '</td></tr>';
            $body .= '<tr><td style="padding:32px;">';
            $body .= '<p style="font-size:16px;margin:0 0 8px;">Bonjour <strong>' . esc_html( $el->prenom ) . '</strong>,</p>';
            $body .= '<p style="color:#374151;margin:0 0 24px;">';
            $body .= $nb > 1
                ? 'Nous vous informons que les cours suivants sont annulés&nbsp;:'
                : 'Nous vous informons que le cours suivant est annulé&nbsp;:';
            $body .= '</p>';

            foreach ( $items as $it ) {
                $date_obj = ! empty( $it['date'] ) ? date_create( $it['date'] ) : null;
                $date_fmt = $date_obj ? $date_obj->format( 'd/m/Y' ) : esc_html( $it['date'] ?? '' );
                $horaire  = '';
                if ( ! empty( $it['heure_debut'] ) ) {
                    $horaire = substr( $it['heure_debut'], 0, 5 );
                    if ( ! empty( $it['heure_fin'] ) ) $horaire .= ' – ' . substr( $it['heure_fin'], 0, 5 );
                }
                $body .= '<div style="background:#fef2f2;border-left:4px solid #ef4444;border-radius:8px;padding:14px 18px;margin-bottom:14px;">';
                $body .= '<div style="font-size:16px;font-weight:600;color:#111;margin-bottom:4px;">🚫 ' . esc_html( $it['titre'] ) . '</div>';
                $body .= '<div style="font-size:13px;color:#6b7280;">📅 ' . esc_html( $date_fmt );
                if ( $horaire ) $body .= '&nbsp; · &nbsp;🕐 ' . esc_html( $horaire );
                $body .= '</div>';
                if ( ! empty( $it['motif'] ) ) {
                    $body .= '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #fecaca;'
                           . 'font-size:13px;color:#374151;line-height:1.6;">'
                           . nl2br( esc_html( wp_unslash( $it['motif'] ) ) ) . '</div>';
                }
                $body .= '</div>';
            }

            $body .= '<p style="color:#6b7280;font-size:13px;margin-top:20px;">À bientôt sur le tatami&nbsp;! 🥋</p>';
            $body .= '</td></tr>';
            $body .= '<tr><td style="background:#f8fafc;padding:16px 32px;text-align:center;border-top:1px solid #e2e8f0;">';
            $body .= '<p style="margin:0;font-size:12px;color:#94a3b8;">' . esc_html( $nom_club ) . ' · Gestion des cours</p>';
            $body .= '</td></tr></table></body></html>';

            if ( wp_mail( $dest, $subject, $body, $headers ) ) $sent++;
        }

        return $sent;
    }

}

endif; // class_exists SpCalPro_Notifications

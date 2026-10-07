<?php
/**
 * Requêtes « calendrier » : entraîneurs et bureau, créneaux et occurrences, événements, présences, disponibilités, interventions (IK), ciblage des créneaux.
 *
 * Méthodes de SpCalPro_DB déplacées telles quelles depuis class-db.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_DB_Calendrier; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_DB_Calendrier {


    /* ── Membres de bureau ───────────────────────────────── */

    /**
     * Retourne les membres ayant le rôle 'bureau' (champ roles contient 'bureau').
     */
    public function get_bureau_members() {
        global $wpdb;
        $tt = $this->table_trainers();
        return $wpdb->get_results(
            "SELECT * FROM $tt WHERE actif=1 AND roles LIKE '%bureau%' ORDER BY ordre ASC, nom ASC"
        );
    }

    /**
     * Compte les interventions réelles par entraîneur sur un mois donné (base des IK).
     *
     * Une journée d'intervention est comptabilisée si :
     *   1. L'entraîneur a disponible=1 dans trainer_dispos pour cette date.
     *   2. Il existe au moins un créneau récurrent non annulé OU un événement ponctuel
     *      ce même jour (la date était bien un jour d'activité du club).
     * Chaque journée compte 1 ou 2 allers-retours (colonne allers_retours, déclarée par
     * l'entraîneur quand ses deux interventions du jour sont trop éloignées) : les IK se
     * calculent sur les allers-retours.
     *
     * @return array  [ trainer_id (int) => [ 'jours' => int, 'ar' => int ] ]
     */
    public function get_interventions_par_trainer( $year, $month ) {
        global $wpdb;

        $start   = sprintf( '%04d-%02d-01', $year, $month );
        $end     = date( 'Y-m-t', strtotime( $start ) );
        $te      = $this->table_events();
        $tdispos = $this->table_trainer_dispos();

        // ── Étape 1 : construire l'ensemble des dates actives du mois ──────────
        // (= jours où il y a au moins un cours/événement réel, non annulé)
        $dates_actives = array();

        // a) Créneaux récurrents non annulés
        $occurrences = $this->get_slot_occurrences( $start, $end );
        foreach ( $occurrences as $occ ) {
            if ( empty( $occ['annul_id'] ) ) {
                $dates_actives[ $occ['date'] ] = true;
            }
        }

        // b) Événements ponctuels (cours, evenement, examen, competition) hors annulations
        $ponctuels = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT date FROM $te
             WHERE date BETWEEN %s AND %s
               AND slot_id IS NULL
               AND type NOT IN ('annulation')",
            $start, $end
        ) );
        foreach ( $ponctuels as $p ) {
            $dates_actives[ $p->date ] = true;
        }

        if ( empty( $dates_actives ) ) return array();

        // ── Étape 2 : pour chaque entraîneur, compter les jours dispo=1 parmi les dates actives ──
        $dates_safe = implode( ',', array_map( function( $d ) use ( $wpdb ) {
            return $wpdb->prepare( '%s', $d );
        }, array_keys( $dates_actives ) ) );

        // Colonne pas encore migrée (maybe_upgrade() ne passe qu'au chargement de l'admin,
        // le récapitulatif part par le cron) : 1 aller-retour par journée.
        $ar_sql = $this->dispos_ont_allers_retours() ? 'SUM(GREATEST(1, LEAST(2, allers_retours)))' : 'COUNT(*)';
        $rows = $wpdb->get_results(
            "SELECT trainer_id, COUNT(*) AS nb, $ar_sql AS ar
             FROM $tdispos
             WHERE date IN ($dates_safe)
               AND disponible = 1
             GROUP BY trainer_id"
        );

        $result = array();
        foreach ( $rows as $r ) {
            $result[ intval( $r->trainer_id ) ] = array( 'jours' => intval( $r->nb ), 'ar' => intval( $r->ar ) );
        }
        return $result;
    }

    /** La colonne allers_retours existe-t-elle déjà sur ce site ? */
    private function dispos_ont_allers_retours() {
        static $ok = null;
        if ( $ok === null ) {
            global $wpdb;
            $ok = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$this->table_trainer_dispos()}` LIKE 'allers_retours'" );
        }
        return $ok;
    }

    /* ══════════════════════════════════════════════════════════
       OCCURRENCES DES CRÉNEAUX RÉCURRENTS
    ══════════════════════════════════════════════════════════ */

    /**
     * Calcule toutes les occurrences des créneaux dans [start_str … end_str].
     * Chaque item : slot_id, date, heure_debut, heure_fin, titre, categorie, recurrence, annul_id
     * annul_id = null si cours actif, int (ID de l'event annulation) si annulé.
     */
    public function get_slot_occurrences( $start_str, $end_str ) {
        global $wpdb;
        $te    = $this->table_events();
        $slots = $this->get_slots();
        if ( empty( $slots ) ) return array();

        // Map des annulations : "YYYY-MM-DD_slot_id" => event_id
        $annul_map = array();
        foreach ( $wpdb->get_results( $wpdb->prepare(
            "SELECT id, date, slot_id FROM $te WHERE type='annulation' AND slot_id IS NOT NULL AND date BETWEEN %s AND %s",
            $start_str, $end_str
        ) ) as $r ) {
            $annul_map[ $r->date . '_' . $r->slot_id ] = intval( $r->id );
        }

        // Map des matérialisations : "YYYY-MM-DD_slot_id" => event_id
        // Inclut tous les types non-annulation avec slot_id (rétrocompat events sauvés en 'evenement' par erreur)
        $mat_map = array();
        foreach ( $wpdb->get_results( $wpdb->prepare(
            "SELECT id, date, slot_id FROM $te WHERE slot_id IS NOT NULL AND type NOT IN ('annulation') AND date BETWEEN %s AND %s",
            $start_str, $end_str
        ) ) as $r ) {
            $key = $r->date . '_' . $r->slot_id;
            // Garder la matérialisation la plus récente si plusieurs (ne devrait pas arriver après le nettoyage)
            if ( ! isset($mat_map[$key]) ) $mat_map[$key] = intval( $r->id );
        }

        $s_dt = new DateTime( $start_str );
        $e_dt = new DateTime( $end_str );
        $out  = array();

        foreach ( $slots as $slot ) {
            $jour = intval( $slot->jour );
            $rec  = $slot->recurrence ?: 'weekly';
            $dd   = ! empty( $slot->date_debut ) ? new DateTime( $slot->date_debut ) : null;
            $df   = ! empty( $slot->date_fin )   ? new DateTime( $slot->date_fin )   : null;

            if ( strpos( $rec, 'monthly_' ) === 0 ) {
                // ── Mensuel ──────────────────────────────────────────
                if ( ! $dd ) continue;
                $n = max( 1, intval( substr( $rec, 8 ) ) );
                $d = clone $dd;
                while ( $d <= $e_dt ) {
                    if ( $d >= $s_dt && ( ! $df || $d <= $df ) ) {
                        $ds  = $d->format( 'Y-m-d' );
                        $key = $ds . '_' . $slot->id;
                        $out[] = $this->build_occ( $slot, $ds, $annul_map[$key] ?? null, $mat_map[$key] ?? null );
                    }
                    $d->modify( "+{$n} months" );
                }
            } else {
                // ── Hebdo / bihebdo / 3 semaines ─────────────────────
                $d = clone $s_dt;
                $diff = ( $jour - intval( $d->format( 'N' ) ) + 7 ) % 7;
                if ( $diff ) $d->modify( "+{$diff} days" );

                // Référence fixe pour bi/3weekly : date_debut si dispo,
                // sinon la première occurrence du slot dans la fenêtre courante
                $ref_biweekly = $dd ?: clone $d;

                while ( $d <= $e_dt ) {
                    if ( $dd && $d < $dd ) { $d->modify( '+7 days' ); continue; }
                    if ( $df && $d > $df ) break;

                    $inc = false;
                    if ( $rec === 'weekly' ) {
                        $inc = true;
                    } elseif ( $rec === 'biweekly' ) {
                        $days = intval( $ref_biweekly->diff( $d )->days );
                        $inc  = ( $days % 14 === 0 );
                    } elseif ( $rec === '3weekly' ) {
                        $days = intval( $ref_biweekly->diff( $d )->days );
                        $inc  = ( $days % 21 === 0 );
                    }

                    if ( $inc ) {
                        $ds  = $d->format( 'Y-m-d' );
                        $key = $ds . '_' . $slot->id;
                        $out[] = $this->build_occ( $slot, $ds, $annul_map[$key] ?? null, $mat_map[$key] ?? null );
                    }
                    $d->modify( '+7 days' );
                }
            }
        }

        usort( $out, function( $a, $b ) {
            $c = strcmp( $a['date'], $b['date'] );
            return $c !== 0 ? $c : strcmp( $a['heure_debut'], $b['heure_debut'] );
        } );
        return $out;
    }

    private function build_occ( $slot, $date_str, $annul_id, $mat_id = null ) {
        return array(
            'slot_id'     => intval( $slot->id ),
            'date'        => $date_str,
            'heure_debut' => $slot->heure_debut,
            'heure_fin'   => $slot->heure_fin,
            'titre'       => $slot->label,
            'categorie'   => $slot->categorie,
            'cours_discipline'     => (string) ( $slot->cours_discipline     ?? '' ),
            'cours_age_categories' => (string) ( $slot->cours_age_categories ?? '' ),
            'recurrence'  => $slot->recurrence ?: 'weekly',
            'annul_id'    => $annul_id,
            'mat_id'      => $mat_id,  // ID de la matérialisation en DB si elle existe
        );
    }

    /* ── Helpers ─────────────────────────────────────────────── */

    public function get_trainers( $actif_only = false ) {
        global $wpdb;
        $tt = $this->table_trainers();
        return $wpdb->get_results( "SELECT * FROM $tt " . ( $actif_only ? 'WHERE actif=1 ' : '' ) . "ORDER BY ordre ASC, nom ASC" );
    }

    /**
     * Retourne uniquement les entraîneurs actifs (rôle "entraineur"),
     * en excluant les membres dont le rôle est exclusivement "bureau".
     * Utilisé pour le popup de disponibilités et la liste admin entraîneurs.
     *
     * @param bool $actif_only  Si true, filtre uniquement les entraîneurs actifs.
     * @return array
     */
    public function get_trainers_entraineurs( $actif_only = false ) {
        global $wpdb;
        $tt    = $this->table_trainers();
        $where = $actif_only ? "WHERE actif=1 AND roles LIKE '%entraineur%'" : "WHERE roles LIKE '%entraineur%'";
        return $wpdb->get_results( "SELECT * FROM $tt $where ORDER BY ordre ASC, nom ASC" );
    }

    public function get_slots( $jour = null ) {
        global $wpdb;
        $tsl = $this->table_slots();
        if ( null !== $jour )
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tsl WHERE jour=%d ORDER BY heure_debut ASC, ordre ASC", intval( $jour ) ) );
        return $wpdb->get_results( "SELECT * FROM $tsl ORDER BY jour ASC, heure_debut ASC, ordre ASC" );
    }

    public function get_events_by_month( $year, $month ) {
        global $wpdb;
        $te    = $this->table_events();
        $start = sprintf( '%04d-%02d-01', $year, $month );
        $end   = date( 'Y-m-t', strtotime( $start ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $te WHERE date BETWEEN %s AND %s ORDER BY date ASC, heure_debut ASC", $start, $end
        ) );
    }

    public function get_presences_event( $event_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_presences_eleves()} WHERE event_id=%d", intval( $event_id ) ) );
    }

    /**
     * Historique des présences d'un élève, jointé avec les events.
     * Retourne : date, heure_debut, heure_fin, titre, categorie, present
     */
    public function get_presences_eleve( $eleve_id ) {
        global $wpdb;
        $tpe = $this->table_presences_eleves();
        $te  = $this->table_events();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT e.date, e.heure_debut, e.heure_fin, e.titre, e.categorie, e.type, pe.present, pe.note
             FROM $tpe pe
             INNER JOIN $te e ON e.id = pe.event_id
             WHERE pe.eleve_id = %d
             ORDER BY e.date DESC, e.heure_debut DESC",
            intval( $eleve_id )
        ) );
    }

    /* ══════════════════════════════════════════════════════════
       DISPONIBILITÉS ENTRAÎNEURS
    ══════════════════════════════════════════════════════════ */

    /**
     * Récupère toutes les dispos des entraîneurs pour un mois donné.
     * Retourne un tableau indexé par "YYYY-MM-DD" => [ trainer_id => ['disponible'=>0/1,'note'=>'','remplacant_id','allers_retours'=>1/2] ]
     */
    public function get_dispos_for_month( $year, $month ) {
        global $wpdb;
        $tdispos = $this->table_trainer_dispos();
        $start   = sprintf( '%04d-%02d-01', $year, $month );
        $end     = date( 'Y-m-t', strtotime( $start ) );
        $rows    = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $tdispos WHERE date BETWEEN %s AND %s", // SELECT * : colonnes ajoutées au fil du temps
            $start, $end
        ) );
        $map = array();
        foreach ( $rows as $r ) {
            if ( ! isset( $map[ $r->date ] ) ) $map[ $r->date ] = array();
            $map[ $r->date ][ intval( $r->trainer_id ) ] = array(
                'disponible'    => intval( $r->disponible ),
                'note'          => $r->note,
                'remplacant_id' => ! empty( $r->remplacant_id ) ? intval( $r->remplacant_id ) : null,
                'allers_retours' => intval( $r->disponible ) === 1 && intval( $r->allers_retours ?? 1 ) === 2 ? 2 : 1,
            );
        }
        return $map;
    }

    /**
     * Récupère les dispos d'une date précise pour tous les entraîneurs.
     * Retourne [ trainer_id => ['disponible'=>0/1,'note'=>'','remplacant_id','allers_retours'=>1/2] ]
     */
    public function get_dispos_for_date( $date_str ) {
        global $wpdb;
        $tdispos = $this->table_trainer_dispos();
        $rows    = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $tdispos WHERE date = %s", $date_str // SELECT * : colonnes ajoutées au fil du temps
        ) );
        $map = array();
        foreach ( $rows as $r ) {
            $map[ intval( $r->trainer_id ) ] = array(
                'disponible'    => intval( $r->disponible ),
                'note'          => $r->note,
                'remplacant_id' => ! empty( $r->remplacant_id ) ? intval( $r->remplacant_id ) : null,
                'allers_retours' => intval( $r->disponible ) === 1 && intval( $r->allers_retours ?? 1 ) === 2 ? 2 : 1,
            );
        }
        return $map;
    }

    /**
     * Encadrement d'une journée d'entraînement, d'après les disponibilités déclarées (par jour) :
     * un entraîneur qui se déplace reste pour tous les cours du jour (choix du 01/10/2026,
     * plutôt qu'un titulaire par créneau). Même règle que les cases du calendrier admin :
     * seuls les entraîneurs actifs déclarés disponibles, plus le remplaçant désigné par le
     * bureau pour un entraîneur absent (« Noé (remplace Thanh) »). Utilisé par l'application
     * (route /calendrier/club) et par le mail au bureau quand une dispo change.
     *
     * @return string[] noms à afficher, dans l'ordre des entraîneurs ; vide = non renseigné
     */
    public function get_encadrement_du_jour( $date ) {
        static $entraineurs = null, $tous = null;
        if ( $entraineurs === null ) {
            $entraineurs = $this->get_trainers_entraineurs( true );
            $tous        = array();
            foreach ( $this->get_trainers( false ) as $t ) {
                $tous[ intval( $t->id ) ] = $t->nom_public ?: $t->nom;
            }
        }
        $dispos = $this->get_dispos_for_date( $date );
        $noms   = array(); // trainer_id affiché => libellé (le remplaçant remplace sa propre ligne « disponible »)
        foreach ( $entraineurs as $t ) {
            $id = intval( $t->id );
            $d  = $dispos[ $id ] ?? null;
            if ( ! $d ) continue;
            $nom = $t->nom_public ?: $t->nom;
            if ( $d['disponible'] === 1 ) {
                if ( ! isset( $noms[ $id ] ) ) $noms[ $id ] = $nom;
            } elseif ( ! empty( $d['remplacant_id'] ) && isset( $tous[ $d['remplacant_id'] ] ) ) {
                $noms[ $d['remplacant_id'] ] = $tous[ $d['remplacant_id'] ] . ' (remplace ' . $nom . ')';
            }
        }
        return array_values( $noms );
    }

    /**
     * Sauvegarde (upsert) la dispo d'un entraîneur pour une date.
     * $disponible     : 1 = dispo, 0 = indisponible, null = supprimer l'entrée
     * $allers_retours : 1 ou 2 (seulement quand disponible) ; null = garder la valeur déjà
     *                   enregistrée (enregistrement d'une note, ancienne page sans le choix)
     */
    public function save_dispo( $trainer_id, $date_str, $disponible, $note = '', $remplacant_id = null, $allers_retours = null ) {
        global $wpdb;
        $tdispos       = $this->table_trainer_dispos();
        $trainer_id    = intval( $trainer_id );
        $note          = sanitize_text_field( $note );
        $remplacant_id = $remplacant_id ? intval( $remplacant_id ) : null;

        if ( null === $disponible ) {
            // Supprimer → retour à "non renseigné"
            $wpdb->delete( $tdispos, array( 'trainer_id' => $trainer_id, 'date' => $date_str ) );
            return;
        }

        $row = array( 'disponible' => intval($disponible), 'note' => $note, 'remplacant_id' => $remplacant_id );
        if ( $this->dispos_ont_allers_retours() ) {
            if ( intval( $disponible ) !== 1 )   $row['allers_retours'] = 1;
            elseif ( null !== $allers_retours ) $row['allers_retours'] = intval( $allers_retours ) === 2 ? 2 : 1;
        }

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $tdispos WHERE trainer_id=%d AND date=%s", $trainer_id, $date_str
        ) );
        if ( $exists ) {
            $wpdb->update( $tdispos, $row, array( 'id' => intval($exists) ) );
        } else {
            $wpdb->insert( $tdispos, array_merge( array(
                'trainer_id'    => $trainer_id,
                'date'          => $date_str,
            ), $row ) );
        }
    }

    /**
     * Traduit l'ancien texte libre « Catégorie » d'un créneau (« TKD, Boxe, Renfo… »,
     * « Enfant », « Renfo & Ados/Adultes »…) en discipline / tranche d'âge, fragment par
     * fragment (séparés par des virgules), avec la table des événements
     * (migrer_fragment_categorie_evenement()). Contrairement aux événements, un fragment non
     * reconnu n'est pas rangé en « Autre » (= tout le club) : il est signalé.
     *
     * @return array{discipline:string[],age:string[],non_reconnus:string[],tout:bool}
     *         tout = vide ou « Général » / « Tous » : le créneau vise tout le club
     */
    public function axes_depuis_texte_creneau( $categorie ) {
        $out = array( 'discipline' => array(), 'age' => array(), 'non_reconnus' => array(), 'tout' => false );
        $fragments = array_filter( array_map( 'trim', explode( ',', (string) $categorie ) ) );
        if ( ! $fragments ) { $out['tout'] = true; return $out; }
        foreach ( $fragments as $f ) {
            // « Général » est aussi le libellé affiché d'un créneau sans catégorie : tout le club.
            if ( in_array( mb_strtolower( $f ), array( 'général', 'general', 'tous', 'tout le club' ), true ) ) { $out['tout'] = true; continue; }
            $res = $this->migrer_fragment_categorie_evenement( $f );
            $ok  = false;
            if ( ! empty( $res['discipline'] ) && $res['discipline'] !== 'Autre' ) { $out['discipline'][] = $res['discipline']; $ok = true; }
            if ( ! empty( $res['age'] ) ) { $out['age'][] = $res['age']; $ok = true; }
            if ( ! $ok ) $out['non_reconnus'][] = $f;
        }
        $out['discipline'] = array_values( array_unique( $out['discipline'] ) );
        $out['age']        = array_values( array_unique( $out['age'] ) );
        return $out;
    }

    /**
     * Un créneau (ligne de sp_cal_slots, occurrence de get_slot_occurrences() ou élément
     * d'annulation : clés categorie, cours_discipline, cours_age_categories) vise-t-il cet
     * adhérent ? Axes discipline × âge renseignés → règle des événements (evenement_concerne()) ;
     * créneau pas encore reclassé (axes vides) → lecture de l'ancien texte libre, et
     * comparaison directe de chaque fragment avec la discipline de l'adhérent (TKD, RENFO…).
     * Partagé par l'application adhérent (liste des cours) et le mail d'annulation.
     */
    public function creneau_concerne( $creneau, $cat_saisie, $cat_age ) {
        $c = (object) $creneau;
        if ( trim( (string) ( $c->cours_discipline ?? '' ) ) !== '' || trim( (string) ( $c->cours_age_categories ?? '' ) ) !== '' ) {
            return $this->evenement_concerne( $c, (string) $cat_saisie, (string) $cat_age );
        }
        $axes = $this->axes_depuis_texte_creneau( $c->categorie ?? '' );
        if ( $axes['tout'] ) return true;
        $codes = array_map( 'mb_strtoupper', array_filter( array_map( 'trim', explode( ',', (string) ( $c->categorie ?? '' ) ) ) ) );
        if ( $cat_saisie !== '' && in_array( mb_strtoupper( $cat_saisie ), $codes, true ) ) return true;
        if ( ! $axes['discipline'] && ! $axes['age'] ) return false; // texte non reconnu : personne plutôt que tout le club
        return $this->evenement_concerne( (object) array(
            'cours_discipline'     => implode( ',', $axes['discipline'] ),
            'cours_age_categories' => implode( ',', $axes['age'] ),
        ), (string) $cat_saisie, (string) $cat_age );
    }

    /**
     * Adhérents actifs (avec un email) concernés par un créneau — mail d'annulation de cours.
     * @param array|object|string $creneau  voir creneau_concerne() ; une chaîne = ancien texte libre
     * @return object[]
     */
    public function get_eleves_concernes_creneau( $creneau ) {
        global $wpdb;
        if ( is_string( $creneau ) ) $creneau = array( 'categorie' => $creneau );
        $eleves = $wpdb->get_results(
            "SELECT * FROM {$this->table_eleves()} WHERE actif = 1 AND ( email != '' OR email_parent != '' )"
        );
        return array_values( array_filter( $eleves, function ( $el ) use ( $creneau ) {
            return $this->creneau_concerne( $creneau, trim( (string) ( $el->categorie_saisie ?? '' ) ), trim( (string) ( $el->categorie_age ?? '' ) ) );
        } ) );
    }

    /** Libellé lisible d'un créneau : « Taekwondo · Enfant, Ado/adulte » (ancien texte si pas encore reclassé). */
    public function libelle_creneau( $creneau ) {
        $c    = (object) $creneau;
        $disc = trim( (string) ( $c->cours_discipline ?? '' ) );
        $age  = trim( (string) ( $c->cours_age_categories ?? '' ) );
        if ( $disc === '' && $age === '' ) return (string) ( $c->categorie ?? '' );
        return implode( ' · ', array_filter( array( str_replace( ',', ', ', $disc ), str_replace( ',', ', ', $age ) ) ) );
    }

    /**
     * Reclassement des créneaux existants (ancien texte libre → discipline × âge), sur le
     * modèle de preview_migration_cours_categories(). Ne considère que les créneaux pas encore
     * reclassés (axes vides) qui ont un texte : rejouable sans écraser un choix fait à la main.
     * Un créneau dont aucun fragment n'est reconnu n'est pas modifié (« à classer à la main »).
     */
    public function preview_migration_creneaux_categories() {
        $out = array();
        foreach ( $this->get_slots() as $s ) {
            if ( trim( (string) ( $s->cours_discipline ?? '' ) ) !== '' || trim( (string) ( $s->cours_age_categories ?? '' ) ) !== '' ) continue;
            if ( trim( (string) $s->categorie ) === '' ) continue;
            $axes  = $this->axes_depuis_texte_creneau( $s->categorie );
            $out[] = array(
                'id'           => intval( $s->id ),
                'label'        => $s->label,
                'jour'         => intval( $s->jour ),
                'heure_debut'  => $s->heure_debut,
                'ancienne'     => $s->categorie,
                'discipline'   => implode( ',', $axes['discipline'] ),
                'age'          => implode( ',', $axes['age'] ),
                'non_reconnus' => $axes['non_reconnus'],
                'tout'         => $axes['tout'],
            );
        }
        return $out;
    }

    /** @return int nombre de créneaux reclassés */
    public function appliquer_migration_creneaux_categories() {
        global $wpdb;
        $nb = 0;
        foreach ( $this->preview_migration_creneaux_categories() as $l ) {
            if ( $l['discipline'] === '' && $l['age'] === '' ) {
                if ( ! $l['tout'] ) continue; // rien de reconnu : à classer à la main
                $l['age'] = 'Tout âge';         // « Général » : tout le club
            }
            $wpdb->update( $this->table_slots(), array(
                'cours_discipline'     => $l['discipline'],
                'cours_age_categories' => $l['age'],
                'categorie'            => $l['discipline'],
            ), array( 'id' => $l['id'] ) );
            $nb++;
        }
        return $nb;
    }

    /**
     * L'événement vise-t-il la discipline et la tranche d'âge de l'adhérent ? Axes
     * cours_discipline (Taekwondo / Renforcement musculaire / Autre) × cours_age_categories
     * (18/09/2026). Pas de ciblage, discipline « Autre » ou « Tout âge » = tout le club.
     */
    private function evenement_concerne( $ev, $cat_saisie, $cat_age ) {
        $disc = array_filter( array_map( 'trim', explode( ',', $ev->cours_discipline     ?? '' ) ) );
        $ages = array_filter( array_map( 'trim', explode( ',', $ev->cours_age_categories ?? '' ) ) );

        $renfo   = (bool) preg_match( '/renfo|renforcement/i', $cat_saisie );
        $disc_el = $renfo ? 'Renforcement musculaire' : 'Taekwondo';
        $disc_ok = empty( $disc ) || in_array( 'Autre', $disc, true ) || in_array( $disc_el, $disc, true );

        $age_ok = empty( $ages ) || in_array( 'Tout âge', $ages, true ) || $cat_age === ''
            || in_array( $cat_age, $ages, true )
            || ( $cat_age === 'Ado/adulte' && in_array( 'Adulte', $ages, true ) );

        return $disc_ok && $age_ok;
    }
}

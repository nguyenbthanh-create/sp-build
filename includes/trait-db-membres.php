<?php
/**
 * Requêtes « adhérents » : élèves, catégories, saisons et renouvellement, adhésions, anniversaires, normalisation des catégories d'âge.
 *
 * Méthodes de SpCalPro_DB déplacées telles quelles depuis class-db.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_DB_Membres; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_DB_Membres {


    /**
     * Retourne les membres filtrés par rôle pour l'affichage frontend.
     * @param  array $roles  ex: ['bureau'] ou ['bureau','entraineur']
     */
    public function get_membres_pour_front( array $roles ) {
        global $wpdb;
        $tt = $this->table_trainers();

        // Migration légère : colonnes photo_url et fonction si absentes
        foreach ( array(
            'photo_url' => "varchar(500) NOT NULL DEFAULT ''",
            'fonction'  => "varchar(150) NOT NULL DEFAULT ''",
        ) as $col => $def ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tt` LIKE '$col'" ) )
                $wpdb->query( "ALTER TABLE `$tt` ADD COLUMN `$col` $def" );
        }

        $all = $wpdb->get_results(
            "SELECT * FROM $tt WHERE actif=1 ORDER BY CAST(ordre AS UNSIGNED) ASC, nom ASC"
        );
        if ( ! $all ) return array();

        // Filtrer par rôle en préservant l'ordre SQL
        $result = array();
        foreach ( $all as $m ) {
            $m_roles = array_map( 'trim', explode( ',', $m->roles ) );
            if ( count( array_intersect( $roles, $m_roles ) ) > 0 ) {
                $result[] = $m;
            }
        }
        return $result;
    }

    /* ── Anniversaires du mois (depuis CSV élèves) ───────────── */

    // Uniquement les élèves actifs (09/09/2026, cf. doleances.md) — un compte désactivé en
    // fin de saison ne doit pas polluer le calendrier admin avec son anniversaire tant que
    // la nouvelle saison n'a pas commencé.
    public function get_birthdays_for_month( $year, $month ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT nom, prenom, date_naissance, annee_naissance FROM {$this->table_eleves()} WHERE date_naissance LIKE %s AND actif = 1 ORDER BY date_naissance ASC",
            '%/' . sprintf( '%02d', $month )
        ) );
    }

    public function get_eleves( $args = array() ) {
        global $wpdb;
        $tel = $this->table_eleves(); $w = array( '1=1' ); $p = array();
        if ( ! empty( $args['categorie_age'] ) )     { $w[] = 'categorie_age = %s';     $p[] = $args['categorie_age']; }
        if ( ! empty( $args['categorie_saisie'] ) )  { $w[] = 'categorie_saisie = %s';  $p[] = $args['categorie_saisie']; }
        if ( ! empty( $args['saison'] ) )            { $w[] = 'saison = %s';            $p[] = $args['saison']; }
        if ( isset( $args['actif'] ) )               { $w[] = 'actif = %d';             $p[] = intval( $args['actif'] ); }
        $sql = "SELECT * FROM $tel WHERE " . implode( ' AND ', $w ) . " ORDER BY rang ASC, categorie_age ASC, nom ASC, prenom ASC";
        return $p ? $wpdb->get_results( $wpdb->prepare( $sql, $p ) ) : $wpdb->get_results( $sql );
    }

    public function get_categories_eleves() {
        global $wpdb;
        $tel = $this->table_eleves();
        // Récupère les catégories distinctes, triées par le rang minimum de leur groupe
        return $wpdb->get_col(
            "SELECT categorie_age
             FROM $tel
             WHERE categorie_age != ''
             GROUP BY categorie_age
             ORDER BY MIN(rang) ASC, categorie_age ASC"
        );
    }

    /**
     * Libellé lisible d'un code de discipline (TKD/RENFO, cf. SP_Front_Adhesion::DISCIPLINES).
     * Point central pour l'affichage — évite de dupliquer le mapping code → libellé dans
     * chaque template (fiche, token, PDF, PWA...) et de les laisser diverger.
     */
    public function label_discipline( $code ) {
        $labels = array( 'TKD' => 'Taekwondo', 'RENFO' => 'Renforcement musculaire' );
        return $labels[ $code ] ?? $code;
    }

    public function get_categories_saisie() {
        global $wpdb;
        return $wpdb->get_col( "SELECT DISTINCT categorie_saisie FROM {$this->table_eleves()} WHERE categorie_saisie != '' ORDER BY categorie_saisie ASC" );
    }

    /**
     * Traduit un fragment de l'ancien champ "categorie" libre d'un événement (TKD, RENFO,
     * Général, Prépa CN, Sortie, Renfo & Ados / Adultes...) vers le nouveau modèle à deux axes
     * Discipline (Taekwondo/Renforcement musculaire/Autre) × Pour qui (tranche d'âge) —
     * cf. échange du 18/09/2026. Tout ce qui n'est pas reconnu comme discipline ou âge tombe
     * dans Discipline="Autre" (sorties, fêtes... qui ne touchent pas l'objet sportif du club).
     *
     * @return array{discipline?:string,age?:string}
     */
    private function migrer_fragment_categorie_evenement( $fragment ) {
        $f = mb_strtolower( trim( (string) $fragment ) );
        $f = str_replace( array( ' / ', ' /', '/ ', '-' ), '/', $f );

        $map = array(
            'tkd'                     => array( 'discipline' => 'Taekwondo' ),
            'taekwondo'               => array( 'discipline' => 'Taekwondo' ),
            'renfo'                   => array( 'discipline' => 'Renforcement musculaire' ),
            'renforcement musculaire' => array( 'discipline' => 'Renforcement musculaire' ),
            'renfo & ados/adultes'    => array( 'discipline' => 'Renforcement musculaire', 'age' => 'Ado/adulte' ),
            'renfo&ados/adultes'      => array( 'discipline' => 'Renforcement musculaire', 'age' => 'Ado/adulte' ),
            'baby'                    => array( 'age' => 'Baby' ),
            'babies'                  => array( 'age' => 'Baby' ),
            'enfant'                  => array( 'age' => 'Enfant' ),
            'ado/adulte'              => array( 'age' => 'Ado/adulte' ),
            'ado/adultes'             => array( 'age' => 'Ado/adulte' ),
            'adulte'                  => array( 'age' => 'Adulte' ),
            'tout age'                => array( 'age' => 'Tout âge' ),
            'tout âge'                => array( 'age' => 'Tout âge' ),
        );

        if ( isset( $map[ $f ] ) ) return $map[ $f ];
        if ( $f === '' ) return array();
        // Non reconnu (Général, Prépa CN, Sortie, "Autre…"...) → Discipline "Autre"
        return array( 'discipline' => 'Autre' );
    }

    /**
     * Aperçu de la bascule de l'ancien champ "categorie" (texte libre) vers les deux nouveaux
     * champs cours_discipline / cours_age_categories — même principe dry-run que
     * preview_normalisation_categorie_age(). Ne considère que les événements pas encore migrés
     * (cours_discipline vide) pour rester rejouable sans dupliquer un travail déjà fait à la main.
     */
    public function preview_migration_cours_categories() {
        global $wpdb;
        $te   = $this->table_events();
        $rows = $wpdb->get_results(
            "SELECT id, titre, categorie FROM $te
             WHERE categorie != '' AND ( cours_discipline IS NULL OR cours_discipline = '' )
             ORDER BY id ASC"
        );

        $apercu = array();
        foreach ( $rows as $r ) {
            $discipline = array();
            $age        = array();
            foreach ( explode( ',', $r->categorie ) as $fragment ) {
                $res = $this->migrer_fragment_categorie_evenement( $fragment );
                if ( ! empty( $res['discipline'] ) && ! in_array( $res['discipline'], $discipline, true ) ) $discipline[] = $res['discipline'];
                if ( ! empty( $res['age'] )        && ! in_array( $res['age'],        $age,        true ) ) $age[]        = $res['age'];
            }
            $apercu[] = array(
                'id'         => intval( $r->id ),
                'titre'      => $r->titre,
                'ancienne'   => $r->categorie,
                'discipline' => implode( ',', $discipline ),
                'age'        => implode( ',', $age ),
            );
        }
        return $apercu;
    }

    /**
     * Applique la bascule prévisualisée par preview_migration_cours_categories() — recalcule
     * l'aperçu au moment de l'application plutôt que de faire confiance à un aperçu obsolète.
     * "categorie" est réécrit à partir de la Discipline pour ne pas casser la couleur du
     * calendrier, le badge de l'agenda public et le shortcode [sp_cal_evenements categorie="…"].
     *
     * @return int Nombre d'événements mis à jour.
     */
    public function appliquer_migration_cours_categories() {
        global $wpdb;
        $te      = $this->table_events();
        $apercu  = $this->preview_migration_cours_categories();
        foreach ( $apercu as $ligne ) {
            $wpdb->update(
                $te,
                array(
                    'cours_discipline'     => $ligne['discipline'],
                    'cours_age_categories' => $ligne['age'],
                    'categorie'            => $ligne['discipline'],
                ),
                array( 'id' => $ligne['id'] )
            );
        }
        return count( $apercu );
    }

    public function get_saisons() {
        global $wpdb;
        return $wpdb->get_col( "SELECT DISTINCT saison FROM {$this->table_eleves()} WHERE saison != '' ORDER BY saison DESC" );
    }

    /* ══════════════════════════════════════════════════════════
       ADHÉSIONS
    ══════════════════════════════════════════════════════════ */

    /**
     * Statut adhésion d'un élève.
     * Retourne : 'actif' | 'inactif'
     */
    public function get_adhesion_status( $eleve ) {
        return intval( $eleve->actif ?? 1 ) ? 'actif' : 'inactif';
    }

    /**
     * Prochaine fin de saison (Y-m-d), aujourd'hui compris. Seuls le jour et le mois du
     * réglage sp_cal_fin_saison comptent : reconduit automatiquement chaque année, pour ne
     * plus afficher « Saison terminée » partout le 1er septembre faute d'avoir changé
     * l'année (retour de test du 28/09/2026). '' si non réglé.
     */
    public static function fin_saison_prochaine() {
        $opt = (string) get_option( 'sp_cal_fin_saison', '' );
        if ( ! preg_match( '/^\d{4}-(\d{2})-(\d{2})$/', $opt, $m ) ) return '';
        $mois = (int) $m[1]; $jour = (int) $m[2];
        $today = current_time( 'Y-m-d' );
        $annee = (int) substr( $today, 0, 4 );
        foreach ( array( $annee, $annee + 1 ) as $a ) {
            $j   = checkdate( $mois, $jour, $a ) ? $jour : 28;   // 29/02 les années non bissextiles
            $fin = sprintf( '%04d-%02d-%02d', $a, $mois, $j );
            if ( $fin >= $today ) return $fin;
        }
        return '';
    }

    /** Jours restants avant la prochaine fin de saison (0 le jour même), null si non réglée. */
    public static function jours_avant_fin_saison() {
        $fin = self::fin_saison_prochaine();
        if ( $fin === '' ) return null;
        return (int) round( ( strtotime( $fin ) - strtotime( current_time( 'Y-m-d' ) ) ) / 86400 );
    }

    /**
     * Saison sportive en cours, au format des fiches ("2026/2027") : celle qui se termine à
     * la prochaine fin de saison. Sans réglage : bascule au 1er septembre (même calcul de
     * secours que SP_Admin_Adhesions::create_member()).
     */
    public static function saison_en_cours() {
        $fin = self::fin_saison_prochaine();
        if ( $fin !== '' ) {
            $a = (int) substr( $fin, 0, 4 );
            return ( $a - 1 ) . '/' . $a;
        }
        $y = (int) current_time( 'Y' ); $m = (int) current_time( 'n' );
        return $m >= 9 ? $y . '/' . ( $y + 1 ) : ( $y - 1 ) . '/' . $y;
    }

    /**
     * Statut de saison d'un adhérent, d'après la saison de SA fiche comparée à la saison en cours.
     * Retourne ['code' => …, 'jours' => int|null, 'saison' => saison de la fiche, 'libelle' => texte court] :
     *  - 'inactif'      : fiche désactivée ;
     *  - 'a_renouveler' : fiche sur une saison passée (renouvellement non fait / non validé) ;
     *  - 'bientot'      : saison en cours, fin dans moins de sp_cal_alerte_jours jours ;
     *  - 'renouvele'    : fiche déjà sur la saison suivante (renouvellement validé en avance) ;
     *  - 'ok'           : à jour (ou saison de la fiche non renseignée / illisible).
     */
    public static function statut_saison_eleve( $el ) {
        $saison = trim( (string) ( $el->saison ?? '' ) );
        $jours  = self::jours_avant_fin_saison();
        $out    = array( 'code' => 'ok', 'jours' => $jours, 'saison' => $saison, 'libelle' => 'Actif' );
        if ( ! intval( $el->actif ?? 1 ) ) {
            return array_merge( $out, array( 'code' => 'inactif', 'libelle' => 'Inactif' ) );
        }
        $debut_fiche = preg_match( '/^(\d{4})\s*[\/-]/', $saison, $m ) ? (int) $m[1] : 0;
        $debut_cours = (int) substr( self::saison_en_cours(), 0, 4 );
        if ( $debut_fiche && $debut_fiche < $debut_cours ) {
            return array_merge( $out, array( 'code' => 'a_renouveler', 'libelle' => 'Saison ' . $saison . ' — renouvellement à faire' ) );
        }
        if ( $debut_fiche && $debut_fiche > $debut_cours ) {
            return array_merge( $out, array( 'code' => 'renouvele', 'libelle' => 'Actif — renouvelé pour ' . $saison ) );
        }
        if ( $jours !== null && $jours <= intval( get_option( 'sp_cal_alerte_jours', 60 ) ) ) {
            return array_merge( $out, array( 'code' => 'bientot', 'libelle' => 'Actif — fin de saison dans ' . $jours . ' j.' ) );
        }
        return $out;
    }

    /**
     * Comptes globaux pour le bandeau : nb inactifs, jours avant fin de saison.
     */
    public function get_adhesions_counts( $saison = '' ) {
        global $wpdb;
        $tel          = $this->table_eleves();
        $where_saison = $saison ? $wpdb->prepare( "AND saison = %s", $saison ) : '';
        $inactif  = intval( $wpdb->get_var(
            "SELECT COUNT(*) FROM $tel WHERE actif = 0 $where_saison"
        ) );
        $total    = intval( $wpdb->get_var(
            "SELECT COUNT(*) FROM $tel WHERE 1=1 $where_saison"
        ) );
        // Jours avant fin de saison (option globale)
        $fin_saison = self::fin_saison_prochaine();
        $jours_fin  = self::jours_avant_fin_saison();
        return compact( 'inactif', 'total', 'jours_fin', 'fin_saison' );
    }

    /**
     * Tous les élèves pour la page Adhésions.
     * $status_filter : '' | 'actif' | 'inactif'
     */
    public function get_adhesions_all( $saison = '', $status_filter = '' ) {
        global $wpdb;
        $tel   = $this->table_eleves();
        $where = array( '1=1' );
        if ( $saison )                      $where[] = $wpdb->prepare( "saison = %s", $saison );
        if ( $status_filter === 'actif' )   $where[] = 'actif = 1';
        if ( $status_filter === 'inactif' ) $where[] = 'actif = 0';
        return $wpdb->get_results(
            "SELECT * FROM $tel WHERE " . implode(' AND ', $where) .
            " ORDER BY actif ASC, rang ASC, categorie_age ASC, nom ASC"
        );
    }

    /**
     * Bascule les catégories d'âge au 1er septembre selon les règles fédérales.
     * Règles : âge révolu au 1er septembre de l'année en cours.
     *   < 6 ans  → Baby
     *   6-10 ans → Enfant
     *   11-14 ans → Ado/adulte
     *   >= 15 ans → Adulte
     *
     * @param bool $dry_run  Si true, retourne les changements sans les appliquer.
     * @return array  ['updated'=>int, 'details'=>[['nom','prenom','avant','apres'],...]]
     */
    public function bascule_categories_septembre( $dry_run = false ) {
        global $wpdb;
        $tel = $this->table_eleves();

        // Date de référence : 1er septembre de l'année en cours
        $annee_ref = intval( date('Y') );
        // Si on est avant le 1er septembre, la prochaine bascule est cette année
        // Si on est après le 1er septembre, la bascule de l'année est déjà passée
        $ts_ref = mktime( 0, 0, 0, 9, 1, $annee_ref );

        // Règles fédérales : catégorie selon âge révolu au 1er septembre
        $regles = array(
            array( 'min' => 0,  'max' => 5,  'cat' => 'Baby' ),
            array( 'min' => 6,  'max' => 10, 'cat' => 'Enfant' ),
            array( 'min' => 11, 'max' => 14, 'cat' => 'Ado/adulte' ),
            array( 'min' => 15, 'max' => 999,'cat' => 'Adulte' ),
        );

        // Récupérer tous les élèves actifs avec date de naissance — le Renforcement musculaire
        // (categorie_saisie='RENFO') n'est pas subdivisé par âge (catégorie "Tout âge" fixe,
        // cf. échange du 18/09/2026) : on l'exclut pour ne pas lui écraser sa catégorie chaque
        // rentrée avec une tranche d'âge qui ne le concerne pas.
        $eleves = $wpdb->get_results(
            "SELECT id, nom, prenom, date_naissance, annee_naissance, categorie_age
             FROM $tel
             WHERE actif = 1
               AND annee_naissance != ''
               AND date_naissance != ''
               AND categorie_saisie != 'RENFO'
             ORDER BY nom ASC, prenom ASC"
        );

        $updated = 0;
        $details = array();

        foreach ( $eleves as $el ) {
            // Calculer l'âge au 1er septembre
            $parts = explode( '/', $el->date_naissance );
            if ( count($parts) < 2 ) continue;
            $ts_naiss = mktime( 0, 0, 0, intval($parts[1]), intval($parts[0]), intval($el->annee_naissance) );
            if ( ! $ts_naiss ) continue;
            $age_sept = (int) floor( ($ts_ref - $ts_naiss) / (365.25 * 24 * 3600) );

            // Déterminer la nouvelle catégorie
            $new_cat = '';
            foreach ( $regles as $r ) {
                if ( $age_sept >= $r['min'] && $age_sept <= $r['max'] ) {
                    $new_cat = $r['cat'];
                    break;
                }
            }
            if ( ! $new_cat ) continue;
            // Pas de changement
            if ( $new_cat === $el->categorie_age ) continue;

            $details[] = array(
                'id'     => intval($el->id),
                'nom'    => $el->prenom . ' ' . mb_strtoupper($el->nom),
                'avant'  => $el->categorie_age,
                'apres'  => $new_cat,
                'age'    => $age_sept,
            );

            if ( ! $dry_run ) {
                $wpdb->update( $tel, array('categorie_age' => $new_cat), array('id' => intval($el->id)) );
                $updated++;
            }
        }

        return array(
            'updated' => $dry_run ? count($details) : $updated,
            'details' => $details,
        );
    }

    /* ══════════════════════════════════════════════════════════
       NORMALISATION ÉCRITURE CATÉGORIE D'ÂGE
    ══════════════════════════════════════════════════════════ */

    /**
     * Normalise l'écriture d'une valeur de catégorie d'âge vers l'une des 4 catégories
     * officielles (Baby / Enfant / Ado/adulte / Adulte — mêmes 4 que bascule_categories_septembre()).
     * Ne touche jamais à QUELLE catégorie un élève appartient, seulement à son écriture — ex.
     * "Babies", "baby", "BABY" deviennent tous "Baby" (cf. doléance 17/09/2026 : plusieurs
     * écritures différentes en base cassaient le ciblage des emails d'inscription événements,
     * qui compare des chaînes exactes).
     *
     * @param  string $valeur
     * @return string|null  Le libellé canonique si reconnu, null si aucun alias ne correspond
     *                       (valeur laissée intacte par l'appelant — nécessite une revue manuelle).
     */
    public function normaliser_categorie_age( $valeur ) {
        $v = trim( (string) $valeur );
        if ( $v === '' ) return $v;

        $v_norm = mb_strtolower( $v );
        $v_norm = str_replace( array( ' / ', ' /', '/ ', '-' ), '/', $v_norm );
        $v_norm = trim( $v_norm );

        $aliases = array(
            'baby'         => 'Baby',
            'babies'       => 'Baby',
            'bebe'         => 'Baby',
            'bebes'        => 'Baby',
            'bébé'         => 'Baby',
            'bébés'        => 'Baby',
            'enfant'       => 'Enfant',
            'enfants'      => 'Enfant',
            'ado/adulte'   => 'Ado/adulte',
            'ado/adultes'  => 'Ado/adulte',
            'ados/adulte'  => 'Ado/adulte',
            'ados/adultes' => 'Ado/adulte',
            'ado'          => 'Ado/adulte',
            'ados'         => 'Ado/adulte',
            'adolescent'   => 'Ado/adulte',
            'adolescents'  => 'Ado/adulte',
            'adulte'       => 'Adulte',
            'adultes'      => 'Adulte',
            // "Tout âge" : catégorie propre au Renforcement musculaire, qui n'est pas subdivisé
            // par âge contrairement au Taekwondo (Baby/Enfant/Ado-adulte/Adulte) — cf. échange
            // du 18/09/2026. "RENFO" trouvé dans categorie_age n'est pas une erreur de saisie,
            // c'est ce que ces élèves auraient dû avoir depuis le début.
            'renfo'        => 'Tout âge',
            'tout age'     => 'Tout âge',
            'tout âge'     => 'Tout âge',
            'tous age'     => 'Tout âge',
            'tous âge'     => 'Tout âge',
            'tous ages'    => 'Tout âge',
            'tous âges'    => 'Tout âge',
        );

        return $aliases[ $v_norm ] ?? null;
    }

    /**
     * Analyse toutes les valeurs de categorie_age actuellement en base (élèves) et propose une
     * normalisation, sans rien modifier. Toujours recalculé à la demande (pas de cache) pour
     * refléter l'état réel de la base au moment de l'aperçu.
     *
     * @return array{changements: array, non_reconnues: array}
     *   - changements : [ ['avant'=>string, 'apres'=>string, 'nb'=>int], ... ] — valeurs reconnues
     *     dont l'écriture diffère du libellé canonique.
     *   - non_reconnues : [ ['valeur'=>string, 'nb'=>int], ... ] — valeurs qu'aucun alias ne
     *     couvre, à corriger manuellement (fiche élève → dropdown Catégorie d'âge → "Autre").
     */
    public function preview_normalisation_categorie_age() {
        global $wpdb;
        $tel = $this->table_eleves();
        $rows = $wpdb->get_results(
            "SELECT categorie_age, COUNT(*) AS nb FROM $tel WHERE categorie_age != '' GROUP BY categorie_age ORDER BY categorie_age ASC"
        );

        $changements    = array();
        $non_reconnues  = array();
        foreach ( $rows as $r ) {
            $canonique = $this->normaliser_categorie_age( $r->categorie_age );
            if ( $canonique === null ) {
                $non_reconnues[] = array( 'valeur' => $r->categorie_age, 'nb' => intval( $r->nb ) );
                continue;
            }
            if ( $canonique === $r->categorie_age ) continue; // déjà correct
            $changements[] = array( 'avant' => $r->categorie_age, 'apres' => $canonique, 'nb' => intval( $r->nb ) );
        }

        return array( 'changements' => $changements, 'non_reconnues' => $non_reconnues );
    }

    /**
     * Applique la normalisation prévisualisée par preview_normalisation_categorie_age() —
     * recalcule l'aperçu au moment de l'application plutôt que de faire confiance à un aperçu
     * potentiellement obsolète (même principe que bascule_categories_septembre()).
     * Ne touche jamais aux valeurs non reconnues.
     *
     * @return int  Nombre total d'élèves mis à jour.
     */
    public function appliquer_normalisation_categorie_age() {
        global $wpdb;
        $tel     = $this->table_eleves();
        $preview = $this->preview_normalisation_categorie_age();
        $total   = 0;
        foreach ( $preview['changements'] as $c ) {
            $wpdb->update( $tel, array( 'categorie_age' => $c['apres'] ), array( 'categorie_age' => $c['avant'] ) );
            $total += $c['nb'];
        }
        return $total;
    }
}

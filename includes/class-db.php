<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'trait-db-membres.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-db-calendrier.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-db-stats.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-db-competitions.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-db-grades.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-db-inscriptions.php';
require_once plugin_dir_path( __FILE__ ) . 'trait-db-sondages.php';
if ( ! class_exists( 'SpCalPro_DB' ) ) :

class SpCalPro_DB {
    use SpCalPro_DB_Sondages; // trait-db-sondages.php

    use SpCalPro_DB_Inscriptions; // trait-db-inscriptions.php

    use SpCalPro_DB_Grades; // trait-db-grades.php

    use SpCalPro_DB_Competitions; // trait-db-competitions.php

    use SpCalPro_DB_Stats; // trait-db-stats.php

    use SpCalPro_DB_Calendrier; // trait-db-calendrier.php

    use SpCalPro_DB_Membres; // trait-db-membres.php


    const PREFIX  = 'sp_cal_';
    const VERSION = '10.17c';

    /** Référentiel TKD Parcours, lu une fois par requête (cf. get_grades_claira()). */
    private $grades_claira_cache = null;
    private $parcours_claira_cache = null;

    public function table( $name ) { global $wpdb; return $wpdb->prefix . self::PREFIX . $name; }
    public function table_trainer_dispos()         { return $this->table( 'trainer_dispos' ); }
    public function table_exam_epreuves()           { return $this->table( 'exam_epreuves' ); }
    public function table_exam_passages()            { return $this->table( 'exam_passages' ); }
    public function table_jury_notes()               { return $this->table( 'jury_notes' ); }
    public function table_exam_sessions()            { return $this->table( 'exam_sessions' ); }
    public function table_exam_tables()              { return $this->table( 'exam_tables' ); }
    public function table_exam_juges()               { return $this->table( 'exam_juges' ); }
    public function table_exam_grade_progression()   { return $this->table( 'exam_grade_progression' ); }

    /* ══════════════════════════════════════════════════════════
       NORMALISATION DES GRADES
       Convertit toute écriture vers notre format officiel
       en tenant compte de la categorie_age de l'élève.
    ══════════════════════════════════════════════════════════ */

    // Mapping grade_tiers (plugin tiers) -> grade_plugin (notre format) par categorie_age
    private static $grades_mapping = [
        [ 'tiers' => '15e keup', 'plugin' => '15e blanche',      'cat' => 'Baby' ],
        [ 'tiers' => '14e keup', 'plugin' => '14e blanche/jaune', 'cat' => 'Baby' ],
        [ 'tiers' => '13e keup', 'plugin' => '13e jaune',         'cat' => 'Baby' ],
        [ 'tiers' => '12e keup', 'plugin' => '12e jaune*',        'cat' => 'Baby' ],
        [ 'tiers' => '11e keup', 'plugin' => '11e orange',        'cat' => 'Baby' ],
        [ 'tiers' => '13e keup', 'plugin' => '13e blanche',       'cat' => 'Enfant' ],
        [ 'tiers' => '12e keup', 'plugin' => '12e jaune',         'cat' => 'Enfant' ],
        [ 'tiers' => '11e keup', 'plugin' => '11e jaune*',        'cat' => 'Enfant' ],
        [ 'tiers' => '10e keup', 'plugin' => '10e orange',        'cat' => 'Enfant' ],
        [ 'tiers' => '9e keup',  'plugin' => '9e orange*',        'cat' => 'Enfant' ],
        [ 'tiers' => '8e keup',  'plugin' => '8e violette',       'cat' => 'Enfant' ],
        [ 'tiers' => '7e keup',  'plugin' => '7e violette*',      'cat' => 'Enfant' ],
        [ 'tiers' => '6e keup',  'plugin' => '6e bleue',          'cat' => 'Enfant' ],
        [ 'tiers' => '5e keup',  'plugin' => '5e bleue*',         'cat' => 'Enfant' ],
        [ 'tiers' => '4e keup',  'plugin' => '4e bleue**',        'cat' => 'Enfant' ],
        [ 'tiers' => '3e keup',  'plugin' => '3e rouge',          'cat' => 'Enfant' ],
        [ 'tiers' => '2e keup',  'plugin' => '2e rouge*',         'cat' => 'Enfant' ],  // Enfant : 2e rouge*
        [ 'tiers' => '1e keup',  'plugin' => '1e rouge**',        'cat' => 'Enfant' ],  // Enfant : 1e rouge**
        [ 'tiers' => 'poom',     'plugin' => 'POOM',              'cat' => 'Enfant' ],
        [ 'tiers' => '10e keup', 'plugin' => '10e blanche',       'cat' => 'Ado/adulte' ],
        [ 'tiers' => '9e keup',  'plugin' => '9e jaune',          'cat' => 'Ado/adulte' ],
        [ 'tiers' => '8e keup',  'plugin' => '8e jaune*',         'cat' => 'Ado/adulte' ],
        [ 'tiers' => '7e keup',  'plugin' => '7e bleue',          'cat' => 'Ado/adulte' ],  // corrigé : bleue pas jaune**
        [ 'tiers' => '6e keup',  'plugin' => '6e bleue',          'cat' => 'Ado/adulte' ],
        [ 'tiers' => '5e keup',  'plugin' => '5e bleue*',         'cat' => 'Ado/adulte' ],
        [ 'tiers' => '4e keup',  'plugin' => '4e bleue**',        'cat' => 'Ado/adulte' ],
        [ 'tiers' => '3e keup',  'plugin' => '3e rouge*',         'cat' => 'Ado/adulte' ],
        [ 'tiers' => '2e keup',  'plugin' => '2e rouge**',        'cat' => 'Ado/adulte' ],  // corrigé : ** pas *
        [ 'tiers' => '1e keup',  'plugin' => '1e rouge***',       'cat' => 'Ado/adulte' ],  // corrigé : *** pas **
        [ 'tiers' => '1e dan',   'plugin' => '1e Dan',            'cat' => 'Ado/adulte' ],
    ];
    public function table_exam_affectations()         { return $this->table( 'exam_affectations' ); }
    public function table_exam_grade_contenu()         { return $this->table( 'exam_grade_contenu' ); }
    public function table_exam_zemita_seuils()          { return $this->table( 'exam_zemita_seuils' ); }
    public function table_exam_zemita_mapping()         { return $this->table( 'exam_zemita_mapping' ); }
    public function table_comp_epreuves()           { return $this->table( 'comp_epreuves' ); }
    public function table_comp_resultats()          { return $this->table( 'comp_resultats' ); }
    public function table_comp_participations()     { return $this->table( 'comp_participations' ); }
    public function table_trainers()           { return $this->table( 'trainers' ); }
    public function table_slots()              { return $this->table( 'slots' ); }
    public function table_events()             { return $this->table( 'events' ); }
    public function table_presences_trainers() { return $this->table( 'presences_trainers' ); }
    public function table_eleves()             { return $this->table( 'eleves' ); }
    public function table_famille_liens()       { return $this->table( 'famille_liens' ); }
    public function table_km_exceptionnels()     { return $this->table( 'km_exceptionnels' ); }
    public function table_presences_eleves()   { return $this->table( 'presences_eleves' ); }

    /* ── Création tables ─────────────────────────────────────── */

    public function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $tt=$this->table_trainers(); $tsl=$this->table_slots(); $te=$this->table_events();
        $tpt=$this->table_presences_trainers(); $tel=$this->table_eleves(); $tpe=$this->table_presences_eleves();
        $tdispos=$this->table_trainer_dispos();

        $sqls = array(
            "CREATE TABLE $tt (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                nom varchar(150) NOT NULL DEFAULT '',
                nom_public varchar(150) NOT NULL DEFAULT '',
                roles varchar(255) NOT NULL DEFAULT '',
                telephone varchar(30) NOT NULL DEFAULT '',
                email varchar(150) NOT NULL DEFAULT '',
                ordre int(11) NOT NULL DEFAULT 0,
                actif tinyint(1) NOT NULL DEFAULT 1,
                km_aller_retour decimal(6,1) NOT NULL DEFAULT 0.0,
                PRIMARY KEY (id)
            ) $charset;",

            "CREATE TABLE $tsl (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                jour tinyint(1) NOT NULL DEFAULT 1,
                label varchar(200) NOT NULL DEFAULT '',
                heure_debut varchar(10) NOT NULL DEFAULT '',
                heure_fin varchar(10) NOT NULL DEFAULT '',
                categorie varchar(100) NOT NULL DEFAULT '',
                ordre int(11) NOT NULL DEFAULT 0,
                recurrence varchar(20) NOT NULL DEFAULT 'weekly',
                date_debut date DEFAULT NULL,
                date_fin date DEFAULT NULL,
                PRIMARY KEY (id)
            ) $charset;",

            "CREATE TABLE $te (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                date date NOT NULL,
                heure_debut varchar(10) NOT NULL DEFAULT '',
                heure_fin varchar(10) NOT NULL DEFAULT '',
                titre varchar(200) NOT NULL DEFAULT '',
                categorie varchar(100) NOT NULL DEFAULT 'Général',
                couleur varchar(20) NOT NULL DEFAULT '#3B82F6',
                type varchar(20) NOT NULL DEFAULT 'evenement',
                description text DEFAULT NULL,
                slot_id mediumint(9) DEFAULT NULL,
                document_url varchar(500) DEFAULT NULL,
                document_nom varchar(200) DEFAULT NULL,
                PRIMARY KEY (id)
            ) $charset;",

            "CREATE TABLE $tpt (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                date date NOT NULL,
                trainer_id mediumint(9) NOT NULL,
                slot_id mediumint(9) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY trainer_slot_date (date, trainer_id, slot_id)
            ) $charset;",

            "CREATE TABLE $tel (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                nom varchar(150) NOT NULL DEFAULT '',
                prenom varchar(150) NOT NULL DEFAULT '',
                date_naissance varchar(10) NOT NULL DEFAULT '',
                annee_naissance varchar(4) NOT NULL DEFAULT '',
                grade varchar(100) NOT NULL DEFAULT '',
                categorie_age varchar(100) NOT NULL DEFAULT '',
                categorie_saisie varchar(100) NOT NULL DEFAULT '',
                saison varchar(20) NOT NULL DEFAULT '',
                rang int(11) NOT NULL DEFAULT 0,
                palmares text DEFAULT NULL,
                licence varchar(30) NOT NULL DEFAULT '',
                actif tinyint(1) NOT NULL DEFAULT 1,
                motif_inactif varchar(255) NOT NULL DEFAULT '',
                token varchar(64) DEFAULT NULL,
                token_sent_at datetime DEFAULT NULL,
                telephone varchar(30) NOT NULL DEFAULT '',
                email varchar(150) NOT NULL DEFAULT '',
                email_parent varchar(150) NOT NULL DEFAULT '',
                representant_nom varchar(150) NOT NULL DEFAULT '',
                representant_prenom varchar(150) NOT NULL DEFAULT '',
                representant_telephone varchar(30) NOT NULL DEFAULT '',
                urgence_nom varchar(150) NOT NULL DEFAULT '',
                urgence_prenom varchar(150) NOT NULL DEFAULT '',
                urgence_telephone varchar(30) NOT NULL DEFAULT '',
                urgence_email varchar(150) NOT NULL DEFAULT '',
                extra_data longtext DEFAULT NULL,
                lieu_naissance varchar(150) NOT NULL DEFAULT '',
                nationalite varchar(100) NOT NULL DEFAULT '',
                adresse varchar(255) NOT NULL DEFAULT '',
                taille_cm varchar(10) NOT NULL DEFAULT '',
                poids_kg varchar(10) NOT NULL DEFAULT '',
                pointure varchar(10) NOT NULL DEFAULT '',
                taille_tshirt varchar(20) NOT NULL DEFAULT '',
                taille_pantalon varchar(20) NOT NULL DEFAULT '',
                droit_image tinyint(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id)
            ) $charset;",

            "CREATE TABLE $tpe (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                event_id mediumint(9) NOT NULL,
                eleve_id mediumint(9) NOT NULL,
                present tinyint(1) NOT NULL DEFAULT 1,
                note varchar(100) DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY event_eleve (event_id, eleve_id)
            ) $charset;",

            "CREATE TABLE $tdispos (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                trainer_id mediumint(9) NOT NULL,
                date date NOT NULL,
                disponible tinyint(1) NOT NULL DEFAULT 1,
                note varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                UNIQUE KEY trainer_date (trainer_id, date)
            ) $charset;",

            "CREATE TABLE {$this->table_comp_epreuves()} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                event_id mediumint(9) NOT NULL,
                nom varchar(200) NOT NULL DEFAULT '',
                modalite varchar(100) NOT NULL DEFAULT '',
                ordre tinyint(3) NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                KEY event_id (event_id)
            ) $charset;",

            "CREATE TABLE {$this->table_comp_resultats()} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                epreuve_id mediumint(9) NOT NULL,
                eleve_id mediumint(9) NOT NULL,
                medaille varchar(10) NOT NULL DEFAULT '',
                score varchar(50) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                UNIQUE KEY epreuve_eleve (epreuve_id, eleve_id)
            ) $charset;",

            "CREATE TABLE {$this->table_comp_participations()} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                event_id mediumint(9) NOT NULL,
                eleve_id mediumint(9) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY event_eleve (event_id, eleve_id)
            ) $charset;",
        );

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( $sqls as $sql ) dbDelta( $sql );
        update_option( 'sp_cal_db_version', self::VERSION );
        $this->create_exam_tables();
    }

    private function create_exam_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$this->table_exam_epreuves()}'" ) ) {
            $wpdb->query( "CREATE TABLE {$this->table_exam_epreuves()} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                nom varchar(200) NOT NULL DEFAULT '',
                categorie_age varchar(150) NOT NULL DEFAULT '',
                description text DEFAULT NULL,
                ordre int(11) NOT NULL DEFAULT 0,
                actif tinyint(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                KEY categorie_age (categorie_age)
            ) $charset" );
        }
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$this->table_exam_passages()}'" ) ) {
            $wpdb->query( "CREATE TABLE {$this->table_exam_passages()} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                event_id mediumint(9) NOT NULL,
                eleve_id mediumint(9) NOT NULL,
                recu tinyint(1) DEFAULT NULL,
                nouveau_grade varchar(100) NOT NULL DEFAULT '',
                examinateur_id mediumint(9) DEFAULT NULL,
                created_at bigint(20) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY event_eleve (event_id, eleve_id),
                KEY event_id (event_id),
                KEY eleve_id (eleve_id)
            ) $charset" );
        }
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$this->table_jury_notes()}'" ) ) {
            $wpdb->query( "CREATE TABLE {$this->table_jury_notes()} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                event_id mediumint(9) NOT NULL,
                eleve_id mediumint(9) NOT NULL,
                epreuve_id mediumint(9) NOT NULL,
                table_id tinyint(3) NOT NULL DEFAULT 1,
                juge_id mediumint(9) DEFAULT NULL,
                juge_nom varchar(150) NOT NULL DEFAULT '',
                note decimal(4,2) DEFAULT NULL,
                reussi tinyint(1) DEFAULT NULL,
                created_at bigint(20) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY event_id (event_id),
                KEY eleve_id (eleve_id),
                KEY idx_note (event_id, eleve_id, epreuve_id, table_id)
            ) $charset" );
        }
    }

    /* ── Migration silencieuse (v10.0 → v10.1 → v10.2) ─────── */

    /**
     * Périodes de vacances (option sp_cal_vacances_zoneC), intitulés nettoyés des « \ » à la
     * lecture : un réenregistrement de Paramètres avec l'ancien code ne doit plus jamais se voir.
     * @return array [ [ 'label', 'start', 'end' ], … ]
     */
    public static function get_vacances() {
        $vac = json_decode( (string) get_option( 'sp_cal_vacances_zoneC', '[]' ), true );
        return is_array( $vac ) ? self::sans_antislash( $vac ) : array();
    }

    /** Retire les « \ » (un ou plusieurs) placés devant une apostrophe ou un guillemet. */
    public static function sans_antislash( $v ) {
        if ( is_array( $v ) ) return array_map( array( __CLASS__, 'sans_antislash' ), $v );
        return is_string( $v ) ? preg_replace( '/\\\\+(?=[\'"])/u', '', $v ) : $v;
    }

    /**
     * Réparation unique (05/10/2026) des textes enregistrés sans wp_unslash() : WordPress
     * ajoute un « \ » devant chaque apostrophe reçue d'un formulaire, et plusieurs saisies
     * (intitulés des vacances, notes de dispo, formulaire d'adhésion…) le gardaient — il se
     * multipliait à chaque réenregistrement (« Vacances d\\\\\\\'Hiver »). Les points
     * d'entrée sont corrigés ; ceci nettoie l'existant. Colonnes de texte libre uniquement ;
     * les colonnes JSON sont décodées, nettoyées puis réencodées (jamais modifiées à l'aveugle).
     */
    private function reparer_antislash() {
        global $wpdb;
        $texte = array(
            $this->table_eleves()                => array( 'nom', 'prenom', 'adresse', 'lieu_naissance', 'nationalite', 'palmares', 'motif_inactif', 'urgence_nom', 'urgence_prenom', 'licence', 'grade' ),
            $this->table_trainers()              => array( 'nom', 'nom_public', 'fonction', 'fonction_bureau' ),
            $this->table_trainer_dispos()        => array( 'note' ),
            $this->table_presences_eleves()      => array( 'note' ),
            $this->table_events()                => array( 'titre', 'description' ),
            $this->table_slots()                 => array( 'label' ),
            $wpdb->prefix . 'sp_adhesions_pending' => array( 'nom', 'prenom', 'lieu_naissance', 'nationalite', 'adresse', 'message', 'ancien_grade', 'refus_motif' ),
        );
        $json = array(
            $this->table_eleves()                  => array( 'extra_data' ),
            $wpdb->prefix . 'sp_adhesions_pending' => array( 'representants_legaux', 'contact_urgence' ),
        );
        foreach ( array( 'texte' => $texte, 'json' => $json ) as $genre => $tables ) {
            foreach ( $tables as $table => $cols ) {
                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) continue;
                foreach ( $cols as $col ) {
                    if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$table` LIKE '$col'" ) ) continue;
                    $rows = $wpdb->get_results( "SELECT id, `$col` AS v FROM `$table` WHERE INSTR(`$col`, CHAR(92)) > 0" );
                    foreach ( $rows as $r ) {
                        if ( $genre === 'texte' ) {
                            $propre = self::sans_antislash( (string) $r->v );
                        } else {
                            $d = json_decode( (string) $r->v, true );
                            if ( ! is_array( $d ) ) continue;
                            $p = self::sans_antislash( $d );
                            if ( $p === $d ) continue;
                            $propre = wp_json_encode( $p );
                        }
                        if ( $propre !== $r->v ) $wpdb->update( $table, array( $col => $propre ), array( 'id' => intval( $r->id ) ) );
                    }
                }
            }
        }
        // Intitulés des périodes de vacances (option JSON)
        $vac = json_decode( (string) get_option( 'sp_cal_vacances_zoneC', '[]' ), true );
        if ( is_array( $vac ) ) {
            $p = self::sans_antislash( $vac );
            if ( $p !== $vac ) update_option( 'sp_cal_vacances_zoneC', wp_json_encode( $p ) );
        }
    }

    public function maybe_upgrade() {
        global $wpdb;
        // v2 : la v1 a pu passer avant que tous les fichiers corrigés soient en ligne.
        if ( get_option( 'sp_cal_repar_antislash_v2' ) !== '1' ) {
            $this->reparer_antislash();
            update_option( 'sp_cal_repar_antislash_v2', '1', false );
        }
        $tsl = $this->table_slots();
        $te  = $this->table_events();
        $tel = $this->table_eleves();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tsl'" ) ) return;

        // v10.1 : créneaux récurrents
        foreach ( array(
            'recurrence' => "varchar(20) NOT NULL DEFAULT 'weekly'",
            'date_debut' => 'date DEFAULT NULL',
            'date_fin'   => 'date DEFAULT NULL',
        ) as $col => $def ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tsl` LIKE '$col'" ) )
                $wpdb->query( "ALTER TABLE `$tsl` ADD COLUMN `$col` $def" );
        }
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$te` LIKE 'slot_id'" ) )
            $wpdb->query( "ALTER TABLE `$te` ADD COLUMN `slot_id` mediumint(9) DEFAULT NULL" );

        // v10.2 : nouveaux champs élèves
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tel'" ) ) {
            foreach ( array(
                'categorie_saisie' => "varchar(100) NOT NULL DEFAULT ''",
                'saison'           => "varchar(20) NOT NULL DEFAULT ''",
                'rang'             => "int(11) NOT NULL DEFAULT 0",
                'palmares'         => "text DEFAULT NULL",
            ) as $col => $def ) {
                if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tel` LIKE '$col'" ) )
                    $wpdb->query( "ALTER TABLE `$tel` ADD COLUMN `$col` $def" );
            }
        }

        // v10.4 : colonne note sur presences_eleves (résultats d'examen)
        $tpe2 = $this->table_presences_eleves();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tpe2'" ) ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tpe2` LIKE 'note'" ) )
                $wpdb->query( "ALTER TABLE `$tpe2` ADD COLUMN `note` varchar(100) DEFAULT NULL" );
        }

        // v10.8 : statut adhésion (actif/inactif) — remplace licence_expiration par élève
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tel'" ) ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tel` LIKE 'actif'" ) )
                $wpdb->query( "ALTER TABLE `$tel` ADD COLUMN `actif` tinyint(1) NOT NULL DEFAULT 1" );
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tel` LIKE 'motif_inactif'" ) )
                $wpdb->query( "ALTER TABLE `$tel` ADD COLUMN `motif_inactif` varchar(255) NOT NULL DEFAULT ''" );
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tel` LIKE 'licence_expiration'" ) === false )
                if ( $wpdb->get_var( "SHOW COLUMNS FROM `$tel` LIKE 'licence_expiration'" ) )
                    $wpdb->query( "ALTER TABLE `$tel` DROP COLUMN `licence_expiration`" );
            // v10.10 : token accès fiche membre
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tel` LIKE 'token'" ) )
                $wpdb->query( "ALTER TABLE `$tel` ADD COLUMN `token` varchar(64) DEFAULT NULL" );
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tel` LIKE 'token_sent_at'" ) )
                $wpdb->query( "ALTER TABLE `$tel` ADD COLUMN `token_sent_at` datetime DEFAULT NULL" );
        }

        // v10.12 : disponibilités entraîneurs
        $tdispos = $this->table_trainer_dispos();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tdispos'" ) ) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query( "CREATE TABLE $tdispos (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                trainer_id mediumint(9) NOT NULL,
                date date NOT NULL,
                disponible tinyint(1) NOT NULL DEFAULT 1,
                note varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                UNIQUE KEY trainer_date (trainer_id, date)
            ) $charset" );
        }

        // v10.13 : compétitions — épreuves et résultats
        $charset = $wpdb->get_charset_collate();
        $tce = $this->table_comp_epreuves();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tce'" ) ) {
            $wpdb->query( "CREATE TABLE $tce (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                event_id mediumint(9) NOT NULL,
                nom varchar(200) NOT NULL DEFAULT '',
                ordre tinyint(3) NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                KEY event_id (event_id)
            ) $charset" );
        }
        $tcr = $this->table_comp_resultats();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tcr'" ) ) {
            $wpdb->query( "CREATE TABLE $tcr (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                epreuve_id mediumint(9) NOT NULL,
                eleve_id mediumint(9) NOT NULL,
                medaille varchar(10) NOT NULL DEFAULT '',
                score varchar(50) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                UNIQUE KEY epreuve_eleve (epreuve_id, eleve_id)
            ) $charset" );
        }

        // v10.14 : documents joints aux événements ponctuels
        $te = $this->table_events();
        foreach ( array(
            'document_url' => "varchar(500) DEFAULT NULL",
            'document_nom' => "varchar(200) DEFAULT NULL",
        ) as $col => $def ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$te` LIKE '$col'" ) )
                $wpdb->query( "ALTER TABLE `$te` ADD COLUMN `$col` $def" );
        }

        // v10.15 : km aller-retour par entraîneur (calcul récapitulatif mensuel)
        $tt = $this->table_trainers();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tt'" ) ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tt` LIKE 'km_aller_retour'" ) )
                $wpdb->query( "ALTER TABLE `$tt` ADD COLUMN `km_aller_retour` decimal(6,1) NOT NULL DEFAULT 0.0" );
            // v10.17c : photo de profil entraîneur / bureau
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tt` LIKE 'photo_url'" ) )
                $wpdb->query( "ALTER TABLE `$tt` ADD COLUMN `photo_url` varchar(500) NOT NULL DEFAULT ''" );
            // v10.17c : fonction/titre affiché frontend
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tt` LIKE 'fonction'" ) )
                $wpdb->query( "ALTER TABLE `$tt` ADD COLUMN `fonction` varchar(150) NOT NULL DEFAULT ''" );
            // Fonction bureau (séparée de la fonction sportive)
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tt` LIKE 'fonction_bureau'" ) )
                $wpdb->query( "ALTER TABLE `$tt` ADD COLUMN `fonction_bureau` varchar(150) NOT NULL DEFAULT ''" );
            // Fonctions séparées sportif / bureau
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tt` LIKE 'fonction_sport'" ) )
                $wpdb->query( "ALTER TABLE `$tt` ADD COLUMN `fonction_sport` varchar(150) NOT NULL DEFAULT ''" );
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tt` LIKE 'fonction_bureau'" ) )
                $wpdb->query( "ALTER TABLE `$tt` ADD COLUMN `fonction_bureau` varchar(150) NOT NULL DEFAULT ''" );
        }

        // v10.16 : modalité sur les épreuves de compétition
        $tce = $this->table_comp_epreuves();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tce'" ) ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tce` LIKE 'modalite'" ) )
                $wpdb->query( "ALTER TABLE `$tce` ADD COLUMN `modalite` varchar(100) NOT NULL DEFAULT '' AFTER nom" );
        }

        // v10.16.1 : participations aux compétitions (lien élève ↔ compétition)
        $tcp = $this->table_comp_participations();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tcp'" ) ) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query( "CREATE TABLE $tcp (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                event_id mediumint(9) NOT NULL,
                eleve_id mediumint(9) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY event_eleve (event_id, eleve_id)
            ) $charset" );
        }
        // Rétrocompat : importer les participations implicites depuis comp_resultats
        // (tout élève qui a déjà un résultat est automatiquement lié)
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tcp'" ) ) {
            $tce2 = $this->table_comp_epreuves();
            $tcr2 = $this->table_comp_resultats();
            $wpdb->query(
                "INSERT IGNORE INTO $tcp (event_id, eleve_id)
                 SELECT DISTINCT ep.event_id, r.eleve_id
                 FROM $tcr2 r
                 INNER JOIN $tce2 ep ON ep.id = r.epreuve_id"
            );
        }

        // v10.16.6 : contacts d'urgence + représentant légal
        $tel = $this->table_eleves();
        foreach ( array(
            'representant_nom'  => "varchar(150) NOT NULL DEFAULT ''",
            'urgence_nom'       => "varchar(150) NOT NULL DEFAULT ''",
            'urgence_telephone' => "varchar(30)  NOT NULL DEFAULT ''",
            'urgence_email'     => "varchar(150) NOT NULL DEFAULT ''",
        ) as $col => $def ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tel` LIKE '$col'" ) )
                $wpdb->query( "ALTER TABLE `$tel` ADD COLUMN `$col` $def" );
        }
        // v10.16.7 : tables exam
        $this->create_exam_tables();

        // v10.16.8 : champs RENFO + table famille_liens
        $tel = $this->table_eleves();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tel'" ) ) {
            foreach ( array(
                'lieu_naissance'  => "varchar(150) NOT NULL DEFAULT ''",
                'nationalite'     => "varchar(100) NOT NULL DEFAULT ''",
                'adresse'         => "varchar(255) NOT NULL DEFAULT ''",
                'taille_cm'       => "varchar(10)  NOT NULL DEFAULT ''",
                'poids_kg'        => "varchar(10)  NOT NULL DEFAULT ''",
                'pointure'        => "varchar(10)  NOT NULL DEFAULT ''",
                'taille_tshirt'   => "varchar(20)  NOT NULL DEFAULT ''",
                'taille_pantalon' => "varchar(20)  NOT NULL DEFAULT ''",
                'droit_image'     => "tinyint(1)   NOT NULL DEFAULT 1",
            ) as $col => $def ) {
                if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tel` LIKE '$col'" ) )
                    $wpdb->query( "ALTER TABLE `$tel` ADD COLUMN `$col` $def" );
            }
        }
        // Table km_exceptionnels
        $tkm = $this->table_km_exceptionnels();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tkm'" ) ) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query( "CREATE TABLE $tkm (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                trainer_id mediumint(9) NOT NULL,
                date date NOT NULL,
                description varchar(255) NOT NULL DEFAULT '',
                km decimal(7,1) NOT NULL DEFAULT 0.0,
                created_by mediumint(9) NOT NULL DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY trainer_id (trainer_id),
                KEY date (date)
            ) $charset" );
        }
        $tfl = $this->table_famille_liens();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tfl'" ) ) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query( "CREATE TABLE $tfl (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                eleve_id_1 mediumint(9) NOT NULL,
                eleve_id_2 mediumint(9) NOT NULL,
                type_lien varchar(50) NOT NULL DEFAULT 'famille',
                confirme tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY lien_unique (eleve_id_1, eleve_id_2)
            ) $charset" );
        }
        // v10.17 : remplaçant sur les dispos entraîneurs
        $tdispos = $this->table_trainer_dispos();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tdispos'" ) ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tdispos` LIKE 'remplacant_id'" ) )
                $wpdb->query( "ALTER TABLE `$tdispos` ADD COLUMN `remplacant_id` mediumint(9) DEFAULT NULL" );
            // 05/10/2026 : 1 ou 2 allers-retours dans la journée (deux interventions trop éloignées)
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tdispos` LIKE 'allers_retours'" ) )
                $wpdb->query( "ALTER TABLE `$tdispos` ADD COLUMN `allers_retours` tinyint(1) NOT NULL DEFAULT 1" );
        }

        // v10.17 : prénoms + téléphone contacts sur les élèves
        $tel = $this->table_eleves();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tel'" ) ) {
            foreach ( array(
                'representant_prenom'   => "varchar(150) NOT NULL DEFAULT ''",
                'representant_telephone'=> "varchar(30)  NOT NULL DEFAULT ''",
                'urgence_prenom'        => "varchar(150) NOT NULL DEFAULT ''",
                'num_passeport'         => "varchar(50)  NOT NULL DEFAULT ''",
            ) as $col => $def ) {
                if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tel` LIKE '$col'" ) )
                    $wpdb->query( "ALTER TABLE `$tel` ADD COLUMN `$col` $def" );
            }
        }

        // Migration categorie → categorie_age
        $texe = $this->table_exam_epreuves();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$texe'" ) ) {
            if ( $wpdb->get_var( "SHOW COLUMNS FROM `$texe` LIKE 'categorie'" ) &&
                 ! $wpdb->get_var( "SHOW COLUMNS FROM `$texe` LIKE 'categorie_age'" ) ) {
                $wpdb->query( "ALTER TABLE `$texe` CHANGE `categorie` `categorie_age` varchar(150) NOT NULL DEFAULT ''" );
            }
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$texe` LIKE 'actif'" ) )
                $wpdb->query( "ALTER TABLE `$texe` ADD COLUMN `actif` tinyint(1) NOT NULL DEFAULT 1" );
        }
        // v10.17c : jury d'examen — sessions, tables, juges, grade progression
        $this->create_jury_tables();

        // v10.18 : support de notation (numérique / papier) sur exam_sessions
        $ts18 = $this->table_exam_sessions();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$ts18'" ) ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$ts18` LIKE 'support_notation'" ) )
                $wpdb->query( "ALTER TABLE `$ts18` ADD COLUMN `support_notation` varchar(20) NOT NULL DEFAULT 'numerique' AFTER mode" );
        }

        // photo_url + nb_licences + eligible_dan sur eleves
        $tel3 = $this->table_eleves();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tel3'" ) ) {
            foreach ( array(
                'photo_url'    => "varchar(500) NOT NULL DEFAULT ''",
                'nb_licences'  => "tinyint UNSIGNED NOT NULL DEFAULT 0",
                'eligible_dan' => "tinyint(1) NOT NULL DEFAULT 0",
            ) as $col => $def ) {
                if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tel3` LIKE '$col'" ) )
                    $wpdb->query( "ALTER TABLE `$tel3` ADD COLUMN `$col` $def" );
            }
        }

        // v10.19 : élargir taille_tshirt / taille_pantalon (varchar(10) trop court —
        // une valeur comme "14 ans / xs" dépasse la limite et fait échouer l'INSERT
        // en mode strict MySQL lors de la création/mise à jour de la fiche élève)
        $tel4 = $this->table_eleves();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tel4'" ) ) {
            foreach ( array( 'taille_tshirt', 'taille_pantalon' ) as $col ) {
                $current = $wpdb->get_row( "SHOW COLUMNS FROM `$tel4` LIKE '$col'" );
                if ( $current && false !== strpos( $current->Type, 'varchar(10)' ) ) {
                    $wpdb->query( "ALTER TABLE `$tel4` MODIFY COLUMN `$col` varchar(20) NOT NULL DEFAULT ''" );
                }
            }
        }

        // 18/09/2026 : ciblage manuel d'élèves en plus des cases Discipline/Tranche d'âge sur
        // l'invitation événement — cas ponctuels non couverts par une catégorie (ex : "tous les
        // combattants, indépendamment de leur âge"). Liste d'IDs élèves séparés par virgule.
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$te` LIKE 'inscriptions_extra_eleves'" ) )
            $wpdb->query( "ALTER TABLE `$te` ADD COLUMN `inscriptions_extra_eleves` text DEFAULT NULL" );

        // 18/09/2026 : remplace l'ancien champ "categorie" fourre-tout (texte libre agrégé au fil du
        // temps : TKD, RENFO, Général, Prépa CN, Sortie, Renfo & Ados / Adultes...) par deux axes
        // propres — Discipline (Taekwondo/Renforcement musculaire/Autre) et Pour qui (tranche d'âge).
        // "categorie" reste renseigné automatiquement (= Discipline) pour ne pas casser la couleur du
        // calendrier, le badge de l'agenda public et le shortcode [sp_cal_evenements categorie="…"].
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$te` LIKE 'cours_discipline'" ) )
            $wpdb->query( "ALTER TABLE `$te` ADD COLUMN `cours_discipline` text DEFAULT NULL" );
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$te` LIKE 'cours_age_categories'" ) )
            $wpdb->query( "ALTER TABLE `$te` ADD COLUMN `cours_age_categories` text DEFAULT NULL" );

        // 05/10/2026 : mêmes deux axes pour les créneaux récurrents, dont la « Catégorie » était
        // restée un texte libre (« TKD, Boxe, Renfo… ») — le mail d'annulation ne trouvait
        // personne quand ce texte différait de la discipline des adhérents. "categorie" reste
        // renseigné automatiquement (= Discipline), comme pour les événements.
        $tsl_axes = $this->table_slots();
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tsl_axes` LIKE 'cours_discipline'" ) )
            $wpdb->query( "ALTER TABLE `$tsl_axes` ADD COLUMN `cours_discipline` text DEFAULT NULL" );
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tsl_axes` LIKE 'cours_age_categories'" ) )
            $wpdb->query( "ALTER TABLE `$tsl_axes` ADD COLUMN `cours_age_categories` text DEFAULT NULL" );
    }

    /* ══════════════════════════════════════════════════════════
       JURY — CREATE TABLES (v10.17c)
    ══════════════════════════════════════════════════════════ */

    public function create_jury_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $ts  = $this->table_exam_sessions();
        $tt  = $this->table_exam_tables();
        $tj  = $this->table_exam_juges();
        $tgp = $this->table_exam_grade_progression();

        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$ts'" ) ) {
$wpdb->query( "CREATE TABLE $ts (
    id          mediumint(9)    NOT NULL AUTO_INCREMENT,
    event_id    mediumint(9)    NOT NULL,
    mode        varchar(20)     NOT NULL DEFAULT 'par_epreuve',
    note_min    tinyint(3)      NOT NULL DEFAULT 0,
    note_max    tinyint(3)      NOT NULL DEFAULT 10,
    seuil_admission decimal(6,2) NOT NULL DEFAULT 0,
    zemita_mode tinyint(1)      NOT NULL DEFAULT 0,
    statut      varchar(20)     NOT NULL DEFAULT 'preparation',
    created_at  datetime        DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY event_id (event_id)
) $charset" );
        }

        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tt'" ) ) {
            $wpdb->query( "CREATE TABLE $tt (
                id          mediumint(9)    NOT NULL AUTO_INCREMENT,
                event_id    mediumint(9)    NOT NULL,
                numero      tinyint(3)      NOT NULL DEFAULT 1,
                epreuve_id  mediumint(9)    DEFAULT NULL,
                label       varchar(200)    NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                KEY event_id (event_id)
            ) $charset" );
        }

        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tj'" ) ) {
            $wpdb->query( "CREATE TABLE $tj (
                id           mediumint(9)   NOT NULL AUTO_INCREMENT,
                event_id     mediumint(9)   NOT NULL,
                table_id     mediumint(9)   NOT NULL,
                nom          varchar(150)   NOT NULL DEFAULT '',
                prenom       varchar(150)   NOT NULL DEFAULT '',
                trainer_id   mediumint(9)   DEFAULT NULL,
                token        varchar(64)    DEFAULT NULL,
                token_sent_at datetime      DEFAULT NULL,
                PRIMARY KEY (id),
                KEY event_id (event_id),
                KEY table_id (table_id),
                UNIQUE KEY token (token)
            ) $charset" );
        }

        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tgp'" ) ) {
            $wpdb->query( "CREATE TABLE $tgp (
                id            mediumint(9)  NOT NULL AUTO_INCREMENT,
                grade_actuel  varchar(100)  NOT NULL DEFAULT '',
                grade_suivant varchar(100)  NOT NULL DEFAULT '',
                categorie_age varchar(100)  NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                UNIQUE KEY cat_grade (categorie_age, grade_actuel)
            ) $charset" );
        } else {
            // Migration : ajouter categorie_age si elle n'existe pas encore
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tgp` LIKE 'categorie_age'" ) ) {
                $wpdb->query( "ALTER TABLE `$tgp` ADD COLUMN categorie_age varchar(100) NOT NULL DEFAULT ''" );
            }
            // Migration : remplacer la cle unique simple par la cle composite
            if ( $wpdb->get_var( "SHOW INDEX FROM `$tgp` WHERE Key_name='grade_actuel'" ) ) {
                $wpdb->query( "ALTER TABLE `$tgp` DROP INDEX grade_actuel" );
            }
            if ( ! $wpdb->get_var( "SHOW INDEX FROM `$tgp` WHERE Key_name='cat_grade'" ) ) {
                $wpdb->query( "ALTER TABLE `$tgp` ADD UNIQUE KEY cat_grade (categorie_age, grade_actuel)" );
            }
        }

        // Table exam_grade_contenu : ressources pédagogiques par grade
        $tgc = $this->table_exam_grade_contenu();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tgc'" ) ) {
            $wpdb->query( "CREATE TABLE $tgc (
                id           mediumint(9)  NOT NULL AUTO_INCREMENT,
                grade_actuel varchar(100)  NOT NULL DEFAULT '',
                description  text          DEFAULT NULL,
                liens        longtext      DEFAULT NULL,
                updated_at   datetime      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY grade_actuel (grade_actuel)
            ) $charset" );
        }

        // Table exam_affectations : affectation explicite candidat → aire
        $ta = $this->table_exam_affectations();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$ta'" ) ) {
            $wpdb->query( "CREATE TABLE $ta (
                id          mediumint(9)  NOT NULL AUTO_INCREMENT,
                event_id    mediumint(9)  NOT NULL,
                eleve_id    mediumint(9)  NOT NULL,
                aire_id     mediumint(9)  NOT NULL,
                ordre       smallint(5)   NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY event_eleve (event_id, eleve_id),
                KEY event_aire (event_id, aire_id)
            ) $charset" );
        }

        // Colonne table_id sur jury_notes si manquante (rétrocompat)
        $tn = $this->table_jury_notes();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tn'" ) ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$tn` LIKE 'exam_table_id'" ) )
                $wpdb->query( "ALTER TABLE `$tn` ADD COLUMN `exam_table_id` mediumint(9) DEFAULT NULL AFTER table_id" );
        }

        // ── ZEMITA : tables et migrations ─────────────────────────────────────

        // Mode ZEMITA sur exam_sessions
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$ts'" ) ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$ts` LIKE 'zemita_mode'" ) ) {
                $wpdb->query( "ALTER TABLE `$ts` ADD COLUMN `zemita_mode` TINYINT(1) NOT NULL DEFAULT 0" );
            }
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$ts` LIKE 'support_notation'" ) ) {
                $wpdb->query( "ALTER TABLE `$ts` ADD COLUMN `support_notation` VARCHAR(20) NOT NULL DEFAULT 'numerique'" );
            }
        }

        // Groupe ZEMITA sur exam_affectations
        $ta = $this->table_exam_affectations();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$ta'" ) ) {
            if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$ta` LIKE 'zemita_groupe'" ) ) {
                $wpdb->query( "ALTER TABLE `$ta` ADD COLUMN `zemita_groupe` VARCHAR(50) NOT NULL DEFAULT ''" );
            }
        }

        // Table grille seuils ZEMITA par examen (9 cases : 3 groupes × 3 catégories)
        $tzs = $this->table_exam_zemita_seuils();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tzs'" ) ) {
            $wpdb->query( "CREATE TABLE $tzs (
                id                 mediumint(9)   NOT NULL AUTO_INCREMENT,
                event_id           mediumint(9)   NOT NULL,
                groupe             varchar(50)    NOT NULL DEFAULT '',
                categorie_age      varchar(50)    NOT NULL DEFAULT '',
                seuil_moyen        decimal(6,2)   NOT NULL DEFAULT 0,
                seuil_performance  decimal(6,2)   NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY event_groupe_cat (event_id, groupe(30), categorie_age(30))
            ) $charset" );
        }

        // Table mapping global grade → groupe ZEMITA
        $tzm = $this->table_exam_zemita_mapping();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$tzm'" ) ) {
            $wpdb->query( "CREATE TABLE $tzm (
                id      mediumint(9)  NOT NULL AUTO_INCREMENT,
                grade   varchar(100)  NOT NULL DEFAULT '',
                groupe  varchar(50)   NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                UNIQUE KEY grade (grade(80))
            ) $charset" );
        }
    }


    /* ══════════════════════════════════════════════════════════
       INSCRIPTIONS AUX ÉVÉNEMENTS
    ══════════════════════════════════════════════════════════ */

    public function table_event_inscriptions() {
        return $this->table( 'event_inscriptions' );
    }

    /** Valeurs des deux axes d'un cours (mêmes listes que CAL.COURS_DISCIPLINES / CAL.COURS_AGES). */
    const COURS_DISCIPLINES = array( 'Taekwondo', 'Renforcement musculaire', 'Autre' );
    const COURS_AGES        = array( 'Baby', 'Enfant', 'Ado/adulte', 'Adulte', 'Tout âge' );
	
	// ============================================================
	// SONDAGES POST-ÉVÉNEMENT
	// ============================================================

	/**
	* Récupère ou crée le sondage d'un événement
	*/
	public function get_or_create_sondage( int $event_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sp_cal_sondages';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d", $event_id ),
			ARRAY_A
		);

		if ( $row ) {
			return $row;
		}

		$wpdb->insert( $table, [
			'event_id'       => $event_id,
			'actif'          => 1,
			'envoye'         => 0,
			'date_creation'  => current_time( 'mysql' ),
		], [ '%d', '%d', '%d', '%s' ] );

		return [
			'id'            => $wpdb->insert_id,
			'event_id'      => $event_id,
			'actif'         => 1,
			'envoye'        => 0,
			'date_envoi'    => null,
			'date_creation' => current_time( 'mysql' ),
		];
	}
}

endif; // class_exists SpCalPro_DB

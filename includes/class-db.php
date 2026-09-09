<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_DB' ) ) :

class SpCalPro_DB {

    const PREFIX  = 'sp_cal_';
    const VERSION = '10.17c';

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

    /**
     * Normalise un grade saisi vers notre format officiel.
     *
     * Étapes :
     * 1. Nettoyage basique (espaces, casse partielle)
     * 2. Lookup dans le mapping tiers -> plugin (avec categorie_age si fournie)
     * 3. Corrections orthographiques connues (°->e, étoiles, etc.)
     *
     * @param  string $grade         Grade saisi (n'importe quelle écriture)
     * @param  string $categorie_age Baby | Enfant | Ado/adulte (optionnel, améliore la précision)
     * @return string                Grade normalisé
     */
    public function normaliser_grade( $grade, $categorie_age = '' ) {
        if ( empty( trim($grade) ) ) return $grade;

        $g = trim( $grade );

        // Étape 0 : nettoyer les caractères Unicode produits par Excel
        // ᵉ (U+1D49) exposant → e ASCII normal  ex: 7ᵉ keup → 7e keup
        $g = str_replace( "\xE1\xB5\x89", 'e', $g );  // UTF-8 de U+1D49
        // Autres variantes d'exposants numériques courants
        $g = str_replace( ['ᵒ','ª','º'], 'e', $g );

        // Étape 1 : lookup mapping tiers -> plugin
        $g_lower = mb_strtolower( $g );
        foreach ( self::$grades_mapping as $row ) {
            if ( mb_strtolower($row['tiers']) === $g_lower ) {
                // Si categorie fournie, vérifier correspondance
                if ( ! $categorie_age || $row['cat'] === $categorie_age ) {
                    return $row['plugin'];
                }
            }
        }

        // Étape 2 : corrections orthographiques
        // ° -> e collé au chiffre (ex: 14° -> 14e, 1° -> 1e)
        $g = preg_replace('/(\d)°/', '$1e', $g);
        // ème/eme -> e (ex: 14ème -> 14e)
        $g = preg_replace('/(\d)è?me?/', '$1e', $g);
        // Normaliser les étoiles : espaces autour -> collées (ex: "orange * *" -> "orange**", "orange *" -> "orange*")
        $g = preg_replace('/\s*\*\s*\*\s*/', '**', $g);
        $g = preg_replace('/\s*\*\s*/', '*', $g);
        // S'assurer qu'il y a un espace avant l'étoile si elle suit une lettre
        $g = preg_replace('/([a-zA-Z])(\*+)/', '$1 $2', $g);
        // Remettre les étoiles collées après normalisation
        $g = str_replace(' *', '*', $g);
        $g = str_replace(' **', '**', $g);
        // Espace entre rang+e et couleur (ex: "14eblanche" -> "14e blanche")
        $g = preg_replace('/(\d+e[r]?)([\p{L}])/u', '$1 $2', $g);
        // POOM / poom -> POOM
        if ( mb_strtolower($g) === 'poom' ) return 'POOM';
        // Dan -> normaliser casse
        $g = preg_replace('/(\d+e)\s+dan/i', '$1 Dan', $g);

        return $g;
    }
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
                taille_tshirt varchar(10) NOT NULL DEFAULT '',
                taille_pantalon varchar(10) NOT NULL DEFAULT '',
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

    public function maybe_upgrade() {
        global $wpdb;
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
                'taille_tshirt'   => "varchar(10)  NOT NULL DEFAULT ''",
                'taille_pantalon' => "varchar(10)  NOT NULL DEFAULT ''",
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
    }

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

    /**
     * Compte les interventions réelles par entraîneur sur un mois donné.
     *
     * Une intervention est comptabilisée si :
     *   1. L'entraîneur a disponible=1 dans trainer_dispos pour cette date.
     *   2. Il existe au moins un créneau récurrent non annulé OU un événement ponctuel
     *      ce même jour (la date était bien un jour d'activité du club).
     *
     * @return array  [ trainer_id (int) => nb_interventions (int) ]
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

        $rows = $wpdb->get_results(
            "SELECT trainer_id, COUNT(*) AS nb
             FROM $tdispos
             WHERE date IN ($dates_safe)
               AND disponible = 1
             GROUP BY trainer_id"
        );

        $result = array();
        foreach ( $rows as $r ) {
            $result[ intval( $r->trainer_id ) ] = intval( $r->nb );
        }
        return $result;
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
            'recurrence'  => $slot->recurrence ?: 'weekly',
            'annul_id'    => $annul_id,
            'mat_id'      => $mat_id,  // ID de la matérialisation en DB si elle existe
        );
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

    public function get_categories_saisie() {
        global $wpdb;
        return $wpdb->get_col( "SELECT DISTINCT categorie_saisie FROM {$this->table_eleves()} WHERE categorie_saisie != '' ORDER BY categorie_saisie ASC" );
    }

    public function get_saisons() {
        global $wpdb;
        return $wpdb->get_col( "SELECT DISTINCT saison FROM {$this->table_eleves()} WHERE saison != '' ORDER BY saison DESC" );
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

    /**
     * Examens passés par un élève (events de type 'examen' avec grade obtenu en note).
     */
    public function get_examens_eleve( $eleve_id ) {
        global $wpdb;
        $tpe = $this->table_presences_eleves();
        $te  = $this->table_events();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id AS event_id, e.date, e.titre, e.categorie, pe.present, pe.note
             FROM $tpe pe
             INNER JOIN $te e ON e.id = pe.event_id
             WHERE pe.eleve_id = %d AND e.type = 'examen'
             ORDER BY e.date ASC",
            intval( $eleve_id )
        ) );
    }

    /**
     * Tous les événements de type examen (pour le sélecteur "lier à un examen").
     * Retourne les examens auxquels l'élève n'est PAS encore lié.
     */
    public function get_examens_disponibles( $eleve_id ) {
        global $wpdb;
        $tpe = $this->table_presences_eleves();
        $te  = $this->table_events();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.date, e.titre, e.categorie
             FROM $te e
             WHERE e.type = 'examen'
               AND e.id NOT IN (
                   SELECT event_id FROM $tpe WHERE eleve_id = %d
               )
             ORDER BY e.date DESC",
            intval( $eleve_id )
        ) );
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
        $fin_saison = get_option( 'sp_cal_fin_saison', '' );
        $jours_fin  = null;
        if ( $fin_saison ) {
            $jours_fin = intval( ceil( ( strtotime($fin_saison) - time() ) / 86400 ) );
        }
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
     * Taux de présence par catégorie d'âge, filtrable par saison.
     * Retourne : categorie_age, nb_eleves, nb_presents, nb_absents, nb_cours
     */
    public function get_stats_par_groupe( $saison = '' ) {
        global $wpdb;
        $tel = $this->table_eleves();
        $tpe = $this->table_presences_eleves();
        $te  = $this->table_events();
        $where_saison = $saison ? $wpdb->prepare( "AND el.saison = %s", $saison ) : '';
        return $wpdb->get_results(
            "SELECT
                el.categorie_age,
                COUNT(DISTINCT el.id)                                           AS nb_eleves,
                SUM(CASE WHEN pe.present=1 THEN 1 ELSE 0 END)                  AS nb_presents,
                SUM(CASE WHEN pe.present=0 THEN 1 ELSE 0 END)                  AS nb_absents,
                COUNT(DISTINCT e.id)                                            AS nb_cours
             FROM $tel el
             LEFT JOIN $tpe pe ON pe.eleve_id = el.id
             LEFT JOIN $te  e  ON e.id = pe.event_id AND e.type NOT IN ('examen','anniversaire')
             WHERE el.categorie_age != '' $where_saison
             GROUP BY el.categorie_age
             ORDER BY MIN(el.rang) ASC, el.categorie_age ASC"
        );
    }

    /**
     * Taux de présence par catégorie saisie (TKD, RENFO…).
     */
    public function get_stats_par_saisie( $saison = '' ) {
        global $wpdb;
        $tel = $this->table_eleves();
        $tpe = $this->table_presences_eleves();
        $te  = $this->table_events();
        $where_saison = $saison ? $wpdb->prepare( "AND el.saison = %s", $saison ) : '';
        return $wpdb->get_results(
            "SELECT
                el.categorie_saisie,
                COUNT(DISTINCT el.id)                                           AS nb_eleves,
                SUM(CASE WHEN pe.present=1 THEN 1 ELSE 0 END)                  AS nb_presents,
                SUM(CASE WHEN pe.present=0 THEN 1 ELSE 0 END)                  AS nb_absents
             FROM $tel el
             LEFT JOIN $tpe pe ON pe.eleve_id = el.id
             LEFT JOIN $te  e  ON e.id = pe.event_id AND e.type NOT IN ('examen','anniversaire')
             WHERE el.categorie_saisie != '' $where_saison
             GROUP BY el.categorie_saisie
             ORDER BY el.categorie_saisie ASC"
        );
    }

    /**
     * Évolution mensuelle du nombre de présences (tous élèves).
     */
    public function get_stats_evolution_mensuelle( $saison = '' ) {
        global $wpdb;
        $tel = $this->table_eleves();
        $tpe = $this->table_presences_eleves();
        $te  = $this->table_events();
        $where_saison = $saison ? $wpdb->prepare( "AND el.saison = %s", $saison ) : '';
        return $wpdb->get_results(
            "SELECT
                DATE_FORMAT(e.date, '%Y-%m')        AS mois,
                COUNT(DISTINCT e.id)                AS nb_cours,
                SUM(CASE WHEN pe.present=1 THEN 1 ELSE 0 END) AS nb_presents,
                COUNT(pe.id)                        AS nb_total
             FROM $te e
             INNER JOIN $tpe pe ON pe.event_id = e.id
             INNER JOIN $tel el ON el.id = pe.eleve_id
             WHERE e.type NOT IN ('examen','anniversaire') $where_saison
             GROUP BY DATE_FORMAT(e.date, '%Y-%m')
             ORDER BY mois ASC"
        );
    }

    /**
     * Classement individuel des élèves par taux de présence.
     * $order : 'desc' (meilleurs) ou 'asc' (moins assidus)
     */
    public function get_stats_classement( $saison = '', $limit = 20, $order = 'desc' ) {
        global $wpdb;
        $tel = $this->table_eleves();
        $tpe = $this->table_presences_eleves();
        $te  = $this->table_events();
        $where_saison = $saison ? $wpdb->prepare( "AND el.saison = %s", $saison ) : '';
        $dir = $order === 'asc' ? 'ASC' : 'DESC';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                el.id, el.nom, el.prenom, el.categorie_age, el.categorie_saisie, el.grade,
                COUNT(pe.id)                                                        AS nb_total,
                SUM(CASE WHEN pe.present=1 THEN 1 ELSE 0 END)                      AS nb_presents,
                ROUND(SUM(CASE WHEN pe.present=1 THEN 1 ELSE 0 END)*100/COUNT(pe.id)) AS taux
             FROM $tel el
             INNER JOIN $tpe pe ON pe.eleve_id = el.id
             INNER JOIN $te  e  ON e.id = pe.event_id AND e.type NOT IN ('examen','anniversaire')
             WHERE 1=1 $where_saison
             GROUP BY el.id
             HAVING nb_total >= 1
             ORDER BY taux $dir, nb_presents $dir
             LIMIT %d",
            intval($limit)
        ) );
    }

    /* ══════════════════════════════════════════════════════════
       DISPONIBILITÉS ENTRAÎNEURS
    ══════════════════════════════════════════════════════════ */

    /**
     * Récupère toutes les dispos des entraîneurs pour un mois donné.
     * Retourne un tableau indexé par "YYYY-MM-DD" => [ trainer_id => ['disponible'=>0/1,'note'=>''] ]
     */
    public function get_dispos_for_month( $year, $month ) {
        global $wpdb;
        $tdispos = $this->table_trainer_dispos();
        $start   = sprintf( '%04d-%02d-01', $year, $month );
        $end     = date( 'Y-m-t', strtotime( $start ) );
        $rows    = $wpdb->get_results( $wpdb->prepare(
            "SELECT trainer_id, date, disponible, note, remplacant_id FROM $tdispos WHERE date BETWEEN %s AND %s",
            $start, $end
        ) );
        $map = array();
        foreach ( $rows as $r ) {
            if ( ! isset( $map[ $r->date ] ) ) $map[ $r->date ] = array();
            $map[ $r->date ][ intval( $r->trainer_id ) ] = array(
                'disponible'    => intval( $r->disponible ),
                'note'          => $r->note,
                'remplacant_id' => $r->remplacant_id ? intval( $r->remplacant_id ) : null,
            );
        }
        return $map;
    }

    /**
     * Récupère les dispos d'une date précise pour tous les entraîneurs.
     * Retourne [ trainer_id => ['disponible'=>0/1,'note'=>''] ]
     */
    public function get_dispos_for_date( $date_str ) {
        global $wpdb;
        $tdispos = $this->table_trainer_dispos();
        $rows    = $wpdb->get_results( $wpdb->prepare(
            "SELECT trainer_id, disponible, note, remplacant_id FROM $tdispos WHERE date = %s", $date_str
        ) );
        $map = array();
        foreach ( $rows as $r ) {
            $map[ intval( $r->trainer_id ) ] = array(
                'disponible'    => intval( $r->disponible ),
                'note'          => $r->note,
                'remplacant_id' => $r->remplacant_id ? intval( $r->remplacant_id ) : null,
            );
        }
        return $map;
    }

    /**
     * Sauvegarde (upsert) la dispo d'un entraîneur pour une date.
     * $disponible : 1 = dispo, 0 = indisponible, null = supprimer l'entrée
     */
    public function save_dispo( $trainer_id, $date_str, $disponible, $note = '', $remplacant_id = null ) {
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

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $tdispos WHERE trainer_id=%d AND date=%s", $trainer_id, $date_str
        ) );
        if ( $exists ) {
            $wpdb->update( $tdispos,
                array( 'disponible' => intval($disponible), 'note' => $note, 'remplacant_id' => $remplacant_id ),
                array( 'id' => intval($exists) )
            );
        } else {
            $wpdb->insert( $tdispos, array(
                'trainer_id'    => $trainer_id,
                'date'          => $date_str,
                'disponible'    => intval($disponible),
                'note'          => $note,
                'remplacant_id' => $remplacant_id,
            ) );
        }
    }

    /* ══════════════════════════════════════════════════════════
       COMPÉTITIONS — ÉPREUVES & RÉSULTATS
    ══════════════════════════════════════════════════════════ */

    /** Retourne les épreuves d'une compétition (event). */
    public function get_comp_epreuves( $event_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_comp_epreuves()} WHERE event_id=%d ORDER BY ordre ASC",
            intval($event_id)
        ) );
    }

    /**
     * Sauvegarde les épreuves d'une compétition.
     * $epreuves = array of ['id'=>0, 'nom'=>'...', 'modalite'=>'...', 'ordre'=>1]
     * Les épreuves avec id=0 sont créées ; les existantes mises à jour.
     * Les épreuves supprimées côté client (non présentes) sont effacées.
     */
    public function save_comp_epreuves( $event_id, $epreuves ) {
        global $wpdb;
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $event_id = intval($event_id);
        $keep_ids = array();

        foreach ( $epreuves as $ep ) {
            $id       = intval($ep['id'] ?? 0);
            $nom      = sanitize_text_field($ep['nom']      ?? '');
            $modalite = sanitize_text_field($ep['modalite'] ?? '');
            $ordre    = intval($ep['ordre'] ?? 1);
            if ( ! $nom ) continue;
            if ( $id ) {
                $wpdb->update( $tce, compact('nom','modalite','ordre'), array('id'=>$id,'event_id'=>$event_id) );
                $keep_ids[] = $id;
            } else {
                $wpdb->insert( $tce, array('event_id'=>$event_id,'nom'=>$nom,'modalite'=>$modalite,'ordre'=>$ordre) );
                $keep_ids[] = $wpdb->insert_id;
            }
        }

        // Supprimer les épreuves retirées (et leurs résultats)
        $existing = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $tce WHERE event_id=%d", $event_id
        ) );
        foreach ( $existing as $eid ) {
            if ( ! in_array( intval($eid), $keep_ids ) ) {
                $wpdb->delete( $tcr, array('epreuve_id'=>intval($eid)) );
                $wpdb->delete( $tce, array('id'=>intval($eid)) );
            }
        }
        return $keep_ids;
    }

    /**
     * Résultats d'une compétition complète.
     * Retourne array indexé epreuve_id => [ eleve_id => {medaille, score} ]
     */
    public function get_comp_resultats_by_event( $event_id ) {
        global $wpdb;
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.epreuve_id, r.eleve_id, r.medaille, r.score
             FROM $tcr r
             INNER JOIN $tce e ON e.id = r.epreuve_id
             WHERE e.event_id = %d",
            intval($event_id)
        ) );
        $map = array();
        foreach ( $rows as $r ) {
            $map[ intval($r->epreuve_id) ][ intval($r->eleve_id) ] = array(
                'medaille' => $r->medaille,
                'score'    => $r->score,
            );
        }
        return $map;
    }

    /**
     * Sauvegarde les résultats.
     * $resultats = array of [ 'epreuve_id'=>int, 'eleve_id'=>int, 'medaille'=>'or|argent|bronze|', 'score'=>'...' ]
     */
    public function save_comp_resultats( $resultats ) {
        global $wpdb;
        $tcr = $this->table_comp_resultats();
        foreach ( $resultats as $r ) {
            $eid = intval($r['epreuve_id'] ?? 0);
            $lid = intval($r['eleve_id']   ?? 0);
            if ( ! $eid || ! $lid ) continue;
            $data = array(
                'medaille' => in_array($r['medaille']??'', array('or','argent','bronze')) ? $r['medaille'] : '',
                'score'    => sanitize_text_field($r['score'] ?? ''),
            );
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $tcr WHERE epreuve_id=%d AND eleve_id=%d", $eid, $lid
            ) );
            if ( $exists ) {
                $wpdb->update( $tcr, $data, array('id'=>intval($exists)) );
            } else {
                $wpdb->insert( $tcr, array_merge($data, array('epreuve_id'=>$eid,'eleve_id'=>$lid)) );
            }
        }
    }

    /**
     * Retourne toutes les compétitions (événements type='competition'), triées date DESC.
     * Optionnel : filtrer par saison (année de la date, ex. '2024-2025').
     */
public function get_all_competitions( $annee = '' ) {
    global $wpdb;
    $te    = $this->table_events();
    $where = "WHERE type = 'competition'";
    $p     = array();
    if ( $annee ) {
        $where .= ' AND YEAR(date) = %d';
        $p[]    = intval($annee);
    }
    $sql = "SELECT * FROM $te $where ORDER BY
        CASE niveau
            WHEN 'international' THEN 1
            WHEN 'national'      THEN 2
            WHEN 'regional'      THEN 3
            ELSE 4
        END ASC,
        date DESC";
    return $p ? $wpdb->get_results( $wpdb->prepare($sql, $p) ) : $wpdb->get_results($sql);
}

    /**
     * Années distinctes des compétitions (pour le sélecteur de filtre).
     */
    public function get_competitions_annees() {
        global $wpdb;
        $te = $this->table_events();
        return $wpdb->get_col(
            "SELECT DISTINCT YEAR(date) AS annee FROM $te WHERE type='competition' ORDER BY annee DESC"
        );
    }

    /**
     * Palmarès global : tous les résultats de compétition, toutes les médailles,
     * jointé avec élèves et compétitions.
     * Si $event_id fourni, filtre sur cette compétition.
     * Si $eleve_id fourni, filtre sur cet élève.
     * Retourne rows : eleve_id, nom, prenom, categorie_age, grade,
     *                 event_id, comp_titre, date,
     *                 epreuve_id, epreuve_nom, medaille, score
     */
    public function get_palmares_global( $event_id = 0, $eleve_id = 0 ) {
        global $wpdb;
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $te  = $this->table_events();
        $tel = $this->table_eleves();

        $where = array( "e.type = 'competition'" );
        $p     = array();
        if ( $event_id ) { $where[] = 'e.id = %d';       $p[] = intval($event_id); }
        if ( $eleve_id ) { $where[] = 'r.eleve_id = %d'; $p[] = intval($eleve_id); }
        $sql_where = implode( ' AND ', $where );

        $sql = "SELECT r.eleve_id, el.nom, el.prenom, el.categorie_age, el.grade,
                       el.droit_image,
                       e.id AS event_id, e.titre AS comp_titre, e.date,
                       ep.id AS epreuve_id, ep.nom AS epreuve_nom, ep.ordre AS epreuve_ordre,
                       r.medaille, r.score
                FROM $tcr r
                INNER JOIN $tce ep ON ep.id = r.epreuve_id
                INNER JOIN $te  e  ON e.id  = ep.event_id
                INNER JOIN $tel el ON el.id = r.eleve_id
                WHERE $sql_where
                ORDER BY e.date DESC, ep.ordre ASC, el.categorie_age ASC, el.nom ASC";

        return $p ? $wpdb->get_results( $wpdb->prepare($sql, $p) ) : $wpdb->get_results($sql);
    }

    /**
     * Résumé des médailles par élève (pour la vue "par élève").
     * Retourne : eleve_id, nom, prenom, categorie_age, grade, nb_or, nb_argent, nb_bronze, nb_total_comp
     */
    public function get_palmares_resume_eleves() {
        global $wpdb;
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $te  = $this->table_events();
        $tel = $this->table_eleves();

        return $wpdb->get_results(
            "SELECT r.eleve_id, el.nom, el.prenom, el.categorie_age, el.grade,
                    el.droit_image,
                    SUM(CASE WHEN r.medaille='or'     THEN 1 ELSE 0 END) AS nb_or,
                    SUM(CASE WHEN r.medaille='argent' THEN 1 ELSE 0 END) AS nb_argent,
                    SUM(CASE WHEN r.medaille='bronze' THEN 1 ELSE 0 END) AS nb_bronze,
                    COUNT(DISTINCT ep.event_id)                           AS nb_total_comp
             FROM $tcr r
             INNER JOIN $tce ep ON ep.id = r.epreuve_id
             INNER JOIN $te  e  ON e.id  = ep.event_id AND e.type = 'competition'
             INNER JOIN $tel el ON el.id = r.eleve_id
             WHERE r.medaille != ''
             GROUP BY r.eleve_id
             ORDER BY nb_or DESC, nb_argent DESC, nb_bronze DESC, el.nom ASC"
        );
    }

    /**
     * Tous les palmarès d'un élève pour la fiche personnelle.
     * Source : participations explicites (table comp_participations).
     * Retourne une ligne par épreuve — LEFT JOIN résultats (peut être vide).
     */
    public function get_palmares_eleve( $eleve_id ) {
        global $wpdb;
        $tcp = $this->table_comp_participations();
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $te  = $this->table_events();
        $eleve_id = intval($eleve_id);
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                COALESCE(r.medaille,'') AS medaille,
                COALESCE(r.score,'')   AS score,
                ep.nom      AS epreuve_nom,
                ep.modalite AS epreuve_modalite,
                ep.ordre    AS epreuve_ordre,
                e.id        AS event_id,
                e.date,
                e.titre     AS comp_titre,
                e.categorie
             FROM $te e
             INNER JOIN $tcp p  ON p.event_id = e.id AND p.eleve_id = %d
             INNER JOIN $tce ep ON ep.event_id = e.id
             LEFT  JOIN $tcr r  ON r.epreuve_id = ep.id AND r.eleve_id = %d
             WHERE e.type = 'competition'
             ORDER BY e.date DESC, ep.ordre ASC",
            $eleve_id, $eleve_id
        ) );
    }

    /* ══════════════════════════════════════════════════════════
       PARTICIPATIONS AUX COMPÉTITIONS (lien élève ↔ compétition)
    ══════════════════════════════════════════════════════════ */

    /**
     * Toutes les compétitions auxquelles un élève est lié (participation explicite
     * OU résultats existants — les deux sources sont fusionnées).
     * Retourne des rows event : id, titre, date, categorie
     */
    public function get_comps_eleve( $eleve_id ) {
        global $wpdb;
        $tcp = $this->table_comp_participations();
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $te  = $this->table_events();
        $eleve_id = intval($eleve_id);
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT e.id, e.titre, e.date, e.categorie
             FROM $te e
             WHERE e.type = 'competition'
               AND (
                   EXISTS (
                       SELECT 1 FROM $tcp p WHERE p.event_id = e.id AND p.eleve_id = %d
                   )
                   OR EXISTS (
                       SELECT 1 FROM $tcr r
                       INNER JOIN $tce ep ON ep.id = r.epreuve_id AND ep.event_id = e.id
                       WHERE r.eleve_id = %d
                   )
               )
             ORDER BY e.date DESC",
            $eleve_id, $eleve_id
        ) );
    }

    /**
     * Compétitions disponibles pour un élève (pas encore liées).
     * Retourne : id, titre, date, categorie
     */
    public function get_comps_disponibles( $eleve_id ) {
        global $wpdb;
        $tcp = $this->table_comp_participations();
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $te  = $this->table_events();
        $eleve_id = intval($eleve_id);
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.titre, e.date, e.categorie
             FROM $te e
             WHERE e.type = 'competition'
               AND e.id NOT IN (
                   SELECT p.event_id FROM $tcp p WHERE p.eleve_id = %d
               )
               AND e.id NOT IN (
                   SELECT ep.event_id FROM $tcr r
                   INNER JOIN $tce ep ON ep.id = r.epreuve_id
                   WHERE r.eleve_id = %d
               )
             ORDER BY e.date DESC",
            $eleve_id, $eleve_id
        ) );
    }

    /**
     * Lie un élève à une compétition (upsert).
     */
    public function link_comp( $event_id, $eleve_id ) {
        global $wpdb;
        $tcp = $this->table_comp_participations();
        $event_id = intval($event_id);
        $eleve_id = intval($eleve_id);
        if ( ! $event_id || ! $eleve_id ) return false;
        // Vérifier que l'event est bien une compétition
        $te = $this->table_events();
        $ok = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $te WHERE id=%d AND type='competition'", $event_id
        ) );
        if ( ! $ok ) return false;
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO $tcp (event_id, eleve_id) VALUES (%d, %d)",
            $event_id, $eleve_id
        ) );
        return true;
    }

    /**
     * Délie un élève d'une compétition.
     * Supprime aussi ses résultats pour cette compétition.
     */
    public function unlink_comp( $event_id, $eleve_id ) {
        global $wpdb;
        $tcp = $this->table_comp_participations();
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $event_id = intval($event_id);
        $eleve_id = intval($eleve_id);
        if ( ! $event_id || ! $eleve_id ) return;
        // Supprimer la participation
        $wpdb->delete( $tcp, array( 'event_id' => $event_id, 'eleve_id' => $eleve_id ) );
        // Supprimer les résultats liés
        $ep_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $tce WHERE event_id=%d", $event_id
        ) );
        if ( $ep_ids ) {
            $in = implode( ',', array_map( 'intval', $ep_ids ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM $tcr WHERE epreuve_id IN ($in) AND eleve_id=%d",
                $eleve_id
            ) );
        }
    }

    /* ══════════════════════════════════════════════════════════
       CONVENTION MÉDAILLE → POINTS
    ══════════════════════════════════════════════════════════ */

    /**
     * Retourne la valeur en points de chaque type de médaille.
     * Configurable via les options WordPress (page Paramètres).
     * Défauts : Or=3, Argent=2, Bronze=1.
     */
    public function get_medal_points() {
        return array(
            'or'     => intval( get_option( 'sp_cal_pts_or',     3 ) ),
            'argent' => intval( get_option( 'sp_cal_pts_argent', 2 ) ),
            'bronze' => intval( get_option( 'sp_cal_pts_bronze', 1 ) ),
        );
    }

    /**
     * Calcule le total de points palmarès d'un élève.
     * Utilisé dans la fiche élève et le récapitulatif des grades.
     *
     * @param int $eleve_id
     * @return array  [ 'total_pts' => int, 'nb_or' => int, 'nb_argent' => int, 'nb_bronze' => int ]
     */
    public function get_palmares_points_eleve( $eleve_id ) {
        global $wpdb;
        $tce = $this->table_comp_epreuves();
        $tcr = $this->table_comp_resultats();
        $te  = $this->table_events();
        $pts = $this->get_medal_points();

        $counts = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN r.medaille='or'     THEN 1 ELSE 0 END) AS nb_or,
                SUM(CASE WHEN r.medaille='argent' THEN 1 ELSE 0 END) AS nb_argent,
                SUM(CASE WHEN r.medaille='bronze' THEN 1 ELSE 0 END) AS nb_bronze
             FROM $tcr r
             INNER JOIN $tce ep ON ep.id = r.epreuve_id
             INNER JOIN $te  e  ON e.id  = ep.event_id AND e.type = 'competition'
             WHERE r.eleve_id = %d AND r.medaille != ''",
            intval($eleve_id)
        ) );

        $nb_or     = intval( $counts->nb_or     ?? 0 );
        $nb_argent = intval( $counts->nb_argent ?? 0 );
        $nb_bronze = intval( $counts->nb_bronze ?? 0 );
        $total_pts = $nb_or * $pts['or'] + $nb_argent * $pts['argent'] + $nb_bronze * $pts['bronze'];

        return compact( 'total_pts', 'nb_or', 'nb_argent', 'nb_bronze' );
    }

public function get_exam_epreuves( $categorie_age = null, $actif_only = true ) {
    global $wpdb;
    $t     = $this->table_exam_epreuves();
    $where = $actif_only ? 'actif = 1' : '1=1';
    $rows  = $wpdb->get_results( "SELECT * FROM $t WHERE $where ORDER BY ordre ASC, nom ASC" );

    if ( ! $categorie_age ) return $rows;

    // Filtrer côté PHP : épreuve applicable si sa liste JSON contient la catégorie
    return array_values( array_filter( $rows, function( $ep ) use ( $categorie_age ) {
        return $this->epreuve_applicable( $ep, $categorie_age );
    } ) );
}

/**
 * Vérifie si une épreuve s'applique à une catégorie d'âge donnée.
 * categorie_age peut être :
 *   - JSON array  : ["Baby","Enfant"]
 *   - texte libre : "Tout", "Baby, Enfant" (rétrocompat)
 *   - vide        : applicable à tous
 */
public function epreuve_applicable( $ep, $categorie_age ) {
    $val = trim( $ep->categorie_age ?? '' );
    if ( empty( $val ) ) return true;

    // Format JSON
    $decoded = json_decode( $val, true );
    if ( is_array( $decoded ) ) {
        foreach ( $decoded as $cat ) {
            if ( strcasecmp( trim( $cat ), $categorie_age ) === 0 ) return true;
        }
        return false;
    }

    // Rétrocompat texte libre : "Tout" ou contient la catégorie
    if ( strtolower( $val ) === 'tout' ) return true;
    return stripos( $val, $categorie_age ) !== false;
}

    public function save_exam_epreuve( array $data, $id = 0 ) {
        global $wpdb;
        $t = $this->table_exam_epreuves();
        $row = array(
            'nom'           => sanitize_text_field( $data['nom'] ?? '' ),
            'categorie_age' => sanitize_text_field( $data['categorie_age'] ?? '' ),
            'description'   => sanitize_textarea_field( $data['description'] ?? '' ),
            'ordre'         => intval( $data['ordre'] ?? 0 ),
            'actif'         => intval( $data['actif'] ?? 1 ),
        );
        $id = intval( $id );
        if ( $id ) { $wpdb->update( $t, $row, array( 'id' => $id ) ); return $id; }
        $wpdb->insert( $t, $row );
        return intval( $wpdb->insert_id );
    }

    public function delete_exam_epreuve( $id ) {
        global $wpdb;
        $wpdb->delete( $this->table_exam_epreuves(), array( 'id' => intval( $id ) ) );
    }

    public function get_exam_categories() {
        global $wpdb;
        $t = $this->table_exam_epreuves();
        return $wpdb->get_col( "SELECT DISTINCT categorie_age FROM $t WHERE categorie_age != '' ORDER BY categorie_age ASC" );
    }

    public function save_exam_passage( $event_id, $eleve_id, $recu, $nouveau_grade, $examinateur_id = null ) {
        global $wpdb;
        $t = $this->table_exam_passages();
        $event_id = intval( $event_id );
        $eleve_id = intval( $eleve_id );
        $row = array(
            'recu'           => $recu === null ? null : intval( $recu ),
            'nouveau_grade'  => sanitize_text_field( $nouveau_grade ),
            'examinateur_id' => $examinateur_id ? intval( $examinateur_id ) : null,
        );
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE event_id=%d AND eleve_id=%d", $event_id, $eleve_id ) );
        if ( $existing ) {
            $wpdb->update( $t, $row, array( 'event_id' => $event_id, 'eleve_id' => $eleve_id ) );
        } else {
            $wpdb->insert( $t, array_merge( $row, array( 'event_id' => $event_id, 'eleve_id' => $eleve_id, 'created_at' => time() ) ) );
        }
        if ( intval( $recu ) && $nouveau_grade ) {
            $wpdb->update( $this->table_eleves(), array( 'grade' => sanitize_text_field( $nouveau_grade ) ), array( 'id' => $eleve_id ) );
        }
    }

    public function get_exam_passages_event( $event_id ) {
        global $wpdb;
        $t = $this->table_exam_passages();
        $tel = $this->table_eleves();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, el.nom, el.prenom, el.categorie_age, el.grade FROM $t p INNER JOIN $tel el ON el.id=p.eleve_id WHERE p.event_id=%d ORDER BY el.categorie_age ASC, el.nom ASC",
            intval( $event_id )
        ) );
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
       JURY — SESSIONS
    ══════════════════════════════════════════════════════════ */

    public function get_jury_session( $event_id ) {
        global $wpdb;
        $t = $this->table_exam_sessions();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE event_id=%d", intval($event_id) ) );
    }

    public function save_jury_session( $event_id, $data ) {
        global $wpdb;
        $t  = $this->table_exam_sessions();
        $ev = intval($event_id);
        $row = array(
            'mode'             => in_array( $data['mode'] ?? '', ['par_categorie','par_epreuve'] ) ? $data['mode'] : 'par_epreuve',
            'support_notation' => in_array( $data['support_notation'] ?? '', ['numerique','papier'] ) ? $data['support_notation'] : 'numerique',
            'note_min'         => intval( $data['note_min'] ?? 0 ),
            'note_max'         => max( 1, intval( $data['note_max'] ?? 10 ) ),
            'seuil_admission'  => floatval( $data['seuil_admission'] ?? 0 ),
			'zemita_mode'      => intval( $data['zemita_mode'] ?? 0 ) ? 1 : 0,
            'statut'           => in_array( $data['statut'] ?? '', ['preparation','en_cours','termine'] ) ? $data['statut'] : 'preparation',
        );
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE event_id=%d", $ev ) );
        if ( $existing ) {
            $wpdb->update( $t, $row, array( 'event_id' => $ev ) );
        } else {
            $wpdb->insert( $t, array_merge( $row, array( 'event_id' => $ev ) ) );
        }
    }

    /* ══════════════════════════════════════════════════════════
       JURY — TABLES
    ══════════════════════════════════════════════════════════ */

    public function get_jury_tables( $event_id ) {
        global $wpdb;
        $t = $this->table_exam_tables();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE event_id=%d ORDER BY numero ASC",
            intval($event_id)
        ) );
    }

public function save_jury_tables( $event_id, $tables ) {
    global $wpdb;
    $t  = $this->table_exam_tables();
    $ev = intval($event_id);

    // Récupérer les aires existantes indexées par numero
    $existing = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $t WHERE event_id=%d", $ev
    ) );
    $existing_by_num = array();
    foreach ( $existing as $ex ) {
        $existing_by_num[ intval($ex->numero) ] = $ex;
    }

    $saved_nums = array();
    foreach ( $tables as $tbl ) {
        $num = intval( $tbl['numero'] ?? 1 );
        $saved_nums[] = $num;
        $row = array(
            'event_id'        => $ev,
            'numero'          => $num,
            'epreuve_id'      => isset($tbl['epreuve_id']) && $tbl['epreuve_id'] ? intval($tbl['epreuve_id']) : null,
            'label'           => sanitize_text_field( $tbl['label'] ?? '' ),
            'note_min'        => intval( $tbl['note_min'] ?? 0 ),
            'note_max'        => max( 1, intval( $tbl['note_max'] ?? 10 ) ),
            'type_verdict'    => in_array( $tbl['type_verdict'] ?? '', ['seuil_fixe','zemita'] ) ? $tbl['type_verdict'] : 'seuil_fixe',
            'seuil_admission' => floatval( $tbl['seuil_admission'] ?? 0 ),
        );
        if ( isset( $existing_by_num[$num] ) ) {
            // UPDATE — préserve l'ID donc les juges restent liés
            $wpdb->update( $t, $row, array( 'id' => intval($existing_by_num[$num]->id) ) );
        } else {
            // INSERT nouvelle aire
            $wpdb->insert( $t, $row );
        }
    }

    // Supprimer les aires retirées
    foreach ( $existing_by_num as $num => $ex ) {
        if ( ! in_array( $num, $saved_nums ) ) {
            $wpdb->delete( $t, array( 'id' => intval($ex->id) ) );
        }
    }
}

    /* ══════════════════════════════════════════════════════════
       JURY — JUGES
    ══════════════════════════════════════════════════════════ */

    public function get_jury_juges( $event_id, $table_id = null ) {
        global $wpdb;
        $t = $this->table_exam_juges();
        if ( $table_id ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $t WHERE event_id=%d AND table_id=%d ORDER BY nom ASC",
                intval($event_id), intval($table_id)
            ) );
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE event_id=%d ORDER BY table_id ASC, nom ASC",
            intval($event_id)
        ) );
    }

    public function get_juge_by_token( $token ) {
        global $wpdb;
        $t = $this->table_exam_juges();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE token=%s", sanitize_text_field($token) ) );
    }

    public function save_jury_juges( $event_id, $juges ) {
        global $wpdb;
        $t  = $this->table_exam_juges();
        $ev = intval($event_id);
        // Keep existing tokens — only delete juges not in the new list
        $existing = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM $t WHERE event_id=%d", $ev ) );
        $keep_ids = array();
        foreach ( $juges as $j ) {
            if ( ! empty($j['id']) ) $keep_ids[] = intval($j['id']);
        }
        foreach ( $existing as $ex ) {
            if ( ! in_array( intval($ex->id), $keep_ids ) ) {
                $wpdb->delete( $t, array( 'id' => intval($ex->id) ) );
            }
        }
        $saved_ids = array();
        foreach ( $juges as $j ) {
            $row = array(
                'event_id'   => $ev,
                'table_id'   => intval( $j['table_id'] ?? 0 ),
                'nom'        => sanitize_text_field( $j['nom'] ?? '' ),
                'prenom'     => sanitize_text_field( $j['prenom'] ?? '' ),
                'trainer_id' => isset($j['trainer_id']) && $j['trainer_id'] ? intval($j['trainer_id']) : null,
            );
            if ( ! empty($j['id']) ) {
                $wpdb->update( $t, $row, array( 'id' => intval($j['id']) ) );
                $saved_ids[] = intval($j['id']);
            } else {
                $wpdb->insert( $t, $row );
                $saved_ids[] = $wpdb->insert_id;
            }
        }
        return $saved_ids;
    }

    public function generate_juge_token( $juge_id ) {
        global $wpdb;
        $t     = $this->table_exam_juges();
        $token = bin2hex( random_bytes(32) );
        $wpdb->update( $t, array(
            'token'         => $token,
            'token_sent_at' => current_time('mysql'),
        ), array( 'id' => intval($juge_id) ) );
        return $token;
    }

    /* ══════════════════════════════════════════════════════════
       JURY — NOTES
    ══════════════════════════════════════════════════════════ */

    /**
     * Sauvegarde ou met à jour une note brute.
     * Borne la note entre note_min et note_max de la session.
     */
    public function save_jury_note( $event_id, $eleve_id, $epreuve_id, $exam_table_id, $juge_id, $juge_nom, $note, $session ) {
        global $wpdb;
        $t   = $this->table_jury_notes();
        $ev  = intval($event_id);
        $eli = intval($eleve_id);
        $epi = intval($epreuve_id);
        $tid = intval($exam_table_id);
        $jid = intval($juge_id);



// La note est déjà bornée par l'appelant, on ne recape pas ici
$note = floatval($note);
        $note     = max( $note_min, min( $note_max, floatval($note) ) );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $t WHERE event_id=%d AND eleve_id=%d AND epreuve_id=%d AND exam_table_id=%d AND juge_id=%d",
            $ev, $eli, $epi, $tid, $jid
        ) );

        $row = array(
            'note'       => $note,
            'juge_nom'   => sanitize_text_field($juge_nom),
            'created_at' => time(),
        );

        if ( $existing ) {
            $wpdb->update( $t, $row, array( 'id' => intval($existing) ) );
        } else {
            $wpdb->insert( $t, array_merge( $row, array(
                'event_id'     => $ev,
                'eleve_id'     => $eli,
                'epreuve_id'   => $epi,
                'exam_table_id'=> $tid,
                'table_id'     => $tid,
                'juge_id'      => $jid,
            ) ) );
        }
    }

/**
 * Sauvegarde une note saisie manuellement par l'admin (saisie papier).
 * Upsert sur (event_id, eleve_id, epreuve_id, exam_table_id, juge_id).
 */
public function save_note_admin( $event_id, $eleve_id, $epreuve_id, $aire_id, $juge_id, $note_finale, $coups_val = null ) {
    global $wpdb;
    $t = $this->table_jury_notes();

    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $t 
         WHERE event_id=%d AND eleve_id=%d AND epreuve_id=%d AND exam_table_id=%d AND juge_id=%d",
        intval($event_id), intval($eleve_id), intval($epreuve_id), intval($aire_id), intval($juge_id)
    ) );

    $row = array(
        'note'       => floatval($note_finale),
        'coups_val'  => $coups_val !== null ? intval($coups_val) : null,
        'juge_nom'   => '__admin__',
        'created_at' => time(),
    );

    if ( $existing ) {
        return $wpdb->update( $t, $row, array( 'id' => intval($existing) ) ) !== false;
    }

    return $wpdb->insert( $t, array_merge( $row, array(
        'event_id'      => intval($event_id),
        'eleve_id'      => intval($eleve_id),
        'epreuve_id'    => intval($epreuve_id),
        'exam_table_id' => intval($aire_id),
        'table_id'      => intval($aire_id),
        'juge_id'       => intval($juge_id),
    ) ) ) !== false;
}

    /**
     * Notes d'un événement pour une table donnée, groupées par élève.
     */
    public function get_jury_notes_table( $event_id, $exam_table_id ) {
        global $wpdb;
        $t   = $this->table_jury_notes();
        $tel = $this->table_eleves();
        $tep = $this->table_exam_epreuves();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT n.*, el.nom, el.prenom, el.categorie_age, el.grade,
                    ep.nom AS epreuve_nom
             FROM $t n
             INNER JOIN $tel el ON el.id = n.eleve_id
             LEFT  JOIN $tep ep ON ep.id = n.epreuve_id
             WHERE n.event_id=%d AND n.exam_table_id=%d
             ORDER BY el.categorie_age ASC, el.nom ASC, n.epreuve_id ASC",
            intval($event_id), intval($exam_table_id)
        ) );
    }

    /**
     * Toutes les notes d'un événement (interface centrale).
     */
    public function get_jury_notes_event( $event_id ) {
        global $wpdb;
        $t   = $this->table_jury_notes();
        $tel = $this->table_eleves();
        $tep = $this->table_exam_epreuves();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT n.*, el.nom, el.prenom, el.categorie_age, el.grade,
                    ep.nom AS epreuve_nom
             FROM $t n
             INNER JOIN $tel el ON el.id = n.eleve_id
             LEFT  JOIN $tep ep ON ep.id = n.epreuve_id
             WHERE n.event_id=%d
             ORDER BY el.categorie_age ASC, el.nom ASC, n.epreuve_id ASC, n.exam_table_id ASC",
            intval($event_id)
        ) );
    }

    /* ══════════════════════════════════════════════════════════
       JURY — CANDIDATS & AFFECTATIONS
    ══════════════════════════════════════════════════════════ */

    /**
     * Retourne les élèves inscrits à cet événement (présences_eleves) avec
     * leur affectation de table courante depuis jury_notes (last table_id vu).
     */
    public function get_jury_candidats( $event_id ) {
        global $wpdb;
        $tpe = $this->table_presences_eleves();
        $tel = $this->table_eleves();
        $tn  = $this->table_jury_notes();
        $ev  = intval($event_id);
        // LEFT JOIN sur presences_eleves : on affiche TOUS les élèves actifs du club.
        // La colonne "present" vaut NULL si aucune présence saisie pour cet examen.
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT el.id, el.nom, el.prenom, el.categorie_age, el.grade,
                    COALESCE(pe.present, 1) AS present,
                    (SELECT exam_table_id FROM $tn
                     WHERE event_id=%d AND eleve_id=el.id
                     ORDER BY id DESC LIMIT 1) AS table_id_courant
             FROM $tel el
             LEFT JOIN $tpe pe ON pe.eleve_id = el.id AND pe.event_id=%d
             WHERE el.actif = 1
             ORDER BY el.categorie_age ASC, el.nom ASC, el.prenom ASC",
            $ev, $ev
        ) );
    }

    /**
     * Réaffecte un candidat à une nouvelle table :
     * met à jour toutes ses notes courantes de l'event vers la nouvelle table.
     * Si aucune note encore → rien à faire (la table sera connue à la 1ère note).
     */
    public function reassign_candidat_table( $event_id, $eleve_id, $new_table_id ) {
        global $wpdb;
        $tn = $this->table_jury_notes();
        $wpdb->update(
            $tn,
            array( 'exam_table_id' => intval($new_table_id), 'table_id' => intval($new_table_id) ),
            array( 'event_id' => intval($event_id), 'eleve_id' => intval($eleve_id) )
        );
    }

    /* ══════════════════════════════════════════════════════════
       JURY — GRADE PROGRESSION
    ══════════════════════════════════════════════════════════ */

    public function get_grade_progression_all() {
        global $wpdb;
        $t = $this->table_exam_grade_progression();
        return $wpdb->get_results( "SELECT * FROM $t ORDER BY grade_actuel ASC" );
    }

    public function get_grade_suivant( $grade_actuel, $categorie_age = '' ) {
        global $wpdb;
        $t = $this->table_exam_grade_progression();

        // Chercher d'abord dans la categorie specifique
        if ( $categorie_age ) {
            $result = $wpdb->get_var( $wpdb->prepare(
                "SELECT grade_suivant FROM $t WHERE grade_actuel=%s AND categorie_age=%s LIMIT 1",
                $grade_actuel, $categorie_age
            ) );
            if ( $result ) return $result;
        }

        // Fallback : lignes sans categorie
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT grade_suivant FROM $t WHERE grade_actuel=%s AND categorie_age='' LIMIT 1",
            $grade_actuel
        ) );
    }

    public function save_grade_progression( $rows ) {
        global $wpdb;
        $t = $this->table_exam_grade_progression();
        $wpdb->query( "TRUNCATE TABLE $t" );
        foreach ( $rows as $r ) {
            $ga  = $this->normaliser_grade( sanitize_text_field( $r['grade_actuel']  ?? '' ) );
            $gs  = $this->normaliser_grade( sanitize_text_field( $r['grade_suivant'] ?? '' ) );
            $cat = sanitize_text_field( $r['categorie_age'] ?? '' );
            if ( ! $ga || ! $gs ) continue;
            $wpdb->replace( $t, array(
                'grade_actuel'  => $ga,
                'grade_suivant' => $gs,
                'categorie_age' => $cat,
            ) );
        }
    }

    /**
     * Calcule le grade vise pour un eleve selon exam_grade_progression.
     */
    public function get_grade_vise_eleve( $eleve ) {
        $cat = $eleve->categorie_age ?? '';
        return $this->get_grade_suivant( $eleve->grade ?? '', $cat ) ?: '';
    }

    /* ══════════════════════════════════════════════════════════
       JURY — AFFECTATIONS CANDIDATS (Passe 1.5)
    ══════════════════════════════════════════════════════════ */

    /**
     * Retourne toutes les affectations d'un event, jointure élève.
     */
    public function get_affectations( $event_id ) {
        global $wpdb;
        $ta  = $this->table_exam_affectations();
        $tel = $this->table_eleves();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, el.nom, el.prenom, el.categorie_age, el.grade
             FROM $ta a
             INNER JOIN $tel el ON el.id = a.eleve_id
             WHERE a.event_id = %d
             ORDER BY a.aire_id ASC, a.ordre ASC, el.nom ASC",
            intval($event_id)
        ) );
    }

    /**
     * Affectations d'une aire donnée.
     */
    public function get_affectations_aire( $event_id, $aire_id ) {
        global $wpdb;
        $ta  = $this->table_exam_affectations();
        $tel = $this->table_eleves();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, el.nom, el.prenom, el.categorie_age, el.grade
             FROM $ta a
             INNER JOIN $tel el ON el.id = a.eleve_id
             WHERE a.event_id = %d AND (a.aire_id = %d OR a.aire_id = 0)
             ORDER BY a.ordre ASC, el.nom ASC",
            intval($event_id), intval($aire_id)
        ) );
    }

    /**
     * Sauvegarder les affectations d'une aire (remplace l'existant).
     * $eleve_ids = tableau d'ids dans l'ordre souhaité.
     */
public function save_affectations_aire( $event_id, $aire_id, $eleve_ids ) {
    global $wpdb;
    $ta  = $this->table_exam_affectations();
    $ev  = intval($event_id);
    $aid = intval($aire_id);

    // Zemita : vérifier si le mode est actif pour cet examen
    $ts = $this->table_exam_sessions();
    $zemita_mode = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT zemita_mode FROM $ts WHERE event_id = %d", $ev
    ) );

    // Pré-charger le mapping grade→groupe si zemita actif (évite N requêtes)
    $mapping = array();
    if ( $zemita_mode ) {
        $tm   = $this->table_exam_zemita_mapping();
        $rows = $wpdb->get_results( "SELECT grade, groupe FROM $tm" );
        foreach ( $rows as $row ) {
            $mapping[ $row->grade ] = $row->groupe;
        }
    }

    // Supprimer les affectations existantes pour cette aire
    $wpdb->delete( $ta, array( 'event_id' => $ev, 'aire_id' => $aid ) );

    foreach ( $eleve_ids as $ordre => $eleve_id ) {
        $eid = intval($eleve_id);
        if ( ! $eid ) continue;

        // Supprimer une éventuelle affectation dans une autre aire
        $wpdb->delete( $ta, array( 'event_id' => $ev, 'eleve_id' => $eid ) );

        $data = array(
            'event_id' => $ev,
            'eleve_id' => $eid,
            'aire_id'  => $aid,
            'ordre'    => intval($ordre),
        );

        // Résoudre zemita_groupe si mode actif
        if ( $zemita_mode ) {
            $tel   = $this->table_eleves();
            $grade = $wpdb->get_var( $wpdb->prepare(
                "SELECT grade FROM $tel WHERE id = %d", $eid
            ) );
            $data['zemita_groupe'] = ( $grade && isset( $mapping[ $grade ] ) )
                ? $mapping[ $grade ]
                : null;
        }

        $wpdb->insert( $ta, $data );
    }
}

public function recalculate_zemita_groupes( $event_id ) {
    global $wpdb;
    $ev  = intval($event_id);
    $ta  = $this->table_exam_affectations();
    $tel = $this->table_eleves();
    $tm  = $this->table_exam_zemita_mapping();

    // Charger le mapping complet
    $rows    = $wpdb->get_results( "SELECT grade, groupe FROM $tm" );
    $mapping = array();
    foreach ( $rows as $row ) {
        $mapping[ $row->grade ] = $row->groupe;
    }

    // Récupérer toutes les affectations + grade de l'élève
    $affectations = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.id, el.grade
         FROM $ta a
         INNER JOIN $tel el ON el.id = a.eleve_id
         WHERE a.event_id = %d",
        $ev
    ) );

    $updated = 0;
    foreach ( $affectations as $aff ) {
        $groupe = ( $aff->grade && isset( $mapping[ $aff->grade ] ) )
            ? $mapping[ $aff->grade ]
            : null;
        $wpdb->update( $ta,
            array( 'zemita_groupe' => $groupe ),
            array( 'id' => intval($aff->id) )
        );
        $updated++;
    }

    return $updated;
}


/**
 * Sauvegarder les affectations en mode par_epreuve.
 * Chaque candidat est affecté à toutes les aires (aire_id = 0).
 * $eleve_ids = tableau d'ids.
 */
public function save_affectations_all_aires( $event_id, $eleve_ids ) {
    global $wpdb;
    $ta = $this->table_exam_affectations();
    $ev = intval( $event_id );

    foreach ( $eleve_ids as $ordre => $eleve_id ) {
        $eid = intval( $eleve_id );
        if ( ! $eid ) continue;

        // Supprimer toute affectation existante pour ce candidat dans cet event
        $wpdb->delete( $ta, array( 'event_id' => $ev, 'eleve_id' => $eid ) );

        // Insérer avec aire_id = 0 → candidat visible sur toutes les aires
        $wpdb->insert( $ta, array(
            'event_id' => $ev,
            'eleve_id' => $eid,
            'aire_id'  => 0,
            'ordre'    => intval( $ordre ),
        ) );
    }
}
    /**
     * Élèves actifs NON encore affectés pour cet event.
     */
    public function get_eleves_non_affectes( $event_id ) {
        global $wpdb;
        $tel = $this->table_eleves();
        $ta  = $this->table_exam_affectations();
        $ev  = intval($event_id);
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT el.id, el.nom, el.prenom, el.categorie_age, el.grade
             FROM $tel el
             WHERE el.actif = 1
             AND el.id NOT IN (
                 SELECT eleve_id FROM $ta WHERE event_id = %d
             )
             ORDER BY el.categorie_age ASC, el.nom ASC",
            $ev
        ) );
    }

    /**
     * Réaffecter un candidat à une nouvelle aire en cours d'examen.
     */
    public function reassign_affectation( $event_id, $eleve_id, $new_aire_id ) {
        global $wpdb;
        $ta  = $this->table_exam_affectations();
        $ev  = intval($event_id);
        $eid = intval($eleve_id);
        $aid = intval($new_aire_id);
        if ( $aid === 0 ) {
            // Désaffecter : supprimer l'entrée
            $wpdb->delete( $ta, array( 'event_id' => $ev, 'eleve_id' => $eid ) );
        } else {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $ta WHERE event_id=%d AND eleve_id=%d", $ev, $eid
            ) );
            if ( $exists ) {
                $wpdb->update( $ta, array('aire_id'=>$aid), array('event_id'=>$ev,'eleve_id'=>$eid) );
            } else {
                $wpdb->insert( $ta, array('event_id'=>$ev,'eleve_id'=>$eid,'aire_id'=>$aid,'ordre'=>0) );
            }
            // Mettre à jour jury_notes existantes
            $wpdb->update(
                $this->table_jury_notes(),
                array( 'exam_table_id' => $aid, 'table_id' => $aid ),
                array( 'event_id' => $ev, 'eleve_id' => $eid )
            );
        }
    }

    /**
     * Retourne l'aire_id affectée à un candidat pour un event donné.
     */
    public function get_aire_for_candidat( $event_id, $eleve_id ) {
        global $wpdb;
        $ta = $this->table_exam_affectations();
        return intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT aire_id FROM $ta WHERE event_id=%d AND eleve_id=%d LIMIT 1",
            intval($event_id), intval($eleve_id)
        ) ) );
    }

    /**
     * Retourne (ou crée) le juge virtuel "Saisie admin" pour une aire donnée.
     * Utilisé pour la saisie manuelle depuis l'interface admin ou la PWA entraîneur.
     */
    public function get_or_create_admin_juge( $event_id, $aire_id ) {
        global $wpdb;
        $tj  = $this->table_exam_juges();
        $ev  = intval($event_id);
        $aid = intval($aire_id);
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $tj WHERE event_id=%d AND table_id=%d AND nom='__admin__' LIMIT 1",
            $ev, $aid
        ) );
        if ( $existing ) return $existing;
        $wpdb->insert( $tj, array(
            'event_id'   => $ev,
            'table_id'   => $aid,
            'nom'        => '__admin__',
            'prenom'     => 'Saisie manuelle',
            'trainer_id' => null,
        ) );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $tj WHERE id=%d LIMIT 1",
            $wpdb->insert_id
        ) );
    }


    /* ══════════════════════════════════════════════════════════
       PASSE 2 — TRANSCRIPTION DES GRADES
    ══════════════════════════════════════════════════════════ */

    /**
     * Génère un snapshot SQL des lignes eleves concernées par cet event.
     * Retourne une string SQL INSERT prête à être sauvegardée ou affichée.
     */
    public function generate_snapshot_sql( $event_id ) {
        global $wpdb;
        $ta  = $this->table_exam_affectations();
        $tel = $this->table_eleves();
        $ev  = intval($event_id);

        $eleve_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT eleve_id FROM $ta WHERE event_id=%d", $ev
        ) );
        // Fallback : presences_eleves si exam_affectations vide
        if ( empty($eleve_ids) ) {
            $tpe2 = $this->table_presences_eleves();
            $eleve_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT eleve_id FROM $tpe2 WHERE event_id=%d", $ev
            ) );
        }
        if ( empty($eleve_ids) ) return '';

        $ids_safe = implode(',', array_map('intval', $eleve_ids));
        $eleves   = $wpdb->get_results(
            "SELECT id, nom, prenom, grade, categorie_age FROM $tel WHERE id IN ($ids_safe)"
        );

        $lines = array();
        $lines[] = '-- Snapshot grades élèves avant transcription jury event_id=' . $ev;
        $lines[] = '-- Généré le ' . current_time('mysql') . ' par SP Calendar PRO';
        $lines[] = '-- Restauration : exécuter les UPDATE ci-dessous dans phpMyAdmin';
        $lines[] = '';
        foreach ( $eleves as $el ) {
            $grade_safe = esc_sql( $el->grade );
            $lines[] = "UPDATE `{$wpdb->prefix}sp_cal_eleves` SET `grade`='{$grade_safe}' WHERE `id`=" . intval($el->id) . "; -- {$el->prenom} {$el->nom}";
        }
        return implode("
", $lines);
    }

    /**
     * Prévisualise les transcriptions à écrire pour un event.
     * Retourne array de [ eleve_id, nom, prenom, grade_actuel, grade_nouveau, admis, override ]
     */
    public function preview_grade_transcription( $event_id, $overrides = array() ) {
        global $wpdb;
        $ta  = $this->table_exam_affectations();
        $tn  = $this->table_jury_notes();
        $tel = $this->table_eleves();
        $ev  = intval($event_id);

        $session  = $this->get_jury_session($ev);
        if ( ! $session ) return array();

        $affectes = $this->get_affectations($ev);

        // Mode par_epreuve : un candidat a N lignes (une par aire) → dédupliquer par eleve_id
        $seen_ids      = array();
        $affectes_uniq = array();
        foreach ( $affectes as $a ) {
            $eid_check = intval( property_exists($a,'eleve_id') ? $a->eleve_id : $a->id );
            if ( ! isset( $seen_ids[ $eid_check ] ) ) {
                $seen_ids[ $eid_check ] = true;
                $affectes_uniq[] = $a;
            }
        }
        $affectes = $affectes_uniq;

        // Fallback : si aucun candidat dans exam_affectations, chercher dans presences_eleves
        if ( empty($affectes) ) {
            $tpe = $this->table_presences_eleves();
            $tel = $this->table_eleves();
            $affectes = $wpdb->get_results( $wpdb->prepare(
                "SELECT el.id, el.nom, el.prenom, el.categorie_age, el.grade
                 FROM $tpe pe
                 INNER JOIN $tel el ON el.id = pe.eleve_id
                 WHERE pe.event_id = %d
                 ORDER BY el.categorie_age ASC, el.nom ASC",
                $ev
            ) );
        }

        // Notes par élève [eleve_id][epreuve_id][juge_id] = note
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT eleve_id, epreuve_id, juge_id, note FROM $tn WHERE event_id=%d", $ev
        ) );
        $notes_idx = array();
        foreach ($rows as $r)
            $notes_idx[intval($r->eleve_id)][intval($r->epreuve_id)][intval($r->juge_id)] = floatval($r->note);

        $result = array();
        foreach ( $affectes as $el ) {
            // eleve_id réel — a.eleve_id prioritaire sur a.id (qui est l'ID ligne affectation)
            $eid   = intval( property_exists($el,'eleve_id') ? $el->eleve_id : $el->id );
            // Calcul score inline (pas de dépendance à SpCalPro_Jury)
            $notes_eleve = $notes_idx[$eid] ?? array();
if ( empty($notes_eleve) ) {
    $score = null;
} else {
    // Charger les épreuves applicables à ce candidat
    $epreuves_all = $this->get_exam_epreuves( null, true );
    $epreuves_ok  = array();
    foreach ( $epreuves_all as $ep ) {
        if ( $this->epreuve_applicable( $ep, $el->categorie_age ?? '' ) ) {
            $epreuves_ok[ intval( $ep->id ) ] = true;
        }
    }
    $score       = 0;
    $nb_epreuves = 0;
    foreach ( $notes_eleve as $ep_id => $ep_notes ) {
        // Ignorer les épreuves non applicables à ce candidat
        if ( ! isset( $epreuves_ok[ intval( $ep_id ) ] ) ) continue;
        $vals = array_values( $ep_notes );
        if ( empty( $vals ) ) continue;
        $score += array_sum( $vals ) / count( $vals );
        $nb_epreuves++;
    }
    $score = $nb_epreuves > 0 ? round( $score / $nb_epreuves, 2 ) : null;
}
            $admis = ($score !== null) ? ($score >= floatval($session->seuil_admission)) : false;

            // Grade nouveau : override prioritaire, sinon mapping auto
            $grade_auto    = $this->get_grade_vise_eleve($el);
            $grade_nouveau = isset($overrides[$eid]) && $overrides[$eid] !== ''
                ? sanitize_text_field($overrides[$eid])
                : $grade_auto;

            $is_override = isset($overrides[$eid]) && $overrides[$eid] !== '';
            $is_forcing  = ! $admis && $is_override && $grade_nouveau !== '';
            $result[] = array(
                'eleve_id'      => $eid,
                'nom'           => $el->nom,
                'prenom'        => $el->prenom,
                'categorie_age' => $el->categorie_age,
                'grade_actuel'  => $el->grade,
                'grade_auto'    => $grade_auto,
                'grade_nouveau' => $grade_nouveau,
                'score'         => $score,
                'admis'         => $admis,
                'override'      => $is_override,
                'forcing'       => $is_forcing,
                // Écrire si admis OU si forcing explicite par l'admin
                'ecrire'        => ($admis || $is_forcing) && $grade_nouveau !== '',
            );
        }
        return $result;
    }

    /**
     * Écrit les passages et grades en base.
     * Appelé APRÈS snapshot + confirmation admin.
     * $lignes = résultat de preview_grade_transcription avec overrides définitifs.
     * Retourne [ nb_passages, nb_grades ]
     */
    public function write_grade_transcription( $event_id, $lignes ) {
        global $wpdb;
        $t   = $this->table_exam_passages();
        $tel = $this->table_eleves();
        $ev  = intval($event_id);
        $nb_passages = 0;
        $nb_grades   = 0;

        foreach ( $lignes as $l ) {
            $eid    = intval($l['eleve_id']);
            $admis  = (bool)$l['admis'];
            $grade  = $this->normaliser_grade( sanitize_text_field($l['grade_nouveau'] ?? ''), '' );

            // Écrire dans exam_passages (upsert)
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $t WHERE event_id=%d AND eleve_id=%d", $ev, $eid
            ) );
            $is_forcing_write = isset($l['forcing']) && $l['forcing'];
            $row = array(
                'recu'          => ($admis || $is_forcing_write) ? 1 : 0,
                'nouveau_grade' => ($admis || $is_forcing_write) ? $grade : '',
                'created_at'    => time(),
            );
            if ( $existing ) {
                $wpdb->update( $t, $row, array('event_id'=>$ev,'eleve_id'=>$eid) );
            } else {
                $wpdb->insert( $t, array_merge($row, array('event_id'=>$ev,'eleve_id'=>$eid)) );
            }
            error_log( '[SP_JURY_v14g] UPSERT exam_passages'
                . ' | eleve_id='     . $eid
                . ' | event_id='     . $ev
                . ' | existing='     . ( $existing ? 'oui' : 'non' )
                . ' | rows_affected=' . $wpdb->rows_affected
                . ' | last_error="'   . $wpdb->last_error . '"'
            );
            $nb_passages++;

            // Écrire grade sur fiche élève si admis OU forcing admin
            if ( ($admis || (isset($l['forcing']) && $l['forcing'])) && $grade ) {
                // 1. Mettre à jour eleves.grade
                $wpdb->update( $tel, array('grade'=>$grade), array('id'=>$eid) );
                error_log( '[SP_JURY_v14g] UPDATE eleves.grade'
                    . ' | eleve_id='      . $eid
                    . ' | grade_nouveau=' . $grade
                    . ' | rows_affected=' . $wpdb->rows_affected
                    . ' | last_error="'   . $wpdb->last_error . '"'
                );
                // 2. UPSERT presences_eleves.note (timeline fiche)
                // La ligne peut ne pas exister si l'élève vient d'exam_affectations
                $tpe = $this->table_presences_eleves();
                $pe_exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $tpe WHERE event_id=%d AND eleve_id=%d",
                    $ev, $eid
                ) );
                if ( $pe_exists ) {
                    $wpdb->update( $tpe,
                        array('note'=>$grade, 'present'=>1),
                        array('event_id'=>$ev,'eleve_id'=>$eid)
                    );
                } else {
                    $wpdb->insert( $tpe, array(
                        'event_id' => $ev,
                        'eleve_id' => $eid,
                        'present'  => 1,
                        'note'     => $grade,
                    ) );
                }
                error_log( '[SP_JURY_v14g] UPSERT presences_eleves'
                    . ' | eleve_id='     . $eid
                    . ' | pe_exists='    . ( $pe_exists ? 'oui' : 'non' )
                    . ' | rows_affected=' . $wpdb->rows_affected
                    . ' | last_error="'   . $wpdb->last_error . '"'
                );
                $nb_grades++;
            }
            error_log( '[SP_JURY_v14g] FIN_LIGNE eleve_id=' . $eid . ' nb_passages=' . $nb_passages . ' nb_grades=' . $nb_grades );
        }
        return array('passages'=>$nb_passages,'grades'=>$nb_grades);
    }


    /* ══════════════════════════════════════════════════════════
       PARCOURS DE PROGRESSION — FICHE ÉLÈVE
    ══════════════════════════════════════════════════════════ */

    /**
     * Construit le parcours complet d'un élève :
     * chaîne de grades depuis le début jusqu'à l'objectif final,
     * avec pour chaque grade : statut (passé/actuel/futur),
     * date de passage réelle si disponible, épreuves requises.
     *
     * @param  object $eleve   Ligne de la table eleves
     * @return array           Tableau de steps + meta (pct, nb_passes, objectif_final)
     */
    public function get_parcours_eleve( $eleve ) {
        global $wpdb;
        $tgp  = $this->table_exam_grade_progression();
        $tep  = $this->table_exam_epreuves();
        $tpass= $this->table_exam_passages();
        $te   = $this->table_events();

        // ── 1. Construire la chaîne complète depuis le début ──────────
        // Filtrer par categorie_age pour éviter les mélanges Baby/Enfant/Ado
        $categorie_age = $eleve->categorie_age ?? '';
        if ( $categorie_age ) {
            $all_prog = $wpdb->get_results( $wpdb->prepare(
                "SELECT grade_actuel, grade_suivant FROM $tgp WHERE categorie_age = %s ORDER BY id ASC",
                $categorie_age
            ) );
        } else {
            $all_prog = $wpdb->get_results(
                "SELECT grade_actuel, grade_suivant FROM $tgp WHERE categorie_age = '' ORDER BY id ASC"
            );
        }
        if ( empty($all_prog) ) return array();

        // Index forward et backward
        $next_of = array(); // grade → grade suivant
        $prev_of = array(); // grade → grade précédent
        foreach ( $all_prog as $row ) {
            $next_of[ $row->grade_actuel ]  = $row->grade_suivant;
            $prev_of[ $row->grade_suivant ] = $row->grade_actuel;
        }

        // Trouver le grade de départ de la chaîne contenant le grade actuel de l'élève
        $grade_courant = $eleve->grade ?? '';
        if ( ! $grade_courant ) {
            // Pas de grade : on prend le premier grade de la table (début absolu)
            $departs = array_diff( array_keys($next_of), array_keys($prev_of) );
            $depart  = reset($departs) ?: '';
        } else {
            // Remonter jusqu'au grade sans prédécesseur
            $depart = $grade_courant;
            $security = 0;
            while ( isset($prev_of[$depart]) && $security < 30 ) {
                $depart = $prev_of[$depart];
                $security++;
            }
        }

        // Construire la liste ordonnée de tous les grades
        $chain = array();
        $g = $depart;
        $security = 0;
        while ( $g && $security < 50 ) {
            $chain[] = $g;
            $g = $next_of[$g] ?? null;
            $security++;
        }
        if ( empty($chain) ) return array();

        // ── 2. Passages réels de l'élève ───────────────────────────
        $passages = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.nouveau_grade, p.recu, e.date, e.titre
             FROM $tpass p
             LEFT JOIN $te e ON e.id = p.event_id
             WHERE p.eleve_id = %d AND p.recu = 1
             ORDER BY e.date ASC",
            intval($eleve->id)
        ) );
        $grade_dates = array(); // grade → date passage
        foreach ( $passages as $p ) {
            if ( $p->nouveau_grade && $p->recu )
                $grade_dates[ $p->nouveau_grade ] = array(
                    'date'  => $p->date,
                    'titre' => $p->titre,
                );
        }

        // ── 3. Épreuves par grade ──────────────────────────────────
        $epreuves_raw = $wpdb->get_results(
            "SELECT * FROM $tep WHERE actif=1 ORDER BY categorie_age ASC, ordre ASC"
        );
        // Index [grade] = array of épreuves (on match sur categorie_age = grade)
        $ep_by_grade = array();
        foreach ( $epreuves_raw as $ep ) {
            $ep_by_grade[ $ep->categorie_age ][] = $ep;
        }

        // ── 4. Construire les steps ────────────────────────────────
        $grade_idx   = array_search( $grade_courant, $chain );
        $nb_total    = count($chain);
        // grade_idx = position du grade actuel (déjà obtenu)
        // nb_passes = nombre de grades obtenus = grade_idx + 1
        $nb_passes   = ($grade_idx !== false) ? $grade_idx + 1 : 0;
        $objectif    = end($chain);

        $steps = array();
        foreach ( $chain as $idx => $grade ) {
            if ( $grade_courant && $idx < $grade_idx ) {
                $statut = 'passe';
            } elseif ( $grade_courant && $idx === $grade_idx ) {
                $statut = 'passe'; // grade actuel = déjà obtenu = passé
            } elseif ( $grade_courant && $idx === $grade_idx + 1 ) {
                $statut = 'actuel'; // prochain grade à viser = actuel
            } else {
                $statut = 'futur';
            }
            $steps[] = array(
                'grade'     => $grade,
                'statut'    => $statut,
                'date'      => $grade_dates[$grade]['date']  ?? null,
                'examen'    => $grade_dates[$grade]['titre'] ?? null,
                'epreuves'  => $ep_by_grade[$grade] ?? array(),
                'is_objectif' => ($idx === $nb_total - 1),
            );
        }

        $pct = $nb_total > 1 ? round($nb_passes / ($nb_total - 1) * 100) : 100;
        // Prochain = grade suivant le grade actuel
        $prochain = $next_of[$grade_courant] ?? null;

        // ── 5. Construire l'historique des catégories précédentes ─────
        // Ordre des catégories : Baby → Enfant → Ado/adulte → Adulte
        $cat_order    = array('Baby', 'Enfant', 'Ado/adulte', 'Adulte');
        $cat_idx_curr = array_search($categorie_age, $cat_order);

        // Extraire les dates depuis extra_data (import CSV)
        $extra_dates  = array(); // grade → date (depuis extra_data.grades)
        $extra_raw    = $eleve->extra_data ?? '';
        if ( $extra_raw ) {
            $extra = json_decode($extra_raw, true);
            if ( isset($extra['grades']) && is_array($extra['grades']) ) {
                foreach ( $extra['grades'] as $date_str => $grade_val ) {
                    // date_str format JJ/MM/AAAA, convertir en Y-m-d
                    $parts = explode('/', $date_str);
                    if ( count($parts) === 3 ) {
                        $date_ymd = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                        // Garder la date la plus récente pour chaque grade
                        if ( ! isset($extra_dates[$grade_val]) || $date_ymd > $extra_dates[$grade_val] ) {
                            $extra_dates[$grade_val] = $date_ymd;
                        }
                    }
                }
            }
        }

        $historique = array();
        if ( $cat_idx_curr !== false && $cat_idx_curr > 0 ) {
            // Construire l'historique pour chaque catégorie précédente
            for ( $ci = 0; $ci < $cat_idx_curr; $ci++ ) {
                $cat_hist = $cat_order[$ci];
                $prog_hist = $wpdb->get_results( $wpdb->prepare(
                    "SELECT grade_actuel, grade_suivant FROM $tgp WHERE categorie_age = %s ORDER BY id ASC",
                    $cat_hist
                ) );
                if ( empty($prog_hist) ) continue;

                // Construire la chaîne de la catégorie historique
                $next_hist = array();
                $prev_hist = array();
                foreach ( $prog_hist as $r ) {
                    $next_hist[$r->grade_actuel] = $r->grade_suivant;
                    $prev_hist[$r->grade_suivant] = $r->grade_actuel;
                }
                $departs_hist = array_diff(array_keys($next_hist), array_keys($prev_hist));
                $dep_hist     = reset($departs_hist) ?: '';
                $chain_hist   = array();
                $g = $dep_hist;
                $sec = 0;
                while ( $g && $sec < 50 ) {
                    $chain_hist[] = $g;
                    $g = $next_hist[$g] ?? null;
                    $sec++;
                }

                // Afficher toute la chaîne de la catégorie historique
                // Les dates sont affichées uniquement si disponibles (extra_data ou exam_passages)
                $steps_hist = array();
                foreach ( $chain_hist as $grade ) {
                    $date_hist = $grade_dates[$grade]['date'] ?? $extra_dates[$grade] ?? null;
                    $steps_hist[] = array(
                        'grade'  => $grade,
                        'date'   => $date_hist,
                        'statut' => 'passe',
                    );
                }

                if ( ! empty($steps_hist) ) {
                    $historique[] = array(
                        'categorie' => $cat_hist,
                        'steps'     => $steps_hist,
                    );
                }
            }
        }

        return array(
            'steps'       => $steps,
            'pct'         => $pct,
            'nb_passes'   => $nb_passes,
            'nb_total'    => $nb_total,
            'grade_courant'=> $grade_courant,
            'objectif'    => $objectif,
            'prochain'    => $prochain,
            'historique'  => $historique,
            'epreuves_prochain' => $next_of[$grade_courant]
                ? ($ep_by_grade[ $next_of[$grade_courant] ] ?? array())
                : array(),
        );
    }

    /* ══════════════════════════════════════════════════════════
       GRADE CONTENU — Ressources pédagogiques
    ══════════════════════════════════════════════════════════ */

    public function get_grade_contenu( $grade_actuel ) {
        global $wpdb;
        $t = $this->table_exam_grade_contenu();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE grade_actuel=%s", sanitize_text_field($grade_actuel)
        ) );
    }

    public function get_all_grade_contenu() {
        global $wpdb;
        $t = $this->table_exam_grade_contenu();
        return $wpdb->get_results( "SELECT * FROM $t ORDER BY grade_actuel ASC" );
    }

    public function save_grade_contenu( $grade_actuel, $description, $liens_json ) {
        global $wpdb;
        $t   = $this->table_exam_grade_contenu();
        $g   = sanitize_text_field($grade_actuel);
        $row = array(
            'description' => wp_kses_post($description),
            'liens'       => $liens_json,
        );
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE grade_actuel=%s", $g ) );
        if ( $existing ) {
            $wpdb->update( $t, $row, array('grade_actuel'=>$g) );
        } else {
            $wpdb->insert( $t, array_merge($row, array('grade_actuel'=>$g)) );
        }
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

        // Récupérer tous les élèves actifs avec date de naissance
        $eleves = $wpdb->get_results(
            "SELECT id, nom, prenom, date_naissance, annee_naissance, categorie_age
             FROM $tel
             WHERE actif = 1
               AND annee_naissance != ''
               AND date_naissance != ''
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
       INSCRIPTIONS AUX ÉVÉNEMENTS
    ══════════════════════════════════════════════════════════ */

    public function table_event_inscriptions() {
        return $this->table( 'event_inscriptions' );
    }

    /**
     * Toutes les inscriptions d'un événement, avec nom/email de l'élève.
     */
    public function get_inscriptions_event( $event_id ) {
        global $wpdb;
        $ti  = $this->table_event_inscriptions();
        $tel = $this->table_eleves();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, e.prenom, e.nom, e.email, e.categorie_age, e.categorie_saisie
             FROM $ti i
             LEFT JOIN $tel e ON e.id = i.eleve_id
             WHERE i.event_id = %d
             ORDER BY e.nom, e.prenom",
            intval( $event_id )
        ) );
    }

    /**
     * Inscription d'un élève précis pour un événement.
     */
    public function get_inscription_eleve( $event_id, $eleve_id ) {
        global $wpdb;
        $ti = $this->table_event_inscriptions();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $ti WHERE event_id=%d AND eleve_id=%d",
            intval( $event_id ), intval( $eleve_id )
        ) );
    }

    /**
     * Crée ou met à jour une inscription (statut + commentaire).
     */
    public function save_inscription( $event_id, $eleve_id, $statut, $commentaire = '' ) {
        global $wpdb;
        $ti       = $this->table_event_inscriptions();
        $existing = $this->get_inscription_eleve( $event_id, $eleve_id );

        $data = array(
            'event_id'     => intval( $event_id ),
            'eleve_id'     => intval( $eleve_id ),
            'statut'       => sanitize_text_field( $statut ),
            'commentaire'  => sanitize_textarea_field( $commentaire ),
            'date_reponse' => date( 'Y-m-d H:i:s' ),
        );

        if ( $existing ) {
            // Incrémenter nb_changements seulement si le statut change réellement
            if ( $existing->statut !== $statut ) {
                $current_nb = isset( $existing->nb_changements ) ? intval( $existing->nb_changements ) : 0;
                $data['nb_changements'] = $current_nb + 1;
            }
            return $wpdb->update( $ti, $data, array( 'id' => intval( $existing->id ) ) ) !== false;
        }

        // Première réponse : nb_changements = 0
        $data['nb_changements'] = 0;
        return $wpdb->insert( $ti, $data ) !== false;
    }

    /**
     * Événements avec inscriptions ouvertes (deadline non dépassée)
     * auxquels l'élève n'a pas encore répondu, pour affichage sur la fiche membre.
     */
    public function get_events_inscriptions_ouvertes_eleve( $eleve_id ) {
        global $wpdb;
        $te    = $this->table_events();
        $ti    = $this->table_event_inscriptions();
        $tel   = $this->table_eleves();
        $today = date( 'Y-m-d' );

        // Récupérer la catégorie de l'élève pour filtrer
        $eleve = $wpdb->get_row( $wpdb->prepare(
            "SELECT categorie_age, categorie_saisie FROM $tel WHERE id=%d",
            intval( $eleve_id )
        ) );
        $cat_age    = $eleve ? trim( $eleve->categorie_age    ?? '' ) : '';
        $cat_saisie = $eleve ? trim( $eleve->categorie_saisie ?? '' ) : '';

        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, COALESCE(i.statut, 'en_attente') AS statut_insc
             FROM $te e
             LEFT JOIN $ti i ON i.event_id = e.id AND i.eleve_id = %d
             WHERE e.inscriptions_actives = 1
               AND e.inscriptions_envoye  = 1
               AND e.date >= %s
             ORDER BY e.date ASC",
            intval( $eleve_id ), $today
        ) );

        // Filtrer côté PHP : si inscriptions_public est renseigné,
        // vérifier que la catégorie de l'élève est dans la liste
        return array_values( array_filter( $events, function( $ev ) use ( $cat_age, $cat_saisie ) {
            $public = trim( $ev->inscriptions_public ?? '' );
            if ( ! $public || $public === '[]' ) return true; // ouvert à tous
            $cats = array_map( 'trim', explode( ',', $public ) );
            // Vérifier catégorie_age OU catégorie_saisie
            foreach ( $cats as $cat ) {
                if ( $cat === '' ) continue;
                if ( $cat_age    && stripos( $cat_age,    $cat ) !== false ) return true;
                if ( $cat_saisie && stripos( $cat_saisie, $cat ) !== false ) return true;
                if ( $cat_age    && stripos( $cat,        $cat_age    ) !== false ) return true;
                if ( $cat_saisie && stripos( $cat,        $cat_saisie ) !== false ) return true;
            }
            return false;
        } ) );
    }

    /**
     * Élèves actifs avec email, filtrés par catégorie si inscriptions_public renseigné.
     */
    public function get_eleves_pour_inscription( $event ) {
        global $wpdb;
        $tel      = $this->table_eleves();
        $ti       = $wpdb->prefix . 'sp_cal_event_inscriptions';
        $event_id = intval( $event->id );
        $public   = trim( isset( $event->inscriptions_public ) ? $event->inscriptions_public : '' );

        // Sous-requête : exclure les élèves ayant déjà une réponse (oui ou non)
        $exclude_sql = "AND id NOT IN (
            SELECT eleve_id FROM $ti
            WHERE event_id = $event_id
            AND reponse IN ('oui','non')
        )";

        if ( ! $public || $public === '[]' ) {
            return $wpdb->get_results(
                "SELECT id, nom, prenom, email, token, categorie_age
                 FROM $tel
                 WHERE actif=1 AND email != ''
                 $exclude_sql
                 ORDER BY nom, prenom"
            );
        }

        $cats = array_filter( array_map( 'trim', explode( ',', $public ) ) );
        if ( empty( $cats ) ) return array();

        $placeholders = implode( ',', array_fill( 0, count( $cats ), '%s' ) );
        $sql  = "SELECT id, nom, prenom, email, token, categorie_age
                 FROM $tel
                 WHERE actif=1 AND email != ''
                 AND categorie_age IN ($placeholders)
                 $exclude_sql
                 ORDER BY nom, prenom";
        $args = array_merge( array( $sql ), $cats );
        return $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), $args ) );
    }
	
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
/**
 * Sauvegarde les questions d'un sondage (remplace toutes les questions existantes)
 * Appelé uniquement si le sondage n'a pas encore été envoyé
 */
public function save_sondage_questions( int $sondage_id, array $questions ): bool {
    global $wpdb;

    // Sécurité : on ne modifie pas si déjà envoyé
    $sondage = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT envoye FROM {$wpdb->prefix}sp_cal_sondages WHERE id = %d",
            $sondage_id
        )
    );
    if ( ! $sondage || $sondage->envoye ) {
        return false;
    }

    $table_q = $wpdb->prefix . 'sp_cal_sondage_questions';

    // Supprime les questions existantes avant de réinsérer
    $wpdb->delete( $table_q, [ 'sondage_id' => $sondage_id ], [ '%d' ] );

    foreach ( $questions as $ordre => $q ) {
        $type       = isset( $q['type'] ) && $q['type'] === 'note' ? 'note' : 'texte';
        $question   = isset( $q['question'] ) ? sanitize_text_field( wp_unslash( $q['question'] ) ) : '';
        $obligatoire = ! empty( $q['obligatoire'] ) ? 1 : 0;

        if ( $question === '' ) {
            continue;
        }

        $wpdb->insert( $table_q, [
            'sondage_id'  => $sondage_id,
            'type'        => $type,
            'question'    => $question,
            'ordre'       => (int) $ordre,
            'obligatoire' => $obligatoire,
        ], [ '%d', '%s', '%s', '%d', '%d' ] );
    }

    return true;
}

/**
 * Récupère les questions d'un sondage triées par ordre
 */
public function get_sondage_questions( int $sondage_id ): array {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sp_cal_sondage_questions
             WHERE sondage_id = %d
             ORDER BY ordre ASC",
            $sondage_id
        ),
        ARRAY_A
    ) ?: [];
}

/**
 * Enregistre les réponses d'un élève à un sondage
 * Idempotent : INSERT ... ON DUPLICATE KEY UPDATE
 */
public function save_sondage_reponses( int $sondage_id, int $eleve_id, array $reponses ): bool {
    global $wpdb;
    $table_r = $wpdb->prefix . 'sp_cal_sondage_reponses';

    // Vérifie que le sondage existe et est actif
    $sondage = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, actif FROM {$wpdb->prefix}sp_cal_sondages WHERE id = %d",
            $sondage_id
        )
    );
    if ( ! $sondage || ! $sondage->actif ) {
        return false;
    }

    foreach ( $reponses as $question_id => $valeur ) {
        $question_id = (int) $question_id;

        // Récupère le type de la question
        $q = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT type FROM {$wpdb->prefix}sp_cal_sondage_questions
                 WHERE id = %d AND sondage_id = %d",
                $question_id,
                $sondage_id
            )
        );
        if ( ! $q ) {
            continue;
        }

        $note  = null;
        $texte = null;

        if ( $q->type === 'note' ) {
            $note = max( 1, min( 5, (int) $valeur ) );
        } else {
            $texte = sanitize_textarea_field( wp_unslash( (string) $valeur ) );
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table_r}
                 (sondage_id, question_id, eleve_id, reponse_note, reponse_texte, date_reponse)
                 VALUES (%d, %d, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                   reponse_note  = VALUES(reponse_note),
                   reponse_texte = VALUES(reponse_texte),
                   date_reponse  = VALUES(date_reponse)",
                $sondage_id,
                $question_id,
                $eleve_id,
                $note,
                $texte,
                current_time( 'mysql' )
            )
        );
    }

    return true;
}

/**
 * Vérifie si un élève a déjà répondu à un sondage
 */
public function eleve_a_repondu_sondage( int $sondage_id, int $eleve_id ): bool {
    global $wpdb;

    $count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sp_cal_sondage_reponses
             WHERE sondage_id = %d AND eleve_id = %d",
            $sondage_id,
            $eleve_id
        )
    );

    return (int) $count > 0;
}

/**
 * Résultats agrégés d'un sondage pour l'admin
 * Retourne par question : moyenne (note), liste textes, nb réponses
 */
public function get_sondage_resultats( int $sondage_id ): array {
    global $wpdb;

    $questions = $this->get_sondage_questions( $sondage_id );
    $resultats = [];

    foreach ( $questions as $q ) {
        $qid = (int) $q['id'];
        $res = [ 'question' => $q, 'nb_reponses' => 0, 'moyenne' => null, 'textes' => [] ];

        if ( $q['type'] === 'note' ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) as nb, AVG(reponse_note) as moy
                     FROM {$wpdb->prefix}sp_cal_sondage_reponses
                     WHERE question_id = %d AND reponse_note IS NOT NULL",
                    $qid
                ),
                ARRAY_A
            );
            $res['nb_reponses'] = (int) ( $row['nb'] ?? 0 );
            $res['moyenne']     = $row['moy'] !== null ? round( (float) $row['moy'], 2 ) : null;
        } else {
            $textes = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT reponse_texte
                     FROM {$wpdb->prefix}sp_cal_sondage_reponses
                     WHERE question_id = %d AND reponse_texte IS NOT NULL AND reponse_texte != ''",
                    $qid
                )
            );
            $res['nb_reponses'] = count( $textes );
            $res['textes']      = $textes;
        }

        $resultats[] = $res;
    }

    return $resultats;
}

/**
 * Marque un sondage comme envoyé
 */
public function marquer_sondage_envoye( int $sondage_id ): void {
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'sp_cal_sondages',
        [ 'envoye' => 1, 'date_envoi' => current_time( 'mysql' ) ],
        [ 'id' => $sondage_id ],
        [ '%d', '%s' ],
        [ '%d' ]
    );
}

/**
 * Récupère les sondages à envoyer (événement passé J+1, non encore envoyés)
 */
public function get_sondages_a_envoyer(): array {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT s.*, e.titre, e.date_fin
         FROM {$wpdb->prefix}sp_cal_sondages s
         INNER JOIN {$wpdb->prefix}sp_cal_events e ON e.id = s.event_id
         WHERE s.envoye = 0
           AND s.actif = 1
           AND DATE(e.date_fin) < CURDATE()
           AND (SELECT COUNT(*) FROM {$wpdb->prefix}sp_cal_sondage_questions q WHERE q.sondage_id = s.id) > 0",
        ARRAY_A
    ) ?: [];
}

/**
 * Sondages auxquels un élève peut encore répondre :
 * événement passé, sondage envoyé, élève inscrit, pas encore répondu
 */
public function get_sondages_en_attente_eleve( int $eleve_id ): array {
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.id as sondage_id, s.event_id, e.titre,
                e.date as event_date,
                (SELECT COUNT(*) FROM {$wpdb->prefix}sp_cal_sondage_questions q WHERE q.sondage_id = s.id) as nb_questions
         FROM {$wpdb->prefix}sp_cal_sondages s
         INNER JOIN {$wpdb->prefix}sp_cal_events e ON e.id = s.event_id
         INNER JOIN {$wpdb->prefix}sp_cal_event_inscriptions i
                 ON i.event_id = s.event_id AND i.eleve_id = %d AND i.reponse = 'oui'
         WHERE s.actif  = 1
           AND s.envoye = 1
           AND DATE(e.date) < CURDATE()
           AND NOT EXISTS (
               SELECT 1 FROM {$wpdb->prefix}sp_cal_sondage_reponses r
               WHERE r.sondage_id = s.id AND r.eleve_id = %d
           )
         ORDER BY e.date DESC",
        $eleve_id,
        $eleve_id
    ), ARRAY_A ) ?: [];

    foreach ( $rows as &$row ) {
        $row['date_fmt'] = $row['event_date']
            ? date_create( $row['event_date'] )->format( 'd/m/Y' )
            : '—';
    }
    return $rows;
}

/* ══════════════════════════════════════════════════════════
   ZEMITA — Seuils par examen (grille 3×3)
══════════════════════════════════════════════════════════ */

/** Retourne la grille complète [groupe][categorie_age] => {seuil_moyen, seuil_performance} */
public function get_zemita_seuils( int $event_id, int $epreuve_id = 3 ): array {
    global $wpdb;
    $t    = $this->table_exam_zemita_seuils();
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $t WHERE event_id = %d AND epreuve_id = %d", $event_id, $epreuve_id
    ) );
    $grid = array();
    foreach ( $rows as $r ) {
        $grid[ $r->groupe ][ $r->categorie_age ] = array(
            'coups_ref'     => floatval( $r->coups_ref ),
            'note_plancher' => floatval( $r->note_plancher ),
        );
    }
    return $grid;
}

public function save_zemita_seuils( int $event_id, array $seuils, int $epreuve_id = 3 ): void {
    global $wpdb;
    $t  = $this->table_exam_zemita_seuils();
    $ev = intval( $event_id );
    foreach ( $seuils as $groupe => $cats ) {
        foreach ( $cats as $cat => $vals ) {
			if ( empty($vals['coups_ref']) && empty($vals['note_plancher']) ) continue;
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO $t
                    (event_id, epreuve_id, groupe, categorie_age, coups_ref, note_plancher)
                 VALUES (%d, %d, %s, %s, %f, %f)
                 ON DUPLICATE KEY UPDATE
                     coups_ref     = %f,
                     note_plancher = %f",
                $ev,
                $epreuve_id,
                sanitize_text_field( $groupe ),
                sanitize_text_field( $cat ),
                floatval( $vals['coups_ref']    ?? 0 ),
                floatval( $vals['note_plancher'] ?? 3 ),
                floatval( $vals['coups_ref']    ?? 0 ),
                floatval( $vals['note_plancher'] ?? 3 )
            ) );
        }
    }
}

/**
 * Convertit un nombre de coups en note /10 par interpolation linéaire.
 *
 * Paliers :
 *   < coups_mini              → note_plancher (défaut 3)
 *   coups_mini → coups_moyen  → interpolation note_plancher → 5
 *   coups_moyen → coups_perf  → interpolation 5 → 10
 *   ≥ coups_perf              → 10
 *
 * @param float $coups         Nombre de coups comptés par le juge
 * @param array $seuils        Ligne de zemita_seuils pour ce groupe/catégorie
 * @return float               Note sur 10, arrondie à 2 décimales
 */
public function convert_coups_to_note( float $coups, array $seuils ): float {
    $ref      = floatval( $seuils['coups_ref']    ?? 0 );
    $plancher = floatval( $seuils['note_plancher'] ?? 3 );
    if ( $ref <= 0 ) return $plancher;
    if ( $coups <= 0 ) return 0.0;
    return round( min( 10.0, ( $coups / $ref ) * 10 ), 2 );
}

/** Seuils pour un candidat donné (via son groupe et sa categorie_age) */
public function get_zemita_seuils_candidat( int $event_id, string $groupe, string $categorie_age, int $epreuve_id = 3 ): ?array {
    global $wpdb;
    $t   = $this->table_exam_zemita_seuils();
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT coups_ref, note_plancher FROM $t
         WHERE event_id=%d AND epreuve_id=%d AND groupe=%s AND categorie_age=%s",
        $event_id, $epreuve_id, $groupe, $categorie_age
    ) );
    if ( ! $row ) return null;
    return array(
        'coups_ref'     => floatval( $row->coups_ref ),
        'note_plancher' => floatval( $row->note_plancher ),
    );
}

/* ══════════════════════════════════════════════════════════
   ZEMITA — Mapping grade → groupe (global)
══════════════════════════════════════════════════════════ */

/** Retourne tout le mapping [grade => groupe] */
public function get_zemita_mapping(): array {
    global $wpdb;
    $t    = $this->table_exam_zemita_mapping();
    $rows = $wpdb->get_results( "SELECT grade, groupe FROM $t ORDER BY groupe, grade" );
    $map  = array();
    foreach ( $rows as $r ) $map[ $r->grade ] = $r->groupe;
    return $map;
}

/** Sauvegarde le mapping complet (remplace tout) */
public function save_zemita_mapping( array $mapping ): void {
    global $wpdb;
    $t = $this->table_exam_zemita_mapping();
    $wpdb->query( "TRUNCATE TABLE $t" );
    foreach ( $mapping as $grade => $groupe ) {
        if ( trim( $grade ) === '' ) continue;
        $wpdb->insert( $t, array(
            'grade'  => sanitize_text_field( trim( $grade ) ),
            'groupe' => sanitize_text_field( trim( $groupe ) ),
        ) );
    }
}

/** Retourne le groupe d'un grade donné (null si non mappé) */
public function get_zemita_groupe_by_grade( string $grade ): ?string {
    global $wpdb;
    $t = $this->table_exam_zemita_mapping();
    return $wpdb->get_var( $wpdb->prepare(
        "SELECT groupe FROM $t WHERE grade = %s LIMIT 1", $grade
    ) ) ?: null;
}

/* ══════════════════════════════════════════════════════════
   ZEMITA — Groupe candidat par examen (dans exam_affectations)
══════════════════════════════════════════════════════════ */

/** Met à jour le groupe ZEMITA d'un candidat pour un examen donné */
public function save_zemita_groupe_candidat( int $event_id, int $eleve_id, string $groupe ): void {
    global $wpdb;
    $ta = $this->table_exam_affectations();
    $wpdb->update(
        $ta,
        array( 'zemita_groupe' => sanitize_text_field( $groupe ) ),
        array( 'event_id' => $event_id, 'eleve_id' => $eleve_id )
    );
}

/** Retourne le groupe ZEMITA d'un candidat pour un examen */
public function get_zemita_groupe_candidat( int $event_id, int $eleve_id ): string {
    global $wpdb;
    $ta = $this->table_exam_affectations();
    return $wpdb->get_var( $wpdb->prepare(
        "SELECT zemita_groupe FROM $ta WHERE event_id=%d AND eleve_id=%d LIMIT 1",
        $event_id, $eleve_id
    ) ) ?: '';
}

/** Initialise les groupes ZEMITA de tous les candidats d'un examen selon le mapping grade */
public function init_zemita_groupes( int $event_id ): void {
    global $wpdb;
    $ta  = $this->table_exam_affectations();
    $tel = $this->table_eleves();
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.eleve_id, el.grade FROM $ta a
         INNER JOIN $tel el ON el.id = a.eleve_id
         WHERE a.event_id = %d", $event_id
    ) );
    foreach ( $rows as $r ) {
        $groupe = $this->get_zemita_groupe_by_grade( $r->grade ) ?: 'Débutant';
        $this->save_zemita_groupe_candidat( $event_id, intval( $r->eleve_id ), $groupe );
    }
}
}

endif; // class_exists SpCalPro_DB

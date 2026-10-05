<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_DB' ) ) :

class SpCalPro_DB {

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

        // Étape 1a : « 15e keup » → grade de même rang dans TKD Parcours pour la catégorie
        // (le mapping ci-dessous garde l'ancienne numérotation Baby / Enfant, conservé en secours)
        if ( $categorie_age && preg_match( '/^(\d+)e\s*keup$/i', $g, $m ) ) {
            $ref = $this->get_grades_claira();
            $cat = $categorie_age === 'Adulte' ? 'Ado/adulte' : $categorie_age;
            foreach ( $ref[ $cat ] ?? array() as $g_claira ) {
                if ( strpos( $g_claira, $m[1] . 'e ' ) === 0 ) return $g_claira;
            }
        }

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
       RÉFÉRENTIEL DE GRADES — FICHE ÉLÈVE (liste déroulante)
    ══════════════════════════════════════════════════════════ */

    /**
     * Retourne, pour chaque categorie_age configurée dans la table de progression,
     * la chaîne ordonnée complète des grades (du premier grade jusqu'à l'objectif final).
     * Sert à peupler la liste déroulante de saisie de grade sur la fiche élève —
     * remplace l'ancien "Chemin de ceinture" (jauge + étapes), jugé trop complexe.
     *
     * Source : TKD Parcours (Claira) quand il est actif — même référentiel que le schéma des
     * grades du site —, sinon la table de progression du module Jury (ancienne numérotation).
     *
     * @return array  [ categorie_age => [ grade1, grade2, ... ] ]
     */
    public function get_grades_referentiel() {
        $claira = $this->get_grades_claira();
        if ( $claira ) return $claira;

        global $wpdb;
        $tgp = $this->table_exam_grade_progression();
        $categories = $wpdb->get_col( "SELECT DISTINCT categorie_age FROM $tgp WHERE categorie_age != '' ORDER BY categorie_age ASC" );

        $result = array();
        foreach ( $categories as $cat ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT grade_actuel, grade_suivant FROM $tgp WHERE categorie_age = %s ORDER BY id ASC",
                $cat
            ) );
            if ( empty( $rows ) ) continue;

            $next_of = array();
            $prev_of = array();
            foreach ( $rows as $r ) {
                $next_of[ $r->grade_actuel ]  = $r->grade_suivant;
                $prev_of[ $r->grade_suivant ] = $r->grade_actuel;
            }
            $departs = array_diff( array_keys( $next_of ), array_keys( $prev_of ) );
            $depart  = reset( $departs ) ?: '';

            $chain = array();
            $g = $depart;
            $security = 0;
            while ( $g && $security < 50 ) {
                $chain[] = $g;
                $g = $next_of[ $g ] ?? null;
                $security++;
            }
            if ( $chain ) $result[ $cat ] = $chain;
        }
        return $result;
    }

    /* ══════════════════════════════════════════════════════════
       GRADES — RÉFÉRENTIEL TKD PARCOURS (plugin claira-tkd-parcours)
       Les grades du club sont gérés dans TKD Parcours (schéma des grades du site) ;
       sp-build les relit ici au lieu de maintenir sa propre liste, dont la numérotation
       Baby / Enfant était restée l'ancienne (15e blanche au lieu de 19e blanche…).
    ══════════════════════════════════════════════════════════ */

    public function claira_grades_actif() {
        return post_type_exists( 'tkd_grade' ) && function_exists( 'claira_tkd_sort_grades_by_keup' );
    }

    /**
     * Écriture sp-build d'un grade TKD Parcours : rang keup + ceinture en minuscules, étoiles
     * collées — « 16e » + « Jaune (*) » → « 16e jaune* », « 18e » + « Blanche / Jaune » →
     * « 18e blanche/jaune ». Poom et Dan : le rang suffit (« Il Poom », « 1er Dan »).
     */
    public static function format_grade_claira( $keup, $titre ) {
        $keup = trim( (string) $keup );
        $t    = mb_strtolower( trim( wp_specialchars_decode( (string) $titre ) ) );
        $t    = preg_replace( '/\s*\/\s*/', '/', $t );
        $t    = preg_replace( '/\s*\((\*+)\)/', '$1', $t );
        if ( preg_match( '/^\d+e$/', $keup ) ) return $keup . ' ' . $t;
        return $keup !== '' ? $keup : $t;
    }

    /**
     * Grades de TKD Parcours par catégorie d'âge sp-build, dans l'ordre du schéma des grades.
     * Adolescent et Adulte (TKD Parcours) partagent la même liste → une seule clé « Ado/adulte »
     * (utilisée aussi pour la catégorie sp-build « Adulte »).
     *
     * @return array  [ 'Baby' => [...], 'Enfant' => [...], 'Ado/adulte' => [...] ] ; vide si
     *                TKD Parcours est inactif ou n'a aucun grade.
     */
    public function get_grades_claira() {
        if ( $this->grades_claira_cache !== null ) return $this->grades_claira_cache;
        $result = array();
        foreach ( $this->get_parcours_claira() as $cat => $grades ) {
            $result[ $cat ] = array_column( $grades, 'grade' );
        }
        return $this->grades_claira_cache = $result;
    }

    /**
     * Même chaîne que get_grades_claira(), avec le programme de chaque grade lu dans TKD Parcours
     * (module Passages de grade : le programme du grade visé est la grille d'examen).
     *
     * @return array  [ 'Baby' => [ [ 'grade', 'post_id', 'min_age' (int|null), 'poomsae',
     *                'tech_bras', 'tech_jambes', 'video_url' ], … ], 'Enfant' => …, 'Ado/adulte' => … ]
     */
    public function get_parcours_claira() {
        if ( $this->parcours_claira_cache !== null ) return $this->parcours_claira_cache;
        if ( ! $this->claira_grades_actif() ) return $this->parcours_claira_cache = array();

        $groupes = array(
            'Baby'       => array( 'termes' => array( 'Baby' ),               'ordre' => 'Baby' ),
            'Enfant'     => array( 'termes' => array( 'Enfant' ),             'ordre' => 'Enfant' ),
            'Ado/adulte' => array( 'termes' => array( 'Adolescent', 'Adulte' ), 'ordre' => 'Adolescent' ),
        );
        $result = array();
        foreach ( $groupes as $cat => $groupe ) {
            $term_ids = array();
            foreach ( $groupe['termes'] as $nom_terme ) {
                $term = get_term_by( 'name', $nom_terme, 'tkd_age_group' );
                if ( $term && ! is_wp_error( $term ) ) $term_ids[] = (int) $term->term_id;
            }
            if ( ! $term_ids ) continue;

            $posts = get_posts( array(
                'post_type'      => 'tkd_grade',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'tax_query'      => array( array(
                    'taxonomy' => 'tkd_age_group',
                    'field'    => 'term_id',
                    'terms'    => $term_ids,
                ) ),
            ) );
            $chain = array();
            $vus   = array();
            foreach ( claira_tkd_sort_grades_by_keup( $posts, $groupe['ordre'] ) as $p ) {
                $g = self::format_grade_claira( get_post_meta( $p->ID, '_claira_tkd_keup_rank', true ), $p->post_title );
                if ( $g === '' || in_array( $g, $vus, true ) ) continue;
                $vus[] = $g;
                // Âge minimum conseillé : « 14+ » → 14 ; vide → pas d'âge minimum.
                $min_age = preg_match( '/\d+/', (string) get_post_meta( $p->ID, '_claira_tkd_min_age', true ), $m ) ? intval( $m[0] ) : null;
                $chain[] = array(
                    'grade'       => $g,
                    'post_id'     => (int) $p->ID,
                    'min_age'     => $min_age,
                    'poomsae'     => trim( (string) get_post_meta( $p->ID, '_claira_tkd_poomsae', true ) ),
                    'tech_bras'   => trim( (string) get_post_meta( $p->ID, '_claira_tkd_tech_bras', true ) ),
                    'tech_jambes' => trim( (string) get_post_meta( $p->ID, '_claira_tkd_tech_jambes', true ) ),
                    'video_url'   => trim( (string) get_post_meta( $p->ID, '_claira_tkd_video_url', true ) ),
                );
            }
            if ( $chain ) $result[ $cat ] = $chain;
        }
        return $this->parcours_claira_cache = $result;
    }

    /**
     * Grade suivant d'un élève (fiche adhérent, fiche imprimée, application, saisie d'un
     * résultat d'examen) : le grade d'après dans le référentiel — TKD Parcours s'il est actif,
     * sinon l'ancienne table de progression (get_grades_referentiel()). '' si le grade actuel
     * n'y figure pas. Rebranché le 03/10/2026 avec la suppression de l'ancien module Jury.
     */
    public function get_grade_vise_eleve( $eleve ) {
        $ref = $this->get_grades_referentiel();
        $cat = (string) ( $eleve->categorie_age ?? '' );
        $c   = mb_strtolower( $cat );
        $cle = strpos( $c, 'baby' ) !== false ? 'Baby'
             : ( strpos( $c, 'enfant' ) !== false ? 'Enfant'
             : ( ( strpos( $c, 'ado' ) !== false || strpos( $c, 'adulte' ) !== false ) ? 'Ado/adulte' : $cat ) );
        $chaine = $ref[ $cat ] ?? $ref[ $cle ] ?? array();
        $actuel = self::cle_grade( (string) ( $eleve->grade ?? '' ) );
        if ( $actuel === '' ) return '';
        foreach ( $chaine as $i => $g ) {
            if ( self::cle_grade( $g ) === $actuel ) return $chaine[ $i + 1 ] ?? '';
        }
        return '';
    }

    /** Clé de comparaison d'un grade : minuscules, sans espaces, « 1er » = « 1e », « ° » = « e ». */
    public static function cle_grade( $g ) {
        $g = mb_strtolower( trim( (string) $g ) );
        $g = str_replace( '°', 'e', $g );
        $g = preg_replace( '/^(\d+)\s*(er|ère|ème|eme)\b/u', '$1e', $g );
        return preg_replace( '/\s+/u', '', $g );
    }

    /** Ceinture d'un grade sans son rang, pour rapprocher deux numérotations : « 12e jaune* » → « jaune* ». */
    private static function grade_sans_rang( $grade ) {
        $g = mb_strtolower( trim( (string) $grade ) );
        $g = preg_replace( '/^\d+\s*(e|er|ère|ème|eme)?\s+/u', '', $g );
        return preg_replace( '/\s+/', '', $g );
    }

    /**
     * Aperçu de l'harmonisation des grades des élèves avec TKD Parcours : élèves dont le grade
     * n'existe pas dans le référentiel TKD Parcours, avec le grade proposé (même ceinture dans la
     * liste de leur catégorie d'âge) — vide si aucune correspondance sûre, à choisir à la main.
     */
    public function preview_harmonisation_grades() {
        global $wpdb;
        $ref = $this->get_grades_claira();
        if ( ! $ref ) return array();
        $tous = array_merge( ...array_values( $ref ) );

        $eleves = $wpdb->get_results( "SELECT id, nom, prenom, grade, categorie_age FROM {$this->table_eleves()} WHERE grade != '' ORDER BY nom, prenom" );
        $out = array();
        foreach ( $eleves as $el ) {
            if ( in_array( $el->grade, $tous, true ) ) continue;   // déjà au référentiel TKD Parcours

            $cat   = $el->categorie_age === 'Adulte' ? 'Ado/adulte' : $el->categorie_age;
            $chain = $ref[ $cat ] ?? array();
            $cle   = self::grade_sans_rang( $el->grade );
            $propose = '';
            foreach ( $chain as $g ) {
                if ( self::grade_sans_rang( $g ) === $cle ) { $propose = $g; break; }
            }
            $out[] = array(
                'id'      => (int) $el->id,
                'nom'     => trim( $el->prenom . ' ' . $el->nom ),
                'cat'     => $el->categorie_age,
                'cat_ref' => isset( $ref[ $cat ] ) ? $cat : '',
                'actuel'  => $el->grade,
                'propose' => $propose,
            );
        }
        return $out;
    }

    /**
     * Applique l'harmonisation : $choix = [ eleve_id => nouveau grade ] (grade vide = inchangé ;
     * seul un grade du référentiel TKD Parcours est accepté), puis recalcule la table de
     * progression des examens depuis TKD Parcours pour que « grade suivant » reste cohérent.
     *
     * @return int  nombre d'élèves mis à jour
     */
    public function appliquer_harmonisation_grades( array $choix ) {
        global $wpdb;
        $ref = $this->get_grades_claira();
        if ( ! $ref ) return 0;
        $tous = array_merge( ...array_values( $ref ) );

        $nb = 0;
        foreach ( $choix as $id => $grade ) {
            $grade = sanitize_text_field( $grade );
            if ( $grade === '' || ! in_array( $grade, $tous, true ) ) continue;
            $nb += (int) $wpdb->update( $this->table_eleves(), array( 'grade' => $grade ), array( 'id' => intval( $id ) ) );
        }
        $this->regenerer_progression_depuis_claira();
        return $nb;
    }

    /**
     * Réécrit la table de progression des examens (Jury → Grades) pour Baby, Enfant, Ado/adulte
     * et Adulte à partir de TKD Parcours : chaque grade → le suivant dans la liste. Les lignes des
     * autres catégories (ou sans catégorie) ne sont pas touchées.
     */
    /** La table de progression des examens suit-elle déjà l'ordre des grades de TKD Parcours ? */
    public function progression_a_jour_claira() {
        global $wpdb;
        $ref = $this->get_grades_claira();
        if ( ! $ref ) return true;
        if ( isset( $ref['Ado/adulte'] ) ) $ref['Adulte'] = $ref['Ado/adulte'];

        $t    = $this->table_exam_grade_progression();
        $rows = $wpdb->get_results( "SELECT grade_actuel, grade_suivant, categorie_age FROM $t" );
        $existant = array();
        foreach ( $rows as $r ) $existant[ $r->categorie_age . '|' . $r->grade_actuel ] = $r->grade_suivant;

        foreach ( $ref as $cat => $chain ) {
            for ( $i = 0; $i < count( $chain ) - 1; $i++ ) {
                if ( ( $existant[ $cat . '|' . $chain[ $i ] ] ?? null ) !== $chain[ $i + 1 ] ) return false;
            }
        }
        return true;
    }

    public function regenerer_progression_depuis_claira() {
        global $wpdb;
        $ref = $this->get_grades_claira();
        if ( ! $ref ) return;
        if ( isset( $ref['Ado/adulte'] ) ) $ref['Adulte'] = $ref['Ado/adulte'];

        $t = $this->table_exam_grade_progression();
        foreach ( $ref as $cat => $chain ) {
            $wpdb->delete( $t, array( 'categorie_age' => $cat ) );
            for ( $i = 0; $i < count( $chain ) - 1; $i++ ) {
                $wpdb->insert( $t, array(
                    'grade_actuel'  => $chain[ $i ],
                    'grade_suivant' => $chain[ $i + 1 ],
                    'categorie_age' => $cat,
                ) );
            }
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
    /**
     * Agenda d'un adhérent (fiche ?token= et PWA, décision du 26/09/2026) : TOUS les
     * événements du club des $jours prochains jours — hors cours, anniversaires et
     * annulations de cours — avec pour chacun :
     *   - concerne    : l'événement vise sa discipline et sa tranche d'âge (ou tout le club),
     *                   ou il est éligible à ses inscriptions ;
     *   - inscription : inscriptions ouvertes (activées + envoyées) ET adhérent éligible ;
     *   - statut_insc : sa réponse (inscrit / refuse / en_attente).
     * L'éligibilité reprend la logique de SpCalPro_Admin::rest_pwa_eleve_evenements()
     * (18/09/2026) : discipline ET tranche d'âge ciblées, ou sélection manuelle exclusive.
     */
    public function get_agenda_eleve( $eleve_id, $jours = 92 ) {
        global $wpdb;
        $te  = $this->table_events();
        $ti  = $this->table_event_inscriptions();
        $eid = intval( $eleve_id );

        $eleve = $wpdb->get_row( $wpdb->prepare(
            "SELECT categorie_saisie, categorie_age FROM {$this->table_eleves()} WHERE id = %d", $eid
        ) );
        $cat_saisie = trim( $eleve->categorie_saisie ?? '' );
        $cat_age    = trim( $eleve->categorie_age    ?? '' );

        $debut = current_time( 'Y-m-d' );
        $fin   = date( 'Y-m-d', strtotime( $debut . ' +' . intval( $jours ) . ' days' ) );
        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, COALESCE(i.statut, 'en_attente') AS statut_insc
             FROM $te e
             LEFT JOIN $ti i ON i.event_id = e.id AND i.eleve_id = %d
             WHERE e.date BETWEEN %s AND %s
               AND e.type NOT IN ('cours','anniversaire','annulation')
             ORDER BY e.date ASC, e.heure_debut ASC",
            $eid, $debut, $fin
        ) );

        foreach ( (array) $events as $ev ) {
            $insc_ouverte = ! empty( $ev->inscriptions_actives ) && ! empty( $ev->inscriptions_envoye );
            $ev->inscription = $insc_ouverte && $this->eleve_eligible_inscription( $ev, $eid, $cat_saisie, $cat_age );
            $ev->concerne    = $ev->inscription || $this->evenement_concerne( $ev, $cat_saisie, $cat_age );
        }
        return (array) $events;
    }

    /** Éligibilité aux inscriptions d'un événement (cf. rest_pwa_eleve_evenements()). */
    private function eleve_eligible_inscription( $ev, $eid, $cat_saisie, $cat_age ) {
        $cats  = array_filter( array_map( 'trim', explode( ',', $ev->inscriptions_categories     ?? '' ) ) );
        $ages  = array_filter( array_map( 'trim', explode( ',', $ev->inscriptions_age_categories ?? '' ) ) );
        $extra = array_filter( array_map( 'intval', explode( ',', $ev->inscriptions_extra_eleves ?? '' ) ) );
        if ( in_array( intval( $eid ), $extra, true ) ) return true;
        if ( empty( $cats ) && empty( $ages ) && ! empty( $extra ) ) return false; // ciblage exclusivement manuel
        $disc_ok = empty( $cats ) || ( $cat_saisie !== '' && in_array( $cat_saisie, $cats, true ) );
        $age_ok  = empty( $ages ) || ( $cat_age    !== '' && in_array( $cat_age,    $ages, true ) );
        return $disc_ok && $age_ok;
    }

    /** Valeurs des deux axes d'un cours (mêmes listes que CAL.COURS_DISCIPLINES / CAL.COURS_AGES). */
    const COURS_DISCIPLINES = array( 'Taekwondo', 'Renforcement musculaire', 'Autre' );
    const COURS_AGES        = array( 'Baby', 'Enfant', 'Ado/adulte', 'Adulte', 'Tout âge' );

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
}

endif; // class_exists SpCalPro_DB

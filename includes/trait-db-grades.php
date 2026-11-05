<?php
/**
 * Requêtes « grades et examens » : référentiel, TKD Parcours (Claira), passages d'examen, harmonisation et progression des grades.
 *
 * Méthodes de SpCalPro_DB déplacées telles quelles depuis class-db.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_DB_Grades; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_DB_Grades {


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
}

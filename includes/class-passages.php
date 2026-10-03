<?php
/**
 * Passages de grade — module de notation (spécification validée le 03/10/2026, EVOLUTION.md).
 *
 * Principe : le programme du grade visé dans TKD Parcours EST la grille d'examen (poomsae,
 * technique de bras, technique de jambes), complété par des épreuves transverses libres.
 * Trois moments au lieu des 7 étapes de l'ancien module Jury :
 *   1. Préparer (wp-admin, un seul écran) : date, candidats (alerte d'âge, jamais bloquante),
 *      juges (entraîneurs + adhérents Poom/Dan), président, réglages Poom.
 *   2. Noter (page mobile du juge, lien personnel / QR, sans compte WordPress, hors ligne) :
 *      keup → Acquis / À revoir / Non acquis ; Poom → note sur 10.
 *   3. Valider (président ou admin) : verdicts proposés, égalités tranchées, « Valider les
 *      grades » écrit le grade sur les fiches (même écriture que l'ancien module :
 *      exam_passages + eleves.grade + note sur la présence à l'examen).
 *
 * Les Dan ne sont jamais un grade visé (examen hors club). Le verdict est calculé dans une
 * seule fonction (resultat_candidat()), seule source de vérité ; la page du juge n'en
 * recalcule aucun.
 *
 * Tables propres au module (garde de version OPT_SCHEMA, sur le modèle de
 * SP_Front_Adhesion::maybe_create_table()) :
 *   sp_cal_passages             un passage = une date (+ son événement « examen » du calendrier)
 *   sp_cal_passage_candidats    élève × passage, grade visé, grille figée (criteres, JSON)
 *   sp_cal_passage_juges        juge × passage, jeton personnel, président
 *   sp_cal_passage_epreuves     épreuves transverses (globales, réutilisées d'un passage à l'autre)
 *   sp_cal_passage_evaluations  une ligne par candidat × juge × critère (mise à jour sur place)
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Passages {

	private static ?self $instance = null;
	private $db;

	const PAGE       = 'sp-cal-passages';
	const CAP        = 'manage_options';
	const SCHEMA     = '1';
	const OPT_SCHEMA = 'sp_cal_passages_schema';

	// Niveaux d'un critère keup
	const NON    = 0;
	const REVOIR = 1;
	const ACQUIS = 2;

	const STATUTS = [ 'preparation' => 'En préparation', 'ouvert' => 'Notation ouverte', 'valide' => 'Validé' ];

	public static function get_instance( $db = null ): self {
		if ( self::$instance === null ) self::$instance = new self( $db );
		return self::$instance;
	}

	private function __construct( $db = null ) {
		$this->db = $db;

		add_action( 'init', [ __CLASS__, 'maybe_create_tables' ] );
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );

		foreach ( [ 'creer', 'preparer', 'statut', 'supprimer', 'epreuve', 'epreuve_suppr' ] as $a ) {
			add_action( 'admin_post_sp_passage_' . $a, [ $this, 'handle_' . $a ] );
		}

		// Page du juge / écran de validation (hors thème)
		add_action( 'template_redirect', [ $this, 'maybe_render_app' ], 1 );

		// Appels de la page du juge : jeton personnel (juges sans compte) ou admin connecté.
		foreach ( [ 'data', 'noter', 'resultats', 'arbitrer', 'valider' ] as $a ) {
			add_action( 'wp_ajax_sp_passage_' . $a,        [ $this, 'ajax_' . $a ] );
			add_action( 'wp_ajax_nopriv_sp_passage_' . $a, [ $this, 'ajax_' . $a ] );
		}
	}

	// ══════════════════════════════════════════════════════════════════════
	// SCHÉMA
	// ══════════════════════════════════════════════════════════════════════

	private static function t( string $nom ): string {
		global $wpdb;
		return $wpdb->prefix . 'sp_cal_' . $nom;
	}

	public static function maybe_create_tables(): void {
		if ( get_option( self::OPT_SCHEMA ) === self::SCHEMA ) return;
		global $wpdb;
		$c = $wpdb->get_charset_collate();

		$wpdb->query( "CREATE TABLE IF NOT EXISTS " . self::t( 'passages' ) . " (
			id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id      INT UNSIGNED NOT NULL DEFAULT 0,
			date          DATE         NOT NULL,
			titre         VARCHAR(200) NOT NULL DEFAULT '',
			statut        VARCHAR(20)  NOT NULL DEFAULT 'preparation',
			poom_seuil    DECIMAL(4,2) NOT NULL DEFAULT 5.00,
			poom_plancher DECIMAL(4,2) NOT NULL DEFAULT 4.00,
			created_at    DATETIME     NOT NULL,
			valide_at     DATETIME     DEFAULT NULL,
			valide_par    VARCHAR(150) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY idx_date (date)
		) $c" );

		$wpdb->query( "CREATE TABLE IF NOT EXISTS " . self::t( 'passage_candidats' ) . " (
			id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
			passage_id   INT UNSIGNED NOT NULL,
			eleve_id     INT UNSIGNED NOT NULL,
			categorie    VARCHAR(20)  NOT NULL DEFAULT '',
			grade_actuel VARCHAR(100) NOT NULL DEFAULT '',
			grade_vise   VARCHAR(100) NOT NULL DEFAULT '',
			mode         VARCHAR(10)  NOT NULL DEFAULT 'keup',
			alerte_age   TINYINT UNSIGNED NOT NULL DEFAULT 0,
			criteres     LONGTEXT,
			arbitrages   LONGTEXT,
			decision     VARCHAR(10)  NOT NULL DEFAULT '',
			grade_obtenu VARCHAR(100) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			UNIQUE KEY passage_eleve (passage_id, eleve_id),
			KEY idx_eleve (eleve_id)
		) $c" );

		$wpdb->query( "CREATE TABLE IF NOT EXISTS " . self::t( 'passage_juges' ) . " (
			id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
			passage_id INT UNSIGNED NOT NULL,
			origine    VARCHAR(12)  NOT NULL DEFAULT 'entraineur',
			ref_id     INT UNSIGNED NOT NULL DEFAULT 0,
			nom        VARCHAR(150) NOT NULL DEFAULT '',
			president  TINYINT(1)   NOT NULL DEFAULT 0,
			token      VARCHAR(40)  NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY token (token),
			UNIQUE KEY passage_juge (passage_id, origine, ref_id)
		) $c" );

		$wpdb->query( "CREATE TABLE IF NOT EXISTS " . self::t( 'passage_epreuves' ) . " (
			id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
			nom           VARCHAR(150) NOT NULL DEFAULT '',
			portee        VARCHAR(12)  NOT NULL DEFAULT 'tous',
			portee_valeur TEXT,
			type          VARCHAR(10)  NOT NULL DEFAULT 'niveaux',
			seuil_acquis  DECIMAL(8,2) DEFAULT NULL,
			seuil_revoir  DECIMAL(8,2) DEFAULT NULL,
			unite         VARCHAR(30)  NOT NULL DEFAULT '',
			ordre         INT          NOT NULL DEFAULT 0,
			actif         TINYINT(1)   NOT NULL DEFAULT 1,
			PRIMARY KEY (id)
		) $c" );

		$wpdb->query( "CREATE TABLE IF NOT EXISTS " . self::t( 'passage_evaluations' ) . " (
			id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
			candidat_id INT UNSIGNED NOT NULL,
			juge_id     INT UNSIGNED NOT NULL,
			critere     VARCHAR(40)  NOT NULL,
			niveau      TINYINT      DEFAULT NULL,
			valeur      DECIMAL(8,2) DEFAULT NULL,
			remarque    TEXT,
			updated_at  DATETIME     NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY cand_juge_crit (candidat_id, juge_id, critere),
			KEY idx_juge (juge_id)
		) $c" );

		// Ne marquer le schéma à jour que si toutes les tables existent (droits SQL limités
		// chez l'hébergeur : on retentera au prochain chargement plutôt que de croire que c'est fait).
		foreach ( [ 'passages', 'passage_candidats', 'passage_juges', 'passage_epreuves', 'passage_evaluations' ] as $n ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::t( $n ) ) ) !== self::t( $n ) ) return;
		}
		update_option( self::OPT_SCHEMA, self::SCHEMA );
	}

	// ══════════════════════════════════════════════════════════════════════
	// LECTURES
	// ══════════════════════════════════════════════════════════════════════

	private function get_passage( int $id ) {
		global $wpdb;
		return $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'passages' ) . ' WHERE id = %d', $id ) ) : null;
	}

	/** @return object[] candidats du passage, avec nom / prénom de l'élève, triés par catégorie puis nom */
	private function get_candidats( int $passage_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT c.*, e.nom, e.prenom FROM ' . self::t( 'passage_candidats' ) . ' c
			 LEFT JOIN ' . $this->db->table_eleves() . ' e ON e.id = c.eleve_id
			 WHERE c.passage_id = %d', $passage_id
		) );
		$ordre_cat = [ 'Baby' => 0, 'Enfant' => 1, 'Ado/adulte' => 2 ];
		usort( $rows, static function ( $a, $b ) use ( $ordre_cat ) {
			$ca = $ordre_cat[ $a->categorie ] ?? 9;
			$cb = $ordre_cat[ $b->categorie ] ?? 9;
			return $ca <=> $cb ?: strcasecmp( $a->nom . ' ' . $a->prenom, $b->nom . ' ' . $b->prenom );
		} );
		foreach ( $rows as $r ) {
			$r->criteres   = json_decode( (string) $r->criteres, true ) ?: [];
			$r->arbitrages = json_decode( (string) $r->arbitrages, true ) ?: [];
		}
		return $rows;
	}

	private function get_juges( int $passage_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::t( 'passage_juges' ) . ' WHERE passage_id = %d ORDER BY president DESC, nom ASC', $passage_id
		) );
	}

	private function get_epreuves( bool $actives_seulement = false ): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::t( 'passage_epreuves' )
			. ( $actives_seulement ? ' WHERE actif = 1' : '' ) . ' ORDER BY ordre ASC, nom ASC' );
	}

	/** Élèves concernés par les grades : actifs, hors renforcement musculaire. */
	private function get_eleves_tkd(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $this->db->table_eleves() . ' WHERE actif = 1 ORDER BY nom ASC, prenom ASC' );
		return array_values( array_filter( $rows, static function ( $el ) {
			return ! preg_match( '/renfo|renforcement/i', (string) ( $el->categorie_saisie ?? '' ) );
		} ) );
	}

	// ══════════════════════════════════════════════════════════════════════
	// PARCOURS : catégorie, grade visé, âge, grille
	// ══════════════════════════════════════════════════════════════════════

	/** Catégorie sp-build → chaîne de TKD Parcours (Baby / Enfant / Ado/adulte), '' si aucune. */
	private static function cat_parcours( string $categorie_age ): string {
		$c = mb_strtolower( $categorie_age );
		if ( strpos( $c, 'baby' ) !== false )   return 'Baby';
		if ( strpos( $c, 'enfant' ) !== false ) return 'Enfant';
		if ( strpos( $c, 'ado' ) !== false || strpos( $c, 'adulte' ) !== false ) return 'Ado/adulte';
		return '';
	}

	/** Clé de comparaison d'un grade : minuscules, sans espaces, « 1er » = « 1e », « ° » = « e ». */
	private static function cle_grade( string $g ): string {
		return SpCalPro_DB::cle_grade( $g );
	}

	public static function est_dan( string $g ): bool  { return (bool) preg_match( '/\bdan\b/iu', $g ); }
	public static function est_poom( string $g ): bool { return (bool) preg_match( '/\bpoom\b/iu', $g ); }

	/** Chaîne du Parcours pour une catégorie : [ [ 'grade', 'min_age', 'poomsae', … ], … ] */
	private function chaine( string $cat ): array {
		$p = $this->db->get_parcours_claira();
		return $p[ $cat ] ?? [];
	}

	/** Position d'un grade dans la chaîne, -1 si absent. */
	private static function position( array $chaine, string $grade ): int {
		$cle = self::cle_grade( $grade );
		if ( $cle === '' ) return -1;
		foreach ( $chaine as $i => $g ) {
			if ( self::cle_grade( $g['grade'] ) === $cle ) return $i;
		}
		return -1;
	}

	private function entree_grade( string $cat, string $grade ): ?array {
		$ch = $this->chaine( $cat );
		$i  = self::position( $ch, $grade );
		return $i >= 0 ? $ch[ $i ] : null;
	}

	/**
	 * Grade visé par défaut : le grade suivant dans le Parcours ; un élève sans grade vise le
	 * deuxième de la chaîne (le premier est la ceinture de départ). '' si inconnu ou si le
	 * suivant est un Dan (examen hors club).
	 */
	private function grade_vise_defaut( $el ): string {
		$ch = $this->chaine( self::cat_parcours( (string) $el->categorie_age ) );
		if ( ! $ch ) return '';
		$i = trim( (string) $el->grade ) === '' ? 0 : self::position( $ch, (string) $el->grade );
		if ( $i < 0 || ! isset( $ch[ $i + 1 ] ) ) return '';
		$suivant = $ch[ $i + 1 ]['grade'];
		return self::est_dan( $suivant ) ? '' : $suivant;
	}

	/**
	 * Âge à une date (Y-m-d). annee_naissance porte l'année ; date_naissance « JJ/MM » (ou
	 * « JJ/MM/AAAA » sur d'anciennes fiches) sert à savoir si l'anniversaire est passé.
	 */
	private static function age_a( $el, string $date ): ?int {
		$an = intval( $el->annee_naissance ?? 0 );
		$dn = (string) ( $el->date_naissance ?? '' );
		if ( ! $an && preg_match( '#^\d{1,2}/\d{1,2}/(\d{4})$#', $dn, $m ) ) $an = intval( $m[1] );
		if ( ! $an ) return null;
		$ts  = strtotime( $date ) ?: current_time( 'timestamp' );
		$age = intval( gmdate( 'Y', $ts ) ) - $an;
		if ( preg_match( '#^(\d{1,2})/(\d{1,2})#', $dn, $m ) && sprintf( '%02d%02d', $m[2], $m[1] ) > gmdate( 'md', $ts ) ) $age--;
		return $age >= 0 ? $age : null;
	}

	/** Nombre d'années manquantes par rapport à l'âge minimum conseillé du grade visé (0 = pas d'alerte). */
	private function alerte_age( $el, string $cat, string $grade_vise, string $date ): int {
		$g   = $this->entree_grade( $cat, $grade_vise );
		$age = self::age_a( $el, $date );
		if ( ! $g || $g['min_age'] === null || $age === null ) return 0;
		return max( 0, $g['min_age'] - $age );
	}

	/**
	 * Grille d'un candidat : programme du grade visé (Parcours) + épreuves transverses qui le
	 * concernent. Figée sur le candidat (colonne criteres) pour qu'une modification du Parcours
	 * pendant l'examen ne change pas ce que les juges sont en train de noter.
	 */
	private function grille( string $cat, string $grade_vise ): array {
		$out = [];
		$g   = $this->entree_grade( $cat, $grade_vise );
		foreach ( $g ? self::programme( $g ) : [] as $p ) {
			$out[] = $p + [ 'type' => 'niveaux', 'seuil_acquis' => null, 'seuil_revoir' => null, 'unite' => '' ];
		}
		$cle_vise = self::cle_grade( $grade_vise );
		foreach ( $this->get_epreuves( true ) as $ep ) {
			if ( $ep->portee === 'categorie' && $ep->portee_valeur !== $cat ) continue;
			if ( $ep->portee === 'grades' ) {
				$liste = array_map( [ __CLASS__, 'cle_grade' ], (array) ( json_decode( (string) $ep->portee_valeur, true ) ?: [] ) );
				if ( ! in_array( $cle_vise, $liste, true ) ) continue;
			}
			$out[] = [
				'cle'          => 'e:' . intval( $ep->id ),
				'libelle'      => $ep->nom,
				'detail'       => '',
				'type'         => $ep->type,
				'seuil_acquis' => $ep->seuil_acquis !== null ? floatval( $ep->seuil_acquis ) : null,
				'seuil_revoir' => $ep->seuil_revoir !== null ? floatval( $ep->seuil_revoir ) : null,
				'unite'        => $ep->unite,
			];
		}
		return $out;
	}

	/** Programme d'un grade du Parcours : [ [ 'cle', 'libelle', 'detail' ], … ] (champs vides ignorés). */
	private static function programme( array $g ): array {
		$out = [];
		foreach ( [ 'poomsae' => 'Poomsae', 'tech_bras' => 'Bras', 'tech_jambes' => 'Jambes' ] as $champ => $libelle ) {
			$txt = trim( preg_replace( '/\s*[\r\n]+\s*/', ' / ', (string) $g[ $champ ] ) );
			if ( $txt !== '' ) $out[] = [ 'cle' => 'p:' . $champ, 'libelle' => $libelle, 'detail' => $txt ];
		}
		return $out;
	}

	private function maj_grille_candidat( $cand ): void {
		global $wpdb;
		$wpdb->update( self::t( 'passage_candidats' ),
			[ 'criteres' => wp_json_encode( $this->grille( $cand->categorie, $cand->grade_vise ) ) ],
			[ 'id' => intval( $cand->id ) ] );
	}

	// ══════════════════════════════════════════════════════════════════════
	// CALCUL DU VERDICT — seule source de vérité
	// ══════════════════════════════════════════════════════════════════════

	/** Niveau keup d'une évaluation : direct (niveaux) ou converti par les seuils (note, mesure). */
	private static function niveau_eval( array $crit, $ev ): ?int {
		if ( $crit['type'] === 'niveaux' ) return $ev->niveau !== null ? intval( $ev->niveau ) : null;
		if ( $ev->valeur === null ) return null;
		$v = floatval( $ev->valeur );
		$a = $crit['seuil_acquis'] ?? ( $crit['type'] === 'note' ? 5 : null );
		$r = $crit['seuil_revoir'] ?? ( $crit['type'] === 'note' ? 4 : null );
		if ( $a === null ) return null;
		if ( $v >= $a ) return self::ACQUIS;
		if ( $r !== null && $v >= $r ) return self::REVOIR;
		return self::NON;
	}

	/** Note sur 10 (Poom) : saisie directe, ou mesure ramenée sur 10 (seuil « acquis » = 10/10). */
	private static function note_eval( array $crit, $ev ): ?float {
		if ( $ev->valeur === null ) return null;
		$v = floatval( $ev->valeur );
		if ( $crit['type'] !== 'mesure' ) return max( 0, min( 10, $v ) );
		$a = $crit['seuil_acquis'] ?? null;
		return $a ? round( max( 0, min( 10, $v / $a * 10 ) ), 2 ) : null;
	}

	/**
	 * Résultat d'un candidat à partir des évaluations de tous les juges.
	 *   keup : par critère, l'avis majoritaire ; égalité → tranchée par le président (arbitrages),
	 *          sinon le critère reste « égalité ». Admis si aucun « Non acquis » et au plus un
	 *          « À revoir ».
	 *   Poom : par critère, moyenne des juges ; admis si moyenne générale ≥ seuil et aucune
	 *          épreuve sous le plancher.
	 * Un critère sans évaluation ou une égalité non tranchée → proposition « incomplet ».
	 *
	 * @param object   $cand   candidat (criteres et arbitrages décodés)
	 * @param object[] $evals  évaluations du candidat (tous juges)
	 * @param array    $noms   juge_id => nom
	 */
	private function resultat_candidat( $cand, array $evals, $passage, array $noms ): array {
		$par_crit  = [];
		$remarques = [];
		foreach ( $evals as $ev ) {
			if ( $ev->critere === '_remarque' ) {
				if ( trim( (string) $ev->remarque ) !== '' ) $remarques[] = [ 'juge' => $noms[ $ev->juge_id ] ?? 'Juge', 'texte' => $ev->remarque ];
				continue;
			}
			$par_crit[ $ev->critere ][] = $ev;
		}

		$lignes  = [];
		$complet = true;
		$poom    = $cand->mode === 'poom';
		$nb_non  = 0; $nb_revoir = 0; $moyennes = [];

		foreach ( $cand->criteres as $crit ) {
			$ligne = [ 'cle' => $crit['cle'], 'libelle' => $crit['libelle'], 'detail' => $crit['detail'] ?? '', 'votes' => [], 'statut' => 'ok' ];
			foreach ( $par_crit[ $crit['cle'] ] ?? [] as $ev ) {
				$val = $poom ? self::note_eval( $crit, $ev ) : self::niveau_eval( $crit, $ev );
				if ( $val === null ) continue;
				$ligne['votes'][] = [ 'juge' => $noms[ $ev->juge_id ] ?? 'Juge', 'valeur' => $val, 'brut' => $ev->valeur !== null ? floatval( $ev->valeur ) : null ];
			}
			$vals = array_column( $ligne['votes'], 'valeur' );

			if ( ! $vals ) {
				$ligne['statut'] = 'manquant';
				$complet = false;
			} elseif ( $poom ) {
				$ligne['moyenne'] = round( array_sum( $vals ) / count( $vals ), 2 );
				$moyennes[]       = $ligne['moyenne'];
				$ligne['desaccord'] = ( max( $vals ) - min( $vals ) ) >= 2;
			} else {
				$compte = array_count_values( array_map( 'intval', $vals ) );
				$max    = max( $compte );
				$tetes  = array_keys( array_filter( $compte, static fn( $n ) => $n === $max ) );
				$ligne['desaccord'] = count( $compte ) > 1;
				if ( count( $tetes ) === 1 ) {
					$ligne['niveau'] = intval( $tetes[0] );
				} elseif ( isset( $cand->arbitrages[ $crit['cle'] ] ) ) {
					$ligne['niveau']  = intval( $cand->arbitrages[ $crit['cle'] ] );
					$ligne['arbitre'] = true;
				} else {
					$ligne['statut'] = 'egalite';
					$ligne['candidats_egalite'] = array_map( 'intval', $tetes );
					$complet = false;
				}
				if ( isset( $ligne['niveau'] ) ) {
					if ( $ligne['niveau'] === self::NON )    $nb_non++;
					if ( $ligne['niveau'] === self::REVOIR ) $nb_revoir++;
				}
			}
			$lignes[] = $ligne;
		}

		$res = [ 'criteres' => $lignes, 'remarques' => $remarques, 'proposition' => 'incomplet' ];
		if ( ! $cand->criteres ) {
			$res['raison'] = 'Aucun critère : grade visé absent du Parcours et aucune épreuve transverse.';
			return $res;
		}
		if ( ! $complet ) return $res;

		if ( $poom ) {
			$seuil    = floatval( $passage->poom_seuil );
			$plancher = floatval( $passage->poom_plancher );
			$moy      = round( array_sum( $moyennes ) / count( $moyennes ), 2 );
			$res['moyenne'] = $moy;
			$res['proposition'] = ( $moy >= $seuil && min( $moyennes ) >= $plancher ) ? 'admis' : 'ajourne';
			$res['raison'] = $res['proposition'] === 'admis'
				? sprintf( 'Moyenne %s/10 ≥ %s, aucune épreuve sous %s.', self::nb( $moy ), self::nb( $seuil ), self::nb( $plancher ) )
				: ( $moy < $seuil ? sprintf( 'Moyenne %s/10 < %s.', self::nb( $moy ), self::nb( $seuil ) )
				                  : sprintf( 'Une épreuve sous le plancher (%s/10).', self::nb( $plancher ) ) );
		} else {
			$res['proposition'] = ( $nb_non === 0 && $nb_revoir <= 1 ) ? 'admis' : 'ajourne';
			$res['raison'] = sprintf( '%d non acquis, %d à revoir.', $nb_non, $nb_revoir );
		}
		return $res;
	}

	private static function nb( $v ): string {
		return rtrim( rtrim( number_format( (float) $v, 2, ',', '' ), '0' ), ',' );
	}

	/** Résultats de tous les candidats d'un passage (écran de validation). */
	private function resultats( $passage ): array {
		global $wpdb;
		$cands = $this->get_candidats( intval( $passage->id ) );
		$noms  = [];
		foreach ( $this->get_juges( intval( $passage->id ) ) as $j ) $noms[ intval( $j->id ) ] = $j->nom;

		$evals_par_cand = [];
		if ( $cands ) {
			$ids  = implode( ',', array_map( 'intval', array_column( $cands, 'id' ) ) );
			foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::t( 'passage_evaluations' ) . " WHERE candidat_id IN ($ids)" ) as $ev ) {
				$ev->juge_id = intval( $ev->juge_id );
				$evals_par_cand[ intval( $ev->candidat_id ) ][] = $ev;
			}
		}
		$out = [];
		foreach ( $cands as $c ) {
			$out[] = array_merge( self::candidat_public( $c ), [
				'resultat'     => $this->resultat_candidat( $c, $evals_par_cand[ intval( $c->id ) ] ?? [], $passage, $noms ),
				'decision'     => $c->decision,
				'grade_obtenu' => $c->grade_obtenu,
			] );
		}
		return $out;
	}

	// ══════════════════════════════════════════════════════════════════════
	// APPLICATION ADHÉRENT — carte « Mon prochain grade » (phase 2)
	// ══════════════════════════════════════════════════════════════════════

	/** Durée pendant laquelle le retour du dernier passage reste affiché dans l'application. */
	const JOURS_RETOUR = 183;

	/**
	 * Données de la carte « Mon prochain grade » (route /eleve/me de l'application) :
	 *   prochain : grade suivant dans le Parcours, son programme et sa vidéo (dan = true si le
	 *              suivant est un Dan : examen hors club, pas de programme affiché) ;
	 *   prevu    : passage à venir où l'élève est déjà candidat ;
	 *   retour   : dernier passage validé (moins de JOURS_RETOUR jours) — décision, chaque
	 *              critère, points à revoir, remarques des juges (sans leur nom).
	 * Le retour reprend resultat_candidat(), le même calcul que l'écran du président.
	 *
	 * @param object $el ligne de sp_cal_eleves
	 * @return array|null null si rien à montrer (pas de Parcours ni de passage)
	 */
	public function donnees_eleve( $el ): ?array {
		global $wpdb;
		$eid = intval( $el->id );
		$out = [ 'prochain' => null, 'prevu' => null, 'retour' => null ];

		// ── Prochain grade
		$ch = $this->chaine( self::cat_parcours( (string) $el->categorie_age ) );
		if ( $ch ) {
			$i = trim( (string) $el->grade ) === '' ? 0 : self::position( $ch, (string) $el->grade );
			if ( $i >= 0 && isset( $ch[ $i + 1 ] ) ) {
				$g = $ch[ $i + 1 ];
				if ( self::est_dan( $g['grade'] ) ) {
					$out['prochain'] = [ 'grade' => $g['grade'], 'dan' => true ];
				} else {
					$age = self::age_a( $el, current_time( 'Y-m-d' ) );
					$out['prochain'] = [
						'grade'      => $g['grade'],
						'dan'        => false,
						'programme'  => array_map( static fn( $p ) => [ 'libelle' => $p['libelle'], 'detail' => $p['detail'] ], self::programme( $g ) ),
						'video_url'  => esc_url_raw( $g['video_url'] ),
						'min_age'    => $g['min_age'],
						// Écart d'âge positif = encore trop jeune (information, jamais bloquant).
						'ans_avant'  => ( $g['min_age'] !== null && $age !== null ) ? max( 0, $g['min_age'] - $age ) : 0,
					];
				}
			}
		}

		$tp = self::t( 'passages' );
		$tc = self::t( 'passage_candidats' );

		// ── Passage à venir
		$prevu = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.date, c.grade_vise FROM $tc c INNER JOIN $tp p ON p.id = c.passage_id
			 WHERE c.eleve_id = %d AND p.statut IN ('preparation','ouvert') AND p.date >= %s ORDER BY p.date ASC LIMIT 1",
			$eid, current_time( 'Y-m-d' )
		) );
		if ( $prevu ) $out['prevu'] = [ 'date_fr' => self::date_fr( $prevu->date ), 'grade_vise' => $prevu->grade_vise ];

		// ── Retour du dernier passage validé
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.id AS cid FROM $tc c INNER JOIN $tp p ON p.id = c.passage_id
			 WHERE c.eleve_id = %d AND p.statut = 'valide' AND c.decision <> '' AND p.date >= %s ORDER BY p.date DESC, p.id DESC LIMIT 1",
			$eid, gmdate( 'Y-m-d', current_time( 'timestamp' ) - self::JOURS_RETOUR * DAY_IN_SECONDS )
		) );
		if ( $row ) {
			$cand = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tc WHERE id = %d", intval( $row->cid ) ) );
			$p    = $this->get_passage( intval( $cand->passage_id ) );
			$cand->criteres   = json_decode( (string) $cand->criteres, true ) ?: [];
			$cand->arbitrages = json_decode( (string) $cand->arbitrages, true ) ?: [];
			$evals = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'passage_evaluations' ) . ' WHERE candidat_id = %d', intval( $cand->id ) ) );
			foreach ( $evals as $ev ) $ev->juge_id = intval( $ev->juge_id );
			$res   = $this->resultat_candidat( $cand, $evals, $p, [] );
			$poom  = $cand->mode === 'poom';
			$crits = [];
			$revoir = [];
			foreach ( $res['criteres'] as $l ) {
				$c = [ 'libelle' => $l['libelle'], 'detail' => $l['detail'] ];
				if ( $poom && isset( $l['moyenne'] ) ) {
					$c['note'] = $l['moyenne'];
					if ( $l['moyenne'] < floatval( $p->poom_seuil ) ) $revoir[] = $l['libelle'];
				} elseif ( isset( $l['niveau'] ) ) {
					$c['niveau'] = $l['niveau'];
					if ( $l['niveau'] !== self::ACQUIS ) $revoir[] = $l['libelle'];
				}
				$crits[] = $c;
			}
			$g_vise = $this->entree_grade( (string) $cand->categorie, (string) $cand->grade_vise );
			$out['retour'] = [
				'date_fr'      => self::date_fr( $p->date ),
				'grade_vise'   => $cand->grade_vise,
				'decision'     => $cand->decision,
				'grade_obtenu' => $cand->grade_obtenu,
				'mode'         => $cand->mode,
				'moyenne'      => $res['moyenne'] ?? null,
				'criteres'     => $crits,
				'a_revoir'     => $revoir,
				'remarques'    => array_values( array_map( static fn( $r ) => $r['texte'], $res['remarques'] ) ),
				'video_url'    => $g_vise ? esc_url_raw( $g_vise['video_url'] ) : '',
			];
		}

		return ( $out['prochain'] || $out['prevu'] || $out['retour'] ) ? $out : null;
	}

	private static function candidat_public( $c ): array {
		return [
			'id'           => intval( $c->id ),
			'nom'          => (string) $c->nom,
			'prenom'       => (string) $c->prenom,
			'categorie'    => $c->categorie,
			'grade_actuel' => $c->grade_actuel,
			'grade_vise'   => $c->grade_vise,
			'mode'         => $c->mode,
			'alerte_age'   => intval( $c->alerte_age ),
			'criteres'     => $c->criteres,
		];
	}

	// ══════════════════════════════════════════════════════════════════════
	// ADMIN — MENU ET PAGES
	// ══════════════════════════════════════════════════════════════════════

	public function register_menu(): void {
		add_submenu_page( 'sp-cal-pro', 'Passages de grade', '🥋 Passages de grade', self::CAP, self::PAGE, [ $this, 'render_page' ] );
	}

	private static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::PAGE ], $args ), admin_url( 'admin.php' ) );
	}

	public static function url_juge( string $token ): string {
		return add_query_arg( 'sp_passage', $token, home_url( '/' ) );
	}

	private static function url_validation( int $passage_id ): string {
		return add_query_arg( 'sp_passage_admin', $passage_id, home_url( '/' ) );
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) wp_die( 'Accès refusé.' );
		$this->notice();
		echo '<div class="wrap sp-cal-wrap sp-pg">';
		$this->styles_admin();
		$pid = intval( $_GET['passage'] ?? 0 );
		$tab = sanitize_key( $_GET['tab'] ?? '' );
		if ( $pid && ( $p = $this->get_passage( $pid ) ) ) {
			$this->page_passage( $p );
		} else {
			echo '<h1>🥋 Passages de grade</h1>';
			echo '<nav class="nav-tab-wrapper">'
				. '<a class="nav-tab' . ( $tab !== 'epreuves' ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url() ) . '">Passages</a>'
				. '<a class="nav-tab' . ( $tab === 'epreuves' ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( [ 'tab' => 'epreuves' ] ) ) . '">Épreuves transverses</a>'
				. '</nav>';
			$tab === 'epreuves' ? $this->page_epreuves() : $this->page_liste();
		}
		echo '</div>';
	}

	private function notice(): void {
		$msgs = [
			'cree'      => [ 'success', 'Passage créé (événement « examen » ajouté au calendrier). Choisissez les candidats et les juges.' ],
			'enreg'     => [ 'success', 'Préparation enregistrée.' ],
			'ouvert'    => [ 'success', 'Notation ouverte : les juges peuvent noter avec leur lien ou leur QR code.' ],
			'prep'      => [ 'success', 'Passage repassé en préparation : les juges ne peuvent plus noter (leurs notes sont conservées).' ],
			'rouvert'   => [ 'warning', 'Passage rouvert. Les grades déjà écrits sur les fiches ne sont pas annulés ; une nouvelle validation les réécrira.' ],
			'suppr'     => [ 'success', 'Passage supprimé.' ],
			'ep_ok'     => [ 'success', 'Épreuve enregistrée. Elle s\'applique aux candidats ajoutés ensuite et à l\'ouverture des passages.' ],
			'ep_suppr'  => [ 'success', 'Épreuve supprimée.' ],
			'incomplet' => [ 'error', 'Pour ouvrir la notation, il faut au moins un candidat, un juge et un président de jury.' ],
			'enreg_incomplet' => [ 'warning', 'Préparation enregistrée, mais la notation n\'est pas ouverte : il faut au moins un candidat, un juge et un président de jury.' ],
			'valide'    => [ 'error', 'Ce passage est validé : rouvrez-le d\'abord pour le modifier.' ],
		];
		$k = sanitize_key( $_GET['msg'] ?? '' );
		if ( isset( $msgs[ $k ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $msgs[ $k ][0] ) . ' is-dismissible"><p>' . esc_html( $msgs[ $k ][1] ) . '</p></div>';
		}
	}

	private function styles_admin(): void {
		?>
		<style>
		.sp-pg .sp-pg-box{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin:16px 0;max-width:1100px;}
		.sp-pg .sp-pg-box h2{margin:0 0 4px;font-size:16px;}
		.sp-pg .sp-pg-box .description{margin:0 0 12px;}
		.sp-pg .sp-pg-badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;}
		.sp-pg .st-preparation{background:#f0f0f1;color:#50575e;}
		.sp-pg .st-ouvert{background:#dbeafe;color:#1d4ed8;}
		.sp-pg .st-valide{background:#dcfce7;color:#15803d;}
		.sp-pg table.sp-pg-table{border-collapse:collapse;width:100%;}
		.sp-pg .sp-pg-table th,.sp-pg .sp-pg-table td{padding:6px 8px;border-bottom:1px solid #f0f0f1;text-align:left;vertical-align:middle;}
		.sp-pg .sp-pg-table tr.sel{background:#f0f6fc;}
		.sp-pg .sp-pg-table tr.cat td{background:#f6f7f7;font-weight:700;padding-top:12px;}
		.sp-pg .sp-pg-alerte{color:#b45309;font-weight:600;}
		.sp-pg .sp-pg-muted{color:#787c82;}
		.sp-pg .sp-pg-tools{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px;}
		.sp-pg .sp-pg-juges{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px 16px;}
		.sp-pg .sp-pg-juge{display:flex;align-items:center;gap:8px;padding:6px 8px;border:1px solid #f0f0f1;border-radius:6px;}
		.sp-pg .sp-pg-juge .pres{margin-left:auto;font-size:12px;}
		.sp-pg .sp-pg-liens{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;}
		.sp-pg .sp-pg-lien{border:1px solid #dcdcde;border-radius:8px;padding:10px;text-align:center;}
		.sp-pg .sp-pg-lien .qr{display:flex;justify-content:center;margin:8px 0;}
		.sp-pg .sp-pg-lien input{width:100%;font-size:11px;}
		.sp-pg .sp-pg-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
		@media (max-width:782px){.sp-pg .sp-pg-table .hide-sm{display:none;}}
		</style>
		<?php
	}

	// ─── Liste des passages + création ───────────────────────────────────

	private function page_liste(): void {
		global $wpdb;
		$tp    = self::t( 'passages' );
		$tc    = self::t( 'passage_candidats' );
		$tj    = self::t( 'passage_juges' );
		$rows  = $wpdb->get_results( "SELECT p.*, (SELECT COUNT(*) FROM $tc c WHERE c.passage_id = p.id) AS nb_cand,
			(SELECT COUNT(*) FROM $tj j WHERE j.passage_id = p.id) AS nb_juges FROM $tp p ORDER BY p.date DESC, p.id DESC LIMIT 100" );
		?>
		<div class="sp-pg-box">
			<h2>Nouveau passage</h2>
			<p class="description">Un passage crée lui-même son événement « examen » dans le calendrier.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sp-pg-actions">
				<?php wp_nonce_field( 'sp_passage_creer' ); ?>
				<input type="hidden" name="action" value="sp_passage_creer">
				<label>Date <input type="date" name="date" required value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></label>
				<label>Intitulé <input type="text" name="titre" value="Passage de grade" class="regular-text"></label>
				<button class="button button-primary">Créer le passage</button>
			</form>
		</div>
		<div class="sp-pg-box">
			<h2>Passages</h2>
			<?php if ( ! $rows ) : ?>
				<p class="sp-pg-muted">Aucun passage pour l'instant.</p>
			<?php else : ?>
				<table class="sp-pg-table">
					<thead><tr><th>Date</th><th>Intitulé</th><th>Statut</th><th>Candidats</th><th class="hide-sm">Juges</th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( self::url( [ 'passage' => $r->id ] ) ); ?>"><strong><?php echo esc_html( self::date_fr( $r->date ) ); ?></strong></a></td>
							<td><?php echo esc_html( $r->titre ); ?></td>
							<td><span class="sp-pg-badge st-<?php echo esc_attr( $r->statut ); ?>"><?php echo esc_html( self::STATUTS[ $r->statut ] ?? $r->statut ); ?></span></td>
							<td><?php echo intval( $r->nb_cand ); ?></td>
							<td class="hide-sm"><?php echo intval( $r->nb_juges ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		if ( ! $this->db->get_parcours_claira() ) {
			echo '<div class="notice notice-warning inline"><p>⚠️ TKD Parcours est inactif ou ne contient aucun grade : les grades visés et les programmes ne peuvent pas être proposés. Seules les épreuves transverses seront notées.</p></div>';
		}
	}

	private static function date_fr( string $ymd ): string {
		$jours = [ 'dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi' ];
		$ts    = strtotime( $ymd );
		return $ts ? ucfirst( $jours[ intval( gmdate( 'w', $ts ) ) ] ) . ' ' . gmdate( 'd/m/Y', $ts ) : $ymd;
	}

	// ─── Écran d'un passage : préparer, ouvrir, liens ────────────────────

	private function page_passage( $p ): void {
		$pid      = intval( $p->id );
		$valide   = $p->statut === 'valide';
		$cands    = [];
		foreach ( $this->get_candidats( $pid ) as $c ) $cands[ intval( $c->eleve_id ) ] = $c;
		$juges    = $this->get_juges( $pid );
		$juges_k  = [];
		foreach ( $juges as $j ) $juges_k[ $j->origine . ':' . intval( $j->ref_id ) ] = $j;
		$post_url = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<h1>🥋 <?php echo esc_html( $p->titre ); ?> — <?php echo esc_html( self::date_fr( $p->date ) ); ?>
			<span class="sp-pg-badge st-<?php echo esc_attr( $p->statut ); ?>"><?php echo esc_html( self::STATUTS[ $p->statut ] ?? $p->statut ); ?></span></h1>
		<p><a href="<?php echo esc_url( self::url() ); ?>">← Tous les passages</a></p>

		<?php $this->bloc_statut( $p, $juges ); ?>

		<form method="post" action="<?php echo $post_url; // phpcs:ignore -- esc_url ci-dessus ?>" id="sp-pg-prep">
			<?php wp_nonce_field( 'sp_passage_preparer_' . $pid ); ?>
			<input type="hidden" name="action" value="sp_passage_preparer">
			<input type="hidden" name="passage_id" value="<?php echo $pid; // phpcs:ignore ?>">
			<fieldset <?php disabled( $valide ); ?>>
			<?php
			$this->bloc_candidats( $p, $cands );
			$this->bloc_juges( $p, $juges_k );
			?>
			<div class="sp-pg-box">
				<h2>Règle d'admission Poom</h2>
				<p class="description">Notes sur 10, moyenne des juges par épreuve. Admis si la moyenne générale atteint le seuil et qu'aucune épreuve n'est sous le plancher. (Keup : admis s'il n'y a aucun « Non acquis » et au plus un « À revoir ».)</p>
				<label>Seuil <input type="number" name="poom_seuil" min="0" max="10" step="0.5" value="<?php echo esc_attr( self::nb( $p->poom_seuil ) ); ?>" style="width:80px"> /10</label>
				&nbsp; <label>Plancher <input type="number" name="poom_plancher" min="0" max="10" step="0.5" value="<?php echo esc_attr( self::nb( $p->poom_plancher ) ); ?>" style="width:80px"> /10</label>
			</div>
			<p class="sp-pg-actions">
				<?php if ( $p->statut === 'preparation' ) : ?>
					<?php // « Enregistrer seulement » en premier : c'est le bouton déclenché par la touche Entrée. ?>
					<button class="button button-hero">Enregistrer seulement</button>
					<button class="button button-primary button-hero" name="ouvrir" value="1">🟢 Enregistrer et ouvrir la notation</button>
				<?php else : ?>
					<button class="button button-primary button-hero">Enregistrer la préparation</button>
				<?php endif; ?>
			</p>
			</fieldset>
		</form>

		<?php if ( $p->statut === 'preparation' ) : ?>
			<form method="post" action="<?php echo $post_url; // phpcs:ignore ?>" onsubmit="return confirm('Supprimer ce passage, ses candidats, ses juges et leurs notes ?');">
				<?php wp_nonce_field( 'sp_passage_supprimer_' . $pid ); ?>
				<input type="hidden" name="action" value="sp_passage_supprimer">
				<input type="hidden" name="passage_id" value="<?php echo $pid; // phpcs:ignore ?>">
				<button class="button-link button-link-delete">Supprimer ce passage</button>
			</form>
		<?php endif;
	}

	private function form_statut( int $pid, string $vers, string $libelle, string $classe = 'button', string $confirm = '' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"'
			. ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>'
			. wp_nonce_field( 'sp_passage_statut_' . $pid, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="sp_passage_statut">'
			. '<input type="hidden" name="passage_id" value="' . $pid . '">'
			. '<input type="hidden" name="vers" value="' . esc_attr( $vers ) . '">'
			. '<button class="' . esc_attr( $classe ) . '">' . esc_html( $libelle ) . '</button></form>';
	}

	private function bloc_statut( $p, array $juges ): void {
		$pid = intval( $p->id );
		echo '<div class="sp-pg-box">';
		if ( $p->statut === 'preparation' ) {
			echo '<h2>Ouvrir la notation</h2><p class="description">Choisissez les candidats et les juges ci-dessous, puis cliquez sur « Enregistrer et ouvrir la notation » en bas de page (ou sur ce bouton si tout est déjà enregistré) : chaque juge reçoit un lien et un QR code. La grille de chaque candidat est figée à l\'ouverture (programme du grade visé + épreuves transverses).</p>';
			echo '<div class="sp-pg-actions">' . $this->form_statut( $pid, 'ouvert', '🟢 Ouvrir la notation', 'button button-primary' ) . '</div>'; // phpcs:ignore
		} elseif ( $p->statut === 'ouvert' ) {
			echo '<h2>Notation ouverte</h2><p class="description">Chaque juge note avec son lien personnel (pas de compte nécessaire, fonctionne hors connexion). Le président voit en plus l\'onglet Résultats pour trancher et valider.</p>';
			echo '<div class="sp-pg-actions"><a class="button button-primary" target="_blank" href="' . esc_url( self::url_validation( $pid ) ) . '">📋 Écran des résultats et de la validation</a>'
				. $this->form_statut( $pid, 'preparation', 'Repasser en préparation' ) . '</div>'; // phpcs:ignore
			$this->bloc_liens( $juges );
		} else {
			echo '<h2>Passage validé</h2><p class="description">Validé le ' . esc_html( mysql2date( 'd/m/Y à H:i', $p->valide_at ) ) . ( $p->valide_par ? ' par ' . esc_html( $p->valide_par ) : '' ) . '. Les grades des admis sont écrits sur leur fiche.</p>';
			echo '<div class="sp-pg-actions"><a class="button" target="_blank" href="' . esc_url( self::url_validation( $pid ) ) . '">📋 Voir les résultats</a>'
				. $this->form_statut( $pid, 'ouvert', 'Rouvrir le passage', 'button', 'Rouvrir ce passage ? Les grades déjà écrits sur les fiches ne seront pas annulés.' ) . '</div>'; // phpcs:ignore
		}
		echo '</div>';
	}

	private function bloc_liens( array $juges ): void {
		if ( ! $juges ) return;
		echo '<h3>Liens des juges</h3><div class="sp-pg-liens">';
		foreach ( $juges as $j ) {
			$url = self::url_juge( $j->token );
			echo '<div class="sp-pg-lien"><strong>' . esc_html( $j->nom ) . '</strong>' . ( $j->president ? ' <span class="sp-pg-badge st-ouvert">Président</span>' : '' )
				. '<div class="qr" data-qr="' . esc_attr( $url ) . '"></div>'
				. '<input type="text" readonly value="' . esc_attr( $url ) . '" onclick="this.select()"></div>';
		}
		echo '</div>';
		?>
		<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
		<script>
		(function init(){
			if (typeof QRCode === 'undefined') { setTimeout(init, 100); return; }
			document.querySelectorAll('.sp-pg-lien .qr').forEach(function(el){
				new QRCode(el, { text: el.getAttribute('data-qr'), width: 140, height: 140, correctLevel: QRCode.CorrectLevel.M });
			});
		})();
		</script>
		<?php
	}

	private function bloc_candidats( $p, array $cands ): void {
		$parcours = $this->db->get_parcours_claira();
		$eleves   = $this->get_eleves_tkd();
		$par_cat  = [ 'Baby' => [], 'Enfant' => [], 'Ado/adulte' => [], '' => [] ];
		foreach ( $eleves as $el ) $par_cat[ self::cat_parcours( (string) $el->categorie_age ) ][] = $el;
		// Élèves déjà candidats mais devenus inactifs : rester visibles pour pouvoir les retirer.
		$vus = array_map( 'intval', array_column( $eleves, 'id' ) );
		?>
		<div class="sp-pg-box">
			<h2>Candidats <span class="sp-pg-muted" id="sp-pg-nbsel"></span></h2>
			<p class="description">Grade visé proposé : le grade suivant dans TKD Parcours (modifiable pour un saut). L'âge conseillé du Parcours n'est jamais bloquant : une alerte s'affiche si l'élève est trop jeune. Les Dan ne se passent pas au club.</p>
			<div class="sp-pg-tools">
				<input type="search" id="sp-pg-filtre" placeholder="Rechercher un élève…" class="regular-text">
				<label><input type="checkbox" id="sp-pg-sel-only"> Seulement les candidats cochés</label>
			</div>
			<table class="sp-pg-table" id="sp-pg-cands">
				<thead><tr><th style="width:28px"></th><th>Élève</th><th class="hide-sm">Âge</th><th>Grade actuel</th><th>Grade visé</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $par_cat as $cat => $liste ) :
					if ( ! $liste ) continue;
					$chaine = $parcours[ $cat ] ?? [];
					?>
					<tr class="cat"><td colspan="6"><?php echo esc_html( $cat !== '' ? $cat : 'Catégorie hors Parcours' ); ?></td></tr>
					<?php foreach ( $liste as $el ) :
						$eid    = intval( $el->id );
						$cand   = $cands[ $eid ] ?? null;
						$vise   = $cand ? $cand->grade_vise : $this->grade_vise_defaut( $el );
						$age    = self::age_a( $el, $p->date );
						$connu  = trim( (string) $el->grade ) === '' || self::position( $chaine, (string) $el->grade ) >= 0;
						?>
						<tr class="cand<?php echo $cand ? ' sel' : ''; ?>" data-age="<?php echo $age === null ? '' : intval( $age ); ?>" data-nom="<?php echo esc_attr( mb_strtolower( $el->nom . ' ' . $el->prenom ) ); ?>">
							<td><input type="checkbox" name="sel[]" value="<?php echo $eid; // phpcs:ignore ?>" <?php checked( (bool) $cand ); ?>></td>
							<td><?php echo esc_html( mb_strtoupper( $el->nom ) . ' ' . $el->prenom ); ?></td>
							<td class="hide-sm"><?php echo $age === null ? '<span class="sp-pg-muted">?</span>' : intval( $age ) . ' ans'; ?></td>
							<td><?php echo esc_html( $el->grade ?: '—' ); ?><?php if ( ! $connu ) echo ' <span class="sp-pg-alerte" title="Grade absent de TKD Parcours : harmonisez-le depuis la page Adhérents, ou choisissez le grade visé à la main.">⚠</span>'; ?></td>
							<td>
								<?php if ( $chaine ) : ?>
									<select name="vise[<?php echo $eid; // phpcs:ignore ?>]">
										<option value="">— choisir —</option>
										<?php foreach ( $chaine as $g ) :
											if ( self::est_dan( $g['grade'] ) ) continue; ?>
											<option value="<?php echo esc_attr( $g['grade'] ); ?>" data-min="<?php echo $g['min_age'] === null ? '' : intval( $g['min_age'] ); ?>" <?php selected( self::cle_grade( $vise ), self::cle_grade( $g['grade'] ) ); ?>><?php echo esc_html( $g['grade'] ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<input type="text" name="vise[<?php echo $eid; // phpcs:ignore ?>]" value="<?php echo esc_attr( $vise ); ?>" placeholder="Grade visé" style="width:140px">
								<?php endif; ?>
							</td>
							<td class="alerte sp-pg-alerte"></td>
						</tr>
					<?php endforeach;
				endforeach;
				foreach ( $cands as $eid => $c ) :
					if ( in_array( $eid, $vus, true ) ) continue; ?>
					<tr class="cand sel" data-age="" data-nom="<?php echo esc_attr( mb_strtolower( $c->nom . ' ' . $c->prenom ) ); ?>">
						<td><input type="checkbox" name="sel[]" value="<?php echo intval( $eid ); ?>" checked></td>
						<td><?php echo esc_html( mb_strtoupper( (string) $c->nom ) . ' ' . $c->prenom ); ?> <span class="sp-pg-muted">(inactif)</span></td>
						<td class="hide-sm"></td><td><?php echo esc_html( $c->grade_actuel ); ?></td>
						<td><input type="text" name="vise[<?php echo intval( $eid ); ?>]" value="<?php echo esc_attr( $c->grade_vise ); ?>" style="width:140px"></td><td></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<script>
		(function(){
			var table = document.getElementById('sp-pg-cands'), filtre = document.getElementById('sp-pg-filtre'),
			    selOnly = document.getElementById('sp-pg-sel-only'), nb = document.getElementById('sp-pg-nbsel');
			function alerte(tr) {
				var sel = tr.querySelector('select'), cell = tr.querySelector('.alerte');
				if (!sel || !cell) return;
				var opt = sel.options[sel.selectedIndex], min = opt ? opt.getAttribute('data-min') : '', age = tr.getAttribute('data-age');
				var ecart = (min !== '' && min !== null && age !== '') ? parseInt(min, 10) - parseInt(age, 10) : 0;
				cell.textContent = ecart > 0 ? '⚠ Trop jeune de ' + ecart + ' an' + (ecart > 1 ? 's' : '') + ' (âge conseillé : ' + min + ' ans)' : '';
			}
			function maj() {
				var q = (filtre.value || '').toLowerCase().trim(), n = 0;
				table.querySelectorAll('tr.cand').forEach(function(tr){
					var coche = tr.querySelector('input[type=checkbox]').checked;
					if (coche) n++;
					tr.classList.toggle('sel', coche);
					tr.style.display = ((!q || tr.getAttribute('data-nom').indexOf(q) !== -1) && (!selOnly.checked || coche)) ? '' : 'none';
				});
				nb.textContent = '(' + n + ' coché' + (n > 1 ? 's' : '') + ')';
			}
			table.addEventListener('change', function(e){
				var tr = e.target.closest('tr.cand'); if (!tr) return;
				if (e.target.tagName === 'SELECT') { alerte(tr); tr.querySelector('input[type=checkbox]').checked = true; }
				maj();
			});
			filtre.addEventListener('input', maj); selOnly.addEventListener('change', maj);
			table.querySelectorAll('tr.cand').forEach(alerte); maj();
		})();
		</script>
		<?php
	}

	private function bloc_juges( $p, array $juges_k ): void {
		global $wpdb;
		$dispos   = $this->db->get_dispos_for_date( $p->date );
		$trainers = $this->db->get_trainers_entraineurs( true );
		usort( $trainers, static function ( $a, $b ) use ( $dispos ) {
			$rang = static fn( $t ) => isset( $dispos[ intval( $t->id ) ] ) ? ( $dispos[ intval( $t->id ) ]['disponible'] ? 0 : 2 ) : 1;
			return $rang( $a ) <=> $rang( $b ) ?: strcasecmp( (string) ( $a->nom_public ?: $a->nom ), (string) ( $b->nom_public ?: $b->nom ) );
		} );
		$noirs = array_filter( $this->get_eleves_tkd(), static function ( $el ) {
			return (bool) preg_match( '/\b(poom|dan|noire)\b/iu', (string) $el->grade );
		} );
		$president = '';
		foreach ( $juges_k as $k => $j ) if ( $j->president ) $president = $k;
		?>
		<div class="sp-pg-box">
			<h2>Juges</h2>
			<p class="description">Entraîneurs (ceux qui ont mis ✅ sur cette date dans « Mes dispos » en tête) et adhérents Poom / ceinture noire. Cochez les juges, puis désignez le président de jury. Retirer un juge supprime ses notes.</p>
			<h3 style="margin:8px 0">Entraîneurs</h3>
			<div class="sp-pg-juges">
			<?php foreach ( $trainers as $t ) :
				$k  = 'entraineur:' . intval( $t->id );
				$d  = $dispos[ intval( $t->id ) ] ?? null;
				$ic = $d ? ( $d['disponible'] ? '✅' : '❌' ) : '<span class="sp-pg-muted">—</span>';
				$this->ligne_juge( $k, (string) ( $t->nom_public ?: $t->nom ), $ic, isset( $juges_k[ $k ] ), $president === $k );
			endforeach; ?>
			</div>
			<h3 style="margin:14px 0 8px">Adhérents Poom / ceinture noire</h3>
			<?php if ( ! $noirs ) : ?>
				<p class="sp-pg-muted">Aucun adhérent actif Poom ou ceinture noire.</p>
			<?php else : ?>
				<div class="sp-pg-juges">
				<?php foreach ( $noirs as $el ) :
					$k = 'adherent:' . intval( $el->id );
					$this->ligne_juge( $k, trim( $el->prenom . ' ' . $el->nom ), '<span class="sp-pg-muted">' . esc_html( $el->grade ) . '</span>', isset( $juges_k[ $k ] ), $president === $k );
				endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function ligne_juge( string $k, string $nom, string $info_html, bool $coche, bool $pres ): void {
		echo '<label class="sp-pg-juge"><input type="checkbox" name="juges[]" value="' . esc_attr( $k ) . '"' . checked( $coche, true, false ) . '> '
			. esc_html( $nom ) . ' ' . $info_html // phpcs:ignore -- $info_html construit et échappé par l'appelant
			. '<span class="pres"><input type="radio" name="president" value="' . esc_attr( $k ) . '"' . checked( $pres, true, false ) . ' title="Président de jury"> Président</span></label>';
	}

	// ─── Épreuves transverses ────────────────────────────────────────────

	private function page_epreuves(): void {
		$eps   = $this->get_epreuves();
		$edit  = null;
		$eid   = intval( $_GET['epreuve'] ?? 0 );
		foreach ( $eps as $e ) if ( intval( $e->id ) === $eid ) $edit = $e;
		$types = [ 'niveaux' => 'Acquis / À revoir / Non acquis', 'note' => 'Note sur 10', 'mesure' => 'Mesure (chiffre relevé)' ];
		$grades_tous = [];
		foreach ( $this->db->get_parcours_claira() as $cat => $ch ) foreach ( $ch as $g ) if ( ! self::est_dan( $g['grade'] ) ) $grades_tous[ $cat ][] = $g['grade'];
		$sel_grades = $edit && $edit->portee === 'grades' ? array_map( [ __CLASS__, 'cle_grade' ], (array) json_decode( (string) $edit->portee_valeur, true ) ) : [];
		?>
		<div class="sp-pg-box">
			<h2>Épreuves transverses</h2>
			<p class="description">Elles s'ajoutent au programme du grade visé (poomsae, bras, jambes, lus dans TKD Parcours) : attitude, condition physique, nombre de coups… Pour un candidat Poom, chaque épreuve est notée sur 10 (une mesure est ramenée sur 10 : seuil « acquis » = 10/10).</p>
			<?php if ( ! $eps ) : ?>
				<p class="sp-pg-muted">Aucune épreuve transverse.</p>
			<?php else : ?>
				<table class="sp-pg-table">
					<thead><tr><th>Épreuve</th><th>Pour</th><th>Type</th><th class="hide-sm">Seuils</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $eps as $e ) : ?>
						<tr<?php echo $e->actif ? '' : ' style="opacity:.5"'; ?>>
							<td><strong><?php echo esc_html( $e->nom ); ?></strong><?php echo $e->actif ? '' : ' (inactive)'; ?></td>
							<td><?php echo esc_html( self::libelle_portee( $e ) ); ?></td>
							<td><?php echo esc_html( $types[ $e->type ] ?? $e->type ); ?></td>
							<td class="hide-sm"><?php echo $e->type === 'niveaux' ? '—' : esc_html( 'acquis ≥ ' . self::nb( $e->seuil_acquis ) . ( $e->seuil_revoir !== null ? ', à revoir ≥ ' . self::nb( $e->seuil_revoir ) : '' ) . ( $e->unite ? ' ' . $e->unite : '' ) ); ?></td>
							<td><a href="<?php echo esc_url( self::url( [ 'tab' => 'epreuves', 'epreuve' => $e->id ] ) ); ?>">Modifier</a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="sp-pg-box">
			<h2><?php echo $edit ? 'Modifier « ' . esc_html( $edit->nom ) . ' »' : 'Nouvelle épreuve'; ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sp-pg-ep">
				<?php wp_nonce_field( 'sp_passage_epreuve' ); ?>
				<input type="hidden" name="action" value="sp_passage_epreuve">
				<input type="hidden" name="id" value="<?php echo $edit ? intval( $edit->id ) : 0; ?>">
				<table class="form-table" role="presentation">
					<tr><th>Nom</th><td><input type="text" name="nom" required class="regular-text" value="<?php echo esc_attr( $edit->nom ?? '' ); ?>" placeholder="ex. Attitude / salut"></td></tr>
					<tr><th>Pour qui</th><td>
						<select name="portee" id="sp-pg-portee">
							<option value="tous" <?php selected( $edit->portee ?? 'tous', 'tous' ); ?>>Tous les candidats</option>
							<option value="categorie" <?php selected( $edit->portee ?? '', 'categorie' ); ?>>Une catégorie</option>
							<option value="grades" <?php selected( $edit->portee ?? '', 'grades' ); ?>>Certains grades visés</option>
						</select>
						<span class="portee-categorie"> <select name="portee_categorie">
							<?php foreach ( [ 'Baby', 'Enfant', 'Ado/adulte' ] as $c ) echo '<option' . selected( ( $edit && $edit->portee === 'categorie' ) ? $edit->portee_valeur : '', $c, false ) . '>' . esc_html( $c ) . '</option>'; ?>
						</select></span>
						<div class="portee-grades" style="margin-top:8px">
							<?php foreach ( $grades_tous as $cat => $liste ) : ?>
								<p style="margin:6px 0 2px"><strong><?php echo esc_html( $cat ); ?></strong></p>
								<?php foreach ( $liste as $g ) : ?>
									<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="portee_grades[]" value="<?php echo esc_attr( $g ); ?>" <?php checked( in_array( self::cle_grade( $g ), $sel_grades, true ) ); ?>> <?php echo esc_html( $g ); ?></label>
								<?php endforeach;
							endforeach; ?>
						</div>
					</td></tr>
					<tr><th>Type</th><td>
						<select name="type" id="sp-pg-type">
							<?php foreach ( $types as $k => $l ) echo '<option value="' . esc_attr( $k ) . '"' . selected( $edit->type ?? 'niveaux', $k, false ) . '>' . esc_html( $l ) . '</option>'; ?>
						</select>
					</td></tr>
					<tr class="seuils"><th>Seuils (candidats keup)</th><td>
						Acquis à partir de <input type="number" step="0.5" name="seuil_acquis" value="<?php echo esc_attr( isset( $edit->seuil_acquis ) ? self::nb( $edit->seuil_acquis ) : '' ); ?>" style="width:90px">
						&nbsp; À revoir à partir de <input type="number" step="0.5" name="seuil_revoir" value="<?php echo esc_attr( isset( $edit->seuil_revoir ) ? self::nb( $edit->seuil_revoir ) : '' ); ?>" style="width:90px">
						<span class="unite"> &nbsp; Unité <input type="text" name="unite" value="<?php echo esc_attr( $edit->unite ?? '' ); ?>" placeholder="coups" style="width:90px"></span>
						<p class="description">En dessous du seuil « à revoir » : non acquis. Note sur 10 sans seuil : acquis ≥ 5, à revoir ≥ 4.</p>
					</td></tr>
					<tr><th>Ordre</th><td><input type="number" name="ordre" value="<?php echo intval( $edit->ordre ?? 0 ); ?>" style="width:80px">
						&nbsp; <label><input type="checkbox" name="actif" value="1" <?php checked( $edit ? (bool) $edit->actif : true ); ?>> Active</label></td></tr>
				</table>
				<p><button class="button button-primary"><?php echo $edit ? 'Enregistrer' : 'Ajouter l\'épreuve'; ?></button>
				<?php if ( $edit ) : ?> <a class="button" href="<?php echo esc_url( self::url( [ 'tab' => 'epreuves' ] ) ); ?>">Annuler</a><?php endif; ?></p>
			</form>
			<?php if ( $edit ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Supprimer cette épreuve ? Les passages déjà ouverts gardent leur grille.');">
					<?php wp_nonce_field( 'sp_passage_epreuve_suppr' ); ?>
					<input type="hidden" name="action" value="sp_passage_epreuve_suppr">
					<input type="hidden" name="id" value="<?php echo intval( $edit->id ); ?>">
					<button class="button-link button-link-delete">Supprimer l'épreuve</button>
				</form>
			<?php endif; ?>
		</div>
		<script>
		(function(){
			var portee = document.getElementById('sp-pg-portee'), type = document.getElementById('sp-pg-type'), f = document.getElementById('sp-pg-ep');
			function maj(){
				f.querySelector('.portee-categorie').style.display = portee.value === 'categorie' ? '' : 'none';
				f.querySelector('.portee-grades').style.display    = portee.value === 'grades' ? '' : 'none';
				f.querySelector('tr.seuils').style.display          = type.value === 'niveaux' ? 'none' : '';
				f.querySelector('.unite').style.display             = type.value === 'mesure' ? '' : 'none';
			}
			portee.addEventListener('change', maj); type.addEventListener('change', maj); maj();
		})();
		</script>
		<?php
	}

	private static function libelle_portee( $e ): string {
		if ( $e->portee === 'categorie' ) return $e->portee_valeur;
		if ( $e->portee === 'grades' ) {
			$l = (array) json_decode( (string) $e->portee_valeur, true );
			return count( $l ) <= 3 ? implode( ', ', $l ) : count( $l ) . ' grades';
		}
		return 'Tous';
	}

	// ══════════════════════════════════════════════════════════════════════
	// ADMIN — ACTIONS (admin-post)
	// ══════════════════════════════════════════════════════════════════════

	private function exiger( string $nonce ): void {
		if ( ! current_user_can( self::CAP ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( $nonce );
	}

	private static function retour( array $args ): void {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	public function handle_creer(): void {
		$this->exiger( 'sp_passage_creer' );
		global $wpdb;
		$date  = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) $date = current_time( 'Y-m-d' );
		$titre = sanitize_text_field( wp_unslash( $_POST['titre'] ?? '' ) ) ?: 'Passage de grade';

		$wpdb->insert( $this->db->table_events(), [
			'date' => $date, 'titre' => $titre, 'type' => 'examen', 'categorie' => 'Général', 'couleur' => '#7C3AED',
		] );
		$wpdb->insert( self::t( 'passages' ), [
			'event_id' => intval( $wpdb->insert_id ), 'date' => $date, 'titre' => $titre,
			'statut' => 'preparation', 'created_at' => current_time( 'mysql' ),
		] );
		self::retour( [ 'passage' => intval( $wpdb->insert_id ), 'msg' => 'cree' ] );
	}

	public function handle_preparer(): void {
		$pid = intval( $_POST['passage_id'] ?? 0 );
		$this->exiger( 'sp_passage_preparer_' . $pid );
		$p = $this->get_passage( $pid );
		if ( ! $p ) self::retour( [] );
		if ( $p->statut === 'valide' ) self::retour( [ 'passage' => $pid, 'msg' => 'valide' ] );
		global $wpdb;
		$tc = self::t( 'passage_candidats' );
		$tj = self::t( 'passage_juges' );
		$te = self::t( 'passage_evaluations' );

		// ── Réglages Poom
		$seuil    = max( 0, min( 10, floatval( $_POST['poom_seuil'] ?? 5 ) ) );
		$plancher = max( 0, min( 10, floatval( $_POST['poom_plancher'] ?? 4 ) ) );
		$wpdb->update( self::t( 'passages' ), [ 'poom_seuil' => $seuil, 'poom_plancher' => $plancher ], [ 'id' => $pid ] );

		// ── Candidats
		$sel  = array_map( 'intval', (array) ( $_POST['sel'] ?? [] ) );
		$vise = array_map( static fn( $v ) => sanitize_text_field( wp_unslash( $v ) ), (array) ( $_POST['vise'] ?? [] ) );
		$existants = [];
		foreach ( $this->get_candidats( $pid ) as $c ) $existants[ intval( $c->eleve_id ) ] = $c;

		foreach ( $existants as $eid => $c ) {
			if ( in_array( $eid, $sel, true ) ) continue;
			$wpdb->delete( $te, [ 'candidat_id' => intval( $c->id ) ] );
			$wpdb->delete( $tc, [ 'id' => intval( $c->id ) ] );
		}
		foreach ( $sel as $eid ) {
			$el = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->db->table_eleves() . ' WHERE id = %d', $eid ) );
			if ( ! $el ) continue;
			$g = trim( (string) ( $vise[ $eid ] ?? '' ) );
			if ( self::est_dan( $g ) ) $g = ''; // examen Dan hors club
			$cat = self::cat_parcours( (string) $el->categorie_age );
			$row = [
				'categorie'  => $cat,
				'grade_vise' => $g,
				'mode'       => self::est_poom( $g ) ? 'poom' : 'keup',
				'alerte_age' => $this->alerte_age( $el, $cat, $g, $p->date ),
			];
			$c = $existants[ $eid ] ?? null;
			if ( $c ) {
				$change = $c->grade_vise !== $g || $c->categorie !== $cat;
				$wpdb->update( $tc, $row, [ 'id' => intval( $c->id ) ] );
				if ( $change ) {
					// Nouvelle grille : les notes de l'ancienne ne correspondent plus.
					$wpdb->delete( $te, [ 'candidat_id' => intval( $c->id ) ] );
					$wpdb->update( $tc, [ 'arbitrages' => null ], [ 'id' => intval( $c->id ) ] );
					$c->categorie = $cat; $c->grade_vise = $g;
					$this->maj_grille_candidat( $c );
				}
			} else {
				$wpdb->insert( $tc, array_merge( $row, [
					'passage_id' => $pid, 'eleve_id' => $eid, 'grade_actuel' => (string) $el->grade,
				] ) );
				$this->maj_grille_candidat( (object) [ 'id' => $wpdb->insert_id, 'categorie' => $cat, 'grade_vise' => $g ] );
			}
		}

		// ── Juges
		$choisis   = array_map( static fn( $v ) => sanitize_text_field( wp_unslash( $v ) ), (array) ( $_POST['juges'] ?? [] ) );
		$president = sanitize_text_field( wp_unslash( $_POST['president'] ?? '' ) );
		$actuels   = [];
		foreach ( $this->get_juges( $pid ) as $j ) $actuels[ $j->origine . ':' . intval( $j->ref_id ) ] = $j;

		foreach ( $actuels as $k => $j ) {
			if ( in_array( $k, $choisis, true ) ) continue;
			$wpdb->delete( $te, [ 'juge_id' => intval( $j->id ) ] );
			$wpdb->delete( $tj, [ 'id' => intval( $j->id ) ] );
		}
		foreach ( $choisis as $k ) {
			if ( ! preg_match( '/^(entraineur|adherent):(\d+)$/', $k, $m ) ) continue;
			$nom = $this->nom_juge( $m[1], intval( $m[2] ) );
			if ( $nom === '' ) continue;
			$pres = $k === $president ? 1 : 0;
			if ( isset( $actuels[ $k ] ) ) {
				$wpdb->update( $tj, [ 'nom' => $nom, 'president' => $pres ], [ 'id' => intval( $actuels[ $k ]->id ) ] );
			} else {
				$wpdb->insert( $tj, [
					'passage_id' => $pid, 'origine' => $m[1], 'ref_id' => intval( $m[2] ), 'nom' => $nom,
					'president' => $pres, 'token' => wp_generate_password( 32, false, false ),
				] );
			}
		}
		// « Enregistrer et ouvrir la notation » : enchaîne l'ouverture sans remonter en haut de page.
		if ( ! empty( $_POST['ouvrir'] ) && $p->statut === 'preparation' ) {
			$res = $this->ouvrir_notation( $pid );
			self::retour( [ 'passage' => $pid, 'msg' => $res === 'ouvert' ? 'ouvert' : 'enreg_incomplet' ] );
		}
		self::retour( [ 'passage' => $pid, 'msg' => 'enreg' ] );
	}

	private function nom_juge( string $origine, int $id ): string {
		global $wpdb;
		if ( $origine === 'entraineur' ) {
			$t = $wpdb->get_row( $wpdb->prepare( 'SELECT nom, nom_public FROM ' . $this->db->table_trainers() . ' WHERE id = %d', $id ) );
			return $t ? (string) ( $t->nom_public ?: $t->nom ) : '';
		}
		$el = $wpdb->get_row( $wpdb->prepare( 'SELECT nom, prenom FROM ' . $this->db->table_eleves() . ' WHERE id = %d', $id ) );
		return $el ? trim( $el->prenom . ' ' . $el->nom ) : '';
	}

	/**
	 * Ouvre la notation d'un passage en préparation : il faut au moins un candidat, un juge et
	 * un président. La grille de chaque candidat est figée à ce moment (programme du Parcours +
	 * épreuves transverses actives). Utilisé par « 🟢 Ouvrir la notation » et par « Enregistrer
	 * et ouvrir la notation ».
	 *
	 * @return string code du message affiché : 'ouvert' ou 'incomplet'
	 */
	private function ouvrir_notation( int $pid ): string {
		global $wpdb;
		$juges = $this->get_juges( $pid );
		$cands = $this->get_candidats( $pid );
		if ( ! $juges || ! $cands || ! array_filter( $juges, static fn( $j ) => (int) $j->president === 1 ) ) return 'incomplet';
		foreach ( $cands as $c ) $this->maj_grille_candidat( $c );
		$wpdb->update( self::t( 'passages' ), [ 'statut' => 'ouvert' ], [ 'id' => $pid ] );
		return 'ouvert';
	}

	public function handle_statut(): void {
		$pid = intval( $_POST['passage_id'] ?? 0 );
		$this->exiger( 'sp_passage_statut_' . $pid );
		$p    = $this->get_passage( $pid );
		$vers = sanitize_key( $_POST['vers'] ?? '' );
		if ( ! $p || ! isset( self::STATUTS[ $vers ] ) || $vers === 'valide' ) self::retour( [] );
		global $wpdb;

		if ( $vers === 'ouvert' && $p->statut === 'preparation' ) {
			self::retour( [ 'passage' => $pid, 'msg' => $this->ouvrir_notation( $pid ) ] );
		} elseif ( $vers === 'ouvert' && $p->statut === 'valide' ) {
			$msg = 'rouvert';
		} elseif ( $vers === 'preparation' && $p->statut === 'ouvert' ) {
			$msg = 'prep';
		} else {
			self::retour( [ 'passage' => $pid ] );
		}
		$wpdb->update( self::t( 'passages' ), [ 'statut' => $vers ], [ 'id' => $pid ] );
		self::retour( [ 'passage' => $pid, 'msg' => $msg ] );
	}

	public function handle_supprimer(): void {
		$pid = intval( $_POST['passage_id'] ?? 0 );
		$this->exiger( 'sp_passage_supprimer_' . $pid );
		$p = $this->get_passage( $pid );
		if ( ! $p || $p->statut !== 'preparation' ) self::retour( [ 'passage' => $pid ] );
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'passage_candidats' ) . ' WHERE passage_id = %d', $pid ) ) );
		if ( $ids ) $wpdb->query( 'DELETE FROM ' . self::t( 'passage_evaluations' ) . ' WHERE candidat_id IN (' . implode( ',', $ids ) . ')' );
		$wpdb->delete( self::t( 'passage_candidats' ), [ 'passage_id' => $pid ] );
		$wpdb->delete( self::t( 'passage_juges' ), [ 'passage_id' => $pid ] );
		$wpdb->delete( self::t( 'passages' ), [ 'id' => $pid ] );
		// L'événement « examen » a été créé par le passage : il part avec lui (sauf s'il porte déjà des présences).
		if ( $p->event_id && ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->db->table_presences_eleves() . ' WHERE event_id = %d', $p->event_id ) ) ) {
			$wpdb->delete( $this->db->table_events(), [ 'id' => intval( $p->event_id ), 'type' => 'examen' ] );
		}
		self::retour( [ 'msg' => 'suppr' ] );
	}

	public function handle_epreuve(): void {
		$this->exiger( 'sp_passage_epreuve' );
		global $wpdb;
		$id     = intval( $_POST['id'] ?? 0 );
		$portee = sanitize_key( $_POST['portee'] ?? 'tous' );
		if ( ! in_array( $portee, [ 'tous', 'categorie', 'grades' ], true ) ) $portee = 'tous';
		$type   = sanitize_key( $_POST['type'] ?? 'niveaux' );
		if ( ! in_array( $type, [ 'niveaux', 'note', 'mesure' ], true ) ) $type = 'niveaux';
		$val    = '';
		if ( $portee === 'categorie' ) $val = sanitize_text_field( wp_unslash( $_POST['portee_categorie'] ?? '' ) );
		if ( $portee === 'grades' ) $val = wp_json_encode( array_values( array_map( static fn( $g ) => sanitize_text_field( wp_unslash( $g ) ), (array) ( $_POST['portee_grades'] ?? [] ) ) ) );
		$num    = static function ( $k ) {
			$v = trim( (string) wp_unslash( $_POST[ $k ] ?? '' ) );
			return $v === '' ? null : floatval( str_replace( ',', '.', $v ) );
		};
		$row = [
			'nom'           => sanitize_text_field( wp_unslash( $_POST['nom'] ?? '' ) ),
			'portee'        => $portee,
			'portee_valeur' => $val,
			'type'          => $type,
			'seuil_acquis'  => $type === 'niveaux' ? null : $num( 'seuil_acquis' ),
			'seuil_revoir'  => $type === 'niveaux' ? null : $num( 'seuil_revoir' ),
			'unite'         => $type === 'mesure' ? sanitize_text_field( wp_unslash( $_POST['unite'] ?? '' ) ) : '',
			'ordre'         => intval( $_POST['ordre'] ?? 0 ),
			'actif'         => empty( $_POST['actif'] ) ? 0 : 1,
		];
		if ( $row['nom'] === '' ) self::retour( [ 'tab' => 'epreuves' ] );
		if ( $id ) $wpdb->update( self::t( 'passage_epreuves' ), $row, [ 'id' => $id ] );
		else       $wpdb->insert( self::t( 'passage_epreuves' ), $row );
		self::retour( [ 'tab' => 'epreuves', 'msg' => 'ep_ok' ] );
	}

	public function handle_epreuve_suppr(): void {
		$this->exiger( 'sp_passage_epreuve_suppr' );
		global $wpdb;
		$wpdb->delete( self::t( 'passage_epreuves' ), [ 'id' => intval( $_POST['id'] ?? 0 ) ] );
		self::retour( [ 'tab' => 'epreuves', 'msg' => 'ep_suppr' ] );
	}

	// ══════════════════════════════════════════════════════════════════════
	// PAGE DU JUGE / ÉCRAN DE VALIDATION (hors thème)
	// ══════════════════════════════════════════════════════════════════════

	public function maybe_render_app(): void {
		$token = isset( $_GET['sp_passage'] ) ? sanitize_text_field( wp_unslash( $_GET['sp_passage'] ) ) : '';
		$admin = intval( $_GET['sp_passage_admin'] ?? 0 );
		if ( $token === '' && ! $admin ) return;

		$cfg = [ 'ajax' => admin_url( 'admin-ajax.php' ) ];
		if ( $token !== '' ) {
			if ( ! $this->juge_par_token( $token ) ) {
				status_header( 403 );
				nocache_headers();
				echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><p style="font-family:sans-serif;padding:24px">Lien de juge invalide. Demandez un nouveau lien à l\'organisateur du passage.</p>';
				exit;
			}
			$cfg['token'] = $token;
		} else {
			if ( ! is_user_logged_in() ) auth_redirect();
			if ( ! current_user_can( self::CAP ) ) wp_die( 'Accès refusé.', 'Passage de grade', [ 'response' => 403 ] );
			$cfg['passage'] = $admin;
			$cfg['nonce']   = wp_create_nonce( 'sp_passage_admin_' . $admin );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		$css = SP_CAL_PRO_URL . 'assets/css/passages-juge.css?ver=' . rawurlencode( sp_cal_asset_ver( 'assets/css/passages-juge.css' ) );
		$js  = SP_CAL_PRO_URL . 'assets/js/passages-juge.js?ver=' . rawurlencode( sp_cal_asset_ver( 'assets/js/passages-juge.js' ) );
		echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
			. '<meta name="theme-color" content="#0d0d0d"><meta name="robots" content="noindex">'
			. '<title>Passage de grade</title>'
			. '<link rel="stylesheet" href="' . esc_url( $css ) . '"></head><body>'
			. '<div id="pg-app"><p class="pg-chargement">Chargement…</p></div>'
			. '<script>window.SP_PASSAGE = ' . wp_json_encode( $cfg ) . ';</script>'
			. '<script src="' . esc_url( $js ) . '"></script>'
			. '</body></html>';
		exit;
	}

	private function juge_par_token( string $token ) {
		global $wpdb;
		if ( strlen( $token ) < 20 ) return null;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'passage_juges' ) . ' WHERE token = %s', $token ) );
	}

	// ══════════════════════════════════════════════════════════════════════
	// AJAX
	// ══════════════════════════════════════════════════════════════════════

	/**
	 * Qui appelle : un juge (jeton personnel) ou un administrateur connecté (écran de validation).
	 * @return array [ passage, juge|null, peut_valider ]
	 */
	private function contexte(): array {
		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		if ( $token !== '' ) {
			$juge = $this->juge_par_token( $token );
			$p    = $juge ? $this->get_passage( intval( $juge->passage_id ) ) : null;
			if ( ! $p ) wp_send_json_error( 'Lien de juge invalide.', 403 );
			return [ $p, $juge, (int) $juge->president === 1 ];
		}
		$pid = intval( $_POST['passage'] ?? 0 );
		if ( ! current_user_can( self::CAP ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'sp_passage_admin_' . $pid ) ) {
			wp_send_json_error( 'Session expirée : rechargez la page.', 403 );
		}
		$p = $this->get_passage( $pid );
		if ( ! $p ) wp_send_json_error( 'Passage introuvable.', 404 );
		return [ $p, null, true ];
	}

	private static function passage_public( $p ): array {
		return [
			'id' => intval( $p->id ), 'titre' => $p->titre, 'date' => $p->date, 'date_fr' => self::date_fr( $p->date ),
			'statut' => $p->statut, 'poom_seuil' => floatval( $p->poom_seuil ), 'poom_plancher' => floatval( $p->poom_plancher ),
		];
	}

	public function ajax_data(): void {
		[ $p, $juge, $peut_valider ] = $this->contexte();
		global $wpdb;
		$cands = $this->get_candidats( intval( $p->id ) );
		$mes   = [];
		if ( $juge && $cands ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'passage_evaluations' ) . ' WHERE juge_id = %d', intval( $juge->id ) ) );
			foreach ( $rows as $r ) {
				$mes[ intval( $r->candidat_id ) ][ $r->critere ] = $r->critere === '_remarque'
					? [ 'remarque' => (string) $r->remarque ]
					: [ 'niveau' => $r->niveau !== null ? intval( $r->niveau ) : null, 'valeur' => $r->valeur !== null ? floatval( $r->valeur ) : null ];
			}
		}
		wp_send_json_success( [
			'passage'      => self::passage_public( $p ),
			'moi'          => $juge ? [ 'id' => intval( $juge->id ), 'nom' => $juge->nom, 'president' => (int) $juge->president === 1 ] : null,
			'peut_valider' => $peut_valider,
			'candidats'    => array_map( [ __CLASS__, 'candidat_public' ], $cands ),
			'mes_evals'    => (object) $mes,
		] );
	}

	/**
	 * Enregistrement d'une évaluation (juge uniquement, passage ouvert). Une ligne par
	 * candidat × juge × critère, mise à jour sur place : renvoyer deux fois le même geste
	 * (file d'envoi hors ligne) ne change rien. Valeur vide → la ligne est effacée.
	 */
	public function ajax_noter(): void {
		[ $p, $juge ] = $this->contexte();
		if ( ! $juge ) wp_send_json_error( 'Seuls les juges notent.', 403 );
		if ( $p->statut !== 'ouvert' ) wp_send_json_error( 'La notation est fermée.', 409 );
		global $wpdb;
		$cid  = intval( $_POST['candidat'] ?? 0 );
		$crit = sanitize_text_field( wp_unslash( $_POST['critere'] ?? '' ) );
		$cand = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'passage_candidats' ) . ' WHERE id = %d AND passage_id = %d', $cid, intval( $p->id ) ) );
		if ( ! $cand ) wp_send_json_error( 'Candidat retiré du passage.', 404 );
		$te  = self::t( 'passage_evaluations' );
		$cle = [ 'candidat_id' => $cid, 'juge_id' => intval( $juge->id ), 'critere' => $crit ];

		if ( $crit === '_remarque' ) {
			$txt = sanitize_textarea_field( wp_unslash( $_POST['remarque'] ?? '' ) );
			if ( $txt === '' ) { $wpdb->delete( $te, $cle ); wp_send_json_success(); }
			$niveau = null; $valeur = null;
		} else {
			$def = null;
			foreach ( (array) json_decode( (string) $cand->criteres, true ) as $c ) if ( $c['cle'] === $crit ) $def = $c;
			if ( ! $def ) wp_send_json_error( 'Critère inconnu pour ce candidat.', 400 );
			$brut = trim( (string) wp_unslash( $_POST['valeur'] ?? '' ) );
			if ( $brut === '' ) { $wpdb->delete( $te, $cle ); wp_send_json_success(); }
			$saisie_note = $cand->mode === 'poom' || $def['type'] !== 'niveaux';
			$niveau = null; $valeur = null; $txt = null;
			if ( $saisie_note ) {
				$valeur = floatval( str_replace( ',', '.', $brut ) );
				if ( $def['type'] !== 'mesure' && ( $valeur < 0 || $valeur > 10 ) ) wp_send_json_error( 'Note entre 0 et 10.', 400 );
				if ( $valeur < 0 || $valeur > 999999 ) wp_send_json_error( 'Valeur invalide.', 400 );
			} else {
				$niveau = intval( $brut );
				if ( ! in_array( $niveau, [ self::NON, self::REVOIR, self::ACQUIS ], true ) ) wp_send_json_error( 'Niveau invalide.', 400 );
			}
		}
		// prepare() changerait null en '' (refusé par MySQL strict sur une colonne numérique) :
		// niveau et valeur sont écrits en clair, déjà convertis en nombres ci-dessus.
		$niv_sql = $niveau === null ? 'NULL' : (string) intval( $niveau );
		$val_sql = $valeur === null ? 'NULL' : number_format( (float) $valeur, 2, '.', '' );
		$ok = $wpdb->query( $wpdb->prepare(
			"INSERT INTO $te (candidat_id, juge_id, critere, niveau, valeur, remarque, updated_at) VALUES (%d, %d, %s, $niv_sql, $val_sql, %s, %s)
			 ON DUPLICATE KEY UPDATE niveau = VALUES(niveau), valeur = VALUES(valeur), remarque = VALUES(remarque), updated_at = VALUES(updated_at)",
			$cid, intval( $juge->id ), $crit, (string) $txt, current_time( 'mysql' )
		) );
		if ( $ok === false ) wp_send_json_error( 'Erreur d\'enregistrement, réessayez.', 500 );
		wp_send_json_success();
	}

	public function ajax_resultats(): void {
		[ $p, , $peut_valider ] = $this->contexte();
		if ( ! $peut_valider ) wp_send_json_error( 'Réservé au président de jury.', 403 );
		wp_send_json_success( [
			'passage'   => self::passage_public( $p ),
			'candidats' => $this->resultats( $p ),
			'juges'     => array_map( static fn( $j ) => [ 'nom' => $j->nom, 'president' => (int) $j->president === 1 ], $this->get_juges( intval( $p->id ) ) ),
		] );
	}

	/** Le président tranche une égalité sur un critère keup (valeur vide = annuler). */
	public function ajax_arbitrer(): void {
		[ $p, , $peut_valider ] = $this->contexte();
		if ( ! $peut_valider ) wp_send_json_error( 'Réservé au président de jury.', 403 );
		if ( $p->statut !== 'ouvert' ) wp_send_json_error( 'La notation est fermée.', 409 );
		global $wpdb;
		$cid  = intval( $_POST['candidat'] ?? 0 );
		$crit = sanitize_text_field( wp_unslash( $_POST['critere'] ?? '' ) );
		$cand = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'passage_candidats' ) . ' WHERE id = %d AND passage_id = %d', $cid, intval( $p->id ) ) );
		if ( ! $cand ) wp_send_json_error( 'Candidat introuvable.', 404 );
		$arb  = json_decode( (string) $cand->arbitrages, true ) ?: [];
		$brut = trim( (string) wp_unslash( $_POST['niveau'] ?? '' ) );
		if ( $brut === '' ) {
			unset( $arb[ $crit ] );
		} else {
			$n = intval( $brut );
			if ( ! in_array( $n, [ self::NON, self::REVOIR, self::ACQUIS ], true ) ) wp_send_json_error( 'Niveau invalide.', 400 );
			$arb[ $crit ] = $n;
		}
		$wpdb->update( self::t( 'passage_candidats' ), [ 'arbitrages' => $arb ? wp_json_encode( $arb ) : null ], [ 'id' => $cid ] );
		wp_send_json_success( [ 'candidats' => $this->resultats( $p ) ] );
	}

	/**
	 * Validation finale : une décision (admis / ajourné) par candidat, grade obtenu pour les
	 * admis (grade visé par défaut, modifiable pour un saut). Écrit le grade sur la fiche
	 * (SpCalPro_DB::save_exam_passage(), comme l'ancien module), la note de la présence à
	 * l'examen (frise de la fiche), puis verrouille le passage.
	 */
	public function ajax_valider(): void {
		[ $p, $juge, $peut_valider ] = $this->contexte();
		if ( ! $peut_valider ) wp_send_json_error( 'Réservé au président de jury.', 403 );
		if ( $p->statut !== 'ouvert' ) wp_send_json_error( 'Ce passage n\'est pas ouvert à la validation.', 409 );
		global $wpdb;
		$decisions = json_decode( wp_unslash( (string) ( $_POST['decisions'] ?? '' ) ), true );
		if ( ! is_array( $decisions ) ) wp_send_json_error( 'Décisions manquantes.', 400 );

		$cands = $this->get_candidats( intval( $p->id ) );
		foreach ( $cands as $c ) {
			$d = $decisions[ $c->id ] ?? null;
			if ( ! $d || ! in_array( $d['decision'] ?? '', [ 'admis', 'ajourne' ], true ) ) {
				wp_send_json_error( 'Décision manquante pour ' . $c->prenom . ' ' . $c->nom . '.', 400 );
			}
			$g = trim( sanitize_text_field( (string) ( $d['grade'] ?? '' ) ) );
			if ( $d['decision'] === 'admis' && ( $g === '' || self::est_dan( $g ) ) ) {
				wp_send_json_error( 'Grade obtenu manquant ou invalide pour ' . $c->prenom . ' ' . $c->nom . '.', 400 );
			}
		}

		$tpe = $this->db->table_presences_eleves();
		foreach ( $cands as $c ) {
			$d     = $decisions[ $c->id ];
			$admis = $d['decision'] === 'admis';
			$g     = $admis ? trim( sanitize_text_field( (string) $d['grade'] ) ) : '';
			$wpdb->update( self::t( 'passage_candidats' ), [ 'decision' => $d['decision'], 'grade_obtenu' => $g ], [ 'id' => intval( $c->id ) ] );
			$this->db->save_exam_passage( intval( $p->event_id ), intval( $c->eleve_id ), $admis ? 1 : 0, $g );
			if ( $admis && $p->event_id ) {
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO $tpe (event_id, eleve_id, present, note) VALUES (%d, %d, 1, %s) ON DUPLICATE KEY UPDATE present = 1, note = VALUES(note)",
					intval( $p->event_id ), intval( $c->eleve_id ), $g
				) );
				do_action( 'sp_cal_passage_grade_valide', intval( $c->eleve_id ), (string) $c->grade_actuel, $g, intval( $p->id ) );
			}
		}
		$par = $juge ? $juge->nom : wp_get_current_user()->display_name;
		$wpdb->update( self::t( 'passages' ), [ 'statut' => 'valide', 'valide_at' => current_time( 'mysql' ), 'valide_par' => (string) $par ], [ 'id' => intval( $p->id ) ] );
		$p = $this->get_passage( intval( $p->id ) );
		wp_send_json_success( [ 'passage' => self::passage_public( $p ), 'candidats' => $this->resultats( $p ) ] );
	}
}

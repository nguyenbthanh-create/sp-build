<?php
/**
 * Gestion des doboks prêtés par le club — V1 (stock, achats, journal, dotations).
 * Spécification complète : EVOLUTION.md, entrée du 25/09/2026.
 *
 * Principe : AUCUN compteur de stock n'est stocké. Tout est déduit d'un journal de
 * mouvements (achat, remise, restitution, inventaire…) : chaque ligne porte son effet
 * sur le stock du club (mvt_stock) et sur la dotation de l'adhérent (mvt_eleve), calculé
 * une fois pour toutes à l'insertion par effets_mouvement(). Stock effectif = SUM(mvt_stock),
 * ce que détient un adhérent = SUM(mvt_eleve) — impossible de désynchroniser les deux.
 *
 * Une « référence » n'a pas de table : c'est le couple modèle × taille. Le modèle attendu
 * d'un adhérent est déduit automatiquement (jamais choisi à la main) :
 *   - blanc   : col noir si ceinture noire (Dan / Poom), sinon col blanc ;
 *   - couleur : catégorie de compétition (année de naissance) × sexe, acheté par ensemble.
 * Un adhérent peut garder son ancien modèle / sa taille : les écarts sont signalés, pas imposés.
 *
 * Hors V1 (cf. EVOLUTION.md) : demandes côté adhérent (fiche token), réservations,
 * écran mobile de distribution, mails, aide à la commande.
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Dobok {

	private static ?self $instance = null;
	private $db;

	const SCHEMA_VERSION = '1';
	const PAGE           = 'sp-cal-dobok';

	const MODELES = [
		'col_blanc' => [ 'type' => 'blanc',   'label' => 'Blanc col blanc',     'detail' => 'Grades couleur' ],
		'col_noir'  => [ 'type' => 'blanc',   'label' => 'Blanc col noir',      'detail' => 'Ceintures noires (Dan / Poom)' ],
		'cadet_g'   => [ 'type' => 'couleur', 'label' => 'Cadet garçon',        'detail' => 'Col rouge et noir, pantalon bleu' ],
		'cadet_f'   => [ 'type' => 'couleur', 'label' => 'Cadet fille',         'detail' => 'Col rouge et noir, pantalon rouge' ],
		'js_h'      => [ 'type' => 'couleur', 'label' => 'Junior/Senior homme', 'detail' => 'Col bleu foncé/noir, pantalon bleu foncé/noir' ],
		'js_f'      => [ 'type' => 'couleur', 'label' => 'Junior/Senior femme', 'detail' => 'Col bleu foncé/noir, pantalon bleu clair' ],
		'master'    => [ 'type' => 'couleur', 'label' => 'Master',              'detail' => 'Bleu foncé' ],
	];

	const TYPES_MVT = [
		'achat'             => 'Réception achat',
		'inventaire'        => 'Ajustement inventaire',
		'remise'            => 'Remise à l\'adhérent',
		'dotation_initiale' => 'Dotation existante',
		'restitution'       => 'Restitution',
		'perte'             => 'Non rendu / perdu',
		'reforme'           => 'Réforme (sortie du stock)',
	];

	const ETATS = [
		'bon'        => 'Bon état',
		'use'        => 'Usé',
		'a_reformer' => 'À réformer',
	];

	const MOTIFS = [
		'taille'       => 'Changement de taille',
		'modele'       => 'Changement de modèle',
		'remplacement' => 'Remplacement (abîmé)',
		'retour'       => 'Retour (départ, plus besoin)',
	];

	const REGLAGES_DEFAUT = [
		'taille_min'     => 100,
		'taille_max'     => 200,
		'marge'          => 0,
		'seuil_alerte'   => 1,
		'cadet_age_max'  => 14,
		'master_age_min' => 50,
		'annee_ref'      => 'fin',
	];

	public static function get_instance( $db = null ): self {
		if ( self::$instance === null ) self::$instance = new self( $db );
		return self::$instance;
	}

	private function __construct( $db = null ) {
		$this->db = $db;

		add_action( 'admin_init',            [ $this, 'maybe_create_tables' ] );
		add_action( 'admin_menu',            [ $this, 'add_menu' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );

		foreach ( [ 'remise', 'restitution', 'perte', 'confirmer', 'corriger', 'presumer',
		            'lot', 'supprimer_lot', 'inventaire', 'supprimer_mvt', 'reglages' ] as $a ) {
			add_action( 'admin_post_sp_dobok_' . $a, [ $this, 'handle_' . $a ] );
		}
	}

	// ══════════════════════════════════════════════════════════════════════
	// SCHÉMA
	// ══════════════════════════════════════════════════════════════════════
	private function t_lots(): string { global $wpdb; return $wpdb->prefix . 'sp_cal_dobok_lots'; }
	private function t_mvt(): string  { global $wpdb; return $wpdb->prefix . 'sp_cal_dobok_mouvements'; }

	private function table_eleves(): string {
		global $wpdb;
		return $this->db ? $this->db->table_eleves() : $wpdb->prefix . 'sp_cal_eleves';
	}

	private function tables_ok(): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->t_mvt() ) ) === $this->t_mvt()
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->t_lots() ) ) === $this->t_lots();
	}

	// Même garde de version que SP_Front_Adhesion::create_table() : la version n'est
	// enregistrée que si les tables existent réellement (dbDelta peut échouer en silence
	// sur l'hébergement — droits CREATE/ALTER), pour retenter au prochain chargement.
	public function maybe_create_tables(): void {
		if ( get_option( 'sp_dobok_schema_version' ) === self::SCHEMA_VERSION ) return;

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "CREATE TABLE {$this->t_lots()} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			modele varchar(20) NOT NULL DEFAULT '',
			taille smallint(6) NOT NULL DEFAULT 0,
			quantite smallint(6) NOT NULL DEFAULT 0,
			date_achat date DEFAULT NULL,
			prix_unitaire decimal(8,2) DEFAULT NULL,
			fournisseur varchar(150) NOT NULL DEFAULT '',
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id)
		) $charset;" );

		dbDelta( "CREATE TABLE {$this->t_mvt()} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT '',
			modele varchar(20) NOT NULL DEFAULT '',
			taille smallint(6) NOT NULL DEFAULT 0,
			quantite smallint(6) NOT NULL DEFAULT 1,
			mvt_stock int(11) NOT NULL DEFAULT 0,
			mvt_eleve int(11) NOT NULL DEFAULT 0,
			eleve_id mediumint(9) DEFAULT NULL,
			etat varchar(20) NOT NULL DEFAULT '',
			motif varchar(20) NOT NULL DEFAULT '',
			saison varchar(20) NOT NULL DEFAULT '',
			lot_id bigint(20) unsigned DEFAULT NULL,
			a_confirmer tinyint(1) NOT NULL DEFAULT 0,
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY eleve_id (eleve_id),
			KEY ref (modele,taille)
		) $charset;" );

		if ( $this->tables_ok() ) {
			update_option( 'sp_dobok_schema_version', self::SCHEMA_VERSION );
		} else {
			error_log( '[SP_Build] Tables dobok non créées — vérifier les droits CREATE TABLE de l\'utilisateur MySQL.' );
		}
	}

	// ══════════════════════════════════════════════════════════════════════
	// MENU / ASSETS
	// ══════════════════════════════════════════════════════════════════════
	public function add_menu(): void {
		add_submenu_page( 'sp-cal-pro', 'Doboks', '🥋 Doboks', SP_Cal_Roles::CAP_GESTION_ADHESIONS, self::PAGE, [ $this, 'render_page' ] );
	}

	public function enqueue( $hook ): void {
		if ( strpos( (string) $hook, self::PAGE ) === false ) return;
		wp_enqueue_style( 'sp-cal-dobok', SP_CAL_PRO_URL . 'assets/css/dobok-admin.css', [], SP_CAL_PRO_VERSION );
	}

	// ══════════════════════════════════════════════════════════════════════
	// RÈGLES MÉTIER
	// ══════════════════════════════════════════════════════════════════════
	private function reglages(): array {
		$r = get_option( 'sp_dobok_reglages', [] );
		return array_merge( self::REGLAGES_DEFAUT, is_array( $r ) ? $r : [] );
	}

	private function tailles(): array {
		$r = $this->reglages();
		return range( (int) $r['taille_min'], (int) $r['taille_max'], 10 );
	}

	private static function modeles_du_type( string $type ): array {
		return array_filter( self::MODELES, fn( $m ) => $m['type'] === $type );
	}

	private static function type_de( string $modele ): string {
		return self::MODELES[ $modele ]['type'] ?? '';
	}

	private static function label( string $modele ): string {
		return self::MODELES[ $modele ]['label'] ?? $modele;
	}

	/** Même calcul que SP_Admin_Adhesions::create_member() quand l'option n'est pas posée. */
	private function saison_courante(): string {
		$s = (string) get_option( 'tkd_saison_courante', '' );
		if ( $s ) return $s;
		$y = (int) current_time( 'Y' );
		$m = (int) current_time( 'n' );
		return $m >= 9 ? $y . '/' . ( $y + 1 ) : ( $y - 1 ) . '/' . $y;
	}

	/** taille_cm est un champ libre : "128", "128 cm", "1,28", "1m28"… */
	public static function parse_cm( $raw ): ?float {
		$s = str_replace( ',', '.', mb_strtolower( trim( (string) $raw ) ) );
		if ( preg_match( '/^(\d)\s*m\s*(\d{1,2})/', $s, $m ) ) {
			$v = (int) $m[1] * 100 + (int) str_pad( $m[2], 2, '0' );
		} elseif ( preg_match( '/\d+(?:\.\d+)?/', $s, $m ) ) {
			$v = (float) $m[0];
			if ( $v > 0 && $v < 3 ) $v *= 100;
		} else {
			return null;
		}
		return ( $v >= 50 && $v <= 250 ) ? (float) $v : null;
	}

	/** Taille arrondie à la dizaine supérieure (+ marge de croissance éventuelle), bornée à la gamme. */
	private function taille_pour( ?float $cm, int $marge = 0 ): ?int {
		if ( $cm === null ) return null;
		$r = $this->reglages();
		$t = (int) ( ceil( ( $cm + $marge ) / 10 ) * 10 );
		return max( (int) $r['taille_min'], min( (int) $r['taille_max'], $t ) );
	}

	public static function est_ceinture_noire( $grade ): bool {
		return (bool) preg_match( '/\b(dan|poom|noire)\b/iu', (string) $grade );
	}

	private static function annee_naissance( object $el ): ?int {
		if ( preg_match( '/^\d{4}$/', (string) ( $el->annee_naissance ?? '' ) ) ) return (int) $el->annee_naissance;
		if ( preg_match( '/(\d{4})/', (string) ( $el->date_naissance ?? '' ), $m ) ) return (int) $m[1];
		return null;
	}

	private static function sexe( object $el ): string {
		$extra = json_decode( (string) ( $el->extra_data ?? '' ), true );
		$s     = is_array( $extra ) ? ( $extra['sexe'] ?? '' ) : '';
		if ( ! $s && isset( $el->sexe ) ) $s = $el->sexe;
		return in_array( $s, [ 'M', 'F' ], true ) ? $s : '';
	}

	/** Âge de compétition : année de référence de la saison − année de naissance. */
	private function age_competition( object $el, string $saison ): ?int {
		$an = self::annee_naissance( $el );
		if ( ! $an || ! preg_match( '#^(\d{4})/(\d{4})$#', $saison, $m ) ) return null;
		$ref = $this->reglages()['annee_ref'] === 'debut' ? (int) $m[1] : (int) $m[2];
		return $ref - $an;
	}

	private function categorie_competition( ?int $age ): string {
		if ( $age === null ) return '';
		$r = $this->reglages();
		if ( $age >= (int) $r['master_age_min'] ) return 'Master';
		if ( $age > (int) $r['cadet_age_max'] )   return 'Junior/Senior';
		return 'Cadet';   // les moins de 12 ans portent le modèle cadet (décision 25/09/2026)
	}

	/** Modèle attendu pour un type, ou '' si indéterminable (naissance / sexe manquants). */
	private function modele_attendu( object $el, string $type, string $saison ): string {
		if ( $type === 'blanc' ) return self::est_ceinture_noire( $el->grade ?? '' ) ? 'col_noir' : 'col_blanc';

		$cat = $this->categorie_competition( $this->age_competition( $el, $saison ) );
		if ( $cat === 'Master' ) return 'master';
		$sexe = self::sexe( $el );
		if ( $cat === '' || $sexe === '' ) return '';
		if ( $cat === 'Cadet' ) return $sexe === 'M' ? 'cadet_g' : 'cadet_f';
		return $sexe === 'M' ? 'js_h' : 'js_f';
	}

	// ══════════════════════════════════════════════════════════════════════
	// ACCÈS AUX DONNÉES
	// ══════════════════════════════════════════════════════════════════════

	/** Effet d'un mouvement sur [stock du club, dotation de l'adhérent]. */
	private static function effets_mouvement( string $type, int $qte, string $etat = '', int $sens = 1 ): array {
		switch ( $type ) {
			case 'achat':             return [ $qte, 0 ];
			case 'inventaire':        return [ $sens * $qte, 0 ];
			case 'remise':            return [ -$qte, $qte ];
			case 'dotation_initiale': return [ 0, $qte ];
			// Un dobok à réformer quitte l'adhérent sans rentrer dans le stock utilisable.
			case 'restitution':       return [ $etat === 'a_reformer' ? 0 : $qte, -$qte ];
			case 'perte':             return [ 0, -$qte ];
			case 'reforme':           return [ -$qte, 0 ];
		}
		return [ 0, 0 ];
	}

	private function ajouter_mouvement( string $type, string $modele, int $taille, int $qte = 1, array $o = [] ): bool {
		global $wpdb;
		[ $ds, $de ] = self::effets_mouvement( $type, $qte, $o['etat'] ?? '', $o['sens'] ?? 1 );
		return (bool) $wpdb->insert( $this->t_mvt(), [
			'type'        => $type,
			'modele'      => $modele,
			'taille'      => $taille,
			'quantite'    => $qte,
			'mvt_stock'   => $ds,
			'mvt_eleve'   => $de,
			'eleve_id'    => $o['eleve_id'] ?? null,
			'etat'        => $o['etat']  ?? '',
			'motif'       => $o['motif'] ?? '',
			'saison'      => $this->saison_courante(),
			'lot_id'      => $o['lot_id'] ?? null,
			'a_confirmer' => ! empty( $o['a_confirmer'] ) ? 1 : 0,
			'note'        => mb_substr( (string) ( $o['note'] ?? '' ), 0, 255 ),
			'created_at'  => current_time( 'mysql' ),
			'created_by'  => get_current_user_id(),
		] );
	}

	/** [modele][taille] => ['stock' => n, 'pret' => n] */
	private function stock_par_ref(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT modele, taille, SUM(mvt_stock) AS stock, SUM(mvt_eleve) AS pret
			 FROM {$this->t_mvt()} GROUP BY modele, taille"
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ $r->modele ][ (int) $r->taille ] = [ 'stock' => (int) $r->stock, 'pret' => (int) $r->pret ];
		}
		return $out;
	}

	private function stock_de( string $modele, int $taille ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(mvt_stock),0) FROM {$this->t_mvt()} WHERE modele = %s AND taille = %d",
			$modele, $taille
		) );
	}

	/** Dotations en cours : [eleve_id] => [ ['modele','taille','nb','a_confirmer'], … ] */
	private function dotations( ?array $eleve_ids = null ): array {
		global $wpdb;
		$where = 'eleve_id IS NOT NULL';
		if ( $eleve_ids !== null ) {
			if ( ! $eleve_ids ) return [];
			$where .= ' AND eleve_id IN (' . implode( ',', array_map( 'intval', $eleve_ids ) ) . ')';
		}
		$rows = $wpdb->get_results(
			"SELECT eleve_id, modele, taille, SUM(mvt_eleve) AS nb, MAX(a_confirmer) AS a_confirmer
			 FROM {$this->t_mvt()} WHERE $where
			 GROUP BY eleve_id, modele, taille HAVING nb > 0
			 ORDER BY modele, taille"
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->eleve_id ][] = [
				'modele' => $r->modele, 'taille' => (int) $r->taille,
				'nb' => (int) $r->nb, 'a_confirmer' => (int) $r->a_confirmer,
			];
		}
		return $out;
	}

	private function detient( int $eleve_id, string $modele, int $taille ): bool {
		foreach ( $this->dotations( [ $eleve_id ] )[ $eleve_id ] ?? [] as $d ) {
			if ( $d['modele'] === $modele && $d['taille'] === $taille ) return true;
		}
		return false;
	}

	/** Nombre d'échanges de TAILLE de la saison : [eleve_id][type] => n */
	private function echanges_taille( string $saison ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT eleve_id, modele, COUNT(*) AS n FROM {$this->t_mvt()}
			 WHERE type = 'restitution' AND motif = 'taille' AND saison = %s
			 GROUP BY eleve_id, modele",
			$saison
		) );
		$out = [];
		foreach ( (array) $rows as $r ) {
			$t = self::type_de( $r->modele );
			$out[ (int) $r->eleve_id ][ $t ] = ( $out[ (int) $r->eleve_id ][ $t ] ?? 0 ) + (int) $r->n;
		}
		return $out;
	}

	private function eleve( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_eleves()} WHERE id = %d", $id ) ) ?: null;
	}

	/** Saison proposée par défaut : la saison courante si elle a des actifs, sinon la plus fréquente. */
	private function saison_par_defaut(): string {
		global $wpdb;
		$tel = $this->table_eleves();
		$s   = $this->saison_courante();
		$n   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tel WHERE actif = 1 AND saison = %s", $s ) );
		if ( $n > 0 ) return $s;
		$f = (string) $wpdb->get_var( "SELECT saison FROM $tel WHERE actif = 1 AND saison != '' GROUP BY saison ORDER BY COUNT(*) DESC LIMIT 1" );
		return $f ?: $s;
	}

	/**
	 * Situation d'un adhérent pour un type : modèle et taille attendus, ce qu'il détient,
	 * alertes. Niveau 'warn' = à traiter, 'info' = à savoir (l'adhérent peut garder l'ancien).
	 */
	private function situation( object $el, string $type, array $detenus, string $saison, int $nb_echanges ): array {
		$cm       = self::parse_cm( $el->taille_cm ?? '' );
		$attendu  = $this->modele_attendu( $el, $type, $saison );
		$taille_m = $this->taille_pour( $cm );
		$sugg     = $this->taille_pour( $cm, (int) $this->reglages()['marge'] );
		$mine     = array_values( array_filter( $detenus, fn( $d ) => self::type_de( $d['modele'] ) === $type ) );
		$alertes  = [];
		$nom_type = $type === 'blanc' ? 'blanc' : 'couleur';

		if ( ! $mine ) {
			$alertes[] = [ 'warn', 'Aucun dobok ' . $nom_type ];
		}
		if ( count( $mine ) > 1 ) {
			$alertes[] = [ 'info', count( $mine ) . ' doboks ' . $nom_type . ' en sa possession' ];
		}
		foreach ( $mine as $d ) {
			if ( $d['a_confirmer'] ) $alertes[] = [ 'info', self::label( $d['modele'] ) . ' ' . $d['taille'] . ' : attribution présumée à confirmer' ];
			if ( $type === 'blanc' && $d['modele'] === 'col_blanc' && $attendu === 'col_noir' ) {
				$alertes[] = [ 'warn', 'Ceinture noire : col noir requis' ];
			} elseif ( $type === 'blanc' && $d['modele'] === 'col_noir' && $attendu === 'col_blanc' ) {
				$alertes[] = [ 'warn', 'Col noir sans ceinture noire enregistrée' ];
			} elseif ( $type === 'couleur' && $attendu && $d['modele'] !== $attendu ) {
				$alertes[] = [ 'info', 'Nouveau modèle possible : ' . self::label( $attendu ) ];
			}
			if ( $taille_m && $d['taille'] < $taille_m ) {
				$alertes[] = [ 'info', 'A grandi : ' . $d['taille'] . ' → ' . $taille_m . ' suggéré' ];
			}
		}
		if ( $nb_echanges > 1 ) {
			$alertes[] = [ 'warn', $nb_echanges . ' échanges de taille ' . $nom_type . ' cette saison (règle : 1)' ];
		}

		return [ 'attendu' => $attendu, 'suggestion' => $sugg, 'detenus' => $mine, 'alertes' => $alertes ];
	}

	/** Alertes de données manquantes, communes aux deux types. */
	private function alertes_donnees( object $el, string $saison ): array {
		$a = [];
		if ( self::parse_cm( $el->taille_cm ?? '' ) === null ) $a[] = [ 'warn', 'Taille (cm) manquante ou illisible' ];
		$age = $this->age_competition( $el, $saison );
		if ( $age === null ) $a[] = [ 'warn', 'Année de naissance manquante' ];
		elseif ( self::sexe( $el ) === '' && $this->categorie_competition( $age ) !== 'Master' ) $a[] = [ 'warn', 'Sexe non renseigné (modèle couleur indéterminable)' ];
		return $a;
	}

	// ══════════════════════════════════════════════════════════════════════
	// HANDLERS (admin-post.php)
	// ══════════════════════════════════════════════════════════════════════
	private function verifier( string $action ): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.', '', [ 'response' => 403 ] );
		check_admin_referer( 'sp_dobok_' . $action );
	}

	/** Retour à la page d'origine (onglet, filtres, adhérent ouvert) avec un message. */
	private function retour( string $msg, array $extra = [] ): void {
		$args = [ 'page' => self::PAGE ];
		foreach ( [ 'tab', 'saison', 'q', 'vue', 'f_type', 'f_modele' ] as $k ) {
			if ( isset( $_POST[ 'r_' . $k ] ) && $_POST[ 'r_' . $k ] !== '' ) {
				$args[ $k ] = sanitize_text_field( wp_unslash( $_POST[ 'r_' . $k ] ) );
			}
		}
		$args['msg'] = $msg;
		$url = add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
		if ( ! empty( $extra['open'] ) ) $url .= '#el-' . (int) $extra['open'];
		wp_safe_redirect( $url );
		exit;
	}

	private function post_modele( string $key = 'modele' ): string {
		$m = sanitize_key( $_POST[ $key ] ?? '' );
		return isset( self::MODELES[ $m ] ) ? $m : '';
	}

	private function post_taille( string $key = 'taille' ): int {
		$t = (int) ( $_POST[ $key ] ?? 0 );
		return ( $t >= 50 && $t <= 250 && $t % 10 === 0 ) ? $t : 0;
	}

	/** "modele|taille" → [modele, taille] validés, ou ['', 0]. */
	private static function split_ref( string $raw ): array {
		[ $m, $t ] = array_pad( explode( '|', $raw, 2 ), 2, '' );
		$m = sanitize_key( $m );
		return isset( self::MODELES[ $m ] ) && (int) $t > 0 ? [ $m, (int) $t ] : [ '', 0 ];
	}

	private function post_note(): string {
		return sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
	}

	/** Remise d'un dobok, avec restitution éventuelle de l'ancien (échange) en une seule saisie. */
	public function handle_remise(): void {
		$this->verifier( 'remise' );
		$eleve_id = (int) ( $_POST['eleve_id'] ?? 0 );
		$modele   = $this->post_modele();
		$taille   = $this->post_taille();
		if ( ! $eleve_id || ! $this->eleve( $eleve_id ) || ! $modele || ! $taille ) $this->retour( 'err_saisie', [ 'open' => $eleve_id ] );

		// Dobok déjà en possession (reprise de l'existant) : aucune sortie de stock, et
		// aucun échange — le champ « en échange de » est ignoré dans ce cas.
		if ( ! empty( $_POST['deja'] ) ) {
			$this->ajouter_mouvement( 'dotation_initiale', $modele, $taille, 1, [ 'eleve_id' => $eleve_id, 'note' => $this->post_note() ] );
			$this->retour( 'ok_declare', [ 'open' => $eleve_id ] );
		}

		[ $r_modele, $r_taille ] = self::split_ref( (string) ( $_POST['rendu'] ?? '' ) );
		if ( $r_modele ) {
			if ( ! $this->detient( $eleve_id, $r_modele, $r_taille ) ) $this->retour( 'err_detenu', [ 'open' => $eleve_id ] );
			if ( $r_modele !== $modele )     $motif = 'modele';
			elseif ( $r_taille !== $taille ) $motif = 'taille';
			else                             $motif = 'remplacement';
			$etat = sanitize_key( $_POST['etat'] ?? 'bon' );
			$this->ajouter_mouvement( 'restitution', $r_modele, $r_taille, 1, [
				'eleve_id' => $eleve_id, 'etat' => isset( self::ETATS[ $etat ] ) ? $etat : 'bon',
				'motif' => $motif, 'note' => $this->post_note(),
			] );
			$this->effacer_a_confirmer( $eleve_id, $r_modele, $r_taille );
		}

		$this->ajouter_mouvement( 'remise', $modele, $taille, 1, [ 'eleve_id' => $eleve_id, 'note' => $this->post_note() ] );
		$msg = $r_modele ? 'ok_echange' : 'ok_remise';
		if ( $this->stock_de( $modele, $taille ) < 0 ) $msg .= '_negatif';
		$this->retour( $msg, [ 'open' => $eleve_id ] );
	}

	public function handle_restitution(): void {
		$this->verifier( 'restitution' );
		$eleve_id = (int) ( $_POST['eleve_id'] ?? 0 );
		[ $modele, $taille ] = self::split_ref( (string) ( $_POST['rendu'] ?? '' ) );
		if ( ! $modele || ! $this->detient( $eleve_id, $modele, $taille ) ) $this->retour( 'err_detenu', [ 'open' => $eleve_id ] );
		$etat  = sanitize_key( $_POST['etat'] ?? 'bon' );
		$motif = sanitize_key( $_POST['motif'] ?? 'retour' );
		$this->ajouter_mouvement( 'restitution', $modele, $taille, 1, [
			'eleve_id' => $eleve_id,
			'etat'     => isset( self::ETATS[ $etat ] ) ? $etat : 'bon',
			'motif'    => isset( self::MOTIFS[ $motif ] ) ? $motif : 'retour',
			'note'     => $this->post_note(),
		] );
		$this->effacer_a_confirmer( $eleve_id, $modele, $taille );
		$this->retour( 'ok_restitution', [ 'open' => $eleve_id ] );
	}

	public function handle_perte(): void {
		$this->verifier( 'perte' );
		$eleve_id = (int) ( $_POST['eleve_id'] ?? 0 );
		[ $modele, $taille ] = self::split_ref( (string) ( $_POST['rendu'] ?? '' ) );
		if ( ! $modele || ! $this->detient( $eleve_id, $modele, $taille ) ) $this->retour( 'err_detenu', [ 'open' => $eleve_id ] );
		$this->ajouter_mouvement( 'perte', $modele, $taille, 1, [ 'eleve_id' => $eleve_id, 'note' => $this->post_note() ] );
		$this->effacer_a_confirmer( $eleve_id, $modele, $taille );
		$this->retour( 'ok_perte', [ 'open' => $eleve_id ] );
	}

	private function effacer_a_confirmer( int $eleve_id, string $modele, int $taille ): void {
		global $wpdb;
		$wpdb->update( $this->t_mvt(), [ 'a_confirmer' => 0 ], [ 'eleve_id' => $eleve_id, 'modele' => $modele, 'taille' => $taille ] );
	}

	public function handle_confirmer(): void {
		$this->verifier( 'confirmer' );
		$eleve_id = (int) ( $_POST['eleve_id'] ?? 0 );
		[ $modele, $taille ] = self::split_ref( (string) ( $_POST['ref'] ?? '' ) );
		if ( $modele ) $this->effacer_a_confirmer( $eleve_id, $modele, $taille );
		$this->retour( 'ok_confirme', [ 'open' => $eleve_id ] );
	}

	/** Correction d'une attribution présumée : pas un échange physique, aucun effet sur le stock. */
	public function handle_corriger(): void {
		$this->verifier( 'corriger' );
		global $wpdb;
		$eleve_id = (int) ( $_POST['eleve_id'] ?? 0 );
		[ $a_modele, $a_taille ] = self::split_ref( (string) ( $_POST['ref'] ?? '' ) );
		$modele = $this->post_modele();
		$taille = $this->post_taille();
		if ( ! $a_modele || ! $modele || ! $taille ) $this->retour( 'err_saisie', [ 'open' => $eleve_id ] );
		$wpdb->delete( $this->t_mvt(), [
			'eleve_id' => $eleve_id, 'modele' => $a_modele, 'taille' => $a_taille,
			'type' => 'dotation_initiale', 'a_confirmer' => 1,
		] );
		$this->ajouter_mouvement( 'dotation_initiale', $modele, $taille, 1, [ 'eleve_id' => $eleve_id, 'note' => 'Correction de l\'attribution présumée' ] );
		$this->retour( 'ok_corrige', [ 'open' => $eleve_id ] );
	}

	/**
	 * Démarrage : attribue en masse, « à confirmer », le dobok présumé de chaque adhérent
	 * actif de la saison qui n'a encore rien d'enregistré pour ce type. Taille = taille (cm)
	 * arrondie à la dizaine supérieure, sans marge (on présume ce qu'il porte, pas ce qu'on
	 * lui donnerait). Aucun effet sur le stock : ces doboks sont déjà chez les adhérents.
	 */
	public function handle_presumer(): void {
		$this->verifier( 'presumer' );
		global $wpdb;
		$saison = sanitize_text_field( wp_unslash( $_POST['r_saison'] ?? '' ) ) ?: $this->saison_par_defaut();
		$eleves = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table_eleves()} WHERE actif = 1 AND saison = %s", $saison
		) );
		$dot  = $this->dotations( array_map( fn( $e ) => (int) $e->id, (array) $eleves ) );
		$faits = 0; $ignores = 0;
		foreach ( (array) $eleves as $el ) {
			$taille = $this->taille_pour( self::parse_cm( $el->taille_cm ?? '' ) );
			foreach ( [ 'blanc', 'couleur' ] as $type ) {
				$a_deja = array_filter( $dot[ (int) $el->id ] ?? [], fn( $d ) => self::type_de( $d['modele'] ) === $type );
				if ( $a_deja ) continue;
				$modele = $this->modele_attendu( $el, $type, $saison );
				if ( ! $modele || ! $taille ) { $ignores++; continue; }
				$this->ajouter_mouvement( 'dotation_initiale', $modele, $taille, 1, [
					'eleve_id' => (int) $el->id, 'a_confirmer' => 1, 'note' => 'Attribution présumée',
				] );
				$faits++;
			}
		}
		$this->retour( 'ok_presume', [ 'n' => $faits, 'n2' => $ignores ] );
	}

	public function handle_lot(): void {
		$this->verifier( 'lot' );
		global $wpdb;
		$modele = $this->post_modele();
		$taille = $this->post_taille();
		$qte    = (int) ( $_POST['quantite'] ?? 0 );
		$date   = sanitize_text_field( $_POST['date_achat'] ?? '' );
		if ( ! $modele || ! $taille || $qte < 1 || $qte > 999 ) $this->retour( 'err_saisie' );
		$prix = str_replace( ',', '.', (string) ( $_POST['prix_unitaire'] ?? '' ) );

		$wpdb->insert( $this->t_lots(), [
			'modele'        => $modele,
			'taille'        => $taille,
			'quantite'      => $qte,
			'date_achat'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_time( 'Y-m-d' ),
			'prix_unitaire' => is_numeric( $prix ) ? round( (float) $prix, 2 ) : null,
			'fournisseur'   => sanitize_text_field( wp_unslash( $_POST['fournisseur'] ?? '' ) ),
			'note'          => $this->post_note(),
			'created_at'    => current_time( 'mysql' ),
			'created_by'    => get_current_user_id(),
		] );
		$lot_id = (int) $wpdb->insert_id;
		if ( ! $lot_id ) $this->retour( 'err_bdd' );
		$this->ajouter_mouvement( 'achat', $modele, $taille, $qte, [ 'lot_id' => $lot_id, 'note' => 'Lot n° ' . $lot_id ] );
		$this->retour( 'ok_lot' );
	}

	public function handle_supprimer_lot(): void {
		$this->verifier( 'supprimer_lot' );
		global $wpdb;
		$lot_id = (int) ( $_POST['lot_id'] ?? 0 );
		$wpdb->delete( $this->t_mvt(),  [ 'lot_id' => $lot_id ] );
		$wpdb->delete( $this->t_lots(), [ 'id' => $lot_id ] );
		$this->retour( 'ok_supprime' );
	}

	/** Inventaire : l'écart entre le comptage saisi et le stock calculé devient un mouvement d'ajustement. */
	public function handle_inventaire(): void {
		$this->verifier( 'inventaire' );
		$stock  = $this->stock_par_ref();
		$compte = (array) ( $_POST['compte'] ?? [] );
		$n = 0;
		foreach ( $compte as $modele => $par_taille ) {
			$modele = sanitize_key( $modele );
			if ( ! isset( self::MODELES[ $modele ] ) || ! is_array( $par_taille ) ) continue;
			foreach ( $par_taille as $taille => $val ) {
				$val = trim( (string) $val );
				if ( $val === '' || ! is_numeric( $val ) ) continue;   // case laissée vide = non comptée
				$taille = (int) $taille;
				$ecart  = max( 0, (int) $val ) - ( $stock[ $modele ][ $taille ]['stock'] ?? 0 );
				if ( $ecart === 0 ) continue;
				$this->ajouter_mouvement( 'inventaire', $modele, $taille, abs( $ecart ), [
					'sens' => $ecart > 0 ? 1 : -1,
					'note' => 'Inventaire du ' . current_time( 'd/m/Y' ),
				] );
				$n++;
			}
		}
		$this->retour( 'ok_inventaire', [ 'n' => $n ] );
	}

	public function handle_supprimer_mvt(): void {
		$this->verifier( 'supprimer_mvt' );
		global $wpdb;
		$id  = (int) ( $_POST['mvt_id'] ?? 0 );
		$mvt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t_mvt()} WHERE id = %d", $id ) );
		if ( $mvt ) {
			// Un achat et son lot vont ensemble : supprimer l'un sans l'autre fausserait la liste des achats.
			if ( $mvt->type === 'achat' && $mvt->lot_id ) $wpdb->delete( $this->t_lots(), [ 'id' => (int) $mvt->lot_id ] );
			$wpdb->delete( $this->t_mvt(), [ 'id' => $id ] );
		}
		$this->retour( 'ok_supprime' );
	}

	public function handle_reglages(): void {
		$this->verifier( 'reglages' );
		$min = max( 50, min( 250, (int) round( (int) ( $_POST['taille_min'] ?? 100 ) / 10 ) * 10 ) );
		$max = max( $min, min( 250, (int) round( (int) ( $_POST['taille_max'] ?? 200 ) / 10 ) * 10 ) );
		update_option( 'sp_dobok_reglages', [
			'taille_min'     => $min,
			'taille_max'     => $max,
			'marge'          => max( 0, min( 20, (int) ( $_POST['marge'] ?? 0 ) ) ),
			'seuil_alerte'   => max( 0, min( 50, (int) ( $_POST['seuil_alerte'] ?? 1 ) ) ),
			'cadet_age_max'  => max( 5, min( 20, (int) ( $_POST['cadet_age_max'] ?? 14 ) ) ),
			'master_age_min' => max( 30, min( 80, (int) ( $_POST['master_age_min'] ?? 50 ) ) ),
			'annee_ref'      => ( $_POST['annee_ref'] ?? '' ) === 'debut' ? 'debut' : 'fin',
		] );
		$this->retour( 'ok_reglages' );
	}

	// ══════════════════════════════════════════════════════════════════════
	// PAGE
	// ══════════════════════════════════════════════════════════════════════
	public function render_page(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );

		$onglets = [ 'stock' => 'Stock', 'adherents' => 'Adhérents', 'achats' => 'Achats', 'journal' => 'Journal', 'reglages' => 'Réglages' ];
		$tab     = sanitize_key( $_GET['tab'] ?? 'stock' );
		if ( ! isset( $onglets[ $tab ] ) ) $tab = 'stock';

		echo '<div class="wrap sp-cal-wrap spd">';
		echo '<h1>🥋 Doboks</h1>';

		if ( ! $this->tables_ok() ) {
			delete_option( 'sp_dobok_schema_version' );   // force une nouvelle tentative au prochain chargement
			echo '<div class="notice notice-error"><p>Les tables de gestion des doboks n\'existent pas encore en base. Rechargez la page ; si le message persiste, les droits MySQL de l\'hébergement empêchent leur création (voir le journal d\'erreurs PHP).</p></div></div>';
			return;
		}

		$this->render_message();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $onglets as $k => $l ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $k ) ),
				$k === $tab ? ' nav-tab-active' : '', esc_html( $l ) );
		}
		echo '</nav><div class="spd-body">';

		switch ( $tab ) {
			case 'adherents': $this->render_adherents(); break;
			case 'achats':    $this->render_achats();    break;
			case 'journal':   $this->render_journal();   break;
			case 'reglages':  $this->render_reglages();  break;
			default:          $this->render_stock();
		}
		echo '</div></div>';
	}

	private function render_message(): void {
		$msg = sanitize_key( $_GET['msg'] ?? '' );
		if ( ! $msg ) return;
		$n  = (int) ( $_GET['n'] ?? 0 );
		$n2 = (int) ( $_GET['n2'] ?? 0 );
		$negatif = ' Attention : le stock calculé de cette taille est maintenant négatif — un inventaire permettra de le recaler.';
		$textes = [
			'ok_remise'           => [ 'success', 'Dobok remis.' ],
			'ok_remise_negatif'   => [ 'warning', 'Dobok remis.' . $negatif ],
			'ok_echange'          => [ 'success', 'Échange enregistré (restitution + remise).' ],
			'ok_echange_negatif'  => [ 'warning', 'Échange enregistré.' . $negatif ],
			'ok_declare'          => [ 'success', 'Dobok déjà en sa possession enregistré (sans sortie de stock).' ],
			'ok_restitution'      => [ 'success', 'Restitution enregistrée.' ],
			'ok_perte'            => [ 'success', 'Dobok déclaré non rendu / perdu.' ],
			'ok_confirme'         => [ 'success', 'Attribution confirmée.' ],
			'ok_corrige'          => [ 'success', 'Attribution corrigée.' ],
			'ok_presume'          => [ 'success', sprintf( '%d attribution(s) présumée(s) créée(s), à confirmer. %d ignorée(s) faute de taille, d\'année de naissance ou de sexe.', $n, $n2 ) ],
			'ok_lot'              => [ 'success', 'Lot d\'achat enregistré et ajouté au stock.' ],
			'ok_supprime'         => [ 'success', 'Suppression effectuée.' ],
			'ok_inventaire'       => [ 'success', sprintf( 'Inventaire enregistré : %d ajustement(s).', $n ) ],
			'ok_reglages'         => [ 'success', 'Réglages enregistrés.' ],
			'err_saisie'          => [ 'error',   'Saisie incomplète ou invalide.' ],
			'err_detenu'          => [ 'error',   'Ce dobok n\'est pas enregistré chez cet adhérent.' ],
			'err_bdd'             => [ 'error',   'Erreur d\'enregistrement en base.' ],
		];
		if ( ! isset( $textes[ $msg ] ) ) return;
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $textes[ $msg ][0] ), esc_html( $textes[ $msg ][1] ) );
	}

	/** Champs cachés : action, nonce, et contexte de retour. */
	private function form_open( string $action, array $retour = [], string $class = '' ): void {
		printf( '<form method="post" action="%s" class="%s">', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( $class ) );
		printf( '<input type="hidden" name="action" value="sp_dobok_%s">', esc_attr( $action ) );
		wp_nonce_field( 'sp_dobok_' . $action );
		foreach ( $retour as $k => $v ) {
			printf( '<input type="hidden" name="r_%s" value="%s">', esc_attr( $k ), esc_attr( $v ) );
		}
	}

	private function select_modele( string $name, string $type, string $selected ): string {
		$html = '<select name="' . esc_attr( $name ) . '">';
		foreach ( ( $type ? self::modeles_du_type( $type ) : self::MODELES ) as $k => $m ) {
			$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $k, $selected, false ), esc_html( $m['label'] ) );
		}
		return $html . '</select>';
	}

	private function select_taille( string $name, ?int $selected ): string {
		$html = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $this->tailles() as $t ) {
			$html .= sprintf( '<option value="%d"%s>%d</option>', $t, selected( $t, $selected, false ), $t );
		}
		return $html . '</select>';
	}

	private function select_etat(): string {
		$html = '<select name="etat">';
		foreach ( self::ETATS as $k => $l ) $html .= sprintf( '<option value="%s">%s</option>', esc_attr( $k ), esc_html( $l ) );
		return $html . '</select>';
	}

	// ── Onglet Stock ─────────────────────────────────────────────────────
	private function render_stock(): void {
		$stock  = $this->stock_par_ref();
		$seuil  = (int) $this->reglages()['seuil_alerte'];
		$tailles = $this->tailles();
		foreach ( $stock as $par_taille ) {
			foreach ( array_keys( $par_taille ) as $t ) if ( ! in_array( $t, $tailles, true ) ) $tailles[] = $t;
		}
		sort( $tailles );

		echo '<div class="sp-box"><h2>Stock par modèle et par taille</h2>';
		echo '<p class="description">Grand chiffre : doboks présents au club. Petit chiffre : doboks prêtés aux adhérents. <span class="spd-leg spd-bas">≤ seuil d\'alerte (' . (int) $seuil . ')</span> <span class="spd-leg spd-neg">négatif : inventaire à faire</span></p>';
		echo '<div class="spd-scroll"><table class="widefat spd-grille"><thead><tr><th>Modèle</th>';
		foreach ( $tailles as $t ) echo '<th>' . (int) $t . '</th>';
		echo '<th>Total</th></tr></thead><tbody>';

		foreach ( [ 'blanc' => 'Doboks blancs', 'couleur' => 'Doboks couleur' ] as $type => $titre ) {
			echo '<tr class="spd-sep"><td colspan="' . ( count( $tailles ) + 2 ) . '">' . esc_html( $titre ) . '</td></tr>';
			foreach ( self::modeles_du_type( $type ) as $k => $m ) {
				$tot_s = 0; $tot_p = 0;
				echo '<tr><th title="' . esc_attr( $m['detail'] ) . '">' . esc_html( $m['label'] ) . '</th>';
				foreach ( $tailles as $t ) {
					$s = $stock[ $k ][ $t ]['stock'] ?? 0;
					$p = $stock[ $k ][ $t ]['pret']  ?? 0;
					$tot_s += $s; $tot_p += $p;
					$cls = $s < 0 ? 'spd-neg' : ( $s <= $seuil ? 'spd-bas' : '' );
					printf( '<td class="%s"><strong>%d</strong>%s</td>', esc_attr( $cls ), $s, $p ? '<small>' . (int) $p . ' prêté' . ( $p > 1 ? 's' : '' ) . '</small>' : '' );
				}
				printf( '<td class="spd-tot"><strong>%d</strong><small>%d prêté%s</small></td></tr>', $tot_s, $tot_p, $tot_p > 1 ? 's' : '' );
			}
		}
		echo '</tbody></table></div></div>';

		// Inventaire (sert aussi à saisir le stock de départ)
		echo '<div class="sp-box"><details><summary><strong>Saisir un inventaire</strong> — comptage physique au club (sert aussi au stock de départ)</summary>';
		echo '<p class="description">Indiquez le nombre de doboks réellement présents. Chaque écart avec le stock calculé est enregistré comme un ajustement dans le journal. Une case laissée vide n\'est pas modifiée.</p>';
		$this->form_open( 'inventaire', [ 'tab' => 'stock' ] );
		echo '<div class="spd-scroll"><table class="widefat spd-grille spd-inv"><thead><tr><th>Modèle</th>';
		foreach ( $tailles as $t ) echo '<th>' . (int) $t . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( self::MODELES as $k => $m ) {
			echo '<tr><th>' . esc_html( $m['label'] ) . '</th>';
			foreach ( $tailles as $t ) {
				printf( '<td><input type="number" min="0" max="999" name="compte[%s][%d]" placeholder="%d"></td>',
					esc_attr( $k ), $t, $stock[ $k ][ $t ]['stock'] ?? 0 );
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p><button type="submit" class="button button-primary" onclick="return confirm(\'Enregistrer cet inventaire ?\');">Enregistrer l\'inventaire</button></p></form>';
		echo '</details></div>';
	}

	// ── Onglet Adhérents ─────────────────────────────────────────────────
	private function render_adherents(): void {
		global $wpdb;
		$tel     = $this->table_eleves();
		$saisons = $this->db ? (array) $this->db->get_saisons() : [];
		$saison  = sanitize_text_field( wp_unslash( $_GET['saison'] ?? '' ) ) ?: $this->saison_par_defaut();
		$q       = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$vue     = sanitize_key( $_GET['vue'] ?? '' );
		$open    = (int) ( $_GET['open'] ?? 0 );
		$retour  = [ 'tab' => 'adherents', 'saison' => $saison, 'q' => $q, 'vue' => $vue ];

		if ( $vue === 'recuperer' ) {
			// Doboks encore chez des adhérents qui ne sont plus actifs sur cette saison.
			$tous = $this->dotations();
			$ids  = array_keys( $tous );
			$eleves = $ids ? $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM $tel WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ") AND NOT ( actif = 1 AND saison = %s ) ORDER BY nom, prenom",
				$saison
			) ) : [];
		} else {
			$eleves = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM $tel WHERE actif = 1 AND saison = %s ORDER BY nom, prenom", $saison
			) );
		}
		if ( $q !== '' ) {
			$needle = mb_strtolower( $q );
			$eleves = array_filter( (array) $eleves, fn( $e ) => str_contains( mb_strtolower( $e->nom . ' ' . $e->prenom . ' ' . $e->prenom . ' ' . $e->nom ), $needle ) );
		}

		$dot  = $this->dotations( array_map( fn( $e ) => (int) $e->id, (array) $eleves ) );
		$ech  = $this->echanges_taille( $saison );
		$rows = [];
		$nb_alertes = 0;
		foreach ( (array) $eleves as $el ) {
			$id  = (int) $el->id;
			$sit = [];
			foreach ( [ 'blanc', 'couleur' ] as $type ) {
				$sit[ $type ] = $this->situation( $el, $type, $dot[ $id ] ?? [], $saison, $ech[ $id ][ $type ] ?? 0 );
			}
			$alertes = $vue === 'recuperer' ? [] : array_merge( $this->alertes_donnees( $el, $saison ), $sit['blanc']['alertes'], $sit['couleur']['alertes'] );
			$a_traiter = (bool) array_filter( $alertes, fn( $a ) => $a[0] === 'warn' );
			if ( $a_traiter ) $nb_alertes++;
			if ( $vue === 'alertes' && ! $alertes ) continue;
			$rows[] = [ $el, $sit, $alertes ];
		}

		// Barre de filtres
		echo '<form method="get" class="spd-filtres"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="adherents">';
		echo '<label>Saison <select name="saison">';
		foreach ( array_unique( array_merge( [ $saison ], $saisons ) ) as $s ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $s ), selected( $s, $saison, false ), esc_html( $s ) );
		}
		echo '</select></label>';
		echo '<label>Vue <select name="vue">';
		foreach ( [ '' => 'Tous les adhérents actifs', 'alertes' => 'Avec alertes uniquement', 'recuperer' => 'Doboks à récupérer (anciens adhérents)' ] as $k => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $k, $vue, false ), esc_html( $l ) );
		}
		echo '</select></label>';
		printf( '<input type="search" name="q" value="%s" placeholder="Nom ou prénom">', esc_attr( $q ) );
		echo '<button class="button">Filtrer</button></form>';

		if ( $vue !== 'recuperer' ) {
			printf( '<p class="spd-compte">%d adhérent(s) affiché(s) — <strong>%d</strong> avec au moins un point à traiter.</p>', count( $rows ), $nb_alertes );
		} else {
			echo '<p class="description">Adhérents qui ne sont pas actifs sur la saison ' . esc_html( $saison ) . ' mais ont encore un dobok du club en leur possession.</p>';
		}

		if ( ! $rows ) {
			echo '<div class="sp-box"><p>Aucun adhérent à afficher.</p></div>';
		} else {
			echo '<table class="widefat striped spd-adh"><thead><tr><th>Adhérent</th><th>Dobok blanc</th><th>Dobok couleur</th><th>Alertes</th><th></th></tr></thead><tbody>';
			foreach ( $rows as [ $el, $sit, $alertes ] ) {
				$this->render_ligne_adherent( $el, $sit, $alertes, $saison, $retour, $open === (int) $el->id );
			}
			echo '</tbody></table>';
		}

		// Attribution présumée (démarrage)
		if ( $vue !== 'recuperer' ) {
			echo '<div class="sp-box spd-presume"><h2>Démarrage : attribution présumée</h2>';
			echo '<p>Pour chaque adhérent actif de la saison ' . esc_html( $saison ) . ' qui n\'a encore <strong>aucun</strong> dobok enregistré d\'un type, enregistre le modèle attendu dans la taille correspondant à sa taille en cm, marqué « à confirmer ». Aucun effet sur le stock : ces doboks sont déjà chez les adhérents. Chaque attribution se confirme ou se corrige ensuite adhérent par adhérent.</p>';
			$this->form_open( 'presumer', $retour );
			echo '<button class="button" onclick="return confirm(\'Créer les attributions présumées pour la saison ' . esc_js( $saison ) . ' ?\');">Créer les attributions présumées</button></form></div>';
		}
		?>
		<script>
		document.querySelectorAll('.spd-toggle').forEach(function (b) {
			b.addEventListener('click', function () {
				var row = document.getElementById('gerer-' + b.dataset.id);
				row.hidden = !row.hidden;
				b.textContent = row.hidden ? 'Gérer' : 'Fermer';
			});
		});
		</script>
		<?php
	}

	private function render_ligne_adherent( object $el, array $sit, array $alertes, string $saison, array $retour, bool $ouvert ): void {
		$id    = (int) $el->id;
		$age   = $this->age_competition( $el, $saison );
		$cat   = $this->categorie_competition( $age );
		$sexe  = self::sexe( $el );
		$fiche = admin_url( 'admin.php?page=sp-cal-fiche-eleve&eleve_id=' . $id );

		echo '<tr id="el-' . $id . '">';
		printf( '<td><a href="%s"><strong>%s</strong> %s</a><div class="spd-meta">%s · %s%s · %s</div></td>',
			esc_url( $fiche ), esc_html( mb_strtoupper( $el->nom ) ), esc_html( $el->prenom ),
			esc_html( $el->grade ?: 'grade ?' ),
			esc_html( $cat ?: 'catégorie ?' ), $sexe ? ' ' . esc_html( $sexe ) : '',
			esc_html( ( $el->taille_cm ?? '' ) !== '' ? $el->taille_cm . ' cm' : 'taille ?' ) );

		foreach ( [ 'blanc', 'couleur' ] as $type ) {
			$s = $sit[ $type ];
			echo '<td>';
			foreach ( $s['detenus'] as $d ) {
				printf( '<span class="spd-dobok%s">%s <b>%d</b>%s</span> ',
					$d['a_confirmer'] ? ' spd-presume-tag' : '',
					esc_html( self::label( $d['modele'] ) ), $d['taille'], $d['nb'] > 1 ? ' ×' . $d['nb'] : '' );
			}
			if ( ! $s['detenus'] ) echo '<span class="spd-vide">—</span>';
			if ( $s['attendu'] || $s['suggestion'] ) {
				printf( '<div class="spd-meta">Attendu : %s %s</div>',
					esc_html( $s['attendu'] ? self::label( $s['attendu'] ) : '?' ), $s['suggestion'] ? (int) $s['suggestion'] : '?' );
			}
			echo '</td>';
		}

		echo '<td>';
		foreach ( $alertes as [ $niv, $txt ] ) printf( '<span class="spd-alerte spd-%s">%s</span>', esc_attr( $niv ), esc_html( $txt ) );
		echo '</td>';
		printf( '<td><button type="button" class="button spd-toggle" data-id="%d">%s</button></td></tr>', $id, $ouvert ? 'Fermer' : 'Gérer' );

		// Panneau de gestion
		printf( '<tr class="spd-gerer" id="gerer-%d"%s><td colspan="5"><div class="spd-panneau">', $id, $ouvert ? '' : ' hidden' );
		foreach ( [ 'blanc' => 'Dobok blanc', 'couleur' => 'Dobok couleur' ] as $type => $titre ) {
			$s = $sit[ $type ];
			echo '<div class="spd-col"><h4>' . esc_html( $titre ) . '</h4>';

			// Ce qu'il détient : confirmer / corriger / restituer / perdu
			foreach ( $s['detenus'] as $d ) {
				$ref = $d['modele'] . '|' . $d['taille'];
				echo '<div class="spd-detenu"><div><strong>' . esc_html( self::label( $d['modele'] ) . ' ' . $d['taille'] ) . '</strong>' . ( $d['a_confirmer'] ? ' <em>(présumé)</em>' : '' ) . '</div>';
				if ( $d['a_confirmer'] ) {
					$this->form_open( 'confirmer', $retour, 'spd-inline' );
					printf( '<input type="hidden" name="eleve_id" value="%d"><input type="hidden" name="ref" value="%s">', $id, esc_attr( $ref ) );
					echo '<button class="button button-small">✓ Confirmer</button></form>';
					$this->form_open( 'corriger', $retour, 'spd-inline' );
					printf( '<input type="hidden" name="eleve_id" value="%d"><input type="hidden" name="ref" value="%s">', $id, esc_attr( $ref ) );
					echo 'En réalité : ' . $this->select_modele( 'modele', $type, $d['modele'] ) . $this->select_taille( 'taille', $d['taille'] ); // phpcs:ignore -- échappé dans les helpers
					echo ' <button class="button button-small">Corriger</button></form>';
				}
				$this->form_open( 'restitution', $retour, 'spd-inline' );
				printf( '<input type="hidden" name="eleve_id" value="%d"><input type="hidden" name="rendu" value="%s">', $id, esc_attr( $ref ) );
				echo 'Rendu sans remplacement : ' . $this->select_etat(); // phpcs:ignore
				echo '<select name="motif"><option value="retour">Départ / plus besoin</option><option value="remplacement">Abîmé</option></select>';
				echo ' <button class="button button-small">Restituer</button></form>';
				$this->form_open( 'perte', $retour, 'spd-inline' );
				printf( '<input type="hidden" name="eleve_id" value="%d"><input type="hidden" name="rendu" value="%s">', $id, esc_attr( $ref ) );
				echo '<button class="button button-small button-link-delete" onclick="return confirm(\'Déclarer ce dobok non rendu / perdu ?\');">Non rendu / perdu</button></form>';
				echo '</div>';
			}

			// Remise ou échange
			$this->form_open( 'remise', $retour, 'spd-remise' );
			printf( '<input type="hidden" name="eleve_id" value="%d">', $id );
			echo '<div><strong>Remettre</strong> ' . $this->select_modele( 'modele', $type, $s['attendu'] ?: '' ) . $this->select_taille( 'taille', $s['suggestion'] ) . '</div>'; // phpcs:ignore
			echo '<div>en échange de <select name="rendu"><option value="">— rien (nouvelle remise)</option>';
			foreach ( $s['detenus'] as $i => $d ) {
				printf( '<option value="%s"%s>%s %d</option>', esc_attr( $d['modele'] . '|' . $d['taille'] ), $i === 0 ? ' selected' : '', esc_html( self::label( $d['modele'] ) ), $d['taille'] );
			}
			echo '</select> rendu ' . $this->select_etat() . '</div>'; // phpcs:ignore
			echo '<label class="spd-deja"><input type="checkbox" name="deja" value="1"> Il l\'a déjà (reprise de l\'existant : pas de sortie de stock)</label>';
			echo '<input type="text" name="note" placeholder="Note (facultatif)" class="spd-note">';
			echo '<button class="button button-primary button-small">Valider</button></form>';
			echo '</div>';
		}
		echo '</div></td></tr>';
	}

	// ── Onglet Achats ────────────────────────────────────────────────────
	private function render_achats(): void {
		global $wpdb;
		$lots = $wpdb->get_results( "SELECT * FROM {$this->t_lots()} ORDER BY date_achat DESC, id DESC LIMIT 300" );

		echo '<div class="sp-box"><h2>Nouveau lot reçu</h2>';
		$this->form_open( 'lot', [ 'tab' => 'achats' ], 'spd-form-lot' );
		echo '<label>Modèle ' . $this->select_modele( 'modele', '', 'col_blanc' ) . '</label>'; // phpcs:ignore
		echo '<label>Taille ' . $this->select_taille( 'taille', 120 ) . '</label>'; // phpcs:ignore
		echo '<label>Quantité <input type="number" name="quantite" min="1" max="999" value="1" required></label>';
		echo '<label>Date d\'achat <input type="date" name="date_achat" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '"></label>';
		echo '<label>Prix unitaire (€) <input type="text" name="prix_unitaire" inputmode="decimal" size="7"></label>';
		echo '<label>Fournisseur <input type="text" name="fournisseur"></label>';
		echo '<label>Note <input type="text" name="note"></label>';
		echo '<button class="button button-primary">Ajouter au stock</button></form></div>';

		echo '<div class="sp-box"><h2>Lots achetés</h2>';
		if ( ! $lots ) { echo '<p>Aucun achat enregistré.</p></div>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Modèle</th><th>Taille</th><th>Qté</th><th>Prix unit.</th><th>Total</th><th>Fournisseur</th><th>Note</th><th></th></tr></thead><tbody>';
		$total = 0.0;
		foreach ( $lots as $l ) {
			$tot    = $l->prix_unitaire !== null ? (float) $l->prix_unitaire * (int) $l->quantite : null;
			$total += (float) $tot;
			printf( '<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>',
				esc_html( $l->date_achat ? date_i18n( 'd/m/Y', strtotime( $l->date_achat ) ) : '' ),
				esc_html( self::label( $l->modele ) ), (int) $l->taille, (int) $l->quantite,
				$l->prix_unitaire !== null ? esc_html( number_format_i18n( (float) $l->prix_unitaire, 2 ) . ' €' ) : '—',
				$tot !== null ? esc_html( number_format_i18n( $tot, 2 ) . ' €' ) : '—',
				esc_html( $l->fournisseur ), esc_html( $l->note ) );
			$this->form_open( 'supprimer_lot', [ 'tab' => 'achats' ], 'spd-inline' );
			printf( '<input type="hidden" name="lot_id" value="%d">', (int) $l->id );
			echo '<button class="button button-small button-link-delete" onclick="return confirm(\'Supprimer ce lot ? Il sera retiré du stock.\');">Supprimer</button></form></td></tr>';
		}
		printf( '</tbody><tfoot><tr><th colspan="5">Total des lots affichés</th><th>%s</th><th colspan="3"></th></tr></tfoot></table></div>',
			esc_html( number_format_i18n( $total, 2 ) . ' €' ) );
	}

	// ── Onglet Journal ───────────────────────────────────────────────────
	private function render_journal(): void {
		global $wpdb;
		$f_type   = sanitize_key( $_GET['f_type'] ?? '' );
		$f_modele = sanitize_key( $_GET['f_modele'] ?? '' );
		$q        = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );

		$where = [ '1=1' ]; $params = [];
		if ( isset( self::TYPES_MVT[ $f_type ] ) ) { $where[] = 'm.type = %s';   $params[] = $f_type; }
		if ( isset( self::MODELES[ $f_modele ] ) ) { $where[] = 'm.modele = %s'; $params[] = $f_modele; }
		if ( $q !== '' ) {
			$where[]  = "CONCAT(e.nom, ' ', e.prenom, ' ', e.prenom, ' ', e.nom) LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $q ) . '%';
		}
		$sql = "SELECT m.*, e.nom, e.prenom FROM {$this->t_mvt()} m
		        LEFT JOIN {$this->table_eleves()} e ON e.id = m.eleve_id
		        WHERE " . implode( ' AND ', $where ) . ' ORDER BY m.id DESC LIMIT 300';
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql );

		echo '<form method="get" class="spd-filtres"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="journal">';
		echo '<select name="f_type"><option value="">Tous les mouvements</option>';
		foreach ( self::TYPES_MVT as $k => $l ) printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $k, $f_type, false ), esc_html( $l ) );
		echo '</select><select name="f_modele"><option value="">Tous les modèles</option>';
		foreach ( self::MODELES as $k => $m ) printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $k, $f_modele, false ), esc_html( $m['label'] ) );
		printf( '</select><input type="search" name="q" value="%s" placeholder="Adhérent"><button class="button">Filtrer</button></form>', esc_attr( $q ) );

		echo '<p class="description">300 derniers mouvements. Supprimer un mouvement saisi par erreur recalcule automatiquement le stock et les dotations.</p>';
		echo '<table class="widefat striped spd-journal"><thead><tr><th>Date</th><th>Mouvement</th><th>Dobok</th><th>Qté</th><th>Stock</th><th>Adhérent</th><th>Détail</th><th>Saisi par</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) echo '<tr><td colspan="9">Aucun mouvement.</td></tr>';
		$retour = [ 'tab' => 'journal', 'f_type' => $f_type, 'f_modele' => $f_modele, 'q' => $q ];
		foreach ( (array) $rows as $r ) {
			$user    = $r->created_by ? get_userdata( (int) $r->created_by ) : null;
			$details = array_filter( [
				self::ETATS[ $r->etat ]   ?? '',
				self::MOTIFS[ $r->motif ] ?? '',
				$r->a_confirmer ? 'à confirmer' : '',
				$r->note,
			] );
			printf( '<tr><td>%s</td><td>%s</td><td>%s %d</td><td>%d</td><td class="%s">%s</td><td>%s</td><td>%s</td><td>%s</td><td>',
				esc_html( mysql2date( 'd/m/Y H:i', $r->created_at ) ),
				esc_html( self::TYPES_MVT[ $r->type ] ?? $r->type ),
				esc_html( self::label( $r->modele ) ), (int) $r->taille, (int) $r->quantite,
				(int) $r->mvt_stock < 0 ? 'spd-moins' : ( (int) $r->mvt_stock > 0 ? 'spd-plus' : '' ),
				(int) $r->mvt_stock === 0 ? '—' : sprintf( '%+d', (int) $r->mvt_stock ),
				$r->eleve_id ? esc_html( mb_strtoupper( (string) $r->nom ) . ' ' . $r->prenom ) : '',
				esc_html( implode( ' · ', $details ) ),
				esc_html( $user ? $user->display_name : '' ) );
			$this->form_open( 'supprimer_mvt', $retour, 'spd-inline' );
			printf( '<input type="hidden" name="mvt_id" value="%d">', (int) $r->id );
			echo '<button class="button button-small button-link-delete" onclick="return confirm(\'Supprimer ce mouvement ?' . ( $r->type === 'achat' ? ' Le lot d\\\'achat associé sera aussi supprimé.' : '' ) . '\');">Supprimer</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}

	// ── Onglet Réglages ──────────────────────────────────────────────────
	private function render_reglages(): void {
		$r = $this->reglages();
		echo '<div class="sp-box"><h2>Réglages</h2>';
		$this->form_open( 'reglages', [ 'tab' => 'reglages' ] );
		echo '<table class="form-table"><tbody>';
		printf( '<tr><th>Gamme de tailles</th><td>de <input type="number" name="taille_min" value="%d" step="10" min="50" max="250"> à <input type="number" name="taille_max" value="%d" step="10" min="50" max="250"> cm, de 10 en 10</td></tr>', (int) $r['taille_min'], (int) $r['taille_max'] );
		printf( '<tr><th>Marge de croissance</th><td><input type="number" name="marge" value="%d" min="0" max="20"> cm<p class="description">Ajoutée à la taille de l\'adhérent avant l\'arrondi à la dizaine supérieure pour la taille suggérée. 0 = taille juste (128 cm → 130) ; 5 = un peu grand (126 cm → 140).</p></td></tr>', (int) $r['marge'] );
		printf( '<tr><th>Seuil d\'alerte de stock</th><td><input type="number" name="seuil_alerte" value="%d" min="0" max="50"><p class="description">Une case du tableau de stock passe en orange quand il reste ce nombre de doboks ou moins.</p></td></tr>', (int) $r['seuil_alerte'] );
		printf( '<tr><th>Catégories de compétition</th><td>Cadet jusqu\'à <input type="number" name="cadet_age_max" value="%d" min="5" max="20"> ans (les plus jeunes portent le modèle cadet), Master à partir de <input type="number" name="master_age_min" value="%d" min="30" max="80"> ans, Junior/Senior entre les deux.</td></tr>', (int) $r['cadet_age_max'], (int) $r['master_age_min'] );
		echo '<tr><th>Âge pris en compte</th><td><select name="annee_ref">';
		printf( '<option value="fin"%s>Âge atteint dans l\'année civile de fin de saison (ex. 2026 pour 2025/2026)</option>', selected( $r['annee_ref'], 'fin', false ) );
		printf( '<option value="debut"%s>Âge atteint dans l\'année civile de début de saison (ex. 2025 pour 2025/2026)</option>', selected( $r['annee_ref'], 'debut', false ) );
		echo '</select><p class="description">À aligner sur le règlement des compétitions de la saison.</p></td></tr>';
		echo '</tbody></table><p><button class="button button-primary">Enregistrer</button></p></form></div>';

		echo '<div class="sp-box"><h2>Modèles</h2><table class="widefat striped"><thead><tr><th>Modèle</th><th>Type</th><th>Description</th></tr></thead><tbody>';
		foreach ( self::MODELES as $m ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $m['label'] ), esc_html( $m['type'] ), esc_html( $m['detail'] ) );
		}
		echo '</tbody></table><p class="description">Blanc : le col est déduit du grade (col noir dès la ceinture noire, Dan ou Poom). Couleur : acheté par ensemble, modèle déduit de la catégorie de compétition et du sexe. Un adhérent peut garder son ancien modèle ou sa taille : les écarts sont signalés, jamais imposés.</p></div>';
	}
}

<?php
/**
 * Gestion des doboks prêtés par le club — V1 (stock, achats, journal, dotations)
 * + V2 (demandes des adhérents depuis leur fiche, réservations, distribution, mails).
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
 * Demandes (V2) : l'adhérent demande un échange / une restitution depuis sa fiche (lien
 * ?token=). Si le stock disponible le permet, le dobok souhaité est aussitôt RÉSERVÉ
 * (statut « ouverte ») pour ne pas le promettre deux fois ; sinon la demande passe « en
 * attente » et sera réservée automatiquement à l'arrivée d'un lot ou d'un retour
 * (promouvoir_attentes()). Disponible = stock effectif − réservé.
 *
 * Hors V2 (cf. EVOLUTION.md) : question dobok dans le formulaire de renouvellement,
 * aide à la commande, demandes automatiques (ceinture noire, changement de catégorie).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Dobok {

	private static ?self $instance = null;
	private $db;

	const SCHEMA_VERSION = '2';
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

	// Nature d'une demande → motif de la restitution qui la solde.
	const NATURES = [
		'taille'       => [ 'label' => 'Changement de taille',   'motif' => 'taille' ],
		'modele'       => [ 'label' => 'Changement de modèle',   'motif' => 'modele' ],
		'remplacement' => [ 'label' => 'Remplacement (abîmé)',   'motif' => 'remplacement' ],
		'restitution'  => [ 'label' => 'Restitution',            'motif' => 'retour' ],
		'dotation'     => [ 'label' => 'Premier dobok',          'motif' => '' ],
	];

	const STATUTS = [
		'ouverte'    => 'Réservée / à traiter',
		'en_attente' => 'En attente de stock',
		'terminee'   => 'Terminée',
		'refusee'    => 'Refusée',
		'annulee'    => 'Annulée',
	];

	const REGLAGES_DEFAUT = [
		'taille_min'     => 100,
		'taille_max'     => 200,
		'marge'          => 0,
		'seuil_alerte'   => 1,
		'cadet_age_max'  => 14,
		'master_age_min' => 51,
		'annee_ref'      => 'fin',
		'demandes_on'    => 1,
		'mail_bureau'    => 1,
		'email_bureau'   => '',
		'mail_famille'   => 1,
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
		            'lot', 'supprimer_lot', 'inventaire', 'supprimer_mvt', 'reglages',
		            'dem_terminer', 'dem_annuler', 'dem_refuser' ] as $a ) {
			add_action( 'admin_post_sp_dobok_' . $a, [ $this, 'handle_' . $a ] );
		}

		// Fiche adhérent (lien ?token=) : bloc « Mes doboks » + traitement de ses formulaires.
		// Priorité 5 : avant SpCalPro_Token::maybe_render_fiche() (10), qui affiche la fiche et exit.
		add_action( 'template_redirect',                  [ $this, 'handle_front' ], 5 );
		add_action( 'sp_cal_fiche_membre_apres_grade',    [ $this, 'render_bloc_adherent' ] );
	}

	// ══════════════════════════════════════════════════════════════════════
	// SCHÉMA
	// ══════════════════════════════════════════════════════════════════════
	private function t_lots(): string { global $wpdb; return $wpdb->prefix . 'sp_cal_dobok_lots'; }
	private function t_mvt(): string  { global $wpdb; return $wpdb->prefix . 'sp_cal_dobok_mouvements'; }
	private function t_dem(): string  { global $wpdb; return $wpdb->prefix . 'sp_cal_dobok_demandes'; }

	private function table_eleves(): string {
		global $wpdb;
		return $this->db ? $this->db->table_eleves() : $wpdb->prefix . 'sp_cal_eleves';
	}

	private function tables_ok(): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->t_mvt() ) ) === $this->t_mvt()
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->t_lots() ) ) === $this->t_lots()
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->t_dem() ) ) === $this->t_dem();
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

		dbDelta( "CREATE TABLE {$this->t_dem()} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			eleve_id mediumint(9) NOT NULL DEFAULT 0,
			type_dobok varchar(10) NOT NULL DEFAULT '',
			nature varchar(20) NOT NULL DEFAULT '',
			rendu_modele varchar(20) NOT NULL DEFAULT '',
			rendu_taille smallint(6) NOT NULL DEFAULT 0,
			souhait_modele varchar(20) NOT NULL DEFAULT '',
			souhait_taille smallint(6) NOT NULL DEFAULT 0,
			statut varchar(20) NOT NULL DEFAULT 'ouverte',
			origine varchar(10) NOT NULL DEFAULT 'adherent',
			hors_regle tinyint(1) NOT NULL DEFAULT 0,
			note_adherent varchar(255) NOT NULL DEFAULT '',
			note_bureau varchar(255) NOT NULL DEFAULT '',
			saison varchar(20) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			traite_par bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY eleve_id (eleve_id),
			KEY statut (statut)
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
		// Pastille du menu : demandes réservées / retours attendus, à traiter au club.
		$n     = $this->front_pret() && current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ? $this->nb_demandes_a_traiter() : 0;
		$titre = '🥋 Doboks' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' );
		add_submenu_page( 'sp-cal-pro', 'Doboks', $titre, SP_Cal_Roles::CAP_GESTION_ADHESIONS, self::PAGE, [ $this, 'render_page' ] );
	}

	private function nb_demandes_a_traiter(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->t_dem()} WHERE statut = 'ouverte'" );
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

	/**
	 * Les adhérents du renforcement musculaire n'ont pas de dobok. Discipline = colonne
	 * categorie_saisie : code 'RENFO' (formulaire d'adhésion / fiche admin), ou libellé libre
	 * des anciennes fiches (« Renfo », « Renforcement musculaire », « Renfo & Ados/Adultes »…).
	 */
	public static function est_concerne( $el ): bool {
		return ! preg_match( '/renfo|renforcement/i', (string) ( $el->categorie_saisie ?? '' ) );
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

	// ── Demandes ─────────────────────────────────────────────────────────

	/** Par référence : [modele][taille] => ['reserve' => n, 'attente' => n, 'attendu' => n] */
	private function demandes_par_ref(): array {
		global $wpdb;
		$out  = [];
		$rows = $wpdb->get_results(
			"SELECT souhait_modele AS m, souhait_taille AS t, statut, COUNT(*) AS n FROM {$this->t_dem()}
			 WHERE statut IN ('ouverte','en_attente') AND souhait_modele != ''
			 GROUP BY souhait_modele, souhait_taille, statut"
		);
		foreach ( (array) $rows as $r ) {
			$out[ $r->m ][ (int) $r->t ][ $r->statut === 'ouverte' ? 'reserve' : 'attente' ] = (int) $r->n;
		}
		$rows = $wpdb->get_results(
			"SELECT rendu_modele AS m, rendu_taille AS t, COUNT(*) AS n FROM {$this->t_dem()}
			 WHERE statut IN ('ouverte','en_attente') AND rendu_modele != ''
			 GROUP BY rendu_modele, rendu_taille"
		);
		foreach ( (array) $rows as $r ) {
			$out[ $r->m ][ (int) $r->t ]['attendu'] = (int) $r->n;
		}
		return $out;
	}

	/** Stock effectif moins ce qui est déjà promis (réservé) : ce qu'on peut encore promettre. */
	private function disponible( string $modele, int $taille ): int {
		global $wpdb;
		$reserve = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->t_dem()} WHERE statut = 'ouverte' AND souhait_modele = %s AND souhait_taille = %d",
			$modele, $taille
		) );
		return $this->stock_de( $modele, $taille ) - $reserve;
	}

	private function demande( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t_dem()} WHERE id = %d", $id ) ) ?: null;
	}

	/** Demandes non closes : [eleve_id][type_dobok] => demande (une seule par type et par adhérent). */
	private function demandes_ouvertes( ?array $eleve_ids = null ): array {
		global $wpdb;
		$where = "statut IN ('ouverte','en_attente')";
		if ( $eleve_ids !== null ) {
			if ( ! $eleve_ids ) return [];
			$where .= ' AND eleve_id IN (' . implode( ',', array_map( 'intval', $eleve_ids ) ) . ')';
		}
		$out = [];
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$this->t_dem()} WHERE $where ORDER BY id" ) as $d ) {
			$out[ (int) $d->eleve_id ][ $d->type_dobok ] = $d;
		}
		return $out;
	}

	private function maj_demande( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$data['traite_par'] = get_current_user_id();
		$wpdb->update( $this->t_dem(), $data, [ 'id' => $id ] );
	}

	/**
	 * Enregistre une demande : réservée tout de suite si le stock disponible le permet,
	 * sinon en attente. « 1 échange de taille par saison » : non bloquant, juste marqué.
	 */
	private function creer_demande( object $el, array $d ): ?object {
		global $wpdb;
		$statut = 'ouverte';
		if ( $d['souhait_modele'] !== '' && $this->disponible( $d['souhait_modele'], $d['souhait_taille'] ) <= 0 ) {
			$statut = 'en_attente';
		}
		$hors_regle = $d['nature'] === 'taille'
			&& ( $this->echanges_taille( $this->saison_courante() )[ (int) $el->id ][ $d['type_dobok'] ] ?? 0 ) >= 1;

		$wpdb->insert( $this->t_dem(), [
			'eleve_id'       => (int) $el->id,
			'type_dobok'     => $d['type_dobok'],
			'nature'         => $d['nature'],
			'rendu_modele'   => $d['rendu_modele'],
			'rendu_taille'   => $d['rendu_taille'],
			'souhait_modele' => $d['souhait_modele'],
			'souhait_taille' => $d['souhait_taille'],
			'statut'         => $statut,
			'origine'        => $d['origine'] ?? 'adherent',
			'hors_regle'     => $hors_regle ? 1 : 0,
			'note_adherent'  => mb_substr( (string) ( $d['note_adherent'] ?? '' ), 0, 255 ),
			'saison'         => $this->saison_courante(),
			'created_at'     => current_time( 'mysql' ),
		] );
		$dem = $wpdb->insert_id ? $this->demande( (int) $wpdb->insert_id ) : null;
		if ( $dem ) {
			$this->mail_bureau( $el, $dem, 'nouvelle' );
			$this->mail_famille( $el, $dem, $statut === 'ouverte' ? 'recue' : 'attente' );
		}
		return $dem;
	}

	/**
	 * Réserve, dans l'ordre d'arrivée, les demandes en attente dont le dobok est de nouveau
	 * disponible (lot reçu, retour, inventaire, demande annulée…) et prévient la famille.
	 */
	private function promouvoir_attentes(): int {
		global $wpdb;
		$n = 0;
		$attentes = $wpdb->get_results( "SELECT * FROM {$this->t_dem()} WHERE statut = 'en_attente' ORDER BY created_at, id" );
		foreach ( (array) $attentes as $d ) {
			if ( $this->disponible( $d->souhait_modele, (int) $d->souhait_taille ) <= 0 ) continue;
			$wpdb->update( $this->t_dem(), [ 'statut' => 'ouverte', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => (int) $d->id ] );
			$d->statut = 'ouverte';
			$el = $this->eleve( (int) $d->eleve_id );
			if ( $el ) $this->mail_famille( $el, $d, 'reservee' );
			$n++;
		}
		return $n;
	}

	/**
	 * Après une action directe du bureau (onglet Adhérents), solde la demande ouverte de
	 * l'adhérent qu'elle satisfait, pour qu'elle ne reste pas en suspens.
	 */
	private function solder_demande_liee( int $eleve_id, string $type, string $souhait_modele, int $souhait_taille, string $rendu_modele = '', int $rendu_taille = 0 ): void {
		$d = $this->demandes_ouvertes( [ $eleve_id ] )[ $eleve_id ][ $type ] ?? null;
		if ( ! $d ) return;
		$ok = $souhait_modele !== ''
			? ( $d->souhait_modele === $souhait_modele && (int) $d->souhait_taille === $souhait_taille )
			: ( $d->nature === 'restitution' && $d->rendu_modele === $rendu_modele && (int) $d->rendu_taille === $rendu_taille );
		if ( $ok ) $this->maj_demande( (int) $d->id, [ 'statut' => 'terminee', 'note_bureau' => 'Traitée depuis l\'onglet Adhérents' ] );
	}

	private static function libelle_demande( object $d ): string {
		$rendu   = $d->rendu_modele   ? self::label( $d->rendu_modele ) . ' ' . (int) $d->rendu_taille : '';
		$souhait = $d->souhait_modele ? self::label( $d->souhait_modele ) . ' ' . (int) $d->souhait_taille : '';
		switch ( $d->nature ) {
			case 'restitution':  return 'Rend ' . $rendu;
			case 'dotation':     return 'Premier dobok : ' . $souhait;
			case 'remplacement': return 'Remplace ' . $rendu . ' (abîmé)';
		}
		return $rendu . ' → ' . $souhait;
	}

	// ── Mails ────────────────────────────────────────────────────────────

	private function email_bureau(): string {
		$e = trim( (string) $this->reglages()['email_bureau'] );
		return $e !== '' ? $e : (string) get_option( 'sp_cal_notif_email', get_option( 'admin_email' ) );
	}

	/** Même logique que SpCalPro_Token::get_fiche_url() (instance non accessible d'ici). */
	private function fiche_url( string $token ): string {
		$page_url = (string) get_option( 'sp_cal_fiche_membre_url', '' );
		if ( ! $page_url ) {
			global $wpdb;
			$page = $wpdb->get_row(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='page'
				 AND post_content LIKE '%sp_cal_fiche_membre%' LIMIT 1"
			);
			$page_url = $page ? get_permalink( $page->ID ) : home_url( '/' );
		}
		return add_query_arg( 'token', $token, trailingslashit( $page_url ) );
	}

	private function envoyer( string $dest, string $sujet, string $corps ): void {
		if ( $dest === '' ) return;
		$club = get_option( 'blogname', 'Club' );
		wp_mail( $dest, '[' . $club . '] ' . $sujet, $corps . "\n\n-- \n" . $club, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}

	private function mail_bureau( object $el, object $d, string $evenement ): void {
		if ( ! $this->reglages()['mail_bureau'] ) return;
		$qui  = $el->prenom . ' ' . mb_strtoupper( $el->nom );
		$type = $d->type_dobok === 'blanc' ? 'blanc' : 'couleur';
		if ( $evenement === 'annulee' ) {
			$this->envoyer( $this->email_bureau(), 'Dobok : demande annulée par ' . $qui,
				$qui . " a annulé sa demande (dobok $type) :\n" . self::libelle_demande( $d ) );
			return;
		}
		$alertes = [];
		if ( $d->hors_regle )               $alertes[] = '⚠ Déjà un échange de taille cette saison (règle : 1 par saison).';
		if ( $d->statut === 'en_attente' )  $alertes[] = '⚠ Pas de stock disponible : demande en attente.';
		$corps  = "Nouvelle demande de $qui (dobok $type) :\n" . self::libelle_demande( $d ) . "\n";
		$corps .= "Statut : " . ( self::STATUTS[ $d->statut ] ?? $d->statut ) . "\n";
		if ( $d->note_adherent ) $corps .= "Précisions : " . $d->note_adherent . "\n";
		if ( $alertes )          $corps .= "\n" . implode( "\n", $alertes ) . "\n";
		$corps .= "\nÀ traiter : " . admin_url( 'admin.php?page=' . self::PAGE . '&tab=demandes' );
		$this->envoyer( $this->email_bureau(), ( $alertes ? '⚠ ' : '' ) . 'Dobok : demande de ' . $qui, $corps );
	}

	private function mail_famille( object $el, object $d, string $evenement ): void {
		if ( ! $this->reglages()['mail_famille'] ) return;
		$dest = $el->email_parent ?: $el->email;
		if ( ! $dest || ! is_email( $dest ) ) return;
		$qui  = $el->prenom . ' ' . mb_strtoupper( $el->nom );
		$lib  = self::libelle_demande( $d );
		switch ( $evenement ) {
			case 'recue':
				$sujet = 'Demande de dobok enregistrée';
				$corps = "Bonjour,\n\nLa demande pour $qui est bien enregistrée : $lib.\n"
					. ( $d->souhait_modele ? "Le dobok est réservé : il vous sera remis lors d'une prochaine distribution au club." : "Pensez à rapporter le dobok au club." )
					. ( $d->hors_regle ? "\n\nUn échange de taille a déjà eu lieu cette saison : le bureau examinera la demande." : '' );
				break;
			case 'attente':
				$sujet = 'Demande de dobok en attente';
				$corps = "Bonjour,\n\nLa demande pour $qui est enregistrée : $lib.\n"
					. "Cette taille n'est pas disponible pour le moment : la demande est en liste d'attente. En attendant, gardez le dobok actuel. Vous serez prévenu(e) dès qu'il sera réservé.";
				break;
			case 'reservee':
				$sujet = 'Votre dobok est réservé';
				$corps = "Bonjour,\n\nBonne nouvelle : le dobok demandé pour $qui est maintenant réservé ($lib).\nIl vous sera remis lors d'une prochaine distribution au club.";
				break;
			case 'refusee':
				$sujet = 'Demande de dobok non retenue';
				$corps = "Bonjour,\n\nLa demande pour $qui ($lib) n'a pas été retenue par le bureau."
					. ( $d->note_bureau ? "\nMotif : " . $d->note_bureau : '' );
				break;
			default:
				return;
		}
		if ( $el->token ) $corps .= "\n\nSuivre la demande : " . $this->fiche_url( $el->token );
		$this->envoyer( $dest, $sujet, $corps );
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
		$this->solder_demande_liee( $eleve_id, self::type_de( $modele ), $modele, $taille );
		if ( $r_modele ) $this->promouvoir_attentes();
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
		$this->solder_demande_liee( $eleve_id, self::type_de( $modele ), '', 0, $modele, $taille );
		$this->promouvoir_attentes();
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
			if ( ! self::est_concerne( $el ) ) continue;   // renforcement musculaire : pas de dobok
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
		$n = $this->promouvoir_attentes();
		$this->retour( 'ok_lot', [ 'n' => $n ] );
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
		$this->promouvoir_attentes();
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
			'master_age_min' => max( 30, min( 80, (int) ( $_POST['master_age_min'] ?? 51 ) ) ),
			'annee_ref'      => ( $_POST['annee_ref'] ?? '' ) === 'debut' ? 'debut' : 'fin',
			'demandes_on'    => empty( $_POST['demandes_on'] ) ? 0 : 1,
			'mail_bureau'    => empty( $_POST['mail_bureau'] ) ? 0 : 1,
			'email_bureau'   => implode( ', ', array_filter( array_map( 'sanitize_email',
				explode( ',', wp_unslash( (string) ( $_POST['email_bureau'] ?? '' ) ) ) ), 'is_email' ) ),
			'mail_famille'   => empty( $_POST['mail_famille'] ) ? 0 : 1,
		] );
		$this->retour( 'ok_reglages' );
	}

	// ── Demandes : traitement par le bureau ──────────────────────────────

	/** Demande à traiter (ouverte ou en attente), ou retour avec erreur. */
	private function post_demande( string $action ): object {
		$this->verifier( $action );
		$d = $this->demande( (int) ( $_POST['dem_id'] ?? 0 ) );
		if ( ! $d || ! in_array( $d->statut, [ 'ouverte', 'en_attente' ], true ) ) $this->retour( 'err_dem' );
		return $d;
	}

	/** Distribution : l'ancien dobok est rendu (si coché) et le nouveau remis, en une fois. */
	public function handle_dem_terminer(): void {
		$d        = $this->post_demande( 'dem_terminer' );
		$eleve_id = (int) $d->eleve_id;
		$note     = $this->post_note();

		if ( $d->rendu_modele && ! empty( $_POST['ancien_rendu'] ) && $this->detient( $eleve_id, $d->rendu_modele, (int) $d->rendu_taille ) ) {
			$etat = sanitize_key( $_POST['etat'] ?? 'bon' );
			$this->ajouter_mouvement( 'restitution', $d->rendu_modele, (int) $d->rendu_taille, 1, [
				'eleve_id' => $eleve_id,
				'etat'     => isset( self::ETATS[ $etat ] ) ? $etat : 'bon',
				'motif'    => self::NATURES[ $d->nature ]['motif'] ?? 'retour',
				'note'     => 'Demande n° ' . $d->id,
			] );
			$this->effacer_a_confirmer( $eleve_id, $d->rendu_modele, (int) $d->rendu_taille );
		}
		$negatif = false;
		if ( $d->souhait_modele ) {
			$this->ajouter_mouvement( 'remise', $d->souhait_modele, (int) $d->souhait_taille, 1, [ 'eleve_id' => $eleve_id, 'note' => 'Demande n° ' . $d->id ] );
			$negatif = $this->stock_de( $d->souhait_modele, (int) $d->souhait_taille ) < 0;
		}
		$this->maj_demande( (int) $d->id, [ 'statut' => 'terminee', 'note_bureau' => $note ] );
		$this->promouvoir_attentes();
		$this->retour( $negatif ? 'ok_dem_terminee_negatif' : 'ok_dem_terminee' );
	}

	/** Cas « garde son ancien dobok » (pas de stock, ou changement d'avis) : rien ne bouge. */
	public function handle_dem_annuler(): void {
		$d = $this->post_demande( 'dem_annuler' );
		$this->maj_demande( (int) $d->id, [ 'statut' => 'annulee', 'note_bureau' => $this->post_note() ?: 'Garde son dobok actuel' ] );
		$this->promouvoir_attentes();
		$this->retour( 'ok_dem_annulee' );
	}

	public function handle_dem_refuser(): void {
		$d = $this->post_demande( 'dem_refuser' );
		$this->maj_demande( (int) $d->id, [ 'statut' => 'refusee', 'note_bureau' => $this->post_note() ] );
		$d = $this->demande( (int) $d->id );
		$el = $this->eleve( (int) $d->eleve_id );
		if ( $el ) $this->mail_famille( $el, $d, 'refusee' );
		$this->promouvoir_attentes();
		$this->retour( 'ok_dem_refusee' );
	}

	// ══════════════════════════════════════════════════════════════════════
	// FICHE ADHÉRENT (lien ?token=) — bloc « Mes doboks »
	// ══════════════════════════════════════════════════════════════════════

	private function eleve_par_token( string $token ): ?object {
		global $wpdb;
		if ( $token === '' ) return null;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_eleves()} WHERE token = %s", $token ) ) ?: null;
	}

	private function front_pret(): bool {
		return get_option( 'sp_dobok_schema_version' ) === self::SCHEMA_VERSION;
	}

	/**
	 * Formulaires du bloc « Mes doboks ». Le lien personnel (token) fait office
	 * d'authentification, comme pour le reste de la fiche ; le nonce protège contre
	 * l'envoi du formulaire depuis un autre site.
	 */
	public function handle_front(): void {
		if ( is_admin() || empty( $_POST['sp_dobok_front'] ) || ! $this->front_pret() ) return;
		$el = $this->eleve_par_token( sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ) );
		if ( ! $el ) return;

		$retour = function ( string $msg ): void {
			$url = add_query_arg( 'dobok_msg', $msg, remove_query_arg( 'dobok_msg' ) );
			wp_safe_redirect( $url . '#spd-front' );
			exit;
		};

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_spd_nonce'] ?? '' ) ), 'sp_dobok_front_' . $el->id ) ) $retour( 'expire' );
		if ( ! (int) $el->actif || ! $this->reglages()['demandes_on'] ) $retour( 'ferme' );

		$action = sanitize_key( $_POST['sp_dobok_front'] );

		if ( $action === 'annuler' ) {
			$d = $this->demande( (int) ( $_POST['dem_id'] ?? 0 ) );
			if ( ! $d || (int) $d->eleve_id !== (int) $el->id || ! in_array( $d->statut, [ 'ouverte', 'en_attente' ], true ) ) $retour( 'erreur' );
			global $wpdb;
			$wpdb->update( $this->t_dem(), [ 'statut' => 'annulee', 'updated_at' => current_time( 'mysql' ), 'note_bureau' => 'Annulée par l\'adhérent' ], [ 'id' => (int) $d->id ] );
			$this->mail_bureau( $el, $d, 'annulee' );
			$this->promouvoir_attentes();
			$retour( 'annulee' );
		}

		if ( $action !== 'demande' ) $retour( 'erreur' );

		$type = ( $_POST['type_dobok'] ?? '' ) === 'couleur' ? 'couleur' : 'blanc';
		if ( isset( $this->demandes_ouvertes( [ (int) $el->id ] )[ (int) $el->id ][ $type ] ) ) $retour( 'deja' );
		if ( empty( $_POST['confirme'] ) ) $retour( 'confirme' );

		$detenus = array_values( array_filter( $this->dotations( [ (int) $el->id ] )[ (int) $el->id ] ?? [], fn( $x ) => self::type_de( $x['modele'] ) === $type ) );
		$choix   = sanitize_key( $_POST['choix'] ?? '' );
		if ( ! self::est_concerne( $el ) && $choix !== 'rendre' ) $retour( 'erreur' );   // renfo : restitution seulement
		$taille  = $this->post_taille();
		$modele  = $this->post_modele();
		$attendu = $this->modele_attendu( $el, $type, $this->saison_courante() );
		$note    = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

		// Dobok rendu : celui choisi s'il en a plusieurs, sinon le seul qu'il a.
		[ $r_modele, $r_taille ] = self::split_ref( (string) ( $_POST['rendu'] ?? '' ) );
		if ( ! $r_modele && count( $detenus ) === 1 ) { $r_modele = $detenus[0]['modele']; $r_taille = $detenus[0]['taille']; }
		$detient = $r_modele && array_filter( $detenus, fn( $x ) => $x['modele'] === $r_modele && $x['taille'] === $r_taille );

		$dem = [ 'type_dobok' => $type, 'rendu_modele' => '', 'rendu_taille' => 0, 'souhait_modele' => '', 'souhait_taille' => 0, 'note_adherent' => $note ];

		if ( $choix === 'premier' ) {
			if ( $detenus ) $retour( 'erreur' );
			if ( ! $attendu ) $retour( 'modele_inconnu' );
			if ( ! in_array( $taille, $this->tailles(), true ) ) $retour( 'erreur' );
			$dem += [ 'nature' => 'dotation' ];
			$dem['souhait_modele'] = $attendu;
			$dem['souhait_taille'] = $taille;
		} else {
			if ( ! $detient ) $retour( 'erreur' );
			$dem['rendu_modele'] = $r_modele;
			$dem['rendu_taille'] = $r_taille;
			if ( $choix === 'rendre' ) {
				$dem['nature'] = 'restitution';
			} elseif ( $choix === 'abime' ) {
				$dem['nature']         = 'remplacement';
				$dem['souhait_modele'] = $r_modele;
				$dem['souhait_taille'] = $r_taille;
			} elseif ( $choix === 'echanger' ) {
				// Modèle : garder le sien ou passer au modèle attendu — rien d'autre.
				if ( ! in_array( $modele, array_filter( [ $r_modele, $attendu ] ), true ) ) $modele = $r_modele;
				if ( ! in_array( $taille, $this->tailles(), true ) ) $retour( 'erreur' );
				if ( $modele === $r_modele && $taille === $r_taille ) $retour( 'identique' );
				$dem['nature']         = $modele !== $r_modele ? 'modele' : 'taille';
				$dem['souhait_modele'] = $modele;
				$dem['souhait_taille'] = $taille;
			} else {
				$retour( 'erreur' );
			}
		}

		$d = $this->creer_demande( $el, $dem );
		if ( ! $d ) $retour( 'erreur' );
		$retour( $d->nature === 'restitution' ? 'ok_rendre' : ( $d->statut === 'ouverte' ? 'ok_reservee' : 'ok_attente' ) );
	}

	/** Bloc « Mes doboks » inséré dans la fiche adhérent (hook de SpCalPro_Token::render_membre_fiche). */
	public function render_bloc_adherent( $el ): void {
		if ( ! is_object( $el ) || ! $this->front_pret() ) return;

		$id       = (int) $el->id;
		$saison   = $this->saison_courante();
		$r        = $this->reglages();
		$dot      = $this->dotations( [ $id ] )[ $id ] ?? [];
		$ouvertes = $this->demandes_ouvertes( [ $id ] )[ $id ] ?? [];
		// Renforcement musculaire : pas de dobok. Bloc affiché seulement s'il en a encore un
		// du club (ancien pratiquant de taekwondo), et alors uniquement pour le rendre.
		$renfo    = ! self::est_concerne( $el );
		if ( $renfo && ! $dot && ! $ouvertes ) return;
		$ech      = $this->echanges_taille( $saison )[ $id ] ?? [];
		$peut     = (int) $el->actif && $r['demandes_on'];
		$cm       = self::parse_cm( $el->taille_cm ?? '' );
		$sugg     = $this->taille_pour( $cm, (int) $r['marge'] );

		$messages = [
			'ok_reservee'    => [ 'ok',   'Demande enregistrée : le dobok est réservé. Il vous sera remis lors d\'une prochaine distribution au club (pensez à rapporter l\'ancien s\'il y a lieu).' ],
			'ok_attente'     => [ 'info', 'Demande enregistrée. Cette taille n\'est pas disponible pour le moment : vous êtes en liste d\'attente et serez prévenu(e) par mail. Gardez votre dobok actuel en attendant.' ],
			'ok_rendre'      => [ 'ok',   'Demande enregistrée : merci de rapporter le dobok au club.' ],
			'annulee'        => [ 'ok',   'Votre demande est annulée.' ],
			'deja'           => [ 'err',  'Une demande est déjà en cours pour ce dobok.' ],
			'confirme'       => [ 'err',  'Cochez « Je confirme ma demande » pour l\'envoyer.' ],
			'identique'      => [ 'err',  'Le dobok demandé est identique au vôtre : choisissez une autre taille ou « Il est abîmé ».' ],
			'modele_inconnu' => [ 'err',  'Votre fiche est incomplète (année de naissance ou sexe) : contactez le bureau.' ],
			'ferme'          => [ 'err',  'Les demandes ne sont pas possibles pour le moment : contactez le bureau.' ],
			'expire'         => [ 'err',  'La page a expiré : rechargez-la puis recommencez.' ],
			'erreur'         => [ 'err',  'La demande n\'a pas pu être enregistrée : vérifiez vos choix.' ],
		];
		$msg = sanitize_key( $_GET['dobok_msg'] ?? '' );
		?>
		<div class="sp-membre-section spd-front" id="spd-front">
			<style>
				.spd-front .spd-f-msg{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:14px}
				.spd-front .spd-f-ok{background:#f0fdf4;border:1px solid #86efac;color:#166534}
				.spd-front .spd-f-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
				.spd-front .spd-f-err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
				.spd-front .spd-f-mesures{display:flex;flex-wrap:wrap;gap:8px 24px;font-size:14px;margin-bottom:6px}
				.spd-front .spd-f-mesures b{font-size:16px}
				.spd-front .spd-f-cartes{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:14px}
				.spd-front .spd-f-carte{border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;background:#fff}
				.spd-front .spd-f-carte h3{margin:0 0 8px;font-size:16px}
				.spd-front .spd-f-dobok{font-size:15px;font-weight:700}
				.spd-front .spd-f-conseil{font-size:13px;color:#4b5563;margin-top:4px}
				.spd-front .spd-f-demande{background:#f9fafb;border-radius:8px;padding:10px 12px;margin-top:10px;font-size:14px}
				.spd-front details{margin-top:10px}
				.spd-front summary{cursor:pointer;font-weight:600;color:#0f70b7}
				.spd-front form{margin:8px 0 0}
				.spd-front form label{display:block;margin:6px 0;font-size:14px}
				.spd-front form select,.spd-front form textarea{max-width:100%}
				.spd-front form textarea{width:100%;min-height:54px}
				.spd-front .spd-f-btn{margin-top:8px;padding:8px 16px;border:0;border-radius:6px;background:#0f70b7;color:#fff;font-weight:600;cursor:pointer}
				.spd-front .spd-f-lien{background:none;border:0;padding:0;color:#b91c1c;text-decoration:underline;cursor:pointer;font-size:13px}
				.spd-front .spd-f-note{font-size:12px;color:#6b7280;margin-top:12px}
			</style>
			<h2>🥋 Mes doboks</h2>

			<?php if ( isset( $messages[ $msg ] ) ) : ?>
				<div class="spd-f-msg spd-f-<?php echo esc_attr( $messages[ $msg ][0] ); ?>"><?php echo esc_html( $messages[ $msg ][1] ); ?></div>
			<?php endif; ?>

			<?php if ( $renfo ) : ?>
				<p>Vous êtes inscrit(e) en renforcement musculaire, qui ne nécessite pas de dobok : merci de rapporter au club le dobok prêté ci-dessous.</p>
			<?php else : ?>
			<div class="spd-f-mesures">
				<span>Taille : <b><?php echo esc_html( $cm !== null ? round( $cm ) . ' cm' : '—' ); ?></b></span>
				<span>Poids : <b><?php echo esc_html( ( $el->poids_kg ?? '' ) !== '' ? $el->poids_kg . ' kg' : '—' ); ?></b></span>
				<span>T-shirt : <b><?php echo esc_html( ( $el->taille_tshirt ?? '' ) ?: '—' ); ?></b></span>
				<span>Pantalon : <b><?php echo esc_html( ( $el->taille_pantalon ?? '' ) ?: '—' ); ?></b></span>
			</div>
			<p class="sp-membre-muted">Mesures saisies à l'inscription ou au renouvellement. Si elles ont changé, précisez-le dans votre demande.</p>
			<?php endif; ?>

			<div class="spd-f-cartes">
			<?php foreach ( [ 'blanc' => 'Dobok blanc', 'couleur' => 'Dobok couleur' ] as $type => $titre ) :
				$mine    = array_values( array_filter( $dot, fn( $x ) => self::type_de( $x['modele'] ) === $type ) );
				$attendu = $this->modele_attendu( $el, $type, $saison );
				$dem     = $ouvertes[ $type ] ?? null;
				if ( $renfo && ! $mine && ! $dem ) continue;
				?>
				<div class="spd-f-carte">
					<h3><?php echo esc_html( $titre ); ?></h3>
					<?php if ( $mine ) : foreach ( $mine as $x ) : ?>
						<div class="spd-f-dobok"><?php echo esc_html( self::label( $x['modele'] ) . ' — taille ' . $x['taille'] ); ?></div>
					<?php endforeach; else : ?>
						<div class="sp-membre-muted">Aucun dobok enregistré.</div>
					<?php endif; ?>

					<?php if ( $sugg && ! $renfo ) : ?>
						<div class="spd-f-conseil">Taille conseillée d'après votre taille : <strong><?php echo (int) $sugg; ?></strong></div>
					<?php endif; ?>
					<?php if ( ! $renfo && $mine && $attendu && ! array_filter( $mine, fn( $x ) => $x['modele'] === $attendu ) ) : ?>
						<div class="spd-f-conseil">Modèle correspondant à votre <?php echo $type === 'blanc' ? 'grade' : 'catégorie'; ?> : <strong><?php echo esc_html( self::label( $attendu ) ); ?></strong><?php echo $type === 'couleur' ? ' (vous pouvez garder le vôtre)' : ''; ?></div>
					<?php endif; ?>

					<?php if ( $dem ) : ?>
						<div class="spd-f-demande">
							<strong>Demande en cours</strong> (<?php echo esc_html( mysql2date( 'd/m/Y', $dem->created_at ) ); ?>) :
							<?php echo esc_html( self::libelle_demande( $dem ) ); ?><br>
							<?php if ( $dem->nature === 'restitution' ) : ?>
								À rapporter au club.
							<?php elseif ( $dem->statut === 'ouverte' ) : ?>
								✅ Réservé — remis lors d'une prochaine distribution au club.
							<?php else : ?>
								⏳ En attente de stock — gardez votre dobok actuel, vous serez prévenu(e) par mail.
							<?php endif; ?>
							<?php if ( $peut ) : ?>
								<form method="post">
									<?php $this->champs_front( $el ); ?>
									<input type="hidden" name="sp_dobok_front" value="annuler">
									<input type="hidden" name="dem_id" value="<?php echo (int) $dem->id; ?>">
									<button type="submit" class="spd-f-lien" onclick="return confirm('Annuler cette demande ?');">Annuler ma demande</button>
								</form>
							<?php endif; ?>
						</div>
					<?php elseif ( $renfo ) : if ( $peut ) : ?>
						<form method="post">
							<?php $this->champs_front( $el ); ?>
							<input type="hidden" name="sp_dobok_front" value="demande">
							<input type="hidden" name="type_dobok" value="<?php echo esc_attr( $type ); ?>">
							<input type="hidden" name="choix" value="rendre">
							<?php if ( count( $mine ) > 1 ) : ?>
								<label>Dobok rendu :
									<select name="rendu">
										<?php foreach ( $mine as $x ) printf( '<option value="%s">%s</option>', esc_attr( $x['modele'] . '|' . $x['taille'] ), esc_html( self::label( $x['modele'] ) . ' ' . $x['taille'] ) ); ?>
									</select>
								</label>
							<?php endif; ?>
							<label><input type="checkbox" name="confirme" value="1" required> Je confirme que je rapporte ce dobok au club</label>
							<button type="submit" class="spd-f-btn">Signaler le retour</button>
						</form>
					<?php endif; ?>
					<?php elseif ( $peut && ( $mine || $attendu ) ) : ?>
						<details>
							<summary><?php echo $mine ? 'Faire une demande' : 'Demander mon dobok'; ?></summary>
							<form method="post">
								<?php $this->champs_front( $el ); ?>
								<input type="hidden" name="sp_dobok_front" value="demande">
								<input type="hidden" name="type_dobok" value="<?php echo esc_attr( $type ); ?>">
								<?php if ( $mine ) : ?>
									<label><input type="radio" name="choix" value="echanger" checked> Changer de taille<?php echo $type === 'couleur' && $attendu && $mine[0]['modele'] !== $attendu ? ' ou de modèle' : ''; ?></label>
									<label><input type="radio" name="choix" value="abime"> Il est abîmé (même taille)</label>
									<label><input type="radio" name="choix" value="rendre"> Je le rends (je n'en ai plus besoin)</label>
									<?php if ( count( $mine ) > 1 ) : ?>
										<label>Dobok concerné :
											<select name="rendu">
												<?php foreach ( $mine as $x ) printf( '<option value="%s">%s</option>', esc_attr( $x['modele'] . '|' . $x['taille'] ), esc_html( self::label( $x['modele'] ) . ' ' . $x['taille'] ) ); ?>
											</select>
										</label>
									<?php endif; ?>
									<?php
									$modeles = array_unique( array_filter( [ $mine[0]['modele'], $attendu ] ) );
									if ( count( $modeles ) > 1 ) : ?>
										<label>Modèle souhaité (pour un changement) :
											<select name="modele">
												<?php foreach ( $modeles as $i => $m ) printf( '<option value="%s">%s</option>', esc_attr( $m ), esc_html( ( $i === 0 ? 'Garder mon modèle : ' : 'Nouveau modèle : ' ) . self::label( $m ) ) ); ?>
											</select>
										</label>
									<?php else : ?>
										<input type="hidden" name="modele" value="<?php echo esc_attr( $mine[0]['modele'] ); ?>">
									<?php endif; ?>
								<?php else : ?>
									<input type="hidden" name="choix" value="premier">
									<p class="spd-f-conseil">Modèle : <strong><?php echo esc_html( self::label( $attendu ) ); ?></strong></p>
								<?php endif; ?>
								<label>Taille souhaitée (pour un changement) :
									<select name="taille">
										<?php
										$defaut = $sugg ?: ( $mine[0]['taille'] ?? null );
										foreach ( $this->tailles() as $t ) printf( '<option value="%d"%s>%d%s</option>', $t, selected( $t, $defaut, false ), $t, $t === $sugg ? ' (conseillée)' : '' );
										?>
									</select>
								</label>
								<?php if ( $mine && ( $ech[ $type ] ?? 0 ) >= 1 ) : ?>
									<p class="spd-f-conseil">ℹ️ La taille de ce dobok a déjà été changée cette saison (1 échange par saison) : le bureau examinera votre demande.</p>
								<?php endif; ?>
								<label>Précisions (facultatif) :
									<textarea name="note" maxlength="255" placeholder="Ex. : nouvelle taille 132 cm"></textarea>
								</label>
								<label><input type="checkbox" name="confirme" value="1" required> Je confirme ma demande</label>
								<button type="submit" class="spd-f-btn">Envoyer ma demande</button>
							</form>
						</details>
					<?php elseif ( ! $mine ) : ?>
						<div class="spd-f-conseil">Contactez le bureau pour obtenir votre dobok.</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			</div>
			<p class="spd-f-note">Les doboks sont prêtés par le club : ils restent sa propriété et sont à rendre en cas de départ.</p>
		</div>
		<?php
	}

	private function champs_front( object $el ): void {
		printf( '<input type="hidden" name="_spd_nonce" value="%s">', esc_attr( wp_create_nonce( 'sp_dobok_front_' . $el->id ) ) );
	}

	// ══════════════════════════════════════════════════════════════════════
	// PAGE
	// ══════════════════════════════════════════════════════════════════════
	public function render_page(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );

		$nb_dem  = $this->tables_ok() ? $this->nb_demandes_a_traiter() : 0;
		$onglets = [ 'stock' => 'Stock', 'demandes' => 'Demandes / distribution' . ( $nb_dem ? " ($nb_dem)" : '' ), 'adherents' => 'Adhérents', 'achats' => 'Achats', 'journal' => 'Journal', 'reglages' => 'Réglages' ];
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
			case 'demandes':  $this->render_demandes();  break;
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
			'ok_lot'              => [ 'success', 'Lot d\'achat enregistré et ajouté au stock.' . ( $n ? sprintf( ' %d demande(s) en attente maintenant réservée(s).', $n ) : '' ) ],
			'ok_supprime'         => [ 'success', 'Suppression effectuée.' ],
			'ok_inventaire'       => [ 'success', sprintf( 'Inventaire enregistré : %d ajustement(s).', $n ) ],
			'ok_reglages'         => [ 'success', 'Réglages enregistrés.' ],
			'err_saisie'          => [ 'error',   'Saisie incomplète ou invalide.' ],
			'err_detenu'          => [ 'error',   'Ce dobok n\'est pas enregistré chez cet adhérent.' ],
			'err_bdd'             => [ 'error',   'Erreur d\'enregistrement en base.' ],
			'ok_dem_terminee'     => [ 'success', 'Demande terminée : mouvements enregistrés.' ],
			'ok_dem_terminee_negatif' => [ 'warning', 'Demande terminée.' . $negatif ],
			'ok_dem_annulee'      => [ 'success', 'Demande close : l\'adhérent garde son dobok actuel.' ],
			'ok_dem_refusee'      => [ 'success', 'Demande refusée (famille prévenue par mail si activé).' ],
			'err_dem'             => [ 'error',   'Cette demande n\'existe pas ou a déjà été traitée.' ],
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
		$dem    = $this->demandes_par_ref();
		$seuil  = (int) $this->reglages()['seuil_alerte'];
		$tailles = $this->tailles();
		foreach ( array_merge( array_values( $stock ), array_values( $dem ) ) as $par_taille ) {
			foreach ( array_keys( $par_taille ) as $t ) if ( ! in_array( $t, $tailles, true ) ) $tailles[] = $t;
		}
		sort( $tailles );

		echo '<div class="sp-box"><h2>Stock par modèle et par taille</h2>';
		echo '<p class="description">Grand chiffre : doboks présents au club. Dessous : disponibles (présents moins réservés), réservés pour une demande, retours attendus, demandes en attente de stock, prêtés aux adhérents. La couleur porte sur le disponible. <span class="spd-leg spd-bas">≤ seuil d\'alerte (' . (int) $seuil . ')</span> <span class="spd-leg spd-neg">négatif : inventaire à faire</span></p>';
		echo '<div class="spd-scroll"><table class="widefat spd-grille"><thead><tr><th>Modèle</th>';
		foreach ( $tailles as $t ) echo '<th>' . (int) $t . '</th>';
		echo '<th>Total</th></tr></thead><tbody>';

		foreach ( [ 'blanc' => 'Doboks blancs', 'couleur' => 'Doboks couleur' ] as $type => $titre ) {
			echo '<tr class="spd-sep"><td colspan="' . ( count( $tailles ) + 2 ) . '">' . esc_html( $titre ) . '</td></tr>';
			foreach ( self::modeles_du_type( $type ) as $k => $m ) {
				$tot_s = 0; $tot_p = 0;
				echo '<tr><th title="' . esc_attr( $m['detail'] ) . '">' . esc_html( $m['label'] ) . '</th>';
				foreach ( $tailles as $t ) {
					$s   = $stock[ $k ][ $t ]['stock'] ?? 0;
					$p   = $stock[ $k ][ $t ]['pret']  ?? 0;
					$res = $dem[ $k ][ $t ]['reserve'] ?? 0;
					$att = $dem[ $k ][ $t ]['attente'] ?? 0;
					$ret = $dem[ $k ][ $t ]['attendu'] ?? 0;
					$dispo = $s - $res;
					$tot_s += $s; $tot_p += $p;
					$cls = $dispo < 0 ? 'spd-neg' : ( $dispo <= $seuil ? 'spd-bas' : '' );
					$det = [];
					if ( $res ) $det[] = 'dispo ' . $dispo;
					if ( $res ) $det[] = $res . ' réservé' . ( $res > 1 ? 's' : '' );
					if ( $ret ) $det[] = $ret . ' retour' . ( $ret > 1 ? 's' : '' ) . ' attendu' . ( $ret > 1 ? 's' : '' );
					if ( $att ) $det[] = '<span class="spd-att">' . $att . ' en attente</span>';
					if ( $p )   $det[] = $p . ' prêté' . ( $p > 1 ? 's' : '' );
					printf( '<td class="%s"><strong>%d</strong>%s</td>', esc_attr( $cls ), $s, implode( '', array_map( fn( $x ) => '<small>' . $x . '</small>', $det ) ) );
				}
				printf( '<td class="spd-tot"><strong>%d</strong><small>%d prêté%s</small></td></tr>', $tot_s, $tot_p, $tot_p > 1 ? 's' : '' );
			}
		}
		echo '</tbody></table></div></div>';

		// Manques : demandes en attente non couvertes par le disponible → à commander.
		$manques = [];
		foreach ( $dem as $k => $par_taille ) {
			foreach ( $par_taille as $t => $v ) {
				$att = $v['attente'] ?? 0;
				if ( ! $att ) continue;
				$dispo = ( $stock[ $k ][ $t ]['stock'] ?? 0 ) - ( $v['reserve'] ?? 0 );
				$besoin = $att - max( 0, $dispo );
				if ( $besoin > 0 ) $manques[] = sprintf( '%d × %s %d', $besoin, self::label( $k ), $t );
			}
		}
		if ( $manques ) {
			echo '<div class="sp-box"><h2>À commander pour les demandes en attente</h2><p>' . esc_html( implode( ' · ', $manques ) ) . '</p>';
			echo '<p class="description">Dès qu\'un lot est saisi dans l\'onglet Achats, les demandes en attente correspondantes sont réservées automatiquement (par ordre d\'arrivée) et les familles prévenues.</p></div>';
		}

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

	// ── Onglet Demandes / distribution ──────────────────────────────────
	// Conçu pour être utilisé sur téléphone au dojo : cartes empilées, gros boutons.
	private function render_demandes(): void {
		global $wpdb;
		$q      = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$retour = [ 'tab' => 'demandes', 'q' => $q ];
		$tel    = $this->table_eleves();

		$rows = $wpdb->get_results(
			"SELECT d.*, e.nom, e.prenom FROM {$this->t_dem()} d
			 LEFT JOIN $tel e ON e.id = d.eleve_id
			 WHERE d.statut IN ('ouverte','en_attente') ORDER BY d.created_at, d.id"
		);
		$hist = $wpdb->get_results(
			"SELECT d.*, e.nom, e.prenom FROM {$this->t_dem()} d
			 LEFT JOIN $tel e ON e.id = d.eleve_id
			 WHERE d.statut NOT IN ('ouverte','en_attente') ORDER BY d.updated_at DESC, d.id DESC LIMIT 50"
		);
		if ( $q !== '' ) {
			$needle = mb_strtolower( $q );
			$match  = fn( $d ) => str_contains( mb_strtolower( $d->nom . ' ' . $d->prenom . ' ' . $d->prenom . ' ' . $d->nom ), $needle );
			$rows   = array_filter( (array) $rows, $match );
			$hist   = array_filter( (array) $hist, $match );
		}

		$groupes = [
			'remettre' => [ 'À remettre / échanger (dobok réservé)', [] ],
			'retour'   => [ 'Retours attendus', [] ],
			'attente'  => [ 'En attente de stock', [] ],
		];
		foreach ( (array) $rows as $d ) {
			if ( $d->statut === 'en_attente' )         $groupes['attente'][1][]  = $d;
			elseif ( $d->nature === 'restitution' )    $groupes['retour'][1][]   = $d;
			else                                       $groupes['remettre'][1][] = $d;
		}

		echo '<form method="get" class="spd-filtres"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="demandes">';
		printf( '<input type="search" name="q" value="%s" placeholder="Rechercher un adhérent"><button class="button">Rechercher</button></form>', esc_attr( $q ) );

		if ( ! $this->reglages()['demandes_on'] ) {
			echo '<div class="notice notice-info inline"><p>Les demandes depuis la fiche adhérent sont désactivées (onglet Réglages).</p></div>';
		}

		foreach ( $groupes as $cle => [ $titre, $liste ] ) {
			printf( '<h2 class="spd-h2">%s <span class="spd-nb">%d</span></h2>', esc_html( $titre ), count( $liste ) );
			if ( ! $liste ) { echo '<p class="description">Rien pour le moment.</p>'; continue; }
			echo '<div class="spd-cartes">';
			foreach ( $liste as $d ) $this->render_carte_demande( $d, $cle, $retour );
			echo '</div>';
		}

		echo '<h2 class="spd-h2">Historique (50 dernières)</h2>';
		if ( ! $hist ) { echo '<p class="description">Aucune demande close.</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Adhérent</th><th>Demande</th><th>Statut</th><th>Note bureau</th></tr></thead><tbody>';
		foreach ( $hist as $d ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( mysql2date( 'd/m/Y', $d->updated_at ?: $d->created_at ) ),
				esc_html( mb_strtoupper( (string) $d->nom ) . ' ' . $d->prenom ),
				esc_html( self::libelle_demande( $d ) ),
				esc_html( self::STATUTS[ $d->statut ] ?? $d->statut ),
				esc_html( $d->note_bureau ) );
		}
		echo '</tbody></table>';
	}

	private function render_carte_demande( object $d, string $groupe, array $retour ): void {
		$fiche = admin_url( 'admin.php?page=' . self::PAGE . '&tab=adherents&q=' . rawurlencode( (string) $d->nom ) . '&open=' . (int) $d->eleve_id . '#el-' . (int) $d->eleve_id );
		echo '<div class="spd-carte">';
		printf( '<div class="spd-carte-tete"><a href="%s"><strong>%s</strong> %s</a><span class="spd-meta">%s · %s</span></div>',
			esc_url( $fiche ), esc_html( mb_strtoupper( (string) $d->nom ) ), esc_html( (string) $d->prenom ),
			esc_html( ( $d->type_dobok === 'blanc' ? 'Blanc' : 'Couleur' ) . ' · ' . ( self::NATURES[ $d->nature ]['label'] ?? $d->nature ) ),
			esc_html( mysql2date( 'd/m/Y', $d->created_at ) ) );
		echo '<div class="spd-carte-quoi">';
		if ( $d->rendu_modele )   printf( '<div>Rend : <b>%s %d</b></div>', esc_html( self::label( $d->rendu_modele ) ), (int) $d->rendu_taille );
		if ( $d->souhait_modele ) printf( '<div>Reçoit : <b>%s %d</b></div>', esc_html( self::label( $d->souhait_modele ) ), (int) $d->souhait_taille );
		echo '</div>';
		if ( $d->hors_regle )    echo '<span class="spd-alerte spd-warn">Déjà un échange de taille cette saison</span>';
		if ( $groupe === 'attente' ) {
			printf( '<span class="spd-alerte spd-info">Stock disponible : %d</span>', $this->disponible( $d->souhait_modele, (int) $d->souhait_taille ) );
		}
		if ( $d->note_adherent ) printf( '<div class="spd-carte-note">« %s »</div>', esc_html( $d->note_adherent ) );

		// Action principale
		$this->form_open( 'dem_terminer', $retour, 'spd-carte-form' );
		printf( '<input type="hidden" name="dem_id" value="%d">', (int) $d->id );
		if ( $d->rendu_modele ) {
			echo '<label><input type="checkbox" name="ancien_rendu" value="1" checked> Ancien dobok rendu</label>';
			echo '<label>État ' . $this->select_etat() . '</label>'; // phpcs:ignore
		}
		$libelle = $groupe === 'retour' ? '✓ Rendu' : ( $d->rendu_modele ? '✓ Échange fait' : '✓ Remis' );
		$confirm = $groupe === 'attente' ? ' onclick="return confirm(\'Pas de stock disponible d\\\'après le calcul. Remettre quand même ?\');"' : '';
		printf( '<button class="button button-primary spd-gros"%s>%s</button></form>', $confirm, esc_html( $libelle ) );

		// Actions secondaires
		echo '<details class="spd-carte-plus"><summary>Autres actions</summary>';
		$this->form_open( 'dem_annuler', $retour, 'spd-carte-form' );
		printf( '<input type="hidden" name="dem_id" value="%d">', (int) $d->id );
		echo '<input type="text" name="note" placeholder="Note (facultatif)">';
		echo '<button class="button">Garde son dobok actuel (clore)</button></form>';
		$this->form_open( 'dem_refuser', $retour, 'spd-carte-form' );
		printf( '<input type="hidden" name="dem_id" value="%d">', (int) $d->id );
		echo '<input type="text" name="note" placeholder="Motif communiqué à la famille">';
		echo '<button class="button button-link-delete" onclick="return confirm(\'Refuser cette demande ?\');">Refuser</button></form>';
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
			// Doboks encore chez des adhérents qui ne sont plus actifs sur cette saison, ou qui
			// sont passés au renforcement musculaire (non concernés par les doboks).
			$tous = $this->dotations();
			$ids  = array_keys( $tous );
			$eleves = $ids ? $wpdb->get_results(
				"SELECT * FROM $tel WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ") ORDER BY nom, prenom"
			) : [];
			$eleves = array_filter( (array) $eleves, fn( $e ) => ! ( (int) $e->actif === 1 && $e->saison === $saison ) || ! self::est_concerne( $e ) );
		} else {
			$eleves = array_filter( (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM $tel WHERE actif = 1 AND saison = %s ORDER BY nom, prenom", $saison
			) ), [ __CLASS__, 'est_concerne' ] );
		}
		if ( $q !== '' ) {
			$needle = mb_strtolower( $q );
			$eleves = array_filter( (array) $eleves, fn( $e ) => str_contains( mb_strtolower( $e->nom . ' ' . $e->prenom . ' ' . $e->prenom . ' ' . $e->nom ), $needle ) );
		}

		$dot  = $this->dotations( array_map( fn( $e ) => (int) $e->id, (array) $eleves ) );
		$dems = $this->demandes_ouvertes( array_map( fn( $e ) => (int) $e->id, (array) $eleves ) );
		$ech  = $this->echanges_taille( $saison );
		$rows = [];
		$nb_alertes = 0;
		foreach ( (array) $eleves as $el ) {
			$id  = (int) $el->id;
			$sit = [];
			foreach ( [ 'blanc', 'couleur' ] as $type ) {
				$sit[ $type ] = $this->situation( $el, $type, $dot[ $id ] ?? [], $saison, $ech[ $id ][ $type ] ?? 0 );
			}
			$alertes = $vue === 'recuperer'
				? [ [ 'warn', self::est_concerne( $el ) ? 'Plus adhérent cette saison : dobok à récupérer' : 'Renforcement musculaire : dobok à récupérer' ] ]
				: array_merge( $this->alertes_donnees( $el, $saison ), $sit['blanc']['alertes'], $sit['couleur']['alertes'] );
			foreach ( $dems[ (int) $el->id ] ?? [] as $d ) {
				$alertes[] = [ $d->statut === 'ouverte' ? 'info' : 'warn', 'Demande : ' . self::libelle_demande( $d ) . ( $d->statut === 'en_attente' ? ' (en attente de stock)' : ( $d->souhait_modele ? ' (réservé)' : ' (retour attendu)' ) ) ];
			}
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
		echo '<button class="button">Filtrer</button>';
		echo '<button type="button" class="button spd-btn-regles" onclick="document.getElementById(\'spd-regles\').showModal()" title="Rappel des règles : qui porte quel dobok (World Taekwondo et règle du club)"><span class="dashicons dashicons-info-outline"></span> Règles des doboks</button>';
		echo '</form>';
		$this->render_modale_regles();

		if ( $vue !== 'recuperer' ) {
			printf( '<p class="spd-compte">%d adhérent(s) affiché(s) (hors renforcement musculaire) — <strong>%d</strong> avec au moins un point à traiter.</p>', count( $rows ), $nb_alertes );
		} else {
			echo '<p class="description">Adhérents qui ne sont pas actifs sur la saison ' . esc_html( $saison ) . ', ou inscrits en renforcement musculaire, mais ont encore un dobok du club en leur possession.</p>';
		}

		if ( ! $rows ) {
			echo '<div class="sp-box"><p>Aucun adhérent à afficher.</p></div>';
		} else {
			echo '<table class="widefat striped spd-adh"><thead><tr><th>Adhérent</th><th>Taille</th><th>Dobok blanc</th><th>Dobok couleur</th><th>Alertes</th><th></th></tr></thead><tbody>';
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

	/**
	 * Fenêtre « Règles des doboks » (onglet Adhérents) : la règle appliquée par le club
	 * (celle que calcule ce module) et, pour référence, les règles World Taekwondo dont elle
	 * s'inspire, avec les écarts assumés. Sources relevées le 26/09/2026 :
	 *   - WT, « Guidelines on Identifications » 2022 (Poomsae Competition Uniform #2, Dobok Uniform #1) ;
	 *   - British Taekwondo, règlement Poomsae Para (juin 2025, basé sur les règles WT) pour les 8-11 ans.
	 */
	private function render_modale_regles(): void {
		$r      = $this->reglages();
		$cadet  = (int) $r['cadet_age_max'];
		$master = (int) $r['master_age_min'];
		$ref    = $r['annee_ref'] === 'debut' ? 'l\'année civile de début de saison' : 'l\'année civile de fin de saison';
		?>
		<dialog id="spd-regles" class="spd-modale" onclick="if (event.target === this) this.close();">
			<div class="spd-modale-corps">
				<button type="button" class="spd-modale-x" onclick="this.closest('dialog').close()" aria-label="Fermer">×</button>
				<h2>Règles des doboks</h2>

				<h3>Règle du club (appliquée par ce module)</h3>
				<ul>
					<li><strong>Chaque adhérent</strong> (hors renforcement musculaire) reçoit en prêt un <strong>dobok blanc</strong> et un <strong>dobok couleur</strong>. Ils restent la propriété du club et sont à rendre en cas de départ.</li>
					<li><strong>Dobok blanc</strong> : même modèle pour tous les âges. <strong>Col noir dès la ceinture noire</strong> (Poom ou Dan), col blanc sinon — déduit du grade.</li>
					<li><strong>Dobok couleur</strong> : acheté par ensemble (veste + pantalon), modèle déduit de la <strong>catégorie de compétition</strong> et du sexe :
						Cadet jusqu'à <?php echo $cadet; ?> ans (les plus jeunes portent aussi le modèle cadet), Junior/Senior, Master à partir de <?php echo $master; ?> ans.
						Âge = âge atteint dans <?php echo esc_html( $ref ); ?> (onglet Réglages).</li>
					<li>Un adhérent <strong>peut garder</strong> son ancien modèle ou sa taille : les écarts sont signalés, jamais imposés.</li>
					<li><strong>1 échange de taille par saison</strong> et par dobok : au-delà, ce n'est pas bloqué mais le bureau est alerté. Un changement de modèle ou un remplacement (abîmé) ne compte pas.</li>
					<li>Tailles de 10 en 10 cm : taille suggérée = taille de l'adhérent arrondie à la dizaine supérieure<?php echo (int) $r['marge'] ? ' + ' . (int) $r['marge'] . ' cm de marge' : ''; ?>.</li>
				</ul>

				<h3>Référence World Taekwondo — tenue de poomsae (compétition)</h3>
				<div class="spd-scroll"><table class="widefat striped">
					<thead><tr><th>Catégorie</th><th>Âge</th><th>Grade (ceinture)</th><th>Veste</th><th>Pantalon</th></tr></thead>
					<tbody>
						<tr><td>Aspirants <em>(tolérance)</em></td><td>8 – 11 ans</td><td>Keup ou Poom</td><td>Dobok blanc standard, <em>ou</em> veste blanche col rouge et noir</td><td>Blanc, <em>ou</em> bleu (garçons) / rouge (filles)</td></tr>
						<tr><td>Cadet garçon</td><td>12 – 14 ans</td><td>Poom (ceinture rouge et noire)</td><td>Blanche, col rouge et noir</td><td>Bleu</td></tr>
						<tr><td>Cadet fille</td><td>12 – 14 ans</td><td>Poom (ceinture rouge et noire)</td><td>Blanche, col rouge et noir</td><td>Rouge</td></tr>
						<tr><td>Junior / Senior homme</td><td>15 – 50 ans</td><td>Dan (ceinture noire)</td><td>Blanche, col noir</td><td>« T-Black » (bleu nuit presque noir)</td></tr>
						<tr><td>Junior / Senior femme</td><td>15 – 50 ans</td><td>Dan (ceinture noire)</td><td>Blanche, col noir</td><td>Bleu clair</td></tr>
						<tr><td>Master (H/F)</td><td>51 ans et plus</td><td>Dan (ceinture noire)</td><td>Dorée</td><td>« T-Black »</td></tr>
					</tbody>
				</table></div>
				<p class="description">En compétition WT, la tenue de poomsae est obligatoire à partir de cadet ; les divisions Keup (ceintures de couleur) concourent en dobok blanc standard.</p>

				<h3>Référence World Taekwondo — dobok blanc standard</h3>
				<ul>
					<li>Veste et pantalon blancs, col en V : <strong>blanc</strong> pour les Keup (ceintures de couleur), <strong>rouge et noir</strong> pour les Poom (ceinture noire des moins de 15 ans), <strong>noir</strong> pour les Dan.</li>
				</ul>

				<h3>Écarts assumés par le club</h3>
				<ul>
					<li>Les <strong>Poom</strong> reçoivent un blanc <strong>col noir</strong> (WT : col rouge et noir).</li>
					<li>Le dobok couleur est prêté à <strong>tous</strong>, ceintures de couleur comprises (WT : tenue de compétition réservée aux Poom / Dan à partir de 12 ans).</li>
					<li>Masters : le club leur prête un ensemble bleu foncé ; WT prévoit une veste dorée.<?php echo $master !== 51 ? ' WT place la limite à <strong>51 ans</strong> : le réglage actuel du club est ' . $master . ' ans.' : ''; ?></li>
				</ul>

				<p class="spd-sources">Sources : <a href="https://www.worldtaekwondo.org/att_file_up/partners_suppliers/2022/2022_WT_Guidelines_of_Identifications.pdf" target="_blank" rel="noopener">World Taekwondo, Guidelines on Identifications (2022)</a> ·
					<a href="https://www.britishtaekwondo.org.uk/wp-content/uploads/2025/09/BT-Poomase-Para-Competition-Rules-June-2025.pdf" target="_blank" rel="noopener">British Taekwondo, règlement Poomsae (juin 2025, d'après WT)</a>.
					Relevé le 26/09/2026 — à revérifier à chaque saison, WT fait évoluer ses règlements.</p>
			</div>
		</dialog>
		<?php
	}

	private function render_ligne_adherent( object $el, array $sit, array $alertes, string $saison, array $retour, bool $ouvert ): void {
		$id    = (int) $el->id;
		$age   = $this->age_competition( $el, $saison );
		$cat   = $this->categorie_competition( $age );
		$sexe  = self::sexe( $el );
		$fiche = admin_url( 'admin.php?page=sp-cal-fiche-eleve&eleve_id=' . $id );

		echo '<tr id="el-' . $id . '">';
		printf( '<td><a href="%s"><strong>%s</strong> %s</a><div class="spd-meta">%s · %s%s</div></td>',
			esc_url( $fiche ), esc_html( mb_strtoupper( $el->nom ) ), esc_html( $el->prenom ),
			esc_html( $el->grade ?: 'grade ?' ),
			esc_html( $cat ?: 'catégorie ?' ), $sexe ? ' ' . esc_html( $sexe ) : '' );

		// Taille actuelle : valeur lue en cm, ou saisie brute illisible signalée telle quelle.
		$cm_brut = trim( (string) ( $el->taille_cm ?? '' ) );
		$cm      = self::parse_cm( $cm_brut );
		if ( $cm !== null )     printf( '<td class="spd-taille"><strong>%s</strong> cm</td>', esc_html( (string) round( $cm ) ) );
		elseif ( $cm_brut !== '' ) printf( '<td class="spd-taille"><span class="spd-alerte spd-warn">%s</span></td>', esc_html( $cm_brut ) );
		else                    echo '<td class="spd-taille"><span class="spd-vide">—</span></td>';

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
		printf( '<tr class="spd-gerer" id="gerer-%d"%s><td colspan="6"><div class="spd-panneau">', $id, $ouvert ? '' : ' hidden' );
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
		printf( '<tr><th>Demandes des adhérents</th><td><label><input type="checkbox" name="demandes_on" value="1"%s> Autoriser les demandes depuis la fiche adhérent (lien personnel)</label><p class="description">Décoché : la fiche affiche toujours les doboks de l\'adhérent, mais sans possibilité de faire une demande.</p></td></tr>', checked( $r['demandes_on'], 1, false ) );
		printf( '<tr><th>Mail au bureau</th><td><label><input type="checkbox" name="mail_bureau" value="1"%s> À chaque nouvelle demande ou annulation</label><br><input type="text" name="email_bureau" value="%s" class="regular-text" placeholder="%s"><p class="description">Adresses séparées par des virgules. Vide = adresse de notification générale du plugin (%s).</p></td></tr>',
			checked( $r['mail_bureau'], 1, false ), esc_attr( $r['email_bureau'] ),
			esc_attr( (string) get_option( 'sp_cal_notif_email', get_option( 'admin_email' ) ) ),
			esc_html( (string) get_option( 'sp_cal_notif_email', get_option( 'admin_email' ) ) ) );
		printf( '<tr><th>Mail aux familles</th><td><label><input type="checkbox" name="mail_famille" value="1"%s> Accusé de réception, dobok réservé (après une attente), demande refusée</label></td></tr>', checked( $r['mail_famille'], 1, false ) );
		echo '</tbody></table><p><button class="button button-primary">Enregistrer</button></p></form></div>';

		echo '<div class="sp-box"><h2>Modèles</h2><table class="widefat striped"><thead><tr><th>Modèle</th><th>Type</th><th>Description</th></tr></thead><tbody>';
		foreach ( self::MODELES as $m ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $m['label'] ), esc_html( $m['type'] ), esc_html( $m['detail'] ) );
		}
		echo '</tbody></table><p class="description">Blanc : le col est déduit du grade (col noir dès la ceinture noire, Dan ou Poom). Couleur : acheté par ensemble, modèle déduit de la catégorie de compétition et du sexe. Un adhérent peut garder son ancien modèle ou sa taille : les écarts sont signalés, jamais imposés.</p></div>';
	}
}

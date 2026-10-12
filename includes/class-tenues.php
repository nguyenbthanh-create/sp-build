<?php
/**
 * Tenues du renforcement musculaire prêtées par le club — étape 1 (12/10/2026) : côté bureau.
 * Besoin de commande, stock, réception des commandes, remise, restitution, journal.
 * Étape 2 (à venir) : bloc « Ma tenue » sur la fiche de l'adhérent, avec ses demandes.
 *
 * Même principe que les doboks (class-dobok.php) : AUCUN compteur n'est stocké, tout est déduit
 * d'un journal de mouvements — chaque ligne porte son effet sur le stock du club (mvt_stock) et
 * sur ce que détient l'adhérent (mvt_eleve). Stock = SUM(mvt_stock), détenu = SUM(mvt_eleve).
 *
 * Volontairement dans ses PROPRES tables (sp_cal_tenue_*) et sa propre classe : le module doboks,
 * en service, n'est pas modifié. Les deux partagent la page d'administration « Doboks & tenues »
 * (?page=sp-cal-dobok&objet=tenues), le droit « gestion des adhésions » et la feuille de style.
 *
 * Ce qui diffère des doboks :
 *   - deux pièces suivies séparément, le haut et le bas, chacune en coupe homme ou femme ;
 *   - tailles en lettres (XS → XXL, liste réglable), lues sur la fiche : « T-shirt » et « Pantalon » ;
 *   - aucun modèle à déduire d'un grade ou d'un âge : un adhérent du renforcement doit avoir
 *     1 haut + 1 bas, dans la coupe de son sexe (proposée, jamais imposée).
 *
 * Qui est concerné : les adhérents inscrits au renforcement musculaire. Une adhésion au taekwondo
 * donne aussi accès au renforcement (pas l'inverse) et rien n'enregistre qui en profite : pour eux
 * rien n'est attendu, mais le bureau peut leur remettre une tenue (vue « Tous les adhérents »).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Tenues {

	private static ?self $instance = null;
	private $db;

	const SCHEMA_VERSION = '1';
	const OBJET          = 'tenues';   // ?page=sp-cal-dobok&objet=tenues

	const MODELES = [
		'haut_h' => [ 'piece' => 'haut', 'coupe' => 'h', 'label' => 'Haut homme' ],
		'haut_f' => [ 'piece' => 'haut', 'coupe' => 'f', 'label' => 'Haut femme' ],
		'bas_h'  => [ 'piece' => 'bas',  'coupe' => 'h', 'label' => 'Bas homme' ],
		'bas_f'  => [ 'piece' => 'bas',  'coupe' => 'f', 'label' => 'Bas femme' ],
	];

	const PIECES = [ 'haut' => 'Haut', 'bas' => 'Bas' ];

	const TYPES_MVT = [
		'achat'       => 'Réception commande',
		'inventaire'  => 'Ajustement inventaire',
		'remise'      => 'Remise à l\'adhérent',
		'restitution' => 'Restitution',
		'perte'       => 'Non rendu / perdu',
		'reforme'     => 'Réforme (sortie du stock)',
	];

	const ETATS = [
		'bon'        => 'Bon état',
		'use'        => 'Usé',
		'a_reformer' => 'À réformer',
	];

	const MOTIFS = [
		'taille'       => 'Changement de taille',
		'remplacement' => 'Remplacement (abîmé)',
		'retour'       => 'Retour (départ, plus besoin)',
	];

	const TAILLES_DEFAUT = [ 'XS', 'S', 'M', 'L', 'XL', 'XXL' ];

	public static function get_instance( $db = null ): self {
		if ( self::$instance === null ) self::$instance = new self( $db );
		return self::$instance;
	}

	private function __construct( $db = null ) {
		$this->db = $db;

		SP_Cal_Schema::enregistrer( 'Tenues renfo', [ $this, 'maybe_create_tables' ] );

		foreach ( [ 'remise', 'restitution', 'perte', 'commande', 'supprimer_lot', 'inventaire', 'reforme', 'supprimer_mvt', 'reglages' ] as $a ) {
			add_action( 'admin_post_sp_tenue_' . $a, [ $this, 'handle_' . $a ] );
		}
	}

	// ══════════════════════════════════════════════════════════════
	// SCHÉMA
	// ══════════════════════════════════════════════════════════════
	private function t_lots(): string { global $wpdb; return $wpdb->prefix . 'sp_cal_tenue_lots'; }
	private function t_mvt(): string  { global $wpdb; return $wpdb->prefix . 'sp_cal_tenue_mouvements'; }

	private function table_eleves(): string {
		global $wpdb;
		return $this->db ? $this->db->table_eleves() : $wpdb->prefix . 'sp_cal_eleves';
	}

	private function tables_ok(): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->t_mvt() ) ) === $this->t_mvt()
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->t_lots() ) ) === $this->t_lots();
	}

	/** Même garde de version que les doboks : la version n'est notée que si les tables existent vraiment. */
	public function maybe_create_tables(): void {
		if ( get_option( 'sp_tenue_schema_version' ) === self::SCHEMA_VERSION ) return;

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "CREATE TABLE {$this->t_lots()} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			modele varchar(20) NOT NULL DEFAULT '',
			taille varchar(8) NOT NULL DEFAULT '',
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
			taille varchar(8) NOT NULL DEFAULT '',
			quantite smallint(6) NOT NULL DEFAULT 1,
			mvt_stock int(11) NOT NULL DEFAULT 0,
			mvt_eleve int(11) NOT NULL DEFAULT 0,
			eleve_id mediumint(9) DEFAULT NULL,
			etat varchar(20) NOT NULL DEFAULT '',
			motif varchar(20) NOT NULL DEFAULT '',
			saison varchar(20) NOT NULL DEFAULT '',
			lot_id bigint(20) unsigned DEFAULT NULL,
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY eleve_id (eleve_id),
			KEY ref (modele,taille)
		) $charset;" );

		if ( $this->tables_ok() ) {
			update_option( 'sp_tenue_schema_version', self::SCHEMA_VERSION );
		} else {
			error_log( '[SP_Build] Tables des tenues non créées — vérifier les droits CREATE TABLE de l\'utilisateur MySQL.' );
			throw new \RuntimeException( 'tables des tenues non créées' ); // module central : nouvel essai dans 10 min
		}
	}

	// ══════════════════════════════════════════════════════════════
	// RÈGLES (fonctions pures, testées dans outils/tests/TenuesTest.php)
	// ══════════════════════════════════════════════════════════════

	/** Liste des tailles à partir du réglage « XS, S, M… » (majuscules, sans doublon). */
	public static function tailles_depuis_texte( $texte ): array {
		$out = [];
		foreach ( preg_split( '/[\s,;]+/', mb_strtoupper( (string) $texte ) ) as $t ) {
			$t = preg_replace( '/[^A-Z0-9]/', '', $t );
			if ( $t !== '' && strlen( $t ) <= 8 && ! in_array( $t, $out, true ) ) $out[] = $t;
		}
		return $out ?: self::TAILLES_DEFAUT;
	}

	/**
	 * Taille lue dans un champ libre de la fiche (« m », « Taille L », « 14 ans / xs », « 2XL »…).
	 * Renvoie une taille de la liste, ou '' si rien de reconnu (la fiche est alors à compléter).
	 */
	public static function lire_taille( $brut, array $tailles ): string {
		$s = mb_strtoupper( trim( (string) $brut ) );
		if ( $s === '' ) return '';
		$s = preg_replace( '/\b2\s*XL\b/', 'XXL', $s );
		$s = preg_replace( '/\b3\s*XL\b/', 'XXXL', $s );
		// Les plus longues d'abord : « XL » ne doit pas être lu « L », ni « XS » lu « S ».
		$par_longueur = $tailles;
		usort( $par_longueur, static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		foreach ( $par_longueur as $t ) {
			if ( preg_match( '/(?<![A-Z0-9])' . preg_quote( $t, '/' ) . '(?![A-Z0-9])/', $s ) ) return $t;
		}
		return '';
	}

	/** Effet d'un mouvement : [ sur le stock du club, sur ce que détient l'adhérent ]. */
	public static function effets_mouvement( string $type, int $qte, string $etat = '', int $sens = 1 ): array {
		switch ( $type ) {
			case 'achat':       return [ $qte, 0 ];
			case 'inventaire':  return [ $sens * $qte, 0 ];
			case 'remise':      return [ -$qte, $qte ];
			// Une pièce à réformer quitte l'adhérent sans rentrer dans le stock utilisable.
			case 'restitution': return [ $etat === 'a_reformer' ? 0 : $qte, -$qte ];
			case 'perte':       return [ 0, -$qte ];
			case 'reforme':     return [ -$qte, 0 ];
		}
		return [ 0, 0 ];
	}

	/** Les adhérents du renforcement musculaire (colonne categorie_saisie : « RENFO » ou ancien libellé libre). */
	public static function est_renfo( $el ): bool {
		return (bool) preg_match( '/renfo|renforcement/i', (string) ( $el->categorie_saisie ?? '' ) );
	}

	/** 'M', 'F' ou '' (fiche à compléter). */
	public static function sexe( $el ): string {
		$extra = json_decode( (string) ( $el->extra_data ?? '' ), true );
		$s     = is_array( $extra ) ? ( $extra['sexe'] ?? '' ) : '';
		if ( ! $s && isset( $el->sexe ) ) $s = $el->sexe;
		return in_array( $s, [ 'M', 'F' ], true ) ? $s : '';
	}

	/**
	 * Ce qu'il faudrait remettre à un adhérent pour une pièce : [ modèle, taille ] — chacun peut
	 * être '' quand la fiche ne permet pas de le savoir (sexe ou taille manquants).
	 */
	public static function attendu( $el, string $piece, array $tailles ): array {
		$sexe   = self::sexe( $el );
		$modele = $sexe === '' ? '' : $piece . '_' . ( $sexe === 'F' ? 'f' : 'h' );
		$taille = self::lire_taille( $piece === 'haut' ? ( $el->taille_tshirt ?? '' ) : ( $el->taille_pantalon ?? '' ), $tailles );
		return [ $modele, $taille ];
	}

	/**
	 * Besoin de commande pour équiper les adhérents du renforcement.
	 *
	 * @param object[] $eleves  Adhérents actifs du renforcement.
	 * @param array    $detenus [ eleve_id => [ [ 'modele' =>, 'taille' =>, 'nb' => ], … ] ]
	 * @return array{besoin:array<string,array<string,int>>,incomplets:array<int,array{eleve:object,manque:string[]}>,equipes:int}
	 */
	public static function besoin( array $eleves, array $detenus, array $tailles ): array {
		$besoin = [];
		$incomplets = [];
		$equipes = 0;
		foreach ( $eleves as $el ) {
			$a = [];
			foreach ( $detenus[ (int) $el->id ] ?? [] as $d ) $a[ self::MODELES[ $d['modele'] ]['piece'] ?? '' ] = true;
			$manque = [];
			foreach ( array_keys( self::PIECES ) as $piece ) {
				if ( ! empty( $a[ $piece ] ) ) continue;   // il a déjà cette pièce
				[ $modele, $taille ] = self::attendu( $el, $piece, $tailles );
				if ( $modele === '' ) $manque['sexe'] = 'sexe';
				if ( $taille === '' ) $manque[ $piece ] = $piece === 'haut' ? 'taille du haut (T-shirt)' : 'taille du bas (pantalon)';
				if ( $modele !== '' && $taille !== '' ) $besoin[ $modele ][ $taille ] = ( $besoin[ $modele ][ $taille ] ?? 0 ) + 1;
			}
			if ( $manque ) $incomplets[] = [ 'eleve' => $el, 'manque' => array_values( $manque ) ];
			if ( ! empty( $a['haut'] ) && ! empty( $a['bas'] ) ) $equipes++;
		}
		return [ 'besoin' => $besoin, 'incomplets' => $incomplets, 'equipes' => $equipes ];
	}

	// ══════════════════════════════════════════════════════════════
	// DONNÉES
	// ══════════════════════════════════════════════════════════════
	private function reglages(): array {
		$r = get_option( 'sp_tenue_reglages', [] );
		$r = is_array( $r ) ? $r : [];
		return [
			'tailles'      => self::tailles_depuis_texte( $r['tailles'] ?? implode( ',', self::TAILLES_DEFAUT ) ),
			'seuil_alerte' => max( 0, (int) ( $r['seuil_alerte'] ?? 1 ) ),
		];
	}

	private function tailles(): array { return $this->reglages()['tailles']; }

	private static function label( string $modele ): string {
		return self::MODELES[ $modele ]['label'] ?? $modele;
	}

	/** Même calcul que les doboks et que la création d'un adhérent. */
	private function saison_courante(): string {
		$s = (string) get_option( 'tkd_saison_courante', '' );
		if ( $s ) return $s;
		$y = (int) current_time( 'Y' );
		$m = (int) current_time( 'n' );
		return $m >= 9 ? $y . '/' . ( $y + 1 ) : ( $y - 1 ) . '/' . $y;
	}

	private function ajouter_mouvement( string $type, string $modele, string $taille, int $qte = 1, array $o = [] ): bool {
		global $wpdb;
		[ $ds, $de ] = self::effets_mouvement( $type, $qte, $o['etat'] ?? '', $o['sens'] ?? 1 );
		return (bool) $wpdb->insert( $this->t_mvt(), [
			'type'       => $type,
			'modele'     => $modele,
			'taille'     => $taille,
			'quantite'   => $qte,
			'mvt_stock'  => $ds,
			'mvt_eleve'  => $de,
			'eleve_id'   => $o['eleve_id'] ?? null,
			'etat'       => $o['etat']  ?? '',
			'motif'      => $o['motif'] ?? '',
			'saison'     => $this->saison_courante(),
			'lot_id'     => $o['lot_id'] ?? null,
			'note'       => mb_substr( (string) ( $o['note'] ?? '' ), 0, 255 ),
			'created_at' => current_time( 'mysql' ),
			'created_by' => get_current_user_id(),
		] );
	}

	/** [modele][taille] => ['stock' => n, 'pret' => n] */
	private function stock_par_ref(): array {
		global $wpdb;
		$out = [];
		foreach ( (array) $wpdb->get_results( "SELECT modele, taille, SUM(mvt_stock) AS stock, SUM(mvt_eleve) AS pret FROM {$this->t_mvt()} GROUP BY modele, taille" ) as $r ) {
			$out[ $r->modele ][ $r->taille ] = [ 'stock' => (int) $r->stock, 'pret' => (int) $r->pret ];
		}
		return $out;
	}

	private function stock_de( string $modele, string $taille ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(mvt_stock),0) FROM {$this->t_mvt()} WHERE modele = %s AND taille = %s", $modele, $taille ) );
	}

	/** Ce que détiennent les adhérents : [eleve_id] => [ ['modele','taille','nb'], … ] */
	private function detenus( ?array $eleve_ids = null ): array {
		global $wpdb;
		$where = 'eleve_id IS NOT NULL';
		if ( $eleve_ids !== null ) {
			if ( ! $eleve_ids ) return [];
			$where .= ' AND eleve_id IN (' . implode( ',', array_map( 'intval', $eleve_ids ) ) . ')';
		}
		$out = [];
		foreach ( (array) $wpdb->get_results( "SELECT eleve_id, modele, taille, SUM(mvt_eleve) AS nb FROM {$this->t_mvt()} WHERE $where GROUP BY eleve_id, modele, taille HAVING nb > 0 ORDER BY modele, taille" ) as $r ) {
			$out[ (int) $r->eleve_id ][] = [ 'modele' => $r->modele, 'taille' => $r->taille, 'nb' => (int) $r->nb ];
		}
		return $out;
	}

	private function detient( int $eleve_id, string $modele, string $taille ): bool {
		foreach ( $this->detenus( [ $eleve_id ] )[ $eleve_id ] ?? [] as $d ) {
			if ( $d['modele'] === $modele && $d['taille'] === $taille ) return true;
		}
		return false;
	}

	private function eleve( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_eleves()} WHERE id = %d", $id ) ) ?: null;
	}

	/** Adhérents actifs, triés par nom. */
	private function eleves_actifs(): array {
		global $wpdb;
		return (array) $wpdb->get_results( "SELECT * FROM {$this->table_eleves()} WHERE actif = 1 ORDER BY nom ASC, prenom ASC" );
	}

	// ══════════════════════════════════════════════════════════════
	// TRAITEMENTS (admin-post)
	// ══════════════════════════════════════════════════════════════
	private function verifier( string $action ): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.', '', [ 'response' => 403 ] );
		check_admin_referer( 'sp_tenue_' . $action );
	}

	private static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => SP_Cal_Dobok::PAGE, 'objet' => self::OBJET ], $args ), admin_url( 'admin.php' ) );
	}

	/** Retour à l'onglet d'origine (filtres, adhérent ouvert) avec un message. */
	private function retour( string $msg, array $extra = [] ): void {
		$args = [];
		foreach ( [ 'tab', 'q', 'vue' ] as $k ) {
			if ( isset( $_POST[ 'r_' . $k ] ) && $_POST[ 'r_' . $k ] !== '' ) $args[ $k ] = sanitize_text_field( wp_unslash( $_POST[ 'r_' . $k ] ) );
		}
		$args['msg'] = $msg;
		$url = self::url( array_merge( $args, $extra ) );
		if ( ! empty( $extra['open'] ) ) $url .= '#el-' . (int) $extra['open'];
		wp_safe_redirect( $url );
		exit;
	}

	private function post_modele( string $key = 'modele' ): string {
		$m = sanitize_key( $_POST[ $key ] ?? '' );
		return isset( self::MODELES[ $m ] ) ? $m : '';
	}

	private function post_taille( string $key = 'taille' ): string {
		$t = mb_strtoupper( sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		return in_array( $t, $this->tailles(), true ) ? $t : '';
	}

	private function post_note(): string {
		return sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
	}

	/** Remise du haut et / ou du bas en une seule saisie. */
	public function handle_remise(): void {
		$this->verifier( 'remise' );
		$id = (int) ( $_POST['eleve_id'] ?? 0 );
		if ( ! $id || ! $this->eleve( $id ) ) $this->retour( 'err_saisie' );

		$a_remettre = [];
		foreach ( array_keys( self::PIECES ) as $piece ) {
			$modele = $this->post_modele( $piece . '_modele' );
			if ( $modele === '' ) continue;   // « ne pas remettre »
			$taille = $this->post_taille( $piece . '_taille' );
			if ( $taille === '' || self::MODELES[ $modele ]['piece'] !== $piece ) $this->retour( 'err_saisie', [ 'open' => $id ] );
			$a_remettre[] = [ $modele, $taille ];
		}
		if ( ! $a_remettre ) $this->retour( 'err_rien', [ 'open' => $id ] );

		$negatif = false;
		foreach ( $a_remettre as [ $modele, $taille ] ) {
			if ( ! $this->ajouter_mouvement( 'remise', $modele, $taille, 1, [ 'eleve_id' => $id, 'note' => $this->post_note() ] ) ) $this->retour( 'err_bdd', [ 'open' => $id ] );
			if ( $this->stock_de( $modele, $taille ) < 0 ) $negatif = true;
		}
		$this->retour( $negatif ? 'ok_remise_negatif' : 'ok_remise', [ 'open' => $id, 'n' => count( $a_remettre ) ] );
	}

	public function handle_restitution(): void {
		$this->verifier( 'restitution' );
		$id     = (int) ( $_POST['eleve_id'] ?? 0 );
		$modele = $this->post_modele();
		$taille = $this->post_taille();
		$etat   = sanitize_key( $_POST['etat'] ?? 'bon' );
		$motif  = sanitize_key( $_POST['motif'] ?? 'retour' );
		if ( ! $id || $modele === '' || $taille === '' || ! isset( self::ETATS[ $etat ] ) || ! isset( self::MOTIFS[ $motif ] ) ) $this->retour( 'err_saisie', [ 'open' => $id ] );
		if ( ! $this->detient( $id, $modele, $taille ) ) $this->retour( 'err_detenu', [ 'open' => $id ] );
		$ok = $this->ajouter_mouvement( 'restitution', $modele, $taille, 1, [ 'eleve_id' => $id, 'etat' => $etat, 'motif' => $motif, 'note' => $this->post_note() ] );
		$this->retour( $ok ? 'ok_restitution' : 'err_bdd', [ 'open' => $id ] );
	}

	public function handle_perte(): void {
		$this->verifier( 'perte' );
		$id     = (int) ( $_POST['eleve_id'] ?? 0 );
		$modele = $this->post_modele();
		$taille = $this->post_taille();
		if ( ! $id || $modele === '' || $taille === '' ) $this->retour( 'err_saisie', [ 'open' => $id ] );
		if ( ! $this->detient( $id, $modele, $taille ) ) $this->retour( 'err_detenu', [ 'open' => $id ] );
		$ok = $this->ajouter_mouvement( 'perte', $modele, $taille, 1, [ 'eleve_id' => $id, 'note' => $this->post_note() ] );
		$this->retour( $ok ? 'ok_perte' : 'err_bdd', [ 'open' => $id ] );
	}

	/** Réception d'une commande : une quantité par modèle × taille, saisie dans une grille. */
	public function handle_commande(): void {
		$this->verifier( 'commande' );
		global $wpdb;
		$date = sanitize_text_field( wp_unslash( $_POST['date_achat'] ?? '' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) $date = current_time( 'Y-m-d' );
		$fournisseur = sanitize_text_field( wp_unslash( $_POST['fournisseur'] ?? '' ) );
		$note        = $this->post_note();
		$prix        = (array) ( $_POST['prix'] ?? [] );
		$qtes        = (array) ( $_POST['qte'] ?? [] );
		$tailles     = $this->tailles();
		$nb          = 0;
		foreach ( self::MODELES as $modele => $_m ) {
			$pu = isset( $prix[ $modele ] ) && $prix[ $modele ] !== '' ? round( (float) str_replace( ',', '.', wp_unslash( $prix[ $modele ] ) ), 2 ) : null;
			foreach ( $tailles as $taille ) {
				$q = (int) ( $qtes[ $modele ][ $taille ] ?? 0 );
				if ( $q <= 0 || $q > 999 ) continue;
				$wpdb->insert( $this->t_lots(), [
					'modele' => $modele, 'taille' => $taille, 'quantite' => $q, 'date_achat' => $date,
					'prix_unitaire' => $pu, 'fournisseur' => $fournisseur, 'note' => mb_substr( $note, 0, 255 ),
					'created_at' => current_time( 'mysql' ), 'created_by' => get_current_user_id(),
				] );
				$lot_id = (int) $wpdb->insert_id;
				if ( ! $lot_id || ! $this->ajouter_mouvement( 'achat', $modele, $taille, $q, [ 'lot_id' => $lot_id, 'note' => $fournisseur ] ) ) $this->retour( 'err_bdd' );
				$nb += $q;
			}
		}
		$this->retour( $nb ? 'ok_commande' : 'err_rien', [ 'n' => $nb ] );
	}

	public function handle_supprimer_lot(): void {
		$this->verifier( 'supprimer_lot' );
		global $wpdb;
		$id = (int) ( $_POST['lot_id'] ?? 0 );
		if ( ! $id ) $this->retour( 'err_saisie' );
		$wpdb->delete( $this->t_mvt(), [ 'lot_id' => $id, 'type' => 'achat' ] );
		$wpdb->delete( $this->t_lots(), [ 'id' => $id ] );
		$this->retour( 'ok_supprime' );
	}

	/** Inventaire : on saisit le nombre réellement compté, l'écart devient un ajustement. */
	public function handle_inventaire(): void {
		$this->verifier( 'inventaire' );
		$comptes = (array) ( $_POST['compte'] ?? [] );
		$stock   = $this->stock_par_ref();
		$n       = 0;
		foreach ( self::MODELES as $modele => $_m ) {
			foreach ( $this->tailles() as $taille ) {
				$brut = $comptes[ $modele ][ $taille ] ?? '';
				if ( $brut === '' || ! is_numeric( $brut ) ) continue;
				$ecart = (int) $brut - (int) ( $stock[ $modele ][ $taille ]['stock'] ?? 0 );
				if ( $ecart === 0 || (int) $brut < 0 ) continue;
				if ( $this->ajouter_mouvement( 'inventaire', $modele, $taille, abs( $ecart ), [ 'sens' => $ecart > 0 ? 1 : -1, 'note' => $this->post_note() ] ) ) $n++;
			}
		}
		$this->retour( 'ok_inventaire', [ 'n' => $n ] );
	}

	/** Sortie du stock d'une pièce inutilisable (trouvée abîmée dans la réserve). */
	public function handle_reforme(): void {
		$this->verifier( 'reforme' );
		$modele = $this->post_modele();
		$taille = $this->post_taille();
		$qte    = (int) ( $_POST['quantite'] ?? 0 );
		if ( $modele === '' || $taille === '' || $qte < 1 || $qte > 99 ) $this->retour( 'err_saisie' );
		$ok = $this->ajouter_mouvement( 'reforme', $modele, $taille, $qte, [ 'note' => $this->post_note() ] );
		$this->retour( $ok ? 'ok_reforme' : 'err_bdd' );
	}

	/** Correction d'une erreur de saisie : le mouvement disparaît du journal, le stock et les dotations suivent. */
	public function handle_supprimer_mvt(): void {
		$this->verifier( 'supprimer_mvt' );
		global $wpdb;
		$id  = (int) ( $_POST['mvt_id'] ?? 0 );
		$mvt = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t_mvt()} WHERE id = %d", $id ) ) : null;
		if ( ! $mvt ) $this->retour( 'err_saisie' );
		// Une réception se supprime par son lot (onglet Achats), pour ne pas laisser un lot sans mouvement.
		if ( $mvt->type === 'achat' && $mvt->lot_id ) $this->retour( 'err_lot' );
		$wpdb->delete( $this->t_mvt(), [ 'id' => $id ] );
		$this->retour( 'ok_supprime' );
	}

	public function handle_reglages(): void {
		$this->verifier( 'reglages' );
		update_option( 'sp_tenue_reglages', [
			'tailles'      => implode( ',', self::tailles_depuis_texte( sanitize_text_field( wp_unslash( $_POST['tailles'] ?? '' ) ) ) ),
			'seuil_alerte' => max( 0, min( 50, (int) ( $_POST['seuil_alerte'] ?? 1 ) ) ),
		], false );
		$this->retour( 'ok_reglages' );
	}

	// ══════════════════════════════════════════════════════════════
	// PAGE D'ADMINISTRATION
	// ══════════════════════════════════════════════════════════════

	/** Bascule Doboks / Tenues, affichée en haut de la page par les deux modules. */
	public static function selecteur( string $actif ): void {
		$liens = [
			'doboks' => [ '🥋 Doboks (taekwondo)', admin_url( 'admin.php?page=' . SP_Cal_Dobok::PAGE ) ],
			'tenues' => [ '👕 Tenues (renforcement musculaire)', self::url() ],
		];
		echo '<p class="spd-objets" style="margin:10px 0 14px;display:flex;gap:8px;flex-wrap:wrap;">';
		foreach ( $liens as $k => [ $libelle, $url ] ) {
			printf( '<a href="%s" class="button%s">%s</a>', esc_url( $url ), $k === $actif ? ' button-primary' : '', esc_html( $libelle ) );
		}
		echo '</p>';
	}

	/** Appelée par SP_Cal_Dobok::render_page() quand ?objet=tenues. */
	public function render_page(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );

		$onglets = [ 'besoin' => 'Besoin de commande', 'stock' => 'Stock', 'adherents' => 'Adhérents', 'achats' => 'Achats', 'journal' => 'Journal', 'reglages' => 'Réglages' ];
		$tab     = sanitize_key( $_GET['tab'] ?? 'besoin' );
		if ( ! isset( $onglets[ $tab ] ) ) $tab = 'besoin';

		echo '<div class="wrap sp-cal-wrap spd">';
		echo '<h1>Doboks &amp; tenues</h1>';
		self::selecteur( 'tenues' );

		if ( ! $this->tables_ok() ) {
			delete_option( 'sp_tenue_schema_version' );   // force une nouvelle tentative au prochain chargement
			echo '<div class="notice notice-error"><p>Les tables de gestion des tenues n\'existent pas encore en base. Rechargez la page ; si le message persiste, les droits MySQL de l\'hébergement empêchent leur création (voir le journal d\'erreurs PHP).</p></div></div>';
			return;
		}

		$this->render_message();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $onglets as $k => $l ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( [ 'tab' => $k ] ) ), $k === $tab ? ' nav-tab-active' : '', esc_html( $l ) );
		}
		echo '</nav><div class="spd-body">';

		switch ( $tab ) {
			case 'stock':     $this->render_stock();     break;
			case 'adherents': $this->render_adherents(); break;
			case 'achats':    $this->render_achats();    break;
			case 'journal':   $this->render_journal();   break;
			case 'reglages':  $this->render_reglages();  break;
			default:          $this->render_besoin();
		}
		echo '</div></div>';
	}

	private function render_message(): void {
		$msg = sanitize_key( $_GET['msg'] ?? '' );
		if ( ! $msg ) return;
		$n       = (int) ( $_GET['n'] ?? 0 );
		$negatif = ' Attention : le stock calculé de cette taille est maintenant négatif — un inventaire permettra de le recaler.';
		$textes  = [
			'ok_remise'         => [ 'success', sprintf( '%d pièce(s) remise(s).', max( 1, $n ) ) ],
			'ok_remise_negatif' => [ 'warning', sprintf( '%d pièce(s) remise(s).', max( 1, $n ) ) . $negatif ],
			'ok_restitution'    => [ 'success', 'Restitution enregistrée.' ],
			'ok_perte'          => [ 'success', 'Pièce déclarée non rendue / perdue.' ],
			'ok_commande'       => [ 'success', sprintf( 'Commande reçue : %d pièce(s) ajoutée(s) au stock.', $n ) ],
			'ok_supprime'       => [ 'success', 'Suppression effectuée.' ],
			'ok_inventaire'     => [ 'success', sprintf( 'Inventaire enregistré : %d ajustement(s).', $n ) ],
			'ok_reforme'        => [ 'success', 'Sortie du stock enregistrée.' ],
			'ok_reglages'       => [ 'success', 'Réglages enregistrés.' ],
			'err_saisie'        => [ 'error',   'Saisie incomplète ou invalide.' ],
			'err_rien'          => [ 'error',   'Rien à enregistrer : aucune pièce ni quantité choisie.' ],
			'err_detenu'        => [ 'error',   'Cette pièce n\'est pas enregistrée chez cet adhérent.' ],
			'err_lot'           => [ 'error',   'Une réception de commande se supprime depuis l\'onglet Achats.' ],
			'err_bdd'           => [ 'error',   'Erreur d\'enregistrement en base.' ],
		];
		if ( ! isset( $textes[ $msg ] ) ) return;
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $textes[ $msg ][0] ), esc_html( $textes[ $msg ][1] ) );
	}

	/** Champs cachés : action, nonce, et contexte de retour. */
	private function form_open( string $action, array $retour = [], string $class = '', string $confirmer = '' ): void {
		printf( '<form method="post" action="%s" class="%s"%s>', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( $class ),
			$confirmer !== '' ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirmer ) ) . ')"' : '' );
		printf( '<input type="hidden" name="action" value="sp_tenue_%s">', esc_attr( $action ) );
		wp_nonce_field( 'sp_tenue_' . $action );
		foreach ( $retour as $k => $v ) printf( '<input type="hidden" name="r_%s" value="%s">', esc_attr( $k ), esc_attr( $v ) );
	}

	private function select_modele( string $name, string $piece, string $selected, bool $avec_vide = false ): string {
		$html = '<select name="' . esc_attr( $name ) . '">';
		if ( $avec_vide ) $html .= '<option value="">— ne pas remettre —</option>';
		foreach ( self::MODELES as $k => $m ) {
			if ( $piece !== '' && $m['piece'] !== $piece ) continue;
			$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $k, $selected, false ), esc_html( $m['label'] ) );
		}
		return $html . '</select>';
	}

	private function select_taille( string $name, string $selected ): string {
		$html = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $this->tailles() as $t ) $html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $t ), selected( $t, $selected, false ), esc_html( $t ) );
		return $html . '</select>';
	}

	private static function select_liste( string $name, array $liste ): string {
		$html = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $liste as $k => $l ) $html .= sprintf( '<option value="%s">%s</option>', esc_attr( $k ), esc_html( $l ) );
		return $html . '</select>';
	}

	/** Grille modèle × taille : $cellule( modèle, taille ) renvoie le contenu HTML de la case. */
	private function grille( callable $cellule, bool $totaux = false ): void {
		$tailles = $this->tailles();
		echo '<div class="spd-scroll"><table class="widefat striped spd-grille" style="max-width:900px;"><thead><tr><th></th>';
		foreach ( $tailles as $t ) echo '<th>' . esc_html( $t ) . '</th>';
		if ( $totaux ) echo '<th class="spd-tot">Total</th>';
		echo '</tr></thead><tbody>';
		foreach ( self::MODELES as $modele => $m ) {
			echo '<tr><th>' . esc_html( $m['label'] ) . '</th>';
			$total = 0;
			foreach ( $tailles as $t ) {
				[ $html, $valeur ] = $cellule( $modele, $t );
				$total += (int) $valeur;
				echo $html; // cellule <td> construite et échappée par l'appelant
			}
			if ( $totaux ) echo '<td class="spd-tot"><strong>' . (int) $total . '</strong></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	// ── Onglet Besoin de commande ────────────────────────────────────────
	private function render_besoin(): void {
		$tailles = $this->tailles();
		$renfo   = array_values( array_filter( $this->eleves_actifs(), [ __CLASS__, 'est_renfo' ] ) );
		$calc    = self::besoin( $renfo, $this->detenus(), $tailles );
		$stock   = $this->stock_par_ref();
		$besoin  = $calc['besoin'];

		$a_commander = [];
		$total_cmd   = 0;
		foreach ( $besoin as $modele => $par_taille ) {
			foreach ( $par_taille as $taille => $n ) {
				$manque = $n - max( 0, (int) ( $stock[ $modele ][ $taille ]['stock'] ?? 0 ) );
				if ( $manque > 0 ) { $a_commander[ $modele ][ $taille ] = $manque; $total_cmd += $manque; }
			}
		}

		printf( '<p class="spd-compte"><strong>%d</strong> adhérent(s) actif(s) au renforcement musculaire — <strong>%d</strong> déjà équipé(s) d\'un haut et d\'un bas.</p>', count( $renfo ), (int) $calc['equipes'] );
		echo '<p class="description" style="max-width:900px;">Pour chaque adhérent du renforcement qui n\'a pas encore sa tenue : un haut et un bas, dans la coupe de son sexe et à la taille notée sur sa fiche (« T-shirt » pour le haut, « Pantalon » pour le bas). '
			. 'Les adhérents du taekwondo, qui ont aussi accès au renforcement, ne sont pas comptés : ajoutez leurs pièces à la main dans la commande si besoin.</p>';

		echo '<h2>À commander</h2>';
		if ( ! $total_cmd ) {
			echo '<p>' . ( $besoin ? 'Le stock couvre tout le besoin : rien à commander.' : 'Aucun besoin calculé pour le moment.' ) . '</p>';
		} else {
			$this->grille( static function ( $modele, $taille ) use ( $besoin, $stock, $a_commander ) {
				$n   = (int) ( $besoin[ $modele ][ $taille ] ?? 0 );
				$s   = (int) ( $stock[ $modele ][ $taille ]['stock'] ?? 0 );
				$cmd = (int) ( $a_commander[ $modele ][ $taille ] ?? 0 );
				if ( ! $n ) return [ '<td class="spd-vide">—</td>', 0 ];
				return [ '<td' . ( $cmd ? '' : ' class="spd-vide"' ) . '><strong>' . $cmd . '</strong><small>besoin ' . $n . ( $s > 0 ? ' · stock ' . $s : '' ) . '</small></td>', $cmd ];
			}, true );
			printf( '<p style="margin-top:12px;"><a class="button button-primary" href="%s">Commande reçue : saisir la réception avec ces quantités</a></p>', esc_url( self::url( [ 'tab' => 'achats', 'prefill' => 'besoin' ] ) ) );
		}

		if ( $calc['incomplets'] ) {
			printf( '<h2>Fiches à compléter (%d)</h2>', count( $calc['incomplets'] ) );
			echo '<p class="description">Ces adhérents ne sont pas comptés, ou seulement en partie, tant que l\'information manque sur leur fiche. Une taille doit contenir l\'une des tailles de la liste (' . esc_html( implode( ', ', $tailles ) ) . ').</p>';
			echo '<table class="widefat striped" style="max-width:900px;"><thead><tr><th>Adhérent</th><th>Il manque</th><th>Noté sur la fiche</th><th></th></tr></thead><tbody>';
			foreach ( $calc['incomplets'] as $l ) {
				$el = $l['eleve'];
				printf( '<tr><td><strong>%s</strong></td><td>%s</td><td>T-shirt : %s · Pantalon : %s</td><td><a class="button button-small" href="%s">Compléter la fiche</a></td></tr>',
					esc_html( trim( $el->nom . ' ' . $el->prenom ) ),
					esc_html( implode( ', ', $l['manque'] ) ),
					esc_html( (string) ( $el->taille_tshirt ?? '' ) !== '' ? $el->taille_tshirt : '—' ),
					esc_html( (string) ( $el->taille_pantalon ?? '' ) !== '' ? $el->taille_pantalon : '—' ),
					esc_url( admin_url( 'admin.php?page=sp-cal-eleves&sp_edit_eleve=' . (int) $el->id ) ) );
			}
			echo '</tbody></table>';
		}
	}

	/** Quantités « à commander » de l'onglet Besoin, pour préremplir la grille de réception. */
	private function quantites_a_commander(): array {
		$renfo  = array_values( array_filter( $this->eleves_actifs(), [ __CLASS__, 'est_renfo' ] ) );
		$besoin = self::besoin( $renfo, $this->detenus(), $this->tailles() )['besoin'];
		$stock  = $this->stock_par_ref();
		$out    = [];
		foreach ( $besoin as $modele => $par_taille ) {
			foreach ( $par_taille as $taille => $n ) {
				$manque = $n - max( 0, (int) ( $stock[ $modele ][ $taille ]['stock'] ?? 0 ) );
				if ( $manque > 0 ) $out[ $modele ][ $taille ] = $manque;
			}
		}
		return $out;
	}

	// ── Onglet Stock ─────────────────────────────────────────────────────
	private function render_stock(): void {
		$stock = $this->stock_par_ref();
		$seuil = $this->reglages()['seuil_alerte'];

		echo '<p class="description">En grand : les pièces disponibles dans la réserve du club. En petit : celles qui sont chez les adhérents.'
			. ' <span class="spd-leg spd-bas">stock bas</span><span class="spd-leg spd-neg">stock négatif</span></p>';
		$this->grille( static function ( $modele, $taille ) use ( $stock, $seuil ) {
			$s = (int) ( $stock[ $modele ][ $taille ]['stock'] ?? 0 );
			$p = (int) ( $stock[ $modele ][ $taille ]['pret'] ?? 0 );
			if ( ! isset( $stock[ $modele ][ $taille ] ) ) return [ '<td class="spd-vide">—</td>', 0 ];
			$classe = $s < 0 ? 'spd-neg' : ( $s <= $seuil ? 'spd-bas' : '' );
			return [ '<td class="' . $classe . '"><strong>' . $s . '</strong><small>' . $p . ' prêté' . ( $p > 1 ? 's' : '' ) . '</small></td>', $s ];
		}, true );

		echo '<details style="margin-top:18px;"><summary><strong>Faire un inventaire</strong> (corriger le stock d\'après un comptage)</summary>';
		echo '<p class="description">Saisissez le nombre de pièces réellement comptées dans la réserve, seulement pour les cases à corriger. L\'écart est enregistré comme un ajustement dans le journal.</p>';
		$this->form_open( 'inventaire', [ 'tab' => 'stock' ], 'spd-inv' );
		$this->grille( static function ( $modele, $taille ) use ( $stock ) {
			$s = (int) ( $stock[ $modele ][ $taille ]['stock'] ?? 0 );
			return [ '<td><input type="number" min="0" max="999" name="compte[' . esc_attr( $modele ) . '][' . esc_attr( $taille ) . ']" placeholder="' . $s . '"></td>', 0 ];
		} );
		echo '<p><input type="text" name="note" class="spd-note" placeholder="Note (facultatif)"> <button type="submit" class="button button-primary">Enregistrer l\'inventaire</button></p></form></details>';

		echo '<details style="margin-top:12px;"><summary><strong>Sortir une pièce du stock</strong> (abîmée, inutilisable)</summary>';
		$this->form_open( 'reforme', [ 'tab' => 'stock' ], 'spd-inline' );
		echo $this->select_modele( 'modele', '', '' ) . $this->select_taille( 'taille', '' )
			. ' <input type="number" name="quantite" min="1" max="99" value="1" style="width:60px;"> <input type="text" name="note" class="spd-note" placeholder="Raison (facultatif)">'
			. ' <button type="submit" class="button">Sortir du stock</button></form></details>';
	}

	// ── Onglet Adhérents ─────────────────────────────────────────────────
	private function render_adherents(): void {
		$vues = [ 'renfo' => 'Renforcement musculaire', 'detenteurs' => 'Ceux qui ont une tenue', 'tous' => 'Tous les adhérents' ];
		$vue  = sanitize_key( $_GET['vue'] ?? 'renfo' );
		if ( ! isset( $vues[ $vue ] ) ) $vue = 'renfo';
		$q    = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$open = (int) ( $_GET['open'] ?? 0 );

		$detenus = $this->detenus();
		$tailles = $this->tailles();
		$eleves  = $this->eleves_actifs();
		// Un ancien adhérent (inactif) qui n'a pas rendu sa tenue doit rester visible.
		$inactifs = array_diff( array_keys( $detenus ), array_map( static function ( $e ) { return (int) $e->id; }, $eleves ) );
		foreach ( $inactifs as $id ) { if ( $el = $this->eleve( (int) $id ) ) $eleves[] = $el; }

		$liste = array_values( array_filter( $eleves, function ( $el ) use ( $vue, $q, $detenus ) {
			$a = ! empty( $detenus[ (int) $el->id ] );
			if ( $vue === 'renfo' && ! self::est_renfo( $el ) && ! $a ) return false;
			if ( $vue === 'detenteurs' && ! $a ) return false;
			if ( $vue !== 'tous' && empty( $el->actif ) && ! $a ) return false;
			return $q === '' || mb_stripos( $el->nom . ' ' . $el->prenom, $q ) !== false;
		} ) );

		echo '<form method="get" class="spd-filtres"><input type="hidden" name="page" value="' . esc_attr( SP_Cal_Dobok::PAGE ) . '"><input type="hidden" name="objet" value="' . esc_attr( self::OBJET ) . '"><input type="hidden" name="tab" value="adherents">';
		echo '<label>Afficher <select name="vue" onchange="this.form.submit()">';
		foreach ( $vues as $k => $l ) printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $k, $vue, false ), esc_html( $l ) );
		echo '</select></label><label>Nom <input type="search" name="q" value="' . esc_attr( $q ) . '"></label><button class="button">Filtrer</button></form>';
		printf( '<p class="spd-compte">%d adhérent(s). Changement de taille : enregistrez la restitution de l\'ancienne pièce, puis la remise de la nouvelle.</p>', count( $liste ) );

		if ( ! $liste ) { echo '<p>Aucun adhérent à afficher.</p>'; return; }

		$retour = [ 'tab' => 'adherents', 'vue' => $vue, 'q' => $q ];
		echo '<table class="widefat striped spd-adh"><thead><tr><th style="width:24%;">Adhérent</th><th style="width:18%;">Tailles de la fiche</th><th>Tenue chez lui</th><th style="width:22%;">À faire</th><th style="width:90px;"></th></tr></thead><tbody>';
		foreach ( $liste as $el ) {
			$id    = (int) $el->id;
			$chez  = $detenus[ $id ] ?? [];
			$a     = [];
			foreach ( $chez as $d ) $a[ self::MODELES[ $d['modele'] ]['piece'] ?? '' ] = true;
			$renfo = self::est_renfo( $el );

			echo '<tr id="el-' . $id . '"><td><strong><a href="' . esc_url( admin_url( 'admin.php?page=sp-cal-fiche-eleve&eleve_id=' . $id ) ) . '">' . esc_html( trim( $el->nom . ' ' . $el->prenom ) ) . '</a></strong>';
			echo '<div class="spd-meta">' . esc_html( $renfo ? 'Renforcement musculaire' : 'Taekwondo' ) . ( empty( $el->actif ) ? ' · <strong>inactif</strong>' : '' ) . '</div></td>';
			echo '<td class="spd-taille">Haut : ' . esc_html( (string) ( $el->taille_tshirt ?? '' ) !== '' ? $el->taille_tshirt : '—' ) . '<br>Bas : ' . esc_html( (string) ( $el->taille_pantalon ?? '' ) !== '' ? $el->taille_pantalon : '—' ) . '</td><td>';
			if ( ! $chez ) echo '<span class="spd-vide">rien</span>';
			foreach ( $chez as $d ) echo '<span class="spd-dobok">' . esc_html( self::label( $d['modele'] ) . ' ' . $d['taille'] . ( $d['nb'] > 1 ? ' ×' . $d['nb'] : '' ) ) . '</span> ';
			echo '</td><td>';
			if ( empty( $el->actif ) && $chez ) echo '<span class="spd-alerte spd-warn">Parti : tenue à récupérer</span>';
			elseif ( $renfo ) foreach ( self::PIECES as $piece => $lib ) { if ( empty( $a[ $piece ] ) ) echo '<span class="spd-alerte spd-info">' . esc_html( $lib ) . ' à remettre</span>'; }
			echo '</td><td><button type="button" class="button button-small spd-toggle" data-cible="gerer-' . $id . '">Gérer</button></td></tr>';

			echo '<tr class="spd-gerer" id="gerer-' . $id . '"' . ( $open === $id ? '' : ' hidden' ) . '><td colspan="5"><div class="spd-panneau">';

			// Remise
			echo '<div class="spd-col"><h4>Remettre</h4>';
			$this->form_open( 'remise', $retour, 'spd-remise' );
			echo '<input type="hidden" name="eleve_id" value="' . $id . '">';
			foreach ( self::PIECES as $piece => $lib ) {
				[ $modele, $taille ] = self::attendu( $el, $piece, $tailles );
				// Proposé seulement si la pièce manque ; sinon « ne pas remettre » est présélectionné.
				$propose = empty( $a[ $piece ] ) ? ( $modele !== '' ? $modele : $piece . '_h' ) : '';
				echo '<label>' . esc_html( $lib ) . ' ' . $this->select_modele( $piece . '_modele', $piece, $propose, true ) . ' ' . $this->select_taille( $piece . '_taille', $taille ) . '</label>';
			}
			echo '<input type="text" name="note" class="spd-note" placeholder="Note (facultatif)"><button type="submit" class="button button-primary">Enregistrer la remise</button></form></div>';

			// Restitution / perte
			echo '<div class="spd-col"><h4>Reprendre</h4>';
			if ( ! $chez ) echo '<p class="spd-vide">Aucune pièce chez cet adhérent.</p>';
			foreach ( $chez as $d ) {
				echo '<div class="spd-detenu"><strong>' . esc_html( self::label( $d['modele'] ) . ' ' . $d['taille'] ) . '</strong><br>';
				$this->form_open( 'restitution', $retour, 'spd-inline' );
				echo '<input type="hidden" name="eleve_id" value="' . $id . '"><input type="hidden" name="modele" value="' . esc_attr( $d['modele'] ) . '"><input type="hidden" name="taille" value="' . esc_attr( $d['taille'] ) . '">';
				echo self::select_liste( 'motif', self::MOTIFS ) . self::select_liste( 'etat', self::ETATS ) . '<button type="submit" class="button">Rendu</button></form>';
				$this->form_open( 'perte', $retour, 'spd-inline', 'Déclarer cette pièce non rendue / perdue ?' );
				echo '<input type="hidden" name="eleve_id" value="' . $id . '"><input type="hidden" name="modele" value="' . esc_attr( $d['modele'] ) . '"><input type="hidden" name="taille" value="' . esc_attr( $d['taille'] ) . '">';
				echo '<button type="submit" class="button-link" style="color:#b91c1c;">Non rendu / perdu</button></form></div>';
			}
			echo '</div></div></td></tr>';
		}
		echo '</tbody></table>';
		?>
		<script>
		document.querySelectorAll('.spd-toggle').forEach(function (b) {
			b.addEventListener('click', function () {
				var l = document.getElementById(b.getAttribute('data-cible'));
				if (l) l.hidden = !l.hidden;
			});
		});
		</script>
		<?php
	}

	// ── Onglet Achats ────────────────────────────────────────────────────
	private function render_achats(): void {
		global $wpdb;
		$prefill = sanitize_key( $_GET['prefill'] ?? '' ) === 'besoin' ? $this->quantites_a_commander() : [];

		echo '<h2>Réception d\'une commande</h2>';
		echo '<p class="description">Saisissez le nombre de pièces reçues par modèle et par taille : elles entrent dans le stock.'
			. ( $prefill ? ' <strong>Quantités préremplies d\'après le besoin de commande : corrigez-les selon ce qui est réellement arrivé.</strong>' : '' ) . '</p>';
		$this->form_open( 'commande', [ 'tab' => 'achats' ] );
		$this->grille( static function ( $modele, $taille ) use ( $prefill ) {
			$v = (int) ( $prefill[ $modele ][ $taille ] ?? 0 );
			return [ '<td class="spd-inv"><input type="number" min="0" max="999" name="qte[' . esc_attr( $modele ) . '][' . esc_attr( $taille ) . ']" value="' . ( $v ?: '' ) . '"></td>', 0 ];
		} );
		echo '<div class="spd-form-lot" style="margin-top:12px;">';
		echo '<label>Date de réception <input type="date" name="date_achat" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '"></label>';
		echo '<label>Fournisseur <input type="text" name="fournisseur" maxlength="150"></label>';
		foreach ( self::MODELES as $k => $m ) echo '<label>Prix unitaire ' . esc_html( mb_strtolower( $m['label'] ) ) . ' (€) <input type="number" step="0.01" min="0" name="prix[' . esc_attr( $k ) . ']" style="width:110px;"></label>';
		echo '<label>Note <input type="text" name="note" maxlength="255"></label>';
		echo '<button type="submit" class="button button-primary">Ajouter au stock</button></div></form>';

		$lots = (array) $wpdb->get_results( "SELECT * FROM {$this->t_lots()} ORDER BY date_achat DESC, id DESC LIMIT 300" );
		echo '<h2 style="margin-top:26px;">Commandes reçues</h2>';
		if ( ! $lots ) { echo '<p>Aucune réception enregistrée.</p>'; return; }
		$total = 0.0;
		echo '<table class="widefat striped" style="max-width:1000px;"><thead><tr><th>Date</th><th>Pièce</th><th>Taille</th><th>Quantité</th><th>Prix unitaire</th><th>Fournisseur</th><th>Note</th><th></th></tr></thead><tbody>';
		foreach ( $lots as $l ) {
			if ( $l->prix_unitaire !== null ) $total += (float) $l->prix_unitaire * (int) $l->quantite;
			echo '<tr><td>' . esc_html( $l->date_achat ? mysql2date( 'd/m/Y', $l->date_achat ) : '—' ) . '</td><td>' . esc_html( self::label( $l->modele ) ) . '</td><td>' . esc_html( $l->taille ) . '</td><td>' . (int) $l->quantite . '</td>';
			echo '<td>' . ( $l->prix_unitaire !== null ? esc_html( number_format( (float) $l->prix_unitaire, 2, ',', ' ' ) ) . ' €' : '—' ) . '</td><td>' . esc_html( $l->fournisseur ) . '</td><td>' . esc_html( $l->note ) . '</td><td>';
			$this->form_open( 'supprimer_lot', [ 'tab' => 'achats' ], '', 'Supprimer cette réception ? Les pièces sortent du stock.' );
			echo '<input type="hidden" name="lot_id" value="' . (int) $l->id . '"><button type="submit" class="button-link" style="color:#b91c1c;">Supprimer</button></form></td></tr>';
		}
		echo '</tbody></table>';
		if ( $total > 0 ) echo '<p><strong>Total des achats dont le prix est renseigné : ' . esc_html( number_format( $total, 2, ',', ' ' ) ) . ' €</strong></p>';
	}

	// ── Onglet Journal ───────────────────────────────────────────────────
	private function render_journal(): void {
		global $wpdb;
		$tel  = $this->table_eleves();
		$rows = (array) $wpdb->get_results( "SELECT m.*, e.nom, e.prenom FROM {$this->t_mvt()} m LEFT JOIN $tel e ON e.id = m.eleve_id ORDER BY m.id DESC LIMIT 300" );
		if ( ! $rows ) { echo '<p>Aucun mouvement pour le moment.</p>'; return; }
		echo '<p class="description">Les 300 derniers mouvements. Supprimer une ligne corrige une erreur de saisie : le stock et les dotations sont recalculés.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Mouvement</th><th>Pièce</th><th>Stock</th><th>Adhérent</th><th>Détail</th><th>Par</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$detail = array_filter( [ self::ETATS[ $r->etat ] ?? '', self::MOTIFS[ $r->motif ] ?? '', $r->note ] );
			$par    = $r->created_by ? get_userdata( (int) $r->created_by ) : null;
			$s      = (int) $r->mvt_stock;
			echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y H:i', $r->created_at ) ) . '</td><td>' . esc_html( self::TYPES_MVT[ $r->type ] ?? $r->type ) . '</td>';
			echo '<td>' . esc_html( self::label( $r->modele ) . ' ' . $r->taille ) . '</td>';
			echo '<td>' . ( $s === 0 ? '—' : '<span class="' . ( $s > 0 ? 'spd-plus' : 'spd-moins' ) . '">' . ( $s > 0 ? '+' : '' ) . $s . '</span>' ) . '</td>';
			echo '<td>' . esc_html( $r->eleve_id ? trim( $r->nom . ' ' . $r->prenom ) : '' ) . '</td><td>' . esc_html( implode( ' · ', $detail ) ) . '</td><td>' . esc_html( $par ? $par->display_name : '' ) . '</td><td>';
			if ( ! ( $r->type === 'achat' && $r->lot_id ) ) {
				$this->form_open( 'supprimer_mvt', [ 'tab' => 'journal' ], '', 'Supprimer ce mouvement ?' );
				echo '<input type="hidden" name="mvt_id" value="' . (int) $r->id . '"><button type="submit" class="button-link" style="color:#b91c1c;">Supprimer</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ── Onglet Réglages ──────────────────────────────────────────────────
	private function render_reglages(): void {
		$r = $this->reglages();
		$this->form_open( 'reglages', [ 'tab' => 'reglages' ] );
		echo '<table class="form-table" style="max-width:760px;">';
		echo '<tr><th>Tailles</th><td><input type="text" name="tailles" class="regular-text" value="' . esc_attr( implode( ', ', $r['tailles'] ) ) . '">'
			. '<p class="description">De la plus petite à la plus grande, séparées par des virgules. Les mêmes pour le haut et le bas.</p></td></tr>';
		echo '<tr><th>Alerte de stock bas</th><td><input type="number" name="seuil_alerte" min="0" max="50" value="' . (int) $r['seuil_alerte'] . '" style="width:70px;"> pièce(s) ou moins'
			. '<p class="description">Les cases du stock passent en orange à ce niveau.</p></td></tr>';
		echo '</table><p><button type="submit" class="button button-primary">Enregistrer</button></p></form>';
	}
}

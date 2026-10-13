<?php
/**
 * Tenues du renforcement musculaire prêtées par le club (12/10/2026).
 * Étape 1, côté bureau : besoin de commande, stock, réception des commandes, remise, restitution, journal.
 * Étape 2, côté adhérent : bloc « Ma tenue » sur sa fiche (lien ?token=) — demande de changement de
 * taille, de remplacement ou de restitution ; la pièce souhaitée est aussitôt RÉSERVÉE si le stock
 * le permet, sinon la demande attend et sera réservée à l'arrivée d'une commande ou d'un retour.
 * Le bureau traite les demandes dans l'onglet « Demandes » (échange enregistré en une seule fois).
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

	const SCHEMA_VERSION = '2';
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

	// Nature d'une demande d'adhérent → motif de la restitution qui la solde.
	const NATURES = [
		'taille'       => [ 'label' => 'Changement de taille', 'motif' => 'taille' ],
		'remplacement' => [ 'label' => 'Remplacement (abîmé)', 'motif' => 'remplacement' ],
		'restitution'  => [ 'label' => 'Restitution',          'motif' => 'retour' ],
	];

	const STATUTS = [
		'ouverte'    => 'Réservée / à traiter',
		'en_attente' => 'En attente de stock',
		'terminee'   => 'Terminée',
		'refusee'    => 'Refusée',
		'annulee'    => 'Annulée',
	];

	public static function get_instance( $db = null ): self {
		if ( self::$instance === null ) self::$instance = new self( $db );
		return self::$instance;
	}

	private function __construct( $db = null ) {
		$this->db = $db;

		SP_Cal_Schema::enregistrer( 'Tenues renfo', [ $this, 'maybe_create_tables' ] );

		foreach ( [ 'remise', 'restitution', 'perte', 'commande', 'supprimer_lot', 'inventaire', 'reforme', 'supprimer_mvt', 'reglages',
		            'dem_terminer', 'dem_refuser', 'dem_annuler', 'inviter', 'exemple' ] as $a ) {
			add_action( 'admin_post_sp_tenue_' . $a, [ $this, 'handle_' . $a ] );
		}

		// Fiche adhérent (lien ?token=) : bloc « Ma tenue », après « Mes doboks » (priorité 20), et
		// traitement de ses formulaires avant l'affichage de la fiche (comme les doboks).
		add_action( 'template_redirect',               [ $this, 'handle_front' ], 5 );
		add_action( 'sp_cal_fiche_membre_apres_grade', [ $this, 'render_bloc_adherent' ], 20 );
	}

	// ══════════════════════════════════════════════════════════════
	// SCHÉMA
	// ══════════════════════════════════════════════════════════════
	private function t_lots(): string { global $wpdb; return $wpdb->prefix . 'sp_cal_tenue_lots'; }
	private function t_mvt(): string  { global $wpdb; return $wpdb->prefix . 'sp_cal_tenue_mouvements'; }
	private function t_dem(): string  { global $wpdb; return $wpdb->prefix . 'sp_cal_tenue_demandes'; }

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

	/** Les tables existent dans la version attendue : le bloc de la fiche adhérent peut s'afficher. */
	private function front_pret(): bool {
		return get_option( 'sp_tenue_schema_version' ) === self::SCHEMA_VERSION;
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

		dbDelta( "CREATE TABLE {$this->t_dem()} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			eleve_id mediumint(9) NOT NULL DEFAULT 0,
			piece varchar(10) NOT NULL DEFAULT '',
			nature varchar(20) NOT NULL DEFAULT '',
			rendu_modele varchar(20) NOT NULL DEFAULT '',
			rendu_taille varchar(8) NOT NULL DEFAULT '',
			souhait_modele varchar(20) NOT NULL DEFAULT '',
			souhait_taille varchar(8) NOT NULL DEFAULT '',
			statut varchar(20) NOT NULL DEFAULT 'ouverte',
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

	/**
	 * Statut d'une nouvelle demande : une restitution est toujours « à traiter » ; une pièce
	 * souhaitée est réservée s'il en reste une de disponible, sinon la demande attend le stock.
	 */
	public static function statut_initial( string $nature, int $disponible ): string {
		if ( $nature === 'restitution' ) return 'ouverte';
		return $disponible > 0 ? 'ouverte' : 'en_attente';
	}

	/** « Changement de taille : Haut femme M → Haut femme L ». */
	public static function libelle_demande( $d ): string {
		$nature = self::NATURES[ $d->nature ]['label'] ?? (string) $d->nature;
		$rendu  = $d->rendu_modele !== ''   ? self::label( $d->rendu_modele ) . ' ' . $d->rendu_taille : '';
		$voulu  = $d->souhait_modele !== '' ? self::label( $d->souhait_modele ) . ' ' . $d->souhait_taille : '';
		if ( $d->nature === 'restitution' ) return $nature . ' : ' . $rendu;
		if ( $d->nature === 'remplacement' ) return $nature . ' : ' . $rendu;
		return $nature . ' : ' . $rendu . ' → ' . $voulu;
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
			'demandes_on'  => (int) ( $r['demandes_on'] ?? 1 ),
			'mail_bureau'  => (int) ( $r['mail_bureau'] ?? 1 ),
			'email_bureau' => (string) ( $r['email_bureau'] ?? '' ),
			'mail_famille' => (int) ( $r['mail_famille'] ?? 1 ),
		];
	}

	private function tailles(): array { return $this->reglages()['tailles']; }

	/** Tailles réglées, pour les autres modules (liste du formulaire d'adhésion au renforcement). */
	public static function tailles_reglees(): array {
		$r = get_option( 'sp_tenue_reglages', [] );
		return self::tailles_depuis_texte( is_array( $r ) ? ( $r['tailles'] ?? '' ) : '' );
	}

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
		if ( ! $ok ) $this->retour( 'err_bdd', [ 'open' => $id ] );

		// Échange en une seule saisie : la même pièce, dans une autre taille, remise aussitôt.
		$echange = $this->post_taille( 'echange_taille' );
		if ( $echange !== '' && $echange !== $taille ) {
			if ( ! $this->ajouter_mouvement( 'remise', $modele, $echange, 1, [ 'eleve_id' => $id, 'note' => $this->post_note() ] ) ) $this->retour( 'err_bdd', [ 'open' => $id ] );
			$negatif = $this->stock_de( $modele, $echange ) < 0;
			$this->promouvoir_attentes();
			$this->retour( $negatif ? 'ok_echange_negatif' : 'ok_echange', [ 'open' => $id ] );
		}
		$this->promouvoir_attentes();
		$this->retour( 'ok_restitution', [ 'open' => $id ] );
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
		$reservees = $nb ? $this->promouvoir_attentes() : 0;
		$this->retour( $nb ? 'ok_commande' : 'err_rien', [ 'n' => $nb, 'n2' => $reservees ] );
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
		$this->promouvoir_attentes();
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
		$this->promouvoir_attentes();
		$this->retour( 'ok_supprime' );
	}

	public function handle_reglages(): void {
		$this->verifier( 'reglages' );
		update_option( 'sp_tenue_reglages', [
			'tailles'      => implode( ',', self::tailles_depuis_texte( sanitize_text_field( wp_unslash( $_POST['tailles'] ?? '' ) ) ) ),
			'seuil_alerte' => max( 0, min( 50, (int) ( $_POST['seuil_alerte'] ?? 1 ) ) ),
			'demandes_on'  => empty( $_POST['demandes_on'] ) ? 0 : 1,
			'mail_bureau'  => empty( $_POST['mail_bureau'] ) ? 0 : 1,
			'email_bureau' => sanitize_email( wp_unslash( $_POST['email_bureau'] ?? '' ) ),
			'mail_famille' => empty( $_POST['mail_famille'] ) ? 0 : 1,
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
		$nb = self::get_instance()->nb_demandes_a_traiter();
		if ( $nb ) $liens['tenues'][0] .= ' — ' . $nb . ' demande' . ( $nb > 1 ? 's' : '' );
		echo '<p class="spd-objets" style="margin:10px 0 14px;display:flex;gap:8px;flex-wrap:wrap;">';
		foreach ( $liens as $k => [ $libelle, $url ] ) {
			printf( '<a href="%s" class="button%s">%s</a>', esc_url( $url ), $k === $actif ? ' button-primary' : '', esc_html( $libelle ) );
		}
		echo '</p>';
	}

	/** Appelée par SP_Cal_Dobok::render_page() quand ?objet=tenues. */
	public function render_page(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );

		$nb_dem  = $this->tables_ok() ? $this->nb_demandes_a_traiter() : 0;
		$onglets = [ 'besoin' => 'Besoin de commande', 'stock' => 'Stock', 'demandes' => 'Demandes' . ( $nb_dem ? " ($nb_dem)" : '' ), 'adherents' => 'Adhérents', 'achats' => 'Achats', 'journal' => 'Journal', 'reglages' => 'Réglages' ];
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
			case 'demandes':  $this->render_demandes();  break;
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
		$n2      = (int) ( $_GET['n2'] ?? 0 );
		$negatif = ' Attention : le stock calculé de cette taille est maintenant négatif — un inventaire permettra de le recaler.';
		$textes  = [
			'ok_remise'         => [ 'success', sprintf( '%d pièce(s) remise(s).', max( 1, $n ) ) ],
			'ok_remise_negatif' => [ 'warning', sprintf( '%d pièce(s) remise(s).', max( 1, $n ) ) . $negatif ],
			'ok_restitution'    => [ 'success', 'Restitution enregistrée.' ],
			'ok_perte'          => [ 'success', 'Pièce déclarée non rendue / perdue.' ],
			'ok_commande'       => [ 'success', sprintf( 'Commande reçue : %d pièce(s) ajoutée(s) au stock.', $n ) . ( $n2 ? sprintf( ' %d demande(s) en attente maintenant réservée(s).', $n2 ) : '' ) ],
			'ok_echange'        => [ 'success', 'Échange enregistré (restitution + remise).' ],
			'ok_echange_negatif' => [ 'warning', 'Échange enregistré.' . $negatif ],
			'ok_dem_terminee'   => [ 'success', 'Demande terminée : mouvements enregistrés.' ],
			'ok_dem_terminee_negatif' => [ 'warning', 'Demande terminée.' . $negatif ],
			'ok_dem_annulee'    => [ 'success', 'Demande close : l\'adhérent garde sa pièce actuelle.' ],
			'ok_dem_refusee'    => [ 'success', 'Demande refusée (adhérent prévenu par mail si activé).' ],
			'err_dem'           => [ 'error',   'Cette demande n\'existe pas ou a déjà été traitée.' ],
			'ok_exemple'        => [ 'success', 'Exemple envoyé : regardez votre boîte de réception (et les indésirables).' ],
			'err_exemple'       => [ 'error',   'L\'exemple n\'a pas pu être envoyé : adresse invalide, ou refus du service d\'envoi.' ],
			'ok_invites'        => [ $n ? 'success' : 'warning', sprintf( '%d mail(s) envoyé(s) pour demander les tailles.', $n ) . ( $n2 ? sprintf( ' %d adhérent(s) sans adresse email : à contacter autrement.', $n2 ) : '' ) ],
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
		$stock   = $this->stock_promettable();
		$besoin  = $this->avec_demandes_en_attente( $calc['besoin'] );

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
			. 'Les adhérents du taekwondo, qui ont aussi accès au renforcement, ne sont pas comptés : ajoutez leurs pièces à la main dans la commande si besoin. '
			. 'Les demandes d\'adhérents en attente de stock sont ajoutées au besoin ; les pièces déjà réservées ne comptent plus dans le stock.</p>';

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

			// Invitation par mail : chacun complète ses tailles lui-même, sur sa fiche personnelle.
			[ $a_inviter, $sans_email ] = $this->adherents_a_inviter();
			echo '<h2 style="margin-top:22px;">Leur demander leurs tailles par mail</h2>';
			echo '<p class="description" style="max-width:900px;">Chaque adhérent dont une taille manque reçoit un mail avec son lien personnel : il choisit ses tailles (XS → XXL) dans la rubrique « Ma tenue » de sa fiche, et le besoin de commande se met à jour tout seul. Le sexe, lui, reste à compléter par le bureau sur la fiche.</p>';
			$derniere = get_option( 'sp_tenue_invitation', [] );
			if ( ! empty( $derniere['date'] ) ) echo '<p>Dernier envoi : <strong>' . esc_html( mysql2date( 'd/m/Y à H:i', $derniere['date'] ) ) . '</strong> — ' . (int) ( $derniere['n'] ?? 0 ) . ' mail(s).</p>';
			if ( ! $a_inviter ) {
				echo '<p>' . ( $sans_email ? 'Aucun des adhérents concernés n\'a d\'adresse email : contactez-les autrement.' : 'Personne à relancer : seules des informations à compléter par le bureau manquent.' ) . '</p>';
			} else {
				$this->form_open( 'inviter', [ 'tab' => 'besoin' ], '', sprintf( 'Envoyer %d mail(s) maintenant ?', count( $a_inviter ) ) );
				echo '<button type="submit" class="button button-primary">📧 Envoyer la demande à ' . count( $a_inviter ) . ' adhérent(s)</button>';
				if ( $sans_email ) echo ' <span class="spd-meta">' . count( $sans_email ) . ' sans adresse email : ' . esc_html( implode( ', ', array_map( static function ( $e ) { return trim( $e->nom . ' ' . $e->prenom ); }, $sans_email ) ) ) . '</span>';
				echo '</form>';

				// Exemple : le mail du premier adhérent de la liste, tel qu'il le recevra, mais adressé au bureau.
				$premier = $a_inviter[0];
				$this->form_open( 'exemple', [ 'tab' => 'besoin' ], 'spd-inline' );
				echo '<label for="spt-exemple-email">Recevoir un exemple (le mail de ' . esc_html( $premier->prenom ) . ') à l\'adresse :</label> ';
				echo '<input type="email" id="spt-exemple-email" name="email_test" class="regular-text" required value="' . esc_attr( wp_get_current_user()->user_email ) . '"> ';
				echo '<button type="submit" class="button">M\'envoyer un exemple</button></form>';

				echo '<details style="margin-top:10px;"><summary>Voir le texte du mail</summary><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px;max-width:760px;">'
					. esc_html( self::texte_invitation( 'Prénom NOM', [ 'haut', 'bas' ], 'https://…/fiche-membre/?token=…' )[1] ) . '</pre></details>';
			}
		}
	}

	/**
	 * Adhérents actifs du renforcement dont une taille (haut ou bas) manque et qui n'ont pas déjà
	 * cette pièce : [ à inviter (avec email), sans email ]. Chaque élève reçoit ->pieces_manquantes.
	 */
	private function adherents_a_inviter(): array {
		$tailles = $this->tailles();
		$detenus = $this->detenus();
		$avec = [];
		$sans = [];
		foreach ( $this->eleves_actifs() as $el ) {
			if ( ! self::est_renfo( $el ) ) continue;
			$a = [];
			foreach ( $detenus[ (int) $el->id ] ?? [] as $d ) $a[ self::MODELES[ $d['modele'] ]['piece'] ?? '' ] = true;
			$manque = [];
			foreach ( array_keys( self::PIECES ) as $piece ) {
				if ( empty( $a[ $piece ] ) && self::attendu( $el, $piece, $tailles )[1] === '' ) $manque[] = $piece;
			}
			if ( ! $manque ) continue;
			$el->pieces_manquantes = $manque;
			$dest = ( $el->email_parent ?? '' ) ?: ( $el->email ?? '' );
			if ( $dest && is_email( $dest ) ) $avec[] = $el; else $sans[] = $el;
		}
		return [ $avec, $sans ];
	}

	/** Sujet et corps du mail qui demande ses tailles à un adhérent. @return array{0:string,1:string} */
	public static function texte_invitation( string $qui, array $pieces, string $lien ): array {
		// Le mail s'adresse directement à l'adhérent (formulation choisie par le club, 13/10/2026).
		$quoi = count( $pieces ) > 1 ? 'vos tailles de haut et de bas' : ( ( $pieces[0] ?? '' ) === 'haut' ? 'votre taille de haut' : 'votre taille de bas' );
		$corps = "Bonjour,\n\n"
			. "Le club prépare la commande des tenues de renforcement musculaire (un haut et un bas), prêtées à chaque adhérent.\n\n"
			. "Pour préparer la vôtre, $qui, il nous manque $quoi.\n\n"
			. "Merci de l'indiquer sur votre fiche personnelle, rubrique « Ma tenue de renforcement musculaire » — cela prend quelques secondes :\n"
			. $lien . "\n\n"
			. "Les tailles vont de XS à XXL. Sans cette information, nous ne pourrons pas commander votre tenue.\n\n"
			. "Ce lien est personnel : merci de ne pas le partager.\n\n"
			. 'Sportivement,';
		return [ 'Tenue de renforcement musculaire : vos tailles', $corps ];
	}

	/** Lien personnel de l'adhérent vers « Ma tenue » : celui qu'il a déjà, créé seulement s'il n'en a jamais eu. */
	private function lien_ma_tenue( object $el ): string {
		$token = (string) ( $el->token ?? '' );
		if ( $token === '' ) {
			global $wpdb;
			$token = bin2hex( random_bytes( 32 ) );
			$wpdb->update( $this->table_eleves(), [ 'token' => $token ], [ 'id' => (int) $el->id ] );
			$el->token = $token;
		}
		return $this->fiche_url( $token ) . '#spt-front';
	}

	/**
	 * Exemple pour le bureau : le mail du premier adhérent à relancer, avec son nom et son vrai lien,
	 * envoyé à l'adresse saisie (jamais à l'adhérent). L'objet porte la mention « EXEMPLE ».
	 */
	public function handle_exemple(): void {
		$this->verifier( 'exemple' );
		$dest = sanitize_email( wp_unslash( $_POST['email_test'] ?? '' ) );
		[ $a_inviter ] = $this->adherents_a_inviter();
		if ( ! $dest || ! is_email( $dest ) || ! $a_inviter ) $this->retour( 'err_exemple' );
		$el = $a_inviter[0];
		[ $sujet, $corps ] = self::texte_invitation( $el->prenom . ' ' . mb_strtoupper( $el->nom ), $el->pieces_manquantes, $this->lien_ma_tenue( $el ) );
		$club = get_option( 'blogname', 'Club' );
		$ok   = wp_mail( $dest, '[' . $club . '] EXEMPLE — ' . $sujet, $corps . "\n\n-- \n" . $club, [ 'Content-Type: text/plain; charset=UTF-8' ] );
		$this->retour( $ok ? 'ok_exemple' : 'err_exemple' );
	}

	/** Envoie la demande de tailles aux adhérents concernés (bouton de l'onglet Besoin de commande). */
	public function handle_inviter(): void {
		$this->verifier( 'inviter' );
		global $wpdb;
		[ $a_inviter, $sans_email ] = $this->adherents_a_inviter();
		$n = 0;
		foreach ( $a_inviter as $el ) {
			[ $sujet, $corps ] = self::texte_invitation( $el->prenom . ' ' . mb_strtoupper( $el->nom ), $el->pieces_manquantes, $this->lien_ma_tenue( $el ) );
			$this->envoyer( ( $el->email_parent ?? '' ) ?: $el->email, $sujet, $corps );
			$n++;
		}
		if ( $n ) update_option( 'sp_tenue_invitation', [ 'date' => current_time( 'mysql' ), 'n' => $n ], false );
		$this->retour( 'ok_invites', [ 'n' => $n, 'n2' => count( $sans_email ) ] );
	}

	/** Quantités « à commander » de l'onglet Besoin, pour préremplir la grille de réception. */
	private function quantites_a_commander(): array {
		$renfo  = array_values( array_filter( $this->eleves_actifs(), [ __CLASS__, 'est_renfo' ] ) );
		$besoin = $this->avec_demandes_en_attente( self::besoin( $renfo, $this->detenus(), $this->tailles() )['besoin'] );
		$stock  = $this->stock_promettable();
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
		$stock   = $this->stock_par_ref();
		$seuil   = $this->reglages()['seuil_alerte'];
		$reserve = $this->reserve_par_ref();

		echo '<p class="description">En grand : les pièces dans la réserve du club. En petit : celles qui sont chez les adhérents, et celles de la réserve déjà réservées pour une demande.'
			. ' <span class="spd-leg spd-bas">stock bas</span><span class="spd-leg spd-neg">stock négatif</span></p>';
		$this->grille( static function ( $modele, $taille ) use ( $stock, $seuil, $reserve ) {
			$s = (int) ( $stock[ $modele ][ $taille ]['stock'] ?? 0 );
			$p = (int) ( $stock[ $modele ][ $taille ]['pret'] ?? 0 );
			$r = (int) ( $reserve[ $modele ][ $taille ]['reserve'] ?? 0 );
			if ( ! isset( $stock[ $modele ][ $taille ] ) ) return [ '<td class="spd-vide">—</td>', 0 ];
			$classe = $s < 0 ? 'spd-neg' : ( $s <= $seuil ? 'spd-bas' : '' );
			return [ '<td class="' . $classe . '"><strong>' . $s . '</strong><small>' . $p . ' prêté' . ( $p > 1 ? 's' : '' ) . ( $r ? ' · ' . $r . ' réservé' . ( $r > 1 ? 's' : '' ) : '' ) . '</small></td>', $s ];
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
		printf( '<p class="spd-compte">%d adhérent(s). Changement de taille : dans « Reprendre », choisissez la taille à remettre en échange avant de cliquer sur « Rendu ».</p>', count( $liste ) );

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
				echo self::select_liste( 'motif', self::MOTIFS ) . self::select_liste( 'etat', self::ETATS );
				echo '<select name="echange_taille" title="Pour un échange : la taille remise à la place"><option value="">sans échange</option>';
				foreach ( $tailles as $t ) { if ( $t !== $d['taille'] ) echo '<option value="' . esc_attr( $t ) . '">remettre ' . esc_html( $t ) . '</option>'; }
				echo '</select><button type="submit" class="button">Rendu</button></form>';
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
		echo '<tr><th>Demandes des adhérents</th><td><label><input type="checkbox" name="demandes_on" value="1"' . checked( $r['demandes_on'], 1, false ) . '> Permettre aux adhérents de demander un changement de taille, un remplacement ou une restitution depuis leur fiche</label></td></tr>';
		echo '<tr><th>Mails</th><td><label><input type="checkbox" name="mail_bureau" value="1"' . checked( $r['mail_bureau'], 1, false ) . '> Prévenir le bureau à chaque demande</label><br>'
			. '<input type="email" name="email_bureau" class="regular-text" value="' . esc_attr( $r['email_bureau'] ) . '" placeholder="Adresse du bureau (vide : celle des notifications du club)"><br>'
			. '<label><input type="checkbox" name="mail_famille" value="1"' . checked( $r['mail_famille'], 1, false ) . '> Prévenir l\'adhérent (demande enregistrée, pièce réservée, demande refusée)</label></td></tr>';
		echo '</table><p><button type="submit" class="button button-primary">Enregistrer</button></p></form>';
	}

	// ══════════════════════════════════════════════════════════════
	// DEMANDES DES ADHÉRENTS (étape 2)
	// ══════════════════════════════════════════════════════════════

	/** [modele][taille] => ['reserve' => n, 'attente' => n] : pièces promises et demandes en attente de stock. */
	private function reserve_par_ref(): array {
		global $wpdb;
		$out = [];
		foreach ( (array) $wpdb->get_results( "SELECT souhait_modele AS m, souhait_taille AS t, statut, COUNT(*) AS n FROM {$this->t_dem()} WHERE statut IN ('ouverte','en_attente') AND souhait_modele != '' GROUP BY souhait_modele, souhait_taille, statut" ) as $r ) {
			$out[ $r->m ][ $r->t ][ $r->statut === 'ouverte' ? 'reserve' : 'attente' ] = (int) $r->n;
		}
		return $out;
	}

	/** Stock par référence, diminué des pièces déjà réservées : ce qu'on peut encore promettre. */
	private function stock_promettable(): array {
		$stock = $this->stock_par_ref();
		foreach ( $this->reserve_par_ref() as $modele => $par_taille ) {
			foreach ( $par_taille as $taille => $r ) {
				if ( ! empty( $r['reserve'] ) ) $stock[ $modele ][ $taille ]['stock'] = (int) ( $stock[ $modele ][ $taille ]['stock'] ?? 0 ) - (int) $r['reserve'];
			}
		}
		return $stock;
	}

	/** Ajoute au besoin de commande les pièces des demandes en attente de stock. */
	private function avec_demandes_en_attente( array $besoin ): array {
		foreach ( $this->reserve_par_ref() as $modele => $par_taille ) {
			foreach ( $par_taille as $taille => $r ) {
				if ( ! empty( $r['attente'] ) ) $besoin[ $modele ][ $taille ] = (int) ( $besoin[ $modele ][ $taille ] ?? 0 ) + (int) $r['attente'];
			}
		}
		return $besoin;
	}

	/** Stock effectif moins ce qui est déjà réservé. */
	private function disponible( string $modele, string $taille ): int {
		global $wpdb;
		$reserve = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->t_dem()} WHERE statut = 'ouverte' AND souhait_modele = %s AND souhait_taille = %s", $modele, $taille ) );
		return $this->stock_de( $modele, $taille ) - $reserve;
	}

	private function demande( int $id ): ?object {
		global $wpdb;
		return $id ? ( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->t_dem()} WHERE id = %d", $id ) ) ?: null ) : null;
	}

	/** Demandes non closes d'un adhérent : [ pièce => demande ] (une seule par pièce). */
	private function demandes_ouvertes_de( int $eleve_id ): array {
		global $wpdb;
		$out = [];
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->t_dem()} WHERE eleve_id = %d AND statut IN ('ouverte','en_attente') ORDER BY id", $eleve_id ) ) as $d ) {
			$out[ $d->piece ] = $d;
		}
		return $out;
	}

	/** Demandes à traiter par le bureau (réservées ou restitutions attendues) — pastille du menu et de l'onglet. */
	public function nb_demandes_a_traiter(): int {
		if ( ! $this->front_pret() ) return 0;
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->t_dem()} WHERE statut = 'ouverte'" );
	}

	private function maj_demande( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$data['traite_par'] = get_current_user_id();
		$wpdb->update( $this->t_dem(), $data, [ 'id' => $id ] );
	}

	/**
	 * Les demandes en attente sont réservées, dans l'ordre d'arrivée, dès que leur pièce est
	 * disponible (commande reçue, retour, inventaire, demande close). @return int nombre réservé
	 */
	private function promouvoir_attentes(): int {
		if ( ! $this->front_pret() ) return 0;
		global $wpdb;
		$n = 0;
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$this->t_dem()} WHERE statut = 'en_attente' ORDER BY id" ) as $d ) {
			if ( $d->souhait_modele === '' || $this->disponible( $d->souhait_modele, $d->souhait_taille ) < 1 ) continue;
			$wpdb->update( $this->t_dem(), [ 'statut' => 'ouverte', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => (int) $d->id ] );
			$d->statut = 'ouverte';
			if ( $el = $this->eleve( (int) $d->eleve_id ) ) $this->mail_famille( $el, $d, 'reservee' );
			$n++;
		}
		return $n;
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
			$page = $wpdb->get_row( "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='page' AND post_content LIKE '%sp_cal_fiche_membre%' LIMIT 1" );
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
		$qui = $el->prenom . ' ' . mb_strtoupper( $el->nom );
		if ( $evenement === 'annulee' ) {
			$this->envoyer( $this->email_bureau(), 'Tenue : demande annulée par ' . $qui, $qui . " a annulé sa demande :\n" . self::libelle_demande( $d ) );
			return;
		}
		$corps  = "Nouvelle demande de $qui (tenue du renforcement musculaire) :\n" . self::libelle_demande( $d ) . "\n";
		$corps .= 'Statut : ' . ( self::STATUTS[ $d->statut ] ?? $d->statut ) . "\n";
		if ( $d->note_adherent ) $corps .= 'Précisions : ' . $d->note_adherent . "\n";
		if ( $d->statut === 'en_attente' ) $corps .= "\n⚠ Pas de stock disponible : demande en attente (ajoutée au besoin de commande).\n";
		$corps .= "\nÀ traiter : " . self::url( [ 'tab' => 'demandes' ] );
		$this->envoyer( $this->email_bureau(), ( $d->statut === 'en_attente' ? '⚠ ' : '' ) . 'Tenue : demande de ' . $qui, $corps );
	}

	private function mail_famille( object $el, object $d, string $evenement ): void {
		if ( ! $this->reglages()['mail_famille'] ) return;
		$dest = ( $el->email_parent ?? '' ) ?: ( $el->email ?? '' );
		if ( ! $dest || ! is_email( $dest ) ) return;
		$qui = $el->prenom . ' ' . mb_strtoupper( $el->nom );
		$lib = self::libelle_demande( $d );
		switch ( $evenement ) {
			case 'recue':
				$sujet = 'Demande de tenue enregistrée';
				$corps = "Bonjour,\n\nLa demande pour $qui est bien enregistrée : $lib.\n"
					. ( $d->souhait_modele !== '' ? 'La pièce est réservée : elle vous sera remise au club, en échange de l\'ancienne.' : 'Pensez à rapporter la pièce au club.' );
				break;
			case 'attente':
				$sujet = 'Demande de tenue en attente';
				$corps = "Bonjour,\n\nLa demande pour $qui est enregistrée : $lib.\n"
					. 'Cette taille n\'est pas disponible pour le moment : la demande est en liste d\'attente. En attendant, gardez la pièce actuelle. Vous serez prévenu(e) dès qu\'elle sera réservée.';
				break;
			case 'reservee':
				$sujet = 'Votre tenue est réservée';
				$corps = "Bonjour,\n\nBonne nouvelle : la pièce demandée pour $qui est maintenant réservée ($lib).\nElle vous sera remise au club, en échange de l'ancienne.";
				break;
			case 'refusee':
				$sujet = 'Demande de tenue non retenue';
				$corps = "Bonjour,\n\nLa demande pour $qui ($lib) n'a pas été retenue par le bureau." . ( $d->note_bureau ? "\nMotif : " . $d->note_bureau : '' );
				break;
			default:
				return;
		}
		if ( ! empty( $el->token ) ) $corps .= "\n\nSuivre la demande : " . $this->fiche_url( $el->token );
		$this->envoyer( $dest, $sujet, $corps );
	}

	// ── Fiche de l'adhérent (lien ?token=) ───────────────────────────────
	private function eleve_par_token( string $token ): ?object {
		global $wpdb;
		if ( $token === '' ) return null;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_eleves()} WHERE token = %s", $token ) ) ?: null;
	}

	/** Pièces qu'un adhérent détient, par pièce : [ 'haut' => ['modele','taille','nb'], … ] (la première s'il en a deux). */
	private function pieces_de( int $eleve_id ): array {
		$out = [];
		foreach ( $this->detenus( [ $eleve_id ] )[ $eleve_id ] ?? [] as $d ) {
			$piece = self::MODELES[ $d['modele'] ]['piece'] ?? '';
			if ( $piece !== '' && ! isset( $out[ $piece ] ) ) $out[ $piece ] = $d;
		}
		return $out;
	}

	/**
	 * Formulaires du bloc « Ma tenue ». Le lien personnel (token) fait office d'authentification,
	 * comme pour le reste de la fiche ; le nonce protège contre l'envoi depuis un autre site.
	 */
	public function handle_front(): void {
		if ( is_admin() || empty( $_POST['sp_tenue_front'] ) || ! $this->front_pret() ) return;
		$el = $this->eleve_par_token( sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ) );
		if ( ! $el ) return;

		$retour = function ( string $msg ): void {
			wp_safe_redirect( add_query_arg( 'tenue_msg', $msg, remove_query_arg( 'tenue_msg' ) ) . '#spt-front' );
			exit;
		};

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_spt_nonce'] ?? '' ) ), 'sp_tenue_front_' . $el->id ) ) $retour( 'expire' );

		$action = sanitize_key( $_POST['sp_tenue_front'] );
		$id     = (int) $el->id;

		// Tailles du haut et du bas notées sur la fiche : l'adhérent du renforcement les corrige
		// lui-même (possible même si les demandes sont désactivées). Elles servent au besoin de commande.
		if ( $action === 'tailles' ) {
			if ( ! (int) $el->actif || ! self::est_renfo( $el ) ) $retour( 'ferme' );
			$haut = $this->post_taille( 'taille_haut' );
			$bas  = $this->post_taille( 'taille_bas' );
			if ( $haut === '' || $bas === '' ) $retour( 'tailles_erreur' );
			global $wpdb;
			$wpdb->update( $this->table_eleves(), [ 'taille_tshirt' => $haut, 'taille_pantalon' => $bas ], [ 'id' => $id ] );
			$retour( 'tailles_ok' );
		}

		if ( ! (int) $el->actif || ! $this->reglages()['demandes_on'] ) $retour( 'ferme' );

		if ( $action === 'annuler' ) {
			$d = $this->demande( (int) ( $_POST['dem_id'] ?? 0 ) );
			if ( ! $d || (int) $d->eleve_id !== $id || ! in_array( $d->statut, [ 'ouverte', 'en_attente' ], true ) ) $retour( 'erreur' );
			global $wpdb;
			$wpdb->update( $this->t_dem(), [ 'statut' => 'annulee', 'updated_at' => current_time( 'mysql' ), 'note_bureau' => 'Annulée par l\'adhérent' ], [ 'id' => (int) $d->id ] );
			$this->mail_bureau( $el, $d, 'annulee' );
			$this->promouvoir_attentes();
			$retour( 'annulee' );
		}

		if ( $action !== 'demande' ) $retour( 'erreur' );

		$piece = sanitize_key( $_POST['piece'] ?? '' );
		if ( ! isset( self::PIECES[ $piece ] ) ) $retour( 'erreur' );
		if ( isset( $this->demandes_ouvertes_de( $id )[ $piece ] ) ) $retour( 'deja' );
		if ( empty( $_POST['confirme'] ) ) $retour( 'confirme' );

		$detenue = $this->pieces_de( $id )[ $piece ] ?? null;
		if ( ! $detenue ) $retour( 'erreur' );   // on ne demande que pour une pièce qu'on a

		$choix = sanitize_key( $_POST['choix'] ?? '' );
		$dem   = [
			'piece' => $piece, 'rendu_modele' => $detenue['modele'], 'rendu_taille' => $detenue['taille'],
			'souhait_modele' => '', 'souhait_taille' => '',
			'note_adherent' => mb_substr( sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), 0, 255 ),
		];
		if ( $choix === 'taille' ) {
			$taille = $this->post_taille();
			if ( $taille === '' ) $retour( 'erreur' );
			if ( $taille === $detenue['taille'] ) $retour( 'identique' );
			$dem += [ 'nature' => 'taille' ];
			$dem['souhait_modele'] = $detenue['modele'];
			$dem['souhait_taille'] = $taille;
		} elseif ( $choix === 'abime' ) {
			$dem += [ 'nature' => 'remplacement' ];
			$dem['souhait_modele'] = $detenue['modele'];
			$dem['souhait_taille'] = $detenue['taille'];
		} elseif ( $choix === 'rendre' ) {
			$dem += [ 'nature' => 'restitution' ];
		} else {
			$retour( 'erreur' );
		}

		$dem['statut']     = self::statut_initial( $dem['nature'], $dem['souhait_modele'] !== '' ? $this->disponible( $dem['souhait_modele'], $dem['souhait_taille'] ) : 0 );
		$dem['eleve_id']   = $id;
		$dem['saison']     = $this->saison_courante();
		$dem['created_at'] = current_time( 'mysql' );
		global $wpdb;
		if ( ! $wpdb->insert( $this->t_dem(), $dem ) ) $retour( 'erreur' );
		$d = $this->demande( (int) $wpdb->insert_id );
		if ( ! $d ) $retour( 'erreur' );

		$this->mail_bureau( $el, $d, 'nouvelle' );
		$this->mail_famille( $el, $d, $d->statut === 'en_attente' ? 'attente' : 'recue' );
		$retour( $d->nature === 'restitution' ? 'ok_rendre' : ( $d->statut === 'en_attente' ? 'ok_attente' : 'ok_reservee' ) );
	}

	private function champs_front( object $el ): void {
		echo '<input type="hidden" name="_spt_nonce" value="' . esc_attr( wp_create_nonce( 'sp_tenue_front_' . $el->id ) ) . '">';
	}

	/** Bloc « Ma tenue » de la fiche de l'adhérent (action sp_cal_fiche_membre_apres_grade). */
	public function render_bloc_adherent( $el ): void {
		if ( ! is_object( $el ) || ! $this->front_pret() ) return;

		$id       = (int) $el->id;
		$pieces   = $this->pieces_de( $id );
		$ouvertes = $this->demandes_ouvertes_de( $id );
		$renfo    = self::est_renfo( $el );
		// Adhérent du taekwondo : le bloc n'apparaît que s'il a une tenue du club.
		if ( ! $renfo && ! $pieces && ! $ouvertes ) return;

		$peut    = (int) $el->actif && $this->reglages()['demandes_on'];
		$tailles = $this->tailles();
		$messages = [
			'ok_reservee' => [ 'ok',   'Demande enregistrée : la pièce est réservée. Elle vous sera remise au club, en échange de l\'ancienne.' ],
			'ok_attente'  => [ 'info', 'Demande enregistrée. Cette taille n\'est pas disponible pour le moment : vous êtes en liste d\'attente et serez prévenu(e) par mail. Gardez votre pièce actuelle en attendant.' ],
			'ok_rendre'   => [ 'ok',   'Demande enregistrée : merci de rapporter la pièce au club.' ],
			'annulee'     => [ 'ok',   'Votre demande est annulée.' ],
			'deja'        => [ 'err',  'Une demande est déjà en cours pour cette pièce.' ],
			'confirme'    => [ 'err',  'Cochez « Je confirme ma demande » pour l\'envoyer.' ],
			'identique'   => [ 'err',  'La taille demandée est celle que vous avez déjà : choisissez-en une autre, ou « Elle est abîmée ».' ],
			'ferme'       => [ 'err',  'Les demandes ne sont pas possibles pour le moment : contactez le bureau.' ],
			'expire'      => [ 'err',  'La page a expiré : rechargez-la puis recommencez.' ],
			'erreur'      => [ 'err',  'La demande n\'a pas pu être enregistrée : vérifiez vos choix.' ],
			'tailles_ok'  => [ 'ok',   'Vos tailles sont enregistrées. Merci !' ],
			'tailles_erreur' => [ 'err', 'Choisissez une taille pour le haut et pour le bas.' ],
		];
		$msg = sanitize_key( $_GET['tenue_msg'] ?? '' );
		// Tailles notées sur la fiche, quand elles sont lisibles (sinon l'adhérent est invité à les choisir).
		$t_haut = self::lire_taille( $el->taille_tshirt ?? '', $tailles );
		$t_bas  = self::lire_taille( $el->taille_pantalon ?? '', $tailles );
		$tailles_a_saisir = $renfo && (int) $el->actif && ( $t_haut === '' || $t_bas === '' );
		?>
		<div class="sp-membre-section spt-front" id="spt-front">
			<style>
				.spt-front .spt-msg{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:14px}
				.spt-front .spt-ok{background:#f0fdf4;border:1px solid #86efac;color:#166534}
				.spt-front .spt-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
				.spt-front .spt-err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
				.spt-front .spt-cartes{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:14px}
				.spt-front .spt-carte{border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;background:#fff}
				.spt-front .spt-carte h3{margin:0 0 8px;font-size:16px}
				.spt-front .spt-piece{font-size:15px;font-weight:700}
				.spt-front .spt-vide{font-size:14px;color:#6b7280}
				.spt-front .spt-demande{background:#f9fafb;border-radius:8px;padding:10px 12px;margin-top:10px;font-size:14px}
				.spt-front details{margin-top:10px}
				.spt-front summary{cursor:pointer;font-weight:600;color:#D4000F}
				.spt-front form{margin:8px 0 0}
				.spt-front form label{display:block;margin:6px 0;font-size:14px}
				.spt-front form select,.spt-front form textarea{max-width:100%}
				.spt-front form textarea{width:100%;min-height:54px}
				.spt-front .spt-btn{margin-top:8px;padding:8px 16px;border:0;border-radius:6px;background:#D4000F;color:#fff;font-weight:600;cursor:pointer}
				.spt-front .spt-lien{background:none;border:0;padding:0;color:#b91c1c;text-decoration:underline;cursor:pointer;font-size:13px}
				.spt-front .spt-note{font-size:12px;color:#6b7280;margin-top:12px}
				.spt-front .spt-tailles{display:flex;flex-wrap:wrap;gap:8px 24px;align-items:center;font-size:14px;margin:6px 0}
				.spt-front .spt-tailles b{font-size:16px}
				.spt-front .spt-edit{background:none;border:1px solid #e5e7eb;border-radius:6px;padding:2px 10px;cursor:pointer;font-size:13px;line-height:1.6}
				.spt-front .spt-edit:hover{border-color:#D4000F}
				.spt-front .spt-tailles-form{border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;margin:8px 0 10px;background:#f9fafb}
				.spt-front .spt-tailles-form[hidden]{display:none}
				.spt-front .spt-tailles-form label{display:inline-block;margin:0 18px 6px 0}
				.spt-front .spt-alerte{background:#fff7ed;border:1px solid #fdba74;color:#9a3412;padding:10px 14px;border-radius:8px;margin:8px 0;font-size:14px}
			</style>
			<h2>👕 Ma tenue de renforcement musculaire</h2>

			<?php if ( isset( $messages[ $msg ] ) ) : ?>
				<div class="spt-msg spt-<?php echo esc_attr( $messages[ $msg ][0] ); ?>"><?php echo esc_html( $messages[ $msg ][1] ); ?></div>
			<?php endif; ?>

			<?php if ( ! $pieces ) : ?>
				<p>Votre tenue (un haut et un bas) est prêtée par le club : elle vous sera remise lors d'une distribution.</p>
			<?php else : ?>
				<p>Cette tenue est prêtée par le club : elle est à rendre si vous arrêtez l'activité.</p>
			<?php endif; ?>

			<?php if ( $renfo ) : ?>
				<?php if ( $tailles_a_saisir ) : ?>
					<div class="spt-alerte">Nous avons besoin de vos tailles pour préparer votre tenue : merci de les indiquer ci-dessous.</div>
				<?php endif; ?>
				<div class="spt-tailles">
					<span>Taille du haut : <b><?php echo esc_html( $t_haut !== '' ? $t_haut : '—' ); ?></b></span>
					<span>Taille du bas : <b><?php echo esc_html( $t_bas !== '' ? $t_bas : '—' ); ?></b></span>
					<?php if ( (int) $el->actif ) : ?>
						<button type="button" class="spt-edit" onclick="var f=document.getElementById('spt-tailles-form');f.hidden=!f.hidden;">✏️ Modifier mes tailles</button>
					<?php endif; ?>
				</div>
				<?php if ( (int) $el->actif ) : ?>
				<form method="post" class="spt-tailles-form" id="spt-tailles-form"<?php echo ( $tailles_a_saisir || $msg === 'tailles_erreur' ) ? '' : ' hidden'; ?>>
					<?php $this->champs_front( $el ); ?>
					<input type="hidden" name="sp_tenue_front" value="tailles">
					<?php foreach ( [ 'taille_haut' => [ 'Haut', $t_haut ], 'taille_bas' => [ 'Bas', $t_bas ] ] as $champ => $c ) : ?>
					<label><?php echo esc_html( $c[0] ); ?> :
						<select name="<?php echo esc_attr( $champ ); ?>" required>
							<option value="">— Choisir —</option>
							<?php foreach ( $tailles as $t ) echo '<option value="' . esc_attr( $t ) . '"' . selected( $c[1], $t, false ) . '>' . esc_html( $t ) . '</option>'; ?>
						</select>
					</label>
					<?php endforeach; ?>
					<button type="submit" class="spt-btn">Enregistrer mes tailles</button>
				</form>
				<?php endif; ?>
			<?php endif; ?>

			<div class="spt-cartes">
			<?php foreach ( self::PIECES as $piece => $lib ) :
				$a   = $pieces[ $piece ] ?? null;
				$dem = $ouvertes[ $piece ] ?? null;
				if ( ! $a && ! $dem && ! $renfo ) continue;
			?>
				<div class="spt-carte">
					<h3><?php echo esc_html( $lib ); ?></h3>
					<?php if ( $a ) : ?>
						<div class="spt-piece"><?php echo esc_html( self::label( $a['modele'] ) . ' — taille ' . $a['taille'] ); ?></div>
					<?php else : ?>
						<div class="spt-vide">Pas encore remis.</div>
					<?php endif; ?>

					<?php if ( $dem ) : ?>
						<div class="spt-demande">
							<strong>Demande en cours</strong> (<?php echo esc_html( mysql2date( 'd/m/Y', $dem->created_at ) ); ?>) :
							<?php echo esc_html( self::libelle_demande( $dem ) ); ?><br>
							<?php if ( $dem->nature === 'restitution' ) : ?>
								À rapporter au club.
							<?php elseif ( $dem->statut === 'ouverte' ) : ?>
								✅ Réservé — remis au club, en échange de l'ancienne pièce.
							<?php else : ?>
								⏳ En attente de stock — gardez votre pièce actuelle, vous serez prévenu(e) par mail.
							<?php endif; ?>
							<?php if ( $peut ) : ?>
								<form method="post">
									<?php $this->champs_front( $el ); ?>
									<input type="hidden" name="sp_tenue_front" value="annuler">
									<input type="hidden" name="dem_id" value="<?php echo (int) $dem->id; ?>">
									<button type="submit" class="spt-lien" onclick="return confirm('Annuler cette demande ?');">Annuler ma demande</button>
								</form>
							<?php endif; ?>
						</div>
					<?php elseif ( $a && $peut ) : ?>
						<details<?php echo in_array( $msg, [ 'confirme', 'identique', 'erreur' ], true ) ? ' open' : ''; ?>>
							<summary>Faire une demande</summary>
							<form method="post">
								<?php $this->champs_front( $el ); ?>
								<input type="hidden" name="sp_tenue_front" value="demande">
								<input type="hidden" name="piece" value="<?php echo esc_attr( $piece ); ?>">
								<label><input type="radio" name="choix" value="taille" checked> Changer de taille :
									<select name="taille">
										<?php foreach ( $tailles as $t ) { if ( $t !== $a['taille'] ) echo '<option value="' . esc_attr( $t ) . '">' . esc_html( $t ) . '</option>'; } ?>
									</select>
								</label>
								<label><input type="radio" name="choix" value="abime"> Elle est abîmée : je demande son remplacement</label>
								<label><input type="radio" name="choix" value="rendre"> Je la rends au club (arrêt de l'activité)</label>
								<label>Précisions (facultatif)
									<textarea name="note" maxlength="255"></textarea>
								</label>
								<label><input type="checkbox" name="confirme" value="1"> Je confirme ma demande</label>
								<button type="submit" class="spt-btn">Envoyer la demande</button>
							</form>
						</details>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			</div>
			<p class="spt-note">Une demande à la fois par pièce. Le bureau est prévenu et vous tient informé(e) par mail.</p>
		</div>
		<?php
	}

	// ── Onglet Demandes (bureau) ─────────────────────────────────────────
	private function render_demandes(): void {
		global $wpdb;
		$tel  = $this->table_eleves();
		$rows = (array) $wpdb->get_results( "SELECT d.*, e.nom, e.prenom FROM {$this->t_dem()} d LEFT JOIN $tel e ON e.id = d.eleve_id WHERE d.statut IN ('ouverte','en_attente') ORDER BY d.statut DESC, e.nom, e.prenom, d.id" );
		if ( ! $this->reglages()['demandes_on'] ) echo '<div class="notice notice-warning inline"><p>Les demandes depuis la fiche des adhérents sont désactivées (onglet Réglages).</p></div>';
		if ( ! $rows ) { echo '<p>Aucune demande en cours.</p>'; return; }

		$retour = [ 'tab' => 'demandes' ];
		echo '<p class="description" style="max-width:900px;">« Terminer » enregistre en une fois le retour de l\'ancienne pièce et la remise de la nouvelle, à faire quand l\'adhérent est au club. Une demande en attente de stock sera réservée automatiquement à la réception d\'une commande ou au retour d\'une pièce.</p>';
		echo '<table class="widefat striped"><thead><tr><th style="width:90px;">Date</th><th style="width:20%;">Adhérent</th><th>Demande</th><th style="width:150px;">État</th><th style="width:36%;">Traiter</th></tr></thead><tbody>';
		foreach ( $rows as $d ) {
			$id_el = (int) $d->eleve_id;
			echo '<tr><td>' . esc_html( mysql2date( 'd/m/Y', $d->created_at ) ) . '</td>';
			echo '<td><strong><a href="' . esc_url( admin_url( 'admin.php?page=sp-cal-fiche-eleve&eleve_id=' . $id_el ) ) . '">' . esc_html( trim( $d->nom . ' ' . $d->prenom ) ) . '</a></strong></td>';
			echo '<td>' . esc_html( self::libelle_demande( $d ) ) . ( $d->note_adherent !== '' ? '<div class="spd-meta">« ' . esc_html( $d->note_adherent ) . ' »</div>' : '' ) . '</td>';
			echo '<td>' . ( $d->statut === 'ouverte' ? '<span class="spd-alerte spd-info">' . ( $d->nature === 'restitution' ? 'Retour attendu' : 'Réservée' ) . '</span>' : '<span class="spd-alerte spd-warn">En attente de stock</span>' ) . '</td><td>';

			if ( $d->statut === 'ouverte' ) {
				$this->form_open( 'dem_terminer', $retour, 'spd-inline' );
				echo '<input type="hidden" name="dem_id" value="' . (int) $d->id . '">';
				echo 'Pièce rendue : ' . self::select_liste( 'etat', self::ETATS );
				echo '<button type="submit" class="button button-primary">' . ( $d->nature === 'restitution' ? 'Terminer (pièce rendue)' : 'Terminer (échange fait)' ) . '</button></form>';
			}
			$this->form_open( 'dem_refuser', $retour, 'spd-inline', 'Refuser cette demande ? L\'adhérent sera prévenu par mail.' );
			echo '<input type="hidden" name="dem_id" value="' . (int) $d->id . '"><input type="text" name="note" placeholder="Motif du refus" style="width:170px;"><button type="submit" class="button">Refuser</button></form>';
			$this->form_open( 'dem_annuler', $retour, 'spd-inline', 'Clore cette demande sans suite ?' );
			echo '<input type="hidden" name="dem_id" value="' . (int) $d->id . '"><button type="submit" class="button-link">Clore sans suite</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function post_demande( string $action ): object {
		$this->verifier( $action );
		$d = $this->demande( (int) ( $_POST['dem_id'] ?? 0 ) );
		if ( ! $d || ! in_array( $d->statut, [ 'ouverte', 'en_attente' ], true ) ) $this->retour( 'err_dem' );
		return $d;
	}

	/** Demande réservée traitée au club : retour de l'ancienne pièce et remise de la nouvelle en une fois. */
	public function handle_dem_terminer(): void {
		$d  = $this->post_demande( 'dem_terminer' );
		$id = (int) $d->eleve_id;
		if ( $d->statut !== 'ouverte' ) $this->retour( 'err_dem' );
		$etat = sanitize_key( $_POST['etat'] ?? 'bon' );
		if ( ! isset( self::ETATS[ $etat ] ) ) $etat = 'bon';
		$note = 'Demande n° ' . (int) $d->id;

		if ( $d->rendu_modele !== '' ) {
			if ( ! $this->detient( $id, $d->rendu_modele, $d->rendu_taille ) ) $this->retour( 'err_detenu' );
			$motif = self::NATURES[ $d->nature ]['motif'] ?? 'retour';
			if ( ! $this->ajouter_mouvement( 'restitution', $d->rendu_modele, $d->rendu_taille, 1, [ 'eleve_id' => $id, 'etat' => $etat, 'motif' => $motif, 'note' => $note ] ) ) $this->retour( 'err_bdd' );
		}
		$negatif = false;
		if ( $d->souhait_modele !== '' ) {
			if ( ! $this->ajouter_mouvement( 'remise', $d->souhait_modele, $d->souhait_taille, 1, [ 'eleve_id' => $id, 'note' => $note ] ) ) $this->retour( 'err_bdd' );
			$negatif = $this->stock_de( $d->souhait_modele, $d->souhait_taille ) < 0;
		}
		$this->maj_demande( (int) $d->id, [ 'statut' => 'terminee' ] );
		$this->promouvoir_attentes();
		$this->retour( $negatif ? 'ok_dem_terminee_negatif' : 'ok_dem_terminee' );
	}

	public function handle_dem_refuser(): void {
		$d = $this->post_demande( 'dem_refuser' );
		$d->note_bureau = mb_substr( $this->post_note(), 0, 255 );
		$this->maj_demande( (int) $d->id, [ 'statut' => 'refusee', 'note_bureau' => $d->note_bureau ] );
		if ( $el = $this->eleve( (int) $d->eleve_id ) ) $this->mail_famille( $el, $d, 'refusee' );
		$this->promouvoir_attentes();
		$this->retour( 'ok_dem_refusee' );
	}

	/** Clôture sans suite (réglé de vive voix, demande faite par erreur…) : aucun mail. */
	public function handle_dem_annuler(): void {
		$d = $this->post_demande( 'dem_annuler' );
		$this->maj_demande( (int) $d->id, [ 'statut' => 'annulee', 'note_bureau' => 'Close par le bureau' ] );
		$this->promouvoir_attentes();
		$this->retour( 'ok_dem_annulee' );
	}
}

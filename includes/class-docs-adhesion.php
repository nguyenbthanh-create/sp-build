<?php
/**
 * Documents d'adhésion protégés (08/10/2026).
 *
 * Les pièces déposées dans le formulaire d'adhésion (class-front-adhesion.php::handle_upload())
 * sont rangées dans wp-content/uploads/sp-adhesions-docs/<type>/<année>/. Jusqu'ici, seule la
 * liste du dossier était bloquée (Options -Indexes) : chaque fichier restait téléchargeable par
 * n'importe qui connaissant son adresse — dont 67 certificats médicaux de mineurs (constaté le
 * 08/10/2026 sur la prod, noms de fichiers souvent devinables : « certificat… », « IMG_2026… »).
 *
 * Désormais :
 *  - les dossiers sensibles (DOSSIERS) refusent tout accès direct par le web (.htaccess écrit par
 *    proteger_dossiers(), à chaque chargement de l'administration tant que la VERSION n'est pas
 *    posée, et après chaque dépôt) ;
 *  - l'administration affiche ces documents par lien() : admin-post.php?action=sp_adh_doc, qui
 *    vérifie la connexion, le droit « gestion des adhésions » et un jeton (nonce), puis envoie le
 *    fichier. Rien ne change pour le bureau (même bouton « 📄 Voir le document ») ;
 *  - les adresses enregistrées en base ne changent pas (aucun fichier déplacé).
 * Les photos (sous-dossier « photos », cartes de membre et fiche adhérent) ne sont pas concernées.
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Docs_Adhesion {

	/** Sous-dossiers de sp-adhesions-docs/ fermés au web. */
	const DOSSIERS = [ 'certificats-medicaux', 'attestations-rc', 'decharges', 'bons-caf' ];
	const ACTION   = 'sp_adh_doc';
	const VERSION  = '1';
	const OPTION   = 'sp_cal_docs_adhesion_proteges';

	/** Dossier racine des documents (chemin disque). */
	public static function base(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'sp-adhesions-docs';
	}

	/**
	 * Chemin relatif « type/année/fichier » d'un document protégé, à partir de son adresse
	 * enregistrée ; '' si l'adresse n'est pas celle d'un document protégé.
	 */
	public static function chemin_relatif( string $url ): string {
		$pos = strpos( $url, '/sp-adhesions-docs/' );
		if ( $pos === false ) return '';
		$rel = rawurldecode( (string) wp_parse_url( substr( $url, $pos + strlen( '/sp-adhesions-docs/' ) ), PHP_URL_PATH ) );
		if ( ! preg_match( '#^([a-z-]+)/(\d{4})/([^/\\\\]+)$#', $rel, $m ) ) return '';
		if ( ! in_array( $m[1], self::DOSSIERS, true ) || $m[3] === '.htaccess' || strpos( $m[3], '..' ) !== false ) return '';
		return $rel;
	}

	/**
	 * Adresse à afficher dans l'administration pour un document : passage par sp_build pour un
	 * document protégé, adresse d'origine sinon (photos, fichiers de la médiathèque, vide).
	 */
	public static function lien( string $url ): string {
		$rel = self::chemin_relatif( $url );
		if ( $rel === '' ) return $url;
		return wp_nonce_url( add_query_arg( [ 'action' => self::ACTION, 'f' => rawurlencode( $rel ) ], admin_url( 'admin-post.php' ) ), self::ACTION . '|' . $rel );
	}

	/** Envoie un document protégé au membre du bureau connecté (admin-post.php?action=sp_adh_doc). */
	public static function servir(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.', 403 );
		// Noms passés par sanitize_file_name() au dépôt (wp_handle_upload) : pas d'espace ni de %.
		$rel = sanitize_text_field( wp_unslash( $_GET['f'] ?? '' ) );
		if ( self::chemin_relatif( '/sp-adhesions-docs/' . $rel ) !== $rel ) wp_die( 'Document introuvable.', 404 );
		check_admin_referer( self::ACTION . '|' . $rel );

		$base    = realpath( self::base() );
		$fichier = realpath( self::base() . '/' . $rel );
		if ( ! $base || ! $fichier || strpos( $fichier, $base . DIRECTORY_SEPARATOR ) !== 0 || ! is_file( $fichier ) ) {
			wp_die( 'Document introuvable.', 404 );
		}

		$type = wp_check_filetype( $fichier, [ 'pdf' => 'application/pdf', 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png' ] );
		if ( empty( $type['type'] ) ) wp_die( 'Type de document non pris en charge.', 415 );

		nocache_headers();
		header( 'Content-Type: ' . $type['type'] );
		header( 'Content-Length: ' . filesize( $fichier ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( basename( $fichier ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() ) ob_end_clean();
		readfile( $fichier );
		exit;
	}

	/**
	 * Écrit dans chaque dossier sensible un .htaccess qui refuse tout accès direct (Apache 2.4 et
	 * 2.2). Crée le dossier s'il n'existe pas encore, pour que la règle soit en place avant le
	 * premier dépôt.
	 */
	public static function proteger_dossiers(): void {
		$regle = "# sp_build (class-docs-adhesion.php) : documents d'adhésion, accès direct refusé.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
		$ok = true;
		foreach ( self::DOSSIERS as $d ) {
			$dir = self::base() . '/' . $d;
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { $ok = false; continue; }
			$f = $dir . '/.htaccess';
			if ( ! file_exists( $f ) || file_get_contents( $f ) !== $regle ) {
				$ok = ( false !== @file_put_contents( $f, $regle ) ) && $ok;
			}
		}
		if ( $ok ) update_option( self::OPTION, self::VERSION, false );
	}

	/** Une fois par VERSION, au chargement de l'administration. */
	public static function verifier(): void {
		if ( get_option( self::OPTION ) !== self::VERSION ) self::proteger_dossiers();
		// Migration des fiches : un essai par heure au plus tant qu'elle n'a pas abouti.
		if ( get_option( self::OPTION_MIGRATION ) !== self::VERSION_MIGRATION
			&& current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS )
			&& ! get_transient( 'sp_cal_docs_fiches_essai' ) ) {
			set_transient( 'sp_cal_docs_fiches_essai', 1, HOUR_IN_SECONDS );
			self::migrer_fiches();
		}
	}

	// ── Documents ajoutés depuis la fiche élève (08/10/2026, suite) ─────────────────────────
	// Le bouton « 📁 Choisir un fichier » de la fiche élève passe par la médiathèque WordPress,
	// dont les fichiers sont publics (uploads/AAAA/MM/). À l'enregistrement de la fiche, un tel
	// document est recopié dans le dossier protégé de son type, et sa copie publique supprimée
	// de la médiathèque (sauf si le même fichier sert ailleurs sur le site).

	/** Clé du document dans extra_data['documents'] → sous-dossier protégé. */
	const TYPES = [
		'certificat_medical' => 'certificats-medicaux',
		'attestation_rc'     => 'attestations-rc',
		'decharge_honneur'   => 'decharges',
		'bon_caf'            => 'bons-caf',
	];
	const VERSION_MIGRATION = '1';
	const OPTION_MIGRATION  = 'sp_cal_docs_fiches_migres';
	const OPTION_JOURNAL    = 'sp_cal_docs_fiches_journal';

	/**
	 * Met à l'abri un document de fiche élève : renvoie l'adresse protégée (copie dans
	 * sp-adhesions-docs/<type>/<année>/), ou l'adresse d'origine si rien n'est à faire / possible.
	 *
	 * @param string $url   adresse enregistrée sur la fiche
	 * @param string $cle   clé de TYPES
	 * @param array  $info  rempli avec 'action' : 'deja' | 'copie' | 'copie_gardee' | 'ignore' | 'echec'
	 */
	public static function securiser_url( string $url, string $cle, array &$info = [] ): string {
		$info = [ 'action' => 'ignore' ];
		if ( $url === '' || ! isset( self::TYPES[ $cle ] ) ) return $url;
		if ( self::chemin_relatif( $url ) !== '' ) { $info['action'] = 'deja'; return $url; }

		// Seuls les fichiers de la médiathèque du site (uploads/AAAA/MM/fichier).
		$uploads = wp_upload_dir();
		$chemin  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$prefixe = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( $prefixe === '' || strpos( $chemin, $prefixe . '/' ) !== 0 ) return $url;
		$rel = rawurldecode( substr( $chemin, strlen( $prefixe ) + 1 ) );
		if ( ! preg_match( '#^\d{4}/\d{2}/[^/\\\\]+$#', $rel ) || strpos( $rel, '..' ) !== false ) return $url;
		$source = $uploads['basedir'] . '/' . $rel;
		$type   = wp_check_filetype( $source, [ 'pdf' => 'application/pdf', 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png' ] );
		if ( ! is_file( $source ) || empty( $type['type'] ) ) { $info['action'] = 'echec'; return $url; }

		self::proteger_dossiers();
		$dossier = self::base() . '/' . self::TYPES[ $cle ] . '/' . gmdate( 'Y' );
		if ( ! wp_mkdir_p( $dossier ) ) { $info['action'] = 'echec'; return $url; }
		$nom = wp_unique_filename( $dossier, sanitize_file_name( basename( $source ) ) );
		if ( ! @copy( $source, $dossier . '/' . $nom ) ) { $info['action'] = 'echec'; return $url; }

		$nouvelle = trailingslashit( $uploads['baseurl'] ) . 'sp-adhesions-docs/' . self::TYPES[ $cle ] . '/' . gmdate( 'Y' ) . '/' . $nom;

		// Supprimer la copie publique (fichier + vignettes + entrée de la médiathèque) si elle
		// n'est utilisée nulle part ailleurs : contenus, réglages, autres fiches élèves.
		$id = attachment_url_to_postid( $url );
		if ( $id && ! self::utilise_ailleurs( $url, $id ) ) {
			wp_delete_attachment( $id, true );
			$info['action'] = 'copie';
		} else {
			$info['action'] = 'copie_gardee';
		}
		return $nouvelle;
	}

	/** Le fichier de la médiathèque sert-il ailleurs que sur une seule fiche élève ? */
	private static function utilise_ailleurs( string $url, int $id ): bool {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) . '%';
		$posts = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID <> %d AND post_type NOT IN ('revision','attachment') AND post_content LIKE %s", $id, $like ) );
		$meta  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_value LIKE %s", $id, $like ) );
		$tel   = $wpdb->prefix . 'sp_cal_eleves';
		$fiches = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tel WHERE extra_data LIKE %s", $like ) );
		return $posts > 0 || $meta > 0 || $fiches > 1;
	}

	/**
	 * Une fois (VERSION_MIGRATION) : met à l'abri les documents déjà présents sur les fiches
	 * élèves. Journal du résultat dans l'option OPTION_JOURNAL (nombres seulement).
	 */
	public static function migrer_fiches(): void {
		global $wpdb;
		$tel  = $wpdb->prefix . 'sp_cal_eleves';
		$rows = $wpdb->get_results( "SELECT id, extra_data FROM $tel WHERE extra_data LIKE '%\"documents\"%'" );
		$bilan = [ 'fiches' => 0, 'copie' => 0, 'copie_gardee' => 0, 'echec' => 0 ];
		foreach ( (array) $rows as $r ) {
			$extra = json_decode( (string) $r->extra_data, true );
			if ( ! is_array( $extra ) || empty( $extra['documents'] ) || ! is_array( $extra['documents'] ) ) continue;
			$modif = false;
			foreach ( $extra['documents'] as $cle => $url ) {
				$info  = [];
				$neuve = self::securiser_url( (string) $url, (string) $cle, $info );
				if ( isset( $bilan[ $info['action'] ] ) ) $bilan[ $info['action'] ]++;
				if ( $neuve !== $url ) { $extra['documents'][ $cle ] = $neuve; $modif = true; }
			}
			if ( $modif ) {
				$wpdb->update( $tel, [ 'extra_data' => wp_json_encode( $extra ) ], [ 'id' => intval( $r->id ) ] );
				$bilan['fiches']++;
			}
		}
		update_option( self::OPTION_JOURNAL, [ 'date' => current_time( 'mysql' ) ] + $bilan, false );
		if ( $bilan['echec'] === 0 ) update_option( self::OPTION_MIGRATION, self::VERSION_MIGRATION, false );
	}
}

add_action( 'admin_post_' . SP_Cal_Docs_Adhesion::ACTION, [ 'SP_Cal_Docs_Adhesion', 'servir' ] );
add_action( 'admin_init', [ 'SP_Cal_Docs_Adhesion', 'verifier' ] );

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
	}
}

add_action( 'admin_post_' . SP_Cal_Docs_Adhesion::ACTION, [ 'SP_Cal_Docs_Adhesion', 'servir' ] );
add_action( 'admin_init', [ 'SP_Cal_Docs_Adhesion', 'verifier' ] );

<?php
/**
 * Dossier par adhérent — fichiers personnels rangés à part et protégés (08/10/2026).
 *
 * Tout ce qui concerne un adhérent (photo, certificat médical, attestation RC, décharge, bon CAF)
 * est rangé dans SON dossier, séparé de la médiathèque publique du site :
 *
 *   wp-content/uploads/sp-adherents/              ← fermé au web (.htaccess « Require all denied »)
 *     <id>-<code>/photo-….jpg                     ← un dossier par fiche élève (code aléatoire)
 *     <id>-<code>/certificat-medical-….pdf
 *     demandes/<code>/…                           ← dépôts d'une demande d'adhésion pas encore acceptée
 *
 * Lecture :
 *  - documents et fichiers des demandes : lien() → admin-post.php?action=sp_adh_doc, réservé aux
 *    comptes ayant le droit « gestion des adhésions » (+ jeton) ;
 *  - photo d'un adhérent : adresse signée url_photo() (?sp_photo=…&s=…), seule forme publique,
 *    pour la carte de membre, la fiche personnelle (lien ?token=), l'application et les vœux
 *    d'anniversaire. Signature par une clé propre au site (option OPTION_CLE, copiée avec la base).
 *
 * Rangement : ranger_fiche() déplace dans le dossier de l'adhérent tout fichier de sa fiche encore
 * ailleurs (dépôt de demande, ancien dossier sp-adhesions-docs/, médiathèque) et met à jour les
 * adresses (fiche + demande d'adhésion d'origine). Appelée à la validation d'une demande, à
 * l'enregistrement d'une fiche, et une fois pour toutes les fiches existantes (migrer()).
 * Copie publique supprimée de la médiathèque si elle ne sert nulle part ailleurs.
 *
 * Historique : version du matin du 08/10 (249c00a / 89d3bdc) = dossiers par TYPE
 * sp-adhesions-docs/<type>/<année>/, encore lus ici (ANCIENS_DOSSIERS) le temps de la migration.
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Docs_Adhesion {

	const BASE       = 'sp-adherents';
	const ANCIENNE   = 'sp-adhesions-docs';
	/** Ancien rangement par type (avant le dossier par adhérent). */
	const DOSSIERS   = [ 'certificats-medicaux', 'attestations-rc', 'decharges', 'bons-caf' ];
	const ACTION     = 'sp_adh_doc';
	const OPTION_CLE = 'sp_cal_photo_cle';

	/** Clé du fichier sur la fiche → préfixe du nom de fichier dans le dossier de l'adhérent. */
	const TYPES = [
		'photo'              => 'photo',
		'certificat_medical' => 'certificat-medical',
		'attestation_rc'     => 'attestation-rc',
		'decharge_honneur'   => 'decharge',
		'bon_caf'            => 'bon-caf',
	];
	/** Sous-dossier du formulaire (class-front-adhesion.php) → clé ci-dessus. */
	const SOUS_DOSSIERS = [
		'photos'               => 'photo',
		'certificats-medicaux' => 'certificat_medical',
		'attestations-rc'      => 'attestation_rc',
		'decharges'            => 'decharge_honneur',
		'bons-caf'             => 'bon_caf',
	];
	/** Colonnes de sp_adhesions_pending → clé. */
	const COLONNES_DEMANDE = [
		'photo_url'              => 'photo',
		'doc_certificat_medical' => 'certificat_medical',
		'doc_attestation_rc'     => 'attestation_rc',
		'doc_decharge_honneur'   => 'decharge_honneur',
		'doc_bon_caf'            => 'bon_caf',
	];
	const MIMES = [ 'pdf' => 'application/pdf', 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif' ];

	const VERSION           = '2';
	const OPTION            = 'sp_cal_docs_adhesion_proteges';
	const VERSION_MIGRATION = '2';
	const OPTION_MIGRATION  = 'sp_cal_docs_fiches_migres';
	const OPTION_JOURNAL    = 'sp_cal_docs_fiches_journal';

	/** @var string|null code du dossier de la demande en cours (un par envoi du formulaire) */
	private static ?string $demande = null;

	// ══════════════════════════════════════════════════════════════════════
	// CHEMINS ET ADRESSES
	// ══════════════════════════════════════════════════════════════════════

	public static function base(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . self::BASE;
	}

	private static function ancienne_base(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . self::ANCIENNE;
	}

	/** Adresse (non publique) d'un fichier rangé : uploads/sp-adherents/<rel>. */
	public static function url_fichier( string $rel ): string {
		return trailingslashit( wp_upload_dir()['baseurl'] ) . self::BASE . '/' . $rel;
	}

	/** Adresse publique signée de la photo d'un adhérent. */
	public static function url_photo( string $rel ): string {
		return add_query_arg( [ 'sp_photo' => rawurlencode( $rel ), 's' => self::signature( $rel ) ], home_url( '/' ) );
	}

	private static function signature( string $rel ): string {
		$cle = (string) get_option( self::OPTION_CLE, '' );
		if ( $cle === '' ) {
			$cle = bin2hex( random_bytes( 32 ) );
			update_option( self::OPTION_CLE, $cle, false );
		}
		return substr( hash_hmac( 'sha256', $rel, $cle ), 0, 24 );
	}

	/**
	 * Chemin « <dossier>/<fichier> » dans sp-adherents/ d'une adresse enregistrée (adresse du
	 * fichier ou photo signée) ; '' si ce n'est pas un fichier du dossier adhérent.
	 */
	public static function chemin_adherent( string $url ): string {
		if ( strpos( $url, 'sp_photo=' ) !== false ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			$rel = rawurldecode( (string) ( $q['sp_photo'] ?? '' ) );
		} else {
			$pos = strpos( $url, '/' . self::BASE . '/' );
			if ( $pos === false ) return '';
			$rel = rawurldecode( (string) wp_parse_url( substr( $url, $pos + strlen( self::BASE ) + 2 ), PHP_URL_PATH ) );
		}
		return self::rel_valide( $rel ) ? $rel : '';
	}

	/** « <id>-<code>/<fichier> » ou « demandes/<code>/<fichier> », sans aucun détour possible. */
	private static function rel_valide( string $rel ): bool {
		// a-trier/ : photos orphelines sorties de la médiathèque (class-adherents-a-trier.php).
		return (bool) preg_match( '#^(\d+-[a-z0-9]{6,16}|demandes/[a-z0-9]{6,16}|a-trier)/[A-Za-z0-9._-]+$#', $rel )
			&& strpos( $rel, '..' ) === false && substr( basename( $rel ), 0, 1 ) !== '.';
	}

	/**
	 * Ancien rangement par type : « type/année/fichier » ; '' sinon. (Photos exclues : elles
	 * n'étaient pas protégées.)
	 */
	public static function chemin_relatif( string $url ): string {
		$pos = strpos( $url, '/' . self::ANCIENNE . '/' );
		if ( $pos === false ) return '';
		$rel = rawurldecode( (string) wp_parse_url( substr( $url, $pos + strlen( self::ANCIENNE ) + 2 ), PHP_URL_PATH ) );
		if ( ! preg_match( '#^([a-z-]+)/(\d{4})/([^/\\\\]+)$#', $rel, $m ) ) return '';
		if ( ! in_array( $m[1], self::DOSSIERS, true ) || $m[3] === '.htaccess' || strpos( $m[3], '..' ) !== false ) return '';
		return $rel;
	}

	/**
	 * Adresse à afficher dans l'administration : passage par sp_build (bureau connecté) pour tout
	 * fichier personnel, adresse d'origine sinon (vide, médiathèque, photo signée déjà servable).
	 */
	public static function lien( string $url ): string {
		if ( strpos( $url, 'sp_photo=' ) !== false ) return $url;
		$rel = self::chemin_adherent( $url );
		if ( $rel !== '' ) {
			return wp_nonce_url( add_query_arg( [ 'action' => self::ACTION, 'a' => rawurlencode( $rel ) ], admin_url( 'admin-post.php' ) ), self::ACTION . '|a|' . $rel );
		}
		$rel = self::chemin_relatif( $url );
		if ( $rel === '' ) return $url;
		return wp_nonce_url( add_query_arg( [ 'action' => self::ACTION, 'f' => rawurlencode( $rel ) ], admin_url( 'admin-post.php' ) ), self::ACTION . '|' . $rel );
	}

	// ══════════════════════════════════════════════════════════════════════
	// LECTURE
	// ══════════════════════════════════════════════════════════════════════

	/** admin-post.php?action=sp_adh_doc — bureau connecté seulement. */
	public static function servir(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.', 403 );
		// Noms passés par sanitize_file_name() au dépôt : pas d'espace ni de %.
		if ( isset( $_GET['a'] ) ) {
			$rel = sanitize_text_field( wp_unslash( $_GET['a'] ) );
			if ( ! self::rel_valide( $rel ) ) wp_die( 'Document introuvable.', 404 );
			check_admin_referer( self::ACTION . '|a|' . $rel );
			self::envoyer( self::base(), $rel, false );
		}
		$rel = sanitize_text_field( wp_unslash( $_GET['f'] ?? '' ) );
		if ( self::chemin_relatif( '/' . self::ANCIENNE . '/' . $rel ) !== $rel ) wp_die( 'Document introuvable.', 404 );
		check_admin_referer( self::ACTION . '|' . $rel );
		self::envoyer( self::ancienne_base(), $rel, false );
	}

	/** ?sp_photo=…&s=… — photo d'un adhérent (adresse signée), sur n'importe quelle page. */
	public static function servir_photo(): void {
		if ( ! isset( $_GET['sp_photo'], $_GET['s'] ) ) return;
		$rel = sanitize_text_field( wp_unslash( $_GET['sp_photo'] ) );
		$sig = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		if ( ! self::rel_valide( $rel ) || strpos( basename( $rel ), 'photo-' ) !== 0 || ! hash_equals( self::signature( $rel ), $sig ) ) {
			status_header( 404 ); exit;
		}
		self::envoyer( self::base(), $rel, true );
	}

	private static function envoyer( string $base, string $rel, bool $photo ): void {
		$racine  = realpath( $base );
		$fichier = realpath( $base . '/' . $rel );
		if ( ! $racine || ! $fichier || strpos( $fichier, $racine . DIRECTORY_SEPARATOR ) !== 0 || ! is_file( $fichier ) ) {
			if ( $photo ) { status_header( 404 ); exit; }
			wp_die( 'Document introuvable.', 404 );
		}
		$type = wp_check_filetype( $fichier, self::MIMES );
		if ( empty( $type['type'] ) ) wp_die( 'Type de document non pris en charge.', 415 );

		if ( $photo ) {
			header( 'Cache-Control: private, max-age=86400' );
		} else {
			nocache_headers();
		}
		header( 'Content-Type: ' . $type['type'] );
		header( 'Content-Length: ' . filesize( $fichier ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( basename( $fichier ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		while ( ob_get_level() ) ob_end_clean();
		readfile( $fichier );
		exit;
	}

	// ══════════════════════════════════════════════════════════════════════
	// PROTECTION DES DOSSIERS
	// ══════════════════════════════════════════════════════════════════════

	private static function regle(): string {
		return "# sp_build (class-docs-adhesion.php) : fichiers personnels des adhérents, accès direct refusé.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
	}

	private static function ecrire_regle( string $dir ): bool {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return false;
		$f = $dir . '/.htaccess';
		if ( file_exists( $f ) && file_get_contents( $f ) === self::regle() ) return true;
		return false !== @file_put_contents( $f, self::regle() );
	}

	/** Ferme sp-adherents/ et les anciens dossiers de documents (par type). */
	public static function proteger_dossiers(): void {
		$ok = self::ecrire_regle( self::base() );
		foreach ( self::DOSSIERS as $d ) {
			if ( is_dir( self::ancienne_base() . '/' . $d ) ) $ok = self::ecrire_regle( self::ancienne_base() . '/' . $d ) && $ok;
		}
		if ( $ok ) update_option( self::OPTION, self::VERSION, false );
	}

	/** Au chargement de l'administration. */
	public static function verifier(): void {
		if ( get_option( self::OPTION ) !== self::VERSION ) self::proteger_dossiers();
		// Rangement des fichiers existants : un essai par heure au plus tant qu'il n'a pas abouti.
		if ( get_option( self::OPTION_MIGRATION ) !== self::VERSION_MIGRATION
			&& current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS )
			&& ! get_transient( 'sp_cal_docs_fiches_essai_' . self::VERSION_MIGRATION ) ) {
			set_transient( 'sp_cal_docs_fiches_essai_' . self::VERSION_MIGRATION, 1, HOUR_IN_SECONDS );
			// Interrompu faute de temps (beaucoup de fichiers) : reprise à la page suivante.
			if ( ! self::migrer() ) delete_transient( 'sp_cal_docs_fiches_essai_' . self::VERSION_MIGRATION );
		}
	}

	// ══════════════════════════════════════════════════════════════════════
	// DÉPÔT PAR LE FORMULAIRE D'ADHÉSION
	// ══════════════════════════════════════════════════════════════════════

	/** Sous-dossier (relatif à uploads/) où ranger un dépôt du formulaire en cours. */
	public static function dossier_demande(): string {
		if ( self::$demande === null ) self::$demande = self::code();
		self::proteger_dossiers();
		return '/' . self::BASE . '/demandes/' . self::$demande;
	}

	/** Nom de fichier d'un dépôt : « <préfixe du type>-<nom d'origine> ». */
	public static function nom_depot( string $sous_dossier, string $nom ): string {
		$cle = self::SOUS_DOSSIERS[ $sous_dossier ] ?? '';
		return ( $cle !== '' ? self::TYPES[ $cle ] . '-' : '' ) . $nom;
	}

	public static function code(): string {
		return strtolower( wp_generate_password( 10, false, false ) );
	}

	// ══════════════════════════════════════════════════════════════════════
	// DÉPÔT DEPUIS LA FICHE ÉLÈVE (09/10/2026)
	// ══════════════════════════════════════════════════════════════════════
	// Les boutons « Choisir une photo » / « Choisir un fichier » de la fiche élève passaient par
	// la médiathèque WordPress (publique) : un envoi suivi d'un abandon de la fiche laissait le
	// fichier public, et les envois répétés y laissaient des doublons (67 photos d'identité
	// orphelines constatées le 09/10). Désormais le fichier va directement dans le dossier de
	// l'adhérent (fiche existante) ou dans un dossier de dépôt fermé (nouvelle fiche, rangé à
	// l'enregistrement par ranger_fiche()).

	const ACTION_DEPOT = 'sp_cal_adh_depot';

	/** admin-ajax.php?action=sp_cal_adh_depot — champs : eleve_id, cle, fichier. */
	public static function ajax_depot(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_send_json_error( 'Accès refusé.', 403 );
		check_ajax_referer( self::ACTION_DEPOT );
		$id  = absint( $_POST['eleve_id'] ?? 0 );
		$cle = sanitize_key( wp_unslash( $_POST['cle'] ?? '' ) );
		if ( ! isset( self::TYPES[ $cle ] ) ) wp_send_json_error( 'Type de document inconnu.', 400 );
		if ( empty( $_FILES['fichier']['name'] ) || ( $_FILES['fichier']['error'] ?? 1 ) !== UPLOAD_ERR_OK ) wp_send_json_error( "Le fichier n'a pas été reçu.", 400 );
		if ( (int) $_FILES['fichier']['size'] > 10 * 1024 * 1024 ) wp_send_json_error( 'Fichier trop lourd (10 Mo au plus).', 400 );

		$mimes = $cle === 'photo'
			? [ 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif' ]
			: [ 'pdf' => 'application/pdf', 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png' ];

		self::proteger_dossiers();
		$dossier = $id ? self::dossier_adherent( $id ) : 'demandes/' . self::code();
		wp_mkdir_p( self::base() . '/' . $dossier );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$cible  = '/' . self::BASE . '/' . $dossier;
		$filtre = static function ( array $dirs ) use ( $cible ): array {
			$dirs['subdir'] = $cible;
			$dirs['path']   = $dirs['basedir'] . $cible;
			$dirs['url']    = $dirs['baseurl'] . $cible;
			return $dirs;
		};
		$_FILES['fichier']['name'] = self::TYPES[ $cle ] . '-' . self::sans_prefixe( sanitize_file_name( (string) $_FILES['fichier']['name'] ) );
		add_filter( 'upload_dir', $filtre );
		$res = wp_handle_upload( $_FILES['fichier'], [ 'test_form' => false, 'mimes' => $mimes ] );
		remove_filter( 'upload_dir', $filtre );
		if ( isset( $res['error'] ) ) wp_send_json_error( $res['error'], 400 );

		$rel = $dossier . '/' . basename( $res['file'] );
		if ( $cle === 'photo' ) self::reduire( $res['file'] );
		// Photo d'une fiche existante : adresse signée (affichable partout) ; sinon adresse du
		// fichier, lue par le bureau et rangée à l'enregistrement de la fiche.
		$valeur = ( $cle === 'photo' && $id ) ? self::url_photo( $rel ) : self::url_fichier( $rel );
		wp_send_json_success( [ 'valeur' => $valeur, 'voir' => self::lien( $valeur ) ] );
	}

	/** Jeton à placer dans la fiche élève pour ajax_depot(). */
	public static function nonce_depot(): string {
		return wp_create_nonce( self::ACTION_DEPOT );
	}

	// ══════════════════════════════════════════════════════════════════════
	// RANGEMENT DANS LE DOSSIER DE L'ADHÉRENT
	// ══════════════════════════════════════════════════════════════════════

	/** « <id>-<code> » : dossier existant de la fiche, créé si besoin. */
	public static function dossier_adherent( int $id ): string {
		$trouve = glob( self::base() . '/' . $id . '-*', GLOB_ONLYDIR ) ?: [];
		foreach ( $trouve as $d ) {
			if ( preg_match( '#^' . $id . '-[a-z0-9]{6,16}$#', basename( $d ) ) ) return basename( $d );
		}
		$nom = $id . '-' . self::code();
		self::proteger_dossiers();
		wp_mkdir_p( self::base() . '/' . $nom );
		return $nom;
	}

	/**
	 * Range dans le dossier de l'adhérent tous les fichiers de sa fiche (photo + documents) et
	 * met à jour les adresses : fiche, et demande(s) d'adhésion qui pointaient vers les mêmes
	 * fichiers. Renvoie le bilan [ 'deja', 'range', 'garde', 'absent' (fichier introuvable : rien à protéger),
	 * 'echec' (copie impossible) => nombre ].
	 */
	public static function ranger_fiche( int $id ): array {
		global $wpdb;
		$tel   = $wpdb->prefix . 'sp_cal_eleves';
		$bilan = [ 'deja' => 0, 'range' => 0, 'garde' => 0, 'absent' => 0, 'echec' => 0 ];
		$el    = $wpdb->get_row( $wpdb->prepare( "SELECT id, photo_url, extra_data FROM $tel WHERE id = %d", $id ) );
		if ( ! $el ) return $bilan;

		$extra = json_decode( (string) $el->extra_data, true );
		$extra = is_array( $extra ) ? $extra : [];
		$docs  = isset( $extra['documents'] ) && is_array( $extra['documents'] ) ? $extra['documents'] : [];

		$dossier = '';
		$changes = [];    // ancienne adresse => nouvelle
		$sources = [];    // fichiers d'origine à retirer une fois les adresses mises à jour

		$a_ranger = [ 'photo' => (string) $el->photo_url ];
		foreach ( $docs as $cle => $url ) {
			if ( isset( self::TYPES[ $cle ] ) && $cle !== 'photo' ) $a_ranger[ $cle ] = (string) $url;
		}
		foreach ( $a_ranger as $cle => $url ) {
			if ( $url === '' ) continue;
			$src = self::source( $url );
			if ( $src === null ) continue;                                   // pas un fichier personnel connu
			if ( $src['type'] === 'adherent' && strpos( $src['rel'], $id . '-' ) === 0 ) { $bilan['deja']++; continue; }
			if ( ! is_file( $src['chemin'] ) ) { $bilan['absent']++; continue; }
			if ( $dossier === '' ) $dossier = self::dossier_adherent( $id );
			$nom  = self::nom_unique( self::base() . '/' . $dossier, self::TYPES[ $cle ] . '-' . self::sans_prefixe( basename( $src['chemin'] ) ) );
			if ( ! @copy( $src['chemin'], self::base() . '/' . $dossier . '/' . $nom ) ) { $bilan['echec']++; continue; }
			$rel   = $dossier . '/' . $nom;
			if ( $cle === 'photo' ) self::reduire( self::base() . '/' . $rel );
			$neuve = $cle === 'photo' ? self::url_photo( $rel ) : self::url_fichier( $rel );
			$changes[ $url ] = $neuve;
			$sources[]       = $src + [ 'url' => $url ];
			if ( $cle === 'photo' ) $el->photo_url = $neuve; else $docs[ $cle ] = $neuve;
			$bilan['range']++;
		}
		if ( ! $changes ) return $bilan;

		$extra['documents'] = $docs;
		$wpdb->update( $tel, [ 'photo_url' => $el->photo_url, 'extra_data' => wp_json_encode( $extra ) ], [ 'id' => $id ] );
		self::remplacer_dans_demandes( $changes );

		foreach ( $sources as $src ) {
			if ( ! self::retirer_source( $src ) ) $bilan['garde']++;
		}
		return $bilan;
	}

	/** Range les fichiers d'une demande pas encore acceptée dans demandes/<code>/. */
	public static function ranger_demande( object $row ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sp_adhesions_pending';
		$bilan = [ 'deja' => 0, 'range' => 0, 'garde' => 0, 'absent' => 0, 'echec' => 0 ];
		$dossier = '';
		$maj = [];
		$sources = [];
		foreach ( self::COLONNES_DEMANDE as $col => $cle ) {
			$url = (string) ( $row->$col ?? '' );
			if ( $url === '' ) continue;
			$src = self::source( $url );
			if ( $src === null ) continue;
			if ( $src['type'] === 'adherent' ) { $bilan['deja']++; continue; }
			if ( ! is_file( $src['chemin'] ) ) { $bilan['absent']++; continue; }
			if ( $dossier === '' ) { $dossier = 'demandes/' . self::code(); self::proteger_dossiers(); wp_mkdir_p( self::base() . '/' . $dossier ); }
			$nom = self::nom_unique( self::base() . '/' . $dossier, self::TYPES[ $cle ] . '-' . self::sans_prefixe( basename( $src['chemin'] ) ) );
			if ( ! @copy( $src['chemin'], self::base() . '/' . $dossier . '/' . $nom ) ) { $bilan['echec']++; continue; }
			$maj[ $col ] = self::url_fichier( $dossier . '/' . $nom );
			$sources[]   = $src + [ 'url' => $url ];
			$bilan['range']++;
		}
		if ( $maj ) {
			$wpdb->update( $table, $maj, [ 'id' => intval( $row->id ) ] );
			foreach ( $sources as $src ) {
				if ( ! self::retirer_source( $src ) ) $bilan['garde']++;
			}
		}
		return $bilan;
	}

	/**
	 * D'où vient un fichier enregistré : [ 'type' => adherent|ancien|mediatheque, 'chemin', 'rel',
	 * 'piece' (id de la médiathèque) ] ; null si ce n'est pas un fichier de ce site à ranger.
	 */
	public static function source( string $url ): ?array {
		$rel = self::chemin_adherent( $url );
		if ( $rel !== '' ) return [ 'type' => 'adherent', 'rel' => $rel, 'chemin' => self::base() . '/' . $rel, 'piece' => 0 ];

		$uploads = wp_upload_dir();
		$chemin  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$prefixe = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( $prefixe === '' || strpos( $chemin, $prefixe . '/' ) !== 0 ) return null;
		$rel = rawurldecode( substr( $chemin, strlen( $prefixe ) + 1 ) );
		if ( strpos( $rel, '..' ) !== false ) return null;

		if ( preg_match( '#^' . self::ANCIENNE . '/[a-z-]+/\d{4}/[^/\\\\]+$#', $rel ) ) {
			$f = $uploads['basedir'] . '/' . $rel;
			return empty( wp_check_filetype( $f, self::MIMES )['type'] ) ? null : [ 'type' => 'ancien', 'rel' => $rel, 'chemin' => $f, 'piece' => 0 ];
		}
		if ( preg_match( '#^\d{4}/\d{2}/[^/\\\\]+$#', $rel ) ) {
			$f = $uploads['basedir'] . '/' . $rel;
			if ( empty( wp_check_filetype( $f, self::MIMES )['type'] ) ) return null;
			return [ 'type' => 'mediatheque', 'rel' => $rel, 'chemin' => $f, 'piece' => (int) attachment_url_to_postid( $url ) ];
		}
		return null;
	}

	/** Remplace d'anciennes adresses par les nouvelles dans les demandes d'adhésion. */
	private static function remplacer_dans_demandes( array $changes ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sp_adhesions_pending';
		foreach ( $changes as $ancienne => $neuve ) {
			foreach ( array_keys( self::COLONNES_DEMANDE ) as $col ) {
				// Une photo de demande reste lue par l'administration : adresse du fichier, pas la photo signée.
				$valeur = strpos( $neuve, 'sp_photo=' ) !== false ? self::url_fichier( self::chemin_adherent( $neuve ) ) : $neuve;
				$wpdb->update( $table, [ $col => $valeur ], [ $col => $ancienne ] );
			}
		}
	}

	/**
	 * Retire le fichier d'origine une fois recopié, s'il n'est plus utilisé nulle part : fichier
	 * de demande ou de l'ancien rangement → supprimé ; médiathèque → entrée supprimée (fichier et
	 * vignettes). Renvoie false si l'original a été gardé parce qu'il sert encore.
	 */
	private static function retirer_source( array $src ): bool {
		if ( self::encore_utilise( $src ) ) return false;
		if ( $src['type'] === 'mediatheque' ) {
			if ( $src['piece'] ) { wp_delete_attachment( $src['piece'], true ); return true; }
			return false; // fichier de la médiathèque sans entrée connue : laissé tel quel
		}
		return @unlink( $src['chemin'] );
	}

	/** L'original sert-il encore (contenus du site, réglages, fiches, demandes, équipe) ? */
	private static function encore_utilise( array $src ): bool {
		global $wpdb;
		// Chemin complet de l'original (le nom seul se retrouve à l'identique dans la copie rangée).
		// Fichier du dossier adhérent : adresse du fichier ou photo signée (« / » encodé en %2F).
		$motifs = $src['type'] === 'adherent'
			? [ self::BASE . '/' . $src['rel'], 'sp_photo=' . rawurlencode( $src['rel'] ) ]
			: [ $src['rel'] ];
		$n = 0;
		foreach ( $motifs as $m ) {
			$like = '%' . $wpdb->esc_like( $m ) . '%';
			$n += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sp_cal_eleves WHERE photo_url LIKE %s OR extra_data LIKE %s", $like, $like ) );
			$n += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sp_adhesions_pending WHERE CONCAT_WS(' ', photo_url, doc_certificat_medical, doc_attestation_rc, doc_decharge_honneur, doc_bon_caf) LIKE %s", $like ) );
		}
		if ( $src['type'] === 'mediatheque' ) {
			// Contenus du site : aussi sous forme de vignette (« nom-300x200.jpg ») → chemin sans extension.
			$like = '%' . $wpdb->esc_like( preg_replace( '/\.[A-Za-z0-9]+$/', '', $src['rel'] ) ) . '%';
			$n += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID <> %d AND post_type NOT IN ('revision','attachment') AND post_content LIKE %s", $src['piece'], $like ) );
			$n += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_value LIKE %s", $src['piece'], $like ) );
			$n += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sp_cal_trainers WHERE photo_url LIKE %s", $like ) );
		}
		return $n > 0;
	}

	/**
	 * Photo d'adhérent ramenée à 800 px au plus (cartes, fiche, application et vœux d'anniversaire :
	 * avant, les vœux utilisaient la version réduite de la médiathèque ; les photos de téléphone
	 * dépassent souvent 4 Mo). Sans effet si l'image est déjà petite ou si l'éditeur d'images
	 * de WordPress n'est pas disponible.
	 */
	public static function reduire( string $fichier ): void {
		if ( ! function_exists( 'wp_get_image_editor' ) ) return;
		$ed = wp_get_image_editor( $fichier );
		if ( is_wp_error( $ed ) ) return;
		$taille = $ed->get_size();
		if ( ( $taille['width'] ?? 0 ) <= 800 && ( $taille['height'] ?? 0 ) <= 800 ) return;
		if ( ! is_wp_error( $ed->resize( 800, 800, false ) ) ) $ed->save( $fichier );
	}

	public static function sans_prefixe( string $nom ): string {
		foreach ( self::TYPES as $p ) {
			if ( strpos( $nom, $p . '-' ) === 0 ) return substr( $nom, strlen( $p ) + 1 );
		}
		return $nom;
	}

	public static function nom_unique( string $dir, string $nom ): string {
		return wp_unique_filename( $dir, sanitize_file_name( $nom ) );
	}

	// ══════════════════════════════════════════════════════════════════════
	// MIGRATION UNIQUE DES FICHIERS EXISTANTS
	// ══════════════════════════════════════════════════════════════════════

	/**
	 * Range une fois toutes les fiches élèves puis les demandes non rattachées à une fiche.
	 * Une fois tout rangé sans échec, ferme aussi l'ancien dossier sp-adhesions-docs/ en entier
	 * (photos comprises). Bilan chiffré dans OPTION_JOURNAL.
	 */
	public static function migrer( int $secondes = 20 ): bool {
		global $wpdb;
		$fin   = time() + $secondes;  // au-delà : on s'arrête, la page suivante reprendra
		$total = (array) get_option( self::OPTION_JOURNAL, [] );
		if ( ( $total['version'] ?? '' ) !== self::VERSION_MIGRATION ) {
			$total = [ 'version' => self::VERSION_MIGRATION, 'passes' => 0, 'fiches' => 0, 'demandes' => 0, 'deja' => 0, 'range' => 0, 'garde' => 0, 'absent' => 0, 'echec' => 0 ];
		}
		$total['passes']++;
		$termine = true;
		foreach ( (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}sp_cal_eleves ORDER BY id" ) as $id ) {
			if ( time() > $fin ) { $termine = false; break; }
			$b = self::ranger_fiche( (int) $id );
			if ( $b['range'] ) $total['fiches']++;
			foreach ( $b as $k => $v ) if ( $k !== 'deja' ) $total[ $k ] += $v;
		}
		if ( $termine ) {
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}sp_adhesions_pending ORDER BY id" ) as $row ) {
				if ( time() > $fin ) { $termine = false; break; }
				$b = self::ranger_demande( $row );
				if ( $b['range'] ) $total['demandes']++;
				foreach ( $b as $k => $v ) if ( $k !== 'deja' ) $total[ $k ] += $v;
			}
		}
		$total['date'] = current_time( 'mysql' );
		if ( $termine && $total['echec'] === 0 ) {
			if ( is_dir( self::ancienne_base() ) ) self::ecrire_regle( self::ancienne_base() );
			update_option( self::OPTION_MIGRATION, self::VERSION_MIGRATION, false );
		}
		update_option( self::OPTION_JOURNAL, $total, false );
		return $termine;
	}
}

add_action( 'admin_post_' . SP_Cal_Docs_Adhesion::ACTION, [ 'SP_Cal_Docs_Adhesion', 'servir' ] );
add_action( 'admin_init', [ 'SP_Cal_Docs_Adhesion', 'verifier' ] );
add_action( 'init', [ 'SP_Cal_Docs_Adhesion', 'servir_photo' ], 1 );
add_action( 'wp_ajax_' . SP_Cal_Docs_Adhesion::ACTION_DEPOT, [ 'SP_Cal_Docs_Adhesion', 'ajax_depot' ] );

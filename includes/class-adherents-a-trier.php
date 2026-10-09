<?php
/**
 * « 🗂️ Photos à trier » — photos orphelines de la médiathèque (09/10/2026).
 *
 * Constat du 09/10/2026 en prod : après le rangement des fichiers dans les dossiers adhérents
 * (class-docs-adhesion.php), 67 photos d'identité restaient dans la médiathèque publique,
 * reliées à aucune fiche ni utilisées nulle part : doublons des envois d'avril 2026 (photos
 * envoyées deux ou trois fois depuis les fiches) et envois abandonnés. Le rangement ne pouvait
 * pas les prendre : rien n'indique à qui elles appartiennent.
 *
 * Cette page :
 *  1. liste les images de la médiathèque non utilisées (aucun contenu, réglage, fiche, demande,
 *     membre de l'équipe ni pièce de la trésorerie) — cochées d'office si le nom ressemble à une
 *     photo de téléphone (IMG_…, image, PXL_…) ;
 *  2. « Mettre à l'abri » : les sort de la médiathèque vers sp-adherents/a-trier/ (fermé au
 *     web), sans rien perdre ;
 *  3. pour chaque photo à l'abri : « Rattacher à une fiche » (devient la photo de l'adhérent,
 *     dans son dossier) ou « Supprimer ».
 * Toutes les actions passent par admin-post.php (droit « gestion des adhésions » + jeton).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Adherents_A_Trier {

	const PAGE    = 'sp-cal-photos-a-trier';
	const DOSSIER = 'a-trier';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 20 );
		foreach ( [ 'abriter', 'rattacher', 'supprimer' ] as $a ) {
			add_action( 'admin_post_sp_cal_trier_' . $a, [ __CLASS__, 'handle_' . $a ] );
		}
	}

	public static function menu(): void {
		add_submenu_page( 'sp-cal-pro', 'Photos à trier', '🗂️ Photos à trier', SP_Cal_Roles::CAP_GESTION_ADHESIONS, self::PAGE, [ __CLASS__, 'page' ] );
	}

	private static function url( array $args = [] ): string {
		return add_query_arg( [ 'page' => self::PAGE ] + $args, admin_url( 'admin.php' ) );
	}

	private static function controle( string $action ): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.', 403 );
		check_admin_referer( 'sp_cal_trier_' . $action );
	}

	// ══════════════════════════════════════════════════════════════════════
	// RECHERCHE DES PHOTOS ORPHELINES
	// ══════════════════════════════════════════════════════════════════════

	/** Nom de photo de téléphone (coché d'office). */
	public static function nom_de_telephone( string $titre ): bool {
		// « image » seul (envoi depuis un téléphone), pas « Image-Contact » (illustration du thème).
		return (bool) preg_match( '/^(image(\s*\(\d+\))?$|image[_-]?\d|img[_-]?\d|pxl_|dsc|dcim|photo[_-]?\d|whatsapp|\d{8,})/i', trim( $titre ) );
	}

	/**
	 * Images de la médiathèque non rattachées et utilisées nulle part.
	 *
	 * @return object[] { ID, post_title, post_date, fichier (chemin sous uploads/), url }
	 */
	public static function orphelines(): array {
		global $wpdb;
		$images = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_date, m.meta_value AS fichier
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
			  WHERE p.post_type = 'attachment' AND p.post_parent = 0 AND p.post_mime_type LIKE 'image/%'
			    AND m.meta_value NOT LIKE 'elementor/%'
			  ORDER BY p.post_date DESC"
		);
		$compta = [];
		foreach ( [ 'sp_compta_depense' => 'justificatif', 'sp_compta_recette' => 'justificatif', 'sp_compta_sponsor' => 'fichier_contrat' ] as $t => $c ) {
			$table = $wpdb->prefix . $t;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) continue;
			foreach ( (array) $wpdb->get_col( "SELECT `$c` FROM `$table` WHERE `$c` <> ''" ) as $v ) $compta[] = (string) $v;
		}
		$logo = [ (string) get_option( 'site_icon' ), (string) get_theme_mod( 'custom_logo' ) ];

		$out = [];
		foreach ( (array) $images as $img ) {
			$id = (string) $img->ID;
			if ( in_array( $id, $compta, true ) || in_array( $id, $logo, true ) ) continue;
			$sans_ext = preg_replace( '/\.[A-Za-z0-9]+$/', '', (string) $img->fichier );
			$like     = '%' . $wpdb->esc_like( $sans_ext ) . '%';
			// Utilisée aussi par son numéro : bloc image (« wp-image-123 », "id":123), galerie (ids="…123…"),
			// Elementor ("id":123). Comptée large : au moindre doute, l'image n'est pas proposée.
			$par_id   = [ '%wp-image-' . $id . '"%', '%"id":' . $id . ',%', '%"id":' . $id . '}%', '%"id":"' . $id . '"%' ];
			$utilise  = 0;
			foreach ( $par_id as $m ) {
				$utilise += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_content LIKE %s", $m ) )
					+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key LIKE %s AND meta_value LIKE %s", $img->ID, '\_elementor%', $m ) );
			}
			$utilise += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_content LIKE %s AND post_content REGEXP %s", '%ids=%', 'ids="[0-9, ]*\\b' . $id . '\\b' ) );
			$utilise += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_content LIKE %s", $like ) )
				+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id <> %d AND ( meta_value LIKE %s OR ( meta_key = '_thumbnail_id' AND meta_value = %s ) )", $img->ID, $like, $id ) )
				+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", $like ) )
				+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sp_cal_trainers WHERE photo_url LIKE %s", $like ) )
				+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sp_cal_eleves WHERE photo_url LIKE %s OR extra_data LIKE %s", $like, $like ) )
				+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sp_adhesions_pending WHERE CONCAT_WS(' ', photo_url, doc_certificat_medical, doc_attestation_rc, doc_decharge_honneur, doc_bon_caf) LIKE %s", $like ) );
			if ( $utilise > 0 ) continue;
			$img->url = wp_get_attachment_image_url( (int) $img->ID, 'thumbnail' ) ?: wp_get_attachment_url( (int) $img->ID );
			$out[]    = $img;
		}
		return $out;
	}

	// ══════════════════════════════════════════════════════════════════════
	// ACTIONS
	// ══════════════════════════════════════════════════════════════════════

	/** Sort les photos cochées de la médiathèque vers sp-adherents/a-trier/. */
	public static function handle_abriter(): void {
		self::controle( 'abriter' );
		$ids      = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );
		$permises = wp_list_pluck( self::orphelines(), 'ID' );  // re-vérifiées au moment d'agir
		$dir      = SP_Cal_Docs_Adhesion::base() . '/' . self::DOSSIER;
		SP_Cal_Docs_Adhesion::proteger_dossiers();
		wp_mkdir_p( $dir );
		$n = 0; $echec = 0;
		foreach ( $ids as $id ) {
			if ( ! in_array( (string) $id, array_map( 'strval', $permises ), true ) ) continue;
			$src = get_attached_file( $id );
			if ( ! $src || ! is_file( $src ) ) { $echec++; continue; }
			$nom = SP_Cal_Docs_Adhesion::nom_unique( $dir, gmdate( 'Ymd', (int) get_post_time( 'U', true, $id ) ) . '-' . $id . '-' . basename( $src ) );
			if ( ! @copy( $src, $dir . '/' . $nom ) ) { $echec++; continue; }
			wp_delete_attachment( $id, true );
			$n++;
		}
		wp_safe_redirect( self::url( [ 'abritees' => $n, 'echecs' => $echec ] ) );
		exit;
	}

	/** Une photo à l'abri devient la photo d'un adhérent (rangée dans son dossier). */
	public static function handle_rattacher(): void {
		self::controle( 'rattacher' );
		global $wpdb;
		$fichier = sanitize_file_name( wp_unslash( $_POST['fichier'] ?? '' ) );
		$eleve   = absint( $_POST['eleve_id'] ?? 0 );
		$src     = SP_Cal_Docs_Adhesion::base() . '/' . self::DOSSIER . '/' . $fichier;
		$tel     = $wpdb->prefix . 'sp_cal_eleves';
		if ( $fichier === '' || ! is_file( $src ) || ! $eleve
			|| ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tel WHERE id = %d", $eleve ) ) ) {
			wp_safe_redirect( self::url( [ 'erreur' => 1 ] ) ); exit;
		}
		$dossier = SP_Cal_Docs_Adhesion::dossier_adherent( $eleve );
		$nom     = SP_Cal_Docs_Adhesion::nom_unique( SP_Cal_Docs_Adhesion::base() . '/' . $dossier, 'photo-' . preg_replace( '/^\d{8}-\d+-/', '', $fichier ) );
		$dest    = SP_Cal_Docs_Adhesion::base() . '/' . $dossier . '/' . $nom;
		if ( ! @rename( $src, $dest ) ) { wp_safe_redirect( self::url( [ 'erreur' => 1 ] ) ); exit; }
		SP_Cal_Docs_Adhesion::reduire( $dest );
		$wpdb->update( $tel, [ 'photo_url' => SP_Cal_Docs_Adhesion::url_photo( $dossier . '/' . $nom ) ], [ 'id' => $eleve ] );
		wp_safe_redirect( self::url( [ 'rattachee' => $eleve ] ) );
		exit;
	}

	/** Suppression définitive d'une photo à l'abri, à la demande du bureau. */
	public static function handle_supprimer(): void {
		self::controle( 'supprimer' );
		$fichier = sanitize_file_name( wp_unslash( $_POST['fichier'] ?? '' ) );
		$f       = SP_Cal_Docs_Adhesion::base() . '/' . self::DOSSIER . '/' . $fichier;
		$ok      = $fichier !== '' && $fichier !== '.htaccess' && is_file( $f ) && @unlink( $f );
		wp_safe_redirect( self::url( $ok ? [ 'supprimee' => 1 ] : [ 'erreur' => 1 ] ) );
		exit;
	}

	// ══════════════════════════════════════════════════════════════════════
	// PAGE
	// ══════════════════════════════════════════════════════════════════════

	public static function page(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		global $wpdb;
		echo '<div class="wrap"><h1>🗂️ Photos à trier</h1>';
		echo '<p style="max-width:780px;color:#475569;">Photos qui ne sont reliées à aucune fiche ni utilisées sur le site. Mises à l\'abri, elles quittent la médiathèque publique pour un dossier fermé : on peut ensuite les rattacher à la fiche de l\'adhérent concerné, ou les supprimer.</p>';

		$notes = [
			'abritees'  => static fn( $v ) => '✅ ' . intval( $v ) . ' photo(s) mise(s) à l\'abri' . ( ! empty( $_GET['echecs'] ) ? ' — ' . intval( $_GET['echecs'] ) . ' échec(s)' : '' ) . '.',
			'rattachee' => static fn( $v ) => '✅ Photo rattachée à la fiche.',
			'supprimee' => static fn( $v ) => '🗑️ Photo supprimée.',
			'erreur'    => static fn( $v ) => '⚠️ L\'opération n\'a pas pu être faite.',
		];
		foreach ( $notes as $k => $f ) {
			if ( isset( $_GET[ $k ] ) ) printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $k === 'erreur' ? 'error' : 'success', esc_html( $f( sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) ) ) );
		}

		// ── 1. Photos à l'abri ──
		$dir      = SP_Cal_Docs_Adhesion::base() . '/' . self::DOSSIER;
		$fichiers = is_dir( $dir ) ? array_values( array_filter( array_map( 'basename', glob( $dir . '/*' ) ?: [] ), static fn( $f ) => $f !== '.htaccess' ) ) : [];
		echo '<h2>À l\'abri, à trier (' . count( $fichiers ) . ')</h2>';
		if ( $fichiers ) {
			$eleves = $wpdb->get_results( "SELECT id, nom, prenom, actif, photo_url FROM {$wpdb->prefix}sp_cal_eleves ORDER BY (photo_url = '') DESC, actif DESC, nom, prenom" );
			$options = '<option value="">— Fiche —</option>';
			foreach ( (array) $eleves as $e ) {
				$options .= sprintf( '<option value="%d">%s%s %s%s</option>', $e->id, $e->photo_url === '' ? '' : '🖼️ ', esc_html( mb_strtoupper( $e->nom ) ), esc_html( $e->prenom ), $e->actif ? '' : ' (inactif)' );
			}
			echo '<p style="color:#64748b;">Dans la liste des fiches, celles <strong>sans photo</strong> viennent en premier ; 🖼️ = la fiche a déjà une photo (elle serait remplacée).</p>';
			echo '<div style="display:flex;flex-wrap:wrap;gap:14px;">';
			foreach ( $fichiers as $f ) {
				$voir = SP_Cal_Docs_Adhesion::lien( SP_Cal_Docs_Adhesion::url_fichier( self::DOSSIER . '/' . $f ) );
				echo '<div style="width:200px;border:1px solid #e5e7eb;border-radius:8px;padding:8px;background:#fff;">';
				printf( '<a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" loading="lazy" style="width:100%%;height:180px;object-fit:cover;border-radius:6px;background:#f1f5f9;"></a>', esc_url( $voir ) );
				printf( '<div style="font-size:11px;color:#64748b;margin:4px 0;word-break:break-all;">%s</div>', esc_html( $f ) );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 6px;">';
				wp_nonce_field( 'sp_cal_trier_rattacher' );
				echo '<input type="hidden" name="action" value="sp_cal_trier_rattacher"><input type="hidden" name="fichier" value="' . esc_attr( $f ) . '">';
				echo '<select name="eleve_id" required style="width:100%;margin-bottom:4px;">' . $options . '</select>'; // options échappées ci-dessus
				echo '<button class="button button-primary button-small" style="width:100%;">Rattacher comme photo</button></form>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Supprimer définitivement cette photo ?\');" style="margin:0;">';
				wp_nonce_field( 'sp_cal_trier_supprimer' );
				echo '<input type="hidden" name="action" value="sp_cal_trier_supprimer"><input type="hidden" name="fichier" value="' . esc_attr( $f ) . '">';
				echo '<button class="button button-small" style="width:100%;color:#b91c1c;">Supprimer</button></form>';
				echo '</div>';
			}
			echo '</div>';
		} else {
			echo '<p>Aucune.</p>';
		}

		// ── 2. Orphelines encore dans la médiathèque ──
		$orph = self::orphelines();
		echo '<h2 style="margin-top:28px;">Encore dans la médiathèque publique (' . count( $orph ) . ')</h2>';
		if ( ! $orph ) { echo '<p>Aucune image orpheline.</p></div>'; return; }
		echo '<p style="color:#64748b;">Images reliées à rien et utilisées nulle part sur le site (contenus, réglages, fiches, demandes, équipe, trésorerie). Cochées d\'office : celles dont le nom ressemble à une photo de téléphone. Vérifiez les autres (illustrations prévues pour une future page ?).</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sp_cal_trier_abriter' );
		echo '<input type="hidden" name="action" value="sp_cal_trier_abriter">';
		echo '<div style="display:flex;flex-wrap:wrap;gap:10px;">';
		foreach ( $orph as $o ) {
			printf(
				'<label style="width:150px;border:1px solid #e5e7eb;border-radius:8px;padding:6px;background:#fff;cursor:pointer;"><img src="%s" loading="lazy" style="width:100%%;height:120px;object-fit:cover;border-radius:6px;"><span style="display:block;font-size:11px;margin-top:4px;"><input type="checkbox" name="ids[]" value="%d" %s> %s<br><span style="color:#94a3b8;">%s</span></span></label>',
				esc_url( (string) $o->url ), intval( $o->ID ), checked( self::nom_de_telephone( (string) $o->post_title ), true, false ),
				esc_html( mb_strimwidth( (string) $o->post_title, 0, 22, '…' ) ), esc_html( mysql2date( 'd/m/Y', $o->post_date ) )
			);
		}
		echo '</div><p><button class="button button-primary">🔒 Mettre à l\'abri la sélection</button></p></form></div>';
	}
}

SP_Cal_Adherents_A_Trier::init();

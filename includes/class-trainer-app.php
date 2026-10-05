<?php
/**
 * PWA "Disponibilités" pour les entraîneurs — shortcode [sp_cal_dispo_app].
 *
 * Écran mobile très simplifié pour qu'un entraîneur déclare lui-même ses dispos, sans
 * passer par le tableau de bord wp-admin (jugé "usine à gaz" pour ce public, cf.
 * doleances.md 09/09/2026) et sans identifiants à retaper (lien personnel à token, sur
 * le même principe que SpCalPro_Token pour la fiche adhérent — voir class-token.php).
 *
 * Écrit dans la même table que l'admin (SpCalPro_DB::save_dispo()) : une dispo posée
 * depuis cette app apparaît immédiatement dans le calendrier admin, une seule source
 * de vérité. Toutes les dispos de l'équipe sont visibles (pas seulement les siennes) —
 * un entraîneur ne peut modifier que sa propre ligne, jamais celle d'un collègue.
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Trainer_App {

	private static ?self $instance = null;
	private $db;

	public static function get_instance( $db = null ): self {
		if ( self::$instance === null ) self::$instance = new self( $db );
		return self::$instance;
	}

	private function __construct( $db = null ) {
		$this->db = $db;

		add_shortcode( 'sp_cal_dispo_app', [ $this, 'render' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_add_token_columns' ] );

		add_action( 'wp_ajax_sp_cal_dispo_app_data',        [ $this, 'ajax_get_data' ] );
		add_action( 'wp_ajax_nopriv_sp_cal_dispo_app_data', [ $this, 'ajax_get_data' ] );
		add_action( 'wp_ajax_sp_cal_dispo_app_save',        [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nopriv_sp_cal_dispo_app_save', [ $this, 'ajax_save' ] );

		add_action( 'wp_ajax_sp_cal_send_dispo_app_link', [ $this, 'ajax_send_link' ] );

		// 01/10/2026 — le lien personnel des disponibilités ouvre désormais l'application
		// entraîneur complète (Accueil / Mes dispos / Pointage), identifiée par ce même jeton.
		add_action( 'template_redirect', [ $this, 'maybe_redirect_to_app' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve_embed' ], 1 );
		add_action( 'rest_api_init', [ $this, 'register_rest' ] );
	}

	// ─── Application entraîneur unifiée (lien personnel → /app/) ───────────────

	/** URL de la page de l'application (shortcode [sp_cal_app]), '' si absente. */
	private function url_pwa(): string {
		global $wpdb;
		$page_id = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE '%[sp_cal_app%' LIMIT 1"
		);
		return $page_id ? (string) get_permalink( $page_id ) : '';
	}

	/** Page des disponibilités dans l'onglet « Mes dispos » de l'application (sans habillage du thème). */
	public static function url_embed( string $token ): string {
		return add_query_arg( [ 'sp_dispo_embed' => '1', 'token' => $token ], home_url( '/' ) );
	}

	/**
	 * Lien personnel habituel (page [sp_cal_dispo_app]?token=…) : redirige vers l'application
	 * en mode entraîneur identifié. Le lien et les raccourcis existants restent valables.
	 * Secours : &classique=1 garde l'ancienne page seule ; pas de redirection non plus si
	 * la page de l'application n'existe pas ou si le jeton est invalide.
	 */
	public function maybe_redirect_to_app(): void {
		if ( ! is_singular() || isset( $_GET['classique'] ) ) return;
		$post = get_post();
		if ( ! $post || ! has_shortcode( (string) $post->post_content, 'sp_cal_dispo_app' ) ) return;
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		if ( ! $this->trainer_by_token( $token ) ) return;
		$app = $this->url_pwa();
		if ( $app === '' ) return;
		wp_safe_redirect( add_query_arg( 'entraineur', $token, $app ) );
		exit;
	}

	/** Disponibilités seules, en ambiance sombre, pour l'iframe de l'onglet « Mes dispos ». */
	public function maybe_serve_embed(): void {
		if ( empty( $_GET['sp_dispo_embed'] ) ) return;
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
		   . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
		   . '<title>Mes disponibilités</title>'
		   . '<style>html,body{margin:0;padding:0;background:#0d0d0d;}</style></head><body>'
		   . $this->render( [ 'theme' => 'sombre' ] ) // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans render()
		   . '</body></html>';
		exit;
	}

	public function register_rest(): void {
		register_rest_route( 'spcal/v1', '/entraineur/session', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_session' ],
			'permission_callback' => '__return_true', // contrôle par le jeton personnel ci-dessous
		] );
	}

	/**
	 * Ouverture de l'application par un entraîneur identifié par son jeton personnel : renvoie
	 * son nom, l'adresse de ses disponibilités et le PIN de pointage commun (qu'un entraîneur
	 * connaît déjà) pour que le Pointage s'ouvre sans le ressaisir.
	 */
	public function rest_session( WP_REST_Request $req ) {
		$token = sanitize_text_field( (string) $req->get_param( 'token' ) );
		$me    = $this->trainer_by_token( $token );
		if ( ! $me ) {
			return new WP_REST_Response( [ 'success' => false, 'data' => 'Lien invalide ou expiré.' ], 403 );
		}
		$pin = function_exists( 'sp_pointage_pin' ) ? sp_pointage_pin() : (string) get_option( 'sp_cal_pointage_pin', '' );
		return new WP_REST_Response( [
			'success'    => true,
			'nom'        => $me->nom_public ?: $me->nom,
			'pin'        => (string) $pin,
			'dispos_url' => self::url_embed( $token ),
		], 200 );
	}

	// ─── Schéma : colonnes token sur la table entraîneurs ──────────────────────
	// Colonnes ajoutées directement via ALTER TABLE (pas dbDelta) après vérification
	// SHOW COLUMNS — dbDelta s'est montré peu fiable sur cet hébergement pour ajouter
	// des colonnes à une table existante (cf. doleances.md 08-09/09/2026), donc on
	// évite cette couche intermédiaire pour cette nouvelle fonctionnalité.
	public static function maybe_add_token_columns(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sp_cal_trainers';

		$existing = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		if ( empty( $existing ) ) return; // table pas encore créée (activation du plugin pas encore passée)

		if ( ! in_array( 'token', $existing, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN token varchar(64) NOT NULL DEFAULT ''" );
		}
		if ( ! in_array( 'token_sent_at', $existing, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN token_sent_at datetime DEFAULT NULL" );
		}

		$existing_after = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		foreach ( [ 'token', 'token_sent_at' ] as $col ) {
			if ( ! in_array( $col, $existing_after, true ) ) {
				error_log( "[SP_Build] Colonne '{$col}' absente de {$table} après tentative d'ajout — vérifier les droits ALTER TABLE de l'utilisateur MySQL sur cet hébergement." );
			}
		}
	}

	// ─── Résolution d'un entraîneur depuis son token ───────────────────────────
	private function trainer_by_token( string $token ): ?object {
		if ( $token === '' ) return null;
		global $wpdb;
		$tt = $this->db->table_trainers();
		$el = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tt} WHERE token = %s AND token != ''", $token ) );
		return $el ?: null;
	}

	// ─── Génération + envoi du lien personnel ──────────────────────────────────
	public function generate_and_send( int $trainer_id, bool $force = false ) {
		global $wpdb;
		$tt = $this->db->table_trainers();
		$t  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tt} WHERE id=%d", $trainer_id ) );
		if ( ! $t || empty( $t->email ) ) return false;

		if ( ! $force && $t->token && $t->token_sent_at ) return false;

		$token = $t->token ?: bin2hex( random_bytes( 32 ) );
		$wpdb->update( $tt, [ 'token' => $token, 'token_sent_at' => current_time( 'mysql' ) ], [ 'id' => $trainer_id ] );

		$club    = get_option( 'blogname', 'Club' );
		$app_url = add_query_arg( 'token', $token, trailingslashit( $this->url_app() ) );

		$subject = '[' . $club . '] Vos disponibilités entraîneur';
		$body    = "Bonjour " . ( $t->nom_public ?: $t->nom ) . ",\n\n"
		         . "Voici votre lien personnel pour déclarer vos disponibilités au " . $club . " :\n\n"
		         . $app_url . "\n\n"
		         . "Vous y verrez aussi les disponibilités déjà posées par les autres entraîneurs.\n\n"
		         . "Sur iPhone : bouton Partager puis \"Sur l'écran d'accueil\".\n"
		         . "Sur Android : menu ⋮ de Chrome puis \"Ajouter à l'écran d'accueil\".\n\n"
		         . "Ce lien est personnel — ne pas le partager.\n\n"
		         . "-- \n" . $club;

		return wp_mail( sanitize_email( $t->email ), $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}

	public function ajax_send_link(): void {
		// Bug signalé le 09/09/2026 : le bouton restait bloqué sur "⏳" sans jamais
		// afficher de succès ni d'erreur, et rien n'apparaissait dans debug.log — signe
		// que la requête échouait avant même d'atteindre wp_send_json_*() (l'ancien JS
		// n'avait pas de gestion d'échec réseau/HTTP, donc l'échec restait invisible des
		// deux côtés). Ajout d'un filet PHP (attrape tout Throwable, log explicite) en
		// plus du filet JS (voir class-admin.php) pour obtenir une vraie cause au
		// prochain essai plutôt que de deviner à nouveau.
		try {
			check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Accès refusé', 403 );

			$trainer_id = intval( $_POST['trainer_id'] ?? 0 );
			if ( ! $trainer_id ) wp_send_json_error( 'ID manquant', 400 );

			$sent = $this->generate_and_send( $trainer_id, true );
			if ( $sent ) {
				wp_send_json_success( 'Lien envoyé.' );
			} else {
				wp_send_json_error( 'Échec de l\'envoi (email manquant ou erreur d\'envoi).' );
			}
		} catch ( \Throwable $e ) {
			error_log( '[SP_Build] ajax_send_link() exception : ' . $e->getMessage() . ' — ' . $e->getFile() . ':' . $e->getLine() );
			wp_send_json_error( 'Erreur interne : ' . $e->getMessage() );
		}
	}

	/** URL de la page publique portant le shortcode [sp_cal_dispo_app]. */
	private function url_app(): string {
		global $wpdb;
		$page_id = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE '%[sp_cal_dispo_app%' LIMIT 1"
		);
		return $page_id ? get_permalink( $page_id ) : home_url( '/dispo-entraineurs/' );
	}

	// ─── AJAX : données du mois ─────────────────────────────────────────────────
	public function ajax_get_data(): void {
		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$me    = $this->trainer_by_token( $token );
		if ( ! $me ) wp_send_json_error( 'Lien invalide ou expiré.' );

		$year  = intval( $_POST['year']  ?? date( 'Y' ) );
		$month = intval( $_POST['month'] ?? date( 'n' ) );

		$trainers = $this->db->get_trainers_entraineurs( true );
		$trainers_out = [];
		foreach ( $trainers as $t ) {
			$trainers_out[] = [
				'id'         => intval( $t->id ),
				'nom'        => $t->nom,
				'nom_public' => $t->nom_public ?: $t->nom,
			];
		}

		wp_send_json_success( [
			'me'       => [ 'id' => intval( $me->id ), 'nom_public' => $me->nom_public ?: $me->nom ],
			'trainers' => $trainers_out,
			'dispos'   => $this->db->get_dispos_for_month( $year, $month ),
			'year'     => $year,
			'month'    => $month,
			'cloture'  => SP_Cal_IK_Cloture::get_instance( $this->db )->est_cloture( sprintf( '%04d-%02d', $year, $month ) ),
		] );
	}

	// ─── AJAX : enregistrer SA PROPRE dispo ─────────────────────────────────────
	public function ajax_save(): void {
		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$me    = $this->trainer_by_token( $token );
		if ( ! $me ) wp_send_json_error( 'Lien invalide ou expiré.' );

		$date       = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		$dispo_raw  = $_POST['disponible'] ?? '';
		$note       = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );

		if ( ! $date ) wp_send_json_error( 'Date manquante.' );
		// Mois clôturé (IK déjà payées) : seul un admin peut encore corriger (SP_Cal_IK_Cloture).
		if ( SP_Cal_IK_Cloture::get_instance( $this->db )->est_cloture( $date ) ) {
			wp_send_json_error( 'Ce mois est clôturé (indemnités déjà payées) : pour une correction, adressez-vous au bureau.' );
		}

		$disponible = ( $dispo_raw === '' ) ? null : intval( $dispo_raw );

		// 1 ou 2 allers-retours ; absent = garder la valeur enregistrée (enregistrement de la note)
		$allers_retours = isset( $_POST['allers_retours'] ) && $_POST['allers_retours'] !== '' ? intval( $_POST['allers_retours'] ) : null;
		$avant = $this->db->get_dispos_for_date( $date )[ intval( $me->id ) ]['disponible'] ?? null;

		// Un entraîneur ne peut jamais écrire que sur SA PROPRE ligne — le trainer_id
		// vient du token résolu côté serveur, jamais d'un paramètre envoyé par le client.
		$this->db->save_dispo( intval( $me->id ), $date, $disponible, $note, null, $allers_retours );
		$ar = $this->db->get_dispos_for_date( $date )[ intval( $me->id ) ]['allers_retours'] ?? 1;

		// Bureau prévenu tout de suite si la date est entre J et J+3 (voir notifier_bureau_dispo()).
		( new SpCalPro_Notifications( $this->db ) )->notifier_bureau_dispo( intval( $me->id ), $date, $avant, $disponible, $note, '', $ar );

		wp_send_json_success( [ 'date' => $date, 'disponible' => $disponible, 'note' => $note, 'allers_retours' => $ar ] );
	}

	// ─── Rendu de l'app ──────────────────────────────────────────────────────────
	public function render( $atts ): string {
		$token  = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		$me     = $this->trainer_by_token( $token );
		$sombre = is_array( $atts ) && ( $atts['theme'] ?? '' ) === 'sombre'; // onglet « Mes dispos » de l'application

		ob_start();

		if ( ! $me ) : ?>
			<div style="max-width:420px;margin:40px auto;padding:24px;text-align:center;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
				<p style="font-size:15px;color:#374151;">🔒 Ce lien est invalide ou a expiré.</p>
				<p style="font-size:13px;color:#9ca3af;">Contactez le club pour recevoir un nouveau lien.</p>
			</div>
		<?php
			return ob_get_clean();
		endif;

		$club = esc_html( get_option( 'blogname', 'Club' ) );
		?>
		<div id="sp-dispo-app" class="<?php echo $sombre ? 'sda-sombre' : ''; ?>" data-token="<?php echo esc_attr( $token ); ?>">
			<style>
			#sp-dispo-app { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 480px; margin: 0 auto; color: #111827; }
			#sp-dispo-app * { box-sizing: border-box; }
			.sda-header { background: #1e3a5f; color: #fff; padding: 16px 18px; border-radius: 12px 12px 0 0; }
			.sda-header-club { font-size: 12px; opacity: .7; text-transform: uppercase; letter-spacing: .05em; }
			.sda-header-me { font-size: 17px; font-weight: 700; margin-top: 2px; }
			.sda-nav { display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border: 1px solid #e2e8f0; border-top: none; padding: 10px 14px; }
			.sda-nav button { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; width: 40px; height: 40px; font-size: 18px; cursor: pointer; }
			.sda-nav-title { font-size: 15px; font-weight: 700; text-transform: capitalize; }
			.sda-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; padding: 12px; background: #fff; border: 1px solid #e2e8f0; border-top: none; }
			.sda-dow { text-align: center; font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; padding-bottom: 4px; }
			.sda-cell { position: relative; aspect-ratio: 1; border-radius: 8px; border: 2px solid transparent; background: #f8fafc; display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; font-weight: 600; }
			.sda-cell.sda-empty { background: transparent; cursor: default; }
			.sda-cell.sda-ok   { background: #dcfce7; color: #166534; }
			.sda-cell.sda-ko   { background: #fee2e2; color: #991b1b; }
			.sda-cell.sda-today { border-color: #1e3a5f; }
			.sda-cell.sda-selected { border-color: #f59e0b; }
			.sda-cell-count { font-size: 8px; font-weight: 400; color: #64748b; margin-top: 1px; }
			.sda-panel { background: #fff; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 12px 12px; padding: 16px; }
			.sda-panel-date { font-size: 14px; font-weight: 700; margin-bottom: 10px; text-transform: capitalize; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
			.sda-close { flex-shrink: 0; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 20px; font-size: 14px; font-weight: 700; color: #111827; cursor: pointer; padding: 7px 14px; text-transform: none; font-family: inherit; }
			.sda-team-row { display: flex; align-items: center; gap: 8px; padding: 5px 0; font-size: 13px; }
			.sda-team-avatar { width: 22px; height: 22px; border-radius: 50%; background: #e5e7eb; color: #374151; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
			.sda-team-status { margin-left: auto; font-size: 12px; }
			.sda-me-label { font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin: 14px 0 8px; }
			.sda-btns { display: flex; gap: 8px; }
			.sda-btn { flex: 1; padding: 10px 4px; border-radius: 10px; border: 2px solid #e5e7eb; background: #fff; font-size: 18px; font-weight: 700; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 4px; font-family: inherit; color: inherit; }
			.sda-btn span { font-size: 12px; }
			.sda-btn.sda-btn-ok.sda-active   { background: #16a34a; border-color: #16a34a; color: #fff; }
			.sda-btn.sda-btn-ko.sda-active   { background: #dc2626; border-color: #dc2626; color: #fff; }
			.sda-btn.sda-btn-nr.sda-active   { background: #64748b; border-color: #64748b; color: #fff; }
			.sda-nr-hint { font-size: 12px; color: #6b7280; text-align: center; margin: 8px 0 0; min-height: 0; }
			.sda-nr-hint:empty { display: none; }
			.sda-ar { display: flex; align-items: center; gap: 8px; margin-top: 12px; font-size: 13px; font-weight: 600; }
			.sda-ar span { flex: 1; }
			.sda-ar-btn { width: 44px; height: 40px; border-radius: 10px; border: 2px solid #e5e7eb; background: #fff; font-size: 16px; font-weight: 700; cursor: pointer; font-family: inherit; color: inherit; }
			.sda-ar-btn.sda-active { background: #1e3a5f; border-color: #1e3a5f; color: #fff; }
			.sda-note { width: 100%; margin-top: 10px; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; font-family: inherit; }
			.sda-saved { text-align: center; font-size: 11px; color: #16a34a; height: 16px; margin-top: 6px; }
			.sda-hint { text-align: center; font-size: 12px; color: #9ca3af; padding: 14px; }

			/* Ambiance sombre de l'application entraîneur (onglet « Mes dispos ») — 01/10/2026.
			   Le nom est déjà dans l'en-tête de l'application : pas de bandeau ici. */
			#sp-dispo-app.sda-sombre { color: #fff; max-width: 560px; padding: 12px 12px 24px; }
			.sda-sombre .sda-header { display: none; }
			.sda-sombre .sda-nav { background: #1a1a1a; border: none; border-radius: 12px 12px 0 0; }
			.sda-sombre .sda-nav button { background: #262626; border: none; color: #fff; }
			.sda-sombre .sda-grid { background: #1a1a1a; border: none; }
			.sda-sombre .sda-dow { color: rgba(255,255,255,.35); }
			.sda-sombre .sda-cell { background: #262626; color: rgba(255,255,255,.85); }
			.sda-sombre .sda-cell.sda-empty { background: transparent; }
			.sda-sombre .sda-cell.sda-ok { background: rgba(22,163,74,.28); color: #86efac; }
			.sda-sombre .sda-cell.sda-ko { background: rgba(220,38,38,.28); color: #fca5a5; }
			.sda-sombre .sda-cell.sda-today { border-color: #0f70b7; }
			.sda-sombre .sda-cell-count { color: rgba(255,255,255,.45); }
			.sda-sombre .sda-panel { background: #1a1a1a; border: none; border-top: 1px solid rgba(255,255,255,.06); }
			.sda-sombre .sda-team-avatar { background: #333; color: #fff; }
			.sda-sombre .sda-me-label { color: rgba(255,255,255,.45); }
			.sda-sombre .sda-btn { background: #262626; border-color: #333; color: #fff; }
			.sda-sombre .sda-ar-btn { background: #262626; border-color: #333; color: #fff; }
			.sda-sombre .sda-ar-btn.sda-active { background: #0f70b7; border-color: #0f70b7; }
			.sda-sombre .sda-note { background: #262626; border-color: #333; color: #fff; }
			.sda-sombre .sda-note::placeholder { color: rgba(255,255,255,.35); }
			.sda-sombre .sda-hint, .sda-sombre .sda-nr-hint { color: rgba(255,255,255,.45); }
			.sda-sombre .sda-close { background: #333; border-color: #4b4b4b; color: #fff; }
			</style>

			<div class="sda-header">
				<div class="sda-header-club"><?php echo $club; ?></div>
				<div class="sda-header-me">👋 <?php echo esc_html( $me->nom_public ?: $me->nom ); ?></div>
			</div>
			<div class="sda-nav">
				<button type="button" id="sda-prev">‹</button>
				<div class="sda-nav-title" id="sda-title">—</div>
				<button type="button" id="sda-next">›</button>
			</div>
			<div class="sda-grid" id="sda-grid"></div>
			<div class="sda-panel" id="sda-panel">
				<p class="sda-hint">Touchez un jour pour voir ou déclarer une disponibilité.</p>
			</div>
		</div>

		<script>
		(function(){
			var root  = document.getElementById('sp-dispo-app');
			var token = root.getAttribute('data-token');
			var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

			var state = {
				year: new Date().getFullYear(),
				month: new Date().getMonth() + 1,
				me: null, trainers: [], dispos: {}, cloture: false,
				selected: null
			};

			var MOIS = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
			var JOURS = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];

			function pad(n){ return n < 10 ? '0' + n : '' + n; }
			function todayStr(){ var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }

			function post(action, data, cb) {
				var body = new URLSearchParams(Object.assign({ action: action, token: token }, data));
				fetch(ajaxUrl, { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() })
					.then(function(r){ return r.json(); })
					.then(function(res){ cb(res); })
					.catch(function(){ cb({ success: false }); });
			}

			function loadMonth() {
				document.getElementById('sda-title').textContent = MOIS[state.month-1] + ' ' + state.year;
				post('sp_cal_dispo_app_data', { year: state.year, month: state.month }, function(res){
					if (!res.success) {
						document.getElementById('sda-grid').innerHTML = '';
						document.getElementById('sda-panel').innerHTML = '<p class="sda-hint">⚠️ ' + (res.data || 'Erreur de chargement') + '</p>';
						return;
					}
					state.me       = res.data.me;
					state.trainers = res.data.trainers;
					state.dispos   = res.data.dispos;
					state.cloture  = !!res.data.cloture; // mois clôturé : IK payées, plus de modification ici
					state.selected = null;
					renderGrid();
					renderPanel();
				});
			}

			function renderGrid() {
				var grid = document.getElementById('sda-grid');
				grid.innerHTML = '';
				JOURS.forEach(function(j){
					var el = document.createElement('div');
					el.className = 'sda-dow'; el.textContent = j;
					grid.appendChild(el);
				});

				var first = new Date(state.year, state.month - 1, 1);
				var startOffset = (first.getDay() + 6) % 7; // lundi = 0
				var daysInMonth = new Date(state.year, state.month, 0).getDate();

				for (var i = 0; i < startOffset; i++) {
					var empty = document.createElement('div');
					empty.className = 'sda-cell sda-empty';
					grid.appendChild(empty);
				}

				for (var d = 1; d <= daysInMonth; d++) {
					var ds = state.year + '-' + pad(state.month) + '-' + pad(d);
					var dayDispos = state.dispos[ds] || {};
					var mine = state.me && dayDispos[state.me.id] ? dayDispos[state.me.id].disponible : null;

					var cell = document.createElement('div');
					cell.className = 'sda-cell' + (mine === 1 ? ' sda-ok' : mine === 0 ? ' sda-ko' : '') + (ds === todayStr() ? ' sda-today' : '') + (ds === state.selected ? ' sda-selected' : '');
					cell.setAttribute('data-date', ds);

					var nb = 0;
					state.trainers.forEach(function(t){ if (dayDispos[t.id] && dayDispos[t.id].disponible === 1) nb++; });

					cell.innerHTML = '<span>' + d + '</span>' + (state.trainers.length > 1 ? '<span class="sda-cell-count">' + nb + '/' + state.trainers.length + '</span>' : '');
					cell.addEventListener('click', function(){
						state.selected = this.getAttribute('data-date');
						renderGrid();
						renderPanel();
					});
					grid.appendChild(cell);
				}
			}

			function statusIcon(dispo) {
				if (!dispo) return '<span style="color:#cbd5e1;">⏳</span>';
				if (dispo.disponible === 1) return '<span style="color:#16a34a;">✅' + (dispo.allers_retours === 2 ? ' ×2' : '') + '</span>';
				if (dispo.disponible === 0) return '<span style="color:#dc2626;">❌</span>';
				return '<span style="color:#cbd5e1;">⏳</span>';
			}

			function renderPanel() {
				var panel = document.getElementById('sda-panel');
				if (!state.selected) {
					panel.innerHTML = '<p class="sda-hint">Touchez un jour pour voir ou déclarer une disponibilité.</p>';
					return;
				}
				var ds = state.selected;
				var dayDispos = state.dispos[ds] || {};
				var dObj = new Date(ds + 'T00:00:00');
				var dateLabel = dObj.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });

				var html = '<div class="sda-panel-date"><span>' + dateLabel + '</span><button type="button" class="sda-close" id="sda-close">✕ Fermer</button></div>';

				state.trainers.forEach(function(t){
					var d = dayDispos[t.id];
					var isMe = state.me && t.id === state.me.id;
					html += '<div class="sda-team-row"><span class="sda-team-avatar">' + (t.nom_public||t.nom).charAt(0).toUpperCase() + '</span>'
						+ '<span>' + (t.nom_public||t.nom) + (isMe ? ' (vous)' : '') + '</span>'
						+ '<span class="sda-team-status">' + statusIcon(d) + '</span></div>';
				});

				var mine = dayDispos[state.me.id] || null;
				var val  = mine ? mine.disponible : null; // 1 / 0 / null (neutre = non renseigné, comme ⬜ dans l'admin)
				var ar   = mine && mine.allers_retours === 2 ? 2 : 1;
				if (state.cloture) {
					// Mois clôturé : IK payées, lecture seule (le serveur refuse aussi l'enregistrement).
					html += '<div class="sda-me-label">Votre disponibilité</div>'
						+ '<p class="sda-nr-hint" style="font-size:13px">'
						+ (val === 1 ? '✅ Disponible' + (ar === 2 ? ' · 2 allers-retours' : '') : val === 0 ? '❌ Indisponible' : '⬜ Pas de réponse')
						+ '</p><p class="sda-hint">🔒 Mois clôturé : les indemnités sont déjà payées. Pour une correction, adressez-vous au bureau.</p>';
					panel.innerHTML = html;
					document.getElementById('sda-close').addEventListener('click', function(){
						state.selected = null; renderGrid(); renderPanel();
						if (root.scrollIntoView) root.scrollIntoView({ block: 'start', behavior: 'smooth' });
					});
					return;
				}
				html += '<div class="sda-me-label">Votre disponibilité</div>'
					+ '<div class="sda-btns">'
					+ '<button type="button" class="sda-btn sda-btn-ok' + (val === 1 ? ' sda-active' : '') + '" data-val="1">✅<span>Disponible</span></button>'
					+ '<button type="button" class="sda-btn sda-btn-nr' + (val === null ? ' sda-active' : '') + '" data-val="">⬜<span>Neutre</span></button>'
					+ '<button type="button" class="sda-btn sda-btn-ko' + (val === 0 ? ' sda-active' : '') + '" data-val="0">❌<span>Indisponible</span></button>'
					+ '</div>'
					+ '<p class="sda-nr-hint">' + (val === null ? 'Neutre : pas de réponse, mais vous pouvez être sollicité si besoin.' : '') + '</p>'
					// Deux interventions trop éloignées dans la journée → deux allers-retours (comptés en IK).
					+ (val === 1 ? '<div class="sda-ar"><span>Allers-retours dans la journée</span>'
						+ '<button type="button" class="sda-ar-btn' + (ar === 1 ? ' sda-active' : '') + '" data-ar="1">1</button>'
						+ '<button type="button" class="sda-ar-btn' + (ar === 2 ? ' sda-active' : '') + '" data-ar="2">2</button></div>'
						+ '<p class="sda-nr-hint">' + (ar === 2 ? 'Deux trajets aller-retour ce jour-là (interventions trop éloignées) : comptés tous les deux en IK.' : 'Choisissez 2 si vos deux interventions sont trop éloignées pour rester sur place.') + '</p>' : '')
					+ (val === null ? '' : '<textarea class="sda-note" id="sda-note" rows="2" placeholder="Note (facultatif)">' + (mine && mine.note ? mine.note : '') + '</textarea>')
					+ '<div class="sda-saved" id="sda-saved"></div>';

				panel.innerHTML = html;

				// Enregistrement : l'écran est mis à jour tout de suite, et les envois partent l'un
				// après l'autre dans l'ordre des gestes. Avant, quitter la note (enregistrement de
				// l'ancien état) et toucher « Indisponible » partaient en même temps : l'ancien état
				// pouvait arriver en dernier et l'emporter (retour terrain du 01/10/2026).
				// allers : 1 ou 2 ; non précisé = garder la valeur en cours (le serveur fait de même).
				function save(disponible, rerender, allers) {
					var noteEl = document.getElementById('sda-note');
					var actuel = (state.dispos[ds] || {})[state.me.id] || {};
					var note   = disponible === null ? '' : (noteEl ? noteEl.value : actuel.note || '');
					var a      = disponible === 1 ? (allers || (actuel.allers_retours === 2 ? 2 : 1)) : 1;
					if (!state.dispos[ds]) state.dispos[ds] = {};
					if (disponible === null) delete state.dispos[ds][state.me.id];
					else state.dispos[ds][state.me.id] = { disponible: disponible, note: note, allers_retours: a };
					renderGrid();
					if (rerender) renderPanel();
					queue(ds, disponible, note, allers || '');
				}

				[].forEach.call(panel.querySelectorAll('.sda-btn'), function(b){
					b.addEventListener('click', function(){
						var v = this.getAttribute('data-val');
						save(v === '' ? null : parseInt(v, 10), true);
					});
				});
				[].forEach.call(panel.querySelectorAll('.sda-ar-btn'), function(b){
					b.addEventListener('click', function(){
						save(1, true, parseInt(this.getAttribute('data-ar'), 10));
					});
				});
				// Refermer le jour et revenir au calendrier
				document.getElementById('sda-close').addEventListener('click', function(){
					state.selected = null;
					renderGrid();
					renderPanel();
					if (root.scrollIntoView) root.scrollIntoView({ block: 'start', behavior: 'smooth' });
				});
				var noteEl = document.getElementById('sda-note');
				if (noteEl) noteEl.addEventListener('blur', function(){
					var mine2 = (state.dispos[ds] || {})[state.me.id];
					if (mine2 && this.value !== (mine2.note || '')) save(mine2.disponible, false);
				});
			}

			// File d'envoi : une requête à la fois, dans l'ordre.
			var file = Promise.resolve();
			function queue(ds, disponible, note, allers) {
				file = file.then(function(){
					return new Promise(function(fin){
						post('sp_cal_dispo_app_save', { date: ds, disponible: disponible === null ? '' : disponible, note: note, allers_retours: allers || '' }, function(res){
							var saved = document.getElementById('sda-saved');
							if (saved) {
								saved.textContent = res.success ? '✓ Enregistré' : '⚠️ ' + (typeof res.data === 'string' ? res.data : 'Erreur — réessayez');
								saved.style.color = res.success ? '' : '#dc2626';
								setTimeout(function(){ if (saved) saved.textContent = ''; }, 2000);
							}
							if (!res.success) loadMonth(); // revenir à l'état réellement enregistré
							fin();
						});
					});
				});
			}

			document.getElementById('sda-prev').addEventListener('click', function(){
				state.month--; if (state.month < 1) { state.month = 12; state.year--; }
				loadMonth();
			});
			document.getElementById('sda-next').addEventListener('click', function(){
				state.month++; if (state.month > 12) { state.month = 1; state.year++; }
				loadMonth();
			});

			loadMonth();
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}

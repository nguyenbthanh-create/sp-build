<?php
/**
 * File d'envoi des emails en masse (06/10/2026, EVOLUTION.md « Envoi des emails »).
 *
 * Pourquoi : les envois groupés (rappels de renouvellement, annulations de cours, invitations
 * aux événements…) partaient tous d'un coup — pics qui dégradent la réputation d'envoi chez
 * Gmail, et risque de dépasser le plafond du forfait Brevo gratuit (300 emails / jour, tous
 * emails du site confondus).
 *
 * Principe :
 * - SP_Cal_Mail_Queue::envoyer_lot() reçoit la liste des emails d'une action. Jusqu'à
 *   SEUIL_DIRECT destinataires, ils partent tout de suite, comme avant. Au-delà, ils sont mis
 *   en file et partent par paquets (20 par minute par défaut), le premier paquet aussitôt.
 * - Plafond journalier (250 par défaut, marge sous les 300 de Brevo) : TOUS les emails du site
 *   sont comptés (hook wp_mail_succeeded), y compris ceux qui ne passent pas par la file
 *   (tkd-cotisations, confirmations…). Plafond atteint → la file reprend le lendemain.
 * - Déclenchement sans cron fiable (cf. class-renouvellement.php) : un événement WP-Cron
 *   unique est programmé tant qu'il reste des emails (il part à la visite suivante du site),
 *   ET toute page d'administration ouverte par le bureau fait avancer la file toutes les
 *   minutes (petit script, voir admin_footer).
 * - Un échec est retenté 2 fois (15 puis 30 min plus tard) avant d'être marqué « échec »,
 *   avec la raison donnée par WordPress ; page « 📨 Envois » pour suivre et relancer.
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Mail_Queue {

	private static ?self $instance = null;

	const PAGE           = 'sp-cal-envois';
	const SEUIL_DIRECT   = 10;   // jusqu'à 10 destinataires : envoi immédiat, sans file
	const MAX_TENTATIVES = 3;
	const HOOK_CRON      = 'sp_cal_mailq_tick';
	const SCHEMA         = '1';

	const OPT_SCHEMA     = 'sp_cal_mailq_schema';
	const OPT_PAR_MINUTE = 'sp_cal_mailq_par_minute';  // défaut 20
	const OPT_QUOTA      = 'sp_cal_mailq_quota_jour';  // défaut 250
	const OPT_COMPTEUR   = 'sp_cal_mail_compteur';     // [ 'jour' => Y-m-d, 'n' => int ] — tous les emails du site
	const OPT_DERNIER    = 'sp_cal_mailq_dernier';     // timestamp du dernier paquet
	const OPT_VERROU     = 'sp_cal_mailq_verrou';      // timestamp, un seul paquet à la fois

	public static function get_instance(): self {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ __CLASS__, 'maybe_create_table' ] );
		add_action( 'wp_mail_succeeded', [ __CLASS__, 'compter_envoi' ] );
		add_action( self::HOOK_CRON, [ $this, 'tick_cron' ] );
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
		add_action( 'admin_footer', [ $this, 'script_admin' ] );
		add_action( 'wp_ajax_sp_cal_mailq_tick', [ $this, 'ajax_tick' ] );
		add_action( 'admin_post_sp_cal_mailq_action', [ $this, 'handle_action' ] );
	}

	// ══════════════════════════════════════════════════════════════════════
	// TABLE
	// ══════════════════════════════════════════════════════════════════════

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sp_cal_mail_queue';
	}

	public static function maybe_create_table(): void {
		if ( get_option( self::OPT_SCHEMA ) === self::SCHEMA ) return;
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			dest varchar(255) NOT NULL DEFAULT '',
			sujet text NOT NULL,
			corps longtext NOT NULL,
			entetes text NOT NULL,
			contexte varchar(60) NOT NULL DEFAULT '',
			statut varchar(10) NOT NULL DEFAULT 'attente',
			tentatives tinyint(3) unsigned NOT NULL DEFAULT 0,
			erreur text NULL,
			cree_le datetime NOT NULL,
			essai_apres datetime NOT NULL,
			envoye_le datetime NULL,
			PRIMARY KEY  (id),
			KEY statut_essai (statut, essai_apres)
		) {$wpdb->get_charset_collate()};" );
		// Option marquée à jour seulement si la table existe vraiment (cf. maybe_create_table()
		// de class-front-adhesion.php : un dbDelta() silencieusement raté ne doit pas être oublié).
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::OPT_SCHEMA, self::SCHEMA );
		} else {
			error_log( '[SP_Build] File d\'envoi : création de la table ' . $t . ' impossible — les envois en masse partiront directement.' );
		}
	}

	private static function table_ok(): bool {
		return get_option( self::OPT_SCHEMA ) === self::SCHEMA;
	}

	// ══════════════════════════════════════════════════════════════════════
	// RÉGLAGES ET COMPTEUR
	// ══════════════════════════════════════════════════════════════════════

	public static function par_minute(): int {
		return max( 1, min( 100, intval( get_option( self::OPT_PAR_MINUTE, 20 ) ) ) );
	}

	public static function quota_jour(): int {
		return max( 1, intval( get_option( self::OPT_QUOTA, 250 ) ) );
	}

	/** Emails envoyés aujourd'hui par le site (tous, file ou non). */
	public static function envoyes_aujourdhui(): int {
		$c = get_option( self::OPT_COMPTEUR, [] );
		return ( is_array( $c ) && ( $c['jour'] ?? '' ) === current_time( 'Y-m-d' ) ) ? intval( $c['n'] ?? 0 ) : 0;
	}

	public static function compter_envoi(): void {
		$jour = current_time( 'Y-m-d' );
		$c    = get_option( self::OPT_COMPTEUR, [] );
		$n    = ( is_array( $c ) && ( $c['jour'] ?? '' ) === $jour ) ? intval( $c['n'] ?? 0 ) : 0;
		update_option( self::OPT_COMPTEUR, [ 'jour' => $jour, 'n' => $n + 1 ], false );
	}

	public static function nb_en_attente(): int {
		if ( ! self::table_ok() ) return 0;
		global $wpdb;
		return intval( $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() . " WHERE statut = 'attente'" ) );
	}

	// ══════════════════════════════════════════════════════════════════════
	// ENVOI
	// ══════════════════════════════════════════════════════════════════════

	/**
	 * Envoie les emails d'une action groupée : immédiatement jusqu'à SEUIL_DIRECT destinataires,
	 * sinon par la file (premier paquet tout de suite, le reste étalé).
	 *
	 * @param array  $envois   liste de [ dest, sujet, corps, entetes (array|string, facultatif) ]
	 * @param string $contexte libellé court affiché sur la page Envois (« Annulation par lot »…)
	 * @return array [ 'envoyes' => int (partis maintenant), 'en_file' => int (encore en attente),
	 *                 'echecs' => [ dest => raison ] (envois immédiats ratés) ]
	 */
	public static function envoyer_lot( array $envois, string $contexte = '' ): array {
		$res = [ 'envoyes' => 0, 'en_file' => 0, 'echecs' => [] ];
		if ( ! $envois ) return $res;

		if ( count( $envois ) <= self::SEUIL_DIRECT || ! self::table_ok() ) {
			foreach ( $envois as $e ) {
				$erreur = self::wp_mail_capture( $e[0], $e[1], $e[2], $e[3] ?? '' );
				if ( $erreur === '' ) $res['envoyes']++;
				else $res['echecs'][ $e[0] ] = $erreur;
			}
			return $res;
		}

		global $wpdb;
		$maintenant = current_time( 'mysql' );
		$ids        = [];
		foreach ( $envois as $e ) {
			$ok = $wpdb->insert( self::table(), [
				'dest'        => (string) $e[0],
				'sujet'       => (string) $e[1],
				'corps'       => (string) $e[2],
				'entetes'     => wp_json_encode( array_values( array_filter( (array) ( $e[3] ?? [] ) ) ) ),
				'contexte'    => mb_substr( $contexte, 0, 60 ),
				'statut'      => 'attente',
				'cree_le'     => $maintenant,
				'essai_apres' => $maintenant,
			] );
			if ( $ok ) {
				$ids[] = (int) $wpdb->insert_id;
				continue;
			}
			// Insertion impossible : on n'abandonne pas l'email, il part directement.
			$erreur = self::wp_mail_capture( $e[0], $e[1], $e[2], $e[3] ?? '' );
			if ( $erreur === '' ) $res['envoyes']++;
			else $res['echecs'][ $e[0] ] = $erreur;
		}

		if ( $ids ) {
			self::traiter( true );
			$ids_sql        = implode( ',', $ids );
			$res['envoyes'] += intval( $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() . " WHERE id IN ({$ids_sql}) AND statut = 'envoye'" ) );
			$res['en_file']  = intval( $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() . " WHERE id IN ({$ids_sql}) AND statut = 'attente'" ) );
		}
		return $res;
	}

	/** wp_mail() qui renvoie '' si l'envoi a réussi, sinon la raison donnée par WordPress. */
	private static function wp_mail_capture( string $to, string $sujet, string $corps, $entetes ): string {
		$erreur  = '';
		$capture = static function ( $error ) use ( &$erreur ) {
			if ( is_wp_error( $error ) ) $erreur = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture );
		$ok = wp_mail( $to, $sujet, $corps, $entetes );
		remove_action( 'wp_mail_failed', $capture );
		if ( $ok ) return '';
		return $erreur !== '' ? $erreur : 'wp_mail() a renvoyé false sans détail.';
	}

	/**
	 * Envoie le prochain paquet : au plus par_minute() emails, dans la limite du plafond du jour,
	 * et pas plus d'un paquet par minute (sauf $force, utilisé pour le premier paquet d'un lot
	 * et le bouton « Envoyer maintenant », qui respectent quand même le plafond).
	 *
	 * @return int nombre d'emails envoyés
	 */
	public static function traiter( bool $force = false ): int {
		if ( ! self::table_ok() ) return 0;
		global $wpdb;
		$t = self::table();

		if ( ! $force && ( time() - intval( get_option( self::OPT_DERNIER, 0 ) ) ) < 55 ) {
			self::programmer_suite();
			return 0;
		}
		// Verrou : deux visites simultanées ne doivent pas envoyer le même paquet deux fois.
		// add_option() échoue si l'option existe déjà ; un verrou de plus de 5 min est périmé.
		if ( ! add_option( self::OPT_VERROU, time(), '', false ) ) {
			if ( ( time() - intval( get_option( self::OPT_VERROU, 0 ) ) ) < 5 * MINUTE_IN_SECONDS ) return 0;
			update_option( self::OPT_VERROU, time(), false );
		}

		$envoyes = 0;
		try {
			$place = min( self::par_minute(), self::quota_jour() - self::envoyes_aujourdhui() );
			if ( $place <= 0 ) return 0;

			$lignes = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$t} WHERE statut = 'attente' AND essai_apres <= %s ORDER BY id ASC LIMIT %d",
				current_time( 'mysql' ), $place
			) );
			if ( $lignes ) update_option( self::OPT_DERNIER, time(), false );

			foreach ( $lignes as $l ) {
				$entetes = json_decode( (string) $l->entetes, true );
				$erreur  = self::wp_mail_capture( $l->dest, $l->sujet, $l->corps, is_array( $entetes ) ? $entetes : '' );
				if ( $erreur === '' ) {
					$envoyes++;
					$wpdb->update( $t, [ 'statut' => 'envoye', 'envoye_le' => current_time( 'mysql' ), 'erreur' => null ], [ 'id' => $l->id ] );
					continue;
				}
				$tentatives = intval( $l->tentatives ) + 1;
				$wpdb->update( $t, [
					'tentatives'  => $tentatives,
					'erreur'      => $erreur,
					'statut'      => $tentatives >= self::MAX_TENTATIVES ? 'echec' : 'attente',
					'essai_apres' => wp_date( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS * $tentatives ),
				], [ 'id' => $l->id ] );
				error_log( "[SP_Build] File d'envoi : échec ({$tentatives}/" . self::MAX_TENTATIVES . ") à {$l->dest} — {$erreur}" );
			}
		} finally {
			delete_option( self::OPT_VERROU );
			self::programmer_suite();
		}
		return $envoyes;
	}

	/** Programme le prochain passage WP-Cron tant qu'il reste des emails en attente. */
	private static function programmer_suite(): void {
		if ( wp_next_scheduled( self::HOOK_CRON ) || self::nb_en_attente() === 0 ) return;
		$quand = self::envoyes_aujourdhui() >= self::quota_jour()
			? ( new DateTimeImmutable( 'tomorrow 00:05', wp_timezone() ) )->getTimestamp()
			: time() + MINUTE_IN_SECONDS;
		wp_schedule_single_event( $quand, self::HOOK_CRON );
	}

	public function tick_cron(): void {
		self::traiter();
		self::purger();
	}

	/** Les emails envoyés sont gardés 30 jours (consultation), les échecs 90 jours. */
	private static function purger(): void {
		if ( get_transient( 'sp_cal_mailq_purge' ) ) return;
		set_transient( 'sp_cal_mailq_purge', 1, DAY_IN_SECONDS );
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE statut = 'envoye' AND envoye_le < %s", wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE statut = 'echec' AND cree_le < %s", wp_date( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
	}

	// ══════════════════════════════════════════════════════════════════════
	// ADMINISTRATION
	// ══════════════════════════════════════════════════════════════════════

	private static function peut_gerer(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS );
	}

	/**
	 * Toute page d'administration ouverte par le bureau fait avancer la file, une fois par
	 * minute, tant qu'il reste des emails — c'est le moteur fiable, WP-Cron n'étant qu'un appoint.
	 */
	public function script_admin(): void {
		if ( ! self::peut_gerer() || self::nb_en_attente() === 0 ) return;
		$url   = admin_url( 'admin-ajax.php' );
		$nonce = wp_create_nonce( 'sp_cal_mailq_tick' );
		?>
		<script>
		(function () {
			function tick() {
				var fd = new FormData();
				fd.append('action', 'sp_cal_mailq_tick');
				fd.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);
				fetch(<?php echo wp_json_encode( $url ); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (r) {
						var el = document.getElementById('sp-mailq-restant');
						if (el && r && r.success) el.textContent = r.data.en_attente;
						if (r && r.success && r.data.en_attente > 0) setTimeout(tick, 60000);
					})
					.catch(function () { setTimeout(tick, 60000); });
			}
			setTimeout(tick, 60000);
		})();
		</script>
		<?php
	}

	public function ajax_tick(): void {
		check_ajax_referer( 'sp_cal_mailq_tick', 'nonce' );
		if ( ! self::peut_gerer() ) wp_send_json_error( 'Accès refusé', 403 );
		$envoyes = self::traiter();
		wp_send_json_success( [ 'envoyes' => $envoyes, 'en_attente' => self::nb_en_attente() ] );
	}

	public function register_menu(): void {
		add_submenu_page( 'sp-cal-pro', 'Envois d\'emails', '📨 Envois', SP_Cal_Roles::CAP_GESTION_ADHESIONS, self::PAGE, [ $this, 'render_page' ] );
	}

	public function handle_action(): void {
		if ( ! self::peut_gerer() ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'sp_cal_mailq_action' );
		global $wpdb;
		$t   = self::table();
		$msg = '';
		switch ( sanitize_key( $_POST['faire'] ?? '' ) ) {
			case 'reglages':
				if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
				update_option( self::OPT_PAR_MINUTE, max( 1, min( 100, intval( $_POST['par_minute'] ?? 20 ) ) ) );
				update_option( self::OPT_QUOTA, max( 1, intval( $_POST['quota'] ?? 250 ) ) );
				$msg = 'reglages';
				break;
			case 'envoyer':
				$msg = 'envoye_' . self::traiter( true );
				break;
			case 'reessayer':
				$n   = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET statut = 'attente', tentatives = 0, essai_apres = %s WHERE statut = 'echec'", current_time( 'mysql' ) ) );
				self::programmer_suite();
				$msg = 'reessai_' . intval( $n );
				break;
			case 'supprimer_echecs':
				$n   = $wpdb->query( "DELETE FROM {$t} WHERE statut = 'echec'" );
				$msg = 'suppr_' . intval( $n );
				break;
			case 'vider_attente':
				$n   = $wpdb->query( "DELETE FROM {$t} WHERE statut = 'attente'" );
				$msg = 'vide_' . intval( $n );
				break;
		}
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'msg' => $msg ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private function bouton( string $faire, string $libelle, string $classe = 'button', string $confirm = '' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 6px 0;"'
			. ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>'
			. '<input type="hidden" name="action" value="sp_cal_mailq_action"><input type="hidden" name="faire" value="' . esc_attr( $faire ) . '">'
			. wp_nonce_field( 'sp_cal_mailq_action', '_wpnonce', true, false )
			. '<button type="submit" class="' . esc_attr( $classe ) . '">' . esc_html( $libelle ) . '</button></form>';
	}

	public function render_page(): void {
		if ( ! self::peut_gerer() ) wp_die( 'Accès refusé.' );
		global $wpdb;
		$t = self::table();

		echo '<div class="wrap"><h1>📨 Envois d\'emails</h1>';

		$msg = sanitize_key( $_GET['msg'] ?? '' );
		if ( $msg !== '' ) {
			[ $quoi, $n ] = array_pad( explode( '_', $msg, 2 ), 2, '0' );
			$textes = [
				'reglages' => 'Réglages enregistrés.',
				'envoye'   => intval( $n ) . ' email(s) envoyé(s).',
				'reessai'  => intval( $n ) . ' email(s) remis dans la file.',
				'suppr'    => intval( $n ) . ' échec(s) supprimé(s).',
				'vide'     => intval( $n ) . ' email(s) retiré(s) de la file (ils ne partiront pas).',
			];
			if ( isset( $textes[ $quoi ] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $textes[ $quoi ] ) . '</p></div>';
		}

		if ( ! self::table_ok() ) {
			echo '<div class="notice notice-error"><p>La table de la file d\'envoi n\'existe pas (droits MySQL ?) : les envois en masse partent directement, sans étalement.</p></div></div>';
			return;
		}

		$attente   = self::nb_en_attente();
		$echecs    = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE statut = 'echec'" ) );
		$aujourdhui = self::envoyes_aujourdhui();
		$quota     = self::quota_jour();

		echo '<div class="sp-box" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin:16px 0;max-width:900px;">';
		echo '<p style="font-size:15px;margin:0 0 8px;">⏳ En attente : <strong id="sp-mailq-restant">' . $attente . '</strong>'
			. ' &nbsp;·&nbsp; 📤 Envoyés aujourd\'hui par le site : <strong>' . $aujourdhui . ' / ' . $quota . '</strong>'
			. ' &nbsp;·&nbsp; ❌ Échecs : <strong>' . $echecs . '</strong></p>';
		if ( $attente > 0 && $aujourdhui >= $quota ) {
			echo '<p style="color:#b45309;margin:0 0 8px;">Plafond du jour atteint : la file reprendra demain matin.</p>';
		} elseif ( $attente > 0 ) {
			echo '<p style="color:#475569;margin:0 0 8px;">Fin estimée dans environ ' . ceil( $attente / self::par_minute() ) . ' minute(s) — la file avance tant qu\'une page d\'administration est ouverte, ou à chaque visite du site.</p>';
		}
		echo '<p style="color:#475569;margin:0 0 12px;">Les envois groupés de plus de ' . self::SEUIL_DIRECT . ' destinataires (rappels de renouvellement, annulations de cours, invitations…) passent par cette file : '
			. self::par_minute() . ' emails par minute au plus, et ' . $quota . ' par jour au plus pour tout le site (forfait Brevo gratuit : 300 par jour). Le journal détaillé de chaque email est dans Brevo → Transactionnel → Logs.</p>';
		if ( $attente > 0 ) {
			echo $this->bouton( 'envoyer', '▶️ Envoyer le prochain paquet maintenant', 'button button-primary' );
			echo $this->bouton( 'vider_attente', '🗑️ Annuler les envois en attente', 'button', 'Retirer les ' . $attente . ' email(s) en attente ? Ils ne partiront pas.' );
		}
		if ( $echecs > 0 ) {
			echo $this->bouton( 'reessayer', '🔁 Réessayer les échecs' );
			echo $this->bouton( 'supprimer_echecs', '🗑️ Supprimer les échecs', 'button', 'Supprimer définitivement les ' . $echecs . ' échec(s) de la liste ?' );
		}
		echo '</div>';

		if ( current_user_can( 'manage_options' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 20px;">';
			echo '<input type="hidden" name="action" value="sp_cal_mailq_action"><input type="hidden" name="faire" value="reglages">';
			wp_nonce_field( 'sp_cal_mailq_action' );
			echo '<label>Emails par minute <input type="number" name="par_minute" min="1" max="100" value="' . self::par_minute() . '" style="width:70px;"></label> &nbsp; ';
			echo '<label>Plafond par jour (tout le site) <input type="number" name="quota" min="1" value="' . $quota . '" style="width:80px;"></label> &nbsp; ';
			echo '<button type="submit" class="button">Enregistrer</button></form>';
		}

		$this->tableau( 'En attente', $wpdb->get_results( "SELECT * FROM {$t} WHERE statut = 'attente' ORDER BY id ASC LIMIT 100" ), 'attente' );
		$this->tableau( 'Échecs', $wpdb->get_results( "SELECT * FROM {$t} WHERE statut = 'echec' ORDER BY id DESC LIMIT 100" ), 'echec' );
		$this->tableau( 'Derniers envoyés par la file', $wpdb->get_results( "SELECT * FROM {$t} WHERE statut = 'envoye' ORDER BY envoye_le DESC LIMIT 30" ), 'envoye' );
		echo '</div>';
	}

	private function tableau( string $titre, array $lignes, string $statut ): void {
		if ( ! $lignes ) return;
		$fmt = static fn( $d ) => $d ? mysql2date( 'd/m H:i', $d ) : '';
		echo '<h2 style="margin-top:24px;">' . esc_html( $titre ) . ' (' . count( $lignes ) . ( count( $lignes ) >= 100 ? '+' : '' ) . ')</h2>';
		echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr><th>Destinataire</th><th>Sujet</th><th>Action</th><th>Créé</th>';
		echo $statut === 'envoye' ? '<th>Envoyé</th>' : '<th>Tentatives</th><th>' . ( $statut === 'attente' ? 'Prochain essai' : 'Raison' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $lignes as $l ) {
			echo '<tr><td>' . esc_html( $l->dest ) . '</td><td>' . esc_html( mb_strimwidth( $l->sujet, 0, 90, '…' ) ) . '</td><td>' . esc_html( $l->contexte ) . '</td><td>' . esc_html( $fmt( $l->cree_le ) ) . '</td>';
			if ( $statut === 'envoye' ) {
				echo '<td>' . esc_html( $fmt( $l->envoye_le ) ) . '</td>';
			} else {
				echo '<td>' . intval( $l->tentatives ) . ' / ' . self::MAX_TENTATIVES . '</td>';
				echo '<td>' . esc_html( $statut === 'attente' ? ( $l->tentatives ? $fmt( $l->essai_apres ) . ' — ' . $l->erreur : '—' ) : (string) $l->erreur ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}

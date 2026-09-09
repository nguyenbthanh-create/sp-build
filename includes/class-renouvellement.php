<?php
/**
 * Renouvellement de saison — campagne annuelle.
 * Voir md/06-renouvellement-saison.md pour la spécification complète.
 *
 * Toutes les étapes sont déclenchées manuellement par le bureau (bouton) — aucun cron.
 * L'ancienne version s'appuyait sur le cron interne de WordPress (wp-cron) pour envoyer
 * les relances et déclencher la désactivation automatiquement après un délai. Abandonné
 * le 08/09/2026 (cf. doléances.md) : wp-cron ne se déclenche que lors d'une visite du
 * site après l'heure prévue, pas de façon garantie — inacceptable pour une action aussi
 * impactante que la désactivation de comptes sur un site à faible trafic. Le bandeau
 * d'alerte "X jours depuis la relance" est calculé à chaque chargement de la page admin
 * (aucune dépendance au cron non plus).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Renouvellement {

	private static ?self $instance = null;
	private $db;

	public static function get_instance( $db = null ): self {
		if ( self::$instance === null ) self::$instance = new self( $db );
		return self::$instance;
	}

	private function __construct( $db = null ) {
		$this->db = $db;

		add_action( 'admin_post_sp_renouv_lancer',         [ $this, 'handle_lancer' ] );
		add_action( 'admin_post_sp_renouv_annuler',        [ $this, 'handle_annuler' ] );
		add_action( 'admin_post_sp_renouv_relance',        [ $this, 'handle_relance' ] );
		add_action( 'admin_post_sp_renouv_relancer_un',    [ $this, 'handle_relancer_un' ] );
		add_action( 'admin_post_sp_renouv_desactiver_lot', [ $this, 'handle_desactiver_lot' ] );
		add_action( 'admin_post_sp_renouv_desactiver_un',  [ $this, 'handle_desactiver_un' ] );

		// Nettoyage de l'ancien cron s'il est encore programmé sur ce site (voir note en tête de fichier).
		if ( wp_next_scheduled( 'sp_cal_renouv_daily_check' ) ) {
			wp_clear_scheduled_hook( 'sp_cal_renouv_daily_check' );
		}
	}

	// ─── Helpers d'options ──────────────────────────────────────────────────
	private function en_cours(): bool {
		return get_option( 'sp_cal_renouv_en_cours', '0' ) === '1';
	}

	private function table_eleves(): string {
		global $wpdb;
		return $this->db ? $this->db->table_eleves() : $wpdb->prefix . 'sp_cal_eleves';
	}

	// ─── Détection de la saison en cours parmi les actifs ─────────────────────
	private function detecter_saison_source(): string {
		global $wpdb;
		$tel = $this->table_eleves();
		return (string) $wpdb->get_var(
			"SELECT saison FROM {$tel} WHERE actif = 1 AND saison != '' GROUP BY saison ORDER BY COUNT(*) DESC LIMIT 1"
		);
	}

	private function deviner_saison_cible( string $source ): string {
		if ( preg_match( '#^(\d{4})/(\d{4})$#', $source, $m ) ) {
			return ( (int) $m[1] + 1 ) . '/' . ( (int) $m[2] + 1 );
		}
		return '';
	}

	/** Adhérents encore sur l'ancienne saison (= n'ont pas renouvelé). */
	private function get_non_renouveles(): array {
		global $wpdb;
		$tel    = $this->table_eleves();
		$source = get_option( 'sp_cal_renouv_saison_source', '' );
		if ( $source === '' ) return [];
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tel} WHERE actif = 1 AND saison = %s ORDER BY categorie_age ASC, nom ASC",
			$source
		) );
	}

	// ══════════════════════════════════════════════════════════════════════
	// LANCEMENT / ANNULATION
	// ══════════════════════════════════════════════════════════════════════
	public function handle_lancer(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		if ( ! check_admin_referer( 'sp_renouv_lancer' ) ) wp_die( 'Nonce invalide.' );

		$saison_cible = sanitize_text_field( $_POST['renouv_saison_cible'] ?? '' );
		if ( $saison_cible === '' ) {
			wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&renouv_erreur=saison_manquante' ) );
			exit;
		}

		$saison_source = $this->detecter_saison_source();

		update_option( 'sp_cal_renouv_en_cours',       '1' );
		update_option( 'sp_cal_renouv_saison_source',  $saison_source );
		update_option( 'sp_cal_renouv_saison_cible',   $saison_cible );
		update_option( 'sp_cal_renouv_date_lancement', current_time( 'Y-m-d' ) );
		update_option( 'sp_cal_renouv_alerte_jours',   max( 1, intval( $_POST['renouv_alerte_jours'] ?? 15 ) ) );
		update_option( 'sp_cal_renouv_relance_le',     '' );

		// Rappel envoyé immédiatement à chaque adhérent concerné, SANS désactiver son compte
		// (accès inchangé) — décision du 08/09/2026 (cf. doléances.md) : le lancement doit
		// directement toucher les adhérents, la désactivation restant une étape séparée et
		// volontaire, déclenchée plus tard depuis le tableau de bord.
		$eleves = $this->get_non_renouveles();
		$envoyes = 0;
		foreach ( $eleves as $el ) {
			if ( $this->envoyer_rappel_lancement( $el ) ) $envoyes++;
		}
		$sans_email = count( $eleves ) - $envoyes;

		wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&renouv_lance=' . $envoyes . '&renouv_lance_sans_email=' . $sans_email ) );
		exit;
	}

	/** Rappel de renouvellement envoyé au lancement — n'affecte pas l'accès (actif inchangé). */
	private function envoyer_rappel_lancement( object $el ): bool {
		$dest = $el->email_parent ?: $el->email;
		if ( ! $dest || ! is_email( $dest ) ) return false;

		global $wpdb;
		$tel = $this->table_eleves();

		// S'assurer qu'un token existe pour construire le lien de renouvellement
		$token = $el->token;
		if ( ! $token ) {
			$token = bin2hex( random_bytes( 32 ) );
			$wpdb->update( $tel, [ 'token' => $token ], [ 'id' => $el->id ] );
		}

		$club          = get_bloginfo( 'name' );
		$cible         = get_option( 'sp_cal_renouv_saison_cible', '' );
		$page_adhesion = $this->url_formulaire_adhesion();
		$lien          = $page_adhesion ? add_query_arg( 'renouv', $token, $page_adhesion ) : '';

		$subject = "[{$club}] La nouvelle saison a commencé — pensez à renouveler votre adhésion";
		$body    = "Bonjour {$el->prenom},\n\n"
		         . "La nouvelle saison" . ( $cible ? " {$cible}" : '' ) . " a commencé au {$club}. Merci de renouveler votre adhésion dès que possible.\n\n"
		         . ( $lien
		             ? "Vos informations sont déjà pré-remplies, il ne reste qu'à les vérifier et les compléter si besoin :\n{$lien}\n\n"
		             : "Contactez le club pour renouveler votre adhésion.\n\n" )
		         . "Votre accès à l'espace personnel reste actif en attendant — pas d'inquiétude à avoir.\n\n"
		         . "À bientôt sur les tatamis !\n— L'équipe {$club}";

		return wp_mail( $dest, $subject, $body );
	}

	public function handle_annuler(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		if ( ! check_admin_referer( 'sp_renouv_annuler' ) ) wp_die( 'Nonce invalide.' );

		update_option( 'sp_cal_renouv_en_cours', '0' );

		wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&renouv_annule=1' ) );
		exit;
	}

	// ══════════════════════════════════════════════════════════════════════
	// RELANCE BUREAU — bouton à effet immédiat, au moment choisi par le bureau
	// ══════════════════════════════════════════════════════════════════════
	public function handle_relance(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		if ( ! check_admin_referer( 'sp_renouv_relance' ) ) wp_die( 'Nonce invalide.' );
		if ( ! $this->en_cours() ) wp_die( 'Aucune campagne en cours.' );

		$this->envoyer_relance_bureau();
		update_option( 'sp_cal_renouv_relance_le', current_time( 'Y-m-d' ) );

		wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&renouv_relance_envoyee=1' ) );
		exit;
	}

	/**
	 * Relance individuelle — même rappel que le lancement (n'affecte pas l'accès), mais pour
	 * un seul adhérent. Sert notamment quand un email a été renseigné après coup sur la fiche
	 * pour un adhérent qui n'en avait pas au moment du lancement (cf. doléances.md 09/09/2026) :
	 * évite d'avoir à ré-envoyer le rappel à toute la liste pour rattraper un seul cas.
	 */
	public function handle_relancer_un(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		if ( ! check_admin_referer( 'sp_renouv_relancer_un' ) ) wp_die( 'Nonce invalide.' );

		global $wpdb;
		$eleve_id = intval( $_GET['eleve_id'] ?? 0 );
		$tel      = $this->table_eleves();
		$el       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tel} WHERE id = %d", $eleve_id ) );

		$ok = $el ? $this->envoyer_rappel_lancement( $el ) : false;

		wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&renouv_relance_un=' . ( $ok ? '1' : '0' ) ) );
		exit;
	}

	private function envoyer_relance_bureau(): void {
		if ( ! $this->db ) return;
		$bureau = $this->db->get_bureau_members();
		if ( empty( $bureau ) ) return;

		$eleves = $this->get_non_renouveles();
		$club   = get_bloginfo( 'name' );
		$cible  = get_option( 'sp_cal_renouv_saison_cible', '' );

		$subject = "[{$club}] 🔄 Relance renouvellement de saison ({$cible})";

		$body  = "Bonjour,\n\n";
		$body .= "Le renouvellement pour la saison {$cible} est en cours. "
		       . count( $eleves ) . " adhérent(s) n'ont pas encore renouvelé :\n\n";
		foreach ( $eleves as $el ) {
			$body .= "  - {$el->prenom} {$el->nom} ({$el->categorie_age})\n";
		}
		$body .= "\nVous pouvez les relancer directement (téléphone, au club...), ou désactiver leurs "
		       . "comptes — individuellement ou en une fois — depuis la page Adhésions : ça leur envoie "
		       . "automatiquement le lien de renouvellement pré-rempli.\n\n";
		$body .= "Voir la page Adhésions : " . admin_url( 'admin.php?page=sp-cal-licences' ) . "\n\n";
		$body .= "-- \n{$club} (notification manuelle)";

		foreach ( $bureau as $m ) {
			if ( ! $m->email || ! is_email( $m->email ) ) continue;
			wp_mail( $m->email, $subject, $body );
		}
	}

	// ══════════════════════════════════════════════════════════════════════
	// DÉSACTIVATION — déclenchement manuel, en lot ou individuel
	// ══════════════════════════════════════════════════════════════════════
	public function handle_desactiver_lot(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		if ( ! check_admin_referer( 'sp_renouv_desactiver_lot' ) ) wp_die( 'Nonce invalide.' );

		$eleves = $this->get_non_renouveles();
		foreach ( $eleves as $el ) {
			$this->desactiver_et_notifier_un( $el );
		}

		wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&renouv_desactive=' . count( $eleves ) ) );
		exit;
	}

	public function handle_desactiver_un(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		if ( ! check_admin_referer( 'sp_renouv_desactiver_un' ) ) wp_die( 'Nonce invalide.' );

		global $wpdb;
		$eleve_id = intval( $_GET['eleve_id'] ?? 0 );
		$tel      = $this->table_eleves();
		$el       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tel} WHERE id = %d", $eleve_id ) );

		if ( $el ) $this->desactiver_et_notifier_un( $el );

		wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&renouv_desactive=1' ) );
		exit;
	}

	/** Désactive un élève (jamais de suppression) et lui envoie le lien de renouvellement. */
	private function desactiver_et_notifier_un( object $el ): void {
		global $wpdb;
		$tel = $this->table_eleves();

		$wpdb->update( $tel,
			[ 'actif' => 0, 'motif_inactif' => 'Non renouvelé — saison écoulée' ],
			[ 'id' => $el->id ]
		);

		// S'assurer qu'un token existe pour construire le lien de renouvellement
		$token = $el->token;
		if ( ! $token ) {
			$token = bin2hex( random_bytes( 32 ) );
			$wpdb->update( $tel, [ 'token' => $token ], [ 'id' => $el->id ] );
		}

		$dest = $el->email_parent ?: $el->email;
		if ( ! $dest || ! is_email( $dest ) ) return;

		$club          = get_bloginfo( 'name' );
		$page_adhesion = $this->url_formulaire_adhesion();
		$lien          = $page_adhesion ? add_query_arg( 'renouv', $token, $page_adhesion ) : '';

		$subject = "[{$club}] Pensez à renouveler votre adhésion";
		$body    = "Bonjour {$el->prenom},\n\n"
		         . "La nouvelle saison a commencé et votre adhésion au {$club} n'a pas encore été renouvelée. "
		         . "Votre accès à l'espace personnel a été temporairement désactivé — vos informations restent bien sûr conservées.\n\n"
		         . ( $lien
		             ? "Pour renouveler, cliquez sur ce lien : vos informations sont déjà pré-remplies, il ne reste qu'à les vérifier et les compléter si besoin.\n{$lien}\n\n"
		             : "Contactez le club pour renouveler votre adhésion.\n\n" )
		         . "À très bientôt sur les tatamis !\n— L'équipe {$club}";

		wp_mail( $dest, $subject, $body );
	}

	/** URL de la page publique portant le shortcode [sp_inscription_adhesion]. */
	private function url_formulaire_adhesion(): string {
		global $wpdb;
		$page_id = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE '%[sp_inscription_adhesion%' LIMIT 1"
		);
		return $page_id ? get_permalink( $page_id ) : '';
	}

	// ══════════════════════════════════════════════════════════════════════
	// TABLEAU DE BORD (appelé depuis page_licences())
	// ══════════════════════════════════════════════════════════════════════
	public function render_box(): void {
		if ( isset( $_GET['renouv_lance'] ) ) {
			$n           = intval( $_GET['renouv_lance'] );
			$sans_email  = intval( $_GET['renouv_lance_sans_email'] ?? 0 );
			$msg = '✅ Renouvellement de saison lancé — rappel envoyé à ' . $n . ' adhérent(s) (comptes non désactivés).';
			if ( $sans_email > 0 ) {
				$msg .= ' ⚠️ <strong>' . $sans_email . '</strong> adhérent(s) n\'ont pas reçu ce rappel faute d\'email renseigné — repérables ci-dessous (badge "pas d\'email"), à compléter puis relancer individuellement.';
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
		}
		if ( isset( $_GET['renouv_annule'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>⚠️ Campagne de renouvellement annulée.</p></div>';
		}
		if ( isset( $_GET['renouv_erreur'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>❌ Merci de renseigner la saison cible.</p></div>';
		}
		if ( isset( $_GET['renouv_relance_envoyee'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>✅ Relance envoyée au bureau.</p></div>';
		}
		if ( isset( $_GET['renouv_desactive'] ) ) {
			$n = intval( $_GET['renouv_desactive'] );
			echo '<div class="notice notice-success is-dismissible"><p>✅ ' . $n . ' compte(s) désactivé(s) — lien de renouvellement envoyé.</p></div>';
		}
		if ( isset( $_GET['renouv_relance_un'] ) ) {
			if ( $_GET['renouv_relance_un'] === '1' ) {
				echo '<div class="notice notice-success is-dismissible"><p>✅ Rappel envoyé à cet adhérent.</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>❌ Impossible d\'envoyer le rappel — vérifiez qu\'un email valide est renseigné sur sa fiche.</p></div>';
			}
		}

		echo '<div class="sp-box" style="margin-bottom:20px;">';
		echo '<h2>🔄 Renouvellement de saison</h2>';

		if ( $this->en_cours() ) {
			$this->render_dashboard();
		} else {
			$this->render_formulaire_lancement();
		}

		echo '</div>';
	}

	private function render_formulaire_lancement(): void {
		$source = $this->detecter_saison_source();
		$cible  = $this->deviner_saison_cible( $source );

		global $wpdb;
		$tel   = $this->table_eleves();
		$count = $source ? (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tel} WHERE actif = 1 AND saison = %s", $source
		) ) : 0;
		?>
		<p class="description">
			Déclenche la campagne de renouvellement (voir <code>md/06-renouvellement-saison.md</code>) :
			<strong>envoie immédiatement</strong> à chaque adhérent concerné un rappel avec son lien de
			renouvellement pré-rempli (leur accès reste actif, rien n'est désactivé à ce stade). La désactivation
			reste une étape séparée et volontaire, à déclencher plus tard depuis le tableau de bord — jamais de
			suppression, jamais d'action automatique déclenchée toute seule.
		</p>
		<?php if ( $source ) : ?>
			<p><strong><?php echo $count; ?></strong> adhérent(s) actuellement actif(s) sur la saison <strong><?php echo esc_html( $source ); ?></strong> recevraient ce rappel immédiatement en cliquant "Lancer" ci-dessous.</p>
		<?php else : ?>
			<p class="sp-muted">Aucune saison active détectée pour l'instant.</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
			<input type="hidden" name="action" value="sp_renouv_lancer">
			<?php wp_nonce_field( 'sp_renouv_lancer' ); ?>
			<div>
				<label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Saison cible</label>
				<input type="text" name="renouv_saison_cible" value="<?php echo esc_attr( $cible ); ?>" placeholder="2026/2027"
				       style="height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;">
			</div>
			<div>
				<label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Bandeau d'alerte désactivation — jours après le lancement</label>
				<input type="number" name="renouv_alerte_jours" value="15" min="1" max="120" style="height:32px;width:80px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;">
			</div>
			<button type="submit" class="button button-primary"
			        onclick="return confirm('Lancer la campagne de renouvellement maintenant ?');">
				🚀 Lancer le renouvellement
			</button>
		</form>
		<?php
	}

	private function render_dashboard(): void {
		$source       = get_option( 'sp_cal_renouv_saison_source', '' );
		$cible        = get_option( 'sp_cal_renouv_saison_cible', '' );
		$lancement    = get_option( 'sp_cal_renouv_date_lancement', '' );
		$relance_le   = get_option( 'sp_cal_renouv_relance_le', '' );
		$alerte_jours = max( 1, intval( get_option( 'sp_cal_renouv_alerte_jours', 15 ) ) );
		$non_renouv   = $this->get_non_renouveles();
		$restants     = count( $non_renouv );
		?>
		<p>
			Campagne en cours : <strong><?php echo esc_html( $source ); ?> → <?php echo esc_html( $cible ); ?></strong>
			(lancée le <?php echo esc_html( date( 'd/m/Y', strtotime( $lancement ) ) ); ?>)
		</p>

		<?php
		// Bandeau d'alerte — calculé à chaque chargement de cette page, aucune dépendance au cron
		// (cf. note en tête de fichier). Basé sur la date de LANCEMENT (pas sur la relance bureau,
		// optionnelle et pas toujours utilisée) puisque les adhérents sont notifiés dès le lancement.
		if ( $lancement !== '' ) :
			$jours_ecoules = intval( floor( ( strtotime( 'today' ) - strtotime( $lancement ) ) / DAY_IN_SECONDS ) );
			if ( $jours_ecoules >= $alerte_jours && $restants > 0 ) : ?>
				<div class="notice notice-warning inline" style="padding:12px 16px;margin:12px 0;">
					<p style="margin:0;">⚠️ Il y a <strong><?php echo $jours_ecoules; ?> jours</strong> que la campagne a été lancée —
					<strong><?php echo $restants; ?></strong> adhérent(s) n'ont toujours pas renouvelé malgré le rappel envoyé.
					Vous pouvez désactiver leurs comptes ci-dessous (en lot ou individuellement).</p>
				</div>
			<?php endif;
		endif; ?>

		<ul style="margin-left:20px;list-style:disc;">
			<li>Relance bureau : <?php echo $relance_le ? '✅ envoyée le ' . esc_html( date( 'd/m/Y', strtotime( $relance_le ) ) ) : '⏳ pas encore envoyée'; ?></li>
			<li><strong><?php echo $restants; ?></strong> adhérent(s) encore sur l'ancienne saison à ce jour</li>
		</ul>

		<div style="margin-bottom:16px;">
			<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sp_renouv_relance">
					<?php wp_nonce_field( 'sp_renouv_relance' ); ?>
					<button type="submit" class="button button-primary">
						📧 Envoyer la relance au bureau
					</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sp_renouv_annuler">
					<?php wp_nonce_field( 'sp_renouv_annuler' ); ?>
					<button type="submit" class="button button-secondary"
					        onclick="return confirm('Annuler la campagne en cours ? Les comptes déjà désactivés ne seront pas réactivés automatiquement.');">
						Annuler la campagne
					</button>
				</form>
			</div>
			<p class="description" style="margin-top:6px;">
				ℹ️ Ce bouton n'envoie rien aux adhérents (déjà notifiés au lancement) — uniquement la liste des non-renouvelés au bureau, pour relance manuelle (téléphone, au club...).
			</p>
		</div>

		<?php if ( $restants > 0 ) : ?>
		<div class="sp-box" style="background:#fafafa;border:1px solid #e2e8f0;">
			<h3 style="margin-top:0;">🔒 Désactivation des comptes non renouvelés</h3>
			<p class="description">Pour ceux qui n'ont toujours pas renouvelé malgré le rappel du lancement. Désactive l'accès à l'espace personnel (jamais de suppression) et envoie à l'adhérent un nouvel email avec le lien de renouvellement pré-rempli.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:14px;">
				<input type="hidden" name="action" value="sp_renouv_desactiver_lot">
				<?php wp_nonce_field( 'sp_renouv_desactiver_lot' ); ?>
				<button type="submit" class="button" style="background:#b91c1c;color:#fff;border-color:#b91c1c;"
				        onclick="return confirm('Désactiver les <?php echo $restants; ?> comptes non renouvelés et leur envoyer le lien de renouvellement ?');">
					🔒 Oui, tout désactiver (<?php echo $restants; ?>)
				</button>
			</form>

			<table class="wp-list-table widefat striped">
				<thead><tr><th>Nom</th><th>Catégorie</th><th>Email</th><th style="width:220px;">Action</th></tr></thead>
				<tbody>
				<?php foreach ( $non_renouv as $el ) :
					$dest        = $el->email_parent ?: $el->email;
					$a_email     = $dest && is_email( $dest );
					$url_desact  = wp_nonce_url(
						admin_url( 'admin-post.php?action=sp_renouv_desactiver_un&eleve_id=' . intval( $el->id ) ),
						'sp_renouv_desactiver_un'
					);
					$url_relance = wp_nonce_url(
						admin_url( 'admin-post.php?action=sp_renouv_relancer_un&eleve_id=' . intval( $el->id ) ),
						'sp_renouv_relancer_un'
					);
				?>
					<tr>
						<td><?php echo esc_html( $el->prenom . ' ' . $el->nom ); ?></td>
						<td><?php echo esc_html( $el->categorie_age ); ?></td>
						<td>
							<?php if ( $a_email ) : ?>
								<?php echo esc_html( $dest ); ?>
							<?php else : ?>
								<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;">⚠️ pas d'email</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $a_email ) : ?>
							<a href="<?php echo esc_url( $url_relance ); ?>" class="button button-small"
							   onclick="return confirm('Renvoyer le rappel de renouvellement à <?php echo esc_js( $el->prenom . ' ' . $el->nom ); ?> (sans désactiver son compte) ?');">
								📧 Relancer
							</a>
							<?php else : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=sp-cal-eleves&sp_edit_eleve=' . intval( $el->id ) ) ); ?>" class="button button-small" title="Ajouter un email sur la fiche pour pouvoir le relancer">
								✏️ Compléter la fiche
							</a>
							<?php endif; ?>
							<a href="<?php echo esc_url( $url_desact ); ?>" class="button button-small"
							   onclick="return confirm('Désactiver <?php echo esc_js( $el->prenom . ' ' . $el->nom ); ?> et lui envoyer le lien de renouvellement ?');">
								Désactiver
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		<?php
	}
}

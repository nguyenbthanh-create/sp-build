<?php
/**
 * Renouvellement de saison — campagne annuelle.
 * Voir md/06-renouvellement-saison.md pour la spécification complète.
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

		add_action( 'admin_post_sp_renouv_lancer',  [ $this, 'handle_lancer' ] );
		add_action( 'admin_post_sp_renouv_annuler', [ $this, 'handle_annuler' ] );
		add_action( 'sp_cal_renouv_daily_check',    [ $this, 'check_daily' ] );

		if ( ! wp_next_scheduled( 'sp_cal_renouv_daily_check' ) ) {
			wp_schedule_event( strtotime( 'today 08:00:00' ), 'daily', 'sp_cal_renouv_daily_check' );
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
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
		if ( ! check_admin_referer( 'sp_renouv_lancer' ) ) wp_die( 'Nonce invalide.' );

		$saison_cible = sanitize_text_field( $_POST['renouv_saison_cible'] ?? '' );
		if ( $saison_cible === '' ) {
			wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&renouv_erreur=saison_manquante' ) );
			exit;
		}

		$saison_source = $this->detecter_saison_source();

		update_option( 'sp_cal_renouv_en_cours',          '1' );
		update_option( 'sp_cal_renouv_saison_source',     $saison_source );
		update_option( 'sp_cal_renouv_saison_cible',      $saison_cible );
		update_option( 'sp_cal_renouv_date_lancement',    current_time( 'Y-m-d' ) );
		update_option( 'sp_cal_renouv_delai_notif1_jours', max( 1, intval( $_POST['renouv_delai1'] ?? 21 ) ) );
		update_option( 'sp_cal_renouv_delai_notif2_jours', max( 1, intval( $_POST['renouv_delai2'] ?? 45 ) ) );
		update_option( 'sp_cal_renouv_notif1_le', '' );
		update_option( 'sp_cal_renouv_notif2_le', '' );

		wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&renouv_lance=1' ) );
		exit;
	}

	public function handle_annuler(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
		if ( ! check_admin_referer( 'sp_renouv_annuler' ) ) wp_die( 'Nonce invalide.' );

		update_option( 'sp_cal_renouv_en_cours', '0' );

		wp_redirect( admin_url( 'admin.php?page=sp-cal-licences&renouv_annule=1' ) );
		exit;
	}

	// ══════════════════════════════════════════════════════════════════════
	// CRON QUOTIDIEN
	// ══════════════════════════════════════════════════════════════════════
	public function check_daily(): void {
		if ( ! $this->en_cours() ) return;

		$lancement = get_option( 'sp_cal_renouv_date_lancement', '' );
		if ( $lancement === '' ) return;

		$jours = intval( floor( ( strtotime( 'today' ) - strtotime( $lancement ) ) / DAY_IN_SECONDS ) );

		$delai1     = intval( get_option( 'sp_cal_renouv_delai_notif1_jours', 21 ) );
		$delai2     = intval( get_option( 'sp_cal_renouv_delai_notif2_jours', 45 ) );
		$notif1_le  = get_option( 'sp_cal_renouv_notif1_le', '' );
		$notif2_le  = get_option( 'sp_cal_renouv_notif2_le', '' );

		if ( $jours >= $delai1 && $notif1_le === '' ) {
			$this->envoyer_notif_bureau( 1 );
			update_option( 'sp_cal_renouv_notif1_le', current_time( 'Y-m-d' ) );
		}

		if ( $jours >= $delai2 && $notif2_le === '' ) {
			$this->envoyer_notif_bureau( 2 );
			$this->desactiver_et_notifier_adherents();
			update_option( 'sp_cal_renouv_notif2_le', current_time( 'Y-m-d' ) );
		}
	}

	// ─── Notification bureau (récapitulatif des non-renouvelés) ───────────────
	private function envoyer_notif_bureau( int $numero ): void {
		if ( ! $this->db ) return;
		$bureau = $this->db->get_bureau_members();
		if ( empty( $bureau ) ) return;

		$eleves = $this->get_non_renouveles();
		$club   = get_bloginfo( 'name' );
		$cible  = get_option( 'sp_cal_renouv_saison_cible', '' );

		$titre = $numero === 1
			? "Rappel : renouvellement de saison en cours"
			: "Dernier rappel : désactivation des comptes non renouvelés";

		$subject = "[{$club}] 🔄 {$titre} ({$cible})";

		$body  = "Bonjour,\n\n";
		$body .= $numero === 1
			? "Le renouvellement pour la saison {$cible} est en cours. "
			. count( $eleves ) . " adhérent(s) n'ont pas encore renouvelé :\n\n"
			: count( $eleves ) . " adhérent(s) n'ont toujours pas renouvelé et vont être désactivés à l'instant "
			. "(leur accès à l'espace personnel sera bloqué, mais leurs données restent en base) :\n\n";

		foreach ( $eleves as $el ) {
			$body .= "  - {$el->prenom} {$el->nom} ({$el->categorie_age})\n";
		}

		$body .= "\nVoir la page Adhésions : " . admin_url( 'admin.php?page=sp-cal-licences' ) . "\n\n";
		$body .= "-- \n{$club} (notification automatique)";

		foreach ( $bureau as $m ) {
			if ( ! $m->email || ! is_email( $m->email ) ) continue;
			wp_mail( $m->email, $subject, $body );
		}
	}

	// ─── Désactivation + email individuel de renouvellement ───────────────────
	private function desactiver_et_notifier_adherents(): void {
		global $wpdb;
		$tel    = $this->table_eleves();
		$eleves = $this->get_non_renouveles();
		if ( empty( $eleves ) ) return;

		$club          = get_bloginfo( 'name' );
		$page_adhesion = $this->url_formulaire_adhesion();

		foreach ( $eleves as $el ) {
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
			if ( ! $dest || ! is_email( $dest ) ) continue;

			$lien = $page_adhesion ? add_query_arg( 'renouv', $token, $page_adhesion ) : '';

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
			echo '<div class="notice notice-success is-dismissible"><p>✅ Renouvellement de saison lancé.</p></div>';
		}
		if ( isset( $_GET['renouv_annule'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>⚠️ Campagne de renouvellement annulée.</p></div>';
		}
		if ( isset( $_GET['renouv_erreur'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>❌ Merci de renseigner la saison cible.</p></div>';
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
			Déclenche la campagne de rappel de fin de saison (voir <code>md/06-renouvellement-saison.md</code>) :
			2 relances au bureau, puis désactivation automatique des comptes non renouvelés (jamais de suppression)
			et envoi du lien de renouvellement à chacun.
		</p>
		<?php if ( $source ) : ?>
			<p><strong><?php echo $count; ?></strong> adhérent(s) actuellement actif(s) sur la saison <strong><?php echo esc_html( $source ); ?></strong> seraient concernés.</p>
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
				<label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">1ʳᵉ relance (bureau) — jours après le lancement</label>
				<input type="number" name="renouv_delai1" value="21" min="1" max="120" style="height:32px;width:80px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;">
			</div>
			<div>
				<label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">2ᵉ relance + désactivation du compte — jours après le lancement</label>
				<input type="number" name="renouv_delai2" value="45" min="1" max="180" style="height:32px;width:80px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;">
			</div>
			<button type="submit" class="button button-primary"
			        onclick="return confirm('Lancer la campagne de renouvellement maintenant ?');">
				🚀 Lancer le renouvellement
			</button>
		</form>
		<?php
	}

	private function render_dashboard(): void {
		$source    = get_option( 'sp_cal_renouv_saison_source', '' );
		$cible     = get_option( 'sp_cal_renouv_saison_cible', '' );
		$lancement = get_option( 'sp_cal_renouv_date_lancement', '' );
		$notif1_le = get_option( 'sp_cal_renouv_notif1_le', '' );
		$notif2_le = get_option( 'sp_cal_renouv_notif2_le', '' );
		$restants  = count( $this->get_non_renouveles() );
		?>
		<p>
			Campagne en cours : <strong><?php echo esc_html( $source ); ?> → <?php echo esc_html( $cible ); ?></strong>
			(lancée le <?php echo esc_html( date( 'd/m/Y', strtotime( $lancement ) ) ); ?>)
		</p>
		<ul style="margin-left:20px;list-style:disc;">
			<li>Relance bureau n°1 : <?php echo $notif1_le ? '✅ envoyée le ' . esc_html( date( 'd/m/Y', strtotime( $notif1_le ) ) ) : '⏳ pas encore envoyée'; ?></li>
			<li>Relance bureau n°2 + désactivation : <?php echo $notif2_le ? '✅ effectuée le ' . esc_html( date( 'd/m/Y', strtotime( $notif2_le ) ) ) : '⏳ pas encore effectuée'; ?></li>
			<li><strong><?php echo $restants; ?></strong> adhérent(s) encore sur l'ancienne saison à ce jour</li>
		</ul>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sp_renouv_annuler">
			<?php wp_nonce_field( 'sp_renouv_annuler' ); ?>
			<button type="submit" class="button button-secondary"
			        onclick="return confirm('Annuler la campagne en cours ? Les comptes déjà désactivés ne seront pas réactivés automatiquement.');">
				Annuler la campagne
			</button>
		</form>
		<?php
	}
}

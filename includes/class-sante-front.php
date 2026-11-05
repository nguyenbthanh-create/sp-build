<?php
/**
 * Bloc « 🩺 Informations santé » de la fiche personnelle (lien ?token=), 29/10/2026.
 *
 * L'adhérent (ou ses parents, pour un mineur) lit et corrige lui-même ses informations santé,
 * jusque-là saisies seulement dans la demande d'adhésion ou par le bureau sur la fiche admin.
 * Colonne sp_cal_eleves.infos_sante (class-db.php). Enregistrement direct, sans prévenir le
 * bureau (choix de l'utilisateur) ; jamais recopié dans un e-mail. Même authentification que le
 * bloc « Ma tenue » (class-tenues.php) : le lien personnel, plus un nonce lié à l'adhérent.
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Sante_Front {

	private static ?self $instance = null;
	private $db;

	public static function get_instance( $db = null ): self {
		if ( self::$instance === null ) self::$instance = new self( $db );
		return self::$instance;
	}

	private function __construct( $db = null ) {
		$this->db = $db;
		add_action( 'template_redirect',               [ $this, 'handle_front' ], 5 );
		add_action( 'sp_cal_fiche_membre_apres_grade', [ $this, 'render_bloc_adherent' ], 30 );
	}

	private function table_eleves(): string {
		global $wpdb;
		return $this->db ? $this->db->table_eleves() : $wpdb->prefix . 'sp_cal_eleves';
	}

	/** La colonne existe (migration passée) : sinon le bloc n'apparaît pas. */
	private function colonne_ok(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$this->table_eleves()}` LIKE 'infos_sante'" );
	}

	public function handle_front(): void {
		if ( is_admin() || empty( $_POST['sp_sante_front'] ) ) return;
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		if ( $token === '' || ! $this->colonne_ok() ) return;
		global $wpdb;
		$el = $wpdb->get_row( $wpdb->prepare( "SELECT id, actif FROM {$this->table_eleves()} WHERE token = %s", $token ) );
		if ( ! $el ) return;

		$retour = function ( string $msg ): void {
			wp_safe_redirect( add_query_arg( 'sante_msg', $msg, remove_query_arg( 'sante_msg' ) ) . '#sps-front' );
			exit;
		};
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sps_nonce'] ?? '' ) ), 'sp_sante_front_' . $el->id ) ) $retour( 'expire' );
		if ( ! (int) $el->actif ) $retour( 'ferme' );

		$texte = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['infos_sante'] ?? '' ) ), 0, 2000 );
		$wpdb->update( $this->table_eleves(), [ 'infos_sante' => $texte ], [ 'id' => (int) $el->id ] );
		$retour( $texte === '' ? 'efface' : 'ok' );
	}

	/** Bloc de la fiche (action sp_cal_fiche_membre_apres_grade). Adhérent actif uniquement. */
	public function render_bloc_adherent( $el ): void {
		if ( ! is_object( $el ) || ! (int) ( $el->actif ?? 0 ) || ! $this->colonne_ok() ) return;
		$texte    = trim( (string) ( $el->infos_sante ?? '' ) );
		$messages = [
			'ok'     => [ 'ok',  'Vos informations santé sont enregistrées. Merci !' ],
			'efface' => [ 'ok',  'Vos informations santé ont été effacées.' ],
			'expire' => [ 'err', 'La page a expiré : rechargez-la puis recommencez.' ],
			'ferme'  => [ 'err', 'Modification impossible pour le moment : contactez le bureau.' ],
		];
		$msg = sanitize_key( $_GET['sante_msg'] ?? '' );
		?>
		<div class="sp-membre-section sps-front" id="sps-front">
			<style>
				.sps-front .sps-msg{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:14px}
				.sps-front .sps-ok{background:#f0fdf4;border:1px solid #86efac;color:#166534}
				.sps-front .sps-err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
				.sps-front .sps-texte{background:#fff8e5;border-left:3px solid #f59e0b;border-radius:6px;padding:10px 12px;font-size:14px;white-space:pre-line}
				.sps-front .sps-vide{font-size:14px;color:#6b7280}
				.sps-front details{margin-top:10px}
				.sps-front summary{cursor:pointer;font-weight:600;color:#D4000F}
				.sps-front textarea{width:100%;min-height:80px;margin-top:8px;font-family:inherit;font-size:14px}
				.sps-front .sps-btn{margin-top:8px;padding:8px 16px;border:0;border-radius:6px;background:#D4000F;color:#fff;font-weight:600;cursor:pointer}
				.sps-front .sps-note{font-size:12px;color:#6b7280;margin-top:10px}
			</style>
			<h2>🩺 Informations santé</h2>

			<?php if ( isset( $messages[ $msg ] ) ) : ?>
				<div class="sps-msg sps-<?php echo esc_attr( $messages[ $msg ][0] ); ?>"><?php echo esc_html( $messages[ $msg ][1] ); ?></div>
			<?php endif; ?>

			<?php if ( $texte !== '' ) : ?>
				<div class="sps-texte"><?php echo esc_html( $texte ); ?></div>
			<?php else : ?>
				<p class="sps-vide">Aucune information renseignée.</p>
			<?php endif; ?>

			<details<?php echo $msg === 'expire' ? ' open' : ''; ?>>
				<summary><?php echo $texte !== '' ? 'Modifier' : 'Ajouter une information'; ?></summary>
				<form method="post" action="">
					<input type="hidden" name="sp_sante_front" value="1">
					<input type="hidden" name="_sps_nonce" value="<?php echo esc_attr( wp_create_nonce( 'sp_sante_front_' . (int) $el->id ) ); ?>">
					<label for="sps-infos">Informations santé utiles à l'encadrement</label>
					<textarea id="sps-infos" name="infos_sante" maxlength="2000"
					          placeholder="Allergies, asthme, traitement, contre-indication, consignes en cas de malaise…"><?php echo esc_textarea( $texte ); ?></textarea>
					<button type="submit" class="sps-btn">Enregistrer</button>
				</form>
			</details>
			<p class="sps-note">Uniquement communiquées aux entraîneurs et au bureau du club, pour encadrer les séances en sécurité. Videz le champ et enregistrez pour les effacer.</p>
		</div>
		<?php
	}
}

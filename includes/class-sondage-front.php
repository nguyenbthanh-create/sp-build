<?php
/**
 * Sondage post-événement — page de réponse de l'adhérent (06/10/2026).
 *
 * Le module sondage (onglet « Sondage » d'un événement du calendrier admin, page de résultats
 * « Sondages », fonctions SpCalPro_DB::*sondage*) n'avait ni envoi des emails, ni page de
 * réponse : SpCalPro_Notifications::send_sondage_event() n'existait pas (le bouton « Envoyer
 * le sondage » plantait) et aucun code n'appelait save_sondage_reponses(). L'envoi est rétabli
 * dans class-notifications.php ; ce fichier rétablit la page ouverte par le lien de l'email :
 *
 *   /?sp_sondage_token=TOKEN&sp_sondage_event=ID
 *
 * TOKEN = jeton personnel de l'adhérent (le même que sa fiche membre). Page autonome (sans
 * l'habillage du thème : Elementor n'est pas prêt à ce stade, cf. class-token.php), jamais en
 * cache. Une seule réponse par adhérent et par sondage.
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Sondage_Front {

	private static ?self $instance = null;
	private $db;

	public static function get_instance( $db = null ): self {
		if ( self::$instance === null ) self::$instance = new self( $db );
		return self::$instance;
	}

	private function __construct( $db = null ) {
		$this->db = $db;
		add_action( 'template_redirect', [ $this, 'maybe_render' ], 5 );
	}

	/** Adresse de la page de réponse d'un adhérent. */
	public static function url( string $token, int $event_id ): string {
		return add_query_arg( [ 'sp_sondage_token' => rawurlencode( $token ), 'sp_sondage_event' => $event_id ], home_url( '/' ) );
	}

	public function maybe_render(): void {
		if ( ! isset( $_GET['sp_sondage_token'], $_GET['sp_sondage_event'] ) || is_admin() || ! $this->db ) return;

		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );

		global $wpdb;
		$token    = sanitize_text_field( wp_unslash( $_GET['sp_sondage_token'] ) );
		$event_id = intval( $_GET['sp_sondage_event'] );
		$el       = $token !== '' ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->db->table_eleves()} WHERE token = %s LIMIT 1", $token ) ) : null;
		$event    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->db->table_events()} WHERE id = %d", $event_id ) );
		$sondage  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sp_cal_sondages WHERE event_id = %d AND actif = 1 AND envoye = 1", $event_id ), ARRAY_A );

		if ( ! $el || ! $event || ! $sondage ) {
			$this->page( 'Sondage', '<div class="ico">🔗</div><h1>Lien invalide ou expiré</h1><p>Ce sondage n\'existe pas ou n\'est plus ouvert.</p>' );
		}

		$sondage_id = intval( $sondage['id'] );
		$questions  = $this->db->get_sondage_questions( $sondage_id );
		$titre      = wp_unslash( (string) $event->titre );

		if ( $this->db->eleve_a_repondu_sondage( $sondage_id, intval( $el->id ) ) ) {
			$this->page( $titre, '<div class="ico">✅</div><h1>Merci !</h1><p>Votre réponse au sondage « ' . esc_html( $titre ) . ' » est bien enregistrée.</p>' );
		}

		$erreurs = [];
		$valeurs = [];
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['sp_sondage_submit'] ) ) {
			$reponses = [];
			foreach ( $questions as $q ) {
				$qid = intval( $q['id'] );
				$val = isset( $_POST['q'][ $qid ] ) ? wp_unslash( (string) $_POST['q'][ $qid ] ) : '';
				$valeurs[ $qid ] = $val;
				if ( $q['type'] === 'note' ) {
					$note = intval( $val );
					if ( $note >= 1 && $note <= 5 ) $reponses[ $qid ] = $note;
					elseif ( ! empty( $q['obligatoire'] ) ) $erreurs[ $qid ] = 'Choisissez une note.';
				} else {
					if ( trim( $val ) !== '' ) $reponses[ $qid ] = wp_slash( $val ); // save_sondage_reponses() fait wp_unslash()
					elseif ( ! empty( $q['obligatoire'] ) ) $erreurs[ $qid ] = 'Merci de répondre à cette question.';
				}
			}
			if ( ! $erreurs ) {
				if ( $reponses ) $this->db->save_sondage_reponses( $sondage_id, intval( $el->id ), $reponses );
				$this->page( $titre, '<div class="ico">🙏</div><h1>Merci pour votre avis !</h1><p>Votre réponse au sondage « ' . esc_html( $titre ) . ' » est enregistrée. Elle nous aide à améliorer nos événements.</p>' );
			}
		}

		$this->page( $titre, $this->formulaire( $el, $titre, $event, $questions, $valeurs, $erreurs ) );
	}

	private function formulaire( object $el, string $titre, object $event, array $questions, array $valeurs, array $erreurs ): string {
		$date = ! empty( $event->date ) && date_create( $event->date ) ? date_create( $event->date )->format( 'd/m/Y' ) : '';
		$h    = '<h1>🗳️ Votre avis nous intéresse</h1>';
		$h   .= '<p class="sous-titre"><strong>' . esc_html( $titre ) . '</strong>' . ( $date ? ' · ' . esc_html( $date ) : '' ) . '</p>';
		$h   .= '<p>Bonjour ' . esc_html( trim( (string) $el->prenom ) ) . ', merci d\'avoir participé ! Quelques secondes suffisent.</p>';
		if ( $erreurs ) $h .= '<p class="erreur-globale">Il manque une ou plusieurs réponses obligatoires.</p>';
		// Bouton désactivé à l'envoi : un double clic n'enregistre pas deux fois la réponse.
		$h .= '<form method="post" onsubmit="this.querySelector(\'button\').disabled=true;"><input type="hidden" name="sp_sondage_submit" value="1">';
		foreach ( $questions as $q ) {
			$qid  = intval( $q['id'] );
			$oblig = ! empty( $q['obligatoire'] );
			$h   .= '<fieldset class="q' . ( isset( $erreurs[ $qid ] ) ? ' en-erreur' : '' ) . '"><legend>' . esc_html( wp_unslash( (string) $q['question'] ) ) . ( $oblig ? ' <span class="oblig">*</span>' : '' ) . '</legend>';
			if ( $q['type'] === 'note' ) {
				$h .= '<div class="notes">';
				for ( $n = 1; $n <= 5; $n++ ) {
					$h .= '<label><input type="radio" name="q[' . $qid . ']" value="' . $n . '"' . checked( (string) ( $valeurs[ $qid ] ?? '' ), (string) $n, false ) . '><span>' . $n . '</span></label>';
				}
				$h .= '</div><div class="legende"><span>1 = pas satisfait</span><span>5 = très satisfait</span></div>';
			} else {
				$h .= '<textarea name="q[' . $qid . ']" rows="4" maxlength="2000">' . esc_textarea( $valeurs[ $qid ] ?? '' ) . '</textarea>';
			}
			if ( isset( $erreurs[ $qid ] ) ) $h .= '<p class="erreur">' . esc_html( $erreurs[ $qid ] ) . '</p>';
			$h .= '</fieldset>';
		}
		$h .= '<button type="submit">Envoyer ma réponse</button></form>';
		return $h;
	}

	/** Page autonome (sans thème), puis arrêt. */
	private function page( string $titre, string $contenu ): void {
		$club = get_option( 'blogname', 'Club' );
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $titre . ' — ' . $club ); ?></title>
<style>
body{margin:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#111827;line-height:1.5}
.entete{background:#111;color:#fff;padding:18px 20px;text-align:center;font-weight:700;font-size:18px}
.carte{max-width:560px;margin:24px auto;background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);padding:24px 20px}
h1{font-size:21px;margin:0 0 6px}.sous-titre{color:#4b5563;margin:0 0 14px}.ico{font-size:52px;text-align:center}
.carte>h1:first-of-type+p,.ico~h1,.ico~p{text-align:center}
fieldset.q{border:1px solid #e5e7eb;border-radius:10px;margin:0 0 14px;padding:12px 14px}
fieldset.en-erreur{border-color:#dc2626;background:#fef2f2}legend{font-weight:600;padding:0 4px}.oblig{color:#dc2626}
.notes{display:flex;gap:8px;justify-content:space-between;margin-top:6px}.notes label{flex:1}
.notes input{position:absolute;opacity:0}.notes span{display:block;text-align:center;padding:10px 0;border:2px solid #d1d5db;border-radius:8px;font-weight:700;cursor:pointer}
.notes input:checked+span{background:#111;border-color:#111;color:#fff}.notes input:focus-visible+span{outline:3px solid #f59e0b}
.legende{display:flex;justify-content:space-between;font-size:12px;color:#6b7280;margin-top:4px}
textarea{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:8px;font:inherit}
button{width:100%;background:#16a34a;color:#fff;border:0;border-radius:8px;padding:14px;font-size:16px;font-weight:700;cursor:pointer}
.erreur,.erreur-globale{color:#b91c1c;font-size:14px;margin:6px 0 0}.erreur-globale{margin:0 0 12px;font-weight:600}
.pied{text-align:center;color:#9ca3af;font-size:12px;margin:0 0 24px}
</style></head>
<body><div class="entete"><?php echo esc_html( $club ); ?></div>
<div class="carte"><?php echo $contenu; // construit et échappé ci-dessus ?></div>
<p class="pied"><?php echo esc_html( $club ); ?></p></body></html>
		<?php
		exit;
	}
}

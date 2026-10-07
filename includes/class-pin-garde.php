<?php
/**
 * Garde du PIN de pointage (07/10/2026) — limite les essais ratés.
 *
 * Le PIN commun du pointage (option sp_cal_pointage_pin) n'a que 4 chiffres tous différents :
 * 5 040 combinaisons, qu'un robot pouvait toutes essayer en quelques minutes (aucune limite).
 * Désormais, après MAX_ESSAIS PIN faux depuis une même adresse IP, cette adresse est bloquée
 * pendant DUREE secondes (le compteur repart à chaque nouvel essai raté). Rien ne change pour
 * les entraîneurs : même lien, même PIN, mêmes écrans.
 *
 * Toutes les vérifications du PIN de sp_build passent par SP_Cal_Pin_Garde::verifier() :
 * pointage (AJAX + routes REST /pointage/*, déclarées dans class-admin.php ET class-ajax.php),
 * calendrier du club de l'application, anniversaires de l'application, page de pointage ?pin=,
 * et — par le filtre rest_pre_dispatch — les routes /pointage/* de l'extension « SP Pointage QR ».
 * L'extension « SP Pointage QR » (hors de ce dépôt) garde ses propres vérifications, mais
 * ses routes /pointage/* sont désormais filtrées en amont (filtrer_rest()).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Pin_Garde {

	const MAX_ESSAIS = 10;
	const DUREE      = 15 * MINUTE_IN_SECONDS;
	const OPTION     = 'sp_cal_pointage_pin';

	/**
	 * PIN actuel du pointage, créé s'il n'existe pas encore. Seule lecture du PIN dans sp_build
	 * (07/10/2026 — avant, le même code était copié dans 5 fichiers). Même option et même format
	 * (4 chiffres tous différents) que l'extension « SP Pointage QR » (sp_pointage_pin()).
	 */
	public static function pin(): string {
		$pin = (string) get_option( self::OPTION, '' );
		return $pin !== '' ? $pin : self::regenerer();
	}

	/** Tire et enregistre un nouveau PIN (bouton « Régénérer » de la page Pointage QR). */
	public static function regenerer(): string {
		$pin = substr( str_shuffle( '0123456789' ), 0, 4 );
		update_option( self::OPTION, $pin );
		return $pin;
	}

	/**
	 * Vérifie le PIN saisi. Renvoie false si le PIN est faux, vide, ou si l'adresse est bloquée
	 * (dans ce cas le PIN n'est même pas comparé). Un essai raté est compté.
	 */
	public static function verifier( string $saisi, string $attendu ): bool {
		if ( self::bloque() ) return false;
		if ( $saisi !== '' && $attendu !== '' && hash_equals( $attendu, $saisi ) ) return true;
		self::compter_echec();
		return false;
	}

	/** L'adresse IP de la requête a-t-elle dépassé le nombre d'essais ratés ? */
	public static function bloque(): bool {
		return intval( get_transient( self::cle() ) ) >= self::MAX_ESSAIS;
	}

	/** Message à renvoyer en cas de refus (précise le blocage, sans rien révéler du PIN). */
	public static function message(): string {
		return self::bloque()
			? 'Trop d\'essais de PIN incorrects : réessayez dans ' . intval( self::DUREE / MINUTE_IN_SECONDS ) . ' minutes.'
			: 'PIN invalide';
	}

	/**
	 * Filtre rest_pre_dispatch : contrôle le PIN des routes /spcal/v1/pointage/* AVANT leur
	 * exécution, quelle que soit l'extension qui les a déclarées. Ces 4 routes sont aussi
	 * déclarées par l'extension séparée « SP Pointage QR » (hors dépôt), qui répond en premier
	 * et ne limitait pas les essais : constaté sur la prod le 07/10/2026 (11 PIN faux sur
	 * /pointage/cours, aucun compté). PIN faux ou adresse bloquée → refus ici, même format de
	 * réponse que l'extension ; PIN bon → la requête continue normalement (le gestionnaire
	 * revérifie le PIN, sans compter d'échec puisqu'il est bon).
	 */
	public static function filtrer_rest( $resultat, $serveur, $requete ) {
		if ( $resultat !== null || ! ( $requete instanceof WP_REST_Request ) ) return $resultat;
		if ( strpos( (string) $requete->get_route(), '/spcal/v1/pointage/' ) !== 0 ) return $resultat;
		$attendu = self::pin();
		if ( self::verifier( sanitize_text_field( (string) $requete->get_param( 'pin' ) ), $attendu ) ) return $resultat;
		return new WP_REST_Response( [ 'success' => false, 'data' => self::message() ], 403 );
	}

	private static function compter_echec(): void {
		$n = intval( get_transient( self::cle() ) ) + 1;
		set_transient( self::cle(), $n, self::DUREE );
		if ( $n === self::MAX_ESSAIS ) {
			error_log( '[SP_Build] PIN de pointage : ' . self::MAX_ESSAIS . ' essais ratés depuis ' . self::ip() . ' — adresse bloquée ' . intval( self::DUREE / MINUTE_IN_SECONDS ) . ' min.' );
		}
	}

	private static function cle(): string {
		return 'sp_pin_ko_' . md5( self::ip() );
	}

	private static function ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'inconnue';
	}
}

add_filter( 'rest_pre_dispatch', [ 'SP_Cal_Pin_Garde', 'filtrer_rest' ], 10, 3 );

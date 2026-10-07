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
 * calendrier du club de l'application, anniversaires de l'application, page de pointage ?pin=.
 * Limite : l'extension séparée « SP Pointage QR » (hors de ce dépôt) fait ses propres
 * vérifications, non couvertes ici.
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Pin_Garde {

	const MAX_ESSAIS = 10;
	const DUREE      = 15 * MINUTE_IN_SECONDS;

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

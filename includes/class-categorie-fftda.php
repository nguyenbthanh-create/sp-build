<?php
/**
 * Catégorie d'âge FFTDA d'un adhérent, telle qu'elle figure sur la licence fédérale
 * (ex. « Junior 1 Féminin ») — calculée, jamais stockée (26/11/2026).
 *
 * Source : tableaux officiels de la FFTDA, saison 26/27, page « Règlement / Inscription /
 * Dérogation » de fftda.fr (« Combat - catégories d'âges et de poids »). Règle fédérale :
 * « l'âge de référence pour toute la saison sportive est l'âge du licencié au 31/12 de l'année
 * civile qui suit le début de saison ». Saison 2026/2027 → âge en 2027 = 2027 − année de naissance.
 *
 * Les tranches d'âge sont fixes d'une saison à l'autre : seules les années de naissance glissent,
 * le calcul suit donc tout seul. Si la FFTDA change un jour une tranche, modifier TRANCHES.
 *
 * Numérotation « Junior 1 / 2 / 3 » : le tableau fédéral donne « Juniors 15 à 17 ans » sans
 * subdivision, mais la licence affiche « Junior 1 » pour 15 ans (vérifié sur une licence
 * 2026-2027, née en 2012) : même découpage par année que les Cadets 1 / 2 / 3.
 * La catégorie « Espoirs » (15-21 ans, compétition seulement) chevauche Juniors et Seniors et
 * n'apparaît pas sur la licence : elle n'est pas calculée.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SP_Cal_Categorie_Fftda' ) ) :

final class SP_Cal_Categorie_Fftda {

	/** Âge minimum => libellé, du plus âgé au plus jeune (âge au 31/12 de l'année de référence). */
	const TRANCHES = [
		40 => 'Master 3',
		35 => 'Master 2',
		30 => 'Master 1',
		18 => 'Senior',
		17 => 'Junior 3',
		16 => 'Junior 2',
		15 => 'Junior 1',
		14 => 'Cadet 3',
		13 => 'Cadet 2',
		12 => 'Cadet 1',
		11 => 'Minime 2',
		10 => 'Minime 1',
		9  => 'Benjamin 2',
		8  => 'Benjamin 1',
		7  => 'Pupille 2',
		6  => 'Pupille 1',
		3  => 'Baby',
	];

	/**
	 * Année de référence : seconde année de la saison du club (option « tkd_saison_courante »,
	 * ex. « 2026/2027 »), sinon saison calculée avec bascule au 1er septembre.
	 */
	public static function annee_reference(): int {
		$s = (string) get_option( 'tkd_saison_courante', '' );
		if ( preg_match( '#^\d{4}\s*/\s*(\d{4})$#', trim( $s ), $m ) ) return (int) $m[1];
		$y = (int) current_time( 'Y' );
		return (int) current_time( 'n' ) >= 9 ? $y + 1 : $y;
	}

	/** Libellé de la tranche pour une année de naissance (« Junior 1 »), '' si hors tableau. */
	public static function tranche( int $annee_naissance, ?int $annee_ref = null ): string {
		if ( $annee_naissance < 1900 ) return '';
		$age = ( $annee_ref ?? self::annee_reference() ) - $annee_naissance;
		foreach ( self::TRANCHES as $min => $libelle ) {
			if ( $age >= $min ) return $libelle;
		}
		return '';
	}

	/**
	 * Catégorie complète d'un adhérent (ligne de la table des élèves) : « Junior 1 Féminin »,
	 * « Senior » si le sexe n'est pas renseigné, '' sans année de naissance.
	 */
	public static function pour( $el, ?int $annee_ref = null ): string {
		if ( ! is_object( $el ) ) return '';
		$annee = 0;
		if ( preg_match( '/^\d{4}$/', trim( (string) ( $el->annee_naissance ?? '' ) ) ) ) {
			$annee = (int) $el->annee_naissance;
		} elseif ( preg_match( '/(\d{4})/', (string) ( $el->date_naissance ?? '' ), $m ) ) {
			$annee = (int) $m[1];
		}
		$tranche = self::tranche( $annee, $annee_ref );
		if ( $tranche === '' ) return '';
		$sexe = self::sexe( $el );
		return $sexe === 'M' ? $tranche . ' Masculin' : ( $sexe === 'F' ? $tranche . ' Féminin' : $tranche );
	}

	/** Sexe « M » / « F » : rangé dans extra_data par la fiche admin et le formulaire d'adhésion. */
	private static function sexe( object $el ): string {
		$extra = json_decode( (string) ( $el->extra_data ?? '' ), true );
		$s     = is_array( $extra ) ? (string) ( $extra['sexe'] ?? '' ) : '';
		if ( $s === '' && isset( $el->sexe ) ) $s = (string) $el->sexe;
		return in_array( $s, [ 'M', 'F' ], true ) ? $s : '';
	}
}

endif;

<?php
/**
 * Examens Dan d'Occitanie importés d'IzziDan (28/11/2026), même principe que les stages
 * (class-stages-izzidan.php) et même choix d'organisateurs (Ligue + comités cochés).
 *
 * IzziDan publie la liste nationale des examens Dan sans compte : https://fftda.izzidan.com/
 * (date, clôture des inscriptions, organisateur, ville, grades). Plus complète que l'agenda de
 * la Ligue (6 examens en Occitanie le 28/11/2026, contre 2). Sont retenus les examens ouverts au
 * 1er Dan (« Examen 1er Dan », « Examen 1-2è Dan », « Examen 1-4è Dan »…).
 *
 * Lancé à chaque import des stages (tâche quotidienne et boutons de la page Candidats).
 * Jamais de doublon ni d'écrasement :
 *  - examen déjà saisi le même jour (type Examen, titre avec « Dan ») : relié, et un bloc
 *    « Informations IzziDan » est ajouté sous sa description, tenu à jour (texte du club intact) ;
 *  - examen créé par l'import : remis à jour tant qu'il n'a pas été modifié à la main ;
 *  - examen déjà importé puis supprimé du calendrier : pas recréé.
 * Repère « Région · IZD » / « Département · IZD » dans le calendrier, le planning et l'agenda.
 * La date d'examen de l'éligibilité reste réglée à la main (page Candidats, dates proposées).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SP_Cal_Examens_Izzidan' ) ) :

final class SP_Cal_Examens_Izzidan {

	const LISTE      = 'https://fftda.izzidan.com/';
	const OPT_IMPORT = 'sp_cal_izzidan_examens';          // [ date|organisateur => id de l'événement ]
	const OPT_CREES  = 'sp_cal_izzidan_examens_crees';    // ids créés par l'import
	const OPT_NIVEAU = 'sp_cal_izzidan_examens_niveau';   // [ id => regional|departemental ]
	const OPT_SIGN   = 'sp_cal_izzidan_examens_sign';     // [ id créé => empreinte ]
	const OPT_LISTE  = 'sp_cal_izzidan_examens_liste';    // examens d'Occitanie du dernier passage
	const DEBUT_BLOC = '— Informations IzziDan (mises à jour automatiquement) —';
	const FIN_BLOC   = '— Fin des informations IzziDan —';

	/** « Région · IZD » / « Département · IZD » pour un examen venu d'IzziDan, '' sinon. */
	public static function libelle( int $event_id ): string {
		static $niv = null;
		if ( $niv === null ) $niv = (array) get_option( self::OPT_NIVEAU, [] );
		if ( ! isset( $niv[ $event_id ] ) ) return '';
		return ( $niv[ $event_id ] === 'departemental' ? 'Département' : 'Région' ) . ' · IZD';
	}

	public static function ids(): array {
		return array_map( 'intval', array_values( (array) get_option( self::OPT_IMPORT, [] ) ) );
	}

	private static function titre_case( string $s ): string {
		return mb_convert_case( mb_strtolower( trim( $s ) ), MB_CASE_TITLE, 'UTF-8' );
	}

	/** Examens de la liste : [ date, cloture, type, nom, cle, ville, grades ]. null si page inaccessible. */
	public static function lire( ?string $html = null ): ?array {
		if ( $html === null ) {
			$r = wp_remote_get( self::LISTE, [ 'timeout' => 20, 'user-agent' => 'Mozilla/5.0 (compatible; club WordPress)' ] );
			if ( is_wp_error( $r ) || (int) wp_remote_retrieve_response_code( $r ) !== 200 ) return null;
			$html = (string) wp_remote_retrieve_body( $r );
		}
		$texte = static fn( string $h ) => trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES, 'UTF-8' ) ) );
		$out = [];
		preg_match_all( '#<tr[^>]*>(.*?)</tr>#si', $html, $lignes );
		foreach ( $lignes[1] as $tr ) {
			preg_match_all( '#<td[^>]*>(.*?)</td>#si', $tr, $td );
			$cel = $td[1];
			$i   = null;
			foreach ( $cel as $k => $c ) {
				if ( SP_Cal_Stages_Izzidan::date_fr( $texte( (string) preg_replace( '#<br.*$#si', '', $c ) ) ) !== '' ) { $i = $k; break; }
			}
			if ( $i === null || ! isset( $cel[ $i + 3 ] ) ) continue;
			$date    = SP_Cal_Stages_Izzidan::date_fr( $texte( (string) preg_replace( '#<br.*$#si', '', $cel[ $i ] ) ) );
			$cloture = preg_match( '/Cl[ôo]ture inscription\s*:\s*(.+)$/u', $texte( $cel[ $i ] ), $m ) ? SP_Cal_Stages_Izzidan::date_fr( $m[1] ) : '';
			$parts   = preg_split( '#<br\s*/?>#i', $cel[ $i + 1 ] );
			$org     = $texte( (string) ( $parts[0] ?? '' ) );
			$ville   = $texte( (string) ( $parts[1] ?? '' ) );
			$grades  = $texte( $cel[ $i + 3 ] );
			if ( ! preg_match( '/examen/i', $grades ) ) continue;
			if ( preg_match( '/^LIGUE\s*:?\s*(.+)$/iu', $org, $x ) )                  { $type = 'ligue'; $nom = self::titre_case( $x[1] ); $cle = 'ligue-' . SP_Cal_Stages_Izzidan::cle( $x[1] ); }
			elseif ( preg_match( '/^C\.?\s*D\.?\s*T\.?\s*:?\s*(.+)$/iu', $org, $x ) ) { $type = 'cdt'; $nom = self::titre_case( $x[1] ); $cle = SP_Cal_Stages_Izzidan::cle( $x[1] ); }
			else continue;
			$out[] = compact( 'date', 'cloture', 'type', 'nom', 'cle', 'ville', 'grades' );
		}
		return $out;
	}

	/** Examen ouvert au 1er Dan : « Examen 1er Dan », « Examen 1-2è Dan », « Examen 1-4è Dan mutualisé ». */
	public static function ouvert_1er_dan( string $grades ): bool {
		return (bool) preg_match( '/examen\s+1\s*(er\b|-)/', strtolower( remove_accents( $grades ) ) );
	}

	private static function fr( string $ymd ): string {
		return $ymd ? substr( $ymd, 8, 2 ) . '/' . substr( $ymd, 5, 2 ) . '/' . substr( $ymd, 0, 4 ) : '';
	}

	private static function organisateur( array $e ): string {
		return $e['type'] === 'ligue' ? 'Ligue ' . $e['nom'] : 'C.D.T. ' . $e['nom'];
	}

	private static function infos( array $e ): string {
		return $e['grades'] . ' — ' . self::organisateur( $e ) . ( $e['ville'] ? ', ' . $e['ville'] : '' ) . '.'
			. ( $e['cloture'] ? "\nClôture des inscriptions : " . self::fr( $e['cloture'] ) . ' (inscription des candidats sur IzziDan).' : '' )
			. "\nListe des examens sur IzziDan : " . self::LISTE;
	}

	private static function empreinte( object $ev ): string {
		return md5( wp_unslash( (string) $ev->description ) . '|' . (string) $ev->titre );
	}

	/** Importe / relie / tient à jour les examens d'Occitanie. Renvoie les lignes du compte rendu. */
	public static function synchroniser(): array {
		global $wpdb;
		$liste = self::lire();
		if ( $liste === null ) return [ 'erreur' => 'liste des examens IzziDan inaccessible', 'trouves' => [], 'crees' => 0 ];
		$te     = $wpdb->prefix . 'sp_cal_events';
		$cols   = (array) $wpdb->get_col( "SHOW COLUMNS FROM `$te`" );
		$import = (array) get_option( self::OPT_IMPORT, [] );
		$crees  = array_map( 'intval', (array) get_option( self::OPT_CREES, [] ) );
		$niv    = (array) get_option( self::OPT_NIVEAU, [] );
		$sign   = (array) get_option( self::OPT_SIGN, [] );
		$coches = SP_Cal_Stages_Izzidan::coches();
		$auj    = current_time( 'Y-m-d' );
		$res    = [ 'erreur' => '', 'trouves' => [], 'crees' => 0 ];
		$occ    = array_values( array_filter( $liste, static fn( $e ) => isset( SP_Cal_Stages_Izzidan::ORGANISATEURS[ $e['cle'] ] ) && self::ouvert_1er_dan( $e['grades'] ) ) );
		update_option( self::OPT_LISTE, $occ, false );
		$par_ev = [];

		foreach ( $occ as $e ) {
			$cle    = $e['date'] . '|' . $e['cle'];
			$niveau = $e['type'] === 'ligue' ? 'regional' : 'departemental';
			$ligne  = [ 'date' => $e['date'], 'org' => self::organisateur( $e ) . ( $e['ville'] ? ' (' . $e['ville'] . ')' : '' ), 'grades' => $e['grades'], 'statut' => '' ];
			if ( $e['date'] < $auj ) { $ligne['statut'] = 'passé'; }
			elseif ( ! in_array( $e['cle'], $coches, true ) ) { $ligne['statut'] = 'organisateur non coché'; }
			elseif ( isset( $import[ $cle ] ) ) { $ligne['statut'] = 'déjà importé'; }
			else {
				$deja = null;
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$te` WHERE date = %s AND type = 'examen'", $e['date'] ) ) as $ev ) {
					if ( in_array( (int) $ev->id, $crees, true ) || in_array( (int) $ev->id, array_map( 'intval', $import ), true ) ) continue;
					if ( preg_match( '/\bdan\b/i', remove_accents( wp_unslash( (string) $ev->titre ) ) ) ) { $deja = $ev; break; }
				}
				if ( $deja ) {
					$import[ $cle ] = (int) $deja->id;
					$ligne['statut'] = 'déjà au calendrier (« ' . wp_unslash( (string) $deja->titre ) . ' »)';
				} else {
					$data = array_intersect_key( [
						'date'                 => $e['date'],
						'heure_debut'          => '',
						'heure_fin'            => '',
						'titre'                => mb_substr( $e['grades'] . ' — ' . self::organisateur( $e ) . ( $e['ville'] ? ' (' . $e['ville'] . ')' : '' ), 0, 200 ),
						'categorie'            => 'Taekwondo',
						'cours_discipline'     => 'Taekwondo',
						'couleur'              => '#b45309',
						'type'                 => 'examen',
						'niveau'               => $niveau,
						'description'          => self::infos( $e ) . "\n\nRepris automatiquement d'IzziDan.",
						'inscriptions_actives' => 0,
					], array_flip( $cols ) );
					if ( $wpdb->insert( $te, $data ) ) {
						$id              = (int) $wpdb->insert_id;
						$import[ $cle ]  = $id;
						$crees[]         = $id;
						$sign[ $id ]     = md5( $data['description'] . '|' . $data['titre'] );
						$ligne['statut'] = 'importé';
						$res['crees']++;
					} else {
						$ligne['statut'] = 'erreur à l\'import';
					}
				}
			}
			if ( isset( $import[ $cle ] ) ) {
				$niv[ (int) $import[ $cle ] ] = $niveau;
				if ( $e['date'] >= $auj ) $par_ev[ (int) $import[ $cle ] ][] = [ $e, count( $res['trouves'] ) ];
			}
			$res['trouves'][] = $ligne;
		}

		// Mise à jour des informations (clôture des inscriptions, ville…).
		foreach ( $par_ev as $id => $items ) {
			$ev = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$te` WHERE id = %d", $id ) );
			if ( ! $ev ) continue;
			$note = '';
			if ( in_array( (int) $id, $crees, true ) ) {
				if ( isset( $sign[ $id ] ) && $sign[ $id ] !== self::empreinte( $ev ) ) {
					$note = 'modifié à la main : plus mis à jour';
				} else {
					$desc = self::infos( $items[0][0] ) . "\n\nRepris automatiquement d'IzziDan.";
					if ( $desc !== wp_unslash( (string) $ev->description ) ) {
						$wpdb->update( $te, [ 'description' => $desc ], [ 'id' => (int) $id ] );
						$note = 'informations mises à jour';
					}
					$sign[ $id ] = md5( $desc . '|' . (string) $ev->titre );
				}
			} else {
				$desc = wp_unslash( (string) $ev->description );
				$club = trim( (string) preg_replace( '/\n*' . preg_quote( self::DEBUT_BLOC, '/' ) . '.*?' . preg_quote( self::FIN_BLOC, '/' ) . '/su', '', $desc ) );
				$bloc = self::DEBUT_BLOC . "\n" . implode( "\n\n", array_map( static fn( $x ) => self::infos( $x[0] ), $items ) ) . "\n" . self::FIN_BLOC;
				$neuf = ( $club !== '' ? $club . "\n\n" : '' ) . $bloc;
				if ( $neuf !== $desc ) {
					$wpdb->update( $te, [ 'description' => $neuf ], [ 'id' => (int) $id ] );
					$note = 'informations IzziDan ajoutées sous la description du club';
				}
			}
			if ( $note !== '' ) foreach ( $items as [ , $i ] ) $res['trouves'][ $i ]['statut'] .= ' — ' . $note;
		}

		update_option( self::OPT_IMPORT, $import, false );
		update_option( self::OPT_CREES, array_values( array_unique( $crees ) ), false );
		update_option( self::OPT_NIVEAU, $niv, false );
		update_option( self::OPT_SIGN, $sign, false );
		return $res;
	}

	/** Examens d'Occitanie ouverts au 1er Dan entre deux dates (dernier passage), pour proposer la date d'examen. */
	public static function proposes( string $debut, string $fin ): array {
		$coches = SP_Cal_Stages_Izzidan::coches();   // mêmes organisateurs que l'import
		return array_values( array_filter( (array) get_option( self::OPT_LISTE, [] ), static fn( $e ) => $e['date'] >= $debut && $e['date'] <= $fin && in_array( $e['cle'], $coches, true ) ) );
	}
}

endif;

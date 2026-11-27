<?php
/**
 * Compétitions de la Ligue Occitanie importées dans le calendrier (28/11/2026).
 *
 * Même principe que les stages IzziDan (class-stages-izzidan.php). La Ligue publie un agenda
 * public au format iCal : https://www.taekwondo-occitanie.com/evenements.ics (environ les
 * 15 prochains événements). Une fois par jour (et sur demande), les compétitions qu'il annonce
 * (championnats, critériums, coupes, opens) sont recopiées dans le calendrier du club en type
 * « Compétition », niveau régional (Ligue) ou départemental (comité, si la case est cochée).
 * Repère « LIG » à côté du niveau dans le calendrier, le planning et l'agenda.
 *
 * Jamais de doublon ni d'écrasement : une compétition déjà importée (même supprimée ensuite)
 * n'est pas recréée ; une compétition déjà saisie à la main le même jour et de la même discipline
 * (combat / poomsae-technique) est seulement reliée à la Ligue (son niveau passe à régional s'il
 * était resté « départemental ») ; un événement importé n'est jamais modifié ensuite.
 * Épreuves et résultats restent saisis par le club.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SP_Cal_Competitions_Ligue' ) ) :

final class SP_Cal_Competitions_Ligue {

	const ICS        = 'https://www.taekwondo-occitanie.com/evenements.ics';
	const AGENDA     = 'https://www.taekwondo-occitanie.com/evenements';
	const OPT_IMPORT = 'sp_cal_ligue_import';     // [ url de la compétition => id de l'événement ]
	const OPT_CREES  = 'sp_cal_ligue_crees';      // ids créés par l'import (jamais réutilisés pour une autre)
	const OPT_DEPT   = 'sp_cal_ligue_cdt';        // 1 = importer aussi les compétitions des comités
	const OPT_ETAT   = 'sp_cal_ligue_etat';
	const CRON       = 'sp_cal_ligue_quotidien';

	private static $instance = null;

	public static function get_instance(): self {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ __CLASS__, 'planifier' ], 99 );
		add_action( self::CRON, [ __CLASS__, 'tache' ] );
		add_action( 'admin_post_sp_cal_ligue_importer', [ $this, 'handle_importer' ] );
	}

	public static function planifier(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) wp_schedule_event( time() + 900, 'daily', self::CRON );
	}

	public static function tache(): void {
		self::synchroniser();
	}

	/** Événement du calendrier venu de l'agenda de la Ligue (importé ou relié). */
	public static function est_ligue( int $event_id ): bool {
		static $ids = null;
		if ( $ids === null ) $ids = array_flip( array_map( 'intval', (array) get_option( self::OPT_IMPORT, [] ) ) );
		return $event_id > 0 && isset( $ids[ $event_id ] );
	}

	/* =====================================================================================
	 * Lecture du flux
	 * =================================================================================== */

	/** Date et heure de Paris depuis une valeur DTSTART / DTEND (« 20270123T070000Z »). */
	private static function paris( string $v ): array {
		if ( ! preg_match( '/^(\d{8})(T(\d{6})(Z?))?$/', trim( $v ), $m ) ) return [ '', '' ];
		if ( empty( $m[2] ) ) return [ substr( $m[1], 0, 4 ) . '-' . substr( $m[1], 4, 2 ) . '-' . substr( $m[1], 6, 2 ), '' ];
		$dt = new \DateTime( $m[1] . 'T' . $m[3], new \DateTimeZone( $m[4] === 'Z' ? 'UTC' : 'Europe/Paris' ) );
		$dt->setTimezone( new \DateTimeZone( 'Europe/Paris' ) );
		return [ $dt->format( 'Y-m-d' ), $dt->format( 'H:i' ) ];
	}

	/** Compétitions du flux : [ url, titre, date, debut, fin, lieu, niveau ]. null si flux inaccessible. */
	public static function lire( ?string $ics = null ): ?array {
		if ( $ics === null ) {
			$r = wp_remote_get( self::ICS, [ 'timeout' => 20, 'user-agent' => 'Mozilla/5.0 (compatible; club WordPress)' ] );
			if ( is_wp_error( $r ) || (int) wp_remote_retrieve_response_code( $r ) !== 200 ) return null;
			$ics = (string) wp_remote_retrieve_body( $r );
		}
		$ics = str_replace( [ "\r\n ", "\n ", "\r\n\t", "\n\t" ], '', $ics );   // lignes repliées
		$out = [];
		preg_match_all( '/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $m );
		foreach ( $m[1] as $v ) {
			$champ = static function ( string $k ) use ( $v ): string {
				return preg_match( '/^' . $k . '[^:\n]*:(.*)$/m', $v, $x ) ? trim( str_replace( [ '\\,', '\\;', '\\n', '\\N' ], [ ',', ';', ' ', ' ' ], $x[1] ) ) : '';
			};
			$titre = $champ( 'SUMMARY' );
			$t     = strtolower( remove_accents( $titre ) );
			if ( ! preg_match( '/championnat|criterium|crterium|coupe|open|tournoi|interclubs?/', $t ) ) continue;
			if ( preg_match( '/stage|examen|formation|entrainement|seminaire/', $t ) ) continue;
			[ $date, $debut ] = self::paris( $champ( 'DTSTART' ) );
			[ $dfin, $fin ]   = self::paris( $champ( 'DTEND' ) );
			if ( $date === '' ) continue;
			// Journée entière codée « 23:00 UTC la veille → 23:00 UTC le jour » : pas d'horaire.
			if ( $debut === '00:00' && ( $fin === '00:00' || $dfin > $date ) ) { $debut = ''; $fin = ''; }
			if ( $dfin !== '' && $dfin !== $date ) $fin = '';
			$out[] = [
				'url'    => $champ( 'URL' ),
				'titre'  => $titre,
				'date'   => $date,
				'debut'  => $debut,
				'fin'    => $fin,
				'lieu'   => trim( (string) preg_replace( '/^Localisation\s*/i', '', $champ( 'LOCATION' ) ) ),
				'niveau' => preg_match( '/\bc\.?\s*d\.?\s*t\.?\b|comite departemental/', $t ) ? 'departemental' : 'regional',
			];
		}
		return $out;
	}

	/** Discipline d'un titre : combat, poomsae (technique) ou '' (non précisée). */
	public static function discipline( string $titre ): string {
		$t = strtolower( remove_accents( $titre ) );
		if ( preg_match( '/combat|kyorugi/', $t ) ) return 'combat';
		if ( preg_match( '/poomsae|technique|poom?se/', $t ) ) return 'poomsae';
		return '';
	}

	/* =====================================================================================
	 * Import
	 * =================================================================================== */

	public static function synchroniser(): array {
		global $wpdb;
		$etat  = [ 'date' => current_time( 'd/m/Y H:i' ), 'erreur' => '', 'trouves' => [], 'crees' => 0 ];
		$liste = self::lire();
		if ( $liste === null ) {
			$etat['erreur'] = 'agenda de la Ligue inaccessible';
			update_option( self::OPT_ETAT, $etat, false );
			return $etat;
		}
		$te     = $wpdb->prefix . 'sp_cal_events';
		$cols   = (array) $wpdb->get_col( "SHOW COLUMNS FROM `$te`" );
		$import = (array) get_option( self::OPT_IMPORT, [] );
		$crees  = array_map( 'intval', (array) get_option( self::OPT_CREES, [] ) );
		$dept   = (bool) get_option( self::OPT_DEPT, 0 );
		$auj    = current_time( 'Y-m-d' );

		foreach ( $liste as $c ) {
			$cle   = $c['url'] !== '' ? $c['url'] : $c['date'] . '|' . sanitize_title( $c['titre'] );
			$ligne = [ 'date' => $c['date'], 'titre' => $c['titre'], 'niveau' => $c['niveau'], 'statut' => '' ];
			if ( $c['date'] < $auj ) { $ligne['statut'] = 'passée'; }
			elseif ( $c['niveau'] === 'departemental' && ! $dept ) { $ligne['statut'] = 'départementale (non importée)'; }
			elseif ( isset( $import[ $cle ] ) ) { $ligne['statut'] = 'déjà importée'; }
			else {
				// Compétition déjà saisie à la main ce jour-là, de la même discipline (ou sans discipline).
				$disc = self::discipline( $c['titre'] );
				$deja = null;
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$te` WHERE date = %s AND type = 'competition'", $c['date'] ) ) as $ev ) {
					if ( in_array( (int) $ev->id, $crees, true ) ) continue;   // créée par l'import pour une autre compétition
					$d2 = self::discipline( wp_unslash( (string) $ev->titre ) );
					if ( $disc === '' || $d2 === '' || $d2 === $disc ) { $deja = $ev; break; }
				}
				if ( $deja ) {
					$import[ $cle ]  = (int) $deja->id;
					$ligne['statut'] = 'déjà au calendrier (« ' . wp_unslash( (string) $deja->titre ) . ' »)';
					if ( $c['niveau'] === 'regional' && in_array( 'niveau', $cols, true ) && (string) ( $deja->niveau ?? 'departemental' ) === 'departemental' ) {
						$wpdb->update( $te, [ 'niveau' => 'regional' ], [ 'id' => (int) $deja->id ] );
						$ligne['statut'] .= ', niveau passé à régional';
					}
				} else {
					$desc = $c['titre'] . ' — ' . ( $c['niveau'] === 'regional' ? 'Ligue Occitanie' : 'comité départemental' ) . '.';
					if ( $c['lieu'] ) $desc .= "\nLieu : " . $c['lieu'];
					if ( $c['debut'] ) $desc .= "\nHoraires : " . $c['debut'] . ( $c['fin'] ? ' - ' . $c['fin'] : '' );
					$desc .= "\nInformations : " . ( $c['url'] ?: self::AGENDA );
					$desc .= "\nImporté automatiquement de l'agenda de la Ligue Occitanie le " . current_time( 'd/m/Y' ) . '.';
					$data = array_intersect_key( [
						'date'                 => $c['date'],
						'heure_debut'          => $c['debut'],
						'heure_fin'            => $c['fin'],
						'titre'                => mb_substr( $c['titre'], 0, 200 ),
						'categorie'            => 'Taekwondo',
						'cours_discipline'     => 'Taekwondo',
						'couleur'              => '#b8860b',
						'type'                 => 'competition',
						'niveau'               => $c['niveau'],
						'description'          => $desc,
						'inscriptions_actives' => 0,
					], array_flip( $cols ) );
					if ( $wpdb->insert( $te, $data ) ) {
						$import[ $cle ]  = (int) $wpdb->insert_id;
						$crees[]         = (int) $wpdb->insert_id;
						$ligne['statut'] = 'importée';
						$etat['crees']++;
					} else {
						$ligne['statut'] = 'erreur à l\'import';
					}
				}
			}
			$etat['trouves'][] = $ligne;
		}
		update_option( self::OPT_IMPORT, $import, false );
		update_option( self::OPT_CREES, array_values( array_unique( $crees ) ), false );
		update_option( self::OPT_ETAT, $etat, false );
		return $etat;
	}

	/* =====================================================================================
	 * Administration (Paramètres → Compétition)
	 * =================================================================================== */

	public function handle_importer(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'sp_cal_ligue_importer' );
		update_option( self::OPT_DEPT, empty( $_POST['cdt'] ) ? 0 : 1, false );
		self::synchroniser();
		wp_safe_redirect( admin_url( 'admin.php?page=sp-cal-settings&onglet=competition&ligue=1#sp-ligue' ) );
		exit;
	}

	public static function render(): void {
		$etat = get_option( self::OPT_ETAT );
		$fr   = static fn( string $d ) => substr( $d, 8, 2 ) . '/' . substr( $d, 5, 2 ) . '/' . substr( $d, 0, 4 );
		echo '<div class="sp-box" id="sp-ligue"><h2>🏆 Compétitions de la Ligue Occitanie</h2>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- message de retour
		if ( isset( $_GET['ligue'] ) && is_array( $etat ) ) echo '<div class="notice notice-success inline"><p>Import fait : ' . (int) $etat['crees'] . ' compétition(s) ajoutée(s) au calendrier.</p></div>';
		echo '<p>Chaque jour, les compétitions annoncées sur <a href="' . esc_url( self::AGENDA ) . '" target="_blank" rel="noopener">l\'agenda de la Ligue</a> (championnats, critériums, coupes) sont ajoutées au calendrier du club, type « Compétition », niveau régional, avec le repère <strong>LIG</strong>. '
			. 'Une compétition déjà saisie le même jour et de la même discipline n\'est pas recopiée. Les épreuves et les résultats restent à saisir par le club.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sp_cal_ligue_importer">';
		wp_nonce_field( 'sp_cal_ligue_importer' );
		echo '<p><label><input type="checkbox" name="cdt" value="1"' . ( get_option( self::OPT_DEPT ) ? ' checked' : '' ) . '> Importer aussi les compétitions des comités départementaux (niveau départemental)</label></p>';
		echo '<p><button class="button button-primary">🔄 Enregistrer et importer maintenant</button></p></form>';
		if ( is_array( $etat ) ) {
			echo '<p>Dernier import : ' . esc_html( $etat['date'] ) . ( $etat['erreur'] ? ' — <span style="color:#dc2626;">' . esc_html( $etat['erreur'] ) . '</span>' : '' ) . '</p>';
			if ( ! empty( $etat['trouves'] ) ) {
				echo '<table class="widefat striped" style="max-width:1000px;"><thead><tr><th>Date</th><th>Compétition</th><th>Niveau</th><th>Dans le calendrier du club</th></tr></thead><tbody>';
				foreach ( $etat['trouves'] as $l ) {
					echo '<tr><td>' . esc_html( $fr( $l['date'] ) ) . '</td><td>' . esc_html( $l['titre'] ) . '</td><td>' . esc_html( $l['niveau'] === 'regional' ? 'Régional' : 'Départemental' ) . '</td><td>' . esc_html( $l['statut'] ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		} else {
			echo '<p class="description">Premier import : demain au plus tard, ou cliquez sur le bouton.</p>';
		}
		echo '</div>';
	}
}

endif;

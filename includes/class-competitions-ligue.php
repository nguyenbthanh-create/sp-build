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
 * était resté « départemental »).
 *
 * Page détail de la Ligue (28/11/2026) : texte complet, affiche, documents. Un événement créé par
 * l'import est remis à jour tant qu'il n'a pas été modifié à la main ; sous un événement saisi par
 * le club, un bloc « Informations de la Ligue » est ajouté et tenu à jour, sans toucher au texte du club.
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
		$par_ev = [];

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
			// Compétition à venir reliée à un événement du club : sa page détail sera reprise.
			if ( isset( $import[ $cle ] ) && $c['date'] >= $auj ) {
				$par_ev[ (int) $import[ $cle ] ][] = [ $c, count( $etat['trouves'] ) - 1 ];
			}
		}
		if ( ! empty( $par_ev ) ) self::completer( $par_ev, $crees, $te, $cols, $etat );
		update_option( self::OPT_IMPORT, $import, false );
		update_option( self::OPT_CREES, array_values( array_unique( $crees ) ), false );
		update_option( self::OPT_ETAT, $etat, false );
		return $etat;
	}

	/* =====================================================================================
	 * Page détail de la Ligue (28/11/2026) : texte complet, affiche, documents
	 * =================================================================================== */

	const OPT_SIGN  = 'sp_cal_ligue_signatures';   // [ id événement créé => empreinte de ce que l'import y a écrit ]
	const OPT_DOCS  = 'sp_cal_ligue_docs';         // [ id événement du club => document joint posé par l'import ]
	const DEBUT_BLOC = '— Informations de la Ligue Occitanie (mises à jour automatiquement) —';
	const FIN_BLOC   = '— Fin des informations de la Ligue —';

	/** Texte (paragraphes), affiche et documents PDF de la page détail ; null si page inaccessible. */
	public static function detail( string $url, ?string $html = null ): ?array {
		if ( $html === null ) {
			if ( ! preg_match( '#^https://www\.taekwondo-occitanie\.com/#', $url ) ) return null;
			$r = wp_remote_get( $url, [ 'timeout' => 20, 'user-agent' => 'Mozilla/5.0 (compatible; club WordPress)' ] );
			if ( is_wp_error( $r ) || (int) wp_remote_retrieve_response_code( $r ) !== 200 ) return null;
			$html = (string) wp_remote_retrieve_body( $r );
		}
		if ( ! preg_match( '#<section id="main-content">(.*?)</section>#s', $html, $m ) ) return null;
		$bloc = $m[1];
		$abs  = static fn( string $u ) => strpos( $u, 'http' ) === 0 ? $u : 'https://www.taekwondo-occitanie.com/' . ltrim( html_entity_decode( $u ), '/' );
		$affiche = preg_match( '#<figure class="illustration">.*?<a[^>]+href="([^"]+)"#s', $bloc, $a ) ? $abs( $a[1] ) : '';
		$docs = [];
		if ( preg_match_all( '#<a[^>]+href="([^"]+\.(?:pdf|docx?|xlsx?))"[^>]*>(.*?)</a>#si', $bloc, $d, PREG_SET_ORDER ) ) {
			foreach ( $d as $x ) $docs[] = [ $abs( $x[1] ), trim( html_entity_decode( wp_strip_all_tags( $x[2] ), ENT_QUOTES, 'UTF-8' ) ) ?: basename( $x[1] ) ];
		}
		$bloc = (string) preg_replace( '#<figure.*?</figure>#s', '', $bloc );
		// Liens du texte : « texte (adresse) » quand l'adresse n'est pas déjà le texte.
		$bloc = (string) preg_replace_callback( '#<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>#s', static function ( $x ) use ( $abs ) {
			$t = trim( wp_strip_all_tags( $x[2] ) );
			$u = $abs( $x[1] );
			return ( $t === '' || strpos( $u, $t ) !== false ) ? $u : $t . ' (' . $u . ')';
		}, $bloc );
		$texte = html_entity_decode( wp_strip_all_tags( str_replace( [ '</p>', '<br>', '<br/>', '<br />', '</li>' ], "\n", $bloc ) ), ENT_QUOTES, 'UTF-8' );
		$lignes = array_values( array_filter( array_map( static fn( $l ) => trim( (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $l ) ), explode( "\n", $texte ) ), 'strlen' ) );
		return [ 'texte' => implode( "\n", $lignes ), 'affiche' => $affiche, 'docs' => $docs ];
	}

	/** Document à joindre : premier document PDF, sinon l'affiche. [ url, nom ] ou null. */
	private static function document( array $d ): ?array {
		if ( ! empty( $d['docs'] ) ) return [ $d['docs'][0][0], mb_substr( $d['docs'][0][1], 0, 190 ) ];
		return $d['affiche'] !== '' ? [ $d['affiche'], 'Affiche (Ligue Occitanie)' ] : null;
	}

	/** Informations d'une compétition : texte de la page, lieu, horaires, autres documents, lien. */
	private static function infos( array $c, ?array $d ): string {
		$l = [];
		if ( $d && $d['texte'] !== '' ) $l[] = $d['texte'];
		else $l[] = $c['titre'] . ( $c['lieu'] ? "\nLieu : " . $c['lieu'] : '' );
		if ( $c['debut'] ) $l[] = 'Horaires : ' . $c['debut'] . ( $c['fin'] ? ' - ' . $c['fin'] : '' );
		if ( $d ) {
			foreach ( array_slice( $d['docs'], 1 ) as $x ) $l[] = 'Document : ' . $x[1] . ' (' . $x[0] . ')';
			if ( ! empty( $d['docs'] ) && $d['affiche'] !== '' ) $l[] = 'Affiche : ' . $d['affiche'];
		}
		$l[] = 'Page de la Ligue : ' . ( $c['url'] ?: self::AGENDA );
		return implode( "\n", $l );
	}

	private static function empreinte( object $ev ): string {
		return md5( wp_unslash( (string) $ev->description ) . '|' . (string) ( $ev->document_url ?? '' ) . '|' . (string) $ev->heure_debut . '|' . (string) $ev->heure_fin );
	}

	/**
	 * Reprend les pages détail des compétitions à venir reliées au calendrier.
	 *  - événement créé par l'import : description, horaires et document remis à jour tant que
	 *    l'événement n'a pas été modifié à la main (empreinte de ce que l'import a écrit) ;
	 *  - événement saisi par le club : bloc « Informations de la Ligue » sous sa description
	 *    (remplacé à chaque passage, le texte du club n'est jamais touché) ; document joint
	 *    seulement s'il n'en a pas, puis tenu à jour tant que c'est celui posé par l'import.
	 */
	private static function completer( array $par_ev, array $crees, string $te, array $cols, array &$etat ): void {
		global $wpdb;
		$sign = (array) get_option( self::OPT_SIGN, [] );
		$docs = (array) get_option( self::OPT_DOCS, [] );
		$avec_doc = in_array( 'document_url', $cols, true );
		$etat['maj'] = 0;
		foreach ( $par_ev as $id => $items ) {
			$ev = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$te` WHERE id = %d", $id ) );
			if ( ! $ev ) continue;
			$details = [];
			foreach ( $items as [ $c ] ) $details[] = [ $c, $c['url'] ? self::detail( $c['url'] ) : null ];
			$note = '';
			if ( in_array( (int) $id, $crees, true ) ) {
				if ( isset( $sign[ $id ] ) && $sign[ $id ] !== self::empreinte( $ev ) ) {
					$note = 'modifiée à la main : plus mise à jour';
				} else {
					[ $c, $d ] = $details[0];
					$maj = [
						'description' => self::infos( $c, $d ) . "\n\nRepris automatiquement de l'agenda de la Ligue Occitanie.",
						'heure_debut' => $c['debut'] ?: (string) $ev->heure_debut,
						'heure_fin'   => $c['fin'] ?: (string) $ev->heure_fin,
					];
					$doc = $d ? self::document( $d ) : null;
					if ( $avec_doc && $doc ) { $maj['document_url'] = $doc[0]; $maj['document_nom'] = $doc[1]; }
					$apres = (object) array_merge( (array) $ev, $maj );
					if ( self::empreinte( $apres ) !== self::empreinte( $ev ) ) {
						$wpdb->update( $te, array_intersect_key( $maj, array_flip( $cols ) ), [ 'id' => (int) $id ] );
						$note = 'détails repris de la page de la Ligue';
						$etat['maj']++;
					}
					$sign[ $id ] = self::empreinte( $apres );
				}
			} else {
				$desc  = wp_unslash( (string) $ev->description );
				$club  = trim( (string) preg_replace( '/\n*' . preg_quote( self::DEBUT_BLOC, '/' ) . '.*?' . preg_quote( self::FIN_BLOC, '/' ) . '/su', '', $desc ) );
				$parts = [];
				foreach ( $details as [ $c, $d ] ) $parts[] = ( count( $details ) > 1 ? '▶ ' . $c['titre'] . "\n" : '' ) . self::infos( $c, $d );
				$bloc  = self::DEBUT_BLOC . "\n" . implode( "\n\n", $parts ) . "\n" . self::FIN_BLOC;
				$maj   = [ 'description' => ( $club !== '' ? $club . "\n\n" : '' ) . $bloc ];
				$doc   = $details[0][1] ? self::document( $details[0][1] ) : null;
				$actuel = (string) ( $ev->document_url ?? '' );
				if ( $avec_doc && $doc && ( $actuel === '' || ( isset( $docs[ $id ] ) && $docs[ $id ] === $actuel ) ) ) {
					$maj['document_url'] = $doc[0];
					$maj['document_nom'] = $doc[1];
					$docs[ $id ] = $doc[0];
				}
				if ( $maj['description'] !== $desc || ( isset( $maj['document_url'] ) && $maj['document_url'] !== $actuel ) ) {
					$wpdb->update( $te, $maj, [ 'id' => (int) $id ] );
					$note = 'informations de la Ligue ajoutées sous la description du club';
					$etat['maj']++;
				}
			}
			if ( $note !== '' ) foreach ( $items as [ , $i ] ) $etat['trouves'][ $i ]['statut'] .= ' — ' . $note;
		}
		update_option( self::OPT_SIGN, $sign, false );
		update_option( self::OPT_DOCS, $docs, false );
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
		if ( isset( $_GET['ligue'] ) && is_array( $etat ) ) echo '<div class="notice notice-success inline"><p>Import fait : ' . (int) $etat['crees'] . ' compétition(s) ajoutée(s) au calendrier, ' . (int) ( $etat['maj'] ?? 0 ) . ' mise(s) à jour depuis les pages de la Ligue.</p></div>';
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

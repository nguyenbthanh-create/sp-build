<?php
/**
 * Stages de préparation Dan importés d'IzziDan (28/11/2026, chantier évolution lot 2).
 *
 * IzziDan (application d'examens Dan de la FFTDA) publie la liste nationale des stages de
 * préparation Dan, consultable sans compte : https://fftda.izzidan.com/stage/?p=liste-stages
 * (date, organisateur « LIGUE … » / « C.D.T. … », grades ; adresse et horaires sur la page
 * d'inscription des stages encore ouverts).
 *
 * Une fois par jour (et sur demande), les stages préparant au 1er Dan organisés par la Ligue
 * Occitanie ou par les comités départementaux cochés sur la page « Candidats 1er Dan » sont
 * recopiés dans le calendrier du club : événement « Stage prépa Dan — C.D.T. Aude », marqué
 * départemental (comité) ou régional (Ligue), sans inscriptions. Le club coche ensuite les
 * présences : c'est ce qui compte pour l'éligibilité (class-eligibilite-dan.php).
 *
 * Jamais de doublon ni d'écrasement : un stage déjà importé (même supprimé ensuite du calendrier)
 * n'est pas recréé, un stage déjà saisi à la main le même jour n'est pas recopié, et un événement
 * importé n'est jamais modifié par l'import suivant.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SP_Cal_Stages_Izzidan' ) ) :

final class SP_Cal_Stages_Izzidan {

	const LISTE      = 'https://fftda.izzidan.com/stage/?p=liste-stages';
	const DETAIL     = 'https://fftda.izzidan.com/stage/?p=inscription-stage&id_stage=';
	const OPT_ORGS   = 'sp_cal_izzidan_organisateurs';   // clés cochées (absente = toutes)
	const OPT_IMPORT = 'sp_cal_izzidan_import';          // [ clé du stage => id de l'événement ]
	const OPT_ETAT   = 'sp_cal_izzidan_etat';            // dernier passage
	const CRON       = 'sp_cal_izzidan_quotidien';

	/** Organisateurs d'Occitanie : la Ligue et les 13 comités départementaux. */
	const ORGANISATEURS = [
		'ligue-occitanie'     => 'Ligue Occitanie',
		'ariege'              => 'Ariège (09)',
		'aude'                => 'Aude (11)',
		'aveyron'             => 'Aveyron (12)',
		'gard'                => 'Gard (30)',
		'haute-garonne'       => 'Haute-Garonne (31)',
		'gers'                => 'Gers (32)',
		'herault'             => 'Hérault (34)',
		'lot'                 => 'Lot (46)',
		'lozere'              => 'Lozère (48)',
		'hautes-pyrenees'     => 'Hautes-Pyrénées (65)',
		'pyrenees-orientales' => 'Pyrénées-Orientales (66)',
		'tarn'                => 'Tarn (81)',
		'tarn-et-garonne'     => 'Tarn-et-Garonne (82)',
	];

	const MOIS = [ 'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7,
		'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12 ];

	private static $instance = null;

	public static function get_instance(): self {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ __CLASS__, 'planifier' ], 99 );
		add_action( self::CRON, [ __CLASS__, 'tache' ] );
		add_action( 'admin_post_sp_cal_izzidan_reglages', [ $this, 'handle_reglages' ] );
		add_action( 'admin_post_sp_cal_izzidan_importer', [ $this, 'handle_importer' ] );
	}

	public static function tache(): void {
		self::synchroniser();
	}

	public static function planifier(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) wp_schedule_event( time() + 600, 'daily', self::CRON );
	}

	/** « Haute-Garonne » → « haute-garonne », « Hérault » → « herault ». */
	public static function cle( string $nom ): string {
		$n = strtolower( remove_accents( trim( $nom ) ) );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $n ), '-' );
	}

	public static function coches(): array {
		$o = get_option( self::OPT_ORGS );
		return is_array( $o ) ? array_values( array_intersect( $o, array_keys( self::ORGANISATEURS ) ) ) : array_keys( self::ORGANISATEURS );
	}

	/* =====================================================================================
	 * Lecture d'IzziDan
	 * =================================================================================== */

	private static function get( string $url ): ?string {
		$r = wp_remote_get( $url, [ 'timeout' => 20, 'user-agent' => 'Mozilla/5.0 (compatible; club WordPress)' ] );
		if ( is_wp_error( $r ) || (int) wp_remote_retrieve_response_code( $r ) !== 200 ) return null;
		return (string) wp_remote_retrieve_body( $r );
	}

	private static function texte( string $html ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( str_replace( '<br', ' <br', $html ) ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/** « 28 Novembre 2026 » → « 2026-11-28 », '' si illisible. */
	public static function date_fr( string $t ): string {
		if ( ! preg_match( '/(\d{1,2})\s+([[:alpha:]\x{00C0}-\x{017F}]+)\s+(\d{4})/u', $t, $m ) ) return '';
		$mois = self::MOIS[ strtolower( remove_accents( $m[2] ) ) ] ?? 0;
		return $mois && checkdate( $mois, (int) $m[1], (int) $m[3] ) ? sprintf( '%04d-%02d-%02d', $m[3], $mois, $m[1] ) : '';
	}

	/**
	 * Stages de la liste IzziDan : [ date, organisateur (texte), type ligue|cdt, nom, cle, grades, id ].
	 * null si la page est inaccessible.
	 */
	public static function lire( ?string $html = null ): ?array {
		$html = $html ?? self::get( self::LISTE );
		if ( $html === null ) return null;
		$out = [];
		preg_match_all( '#<tr>(.*?)</tr>#si', $html, $lignes );
		foreach ( $lignes[1] as $tr ) {
			preg_match_all( '#<td[^>]*>(.*?)</td>#si', $tr, $td );
			if ( count( $td[1] ) < 4 ) continue;
			$date = self::date_fr( self::texte( preg_replace( '#<span.*$#si', '', $td[1][0] ) ) );
			if ( $date === '' ) continue;
			$org = self::texte( $td[1][1] );
			if ( preg_match( '/^LIGUE\s+(.+)$/iu', $org, $m ) ) { $type = 'ligue'; $nom = $m[1]; $cle = 'ligue-' . self::cle( $m[1] ); }
			elseif ( preg_match( '/^C\.?\s*D\.?\s*T\.?\s+(.+)$/iu', $org, $m ) ) { $type = 'cdt'; $nom = $m[1]; $cle = self::cle( $m[1] ); }
			else { $type = 'autre'; $nom = $org; $cle = self::cle( $org ); }
			$out[] = [
				'date'   => $date,
				'org'    => $org,
				'type'   => $type,
				'nom'    => trim( $nom ),
				'cle'    => $cle,
				'grades' => self::texte( $td[1][3] ),
				'id'     => preg_match( '/inscription-stage"\s*,\s*(\d+)/', $tr, $i ) ? (int) $i[1] : 0,
			];
		}
		return $out;
	}

	/** Stage qui prépare au 1er Dan (« Préparation au 1er dan », « … du 1er dan au 4ème dan »). */
	public static function prepare_1er_dan( string $grades ): bool {
		return (bool) preg_match( '/\b1\s*er\s*dan\b/', strtolower( remove_accents( $grades ) ) );
	}

	/** Adresse et horaires de la page d'inscription (stages encore ouverts). */
	public static function detail( int $id, ?string $html = null ): array {
		$html = $html ?? ( $id ? self::get( self::DETAIL . $id ) : null );
		if ( ! $html ) return [ 'adresse' => '', 'debut' => '', 'fin' => '' ];
		$t = self::texte( (string) preg_replace( '#<(script|style)[^>]*>.*?</\1>#si', '', $html ) );
		$adresse = preg_match( '/Adresse\s*:\s*(.+?)\s*(Horaires|Clôture|Cl\x{00F4}ture|$)/u', $t, $a ) ? trim( $a[1] ) : '';
		$h = preg_match( '/Horaires\s*:\s*De\s*(\d{1,2})[:h](\d{2})\s*à\s*(\d{1,2})[:h](\d{2})/u', $t, $m );
		return [
			'adresse' => mb_substr( $adresse, 0, 200 ),
			'debut'   => $h ? sprintf( '%02d:%02d', $m[1], $m[2] ) : '',
			'fin'     => $h ? sprintf( '%02d:%02d', $m[3], $m[4] ) : '',
		];
	}

	/* =====================================================================================
	 * Import dans le calendrier
	 * =================================================================================== */

	/** Stages d'Occitanie de la liste qui préparent au 1er Dan, avec leur clé d'organisateur. */
	public static function occitanie( array $stages ): array {
		return array_values( array_filter( $stages, static function ( $s ) {
			return $s['type'] !== 'autre' && isset( self::ORGANISATEURS[ $s['cle'] ] ) && self::prepare_1er_dan( $s['grades'] );
		} ) );
	}

	public static function synchroniser(): array {
		global $wpdb;
		$etat = [ 'date' => current_time( 'd/m/Y H:i' ), 'erreur' => '', 'trouves' => [], 'crees' => 0 ];
		$liste = self::lire();
		if ( $liste === null ) {
			$etat['erreur'] = 'liste des stages IzziDan inaccessible';
			update_option( self::OPT_ETAT, $etat, false );
			return $etat;
		}
		$te      = $wpdb->prefix . 'sp_cal_events';
		$cols    = (array) $wpdb->get_col( "SHOW COLUMNS FROM `$te`" );
		$import  = (array) get_option( self::OPT_IMPORT, [] );
		$coches  = self::coches();
		$debut   = SP_Cal_Eligibilite_Dan::bornes( SP_Cal_Eligibilite_Dan::saison_courante() )[0];
		$couleur = class_exists( 'SpCalPro_DB' ) ? ( SpCalPro_DB::style_categorie( 'Taekwondo' )['color'] ?? '' ) : '';

		// Un même événement rattaché à deux stages du même jour (ex. Ligue et C.D.T. Hérault le
		// 25/04/2027, constaté au premier import en prod) : on ne garde que le rattachement dont
		// l'organisateur figure dans le titre ; les autres stages seront importés à part.
		$par_ev = [];
		foreach ( $import as $k => $id ) $par_ev[ (int) $id ][] = (string) $k;
		foreach ( $par_ev as $id => $cles ) {
			if ( count( $cles ) < 2 ) continue;
			$titre = str_replace( '-', ' ', strtolower( remove_accents( wp_unslash( (string) $wpdb->get_var( $wpdb->prepare( "SELECT titre FROM `$te` WHERE id = %d", $id ) ) ) ) ) );
			foreach ( $cles as $k ) {
				$org = (string) ( explode( '|', $k )[1] ?? '' );
				$nom = $org === 'ligue-occitanie' ? 'ligue' : str_replace( '-', ' ', $org );
				if ( strpos( $titre, $nom ) === false ) unset( $import[ $k ] );
			}
		}

		foreach ( self::occitanie( $liste ) as $s ) {
			$cle    = $s['date'] . '|' . $s['cle'];
			$niveau = $s['type'] === 'ligue' ? 'regional' : 'departemental';
			$ligne  = [ 'date' => $s['date'], 'org' => $s['org'], 'grades' => $s['grades'], 'statut' => '' ];
			if ( $s['date'] < $debut ) { $ligne['statut'] = 'saison passée'; }
			elseif ( ! in_array( $s['cle'], $coches, true ) ) { $ligne['statut'] = 'comité non coché'; }
			elseif ( isset( $import[ $cle ] ) ) { $ligne['statut'] = 'déjà importé'; $ligne['event'] = (int) $import[ $cle ]; }
			else {
				// Stage déjà saisi à la main ce jour-là (marqué départemental/régional ou titre « prépa Dan »).
				$meme = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$te` WHERE date = %s AND type <> 'annulation'", $s['date'] ) );
				$deja = null;
				foreach ( $meme as $ev ) {
					if ( in_array( (int) $ev->id, array_map( 'intval', $import ), true ) ) continue;   // déjà lié à un autre stage
					if ( in_array( (string) ( $ev->prepa_dan ?? '' ), [ 'departemental', 'regional' ], true ) || SP_Cal_Eligibilite_Dan::titre_stage( wp_unslash( (string) $ev->titre ) ) ) { $deja = $ev; break; }
				}
				if ( $deja ) {
					$import[ $cle ]  = (int) $deja->id;
					$ligne['statut'] = 'déjà au calendrier';
					$ligne['event']  = (int) $deja->id;
					if ( (string) ( $deja->prepa_dan ?? '' ) === '' && in_array( 'prepa_dan', $cols, true ) ) {
						$wpdb->update( $te, [ 'prepa_dan' => $niveau ], [ 'id' => (int) $deja->id ] );   // classé d'après IzziDan
						$ligne['statut'] = 'déjà au calendrier (niveau ajouté)';
					}
				} else {
					$d     = self::detail( $s['id'] );
					$nom   = $s['type'] === 'ligue' ? 'Ligue ' . $s['nom'] : 'C.D.T. ' . $s['nom'];
					$desc  = 'Stage de préparation Dan ' . ( $niveau === 'regional' ? 'régional' : 'départemental' ) . ' — ' . $nom . ' — ' . $s['grades'] . '.';
					if ( $d['adresse'] ) $desc .= "\nAdresse : " . $d['adresse'];
					if ( $d['debut'] ) $desc .= "\nHoraires : " . $d['debut'] . ' - ' . $d['fin'];
					$desc .= "\nInscriptions des candidats sur IzziDan : " . ( $s['id'] ? self::DETAIL . $s['id'] : self::LISTE );
					$desc .= "\nImporté automatiquement d'IzziDan le " . current_time( 'd/m/Y' ) . '. Après le stage, cocher les présents (onglet Présences) : c\'est ce qui compte pour le 1er Dan.';
					$data = [
						'date'                 => $s['date'],
						'heure_debut'          => $d['debut'],
						'heure_fin'            => $d['fin'],
						'titre'                => 'Stage prépa Dan — ' . $nom,
						'categorie'            => 'Taekwondo',
						'cours_discipline'     => 'Taekwondo',
						'couleur'              => $couleur ?: '#3B82F6',
						'type'                 => 'evenement',
						'description'          => $desc,
						'prepa_dan'            => $niveau,
						'inscriptions_actives' => 0,
					];
					$data = array_intersect_key( $data, array_flip( $cols ) );
					if ( $wpdb->insert( $te, $data ) ) {
						$import[ $cle ]  = (int) $wpdb->insert_id;
						$ligne['statut'] = 'importé';
						$ligne['event']  = (int) $wpdb->insert_id;
						$etat['crees']++;
					} else {
						$ligne['statut'] = 'erreur à l\'import';
					}
				}
			}
			$etat['trouves'][] = $ligne;
		}
		update_option( self::OPT_IMPORT, $import, false );
		update_option( self::OPT_ETAT, $etat, false );
		return $etat;
	}

	/* =====================================================================================
	 * Administration (bloc de la page « Candidats 1er Dan »)
	 * =================================================================================== */

	public function handle_reglages(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'sp_cal_izzidan_reglages' );
		$coches = array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['organisateurs'] ?? [] ) ), array_keys( self::ORGANISATEURS ) ) );
		update_option( self::OPT_ORGS, $coches, false );
		self::synchroniser();
		wp_safe_redirect( admin_url( 'admin.php?page=' . SP_Cal_Eligibilite_Dan::PAGE . '&izzidan=1#sp-izzidan' ) );
		exit;
	}

	public function handle_importer(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'sp_cal_izzidan_importer' );
		self::synchroniser();
		wp_safe_redirect( admin_url( 'admin.php?page=' . SP_Cal_Eligibilite_Dan::PAGE . '&izzidan=1#sp-izzidan' ) );
		exit;
	}

	private static function fr( string $ymd ): string {
		return substr( $ymd, 8, 2 ) . '/' . substr( $ymd, 5, 2 ) . '/' . substr( $ymd, 0, 4 );
	}

	public static function render(): void {
		$coches = self::coches();
		$etat   = get_option( self::OPT_ETAT );
		echo '<div id="sp-izzidan" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;max-width:1000px;margin-top:14px;">';
		echo '<h2 style="margin-top:0;">🔄 Stages départementaux et régionaux (IzziDan)</h2>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- message de retour
		if ( isset( $_GET['izzidan'] ) ) echo '<div class="notice notice-success inline"><p>Import IzziDan fait' . ( is_array( $etat ) ? ' : ' . (int) $etat['crees'] . ' stage(s) ajouté(s) au calendrier.' : '.' ) . '</p></div>';
		echo '<p>Chaque jour, les stages de préparation au 1er Dan publiés sur <a href="' . esc_url( self::LISTE ) . '" target="_blank" rel="noopener">IzziDan</a> par les organisateurs cochés sont ajoutés au calendrier du club (sans inscriptions). '
			. 'Il reste à <strong>cocher les présents</strong> après chaque stage. Les prépas du club ne comptent pas pour l\'examen.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sp_cal_izzidan_reglages">';
		wp_nonce_field( 'sp_cal_izzidan_reglages' );
		echo '<p style="margin-bottom:4px;"><strong>Organisateurs pris en compte :</strong> <a href="#" onclick="document.querySelectorAll(\'.sp-izz-org\').forEach(c=>c.checked=true);return false;">tout cocher</a> · <a href="#" onclick="document.querySelectorAll(\'.sp-izz-org\').forEach(c=>c.checked=false);return false;">tout décocher</a></p>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:4px 12px;">';
		foreach ( self::ORGANISATEURS as $k => $lib ) {
			echo '<label><input type="checkbox" class="sp-izz-org" name="organisateurs[]" value="' . esc_attr( $k ) . '"' . ( in_array( $k, $coches, true ) ? ' checked' : '' ) . '> '
				. ( $k === 'ligue-occitanie' ? '<strong>' . esc_html( $lib ) . '</strong> (régional)' : 'C.D.T. ' . esc_html( $lib ) ) . '</label>';
		}
		echo '</div><p><button class="button button-primary">Enregistrer et importer</button></p></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;"><input type="hidden" name="action" value="sp_cal_izzidan_importer">';
		wp_nonce_field( 'sp_cal_izzidan_importer' );
		echo '<button class="button">🔄 Importer maintenant</button></form>';
		echo ' <span class="description">Un organisateur décoché n\'est plus importé ; les stages déjà ajoutés restent dans le calendrier.</span>';

		if ( is_array( $etat ) ) {
			echo '<p style="margin-top:12px;">Dernier import : ' . esc_html( $etat['date'] ) . ( $etat['erreur'] ? ' — <span style="color:#dc2626;">' . esc_html( $etat['erreur'] ) . '</span>' : '' ) . '</p>';
			if ( ! empty( $etat['trouves'] ) ) {
				echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Organisateur</th><th>Grades</th><th>Dans le calendrier du club</th></tr></thead><tbody>';
				foreach ( $etat['trouves'] as $l ) {
					echo '<tr><td>' . esc_html( self::fr( $l['date'] ) ) . '</td><td>' . esc_html( $l['org'] ) . '</td><td>' . esc_html( $l['grades'] ) . '</td><td>' . esc_html( $l['statut'] ) . '</td></tr>';
				}
				echo '</tbody></table>';
			} elseif ( ! $etat['erreur'] ) {
				echo '<p class="description">Aucun stage de préparation au 1er Dan en Occitanie sur IzziDan pour l\'instant.</p>';
			}
		} else {
			echo '<p class="description">Premier import : demain au plus tard, ou cliquez sur « Importer maintenant ».</p>';
		}
		echo '</div>';
	}
}

endif;

<?php
/**
 * Éligibilité à l'examen du 1er Dan — calcul unique (27/11/2026, chantier évolution lot 2).
 *
 * Remplace les deux calculs qui existaient (fiche admin dans trait-members-eleves.php et espace
 * adhérent dans class-token.php) et la case « Éligible 1e Dan » cochée à la main.
 *
 * Règles (FFTDA, données par l'utilisateur le 27/11/2026) :
 *  1. 3 licences dont celle de la saison en cours (les 2 autres continues ou non).
 *     Saisons licenciées au club : extra_data « saisons_licence », complété à chaque adhésion,
 *     renouvellement et enregistrement de fiche ; licences hors club : extra_data
 *     « licences_hors_club », saisi à la main (initialisé depuis l'ancien champ nb_licences).
 *  2. 14 ans révolus le jour de l'examen : anniversaire des 14 ans au plus tard le jour de
 *     l'examen. Date de l'examen réglée à la main par saison (option sp_cal_dan_examens), sinon
 *     30 juin de fin de saison (ancienne règle).
 *  3. 2 stages de préparation Dan (départementaux ou régionaux) dont un dans la saison en cours.
 *     Preuve : présence pointée à un événement du calendrier marqué « stage de préparation Dan »
 *     départemental ou régional (colonne events.prepa_dan), ou stage fait ailleurs ajouté à la main
 *     sur la fiche (extra_data « stages_dan_externes »). Les prépas du club ne comptent pas : un
 *     titre « prépa Dan » ne suffit plus (28/11/2026) — ces événements sont listés « à classer ».
 *     Les stages départementaux et régionaux d'Occitanie sont importés d'IzziDan
 *     (class-stages-izzidan.php).
 * Une dérogation avec motif (extra_data « derogation_dan ») rend l'adhérent éligible.
 * Rien n'est recalculé en base sauf nb_licences et eligible_dan, tenus à jour à l'enregistrement
 * de la fiche pour le code qui les lit encore.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SP_Cal_Eligibilite_Dan' ) ) :

final class SP_Cal_Eligibilite_Dan {

	const LICENCES     = 3;
	const AGE          = 14;
	const STAGES       = 2;
	const OPT_EXAMENS  = 'sp_cal_dan_examens';          // [ '2026/2027' => '2027-05-23' ]
	const ICS          = 'https://www.taekwondo-occitanie.com/evenements.ics';
	const TRANSIENT    = 'sp_cal_dan_ics';
	const PAGE         = 'sp-cal-dan';
	const NIVEAUX      = [ 'departemental' => 'Départemental', 'regional' => 'Régional' ];

	private static $instance = null;
	private static $stages_cache = null;

	public static function get_instance(): self {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ], 30 );
		add_action( 'admin_post_sp_cal_dan_examen', [ $this, 'handle_examen' ] );
		add_action( 'admin_post_sp_cal_dan_cibler', [ $this, 'handle_cibler' ] );
		add_action( 'admin_post_sp_cal_dan_classer', [ $this, 'handle_classer' ] );
		SP_Cal_Schema::enregistrer( 'Éligibilité 1er Dan', [ __CLASS__, 'maybe_upgrade' ] );
	}

	/* =====================================================================================
	 * Base de données
	 * =================================================================================== */

	/**
	 * Colonne events.prepa_dan ('departemental' ou 'regional' = compté ; '' ou 'non' = pas compté) et
	 * reprise de l'historique : saison actuelle des adhérents actifs ajoutée à saisons_licence,
	 * licences_hors_club initialisé depuis nb_licences. Idempotent.
	 */
	public static function maybe_upgrade(): void {
		global $wpdb;
		$te = $wpdb->prefix . 'sp_cal_events';
		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$te` LIKE 'prepa_dan'" ) ) {
			$wpdb->query( "ALTER TABLE `$te` ADD COLUMN `prepa_dan` varchar(20) NOT NULL DEFAULT ''" );
			if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `$te` LIKE 'prepa_dan'" ) ) {
				throw new \RuntimeException( 'colonne prepa_dan absente de ' . $te );
			}
		}
		if ( get_option( 'sp_cal_dan_reprise' ) ) return;
		$tel = $wpdb->prefix . 'sp_cal_eleves';
		foreach ( (array) $wpdb->get_results( "SELECT id, saison, actif, nb_licences, extra_data FROM `$tel`" ) as $el ) {
			$extra = json_decode( (string) $el->extra_data, true );
			$extra = is_array( $extra ) ? $extra : [];
			$avant = $extra;
			if ( (int) $el->actif === 1 && trim( (string) $el->saison ) !== '' ) {
				$extra = self::ajouter_saison( $extra, (string) $el->saison );
			}
			if ( ! array_key_exists( 'licences_hors_club', $extra ) ) {
				$extra['licences_hors_club'] = max( 0, (int) $el->nb_licences - count( (array) ( $extra['saisons_licence'] ?? [] ) ) );
			}
			if ( $extra !== $avant ) {
				$wpdb->update( $tel, [ 'extra_data' => wp_json_encode( $extra ) ], [ 'id' => (int) $el->id ] );
			}
		}
		update_option( 'sp_cal_dan_reprise', current_time( 'mysql' ), false );
	}

	public static function colonne_prepa(): bool {
		static $ok = null;
		if ( $ok === null ) {
			global $wpdb;
			$ok = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$wpdb->prefix}sp_cal_events` LIKE 'prepa_dan'" );
		}
		return $ok;
	}

	/* =====================================================================================
	 * Saisons et dates
	 * =================================================================================== */

	public static function saison_courante(): string {
		$s = trim( (string) get_option( 'tkd_saison_courante', '' ) );
		if ( preg_match( '#^\d{4}/\d{4}$#', $s ) ) return $s;
		$y = (int) current_time( 'Y' );
		return (int) current_time( 'n' ) >= 9 ? $y . '/' . ( $y + 1 ) : ( $y - 1 ) . '/' . $y;
	}

	/** [ début, fin ] d'une saison « 2026/2027 » : 01/09/2026 → 31/08/2027. */
	public static function bornes( string $saison ): array {
		$y = (int) substr( $saison, 0, 4 );
		return [ $y . '-09-01', ( $y + 1 ) . '-08-31' ];
	}

	/** Date de l'examen visé (Y-m-d) et si elle a été réglée à la main. */
	public static function date_examen( ?string $saison = null ): array {
		$saison = $saison ?? self::saison_courante();
		$dates  = (array) get_option( self::OPT_EXAMENS, [] );
		$d      = (string) ( $dates[ $saison ] ?? '' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) return [ $d, true ];
		return [ ( (int) substr( $saison, 0, 4 ) + 1 ) . '-06-30', false ];
	}

	public static function ajouter_saison( array $extra, string $saison ): array {
		$saison = trim( $saison );
		if ( ! preg_match( '#^\d{4}/\d{4}$#', $saison ) ) return $extra;
		$liste = array_values( array_unique( array_merge( (array) ( $extra['saisons_licence'] ?? [] ), [ $saison ] ) ) );
		sort( $liste );
		$extra['saisons_licence'] = $liste;
		return $extra;
	}

	private static function extra( object $el ): array {
		$e = json_decode( (string) ( $el->extra_data ?? '' ), true );
		return is_array( $e ) ? $e : [];
	}

	/** Date de naissance complète (Y-m-d) depuis date_naissance « jj/mm » + annee_naissance. */
	private static function naissance( object $el ): string {
		$p = explode( '/', (string) ( $el->date_naissance ?? '' ) );
		$a = (int) ( $el->annee_naissance ?? 0 );
		if ( count( $p ) < 2 || $a < 1900 || ! checkdate( (int) $p[1], (int) $p[0], $a ) ) return '';
		return sprintf( '%04d-%02d-%02d', $a, (int) $p[1], (int) $p[0] );
	}

	private static function fr( string $ymd ): string {
		return $ymd ? substr( $ymd, 8, 2 ) . '/' . substr( $ymd, 5, 2 ) . '/' . substr( $ymd, 0, 4 ) : '';
	}

	/* =====================================================================================
	 * Stages
	 * =================================================================================== */

	/** Titre qui ressemble à un stage de préparation au 1er Dan (pas « 5e Dan et plus »). */
	public static function titre_stage( string $titre ): bool {
		$t = remove_accents( mb_strtolower( $titre ) );
		if ( ! preg_match( '/prepa(ration)?\b.*\bdan\b/', $t ) ) return false;
		return ! preg_match( '/\b[5-9]\s*(e|eme|ieme)?\s*dan\s+et\s+plus/', $t );
	}

	public static function est_stage( object $ev ): bool {
		// Seuls les stages départementaux et régionaux comptent (choix de l'utilisateur, 28/11/2026).
		return ( $ev->type ?? '' ) !== 'annulation' && isset( self::NIVEAUX[ (string) ( $ev->prepa_dan ?? '' ) ] );
	}

	/** Stages du calendrier avec les adhérents pointés présents : [ eleve_id => [ stage, … ] ]. */
	private static function stages_club(): array {
		if ( self::$stages_cache !== null ) return self::$stages_cache;
		global $wpdb;
		$te   = $wpdb->prefix . 'sp_cal_events';
		$tp   = $wpdb->prefix . 'sp_cal_presences_eleves';
		$col  = self::colonne_prepa() ? 'e.prepa_dan' : "''";
		$rows = (array) $wpdb->get_results(
			"SELECT e.id, e.date, e.titre, e.type, {$col} AS prepa_dan, p.eleve_id
			 FROM `$te` e JOIN `$tp` p ON p.event_id = e.id AND p.present = 1
			 WHERE e.type <> 'annulation' AND {$col} IN ('departemental','regional')"
		);
		$out = [];
		foreach ( $rows as $r ) {
			if ( ! self::est_stage( $r ) ) continue;
			$out[ (int) $r->eleve_id ][] = [
				'date'   => (string) $r->date,
				'titre'  => wp_unslash( (string) $r->titre ),
				'niveau' => self::NIVEAUX[ (string) $r->prepa_dan ] ?? 'niveau non précisé',
				'source' => 'club',
			];
		}
		return self::$stages_cache = $out;
	}

	/* =====================================================================================
	 * Calcul
	 * =================================================================================== */

	/**
	 * Évalue un adhérent (ligne de sp_cal_eleves). Retour :
	 *  licences / age / stages : [ ok, texte, … ] ; derogation : [motif, date, par] ou null ;
	 *  eligible ; manque (textes courts de ce qui manque).
	 */
	public static function evaluer( object $el, ?string $saison = null ): array {
		$saison = $saison ?? self::saison_courante();
		$extra  = self::extra( $el );

		// 1. Licences
		$club = (array) ( $extra['saisons_licence'] ?? [] );
		if ( (int) ( $el->actif ?? 0 ) === 1 && trim( (string) ( $el->saison ?? '' ) ) !== '' ) {
			$club = (array) self::ajouter_saison( [ 'saisons_licence' => $club ], (string) $el->saison )['saisons_licence'];
		}
		$hors    = array_key_exists( 'licences_hors_club', $extra )
			? max( 0, (int) $extra['licences_hors_club'] )
			: max( 0, (int) ( $el->nb_licences ?? 0 ) - count( $club ) );
		$total   = count( $club ) + $hors;
		$encours = (int) ( $el->actif ?? 0 ) === 1 && trim( (string) ( $el->saison ?? '' ) ) === $saison;
		$lic_ok  = $encours && $total >= self::LICENCES;
		$lic_txt = $total . ' licence' . ( $total > 1 ? 's' : '' ) . ' (' . count( $club ) . ' au club' . ( $hors ? ', ' . $hors . ' hors club' : '' ) . ')'
			. ( $encours ? ', dont celle de ' . $saison : ' — licence ' . $saison . ' non prise' );

		// 2. Âge le jour de l'examen
		[ $exam, $exam_regle ] = self::date_examen( $saison );
		$naiss   = self::naissance( $el );
		$date14  = $naiss ? ( (int) substr( $naiss, 0, 4 ) + self::AGE ) . substr( $naiss, 4 ) : '';
		if ( $naiss && substr( $naiss, 5 ) === '02-29' && ! checkdate( 2, 29, (int) substr( $date14, 0, 4 ) ) ) {
			$date14 = substr( $date14, 0, 4 ) . '-03-01';   // né un 29 février : 14 ans le 1er mars
		}
		$age_ok  = $date14 !== '' && $date14 <= $exam;
		$exam_tx = ( $exam_regle ? 'examen du ' : 'date d\'examen non réglée, calcul au ' ) . self::fr( $exam );
		$age_txt = $date14 === '' ? 'date de naissance manquante'
			: ( $age_ok ? '14 ans le ' . self::fr( $date14 ) . ' (' . $exam_tx . ')' : 'aura 14 ans le ' . self::fr( $date14 ) . ', après l\'' . ( $exam_regle ? 'examen du ' : 'échéance du ' ) . self::fr( $exam ) );

		// 3. Stages
		$stages = self::stages_club()[ (int) ( $el->id ?? 0 ) ] ?? [];
		foreach ( (array) ( $extra['stages_dan_externes'] ?? [] ) as $s ) {
			if ( empty( $s['date'] ) ) continue;
			$stages[] = [
				'date'   => (string) $s['date'],
				'titre'  => 'Stage fait ailleurs' . ( ! empty( $s['lieu'] ) ? ' — ' . $s['lieu'] : '' ),
				'niveau' => self::NIVEAUX[ (string) ( $s['niveau'] ?? '' ) ] ?? 'niveau non précisé',
				'source' => 'externe',
			];
		}
		usort( $stages, static fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );
		[ $deb, $fin ] = self::bornes( $saison );
		$dans    = count( array_filter( $stages, static fn( $s ) => $s['date'] >= $deb && $s['date'] <= $fin ) );
		$stg_ok  = count( $stages ) >= self::STAGES && $dans >= 1;
		$stg_txt = count( $stages ) . ' stage' . ( count( $stages ) > 1 ? 's' : '' ) . ' sur ' . self::STAGES . ', dont ' . $dans . ' en ' . $saison;

		$derog = is_array( $extra['derogation_dan'] ?? null ) && ! empty( $extra['derogation_dan']['motif'] ) ? $extra['derogation_dan'] : null;

		$manque = [];
		if ( ! $lic_ok ) $manque[] = ! $encours ? 'licence ' . $saison : ( self::LICENCES - $total ) . ' licence(s)';
		if ( ! $age_ok ) $manque[] = $date14 === '' ? 'date de naissance' : 'âge';
		if ( ! $stg_ok ) {
			$n = max( self::STAGES - count( $stages ), $dans >= 1 ? 0 : 1 );
			$manque[] = $n . ' stage' . ( $n > 1 ? 's' : '' ) . ( $dans >= 1 ? '' : ( $n > 1 ? ' dont 1 dans la saison' : ' dans la saison' ) );
		}

		return [
			'saison'     => $saison,
			'licences'   => [ 'ok' => $lic_ok, 'texte' => $lic_txt, 'club' => $club, 'hors_club' => $hors, 'total' => $total, 'en_cours' => $encours ],
			'age'        => [ 'ok' => $age_ok, 'texte' => $age_txt, 'date_14' => $date14, 'examen' => $exam, 'examen_regle' => $exam_regle ],
			'stages'     => [ 'ok' => $stg_ok, 'texte' => $stg_txt, 'liste' => $stages, 'saison' => $dans ],
			'derogation' => $derog,
			'conditions' => $lic_ok && $age_ok && $stg_ok,
			'eligible'   => ( $lic_ok && $age_ok && $stg_ok ) || $derog !== null,
			'manque'     => $manque,
		];
	}

	/** Bloc concerné : adhérent sans Dan, Ado/Adulte ou d'au moins 13 ans le jour de l'examen. */
	public static function concerne( object $el ): bool {
		if ( preg_match( '/\bdan\b/i', (string) ( $el->grade ?? '' ) ) ) return false;
		if ( in_array( (string) ( $el->categorie_age ?? '' ), [ 'Ado/adulte', 'Adulte' ], true ) ) return true;
		$n = self::naissance( $el );
		return $n !== '' && ( (int) substr( self::date_examen()[0], 0, 4 ) - (int) substr( $n, 0, 4 ) ) >= 13;
	}

	/* =====================================================================================
	 * Fiche admin : enregistrement
	 * =================================================================================== */

	/**
	 * Met à jour extra_data depuis le formulaire de la fiche (licences hors club, stages faits
	 * ailleurs, dérogation) et enregistre la saison de la fiche si l'adhérent est actif.
	 * Renvoie [ extra, nb_licences, eligible_dan ].
	 */
	public static function depuis_formulaire( array $extra, array $post, string $saison_fiche, int $actif, int $id, int $nb_avant = 0 ): array {
		// Avant toute saison ajoutée : licences hors club reprises de l'ancien compteur si jamais réglées.
		if ( ! array_key_exists( 'licences_hors_club', $extra ) ) {
			$extra['licences_hors_club'] = max( 0, $nb_avant - count( (array) ( $extra['saisons_licence'] ?? [] ) ) - ( $actif === 1 && $saison_fiche !== '' && ! in_array( $saison_fiche, (array) ( $extra['saisons_licence'] ?? [] ), true ) ? 1 : 0 ) );
		}
		if ( $actif === 1 ) $extra = self::ajouter_saison( $extra, $saison_fiche );
		if ( isset( $post['eleve_dan_hors_club'] ) ) {
			$extra['licences_hors_club'] = max( 0, min( 50, (int) $post['eleve_dan_hors_club'] ) );
		}
		if ( isset( $post['eleve_dan_bloc'] ) ) {
			$garder = [];
			$suppr  = array_map( 'intval', (array) ( $post['eleve_dan_stage_suppr'] ?? [] ) );
			foreach ( array_values( (array) ( $extra['stages_dan_externes'] ?? [] ) ) as $i => $s ) {
				if ( ! in_array( $i, $suppr, true ) ) $garder[] = $s;
			}
			$d = sanitize_text_field( wp_unslash( (string) ( $post['eleve_dan_stage_date'] ?? '' ) ) );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				$niv      = sanitize_key( wp_unslash( (string) ( $post['eleve_dan_stage_niveau'] ?? '' ) ) );
				$garder[] = [
					'date'   => $d,
					'lieu'   => mb_substr( sanitize_text_field( wp_unslash( (string) ( $post['eleve_dan_stage_lieu'] ?? '' ) ) ), 0, 120 ),
					'niveau' => isset( self::NIVEAUX[ $niv ] ) ? $niv : '',
				];
			}
			$extra['stages_dan_externes'] = $garder;
			$motif = mb_substr( sanitize_text_field( wp_unslash( (string) ( $post['eleve_dan_derogation_motif'] ?? '' ) ) ), 0, 300 );
			if ( ! empty( $post['eleve_dan_derogation'] ) && $motif !== '' ) {
				$avant = is_array( $extra['derogation_dan'] ?? null ) ? $extra['derogation_dan'] : [];
				$extra['derogation_dan'] = ( ( $avant['motif'] ?? '' ) === $motif ) ? $avant : [
					'motif' => $motif,
					'date'  => current_time( 'd/m/Y' ),
					'par'   => wp_get_current_user()->display_name,
				];
			} else {
				unset( $extra['derogation_dan'] );
			}
		}
		self::$stages_cache = null;
		$el = (object) [ 'id' => $id, 'actif' => $actif, 'saison' => $saison_fiche, 'extra_data' => wp_json_encode( $extra ),
			'date_naissance' => sanitize_text_field( wp_unslash( (string) ( $post['eleve_ddn'] ?? '' ) ) ),
			'annee_naissance' => sanitize_text_field( wp_unslash( (string) ( $post['eleve_annee'] ?? '' ) ) ) ];
		$r = self::evaluer( $el );
		return [ $extra, min( 255, $r['licences']['total'] ), $r['eligible'] ? 1 : 0 ];
	}

	/* =====================================================================================
	 * Affichage
	 * =================================================================================== */

	private static function puce( bool $ok ): string {
		return $ok ? '<span style="color:#15803d;">✅</span>' : '<span style="color:#dc2626;">❌</span>';
	}

	/** Bloc « Éligibilité 1er Dan ». $admin : champs de saisie (dans le formulaire de la fiche). */
	public static function rendre( object $el, bool $admin ): string {
		$r = self::evaluer( $el );
		$h = '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;margin-top:8px;font-size:13px;">';
		$h .= '<div style="font-weight:700;margin-bottom:10px;">🥋 Éligibilité 1er Dan — saison ' . esc_html( $r['saison'] ) . '</div>';
		$h .= '<div style="margin-bottom:6px;">' . self::puce( $r['licences']['ok'] ) . ' <strong>Licences</strong> : ' . esc_html( $r['licences']['texte'] ) . '</div>';
		$h .= '<div style="margin-bottom:6px;">' . self::puce( $r['age']['ok'] ) . ' <strong>14 ans révolus le jour de l\'examen</strong> : ' . esc_html( $r['age']['texte'] ) . '</div>';
		$h .= '<div style="margin-bottom:4px;">' . self::puce( $r['stages']['ok'] ) . ' <strong>Stages de préparation</strong> : ' . esc_html( $r['stages']['texte'] ) . '</div>';
		if ( $r['stages']['liste'] ) {
			$h .= '<ul style="margin:0 0 8px 26px;list-style:disc;">';
			foreach ( $r['stages']['liste'] as $s ) {
				$h .= '<li>' . esc_html( self::fr( $s['date'] ) . ' — ' . $s['titre'] . ' (' . $s['niveau'] . ')' ) . '</li>';
			}
			$h .= '</ul>';
		}
		$fond = $r['eligible'] ? '#f0fdf4;border:1px solid #86efac;color:#15803d' : '#fef2f2;border:1px solid #fca5a5;color:#dc2626';
		$res  = $r['conditions'] ? '→ Éligible au 1er Dan'
			: ( $r['derogation'] ? '→ Éligible par dérogation : ' . $r['derogation']['motif'] : '→ Pas encore éligible — il manque : ' . implode( ', ', $r['manque'] ) );
		$h .= '<div style="background:' . $fond . ';border-radius:6px;padding:8px 12px;margin:8px 0;font-weight:700;">' . esc_html( $res ) . '</div>';

		if ( $admin ) {
			$extra = self::extra( $el );
			$h .= '<input type="hidden" name="eleve_dan_bloc" value="1">';
			$h .= '<p style="margin:10px 0 6px;"><label>Licences prises hors club (autre club, avant l\'arrivée) : <input type="number" name="eleve_dan_hors_club" min="0" max="50" value="'
				. (int) $r['licences']['hors_club'] . '" style="width:60px;"></label>';
			$h .= ' <span style="color:#6b7280;">Saisons au club : ' . esc_html( $r['licences']['club'] ? implode( ', ', $r['licences']['club'] ) : 'aucune' ) . ' (complétées automatiquement).</span></p>';
			$ext = array_values( (array) ( $extra['stages_dan_externes'] ?? [] ) );
			if ( $ext ) {
				$h .= '<div style="margin:6px 0;">Stages faits ailleurs :';
				foreach ( $ext as $i => $s ) {
					$h .= '<br><label><input type="checkbox" name="eleve_dan_stage_suppr[]" value="' . (int) $i . '"> retirer</label> — '
						. esc_html( self::fr( (string) ( $s['date'] ?? '' ) ) . ( ! empty( $s['lieu'] ) ? ' — ' . $s['lieu'] : '' ) . ' (' . ( self::NIVEAUX[ $s['niveau'] ?? '' ] ?? 'niveau non précisé' ) . ')' );
				}
				$h .= '</div>';
			}
			$h .= '<div style="margin:6px 0;">Ajouter un stage fait ailleurs : <input type="date" name="eleve_dan_stage_date"> <input type="text" name="eleve_dan_stage_lieu" placeholder="Lieu / organisateur" style="width:180px;"> '
				. '<select name="eleve_dan_stage_niveau"><option value="">Niveau…</option><option value="departemental">Départemental</option><option value="regional">Régional</option></select></div>';
			$d = $r['derogation'];
			$h .= '<div style="margin:8px 0 0;"><label><input type="checkbox" name="eleve_dan_derogation" value="1"' . ( $d ? ' checked' : '' ) . '> <strong>Dérogation</strong> (cas validé par la fédération)</label> — motif : '
				. '<input type="text" name="eleve_dan_derogation_motif" value="' . esc_attr( $d['motif'] ?? '' ) . '" style="width:320px;" placeholder="Obligatoire pour la dérogation">'
				. ( $d ? ' <span style="color:#6b7280;">(' . esc_html( $d['date'] . ', ' . $d['par'] ) . ')</span>' : '' ) . '</div>';
			$h .= '<p style="margin:8px 0 0;color:#6b7280;">Stages du club : comptés d\'après les présences pointées aux événements « stage préparation Dan » du calendrier. '
				. '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Candidats et date de l\'examen</a></p>';
		}
		return $h . '</div>';
	}

	/* =====================================================================================
	 * Page « Candidats 1er Dan »
	 * =================================================================================== */

	public function menu(): void {
		add_submenu_page( 'sp-cal-pro', 'Candidats 1er Dan', '🥋 Candidats 1er Dan', SP_Cal_Roles::CAP_GESTION_ADHESIONS, self::PAGE, [ $this, 'page' ] );
	}

	/** Examens et stages Dan publiés par la Ligue Occitanie (flux iCal, mis en cache 12 h). */
	public static function ligue(): array {
		$c = get_transient( self::TRANSIENT );
		if ( is_array( $c ) ) return $c;
		$r   = wp_remote_get( self::ICS, [ 'timeout' => 15, 'user-agent' => 'Mozilla/5.0 (compatible; club WordPress)' ] );
		$out = [ 'examens' => [], 'stages' => [], 'erreur' => '' ];
		if ( is_wp_error( $r ) || (int) wp_remote_retrieve_response_code( $r ) !== 200 ) {
			$out['erreur'] = 'flux de la Ligue inaccessible';
			set_transient( self::TRANSIENT, $out, HOUR_IN_SECONDS );
			return $out;
		}
		$ics = str_replace( [ "\r\n ", "\n " ], '', (string) wp_remote_retrieve_body( $r ) );   // lignes repliées
		preg_match_all( '/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $m );
		foreach ( $m[1] as $v ) {
			if ( ! preg_match( '/^SUMMARY[^:]*:(.*)$/m', $v, $s ) || ! preg_match( '/^DTSTART[^:]*:(\d{8})(T\d{6}Z?)?/m', $v, $d ) ) continue;
			$titre = trim( str_replace( [ '\\,', '\\;', '\\n' ], [ ',', ';', ' ' ], $s[1] ) );
			$date  = substr( $d[1], 0, 4 ) . '-' . substr( $d[1], 4, 2 ) . '-' . substr( $d[1], 6, 2 );
			if ( ! empty( $d[2] ) && substr( $d[2], -1 ) === 'Z' ) {
				// Heure UTC : une journée entière est codée 23:00 UTC la veille → date de Paris.
				$dt   = new \DateTime( $d[1] . $d[2], new \DateTimeZone( 'UTC' ) );
				$date = $dt->setTimezone( new \DateTimeZone( 'Europe/Paris' ) )->format( 'Y-m-d' );
			}
			$lieu = preg_match( '/^LOCATION[^:]*:(.*)$/m', $v, $l ) ? trim( preg_replace( '/^Localisation\s*/i', '', str_replace( '\\,', ',', $l[1] ) ) ) : '';
			$t    = remove_accents( mb_strtolower( $titre ) );
			if ( preg_match( '/examen/', $t ) && preg_match( '/\b1\s*er\b|1er/', $t ) && preg_match( '/\bdan\b/', $t ) ) {
				$out['examens'][] = [ 'date' => $date, 'titre' => $titre, 'lieu' => $lieu ];
			} elseif ( self::titre_stage( $titre ) && ! preg_match( '/\b[5-9]\s*(e|eme)?\s*dan/', $t ) ) {
				$out['stages'][] = [ 'date' => $date, 'titre' => $titre, 'lieu' => $lieu ];
			}
		}
		set_transient( self::TRANSIENT, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	public function handle_examen(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'sp_cal_dan_examen' );
		$saison = self::saison_courante();
		$d      = sanitize_text_field( wp_unslash( (string) ( $_POST['date_examen'] ?? '' ) ) );
		$dates  = (array) get_option( self::OPT_EXAMENS, [] );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) $dates[ $saison ] = $d;
		else unset( $dates[ $saison ] );
		update_option( self::OPT_EXAMENS, $dates, false );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&examen=1' ) );
		exit;
	}

	/** Ajoute les candidats choisis au ciblage manuel des inscriptions d'un stage du calendrier. */
	public function handle_cibler(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'sp_cal_dan_cibler' );
		global $wpdb;
		$te  = $wpdb->prefix . 'sp_cal_events';
		$ev  = absint( wp_unslash( $_POST['event_id'] ?? 0 ) );
		$ids = array_filter( array_map( 'intval', (array) ( $_POST['eleves'] ?? [] ) ) );
		$n   = 0;
		$row = $ev ? $wpdb->get_row( $wpdb->prepare( "SELECT id, inscriptions_extra_eleves FROM `$te` WHERE id = %d", $ev ) ) : null;
		if ( $row && $ids ) {
			$avant = array_filter( array_map( 'intval', explode( ',', (string) $row->inscriptions_extra_eleves ) ) );
			$tout  = array_values( array_unique( array_merge( $avant, $ids ) ) );
			$n     = count( $tout ) - count( $avant );
			$wpdb->update( $te, [ 'inscriptions_extra_eleves' => implode( ',', $tout ) ], [ 'id' => $ev ] );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&cible=' . $n . '&ev=' . $ev ) );
		exit;
	}

	/** Classe un événement « prépa Dan » : départemental, régional ou stage du club (non compté). */
	public function handle_classer(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'sp_cal_dan_classer' );
		global $wpdb;
		$ev  = absint( wp_unslash( $_POST['event_id'] ?? 0 ) );
		$niv = sanitize_key( wp_unslash( $_POST['niveau'] ?? '' ) );
		if ( $ev && self::colonne_prepa() && ( isset( self::NIVEAUX[ $niv ] ) || $niv === 'non' ) ) {
			$wpdb->update( $wpdb->prefix . 'sp_cal_events', [ 'prepa_dan' => $niv ], [ 'id' => $ev ] );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '#sp-dan-classer' ) );
		exit;
	}

	/** Événements dont le titre ressemble à un stage prépa Dan mais qui n'ont pas été classés. */
	private static function a_classer(): array {
		if ( ! self::colonne_prepa() ) return [];
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT id, date, titre, type FROM `{$wpdb->prefix}sp_cal_events` WHERE prepa_dan = '' AND type <> 'annulation' AND titre LIKE '%dan%' ORDER BY date DESC LIMIT 200" );
		return array_values( array_filter( $rows, static fn( $r ) => self::titre_stage( wp_unslash( (string) $r->titre ) ) ) );
	}

	public function page(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) return;
		global $wpdb;
		$saison = self::saison_courante();
		[ $exam, $regle ] = self::date_examen( $saison );
		$filtre = sanitize_key( wp_unslash( $_GET['filtre'] ?? 'proches' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtre d'affichage

		echo '<div class="wrap"><h1>🥋 Candidats 1er Dan — saison ' . esc_html( $saison ) . '</h1>';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- messages de retour
		if ( isset( $_GET['examen'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Date de l\'examen enregistrée.</p></div>';
		if ( isset( $_GET['cible'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . (int) $_GET['cible'] . ' adhérent(s) ajouté(s) au ciblage des inscriptions du stage. Les invitations s\'envoient depuis le calendrier (onglet de l\'événement).</p></div>';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Date de l'examen
		$ligue = self::ligue();
		echo '<div class="sp-box" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;max-width:1000px;">';
		echo '<h2 style="margin-top:0;">📅 Date de l\'examen visé</h2>';
		echo '<p>' . ( $regle ? 'Examen du <strong>' . esc_html( self::fr( $exam ) ) . '</strong> : l\'âge est calculé à cette date.' : '⚠️ Pas de date réglée pour ' . esc_html( $saison ) . ' : l\'âge est calculé au ' . esc_html( self::fr( $exam ) ) . ' (fin de saison).' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sp_cal_dan_examen">';
		wp_nonce_field( 'sp_cal_dan_examen' );
		echo '<input type="date" name="date_examen" value="' . esc_attr( $regle ? $exam : '' ) . '"> <button class="button button-primary">Enregistrer</button> <span class="description">(vide = fin de saison)</span></form>';
		[ $deb, $fin ] = self::bornes( $saison );
		$prop = array_filter( $ligue['examens'], static fn( $e ) => $e['date'] >= $deb && $e['date'] <= $fin );
		if ( $prop ) {
			echo '<p style="margin-bottom:4px;">Publiés par la Ligue Occitanie :</p><ul style="margin-top:0;">';
			foreach ( $prop as $e ) {
				echo '<li><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;"><input type="hidden" name="action" value="sp_cal_dan_examen"><input type="hidden" name="date_examen" value="' . esc_attr( $e['date'] ) . '">';
				wp_nonce_field( 'sp_cal_dan_examen' );
				echo '<strong>' . esc_html( self::fr( $e['date'] ) ) . '</strong> — ' . esc_html( $e['titre'] ) . ( $e['lieu'] ? ' <span class="description">(' . esc_html( $e['lieu'] ) . ')</span>' : '' )
					. ' <button class="button button-small"' . ( $regle && $exam === $e['date'] ? ' disabled' : '' ) . '>Utiliser cette date</button></form></li>';
			}
			echo '</ul>';
		} elseif ( $ligue['erreur'] ) {
			echo '<p class="description">Dates de la Ligue Occitanie : ' . esc_html( $ligue['erreur'] ) . '.</p>';
		}
		$st = array_filter( $ligue['stages'], static fn( $e ) => $e['date'] >= current_time( 'Y-m-d' ) );
		if ( $st ) {
			echo '<p style="margin-bottom:4px;">Stages de préparation annoncés par la Ligue (à ajouter au calendrier du club pour pointer les présences) :</p><ul style="margin-top:0;">';
			foreach ( $st as $e ) echo '<li>' . esc_html( self::fr( $e['date'] ) . ' — ' . $e['titre'] . ( $e['lieu'] ? ' (' . $e['lieu'] . ')' : '' ) ) . '</li>';
			echo '</ul>';
		}
		echo '<p class="description">Source : <a href="https://www.taekwondo-occitanie.com/evenements" target="_blank" rel="noopener">agenda de la Ligue Occitanie</a>, lu automatiquement ; rien n\'est enregistré sans votre clic.</p></div>';

		// Stages importés d'IzziDan (comités cochés)
		if ( class_exists( 'SP_Cal_Stages_Izzidan' ) ) SP_Cal_Stages_Izzidan::render();

		// Anciens événements « prépa Dan » à classer
		$classer = self::a_classer();
		if ( $classer ) {
			echo '<div id="sp-dan-classer" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:14px 18px;max-width:1000px;margin-top:14px;">';
			echo '<h2 style="margin-top:0;">🗂️ Événements « prépa Dan » à classer (' . count( $classer ) . ')</h2>';
			echo '<p>Seuls les stages départementaux et régionaux comptent pour l\'examen. Ces événements ont un titre de stage de préparation Dan mais leur niveau n\'est pas indiqué : ils ne sont <strong>pas comptés</strong> tant qu\'ils ne sont pas classés.</p><table class="widefat striped"><tbody>';
			foreach ( $classer as $c ) {
				echo '<tr><td style="width:100px;">' . esc_html( self::fr( (string) $c->date ) ) . '</td><td>' . esc_html( wp_unslash( (string) $c->titre ) ) . '</td><td style="white-space:nowrap;">';
				foreach ( [ 'departemental' => 'Départemental', 'regional' => 'Régional', 'non' => 'Stage du club (ne compte pas)' ] as $v => $lib ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;"><input type="hidden" name="action" value="sp_cal_dan_classer"><input type="hidden" name="event_id" value="' . (int) $c->id . '"><input type="hidden" name="niveau" value="' . esc_attr( $v ) . '">';
					wp_nonce_field( 'sp_cal_dan_classer' );
					echo '<button class="button button-small">' . esc_html( $lib ) . '</button></form> ';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}

		// Candidats
		$eleves = (array) $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}sp_cal_eleves` WHERE actif = 1 ORDER BY nom, prenom" );
		$lignes = [];
		foreach ( $eleves as $el ) {
			if ( ! self::concerne( $el ) ) continue;
			$r = self::evaluer( $el, $saison );
			$lignes[] = [ $el, $r ];
		}
		$nb_elig = count( array_filter( $lignes, static fn( $l ) => $l[1]['eligible'] ) );
		$nb_un   = count( array_filter( $lignes, static fn( $l ) => ! $l[1]['eligible'] && count( $l[1]['manque'] ) === 1 ) );
		$aff     = array_filter( $lignes, static function ( $l ) use ( $filtre ) {
			if ( $filtre === 'eligibles' ) return $l[1]['eligible'];
			if ( $filtre === 'tous' ) return true;
			return $l[1]['eligible'] || count( $l[1]['manque'] ) === 1;
		} );

		echo '<h2>Adhérents</h2><p>';
		$base = admin_url( 'admin.php?page=' . self::PAGE );
		foreach ( [ 'proches' => 'Éligibles + il manque une condition (' . ( $nb_elig + $nb_un ) . ')', 'eligibles' => 'Éligibles (' . $nb_elig . ')', 'tous' => 'Tous les concernés (' . count( $lignes ) . ')' ] as $k => $lib ) {
			echo $k === $filtre ? '<strong>' . esc_html( $lib ) . '</strong>' : '<a href="' . esc_url( add_query_arg( 'filtre', $k, $base ) ) . '">' . esc_html( $lib ) . '</a>';
			echo ' &nbsp;|&nbsp; ';
		}
		echo '</p><p class="description">Concernés : adhérents actifs sans Dan, Ado/Adulte ou d\'au moins 13 ans l\'année de l\'examen.</p>';

		// Formulaire de ciblage vers un stage à venir du calendrier
		$te      = $wpdb->prefix . 'sp_cal_events';
		$col     = self::colonne_prepa() ? 'prepa_dan' : "'' AS prepa_dan";
		$futurs  = array_filter( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, date, titre, type, {$col} FROM `$te` WHERE date >= %s AND type <> 'annulation' ORDER BY date LIMIT 300", current_time( 'Y-m-d' ) ) ), [ __CLASS__, 'est_stage' ] );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sp_cal_dan_cibler">';
		wp_nonce_field( 'sp_cal_dan_cibler' );
		echo '<table class="widefat striped" style="max-width:1200px;"><thead><tr><th style="width:24px;"><input type="checkbox" onclick="document.querySelectorAll(\'.sp-dan-chk\').forEach(c=>c.checked=this.checked)"></th><th>Adhérent</th><th>Grade</th><th>Licences</th><th>Âge à l\'examen</th><th>Stages</th><th>Résultat</th></tr></thead><tbody>';
		if ( ! $aff ) echo '<tr><td colspan="7">Aucun adhérent dans ce filtre.</td></tr>';
		foreach ( $aff as [ $el, $r ] ) {
			$fiche = admin_url( 'admin.php?page=sp-cal-eleves&sp_edit_eleve=' . (int) $el->id );
			$res   = $r['conditions'] ? '✅ Éligible' : ( $r['derogation'] ? '✅ Dérogation' : '⏳ manque : ' . implode( ', ', $r['manque'] ) );
			echo '<tr><td><input type="checkbox" class="sp-dan-chk" name="eleves[]" value="' . (int) $el->id . '"' . ( ! $r['stages']['ok'] ? ' checked' : '' ) . '></td>'
				. '<td><a href="' . esc_url( $fiche ) . '">' . esc_html( mb_strtoupper( (string) $el->nom ) . ' ' . $el->prenom ) . '</a></td>'
				. '<td>' . esc_html( (string) $el->grade ) . '</td>'
				. '<td>' . self::puce( $r['licences']['ok'] ) . ' ' . esc_html( $r['licences']['total'] . ( $r['licences']['en_cours'] ? '' : ' (saison non prise)' ) ) . '</td>'
				. '<td>' . self::puce( $r['age']['ok'] ) . ' ' . esc_html( $r['age']['date_14'] ? '14 ans le ' . self::fr( $r['age']['date_14'] ) : 'naissance ?' ) . '</td>'
				. '<td>' . self::puce( $r['stages']['ok'] ) . ' ' . esc_html( count( $r['stages']['liste'] ) . '/' . self::STAGES . ' (' . $r['stages']['saison'] . ' cette saison)' ) . '</td>'
				. '<td><strong>' . esc_html( $res ) . '</strong></td></tr>';
		}
		echo '</tbody></table>';
		if ( $futurs ) {
			echo '<p style="margin-top:12px;">Ajouter les adhérents cochés (par défaut : ceux à qui il manque un stage) aux invitations du stage : <select name="event_id">';
			foreach ( $futurs as $f ) echo '<option value="' . (int) $f->id . '">' . esc_html( self::fr( (string) $f->date ) . ' — ' . wp_unslash( (string) $f->titre ) ) . '</option>';
			echo '</select> <button class="button button-primary">Cibler</button></p>';
		} else {
			echo '<p class="description" style="margin-top:12px;">Aucun stage de préparation Dan à venir dans le calendrier du club : créez l\'événement (case « Stage préparation Dan ») pour pouvoir y inviter les candidats.</p>';
		}
		echo '</form></div>';
	}
}

endif;

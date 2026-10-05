<?php
/**
 * IK des entraîneurs : calcul mensuel unique, clôture des mois payés, régularisations.
 *
 * Les interventions sont recalculées à chaque fois depuis les dispos
 * (SpCalPro_DB::get_interventions_par_trainer()) : sans clôture, une dispo modifiée après coup
 * sur un mois déjà payé changeait le total sans que personne ne le voie (EVOLUTION.md, 05/10/2026).
 *
 * Fonctionnement (choix de l'utilisateur du 05/10/2026) :
 *   - le trésorier clôture un mois une fois les IK payées (bouton dans Réglages → Récapitulatif
 *     mensuel) : le détail de chaque entraîneur est figé (jours, allers-retours, km, tarif, montant) ;
 *   - un mois clôturé ne se modifie plus depuis « Mes dispos » ; l'admin peut encore corriger
 *     après confirmation, et l'écart avec le figé apparaît dans le récapitulatif suivant
 *     (bloc « Régularisations ») ;
 *   - clôturer le mois suivant solde ces régularisations : elles font partie du montant figé
 *     de ce mois, et les mois corrigés sont remis à jour (en gardant leur tarif et leurs km) ;
 *   - la dernière clôture faite peut être annulée (« Rouvrir ») (les régularisations qu'il avait soldées
 *     reviennent).
 * Les écarts sont calculés avec le tarif et les km figés du mois concerné : un changement de
 * tarif ou de distance après coup ne crée pas de régularisation.
 *
 * Stockage : option sp_cal_ik_clotures = [ 'AAAA-MM' => clôture ], voir cloturer().
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_IK_Cloture {

	private static ?self $instance = null;
	private $db;

	const OPTION = 'sp_cal_ik_clotures';
	const CAP    = 'manage_options';

	public static function get_instance( $db = null ): self {
		if ( self::$instance === null ) self::$instance = new self( $db );
		return self::$instance;
	}

	private function __construct( $db = null ) {
		$this->db = $db;
		add_action( 'admin_post_sp_cal_ik_cloturer', [ $this, 'handle_cloturer' ] );
		add_action( 'admin_post_sp_cal_ik_rouvrir',  [ $this, 'handle_rouvrir' ] );
	}

	// ══════════════════════════════════════════════════════════════════════
	// CALCUL — seule source des montants (récapitulatif, clôture, régularisations)
	// ══════════════════════════════════════════════════════════════════════

	/**
	 * IK d'un mois, entraîneur par entraîneur (entraîneurs actifs ayant eu au moins une
	 * intervention ou des km exceptionnels).
	 *
	 * @return array [ 'tarif' => float, 'total' => float, 'sans_km' => string[],
	 *                 'lignes' => [ trainer_id => [ nom, jours, nb (allers-retours), km, km_excep, excep_detail, montant ] ] ]
	 */
	public function calcul_mois( int $year, int $month ): array {
		global $wpdb;
		$tarif = floatval( get_option( 'sp_cal_tarif_km', 0 ) );
		$out   = [ 'tarif' => $tarif, 'total' => 0.0, 'sans_km' => [], 'lignes' => [] ];

		$interventions = $this->db->get_interventions_par_trainer( $year, $month );

		$debut = sprintf( '%04d-%02d-01', $year, $month );
		$fin   = gmdate( 'Y-m-t', strtotime( $debut . ' 12:00:00 UTC' ) );
		$tkm   = $this->db->table_km_exceptionnels();
		$excep = [];
		foreach ( $wpdb->get_results( $wpdb->prepare(
			"SELECT trainer_id, SUM(km) AS total_km, GROUP_CONCAT(CONCAT(description,' (',DATE_FORMAT(date,'%%d/%%m'),'): ',km,' km') ORDER BY date SEPARATOR ' | ') AS detail
			 FROM $tkm WHERE date BETWEEN %s AND %s GROUP BY trainer_id",
			$debut, $fin
		) ) as $r ) {
			$excep[ intval( $r->trainer_id ) ] = [ 'total' => floatval( $r->total_km ), 'detail' => (string) $r->detail ];
		}

		foreach ( $this->db->get_trainers( true ) as $t ) {
			if ( strpos( (string) $t->roles, 'entraineur' ) === false ) continue;
			$tid   = intval( $t->id );
			$nb    = intval( $interventions[ $tid ]['ar'] ?? 0 ); // allers-retours : base des IK
			$a_exc = isset( $excep[ $tid ] );
			if ( $nb === 0 && ! $a_exc ) continue;

			$km       = isset( $t->km_aller_retour ) ? floatval( $t->km_aller_retour ) : 0.0;
			$km_excep = $a_exc ? $excep[ $tid ]['total'] : 0.0;
			$montant  = self::montant( $tarif, $km, $nb, $km_excep );

			$out['total'] += $montant;
			if ( $km === 0.0 && $nb > 0 ) $out['sans_km'][] = trim( $t->nom );
			$out['lignes'][ $tid ] = [
				'nom'          => trim( $t->nom ),
				'jours'        => intval( $interventions[ $tid ]['jours'] ?? 0 ),
				'nb'           => $nb,
				'km'           => $km,
				'km_excep'     => $km_excep,
				'excep_detail' => $a_exc ? $excep[ $tid ]['detail'] : '',
				'montant'      => $montant,
			];
		}
		$out['total'] = round( $out['total'], 2 );
		return $out;
	}

	private static function montant( float $tarif, float $km, int $nb, float $km_excep ): float {
		$hab = ( $tarif > 0 && $km > 0 && $nb > 0 ) ? round( $tarif * $km * $nb, 2 ) : 0.0;
		$exc = ( $tarif > 0 && $km_excep > 0 ) ? round( $tarif * $km_excep, 2 ) : 0.0;
		return $hab + $exc;
	}

	// ══════════════════════════════════════════════════════════════════════
	// CLÔTURES
	// ══════════════════════════════════════════════════════════════════════

	public function clotures(): array {
		$c = get_option( self::OPTION, [] );
		return is_array( $c ) ? $c : [];
	}

	/** Le mois de cette date (AAAA-MM-JJ ou AAAA-MM) est-il clôturé ? */
	public function est_cloture( string $date ): bool {
		return isset( $this->clotures()[ substr( $date, 0, 7 ) ] );
	}

	/**
	 * Écarts actuels des mois clôturés par rapport à leur figé, avec le tarif et les km figés.
	 *
	 * @return array [ [ 'mois' => 'AAAA-MM', 'trainer_id', 'nom', 'ar' (écart), 'km_excep' (écart), 'montant' (écart), 'nouveau' (ligne recalculée au figé) ], … ]
	 */
	public function regularisations(): array {
		$out = [];
		foreach ( $this->clotures() as $ym => $c ) {
			[ $y, $m ] = array_map( 'intval', explode( '-', $ym ) );
			$actuel = $this->calcul_mois( $y, $m )['lignes'];
			$fige   = $c['lignes'] ?? [];
			foreach ( array_unique( array_merge( array_keys( $fige ), array_keys( $actuel ) ) ) as $tid ) {
				$f  = $fige[ $tid ] ?? null;
				$a  = $actuel[ $tid ] ?? null;
				$nb = $a ? $a['nb'] : 0;
				$ke = $a ? $a['km_excep'] : 0.0;
				if ( $f && $nb === intval( $f['nb'] ) && abs( $ke - floatval( $f['km_excep'] ) ) < 0.01 ) continue;
				if ( ! $f && ! $a ) continue;
				// Tarif et km du figé : seul le nombre d'allers-retours ou de km exceptionnels compte.
				$km      = $f ? floatval( $f['km'] ) : floatval( $a['km'] );
				$tarif   = floatval( $c['tarif'] ?? 0 );
				$montant = self::montant( $tarif, $km, $nb, $ke );
				$ecart   = round( $montant - ( $f ? floatval( $f['montant'] ) : 0.0 ), 2 );
				$out[] = [
					'mois'       => $ym,
					'trainer_id' => intval( $tid ),
					'nom'        => $f ? $f['nom'] : $a['nom'],
					'ar'         => $nb - ( $f ? intval( $f['nb'] ) : 0 ),
					'km_excep'   => round( $ke - ( $f ? floatval( $f['km_excep'] ) : 0.0 ), 1 ),
					'montant'    => $ecart,
					'nouveau'    => $nb === 0 && $ke == 0 ? null : [
						'nom' => $f ? $f['nom'] : $a['nom'], 'jours' => $a ? $a['jours'] : 0, 'nb' => $nb, 'km' => $km,
						'km_excep' => $ke, 'excep_detail' => $a ? $a['excep_detail'] : '', 'montant' => $montant,
					],
				];
			}
		}
		usort( $out, static fn( $a, $b ) => strcmp( $a['mois'], $b['mois'] ) ?: strcasecmp( $a['nom'], $b['nom'] ) );
		return $out;
	}

	/**
	 * Clôture un mois passé : fige son calcul et solde les régularisations en cours (les mois
	 * corrigés sont remis à jour ; leur ancien figé est gardé pour une éventuelle réouverture).
	 */
	public function cloturer( string $ym, string $par ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $ym ) || $ym >= current_time( 'Y-m' ) ) return false;
		$clotures = $this->clotures();
		if ( isset( $clotures[ $ym ] ) ) return false;

		$regul   = $this->regularisations();
		$absorbe = [];
		foreach ( $regul as $r ) {
			if ( ! isset( $absorbe[ $r['mois'] ] ) ) $absorbe[ $r['mois'] ] = $clotures[ $r['mois'] ]['lignes'] ?? [];
			if ( $r['nouveau'] ) $clotures[ $r['mois'] ]['lignes'][ $r['trainer_id'] ] = $r['nouveau'];
			else unset( $clotures[ $r['mois'] ]['lignes'][ $r['trainer_id'] ] );
		}

		[ $y, $m ] = array_map( 'intval', explode( '-', $ym ) );
		$calc = $this->calcul_mois( $y, $m );
		$total_regul = round( array_sum( array_column( $regul, 'montant' ) ), 2 );
		$clotures[ $ym ] = [
			'date'            => current_time( 'mysql' ),
			'par'             => $par,
			'tarif'           => $calc['tarif'],
			'lignes'          => $calc['lignes'],
			'total'           => $calc['total'],
			'regularisations' => array_map( static function ( $r ) { unset( $r['nouveau'] ); return $r; }, $regul ),
			'total_regul'     => $total_regul,
			'absorbe'         => $absorbe,
		];
		ksort( $clotures );
		update_option( self::OPTION, $clotures, false );
		return true;
	}

	/** Mois clôturé le plus récemment (par date de clôture, les mois pouvant être clôturés dans le désordre). */
	private static function derniere_cloture( array $clotures ): ?string {
		$dernier = null;
		foreach ( $clotures as $ym => $c ) {
			if ( $dernier === null || strcmp( (string) $c['date'], (string) $clotures[ $dernier ]['date'] ) >= 0 ) $dernier = $ym;
		}
		return $dernier;
	}

	/** Rouvre la dernière clôture faite ; les régularisations qu'elle avait soldées reviennent. */
	public function rouvrir( string $ym ): bool {
		$clotures = $this->clotures();
		if ( ! isset( $clotures[ $ym ] ) || self::derniere_cloture( $clotures ) !== $ym ) return false;
		foreach ( $clotures[ $ym ]['absorbe'] ?? [] as $mois => $lignes ) {
			if ( isset( $clotures[ $mois ] ) ) $clotures[ $mois ]['lignes'] = $lignes;
		}
		unset( $clotures[ $ym ] );
		update_option( self::OPTION, $clotures, false );
		return true;
	}

	// ══════════════════════════════════════════════════════════════════════
	// ADMIN — bloc « Clôture des IK » (Réglages, sous le récapitulatif mensuel)
	// ══════════════════════════════════════════════════════════════════════

	private static function libelle_mois( string $ym ): string {
		$mois = [ 1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' ];
		[ $y, $m ] = array_map( 'intval', explode( '-', $ym ) );
		return ( $mois[ $m ] ?? $ym ) . ' ' . $y;
	}

	private static function euros( float $v, bool $signe = false ): string {
		return ( $signe && $v > 0 ? '+' : '' ) . number_format( $v, 2, ',', ' ' ) . ' €';
	}

	public function render_admin(): void {
		if ( ! current_user_can( self::CAP ) ) return;
		$clotures = $this->clotures();
		$dernier  = self::derniere_cloture( $clotures );
		$regul    = $this->regularisations();
		$msg      = sanitize_key( $_GET['ik'] ?? '' );
		$post     = esc_url( admin_url( 'admin-post.php' ) );

		echo '<div class="sp-box" id="sp-ik-cloture"><h2>🔒 Clôture des IK</h2>'
			. '<p class="description">Une fois les IK d\'un mois payées, clôturez-le : le montant de chaque entraîneur est figé et les entraîneurs ne peuvent plus modifier leurs dispos de ce mois. '
			. 'Une correction faite ensuite par un admin apparaît en <strong>régularisation</strong> dans le récapitulatif suivant ; elle est soldée à la clôture du mois suivant.</p>';
		if ( $msg === 'cloture' ) echo '<div class="notice notice-success inline"><p>Mois clôturé.</p></div>';
		if ( $msg === 'rouvert' ) echo '<div class="notice notice-warning inline"><p>Mois rouvert : les entraîneurs peuvent de nouveau modifier leurs dispos de ce mois.</p></div>';
		if ( $msg === 'refuse' )  echo '<div class="notice notice-error inline"><p>Action impossible (mois en cours, déjà clôturé, ou pas la dernière clôture faite).</p></div>';

		if ( $regul ) {
			echo '<div class="notice notice-warning inline"><p><strong>Régularisations en attente</strong> (dispos corrigées après clôture) — soldées à la prochaine clôture :</p><ul style="margin:4px 0 8px 18px;list-style:disc">';
			foreach ( $regul as $r ) {
				echo '<li>' . esc_html( ucfirst( self::libelle_mois( $r['mois'] ) ) . ' — ' . $r['nom'] . ' : '
					. ( $r['ar'] ? sprintf( '%+d aller(s)-retour(s)', $r['ar'] ) : '' )
					. ( $r['ar'] && $r['km_excep'] ? ', ' : '' )
					. ( $r['km_excep'] ? sprintf( '%+.1f km exceptionnels', $r['km_excep'] ) : '' )
					. ' → ' . self::euros( $r['montant'], true ) ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<table class="widefat striped" style="max-width:760px;margin-top:10px"><thead><tr><th>Mois</th><th>Total</th><th>État</th><th></th></tr></thead><tbody>';
		$ts = strtotime( current_time( 'Y-m' ) . '-01 12:00:00 UTC' );
		for ( $i = 1; $i <= 6; $i++ ) {
			$ym = gmdate( 'Y-m', strtotime( "-$i month", $ts ) );
			$c  = $clotures[ $ym ] ?? null;
			[ $y, $m ] = array_map( 'intval', explode( '-', $ym ) );
			echo '<tr><td><strong>' . esc_html( ucfirst( self::libelle_mois( $ym ) ) ) . '</strong></td>';
			if ( $c ) {
				echo '<td>' . esc_html( self::euros( floatval( $c['total'] ) ) )
					. ( ! empty( $c['total_regul'] ) ? '<br><small>+ régularisations soldées : ' . esc_html( self::euros( floatval( $c['total_regul'] ), true ) ) . '</small>' : '' ) . '</td>'
					. '<td>🔒 Clôturé le ' . esc_html( mysql2date( 'd/m/Y', $c['date'] ) ) . ( $c['par'] ? ' par ' . esc_html( $c['par'] ) : '' ) . '</td><td>';
				if ( $ym === $dernier ) {
					echo '<form method="post" action="' . $post . '" onsubmit="return confirm(\'Rouvrir ' . esc_js( self::libelle_mois( $ym ) ) . ' ? Les entraîneurs pourront de nouveau modifier leurs dispos de ce mois.\');">' // phpcs:ignore
						. wp_nonce_field( 'sp_cal_ik_rouvrir', '_wpnonce', true, false )
						. '<input type="hidden" name="action" value="sp_cal_ik_rouvrir"><input type="hidden" name="mois" value="' . esc_attr( $ym ) . '">'
						. '<button class="button button-small">Rouvrir</button></form>';
				}
			} else {
				$calc = $this->calcul_mois( $y, $m );
				echo '<td>' . esc_html( self::euros( $calc['total'] ) ) . '</td><td>Ouvert</td><td>'
					. '<form method="post" action="' . $post . '" onsubmit="return confirm(\'Clôturer ' . esc_js( self::libelle_mois( $ym ) ) . ' ? Le montant de chaque entraîneur sera figé' . ( $regul ? ' et les régularisations en attente seront soldées avec ce mois' : '' ) . '.\');">' // phpcs:ignore
					. wp_nonce_field( 'sp_cal_ik_cloturer', '_wpnonce', true, false )
					. '<input type="hidden" name="action" value="sp_cal_ik_cloturer"><input type="hidden" name="mois" value="' . esc_attr( $ym ) . '">'
					. '<button class="button button-small button-primary">🔒 Clôturer</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function retour( string $msg ): void {
		wp_safe_redirect( add_query_arg( 'ik', $msg, wp_get_referer() ?: admin_url( 'admin.php?page=sp-cal-pro' ) ) . '#sp-ik-cloture' );
		exit;
	}

	public function handle_cloturer(): void {
		if ( ! current_user_can( self::CAP ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'sp_cal_ik_cloturer' );
		$ok = $this->cloturer( sanitize_text_field( wp_unslash( $_POST['mois'] ?? '' ) ), wp_get_current_user()->display_name );
		$this->retour( $ok ? 'cloture' : 'refuse' );
	}

	public function handle_rouvrir(): void {
		if ( ! current_user_can( self::CAP ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'sp_cal_ik_rouvrir' );
		$ok = $this->rouvrir( sanitize_text_field( wp_unslash( $_POST['mois'] ?? '' ) ) );
		$this->retour( $ok ? 'rouvert' : 'refuse' );
	}
}

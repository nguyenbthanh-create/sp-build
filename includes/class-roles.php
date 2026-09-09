<?php
/**
 * Rôles & capacités personnalisés du plugin.
 *
 * Jusqu'ici tout le wp-admin de sp_build était verrouillé `manage_options` de façon
 * uniforme — quiconque avait accès à une page avait accès à toutes. Le bureau du club
 * a des besoins différenciés (cf. doleances.md 09/09/2026) : la secrétaire doit pouvoir
 * gérer les fiches élèves, les demandes d'adhésion et le renouvellement de saison, sans
 * avoir accès au calendrier, au jury/examens ni aux réglages généraux du plugin. La
 * trésorière, elle, n'a besoin que de consulter le planning des entraîneurs (module
 * compta pris en charge par un plugin séparé).
 *
 * Introduit des capacités dédiées et deux rôles minimaux plutôt que de réutiliser un
 * rôle WordPress natif (Editor/Author) qui porterait des droits sans rapport (édition
 * de tout le contenu du site).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Roles {

	private static ?self $instance = null;

	const CAP_GESTION_ADHESIONS = 'sp_gestion_adhesions';
	const CAP_VOIR_PLANNING     = 'sp_cal_voir_planning';
	const ROLE_SECRETAIRE       = 'sp_secretaire';
	const ROLE_TRESORIERE       = 'sp_tresoriere';

	public static function get_instance(): self {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_create_role' ] );
	}

	// Auto-guérison à chaque chargement admin (même principe que maybe_create_table()
	// ailleurs dans ce projet) plutôt qu'à la seule activation du plugin, qui peut être
	// manquée si le plugin était déjà actif au moment de ce déploiement.
	public static function maybe_create_role(): void {
		// ── Secrétaire : gestion adhésions + accès au lien planning ──
		$role = get_role( self::ROLE_SECRETAIRE );
		if ( ! $role ) {
			add_role( self::ROLE_SECRETAIRE, 'Secrétaire (adhésions)', [
				'read' => true,
			] );
			$role = get_role( self::ROLE_SECRETAIRE );
		}
		if ( $role && ! $role->has_cap( self::CAP_GESTION_ADHESIONS ) ) {
			$role->add_cap( self::CAP_GESTION_ADHESIONS );
		}
		if ( $role && ! $role->has_cap( self::CAP_VOIR_PLANNING ) ) {
			$role->add_cap( self::CAP_VOIR_PLANNING );
		}

		// ── Trésorière : uniquement le lien planning (le module compta, plugin séparé,
		// gère ses propres droits d'accès — cf. doleances.md 09/09/2026) ──
		$role_tres = get_role( self::ROLE_TRESORIERE );
		if ( ! $role_tres ) {
			add_role( self::ROLE_TRESORIERE, 'Trésorière', [
				'read' => true,
			] );
			$role_tres = get_role( self::ROLE_TRESORIERE );
		}
		if ( $role_tres && ! $role_tres->has_cap( self::CAP_VOIR_PLANNING ) ) {
			$role_tres->add_cap( self::CAP_VOIR_PLANNING );
		}

		// Les administrateurs (manage_options) doivent aussi passer les nouveaux contrôles
		// current_user_can( CAP_* ) — sinon ils perdraient l'accès aux pages dont la
		// capacité a été changée pour un contrôle plus étroit.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			if ( ! $admin_role->has_cap( self::CAP_GESTION_ADHESIONS ) ) $admin_role->add_cap( self::CAP_GESTION_ADHESIONS );
			if ( ! $admin_role->has_cap( self::CAP_VOIR_PLANNING ) )     $admin_role->add_cap( self::CAP_VOIR_PLANNING );
		}
	}
}

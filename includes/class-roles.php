<?php
/**
 * Rôles & capacités personnalisés du plugin.
 *
 * Jusqu'ici tout le wp-admin de sp_build était verrouillé `manage_options` de façon
 * uniforme — quiconque avait accès à une page avait accès à toutes. Le bureau du club
 * a des besoins différenciés (cf. doleances.md 09/09/2026) : la secrétaire doit pouvoir
 * gérer les fiches élèves, les demandes d'adhésion et le renouvellement de saison, sans
 * avoir accès au calendrier, au jury/examens ni aux réglages généraux du plugin.
 *
 * Introduit une capacité dédiée (`sp_gestion_adhesions`) et un rôle minimal
 * (`sp_secretaire`, juste `read` + cette capacité) plutôt que de réutiliser un rôle
 * WordPress natif (Editor/Author) qui porterait des droits sans rapport (édition de
 * tout le contenu du site).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Roles {

	private static ?self $instance = null;

	const CAP_GESTION_ADHESIONS = 'sp_gestion_adhesions';
	const ROLE_SECRETAIRE       = 'sp_secretaire';

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

		// Les administrateurs (manage_options) doivent aussi passer les nouveaux contrôles
		// current_user_can( CAP_GESTION_ADHESIONS ) — sinon ils perdraient l'accès aux pages
		// dont la capacité a été changée pour ce nouveau contrôle plus étroit.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role && ! $admin_role->has_cap( self::CAP_GESTION_ADHESIONS ) ) {
			$admin_role->add_cap( self::CAP_GESTION_ADHESIONS );
		}
	}
}

<?php
/**
 * Gestion unique des évolutions de la base de données (restructuration, étape 5 — 07/10/2026).
 *
 * Avant : 8 modules vérifiaient eux-mêmes leurs tables, la plupart à CHAQUE chargement de page
 * (SpCalPro_DB::maybe_upgrade() : ~90 requêtes « SHOW COLUMNS / SHOW TABLES » par page
 * d'administration ; formulaire d'adhésion : 1 requête par page, publique comprise…).
 *
 * Maintenant : chaque module confie sa fonction de vérification à SP_Cal_Schema::enregistrer()
 * au lieu de l'accrocher lui-même à init / admin_init. Ces fonctions ne changent pas (mêmes
 * instructions SQL, mêmes gardes internes) ; c'est seulement le MOMENT où elles tournent qui
 * change : une seule fois après chaque déploiement d'un fichier qui définit des tables.
 *
 * Principe : une « empreinte » (signature()) est calculée à partir de la date et de la taille
 * des fichiers concernés (FICHIERS) et de REVISION. Tant qu'elle est égale à celle enregistrée
 * (option OPT_SIGNATURE), rien ne tourne : une lecture d'option. Dès qu'un de ces fichiers est
 * redéployé, toutes les vérifications repassent une fois (elles sont idempotentes : chacune
 * n'ajoute que ce qui manque).
 *
 * Garde-fous : verrou (un seul passage à la fois) ; en cas d'erreur, l'empreinte n'est pas
 * enregistrée et une nouvelle tentative a lieu au plus tôt 10 minutes plus tard ; journal des
 * 20 derniers passages (option OPT_JOURNAL), affiché sur la page Paramètres avec un bouton
 * « Revérifier la base de données ».
 *
 * RÈGLE : un module qui crée ou modifie une table doit (1) passer par enregistrer(), (2) être
 * listé dans FICHIERS. Pour forcer un nouveau passage sans redéployer de fichier, augmenter
 * REVISION ou utiliser le bouton de la page Paramètres.
 *
 * Non centralisés (volontairement) : les ajouts de colonne « au besoin » faits au milieu d'une
 * action (création d'adhérent, liste des adhésions — class-admin-adhesions.php, et
 * class-admin-members.php) : ils ne coûtent rien hors de ces actions.
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Schema {

	const REVISION      = '1';
	const OPT_SIGNATURE = 'sp_cal_schema_signature';
	const OPT_VERROU    = 'sp_cal_schema_verrou';
	const OPT_ESSAI     = 'sp_cal_schema_dernier_essai';
	const OPT_JOURNAL   = 'sp_cal_schema_journal';
	const DELAI_REESSAI = 10 * MINUTE_IN_SECONDS;

	/** Fichiers qui définissent des tables (chemins relatifs au dossier du plugin). */
	const FICHIERS = [
		'includes/class-schema.php',
		'includes/class-db.php',
		'includes/class-admin.php',
		'includes/class-front-adhesion.php',
		'includes/class-admin-inscriptions.php',
		'includes/class-trainer-app.php',
		'includes/class-dobok.php',
		'includes/class-passages.php',
		'includes/class-mail-queue.php',
	];

	/** @var array<int, array{0: string, 1: callable}> vérifications enregistrées, dans l'ordre */
	private static array $etapes = [];
	private static bool $branche = false;

	/**
	 * Confie une vérification de tables au module central. À appeler dans le constructeur du
	 * module (avant le hook init). $libelle apparaît dans le journal.
	 */
	public static function enregistrer( string $libelle, callable $verification ): void {
		self::$etapes[] = [ $libelle, $verification ];
		if ( ! self::$branche ) {
			self::$branche = true;
			add_action( 'init', [ __CLASS__, 'verifier' ], 1 );
			add_action( 'admin_post_sp_cal_schema_reverifier', [ __CLASS__, 'handle_reverifier' ] );
		}
	}

	/** Empreinte des fichiers qui définissent des tables. */
	public static function signature(): string {
		$base = defined( 'SP_CAL_PRO_PATH' ) ? SP_CAL_PRO_PATH : plugin_dir_path( __DIR__ );
		$s    = self::REVISION;
		foreach ( self::FICHIERS as $f ) {
			$p  = $base . $f;
			$s .= '|' . $f . ':' . ( is_file( $p ) ? filemtime( $p ) . ':' . filesize( $p ) : '-' );
		}
		return md5( $s );
	}

	/** À chaque requête (init, priorité 1) : ne fait rien si l'empreinte est à jour. */
	public static function verifier(): void {
		$signature = self::signature();
		if ( get_option( self::OPT_SIGNATURE ) === $signature ) return;
		if ( ( time() - intval( get_option( self::OPT_ESSAI, 0 ) ) ) < self::DELAI_REESSAI
			&& get_option( self::OPT_SIGNATURE . '_echec' ) === $signature ) return;
		self::executer( $signature, 'automatique' );
	}

	/** Exécute toutes les vérifications enregistrées. @return bool true si tout a réussi */
	private static function executer( string $signature, string $origine ): bool {
		// Verrou : add_option() échoue si l'option existe ; un verrou de plus de 5 min est périmé.
		if ( ! add_option( self::OPT_VERROU, time(), '', false ) ) {
			if ( ( time() - intval( get_option( self::OPT_VERROU, 0 ) ) ) < 5 * MINUTE_IN_SECONDS ) return false;
			update_option( self::OPT_VERROU, time(), false );
		}
		update_option( self::OPT_ESSAI, time(), false );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // dbDelta(), aussi hors administration

		$debut   = microtime( true );
		$erreurs = [];
		foreach ( self::$etapes as [ $libelle, $verification ] ) {
			try {
				call_user_func( $verification );
			} catch ( \Throwable $e ) {
				$erreurs[] = $libelle . ' : ' . $e->getMessage();
				error_log( '[SP_Build] Base de données — échec de « ' . $libelle . ' » : ' . $e->getMessage() );
			}
		}
		delete_option( self::OPT_VERROU );

		if ( $erreurs ) {
			update_option( self::OPT_SIGNATURE . '_echec', $signature, false );
		} else {
			update_option( self::OPT_SIGNATURE, $signature );
			delete_option( self::OPT_SIGNATURE . '_echec' );
		}
		$journal   = (array) get_option( self::OPT_JOURNAL, [] );
		$journal[] = [
			'date'    => current_time( 'd/m/Y H:i:s' ),
			'origine' => $origine,
			'etapes'  => count( self::$etapes ),
			'duree'   => round( microtime( true ) - $debut, 2 ),
			'erreurs' => $erreurs,
		];
		update_option( self::OPT_JOURNAL, array_slice( $journal, -20 ), false );
		return ! $erreurs;
	}

	/** Bouton « Revérifier la base de données » de la page Paramètres. */
	public static function handle_reverifier(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'sp_cal_schema_reverifier' );
		$ok = self::executer( self::signature(), 'manuelle' );
		wp_safe_redirect( add_query_arg( [ 'page' => 'sp-cal-settings', 'schema' => $ok ? 'ok' : 'ko' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Bloc d'état pour la page Paramètres. */
	public static function render_statut(): void {
		$journal = (array) get_option( self::OPT_JOURNAL, [] );
		$dernier = $journal ? end( $journal ) : null;
		$a_jour  = get_option( self::OPT_SIGNATURE ) === self::signature();
		if ( isset( $_GET['schema'] ) ) {
			echo sanitize_key( $_GET['schema'] ) === 'ok'
				? '<div class="notice notice-success is-dismissible"><p>✅ Base de données vérifiée.</p></div>'
				: '<div class="notice notice-error is-dismissible"><p>❌ La vérification a rencontré une erreur — voir le détail ci-dessous.</p></div>';
		}
		echo '<div class="sp-box"><h2>🗄️ Base de données</h2><p>';
		echo $a_jour ? '✅ À jour' : '⏳ Vérification en attente (elle passera au prochain chargement de page)';
		if ( $dernier ) {
			echo ' — dernière vérification le ' . esc_html( $dernier['date'] ) . ' (' . esc_html( $dernier['origine'] ) . ', '
				. intval( $dernier['etapes'] ) . ' modules, ' . esc_html( (string) $dernier['duree'] ) . ' s)';
		}
		echo '</p>';
		if ( $dernier && ! empty( $dernier['erreurs'] ) ) {
			echo '<p style="color:#b91c1c;">Erreurs : ' . esc_html( implode( ' · ', $dernier['erreurs'] ) ) . '</p>';
		}
		echo '<p class="description">Les tables du plugin sont vérifiées automatiquement une fois après chaque déploiement d\'un fichier qui les définit (et non plus à chaque page). Le bouton relance la vérification complète.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="sp_cal_schema_reverifier">';
		wp_nonce_field( 'sp_cal_schema_reverifier' );
		echo '<button type="submit" class="button">Revérifier la base de données</button></form></div>';
	}
}

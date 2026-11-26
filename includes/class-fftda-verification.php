<?php
/**
 * Vérification des catégories FFTDA en début de saison (26/11/2026).
 *
 * Bloc de Paramètres → Maintenance, actionné par un administrateur à chaque début de saison :
 *  1. « Chercher les tableaux FFTDA » : lit les pages réglementaires de fftda.fr et liste les PDF
 *     publiés pour la saison (dossier « files/file/Competition/AAAA_AAAA/ »). Rien n'est analysé
 *     automatiquement : un PDF change trop souvent de nom et de présentation pour être lu de façon fiable.
 *  2. L'administrateur compare ces tableaux avec celui calculé par le plugin (années de naissance →
 *     catégorie), puis « valide la saison » : date et auteur sont enregistrés.
 * Tant que la saison en cours n'est pas validée, un rappel s'affiche sur la page Adhérents.
 * Si une tranche a changé, il faut modifier SP_Cal_Categorie_Fftda::TRANCHES (class-categorie-fftda.php).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SP_Cal_Fftda_Verification' ) ) :

final class SP_Cal_Fftda_Verification {

	const OPT        = 'sp_cal_fftda_verification';   // dernière validation : saison, date, auteur, documents
	const TRANSIENT  = 'sp_cal_fftda_recherche';       // résultat de la dernière recherche sur fftda.fr
	const PAGES      = [
		'https://www.fftda.fr/fr/16-reglement-inscription-derogation.html',
		'https://www.fftda.fr/fr/541-competitions-combat.html',
		'https://www.fftda.fr/fr/542-competitions-technique.html',
	];

	private static $instance = null;

	public static function get_instance(): self {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_sp_cal_fftda_chercher', [ $this, 'handle_chercher' ] );
		add_action( 'admin_post_sp_cal_fftda_valider', [ $this, 'handle_valider' ] );
		add_action( 'admin_notices', [ $this, 'rappel' ] );
	}

	/** Saison de référence, ex. « 2026/2027 » (même calcul que la catégorie). */
	public static function saison(): string {
		$ref = SP_Cal_Categorie_Fftda::annee_reference();
		return ( $ref - 1 ) . '/' . $ref;
	}

	public static function est_validee(): bool {
		$v = (array) get_option( self::OPT, [] );
		return ( $v['saison'] ?? '' ) === self::saison();
	}

	private static function url_bloc(): string {
		return admin_url( 'admin.php?page=sp-cal-settings&onglet=maintenance#sp-fftda' );
	}

	/** Rappel sur la page Adhérents tant que la saison n'est pas validée. */
	public function rappel(): void {
		if ( ( $_GET['page'] ?? '' ) !== 'sp-cal-eleves' || ! current_user_can( 'manage_options' ) ) return;
		if ( self::est_validee() ) return;
		echo '<div class="notice notice-warning"><p>🥋 <strong>Catégories FFTDA ' . esc_html( self::saison() )
			. ' à vérifier</strong> : début de saison, comparez les tableaux de la fédération avec ceux du plugin. '
			. '<a href="' . esc_url( self::url_bloc() ) . '">Faire la vérification</a></p></div>';
	}

	/** Cherche sur fftda.fr les PDF de la saison. */
	public function handle_chercher(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'sp_cal_fftda_chercher' );
		$dossier = str_replace( '/', '_', self::saison() );   // « 2026_2027 »
		$docs = [];
		$erreurs = 0;
		foreach ( self::PAGES as $page ) {
			$r = wp_remote_get( $page, [ 'timeout' => 15, 'user-agent' => 'Mozilla/5.0 (compatible; club WordPress)' ] );
			if ( is_wp_error( $r ) || (int) wp_remote_retrieve_response_code( $r ) !== 200 ) { $erreurs++; continue; }
			$html = (string) wp_remote_retrieve_body( $r );
			// guillemets doubles ou simples traités à part : un nom de fichier peut contenir une apostrophe (« d'age »)
			preg_match_all( '#href\s*=\s*"([^"]+\.pdf)"#i', $html, $m1 );
			preg_match_all( "#href\s*=\s*'([^']+\.pdf)'#i", $html, $m2 );
			foreach ( array_merge( $m1[1], $m2[1] ) as $href ) {
				$href = html_entity_decode( $href, ENT_QUOTES );
				if ( strpos( rawurldecode( $href ), $dossier ) === false ) continue;
				$nom = rawurldecode( basename( $href ) );
				if ( ! preg_match( '/(cat[ée]gor|[aâ]ge)/iu', $nom ) ) continue;   // seulement les tableaux de catégories
				if ( strpos( $href, 'http' ) !== 0 ) $href = 'https://www.fftda.fr/' . ltrim( $href, '/' );
				$docs[ $href ] = $nom;
			}
		}
		set_transient( self::TRANSIENT, [
			'saison'  => self::saison(),
			'date'    => current_time( 'd/m/Y H:i' ),
			'docs'    => $docs,
			'erreurs' => $erreurs,
		], 30 * DAY_IN_SECONDS );
		wp_safe_redirect( self::url_bloc() );
		exit;
	}

	/** L'administrateur a comparé : la saison est validée. */
	public function handle_valider(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );
		check_admin_referer( 'sp_cal_fftda_valider' );
		$rech = (array) get_transient( self::TRANSIENT );
		$user = wp_get_current_user();
		update_option( self::OPT, [
			'saison' => self::saison(),
			'date'   => current_time( 'd/m/Y H:i' ),
			'auteur' => $user ? $user->display_name : '',
			'docs'   => ( $rech['saison'] ?? '' ) === self::saison() ? (array) ( $rech['docs'] ?? [] ) : [],
		], false );
		wp_safe_redirect( self::url_bloc() );
		exit;
	}

	/** Lignes « années de naissance → catégorie » pour la saison. */
	private static function lignes(): array {
		$ref = SP_Cal_Categorie_Fftda::annee_reference();
		$lignes = [];
		$age_sup = null;   // âge minimum de la tranche immédiatement plus âgée
		foreach ( SP_Cal_Categorie_Fftda::TRANCHES as $min => $libelle ) {
			$plus_jeune = $ref - $min;                                // né au plus tard
			$plus_vieux = $age_sup === null ? null : $ref - $age_sup + 1;
			$ages  = $age_sup === null ? $min . ' ans et plus' : ( $age_sup - 1 === $min ? $min . ' ans' : $min . ' à ' . ( $age_sup - 1 ) . ' ans' );
			$annee = $plus_vieux === null ? $plus_jeune . ' et avant' : ( $plus_vieux === $plus_jeune ? (string) $plus_jeune : $plus_vieux . ' à ' . $plus_jeune );
			$lignes[] = [ $libelle, $ages, $annee ];
			$age_sup = $min;
		}
		return $lignes;
	}

	/** Bloc de Paramètres → Maintenance. */
	public static function render(): void {
		$saison  = self::saison();
		$club    = trim( (string) get_option( 'tkd_saison_courante', '' ) );
		$v       = (array) get_option( self::OPT, [] );
		$rech    = get_transient( self::TRANSIENT );
		$ok      = self::est_validee();
		$y       = (int) current_time( 'Y' );
		$calend  = (int) current_time( 'n' ) >= 9 ? $y . '/' . ( $y + 1 ) : ( $y - 1 ) . '/' . $y;

		echo '<div class="sp-box" id="sp-fftda" style="border-left:4px solid ' . ( $ok ? '#16a34a' : '#f59e0b' ) . ';">';
		echo '<h2>🥋 Catégories FFTDA — vérification de début de saison</h2>';
		echo '<p>Saison utilisée pour le calcul : <strong>' . esc_html( $saison ) . '</strong>';
		echo $club !== '' ? ' (réglage « saison courante » du club)' : ' (calculée à la date du jour, aucun réglage « saison courante »)';
		echo '. ';
		echo $ok
			? '✅ Vérifiée le ' . esc_html( $v['date'] ?? '' ) . ( ! empty( $v['auteur'] ) ? ' par ' . esc_html( $v['auteur'] ) : '' ) . '.'
			: '⚠️ <strong>Pas encore vérifiée pour cette saison.</strong>';
		echo '</p>';
		if ( $club !== '' && $club !== $calend ) {
			echo '<p style="color:#b45309;">⚠️ Nous sommes en saison ' . esc_html( $calend ) . ' mais la « saison courante » du club est encore '
				. esc_html( $club ) . ' : les catégories restent calculées sur cette saison tant qu\'elle n\'a pas été passée à la suivante (clôture de saison dans TKD Cotisations).</p>';
		}

		echo '<p class="description">Chaque début de saison : 1) chercher les tableaux publiés par la FFTDA, 2) les ouvrir et comparer avec le tableau ci-dessous, 3) valider. '
			. 'Si une tranche ne correspond plus, ne validez pas et signalez-le : la correction se fait dans le plugin.</p>';

		// 1. Recherche
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:10px 0;">';
		echo '<input type="hidden" name="action" value="sp_cal_fftda_chercher">';
		wp_nonce_field( 'sp_cal_fftda_chercher' );
		echo '<button type="submit" class="button">🔎 1. Chercher les tableaux FFTDA ' . esc_html( $saison ) . ' sur fftda.fr</button></form>';

		if ( is_array( $rech ) && ( $rech['saison'] ?? '' ) === $saison ) {
			echo '<p>Recherche du ' . esc_html( $rech['date'] ) . ' : ';
			if ( ! empty( $rech['docs'] ) ) {
				echo count( $rech['docs'] ) . ' document(s) trouvé(s)</p><ul style="list-style:disc;margin-left:20px;">';
				foreach ( $rech['docs'] as $url => $nom ) {
					echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $nom ) . '</a></li>';
				}
				echo '</ul>';
			} else {
				echo '<strong>aucun tableau de catégories ' . esc_html( $saison ) . ' trouvé</strong>'
					. ( ! empty( $rech['erreurs'] ) ? ' (' . intval( $rech['erreurs'] ) . ' page(s) de fftda.fr inaccessibles)' : '' )
					. '. La FFTDA ne les a peut-être pas encore publiés : réessayez plus tard, ou cherchez-les sur '
					. '<a href="' . esc_url( self::PAGES[0] ) . '" target="_blank" rel="noopener">la page Règlement / Inscription / Dérogation</a>.</p>';
			}
		}

		// 2. Tableau calculé
		echo '<table class="widefat striped" style="max-width:560px;margin:10px 0;"><thead><tr><th>Catégorie</th><th>Âge au 31/12/'
			. intval( SP_Cal_Categorie_Fftda::annee_reference() ) . '</th><th>Né(e) en</th></tr></thead><tbody>';
		foreach ( self::lignes() as $l ) {
			echo '<tr><td>' . esc_html( $l[0] ) . '</td><td>' . esc_html( $l[1] ) . '</td><td>' . esc_html( $l[2] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Junior 1 / 2 / 3 : numérotation de la licence, absente des tableaux fédéraux (« Juniors 15 à 17 ans »).</p>';

		// 3. Validation
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;">';
		echo '<input type="hidden" name="action" value="sp_cal_fftda_valider">';
		wp_nonce_field( 'sp_cal_fftda_valider' );
		echo '<button type="submit" class="button button-primary">✅ 2. J\'ai comparé : les catégories ' . esc_html( $saison ) . ' sont conformes</button></form>';
		echo '</div>';
	}
}

endif;

<?php
/**
 * Organisation du menu « SP Calendar » de l'administration + page « Shortcodes ».
 *
 * Chaque module ajoute lui-même sa page au menu (add_submenu_page), dans l'ordre où il a été
 * écrit : au fil du temps la liste est devenue longue et sans logique. Ce module ne déclare
 * aucune de ces pages : il passe APRÈS tout le monde (admin_menu, priorité 999) et se contente
 * de remettre la liste dans l'ordre de GROUPES, de renommer les entrées et d'intercaler des
 * intertitres non cliquables. Les adresses (?page=…) et les droits de chaque page ne changent pas.
 *
 * Une page absente de GROUPES (nouveau module pas encore rangé) n'est jamais perdue : elle est
 * placée à la fin, sous l'intertitre « Autres ».
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Menu {

	const PARENT          = 'sp-cal-pro';
	const PAGE_SHORTCODES = 'sp-cal-shortcodes';

	/** Intertitre => [ adresse de la page => libellé dans le menu ], dans l'ordre d'affichage. */
	const GROUPES = [
		'Planning' => [
			'sp-cal-pro'          => 'Calendrier',
			'sp-cal-slots'        => 'Créneaux',
			'sp-cal-inscriptions' => 'Inscriptions & sondages',
		],
		'Présences' => [
			'sp-cal-pointage' => 'Pointage QR',
			'sp-cal-stats'    => 'Statistiques',
		],
		'Adhérents' => [
			'sp-cal-eleves'         => 'Adhérents',
			'sp_adhesions'          => 'Demandes d\'adhésion',
			'sp-cal-licences'       => 'Fin de saison & certificats',
			'sp-cal-photos-a-trier' => 'Photos à trier',
			'sp-cal-print-cartes'   => 'Cartes membres',
			'sp-cal-anniversaires'  => 'Anniversaires',
			'sp-cal-dobok'          => 'Doboks',
		],
		'Grades & compétition' => [
			'sp-cal-passages' => 'Passages de grade',
			'sp-cal-palmares' => 'Palmarès',
		],
		'Encadrement' => [
			'sp-cal-trainers' => 'Entraîneurs & Bureau',
			'sp-cal-ik'       => 'Indemnités km',
		],
		'Configuration' => [
			'sp-cal-envois'       => 'Envois d\'emails',
			self::PAGE_SHORTCODES => 'Shortcodes',
			'sp-cal-settings'     => 'Paramètres',
		],
	];

	/** Pages hors menu (ouvertes par un onglet ou un lien) => entrée du menu à garder surlignée. */
	const PAGES_RATTACHEES = [
		'sp-cal-sondages'     => 'sp-cal-inscriptions',
		'sp-cal-dates-grades' => 'sp-cal-passages',
		'sp-cal-fiche-eleve'  => 'sp-cal-eleves',
	];

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 20 );
		add_action( 'admin_menu', [ __CLASS__, 'organiser' ], 999 );
		add_filter( 'parent_file', [ __CLASS__, 'menu_parent_actif' ] );
		add_filter( 'submenu_file', [ __CLASS__, 'sous_menu_actif' ] );
		add_action( 'admin_head', [ __CLASS__, 'styles' ] );
	}

	public static function menu(): void {
		add_submenu_page( self::PARENT, 'Shortcodes', 'Shortcodes', 'manage_options', self::PAGE_SHORTCODES, [ __CLASS__, 'page_shortcodes' ] );
	}

	/* ── Ordre, libellés et intertitres ─────────────────────────────────────── */

	public static function organiser(): void {
		global $submenu;
		if ( empty( $submenu[ self::PARENT ] ) ) return;

		$par_slug = [];
		foreach ( $submenu[ self::PARENT ] as $item ) $par_slug[ $item[2] ] = $item;

		$groupes = self::GROUPES;
		$ranges  = array_merge( ...array_values( array_map( 'array_keys', $groupes ) ) );
		foreach ( array_diff( array_keys( $par_slug ), $ranges ) as $slug ) {
			$groupes['Autres'][ $slug ] = null;   // libellé d'origine conservé
		}

		$nouveau = [];
		foreach ( $groupes as $intertitre => $pages ) {
			$items = [];
			foreach ( $pages as $slug => $libelle ) {
				if ( ! isset( $par_slug[ $slug ] ) ) continue;
				$item = $par_slug[ $slug ];
				if ( $libelle !== null ) {
					// La pastille de compteur (demandes en attente, doboks à traiter) est conservée.
					$pastille = preg_match( '/<span class="awaiting-mod">.*<\/span>/s', (string) $item[0], $m ) ? ' ' . $m[0] : '';
					$item[0]  = esc_html( $libelle ) . $pastille;
				}
				$items[] = $item;
			}
			$visibles = array_filter( $items, static function ( $item ) { return current_user_can( $item[1] ); } );
			if ( ! $visibles ) continue;   // WordPress retire ensuite lui-même les pages interdites
			// Jamais d'intertitre en tête de liste : WordPress fait pointer le menu « SP Calendar »
			// vers la première entrée visible, qui doit donc être une vraie page.
			if ( $nouveau ) {
				$nouveau[] = [ esc_html( $intertitre ), 'read', '#sp-cal-groupe-' . sanitize_title( $intertitre ), $intertitre, 'sp-cal-menu-titre' ];
			}
			foreach ( $items as $item ) $nouveau[] = $item;
		}
		$submenu[ self::PARENT ] = $nouveau;
	}

	public static function menu_parent_actif( $parent_file ) {
		return isset( self::PAGES_RATTACHEES[ self::page_courante() ] ) ? self::PARENT : $parent_file;
	}

	public static function sous_menu_actif( $submenu_file ) {
		return self::PAGES_RATTACHEES[ self::page_courante() ] ?? $submenu_file;
	}

	private static function page_courante(): string {
		return sanitize_key( $_GET['page'] ?? '' );
	}

	/** Le menu est visible sur toutes les pages de l'administration : style minimal, en ligne. */
	public static function styles(): void {
		echo '<style>'
			. '#adminmenu .wp-submenu li.sp-cal-menu-titre a,#adminmenu .wp-submenu li.sp-cal-menu-titre a:hover,#adminmenu .wp-submenu li.sp-cal-menu-titre a:focus{'
			. 'pointer-events:none;cursor:default;box-shadow:none;color:#72aee6 !important;font-size:11px;font-weight:600;'
			. 'text-transform:uppercase;letter-spacing:.06em;margin-top:6px;padding-top:9px;padding-bottom:2px;'
			. 'border-top:1px solid rgba(255,255,255,.12);}'
			. '</style>';
	}

	/* ── Onglets communs à plusieurs pages ──────────────────────────────────── */

	/** Onglets des pages « Inscriptions » et « Sondages » (une seule entrée dans le menu). */
	public static function onglets_evenements( string $actif ): void {
		self::onglets( [
			'inscriptions' => [ 'Inscriptions', admin_url( 'admin.php?page=sp-cal-inscriptions' ) ],
			'sondages'     => [ 'Sondages', admin_url( 'admin.php?page=sp-cal-sondages' ) ],
		], $actif );
	}

	/** Onglets de « Passages de grade », dont l'import des dates de grades (ancienne entrée du menu). */
	public static function onglets_grades( string $actif ): void {
		self::onglets( [
			'passages' => [ 'Passages', admin_url( 'admin.php?page=sp-cal-passages' ) ],
			'epreuves' => [ 'Épreuves transverses', admin_url( 'admin.php?page=sp-cal-passages&tab=epreuves' ) ],
			'dates'    => [ 'Dates des grades', admin_url( 'admin.php?page=sp-cal-dates-grades' ) ],
		], $actif );
	}

	/** Barre d'onglets : [ clé => [ libellé, adresse ] ]. */
	public static function onglets( array $onglets, string $actif ): void {
		echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px;">';
		foreach ( $onglets as $cle => [ $libelle, $url ] ) {
			printf( '<a class="nav-tab%s" href="%s">%s</a>', $cle === $actif ? ' nav-tab-active' : '', esc_url( $url ), esc_html( $libelle ) );
		}
		echo '</nav>';
	}

	/* ── Page « Shortcodes » ────────────────────────────────────────────────── */

	/** Famille => [ shortcode => [ ce qu'il affiche, options ] ]. */
	private static function catalogue(): array {
		return [
			'Pages publiques' => [
				'sp_cal_planning'         => [ 'Planning hebdomadaire des cours (créneaux).', '' ],
				'sp_cal_evenements'       => [ 'Liste des prochains événements du calendrier.', 'nb (10) · type · categorie · passes (1 = événements passés)' ],
				'sp_cal_palmares'         => [ 'Palmarès des compétitions (réglages dans Paramètres).', '' ],
				'sp_top5_saison'          => [ 'Classement des meilleurs élèves de la saison.', 'nb (5) · saison · titre · categorie_age · discipline' ],
				'sp_cal_bureau'           => [ 'Trombinoscope du bureau et des entraîneurs.', 'roles (bureau) · titre · colonnes (3) · taille_photo (130) · couleur_nom · couleur_fn' ],
				'sp_inscription_adhesion' => [ 'Formulaire d\'adhésion et de renouvellement.', '' ],
			],
			'Espaces à accès personnel' => [
				'sp_cal_app'          => [ 'Application du club (page /app/) : adhérents, entraîneurs, pointage.', '' ],
				'sp_cal_dispo_app'    => [ 'Application « Disponibilités » des entraîneurs (lien personnel).', '' ],
				'sp_cal_fiche_membre' => [ 'Fiche de suivi d\'un adhérent, ouverte par son lien personnel.', '' ],
				'sp_cal_eleves'       => [ 'Liste des élèves.', '' ],
			],
			'Usage interne' => [
				'sp_cal_calendar' => [ 'Calendrier de gestion. Ne fonctionne que dans l\'administration (page « Calendrier ») : ne pas le placer sur une page du site.', '' ],
			],
			'Autres extensions du club' => [
				'claira_tkd_parcours'         => [ 'TKD Parcours : programme des grades.', 'order (ASC) · age' ],
				'claira_tkd_parcours_tableau' => [ 'TKD Parcours : tableau de progression imprimable.', 'age (Enfant) · titre · sous_titre' ],
				'claira_tkd_schema_grades'    => [ 'TKD Parcours : schéma des grades par catégorie d\'âge.', 'bandeau · aide · note · sous_titre_baby · sous_titre_enfant · sous_titre_ado' ],
				'sp_compta_saisie_rapide'     => [ 'Trésorerie : saisie rapide d\'une dépense ou d\'une recette.', '' ],
			],
		];
	}

	/** Pages et modèles du site (éditeur classique ou Elementor) qui contiennent le shortcode. */
	private static function pages_utilisant( string $tag ): array {
		global $wpdb;
		$seul = '%' . $wpdb->esc_like( '[' . $tag . ']' ) . '%';
		$opts = '%' . $wpdb->esc_like( '[' . $tag . ' ' ) . '%';
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title, p.post_status
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
			 WHERE p.post_type NOT IN ('revision','nav_menu_item','attachment')
			   AND p.post_status IN ('publish','private','draft','pending','future')
			   AND ( p.post_content LIKE %s OR p.post_content LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s )
			 ORDER BY p.post_title
			 LIMIT 20",
			$seul, $opts, $seul, $opts
		) );
	}

	public static function page_shortcodes(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé.' );

		echo '<div class="wrap sp-cal-wrap"><h1>Shortcodes</h1>';
		echo '<p class="description" style="max-width:820px;">Un shortcode est un petit code entre crochets à coller dans une page du site : il y affiche un élément du club. '
			. 'Les options s\'ajoutent dans les crochets, par exemple <code>[sp_cal_evenements nb="5"]</code> (la valeur par défaut est entre parenthèses).</p>';

		foreach ( self::catalogue() as $famille => $shortcodes ) {
			echo '<h2>' . esc_html( $famille ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:1200px;"><thead><tr>'
				. '<th style="width:270px;">Shortcode</th><th>Ce qu\'il affiche</th><th style="width:26%;">Options</th><th style="width:22%;">Utilisé sur</th>'
				. '</tr></thead><tbody>';
			foreach ( $shortcodes as $tag => [ $role, $options ] ) {
				$code  = '[' . $tag . ']';
				// Pas de test shortcode_exists() : certains ne sont déclarés que sur les pages publiques.
				echo '<tr><td><code>' . esc_html( $code ) . '</code>';
				echo ' <button type="button" class="button button-small sp-sc-copier" data-code="' . esc_attr( $code ) . '">Copier</button>';
				echo '</td><td>' . esc_html( $role ) . '</td><td>' . ( $options !== '' ? esc_html( $options ) : '—' ) . '</td><td>';
				$pages = self::pages_utilisant( $tag );
				if ( ! $pages ) echo '—';
				foreach ( $pages as $p ) {
					$titre = $p->post_title !== '' ? $p->post_title : '(sans titre)';
					$lien  = get_edit_post_link( (int) $p->ID );
					echo $lien ? '<a href="' . esc_url( $lien ) . '">' . esc_html( $titre ) . '</a>' : esc_html( $titre );
					if ( $p->post_status !== 'publish' ) echo ' <em>(' . esc_html( $p->post_status === 'draft' ? 'brouillon' : $p->post_status ) . ')</em>';
					echo '<br>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		?>
		<script>
		document.addEventListener( 'click', function ( e ) {
			var b = e.target.closest ? e.target.closest( '.sp-sc-copier' ) : null;
			if ( ! b ) return;
			var code = b.getAttribute( 'data-code' ), fini = function () {
				b.textContent = 'Copié ✓';
				setTimeout( function () { b.textContent = 'Copier'; }, 1500 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( code ).then( fini );
			} else {
				var t = document.createElement( 'textarea' );
				t.value = code; document.body.appendChild( t ); t.select();
				document.execCommand( 'copy' ); document.body.removeChild( t ); fini();
			}
		} );
		</script>
		<?php
		echo '</div>';
	}
}

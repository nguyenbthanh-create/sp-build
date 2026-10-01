<?php
/**
 * Anniversaires du mois — pour le goûter mensuel du club (demande du 01/10/2026).
 *
 * 1. Page d'administration « Anniversaires » : liste du mois choisi + impressions —
 *    l'affiche du mois (« Joyeux anniversaire ! Le club souhaite un joyeux anniversaire à »
 *    suivi directement des pastilles photo + prénom + jour) et, en option, des vignettes à
 *    découper. Polices festives (Google Fonts) chargées uniquement sur ces pages d'impression.
 * 2. Route REST spcal/v1/anniversaires (même PIN que le pointage) : l'onglet Pointage de
 *    l'application entraîneur affiche les anniversaires du mois, cours par cours.
 * 3. Vœux personnels par email le jour J, envoyés par la tâche quotidienne de 8 h DÉJÀ
 *    planifiée par SpCalPro_Notifications (hook sp_cal_daily_notif) — aucune nouvelle tâche.
 *    Garde-fous : désactivé par défaut, jour J strict (ni avance ni rattrapage), jamais deux
 *    fois le même adhérent la même année, 10 envois maximum par passage, adhérents exclus
 *    à la demande, journal des envois et des échecs (raison WordPress).
 *
 * Données affichées au club (affiche, vignettes) : prénom + initiale du nom et jour, jamais
 * l'âge ni la date de naissance complète. Photo uniquement si une photo existe sur la fiche
 * ET que la diffusion est autorisée (droit_image = 1).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Anniversaires {

	private static ?self $instance = null;
	private $db;

	const MOIS = array( 1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' );

	// Options des vœux par email
	const OPT_VOEUX_ACTIF = 'sp_cal_voeux_actif';     // '1' / '0' (défaut '0')
	const OPT_EXCLUS      = 'sp_cal_voeux_exclus';    // [ id_eleve, … ] — ne reçoivent pas de vœux
	const OPT_ENVOYES     = 'sp_cal_voeux_envoyes';   // [ annee => [ id_eleve, … ] ] — anti-doublon
	const OPT_JOURNAL     = 'sp_cal_voeux_journal';   // 40 dernières lignes [ date, nom, email, ok, erreur ]
	const MAX_PAR_PASSAGE = 10;

	private string $derniere_erreur_mail = '';

	public static function get_instance( $db = null ): self {
		if ( self::$instance === null ) self::$instance = new self( $db );
		return self::$instance;
	}

	private function __construct( $db = null ) {
		$this->db = $db;
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
		add_action( 'admin_post_sp_anniv_print', [ $this, 'handle_print' ] );
		add_action( 'admin_post_sp_anniv_voeux_reglage', [ $this, 'handle_voeux_reglage' ] );
		add_action( 'admin_post_sp_anniv_voeux_exclure', [ $this, 'handle_voeux_exclure' ] );
		add_action( 'admin_post_sp_anniv_voeux_test', [ $this, 'handle_voeux_test' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest' ] );
		// Tâche quotidienne de 8 h existante (planifiée par SpCalPro_Notifications).
		add_action( 'sp_cal_daily_notif', [ $this, 'envoyer_voeux_du_jour' ], 20 );
	}

	// ══════════════════════════════════════════════════════════════════════
	// DONNÉES
	// ══════════════════════════════════════════════════════════════════════

	private function table_eleves(): string {
		global $wpdb;
		return $this->db ? $this->db->table_eleves() : $wpdb->prefix . 'sp_cal_eleves';
	}

	/**
	 * Adhérents actifs nés le mois donné (et, si $jour > 0, ce jour-là), triés par jour.
	 * date_naissance est stockée « JJ/MM » (année à part, annee_naissance) — même filtre que le
	 * calendrier admin (SpCalPro_DB::get_birthdays_for_month).
	 *
	 * @return object[] chaque élément enrichi de ->jour (int) et ->age (int|null, âge atteint cette année-là)
	 */
	public function get_anniversaires( int $annee, int $mois, int $jour = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table_eleves()} WHERE actif = 1 AND date_naissance LIKE %s",
			'%/' . sprintf( '%02d', $mois )
		) );
		$out = [];
		foreach ( (array) $rows as $el ) {
			$parts = explode( '/', (string) $el->date_naissance );
			$j     = intval( $parts[0] ?? 0 );
			if ( $j < 1 || $j > 31 || intval( $parts[1] ?? 0 ) !== $mois ) continue;
			if ( $jour > 0 && $j !== $jour ) continue;
			$el->jour = $j;
			$el->age  = ! empty( $el->annee_naissance ) ? $annee - intval( $el->annee_naissance ) : null;
			$out[]    = $el;
		}
		usort( $out, static function ( $a, $b ) {
			return $a->jour <=> $b->jour ?: strcasecmp( $a->prenom, $b->prenom );
		} );
		return $out;
	}

	/** « Léa M. » — prénom + initiale du nom, pour tout ce qui est affiché au club. */
	private static function nom_court( object $el ): string {
		$initiale = mb_strtoupper( mb_substr( trim( (string) $el->nom ), 0, 1 ) );
		return trim( $el->prenom ) . ( $initiale !== '' ? ' ' . $initiale . '.' : '' );
	}

	private static function initiales( object $el ): string {
		return mb_strtoupper( mb_substr( trim( (string) $el->prenom ), 0, 1 ) . mb_substr( trim( (string) $el->nom ), 0, 1 ) );
	}

	/** Adresse de la photo affichable (version réduite si possible), ou '' si pas de photo ou pas d'autorisation de diffusion. */
	private static function photo( object $el ): string {
		if ( empty( $el->photo_url ) || empty( $el->droit_image ) ) return '';
		$id = attachment_url_to_postid( $el->photo_url );
		if ( $id ) {
			$src = wp_get_attachment_image_url( $id, 'medium' );
			if ( $src ) return $src;
		}
		return $el->photo_url;
	}

	/** Mois demandé (« AAAA-MM »), sinon le mois en cours. */
	private static function mois_demande(): array {
		$m = isset( $_GET['mois'] ) ? sanitize_text_field( wp_unslash( $_GET['mois'] ) ) : '';
		if ( preg_match( '/^(\d{4})-(\d{2})$/', $m, $x ) && intval( $x[2] ) >= 1 && intval( $x[2] ) <= 12 ) {
			return [ intval( $x[1] ), intval( $x[2] ) ];
		}
		return [ intval( current_time( 'Y' ) ), intval( current_time( 'n' ) ) ];
	}

	// ══════════════════════════════════════════════════════════════════════
	// PAGE D'ADMINISTRATION
	// ══════════════════════════════════════════════════════════════════════

	public function register_menu(): void {
		add_submenu_page( 'sp-cal-pro', 'Anniversaires', '🎂 Anniversaires', SP_Cal_Roles::CAP_GESTION_ADHESIONS, 'sp-cal-anniversaires', [ $this, 'render_page' ] );
	}

	private function url_page( string $cle_mois, array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => 'sp-cal-anniversaires', 'mois' => $cle_mois ], $args ), admin_url( 'admin.php' ) );
	}

	public function render_page(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );

		[ $annee, $mois ] = self::mois_demande();
		$liste      = $this->get_anniversaires( $annee, $mois );
		$cle_mois   = sprintf( '%04d-%02d', $annee, $mois );
		$url_print  = static function ( string $type ) use ( $cle_mois ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=sp_anniv_print&type=' . $type . '&mois=' . $cle_mois ), 'sp_anniv_print' );
		};
		$avec_photo = count( array_filter( $liste, static fn( $el ) => self::photo( $el ) !== '' ) );
		$actif      = get_option( self::OPT_VOEUX_ACTIF, '0' ) === '1';
		$exclus     = array_map( 'intval', (array) get_option( self::OPT_EXCLUS, [] ) );
		$journal    = (array) get_option( self::OPT_JOURNAL, [] );
		$envoyes    = array_map( 'intval', (array) ( ( (array) get_option( self::OPT_ENVOYES, [] ) )[ $annee ] ?? [] ) );
		$aujourdhui = current_time( 'Y-m-d' );
		$jours_sem  = [ 'dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi' ];

		if ( isset( $_GET['voeux_msg'] ) ) {
			$msgs = [
				'active'   => [ 'success', 'Vœux par email activés : ils partiront le jour de chaque anniversaire, lors de la tâche quotidienne de 8 h.' ],
				'desactive'=> [ 'warning', 'Vœux par email désactivés.' ],
				'exclu'    => [ 'success', 'Cet adhérent ne recevra pas de vœux par email.' ],
				'inclus'   => [ 'success', 'Cet adhérent recevra de nouveau les vœux par email.' ],
				'test_ok'  => [ 'success', 'Exemple de vœux envoyé — voir l\'adresse dans le journal ci-dessous (pensez aux courriers indésirables).' ],
				'test_ko'  => [ 'error', 'L\'exemple n\'a pas pu être envoyé — voir la raison dans le journal ci-dessous.' ],
			];
			$k = sanitize_key( wp_unslash( $_GET['voeux_msg'] ) );
			if ( isset( $msgs[ $k ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $msgs[ $k ][0] ) . ' is-dismissible"><p>' . esc_html( $msgs[ $k ][1] ) . '</p></div>';
			}
		}
		?>
		<div class="wrap sp-cal-wrap">
			<h1>🎂 Anniversaires</h1>

			<div class="sp-box">
				<h2>Anniversaires de <?php echo esc_html( self::MOIS[ $mois ] . ' ' . $annee ); ?> (<?php echo count( $liste ); ?>)</h2>
				<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
					<input type="hidden" name="page" value="sp-cal-anniversaires">
					<label for="sp-anniv-mois">Mois :</label>
					<input type="month" id="sp-anniv-mois" name="mois" value="<?php echo esc_attr( $cle_mois ); ?>">
					<button type="submit" class="button">Afficher</button>
				</form>

				<?php if ( ! $liste ) : ?>
					<p class="sp-muted">Aucun anniversaire parmi les adhérents actifs ce mois-ci.</p>
				<?php else : ?>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( $url_print( 'affiche' ) ); ?>" target="_blank" rel="noopener">Imprimer l'affiche du mois</a>
						<a class="button" href="<?php echo esc_url( $url_print( 'vignettes' ) ); ?>" target="_blank" rel="noopener">Vignettes à découper (option)</a>
					</p>
					<p class="description">
						L'affiche reprend « Joyeux anniversaire ! Le club souhaite un joyeux anniversaire à » suivi des pastilles (photo, prénom, jour) ;
						les pastilles s'adaptent au nombre d'anniversaires (A4, ou A3 pour un mois très chargé).
						Au club, seuls le prénom, l'initiale du nom et le jour sont affichés (ni l'âge ni la date de naissance).
						Photos : <?php echo intval( $avec_photo ); ?> / <?php echo count( $liste ); ?> — uniquement si une photo
						est enregistrée sur la fiche <strong>et</strong> que la diffusion des photos est autorisée ; sinon, les initiales.
					</p>
					<table class="wp-list-table widefat striped" style="margin-top:12px;">
						<thead><tr><th style="width:150px;">Anniversaire</th><th>Adhérent</th><th>Âge atteint</th><th>Catégorie</th><th>Photo sur l'affiche</th><th>Vœux par email</th></tr></thead>
						<tbody>
						<?php foreach ( $liste as $el ) :
							$raison   = self::photo( $el ) !== '' ? '✅ oui'
								: ( empty( $el->photo_url ) ? '— pas de photo sur la fiche' : '— diffusion non autorisée' );
							$est_exclu = in_array( (int) $el->id, $exclus, true );
							$dest      = $this->destinataire_voeux( $el );
							$url_bascule = wp_nonce_url( admin_url( 'admin-post.php?action=sp_anniv_voeux_exclure&eleve_id=' . intval( $el->id ) . '&mois=' . $cle_mois ), 'sp_anniv_voeux_exclure' );

							// Date d'anniversaire de l'année affichée (un 29 février tombe le 1er mars hors année bissextile).
							$date_iso   = checkdate( $mois, $el->jour, $annee ) ? sprintf( '%04d-%02d-%02d', $annee, $mois, $el->jour ) : '';
							$date_label = $date_iso
								? $jours_sem[ intval( date( 'w', strtotime( $date_iso ) ) ) ] . ' ' . $el->jour . ( $el->jour === 1 ? 'er' : '' ) . ' ' . self::MOIS[ $mois ]
								: $el->jour . ' ' . self::MOIS[ $mois ];

							// État des vœux pour cette personne.
							if ( in_array( (int) $el->id, $envoyes, true ) ) {
								$etat = '✅ vœux envoyés';
							} elseif ( ! $actif ) {
								$etat = 'vœux désactivés';
							} elseif ( $date_iso === $aujourdhui ) {
								$etat = "🎂 aujourd'hui — envoi au passage de la tâche de 8 h";
							} elseif ( $date_iso !== '' && $date_iso > $aujourdhui ) {
								$etat = 'prévus le ' . date( 'd/m', strtotime( $date_iso ) );
							} else {
								$etat = 'jour passé — non envoyés';
							}
							?>
							<tr<?php echo $date_iso === $aujourdhui ? ' style="background:#fffbea;"' : ''; ?>>
								<td><strong><?php echo esc_html( $date_label ); ?></strong></td>
								<td><?php echo esc_html( $el->prenom . ' ' . $el->nom ); ?></td>
								<td><?php echo $el->age !== null ? intval( $el->age ) . ' ans' : '<span class="sp-muted">—</span>'; ?></td>
								<td><?php echo esc_html( $el->categorie_age ); ?></td>
								<td><?php echo esc_html( $raison ); ?></td>
								<td>
									<?php if ( $est_exclu ) : ?>
										<span class="sp-muted">non (exclu)</span>
										<a href="<?php echo esc_url( $url_bascule ); ?>" class="button button-small">Réactiver</a>
									<?php elseif ( $dest === '' ) : ?>
										<span class="sp-muted">— pas d'email valide</span>
									<?php else : ?>
										<?php echo esc_html( $dest ); ?>
										<a href="<?php echo esc_url( $url_bascule ); ?>" class="button button-small" title="Ne pas envoyer de vœux à cet adhérent">Ne pas envoyer</a>
										<br><span class="sp-muted"><?php echo esc_html( $etat ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="sp-box">
				<h2>Vœux personnels par email</h2>
				<p>
					Le jour de son anniversaire, chaque adhérent reçoit un petit mot du club (pour un enfant, le message lui est écrit et envoyé à ses parents).
					Envoi par la tâche quotidienne de 8 h déjà en place — <strong>jour J uniquement</strong> (ni avance ni rattrapage), jamais deux fois la même année,
					<?php echo intval( self::MAX_PAR_PASSAGE ); ?> envois au maximum par jour.
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
					<input type="hidden" name="action" value="sp_anniv_voeux_reglage">
					<input type="hidden" name="mois" value="<?php echo esc_attr( $cle_mois ); ?>">
					<input type="hidden" name="actif" value="<?php echo $actif ? '0' : '1'; ?>">
					<?php wp_nonce_field( 'sp_anniv_voeux_reglage' ); ?>
					<strong>État :</strong> <?php echo $actif ? '<span style="color:#15803d;">✅ activés</span>' : '<span class="sp-muted">désactivés</span>'; ?>
					<button type="submit" class="button <?php echo $actif ? '' : 'button-primary'; ?>" style="margin-left:8px;"><?php echo $actif ? 'Désactiver' : 'Activer les vœux par email'; ?></button>
				</form>
				<?php if ( $liste ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
						<input type="hidden" name="action" value="sp_anniv_voeux_test">
						<input type="hidden" name="eleve_id" value="<?php echo intval( $liste[0]->id ); ?>">
						<input type="hidden" name="mois" value="<?php echo esc_attr( $cle_mois ); ?>">
						<?php wp_nonce_field( 'sp_anniv_voeux_test' ); ?>
						<label for="sp-anniv-test-email">Recevoir un exemple (vœux de <?php echo esc_html( $liste[0]->prenom ); ?>) à l'adresse :</label>
						<input type="email" id="sp-anniv-test-email" name="email_test" class="regular-text" required
						       value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
						<button type="submit" class="button">Envoyer l'exemple</button>
					</form>
				<?php endif; ?>

				<?php if ( $journal ) : ?>
					<h3 style="margin-top:18px;">Derniers envois</h3>
					<table class="wp-list-table widefat striped">
						<thead><tr><th style="width:150px;">Date</th><th>Adhérent</th><th>Adresse</th><th>Résultat</th></tr></thead>
						<tbody>
						<?php foreach ( array_reverse( $journal ) as $l ) : ?>
							<tr>
								<td><?php echo esc_html( $l['date'] ?? '' ); ?></td>
								<td><?php echo esc_html( $l['nom'] ?? '' ); ?></td>
								<td><?php echo esc_html( $l['email'] ?? '' ); ?></td>
								<td><?php echo ! empty( $l['ok'] ) ? '✅ envoyé' : '❌ échec' . ( ! empty( $l['erreur'] ) ? ' — <code>' . esc_html( $l['erreur'] ) . '</code>' : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// ══════════════════════════════════════════════════════════════════════
	// IMPRESSIONS (page autonome, sans l'habillage de l'administration)
	// ══════════════════════════════════════════════════════════════════════

	public function handle_print(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		if ( ! check_admin_referer( 'sp_anniv_print' ) ) wp_die( 'Nonce invalide.' );

		[ $annee, $mois ] = self::mois_demande();
		$type  = ( isset( $_GET['type'] ) && $_GET['type'] === 'vignettes' ) ? 'vignettes' : 'affiche';
		$liste = $this->get_anniversaires( $annee, $mois );
		$club  = get_bloginfo( 'name' );
		$logo  = '';
		$logo_id = get_theme_mod( 'custom_logo' );
		if ( $logo_id ) $logo = (string) wp_get_attachment_image_url( $logo_id, 'medium' );
		$titre_mois = self::MOIS[ $mois ];

		// Taille des pastilles de l'affiche selon le nombre d'anniversaires du mois.
		$n = count( $liste );
		$taille = $n <= 6 ? 'xl' : ( $n <= 12 ? 'l' : ( $n <= 20 ? 'm' : 's' ) );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( 'Anniversaires — ' . $titre_mois . ' ' . $annee ); ?></title>
<?php /* Polices festives, chargées uniquement ici (pages d'impression des anniversaires). */ ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;500;600&family=Pacifico&display=swap" rel="stylesheet">
<style>
	:root { --rouge:#D4000F; --encre:#222; --gris:#7a7a7a; }
	* { box-sizing: border-box; }
	body { margin: 0; background: #f2f0ed; font-family: 'Fredoka', 'Comic Sans MS', sans-serif; color: var(--encre); }
	.barre { position: sticky; top: 0; display: flex; gap: 10px; align-items: center; justify-content: center; padding: 12px; background: #fff; border-bottom: 1px solid #e8e5e2; font-size: 14px; }
	.barre button { font: inherit; font-weight: 600; padding: 8px 18px; border-radius: 20px; border: 1px solid var(--rouge); background: var(--rouge); color: #fff; cursor: pointer; }
	.page { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 14mm; background: #fff; box-shadow: 0 4px 18px rgba(0,0,0,.12); position: relative; overflow: hidden; }

	/* Guirlande de fanions aux couleurs des ceintures */
	.fanions { display: flex; justify-content: center; margin: -14mm -14mm 6mm; height: 20mm; }
	.fanions span { flex: 1; clip-path: polygon(0 0, 100% 0, 50% 100%); }
	.fanions span:nth-child(7n+1) { background: #FFD700; } .fanions span:nth-child(7n+2) { background: #e67e22; }
	.fanions span:nth-child(7n+3) { background: #27ae60; } .fanions span:nth-child(7n+4) { background: #2980b9; }
	.fanions span:nth-child(7n+5) { background: #c0392b; } .fanions span:nth-child(7n+6) { background: #8e44ad; }
	.fanions span:nth-child(7n)   { background: #111; }

	/* En-tête de l'affiche */
	.logo { display: block; height: 20mm; margin: 0 auto 3mm; }
	.titre { font-family: 'Pacifico', cursive; font-weight: 400; color: var(--rouge); text-align: center; font-size: 44pt; line-height: 1.15; margin: 0; }
	.sous-titre { text-align: center; font-size: 19pt; font-weight: 500; margin: 3mm 0 1mm; }
	.mois { text-align: center; font-size: 13pt; color: var(--gris); text-transform: uppercase; letter-spacing: .14em; margin: 0 0 8mm; }
	.pied { position: absolute; left: 0; right: 0; bottom: 8mm; text-align: center; color: var(--gris); font-size: 11pt; }
	.vide { text-align: center; font-size: 16pt; color: var(--gris); margin-top: 30mm; }

	/* Pastilles (affiche) — tailles automatiques selon le nombre d'anniversaires */
	.pastilles { display: flex; flex-wrap: wrap; justify-content: center; gap: 7mm 6mm; padding-bottom: 14mm; }
	.pastille { display: flex; flex-direction: column; align-items: center; text-align: center; width: var(--w); }
	.taille-xl { --w: 52mm; --rond: 44mm; --nom: 20pt; --date: 12pt; }
	.taille-l  { --w: 40mm; --rond: 33mm; --nom: 16pt; --date: 10.5pt; }
	.taille-m  { --w: 30mm; --rond: 25mm; --nom: 13pt; --date: 8.5pt; row-gap: 5mm; }
	.taille-s  { --w: 24mm; --rond: 18mm; --nom: 10.5pt; --date: 7.5pt; row-gap: 3mm; column-gap: 5mm; }
	/* Mois chargé : en-tête plus compact pour garder une seule feuille */
	.dense .fanions { height: 13mm; margin-bottom: 4mm; }
	.dense .logo { height: 14mm; margin-bottom: 2mm; }
	.dense .titre { font-size: 34pt; }
	.dense .sous-titre { font-size: 16pt; margin-top: 2mm; }
	.dense .mois { font-size: 11pt; margin-bottom: 5mm; }
	.rond { position: relative; width: var(--rond); height: var(--rond); border-radius: 50%; overflow: hidden; border: 1mm solid #fff; box-shadow: 0 0 0 0.6mm var(--rouge); margin-bottom: 2.5mm; display: flex; align-items: center; justify-content: center; background: #fdeaea; color: var(--rouge); font-weight: 600; font-size: calc(var(--rond) * .32); }
	.rond img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: 50% 22%; }
	.prenom { font-family: 'Pacifico', cursive; font-size: var(--nom); line-height: 1.2; color: var(--encre); }
	.date { margin-top: 1mm; font-size: var(--date); color: var(--rouge); font-weight: 600; text-transform: uppercase; letter-spacing: .08em; }

	/* Vignettes à découper (option) : 3 × 4 par feuille A4, traits de coupe en pointillés */
	.page-vignettes { padding: 10mm; }
	.planche { display: grid; grid-template-columns: repeat(3, 1fr); grid-auto-rows: 66mm; --rond: 34mm; --nom: 17pt; --date: 11pt; }
	.vignette { border: 1px dashed #bbb; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 4mm; }

	@page { size: A4 portrait; margin: 0; }
	@media print {
		body { background: #fff; }
		.barre { display: none; }
		.page { margin: 0; box-shadow: none; page-break-after: always; }
		.page:last-child { page-break-after: auto; }
		* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
	}
</style>
</head>
<body>
<div class="barre">
	<span><?php echo esc_html( ( $type === 'affiche' ? 'Affiche' : 'Vignettes à découper' ) . ' — ' . $titre_mois . ' ' . $annee ); ?> (A4 ; pour un mois très chargé, imprimez en A3)</span>
	<button type="button" onclick="window.print()">Imprimer</button>
</div>
<?php
/** Pastille : photo ronde (initiales dessous, visibles si pas de photo ou si elle ne se charge pas), prénom, jour. */
$pastille = static function ( object $el, string $classe ) use ( $titre_mois ) {
	$photo = self::photo( $el );
	?>
	<div class="<?php echo esc_attr( $classe ); ?>">
		<div class="rond"><span><?php echo esc_html( self::initiales( $el ) ); ?></span><?php if ( $photo ) : ?><img src="<?php echo esc_url( $photo ); ?>" alt="" onerror="this.remove()"><?php endif; ?></div>
		<div class="prenom"><?php echo esc_html( self::nom_court( $el ) ); ?></div>
		<div class="date"><?php echo esc_html( $el->jour . ( $el->jour === 1 ? 'er' : '' ) . ' ' . $titre_mois ); ?></div>
	</div>
	<?php
};

if ( $type === 'affiche' ) : ?>
	<div class="page<?php echo in_array( $taille, [ 'm', 's' ], true ) ? ' dense' : ''; ?>">
		<div class="fanions" aria-hidden="true"><?php echo str_repeat( '<span></span>', 14 ); ?></div>
		<?php if ( $logo ) : ?><img class="logo" src="<?php echo esc_url( $logo ); ?>" alt=""><?php endif; ?>
		<h1 class="titre">Joyeux anniversaire !</h1>
		<p class="sous-titre">Le club souhaite un joyeux anniversaire à</p>
		<p class="mois">en <?php echo esc_html( $titre_mois . ' ' . $annee ); ?></p>
		<?php if ( ! $liste ) : ?>
			<p class="vide">Pas d'anniversaire ce mois-ci.</p>
		<?php else : ?>
			<div class="pastilles taille-<?php echo esc_attr( $taille ); ?>">
				<?php foreach ( $liste as $el ) $pastille( $el, 'pastille' ); ?>
			</div>
		<?php endif; ?>
		<p class="pied"><?php echo esc_html( $club ); ?></p>
	</div>
<?php else :
	foreach ( array_chunk( $liste, 12 ) ?: [ [] ] as $planche ) : ?>
	<div class="page page-vignettes">
		<?php if ( ! $planche ) : ?>
			<p class="vide">Pas d'anniversaire ce mois-ci.</p>
		<?php else : ?>
			<div class="planche">
				<?php foreach ( $planche as $el ) $pastille( $el, 'vignette' ); ?>
			</div>
		<?php endif; ?>
	</div>
<?php endforeach;
endif; ?>
</body>
</html>
<?php
		exit;
	}

	// ══════════════════════════════════════════════════════════════════════
	// APPLICATION ENTRAÎNEUR (onglet Pointage) — même PIN que le pointage
	// ══════════════════════════════════════════════════════════════════════

	public function register_rest(): void {
		register_rest_route( 'spcal/v1', '/anniversaires', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_anniversaires' ],
			'permission_callback' => '__return_true', // contrôle par PIN ci-dessous, comme /pointage/*
		] );
	}

	public function rest_anniversaires( WP_REST_Request $req ) {
		$pin = sanitize_text_field( (string) $req->get_param( 'pin' ) );
		if ( ! function_exists( 'sp_pointage_pin' ) || $pin === '' || ! hash_equals( (string) sp_pointage_pin(), $pin ) ) {
			return new WP_REST_Response( [ 'success' => false, 'data' => 'PIN invalide' ], 403 );
		}
		$date = sanitize_text_field( (string) $req->get_param( 'date' ) );
		$ts   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? strtotime( $date ) : false;
		$ts   = $ts ?: current_time( 'timestamp' );
		$jour_ref = intval( date( 'j', $ts ) );

		$data = [];
		foreach ( $this->get_anniversaires( intval( date( 'Y', $ts ) ), intval( date( 'n', $ts ) ) ) as $el ) {
			$data[] = [
				'nom'        => self::nom_court( $el ),
				'jour'       => $el->jour,
				'age'        => $el->age,
				'categorie'  => (string) $el->categorie_age,
				'passe'      => $el->jour < $jour_ref,
				'aujourdhui' => $el->jour === $jour_ref,
			];
		}
		return new WP_REST_Response( [ 'success' => true, 'mois' => self::MOIS[ intval( date( 'n', $ts ) ) ], 'data' => $data ], 200 );
	}

	// ══════════════════════════════════════════════════════════════════════
	// VŒUX PERSONNELS PAR EMAIL — jour J, via la tâche de 8 h existante
	// ══════════════════════════════════════════════════════════════════════

	/** Mineur : âge connu < 18, ou à défaut catégorie Baby / Enfant. */
	private static function est_mineur( object $el ): bool {
		if ( $el->age !== null ) return $el->age < 18;
		return in_array( mb_strtolower( (string) $el->categorie_age ), [ 'baby', 'enfant' ], true );
	}

	/** Mineur : email du parent d'abord ; adulte : son email d'abord. Adresse valide uniquement, sinon ''. */
	private function destinataire_voeux( object $el ): string {
		$ordre = self::est_mineur( $el ) ? [ $el->email_parent ?? '', $el->email ?? '' ] : [ $el->email ?? '', $el->email_parent ?? '' ];
		foreach ( $ordre as $email ) {
			$email = trim( (string) $email );
			if ( $email !== '' && is_email( $email ) ) return $email;
		}
		return '';
	}

	/** Sujet et corps des vœux. */
	private static function message_voeux( object $el ): array {
		$club   = get_bloginfo( 'name' );
		$prenom = trim( (string) $el->prenom );
		$ans    = $el->age ? " ses {$el->age} ans" : ' son anniversaire';
		$sujet  = "[{$club}] Joyeux anniversaire {$prenom} ! 🎂";

		if ( self::est_mineur( $el ) ) {
			$corps = "Bonjour,\n\n"
			       . "C'est un grand jour : {$prenom} fête aujourd'hui{$ans} ! Voici un petit mot du club à lui transmettre :\n\n"
			       . "« Joyeux anniversaire {$prenom} ! Toute l'équipe du {$club} te souhaite une merveilleuse journée. "
			       . "On fêtera ça tous ensemble au club avec les anniversaires du mois ! »\n\n"
			       . "Sportivement,\n— L'équipe {$club}";
		} else {
			$corps = "Bonjour {$prenom},\n\n"
			       . "Toute l'équipe du {$club} vous souhaite un très joyeux anniversaire et une excellente journée !\n\n"
			       . "Au plaisir de vous retrouver sur les tatamis.\n\n"
			       . "Sportivement,\n— L'équipe {$club}";
		}
		// Possibilité de refuser les vœux : le bureau l'applique avec « Ne pas envoyer » sur la page Anniversaires.
		$corps .= "\n\n—\nVous préférez ne plus recevoir ce message ? Répondez simplement à cet email et nous ne vous l'enverrons plus.";
		return [ $sujet, $corps ];
	}

	/** wp_mail() qui retient la raison d'un échec (même principe que le renouvellement). */
	private function envoyer_mail( string $to, string $sujet, string $corps ): bool {
		$this->derniere_erreur_mail = '';
		$capture = function ( $error ) {
			if ( is_wp_error( $error ) ) $this->derniere_erreur_mail = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture );
		$ok = wp_mail( $to, $sujet, $corps );
		remove_action( 'wp_mail_failed', $capture );
		if ( ! $ok && $this->derniere_erreur_mail === '' ) $this->derniere_erreur_mail = 'wp_mail() a renvoyé false sans détail.';
		return $ok;
	}

	private function journaliser( object $el, string $email, bool $ok ): void {
		$journal   = (array) get_option( self::OPT_JOURNAL, [] );
		$journal[] = [
			'date'   => current_time( 'd/m/Y H:i' ),
			'nom'    => trim( $el->prenom . ' ' . $el->nom ),
			'email'  => $email,
			'ok'     => $ok,
			'erreur' => $ok ? '' : $this->derniere_erreur_mail,
		];
		update_option( self::OPT_JOURNAL, array_slice( $journal, -40 ), false );
		if ( ! $ok ) error_log( "[SP_Build] Vœux d'anniversaire : échec d'envoi à {$email} — {$this->derniere_erreur_mail}" );
	}

	/**
	 * Appelée par la tâche quotidienne de 8 h (sp_cal_daily_notif). Ne fait rien si les vœux
	 * sont désactivés. Jour J strict : uniquement les anniversaires de la date du jour.
	 */
	public function envoyer_voeux_du_jour(): void {
		if ( get_option( self::OPT_VOEUX_ACTIF, '0' ) !== '1' ) return;

		$annee   = intval( current_time( 'Y' ) );
		$exclus  = array_map( 'intval', (array) get_option( self::OPT_EXCLUS, [] ) );
		$envoyes = (array) get_option( self::OPT_ENVOYES, [] );
		$deja    = array_map( 'intval', (array) ( $envoyes[ $annee ] ?? [] ) );
		$n       = 0;

		foreach ( $this->get_anniversaires( $annee, intval( current_time( 'n' ) ), intval( current_time( 'j' ) ) ) as $el ) {
			if ( $n >= self::MAX_PAR_PASSAGE ) break;
			$id = (int) $el->id;
			if ( in_array( $id, $exclus, true ) || in_array( $id, $deja, true ) ) continue;
			$dest = $this->destinataire_voeux( $el );
			if ( $dest === '' ) continue;

			[ $sujet, $corps ] = self::message_voeux( $el );
			$ok = $this->envoyer_mail( $dest, $sujet, $corps );
			$this->journaliser( $el, $dest, $ok );
			$n++;
			// Marqué même en cas d'échec : jamais de deuxième tentative (ni doublon, ni rattrapage).
			$deja[] = $id;
		}

		// Anti-doublon : on ne garde que l'année en cours.
		update_option( self::OPT_ENVOYES, [ $annee => array_values( array_unique( $deja ) ) ], false );
	}

	// ── Actions de la page d'administration ───────────────────────────────

	private function verifier( string $nonce ): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );
		if ( ! check_admin_referer( $nonce ) ) wp_die( 'Nonce invalide.' );
	}

	private function retour( string $msg ): void {
		$mois = isset( $_REQUEST['mois'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mois'] ) ) : '';
		wp_safe_redirect( $this->url_page( $mois, [ 'voeux_msg' => $msg ] ) );
		exit;
	}

	public function handle_voeux_reglage(): void {
		$this->verifier( 'sp_anniv_voeux_reglage' );
		$actif = isset( $_POST['actif'] ) && $_POST['actif'] === '1';
		update_option( self::OPT_VOEUX_ACTIF, $actif ? '1' : '0' );
		$this->retour( $actif ? 'active' : 'desactive' );
	}

	public function handle_voeux_exclure(): void {
		$this->verifier( 'sp_anniv_voeux_exclure' );
		$id     = intval( $_GET['eleve_id'] ?? 0 );
		$exclus = array_map( 'intval', (array) get_option( self::OPT_EXCLUS, [] ) );
		if ( in_array( $id, $exclus, true ) ) {
			$exclus = array_values( array_diff( $exclus, [ $id ] ) );
			$msg    = 'inclus';
		} else {
			$exclus[] = $id;
			$msg      = 'exclu';
		}
		update_option( self::OPT_EXCLUS, $exclus, false );
		$this->retour( $msg );
	}

	/**
	 * Envoie l'exemple des vœux d'un adhérent à l'adresse saisie (par défaut celle du compte
	 * connecté) — rien n'est envoyé à l'adhérent.
	 */
	public function handle_voeux_test(): void {
		$this->verifier( 'sp_anniv_voeux_test' );
		global $wpdb;
		$el   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_eleves()} WHERE id = %d", intval( $_POST['eleve_id'] ?? 0 ) ) );
		$dest = sanitize_email( wp_unslash( $_POST['email_test'] ?? '' ) );
		if ( ! $el || ! is_email( $dest ) ) $this->retour( 'test_ko' );

		$el->age = ! empty( $el->annee_naissance ) ? intval( current_time( 'Y' ) ) - intval( $el->annee_naissance ) : null;
		[ $sujet, $corps ] = self::message_voeux( $el );
		$ok = $this->envoyer_mail( $dest, '[EXEMPLE] ' . $sujet, "(Exemple — ce message n'a pas été envoyé à l'adhérent.)\n\n" . $corps );
		$this->journaliser( (object) [ 'prenom' => 'Exemple :', 'nom' => trim( $el->prenom . ' ' . $el->nom ) ], $dest, $ok );
		$this->retour( $ok ? 'test_ok' : 'test_ko' );
	}
}

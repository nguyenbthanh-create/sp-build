<?php
/**
 * Carte de membre — dessin unique du recto et du verso (refonte du 13/10/2026).
 *
 * Avant, la carte était dessinée séparément sur la fiche personnelle (class-token.php) et dans
 * l'impression par lot de l'administration (trait-members-cartes.php), chacune avec ses règles en
 * pixels pour l'écran et en millimètres pour l'impression. Ici, un seul dessin : toutes les
 * dimensions sont des multiples de l'unité --u (1/340 de la largeur de la carte) et d'un débord
 * --b. À l'écran --u vaut 1 px ; à l'impression, 85,6 mm / 340 (format carte bancaire) ; en
 * « mode imprimeur », --b vaut 2 mm et les fonds débordent jusqu'aux traits de coupe.
 *
 * Demandes du club : rester dans ses couleurs (noir, bleu, jaune, rouge), une photo d'identité
 * rectangulaire bien plus grande (elle faisait 11 mm une fois imprimée), le prénom et le nom en
 * grand, les informations personnelles (urgence, naissance, licence) au verso, et 태권도 en
 * filigrane dans une police calligraphiée (East Sea Dokdo, embarquée : assets/fonts/).
 *
 * Cadrage de la photo : réglé par le bureau sur la fiche de l'adhérent (outil de recadrage) et
 * gardé dans extra_data['photo_cadrage'] = [ 'x' => …, 'y' => …, 'z' => … ] — point visé en % et zoom. Sans réglage,
 * le centre de l'image, comme avant. style_photo() sert aussi à l'en-tête de la fiche.
 *
 * L'application /app/ garde pour l'instant son ancien dessin (assets/pwa/) : elle ne se modifie
 * qu'avec l'accord explicite du club et un essai sur le site de test.
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Carte {

	const LOGO_DEFAUT = 'https://tkdclaira.fr/wp-content/uploads/2026/04/LogoSansLettre.png';

	/** Proportions du cadre de la photo sur la carte (largeur / hauteur), reprises par l'outil de recadrage. */
	const PHOTO_L = 92;
	const PHOTO_H = 118;

	/** Cadrage enregistré : [ x %, y %, zoom ] — bornés ; [ 50, 50, 1.0 ] par défaut (centre, sans zoom). */
	public static function cadrage( $el ): array {
		$extra = json_decode( (string) ( $el->extra_data ?? '' ), true );
		$c     = is_array( $extra ) && is_array( $extra['photo_cadrage'] ?? null ) ? $extra['photo_cadrage'] : [];
		return self::cadrage_valide( $c['x'] ?? 50, $c['y'] ?? 50, $c['z'] ?? 1 );
	}

	/** Valeurs de cadrage ramenées dans leurs bornes (x, y : 0 à 100 ; zoom : 1 à 4). */
	public static function cadrage_valide( $x, $y, $z ): array {
		return [
			max( 0, min( 100, (int) round( (float) $x ) ) ),
			max( 0, min( 100, (int) round( (float) $y ) ) ),
			max( 1.0, min( 4.0, round( (float) $z, 2 ) ) ),
		];
	}

	/** Style en ligne d'une photo d'adhérent recadrée (carte, en-tête de la fiche, aperçus). */
	public static function style_photo( $el ): string {
		[ $x, $y, $z ] = self::cadrage( $el );
		return sprintf( 'object-fit:cover;object-position:%1$d%% %2$d%%;transform:scale(%3$s);transform-origin:%1$d%% %2$d%%;', $x, $y, rtrim( rtrim( number_format( $z, 2, '.', '' ), '0' ), '.' ) );
	}

	/** Initiales affichées quand l'adhérent n'a pas de photo. */
	public static function initiales( $el ): string {
		$i = mb_substr( trim( (string) ( $el->prenom ?? '' ) ), 0, 1 ) . mb_substr( trim( (string) ( $el->nom ?? '' ) ), 0, 1 );
		return mb_strtoupper( $i );
	}

	/** Taille du nom (en unités) selon sa longueur, pour qu'il tienne sur une ligne. */
	public static function taille_nom( string $texte ): int {
		$n = mb_strlen( $texte );
		if ( $n <= 9 )  return 24;
		if ( $n <= 12 ) return 20;
		if ( $n <= 15 ) return 17;
		if ( $n <= 19 ) return 14;
		return 12;
	}

	/** Réglages du club repris sur toutes les cartes. */
	public static function contexte(): array {
		return [
			'club'     => (string) get_option( 'blogname', 'Club' ),
			'logo'     => (string) get_option( 'sp_cal_logo_url', self::LOGO_DEFAUT ) ?: self::LOGO_DEFAUT,
			'num'      => (string) get_option( 'sp_cal_club_num', '' ),
			'affil'    => (string) get_option( 'sp_cal_club_affiliation', '' ),
			'ligue'    => (string) get_option( 'sp_cal_club_ligue', '' ),
			'labelise' => (int) get_option( 'sp_cal_club_labelise', 0 ),
			'site'     => (string) preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( home_url() ) ),
		];
	}

	/** Feuille de style de la carte (une seule fois par page). */
	public static function css(): string {
		$police = esc_url( SP_CAL_PRO_URL . 'assets/fonts/east-sea-dokdo-taekwondo.woff2' );
		$u      = static function ( $n ) { return 'calc(' . $n . '*var(--u))'; };
		return "
@font-face{font-family:'SP Dokdo';src:url({$police}) format('woff2');font-display:swap;}
.spcm{--u:1px;--b:0px;position:relative;overflow:hidden;box-sizing:content-box;flex-shrink:0;width:calc(340*var(--u) + 2*var(--b));height:calc(215*var(--u) + 2*var(--b));border-radius:{$u(12)};background:#111;color:#fff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;line-height:1.2;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
.spcm *{box-sizing:border-box;margin:0;padding:0;}
.spcm--impression{--u:calc(85.6mm / 340);border-radius:0;}
.spcm--imprimeur{--b:2mm;}
.spcm-in{position:absolute;left:var(--b);top:var(--b);width:{$u(340)};height:{$u(215)};}
.spcm-bleu{position:absolute;left:0;top:0;bottom:0;width:calc(118*var(--u) + var(--b));background:#0f70b7;}
.spcm-jaune{position:absolute;top:0;bottom:0;left:calc(118*var(--u) + var(--b));width:{$u(4)};background:#ffdd0e;}
.spcm-rouge{position:absolute;right:0;bottom:0;left:calc(122*var(--u) + var(--b));height:calc(5*var(--u) + var(--b));background:#e30613;}
.spcm-photo{position:absolute;left:{$u(13)};top:{$u(14)};width:{$u(self::PHOTO_L)};height:{$u(self::PHOTO_H)};border-radius:{$u(6)};overflow:hidden;border:{$u(2)} solid #fff;background:#1f2937;display:flex;align-items:center;justify-content:center;}
.spcm-photo img{width:100%;height:100%;display:block;}
.spcm-initiales{font-size:{$u(34)};font-weight:600;color:rgba(255,255,255,.85);letter-spacing:{$u(1)};}
.spcm-saison{position:absolute;left:{$u(4)};top:{$u(140)};width:{$u(110)};text-align:center;font-size:{$u(10)};font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;}
.spcm-discipline{position:absolute;left:{$u(4)};top:{$u(155)};width:{$u(110)};text-align:center;font-size:{$u(8.5)};color:#ffdd0e;line-height:1.15;}
.spcm-titre{position:absolute;left:{$u(136)};top:{$u(15)};font-size:{$u(9)};letter-spacing:{$u(1.6)};color:#ffdd0e;font-weight:600;}
.spcm-logo{position:absolute;right:{$u(12)};top:{$u(10)};width:{$u(30)};height:{$u(30)};border-radius:{$u(4)};background:#fff;display:flex;align-items:center;justify-content:center;}
.spcm-logo img{width:{$u(27)};height:{$u(27)};object-fit:contain;}
.spcm-prenom,.spcm-nom{position:absolute;left:{$u(136)};right:{$u(12)};font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.1;}
.spcm-prenom{top:{$u(50)};}
.spcm-nom{top:{$u(77)};}
.spcm-club{position:absolute;left:{$u(136)};right:{$u(84)};top:{$u(110)};font-size:{$u(8.5)};letter-spacing:{$u(1)};color:rgba(255,255,255,.8);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.spcm-meta{position:absolute;left:{$u(136)};right:{$u(84)};top:{$u(123)};font-size:{$u(7)};color:rgba(255,255,255,.6);line-height:1.3;}
.spcm-etoiles{position:absolute;left:{$u(136)};bottom:{$u(34)};font-size:{$u(9)};letter-spacing:{$u(1)};white-space:nowrap;}
.spcm-scan{position:absolute;left:{$u(136)};bottom:{$u(18)};font-size:{$u(7.5)};color:rgba(255,255,255,.7);}
.spcm-qr{position:absolute;right:{$u(12)};bottom:{$u(16)};width:{$u(66)};height:{$u(66)};background:#fff;border-radius:{$u(5)};padding:{$u(3)};display:flex;align-items:center;justify-content:center;overflow:hidden;}
.spcm-qr img,.spcm-qr canvas{width:100% !important;height:100% !important;display:block;}
.spcm-qr > div{width:100%;height:100%;}
.spcm-v-bleu{position:absolute;left:0;right:0;top:0;height:calc(5*var(--u) + var(--b));background:#0f70b7;}
.spcm-v-jaune{position:absolute;left:0;right:0;top:calc(5*var(--u) + var(--b));height:{$u(3)};background:#ffdd0e;}
.spcm-v-rouge{position:absolute;left:0;right:0;bottom:0;height:calc(5*var(--u) + var(--b));background:#e30613;}
.spcm-v-tkd{position:absolute;right:{$u(12)};top:{$u(70)};font-family:'SP Dokdo','Nanum Brush Script',cursive;font-size:{$u(78)};line-height:1;color:rgba(255,255,255,.2);user-select:none;white-space:nowrap;}
.spcm--impression .spcm-v-tkd{color:rgba(255,255,255,.34);}
.spcm-v-etiquette{position:absolute;left:{$u(16)};top:{$u(24)};font-size:{$u(8.5)};letter-spacing:{$u(1.4)};color:#ffdd0e;font-weight:600;}
.spcm-v-tel{position:absolute;left:{$u(16)};top:{$u(38)};font-size:{$u(18)};font-weight:600;letter-spacing:{$u(.5)};}
.spcm-v-contact{position:absolute;left:{$u(16)};top:{$u(62)};right:{$u(120)};font-size:{$u(8.5)};color:rgba(255,255,255,.75);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.spcm-v-porteur{position:absolute;right:{$u(14)};top:{$u(20)};max-width:{$u(150)};background:#ffdd0e;color:#111;border-radius:{$u(8)};padding:{$u(2)} {$u(8)};font-size:{$u(8)};font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.spcm-v-infos{position:absolute;left:{$u(16)};top:{$u(90)};display:flex;flex-direction:column;gap:{$u(5)};}
.spcm-v-ligne{display:flex;gap:{$u(8)};align-items:baseline;font-size:{$u(8.5)};}
.spcm-v-ligne span{width:{$u(52)};flex-shrink:0;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:{$u(.5)};font-size:{$u(7)};}
.spcm-v-ligne b{font-weight:600;color:#fff;}
.spcm-v-pied{position:absolute;left:{$u(16)};right:{$u(16)};bottom:{$u(13)};font-size:{$u(7.5)};color:rgba(255,255,255,.65);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.spcm-coupe{position:absolute;background:rgba(255,255,255,.6);z-index:5;}
.spcm-coupe.h{width:1.5mm;height:.4pt;}
.spcm-coupe.v{width:.4pt;height:1.5mm;}
.spcm-coupe.hg.h{top:2mm;left:0;}.spcm-coupe.hg.v{top:0;left:2mm;}
.spcm-coupe.hd.h{top:2mm;right:0;}.spcm-coupe.hd.v{top:0;right:2mm;}
.spcm-coupe.bg.h{bottom:2mm;left:0;}.spcm-coupe.bg.v{bottom:0;left:2mm;}
.spcm-coupe.bd.h{bottom:2mm;right:0;}.spcm-coupe.bd.v{bottom:0;right:2mm;}
";
	}

	/** Traits de coupe du mode imprimeur (à 2 mm des bords, dans le débord). */
	private static function traits_de_coupe(): string {
		$h = '';
		foreach ( [ 'hg', 'hd', 'bg', 'bd' ] as $coin ) $h .= '<span class="spcm-coupe h ' . $coin . '"></span><span class="spcm-coupe v ' . $coin . '"></span>';
		return $h;
	}

	/**
	 * Recto et verso d'une carte.
	 *
	 * @param object $el Adhérent.
	 * @param array  $o  qr (HTML du QR code : image, ou conteneur rempli par un script), discipline
	 *                   (libellé), impression (bool : dimensions en mm), imprimeur (bool : débord
	 *                   2 mm et traits de coupe), id (suffixe des identifiants HTML).
	 * @return array{recto:string,verso:string}
	 */
	public static function html( $el, array $o = [] ): array {
		$c        = self::contexte();
		$classes  = 'spcm' . ( ! empty( $o['impression'] ) || ! empty( $o['imprimeur'] ) ? ' spcm--impression' : '' ) . ( ! empty( $o['imprimeur'] ) ? ' spcm--imprimeur' : '' );
		$coupe    = ! empty( $o['imprimeur'] ) ? self::traits_de_coupe() : '';
		$id       = isset( $o['id'] ) ? ' id="%s-' . esc_attr( (string) $o['id'] ) . '"' : '';
		$prenom   = trim( (string) ( $el->prenom ?? '' ) );
		$nom      = mb_strtoupper( trim( (string) ( $el->nom ?? '' ) ) );
		$taille   = min( self::taille_nom( $prenom ), self::taille_nom( $nom ) );
		$club     = mb_strtoupper( $c['club'] );
		$meta     = array_filter( [ $c['affil'] ? 'FFTDA ' . $c['affil'] : '', $c['ligue'], $c['num'] ? 'Club ' . $c['num'] : '' ] );
		$etoiles  = '';
		if ( $c['labelise'] > 0 ) {
			for ( $i = 1; $i <= 5; $i++ ) $etoiles .= '<span style="color:' . ( $i <= $c['labelise'] ? '#ffdd0e' : 'rgba(255,255,255,.25)' ) . ';">★</span>';
		}

		// ── Recto ──
		$r  = '<div class="' . $classes . ' spcm-recto"' . sprintf( $id, 'sp-carte-recto' ) . '>' . $coupe;
		$r .= '<div class="spcm-bleu"></div><div class="spcm-jaune"></div><div class="spcm-rouge"></div><div class="spcm-in">';
		$r .= '<div class="spcm-photo">' . ( ! empty( $el->photo_url )
			? '<img src="' . esc_url( $el->photo_url ) . '" alt="" style="' . esc_attr( self::style_photo( $el ) ) . '">'
			: '<span class="spcm-initiales">' . esc_html( self::initiales( $el ) ) . '</span>' ) . '</div>';
		if ( ! empty( $el->saison ) ) $r .= '<div class="spcm-saison">Saison ' . esc_html( $el->saison ) . '</div>';
		if ( ! empty( $o['discipline'] ) ) $r .= '<div class="spcm-discipline">' . esc_html( $o['discipline'] ) . '</div>';
		$r .= '<div class="spcm-titre">CARTE DE MEMBRE</div>';
		$r .= '<div class="spcm-logo"><img src="' . esc_url( $c['logo'] ) . '" alt="" onerror="this.style.display=\'none\'"></div>';
		$r .= '<div class="spcm-prenom" style="font-size:calc(' . $taille . '*var(--u));">' . esc_html( $prenom ) . '</div>';
		$r .= '<div class="spcm-nom" style="font-size:calc(' . $taille . '*var(--u));">' . esc_html( $nom ) . '</div>';
		$r .= '<div class="spcm-club">' . esc_html( $club ) . '</div>';
		if ( $meta ) $r .= '<div class="spcm-meta">' . esc_html( implode( ' · ', $meta ) ) . '</div>';
		if ( $etoiles !== '' ) $r .= '<div class="spcm-etoiles">' . $etoiles . '</div>';
		$r .= '<div class="spcm-scan">Scanner pour pointer</div>';
		$r .= '<div class="spcm-qr">' . ( $o['qr'] ?? '' ) . '</div>';
		$r .= '</div></div>';

		// ── Verso ──
		$contact = trim( (string) ( $el->urgence_prenom ?? '' ) . ' ' . mb_strtoupper( (string) ( $el->urgence_nom ?? '' ) ) );
		$lignes  = [];
		if ( ! empty( $el->date_naissance ) && ! empty( $el->annee_naissance ) ) $lignes['Né(e) le'] = $el->date_naissance . '/' . $el->annee_naissance;
		if ( ! empty( $el->licence ) )       $lignes['Licence']   = $el->licence;
		if ( ! empty( $el->num_passeport ) ) $lignes['Passeport'] = $el->num_passeport;

		$v  = '<div class="' . $classes . ' spcm-verso"' . sprintf( $id, 'sp-carte-verso' ) . '>' . $coupe;
		$v .= '<div class="spcm-v-bleu"></div><div class="spcm-v-jaune"></div><div class="spcm-v-rouge"></div><div class="spcm-in">';
		$v .= '<div class="spcm-v-tkd" aria-hidden="true">태권도</div>';
		$v .= '<div class="spcm-v-porteur">' . esc_html( trim( $prenom . ' ' . $nom ) ) . '</div>';
		if ( ! empty( $el->urgence_telephone ) ) {
			$v .= '<div class="spcm-v-etiquette">EN CAS D\'URGENCE</div>';
			$v .= '<div class="spcm-v-tel">' . esc_html( $el->urgence_telephone ) . '</div>';
			if ( $contact !== '' ) $v .= '<div class="spcm-v-contact">Contact : ' . esc_html( $contact ) . '</div>';
		}
		if ( $lignes ) {
			$v .= '<div class="spcm-v-infos">';
			foreach ( $lignes as $k => $val ) $v .= '<div class="spcm-v-ligne"><span>' . esc_html( $k ) . '</span><b>' . esc_html( $val ) . '</b></div>';
			$v .= '</div>';
		}
		$pied = array_filter( [ $club, $c['affil'], $c['num'] ? 'Club ' . $c['num'] : '', $c['site'] ] );
		$v   .= '<div class="spcm-v-pied">' . esc_html( implode( ' · ', $pied ) ) . ' — carte personnelle</div>';
		$v   .= '</div></div>';

		return [ 'recto' => $r, 'verso' => $v ];
	}
}

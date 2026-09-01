<?php
/**
 * Shortcode front-end : [sp_inscription_adhesion]
 * Formulaire de pré-inscription / demande d'adhésion
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Front_Adhesion {

	private static ?self $instance = null;
	private string $table;

	private const DISCIPLINES = [ 'TKD', 'RENFO' ];

	public static function get_instance(): self {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'sp_adhesions_pending';
		add_shortcode( 'sp_inscription_adhesion', [ $this, 'render_shortcode' ] );
	}

	// ─── Shortcode ────────────────────────────────────────────────────────────
	public function render_shortcode( array $atts = [] ): string {
		wp_enqueue_style( 'sp-adhesion-front', SP_CAL_PRO_URL . 'assets/css/adhesion-front.css', [], SP_CAL_PRO_VERSION );

		$result = null;
		if ( isset( $_POST['sp_adhesion_submit'] ) && check_admin_referer( 'sp_adhesion_form', 'sp_adhesion_nonce' ) ) {
			$result = $this->handle_submission();
		}

		ob_start();
		if ( $result && $result['success'] ) {
			$this->render_success( $result );
		} else {
			$this->render_form( $result );
		}
		return ob_get_clean();
	}

	// ─── Traitement soumission ────────────────────────────────────────────────
	private function handle_submission(): array {
		global $wpdb;

		// ── Sanitisation ──
		$nom       = sanitize_text_field( $_POST['nom']            ?? '' );
		$prenom    = sanitize_text_field( $_POST['prenom']         ?? '' );
		$ddn       = sanitize_text_field( $_POST['date_naissance'] ?? '' );
		$sexe      = in_array( $_POST['sexe'] ?? '', [ 'M', 'F' ], true ) ? $_POST['sexe'] : '';
		$email     = sanitize_email( $_POST['email']               ?? '' );
		$telephone = sanitize_text_field( $_POST['telephone']      ?? '' );

		$lieu_naissance = sanitize_text_field( $_POST['lieu_naissance'] ?? '' );
		$nationalite    = sanitize_text_field( $_POST['nationalite']    ?? '' );
		$adresse        = sanitize_text_field( $_POST['adresse']        ?? '' );

		$discipline = sanitize_text_field( $_POST['discipline'] ?? '' );
		$categorie  = sanitize_text_field( $_POST['categorie']  ?? '' );
		$message    = sanitize_textarea_field( $_POST['message'] ?? '' );

		// Représentant légal
		$repres_statut    = sanitize_text_field( $_POST['repres_statut']    ?? '' );
		$repres_nom       = sanitize_text_field( $_POST['repres_nom']       ?? '' );
		$repres_telephone = sanitize_text_field( $_POST['repres_telephone'] ?? '' );
		$repres_email     = sanitize_text_field( $_POST['repres_email']     ?? '' );

		// Contact urgence
		$urg_nom       = sanitize_text_field( $_POST['urg_nom']       ?? '' );
		$urg_telephone = sanitize_text_field( $_POST['urg_telephone'] ?? '' );
		$urg_email     = sanitize_text_field( $_POST['urg_email']     ?? '' );

		// Pratique antérieure
		$pratique_anterieure = isset( $_POST['pratique_anterieure'] ) ? 1 : 0;
		$ancien_licence      = sanitize_text_field( $_POST['ancien_licence']   ?? '' );
		$ancien_passeport    = sanitize_text_field( $_POST['ancien_passeport'] ?? '' );
		$ancien_grade        = sanitize_text_field( $_POST['ancien_grade']     ?? '' );

		// Mensurations
		$taille_cm      = sanitize_text_field( $_POST['taille_cm']      ?? '' );
		$poids_kg       = sanitize_text_field( $_POST['poids_kg']       ?? '' );
		$pointure       = sanitize_text_field( $_POST['pointure']       ?? '' );
		$taille_tshirt  = sanitize_text_field( $_POST['taille_tshirt']  ?? '' );
		$taille_pantalon= sanitize_text_field( $_POST['taille_pantalon']?? '' );

		// Légal
		$autorisation_photo = isset( $_POST['autorisation_photo'] ) ? 1 : 0;
		$autorisation_seul  = isset( $_POST['autorisation_seul'] )  ? 1 : 0;
		$droit_image        = isset( $_POST['droit_image'] )        ? 1 : 0;
		$reglement_accepte  = isset( $_POST['reglement_accepte'] )  ? 1 : 0;

		// ── Validation ──
		$errors = [];

		if ( $nom    === '' ) $errors[] = 'Le nom est requis.';
		if ( $prenom === '' ) $errors[] = 'Le prénom est requis.';

		if ( $ddn === '' || ! $this->valid_date( $ddn ) ) {
			$errors[] = 'La date de naissance est requise (format JJ/MM/AAAA).';
		} else {
			$ts = strtotime( $ddn );
			if ( $ts > time() || $ts < strtotime( '1900-01-01' ) ) {
				$errors[] = 'La date de naissance n\'est pas réaliste.';
			}
		}

		if ( $sexe      === '' ) $errors[] = 'Le sexe est requis.';
		if ( ! is_email( $email ) ) $errors[] = 'Un email valide est requis.';
		if ( $telephone === '' ) $errors[] = 'Le téléphone est requis.';
		if ( ! in_array( $discipline, self::DISCIPLINES, true ) ) $errors[] = 'Discipline invalide.';
		if ( $categorie === '' ) $errors[] = 'La catégorie n\'a pas pu être calculée. Vérifiez la date de naissance et la discipline.';
		if ( $urg_nom          === '' ) $errors[] = 'Le nom du contact d\'urgence est requis.';
		if ( $urg_telephone    === '' ) $errors[] = 'Le téléphone du contact d\'urgence est requis.';

		// Représentant légal obligatoire uniquement pour les mineurs
		$est_mineur_form = false;
		if ( $ddn !== '' && $this->valid_date( $ddn ) ) {
			$birth = new \DateTime( $ddn );
			$today = new \DateTime();
			$est_mineur_form = $today->diff( $birth )->y < 18;
		}
		if ( $est_mineur_form ) {
			if ( $repres_nom       === '' ) $errors[] = 'Le nom du représentant légal est requis (adhérent mineur).';
			if ( $repres_telephone === '' ) $errors[] = 'Le téléphone du représentant légal est requis (adhérent mineur).';
		}
		if ( ! $reglement_accepte ) $errors[] = 'Vous devez accepter le règlement intérieur.';

		// Anti-doublon
		if ( empty( $errors ) ) {
			$already = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE email = %s AND statut = 'pending' LIMIT 1",
				$email
			) );
			if ( $already ) {
				$errors[] = 'Une demande avec cet email est déjà en cours de traitement. Contactez-nous si besoin.';
			}
		}

		if ( ! empty( $errors ) ) {
			return [ 'success' => false, 'errors' => $errors, 'data' => $_POST ];
		}

		// ── Insertion ──
		$ok = $wpdb->insert( $this->table, [
			'nom'                 => $nom,
			'prenom'              => $prenom,
			'date_naissance'      => $ddn,
			'sexe'                => $sexe,
			'email'               => $email,
			'telephone'           => $telephone,
			'lieu_naissance'      => $lieu_naissance,
			'nationalite'         => $nationalite,
			'adresse'             => $adresse,
			'discipline'          => $discipline,
			'categorie'           => $categorie,
			'message'             => $message,
			'repres_statut'       => $repres_statut,
			'repres_nom'          => $repres_nom,
			'repres_telephone'    => $repres_telephone,
			'repres_email'        => $repres_email,
			'urg_nom'             => $urg_nom,
			'urg_telephone'       => $urg_telephone,
			'urg_email'           => $urg_email,
			'pratique_anterieure' => $pratique_anterieure,
			'ancien_licence'      => $ancien_licence,
			'ancien_passeport'    => $ancien_passeport,
			'ancien_grade'        => $ancien_grade,
			'taille_cm'           => $taille_cm,
			'poids_kg'            => $poids_kg,
			'pointure'            => $pointure,
			'taille_tshirt'       => $taille_tshirt,
			'taille_pantalon'     => $taille_pantalon,
			'autorisation_photo'  => $autorisation_photo,
			'autorisation_seul'   => $autorisation_seul,
			'droit_image'         => $droit_image,
			'reglement_accepte'   => $reglement_accepte,
			'statut'              => 'pending',
			'ip_address'          => $_SERVER['REMOTE_ADDR'] ?? '',
			'created_at'          => current_time( 'mysql' ),
		], [
			'%s','%s','%s','%s','%s','%s',
			'%s','%s','%s','%s','%s','%s',
			'%s','%s','%s','%s',
			'%s','%s','%s',
			'%d','%s','%s','%s',
			'%s','%s','%s','%s','%s',
			'%d','%d','%d','%d',
			'%s','%s','%s',
		] );

		if ( ! $ok ) {
			return [ 'success' => false, 'errors' => [ 'Une erreur technique est survenue. Veuillez réessayer.' ] ];
		}

		$new_id = $wpdb->insert_id;
		$this->email_admin_notification( $new_id, $nom, $prenom, $email, $categorie, $discipline );
		$this->email_confirmation_adherent( $email, $prenom, $nom );

		return [ 'success' => true, 'prenom' => $prenom, 'email' => $email ];
	}

	// ─── Emails ──────────────────────────────────────────────────────────────
	private function email_admin_notification( int $id, string $nom, string $prenom, string $email, string $cat, string $disc ): void {
		$admin_email = get_option( 'admin_email' );
		$club        = get_bloginfo( 'name' );
		$url         = admin_url( "admin.php?page=sp_adhesions&action=view&id={$id}" );
		$subject     = "[{$club}] Nouvelle demande d'adhésion — {$prenom} {$nom}";
		$body        = "Bonjour,\n\nNouvelle demande d'adhésion reçue.\n\n"
		             . "Adhérent : {$prenom} {$nom}\nEmail : {$email}\n"
		             . "Catégorie : {$cat} — {$disc}\n\nVoir la demande :\n{$url}\n\n— SP Build";
		wp_mail( $admin_email, $subject, $body );
	}

	private function email_confirmation_adherent( string $email, string $prenom, string $nom ): void {
		$club    = get_bloginfo( 'name' );
		$subject = "[{$club}] Votre demande d'adhésion a bien été reçue";
		$body    = "Bonjour {$prenom},\n\n"
		         . "Nous avons bien reçu votre demande d'adhésion au club {$club}.\n\n"
		         . "Votre demande est en cours d'examen. Si elle est acceptée, votre compte sera activé "
		         . "par l'administrateur très prochainement et vous recevrez un email avec vos informations d'accès.\n\n"
		         . "En attendant, n'hésitez pas à nous contacter pour toute question.\n\n"
		         . "À bientôt sur les tatamis !\n— L'équipe {$club}";
		wp_mail( $email, $subject, $body );
	}

	// ─── Succès ───────────────────────────────────────────────────────────────
	private function render_success( array $r ): void { ?>
		<div class="sp-adh-success">
			<div class="sp-adh-success-icon">✅</div>
			<h2>Demande envoyée !</h2>
			<p>Bonjour <strong><?= esc_html( $r['prenom'] ) ?></strong>,</p>
			<p>Votre demande a bien été enregistrée. Un email de confirmation a été envoyé à <strong><?= esc_html( $r['email'] ) ?></strong>.</p>
			<p>Notre équipe vous contactera prochainement pour finaliser votre inscription.</p>
		</div>
	<?php }

	// ─── Formulaire ───────────────────────────────────────────────────────────
	private function render_form( ?array $result ): void {
		$errors = $result['errors'] ?? [];
		$data   = $result['data']   ?? [];
		$v = static fn( string $k ): string => esc_attr( $data[ $k ] ?? '' );
		?>
		<div class="sp-adh-wrapper">
			<h2 class="sp-adh-title">Demande d'adhésion</h2>
			<p class="sp-adh-intro">Remplissez ce formulaire pour rejoindre notre club. Notre équipe vous contactera pour finaliser votre inscription et le règlement de la cotisation.</p>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="sp-adh-errors" role="alert">
					<strong>⚠️ Veuillez corriger les erreurs suivantes :</strong>
					<ul><?php foreach ( $errors as $e ) echo '<li>' . esc_html( $e ) . '</li>'; ?></ul>
				</div>
			<?php endif; ?>

			<form method="post" class="sp-adh-form" novalidate lang="fr">
				<?php wp_nonce_field( 'sp_adhesion_form', 'sp_adhesion_nonce' ); ?>
				<input type="hidden" name="categorie" id="sp_categorie_hidden" value="<?= $v('categorie') ?>">

				<!-- ══ IDENTITÉ ══════════════════════════════════════════════ -->
				<div class="sp-adh-group">
					<h3 class="sp-adh-group-title">👤 Identité de l'adhérent</h3>
					<div class="sp-adh-row">
						<div class="sp-adh-col">
							<label for="sp_nom">Nom <span class="sp-req">*</span></label>
							<input type="text" id="sp_nom" name="nom" value="<?= $v('nom') ?>" autocomplete="family-name" required>
						</div>
						<div class="sp-adh-col">
							<label for="sp_prenom">Prénom <span class="sp-req">*</span></label>
							<input type="text" id="sp_prenom" name="prenom" value="<?= $v('prenom') ?>" autocomplete="given-name" required>
						</div>
					</div>
					<div class="sp-adh-row">
						<div class="sp-adh-col">
							<label for="sp_ddn">
								Date de naissance <span class="sp-req">*</span>
								<span class="sp-optional"> (JJ/MM/AAAA)</span>
							</label>
							<input type="date" id="sp_ddn" name="date_naissance"
							       value="<?= $v('date_naissance') ?>"
							       max="<?= esc_attr( date('Y-m-d') ) ?>"
							       min="1900-01-01" required>
						</div>
						<div class="sp-adh-col">
							<div class="sp-adh-inline-fieldset">
								<span class="sp-adh-inline-label">Sexe <span class="sp-req">*</span></span>
								<div class="sp-adh-radio-group">
									<label class="sp-adh-radio">
										<input type="radio" name="sexe" value="M" <?= ($data['sexe']??'')==='M'?'checked':'' ?> required> Masculin
									</label>
									<label class="sp-adh-radio">
										<input type="radio" name="sexe" value="F" <?= ($data['sexe']??'')==='F'?'checked':'' ?>> Féminin
									</label>
								</div>
							</div>
						</div>
					</div>
					<div class="sp-adh-row">
						<div class="sp-adh-col">
							<label for="sp_lieu_naissance">Lieu de naissance</label>
							<input type="text" id="sp_lieu_naissance" name="lieu_naissance" value="<?= $v('lieu_naissance') ?>">
						</div>
						<div class="sp-adh-col">
							<label for="sp_nationalite">Nationalité</label>
							<input type="text" id="sp_nationalite" name="nationalite" value="<?= $v('nationalite') ?>">
						</div>
					</div>
					<div class="sp-adh-row">
						<div class="sp-adh-col sp-adh-col-full">
							<label for="sp_adresse">Adresse</label>
							<input type="text" id="sp_adresse" name="adresse" value="<?= $v('adresse') ?>" autocomplete="street-address">
						</div>
					</div>
				</div>

				<!-- ══ CONTACT ADHÉRENT ══════════════════════════════════════ -->
				<div class="sp-adh-group">
					<h3 class="sp-adh-group-title">📞 Contact de l'adhérent</h3>
					<div class="sp-adh-row">
						<div class="sp-adh-col">
							<label for="sp_email">Email <span class="sp-req">*</span></label>
							<input type="email" id="sp_email" name="email" value="<?= $v('email') ?>" autocomplete="email" required>
						</div>
						<div class="sp-adh-col">
							<label for="sp_tel">Téléphone <span class="sp-req">*</span></label>
							<input type="tel" id="sp_tel" name="telephone" value="<?= $v('telephone') ?>" autocomplete="tel" required>
						</div>
					</div>
				</div>

				<!-- ══ REPRÉSENTANT LÉGAL ════════════════════════════════════ -->
				<div class="sp-adh-group" id="sp_repres_bloc">
					<h3 class="sp-adh-group-title">
						👨‍👩‍👧 Représentant(s) légal/légaux
						<span id="sp_repres_mineur_badge" style="display:none;background:#dc2626;color:#fff;font-size:.75rem;padding:1px 8px;border-radius:10px;font-weight:600;margin-left:8px;vertical-align:middle;">Mineur</span>
					</h3>
					<p class="sp-adh-group-desc">Personne(s) ayant autorité parentale — utilisé pour les communications officielles et autorisations.</p>
					<div class="sp-adh-row">
						<div class="sp-adh-col">
							<label for="sp_repres_statut">Statut <span class="sp-req sp-repres-req">*</span></label>
							<input type="text" id="sp_repres_statut" name="repres_statut"
							       value="<?= $v('repres_statut') ?>"
							       placeholder="Ex : Père et mère, Tuteur légal, Famille d'accueil…">
						</div>
					</div>
					<div class="sp-adh-row">
						<div class="sp-adh-col">
							<label for="sp_repres_nom">Nom(s) complet(s) <span class="sp-req sp-repres-req">*</span></label>
							<input type="text" id="sp_repres_nom" name="repres_nom"
							       value="<?= $v('repres_nom') ?>"
							       placeholder="Ex : Martin Jean et Martin Sophie">
						</div>
					</div>
					<div class="sp-adh-row">
						<div class="sp-adh-col">
							<label for="sp_repres_telephone">Téléphone(s) <span class="sp-req sp-repres-req">*</span></label>
							<input type="text" id="sp_repres_telephone" name="repres_telephone"
							       value="<?= $v('repres_telephone') ?>"
							       placeholder="Ex : 06 12 34 56 78 / 07 98 76 54 32">
						</div>
						<div class="sp-adh-col">
							<label for="sp_repres_email">Email(s)</label>
							<input type="text" id="sp_repres_email" name="repres_email"
							       value="<?= $v('repres_email') ?>"
							       placeholder="Ex : parent1@mail.fr, parent2@mail.fr">
						</div>
					</div>
				</div>

				<!-- ══ CONTACT URGENCE ═══════════════════════════════════════ -->
				<div class="sp-adh-group">
					<h3 class="sp-adh-group-title">🚨 Contact d'urgence</h3>
					<p class="sp-adh-group-desc">Personne à contacter immédiatement en cas d'incident — peut être différente du représentant légal.</p>
					<div class="sp-adh-row">
						<div class="sp-adh-col">
							<label for="sp_urg_nom">Nom(s) <span class="sp-req">*</span></label>
							<input type="text" id="sp_urg_nom" name="urg_nom"
							       value="<?= $v('urg_nom') ?>"
							       placeholder="Ex : Dupont Jean, ou grand-mère Marie…" required>
						</div>
					</div>
					<div class="sp-adh-row">
						<div class="sp-adh-col">
							<label for="sp_urg_tel">Téléphone(s) <span class="sp-req">*</span></label>
							<input type="text" id="sp_urg_tel" name="urg_telephone"
							       value="<?= $v('urg_telephone') ?>"
							       placeholder="Ex : 06 11 22 33 44 / 07 55 66 77 88" required>
						</div>
						<div class="sp-adh-col">
							<label for="sp_urg_email">Email(s)</label>
							<input type="text" id="sp_urg_email" name="urg_email"
							       value="<?= $v('urg_email') ?>"
							       placeholder="Ex : urgence@mail.fr">
						</div>
					</div>
				</div>

				<!-- ══ CLUB ══════════════════════════════════════════════════ -->
				<div class="sp-adh-group">
					<h3 class="sp-adh-group-title">🥋 Pratique au club</h3>
					<div class="sp-adh-row">
						<div class="sp-adh-col">
							<label for="sp_disc">Discipline souhaitée <span class="sp-req">*</span></label>
							<select id="sp_disc" name="discipline" required>
								<option value="">— Choisir —</option>
								<option value="TKD"   <?= ($data['discipline']??'')==='TKD'  ?'selected':'' ?>>Taekwondo</option>
								<option value="RENFO" <?= ($data['discipline']??'')==='RENFO'?'selected':'' ?>>Renforcement musculaire</option>
							</select>
						</div>
						<div class="sp-adh-col">
							<label>Catégorie d'âge <span class="sp-req">*</span></label>
							<div class="sp-adh-categorie-display" id="sp_categorie_display">
								<span class="sp-adh-categorie-placeholder">Calculée selon la date de naissance et la discipline</span>
							</div>
						</div>
					</div>
					<div class="sp-adh-row">
						<div class="sp-adh-col sp-adh-col-full">
							<label for="sp_msg">Message / Questions <span class="sp-optional">(optionnel)</span></label>
							<textarea id="sp_msg" name="message" rows="3"
							          placeholder="Informations complémentaires, questions sur les horaires, tarifs..."
							><?= esc_textarea( $data['message'] ?? '' ) ?></textarea>
						</div>
					</div>
				</div>

				<!-- ══ PRATIQUE ANTÉRIEURE ═══════════════════════════════════ -->
				<div class="sp-adh-group">
					<h3 class="sp-adh-group-title">🏅 Pratique antérieure</h3>
					<label class="sp-adh-check sp-adh-check-trigger">
						<input type="checkbox" name="pratique_anterieure" id="sp_pratique_anterieure" value="1"
						       <?= !empty($data['pratique_anterieure'])?'checked':'' ?>>
						<span>Je pratique ou ai déjà pratiqué dans un autre club</span>
					</label>
					<div class="sp-adh-conditional" id="sp_pratique_fields" style="<?= !empty($data['pratique_anterieure'])?'':'display:none' ?>">
						<div class="sp-adh-row">
							<div class="sp-adh-col">
								<label for="sp_ancien_licence">Numéro de licence <span class="sp-optional">(si vous en possédez un)</span></label>
								<input type="text" id="sp_ancien_licence" name="ancien_licence" value="<?= $v('ancien_licence') ?>">
							</div>
							<div class="sp-adh-col">
								<label for="sp_ancien_passeport">Numéro de passeport FFTDA <span class="sp-optional">(si vous en possédez un)</span></label>
								<input type="text" id="sp_ancien_passeport" name="ancien_passeport" value="<?= $v('ancien_passeport') ?>">
							</div>
						</div>
						<div class="sp-adh-row">
							<div class="sp-adh-col sp-adh-col-half">
								<label for="sp_ancien_grade">Grade actuel</label>
								<input type="text" id="sp_ancien_grade" name="ancien_grade" value="<?= $v('ancien_grade') ?>"
								       placeholder="Ex : Ceinture bleue, 1er Dan…">
							</div>
						</div>
					</div>
				</div>

				<!-- ══ MENSURATIONS ══════════════════════════════════════════ -->
				<div class="sp-adh-group">
					<h3 class="sp-adh-group-title">📏 Mensurations <span class="sp-optional" style="font-weight:400;font-size:.85rem;">(optionnel — utile pour la commande des équipements)</span></h3>
					<label class="sp-adh-check sp-adh-check-trigger">
						<input type="checkbox" id="sp_mensuration_toggle" value="1"
						       <?= ( !empty($data['taille_cm']) || !empty($data['poids_kg']) || !empty($data['pointure']) ) ? 'checked' : '' ?>>
						<span>Renseigner les mensurations maintenant</span>
					</label>
					<div class="sp-adh-conditional" id="sp_mensuration_fields"
					     style="<?= ( !empty($data['taille_cm']) || !empty($data['poids_kg']) || !empty($data['pointure']) ) ? '' : 'display:none' ?>">
						<div class="sp-adh-row">
							<div class="sp-adh-col">
								<label for="sp_taille">Taille (cm)</label>
								<input type="number" id="sp_taille" name="taille_cm" value="<?= $v('taille_cm') ?>"
								       min="50" max="250" placeholder="Ex : 165">
							</div>
							<div class="sp-adh-col">
								<label for="sp_poids">Poids (kg)</label>
								<input type="number" id="sp_poids" name="poids_kg" value="<?= $v('poids_kg') ?>"
								       min="10" max="250" placeholder="Ex : 60">
							</div>
							<div class="sp-adh-col">
								<label for="sp_pointure">Pointure</label>
								<input type="number" id="sp_pointure" name="pointure" value="<?= $v('pointure') ?>"
								       min="20" max="55" placeholder="Ex : 38">
							</div>
						</div>
						<div class="sp-adh-row">
							<div class="sp-adh-col">
								<label for="sp_tshirt">Taille t-shirt / sweat</label>
								<input type="text" id="sp_tshirt" name="taille_tshirt" value="<?= $v('taille_tshirt') ?>"
								       placeholder="Ex : M, L, XL, 12 ans…">
							</div>
							<div class="sp-adh-col">
								<label for="sp_pantalon">Taille pantalon</label>
								<input type="text" id="sp_pantalon" name="taille_pantalon" value="<?= $v('taille_pantalon') ?>"
								       placeholder="Ex : 40, 12 ans…">
							</div>
						</div>
					</div>
				</div>

				<!-- ══ LÉGAL ═════════════════════════════════════════════════ -->
				<div class="sp-adh-group sp-adh-group-legal">
					<h3 class="sp-adh-group-title">📋 Autorisations &amp; Règlement</h3>
					<label class="sp-adh-check">
						<input type="checkbox" name="autorisation_photo" value="1" <?= !empty($data['autorisation_photo'])?'checked':'' ?>>
						<span>J'autorise la prise de <strong>photos et vidéos</strong> lors des activités du club.</span>
					</label>
					<label class="sp-adh-check">
						<input type="checkbox" name="droit_image" value="1" <?= !empty($data['droit_image'])?'checked':'' ?>>
						<span>J'autorise la <strong>diffusion</strong> de ces photos et vidéos sur les supports du club (site web, réseaux sociaux).</span>
					</label>
					<label class="sp-adh-check">
						<input type="checkbox" name="autorisation_seul" value="1" <?= !empty($data['autorisation_seul'])?'checked':'' ?>>
						<span>J'autorise l'adhérent à <strong>repartir seul(e)</strong> après les entraînements.</span>
					</label>
					<label class="sp-adh-check sp-adh-check-required">
						<input type="checkbox" name="reglement_accepte" value="1" required <?= !empty($data['reglement_accepte'])?'checked':'' ?>>
						<span>J'ai lu et j'accepte le <strong>règlement intérieur</strong> du club. <span class="sp-req">*</span></span>
					</label>
				</div>

				<div class="sp-adh-footer">
					<p class="sp-adh-required-note"><span class="sp-req">*</span> Champs obligatoires</p>
					<button type="submit" name="sp_adhesion_submit" class="sp-adh-btn-submit">
						Envoyer ma demande d'adhésion →
					</button>
				</div>
			</form>
		</div>

		<script>
		(function() {
			// ── Catégorie calculée ────────────────────────────────────────────
			const ddnInput  = document.getElementById('sp_ddn');
			const discInput = document.getElementById('sp_disc');
			const hidden    = document.getElementById('sp_categorie_hidden');
			const display   = document.getElementById('sp_categorie_display');

			function calculerCategorie() {
				const ddn  = ddnInput.value;
				const disc = discInput.value;

				if ( disc === 'RENFO' ) {
					hidden.value = 'RENFO';
					display.innerHTML = '<strong class="sp-adh-cat-badge sp-adh-cat-renfo">Renforcement musculaire</strong>';
					return;
				}
				if ( ! ddn || disc !== 'TKD' ) {
					hidden.value = '';
					display.innerHTML = '<span class="sp-adh-categorie-placeholder">Calculée selon la date de naissance et la discipline</span>';
					return;
				}

				const birth = new Date(ddn);
				const today = new Date();
				const sept1 = new Date(today.getFullYear(), 8, 1);

				let age = sept1.getFullYear() - birth.getFullYear();
				if ( birth.getMonth() > 8 || (birth.getMonth() === 8 && birth.getDate() > 1) ) age--;

				let cat, label, cssClass;
				if ( age < 6 ) {
					cat = 'Baby'; label = 'Baby (moins de 6 ans au 1er sept.)'; cssClass = 'sp-adh-cat-baby';
				} else if ( age < 11 ) {
					cat = 'Enfant'; label = 'Enfant (6 à 10 ans au 1er sept.)'; cssClass = 'sp-adh-cat-enfant';
				} else {
					cat = 'Ado/Adulte'; label = 'Ado / Adulte (11 ans et plus)'; cssClass = 'sp-adh-cat-adulte';
				}
				hidden.value = cat;
				display.innerHTML = '<strong class="sp-adh-cat-badge ' + cssClass + '">' + label + '</strong>';
			}

			ddnInput.addEventListener('change', calculerCategorie);
			discInput.addEventListener('change', calculerCategorie);
			calculerCategorie();

			// ── Représentant légal : conditionnel selon majorité ──────────────
			const represBloc   = document.getElementById('sp_repres_bloc');
			const represBadge  = document.getElementById('sp_repres_mineur_badge');
			const represInputs = represBloc ? represBloc.querySelectorAll('input[id="sp_repres_statut"], input[id="sp_repres_nom"], input[id="sp_repres_telephone"]') : [];

			function majeurOuMineur() {
				const ddn = ddnInput.value;
				if ( ! represBloc ) return;

				if ( ! ddn ) {
					// DDN non saisie → bloc visible, non obligatoire
					represBloc.style.display = '';
					represBadge.style.display = 'none';
					represInputs.forEach( i => i.removeAttribute('required') );
					return;
				}

				const birth    = new Date( ddn );
				const today    = new Date();
				let age = today.getFullYear() - birth.getFullYear();
				const m = today.getMonth() - birth.getMonth();
				if ( m < 0 || ( m === 0 && today.getDate() < birth.getDate() ) ) age--;

				if ( age < 18 ) {
					// Mineur → bloc visible et obligatoire
					represBloc.style.display = '';
					represBadge.style.display = 'inline';
					represInputs.forEach( i => i.setAttribute('required', 'required') );
				} else {
					// Majeur → bloc masqué, non obligatoire
					represBloc.style.display = 'none';
					represBadge.style.display = 'none';
					represInputs.forEach( i => i.removeAttribute('required') );
				}
			}

			ddnInput.addEventListener('change', majeurOuMineur);
			majeurOuMineur(); // init si DDN déjà remplie (ex: retour après erreur)

			// ── Blocs conditionnels (pratique + mensurations) ─────────────────
			function initToggle(checkboxId, fieldsId) {
				const cb = document.getElementById(checkboxId);
				const fl = document.getElementById(fieldsId);
				if (!cb || !fl) return;
				cb.addEventListener('change', function() {
					fl.style.display = this.checked ? '' : 'none';
					if (!this.checked) fl.querySelectorAll('input').forEach(i => i.value = '');
				});
			}
			initToggle('sp_pratique_anterieure', 'sp_pratique_fields');
			initToggle('sp_mensuration_toggle',  'sp_mensuration_fields');
		})();
		</script>
		<?php
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────
	private function valid_date( string $date ): bool {
		$d = \DateTime::createFromFormat( 'Y-m-d', $date );
		return $d instanceof \DateTime && $d->format( 'Y-m-d' ) === $date;
	}

	// ─── Création de la table ─────────────────────────────────────────────────
	public static function create_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'sp_adhesions_pending';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			nom                  VARCHAR(100)  NOT NULL,
			prenom               VARCHAR(100)  NOT NULL,
			date_naissance       DATE          NOT NULL,
			sexe                 ENUM('M','F') NOT NULL,
			email                VARCHAR(200)  NOT NULL,
			telephone            VARCHAR(30)   NOT NULL DEFAULT '',
			lieu_naissance       VARCHAR(150)  NOT NULL DEFAULT '',
			nationalite          VARCHAR(100)  NOT NULL DEFAULT '',
			adresse              VARCHAR(255)  NOT NULL DEFAULT '',
			discipline           VARCHAR(50)   NOT NULL DEFAULT '',
			categorie            VARCHAR(50)   NOT NULL DEFAULT '',
			message              TEXT,
			repres_statut        VARCHAR(255)  NOT NULL DEFAULT '',
			repres_nom           VARCHAR(255)  NOT NULL DEFAULT '',
			repres_telephone     VARCHAR(100)  NOT NULL DEFAULT '',
			repres_email         VARCHAR(255)  NOT NULL DEFAULT '',
			urg_nom              VARCHAR(255)  NOT NULL DEFAULT '',
			urg_telephone        VARCHAR(100)  NOT NULL DEFAULT '',
			urg_email            VARCHAR(255)  NOT NULL DEFAULT '',
			pratique_anterieure  TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			ancien_licence       VARCHAR(50)   NOT NULL DEFAULT '',
			ancien_passeport     VARCHAR(50)   NOT NULL DEFAULT '',
			ancien_grade         VARCHAR(100)  NOT NULL DEFAULT '',
			taille_cm            VARCHAR(10)   NOT NULL DEFAULT '',
			poids_kg             VARCHAR(10)   NOT NULL DEFAULT '',
			pointure             VARCHAR(10)   NOT NULL DEFAULT '',
			taille_tshirt        VARCHAR(20)   NOT NULL DEFAULT '',
			taille_pantalon      VARCHAR(20)   NOT NULL DEFAULT '',
			autorisation_photo   TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			autorisation_seul    TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			droit_image          TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			reglement_accepte    TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			statut               ENUM('pending','valide','refuse') NOT NULL DEFAULT 'pending',
			refus_motif          TEXT,
			ip_address           VARCHAR(45)   NOT NULL DEFAULT '',
			created_at           DATETIME      NOT NULL,
			updated_at           DATETIME      DEFAULT NULL,
			PRIMARY KEY          (id),
			KEY idx_statut       (statut),
			KEY idx_email        (email(50))
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

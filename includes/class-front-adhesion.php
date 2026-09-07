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

	private const STATUTS_CONTACT = [
		'pere'               => 'Père',
		'mere'               => 'Mère',
		'conjoint'           => 'Conjoint/e',
		'famille_accueil'    => "Famille d'accueil",
		'representant_legal' => 'Représentant légal',
		'autre'              => 'Autre',
	];

	private const MAX_REPRESENTANTS = 2;

	// Colonne la plus récemment ajoutée à la table — sert de "sentinelle" pour
	// maybe_create_table() (voir plus bas). La mettre à jour à chaque nouvelle colonne.
	private const SENTINEL_COLUMN = 'photo_url';

	public static function get_instance(): self {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'sp_adhesions_pending';
		add_shortcode( 'sp_inscription_adhesion', [ $this, 'render_shortcode' ] );
		add_action( 'init', [ __CLASS__, 'maybe_create_table' ] );
		add_action( 'init', [ $this, 'maybe_print_attestation' ] );
	}

	// La création de table n'était jamais déclenchée nulle part avant le 2026-09-01
	// (voir md/04-journal-modifications.md). Un premier correctif s'appuyait sur un simple
	// numéro de version en option — mais si dbDelta() échoue silencieusement (ex: droits
	// ALTER TABLE insuffisants sur l'hébergement), l'option était quand même marquée à jour
	// et le correctif ne se relançait plus jamais, malgré des colonnes manquantes en base
	// (cause du bug "Une erreur technique est survenue" du 03/09/2026, cf. 04-journal-modifications.md).
	// Corrigé en vérifiant l'existence réelle de la dernière colonne attendue à chaque chargement,
	// au lieu de se fier à un numéro de version qui peut désynchroniser de la réalité.
	public static function maybe_create_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sp_adhesions_pending';

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SHOW COLUMNS FROM {$table} LIKE %s", self::SENTINEL_COLUMN
		) );
		if ( $exists ) return;

		self::create_table();

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SHOW COLUMNS FROM {$table} LIKE %s", self::SENTINEL_COLUMN
		) );
		if ( ! $exists ) {
			error_log(
				'[SP_Build] La colonne ' . self::SENTINEL_COLUMN . ' est absente de ' . $table . ' après dbDelta() — '
				. 'vérifier que l\'utilisateur MySQL du site a bien le droit ALTER TABLE.'
			);
		}
	}

	// ─── Shortcode ────────────────────────────────────────────────────────────
	public function render_shortcode( array $atts = [] ): string {
		wp_enqueue_style( 'sp-adhesion-front', SP_CAL_PRO_URL . 'assets/css/adhesion-front.css', [], SP_CAL_PRO_VERSION );

		// Renouvellement (cf. md/06-renouvellement-saison.md) : ?renouv=TOKEN identifie
		// une fiche élève existante à pré-remplir. Le token est le même que celui de
		// l'espace membre (SP_Token) — jamais une simple ID, toujours résolu par ce secret.
		$renouv_token = sanitize_text_field( $_POST['renouv_token'] ?? $_GET['renouv'] ?? '' );
		$renouv_eleve = $renouv_token !== '' ? $this->lookup_eleve_by_token( $renouv_token ) : null;

		$result = null;
		if ( isset( $_POST['sp_adhesion_submit'] ) && check_admin_referer( 'sp_adhesion_form', 'sp_adhesion_nonce' ) ) {
			$result = $this->handle_submission( $renouv_eleve );
		}

		ob_start();
		if ( $result && $result['success'] ) {
			$this->render_success( $result );
		} else {
			$this->render_form( $result, $renouv_eleve );
		}
		return ob_get_clean();
	}

	// Modèle imprimable de l'attestation sur l'honneur (Renforcement musculaire, cf. doléances.md).
	// Public (pas de current_user_can) : accessible depuis le formulaire d'adhésion avant même
	// que le visiteur soit identifié. Nom/prénom pré-remplis si déjà saisis dans le formulaire,
	// sinon ligne vierge à compléter à la main — pas de dépendance à une lib PDF (aucune dispo
	// sur cet hébergement), impression navigateur comme pour les autres documents du plugin
	// (cf. includes/class-pdf.php).
	public function maybe_print_attestation(): void {
		if ( ( $_GET['sp_adh_print'] ?? '' ) !== 'attestation_renfo' ) return;

		$nom    = sanitize_text_field( wp_unslash( $_GET['nom']    ?? '' ) );
		$prenom = sanitize_text_field( wp_unslash( $_GET['prenom'] ?? '' ) );
		$nom_complet = trim( $prenom . ' ' . $nom );
		$club = get_option( 'blogname', 'Club' );

		while ( ob_get_level() ) ob_end_clean();
		header( 'Content-Type: text/html; charset=UTF-8' );
		?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Attestation sur l'honneur — <?= esc_html( $club ) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 12pt; color: #111; background: #fff; padding: 20mm; max-width: 210mm; margin: 0 auto; }
h1 { font-size: 14pt; color: #1e3a5f; text-align: center; margin-bottom: 24px; text-transform: uppercase; }
.attestation-corps { line-height: 1.9; text-align: justify; }
.attestation-blanc { display: inline-block; min-width: 220px; border-bottom: 1px solid #111; }
.attestation-signature { margin-top: 60px; display: flex; justify-content: space-between; }
.attestation-signature div { width: 45%; }
.attestation-signature .ligne { margin-top: 40px; border-top: 1px solid #999; padding-top: 4px; font-size: 9pt; color: #666; }
.no-print { margin-bottom: 24px; }
.btn-print { background: #1e3a5f; color: #fff; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; font-size: 11pt; margin-right: 8px; }
.btn-close { background: #eee; color: #333; border: 1px solid #ccc; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-size: 11pt; }
@media print { .no-print { display: none !important; } body { padding: 10mm; } }
</style>
</head>
<body>
<div class="no-print">
	<button class="btn-print" onclick="window.print()">🖨️ Imprimer / Enregistrer en PDF</button>
	<button class="btn-close" onclick="window.close()">✕ Fermer</button>
</div>

<h1>Attestation sur l'honneur</h1>

<div class="attestation-corps">
	<p>
		Je soussigné(e)
		<?php if ( $nom_complet !== '' ) : ?>
			<strong><?= esc_html( mb_strtoupper( $nom_complet ) ) ?></strong>
		<?php else : ?>
			<span class="attestation-blanc">&nbsp;</span>
		<?php endif; ?>
		atteste sur l'honneur être en bonne condition physique et être apte à pratiquer les activités
		de renforcement musculaire et de self-défense proposées par le club <?= esc_html( $club ) ?>.
	</p>
	<p style="margin-top:16px;">
		Je déclare ne présenter, à ma connaissance, aucune contre-indication médicale à la pratique
		de ces activités et m'engage à informer le club de toute évolution de mon état de santé
		pouvant avoir une incidence sur ma pratique.
	</p>
</div>

<div class="attestation-signature">
	<div><div class="ligne">Date</div></div>
	<div><div class="ligne">Signature</div></div>
</div>

</body>
</html>
		<?php
		exit;
	}

	// ─── Renouvellement : lookup + pré-remplissage ────────────────────────────
	private function lookup_eleve_by_token( string $token ): ?object {
		global $wpdb;
		if ( $token === '' ) return null;
		$tel = $wpdb->prefix . 'sp_cal_eleves';
		$el  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tel} WHERE token = %s LIMIT 1", $token ) );
		return $el ?: null;
	}

	private function to_iso_date( ?string $d ): string {
		$d = trim( (string) $d );
		if ( $d === '' ) return '';
		foreach ( [ 'Y-m-d', 'd/m/Y', 'd-m-Y' ] as $fmt ) {
			$dt = \DateTime::createFromFormat( $fmt, $d );
			if ( $dt instanceof \DateTime && $dt->format( $fmt ) === $d ) return $dt->format( 'Y-m-d' );
		}
		return '';
	}

	/** Reconstruit un tableau "façon $_POST" depuis une fiche sp_cal_eleves, pour pré-remplir le formulaire. */
	private function build_prefill_from_eleve( object $el ): array {
		$extra = json_decode( $el->extra_data ?? '', true );
		$extra = is_array( $extra ) ? $extra : [];

		$representants = $extra['representants_legaux'] ?? [];
		if ( empty( $representants ) && ( ! empty( $el->representant_nom ) || ! empty( $el->representant_telephone ) ) ) {
			$representants = [ [
				'statut'    => '',
				'nom'       => $el->representant_nom       ?? '',
				'prenom'    => $el->representant_prenom    ?? '',
				'telephone' => $el->representant_telephone ?? '',
				'email'     => $el->email_parent           ?? '',
			] ];
		}

		$urgence = $extra['contact_urgence'] ?? [];
		if ( empty( $urgence ) && ! empty( $el->urgence_nom ) ) {
			$urgence = [
				'statut'    => '',
				'nom'       => $el->urgence_nom       ?? '',
				'prenom'    => $el->urgence_prenom    ?? '',
				'telephone' => $el->urgence_telephone ?? '',
				'email'     => $el->urgence_email     ?? '',
			];
		}

		$discipline = in_array( $el->categorie_saisie ?? '', self::DISCIPLINES, true ) ? $el->categorie_saisie : '';

		return [
			'nom'                => $el->nom                ?? '',
			'prenom'             => $el->prenom              ?? '',
			'date_naissance'     => $this->to_iso_date( $el->date_naissance ?? '' ),
			'sexe'               => $extra['sexe']           ?? '',
			'email'              => $el->email               ?? '',
			'telephone'          => $el->telephone           ?? '',
			'lieu_naissance'     => $el->lieu_naissance      ?? '',
			'nationalite'        => $el->nationalite         ?? '',
			'adresse'            => $el->adresse             ?? '',
			'discipline'         => $discipline,
			'repres'             => $representants,
			'urgence'            => $urgence,
			'ancien_licence'     => $el->licence             ?? '',
			'ancien_passeport'   => $el->num_passeport       ?? '',
			'ancien_grade'       => $el->grade               ?? '',
			'taille_cm'          => $el->taille_cm           ?? '',
			'poids_kg'           => $el->poids_kg            ?? '',
			'pointure'           => $el->pointure            ?? '',
			'taille_tshirt'      => $el->taille_tshirt       ?? '',
			'taille_pantalon'    => $el->taille_pantalon     ?? '',
			'autorisation_photo' => ! empty( $extra['autorisation_photo'] ) ? 1 : 0,
			'droit_image'        => (int) ( $el->droit_image ?? 0 ),
			'autorisation_seul'  => (int) ( $el->autorisation_seul ?? 0 ),
		];
	}

	// ─── Traitement soumission ────────────────────────────────────────────────
	private function handle_submission( ?object $renouv_eleve = null ): array {
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

		// Pass'Sport : simple champ déclaratif, aucune vérification/calcul (cf. md/08-passsport-etat-des-lieux.md
		// — pas d'API de vérification accessible à un tiers, le club continue de traiter ça manuellement).
		$pass_sport_code = strtoupper( trim( sanitize_text_field( $_POST['pass_sport_code'] ?? '' ) ) );
		$caf_bon         = isset( $_POST['caf_bon'] ) ? 1 : 0;

		// Date du certificat médical (cf. doléance certificat médical — validité FFTDA d'1 an,
		// non bloquant si dépassée, seulement une alerte).
		$date_certif_medical = sanitize_text_field( $_POST['date_certificat_medical'] ?? '' );

		// Renfo : suivi médical selon la réglementation générale du sport (cf. doléance certificat
		// médical / Renfo) — certificat obligatoire seulement en première inscription adulte,
		// sinon questionnaire de santé QS-Sport (certificat optionnel si une réponse était positive).
		$qs_sport_renfo          = isset( $_POST['qs_sport_renfo'] ) ? 1 : 0;
		$date_certif_renfo_maj   = sanitize_text_field( $_POST['date_certificat_medical_renfo_maj'] ?? '' );
		$date_certif_renfo_qs    = sanitize_text_field( $_POST['date_certificat_medical_renfo_qs']  ?? '' );

		// Représentants légaux : 1 à 2 blocs, une personne par bloc (cf. doléance #1)
		$representants = $this->parse_personnes( is_array( $_POST['repres'] ?? null ) ? $_POST['repres'] : [], self::MAX_REPRESENTANTS );

		// Contact d'urgence : 1 bloc, même structure que les représentants (cf. doléance #7)
		$urgence_brut = is_array( $_POST['urgence'] ?? null ) ? $_POST['urgence'] : [];
		$urgence      = $this->parse_personnes( [ $urgence_brut ], 1 )[0] ?? $this->empty_personne();

		// Pratique antérieure
		$pratique_anterieure = isset( $_POST['pratique_anterieure'] ) ? 1 : 0;
		$ancien_licence      = sanitize_text_field( $_POST['ancien_licence']   ?? '' );
		$ancien_passeport    = sanitize_text_field( $_POST['ancien_passeport'] ?? '' );
		$ancien_grade        = sanitize_text_field( $_POST['ancien_grade']     ?? '' );

		// Mensurations — désormais obligatoires (cf. doléance #5)
		$taille_cm       = sanitize_text_field( $_POST['taille_cm']       ?? '' );
		$poids_kg        = sanitize_text_field( $_POST['poids_kg']        ?? '' );
		$pointure        = sanitize_text_field( $_POST['pointure']        ?? '' );
		$taille_tshirt   = sanitize_text_field( $_POST['taille_tshirt']   ?? '' );
		$taille_pantalon = sanitize_text_field( $_POST['taille_pantalon'] ?? '' );

		// Légal
		$autorisation_photo = isset( $_POST['autorisation_photo'] ) ? 1 : 0;
		$droit_image        = isset( $_POST['droit_image'] )        ? 1 : 0;
		$reglement_accepte  = isset( $_POST['reglement_accepte'] )  ? 1 : 0;

		// ── Âge / majorité — calculée côté serveur, jamais fait confiance au client ──
		$est_mineur_form = false;
		if ( $ddn !== '' && $this->valid_date( $ddn ) ) {
			$birth = new \DateTime( $ddn );
			$today = new \DateTime();
			$est_mineur_form = $today->diff( $birth )->y < 18;
		}

		// "Repartir seul" n'a de sens que pour un mineur (cf. doléance #7) : forcé à 0 sinon,
		// quelle que soit la valeur postée (défense en profondeur, indépendante du JS).
		$autorisation_seul = ( $est_mineur_form && isset( $_POST['autorisation_seul'] ) ) ? 1 : 0;

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
		if ( $lieu_naissance === '' ) $errors[] = 'Le lieu de naissance est requis.';
		if ( $nationalite    === '' ) $errors[] = 'La nationalité est requise.';
		if ( $adresse        === '' ) $errors[] = 'L\'adresse est requise.';
		if ( ! in_array( $discipline, self::DISCIPLINES, true ) ) $errors[] = 'Discipline invalide.';
		if ( $categorie === '' ) $errors[] = 'La catégorie n\'a pas pu être calculée. Vérifiez la date de naissance et la discipline.';

		// Représentants légaux : au moins un bloc complet (statut + nom + téléphone), uniquement pour un mineur
		if ( $est_mineur_form ) {
			$premier = $representants[0] ?? null;
			if ( ! $premier || $premier['statut'] === '' || $premier['nom'] === '' || $premier['telephone'] === '' ) {
				$errors[] = 'Au moins un représentant légal complet (statut, nom, téléphone) est requis pour un adhérent mineur.';
			}
		}

		// Contact d'urgence : toujours requis, quel que soit l'âge
		if ( $urgence['statut']    === '' ) $errors[] = 'Le lien du contact d\'urgence avec l\'adhérent est requis.';
		if ( $urgence['nom']       === '' ) $errors[] = 'Le nom du contact d\'urgence est requis.';
		if ( $urgence['telephone'] === '' ) $errors[] = 'Le téléphone du contact d\'urgence est requis.';

		// Mensurations obligatoires (cf. doléance #5)
		if ( $taille_cm       === '' ) $errors[] = 'La taille est requise.';
		if ( $poids_kg        === '' ) $errors[] = 'Le poids est requis.';
		if ( $pointure        === '' ) $errors[] = 'La pointure est requise.';
		if ( $taille_tshirt   === '' ) $errors[] = 'La taille de t-shirt/sweat est requise.';
		if ( $taille_pantalon === '' ) $errors[] = 'La taille de pantalon est requise.';

		// Documents obligatoires selon la discipline (cf. doléance #2)
		$need_certif_medical = $discipline === 'TKD';
		$need_rc             = $discipline === 'RENFO';
		$need_decharge       = $discipline === 'RENFO';

		if ( $need_certif_medical && empty( $_FILES['doc_certificat_medical']['name'] ) ) {
			$errors[] = 'Le certificat médical est obligatoire pour la pratique du Taekwondo.';
		}
		if ( $need_certif_medical && ( $date_certif_medical === '' || ! $this->valid_date( $date_certif_medical ) ) ) {
			$errors[] = 'La date du certificat médical est requise.';
		}
		if ( $need_rc && empty( $_FILES['doc_attestation_rc']['name'] ) ) {
			$errors[] = 'L\'attestation de responsabilité civile est obligatoire pour le Renforcement musculaire.';
		}
		if ( $need_decharge && empty( $_FILES['doc_decharge_honneur']['name'] ) ) {
			$errors[] = 'La décharge sur l\'honneur est obligatoire pour le Renforcement musculaire.';
		}

		// Renfo — suivi médical : certificat obligatoire seulement en première inscription adulte,
		// sinon questionnaire de santé QS-Sport (ou certificat en alternative si réponse positive).
		// Jamais fait confiance à l'âge/au statut de renouvellement calculés côté client.
		$need_renfo_certif_maj = $discipline === 'RENFO' && ! $est_mineur_form && ! $renouv_eleve;
		$need_renfo_qs         = $discipline === 'RENFO' && ! $need_renfo_certif_maj;

		if ( $need_renfo_certif_maj ) {
			if ( empty( $_FILES['doc_certificat_medical_renfo_maj']['name'] ) ) {
				$errors[] = 'Le certificat médical est obligatoire pour une première inscription au Renforcement musculaire (adulte).';
			}
			if ( $date_certif_renfo_maj === '' || ! $this->valid_date( $date_certif_renfo_maj ) ) {
				$errors[] = 'La date du certificat médical est requise.';
			}
		}
		if ( $need_renfo_qs ) {
			$a_certif_renfo_qs = ! empty( $_FILES['doc_certificat_medical_renfo_qs']['name'] );
			if ( ! $qs_sport_renfo && ! $a_certif_renfo_qs ) {
				$errors[] = 'Merci soit de confirmer le questionnaire de santé QS-Sport (si vous avez répondu non à toutes les questions), soit de joindre un certificat médical (si vous avez répondu oui à une question).';
			}
			if ( $a_certif_renfo_qs && ( $date_certif_renfo_qs === '' || ! $this->valid_date( $date_certif_renfo_qs ) ) ) {
				$errors[] = 'La date du certificat médical est requise si vous en joignez un.';
			}
		}

		// Bon CAF : le fichier n'est requis que si la case "J'ai un bon CAF" est cochée
		if ( $caf_bon && empty( $_FILES['doc_bon_caf']['name'] ) ) {
			$errors[] = 'Merci de déposer votre bon CAF, ou de décocher la case si vous n\'en avez pas.';
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

		// ── Envoi des documents — uniquement une fois tout le reste validé ──
		$doc_certificat_medical = '';
		$doc_attestation_rc     = '';
		$doc_decharge_honneur   = '';

		if ( $need_certif_medical ) {
			$up = $this->handle_upload( 'doc_certificat_medical', 'certificats-medicaux' );
			if ( ! $up['ok'] ) {
				return [ 'success' => false, 'errors' => [ $up['error'] ?: "Erreur lors de l'envoi du certificat médical." ], 'data' => $_POST ];
			}
			$doc_certificat_medical = $up['url'];
		}
		// Renfo : le certificat (obligatoire en 1ère inscription adulte, ou optionnel via QS-Sport)
		// est reporté dans les mêmes colonnes que le certificat Taekwondo — mutuellement exclusifs
		// puisqu'un adhérent ne choisit qu'une seule discipline.
		if ( $need_renfo_certif_maj ) {
			$up = $this->handle_upload( 'doc_certificat_medical_renfo_maj', 'certificats-medicaux' );
			if ( ! $up['ok'] ) {
				return [ 'success' => false, 'errors' => [ $up['error'] ?: "Erreur lors de l'envoi du certificat médical." ], 'data' => $_POST ];
			}
			$doc_certificat_medical = $up['url'];
			$date_certif_medical    = $date_certif_renfo_maj;
		}
		if ( $need_renfo_qs && ! empty( $_FILES['doc_certificat_medical_renfo_qs']['name'] ) ) {
			$up = $this->handle_upload( 'doc_certificat_medical_renfo_qs', 'certificats-medicaux' );
			if ( ! $up['ok'] ) {
				return [ 'success' => false, 'errors' => [ $up['error'] ?: "Erreur lors de l'envoi du certificat médical." ], 'data' => $_POST ];
			}
			$doc_certificat_medical = $up['url'];
			$date_certif_medical    = $date_certif_renfo_qs;
		}
		if ( $need_rc ) {
			$up = $this->handle_upload( 'doc_attestation_rc', 'attestations-rc' );
			if ( ! $up['ok'] ) {
				return [ 'success' => false, 'errors' => [ $up['error'] ?: "Erreur lors de l'envoi de l'attestation de responsabilité civile." ], 'data' => $_POST ];
			}
			$doc_attestation_rc = $up['url'];
		}
		if ( $need_decharge ) {
			$up = $this->handle_upload( 'doc_decharge_honneur', 'decharges' );
			if ( ! $up['ok'] ) {
				return [ 'success' => false, 'errors' => [ $up['error'] ?: "Erreur lors de l'envoi de la décharge sur l'honneur." ], 'data' => $_POST ];
			}
			$doc_decharge_honneur = $up['url'];
		}
		$doc_bon_caf = '';
		if ( $caf_bon ) {
			$up = $this->handle_upload( 'doc_bon_caf', 'bons-caf' );
			if ( ! $up['ok'] ) {
				return [ 'success' => false, 'errors' => [ $up['error'] ?: "Erreur lors de l'envoi du bon CAF." ], 'data' => $_POST ];
			}
			$doc_bon_caf = $up['url'];
		}
		// Photo de l'adhérent : facultative, pas de blocage si absente — seule une erreur
		// d'envoi réelle (format, taille) est remontée comme erreur de formulaire.
		$photo_url = '';
		$up = $this->handle_upload( 'photo_adherent', 'photos' );
		if ( $up['ok'] ) {
			$photo_url = $up['url'];
		} elseif ( $up['error'] ) {
			return [ 'success' => false, 'errors' => [ $up['error'] ], 'data' => $_POST ];
		}

		// ── Insertion ──
		$ok = $wpdb->insert( $this->table, [
			'nom'                    => $nom,
			'prenom'                 => $prenom,
			'date_naissance'         => $ddn,
			'sexe'                   => $sexe,
			'email'                  => $email,
			'telephone'              => $telephone,
			'lieu_naissance'         => $lieu_naissance,
			'nationalite'            => $nationalite,
			'adresse'                => $adresse,
			'discipline'             => $discipline,
			'categorie'              => $categorie,
			'message'                => $message,
			'pass_sport_code'        => $pass_sport_code,
			'caf_bon'                => $caf_bon,
			'doc_bon_caf'            => $doc_bon_caf,
			'representants_legaux'   => wp_json_encode( $representants ),
			'contact_urgence'        => wp_json_encode( $urgence ),
			'renouvellement_eleve_id'=> $renouv_eleve ? $renouv_eleve->id : null,
			'pratique_anterieure'    => $pratique_anterieure,
			'ancien_licence'         => $ancien_licence,
			'ancien_passeport'       => $ancien_passeport,
			'ancien_grade'           => $ancien_grade,
			'taille_cm'              => $taille_cm,
			'poids_kg'               => $poids_kg,
			'pointure'               => $pointure,
			'taille_tshirt'          => $taille_tshirt,
			'taille_pantalon'        => $taille_pantalon,
			'doc_certificat_medical' => $doc_certificat_medical,
			'date_certificat_medical'=> $date_certif_medical ?: null,
			'qs_sport_confirme'      => $qs_sport_renfo,
			'doc_attestation_rc'     => $doc_attestation_rc,
			'doc_decharge_honneur'   => $doc_decharge_honneur,
			'photo_url'              => $photo_url,
			'autorisation_photo'     => $autorisation_photo,
			'autorisation_seul'      => $autorisation_seul,
			'droit_image'            => $droit_image,
			'reglement_accepte'      => $reglement_accepte,
			'statut'                 => 'pending',
			'ip_address'             => $_SERVER['REMOTE_ADDR'] ?? '',
			'created_at'             => current_time( 'mysql' ),
		], [
			'%s','%s','%s','%s','%s','%s',
			'%s','%s','%s','%s','%s','%s',
			'%s','%d','%s',
			'%s','%s','%d',
			'%d','%s','%s','%s',
			'%s','%s','%s','%s','%s',
			'%s','%s','%d','%s','%s','%s',
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

	// ─── Blocs "personne" (représentants légaux / contact urgence) ────────────
	private function empty_personne(): array {
		return [ 'statut' => '', 'nom' => '', 'prenom' => '', 'telephone' => '', 'email' => '' ];
	}

	private function parse_personnes( array $blocs, int $max ): array {
		$out = [];
		foreach ( $blocs as $bloc ) {
			if ( ! is_array( $bloc ) ) continue;
			$p = [
				'statut'    => sanitize_text_field( $bloc['statut']    ?? '' ),
				'nom'       => sanitize_text_field( $bloc['nom']       ?? '' ),
				'prenom'    => sanitize_text_field( $bloc['prenom']    ?? '' ),
				'telephone' => sanitize_text_field( $bloc['telephone'] ?? '' ),
				'email'     => sanitize_text_field( $bloc['email']     ?? '' ),
			];
			if ( $p === $this->empty_personne() ) continue; // bloc laissé vide (ex : 2e représentant ajouté puis non rempli)
			$out[] = $p;
			if ( count( $out ) >= $max ) break;
		}
		return $out;
	}

	// ─── Upload de documents (cf. doléance #2) ────────────────────────────────
	private function handle_upload( string $field, string $subdir ): array {
		if ( empty( $_FILES[ $field ]['name'] ) ) {
			return [ 'ok' => false, 'error' => null, 'url' => '' ];
		}
		if ( $_FILES[ $field ]['error'] !== UPLOAD_ERR_OK ) {
			return [ 'ok' => false, 'error' => "Erreur lors de l'envoi du fichier.", 'url' => '' ];
		}
		if ( $_FILES[ $field ]['size'] > 5 * 1024 * 1024 ) {
			return [ 'ok' => false, 'error' => 'Le fichier dépasse la taille maximale autorisée (5 Mo).', 'url' => '' ];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Rangés à part du dossier uploads/ standard, hors indexation directe (voir protect_uploads_dir()).
		$target = '/sp-adhesions-docs/' . trim( $subdir, '/' ) . '/' . date( 'Y' );
		$filter = static function ( array $dirs ) use ( $target ): array {
			$dirs['subdir'] = $target;
			$dirs['path']   = $dirs['basedir'] . $target;
			$dirs['url']    = $dirs['baseurl'] . $target;
			return $dirs;
		};
		add_filter( 'upload_dir', $filter );

		$result = wp_handle_upload( $_FILES[ $field ], [
			'test_form' => false,
			'mimes'     => [
				'pdf'      => 'application/pdf',
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
			],
		] );

		remove_filter( 'upload_dir', $filter );

		if ( isset( $result['error'] ) ) {
			return [ 'ok' => false, 'error' => $result['error'], 'url' => '' ];
		}

		$this->protect_uploads_dir();

		return [ 'ok' => true, 'error' => null, 'url' => $result['url'] ];
	}

	private function protect_uploads_dir(): void {
		$dir      = wp_upload_dir()['basedir'] . '/sp-adhesions-docs';
		$htaccess = $dir . '/.htaccess';
		if ( is_dir( $dir ) && ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Options -Indexes\n" );
		}
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
	private function render_form( ?array $result, ?object $renouv_eleve = null ): void {
		$errors = $result['errors'] ?? [];
		$data   = $result['data']   ?? ( $renouv_eleve ? $this->build_prefill_from_eleve( $renouv_eleve ) : [] );
		$v = static fn( string $k ): string => esc_attr( $data[ $k ] ?? '' );

		// Ré-affichage après erreur : au moins 1 bloc représentant, jusqu'à MAX_REPRESENTANTS
		$repres_posted = is_array( $data['repres'] ?? null ) ? array_values( $data['repres'] ) : [];
		$nb_repres     = max( 1, min( self::MAX_REPRESENTANTS, count( $repres_posted ) ?: 1 ) );

		$urgence_posted = is_array( $data['urgence'] ?? null ) ? $data['urgence'] : [];

		$reglement_html = (string) get_option( 'sp_cal_reglement_interieur', '' );

		// Bandeau bien visible côté adhérent quand il s'agit d'un renouvellement (cf. doléance renouvellement,
		// point laissé à mon appréciation) : titre, bandeau et libellé du bouton changent — jamais juste
		// une mention discrète, pour éviter toute confusion avec une nouvelle inscription.
		$titre = $renouv_eleve
			? '🔄 Renouvellement d\'adhésion — ' . esc_html( $renouv_eleve->prenom . ' ' . $renouv_eleve->nom )
			: 'Demande d\'adhésion';
		$intro = $renouv_eleve
			? 'Vos informations sont pré-remplies ci-dessous à partir de votre fiche existante : vérifiez-les, complétez ce qui manque, puis envoyez pour renouveler votre adhésion.'
			: 'Remplissez ce formulaire pour rejoindre notre club. Notre équipe vous contactera pour finaliser votre inscription et le règlement de la cotisation.';
		$btn_label = $renouv_eleve ? 'Confirmer mon renouvellement →' : 'Envoyer ma demande d\'adhésion →';
		?>
		<div class="sp-adh-wrapper">
			<h2 class="sp-adh-title"><?= $titre ?></h2>
			<?php if ( $renouv_eleve ) : ?>
				<div class="sp-adh-renouv-banner">🔄 <?= esc_html( $intro ) ?></div>
			<?php else : ?>
				<p class="sp-adh-intro"><?= esc_html( $intro ) ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="sp-adh-errors" role="alert">
					<strong>⚠️ Veuillez corriger les erreurs suivantes :</strong>
					<ul><?php foreach ( $errors as $e ) echo '<li>' . esc_html( $e ) . '</li>'; ?></ul>
				</div>
			<?php endif; ?>

			<form method="post" class="sp-adh-form" novalidate lang="fr" enctype="multipart/form-data">
				<?php wp_nonce_field( 'sp_adhesion_form', 'sp_adhesion_nonce' ); ?>
				<input type="hidden" name="categorie" id="sp_categorie_hidden" value="<?= $v('categorie') ?>">
				<?php if ( $renouv_eleve ) : ?>
					<input type="hidden" name="renouv_token" value="<?= esc_attr( $renouv_eleve->token ) ?>">
				<?php endif; ?>

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
							<label for="sp_lieu_naissance">Lieu de naissance <span class="sp-req">*</span></label>
							<input type="text" id="sp_lieu_naissance" name="lieu_naissance" value="<?= $v('lieu_naissance') ?>" required>
						</div>
						<div class="sp-adh-col">
							<label for="sp_nationalite">Nationalité <span class="sp-req">*</span></label>
							<input type="text" id="sp_nationalite" name="nationalite" value="<?= $v('nationalite') ?>" required>
						</div>
					</div>
					<div class="sp-adh-row">
						<div class="sp-adh-col sp-adh-col-full">
							<label for="sp_adresse">Adresse <span class="sp-req">*</span></label>
							<input type="text" id="sp_adresse" name="adresse" value="<?= $v('adresse') ?>" autocomplete="street-address" required>
						</div>
					</div>
					<div class="sp-adh-row">
						<div class="sp-adh-col sp-adh-col-full">
							<label for="sp_photo">Photo de l'adhérent <span class="sp-optional">(facultatif — utilisée pour la carte de membre)</span></label>
							<input type="file" id="sp_photo" name="photo_adherent" accept=".jpg,.jpeg,.png">
							<p class="sp-optional">Formats acceptés : JPG, PNG — 5 Mo maximum.</p>
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

				<!-- ══ REPRÉSENTANTS LÉGAUX ══════════════════════════════════ -->
				<div class="sp-adh-group" id="sp_repres_bloc">
					<h3 class="sp-adh-group-title">
						👨‍👩‍👧 Représentant(s) légal/légaux
						<span id="sp_repres_mineur_badge" style="display:none;background:#dc2626;color:#fff;font-size:.75rem;padding:1px 8px;border-radius:10px;font-weight:600;margin-left:8px;vertical-align:middle;">Mineur</span>
					</h3>
					<p class="sp-adh-group-desc">Une personne par bloc — utilisé pour les communications officielles et autorisations.</p>

					<div id="sp_repres_list">
						<?php for ( $i = 0; $i < $nb_repres; $i++ ) : ?>
							<?php echo $this->render_personne_block( 'repres', $i, $repres_posted[ $i ] ?? [], $i === 0 ? 'conditional' : 'optional' ); ?>
						<?php endfor; ?>
					</div>

					<div class="sp-adh-row" id="sp_repres_add_row">
						<button type="button" id="sp_repres_add_btn" class="sp-adh-btn-add">+ Ajouter un représentant légal</button>
					</div>
				</div>

				<!-- Template JS pour cloner un bloc représentant supplémentaire -->
				<template id="sp_repres_template"><?php echo $this->render_personne_block( 'repres', '__INDEX__', [], 'optional' ); ?></template>

				<!-- ══ CONTACT URGENCE ═══════════════════════════════════════ -->
				<div class="sp-adh-group">
					<h3 class="sp-adh-group-title">🚨 Contact d'urgence</h3>
					<p class="sp-adh-group-desc">Personne à contacter immédiatement en cas d'incident — peut être différente du/des représentant(s) légal/légaux.</p>
					<?php echo $this->render_personne_block( 'urgence', null, $urgence_posted, 'required' ); ?>
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
						<div class="sp-adh-col">
							<label for="sp_pass_sport">Code Pass'Sport <span class="sp-optional">(si vous en avez un)</span></label>
							<input type="text" id="sp_pass_sport" name="pass_sport_code" value="<?= $v('pass_sport_code') ?>"
							       placeholder="Ex : 26-XXXX-XXXX" maxlength="20" style="text-transform:uppercase;">
							<p class="sp-optional">Le club se charge de la déclarer et d'appliquer la réduction — aucune vérification automatique.</p>
						</div>
					</div>
					<div class="sp-adh-row">
						<div class="sp-adh-col sp-adh-col-full">
							<label class="sp-adh-check sp-adh-check-trigger" style="padding-left:0;">
								<input type="checkbox" name="caf_bon" id="sp_caf_bon" value="1" <?= !empty($data['caf_bon'])?'checked':'' ?>>
								<span>J'ai un bon CAF</span>
							</label>
							<div class="sp-adh-conditional" id="sp_caf_bon_fields" style="<?= !empty($data['caf_bon'])?'':'display:none' ?>">
								<label for="sp_doc_bon_caf">Bon CAF <span class="sp-req">*</span></label>
								<input type="file" id="sp_doc_bon_caf" name="doc_bon_caf" accept=".pdf,.jpg,.jpeg,.png">
								<p class="sp-optional">Formats acceptés : PDF, JPG, PNG — 5 Mo maximum.</p>
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

				<!-- ══ DOCUMENTS OBLIGATOIRES (selon discipline) ═════════════ -->
				<div class="sp-adh-group" id="sp_docs_group" style="display:none;">
					<h3 class="sp-adh-group-title">📎 Documents obligatoires</h3>
					<p class="sp-adh-group-desc">Les documents demandés dépendent de la discipline choisie ci-dessus.</p>

					<div class="sp-adh-row sp-adh-doc-row" id="sp_doc_certif_row" style="display:none;">
						<div class="sp-adh-col">
							<label for="sp_doc_certif">Certificat médical <span class="sp-optional">(obligatoire pour le Taekwondo)</span> <span class="sp-req">*</span></label>
							<input type="file" id="sp_doc_certif" name="doc_certificat_medical" accept=".pdf,.jpg,.jpeg,.png">
							<p class="sp-optional">Formats acceptés : PDF, JPG, PNG — 5 Mo maximum.</p>
						</div>
						<div class="sp-adh-col">
							<label for="sp_date_certif">Date du certificat <span class="sp-req">*</span></label>
							<input type="date" id="sp_date_certif" name="date_certificat_medical" value="<?= $v('date_certificat_medical') ?>" max="<?= esc_attr( date('Y-m-d') ) ?>">
							<p class="sp-optional">Le certificat de non contre-indication au Taekwondo en compétition doit être renouvelé régulièrement (règlement FFTDA — actuellement <?= (int) get_option( 'sp_cal_certif_medical_mois', 12 ) ?> mois).</p>
							<p id="sp_certif_perime" class="sp-adh-warning" style="display:none;">⚠️ Ce certificat dépasse la durée de validité recommandée — il ne sera plus valide selon le règlement FFTDA. Vous pouvez tout de même envoyer votre demande, mais pensez à fournir un certificat plus récent dès que possible.</p>
						</div>
					</div>

					<div class="sp-adh-row sp-adh-doc-row" id="sp_doc_rc_row" style="display:none;">
						<div class="sp-adh-col sp-adh-col-full">
							<label for="sp_doc_rc">Attestation de responsabilité civile <span class="sp-optional">(obligatoire pour le Renforcement musculaire)</span> <span class="sp-req">*</span></label>
							<input type="file" id="sp_doc_rc" name="doc_attestation_rc" accept=".pdf,.jpg,.jpeg,.png">
							<p class="sp-optional">Formats acceptés : PDF, JPG, PNG — 5 Mo maximum.</p>
						</div>
					</div>

					<div class="sp-adh-row sp-adh-doc-row" id="sp_doc_decharge_row" style="display:none;">
						<div class="sp-adh-col sp-adh-col-full">
							<label for="sp_doc_decharge">Décharge sur l'honneur <span class="sp-optional">(obligatoire pour le Renforcement musculaire)</span> <span class="sp-req">*</span></label>
							<p class="sp-optional">Vous n'avez pas de modèle ? <button type="button" class="sp-adh-link-btn" id="sp_attestation_print">🖨️ Imprimer le modèle à signer</button>, puis déposez-le ci-dessous une fois signé.</p>
							<input type="file" id="sp_doc_decharge" name="doc_decharge_honneur" accept=".pdf,.jpg,.jpeg,.png">
							<p class="sp-optional">Formats acceptés : PDF, JPG, PNG — 5 Mo maximum.</p>
						</div>
					</div>

					<!-- Renfo, adulte, première inscription : certificat médical obligatoire (réglementation générale du sport) -->
					<div class="sp-adh-row sp-adh-doc-row" id="sp_doc_renfo_certif_row" style="display:none;">
						<div class="sp-adh-col">
							<label for="sp_doc_renfo_certif">Certificat médical <span class="sp-optional">(première inscription, adulte)</span> <span class="sp-req">*</span></label>
							<input type="file" id="sp_doc_renfo_certif" name="doc_certificat_medical_renfo_maj" accept=".pdf,.jpg,.jpeg,.png">
							<p class="sp-optional">Formats acceptés : PDF, JPG, PNG — 5 Mo maximum.</p>
						</div>
						<div class="sp-adh-col">
							<label for="sp_date_renfo_certif">Date du certificat <span class="sp-req">*</span></label>
							<input type="date" id="sp_date_renfo_certif" name="date_certificat_medical_renfo_maj" value="<?= $v('date_certificat_medical_renfo_maj') ?>" max="<?= esc_attr( date('Y-m-d') ) ?>">
							<p class="sp-optional">Obligatoire à la première inscription pour un adulte (réglementation générale du sport). Les années suivantes, un questionnaire de santé suffit.</p>
						</div>
					</div>

					<!-- Renfo, mineur ou renouvellement : questionnaire de santé QS-Sport (certificat requis seulement si une réponse est positive).
					     Noms de champs volontairement différents du bloc ci-dessus : deux <input> avec le même name se marcheraient dessus
					     côté serveur (le second, même vide, écraserait la valeur du premier — un seul des deux blocs est jamais rempli). -->
					<div class="sp-adh-row sp-adh-doc-row" id="sp_doc_renfo_qs_row" style="display:none;">
						<div class="sp-adh-col sp-adh-col-full">
							<label class="sp-adh-check" style="padding-left:0;">
								<input type="checkbox" name="qs_sport_renfo" id="sp_qs_sport_renfo" value="1" <?= !empty($data['qs_sport_renfo'])?'checked':'' ?>>
								<span>Je certifie avoir complété le questionnaire de santé <strong>QS-Sport</strong> et avoir répondu <strong>NON</strong> à toutes les questions.</span>
							</label>
							<p class="sp-optional">Si vous avez répondu <strong>OUI</strong> à au moins une question, ne cochez pas cette case et joignez plutôt un certificat médical ci-dessous :</p>
							<label for="sp_doc_renfo_qs_certif">Certificat médical <span class="sp-optional">(uniquement si une réponse était positive au questionnaire)</span></label>
							<input type="file" id="sp_doc_renfo_qs_certif" name="doc_certificat_medical_renfo_qs" accept=".pdf,.jpg,.jpeg,.png">
							<label for="sp_date_renfo_qs_certif" style="margin-top:.5rem;display:block;">Date du certificat <span class="sp-optional">(si fourni)</span></label>
							<input type="date" id="sp_date_renfo_qs_certif" name="date_certificat_medical_renfo_qs" value="<?= $v('date_certificat_medical_renfo_qs') ?>" max="<?= esc_attr( date('Y-m-d') ) ?>">
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

				<!-- ══ MENSURATIONS (obligatoire — cf. doléance #5) ══════════ -->
				<div class="sp-adh-group">
					<h3 class="sp-adh-group-title">📏 Mensurations <span class="sp-optional" style="font-weight:400;font-size:.85rem;">(nécessaire pour la commande des équipements)</span></h3>
					<div class="sp-adh-row">
						<div class="sp-adh-col">
							<label for="sp_taille">Taille (cm) <span class="sp-req">*</span></label>
							<input type="number" id="sp_taille" name="taille_cm" value="<?= $v('taille_cm') ?>"
							       min="50" max="250" placeholder="Ex : 165" required>
						</div>
						<div class="sp-adh-col">
							<label for="sp_poids">Poids (kg) <span class="sp-req">*</span></label>
							<input type="number" id="sp_poids" name="poids_kg" value="<?= $v('poids_kg') ?>"
							       min="10" max="250" placeholder="Ex : 60" required>
						</div>
						<div class="sp-adh-col">
							<label for="sp_pointure">Pointure <span class="sp-req">*</span></label>
							<input type="number" id="sp_pointure" name="pointure" value="<?= $v('pointure') ?>"
							       min="20" max="55" placeholder="Ex : 38" required>
						</div>
					</div>
					<div class="sp-adh-row">
						<div class="sp-adh-col">
							<label for="sp_tshirt">Taille t-shirt / sweat <span class="sp-req">*</span></label>
							<input type="text" id="sp_tshirt" name="taille_tshirt" value="<?= $v('taille_tshirt') ?>"
							       placeholder="Ex : M, L, XL, 12 ans…" required>
						</div>
						<div class="sp-adh-col">
							<label for="sp_pantalon">Taille pantalon <span class="sp-req">*</span></label>
							<input type="text" id="sp_pantalon" name="taille_pantalon" value="<?= $v('taille_pantalon') ?>"
							       placeholder="Ex : 40, 12 ans…" required>
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
					<label class="sp-adh-check" id="sp_autorisation_seul_row">
						<input type="checkbox" name="autorisation_seul" value="1" <?= !empty($data['autorisation_seul'])?'checked':'' ?>>
						<span>J'autorise l'adhérent à <strong>repartir seul(e)</strong> après les entraînements.</span>
					</label>
					<label class="sp-adh-check sp-adh-check-required">
						<input type="checkbox" name="reglement_accepte" value="1" required <?= !empty($data['reglement_accepte'])?'checked':'' ?>>
						<span>J'ai lu et j'accepte le <button type="button" class="sp-adh-link-btn" id="sp_reglement_open">règlement intérieur</button> du club. <span class="sp-req">*</span></span>
					</label>
				</div>

				<div class="sp-adh-footer">
					<p class="sp-adh-required-note"><span class="sp-req">*</span> Champs obligatoires</p>
					<button type="submit" name="sp_adhesion_submit" class="sp-adh-btn-submit">
						<?= $btn_label ?>
					</button>
				</div>
			</form>
		</div>

		<!-- ══ POPUP RÈGLEMENT INTÉRIEUR (cf. doléance #6) ═══════════════ -->
		<div class="sp-adh-modal-overlay" id="sp_reglement_overlay" style="display:none;">
			<div class="sp-adh-modal" role="dialog" aria-modal="true" aria-labelledby="sp_reglement_title">
				<div class="sp-adh-modal-header">
					<h3 id="sp_reglement_title">📋 Règlement intérieur</h3>
					<button type="button" class="sp-adh-modal-close" id="sp_reglement_close" aria-label="Fermer">✕</button>
				</div>
				<div class="sp-adh-modal-body">
					<?php echo $reglement_html !== ''
						? wp_kses_post( wpautop( $reglement_html ) )
						: '<p><em>Le règlement intérieur n\'a pas encore été renseigné par le club. Contactez-nous pour plus d\'informations.</em></p>'; ?>
				</div>
			</div>
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

			// ── Documents obligatoires selon discipline (doléance #2) ─────────
			const docsGroup   = document.getElementById('sp_docs_group');
			const docCertif   = document.getElementById('sp_doc_certif_row');
			const docRc       = document.getElementById('sp_doc_rc_row');
			const docDecharge = document.getElementById('sp_doc_decharge_row');

			function setDocRequired(rowEl, required) {
				if (!rowEl) return;
				rowEl.style.display = required ? '' : 'none';
				rowEl.querySelectorAll('input[type="file"], input[type="date"]').forEach(function(input) {
					if (required) { input.setAttribute('required', 'required'); }
					else { input.removeAttribute('required'); input.value = ''; }
				});
			}

			// ── Alerte non bloquante : certificat médical de plus d'un an (règlement FFTDA) ──
			const dateCertifInput = document.getElementById('sp_date_certif');
			const certifPerimeMsg = document.getElementById('sp_certif_perime');
			const CERTIF_MEDICAL_MOIS = <?= (int) get_option( 'sp_cal_certif_medical_mois', 12 ) ?>;
			if (dateCertifInput && certifPerimeMsg) {
				dateCertifInput.addEventListener('change', function() {
					if (!this.value) { certifPerimeMsg.style.display = 'none'; return; }
					const limite = new Date();
					limite.setMonth(limite.getMonth() - CERTIF_MEDICAL_MOIS);
					certifPerimeMsg.style.display = (new Date(this.value) < limite) ? '' : 'none';
				});
			}

			// Âge (mineur/majeur), calculé côté client pour l'affichage — jamais fait confiance
			// côté serveur (recalculé côté PHP à la soumission, comme pour la catégorie).
			function estMineur() {
				const ddn = ddnInput.value;
				if ( ! ddn ) return null;
				const birth = new Date( ddn );
				const today = new Date();
				let age = today.getFullYear() - birth.getFullYear();
				const m = today.getMonth() - birth.getMonth();
				if ( m < 0 || ( m === 0 && today.getDate() < birth.getDate() ) ) age--;
				return age < 18;
			}

			// Renfo : certificat obligatoire seulement pour un adulte en première inscription ;
			// mineur ou renouvellement => questionnaire de santé QS-Sport (certificat en option si
			// une réponse était positive) — cf. doléance certificat médical / réglementation générale du sport.
			const RENOUVELLEMENT   = <?= $renouv_eleve ? 'true' : 'false' ?>;
			const docRenfoCertif   = document.getElementById('sp_doc_renfo_certif_row');
			const docRenfoQs       = document.getElementById('sp_doc_renfo_qs_row');

			function majRenfoMedical() {
				if (discInput.value !== 'RENFO') {
					setDocRequired(docRenfoCertif, false);
					setDocRequired(docRenfoQs,     false);
					return;
				}
				const mineur = estMineur();
				const useQs = RENOUVELLEMENT || mineur === true || mineur === null;
				setDocRequired(docRenfoCertif, ! useQs);
				docRenfoQs.style.display = useQs ? '' : 'none';
				if (!useQs) {
					const cb = docRenfoQs.querySelector('input[type="checkbox"]');
					if (cb) cb.checked = false;
					docRenfoQs.querySelectorAll('input[type="file"], input[type="date"]').forEach(i => i.value = '');
				}
			}

			function majDocuments() {
				const disc    = discInput.value;
				const isTkd   = disc === 'TKD';
				const isRenfo = disc === 'RENFO';
				docsGroup.style.display = (isTkd || isRenfo) ? '' : 'none';
				setDocRequired(docCertif,   isTkd);
				setDocRequired(docRc,       isRenfo);
				setDocRequired(docDecharge, isRenfo);
				majRenfoMedical();
			}
			discInput.addEventListener('change', majDocuments);
			ddnInput.addEventListener('change', majRenfoMedical);
			majDocuments();

			// ── Représentant légal + "repartir seul" : conditionnel à la majorité (doléance #7) ──
			const represBloc       = document.getElementById('sp_repres_bloc');
			const represBadge      = document.getElementById('sp_repres_mineur_badge');
			const autorisationSeul = document.getElementById('sp_autorisation_seul_row');

			function represRequiredInputs() {
				return represBloc ? represBloc.querySelectorAll('[data-repres-required]') : [];
			}

			function majeurOuMineur() {
				const ddn = ddnInput.value;
				if ( ! represBloc ) return;

				if ( ! ddn ) {
					represBloc.style.display = '';
					if (autorisationSeul) autorisationSeul.style.display = '';
					represBadge.style.display = 'none';
					represRequiredInputs().forEach( i => i.removeAttribute('required') );
					return;
				}

				const birth = new Date( ddn );
				const today = new Date();
				let age = today.getFullYear() - birth.getFullYear();
				const m = today.getMonth() - birth.getMonth();
				if ( m < 0 || ( m === 0 && today.getDate() < birth.getDate() ) ) age--;

				if ( age < 18 ) {
					represBloc.style.display = '';
					if (autorisationSeul) autorisationSeul.style.display = '';
					represBadge.style.display = 'inline';
					represRequiredInputs().forEach( i => i.setAttribute('required', 'required') );
				} else {
					represBloc.style.display = 'none';
					if (autorisationSeul) {
						autorisationSeul.style.display = 'none';
						const cb = autorisationSeul.querySelector('input[type="checkbox"]');
						if (cb) cb.checked = false;
					}
					represBadge.style.display = 'none';
					represRequiredInputs().forEach( i => i.removeAttribute('required') );
				}
			}

			ddnInput.addEventListener('change', majeurOuMineur);
			majeurOuMineur(); // init si DDN déjà remplie (ex: retour après erreur)

			// ── Représentants légaux : ajout / suppression de blocs (doléance #1) ──
			const represList     = document.getElementById('sp_repres_list');
			const represTemplate = document.getElementById('sp_repres_template');
			const represAddBtn   = document.getElementById('sp_repres_add_btn');
			const MAX_REPRES     = <?= (int) self::MAX_REPRESENTANTS ?>;

			function updateAddBtnVisibility() {
				const count = represList.querySelectorAll('.sp-adh-personne-block').length;
				represAddBtn.style.display = count >= MAX_REPRES ? 'none' : '';
			}

			if (represAddBtn) {
				represAddBtn.addEventListener('click', function() {
					const count = represList.querySelectorAll('.sp-adh-personne-block').length;
					if ( count >= MAX_REPRES ) return;
					const html = represTemplate.innerHTML.replace(/__INDEX__/g, String(count));
					const wrapper = document.createElement('div');
					wrapper.innerHTML = html.trim();
					const block = wrapper.firstElementChild;
					represList.appendChild(block);
					updateAddBtnVisibility();
				});
			}

			if (represList) {
				represList.addEventListener('click', function(e) {
					const btn = e.target.closest('.sp-adh-personne-remove');
					if ( ! btn ) return;
					const block = btn.closest('.sp-adh-personne-block');
					if ( block ) block.remove();
					updateAddBtnVisibility();
				});
			}

			updateAddBtnVisibility();

			// ── Bloc conditionnel : pratique antérieure ───────────────────────
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

			// ── Bon CAF : dépôt du fichier requis uniquement si la case est cochée ────
			const cafBonCheckbox = document.getElementById('sp_caf_bon');
			const cafBonFields   = document.getElementById('sp_caf_bon_fields');
			if (cafBonCheckbox && cafBonFields) {
				const cafBonInput = cafBonFields.querySelector('input[type="file"]');
				cafBonCheckbox.addEventListener('change', function() {
					cafBonFields.style.display = this.checked ? '' : 'none';
					if (cafBonInput) {
						if (this.checked) cafBonInput.setAttribute('required', 'required');
						else { cafBonInput.removeAttribute('required'); cafBonInput.value = ''; }
					}
				});
			}

			// ── Popup règlement intérieur (doléance #6) ───────────────────────
			const reglementOpen    = document.getElementById('sp_reglement_open');
			const reglementOverlay = document.getElementById('sp_reglement_overlay');
			const reglementClose   = document.getElementById('sp_reglement_close');

			function openReglement(e) { if (e) e.preventDefault(); reglementOverlay.style.display = 'flex'; }
			function closeReglement() { reglementOverlay.style.display = 'none'; }

			if (reglementOpen)    reglementOpen.addEventListener('click', openReglement);
			if (reglementClose)   reglementClose.addEventListener('click', closeReglement);
			if (reglementOverlay) reglementOverlay.addEventListener('click', function(e) {
				if (e.target === reglementOverlay) closeReglement();
			});
			document.addEventListener('keydown', function(e) {
				if (e.key === 'Escape') closeReglement();
			});

			// ── Impression du modèle d'attestation sur l'honneur (Renfo) ──────
			const attestationBtn = document.getElementById('sp_attestation_print');
			if (attestationBtn) {
				attestationBtn.addEventListener('click', function() {
					const nomEl    = document.getElementById('sp_nom');
					const prenomEl = document.getElementById('sp_prenom');
					const params   = new URLSearchParams({ sp_adh_print: 'attestation_renfo' });
					if (nomEl    && nomEl.value)    params.set('nom', nomEl.value);
					if (prenomEl && prenomEl.value) params.set('prenom', prenomEl.value);
					window.open('<?= esc_url( home_url( '/' ) ) ?>?' + params.toString(), '_blank');
				});
			}
		})();
		</script>
		<?php
	}

	// ─── Rendu d'un bloc "personne" (représentant légal ou contact urgence) ──
	// $requirement : 'required' (toujours obligatoire), 'conditional' (obligatoire si mineur,
	// géré en JS via data-repres-required), 'optional' (jamais obligatoire).
	private function render_personne_block( string $prefix, $index, array $values, string $requirement = 'optional' ): string {
		$idx  = $index === null ? '' : "[{$index}]";
		$name = static fn( string $field ): string => "{$prefix}{$idx}[{$field}]";
		$val  = static fn( string $field ): string => esc_attr( $values[ $field ] ?? '' );

		$show_star = in_array( $requirement, [ 'required', 'conditional' ], true );
		$req_star  = $show_star ? ' <span class="sp-req">*</span>' : '';
		$req_attr  = $requirement === 'required'    ? 'required' : '';
		$data_req  = $requirement === 'conditional' ? 'data-repres-required="1"' : '';

		$removable = $prefix === 'repres' && $index !== null && $index !== '__INDEX__' && (int) $index > 0;

		ob_start();
		?>
		<div class="sp-adh-personne-block">
			<?php if ( $removable ) : ?>
				<button type="button" class="sp-adh-personne-remove" aria-label="Retirer ce représentant">✕ Retirer</button>
			<?php endif; ?>
			<div class="sp-adh-row">
				<div class="sp-adh-col">
					<label>Statut<?= $req_star ?></label>
					<select name="<?= esc_attr( $name('statut') ) ?>" <?= $req_attr ?> <?= $data_req ?>>
						<option value="">— Choisir —</option>
						<?php foreach ( self::STATUTS_CONTACT as $key => $label ) : ?>
							<option value="<?= esc_attr( $key ) ?>" <?= ( $values['statut'] ?? '' ) === $key ? 'selected' : '' ?>><?= esc_html( $label ) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<div class="sp-adh-row">
				<div class="sp-adh-col">
					<label>Nom<?= $req_star ?></label>
					<input type="text" name="<?= esc_attr( $name('nom') ) ?>" value="<?= $val('nom') ?>" <?= $req_attr ?> <?= $data_req ?>>
				</div>
				<div class="sp-adh-col">
					<label>Prénom</label>
					<input type="text" name="<?= esc_attr( $name('prenom') ) ?>" value="<?= $val('prenom') ?>">
				</div>
			</div>
			<div class="sp-adh-row">
				<div class="sp-adh-col">
					<label>Téléphone<?= $req_star ?></label>
					<input type="text" name="<?= esc_attr( $name('telephone') ) ?>" value="<?= $val('telephone') ?>" <?= $req_attr ?> <?= $data_req ?>>
				</div>
				<div class="sp-adh-col">
					<label>Email</label>
					<input type="email" name="<?= esc_attr( $name('email') ) ?>" value="<?= $val('email') ?>">
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────
	private function valid_date( string $date ): bool {
		$d = \DateTime::createFromFormat( 'Y-m-d', $date );
		return $d instanceof \DateTime && $d->format( 'Y-m-d' ) === $date;
	}

	public static function statut_label( string $key ): string {
		return self::STATUTS_CONTACT[ $key ] ?? ( $key !== '' ? $key : '—' );
	}

	// ─── Création de la table ─────────────────────────────────────────────────
	public static function create_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'sp_adhesions_pending';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id                      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			nom                     VARCHAR(100)  NOT NULL,
			prenom                  VARCHAR(100)  NOT NULL,
			date_naissance          DATE          NOT NULL,
			sexe                    ENUM('M','F') NOT NULL,
			email                   VARCHAR(200)  NOT NULL,
			telephone               VARCHAR(30)   NOT NULL DEFAULT '',
			lieu_naissance          VARCHAR(150)  NOT NULL DEFAULT '',
			nationalite             VARCHAR(100)  NOT NULL DEFAULT '',
			adresse                 VARCHAR(255)  NOT NULL DEFAULT '',
			discipline              VARCHAR(50)   NOT NULL DEFAULT '',
			categorie               VARCHAR(50)   NOT NULL DEFAULT '',
			message                 TEXT,
			pass_sport_code         VARCHAR(20)   NOT NULL DEFAULT '',
			caf_bon                 TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			doc_bon_caf             VARCHAR(255)  NOT NULL DEFAULT '',
			representants_legaux    LONGTEXT,
			contact_urgence         LONGTEXT,
			renouvellement_eleve_id INT UNSIGNED  DEFAULT NULL,
			pratique_anterieure     TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			ancien_licence          VARCHAR(50)   NOT NULL DEFAULT '',
			ancien_passeport        VARCHAR(50)   NOT NULL DEFAULT '',
			ancien_grade            VARCHAR(100)  NOT NULL DEFAULT '',
			taille_cm               VARCHAR(10)   NOT NULL DEFAULT '',
			poids_kg                VARCHAR(10)   NOT NULL DEFAULT '',
			pointure                VARCHAR(10)   NOT NULL DEFAULT '',
			taille_tshirt           VARCHAR(20)   NOT NULL DEFAULT '',
			taille_pantalon         VARCHAR(20)   NOT NULL DEFAULT '',
			doc_certificat_medical  VARCHAR(255)  NOT NULL DEFAULT '',
			date_certificat_medical DATE          DEFAULT NULL,
			qs_sport_confirme       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			doc_attestation_rc      VARCHAR(255)  NOT NULL DEFAULT '',
			doc_decharge_honneur    VARCHAR(255)  NOT NULL DEFAULT '',
			photo_url               VARCHAR(255)  NOT NULL DEFAULT '',
			autorisation_photo      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			autorisation_seul       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			droit_image             TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			reglement_accepte       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			statut                  ENUM('pending','valide','refuse') NOT NULL DEFAULT 'pending',
			refus_motif             TEXT,
			ip_address              VARCHAR(45)   NOT NULL DEFAULT '',
			created_at              DATETIME      NOT NULL,
			updated_at              DATETIME      DEFAULT NULL,
			PRIMARY KEY             (id),
			KEY idx_statut          (statut),
			KEY idx_email           (email(50)),
			KEY idx_renouv          (renouvellement_eleve_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

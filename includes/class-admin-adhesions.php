<?php
/**
 * Admin : Page "Demandes d'adhésion"
 * Gestion des pré-inscriptions soumises via [sp_inscription_adhesion]
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Admin_Adhesions {

	private static ?self $instance = null;

	private string $table;
	private string $eleves_table;

	// ─── Singleton ───────────────────────────────────────────────────────────
	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table         = $wpdb->prefix . 'sp_adhesions_pending';
		$this->eleves_table  = $wpdb->prefix . 'sp_cal_eleves';

		add_action( 'admin_menu',                        [ $this, 'register_menu'   ], 20 );
		add_action( 'admin_post_sp_adhesion_valider',    [ $this, 'handle_valider'  ] );
		add_action( 'admin_post_sp_adhesion_refuser',    [ $this, 'handle_refuser'  ] );
	}

	// ─── Menu ─────────────────────────────────────────────────────────────────
	public function register_menu(): void {
		global $wpdb;

		$pending = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table} WHERE statut = 'pending'"
		);

		$label = $pending > 0
			? '📝 Demandes adhésion <span class="awaiting-mod">' . $pending . '</span>'
			: '📝 Demandes adhésion';

		add_submenu_page(
			'sp-cal-pro',
			"Demandes d'adhésion",
			$label,
			SP_Cal_Roles::CAP_GESTION_ADHESIONS,
			'sp_adhesions',
			[ $this, 'render_page' ]
		);
	}

	// ─── Page principale ─────────────────────────────────────────────────────
	public function render_page(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) {
			wp_die( 'Accès refusé.' );
		}

		$action = sanitize_key( $_GET['action'] ?? 'list' );
		$id     = absint( $_GET['id'] ?? 0 );

		echo '<div class="wrap sp-adh-admin">';
		echo '<h1 class="wp-heading-inline">📝 Demandes d\'adhésion</h1>';

		$this->render_notices();

		if ( $action === 'view' && $id > 0 ) {
			$this->render_view( $id );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	// ─── Notices flash ───────────────────────────────────────────────────────
	private function render_notices(): void {
		$notices = [
			'valide'      => [ 'success', '✅ Demande validée. Le compte élève a été créé et le token envoyé.' ],
			'refuse'      => [ 'success', '🚫 Demande refusée. L\'adhérent a été notifié par email.' ],
			'deja_valide' => [ 'warning', '⚠️ Cette demande est déjà traitée.' ],
			'error_db'    => [ 'error',   '❌ Erreur base de données lors de la création de l\'élève.' ],
			'error_nonce' => [ 'error',   '❌ Erreur de sécurité (nonce invalide). Veuillez réessayer.' ],
			'not_found'   => [ 'error',   '❌ Demande introuvable.' ],
		];

		$key = sanitize_key( $_GET['sp_notice'] ?? '' );
		if ( $key && isset( $notices[ $key ] ) ) {
			[ $type, $msg ] = $notices[ $key ];
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( $msg )
			);
		}

		// Afficher l'erreur DB détaillée si disponible
		if ( $key === 'error_db' ) {
			$db_id  = absint( $_GET['id'] ?? 0 );
			$db_err = $db_id ? get_transient( 'sp_adh_db_error_' . $db_id ) : '';
			if ( $db_err ) {
				printf(
					'<div class="notice notice-error"><p><strong>Détail erreur SQL :</strong> %s</p></div>',
					esc_html( $db_err )
				);
				delete_transient( 'sp_adh_db_error_' . $db_id );
			}
		}
	}

	// ══════════════════════════════════════════════════════════════════════════
	// LISTE
	// ══════════════════════════════════════════════════════════════════════════
	private function render_list(): void {
		global $wpdb;

		$allowed_statuts = [ 'pending', 'valide', 'refuse', 'all' ];
		$sf = sanitize_key( $_GET['statut'] ?? 'pending' );
		if ( ! in_array( $sf, $allowed_statuts, true ) ) $sf = 'pending';

		// Counts par statut
		$raw_counts = $wpdb->get_results(
			"SELECT statut, COUNT(*) AS n FROM {$this->table} GROUP BY statut",
			ARRAY_A
		);
		$counts = array_fill_keys( $allowed_statuts, 0 );
		foreach ( $raw_counts as $r ) {
			if ( isset( $counts[ $r['statut'] ] ) ) {
				$counts[ $r['statut'] ] = (int) $r['n'];
			}
			$counts['all'] += (int) $r['n'];
		}

		// Onglets
		$tabs = [
			'pending' => "⏳ En attente ({$counts['pending']})",
			'valide'  => "✅ Validées ({$counts['valide']})",
			'refuse'  => "🚫 Refusées ({$counts['refuse']})",
			'all'     => "Toutes ({$counts['all']})",
		];

		echo '<ul class="subsubsub">';
		foreach ( $tabs as $key => $label ) {
			$url    = esc_url( add_query_arg( [ 'page' => 'sp_adhesions', 'statut' => $key ], admin_url( 'admin.php' ) ) );
			$active = $sf === $key ? ' class="current" aria-current="page"' : '';
			echo "<li><a href='{$url}'{$active}>{$label}</a> &nbsp;</li>";
		}
		echo '</ul><br class="clear">';

		// Requête
		$where = $sf !== 'all'
			? $wpdb->prepare( 'WHERE statut = %s', $sf )
			: '';

		// SELECT * plutôt qu'une liste de colonnes explicite : si une colonne récente
		// (ex: renouvellement_eleve_id) n'a pas encore été migrée par dbDelta() sur cet
		// environnement, une liste nommée ferait échouer TOUTE la requête (donc la liste
		// entière disparaît) au lieu de simplement ignorer la colonne manquante.
		$rows = $wpdb->get_results(
			"SELECT * FROM {$this->table} {$where}
			 ORDER BY created_at DESC"
		);

		if ( empty( $rows ) ) {
			echo '<p style="margin-top:1.5rem;">Aucune demande' . ( $sf !== 'all' ? ' dans cette catégorie' : '' ) . '.</p>';
			return;
		}
		?>
		<table class="wp-list-table widefat fixed striped sp-adh-table" style="margin-top:1rem;">
			<thead>
				<tr>
					<th style="width:140px">Date</th>
					<th>Nom / Prénom</th>
					<th>Email</th>
					<th>Catégorie</th>
					<th>Discipline</th>
					<th style="width:130px">Statut</th>
					<th style="width:80px">Action</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) :
				$view_url = esc_url( add_query_arg( [
					'page'   => 'sp_adhesions',
					'action' => 'view',
					'id'     => $row->id,
				], admin_url( 'admin.php' ) ) );
				$est_renouv    = ! empty( $row->renouvellement_eleve_id );
				$certif_perime = $this->certificat_medical_perime( $row->date_certificat_medical ?? '' );
			?>
				<tr<?= $est_renouv ? ' style="background:#eef4fb;"' : '' ?>>
					<td><?= esc_html( date( 'd/m/Y H:i', strtotime( $row->created_at ) ) ) ?></td>
					<td>
						<strong><?= esc_html( "{$row->prenom} {$row->nom}" ) ?></strong>
						<?php if ( $est_renouv ) : ?>
							<br><span style="background:#2271b1;color:#fff;padding:1px 8px;border-radius:10px;font-size:.72em;font-weight:600;">🔄 Renouvellement</span>
						<?php endif; ?>
						<?php if ( $certif_perime ) : ?>
							<br><span style="background:#f59e0b;color:#fff;padding:1px 8px;border-radius:10px;font-size:.72em;font-weight:600;">⚠️ Certificat médical &gt; 1 an</span>
						<?php endif; ?>
					</td>
					<td><?= esc_html( $row->email ) ?></td>
					<td><?= esc_html( $row->categorie ) ?></td>
					<td><?= esc_html( $row->discipline ) ?></td>
					<td><?= $this->badge_statut( $row->statut ) ?></td>
					<td><a href="<?= $view_url ?>" class="button button-small">👁 Voir</a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// ══════════════════════════════════════════════════════════════════════════
	// FICHE DÉTAIL
	// ══════════════════════════════════════════════════════════════════════════
	private function render_view( int $id ): void {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
			$id
		) );

		if ( ! $row ) {
			echo '<div class="notice notice-error"><p>Demande introuvable.</p></div>';
			return;
		}

		$back = esc_url( add_query_arg( 'page', 'sp_adhesions', admin_url( 'admin.php' ) ) );
		echo "<p style='margin-top:1rem;'><a href='{$back}' class='button'>← Retour à la liste</a></p>";

		echo '<div class="sp-adh-fiche">';

		// Bandeau très visible côté admin quand c'est un renouvellement (cf. doléance renouvellement) :
		// évite qu'un gestionnaire valide "Créer le compte" par réflexe sur une fiche qui existe déjà.
		if ( ! empty( $row->renouvellement_eleve_id ) ) {
			$fiche_url = admin_url( 'admin.php?page=sp-cal-fiche-eleve&eleve_id=' . intval( $row->renouvellement_eleve_id ) );
			printf(
				'<div class="notice notice-info" style="border-left-color:#2271b1;padding:12px 16px;margin:1rem 0;">
					<p style="margin:0;font-size:14px;"><strong>🔄 Ceci est un RENOUVELLEMENT</strong> — cette demande met à jour une fiche existante, elle n\'en crée pas une nouvelle.
					<a href="%s" target="_blank" style="margin-left:8px;">Voir la fiche actuelle de l\'adhérent →</a></p>
				</div>',
				esc_url( $fiche_url )
			);
		}

		// Alerte non bloquante : certificat médical au-delà de la durée réglée par le bureau (cf. doleances.md).
		if ( $this->certificat_medical_perime( $row->date_certificat_medical ?? '' ) ) {
			$mois = max( 1, intval( get_option( 'sp_cal_certif_medical_mois', 12 ) ) );
			printf(
				'<div class="notice notice-warning" style="border-left-color:#f59e0b;padding:12px 16px;margin:1rem 0;">
					<p style="margin:0;font-size:14px;"><strong>⚠️ Certificat médical périmé</strong> — le certificat déposé date du %s, il dépasse la durée de validité réglée par le bureau (%d mois — page 🪪 Adhésions), conformément au règlement FFTDA pour le Taekwondo en compétition. Ceci n\'empêche pas de valider la demande, mais pensez à redemander un certificat à jour à l\'adhérent.</p>
				</div>',
				esc_html( date( 'd/m/Y', strtotime( $row->date_certificat_medical ) ) ),
				$mois
			);
		}

		printf(
			'<h2>Demande #%d — %s %s</h2>',
			$row->id,
			esc_html( $row->prenom ),
			esc_html( $row->nom )
		);
		echo $this->badge_statut( $row->statut );
		echo '<p class="sp-adh-meta">Soumise le ' . esc_html( date( 'd/m/Y à H:i', strtotime( $row->created_at ) ) ) . '</p>';

		// ── Sections de données ──────────────────────────────────────────────
		$representants = $this->decode_personnes( $row->representants_legaux ?? '' );
		$urgence       = $this->decode_personnes( $row->contact_urgence      ?? '', true );

		$sections = [
			'👤 Identité' => [
				'Nom'               => $row->nom,
				'Prénom'            => $row->prenom,
				'Date de naissance' => date( 'd/m/Y', strtotime( $row->date_naissance ) ),
				'Sexe'              => $row->sexe === 'M' ? 'Masculin' : 'Féminin',
				'Lieu de naissance' => $row->lieu_naissance ?: '—',
				'Nationalité'       => $row->nationalite    ?: '—',
				'Adresse'           => $row->adresse        ?: '—',
			],
			'📞 Contact' => [
				'Email'     => $row->email,
				'Téléphone' => $row->telephone ?: '—',
			],
		];

		foreach ( $sections as $title => $fields ) {
			echo "<div class='sp-adh-section'><h3>{$title}</h3><table class='form-table'>";
			foreach ( $fields as $label => $value ) {
				printf(
					'<tr><th scope="row">%s</th><td>%s</td></tr>',
					esc_html( $label ),
					esc_html( $value )
				);
			}
			echo '</table></div>';
		}

		// Représentants légaux : un bloc affiché par personne
		echo "<div class='sp-adh-section'><h3>👨‍👩‍👧 Représentant(s) légal/légaux</h3>";
		if ( empty( $representants ) ) {
			echo '<p>—</p>';
		} else {
			foreach ( $representants as $i => $p ) {
				printf( '<h4 style="margin:.6rem 0 .3rem;">Représentant %d</h4>', $i + 1 );
				echo '<table class="form-table">';
				printf( '<tr><th scope="row">Statut</th><td>%s</td></tr>', esc_html( SP_Front_Adhesion::statut_label( $p['statut'] ?? '' ) ) );
				printf( '<tr><th scope="row">Nom</th><td>%s</td></tr>', esc_html( trim( ( $p['nom'] ?? '' ) . ' ' . ( $p['prenom'] ?? '' ) ) ?: '—' ) );
				printf( '<tr><th scope="row">Téléphone</th><td>%s</td></tr>', esc_html( $p['telephone'] ?? '' ?: '—' ) );
				printf( '<tr><th scope="row">Email</th><td>%s</td></tr>', esc_html( $p['email'] ?? '' ?: '—' ) );
				echo '</table>';
			}
		}
		echo '</div>';

		// Contact d'urgence
		echo "<div class='sp-adh-section'><h3>🚨 Contact d'urgence</h3><table class='form-table'>";
		printf( '<tr><th scope="row">Statut</th><td>%s</td></tr>', esc_html( SP_Front_Adhesion::statut_label( $urgence['statut'] ?? '' ) ) );
		printf( '<tr><th scope="row">Nom</th><td>%s</td></tr>', esc_html( trim( ( $urgence['nom'] ?? '' ) . ' ' . ( $urgence['prenom'] ?? '' ) ) ?: '—' ) );
		printf( '<tr><th scope="row">Téléphone</th><td>%s</td></tr>', esc_html( $urgence['telephone'] ?? '' ?: '—' ) );
		printf( '<tr><th scope="row">Email</th><td>%s</td></tr>', esc_html( $urgence['email'] ?? '' ?: '—' ) );
		echo '</table></div>';

		$sections2 = [
			'🥋 Club' => [
				'Discipline'         => $row->discipline,
				'Catégorie d\'âge'   => $row->categorie,
				'Message'            => $row->message ?: '—',
				'Questionnaire QS-Sport' => $row->discipline === 'RENFO'
					? ( ($row->qs_sport_confirme ?? 0) ? '✅ Confirmé (toutes réponses négatives)' : ( $row->doc_certificat_medical ? 'Non — certificat médical fourni à la place' : '⚠️ Ni confirmé ni certificat fourni' ) )
					: '—',
			],
			'💳 Pass\'Sport / CAF' => [
				'Code Pass\'Sport déclaré' => $row->pass_sport_code ?: '—',
				'Bon CAF déclaré'          => ($row->caf_bon ?? 0) ? '✅ Oui (voir Documents ci-dessous)' : 'Non',
			],
			'🏅 Pratique antérieure' => [
				'Déjà pratiqué'      => ($row->pratique_anterieure ?? 0) ? '✅ Oui' : 'Non',
				'N° licence'         => $row->ancien_licence   ?: '—',
				'N° passeport FFTDA' => $row->ancien_passeport ?: '—',
				'Grade'              => $row->ancien_grade     ?: '—',
			],
			'📏 Mensurations' => [
				'Taille (cm)'       => $row->taille_cm       ?: '—',
				'Poids (kg)'        => $row->poids_kg        ?: '—',
				'Pointure'          => $row->pointure        ?: '—',
				'T-shirt / Sweat'   => $row->taille_tshirt   ?: '—',
				'Pantalon'          => $row->taille_pantalon ?: '—',
			],
		];

		foreach ( $sections2 as $title => $fields ) {
			echo "<div class='sp-adh-section'><h3>{$title}</h3><table class='form-table'>";
			foreach ( $fields as $label => $value ) {
				printf(
					'<tr><th scope="row">%s</th><td>%s</td></tr>',
					esc_html( $label ),
					esc_html( $value )
				);
			}
			echo '</table></div>';
		}

		// Documents déposés
		$docs = [
			'Photo de l\'adhérent'                    => $row->photo_url             ?? '',
			'Certificat médical'                     => $row->doc_certificat_medical ?? '',
			'Attestation de responsabilité civile'    => $row->doc_attestation_rc     ?? '',
			'Décharge sur l\'honneur'                 => $row->doc_decharge_honneur   ?? '',
			'Bon CAF'                                 => $row->doc_bon_caf            ?? '',
		];
		echo "<div class='sp-adh-section'><h3>📎 Documents</h3><table class='form-table'>";
		foreach ( $docs as $label => $url ) {
			if ( $label === 'Photo de l\'adhérent' && $url ) {
				$val = '<img src="' . esc_url( $url ) . '" style="width:56px;height:56px;object-fit:cover;border-radius:50%;border:1px solid #ddd;vertical-align:middle;margin-right:10px;">'
					. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Voir en grand</a>';
			} else {
				$val = $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">📄 Voir le document</a>' : '—';
			}
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), $val );
			if ( $label === 'Certificat médical' && ! empty( $row->date_certificat_medical ) && $row->date_certificat_medical !== '0000-00-00' ) {
				$certif_perime = $this->certificat_medical_perime( $row->date_certificat_medical );
				$date_val = esc_html( date( 'd/m/Y', strtotime( $row->date_certificat_medical ) ) )
					. ( $certif_perime ? ' — <strong style="color:#b45309;">⚠️ plus d\'un an (règlement FFTDA)</strong>' : ' — ✅ valide' );
				printf( '<tr><th scope="row">Date du certificat</th><td>%s</td></tr>', $date_val );
			}
		}
		echo '</table></div>';

		// Autorisations
		echo "<div class='sp-adh-section'><h3>📋 Autorisations</h3><table class='form-table'>";
		$autorisations = [
			'Photos / vidéos'    => ($row->autorisation_photo ?? 0) ? '✅ Autorisé' : '❌ Refusé',
			'Droit à l\'image'   => ($row->droit_image ?? 0)        ? '✅ Autorisé' : '❌ Refusé',
			'Repartir seul(e)'   => ($row->autorisation_seul ?? 0)  ? '✅ Autorisé' : '❌ Non (ou majeur — non applicable)',
			'Règlement accepté'  => ($row->reglement_accepte ?? 0)  ? '✅ Oui'      : '⚠️ Non',
		];
		foreach ( $autorisations as $label => $value ) {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</table></div>';

		// Motif de refus éventuel
		if ( $row->statut === 'refuse' && $row->refus_motif ) {
			echo '<div class="sp-adh-section sp-adh-section-refus">';
			echo '<h3>🗒 Motif de refus</h3>';
			echo '<p>' . nl2br( esc_html( $row->refus_motif ) ) . '</p>';
			echo '</div>';
		}

		// Actions (seulement si pending)
		if ( $row->statut === 'pending' ) {
			$this->render_actions( $row );
		}

		echo '</div>'; // .sp-adh-fiche
	}

	// ─── Boutons d'action ─────────────────────────────────────────────────────
	private function render_actions( object $row ): void {
		$valider_url = wp_nonce_url(
			add_query_arg(
				[ 'action' => 'sp_adhesion_valider', 'id' => $row->id ],
				admin_url( 'admin-post.php' )
			),
			"sp_valider_{$row->id}"
		);
		$est_renouv = ! empty( $row->renouvellement_eleve_id );
		?>
		<div class="sp-adh-actions">
			<h3>⚡ Actions</h3>

			<!-- Valider -->
			<div class="sp-adh-action-block sp-adh-action-valider">
				<h4>✅ <?= $est_renouv ? 'Valider le renouvellement' : 'Valider la demande' ?></h4>
				<p>
					<?php if ( $est_renouv ) : ?>
						La fiche élève existante sera mise à jour (informations, saison) et réactivée.
						Le paiement reste à gérer manuellement.
					<?php else : ?>
						Un profil élève sera créé dans la base de données et un token d'accès
						sera envoyé par email à <strong><?= esc_html( $row->email ) ?></strong>.
						Le paiement reste à gérer manuellement.
					<?php endif; ?>
				</p>
				<a href="<?= esc_url( $valider_url ) ?>"
				   class="button button-primary"
				   onclick="return confirm('<?= $est_renouv
				       ? esc_js( "Valider le renouvellement de {$row->prenom} {$row->nom} et mettre à jour sa fiche ?" )
				       : esc_js( "Valider la demande de {$row->prenom} {$row->nom} et créer son compte élève ?" ) ?>')">
					<?= $est_renouv ? '✅ Valider le renouvellement & mettre à jour la fiche' : '✅ Valider & créer le compte' ?>
				</a>
			</div>

			<!-- Refuser -->
			<div class="sp-adh-action-block sp-adh-action-refuser">
				<h4>🚫 Refuser la demande</h4>
				<form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>">
					<input type="hidden" name="action" value="sp_adhesion_refuser">
					<input type="hidden" name="id"     value="<?= intval( $row->id ) ?>">
					<?php wp_nonce_field( "sp_refuser_{$row->id}", 'sp_refus_nonce' ); ?>
					<label for="sp_refus_motif">
						Motif du refus
						<span style="font-weight:400;font-size:.85em;">(sera inclus dans l'email à l'adhérent — optionnel)</span>
					</label><br>
					<textarea name="refus_motif" id="sp_refus_motif" rows="4"
					          style="width:100%;max-width:600px;margin:.4rem 0;"
					          placeholder="Ex : La catégorie d'âge ne correspond pas à la discipline choisie."></textarea>
					<br>
					<button type="submit" class="button button-secondary"
					        onclick="return confirm('Refuser définitivement cette demande ?')">
						🚫 Refuser la demande
					</button>
				</form>
			</div>

		</div>
		<?php
	}

	// ══════════════════════════════════════════════════════════════════════════
	// HANDLER — VALIDER
	// ══════════════════════════════════════════════════════════════════════════
	public function handle_valider(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );

		$id = absint( $_GET['id'] ?? 0 );

		if ( ! check_admin_referer( "sp_valider_{$id}" ) ) {
			wp_redirect( $this->list_url( 'error_nonce' ) );
			exit;
		}

		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
			$id
		) );

		if ( ! $row ) {
			wp_redirect( $this->list_url( 'not_found' ) );
			exit;
		}

		if ( $row->statut !== 'pending' ) {
			wp_redirect( $this->list_url( 'deja_valide' ) );
			exit;
		}

		// Créer le membre, ou mettre à jour la fiche existante s'il s'agit d'un renouvellement
		// (cf. md/06-renouvellement-saison.md — ne jamais dupliquer la fiche d'un adhérent existant)
		$est_renouv = ! empty( $row->renouvellement_eleve_id );
		$member_id  = $est_renouv
			? $this->update_member_renouvellement( $row )
			: $this->create_member( $row );

		if ( ! $member_id ) {
			// Stocker l'erreur DB pour l'afficher sur la fiche
			set_transient( 'sp_adh_db_error_' . $id, $wpdb->last_error, 60 );
			wp_redirect( $this->view_url( $id, 'error_db' ) );
			exit;
		}

		// Marquer validée
		$wpdb->update(
			$this->table,
			[ 'statut' => 'valide', 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ], [ '%d' ]
		);

		// Email de confirmation : contenu différent pour un renouvellement (le compte existe déjà)
		if ( $est_renouv ) {
			$this->email_renouvellement_confirme( $row );
		} else {
			$this->send_token_or_welcome( $row, $member_id );
		}

		// Rediriger vers l'onglet "Validées" pour voir la demande traitée
		wp_redirect( $this->list_url( 'valide', 'valide' ) );
		exit;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// HANDLER — REFUSER
	// ══════════════════════════════════════════════════════════════════════════
	public function handle_refuser(): void {
		if ( ! current_user_can( SP_Cal_Roles::CAP_GESTION_ADHESIONS ) ) wp_die( 'Accès refusé.' );

		$id          = absint( $_POST['id'] ?? 0 );
		$refus_motif = sanitize_textarea_field( $_POST['refus_motif'] ?? '' );

		if ( ! check_admin_referer( "sp_refuser_{$id}", 'sp_refus_nonce' ) ) {
			wp_redirect( $this->list_url( 'error_nonce' ) );
			exit;
		}

		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
			$id
		) );

		if ( ! $row ) {
			wp_redirect( $this->list_url( 'not_found' ) );
			exit;
		}

		if ( $row->statut !== 'pending' ) {
			wp_redirect( $this->list_url( 'deja_valide' ) );
			exit;
		}

		$wpdb->update(
			$this->table,
			[
				'statut'      => 'refuse',
				'refus_motif' => $refus_motif,
				'updated_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s' ], [ '%d' ]
		);

		$this->email_refus( $row, $refus_motif );

		// Rediriger vers l'onglet "Refusées" pour voir la demande traitée
		wp_redirect( $this->list_url( 'refuse', 'refuse' ) );
		exit;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// DÉCODAGE DES BLOCS "PERSONNE" (représentants légaux / contact urgence)
	// ══════════════════════════════════════════════════════════════════════════
	// $single = true pour un contact d'urgence (JSON = un seul objet), false pour
	// une liste de représentants (JSON = tableau d'objets).
	private function decode_personnes( string $json, bool $single = false ): array {
		if ( $json === '' ) return $single ? [] : [];
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) return $single ? [] : [];
		if ( $single ) {
			// Ancien format éventuel (tableau à un élément) ou objet direct
			return isset( $decoded['statut'] ) || isset( $decoded['nom'] ) ? $decoded : ( $decoded[0] ?? [] );
		}
		return $decoded;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// CRÉATION MEMBRE dans sp_cal_eleves
	// ══════════════════════════════════════════════════════════════════════════
	private function create_member( object $row ): int|false {
		global $wpdb;

		$annee_naissance = substr( $row->date_naissance, 0, 4 );

		$saison = get_option( 'tkd_saison_courante', '' );
		if ( ! $saison ) {
			$y = (int) date('Y'); $m = (int) date('m');
			$saison = $m >= 9 ? "{$y}/" . ($y+1) : ($y-1) . "/{$y}";
		}

		$representants = $this->decode_personnes( $row->representants_legaux ?? '' );
		$urgence       = $this->decode_personnes( $row->contact_urgence      ?? '', true );
		$repres1       = $representants[0] ?? [];

		$docs = [
			'certificat_medical' => $row->doc_certificat_medical ?? '',
			'date_certificat_medical' => $row->date_certificat_medical ?? '',
			'attestation_rc'     => $row->doc_attestation_rc     ?? '',
			'decharge_honneur'   => $row->doc_decharge_honneur   ?? '',
			'bon_caf'            => $row->doc_bon_caf            ?? '',
		];

		// L'essentiel de la structure "1 à 2 représentants" est conservé intégralement en JSON
		// dans extra_data en attendant la refonte du modèle de données (cf. md/02-etat-des-lieux.md) :
		// sp_cal_eleves reste une table à plat, on y range donc le 1er représentant pour compat,
		// et la liste complète (jusqu'à 2) pour ne rien perdre.
		$extra = [
			'sexe'                 => $row->sexe,
			'autorisation_photo'   => (bool) ($row->autorisation_photo ?? 0),
			'autorisation_seul'    => (bool) ($row->autorisation_seul  ?? 0),
			'reglement_accepte'    => (bool) ($row->reglement_accepte  ?? 0),
			'pratique_anterieure'  => (bool) ($row->pratique_anterieure ?? 0),
			'ancien_licence'       => $row->ancien_licence       ?? '',
			'ancien_passeport'     => $row->ancien_passeport     ?? '',
			'message_adhesion'     => $row->message              ?? '',
			'pass_sport_code'      => $row->pass_sport_code      ?? '',
			'date_certificat_medical' => $row->date_certificat_medical ?? '',
			'qs_sport_confirme'    => (bool) ($row->qs_sport_confirme  ?? 0),
			'representants_legaux' => $representants,
			'contact_urgence'      => $urgence,
			'documents'            => $docs,
			'source'               => 'adhesion_form',
			'adhesion_id'          => $row->id,
		];

		$data = [
			'nom'                    => $row->nom,
			'prenom'                 => $row->prenom,
			'date_naissance'         => $row->date_naissance,
			'annee_naissance'        => $annee_naissance,
			'lieu_naissance'         => $row->lieu_naissance    ?? '',
			'nationalite'            => $row->nationalite       ?? '',
			'adresse'                => $row->adresse           ?? '',
			'categorie_age'          => $row->categorie,
			'categorie_saisie'       => $row->discipline,
			'grade'                  => $row->ancien_grade      ?? '',
			'saison'                 => $saison,
			'email'                  => $row->email,
			'email_parent'           => $repres1['email']       ?? '',
			'telephone'              => $row->telephone,
			'urgence_nom'            => trim( ( $urgence['nom'] ?? '' ) . ' ' . ( $urgence['prenom'] ?? '' ) ),
			'urgence_prenom'         => '',
			'urgence_telephone'      => $urgence['telephone']   ?? '',
			'urgence_email'          => $urgence['email']       ?? '',
			'num_passeport'          => $row->ancien_passeport  ?? '',
			'licence'                => $row->ancien_licence    ?? '',
			'droit_image'            => (int) ($row->droit_image ?? 0),
			'autorisation_seul'      => (int) ($row->autorisation_seul ?? 0),
			'representant_nom'       => $repres1['nom']         ?? '',
			'representant_prenom'    => $repres1['prenom']      ?? '',
			'representant_telephone' => $repres1['telephone']   ?? '',
			'taille_cm'              => $row->taille_cm         ?? '',
			'poids_kg'               => $row->poids_kg          ?? '',
			'pointure'               => $row->pointure          ?? '',
			'taille_tshirt'          => $row->taille_tshirt     ?? '',
			'taille_pantalon'        => $row->taille_pantalon   ?? '',
			'motif_inactif'          => '',
			'palmares'               => '',
			'photo_url'              => $row->photo_url ?? '',
			'actif'                  => 0,
			'rang'                   => 0,
			'nb_licences'            => 0,
			'eligible_dan'           => 0,
			'extra_data'             => wp_json_encode( $extra ),
		];

		$format = [
			'%s','%s','%s','%s','%s','%s','%s',
			'%s','%s','%s','%s',
			'%s','%s','%s',
			'%s','%s','%s','%s',
			'%s','%s',
			'%d',  // droit_image
			'%d',  // autorisation_seul
			'%s','%s','%s',
			'%s','%s','%s','%s','%s',
			'%s','%s','%s',
			'%d','%d','%d','%d',
			'%s',
		];

		// Filtrage défensif contre le schéma réel — cf. même correctif dans
		// SP_Front_Adhesion::handle_submission() : une colonne pas encore migrée sur cet
		// hébergement (dbDelta() en échec silencieux, droits ALTER TABLE) ne doit plus faire
		// échouer toute la création du compte, juste être ignorée (et journalisée).
		$existing_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$this->eleves_table}" );
		$filtered_data   = [];
		$filtered_format = [];
		$i = 0;
		foreach ( $data as $col => $val ) {
			if ( in_array( $col, $existing_cols, true ) ) {
				$filtered_data[ $col ] = $val;
				$filtered_format[]     = $format[ $i ];
			} else {
				error_log( "[SP_Build] Colonne '{$col}' absente de {$this->eleves_table} — ignorée à la création du membre. Vérifier les droits ALTER TABLE de l'utilisateur MySQL sur cet hébergement." );
			}
			$i++;
		}

		$inserted = $wpdb->insert( $this->eleves_table, $filtered_data, $filtered_format );

		if ( ! $inserted ) {
			error_log( '[SP_Build] create_member() DB error: ' . $wpdb->last_error );
			return false;
		}

		return $wpdb->insert_id;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// MISE À JOUR MEMBRE — renouvellement (ne crée jamais de doublon)
	// ══════════════════════════════════════════════════════════════════════════
	private function update_member_renouvellement( object $row ): int|false {
		global $wpdb;

		$eleve_id = (int) $row->renouvellement_eleve_id;
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->eleves_table} WHERE id = %d", $eleve_id ) );
		if ( ! $existing ) {
			error_log( '[SP_Build] update_member_renouvellement() : fiche élève #' . $eleve_id . ' introuvable.' );
			return false;
		}

		$saison_cible = get_option( 'sp_cal_renouv_saison_cible', '' );
		if ( $saison_cible === '' ) {
			// Renouvellement hors campagne formelle (ex: adhérent revenu spontanément) : même
			// calcul de secours que pour une nouvelle inscription.
			$y = (int) date( 'Y' ); $m = (int) date( 'm' );
			$saison_cible = $m >= 9 ? "{$y}/" . ( $y + 1 ) : ( $y - 1 ) . "/{$y}";
		}

		$representants = $this->decode_personnes( $row->representants_legaux ?? '' );
		$urgence       = $this->decode_personnes( $row->contact_urgence      ?? '', true );
		$repres1       = $representants[0] ?? [];

		$docs = [
			'certificat_medical' => $row->doc_certificat_medical ?? '',
			'date_certificat_medical' => $row->date_certificat_medical ?? '',
			'attestation_rc'     => $row->doc_attestation_rc     ?? '',
			'decharge_honneur'   => $row->doc_decharge_honneur   ?? '',
			'bon_caf'            => $row->doc_bon_caf            ?? '',
		];

		// On fusionne avec l'extra_data existant pour ne jamais écraser un historique sans rapport
		// avec l'adhésion (ex: grades importés en CSV, cf. class-admin-members.php:2761).
		$extra_existant = json_decode( $existing->extra_data ?? '', true );
		$extra_existant = is_array( $extra_existant ) ? $extra_existant : [];

		$extra = array_merge( $extra_existant, [
			'sexe'                       => $row->sexe,
			'autorisation_photo'         => (bool) ( $row->autorisation_photo ?? 0 ),
			'autorisation_seul'          => (bool) ( $row->autorisation_seul  ?? 0 ),
			'reglement_accepte'          => (bool) ( $row->reglement_accepte  ?? 0 ),
			'pratique_anterieure'        => (bool) ( $row->pratique_anterieure ?? 0 ),
			'ancien_licence'             => $row->ancien_licence   ?? '',
			'ancien_passeport'           => $row->ancien_passeport ?? '',
			'message_adhesion'           => $row->message          ?? '',
			'pass_sport_code'            => $row->pass_sport_code  ?? '',
			'qs_sport_confirme'          => (bool) ( $row->qs_sport_confirme ?? 0 ),
			'representants_legaux'       => $representants,
			'contact_urgence'            => $urgence,
			'documents'                  => $docs,
			'source'                     => 'adhesion_form_renouvellement',
			'adhesion_id'                => $row->id,
			'derniere_saison_renouvelee' => $saison_cible,
		] );

		$data = [
			'nom'                    => $row->nom,
			'prenom'                 => $row->prenom,
			'date_naissance'         => $row->date_naissance,
			'annee_naissance'        => substr( $row->date_naissance, 0, 4 ),
			'lieu_naissance'         => $row->lieu_naissance    ?? '',
			'nationalite'            => $row->nationalite       ?? '',
			'adresse'                => $row->adresse           ?? '',
			'categorie_age'          => $row->categorie,
			'categorie_saisie'       => $row->discipline,
			'saison'                 => $saison_cible,
			'email'                  => $row->email,
			'email_parent'           => $repres1['email']       ?? '',
			'telephone'              => $row->telephone,
			'urgence_nom'            => trim( ( $urgence['nom'] ?? '' ) . ' ' . ( $urgence['prenom'] ?? '' ) ),
			'urgence_telephone'      => $urgence['telephone']   ?? '',
			'urgence_email'          => $urgence['email']       ?? '',
			'num_passeport'          => $row->ancien_passeport  ?? '',
			'licence'                => $row->ancien_licence    ?? '',
			'droit_image'            => (int) ( $row->droit_image ?? 0 ),
			'autorisation_seul'      => (int) ( $row->autorisation_seul ?? 0 ),
			'representant_nom'       => $repres1['nom']         ?? '',
			'representant_prenom'    => $repres1['prenom']      ?? '',
			'representant_telephone' => $repres1['telephone']   ?? '',
			'taille_cm'              => $row->taille_cm         ?? '',
			'poids_kg'               => $row->poids_kg          ?? '',
			'pointure'               => $row->pointure          ?? '',
			'taille_tshirt'          => $row->taille_tshirt     ?? '',
			'taille_pantalon'        => $row->taille_pantalon   ?? '',
			'motif_inactif'          => '',
			// Ne remplace la photo existante que si l'adhérent en a re-déposé une (renouvellement =
			// facultatif ici) — sinon on garde celle déjà présente sur la fiche.
			'photo_url'              => ( $row->photo_url ?? '' ) !== '' ? $row->photo_url : ( $existing->photo_url ?? '' ),
			// Valider la demande n'active pas le compte : comme pour une premiere adhesion
			// (create_member() ci-dessus), le bureau doit d'abord verifier les pieces (certificat
			// medical a jour, etc.) puis cocher "Actif" a la main sur la fiche - decision confirmee
			// le 12/09/2026, cf. doleance cotisations/vue globale.
			'actif'                  => 0,
			'extra_data'             => wp_json_encode( $extra ),
		];

		$format = [
			'%s','%s','%s','%s',
			'%s','%s','%s',
			'%s','%s','%s',
			'%s','%s','%s',
			'%s','%s','%s',
			'%s','%s',
			'%d','%d',
			'%s','%s','%s',
			'%s','%s','%s','%s','%s',
			'%s',
			'%s',
			'%d',
			'%s',
		];

		// Filtrage défensif contre le schéma réel — même correctif que create_member() /
		// SP_Front_Adhesion::handle_submission() : une colonne pas encore migrée ne doit plus
		// faire échouer tout le renouvellement, juste être ignorée (et journalisée).
		$existing_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$this->eleves_table}" );
		$filtered_data   = [];
		$filtered_format = [];
		$i = 0;
		foreach ( $data as $col => $val ) {
			if ( in_array( $col, $existing_cols, true ) ) {
				$filtered_data[ $col ] = $val;
				$filtered_format[]     = $format[ $i ];
			} else {
				error_log( "[SP_Build] Colonne '{$col}' absente de {$this->eleves_table} — ignorée lors du renouvellement. Vérifier les droits ALTER TABLE de l'utilisateur MySQL sur cet hébergement." );
			}
			$i++;
		}

		$ok = $wpdb->update( $this->eleves_table, $filtered_data, [ 'id' => $eleve_id ], $filtered_format, [ '%d' ] );

		if ( $ok === false ) {
			error_log( '[SP_Build] update_member_renouvellement() DB error: ' . $wpdb->last_error );
			return false;
		}

		return $eleve_id;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// EMAILS
	// ══════════════════════════════════════════════════════════════════════════
	private function email_renouvellement_confirme( object $row ): void {
		$club    = get_bloginfo( 'name' );
		$saison  = get_option( 'sp_cal_renouv_saison_cible', '' );
		$subject = "[{$club}] Votre renouvellement est confirmé !";
		$body    = "Bonjour {$row->prenom},\n\n"
		         . "Votre renouvellement d'adhésion au club {$club}" . ( $saison ? " pour la saison {$saison}" : '' ) . " a bien été validé.\n\n"
		         . "Votre accès à l'espace personnel est de nouveau actif.\n\n"
		         . "Pensez à régler votre cotisation lors de votre prochaine venue si ce n'est pas déjà fait.\n\n"
		         . "À bientôt sur les tatamis !\n"
		         . "— L'équipe {$club}";

		wp_mail( $row->email, $subject, $body );
	}


	private function send_token_or_welcome( object $row, int $member_id ): void {
		// Intégration SP_Token si disponible
		if ( class_exists( 'SP_Token' ) && method_exists( 'SP_Token', 'generate_and_send' ) ) {
			SP_Token::generate_and_send( $member_id, $row->email );
			return;
		}

		// Fallback : email de bienvenue simple
		$club    = get_bloginfo( 'name' );
		$subject = "[{$club}] Votre adhésion est validée !";
		$body    = "Bonjour {$row->prenom},\n\n"
		         . "Votre demande d'adhésion au club {$club} a été validée !\n\n"
		         . "Votre fiche est maintenant active. "
		         . "Vous recevrez prochainement vos informations de connexion.\n\n"
		         . "Pensez à régler votre cotisation lors de votre prochaine venue.\n\n"
		         . "À bientôt sur les tatamis !\n"
		         . "— L'équipe {$club}";

		wp_mail( $row->email, $subject, $body );
	}

	private function email_refus( object $row, string $motif ): void {
		$club    = get_bloginfo( 'name' );
		$subject = "[{$club}] Votre demande d'adhésion";
		$motif_txt = $motif
			? "\n\nMotif :\n{$motif}\n"
			: '';

		$body = "Bonjour {$row->prenom},\n\n"
		      . "Nous avons bien examiné votre demande d'adhésion au club {$club}.\n\n"
		      . "Malheureusement, nous ne pouvons pas y donner suite pour le moment.{$motif_txt}\n"
		      . "N'hésitez pas à nous contacter pour plus d'informations ou pour soumettre une nouvelle demande.\n\n"
		      . "Cordialement,\n"
		      . "— L'équipe {$club}";

		wp_mail( $row->email, $subject, $body );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// HELPERS URL / AFFICHAGE
	// ══════════════════════════════════════════════════════════════════════════
	private function list_url( string $notice = '', string $statut = '' ): string {
		$args = [ 'page' => 'sp_adhesions' ];
		if ( $notice ) $args['sp_notice'] = $notice;
		if ( $statut ) $args['statut']    = $statut;
		return admin_url( add_query_arg( $args, 'admin.php' ) );
	}

	private function view_url( int $id, string $notice = '' ): string {
		$args = [ 'page' => 'sp_adhesions', 'action' => 'view', 'id' => $id ];
		if ( $notice ) $args['sp_notice'] = $notice;
		return admin_url( add_query_arg( $args, 'admin.php' ) );
	}

	private function badge_statut( string $statut ): string {
		$badges = [
			'pending' => '<span style="background:#f0ad4e;color:#fff;padding:2px 8px;border-radius:3px;font-size:.8em;">⏳ En attente</span>',
			'valide'  => '<span style="background:#46b450;color:#fff;padding:2px 8px;border-radius:3px;font-size:.8em;">✅ Validée</span>',
			'refuse'  => '<span style="background:#dc3232;color:#fff;padding:2px 8px;border-radius:3px;font-size:.8em;">🚫 Refusée</span>',
		];
		return $badges[ $statut ] ?? esc_html( $statut );
	}

	// Certificat médical Taekwondo : durée de validité configurable par le bureau
	// (page 🪪 Adhésions, option sp_cal_certif_medical_mois, 12 par défaut — cf. règlement
	// FFTDA). Contrôle non bloquant (cf. doleances.md). Calculé à l'affichage, jamais figé en base.
	private function certificat_medical_perime( string $date ): bool {
		if ( $date === '' || $date === '0000-00-00' ) return false;
		$ts = strtotime( $date );
		$mois = max( 1, intval( get_option( 'sp_cal_certif_medical_mois', 12 ) ) );
		return $ts && $ts < strtotime( "-{$mois} months" );
	}
}

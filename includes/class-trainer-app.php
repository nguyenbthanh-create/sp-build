<?php
/**
 * PWA "Disponibilités" pour les entraîneurs — shortcode [sp_cal_dispo_app].
 *
 * Écran mobile très simplifié pour qu'un entraîneur déclare lui-même ses dispos, sans
 * passer par le tableau de bord wp-admin (jugé "usine à gaz" pour ce public, cf.
 * doleances.md 09/09/2026) et sans identifiants à retaper (lien personnel à token, sur
 * le même principe que SpCalPro_Token pour la fiche adhérent — voir class-token.php).
 *
 * Écrit dans la même table que l'admin (SpCalPro_DB::save_dispo()) : une dispo posée
 * depuis cette app apparaît immédiatement dans le calendrier admin, une seule source
 * de vérité. Toutes les dispos de l'équipe sont visibles (pas seulement les siennes) —
 * un entraîneur ne peut modifier que sa propre ligne, jamais celle d'un collègue.
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SP_Cal_Trainer_App {

	private static ?self $instance = null;
	private $db;

	public static function get_instance( $db = null ): self {
		if ( self::$instance === null ) self::$instance = new self( $db );
		return self::$instance;
	}

	private function __construct( $db = null ) {
		$this->db = $db;

		add_shortcode( 'sp_cal_dispo_app', [ $this, 'render' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_add_token_columns' ] );

		add_action( 'wp_ajax_sp_cal_dispo_app_data',        [ $this, 'ajax_get_data' ] );
		add_action( 'wp_ajax_nopriv_sp_cal_dispo_app_data', [ $this, 'ajax_get_data' ] );
		add_action( 'wp_ajax_sp_cal_dispo_app_save',        [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nopriv_sp_cal_dispo_app_save', [ $this, 'ajax_save' ] );

		add_action( 'wp_ajax_sp_cal_send_dispo_app_link', [ $this, 'ajax_send_link' ] );
	}

	// ─── Schéma : colonnes token sur la table entraîneurs ──────────────────────
	// Colonnes ajoutées directement via ALTER TABLE (pas dbDelta) après vérification
	// SHOW COLUMNS — dbDelta s'est montré peu fiable sur cet hébergement pour ajouter
	// des colonnes à une table existante (cf. doleances.md 08-09/09/2026), donc on
	// évite cette couche intermédiaire pour cette nouvelle fonctionnalité.
	public static function maybe_add_token_columns(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'sp_cal_trainers';

		$existing = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		if ( empty( $existing ) ) return; // table pas encore créée (activation du plugin pas encore passée)

		if ( ! in_array( 'token', $existing, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN token varchar(64) NOT NULL DEFAULT ''" );
		}
		if ( ! in_array( 'token_sent_at', $existing, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN token_sent_at datetime DEFAULT NULL" );
		}

		$existing_after = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		foreach ( [ 'token', 'token_sent_at' ] as $col ) {
			if ( ! in_array( $col, $existing_after, true ) ) {
				error_log( "[SP_Build] Colonne '{$col}' absente de {$table} après tentative d'ajout — vérifier les droits ALTER TABLE de l'utilisateur MySQL sur cet hébergement." );
			}
		}
	}

	// ─── Résolution d'un entraîneur depuis son token ───────────────────────────
	private function trainer_by_token( string $token ): ?object {
		if ( $token === '' ) return null;
		global $wpdb;
		$tt = $this->db->table_trainers();
		$el = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tt} WHERE token = %s AND token != ''", $token ) );
		return $el ?: null;
	}

	// ─── Génération + envoi du lien personnel ──────────────────────────────────
	public function generate_and_send( int $trainer_id, bool $force = false ) {
		global $wpdb;
		$tt = $this->db->table_trainers();
		$t  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tt} WHERE id=%d", $trainer_id ) );
		if ( ! $t || empty( $t->email ) ) return false;

		if ( ! $force && $t->token && $t->token_sent_at ) return false;

		$token = $t->token ?: bin2hex( random_bytes( 32 ) );
		$wpdb->update( $tt, [ 'token' => $token, 'token_sent_at' => current_time( 'mysql' ) ], [ 'id' => $trainer_id ] );

		$club    = get_option( 'blogname', 'Club' );
		$app_url = add_query_arg( 'token', $token, trailingslashit( $this->url_app() ) );

		$subject = '[' . $club . '] Vos disponibilités entraîneur';
		$body    = "Bonjour " . ( $t->nom_public ?: $t->nom ) . ",\n\n"
		         . "Voici votre lien personnel pour déclarer vos disponibilités au " . $club . " :\n\n"
		         . $app_url . "\n\n"
		         . "Vous y verrez aussi les disponibilités déjà posées par les autres entraîneurs.\n\n"
		         . "Sur iPhone : bouton Partager puis \"Sur l'écran d'accueil\".\n"
		         . "Sur Android : menu ⋮ de Chrome puis \"Ajouter à l'écran d'accueil\".\n\n"
		         . "Ce lien est personnel — ne pas le partager.\n\n"
		         . "-- \n" . $club;

		return wp_mail( sanitize_email( $t->email ), $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}

	public function ajax_send_link(): void {
		check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Accès refusé', 403 );

		$trainer_id = intval( $_POST['trainer_id'] ?? 0 );
		if ( ! $trainer_id ) wp_send_json_error( 'ID manquant', 400 );

		$sent = $this->generate_and_send( $trainer_id, true );
		if ( $sent ) {
			wp_send_json_success( 'Lien envoyé.' );
		} else {
			wp_send_json_error( 'Échec de l\'envoi (email manquant ou erreur d\'envoi).' );
		}
	}

	/** URL de la page publique portant le shortcode [sp_cal_dispo_app]. */
	private function url_app(): string {
		global $wpdb;
		$page_id = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE '%[sp_cal_dispo_app%' LIMIT 1"
		);
		return $page_id ? get_permalink( $page_id ) : home_url( '/dispo-entraineurs/' );
	}

	// ─── AJAX : données du mois ─────────────────────────────────────────────────
	public function ajax_get_data(): void {
		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$me    = $this->trainer_by_token( $token );
		if ( ! $me ) wp_send_json_error( 'Lien invalide ou expiré.' );

		$year  = intval( $_POST['year']  ?? date( 'Y' ) );
		$month = intval( $_POST['month'] ?? date( 'n' ) );

		$trainers = $this->db->get_trainers_entraineurs( true );
		$trainers_out = [];
		foreach ( $trainers as $t ) {
			$trainers_out[] = [
				'id'         => intval( $t->id ),
				'nom'        => $t->nom,
				'nom_public' => $t->nom_public ?: $t->nom,
			];
		}

		wp_send_json_success( [
			'me'       => [ 'id' => intval( $me->id ), 'nom_public' => $me->nom_public ?: $me->nom ],
			'trainers' => $trainers_out,
			'dispos'   => $this->db->get_dispos_for_month( $year, $month ),
			'year'     => $year,
			'month'    => $month,
		] );
	}

	// ─── AJAX : enregistrer SA PROPRE dispo ─────────────────────────────────────
	public function ajax_save(): void {
		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$me    = $this->trainer_by_token( $token );
		if ( ! $me ) wp_send_json_error( 'Lien invalide ou expiré.' );

		$date       = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		$dispo_raw  = $_POST['disponible'] ?? '';
		$note       = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );

		if ( ! $date ) wp_send_json_error( 'Date manquante.' );

		$disponible = ( $dispo_raw === '' ) ? null : intval( $dispo_raw );

		// Un entraîneur ne peut jamais écrire que sur SA PROPRE ligne — le trainer_id
		// vient du token résolu côté serveur, jamais d'un paramètre envoyé par le client.
		$this->db->save_dispo( intval( $me->id ), $date, $disponible, $note );

		wp_send_json_success( [ 'date' => $date, 'disponible' => $disponible, 'note' => $note ] );
	}

	// ─── Rendu de l'app ──────────────────────────────────────────────────────────
	public function render( $atts ): string {
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		$me    = $this->trainer_by_token( $token );

		ob_start();

		if ( ! $me ) : ?>
			<div style="max-width:420px;margin:40px auto;padding:24px;text-align:center;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
				<p style="font-size:15px;color:#374151;">🔒 Ce lien est invalide ou a expiré.</p>
				<p style="font-size:13px;color:#9ca3af;">Contactez le club pour recevoir un nouveau lien.</p>
			</div>
		<?php
			return ob_get_clean();
		endif;

		$club = esc_html( get_option( 'blogname', 'Club' ) );
		?>
		<div id="sp-dispo-app" data-token="<?php echo esc_attr( $token ); ?>">
			<style>
			#sp-dispo-app { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 480px; margin: 0 auto; color: #111827; }
			#sp-dispo-app * { box-sizing: border-box; }
			.sda-header { background: #1e3a5f; color: #fff; padding: 16px 18px; border-radius: 12px 12px 0 0; }
			.sda-header-club { font-size: 12px; opacity: .7; text-transform: uppercase; letter-spacing: .05em; }
			.sda-header-me { font-size: 17px; font-weight: 700; margin-top: 2px; }
			.sda-nav { display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border: 1px solid #e2e8f0; border-top: none; padding: 10px 14px; }
			.sda-nav button { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; width: 40px; height: 40px; font-size: 18px; cursor: pointer; }
			.sda-nav-title { font-size: 15px; font-weight: 700; text-transform: capitalize; }
			.sda-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; padding: 12px; background: #fff; border: 1px solid #e2e8f0; border-top: none; }
			.sda-dow { text-align: center; font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; padding-bottom: 4px; }
			.sda-cell { position: relative; aspect-ratio: 1; border-radius: 8px; border: 2px solid transparent; background: #f8fafc; display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; font-weight: 600; }
			.sda-cell.sda-empty { background: transparent; cursor: default; }
			.sda-cell.sda-ok   { background: #dcfce7; color: #166534; }
			.sda-cell.sda-ko   { background: #fee2e2; color: #991b1b; }
			.sda-cell.sda-today { border-color: #1e3a5f; }
			.sda-cell.sda-selected { border-color: #f59e0b; }
			.sda-cell-count { font-size: 8px; font-weight: 400; color: #64748b; margin-top: 1px; }
			.sda-panel { background: #fff; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 12px 12px; padding: 16px; }
			.sda-panel-date { font-size: 14px; font-weight: 700; margin-bottom: 10px; text-transform: capitalize; }
			.sda-team-row { display: flex; align-items: center; gap: 8px; padding: 5px 0; font-size: 13px; }
			.sda-team-avatar { width: 22px; height: 22px; border-radius: 50%; background: #e5e7eb; color: #374151; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
			.sda-team-status { margin-left: auto; font-size: 12px; }
			.sda-me-label { font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin: 14px 0 8px; }
			.sda-btns { display: flex; gap: 8px; }
			.sda-btn { flex: 1; padding: 14px 8px; border-radius: 10px; border: 2px solid #e5e7eb; background: #fff; font-size: 14px; font-weight: 700; cursor: pointer; }
			.sda-btn.sda-btn-ok.sda-active   { background: #16a34a; border-color: #16a34a; color: #fff; }
			.sda-btn.sda-btn-ko.sda-active   { background: #dc2626; border-color: #dc2626; color: #fff; }
			.sda-note { width: 100%; margin-top: 10px; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; font-family: inherit; }
			.sda-clear { display: block; text-align: center; margin-top: 10px; font-size: 12px; color: #9ca3af; cursor: pointer; }
			.sda-saved { text-align: center; font-size: 11px; color: #16a34a; height: 16px; margin-top: 6px; }
			.sda-hint { text-align: center; font-size: 12px; color: #9ca3af; padding: 14px; }
			</style>

			<div class="sda-header">
				<div class="sda-header-club"><?php echo $club; ?></div>
				<div class="sda-header-me">👋 <?php echo esc_html( $me->nom_public ?: $me->nom ); ?></div>
			</div>
			<div class="sda-nav">
				<button type="button" id="sda-prev">‹</button>
				<div class="sda-nav-title" id="sda-title">—</div>
				<button type="button" id="sda-next">›</button>
			</div>
			<div class="sda-grid" id="sda-grid"></div>
			<div class="sda-panel" id="sda-panel">
				<p class="sda-hint">Touchez un jour pour voir ou déclarer une disponibilité.</p>
			</div>
		</div>

		<script>
		(function(){
			var root  = document.getElementById('sp-dispo-app');
			var token = root.getAttribute('data-token');
			var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

			var state = {
				year: new Date().getFullYear(),
				month: new Date().getMonth() + 1,
				me: null, trainers: [], dispos: {},
				selected: null
			};

			var MOIS = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
			var JOURS = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];

			function pad(n){ return n < 10 ? '0' + n : '' + n; }
			function todayStr(){ var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }

			function post(action, data, cb) {
				var body = new URLSearchParams(Object.assign({ action: action, token: token }, data));
				fetch(ajaxUrl, { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() })
					.then(function(r){ return r.json(); })
					.then(function(res){ cb(res); })
					.catch(function(){ cb({ success: false }); });
			}

			function loadMonth() {
				document.getElementById('sda-title').textContent = MOIS[state.month-1] + ' ' + state.year;
				post('sp_cal_dispo_app_data', { year: state.year, month: state.month }, function(res){
					if (!res.success) {
						document.getElementById('sda-grid').innerHTML = '';
						document.getElementById('sda-panel').innerHTML = '<p class="sda-hint">⚠️ ' + (res.data || 'Erreur de chargement') + '</p>';
						return;
					}
					state.me       = res.data.me;
					state.trainers = res.data.trainers;
					state.dispos   = res.data.dispos;
					state.selected = null;
					renderGrid();
					renderPanel();
				});
			}

			function renderGrid() {
				var grid = document.getElementById('sda-grid');
				grid.innerHTML = '';
				JOURS.forEach(function(j){
					var el = document.createElement('div');
					el.className = 'sda-dow'; el.textContent = j;
					grid.appendChild(el);
				});

				var first = new Date(state.year, state.month - 1, 1);
				var startOffset = (first.getDay() + 6) % 7; // lundi = 0
				var daysInMonth = new Date(state.year, state.month, 0).getDate();

				for (var i = 0; i < startOffset; i++) {
					var empty = document.createElement('div');
					empty.className = 'sda-cell sda-empty';
					grid.appendChild(empty);
				}

				for (var d = 1; d <= daysInMonth; d++) {
					var ds = state.year + '-' + pad(state.month) + '-' + pad(d);
					var dayDispos = state.dispos[ds] || {};
					var mine = state.me && dayDispos[state.me.id] ? dayDispos[state.me.id].disponible : null;

					var cell = document.createElement('div');
					cell.className = 'sda-cell' + (mine === 1 ? ' sda-ok' : mine === 0 ? ' sda-ko' : '') + (ds === todayStr() ? ' sda-today' : '') + (ds === state.selected ? ' sda-selected' : '');
					cell.setAttribute('data-date', ds);

					var nb = 0;
					state.trainers.forEach(function(t){ if (dayDispos[t.id] && dayDispos[t.id].disponible === 1) nb++; });

					cell.innerHTML = '<span>' + d + '</span>' + (state.trainers.length > 1 ? '<span class="sda-cell-count">' + nb + '/' + state.trainers.length + '</span>' : '');
					cell.addEventListener('click', function(){
						state.selected = this.getAttribute('data-date');
						renderGrid();
						renderPanel();
					});
					grid.appendChild(cell);
				}
			}

			function statusIcon(dispo) {
				if (!dispo) return '<span style="color:#cbd5e1;">⏳</span>';
				if (dispo.disponible === 1) return '<span style="color:#16a34a;">✅</span>';
				if (dispo.disponible === 0) return '<span style="color:#dc2626;">❌</span>';
				return '<span style="color:#cbd5e1;">⏳</span>';
			}

			function renderPanel() {
				var panel = document.getElementById('sda-panel');
				if (!state.selected) {
					panel.innerHTML = '<p class="sda-hint">Touchez un jour pour voir ou déclarer une disponibilité.</p>';
					return;
				}
				var ds = state.selected;
				var dayDispos = state.dispos[ds] || {};
				var dObj = new Date(ds + 'T00:00:00');
				var dateLabel = dObj.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });

				var html = '<div class="sda-panel-date">' + dateLabel + '</div>';

				state.trainers.forEach(function(t){
					var d = dayDispos[t.id];
					var isMe = state.me && t.id === state.me.id;
					html += '<div class="sda-team-row"><span class="sda-team-avatar">' + (t.nom_public||t.nom).charAt(0).toUpperCase() + '</span>'
						+ '<span>' + (t.nom_public||t.nom) + (isMe ? ' (vous)' : '') + '</span>'
						+ '<span class="sda-team-status">' + statusIcon(d) + '</span></div>';
				});

				var mine = dayDispos[state.me.id] || null;
				html += '<div class="sda-me-label">Votre disponibilité</div>'
					+ '<div class="sda-btns">'
					+ '<button type="button" class="sda-btn sda-btn-ok' + (mine && mine.disponible === 1 ? ' sda-active' : '') + '" id="sda-btn-ok">✅ Disponible</button>'
					+ '<button type="button" class="sda-btn sda-btn-ko' + (mine && mine.disponible === 0 ? ' sda-active' : '') + '" id="sda-btn-ko">❌ Indisponible</button>'
					+ '</div>'
					+ '<textarea class="sda-note" id="sda-note" rows="2" placeholder="Note (facultatif)">' + (mine && mine.note ? mine.note : '') + '</textarea>'
					+ '<span class="sda-clear" id="sda-clear">🗑️ Effacer ma réponse</span>'
					+ '<div class="sda-saved" id="sda-saved"></div>';

				panel.innerHTML = html;

				function save(disponible) {
					var note = document.getElementById('sda-note').value;
					post('sp_cal_dispo_app_save', { date: ds, disponible: disponible === null ? '' : disponible, note: note }, function(res){
						var saved = document.getElementById('sda-saved');
						if (res.success) {
							saved.textContent = '✓ Enregistré';
							if (!state.dispos[ds]) state.dispos[ds] = {};
							if (disponible === null) { delete state.dispos[ds][state.me.id]; }
							else { state.dispos[ds][state.me.id] = { disponible: disponible, note: note }; }
							renderGrid();
						} else {
							saved.textContent = '⚠️ Erreur — réessayez';
						}
						setTimeout(function(){ if (saved) saved.textContent = ''; }, 2000);
					});
				}

				document.getElementById('sda-btn-ok').addEventListener('click', function(){ save(1); renderPanelKeepNote(1); });
				document.getElementById('sda-btn-ko').addEventListener('click', function(){ save(0); renderPanelKeepNote(0); });
				document.getElementById('sda-clear').addEventListener('click', function(){ save(null); });
				document.getElementById('sda-note').addEventListener('blur', function(){
					var mine2 = (state.dispos[ds] || {})[state.me.id];
					if (mine2) save(mine2.disponible);
				});

				function renderPanelKeepNote(val) {
					document.getElementById('sda-btn-ok').classList.toggle('sda-active', val === 1);
					document.getElementById('sda-btn-ko').classList.toggle('sda-active', val === 0);
				}
			}

			document.getElementById('sda-prev').addEventListener('click', function(){
				state.month--; if (state.month < 1) { state.month = 12; state.year--; }
				loadMonth();
			});
			document.getElementById('sda-next').addEventListener('click', function(){
				state.month++; if (state.month > 12) { state.month = 1; state.year++; }
				loadMonth();
			});

			loadMonth();
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}

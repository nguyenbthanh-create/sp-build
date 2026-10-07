<?php
/**
 * Pointage QR : routes REST /pointage/* (déclarées ici et par l'extension « SP Pointage QR »), actions AJAX de pointage, page d'administration « Pointage QR » (PIN, lien). Le PIN est vérifié par SP_Cal_Pin_Garde (class-pin-garde.php).
 *
 * Méthodes de SpCalPro_Admin déplacées telles quelles depuis class-admin.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_Admin_Pointage; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_Admin_Pointage {


    /* ══════════════════════════════════════════════════════════
       HANDLE POST / GET REQUESTS
    ══════════════════════════════════════════════════════════ */

    // Enregistre les routes REST de pointage (accessibles sans login)
    public function register_pointage_rest() {
        $routes = array( 'cours', 'scan', 'cours_eleve', 'lot' );
        foreach ( $routes as $r ) {
            register_rest_route( 'spcal/v1', '/pointage/' . $r, array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_pointage_' . $r ),
                'permission_callback' => '__return_true',
            ) );
        }
    }
    private function rest_params_to_post( WP_REST_Request $req ) {
        foreach ( $req->get_params() as $k => $v ) { $_POST[ $k ] = $v; }
        // Support JSON body
        $json = $req->get_json_params();
        if ( $json ) { foreach ( $json as $k => $v ) { $_POST[ $k ] = $v; } }
    }
    public function rest_pointage_cours( WP_REST_Request $req ) {
        $this->rest_params_to_post( $req ); $this->ajax_pointage_cours(); exit;
    }
    public function rest_pointage_scan( WP_REST_Request $req ) {
        $this->rest_params_to_post( $req ); $this->ajax_pointage_scan(); exit;
    }
    public function rest_pointage_cours_eleve( WP_REST_Request $req ) {
        $this->rest_params_to_post( $req ); $this->ajax_pointage_cours_eleve(); exit;
    }
    public function rest_pointage_lot( WP_REST_Request $req ) {
        $this->rest_params_to_post( $req ); $this->ajax_pointage_lot(); exit;
    }

    /* ══════════════════════════════════════════════════════════
       POINTAGE QR CODE
    ══════════════════════════════════════════════════════════ */

    // Code PIN pointage : SP_Cal_Pin_Garde::pin() (class-pin-garde.php) depuis le 07/10/2026.

    // ── Helper : matérialise une occurrence slot+date dans events si besoin ──
    // Retourne l'event_id (existant ou nouvellement créé)
    private function materialiser_occurrence( $slot_id, $date ) {
        global $wpdb;
        $te  = $this->db->table_events();
        $tsl = $this->db->table_slots();

        // Déjà matérialisé ?
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $te WHERE slot_id = %d AND date = %s AND type != 'annulation' LIMIT 1",
            $slot_id, $date
        ) );
        if ( $existing ) return intval( $existing );

        // Récupérer le slot
        $slot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tsl WHERE id = %d", $slot_id ) );
        if ( ! $slot ) return 0;

        $wpdb->insert( $te, array(
            'date'        => $date,
            'heure_debut' => $slot->heure_debut,
            'heure_fin'   => $slot->heure_fin,
            'titre'       => $slot->label,
            'categorie'   => $slot->categorie ?: 'Général',
            'type'        => 'cours',
            'slot_id'     => $slot_id,
        ) );
        return intval( $wpdb->insert_id );
    }

    // AJAX : cours d'un jour (depuis les slots récurrents)
    public function ajax_pointage_cours() {
        $pin  = sanitize_text_field( wp_unslash( $_POST['pin']  ?? '' ) );
        $date = sanitize_text_field( wp_unslash( $_POST['date'] ?? date('Y-m-d' ) ) );
        if ( ! SP_Cal_Pin_Garde::verifier( (string) $pin, SP_Cal_Pin_Garde::pin() ) ) {
            wp_send_json_error( SP_Cal_Pin_Garde::message(), 403 ); return;
        }
        // Utiliser get_slot_occurrences pour avoir les cours récurrents
        $occs = $this->db->get_slot_occurrences( $date, $date );
        $cours = array();
        foreach ( $occs as $occ ) {
            if ( $occ['annul_id'] ) continue; // cours annulé
            $cours[] = array(
                'slot_id'     => $occ['slot_id'],
                'mat_id'      => $occ['mat_id'],   // null si pas encore matérialisé
                'date'        => $occ['date'],
                'titre'       => $occ['titre'],
                'heure_debut' => $occ['heure_debut'],
                'heure_fin'   => $occ['heure_fin'],
                'categorie'   => $occ['categorie'],
            );
        }
        wp_send_json_success( $cours );
    }

    // AJAX : cours récents non pointés pour un élève (mode rétroactif)
    public function ajax_pointage_cours_eleve() {
        $pin   = sanitize_text_field( wp_unslash( $_POST['pin']   ?? '' ) );
        $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        if ( ! SP_Cal_Pin_Garde::verifier( (string) $pin, SP_Cal_Pin_Garde::pin() ) ) {
            wp_send_json_error( SP_Cal_Pin_Garde::message(), 403 ); return;
        }
        global $wpdb;
        $tel   = $this->db->table_eleves();
        $tpe   = $this->db->table_presences_eleves();
        $eleve = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom, prenom FROM $tel WHERE token = %s AND actif = 1 LIMIT 1", $token
        ) );
        if ( ! $eleve ) { wp_send_json_error( 'Élève non trouvé', 404 ); return; }

        // Occurrences des 30 derniers jours
        $date_fin   = date('Y-m-d');
        $date_debut = date('Y-m-d', strtotime('-30 days'));
        $occs = $this->db->get_slot_occurrences( $date_debut, $date_fin );

        $cours = array();
        foreach ( array_reverse( $occs ) as $occ ) {
            if ( $occ['annul_id'] ) continue;
            // Vérifier si déjà pointé (nécessite mat_id)
            $deja = false;
            if ( $occ['mat_id'] ) {
                $deja = (bool) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $tpe WHERE event_id = %d AND eleve_id = %d LIMIT 1",
                    $occ['mat_id'], $eleve->id
                ) );
            }
            $cours[] = array(
                'slot_id'      => $occ['slot_id'],
                'mat_id'       => $occ['mat_id'],
                'date'         => $occ['date'],
                'titre'        => $occ['titre'],
                'heure_debut'  => $occ['heure_debut'],
                'heure_fin'    => $occ['heure_fin'],
                'categorie'    => $occ['categorie'],
                'deja_pointe'  => $deja ? 1 : 0,
            );
        }
        wp_send_json_success( array(
            'eleve' => array( 'nom' => $eleve->prenom . ' ' . mb_strtoupper( $eleve->nom ) ),
            'cours' => $cours,
        ) );
    }

    // AJAX : enregistrer une présence au scan
    public function ajax_pointage_scan() {
        global $wpdb;
        $pin      = sanitize_text_field( wp_unslash( $_POST['pin']      ?? '' ) );
        $token    = sanitize_text_field( wp_unslash( $_POST['token']    ?? '' ) );
        $slot_id  = intval( $_POST['slot_id']  ?? 0 );
        $date     = sanitize_text_field( wp_unslash( $_POST['date']     ?? '' ) );
        if ( ! SP_Cal_Pin_Garde::verifier( (string) $pin, SP_Cal_Pin_Garde::pin() ) ) {
            wp_send_json_error( SP_Cal_Pin_Garde::message(), 403 ); return;
        }
        if ( ! $token || ! $slot_id || ! $date ) {
            wp_send_json_error( 'Données manquantes', 400 ); return;
        }
        // Trouver l'élève
        $tel   = $this->db->table_eleves();
        $eleve = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom, prenom FROM $tel WHERE token = %s AND actif = 1 LIMIT 1", $token
        ) );
        if ( ! $eleve ) { wp_send_json_error( 'Élève non trouvé', 404 ); return; }

        // Matérialiser l'occurrence si besoin → obtenir event_id
        $event_id = $this->materialiser_occurrence( $slot_id, $date );
        if ( ! $event_id ) { wp_send_json_error( 'Créneau introuvable', 404 ); return; }

        // Enregistrer la présence
        $tpe      = $this->db->table_presences_eleves();
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $tpe WHERE event_id = %d AND eleve_id = %d", $event_id, $eleve->id
        ) );
        if ( $existing ) {
            wp_send_json_success( array(
                'nom' => $eleve->prenom . ' ' . mb_strtoupper( $eleve->nom ),
                'status' => 'already', 'msg' => 'Déjà pointé',
            ) ); return;
        }
        $wpdb->insert( $tpe, array( 'event_id' => $event_id, 'eleve_id' => $eleve->id, 'present' => 1 ) );
        wp_send_json_success( array(
            'nom' => $eleve->prenom . ' ' . mb_strtoupper( $eleve->nom ),
            'status' => 'ok', 'msg' => 'Présent',
        ) );
    }

    // AJAX : enregistrer plusieurs présences en lot
    public function ajax_pointage_lot() {
        global $wpdb;
        $pin      = sanitize_text_field( wp_unslash( $_POST['pin']   ?? '' ) );
        $token    = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        $items    = $_POST['items'] ?? array(); // array de {slot_id, date}
        if ( ! SP_Cal_Pin_Garde::verifier( (string) $pin, SP_Cal_Pin_Garde::pin() ) ) {
            wp_send_json_error( SP_Cal_Pin_Garde::message(), 403 ); return;
        }
        if ( ! $token || empty( $items ) ) {
            wp_send_json_error( 'Données manquantes', 400 ); return;
        }
        $tel   = $this->db->table_eleves();
        $eleve = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom, prenom FROM $tel WHERE token = %s AND actif = 1 LIMIT 1", $token
        ) );
        if ( ! $eleve ) { wp_send_json_error( 'Élève non trouvé', 404 ); return; }

        $tpe = $this->db->table_presences_eleves();
        $nb  = 0;
        foreach ( $items as $item ) {
            $sid  = intval( $item['slot_id'] ?? 0 );
            $date = sanitize_text_field( $item['date'] ?? '' );
            if ( ! $sid || ! $date ) continue;
            $eid = $this->materialiser_occurrence( $sid, $date );
            if ( ! $eid ) continue;
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $tpe WHERE event_id=%d AND eleve_id=%d", $eid, $eleve->id
            ) );
            if ( ! $exists ) {
                $wpdb->insert( $tpe, array( 'event_id' => $eid, 'eleve_id' => $eleve->id, 'present' => 1 ) );
                $nb++;
            }
        }
        wp_send_json_success( array(
            'nom' => $eleve->prenom . ' ' . mb_strtoupper( $eleve->nom ),
            'nb'  => $nb,
        ) );
    }


    // Page admin pointage
    public function page_pointage() {
        if (!current_user_can('manage_options')) wp_die('Accès refusé');
        $pin = SP_Cal_Pin_Garde::pin();
        $pointage_url = home_url('/pointage/?pin=' . $pin);

        // Régénérer le PIN si demandé
        if (isset($_POST['regenerer_pin']) && check_admin_referer('sp_pointage_regen')) {
            SP_Cal_Pin_Garde::regenerer();
            wp_redirect(admin_url('admin.php?page=sp-cal-pointage&pin_ok=1')); exit;
        }
        ?>
        <div class="wrap" style="max-width:700px;">
            <h1>📡 Pointage QR Code</h1>

            <?php if(isset($_GET['pin_ok'])): ?>
            <div class="updated is-dismissible"><p>✅ Nouveau PIN généré.</p></div>
            <?php endif; ?>

            <div class="sp-box" style="margin-bottom:18px;">
                <h2 style="margin-top:0;">🔐 Accès entraîneur</h2>
                <p>Partagez cette URL avec les entraîneurs — elle donne accès au scanner de présences :</p>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
                    <code style="background:#f1f5f9;padding:8px 14px;border-radius:6px;font-size:14px;flex:1;">
                        <?php echo esc_html($pointage_url); ?>
                    </code>
                    <button onclick="navigator.clipboard.writeText('<?php echo esc_js($pointage_url); ?>').then(()=>this.textContent='✅ Copié!')" class="button">📋 Copier</button>
                </div>
                <p style="color:#64748b;font-size:13px;">
                    PIN actuel : <strong style="font-size:20px;letter-spacing:4px;"><?php echo esc_html($pin); ?></strong>
                </p>
                <form method="post">
                    <?php wp_nonce_field('sp_pointage_regen'); ?>
                    <input type="submit" name="regenerer_pin" class="button button-secondary" value="🔄 Générer un nouveau PIN"
                           onclick="return confirm('Changer le PIN rendra l'ancienne URL inutilisable. Continuer ?')">
                </form>
            </div>

            <!-- QR Code de l'URL de pointage -->
            <div class="sp-box">
                <h2 style="margin-top:0;">📱 QR Code de la page pointage</h2>
                <p style="color:#64748b;font-size:13px;">Imprimez et affichez ce QR Code en salle — les entraîneurs le scannent pour ouvrir la page directement.</p>
                <div id="sp-pointage-qr" style="display:inline-block;padding:12px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;"></div>
            </div>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
        (function(){
            function init() {
                if (typeof QRCode === 'undefined') { setTimeout(init, 100); return; }
                new QRCode(document.getElementById('sp-pointage-qr'), {
                    text: '<?php echo esc_js($pointage_url); ?>',
                    width: 180, height: 180,
                    colorDark: '#111', colorLight: '#fff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
            init();
        })();
        </script>
        <?php
    }
}

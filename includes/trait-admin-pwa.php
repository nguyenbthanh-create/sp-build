<?php
/**
 * Application mobile [sp_cal_app] (adhérents, entraîneurs, bureau) : page de l'application (render_pwa_app), service worker et manifeste, API REST spcal/v1 (authentification JWT, fiche, calendrier, événements, calendrier du club), notifications push (abonnements).
 *
 * Méthodes de SpCalPro_Admin déplacées telles quelles depuis class-admin.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_Admin_Pwa; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_Admin_Pwa {


    /* ══════════════════════════════════════════════════════════════════════
       PWA — PHASE 1 : API REST
       POST /wp-json/spcal/v1/auth
       GET  /wp-json/spcal/v1/eleve/me
       GET  /wp-json/spcal/v1/calendrier
    ══════════════════════════════════════════════════════════════════════ */

    public function register_pwa_rest() {
        register_rest_route( 'spcal/v1', '/auth', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_pwa_auth' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'spcal/v1', '/eleve/evenements', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_pwa_eleve_evenements' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'spcal/v1', '/eleve/me', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_pwa_eleve_me' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'spcal/v1', '/calendrier', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_pwa_calendrier' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'spcal/v1', '/calendrier/club', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_pwa_calendrier_club' ),
            'permission_callback' => '__return_true',
        ) );
        // Push subscriptions
        register_rest_route( 'spcal/v1', '/push/vapid-public', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_pwa_vapid_public' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'spcal/v1', '/push/subscribe', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_pwa_push_subscribe' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'spcal/v1', '/push/unsubscribe', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'rest_pwa_push_unsubscribe' ),
            'permission_callback' => '__return_true',
        ) );
    }

    private function jwt_secret() : string {
        return defined( 'SP_CAL_JWT_SECRET' ) ? SP_CAL_JWT_SECRET : wp_salt( 'auth' );
    }
    private function jwt_encode( array $payload ) : string {
        $h = $this->b64u( json_encode( array( 'typ' => 'JWT', 'alg' => 'HS256' ) ) );
        $p = $this->b64u( json_encode( $payload ) );
        $s = $this->b64u( hash_hmac( 'sha256', "$h.$p", $this->jwt_secret(), true ) );
        return "$h.$p.$s";
    }
    private function jwt_decode( string $token ) {
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 3 ) return false;
        $h = $parts[0]; $p = $parts[1]; $sig = $parts[2];
        $expected = $this->b64u( hash_hmac( 'sha256', "$h.$p", $this->jwt_secret(), true ) );
        if ( ! hash_equals( $expected, $sig ) ) return false;
        $data = json_decode( $this->b64u_decode( $p ), true );
        if ( ! is_array( $data ) || empty( $data['exp'] ) ) return false;
        if ( $data['exp'] < time() ) return false;
        return $data;
    }
    private function b64u( string $data ) : string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
    private function b64u_decode( string $data ) : string {
        $pad = ( 4 - strlen( $data ) % 4 ) % 4;
        return base64_decode( strtr( $data, '-_', '+/' ) . str_repeat( '=', $pad ) );
    }
    private function pwa_rate_limit( string $key, int $max, int $window ) : bool {
        $tk  = 'sp_rl_' . md5( $key );
        $val = get_transient( $tk );
        if ( $val === false ) { set_transient( $tk, 1, $window ); return true; }
        if ( intval( $val ) >= $max ) return false;
        set_transient( $tk, intval( $val ) + 1, $window );
        return true;
    }
    private function pwa_require_auth( WP_REST_Request $req ) {
        $auth = $req->get_header( 'Authorization' ) ?? '';
        $raw_jwt = strncmp( $auth, 'Bearer ', 7 ) === 0
            ? substr( $auth, 7 )
            : sanitize_text_field( wp_unslash( $req->get_param( 'jwt' ) ?? '' ) );
        if ( ! $raw_jwt ) return new WP_Error( 'spcal_no_auth', 'Authentification requise.', array( 'status' => 401 ) );
        $payload = $this->jwt_decode( $raw_jwt );
        if ( ! $payload || empty( $payload['eid'] ) ) return new WP_Error( 'spcal_invalid_jwt', 'Session expirée. Reconnectez-vous.', array( 'status' => 401 ) );
        return intval( $payload['eid'] );
    }

    public function rest_pwa_auth( WP_REST_Request $req ) {
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        if ( ! $this->pwa_rate_limit( 'auth_' . $ip, 10, 60 ) )
            return new WP_Error( 'spcal_rate_limit', 'Trop de tentatives. Réessayez dans une minute.', array( 'status' => 429 ) );
        $json      = $req->get_json_params();
        $raw_token = $json['token'] ?? sanitize_text_field( wp_unslash( $req->get_param( 'token' ) ?? '' ) );
        $raw_token = sanitize_text_field( wp_unslash( $raw_token ) );
        if ( ! $raw_token || ! preg_match( '/^[0-9a-f]{64}$/', $raw_token ) )
            return new WP_Error( 'spcal_invalid_token', 'Token invalide.', array( 'status' => 401 ) );
        global $wpdb;
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare( "SELECT id, nom, prenom, actif FROM $tel WHERE token = %s LIMIT 1", $raw_token ) );
        if ( ! $el ) return new WP_Error( 'spcal_not_found', 'Token invalide ou compte introuvable.', array( 'status' => 401 ) );
        if ( ! intval( $el->actif ) ) return new WP_Error( 'spcal_inactive', 'Compte inactif. Contactez votre club.', array( 'status' => 403 ) );
        $iat = time(); $exp = $iat + DAY_IN_SECONDS;
        $jwt = $this->jwt_encode( array( 'sub' => $raw_token, 'eid' => intval( $el->id ), 'iat' => $iat, 'exp' => $exp ) );
        return rest_ensure_response( array(
            'success' => true, 'jwt' => $jwt, 'expires_at' => $exp, 'ttl' => DAY_IN_SECONDS,
            'eleve'   => array( 'id' => intval( $el->id ), 'prenom' => $el->prenom, 'nom' => mb_strtoupper( $el->nom ) ),
        ) );
    }

    public function rest_pwa_eleve_me( WP_REST_Request $req ) {
        $eid = $this->pwa_require_auth( $req );
        if ( is_wp_error( $eid ) ) return $eid;
        global $wpdb;
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tel WHERE id = %d AND actif = 1 LIMIT 1", $eid ) );
        if ( ! $el ) return new WP_Error( 'spcal_not_found', 'Profil introuvable.', array( 'status' => 404 ) );
        $presences  = $this->db->get_presences_eleve( intval( $el->id ) );
        $pres_cours = array_values( array_filter( $presences, static function( $p ) {
            return ! in_array( $p->type ?? '', array( 'examen', 'anniversaire' ), true );
        } ) );
        $nb_present = count( array_filter( $pres_cours, static function( $p ) { return intval( $p->present ) === 1; } ) );
        $nb_total   = count( $pres_cours );
        $taux       = $nb_total > 0 ? round( $nb_present / $nb_total * 100 ) : null;
        // Statut d'après la saison de la fiche (SpCalPro_DB::statut_saison_eleve) ; codes de l'API inchangés.
        $statut_saison = SpCalPro_DB::statut_saison_eleve( $el );
        $fin_saison    = SpCalPro_DB::fin_saison_prochaine();
        $jours         = $statut_saison['jours'];
        $adhesion      = array( 'a_renouveler' => 'expire', 'bientot' => 'expire_bientot' )[ $statut_saison['code'] ] ?? 'actif';
        $grade_vise = $this->db->get_grade_vise_eleve( $el );
        return rest_ensure_response( array(
            'id' => intval( $el->id ), 'prenom' => $el->prenom, 'nom' => mb_strtoupper( $el->nom ),
            'grade' => $el->grade ?? '', 'categorie_age' => $el->categorie_age ?? '',
            'categorie_saisie' => $el->categorie_saisie ?? '', 'saison' => $el->saison ?? '',
            'photo_url' => $el->photo_url ?? '', 'licence' => $el->licence ?? '',
            'date_naissance' => $el->date_naissance ?? '', 'annee_naissance' => $el->annee_naissance ?? '',
            'num_passeport' => $el->num_passeport ?? '', 'urgence_telephone' => $el->urgence_telephone ?? '',
            'nb_licences' => intval( $el->nb_licences ?? 0 ),
            'adhesion'   => array( 'statut' => $adhesion, 'jours' => $jours, 'fin' => $fin_saison ),
            'assiduite'  => array( 'present' => $nb_present, 'total' => $nb_total, 'taux' => $taux ),
            'grade_vise' => $grade_vise ?: '',
            // Carte « Mon prochain grade » : programme du Parcours + retour du dernier passage (class-passages.php).
            'parcours'   => SP_Cal_Passages::get_instance()->donnees_eleve( $el ),
            // Passerelle vers la fiche complète (grades, présences, doboks) — unification fiche / PWA, étape 1.
            'fiche_url'  => ( $this->token && ! empty( $el->token ) ) ? $this->token->get_fiche_url( $el->token ) : '',
            'carte' => array(
                'qr_url'        => home_url( '/' ) . '?token=' . rawurlencode( $el->token ?? '' ),
                'club_nom'      => get_option( 'blogname', '' ),
                'club_num'      => get_option( 'sp_cal_club_num', '' ),
                'club_affil'    => get_option( 'sp_cal_club_affiliation', '' ),
                'club_ligue'    => get_option( 'sp_cal_club_ligue', '' ),
                'club_labelise' => intval( get_option( 'sp_cal_club_labelise', 0 ) ),
                'logo_url'      => get_option( 'sp_cal_logo_url', '' ),
            ),
        ) );
    }

    public function rest_pwa_eleve_evenements( WP_REST_Request $req ) {
        $eid = $this->pwa_require_auth( $req );
        if ( is_wp_error( $eid ) ) return $eid;

        // Agenda complet sur 3 mois (hors cours et anniversaires), avec ce qui concerne
        // l'adhérent et ses inscriptions ouvertes — calcul partagé avec la fiche ?token=
        // (SpCalPro_DB::get_agenda_eleve(), décision du 26/09/2026).
        $out = array();
        foreach ( $this->db->get_agenda_eleve( intval( $eid ), 92 ) as $ev ) {
            $out[] = array(
                'id'                    => intval( $ev->id ),
                'titre'                 => $ev->titre                 ?? '',
                'date'                  => $ev->date                  ?? '',
                'heure_debut'           => $ev->heure_debut           ?? '',
                'type'                  => $ev->type                  ?? '',
                'inscriptions_deadline' => $ev->inscriptions_deadline ?? null,
                'inscriptions_message'  => $ev->inscriptions_message  ?? '',
                'statut_insc'           => $ev->statut_insc           ?? 'en_attente',
                'concerne'              => (bool) $ev->concerne,
                'inscription'           => (bool) $ev->inscription,
                // Compatibilité avec l'ancien client : « eligible » = peut répondre.
                'eligible'              => (bool) $ev->inscription,
            );
        }
        return rest_ensure_response( array( 'evenements' => $out ) );
    }

    public function rest_pwa_calendrier_club( WP_REST_Request $req ) {
        $pin = sanitize_text_field( wp_unslash( $req->get_param( 'pin' ) ?? '' ) );
        if ( ! SP_Cal_Pin_Garde::verifier( (string) $pin, SP_Cal_Pin_Garde::pin() ) ) {
            return new WP_Error( 'spcal_invalid_pin', SP_Cal_Pin_Garde::message(), array( 'status' => 401 ) );
        }
        $from_raw = sanitize_text_field( wp_unslash( $req->get_param( 'from' ) ?? date( 'Y-m-d' ) ) );
        $nb       = min( 30, max( 1, intval( $req->get_param( 'nb' ) ?? 20 ) ) );
        $from     = ( preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $from_raw ) && strtotime( $from_raw ) ) ? $from_raw : date( 'Y-m-d' );
        $to       = date( 'Y-m-d', strtotime( $from . ' +90 days' ) );
        $occs     = $this->db->get_slot_occurrences( $from, $to );
        $cours    = array();
        $encadrement_par_date = array();
        foreach ( $occs as $occ ) {
            if ( $occ['annul_id'] ) continue;
            if ( ! isset( $encadrement_par_date[ $occ['date'] ] ) ) {
                $encadrement_par_date[ $occ['date'] ] = $this->encadrement_du_jour( $occ['date'] );
            }
            $cours[] = array(
                'slot_id'     => intval( $occ['slot_id'] ),
                'mat_id'      => $occ['mat_id'] ? intval( $occ['mat_id'] ) : null,
                'date'        => $occ['date'],
                'heure_debut' => $occ['heure_debut'] ?? '',
                'heure_fin'   => $occ['heure_fin']   ?? '',
                'titre'       => $occ['titre']        ?? '',
                'categorie'   => $occ['categorie']    ?? '',
                'encadrement' => $encadrement_par_date[ $occ['date'] ],
            );
            if ( count( $cours ) >= $nb ) break;
        }
        return rest_ensure_response( array( 'from' => $from, 'nb' => count( $cours ), 'cours' => $cours ) );
    }

    /** Encadrement d'une journée : voir SpCalPro_DB::get_encadrement_du_jour() (règle partagée avec le mail au bureau). */
    private function encadrement_du_jour( $date ) {
        return $this->db->get_encadrement_du_jour( $date );
    }

    public function rest_pwa_calendrier( WP_REST_Request $req ) {
        $eid = $this->pwa_require_auth( $req );
        if ( is_wp_error( $eid ) ) return $eid;
        global $wpdb;
        $tel = $this->db->table_eleves();
        $el  = $wpdb->get_row( $wpdb->prepare( "SELECT categorie_saisie, categorie_age FROM $tel WHERE id = %d AND actif = 1 LIMIT 1", $eid ) );
        if ( ! $el ) return new WP_Error( 'spcal_not_found', 'Élève introuvable.', array( 'status' => 404 ) );
        $from_raw = sanitize_text_field( wp_unslash( $req->get_param( 'from' ) ?? date( 'Y-m-d' ) ) );
        $nb       = min( 30, max( 1, intval( $req->get_param( 'nb' ) ?? 20 ) ) );
        $from     = ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from_raw ) && strtotime( $from_raw ) ) ? $from_raw : date( 'Y-m-d' );
        $to       = date( 'Y-m-d', strtotime( $from . ' +90 days' ) );
        $occs     = $this->db->get_slot_occurrences( $from, $to );
        $cat      = trim( (string) ( $el->categorie_saisie ?? '' ) );
        $cat_age  = trim( (string) ( $el->categorie_age ?? '' ) );
        $cours    = array();
        foreach ( $occs as $occ ) {
            if ( $occ['annul_id'] ) continue;
            // Même règle que le mail d'annulation (SpCalPro_DB::creneau_concerne()) : discipline ×
            // tranche d'âge du créneau, ou ancien texte libre tant qu'il n'est pas reclassé.
            // Avant : seule la discipline de l'adhérent comparée au texte libre — un créneau
            // « Enfant » n'apparaissait pas à un adhérent « TKD ».
            if ( ( $cat !== '' || $cat_age !== '' ) && ! $this->db->creneau_concerne( $occ, $cat, $cat_age ) ) continue;
            $cours[] = array(
                'slot_id' => intval( $occ['slot_id'] ), 'mat_id' => $occ['mat_id'] ? intval( $occ['mat_id'] ) : null,
                'date' => $occ['date'], 'heure_debut' => $occ['heure_debut'] ?? '', 'heure_fin' => $occ['heure_fin'] ?? '',
                'titre' => $occ['titre'] ?? '', 'categorie' => $occ['categorie'] ?? '',
            );
            if ( count( $cours ) >= $nb ) break;
        }
        return rest_ensure_response( array( 'from' => $from, 'nb' => count( $cours ), 'cours' => $cours ) );
    }

    /* ══════════════════════════════════════════════════════════════════════
       PWA — PHASE 2 : APPLICATION MOBILE INSTALLABLE
       Shortcode : [sp_cal_app]
       SW        : /?spcal_sw=1
       Manifest  : /?spcal_manifest=1
    ══════════════════════════════════════════════════════════════════════ */

    /** Injecte les balises PWA dans <head> uniquement sur la page du shortcode. */
    public function pwa_head_tags() {
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'sp_cal_app' ) ) return;
        $manifest_url = esc_url( home_url( '/?spcal_manifest=1' ) );
        $logo_url     = esc_url( get_option( 'sp_cal_logo_url', '' ) );
        $club         = esc_attr( get_option( 'blogname', 'TKD' ) );
        echo "<link rel=\"manifest\" href=\"$manifest_url\">\n";
        echo "<meta name=\"theme-color\" content=\"#0f70b7\">\n";
        echo "<meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n";
        echo "<meta name=\"apple-mobile-web-app-status-bar-style\" content=\"black-translucent\">\n";
        echo "<meta name=\"apple-mobile-web-app-title\" content=\"$club\">\n";
        if ( $logo_url ) echo "<link rel=\"apple-touch-icon\" href=\"$logo_url\">\n";
    }

    /** Sert le Service Worker et le manifest via template_redirect. */
    public function maybe_serve_pwa_assets() {
        // ── Service Worker ──────────────────────────────────────
        if ( isset( $_GET['spcal_sw'] ) ) {
            $app_url = esc_url( home_url( '/app/' ) );
            header( 'Content-Type: application/javascript; charset=utf-8' );
            header( 'Cache-Control: no-cache, no-store, must-revalidate' );
            header( 'Service-Worker-Allowed: /' );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $this->get_pwa_sw_code( $app_url );
            exit;
        }
        // ── Manifest ────────────────────────────────────────────
        if ( isset( $_GET['spcal_manifest'] ) ) {
            header( 'Content-Type: application/manifest+json; charset=utf-8' );
            header( 'Cache-Control: no-cache' );
            $icon_url = get_option( 'sp_cal_logo_url', '' );
            $icons    = array();
            if ( $icon_url ) {
                $icons[] = array( 'src' => $icon_url, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable' );
                $icons[] = array( 'src' => $icon_url, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' );
            }
            $manifest = array(
                'name'             => get_option( 'blogname', 'TKD' ),
                'short_name'       => get_option( 'blogname', 'TKD' ),
                'description'      => 'Suivi membre — ' . get_option( 'blogname', '' ),
                'start_url'        => home_url( '/app/?source=pwa' ),
                'scope'            => home_url( '/' ),
                'display'          => 'standalone',
                'background_color' => '#111111',
                'theme_color'      => '#0f70b7',
                'lang'             => 'fr',
                'icons'            => $icons,
            );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            exit;
        }
    }

    /** Code du Service Worker : réseau d'abord pour la seule page de l'application, rien d'autre n'est intercepté. */
    private function get_pwa_sw_code( string $app_url ) : string {
        return <<<'SWJS'
const CACHE_NAME = 'spcal-pwa-v2';
const SHELL_URLS = [self.registration.scope + 'app/'];

/* ── Événements push ─────────────────────────────────────────── */
self.addEventListener('push', function(e) {
    var data = {};
    try { data = e.data ? e.data.json() : {}; } catch(err) {}
    var title = data.title || 'TKD Club';
    var body  = data.body  || 'Nouveau message de votre club';
    var icon  = data.icon  || '/favicon.ico';
    e.waitUntil(
        self.registration.showNotification(title, {
            body: body, icon: icon, badge: icon,
            tag: 'spcal-notif', requireInteraction: false,
        })
    );
});

self.addEventListener('notificationclick', function(e) {
    e.notification.close();
    e.waitUntil( clients.openWindow(self.registration.scope + 'app/') );
});

self.addEventListener('install', function(e) {
    e.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(SHELL_URLS).catch(function(){});
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', function(e) {
    e.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(keys.filter(function(k){ return k !== CACHE_NAME; }).map(function(k){ return caches.delete(k); }));
        }).then(function(){ return clients.claim(); })
    );
});

// Correctif du 27/09/2026 : l'ancienne version interceptait TOUTES les requêtes GET du site
// (scope « / ») en « cache d'abord » — pages, CSS, et même l'admin : tout navigateur ayant
// ouvert l'application une fois revoyait d'anciennes versions (ex. ancien en-tête de la fiche
// adhérent après sa mise à jour). Désormais seule la page de l'application est mise en
// cache, en « réseau d'abord » (cache = secours hors ligne) ; le reste du site n'est jamais
// intercepté. CACHE_NAME changé : l'activation supprime l'ancien cache.
self.addEventListener('fetch', function(e) {
    if (e.request.method !== 'GET' || e.request.mode !== 'navigate') return;
    var url = new URL(e.request.url);
    if (url.origin !== self.location.origin) return;
    var estApp = SHELL_URLS.some(function(s){ return new URL(s).pathname === url.pathname; });
    if (!estApp) return;

    e.respondWith(
        fetch(e.request).then(function(response) {
            if (response.ok) {
                var clone = response.clone();
                caches.open(CACHE_NAME).then(function(cache){ cache.put(SHELL_URLS[0], clone); });
            }
            return response;
        }).catch(function() {
            return caches.match(SHELL_URLS[0]);
        })
    );
});
// 07/10/2026 : le CSS et le JavaScript de l'application sont dans des fichiers séparés
// (assets/pwa/) : même stratégie « réseau d'abord, cache en secours » que la page, pour qu'elle
// s'ouvre toujours hors connexion. Rien d'autre du site n'est intercepté.
self.addEventListener('fetch', function(e) {
    if (e.request.method !== 'GET') return;
    var url = new URL(e.request.url);
    if (url.origin !== self.location.origin || url.pathname.indexOf('/assets/pwa/') === -1) return;
    e.respondWith(
        fetch(e.request).then(function(response) {
            if (response.ok) {
                var clone = response.clone();
                caches.open(CACHE_NAME).then(function(cache){ cache.put(e.request, clone); });
            }
            return response;
        }).catch(function() {
            return caches.match(e.request, { ignoreSearch: true });
        })
    );
});
SWJS;
    }

    /** Shortcode [sp_cal_app] — rendu de la PWA complète. */
    public function render_pwa_app() {
        $api_base  = rest_url( 'spcal/v1' );
        $sw_url    = home_url( '/?spcal_sw=1' );
        $logo_url  = esc_url( get_option( 'sp_cal_logo_url', '' ) );
        $club_nom  = esc_js( get_option( 'blogname', 'TKD' ) );

        // Clé publique VAPID pour le push
        if ( class_exists( 'SpCalPro_WebPush' ) ) {
            $vapid_keys = SpCalPro_WebPush::get_or_create_keys();
            $vapid_pub  = $vapid_keys['public_b64u'];
        } else {
            $vapid_pub = get_option( 'sp_cal_vapid_public', '' );
        }
        $config_js = wp_json_encode( array(
            'apiBase'     => $api_base,
            'swUrl'       => $sw_url,
            'clubNom'     => get_option( 'blogname', 'TKD' ),
            'logoUrl'     => get_option( 'sp_cal_logo_url', '' ),
            'vapidPublic' => $vapid_pub,
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
            'jsqrUrl'     => SP_CAL_PRO_URL . 'assets/js/jsQR.min.js', // lu par assets/pwa/app.js (07/10/2026)
        ) );

        ob_start();
        ?>
<script>window.SPCAL = <?php echo $config_js; // phpcs:ignore ?>;</script>
<script>
// Diagnostic temporaire (doléances 10/09/2026) : le scan QR reste muet sur iPhone/Firefox
// sans aucune erreur visible, et les devtools ne sont pas facilement accessibles sur iOS --
// ce filet affiche à l'écran toute erreur JS non interceptée ailleurs, pour obtenir la
// vraie cause au prochain test plutôt que deviner une nouvelle fois. À retirer une fois
// la cause confirmée.
window.addEventListener('error', function(e) {
    if (window.__spCalErrShown) return;
    window.__spCalErrShown = true;
    alert('Erreur JS : ' + e.message + '\n' + (e.filename || '') + ':' + (e.lineno || '?'));
});
</script>

<link rel="stylesheet" href="<?php echo esc_url( SP_CAL_PRO_URL . 'assets/pwa/app.css?ver=' . sp_cal_asset_ver( 'assets/pwa/app.css' ) ); ?>">

<div id="spcal-pwa">

    <!-- Loading -->
    <div id="spcal-loading">
        <?php if ( $logo_url ) : ?>
        <img src="<?php echo esc_url( $logo_url ); ?>" alt="">
        <?php else : ?>
        <div style="font-size:48px;">🥋</div>
        <?php endif; ?>
        <div class="spcal-spinner"></div>
        <p>Chargement en cours…</p>
    </div>

    <!-- Error -->
    <div id="spcal-error">
        <div class="spcal-err-ico">🔒</div>
        <h2>Accès invalide</h2>
        <p id="spcal-error-msg">Lien expiré ou invalide.<br>Contactez votre club pour recevoir un nouveau lien.</p>
    </div>

    <!-- App -->
    <div id="spcal-app">

        <!-- Header -->
        <header id="spcal-header">
            <img id="spcal-header-logo" src="" alt="" style="display:none">
            <div id="spcal-header-logo-placeholder"></div>
            <div id="spcal-header-text">
                <div id="spcal-header-club"><?php echo esc_html( get_option( 'blogname', '' ) ); ?></div>
                <div id="spcal-header-name">Chargement…</div>
            </div>
            <div id="spcal-grade-pill" style="display:none"></div>
        </header>

        <!-- Main -->
        <main id="spcal-main">

            <!-- Accueil -->
            <section id="spcal-accueil" class="spcal-screen active">
                <div id="spcal-greeting"></div>
                <div id="spcal-greeting-sub"></div>
                <div id="spcal-adhesion-wrap"></div>
                <div id="spcal-assiduite-wrap"></div>
                <!-- Rappel « passage de grade à venir » — renderGradeRappel() -->
                <div id="spcal-grade-rappel"></div>
                <div id="spcal-fiche-wrap"></div>
                <div class="spcal-section-title">Prochains cours</div>
                <div id="spcal-prochains-cours"><div class="spcal-empty">Chargement…</div></div>
            </section>

            <!-- Carte -->
            <section id="spcal-carte" class="spcal-screen">
                <div class="spcal-section-title">Carte de membre</div>
                <div class="spcal-card-scene">
                    <div class="spcal-card-inner" id="spcal-card-inner" onclick="spCalFlipCard()">
                        <div class="sp-carte-recto" id="spcal-recto"></div>
                        <div class="sp-carte-verso" id="spcal-verso"></div>
                    </div>
                </div>
                <p class="spcal-card-hint">Appuyez sur la carte pour retourner</p>
                <div class="spcal-install-hint" id="spcal-install-hint" style="display:none">
                    <span class="spcal-ih-ico">📲</span>
                    <span id="spcal-install-text"></span>
                </div>
                <!-- Progression de grade — injecté par renderGradeProgression() -->
                <div id="spcal-grade-progression"></div>
            </section>

            <!-- Calendrier -->
            <section id="spcal-calendrier" class="spcal-screen">
                <div class="spcal-section-title">Calendrier</div>
                <div id="spcal-cal-list"><div class="spcal-empty">Chargement…</div></div>
            </section>

            <!-- Événements & Inscriptions -->
            <section id="spcal-evenements" class="spcal-screen">
                <div class="spcal-section-title">Événements — 3 prochains mois</div>
                <div id="spcal-evt-list"><div class="spcal-empty">Chargement…</div></div>
            </section>

            <!-- Pointage entraîneurs -->
            <section id="spcal-pointage" class="spcal-screen">
                <!-- Écran PIN -->
                <div id="spcal-pin-screen">
                    <div class="spcal-section-title">Accès entraîneur</div>
                    <p style="font-size:13px;color:rgba(255,255,255,.5);margin-bottom:16px;">Entrez votre code PIN pour accéder au pointage.</p>
                    <div style="display:flex;gap:10px;align-items:center;margin-bottom:12px;">
                        <input id="spcal-pin-input" type="password" inputmode="numeric" maxlength="8"
                            placeholder="Code PIN"
                            style="flex:1;background:#1a1a1a;border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:12px 16px;color:#fff;font-size:18px;letter-spacing:4px;outline:none;">
                        <button onclick="spCalPinSubmit()"
                            style="background:#0f70b7;border:none;border-radius:10px;padding:12px 20px;color:#fff;font-size:15px;font-weight:600;cursor:pointer;">OK</button>
                    </div>
                    <div id="spcal-pin-error" style="display:none;color:#f87171;font-size:13px;margin-bottom:8px;"></div>
                </div>
                <!-- Dashboard pointage (affiché après PIN valide) -->
                <div id="spcal-pointage-dashboard" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <div class="spcal-section-title" style="margin:0;">Pointage du <span id="spcal-ptg-date"></span></div>
                        <button id="spcal-ptg-logout" onclick="spCalPinLogout()" style="background:none;border:none;color:rgba(255,255,255,.3);font-size:12px;cursor:pointer;">Déconnexion</button>
                    </div>
                    <!-- Sélecteur cours -->
                    <div id="spcal-cours-list-wrap">
                        <p style="font-size:13px;color:rgba(255,255,255,.5);">Sélectionnez un cours :</p>
                        <div id="spcal-ptg-cours-list"></div>
                    </div>
                    <!-- Zone scan (affichée après sélection cours) -->
                    <div id="spcal-scan-wrap" style="display:none;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                            <button onclick="spCalBackToCours()" style="background:#1a1a1a;border:none;border-radius:8px;padding:8px 12px;color:rgba(255,255,255,.6);font-size:13px;cursor:pointer;">← Cours</button>
                            <div id="spcal-ptg-cours-title" style="font-size:14px;font-weight:600;"></div>
                        </div>
                        <!-- Caméra QR -->
                        <div style="position:relative;width:100%;max-width:320px;margin:0 auto 16px;border-radius:12px;overflow:hidden;background:#000;">
                            <video id="spcal-qr-video" style="width:100%;display:block;" playsinline autoplay muted></video>
                            <canvas id="spcal-qr-canvas" style="display:none;"></canvas>
                            <div style="position:absolute;inset:0;pointer-events:none;">
                                <div id="spcal-scan-frame" class="spcal-scan-frame"><div class="spcal-scan-line"></div></div>
                            </div>
                            <!-- Résultat affiché dans l'image (04/10/2026) : la lecture est en pause pendant
                                 l'affichage puis reprend seule ; toucher le bandeau reprend tout de suite. -->
                            <div id="spcal-scan-overlay" class="spcal-scan-overlay" onclick="spCalScanReprendre()" style="display:none;">
                                <div id="spcal-scan-ov-nom" class="spcal-scan-ov-nom"></div>
                                <div id="spcal-scan-ov-msg" class="spcal-scan-ov-msg"></div>
                                <div class="spcal-scan-ov-hint">Toucher pour scanner l'élève suivant</div>
                            </div>
                        </div>
                        <p style="text-align:center;font-size:13px;color:rgba(255,255,255,.4);margin-bottom:4px;">Scannez la carte QR de l'élève</p>
                        <p id="spcal-scan-etat" style="text-align:center;font-size:11px;color:rgba(255,255,255,.3);margin-bottom:16px;"></p>
                        <!-- Feedback scan -->
                        <div id="spcal-scan-feedback" style="display:none;border-radius:12px;padding:14px 16px;text-align:center;margin-bottom:12px;">
                            <div id="spcal-scan-nom" style="font-size:17px;font-weight:700;"></div>
                            <div id="spcal-scan-msg" style="font-size:13px;margin-top:4px;"></div>
                        </div>
                        <!-- Log des présences -->
                        <div class="spcal-section-title" style="margin-top:8px;">Présences enregistrées</div>
                        <div id="spcal-ptg-log"></div>
                    </div>
                </div>
            </section>

            <!-- Anniversaires du mois (class-anniversaires.php, route /anniversaires) : onglet 🎂 à part,
                 pour ne pas mêler ces prénoms aux présences du pointage (choix de l'utilisateur, 01/10/2026) -->
            <section id="spcal-anniv" class="spcal-screen">
                <div id="spcal-ptg-anniv"><div class="spcal-empty">Chargement…</div></div>
            </section>

            <!-- Mes dispos (mode entraîneur par lien personnel) — chargé à la première ouverture -->
            <section id="spcal-dispos" class="spcal-screen">
                <div id="spcal-dispos-box"><div class="spcal-empty">Chargement…</div></div>
            </section>

            <!-- Saisie rapide de la trésorerie (membres du bureau, lien personnel) : ouverte dans une
                 fenêtre à part — pas insérée ici (un cadre bloquait le défilement pour « Mes dispos »,
                 et l'envoi du formulaire ferait quitter l'application). -->
            <section id="spcal-saisie" class="spcal-screen">
                <div class="spcal-section-title">Trésorerie</div>
                <div class="spcal-saisie-carte">
                    <div class="spcal-saisie-ico">💶</div>
                    <p><strong>Saisie rapide</strong><br>Une dépense ou une recette en quelques secondes, avec la photo du justificatif.</p>
                    <a id="spcal-saisie-lien" class="spcal-saisie-btn" href="#" target="_blank" rel="noopener">Ouvrir la saisie rapide</a>
                    <p class="spcal-saisie-aide">S'ouvre dans une fenêtre à part : fermez-la (« OK » / « Terminé ») pour revenir ici. La première fois, connectez-vous avec votre compte du site : la connexion est ensuite gardée un an.</p>
                </div>
            </section>

        </main>

        <!-- Bottom nav -->
        <nav id="spcal-nav">
            <button class="spcal-nav-btn active" onclick="spCalNav('accueil',this)">
                <span class="spcal-nav-ico">🏠</span>
                <span>Accueil</span>
            </button>
            <button class="spcal-nav-btn" id="spcal-nav-carte" onclick="spCalNav('carte',this)">
                <span class="spcal-nav-ico">🪪</span>
                <span>Carte</span>
            </button>
            <button class="spcal-nav-btn" id="spcal-nav-calendrier" onclick="spCalNav('calendrier',this)">
                <span class="spcal-nav-ico">📅</span>
                <span>Calendrier</span>
            </button>
            <button class="spcal-nav-btn" id="spcal-nav-evenements" onclick="spCalNav('evenements',this)">
                <span class="spcal-nav-ico">🎯</span>
                <span>Événements</span>
            </button>
            <button class="spcal-nav-btn" id="spcal-nav-pointage" style="display:none" onclick="spCalNav('pointage',this)">
                <span class="spcal-nav-ico">📡</span>
                <span>Pointage</span>
            </button>
            <button class="spcal-nav-btn" id="spcal-nav-anniv" style="display:none" onclick="spCalNav('anniv',this)">
                <span class="spcal-nav-ico">🎂</span>
                <span>Anniversaires</span>
            </button>
            <button class="spcal-nav-btn" id="spcal-nav-saisie" style="display:none" onclick="spCalNav('saisie',this)">
                <span class="spcal-nav-ico">💶</span>
                <span>Saisie</span>
            </button>
        </nav>

    </div><!-- /#spcal-app -->

</div><!-- /#spcal-pwa -->

<link href="https://fonts.googleapis.com/css2?family=Song+Myung&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="<?php echo esc_url( SP_CAL_PRO_URL . 'assets/pwa/app.js?ver=' . sp_cal_asset_ver( 'assets/pwa/app.js' ) ); ?>"></script>
        <?php
        return ob_get_clean();
    }


    /* ── Push table ──────────────────────────────────────────────── */

    public function maybe_create_push_table() {
        if ( get_option( 'sp_cal_push_db_v1' ) ) return;
        global $wpdb;
        $table   = $wpdb->prefix . 'sp_cal_push_subs';
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            eleve_id bigint(20) UNSIGNED NOT NULL,
            endpoint text NOT NULL,
            p256dh varchar(128) NOT NULL,
            auth varchar(64) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY eleve_id (eleve_id),
            UNIQUE KEY endpoint_hash (endpoint(200))
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'sp_cal_push_db_v1', 1 );
    }

    /* ── Push REST handlers ───────────────────────────────────────── */

    /** GET /wp-json/spcal/v1/push/vapid-public — retourne la clé publique VAPID. */
    public function rest_pwa_vapid_public( WP_REST_Request $req ) {
        $pub = get_option( 'sp_cal_vapid_public', '' );
        if ( ! $pub && class_exists( 'SpCalPro_WebPush' ) ) {
            $keys = SpCalPro_WebPush::get_or_create_keys();
            $pub  = $keys['public_b64u'];
        }
        return rest_ensure_response( array( 'public_key' => $pub ) );
    }

    /** POST /wp-json/spcal/v1/push/subscribe — enregistre un abonnement push. */
    public function rest_pwa_push_subscribe( WP_REST_Request $req ) {
        $eid = $this->pwa_require_auth( $req );
        if ( is_wp_error( $eid ) ) return $eid;

        $json     = $req->get_json_params() ?: array();
        $endpoint = sanitize_text_field( wp_unslash( $json['endpoint'] ?? '' ) );
        $p256dh   = sanitize_text_field( wp_unslash( $json['p256dh']   ?? '' ) );
        $auth     = sanitize_text_field( wp_unslash( $json['auth']     ?? '' ) );

        if ( ! $endpoint || ! $p256dh || ! $auth ) {
            return new WP_Error( 'spcal_push_invalid', 'Données de subscription manquantes.', array( 'status' => 400 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sp_cal_push_subs';

        // Upsert : si l'endpoint existe déjà, on met à jour
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE endpoint = %s LIMIT 1", $endpoint
        ) );
        if ( $existing ) {
            $wpdb->update( $table,
                array( 'eleve_id' => $eid, 'p256dh' => $p256dh, 'auth' => $auth ),
                array( 'id' => intval( $existing ) )
            );
        } else {
            $wpdb->insert( $table, array(
                'eleve_id' => $eid, 'endpoint' => $endpoint,
                'p256dh'   => $p256dh, 'auth' => $auth,
            ) );
        }

        return rest_ensure_response( array( 'success' => true ) );
    }

    /** DELETE /wp-json/spcal/v1/push/unsubscribe — supprime un abonnement. */
    public function rest_pwa_push_unsubscribe( WP_REST_Request $req ) {
        $eid = $this->pwa_require_auth( $req );
        if ( is_wp_error( $eid ) ) return $eid;

        $json     = $req->get_json_params() ?: array();
        $endpoint = sanitize_text_field( wp_unslash( $json['endpoint'] ?? '' ) );
        if ( ! $endpoint ) return new WP_Error( 'spcal_push_invalid', 'Endpoint manquant.', array( 'status' => 400 ) );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'sp_cal_push_subs',
            array( 'eleve_id' => $eid, 'endpoint' => $endpoint )
        );
        return rest_ensure_response( array( 'success' => true ) );
    }
}

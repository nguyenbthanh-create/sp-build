<?php
/**
 * Plugin Name: SP Pointage QR
 * Description: Routes REST publiques pour le pointage QR Code des présences élèves.
 * Version:     1.0.0
 * Author:      Club
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function() {

    $prefix = 'mod237_';
    $routes = array( 'cours', 'scan', 'cours_eleve', 'lot' );

    foreach ( $routes as $r ) {
        register_rest_route( 'spcal/v1', '/pointage/' . $r, array(
            'methods'             => 'POST',
            'callback'            => function( WP_REST_Request $req ) use ( $r, $prefix ) {
                return sp_pointage_handle( $r, $req, $prefix );
            },
            'permission_callback' => '__return_true',
        ) );
    }

} );

function sp_pointage_pin() {
    $p = get_option( 'sp_cal_pointage_pin', '' );
    if ( ! $p ) {
        $p = substr( str_shuffle( '0123456789' ), 0, 4 );
        update_option( 'sp_cal_pointage_pin', $p );
    }
    return $p;
}

function sp_pointage_mat_occ( $slot_id, $date, $prefix ) {
    global $wpdb;
    $te  = $prefix . 'sp_cal_events';
    $tsl = $prefix . 'sp_cal_slots';

    $ex = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $te WHERE slot_id=%d AND date=%s AND type!='annulation' LIMIT 1",
        $slot_id, $date
    ) );
    if ( $ex ) return intval( $ex );

    $slot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tsl WHERE id=%d", $slot_id ) );
    if ( ! $slot ) return 0;

    $wpdb->insert( $te, array(
        'date'        => $date,
        'heure_debut' => $slot->heure_debut,
        'heure_fin'   => $slot->heure_fin,
        'titre'       => $slot->label,
        'categorie'   => $slot->categorie ?: 'General',
        'type'        => 'cours',
        'slot_id'     => $slot_id,
    ) );
    return intval( $wpdb->insert_id );
}

function sp_pointage_handle( $route, WP_REST_Request $req, $prefix ) {
    global $wpdb;

    $params = $req->get_params();
    $pin    = sanitize_text_field( $params['pin']   ?? '' );

    if ( $pin !== sp_pointage_pin() ) {
        return new WP_REST_Response( array( 'success' => false, 'data' => 'PIN invalide' ), 403 );
    }

    $tel = $prefix . 'sp_cal_eleves';
    $tpe = $prefix . 'sp_cal_presences_eleves';

    // ── cours : liste des créneaux du jour ──────────────────
    if ( $route === 'cours' ) {
        $date = sanitize_text_field( $params['date'] ?? date( 'Y-m-d' ) );
        $te   = $prefix . 'sp_cal_events';
        $tsl  = $prefix . 'sp_cal_slots';

        // Charger les slots actifs ce jour
        $dow   = intval( date( 'N', strtotime( $date ) ) ); // 1=lun … 7=dim
        $slots = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $tsl WHERE jour=%d ORDER BY heure_debut ASC", $dow
        ) );

        // Annulations du jour
        $annuls = array();
        foreach ( $wpdb->get_results( $wpdb->prepare(
            "SELECT slot_id FROM $te WHERE date=%s AND type='annulation' AND slot_id IS NOT NULL", $date
        ) ) as $a ) {
            $annuls[ intval($a->slot_id) ] = true;
        }

        // Matérialisations existantes
        $mats = array();
        foreach ( $wpdb->get_results( $wpdb->prepare(
            "SELECT id, slot_id FROM $te WHERE date=%s AND type!='annulation' AND slot_id IS NOT NULL", $date
        ) ) as $m ) {
            $mats[ intval($m->slot_id) ] = intval($m->id);
        }

        $cours = array();
        foreach ( $slots as $slot ) {
            $sid = intval( $slot->id );
            if ( isset( $annuls[$sid] ) ) continue;
            // Vérifier date_debut / date_fin du slot
            if ( ! empty($slot->date_debut) && $date < $slot->date_debut ) continue;
            if ( ! empty($slot->date_fin)   && $date > $slot->date_fin   ) continue;
            $cours[] = array(
                'slot_id'     => $sid,
                'mat_id'      => $mats[$sid] ?? null,
                'date'        => $date,
                'titre'       => $slot->label,
                'heure_debut' => $slot->heure_debut,
                'heure_fin'   => $slot->heure_fin,
                'categorie'   => $slot->categorie,
            );
        }
        return new WP_REST_Response( array( 'success' => true, 'data' => $cours ), 200 );
    }

    // ── scan : enregistrer une présence ─────────────────────
    if ( $route === 'scan' ) {
        $raw     = sanitize_text_field( $params['token']   ?? '' );
        $slot_id = intval(              $params['slot_id'] ?? 0  );
        $date    = sanitize_text_field( $params['date']    ?? '' );

        // Extraire le token si l'URL complète est envoyée
        if ( strpos( $raw, 'token=' ) !== false ) {
            $qs = parse_url( $raw, PHP_URL_QUERY ) ?: $raw;
            parse_str( $qs, $qp );
            $raw = $qp['token'] ?? $raw;
        }
        $token = sanitize_text_field( $raw );

        if ( ! $token || ! $slot_id || ! $date ) {
            return new WP_REST_Response( array( 'success' => false, 'data' => 'Donnees manquantes' ), 400 );
        }

        $el = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom, prenom FROM $tel WHERE token=%s AND actif=1 LIMIT 1", $token
        ) );
        if ( ! $el ) {
            return new WP_REST_Response( array( 'success' => false, 'data' => 'Eleve non trouve' ), 404 );
        }

        $eid = sp_pointage_mat_occ( $slot_id, $date, $prefix );
        if ( ! $eid ) {
            return new WP_REST_Response( array( 'success' => false, 'data' => 'Creneau introuvable' ), 404 );
        }

        $ex = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $tpe WHERE event_id=%d AND eleve_id=%d", $eid, $el->id
        ) );
        if ( $ex ) {
            return new WP_REST_Response( array( 'success' => true, 'data' => array(
                'nom' => $el->prenom . ' ' . mb_strtoupper( $el->nom ),
                'status' => 'already', 'msg' => 'Deja pointe',
            ) ), 200 );
        }

        $wpdb->insert( $tpe, array( 'event_id' => $eid, 'eleve_id' => $el->id, 'present' => 1 ) );
        return new WP_REST_Response( array( 'success' => true, 'data' => array(
            'nom' => $el->prenom . ' ' . mb_strtoupper( $el->nom ),
            'status' => 'ok', 'msg' => 'Present',
        ) ), 200 );
    }

    // ── cours_eleve : cours récents pour modal rétroactif ───
    if ( $route === 'cours_eleve' ) {
        $raw   = sanitize_text_field( $params['token'] ?? '' );
        if ( strpos( $raw, 'token=' ) !== false ) {
            $qs = parse_url( $raw, PHP_URL_QUERY ) ?: $raw;
            parse_str( $qs, $qp );
            $raw = $qp['token'] ?? $raw;
        }
        $token = sanitize_text_field( $raw );

        $el = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom, prenom FROM $tel WHERE token=%s AND actif=1 LIMIT 1", $token
        ) );
        if ( ! $el ) {
            return new WP_REST_Response( array( 'success' => false, 'data' => 'Eleve non trouve' ), 404 );
        }

        $date_fin   = date( 'Y-m-d' );
        $date_debut = date( 'Y-m-d', strtotime( '-30 days' ) );
        $te  = $prefix . 'sp_cal_events';
        $tsl = $prefix . 'sp_cal_slots';

        // Récupérer tous les slots et générer les occurrences des 30 derniers jours
        $all_slots = $wpdb->get_results( "SELECT * FROM $tsl ORDER BY jour ASC, heure_debut ASC" );
        $annuls    = array();
        foreach ( $wpdb->get_results( $wpdb->prepare(
            "SELECT date, slot_id FROM $te WHERE date BETWEEN %s AND %s AND type='annulation' AND slot_id IS NOT NULL",
            $date_debut, $date_fin
        ) ) as $a ) {
            $annuls[ $a->date . '_' . intval($a->slot_id) ] = true;
        }
        $mats = array();
        foreach ( $wpdb->get_results( $wpdb->prepare(
            "SELECT id, date, slot_id FROM $te WHERE date BETWEEN %s AND %s AND type!='annulation' AND slot_id IS NOT NULL",
            $date_debut, $date_fin
        ) ) as $m ) {
            $mats[ $m->date . '_' . intval($m->slot_id) ] = intval($m->id);
        }

        $cours = array();
        $d = new DateTime( $date_debut );
        $end = new DateTime( $date_fin );
        while ( $d <= $end ) {
            $ds  = $d->format( 'Y-m-d' );
            $dow = intval( $d->format( 'N' ) );
            foreach ( $all_slots as $slot ) {
                if ( intval($slot->jour) !== $dow ) continue;
                if ( ! empty($slot->date_debut) && $ds < $slot->date_debut ) continue;
                if ( ! empty($slot->date_fin)   && $ds > $slot->date_fin   ) continue;
                $sid = intval($slot->id);
                if ( isset($annuls[$ds.'_'.$sid]) ) continue;
                $mat_id = $mats[$ds.'_'.$sid] ?? null;
                $deja   = $mat_id ? (bool) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $tpe WHERE event_id=%d AND eleve_id=%d LIMIT 1", $mat_id, $el->id
                ) ) : false;
                $cours[] = array(
                    'slot_id'     => $sid,
                    'mat_id'      => $mat_id,
                    'date'        => $ds,
                    'titre'       => $slot->label,
                    'heure_debut' => $slot->heure_debut,
                    'heure_fin'   => $slot->heure_fin,
                    'categorie'   => $slot->categorie,
                    'deja_pointe' => $deja ? 1 : 0,
                );
            }
            $d->modify( '+1 day' );
        }
        // Trier du plus récent au plus ancien
        usort( $cours, function($a,$b){ return strcmp($b['date'],$a['date']) ?: strcmp($b['heure_debut'],$a['heure_debut']); } );

        return new WP_REST_Response( array( 'success' => true, 'data' => array(
            'eleve' => array( 'nom' => $el->prenom . ' ' . mb_strtoupper( $el->nom ) ),
            'cours' => $cours,
        ) ), 200 );
    }

    // ── lot : enregistrer plusieurs présences ───────────────
    if ( $route === 'lot' ) {
        $raw   = sanitize_text_field( $params['token'] ?? '' );
        if ( strpos( $raw, 'token=' ) !== false ) {
            $qs = parse_url( $raw, PHP_URL_QUERY ) ?: $raw;
            parse_str( $qs, $qp );
            $raw = $qp['token'] ?? $raw;
        }
        $token = sanitize_text_field( $raw );
        $items = $params['items'] ?? array();

        if ( ! $token || empty( $items ) ) {
            return new WP_REST_Response( array( 'success' => false, 'data' => 'Donnees manquantes' ), 400 );
        }

        $el = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom, prenom FROM $tel WHERE token=%s AND actif=1 LIMIT 1", $token
        ) );
        if ( ! $el ) {
            return new WP_REST_Response( array( 'success' => false, 'data' => 'Eleve non trouve' ), 404 );
        }

        $nb = 0;
        foreach ( $items as $item ) {
            $sid  = intval( $item['slot_id'] ?? 0 );
            $date = sanitize_text_field( $item['date'] ?? '' );
            if ( ! $sid || ! $date ) continue;
            $eid = sp_pointage_mat_occ( $sid, $date, $prefix );
            if ( ! $eid ) continue;
            if ( ! $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $tpe WHERE event_id=%d AND eleve_id=%d", $eid, $el->id
            ) ) ) {
                $wpdb->insert( $tpe, array( 'event_id' => $eid, 'eleve_id' => $el->id, 'present' => 1 ) );
                $nb++;
            }
        }

        return new WP_REST_Response( array( 'success' => true, 'data' => array(
            'nom' => $el->prenom . ' ' . mb_strtoupper( $el->nom ),
            'nb'  => $nb,
        ) ), 200 );
    }

    return new WP_REST_Response( array( 'success' => false, 'data' => 'Route inconnue' ), 404 );
}

// Route REST publique pour le contenu pédagogique d'un grade
add_action( 'rest_api_init', function() {
    register_rest_route( 'spcal/v1', '/grade_contenu', array(
        'methods'             => 'GET',
        'callback'            => 'sp_get_grade_contenu_rest',
        'permission_callback' => '__return_true',
        'args'                => array(
            'grade' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
        ),
    ) );
} );

function sp_get_grade_contenu_rest( WP_REST_Request $req ) {
    global $wpdb;
    $grade  = sanitize_text_field( $req->get_param('grade') );
    $prefix = 'mod237_';
    $table  = $prefix . 'sp_cal_exam_grade_contenu';
    $row    = $wpdb->get_row( $wpdb->prepare(
        "SELECT description, liens FROM $table WHERE grade_actuel = %s LIMIT 1", $grade
    ) );
    if ( ! $row ) {
        return new WP_REST_Response( array( 'success' => true, 'data' => array( 'description' => '', 'liens' => array() ) ), 200 );
    }
    $liens = $row->liens ? ( json_decode( $row->liens, true ) ?: array() ) : array();
    return new WP_REST_Response( array( 'success' => true, 'data' => array(
        'description' => $row->description,
        'liens'       => $liens,
    ) ), 200 );
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SpCalPro_Jury' ) ) :

/**
 * SpCalPro_Jury — Jury d'examen Passe 1.5
 * URL publique par aire : ?jury_aire=ID&jury_event=ID
 * Sélection du juge sur l'écran (pas de token email)
 */
class SpCalPro_Jury {

    private $db;

    public function __construct( SpCalPro_DB $db ) {
        $this->db = $db;
        add_action( 'template_redirect', array( $this, 'maybe_render_aire' ) );
        // AJAX nopriv (juges sans compte WP)
        foreach ( array('get_aire_state','save_note','get_next_candidat','search_candidat') as $a )
            add_action( 'wp_ajax_nopriv_sp_jury_'.$a, array( $this, 'ajax_'.$a ) );
        // AJAX admin
        foreach ( array('get_aire_state','save_note','get_next_candidat','get_centrale',
                 'save_session','save_juges','save_affectations','reassign_candidat',
                 'remove_candidat','reset_affectations',
                 'save_zemita_seuils','save_zemita_mapping','save_zemita_groupe',
                 'set_statut','save_grade_progression','get_qr_url',
                 'preview_grades','write_grades',
                 'save_grade_contenu','get_grade_contenu',
                 'save_note_admin',
                 'recalculate_zemita',
                 'search_candidat')
                 as $a )
    add_action( 'wp_ajax_sp_jury_'.$a, array( $this, 'ajax_'.$a ) );
	add_action( 'wp_ajax_sp_jury_mobile_save_notes',        array( $this, 'ajax_mobile_save_notes' ) );
	add_action( 'wp_ajax_nopriv_sp_jury_mobile_save_notes', array( $this, 'ajax_mobile_save_notes' ) );
        // Contenu pédagogique accessible aux visiteurs non connectés (page token)
    add_action( 'wp_ajax_nopriv_sp_jury_get_grade_contenu', array( $this, 'ajax_get_grade_contenu' ) );
    }

    private function nonce()         { check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' ); }
    private function require_admin() { if ( ! current_user_can('manage_options') ) wp_send_json_error('Accès refusé',403); }

    public function get_aire_url( $event_id, $aire_id ) {
        return add_query_arg( array('jury_event'=>intval($event_id),'jury_aire'=>intval($aire_id)), home_url('/') );
    }

    /**
     * Calcul du score d'un élève.
     * $notes_eleve  : [epreuve_id => [juge_id => note, ...], ...]
     * $nb_juges     : int   → même diviseur pour toutes les épreuves (mode par_categorie)
     *                 array → [epreuve_id => nb_juges] par épreuve (mode par_epreuve)
     *                 0     → auto-détecté sur les notes saisies
     * Logique : pour chaque épreuve, moyenne des juges → somme des moyennes par épreuve.
     */
    public static function calc_score_eleve( $notes_eleve, $nb_juges = 0 ) {
        if ( empty($notes_eleve) ) return null;
        $total = 0; $has = false;
        foreach ( $notes_eleve as $ep_id => $ep ) {
            if ( empty($ep) ) continue;
            $notes_ep = array_values($ep);
            if ( is_array($nb_juges) ) {
                // Mode par_epreuve : nb_juges spécifique à chaque épreuve/aire
                $diviseur = intval( $nb_juges[ intval($ep_id) ] ?? count($notes_ep) );
            } else {
                $diviseur = ( $nb_juges > 0 ) ? intval($nb_juges) : count($notes_ep);
            }
            if ( $diviseur === 0 ) continue;
            $total += array_sum($notes_ep) / $diviseur;
            $has = true;
        }
        return $has ? round($total, 2) : null;
    }

    /**
     * Indicateur de complétude des notes pour un élève.
     * Retourne ['saisies'=>X, 'attendues'=>Y, 'complet'=>bool, 'incomplet_bloque'=>bool]
     * incomplet_bloque = true si nb_juges assignés > 1 ET au moins 1 juge n'a pas saisi.
     */
    public static function calc_completude( $notes_eleve, $nb_juges, $nb_epreuves ) {
        $saisies = 0;
        foreach ( $notes_eleve as $ep ) $saisies += count($ep);
        $attendues = max(1, $nb_juges) * $nb_epreuves;
        $complet   = ( $saisies >= $attendues && $attendues > 0 );
        return array(
            'saisies'          => $saisies,
            'attendues'        => $attendues,
            'complet'          => $complet,
            'incomplet_bloque' => ( $nb_juges > 1 && ! $complet ),
        );
    }

/* ── Routing front ── */
public function maybe_render_aire() {
	    // ── Nouvelle interface mobile ──
    if ( ! empty( $_GET['jury_mobile'] ) && $_GET['jury_mobile'] === '1' ) {
        $mobile = new SpCalPro_JuryMobile( $this->db );
        $mobile->render();
        return;
    }
    if ( empty($_GET['jury_aire']) || empty($_GET['jury_event']) || is_admin() ) return;
    $aire_id  = intval($_GET['jury_aire']);
    $event_id = intval($_GET['jury_event']);
    $session  = $this->db->get_jury_session($event_id);
    if ( ! $session ) return;
    global $wpdb;
    $tt   = $this->db->table_exam_tables();
    $aire = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $tt WHERE id=%d AND event_id=%d",$aire_id,$event_id) );
    if ( ! $aire ) return;
    $juges     = $this->db->get_jury_juges($event_id,$aire_id);
    $candidats = $this->db->get_affectations_aire($event_id,$aire_id);
    $epreuves  = $this->get_epreuves_for_session($event_id,$aire_id,$session);
    // Page autonome sans thème
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Jury — ' . esc_html($aire->label ?: 'Aire '.$aire->numero) . '</title>';
    wp_enqueue_style( 'sp-cal-calendar', SP_CAL_PRO_URL.'assets/css/calendar.css', array(), SP_CAL_PRO_VERSION );
    wp_enqueue_script( 'jquery' );
    wp_print_styles();
    wp_print_scripts();
    echo '</head><body>';
    echo '<div id="sp-jury-app">';
    include SP_CAL_PRO_PATH.'templates/jury-aire.php';
    echo '</div></body></html>';
    exit;
}

    public function get_epreuves_for_session( $event_id, $aire_id, $session ) {
        global $wpdb;
        $tep = $this->db->table_exam_epreuves();
        $tt  = $this->db->table_exam_tables();
        if ( $session->mode === 'par_epreuve' ) {
            $eid = $wpdb->get_var($wpdb->prepare("SELECT epreuve_id FROM $tt WHERE id=%d",intval($aire_id)));
            return $eid ? $wpdb->get_results($wpdb->prepare("SELECT * FROM $tep WHERE id=%d",intval($eid))) : array();
        }
        return $wpdb->get_results("SELECT * FROM $tep WHERE actif=1 ORDER BY ordre ASC, nom ASC");
    }

    public function get_notes_index( $event_id, $aire_id ) {
        global $wpdb;
        $tn   = $this->db->table_jury_notes();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT eleve_id,epreuve_id,juge_id,note FROM $tn WHERE event_id=%d AND exam_table_id=%d",
            intval($event_id),intval($aire_id)
        ));
        $idx = array();
        foreach ($rows as $r) $idx[intval($r->eleve_id)][intval($r->epreuve_id)][intval($r->juge_id)] = floatval($r->note);
        return $idx;
    }

    /* ── AJAX get_aire_state ── */
    public function ajax_get_aire_state() {
        $event_id = intval($_REQUEST['event_id']??0);
        $aire_id  = intval($_REQUEST['aire_id']??0);
        if (!$event_id||!$aire_id){wp_send_json_error('Paramètres manquants',400);return;}
        $session  = $this->db->get_jury_session($event_id);
        if (!$session){wp_send_json_error('Session introuvable',404);return;}
        $juges     = $this->db->get_jury_juges($event_id,$aire_id);
        $candidats = $this->db->get_affectations_aire($event_id,$aire_id);
        $epreuves  = $this->get_epreuves_for_session($event_id,$aire_id,$session);
        $notes_idx = $this->get_notes_index($event_id,$aire_id);

        // Si un juge_id est passé (interface juge), ne retourner que SES notes
        // pour éviter que les inputs soient pré-remplis avec les notes d'un autre juge
        $filter_juge_id = intval($_REQUEST['juge_id'] ?? 0);
        $notes_for_juge = $notes_idx; // notes complètes pour le calcul des scores
        if ($filter_juge_id > 0) {
            $notes_for_juge = array();
            foreach ($notes_idx as $eid => $epreuves) {
                foreach ($epreuves as $ep_id => $juges_notes) {
                    if (isset($juges_notes[$filter_juge_id])) {
                        $notes_for_juge[$eid][$ep_id][$filter_juge_id] = $juges_notes[$filter_juge_id];
                    }
                }
            }
        }

        $nb_juges   = count($juges);
        $nb_epreuves = count($epreuves);

        // Grille ZEMITA si mode activé
        $zemita_mode  = ! empty( $session->zemita_mode );
        $zemita_grid  = $zemita_mode ? $this->db->get_zemita_seuils( $event_id ) : array();
        $zemita_groupes_const = array( 'Débutant', 'Intermédiaire', 'Confirmé' );

// Récupérer les paramètres de l'aire courante
global $wpdb;
$tt   = $this->db->table_exam_tables();
$aire = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM $tt WHERE id=%d AND event_id=%d", $aire_id, $event_id
) );

        $scores    = array();
        foreach ($candidats as $c) {
            $eid   = intval($c->eleve_id);
            $notes = $notes_idx[$eid] ?? array();
            $score = self::calc_score_eleve($notes, $nb_juges);
            $comp  = self::calc_completude($notes, $nb_juges, $nb_epreuves);

// Seuils : ZEMITA par groupe/catégorie ou seuil de l'aire
$zemita_groupe = $c->zemita_groupe ?? '';
$aire_type     = $aire->type_verdict    ?? 'seuil_fixe';
$aire_seuil    = floatval( $aire->seuil_admission ?? $session->seuil_admission );

if ( $zemita_mode && $aire_type === 'zemita' && $zemita_groupe
     && isset( $zemita_grid[ $zemita_groupe ][ $c->categorie_age ] ) ) {
    $seuils      = $zemita_grid[ $zemita_groupe ][ $c->categorie_age ];
    $seuil_admis = $seuils['seuil_moyen'];
    $seuil_perf  = $seuils['seuil_performance'];
} else {
    $seuil_admis = $aire_seuil;
    $seuil_perf  = null;
}

            $admis      = ( $score !== null ) ? ( $score >= $seuil_admis ) : null;
            $excellence = ( $seuil_perf !== null && $score !== null ) ? ( $score >= $seuil_perf ) : false;

            $scores[$eid] = array(
                'score'             => $score,
                'admis'             => $admis,
                'excellence'        => $excellence,
                'zemita_groupe'     => $zemita_groupe,
                'seuil_moyen'       => $seuil_admis,
                'seuil_performance' => $seuil_perf,
                'grade_vise'        => $this->db->get_grade_vise_eleve($c),
                'completude'        => $comp,
            );
        }
        // Ajouter le statut locked si examen pas en cours
        $locked = ($session->statut !== 'en_cours');
        wp_send_json_success(array(
            'session'          => $session,
            'juges'            => $juges,
            'candidats'        => $candidats,
            'epreuves'         => $epreuves,
            'notes'            => $notes_for_juge,
            'scores'           => $scores,
            'locked'           => $locked,
			'note_min'    	   => intval( $aire->note_min ?? $session->note_min ),
			'note_max'         => intval( $aire->note_max ?? $session->note_max ),
			'aire'             => $aire,
            'zemita_mode'      => $zemita_mode,
            'zemita_groupes'   => $zemita_groupes_const,
            'zemita_grid'      => $zemita_grid,
            'ts'               => time(),
        ));
    }

public function ajax_search_candidat() {
    $this->require_admin();
    $event_id = intval( $_POST['event_id'] ?? 0 );
    $q        = sanitize_text_field( $_POST['q'] ?? '' );
    $aire_id  = intval( $_POST['aire_id'] ?? 0 );
    if ( ! $event_id || strlen($q) < 2 ) {
        wp_send_json_success( array() );
        return;
    }
    global $wpdb;
    $ta   = $this->db->table_exam_affectations();
    $tel  = $this->db->table_eleves();
    $like = '%' . $wpdb->esc_like($q) . '%';

    if ( $aire_id ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT el.id, el.nom, el.prenom, el.grade, el.categorie_age,
                    a.aire_id, a.zemita_groupe
             FROM $ta a
             INNER JOIN $tel el ON el.id = a.eleve_id
             WHERE a.event_id = %d
               AND ( el.nom LIKE %s OR el.prenom LIKE %s )
               AND a.aire_id = %d
             ORDER BY el.nom ASC, el.prenom ASC
             LIMIT 20",
            $event_id, $like, $like, $aire_id
        ) );
    } else {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT el.id, el.nom, el.prenom, el.grade, el.categorie_age,
                    a.aire_id, a.zemita_groupe
             FROM $ta a
             INNER JOIN $tel el ON el.id = a.eleve_id
             WHERE a.event_id = %d
               AND ( el.nom LIKE %s OR el.prenom LIKE %s )
             ORDER BY el.nom ASC, el.prenom ASC
             LIMIT 20",
            $event_id, $like, $like
        ) );
    }

    wp_send_json_success( array_map( function($r) {
        return array(
            'id'            => intval($r->id),
            'nom'           => $r->nom,
            'prenom'        => $r->prenom,
            'grade'         => $r->grade,
            'categorie_age' => $r->categorie_age,
            'aire_id'       => intval($r->aire_id),
            'zemita_groupe' => $r->zemita_groupe ?: '',
        );
    }, $rows ) );
}
	
    public function ajax_save_note() {
        $event_id  = intval($_POST['event_id']??0);
        $aire_id   = intval($_POST['aire_id']??0);
        $juge_id   = intval($_POST['juge_id']??0);
        $eleve_id  = intval($_POST['eleve_id']??0);
        $epreuve_id= intval($_POST['epreuve_id']??0);
        if (!$event_id||!$aire_id||!$juge_id||!$eleve_id||!$epreuve_id){
            wp_send_json_error('Paramètres manquants',400);return;
        }
        $session = $this->db->get_jury_session($event_id);
        if (!$session){wp_send_json_error('Session introuvable',404);return;}
        // Bloquer la notation si l'examen n'est pas en cours
        if ($session->statut !== 'en_cours'){
            wp_send_json_error('L\'examen n\'est pas encore ouvert. La notation sera possible une fois l\'examen lancé par l\'administrateur.',403);
            return;
        }
        global $wpdb;
        $tj   = $this->db->table_exam_juges();
        $juge = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tj WHERE id=%d AND event_id=%d",$juge_id,$event_id));
        if (!$juge){wp_send_json_error('Juge inconnu',403);return;}
        $tt       = $this->db->table_exam_tables();
$aire_row = $wpdb->get_row( $wpdb->prepare(
    "SELECT note_min, note_max FROM $tt WHERE id=%d AND event_id=%d",
    $aire_id, $event_id
));
$note_min = intval( $aire_row->note_min ?: $session->note_min );
$note_max = intval( $aire_row->note_max ?: $session->note_max );
        $delta    = isset($_POST['delta']) ? intval($_POST['delta']) : null;
        $note_val = isset($_POST['note_val']) ? floatval($_POST['note_val']) : null;
        if ($delta!==null) {
            $tn = $this->db->table_jury_notes();
            $cur = $wpdb->get_var($wpdb->prepare(
                "SELECT note FROM $tn WHERE event_id=%d AND eleve_id=%d AND epreuve_id=%d AND exam_table_id=%d AND juge_id=%d",
                $event_id,$eleve_id,$epreuve_id,$aire_id,$juge_id
            ));
            $note_val = floatval($cur!==null ? $cur : $note_min) + $delta;
        }
        $note_finale = max($note_min,min($note_max,floatval($note_val)));
        $this->db->save_jury_note($event_id,$eleve_id,$epreuve_id,$aire_id,$juge_id,trim($juge->prenom.' '.$juge->nom),$note_finale,$session);
        wp_send_json_success(array('eleve_id'=>$eleve_id,'epreuve_id'=>$epreuve_id,'juge_id'=>$juge_id,'note'=>$note_finale));
    }
	
    /**
     * Saisie manuelle admin (Step 5 papier) et PWA entraîneur.
     * Requiert nonce + manage_options.
     * Détermine l'aire depuis exam_affectations, crée le juge virtuel __admin__ si nécessaire.
     */
	 
public function ajax_save_note_admin() {
    $this->require_admin();
    $event_id   = intval( $_POST['event_id']   ?? 0 );
    $eleve_id   = intval( $_POST['eleve_id']   ?? 0 );
    $epreuve_id = intval( $_POST['epreuve_id'] ?? 0 );
    $note_val   = floatval( $_POST['note_val'] ?? 0 );
    $coups_val  = isset( $_POST['coups_val'] ) && $_POST['coups_val'] !== '' 
                  ? intval( $_POST['coups_val'] ) 
                  : null;

    if ( ! $event_id || ! $eleve_id || ! $epreuve_id ) {
        wp_send_json_error( 'Paramètres manquants', 400 ); return;
    }

    $session = $this->db->get_jury_session( $event_id );
    if ( ! $session ) { wp_send_json_error( 'Session introuvable', 404 ); return; }

    $aire_id = $this->db->get_aire_for_candidat( $event_id, $eleve_id );
    if ( ! $aire_id ) {
        $aires   = $this->db->get_jury_tables( $event_id );
        $aire_id = ! empty( $aires ) ? intval( $aires[0]->id ) : 0;
    }
    if ( ! $aire_id ) { wp_send_json_error( 'Aucune aire configurée', 404 ); return; }

    $juge = $this->db->get_or_create_admin_juge( $event_id, $aire_id );
    if ( ! $juge ) { wp_send_json_error( 'Impossible de créer le juge admin', 500 ); return; }

    global $wpdb;
    $tt       = $this->db->table_exam_tables();
    $aire_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT note_min, note_max FROM $tt WHERE id=%d AND event_id=%d",
        $aire_id, $event_id
    ) );
    $note_min    = intval( $aire_row->note_min ?? $session->note_min );
    $note_max    = intval( $aire_row->note_max ?? $session->note_max );
    $note_finale = max( $note_min, min( $note_max, $note_val ) );

    $result = $this->db->save_note_admin( $event_id, $eleve_id, $epreuve_id, $aire_id, $juge->id, $note_finale, $coups_val );

    if ( ! $result ) {
        wp_send_json_error( 'Erreur sauvegarde note', 500 ); return;
    }

    wp_send_json_success( array(
        'eleve_id'   => $eleve_id,
        'epreuve_id' => $epreuve_id,
        'note'       => $note_finale,
        'coups_val'  => $coups_val,
    ) );
}

    /* ── AJAX get_next_candidat ── */
    public function ajax_get_next_candidat() {
        $event_id   = intval($_POST['event_id']??0);
        $aire_id    = intval($_POST['aire_id']??0);
        $current_id = intval($_POST['current_eleve']??0);
        $direction  = sanitize_text_field($_POST['direction']??'next');
        $candidats  = $this->db->get_affectations_aire($event_id,$aire_id);
        if (empty($candidats)){wp_send_json_error('Aucun candidat',404);return;}
        $ids = array_column((array)$candidats,'id');
        $pos = array_search($current_id,$ids);
        $new_pos = ($direction==='next')
            ? (($pos===false)?0:min(count($ids)-1,$pos+1))
            : (($pos===false)?0:max(0,$pos-1));
        wp_send_json_success(array('eleve_id'=>$ids[$new_pos],'position'=>$new_pos+1,'total'=>count($ids)));
    }

    /* ── AJAX get_centrale ── */
    public function ajax_get_centrale() {
        $this->nonce(); $this->require_admin();
        $event_id = intval($_POST['event_id']??0);
        if (!$event_id){wp_send_json_error('event_id requis',400);return;}
        $session      = $this->db->get_jury_session($event_id);
        $aires        = $this->db->get_jury_tables($event_id);
        $juges        = $this->db->get_jury_juges($event_id);
        $affectations = $this->db->get_affectations($event_id);
        $non_affectes = $this->db->get_eleves_non_affectes($event_id);
        global $wpdb;
        $tn   = $this->db->table_jury_notes();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT eleve_id,epreuve_id,juge_id,exam_table_id as aire_id,note FROM $tn WHERE event_id=%d",$event_id
        ));
        $notes_idx = array();
        foreach ($rows as $r) $notes_idx[intval($r->eleve_id)][intval($r->epreuve_id)][intval($r->juge_id)] = floatval($r->note);
        $overrides = array();
        if (!empty($_POST['grade_overrides'])&&is_array($_POST['grade_overrides'])) {
            foreach ($_POST['grade_overrides'] as $eid=>$g) {
                $g=sanitize_text_field($g); if($g) $overrides[intval($eid)]=$g;
            }
        }
        // Index nb_juges par aire
        $juges_par_aire = array();
        foreach ( $juges as $j ) { $juges_par_aire[intval($j->table_id)] = ($juges_par_aire[intval($j->table_id)]??0)+1; }

        // En mode par_epreuve : construire [epreuve_id => nb_juges] en passant par l'aire
        $nb_juges_par_epreuve = array();
        if ( $session && $session->mode === 'par_epreuve' ) {
            foreach ( $aires as $aire ) {
                if ( ! empty($aire->epreuve_id) ) {
                    $nb_juges_par_epreuve[ intval($aire->epreuve_id) ] = $juges_par_aire[ intval($aire->id) ] ?? 0;
                }
            }
        }

        // Nb épreuves attendues : en par_epreuve = nb aires (une épreuve par aire)
        // en par_categorie = toutes les épreuves actives
        if ($session && $session->mode === 'par_epreuve') {
            $nb_epreuves_glob = count($aires);
        } else {
            $epreuves_session = $this->db->get_exam_epreuves(null, true);
            $nb_epreuves_glob = count($epreuves_session);
        }

        $scores = array();
        foreach ($affectations as $c) {
            $eid        = intval($c->eleve_id);
            // Mode par_epreuve : aire_id=0 → utiliser le tableau epreuve→nb_juges
            // Mode par_categorie : nb_juges de l'aire assignée
            if ( $session && $session->mode === 'par_epreuve' ) {
                $nb_juges_calc = $nb_juges_par_epreuve; // array [epreuve_id => nb_juges]
                $nb_juges_completude = $nb_juges_par_epreuve
                    ? (int) round( array_sum($nb_juges_par_epreuve) / max(1, count($nb_juges_par_epreuve)) )
                    : 1;
            } else {
                $nb_juges_calc       = $juges_par_aire[intval($c->aire_id)] ?? 1;
                $nb_juges_completude = $nb_juges_calc;
            }
            $notes      = $notes_idx[$eid] ?? array();
            $score      = self::calc_score_eleve($notes, $nb_juges_calc);
            $comp       = self::calc_completude($notes, $nb_juges_completude, $nb_epreuves_glob);
            $grade_auto = $this->db->get_grade_vise_eleve($c);
            $scores[$eid] = array(
                'score'=>$score,
                'admis'=>($session&&$score!==null)?($score>=floatval($session->seuil_admission)):null,
                'grade_vise'=>$overrides[$eid]??$grade_auto,
                'grade_auto'=>$grade_auto,
                'overridden'=>isset($overrides[$eid]),
                'completude'=>$comp,
            );
        }
        wp_send_json_success(array(
            'session'=>$session,'aires'=>$aires,'juges'=>$juges,
            'affectations'=>$affectations,'non_affectes'=>$non_affectes,
            'notes'=>$notes_idx,'scores'=>$scores,'ts'=>time(),
        ));
    }

    /* ── AJAX save_session ── */
    public function ajax_save_session() {
        $this->nonce(); $this->require_admin();
        $event_id = intval($_POST['event_id']??0);
        if (!$event_id){wp_send_json_error('event_id requis',400);return;}
        $data = array(
            'mode'             => in_array($_POST['mode']??'',['par_categorie','par_epreuve'])?$_POST['mode']:'par_categorie',
            'support_notation' => in_array($_POST['support_notation']??'',['numerique','papier'])?$_POST['support_notation']:'numerique',
            'note_min'         => intval($_POST['note_min']??0),
            'note_max'         => max(1,intval($_POST['note_max']??10)),
            'seuil_admission'  => floatval($_POST['seuil_admission']??0),
            'zemita_mode'      => intval($_POST['zemita_mode']??0) ? 1 : 0,
            'statut'           => in_array($_POST['statut']??'',['preparation','en_cours','termine'])?$_POST['statut']:'preparation',
        );
        $this->db->save_jury_session($event_id,$data);
        // Toujours sauvegarder les aires — même un tableau vide supprime les aires existantes
        $aires_raw = isset($_POST['aires']) && is_array($_POST['aires']) ? $_POST['aires'] : array();
$aires = array_map(function($a){return array(
    'numero'          => intval($a['numero']??1),
    'epreuve_id'      => intval($a['epreuve_id']??0)?:null,
    'label'           => sanitize_text_field($a['label']??''),
    'note_min'        => intval($a['note_min']??0),
    'note_max'        => max(1,intval($a['note_max']??10)),
    'type_verdict'    => in_array($a['type_verdict']??'',['seuil_fixe','zemita'])?$a['type_verdict']:'seuil_fixe',
    'seuil_admission' => floatval($a['seuil_admission']??0),
);},$aires_raw);
        $this->db->save_jury_tables($event_id,$aires);
        wp_send_json_success('Session sauvegardée.');
    }

    /* ── AJAX save_juges ── */
    public function ajax_save_juges() {
        $this->nonce(); $this->require_admin();
        $event_id = intval($_POST['event_id']??0);
        $juges_raw = $_POST['juges']??array();
        if (!is_array($juges_raw)){wp_send_json_error('Format invalide',400);return;}
        $juges = array_map(function($j){return array(
            'id'=>intval($j['id']??0)?:null,
            'table_id'=>intval($j['aire_id']??0),
            'nom'=>sanitize_text_field($j['nom']??''),
            'prenom'=>sanitize_text_field($j['prenom']??''),
            'trainer_id'=>intval($j['trainer_id']??0)?:null,
        );},$juges_raw);
        $this->db->save_jury_juges($event_id,$juges);
        wp_send_json_success('Juges sauvegardés.');
    }

    /* ── AJAX save_affectations ── */
    public function ajax_save_affectations() {
        $this->nonce(); $this->require_admin();
        $event_id  = intval($_POST['event_id']??0);
        $aire_id   = intval($_POST['aire_id']??0);
        $eleve_ids = array_map('intval',(array)($_POST['eleve_ids']??array()));
        if (!$event_id){wp_send_json_error('Paramètres manquants',400);return;}

        $session = $this->db->get_jury_session($event_id);

        if ($session && $session->mode === 'par_epreuve') {
            // Mode par épreuve : un candidat passe sur toutes les aires
            // → on l'affecte à toutes d'un seul coup
            $this->db->save_affectations_all_aires($event_id, $eleve_ids);
        } else {
            // Mode par catégorie : affectation à une aire spécifique
            if (!$aire_id){wp_send_json_error('Paramètres manquants',400);return;}
            $this->db->save_affectations_aire($event_id, $aire_id, $eleve_ids);
        }
        wp_send_json_success(count($eleve_ids).' candidat(s) affecté(s).');
    }

    /* ── AJAX reassign_candidat ── */
    public function ajax_reassign_candidat() {
        $this->nonce(); $this->require_admin();
        $event_id    = intval($_POST['event_id']??0);
        $eleve_id    = intval($_POST['eleve_id']??0);
        $new_aire_id = intval($_POST['new_aire_id']??0);
        if (!$event_id||!$eleve_id||!$new_aire_id){wp_send_json_error('Paramètres manquants',400);return;}
        $this->db->reassign_affectation($event_id,$eleve_id,$new_aire_id);
        wp_send_json_success('Candidat réaffecté.');
    }

    /* ── AJAX remove_candidat ── */
    public function ajax_remove_candidat() {
        $this->nonce(); $this->require_admin();
        $event_id = intval($_POST['event_id']??0);
        $eleve_id = intval($_POST['eleve_id']??0);
        if (!$event_id||!$eleve_id){wp_send_json_error('Paramètres manquants',400);return;}
        global $wpdb;
        $ta = $this->db->table_exam_affectations();
        $wpdb->delete($ta, array('event_id'=>$event_id,'eleve_id'=>$eleve_id));
        wp_send_json_success('Candidat retiré.');
    }

    /* ── AJAX reset_affectations ── */
    public function ajax_reset_affectations() {
        $this->nonce(); $this->require_admin();
        $event_id = intval($_POST['event_id']??0);
        if (!$event_id){wp_send_json_error('Paramètres manquants',400);return;}
        global $wpdb;
        $ta = $this->db->table_exam_affectations();
        $wpdb->delete($ta, array('event_id'=>$event_id));
        wp_send_json_success('Tous les candidats ont été retirés.');
    }

    /* ── AJAX save_zemita_seuils ── */
public function ajax_save_zemita_seuils() {
    $this->nonce(); $this->require_admin();
    $event_id   = intval( $_POST['event_id']   ?? 0 );
    $seuils     = $_POST['seuils']             ?? array();
    $epreuve_id = intval( $_POST['epreuve_id'] ?? 3 );
    if ( ! $event_id || ! is_array( $seuils ) ) { wp_send_json_error( 'Paramètres manquants', 400 ); return; }
    $this->db->save_zemita_seuils( $event_id, $seuils, $epreuve_id );
    if ( $epreuve_id === 3 ) $this->db->init_zemita_groupes( $event_id );
    wp_send_json_success( 'Seuils sauvegardés.' );
}

    /* ── AJAX save_zemita_mapping ── */
    public function ajax_save_zemita_mapping() {
        $this->nonce(); $this->require_admin();
        $mapping = $_POST['mapping'] ?? array();
        if ( ! is_array( $mapping ) ) { wp_send_json_error( 'Paramètres manquants', 400 ); return; }
        $this->db->save_zemita_mapping( $mapping );
        wp_send_json_success( 'Mapping ZEMITA sauvegardé.' );
    }

    /* ── AJAX save_zemita_groupe ── */
    public function ajax_save_zemita_groupe() {
        check_ajax_referer( 'sp_cal_admin_nonce', 'nonce' );
        $event_id = intval( $_POST['event_id'] ?? 0 );
        $eleve_id = intval( $_POST['eleve_id'] ?? 0 );
        $groupe   = sanitize_text_field( $_POST['groupe'] ?? '' );
        if ( ! $event_id || ! $eleve_id || ! $groupe ) { wp_send_json_error( 'Paramètres manquants', 400 ); return; }
        $this->db->save_zemita_groupe_candidat( $event_id, $eleve_id, $groupe );
        wp_send_json_success( array( 'groupe' => $groupe ) );
    }

    public function ajax_set_statut() {
        $this->nonce(); $this->require_admin();
        $event_id = intval($_POST['event_id']??0);
        $statut   = sanitize_text_field($_POST['statut']??'');
        if (!$event_id||!in_array($statut,['preparation','en_cours','termine'])){wp_send_json_error('Invalide',400);return;}
        global $wpdb;
        $wpdb->update($this->db->table_exam_sessions(),array('statut'=>$statut),array('event_id'=>$event_id));
        wp_send_json_success($statut);
    }

    /* ── AJAX save_grade_progression ── */
    public function ajax_save_grade_progression() {
        $this->nonce(); $this->require_admin();
        $rows_raw = $_POST['rows']??array();
        if (!is_array($rows_raw)){wp_send_json_error('Format invalide',400);return;}
        $rows = array_filter(array_map(function($r){
            $ga=sanitize_text_field($r['grade_actuel']??''); $gs=sanitize_text_field($r['grade_suivant']??'');
            return ($ga&&$gs)?array('grade_actuel'=>$ga,'grade_suivant'=>$gs):null;
        },$rows_raw));
        $this->db->save_grade_progression(array_values($rows));
        wp_send_json_success('Progression sauvegardée.');
    }

    /* ── AJAX get_qr_url ── */
    public function ajax_get_qr_url() {
    $this->nonce(); $this->require_admin();
    $event_id = intval($_POST['event_id']??0);
    $aire_id  = intval($_POST['aire_id']??0);
    if (!$event_id||!$aire_id){wp_send_json_error('Paramètres manquants',400);return;}
    
    // Nouvelle URL mobile
    $mobile = new SpCalPro_JuryMobile( $this->db );
    $token  = $mobile->generate_token( $event_id, $aire_id );
    $url    = add_query_arg( array(
        'jury_mobile' => '1',
        'event_id'    => $event_id,
        'aire_id'     => $aire_id,
        'token'       => $token,
    ), home_url('/') );
    
    $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($url);
    wp_send_json_success(array('url'=>$url,'qr'=>$qr));
}

    /* ══════════════════════════════════════════════════════════
       PASSE 2 — AJAX TRANSCRIPTION GRADES
    ══════════════════════════════════════════════════════════ */

    public function ajax_preview_grades() {
        $this->nonce(); $this->require_admin();
        $event_id  = intval($_POST['event_id'] ?? 0);
        if (!$event_id) { wp_send_json_error('event_id requis',400); return; }
        $overrides = array();
        if (!empty($_POST['overrides']) && is_array($_POST['overrides'])) {
            foreach ($_POST['overrides'] as $eid => $g) {
                $g = sanitize_text_field($g);
                $overrides[intval($eid)] = $g;
            }
        }
        $preview  = $this->db->preview_grade_transcription($event_id, $overrides);
        $snapshot = $this->db->generate_snapshot_sql($event_id);
        wp_send_json_success(array('preview'=>$preview,'snapshot'=>$snapshot));
    }

    public function ajax_write_grades() {
        $this->nonce(); $this->require_admin();
        $event_id  = intval($_POST['event_id'] ?? 0);
        $confirmed = intval($_POST['confirmed'] ?? 0);
        if (!$event_id)   { wp_send_json_error('event_id requis',400); return; }
        if (!$confirmed)  { wp_send_json_error('Confirmation requise',400); return; }

        // Overrides finaux
        $overrides = array();
        if (!empty($_POST['overrides']) && is_array($_POST['overrides'])) {
            foreach ($_POST['overrides'] as $eid => $g) {
                $g = sanitize_text_field($g);
                $overrides[intval($eid)] = $g;
            }
        }

        // Vérifier session terminée
        $session = $this->db->get_jury_session($event_id);
        if (!$session) { wp_send_json_error('Session introuvable',404); return; }
        if ($session->statut !== 'termine') {
            wp_send_json_error('La session doit être terminée avant transcription.',403); return;
        }

        // Snapshot SQL sauvegardé en option WP (récupérable)
        $snapshot = $this->db->generate_snapshot_sql($event_id);
        update_option('sp_jury_snapshot_' . $event_id, $snapshot);

        // Prévisualisation avec overrides → lignes à écrire
        $lignes = $this->db->preview_grade_transcription($event_id, $overrides);

        // Debug temporaire
        error_log('[SP_JURY] write_grades event_id=' . $event_id
            . ' statut=' . $session->statut
            . ' nb_lignes=' . count($lignes)
            . ' overrides=' . json_encode($overrides));
        foreach ($lignes as $l) {
            error_log('[SP_JURY] ligne: eleve=' . ($l['prenom']??'') . ' ' . ($l['nom']??'')
                . ' admis=' . json_encode($l['admis'])
                . ' score=' . json_encode($l['score'])
                . ' ecrire=' . json_encode($l['ecrire'])
                . ' grade_nouveau=' . ($l['grade_nouveau']??''));
        }

        // Écriture
        $result = $this->db->write_grade_transcription($event_id, $lignes);

        wp_send_json_success(array(
            'passages' => $result['passages'],
            'grades'   => $result['grades'],
            'snapshot' => $snapshot,
            'msg'      => $result['grades'] . ' grade(s) mis à jour, ' . $result['passages'] . ' passage(s) enregistrés.',
        ));
    }

    /* ── AJAX save_grade_contenu ── */
    public function ajax_save_grade_contenu() {
        $this->nonce(); $this->require_admin();
        $grade      = sanitize_text_field($_POST['grade_actuel'] ?? '');
        $desc       = wp_kses_post($_POST['description'] ?? '');
        $liens_raw  = $_POST['liens'] ?? array();
        if (!$grade) { wp_send_json_error('Grade requis',400); return; }
        // Valider et nettoyer les liens
        $liens = array();
        if (is_array($liens_raw)) {
            foreach ($liens_raw as $l) {
                $label = sanitize_text_field($l['label'] ?? '');
                $url   = esc_url_raw($l['url'] ?? '');
                $type  = in_array($l['type'] ?? '', ['lien','video','image','pdf']) ? $l['type'] : 'lien';
                if ($url) $liens[] = compact('label','url','type');
            }
        }
        $this->db->save_grade_contenu($grade, $desc, json_encode($liens));
        wp_send_json_success('Contenu sauvegardé.');
    }

    /* ── AJAX get_grade_contenu ── */
    // Accessible sans authentification (page token élève/parent)
    public function ajax_get_grade_contenu() {
        $grade = sanitize_text_field($_REQUEST['grade_actuel'] ?? '');
        if (!$grade) { wp_send_json_error('Grade requis',400); return; }
        $row = $this->db->get_grade_contenu($grade);
        $liens = array();
        if ($row && $row->liens) {
            $liens = json_decode($row->liens, true) ?: array();
        }
        wp_send_json_success(array(
            'grade_actuel' => $grade,
            'description'  => $row ? $row->description : '',
            'liens'        => $liens,
        ));
    }

    public function ajax_recalculate_zemita() {
    $this->nonce(); $this->require_admin();
    $event_id = intval( $_POST['event_id'] ?? 0 );
    if ( ! $event_id ) {
        wp_send_json_error( 'event_id manquant' ); return;
    }
    $updated = $this->db->recalculate_zemita_groupes( $event_id );
    wp_send_json_success( array(
        'updated' => $updated,
        'message' => "$updated candidat(s) mis à jour",
    ) );
}
/**
 * À ajouter dans class-jury.php
 * 
 * 1. Dans le __construct, ajouter ces lignes :
 *    add_action( 'wp_ajax_sp_jury_mobile_save_notes',        array( $this, 'ajax_mobile_save_notes' ) );
 *    add_action( 'wp_ajax_nopriv_sp_jury_mobile_save_notes', array( $this, 'ajax_mobile_save_notes' ) );
 *
 * 2. Dans maybe_render_aire() ou l'équivalent, ajouter la détection jury_mobile=1 :
 *    if ( ! empty( $_GET['jury_mobile'] ) && $_GET['jury_mobile'] === '1' ) {
 *        $mobile = new SpCalPro_JuryMobile( $this->db );
 *        $mobile->render();
 *        return;
 *    }
 *
 * 3. Ajouter la méthode ajax_mobile_save_notes() ci-dessous dans la classe.
 */

    /**
     * AJAX — Sauvegarde des notes depuis l'interface mobile juge.
     * Accepte note_val (note finale) + coups_val (coups bruts optionnel).
     * Pas de vérification manage_options — accès via token QR uniquement.
     */
    public function ajax_mobile_save_notes(): void {
        // Vérification nonce
        if ( ! check_ajax_referer( 'sp_jury_mobile_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Nonce invalide', 403 );
        }

        $event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;
        $aire_id  = isset( $_POST['aire_id']  ) ? (int) $_POST['aire_id']  : 0;
        $eleve_id = isset( $_POST['eleve_id'] ) ? (int) $_POST['eleve_id'] : 0;
        $notes_raw = isset( $_POST['notes'] )   ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '';

        if ( ! $event_id || ! $aire_id || ! $eleve_id || ! $notes_raw ) {
            wp_send_json_error( 'Données manquantes' );
        }

        $notes = json_decode( $notes_raw, true );
        if ( ! is_array( $notes ) || empty( $notes ) ) {
            wp_send_json_error( 'Format invalide' );
        }

        // Vérifier que l'examen est en cours
        $session = $this->db->get_jury_session( $event_id );
        if ( ! $session || $session->statut !== 'en_cours' ) {
            wp_send_json_error( 'Session non active', 403 );
        }

        // Vérifier que l'élève est bien affecté à cette aire
        $aire_eleve = $this->db->get_aire_for_candidat( $event_id, $eleve_id );
        if ( $aire_eleve !== $aire_id && $aire_eleve !== 0 ) {
            wp_send_json_error( 'Candidat non affecté à cette aire', 403 );
        }

        // Récupérer ou créer le juge admin pour cette aire
        $juge = $this->db->get_or_create_admin_juge( $event_id, $aire_id );
        if ( ! $juge ) {
            wp_send_json_error( 'Impossible de créer le juge', 500 );
        }

        // Limites de notes
        global $wpdb;
        $tt       = $this->db->table_exam_tables();
        $aire_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT note_min, note_max FROM $tt WHERE id=%d AND event_id=%d",
            $aire_id, $event_id
        ) );
        $note_min = intval( $aire_row->note_min ?? $session->note_min );
        $note_max = intval( $aire_row->note_max ?? $session->note_max );

        // Sauvegarder chaque note
        $saved = 0;
        foreach ( $notes as $entry ) {
            $epreuve_id = isset( $entry['epreuve_id'] ) ? (int)   $entry['epreuve_id'] : 0;
            $note_val   = isset( $entry['note_val']   ) ? (float) $entry['note_val']   : null;
            $coups_val  = isset( $entry['coups_val']  ) && $entry['coups_val'] !== null
                          ? (int) $entry['coups_val'] : null;

            if ( ! $epreuve_id || $note_val === null ) continue;

            // Borner la note
            $note_finale = max( $note_min, min( $note_max, $note_val ) );

            $result = $this->db->save_note_admin(
                $event_id, $eleve_id, $epreuve_id,
                $aire_id, $juge->id,
                $note_finale, $coups_val
            );

            if ( $result ) $saved++;
        }

        if ( $saved === 0 ) {
            wp_send_json_error( 'Aucune note sauvegardée' );
        }

        wp_send_json_success( array(
            'saved'    => $saved,
            'eleve_id' => $eleve_id,
        ) );
    }
}
endif; // class_exists SpCalPro_Jury

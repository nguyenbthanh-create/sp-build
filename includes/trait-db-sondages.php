<?php
/**
 * Requêtes « sondages post-événement ».
 *
 * Méthodes de SpCalPro_DB déplacées telles quelles depuis class-db.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_DB_Sondages; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_DB_Sondages {

/**
 * Sauvegarde les questions d'un sondage (remplace toutes les questions existantes)
 * Appelé uniquement si le sondage n'a pas encore été envoyé
 */
public function save_sondage_questions( int $sondage_id, array $questions ): bool {
    global $wpdb;

    // Sécurité : on ne modifie pas si déjà envoyé
    $sondage = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT envoye FROM {$wpdb->prefix}sp_cal_sondages WHERE id = %d",
            $sondage_id
        )
    );
    if ( ! $sondage || $sondage->envoye ) {
        return false;
    }

    $table_q = $wpdb->prefix . 'sp_cal_sondage_questions';

    // Supprime les questions existantes avant de réinsérer
    $wpdb->delete( $table_q, [ 'sondage_id' => $sondage_id ], [ '%d' ] );

    foreach ( $questions as $ordre => $q ) {
        $type       = isset( $q['type'] ) && $q['type'] === 'note' ? 'note' : 'texte';
        $question   = isset( $q['question'] ) ? sanitize_text_field( wp_unslash( $q['question'] ) ) : '';
        $obligatoire = ! empty( $q['obligatoire'] ) ? 1 : 0;

        if ( $question === '' ) {
            continue;
        }

        $wpdb->insert( $table_q, [
            'sondage_id'  => $sondage_id,
            'type'        => $type,
            'question'    => $question,
            'ordre'       => (int) $ordre,
            'obligatoire' => $obligatoire,
        ], [ '%d', '%s', '%s', '%d', '%d' ] );
    }

    return true;
}

/**
 * Récupère les questions d'un sondage triées par ordre
 */
public function get_sondage_questions( int $sondage_id ): array {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sp_cal_sondage_questions
             WHERE sondage_id = %d
             ORDER BY ordre ASC",
            $sondage_id
        ),
        ARRAY_A
    ) ?: [];
}

/**
 * Enregistre les réponses d'un élève à un sondage
 * Idempotent : INSERT ... ON DUPLICATE KEY UPDATE
 */
public function save_sondage_reponses( int $sondage_id, int $eleve_id, array $reponses ): bool {
    global $wpdb;
    $table_r = $wpdb->prefix . 'sp_cal_sondage_reponses';

    // Vérifie que le sondage existe et est actif
    $sondage = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, actif FROM {$wpdb->prefix}sp_cal_sondages WHERE id = %d",
            $sondage_id
        )
    );
    if ( ! $sondage || ! $sondage->actif ) {
        return false;
    }

    foreach ( $reponses as $question_id => $valeur ) {
        $question_id = (int) $question_id;

        // Récupère le type de la question
        $q = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT type FROM {$wpdb->prefix}sp_cal_sondage_questions
                 WHERE id = %d AND sondage_id = %d",
                $question_id,
                $sondage_id
            )
        );
        if ( ! $q ) {
            continue;
        }

        $note  = null;
        $texte = null;

        if ( $q->type === 'note' ) {
            $note = max( 1, min( 5, (int) $valeur ) );
        } else {
            $texte = sanitize_textarea_field( wp_unslash( (string) $valeur ) );
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table_r}
                 (sondage_id, question_id, eleve_id, reponse_note, reponse_texte, date_reponse)
                 VALUES (%d, %d, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                   reponse_note  = VALUES(reponse_note),
                   reponse_texte = VALUES(reponse_texte),
                   date_reponse  = VALUES(date_reponse)",
                $sondage_id,
                $question_id,
                $eleve_id,
                $note,
                $texte,
                current_time( 'mysql' )
            )
        );
    }

    return true;
}

/**
 * Vérifie si un élève a déjà répondu à un sondage
 */
public function eleve_a_repondu_sondage( int $sondage_id, int $eleve_id ): bool {
    global $wpdb;

    $count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sp_cal_sondage_reponses
             WHERE sondage_id = %d AND eleve_id = %d",
            $sondage_id,
            $eleve_id
        )
    );

    return (int) $count > 0;
}

/**
 * Résultats agrégés d'un sondage pour l'admin
 * Retourne par question : moyenne (note), liste textes, nb réponses
 */
public function get_sondage_resultats( int $sondage_id ): array {
    global $wpdb;

    $questions = $this->get_sondage_questions( $sondage_id );
    $resultats = [];

    foreach ( $questions as $q ) {
        $qid = (int) $q['id'];
        $res = [ 'question' => $q, 'nb_reponses' => 0, 'moyenne' => null, 'textes' => [] ];

        if ( $q['type'] === 'note' ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) as nb, AVG(reponse_note) as moy
                     FROM {$wpdb->prefix}sp_cal_sondage_reponses
                     WHERE question_id = %d AND reponse_note IS NOT NULL",
                    $qid
                ),
                ARRAY_A
            );
            $res['nb_reponses'] = (int) ( $row['nb'] ?? 0 );
            $res['moyenne']     = $row['moy'] !== null ? round( (float) $row['moy'], 2 ) : null;
        } else {
            $textes = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT reponse_texte
                     FROM {$wpdb->prefix}sp_cal_sondage_reponses
                     WHERE question_id = %d AND reponse_texte IS NOT NULL AND reponse_texte != ''",
                    $qid
                )
            );
            $res['nb_reponses'] = count( $textes );
            $res['textes']      = $textes;
        }

        $resultats[] = $res;
    }

    return $resultats;
}

/**
 * Marque un sondage comme envoyé
 */
public function marquer_sondage_envoye( int $sondage_id ): void {
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'sp_cal_sondages',
        [ 'envoye' => 1, 'date_envoi' => current_time( 'mysql' ) ],
        [ 'id' => $sondage_id ],
        [ '%d', '%s' ],
        [ '%d' ]
    );
}

/**
 * Récupère les sondages à envoyer (événement passé J+1, non encore envoyés)
 */
public function get_sondages_a_envoyer(): array {
    global $wpdb;

    return $wpdb->get_results(
        "SELECT s.*, e.titre, e.date_fin
         FROM {$wpdb->prefix}sp_cal_sondages s
         INNER JOIN {$wpdb->prefix}sp_cal_events e ON e.id = s.event_id
         WHERE s.envoye = 0
           AND s.actif = 1
           AND DATE(e.date_fin) < CURDATE()
           AND (SELECT COUNT(*) FROM {$wpdb->prefix}sp_cal_sondage_questions q WHERE q.sondage_id = s.id) > 0",
        ARRAY_A
    ) ?: [];
}

/**
 * Sondages auxquels un élève peut encore répondre :
 * événement passé, sondage envoyé, élève inscrit, pas encore répondu
 */
public function get_sondages_en_attente_eleve( int $eleve_id ): array {
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.id as sondage_id, s.event_id, e.titre,
                e.date as event_date,
                (SELECT COUNT(*) FROM {$wpdb->prefix}sp_cal_sondage_questions q WHERE q.sondage_id = s.id) as nb_questions
         FROM {$wpdb->prefix}sp_cal_sondages s
         INNER JOIN {$wpdb->prefix}sp_cal_events e ON e.id = s.event_id
         INNER JOIN {$wpdb->prefix}sp_cal_event_inscriptions i
                 ON i.event_id = s.event_id AND i.eleve_id = %d AND i.reponse = 'oui'
         WHERE s.actif  = 1
           AND s.envoye = 1
           AND DATE(e.date) < CURDATE()
           AND NOT EXISTS (
               SELECT 1 FROM {$wpdb->prefix}sp_cal_sondage_reponses r
               WHERE r.sondage_id = s.id AND r.eleve_id = %d
           )
         ORDER BY e.date DESC",
        $eleve_id,
        $eleve_id
    ), ARRAY_A ) ?: [];

    foreach ( $rows as &$row ) {
        $row['date_fmt'] = $row['event_date']
            ? date_create( $row['event_date'] )->format( 'd/m/Y' )
            : '—';
    }
    return $rows;
}
}

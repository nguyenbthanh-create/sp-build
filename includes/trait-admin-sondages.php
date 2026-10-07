<?php
/**
 * Page d'administration « Sondages » (résultats des sondages post-événement).
 *
 * Méthodes de SpCalPro_Admin déplacées telles quelles depuis class-admin.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_Admin_Sondages; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_Admin_Sondages {




    /* ══════════════════════════════════════════════════════════
       PAGE SONDAGES — RÉSULTATS
    ══════════════════════════════════════════════════════════ */

    public function page_sondages(): void {
        global $wpdb;

        $event_id = intval( $_GET['event_id'] ?? 0 );
        $ts       = $wpdb->prefix . 'sp_cal_sondages';
        $te       = $this->db->table_events();

        // ── VUE LISTE ────────────────────────────────────────────────────────
        if ( ! $event_id ) {
            $sondages = $wpdb->get_results(
                "SELECT s.*, e.titre, e.date as event_date,
                        (SELECT COUNT(*) FROM {$wpdb->prefix}sp_cal_sondage_questions q WHERE q.sondage_id = s.id) as nb_questions,
                        (SELECT COUNT(DISTINCT r.eleve_id) FROM {$wpdb->prefix}sp_cal_sondage_reponses r WHERE r.sondage_id = s.id) as nb_repondants
                 FROM $ts s
                 INNER JOIN $te e ON e.id = s.event_id
                 ORDER BY e.date DESC
                 LIMIT 100"
            );

            echo '<div class="wrap"><h1>🗳️ Sondages post-événement</h1>';

            if ( empty( $sondages ) ) {
                echo '<p>Aucun sondage créé. Ouvrez un événement dans le calendrier et créez des questions dans l\'onglet Sondage.</p>';
                echo '</div>';
                return;
            }

            echo '<table class="widefat striped"><thead><tr>
                <th>Événement</th>
                <th>Date</th>
                <th>Questions</th>
                <th>Statut envoi</th>
                <th>Répondants</th>
                <th>Actions</th>
            </tr></thead><tbody>';

            foreach ( $sondages as $s ) {
                $url      = admin_url( 'admin.php?page=sp-cal-sondages&event_id=' . intval( $s->event_id ) );
                $date_fmt = $s->event_date ? date_create( $s->event_date )->format( 'd/m/Y' ) : '—';
                $envoye   = intval( $s->envoye );
                $statut   = $envoye
                    ? '<span style="color:#15803d;">✅ Envoyé le ' . esc_html( $s->date_envoi ? date_create($s->date_envoi)->format('d/m/Y') : '—' ) . '</span>'
                    : '<span style="color:#92400e;">⏳ Non envoyé</span>';

                echo '<tr>';
                echo '<td><a href="' . esc_url( $url ) . '"><strong>' . esc_html( $s->titre ) . '</strong></a></td>';
                echo '<td>' . esc_html( $date_fmt ) . '</td>';
                echo '<td style="text-align:center;">' . intval( $s->nb_questions ) . '</td>';
                echo '<td>' . $statut . '</td>';
                echo '<td style="text-align:center;">' . ( $envoye ? '<strong>' . intval( $s->nb_repondants ) . '</strong>' : '—' ) . '</td>';
                echo '<td>';
                if ( $envoye && intval( $s->nb_repondants ) > 0 ) {
                    echo '<a href="' . esc_url( $url ) . '" class="button button-small button-primary">📊 Voir résultats</a>';
                } elseif ( ! $envoye ) {
                    echo '<span style="color:#9ca3af;font-size:12px;">Sondage non encore envoyé</span>';
                } else {
                    echo '<span style="color:#9ca3af;font-size:12px;">Aucune réponse</span>';
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
            return;
        }

        // ── VUE DÉTAIL ───────────────────────────────────────────────────────
        $event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $te WHERE id = %d", $event_id ) );
        if ( ! $event ) {
            echo '<div class="wrap"><p>Événement introuvable.</p></div>';
            return;
        }

        $sondage = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $ts WHERE event_id = %d", $event_id
        ), ARRAY_A );
        if ( ! $sondage ) {
            echo '<div class="wrap"><p>Sondage introuvable pour cet événement.</p></div>';
            return;
        }

        $sondage_id   = intval( $sondage['id'] );
        $resultats    = $this->db->get_sondage_resultats( $sondage_id );
        $date_fmt     = $event->date ? date_create( $event->date )->format( 'd/m/Y' ) : '—';
        $back_url     = admin_url( 'admin.php?page=sp-cal-sondages' );

        // Taux de participation
        $nb_invites   = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT eleve_id) FROM {$wpdb->prefix}sp_cal_sondage_reponses WHERE sondage_id = %d",
            $sondage_id
        ) );
        ?>
        <div class="wrap">
            <h1>🗳️ Sondage — <?php echo esc_html( $event->titre ); ?></h1>
            <p>
                <a href="<?php echo esc_url( $back_url ); ?>" class="button">← Retour</a>
                &nbsp;
                <strong>📅 <?php echo esc_html( $date_fmt ); ?></strong>
                &nbsp;&nbsp;
                <strong><?php echo intval( $nb_invites ); ?> répondant(s)</strong>
                <?php if ( $sondage['date_envoi'] ) : ?>
                &nbsp;&nbsp;
                <span style="color:#6b7280;">Envoyé le <?php echo esc_html( date_create($sondage['date_envoi'])->format('d/m/Y') ); ?></span>
                <?php endif; ?>
            </p>
            <hr>

            <?php if ( empty( $resultats ) ) : ?>
                <p style="color:#9ca3af;">Aucune réponse enregistrée.</p>
            <?php else : ?>
                <?php foreach ( $resultats as $res ) :
                    $q = $res['question'];
                    ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin-bottom:20px;">

                    <h3 style="margin:0 0 4px;font-size:15px;">
                        <?php echo esc_html( $q['question'] ); ?>
                        <span style="font-size:12px;color:#9ca3af;font-weight:normal;margin-left:8px;">
                            <?php echo $q['type'] === 'note' ? '⭐ Note 1-5' : '💬 Texte libre'; ?>
                        </span>
                    </h3>
                    <p style="margin:0 0 12px;color:#6b7280;font-size:13px;"><?php echo intval( $res['nb_reponses'] ); ?> réponse(s)</p>

                    <?php if ( $q['type'] === 'note' && $res['moyenne'] !== null ) :
                        $moy = (float) $res['moyenne'];
                        $pct = round( ( $moy / 5 ) * 100 );
                        // Distribution des notes
                        $distrib = $wpdb->get_results( $wpdb->prepare(
                            "SELECT reponse_note as note, COUNT(*) as nb
                             FROM {$wpdb->prefix}sp_cal_sondage_reponses
                             WHERE question_id = %d AND reponse_note IS NOT NULL
                             GROUP BY reponse_note ORDER BY reponse_note DESC",
                            intval( $q['id'] )
                        ) );
                        $total = array_sum( array_column( (array) $distrib, 'nb' ) );
                        ?>
                        <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
                            <div style="font-size:36px;font-weight:700;color:#1d4ed8;"><?php echo number_format( $moy, 1 ); ?></div>
                            <div>
                                <div style="font-size:20px;">
                                    <?php for ( $i = 1; $i <= 5; $i++ ) echo $i <= round($moy) ? '⭐' : '☆'; ?>
                                </div>
                                <div style="font-size:12px;color:#6b7280;">sur 5</div>
                            </div>
                        </div>
                        <?php foreach ( $distrib as $d ) :
                            $bar_pct = $total > 0 ? round( ( $d->nb / $total ) * 100 ) : 0;
                            ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;font-size:13px;">
                                <span style="width:20px;text-align:right;color:#6b7280;"><?php echo intval($d->note); ?>★</span>
                                <div style="flex:1;background:#f3f4f6;border-radius:4px;height:14px;overflow:hidden;">
                                    <div style="width:<?php echo $bar_pct; ?>%;background:#1d4ed8;height:100%;border-radius:4px;"></div>
                                </div>
                                <span style="width:40px;color:#6b7280;"><?php echo intval($d->nb); ?> (<?php echo $bar_pct; ?>%)</span>
                            </div>
                        <?php endforeach; ?>

                    <?php elseif ( $q['type'] === 'texte' ) : ?>
                        <?php if ( empty( $res['textes'] ) ) : ?>
                            <p style="color:#9ca3af;font-style:italic;">Aucun commentaire.</p>
                        <?php else : ?>
                            <div style="display:grid;gap:8px;">
                                <?php foreach ( $res['textes'] as $t ) : ?>
                                <div style="background:#f9fafb;border-left:3px solid #1d4ed8;padding:10px 14px;border-radius:0 6px 6px 0;font-size:13px;color:#374151;">
                                    <?php echo esc_html( $t ); ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
        <?php
    }
}

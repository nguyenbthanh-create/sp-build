<?php
/**
 * Page d'administration « Paramètres » de SP Calendar PRO (réglages du club, couleurs, convention de points, règlement intérieur, réinitialisations…).
 *
 * Méthodes de SpCalPro_Admin déplacées telles quelles depuis class-admin.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_Admin_Reglages; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_Admin_Reglages {


    public function page_settings() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        $pub             = get_option( 'sp_cal_public_link', '' );
        $fiche_membre_url= get_option( 'sp_cal_fiche_membre_url', '' );
        $api_key    = get_option( 'sp_cal_api_key', '' );
        $cat_colors = json_decode( get_option( 'sp_cal_cat_colors', '{}' ), true ) ?: array();

        global $wpdb;
        $cats = array_merge(
            $this->db->get_categories_eleves(),
            $wpdb->get_col( "SELECT DISTINCT categorie FROM {$this->db->table_events()} WHERE categorie != '' ORDER BY categorie" )
        );
        $cats = array_unique( $cats );
        sort( $cats );
        ?>
        <div class="wrap sp-cal-wrap">
        <h1>Paramètres SP Calendar PRO</h1>

        <?php $this->notice_flash( 'saved', 'Paramètres enregistrés.' ); ?>
        <?php if (isset($_GET['vac_saved'])) echo '<div class="notice notice-success is-dismissible"><p>✅ Couleurs calendrier enregistrées.</p></div>'; ?>
        <?php if (isset($_GET['pts_saved'])) echo '<div class="notice notice-success is-dismissible"><p>✅ Convention médaille → points enregistrée.</p></div>'; ?>
        <?php if (isset($_GET['fiche_created'])) echo '<div class="notice notice-success is-dismissible"><p>✅ Page "Ma fiche" créée et URL configurée automatiquement.</p></div>'; ?>
        <?php if (isset($_GET['reglement_saved'])) echo '<div class="notice notice-success is-dismissible"><p>✅ Règlement intérieur enregistré.</p></div>'; ?>
        <?php if (isset($_GET['events_reset'])) echo '<div class="notice notice-success is-dismissible"><p>✅ Cours et événements réinitialisés.</p></div>'; ?>
        <?php if (isset($_GET['eleves_reset'])) echo '<div class="notice notice-success is-dismissible"><p>✅ Élèves et présences réinitialisés.</p></div>'; ?>
        <?php $medal_pts = $this->db->get_medal_points(); ?>

        <!-- ÉTAT DE LA BASE DE DONNÉES (class-schema.php, 07/10/2026) -->
        <?php SP_Cal_Schema::render_statut(); ?>

        <!-- INFORMATIONS CLUB -->
        <div class="sp-box" style="margin-bottom:18px;">
            <h2>🏛️ Informations du club</h2>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_settings' ); ?>
                <input type="hidden" name="sp_cal_save_settings" value="1">
                <table class="form-table">
                    <tr>
                        <th>N° de club</th>
                        <td><input type="text" name="sp_cal_club_num" class="regular-text"
                                   value="<?php echo esc_attr(get_option('sp_cal_club_num','')); ?>"
                                   placeholder="Ex: 066001"></td>
                    </tr>
                    <tr>
                        <th>N° d'affiliation FFTDA</th>
                        <td><input type="text" name="sp_cal_club_affiliation" class="regular-text"
                                   value="<?php echo esc_attr(get_option('sp_cal_club_affiliation','')); ?>"
                                   placeholder="N° affiliation fédérale"></td>
                    </tr>
                    <tr>
                        <th>Ligue</th>
                        <td><input type="text" name="sp_cal_club_ligue" class="regular-text"
                                   value="<?php echo esc_attr(get_option('sp_cal_club_ligue','')); ?>"
                                   placeholder="Ex: Ligue Occitanie"></td>
                    </tr>
                    <tr>
                        <th>Club labellisé</th>
                        <td>
                            <select name="sp_cal_club_labelise" style="padding:5px 8px;border:1px solid #ddd;border-radius:4px;">
                                <?php
                                $labelise = intval(get_option('sp_cal_club_labelise', 0));
                                $options = array(0=>'Non labellisé', 1=>'⭐ 1 étoile', 2=>'⭐⭐ 2 étoiles', 3=>'⭐⭐⭐ 3 étoiles', 4=>'⭐⭐⭐⭐ 4 étoiles', 5=>'⭐⭐⭐⭐⭐ 5 étoiles');
                                foreach ($options as $v => $l) echo '<option value="'.$v.'"'.selected($labelise,$v,false).'>'.$l.'</option>';
                                ?>
                            </select>
                            <p class="description">Affiché discrètement sur la carte de membre.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" name="sp_cal_save_settings" class="button button-primary" value="Enregistrer les informations club"></p>
            </form>
        </div>

        <!-- GÉNÉRAL -->
        <div class="sp-box">
            <h2>⚙️ Général</h2>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th>URL planning public</th>
                        <td><input type="url" name="sp_cal_public_link" class="large-text" value="<?php echo esc_attr($pub); ?>"></td>
                    </tr>
                    <tr>
                        <th>Clé API</th>
                        <td><code><?php echo esc_html($api_key); ?></code></td>
                    </tr>
                    <tr>
                        <th>Shortcodes</th>
                        <td>
                            Planning hebdo : <code>[sp_cal_planning]</code><br>
                            Prochains événements : <code>[sp_cal_evenements]</code><br>
                            Fiche membre : <code>[sp_cal_fiche_membre]</code><br>
                            Liste des élèves : <code>[sp_cal_eleves]</code><br>
                            Palmarès public : <code>[sp_cal_palmares]</code>
                        </td>
                    </tr>
                </table>

                <!-- Couleurs catégories -->
                <h3>🎨 Couleurs &amp; icônes des catégories</h3>
                <?php if ( empty($cats) ) : ?>
                    <p class="sp-muted">Aucune catégorie trouvée (importez d'abord des élèves ou créez des événements).</p>
                <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-bottom:16px;">
                    <thead><tr>
                        <th style="width:200px;">Catégorie</th>
                        <th style="width:120px;">Couleur</th>
                        <th style="width:180px;">Icône</th>
                        <th>Aperçu</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $cats as $cat ) :
                        $key   = md5($cat);  // md5 = [0-9a-f] uniquement, safe pour ID HTML et jQuery
                        $saved = $cat_colors[$cat] ?? array();
                        $color = $saved['color'] ?? '#2271b1';
                        $icon  = $saved['icon']  ?? '';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($cat); ?></strong></td>
                        <!-- Champ caché pour retrouver le vrai nom de catégorie côté PHP -->
                        <input type="hidden" name="cat_name[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($cat); ?>">
                        <td><input type="color" name="cat_color[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($color); ?>"
                            oninput="document.getElementById('prev_<?php echo esc_attr($key); ?>').style.background=this.value"></td>
                        <td>
                            <input type="hidden" name="cat_icon[<?php echo esc_attr($key); ?>]" id="icon_input_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($icon); ?>">
                            <button type="button" class="sp-emoji-btn button" data-key="<?php echo esc_attr($key); ?>" style="font-size:18px;min-width:44px;"><?php echo esc_html($icon ?: '＋'); ?></button>
                            <?php if ($icon) echo '<button type="button" class="sp-emoji-clear button" data-key="' . esc_attr($key) . '" style="margin-left:4px;color:#b91c1c;">✕</button>'; ?>
                        </td>
                        <td>
                            <span id="prev_<?php echo esc_attr($key); ?>" style="display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:20px;font-size:12px;color:#fff;background:<?php echo esc_attr($color); ?>;">
                                <span id="prev_icon_<?php echo esc_attr($key); ?>"><?php echo $icon ? esc_html($icon) . ' ' : ''; ?></span><?php echo esc_html($cat); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <input type="submit" name="sp_cal_save_settings" class="button button-primary" value="Enregistrer les paramètres">
            </form>
        </div>

        <!-- RÈGLEMENT INTÉRIEUR (popup formulaire d'adhésion public) -->
        <div class="sp-box" style="margin-top:18px;">
            <h2>📄 Règlement intérieur</h2>
            <p class="description" style="margin-bottom:12px;">
                Ce texte est affiché dans la fenêtre popup du formulaire d'adhésion public (<code>[sp_inscription_adhesion]</code>)
                lorsque l'adhérent clique sur « règlement intérieur ». Quelques balises HTML simples sont acceptées (paragraphes, gras, listes, liens).
            </p>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_reglement' ); ?>
                <input type="hidden" name="sp_cal_save_reglement" value="1">
                <textarea name="sp_cal_reglement_interieur" rows="14" style="width:100%;max-width:900px;font-family:monospace;font-size:13px;"><?php
                    echo esc_textarea( get_option( 'sp_cal_reglement_interieur', '' ) );
                ?></textarea>
                <p class="submit"><input type="submit" name="sp_cal_save_reglement" class="button button-primary" value="Enregistrer le règlement intérieur"></p>
            </form>
        </div>

        <!-- VEILLE RÉGLEMENTAIRE (pense-bête, pas d'appel automatique) -->
        <div class="sp-box" style="margin-top:18px;">
            <h2>📋 Veille réglementaire</h2>
            <?php if ( isset( $_GET['veille_demandee'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ Demande de veille enregistrée. Pensez à ouvrir une conversation avec Claude Code et à lui demander de lancer la veille réglementaire pour ce plugin.</p></div>
            <?php endif; ?>
            <p class="description" style="margin-bottom:10px;">
                Le plugin s'appuie sur 3 hypothèses réglementaires codées en dur, à revérifier de temps en temps auprès des sources officielles :
            </p>
            <ul style="margin:0 0 12px 20px;list-style:disc;font-size:13px;color:#374151;">
                <li>Certificat médical Taekwondo (FFTDA) : renouvellement chaque année (12 mois par défaut, réglable ci-dessus dans 🪪 Adhésions).</li>
                <li>Renforcement musculaire : certificat médical seulement en 1ère inscription adulte, sinon questionnaire de santé QS-Sport.</li>
                <li>Pass'Sport : aide de 50€, code déclaratif, aucune API de vérification connue côté Compte Asso.</li>
            </ul>
            <p class="description" style="margin-bottom:10px;">
                Ce bouton n'interroge rien automatiquement — il enregistre simplement la date de votre demande, comme un pense-bête. La vérification elle-même se fait en demandant à Claude Code de « lancer la veille réglementaire » dans une conversation : il consultera les sources officielles et vous fera un rapport.
            </p>
            <?php $veille_demandee_le = get_option( 'sp_cal_veille_reglementaire_demandee_le', '' ); ?>
            <p style="margin-bottom:10px;font-size:13px;">
                Dernière demande de veille :
                <strong><?php echo $veille_demandee_le ? esc_html( date( 'd/m/Y', strtotime( $veille_demandee_le ) ) ) : 'jamais'; ?></strong>
            </p>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_veille_reglementaire' ); ?>
                <input type="hidden" name="sp_veille_reglementaire_demandee" value="1">
                <input type="submit" class="button button-primary" value="🔍 Demander une veille réglementaire">
            </form>
        </div>

        <!-- COULEURS CALENDRIER — WEEKENDS & VACANCES ZONE C -->
        <div class="sp-box">
            <h2>📅 Fonds de couleur — Calendrier &amp; Planning</h2>
            <p class="description" style="margin-bottom:16px;">
                Coloration des cellules week-end et des périodes de vacances scolaires Zone C sur le calendrier admin et le planning public.
            </p>

            <!-- Couleurs -->
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_vacances' ); ?>
                <table class="form-table" style="max-width:480px;">
                    <tr>
                        <th style="width:200px;">🎨 Fond week-end</th>
                        <td>
                            <input type="color" name="sp_cal_we_color" value="<?php echo esc_attr( get_option('sp_cal_we_color','#f3f4f6') ); ?>" style="width:60px;height:32px;cursor:pointer;">
                            <span class="description" style="margin-left:8px;">Par défaut : gris clair</span>
                        </td>
                    </tr>
                    <tr>
                        <th>🌻 Fond vacances scolaires</th>
                        <td>
                            <input type="color" name="sp_cal_vac_color" value="<?php echo esc_attr( get_option('sp_cal_vac_color','#fef9c3') ); ?>" style="width:60px;height:32px;cursor:pointer;">
                            <span class="description" style="margin-left:8px;">Par défaut : jaune pâle</span>
                        </td>
                    </tr>
                </table>

                <!-- Saisie manuelle des vacances Zone C -->
                <h3>🗓️ Périodes de vacances scolaires Zone C</h3>
                <p class="description" style="margin-bottom:12px;">
                    Saisissez les périodes de vacances. Les dates de début et de fin sont <strong>incluses</strong>.<br>
                    Source officielle : <a href="https://www.education.gouv.fr/calendrier-scolaire-100148" target="_blank">education.gouv.fr/calendrier-scolaire</a>
                </p>

                <?php $vac_stored = SpCalPro_DB::get_vacances(); ?>

                <table class="wp-list-table widefat fixed" id="vac-table" style="max-width:720px;margin-bottom:10px;">
                    <thead><tr>
                        <th style="width:220px;">Libellé (ex: Toussaint 2025)</th>
                        <th style="width:160px;">Début</th>
                        <th style="width:160px;">Fin</th>
                        <th style="width:40px;"></th>
                    </tr></thead>
                    <tbody id="vac-rows">
                    <?php
                    $rows_to_show = ! empty($vac_stored) ? $vac_stored : array( array('label'=>'','start'=>'','end'=>'') );
                    foreach ( $rows_to_show as $v ) : ?>
                    <tr class="vac-row">
                        <td><input type="text"  name="vac_label[]" value="<?php echo esc_attr($v['label']); ?>" placeholder="Toussaint 2025" class="regular-text" style="width:100%;"></td>
                        <td><input type="date"  name="vac_start[]" value="<?php echo esc_attr($v['start']); ?>" style="width:100%;"></td>
                        <td><input type="date"  name="vac_end[]"   value="<?php echo esc_attr($v['end']);   ?>" style="width:100%;"></td>
                        <td><button type="button" class="button vac-remove" title="Supprimer" style="color:#dc2626;padding:2px 6px;">✕</button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <button type="button" id="vac-add" class="button" style="margin-bottom:16px;">+ Ajouter une période</button><br>

                <input type="submit" name="sp_cal_save_vacances" class="button button-primary" value="💾 Enregistrer couleurs &amp; périodes">
            </form>

            <script>
            (function($){
                $('#vac-add').on('click', function(){
                    $('#vac-rows').append(
                        '<tr class="vac-row">'
                        + '<td><input type="text" name="vac_label[]" placeholder="Ex: Printemps 2026" class="regular-text" style="width:100%;"></td>'
                        + '<td><input type="date" name="vac_start[]" style="width:100%;"></td>'
                        + '<td><input type="date" name="vac_end[]"   style="width:100%;"></td>'
                        + '<td><button type="button" class="button vac-remove" title="Supprimer" style="color:#dc2626;padding:2px 6px;">✕</button></td>'
                        + '</tr>'
                    );
                });
                $(document).on('click', '.vac-remove', function(){
                    $(this).closest('tr').remove();
                });
            })(jQuery);
            </script>
        </div>

        <!-- CONVENTION MÉDAILLE → POINTS -->
        <div class="sp-box">
            <h2>🏅 Convention médaille → points</h2>
            <p class="description" style="margin-bottom:14px;">
                Ces valeurs définissent combien de <strong>points</strong> rapporte chaque type de médaille.
                Le total de points est affiché sur la fiche élève et peut être utilisé comme critère pour les passages de grade.
            </p>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_medal_pts' ); ?>
                <table class="form-table" style="max-width:480px;">
                    <tr>
                        <th style="width:180px;">
                            <span style="font-size:22px;vertical-align:middle;">🥇</span>
                            Médaille d'Or
                        </th>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input type="number" name="sp_pts_or" min="0" max="99" value="<?php echo esc_attr($medal_pts['or']); ?>"
                                       style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                                <span style="color:#6b7280;">points</span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <span style="font-size:22px;vertical-align:middle;">🥈</span>
                            Médaille d'Argent
                        </th>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input type="number" name="sp_pts_argent" min="0" max="99" value="<?php echo esc_attr($medal_pts['argent']); ?>"
                                       style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                                <span style="color:#6b7280;">points</span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <span style="font-size:22px;vertical-align:middle;">🥉</span>
                            Médaille de Bronze
                        </th>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input type="number" name="sp_pts_bronze" min="0" max="99" value="<?php echo esc_attr($medal_pts['bronze']); ?>"
                                       style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                                <span style="color:#6b7280;">points</span>
                            </div>
                        </td>
                    </tr>
                </table>

                <!-- Aperçu de la convention actuelle -->
                <div id="sp-medal-preview" style="margin:14px 0 18px;padding:12px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;display:inline-flex;gap:20px;flex-wrap:wrap;align-items:center;">
                    <span style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Aperçu actuel :</span>
                    <span>🥇 = <strong id="prev_or"><?php echo $medal_pts['or']; ?></strong> pt</span>
                    <span>🥈 = <strong id="prev_argent"><?php echo $medal_pts['argent']; ?></strong> pt</span>
                    <span>🥉 = <strong id="prev_bronze"><?php echo $medal_pts['bronze']; ?></strong> pt</span>
                    <span style="color:#6b7280;">→ 🥇+🥈+🥉 = <strong id="prev_total"><?php echo $medal_pts['or'] + $medal_pts['argent'] + $medal_pts['bronze']; ?></strong> pts max</span>
                </div>
                <script>
                (function(){
                    var inputs = { or: document.querySelector('[name=sp_pts_or]'), argent: document.querySelector('[name=sp_pts_argent]'), bronze: document.querySelector('[name=sp_pts_bronze]') };
                    function update() {
                        var o=parseInt(inputs.or.value)||0, a=parseInt(inputs.argent.value)||0, b=parseInt(inputs.bronze.value)||0;
                        document.getElementById('prev_or').textContent     = o;
                        document.getElementById('prev_argent').textContent = a;
                        document.getElementById('prev_bronze').textContent = b;
                        document.getElementById('prev_total').textContent  = o+a+b;
                    }
                    inputs.or.addEventListener('input', update);
                    inputs.argent.addEventListener('input', update);
                    inputs.bronze.addEventListener('input', update);
                })();
                </script>

                <input type="submit" name="sp_save_medal_pts" class="button button-primary" value="Enregistrer la convention">
            </form>
        </div>
		<?php
        $coef_dep = floatval( get_option( 'sp_cal_coef_departemental', 1.0 ) );
        $coef_reg = floatval( get_option( 'sp_cal_coef_regional',      1.5 ) );
        $coef_nat = floatval( get_option( 'sp_cal_coef_national',      2.0 ) );
        $coef_int = floatval( get_option( 'sp_cal_coef_international', 3.0 ) );
        ?>
        <!-- COEFFICIENTS NIVEAUX COMPÉTITION -->
        <div class="sp-box">
            <h2>🏆 Coefficients niveaux de compétition</h2>
            <p class="description" style="margin-bottom:14px;">
                Ces coefficients multiplient les points de médaille selon le niveau de la compétition.
            </p>
            <?php if ( isset($_GET['coef_saved']) ) : ?>
            <div class="notice notice-success inline"><p>✅ Coefficients enregistrés.</p></div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_coef_niveaux' ); ?>
                <table class="form-table" style="max-width:480px;">
                    <tr>
                        <th style="width:180px;">🏘️ Départemental</th>
                        <td><div style="display:flex;align-items:center;gap:10px;">
                            <input type="number" name="sp_coef_dep" min="0.1" max="10" step="0.1"
                                   value="<?php echo esc_attr($coef_dep); ?>"
                                   style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                            <span style="color:#6b7280;">× points médaille</span>
                        </div></td>
                    </tr>
                    <tr>
                        <th>🌍 Régional</th>
                        <td><div style="display:flex;align-items:center;gap:10px;">
                            <input type="number" name="sp_coef_reg" min="0.1" max="10" step="0.1"
                                   value="<?php echo esc_attr($coef_reg); ?>"
                                   style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                            <span style="color:#6b7280;">× points médaille</span>
                        </div></td>
                    </tr>
                    <tr>
                        <th>🇫🇷 National</th>
                        <td><div style="display:flex;align-items:center;gap:10px;">
                            <input type="number" name="sp_coef_nat" min="0.1" max="10" step="0.1"
                                   value="<?php echo esc_attr($coef_nat); ?>"
                                   style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                            <span style="color:#6b7280;">× points médaille</span>
                        </div></td>
                    </tr>
                    <tr>
                        <th>🌐 International</th>
                        <td><div style="display:flex;align-items:center;gap:10px;">
                            <input type="number" name="sp_coef_int" min="0.1" max="10" step="0.1"
                                   value="<?php echo esc_attr($coef_int); ?>"
                                   style="width:70px;height:32px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:15px;font-weight:600;text-align:center;">
                            <span style="color:#6b7280;">× points médaille</span>
                        </div></td>
                    </tr>
                </table>
                <input type="submit" name="sp_save_coef_niveaux" class="button button-primary" value="Enregistrer les coefficients">
            </form>
        </div>

        <!-- NOTIFICATIONS EMAIL -->
        <?php
        $notif_email          = get_option('sp_cal_notif_email',          get_option('admin_email'));
        $notif_anniv          = get_option('sp_cal_notif_anniv',          '0') === '1';
        $notif_anniv_jours    = intval(get_option('sp_cal_notif_anniv_jours',    7));
        $notif_absences       = get_option('sp_cal_notif_absences',       '0') === '1';
        $notif_absences_seuil = intval(get_option('sp_cal_notif_absences_seuil', 3));
        $last_run             = get_option('sp_cal_notif_last_run', null);
        ?>
        <div class="sp-box">
            <h2>📧 Notifications email</h2>
            <p class="description">Les notifications sont envoyées automatiquement chaque matin à 8h via le cron WordPress.</p>

            <form method="post">
                <?php wp_nonce_field('sp_cal_settings'); ?>
                <table class="form-table" style="max-width:640px;">
                    <tr>
                        <th>Email destinataire</th>
                        <td>
                            <input type="email" name="sp_cal_notif_email" class="regular-text"
                                   value="<?php echo esc_attr($notif_email); ?>">
                            <p class="description">Reçoit toutes les notifications du club.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>🎂 Anniversaires</th>
                        <td>
                            <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                <input type="checkbox" name="sp_cal_notif_anniv" value="1" <?php checked($notif_anniv); ?>>
                                Activer les rappels d'anniversaires
                            </label>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span>Prévenir</span>
                                <input type="number" name="sp_cal_notif_anniv_jours" min="1" max="30"
                                       value="<?php echo esc_attr($notif_anniv_jours); ?>"
                                       style="width:60px;height:28px;border:1px solid #8c8f94;border-radius:4px;padding:0 6px;">
                                <span>jours à l'avance</span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>⚠️ Absences répétées</th>
                        <td>
                            <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                <input type="checkbox" name="sp_cal_notif_absences" value="1" <?php checked($notif_absences); ?>>
                                Activer les alertes d'absences consécutives
                            </label>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span>Alerter après</span>
                                <input type="number" name="sp_cal_notif_absences_seuil" min="2" max="20"
                                       value="<?php echo esc_attr($notif_absences_seuil); ?>"
                                       style="width:60px;height:28px;border:1px solid #8c8f94;border-radius:4px;padding:0 6px;">
                                <span>absences consécutives</span>
                            </div>
                        </td>
                    </tr>
                </table>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:12px;">
                    <input type="submit" name="sp_cal_save_settings" class="button button-primary" value="Enregistrer">
                    <button type="button" id="sp-notif-test-anniv" class="button"
                            data-type="anniv" <?php echo !$notif_anniv ? 'disabled title="Activez d\'abord les anniversaires"' : ''; ?>>
                        🎂 Tester anniversaires
                    </button>
                    <button type="button" id="sp-notif-test-absences" class="button"
                            data-type="absences" <?php echo !$notif_absences ? 'disabled title="Activez d\'abord les absences"' : ''; ?>>
                        ⚠️ Tester absences
                    </button>
                    <span id="sp-notif-result" style="font-size:13px;color:#15803d;display:none;"></span>
                </div>
            </form>

            <?php if ($last_run): ?>
            <p class="sp-muted" style="margin-top:12px;font-size:12px;">
                Dernier envoi : <?php echo esc_html($last_run['date']); ?>
                <?php
                $s = $last_run['sent'] ?? array();
                $parts = array();
                if (isset($s['anniv']))    $parts[] = $s['anniv'] . ' anniv.';
                if (isset($s['absences'])) $parts[] = $s['absences'] . ' absences';
                if ($parts) echo '— ' . implode(', ', $parts) . ' email(s) envoyé(s)';
                ?>
            </p>
            <?php endif; ?>
        </div>

        <!-- RÉCAPITULATIF MENSUEL ENTRAÎNEURS -->
        <?php
        $recap_actif = get_option('sp_cal_recap_actif', '0') === '1';
        $recap_jour  = intval( get_option('sp_cal_recap_jour', 1) );
        $tarif_km    = floatval( get_option('sp_cal_tarif_km', 0) );
        $recap_last  = get_option('sp_cal_recap_last_run', null);
        ?>
        <div class="sp-box">
            <h2>📊 Récapitulatif mensuel entraîneurs</h2>
            <p class="description">
                Le <strong><?php echo esc_html(ordinal_fr($recap_jour)); ?> de chaque mois</strong>,
                un email est envoyé aux membres du bureau avec les jours d'intervention, les allers-retours
                et le montant calculé pour chaque entraîneur.<br>
                <strong>Formule :</strong> tarif €/km &times; km aller-retour &times; nombre d'allers-retours (1 par jour d'intervention, 2 si l'entraîneur l'a déclaré).
                Les km sont configurés sur la fiche de chaque entraîneur.
            </p>
            <?php $this->notice_flash('recap_saved', 'Paramètres du récapitulatif enregistrés.'); ?>
            <form method="post">
                <?php wp_nonce_field('sp_cal_recap_settings'); ?>
                <table class="form-table" style="max-width:640px;">
                    <tr>
                        <th>Activer</th>
                        <td>
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" name="sp_cal_recap_actif" value="1" <?php checked($recap_actif); ?>>
                                Envoyer automatiquement le récapitulatif mensuel
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Jour d'envoi</th>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span>Le</span>
                                <input type="number" name="sp_cal_recap_jour" min="1" max="28"
                                       value="<?php echo esc_attr($recap_jour); ?>"
                                       style="width:60px;height:28px;border:1px solid #8c8f94;border-radius:4px;padding:0 6px;">
                                <span>de chaque mois <em style="color:#888;">(max. 28 pour éviter les mois courts)</em></span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>💶 Tarif kilométrique</th>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <input type="number" name="sp_cal_tarif_km" min="0" max="5" step="0.01"
                                       value="<?php echo esc_attr(number_format($tarif_km, 2, '.', '')); ?>"
                                       style="width:80px;height:28px;border:1px solid #8c8f94;border-radius:4px;padding:0 6px;">
                                <span>€/km</span>
                            </div>
                            <p class="description" style="margin-top:4px;">
                                Taux légal de remboursement kilométrique. Exemple : 0.42 €/km.
                            </p>
                        </td>
                    </tr>
                </table>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:12px;">
                    <input type="submit" name="sp_cal_save_recap" class="button button-primary" value="Enregistrer">
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <?php
                        $mois_labels_fr = array(
                            1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
                            7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre',
                        );
                        $cur_year  = intval(date('Y'));
                        $cur_month = intval(date('n'));
                        // Mois proposés : les 12 derniers
                        $prev_month = $cur_month - 1 ?: 12;
                        $prev_year  = $cur_month - 1 ? $cur_year : $cur_year - 1;
                        ?>
                        <select id="sp-recap-month" style="height:30px;border:1px solid #8c8f94;border-radius:4px;padding:0 4px;">
                            <?php foreach ( $mois_labels_fr as $num => $label ) :
                                $y = ( $num <= $cur_month && $num >= 1 ) ? $cur_year : $cur_year - 1;
                                // Ajuster : si num > cur_month c'est l'année précédente
                                if ( $num > $cur_month ) $y = $cur_year - 1;
                                else $y = $cur_year;
                                $selected = ( $num === $prev_month ) ? ' selected' : '';
                            ?>
                            <option value="<?php echo $num; ?>" data-year="<?php echo ($num > $cur_month ? $cur_year - 1 : $cur_year); ?>"<?php echo $selected; ?>>
                                <?php echo $label . ' ' . ($num > $cur_month ? $cur_year - 1 : $cur_year); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="sp-recap-test-now" class="button"
                                title="Envoie le récapitulatif du mois sélectionné aux membres bureau">
                            📊 Envoyer maintenant
                        </button>
                    </div>
                    <span id="sp-recap-result" style="font-size:13px;color:#15803d;display:none;"></span>
                </div>
            </form>
            <?php if ($recap_last): ?>
            <p class="sp-muted" style="margin-top:12px;font-size:12px;">
                Dernier envoi : <?php echo esc_html($recap_last['date']); ?>
                — mois concerné : <?php echo esc_html($recap_last['mois'] ?? '—'); ?>
                — <?php echo intval($recap_last['sent'] ?? 0); ?> email(s) envoyé(s)
            </p>
            <?php endif; ?>
            <script>
            (function($){
                $('#sp-recap-test-now').on('click', function(){
                    var btn   = $(this).prop('disabled', true).text('Envoi…');
                    var sel   = $('#sp-recap-month option:selected');
                    var month = parseInt(sel.val(), 10);
                    var year  = parseInt(sel.data('year'), 10);
                    $.post(ajaxurl, {
                        action:       'sp_cal_send_recap_now',
                        nonce:        '<?php echo wp_create_nonce("sp_cal_admin_nonce"); ?>',
                        recap_month:  month,
                        recap_year:   year
                    }, function(res){
                        btn.prop('disabled', false).text('📊 Envoyer maintenant');
                        var $r = $('#sp-recap-result');
                        $r.css('color', res.success ? '#15803d' : '#b91c1c').text(res.success ? res.data : '❌ ' + res.data).show();
                        setTimeout(function(){ $r.fadeOut(); }, 8000);
                    });
                });
            })(jQuery);
            </script>
        </div>

        <!-- IK : détail, clôture des mois payés et autorisations → page 💶 IK -->
        <p class="sp-muted" style="margin:-6px 0 18px;">💶 Détail des IK par entraîneur, clôture des mois payés et régularisations : page <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SP_Cal_IK_Cloture::PAGE ) ); ?>">💶 IK</a>.</p>

        <!-- TOKENS FICHE MEMBRE -->
        <?php
        global $wpdb;
        $tel           = $this->db->table_eleves();
        $nb_with_token = intval($wpdb->get_var("SELECT COUNT(*) FROM $tel WHERE actif=1 AND token IS NOT NULL AND token!=''"));
        $nb_without    = intval($wpdb->get_var("SELECT COUNT(*) FROM $tel WHERE actif=1 AND (token IS NULL OR token='')"));
        $fiche_url_ex    = '';
        if ($this->token) $fiche_url_ex = $this->token->get_fiche_url('EXEMPLE');
        $palmares_url    = get_option( 'sp_cal_palmares_url', '' );
        ?>
        <div class="sp-box">
            <h2>🔗 Fiche de suivi membre (accès par token)</h2>
            <p class="description">
                Chaque adhérent actif reçoit automatiquement un lien personnel par email lors de son activation.
                Ce lien donne accès à sa fiche de suivi : grades, présences, statut adhésion.
            </p>

            <!-- URL dédiée — formulaire séparé pour éviter les conflits -->
            <form method="post" style="margin:12px 0 16px;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
                <?php wp_nonce_field('sp_cal_fiche_url'); ?>
                <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">
                    URL de la page "Fiche membre" <span style="color:#c00;">*</span>
                </label>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="url" name="sp_cal_fiche_membre_url" class="large-text"
                           value="<?php echo esc_attr($fiche_membre_url); ?>"
                           placeholder="https://votresite.fr/ma-fiche/"
                           style="max-width:400px;">
                    <input type="submit" name="sp_save_fiche_url" class="button button-primary" value="Enregistrer l'URL">
                    <?php if ($fiche_membre_url): ?>
                    <a href="<?php echo esc_url(add_query_arg('token','TEST',$fiche_membre_url)); ?>"
                       target="_blank" class="button" title="Tester le lien">🔗 Tester</a>
                    <?php endif; ?>
                </div>
                <p class="description" style="margin-top:6px;">
                    Page WordPress contenant le shortcode <code>[sp_cal_fiche_membre]</code> — <strong>pas</strong> <code>[sp_fiche_eleve]</code>.
                    <?php
                    global $wpdb;
                    $real_url = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='sp_cal_fiche_membre_url'");
                    if ( ! $real_url ): ?>
                    <br><strong style="color:#b45309;">⚠️ Non configurée — les tokens envoient vers la page d'accueil !</strong>
                    <?php else: ?>
                    <br>✅ URL en base : <code><?php echo esc_html($real_url); ?></code>
                    <?php endif; ?>
                </p>
            </form>
            <!-- Créer la page automatiquement -->
            <?php if ( ! $fiche_membre_url ) : ?>
            <form method="post" style="margin:10px 0 0;padding:10px 14px;background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;display:inline-flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <?php wp_nonce_field('sp_cal_create_fiche_page'); ?>
                <span style="font-size:13px;color:#92400e;">💡 Aucune page configurée — créez-la automatiquement :</span>
                <input type="submit" name="sp_create_fiche_page" class="button" value="✨ Créer la page « Ma fiche »">
            </form>
            <?php endif; ?>
            <table class="form-table" style="max-width:640px;">
                <tr>
                    <th>Shortcode</th>
                    <td>
                        Créez une page WordPress et insérez-y : <code>[sp_cal_fiche_membre]</code><br>
                        <span class="description">Les tokens dans les emails renvoient vers cette page.</span>
                    </td>
                </tr>
                <?php if($fiche_url_ex): ?>
                <tr>
                    <th>Exemple de lien</th>
                    <td><code style="font-size:11px;"><?php echo esc_html(str_replace('EXEMPLE','xxxxxxxx',$fiche_url_ex)); ?></code></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Tokens générés</th>
                    <td>
                        <strong><?php echo $nb_with_token; ?></strong> élèves actifs ont un token
                        <?php if($nb_without > 0): ?>
                        — <strong style="color:#b45309;"><?php echo $nb_without; ?></strong> élèves actifs sans token
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php if($nb_without > 0): ?>
            <button type="button" id="sp-btn-generate-all-tokens" class="button button-primary" style="margin-top:8px;">
                📧 Générer et envoyer les tokens manquants (<?php echo $nb_without; ?> élèves)
            </button>
            <span id="sp-all-tokens-result" style="margin-left:12px;font-size:13px;color:#15803d;display:none;"></span>
            <?php endif; ?>
        </div>

        <!-- URL PAGE PALMARÈS PUBLIC -->
        <div class="sp-box">
            <h2>🏆 Page palmarès public</h2>
            <p class="description">
                Indiquez l'URL de la page WordPress contenant le shortcode <code>[sp_cal_palmares]</code>.<br>
                Cette URL est utilisée par le planning public pour rediriger vers les résultats d'une compétition passée.
            </p>
            <form method="post" style="margin:12px 0 0;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
                <?php wp_nonce_field('sp_cal_palmares_url'); ?>
                <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;">
                    URL de la page «&nbsp;Palmarès&nbsp;» <span style="color:#c00;">*</span>
                </label>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="url" name="sp_cal_palmares_url" class="large-text"
                           value="<?php echo esc_attr($palmares_url); ?>"
                           placeholder="https://votresite.fr/palmares/"
                           style="max-width:400px;">
                    <input type="submit" name="sp_save_palmares_url" class="button button-primary" value="Enregistrer l'URL">
                    <?php if ($palmares_url): ?>
                    <a href="<?php echo esc_url($palmares_url); ?>" target="_blank" class="button">🔗 Voir la page</a>
                    <?php endif; ?>
                </div>
                <p class="description" style="margin-top:6px;">
                    <?php if ( ! $palmares_url ): ?>
                    <strong style="color:#b45309;">⚠️ Non configurée — le lien 🏆 sur le planning ne sera pas actif.</strong>
                    <?php else: ?>
                    ✅ URL enregistrée : <code><?php echo esc_html($palmares_url); ?></code>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <!-- MIGRATION DISCIPLINE / POUR QUI DES ÉVÉNEMENTS -->
        <?php
        if ( isset($_GET['migration_cours_done']) ) {
            $nb_mig = intval($_GET['nb']);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Migration effectuée — <strong>' . $nb_mig . '</strong> événement(s) mis à jour.</p></div>';
        }
        if ( isset($_GET['migration_cours_preview']) ) {
            $preview_mig = get_transient( 'sp_migrer_cours_cat_preview_' . get_current_user_id() );
            if ( $preview_mig !== false ) :
        ?>
        <div class="notice notice-warning" style="padding:16px;">
            <?php if ( empty($preview_mig) ) : ?>
            <p><strong>👁️ Rien à migrer</strong> — tous les événements ont déjà une Discipline renseignée.</p>
            <?php else : ?>
            <p><strong>👁️ Aperçu de la migration — <?php echo count($preview_mig); ?> événement(s) concerné(s)</strong></p>
            <table class="wp-list-table widefat fixed striped" style="max-width:800px;margin:10px 0;">
                <thead><tr><th>Événement</th><th>Ancienne catégorie</th><th>Discipline</th><th>Pour qui</th></tr></thead>
                <tbody>
                <?php foreach ( array_slice($preview_mig, 0, 50) as $m ): ?>
                <tr>
                    <td><?php echo esc_html($m['titre']); ?></td>
                    <td><span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html($m['ancienne']); ?></span></td>
                    <td><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html($m['discipline'] !== '' ? str_replace(',', ', ', $m['discipline']) : '—'); ?></span></td>
                    <td><span style="background:#dbeafe;color:#1e3a8a;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo esc_html($m['age'] !== '' ? str_replace(',', ', ', $m['age']) : '—'); ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ( count($preview_mig) > 50 ): ?>
            <p style="color:#64748b;font-size:12px;">… et <?php echo count($preview_mig) - 50; ?> de plus (non affichés, mais bien pris en compte à la confirmation).</p>
            <?php endif; ?>
            <form method="post" style="margin-top:8px;">
                <?php wp_nonce_field('sp_migrer_cours_categories'); ?>
                <input type="hidden" name="sp_migrer_cours_categories" value="1">
                <input type="submit" class="button button-primary" value="✅ Confirmer la migration">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-settings')); ?>" class="button" style="margin-left:8px;">Annuler</a>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; } ?>

        <div class="sp-box" style="border-left:4px solid #e5e7eb;margin-bottom:18px;">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div style="flex:1;">
                    <strong>🎯 Migrer les événements vers Discipline / Pour qui</strong>
                    <p style="margin:4px 0 0;color:#64748b;font-size:13px;">
                        Convertit l'ancien champ "Catégorie" en texte libre (TKD, RENFO, Général, Prépa CN, Sortie…)
                        des événements existants vers les deux nouveaux menus du calendrier — <strong>Discipline</strong>
                        (Taekwondo / Renforcement musculaire / Autre) et <strong>Pour qui</strong> (tranche d'âge).
                        Les valeurs non reconnues (Général, Prépa CN, Sortie…) basculent en Discipline "Autre".
                        Sans effet sur les événements déjà migrés.
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field('sp_migrer_cours_categories'); ?>
                        <input type="hidden" name="sp_migrer_cours_categories" value="1">
                        <input type="hidden" name="sp_migrer_preview" value="1">
                        <input type="submit" class="button" value="👁️ Prévisualiser">
                    </form>
                </div>
            </div>
        </div>

        <!-- NETTOYAGE DOUBLONS -->
        <div class="sp-box" style="border-left:4px solid #f59e0b;">
            <h2 style="color:#92400e;">🧹 Nettoyage des événements parasites</h2>
            <?php if (isset($_GET['cleaned'])): ?>
            <div class="notice notice-success is-dismissible" style="margin:0 0 12px;"><p>✅ Nettoyage effectué — <?php echo intval($_GET['cleaned']); ?> événement(s) parasite(s) supprimé(s).</p></div>
            <?php endif; ?>
            <p>Supprime les <strong>événements en double</strong> créés accidentellement lors des saisies de présences (cours récurrents matérialisés plusieurs fois pour le même créneau/date).<br>
            <strong style="color:#15803d;">Les présences déjà enregistrées sont conservées.</strong></p>
            <form method="post">
                <?php wp_nonce_field('sp_cleanup_mats'); ?>
                <input type="submit" name="sp_cleanup_mats" class="button" style="background:#f59e0b;color:#fff;border-color:#d97706;"
                    value="🧹 Nettoyer les événements parasites" onclick="return confirm('Supprimer les événements en double ? Les présences enregistrées sont conservées.')">
            </form>
        </div>

        <!-- ZONE DANGER -->
        <div class="sp-box" style="border-left:4px solid #dc2626;">
            <h2 style="color:#dc2626;">⚠️ Zone de réinitialisation</h2>
            <div style="display:flex;gap:20px;flex-wrap:wrap;">
                <div>
                    <p><strong>Cours &amp; événements</strong><br>Supprime tous les cours, événements et présences élèves.</p>
                    <form method="post">
                        <?php wp_nonce_field('sp_reset_events'); ?>
                        <input type="submit" name="sp_reset_events" class="button" style="background:#dc2626;color:#fff;border-color:#dc2626;"
                            value="🗑️ Vider cours &amp; événements" onclick="return confirm('Supprimer TOUS les cours, événements et présences ? Irréversible.')">
                    </form>
                </div>
                <div>
                    <p><strong>Élèves</strong><br>Supprime tous les élèves et leurs présences.</p>
                    <form method="post">
                        <?php wp_nonce_field('sp_reset_eleves'); ?>
                        <input type="submit" name="sp_reset_eleves" class="button" style="background:#dc2626;color:#fff;border-color:#dc2626;"
                            value="🗑️ Vider les élèves" onclick="return confirm('Supprimer TOUS les élèves et présences ? Irréversible.')">
                    </form>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════
             DIAGNOSTIC PLUGIN
        ════════════════════════════════════════════════════ -->
        <div class="sp-box" style="margin-top:24px;">
            <h2 style="margin-top:0;">🔍 Diagnostic plugin</h2>
            <p style="color:#6b7280;font-size:13px;margin-bottom:16px;">
                Vérifie que le plugin, PHP, WordPress et la base de données fonctionnent correctement.<br>
                À utiliser après chaque mise à jour WordPress ou en cas de comportement inattendu.
            </p>
            <button id="sp-ping-btn" class="button button-secondary" style="font-size:14px;padding:6px 18px;">
                🔍 Lancer le diagnostic
            </button>
            <div id="sp-ping-result" style="display:none;margin-top:16px;padding:16px 20px;border-radius:10px;font-size:13px;line-height:2;"></div>
        </div>
        <script>
        document.getElementById('sp-ping-btn').addEventListener('click', function() {
            var btn = this;
            var box = document.getElementById('sp-ping-result');
            btn.disabled = true;
            btn.textContent = '⏳ Vérification en cours…';
            box.style.display = 'none';

            jQuery.post(ajaxurl, {
                action: 'sp_cal_ping',
            }, function(res) {
                btn.disabled = false;
                btn.textContent = '🔍 Lancer le diagnostic';
                if (res && res.success) {
                    var d = res.data;
                    var dbOk  = d.db_status === 'ok';
                    var color = dbOk ? '#f0fdf4' : '#fef2f2';
                    var bord  = dbOk ? '#bbf7d0' : '#fecaca';
                    var icon  = dbOk ? '✅' : '❌';
                    box.style.background = color;
                    box.style.border     = '1px solid ' + bord;
                    box.innerHTML =
                        '<strong style="font-size:15px;">' + icon + ' ' + (dbOk ? 'Tout fonctionne correctement' : 'Problème détecté') + '</strong><br>'
                        + '📦 Plugin&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <strong>' + d.plugin_version + '</strong><br>'
                        + '🐘 PHP&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <strong>' + d.php_version + '</strong><br>'
                        + '🔵 WordPress&nbsp;: <strong>' + d.wp_version + '</strong><br>'
                        + '🗄️ Base de données&nbsp;: <strong style="color:' + (dbOk ? '#16a34a' : '#dc2626') + ';">' + d.db_status + '</strong><br>'
                        + '🕐 Vérifié le&nbsp;: <strong>' + d.timestamp + '</strong>';
                } else {
                    var msg = (res && res.data) ? res.data : 'Réponse inattendue du serveur.';
                    box.style.background = '#fef2f2';
                    box.style.border     = '1px solid #fecaca';
                    box.innerHTML = '❌ <strong>Erreur&nbsp;:</strong> ' + msg
                        + '<br><small style="color:#9ca3af;">Si le problème persiste, désactivez puis réactivez le plugin.</small>';
                }
                box.style.display = 'block';
            }, 'json').fail(function(xhr) {
                btn.disabled = false;
                btn.textContent = '🔍 Lancer le diagnostic';
                box.style.background = '#fef2f2';
                box.style.border     = '1px solid #fecaca';
                box.innerHTML = '❌ <strong>Impossible de contacter le serveur.</strong>'
                    + '<br>Code HTTP&nbsp;: <strong>' + xhr.status + '</strong>'
                    + '<br>Réponse&nbsp;: <code style="font-size:11px;">' + (xhr.responseText ? xhr.responseText.substring(0,200) : 'vide') + '</code>'
                    + '<br><small style="color:#9ca3af;">💡 Désactivez puis réactivez le plugin, puis réessayez.</small>';
                box.style.display = 'block';
            });
        });
        </script>

        </div>
        <?php
    }
}

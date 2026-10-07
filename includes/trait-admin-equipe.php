<?php
/**
 * Pages d'administration « Entraîneurs & Bureau » et « Créneaux » (+ time_select(), utilisé seulement par les créneaux).
 *
 * Méthodes de SpCalPro_Admin déplacées telles quelles depuis class-admin.php (restructuration,
 * étape 3 — 07/10/2026, outils/deplacer-methodes.php). La classe les réintègre par « use SpCalPro_Admin_Equipe; » :
 * même comportement, mêmes appels ($this->db, méthodes privées de la classe).
 *
 * @package SP_Build
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait SpCalPro_Admin_Equipe {


    /* ══════════════════════════════════════════════════════════
       PAGE : Entraîneurs
    ══════════════════════════════════════════════════════════ */

    public function page_trainers() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        global $wpdb;
        $tt       = $this->db->table_trainers();
        // Liste principale : uniquement les personnes ayant le rôle "entraineur"
        // (les membres purement "bureau" sont affichés dans la section dédiée ci-dessous)
        $trainers = $this->db->get_trainers_entraineurs();

        $edit = null;
        if ( isset( $_GET['sp_edit_trainer'] ) ) {
            $edit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tt WHERE id=%d", intval( $_GET['sp_edit_trainer'] ) ) );
        }

        $jours_labels = array( 1=>'Lun', 2=>'Mar', 3=>'Mer', 4=>'Jeu', 5=>'Ven', 6=>'Sam', 7=>'Dim' );
        ?>
        <div class="wrap sp-cal-wrap">
        <h1>Entraîneurs &amp; Bureau</h1>

        <?php $this->notice_flash( 'saved', 'Entraîneur enregistré.' ); ?>
        <?php $this->notice_flash( 'deleted', 'Entraîneur supprimé.' ); ?>

        <div class="sp-box">
            <h2><?php echo $edit ? '✏️ Modifier' : '➕ Ajouter un entraîneur'; ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'sp_cal_save_trainer' ); ?>
                <input type="hidden" name="trainer_id" value="<?php echo $edit ? intval( $edit->id ) : 0; ?>">
                <table class="form-table" style="max-width:640px;">
                    <tr><th>Nom</th><td><input type="text" name="trainer_nom" class="regular-text" value="<?php echo esc_attr( $edit->nom ?? '' ); ?>" required></td></tr>
                    <tr><th>Nom public</th><td><input type="text" name="trainer_pubnom" class="regular-text" value="<?php echo esc_attr( $edit->nom_public ?? '' ); ?>"></td></tr>
                    <tr><th>Fonction sportif</th><td>
                        <input type="text" name="trainer_fonction" class="regular-text"
                               value="<?php echo esc_attr( $edit->fonction ?? '' ); ?>"
                               placeholder="Ex: Coach principal, Entraîneur…">
                        <p class="description" style="margin-top:4px;">Rôle sportif — affiché si renseigné.</p>
                    </td></tr>
                    <tr><th>Fonction bureau</th><td>
                        <input type="text" name="trainer_fonction_bureau" class="regular-text"
                               value="<?php echo esc_attr( $edit->fonction_bureau ?? '' ); ?>"
                               placeholder="Ex: Président, Trésorière, Secrétaire…">
                        <p class="description" style="margin-top:4px;">Rôle au bureau — affiché si renseigné.</p>
                    </td></tr>
                    <tr><th>Rôles</th><td>
                        <?php
                        $roles_val  = $edit->roles ?? '';
                        $roles_list = array( 'entraineur' => '🥋 Entraîneur', 'bureau' => '🏛️ Bureau', 'eleve' => '🎓 Élève' );
                        foreach ( $roles_list as $rv => $rl ) {
                            $checked = ( strpos($roles_val, $rv) !== false ) ? ' checked' : '';
                            echo '<label style="display:inline-flex;align-items:center;gap:5px;margin-right:14px;cursor:pointer;">';
                            echo '<input type="checkbox" name="trainer_roles_cb[]" value="' . $rv . '"' . $checked . '> ' . $rl;
                            echo '</label>';
                        }
                        ?>
                        <input type="hidden" name="trainer_roles" id="trainer_roles_hidden" value="<?php echo esc_attr($roles_val); ?>">
                        <script>
                        (function(){
                            var cbs = document.querySelectorAll('input[name="trainer_roles_cb[]"]');
                            function sync(){ document.getElementById('trainer_roles_hidden').value = Array.from(cbs).filter(function(c){return c.checked;}).map(function(c){return c.value;}).join(','); }
                            cbs.forEach(function(c){ c.addEventListener('change', sync); });
                        })();
                        </script>
                    </td></tr>
                    <tr><th>Téléphone</th><td><input type="text" name="trainer_tel" class="regular-text" value="<?php echo esc_attr( $edit->telephone ?? '' ); ?>"></td></tr>
                    <tr><th>Email</th><td><input type="email" name="trainer_email" class="regular-text" value="<?php echo esc_attr( $edit->email ?? '' ); ?>"></td></tr>
                    <tr>
                        <th>📍 Km aller-retour</th>
                        <td>
                            <input type="number" name="trainer_km" min="0" max="999" step="0.5"
                                   value="<?php echo esc_attr( number_format( floatval( $edit->km_aller_retour ?? 0 ), 1, '.', '' ) ); ?>"
                                   style="width:90px;"> km
                            <p class="description" style="margin-top:4px;">Distance domicile → salle (aller-retour). Utilisée dans le récapitulatif mensuel.</p>
                        </td>
                    </tr>
                    <tr><th>Ordre d'affichage</th><td><input type="number" name="trainer_ordre" value="<?php echo intval( $edit->ordre ?? 0 ); ?>" style="width:80px;"></td></tr>
                    <tr><th>Actif</th><td><input type="checkbox" name="trainer_actif" value="1" <?php checked( $edit->actif ?? 1 ); ?>></td></tr>
                    <tr>
                        <th>📷 Photo de profil</th>
                        <td>
                            <div style="display:flex;align-items:flex-start;gap:16px;">
                                <div id="sp-trainer-photo-preview" style="width:80px;height:80px;border-radius:50%;overflow:hidden;background:#e5e7eb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <?php if ( ! empty($edit->photo_url) ) : ?>
                                        <img src="<?php echo esc_url($edit->photo_url); ?>" style="width:100%;height:100%;object-fit:cover;" id="sp-trainer-photo-img">
                                    <?php else : ?>
                                        <span id="sp-trainer-photo-placeholder" style="font-size:28px;color:#9ca3af;">👤</span>
                                        <img src="" style="width:100%;height:100%;object-fit:cover;display:none;" id="sp-trainer-photo-img">
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <input type="hidden" name="trainer_photo_url" id="trainer_photo_url" value="<?php echo esc_attr($edit->photo_url ?? ''); ?>">
                                    <button type="button" class="button" id="sp-trainer-photo-btn">📁 Choisir une photo</button>
                                    <button type="button" class="button" id="sp-trainer-photo-clear" style="margin-left:6px;color:#dc2626;<?php echo empty($edit->photo_url) ? 'display:none;' : ''; ?>">✕ Supprimer</button>
                                    <p class="description" style="margin-top:6px;">Recommandé : carré, minimum 200×200 px.</p>
                                </div>
                            </div>
                            <script>
                            (function($){
                                var frame;
                                $('#sp-trainer-photo-btn').on('click', function(e){
                                    e.preventDefault();
                                    if (frame) { frame.open(); return; }
                                    frame = wp.media({ title: 'Choisir une photo', button: { text: 'Utiliser cette photo' }, multiple: false, library: { type: 'image' } });
                                    frame.on('select', function(){
                                        var att = frame.state().get('selection').first().toJSON();
                                        $('#trainer_photo_url').val(att.url);
                                        $('#sp-trainer-photo-img').attr('src', att.url).show();
                                        $('#sp-trainer-photo-placeholder').hide();
                                        $('#sp-trainer-photo-clear').show();
                                    });
                                    frame.open();
                                });
                                $('#sp-trainer-photo-clear').on('click', function(){
                                    $('#trainer_photo_url').val('');
                                    $('#sp-trainer-photo-img').attr('src','').hide();
                                    $('#sp-trainer-photo-placeholder').show();
                                    $(this).hide();
                                });
                            })(jQuery);
                            </script>
                        </td>
                    </tr>
                </table>
                <p>
                    <input type="submit" name="sp_cal_save_trainer" class="button button-primary" value="<?php echo $edit ? 'Mettre à jour' : 'Ajouter'; ?>">
                    <?php if ( $edit ) echo '<a href="' . admin_url( 'admin.php?page=sp-cal-trainers' ) . '" class="button" style="margin-left:8px;">Annuler</a>'; ?>
                </p>
            </form>
        </div>

        <div class="sp-box">
            <h2>Liste des entraîneurs (<?php echo count( $trainers ); ?> entraîneur<?php echo count( $trainers ) > 1 ? 's' : ''; ?>)</h2>
            <?php if ( empty( $trainers ) ) : ?>
                <p class="sp-muted">Aucun entraîneur configuré.</p>
            <?php else :
            $role_badges = array(
                'entraineur' => array('label'=>'Entraîneur', 'bg'=>'#1e3a5f','color'=>'#fff'),
                'bureau'     => array('label'=>'Bureau',     'bg'=>'#7c3aed','color'=>'#fff'),
                'eleve'      => array('label'=>'Élève',      'bg'=>'#15803d','color'=>'#fff'),
            );
            ?>
            <table class="wp-list-table widefat striped">
                <thead><tr>
                    <th style="width:50px;">Ordre</th>
                    <th>Nom</th>
                    <th>Rôles</th>
                    <th>Téléphone</th>
                    <th>Email</th>
                    <th style="width:80px;" title="Distance domicile → salle (aller-retour), utilisée dans le récap mensuel">📍 Km A/R</th>
                    <th style="width:80px;">Actif</th>
                    <th style="width:110px;">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $trainers as $t ) :
                    $del      = wp_nonce_url( admin_url( 'admin.php?page=sp-cal-trainers&sp_delete_trainer=' . $t->id ), 'sp_delete_trainer_' . $t->id );
                    $edit_url = admin_url( 'admin.php?page=sp-cal-trainers&sp_edit_trainer=' . $t->id );
                    $t_roles  = array_filter( array_map( 'trim', explode(',', $t->roles) ) );
                ?>
                <tr>
                    <td style="text-align:center;"><?php echo intval( $t->ordre ); ?></td>
                    <td>
                        <strong><?php echo esc_html( $t->nom ); ?></strong>
                        <?php if ( $t->nom_public && $t->nom_public !== $t->nom ) echo ' <em class="sp-muted">(' . esc_html( $t->nom_public ) . ')</em>'; ?>
                        <?php if ( ! empty($t->fonction) ) echo '<br><span style="font-size:11px;color:#64748b;">🥋 ' . esc_html($t->fonction) . '</span>'; ?>
                        <?php if ( ! empty($t->fonction_bureau) ) echo '<br><span style="font-size:11px;color:#64748b;">🏛️ ' . esc_html($t->fonction_bureau) . '</span>'; ?>
                    </td>
                    <td><?php
                        foreach ( $t_roles as $rv ) {
                            $rb = $role_badges[$rv] ?? null;
                            if ( $rb ) echo '<span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:700;background:'.esc_attr($rb['bg']).';color:'.esc_attr($rb['color']).';margin-right:4px;">'.esc_html($rb['label']).'</span>';
                            else echo '<span class="sp-muted">'.esc_html($rv).'</span> ';
                        }
                    ?></td>
                    <td><?php echo esc_html( $t->telephone ); ?></td>
                    <td><?php echo esc_html( $t->email ); ?></td>
                    <td style="text-align:center;"><?php
                        $km = floatval($t->km_aller_retour ?? 0);
                        echo $km > 0 ? '<strong>' . number_format($km, 1, ',', '') . '</strong> km' : '<span class="sp-muted">—</span>';
                    ?></td>
                    <td style="text-align:center;"><?php echo $t->actif ? '<span style="color:#15803d;font-weight:700;">✔</span>' : '<span style="color:#aaa;">–</span>'; ?></td>
                    <td>
                        <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">✏️</a>
                        <?php if ( ! empty( $t->email ) ) : ?>
                        <button type="button" class="button button-small sp-btn-send-trainer-app"
                            data-id="<?php echo intval( $t->id ); ?>"
                            data-nom="<?php echo esc_attr( $t->nom ); ?>"
                            title="Envoyer le lien application pointage">📲</button>
                        <button type="button" class="button button-small sp-btn-send-dispo-app"
                            data-id="<?php echo intval( $t->id ); ?>"
                            data-nom="<?php echo esc_attr( $t->nom ); ?>"
                            title="Envoyer le lien Disponibilités">🗓️</button>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( $del ); ?>" class="button button-small sp-btn-del" onclick="return confirm('Supprimer cet entraîneur ?')">🗑️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <script>
        (function($){
            $('.sp-btn-send-trainer-app').on('click', function() {
                var id  = $(this).data('id');
                var nom = $(this).data('nom');
                if (!confirm('Envoyer le lien application à ' + nom + ' ?')) return;
                var $btn = $(this).prop('disabled', true).text('⏳');
                $.post(ajaxurl, {
                    action:     'sp_cal_send_trainer_app',
                    trainer_id: id,
                    nonce: '<?php echo wp_create_nonce("sp_cal_admin_nonce"); ?>'
                }, function(r) {
                    if (r.success) {
                        $btn.text('✅').css('color','#15803d');
                        setTimeout(function(){ $btn.prop('disabled',false).text('📲').css('color',''); }, 3000);
                    } else {
                        alert('Erreur : ' + (r.data || 'Envoi échoué'));
                        $btn.prop('disabled',false).text('📲');
                    }
                });
            });
            $('.sp-btn-send-dispo-app').on('click', function() {
                var id  = $(this).data('id');
                var nom = $(this).data('nom');
                if (!confirm('Envoyer le lien Disponibilités à ' + nom + ' ?')) return;
                var $btn = $(this).prop('disabled', true).text('⏳');
                $.post(ajaxurl, {
                    action:     'sp_cal_send_dispo_app_link',
                    trainer_id: id,
                    nonce: '<?php echo wp_create_nonce("sp_cal_admin_nonce"); ?>'
                })
                .done(function(r) {
                    if (r && r.success) {
                        $btn.text('✅').css('color','#15803d');
                        setTimeout(function(){ $btn.prop('disabled',false).text('🗓️').css('color',''); }, 3000);
                    } else {
                        alert('Erreur : ' + (r && r.data ? r.data : 'Envoi échoué'));
                        $btn.prop('disabled',false).text('🗓️');
                    }
                })
                // Sans ce .fail(), une requête en échec (erreur PHP fatale, timeout...) laissait
                // le bouton bloqué indéfiniment sur "⏳" sans aucun message — bug signalé le
                // 09/09/2026, cause exacte jamais confirmée faute de retour visible.
                .fail(function(xhr) {
                    alert('Erreur réseau/serveur (HTTP ' + xhr.status + ') — voir le journal du site pour le détail.');
                    $btn.prop('disabled',false).text('🗓️');
                });
            });
        })(jQuery);
        </script>

        <!-- MEMBRES DU BUREAU -->
        <?php $bureau = $this->db->get_bureau_members(); ?>
        <div class="sp-box" style="margin-top:18px;">
            <h2>🏛️ Membres du bureau (<?php echo count($bureau); ?>)</h2>
            <?php if ( empty($bureau) ) : ?>
                <p class="sp-muted">Aucun membre ayant le rôle "Bureau". Cochez "Bureau" dans les rôles d'un entraîneur pour l'y faire apparaître.</p>
            <?php else : ?>
            <table class="wp-list-table widefat striped">
                <thead><tr>
                    <th>Nom</th><th>Autres rôles</th><th>Téléphone</th><th>Email</th><th style="width:110px;">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $bureau as $m ) :
                    $other_roles = array_filter( array_map('trim', explode(',', $m->roles)), function($r){ return $r !== 'bureau'; } );
                    $edit_url    = admin_url( 'admin.php?page=sp-cal-trainers&sp_edit_trainer=' . $m->id );
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($m->nom); ?></strong>
                        <?php if ($m->nom_public && $m->nom_public !== $m->nom) echo ' <em class="sp-muted">('.esc_html($m->nom_public).')</em>'; ?>
                        <?php if ( ! empty($m->fonction_bureau) ) echo '<br><span style="font-size:11px;color:#64748b;">🏛️ ' . esc_html($m->fonction_bureau) . '</span>'; ?>
                        <?php if ( ! empty($m->fonction) ) echo '<br><span style="font-size:11px;color:#64748b;">🥋 ' . esc_html($m->fonction) . '</span>'; ?>
                    </td>
                    <td class="sp-muted"><?php echo esc_html(implode(', ', $other_roles)); ?></td>
                    <td><?php echo esc_html($m->telephone); ?></td>
                    <td><?php echo esc_html($m->email); ?></td>
                    <td><a href="<?php echo esc_url($edit_url); ?>" class="button button-small">✏️</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════════════════════════════════════
             BLOC : Déplacements kilométriques exceptionnels
        ════════════════════════════════════════════════════ -->
        <?php
        $trainers_e = $this->db->get_trainers_entraineurs( true );
        // Déplacements du mois en cours
        $tkm2  = $this->db->table_km_exceptionnels();
        $tt2   = $this->db->table_trainers();
        $cur_y = intval(date('Y')); $cur_m = intval(date('m'));
        $start_m = sprintf('%04d-%02d-01', $cur_y, $cur_m);
        $end_m   = date('Y-m-t', strtotime($start_m));
        $km_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT k.*, t.nom AS trainer_nom FROM $tkm2 k
             INNER JOIN $tt2 t ON t.id = k.trainer_id
             WHERE k.date BETWEEN %s AND %s ORDER BY k.date ASC, k.id ASC",
            $start_m, $end_m
        ) );
        ?>
        <div class="sp-box">
            <h2>🚗 Déplacements kilométriques exceptionnels</h2>
            <p class="description">Saisissez un déplacement hors trajet habituel (compétition, stage, etc.). Les km s'ajoutent au récapitulatif mensuel de l'entraîneur concerné.</p>

            <!-- Formulaire de saisie -->
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;max-width:900px;margin-bottom:16px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Entraîneur</label>
                    <select id="km-excep-trainer" style="min-width:160px;">
                        <option value="">— Choisir —</option>
                        <?php foreach($trainers_e as $t): ?>
                        <option value="<?php echo intval($t->id); ?>"><?php echo esc_html($t->nom); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Date</label>
                    <input type="date" id="km-excep-date" value="<?php echo esc_attr(date('Y-m-d')); ?>" style="width:150px;">
                </div>
                <div style="flex:1;min-width:180px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Description</label>
                    <input type="text" id="km-excep-desc" placeholder="Ex : Coupe de Claira" style="width:100%;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Km aller-retour</label>
                    <input type="number" id="km-excep-km" min="1" step="0.5" placeholder="84" style="width:90px;">
                </div>
                <div>
                    <button class="button button-primary" id="km-excep-add-btn">➕ Ajouter</button>
                </div>
            </div>
            <div id="km-excep-result" style="font-size:13px;margin-bottom:10px;display:none;"></div>

            <!-- Liste du mois en cours -->
            <div id="km-excep-table-wrap">
            <?php if ( $km_rows ) : ?>
            <h3 style="font-size:13px;font-weight:600;margin-bottom:8px;">
                📅 Déplacements de <?php echo esc_html( (new DateTime($start_m))->format('F Y') ); ?>
            </h3>
            <table class="wp-list-table widefat fixed striped" style="max-width:860px;">
                <thead><tr>
                    <th>Entraîneur</th><th>Date</th><th>Description</th><th>Km A/R</th><th style="width:80px;"></th>
                </tr></thead>
                <tbody id="km-excep-tbody">
                <?php foreach($km_rows as $kr): ?>
                <tr id="km-excep-row-<?php echo intval($kr->id); ?>">
                    <td><?php echo esc_html($kr->trainer_nom); ?></td>
                    <td><?php echo esc_html( date('d/m/Y', strtotime($kr->date)) ); ?></td>
                    <td><?php echo esc_html($kr->description); ?></td>
                    <td><?php echo number_format(floatval($kr->km),1,',',' '); ?> km</td>
                    <td>
                        <button class="button button-small sp-km-excep-delete" style="color:#dc2626;"
                                data-id="<?php echo intval($kr->id); ?>"
                                onclick="spKmExcepDelete(<?php echo intval($kr->id); ?>)">🗑️</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p id="km-excep-empty" class="sp-muted" style="font-size:13px;">Aucun déplacement exceptionnel ce mois.</p>
            <?php endif; ?>
            </div>
        </div>

        <script>
        (function($){
            var nonce = '<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>';
            $('#km-excep-add-btn').on('click', function(){
                var trainer = $('#km-excep-trainer').val();
                var date    = $('#km-excep-date').val();
                var desc    = $('#km-excep-desc').val().trim();
                var km      = $('#km-excep-km').val();
                var $res    = $('#km-excep-result');
                if (!trainer) { $res.show().css('color','#dc2626').text('⚠️ Choisissez un entraîneur.'); return; }
                if (!date)    { $res.show().css('color','#dc2626').text('⚠️ Date manquante.'); return; }
                if (!km || parseFloat(km) <= 0) { $res.show().css('color','#dc2626').text('⚠️ Km invalides.'); return; }
                var $btn = $(this).prop('disabled',true).text('…');
                $.post(ajaxurl,{
                    action:'sp_cal_save_km_excep', nonce:nonce,
                    trainer_id:trainer, date:date, description:desc, km:km
                }, function(res){
                    $btn.prop('disabled',false).text('➕ Ajouter');
                    if (!res.success) { $res.show().css('color','#dc2626').text('❌ '+res.data); return; }
                    $res.show().css('color','#15803d').text('✅ Déplacement enregistré.');
                    // Recharger la page pour rafraîchir le tableau
                    setTimeout(function(){ location.reload(); }, 800);
                });
            });
        }(jQuery));
        function spKmExcepDelete(id){
            if (!confirm('Supprimer ce déplacement ?')) return;
            jQuery.post(ajaxurl,{action:'sp_cal_delete_km_excep',nonce:'<?php echo wp_create_nonce('sp_cal_admin_nonce'); ?>',km_id:id},function(res){
                if (res.success) jQuery('#km-excep-row-'+id).fadeOut(300, function(){ jQuery(this).remove(); });
            });
        }
        </script>

        </div><!-- /.wrap -->
        <?php
    }

    /* ══════════════════════════════════════════════════════════
       PAGE : Créneaux horaires
    ══════════════════════════════════════════════════════════ */

    public function page_slots() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
        global $wpdb;
        $tsl   = $this->db->table_slots();
        $slots = $this->db->get_slots();

        $edit = null;
        if ( isset( $_GET['sp_edit_slot'] ) ) {
            $edit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tsl WHERE id=%d", intval( $_GET['sp_edit_slot'] ) ) );
        }

        $jours = array( 1=>'Lundi', 2=>'Mardi', 3=>'Mercredi', 4=>'Jeudi', 5=>'Vendredi', 6=>'Samedi', 7=>'Dimanche' );
        $jours_court = array( 1=>'Lun', 2=>'Mar', 3=>'Mer', 4=>'Jeu', 5=>'Ven', 6=>'Sam', 7=>'Dim' );

        $rec_options = array(
            'weekly'    => '🔄 Chaque semaine',
            'biweekly'  => '🔄 1 semaine / 2',
            '3weekly'   => '🔄 1 semaine / 3',
            'monthly_1' => '📅 Chaque mois',
            'monthly_2' => '📅 Tous les 2 mois',
            'monthly_3' => '📅 Tous les 3 mois',
            'monthly_4' => '📅 Tous les 4 mois',
            'monthly_5' => '📅 Tous les 5 mois',
            'monthly_6' => '📅 Tous les 6 mois',
            'monthly_7' => '📅 Tous les 7 mois',
            'monthly_8' => '📅 Tous les 8 mois',
            'monthly_9' => '📅 Tous les 9 mois',
        );

        $ed = array( 'jour'=>1, 'label'=>'', 'cat'=>'', 'disc'=>array(), 'ages'=>array(), 'ordre'=>0, 'dh'=>'08', 'dm'=>'00', 'fh'=>'09', 'fm'=>'00', 'rec'=>'weekly', 'dd'=>'', 'df'=>'' );
        if ( $edit ) {
            $ed['jour']  = intval( $edit->jour );
            $ed['label'] = $edit->label;
            $ed['cat']   = $edit->categorie;
            $ed['disc']  = array_filter( array_map( 'trim', explode( ',', (string) ( $edit->cours_discipline ?? '' ) ) ) );
            $ed['ages']  = array_filter( array_map( 'trim', explode( ',', (string) ( $edit->cours_age_categories ?? '' ) ) ) );
            // Créneau pas encore reclassé : cases pré-cochées d'après l'ancien texte libre.
            if ( ! $ed['disc'] && ! $ed['ages'] && trim( (string) $edit->categorie ) !== '' ) {
                $axes        = $this->db->axes_depuis_texte_creneau( $edit->categorie );
                $ed['a_reclasser'] = true;
                $ed['disc']  = $axes['discipline'];
                $ed['ages']  = $axes['age'];
            }
            $ed['ordre'] = intval( $edit->ordre );
            $ed['rec']   = $edit->recurrence ?? 'weekly';
            $ed['dd']    = $edit->date_debut ?? '';
            $ed['df']    = $edit->date_fin   ?? '';
            if ( preg_match( '/^(\d{2})h(\d{2})$/', $edit->heure_debut, $m ) ) { $ed['dh'] = $m[1]; $ed['dm'] = $m[2]; }
            if ( preg_match( '/^(\d{2})h(\d{2})$/', $edit->heure_fin,   $m ) ) { $ed['fh'] = $m[1]; $ed['fm'] = $m[2]; }
        }

        $by_jour = array();
        foreach ( $slots as $s ) $by_jour[ intval($s->jour) ][] = $s;
        ?>
        <div class="wrap sp-cal-wrap">
        <h1>Créneaux horaires</h1>
        <?php $this->notice_flash( 'saved', 'Créneau enregistré.' ); ?>
        <?php $this->notice_flash( 'deleted', 'Créneau supprimé.' ); ?>
        <?php if ( isset( $_GET['reclasses'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . intval( $_GET['reclasses'] ) . ' créneau(x) reclassé(s) en Discipline × Pour qui.</p></div>'; ?>

        <?php
        // Reclassement de l'existant : ancien texte libre → Discipline × Pour qui (05/10/2026).
        $a_reclasser = $this->db->preview_migration_creneaux_categories();
        if ( $a_reclasser ) :
            $nb_auto = count( array_filter( $a_reclasser, static fn( $l ) => $l['discipline'] !== '' || $l['age'] !== '' || $l['tout'] ) );
        ?>
        <div class="sp-box" style="border-left:4px solid #f59e0b;">
            <h2>⚠️ <?php echo count( $a_reclasser ); ?> créneau(x) avec l'ancienne catégorie en texte libre</h2>
            <p class="description">La catégorie d'un créneau se choisit désormais avec des cases (Discipline × Pour qui), comme les événements : c'est elle qui décide quels adhérents sont prévenus d'une annulation et voient le cours dans leur application. Voici ce que donnerait la conversion automatique ; ce qui n'est pas reconnu reste à classer à la main (bouton ✏️ du créneau).</p>
            <table class="widefat striped" style="margin:10px 0;">
                <thead><tr><th>Créneau</th><th>Ancienne catégorie</th><th>Discipline</th><th>Pour qui</th><th>Résultat</th></tr></thead>
                <tbody>
                <?php foreach ( $a_reclasser as $l ) :
                    $auto = $l['discipline'] !== '' || $l['age'] !== '' || $l['tout']; ?>
                    <tr>
                        <td><?php echo esc_html( $jours[ $l['jour'] ] . ' ' . $l['heure_debut'] . ' — ' . $l['label'] ); ?></td>
                        <td><code><?php echo esc_html( $l['ancienne'] ); ?></code></td>
                        <td><?php echo esc_html( str_replace( ',', ', ', $l['discipline'] ) ?: '—' ); ?></td>
                        <td><?php echo esc_html( str_replace( ',', ', ', $l['age'] ) ?: ( $l['tout'] && $l['discipline'] === '' ? 'Tout âge' : '—' ) ); ?></td>
                        <td><?php
                            if ( ! $auto ) echo '<span style="color:#b45309">À classer à la main</span>';
                            elseif ( $l['non_reconnus'] ) echo '✅ <span style="color:#b45309">(ignoré : ' . esc_html( implode( ', ', $l['non_reconnus'] ) ) . ')</span>';
                            else echo '✅';
                        ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ( $nb_auto ) : ?>
            <form method="post" onsubmit="return confirm('Reclasser <?php echo intval( $nb_auto ); ?> créneau(x) comme indiqué ?');">
                <?php wp_nonce_field( 'sp_migrer_creneaux_categories' ); ?>
                <input type="hidden" name="sp_migrer_creneaux_categories" value="1">
                <button class="button button-primary">Reclasser <?php echo intval( $nb_auto ); ?> créneau(x)</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="sp-two-col">

        <!-- FORMULAIRE -->
        <div class="sp-box">
            <h2><?php echo $edit ? '✏️ Modifier le créneau' : '➕ Nouveau créneau'; ?></h2>
            <p class="description">Un même intitulé peut exister sur plusieurs jours avec des cours différents.</p>
            <form method="post" style="margin-top:12px;">
                <?php wp_nonce_field( 'sp_cal_save_slot' ); ?>
                <input type="hidden" name="slot_id" value="<?php echo $edit ? intval($edit->id) : 0; ?>">
                <table class="form-table sp-form-stack">
                    <tr>
                        <th>Jour</th>
                        <td><select name="slot_jour" class="sp-select">
                            <?php foreach($jours as $n=>$l) echo '<option value="'.  $n .'"'. selected($ed['jour'],$n,false) .'>'. $l .'</option>'; ?>
                        </select></td>
                    </tr>
                    <tr>
                        <th>Intitulé du cours</th>
                        <td><input type="text" name="slot_label" class="regular-text" value="<?php echo esc_attr($ed['label']); ?>" placeholder="ex: Cours TKD adultes" required></td>
                    </tr>
                    <tr>
                        <th>Heure de début</th>
                        <td class="sp-time-row">
                            <?php echo $this->time_select('slot_debut_h',$ed['dh'],1,24); ?> h
                            <?php echo $this->time_select('slot_debut_m',$ed['dm']); ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Heure de fin</th>
                        <td class="sp-time-row">
                            <?php echo $this->time_select('slot_fin_h',$ed['fh'],1,24); ?> h
                            <?php echo $this->time_select('slot_fin_m',$ed['fm']); ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Discipline</th>
                        <td>
                            <div class="sp-checks"><?php foreach ( SpCalPro_DB::COURS_DISCIPLINES as $d ) : ?>
                                <label class="sp-check"><input type="checkbox" name="slot_discipline[]" value="<?php echo esc_attr( $d ); ?>" <?php checked( in_array( $d, $ed['disc'], true ) ); ?>> <?php echo esc_html( $d ); ?></label>
                            <?php endforeach; ?></div>
                        </td>
                    </tr>
                    <tr>
                        <th>Pour qui</th>
                        <td>
                            <div class="sp-checks"><?php foreach ( SpCalPro_DB::COURS_AGES as $a ) : ?>
                                <label class="sp-check"><input type="checkbox" name="slot_ages[]" value="<?php echo esc_attr( $a ); ?>" <?php checked( in_array( $a, $ed['ages'], true ) ); ?>> <?php echo esc_html( $a ); ?></label>
                            <?php endforeach; ?></div>
                            <p class="description">Sert à prévenir les bons adhérents quand le cours est annulé et à afficher le cours dans leur application. Rien de coché = tout le club.<?php if ( ! empty( $ed['a_reclasser'] ) ) echo '<br><strong>⚠️ Ancienne catégorie en texte libre : « ' . esc_html( $ed['cat'] ) . ' ».</strong> Les cases ont été pré-cochées à partir de ce texte : vérifiez-les puis enregistrez.'; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th>🔄 Récurrence</th>
                        <td>
                            <select name="slot_recurrence" class="sp-select" id="slot-rec-sel">
                                <?php foreach($rec_options as $rv=>$rl) echo '<option value="'. $rv .'"'. selected($ed['rec'],$rv,false) .'>'. $rl .'</option>'; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Date de début</th>
                        <td>
                            <input type="date" name="slot_date_debut" id="slot-date-debut" value="<?php echo esc_attr($ed['dd']); ?>" class="sp-input" style="width:160px;">
                            <p class="description">Requise pour biweekly, 3weekly et mensuel. Définit la semaine de référence.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Date de fin <small>(optionnel)</small></th>
                        <td><input type="date" name="slot_date_fin" value="<?php echo esc_attr($ed['df']); ?>" class="sp-input" style="width:160px;"></td>
                    </tr>
                    <tr>
                        <th>Ordre</th>
                        <td><input type="number" name="slot_ordre" value="<?php echo $ed['ordre']; ?>" style="width:80px;"></td>
                    </tr>
                </table>
                <p>
                    <input type="submit" name="sp_cal_save_slot" class="button button-primary" value="<?php echo $edit ? 'Mettre à jour' : 'Ajouter le créneau'; ?>">
                    <?php if($edit) echo '<a href="'. admin_url('admin.php?page=sp-cal-slots') .'" class="button" style="margin-left:8px;">Annuler</a>'; ?>
                </p>
            </form>
            <script>
            document.getElementById('slot-rec-sel').addEventListener('change', function(){
                var needsDate = (this.value !== 'weekly');
                document.getElementById('slot-date-debut').style.borderColor = needsDate ? '#f59e0b' : '';
            });
            </script>
        </div>

        <!-- APERÇU PLANNING -->
        <div class="sp-box">
            <h2>📅 Aperçu planning hebdo (<?php echo count($slots); ?> créneaux)</h2>
            <?php if ( empty($slots) ) : ?>
                <p class="sp-muted">Aucun créneau configuré.</p>
            <?php else : ?>
            <div style="overflow-x:auto;">
            <table class="sp-planning-preview">
                <thead><tr>
                    <?php foreach($jours_court as $n=>$l) echo '<th>'. $l .'</th>'; ?>
                </tr></thead>
                <tbody><tr>
                <?php foreach($jours as $num=>$label) : ?>
                <td>
                    <?php if(isset($by_jour[$num])) foreach($by_jour[$num] as $s) :
                        $rec_label = $rec_options[$s->recurrence ?? 'weekly'] ?? 'weekly';
                        $is_rec    = ($s->recurrence ?? 'weekly') === 'weekly';
                        $icon      = strpos($s->recurrence ?? '','monthly') !== false ? '📅' : '🔄';
                    ?>
                    <div class="sp-slot-preview">
                        <div style="font-size:11px;margin-bottom:3px;"><?php echo $icon; ?> <strong><?php echo esc_html($s->label); ?></strong></div>
                        <span class="sp-muted"><?php echo esc_html($s->heure_debut.' – '.$s->heure_fin); ?></span><br>
                        <?php
                        $lib_cr = $this->db->libelle_creneau( $s );
                        $a_recl = trim( (string) ( $s->cours_discipline ?? '' ) ) === '' && trim( (string) ( $s->cours_age_categories ?? '' ) ) === '' && trim( (string) $s->categorie ) !== '';
                        if ( $lib_cr !== '' ) echo '<span class="sp-badge-blue"' . ( $a_recl ? ' style="background:#fef3c7;color:#92400e" title="Ancienne catégorie en texte libre : à reclasser"' : '' ) . '>' . ( $a_recl ? '⚠️ ' : '' ) . esc_html( $lib_cr ) . '</span>';
                        ?>
                        <div style="margin-top:4px;font-size:10px;color:#6b7280;"><?php echo esc_html($rec_label); ?></div>
                        <?php if($s->date_debut) echo '<div style="font-size:10px;color:#888;">Du '. esc_html($s->date_debut) . ($s->date_fin ? ' au '. esc_html($s->date_fin) : '') .'</div>'; ?>
                        <div style="margin-top:5px;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=sp-cal-slots&sp_edit_slot='.$s->id)); ?>" class="button button-small">✏️</a>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=sp-cal-slots&sp_delete_slot='.$s->id),'sp_delete_slot_'.$s->id)); ?>" class="button button-small sp-btn-del" onclick="return confirm('Supprimer ?')">🗑️</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </td>
                <?php endforeach; ?>
                </tr></tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        </div><!-- .sp-two-col -->
        </div>
        <?php
    }

    private function time_select( $name, $selected, $from = null, $to = null ) {
        if ( $from !== null ) {
            // Sélecteur heures
            $html = '<select name="' . esc_attr($name) . '" class="sp-time-sel">';
            for ( $h = $from; $h <= $to; $h++ ) {
                $hh = sprintf('%02d', $h);
                $html .= '<option value="' . $hh . '"' . selected($selected, $hh, false) . '>' . $hh . '</option>';
            }
            $html .= '</select>';
        } else {
            // Sélecteur minutes
            $html = '<select name="' . esc_attr($name) . '" class="sp-time-sel">';
            foreach ( array('00','15','30','45') as $mm ) {
                $html .= '<option value="' . $mm . '"' . selected($selected, $mm, false) . '>' . $mm . '</option>';
            }
            $html .= '</select>';
        }
        return $html;
    }
}

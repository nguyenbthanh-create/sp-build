<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$is_admin    = current_user_can( 'manage_options' );
$public_link = get_option( 'sp_cal_public_link', home_url('/planning/') );
?>
<div id="sp-cal-container">

    <div class="sp-cal-toolbar">
        <a href="<?php echo esc_url($public_link); ?>" target="_blank" class="sp-cal-btn-apercu">
            <span class="dashicons dashicons-external"></span> Planning public
        </a>
        <div class="sp-cal-nav-center">
            <button id="cal-prev" class="sp-nav-btn"><span class="dashicons dashicons-arrow-left-alt2"></span></button>
            <div style="text-align:center;">
                <div id="cal-title" class="sp-cal-month-title"></div>
                <div id="cal-today-label" class="sp-cal-today-label"></div>
            </div>
            <button id="cal-next" class="sp-nav-btn"><span class="dashicons dashicons-arrow-right-alt2"></span></button>
        </div>
        <div style="min-width:160px;text-align:right;">
            <?php if($is_admin): ?>
            <button id="cal-today-btn" class="sp-cal-btn-apercu" style="background:rgba(255,255,255,0.15);">Aujourd'hui</button>
            <?php endif; ?>
        </div>
    </div>

    <div id="cal-loading" style="display:none;text-align:center;padding:40px;color:#888;">
        Chargement…
    </div>
    <div id="cal-grid-wrap"></div>

    <?php if ( $is_admin ) : ?>
    <!-- ═══════════════ MODALE ═══════════════ -->
    <div class="sp-modal-overlay" id="sp-event-modal">
        <div class="sp-modal-box">

            <div class="sp-modal-header">
                <h3 id="sp-modal-title">Gestion du jour</h3>
                <button id="sp-modal-close" class="sp-modal-close">&times;</button>
            </div>

            <!-- ── VUE : résumé du jour ───────────────────── -->
            <div id="modal-view-day">

                <!-- Section cours récurrents -->
                <div class="sp-modal-section" id="section-recurrents" style="display:none;">
                    <div class="sp-section-title">🔄 Cours programmés</div>
                    <div id="list-recurrents"></div>
                </div>

                <!-- Section cours + événements ponctuels -->
                <div class="sp-modal-section" id="section-ponctuels" style="display:none;">
                    <div class="sp-section-title">⚡ Cours &amp; événements ponctuels</div>
                    <div id="list-ponctuels"></div>
                </div>

                <!-- Section anniversaires -->
                <div class="sp-modal-section" id="section-bdays" style="display:none;">
                    <div class="sp-section-title">🎂 Anniversaires</div>
                    <div id="list-bdays"></div>
                </div>

                <!-- Section disponibilités entraîneurs -->
                <div class="sp-modal-section" id="section-dispos">
                    <div class="sp-section-title sp-dispo-title-row">
                        <span>🙋 Disponibilités entraîneurs</span>
                        <span id="dispo-summary" class="sp-dispo-summary"></span>
                    </div>
                    <div id="list-dispos">
                        <div style="text-align:center;color:#aaa;padding:14px;font-size:13px;">Chargement…</div>
                    </div>
                    <!-- Bouton envoi bureau — visible uniquement quand il y a des changements J-3 -->
                    <div id="dispo-notif-bar" style="display:none;margin-top:10px;padding:10px 12px;background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;">
                        <div style="font-size:12px;color:#92400e;margin-bottom:8px;" id="dispo-notif-summary"></div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <button id="btn-send-dispo-notif" class="button button-primary" style="background:#1e3a5f;border-color:#1e3a5f;font-size:12px;height:32px;">
                                ✉️ Envoyer la notification au bureau
                            </button>
                            <span id="dispo-notif-result" style="font-size:12px;color:#15803d;display:none;"></span>
                        </div>
                    </div>
                </div>

                <div id="no-day-content" style="text-align:center;color:#888;padding:8px 0 4px;font-size:13px;">
                    Aucun cours ni événement ce jour.
                </div>

                <div class="sp-day-quick-actions">
                    <button id="btn-new-event" class="button button-primary sp-quick-btn">
                        ➕ Ajouter cours / événement
                    </button>
                    <button id="btn-quick-presence" class="button sp-quick-btn" style="background:#15803d;color:#fff;border-color:#15803d;">
                        👥 Présences des élèves
                    </button>
                </div>
            </div>

            <!-- ── VUE : sélection du cours pour les présences ── -->
            <div id="modal-view-pick" style="display:none;">
                <p style="font-size:13px;color:#6b7280;margin:0 0 12px;">
                    Plusieurs cours ce jour. Choisissez celui pour lequel saisir les présences :
                </p>
                <div id="pick-list"></div>
                <button id="btn-pick-back" class="button" style="margin-top:14px;width:100%;">
                    ← Retour
                </button>
            </div>

            <!-- ── VUE : formulaire création / édition ────── -->
            <div id="modal-view-form" style="display:none;">

                <div class="sp-modal-tabs">
                    <button class="sp-tab-btn active" data-tab="details">📅 Détails</button>
                    <button class="sp-tab-btn" data-tab="presences" id="tab-presences-btn" disabled>
                        👥 Présences <span id="pres-count" class="sp-count" style="display:none;"></span>
                    </button>
                    <button class="sp-tab-btn" data-tab="examen" id="tab-examen-btn" disabled style="display:none;">
                        🎓 Candidats <span id="exam-count" class="sp-count" style="display:none;"></span>
                    </button>
                    <button class="sp-tab-btn" data-tab="competition" id="tab-competition-btn" disabled style="display:none;">
                        🏆 Compétition
                    </button>
                    <button class="sp-tab-btn" data-tab="sondage" id="tab-sondage-btn" disabled style="display:none;">
                        🗳️ Sondage
                    </button>
                </div>

                <!-- Détails -->
                <div id="tab-details" class="sp-tab-content active">
                    <div class="sp-form-grid">
                        <div class="sp-form-row sp-form-full">
                            <label>Titre <span style="color:#e00;">*</span></label>
                            <input type="text" id="ev-titre" class="sp-input" placeholder="ex: Cours TKD adultes">
                        </div>
                        <div class="sp-form-row">
                            <label>Date <span style="color:#e00;">*</span></label>
                            <input type="date" id="ev-date" class="sp-input">
                        </div>
                        <div class="sp-form-row">
                            <label>Type</label>
                            <select id="ev-type" class="sp-input">
                                <option value="cours">🥋 Cours</option>
                                <option value="evenement">📅 Événement</option>
                                <option value="examen">🎓 Examen / Passage de grade</option>
                                <option value="competition">🏆 Compétition</option>
                            </select>
							<div id="ev-niveau-wrap" style="display:none;margin-top:8px;">
    <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">🏆 Niveau de la compétition</label>
    <select id="ev-niveau" class="sp-input">
        <option value="departemental">🏘️ Départemental</option>
        <option value="regional">🌍 Régional</option>
        <option value="national">🇫🇷 National</option>
        <option value="international">🌐 International</option>
    </select>
</div>
                        </div>
                        <div class="sp-form-row">
                            <label>Heure début</label>
                            <input type="time" id="ev-debut" class="sp-input">
                        </div>
                        <div class="sp-form-row">
                            <label>Heure fin</label>
                            <input type="time" id="ev-fin" class="sp-input">
                        </div>
                        <div class="sp-form-row">
                            <label>Catégorie</label>
                            <input type="text" id="ev-categorie" list="ev-cat-list" class="sp-input" placeholder="TKD, Renfo…">
                            <datalist id="ev-cat-list"></datalist>
                        </div>
                        <div class="sp-form-row">
                            <label>Couleur</label>
                            <input type="color" id="ev-couleur" value="#3B82F6" class="sp-input" style="height:38px;">
                        </div>
                        <div class="sp-form-row sp-form-full">
                            <label>Description</label>
                            <textarea id="ev-description" class="sp-input" rows="2" placeholder="Optionnel…"></textarea>
                        </div>
                        <div class="sp-form-row sp-form-full" id="row-document">
                            <label>📄 Document joint <span style="font-size:11px;color:#9ca3af;">(PDF, image, Word — max 5 Mo)</span></label>
                            <div id="doc-current" style="display:none;padding:6px 10px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;font-size:12px;display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <a id="doc-current-link" href="#" target="_blank" style="font-weight:600;color:#15803d;">📄 <span id="doc-current-name"></span></a>
                                <button type="button" id="btn-delete-doc" class="button button-small" style="color:#b91c1c;border-color:#b91c1c;padding:1px 8px;font-size:11px;" title="Supprimer le document">✕</button>
                            </div>
                            <input type="file" id="ev-document" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx" style="font-size:13px;">
                            <div id="doc-upload-progress" style="display:none;margin-top:4px;font-size:12px;color:#6b7280;">Envoi en cours…</div>
                        </div>
                    </div><!-- /.sp-form-grid -->
                </div><!-- /#tab-details -->

                <!-- Présences -->
                <div id="tab-presences" class="sp-tab-content">
                    <div id="pres-toolbar" style="display:flex;gap:8px;margin-bottom:10px;align-items:center;flex-wrap:wrap;">
                        <button id="pres-all"  class="button button-small">✅ Tous présents</button>
                        <button id="pres-none" class="button button-small">⬜ Tout décocher</button>
                        <select id="pres-flt-saisie" style="height:30px;border:1px solid #ccc;border-radius:4px;padding:0 6px;">
                            <option value="">— Tous les groupes —</option>
                        </select>
                        <input type="text" id="pres-search" placeholder="🔍 Nom…" style="flex:1;min-width:100px;height:30px;border:1px solid #ccc;border-radius:4px;padding:0 8px;">
                    </div>
                    <div id="pres-list" style="max-height:280px;overflow-y:auto;border:1px solid #eee;border-radius:6px;"></div>
                    <div id="pres-loading" style="text-align:center;padding:30px;color:#888;display:none;">Chargement…</div>
                </div>

                <!-- Examen / Passage de grade -->
                <div id="tab-examen" class="sp-tab-content">
                    <div id="exam-toolbar" style="display:flex;gap:8px;margin-bottom:10px;align-items:center;flex-wrap:wrap;">
                        <select id="exam-flt-saisie" style="height:30px;border:1px solid #ccc;border-radius:4px;padding:0 6px;">
                            <option value="">— Tous les groupes —</option>
                        </select>
                        <input type="text" id="exam-search" placeholder="🔍 Nom…" style="flex:1;min-width:100px;height:30px;border:1px solid #ccc;border-radius:4px;padding:0 8px;">
                        <button id="exam-select-all" class="button button-small">✅ Sélectionner tous</button>
                    </div>
                    <div id="exam-list" style="max-height:260px;overflow-y:auto;border:1px solid #eee;border-radius:6px;"></div>
                    <div id="exam-loading" style="text-align:center;padding:30px;color:#888;display:none;">Chargement…</div>
                    <div id="exam-apply-bar" style="margin-top:10px;display:none;">
                        <button id="btn-apply-grades" class="button button-primary" style="background:#15803d;border-color:#15803d;">
                            ✅ Appliquer les grades aux fiches élèves
                        </button>
                        <span id="exam-apply-msg" style="margin-left:10px;font-size:13px;color:#15803d;display:none;"></span>
                    </div>
                </div>

                <!-- Compétition -->
                <div id="tab-competition" class="sp-tab-content">
                    <div id="comp-loading" style="text-align:center;padding:30px;color:#888;display:none;">Chargement…</div>

                    <!-- Épreuves -->
                    <div id="comp-epreuves-section">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <span style="font-size:12px;font-weight:700;color:#374151;">📋 Épreuves (max 6)</span>
                            <span id="btn-add-epreuve" class="sp-occ-btn sp-occ-btn-restore" role="button" tabindex="0">+ Ajouter une épreuve</span>
                        </div>
                        <div id="comp-epreuves-list"></div>
                        <div style="margin-top:6px;">
                            <span id="btn-save-epreuves" class="sp-occ-btn sp-occ-btn-restore" role="button" tabindex="0">💾 Enregistrer les épreuves</span>
                            <span id="comp-epreuves-msg" style="margin-left:8px;font-size:12px;color:#15803d;display:none;"></span>
                        </div>
                    </div>

                    <hr style="margin:12px 0;border:none;border-top:1px solid #e5e7eb;">

                    <!-- Résultats -->
                    <div id="comp-resultats-section">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <span style="font-size:12px;font-weight:700;color:#374151;">🏅 Résultats par épreuve</span>
                            <select id="comp-flt-saisie" style="height:28px;border:1px solid #ccc;border-radius:4px;padding:0 6px;font-size:12px;">
                                <option value="">— Tous —</option>
                            </select>
                        </div>
                        <div id="comp-resultats-list" style="max-height:280px;overflow-y:auto;"></div>
                        <div style="margin-top:8px;">
                            <span id="btn-save-resultats" class="sp-occ-btn sp-occ-btn-restore" role="button" tabindex="0">💾 Enregistrer les résultats</span>
                            <span id="comp-resultats-msg" style="margin-left:8px;font-size:12px;color:#15803d;display:none;"></span>
                        </div>
                    </div>
                </div>

                <!-- Sondage post-événement -->
                <div id="tab-sondage" class="sp-tab-content">
                    <div id="sondage-loading" style="text-align:center;padding:30px;color:#888;display:none;">Chargement…</div>
                    <div id="sondage-wrap">

                        <!-- Zone édition questions -->
                        <div id="sondage-edit-zone">
                            <p style="font-size:12px;color:#6b7280;margin:0 0 10px;">
                                Questions envoyées automatiquement aux élèves inscrits le lendemain de l'événement.
                            </p>
                            <div id="sondage-questions-list"></div>
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <span id="btn-sondage-add-note"  class="sp-occ-btn sp-occ-btn-restore" role="button" tabindex="0">⭐ + Note 1-5</span>
                                <span id="btn-sondage-add-texte" class="sp-occ-btn sp-occ-btn-restore" role="button" tabindex="0">💬 + Question texte</span>
                            </div>
                            <div style="margin-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                <span id="btn-sondage-save" class="sp-occ-btn sp-occ-btn-restore" role="button" tabindex="0">💾 Enregistrer les questions</span>
                                <span id="sondage-save-msg" style="font-size:12px;color:#15803d;display:none;"></span>
                            </div>

                            <!-- Liste élèves destinataires -->
                            <div style="margin-top:14px;padding-top:12px;border-top:1px solid #e5e7eb;">
                                <p style="font-size:12px;color:#6b7280;margin:0 0 8px;">📋 Destinataires — cochez les élèves à qui envoyer le sondage :</p>
                                <div id="sondage-eleves-loading" style="font-size:12px;color:#9ca3af;">Chargement…</div>
                                <div id="sondage-eleves-list" style="max-height:180px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:8px 10px;background:#fafafa;"></div>
                            </div>

                            <div style="margin-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                <span id="btn-sondage-envoyer" role="button" tabindex="0"
                                      style="display:inline-block;background:#1d4ed8;color:#fff;padding:7px 14px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">
                                    📨 Envoyer par email
                                </span>
                                <span id="sondage-envoyer-msg" style="font-size:12px;display:none;"></span>
                            </div>
                        </div>

                        <!-- Zone résultats (après envoi) -->
                        <div id="sondage-resultats-zone" style="display:none;">
                            <p style="font-size:12px;color:#6b7280;margin:0 0 12px;">
                                ✅ Sondage envoyé. Les questions ne sont plus modifiables.
                            </p>
                            <div id="sondage-resultats-list"></div>
                        </div>

                    </div>
                </div>

                <div class="sp-modal-footer">
                    <button id="btn-back-day" class="button">← Retour</button>
                    <div style="display:flex;gap:8px;">
                        <button id="btn-delete-event" class="button" style="color:#c00;display:none;">🗑️ Supprimer</button>
                        <button id="btn-save-event" class="button button-primary">Enregistrer</button>
                    </div>
                </div>

            </div><!-- #modal-view-form -->

        </div><!-- .sp-modal-box -->
    </div><!-- .sp-modal-overlay -->
    <?php endif; ?>

</div><!-- #sp-cal-container -->

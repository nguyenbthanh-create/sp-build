<?php
/**
 * Template : liste des élèves actifs — shortcode [sp_cal_eleves]
 * Variables disponibles : $eleves (array of stdClass), $this (SpCalPro_ElevesFront)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Collecter les catégories présentes (triées)
$cats_presentes = array();
foreach ( $eleves as $el ) {
    $c = trim( $el->categorie_age ?? '' );
    if ( $c && ! in_array( $c, $cats_presentes ) ) $cats_presentes[] = $c;
}
sort( $cats_presentes );
?>
<div class="sp-eleves-wrap">

    <!-- ── En-tête ─────────────────────────────────────────── -->
    <div class="sp-cal-toolbar">
        <span style="font-size:16px;font-weight:700;letter-spacing:.3px;">🥋 Liste des élèves</span>
        <span id="sp-eleves-total-label" style="font-size:13px;opacity:.8;">
            <?php echo count($eleves); ?> élève<?php echo count($eleves) !== 1 ? 's' : ''; ?> actif<?php echo count($eleves) !== 1 ? 's' : ''; ?>
        </span>
    </div>

    <?php if ( empty($eleves) ) : ?>
        <p style="color:#6b7280;font-style:italic;padding:16px;">Aucun élève actif pour le moment.</p>
    <?php else : ?>

    <!-- ── Filtres catégories ────────────────────────────────── -->
    <?php if ( ! empty($cats_presentes) ) : ?>
    <div class="sp-eleves-filters">
        <button class="sp-eleves-filter-btn active" data-cat="" onclick="spElevesSetCat(this,'')">
            Tous <span class="sp-filter-count"><?php echo count($eleves); ?></span>
        </button>
        <?php foreach ( $cats_presentes as $cat ) :
            $n = count( array_filter( $eleves, fn($e) => trim($e->categorie_age ?? '') === $cat ) );
        ?>
        <button class="sp-eleves-filter-btn" data-cat="<?php echo esc_attr($cat); ?>" onclick="spElevesSetCat(this,'<?php echo esc_js($cat); ?>')">
            <?php echo esc_html($cat); ?> <span class="sp-filter-count"><?php echo $n; ?></span>
        </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Recherche ────────────────────────────────────────── -->
    <div style="margin-bottom:14px;">
        <input type="text" id="sp-eleves-search" class="sp-input"
               placeholder="🔍 Rechercher par nom, prénom, grade…"
               style="max-width:400px;" oninput="spElevesFilter()">
    </div>

    <!-- ── Tableau ──────────────────────────────────────────── -->
    <div class="sp-eleves-table-wrap">
        <table class="sp-eleves-table" id="sp-eleves-table">
            <thead>
                <tr>
                    <th class="sp-sortable" onclick="spElevesSort(0)">Nom <span class="sp-sort-icon">↕</span></th>
                    <th>Prénom</th>
                    <th class="sp-sortable" onclick="spElevesSort(2)">Catégorie <span class="sp-sort-icon">↕</span></th>
                    <th class="sp-sortable" onclick="spElevesSort(3)">Âge <span class="sp-sort-icon">↕</span></th>
                    <th class="sp-sortable" onclick="spElevesSort(4)">Grade <span class="sp-sort-icon">↕</span></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $eleves as $el ) :
                $age      = SpCalPro_ElevesFront::calcul_age( $el->annee_naissance );
                $age_str  = $age !== null ? $age . ' ans' : '—';
                $age_val  = $age !== null ? $age : 999;
                $cat      = trim( $el->categorie_age ?? '' );
                $grade    = trim( $el->grade ?? '' );
                $has_token = ! empty( $el->token );
                $fiche_url = $has_token ? $this->token->get_fiche_url( $el->token ) : '';
                $cat_slug  = sanitize_title( $cat );
            ?>
                <tr class="sp-eleves-row"
                    data-cat="<?php echo esc_attr($cat); ?>"
                    data-age="<?php echo $age_val; ?>">
                    <td class="sp-eleves-nom"><?php echo esc_html($el->nom); ?></td>
                    <td><?php echo esc_html($el->prenom); ?></td>
                    <td>
                        <?php if ($cat) : ?>
                            <span class="sp-eleves-badge sp-cat-<?php echo esc_attr($cat_slug); ?>"><?php echo esc_html($cat); ?></span>
                        <?php else : ?><span style="color:#9ca3af;">—</span><?php endif; ?>
                    </td>
                    <td data-sort="<?php echo $age_val; ?>"><?php echo esc_html($age_str); ?></td>
                    <td>
                        <?php if ($grade) : ?>
                            <span class="sp-eleves-grade"><?php echo esc_html($grade); ?></span>
                        <?php else : ?><span style="color:#9ca3af;">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <?php if ($has_token) : ?>
                            <a href="<?php echo esc_url($fiche_url); ?>" target="_blank" class="sp-eleves-btn-fiche">👤 Voir la fiche</a>
                        <?php else : ?>
                            <span class="sp-eleves-btn-fiche sp-eleves-btn-disabled" title="Token non encore généré">🔒 Fiche indisponible</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p id="sp-eleves-count" style="font-size:12px;color:#6b7280;margin-top:8px;"></p>

    <?php endif; ?>
</div>

<style>
.sp-eleves-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:960px;color:#1f2937}
.sp-eleves-wrap .sp-input{background:#fff!important;color:#1f2937!important;border:1px solid #d1d5db!important}
/* Filtres */
.sp-eleves-filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.sp-eleves-filter-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border:2px solid #d1d5db;border-radius:20px;background:#fff;color:#374151;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;line-height:1}
.sp-eleves-filter-btn:hover{border-color:#1e3a5f;color:#1e3a5f}
.sp-eleves-filter-btn.active{background:#1e3a5f!important;border-color:#1e3a5f!important;color:#fff!important}
.sp-filter-count{background:rgba(0,0,0,.1);border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700}
.sp-eleves-filter-btn.active .sp-filter-count{background:rgba(255,255,255,.25)}
/* Tableau */
.sp-eleves-table-wrap{overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;background:#fff}
.sp-eleves-table{width:100%;border-collapse:collapse;font-size:14px;background:#fff}
.sp-eleves-table thead tr{background:#1e3a5f!important;color:#fff!important}
.sp-eleves-table thead th{padding:11px 14px;text-align:left;font-weight:600;font-size:13px;white-space:nowrap;color:#fff!important;background:#1e3a5f!important;border:none!important}
.sp-eleves-table thead th:first-child{border-radius:10px 0 0 0}
.sp-eleves-table thead th:last-child{border-radius:0 10px 0 0}
.sp-sortable{cursor:pointer;user-select:none}
.sp-sortable:hover{background:#2d5a9e!important}
.sp-sort-icon{opacity:.5;font-size:11px;margin-left:3px}
.sp-sortable.asc .sp-sort-icon,.sp-sortable.desc .sp-sort-icon{opacity:1}
.sp-eleves-table tbody tr{border-bottom:1px solid #f1f5f9!important;background:#fff!important;transition:background .12s}
.sp-eleves-table tbody tr:last-child{border-bottom:none!important}
.sp-eleves-table tbody tr:hover{background:#f8fafc!important}
.sp-eleves-table td{padding:10px 14px;vertical-align:middle;color:#1f2937!important;border:none!important;background:transparent!important}
.sp-eleves-nom{font-weight:700;color:#1e3a5f!important}
/* Badges catégories */
.sp-eleves-badge{display:inline-block;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:600;border:none;background:#e0f2fe!important;color:#0369a1!important}
.sp-cat-baby{background:#fce7f3!important;color:#be185d!important}
.sp-cat-enfant{background:#d1fae5!important;color:#065f46!important}
.sp-cat-ado-adulte{background:#ede9fe!important;color:#5b21b6!important}
/* Grade */
.sp-eleves-grade{display:inline-block;background:#fef3c7!important;color:#92400e!important;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:600;border:none}
/* Bouton fiche */
.sp-eleves-btn-fiche{display:inline-block;background:#1e3a5f!important;color:#fff!important;border-radius:6px;padding:5px 12px;font-size:12px;font-weight:600;text-decoration:none!important;white-space:nowrap;transition:background .15s;border:none}
.sp-eleves-btn-fiche:hover{background:#2d5a9e!important;color:#fff!important}
.sp-eleves-btn-disabled{background:#e5e7eb!important;color:#9ca3af!important;cursor:not-allowed}
.sp-eleves-btn-disabled:hover{background:#e5e7eb!important}
.sp-eleves-row[data-hidden="1"]{display:none}
@media(max-width:600px){.sp-eleves-table thead th:nth-child(4),.sp-eleves-table td:nth-child(4){display:none}}
</style>

<script>
(function(){
    var _cat='', _col=-1, _asc=true;

    window.spElevesSetCat=function(btn,cat){
        _cat=cat;
        document.querySelectorAll('.sp-eleves-filter-btn').forEach(function(b){
            b.classList.toggle('active', b===btn);
        });
        spElevesFilter();
    };

    window.spElevesFilter=function(){
        var q=(document.getElementById('sp-eleves-search').value||'').toLowerCase().trim();
        var rows=document.querySelectorAll('#sp-eleves-table tbody .sp-eleves-row');
        var shown=0;
        rows.forEach(function(row){
            var ok=(!_cat||row.getAttribute('data-cat')===_cat)&&(!q||row.textContent.toLowerCase().indexOf(q)!==-1);
            row.setAttribute('data-hidden',ok?'0':'1');
            if(ok)shown++;
        });
        var el=document.getElementById('sp-eleves-count');
        el.textContent=(_cat||q)?shown+' / '+rows.length+' élève'+(rows.length!==1?'s':''):'';
    };

    window.spElevesSort=function(colIdx){
        var table=document.getElementById('sp-eleves-table');
        var tbody=table.querySelector('tbody');
        _asc=(_col===colIdx)?!_asc:true;
        _col=colIdx;
        table.querySelectorAll('thead .sp-sortable').forEach(function(th,i){
            var realIdx=parseInt(th.getAttribute('onclick').match(/\d+/)[0]);
            th.classList.remove('asc','desc');
            var ico=th.querySelector('.sp-sort-icon');
            if(realIdx===colIdx){th.classList.add(_asc?'asc':'desc');if(ico)ico.textContent=_asc?'↑':'↓';}
            else{if(ico)ico.textContent='↕';}
        });
        var rows=Array.from(tbody.querySelectorAll('tr.sp-eleves-row'));
        rows.sort(function(a,b){
            if(colIdx===3){
                var av=parseInt(a.getAttribute('data-age')||'999',10);
                var bv=parseInt(b.getAttribute('data-age')||'999',10);
                return _asc?av-bv:bv-av;
            }
            var av=(a.cells[colIdx]?a.cells[colIdx].textContent:'').trim().toLowerCase();
            var bv=(b.cells[colIdx]?b.cells[colIdx].textContent:'').trim().toLowerCase();
            return _asc?(av<bv?-1:av>bv?1:0):(av<bv?1:av>bv?-1:0);
        });
        rows.forEach(function(r){tbody.appendChild(r);});
    };
})();
</script>

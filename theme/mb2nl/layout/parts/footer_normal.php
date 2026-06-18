<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 *
 * @package   theme_mb2nl
 * @copyright 2017 - 2026 Mariusz Boloz (lmsstyle.com)
 * @license   PHP and HTML: http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later. Other parts: http://themeforest.net/licenses
 *
 */

defined('MOODLE_INTERNAL') || die();

global $OUTPUT, $PAGE, $COURSE, $DB, $USER;

// [L3M-OBJECTIFS] Injecter la box "Ce que vous allez apprendre" sur les pages de cours
if (strpos($PAGE->pagetype, 'course-view') === 0 && !empty($COURSE->id)) {
    $l3m_ctx = context_course::instance($COURSE->id);
    $l3m_is_student = !has_capability('moodle/course:viewhiddencourses', $l3m_ctx);
    if ($l3m_is_student) {
        $l3m_field = $DB->get_record('customfield_field', ['shortname' => 'objectifs']);
        if ($l3m_field) {
            $l3m_data = $DB->get_record('customfield_data', ['fieldid' => $l3m_field->id, 'instanceid' => $COURSE->id]);
            if ($l3m_data && !empty(trim(strip_tags($l3m_data->value)))) {
                preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $l3m_data->value, $l3m_matches);
                $l3m_items = $l3m_matches[1] ?? [];
                if (!empty($l3m_items)) {
                    $l3m_mid = ceil(count($l3m_items) / 2);
                    $l3m_col1 = array_slice($l3m_items, 0, $l3m_mid);
                    $l3m_col2 = array_slice($l3m_items, $l3m_mid);
                    $l3m_render = function($col) {
                        $o = '<ul class="l3m-obj-list">';
                        foreach ($col as $item) { $o .= '<li>' . strip_tags($item) . '</li>'; }
                        return $o . '</ul>';
                    };
                    $l3m_box  = '<div class="l3m-objectifs">';
                    $l3m_box .= '<h3 class="l3m-obj-title">Ce que vous allez apprendre</h3>';
                    $l3m_box .= '<div class="l3m-obj-grid">';
                    $l3m_box .= '<div class="l3m-obj-col">' . $l3m_render($l3m_col1) . '</div>';
                    if (!empty($l3m_col2)) {
                        $l3m_box .= '<div class="l3m-obj-col">' . $l3m_render($l3m_col2) . '</div>';
                    }
                    $l3m_box .= '</div></div>';
                    // Inject via JS since we're in the footer
                    $l3m_box_json = json_encode($l3m_box);
                    $l3m_obj_script = '<script>
(function(){
    function injectObjectifs() {
        if (document.getElementById("l3m-objectifs-box")) return;
        var target = document.getElementById("page-content") || document.getElementById("region-main");
        if (!target) return;
        var box = document.createElement("div");
        box.id = "l3m-objectifs-box";
        box.innerHTML = ' . $l3m_box_json . ';
        target.insertBefore(box, target.firstChild);
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", injectObjectifs);
    } else {
        injectObjectifs();
    }
    setTimeout(injectObjectifs, 500);
})();
</script>';
                }
            }
        }
    }
}
// [/L3M-OBJECTIFS]
$l3m_obj_script = $l3m_obj_script ?? '';

$footerid = theme_mb2nl_footerid();
$socilatt = theme_mb2nl_theme_setting($PAGE, 'socialtt') == 1 ? 'top' : '';
$footthemecontent = theme_mb2nl_theme_setting($PAGE, 'foottext');
$footcontent = theme_mb2nl_format_txt($footthemecontent, FORMAT_HTML);
$isdark = theme_mb2nl_theme_setting($PAGE, 'footerstyle') === 'dark' && ! $footerid ? ' dark1' : '';
$partnerlogos = theme_mb2nl_get_footer_images();
$parnerlinks = theme_mb2nl_line_content(theme_mb2nl_theme_setting($PAGE, 'partnerslinks'));
$footercss = $footerid ? 'custom-footer' : 'theme-footer';
$footercss .= $isdark;

$html = '';
$html .= $l3m_obj_script;

$html .= '</div><!-- //end #page-b -->';
$html .= '</div><!--  end .pagelayout-content -->';
$html .= '<div class="page-c">';

if (count($partnerlogos) && ! $footerid) {
    $html .= '<div class="partners">';
    $html .= '<div class="container-fluid">';
    $html .= '<div class="row">';
    $html .= '<div class="col-md-12">';
    $html .= '<div class="partner-images">';

    foreach ($partnerlogos as $k => $logo) {
        $isk = $k + 1;
        $alttext = isset($parnerlinks[$isk]['text']) ? $parnerlinks[$isk]['text'] : '';
        $link = isset($parnerlinks[$isk]['url']) ? $parnerlinks[$isk]['url'] : '';
        $target = (isset($parnerlinks[$isk]['url_target']) && $parnerlinks[$isk]['url_target']) ? ' target="_blank"' : '';

        if ($link) {
            $html .= '<a href="' . $link . '"' . $target . '>';
        }

            $html .= '<img src="' . $logo . '" alt="' . $alttext . '">';

        if ($link) {
            $html .= '</a>';
        }
    }

    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
}

$html .= '<footer id="footer" class="' . $footercss . ' main-footer">';

if ($footerid) {
    $html .= theme_mb2nl_format_txt('[mb2footer footerid="' . $footerid . '"]', FORMAT_HTML);
} else {
    $html .= '<div class="container-fluid">';
    $html .= '<div class="row">';
    $html .= '<div class="col-md-12">';
    $html .= '<div class="footer-content flexcols">';
    $html .= '<div class="footer-text">';
    $html .= '<p>' . $footcontent . '</p>';
    $html .= theme_mb2nl_language_list('footer');
    $html .= '</div>';

    if (theme_mb2nl_theme_setting($PAGE, 'socialfooter') == 1) {
        $html .= '<div class="footer-social">';
        $html .= theme_mb2nl_social_icons(['tt' => $socilatt, 'pos' => 'footer']);
        $html .= '</div>';
    }

    $html .= '</div>';
    $html .= $OUTPUT->theme_part('footer_tools');

    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
}

$html .= '</footer>';
$html .= '</div><!-- //end #page-c -->';
$html .= $OUTPUT->theme_part('region_adminblock');
$html .= '</div><!--  end pagelayout-b -->';
$html .= '</div><!--  end .pagelayout -->';
$html .= '</div><!-- end #page-outer -->';
$html .= theme_mb2nl_iconnav();

if (theme_mb2nl_panel_link('fixedbar') || theme_mb2nl_show_hide_sidebars($vars)) {
    $html .= '<div class="fixed-bar">';
    $html .= theme_mb2nl_panel_link('fixedbar');
    $html .= theme_mb2nl_show_hide_sidebars($vars);
    $html .= '</div>';
}

$html .= theme_mb2nl_scrolltt();
$html .= '<a href="#page" class="sr-only sr-only-focusable">' . get_string('scrolltt', 'theme_mb2nl') . '</a>';
$html .= $OUTPUT->theme_part('course_panel');

if (theme_mb2nl_theme_setting($PAGE, 'bookmarks') && isloggedin() && !isguestuser()) {
    $html .= theme_mb2nl_user_bookmarks_modal();
}

if (theme_mb2nl_is_header_tools_modal()) {
    $html .= theme_mb2nl_modal_tmpl('login');
    $html .= theme_mb2nl_modal_tmpl('search');
}

$html .= theme_mb2nl_note_moadalform();
$html .= theme_mb2nl_ganalytics();
// [L3M-TOC-SCROLL]
if (theme_mb2nl_is_course()) {
    $html .= '<script src="' . $CFG->wwwroot . '/theme/mb2nl/assets/js/l3m_toc_scroll.js?v=' . filemtime($CFG->dirroot . '/theme/mb2nl/assets/js/l3m_toc_scroll.js') . '"></script>';
}
// [/L3M-TOC-SCROLL]

// [L3M-FAQ-ACCORDION]
$html .= '<script>
document.addEventListener("DOMContentLoaded", function() {
    function initFaq() {
        var questions = document.querySelectorAll(".l3m-faq-question");
        if (!questions.length) { setTimeout(initFaq, 300); return; }
        questions.forEach(function(q) {
            q.addEventListener("click", function() {
                var item = this.closest(".l3m-faq-item");
                var wasOpen = item.classList.contains("open");
                document.querySelectorAll(".l3m-faq-item").forEach(function(i) {
                    i.classList.remove("open");
                });
                if (!wasOpen) item.classList.add("open");
            });
        });
    }
    initFaq();
});
</script>';
// [/L3M-FAQ-ACCORDION]
// [L3M-ATTENDANCE-WIDTH]
if (strpos($_SERVER['REQUEST_URI'] ?? '', '/mod/attendance/') !== false) {
    $html .= '<style>
        .container-fluid, .container-sm, .container-md, .container-lg, .container-xl, .container-xxl {
            max-width: 100% !important;
        }
    </style>';
}
// [/L3M-ATTENDANCE-WIDTH]

// [L3M] Hide forum/attendance controls when page is loaded inside iframe
$html .= '<script>(function(){
    if(window.self===window.top)return;
    var SEL=[
        "div[data-container=\"discussion-tools\"]",
        "form#mode",
        "div.singleselect.d-inline-block",
        ".d-flex.flex-column.flex-sm-row.mb-1",
        "a[data-region=\"post-action\"][title=\"Lien permanent vers ce message\"]",
        "span.attbtn:not(:first-child)","span.attcurbtn:not(:first-child)",
        ".discussioncontrols"
    ];
    function hideIframeExtras(){
        SEL.forEach(function(s){
            document.querySelectorAll(s).forEach(function(el){
                var t=el.textContent.trim().toLowerCase();
                if(s.indexOf("attbtn")!==-1||s.indexOf("attcurbtn")!==-1){
                    if(t!=="tout")el.style.cssText="display:none!important";
                } else {
                    el.style.cssText="display:none!important";
                }
            });
        });
        document.querySelectorAll("a").forEach(function(a){
            if((a.textContent||"").trim()==="Permalien")a.style.cssText="display:none!important";
        });
        document.querySelectorAll("button,a.btn,.btn").forEach(function(b){
            if((b.textContent||"").trim()==="Réglages"){
                (b.closest(".dropdown,.btn-group,.ms-auto")||b).style.cssText="display:none!important";
            }
        });
    }
    new MutationObserver(hideIframeExtras).observe(document.body,{childList:true,subtree:true});
    if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",hideIframeExtras);}
    else{hideIframeExtras();}
    setTimeout(hideIframeExtras,800);
    setTimeout(hideIframeExtras,2000);
})();</script>';

// [L3M] CSS + JS global
$html .= '<style>
#acsb-menu_launcher,.acsb-trigger,#acsb-menu { display:none!important; }
#fitem_id_allowupdatestatus { display:none!important; }
/* Evite que l image de fond du header intercepte les clics sur le bouton d edition */
.page-header-img { pointer-events:none!important; }
/* Le theme desactive tous les liens dans #page-header — reetablir pour le bouton edition */
#page-header a.theme-turnediting { pointer-events:auto!important; cursor:pointer!important; }
</style>';
$html .= '<script>(function(){
    function hideStatusbar(){
        document.querySelectorAll(".tox-statusbar").forEach(function(el){
            el.style.cssText="display:none!important";
        });
    }
    new MutationObserver(hideStatusbar).observe(document.documentElement,{childList:true,subtree:true});
    hideStatusbar();
})();</script>';
// [L3M] Hide "Avancé" / "Advanced" button near form submit buttons
// This button is generated by forum/post_form.php via get_string('showadvancededitor')
// It renders as a submit element with class "btn-link" and text "Avancé" (fr) / "Advanced" (en)
$html .= '<script>(function(){
    function hideAvance(){
        document.querySelectorAll("input[type=submit],input[type=button],button,.btn,a.btn").forEach(function(el){
            var t = el.value || el.textContent || "";
            if(t.trim()==="Avanc\u00e9"||t.trim()==="Advanced"){
                el.style.cssText="display:none!important";
            }
        });
    }
    new MutationObserver(hideAvance).observe(document.body,{childList:true,subtree:true});
    hideAvance();
})();</script>';
$html .= $OUTPUT->standard_end_of_body_html();


// [L3M] Redirection formateur : dashboard → Espace formateur
global $PAGE, $USER, $DB;
if (isloggedin() && !isguestuser() && in_array($PAGE->pagetype, ['my-index', 'my-courses'])) {
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    $isteacher = $teacherroleid && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $teacherroleid]);
    if ($isteacher) {
        $html .= '<script>window.location.replace("/mod/page/view.php?id=169");</script>';
    }
}


// [L3M] Page journal activités – nettoyage complet vue formateur
$html .= '<script>(function(){
if(window.location.pathname.indexOf("/report/log")===-1)return;
var HIDE_COLS=["Origine","Adresse IP","Description","Nom de l\'événement","Utilisateur concerné"];
function cleanLog(){
    // 1. Navigation "Journaux" dropdown
    var nav=document.querySelector(".tertiary-navigation,.urlselect");
    if(nav)nav.style.setProperty("display","none","important");
    // 2. Filtres inutiles — garder id (cours), user (participants), date (jours)
    document.querySelectorAll("select[name]").forEach(function(s){
        var n=s.getAttribute("name")||"";
        if(n!=="id"&&n!=="user"&&n!=="date"){
            var p=s.closest(".fitem,.form-group,span");
            if(p)p.style.setProperty("display","none","important");
            else s.style.setProperty("display","none","important");
        }
    });
    // 3. Icône aide "?"
    document.querySelectorAll(".helptooltip").forEach(function(el){el.style.setProperty("display","none","important");});
    // 4. Bouton "Consulter ces journaux"
    document.querySelectorAll("input[type=submit],button[type=submit]").forEach(function(b){
        if((b.value||b.textContent||"").toLowerCase().indexOf("journal")>-1)
            b.style.setProperty("display","none","important");
    });
    // 5. Colonnes inutiles (par texte d\'en-tête)
    document.querySelectorAll("table").forEach(function(tbl){
        var ths=Array.from(tbl.querySelectorAll("thead th,tr:first-child th"));
        ths.forEach(function(th,i){
            var txt=th.textContent.trim();
            if(HIDE_COLS.some(function(h){return txt===h||txt.indexOf(h)===0;})){
                tbl.querySelectorAll("tr").forEach(function(tr){
                    var cell=tr.querySelectorAll("th,td")[i];
                    if(cell)cell.style.setProperty("display","none","important");
                });
            }
        });
    });
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",cleanLog);
else cleanLog();
setTimeout(cleanLog,600);
})();</script>';


// [L3M] Page achèvement cours – nettoyage vue formateur
if (isloggedin() && !isguestuser() && strpos($PAGE->url->out(), 'report/completion') !== false) {
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    $isteacher = $teacherroleid && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $teacherroleid]);
    if ($isteacher) {
        $html .= '<style>
/* Filtre alphabétique A-Z */
.path-report-completion #completion-progress div.firstnames,
.path-report-completion #completion-progress div.lastnames,
.path-report-completion .firstinitial,
.path-report-completion .lastinitial { display:none!important; }
/* Dropdown Achèvement de cours */
.path-report-completion .tertiary-navigation select,
.path-report-completion .tertiary-navigation .singleselect { display:none!important; }
/* Liens CSV */
.path-report-completion p.completionlink,
.path-report-completion .download-completion { display:none!important; }
/* Rotation colonnes : forcer lisibilité */
.path-report-completion .completion-progresscell { white-space:nowrap!important; min-width:80px!important; }
</style>';
    }
}


// [L3M] Page profil apprenant – nettoyage vue formateur
if (isloggedin() && !isguestuser() && strpos($PAGE->pagetype, 'user-profile') !== false) {
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    $isteacher = $teacherroleid && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $teacherroleid]);
    if ($isteacher) {
        $html .= '<style>
/* Administration section vide */
.path-user [data-key="administration"],
.path-user #region-main .card:has(h3:empty),
.path-user .profile_tree section.node_category:empty { display:none!important; }
/* Message personnel + Demande de contact */
.path-user [data-action="message-user"],
.path-user [data-action="add-contact"],
.path-user .contact-request,
.path-user a[href*="message/index.php?user"],
.path-user a[href*="user/action_redir.php"] { display:none!important; }
/* Pays + Fuseau horaire */
.path-user dl dt:has(+dd:-moodle-empty) ~ dd,
.path-user [data-key="country"],
.path-user [data-key="timezone"] { display:none!important; }
</style>';
        $html .= '<script>(function(){
function cleanProfile(){
    // Masquer "Message personnel" et "Demande de contact"
    document.querySelectorAll("a,button").forEach(function(el){
        var t=el.textContent.trim();
        if(t==="Message personnel"||t.indexOf("contact")>-1||t.indexOf("Contact")>-1||
           t.indexOf("Demande")>-1){
            var wrap=el.closest(".card-body > a, .btn-group, li")||el;
            wrap.style.setProperty("display","none","important");
        }
    });
    // Masquer section Administration (vide)
    document.querySelectorAll("h3,h4").forEach(function(h){
        if(h.textContent.trim()==="Administration"){
            var card=h.closest(".card,.profile_tree section");
            if(card) card.style.setProperty("display","none","important");
        }
    });
    // Masquer Pays et Fuseau horaire
    document.querySelectorAll("dt").forEach(function(dt){
        var t=dt.textContent.trim();
        if(t==="Pays"||t==="Fuseau horaire"){
            var dd=dt.nextElementSibling;
            dt.style.display="none";
            if(dd) dd.style.display="none";
        }
    });
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",cleanProfile);
else cleanProfile();
})();</script>';
    }
}


// [L3M] Masquer "Journal du site" du tableau de bord formateur
$html .= '<script>(function(){
    document.querySelectorAll("a").forEach(function(a){
        if(a.textContent.trim().indexOf("Journal du site")>-1){
            var wrap=a.closest("div,td,li,p")||a.parentElement;
            if(wrap) wrap.style.setProperty("display","none","important");
            else a.style.setProperty("display","none","important");
        }
    });
})();</script>';


// [L3M] Vue d'ensemble notes formateur – masquer "Les cours que je suis"
global $PAGE, $USER, $DB;
if (isloggedin() && !isguestuser() && strpos($PAGE->pagetype, 'grade-report-overview') !== false) {
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    $isteacher = $teacherroleid && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $teacherroleid]);
    if ($isteacher) {
        $html .= '<script>(function(){
function hideStudentSection(){
    document.querySelectorAll("h2,h3,h4").forEach(function(h){
        var t=h.textContent.trim();
        if(t.indexOf("que je suis")>-1||t.indexOf("que je suivais")>-1){
            var section=h.closest("div,section")||h.parentElement;
            // cacher le titre + le tableau qui suit
            var next=h.nextElementSibling;
            h.style.display="none";
            while(next&&next.tagName!=="H2"&&next.tagName!=="H3"){
                next.style.display="none";
                next=next.nextElementSibling;
            }
        }
    });
    // Rendre liens "Les cours que je donne" non-cliquables
    document.querySelectorAll(".grade-overview-grade table a").forEach(function(a){
        a.style.setProperty("pointer-events","none","important");
        a.style.setProperty("color","inherit","important");
        a.style.setProperty("text-decoration","none","important");
    });
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",hideStudentSection);
else hideStudentSection();
})();</script>';
    }
}


// [L3M] Tableau rapport quiz – scroll horizontal toutes colonnes
$html .= '<script>(function(){
function fixQuizTable(){
    document.querySelectorAll("table.flexible,table.generaltable,#tablecontainer table").forEach(function(tbl){
        if(tbl.closest(".l3m-scroll-wrap"))return;
        var wrap=document.createElement("div");
        wrap.className="l3m-scroll-wrap";
        wrap.style.cssText="overflow-x:auto!important;width:100%!important;display:block!important;-webkit-overflow-scrolling:touch";
        tbl.parentNode.insertBefore(wrap,tbl);
        wrap.appendChild(tbl);
        tbl.style.setProperty("min-width","max-content","important");
    });
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",fixQuizTable);
else fixQuizTable();
var obsQ=new MutationObserver(fixQuizTable);
obsQ.observe(document.body,{childList:true,subtree:true});
})();</script>';

// [L3M] Page notifications – masquer navbar, unsubscribelink et liens inutiles
$html .= '<script>(function(){
function cleanNotifPage(){
    // Icône engrenage (préférences notifications)
    document.querySelectorAll("[data-region=\'popover-region-notifications\'] .popover-region-header-actions,a[href*=\'notificationpreferences\']").forEach(function(el){
        el.style.setProperty("display","none","important");
    });
    // Breadcrumb forum (.navbar) et liens désabonnement (.unsubscribelink)
    document.querySelectorAll(".navbar,.unsubscribelink").forEach(function(el){
        el.style.setProperty("display","none","important");
    });
    // HR séparateur avant les liens
    document.querySelectorAll(".unsubscribelink ~ hr, hr + .unsubscribelink").forEach(function(el){
        el.style.setProperty("display","none","important");
    });
    // Lien "Aller à" et "Voir ce message" dans le body de notification
    document.querySelectorAll("a").forEach(function(a){
        var t=a.textContent.trim();
        if(t.indexOf("Aller à")===0||t.indexOf("Voir ce message")===0||
           t.indexOf("préférences")>-1||t.indexOf("digest")>-1||
           t.indexOf("unsubscribe")>-1){
            var p=a.closest("p,div.mdl-align,td");
            (p||a).style.setProperty("display","none","important");
        }
    });
    // HR orphelins dans la zone notif
    document.querySelectorAll("[data-region] hr, .notification-message-body hr").forEach(function(hr){
        hr.style.setProperty("display","none","important");
    });
}
cleanNotifPage();
var obs=new MutationObserver(cleanNotifPage);
obs.observe(document.body,{childList:true,subtree:true});
})();</script>';


// [L3M] Alignement barre de progression — min-height sur les titres
$html .= '<script>(function(){
function styleCard(item){
    var title=item.querySelector(".title");
    if(title) title.style.setProperty("min-height","4.2rem","important");
}
function alignAll(){document.querySelectorAll(".theme-course-item").forEach(styleCard);}
alignAll();
var obs=new MutationObserver(function(muts){
    muts.forEach(function(m){
        m.addedNodes.forEach(function(n){
            if(n.nodeType!==1)return;
            if(n.classList&&n.classList.contains("theme-course-item"))styleCard(n);
            n.querySelectorAll&&n.querySelectorAll(".theme-course-item").forEach(styleCard);
        });
    });
});
obs.observe(document.body,{childList:true,subtree:true});
})();</script>';


// [L3M] Hero background image + formateur hero selector fix — applied via JS
if (in_array($PAGE->pagetype, ['site-index'])) {
    $html .= '<script>(function(){
function applyHeroBg(){
    // Find the hero section — first .mb2-pb-fpsection in the page
    var hero=null;
    var candidates=document.querySelectorAll(".mb2-pb-fpsection");
    if(candidates.length) hero=candidates[0];
    if(!hero){
        candidates=document.querySelectorAll(".mb2-pb-section");
        if(candidates.length) hero=candidates[0];
    }
    if(!hero) return;

    // Store reference globally so teacher JS can find it
    window.L3M_HERO=hero;

    // Apply background image on the section-inner (which carries the bgcolor)
    var inner=hero.querySelector(".mb2-pb-section-inner,.section-inner");
    if(inner){
        inner.style.setProperty("background-image","url(\"/theme/mb2nl/pix/hero_bg.jpg\")","important");
        inner.style.setProperty("background-size","cover","important");
        inner.style.setProperty("background-position","center center","important");
        inner.style.setProperty("background-repeat","no-repeat","important");
        inner.style.setProperty("background-color","#0A1628","important");
    }
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",applyHeroBg);
else applyHeroBg();
})();</script>';
}


// [L3M] Espace formateur : hero personnalisé + overlays stats sur cartes cours
global $DB, $USER, $PAGE;
if (isloggedin() && !isguestuser()) {
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    $isteacher = $teacherroleid && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $teacherroleid]);
    if ($isteacher && in_array($PAGE->pagetype, ['site-index', 'course-index'])) {
        // Courses this teacher is assigned to
        $sql = 'SELECT DISTINCT c.id, c.fullname, c.shortname
                FROM {course} c
                JOIN {context} cx ON cx.instanceid = c.id AND cx.contextlevel = :ctx
                JOIN {role_assignments} ra ON ra.contextid = cx.id
                WHERE ra.userid = :uid AND ra.roleid = :rid AND c.id > 1
                ORDER BY c.id';
        $teachercourses = $DB->get_records_sql($sql, [
            'ctx' => CONTEXT_COURSE, 'uid' => $USER->id, 'rid' => $teacherroleid,
        ]);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        // Count DISTINCT students across all teacher's courses (not sum per course)
        $courseids = array_keys((array)$teachercourses);
        $totalstudents = 0;
        if (!empty($courseids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
            $totalstudents = (int)$DB->get_field_sql(
                "SELECT COUNT(DISTINCT ra.userid)
                 FROM {role_assignments} ra
                 JOIN {context} cx ON cx.id = ra.contextid AND cx.contextlevel = :ctxlevel
                 WHERE cx.instanceid $insql AND ra.roleid = :srid",
                array_merge(['ctxlevel' => CONTEXT_COURSE, 'srid' => $studentroleid], $inparams)
            );
        }
        $coursedata = [];
        $bbbmodid = $DB->get_field('modules', 'id', ['name' => 'bigbluebuttonbn']);
        foreach ($teachercourses as $c) {
            // Enrolled students per course
            $ctx = context_course::instance($c->id);
            $enrolled = $DB->count_records_sql(
                'SELECT COUNT(DISTINCT ra.userid) FROM {role_assignments} ra WHERE ra.contextid = :cid AND ra.roleid = :rid',
                ['cid' => $ctx->id, 'rid' => $studentroleid]
            );
            // Average completion % — count distinct (userid, moduleaid) pairs
            $total_activities = $DB->count_records_sql(
                'SELECT COUNT(cm.id) FROM {course_modules} cm WHERE cm.course = :cid AND cm.completion > 0',
                ['cid' => $c->id]
            );
            $avgpct = 0;
            if ($enrolled > 0 && $total_activities > 0) {
                $completed = (int)$DB->get_field_sql(
                    'SELECT COUNT(DISTINCT cmc.userid, cmc.coursemoduleid)
                     FROM {course_modules_completion} cmc
                     JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                     JOIN {role_assignments} ra ON ra.userid = cmc.userid AND ra.contextid = :ctxid AND ra.roleid = :srid
                     WHERE cm.course = :cid AND cmc.completionstate >= 1',
                    ['cid' => $c->id, 'ctxid' => $ctx->id, 'srid' => $studentroleid]
                );
                $avgpct = min(100, round(($completed / ($enrolled * $total_activities)) * 100));
            }
            // BBB activity link
            $bbblink = '';
            if ($bbbmodid) {
                $cmid = $DB->get_field_sql(
                    'SELECT cm.id FROM {course_modules} cm WHERE cm.course = :cid AND cm.module = :mid LIMIT 1',
                    ['cid' => $c->id, 'mid' => $bbbmodid]
                );
                if ($cmid) {
                    $bbblink = '/mod/bigbluebuttonbn/view.php?id=' . $cmid;
                }
            }
            $coursedata[$c->id] = [
                'enrolled' => $enrolled,
                'avg'      => $avgpct,
                'bbb'      => $bbblink,
            ];
        }
        $firstname = s($USER->firstname);
        $ncourses = count($teachercourses);
        $datajs = json_encode($coursedata);
        $html .= <<<HTML
<script>(function(){
var path=window.location.pathname;
if(path!=="/"&&path!=="/index.php"&&path.indexOf("/?")!==0)return;

// 1. Hero formateur : remplace tout le contenu du hero
var hero=window.L3M_HERO||document.querySelector('.mb2-pb-fpsection')||document.querySelector('.mb2-pb-section');
if(hero){
  var scope=hero.querySelector('.mb2-pb-sortable-rows,.mb2-pb-section-inner,.section-inner')||hero;
  scope.innerHTML='<div style="text-align:center;padding:0 24px;display:flex;flex-direction:column;align-items:center;gap:20px">'
    +'<h1 style="color:#C9A227;font-size:2.6em;font-weight:700;margin:0;line-height:1.2">'
    +'Bonjour {$firstname}&nbsp;👋'
    +'<br><span style="font-size:0.48em;font-weight:400;color:#fff;opacity:0.85">'
    +'{$ncourses} formations &nbsp;&bull;&nbsp; {$totalstudents} étudiants inscrits'
    +'</span></h1>'
    +'<p style="color:#ddd;font-size:1.05em;margin:0;max-width:600px">'
    +'Retrouvez vos cours, suivez la progression de vos étudiants et accédez à vos classes virtuelles.'
    +'</p>'
    +'<a href="/course/index.php" style="background:#C9A227;color:#111;font-weight:700;font-size:1em;'
    +'padding:12px 32px;border-radius:30px;text-decoration:none;margin-top:4px">'
    +'Gérer mes cours</a>'
    +'</div>';
}

// 2. Overlays stats — titre sous la carte caché (déjà dans le thumbnail)
var data={$datajs};
function addOverlay(card,cid){
  var d=data[cid];if(!d)return;
  if(card.querySelector('.l3m-teacher-overlay'))return;
  // Cacher le titre texte sous la carte — tout est déjà dans le thumbnail
  var titleWrap=card.querySelector('.title,.course-title,h2,h3,h4,h5');
  if(titleWrap) titleWrap.style.display='none';
  var details=card.querySelector('.course-item-deatils,.course-details,.course-meta');
  if(details) details.style.display='none';
  // Overlay stats
  var ov=document.createElement('div');
  ov.className='l3m-teacher-overlay';
  ov.style.cssText='position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.85));padding:8px 12px 10px;display:flex;align-items:center;justify-content:space-between;gap:6px;z-index:5;';
  var stats='<span style="color:#fff;font-size:11.5px;line-height:1.5;display:flex;flex-direction:column;gap:1px">'+
    '<span>👥 '+d.enrolled+' étudiant'+(d.enrolled>1?'s':'')+'</span>'+
    '<span style="color:#C9A227">📊 '+d.avg+'% progression moy.</span>'+
    '</span>';
  var btn=d.bbb?'<a href="'+d.bbb+'" style="background:#C9A227;color:#111;font-size:11px;font-weight:700;padding:5px 9px;border-radius:20px;text-decoration:none;white-space:nowrap;flex-shrink:0;line-height:1.2">▶ Classe<br>virtuelle</a>':'';
  ov.innerHTML=stats+btn;
  var wrap=card.querySelector('.image-wrap');
  if(wrap){wrap.style.position='relative';wrap.appendChild(ov);}
}
function scanCards(){
  Object.keys(data).forEach(function(cid){
    var card=document.querySelector('.course-'+cid);
    if(card)addOverlay(card,parseInt(cid));
  });
}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',scanCards);}
else{scanCards();}
setTimeout(scanCards,800);
})();</script>
HTML;
    }
}



// [L3M] Hide mycourse badge for teachers/admins, keep for students
global $DB, $USER;
if (isloggedin() && !isguestuser()) {
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    if (is_siteadmin() || ($teacherroleid && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $teacherroleid]))) {
        $html .= '<style>.badge-mycourse{display:none!important}</style>';
    }
}

// [L3M] Hide various UI elements
$html .= '<script>(function(){
    function hideElements(){
        var sel=[
            ".item-courses",".item-parts",".item-addpage",
            ".calendar-controls a[href*=\'import\']",
            "a[href*=\'/calendar/import\']",
            "a[href*=\'action=import\']",
            ".calendarwrapper a[href*=\'import\']",
            "#page-calendar-view [data-action=\'new-event-button\']",
            "#page-calendar-view .newevent",
            "[data-action=\'new-event-button\']",
            ".calendar-event-filters",
            ".calendar_filters",
            "#page-calendar-view .block_calendar_month .generalbox"
        ];
        sel.forEach(function(s){
            document.querySelectorAll(s).forEach(function(el){
                el.style.cssText="display:none!important";
            });
        });
        var isTeacher=document.body.classList.contains("roleshortname-editingteacher")||document.body.classList.contains("roleshortname-teacher");
        // Cacher les liens course/edit seulement pour les \u00e9tudiants (pas les formateurs)
        if(!isTeacher){
            document.querySelectorAll("a[href*=\'/course/edit.php\']").forEach(function(a){(a.closest("li")||a.parentElement||a).style.cssText="display:none!important";});
        }
        document.querySelectorAll("a[href*=\'/contentbank/\']").forEach(function(a){(a.closest("li")||a.parentElement||a).style.cssText="display:none!important";});
        document.querySelectorAll("a").forEach(function(a){
            var t=a.textContent.trim();
            // "Modifier les param\u00e8tres du cours" visible pour le formateur, cach\u00e9 pour les \u00e9tudiants
            if(t==="Importer ou exporter des calendriers"||t==="Nouvel \u00e9v\u00e9nement"||(t==="Modifier les param\u00e8tres du cours"&&!isTeacher)){
                (a.closest("li")||a.parentElement||a).style.cssText="display:none!important";
            }
        });
        document.querySelectorAll("h3,h4,h5,div").forEach(function(el){
            if(el.textContent.trim()==="L\u00e9gende"){
                var p=el.closest(".block,.card,.generalbox");
                if(p)p.style.cssText="display:none!important";
                else el.parentElement.style.cssText="display:none!important";
            }
        });
        document.querySelectorAll("button").forEach(function(b){
            if(b.textContent.trim()==="Nouvel \u00e9v\u00e9nement"){
                b.closest("div")?b.closest("div").style.cssText="display:none!important":b.style.cssText="display:none!important";
            }
        });
        document.querySelectorAll("[data-action=\'toggle-manual-completion\'],form.togglecompletion button,.btn-completioncheckbox").forEach(function(el){
            el.style.cssText="display:none!important";
        });
        document.querySelectorAll("a,span,.instancename").forEach(function(el){
            if(el.textContent.trim()==="Nouvelles diverses et annonces"){
                (el.closest("li.activity,.activity-item,.section-item")||el.parentElement||el).style.cssText="display:none!important";
            }
        });
    }
    // Rename "Tableau de bord" → "Mon apprentissage" via TreeWalker
    function renameDashboard(){
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
        var node;
        while((node = walker.nextNode())){
            if(node.nodeValue && node.nodeValue.trim() === "Tableau de bord"){
                node.nodeValue = "Mon apprentissage";
            }
        }
        // Also page title
        if(document.title.indexOf("Tableau de bord") !== -1){
            document.title = document.title.replace("Tableau de bord","Mon apprentissage");
        }
    }
    if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){hideElements();renameDashboard();});}
    else{hideElements();renameDashboard();}
    new MutationObserver(function(){hideElements();renameDashboard();}).observe(document.body,{childList:true,subtree:true});
})();</script>';

// [L3M] Hide quiz report - garder seulement le tableau des tentatives
$html .= '<script>(function(){
    if(window.location.pathname.indexOf("/mod/quiz/report.php")===-1)return;
    function hideQuizExtras(){
        // Filtres A-Z, compteur tentatives, téléchargement, navigation rapport, filtre groupe
        ["form.mform","form.dataformatselector","div.initialbar","div.initialbars",
         ".quizattemptcounts",".downloadoptions",
         ".groupselector",".singleselect",
         "#page-mod-quiz-report .tertiary-navigation",
         "#page-mod-quiz-report .selectgroup",
         "#page-mod-quiz-report .urlselect"
        ].forEach(function(s){
            document.querySelectorAll(s).forEach(function(el){el.style.cssText="display:none!important";});
        });
        // Sélecteur de type de rapport (Notes, Aperçu...) = premier select orphelin
        document.querySelectorAll("select").forEach(function(sel){
            if(!sel.closest("table")&&!sel.closest("form.dataformatselector")){
                (sel.closest("div.singleselect,div.urlselect,.form-group")||sel.parentElement||sel).style.cssText="display:none!important";
            }
        });
        // Lien "Réinitialiser les préférences du tableau" + boutons Supprimer/Recalculer
        document.querySelectorAll("a").forEach(function(a){
            if(a.textContent.indexOf("éinitialiser")!==-1)a.style.cssText="display:none!important";
        });
        document.querySelectorAll("button,input[type=submit]").forEach(function(b){
            var t=b.textContent.trim()||b.value||"";
            if(t.indexOf("upprimer")!==-1||t.indexOf("ecalculer")!==-1){
                (b.closest("div.quizattemptcounts,div.mdl-align,.que")||b.parentElement||b).style.cssText="display:none!important";
            }
        });
        // Graphique nombre total participants (masquer seulement le h3 + ses frères suivants canvas/img/div)
        document.querySelectorAll("h3,h4").forEach(function(h){
            var t=h.textContent.toLowerCase();
            if(t.indexOf("intervalle")!==-1||t.indexOf("nombre total")!==-1){
                h.style.cssText="display:none!important";
                var el=h.nextElementSibling;
                while(el&&el.tagName!=="TABLE"){el.style.cssText="display:none!important";el=el.nextElementSibling;}
            }
        });
    }
    if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",hideQuizExtras);}
    else{hideQuizExtras();}
    new MutationObserver(function(){hideQuizExtras();}).observe(document.body,{childList:true,subtree:true});
})();</script>';

// [L3M] Block calendar event creation for students + block redirect to create/edit page
$html .= '<script>(function(){
    var isStudent=document.body.classList.contains("roleshortname-student");
    if(!isStudent)return;
    document.addEventListener("click",function(e){
        var a=e.target.closest("a[href]");
        if(a){
            var h=a.href||"";
            if((h.indexOf("/calendar/event.php")!==-1&&(h.indexOf("action=new")!==-1||h.indexOf("action=edit")!==-1))||
               h.indexOf("/calendar/export")!==-1){
                e.preventDefault();e.stopPropagation();return;
            }
        }
        var btn=e.target.closest("button,[data-action]");
        if(btn){
            var act=btn.getAttribute("data-action")||"";
            if(act==="new-event-button"||act==="edit-event"||act==="delete-event"){
                e.preventDefault();e.stopPropagation();return;
            }
        }
    },true);
})();</script>';

// [L3M] Hide profile page blocks (Divers, Rapports, Protection)
$html .= '<script>(function(){
    var path=window.location.pathname;
    if(path.indexOf("/user/profile.php")===-1&&path.indexOf("/user/view.php")===-1)return;
    function hideProfileBlocks(){
        var HIDE=["divers","rapports","protection des donnees et politiques","protection des donnees"];
        document.querySelectorAll("section,aside,.card,.block").forEach(function(sec){
            var h=sec.querySelector("h2,h3,h4,.card-header,.block-header");
            if(!h)return;
            var t=h.textContent.trim().toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g,"");
            HIDE.forEach(function(k){if(t===k||t.startsWith(k))sec.style.cssText="display:none!important";});
        });
    }
    if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",hideProfileBlocks);}
    else{hideProfileBlocks();}
})();</script>';

// [L3M] Hide user menu items: Notes, Rapports, Préférences, Prendre le rôle
$html .= '<script>(function(){
    var HIDE=["notes","rapports","preferences","prendre le role"];
    function norm(s){return s.trim().toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g,"");}
    function hideItems(root){
        (root||document).querySelectorAll("a,li").forEach(function(el){
            if(el.children.length>2)return;
            var t=norm(el.textContent);
            HIDE.forEach(function(k){
                if(t===k||t.startsWith(k)){
                    var li=el.closest("li")||el;
                    li.style.cssText="display:none!important";
                }
            });
        });
    }
    if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){hideItems();});}
    else{hideItems();}
    new MutationObserver(function(muts){
        muts.forEach(function(m){m.addedNodes.forEach(function(n){if(n.nodeType===1)hideItems(n);});});
    }).observe(document.body,{childList:true,subtree:true});
})();</script>';

// [L3M] Calendar events: afficher nom court + jj/mm/aa HH:mm
$html .= '<script>(function(){
    if(window.location.pathname.indexOf("/calendar/")===-1)return;
    function pad(n){return n<10?"0"+n:n;}
    function fmtTs(ts){
        var d=new Date(ts*1000);
        return pad(d.getDate())+"/"+pad(d.getMonth()+1)+"/"+String(d.getFullYear()).slice(-2)+" "+pad(d.getHours())+":"+pad(d.getMinutes());
    }
    var HIDE_EVENTS=["delai de annonces","delai d"];
    function norm(s){return s.trim().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g,"");}
    function processEvents(){
        // Hide unwanted calendar events
        document.querySelectorAll("li[data-region],a[data-starttime]").forEach(function(el){
            var nameEl=el.querySelector(".eventname")||el.querySelector("a")||el;
            var t=norm(nameEl.textContent);
            var li=el.tagName==="LI"?el:(el.closest("li")||el);
            HIDE_EVENTS.forEach(function(k){if(t===k||t.startsWith(k))li.style.cssText="display:none!important";});
        });
        document.querySelectorAll("a[data-starttime]").forEach(function(a){
            var nameSpan=a.querySelector(".eventname");
            var timeSpan=a.querySelector(".l3m-eventtime");
            if(!nameSpan)return;
            // Keep full name (no truncation)
            if(!nameSpan.dataset.l3mDone){
                nameSpan.dataset.l3mDone="1";
            }
            // Fill date/time
            if(timeSpan&&!timeSpan.textContent){
                var ts=parseInt(a.getAttribute("data-starttime"),10);
                if(ts)timeSpan.textContent=fmtTs(ts);
            }
        });
    }
    if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",processEvents);}
    else{processEvents();}
    new MutationObserver(function(){processEvents();}).observe(document.body,{childList:true,subtree:true});
})();</script>';

// [L3M-ATTENDANCE-BADGE] Compteur présences sur le calendrier (formateurs uniquement)
global $DB, $USER;
$showAttBadge = false;
if (isloggedin() && !isguestuser()) {
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    if (is_siteadmin() || ($teacherroleid && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $teacherroleid]))) {
        $showAttBadge = true;
    }
}
if ($showAttBadge && strpos($_SERVER['REQUEST_URI'] ?? '', '/calendar/') !== false) {
    $html .= '<style>
.l3m-att-badge{display:inline-block;margin-left:4px;padding:1px 5px;border-radius:10px;font-size:0.72em;font-weight:600;background:#e8f5e9;color:#2e7d32;white-space:nowrap;vertical-align:middle;}
.l3m-att-badge.zero{background:#fce4ec;color:#c62828;}
</style>';
    $html .= '<script>(function(){
    function addAttBadges(stats){
        document.querySelectorAll("a[data-action=\'view-event\'][data-event-id]").forEach(function(a){
            if(a.dataset.l3mAttDone)return;
            var eid=a.getAttribute("data-event-id");
            if(!stats[eid])return;
            var s=stats[eid];
            var badge=document.createElement("span");
            badge.className="l3m-att-badge"+(s.present===0?" zero":"");
            badge.textContent=s.present+"/"+s.total;
            badge.title=s.present+" présence(s) validée(s) sur "+s.total+" apprenant(s) inscrit(s)";
            a.appendChild(badge);
            a.dataset.l3mAttDone="1";
        });
    }
    fetch("/theme/mb2nl/ajax/attendance_stats.php")
        .then(function(r){return r.json();})
        .then(function(stats){
            if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){addAttBadges(stats);});}
            else{addAttBadges(stats);}
            new MutationObserver(function(){addAttBadges(stats);}).observe(document.body,{childList:true,subtree:true});
        })
        .catch(function(){});
})();</script>';
}
// [/L3M-ATTENDANCE-BADGE]

// [L3M] Replace "Rien à afficher" on customcert page
$html .= '<script>(function(){
    if(window.location.pathname.indexOf("/mod/customcert/") === -1) return;
    var msg = "Aucune attestation émise pour le moment. Les attestations apparaîtront ici une fois que les apprenants auront complété la formation.";
    function replaceRien(root){
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
        var node;
        while((node = walker.nextNode())){
            if(node.nodeValue.indexOf("Rien") !== -1 && node.nodeValue.indexOf("afficher") !== -1){
                node.nodeValue = msg;
            }
        }
    }
    if(document.readyState === "loading"){document.addEventListener("DOMContentLoaded", function(){ replaceRien(document.body); });}
    else { replaceRien(document.body); }
    setTimeout(function(){ replaceRien(document.body); }, 800);
})();</script>';

// [L3M-MON-APPRENTISSAGE] Dashboard "Mon apprentissage" enhancement
$html .= '<style>
#l3m-learning-section { padding:20px 0 8px; }
#l3m-learning-section h2 { font-family:Montserrat,sans-serif; font-weight:800; font-size:1.3rem;
  color:#1a1a1a; border-left:4px solid #C5A900; padding-left:12px; margin-bottom:16px; }
.l3m-card-resume-wrap { padding:8px 12px 10px; border-top:1px solid #f0f0f0; }
.l3m-card-resume-link { display:flex; align-items:center; gap:7px;
  background:linear-gradient(135deg,#C5A900,#a08900); color:#000 !important;
  border-radius:5px; padding:7px 12px; text-decoration:none !important;
  font-family:Montserrat,sans-serif; font-weight:700; font-size:12px;
  transition:transform .15s,box-shadow .15s; }
.l3m-card-resume-link:hover { transform:translateY(-1px); box-shadow:0 3px 10px rgba(197,169,0,.4); }
.l3m-card-resume-link .l3m-cr-label { font-size:10px; text-transform:uppercase; letter-spacing:.05em; display:block; line-height:1.2; }
.l3m-card-resume-link .l3m-cr-name { display:block; font-weight:600; font-size:12px;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px; opacity:.9; }
.l3m-card-resume-date { display:block; font-size:10px; color:#999;
  font-family:"Open Sans",sans-serif; margin-top:5px; padding-left:2px; }
</style>';

$html .= '<script>(function(){
    // Run on dashboard, my-courses, and frontpage (anywhere course cards appear)
    var body = document.body;
    var onDash = body.classList.contains("pagelayout-mydashboard") ||
                 body.classList.contains("path-my") ||
                 body.classList.contains("path-course-index") ||
                 body.classList.contains("path-site") ||
                 window.location.pathname.indexOf("/my/") !== -1 ||
                 window.location.pathname.indexOf("/course/index.php") !== -1;
    if (!onDash) return;

    function fmtDate(ts){
        var d = new Date(ts);
        return d.toLocaleDateString("fr-FR",{day:"2-digit",month:"short"}) +
               " à " + d.toLocaleTimeString("fr-FR",{hour:"2-digit",minute:"2-digit"});
    }
    function fmtTime(s){
        s = parseInt(s||0,10);
        if(!s) return null;
        var h = Math.floor(s/3600), m = Math.floor((s%3600)/60);
        if(h>0) return h+"h "+(m<10?"0":"")+m+"min";
        if(m>0) return m+" min";
        return s+" sec";
    }

    function enhanceCards(){
        document.querySelectorAll("[data-course-id]").forEach(function(card){
            if(card.dataset.l3mLearning) return;
            // Skip if a parent also has data-course-id (avoid nested duplicates)
            if(card.parentElement && card.parentElement.closest("[data-course-id]")) return;
            card.dataset.l3mLearning = "1";
            var cid = card.dataset.courseId;
            try {
                var data = JSON.parse(localStorage.getItem("l3m_toc_"+cid)||"null");
                var totalTime = fmtTime(localStorage.getItem("l3m_time_"+cid));
                var hasResume = data && data.name && data.url && (Date.now()-data.ts) < 86400000*30;
                if(!hasResume && !totalTime) return;
                var resumeHtml = hasResume
                    ? "<a href=\"/course/view.php?id="+cid+"\" class=\"l3m-card-resume-link\" title=\"Reprendre : "+data.name.replace(/"/g,"&quot;")+"\">" +
                      "<span>&#9654;</span>" +
                      "<span><span class=\"l3m-cr-label\">Reprendre</span>" +
                      "<span class=\"l3m-cr-name\">"+data.name.replace(/</g,"&lt;").replace(/>/g,"&gt;")+"</span></span>" +
                      "</a>" +
                      "<span class=\"l3m-card-resume-date\">Dernière activité : "+fmtDate(data.ts)+"</span>"
                    : "";
                var timeHtml = totalTime
                    ? "<span class=\"l3m-card-resume-date\" style=\"color:#C5A900;\">⏱ "+totalTime+" de formation</span>"
                    : "";
                var wrap = document.createElement("div");
                wrap.className = "l3m-card-resume-wrap";
                wrap.innerHTML = resumeHtml + timeHtml;
                var footer = card.querySelector(".dashboard-card-footer, .card-footer");
                var body = card.querySelector(".course-info-container, .card-body");
                var target = footer || body || card;
                target.appendChild(wrap);
            } catch(e){}
        });
    }

    if(document.readyState==="loading"){
        document.addEventListener("DOMContentLoaded",function(){ enhanceCards(); setTimeout(enhanceCards,800); });
    } else {
        enhanceCards(); setTimeout(enhanceCards,800);
    }
    // Watch for lazy-loaded cards
    var obs = new MutationObserver(enhanceCards);
    document.addEventListener("DOMContentLoaded",function(){
        obs.observe(document.body,{childList:true,subtree:true});
        setTimeout(function(){ obs.disconnect(); },5000);
    });
})();</script>';
// [/L3M-MON-APPRENTISSAGE]


// [L3M] Quiz attempt : afficher "Question X / Y"
if (strpos($PAGE->pagetype, 'mod-quiz-attempt') !== false) {
    $l3m_attemptid = optional_param('attempt', 0, PARAM_INT);
    $l3m_totalq = 0;
    if ($l3m_attemptid) {
        $l3m_att = $DB->get_record('quiz_attempts', ['id' => $l3m_attemptid], 'id,quiz');
        if ($l3m_att) {
            $l3m_totalq = $DB->count_records('quiz_slots', ['quizid' => $l3m_att->quiz]);
        }
    }
    if ($l3m_totalq > 0) {
        $l3m_tq = (int)$l3m_totalq;
        $html .= '<script>(function(){
    var totalQ=' . $l3m_tq . ';
    function updateQuizProgress(){
        // Sélecteur exact: h3.no dans .que .info, le span.qno contient le numéro
        var h3=document.querySelector(".que .info h3.no");
        if(h3 && !h3.dataset.l3mDone){
            h3.dataset.l3mDone="1";
            var sep=document.createElement("span");
            sep.textContent=" / "+totalQ;
            sep.setAttribute("aria-hidden","true");
            sep.style.cssText="font-weight:400;";
            h3.appendChild(sep);
        }
    }
    if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",updateQuizProgress);}
    else{updateQuizProgress();}
    setTimeout(updateQuizProgress,300);
})();</script>';
    }
}

$html .= '</body>';
$html .= '</html>';

// [L3M] Bouton WhatsApp groupe — visible pour les étudiants sur les cours ECSR
global $COURSE, $USER, $DB;
$ecsr_courses = [2,3,4,5,6,7,8,9,10,11,12];
if (isloggedin() && !isguestuser() && !is_siteadmin()
    && isset($COURSE->id) && in_array($COURSE->id, $ecsr_courses)) {
    // Vérifier que l'utilisateur n'est pas formateur
    $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
    $isTeacher = $teacherroleid && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $teacherroleid]);
    if (!$isTeacher) {
        $whatsapp_link = 'https://chat.whatsapp.com/CAqlpOsGrzJIHKPGxogmY3';
        $html .= '<a id="l3m-whatsapp-banner" style="'
            . 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);'
            . 'background:#25D366;color:#fff;font-weight:700;font-size:14px;'
            . 'padding:12px 24px;border-radius:50px;z-index:99999;'
            . 'box-shadow:0 4px 16px rgba(0,0,0,.25);display:flex;align-items:center;gap:10px;'
            . 'text-decoration:none;white-space:nowrap;'
            . '" href="' . $whatsapp_link . '" target="_blank">'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="white" viewBox="0 0 24 24">'
            . '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15'
            . '-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475'
            . '-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52'
            . '.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207'
            . '-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372'
            . '-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074'
            . '.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625'
            . '.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413'
            . '.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>'
            . '<path d="M12 0C5.373 0 0 5.373 0 12c0 2.136.561 4.14 1.535 5.874L0 24l6.335-1.507'
            . 'A11.945 11.945 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.882'
            . 'a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.981.999-3.648-.235-.374'
            . 'A9.867 9.867 0 012.118 12C2.118 6.533 6.533 2.118 12 2.118'
            . 'c5.466 0 9.882 4.415 9.882 9.882 0 5.466-4.416 9.882-9.882 9.882z"/>'
            . '</svg>'
            . 'Rejoindre le groupe WhatsApp'
            . '</a>';
    }
}

echo $html;

// [L3M] Page création/édition utilisateur — déplacer champ téléphone en haut
$html2 = '<script>(function(){
    var path = window.location.pathname;
    if (path.indexOf("/user/editadvanced.php") === -1 && path.indexOf("/user/edit.php") === -1) return;
    function movePhone(){
        var phoneInput = document.querySelector("input[name=\'phone1\']");
        if (!phoneInput) return;
        // Remonter jusqu\'au conteneur de ligne du champ
        var phoneRow = phoneInput.parentElement;
        while (phoneRow && !phoneRow.classList.contains("fitem") && phoneRow.tagName !== "FIELDSET" && phoneRow !== document.body) {
            phoneRow = phoneRow.parentElement;
        }
        if (!phoneRow || phoneRow === document.body) return;
        // Trouver le champ email
        var emailInput = document.querySelector("input[name=\'email\']");
        if (!emailInput) return;
        var emailRow = emailInput.parentElement;
        while (emailRow && !emailRow.classList.contains("fitem") && emailRow !== document.body) {
            emailRow = emailRow.parentElement;
        }
        if (!emailRow || !emailRow.parentNode || emailRow === document.body) return;
        // Badge WhatsApp sur le label
        if (!document.querySelector(".l3m-whatsapp-badge")) {
            var lbl = phoneRow.querySelector("label");
            if (lbl) {
                var badge = document.createElement("span");
                badge.className = "l3m-whatsapp-badge";
                badge.style.cssText = "background:#25D366;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:8px;font-weight:700;vertical-align:middle";
                badge.textContent = "WhatsApp ✓";
                lbl.appendChild(badge);
            }
        }
        // Déplacer juste après le champ email
        emailRow.parentNode.insertBefore(phoneRow, emailRow.nextSibling);
    }
    // Lancer après chargement complet + délais pour Moodle lazy-render
    function tryMove(attempts) {
        movePhone();
        if (attempts > 0) setTimeout(function(){ tryMove(attempts - 1); }, 600);
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function(){ tryMove(5); });
    } else {
        tryMove(5);
    }
})();</script>';
echo $html2;

if ($quickview = theme_mb2nl_theme_setting($PAGE, 'quickview')) {
    $PAGE->requires->js_call_amd('theme_mb2nl/quickview', 'init', [$quickview]);
}

if (has_capability('moodle/site:config', context_system::instance())) {
    $PAGE->requires->js_call_amd('theme_mb2nl/settings', 'toggleAll');
    $PAGE->requires->js_call_amd('theme_mb2nl/settings', 'toggleCat');
    $PAGE->requires->js_call_amd('theme_mb2nl/settings', 'spectrumInit');
}

$PAGE->requires->js_amd_inline('require([\'theme_boost/loader\']);');

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

// [L3M] override mb2nl footer builder — notre footer multi-colonnes toujours affiché
if (false) {
    $html .= theme_mb2nl_format_txt('[mb2footer footerid="' . $footerid . '"]', FORMAT_HTML);
} else {
    // [L3M] Footer multi-colonnes — 4 colonnes : Brand · Navigation · Contact · Légal
    $l3m_year = date('Y');
    $html .= '<style>
/* L3M Footer multi-colonnes */
.main-footer { padding:0!important; border-top:none!important; }
.l3m-footer-grid {
    display:grid;
    grid-template-columns: 2fr 1.2fr 1.2fr 1fr;
    gap:40px;
    padding:48px 40px 40px;
    max-width:1280px;
    margin:0 auto;
}
@media(max-width:900px){
    .l3m-footer-grid { grid-template-columns:1fr 1fr; gap:32px; padding:36px 24px; }
}
@media(max-width:560px){
    .l3m-footer-grid { grid-template-columns:1fr; gap:24px; padding:28px 20px; }
}
.l3m-footer-logo { display:flex;align-items:center;gap:10px;margin-bottom:14px; }
.l3m-footer-logo-mark { width:36px;height:36px;background:#C9A227;border-radius:8px;display:flex;align-items:center;justify-content:center; }
.l3m-footer-logo-mark svg { width:20px;height:20px; }
.l3m-footer-logo-name { font-size:17px;font-weight:800;color:#fff;letter-spacing:-.01em; }
.l3m-footer-logo-name span { color:#C9A227; }
.l3m-footer-tagline { color:#6B7A95;font-size:13px;line-height:1.65;margin-bottom:20px;max-width:280px; }
.l3m-footer-copy { color:#445066;font-size:11px;line-height:1.6; }
.l3m-footer-title { color:#fff;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid #1E2E44; }
.l3m-footer-links { list-style:none;padding:0;margin:0; }
.l3m-footer-links li { margin-bottom:9px; }
.l3m-footer-links a { color:#6B7A95;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:color .18s; }
.l3m-footer-links a:hover,.l3m-footer-links a:focus { color:#C9A227; }
.l3m-footer-links a i { font-size:14px;flex-shrink:0; }
.l3m-footer-tools { display:none; }
</style>';
    $html .= '<div class="l3m-footer-grid">';

    // Column 1 — Brand
    $html .= '<div class="l3m-fcol">';
    $html .= '<div class="l3m-footer-logo">';
    $html .= '<div class="l3m-footer-logo-mark"><svg viewBox="0 0 20 20" fill="none"><path d="M4 15L10 5l6 10H4z" fill="white" opacity=".9"/></svg></div>';
    $html .= '<span class="l3m-footer-logo-name"><span>L3M</span> Formation</span>';
    $html .= '</div>';
    $html .= '<p class="l3m-footer-tagline">Votre plateforme de formation professionnelle en ligne, adaptée à votre rythme.</p>';
    $html .= '<p class="l3m-footer-copy">&copy; ' . $l3m_year . ' L3M Formation<br>Tous droits réservés</p>';
    $html .= '</div>';

    // Column 2 — Navigation
    $html .= '<div class="l3m-fcol">';
    $html .= '<h4 class="l3m-footer-title">Navigation</h4>';
    $html .= '<ul class="l3m-footer-links">';
    $html .= '<li><a href="/"><i class="ri-home-4-line"></i>Accueil</a></li>';
    $html .= '<li><a href="/course/index.php"><i class="ri-graduation-cap-line"></i>Nos formations</a></li>';
    $html .= '<li><a href="/my/"><i class="ri-dashboard-line"></i>Mon apprentissage</a></li>';
    $html .= '<li><a href="https://l3m.glowbl.com/l3m-ecsr" target="_blank"><i class="ri-video-chat-line"></i>Classe virtuelle</a></li>';
    $html .= '</ul>';
    $html .= '</div>';

    // Column 3 — Contact
    $html .= '<div class="l3m-fcol">';
    $html .= '<h4 class="l3m-footer-title">Contactez-nous</h4>';
    $html .= '<ul class="l3m-footer-links">';
    $html .= '<li><a href="https://wa.me/33600000000" target="_blank"><i class="ri-whatsapp-line"></i>WhatsApp</a></li>';
    $html .= '<li><a href="tel:+33600000000"><i class="ri-phone-line"></i>Téléphone</a></li>';
    $html .= '<li><a href="mailto:contact@l3m-formation.fr"><i class="ri-mail-line"></i>Email</a></li>';
    $html .= '<li><a href="#" target="_blank"><i class="ri-linkedin-box-line"></i>LinkedIn</a></li>';
    $html .= '</ul>';
    $html .= '</div>';

    // Column 4 — Légal
    $html .= '<div class="l3m-fcol">';
    $html .= '<h4 class="l3m-footer-title">Légal</h4>';
    $html .= '<ul class="l3m-footer-links">';
    $html .= '<li><a href="/mod/page/view.php?id=168"><i class="ri-shield-check-line"></i>Politique de confidentialité (RGPD)</a></li>';
    $html .= '</ul>';
    $html .= '</div>';

    $html .= '</div>'; // l3m-footer-grid

    // Keep Moodle footer tools (hidden) for compatibility
    $html .= '<div class="l3m-footer-tools">';
    $html .= $OUTPUT->theme_part('footer_tools');
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
// [L3M] Redirect formateur déplacé dans head.php

// [L3M] Cacher la cloche de notifications pour le formateur
$teacherroleid_notif = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
if ($teacherroleid_notif && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $teacherroleid_notif])) {
    $html .= '<style>
#nav-notification-popover-container { display:none!important; }
</style>';
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


// [L3M] Temoignages — section homepage
if (in_array($PAGE->pagetype, ['site-index'])) {
    $html .= '<style>
#l3m-testimonials{background:#0D1824;padding:72px 20px;margin-top:0}
#l3m-testimonials .l3m-t-inner{max-width:1140px;margin:0 auto}
#l3m-testimonials .l3m-t-eyebrow{color:#C9A227;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;text-align:center;margin-bottom:10px}
#l3m-testimonials .l3m-t-title{color:#fff;font-size:clamp(22px,3vw,34px);font-weight:800;text-align:center;margin-bottom:48px;line-height:1.2}
#l3m-testimonials .l3m-t-title span{color:#C9A227}
#l3m-testimonials .l3m-t-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}
@media(max-width:820px){#l3m-testimonials .l3m-t-grid{grid-template-columns:1fr}}
#l3m-testimonials .l3m-t-card{background:#111E2D;border:1px solid #1E2E44;border-radius:14px;padding:28px 26px 24px;display:flex;flex-direction:column;gap:16px;position:relative}
#l3m-testimonials .l3m-t-card::before{content:open-quote;position:absolute;top:16px;right:22px;font-size:72px;line-height:1;color:#C9A22722;font-family:Georgia,serif}
#l3m-testimonials .l3m-t-stars{color:#C9A227;font-size:14px;letter-spacing:2px}
#l3m-testimonials .l3m-t-text{color:#8CA0B8;font-size:14px;line-height:1.7;flex:1}
#l3m-testimonials .l3m-t-author{display:flex;align-items:center;gap:12px;margin-top:4px;border-top:1px solid #1E2E44;padding-top:16px}
#l3m-testimonials .l3m-t-avatar{width:40px;height:40px;border-radius:50%;background:#C9A227;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#0D1824;flex-shrink:0}
#l3m-testimonials .l3m-t-name{color:#fff;font-size:14px;font-weight:700;line-height:1.2}
#l3m-testimonials .l3m-t-role{color:#4A6080;font-size:12px}
</style>';
    $html .= '<script>(function(){
var TESTIMONIALS=[
  {stars:5,text:"La formation est tres bien structuree et le formateur est disponible et reactif. Contenu clair, progressif, et les classes virtuelles permettent de poser ses questions en direct. J\'ai reussi mon examen du premier coup !",name:"Sophie M.",role:"Apprenante ECSR - CCP1",initials:"SM"},
  {stars:5,text:"J\'avais quelques apprehensions sur le format 100 % en ligne, mais tout est bien explique etape par etape. Les modules sont concis et on avance vraiment a son rythme. Je recommande vivement.",name:"Thomas L.",role:"Apprenant ECSR - CCP2",initials:"TL"},
  {stars:5,text:"Plateforme intuitive, contenu de qualite et suivi personnalise. Le tableau de bord apprenant permet de voir sa progression en temps reel. Une vraie difference par rapport aux formations classiques.",name:"Marie D.",role:"Apprenante ECSR - CCP1",initials:"MD"}
];
function buildTestimonials(){
  if(document.getElementById("l3m-testimonials")) return;
  var footer=document.getElementById("page-footer")||document.querySelector(".main-footer")||document.querySelector("footer");
  if(!footer) return;
  var sec=document.createElement("section");
  sec.id="l3m-testimonials";
  var cards=TESTIMONIALS.map(function(t){
    var stars="";
    for(var i=0;i<t.stars;i++) stars+="&#9733;";
    return "<div class=\"l3m-t-card\">"
      +"<div class=\"l3m-t-stars\">"+stars+"</div>"
      +"<p class=\"l3m-t-text\">"+t.text+"</p>"
      +"<div class=\"l3m-t-author\">"
      +"<div class=\"l3m-t-avatar\">"+t.initials+"</div>"
      +"<div><div class=\"l3m-t-name\">"+t.name+"</div>"
      +"<div class=\"l3m-t-role\">"+t.role+"</div></div>"
      +"</div></div>";
  }).join("");
  sec.innerHTML="<div class=\"l3m-t-inner\">"
    +"<p class=\"l3m-t-eyebrow\">Temoignages</p>"
    +"<h2 class=\"l3m-t-title\">Ce que disent nos <span>apprenants</span></h2>"
    +"<div class=\"l3m-t-grid\">"+cards+"</div>"
    +"</div>";
  footer.parentNode.insertBefore(sec,footer);
}
if(document.readyState==="loading") document.addEventListener("DOMContentLoaded",buildTestimonials);
else buildTestimonials();
})();</script>';
}

// [L3M] Hero premium public + background
if (in_array($PAGE->pagetype, ['site-index'])) {

$isTeacher = isloggedin() && !isguestuser() &&
    (function() use ($DB, $USER) {
        $rid = $DB->get_field('role','id',['shortname'=>'editingteacher']);
        return $rid && $DB->record_exists('role_assignments',['userid'=>$USER->id,'roleid'=>$rid]);
    })();

$html .= '<style>
/* ── Hero section premium ───────────────────────────────── */
.l3m-hero-wrap {
  position:relative;
  min-height:480px;
  display:flex;
  flex-direction:column;
  justify-content:center;
  padding:80px 60px 40px;
  box-sizing:border-box;
  overflow:hidden;
}
.l3m-hero-overlay {
  position:absolute;inset:0;
  background:linear-gradient(90deg,rgba(10,22,40,.88) 40%,rgba(10,22,40,.45));
  z-index:1;
}
.l3m-hero-content {
  position:relative;z-index:2;max-width:620px;
}
.l3m-hero-badge {
  display:inline-flex;align-items:center;gap:6px;
  border:1px solid rgba(201,162,39,.55);
  color:#C9A227;font-size:11px;font-weight:600;
  letter-spacing:.12em;text-transform:uppercase;
  padding:4px 14px;border-radius:20px;
  margin-bottom:22px;
}
.l3m-hero-badge::before {
  content:"";width:6px;height:6px;border-radius:50%;
  background:#C9A227;flex-shrink:0;
}
.l3m-hero-h1 {
  font-size:clamp(2rem,4vw,3rem);font-weight:800;
  line-height:1.18;color:#fff;margin:0 0 16px;
  text-wrap:balance;
}
.l3m-hero-h1 span { color:#C9A227; }
.l3m-hero-sub {
  font-size:15px;color:rgba(255,255,255,.72);
  line-height:1.6;margin:0 0 32px;max-width:520px;
}
.l3m-hero-ctas {
  display:flex;gap:16px;flex-wrap:wrap;align-items:center;
}
.l3m-cta-primary {
  background:#C9A227;color:#111;
  font-weight:700;font-size:14px;
  padding:13px 28px;border-radius:4px;
  text-decoration:none;
  transition:opacity .18s;
  display:inline-flex;align-items:center;gap:8px;
}
.l3m-cta-primary:hover { opacity:.88; }
.l3m-cta-outline {
  border:1.5px solid rgba(255,255,255,.4);color:#fff;
  font-weight:600;font-size:14px;
  padding:12px 24px;border-radius:4px;
  text-decoration:none;
  transition:border-color .18s;
  display:inline-flex;align-items:center;gap:8px;
}
.l3m-cta-outline:hover { border-color:#fff; color:#fff; }

/* ── Stats bar ───────────────────────────────────────────── */
.l3m-stats-bar {
  display:flex;
  background:#0F1B2D;
  border-top:2px solid rgba(201,162,39,.25);
  padding:20px 60px;
  gap:0;
  flex-wrap:wrap;
}
.l3m-stat-item {
  flex:1 1 180px;
  display:flex;align-items:center;gap:14px;
  padding:8px 24px 8px 0;
  border-right:1px solid rgba(255,255,255,.08);
}
.l3m-stat-item:last-child { border-right:none; }
.l3m-stat-icon {
  font-size:22px;color:#C9A227;flex-shrink:0;
}
.l3m-stat-num {
  font-size:20px;font-weight:800;color:#fff;
  font-variant-numeric:tabular-nums;line-height:1;
}
.l3m-stat-lbl {
  font-size:11px;color:rgba(255,255,255,.5);
  letter-spacing:.04em;margin-top:3px;
}
@media(max-width:768px){
  .l3m-hero-wrap{padding:60px 24px 32px;}
  .l3m-stats-bar{padding:16px 24px;}
  .l3m-stat-item{border-right:none;padding:8px 0;}
}
</style>';

if (!$isTeacher) {
    $html .= '<script>(function(){
function applyHeroBg(){
    var hero=document.querySelector(".mb2-pb-fpsection")||document.querySelector(".mb2-pb-section");
    if(!hero) return;
    window.L3M_HERO=hero;

    var inner=hero.querySelector(".mb2-pb-section-inner,.section-inner")||hero;

    // Background image
    inner.style.setProperty("background-image","url(\"/theme/mb2nl/pix/hero_bg.jpg\")","important");
    inner.style.setProperty("background-size","cover","important");
    inner.style.setProperty("background-position","center center","important");
    inner.style.setProperty("background-repeat","no-repeat","important");
    inner.style.setProperty("min-height","480px","important");
    inner.style.setProperty("display","flex","important");
    inner.style.setProperty("align-items","center","important");
    inner.style.setProperty("position","relative","important");

    if(document.getElementById("l3m-hero-content")) return;

    // Dark overlay
    var overlay=document.createElement("div");
    overlay.className="l3m-hero-overlay";
    inner.insertBefore(overlay, inner.firstChild);

    // Hero content
    var wrap=document.createElement("div");
    wrap.className="l3m-hero-wrap";
    wrap.id="l3m-hero-content";
    wrap.innerHTML=
      "<div class=\"l3m-hero-content\">"
      +"<span class=\"l3m-hero-badge\">Certifications ECSR reconnues</span>"
      +"<h1 class=\"l3m-hero-h1\">Formez-vous aujourd\'hui,<br>"
      +"<span>devenez expert demain</span></h1>"
      +"<p class=\"l3m-hero-sub\">Préparez-vous aux certifications ECSR avec des formations "
      +"en ligne 100 % flexibles et adaptées à votre rythme.</p>"
      +"<div class=\"l3m-hero-ctas\">"
      +"<a href=\"/course/index.php\" class=\"l3m-cta-primary\">"
      +"Découvrir nos formations →</a>"
      +"<a href=\"/login/index.php\" class=\"l3m-cta-outline\">"
      +"▶ Mon espace formation</a>"
      +"</div>"
      +"</div>";
    inner.appendChild(wrap);

    // Stats bar after hero section
    if(!document.getElementById("l3m-stats-bar")){
        var stats=document.createElement("div");
        stats.id="l3m-stats-bar";
        stats.className="l3m-stats-bar";
        stats.innerHTML=
          "<div class=\"l3m-stat-item\"><span class=\"l3m-stat-icon\"><i class=\"ri-user-3-line\"></i></span>"
          +"<div><div class=\"l3m-stat-num\">+25</div><div class=\"l3m-stat-lbl\">Apprenants formés</div></div></div>"
          +"<div class=\"l3m-stat-item\"><span class=\"l3m-stat-icon\"><i class=\"ri-medal-line\"></i></span>"
          +"<div><div class=\"l3m-stat-num\">98 %</div><div class=\"l3m-stat-lbl\">Taux de réussite</div></div></div>"
          +"<div class=\"l3m-stat-item\"><span class=\"l3m-stat-icon\"><i class=\"ri-group-line\"></i></span>"
          +"<div><div class=\"l3m-stat-num\">Formateurs</div><div class=\"l3m-stat-lbl\">Experts métiers</div></div></div>"
          +"<div class=\"l3m-stat-item\"><span class=\"l3m-stat-icon\"><i class=\"ri-award-line\"></i></span>"
          +"<div><div class=\"l3m-stat-num\">Certifications</div><div class=\"l3m-stat-lbl\">Reconnues</div></div></div>";
        hero.insertAdjacentElement("afterend", stats);
    }
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",applyHeroBg);
else applyHeroBg();
})();</script>';
} else {
    $html .= '<script>(function(){
function applyHeroBg(){
    var hero=document.querySelector(".mb2-pb-fpsection")||document.querySelector(".mb2-pb-section");
    if(!hero) return;
    window.L3M_HERO=hero;
    var inner=hero.querySelector(".mb2-pb-section-inner,.section-inner");
    if(inner){
        inner.style.setProperty("background-image","url(\\"/theme/mb2nl/pix/hero_bg.jpg\\")","important");
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
                    $bbblink = 'https://l3m.glowbl.com/l3m-ecsr';
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
    +'{$ncourses} cours &nbsp;&bull;&nbsp; {$totalstudents} étudiants inscrits'
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
            if(el.closest("#region-main,[role=\'main\'],#page-content,.activity"))return;
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






// [L3M] BBB -> Google Meet
$l3m_gmeet = 'https://l3m.glowbl.com/l3m-ecsr';
$html .= '<script>
(function(){
    var gm="' . $l3m_gmeet . '";
    if(window.location.pathname.indexOf("/mod/bigbluebuttonbn/")===0){
        window.location.replace(gm);return;
    }
    document.addEventListener("click",function(e){
        var a=e.target.closest("a");
        if(a&&a.href&&a.href.indexOf("/mod/bigbluebuttonbn/")!==-1){
            e.preventDefault();
            window.open(gm,"_blank");
        }
    },true);
})();
</script>';

// [L3M] Cacher le bloc "Mes cours" dans la sidebar des cours
if (strpos($PAGE->pagetype, 'course-') === 0) {
    $html .= '<style>
.sidebar-col .block_navigation li[aria-label="Mes cours"],
.sidebar-col .block_navigation li[title="Mes cours"],
.sidebar-col .block h3 + ul > li:first-child > p > a[title="Mes cours"],
.sidebar-col .block_navigation .block_tree > ul > li.contains_branch:has(p a[title="Mes cours"]) { display:none!important; }
</style>';
    $html .= '<script>(function(){
    function hideMesCours(){
        document.querySelectorAll(".sidebar-col .block, .sidebar-col section.block").forEach(function(bl){
            var h=bl.querySelector("h2,h3,h4,.card-header,.block-header,.h5");
            if(h && h.textContent.trim()==="Mes cours"){
                bl.style.cssText="display:none!important";
            }
        });
    }
    if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",hideMesCours);
    else hideMesCours();
    setTimeout(hideMesCours,800);
})();</script>';
}


// [L3M] Barre de recherche visible dans le header
$html .= '<style>
#l3m-hsearch{display:flex;align-items:center;background:rgba(0,0,0,.05);border:1.5px solid rgba(0,0,0,.18);border-radius:22px;padding:0 6px 0 14px;height:34px;transition:border-color .2s,background .2s;flex-shrink:0;gap:2px}
#l3m-hsearch:focus-within{border-color:#C9A227;background:rgba(255,255,255,.15)}
#l3m-hsearch input{background:none;border:none;outline:none;color:#fff;font-size:13px;width:160px;min-width:0}
#l3m-hsearch input::placeholder{color:rgba(255,255,255,.42)}
#l3m-hsearch button{background:none;border:none;cursor:pointer;color:#C9A227;font-size:16px;padding:4px 6px;display:flex;align-items:center}
#theme-search-btn{display:none!important}
</style>';


// [L3M] Navigation dark premium
$html .= '<style>
/* ── Background header dark + variables dropdown ────────── */
#main-header .master-header-inner-bg,
#main-header { background:#1A1A1A !important; }
#main-header .header-innner { border-bottom: none !important; }

/* Écrasement des variables CSS du menu mb2mm */
#main-header {
  --mb2mm-ddbgcolor: #1A1A1A;
  --mb2mm-mddbgcolor: #1A1A1A;
  --mb2mm-ddcolor: #ffffff;
  --mb2mm-ddhcolor: #C9A227;
  --mb2mm-ddhbgcolor: rgba(255,255,255,.06);
  --mb2mm-ddsubcolor: rgba(255,255,255,.6);
  --mb2mm-ddsubhcolor: #C9A227;
  --mb2mm-ddbordercolor: rgba(255,255,255,.09);
  --mb2mm-hcolor: #ffffff;
  --mb2mm-hhcolor: #C9A227;
}

/* ── Nav liens ──────────────────────────────────────────── */
/* ── Nav liens blancs gras ──────────────────────────────── */
#main-header .mb2mm a.mb2mm-action,
#main-header .mb2mm a.mb2mm-action span,
#main-header .mb2mm a.mb2mm-action .mb2mm-label,
#main-header .mb2mm a.mb2mm-action .mb2mm-item-content {
  color:rgba(255,255,255,.82) !important;
  font-size:14px !important;
  font-weight:700 !important;
  letter-spacing:.02em !important;
  transition:color .18s !important;
}
#main-header .mb2mm > li.level-1 > a.mb2mm-action:hover,
#main-header .mb2mm > li.level-1 > a.mb2mm-action:hover span {
  color:#fff !important;
}
/* Lien actif gold — classe posée par JS */
#main-header .mb2mm > li.level-1 > a.l3m-nav-active,
#main-header .mb2mm > li.level-1 > a.l3m-nav-active span {
  color:#C9A227 !important;
}
#main-header .mb2mm > li.level-1 > a.l3m-nav-active {
  border-bottom:2.5px solid #C9A227 !important;
  padding-bottom:3px !important;
}


/* ── Alignement : logo gauche, nav centré, outils droite ── */
#main-header .flexcols {
  display:flex !important;
  align-items:center !important;
  justify-content:space-between !important;
  gap:0 !important;
}
#main-header .logo-wrap {
  flex:0 0 auto !important;
  margin-right:32px !important;
}
#main-header #main-navigation {
  flex:1 1 auto !important;
  display:flex !important;
  align-items:center !important;
}
#main-header .navigation-header {
  flex:1 1 auto !important;
}
#main-header ul.mb2mm {
  display:flex !important;
  align-items:center !important;
  gap:6px !important;
  padding:0 !important;
  margin:0 !important;
}
#main-header ul.mb2mm > li.level-1 {
  padding:0 !important;
}
#main-header ul.mb2mm > li.level-1 > a.mb2mm-action {
  padding:6px 14px !important;
  display:flex !important;
  align-items:center !important;
  height:54px !important;
}
/* Outils (search, avatar) collés à droite */
#main-header .header-tools {
  flex:0 0 auto !important;
  margin-left:16px !important;
}

/* ── Dropdown menus fond noir + texte blanc ──────────────── */
.mb2mm-dd { background:#1A1A1A !important; }
.mb2mm-dd * { background:transparent !important; }
.mb2mm-dd,
.mb2mm-dd li,
.mb2mm-dd a,
.mb2mm-dd a span,
.mb2mm-dd .mb2mm-label {
  color:#fff !important;
}
.mb2mm-dd { border-top:2.5px solid #C9A227 !important; box-shadow:0 8px 24px rgba(0,0,0,.45) !important; }
.mb2mm-dd a:hover, .mb2mm-dd a:hover span { color:#C9A227 !important; }

/* ── Bouton "Mon espace" → pill or ─────────────────────── */
#btn-menu-logincontainer {
  background:#C9A227 !important;
  border-radius:4px !important;
  padding:6px 16px !important;
  flex-direction:row !important;
  gap:6px !important;
  align-items:center !important;
  font-size:12px !important;
  font-weight:700 !important;
  letter-spacing:.05em !important;
  margin-left:8px !important;
}
#btn-menu-logincontainer i.icon1,
#btn-menu-logincontainer .label {
  color:#1A1A1A !important;
  margin-top:0 !important;
  font-size:12px !important;
}

/* ── Tous les boutons / icônes header en blanc ───────────── */
#main-header .header-tools-link,
#main-header .header-tools-link i,
#main-header .header-tools-link .text1,
#main-header .header-mtools-link,
#main-header .header-mtools-link i,
#main-header [data-region="popover-region-messages"] button,
#main-header [data-region="popover-region-messages"] i,
#main-header .popover-region-toggle i,
#main-header .count-container,
#btn-menu-searchcontainer,
#btn-menu-searchcontainer i.icon1,
#btn-menu-searchcontainer .label {
  color:rgba(255,255,255,.75) !important;
  margin-top:0 !important;
}
#main-header .header-tools-link:hover,
#main-header .header-tools-link:hover i,
#main-header .header-mtools-link:hover i {
  color:#fff !important;
}
/* Sauf "Mon espace" qui reste gold */
#main-header .header-tools-link.loginbtn,
#main-header .header-tools-link.loginbtn i,
#main-header .header-tools-link.loginbtn .text1 {
  color:#1A1A1A !important;
}

/* ── Barre recherche compacte & premium ──────────────────── */
#l3m-hsearch {
  background:rgba(255,255,255,.07) !important;
  border:1.5px solid rgba(255,255,255,.18) !important;
  border-radius:20px !important;
  height:36px !important;
  padding:0 8px 0 14px !important;
  max-width:200px !important;
  transition:border-color .2s, max-width .2s !important;
}
#l3m-hsearch:focus-within {
  border-color:#C9A227 !important;
  background:rgba(255,255,255,.11) !important;
  max-width:230px !important;
}
#l3m-hsearch input { color:#fff !important; font-size:12.5px !important; width:130px !important; }
#l3m-hsearch input::placeholder { color:rgba(255,255,255,.38) !important; }
#l3m-hsearch button { color:#C9A227 !important; font-size:15px !important; }

/* ── Avatar utilisateur — contour or ─────────────────────── */
#main-header .userinitials,
#main-header .user-picture .userinitials {
  background:#111 !important;
  color:#C9A227 !important;
  border:2px solid #C9A227 !important;
  font-weight:700 !important;
  font-size:13px !important;
}
#main-header .user-picture img {
  border:2px solid #C9A227 !important;
  border-radius:50% !important;
}

/* ── Icône personne (mtool-login) : masquée ──────────────── */
#main-header .header-mtools-link.mtool-login,
#main-header .header-tools-link.mtool-login { display:none !important; }

/* ── Icône message — style premium ───────────────────────── */
#main-header [data-region="popover-region-messages"] .popover-region-toggle {
  width:38px !important; height:38px !important;
  display:inline-flex !important;
  align-items:center !important; justify-content:center !important;
  border-radius:50% !important;
  background:rgba(255,255,255,.08) !important;
  border:1.5px solid rgba(255,255,255,.22) !important;
  transition:border-color .2s, background .2s !important;
  position:relative !important;
}
#main-header [data-region="popover-region-messages"] .popover-region-toggle:hover {
  border-color:#C9A227 !important;
  background:rgba(201,162,39,.12) !important;
}
#main-header [data-region="popover-region-messages"] .popover-region-toggle i,
#main-header [data-region="popover-region-messages"] .popover-region-toggle .fa {
  font-size:17px !important;
  color:#fff !important;
}
/* Badge compteur messages */
#main-header [data-region="popover-region-messages"] .count-container {
  background:#C9A227 !important;
  color:#111 !important;
  font-size:9px !important;
  font-weight:700 !important;
  min-width:16px !important;
  height:16px !important;
  border-radius:8px !important;
  top:-3px !important; right:-3px !important;
}

/* ── Masquer texte labels dans header tools ───────────────── */
[data-region="popover-region-messages"] .text1,
[data-region="popover-region-messages"] span:not(.icon):not(.count-container):not([aria-hidden="true"]):not(.sr-only),
#main-header .header-tools-link .text1,
#main-header .header-mtools-link .text1 { display:none !important; }

/* ── Espacement zone droite ───────────────────────────────── */
#main-header .header-tools { display:flex !important; align-items:center !important; gap:10px !important; }

/* ── Hamburger menu mobile ───────────────────────────────── */
#main-header .show-menu i { color:#fff !important; }

/* ── Menu extracontent contrôles ─────────────────────────── */
.menu-extracontent-controls { gap:4px !important; }
</style>';

$html .= '<script>(function(){
// Dropdown fond noir + texte blanc via inline styles
function l3mDropdown(){
    document.querySelectorAll(".mb2mm-dd").forEach(function(dd){
        dd.style.setProperty("background","#1A1A1A","important");
        dd.style.setProperty("border-top","2.5px solid #C9A227","important");
        dd.style.setProperty("box-shadow","0 8px 24px rgba(0,0,0,.5)","important");
        dd.querySelectorAll("a, span, li").forEach(function(el){
            el.style.setProperty("background","transparent","important");
            if(el.tagName==="A"||el.tagName==="SPAN"||el.tagName==="LI"){
                el.style.setProperty("color","#fff","important");
            }
        });
        dd.querySelectorAll("a").forEach(function(a){
            a.addEventListener("mouseenter",function(){
                a.style.setProperty("color","#C9A227","important");
            });
            a.addEventListener("mouseleave",function(){
                a.style.setProperty("color","#fff","important");
            });
        });
    });
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",l3mDropdown);
else l3mDropdown();
// Re-appliquer au hover sur items parents (dropdown apparaît après)
document.querySelectorAll(".mb2mm > li.isparent").forEach(function(li){
    li.addEventListener("mouseenter",function(){setTimeout(l3mDropdown,50);});
    li.addEventListener("click",function(){setTimeout(l3mDropdown,50);});
});
})();</script>';

$html .= '<style>
/* CSS override logo-light src */
.main-logo img.logo-light {
  content: url("/theme/mb2nl/pix/logo_l3m_white.png") !important;
}
</style>';
$html .= '<script>(function(){
function l3mLogo(){
    var img = document.querySelector(".main-logo img.logo-light, .logo-wrap img.logo-light");
    if(!img) return;
    img.setAttribute("src","/theme/mb2nl/pix/logo_l3m_white.png?v=3");
    img.style.setProperty("height","40px","important");
    img.style.setProperty("width","auto","important");
}
l3mLogo();
document.addEventListener("DOMContentLoaded", l3mLogo);
setTimeout(l3mLogo, 200);
setTimeout(l3mLogo, 800);
})();</script>';

$html .= '<script>(function(){
function l3mNavActive(){
    var cur=window.location.pathname.replace(/\/$/,"");
    document.querySelectorAll("#main-header ul.mb2mm > li.level-1 > a.mb2mm-action").forEach(function(a){
        var p=new URL(a.href,window.location.origin).pathname.replace(/\/$/,"");
        if(p===cur||(cur===""&&p===""))a.classList.add("l3m-nav-active");
    });
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",l3mNavActive);
else l3mNavActive();
})();</script>';

$html .= '<script>(function(){
function go(){
    if(document.getElementById("l3m-hsearch"))return;
    var sb=document.getElementById("theme-search-btn");
    if(!sb)return;
    var form=document.createElement("form");
    form.id="l3m-hsearch";
    form.action="/course/search.php";
    form.method="get";
    var inp=document.createElement("input");
    inp.type="text";inp.name="search";
    inp.placeholder="Rechercher une formation…";
    inp.autocomplete="off";inp.setAttribute("aria-label","Rechercher");
    var btn=document.createElement("button");
    btn.type="submit";btn.setAttribute("aria-label","Rechercher");
    var ico=document.createElement("i");
    ico.className="ri-search-line";
    btn.appendChild(ico);
    form.appendChild(inp);form.appendChild(btn);
    sb.parentNode.insertBefore(form,sb);
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",go);
else go();
})();</script>';

// [L3M] Hover cartes + Messagerie pleine page
$html .= '<style>
/* Cacher le bloc Catégories de cours */
.block.block_course_list { display:none!important; }
/* Cacher Mode edition */
.editmode-switch-form, [data-region="editmode-switch"], .editmode-switch, .theme-turnediting { display:none!important; }

/* ── Hover cartes cours ─────────────────────────────────────────── */
.dashboard-card,
.myc-item.card,
.coursebox {
    transition: transform .22s ease, box-shadow .22s ease !important;
    will-change: transform;
}
.dashboard-card:hover,
.myc-item.card:hover {
    transform: translateY(-6px) !important;
    box-shadow: 0 16px 36px rgba(0,0,0,.14) !important;
}
.coursebox:hover {
    transform: translateY(-6px) !important;
    box-shadow: 0 16px 36px rgba(0,0,0,.14) !important;
    border-color: #C9A227 !important;
}
/* Zoom image fond (div background-image) */
.dashboard-card-img,
.courseimage {
    overflow: hidden;
    transition: transform .35s ease !important;
}
.dashboard-card:hover .dashboard-card-img,
.myc-item.card:hover .dashboard-card-img {
    transform: scale(1.06) !important;
}
.coursebox:hover .courseimage {
    transform: scale(1.06) !important;
}
/* ── Messagerie : cacher le panneau latéral, lien pleine page ───── */
</style>';

$html .= '<script>(function(){
/* Hover cartes — clip overflow pour que le zoom image reste propre */
function clipCourseImages(){
    document.querySelectorAll(".courseimage, .card-img-top, [data-course-id] img").forEach(function(el){
        el.style.overflow = "hidden";
    });
}

/* Messagerie — intercepte le clic sur le bouton drawer et redirige */
function interceptMsg(){
    document.addEventListener("click", function(e){
        var btn = e.target.closest(
            "[data-region=\"popover-region-messages\"] a," +
            "a[id^=\"message-drawer-toggle-\"]"
        );
        if(!btn) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        window.location.href = "/message/index.php";
    }, true);
}

if(document.readyState === "loading"){
    document.addEventListener("DOMContentLoaded", function(){ clipCourseImages(); interceptMsg(); });
} else {
    clipCourseImages(); interceptMsg();
}
})();</script>';

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

// [L3M] Recherche formateurs + prevention Enter
$html2 .= '<script>
(function(){
    // Filtrage formateurs
    document.addEventListener("input", function(e){
        if (!e.target || e.target.id !== "l3m-trainer-search-inpt") return;
        var q = e.target.value.toLowerCase().trim();
        var panel = document.getElementById("cfilter_instructors");
        if (!panel) return;
        var items = panel.querySelectorAll("ul li.filter-form-field");
        var visible = 0;
        items.forEach(function(li){
            var txt = (li.querySelector("label") || li).textContent.toLowerCase();
            var match = !q || txt.indexOf(q) !== -1;
            li.style.display = match ? "" : "none";
            if (match) visible++;
        });
        var nm = document.getElementById("l3m-no-trainer");
        if (nm) nm.style.display = visible === 0 ? "block" : "none";
    });
    // Bloquer Enter dans le champ de recherche formateurs
    document.addEventListener("keydown", function(e){
        if (e.target && e.target.id === "l3m-trainer-search-inpt" && (e.key === "Enter" || e.keyCode === 13)){
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
    }, true);
})();
</script>';
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

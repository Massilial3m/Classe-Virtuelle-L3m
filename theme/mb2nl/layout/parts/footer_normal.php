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
    $html .= '<li><a href="/offre.php"><i class="ri-price-tag-3-line"></i>Notre offre ECSR</a></li>';
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
.langmenu,.lang-menu,.lang-fullname,.lang-shortname,
#footer .langmenu,footer .langmenu,.footer-lang,
.footer-tools .langmenu,.footer-tools [class*="lang"],
[data-langmenu],.standard-footer-html .langmenu { display:none!important; }
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
$html .= '<script>(function(){
function hideLang(){
    /* Cibler uniquement les vrais conteneurs de menu langue */
    document.querySelectorAll(".lang-fullname,.lang-shortname,.langmenu").forEach(function(el){
        var p=el.closest("ul,li,div,nav")||el;
        p.style.setProperty("display","none","important");
    });
    /* Liens lang= uniquement dans les menus langue connus — pas dans le contenu principal */
    var LANG_CONTAINERS=".langmenu,.lang-menu,.footer-lang,.footer-tools,[data-region=\"usermenu\"]";
    document.querySelectorAll(LANG_CONTAINERS+" a[href*=\"lang=\"]").forEach(function(a){
        var p=a.closest("ul,li,div,p,nav")||a;
        p.style.setProperty("display","none","important");
    });
}
hideLang();
document.addEventListener("DOMContentLoaded",hideLang);
setTimeout(hideLang,300);
setTimeout(hideLang,1000);
var obs=new MutationObserver(hideLang);
obs.observe(document.body,{childList:true,subtree:true});
})();</script>';


// [L3M] Redirection formateur : dashboard → Espace formateur
global $PAGE, $USER, $DB;
// [L3M] Redirect formateur déplacé dans head.php

// [L3M] Tableau de bord formateur (mod/page id=169) — visuel harmonisé
if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/mod/page/view.php') !== false &&
    (strpos($_SERVER['REQUEST_URI'], 'id=169') !== false)) {
    $html .= '<style>
/* Masquer le titre Moodle auto-généré (premier "Tableau de bord formateur") */
#page-header,.mb2-pb-page-header,.page-header-image,.mb2nl-page-header,
.headerimage-wrapper,.mb2-pb-headerimage,.secondary-navigation,.page-secnav,
.activity-header,.activity-header-inner,.activity-name,
#page-mod-page-view h1,
#page-mod-page-view .page-header-headings,
#page-mod-page-view .mb2-pb-page-header-title,
#page-mod-page-view [data-region="activity-name"]{display:none!important;}
/* Fond blanc */
body#page-mod-page-view,
#page-mod-page-view #page,
#page-mod-page-view #page-content,
#page-mod-page-view #region-main,
#page-mod-page-view .region-main-content{background:#fff!important;}
/* Retirer le padding autour du contenu */
#page-mod-page-view #region-main{padding:0!important;}
#page-mod-page-view #main-content{padding:8px 0 40px!important;}
#page-mod-page-view .container-fluid{padding-left:0!important;padding-right:0!important;}
/* Breadcrumb discret sur fond blanc */
#page-mod-page-view .breadcrumb-container,
#page-mod-page-view nav[aria-label="Navigation"],
#page-mod-page-view .navbar{
  background:#f5f5f5!important;border-bottom:1px solid #e0e0e0!important;
  padding:10px 24px!important;margin:0!important;
}
#page-mod-page-view .breadcrumb{background:transparent!important;padding:0!important;margin:0!important;}
#page-mod-page-view .breadcrumb-item a{color:#666!important;font-size:12px!important;text-decoration:none!important;}
#page-mod-page-view .breadcrumb-item a:hover{color:#C9A227!important;}
#page-mod-page-view .breadcrumb-item.active{color:#C9A227!important;font-size:12px!important;}
#page-mod-page-view .breadcrumb-item+.breadcrumb-item::before{color:#999!important;}
/* Masquer le premier item du breadcrumb (Lindberg Formation / home) */
#page-mod-page-view .breadcrumb-item:first-child{display:none!important;}
</style>
<script>(function(){
  if(window.location.pathname.indexOf("/mod/page/view.php")===-1)return;
  var params=new URLSearchParams(window.location.search);
  if(params.get("id")!=="169")return;
  function cleanPage(){
    /* Masquer le premier breadcrumb item (Lindberg Formation) */
    var bc=document.querySelectorAll(".breadcrumb-item,.breadcrumb li");
    if(bc.length>0) bc[0].style.setProperty("display","none","important");
    /* Supprimer "Lindberg Formation" partout dans la page */
    document.querySelectorAll("*").forEach(function(el){
      if(el.children.length>0)return;
      if(el.textContent.trim()==="Lindberg Formation"||el.textContent.trim()==="Lindberg"){
        var p=el.closest("li,.breadcrumb-item,span")||el;
        p.style.setProperty("display","none","important");
      }
    });
    /* Masquer liens home icon */
    document.querySelectorAll("a[href=\"/\"],a[href*=\"//\"+location.host+\"/\"]").forEach(function(a){
      var t=a.textContent.trim();
      if(t===""||t==="Accueil"||t==="Home"){
        var li=a.closest("li,.breadcrumb-item")||a.parentElement;
        li.style.setProperty("display","none","important");
      }
    });
  }
  if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",cleanPage);
  else cleanPage();
  setTimeout(cleanPage,300);
  setTimeout(cleanPage,800);
})();</script>';
}

// [L3M] Notifications : visible étudiants, caché formateurs
$teacherroleid_notif = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
$isTeacher_notif = $teacherroleid_notif && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $teacherroleid_notif]);
if ($isTeacher_notif) {
    $html .= '<style>#nav-notification-popover-container,[data-region="popover-region-notifications"]{display:none!important;}</style>';
    // [L3M] Bouton "Mon espace" dans la navbar pour les formateurs
    $html .= '<style>
#l3m-mon-espace-btn{
  display:inline-flex;align-items:center;gap:7px;
  background:#2E9488;color:#fff!important;
  font-size:13px;font-weight:600;
  padding:7px 16px;border-radius:20px;
  text-decoration:none!important;
  white-space:nowrap;cursor:pointer;
  border:none;
  transition:background .18s,box-shadow .18s;
  box-shadow:0 2px 8px rgba(46,148,136,.25);
}
#l3m-mon-espace-btn:hover{background:#267d73;box-shadow:0 4px 14px rgba(46,148,136,.35);}
#l3m-mon-espace-btn svg{width:16px;height:16px;flex-shrink:0;}
</style>
<script>(function(){
function injectMonEspaceBtn(){
  if(document.getElementById("l3m-mon-espace-btn")) return;
  var a=document.createElement("a");
  a.id="l3m-mon-espace-btn";
  a.href="/espace-formateur.php";
  a.innerHTML=\'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Mon espace\';
  // Chercher la zone droite de la navbar (usermenu, login-info, header-tools)
  var targets=[
    "[data-region=\'usermenu\']",
    ".usermenu",
    ".login-info",
    ".header-tools",
    "#usernavigation",
    ".navbar-nav.ms-auto",
    ".navbar .ms-auto",
    ".primary-navigation .navbar",
    "#main-header .navbar"
  ];
  var inserted=false;
  for(var i=0;i<targets.length;i++){
    var el=document.querySelector(targets[i]);
    if(el){
      el.insertAdjacentElement("beforebegin",a);
      inserted=true;
      break;
    }
  }
  if(!inserted){
    var nav=document.querySelector("#main-header,.navbar,.fixed-top");
    if(nav){
      nav.appendChild(a);
    }
  }
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",injectMonEspaceBtn);
else injectMonEspaceBtn();
})();</script>';
} else {
    $html .= '<style>#nav-notification-popover-container,[data-region="popover-region-notifications"]{display:inline-flex!important;align-items:center!important;}</style>';
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
    // Cacher le mot "Notifications" dans le bouton toggle
    document.querySelectorAll("#nav-notification-popover-container .popover-region-toggle, [data-region=\'popover-region-notifications\'] .popover-region-toggle").forEach(function(btn){
        btn.childNodes.forEach(function(node){
            if(node.nodeType===3&&node.textContent.trim().length>0){
                node.textContent="";
            }
        });
        btn.querySelectorAll("span").forEach(function(sp){
            var t=sp.textContent.trim().toLowerCase();
            if(t==="notifications"||t==="notification"){
                sp.style.setProperty("display","none","important");
            }
        });
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


// [L3M] Temoignages — section homepage (masqué pour formateurs)
$_is_teacher_testi = isloggedin() && !isguestuser() && (function() use ($DB, $USER) {
    $rid = $DB->get_field('role','id',['shortname'=>'editingteacher']);
    return $rid && $DB->record_exists('role_assignments',['userid'=>$USER->id,'roleid'=>$rid]);
})();
if (in_array($PAGE->pagetype, ['site-index']) && !$_is_teacher_testi) {
    $html .= '<style>
#l3m-testimonials{background:#111111;padding:72px 20px;margin-top:0}
#l3m-testimonials .l3m-t-inner{max-width:1140px;margin:0 auto}
#l3m-testimonials .l3m-t-eyebrow{color:#C9A227;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;text-align:center;margin-bottom:10px}
#l3m-testimonials .l3m-t-title{color:#fff;font-size:clamp(22px,3vw,34px);font-weight:800;text-align:center;margin-bottom:48px;line-height:1.2}
#l3m-testimonials .l3m-t-title span{color:#C9A227}
#l3m-testimonials .l3m-t-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}
@media(max-width:820px){#l3m-testimonials .l3m-t-grid{grid-template-columns:1fr}}
#l3m-testimonials .l3m-t-card{background:#1A1A1A;border:1px solid #2A2A2A;border-radius:14px;padding:28px 26px 24px;display:flex;flex-direction:column;gap:16px;position:relative}
#l3m-testimonials .l3m-t-card::before{content:open-quote;position:absolute;top:16px;right:22px;font-size:72px;line-height:1;color:#C9A22722;font-family:Georgia,serif}
#l3m-testimonials .l3m-t-stars{color:#C9A227;font-size:14px;letter-spacing:2px}
#l3m-testimonials .l3m-t-text{color:#8CA0B8;font-size:14px;line-height:1.7;flex:1}
#l3m-testimonials .l3m-t-author{display:flex;align-items:center;gap:12px;margin-top:4px;border-top:1px solid #2A2A2A;padding-top:16px}
#l3m-testimonials .l3m-t-avatar{width:44px;height:44px;border-radius:50%;background:#C9A227;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#0D1824;flex-shrink:0;overflow:hidden;}
#l3m-testimonials .l3m-t-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
#l3m-testimonials .l3m-t-name{color:#fff;font-size:14px;font-weight:700;line-height:1.2}
#l3m-testimonials .l3m-t-role{color:#4A6080;font-size:12px}
</style>';
    $html .= '<script>(function(){
var TESTIMONIALS=[
  {stars:5,text:"La formation est tres bien structuree et le formateur est disponible et reactif. Contenu clair, progressif, et les classes virtuelles permettent de poser ses questions en direct. J\'ai reussi mon examen du premier coup !",name:"Sophie M.",role:"Apprenante ECSR - CCP1",initials:"SM",photo:"https://randomuser.me/api/portraits/women/25.jpg"},
  {stars:5,text:"J\'avais quelques apprehensions sur le format 100 % en ligne, mais tout est bien explique etape par etape. Les modules sont concis et on avance vraiment a son rythme. Je recommande vivement.",name:"Thomas L.",role:"Apprenant ECSR - CCP2",initials:"TL",photo:"https://randomuser.me/api/portraits/men/32.jpg"},
  {stars:5,text:"Plateforme intuitive, contenu de qualite et suivi personnalise. Le tableau de bord apprenant permet de voir sa progression en temps reel. Une vraie difference par rapport aux formations classiques.",name:"Marie D.",role:"Apprenante ECSR - CCP1",initials:"MD",photo:"https://randomuser.me/api/portraits/women/68.jpg"}
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
      +"<div class=\"l3m-t-avatar\"><img src=\""+t.photo+"\" alt=\""+t.name+"\" loading=\"lazy\"></div>"
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

// Badge espace formateur / etudiant
if (isloggedin() && !isguestuser()) {
    $role_label = $isTeacher ? 'Espace Formateur' : 'Espace Étudiant';
    $role_color = '#C9A227';
    $html .= '<style>
#l3m-role-banner{
  position:absolute;
  bottom:-26px;right:0;top:auto;left:auto;
  z-index:99999;
  display:inline-block;
  background:' . $role_color . ';color:#111;
  font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
  padding:4px 18px 4px 16px;border-radius:20px 0 0 20px;
  pointer-events:none;
  white-space:nowrap;
}
</style>
<div id="l3m-role-banner">' . $role_label . '</div>
<script>(function(){
  function init(){
    var banner = document.getElementById("l3m-role-banner");
    if(!banner) return;
    var header = document.getElementById("main-header") || document.querySelector("header,.mb2nl-header,.site-header");
    if(!header) return;
    if(!header.contains(banner)) header.appendChild(banner);
    header.style.setProperty("overflow","visible","important");
    header.style.setProperty("position","relative","important");
  }
  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
</script>';
}

$html .= '<style>
/* ── Hero section premium ───────────────────────────────── */
.l3m-hero-wrap {
  position:relative;
  min-height:480px;
  display:flex;
  flex-direction:column;
  justify-content:center;
  padding:60px 60px 48px;
  box-sizing:border-box;
  overflow:hidden;
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
  background:#111111;
  border-top:2px solid rgba(201,162,39,.25);
  padding:20px 60px;
  gap:0;
  flex-wrap:wrap;
  justify-content:center;
}

.l3m-stat-item {
  flex:1 1 180px;
  display:flex;align-items:center;justify-content:center;gap:14px;
  padding:8px 0;
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

/* ── Section formations ──────────────────────────────── */
.l3m-sec-formations .section-inner {
  background:#111 !important;
}
.l3m-sec-formations .heading .headingtext {
  color:#fff !important;
}
.l3m-sec-formations .heading .headingtext em,
.l3m-sec-formations .heading .headingtext span {
  color:#C9A227 !important;
}
.l3m-sec-formations .theme-course-item-inner {
  background:#1A1A1A !important;
  border:1px solid rgba(255,255,255,.07) !important;
  border-radius:10px !important;
  overflow:hidden !important;
  transition:transform .2s,box-shadow .2s,border-color .2s !important;
}
.l3m-sec-formations .theme-course-item-inner:hover {
  transform:translateY(-5px) !important;
  box-shadow:0 14px 36px rgba(0,0,0,.5) !important;
  border-color:rgba(201,162,39,.4) !important;
}
.l3m-sec-formations .image-wrap img {
  height:185px !important;
  object-fit:cover !important;
  width:100% !important;
  display:block !important;
}
.l3m-sec-formations .content-wrap {
  padding:16px 18px 14px !important;
}
.l3m-sec-formations .title a {
  color:#fff !important;
  font-size:15px !important;
  font-weight:700 !important;
  line-height:1.35 !important;
  text-decoration:none !important;
}
.l3m-sec-formations .title a:hover { color:#C9A227 !important; }
.l3m-sec-formations .course-item-deatils,
.l3m-sec-formations .course-item-deatils * {
  color:rgba(255,255,255,.45) !important;
  font-size:12px !important;
}
.l3m-sec-formations .course-footer {
  border-top:1px solid rgba(255,255,255,.07) !important;
  padding-top:10px !important;
  margin-top:10px !important;
}
.l3m-sec-formations .course-footer,
.l3m-sec-formations .course-footer * {
  color:rgba(255,255,255,.4) !important;
  font-size:12px !important;
}
.l3m-sec-formations .progress {
  background:rgba(255,255,255,.1) !important;
  border-radius:4px !important;
  height:5px !important;
}
.l3m-sec-formations .progress-bar {
  background:#C9A227 !important;
}
.l3m-sec-formations .l3m-course-progress {
  padding:0 18px 14px !important;
}
.l3m-sec-formations .mb2-pb-btn {
  background:#C9A227 !important;
  color:#111 !important;
  font-weight:700 !important;
}
.l3m-sec-formations .mb2-pb-btn:hover { opacity:.88 !important; }
@media(max-width:768px){
  .l3m-sec-formations .image-wrap img{height:150px !important;}
}
</style>';

// Progress per category for logged-in students (SQL direct — compatible Moodle 5)
$parcours_progress = ['ccp1' => 0, 'ccp2' => 0, 'exam' => 0];
if (isloggedin() && !isguestuser() && !$isTeacher) {
    $cat_courses = [
        'ccp1' => [2,3,4,5,6],
        'ccp2' => [8,9,10,11],
        'exam' => [7,12],
    ];
    foreach ($cat_courses as $key => $cids) {
        $total = 0; $done = 0;
        foreach ($cids as $cid) {
            $total += (int)$DB->count_records_select('course_modules', 'course = :cid AND completion > 0', ['cid' => $cid]);
            $done  += (int)$DB->get_field_sql(
                'SELECT COUNT(*) FROM {course_modules_completion} cmc
                 JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                 WHERE cm.course = :cid AND cmc.userid = :uid AND cmc.completionstate >= 1 AND cm.completion > 0',
                ['cid' => $cid, 'uid' => $USER->id]
            );
        }
        $parcours_progress[$key] = $total > 0 ? min(100, round($done / $total * 100)) : 0;
    }
}
$p_ccp1 = $parcours_progress['ccp1'];
$p_ccp2 = $parcours_progress['ccp2'];
$p_exam = $parcours_progress['exam'];
$show_progress = isloggedin() && !isguestuser() && !$isTeacher ? 'true' : 'false';
if (isloggedin() && !isguestuser() && !$isTeacher) {
    $l3m_prenom   = json_encode($USER->firstname);
    $l3m_lbl      = 'CCP1'; $l3m_href = '/course/index.php?categoryid=3'; $l3m_pct = $p_ccp1;
    if ($p_ccp1 > 0 && $p_ccp1 < 100)  { $l3m_lbl = 'CCP1'; $l3m_href = '/course/index.php?categoryid=3'; $l3m_pct = $p_ccp1; }
    elseif ($p_ccp2 > 0 && $p_ccp2 < 100) { $l3m_lbl = 'CCP2'; $l3m_href = '/course/index.php?categoryid=4'; $l3m_pct = $p_ccp2; }
    elseif ($p_exam > 0 && $p_exam < 100)  { $l3m_lbl = 'EXAM'; $l3m_href = '/course/index.php?categoryid=5'; $l3m_pct = $p_exam; }
    $l3m_lbl_js  = json_encode($l3m_lbl);
    $l3m_href_js = json_encode($l3m_href);
} else {
    $l3m_prenom = '""'; $l3m_lbl_js = '""'; $l3m_href_js = '""'; $l3m_pct = 0;
}

$html .= '<style>
/* ── Section Parcours ──────────────────────────────────── */
.l3m-parcours {
  background:#f7f7f7;
  padding:60px 60px 50px;
  box-sizing:border-box;
}
.l3m-parcours-header {
  display:flex;align-items:flex-end;justify-content:space-between;
  margin-bottom:36px;flex-wrap:wrap;gap:12px;
}
.l3m-parcours-eyebrow {
  display:block;font-size:11px;font-weight:700;letter-spacing:.14em;
  color:#C9A227;text-transform:uppercase;margin-bottom:8px;
}
.l3m-parcours-title {
  font-size:clamp(1.6rem,3vw,2.1rem);font-weight:800;color:#111;
  margin:0;padding-bottom:10px;
  border-bottom:3px solid #C9A227;display:inline-block;
}
.l3m-parcours-viewall {
  font-size:13px;font-weight:600;color:#111;text-decoration:none;
  border:1px solid #ddd;padding:8px 16px;border-radius:4px;
  white-space:nowrap;transition:border-color .18s;
}
.l3m-parcours-viewall:hover { border-color:#C9A227;color:#C9A227; }
.l3m-parcours-grid {
  display:grid;grid-template-columns:repeat(3,1fr);gap:24px;
}
.l3m-parcours-card {
  background:#fff;border-radius:10px;
  box-shadow:0 2px 12px rgba(0,0,0,.08);
  overflow:hidden;display:flex;flex-direction:column;
  transition:transform .2s,box-shadow .2s;
}
.l3m-parcours-card:hover {
  transform:translateY(-5px);
  box-shadow:0 10px 28px rgba(0,0,0,.13);
}
.l3m-parcours-img {
  position:relative;height:220px;overflow:hidden;
}
.l3m-parcours-img-photo {
  position:absolute;inset:0;
  width:100%;height:100%;object-fit:cover;
  filter:saturate(0.45) brightness(0.88);
  z-index:0;
}
.l3m-parcours-img-overlay {
  position:absolute;inset:0;z-index:2;
  background:linear-gradient(transparent 30%,rgba(0,0,0,.82) 72%);
  display:flex;flex-direction:column;justify-content:flex-end;
  padding:14px 16px;
}
.l3m-parcours-badge { z-index:3; }
.l3m-parcours-img-title { color:#fff;font-size:15px;font-weight:700;line-height:1.3;margin:0 0 3px; }
.l3m-parcours-img-sub  { color:rgba(255,255,255,.75);font-size:12px;margin:0; }
.l3m-parcours-badge {
  position:absolute;top:12px;left:12px;
  font-size:11px;font-weight:700;letter-spacing:.05em;
  padding:3px 11px;border-radius:4px;color:#fff;z-index:2;
}
.l3m-parcours-badge.ccp1 { background:#2563EB; }
.l3m-parcours-badge.ccp2 { background:#16A34A; }
.l3m-parcours-badge.exam { background:#DC2626; }
.l3m-parcours-img-sub {
  color:rgba(255,255,255,.75);font-size:12px;margin:0;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}
.l3m-parcours-body {
  padding:16px 18px 18px;display:flex;flex-direction:column;flex:1;
}
.l3m-parcours-desc {
  font-size:13px;color:#555;line-height:1.55;margin:0 0 14px;font-weight:500;
}
.l3m-parcours-meta {
  display:flex;gap:0;margin-bottom:16px;flex:1;
}
.l3m-parcours-meta-item {
  flex:1;display:flex;flex-direction:column;align-items:center;
  border-right:1px solid #eee;padding:8px 8px;
}
.l3m-parcours-meta-item:first-child { padding-left:0; }
.l3m-parcours-meta-item:last-child { border-right:none; }
.l3m-meta-icon {
  font-size:18px;color:#C9A227;margin-bottom:4px;
}
.l3m-parcours-meta-num {
  font-size:16px;font-weight:800;color:#111;line-height:1;
}
.l3m-parcours-meta-lbl {
  font-size:10px;color:#999;letter-spacing:.04em;margin-top:2px;font-weight:600;
}
.l3m-parcours-progress-row {
  display:flex;align-items:center;gap:10px;margin-top:auto;
}
.l3m-parcours-pct {
  font-size:13px;font-weight:700;color:#111;min-width:32px;
}
.l3m-parcours-bar-wrap {
  flex:1;background:#eee;border-radius:4px;height:5px;overflow:hidden;
}
.l3m-parcours-bar-fill {
  height:100%;border-radius:4px;transition:width .6s;
}
.l3m-parcours-bar-fill.ccp1 { background:#2563EB; }
.l3m-parcours-bar-fill.ccp2 { background:#16A34A; }
.l3m-parcours-bar-fill.exam { background:#DC2626; }
.l3m-parcours-btn {
  width:32px;height:32px;border-radius:50%;
  background:#C9A227;color:#111;font-weight:700;font-size:16px;
  display:flex;align-items:center;justify-content:center;
  text-decoration:none;flex-shrink:0;transition:opacity .18s;
}
.l3m-parcours-btn:hover { opacity:.85; }
@media(max-width:900px){
  .l3m-parcours{padding:40px 24px 36px;}
  .l3m-parcours-grid{grid-template-columns:1fr;}
}
</style>';

$html .= <<<HTML
<script>(function(){
var PROGRESS={ccp1:{$p_ccp1},ccp2:{$p_ccp2},exam:{$p_exam}};
var SHOW_PROGRESS={$show_progress};
window.L3M_PRENOM={$l3m_prenom};
window.L3M_LBL={$l3m_lbl_js};
window.L3M_PCT={$l3m_pct};
window.L3M_HREF={$l3m_href_js};

var CARDS=[
  {key:"ccp1",badge:"CCP1",badgeCls:"ccp1",
   title:"Animer des actions de formation à la conduite",
   sub:"Acquérez les compétences pour animer des formations efficaces et engageantes.",
   desc:"Acquérez les compétences pour animer des formations efficaces et engageantes.",
   meta:[{n:"5",l:"Cours"},{n:"Débutant",l:"Niveau"},{n:"35h",l:"Formation"}],
   href:"/course/index.php?categoryid=3",
   img:"/theme/mb2nl/pix/card_ccp1.jpg"},
  {key:"ccp2",badge:"CCP2",badgeCls:"ccp2",
   title:"Veille professionnelle et développement",
   sub:"Développez une veille efficace et contribuez à la croissance de votre activité.",
   desc:"Développez une veille efficace et contribuez à la croissance de votre activité professionnelle.",
   meta:[{n:"4",l:"Cours"},{n:"Intermédiaire",l:"Niveau"},{n:"20h",l:"Formation"}],
   href:"/course/index.php?categoryid=4",
   img:"/theme/mb2nl/pix/card_ccp2.jpg"},
  {key:"exam",badge:"EXAM",badgeCls:"exam",
   title:"Préparation à l\'évaluation",
   sub:"Préparez-vous et validez vos compétences pour réussir votre évaluation.",
   desc:"Préparez-vous et validez vos compétences pour réussir votre évaluation finale.",
   meta:[{n:"2",l:"Examens"},{n:"Tous niveaux",l:"Niveau"},{n:"5h",l:"Formation"}],
   href:"/course/index.php?categoryid=5",
   img:"/theme/mb2nl/pix/card_exam.jpg"}
];

function buildParcours(){
  if(document.getElementById("l3m-parcours-section")) return;

  // Hide existing "Nos formations" page builder section
  var existing=document.querySelector(".mb2-pb-courses");
  if(existing){
    var sec=existing.closest(".mb2-pb-fpsection");
    if(sec) sec.style.setProperty("display","none","important");
  }

  // Build section
  var section=document.createElement("div");
  section.id="l3m-parcours-section";
  section.className="l3m-parcours";

  // Header
  var header=document.createElement("div");
  header.className="l3m-parcours-header";
  header.innerHTML=
    "<div>"
    +"<span class=\"l3m-parcours-eyebrow\">Nos parcours de formation</span>"
    +"<h2 class=\"l3m-parcours-title\">Choisissez votre parcours</h2>"
    +"</div>"
    +"<a href=\"/course/index.php\" class=\"l3m-parcours-viewall\">Voir tous les parcours &rsaquo;</a>";
  section.appendChild(header);

  // Grid
  var grid=document.createElement("div");
  grid.className="l3m-parcours-grid";

  CARDS.forEach(function(c){
    var pct=SHOW_PROGRESS?PROGRESS[c.key]:0;
    var icons=["ri-book-open-line","ri-time-line","ri-bar-chart-horizontal-line"];
    var metaHtml=c.meta.map(function(m,i){
      return "<div class=\"l3m-parcours-meta-item\">"
        +"<i class=\""+icons[i]+" l3m-meta-icon\"></i>"
        +"<span class=\"l3m-parcours-meta-num\">"+m.n+"</span>"
        +"<span class=\"l3m-parcours-meta-lbl\">"+m.l+"</span>"
        +"</div>";
    }).join("");

    var card=document.createElement("div");
    card.className="l3m-parcours-card";
    card.style.cursor="pointer";
    (function(href){
      card.addEventListener("click",function(e){
        if(!e.target.closest("a"))window.location.href=href;
      });
    })(c.href);
    card.innerHTML=
      "<div class=\"l3m-parcours-img\">"
      +"<img class=\"l3m-parcours-img-photo\" src=\""+c.img+"\" alt=\""+c.title+"\">"
      +"<span class=\"l3m-parcours-badge "+c.badgeCls+"\">"+c.badge+"</span>"
      +"<div class=\"l3m-parcours-img-overlay\">"
      +"<h3 class=\"l3m-parcours-img-title\">"+c.title+"</h3>"
      +"<p class=\"l3m-parcours-img-sub\">"+c.sub+"</p>"
      +"</div></div>"
      +"<div class=\"l3m-parcours-body\">"
      +"<p class=\"l3m-parcours-desc\">"+c.desc+"</p>"
      +"<div class=\"l3m-parcours-meta\">"+metaHtml+"</div>"
      +(SHOW_PROGRESS?"<div class=\"l3m-parcours-progress-row\">"
      +"<span class=\"l3m-parcours-pct\">"+pct+"%</span>"
      +"<div class=\"l3m-parcours-bar-wrap\">"
      +"<div class=\"l3m-parcours-bar-fill "+c.badgeCls+"\" style=\"width:"+pct+"%\"></div>"
      +"</div>"
      +"<a href=\""+c.href+"\" class=\"l3m-parcours-btn\">&rarr;</a>"
      +"</div>":"<a href=\""+c.href+"\" class=\"l3m-cta-primary\" style=\"margin-top:auto;text-decoration:none;text-align:center\">Accéder &rarr;</a>")
      +"</div>";
    grid.appendChild(card);
  });

  section.appendChild(grid);

  // Insert after stats bar or after hero
  // Insert right after hero section (before anything else)
  var hero=window.L3M_HERO||document.querySelector(".mb2-pb-fpsection")||document.querySelector(".mb2-pb-section");
  var statsBar=document.getElementById("l3m-stats-bar");
  var anchor=statsBar||hero;
  if(anchor&&anchor.parentNode){
    anchor.parentNode.insertBefore(section,anchor.nextSibling);
  } else {
    var main=document.getElementById("page-content")||document.body;
    main.prepend(section);
  }

  // ── Section avantages (après parcours) ──────────────
  if(document.getElementById("l3m-avantages")) return;
  var avantages=[
    {icon:"ri-award-line",      title:"Certification reconnue",    desc:"Des certifications professionnelles reconnues par l’État et les acteurs du secteur."},
    {icon:"ri-user-star-line",  title:"Formateurs experts",        desc:"Apprenez auprès de professionnels expérimentés et passionnés par leur métier."},
    {icon:"ri-time-line",       title:"Apprentissage flexible",    desc:"Formez-vous où vous voulez, quand vous voulez, à votre rythme."},
    {icon:"ri-headphone-line",  title:"Support dédié",  desc:"Une équipe à votre écoute pour vous accompagner tout au long de votre parcours."}
  ];
  var av=document.createElement("div");
  av.id="l3m-avantages";
  av.style.cssText="background:#FDF9F3;padding:40px 60px;display:grid;grid-template-columns:repeat(4,1fr);gap:28px;box-sizing:border-box;";
  avantages.forEach(function(a){
    var col=document.createElement("div");
    col.style.cssText="display:flex;flex-direction:column;align-items:flex-start;gap:12px;";
    col.innerHTML=
      "<div style=\"width:48px;height:48px;border-radius:50%;background:#FBF4DC;display:flex;align-items:center;justify-content:center;flex-shrink:0;\">"
      +"<i class=\""+a.icon+"\" style=\"font-size:22px;color:#C9A227;\"></i></div>"
      +"<div>"
      +"<div style=\"font-size:14px;font-weight:700;color:#111;margin-bottom:6px;\">"+a.title+"</div>"
      +"<div style=\"font-size:13px;color:#666;line-height:1.55;\">"+a.desc+"</div>"
      +"</div>";
    av.appendChild(col);
  });
  section.insertAdjacentElement("afterend",av);
}

function tryBuildParcours(){
  // Wait for stats bar (created by applyHeroBg) before inserting
  if(!document.getElementById("l3m-stats-bar")){
    setTimeout(tryBuildParcours,100);
    return;
  }
  buildParcours();
}
if(document.readyState==="loading") document.addEventListener("DOMContentLoaded",tryBuildParcours);
else tryBuildParcours();
})();</script>
HTML;

// Masquer la FAQ de la homepage
$html .= '<script>(function(){
function hideFaqSection(){
    document.querySelectorAll(".mb2-pb-fpsection,.mb2-pb-section").forEach(function(sec){
        if(sec.querySelector(".l3m-faq")
           ||sec.textContent.includes("Questions fréquentes")
           ||sec.textContent.includes("Classe virtuelle intégrée")){
            sec.style.setProperty("display","none","important");
        }
    });
}
if(document.readyState==="loading") document.addEventListener("DOMContentLoaded",hideFaqSection);
else hideFaqSection();
setTimeout(hideFaqSection,600);
})();</script>';



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
    inner.style.setProperty("padding-top","0","important");
    inner.style.setProperty("padding-bottom","0","important");
    inner.style.setProperty("display","flex","important");
    inner.style.setProperty("align-items","center","important");
    inner.style.setProperty("position","relative","important");

    if(document.getElementById("l3m-hero-content")) return;

    // Hide existing Moodle page builder content in the section
    inner.querySelectorAll(".mb2-pb-sortable-rows,.mb2-pb-row,.mb2-pb-column,.mb2-pb-text,.mb2-pb-button").forEach(function(el){
        el.style.setProperty("display","none","important");
    });

    // Hero content
    var wrap=document.createElement("div");
    wrap.className="l3m-hero-wrap";
    wrap.id="l3m-hero-content";
    wrap.innerHTML=
      "<div class=\"l3m-hero-content\">"
      +"<h1 class=\"l3m-hero-h1\">Formez-vous au métier d\'ECSR,<br>"
      +"<span>sans quitter votre canapé.</span></h1>"
      +"<p class=\"l3m-hero-sub\">Le Titre Professionnel Enseignant de la Conduite et de la Sécurité Routière, "
      +"préparé 100 % en ligne, à votre rythme, avec un formateur qui vous suit.</p>"
      +"<div class=\"l3m-hero-ctas\">"
      +"<a href=\"/offre.php\" class=\"l3m-cta-primary\">"
      +"Découvrir la formation →</a>"
      +"<a href=\"/my/\" class=\"l3m-cta-outline\">"
      +"▶ Accéder à mon espace</a>"
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
function runHeroAndReady(){
    applyHeroBg();
    document.body.classList.add("l3m-ready");
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",runHeroAndReady);
else runHeroAndReady();
/* Fallback : révèle la page si applyHeroBg échoue */
setTimeout(function(){ document.body.classList.add("l3m-ready"); }, 1500);
// Patch textes hero pour etudiant connecte
if(window.L3M_PRENOM){
  function patchHeroText(){
    var w=document.getElementById("l3m-hero-content");
    if(!w){setTimeout(patchHeroText,100);return;}
    var badge=w.querySelector(".l3m-hero-badge");
    var h1=w.querySelector(".l3m-hero-h1");
    var sub=w.querySelector(".l3m-hero-sub");
    var cta1=w.querySelector(".l3m-cta-primary");
    var cta2=w.querySelector(".l3m-cta-outline");
    if(badge) badge.style.display="none";
    if(h1) h1.innerHTML="Bonjour <span style=\"color:#C9A227\">"+L3M_PRENOM+"</span>, reprenez là où vous vous étiez arrêté";
    if(sub) sub.textContent=L3M_LBL+" · "+L3M_PCT+"% complété · Continuez votre formation";
    if(cta1){cta1.textContent="Reprendre ma formation →";cta1.href=L3M_HREF;}
    if(cta2){cta2.textContent="▶ Mon tableau de bord";cta2.href="/my/";}
  }
  patchHeroText();
}
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
        $ncourses  = count($teachercourses);
        $datajs    = json_encode($coursedata);


        $html .= <<<HTML
<script>(function(){
var path=window.location.pathname;
if(path!=="/"&&path!=="/index.php"&&path.indexOf("/?")!==0)return;
window.L3M_TEACHER_PRENOM="{$firstname}";
window.L3M_TEACHER_STUDENTS={$totalstudents};
window.L3M_TEACHER_COURSES={$ncourses};
function patchHeroTeacher(){
  var w=document.getElementById("l3m-hero-content");
  if(!w){setTimeout(patchHeroTeacher,100);return;}
  var badge=w.querySelector(".l3m-hero-badge");
  var h1=w.querySelector(".l3m-hero-h1");
  var sub=w.querySelector(".l3m-hero-sub");
  var cta1=w.querySelector(".l3m-cta-primary");
  var cta2=w.querySelector(".l3m-cta-outline");
  if(badge) badge.textContent="Espace formateur";
  if(h1) h1.innerHTML="Bonjour <span style=\"color:#C9A227\">"+L3M_TEACHER_PRENOM+"</span>&nbsp;! Bienvenue dans votre espace.";
  if(sub) sub.textContent=L3M_TEACHER_STUDENTS+" apprenants · "+L3M_TEACHER_COURSES+" cours actifs";
  if(cta1){cta1.textContent="Mon espace formateur →";cta1.href="/espace-formateur.php";}
  if(cta2){cta2.textContent="Mes cours";cta2.href="/course/index.php";}
}
patchHeroTeacher();
function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
var data={$datajs};
function addOverlay(card,cid){
  var d=data[cid];if(!d)return;
  if(card.querySelector('.l3m-teacher-overlay'))return;
  var ov=document.createElement('div');
  ov.className='l3m-teacher-overlay';
  ov.style.cssText='position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.85));padding:8px 12px 10px;display:flex;align-items:center;justify-content:space-between;gap:6px;z-index:5;';
  var stats='<span style="color:#fff;font-size:11.5px;line-height:1.5;display:flex;flex-direction:column;gap:1px">'
    +'<span>\u{1f465} '+d.enrolled+' étudiant'+(d.enrolled>1?'s':'')+'</span>'
    +'<span style="color:#C9A227">\u{1f4ca} '+d.avg+'% progression moy.</span>'
    +'</span>';
  var btn=d.bbb?'<a href="'+esc(d.bbb)+'" target="_blank" style="background:#C9A227;color:#111;font-size:11px;font-weight:700;padding:5px 9px;border-radius:20px;text-decoration:none;white-space:nowrap;flex-shrink:0;line-height:1.2">▶ Classe<br>virtuelle</a>':'';
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

// [L3M] Lien "Formations" dans le header → page parcours
$html .= '<script>(function(){
function fixFormationsLink(){
    document.querySelectorAll("#main-nav a, .mb2mm a, .mb2mm-nav a").forEach(function(a){
        var t=a.textContent.trim();
        if((t==="Formations"||t==="Nos formations")&&a.href.indexOf("/parcours.php")===-1){
            a.href="/parcours.php";
        }
    });
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",fixFormationsLink);
else fixFormationsLink();
setTimeout(fixFormationsLink,300);
})();</script>';

// [L3M] Hide quiz report - garder seulement le tableau des tentatives
$html .= '<script>(function(){
    if(window.location.pathname.indexOf("/mod/quiz/report.php")===-1)return;
    function hideCourseSidebar(){
        /* Cibler par texte "Contenu du cours" */
        document.querySelectorAll("h2,h3,h4,h5,span,div,p").forEach(function(el){
            if(el.children.length>0)return;
            var t=el.textContent.trim();
            if(t==="Contenu du cours"||t==="Course content"||t==="Table of contents"){
                var block=el.closest(".block,.card,.sidebar-col,.drawer,.aside,.col-md-3,.col-md-4,[class*=sidebar],[class*=drawer],[class*=courseindex],[class*=course-index]")||el.parentElement;
                if(block) block.style.setProperty("display","none","important");
            }
        });
        /* Sélecteurs connus mb2nl/Moodle5 */
        ["#theme-course-index",".mb2-course-index",".mb2nl-course-index",
         "[data-region=\'courseindex\']",".courseindex","#courseindex",
         ".drawer[id*=\'courseindex\']",".drawer-right",
         ".block_tableofcontents",".block_course_toc",
         ".course-index",".course-index-drawer"
        ].forEach(function(s){
            document.querySelectorAll(s).forEach(function(el){
                el.style.setProperty("display","none","important");
            });
        });
    }
    if(document.readyState==="loading") document.addEventListener("DOMContentLoaded",hideCourseSidebar);
    else hideCourseSidebar();
    setTimeout(hideCourseSidebar,400);
    new MutationObserver(hideCourseSidebar).observe(document.body,{childList:true,subtree:true});
})();</script>';
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
        // Bouton/badge "Test" (filtre groupe actif) + espace vide groupe
        document.querySelectorAll("*").forEach(function(el){
            if(el.children.length===0 && el.textContent.trim()==="Test"){
                var p=el.closest(".groupselector,.groups,.btn-group,.form-group,form,nav,div,[class*=group],[class*=tertiary]")||el.parentElement||el;
                p.style.cssText="display:none!important";
            }
        });
        // Cases à cocher (1ère colonne th/td avec input[type=checkbox])
        document.querySelectorAll("table").forEach(function(tbl){
            tbl.querySelectorAll("th:first-child,td:first-child").forEach(function(cell){
                if(cell.querySelector("input[type=checkbox]")||
                   (cell.tagName==="TH"&&cell.querySelector("input[type=checkbox],label")||
                   (cell.innerHTML.trim()===""||cell.innerHTML.indexOf("checkbox")!==-1))){
                    cell.style.cssText="display:none!important";
                }
            });
            // Header checkbox
            var firstTh=tbl.querySelector("thead th:first-child");
            if(firstTh&&(firstTh.querySelector("input")||firstTh.innerHTML.trim()==="")){
                firstTh.style.cssText="display:none!important";
                tbl.querySelectorAll("tbody td:first-child").forEach(function(td){
                    td.style.cssText="display:none!important";
                });
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
            /* Exclure : contenu principal, navigations secondaire/tertiaire, onglets */
            if(el.closest("#region-main,[role=\"main\"],#page-content,.activity,.tertiary-navigation,.secondary-navigation,[data-region=\"tertiary-navigation\"],[data-region=\"secondary-navigation\"],.nav-tabs,.grade-overview-grade,table"))return;
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
/* ── Masquer moremenu navigation (pill blanc entre header et contenu) ── */
.moremenu.navigation { display:none!important; }

/* ── Icône quicklinks (SVG entre logo et Accueil) ────────── */
#main-header .quicklinks svg,
#main-header .quicklinks svg path,
#main-header .quicklinks svg circle,
#main-header .quicklinks svg rect,
#main-header .quicklinks svg use {
  fill: rgba(255,255,255,.85) !important;
  stroke: none !important;
}
#main-header .quicklinks { color: rgba(255,255,255,.85) !important; }

/* ── Toutes les icônes du header en blanc (sauf exceptions) ─ */
#main-header i[class],
#main-header .icon1,
#main-header .icon2,
#main-header button.themereset i,
#main-header button.themereset .icon1,
#main-header .show-menu i,
#main-header .show-menu .icon1 {
  color: rgba(255,255,255,.82) !important;
}
/* Exception : bouton Mon espace (or sur fond sombre) */
#main-header .loginbtn i,
#main-header .loginbtn .icon1,
#btn-menu-logincontainer i,
#btn-menu-logincontainer .icon1 {
  color: #1A1A1A !important;
}

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
#main-header .mb2mm a.mb2mm-action i,
#main-header .mb2mm a.mb2mm-action .mb2mm-label,
#main-header .mb2mm a.mb2mm-action .mb2mm-item-content {
  color:rgba(255,255,255,.82) !important;
  font-size:14px !important;
  font-weight:700 !important;
  letter-spacing:.02em !important;
  transition:color .18s !important;
}
#main-header .mb2mm > li.level-1 > a.mb2mm-action:hover,
#main-header .mb2mm > li.level-1 > a.mb2mm-action:hover span,
#main-header .mb2mm > li.level-1 > a.mb2mm-action:hover i {
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

/* ── Dropdown + menu mobile : fond noir + texte blanc ─────── */
.mb2mm-dd,
#mb2-mobilemenu,
.mb2-mobilemenu,
.mobile-navbottom,
#mobilemenu_extra-content,
.mb2mm-mdd,
.mb2mm .mb2mm-dd {
  background:#1A1A1A !important;
}
.mb2mm-dd *,
#mb2-mobilemenu *,
.mb2-mobilemenu *,
.mobile-navbottom *,
.mb2mm-mdd * {
  background:transparent !important;
}
.mb2mm-dd,
.mb2mm-dd li,
.mb2mm-dd a,
.mb2mm-dd a span,
.mb2mm-dd .mb2mm-label,
#mb2-mobilemenu a,
#mb2-mobilemenu span,
#mb2-mobilemenu li,
.mb2-mobilemenu a,
.mb2-mobilemenu span,
.mobile-navbottom a,
.mobile-navbottom span,
.mb2mm-mdd a,
.mb2mm-mdd span {
  color:#fff !important;
}
.mb2mm-dd { border-top:2.5px solid #C9A227 !important; box-shadow:0 8px 24px rgba(0,0,0,.45) !important; }
.mb2mm-dd a:hover, .mb2mm-dd a:hover span { color:#C9A227 !important; }
#mb2-mobilemenu a:hover, .mb2-mobilemenu a:hover { color:#C9A227 !important; }

/* ── Menu quicklinks (mobile) ────────────────────────────── */
.quicklinks-list {
  background:#1A1A1A !important;
  box-shadow:0 8px 24px rgba(0,0,0,.45) !important;
  border-top:2px solid #C9A227 !important;
}
.quicklinks-list *,
.quicklinks-list a,
.quicklinks-list span,
.quicklinks-list li {
  background:transparent !important;
  color:#fff !important;
}
.quicklinks-list a:hover,
.quicklinks-list a:hover span { color:#C9A227 !important; }

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
#main-header .popover-region-toggle,
#main-header .popover-region-toggle i,
#main-header .count-container,
#btn-menu-searchcontainer,
#btn-menu-searchcontainer i.icon1,
#btn-menu-searchcontainer .label,
#main-header .header-tools button,
#main-header .header-tools button i,
#main-header .header-tools a i,
#main-header .btn-mtools-dd,
#main-header .btn-mtools-dd i,
#main-header .dropdown-toggle:not(.loginbtn),
#main-header .dropdown-toggle:not(.loginbtn) i,
#main-header .header-mtools-btn,
#main-header .header-mtools-btn i,
#main-header .header-icons a,
#main-header .header-icons a i,
#main-header .header-tools-wrap a i,
#main-header .header-tools-wrap button i,
#main-header [data-region="popover-region-notifications"] button,
#main-header [data-region="popover-region-notifications"] i {
  color:rgba(255,255,255,.78) !important;
  margin-top:0 !important;
}
#main-header .header-tools-link:hover,
#main-header .header-tools-link:hover i,
#main-header .header-mtools-link:hover i,
#main-header .header-tools button:hover i,
#main-header .dropdown-toggle:hover i {
  color:#fff !important;
}
/* Sauf "Mon espace" qui reste gold */

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

/* ── Icônes message + notification — style cercle premium ─── */
#main-header [data-region="popover-region-messages"] .popover-region-toggle,
#main-header [data-region="popover-region-notifications"] .popover-region-toggle,
#main-header #nav-notification-popover-container .popover-region-toggle {
  width:38px !important; height:38px !important;
  display:inline-flex !important;
  align-items:center !important; justify-content:center !important;
  border-radius:50% !important;
  background:rgba(255,255,255,.08) !important;
  border:1.5px solid rgba(255,255,255,.22) !important;
  transition:border-color .2s, background .2s !important;
  position:relative !important;
}
#main-header [data-region="popover-region-messages"] .popover-region-toggle:hover,
#main-header [data-region="popover-region-notifications"] .popover-region-toggle:hover,
#main-header #nav-notification-popover-container .popover-region-toggle:hover {
  border-color:#C9A227 !important;
  background:rgba(201,162,39,.12) !important;
}
#main-header [data-region="popover-region-messages"] .popover-region-toggle i,
#main-header [data-region="popover-region-messages"] .popover-region-toggle .fa,
#main-header [data-region="popover-region-notifications"] .popover-region-toggle i,
#main-header [data-region="popover-region-notifications"] .popover-region-toggle .fa,
#main-header #nav-notification-popover-container .popover-region-toggle i {
  font-size:17px !important;
  color:#fff !important;
}
/* Cacher le texte "Notifications" dans le bouton */
#main-header [data-region="popover-region-notifications"] .popover-region-toggle .sr-only,
#main-header [data-region="popover-region-notifications"] .popover-region-toggle span:not(.count-container):not(.icon):not(.fa):not([class*="badge"]):not([class*="count"]),
#main-header #nav-notification-popover-container .popover-region-toggle .notification-label,
#main-header #nav-notification-popover-container .popover-region-toggle [data-notification-label] {
  display:none !important;
}
/* Badge compteur notifications */
#main-header [data-region="popover-region-notifications"] .count-container,
#main-header #nav-notification-popover-container .count-container {
  background:#C9A227 !important;
  color:#111 !important;
  font-size:9px !important;
  font-weight:700 !important;
  min-width:16px !important;
  height:16px !important;
  border-radius:8px !important;
  top:-3px !important; right:-3px !important;
}
/* SVG icons (Moodle 5 utilise des SVG pour les icônes boutons) */
#main-header .popover-region-toggle svg,
#main-header .popover-region-toggle svg *,
#main-header .header-tools button svg,
#main-header .header-tools button svg *,
#main-header .header-tools a svg,
#main-header .header-tools a svg * {
  fill:rgba(255,255,255,.82) !important;
  stroke:none !important;
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

/* ── Dropdown tools (Calendrier / Rapport / Émargement) ──── */
#main-header .dropdown-menu,
#main-header .header-tools .dropdown-menu {
  background:#1A1A1A !important;
  border:none !important;
  border-top:2.5px solid #C9A227 !important;
  box-shadow:0 8px 24px rgba(0,0,0,.5) !important;
  border-radius:0 0 8px 8px !important;
  min-width:200px !important;
}
#main-header .dropdown-menu a,
#main-header .dropdown-menu .dropdown-item {
  color:rgba(255,255,255,.82) !important;
  background:transparent !important;
  font-size:13px !important;
  font-weight:600 !important;
  padding:10px 18px !important;
  display:flex !important;
  align-items:center !important;
  gap:8px !important;
}
#main-header .dropdown-menu a:hover,
#main-header .dropdown-menu .dropdown-item:hover {
  color:#C9A227 !important;
  background:rgba(255,255,255,.05) !important;
}
#main-header .dropdown-menu a i,
#main-header .dropdown-menu .dropdown-item i {
  color:#C9A227 !important;
  font-size:15px !important;
}
</style>';

$html .= '<script>(function(){
// Force blanc sur SVG quicklinks (entre logo et Accueil)
function fixQuicklinks(){
    document.querySelectorAll("#main-header .quicklinks svg, #main-header .quicklinks svg *").forEach(function(el){
        el.style.setProperty("fill","rgba(255,255,255,.85)","important");
    });
    document.querySelectorAll("#main-header .quicklinks").forEach(function(el){
        el.style.setProperty("color","rgba(255,255,255,.85)","important");
    });
}
document.addEventListener("DOMContentLoaded", fixQuicklinks);
setTimeout(fixQuicklinks, 100);
setTimeout(fixQuicklinks, 500);
})();</script>';

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
    form.action="/search.php";
    form.method="get";
    var inp=document.createElement("input");
    inp.type="text";inp.name="q";
    inp.placeholder="Rechercher sur le site…";
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

/* ═══════════════════════════════════════════════════════════════════
   PAGE CATALOGUE COURS — harmonie avec la home L3M
   ═══════════════════════════════════════════════════════════════════ */

/* ── Header catégorie ─────────────────────────────────────────────── */
body.path-course-index .page-header-headings,
body.path-course-index .l3m-cat-hero {
    display: none !important;
}
body.path-course-index #page-header {
    background: #111 !important;
    border-bottom: 3px solid #C9A227 !important;
    padding: 0 !important;
    margin-bottom: 0 !important;
    min-height: 0 !important;
}
/* ── Page background ──────────────────────────────────────────────── */
body.path-course-index #page,
body.path-course-index #page-content {
    background: #f7f6f2 !important;
}
/* ── Course cards container ───────────────────────────────────────── */
body.path-course-index .courses {
    display: grid !important;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)) !important;
    gap: 24px !important;
    padding: 0 !important;
}
/* ── Card base ────────────────────────────────────────────────────── */
body.path-course-index .coursebox {
    background: #fff !important;
    border: 1.5px solid #e8e4d8 !important;
    border-radius: 12px !important;
    overflow: hidden !important;
    box-shadow: 0 2px 12px rgba(0,0,0,.07) !important;
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s !important;
    margin: 0 !important;
    padding: 0 !important;
    width: auto !important;
}
body.path-course-index .coursebox:hover {
    transform: translateY(-5px) !important;
    box-shadow: 0 12px 32px rgba(0,0,0,.13) !important;
    border-color: #C9A227 !important;
}
/* ── Card image — override random color overlays ──────────────────── */
body.path-course-index .courseimage,
body.path-course-index .courseimage > div {
    border-radius: 0 !important;
    height: 180px !important;
    background-size: cover !important;
    background-position: center !important;
    position: relative !important;
}
/* Neutralise les overlays colorés du thème */
body.path-course-index .courseimage::after,
body.path-course-index .courseimage > div::after,
body.path-course-index .courseimage::before,
body.path-course-index .courseimage > div::before {
    display: none !important;
}
/* Overlay sombre uniforme L3M */
body.path-course-index .courseimage {
    overflow: hidden !important;
}
body.path-course-index .courseimage > div {
    filter: none !important;
}
/* ── Badge "Mon module" ───────────────────────────────────────────── */
body.path-course-index .l3m-module-badge,
body.path-course-index .badge.l3m-enrolled {
    background: #C9A227 !important;
    color: #111 !important;
    font-weight: 700 !important;
    font-size: 11px !important;
    border-radius: 20px !important;
    padding: 3px 12px !important;
    border: none !important;
}
/* ── Card content ─────────────────────────────────────────────────── */
body.path-course-index .coursebox .coursename a,
body.path-course-index .coursebox .coursename {
    color: #111 !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    line-height: 1.35 !important;
    text-decoration: none !important;
}
body.path-course-index .coursebox .coursename a:hover {
    color: #C9A227 !important;
}
body.path-course-index .coursebox .info {
    padding: 16px !important;
}
body.path-course-index .coursebox .content {
    padding: 0 16px 16px !important;
}
/* ── Progress bar ─────────────────────────────────────────────────── */
body.path-course-index .progress {
    height: 6px !important;
    border-radius: 6px !important;
    background: #e8e4d8 !important;
    margin: 10px 0 4px !important;
    overflow: hidden !important;
}
body.path-course-index .progress-bar {
    background: #C9A227 !important;
    border-radius: 6px !important;
}
body.path-course-index .completion-progress {
    font-size: 12px !important;
    color: #888 !important;
    font-weight: 500 !important;
}
/* ── Filter bar ───────────────────────────────────────────────────── */
body.path-course-index .mb2nl-courses-search,
body.path-course-index .mb2nl-courses-filter,
body.path-course-index [class*="filter"] input,
body.path-course-index .form-control {
    border-radius: 8px !important;
    border: 1.5px solid #ddd !important;
}
body.path-course-index .btn-primary,
body.path-course-index [data-action="filter"] {
    background: #C9A227 !important;
    border-color: #C9A227 !important;
    color: #111 !important;
    font-weight: 700 !important;
    border-radius: 8px !important;
}
/* ── Masquer bloc "Catégorie" / category select / search ── */
body.path-course-index h3.categoryname,
body.path-course-index h4.categoryname,
body.path-course-index .info:has(.categoryname),
body.path-course-index .coursecat,
body.path-course-index #action_bar,
body.path-course-index .tertiary-navigation,
body.path-course-index .mb2nl-allcats-btn,
body.path-course-index .l3m-cat-toggle,
body.path-course-index [data-filterregion="filter"] { display:none!important; }

/* ══════════════════════════════════════════════════════════════════
   DASHBOARD /my/ — maquette L3M
   ══════════════════════════════════════════════════════════════════ */

/* ── Supprime le padding gauche/droite ── */
body.path-my #page {
    padding-left: 0 !important;
    padding-right: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}
/* Forcer section.content-col en pleine largeur (DOM reel : section.content-col.col-lg-10) */
body.path-my section.content-col {
    width: 100% !important;
    max-width: 100% !important;
    flex: 0 0 100% !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
}
/* Cacher la colonne sidebar */
body.path-my div.sidebar-col {
    display: none !important;
}
/* Le row #theme-main-content sans marges */
body.path-my #theme-main-content {
    margin-left: 0 !important;
    margin-right: 0 !important;
    width: 100% !important;
}
/* container-fluid sans padding lateral */
body.path-my .container-fluid {
    padding-left: 0 !important;
    padding-right: 0 !important;
}
body.path-my #page-content,
body.path-my #region-main {
    padding-left: 0 !important;
    padding-right: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}
body.path-my #page-header { display:none !important; }
/* Masquer le contenu AJAX dashboard Moodle (dshb-wrap) de façon permanente */
body.path-my .dshb-wrap { display:none !important; }
/* Masquer les blocs Moodle natifs sur le dashboard */
body.path-my [data-block],
body.path-my .block_myoverview,
body.path-my .block_timeline,
body.path-my .block_recentlyaccesseditems { display:none !important; }

/* ── Wrapper centré pour le contenu dashboard ── */
body.path-my #l3m-dash-wrapper {
    max-width: 1320px !important;
    margin: 0 auto !important;
    padding: 24px 32px !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

/* ── Background page ── */
body.path-my #page { background: #f4f4f2 !important; }

/* ── Cache le chart-wrap-outer async (remplace par jauges PHP statiques) ── */
body.path-my .chart-wrap-outer { display: none !important; }

/* ── Carte Mes cours (jauges SVG statiques) ── */
body.path-my #l3m-mes-cours-card {
    background: #fff !important;
    border-radius: 14px !important;
    padding: 24px 36px !important;
    box-shadow: 0 1px 10px rgba(0,0,0,.07) !important;
    border: 1px solid #eee !important;
    margin-bottom: 20px !important;
    position: relative !important;
    overflow: hidden !important;
    display: none !important;
}
body.path-my .l3m-mc-title {
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #aaa !important;
    text-transform: uppercase !important;
    letter-spacing: .1em !important;
    margin-bottom: 16px !important;
    display: block !important;
}
body.path-my .l3m-mc-gauges {
    display: flex !important;
    align-items: flex-start !important;
    gap: 40px !important;
    flex-wrap: nowrap !important;
}
body.path-my .l3m-gauge-item {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
}
body.path-my .l3m-gauge-svg {
    width: 120px !important;
    height: 120px !important;
    display: block !important;
}
body.path-my .l3m-gauge-sub {
    font-size: 10px !important;
    color: #888 !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: .07em !important;
    margin-top: 4px !important;
    text-align: center !important;
    display: block !important;
}
body.path-my .l3m-mc-cap {
    position: absolute !important;
    right: 32px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    font-size: 72px !important;
    opacity: .1 !important;
    pointer-events: none !important;
    user-select: none !important;
}

/* ── Blocs en bas : 3 colonnes ── */
body.path-my #l3m-dash-bottom {
    display: grid !important;
    grid-template-columns: 1fr 1.5fr 1fr !important;
    gap: 20px !important;
    align-items: start !important;
}

/* ── Bloc Éléments consultés — plus grand ── */
body.path-my .block_recentlyaccesseditems,
body.path-my .block_timeline {
    background: #fff !important;
    border-radius: 14px !important;
    border: 1px solid #eee !important;
    box-shadow: 0 1px 10px rgba(0,0,0,.07) !important;
    min-width: 280px !important;
    font-size: 14px !important;
}
body.path-my .block_recentlyaccesseditems .card-body,
body.path-my .block_timeline .card-body {
    padding: 20px !important;
}
body.path-my .block_recentlyaccesseditems .card-title,
body.path-my .block_timeline .card-title {
    font-size: 14px !important;
    font-weight: 700 !important;
    color: #111 !important;
    text-transform: uppercase !important;
    letter-spacing: .05em !important;
}
body.path-my .block_recentlyaccesseditems .media,
body.path-my .block_recentlyaccesseditems [data-region="activity-item"] {
    padding: 10px 0 !important;
    border-bottom: 1px solid #f4f4f2 !important;
}
body.path-my .block_recentlyaccesseditems .media-body .text-truncate {
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #111 !important;
}
body.path-my .block_recentlyaccesseditems .media-body small,
body.path-my .block_recentlyaccesseditems .media-body .coursename {
    color: #C9A227 !important;
    font-size: 11px !important;
    font-weight: 600 !important;
}
body.path-my .block_recentlyaccesseditems .btn,
body.path-my .block_timeline .btn {
    background: #111 !important;
    color: #fff !important;
    border-radius: 8px !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    border: none !important;
    padding: 8px 16px !important;
    margin-top: 12px !important;
}

/* ── Carte greeting ── */
body.path-my #l3m-greeting-card {
    background: linear-gradient(145deg,#fffbf0,#fff8e1) !important;
    border-radius: 14px !important;
    padding: 28px 24px !important;
    box-shadow: 0 1px 10px rgba(0,0,0,.07) !important;
    border: 1px solid #f0e8c8 !important;
    position: relative !important;
    overflow: hidden !important;
}
body.path-my #l3m-greeting-card h2,
body.path-my #l3m-greeting-card .l3m-greeting-name {
    font-size: 1.15rem !important;
    font-weight: 800 !important;
    color: #111 !important;
    margin: 0 0 12px !important;
}
body.path-my #l3m-greeting-card p {
    font-size: 13px !important;
    color: #555 !important;
    margin: 0 0 8px !important;
    line-height: 1.55 !important;
}
body.path-my #l3m-greeting-card .l3m-cta-continue {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin-top: 16px !important;
    background: #C9A227 !important;
    color: #111 !important;
    font-weight: 700 !important;
    font-size: 13px !important;
    padding: 10px 20px !important;
    border-radius: 8px !important;
    text-decoration: none !important;
}

/* ── Carte heures ── */
body.path-my #l3m-hours-card {
    background: #fff !important;
    border-radius: 14px !important;
    padding: 22px 24px !important;
    box-shadow: 0 1px 10px rgba(0,0,0,.07) !important;
    border: 1px solid #eee !important;
}
body.path-my #l3m-hours-card .l3m-card-header {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    margin-bottom: 16px !important;
}
body.path-my #l3m-hours-card .l3m-card-title {
    font-size: 14px !important;
    font-weight: 700 !important;
    color: #111 !important;
}
body.path-my #l3m-hours-card .l3m-total-badge {
    background: #C9A227 !important;
    color: #111 !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    padding: 3px 12px !important;
    border-radius: 20px !important;
}
body.path-my #l3m-hours-card table {
    width: 100% !important;
    border-collapse: collapse !important;
    font-size: 13px !important;
}
body.path-my #l3m-hours-card tr {
    border-bottom: 1px solid #f4f4f2 !important;
}
body.path-my #l3m-hours-card td {
    padding: 8px 4px !important;
    color: #444 !important;
}
body.path-my #l3m-hours-card td:last-child {
    font-weight: 700 !important;
    color: #111 !important;
    text-align: right !important;
}
body.path-my #l3m-hours-card .l3m-see-all {
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    margin-top: 14px !important;
    font-size: 13px !important;
    color: #555 !important;
    border: 1px solid #ddd !important;
    padding: 7px 16px !important;
    border-radius: 8px !important;
    text-decoration: none !important;
    font-weight: 500 !important;
}

/* ── Accès rapide ── */
body.path-my #l3m-quickaccess-card {
    background: #fff !important;
    border-radius: 14px !important;
    padding: 22px 24px !important;
    box-shadow: 0 1px 10px rgba(0,0,0,.07) !important;
    border: 1px solid #eee !important;
}
body.path-my #l3m-quickaccess-card .l3m-card-title {
    font-size: 14px !important;
    font-weight: 700 !important;
    color: #111 !important;
    margin-bottom: 16px !important;
}
body.path-my #l3m-quickaccess-card .l3m-qa-item {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 10px 12px !important;
    border-radius: 8px !important;
    border: 1px solid #eee !important;
    margin-bottom: 8px !important;
    text-decoration: none !important;
    transition: border-color .15s !important;
}
body.path-my #l3m-quickaccess-card .l3m-qa-item:hover {
    border-color: #C9A227 !important;
}
body.path-my #l3m-quickaccess-card .l3m-qa-icon {
    color: #C9A227 !important;
    font-size: 18px !important;
    flex-shrink: 0 !important;
}
body.path-my #l3m-quickaccess-card .l3m-qa-label {
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #111 !important;
    display: block !important;
    line-height: 1.2 !important;
}
body.path-my #l3m-quickaccess-card .l3m-qa-sub {
    font-size: 11px !important;
    color: #C9A227 !important;
    font-weight: 600 !important;
}
body.path-my #l3m-quickaccess-card .l3m-qa-more {
    display: block !important;
    width: 100% !important;
    margin-top: 4px !important;
    background: #111 !important;
    color: #fff !important;
    font-weight: 700 !important;
    font-size: 13px !important;
    padding: 10px 0 !important;
    border-radius: 8px !important;
    border: none !important;
    cursor: pointer !important;
    text-align: center !important;
    text-decoration: none !important;
}

/* Cacher les anciens blocs Moodle sur /my/ (remplacés par notre layout) */
body.path-my .l3m-dash-hidden { display:none !important; }
</style>';

/* ── Dashboard /my/ JS ─────────────────────────────────────── */
if (strpos($PAGE->pagetype, 'my-index') !== false ||
    strpos($_SERVER['REQUEST_URI'] ?? '', '/my/') !== false) {

    $dash_bbb_courses = [];
    $bbb_records = $DB->get_records_sql(
        'SELECT cm.id as cmid, u.name, c.shortname, c.fullname
         FROM {url} u
         JOIN {course_modules} cm ON cm.instance=u.id AND cm.module=(SELECT id FROM {modules} WHERE name=\'url\')
         JOIN {course} c ON c.id=u.course
         WHERE c.id > 1 AND u.name LIKE \'%Classe virtuelle%\'
         ORDER BY c.sortorder ASC
         LIMIT 5'
    );
    foreach ($bbb_records as $row) {
        $dash_bbb_courses[] = [
            'label' => $row->shortname,
            'sub'   => shorten_text(strip_tags($row->name), 32),
            'href'  => '/mod/url/view.php?id=' . $row->cmid,
        ];
    }

    $dash_greeting_name = isset($USER->firstname) ? htmlspecialchars($USER->firstname) : '';
    setlocale(LC_TIME, 'fr_FR.UTF-8', 'fr_FR', 'fr');
    $dash_today = strftime('%A %d %B %Y');

    // Last visited course
    $dash_last_course = '';
    $last_log = $DB->get_record_sql(
        'SELECT c.fullname FROM {logstore_standard_log} l
         JOIN {course} c ON c.id=l.courseid
         WHERE l.userid=:uid AND l.courseid > 1
         ORDER BY l.timecreated DESC LIMIT 1',
        ['uid' => $USER->id]
    );
    if ($last_log) $dash_last_course = htmlspecialchars($last_log->fullname);

    // Hours total
    $dash_total_secs = 0;
    $gap = 30 * 60;
    $dash_hours_rows = [];
    foreach ($DB->get_records_select('course', 'id > 1', [], 'sortorder ASC', 'id,shortname') as $c) {
        $logs = $DB->get_fieldset_select('logstore_standard_log', 'timecreated',
            'userid=:uid AND courseid=:cid', ['uid'=>$USER->id,'cid'=>$c->id], 'timecreated ASC');
        $secs = 0;
        for ($i=1; $i<count($logs); $i++) {
            $d = $logs[$i]-$logs[$i-1];
            if ($d>0 && $d<=$gap) $secs+=$d;
        }
        if ($secs > 0) {
            $dash_total_secs += $secs;
            $h = floor($secs/3600); $m = floor(($secs%3600)/60);
            $dash_hours_rows[] = ['name'=>htmlspecialchars($c->shortname),
                'time'=> ($h>0 ? $h.'h '.($m<10?'0':'').$m.'min' : $m.' min')];
        }
    }
    $dash_total_h = floor($dash_total_secs/3600);
    $dash_total_m = floor(($dash_total_secs%3600)/60);
    $dash_total_fmt = $dash_total_h.'h '.$dash_total_m.'min';


    // Statistiques inscriptions / progression / terminés (seuil 80% activités)
    $dash_enrolled = 0; $dash_completed = 0; $dash_inprogress = 0;
    try {
        $dash_enrolled = (int)$DB->count_records_sql(
            'SELECT COUNT(DISTINCT e.courseid) FROM {user_enrolments} ue
             JOIN {enrol} e ON e.id = ue.enrolid
             JOIN {course} c ON c.id = e.courseid
             WHERE ue.userid = :uid AND c.id > 1',
            ['uid' => $USER->id]
        );
        // Terminé = >= 80% des activités visibles du cours complétées
        $all_courses = $DB->get_records_select('course', 'id > 1', [], '', 'id');
        foreach ($all_courses as $dc) {
            $total = $DB->count_records('course_modules', ['course'=>$dc->id, 'visible'=>1]);
            if ($total == 0) continue;
            $done = (int)$DB->count_records_sql(
                'SELECT COUNT(*) FROM {course_modules_completion} cmc
                 JOIN {course_modules} cm ON cm.id=cmc.coursemoduleid
                 WHERE cm.course=:cid AND cmc.userid=:uid AND cmc.completionstate > 0 AND cm.visible=1',
                ['cid'=>$dc->id, 'uid'=>$USER->id]
            );
            if ($total > 0 && round($done / $total * 100) >= 80) {
                $dash_completed++;
            }
        }
    } catch (Exception $e) {}
    $dash_inprogress = max(0, $dash_enrolled - $dash_completed);

    // Generateur de jauge SVG semicircle
    $l3m_gauge_html = function($num, $total, $label, $sublabel, $idx) {
        $progress = $total > 0 ? min(1.0, (float)$num / (float)$total) : 0;
        $offset   = round(198 * (1 - $progress), 1);
        $n = htmlspecialchars((string)$num);
        $l = htmlspecialchars($label);
        $s = htmlspecialchars($sublabel);
        return
            '<div class="l3m-gauge-item">'
           .'<svg viewBox="12 15 86 96" xmlns="http://www.w3.org/2000/svg" class="l3m-gauge-svg">'
           .'<defs><linearGradient id="lsg'.$idx.'" x1="0%" y1="0%" x2="100%" y2="0%">'
           .'<stop class="start-color" offset="0%" stop-color="#C9A227"/>'
           .'<stop class="end-color" offset="100%" stop-color="#b8921f"/>'
           .'</linearGradient></defs>'
           .'<path d="M30,90 A40,40 0 1,1 80,90" fill="transparent" stroke="#e8e4d8" stroke-opacity="1" stroke-width="10" stroke-linecap="round"/>'
           .'<path class="blue" stroke="url(#lsg'.$idx.')" stroke-dasharray="198" stroke-width="10" stroke-dashoffset="'.$offset.'" fill="none" d="M30,90 A40,40 0 1,1 80,90" stroke-linecap="round"/>'
           .'<text x="55" y="66" text-anchor="middle" font-size="22" font-weight="bold" fill="#111">'.$n.'</text>'
           .'<text x="55" y="104" text-anchor="middle" font-size="9" fill="#777">'.$l.'</text>'
           .'</svg>'
           .'<span class="l3m-gauge-sub">'.$s.'</span>'
           .'</div>';
    };

    $dash_mescourshtml =
        '<div id="l3m-mes-cours-card">'
       .'<div class="l3m-mc-title">MES COURS</div>'
       .'<div class="l3m-mc-gauges">'
       .$l3m_gauge_html($dash_enrolled,   max(1,$dash_enrolled), 'Tous les cours', 'Inscriptions totales', 1)
       .$l3m_gauge_html($dash_inprogress, max(1,$dash_enrolled), 'En cours',       'Cours en progression', 2)
       .$l3m_gauge_html($dash_completed,  max(1,$dash_enrolled), 'Terminés',     'Cours terminés',     3)
       .'</div>'
       .'<div class="l3m-mc-cap">🎓</div>'
       .'</div>';

    $dash_bbb_json   = json_encode($dash_bbb_courses);
    $dash_hours_json = json_encode($dash_hours_rows);


    // Injecter le bloc Mes cours (jauges SVG statiques) avant le script
    $html .= $dash_mescourshtml;
    $html .= <<<HTML
<script>
(function(){
    if (!document.body.classList.contains("path-my") &&
        window.location.pathname.indexOf("/my/") === -1) return;

    var BBB_LINKS  = {$dash_bbb_json};
    var HOURS_ROWS = {$dash_hours_json};
    var TOTAL_HRS  = "{$dash_total_fmt}";
    var GREETING   = "{$dash_greeting_name}";
    var TODAY_STR  = "{$dash_today}";
    var LAST_COURSE= "{$dash_last_course}";

    function buildDashLayout() {
        if (document.getElementById("l3m-dash-bottom")) return;

        /* Date en français via JS */
        var now = new Date();
        var jours = ["dimanche","lundi","mardi","mercredi","jeudi","vendredi","samedi"];
        var mois  = ["janvier","février","mars","avril","mai","juin","juillet","août","septembre","octobre","novembre","décembre"];
        var todayFr = jours[now.getDay()] + " " + now.getDate() + " " + mois[now.getMonth()] + " " + now.getFullYear();

        /* ── 1. Masquer TOUT le contenu existant dans #page sauf navbar ── */
        var ph = document.getElementById("page-header");
        if (ph) ph.style.setProperty("display","none","important");

        /* Masquer tous les enfants directs de #page-content et wrappers */
        var main = document.getElementById("page-content") ||
                   document.querySelector("[role=main]") ||
                   document.querySelector(".l3m-main-content");
        if (!main) return;

        /* Forcer section.content-col en pleine largeur (DOM reel du theme mb2nl) */
        var contentSection = document.querySelector("section.content-col");
        if (contentSection) {
            contentSection.style.setProperty("width","100%","important");
            contentSection.style.setProperty("max-width","100%","important");
            contentSection.style.setProperty("flex","0 0 100%","important");
            contentSection.style.setProperty("padding","0","important");
        }
        /* Cacher la colonne sidebar */
        var sidebarCol = document.querySelector("div.sidebar-col");
        if (sidebarCol) sidebarCol.style.setProperty("display","none","important");
        /* Le row parent sans marges */
        var themeRow = document.getElementById("theme-main-content");
        if (themeRow) {
            themeRow.style.setProperty("margin","0","important");
            themeRow.style.setProperty("width","100%","important");
        }
        /* container-fluid sans padding */
        var cf = document.querySelector(".container-fluid");
        if (cf) cf.style.setProperty("padding","0","important");

        /* Le bloc Mes cours (#l3m-mes-cours-card) est deja dans le DOM (injecte par PHP) */
        var mesCoursCard = document.getElementById("l3m-mes-cours-card");
        if (mesCoursCard && mesCoursCard.parentNode) {
            mesCoursCard.parentNode.removeChild(mesCoursCard);
        }

        /* Masquer tout l'existant */
        Array.prototype.forEach.call(main.children, function(child) {
            child.style.setProperty("display","none","important");
        });

        /* Cacher les éléments natifs Moodle chargés via AJAX après le layout */
        var mo = new MutationObserver(function() {
            document.querySelectorAll(".chart-wrap-outer,[data-block],.block_myoverview,.block_timeline,.block_recentlyaccesseditems").forEach(function(el) {
                el.style.setProperty("display","none","important");
            });
        });
        mo.observe(document.body, { childList: true, subtree: true });
        setTimeout(function(){ mo.disconnect(); }, 15000);

        /* Grille 3-col */
        var grid = document.createElement("div");
        grid.id = "l3m-dash-bottom";

        /* Col 1 : Greeting */
        var lastCourseHtml = LAST_COURSE
            ? "<p>Dernier cours : <strong>" + LAST_COURSE + "</strong></p>" : "";
        var col1 = document.createElement("div");
        col1.id = "l3m-greeting-card";
        col1.innerHTML =
            "<h2 class=\"l3m-greeting-name\">Bonjour " + GREETING + " 👋</h2>" +
            "<p>Aujourd'hui : <strong>" + todayFr + "</strong></p>" +
            lastCourseHtml +
            "<a href=\"/parcours.php\" class=\"l3m-cta-continue\">▶ Continuer la formation</a>";

        /* Col 2 : Heures */
        var rowsHtml = "";
        HOURS_ROWS.forEach(function(r) {
            rowsHtml += "<tr><td>" + r.name + "</td><td>" + r.time + "</td></tr>";
        });
        if (!rowsHtml) {
            rowsHtml = "<tr><td colspan=\"2\" style=\"color:#aaa;font-style:italic;padding:12px 4px;\">Aucune session enregistrée</td></tr>";
        }
        var col2 = document.createElement("div");
        col2.id = "l3m-hours-card";
        col2.innerHTML =
            "<div class=\"l3m-card-header\">" +
            "  <span class=\"l3m-card-title\">⏳ Heures de formation</span>" +
            "  <span class=\"l3m-total-badge\">Total : " + TOTAL_HRS + "</span>" +
            "</div>" +
            "<table><tbody>" + rowsHtml + "</tbody></table>" +
            "";

        /* Col 3 : Acces rapide */
        var qaHtml = "";
        var bbbList = BBB_LINKS.length ? BBB_LINKS : [];
        bbbList.slice(0, 4).forEach(function(b) {
            qaHtml +=
                "<a href=\"" + b.href + "\" class=\"l3m-qa-item\">" +
                "  <span class=\"l3m-qa-icon\">🔗</span>" +
                "  <span>" +
                "    <span class=\"l3m-qa-label\">Classe virtuelle</span>" +
                "    <span class=\"l3m-qa-sub\">" + b.sub + "</span>" +
                "  </span>" +
                "</a>";
        });
        if (!qaHtml) {
            qaHtml = "<p style=\"font-size:13px;color:#aaa;font-style:italic;\">Aucune classe virtuelle disponible.</p>";
        }
        var col3 = document.createElement("div");
        col3.id = "l3m-quickaccess-card";
        col3.innerHTML =
            "<div class=\"l3m-card-title\">⚡ Accès rapide</div>" +
            qaHtml +
            "<a href=\"/course/index.php\" class=\"l3m-qa-more\">Voir plus d'éléments</a>";

        grid.appendChild(col1);
        grid.appendChild(col2);
        grid.appendChild(col3);

        /* Wrapper centre : Mes cours + grille */
        var wrapper = document.createElement("div");
        wrapper.id = "l3m-dash-wrapper";
        if (mesCoursCard) {
            mesCoursCard.style.setProperty("display","block","important");
            wrapper.appendChild(mesCoursCard);
        }
        wrapper.appendChild(grid);
        main.appendChild(wrapper);
        main.style.setProperty("padding","0","important");

        /* Masquer les anciens blocs natifs Moodle */
        document.querySelectorAll(".block_recentlyaccesseditems,.block_timeline,.block_myoverview,[data-block]").forEach(function(bl) {
            bl.style.setProperty("display","none","important");
        });

        repaintSvg();
        document.body.classList.add("l3m-ready");
    }

    /* Fallback : révèle le contenu même si buildDashLayout échoue */
    setTimeout(function(){ document.body.classList.add("l3m-ready"); }, 2500);

    function repaintSvg() {
        document.querySelectorAll(".dash-chart path.blue").forEach(function(p) {
            p.setAttribute("stroke","#C9A227");
        });
        document.querySelectorAll(".dash-chart path:not(.blue):not(.gradbg)").forEach(function(p) {
            p.setAttribute("stroke","#e8e4d8");
            p.setAttribute("stroke-opacity","1");
        });
        document.querySelectorAll(".dash-chart .start-color").forEach(function(s) {
            s.setAttribute("stop-color","#C9A227");
        });
        document.querySelectorAll(".dash-chart .end-color").forEach(function(s) {
            s.setAttribute("stop-color","#b8921f");
        });
        document.querySelectorAll(".dash-chart text").forEach(function(t) {
            t.setAttribute("fill","#111");
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function() { buildDashLayout(); });
    } else {
        buildDashLayout();
    }
    [500, 1200].forEach(function(ms){ setTimeout(repaintSvg, ms); });
})();
</script>
HTML;
}

$html .= '<script>(function(){
/* ── Page catalogue cours — harmonisation L3M ───────────────────── */
var isCatPage = document.body.classList.contains("path-course-index");

if (isCatPage) {
    /* Catégorie courante depuis URL */
    var catId = (window.location.search.match(/categoryid=(\d+)/) || [])[1] || "";
    var catLabels = {"3": "CCP1 — Animer des actions de formation", "4": "CCP2 — Veille et développement", "5": "Examen de certification ECSR"};
    var catSubs   = {"3": "5 modules · Compétences pédagogiques et techniques d\'animation", "4": "6 modules · Veille professionnelle et gestion d\'école", "5": "Simulations d\'épreuves et préparation à l\'évaluation finale"};
    var catLabel  = catLabels[catId] || "Catalogue des formations";
    var catSub    = catSubs[catId]   || "Formation TP ECSR — RNCP 35329";

    function buildCatHero() {
        var existing = document.getElementById("l3m-cat-hero");
        if (existing) return;

        /* Point d\'insertion : AVANT #page-header (= tout en haut sous la navbar) */
        var pageHdr = document.getElementById("page-header");
        var insertBefore = pageHdr || document.getElementById("page-content");
        if (!insertBefore) return;

        /* Extraire le fil d\'Ariane depuis #page-header avant de le cacher */
        var bcItems = [];
        if (pageHdr) {
            var bcEl = pageHdr.querySelector("ol.breadcrumb, nav ol, .breadcrumb");
            if (bcEl) {
                bcEl.querySelectorAll("li").forEach(function(li) {
                    var a = li.querySelector("a");
                    var txt = li.textContent.trim().replace(/[›>\/]/g, "").trim();
                    if (!txt) return;
                    if (li.querySelector("svg, .icon, i.fa")) {
                        bcItems.push({text: "Accueil", href: "/"});
                    } else {
                        bcItems.push({text: txt, href: a ? a.href : null});
                    }
                });
            }
        }
        var bcHtml = "";
        if (bcItems.length > 0) {
            bcHtml = "<nav style=\"margin-bottom:16px;display:flex;align-items:center;gap:6px;flex-wrap:wrap\">";
            bcItems.forEach(function(item, i) {
                if (i > 0) bcHtml += "<span style=\"color:rgba(255,255,255,.3);font-size:12px\">›</span>";
                var isLast = i === bcItems.length - 1;
                if (item.href && !isLast) {
                    bcHtml += "<a href=\"" + item.href + "\" style=\"color:rgba(255,255,255,.45);font-size:12px;text-decoration:none\" onmouseover=\"this.style.color=\'#C9A227\'\" onmouseout=\"this.style.color=\'rgba(255,255,255,.45)\'\">" + item.text + "</a>";
                } else {
                    bcHtml += "<span style=\"color:rgba(255,255,255,.8);font-size:12px;font-weight:600\">" + item.text + "</span>";
                }
            });
            bcHtml += "</nav>";
        }

        /* Cacher l\'ancien page-header (doublon vagues + Catégorie) */
        if (pageHdr) pageHdr.style.setProperty("display", "none", "important");

        var hero = document.createElement("div");
        hero.id = "l3m-cat-hero";
        hero.style.cssText = [
            "background:linear-gradient(135deg,#111 0%,#1c1c1c 60%,#222 100%)",
            "border-bottom:3px solid #C9A227",
            "padding:28px 48px 36px",
            "position:relative",
            "overflow:hidden"
        ].join(";");
        hero.innerHTML = [
            "<div style=\"position:absolute;top:-40px;right:-60px;width:320px;height:320px;",
            "background:radial-gradient(circle,rgba(201,162,39,.12) 0%,transparent 70%);pointer-events:none\"></div>",
            bcHtml,
            "<span style=\"display:inline-block;font-size:11px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;",
            "color:#C9A227;margin-bottom:10px\">Formation ECSR · RNCP 35329</span>",
            "<h1 style=\"margin:0 0 10px;font-size:1.8rem;font-weight:800;color:#fff;line-height:1.2\">",
            catLabel, "</h1>",
            "<p style=\"margin:0 0 24px;font-size:14px;color:rgba(255,255,255,.6);line-height:1.6\">", catSub, "</p>",
            "<div style=\"display:flex;gap:12px;flex-wrap:wrap\">",
            "<a href=\"/parcours.php\" style=\"display:inline-flex;align-items:center;gap:6px;",
            "background:transparent;border:1.5px solid rgba(255,255,255,.2);color:rgba(255,255,255,.75);",
            "padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none\">← Toutes les formations</a>",
            "<a href=\"/my/\" style=\"display:inline-flex;align-items:center;gap:6px;",
            "background:#C9A227;color:#111;",
            "padding:8px 20px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none\">Mon tableau de bord</a>",
            "</div>"
        ].join("");
        insertBefore.parentNode.insertBefore(hero, insertBefore);
    }

    /* Supprimer les overlays colorés aléatoires du thème mb2nl */
    function fixCourseImageOverlays() {
        /* Les overlays sont souvent des pseudo-éléments ou des div enfants avec background coloré */
        document.querySelectorAll(".courseimage").forEach(function(img) {
            /* Forcer overflow hidden pour clip */
            img.style.setProperty("overflow", "hidden", "important");
            img.style.setProperty("border-radius", "0", "important");
            /* Chercher les divs enfants avec une couleur de fond (overlay) */
            img.querySelectorAll("div").forEach(function(overlay) {
                var bg = overlay.style.backgroundColor || getComputedStyle(overlay).backgroundColor;
                /* Si c\'est un overlay coloré (pas transparent), remplacer par sombre */
                if (bg && bg !== "rgba(0, 0, 0, 0)" && bg !== "transparent") {
                    overlay.style.setProperty("background-color", "rgba(0,0,0,0.45)", "important");
                    overlay.style.setProperty("mix-blend-mode", "multiply", "important");
                }
            });
            /* Appliquer le style image */
            img.style.setProperty("height", "180px", "important");
            img.style.setProperty("background-size", "cover", "important");
            img.style.setProperty("background-position", "center", "important");
        });

        /* Rebadger en "Mon cours" */
        document.querySelectorAll("*").forEach(function(b) {
            var t = b.textContent.trim();
            if ((t === "Mon module" || t === "Inscrit" || t === "Enrolled" || t === "In progress") && b.children.length === 0) {
                b.textContent = "Mon cours";
                b.style.setProperty("background", "#C9A227", "important");
                b.style.setProperty("color", "#111", "important");
                b.style.setProperty("font-weight", "700", "important");
                b.style.setProperty("border-radius", "20px", "important");
                b.style.setProperty("font-size", "11px", "important");
                b.style.setProperty("padding", "3px 12px", "important");
            }
        });

        /* Barres de progression → gold */
        document.querySelectorAll(".progress-bar").forEach(function(pb) {
            pb.style.setProperty("background", "#C9A227", "important");
        });
    }

    function buildParcoursFilter() {
        /* Renommer "Filtres" → "Filtre" */
        document.querySelectorAll("button,a,[role=button]").forEach(function(el){
            if(el.childNodes){
                el.childNodes.forEach(function(node){
                    if(node.nodeType===3 && node.nodeValue.trim()==="Filtres")
                        node.nodeValue = "Filtre";
                });
            }
            if((el.textContent||"").trim()==="Filtres") el.textContent="Filtre";
        });
    }

    function hideCategoryBloc() {
        var HIDE_TEXTS = ["Catégorie","Catégories de cours","Categories"];
        document.querySelectorAll("h1,h2,h3,h4,h5,h6,button,a,span,div,label,select").forEach(function(el){
            var t = (el.textContent||"").trim();
            if (HIDE_TEXTS.indexOf(t) !== -1) {
                el.style.setProperty("display","none","important");
                if (el.parentElement) el.parentElement.style.setProperty("display","none","important");
            }
        });
        document.querySelectorAll("h3.categoryname,h4.categoryname,.coursecat,#action_bar .navitem:not(.dropdown),[data-filterregion=\"filter\"],.mb2nl-allcats-btn").forEach(function(el){
            el.style.setProperty("display","none","important");
        });
    }

    function initCatPage() {
        buildCatHero();
        fixCourseImageOverlays();
        buildParcoursFilter();
        hideCategoryBloc();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function(){
            initCatPage();
            setTimeout(fixCourseImageOverlays, 400);
            setTimeout(hideCategoryBloc, 500);
            setTimeout(fixCourseImageOverlays, 1200);
            setTimeout(hideCategoryBloc, 1500);
            setTimeout(fixCourseImageOverlays, 2500);
        });
    } else {
        initCatPage();
        setTimeout(fixCourseImageOverlays, 400);
        setTimeout(hideCategoryBloc, 500);
        setTimeout(fixCourseImageOverlays, 1200);
        setTimeout(hideCategoryBloc, 1500);
        setTimeout(fixCourseImageOverlays, 2500);
    }
    /* MutationObserver — attrape les cartes chargées dynamiquement */
    var mo = new MutationObserver(function(){ fixCourseImageOverlays(); hideCategoryBloc(); });
    mo.observe(document.body, {childList:true, subtree:true});
    setTimeout(function(){ mo.disconnect(); }, 8000);
}

/* ── Hover cartes — clip overflow pour que le zoom image reste propre */
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

/* Remplacer le tiret cadratin par un espace dans tous les noeuds texte */
function replaceEmDash(root){
    var walker = document.createTreeWalker(root||document.body, NodeFilter.SHOW_TEXT, null, false);
    var node;
    while((node = walker.nextNode())){
        if(node.nodeValue.indexOf("—") !== -1){
            node.nodeValue = node.nodeValue.replace(/\s*—\s*/g, " ");
        }
    }
}
if(document.readyState === "loading"){
    document.addEventListener("DOMContentLoaded", function(){ replaceEmDash(); });
} else {
    replaceEmDash();
}
var _emDashObs = new MutationObserver(function(muts){
    muts.forEach(function(m){
        m.addedNodes.forEach(function(n){
            if(n.nodeType === 3 && n.nodeValue.indexOf("—") !== -1){
                n.nodeValue = n.nodeValue.replace(/\s*—\s*/g, " ");
            } else if(n.nodeType === 1){
                replaceEmDash(n);
            }
        });
    });
});
_emDashObs.observe(document.body, {childList:true, subtree:true});
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

// [L3M] Bouton "Mon espace" dans le header pour les élèves
if (isloggedin() && !isguestuser()) {
    $l3m_sr = $DB->get_field('role', 'id', ['shortname' => 'student']);
    if ($l3m_sr && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $l3m_sr])) {
        $l3m_url = $CFG->wwwroot . '/espace-eleve.php';
        echo '<style>
.l3m-eleve-btn{display:inline-flex;align-items:center;gap:6px;background:#2E9488;color:#fff!important;
padding:7px 16px;border-radius:20px;font-size:13px;font-weight:700;text-decoration:none!important;
white-space:nowrap;transition:background .2s;margin-left:10px;order:99}
.l3m-eleve-btn:hover{background:#1C5F58;color:#fff!important}
</style>
<script>
(function(){
  function injectBtn(){
    var fc=document.querySelector("#master-header .flexcols");
    if(!fc||document.querySelector(".l3m-eleve-btn"))return;
    var a=document.createElement("a");
    a.href="' . $l3m_url . '";
    a.className="l3m-eleve-btn";
    a.innerHTML=\'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Mon espace\';
    fc.appendChild(a);
  }
  if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",injectBtn);}
  else{injectBtn();}
})();
</script>';
    }
}

if ($quickview = theme_mb2nl_theme_setting($PAGE, 'quickview')) {
    $PAGE->requires->js_call_amd('theme_mb2nl/quickview', 'init', [$quickview]);
}

if (has_capability('moodle/site:config', context_system::instance())) {
    $PAGE->requires->js_call_amd('theme_mb2nl/settings', 'toggleAll');
    $PAGE->requires->js_call_amd('theme_mb2nl/settings', 'toggleCat');
    $PAGE->requires->js_call_amd('theme_mb2nl/settings', 'spectrumInit');
}

$PAGE->requires->js_amd_inline('require([\'theme_boost/loader\']);');

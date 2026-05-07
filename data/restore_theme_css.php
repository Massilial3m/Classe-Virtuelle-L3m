<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');

cli_heading('Restauration CSS thème Moove – L3M Formation');

$scsspre = <<<'CSS'
.navbar {
    background-color: #1A1A1A !important;
    border-bottom: 2px solid #C9A227 !important;
}
.navbar .nav-link,
.navbar-brand,
.primary-navigation .nav-link,
.navbar .navbar-nav .nav-link,
.navbar .editmode-switch-form label,
.navbar .form-check-label {
    color: #FFFFFF !important;
}
.navbar .nav-link:hover { color: #C9A227 !important; }
.navbar-toggler { filter: invert(1) !important; }
.userinitials { background-color: #C9A227 !important; color: #1A1A1A !important; font-weight: bold !important; }

.navbar .userbutton .fa-chevron-down,
.navbar .userbutton [data-region="caret"],
.navbar .userbutton::after,
.navbar .usermenu .fa-chevron-down,
.navbar .usermenu-toggle::after,
.navbar [data-toggle="dropdown"]::after,
.navbar .dropdown-toggle::after {
    color: #FFFFFF !important;
    border-top-color: #FFFFFF !important;
    border-bottom-color: #FFFFFF !important;
}
CSS;

$scss = <<<'CSS'
/* Styles frontpage pour utilisateurs connectés */
#page-site-index #feature {
    background-color: var(--bs-body-bg);
    padding: 4rem 0;
    margin-left: -15px;
    margin-right: -15px;
}
#page-site-index #feature .card {
    border: 1px solid #e2e8f0;
    background-color: var(--moove-bg-white);
}
#page-site-index #feature .icon-lg {
    width: 6rem;
    height: 6rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    background-color: $brand-primary;
    border-radius: 50%;
}
#page-site-index #feature .icon-lg img {
    max-width: 60%;
    filter: brightness(0) invert(1);
}

/* FAQ */
#page-site-index #faq {
    padding: 4rem 0;
}
#page-site-index #faq .accordion-button:not(.collapsed) {
    background-color: $brand-primary;
    color: #fff;
}
#page-site-index #faq .accordion-item {
    border: 1px solid $brand-primary;
}
#page-site-index #faq h2:not(.accordion-header) {
    text-align: center !important;
    text-decoration: none !important;
    font-weight: bold !important;
    padding-bottom: 12px !important;
    display: block !important;
}
#page-site-index #faq h2:not(.accordion-header)::after {
    content: "" !important;
    display: block !important;
    width: 60px !important;
    height: 4px !important;
    background-color: #C9A227 !important;
    margin: 8px auto 0 auto !important;
}

/* Dropdown */
.dropdown-item { color: #1A1A1A !important; }
.dropdown-item:hover, .dropdown-item:focus {
    background-color: $brand-primary !important;
    color: #1A1A1A !important;
}

/* Section chiffres */
#page-site-index #numbers {
    background-color: #1A1A1A !important;
    padding: 5.625rem 0 !important;
}
#page-site-index #numbers .sectionheading,
#page-site-index #numbers h2,
#page-site-index #numbers p { color: #FFFFFF !important; }
#page-site-index #numbers .rate-box,
#page-site-index #numbers .rate-box.bg-primary,
#page-site-index #numbers .rate-box.bg-cloudburst {
    background-color: #C9A227 !important;
    box-shadow: 0 6px 0 rgba(201,162,39,0.5) !important;
    border-radius: 12px !important;
    padding: 25px 35px !important;
}
#page-site-index #numbers .rate-box h3 {
    color: #1A1A1A !important;
    font-size: 50px !important;
    line-height: 1.2 !important;
    margin-bottom: 15px !important;
}
#page-site-index #numbers .rate-box p {
    color: #1A1A1A !important;
    font-size: 15px !important;
    line-height: 1.4 !important;
    margin-bottom: 0 !important;
}

/* Footer – LinkedIn doré */
.footer-info-social a[href*="linkedin"],
footer .social-links a[href*="linkedin"],
#page-footer a[href*="linkedin"] {
    background-color: #C9A227 !important;
    border-color: #C9A227 !important;
}
.footer-info-social a[href*="linkedin"] i,
footer .social-links a[href*="linkedin"] i,
#page-footer a[href*="linkedin"] i,
.footer-info-social a[href*="linkedin"] .fa,
#page-footer a[href*="linkedin"] .fa {
    color: #FFFFFF !important;
}

/* Footer – bouton Contact doré */
.footer-info-social a[href*="mailto"],
footer .social-links a[href*="mailto"],
#page-footer a[href*="mailto"] {
    background-color: #C9A227 !important;
    border-color: #C9A227 !important;
}
.footer-info-social a[href*="mailto"] i,
footer .social-links a[href*="mailto"] i,
#page-footer a[href*="mailto"] i {
    color: #FFFFFF !important;
}

/* Masquer section copyright Conecti.me */
.copyright { display: none !important; }

/* Fil d'Ariane */
#page-navbar,
.moove-breadcrumb {
    display: block !important;
    width: 100% !important;
    padding: 6px 0 !important;
}
.moove-breadcrumb .breadcrumb {
    background: transparent !important;
    padding: 0 !important;
    margin: 0 !important;
    flex-wrap: wrap !important;
}
.moove-breadcrumb .breadcrumb-item a {
    color: #C9A227 !important;
    text-decoration: none !important;
    font-size: 0.875rem !important;
}
.moove-breadcrumb .breadcrumb-item a:hover {
    text-decoration: underline !important;
    color: #C9A227 !important;
}
.moove-breadcrumb .breadcrumb-item.active {
    color: #555555 !important;
    font-size: 0.875rem !important;
}
.moove-breadcrumb .breadcrumb-item + .breadcrumb-item::before {
    color: #C9A227 !important;
    content: "›" !important;
}

/* Responsive – espacement boxes chiffres */
@media (max-width: 768px) {
    #page-site-index #numbers .rate-box {
        margin-bottom: 32px !important;
    }
    #page-site-index #numbers [class*="col-"],
    #page-site-index #numbers .col {
        padding-bottom: 16px !important;
    }
    .idnumber {
        display: none !important;
    }
}

/* Responsive – barre de recherche */
@media (max-width: 992px) {
    #page-course-management .tertiary-navigation .navitem.ms-auto,
    .tertiary-navigation .navitem.ms-auto,
    .navitem.ms-auto {
        flex: 0 0 100% !important;
        width: 100% !important;
        margin-left: 0 !important;
        margin-top: 8px !important;
    }
    .tertiary-navigation .d-flex,
    #page-course-management .d-flex.align-items-center {
        flex-wrap: wrap !important;
    }
    .navitem.ms-auto .input-group,
    .navitem.ms-auto form,
    .navitem.ms-auto .simplesearchform {
        width: 100% !important;
    }
    .navitem.ms-auto input[type="text"],
    .navitem.ms-auto .form-control {
        flex: 1 !important;
        min-width: 0 !important;
    }
}

/* Page connexion – design sombre L3M */
#page-login-index body,
body#page-login-index {
    background-color: #1A1A1A !important;
}
#page-login-index #page-wrapper,
#page-login-index #page {
    background-color: #1A1A1A !important;
}
#page-login-index .login-container,
#page-login-index .card,
#page-login-index .login-card {
    background-color: #2A2A2A !important;
    border: none !important;
    border-radius: 12px !important;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5) !important;
    padding: 40px 36px !important;
}
#page-login-index h2,
#page-login-index .login-heading,
#page-login-index .card-title,
#page-login-index p,
#page-login-index label,
#page-login-index .form-label {
    color: #FFFFFF !important;
}
#page-login-index input[type="text"],
#page-login-index input[type="password"],
#page-login-index input[type="email"] {
    background-color: #FFFFFF !important;
    border: 2px solid #C9A227 !important;
    border-radius: 6px !important;
    color: #1A1A1A !important;
}
#page-login-index input[type="text"]:focus,
#page-login-index input[type="password"]:focus {
    border-color: #C9A227 !important;
    box-shadow: 0 0 0 3px rgba(201,162,39,0.3) !important;
}
#page-login-index .btn-primary,
#page-login-index [type="submit"],
#page-login-index .btn[name="loginbtn"] {
    background-color: #C9A227 !important;
    border-color: #C9A227 !important;
    color: #1A1A1A !important;
    font-weight: bold !important;
    border-radius: 6px !important;
}
#page-login-index a {
    color: #C9A227 !important;
}
#page-login-index .form-text,
#page-login-index small {
    color: #AAAAAA !important;
}
CSS;

$footer_js = <<<'JS'
<script>
setTimeout(function() {
    try {
        if (window.location.pathname.indexOf("/mod/") === -1) return;
        var courseMatch = document.body.className.match(/course-(\d+)/);
        if (!courseMatch) return;
        var courseUrl = M.cfg.wwwroot + "/course/view.php?id=" + courseMatch[1];
        var div = document.createElement("div");
        div.style.cssText = "padding:10px 20px 0 20px;";
        div.innerHTML = "<a href='" + courseUrl + "' style='background-color:#C9A227;color:#1A1A1A;font-weight:bold;padding:8px 18px;border-radius:6px;text-decoration:none;display:inline-block;'>← Retour au cours</a>";
        var main = document.getElementById("region-main");
        if (main) main.insertBefore(div, main.firstChild);
    } catch(e) {}
}, 800);
</script>
JS;

set_config('scsspre', $scsspre, 'theme_moove');
set_config('scss', $scss, 'theme_moove');
set_config('additionalhtmlfooter', $footer_js);
purge_all_caches();

cli_writeln('CSS thème restauré avec succès.');

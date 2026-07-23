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

global $PAGE, $OUTPUT;

require_once(__DIR__ . '/../../../../l3m_branding.php');
$brand = l3m_get_branding();

$socilatt = theme_mb2nl_theme_setting($PAGE, 'socialtt') == 1 ? 'top' : '';
$footct = theme_mb2nl_format_txt(theme_mb2nl_theme_setting($PAGE, 'foottext'), FORMAT_HTML);
$html = '';

$html .= '<footer class="login-footer">';
$html .= '<div class="container-fluid">';
$html .= '<div class="row">';
$html .= '<div class="col-md-12">';
$html .= '<div class="footer-inner">';
$html .= '<p class="mb-2">' . $footct . '</p>';
$html .= theme_mb2nl_language_list('footer');

if (theme_mb2nl_theme_setting($PAGE, 'socialfooter') == 1) {
    $html .= '<div class="footer-social">';
    $html .= theme_mb2nl_social_icons(['tt' => $socilatt, 'pos' => 'footer']);
    $html .= '</div>';
}

$html .= '</div>';
$html .= '</div>';
$html .= '</div>';
$html .= '</div>';
$html .= '</footer>';
$html .= '</div> <!--  end pagelayout-content -->';
$html .= '</div> <!--  end pagelayout-b -->';
$html .= '</div> <!--  end .pagelayout -->';
$html .= $OUTPUT->theme_part('region_adminblock');
$html .= '</div><!-- end #page-outer -->';
$html .= $OUTPUT->theme_part('course_panel');
$html .= theme_mb2nl_note_moadalform();
$html .= theme_mb2nl_ganalytics();
$html .= $OUTPUT->standard_end_of_body_html();
$html .= <<<HTML
<style>
/* ===== L3M LOGIN PAGE (maquette carte centrée) ===== */
body.pagelayout-login,
body.pagelayout-login #page-outer,
body.pagelayout-login #page {
  background:radial-gradient(circle at 30% 20%, #1e3345 0%, #0f1b28 55%, #0a141e 100%) !important;
  min-height:100vh !important;
}

/* Header logo d'origine masqué : le logo vit dans la carte (Mustache) */
body.pagelayout-login .login-header { display:none !important; }

body.pagelayout-login .pagelayout {
  display:block !important; min-height:100vh !important;
  margin:0 !important; max-width:100% !important;
}
body.pagelayout-login .pagelayout-b {
  background:transparent !important; min-height:100vh !important;
  flex:1 1 100% !important; width:100% !important; max-width:100% !important;
}
body.pagelayout-login .pagelayout-content {
  display:flex !important; align-items:center !important; justify-content:center !important;
  min-height:100vh !important; padding:40px 20px !important; background:transparent !important;
  flex:1 1 100% !important; width:100% !important; max-width:100% !important;
  position:relative !important;
}

body.pagelayout-login #main-content,
body.pagelayout-login #main-content > .container-fluid,
body.pagelayout-login #theme-main-content,
body.pagelayout-login section.content-col,
body.pagelayout-login #region-main {
  display:flex !important; flex-direction:column !important;
  align-items:center !important; width:100% !important;
  padding-left:0 !important; padding-right:0 !important;
}
body.pagelayout-login #page-content {
  display:flex !important; align-items:center !important; justify-content:center !important;
  width:100% !important; min-height:60vh !important;
}
body.pagelayout-login #page-content > * {
  width:100% !important; margin-left:auto !important; margin-right:auto !important;
}

/* Le vrai conteneur du formulaire est .loginform > .loginleft (pas .loginpanel,
   qui n'existe pas dans le DOM réel de ce thème). Le thème impose plusieurs
   largeurs figées imbriquées en amont (.pagelayout-b, .pagelayout-content,
   .loginform .loginleft{flex:0 0 40%}...) pour sa mise en page 2 colonnes
   native — plutôt que neutraliser chaque niveau (fragile, dépend de la config
   du thème), on centre .loginform en position absolue sur toute la hauteur de
   .pagelayout-content : indépendant du reste de la chaîne flex.
   .loginright (colonne secondaire signup/instructions) est vide sur ce site,
   on la masque. */
.loginform,
body.pagelayout-login .loginform {
  position:absolute !important; left:50% !important; top:50% !important;
  transform:translate(-50%,-50%) !important;
  display:flex !important; flex-flow:row wrap !important; justify-content:center !important;
  align-items:flex-start !important; width:auto !important; max-width:100% !important; margin:0 !important;
}
.loginform .loginright,
body.pagelayout-login .loginright { display:none !important; }
.loginform .loginleft,
body.pagelayout-login .loginleft {
  flex:0 1 420px !important; max-width:420px !important; width:100% !important;
  background:#fff !important; border:none !important; border-radius:20px !important;
  padding:40px 36px !important; margin:0 auto !important;
  box-shadow:0 24px 64px rgba(0,0,0,.45) !important; color:#24333C !important;
}
body.pagelayout-login .alert {
  background:transparent !important; border:none !important; box-shadow:none !important;
  border-radius:0 !important; padding:0 !important; color:inherit !important;
}

/* Marque */
.l3m-login-brand { display:flex; align-items:center; justify-content:center; gap:8px; margin-bottom:10px; }
.l3m-login-brand-name { font-weight:800; font-size:19px; color:#24333C; letter-spacing:.02em; }
.l3m-login-brand-name em { font-style:italic; color:{$brand['orange']}; font-weight:400; font-family:Georgia,serif; }
body.pagelayout-login .l3m-login-tagline {
  text-align:center; color:#68767E !important; font-size:13.5px !important;
  line-height:1.5 !important; margin:0 0 22px !important;
}

/* Champs (sans icone a gauche : le navigateur affiche deja nativement sa
   propre icone pour les champs identifiant/mot de passe, superposee a toute
   icone perso au meme endroit — inutile de dupliquer/entrer en conflit) */
body.pagelayout-login .l3m-has-icon { position:relative !important; }
body.pagelayout-login input[type=text],
body.pagelayout-login input[type=password],
body.pagelayout-login input[type=email] {
  background:#F1F0EA !important; color:#24333C !important;
  border:1.5px solid transparent !important; border-radius:10px !important;
  padding:11px 14px 11px 34px !important; width:100% !important;
}
body.pagelayout-login input[type=text]:focus,
body.pagelayout-login input[type=password]:focus {
  border-color:{$brand['teal']} !important; outline:none !important;
  box-shadow:0 0 0 3px rgba(46,148,136,.14) !important;
}
body.pagelayout-login .login-form-password input[type=password],
body.pagelayout-login .login-form-password input[type=text] {
  padding-right:38px !important;
}
/* Bouton afficher/masquer mot de passe : le JS du theme remplace le texte de
   cet element a chaque clic (.text()), impossible d'y mettre une icone HTML
   directement sans qu'elle soit ecrasee. On masque le texte natif et on
   affiche une icone oeil / oeil barre en fond CSS, pilotee par la classe
   .istext que ce meme JS ajoute/retire. */
body.pagelayout-login .pass_show {
  display:inline-block !important;
  position:absolute !important; right:12px !important; top:50% !important;
  margin-top:0 !important; margin-bottom:0 !important;
  transform:translateY(-50%) !important; left:auto !important;
  width:19px !important; height:19px !important; cursor:pointer !important;
  font-size:0 !important; line-height:0 !important; color:transparent !important; opacity:.65 !important;
  background-repeat:no-repeat !important; background-position:center !important; background-size:19px 19px !important;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2368767E' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/%3E%3Ccircle cx='12' cy='12' r='3'/%3E%3C/svg%3E") !important;
}
body.pagelayout-login .pass_show:hover { opacity:1 !important; }
body.pagelayout-login .pass_show.istext {
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2368767E' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.53 18.53 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24'/%3E%3Cline x1='1' y1='1' x2='23' y2='23'/%3E%3C/svg%3E") !important;
}
body.pagelayout-login input::placeholder { color:#9aa3a8 !important; }
body.pagelayout-login input:-webkit-autofill,
body.pagelayout-login input:-webkit-autofill:hover,
body.pagelayout-login input:-webkit-autofill:focus {
  -webkit-box-shadow:0 0 0 1000px #F1F0EA inset !important;
  -webkit-text-fill-color:#24333C !important;
  transition:background-color 9999s ease-in-out 0s;
}
body.pagelayout-login label { color:#68767E !important; font-size:12.5px !important; }

/* Se souvenir de moi / mot de passe oublié */
body.pagelayout-login .l3m-login-remember-row {
  display:flex !important; align-items:center !important; justify-content:space-between !important;
  flex-wrap:wrap; gap:8px; margin-top:2px !important;
}
.l3m-login-remember { display:flex; align-items:center; gap:6px; font-size:12.5px !important; color:#68767E !important; cursor:pointer; margin:0 !important; }
.l3m-login-remember input[type=checkbox] { width:14px; height:14px; accent-color:{$brand['teal']}; }
body.pagelayout-login a.forgetpass,
body.pagelayout-login .forgetpass,
body.pagelayout-login .l3m-login-remember-row a { color:{$brand['teal']} !important; font-size:12.5px !important; font-weight:600; }

body.pagelayout-login #loginbtn,
body.pagelayout-login [type=submit] {
  background:{$brand['orange']} !important; color:#24333C !important;
  font-weight:800 !important; border:none !important;
  border-radius:10px !important; font-size:15px !important;
  padding:13px !important; letter-spacing:.02em !important; width:100% !important;
}
body.pagelayout-login #loginbtn:hover { background:{$brand['orange_d']} !important; }

.l3m-login-sep { display:flex; align-items:center; gap:10px; margin:18px 0 14px; color:#9aa3a8; font-size:12px; }
.l3m-login-sep::before, .l3m-login-sep::after { content:''; flex:1; height:1px; background:#E4E1D6; }

body.pagelayout-login .cookies-notice {
  text-align:center !important; font-size:11.5px !important; color:#9aa3a8 !important;
  border-top:1px solid #E4E1D6 !important; padding-top:16px !important; margin-top:6px !important;
}
body.pagelayout-login .cookies-notice a { color:#68767E !important; text-decoration:underline; }

body.pagelayout-login .login-footer { display:none !important; }

/* Pages secondaires (mot de passe oublie, inscription) : pas de .loginform
   dans le DOM (template Mustache different de login/index.php), on habille
   donc #page-content > [role=main] en carte blanche equivalente. Scope via
   data-ptype (present sur #page) pour ne jamais toucher login-index, deja
   gere par .loginform ci-dessus. */
body.pagelayout-login #page[data-ptype="login-forgot_password"] #page-content > [role="main"],
body.pagelayout-login #page[data-ptype="login-signup"] #page-content > [role="main"] {
  background:#fff !important; border-radius:20px !important;
  padding:40px 36px !important; max-width:460px !important; width:100% !important;
  box-shadow:0 24px 64px rgba(0,0,0,.45) !important; color:#24333C !important;
}
body.pagelayout-login #page[data-ptype="login-forgot_password"] .box.generalbox,
body.pagelayout-login #page[data-ptype="login-signup"] .box.generalbox {
  color:#68767E !important; font-size:13.5px !important; line-height:1.5 !important;
  padding:0 0 18px !important; text-align:center !important;
}
body.pagelayout-login #page[data-ptype="login-forgot_password"] h3,
body.pagelayout-login #page[data-ptype="login-signup"] h3 {
  font-size:15px !important; font-weight:800 !important; color:#24333C !important;
  margin:18px 0 10px !important;
}
body.pagelayout-login #page[data-ptype="login-forgot_password"] .fitem,
body.pagelayout-login #page[data-ptype="login-signup"] .fitem { margin-bottom:14px !important; }
</style>
<script>
(function(){
  function buildLoginLayout(){
    var btn = document.getElementById('loginbtn');
    if (btn && !btn.dataset.l3mDone) {
      btn.textContent = 'Connexion';
      btn.dataset.l3mDone = '1';
    }

    // Marque multi-clients : le bloc de marque de login/index.php vient du
    // Mustache (texte/couleurs figes a la generation) — on le met a jour ici
    // cote client pour refleter le brand du domaine courant. Si un logo image
    // existe pour ce brand, il remplace entierement l'icone+texte (comme dans
    // les 3 tableaux de bord), sinon on garde l'icone SVG recoloree + le nom.
    var brandLogoUrl = '{$brand['logo']}';
    var brandIconHtml = brandLogoUrl
      ? '<img src="' + brandLogoUrl + '" alt="{$brand['name']}" style="height:44px;width:auto">'
      : '<span class="l3m-login-brand-icon" aria-hidden="true">' +
        '<svg width="26" height="20" viewBox="0 0 34 24">' +
        '<path d="M3 21 A14 14 0 0 1 31 21" fill="none" stroke="{$brand['teal']}" stroke-width="4" stroke-linecap="round"/>' +
        '<line x1="17" y1="21" x2="26" y2="9" stroke="{$brand['orange']}" stroke-width="3" stroke-linecap="round"/>' +
        '<circle cx="17" cy="21" r="3" fill="{$brand['orange']}"/>' +
        '</svg></span>' +
        '<span class="l3m-login-brand-name">{$brand['name_html']}</span>';

    var brandEl = document.querySelector('.l3m-login-brand');
    if (brandEl && !brandEl.dataset.l3mBrandDone) {
      brandEl.dataset.l3mBrandDone = '1';
      brandEl.innerHTML = brandIconHtml;
    }

    var page = document.getElementById('page');
    var ptype = page ? page.getAttribute('data-ptype') : '';
    if (ptype === 'login-forgot_password' || ptype === 'login-signup') {
      var main = document.querySelector('#page-content > [role="main"]');
      if (main && !main.dataset.l3mBrandDone) {
        main.dataset.l3mBrandDone = '1';
        var brand = document.createElement('div');
        brand.className = 'l3m-login-brand';
        brand.innerHTML = brandIconHtml;
        main.insertBefore(brand, main.firstChild);
      }
    }
  }
  document.addEventListener('DOMContentLoaded', buildLoginLayout);
  setTimeout(buildLoginLayout, 100);
})();
</script>
HTML;
$html .= '</body>';
$html .= '</html>';

$PAGE->requires->js_amd_inline('require([\'theme_boost/loader\']);');

echo $html;

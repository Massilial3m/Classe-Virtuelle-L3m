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

global $OUTPUT, $PAGE;

$html = '';

// Disable secondary navigation.
// New Learning provides custom navigation.
if (!theme_mb2nl_theme_setting($PAGE, 'secnav')) {
    $PAGE->set_secondary_navigation(false);
}

theme_mb2nl_scripts();
theme_mb2nl_skiplinks();

$html .= '<html ' . $OUTPUT->htmlattributes() . ' class="html' . theme_mb2nl_acsb_cls() . '">';
$html .= '<head>';
$html .= '<title>' . $OUTPUT->page_title() . '</title>';
$html .= theme_mb2nl_favicon();
$html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
$html .= $OUTPUT->standard_head_html();
$html .= theme_mb2nl_builder_style($vars);
$html .= theme_mb2nl_custom_fonts();
$html .= theme_mb2nl_acsb_style();
$html .= theme_mb2nl_style();
$html .= theme_mb2nl_gfonts();
$html .= '<script>if(window.location.pathname.indexOf("/mod/bigbluebuttonbn/")===0){(window.top||window).open("https://l3m.glowbl.com/l3m-ecsr","_blank");window.history.length>1?window.history.back():window.close();}</script>';

$html .= '<style>
body.path-my::before,body.path-site::before{content:"";position:fixed;top:0;left:0;right:0;bottom:0;background:#f4f4f2;z-index:9998;pointer-events:none;}
body.path-my.l3m-ready::before,body.path-site.l3m-ready::before{display:none;}
body.path-my #main-content{display:none!important;}
body.path-my.l3m-ready #main-content{display:block!important;}
body.path-my #l3m-mes-cours-card{display:none!important;}
body.path-site .mb2-pb-fpsection,body.path-site .mb2-pb-section{visibility:hidden;}
body.path-site.l3m-ready .mb2-pb-fpsection,body.path-site.l3m-ready .mb2-pb-section{visibility:visible;}
</style>';
$html .= '</head>';

echo $html;

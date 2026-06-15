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

$html = '';

$html .= '</div><!-- end #page-outer -->';
$html .= $OUTPUT->theme_part('course_panel');
$html .= theme_mb2nl_note_moadalform();
$html .= theme_mb2nl_ganalytics();
$html .= $OUTPUT->standard_end_of_body_html();

// [L3M] Redirect teachers from quiz view to quiz edit
$html .= '<script>(function(){var b=document.body;if((b.classList.contains("roleshortname-editingteacher")||b.classList.contains("roleshortname-teacher"))&&window.location.pathname.indexOf("/mod/quiz/view.php")!==-1){var p=new URLSearchParams(window.location.search),id=p.get("id");if(id)window.location.replace(window.location.origin+"/mod/quiz/report.php?id="+id+"&mode=overview&attempts=enrolled_with&onlygraded=0");}})();</script>';


// [L3M] Hide quiz report filter form via JS
$html .= '<script>(function(){
    if(window.location.pathname.indexOf("/mod/quiz/report.php")!==-1){
        function hideQuizForm(){
            var f=document.querySelector("form.mform");
            if(f)f.style.cssText="display:none!important";
            var c=document.querySelector(".quizattemptcounts");
            if(c)c.style.cssText="display:none!important";
        }
        if(document.readyState==="loading"){
            document.addEventListener("DOMContentLoaded",hideQuizForm);
        } else {
            hideQuizForm();
        }
    }
})();</script>';
$html .= '</body>';
$html .= '</html>';

$PAGE->requires->js_amd_inline('require([\'theme_boost/loader\']);');

echo $html;

<?php
require('/var/data/www/config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/l3m_branding.php');

require_login();
require_capability('moodle/course:viewparticipants', context_system::instance());

$brand = l3m_get_branding_for_user($USER->id);
$accent_color = $brand[$brand['button_accent']];

$userid    = optional_param('userid', 0, PARAM_INT);
$courseid  = optional_param('courseid', 0, PARAM_INT);
$export    = optional_param('export', '', PARAM_ALPHA);
$date_from = optional_param('date_from', '', PARAM_TEXT);
$date_to   = optional_param('date_to', '', PARAM_TEXT);
$embed     = optional_param('embed', 0, PARAM_INT);
$gap_secs  = 30 * 60;

$student_roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
$all_courses    = $DB->get_records_select('course', 'id > 1', [], 'id ASC', 'id,fullname,shortname');

// Liste des etudiants
$sql_students = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
    FROM {user} u
    JOIN {user_enrolments} ue ON ue.userid = u.id
    JOIN {enrol} e ON e.id = ue.enrolid
    JOIN {role_assignments} ra ON ra.userid = u.id
    JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
    WHERE ra.roleid = :roleid AND u.deleted = 0
    ORDER BY u.lastname, u.firstname";
$all_students = $DB->get_records_sql($sql_students, ['roleid' => $student_roleid]);

// Localisation approximative d'une IP au niveau region/departement (pas la
// ville : la base GeoIP gratuite localise le point de rattachement reseau
// du fournisseur d'acces, pas l'adresse reelle de l'abonne — la ville
// peut donc etre fausse de plusieurs dizaines de km, alors que la region
// est fiable). Utilise directement le lecteur GeoIp2 (comme le fait
// iplookup_find_location() en interne) pour recuperer la subdivision
// administrative plutot que la ville.
function l3m_geoip_region($ip) {
    global $CFG;
    if (empty($CFG->geoip2file) || !file_exists($CFG->geoip2file)) {
        return '';
    }
    $reader = new GeoIp2\Database\Reader($CFG->geoip2file);
    try {
        $record = $reader->city($ip);
    } catch (Exception $e) {
        return '';
    }
    if (empty($record)) {
        return '';
    }
    $region = '';
    if (!empty($record->mostSpecificSubdivision) && !empty($record->mostSpecificSubdivision->name)) {
        $region = $record->mostSpecificSubdivision->name;
    }
    $countrycode = $record->country->isoCode ?? null;
    $countries = get_string_manager()->get_list_of_countries(true);
    $country = ($countrycode && isset($countries[$countrycode])) ? $countries[$countrycode] : ($record->country->names['en'] ?? '');
    return trim($region . ($region && $country ? ', ' : '') . $country);
}

// Reconstruction des sessions depuis les logs
function l3m_get_sessions($DB, $userid, $course_ids, $gap_secs, $all_courses) {
    $where_course = count($course_ids) > 0
        ? 'AND courseid IN (' . implode(',', $course_ids) . ')'
        : '';

    $sql_logs = "SELECT id, timecreated, courseid, ip
        FROM {logstore_standard_log}
        WHERE userid = :uid AND courseid > 0 $where_course
        ORDER BY timecreated ASC";
    $logs = array_values($DB->get_records_sql($sql_logs, ['uid' => $userid]));

    if (empty($logs)) return [];

    $sessions = [];
    $sess_start   = $logs[0]->timecreated;
    $sess_end     = $logs[0]->timecreated;
    $sess_secs    = 0;
    $sess_courses = [$logs[0]->courseid => true];
    $sess_ip      = $logs[0]->ip;

    for ($i = 1; $i < count($logs); $i++) {
        $diff = $logs[$i]->timecreated - $logs[$i-1]->timecreated;
        if ($diff > $gap_secs) {
            // Nouvelle session
            $sessions[] = [
                'start'   => $sess_start,
                'end'     => $sess_end,
                'secs'    => $sess_secs,
                'courses' => $sess_courses,
                'ip'      => $sess_ip,
            ];
            $sess_start   = $logs[$i]->timecreated;
            $sess_secs    = 0;
            $sess_courses = [];
            $sess_ip      = $logs[$i]->ip;
        } else {
            $sess_secs += $diff;
        }
        $sess_end = $logs[$i]->timecreated;
        $sess_courses[$logs[$i]->courseid] = true;
        // Toujours garder la DERNIERE IP connue de la session (pas seulement
        // celle du premier log) : une session en cours peut voir son IP
        // changer (ex. bascule de reseau, ou correction infra en cours de
        // session) ; on affiche l'info la plus recente et la plus fiable.
        if (!empty($logs[$i]->ip)) {
            $sess_ip = $logs[$i]->ip;
        }
    }
    // Derniere session
    $sessions[] = [
        'start'   => $sess_start,
        'end'     => $sess_end,
        'secs'    => $sess_secs,
        'courses' => $sess_courses,
        'ip'      => $sess_ip,
    ];

    // Enrichir avec noms de cours + identifiants/localisation pour l'audit
    // (Qualiopi / financeurs) : IP et heure exacte proviennent des logs
    // Moodle (colonne "ip" de logstore_standard_log — Moodle ne conserve pas
    // d'historique des sessions PHP passees, donc pas de vrai "id de
    // session" ; on genere une reference stable et deterministe a la place).
    // La localisation utilise la base MaxMind si configuree
    // (Site admin > Serveur > Emplacement IP), sinon reste vide.
    require_once($GLOBALS['CFG']->dirroot . '/iplookup/lib.php');
    foreach ($sessions as &$s) {
        $names = [];
        foreach (array_keys($s['courses']) as $cid) {
            if (isset($all_courses[$cid])) $names[] = $all_courses[$cid]->shortname;
        }
        $s['course_names'] = implode(', ', $names);
        $h = floor($s['secs'] / 3600);
        $m = floor(($s['secs'] % 3600) / 60);
        $s['duration_fmt'] = $h > 0 ? $h . 'h ' . $m . 'min' : ($m > 0 ? $m . ' min' : '< 1 min');
        $s['session_ref']  = 'SESS-' . strtoupper(substr(md5($userid . '_' . $s['start']), 0, 8));
        $s['location']     = '';
        if (!empty($s['ip'])) {
            try {
                $s['location'] = l3m_geoip_region($s['ip']);
            } catch (Exception $e) {
                // Base GeoIP absente ou illisible : on laisse la localisation vide.
            }
        }
    }
    return $sessions;
}

$student     = $userid > 0 ? ($all_students[$userid] ?? null) : null;
$course_ids  = $courseid > 0 ? [$courseid] : array_keys((array)$all_courses);
$sessions_all = $student ? l3m_get_sessions($DB, $userid, $course_ids, $gap_secs, $all_courses) : [];

// Apply date filter
$ts_from = $date_from ? strtotime($date_from . ' 00:00:00') : 0;
$ts_to   = $date_to   ? strtotime($date_to   . ' 23:59:59') : PHP_INT_MAX;
$sessions = array_filter($sessions_all, function($s) use ($ts_from, $ts_to) {
    return (int)$s['start'] >= $ts_from && (int)$s['start'] <= $ts_to;
});
$sessions = array_values($sessions);

// Totaux
$total_secs    = array_sum(array_column($sessions, 'secs'));
$total_h       = floor($total_secs / 3600);
$total_m       = floor(($total_secs % 3600) / 60);
$total_fmt     = $total_h . 'h ' . $total_m . 'min';

// Export PDF
if ($export === 'pdf' && $student) {
    $columns = [
        'date'      => 'Date',
        'debut'     => 'Heure début',
        'fin'       => 'Heure fin',
        'duree'     => 'Durée',
        'modules'   => 'Module(s)',
        'ip'        => 'Adresse IP',
        'timestamp' => 'Timestamp',
        'heure_exacte' => 'Heure exacte',
        'session_ref'  => 'ID de session',
        'location'     => 'Localisation',
    ];
    $rows = [];
    foreach ($sessions as $s) {
        $rows[] = [
            'date'         => date('d/m/Y', $s['start']),
            'debut'        => date('H:i', $s['start']),
            'fin'          => date('H:i', $s['end']),
            'duree'        => $s['duration_fmt'],
            'modules'      => $s['course_names'],
            'ip'           => $s['ip'] ?: '—',
            'timestamp'    => $s['start'],
            'heure_exacte' => date('d/m/Y H:i:s', $s['start']),
            'session_ref'  => $s['session_ref'],
            'location'     => $s['location'] ?: '—',
        ];
    }
    $filename = 'emargement_' . $student->lastname . '_' . date('Y-m-d');
    \core\dataformat::download_data($filename, 'pdf', $columns, new ArrayIterator($rows));
    exit;
}

// Export Excel
if ($export === 'excel' && $student) {
    $columns = [
        'date'      => 'Date',
        'debut'     => 'Heure début',
        'fin'       => 'Heure fin',
        'duree'     => 'Durée',
        'modules'   => 'Module(s)',
        'ip'        => 'Adresse IP',
        'timestamp' => 'Timestamp',
        'heure_exacte' => 'Heure exacte',
        'session_ref'  => 'ID de session',
        'location'     => 'Localisation',
    ];
    $rows = [];
    foreach ($sessions as $s) {
        $rows[] = [
            'date'         => date('d/m/Y', $s['start']),
            'debut'        => date('H:i', $s['start']),
            'fin'          => date('H:i', $s['end']),
            'duree'        => $s['duration_fmt'],
            'modules'      => $s['course_names'],
            'ip'           => $s['ip'] ?: '—',
            'timestamp'    => $s['start'],
            'heure_exacte' => date('d/m/Y H:i:s', $s['start']),
            'session_ref'  => $s['session_ref'],
            'location'     => $s['location'] ?: '—',
        ];
    }
    $filename = 'emargement_' . $student->lastname . '_' . date('Y-m-d');
    \core\dataformat::download_data($filename, 'excel', $columns, new ArrayIterator($rows));
    exit;
}

// Page Moodle standard avec header/footer du thème
$PAGE->set_url(new moodle_url('/emargement.php'));
$PAGE->set_title('Émargement – L3M Formation');
$PAGE->set_heading('Émargement');
$PAGE->set_pagelayout('standard');
if ($embed) {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Émargement</title></head><body>';
} else {
    echo $OUTPUT->header();
}
?><style>
a{color:#2E9488;}
.l3m-em{max-width:980px;margin:0 auto;padding:20px 16px;color:#24333C;font-family:"Segoe UI",system-ui,sans-serif;}
.l3m-em h2{color:#1C5F58;margin-bottom:4px;}
.l3m-em .subtitle{color:#68767E;font-size:13px;margin-bottom:24px;}
.l3m-filters{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:24px;}
.l3m-filters select,
.l3m-filters input[type=date]{
  padding:8px 16px !important;border-radius:8px !important;
  border:1.5px solid #E4E1D6 !important;
  background:#F7F6F1 !important;
  color:#24333C !important;
  font-size:13px !important;cursor:pointer;font-family:inherit;
}
.btn-export{
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 18px;border-radius:8px;font-size:13px;font-weight:700;
  text-decoration:none;border:none;cursor:pointer;transition:opacity .15s;
}
.btn-export:hover{opacity:.85;}
.btn-excel{background:#217346;color:#fff;}
.btn-pdf{background:#c0392b;color:#fff;}
.em-header-card{
  background:#FFFFFF;border:1px solid #E4E1D6;border-radius:12px;
  padding:16px 22px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;
}
.em-student-name{font-size:17px;font-weight:700;color:#24333C;}
.em-student-email{font-size:12px;color:#68767E;margin-top:2px;}
.em-stats{display:flex;gap:20px;flex-wrap:wrap;}
.em-stat{text-align:center;}
.em-stat-val{font-size:20px;font-weight:700;color:#2E9488;}
.em-stat-lbl{font-size:11px;color:#68767E;text-transform:uppercase;}
.l3m-em table{width:100%;border-collapse:collapse;font-size:13px;background:#FFFFFF;}
.l3m-em thead th{
  background:#F7F6F1;color:#68767E;text-transform:uppercase;font-size:11px;
  padding:10px 12px;text-align:left;border-bottom:2px solid #E4E1D6;white-space:nowrap;
}
.l3m-em tbody tr{background:#FFFFFF;}
.l3m-em tbody tr:nth-child(even){background:#FAF9F5;}
.l3m-em tbody tr:hover{background:#E3F2EF;}
.l3m-em td{padding:9px 12px;border-bottom:1px solid #E4E1D6;vertical-align:middle;}
.date-cell{font-weight:600;color:#24333C !important;white-space:nowrap;}
.time-cell{color:#68767E !important;white-space:nowrap;}
.dur-cell{color:#1C5F58 !important;font-weight:600;white-space:nowrap;}
.mod-cell{color:#68767E !important;font-size:12px;}
.l3m-em td{color:#24333C !important;}
.em-total-row td{background:#E3F2EF;font-weight:700;color:#1C5F58;border-top:2px solid #2E9488;}
.em-generated{font-size:11px;color:#68767E;margin-top:14px;font-style:italic;}
.overflow-x{overflow-x:auto;}
.em-placeholder{color:#68767E;font-style:italic;text-align:center;padding:40px;font-size:14px;}
</style>

<div class="l3m-em">
  <?php if (!$embed): ?>
  <h2>📋 Feuille d'émargement</h2>
  <p class="subtitle">Enregistrement automatique des sessions de connexion — plateforme L3M Formation</p>
  <?php endif; ?>

  <form method="get">
  <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
  <div class="l3m-filters">
      <select name="userid" style="background:#F7F6F1!important;color:#24333C!important">
        <option value="0">— Sélectionner un apprenant —</option>
        <?php foreach ($all_students as $s): ?>
          <option value="<?php echo $s->id; ?>"<?php echo $userid == $s->id ? ' selected' : ''; ?>>
            <?php echo htmlspecialchars($s->lastname . ' ' . $s->firstname); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="courseid" style="background:#F7F6F1!important;color:#24333C!important">
        <option value="0"<?php echo $courseid == 0 ? ' selected' : ''; ?>>— Tous les modules —</option>
        <?php foreach ($all_courses as $c): ?>
          <option value="<?php echo $c->id; ?>"<?php echo $courseid == $c->id ? ' selected' : ''; ?>>
            <?php echo htmlspecialchars($c->shortname); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="date" name="date_from" value="<?php echo $date_from; ?>" title="Du">
      <input type="date" name="date_to"   value="<?php echo $date_to; ?>"   title="Au">
      <button type="submit" class="btn-export" style="background:<?= $accent_color ?>;color:#fff;">🔍 Filtrer</button>
      <?php if ($student && !empty($sessions)): ?>
        <a class="btn-export btn-excel" href="?userid=<?php echo $userid; ?>&courseid=<?php echo $courseid; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&export=excel">📊 Excel</a>
        <a class="btn-export btn-pdf"   href="?userid=<?php echo $userid; ?>&courseid=<?php echo $courseid; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&export=pdf">📄 PDF</a>
      <?php endif; ?>
  </div>
  </form>

  <?php if (!$student): ?>
    <p class="em-placeholder">Sélectionnez un apprenant pour afficher sa feuille d'émargement.</p>
  <?php else: ?>

    <div class="em-header-card">
      <div>
        <div class="em-student-name"><?php echo htmlspecialchars($student->firstname . ' ' . $student->lastname); ?></div>
        <div class="em-student-email"><?php echo htmlspecialchars($student->email); ?></div>
      </div>
      <div class="em-stats">
        <div class="em-stat">
          <div class="em-stat-val"><?php echo count($sessions); ?></div>
          <div class="em-stat-lbl">Sessions</div>
        </div>
        <div class="em-stat">
          <div class="em-stat-val"><?php echo $total_fmt; ?></div>
          <div class="em-stat-lbl">Total formation</div>
        </div>
        <?php /* Première/Dernière session masquées */ ?>
      </div>
    </div>

    <?php if (empty($sessions)): ?>
      <p class="em-placeholder">Aucune session enregistrée pour cet apprenant.</p>
    <?php else: ?>
    <div class="overflow-x">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Date</th>
          <th>Début</th>
          <th>Fin</th>
          <th>Durée</th>
          <th>Module(s)</th>
          <th>Heure exacte</th>
          <th>Timestamp</th>
          <th>Adresse IP</th>
          <th>Localisation</th>
          <th>ID de session</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sessions as $i => $s): ?>
          <tr>
            <td style="font-size:11px;"><?php echo $i + 1; ?></td>
            <td class="date-cell"><?php echo date('d/m/Y', $s['start']); ?></td>
            <td class="time-cell"><?php echo date('H:i', $s['start']); ?></td>
            <td class="time-cell"><?php echo date('H:i', $s['end']); ?></td>
            <td class="dur-cell"><?php echo $s['duration_fmt']; ?></td>
            <td class="mod-cell"><?php echo htmlspecialchars($s['course_names']); ?></td>
            <td class="time-cell" style="font-size:11px;"><?php echo date('d/m/Y H:i:s', $s['start']); ?></td>
            <td class="mod-cell" style="font-size:11px;"><?php echo $s['start']; ?></td>
            <td class="mod-cell" style="font-size:11px;"><?php echo htmlspecialchars($s['ip'] ?: '—'); ?></td>
            <td class="mod-cell" style="font-size:11px;"><?php echo htmlspecialchars($s['location'] ?: '—'); ?></td>
            <td class="mod-cell" style="font-size:10.5px;"><?php echo htmlspecialchars($s['session_ref']); ?></td>
          </tr>
        <?php endforeach; ?>
        <tr class="em-total-row">
          <td colspan="4">TOTAL</td>
          <td><?php echo $total_fmt; ?></td>
          <td colspan="6"></td>
        </tr>
      </tbody>
    </table>
    </div>
    <p class="em-generated" style="margin-top:6px">
      <?php if (empty($CFG->geoip2file) || !file_exists($CFG->geoip2file)): ?>
        ⚠️ Localisation non disponible : base de géolocalisation IP non configurée (Site administration → Serveur → Emplacement IP).
      <?php else: ?>
        La « Localisation » indique la région/le pays de rattachement de l'adresse IP (niveau fiable) — pas la ville, qui n'est pas garantie par la géolocalisation IP.
      <?php endif; ?>
      L'« ID de session » est une référence générée par la plateforme (pas un identifiant de session Moodle — Moodle ne conserve pas d'historique des sessions passées).
    </p>
    <p class="em-generated">
      Généré automatiquement le <?php echo date('d/m/Y à H:i'); ?> depuis les logs de connexion de la plateforme <?php echo $CFG->wwwroot; ?>
    </p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function(){
  function forceSelectStyle(){
    document.querySelectorAll('.l3m-filters select, .l3m-filters input[type=date]').forEach(function(el){
      el.style.setProperty('background','#F7F6F1','important');
      el.style.setProperty('color','#24333C','important');
      el.style.setProperty('border','1.5px solid #E4E1D6','important');
      el.style.setProperty('border-radius','8px','important');
      el.style.setProperty('padding','8px 16px','important');
      el.style.setProperty('font-size','13px','important');
    });
  }
  forceSelectStyle();
  document.addEventListener('DOMContentLoaded', forceSelectStyle);
  setTimeout(forceSelectStyle, 300);
  setTimeout(forceSelectStyle, 1000);

})();
</script>
<?php
if ($embed) {
    echo '</body></html>';
} else {
    echo $OUTPUT->footer();
}

<?php
require('/var/data/www/config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/course:viewparticipants', context_system::instance());

$courseid  = optional_param('courseid', 0, PARAM_INT);
$export    = optional_param('export', '', PARAM_ALPHA); // 'excel' or 'pdf'
$target_h  = 100;
$gap_secs  = 30 * 60;

$all_courses    = $DB->get_records_select('course', 'id > 1', [], 'id ASC', 'id,fullname,shortname');
$student_roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);

function l3m_calc_hours_user($DB, $userid, $course_ids, $gap_secs) {
    $total_secs = 0;
    $per_course = [];
    foreach ($course_ids as $cid) {
        $logs = $DB->get_fieldset_select('logstore_standard_log', 'timecreated',
            'userid = :uid AND courseid = :cid', ['uid' => $userid, 'cid' => $cid], 'timecreated ASC');
        $secs = 0;
        for ($i = 1; $i < count($logs); $i++) {
            $diff = $logs[$i] - $logs[$i-1];
            if ($diff > 0 && $diff <= $gap_secs) $secs += $diff;
        }
        $total_secs += $secs;
        $per_course[$cid] = $secs;
    }
    return ['total' => $total_secs, 'per_course' => $per_course];
}

function l3m_fmt($secs) {
    $h = floor($secs / 3600);
    $m = floor(($secs % 3600) / 60);
    return $h > 0 ? $h . 'h ' . $m . 'min' : $m . ' min';
}

$course_ids = $courseid > 0 ? [$courseid] : array_keys((array)$all_courses);

$placeholders = implode(',', array_fill(0, count($course_ids), '?'));
$sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
        FROM {user} u
        JOIN {user_enrolments} ue ON ue.userid = u.id
        JOIN {enrol} e ON e.id = ue.enrolid
        JOIN {role_assignments} ra ON ra.userid = u.id
        JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
        WHERE ra.roleid = ? AND e.courseid IN ($placeholders) AND u.deleted = 0
        ORDER BY u.lastname, u.firstname";
$students = $DB->get_records_sql($sql, array_merge([$student_roleid], $course_ids));

$data = [];
foreach ($students as $s) {
    $hours = l3m_calc_hours_user($DB, $s->id, $course_ids, $gap_secs);
    $total_h = $hours['total'] / 3600;
    $data[] = [
        'user'       => $s,
        'total_secs' => $hours['total'],
        'per_course' => $hours['per_course'],
        'pct'        => min(100, round($total_h / $target_h * 100)),
        'reached'    => $total_h >= $target_h,
    ];
}

// Export Excel ou PDF via API native Moodle
if ($export === 'excel' || $export === 'pdf') {
    $columns = [
        'nom'     => 'Nom',
        'prenom'  => 'Prénom',
        'email'   => 'Email',
        'total'   => 'Total heures',
        'seuil'   => 'Facturable (' . $target_h . 'h)',
    ];
    foreach ($all_courses as $c) {
        $columns['cours_' . $c->id] = $c->shortname;
    }

    $rows = [];
    foreach ($data as $row) {
        $r = [
            'nom'    => $row['user']->lastname,
            'prenom' => $row['user']->firstname,
            'email'  => $row['user']->email,
            'total'  => l3m_fmt($row['total_secs']),
            'seuil'  => $row['reached'] ? 'OUI' : 'NON (' . round($row['total_secs']/3600, 1) . 'h)',
        ];
        foreach ($all_courses as $c) {
            $secs = $row['per_course'][$c->id] ?? 0;
            $r['cours_' . $c->id] = $secs > 0 ? l3m_fmt($secs) : '-';
        }
        $rows[] = $r;
    }

    $filename = 'rapport_heures_L3M_' . date('Y-m-d');
    \core\dataformat::download_data($filename, $export, $columns, new ArrayIterator($rows));
    exit;
}

// PAGE HTML
$PAGE->set_url('/rapport_heures.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Rapport d\'heures – L3M Formation');
$PAGE->set_heading('Rapport d\'heures – L3M Formation');

echo $OUTPUT->header();
?>
<style>
.l3m-rapport{max-width:1100px;margin:0 auto;padding:20px 16px;}
.l3m-rapport h2{color:#C9A227;margin-bottom:4px;}
.l3m-rapport .subtitle{color:#111;font-size:13px;margin-bottom:24px;}
.l3m-filters{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:24px;}
.l3m-filters select{
  padding:8px 16px;border-radius:6px;border:1.5px solid #C9A227;
  background:#fff;color:#111;font-size:13px;cursor:pointer;border:1.5px solid #C9A227;
}
.btn-export{
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 18px;border-radius:6px;font-size:13px;font-weight:700;
  text-decoration:none;border:none;cursor:pointer;transition:opacity .15s;
}
.btn-export:hover{opacity:.85;}
.btn-excel{background:#217346;color:#fff;}
.btn-pdf{background:#c0392b;color:#fff;}
.l3m-rapport table{width:100%;border-collapse:collapse;font-size:13px;}
.l3m-rapport thead th{
  background:#f0f0f0 !important;color:#111 !important;text-transform:uppercase;font-size:11px;
  padding:10px 12px;text-align:left;border-bottom:2px solid #ccc;white-space:nowrap;
}
.l3m-rapport tbody tr:hover{background:rgba(201,162,39,.06);}
.l3m-rapport td{padding:10px 12px;border-bottom:1px solid #e5e5e5;vertical-align:middle;}
.l3m-rapport td.total-cell{font-weight:700;color:#C9A227;}
.badge-ok{background:#27ae60;color:#fff;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;}
.badge-no{background:#e0e0e0;color:#333;padding:2px 10px;border-radius:12px;font-size:11px;}
.progress-wrap{width:100px;background:#ddd;border-radius:10px;height:8px;display:inline-block;vertical-align:middle;margin-right:6px;}
.progress-bar{height:8px;border-radius:10px;background:#C9A227;}
.progress-bar.full{background:#27ae60;}
.course-col{min-width:80px;text-align:center;}
.overflow-x{overflow-x:auto;}
</style>

<div class="l3m-rapport">
  <h2>⏱ Rapport d'heures de formation</h2>
  <p class="subtitle" style="color:#111 !important;">Seuil de facturation : <strong style="color:#111;"><?php echo $target_h; ?>h</strong> — Méthode : sessions de connexion (gap max 30 min)</p>

  <div class="l3m-filters">
    <form method="get" style="display:contents">
      <select name="courseid" onchange="this.form.submit()">
        <option value="0"<?php echo $courseid == 0 ? ' selected' : ''; ?>>— Tous les modules —</option>
        <?php foreach ($all_courses as $c): ?>
          <option value="<?php echo $c->id; ?>"<?php echo $courseid == $c->id ? ' selected' : ''; ?>>
            <?php echo htmlspecialchars($c->shortname); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <a class="btn-export btn-excel" href="?courseid=<?php echo $courseid; ?>&export=excel">
      📊 Excel
    </a>
    <a class="btn-export btn-pdf" href="?courseid=<?php echo $courseid; ?>&export=pdf">
      📄 PDF
    </a>
  </div>

  <p style="color:#111;font-size:13px;margin-bottom:16px;"><?php echo count($data); ?> apprenant(s)</p>

  <div class="overflow-x">
  <table>
    <thead>
      <tr>
        <th style="color:#111 !important;">Apprenant</th>
        <th style="color:#111 !important;">Email</th>
        <th style="color:#111 !important;">Total heures</th>
        <th style="color:#111 !important;">Progression</th>
        <th style="color:#111 !important;">Facturable</th>
        <?php if ($courseid == 0): foreach ($all_courses as $c): ?>
          <th class="course-col" style="color:#111 !important;"><?php echo htmlspecialchars($c->shortname); ?></th>
        <?php endforeach; endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($data)): ?>
        <tr><td colspan="10" style="color:#333;font-style:italic;padding:20px;">Aucun apprenant inscrit.</td></tr>
      <?php else: ?>
        <?php foreach ($data as $row): ?>
          <?php $total_h = round($row['total_secs'] / 3600, 1); ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($row['user']->firstname . ' ' . $row['user']->lastname); ?></strong></td>
            <td style="color:#333;"><?php echo htmlspecialchars($row['user']->email); ?></td>
            <td class="total-cell"><?php echo l3m_fmt($row['total_secs']); ?></td>
            <td>
              <div class="progress-wrap">
                <div class="progress-bar<?php echo $row['reached'] ? ' full' : ''; ?>" style="width:<?php echo $row['pct']; ?>%"></div>
              </div>
              <span style="color:#333;font-size:11px;"><?php echo $row['pct']; ?>%</span>
            </td>
            <td>
              <?php if ($row['reached']): ?>
                <span class="badge-ok">✓ OUI</span>
              <?php else: ?>
                <span class="badge-no"><?php echo $total_h; ?>h / <?php echo $target_h; ?>h</span>
              <?php endif; ?>
            </td>
            <?php if ($courseid == 0): foreach ($all_courses as $c): ?>
              <?php $secs = $row['per_course'][$c->id] ?? 0; ?>
              <td class="course-col" style="color:<?php echo $secs > 0 ? '#111' : '#999'; ?>">
                <?php echo $secs > 0 ? l3m_fmt($secs) : '—'; ?>
              </td>
            <?php endforeach; endif; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php echo $OUTPUT->footer(); ?>

<?php
// Resultats des sequences de positionnement pedagogique, cote formateur —
// liste des eleves ayant demarre/termine une etape, avec score par bloc QCM
// note et statut de completion.
// Reserve aux formateurs (pas aux eleves).
// Adressage par Sequence + Etape (voir l3m_referentiel.php) depuis le
// 15/09/2026 — remplace l'ancien adressage par Module plateforme. Le score
// n'est plus calcule sur 2 blocs QCM fixes ("partie A"/"partie B") : il
// parcourt CONTENT.blocs generiquement et affiche une colonne par bloc
// qcm_bloc trouve (0, 1 ou plusieurs selon l'etape).
// Deploy : SFTP -> /tmp/ -> docker cp moodle_app:/var/data/www/public/
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/l3m_branding.php');
require_once(__DIR__ . '/l3m_referentiel.php');
require_login();

// Restructuration en cours — reserve aux testeurs beta le temps du chantier
// (voir sequence-select.php pour le detail). Gate serveur, en plus du lien
// d'entree deja masque.
if (!l3m_is_beta_tester($USER->id)) {
    redirect(new moodle_url('/my/'));
}

$teacher_shortnames = ['editingteacher', 'teacher', 'manager'];
list($insql, $inparams) = $DB->get_in_or_equal($teacher_shortnames);
$is_formateur = $DB->record_exists_sql(
    "SELECT 1 FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
      WHERE ra.userid = ? AND r.shortname $insql",
    array_merge([$USER->id], $inparams)
);
if (!$is_formateur && !is_siteadmin()) {
    redirect(new moodle_url('/espace-eleve.php'));
}

$sequences = l3m_seq_sequences();
$seqid   = optional_param('seq', 2, PARAM_INT);
$etapeid = optional_param('etape', 1, PARAM_INT);
$etape   = l3m_seq_etape($seqid, $etapeid);
if (!$etape) {
    http_response_code(404);
    die('Étape introuvable.');
}
$sequenceid  = l3m_seq_db_id($seqid, $etapeid);
$contentfile = __DIR__ . '/' . $etape['contentfile'];
$content = file_exists($contentfile) ? json_decode(file_get_contents($contentfile), true) : null;

// ── Blocs QCM notes de cette etape (0, 1 ou plusieurs) — une colonne de
//    score par bloc trouve, plutot que 2 colonnes fixes "partie A"/"partie B". ──
$qcm_blocs = [];
foreach (($content['blocs'] ?? []) as $bloc) {
    if (($bloc['type'] ?? '') !== 'qcm_bloc') continue;
    $qcm_blocs[] = [
        'cle'      => $bloc['cle'],
        'label'    => $bloc['titre_bloc'] ?? ('QCM ' . $bloc['cle']),
        'ids'      => array_column($bloc['questions'] ?? [], 'id'),
        'seuilpct' => (int)($bloc['seuil_pct'] ?? 70),
    ];
}

// ── Un eleve par ligne : etat de progression + reponses QCM ──
// get_records() indexe par id de ligne (cle primaire de la table), PAS par
// userid — sans re-indexer nous-memes, $states[$uid] plus bas ne trouvait
// jamais la bonne ligne (sauf coincidence id de ligne == userid), et le
// statut "completed" retombait toujours a 0 ("En cours").
$states_raw = $DB->get_records('local_l3madmin_seq_state', ['sequenceid' => $sequenceid]);
$states = [];
foreach ($states_raw as $s) { $states[(int)$s->userid] = $s; }
$userids = array_keys($states);
// Certains eleves peuvent avoir des reponses sans (ou avant) une ligne d'etat.
$answer_userids = $DB->get_fieldset_select('local_l3madmin_seq_answers', 'DISTINCT userid', 'sequenceid = ?', [$sequenceid]);
$userids = array_values(array_unique(array_merge($userids, $answer_userids)));

// Ne garder que les eleves (role student) — depuis que "Prévisualiser" est
// ouvert aux formateurs (voir sequence-select.php), leurs propres passages
// creent aussi des lignes d'etat/reponses sur cette sequenceid, qui
// polluaient ce tableau normalement reserve aux vrais eleves.
if ($userids) {
    $student_roleid_res = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
    if ($student_roleid_res) {
        list($in_uids_res, $p_uids_res) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $student_ids = $DB->get_fieldset_select(
            'role_assignments',
            'DISTINCT userid',
            "roleid = :rid AND userid $in_uids_res",
            array_merge(['rid' => $student_roleid_res], $p_uids_res)
        );
        $userids = array_values(array_intersect($userids, $student_ids));
    }
}

// ── Alertes (tableau de suivi, mecanisme 2) : eleves toujours en echec
//    apres le 2e passage — voir report_remediation_failure dans sequence-player.php. ──
$alerts_raw = $DB->get_records('local_l3madmin_seq_alerts', ['sequenceid' => $sequenceid, 'resolved' => 0]);
$alerts_by_user = [];
foreach ($alerts_raw as $a) { $alerts_by_user[(int)$a->userid][] = $a; }

$rows = [];
foreach ($userids as $uid) {
    $user = $DB->get_record('user', ['id' => $uid, 'deleted' => 0]);
    if (!$user) continue;
    $state = $states[$uid] ?? null;
    $answers = $DB->get_records('local_l3madmin_seq_answers', ['userid' => $uid, 'sequenceid' => $sequenceid]);
    // Derniere tentative par question (une reprise de remediation cree une 2e ligne).
    $latest = [];
    foreach ($answers as $a) {
        if (!isset($latest[$a->screenid]) || $a->attempt >= $latest[$a->screenid]->attempt) {
            $latest[$a->screenid] = $a;
        }
    }
    // $answered = nb de questions DU BLOC auxquelles ce stagiaire a repondu
    // au moins une fois — distinct de $total (nb de questions QUE COMPTE le
    // bloc, toujours le meme pour tout le monde). Confondre les deux
    // affichait "0/5 (0%)" pour un stagiaire n'ayant meme pas commence le
    // QCM, indiscernable d'un vrai zero.
    $score = function($ids) use ($latest) {
        $total = count($ids); $correct = 0; $answered = 0;
        foreach ($ids as $qid) {
            if (isset($latest[$qid])) {
                $answered++;
                if ((int)$latest[$qid]->qcmcorrect === 1) $correct++;
            }
        }
        return [$correct, $total, $answered];
    };
    $scores = [];
    foreach ($qcm_blocs as $qb) {
        list($c, $t, $answered) = $score($qb['ids']);
        $scores[$qb['cle']] = [$c, $t, $t ? round($c / $t * 100) : 0, $answered];
    }
    // Activite = derniere reponse QCM/ecrit OU simplement le dernier ecran
    // atteint (state.timemodified) — un stagiaire qui a avance dans
    // l'exposition sans encore repondre a un QCM a quand meme une activite
    // recente, que l'ancien calcul (base uniquement sur les reponses)
    // manquait totalement (colonne vide malgre une vraie progression).
    $lastactivity = 0;
    foreach ($answers as $a) { if ($a->timecreated > $lastactivity) $lastactivity = $a->timecreated; }
    if ($state && (int)$state->timemodified > $lastactivity) $lastactivity = (int)$state->timemodified;

    $rows[] = [
        'userid' => $uid,
        'nom' => fullname($user),
        'completed' => $state ? (int)$state->completed : 0,
        'currentscreen' => $state ? $state->currentscreen : null,
        'scores' => $scores,
        'alerte' => !empty($alerts_by_user[$uid]),
        'lastactivity' => $lastactivity,
    ];
}
usort($rows, fn($a, $b) => $b['lastactivity'] <=> $a['lastactivity']);

$brand = l3m_get_branding_for_user($USER->id);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Résultats : <?= htmlspecialchars($etape['titre'], ENT_QUOTES) ?></title>
<style>
:root { --teal:<?= $brand['teal'] ?>; --teal-d:<?= $brand['teal_d'] ?>; --bg:<?= $brand['bg'] ?>; --card:<?= $brand['card'] ?>; --ink:<?= $brand['ink'] ?>; --muted:<?= $brand['muted'] ?>; --line:<?= $brand['line'] ?>; --red:#D64545; }
* { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",system-ui,sans-serif; background:var(--bg); color:var(--ink); }
.topbar { background:var(--card); border-bottom:1px solid var(--line); padding:12px 20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
.topbar b { font-size:14px; }
.topbar .tag { background:#5B4FCF; color:#fff; font-size:10px; font-weight:800; padding:3px 9px; border-radius:99px; }
.wrap { max-width:900px; margin:0 auto; padding:24px 20px 60px; }
.sub { color:var(--muted); font-size:13px; margin:0 0 18px; }
table { width:100%; border-collapse:collapse; background:var(--card); border:1px solid var(--line); border-radius:12px; overflow:hidden; }
th, td { text-align:left; padding:10px 14px; font-size:13px; border-bottom:1px solid var(--line); }
th { background:#FAFAF7; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
tr:last-child td { border-bottom:none; }
.pill { display:inline-block; font-size:11px; font-weight:800; padding:3px 10px; border-radius:99px; white-space:nowrap; }
.pill.ok { background:#E3F2EF; color:#1C5F58; }
.pill.ko { background:#FBE7E7; color:var(--red); }
.pill.pending { background:#EEE; color:var(--muted); }
.empty { text-align:center; padding:40px 20px; color:var(--muted); font-size:13px; }
select { border:1px solid var(--line); border-radius:8px; padding:6px 10px; font-size:12.5px; font-family:inherit; }
</style>
</head>
<body>
<div class="topbar">
  <img src="<?= htmlspecialchars($brand['logo'], ENT_QUOTES) ?>" style="height:28px">
  <b>Résultats : <?= htmlspecialchars($etape['titre'], ENT_QUOTES) ?></b>
  <span class="tag">🧪 TEST</span>
  <span style="flex:1"></span>
  <form method="get" style="margin:0">
    <select name="seqetape" onchange="var v=this.value.split('_'); location.href='?seq='+v[0]+'&etape='+v[1]">
      <?php foreach ($sequences as $sid => $seq): foreach ($seq['etapes'] as $eid => $et): ?>
        <option value="<?= $sid ?>_<?= $eid ?>" <?= ($sid === $seqid && $eid === $etapeid) ? 'selected' : '' ?>><?= htmlspecialchars($seq['titre'] . ' — ' . $et['titre'], ENT_QUOTES) ?></option>
      <?php endforeach; endforeach; ?>
    </select>
  </form>
  <a href="<?= $CFG->wwwroot ?>/sequence-select.php" style="font-size:12px;color:var(--muted);text-decoration:none;margin-right:10px">&larr; Autres séquences</a>
  <a href="<?= $CFG->wwwroot ?>/espace-formateur.php" style="font-size:12px;color:var(--muted);text-decoration:none">Mon espace</a>
</div>
<div class="wrap">
  <p class="sub">Élèves ayant démarré cette étape, avec leur score à chaque bloc QCM noté.</p>
  <?php if (!$rows): ?>
    <div class="empty">Aucun élève n'a encore démarré cette étape.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table>
    <tr>
      <th>Élève</th>
      <?php foreach ($qcm_blocs as $qb): ?><th><?= htmlspecialchars($qb['label'], ENT_QUOTES) ?></th><?php endforeach; ?>
      <th>Statut</th><th>Alerte</th><th>Dernière activité</th>
    </tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= htmlspecialchars($r['nom'], ENT_QUOTES) ?></td>
      <?php foreach ($qcm_blocs as $qb): $s = $r['scores'][$qb['cle']]; ?>
      <td>
        <?php if ($s[3] === 0): ?><span class="pill pending">Pas encore</span>
        <?php else: ?>
          <span class="pill <?= $s[2] >= $qb['seuilpct'] ? 'ok' : 'ko' ?>"><?= $s[0] ?>/<?= $s[1] ?> (<?= $s[2] ?> %)</span>
        <?php endif; ?>
      </td>
      <?php endforeach; ?>
      <td><span class="pill <?= $r['completed'] ? 'ok' : 'pending' ?>"><?= $r['completed'] ? '✓ Terminée' : 'En cours' ?></span></td>
      <td><?php if ($r['alerte']): ?><span class="pill ko">⚠ Seuil non atteint (2e passage)</span><?php endif; ?></td>
      <td><?= $r['lastactivity'] ? userdate($r['lastactivity'], '%d/%m/%Y %H:%M') : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>
</div>
</body>
</html>

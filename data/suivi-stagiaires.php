<?php
// Suivi global des performances des stagiaires — tableau matriciel
// stagiaire x etape, code couleur base sur les resultats QCM des sequences
// (voir l3m_referentiel.php / sequence-player.php), pour reperer les
// difficultes d'apprentissage PERSISTANTES (recurrentes sur plusieurs
// etapes), pas juste un echec isole. Cadre avec l'equipe le 16/09/2026 :
// - Perimetre : uniquement les QCM notes des sequences (pas les quiz des
//   11 modules Moodle).
// - "Difficulte persistante" = au moins 2 etapes en echec persistant
//   (alerte non resolue, voir local_l3madmin_seq_alerts) pour un meme
//   stagiaire.
// - Reserve au Responsable pedagogique (pas a tout formateur).
// Deploy : SFTP -> /tmp/ -> docker cp moodle_app:/var/data/www/public/
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/l3m_branding.php');
require_once(__DIR__ . '/l3m_referentiel.php');
require_login();

if (!l3m_is_ped_lead($USER->id) && !is_siteadmin()) {
    redirect(new moodle_url($CFG->wwwroot . '/espace-formateur.php'));
}

// Seuil (nombre d'etapes en echec persistant) a partir duquel on considere
// la difficulte comme "persistante" et pas juste isolee.
define('L3M_SUIVI_SEUIL_PERSISTANT', 2);

// ── Colonnes de la matrice : un bloc QCM note par etape PUBLIEE (pas les
//    brouillons — pas de sens de suivre des resultats sur un contenu qui
//    n'est pas encore accessible aux eleves). ──
$sequences = l3m_seq_sequences();
$colonnes = [];
foreach ($sequences as $seqid => $seq) {
    foreach ($seq['etapes'] as $etapeid => $etape) {
        if (!l3m_seq_etape_est_publiee($etape)) continue;
        $contentfile = __DIR__ . '/' . $etape['contentfile'];
        if (!file_exists($contentfile)) continue;
        $content = json_decode(file_get_contents($contentfile), true);
        $dbid = l3m_seq_db_id($seqid, $etapeid);
        $qcm_blocs = array_values(array_filter($content['blocs'] ?? [], fn($b) => ($b['type'] ?? '') === 'qcm_bloc'));
        foreach ($qcm_blocs as $bloc) {
            $colonnes[] = [
                'dbid'      => $dbid,
                'seqid'     => $seqid,
                'etapeid'   => $etapeid,
                'blocCle'   => $bloc['cle'],
                'label'     => $etape['titre'] . (count($qcm_blocs) > 1 ? ' — ' . ($bloc['titre_bloc'] ?? $bloc['cle']) : ''),
                'ids'       => array_column($bloc['questions'] ?? [], 'id'),
                'seuil_pct' => (int)($bloc['seuil_pct'] ?? 70),
            ];
        }
    }
}

// ── Etat d'un stagiaire sur une colonne : absent / encours / reussi / echec
//    (echec = alerte non resolue = 2e passage encore rate, voir
//    sequence-player.php action report_remediation_failure). ──
function l3m_suivi_etat(int $uid, array $col): array {
    global $DB;
    $answers = $DB->get_records('local_l3madmin_seq_answers', ['userid' => $uid, 'sequenceid' => $col['dbid']]);
    $latest = [];
    foreach ($answers as $a) {
        if (!in_array($a->screenid, $col['ids'], true)) continue;
        if (!isset($latest[$a->screenid]) || $a->attempt >= $latest[$a->screenid]->attempt) $latest[$a->screenid] = $a;
    }
    if (!$latest) return ['etat' => 'absent', 'pct' => null];
    $total = count($col['ids']);
    $correct = 0;
    foreach ($col['ids'] as $qid) {
        if (isset($latest[$qid]) && (int)$latest[$qid]->qcmcorrect === 1) $correct++;
    }
    $pct = $total ? (int)round($correct / $total * 100) : 0;
    $alerte = $DB->record_exists('local_l3madmin_seq_alerts', ['userid' => $uid, 'sequenceid' => $col['dbid'], 'bloccle' => $col['blocCle'], 'resolved' => 0]);
    if ($alerte) return ['etat' => 'echec', 'pct' => $pct];
    if ($pct >= $col['seuil_pct']) return ['etat' => 'reussi', 'pct' => $pct];
    return ['etat' => 'encours', 'pct' => $pct];
}

// ── Stagiaires (role eleve) ayant au moins une tentative sur une des
//    colonnes ci-dessus — on ne liste pas tous les eleves inscrits, juste
//    ceux qui ont deja touche a une sequence. ──
$student_roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
$students_raw = $student_roleid ? $DB->get_records_sql(
    "SELECT u.id, u.firstname, u.lastname FROM {role_assignments} ra
       JOIN {user} u ON u.id = ra.userid
      WHERE ra.roleid = ? AND u.deleted = 0",
    [$student_roleid]
) : [];

$rows = [];
foreach ($students_raw as $u) {
    $cells = [];
    $has_any = false;
    $nb_echec = 0;
    foreach ($colonnes as $ci => $col) {
        $etat = l3m_suivi_etat((int)$u->id, $col);
        if ($etat['etat'] !== 'absent') $has_any = true;
        if ($etat['etat'] === 'echec') $nb_echec++;
        $cells[$ci] = $etat;
    }
    if (!$has_any) continue;
    $rows[] = [
        'userid' => $u->id,
        'nom' => fullname($u),
        'cells' => $cells,
        'nb_echec' => $nb_echec,
        'persistant' => $nb_echec >= L3M_SUIVI_SEUIL_PERSISTANT,
    ];
}
usort($rows, fn($a, $b) => $b['nb_echec'] <=> $a['nb_echec']);

$brand = l3m_get_branding_for_user($USER->id);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Suivi des stagiaires</title>
<style>
:root { --teal:<?= $brand['teal'] ?>; --teal-d:<?= $brand['teal_d'] ?>; --bg:<?= $brand['bg'] ?>; --card:<?= $brand['card'] ?>; --ink:<?= $brand['ink'] ?>; --muted:<?= $brand['muted'] ?>; --line:<?= $brand['line'] ?>; --red:#D64545; }
* { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",system-ui,sans-serif; background:var(--bg); color:var(--ink); }
.topbar { background:var(--card); border-bottom:1px solid var(--line); padding:12px 20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; row-gap:8px; }
.topbar b { font-size:14px; }
.topbar .tag { background:#5B4FCF; color:#fff; font-size:10px; font-weight:800; padding:3px 9px; border-radius:99px; }
.wrap { max-width:1100px; margin:0 auto; padding:24px 20px 60px; }
h1 { font-size:19px; margin:0 0 4px; }
.sub { color:var(--muted); font-size:13px; margin:0 0 16px; }
.legend { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:18px; font-size:12px; color:var(--muted); }
.legend .dot { display:inline-block; width:11px; height:11px; border-radius:3px; margin-right:5px; vertical-align:-1px; }
.tbl-wrap { overflow-x:auto; border:1px solid var(--line); border-radius:12px; background:var(--card); }
table { border-collapse:collapse; width:100%; font-size:12.5px; }
th, td { padding:8px 10px; text-align:center; border-bottom:1px solid var(--line); white-space:nowrap; }
th { background:#FAFAF7; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; color:var(--muted); max-width:140px; white-space:normal; }
td.nom, th.nom { text-align:left; position:sticky; left:0; background:var(--card); z-index:2; font-weight:700; white-space:normal; min-width:150px; }
th.nom { background:#FAFAF7; z-index:3; }
tr:last-child td { border-bottom:none; }
.cell { display:inline-flex; align-items:center; justify-content:center; width:38px; height:26px; border-radius:7px; font-weight:800; font-size:11px; }
.cell.reussi { background:#E3F2EF; color:#1C5F58; }
.cell.echec { background:#FBE7E7; color:var(--red); }
.cell.encours { background:#FFF3DC; color:#8A6100; }
.cell.absent { background:#F5F5F1; color:#CCC; }
.badge-pers { display:inline-block; background:#FBE7E7; color:var(--red); font-size:10.5px; font-weight:800; padding:3px 9px; border-radius:99px; white-space:nowrap; }
.empty-state { text-align:center; padding:50px 20px; color:var(--muted); font-size:13px; }
</style>
</head>
<body>
<div class="topbar">
  <img src="<?= htmlspecialchars($brand['logo'], ENT_QUOTES) ?>" style="height:28px">
  <b>Suivi global des stagiaires</b>
  <span class="tag">🧪 TEST</span>
  <span style="flex:1"></span>
  <a href="<?= $CFG->wwwroot ?>/sequence-select.php" style="font-size:12px;color:var(--muted);text-decoration:none;margin-right:10px">&larr; Séquences</a>
  <a href="<?= $CFG->wwwroot ?>/espace-formateur.php" style="font-size:12px;color:var(--muted);text-decoration:none">Mon espace</a>
</div>
<div class="wrap">
  <h1>Suivi global des stagiaires</h1>
  <p class="sub">Résultats aux QCM notés de chaque étape publiée, tous stagiaires confondus. Une difficulté est considérée <b>persistante</b> à partir de <?= L3M_SUIVI_SEUIL_PERSISTANT ?> étapes en échec (2<sup>e</sup> passage toujours raté).</p>
  <div class="legend">
    <span><span class="dot" style="background:#E3F2EF;border:1px solid #1C5F58"></span>Réussi</span>
    <span><span class="dot" style="background:#FFF3DC;border:1px solid #8A6100"></span>En cours (pas encore au seuil)</span>
    <span><span class="dot" style="background:#FBE7E7;border:1px solid var(--red)"></span>Échec persistant (alerte)</span>
    <span><span class="dot" style="background:#F5F5F1;border:1px solid #CCC"></span>Pas encore fait</span>
  </div>

  <?php if (!$colonnes): ?>
    <div class="empty-state">Aucune étape publiée avec QCM noté pour l'instant.</div>
  <?php elseif (!$rows): ?>
    <div class="empty-state">Aucun stagiaire n'a encore de résultat sur une séquence.</div>
  <?php else: ?>
  <div class="tbl-wrap">
  <table>
    <tr>
      <th class="nom">Stagiaire</th>
      <?php foreach ($colonnes as $col): ?><th><?= htmlspecialchars($col['label'], ENT_QUOTES) ?></th><?php endforeach; ?>
      <th>Difficulté</th>
    </tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td class="nom"><?= htmlspecialchars($r['nom'], ENT_QUOTES) ?></td>
      <?php foreach ($colonnes as $ci => $col): $c = $r['cells'][$ci]; ?>
        <td><span class="cell <?= $c['etat'] ?>" title="<?= htmlspecialchars($col['label'], ENT_QUOTES) ?>"><?= $c['pct'] !== null ? $c['pct'] . '%' : '—' ?></span></td>
      <?php endforeach; ?>
      <td><?php if ($r['persistant']): ?><span class="badge-pers">⚠ <?= $r['nb_echec'] ?> étape(s)</span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>
</div>
</body>
</html>

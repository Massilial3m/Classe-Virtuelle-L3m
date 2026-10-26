<?php
// Selecteur de sequence/etape pour les sequences de positionnement
// pedagogique — point d'entree commun eleve (lancer) / formateur (editer le
// contenu). Adressage par Sequence + Etape (voir l3m_referentiel.php)
// depuis le 15/09/2026 — remplace l'ancien adressage par Module plateforme
// (liste plate des 11 cours Moodle) : chaque Sequence peut maintenant
// regrouper plusieurs Etapes, chacune rattachee a une ou plusieurs Unites
// du referentiel officiel.
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/l3m_branding.php');
require_once(__DIR__ . '/l3m_referentiel.php');
require_login();

// Restructuration Matiere > Sous-matiere > Unite / sequences multi-etapes
// (voir l3m_referentiel.php) — generalisee a tous les comptes le 17/09/2026
// (reservee aux testeurs beta Latifa pendant le chantier).

$student_roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
$is_student = $student_roleid && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $student_roleid]);
$teacher_shortnames = ['editingteacher', 'teacher', 'manager'];
list($insql, $inparams) = $DB->get_in_or_equal($teacher_shortnames);
$is_formateur = $DB->record_exists_sql(
    "SELECT 1 FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
      WHERE ra.userid = ? AND r.shortname $insql",
    array_merge([$USER->id], $inparams)
);
// Edition du contenu reservee au Responsable pedagogique — un formateur
// simple garde "Résultats" (lecture) mais pas "Éditer".
$is_ped_lead = l3m_is_ped_lead($USER->id) || is_siteadmin();

// Journal d'activite (voir l3m_log_activity() dans l3m_branding.php) —
// alimente la feuille d'emargement LBA (emargement.php).
if ($is_student && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    l3m_log_activity($USER->id, 'Mes séquences');
}

// ── Creation de sequences/etapes (constructeur de blocs, partie B) — appels
//    AJAX depuis les formulaires "+ Nouvelle sequence"/"+ Nouvelle etape"
//    plus bas. Reservee au Responsable pedagogique. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    header('Content-Type: application/json');
    if (!$is_ped_lead) { echo json_encode(['ok' => false, 'msg' => 'Réservé au responsable pédagogique.']); exit; }
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
    if ($action === 'create_sequence') {
        $titre = clean_param(required_param('titre', PARAM_TEXT), PARAM_TEXT);
        if ($titre === '') { echo json_encode(['ok' => false, 'msg' => 'Titre requis.']); exit; }
        $seqid = l3m_seq_create_sequence($titre);
        echo json_encode(['ok' => true, 'seqid' => $seqid]);
        exit;
    }
    if ($action === 'create_etape') {
        $seqid = required_param('seqid', PARAM_INT);
        $titre = clean_param(required_param('titre', PARAM_TEXT), PARAM_TEXT);
        $unites = optional_param_array('unites', [], PARAM_TEXT);
        if ($titre === '') { echo json_encode(['ok' => false, 'msg' => 'Titre requis.']); exit; }
        $etapeid = l3m_seq_create_etape($seqid, $titre, $unites);
        if ($etapeid === null) { echo json_encode(['ok' => false, 'msg' => 'Séquence introuvable.']); exit; }
        echo json_encode(['ok' => true, 'seqid' => $seqid, 'etapeid' => $etapeid]);
        exit;
    }
    if ($action === 'delete_etape') {
        $seqid = required_param('seqid', PARAM_INT);
        $etapeid = required_param('etapeid', PARAM_INT);
        $ok = l3m_seq_delete_etape($seqid, $etapeid);
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'msg' => 'Étape introuvable.']);
        exit;
    }
    if ($action === 'delete_sequence') {
        $seqid = required_param('seqid', PARAM_INT);
        $ok = l3m_seq_delete_sequence($seqid);
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'msg' => 'Séquence introuvable.']);
        exit;
    }
    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

$sequences = l3m_seq_sequences();
$brand = l3m_get_branding_for_user($USER->id);
$backurl = $is_student ? '/espace-eleve.php' : '/espace-formateur.php';
$sesskey = sesskey();
$unites_aplati = l3m_referentiel_unites_aplati();
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Séquences de positionnement</title>
<style>
:root { --teal:<?= $brand['teal'] ?>; --teal-d:<?= $brand['teal_d'] ?>; --bg:<?= $brand['bg'] ?>; --card:<?= $brand['card'] ?>; --ink:<?= $brand['ink'] ?>; --muted:<?= $brand['muted'] ?>; --line:<?= $brand['line'] ?>; }
* { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",system-ui,sans-serif; background:var(--bg); color:var(--ink); }
.topbar { background:var(--card); border-bottom:1px solid var(--line); padding:12px 20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; row-gap:8px; }
.topbar b { font-size:14px; }
.topbar .tag { background:#5B4FCF; color:#fff; font-size:10px; font-weight:800; padding:3px 9px; border-radius:99px; }
.wrap { max-width:760px; margin:0 auto; padding:24px 20px; }
h1 { font-size:19px; margin:0 0 4px; }
.sub { color:var(--muted); font-size:13px; margin:0 0 8px; }
.ref-note { color:var(--muted); font-size:11.5px; margin:0 0 20px; }
.seq-card { background:var(--card); border:1px solid var(--line); border-radius:14px; margin-bottom:16px; overflow:hidden; }
.seq-head { padding:14px 18px; background:#FAFAF7; border-bottom:1px solid var(--line); font-weight:800; font-size:14px; display:flex; align-items:center; gap:8px; }
.seq-del { margin-left:auto; background:none; border:none; color:#D64545; cursor:pointer; font-size:13px; padding:4px 8px; opacity:.7; }
.seq-del:hover { opacity:1; }
.row { padding:14px 18px; border-bottom:1px solid var(--line); display:flex; align-items:center; gap:12px; }
.row:last-child { border-bottom:none; }
.row .nom { flex:1; }
.row .nom b { font-size:13.5px; display:block; }
.row .nom .unites { font-size:11.5px; color:var(--muted); margin-top:2px; }
.status { font-size:10.5px; font-weight:800; padding:3px 10px; border-radius:99px; white-space:nowrap; }
.status.ready { background:#E3F2EF; color:#1C5F58; }
.status.empty { background:#EEE; color:var(--muted); }
.btn { display:inline-flex; align-items:center; gap:6px; border:none; border-radius:99px; padding:8px 16px; font-size:12px; font-weight:700; cursor:pointer; text-decoration:none; white-space:nowrap; }
.btn.primary { background:var(--teal); color:#fff; }
.btn.ghost { background:var(--card); color:var(--teal-d); border:1.5px solid var(--teal); }
.btn.disabled { background:#EEE; color:#AAA; pointer-events:none; }
.btn.danger { background:var(--card); color:#D64545; border:1.5px solid #D64545; }
.empty-state { text-align:center; padding:40px 20px; color:var(--muted); font-size:13px; }
.addbar { display:flex; gap:8px; margin-bottom:20px; }
.addbar input[type=text] { flex:1; border:1px dashed var(--line); border-radius:8px; padding:10px 12px; font-size:13px; font-family:inherit; background:var(--card); }
.new-etape { border-top:1px solid var(--line); padding:12px 18px; background:#FAFAF7; }
.new-etape input[type=text] { width:100%; border:1px solid var(--line); border-radius:8px; padding:8px 10px; font-size:13px; font-family:inherit; margin-bottom:8px; }
.unites-picker { max-height:160px; overflow-y:auto; border:1px solid var(--line); border-radius:8px; padding:8px 10px; background:#fff; margin-bottom:8px; font-size:12px; }
.unites-picker .mat { font-weight:800; color:var(--muted); text-transform:uppercase; font-size:10px; margin:6px 0 2px; }
.unites-picker label { display:block; padding:2px 0; cursor:pointer; }
@media(max-width:600px){
  /* .row est en flex align-items:center sur toute sa hauteur : quand le nom
     de l'etape est long et retombe sur plusieurs lignes, le badge de statut
     et les boutons se retrouvent centres au milieu du titre au lieu d'etre
     alignes proprement dessous — on passe le titre en pleine largeur et on
     fait retomber statut+boutons sur une 2e ligne, alignes en haut. */
  .row { flex-wrap:wrap; align-items:flex-start; }
  .row .nom { flex:1 1 100%; margin-bottom:2px; }
}
</style>
</head>
<body>
<div class="topbar">
  <img src="<?= htmlspecialchars($brand['logo'], ENT_QUOTES) ?>" style="height:28px">
  <b>Séquences de positionnement</b>
  <span class="tag">🧪 TEST</span>
  <span style="flex:1"></span>
  <?php if ($is_ped_lead): ?>
  <a href="<?= $CFG->wwwroot ?>/suivi-stagiaires.php" style="font-size:12px;color:var(--muted);text-decoration:none;margin-right:10px">📊 Suivi des stagiaires</a>
  <?php endif; ?>
  <a href="<?= $CFG->wwwroot . $backurl ?>" style="font-size:12px;color:var(--muted);text-decoration:none">&larr; Mon espace</a>
</div>
<div class="wrap">
  <h1>Séquences</h1>
  <p class="sub">Chaque séquence regroupe une ou plusieurs étapes ; chaque étape peut couvrir une ou plusieurs unités du référentiel officiel.</p>

  <?php if ($is_ped_lead): ?>
  <div class="addbar">
    <input type="text" id="new_seq_titre" placeholder="Titre de la nouvelle séquence…">
    <button type="button" class="btn ghost" onclick="createSequence()">+ Nouvelle séquence</button>
  </div>
  <?php endif; ?>

  <?php if (!$sequences): ?>
    <div class="empty-state">Aucune séquence cadrée pour l'instant.</div>
  <?php endif; ?>

  <?php foreach ($sequences as $sid => $seq):
      // Une sequence dont toutes les etapes sont en brouillon n'a rien a
      // montrer a un eleve — on saute la carte entiere plutot que d'afficher
      // un titre de sequence suivi de rien.
      $a_au_moins_une_etape_visible = $is_formateur || array_reduce($seq['etapes'], fn($c, $et) => $c || l3m_seq_etape_est_publiee($et), false);
      if (!$a_au_moins_une_etape_visible) continue;
  ?>
  <div class="seq-card">
    <div class="seq-head">
      <span><?= htmlspecialchars($seq['titre'], ENT_QUOTES) ?></span>
      <?php if ($is_ped_lead): ?>
      <button type="button" class="seq-del" onclick="deleteSequence(<?= $sid ?>, this)" title="Supprimer toute la séquence">🗑 Supprimer la séquence</button>
      <?php endif; ?>
    </div>
    <?php if ($is_student):
        // Une Sequence a plusieurs Etapes s'enchaine desormais automatiquement
        // en un seul parcours (voir sequence-player.php, "Continuer vers la
        // partie suivante") — un seul bouton de lancement par Sequence plutot
        // qu'un bouton "Lancer" par Etape, pointant vers la reprise.
        $lancer = l3m_seq_sequence_etape_a_lancer($USER->id, $sid, $seq);
        if ($lancer):
          $etape_cible = l3m_seq_etape($sid, $lancer['etapeid']);
          $label = $lancer['terminee'] ? 'Revoir' : ($lancer['done'] > 0 ? 'Continuer' : 'Lancer');
    ?>
    <div class="row">
      <div class="nom">
        <b><?= $lancer['terminee'] ? 'Séquence terminée' : htmlspecialchars('Partie en cours : ' . $etape_cible['titre'], ENT_QUOTES) ?></b>
        <div class="unites"><?= $lancer['done'] ?>/<?= $lancer['total'] ?> partie(s) terminée(s)</div>
      </div>
      <span class="status ready"><?= $lancer['terminee'] ? '✓ Terminée' : '✓ Disponible' ?></span>
      <a class="btn primary" href="<?= $CFG->wwwroot ?>/sequence-player.php?seq=<?= $sid ?>&etape=<?= $lancer['etapeid'] ?>"><?= $label ?></a>
    </div>
    <?php endif; endif; ?>
    <?php if ($is_formateur): foreach ($seq['etapes'] as $eid => $et):
        // Une etape en brouillon (pas encore publiee par le Responsable
        // pedagogique) est totalement invisible des eleves — seuls les
        // formateurs la voient, avec un badge distinct, pour la previsualiser
        // avant publication (voir l3m_seq_etape_est_publiee()).
        $publiee = l3m_seq_etape_est_publiee($et);
        $contentfile = __DIR__ . '/' . $et['contentfile'];
        $ready = file_exists($contentfile);
        $unite_noms = l3m_seq_etape_unites_noms($et);
    ?>
    <div class="row">
      <div class="nom">
        <b><?= htmlspecialchars($et['titre'], ENT_QUOTES) ?></b>
        <?php if ($unite_noms): ?><div class="unites">Unité(s) : <?= htmlspecialchars(implode(', ', $unite_noms), ENT_QUOTES) ?></div><?php endif; ?>
      </div>
      <?php if (!$publiee): ?>
        <span class="status empty">🚧 Brouillon</span>
      <?php else: ?>
        <span class="status <?= $ready ? 'ready' : 'empty' ?>"><?= $ready ? '✓ Contenu prêt' : 'À compléter' ?></span>
      <?php endif; ?>
        <a class="btn ghost" href="<?= $CFG->wwwroot ?>/sequence-player.php?seq=<?= $sid ?>&etape=<?= $eid ?>">👁 Prévisualiser</a>
        <a class="btn ghost" href="<?= $CFG->wwwroot ?>/sequence-results.php?seq=<?= $sid ?>&etape=<?= $eid ?>">Résultats</a>
        <?php if ($is_ped_lead): ?>
        <a class="btn ghost" href="<?= $CFG->wwwroot ?>/sequence-editor.php?seq=<?= $sid ?>&etape=<?= $eid ?>">Éditer</a>
        <button type="button" class="btn danger" onclick="deleteEtape(<?= $sid ?>, <?= $eid ?>, this)">Supprimer</button>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
    <?php if ($is_ped_lead): ?>
    <div class="new-etape">
      <input type="text" id="new_etape_titre_<?= $sid ?>" placeholder="Titre de la nouvelle étape…">
      <div class="unites-picker" id="new_etape_unites_<?= $sid ?>">
        <?php $cur_mat = null; foreach ($unites_aplati as $code => $u): if ($u['matiere_id'] !== $cur_mat): $cur_mat = $u['matiere_id']; ?>
          <div class="mat"><?= htmlspecialchars($u['matiere_nom'], ENT_QUOTES) ?></div>
        <?php endif; ?>
          <label><input type="checkbox" value="<?= htmlspecialchars($code, ENT_QUOTES) ?>"> <?= htmlspecialchars($code . ' — ' . $u['nom'], ENT_QUOTES) ?></label>
        <?php endforeach; ?>
        <?php if (!$unites_aplati): ?><p style="color:var(--muted);margin:0">Référentiel pas encore rempli.</p><?php endif; ?>
      </div>
      <button type="button" class="btn ghost" onclick="createEtape(<?= $sid ?>)">+ Nouvelle étape</button>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php if ($is_ped_lead): ?>
<script>
var SESSKEY = <?= json_encode($sesskey) ?>;
var PAGEURL = <?= json_encode($CFG->wwwroot . '/sequence-select.php') ?>;
function post(action, data) {
  var fd = new FormData();
  fd.append('sesskey', SESSKEY);
  fd.append('action', action);
  Object.keys(data).forEach(function(k){
    var v = data[k];
    if (Array.isArray(v)) v.forEach(function(item){ fd.append(k + '[]', item); });
    else fd.append(k, v);
  });
  return fetch(PAGEURL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r){ return r.json(); });
}
function createSequence() {
  var el = document.getElementById('new_seq_titre');
  var titre = el.value.trim();
  if (!titre) { alert('Merci d\'indiquer un titre pour la séquence.'); el.focus(); return; }
  post('create_sequence', { titre: titre }).then(function(r){
    if (r.ok) location.reload(); else alert(r.msg || 'Erreur');
  });
}
function createEtape(seqid) {
  var titreEl = document.getElementById('new_etape_titre_' + seqid);
  var titre = titreEl.value.trim();
  if (!titre) { alert('Merci d\'indiquer un titre pour l\'étape.'); titreEl.focus(); return; }
  var boxes = document.querySelectorAll('#new_etape_unites_' + seqid + ' input[type=checkbox]:checked');
  var unites = Array.prototype.map.call(boxes, function(b){ return b.value; });
  if (!unites.length && !confirm('Aucune unité du référentiel sélectionnée — créer quand même cette étape ?')) return;
  post('create_etape', { seqid: seqid, titre: titre, unites: unites }).then(function(r){
    if (r.ok) location.href = PAGEURL.replace('sequence-select.php', 'sequence-editor.php') + '?seq=' + r.seqid + '&etape=' + r.etapeid;
    else alert(r.msg || 'Erreur');
  });
}
function deleteSequence(seqid, btn) {
  if (!confirm('Supprimer TOUTE la séquence et toutes ses parties ? Cette action est définitive.')) return;
  post('delete_sequence', { seqid: seqid }).then(function(r){
    if (r.ok) location.reload(); else alert(r.msg || 'Erreur');
  });
}
function deleteEtape(seqid, etapeid, btn) {
  if (!confirm('Supprimer cette étape ? Cette action est définitive.')) return;
  post('delete_etape', { seqid: seqid, etapeid: etapeid }).then(function(r){
    if (r.ok) location.reload(); else alert(r.msg || 'Erreur');
  });
}
</script>
<?php endif; ?>
</body>
</html>

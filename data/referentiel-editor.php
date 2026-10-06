<?php
// Editeur du referentiel officiel (Matiere > Sous-matiere > Unite, voir
// l3m_referentiel.php) — permet au formateur/a l'equipe pedagogique de
// renommer les titres existants et d'ajouter des matieres/sous-matieres/
// unites, sans passer par un developpeur (le referentiel etait fige en dur
// dans le code jusqu'au 16/09/2026).
// Reserve aux formateurs (pas aux eleves) — meme gating que les autres
// pages du chantier Sequences/Programme.
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/l3m_branding.php');
require_once(__DIR__ . '/l3m_referentiel.php');
require_login();

// Creation/edition du referentiel reservee au Responsable pedagogique (voir
// l3m_is_ped_lead() dans l3m_branding.php) — pas a tout formateur. Un
// formateur simple qui tombe ici (lien pas cense lui etre affiche, voir
// programme.php) est renvoye vers Programme en lecture seule.
if (!l3m_is_ped_lead($USER->id) && !is_siteadmin()) {
    redirect(new moodle_url($CFG->wwwroot . '/programme.php'));
}

// Existe deja dans le referentiel (toutes matieres/sous-matieres confondues) ?
function l3m_ref_code_existe(array $matieres, string $code): bool {
    foreach ($matieres as $mat) {
        foreach (($mat['sous_matieres'] ?? []) as $sm) {
            if (isset($sm['unites'][$code])) return true;
        }
    }
    return false;
}
function l3m_ref_next_id(array $keys): int {
    $ints = array_map('intval', $keys);
    return $ints ? max($ints) + 1 : 1;
}

$saved = false;
$sesskey = sesskey();
$is_ajax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
    $matieres = l3m_referentiel_matieres();

    if ($action === 'save_titles') {
        $mids   = $_POST['matiere_id'] ?? [];
        $mnoms  = $_POST['matiere_nom'] ?? [];
        foreach ($mids as $i => $mid) {
            if (isset($matieres[$mid])) $matieres[$mid]['nom'] = clean_param($mnoms[$i] ?? '', PARAM_TEXT);
        }
        $smmids = $_POST['sm_mid'] ?? [];
        $smids  = $_POST['sm_id'] ?? [];
        $smnoms = $_POST['sm_nom'] ?? [];
        foreach ($smmids as $i => $mid) {
            $sid = $smids[$i] ?? null;
            if ($sid !== null && isset($matieres[$mid]['sous_matieres'][$sid])) {
                $matieres[$mid]['sous_matieres'][$sid]['nom'] = clean_param($smnoms[$i] ?? '', PARAM_TEXT);
            }
        }
        $ucodes = $_POST['unite_code'] ?? [];
        $unoms  = $_POST['unite_nom'] ?? [];
        foreach ($ucodes as $i => $code) {
            foreach ($matieres as &$mat) {
                foreach ($mat['sous_matieres'] as &$sm) {
                    if (isset($sm['unites'][$code])) {
                        $sm['unites'][$code] = clean_param($unoms[$i] ?? '', PARAM_TEXT);
                    }
                }
                unset($sm);
            }
            unset($mat);
        }
        l3m_referentiel_save_matieres($matieres);
        $saved = true;

    } elseif ($action === 'add_matiere') {
        $nom = clean_param(required_param('nom', PARAM_TEXT), PARAM_TEXT);
        header('Content-Type: application/json');
        if ($nom === '') { echo json_encode(['ok' => false, 'msg' => 'Titre requis.']); exit; }
        $newid = l3m_ref_next_id(array_keys($matieres));
        $matieres[$newid] = ['nom' => $nom, 'sous_matieres' => []];
        l3m_referentiel_save_matieres($matieres);
        echo json_encode(['ok' => true]);
        exit;

    } elseif ($action === 'add_sousmatiere') {
        $mid = required_param('matiere_id', PARAM_INT);
        $nom = clean_param(required_param('nom', PARAM_TEXT), PARAM_TEXT);
        header('Content-Type: application/json');
        if ($nom === '' || !isset($matieres[$mid])) { echo json_encode(['ok' => false, 'msg' => 'Titre requis ou matière introuvable.']); exit; }
        $newid = l3m_ref_next_id(array_keys($matieres[$mid]['sous_matieres'] ?? []));
        $matieres[$mid]['sous_matieres'][$newid] = ['nom' => $nom, 'unites' => []];
        l3m_referentiel_save_matieres($matieres);
        echo json_encode(['ok' => true]);
        exit;

    } elseif ($action === 'add_unite') {
        $mid  = required_param('matiere_id', PARAM_INT);
        $sid  = required_param('sousmatiere_id', PARAM_INT);
        $code = trim(clean_param(required_param('code', PARAM_TEXT), PARAM_TEXT));
        $nom  = clean_param(required_param('nom', PARAM_TEXT), PARAM_TEXT);
        header('Content-Type: application/json');
        if ($code === '' || $nom === '') { echo json_encode(['ok' => false, 'msg' => 'Code et titre requis.']); exit; }
        if (!isset($matieres[$mid]['sous_matieres'][$sid])) { echo json_encode(['ok' => false, 'msg' => 'Sous-matière introuvable.']); exit; }
        if (l3m_ref_code_existe($matieres, $code)) { echo json_encode(['ok' => false, 'msg' => 'Ce code existe déjà dans le référentiel.']); exit; }
        $matieres[$mid]['sous_matieres'][$sid]['unites'][$code] = $nom;
        l3m_referentiel_save_matieres($matieres);
        echo json_encode(['ok' => true]);
        exit;
    }
}

$matieres = l3m_referentiel_matieres();
ksort($matieres);
foreach ($matieres as &$__m) { ksort($__m['sous_matieres']); } unset($__m);
$brand = l3m_get_branding_for_user($USER->id);
$refstats = l3m_referentiel_stats();
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Éditeur du programme</title>
<style>
:root { --teal:<?= $brand['teal'] ?>; --teal-d:<?= $brand['teal_d'] ?>; --bg:<?= $brand['bg'] ?>; --card:<?= $brand['card'] ?>; --ink:<?= $brand['ink'] ?>; --muted:<?= $brand['muted'] ?>; --line:<?= $brand['line'] ?>; --red:#D64545; }
* { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",system-ui,sans-serif; background:var(--bg); color:var(--ink); }
.topbar { position:sticky; top:0; z-index:20; background:var(--card); border-bottom:1px solid var(--line); padding:12px 20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; row-gap:8px; }
.topbar b { font-size:14px; }
.topbar .tag { background:#5B4FCF; color:#fff; font-size:10px; font-weight:800; padding:3px 9px; border-radius:99px; }
.wrap { max-width:820px; margin:0 auto; padding:24px 20px 100px; }
.sub { color:var(--muted); font-size:13px; margin:0 0 20px; }
.mat-card { background:var(--card); border:1px solid var(--line); border-radius:14px; margin-bottom:16px; overflow:hidden; }
.mat-head { padding:12px 18px; background:var(--teal); display:flex; align-items:center; gap:8px; }
.mat-head input { flex:1; border:none; background:rgba(255,255,255,.15); color:#fff; font-weight:800; font-size:14px; padding:8px 10px; border-radius:8px; }
.mat-head input::placeholder { color:rgba(255,255,255,.7); }
.mat-body { padding:14px 18px; }
.sm-block { border:1px solid var(--line); border-radius:10px; padding:12px 14px; margin-bottom:12px; background:#FAFAF7; }
.sm-head { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
.sm-head .code { font-size:11px; font-weight:800; color:var(--teal-d); white-space:nowrap; }
.sm-head input { flex:1; border:1px solid var(--line); border-radius:8px; padding:6px 10px; font-size:13px; font-weight:700; font-family:inherit; }
.u-row { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
.u-row .code { font-size:11px; color:var(--muted); width:56px; flex-shrink:0; }
.u-row input[type=text] { flex:1; border:1px solid var(--line); border-radius:8px; padding:6px 10px; font-size:12.5px; font-family:inherit; }
.add-row { display:flex; gap:6px; margin-top:8px; }
.add-row input { border:1px dashed var(--line); border-radius:8px; padding:6px 10px; font-size:12.5px; font-family:inherit; background:#fff; }
.add-row input.code { width:70px; }
.add-row input.nom { flex:1; }
.btn { display:inline-flex; align-items:center; gap:6px; border:none; border-radius:99px; padding:10px 22px; font-size:13px; font-weight:700; cursor:pointer; background:var(--teal); color:#fff; }
.btn.mini { padding:6px 14px; font-size:12px; }
.btn.ghost { background:var(--card); color:var(--teal-d); border:1.5px solid var(--teal); }
.savebar { position:fixed; bottom:0; left:0; right:0; background:var(--card); border-top:1px solid var(--line); padding:12px 20px; display:flex; justify-content:center; gap:12px; box-shadow:0 -2px 10px rgba(0,0,0,.06); }
.toast { position:fixed; top:60px; left:50%; transform:translateX(-50%); background:#2E9488; color:#fff; padding:10px 20px; border-radius:99px; font-size:13px; font-weight:700; z-index:99; }
.addmat { display:flex; gap:8px; margin-bottom:20px; }
.addmat input { flex:1; border:1px dashed var(--line); border-radius:8px; padding:10px 12px; font-size:13px; font-family:inherit; background:var(--card); }
</style>
</head>
<body>
<div class="topbar">
  <img src="<?= htmlspecialchars($brand['logo'], ENT_QUOTES) ?>" style="height:28px">
  <b>Éditeur du programme</b>
  <span class="tag">🧪 TEST</span>
  <span style="flex:1"></span>
  <a href="<?= $CFG->wwwroot ?>/programme.php" style="font-size:12px;color:var(--muted);text-decoration:none;margin-right:10px">&larr; Programme</a>
  <a href="<?= $CFG->wwwroot ?>/espace-formateur.php" style="font-size:12px;color:var(--muted);text-decoration:none">Mon espace</a>
</div>
<?php if ($saved): ?><div class="toast">✓ Titres enregistrés</div><?php endif; ?>
<div class="wrap">
  <p class="sub">Renommez les titres existants ou ajoutez des matières / sous-matières / unités. Référentiel rempli à <?= $refstats['pct_complet'] ?> % (<?= $refstats['unites_connues'] ?>/<?= $refstats['unites_annoncees'] ?> unités connues).</p>

  <div class="addmat">
    <input type="text" id="new_matiere_nom" placeholder="Titre de la nouvelle matière…">
    <button type="button" class="btn mini" onclick="addMatiere()">+ Ajouter une matière</button>
  </div>

  <form method="post" id="titles-form">
  <input type="hidden" name="sesskey" value="<?= $sesskey ?>">
  <input type="hidden" name="action" value="save_titles">

  <?php foreach ($matieres as $mid => $matiere): ?>
  <div class="mat-card">
    <div class="mat-head">
      <span style="color:#fff;font-size:11px;font-weight:800">M<?= $mid ?></span>
      <input type="hidden" name="matiere_id[]" value="<?= $mid ?>">
      <input type="text" name="matiere_nom[]" value="<?= htmlspecialchars($matiere['nom'], ENT_QUOTES) ?>">
    </div>
    <div class="mat-body">
      <?php foreach (($matiere['sous_matieres'] ?? []) as $sid => $sm): ?>
      <div class="sm-block">
        <div class="sm-head">
          <span class="code"><?= $mid ?>.<?= $sid ?></span>
          <input type="hidden" name="sm_mid[]" value="<?= $mid ?>">
          <input type="hidden" name="sm_id[]" value="<?= $sid ?>">
          <input type="text" name="sm_nom[]" value="<?= htmlspecialchars($sm['nom'], ENT_QUOTES) ?>">
        </div>
        <?php foreach (($sm['unites'] ?? []) as $code => $nom): ?>
        <div class="u-row">
          <span class="code"><?= htmlspecialchars($code, ENT_QUOTES) ?></span>
          <input type="hidden" name="unite_code[]" value="<?= htmlspecialchars($code, ENT_QUOTES) ?>">
          <input type="text" name="unite_nom[]" value="<?= htmlspecialchars($nom, ENT_QUOTES) ?>">
        </div>
        <?php endforeach; ?>
        <div class="add-row">
          <input type="text" class="code" id="new_unite_code_<?= $mid ?>_<?= $sid ?>" placeholder="ex. <?= $mid ?>.<?= $sid ?>.<?= count($sm['unites'] ?? []) + 1 ?>">
          <input type="text" class="nom" id="new_unite_nom_<?= $mid ?>_<?= $sid ?>" placeholder="Titre de la nouvelle unité…">
          <button type="button" class="btn mini ghost" onclick="addUnite(<?= $mid ?>, <?= $sid ?>)">+ Unité</button>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="add-row">
        <input type="text" class="nom" id="new_sm_nom_<?= $mid ?>" placeholder="Titre de la nouvelle sous-matière…">
        <button type="button" class="btn mini ghost" onclick="addSousMatiere(<?= $mid ?>)">+ Sous-matière</button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (!$matieres): ?>
    <p class="sub">Aucune matière pour l'instant — ajoutez-en une ci-dessus.</p>
  <?php endif; ?>

  <div class="savebar">
    <button type="submit" class="btn">💾 Enregistrer les titres</button>
  </div>
  </form>
</div>
<script>
var SESSKEY = <?= json_encode($sesskey) ?>;
var PAGEURL = <?= json_encode($CFG->wwwroot . '/referentiel-editor.php') ?>;

function post(action, data) {
  var fd = new FormData();
  fd.append('sesskey', SESSKEY);
  fd.append('action', action);
  Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
  return fetch(PAGEURL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r){ return r.json(); });
}

function addMatiere() {
  var el = document.getElementById('new_matiere_nom');
  var nom = el.value.trim();
  if (!nom) { el.focus(); return; }
  post('add_matiere', { nom: nom }).then(function(r){
    if (r.ok) location.reload(); else alert(r.msg || 'Erreur');
  });
}
function addSousMatiere(mid) {
  var el = document.getElementById('new_sm_nom_' + mid);
  var nom = el.value.trim();
  if (!nom) { el.focus(); return; }
  post('add_sousmatiere', { matiere_id: mid, nom: nom }).then(function(r){
    if (r.ok) location.reload(); else alert(r.msg || 'Erreur');
  });
}
function addUnite(mid, sid) {
  var codeEl = document.getElementById('new_unite_code_' + mid + '_' + sid);
  var nomEl  = document.getElementById('new_unite_nom_' + mid + '_' + sid);
  var code = codeEl.value.trim(), nom = nomEl.value.trim();
  if (!code || !nom) { (code ? nomEl : codeEl).focus(); return; }
  post('add_unite', { matiere_id: mid, sousmatiere_id: sid, code: code, nom: nom }).then(function(r){
    if (r.ok) location.reload(); else alert(r.msg || 'Erreur');
  });
}
</script>
</body>
</html>

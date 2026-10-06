<?php
// Editeur des "Sequences" affichees dans la section "Mes modules"
// (espace-eleve.php / espace-formateur.php) — permet au Responsable
// pedagogique de creer des sequences et d'y rattacher librement des
// "modules" (Sous-matieres et/ou Unites du referentiel officiel, voir
// l3m_referentiel.php). Simple repertoire d'affichage, aucun contenu/QCM
// derriere — A NE PAS CONFONDRE avec sequence-editor.php (Sequences de
// positionnement, Etapes avec contenu interactif).
// Reserve au Responsable pedagogique (voir l3m_is_ped_lead()), meme gating
// que referentiel-editor.php.
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/l3m_branding.php');
require_once(__DIR__ . '/l3m_referentiel.php');
require_login();

if (!l3m_is_ped_lead($USER->id) && !is_siteadmin()) {
    redirect(new moodle_url($CFG->wwwroot . '/espace-formateur.php'));
}

function l3m_progseq_next_id(array $keys): int {
    $ints = array_map('intval', $keys);
    return $ints ? max($ints) + 1 : 1;
}

$saved = false;
$sesskey = sesskey();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
    $sequences = l3m_progseq_all();

    if ($action === 'save_titles') {
        $sids  = $_POST['seq_id'] ?? [];
        $snoms = $_POST['seq_nom'] ?? [];
        foreach ($sids as $i => $sid) {
            if (isset($sequences[$sid])) $sequences[$sid]['titre'] = clean_param($snoms[$i] ?? '', PARAM_TEXT);
        }
        l3m_progseq_save($sequences);
        $saved = true;

    } elseif ($action === 'add_sequence') {
        $nom = clean_param(required_param('nom', PARAM_TEXT), PARAM_TEXT);
        header('Content-Type: application/json');
        if ($nom === '') { echo json_encode(['ok' => false, 'msg' => 'Titre requis.']); exit; }
        $newid = l3m_progseq_next_id(array_keys($sequences));
        $sequences[$newid] = ['titre' => $nom, 'modules' => []];
        l3m_progseq_save($sequences);
        echo json_encode(['ok' => true]);
        exit;

    } elseif ($action === 'delete_sequence') {
        $seqid = required_param('seqid', PARAM_INT);
        header('Content-Type: application/json');
        unset($sequences[$seqid]);
        l3m_progseq_save($sequences);
        echo json_encode(['ok' => true]);
        exit;

    } elseif ($action === 'add_module') {
        $seqid = required_param('seqid', PARAM_INT);
        $ref   = clean_param(required_param('ref', PARAM_TEXT), PARAM_TEXT);
        header('Content-Type: application/json');
        if (!isset($sequences[$seqid])) { echo json_encode(['ok' => false, 'msg' => 'Séquence introuvable.']); exit; }
        if (!l3m_progseq_resolve_module($ref)) { echo json_encode(['ok' => false, 'msg' => 'Module introuvable dans le référentiel.']); exit; }
        if (!in_array($ref, $sequences[$seqid]['modules'] ?? [], true)) {
            $sequences[$seqid]['modules'][] = $ref;
        }
        l3m_progseq_save($sequences);
        echo json_encode(['ok' => true]);
        exit;

    } elseif ($action === 'remove_module') {
        $seqid = required_param('seqid', PARAM_INT);
        $ref   = clean_param(required_param('ref', PARAM_TEXT), PARAM_TEXT);
        header('Content-Type: application/json');
        if (isset($sequences[$seqid])) {
            $sequences[$seqid]['modules'] = array_values(array_filter(
                $sequences[$seqid]['modules'] ?? [],
                fn($r) => $r !== $ref
            ));
        }
        l3m_progseq_save($sequences);
        echo json_encode(['ok' => true]);
        exit;

    } elseif ($action === 'set_etape') {
        // Lie (ou delie, si etape_seqid/etapeid absents) l'UNIQUE Etape du
        // registre QCM qui pilote le bouton "Faire l'etape" de cette
        // Sequence (voir l3m_progseq_etape_status()) — une Sequence de "Mes
        // modules" = au plus une Etape, pas une par module.
        $seqid = required_param('seqid', PARAM_INT);
        $etape_seqid = optional_param('etape_seqid', 0, PARAM_INT);
        $etape_etapeid = optional_param('etape_etapeid', 0, PARAM_INT);
        header('Content-Type: application/json');
        if (!isset($sequences[$seqid])) { echo json_encode(['ok' => false, 'msg' => 'Séquence introuvable.']); exit; }
        if ($etape_seqid && $etape_etapeid) {
            $sequences[$seqid]['etape_seqid'] = $etape_seqid;
            $sequences[$seqid]['etape_etapeid'] = $etape_etapeid;
        } else {
            unset($sequences[$seqid]['etape_seqid'], $sequences[$seqid]['etape_etapeid']);
        }
        l3m_progseq_save($sequences);
        echo json_encode(['ok' => true]);
        exit;

    } elseif ($action === 'set_type') {
        // 'etape' (defaut, cours en distanciel classique) ou
        // 'classe_virtuelle' (lien de connexion direct, ex. Glowbl) — voir
        // l3m_progseq_for_display() dans l3m_referentiel.php.
        $seqid = required_param('seqid', PARAM_INT);
        $type = required_param('type', PARAM_ALPHANUMEXT);
        header('Content-Type: application/json');
        if (!isset($sequences[$seqid])) { echo json_encode(['ok' => false, 'msg' => 'Séquence introuvable.']); exit; }
        if (!in_array($type, ['etape', 'classe_virtuelle'], true)) { echo json_encode(['ok' => false, 'msg' => 'Type invalide.']); exit; }
        $sequences[$seqid]['type'] = $type;
        l3m_progseq_save($sequences);
        echo json_encode(['ok' => true]);
        exit;

    } elseif ($action === 'set_cv_url') {
        $seqid = required_param('seqid', PARAM_INT);
        $url = clean_param(required_param('url', PARAM_URL), PARAM_URL);
        header('Content-Type: application/json');
        if (!isset($sequences[$seqid])) { echo json_encode(['ok' => false, 'msg' => 'Séquence introuvable.']); exit; }
        $sequences[$seqid]['cv_url'] = $url;
        l3m_progseq_save($sequences);
        echo json_encode(['ok' => true]);
        exit;

    } elseif ($action === 'set_cv_auto') {
        // Case a cocher "Connexion automatique" — aucun cours/module choisi
        // ici, juste un oui/non. A l'activation, reserve une salle Glowbl
        // distincte de celles deja prises par d'autres Sequences (voir
        // l3m_glowbl_assign_room() dans l3m_branding.php) et la fixe sur
        // cette Sequence (cv_cmid) pour qu'elle garde toujours la meme.
        $seqid = required_param('seqid', PARAM_INT);
        $auto = optional_param('auto', 0, PARAM_BOOL);
        header('Content-Type: application/json');
        if (!isset($sequences[$seqid])) { echo json_encode(['ok' => false, 'msg' => 'Séquence introuvable.']); exit; }
        $sequences[$seqid]['cv_auto'] = $auto;
        if ($auto && empty($sequences[$seqid]['cv_cmid'])) {
            $used = [];
            foreach ($sequences as $osid => $oseq) {
                if ((string)$osid !== (string)$seqid && !empty($oseq['cv_auto']) && !empty($oseq['cv_cmid'])) {
                    $used[] = (int)$oseq['cv_cmid'];
                }
            }
            $cmid = l3m_glowbl_assign_room($used);
            if ($cmid) $sequences[$seqid]['cv_cmid'] = $cmid;
        }
        l3m_progseq_save($sequences);
        echo json_encode(['ok' => true]);
        exit;

    } elseif ($action === 'set_cv_module_done') {
        // Coche/decoche un module precis comme "aborde" pendant une seance
        // "classe virtuelle" — pas de suivi automatique possible (Glowbl ne
        // remonte pas la presence), donc a la main, module par module (pas
        // un "tout ou rien" par Sequence : question du 06/10/2026, le
        // Responsable pedagogique peut n'avoir traite qu'une partie des
        // modules pendant la seance). Sert a l3m_progseq_sequences_for_unite()
        // pour gater le deverrouillage des modules qu'elle partage avec
        // d'autres Sequences.
        $seqid = required_param('seqid', PARAM_INT);
        $ref = clean_param(required_param('ref', PARAM_TEXT), PARAM_TEXT);
        $done = optional_param('done', 0, PARAM_BOOL);
        header('Content-Type: application/json');
        if (!isset($sequences[$seqid])) { echo json_encode(['ok' => false, 'msg' => 'Séquence introuvable.']); exit; }
        $list = $sequences[$seqid]['cv_done_modules'] ?? [];
        if ($done) {
            if (!in_array($ref, $list, true)) $list[] = $ref;
        } else {
            $list = array_values(array_filter($list, fn($r) => $r !== $ref));
        }
        $sequences[$seqid]['cv_done_modules'] = $list;
        l3m_progseq_save($sequences);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// Rattrapage : des Sequences deja passees en "connexion automatique" avant
// l'introduction des salles distinctes (toutes partageaient encore la meme
// salle fixe) recoivent ici leur propre salle, une seule fois.
$sequences_check = l3m_progseq_all();
$sequences_changed = false;
$used_cmids = [];
foreach ($sequences_check as $seq) {
    if (!empty($seq['cv_auto']) && !empty($seq['cv_cmid'])) $used_cmids[] = (int)$seq['cv_cmid'];
}
foreach ($sequences_check as $sid => &$seq) {
    if (!empty($seq['cv_auto']) && empty($seq['cv_cmid'])) {
        $cmid = l3m_glowbl_assign_room($used_cmids);
        if ($cmid) {
            $seq['cv_cmid'] = $cmid;
            $used_cmids[] = $cmid;
            $sequences_changed = true;
        }
    }
}
unset($seq);
if ($sequences_changed) {
    l3m_progseq_save($sequences_check);
}

$sequences = l3m_progseq_all();
ksort($sequences);
$picker = l3m_progseq_picker_options();
$etapes_picker = l3m_progseq_etapes_picker();
$glowbl_default = l3m_glowbl_url();
$brand = l3m_get_branding_for_user($USER->id);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Éditeur des séquences</title>
<style>
:root { --teal:<?= $brand['teal'] ?>; --teal-d:<?= $brand['teal_d'] ?>; --teal-t:<?= $brand['teal_t'] ?>; --bg:<?= $brand['bg'] ?>; --card:<?= $brand['card'] ?>; --ink:<?= $brand['ink'] ?>; --muted:<?= $brand['muted'] ?>; --line:<?= $brand['line'] ?>; --red:#D64545; }
* { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",system-ui,sans-serif; background:var(--bg); color:var(--ink); }
.topbar { position:sticky; top:0; z-index:20; background:var(--card); border-bottom:1px solid var(--line); padding:12px 20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; row-gap:8px; }
.topbar b { font-size:14px; }
.wrap { max-width:820px; margin:0 auto; padding:24px 20px 100px; }
.sub { color:var(--muted); font-size:13px; margin:0 0 20px; }
.seq-card { background:var(--card); border:1px solid var(--line); border-radius:14px; margin-bottom:16px; overflow:hidden; }
.seq-head { padding:12px 18px; background:var(--teal); display:flex; align-items:center; gap:8px; }
.seq-head input { flex:1; border:none; background:rgba(255,255,255,.15); color:#fff; font-weight:800; font-size:14px; padding:8px 10px; border-radius:8px; }
.seq-head input::placeholder { color:rgba(255,255,255,.7); }
.seq-del { background:none; border:none; color:rgba(255,255,255,.85); cursor:pointer; font-size:13px; padding:4px 8px; }
.seq-body { padding:14px 18px; }
.mod-chip { display:inline-flex; align-items:center; gap:6px; background:var(--teal-t); color:var(--teal-d); border-radius:99px; padding:5px 8px 5px 12px; font-size:12px; font-weight:600; margin:0 6px 6px 0; }
.mod-chip button { background:none; border:none; color:var(--teal-d); cursor:pointer; font-size:13px; line-height:1; padding:2px; opacity:.7; }
.mod-chip button:hover { opacity:1; }
.mod-chip .tag { font-size:9px; text-transform:uppercase; opacity:.7; font-weight:800; }
.add-row { display:flex; gap:6px; margin-top:10px; }
.add-row select { flex:1; border:1px dashed var(--line); border-radius:8px; padding:7px 10px; font-size:12.5px; font-family:inherit; background:#fff; }
.btn { display:inline-flex; align-items:center; gap:6px; border:none; border-radius:99px; padding:10px 22px; font-size:13px; font-weight:700; cursor:pointer; background:var(--teal); color:#fff; }
.btn.mini { padding:6px 14px; font-size:12px; }
.btn.ghost { background:var(--card); color:var(--teal-d); border:1.5px solid var(--teal); }
.btn:disabled { background:var(--card); color:var(--muted); border:1.5px dashed var(--line); cursor:not-allowed; }
.add-row { flex-wrap:wrap; }
.add-row .btn { flex-shrink:0; white-space:nowrap; }
.etape-row { margin-bottom:14px; padding-bottom:14px; border-bottom:1px dashed var(--line); }
.etape-row label { display:block; font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; margin-bottom:5px; }
.etape-row select { width:100%; border:1px solid var(--line); border-radius:8px; padding:8px 10px; font-size:12.5px; font-family:inherit; background:#fff; }
.savebar { position:fixed; bottom:0; left:0; right:0; background:var(--card); border-top:1px solid var(--line); padding:12px 20px; display:flex; justify-content:center; gap:12px; box-shadow:0 -2px 10px rgba(0,0,0,.06); }
.toast { position:fixed; top:60px; left:50%; transform:translateX(-50%); background:#2E9488; color:#fff; padding:10px 20px; border-radius:99px; font-size:13px; font-weight:700; z-index:99; }
.addseq { display:flex; gap:8px; margin-bottom:20px; }
.addseq input { flex:1; border:1px dashed var(--line); border-radius:8px; padding:10px 12px; font-size:13px; font-family:inherit; background:var(--card); }
</style>
</head>
<body>
<div class="topbar">
  <img src="<?= htmlspecialchars($brand['logo'], ENT_QUOTES) ?>" style="height:28px">
  <b>Éditeur des séquences</b>
  <span style="flex:1"></span>
  <a href="<?= $CFG->wwwroot ?>/espace-formateur.php" style="font-size:12px;color:var(--muted);text-decoration:none">&larr; Mon espace</a>
</div>
<?php if ($saved): ?><div class="toast">✓ Titres enregistrés</div><?php endif; ?>
<div class="wrap">
  <p class="sub">Chaque Séquence regroupe des modules choisis parmi les Sous-matières et Unités du <a href="<?= $CFG->wwwroot ?>/referentiel-editor.php">référentiel (Programme)</a>. Elles s'affichent dans « Mes modules », côté élève et formateur.</p>

  <div class="addseq">
    <input type="text" id="new_seq_nom" placeholder="Titre de la nouvelle séquence…">
    <button type="button" class="btn mini" onclick="addSequence()">+ Ajouter une séquence</button>
  </div>

  <form method="post" id="titles-form">
  <input type="hidden" name="sesskey" value="<?= $sesskey ?>">
  <input type="hidden" name="action" value="save_titles">

  <?php foreach ($sequences as $sid => $seq): ?>
  <?php
    $current = $seq['modules'] ?? [];
    $currentSet = array_flip($current);
  ?>
  <div class="seq-card">
    <div class="seq-head">
      <span style="color:#fff;font-size:11px;font-weight:800">S<?= $sid ?></span>
      <input type="hidden" name="seq_id[]" value="<?= $sid ?>">
      <input type="text" name="seq_nom[]" value="<?= htmlspecialchars($seq['titre'] ?? '', ENT_QUOTES) ?>">
      <button type="button" class="seq-del" onclick="deleteSequence(<?= $sid ?>, this)">🗑</button>
    </div>
    <div class="seq-body" id="seq-body-<?= $sid ?>">
      <?php $seqtype = $seq['type'] ?? 'etape'; ?>
      <div class="etape-row">
        <label for="seqtype_<?= $sid ?>">Type de séance (détermine l'unique bouton d'action de cette séquence)</label>
        <select id="seqtype_<?= $sid ?>" onchange="setType(<?= $sid ?>, this.value)">
          <option value="etape" <?= $seqtype === 'etape' ? 'selected' : '' ?>>📄 Cours en distanciel (bouton "Faire l'étape")</option>
          <option value="classe_virtuelle" <?= $seqtype === 'classe_virtuelle' ? 'selected' : '' ?>>🔴 Classe virtuelle (lien de connexion)</option>
        </select>
      </div>
      <div class="etape-row" id="etape-sub-<?= $sid ?>" style="<?= $seqtype === 'classe_virtuelle' ? 'display:none' : '' ?>">
        <label for="etape_<?= $sid ?>">Étape liée</label>
        <select id="etape_<?= $sid ?>" onchange="setEtape(<?= $sid ?>, this.value)">
          <option value="">Aucune</option>
          <?php foreach ($etapes_picker as $et):
            $val = $et['seqid'] . ':' . $et['etapeid'];
            $isSel = (int)($seq['etape_seqid'] ?? 0) === $et['seqid'] && (int)($seq['etape_etapeid'] ?? 0) === $et['etapeid'];
          ?>
            <option value="<?= $val ?>" <?= $isSel ? 'selected' : '' ?>>
              <?= htmlspecialchars($et['label'], ENT_QUOTES) ?><?= $et['publiee'] ? '' : ' (brouillon)' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php $cvAuto = !empty($seq['cv_auto']); ?>
      <div class="etape-row" id="cv-sub-<?= $sid ?>" style="<?= $seqtype === 'etape' ? 'display:none' : '' ?>">
        <label for="cvurl_<?= $sid ?>">Classe virtuelle</label>
        <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:10px;cursor:pointer">
          <input type="checkbox" id="cvauto_<?= $sid ?>" onchange="toggleCvAuto(<?= $sid ?>, this.checked)" <?= $cvAuto ? 'checked' : '' ?>>
          Connexion automatique (recommandé : l'élève arrive directement dans Glowbl, sans se reconnecter)
        </label>
        <div id="cv-external-<?= $sid ?>" style="<?= $cvAuto ? 'display:none' : '' ?>">
          <div style="display:flex;gap:6px">
            <input type="url" id="cvurl_<?= $sid ?>" value="<?= htmlspecialchars($seq['cv_url'] ?? '', ENT_QUOTES) ?>" placeholder="https://l3m.glowbl.com/…" style="flex:1;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font-size:12.5px;font-family:inherit">
            <button type="button" class="btn mini ghost" onclick="useGeneralGlowbl(<?= $sid ?>)">Lien Glowbl de la plateforme</button>
            <button type="button" class="btn mini ghost" onclick="saveCvUrl(<?= $sid ?>)">✓ Enregistrer</button>
          </div>
          <p class="sub" style="margin:6px 0 0">La plateforme utilise déjà une salle Glowbl générale (<?= htmlspecialchars($glowbl_default, ENT_QUOTES) ?>). Cliquez sur « Lien Glowbl de la plateforme » pour la réutiliser directement, ou collez le lien d'une salle dédiée si cette séquence en a une.</p>
        </div>
      </div>
      <?php if (!$current): ?>
        <p class="sub" style="margin:0 0 4px" id="seq-empty-<?= $sid ?>">Aucun module pour l'instant.</p>
      <?php endif; ?>
      <div id="seq-chips-<?= $sid ?>">
      <?php foreach ($current as $ref):
        $mod = l3m_progseq_resolve_module($ref);
        if (!$mod) continue;
      ?>
        <span class="mod-chip" data-ref="<?= htmlspecialchars($ref, ENT_QUOTES) ?>">
          <span class="tag"><?= $mod['type'] === 'unite' ? 'Unité' : 'Sous-matière' ?></span>
          <?= htmlspecialchars($mod['nom'], ENT_QUOTES) ?>
          <button type="button" onclick="removeModule(<?= $sid ?>, '<?= htmlspecialchars($ref, ENT_QUOTES) ?>', this)">✕</button>
        </span>
      <?php endforeach; ?>
      </div>
      <div class="add-row">
        <select id="add_mod_<?= $sid ?>" onchange="document.getElementById('confirm_mod_<?= $sid ?>').disabled = !this.value;">
          <option value="">Choisir un module à ajouter</option>
          <?php foreach ($picker as $mid => $group): ?>
            <optgroup label="<?= htmlspecialchars($group['matiere_nom'], ENT_QUOTES) ?>">
              <?php foreach ($group['items'] as $item): if (isset($currentSet[$item['ref']])) continue; ?>
                <option value="<?= htmlspecialchars($item['ref'], ENT_QUOTES) ?>">
                  <?= $item['type'] === 'unite' ? '· ' : '▸ ' ?><?= htmlspecialchars($item['nom'], ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <button type="button" id="confirm_mod_<?= $sid ?>" class="btn mini primary" disabled onclick="addModule(<?= $sid ?>)">✓ Confirmer</button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (!$sequences): ?>
    <p class="sub">Aucune séquence pour l'instant. Ajoutez-en une ci-dessus.</p>
  <?php endif; ?>

  <?php if ($sequences): ?>
  <div class="savebar">
    <button type="submit" class="btn">💾 Enregistrer les titres</button>
  </div>
  <?php endif; ?>
  </form>
</div>
<script>
var SESSKEY = <?= json_encode($sesskey) ?>;
var PAGEURL = <?= json_encode($CFG->wwwroot . '/sequences-config.php') ?>;
var GLOWBL_DEFAULT = <?= json_encode($glowbl_default) ?>;

function post(action, data) {
  var fd = new FormData();
  fd.append('sesskey', SESSKEY);
  fd.append('action', action);
  Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
  return fetch(PAGEURL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r){
      // Une reponse non-JSON (session expiree, erreur serveur...) faisait
      // echouer r.json() silencieusement (aucune alerte, le clic semblait
      // "ne rien faire") — on la detecte ici pour toujours donner un retour
      // visible a l'utilisateur (question du 25/09/2026).
      return r.text().then(function(txt) {
        try { return JSON.parse(txt); }
        catch (e) { throw new Error('Réponse inattendue du serveur (session peut-être expirée).'); }
      });
    })
    .catch(function(e) {
      return { ok: false, msg: e.message + '. Rechargez la page et réessayez.' };
    });
}

function addSequence() {
  var el = document.getElementById('new_seq_nom');
  var nom = el.value.trim();
  if (!nom) { el.focus(); return; }
  post('add_sequence', { nom: nom }).then(function(r){
    if (r.ok) location.reload(); else alert(r.msg || 'Erreur');
  });
}
function deleteSequence(seqid, btn) {
  if (!confirm('Supprimer cette séquence ? Cette action est définitive.')) return;
  post('delete_sequence', { seqid: seqid }).then(function(r){
    if (r.ok) location.reload(); else alert(r.msg || 'Erreur');
  });
}
function addModule(seqid) {
  var sel = document.getElementById('add_mod_' + seqid);
  var ref = sel.value;
  if (!ref) { sel.focus(); return; }
  post('add_module', { seqid: seqid, ref: ref }).then(function(r){
    if (r.ok) location.reload(); else alert(r.msg || 'Erreur');
  });
}
function removeModule(seqid, ref, btn) {
  post('remove_module', { seqid: seqid, ref: ref }).then(function(r){
    if (r.ok) location.reload(); else alert(r.msg || 'Erreur');
  });
}
function setEtape(seqid, val) {
  var parts = val ? val.split(':') : ['0', '0'];
  post('set_etape', { seqid: seqid, etape_seqid: parts[0], etape_etapeid: parts[1] }).then(function(r){
    if (!r.ok) alert(r.msg || 'Erreur');
  });
}
function setType(seqid, type) {
  document.getElementById('etape-sub-' + seqid).style.display = type === 'etape' ? '' : 'none';
  document.getElementById('cv-sub-' + seqid).style.display = type === 'classe_virtuelle' ? '' : 'none';
  post('set_type', { seqid: seqid, type: type }).then(function(r){
    if (!r.ok) alert(r.msg || 'Erreur');
  });
}
function toggleCvAuto(seqid, checked) {
  document.getElementById('cv-external-' + seqid).style.display = checked ? 'none' : '';
  post('set_cv_auto', { seqid: seqid, auto: checked ? 1 : 0 }).then(function(r){
    if (!r.ok) alert(r.msg || 'Erreur');
  });
}
function useGeneralGlowbl(seqid) {
  document.getElementById('cvurl_' + seqid).value = GLOWBL_DEFAULT;
  saveCvUrl(seqid);
}
function saveCvUrl(seqid) {
  var url = document.getElementById('cvurl_' + seqid).value.trim();
  post('set_cv_url', { seqid: seqid, url: url }).then(function(r){
    if (!r.ok) alert(r.msg || 'Erreur');
  });
}
</script>
</body>
</html>

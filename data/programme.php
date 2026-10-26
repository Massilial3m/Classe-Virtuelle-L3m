<?php
// Vue "Programme" — bibliotheque de consultation Matiere > Sous-matiere >
// Unite (voir l3m_referentiel.php), PAS un parcours navigable : elle montre
// simplement, pour chaque unite du referentiel officiel, si son support
// pre-redige est deverrouille pour l'utilisateur courant (etape terminee +
// fichier depose par l'equipe pedagogique) — voir doc_expli.pdf, partie
// "Structure de la formation et distinction entre programme et sequences".
// A ne pas confondre avec "Mes modules" (11 cours Moodle, inchange) ni avec
// "Sequences" (sequence-select.php, le parcours navigable lui-meme).
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/l3m_branding.php');
require_once(__DIR__ . '/l3m_referentiel.php');
require_once(__DIR__ . '/lia_engine.php'); // l3m_send_notification() — alerte eleves au deverrouillage force
require_login();

// Restructuration Sequences/Programme generalisee a tous les comptes le
// 17/09/2026 (etait reservee aux testeurs beta Latifa pendant le chantier —
// voir sequence-select.php pour l'historique).

$student_roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
$is_student = $student_roleid && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $student_roleid]);
$teacher_shortnames = ['editingteacher', 'teacher', 'manager'];
list($insql, $inparams) = $DB->get_in_or_equal($teacher_shortnames);
$is_formateur = $DB->record_exists_sql(
    "SELECT 1 FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
      WHERE ra.userid = ? AND r.shortname $insql",
    array_merge([$USER->id], $inparams)
);
// Creation/edition (referentiel, verrouillage manuel des supports) reservee
// au Responsable pedagogique — un formateur simple garde Programme en
// lecture seule (voir l3m_is_ped_lead() dans l3m_branding.php).
$is_ped_lead = l3m_is_ped_lead($USER->id) || is_siteadmin();

// Journal d'activite (voir l3m_log_activity() dans l3m_branding.php) —
// alimente la feuille d'emargement LBA (emargement.php), seulement pour un
// eleve reel consultant la page (pas un formateur) et pas sur les appels
// AJAX de cette meme page (deja comptes a l'ouverture).
if ($is_student && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    l3m_log_activity($USER->id, 'Programme');
}

$brand = l3m_get_branding_for_user($USER->id);
$backurl = $is_student ? '/espace-eleve.php' : '/espace-formateur.php';
$sesskey = sesskey();
// Mode integre : la page est affichee dans un iframe directement sur "Mes
// modules" (espace-eleve.php/formateur.php), a la place de l'ancienne carte
// d'entree "Programme" — on masque alors la barre de nav propre a cette
// page (logo, "Éditer le programme", "Mon espace") qui ferait doublon avec
// celle de la page hote.
$embed = optional_param('embed', 0, PARAM_BOOL);
// Deep-link depuis "Sequences" (espace-eleve.php/formateur.php) — id du
// noeud de l'arbre sur lequel atterrir directement (voir 'target' dans
// l3m_progseq_resolve_module()). Format libre ("m-3", "sm-1-2", "1.1.1"),
// jamais utilise en requete SQL, uniquement compare cote client a des ids
// deja connus de l'arbre — PARAM_TEXT suffit.
$open = optional_param('open', '', PARAM_TEXT);
$highlight = optional_param('highlight', '', PARAM_TEXT);

// ── Verrouillage/deverrouillage manuel par le Responsable pedagogique (voir
//    l3m_referentiel.php, l3m_seq_doc_set_override) — appel AJAX depuis le
//    <select> a cote de chaque unite. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_ALPHANUMEXT) === 'set_override') {
    require_sesskey();
    header('Content-Type: application/json');
    $dbid  = required_param('dbid', PARAM_ALPHANUMEXT);
    // Le Responsable pedagogique peut tout deverrouiller ; un formateur
    // simple seulement s'il a un acces "edition" (voir Pedagogie) sur LA
    // Sequence qui porte ce dbid precisement (question du 07/10/2026).
    if (!$is_ped_lead && !l3m_seq_formateur_can_edit_dbid($USER->id, $dbid)) {
        echo json_encode(['ok' => false, 'msg' => 'Réservé au responsable pédagogique ou à un formateur autorisé sur cette séquence.']); exit;
    }
    $value = required_param('value', PARAM_ALPHA);
    if (!in_array($value, ['auto', 'open', 'locked'], true)) { echo json_encode(['ok' => false, 'msg' => 'Valeur invalide.']); exit; }
    $old_override_sov = l3m_seq_doc_override($dbid);
    l3m_seq_doc_set_override($dbid, $value === 'auto' ? null : $value);

    // Alerte les eleves concernes uniquement au passage en "Forcer
    // disponible" (pas a chaque reglage) — plus de cours Moodle a surveiller
    // pour savoir qu'un support vient de s'ouvrir, question du 07/10/2026.
    if ($value === 'open' && $old_override_sov !== 'open') {
        $student_roleid_sov = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        if ($student_roleid_sov) {
            $label_sov = l3m_resolve_dbid_label($dbid);
            $students_sov = $DB->get_records_sql(
                "SELECT DISTINCT u.id
                 FROM {user} u
                 JOIN {role_assignments} ra ON ra.userid = u.id
                 JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                 WHERE ra.roleid = :rid AND u.deleted = 0",
                ['rid' => $student_roleid_sov]
            );
            foreach ($students_sov as $s_sov) {
                if ($DB->record_exists('local_l3madmin_seq_state', ['userid' => (int)$s_sov->id, 'sequenceid' => $dbid, 'completed' => 1])) continue;
                l3m_send_notification(
                    $USER->id, (int)$s_sov->id, 'Support débloqué : ' . $label_sov,
                    'Le support "' . $label_sov . '" a été débloqué dans Programme.',
                    $CFG->wwwroot . '/espace-eleve.php#cours', 'Voir Programme'
                );
            }
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── Upload direct d'un PDF pour une Unite sans Etape (voir l3m_unite_doc_*()
//    dans l3m_referentiel.php) — reserve au Responsable pedagogique, question
//    du 07/10/2026 "ajouter le pdf ici dans Programme". ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_ALPHANUMEXT) === 'upload_unite_doc') {
    require_sesskey();
    header('Content-Type: application/json');
    if (!$is_ped_lead) { echo json_encode(['ok' => false, 'msg' => 'Réservé au responsable pédagogique.']); exit; }
    $code = required_param('code', PARAM_TEXT);
    if (!l3m_referentiel_unite($code)) { echo json_encode(['ok' => false, 'msg' => 'Unité inconnue.']); exit; }
    if (empty($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => 'Fichier manquant ou erreur d\'upload.']); exit;
    }
    $tmp = $_FILES['document']['tmp_name'];
    if ($_FILES['document']['size'] > 20 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'msg' => 'Fichier trop volumineux (20 Mo max).']); exit;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmp);
    finfo_close($finfo);
    if ($mime !== 'application/pdf') {
        echo json_encode(['ok' => false, 'msg' => 'Seuls les fichiers PDF sont acceptés.']); exit;
    }
    $dir = l3m_unite_doc_dir();
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    if (!move_uploaded_file($tmp, l3m_unite_doc_path($code))) {
        echo json_encode(['ok' => false, 'msg' => 'Échec de l\'enregistrement du fichier.']); exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── Telechargement/visualisation d'un document direct par Unite (voir
//    ci-dessus) : meme verification de deverrouillage que pour un document
//    d'Etape, le formateur/Responsable pedagogique garde toujours acces pour
//    controle. "mode=view" (bouton "Visualiser" du Responsable pedagogique)
//    ouvre le PDF dans l'onglet au lieu de forcer un telechargement —
//    question du 09/10/2026 "3 boutons : visualisation, edition, suppression". ──
if (optional_param('action', '', PARAM_ALPHANUMEXT) === 'download_unite_doc') {
    $code = required_param('code', PARAM_TEXT);
    $mode = optional_param('mode', 'attachment', PARAM_ALPHA);
    $path = l3m_unite_doc_path($code);
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "Ce support n'est pas encore disponible.";
        exit;
    }
    if (!$is_formateur) {
        $st = l3m_prog_statut_unite($code, $USER->id, false);
        if ($st['etat'] !== 'deverrouille') {
            http_response_code(403);
            header('Content-Type: text/plain');
            echo ($st['override'] ?? null) === 'locked'
                ? "Ce support a ete verrouille par le formateur."
                : "Ce support n'est pas encore disponible.";
            exit;
        }
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($mode === 'view' ? 'inline' : 'attachment') . '; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── Suppression d'un document direct par Unite — reserve au Responsable
//    pedagogique, question du 09/10/2026. Remet l'Unite a l'etat "Pas encore
//    programmee" et efface l'eventuel override (sinon une nouvelle
//    Unite re-uploadee plus tard heriterait a tort d'un ancien
//    deverrouillage force). ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_ALPHANUMEXT) === 'delete_unite_doc') {
    require_sesskey();
    header('Content-Type: application/json');
    if (!$is_ped_lead) { echo json_encode(['ok' => false, 'msg' => 'Réservé au responsable pédagogique.']); exit; }
    $code = required_param('code', PARAM_TEXT);
    $path = l3m_unite_doc_path($code);
    if (is_file($path)) { unlink($path); }
    l3m_seq_doc_set_override(l3m_unite_doc_dbid($code), null);
    echo json_encode(['ok' => true]);
    exit;
}

$matieres = l3m_referentiel_matieres();

// l3m_prog_statut_unite() vit desormais dans l3m_referentiel.php (reutilisee
// par Parcours cote espace-eleve.php pour la progression par sous-matiere).

// ── Arbre Matiere > Sous-matiere > Unite pour la navigation "drill-down"
//    cote client (maquette du 21/09/2026 — visuel uniquement, la logique de
//    statut ci-dessus ne change pas). ──
$tree = [];
foreach ($matieres as $mid => $matiere) {
    $sm_nodes = [];
    $mat_dever = 0; $mat_total = 0;
    foreach ($matiere['sous_matieres'] as $sid => $sm) {
        $u_nodes = [];
        $sm_dever = 0; $sm_total = 0;
        foreach ($sm['unites'] as $code => $nom) {
            $st = l3m_prog_statut_unite($code, $USER->id, $is_formateur);
            $sm_total++; $mat_total++;
            if ($st['etat'] === 'deverrouille') { $sm_dever++; $mat_dever++; }
            $u_nodes[] = [
                'id' => $code, 'code' => $code, 'title' => $nom,
                'etat' => $st['etat'], 'label' => $st['label'],
                'seqid' => $st['seqid'] ?? null, 'etapeid' => $st['etapeid'] ?? null,
                'dbid' => $st['dbid'] ?? null, 'override' => $st['override'] ?? null,
                'direct' => $st['direct'] ?? false,
            ];
        }
        $sm_nodes[] = [
            'id' => "sm-$mid-$sid", 'code' => "$mid.$sid", 'title' => $sm['nom'],
            'progress' => $sm_total ? round($sm_dever / $sm_total, 2) : 0,
            'meta' => "$sm_dever/$sm_total unités disponibles",
            'children' => $u_nodes,
        ];
    }
    $tree[] = [
        'id' => "m-$mid", 'code' => "MATIÈRE $mid", 'title' => $matiere['nom'],
        'progress' => $mat_total ? round($mat_dever / $mat_total, 2) : 0,
        'meta' => "$mat_dever/$mat_total unités disponibles",
        'children' => $sm_nodes,
    ];
}

?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Programme</title>
<style>
  :root{
    --bg: #F5F6F8;
    --panel: #FFFFFF;
    --ink: #1D2430;
    --ink-soft: #5B6472;
    --line: #E4E7EC;
    --teal: #1B8C8C;
    --teal-dark: #146E6E;
    --amber: #C98A2C;
    --amber-bg: #FBF1DF;
    --locked: #9AA2AD;
    --locked-bg: #EFF1F4;
    --radius-card: 14px;
    --radius-pill: 999px;
  }
  *{box-sizing:border-box;}
  body{
    margin:0;
    background:var(--bg);
    font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, "Inter", sans-serif;
    color:var(--ink);
  }
  .sitebar{ background:var(--panel); border-bottom:1px solid var(--line); padding:12px 20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; row-gap:8px; }
  .sitebar .tag { background:#5B4FCF; color:#fff; font-size:10px; font-weight:800; padding:3px 9px; border-radius:99px; }
  .sitebar a { font-size:12px; color:var(--ink-soft); text-decoration:none; }
  .sitebar a:hover { color:var(--teal-dark); }

  .page{padding:32px 24px 80px;}
  .wrap{max-width:1180px;margin:0 auto;}
  /* Mode integre (iframe sur "Mes modules", voir embed=1) : bg transparent
     (la carte hote fournit deja son propre fond) et marges reduites, la
     page hote ayant deja son propre padding lateral. */
  body.is-embed{background:transparent;}
  body.is-embed .page{padding:0;}

  .topbar{display:flex;align-items:center;gap:12px;margin-bottom:6px;}
  .back-btn{
    display:inline-flex;align-items:center;gap:6px;
    border:1px solid var(--line);background:var(--panel);color:var(--ink);
    padding:8px 14px;border-radius:var(--radius-pill);font-size:14px;font-weight:600;
    cursor:pointer;transition:border-color .15s ease, transform .1s ease;
    font-family:inherit;
  }
  .back-btn:hover{border-color:#C7CCD4;}
  .back-btn:active{transform:scale(0.97);}
  .back-btn.hidden{display:none;}

  .crumbs{font-size:14px;color:var(--ink-soft);display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
  .crumbs .sep{color:#C7CCD4;}
  .crumbs .current{color:var(--ink);font-weight:600;}
  .crumbs button{background:none;border:none;padding:0;font:inherit;color:var(--ink-soft);cursor:pointer;text-decoration:none;}
  .crumbs button:hover{color:var(--teal-dark);text-decoration:underline;}

  h1{font-size:28px;letter-spacing:-0.01em;margin:18px 0 4px;}
  .subtitle{color:var(--ink-soft);font-size:15px;margin:0 0 6px;line-height:1.5;}

  .grid{display:grid;grid-template-columns:repeat(auto-fill, minmax(250px, 1fr));gap:16px;}

  .card{
    background:var(--panel);border:1px solid var(--line);border-radius:var(--radius-card);
    padding:20px;display:flex;flex-direction:column;min-height:168px;cursor:pointer;
    transition:border-color .15s ease, box-shadow .15s ease, transform .12s ease;
    position:relative;
  }
  .card.clickable:hover{border-color:#BFC9D6;box-shadow:0 4px 14px rgba(20,30,45,0.06);transform:translateY(-1px);}
  .card-highlight{border-color:var(--teal);box-shadow:0 0 0 3px rgba(27,140,140,0.18);animation:cardPulse 1.6s ease 1;}
  @keyframes cardPulse{0%{box-shadow:0 0 0 0 rgba(27,140,140,0.35);}70%{box-shadow:0 0 0 10px rgba(27,140,140,0);}100%{box-shadow:0 0 0 3px rgba(27,140,140,0.18);}}
  .card.disabled{cursor:default;}
  .card.disabled:hover{transform:none;box-shadow:none;border-color:var(--line);}

  .card-id{font-size:11.5px;font-weight:700;letter-spacing:0.04em;color:var(--teal-dark);margin-bottom:8px;}
  .card-id.locked{color:var(--locked);}
  .card-title{font-size:16px;font-weight:700;line-height:1.35;margin:0 0 14px;flex-grow:1;}

  .bar-track{height:5px;background:var(--line);border-radius:99px;overflow:hidden;margin-bottom:8px;}
  .bar-fill{height:100%;background:linear-gradient(90deg, #2AA6A6, var(--teal-dark));border-radius:99px;}
  .card-meta{font-size:13px;color:var(--ink-soft);margin-bottom:14px;}
  .card-action{margin-top:auto;}

  .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--radius-pill);font-size:13.5px;font-weight:700;border:none;cursor:pointer;text-decoration:none;font-family:inherit;}
  .btn-primary{background:var(--teal);color:#fff;}
  .btn-primary:hover{background:var(--teal-dark);}
  .btn-download{background:var(--teal-dark);color:#fff;}
  .pill-locked{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:var(--radius-pill);font-size:13px;font-weight:600;background:var(--locked-bg);color:var(--locked);}
  .pill-available{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:var(--radius-pill);font-size:13px;font-weight:600;background:var(--amber-bg);color:var(--amber);margin-right:8px;}
  .row-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px;}
  .ovr-select{border:1px solid var(--line);border-radius:8px;padding:6px 8px;font-size:11.5px;font-family:inherit;background:#fff;color:var(--ink);}
  /* Actions en icones seules, groupees en haut a droite de la carte (meme
     esprit que les icones crayon/poubelle des cartes "Intervenants" cote
     admin-lba.php) — remplace les boutons texte empiles qui alourdissaient
     la carte, question du 12/10/2026. */
  .card-icon-actions{position:absolute;top:16px;right:16px;display:flex;gap:4px;align-items:center;}
  .card.has-icon-actions .card-id,.card.has-icon-actions .card-title{padding-right:84px;}
  .card-icon-btn{border:none;background:none;color:var(--ink-soft);cursor:pointer;font-size:15px;padding:5px;line-height:1;border-radius:7px;transition:background .12s,color .12s;}
  .card-icon-btn:hover{background:var(--bg);color:var(--ink);}
  .card-icon-btn.danger:hover{background:#FBE7E7;color:#D64545;}
  .card-icon-btn:disabled{opacity:.5;cursor:default;}

  .chevron{position:absolute;top:20px;right:18px;color:#C7CCD4;font-size:18px;}

  .empty-note{grid-column:1/-1;color:var(--ink-soft);font-size:14px;padding:24px;text-align:center;border:1px dashed var(--line);border-radius:var(--radius-card);}

  .fade-in{animation: fadeIn .18s ease;}
  @keyframes fadeIn{from{opacity:0; transform:translateY(4px);} to{opacity:1; transform:translateY(0);}}

  @media (max-width:480px){ h1{font-size:22px;} .grid{grid-template-columns:1fr;} .page{padding:20px 14px 60px;} }
</style>
</head>
<body<?= $embed ? ' class="is-embed"' : '' ?>>
<?php if (!$embed): ?>
<div class="sitebar">
  <img src="<?= htmlspecialchars($brand['logo'], ENT_QUOTES) ?>" style="height:26px">
  <span class="tag">🧪 TEST</span>
  <span style="flex:1"></span>
  <?php if ($is_ped_lead): ?>
  <a href="<?= $CFG->wwwroot ?>/referentiel-editor.php">✏️ Éditer le programme</a>
  <?php endif; ?>
  <a href="<?= $CFG->wwwroot . $backurl ?>">&larr; Mon espace</a>
</div>
<?php endif; ?>
<div class="page">
<div class="wrap">

  <div class="topbar">
    <button class="back-btn hidden" id="backBtn" onclick="goBack()">‹ Retour</button>
  </div>
  <div class="crumbs" id="crumbs"></div>

  <h1 id="pageTitle">Programme</h1>
  <p class="subtitle" id="pageSubtitle">Bibliothèque des supports de cours, classée par matière. Un support se débloque une fois l'étape correspondante terminée dans « Séquences ».</p>

  <div class="grid fade-in" id="grid"></div>

</div>
</div>

<script>
var SESSKEY   = <?= json_encode($sesskey) ?>;
var PAGEURL   = <?= json_encode($CFG->wwwroot . '/programme.php') ?>;
var PLAYERURL = <?= json_encode($CFG->wwwroot . '/sequence-player.php') ?>;
var IS_PED_LEAD = <?= json_encode($is_ped_lead) ?>;
var OPEN_TARGET = <?= json_encode($open) ?>;
var HIGHLIGHT_ID = <?= json_encode($highlight) ?>;

var DATA = { id: "root", title: "Programme", children: <?= json_encode($tree, JSON_UNESCAPED_UNICODE) ?> };

/* ---------- Navigation state ---------- */
let path = [DATA]; // stack of nodes from root to current

// Deep-link depuis "Sequences" (voir OPEN_TARGET/HIGHLIGHT_ID ci-dessus) —
// cherche le noeud d'id donne dans l'arbre et retourne le chemin racine ->
// noeud, ou null si l'id n'existe plus (referentiel modifie entre-temps).
function findPath(id, node, trail) {
  trail = trail.concat([node]);
  if (node.id === id) return trail;
  var kids = node.children || [];
  for (var i = 0; i < kids.length; i++) {
    var found = findPath(id, kids[i], trail);
    if (found) return found;
  }
  return null;
}
if (OPEN_TARGET) {
  var foundPath = findPath(OPEN_TARGET, DATA, []);
  if (foundPath) path = foundPath;
}

function currentNode(){ return path[path.length-1]; }

function esc(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

function render(){
  const node = currentNode();
  const grid = document.getElementById('grid');
  const backBtn = document.getElementById('backBtn');
  const crumbsEl = document.getElementById('crumbs');
  const title = document.getElementById('pageTitle');
  const subtitle = document.getElementById('pageSubtitle');

  backBtn.classList.toggle('hidden', path.length <= 1);

  // A la racine, le fil d'Ariane ne contiendrait qu'un seul mot "Programme"
  // — deja redondant avec le h1 juste en dessous (et, en mode integre, avec
  // le titre de section de la page hote). On ne l'affiche qu'a partir du
  // 1er niveau de profondeur, la ou il devient realement utile.
  crumbsEl.style.display = path.length <= 1 ? 'none' : 'flex';
  crumbsEl.innerHTML = '';
  path.forEach((n, i) => {
    if(i > 0){
      const sep = document.createElement('span');
      sep.className = 'sep';
      sep.textContent = '›';
      crumbsEl.appendChild(sep);
    }
    if(i === path.length - 1){
      const span = document.createElement('span');
      span.className = 'current';
      span.textContent = n.title;
      crumbsEl.appendChild(span);
    } else {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = n.title;
      btn.onclick = () => { path = path.slice(0, i+1); render(); };
      crumbsEl.appendChild(btn);
    }
  });

  if(node.id === 'root'){
    title.textContent = 'Programme';
    subtitle.style.display = 'block';
  } else {
    title.textContent = node.title;
    subtitle.style.display = 'none';
  }

  grid.innerHTML = '';
  grid.classList.remove('fade-in');
  void grid.offsetWidth;
  grid.classList.add('fade-in');

  const children = node.children || [];

  if(children.length === 0){
    const empty = document.createElement('div');
    empty.className = 'empty-note';
    empty.textContent = node.id === 'root'
      ? "Référentiel pas encore rempli."
      : "Pas encore programmé — le contenu sera ajouté par l'équipe pédagogique.";
    grid.appendChild(empty);
    return;
  }

  children.forEach(child => { grid.appendChild(buildCard(child)); });

  // Mise en valeur du module cible depuis "Sequences" (voir HIGHLIGHT_ID
  // ci-dessus) — une seule fois, sur la carte reellement affichee ici.
  if (HIGHLIGHT_ID) {
    const target = grid.querySelector('[data-node-id="' + CSS.escape(HIGHLIGHT_ID) + '"]');
    if (target) {
      target.classList.add('card-highlight');
      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }
}

function buildCard(node){
  const isLeaf = node.children === undefined; // pas de "children" => c'est une unite
  const card = document.createElement('div');
  card.className = 'card' + (isLeaf ? ' disabled' : ' clickable');
  card.dataset.nodeId = node.id;

  const idLine = document.createElement('div');
  idLine.className = 'card-id';
  idLine.textContent = node.code || '';
  card.appendChild(idLine);

  const titleEl = document.createElement('div');
  titleEl.className = 'card-title';
  titleEl.textContent = node.title;
  card.appendChild(titleEl);

  if(!isLeaf){
    if((node.children || []).length === 0){
      idLine.classList.add('locked');
      const meta = document.createElement('div');
      meta.className = 'card-meta';
      meta.textContent = 'Rien de programmé pour l\'instant';
      card.appendChild(meta);
      const pill = document.createElement('span');
      pill.className = 'pill-locked';
      pill.innerHTML = '🔒 Verrouillé';
      card.appendChild(pill);
      return card;
    }

    const track = document.createElement('div');
    track.className = 'bar-track';
    const fill = document.createElement('div');
    fill.className = 'bar-fill';
    fill.style.width = Math.round((node.progress||0)*100) + '%';
    track.appendChild(fill);
    card.appendChild(track);

    const meta = document.createElement('div');
    meta.className = 'card-meta';
    meta.textContent = node.meta || '';
    card.appendChild(meta);

    const chevron = document.createElement('div');
    chevron.className = 'chevron';
    chevron.textContent = '›';
    card.appendChild(chevron);
    card.onclick = () => { path.push(node); render(); };

  } else {
    const row = document.createElement('div');
    row.className = 'row-actions card-action';

    const unlocked = node.etat === 'deverrouille';
    const pill = document.createElement('span');
    pill.className = unlocked ? 'pill-available' : 'pill-locked';
    pill.innerHTML = (unlocked ? '🔓 ' : '🔒 ') + esc(node.label || '');
    row.appendChild(pill);

    if(IS_PED_LEAD && node.dbid){
      const sel = document.createElement('select');
      sel.className = 'ovr-select';
      sel.dataset.dbid = node.dbid;
      sel.onchange = () => setOverride(sel);
      sel.onclick = (e) => e.stopPropagation();
      [['auto','Auto (selon progression)'],['open','🔓 Forcer disponible'],['locked','🔒 Forcer verrouillé']].forEach(([v,l]) => {
        const opt = document.createElement('option');
        opt.value = v; opt.textContent = l;
        opt.selected = (node.override || 'auto') === v;
        sel.appendChild(opt);
      });
      row.appendChild(sel);
    }

    card.appendChild(row);

    // Actions en icones seules, groupees en haut a droite de la carte (voir
    // ".card-icon-actions" plus haut) — remplace les boutons texte "Faire
    // l'étape" (deplace vers Sequences, question du 01/10/2026), puis
    // "Visualiser"/"Télécharger"/"Remplacer"/"Supprimer" en texte empile
    // (question du 12/10/2026, "icones comme la carte Intervenants"). Une
    // Unite "direct" (sans Etape QCM, voir l3m_unite_doc_*()) peut en plus
    // etre deposee/remplacee/supprimee par le Responsable pedagogique.
    const icons = document.createElement('div');
    icons.className = 'card-icon-actions';
    icons.onclick = (e) => e.stopPropagation();

    function iconBtn(tag, icon, label) {
      const el = document.createElement(tag);
      if (tag === 'button') el.type = 'button';
      el.className = 'card-icon-btn';
      el.innerHTML = icon;
      el.title = label;
      el.setAttribute('aria-label', label);
      return el;
    }

    const baseUrl = node.direct
      ? (PAGEURL + '?action=download_unite_doc&code=' + encodeURIComponent(node.code))
      : (PLAYERURL + '?action=download_document&seq=' + node.seqid + '&etape=' + node.etapeid);
    const canPedLeadPreview = IS_PED_LEAD && node.direct && node.dbid;

    // "Visualiser" : un seul bouton, qu'il vienne du deverrouillage normal
    // (tout le monde) ou de l'acces de controle du Responsable pedagogique
    // quand c'est encore verrouille pour les eleves (jamais les deux a la
    // fois, evite le doublon vu sur la carte "Continuum éducatif").
    if (unlocked || canPedLeadPreview) {
      const view = iconBtn('a', '👁', 'Visualiser');
      view.href = baseUrl + '&mode=view';
      view.target = '_blank';
      icons.appendChild(view);
    }
    // "Télécharger" : meme logique que "Visualiser" ci-dessus — le
    // Responsable pedagogique doit pouvoir le recuperer pour controle meme
    // si le support est encore verrouille pour les eleves (question du
    // 12/10/2026, "je ne vois pas l'icone télécharger").
    if (unlocked || canPedLeadPreview) {
      const dl = iconBtn('a', '⬇', 'Télécharger');
      dl.href = baseUrl;
      dl.target = '_blank';
      icons.appendChild(dl);
    }

    if (IS_PED_LEAD && node.direct) {
      const fileInp = document.createElement('input');
      fileInp.type = 'file';
      fileInp.accept = 'application/pdf';
      fileInp.style.display = 'none';
      icons.appendChild(fileInp);

      const triggerBtn = iconBtn('button', node.dbid ? '✏️' : '📤', node.dbid ? 'Remplacer le PDF' : 'Déposer le PDF');
      triggerBtn.onclick = () => fileInp.click();
      fileInp.onchange = () => uploadUniteDoc(node.code, fileInp, triggerBtn);
      icons.appendChild(triggerBtn);

      if (node.dbid) {
        const delBtn = iconBtn('button', '🗑', 'Supprimer le PDF');
        delBtn.classList.add('danger');
        delBtn.onclick = () => deleteUniteDoc(node.code, delBtn);
        icons.appendChild(delBtn);
      }
    }

    if (icons.children.length) {
      card.classList.add('has-icon-actions');
      card.appendChild(icons);
    }
  }

  return card;
}

function goBack(){
  if(path.length > 1){ path.pop(); render(); }
}

function setOverride(sel) {
  var dbid = sel.dataset.dbid, value = sel.value;
  var fd = new FormData();
  fd.append('sesskey', SESSKEY);
  fd.append('action', 'set_override');
  fd.append('dbid', dbid);
  fd.append('value', value);
  sel.disabled = true;
  fetch(PAGEURL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r){ return r.json(); })
    .then(function(r){ if (r.ok) location.reload(); else { alert(r.msg || 'Erreur'); sel.disabled = false; } });
}

function uploadUniteDoc(code, fileInp, btn) {
  if (!fileInp.files || !fileInp.files[0]) { alert('Choisissez un fichier PDF d\'abord.'); return; }
  var fd = new FormData();
  fd.append('sesskey', SESSKEY);
  fd.append('action', 'upload_unite_doc');
  fd.append('code', code);
  fd.append('document', fileInp.files[0]);
  btn.disabled = true;
  var label = btn.textContent;
  btn.textContent = 'Envoi…';
  fetch(PAGEURL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r){ return r.json(); })
    .then(function(r){
      if (r.ok) { location.reload(); }
      else { alert(r.msg || 'Erreur'); btn.disabled = false; btn.textContent = label; }
    })
    .catch(function(){ alert('Erreur réseau.'); btn.disabled = false; btn.textContent = label; });
}

function deleteUniteDoc(code, btn) {
  if (!confirm('Supprimer ce document ? Cette action est irréversible.')) return;
  var fd = new FormData();
  fd.append('sesskey', SESSKEY);
  fd.append('action', 'delete_unite_doc');
  fd.append('code', code);
  btn.disabled = true;
  fetch(PAGEURL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r){ return r.json(); })
    .then(function(r){
      if (r.ok) { location.reload(); }
      else { alert(r.msg || 'Erreur'); btn.disabled = false; }
    })
    .catch(function(){ alert('Erreur réseau.'); btn.disabled = false; });
}

render();

// Mode integre (iframe, voir embed=1) : prevenir la page hote (espace-
// eleve/formateur.php) de la hauteur reelle du contenu a chaque changement
// (navigation drill-down, retour, etc.), pour que l'iframe s'ajuste sans
// double scrollbar — un MutationObserver plutot qu'un hook dans render()
// couvre aussi le "return" anticipe du cas "aucun contenu".
if (window.parent !== window) {
  var notifyHeight = function() {
    window.parent.postMessage({ type: 'l3m-prog-height', height: document.body.scrollHeight }, '*');
  };
  new MutationObserver(notifyHeight).observe(document.getElementById('grid'), { childList: true, subtree: true });
  window.addEventListener('resize', notifyHeight);
  notifyHeight();
}
</script>
</body>
</html>

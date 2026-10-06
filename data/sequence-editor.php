<?php
// Editeur de contenu du lecteur de sequence (sequence-player.php).
// Le referent pedagogique remplit le texte reel (questions, references,
// QCM, seuils) dans la structure DEJA VALIDEE du storyboard — pas de
// creation/suppression d'ecran ici, uniquement le contenu.
// Reserve aux formateurs (pas aux eleves).
// Adressage par Sequence + Etape (voir l3m_referentiel.php) depuis le
// 15/09/2026 — remplace l'ancien adressage par Module plateforme. La liste
// de champs edites n'est plus figee sur les noms de phases d'un seul
// contenu de test : elle parcourt CONTENT.blocs generiquement, quel que
// soit leur nombre/forme (voir la boucle "PARCOURS DES BLOCS" plus bas).
// Deploy : SFTP -> /tmp/ -> docker cp moodle_app:/var/data/www/public/
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/l3m_branding.php');
require_once(__DIR__ . '/l3m_referentiel.php');
require_login();

// Generalise a tous les comptes le 17/09/2026 (etait reserve aux testeurs
// beta Latifa pendant le chantier — voir sequence-select.php pour
// l'historique).

// Edition du contenu (texte, support PDF) reservee au Responsable
// pedagogique — un formateur simple consulte via sequence-select.php
// (Résultats) mais n'a pas ce lien "Éditer" ni acces direct ici.
if (!l3m_is_ped_lead($USER->id) && !is_siteadmin()) {
    redirect(new moodle_url($CFG->wwwroot . '/sequence-select.php'));
}

$seqid   = optional_param('seq', 2, PARAM_INT);
$etapeid = optional_param('etape', 1, PARAM_INT);
$etape   = l3m_seq_etape($seqid, $etapeid);
if (!$etape) {
    http_response_code(404);
    die('Étape introuvable.');
}
$contentfile = __DIR__ . '/' . $etape['contentfile'];
if (!file_exists($contentfile)) {
    http_response_code(404);
    die('Fichier de contenu introuvable pour cette étape.');
}

// ── Ecrit une valeur dans un tableau imbrique a partir d'un chemin en points
//    ("blocs.1.ecrans.0.texte") ──
function l3m_seq_set_path(&$data, $path, $value) {
    $parts = explode('.', $path);
    $ref = &$data;
    foreach ($parts as $i => $part) {
        $key = is_numeric($part) ? (int)$part : $part;
        if ($i === count($parts) - 1) {
            $ref[$key] = $value;
        } else {
            if (!isset($ref[$key]) || !is_array($ref[$key])) $ref[$key] = [];
            $ref = &$ref[$key];
        }
    }
}
function l3m_seq_get_path($data, $path, $default = '') {
    $parts = explode('.', $path);
    $ref = $data;
    foreach ($parts as $part) {
        $key = is_numeric($part) ? (int)$part : $part;
        if (!isset($ref[$key])) return $default;
        $ref = $ref[$key];
    }
    return $ref;
}

// ── Constructeur de blocs (partie B) : ajout/suppression/reordonnancement
//    de blocs et de leurs sous-elements (ecrans, questions, options,
//    champs), + publication. Chemin AJAX distinct de l'enregistrement du
//    texte plus bas (celui-ci n'envoie jamais de "action"). ──
$structural_actions = ['add_bloc', 'delete_bloc', 'move_bloc', 'add_ecran', 'delete_ecran',
    'add_question', 'delete_question', 'add_option', 'delete_option', 'add_champ', 'delete_champ',
    'publier_etape', 'depublier_etape'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(optional_param('action', '', PARAM_ALPHANUMEXT), $structural_actions, true)) {
    require_sesskey();
    header('Content-Type: application/json');
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
    $content = json_decode(file_get_contents($contentfile), true);
    $blocs = &$content['blocs'];

    if ($action === 'add_bloc') {
        $type = required_param('blocktype', PARAM_ALPHANUMEXT);
        $skeleton = l3m_seq_new_bloc($type, count($blocs) + 1);
        if (!$skeleton) { echo json_encode(['ok' => false, 'msg' => 'Type de bloc inconnu.']); exit; }
        $blocs[] = $skeleton;

    } elseif ($action === 'delete_bloc') {
        $bi = required_param('bi', PARAM_INT);
        if (!isset($blocs[$bi])) { echo json_encode(['ok' => false, 'msg' => 'Bloc introuvable.']); exit; }
        array_splice($blocs, $bi, 1);

    } elseif ($action === 'move_bloc') {
        $bi  = required_param('bi', PARAM_INT);
        $dir = required_param('dir', PARAM_ALPHA); // 'up' | 'down'
        $target = $dir === 'up' ? $bi - 1 : $bi + 1;
        if (!isset($blocs[$bi]) || !isset($blocs[$target])) { echo json_encode(['ok' => false, 'msg' => 'Déplacement impossible.']); exit; }
        $tmp = $blocs[$bi]; $blocs[$bi] = $blocs[$target]; $blocs[$target] = $tmp;

    } elseif ($action === 'add_ecran') {
        $bi = required_param('bi', PARAM_INT);
        $sstype = required_param('sstype', PARAM_ALPHANUMEXT);
        if (!isset($blocs[$bi]) || $blocs[$bi]['type'] !== 'expo') { echo json_encode(['ok' => false, 'msg' => 'Bloc introuvable.']); exit; }
        $skeleton = l3m_seq_new_ecran($sstype, $blocs[$bi]['cle'], count($blocs[$bi]['ecrans'] ?? []) + 1);
        if (!$skeleton) { echo json_encode(['ok' => false, 'msg' => 'Type d\'écran inconnu.']); exit; }
        $blocs[$bi]['ecrans'][] = $skeleton;

    } elseif ($action === 'delete_ecran') {
        $bi = required_param('bi', PARAM_INT);
        $ei = required_param('ei', PARAM_INT);
        if (!isset($blocs[$bi]['ecrans'][$ei])) { echo json_encode(['ok' => false, 'msg' => 'Écran introuvable.']); exit; }
        array_splice($blocs[$bi]['ecrans'], $ei, 1);

    } elseif ($action === 'add_question') {
        $bi = required_param('bi', PARAM_INT);
        if (!isset($blocs[$bi]) || $blocs[$bi]['type'] !== 'qcm_bloc') { echo json_encode(['ok' => false, 'msg' => 'Bloc introuvable.']); exit; }
        $blocs[$bi]['questions'][] = l3m_seq_new_qcm_question($blocs[$bi]['cle'], count($blocs[$bi]['questions'] ?? []) + 1);

    } elseif ($action === 'delete_question') {
        $bi = required_param('bi', PARAM_INT);
        $qi = required_param('qi', PARAM_INT);
        if (!isset($blocs[$bi]['questions'][$qi])) { echo json_encode(['ok' => false, 'msg' => 'Question introuvable.']); exit; }
        array_splice($blocs[$bi]['questions'], $qi, 1);

    } elseif ($action === 'add_option') {
        $bi = required_param('bi', PARAM_INT);
        $qi = required_param('qi', PARAM_INT);
        if (!isset($blocs[$bi]['questions'][$qi])) { echo json_encode(['ok' => false, 'msg' => 'Question introuvable.']); exit; }
        $blocs[$bi]['questions'][$qi]['options'][] = l3m_seq_new_qcm_option();

    } elseif ($action === 'delete_option') {
        $bi = required_param('bi', PARAM_INT);
        $qi = required_param('qi', PARAM_INT);
        $oi = required_param('oi', PARAM_INT);
        if (!isset($blocs[$bi]['questions'][$qi]['options'][$oi])) { echo json_encode(['ok' => false, 'msg' => 'Option introuvable.']); exit; }
        if (count($blocs[$bi]['questions'][$qi]['options']) <= 2) { echo json_encode(['ok' => false, 'msg' => 'Au moins 2 options requises.']); exit; }
        array_splice($blocs[$bi]['questions'][$qi]['options'], $oi, 1);

    } elseif ($action === 'add_champ') {
        $bi = required_param('bi', PARAM_INT);
        if (!isset($blocs[$bi]) || $blocs[$bi]['type'] !== 'collecte_brute') { echo json_encode(['ok' => false, 'msg' => 'Bloc introuvable.']); exit; }
        $blocs[$bi]['champs'][] = l3m_seq_new_champ(count($blocs[$bi]['champs'] ?? []) + 1);

    } elseif ($action === 'delete_champ') {
        $bi = required_param('bi', PARAM_INT);
        $ci = required_param('ci', PARAM_INT);
        if (!isset($blocs[$bi]['champs'][$ci])) { echo json_encode(['ok' => false, 'msg' => 'Champ introuvable.']); exit; }
        array_splice($blocs[$bi]['champs'], $ci, 1);

    } elseif ($action === 'publier_etape' || $action === 'depublier_etape') {
        if ($action === 'publier_etape') {
            $erreurs = l3m_seq_etape_erreurs_publication($content);
            if ($erreurs) { echo json_encode(['ok' => false, 'msg' => implode(' / ', $erreurs)]); exit; }
        }
        $sequences = l3m_seq_sequences();
        if (!isset($sequences[$seqid]['etapes'][$etapeid])) { echo json_encode(['ok' => false, 'msg' => 'Étape introuvable.']); exit; }
        $sequences[$seqid]['etapes'][$etapeid]['statut'] = $action === 'publier_etape' ? 'publiee' : 'brouillon';
        l3m_seq_save_sequences($sequences);
        file_put_contents($contentfile, json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(['ok' => true]);
        exit;
    }

    unset($blocs);
    file_put_contents($contentfile, json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(['ok' => true]);
    exit;
}

$saved = false;
$doc_uploaded = false;
$doc_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $content = json_decode(file_get_contents($contentfile), true);
    $fields = $_POST['field'] ?? [];
    foreach ($fields as $path => $value) {
        // "true"/"false" venant des checkboxes QCM (voir JS plus bas).
        if ($value === '__true__') $value = true;
        elseif ($value === '__false__') $value = false;
        else $value = clean_param($value, PARAM_RAW_TRIMMED);
        l3m_seq_set_path($content, $path, $value);
    }
    file_put_contents($contentfile, json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $saved = true;

    // ── Support pre-redige (PDF) — depose ici par le formateur/l'equipe
    //    pedagogique, remplace le fichier deverrouille cote eleve une fois
    //    l'etape terminee (voir sequence-player.php, action
    //    download_document, et l3m_referentiel.php). ──
    if (!empty($_FILES['document_file']['name'])) {
        $err = $_FILES['document_file']['error'];
        if ($err !== UPLOAD_ERR_NO_FILE) {
            $docpath = l3m_seq_etape_document_path($seqid, $etapeid);
            if (!$docpath) {
                $doc_error = "Cette étape n'a pas d'emplacement de support configuré.";
            } elseif ($err !== UPLOAD_ERR_OK) {
                $doc_error = "Erreur lors de l'envoi du fichier.";
            } else {
                $tmp = $_FILES['document_file']['tmp_name'];
                $ext = strtolower(pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION));
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                $maxbytes = 20 * 1024 * 1024;
                if ($ext !== 'pdf' || $mime !== 'application/pdf') {
                    $doc_error = "Le fichier doit être un PDF.";
                } elseif ($_FILES['document_file']['size'] > $maxbytes) {
                    $doc_error = "Le fichier dépasse la taille maximale (20 Mo).";
                } else {
                    $dir = dirname($docpath);
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    if (move_uploaded_file($tmp, $docpath)) {
                        $doc_uploaded = true;
                    } else {
                        $doc_error = "Échec de l'enregistrement du fichier sur le serveur.";
                    }
                }
            }
        }
    }

    // ── Image d'ecran (upload direct, comme le support PDF mais par ecran
    //    plutot que par etape) — champs "ecran_image[bi][ei]" dans le
    //    formulaire, un par ecran d'un bloc expo. Stockee dans seq_images/,
    //    servie directement (pas d'action de telechargement gardee : une
    //    image de contenu est visible des qu'on voit l'ecran, contrairement
    //    au support PDF qui se debloque a la fin). ──
    if (!empty($_FILES['ecran_image']['name'])) {
        $content_img = json_decode(file_get_contents($contentfile), true);
        $changed = false;
        foreach ($_FILES['ecran_image']['name'] as $bi_up => $per_bi) {
            foreach ($per_bi as $ei_up => $filename) {
                if ($filename === '') continue;
                $err = $_FILES['ecran_image']['error'][$bi_up][$ei_up];
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if (!isset($content_img['blocs'][$bi_up]['ecrans'][$ei_up])) continue;
                $ecran_label = $content_img['blocs'][$bi_up]['ecrans'][$ei_up]['id'] ?? '?';
                if ($err !== UPLOAD_ERR_OK) { $doc_error = "Erreur lors de l'envoi de l'image (écran $ecran_label)."; continue; }
                $tmp = $_FILES['ecran_image']['tmp_name'][$bi_up][$ei_up];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
                $maxbytes = 5 * 1024 * 1024;
                if (!isset($allowed[$ext]) || $mime !== $allowed[$ext]) {
                    $doc_error = "Image invalide (jpg/png/webp attendu) pour l'écran $ecran_label.";
                    continue;
                }
                if ($_FILES['ecran_image']['size'][$bi_up][$ei_up] > $maxbytes) {
                    $doc_error = "Image trop lourde (5 Mo max) pour l'écran $ecran_label.";
                    continue;
                }
                $ecranid_safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $ecran_label);
                $relpath = 'seq_images/seq' . $seqid . '_etape' . $etapeid . '_' . $ecranid_safe . '.' . $ext;
                $fullpath = __DIR__ . '/' . $relpath;
                $dir = dirname($fullpath);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (move_uploaded_file($tmp, $fullpath)) {
                    $content_img['blocs'][$bi_up]['ecrans'][$ei_up]['image'] = $relpath;
                    $changed = true;
                    $doc_uploaded = true;
                } else {
                    $doc_error = "Échec de l'enregistrement de l'image (écran $ecran_label).";
                }
            }
        }
        if ($changed) {
            file_put_contents($contentfile, json_encode($content_img, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }
}

$content = json_decode(file_get_contents($contentfile), true);
$brand = l3m_get_branding_for_user($USER->id);
$sesskey = sesskey();

function f($path, $content, $label, $multiline = true) {
    $val = l3m_seq_get_path($content, $path, '');
    $id = 'f_' . md5($path);
    $out = '<div class="fld"><label for="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES) . '</label>';
    if ($multiline) {
        $out .= '<textarea id="' . $id . '" name="field[' . htmlspecialchars($path, ENT_QUOTES) . ']">' . htmlspecialchars($val, ENT_QUOTES) . '</textarea>';
    } else {
        $out .= '<input type="text" id="' . $id . '" name="field[' . htmlspecialchars($path, ENT_QUOTES) . ']" value="' . htmlspecialchars($val, ENT_QUOTES) . '">';
    }
    $out .= '</div>';
    return $out;
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Éditeur : <?= htmlspecialchars($content['titre'], ENT_QUOTES) ?></title>
<style>
:root { --teal:<?= $brand['teal'] ?>; --teal-d:<?= $brand['teal_d'] ?>; --bg:<?= $brand['bg'] ?>; --card:<?= $brand['card'] ?>; --ink:<?= $brand['ink'] ?>; --muted:<?= $brand['muted'] ?>; --line:<?= $brand['line'] ?>; --red:#D64545; }
* { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",system-ui,sans-serif; background:var(--bg); color:var(--ink); }
.topbar { position:sticky; top:0; z-index:20; background:var(--card); border-bottom:1px solid var(--line); padding:12px 20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; row-gap:8px; }
.topbar b { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:220px; }
.topbar b { font-size:14px; }
.topbar .tag { background:#5B4FCF; color:#fff; font-size:10px; font-weight:800; padding:3px 9px; border-radius:99px; }
.wrap { max-width:820px; margin:0 auto; padding:24px 20px 100px; }
.phase { background:var(--card); border:1px solid var(--line); border-radius:14px; margin-bottom:16px; overflow:hidden; }
.phase-head { padding:14px 18px; background:var(--teal); color:#fff; font-weight:800; font-size:13px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; }
.phase-body { padding:16px 18px; display:none; }
.phase.open .phase-body { display:block; }
.screen { border:1px solid var(--line); border-radius:10px; padding:14px; margin-bottom:12px; background:#FAFAF7; }
.screen-id { font-size:10px; font-weight:800; color:var(--teal-d); text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px; }
.fld { margin-bottom:10px; }
.fld label { display:block; font-size:11.5px; font-weight:700; color:var(--muted); margin-bottom:3px; }
.fld textarea, .fld input[type=text] { width:100%; border:1px solid var(--line); border-radius:8px; padding:8px 10px; font-family:inherit; font-size:13px; }
.fld textarea { min-height:44px; resize:vertical; }
.opt-row { display:flex; gap:8px; align-items:flex-start; margin-bottom:6px; padding:8px; background:#fff; border:1px solid var(--line); border-radius:8px; }
.opt-row input[type=checkbox] { margin-top:9px; }
.opt-row .fld { flex:1; margin-bottom:0; }
.btn { display:inline-flex; align-items:center; gap:6px; border:none; border-radius:99px; padding:10px 22px; font-size:13px; font-weight:700; cursor:pointer; background:var(--teal); color:#fff; }
.btn.mini { padding:5px 12px; font-size:11.5px; }
.btn.ghost { background:var(--card); color:var(--teal-d); border:1.5px solid var(--teal); }
.btn.danger { background:#fff; color:var(--red); border:1.5px solid var(--red); }
.savebar { position:fixed; bottom:0; left:0; right:0; background:var(--card); border-top:1px solid var(--line); padding:12px 20px; display:flex; justify-content:center; gap:12px; box-shadow:0 -2px 10px rgba(0,0,0,.06); }
.toast { position:fixed; top:60px; left:50%; transform:translateX(-50%); background:#2E9488; color:#fff; padding:10px 20px; border-radius:99px; font-size:13px; font-weight:700; z-index:99; }
.bloc-ctrls { display:flex; gap:4px; }
.bloc-ctrls button { background:rgba(255,255,255,.18); border:none; color:#fff; border-radius:6px; width:26px; height:26px; cursor:pointer; font-size:12px; }
.bloc-ctrls button:hover { background:rgba(255,255,255,.32); }
.addbox { border:1px dashed var(--line); border-radius:10px; padding:10px; margin-top:8px; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.addbox select { border:1px solid var(--line); border-radius:8px; padding:6px 8px; font-size:12px; font-family:inherit; }
.pub-badge { font-size:10px; font-weight:800; padding:3px 9px; border-radius:99px; }
.pub-badge.brouillon { background:#FFF3DC; color:#8A6100; }
.pub-badge.publiee { background:#E3F2EF; color:#1C5F58; }
</style>
</head>
<body>
<?php $publiee = l3m_seq_etape_est_publiee($etape); ?>
<div class="topbar">
  <img src="<?= htmlspecialchars($brand['logo'], ENT_QUOTES) ?>" style="height:28px">
  <b>Éditeur : <?= htmlspecialchars($etape['titre'], ENT_QUOTES) ?></b>
  <span class="tag">🧪 TEST</span>
  <span class="pub-badge <?= $publiee ? 'publiee' : 'brouillon' ?>"><?= $publiee ? '✓ Publiée' : '🚧 Brouillon' ?></span>
  <span style="flex:1"></span>
  <?php if ($publiee): ?>
    <button type="button" class="btn mini ghost" style="margin-right:10px" onclick="depublierEtape()">Repasser en brouillon</button>
  <?php else: ?>
    <button type="button" class="btn mini" style="margin-right:10px" onclick="publierEtape()">✅ Publier cette étape</button>
  <?php endif; ?>
  <a href="<?= $CFG->wwwroot ?>/sequence-results.php?seq=<?= $seqid ?>&etape=<?= $etapeid ?>" style="font-size:12px;color:var(--muted);text-decoration:none;margin-right:10px">Résultats</a>
  <a href="<?= $CFG->wwwroot ?>/sequence-select.php" style="font-size:12px;color:var(--muted);text-decoration:none;margin-right:10px">&larr; Autres séquences</a>
  <a href="<?= $CFG->wwwroot ?>/espace-formateur.php" style="font-size:12px;color:var(--muted);text-decoration:none">Mon espace</a>
</div>
<?php if ($saved): ?><div class="toast">✓ Contenu enregistré<?= $doc_uploaded ? ' — support mis à jour' : '' ?></div><?php endif; ?>
<?php if ($doc_error): ?><div class="toast" style="background:#D64545"><?= htmlspecialchars($doc_error, ENT_QUOTES) ?></div><?php endif; ?>
<form method="post" class="wrap" enctype="multipart/form-data">
<input type="hidden" name="sesskey" value="<?= $sesskey ?>">
<input type="hidden" name="seq" value="<?= $seqid ?>">
<input type="hidden" name="etape" value="<?= $etapeid ?>">

<p style="color:var(--muted);font-size:13px">Remplis ici le texte réel de la séquence : les questions, les réponses de référence, les options QCM et les seuils. La structure (nombre d'écrans, blocs) reste celle déjà validée dans le storyboard.</p>

<?php
// Controles de structure (deplacer/supprimer un bloc) affiches dans chaque
// phase-head — AJAX, voir JS plus bas. Le onclick est mis en stopPropagation
// pour ne pas re-basculer l'accordeon phase-head en meme temps.
function blocCtrls($bi, $total) {
    $up = $bi > 0 ? '<button type="button" onclick="event.stopPropagation();moveBloc(' . $bi . ',\'up\')" title="Monter">↑</button>' : '';
    $down = $bi < $total - 1 ? '<button type="button" onclick="event.stopPropagation();moveBloc(' . $bi . ',\'down\')" title="Descendre">↓</button>' : '';
    $del = '<button type="button" onclick="event.stopPropagation();deleteBloc(' . $bi . ')" title="Supprimer ce bloc">🗑</button>';
    return '<div class="bloc-ctrls">' . $up . $down . $del . '</div>';
}

// ── PARCOURS DES BLOCS (generique, remplace les phases fixes en dur) ──
$nb_blocs = count($content['blocs']);
foreach ($content['blocs'] as $bi => $bloc) {
    $bpath = "blocs.$bi";
    $ctrls = blocCtrls($bi, $nb_blocs);

    if ($bloc['type'] === 'ouverture') {
        echo '<div class="phase open"><div class="phase-head" onclick="this.parentElement.classList.toggle(\'open\')"><span>Ouverture ▾</span>' . $ctrls . '</div><div class="phase-body">';
        echo f("$bpath.titre", $content, 'Titre');
        echo f("$bpath.accroche", $content, 'Accroche');
        foreach (($bloc['objectifs'] ?? []) as $oi => $o) {
            echo f("$bpath.objectifs.$oi", $content, 'Objectif ' . ($oi + 1));
        }
        echo '</div></div>';

    } elseif ($bloc['type'] === 'expo') {
        $label = ($bloc['titre_partie'] ?? $bloc['cle']) . (($bloc['statut'] ?? '') === 'note' ? ' (notée)' : ' (non notée)');
        echo '<div class="phase"><div class="phase-head" onclick="this.parentElement.classList.toggle(\'open\')"><span>' . htmlspecialchars($label, ENT_QUOTES) . ' ▾</span>' . $ctrls . '</div><div class="phase-body">';
        echo f("$bpath.titre_partie", $content, 'Titre de la partie', false);
        foreach ($bloc['ecrans'] as $ei => $ecran) {
            $base = "$bpath.ecrans.$ei";
            echo '<div class="screen"><div class="screen-id" style="display:flex;justify-content:space-between;align-items:center">Écran ' . htmlspecialchars($ecran['id'], ENT_QUOTES) . ' : ' . htmlspecialchars($ecran['type'], ENT_QUOTES) . '<button type="button" class="btn mini danger" onclick="deleteEcran(' . $bi . ',' . $ei . ')">🗑 Supprimer</button></div>';
            if (isset($ecran['texte'])) echo f("$base.texte", $content, 'Texte affiché');
            if (isset($ecran['revele_texte'])) echo f("$base.revele_texte", $content, 'Texte de rappel (réponse à l\'écran précédent révélé ici)');
            if (!empty($ecran['note_production'])) {
                echo '<p style="font-size:12px;color:var(--muted);background:#FFF3DC;border-radius:8px;padding:8px 10px;margin:0 0 10px">🎬 ' . htmlspecialchars($ecran['note_production'], ENT_QUOTES) . '</p>';
            }
            echo f("$base.video", $content, 'Lien vidéo (YouTube/Vimeo, optionnel)', false);
            // ── Image d'ecran (upload direct, optionnelle) ──
            $img_rel = $ecran['image'] ?? '';
            $img_path = $img_rel ? __DIR__ . '/' . $img_rel : null;
            echo '<div class="fld"><label>Image (optionnelle)</label>';
            if ($img_path && file_exists($img_path)) {
                echo '<div style="margin-bottom:6px"><img src="' . htmlspecialchars($CFG->wwwroot . '/' . $img_rel, ENT_QUOTES) . '" style="max-width:160px;max-height:100px;border-radius:8px;border:1px solid var(--line);display:block"></div>';
            }
            echo '<input type="file" name="ecran_image[' . $bi . '][' . $ei . ']" accept="image/jpeg,image/png,image/webp"></div>';
            if (isset($ecran['question'])) {
                echo f("$base.question", $content, 'Question', false);
                if (isset($ecran['reponse_reference'])) echo f("$base.reponse_reference", $content, 'Réponse de référence');
            } elseif (isset($ecran['reponse_reference'])) {
                // Ecrans "texte_seul" qui completent la reponse d'une question
                // posee sur l'ecran precedent.
                echo f("$base.reponse_reference", $content, 'Réponse de référence (complète la question de l\'écran précédent)');
            }
            if (isset($ecran['questions']) && is_array($ecran['questions'])) {
                foreach ($ecran['questions'] as $qi => $q) {
                    echo f("$base.questions.$qi.question", $content, 'Question ' . ($qi + 1), false);
                    if (isset($q['reponse_reference'])) echo f("$base.questions.$qi.reponse_reference", $content, 'Réponse de référence ' . ($qi + 1));
                }
            }
            echo '</div>';
        }
        echo '<div class="addbox">';
        echo '<select id="new_ecran_type_' . $bi . '">';
        foreach (['amorce' => 'Amorce (question ouverte)', 'texte_seul' => 'Texte seul / révélation', 'synthese' => 'Synthèse', 'texte_question' => 'Texte + question', 'texte_question_double' => 'Texte + 2 questions', 'amorces_multiples' => 'Plusieurs amorces'] as $v => $l) {
            echo '<option value="' . $v . '">' . htmlspecialchars($l, ENT_QUOTES) . '</option>';
        }
        echo '</select><button type="button" class="btn mini ghost" onclick="addEcran(' . $bi . ')">+ Ajouter un écran</button></div>';
        echo '</div></div>';

    } elseif ($bloc['type'] === 'transition') {
        echo '<div class="phase"><div class="phase-head" onclick="this.parentElement.classList.toggle(\'open\')"><span>Transition ▾</span>' . $ctrls . '</div><div class="phase-body">';
        echo f("$bpath.texte", $content, "Texte d'annonce");
        echo f("$bpath.bouton", $content, 'Texte du bouton', false);
        echo '</div></div>';

    } elseif ($bloc['type'] === 'qcm_bloc') {
        echo '<div class="phase"><div class="phase-head" onclick="this.parentElement.classList.toggle(\'open\')"><span>QCM noté ▾</span>' . $ctrls . '</div><div class="phase-body">';
        echo f("$bpath.seuil_bon_sur_total", $content, 'Seuil requis (ex. 4/5)', false);
        echo f("$bpath.seuil_pct", $content, 'Seuil requis (%, nombre)', false);
        foreach ($bloc['questions'] as $qi => $q) {
            $base = "$bpath.questions.$qi";
            echo '<div class="screen"><div class="screen-id" style="display:flex;justify-content:space-between;align-items:center">Question ' . htmlspecialchars($q['id'], ENT_QUOTES) . '<button type="button" class="btn mini danger" onclick="deleteQuestion(' . $bi . ',' . $qi . ')">🗑 Supprimer</button></div>';
            echo f("$base.question", $content, 'Énoncé', false);
            foreach ($q['options'] as $oi => $opt) {
                $obase = "$base.options.$oi";
                $cid = 'c_' . md5("$obase.correcte");
                echo '<div class="opt-row">';
                echo '<input type="checkbox" id="' . $cid . '" ' . (!empty($opt['correcte']) ? 'checked' : '') . ' onchange="document.getElementById(\'h_' . $cid . '\').value = this.checked ? \'__true__\' : \'__false__\'">';
                echo '<input type="hidden" id="h_' . $cid . '" name="field[' . htmlspecialchars("$obase.correcte", ENT_QUOTES) . ']" value="' . (!empty($opt['correcte']) ? '__true__' : '__false__') . '">';
                echo '<div class="fld" style="flex:2">' . f("$obase.texte", $content, 'Option ' . ($oi + 1) . ' (coche = bonne réponse)', false) . '</div>';
                echo '<div class="fld" style="flex:2">' . f("$obase.explication", $content, 'Explication affichée après réponse', false) . '</div>';
                echo '<button type="button" class="btn mini danger" onclick="deleteOption(' . $bi . ',' . $qi . ',' . $oi . ')" style="align-self:center">🗑</button>';
                echo '</div>';
            }
            echo '<button type="button" class="btn mini ghost" onclick="addOption(' . $bi . ',' . $qi . ')">+ Option</button>';
            echo '</div>';
        }
        echo '<div class="addbox"><button type="button" class="btn mini ghost" onclick="addQuestion(' . $bi . ')">+ Ajouter une question</button></div>';
        echo '</div></div>';

    } elseif ($bloc['type'] === 'collecte_brute') {
        echo '<div class="phase"><div class="phase-head" onclick="this.parentElement.classList.toggle(\'open\')"><span>Écran de collecte (aucune correction) ▾</span>' . $ctrls . '</div><div class="phase-body">';
        foreach (($bloc['champs'] ?? []) as $ci => $c) {
            echo '<div style="display:flex;gap:8px;align-items:flex-end">' . f("$bpath.champs.$ci.consigne", $content, 'Consigne ' . ($ci + 1)) . '<button type="button" class="btn mini danger" onclick="deleteChamp(' . $bi . ',' . $ci . ')" style="margin-bottom:10px">🗑</button></div>';
        }
        echo '<div class="addbox"><button type="button" class="btn mini ghost" onclick="addChamp(' . $bi . ')">+ Ajouter un champ</button></div>';
        echo '</div></div>';

    } elseif ($bloc['type'] === 'cloture') {
        echo '<div class="phase"><div class="phase-head" onclick="this.parentElement.classList.toggle(\'open\')"><span>Clôture ▾</span>' . $ctrls . '</div><div class="phase-body">';
        echo f("$bpath.texte", $content, "Texte de fin d'étape");
        echo f("$bpath.document.nom_affiche", $content, 'Nom affiché du support', false);

        // ── Support pre-redige : meme fichier pour tous les eleves, se
        //    debloque quand l'etape est terminee — voir l3m_referentiel.php
        //    et sequence-player.php (action download_document). ──
        $docpath = l3m_seq_etape_document_path($seqid, $etapeid);
        $docdispo = l3m_seq_etape_document_disponible($seqid, $etapeid);
        echo '<div class="screen"><div class="screen-id">Support à télécharger (PDF)</div>';
        if ($docpath === null) {
            echo '<p style="font-size:12.5px;color:var(--muted);margin:0 0 8px">Cette étape n\'a pas d\'emplacement de support configuré.</p>';
        } else {
            if ($docdispo) {
                $taille = round(filesize($docpath) / 1024);
                $maj = userdate(filemtime($docpath), '%d/%m/%Y %H:%M');
                echo '<p style="font-size:12.5px;color:var(--muted);margin:0 0 8px">✓ Support déposé (' . $taille . ' Ko, mis à jour le ' . $maj . '). L\'envoi d\'un nouveau fichier ci-dessous le remplace.</p>';
            } else {
                echo '<p style="font-size:12.5px;color:var(--muted);margin:0 0 8px">Aucun support déposé pour l\'instant — les élèves voient "en cours de préparation" tant qu\'aucun fichier n\'est envoyé ici.</p>';
            }
            echo '<input type="file" name="document_file" accept="application/pdf">';
        }
        echo '</div>';

        echo '</div></div>';
    }
}
?>

<div class="addbox" style="margin-bottom:90px">
  <select id="new_bloc_type">
    <option value="ouverture">Ouverture</option>
    <option value="expo">Exposition (non notée)</option>
    <option value="transition">Transition</option>
    <option value="qcm_bloc">QCM noté</option>
    <option value="collecte_brute">Collecte (sans correction)</option>
    <option value="cloture">Clôture</option>
  </select>
  <button type="button" class="btn ghost" onclick="addBloc()">+ Ajouter un bloc</button>
</div>

<div class="savebar">
  <button type="submit" class="btn">💾 Enregistrer le contenu</button>
</div>
</form>
<script>
var SESSKEY  = <?= json_encode($sesskey) ?>;
var PAGEURL  = <?= json_encode($CFG->wwwroot . '/sequence-editor.php') ?>;
var SEQNUM   = <?= json_encode($seqid) ?>;
var ETAPENUM = <?= json_encode($etapeid) ?>;

function post(action, data) {
  var fd = new FormData();
  fd.append('sesskey', SESSKEY);
  fd.append('action', action);
  fd.append('seq', SEQNUM);
  fd.append('etape', ETAPENUM);
  Object.keys(data || {}).forEach(function(k){ fd.append(k, data[k]); });
  return fetch(PAGEURL + '?seq=' + SEQNUM + '&etape=' + ETAPENUM, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(r){ return r.json(); });
}
function afterPost(r) { if (r.ok) location.reload(); else alert(r.msg || 'Erreur'); }

function addBloc() { post('add_bloc', { blocktype: document.getElementById('new_bloc_type').value }).then(afterPost); }
function deleteBloc(bi) { if (confirm('Supprimer ce bloc et tout son contenu ?')) post('delete_bloc', { bi: bi }).then(afterPost); }
function moveBloc(bi, dir) { post('move_bloc', { bi: bi, dir: dir }).then(afterPost); }
function addEcran(bi) { post('add_ecran', { bi: bi, sstype: document.getElementById('new_ecran_type_' + bi).value }).then(afterPost); }
function deleteEcran(bi, ei) { if (confirm('Supprimer cet écran ?')) post('delete_ecran', { bi: bi, ei: ei }).then(afterPost); }
function addQuestion(bi) { post('add_question', { bi: bi }).then(afterPost); }
function deleteQuestion(bi, qi) { if (confirm('Supprimer cette question ?')) post('delete_question', { bi: bi, qi: qi }).then(afterPost); }
function addOption(bi, qi) { post('add_option', { bi: bi, qi: qi }).then(afterPost); }
function deleteOption(bi, qi, oi) { post('delete_option', { bi: bi, qi: qi, oi: oi }).then(afterPost); }
function addChamp(bi) { post('add_champ', { bi: bi }).then(afterPost); }
function deleteChamp(bi, ci) { if (confirm('Supprimer ce champ ?')) post('delete_champ', { bi: bi, ci: ci }).then(afterPost); }
function publierEtape() { post('publier_etape', {}).then(afterPost); }
function depublierEtape() { if (confirm('Repasser cette étape en brouillon ? Elle redeviendra invisible des élèves.')) post('depublier_etape', {}).then(afterPost); }
</script>
</body>
</html>

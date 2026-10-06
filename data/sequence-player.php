<?php
// Lecteur de sequence pedagogique generique (exposition non notee + QCM note
// avec seuil/remediation + ecran de collecte brute + support pre-redige).
// Generalise a tous les comptes le 17/09/2026 (etait reserve aux testeurs
// beta Latifa pendant la restructuration Matiere > Sous-matiere > Unite /
// sequences multi-etapes, voir l3m_referentiel.php) — seules les etapes
// PUBLIEES sont visibles des eleves (voir l3m_seq_etape_est_publiee()), donc
// pas de risque d'exposer un brouillon en cours de construction.
// Deploy : SFTP -> /tmp/ -> docker cp moodle_app:/var/data/www/public/
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/l3m_branding.php');
require_once(__DIR__ . '/l3m_referentiel.php');
require_once(__DIR__ . '/lia_engine.php'); // l3m_send_notification() — alerte formateur (mecanisme 2)
require_login();

// s() est deja fournie par Moodle core (lib/weblib.php) — pas besoin de la
// redefinir, ce qui provoquait une erreur fatale de redeclaration.

// Reprend l'appel Gemini existant (espace-eleve.php, action lia_remediate) —
// meme cle d'API (GEMINI_API_KEY), meme modele, meme logique de retry.
function l3m_call_gemini($prompt) {
    $apikey = getenv('GEMINI_API_KEY');
    if (!$apikey) {
        return ['ok' => false, 'msg' => "Cle API Gemini non configuree sur le serveur"];
    }
    $url  = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-lite-latest:generateContent?key=' . $apikey;
    $body = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['responseMimeType' => 'application/json'],
    ]);
    // Timeout/retry volontairement plus courts que la version "remediation"
    // d'espace-eleve.php (30s x3) : cet echo est un plus cosmetique sur un
    // ecran non note, pas la peine de faire attendre l'eleve ni de risquer
    // de depasser le temps d'execution PHP — 2 essais rapides, puis on
    // continue sans echo (la reponse elle-meme est toujours enregistree).
    $max_tries = 2; $httpcode = 0; $response = false; $curlerr = '';
    for ($try = 1; $try <= $max_tries; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            // Le conteneur n'a pas de connectivite IPv6 — voir le meme
            // correctif dans espace-eleve.php::l3m_call_gemini().
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerr  = curl_error($ch);
        curl_close($ch);
        if ($response !== false && !in_array($httpcode, [429, 503], true)) break;
        if ($try < $max_tries) sleep(1);
    }
    if ($response === false) return ['ok' => false, 'msg' => 'Erreur reseau vers Gemini : ' . $curlerr];
    $decoded = json_decode($response, true);
    if ($httpcode !== 200 || !$decoded) {
        if (in_array($httpcode, [429, 503], true)) {
            return ['ok' => false, 'msg' => "Lia est momentanement surchargee. Reessaie dans une minute."];
        }
        return ['ok' => false, 'msg' => 'Erreur API Gemini (HTTP ' . $httpcode . ')'];
    }
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $data = json_decode($text, true);
    if (!$data) return ['ok' => false, 'msg' => 'Reponse IA illisible'];
    return ['ok' => true, 'data' => $data];
}

// Adressage par Sequence + Etape (voir l3m_referentiel.php) — remplace
// l'ancien adressage par Module plateforme ("?module=N") depuis le
// 15/09/2026.
$seqid   = optional_param('seq', 2, PARAM_INT);
$etapeid = optional_param('etape', 1, PARAM_INT);
$etape   = l3m_seq_etape($seqid, $etapeid);
if (!$etape) {
    redirect(new moodle_url('/sequence-select.php'), "Cette étape n'existe pas (encore).", null, \core\output\notification::NOTIFY_INFO);
}
// Une etape en brouillon (pas encore publiee, voir l3m_seq_etape_est_publiee())
// reste jouable par les formateurs pour la previsualiser avant publication,
// mais totalement bloquee pour les eleves — meme par acces direct a l'URL.
if (!l3m_seq_etape_est_publiee($etape)) {
    $teacher_shortnames_gate = ['editingteacher', 'teacher', 'manager'];
    list($insql_gate, $inparams_gate) = $DB->get_in_or_equal($teacher_shortnames_gate);
    $peut_previsualiser = is_siteadmin() || $DB->record_exists_sql(
        "SELECT 1 FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
          WHERE ra.userid = ? AND r.shortname $insql_gate",
        array_merge([$USER->id], $inparams_gate)
    );
    if (!$peut_previsualiser) {
        redirect(new moodle_url('/sequence-select.php'), "Cette étape n'est pas encore disponible.", null, \core\output\notification::NOTIFY_INFO);
    }
}
$sequenceid  = l3m_seq_db_id($seqid, $etapeid);
$contentfile = __DIR__ . '/' . $etape['contentfile'];
$content = file_exists($contentfile) ? json_decode(file_get_contents($contentfile), true) : null;
if (!$content) {
    http_response_code(500);
    die('Contenu de sequence introuvable.');
}

// ── Etat de progression (table local_l3madmin_seq_state) ──
function l3m_seq_get_state($userid, $sequenceid) {
    global $DB;
    $row = $DB->get_record('local_l3madmin_seq_state', ['userid' => $userid, 'sequenceid' => $sequenceid]);
    if (!$row) {
        $row = (object)['userid' => $userid, 'sequenceid' => $sequenceid, 'currentscreen' => '0.1',
            'phase3passed' => 0, 'phase6passed' => 0, 'completed' => 0, 'timemodified' => time()];
        $row->id = $DB->insert_record('local_l3madmin_seq_state', $row);
    }
    return $row;
}
function l3m_seq_save_state($userid, $sequenceid, $fields) {
    global $DB;
    // set_field() par champ (SQL "UPDATE ... SET champ=?"), plutot qu'un
    // fetch-puis-update_record() sur la ligne entiere : deux appels
    // apiPost({action:'save_state',...}) peuvent partir presque en meme
    // temps (changement d'ecran + fin de sequence, voir goTo() + le
    // handler du type "cloture") sans s'attendre l'un l'autre — avec un
    // update_record() classique, celui qui ecrit en second re-ecrivait sa
    // COPIE (lue avant l'autre appel) de toute la ligne, effacant au
    // passage le champ que l'autre appel venait de poser (ex. "completed"
    // repasse a 0). En ne touchant que la colonne concernee, chaque appel
    // est independant et ne peut plus ecraser le travail de l'autre.
    $row = l3m_seq_get_state($userid, $sequenceid);
    foreach ($fields as $k => $v) {
        $DB->set_field('local_l3madmin_seq_state', $k, $v, ['id' => $row->id]);
        $row->$k = $v;
    }
    $DB->set_field('local_l3madmin_seq_state', 'timemodified', time(), ['id' => $row->id]);
    return $row;
}

// ── Reponses (table local_l3madmin_seq_answers) ──
function l3m_seq_get_answers($userid, $sequenceid) {
    global $DB;
    $rows = $DB->get_records('local_l3madmin_seq_answers', ['userid' => $userid, 'sequenceid' => $sequenceid]);
    $out = [];
    foreach ($rows as $r) {
        // Ne garder que la derniere tentative par ecran (utile pour les
        // reprises de QCM apres remediation, ou l'ecran garde le meme id).
        if (!isset($out[$r->screenid]) || $r->attempt >= $out[$r->screenid]->attempt) {
            $out[$r->screenid] = $r;
        }
    }
    return $out;
}
function l3m_seq_save_answer($userid, $sequenceid, $screenid, $kind, $answertext, $aiecho, $qcmdata, $qcmcorrect, $attempt) {
    global $DB;
    $rec = (object)[
        'userid' => $userid, 'sequenceid' => $sequenceid, 'screenid' => $screenid, 'kind' => $kind,
        'answertext' => $answertext, 'aiecho' => $aiecho, 'qcmdata' => $qcmdata,
        'qcmcorrect' => $qcmcorrect, 'attempt' => $attempt, 'timecreated' => time(),
    ];
    return $DB->insert_record('local_l3madmin_seq_answers', $rec);
}

// Retrouve une question de contenu (texte + options) par son id, dans
// n'importe quel bloc de type "qcm_bloc" du contenu — utilise pour la
// correction serveur des QCM. Generique depuis le 15/09/2026 (avant :
// cherchait uniquement dans les 2 blocs QCM fixes de l'ancien contenu de
// test "accidentalite"/"accidentologie").
function l3m_seq_find_qcm_question($content, $qid) {
    foreach (($content['blocs'] ?? []) as $bloc) {
        if (($bloc['type'] ?? '') !== 'qcm_bloc') continue;
        foreach (($bloc['questions'] ?? []) as $q) {
            if ($q['id'] === $qid) return [$bloc['cle'], $q];
        }
    }
    return [null, null];
}

// Notifie une liste ciblee de formateurs (par identifiant Moodle), plutot
// que tous les editingteacher du client — accord du 16/09/2026 : Sebastien
// (referent pedagogique reel) + le compte de test formateur de Latifa, pour
// que les alertes de test n'atterrissent plus chez tous les formateurs LBA.
// A remplacer par une vraie notion de "formateur assigne a la sequence" le
// jour ou elle existera.
function l3m_seq_alert_recipients_usernames(): array {
    return ['sebastien.delahaye', 'latifa.faida3'];
}
function l3m_seq_notify_formateurs_alert(int $studentid, string $etapetitre, int $seqid, int $etapeid) {
    global $DB, $CFG;
    $student = $DB->get_record('user', ['id' => $studentid]);
    if (!$student) return;
    $resultsurl = $CFG->wwwroot . '/sequence-results.php?seq=' . $seqid . '&etape=' . $etapeid;
    foreach (l3m_seq_alert_recipients_usernames() as $username) {
        $teacher = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
        if (!$teacher) continue; // compte pas (encore) present sur cette instance, on ignore silencieusement
        l3m_send_notification(
            $studentid, (int)$teacher->id,
            'Alerte séquence : seuil non atteint',
            fullname($student) . " n'a pas atteint le seuil requis après remédiation sur « " . $etapetitre . " ».",
            $resultsurl, 'Voir les résultats'
        );
    }
}

// ===================== ACTIONS POST (AJAX) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_ALPHANUMEXT);
    header('Content-Type: application/json');

    if ($action === 'save_ecrit') {
        $screenid = required_param('screenid', PARAM_TEXT);
        $question = required_param('question', PARAM_TEXT);
        $answer   = required_param('answer', PARAM_TEXT);
        $reponse_reference = optional_param('reponse_reference', '', PARAM_TEXT);

        $echo = '';
        $prompt = "Tu es Lia, l'assistante pedagogique d'une formation professionnelle (ECSR). "
            . "Un stagiaire vient de repondre librement a une question de positionnement (avant de voir le cours). "
            . "Question posee : " . $question . "\nReponse du stagiaire : " . ($answer !== '' ? $answer : '(vide)')
            . "\n\nReformule cette reponse en une phrase courte, a l'echo, en tutoyant le stagiaire. "
            . "NE JAMAIS dire si c'est juste ou faux, NE JAMAIS ajouter d'information non dite par le stagiaire — "
            . "reformule seulement ce qu'il a ecrit, meme si la reponse est vide ou hors sujet (dans ce cas, "
            . "reformule simplement qu'il n'a pas encore d'avis ou n'a pas repondu). "
            . "Reponds UNIQUEMENT avec un objet JSON valide de cette forme exacte : {\"echo\": \"...\"}";
        $result = l3m_call_gemini($prompt);
        if ($result['ok'] && !empty($result['data']['echo'])) {
            $echo = (string)$result['data']['echo'];
        }

        l3m_seq_save_answer($USER->id, $sequenceid, $screenid, 'ecrit', $answer, $echo, null, null, 1);
        echo json_encode(['ok' => true, 'echo' => $echo, 'reponse_reference' => $reponse_reference], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_collecte') {
        $screenid = required_param('screenid', PARAM_TEXT);
        $champid  = required_param('champid', PARAM_TEXT);
        $answer   = required_param('answer', PARAM_TEXT);
        // Aucune IA, aucune correction : stockage tel quel (voir 4.8 dans le storyboard).
        l3m_seq_save_answer($USER->id, $sequenceid, $screenid . '_' . $champid, 'collecte', $answer, null, null, null, 1);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'save_qcm') {
        $qid      = required_param('qid', PARAM_TEXT);
        $selected = json_decode(required_param('selected', PARAM_RAW), true) ?: []; // indices choisis
        $attempt  = optional_param('attempt', 1, PARAM_INT);

        list(, $q) = l3m_seq_find_qcm_question($content, $qid);
        if (!$q) { echo json_encode(['ok' => false, 'msg' => 'Question introuvable']); exit; }

        $correct_idx = [];
        foreach ($q['options'] as $i => $opt) { if (!empty($opt['correcte'])) $correct_idx[] = $i; }
        sort($selected); sort($correct_idx);
        $iscorrect = ($selected === $correct_idx);

        l3m_seq_save_answer($USER->id, $sequenceid, $qid, 'qcm', null, null, json_encode($selected), $iscorrect ? 1 : 0, $attempt);

        // Mecanisme de remediation (accord du 14/09/2026, voir doc de
        // cadrage) : l'ecran ne montre JAMAIS quelle option etait correcte
        // ni d'explication par option — on ne renvoie donc plus "options".
        // "correct" (booleen, cette question precise) reste renvoye : le
        // client en a besoin pour accumuler le score total sur l'ecran
        // qcm_score, mais ne l'affiche jamais par question.
        echo json_encode(['ok' => true, 'correct' => $iscorrect], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Resume de remediation genere par l'IA — affiche entre l'echec du 1er
    // passage et le 2e passage (identique) du QCM. Ne revele JAMAIS quelle
    // question etait ratee : s'appuie sur le contenu non note de l'etape +
    // l'enonce des questions ratees (utilise en interne uniquement, pour
    // cibler les bons themes), jamais cite tel quel dans la reponse.
    if ($action === 'get_remediation_summary') {
        $blocCle = required_param('blocCle', PARAM_ALPHANUMEXT);
        $missedQuestions = json_decode(required_param('missed_questions', PARAM_RAW), true) ?: [];

        // Cache : un resume deja genere pour CE bloc + CET echec est reutilise
        // tel quel (evite de re-appeler l'IA a chaque revisite de l'ecran).
        $cachekey = $blocCle . '_remediation_summary';
        $cached = $DB->get_record('local_l3madmin_seq_answers', ['userid' => $USER->id, 'sequenceid' => $sequenceid, 'screenid' => $cachekey], '*', IGNORE_MULTIPLE);
        if ($cached && $cached->answertext) {
            echo json_encode(['ok' => true, 'summary' => $cached->answertext], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $ctx_txt = '';
        foreach (($content['blocs'] ?? []) as $bloc) {
            if (($bloc['type'] ?? '') !== 'expo') continue;
            foreach (($bloc['ecrans'] ?? []) as $ecran) {
                if (!empty($ecran['texte'])) $ctx_txt .= "\n\n" . trim($ecran['texte']);
            }
        }
        $ctx_txt = trim(preg_replace('/\s+/u', ' ', $ctx_txt));
        if (mb_strlen($ctx_txt) > 6000) $ctx_txt = mb_substr($ctx_txt, 0, 6000);

        $missed_txt = '';
        foreach ($missedQuestions as $mq) { $missed_txt .= "- " . clean_param($mq, PARAM_TEXT) . "\n"; }

        $prompt = "Tu es Lia, l'assistante pedagogique d'une formation professionnelle (ECSR). "
            . "Un stagiaire vient d'echouer a un QCM de positionnement. Voici le contenu du support concerne :\n\n"
            . ($ctx_txt !== '' ? $ctx_txt : '(pas de contenu texte disponible)')
            . "\n\nVoici les enonces des questions ratees (usage interne UNIQUEMENT, pour cibler les bons themes a rappeler) :\n"
            . $missed_txt
            . "\n\nRedige un court resume cible (4-6 phrases) rappelant les notions du support liees a ces questions, "
            . "en tutoyant le stagiaire. IMPORTANT : ne jamais dire explicitement quelles questions ont ete ratees, "
            . "ne jamais citer les enonces des questions ci-dessus, ne jamais dire \"tu as repondu faux a...\" — "
            . "presente ca comme un rappel de revision normal, pas comme une correction. "
            . "Reponds UNIQUEMENT avec un objet JSON valide de cette forme exacte : {\"summary\": \"...\"}";

        $result = l3m_call_gemini($prompt);
        $summary = ($result['ok'] && !empty($result['data']['summary']))
            ? (string)$result['data']['summary']
            : "Reprends le contenu de cette étape avant de retenter le QCM : relis les points clés des parties précédentes.";

        l3m_seq_save_answer($USER->id, $sequenceid, $cachekey, 'remediation_summary', $summary, null, null, null, 1);
        echo json_encode(['ok' => true, 'summary' => $summary], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Alerte formateur : le stagiaire n'a toujours pas atteint le seuil
    // apres le 2e passage (non bloquant — la sequence continue quand meme).
    // Idempotent : un seul enregistrement par etudiant+bloc (cle unique en
    // base), donc rejouable sans dupliquer l'alerte/la notification.
    if ($action === 'report_remediation_failure') {
        $blocCle = required_param('blocCle', PARAM_ALPHANUMEXT);
        $already = $DB->record_exists('local_l3madmin_seq_alerts', ['userid' => $USER->id, 'sequenceid' => $sequenceid, 'bloccle' => $blocCle]);
        if (!$already) {
            $DB->insert_record('local_l3madmin_seq_alerts', (object)[
                'userid' => $USER->id, 'sequenceid' => $sequenceid, 'bloccle' => $blocCle,
                'resolved' => 0, 'timecreated' => time(),
            ]);
            l3m_seq_notify_formateurs_alert($USER->id, $content['titre'] ?? $sequenceid, $seqid, $etapeid);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'save_state') {
        $fields = [];
        foreach (['currentscreen', 'phase3passed', 'phase6passed', 'completed'] as $f) {
            if (isset($_POST[$f])) $fields[$f] = ($f === 'currentscreen') ? clean_param($_POST[$f], PARAM_TEXT) : (int)$_POST[$f];
        }
        $row = l3m_seq_save_state($USER->id, $sequenceid, $fields);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reset_progress') {
        // Utile en test : recommencer la sequence de zero.
        $DB->delete_records('local_l3madmin_seq_answers', ['userid' => $USER->id, 'sequenceid' => $sequenceid]);
        $DB->delete_records('local_l3madmin_seq_state', ['userid' => $USER->id, 'sequenceid' => $sequenceid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Action inconnue']);
    exit;
}

// ===================== SUPPORT PRE-REDIGE (GET) =====================
// Remplace depuis le 16/09/2026 l'ancien export PDF genere a la volee depuis
// les reponses de l'eleve (TCPDF) : desormais un seul fichier pre-redige par
// l'equipe pedagogique, identique pour tous, qui se debloque une fois
// l'etape terminee — voir doc_expli.pdf et l3m_referentiel.php.
if (optional_param('action', '', PARAM_ALPHANUMEXT) === 'download_document') {
    if (!l3m_seq_document_deverrouille_pour($USER->id, $sequenceid, $seqid, $etapeid)) {
        $verrouille_manuel = l3m_seq_doc_override($sequenceid) === 'locked';
        http_response_code(403);
        header('Content-Type: text/plain');
        echo $verrouille_manuel
            ? "Ce support a ete verrouille par le formateur."
            : "Ce support se debloque une fois l'etape terminee.";
        exit;
    }
    $docpath = l3m_seq_etape_document_path($seqid, $etapeid);
    if (!$docpath || !file_exists($docpath)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "Ce support n'est pas encore disponible — il est en cours de preparation par l'equipe pedagogique.";
        exit;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($docpath) . '"');
    header('Content-Length: ' . filesize($docpath));
    readfile($docpath);
    exit;
}

$brand = l3m_get_branding_for_user($USER->id);
$sesskey = sesskey();
$state = l3m_seq_get_state($USER->id, $sequenceid);
$answers_raw = l3m_seq_get_answers($USER->id, $sequenceid);
$answers_out = [];
foreach ($answers_raw as $sid => $r) {
    $answers_out[$sid] = ['answertext' => $r->answertext, 'aiecho' => $r->aiecho, 'qcmcorrect' => $r->qcmcorrect,
        'attempt' => $r->attempt, 'qcmSelected' => $r->qcmdata ? json_decode($r->qcmdata) : null];
}

// La correction (options.correcte / explication) n'est PAS envoyee au client
// tant que la question n'a pas ete repondue — elle arrive au moment de la
// reponse via l'action save_qcm (voir plus haut). On nettoie ici la copie
// envoyee au navigateur au chargement de page pour les QCM deja soumis :
// dans ce cas, la correction est deja legitimement connue de l'eleve, donc
// on la renvoie ; pour les QCM pas encore repondus, on retire "correcte"/
// "explication" de chaque option.
$content_for_client = $content;
foreach ($content_for_client['blocs'] as &$bloc) {
    if (($bloc['type'] ?? '') !== 'qcm_bloc') continue;
    foreach ($bloc['questions'] as &$q) {
        if (isset($answers_out[$q['id']])) continue; // deja repondu : correction visible
        foreach ($q['options'] as &$opt) { unset($opt['correcte']); unset($opt['explication']); }
        unset($opt);
    }
    unset($q);
}
unset($bloc);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= s($content['titre']) ?></title>
<style>
:root { --teal:<?= $brand['teal'] ?>; --teal-d:<?= $brand['teal_d'] ?>; --teal-t:<?= $brand['teal_t'] ?>;
  --orange:<?= $brand['orange'] ?>; --bg:<?= $brand['bg'] ?>; --card:<?= $brand['card'] ?>; --ink:<?= $brand['ink'] ?>;
  --muted:<?= $brand['muted'] ?>; --line:<?= $brand['line'] ?>; --red:#D64545; --red-t:#FBE7E7; }
* { box-sizing:border-box; }
body { margin:0; font-family:"Segoe UI",system-ui,sans-serif; background:var(--bg); color:var(--ink); }
.topbar { background:var(--card); border-bottom:1px solid var(--line); padding:12px 20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; row-gap:8px; }
.topbar a.nav-locked { pointer-events:none; opacity:.35; cursor:not-allowed; }
.topbar b { font-size:14px; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.topbar .tag { background:#5B4FCF; color:#fff; font-size:10px; font-weight:800; padding:3px 9px; border-radius:99px; flex-shrink:0; }
@media(max-width:480px) {
  .topbar b { max-width:150px; }
  .topbar > span[style*="flex:1"] { display:none; }
}
.wrap { max-width:720px; margin:0 auto; padding:32px 20px 80px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:26px 28px; margin-bottom:16px; }
.texte { font-size:14.5px; line-height:1.7; white-space:pre-wrap; }
.video-embed { position:relative; width:100%; padding-top:56.25%; margin:12px 0; border-radius:10px; overflow:hidden; background:#000; }
.video-embed iframe { position:absolute; inset:0; width:100%; height:100%; border:none; }
.ecran-image { display:block; max-width:100%; height:auto; border-radius:10px; margin:12px 0; }
.question-lbl { font-size:13px; font-weight:700; margin:16px 0 8px; }
textarea { width:100%; min-height:70px; border:1px solid var(--line); border-radius:10px; padding:10px 12px; font-family:inherit; font-size:13.5px; resize:vertical; }
.echo-box { background:var(--teal-t); border-radius:10px; padding:12px 14px; font-size:13px; margin-top:10px; display:none; }
.echo-box.on { display:block; }
.ref-box { background:#F4F2E9; border-radius:10px; padding:12px 14px; font-size:13px; margin-top:8px; display:none; }
.ref-box.on { display:block; }
.btn { display:inline-flex; align-items:center; gap:6px; border:none; border-radius:99px; padding:10px 22px; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; background:var(--teal); color:#fff; }
.btn:disabled { opacity:.4; cursor:not-allowed; }
.btn.ghost { background:var(--card); color:var(--teal-d); border:1.5px solid var(--teal); }
.footer-nav { display:flex; justify-content:flex-end; gap:10px; margin-top:8px; }
.progress { font-size:11px; color:var(--muted); margin-bottom:6px; }
.opt { display:flex; align-items:flex-start; gap:8px; padding:10px 12px; border:1px solid var(--line); border-radius:10px; margin-bottom:8px; cursor:pointer; }
.opt.sel { border-color:var(--teal); background:var(--teal-t); }
.opt.correcte { border-color:#2E9488; background:#E3F2EF; }
.opt.incorrecte-sel { border-color:var(--red); background:var(--red-t); }
.opt-expl { font-size:12px; color:var(--muted); margin-top:4px; padding-left:26px; }
.score-box { text-align:center; padding:20px; }
.score-num { font-size:36px; font-weight:800; }
.score-ok { color:#2E9488; } .score-ko { color:var(--red); }
.badge-notete { display:inline-block; background:var(--red-t); color:var(--red); font-size:10px; font-weight:800; padding:2px 9px; border-radius:99px; margin-bottom:8px; }
.badge-nonnote { display:inline-block; background:#EEE; color:var(--muted); font-size:10px; font-weight:800; padding:2px 9px; border-radius:99px; margin-bottom:8px; }
.dbg { position:fixed; bottom:14px; right:14px; background:#5B4FCF; color:#fff; font-size:11px; font-weight:700; padding:6px 12px; border-radius:99px; cursor:pointer; }
</style>
</head>
<body>
<div class="topbar">
  <img src="<?= s($brand['logo']) ?>" style="height:28px">
  <b><?= s($content['titre']) ?></b>
  <span class="tag">🧪 TEST</span>
  <span style="flex:1"></span>
  <a id="nav-autres" href="<?= $CFG->wwwroot ?>/sequence-select.php" style="font-size:12px;color:var(--muted);text-decoration:none;margin-right:10px">&larr; Autres séquences</a>
  <a id="nav-espace" href="<?= $CFG->wwwroot ?>/espace-eleve.php" style="font-size:12px;color:var(--muted);text-decoration:none">Mon espace</a>
</div>
<div class="wrap" id="app"></div>
<div class="dbg" onclick="seqResetProgress()" title="Recommencer la sequence (test uniquement)">↺ Recommencer</div>

<script>
var CONTENT   = <?= json_encode($content_for_client, JSON_UNESCAPED_UNICODE) ?>;
var ANSWERS   = <?= json_encode($answers_out, JSON_UNESCAPED_UNICODE) ?>;
// Brouillons non encore valides sur un ecran a plusieurs questions : sans
// ca, valider UNE question re-dessine tout l'ecran a partir de ANSWERS
// (qui ne contient pas encore les autres reponses) et efface ce qui etait
// tape mais pas encore soumis dans les autres champs du meme ecran.
var _draftAnswers = {};
var STATE     = <?= json_encode(['currentscreen' => $state->currentscreen, 'phase3passed' => (int)$state->phase3passed, 'phase6passed' => (int)$state->phase6passed, 'completed' => (int)$state->completed]) ?>;
var SESSKEY   = <?= json_encode($sesskey) ?>;
var SEQIDSTR  = <?= json_encode($sequenceid) ?>; // id cote base (ex. "seq2_etape1"), pour debug uniquement
var SEQNUM    = <?= json_encode($seqid) ?>;
var ETAPENUM  = <?= json_encode($etapeid) ?>;
var PAGEURL   = <?= json_encode($CFG->wwwroot . '/sequence-player.php') ?>;
// Racine du site — pour construire l'URL des images d'ecran (voir
// sequence-editor.php, champ "Image" : chemin relatif du type
// "seq_images/xxx.jpg" stocke dans le contenu).
var SITEURL   = <?= json_encode($CFG->wwwroot) ?>;
// Support pre-redige (voir l3m_referentiel.php) — vrai seulement si le
// fichier a ete depose sur le disque ET pas verrouille manuellement par le
// formateur, sinon on affiche "en preparation" plutot qu'un lien casse.
var DOC_DISPONIBLE = <?= json_encode(l3m_seq_etape_document_disponible($seqid, $etapeid) && l3m_seq_doc_override($sequenceid) !== 'locked') ?>;

function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
// Litteral JS a inserer dans un attribut onclick="..." (double-quote) : quote
// simple pour l'enveloppe JS + echappement HTML pour rester dans l'attribut.
function jsStr(v) {
  return "'" + String(v == null ? '' : v)
    .replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, '\\n').replace(/"/g, '&quot;') + "'";
}
// Meme principe pour passer un objet/tableau entier a un onclick="..." :
// JSON.stringify produit des guillemets doubles qui casseraient l'attribut
// HTML (lui-meme entre guillemets doubles) — on repasse par jsStr() puis
// JSON.parse() cote handler.
function jsObj(v) { return 'JSON.parse(' + jsStr(JSON.stringify(v)) + ')'; }
function apiPost(data) {
  var fd = new FormData();
  fd.append('sesskey', SESSKEY);
  fd.append('seq', SEQNUM);     // sans ca, le serveur retomberait sur la sequence/etape par defaut (2/1)
  fd.append('etape', ETAPENUM);
  Object.keys(data).forEach(function(k){ fd.append(k, typeof data[k] === 'object' ? JSON.stringify(data[k]) : data[k]); });
  return fetch(PAGEURL, { method: 'POST', body: fd }).then(function(r){ return r.json(); });
}

function seqResetProgress() {
  if (!confirm('Recommencer la sequence a zero (efface toutes tes reponses) ?')) return;
  apiPost({ action: 'reset_progress' }).then(function(){ location.reload(); });
}

// ── Aplatit le contenu en une liste uniforme d'etapes ──
// Genere la liste a plat des ecrans a partir de CONTENT.blocs (liste
// ordonnee et de longueur libre, depuis le 15/09/2026 — avant : noms de
// phases fixes en dur, ne supportait qu'une seule forme de contenu, celle
// du module 2 de test). Un bloc peut etre : ouverture / expo (non note,
// plusieurs ecrans) / transition / qcm_bloc (note, plusieurs questions +
// un ecran de score) / collecte_brute / cloture (+ ecran pdf apparie).
function nextBlocFirstId(curIdx) {
  for (var i = curIdx + 1; i < CONTENT.blocs.length; i++) {
    var b = CONTENT.blocs[i];
    if (b.type === 'expo' && b.ecrans.length) return b.ecrans[0].id;
    if (b.type === 'transition') return b.cle;
    if (b.type === 'qcm_bloc' && b.questions.length) return b.questions[0].id;
    if (b.type === 'collecte_brute') return b.id || b.cle;
    if (b.type === 'cloture') return b.cle;
  }
  return null; // dernier bloc du contenu, rien apres
}
function buildSteps() {
  var steps = [];
  CONTENT.blocs.forEach(function(bloc, bi) {
    if (bloc.type === 'ouverture') {
      steps.push({ id: '0.1', type: 'ouverture', data: bloc });

    } else if (bloc.type === 'expo') {
      bloc.ecrans.forEach(function(e){ steps.push(Object.assign({ blocCle: bloc.cle, statut: bloc.statut }, e)); });

    } else if (bloc.type === 'transition') {
      steps.push({ id: bloc.cle, type: 'transition', data: bloc });

    } else if (bloc.type === 'qcm_bloc') {
      bloc.questions.forEach(function(q, i){
        steps.push({ id: q.id, type: 'qcm', blocCle: bloc.cle, question: q, first: i===0, last: i===bloc.questions.length-1, attemptNum: 1 });
      });
      steps.push({
        id: bloc.cle + '_score', type: 'qcm_score', blocCle: bloc.cle,
        seuil: bloc.seuil_bon_sur_total, seuilpct: bloc.seuil_pct,
        qids: bloc.questions.map(function(q){ return q.id; }),
        nextafter: nextBlocFirstId(bi), attemptNum: 1,
      });

    } else if (bloc.type === 'collecte_brute') {
      steps.push(Object.assign({}, bloc, { type: 'collecte_brute' }));

    } else if (bloc.type === 'cloture') {
      steps.push({ id: bloc.cle, type: 'cloture', data: bloc });
      steps.push({ id: bloc.cle + '_document', type: 'document', data: bloc });
    }
  });
  return steps;
}
var STEPS = buildSteps();
function stepIndexById(id) { for (var i=0;i<STEPS.length;i++) if (STEPS[i].id === id) return i; return 0; }
var CUR = stepIndexById(STATE.currentscreen);

// Progression a sens unique : une fois un ecran atteint (ex. le debut du
// QCM), impossible d'y revenir pour relire l'exposition ou re-changer une
// reponse deja validee — goTo() refuse tout deplacement vers un index
// anterieur a CUR. Sans danger pour les usages existants : tous les appels
// reels (goNext(), scoreContinue(), goToRemediationSummary(), startRetake())
// avancent uniquement, jamais de retour arriere legitime dans ce lecteur.
function goTo(idx) {
  if (idx < CUR) return;
  CUR = idx;
  var step = STEPS[CUR];
  apiPost({ action: 'save_state', currentscreen: step.id });
  render();
}
function goNext() { goTo(Math.min(CUR + 1, STEPS.length - 1)); }

function render() {
  var step = STEPS[CUR];
  var app = document.getElementById('app');
  var pct = Math.round((CUR / (STEPS.length - 1)) * 100);
  // "Écran" (pas "Étape") : depuis le 15/09/2026, "Étape" designe une portion
  // d'une Sequence (voir l3m_referentiel.php) — reutiliser le meme mot ici
  // pour le numero d'ecran affiche pretait a confusion (question du 16/09).
  var html = '<div class="progress">Écran ' + (CUR+1) + ' / ' + STEPS.length + '</div>';

  if (step.type === 'ouverture') {
    html += '<div class="card"><span class="badge-nonnote">Introduction</span><h2>' + esc(step.data.titre) + '</h2>' +
      '<p class="texte">' + esc(step.data.accroche) + '</p>' +
      '<ul>' + step.data.objectifs.map(function(o){return '<li>'+esc(o)+'</li>';}).join('') + '</ul>' +
      '<div class="footer-nav"><button class="btn" onclick="goNext()">Commencer</button></div></div>';

  } else if (step.type === 'transition') {
    html += '<div class="card" style="text-align:center"><p class="texte">' + esc(step.data.texte) + '</p>' +
      '<div class="footer-nav" style="justify-content:center"><button class="btn" onclick="goNext()">' + esc(step.data.bouton) + '</button></div></div>';

  } else if (step.type === 'amorce' || step.type === 'texte_question' || step.type === 'texte_question_double' || step.type === 'amorces_multiples' || step.type === 'texte_seul' || step.type === 'synthese') {
    html += renderExposition(step);

  } else if (step.type === 'collecte_brute') {
    html += renderCollecte(step);

  } else if (step.type === 'qcm') {
    html += renderQcm(step);

  } else if (step.type === 'qcm_score') {
    html += renderQcmScore(step);

  } else if (step.type === 'remediation_summary') {
    html += renderRemediationSummary(step);

  } else if (step.type === 'cloture') {
    html += '<div class="card" style="text-align:center"><h2>🎉 Séquence terminée</h2><p class="texte">' + esc(step.data.texte) + '</p>' +
      '<div class="footer-nav" style="justify-content:center"><button class="btn" onclick="goNext()">Voir mon support</button></div></div>';
    apiPost({ action: 'save_state', completed: 1 });

  } else if (step.type === 'document') {
    if (DOC_DISPONIBLE) {
      html += '<div class="card" style="text-align:center"><p class="texte">🔓 Ton support « ' + esc(step.data.document.nom_affiche) + ' » vient de se débloquer.</p>' +
        '<div class="footer-nav" style="justify-content:center"><a class="btn" href="' + PAGEURL + '?action=download_document&seq=' + SEQNUM + '&etape=' + ETAPENUM + '" target="_blank">⬇ Télécharger le support</a></div></div>';
    } else {
      html += '<div class="card" style="text-align:center"><p class="texte">Ton support « ' + esc(step.data.document.nom_affiche) + ' » est en cours de préparation par l\'équipe pédagogique. Il apparaîtra ici et dans « Programme » dès qu\'il sera disponible.</p></div>';
    }
  }

  app.innerHTML = html;
  updateNavLock(step);
}

// Pendant un QCM note pas encore valide, l'eleve ne doit "aller nulle
// part" : on desactive les liens de sortie de la topbar (retour aux
// sequences, mon espace) et on avertit le navigateur si l'onglet est
// ferme/rafraichi/navigue en arriere pendant ce temps. Redevient normal
// des que la question est validee (ANSWERS[...] rempli) ou qu'on quitte
// l'ecran QCM.
function updateNavLock(step) {
  var locked = step.type === 'qcm' && !ANSWERS[step.question.id];
  ['nav-autres', 'nav-espace'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.classList.toggle('nav-locked', locked);
  });
  window.onbeforeunload = locked ? function(e) {
    e.preventDefault();
    e.returnValue = '';
    return '';
  } : null;
}

// Convertit un lien YouTube/Vimeo "normal" en URL embarquable dans un
// iframe — l'ecran ne stocke que le lien tel que colle par le formateur
// dans l'editeur (voir sequence-editor.php), jamais l'URL d'embed directement.
function videoEmbedUrl(url) {
  if (!url) return null;
  var yt = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)([\w-]{6,})/);
  if (yt) return 'https://www.youtube.com/embed/' + yt[1];
  var vim = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
  if (vim) return 'https://player.vimeo.com/video/' + vim[1];
  return null;
}

function renderExposition(step) {
  var html = '<div class="card"><span class="badge-nonnote">Non noté</span>';
  if (step.sous_titre) html += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin:8px 0 2px">' + esc(step.sous_titre) + '</div>';
  if (step.texte) html += '<p class="texte">' + esc(step.texte) + '</p>';
  if (step.image) {
    html += '<img class="ecran-image" src="' + esc(SITEURL + '/' + step.image) + '" alt="">';
  }
  if (step.video) {
    var embedUrl = videoEmbedUrl(step.video);
    html += embedUrl
      ? '<div class="video-embed"><iframe src="' + esc(embedUrl) + '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>'
      : '<p class="texte" style="color:var(--muted);font-style:italic">Lien vidéo non reconnu (YouTube/Vimeo attendu).</p>';
  }

  var questions = [];
  if (step.type === 'amorce') questions = [{ id_question: step.id, question: step.question, isAmorce: true }];
  else if (step.type === 'texte_question') questions = [{ id_question: step.id_question, question: step.question, reponse_reference: step.reponse_reference }];
  else if (step.type === 'texte_question_double') questions = step.questions;
  else if (step.type === 'amorces_multiples') questions = step.questions.map(function(q){ return Object.assign({ isAmorce: true }, q); });

  questions.forEach(function(q) {
    var qid = q.id_question || step.id;
    var saved = ANSWERS[qid];
    var draftAttr = saved ? '' : ' oninput="_draftAnswers[' + jsStr(qid) + ']=this.value"';
    html += '<div class="question-lbl">' + esc(q.question) + '</div>' +
      '<textarea id="ta_' + qid + '" placeholder="Ta réponse…" ' + (saved ? 'disabled' : '') + draftAttr + '>' + esc(saved ? saved.answertext : (_draftAnswers[qid] || '')) + '</textarea>' +
      (!saved ? '<div style="margin-top:6px"><button class="btn ghost" onclick="submitEcrit(' + jsStr(qid) + ', ' + jsStr(q.question) + ', ' + jsStr(q.reponse_reference || '') + ')">Valider</button></div>' : '') +
      '<div class="echo-box' + (saved && saved.aiecho ? ' on' : '') + '" id="echo_' + qid + '">' + (saved ? esc(saved.aiecho) : '') + '</div>' +
      (q.reponse_reference && saved ? '<div class="ref-box on">✓ ' + esc(q.reponse_reference) + '</div>' : '');
  });

  // Reveler les amorces precedentes si ce step le demande
  var revele = step.revele_reponse_de || step.revele_a;
  if (revele) {
    var ids = Array.isArray(revele) ? revele : [revele];
    ids.forEach(function(prevId) {
      var prevStep = STEPS.find(function(s){ return s.id === prevId; });
      var refText = step.revele_texte || step.reponse_reference;
      if (prevStep) html += '<div class="ref-box on" style="margin-top:12px"><i>Rappel de ta réponse à « ' + esc(prevStep.question || '') + ' »</i><br>✓ ' + esc(refText || '') + '</div>';
    });
  }

  var allAnswered = questions.every(function(q){ return !!ANSWERS[q.id_question || step.id]; });
  html += '<div class="footer-nav"><button class="btn" ' + (allAnswered ? '' : 'disabled') + ' onclick="goNext()">Continuer</button></div></div>';
  return html;
}

function submitEcrit(qid, question, reponse_reference) {
  var ta = document.getElementById('ta_' + qid);
  var answer = ta.value.trim();
  if (!answer) { alert('Écris une réponse avant de valider.'); ta.focus(); return; }
  ta.disabled = true;
  apiPost({ action: 'save_ecrit', screenid: qid, question: question, answer: answer, reponse_reference: reponse_reference }).then(function(r) {
    ANSWERS[qid] = { answertext: answer, aiecho: r.echo || '' };
    delete _draftAnswers[qid];
    render();
  }).catch(function() {
    // Ne jamais laisser le bouton bloque si l'appel echoue (reseau, IA en
    // erreur) : on redonne la main pour reessayer plutot que de rester
    // coince sans aucune action possible.
    ta.disabled = false;
    alert("Une erreur est survenue, réessaie.");
  });
}

function renderCollecte(step) {
  var html = '<div class="card"><span class="badge-nonnote">Collecte, aucune correction</span>' +
    '<p class="texte" style="color:var(--muted);font-style:italic">Tes réponses ici ne sont ni jugées ni corrigées.</p>';
  var allAnswered = true;
  step.champs.forEach(function(c) {
    var sid = step.id + '_' + c.id_champ;
    var saved = ANSWERS[sid];
    if (!saved) allAnswered = false;
    html += '<div class="question-lbl">' + esc(c.consigne) + '</div>' +
      '<textarea id="tc_' + c.id_champ + '" ' + (saved ? 'disabled' : '') + '>' + esc(saved ? saved.answertext : '') + '</textarea>';
  });
  html += '<div class="footer-nav">' +
    (allAnswered ? '<button class="btn" onclick="goNext()">Continuer</button>' :
      '<button class="btn ghost" onclick="submitCollecte(' + jsObj(step) + ')">Envoyer</button>') +
    '</div></div>';
  return html;
}
function submitCollecte(step) {
  var vals = step.champs.map(function(c) { return document.getElementById('tc_' + c.id_champ).value.trim(); });
  if (vals.some(function(v){ return !v; })) { alert('Remplis tous les champs avant d\'envoyer.'); return; }
  var calls = step.champs.map(function(c, i) {
    ANSWERS[step.id + '_' + c.id_champ] = { answertext: vals[i] };
    return apiPost({ action: 'save_collecte', screenid: step.id, champid: c.id_champ, answer: vals[i] });
  });
  Promise.all(calls).then(render);
}

function renderQcm(step) {
  var q = step.question;
  var saved = ANSWERS[q.id];
  var html = '<div class="card"><span class="badge-notete">Noté</span><div class="question-lbl" style="font-size:14px">' + esc(q.question) + '</div>';
  q.options.forEach(function(opt, i) {
    // Mecanisme de remediation (accord du 14/09/2026) : jamais reveler
    // quelle option etait correcte, meme apres validation — la question
    // passe juste en lecture seule (grisee), sans indication juste/faux.
    var checked = saved && saved.qcmSelected && saved.qcmSelected.indexOf(i) !== -1;
    var cls = 'opt' + (checked ? ' sel' : '');
    html += '<label class="' + cls + '" data-i="' + i + '">' +
      '<input type="checkbox" ' + (saved ? 'disabled' : '') + (checked ? ' checked' : '') + ' onchange="toggleOpt(this)"> ' +
      '<span>' + esc(opt.texte) + '</span></label>';
  });
  html += '<div class="footer-nav">' +
    (saved ? '<button class="btn" onclick="goNext()">' + (step.last ? 'Voir le score' : 'Suivant') + '</button>' :
      '<button class="btn" id="qcm-valider" onclick="submitQcm(' + jsStr(q.id) + ', ' + step.attemptNum + ')">Valider</button>') +
    '</div></div>';
  return html;
}
function toggleOpt(cb) { cb.closest('.opt').classList.toggle('sel', cb.checked); }
function submitQcm(qid, attemptNum) {
  var selected = [];
  document.querySelectorAll('.opt input:checked').forEach(function(cb){ selected.push(parseInt(cb.closest('.opt').dataset.i, 10)); });
  if (!selected.length) { alert('Coche au moins une réponse avant de valider.'); return; }
  var btn = document.getElementById('qcm-valider');
  if (btn) btn.disabled = true;
  apiPost({ action: 'save_qcm', qid: qid, selected: selected, attempt: attemptNum }).then(function(r) {
    // "correct" sert uniquement a accumuler le score total sur l'ecran
    // qcm_score qui suit — jamais affiche question par question.
    ANSWERS[qid] = { qcmSelected: selected, qcmcorrect: r.correct ? 1 : 0 };
    render();
  }).catch(function() {
    if (btn) btn.disabled = false;
    alert("Une erreur est survenue, réessaie.");
  });
}

function blocIndexByCle(cle) {
  for (var i = 0; i < CONTENT.blocs.length; i++) if (CONTENT.blocs[i].cle === cle) return i;
  return -1;
}
function findBlocByCle(cle) {
  return CONTENT.blocs.find(function(b){ return b.cle === cle; });
}

// Ecran de score du QCM — mecanisme de remediation agree le 14/09/2026 :
// - 1er passage : jamais de detail par question, seul le score compte.
//   Echec -> resume de revision genere par l'IA, puis meme QCM en entier
//   repasse (pas seulement les questions manquees).
// - 2e passage : jamais bloquant, quel que soit le resultat. Echec -> une
//   alerte est envoyee au(x) formateur(s), sans bloquer la progression.
function renderQcmScore(step) {
  var nbCorrect = 0;
  step.qids.forEach(function(qid){ if (ANSWERS[qid] && ANSWERS[qid].qcmcorrect) nbCorrect++; });
  var total = step.qids.length;
  var pct = Math.round((nbCorrect / total) * 100);
  var passed = pct >= step.seuilpct;
  var html = '<div class="card score-box">' +
    '<div class="score-num ' + (passed ? 'score-ok' : 'score-ko') + '">' + nbCorrect + ' / ' + total + '</div>' +
    '<p class="texte">Seuil requis : ' + step.seuil + ' (' + step.seuilpct + ' %)</p>';

  if (passed) {
    html += '<p style="color:#2E9488;font-weight:700">✓ Seuil atteint</p><div class="footer-nav" style="justify-content:center">' +
      '<button class="btn" onclick="scoreContinue(' + jsObj(step) + ')">Continuer</button></div>';

  } else if (!step.finalAttempt) {
    html += '<p style="color:var(--red);font-weight:700">✗ Seuil non atteint</p>' +
      '<p class="texte">Un résumé ciblé va t\'aider à revoir les points clés avant de retenter le même QCM.</p>' +
      '<div class="footer-nav" style="justify-content:center"><button class="btn ghost" onclick="goToRemediationSummary(' + jsObj(step) + ')">Voir mon résumé de révision</button></div>';

  } else {
    // 2e passage toujours en echec : jamais bloquant, on informe juste le
    // stagiaire que son formateur en sera informe, et on continue.
    html += '<p style="color:var(--red);font-weight:700">✗ Seuil non atteint</p>' +
      '<p class="texte">Pas de souci, ton formateur en sera informé pour t\'accompagner sur ce point. Tu peux continuer.</p>' +
      '<div class="footer-nav" style="justify-content:center"><button class="btn" onclick="reportRemediationFailureAndContinue(' + jsObj(step) + ')">Continuer</button></div>';
  }
  html += '</div>';
  return html;
}
function scoreContinue(step) {
  goTo(stepIndexById(step.nextafter));
}
function reportRemediationFailureAndContinue(step) {
  apiPost({ action: 'report_remediation_failure', blocCle: step.blocCle }).then(function() {
    scoreContinue(step);
  }).catch(function() { scoreContinue(step); }); // ne bloque jamais la progression, meme si l'alerte echoue
}

// Ecran de resume IA entre l'echec du 1er passage et le 2e passage
// (identique) du meme QCM.
function renderRemediationSummary(step) {
  var box = document.getElementById('app');
  var bloc = findBlocByCle(step.blocCle);
  var missed = step.qids.filter(function(qid){ return !(ANSWERS[qid] && ANSWERS[qid].qcmcorrect); });
  var missedTexts = missed.map(function(qid){
    var q = bloc.questions.find(function(x){ return x.id === qid; });
    return q ? q.question : '';
  });
  loadRemediationSummary(step.blocCle, missedTexts);
  return '<div class="card">' +
    '<span class="badge-nonnote">Révision</span>' +
    '<p class="texte" id="remediation-summary-text"><span class="dv-spinner"></span> Préparation de ton résumé, quelques secondes…</p>' +
    '<div class="footer-nav" style="justify-content:center"><button class="btn" onclick="startRetake(' + jsStr(step.blocCle) + ')">Refaire le QCM</button></div>' +
    '</div>';
}
function loadRemediationSummary(blocCle, missedTexts) {
  apiPost({ action: 'get_remediation_summary', blocCle: blocCle, missed_questions: JSON.stringify(missedTexts) }).then(function(r) {
    var el = document.getElementById('remediation-summary-text');
    if (el) el.textContent = r.ok ? r.summary : "Reprends le contenu de cette étape avant de retenter le QCM.";
  }).catch(function() {
    // Sans ce filet, un simple souci reseau/timeout laissait l'ecran bloque
    // indefiniment sur "…" sans jamais rien afficher (signale le 16/09/2026).
    var el = document.getElementById('remediation-summary-text');
    if (el) {
      el.innerHTML = "Le résumé n'a pas pu être généré (problème réseau). " +
        '<button class="btn ghost" style="margin-left:8px;padding:4px 12px;font-size:11px" onclick="loadRemediationSummary(' + jsStr(blocCle) + ', ' + jsObj(missedTexts) + ')">Réessayer</button>';
    }
  });
}
function goToRemediationSummary(scoreStep) {
  var bloc = findBlocByCle(scoreStep.blocCle);
  var summaryStepId = scoreStep.blocCle + '_remediation_summary';
  var insertAt = CUR;
  var newSteps = [{ id: summaryStepId, type: 'remediation_summary', blocCle: scoreStep.blocCle, qids: scoreStep.qids }];
  Array.prototype.splice.apply(STEPS, [insertAt + 1, 0].concat(newSteps));
  goNext();
}
function startRetake(blocCle) {
  var bloc = findBlocByCle(blocCle);
  var bi = blocIndexByCle(blocCle);
  var insertAt = CUR;
  var newSteps = bloc.questions.map(function(q, i) {
    delete ANSWERS[q.id]; // permet de re-repondre (2e passage, attempt 2)
    return { id: q.id, type: 'qcm', blocCle: bloc.cle, question: q, first: i===0, last: i===bloc.questions.length-1, attemptNum: 2 };
  });
  newSteps.push({
    id: bloc.cle + '_score2', type: 'qcm_score', blocCle: bloc.cle,
    seuil: bloc.seuil_bon_sur_total, seuilpct: bloc.seuil_pct,
    qids: bloc.questions.map(function(q){ return q.id; }),
    nextafter: nextBlocFirstId(bi), attemptNum: 2, finalAttempt: true,
  });
  Array.prototype.splice.apply(STEPS, [insertAt + 1, 0].concat(newSteps));
  goNext();
}

render();
</script>
</body>
</html>

<?php
// ── Racine du domaine (lba-vc.l3m-holding.fr) ──────────────────────────────
// Remplace l'ancienne detection nginx par cookie (peu fiable : visiter
// /login/index.php cree deja un MoodleSession pour un visiteur anonyme,
// ce qui faisait croire a nginx qu'il etait connecte et le renvoyait vers
// Moodle -> forcelogin -> retour sur la page de connexion en boucle).
// Ici on verifie le vrai etat d'authentification cote PHP, puis soit on
// redirige vers le bon espace (deja connecte), soit on sert directement
// la landing page statique (anonyme / invite), sans redirection visible.
// Deploiement : SFTP -> /tmp/ -> docker cp moodle_app:/var/data/www/public/l3m_root_gate.php
// ─────────────────────────────────────────────────────────────────────────
require(__DIR__ . '/../config.php');

if (isloggedin() && !isguestuser()) {
    global $DB, $USER;

    $target = null;
    if (is_siteadmin()) {
        $target = '/admin-lba.php';
    } else {
        $ctx_sys = context_system::instance();
        $manager_roleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        if ($manager_roleid && $DB->record_exists('role_assignments', [
            'userid' => $USER->id, 'roleid' => $manager_roleid, 'contextid' => $ctx_sys->id,
        ])) {
            $target = '/admin-lba.php';
        } else {
            $teacher_roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
            if ($teacher_roleid && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $teacher_roleid])) {
                $target = '/espace-formateur.php';
            } else {
                $student_roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
                if ($student_roleid && $DB->record_exists('role_assignments', ['userid' => $USER->id, 'roleid' => $student_roleid])) {
                    $target = '/espace-eleve.php';
                }
            }
        }
    }

    // Compte connecte sans role reconnu (rare) : /my/ (tableau de bord
    // Moodle standard) plutot que de rediriger vers '/' — qui repasserait
    // par ce meme script et boucler ait.
    redirect($CFG->wwwroot . ($target ?? '/my/'));
}

// Anonyme (ou invite) : servir directement la landing page statique,
// sans redirection visible, en gardant l'URL racine propre.
header('Content-Type: text/html; charset=UTF-8');
readfile($CFG->dirroot . '/lba-academie.html');

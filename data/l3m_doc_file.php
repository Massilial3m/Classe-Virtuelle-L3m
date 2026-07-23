<?php
// ── Sert les documents uploadés via "Documents élèves" (admin-lba.php) ────────
// Auth : require_login + (propriétaire du document OU manager/admin)
// Déploiement : SFTP → /tmp/ → docker cp moodle_app:/var/data/www/public/l3m_doc_file.php
// ─────────────────────────────────────────────────────────────────────────────
require_once(__DIR__ . '/../config.php');
require_login();

$docid = required_param('docid', PARAM_INT);
$type  = optional_param('type', 'original', PARAM_ALPHA);
$doc   = $DB->get_record('l3m_docs', ['id' => $docid], '*', MUST_EXIST);

$ctx_sys        = context_system::instance();
$manager_roleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
$is_manager     = $manager_roleid && $DB->record_exists('role_assignments', [
    'userid' => $USER->id, 'roleid' => $manager_roleid, 'contextid' => $ctx_sys->id,
]);
$is_owner = ((int)$doc->userid === (int)$USER->id);

if (!$is_owner && !$is_manager && !is_siteadmin()) {
    throw new moodle_exception('nopermissions', 'error', '', 'accéder à ce document');
}

$filearea = ($type === 'signed') ? 'l3m_doc_signed' : 'l3m_doc';
$fs    = get_file_storage();
$files = $fs->get_area_files(context_user::instance($doc->userid)->id, 'local_l3madmin', $filearea, $docid, 'itemid', false);
$file  = reset($files);
if (!$file) {
    throw new moodle_exception('filenotfound', 'error');
}

send_stored_file($file, 0, 0, true);

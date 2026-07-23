<?php
namespace local_l3m_redirect;

defined('MOODLE_INTERNAL') || die();

class observer {

    /**
     * Après connexion, envoie les coordinateurs (rôle "manager" au niveau
     * site) et les administrateurs du site directement vers admin-lba.php,
     * sauf s'ils visaient déjà une page précise avant de se connecter.
     */
    public static function on_user_loggedin(\core\event\user_loggedin $event): void {
        global $DB, $CFG, $SESSION;

        if (!empty($SESSION->wantsurl)) {
            return;
        }

        $userid = (int)$event->userid;

        if (is_siteadmin($userid)) {
            $SESSION->wantsurl = $CFG->wwwroot . '/admin-lba.php';
            return;
        }

        $ctx_sys = \context_system::instance();
        $manager_roleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $is_manager = $manager_roleid && $DB->record_exists('role_assignments', [
            'userid' => $userid, 'roleid' => $manager_roleid, 'contextid' => $ctx_sys->id,
        ]);

        if ($is_manager) {
            $SESSION->wantsurl = $CFG->wwwroot . '/admin-lba.php';
            return;
        }

        $teacher_roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $is_teacher = $teacher_roleid && $DB->record_exists('role_assignments', [
            'userid' => $userid, 'roleid' => $teacher_roleid,
        ]);

        if ($is_teacher) {
            $SESSION->wantsurl = $CFG->wwwroot . '/espace-formateur.php';
            return;
        }

        $student_roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $is_student = $student_roleid && $DB->record_exists('role_assignments', [
            'userid' => $userid, 'roleid' => $student_roleid,
        ]);

        if ($is_student) {
            $SESSION->wantsurl = $CFG->wwwroot . '/espace-eleve.php';
        }
    }
}

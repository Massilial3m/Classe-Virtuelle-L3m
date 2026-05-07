<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');

cli_heading('Création des comptes test ECSR');

global $DB;

$role_formateur = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
$role_apprenant = $DB->get_field('role', 'id', ['shortname' => 'student']);

$users_to_create = [
    [
        'username'  => 'formateur.test',
        'password'  => 'Formateur@2025',
        'firstname' => 'Formateur',
        'lastname'  => 'Test',
        'email'     => 'formateur.test@lindberg-formation.fr',
        'roleid'    => $role_formateur,
        'label'     => 'formateur',
    ],
    [
        'username'  => 'apprenant.test',
        'password'  => 'Apprenant@2025',
        'firstname' => 'Apprenant',
        'lastname'  => 'Test',
        'email'     => 'apprenant.test@lindberg-formation.fr',
        'roleid'    => $role_apprenant,
        'label'     => 'apprenant',
    ],
];

$created_users = [];

foreach ($users_to_create as $udata) {
    $existing = $DB->get_record('user', ['username' => $udata['username']]);
    if ($existing) {
        cli_writeln("→ '{$udata['username']}' existe déjà (id={$existing->id})");
        $created_users[] = ['user' => $existing, 'roleid' => $udata['roleid']];
        continue;
    }

    $user = new stdClass();
    $user->username    = $udata['username'];
    $user->password    = hash_internal_user_password($udata['password']);
    $user->firstname   = $udata['firstname'];
    $user->lastname    = $udata['lastname'];
    $user->email       = $udata['email'];
    $user->lang        = 'fr';
    $user->country     = 'FR';
    $user->auth        = 'manual';
    $user->confirmed   = 1;
    $user->mnethostid  = $CFG->mnet_localhost_id;
    $user->timecreated  = time();
    $user->timemodified = time();
    $user->id = $DB->insert_record('user', $user);

    cli_writeln("✓ Créé : {$udata['firstname']} {$udata['lastname']} — {$udata['label']}");
    $created_users[] = ['user' => $DB->get_record('user', ['id' => $user->id]), 'roleid' => $udata['roleid']];
}

cli_writeln('');

// Inscrire dans tous les cours ECSR
$enrol_plugin = enrol_get_plugin('manual');

$ecsr_courses = $DB->get_records_sql("
    SELECT c.id, c.shortname
    FROM {course} c
    JOIN {course_categories} cc ON cc.id = c.category
    WHERE cc.idnumber LIKE 'ECSR%'
    ORDER BY c.id
");

cli_writeln('Inscription dans ' . count($ecsr_courses) . ' cours ECSR...');

foreach ($ecsr_courses as $course) {
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
    if (!$instance) {
        $instanceid = $enrol_plugin->add_instance($course);
        $instance   = $DB->get_record('enrol', ['id' => $instanceid]);
    }

    foreach ($created_users as $entry) {
        $already = $DB->record_exists('user_enrolments', [
            'enrolid' => $instance->id,
            'userid'  => $entry['user']->id,
        ]);
        if (!$already) {
            $enrol_plugin->enrol_user($instance, $entry['user']->id, $entry['roleid']);
        }
    }
    cli_writeln("  ✓ {$course->shortname}");
}

cli_writeln('');
cli_writeln('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
cli_writeln('FORMATEUR TEST');
cli_writeln('  Identifiant  : formateur.test');
cli_writeln('  Mot de passe : Formateur@2025');
cli_writeln('');
cli_writeln('APPRENANT TEST');
cli_writeln('  Identifiant  : apprenant.test');
cli_writeln('  Mot de passe : Apprenant@2025');
cli_writeln('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

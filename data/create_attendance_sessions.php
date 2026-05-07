<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');

cli_heading('Création des séances de test – Émargement ECSR');

global $DB;

// Statuts par défaut pour l'émargement
function create_default_statuses($attendanceid) {
    global $DB;

    $statuses = [
        ['acronym' => 'P',  'description' => 'Présent',           'grade' => 2, 'setnumber' => 0],
        ['acronym' => 'R',  'description' => 'En retard',         'grade' => 1, 'setnumber' => 0],
        ['acronym' => 'AJ', 'description' => 'Absent justifié',   'grade' => 0, 'setnumber' => 0],
        ['acronym' => 'A',  'description' => 'Absent',            'grade' => 0, 'setnumber' => 0],
    ];

    foreach ($statuses as $s) {
        $existing = $DB->get_record('attendance_statuses', [
            'attendanceid' => $attendanceid,
            'acronym'      => $s['acronym'],
            'setnumber'    => $s['setnumber'],
        ]);
        if (!$existing) {
            $rec = new stdClass();
            $rec->attendanceid  = $attendanceid;
            $rec->acronym       = $s['acronym'];
            $rec->description   = $s['description'];
            $rec->grade         = $s['grade'];
            $rec->setnumber     = $s['setnumber'];
            $rec->visible       = 1;
            $rec->deleted       = 0;
            $DB->insert_record('attendance_statuses', $rec);
        }
    }
}

// Séances à créer par cours (shortname => liste de séances)
$sessions_data = [
    'ECSR-CCP1-M1' => [
        ['desc' => 'Séance 1 – Les textes réglementaires',       'days_offset' => -7],
        ['desc' => 'Séance 2 – Rôle des institutions',           'days_offset' => -5],
        ['desc' => 'Séance 3 – Agréments et responsabilités',    'days_offset' =>  1],
    ],
    'ECSR-CCP1-M2' => [
        ['desc' => 'Séance 1 – Fondements de l\'andragogie',     'days_offset' => -6],
        ['desc' => 'Séance 2 – Méthodes pédagogiques actives',   'days_offset' => -4],
        ['desc' => 'Séance 3 – Posture du formateur',            'days_offset' =>  2],
    ],
    'ECSR-CCP2-M4' => [
        ['desc' => 'Séance 1 – Obligations administratives',     'days_offset' => -3],
        ['desc' => 'Séance 2 – CPF et Qualiopi',                 'days_offset' =>  3],
    ],
];

foreach ($sessions_data as $shortname => $sessions) {
    $course = $DB->get_record('course', ['shortname' => $shortname]);
    if (!$course) {
        cli_writeln("✗ Cours introuvable : $shortname");
        continue;
    }

    // Récupérer l'instance attendance du cours
    $cm = $DB->get_record_sql("
        SELECT cm.id, cm.instance
        FROM {course_modules} cm
        JOIN {modules} m ON m.id = cm.module
        WHERE m.name = 'attendance' AND cm.course = ?
        LIMIT 1
    ", [$course->id]);

    if (!$cm) {
        cli_writeln("✗ Attendance introuvable pour : $shortname");
        continue;
    }

    $attendanceid = $cm->instance;

    // Créer les statuts par défaut
    create_default_statuses($attendanceid);

    cli_writeln("→ {$course->fullname}");

    foreach ($sessions as $s) {
        $date = mktime(9, 0, 0,
            date('n'),
            date('j') + $s['days_offset'],
            date('Y')
        );

        $session = new stdClass();
        $session->attendanceid      = $attendanceid;
        $session->sessdate          = $date;
        $session->duration          = 7200; // 2 heures
        $session->lasttaken         = 0;
        $session->lasttakenby       = 0;
        $session->timemodified      = time();
        $session->description       = $s['desc'];
        $session->descriptionformat = FORMAT_HTML;
        $session->studentscanmark   = 1;
        $session->autoassignstatus  = 0;
        $session->subnet            = '';
        $session->studentpassword   = '';
        $session->automark          = 0;
        $session->automarkcompleted = 0;
        $session->preventsharedip   = 0;
        $session->statusset         = 0;
        $session->absenteereport    = 1;
        $session->includeqrcode     = 0;
        $session->rotateqrcode      = 0;
        $session->rotateqrcodesecret = '';
        $session->id = $DB->insert_record('attendance_sessions', $session);

        $date_str = date('d/m/Y', $date);
        $past     = $s['days_offset'] < 0 ? ' (passée)' : ' (à venir)';
        cli_writeln("  ✓ {$s['desc']} – $date_str$past");
    }
}

purge_all_caches();
cli_writeln('');
cli_writeln('Séances créées avec succès.');

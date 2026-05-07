<?php
define('CLI_SCRIPT', true);
require('/var/data/www/config.php');
require_once($CFG->libdir . '/clilib.php');

cli_heading('Mise à jour des URLs vidéo ECSR');

global $DB;

$videos = [
    [
        'name' => 'Vidéo – Le permis de conduire en France : cadre réglementaire',
        'url'  => 'https://www.youtube.com/watch?v=qWIhEjCQb28',
        'desc' => 'CCP ECSR : l\'essentiel – Présentation des fondamentaux réglementaires du métier d\'enseignant de la conduite.',
    ],
    [
        'name' => 'Vidéo – Techniques pédagogiques pour la formation à la conduite',
        'url'  => 'https://www.youtube.com/watch?v=rR2qHwIm6vA',
        'desc' => '6 techniques pédagogiques actives à utiliser en formation d\'adultes – Méthodes applicables à l\'enseignement de la conduite.',
    ],
    [
        'name' => 'Vidéo – Comprendre la certification Qualiopi',
        'url'  => 'https://www.youtube.com/watch?v=HRwKQOc8WAY',
        'desc' => 'Accéder aux financements CPF avec Qualiopi – Conditions d\'éligibilité et démarches pour les organismes de formation.',
    ],
];

foreach ($videos as $v) {
    $rec = $DB->get_record('url', ['name' => $v['name']]);
    if (!$rec) {
        cli_writeln("✗ Introuvable : {$v['name']}");
        continue;
    }
    $DB->set_field('url', 'externalurl', $v['url'],  ['id' => $rec->id]);
    $DB->set_field('url', 'intro',       '<p>' . $v['desc'] . '</p>', ['id' => $rec->id]);
    cli_writeln("✓ Mise à jour : {$v['name']}");
}

purge_all_caches();
cli_writeln('Terminé.');

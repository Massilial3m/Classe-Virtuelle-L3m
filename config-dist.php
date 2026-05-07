<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'db';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'moodle';
$CFG->dbpass    = 'moodlepassword';
$CFG->prefix    = 'mdl_';

$CFG->wwwroot   = 'http://192.168.1.12';
$CFG->dataroot  = '/var/data/moodledata';
$CFG->dirroot   = '/var/data/www';
$CFG->libdir   = '/var/data/www/lib';

$CFG->admin     = 'admin';
$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');


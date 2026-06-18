<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_l3m_whatsapp\\task\\send_reminders',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '18',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
];

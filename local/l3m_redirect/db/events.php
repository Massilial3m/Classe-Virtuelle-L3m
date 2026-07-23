<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    ['eventname' => '\\core\\event\\user_loggedin',
     'callback'  => 'local_l3m_redirect\\observer::on_user_loggedin'],
];

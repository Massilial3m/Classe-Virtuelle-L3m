<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    ['eventname' => '\\mod_forum\\event\\post_created',
     'callback'  => 'local_l3m_whatsapp\\observer::on_forum_post'],
    ['eventname' => '\\mod_forum\\event\\discussion_created',
     'callback'  => 'local_l3m_whatsapp\\observer::on_forum_discussion'],
    ['eventname' => '\\core\\event\\calendar_event_created',
     'callback'  => 'local_l3m_whatsapp\\observer::on_calendar_created'],
    ['eventname' => '\\core\\event\\calendar_event_updated',
     'callback'  => 'local_l3m_whatsapp\\observer::on_calendar_updated'],
    ['eventname' => '\\core\\event\\calendar_event_deleted',
     'callback'  => 'local_l3m_whatsapp\\observer::on_calendar_deleted'],
    ['eventname' => '\\core\\event\\user_enrolment_created',
     'callback'  => 'local_l3m_whatsapp\\observer::on_enrolment_created'],
];

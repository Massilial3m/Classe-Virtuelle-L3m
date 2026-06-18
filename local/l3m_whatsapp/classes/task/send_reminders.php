<?php
namespace local_l3m_whatsapp\task;

defined('MOODLE_INTERNAL') || die();

class send_reminders extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'Rappels WhatsApp J-1 (L3M)';
    }

    public function execute(): void {
        global $DB;

        // Fenêtre : demain 00:00 → demain 23:59
        $tomorrow_start = mktime(0,  0,  0, date('n'), date('j') + 1, date('Y'));
        $tomorrow_end   = mktime(23, 59, 59, date('n'), date('j') + 1, date('Y'));

        // Récupérer les événements du calendrier pour demain
        $events = $DB->get_records_sql("
            SELECT e.*, c.fullname AS coursename
            FROM {event} e
            LEFT JOIN {course} c ON c.id = e.courseid
            WHERE e.timestart BETWEEN :start AND :end
            AND e.visible = 1
            AND (
                e.courseid IN (2,3,4,5,6,7,8,9,10,11,12)
                OR LOWER(e.name) LIKE '%bbb%'
                OR LOWER(e.name) LIKE '%classe%'
                OR LOWER(e.name) LIKE '%session%'
                OR LOWER(e.name) LIKE '%virtuelle%'
            )
            ORDER BY e.timestart ASC
        ", ['start' => $tomorrow_start, 'end' => $tomorrow_end]);

        if (empty($events)) {
            mtrace('[L3M WhatsApp] Aucune session demain — pas de rappel envoyé.');
            return;
        }

        foreach ($events as $event) {
            $name     = clean_param($event->name, PARAM_TEXT);
            $course   = $event->coursename ? clean_param($event->coursename, PARAM_TEXT) : '';
            $heure    = userdate($event->timestart, '%Hh%M');
            $date     = userdate($event->timestart, '%A %d %B %Y');

            // Construire le lien BBB si disponible
            $bbb_link = '';
            if ($event->courseid) {
                $bbb = $DB->get_record('course_modules', [
                    'course' => $event->courseid,
                    'module' => $DB->get_field('modules', 'id', ['name' => 'bigbluebuttonbn']),
                    'deletioninprogress' => 0,
                ]);
                if ($bbb) {
                    $bbb_link = "\n🔗 " . (new \moodle_url('/mod/bigbluebuttonbn/view.php', ['id' => $bbb->id]))->out(false);
                }
            }

            $message =
                "🔔 *Rappel — Session demain*\n\n" .
                "📌 {$name}\n" .
                ($course ? "📚 {$course}\n" : "") .
                "🕐 {$date} à {$heure}" .
                $bbb_link;

            $this->send_whatsapp($message);
            mtrace("[L3M WhatsApp] Rappel envoyé pour : {$name}");
        }
    }

    private function send_whatsapp(string $message): void {
        $payload = json_encode([
            'groupId' => '120363428333307670@g.us',
            'message' => $message,
        ]);

        $ch = curl_init('http://151.80.61.51:3001/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-token: 957127c92c7233b642f1e885a6c46f91be5475bc8c7d6195bf4fe6b5aa840683'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

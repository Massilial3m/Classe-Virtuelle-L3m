<?php
namespace local_l3m_whatsapp;

defined('MOODLE_INTERNAL') || die();

class observer {

    const GROUP_ID       = '120363410662880898@g.us';
    // Reseau Docker interne (meme reseau "moodle_net" que moodle_app) plutot
    // que l'IP publique du serveur : evite d'exposer ce service sur internet
    // (question de securite du 23/10/2026, suite a Node2Shell CVE-2026-111497
    // qui a pousse a verifier l'exposition reseau de tous les services).
    const BOT_URL        = 'http://l3m_whatsapp:3001/send';
    const BOT_INVITE_URL = 'http://l3m_whatsapp:3001/invite-link';
    const API_TOKEN      = '957127c92c7233b642f1e885a6c46f91be5475bc8c7d6195bf4fe6b5aa840683';
    const ECSR_COURSES   = [2,3,4,5,6,7,8,9,10,11,12];

    /** Envoyer un message dans le groupe */
    private static function send(string $message): void {
        $payload = json_encode(['groupId' => self::GROUP_ID, 'message' => $message]);
        $ch = curl_init(self::BOT_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-token: ' . self::API_TOKEN],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /** Envoyer un message privé à un numéro de téléphone */
    private static function send_private(string $phone, string $message): void {
        $clean = preg_replace('/\D/', '', $phone);
        $payload = json_encode(['to' => $clean, 'message' => $message]);
        $ch = curl_init(self::BOT_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-token: ' . self::API_TOKEN],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /** Récupérer le lien d'invitation du groupe */
    private static function get_invite_link(): ?string {
        $url = self::BOT_INVITE_URL . '?groupId=' . urlencode(self::GROUP_ID);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['x-api-token: ' . self::API_TOKEN],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($result, true);
        return $data['link'] ?? null;
    }

    /**
     * Étudiant inscrit → envoi du lien d'invitation WhatsApp en message privé
     */
    public static function on_enrolment_created(\core\event\user_enrolment_created $event): void {
        if (!in_array($event->courseid, self::ECSR_COURSES)) return;

        global $DB;
        $user = $DB->get_record('user', ['id' => $event->relateduserid], 'id,firstname,lastname,phone1,phone2');
        if (!$user) return;

        $phone = trim($user->phone1 ?: $user->phone2);
        if (empty($phone)) return;

        $firstname  = clean_param($user->firstname, PARAM_TEXT);
        $course     = $DB->get_record('course', ['id' => $event->courseid], 'fullname');
        $coursename = $course ? clean_param($course->fullname, PARAM_TEXT) : 'la formation ECSR';

        $link = self::get_invite_link();
        if (!$link) return;

        $msg = "Bonjour {$firstname} !\n\n"
             . "Vous etes inscrit(e) a *{$coursename}* sur L3M Formation.\n\n"
             . "Rejoignez notre groupe WhatsApp pour recevoir toutes les notifications "
             . "(rappels de sessions, annonces, modifications) :\n"
             . $link . "\n\n"
             . "A bientot sur L3M Formation !";

        self::send_private($phone, $msg);
    }

    public static function on_forum_post(\mod_forum\event\post_created $event): void {
        if (!in_array($event->courseid, self::ECSR_COURSES)) return;
        global $DB;
        $post   = $DB->get_record('forum_posts', ['id' => $event->objectid]);
        $course = $DB->get_record('course', ['id' => $event->courseid], 'fullname');
        if (!$post || !$course) return;
        $subject    = clean_param($post->subject, PARAM_TEXT);
        $coursename = clean_param($course->fullname, PARAM_TEXT);
        $url = new \moodle_url('/mod/forum/discuss.php', ['d' => $post->discussion]);
        self::send("💬 *Nouveau message — Forum {$coursename}*\nSujet : {$subject}\n🔗 {$url->out(false)}");
    }

    public static function on_forum_discussion(\mod_forum\event\discussion_created $event): void {
        if (!in_array($event->courseid, self::ECSR_COURSES)) return;
        global $DB;
        $discussion = $DB->get_record('forum_discussions', ['id' => $event->objectid]);
        $course     = $DB->get_record('course', ['id' => $event->courseid], 'fullname');
        if (!$discussion || !$course) return;
        $name       = clean_param($discussion->name, PARAM_TEXT);
        $coursename = clean_param($course->fullname, PARAM_TEXT);
        $url = new \moodle_url('/mod/forum/discuss.php', ['d' => $discussion->id]);
        self::send("📢 *Nouvelle discussion — {$coursename}*\nSujet : {$name}\n🔗 {$url->out(false)}");
    }

    public static function on_calendar_created(\core\event\calendar_event_created $event): void {
        global $DB;
        $cal = $DB->get_record('event', ['id' => $event->objectid]);
        if (!$cal || !preg_match('/bbb|classe|session|virtuelle/i', $cal->name)) return;
        $date = userdate($cal->timestart, '%d/%m/%Y à %Hh%M');
        self::send("📅 *Nouvelle session planifiée*\n📌 " . clean_param($cal->name, PARAM_TEXT) . "\n🕐 {$date}");
    }

    public static function on_calendar_updated(\core\event\calendar_event_updated $event): void {
        global $DB;
        $cal = $DB->get_record('event', ['id' => $event->objectid]);
        if (!$cal || !preg_match('/bbb|classe|session|virtuelle/i', $cal->name)) return;
        $date = userdate($cal->timestart, '%d/%m/%Y à %Hh%M');
        self::send("⚠️ *Session modifiée*\n📌 " . clean_param($cal->name, PARAM_TEXT) . "\n🕐 Nouveau créneau : {$date}");
    }

    public static function on_calendar_deleted(\core\event\calendar_event_deleted $event): void {
        $other = $event->other;
        $name  = isset($other['name']) ? clean_param($other['name'], PARAM_TEXT) : 'Session';
        self::send("❌ *Session annulée*\n📌 {$name}\nCette session a été supprimée du calendrier.");
    }
}

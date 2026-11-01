<?php
namespace WS40\Broadcaster;
if (!defined('ABSPATH')) { exit; }

final class Webcam {
    const MAX_BYTES = 8388608;
    public static function boot() {
        add_action(Telegram::HOOK, array(__CLASS__, 'tick'), 30);
        add_action('admin_post_cmt_webcam_test', array(__CLASS__, 'test_send'));
    }
    public static function status($message) {
        update_option('cmt_webcam_status', wp_date('d/m/Y H:i:s', time(), Telegram::tz()) . ' — ' . $message, false);
    }
    public static function valid_url($url) {
        return is_string($url) && wp_parse_url($url, PHP_URL_SCHEME) === 'https' && wp_http_validate_url($url);
    }
    public static function tick() {
        $s = Telegram::settings();
        if (!$s['webcam_enabled'] || !$s['token']) { return; }
        $slot = Telegram::slot(time(), $s['webcam_hours'], $s);
        if ($slot !== null) { self::publish($s, $slot); }
    }
    public static function test_send() {
        Telegram::guard('cmt_webcam_test');
        $before = (string) get_option('cmt_webcam_status', '');
        self::publish(Telegram::settings(), null);
        Telegram::notice_from_status('cmt_webcam_status', $before, 'Webcam');
        Telegram::redirect();
    }
    public static function image($bytes) {
        if (!is_string($bytes) || strlen($bytes) < 12 || strlen($bytes) > self::MAX_BYTES) {
            return new \WP_Error('image', 'Immagine assente o oltre il limite di 8 MiB.');
        }
        if (substr($bytes, 0, 3) !== "\xff\xd8\xff" && substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return new \WP_Error('image', 'Il link non restituisce una foto JPEG o PNG.');
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'] ?? '', array('image/jpeg', 'image/png'), true) ||
            $info[0] < 1 || $info[1] < 1 || $info[0] + $info[1] > 10000 || max($info[0], $info[1]) / min($info[0], $info[1]) > 20) {
            return new \WP_Error('image', 'Immagine non valida o dimensioni incompatibili con Telegram.');
        }
        return array('bytes' => $bytes, 'mime' => $info['mime'], 'ext' => $info['mime'] === 'image/png' ? 'png' : 'jpg');
    }
    public static function fetch($s) {
        if (!self::valid_url($s['webcam_url'])) { return new \WP_Error('url', 'URL webcam non valido: usa un indirizzo HTTPS pubblico.'); }
        $url = $s['webcam_cache_bust'] ? add_query_arg('_cmt', time(), $s['webcam_url']) : $s['webcam_url'];
        $r = wp_safe_remote_get($url, array('timeout' => 25, 'redirection' => 3,
            'limit_response_size' => self::MAX_BYTES + 1, 'headers' => array('Accept' => 'image/jpeg,image/png',
                'Cache-Control' => 'no-cache, no-store', 'Pragma' => 'no-cache')));
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) {
            return new \WP_Error('webcam', 'Webcam non raggiungibile. Controlla il link e l’accesso dall’hosting.');
        }
        $modified = wp_remote_retrieve_header($r, 'last-modified');
        $modified_ts = is_string($modified) && $modified !== '' ? strtotime($modified) : false;
        if ((int) $s['webcam_max_age'] > 0 && $modified_ts !== false &&
            (time() - $modified_ts > (int) $s['webcam_max_age'] * 60 || $modified_ts > time() + 300)) {
            return new \WP_Error('stale', 'Foto webcam scaduta secondo Last-Modified: invio saltato.');
        }
        return self::image(wp_remote_retrieve_body($r));
    }
    public static function caption($s, $now) {
        $text = Telegram::in_site_locale(static function () use ($s, $now) {
            return strtr($s['webcam_caption'], array('{data}' => Telegram::date_text($now, $s), '{ora}' => Telegram::time_text($now, $s), '{sito}' => home_url('/')));
        });
        if (strlen($text) > 1024) { return new \WP_Error('caption', 'Didascalia troppo lunga dopo la sostituzione dei segnaposto. Accorciala e salva.'); }
        return $text;
    }
    public static function multipart($channel, $caption, $image, $boundary) {
        $body = '';
        foreach (array('chat_id' => $channel, 'caption' => $caption) as $key => $value) {
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $key . "\"\r\n\r\n" . $value . "\r\n";
        }
        $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"webcam." . $image['ext'] . "\"\r\nContent-Type: " . $image['mime'] . "\r\n\r\n" . $image['bytes'] . "\r\n--" . $boundary . "--\r\n";
        return $body;
    }
    public static function publish($s, $slot) {
        if (!$s['token']) { self::status('Salva prima il token Telegram.'); return; }
        if (!Telegram::lock()) { return; }
        try {
            $scope = md5($s['channel'] . '|' . $s['webcam_url']);
            $slot_key = 'cmt_webcam_slot_' . $scope; $hash_key = 'cmt_webcam_hash_' . $scope;
            if ($slot !== null && (int) get_option($slot_key, 0) >= $slot) { return; }
            $caption = self::caption($s, time());
            if (is_wp_error($caption)) { self::status($caption->get_error_message()); return; }
            $image = self::fetch($s);
            if (is_wp_error($image)) { self::status($image->get_error_message()); return; }
            $hash = hash('sha256', $image['bytes']);
            if ($slot !== null && $s['webcam_skip_same'] && get_option($hash_key, '') === $hash) {
                update_option($slot_key, $slot, false);
                self::status('Foto identica all’ultima inviata: pubblicazione saltata.'); return;
            }
            // Upload new bytes every time. A cached Telegram file_id or URL is never reused.
            $boundary = 'CMT' . bin2hex(random_bytes(16));
            $body = self::multipart($s['channel'], $caption, $image, $boundary);
            if ($slot !== null) { update_option($slot_key, $slot, false); }
            $r = wp_remote_post('https://api.telegram.org/bot' . $s['token'] . '/sendPhoto', array('timeout' => 35,
                'redirection' => 0, 'headers' => array('Content-Type' => 'multipart/form-data; boundary=' . $boundary), 'body' => $body));
            if (is_wp_error($r)) { self::status('Errore di rete Telegram: esito incerto. Controlla il canale prima di riprovare. Nessun ritentativo automatico per questo orario.'); return; }
            $response = json_decode(wp_remote_retrieve_body($r), true);
            if (wp_remote_retrieve_response_code($r) !== 200 || ($response['ok'] ?? false) !== true) {
                self::status('Invio webcam rifiutato da Telegram (codice ' . (int) ($response['error_code'] ?? wp_remote_retrieve_response_code($r)) . '). Controlla permessi e formato. Nessun ritentativo per questo orario.'); return;
            }
            update_option($hash_key, $hash, false);
            self::status('Foto webcam ' . ($slot === null ? 'di prova ' : '') . 'inviata a ' . $s['channel'] . '.');
        } finally { delete_option('cmt_telegram_lock'); }
    }
    public static function fields($s) {
        ?>
        <h2><?php echo esc_html(__("Webcam programmabile", 'ws40-broadcaster')); ?></h2>
        <p><?php echo esc_html(__("Invia una foto come messaggio separato. Gli orari sono indipendenti da bollettini e previsioni, nel fuso orario della stazione.", 'ws40-broadcaster')); ?></p>
        <table class="form-table">
        <tr><th><?php echo esc_html(__("Invio webcam", 'ws40-broadcaster')); ?></th><td><label><input type="checkbox" name="webcam_enabled" value="1" <?php checked($s['webcam_enabled']); ?>> <?php echo esc_html(__("Attiva le immagini programmate", 'ws40-broadcaster')); ?></label></td></tr>
        <tr><th><label for="cmt-wurl"><?php echo esc_html(__("URL immagine", 'ws40-broadcaster')); ?></label></th><td><input id="cmt-wurl" type="url" name="webcam_url" class="large-text" value="<?php echo esc_attr($s['webcam_url']); ?>"><p><?php echo esc_html(__("Indirizzo HTTPS pubblico di un’immagine JPEG o PNG (massimo 8 MiB).", 'ws40-broadcaster')); ?></p></td></tr>
        <tr><th><label for="cmt-whours"><?php echo esc_html(__("Orari webcam", 'ws40-broadcaster')); ?></label></th><td><input id="cmt-whours" name="webcam_hours" class="large-text" value="<?php echo esc_attr(implode(', ', $s['webcam_hours'])); ?>" required><p><?php echo esc_html(__("HH:MM separati da virgole. Puoi cambiare frequenza, fasce e orari (massimo 24 invii al giorno).", 'ws40-broadcaster')); ?></p></td></tr>
        <tr><th><label for="cmt-wcaption"><?php echo esc_html(__("Didascalia", 'ws40-broadcaster')); ?></label></th><td><textarea id="cmt-wcaption" name="webcam_caption" class="large-text" rows="4"><?php echo esc_textarea($s['webcam_caption']); ?></textarea><p>Testo semplice. Segnaposto: {data}, {ora}, {sito}. Data e ora indicano il download, non necessariamente lo scatto. Massimo 800 byte prima e 1024 byte dopo le sostituzioni.</p></td></tr>
        <tr><th><?php echo esc_html(__("Aggiornamento immagine", 'ws40-broadcaster')); ?></th><td><label><input type="checkbox" name="webcam_cache_bust" value="1" <?php checked($s['webcam_cache_bust']); ?>> <?php echo esc_html(__("Aggiungi un parametro variabile al link per evitare copie in cache", 'ws40-broadcaster')); ?></label><p><label><input type="checkbox" name="webcam_skip_same" value="1" <?php checked($s['webcam_skip_same']); ?>> <?php echo esc_html(__("Salta le foto identiche all’ultima inviata", 'ws40-broadcaster')); ?></label></p><label><?php echo esc_html(__("Età massima dichiarata dal server (minuti):", 'ws40-broadcaster')); ?> <input type="number" name="webcam_max_age" min="0" max="1440" value="<?php echo esc_attr($s['webcam_max_age']); ?>"></label><p><?php echo esc_html(__("0 disabilita questo controllo. Si applica solo se la sorgente fornisce Last-Modified. Senza quel dato non è possibile certificare l’ora dello scatto.", 'ws40-broadcaster')); ?></p></td></tr>
        </table>
        <?php
    }
    public static function test_form() {
        ?>
        <hr><h2><?php echo esc_html(__("Prova webcam", 'ws40-broadcaster')); ?></h2>
        <p><strong><?php echo esc_html(__("Stato webcam:", 'ws40-broadcaster')); ?></strong> <?php echo esc_html(get_option('cmt_webcam_status', 'Nessun invio eseguito.')); ?></p>
        <p><?php echo esc_html(__("Salva prima le impostazioni. Il pulsante pubblica subito una foto reale nel canale, anche se identica all’ultima inviata.", 'ws40-broadcaster')); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="cmt_webcam_test"><?php wp_nonce_field('cmt_webcam_test'); ?>
        <?php submit_button('Invia foto webcam di prova nel canale', 'secondary'); ?></form>
        <?php
    }
}

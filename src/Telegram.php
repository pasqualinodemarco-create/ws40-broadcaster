<?php
namespace WS40\Broadcaster;
if (!defined('ABSPATH')) { exit; }
final class Telegram {
    const OPT = 'cmt_telegram_settings';
    const HOOK = 'cmt_telegram_tick';

    public static function defaults() {
        return array('token' => '', 'channel' => '', 'topic' => 0, 'enabled' => false,
            'station_id' => 1,
            'hours' => array('07:00', '10:00', '13:00', '16:00', '19:00', '22:00'),
            'forecast_enabled' => false, 'forecast_hour' => '07:00', 'forecast_lat' => 0, 'forecast_lon' => 0, 'forecast_key' => '',
            'webcam_enabled' => false, 'webcam_url' => '',
            'webcam_hours' => array('07:00', '10:00', '13:00', '16:00', '19:00', '22:00'),
            'webcam_caption' => __("📷 Webcam della stazione\nScaricata il {data} alle {ora}\n{sito}", 'ws40-broadcaster'),
            'webcam_cache_bust' => true, 'webcam_skip_same' => true, 'webcam_max_age' => 60);
    }
    public static function settings() {
        return array_merge(self::defaults(), (array) get_option(self::OPT, array()));
    }
    /** WS40 station row (cached per request); empty when WS40 is missing. */
    public static function station_row($id) {
        static $cache = array();
        $id = (int) $id;
        if (isset($cache[$id])) { return $cache[$id]; }
        $repo = 'WS40\Domain\Stations\StationRepository';
        $row = array();
        if ($id > 0 && class_exists($repo) && method_exists($repo, 'getRow')) {
            try { $row = (array) $repo::getRow($id); } catch (\Throwable $e) { $row = array(); }
        }
        return $cache[$id] = $row;
    }
    /** Time zone of the selected WS40 station; the site time zone when it has none. */
    public static function tz($s = null) {
        $s = is_array($s) ? $s : self::settings();
        $name = (string) (self::station_row($s['station_id'])['tz'] ?? '');
        if ($name !== '') {
            try { return new \DateTimeZone($name); } catch (\Exception $e) { /* fall back */ }
        }
        return wp_timezone();
    }
    /** Unit system and symbols of the selected WS40 station (same rules as WS40). */
    public static function units($s = null) {
        $s = is_array($s) ? $s : self::settings();
        $id = (int) $s['station_id'];
        $imperial = (self::station_row($id)['unit_system'] ?? 'metric') === 'imperial';
        $wind = $imperial ? 'mph' : 'km/h';
        $conv = 'WS40\Application\Units\UnitConverter';
        if (class_exists($conv) && method_exists($conv, 'windSymbol')) {
            try { $wind = (string) $conv::windSymbol($imperial ? 'imperial' : 'metric', $id); } catch (\Throwable $e) { /* keep default */ }
        }
        return array('system' => $imperial ? 'imperial' : 'metric', 'temp' => $imperial ? '°F' : '°C',
            'press' => $imperial ? 'inHg' : 'hPa', 'wind' => $wind, 'rain' => $imperial ? 'in' : 'mm');
    }
    /** Builds channel text in the site language, also when an admin with another language presses a test button. */
    public static function in_site_locale($callback) {
        $switched = function_exists('switch_to_locale') && switch_to_locale(get_locale());
        try { return $callback(); } finally { if ($switched) { restore_previous_locale(); } }
    }
    /** Date and time of a timestamp, in the station time zone and the formats chosen by the translation. */
    public static function date_text($ts, $s = null) {
        return wp_date(_x('d/m/Y', 'date format of the Telegram messages', 'ws40-broadcaster'), (int) $ts, self::tz($s));
    }
    public static function time_text($ts, $s = null) {
        return wp_date(_x('H:i', 'time format of the Telegram messages', 'ws40-broadcaster'), (int) $ts, self::tz($s));
    }
    /** Telegram destination: chat_id plus message_thread_id when a forum topic is set. */
    public static function target($s) {
        $t = array('chat_id' => $s['channel']);
        if ((int) ($s['topic'] ?? 0) > 0) { $t['message_thread_id'] = (int) $s['topic']; }
        return $t;
    }
    /** Destination for de-duplication keys and status lines; without a topic it is the channel alone, as in 1.3.1. */
    public static function scope_name($s) {
        return (int) ($s['topic'] ?? 0) > 0 ? $s['channel'] . '#' . (int) $s['topic'] : $s['channel'];
    }
    public static function boot() {
        add_action('init', array(__CLASS__, 'load_i18n'), 1);
        add_filter('cron_schedules', array(__CLASS__, 'interval'));
        add_action('init', array(__CLASS__, 'ensure_cron'));
        add_action(self::HOOK, array(__CLASS__, 'tick'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_cmt_save', array(__CLASS__, 'save'));
        add_action('admin_post_cmt_test', array(__CLASS__, 'test_send'));
        add_action('admin_post_cmt_api_test', array(__CLASS__, 'test_api'));
    }
    public static function load_i18n() { load_plugin_textdomain('ws40-broadcaster', false, dirname(plugin_basename(WS40_BROADCASTER_FILE)) . '/languages'); }
    public static function interval($schedules) {
        $schedules['cmt_minute'] = array('interval' => 60, 'display' => __('Ogni minuto (WS40 Broadcaster)', 'ws40-broadcaster'));
        return $schedules;
    }
    public static function ensure_cron() {
        if (!wp_next_scheduled(self::HOOK)) { wp_schedule_event(time() + 60, 'cmt_minute', self::HOOK); }
    }
    public static function deactivate() { wp_clear_scheduled_hook(self::HOOK); }
    public static function menu() {
        add_menu_page('WS40 Broadcaster', 'WS40 Broadcaster', 'manage_options', 'ws40-broadcaster', array(__CLASS__, 'page'), 'dashicons-megaphone', 57);
        add_submenu_page('ws40-broadcaster', 'Impostazioni — WS40 Broadcaster', 'Impostazioni', 'manage_options', 'ws40-broadcaster', array(__CLASS__, 'page'));
        Guide::menu();
        Banner::menu();
    }
    public static function guard($action) {
        if (!current_user_can('manage_options')) { wp_die('Accesso non consentito.'); }
        check_admin_referer($action);
    }
    public static function notice($type, $message) {
        $allowed = array('success', 'error', 'warning', 'info');
        $type = in_array($type, $allowed, true) ? $type : 'info';
        set_transient('ws40_broadcaster_action_notice_' . get_current_user_id(),
            array('type' => $type, 'message' => sanitize_text_field($message)), 120);
    }
    public static function notice_from_status($key, $before, $label) {
        $after = (string) get_option($key, '');
        if ($after === '' || $after === $before) {
            self::notice('warning', $label . ': nessun nuovo risultato. Verifica la configurazione o un invio già in corso.');
            return;
        }
        $lower = strtolower($after);
        $errors = array('errore', 'fallit', 'rifiutat', 'non salvate', 'non valido', 'inserisci', 'salva prima', 'non disponibile', 'impossibile');
        $warnings = array('saltat', 'blocc', 'identica', 'in corso', 'esito incerto');
        foreach ($warnings as $word) { if (strpos($lower, $word) !== false) { self::notice('warning', $label . ': ' . $after); return; } }
        foreach ($errors as $word) { if (strpos($lower, $word) !== false) { self::notice('error', $label . ': ' . $after); return; } }
        self::notice('success', $label . ': ' . $after);
    }
    public static function render_notice() {
        $key = 'ws40_broadcaster_action_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice)) { return; }
        delete_transient($key);
        $type = in_array($notice['type'] ?? '', array('success', 'error', 'warning', 'info'), true) ? $notice['type'] : 'info';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible" role="status"><p>' . esc_html($notice['message'] ?? '') . '</p></div>';
    }
    public static function redirect() {
        wp_safe_redirect(admin_url('admin.php?page=ws40-broadcaster')); exit;
    }
    public static function status($message) {
        update_option('cmt_telegram_status', wp_date('d/m/Y H:i:s', time(), self::tz()) . ' — ' . $message, false);
    }
    public static function parse_hours($text) {
        $hours = array_values(array_unique(array_map('trim', explode(',', $text))));
        if (count($hours) < 1 || count($hours) > 24) { return false; }
        foreach ($hours as $hour) {
            if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $hour)) { return false; }
        }
        sort($hours); return $hours;
    }
    public static function save() {
        self::guard('cmt_save');
        $s = self::settings();
        $token = trim(sanitize_text_field(wp_unslash($_POST['token'] ?? '')));
        if ($token !== '' && !preg_match('/^[0-9]+:[A-Za-z0-9_-]{20,}$/D', $token)) {
            self::status('Impostazioni non salvate: formato token non valido.'); self::notice('error', get_option('cmt_telegram_status')); self::redirect();
        }
        if ($token !== '') { $s['token'] = $token; }
        if (!empty($_POST['remove_token'])) { $s['token'] = ''; }
        $channel = trim(sanitize_text_field(wp_unslash($_POST['channel'] ?? '')));
        $hours = self::parse_hours(sanitize_text_field(wp_unslash($_POST['hours'] ?? '')));
        if (!preg_match('/^(?:@[A-Za-z][A-Za-z0-9_]{4,31}|-100[0-9]+)$/D', $channel) || $hours === false) {
            self::status('Impostazioni non salvate: controlla canale e orari (HH:MM separati da virgole).'); self::notice('error', get_option('cmt_telegram_status')); self::redirect();
        }
        $topic = trim(sanitize_text_field(wp_unslash($_POST['topic'] ?? '')));
        if ($topic !== '' && !preg_match('/^[1-9][0-9]{0,9}$/D', $topic)) {
            self::status('Impostazioni non salvate: l’ID argomento deve essere un numero intero, oppure vuoto.'); self::notice('error', get_option('cmt_telegram_status')); self::redirect();
        }
        $s['topic'] = (int) $topic;
        $choices = self::stations();
        $station_id = filter_var(wp_unslash($_POST['station_id'] ?? $s['station_id']), FILTER_VALIDATE_INT);
        if ($station_id === false || !isset($choices[(int) $station_id])) {
            self::status('Impostazioni non salvate: seleziona una stazione WS40 attiva.'); self::notice('error', get_option('cmt_telegram_status')); self::redirect();
        }
        $s['station_id'] = $station_id;
        $forecast_hours = self::parse_hours(sanitize_text_field(wp_unslash($_POST['forecast_hour'] ?? '07:00')));
        $lat = filter_var(wp_unslash($_POST['forecast_lat'] ?? '45.82'), FILTER_VALIDATE_FLOAT);
        $lon = filter_var(wp_unslash($_POST['forecast_lon'] ?? '13.34'), FILTER_VALIDATE_FLOAT);
        if ($forecast_hours === false || count($forecast_hours) !== 1 || $lat === false || $lon === false || abs($lat) > 90 || abs($lon) > 180) {
            self::status('Impostazioni non salvate: controlla orario e coordinate delle previsioni.'); self::notice('error', get_option('cmt_telegram_status')); self::redirect();
        }
        $s['forecast_hour'] = $forecast_hours[0]; $s['forecast_lat'] = $lat; $s['forecast_lon'] = $lon;
        $forecast_key = trim(sanitize_text_field(wp_unslash($_POST['forecast_key'] ?? '')));
        if ($forecast_key !== '') { $s['forecast_key'] = $forecast_key; }
        if (!empty($_POST['remove_forecast_key'])) { $s['forecast_key'] = ''; }
        $s['forecast_enabled'] = !empty($_POST['forecast_enabled']) && $s['token'] !== '';
        $webcam_url = esc_url_raw(wp_unslash($_POST['webcam_url'] ?? $s['webcam_url']));
        $webcam_hours = self::parse_hours(sanitize_text_field(wp_unslash($_POST['webcam_hours'] ?? implode(',', $s['webcam_hours']))));
        $webcam_caption = sanitize_textarea_field(wp_unslash($_POST['webcam_caption'] ?? $s['webcam_caption']));
        $webcam_age = filter_var(wp_unslash($_POST['webcam_max_age'] ?? '60'), FILTER_VALIDATE_INT);
        if (($webcam_url !== '' && !Webcam::valid_url($webcam_url)) || ($webcam_url === '' && !empty($_POST['webcam_enabled'])) || $webcam_hours === false || strlen($webcam_caption) > 800 ||
            $webcam_age === false || $webcam_age < 0 || $webcam_age > 1440) {
            self::status('Impostazioni non salvate: controlla URL pubblico HTTPS, orari, didascalia (massimo 800 byte) ed età massima webcam.'); self::notice('error', get_option('cmt_telegram_status')); self::redirect();
        }
        $s['webcam_url'] = $webcam_url; $s['webcam_hours'] = $webcam_hours; $s['webcam_caption'] = $webcam_caption;
        $s['webcam_max_age'] = $webcam_age; $s['webcam_enabled'] = !empty($_POST['webcam_enabled']) && $s['token'] !== '';
        $s['webcam_cache_bust'] = !empty($_POST['webcam_cache_bust']); $s['webcam_skip_same'] = !empty($_POST['webcam_skip_same']);
        $s['channel'] = $channel; $s['hours'] = $hours;
        $s['enabled'] = !empty($_POST['enabled']) && $s['token'] !== '';
        update_option(self::OPT, $s, false);
        self::status('Impostazioni salvate. Bollettini: ' . ($s['enabled'] ? 'attivi' : 'disattivati') . '; previsioni: ' . ($s['forecast_enabled'] ? 'attive' : 'disattivate') . '; webcam: ' . ($s['webcam_enabled'] ? 'attiva.' : 'disattivata.'));
        self::notice('success', get_option('cmt_telegram_status'));
        self::redirect();
    }
    // Only the latest due slot is eligible. Missed slots older than 15 minutes are skipped.
    public static function slot($now, $hours, $s = null) {
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(self::tz($s));
        $candidate = null;
        foreach ($hours as $hour) {
            list($h, $m) = array_map('intval', explode(':', $hour));
            $due = $local->setTime($h, $m)->getTimestamp();
            if ($due <= $now && $now - $due <= 900 && ($candidate === null || $due > $candidate)) { $candidate = $due; }
        }
        return $candidate;
    }
    public static function tick() {
        $s = self::settings();
        if (!$s['enabled'] || !$s['token']) { return; }
        $slot = self::slot(time(), $s['hours'], $s);
        if ($slot !== null) { self::publish($s, $slot); }
    }
    public static function test_send() {
        self::guard('cmt_test');
        $before = (string) get_option('cmt_telegram_status', '');
        self::publish(self::settings(), null);
        self::notice_from_status('cmt_telegram_status', $before, 'Bollettino stazione');
        self::redirect();
    }
    public static function stations() {
        $repo = 'WS40\Domain\Stations\StationRepository';
        // WS40 uses an autoloader: do not load its files directly.
        if (!class_exists($repo)) { return array(); }
        try { $rows = $repo::getChoices(); } catch (\Throwable $e) { return array(); }
        $choices = array();
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && !empty($row['is_active'])) { $choices[$id] = (string) ($row['station_name'] ?: 'Stazione ' . $id); }
        }
        return $choices;
    }
    public static function station_name($id) {
        $choices = self::stations();
        return $choices[(int) $id] ?? ('Stazione ' . (int) $id);
    }
    public static function validate($payload, $now, $station_id = 1) {
        if (!is_array($payload) || empty($payload['ok']) || (int) ($payload['station_id'] ?? 0) !== (int) $station_id || !is_array($payload['data'] ?? null)) {
            return new \WP_Error('schema', 'Formato dati WS40 non valido o ID stazione differente.');
        }
        $ts = $payload['logged_ts'] ?? null;
        if (!is_numeric($ts) || (int) $ts <= 0) {
            return new \WP_Error('timestamp', 'WS40 non ha fornito una data valida per l’ultima rilevazione.');
        }
        $age = $now - (int) $ts;
        if ($age > 1800 || $age < -300) {
            return new \WP_Error('stale', 'Ultima rilevazione non recente: ' . (int) round($age / 60) . ' minuti fa (limite 30).');
        }
        if (!is_numeric($payload['data']['outTemp'] ?? null)) {
            return new \WP_Error('temperature', 'Temperatura esterna assente nel JSON WS40.');
        }
        // WS40 supports both metric and imperial station settings.
        // Keep the original unit system and render the correct temperature symbol.
        return $payload;
    }
    public static function fetch($settings = null) {
        $s = is_array($settings) ? $settings : self::settings();
        $choices = self::stations();
        $id = (int) $s['station_id'];
        if (!isset($choices[$id])) { return new \WP_Error('ws40_missing', 'WS40 non disponibile oppure stazione non attiva.'); }
        // Dispatch locally: no external HTTP request or public endpoint required.
        $request = new \WP_REST_Request('GET', '/ws40/v1/current');
        $request->set_param('station_id', $id);
        $response = rest_do_request($request);
        if (is_wp_error($response)) { return $response; }
        if ($response->is_error()) { return new \WP_Error('ws40_api', 'WS40 ha restituito HTTP ' . $response->get_status() . ': ' . sanitize_text_field((string) ($response->get_data()['error'] ?? $response->get_data()['code'] ?? 'nessun dettaglio'))); }
        return self::validate($response->get_data(), time(), $id);
    }
    public static function test_api() {
        self::guard('cmt_api_test');
        $p = self::fetch(self::settings());
        if (is_wp_error($p)) {
            update_option('ws40_broadcaster_api_status', 'Verifica fallita: ' . $p->get_error_message(), false);
        } else {
            update_option('ws40_broadcaster_api_status', 'API OK: stazione ' . (int) $p['station_id'] . ', rilevazione ' .
                wp_date('d/m/Y H:i:s', (int) $p['logged_ts'], self::tz()) .
                ', temperatura ' . self::number($p['data'], 'outTemp') . ' ' . (string) ($p['meta']['units']['temp'] ?? self::units()['temp']) . '.');
        }
        self::notice(is_wp_error($p) ? 'error' : 'success', get_option('ws40_broadcaster_api_status'));
        self::redirect();
    }
    public static function number($data, $key, $decimals = 1) {
        if (!isset($data[$key]) || !is_numeric($data[$key]) || !is_finite((float) $data[$key])) { return '—'; }
        // Decimal point of the language, no thousands separator (1021,9 hPa, not 1.021,9).
        global $wp_locale;
        $point = ($wp_locale instanceof \WP_Locale) ? (string) $wp_locale->number_format['decimal_point'] : '.';
        return number_format((float) $data[$key], $decimals, $point, '');
    }
    public static function message($p, $s = null) {
        $s = is_array($s) ? $s : self::settings();
        $d = $p['data'];
        $u = array_merge(self::units($s), array_filter((array) ($p['meta']['units'] ?? array()), 'is_string'));
        $imperial = ($p['meta']['unit_system'] ?? self::units($s)['system']) === 'imperial';
        $dir = isset($d['windDirTxt']) && is_string($d['windDirTxt']) ? htmlspecialchars($d['windDirTxt'], ENT_QUOTES, 'UTF-8') : '';
        $line = static function ($icon, $label, $value) { return $icon . ' ' . $label . ': <b>' . $value . '</b>'; };
        $lines = array('📍 <b>' . esc_html(self::station_name($p['station_id'])) . '</b>',
            '📅 ' . sprintf(__('%1$s • ore %2$s', 'ws40-broadcaster'), self::date_text($p['logged_ts'], $s), self::time_text($p['logged_ts'], $s)), '',
            $line('🌡', __('Temperatura', 'ws40-broadcaster'), self::number($d, 'outTemp') . ' ' . $u['temp']),
            $line('💧', __('Umidità', 'ws40-broadcaster'), self::number($d, 'outHumidity', 0) . '%'),
            $line('🌬', __('Vento', 'ws40-broadcaster'), self::number($d, 'windSpeed') . ' ' . $u['wind']) . ($dir !== '' ? ' • ' . $dir : ''),
            $line('💨', __('Raffica attuale', 'ws40-broadcaster'), self::number($d, 'windGust') . ' ' . $u['wind']),
            $line('☔', __('Pioggia giornaliera', 'ws40-broadcaster'), self::number($d, 'rainDay', $imperial ? 2 : 1) . ' ' . $u['rain']),
            $line('🧭', __('Pressione', 'ws40-broadcaster'), self::number($d, 'pressure', $imperial ? 2 : 1) . ' ' . $u['press']),
            $line('🌡', __('Minima / massima del giorno', 'ws40-broadcaster'), self::number($d, 'tempMin') . ' / ' . self::number($d, 'tempMax') . ' ' . $u['temp']), '',
            sprintf(__('Dati osservati • %s', 'ws40-broadcaster'), esc_html(get_bloginfo('name'))));
        return implode("\n", $lines);
    }
    // Atomic database lock shared by cron and manual sends; expired claims are removed conditionally.
    public static function lock() {
        global $wpdb;
        $name = 'cmt_telegram_lock'; $old = get_option($name);
        if ($old && (int) $old < time() - 120) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $old));
            wp_cache_delete($name, 'options'); wp_cache_delete('notoptions', 'options');
        }
        return add_option($name, (string) time(), '', false);
    }
    public static function publish($s, $slot) {
        if (!$s['token']) { self::status('Inserisci e salva il token prima di inviare.'); return; }
        if (!self::lock()) { self::status('Invio non avviato: un altro invio è in corso (blocco temporaneo).'); return; }
        try {
            $scope = md5(self::scope_name($s));
            $last_slot = (int) get_option('cmt_slot_' . $scope, 0);
            if ($slot !== null && $last_slot >= $slot) { return; }
            $p = self::fetch($s);
            if (is_wp_error($p)) { self::status($p->get_error_message()); return; }
            $last_sample = (int) get_option('cmt_sample_' . $scope, 0);
            if ($slot !== null && $last_sample >= (int) $p['logged_ts']) {
                update_option('cmt_slot_' . $scope, $slot, false);
                self::status('Invio saltato: rilevazione già pubblicata.'); return;
            }
            // Claim BEFORE sending. Never blindly retry a possibly delivered HTTP timeout.
            if ($slot !== null) { update_option('cmt_slot_' . $scope, $slot, false); }
            $r = wp_remote_post('https://api.telegram.org/bot' . $s['token'] . '/sendMessage', array(
                'timeout' => 20, 'redirection' => 0, 'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode(self::target($s) + array('text' => self::in_site_locale(static function () use ($p, $s) { return self::message($p, $s); }),
                    'parse_mode' => 'HTML', 'link_preview_options' => array('is_disabled' => true)))));
            if (is_wp_error($r)) {
                self::status('Errore di rete Telegram: esito incerto. Controlla il canale prima di riprovare. Nessun ritentativo automatico per questo orario.'); return;
            }
            $body = json_decode(wp_remote_retrieve_body($r), true);
            if (wp_remote_retrieve_response_code($r) !== 200 || ($body['ok'] ?? false) !== true) {
                $code = (int) ($body['error_code'] ?? wp_remote_retrieve_response_code($r));
                self::status('Telegram ha rifiutato l’invio (codice ' . $code . '): ' . sanitize_text_field(substr((string) ($body['description'] ?? 'nessun dettaglio'), 0, 180)) . '. Nessun ritentativo automatico per questo orario.'); return;
            }
            update_option('cmt_sample_' . $scope, (int) $p['logged_ts'], false);
            self::status(($slot === null ? 'Prova' : 'Bollettino') . ' inviato a ' . self::scope_name($s) . '.');
        } finally { delete_option('cmt_telegram_lock'); }
    }
    public static function admin_assets($hook) {
        if (strpos((string) $hook, 'ws40-broadcaster') === false) { return; }
        wp_enqueue_style('ws40b-admin', plugins_url('assets/admin.css', WS40_BROADCASTER_FILE), array(), '1.3.2');
    }
    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $s = self::settings();
        ?>
        <div class="wrap ws40b-admin">
<div class="ws40b-hero"><div class="ws40b-brand"><div class="ws40b-logo"><img src="<?php echo esc_url(plugins_url('assets/ws40-logo.svg', WS40_BROADCASTER_FILE)); ?>" alt="WeatherStation40"></div><div><h1><?php echo esc_html(__("WS40 Broadcaster", 'ws40-broadcaster')); ?></h1><p class="ws40b-tagline"><?php echo esc_html(__("Pubblicazione automatica di bollettini, previsioni e webcam", 'ws40-broadcaster')); ?></p></div></div><span class="ws40b-badge"><?php echo esc_html(__("Integrato con WS40", 'ws40-broadcaster')); ?></span></div>
<p style="margin-top:4px;color:#646970"><strong><?php echo esc_html(__("Versione 1.3.2", 'ws40-broadcaster')); ?></strong> · <a href="https://www.weatherstation40.live/" target="_blank" rel="noopener noreferrer"><?php echo esc_html(__("Community WeatherStation40 ↗", 'ws40-broadcaster')); ?></a></p>
        <?php self::render_notice(); Guide::tabs('settings'); ?>
        <div class="ws40b-panel ws40b-overview"><h2><?php echo esc_html(__("Panoramica del servizio", 'ws40-broadcaster')); ?></h2>
        <p><?php echo esc_html(sprintf(__('Stazioni rilevate da WeatherStation40 • Orari, date e unità della stazione selezionata (fuso orario %s).', 'ws40-broadcaster'), self::tz($s)->getName())); ?></p>
        <p><strong><?php echo esc_html(__("Stato invio Telegram:", 'ws40-broadcaster')); ?></strong> <?php echo esc_html(get_option('cmt_telegram_status', 'Nessun invio eseguito.')); ?></p>
<p><strong><?php echo esc_html(__("Stato lettura stazione:", 'ws40-broadcaster')); ?></strong> <?php echo esc_html(get_option('ws40_broadcaster_api_status', 'Verifica non ancora eseguita.')); ?></p>
<p class="description"><?php echo esc_html(__("La verifica API controlla solo la lettura. Per verificare la pubblicazione usa «Invia bollettino di prova nel canale».", 'ws40-broadcaster')); ?></p>
        </div><div class="ws40b-panel ws40b-settings"><h2><?php echo esc_html(__("Configurazione canale e stazione", 'ws40-broadcaster')); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="cmt_save"><?php wp_nonce_field('cmt_save'); ?>
        <table class="form-table">
        <tr><th><label for="cmt-station-id"><?php echo esc_html(__("Stazione WS40", 'ws40-broadcaster')); ?></label></th><td>
        <?php $stations = self::stations(); if (!$stations): ?>
        <div class="notice notice-warning inline"><p><?php echo esc_html(__("WS40 non è attivo, non è disponibile o non contiene stazioni abilitate. Attiva WS40 e configura una stazione prima di utilizzare Broadcaster.", 'ws40-broadcaster')); ?></p></div>
        <?php else: ?>
        <select id="cmt-station-id" name="station_id" required>
        <?php foreach ($stations as $id => $name): ?><option value="<?php echo esc_attr($id); ?>" <?php selected((int)$s['station_id'], (int)$id); ?>><?php echo esc_html($name . ' (ID ' . $id . ')'); ?></option><?php endforeach; ?>
        </select><p><?php echo esc_html(__("Elenco letto direttamente da WS40. Non serve inserire alcuna API.", 'ws40-broadcaster')); ?></p>
        <?php endif; ?></td></tr>
        <tr><th><label for="cmt-token"><?php echo esc_html(__("Token BotFather", 'ws40-broadcaster')); ?></label></th><td>
        <input id="cmt-token" type="password" name="token" value="<?php echo esc_attr($s['token']); ?>" class="regular-text" autocomplete="off" spellcheck="false">
        <button type="button" class="button ws40-secret-toggle" data-target="cmt-token" aria-pressed="false" aria-label="Mostra token Telegram"><?php echo esc_html(__("Mostra", 'ws40-broadcaster')); ?></button>
        <p><?php echo $s['token'] ? 'Token salvato. Puoi visualizzarlo, modificarlo oppure rimuoverlo.' : 'Token non ancora configurato.'; ?></p>
        <label><input type="checkbox" name="remove_token" value="1"> <?php echo esc_html(__("Rimuovi il token salvato", 'ws40-broadcaster')); ?></label></td></tr>
        <tr><th><label for="cmt-channel"><?php echo esc_html(__("Canale", 'ws40-broadcaster')); ?></label></th><td><input id="cmt-channel" name="channel" class="regular-text" value="<?php echo esc_attr($s['channel']); ?>"></td></tr>
        <tr><th><label for="cmt-topic"><?php echo esc_html(__("ID argomento (topic)", 'ws40-broadcaster')); ?></label></th><td><input id="cmt-topic" type="number" name="topic" min="1" step="1" class="small-text" value="<?php echo (int) $s['topic'] > 0 ? (int) $s['topic'] : ''; ?>"><p><?php echo esc_html(__("Facoltativo, solo per i gruppi con argomenti. È il numero centrale nel link di un messaggio dell’argomento (t.me/c/…/NUMERO/… oppure t.me/gruppo/NUMERO/…). Vuoto: canale o argomento # General.", 'ws40-broadcaster')); ?></p></td></tr>
        <tr><th><label for="cmt-hours"><?php echo esc_html(__("Orari", 'ws40-broadcaster')); ?></label></th><td><input id="cmt-hours" name="hours" class="large-text" value="<?php echo esc_attr(implode(', ', $s['hours'])); ?>"><p><?php echo esc_html(__("Formato HH:MM, separati da virgole. Massimo 24 orari al giorno.", 'ws40-broadcaster')); ?></p></td></tr>
        <tr><th><?php echo esc_html(__("Invio automatico", 'ws40-broadcaster')); ?></th><td><label><input type="checkbox" name="enabled" value="1" <?php checked($s['enabled']); ?>> <?php echo esc_html(__("Attiva i bollettini programmati", 'ws40-broadcaster')); ?></label></td></tr></table>
        <h2><?php echo esc_html(__("Previsioni giornaliere — Open-Meteo", 'ws40-broadcaster')); ?></h2>
        <p><?php echo esc_html(__("Un messaggio separato con le previsioni di oggi, nel fuso orario e nelle unità della stazione. Configura le coordinate della località per le previsioni.", 'ws40-broadcaster')); ?></p>
        <table class="form-table">
        <tr><th><?php echo esc_html(__("Invio previsioni", 'ws40-broadcaster')); ?></th><td><label><input type="checkbox" name="forecast_enabled" value="1" <?php checked($s['forecast_enabled']); ?>> <?php echo esc_html(__("Attiva le previsioni una volta al giorno", 'ws40-broadcaster')); ?></label></td></tr>
        <tr><th><label for="cmt-fhour"><?php echo esc_html(__("Orario previsioni", 'ws40-broadcaster')); ?></label></th><td><input id="cmt-fhour" type="time" name="forecast_hour" value="<?php echo esc_attr($s['forecast_hour']); ?>" required></td></tr>
        <tr><th><?php echo esc_html(__("Posizione", 'ws40-broadcaster')); ?></th><td><label><?php echo esc_html(__("Latitudine", 'ws40-broadcaster')); ?> <input type="number" name="forecast_lat" step="any" min="-90" max="90" value="<?php echo esc_attr($s['forecast_lat']); ?>" required></label> <label><?php echo esc_html(__("Longitudine", 'ws40-broadcaster')); ?> <input type="number" name="forecast_lon" step="any" min="-180" max="180" value="<?php echo esc_attr($s['forecast_lon']); ?>" required></label></td></tr>
        <tr><th><label for="cmt-fkey"><?php echo esc_html(__("Chiave Open-Meteo", 'ws40-broadcaster')); ?></label></th><td><input id="cmt-fkey" type="password" name="forecast_key" value="<?php echo esc_attr($s['forecast_key']); ?>" class="regular-text" autocomplete="off" spellcheck="false"><button type="button" class="button ws40-secret-toggle" data-target="cmt-fkey" aria-pressed="false" aria-label="Mostra chiave Open-Meteo"><?php echo esc_html(__("Mostra", 'ws40-broadcaster')); ?></button><p><?php echo $s['forecast_key'] ? 'Chiave salvata: uso API abbonati. Puoi visualizzarla o modificarla.' : 'Nessuna chiave: uso API gratuita per impieghi non commerciali.'; ?></p><label><input type="checkbox" name="remove_forecast_key" value="1"> <?php echo esc_html(__("Rimuovi chiave Open-Meteo", 'ws40-broadcaster')); ?></label><p><?php echo esc_html(__("L’API gratuita è per uso non commerciale; siti con pubblicità o abbonamenti richiedono il piano adatto.", 'ws40-broadcaster')); ?> <a href="https://open-meteo.com/en/terms" target="_blank" rel="noopener noreferrer"><?php echo esc_html(__("Condizioni Open-Meteo", 'ws40-broadcaster')); ?></a>.</p></td></tr>
        </table>
        <?php Webcam::fields($s); submit_button('Salva impostazioni', 'primary ws40b-save'); ?></form></div>
        <script>
        document.addEventListener('click', function(event) {
            var button = event.target.closest('.ws40-secret-toggle');
            if (!button) return;
            var field = document.getElementById(button.getAttribute('data-target'));
            if (!field) return;
            var show = field.type === 'password';
            field.type = show ? 'text' : 'password';
            button.textContent = show ? 'Nascondi' : 'Mostra';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            button.setAttribute('aria-label', (show ? 'Nascondi ' : 'Mostra ') + (field.id === 'cmt-token' ? 'token Telegram' : 'chiave Open-Meteo'));
        });
        </script>
        <?php $api_notice = get_transient('ws40_broadcaster_api_notice_' . get_current_user_id()); if (is_array($api_notice)) : delete_transient('ws40_broadcaster_api_notice_' . get_current_user_id()); ?>
        <div class="notice notice-<?php echo esc_attr($api_notice[0]); ?> is-dismissible"><p><strong><?php echo esc_html(__("Verifica WS40:", 'ws40-broadcaster')); ?></strong> <?php echo esc_html($api_notice[1]); ?></p></div>
        <?php endif; ?>
        <div class="ws40b-panel ws40b-tests"><h2><?php echo esc_html(__("Verifica dati della stazione", 'ws40-broadcaster')); ?></h2>
        <p><strong><?php echo esc_html(__("Ultimo risultato:", 'ws40-broadcaster')); ?></strong> <?php echo esc_html(get_option('ws40_broadcaster_api_status', 'Nessuna verifica eseguita.')); ?></p>
        <p><?php echo esc_html(__("Salva prima le impostazioni, poi verifica che WS40 restituisca dati validi e aggiornati (unità metriche o imperiali). La verifica non invia messaggi Telegram.", 'ws40-broadcaster')); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="cmt_api_test"><?php wp_nonce_field('cmt_api_test'); ?>
        <?php submit_button('Verifica dati della stazione', 'secondary'); ?></form>
        </div><div class="ws40b-panel ws40b-tests"><h2><?php echo esc_html(__("Invio di prova", 'ws40-broadcaster')); ?></h2><p><?php echo esc_html(__("Pubblica subito un bollettino reale nel canale salvato. Salva prima le impostazioni. Funziona anche con l’automatismo disattivato.", 'ws40-broadcaster')); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="cmt_test"><?php wp_nonce_field('cmt_test'); ?>
        <?php submit_button('Invia bollettino di prova nel canale', 'secondary'); ?></form>
        </div><div class="ws40b-panel ws40b-tests"><h2><?php echo esc_html(__("Prova previsioni", 'ws40-broadcaster')); ?></h2>
        <p><strong><?php echo esc_html(__("Stato previsioni:", 'ws40-broadcaster')); ?></strong> <?php echo esc_html(get_option('cmt_forecast_status', 'Nessun invio eseguito.')); ?></p>
        <p><?php echo esc_html(__("Invia subito le previsioni di oggi nel canale salvato. La prova può ripubblicarle; dopo la prova non verranno reinviate automaticamente oggi. Salva prima le impostazioni.", 'ws40-broadcaster')); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="cmt_forecast_test"><?php wp_nonce_field('cmt_forecast_test'); ?>
        <?php submit_button('Invia previsioni di prova nel canale', 'secondary'); ?></form>
        </div><div class="ws40b-panel ws40b-tests"><?php Webcam::test_form(); ?></div><div class="ws40b-panel ws40b-notes"><h2><?php echo esc_html(__("Note operative", 'ws40-broadcaster')); ?></h2>
        <hr><p><?php echo esc_html(__("Il bot deve essere amministratore del canale con il permesso di pubblicare messaggi. Token e chiavi sono conservati nelle opzioni del database WordPress. La visualizzazione è disponibile solo agli amministratori autorizzati; non mostrare le credenziali durante condivisioni dello schermo.", 'ws40-broadcaster')); ?></p>
        <p><?php echo esc_html(__("I dati offline, più vecchi di 30 minuti o privi di temperatura non vengono pubblicati. Le rilevazioni già inviate vengono saltate dagli invii automatici.", 'ws40-broadcaster')); ?></p>
        <p><strong><?php echo esc_html(__("Puntualità:", 'ws40-broadcaster')); ?></strong> <?php echo esc_html(__("WP-Cron dipende dalle visite al sito. Per rispettare gli orari configura il cron dell’hosting ogni minuto (istruzioni nel README). Gli orari passati da oltre 15 minuti vengono saltati.", 'ws40-broadcaster')); ?></p>
        </div></div>
        <?php
    }
}

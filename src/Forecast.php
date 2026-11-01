<?php
namespace WS40\Broadcaster;
if (!defined('ABSPATH')) { exit; }

final class Forecast {
    public static function boot() {
        add_action(Telegram::HOOK, array(__CLASS__, 'tick'), 20);
        add_action('admin_post_cmt_forecast_test', array(__CLASS__, 'test_send'));
    }
    public static function status($message) {
        update_option('cmt_forecast_status', wp_date('d/m/Y H:i:s', time(), Telegram::tz()) . ' — ' . $message, false);
    }
    public static function day($now, $s = null) {
        return (new \DateTimeImmutable('@' . $now))->setTimezone(Telegram::tz($s))->format('Y-m-d');
    }
    public static function tick() {
        $s = Telegram::settings();
        if (!$s['forecast_enabled'] || !$s['token']) { return; }
        if (Telegram::slot(time(), array($s['forecast_hour']), $s) !== null) { self::publish($s); }
    }
    public static function test_send() {
        Telegram::guard('cmt_forecast_test');
        $before = (string) get_option('cmt_forecast_status', '');
        self::publish(Telegram::settings(), true);
        Telegram::notice_from_status('cmt_forecast_status', $before, 'Previsioni');
        Telegram::redirect();
    }
    /** Open-Meteo units matching the station: request value, accepted answer labels, symbol shown. */
    public static function api_units($s) {
        $u = Telegram::units($s);
        $imperial = $u['system'] === 'imperial';
        $wind = $u['wind'] === 'm/s' ? array('ms', array('m/s'), 'm/s') : ($imperial ? array('mph', array('mp/h', 'mph'), 'mph') : array('kmh', array('km/h'), 'km/h'));
        return array(
            'temp' => $imperial ? array('fahrenheit', array('°F'), '°F') : array('celsius', array('°C'), '°C'),
            'wind' => $wind,
            'rain' => $imperial ? array('inch', array('inch', 'in'), 'in') : array('mm', array('mm'), 'mm'),
        );
    }
    /** IANA name for Open-Meteo; "auto" (time zone of the coordinates) when the station only has an offset. */
    public static function api_timezone($s) {
        $name = Telegram::tz($s)->getName();
        return preg_match('~^[A-Za-z]+(?:/[A-Za-z0-9_+\-]+)+$|^UTC$~', $name) ? $name : 'auto';
    }
    public static function url($s) {
        $host = $s['forecast_key'] !== '' ? 'https://customer-api.open-meteo.com/v1/forecast' : 'https://api.open-meteo.com/v1/forecast';
        $units = self::api_units($s);
        $query = array('latitude' => $s['forecast_lat'], 'longitude' => $s['forecast_lon'],
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,wind_speed_10m_max,wind_gusts_10m_max',
            'timezone' => self::api_timezone($s), 'forecast_days' => 1, 'temperature_unit' => $units['temp'][0],
            'wind_speed_unit' => $units['wind'][0], 'precipitation_unit' => $units['rain'][0]);
        if ($s['forecast_key'] !== '') { $query['apikey'] = $s['forecast_key']; }
        return $host . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    public static function validate($p, $now, $s = null) {
        $s = is_array($s) ? $s : Telegram::settings();
        if (!is_array($p) || !empty($p['error']) || !is_array($p['daily'] ?? null) ||
            ($p['daily']['time'][0] ?? null) !== self::day($now, $s)) {
            return new \WP_Error('forecast', 'Previsioni assenti o riferite a una giornata diversa da oggi.');
        }
        $units = self::api_units($s);
        $expected = array('temperature_2m_max' => $units['temp'][1], 'temperature_2m_min' => $units['temp'][1], 'precipitation_sum' => $units['rain'][1],
            'precipitation_probability_max' => array('%'), 'wind_speed_10m_max' => $units['wind'][1], 'wind_gusts_10m_max' => $units['wind'][1]);
        foreach ($expected as $key => $accepted) {
            if (!in_array((string) ($p['daily_units'][$key] ?? ''), $accepted, true)) { return new \WP_Error('units', 'Unità delle previsioni non valide.'); }
        }
        foreach (array('weather_code', 'temperature_2m_max', 'temperature_2m_min') as $key) {
            $v = $p['daily'][$key][0] ?? null;
            if (!is_numeric($v) || !is_finite((float) $v)) { return new \WP_Error('forecast', 'Previsioni incomplete.'); }
        }
        return $p;
    }
    public static function fetch($s) {
        $r = wp_remote_get(self::url($s), array('timeout' => 15, 'redirection' => 0,
            'headers' => array('Accept' => 'application/json', 'Cache-Control' => 'no-cache')));
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) {
            return new \WP_Error('forecast', 'Previsioni Open-Meteo non disponibili. Verifica connessione e, se presente, chiave API.');
        }
        return self::validate(json_decode(wp_remote_retrieve_body($r), true), time(), $s);
    }
    public static function weather($code) {
        $map = array(0 => '☀️ ' . __('Cielo sereno', 'ws40-broadcaster'), 1 => '🌤 ' . __('Prevalentemente sereno', 'ws40-broadcaster'),
            2 => '⛅ ' . __('Parzialmente nuvoloso', 'ws40-broadcaster'), 3 => '☁️ ' . __('Cielo coperto', 'ws40-broadcaster'),
            45 => '🌫 ' . __('Nebbia', 'ws40-broadcaster'), 48 => '🌫 ' . __('Nebbia con brina', 'ws40-broadcaster'),
            51 => '🌦 ' . __('Pioviggine debole', 'ws40-broadcaster'), 53 => '🌦 ' . __('Pioviggine moderata', 'ws40-broadcaster'), 55 => '🌧 ' . __('Pioviggine intensa', 'ws40-broadcaster'),
            56 => '🌧 ' . __('Pioviggine gelata debole', 'ws40-broadcaster'), 57 => '🌧 ' . __('Pioviggine gelata intensa', 'ws40-broadcaster'),
            61 => '🌧 ' . __('Pioggia debole', 'ws40-broadcaster'), 63 => '🌧 ' . __('Pioggia moderata', 'ws40-broadcaster'), 65 => '🌧 ' . __('Pioggia intensa', 'ws40-broadcaster'),
            66 => '🌧 ' . __('Pioggia gelata debole', 'ws40-broadcaster'), 67 => '🌧 ' . __('Pioggia gelata intensa', 'ws40-broadcaster'),
            71 => '🌨 ' . __('Neve debole', 'ws40-broadcaster'), 73 => '🌨 ' . __('Neve moderata', 'ws40-broadcaster'), 75 => '🌨 ' . __('Neve intensa', 'ws40-broadcaster'),
            77 => '🌨 ' . __('Nevischio granulare', 'ws40-broadcaster'), 80 => '🌦 ' . __('Rovesci deboli', 'ws40-broadcaster'),
            81 => '🌧 ' . __('Rovesci moderati', 'ws40-broadcaster'), 82 => '🌧 ' . __('Rovesci violenti', 'ws40-broadcaster'),
            85 => '🌨 ' . __('Rovesci di neve deboli', 'ws40-broadcaster'), 86 => '🌨 ' . __('Rovesci di neve intensi', 'ws40-broadcaster'),
            95 => '⛈ ' . __('Temporali', 'ws40-broadcaster'), 96 => '⛈ ' . __('Temporali con grandine debole', 'ws40-broadcaster'),
            99 => '⛈ ' . __('Temporali con grandine intensa', 'ws40-broadcaster'));
        return $map[(int) $code] ?? __('Condizioni non disponibili', 'ws40-broadcaster');
    }
    public static function message($p, $s = null) {
        $s = is_array($s) ? $s : Telegram::settings();
        $d = array();
        foreach ($p['daily'] as $key => $values) { $d[$key] = $values[0] ?? null; }
        $u = self::api_units($s);
        $imperial = Telegram::units($s)['system'] === 'imperial';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $d['time'], Telegram::tz($s));
        $line = static function ($icon, $label, $value) { return $icon . ' ' . $label . ': <b>' . $value . '</b>'; };
        return implode("\n", array('🔮 <b>' . __('Previsioni di oggi — stazione meteorologica', 'ws40-broadcaster') . '</b>',
            '📅 ' . Telegram::date_text($date->getTimestamp(), $s), '', self::weather($d['weather_code']),
            $line('🌡', __('Minima / massima previste', 'ws40-broadcaster'), Telegram::number($d, 'temperature_2m_min') . ' / ' . Telegram::number($d, 'temperature_2m_max') . ' ' . $u['temp'][2]),
            $line('☔', __('Probabilità massima di precipitazioni', 'ws40-broadcaster'), Telegram::number($d, 'precipitation_probability_max', 0) . '%'),
            $line('💧', __('Precipitazioni totali previste', 'ws40-broadcaster'), Telegram::number($d, 'precipitation_sum', $imperial ? 2 : 1) . ' ' . $u['rain'][2]),
            $line('🌬', __('Vento massimo previsto', 'ws40-broadcaster'), Telegram::number($d, 'wind_speed_10m_max') . ' ' . $u['wind'][2]),
            $line('💨', __('Raffiche massime previste', 'ws40-broadcaster'), Telegram::number($d, 'wind_gusts_10m_max') . ' ' . $u['wind'][2]), '',
            __('Previsioni da modelli meteorologici, soggette a variazioni.', 'ws40-broadcaster'),
            __('Fonte', 'ws40-broadcaster') . ': <a href="https://open-meteo.com/">Open-Meteo</a> · <a href="https://creativecommons.org/licenses/by/4.0/">CC BY 4.0</a>',
            '<a href="' . esc_url(home_url('/')) . '">' . esc_html(get_bloginfo('name')) . '</a>'));
    }
    public static function publish($s, $manual = false) {
        if (!$s['token']) { self::status('Salva prima il token Telegram.'); return; }
        if (!Telegram::lock()) { return; }
        try {
            $key = 'cmt_forecast_day_' . md5(Telegram::scope_name($s)); $day = self::day(time(), $s);
            if (!$manual && get_option($key, '') === $day) { return; }
            $p = self::fetch($s);
            if (is_wp_error($p)) { self::status($p->get_error_message()); return; }
            // Claim today before POST: no blind retries after an ambiguous timeout.
            update_option($key, $day, false);
            $r = wp_remote_post('https://api.telegram.org/bot' . $s['token'] . '/sendMessage', array(
                'timeout' => 20, 'redirection' => 0, 'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode(Telegram::target($s) + array('text' => Telegram::in_site_locale(static function () use ($p, $s) { return self::message($p, $s); }), 'parse_mode' => 'HTML',
                    'link_preview_options' => array('is_disabled' => true)))));
            if (is_wp_error($r)) { self::status('Errore di rete Telegram: esito incerto. Controlla il canale. Nessun ritentativo automatico oggi.'); return; }
            $body = json_decode(wp_remote_retrieve_body($r), true);
            if (wp_remote_retrieve_response_code($r) !== 200 || ($body['ok'] ?? false) !== true) {
                self::status('Invio previsioni rifiutato da Telegram (codice ' . (int) ($body['error_code'] ?? wp_remote_retrieve_response_code($r)) . '). Controlla token e permessi. Nessun ritentativo automatico oggi.'); return;
            }
            self::status('Previsioni ' . ($manual ? 'di prova ' : '') . 'inviate a ' . Telegram::scope_name($s) . '.');
        } finally { delete_option('cmt_telegram_lock'); }
    }
}

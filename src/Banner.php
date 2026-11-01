<?php
namespace WS40\Broadcaster;
if (!defined('ABSPATH')) { exit; }
final class Banner {
    const OPT = 'ws40b_banner_settings';
    public static function defaults() {
        return array('eyebrow'=>__('La tua stazione meteo', 'ws40-broadcaster'), 'subtitle'=>__('Il nostro canale Telegram', 'ws40-broadcaster'),
            'title'=>__('Il meteo, direttamente su Telegram.', 'ws40-broadcaster'), 'description'=>__('Unisciti al canale e ricevi i dati della nostra stazione meteo.', 'ws40-broadcaster'),
            'schedule_title'=>__('Aggiornamenti meteorologici', 'ws40-broadcaster'), 'schedule'=>'', 'button'=>__('Seguici su Telegram ↗', 'ws40-broadcaster'),
            'url'=>'', 'bg1'=>'#07466e', 'bg2'=>'#087bab', 'text'=>'#ffffff', 'button_bg'=>'#ffffff', 'button_text'=>'#075578',
            'radius'=>18, 'padding'=>18, 'title_size'=>25, 'body_size'=>14, 'max_width'=>0,
            'show_icon'=>1, 'show_schedule'=>1, 'new_tab'=>1);
    }
    public static function settings() { return array_merge(self::defaults(), (array)get_option(self::OPT, array())); }
    public static function boot() {
        add_action('admin_post_ws40b_banner_save', array(__CLASS__, 'save'));
        add_shortcode('ws40_broadcaster_banner', array(__CLASS__, 'shortcode'));
    }
    public static function menu() {
        add_submenu_page('ws40-broadcaster', __('Banner HTML — WS40 Broadcaster', 'ws40-broadcaster'), __('Banner HTML', 'ws40-broadcaster'), 'manage_options', 'ws40-broadcaster-banner', array(__CLASS__, 'page'));
    }
    public static function save() {
        if (!current_user_can('manage_options')) { wp_die(__('Permessi insufficienti.', 'ws40-broadcaster')); }
        check_admin_referer('ws40b_banner_save');
        $old = self::settings(); $new = array();
        foreach (array('eyebrow','subtitle','title','description','schedule_title','schedule','button') as $key) {
            $new[$key] = sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
            $new[$key] = mb_substr($new[$key], 0, 300);
        }
        $url = esc_url_raw(trim(wp_unslash($_POST['url'] ?? '')));
        if ($url && (!wp_http_validate_url($url) || wp_parse_url($url, PHP_URL_SCHEME) !== 'https')) {
            Telegram::notice('error',__('Banner non salvato: inserisci un URL HTTPS pubblico valido.', 'ws40-broadcaster'));
            wp_safe_redirect(admin_url('admin.php?page=ws40-broadcaster-banner')); exit;
        }
        $new['url'] = $url;
        foreach (array('bg1','bg2','text','button_bg','button_text') as $key) { $new[$key] = sanitize_hex_color(wp_unslash($_POST[$key] ?? '')) ?: $old[$key]; }
        foreach (array('radius'=>array(0,60),'padding'=>array(4,60),'title_size'=>array(14,48),'body_size'=>array(11,24),'max_width'=>array(0,1600)) as $key=>$limits) {
            $new[$key] = max($limits[0], min($limits[1], absint($_POST[$key] ?? $old[$key])));
        }
        foreach (array('show_icon','show_schedule','new_tab') as $key) { $new[$key] = !empty($_POST[$key]) ? 1 : 0; }
        update_option(self::OPT,$new,false);
        Telegram::notice('success',__('Banner salvato correttamente. Il codice HTML e lo shortcode utilizzano ora le nuove impostazioni.', 'ws40-broadcaster'));
        wp_safe_redirect(admin_url('admin.php?page=ws40-broadcaster-banner')); exit;
    }
    public static function shortcode() { return self::html(self::settings()); }
    public static function html($v) {
        $v = array_merge(self::defaults(),$v);
        $style='display:block!important;box-sizing:border-box!important;width:100%!important;'.($v['max_width'] ? 'max-width:'.(int)$v['max_width'].'px!important;' : 'max-width:none!important;').'height:auto!important;margin:0 auto!important;padding:'.(int)$v['padding'].'px!important;border-radius:'.(int)$v['radius'].'px;background:linear-gradient(135deg,'.esc_attr($v['bg1']).' 0%,'.esc_attr($v['bg2']).' 100%);color:'.esc_attr($v['text']).';font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;';
        $out='<div class="ws40b-telegram-banner" style="'.esc_attr($style).'">';
        if ($v['show_icon']) { $out.='<div style="float:left;margin:0 12px 8px 0;padding:8px;border-radius:12px;background:rgba(255,255,255,.14);width:40px;height:40px;box-sizing:border-box" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" style="display:block"><path d="M21.8 3.2 18.5 20c-.2 1.2-.9 1.5-1.9.9l-5-3.7-2.4 2.3c-.3.3-.5.5-1 .5l.4-5.1 9.3-8.4c.4-.4-.1-.6-.6-.3L5.8 13.4.9 11.9c-1.1-.3-1.1-1.1.2-1.6L20.3 2.9c.9-.3 1.7.2 1.5.3Z"/></svg></div>'; }
        $out.='<div style="min-height:44px;padding-top:2px"><div style="font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase">'.esc_html($v['eyebrow']).'</div><div style="font-size:13px;margin-top:4px">'.esc_html($v['subtitle']).'</div></div>';
        $out.='<div role="heading" aria-level="2" style="clear:both;padding-top:10px;margin:0 0 10px;font-size:'.(int)$v['title_size'].'px;font-weight:750;line-height:1.2;letter-spacing:-.5px">'.esc_html($v['title']).'</div>';
        $out.='<p style="margin:0 0 14px;font-size:'.(int)$v['body_size'].'px;line-height:1.5;color:inherit">'.esc_html($v['description']).'</p>';
        if ($v['show_schedule']) { $out.='<div style="margin:0 0 14px;padding:10px 12px;border:1px solid rgba(255,255,255,.24);border-radius:10px;background:rgba(255,255,255,.08);font-size:13px;line-height:1.5"><strong>'.esc_html($v['schedule_title']).'</strong><br><span>'.esc_html($v['schedule']).'</span></div>'; }
        if ($v['url']) { $out.='<a href="'.esc_url($v['url']).'" '.($v['new_tab']?'target="_blank" rel="noopener noreferrer"':'').' style="display:block!important;box-sizing:border-box!important;width:100%!important;min-height:44px;padding:12px;border-radius:10px;background:'.esc_attr($v['button_bg']).'!important;color:'.esc_attr($v['button_text']).'!important;text-align:center;text-decoration:none!important;font-size:15px;font-weight:750;line-height:1.3">'.esc_html($v['button']).'</a>'; }
        return $out.'</div>';
    }
    public static function page() {
        if (!current_user_can('manage_options')) return;
        $v=self::settings(); $s=Telegram::settings();
        if (!$v['url'] && !empty($s['channel']) && strpos($s['channel'],'@')===0) { $v['url']='https://t.me/'.ltrim($s['channel'],'@'); }
        if (!$v['schedule'] && !empty($s['hours'])) { $v['schedule']=implode(' · ',$s['hours']); }
        ?>
        <div class="wrap ws40b-admin"><div class="ws40b-hero"><div class="ws40b-brand"><div class="ws40b-logo"><img src="<?php echo esc_url(plugins_url('assets/ws40-logo.svg', WS40_BROADCASTER_FILE)); ?>" alt="WS40"></div><div><h1><?php echo esc_html(__("Banner HTML", 'ws40-broadcaster')); ?></h1><p class="ws40b-tagline"><?php echo esc_html(__("Crea e personalizza un banner Telegram per il tuo sito", 'ws40-broadcaster')); ?></p></div></div><span class="ws40b-badge"><?php echo esc_html(__("WS40 Broadcaster · 1.3.1", 'ws40-broadcaster')); ?></span></div>
        <?php Telegram::render_notice(); Guide::tabs('banner'); ?>
        <div class="ws40b-panel"><h2><?php echo esc_html(__("Personalizzazione del banner", 'ws40-broadcaster')); ?></h2><p><?php echo esc_html(__("Modifica testi, colori, dimensioni e destinazione. Il banner non richiede script esterni e può essere inserito tramite shortcode o HTML.", 'ws40-broadcaster')); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('ws40b_banner_save'); ?><input type="hidden" name="action" value="ws40b_banner_save">
        <table class="form-table"><tbody>
        <?php foreach (array('eyebrow'=>__('Nome della località / etichetta', 'ws40-broadcaster'),'subtitle'=>__('Sottotitolo', 'ws40-broadcaster'),'title'=>__('Titolo principale', 'ws40-broadcaster'),'description'=>__('Descrizione', 'ws40-broadcaster'),'schedule_title'=>__('Titolo frequenza', 'ws40-broadcaster'),'schedule'=>__('Orari visualizzati', 'ws40-broadcaster'),'button'=>__('Testo pulsante', 'ws40-broadcaster'),'url'=>__('URL destinazione HTTPS', 'ws40-broadcaster')) as $key=>$label): ?>
        <tr><th><label for="ws40b-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input class="large-text" type="<?php echo $key==='url'?'url':'text'; ?>" id="ws40b-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($v[$key]); ?>"></td></tr>
        <?php endforeach; ?>
        <?php foreach (array('bg1'=>__('Sfondo iniziale', 'ws40-broadcaster'),'bg2'=>__('Sfondo finale', 'ws40-broadcaster'),'text'=>__('Testo', 'ws40-broadcaster'),'button_bg'=>__('Sfondo pulsante', 'ws40-broadcaster'),'button_text'=>__('Testo pulsante', 'ws40-broadcaster')) as $key=>$label): ?>
        <tr><th><label for="ws40b-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input type="color" id="ws40b-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($v[$key]); ?>"> <code><?php echo esc_html($v[$key]); ?></code></td></tr>
        <?php endforeach; ?>
        <?php foreach (array('radius'=>__('Angoli arrotondati (px)', 'ws40-broadcaster'),'padding'=>__('Spaziatura interna (px)', 'ws40-broadcaster'),'title_size'=>__('Dimensione titolo (px)', 'ws40-broadcaster'),'body_size'=>__('Dimensione descrizione (px)', 'ws40-broadcaster'),'max_width'=>__('Larghezza massima (px, 0 = piena)', 'ws40-broadcaster')) as $key=>$label): ?>
        <tr><th><label for="ws40b-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input type="number" id="ws40b-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($v[$key]); ?>" min="0" max="1600"></td></tr>
        <?php endforeach; ?>
        <?php foreach (array('show_icon'=>__('Mostra icona Telegram', 'ws40-broadcaster'),'show_schedule'=>__('Mostra riquadro orari', 'ws40-broadcaster'),'new_tab'=>__('Apri il link in una nuova scheda', 'ws40-broadcaster')) as $key=>$label): ?>
        <tr><th><?php echo esc_html($label); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php checked($v[$key]); ?>> <?php echo esc_html(__("Abilitato", 'ws40-broadcaster')); ?></label></td></tr>
        <?php endforeach; ?>
        </tbody></table><?php submit_button(__('Salva banner', 'ws40-broadcaster')); ?></form></div>
        <div class="ws40b-panel"><h2><?php echo esc_html(__("Anteprima del banner salvato", 'ws40-broadcaster')); ?></h2><div style="max-width:520px"><?php echo self::html($v); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><p class="description"><?php echo esc_html(__("L'anteprima si aggiorna dopo il salvataggio.", 'ws40-broadcaster')); ?></p></div>
        <div class="ws40b-panel"><h2><?php echo esc_html(__("Inserisci il banner nel sito", 'ws40-broadcaster')); ?></h2><p><strong><?php echo esc_html(__("Metodo consigliato:", 'ws40-broadcaster')); ?></strong> <?php echo esc_html(__("aggiungi un blocco Shortcode e incolla:", 'ws40-broadcaster')); ?></p><p><code><?php echo esc_html(__("[ws40_broadcaster_banner]", 'ws40-broadcaster')); ?></code></p><p><?php echo esc_html(__("Oppure copia il codice generato in un blocco HTML personalizzato:", 'ws40-broadcaster')); ?></p><textarea class="large-text code" rows="11" readonly id="ws40b-banner-html"><?php echo esc_textarea(self::html($v)); ?></textarea><p><button type="button" class="button button-secondary" id="ws40b-copy"><?php echo esc_html(__("Copia codice HTML", 'ws40-broadcaster')); ?></button> <span id="ws40b-copy-status" role="status"></span></p></div>
        <script>document.getElementById('ws40b-copy').addEventListener('click',function(){var t=document.getElementById('ws40b-banner-html');navigator.clipboard.writeText(t.value).then(function(){document.getElementById('ws40b-copy-status').textContent=<?php echo wp_json_encode(__('Codice copiato.', 'ws40-broadcaster')); ?>;},function(){t.select();document.getElementById('ws40b-copy-status').textContent=<?php echo wp_json_encode(__('Seleziona il codice e copialo manualmente.', 'ws40-broadcaster')); ?>;});});</script>
        </div><?php
    }
}

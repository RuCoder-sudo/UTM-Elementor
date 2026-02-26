<?php
/**
 * Plugin Name: UTM Elementor
 * Plugin URI: https://github.com/RuCoder-sudo/AmoCRM-UTM-Elementor
 * Description: Плагин сохраняет UTM-метки в cookies и автоматически подставляет их в Elementor Pro Form.
 * Version: 3.2
 * Author: Сергей Солошенко (RuCoder)
 * Author URI: https://рукодер.рф
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: UTM-Elementor
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.5
 * WC requires at least: 4.0
 * WC tested up to: 8.5
 * Network: false
 * 
 * Разработчик: Сергей Солошенко | РуКодер
 * Специализация: Веб-разработка с 2018 года | WordPress / Full Stack
 * Принцип работы: "Сайт как для себя"
 * Контакты: 
 * - Телефон/WhatsApp: +7 (985) 985-53-97
 * - Email: support@рукодер.рф
 * - Telegram: @RussCoder
 * - Портфолио: https://рукодер.рф
 * - GitHub: https://github.com/RuCoder-sudo
 */

if (!defined('ABSPATH')) exit;

class UTM_Elementor_Helper {
    const COOKIE_PREFIX   = 'utm_';
    const OPTION_KEY      = 'utm_elem_settings'; // ['inject'=>1, 'frontend_fill'=>1, 'ttl_days'=>90]
    const PAGE_SLUG       = 'utm-elementor';

    public function __construct() {
        add_action('init', [$this, 'capture_utm_into_cookies'], 1);
        add_shortcode('utm', [$this, 'shortcode_utm']);
        add_action('elementor/dynamic_tags/register', [$this, 'register_dynamic_tags']);
        add_action('elementor_pro/forms/process', [$this, 'maybe_inject_on_submit'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_js'], 20);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_utm_elem_save', [$this, 'admin_save']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);
    }
    
    public function add_action_links($links) {
        $settings_url = admin_url('options-general.php?page=' . self::PAGE_SLUG);
        $custom = [
            '<a href="' . esc_url($settings_url) . '">' . esc_html__('Настройки', 'utm-elementor') . '</a>',
        ];
        return array_merge($custom, $links);
    }

    /* =====================  OPTIONS  ===================== */

    public static function get_options() {
        $opt = get_option(self::OPTION_KEY, []);
        $opt = is_array($opt) ? $opt : [];
        return wp_parse_args($opt, [
            'inject'        => 1,
            'frontend_fill' => 1,
            'ttl_days'      => 90,
            'shortcode'     => 1,
            'dynamic_tag'   => 1,
        ]);
    }

    /* =====================  UTM capture  ===================== */

    private function keys_map() {
        return [
            'utm_source','utm_medium','utm_campaign','utm_term','utm_content',
            'gclid','fbclid','msclkid','wbraid','gbraid','yclid',
            'referrer','landing_page',
        ];
    }

    public function capture_utm_into_cookies() {
        $has_any_first = false;
        foreach ($this->keys_map() as $k) {
            if (!empty($_COOKIE[self::COOKIE_PREFIX.'first_'.$k])) { $has_any_first = true; break; }
        }

        $qs = $_GET;
        $found_new = false;

        foreach ($this->keys_map() as $k) {
            if (isset($qs[$k])) {
                $val = sanitize_text_field(wp_unslash($qs[$k]));
                $found_new = true;
                $this->set_cookie('last_'.$k, $val);
                if (!$has_any_first) {
                    $this->set_cookie('first_'.$k, $val);
                }
            }
        }

        if (!$has_any_first) {
            $this->set_cookie('first_referrer', isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '');
            $this->set_cookie('first_landing_page', esc_url_raw(home_url(add_query_arg([], $_SERVER['REQUEST_URI']))));
        }
        if ($found_new) {
            $this->set_cookie('last_referrer', isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '');
            $this->set_cookie('last_landing_page', esc_url_raw(home_url(add_query_arg([], $_SERVER['REQUEST_URI']))));
        }
    }

    private function set_cookie($key, $val) {
        $opt  = self::get_options();
        $days = isset($opt['ttl_days']) ? (int)$opt['ttl_days'] : 90;
        $days = max(1, min(3650, $days));

        $expire = time() + 60 * 60 * 24 * $days;

        setcookie(
            self::COOKIE_PREFIX . $key,
            $val,
            $expire,
            COOKIEPATH ?: '/',
            COOKIE_DOMAIN ?: '',
            is_ssl(),
            true // httpOnly
        );

        $_COOKIE[self::COOKIE_PREFIX . $key] = $val;
    }

    /* =====================  Shortcode  ===================== */

    public function shortcode_utm($atts) {
        $opt = self::get_options();
        if (empty($opt['shortcode'])) {
            $atts = shortcode_atts(['fallback' => ''], $atts, 'utm');
            return $atts['fallback'];
        }
        
        $atts = shortcode_atts([
            'key'      => '',
            'scope'    => 'last',
            'fallback' => '',
        ], $atts, 'utm');

        $key = preg_replace('/[^a-z0-9_]/', '', strtolower($atts['key']));
        if (!$key) return $atts['fallback'];

        $scope      = $atts['scope'] === 'first' ? 'first' : 'last';
        $cookie_key = self::COOKIE_PREFIX . $scope . '_' . $key;

        return isset($_COOKIE[$cookie_key]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_key])) : $atts['fallback'];
    }

    /* =====================  Dynamic Tag  ===================== */

    public function register_dynamic_tags($manager) {
        $opt = self::get_options();
        if (empty($opt['dynamic_tag'])) return;
        if (!class_exists('\Elementor\Core\DynamicTags\Tag')) return;
        require_once __DIR__ . '/utm-dynamic-tag.php';
        $manager->register(new \UTM_Dynamic_Tag());
    }


    /* =========  Submit Injection (toggle)  =========== */

    public function maybe_inject_on_submit($record, $handler) {
        $opt = self::get_options();
        if (empty($opt['inject'])) return;
        $this->inject_utm_on_submit($record, $handler);
    }

    private function inject_utm_on_submit($record, $handler) {
        if (!method_exists($record, 'get')) return;

        $raw_fields = $record->get('fields');

        $add = function($id, $label, $value) use (&$raw_fields) {
            if ($value === '' || $value === null) return;
            if (isset($raw_fields[$id])) return;
            $raw_fields[$id] = [
                'id'        => $id,
                'type'      => 'hidden',
                'label'     => $label,
                'value'     => $value,
                'raw_value' => $value,
            ];
        };

        foreach ($this->utm_pairs_labels() as $k => $label) {
            $val = $_COOKIE[self::COOKIE_PREFIX.'last_'.$k] ?? ($_COOKIE[self::COOKIE_PREFIX.'first_'.$k] ?? '');
            $add($k, $label, sanitize_text_field($val));
        }

        $record->set('fields', $raw_fields);
    }

    private function utm_pairs_labels() {
        return [
            'utm_source'   => 'UTM Source',
            'utm_medium'   => 'UTM Medium',
            'utm_campaign' => 'UTM Campaign',
            'utm_term'     => 'UTM Term',
            'utm_content'  => 'UTM Content',
            'gclid'        => 'GCLID',
            'fbclid'       => 'FBCLID',
            'msclkid'      => 'MSCLKID',
            'wbraid'       => 'WBRAID',
            'gbraid'       => 'GBRAID',
            'yclid'        => 'YCLID',
            'referrer'     => 'Referrer',
            'landing_page' => 'Landing Page',
        ];
    }

    /* =====================  Front JS helper (toggle)  ===================== */

    public function maybe_enqueue_js() {
        $opt = self::get_options();
        if (empty($opt['frontend_fill'])) return;
        $this->enqueue_js();
    }

    private function enqueue_js() {
        $handle = 'utm-elem-helper';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, '', [], null, true);
        }
        wp_enqueue_script($handle);

        $code = "
document.addEventListener('DOMContentLoaded', function() {
  function getCookieMap(){
    return document.cookie.split(';').reduce(function(acc, c){
      var p = c.split('='), k = (p[0]||'').trim(), v = decodeURIComponent((p[1]||'').trim());
      if(k) acc[k]=v; return acc;
    }, {});
  }
  var cookies = getCookieMap();
  function get(key){ return cookies['".self::COOKIE_PREFIX."'+key]||''; }

  var keys = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','fbclid','msclkid','wbraid','gbraid','yclid','referrer','landing_page'];

  function fill(){
    keys.forEach(function(k){
      var el = document.querySelector('form input[name=\"'+k+'\"]');
      if (el && !el.value) {
        var v = get('last_'+k) || get('first_'+k) || '';
        if (v) el.value = v;
      }
    });
  }
  fill();
  var obs = new MutationObserver(function(){ cookies = getCookieMap(); fill(); });
  obs.observe(document.documentElement, {childList:true, subtree:true});
});
        ";

        wp_add_inline_script($handle, $code);
    }

    /* =====================  Admin Page  ===================== */

    public function admin_menu() {
        add_options_page(
            'UTM Elementor',
            'UTM Elementor',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function admin_assets($hook) {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_' . self::PAGE_SLUG) return;
    }

    public function admin_save() {
        if (!current_user_can('manage_options')) return;
        check_admin_referer('utm_elem_save');

        $data = [
            'inject'        => isset($_POST['inject']) ? 1 : 0,
            'frontend_fill' => isset($_POST['frontend_fill']) ? 1 : 0,
            'ttl_days'      => isset($_POST['ttl_days']) ? (int)$_POST['ttl_days'] : 90,
            'shortcode'     => isset($_POST['shortcode']) ? 1 : 0,
            'dynamic_tag'   => isset($_POST['dynamic_tag']) ? 1 : 0,
        ];

        update_option(self::OPTION_KEY, $data);

        wp_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG . '&settings-updated=1'));
        exit;
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) return;
        $opt    = self::get_options();
        $action = wp_nonce_url(admin_url('admin-post.php?action=utm_elem_save'), 'utm_elem_save');

        echo '<style>
        :root {
  --ueh-bg: #f0f2f5;
  --ueh-bg-soft: #ffffff;
  --ueh-card: #ffffff;
  --ueh-border: rgba(0,0,0,0.06);
  --ueh-text: #1d2433;
  --ueh-muted: #5a6475;
  --ueh-accent1: #3b82f6;
  --ueh-accent2: #ec4899;
  --ueh-focus: #93c5fd;
}
@media (prefers-color-scheme: dark) {
  :root {
    --ueh-bg: #0f1226;
    --ueh-bg-soft: #131735;
    --ueh-card: #161a3b;
    --ueh-border: rgba(255,255,255,0.1);
    --ueh-text: #e6e9f0;
    --ueh-muted: #a8b0c6;
    --ueh-accent1: #6aa7ff;
    --ueh-accent2: #ff5edb;
    --ueh-focus: #a0c2ff;
  }
}
.ueh-wrap {
  font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  color: var(--ueh-text);
  background: var(--ueh-bg);
  padding: 32px;
  border-radius: 12px;
  max-width: 1200px;
  margin: 20px auto;
}
.ueh-header { margin-bottom: 32px; }
.ueh-title { font-size: 32px; font-weight: 800; margin: 0; background: linear-gradient(135deg, var(--ueh-accent1), var(--ueh-accent2)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.ueh-sub { color: var(--ueh-muted); font-size: 16px; margin-top: 8px; }

.ueh-tabs { display: flex; gap: 12px; margin-bottom: 24px; border-bottom: 1px solid var(--ueh-border); padding-bottom: 12px; }
.ueh-tab-btn { background: transparent; border: none; color: var(--ueh-muted); padding: 8px 20px; cursor: pointer; font-weight: 600; font-size: 15px; transition: all 0.2s; border-radius: 6px; }
.ueh-tab-btn:hover { color: var(--ueh-text); background: var(--ueh-bg-soft); }
.ueh-tab-btn.active { background: var(--ueh-bg-soft); color: var(--ueh-accent1); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }

.ueh-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 24px; }
@media (max-width: 900px) { .ueh-grid { grid-template-columns: 1fr; } }

.ueh-card {
  background: var(--ueh-card);
  border: 1px solid var(--ueh-border);
  border-radius: 12px;
  padding: 24px;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}
.ueh-card h3 { margin: 0 0 20px; font-size: 18px; font-weight: 700; border-bottom: 1px solid var(--ueh-border); padding-bottom: 12px; color: var(--ueh-text); }

.ueh-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 0; border-bottom: 1px solid var(--ueh-border); }
.ueh-row:last-child { border-bottom: none; }
.ueh-row-content { flex: 1; }
.ueh-row-title { font-weight: 600; font-size: 15px; margin-bottom: 4px; }
.ueh-row-desc { color: var(--ueh-muted); font-size: 13px; line-height: 1.4; }

.ueh-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.ueh-switch input { opacity: 0; width: 0; height: 0; }
.ueh-slider { position: absolute; cursor: pointer; inset: 0; background-color: #cbd5e1; border-radius: 24px; transition: .3s; }
.ueh-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; border-radius: 50%; transition: .3s; }
input:checked + .ueh-slider { background-color: var(--ueh-accent1); }
input:checked + .ueh-slider:before { transform: translateX(20px); }

.ueh-input-number { width: 80px; padding: 8px 12px; border: 1px solid var(--ueh-border); border-radius: 6px; background: var(--ueh-bg-soft); color: var(--ueh-text); }

.ueh-button-primary {
  background: var(--ueh-accent1);
  color: white;
  border: none;
  padding: 12px 24px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.2s;
  margin-top: 24px;
}
.ueh-button-primary:hover { opacity: 0.9; }

.ueh-code-block { background: #1e293b; color: #e2e8f0; padding: 16px; border-radius: 8px; font-family: monospace; font-size: 13px; line-height: 1.6; overflow-x: auto; margin: 12px 0; }

.ueh-instruction-section { margin-bottom: 24px; }
.ueh-instruction-section h4 { font-size: 16px; font-weight: 700; margin-bottom: 12px; color: var(--ueh-accent1); }
.ueh-instruction-list { padding-left: 20px; margin: 0; }
.ueh-instruction-list li { margin-bottom: 8px; font-size: 14px; color: var(--ueh-muted); }
        </style>
        <div class="ueh-wrap">
            <div class="ueh-header">
                <h1 class="ueh-title">UTM Elementor</h1>
                <p class="ueh-sub">Профессиональная система отслеживания UTM-меток для Elementor Pro</p>
            </div>

            <div class="ueh-tabs">
                <button type="button" class="ueh-tab-btn active" onclick="switchTab(event, 'settings')">Настройки</button>
                <button type="button" class="ueh-tab-btn" onclick="switchTab(event, 'instructions')">Инструкция</button>
            </div>

            <div id="tab-settings" class="ueh-tab-content">
                <div class="ueh-grid">
                    <div class="ueh-card">
                        <h3>Конфигурация плагина</h3>
                        <form method="post" action="'.$action.'">
                            <div class="ueh-row">
                                <div class="ueh-row-content">
                                    <div class="ueh-row-title">Серверная инъекция</div>
                                    <div class="ueh-row-desc">Автоматически добавляет UTM-метки в тело письма и базу заявок, даже если в форме нет скрытых полей.</div>
                                </div>
                                <label class="ueh-switch">
                                    <input type="checkbox" name="inject" value="1" '.checked(1, $opt['inject'], false).'>
                                    <span class="ueh-slider"></span>
                                </label>
                            </div>

                            <div class="ueh-row">
                                <div class="ueh-row-content">
                                    <div class="ueh-row-title">Автозаполнение на фронтенде (JS)</div>
                                    <div class="ueh-row-desc">Заполняет скрытые поля форм значениями из Cookies сразу после загрузки страницы (поддерживает Popups).</div>
                                </div>
                                <label class="ueh-switch">
                                    <input type="checkbox" name="frontend_fill" value="1" '.checked(1, $opt['frontend_fill'], false).'>
                                    <span class="ueh-slider"></span>
                                </label>
                            </div>

                            <div class="ueh-row">
                                <div class="ueh-row-content">
                                    <div class="ueh-row-title">Шорткоды [utm]</div>
                                    <div class="ueh-row-desc">Позволяет использовать шорткоды для вывода меток в любом месте сайта или в настройках интеграций.</div>
                                </div>
                                <label class="ueh-switch">
                                    <input type="checkbox" name="shortcode" value="1" '.checked(1, $opt['shortcode'], false).'>
                                    <span class="ueh-slider"></span>
                                </label>
                            </div>

                            <div class="ueh-row">
                                <div class="ueh-row-content">
                                    <div class="ueh-row-title">Dynamic Tag</div>
                                    <div class="ueh-row-desc">Регистрирует динамический тег "UTM (Cookie)" в Elementor для гибкой настройки полей.</div>
                                </div>
                                <label class="ueh-switch">
                                    <input type="checkbox" name="dynamic_tag" value="1" '.checked(1, $opt['dynamic_tag'], false).'>
                                    <span class="ueh-slider"></span>
                                </label>
                            </div>

                            <div class="ueh-row">
                                <div class="ueh-row-content">
                                    <div class="ueh-row-title">Срок жизни Cookies (дней)</div>
                                    <div class="ueh-row-desc">Как долго хранить данные о посетителе. Рекомендуется 90 дней.</div>
                                </div>
                                <input type="number" name="ttl_days" value="'.esc_attr($opt['ttl_days']).'" min="1" max="3650" class="ueh-input-number">
                            </div>

                            <button type="submit" class="ueh-button-primary">Сохранить изменения</button>
                        </form>
                    </div>

                    <div class="ueh-card">
                        <h3>Быстрый старт</h3>
                        <div class="ueh-instruction-section">
                            <h4>Примеры шорткодов</h4>
                            <div class="ueh-code-block">
                                [utm key="utm_source" scope="last"]<br>
                                [utm key="utm_campaign" scope="first"]
                            </div>
                        </div>
                        <div class="ueh-instruction-section">
                            <h4>Доступные ключи</h4>
                            <div class="ueh-row-desc">
                                utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid, fbclid, msclkid, referrer, landing_page
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="tab-instructions" class="ueh-tab-content" style="display:none;">
                <div class="ueh-card">
                    <h3>Как использовать UTM Elementor</h3>
                    
                    <div class="ueh-grid">
                        <div class="ueh-instruction-section">
                            <h4>1. Автоматический режим</h4>
                            <p class="ueh-row-desc">Просто включите "Серверную инъекцию". Плагин сам найдет все формы Elementor и добавит к ним данные UTM при отправке. Вам не нужно ничего менять в редакторе.</p>
                        </div>
                        
                        <div class="ueh-instruction-section">
                            <h4>2. Ручной режим (Dynamic Tag)</h4>
                            <ul class="ueh-instruction-list">
                                <li>Добавьте скрытое поле в форму Elementor.</li>
                                <li>В поле "Default Value" нажмите на иконку динамических тегов.</li>
                                <li>Выберите <b>UTM (Cookie)</b>.</li>
                                <li>В настройках тега укажите ключ (например, utm_source).</li>
                            </ul>
                        </div>
                    </div>

                    <div class="ueh-instruction-section" style="margin-top:24px;">
                        <h4>3. Интеграции (amoCRM, Webhooks)</h4>
                        <p class="ueh-row-desc">Если вы используете сторонние модули интеграции, вставляйте шорткоды прямо в поля маппинга данных. Это гарантирует передачу меток, даже если они не отображаются в письме.</p>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function switchTab(evt, tabId) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("ueh-tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }
            tablinks = document.getElementsByClassName("ueh-tab-btn");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
            }
            document.getElementById("tab-" + tabId).style.display = "block";
            evt.currentTarget.className += " active";
        }
        </script>';
    }

}

new UTM_Elementor_Helper();

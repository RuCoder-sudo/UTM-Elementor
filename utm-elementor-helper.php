<?php
/**
 * Plugin Name: AmoCRM UTM Elementor
 * Plugin URI: https://github.com/RuCoder-sudo/AmoCRM-UTM-Elementor
 * Description: Плагин сохраняет UTM-метки в cookies и автоматически подставляет их в Elementor Pro Form.
 * Version: 3.2
 * Author: Сергей Солошенко (RuCoder)
 * Author URI: https://рукодер.рф
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: AmoCRM-UTM-Elementor
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
    const OPTION_KEY      = 'utm_elem_helper_settings'; // ['inject'=>1, 'frontend_fill'=>1, 'ttl_days'=>90]
    const PAGE_SLUG       = 'utm-elementor-helper';

    public function __construct() {
        add_action('init', [$this, 'capture_utm_into_cookies'], 1);
        add_shortcode('utm', [$this, 'shortcode_utm']);
        add_action('elementor/dynamic_tags/register', [$this, 'register_dynamic_tags']);
        add_action('elementor_pro/forms/process', [$this, 'maybe_inject_on_submit'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_js'], 20);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_utm_elem_helper_save', [$this, 'admin_save']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);
    }
    
    public function add_action_links($links) {
    $settings_url = admin_url('options-general.php?page=' . self::PAGE_SLUG);
    $custom = [
        '<a href="' . esc_url($settings_url) . '">' . esc_html__('Настройки', 'utm-elementor-helper') . '</a>',
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
            // шорткоды отключены — ничего не подставляем
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
            'UTM Elementor Helper',
            'UTM Elementor Helper',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function admin_assets($hook) {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_' . self::PAGE_SLUG) return;

        $css = '';
        wp_add_inline_style('wp-components', $css);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) return;
        $opt    = self::get_options();
        $action = wp_nonce_url(admin_url('admin-post.php?action=utm_elem_helper_save'), 'utm_elem_helper_save');

        echo '<style>
        :root {
  --ueh-bg: #0f1226;
  --ueh-bg-soft: #131735;
  --ueh-card: #161a3b;
  --ueh-border: rgba(255,255,255,0.08);
  --ueh-text: #e6e9f0;
  --ueh-muted: #a8b0c6;
  --ueh-accent1: #6aa7ff;
  --ueh-accent2: #ff5edb;
  --ueh-focus: #a0c2ff;
}
@media (prefers-color-scheme: light) {
  :root {
    --ueh-bg: #f7f8fb;
    --ueh-bg-soft: #ffffff;
    --ueh-card: #ffffff;
    --ueh-border: rgba(0,0,0,0.08);
    --ueh-text: #1d2433;
    --ueh-muted: #5a6475;
    --ueh-accent1: #243a85;
    --ueh-accent2: #e50050;
    --ueh-focus: #93c5fd;
  }
}
.ueh-wrap {
  font-family: Inter, -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
  color: var(--ueh-text);
  background: radial-gradient(1000px 600px at 100% -10%, rgba(106,167,255,0.18), transparent 60%),
              radial-gradient(800px 500px at -20% 120%, rgba(255,94,219,0.12), transparent 60%),
              var(--ueh-bg);
  padding: 24px 24px 40px;
  border-radius: 16px;
}
.ueh-header { display:flex; align-items:center; gap:14px; margin-bottom:18px; }
.ueh-badge {
  background: linear-gradient(135deg, var(--ueh-accent1), var(--ueh-accent2));
  color:#fff; font-weight:600; font-size:12px; padding:6px 10px; border-radius:999px; letter-spacing:.3px;
}
.ueh-title { font-size:28px; font-weight:800; margin:4px 0 2px; }
.ueh-sub { color:var(--ueh-muted); margin:0 0 16px; }

.ueh-card {
  background: var(--ueh-card);
  border:1px solid var(--ueh-border);
  border-radius:16px; padding:18px;
  box-shadow: 0 8px 30px rgba(0,0,0,0.12);
}
.ueh-card h3 { margin:0 0 12px; font-size:16px; font-weight:700; color: var(--ueh-text);}

.ueh-grid-1 { display:grid; grid-template-columns: 1fr; gap:18px; margin-bottom:18px; }
.ueh-grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap:18px; }
@media (max-width: 1180px) { .ueh-grid-2 { grid-template-columns: 1fr; } }

.ueh-row { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 0; border-top:1px solid var(--ueh-border); }
.ueh-row:first-child { border-top:0; }
.ueh-row .desc { color:var(--ueh-muted); font-size:12.5px; }

.ueh-switch { --w:48px; --h:28px; position:relative; display:inline-block; width:var(--w); height:var(--h); }
.ueh-switch input { opacity:0; width:0; height:0; }
.ueh-slider {
  position:absolute; cursor:pointer; inset:0; border-radius:999px;
  background: linear-gradient(135deg, #232744, #1a1e39); border:1px solid var(--ueh-border); transition: all .25s ease;
}
.ueh-slider:before {
  content:""; position:absolute; height:22px; width:22px; left:3px; top:50%; transform:translateY(-50%);
  background:white; border-radius:50%; box-shadow: 0 4px 12px rgba(0,0,0,0.25); transition: all .25s ease;
}
.ueh-switch input:checked + .ueh-slider { background: linear-gradient(135deg, var(--ueh-accent1), var(--ueh-accent2)); box-shadow: 0 0 0 3px rgba(106,167,255,0.2); }
.ueh-switch input:checked + .ueh-slider:before { transform:translate(20px, -50%); }

.ueh-number {
  width:140px; padding:10px 12px; border-radius:12px; background:var(--ueh-bg-soft);
  border:1px solid var(--ueh-border); color:var(--ueh-text);
}
.ueh-number:focus { outline:none; border-color: var(--ueh-focus); box-shadow: 0 0 0 3px rgba(160,194,255,0.25); }

.ueh-code {
  background: var(--ueh-bg-soft); border:1px solid var(--ueh-border); border-radius:12px;
  padding:14px; white-space:pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size:12.5px; color: var(--ueh-text);
}
.ueh-list { margin:0; padding-left:18px; color:var(--ueh-muted); }
.ueh-footnote { color:var(--ueh-muted); font-size:12px; margin-top:8px; }
.ueh-cta { display:flex; gap:10px; flex-wrap:wrap; }
.ueh-pill { border:1px solid var(--ueh-border); border-radius:999px; padding:6px 10px; color:var(--ueh-muted); }
</style>
<div class="wrap"><div class="ueh-wrap">';

        echo '<div class="ueh-title">UTM Elementor Helper</div>';
        echo '<br><div class="ueh-sub">Автосохранение и подстановка UTM-меток без «грязных» URL. First/Last touch, шорткоды, Dynamic Tag, серверная инъекция.</div>';

        echo '<div style="margin-bottom: 20px;">';
        echo '<style>.ueh-tab-btn { background: transparent; border: 1px solid var(--ueh-border); color: var(--ueh-text); padding: 8px 16px; border-radius: 8px; cursor: pointer; margin-right: 8px; font-weight: 600; transition: all 0.2s;} .ueh-tab-btn.active { background: linear-gradient(135deg, var(--ueh-accent1), var(--ueh-accent2)); color: #fff; border-color: transparent; } .ueh-tab-content { display: none; } .ueh-tab-content.active { display: block; }</style>';
        echo '<button type="button" class="ueh-tab-btn active" onclick="document.querySelectorAll(\'.ueh-tab-btn\').forEach(b=>b.classList.remove(\'active\')); this.classList.add(\'active\'); document.querySelectorAll(\'.ueh-tab-content\').forEach(c=>c.classList.remove(\'active\')); document.getElementById(\'tab-settings-content\').classList.add(\'active\');">Настройки</button>';
        echo '<button type="button" class="ueh-tab-btn" onclick="document.querySelectorAll(\'.ueh-tab-btn\').forEach(b=>b.classList.remove(\'active\')); this.classList.add(\'active\'); document.querySelectorAll(\'.ueh-tab-content\').forEach(c=>c.classList.remove(\'active\')); document.getElementById(\'tab-instructions-content\').classList.add(\'active\');">Инструкция</button>';
        echo '</div>';

        echo '<div id="tab-settings-content" class="ueh-tab-content active">';

        /* ---- 1-й блок: во всю ширину ---- */
        echo '<div class="ueh-grid-1">';
        echo '  <div class="ueh-card">';
        echo '    <h3>Описание плагина</h3>';
        echo '    <p>Плагин фиксирует UTM-метки и идентификаторы посетителей в cookies как <em>first touch</em> и <em>last touch</em>, сохраняет referrer и landing_page, а затем подставляет их в формы Elementor Pro — через Dynamic Tag, шорткоды, JS-автозаполнение и/или серверную инъекцию при отправке заявки. Работает с кэшем, попапами и шаблонами.</p>';
        echo '    <div class="ueh-cta"><span class="ueh-pill">WordPress 5.8+</span><span class="ueh-pill">Elementor Pro 3.x+</span><span class="ueh-pill">PHP 7.4+</span></div>';
        echo '    <h3 style="margin-top:16px">Как это работает</h3>';
        echo '    <ol class="ueh-list">';
        echo '      <li>При первом заходе с ?utm_* и/или кликовыми ID значения сохраняются в cookies (режимы first/last) + referrer/landing_page.</li>';
        echo '      <li>Подстановка в формы: Dynamic Tag «UTM (Cookie)», шорткоды, JS-автозаполнение скрытых полей.</li>';
        echo '      <li>Серверная инъекция (если включено) гарантирует наличие UTM даже без скрытых полей — в письмах, [all-fields], webhook.</li>';
        echo '    </ol>';
        echo '    <p class="ueh-footnote">GDPR: классифицируйте эти cookies как «Статистика/Маркетинг» в баннере согласия. Срок хранения настраивается.</p>';
        echo '  </div>';
        echo '</div>';

        /* ---- 2-я строка: 2 карточки в ряд ---- */
        echo '<div class="ueh-grid-2">';

        // Левая: Настройки
        echo '<div class="ueh-card">';
        echo '<h3>Настройки плагина</h3>';
        echo '<form method="post" action="'.$action.'">';

        echo '<div class="ueh-row">';
        echo '<div><strong>Серверная инъекция при сабмите</strong><div class="desc">Добавлять UTM в запись формы даже если скрытых полей нет.</div></div>';
        echo '<label class="ueh-switch"><input type="checkbox" name="inject" value="1" '.checked(1, $opt['inject'], false).' /><span class="ueh-slider"></span></label>';
        echo '</div>';

        echo '<div class="ueh-row">';
        echo '<div><strong>Автозаполнение UTM на фронте (JS)</strong><div class="desc">Подставлять значения из cookies в скрытые поля, включая попапы.</div></div>';
        echo '<label class="ueh-switch"><input type="checkbox" name="frontend_fill" value="1" '.checked(1, $opt['frontend_fill'], false).' /><span class="ueh-slider"></span></label>';
        echo '</div>';

        echo '<div class="ueh-row">';
        echo '<div><strong>Срок жизни cookies (дни)</strong><div class="desc">Диапазон: 1–3650. По умолчанию — 90.</div></div>';
        echo '<input class="ueh-number" type="number" min="1" max="3650" name="ttl_days" value="'.esc_attr($opt['ttl_days']).'" />';
        echo '</div>';
        
        echo '<div class="ueh-row">';
        echo '<div><strong>Шорткоды [utm]</strong><div class="desc">Включить подстановку значений через шорткоды в интеграциях/формах.</div></div>';
        echo '<label class="ueh-switch"><input type="checkbox" name="shortcode" value="1" '.checked(1, $opt['shortcode'], false).' /><span class="ueh-slider"></span></label>';
        echo '</div>';

        echo '<div class="ueh-row">';
        echo '<div><strong>Dynamic Tag</strong><div class="desc">Регистрировать тег «UTM (Cookie)» для Elementor Pro.</div></div>';
        echo '<label class="ueh-switch"><input type="checkbox" name="dynamic_tag" value="1" '.checked(1, $opt['dynamic_tag'], false).' /><span class="ueh-slider"></span></label>';
        echo '</div>';


        submit_button('Сохранить настройки', 'primary', '', false);
        echo '</form>';
        echo '</div>';

        // Правая: Список шорткодов
        echo '<div class="ueh-card">';
        echo '<h3>Список доступных шорт-кодов</h3>';
        echo '<div class="ueh-code">';
        echo "[utm key=\"utm_source\" scope=\"last\" fallback=\"\"]\n";
        echo "[utm key=\"utm_campaign\"]\n";
        echo "[utm key=\"landing_page\" scope=\"first\" fallback=\"(unknown)\"]\n\n";
        echo "И другие:";
        echo "utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid, fbclid, msclkid, wbraid, gbraid, yclid, referrer, landing_page\n";
        echo "\n• scope: last (по умолчанию) | first\n";
        echo "• fallback: текст по умолчанию, если cookie отсутствует";
        echo '</div>';

        echo '<h3 style="margin-top:16px">Dynamic Tag</h3>';
        echo '<p>В поле формы выберите: <b>Dynamic → UTM (Cookie)</b>, затем задайте <em>Scope</em> (Last/First) и <em>Key</em> (utm_source и др.).</p>';
        echo '<p class="ueh-footnote">Совместимо с кэшем/шаблонами/попапами. Для amoCRM и других интеграций используйте шорткоды в полях маппинга.</p>';

        echo '</div>';

        echo '</div>';
        
        
        echo '</div>'; // End of tab-settings-content
        
        echo '<div id="tab-instructions-content" class="ueh-tab-content">';
        echo '<div class="ueh-card" style="white-space: pre-wrap; line-height: 1.6;">';
        echo 'Кому пригодится
Владельцам и маркетологам на WordPress + Elementor Pro
Тем, у кого utm-метки теряются при переходе между страницами
Тем, кто отправляет заявки в CRM, email, вебхуки и хочет корректную атрибуцию
Решает проблемы с UTM
UTM пропадают после перехода на другую страницу
UTM-метки не попадают в форму в попапе Elementor
Не везде добавлены скрытые поля UTM-меток
Нужно разом добавить поля UTM-меток на все формы сайта
Как это работает
Фиксация входа: при входе на сайт с UTM-метками плагин запишет их в cookies в двух режимах — First touch и Last touch, вместе с referrer и landing_page.
Подстановка в формы:
Dynamic Tag «UTM (Cookie)» — выбирается в Default Value настройке у поля в форме Elementor Pro.
Шорткоды UTM-меток —  для интеграций amoCRM/Bitrix24/вебхуков.
JS-автозаполнение заполняет поля UTM-меток из Cookies на фронте, включая формы в попапах.
Серверная инъекция  добавит UTM-метки к заявке даже без UTM-полей в формах.
Чистые URL: без протаскивания меток в URL — метки сохраняются в cookies.
Основные возможности
Сохраняет: utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid, fbclid, msclkid, wbraid, gbraid, yclid, referrer, landing_page в Cookie и автоматически интегрирует их в формы/заявки Elementor Pro.

First/Last touch — UTM-метки первого и последнего входа на сайт

Dynamic Tag — динамическая подстановка UTM-меток в поля Elementor

Шорткоды UTM-меток для интеграций/маппинга

Серверная инъекция — отправка UTM даже без настройки скрытых полей форм

JS-автодобавление значений в скрытые поля форм.

TTL cookies — время жизни cookies.

Совместимость с кэшем, шаблонами и Elementor Pop-up.



Установка плагина
Установите и активируйте плагин.

В Настройки → UTM Elementor Helper включите нужные тумблеры:

«Серверная инъекция при сабмите»

«Автозаполнение UTM на фронте (JS)»

«Шорткоды» и «Dynamic Tag» — по необходимости

Задайте TTL cookies (время жизни)

(Опционально) В форме добавьте скрытые поля с custom_id

В Default Value используйте Dynamic Tag «UTM (Cookie)» или шорткоды.

Совместимость
WordPress 5.8+
PHP 7.4+
Elementor Pro 3.x+ (Form widget)
Приватность / GDPR
Используются технические cookies для атрибуции.
В баннере согласия отнесите их к «Статистика/Маркетинг».
Срок хранения — настраиваемый TTL (по умолчанию 90 дней).';
        echo '</div>';
        echo '</div>'; // End of tab-instructions-content

        echo '<br><br><center><a href="https://рукодер.рф/" target="_blank" style="text-decoration: none;"><span class="ueh-badge">RuCoder</span></a></center>';

        echo '</div></div>';
    }

    public function admin_save() {
        if (!current_user_can('manage_options') || !check_admin_referer('utm_elem_helper_save')) wp_die('Not allowed');

        // Тумблеры
        $inject        = isset($_POST['inject']) ? 1 : 0;
        $frontend_fill = isset($_POST['frontend_fill']) ? 1 : 0;
        $shortcode     = isset($_POST['shortcode']) ? 1 : 0;
        $dynamic_tag   = isset($_POST['dynamic_tag']) ? 1 : 0;

        // TTL (с валидацией)
        $ttl_days = isset($_POST['ttl_days']) ? (int) $_POST['ttl_days'] : 90;
        $ttl_days = max(1, min(3650, $ttl_days));

        update_option(self::OPTION_KEY, [
            'inject'        => $inject,
            'frontend_fill' => $frontend_fill,
            'ttl_days'      => $ttl_days,
            'shortcode'     => $shortcode,
            'dynamic_tag'   => $dynamic_tag,
        ], false);

        wp_redirect(add_query_arg(['page'=> self::PAGE_SLUG, 'updated' => '1'], admin_url('options-general.php')));
        exit;
    }

}

new UTM_Elementor_Helper();
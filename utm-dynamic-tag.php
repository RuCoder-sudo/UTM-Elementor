<?php
if (!defined('ABSPATH')) exit;

use Elementor\Core\DynamicTags\Tag;

class UTM_Dynamic_Tag extends Tag {
    public function get_name() { return 'utm_cookie'; }
    public function get_title() { return __('UTM (Cookie)', 'utm-elementor-helper'); }
    public function get_group() { return 'site'; } 
    public function get_categories() {
        return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ];
    }

    protected function register_controls() {
        $this->add_control('scope', [
            'label'   => __('Scope', 'utm-elementor-helper'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'last',
            'options' => ['last' => 'Last touch', 'first' => 'First touch'],
        ]);
        $this->add_control('key', [
            'label'   => __('Key', 'utm-elementor-helper'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => [
                'utm_source' => 'utm_source',
                'utm_medium' => 'utm_medium',
                'utm_campaign' => 'utm_campaign',
                'utm_term' => 'utm_term',
                'utm_content' => 'utm_content',
                'gclid' => 'gclid',
                'fbclid' => 'fbclid',
                'msclkid' => 'msclkid',
                'wbraid' => 'wbraid',
                'gbraid' => 'gbraid',
                'yclid' => 'yclid',
                'referrer' => 'referrer',
                'landing_page' => 'landing_page',
            ],
            'default' => 'utm_source',
        ]);
        $this->add_control('fallback', [
            'label'   => __('Fallback', 'utm-elementor-helper'),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
    }

    public function render() {
        $scope    = $this->get_settings('scope') === 'first' ? 'first' : 'last';
        $key      = preg_replace('/[^a-z0-9_]/', '', strtolower($this->get_settings('key')));
        $fallback = $this->get_settings('fallback') ?: '';
        $ck       = UTM_Elementor_Helper::COOKIE_PREFIX . $scope . '_' . $key;

        echo isset($_COOKIE[$ck]) ? esc_html($_COOKIE[$ck]) : esc_html($fallback);
    }
}
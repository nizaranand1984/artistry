<?php

if (!defined('ABSPATH')) {
    die('-1');
}

class QuadMenu_Compiler extends QuadMenu_Redux {

    public $redux = '';
    public $args = array();
    public static $instance;

    public function __construct() {

        add_filter('quadmenu_global_js_data', array($this, 'js_data'));

        add_action('init', array($this, 'activation'));

        add_action('redux/page/' . QUADMENU_REDUX . '/enqueue', array($this, 'enqueue'));

        add_filter('redux/options/' . QUADMENU_REDUX . '/ajax_save/response', array($this, 'compile_variables'));

        add_filter('redux/options/' . QUADMENU_REDUX . '/compiler', array($this, 'compiler'), 5, 3);

        add_action('wp_ajax_quadmenu_compiler_save', array($this, 'compiler_save'));
    }

    public static function instance() {
        if (!isset(self::$instance)) {
            self::$instance = new QuadMenu_Compiler();
        }
        return self::$instance;
    }

    function activation() {

        if (!get_transient('_quadmenu_activation'))
            return;

        Quadmenu_Compiler::do_compiler(true);
    }

    function js_data($data) {

        global $quadmenu;

        $data['debug'] = QUADMENU_DEV;
        $data['files'] = apply_filters('quadmenu_compiler_files', array(
            QUADMENU_URL_ASSETS . 'frontend/less/quadmenu-locations.less',
            QUADMENU_URL_ASSETS . 'frontend/less/quadmenu-widgets.less',
        ));
        $data['variables'] = $this->less_variables($quadmenu);
        $data['compiler'] = $this->run_compiler();
        $data['nonce'] = wp_create_nonce('quadmenu');

        return $data;
    }

    public function enqueue() {

        wp_register_script('quadmenu-less', QUADMENU_URL . 'assets/backend/js/less.min.js', array(), '', true);

        wp_enqueue_script('quadmenu-admin-compiler', QUADMENU_URL . 'assets/backend/js/quadmenu-admin-compiler' . QuadMenu::isMin() . '.js', array('quadmenu-less'), '', true);

        wp_localize_script('quadmenu-admin-compiler', 'quadmenu', apply_filters('quadmenu_global_js_data', array()));
    }

    function compile_variables($return_array) {

        if (is_array($return_array)) {
            $return_array['variables'] = $this->less_variables($return_array['options']);
        }

        return $return_array;
    }

    public function compiler_save() {

        $return_array = array('status' => 'error');

        check_ajax_referer('quadmenu', 'nonce');

        if (!isset($_POST['output']['imports'][0])) {
            $this->add_notification('red', esc_html__('Imports is undefined.', 'quadmenu'));
            wp_die();
        }

        if (!isset($_POST['output']['css'])) {
            $this->add_notification('red', esc_html__('CSS is undefined.', 'quadmenu'));
            wp_die();
        }

        $return_array['status'] = 'success';

        try {
            $this->save_file(str_replace('.less', '.css', basename($_POST['output']['imports'][0])), QUADMENU_PATH_CSS, stripslashes($_POST['output']['css']));
        } catch (Exception $e) {
            $return_array['status'] = $e->getMessage();
        }

        ob_start();

        $this->notification_bar();

        $notification_bar = ob_get_contents();

        ob_end_clean();

        $return_array['notification_bar'] = $notification_bar;

        Quadmenu_Compiler::do_compiler(false);

        echo json_encode($return_array);

        wp_die();
    }

    public static function do_compiler($run = true) {

        if ($run) {
            update_option('_quadmenu_compiler', $run);
        } else {
            delete_option('_quadmenu_compiler');
        }
    }

    public function run_compiler() {

        return (int) get_option('_quadmenu_compiler', false);
    }

    public function compiler($options, $css, $changed) {

        Quadmenu_Compiler::do_compiler(true);

        $this->add_notification('yellow', sprintf(esc_html__('Some style options have been changed. Your stylesheet will be compiled to reflect changes. %s.', 'quadmenu'), esc_html__('Please wait', 'quadmenu')));
    }

    public function save_file($name = false, $dir = false, $content = false) {

        if (!$name || !$dir || !$content) {
            return;
        }

        if (!class_exists('ReduxFrameworkInstances')) {
            $this->add_notification('error', esc_html__('ReduxFramework is not installed', 'quadmenu'));
            return;
        }

        $this->redux = ReduxFrameworkInstances::get_instance(QUADMENU_REDUX);

        // check if file exists ------------------------------------------------
        $is_file = is_file(trailingslashit($dir) . $name);

        // create the folder ---------------------------------------------------
        if (!is_dir($dir)) {
            $this->redux->filesystem->execute('mkdir', $dir);
            $this->add_notification('yellow', sprintf(esc_html__('Folder created : %1$s', 'quadmenu'), $dir));
        }

        // write file ----------------------------------------------------------
        if ($this->redux->filesystem->execute('put_contents', trailingslashit($dir) . $name, array('content' => $content))) {
            $this->add_notification('green', sprintf(esc_html__('File has been %2$s : %1$s', 'quadmenu'), trailingslashit($dir) . $name, $is_file ? esc_html__('updated', 'quadmenu') : esc_html__('created', 'quadmenu')));
            return;
        }

        $this->add_notification('error', sprintf(esc_html__('File cant\'t been created : %1$s', 'quadmenu'), trailingslashit($dir) . $name));
    }

    public function less_variables(&$data, $header = '') {

        $html = QuadMenu_Compiler::less_locations();

        if (!is_array($data))
            return $data;

        if (isset($data['css'])) {
            unset($data['css']);
        }

        foreach ($data as $key => &$val) {

            $value = ($key != 'font-options') ? $val : '';

            $value = (filter_var($value, FILTER_VALIDATE_URL)) ? "'{$value}'" : $value;

            if (is_array($value)) {
                $html = array_merge($html, $this->less_variables($value, "{$key}_"));
            } elseif ($value != '') {
                $html["@{$header}{$key}"] = $value;
            } else {
                $html["@{$header}{$key}"] = 0;
            }
        }

        return $html;
    }

    static function less_locations($themes = array()) {

        global $quadmenu_themes;

        if (count($quadmenu_themes)) {
            foreach ($quadmenu_themes as $key => $theme) {

                $themes[] = '~"' . $key . '"';
            }
        }

        return array('@themes' => implode(',', array_reverse($themes)));
    }

    function redux_compiler($return_array) {

        global $quadmenu;

        if (is_array($return_array)) {
            $return_array['options'] = apply_filters('quadmenu_developer_options', $quadmenu);
            $return_array['variables'] = $this->less_variables($return_array['options']);
        }

        return $return_array;
    }

}

new QuadMenu_Compiler();
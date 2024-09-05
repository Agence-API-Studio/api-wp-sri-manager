<?php
/**
 * Plugin Name: WP SRI Manager
 * Plugin URI: https://www.api-studio.fr
 * Description: Gère automatiquement l'intégrité des sous-ressources (SRI) pour les scripts et styles externes dans WordPress.
 * Version: 2.0
 * Author: API Studio
 * Author URI: https://www.api-studio.fr
 * License: GPL2
 */

// Éviter l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

class SRI_Manager {
    private $scripts_with_sri = array();
    private $styles_with_sri = array();

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_print_scripts', array($this, 'detect_and_generate_sri'), 100);
        add_action('wp_print_styles', array($this, 'detect_and_generate_sri'), 100);
        add_filter('script_loader_tag', array($this, 'add_sri_to_scripts'), 10, 3);
        add_filter('style_loader_tag', array($this, 'add_sri_to_styles'), 10, 4);
    }

    public function add_admin_menu() {
        add_options_page('SRI Manager', 'SRI Manager', 'manage_options', 'sri-manager-advanced', array($this, 'options_page'));
    }

    public function register_settings() {
        register_setting('sri_manager_settings', 'sri_manager_data');
    }

    public function options_page() {
        ?>
        <div class="wrap">
            <h1>SRI Manager</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('sri_manager_settings');
                do_settings_sections('sri_manager_settings');
                ?>
                <h2>Ressources externes détectées avec hachages SRI</h2>
                <textarea name="sri_manager_data" rows="20" cols="80"><?php echo esc_textarea(get_option('sri_manager_data')); ?></textarea>
                <p>Ce champ est automatiquement mis à jour. Vous pouvez modifier manuellement si nécessaire.</p>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function detect_and_generate_sri() {
        global $wp_scripts, $wp_styles;
        
        $this->process_dependencies($wp_scripts, 'scripts');
        $this->process_dependencies($wp_styles, 'styles');
        
        update_option('sri_manager_data', json_encode(array(
            'scripts' => $this->scripts_with_sri,
            'styles' => $this->styles_with_sri
        )));
    }

    private function process_dependencies($dependency_object, $type) {
        if (!is_object($dependency_object) || empty($dependency_object->queue)) {
            return;
        }

        foreach ($dependency_object->queue as $handle) {
            $obj = $dependency_object->registered[$handle];
            if (isset($obj->src) && $this->is_external_url($obj->src)) {
                $url = $this->ensure_absolute_url($obj->src);
                $hash = $this->generate_sri_hash($url);
                if ($hash) {
                    if ($type === 'scripts') {
                        $this->scripts_with_sri[$handle] = $hash;
                    } else {
                        $this->styles_with_sri[$handle] = $hash;
                    }
                }
            }
        }

        // Traitement spécial pour Google Fonts
        if ($type === 'styles') {
            $this->process_google_fonts($dependency_object);
        }
    }

    private function process_google_fonts($wp_styles) {
        foreach ($wp_styles->queue as $handle) {
            $obj = $wp_styles->registered[$handle];
            if (isset($obj->src) && strpos($obj->src, 'fonts.googleapis.com') !== false) {
                $url = $this->ensure_absolute_url($obj->src);
                $hash = $this->generate_sri_hash($url);
                if ($hash) {
                    $this->styles_with_sri[$handle] = $hash;
                }
            }
        }
    }

    private function is_external_url($url) {
        $home_url = home_url();
        $parsed_home = parse_url($home_url);
        $parsed_url = parse_url($url);

        // Vérifier si l'URL est relative
        if (empty($parsed_url['host'])) {
            return false;
        }

        // Vérifier si l'hôte de l'URL correspond à l'hôte du site
        return $parsed_url['host'] !== $parsed_home['host'];
    }

    private function ensure_absolute_url($url) {
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        return $url;
    }

    private function generate_sri_hash($url) {
        $content = @file_get_contents($url);
        if ($content === false) {
            return false;
        }
        $hash = hash('sha384', $content, true);
        return 'sha384-' . base64_encode($hash);
    }

    public function add_sri_to_scripts($tag, $handle, $src) {
        $this->load_sri_data();
        if (array_key_exists($handle, $this->scripts_with_sri) && $this->is_external_url($src)) {
            $tag = str_replace(' src', ' integrity="' . $this->scripts_with_sri[$handle] . '" crossorigin="anonymous" src', $tag);
        }
        return $tag;
    }

    public function add_sri_to_styles($tag, $handle, $href, $media) {
        $this->load_sri_data();
        if (array_key_exists($handle, $this->styles_with_sri) && $this->is_external_url($href)) {
            $tag = str_replace(' href', ' integrity="' . $this->styles_with_sri[$handle] . '" crossorigin="anonymous" href', $tag);
        }
        return $tag;
    }

    private function load_sri_data() {
        $data = json_decode(get_option('sri_manager_data'), true);
        if (is_array($data)) {
            $this->scripts_with_sri = isset($data['scripts']) ? $data['scripts'] : array();
            $this->styles_with_sri = isset($data['styles']) ? $data['styles'] : array();
        }
    }
}

$sri_manager = new SRI_Manager();
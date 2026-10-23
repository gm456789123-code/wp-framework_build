<?php

declare(strict_types=1);

// Zero WP CSS Injection per agents_md.md Section 3
add_action('wp_enqueue_scripts', function (): void {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('global-styles');
    wp_dequeue_style('classic-theme-styles');
}, 100);

// Plugin Buffer: Remove default WordPress head bloat and plugin tracking hooks
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');

// Plugin Buffer: Disable XML-RPC completely (eliminates brute-force and DDoS amplification)
add_filter('xmlrpc_enabled', '__return_false');

// Plugin Buffer: Block unauthenticated REST API user enumeration (prevents username harvesting)
add_filter('rest_endpoints', function (array $endpoints): array {
    if (!is_user_logged_in() && isset($endpoints['/wp/v2/users'])) {
        unset($endpoints['/wp/v2/users']);
    }
    if (!is_user_logged_in() && isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
        unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
    }

    return $endpoints;
});

// Plugin Buffer: Prevent third-party plugins from injecting unsolicited filters into the_content
add_action('template_redirect', function (): void {
    if (!is_admin()) {
        // Ensure content rendering stays purely in Sovereign BlockAstParser control
        remove_filter('the_content', 'wpautop');
    }
}, 1);

// Plugin Buffer: Strip X-Pingback header and disable pingback/trackback processing
add_filter('wp_headers', function (array $headers): array {
    unset($headers['X-Pingback']);
    return $headers;
});
add_filter('xmlrpc_methods', function (array $methods): array {
    unset($methods['pingback.ping'], $methods['pingback.extensions.getPingbacks']);
    return $methods;
});

// Plugin Buffer: Soft-delete shielding for WordPress Core & Plugin queries
// Ensures plugins in wp-admin / REST do not resurrect or query soft-deleted Sovereign posts
add_filter('posts_where', function (string $where): string {
    global $wpdb;
    if ($wpdb instanceof wpdb && !is_admin()) {
        $where .= " AND {$wpdb->posts}.deleted_at IS NULL";
    }
    return $where;
});

// Plugin Buffer: Protect Sovereign theme from wordpress.org repository collision & unexpected auto-updates
add_filter('auto_update_theme', '__return_false');

// Plugin Buffer: Deregister frontend Heartbeat API polling initiated by plugins
add_action('init', function (): void {
    if (!is_admin() && function_exists('wp_deregister_script')) {
        wp_deregister_script('heartbeat');
    }
}, 1);

// Plugin Buffer: Warn admins when a known frontend-dependent plugin is active.
// Sovereign Engine's public front controller (public/front.php) never boots
// WordPress's hook/template system on the live site -- only wp-admin itself
// still runs real WordPress -- so shortcodes, widgets, checkout flows, and
// enqueued frontend scripts from these plugins will never appear to visitors,
// even though they look fully installed and configured in wp-admin.
add_action('admin_notices', function (): void {
    if (!current_user_can('activate_plugins')) {
        return;
    }

    $activePlugins = (array) get_option('active_plugins', []);
    if (is_multisite()) {
        $activePlugins = array_merge(
            $activePlugins,
            array_keys((array) get_site_option('active_sitewide_plugins', [])),
        );
    }

    $frontendDependent = [
        'woocommerce/woocommerce.php' => 'WooCommerce',
        'contact-form-7/wp-contact-form-7.php' => 'Contact Form 7',
        'wpforms-lite/wpforms.php' => 'WPForms',
        'wpforms/wpforms.php' => 'WPForms',
        'elementor/elementor.php' => 'Elementor',
        'elementor-pro/elementor-pro.php' => 'Elementor Pro',
        'jetpack/jetpack.php' => 'Jetpack',
        'bbpress/bbpress.php' => 'bbPress',
        'buddypress/bp-loader.php' => 'BuddyPress',
        'mailchimp-for-wp/mailchimp-for-wp.php' => 'MC4WP: Mailchimp for WordPress',
    ];

    $found = [];
    foreach ($frontendDependent as $basename => $label) {
        if (in_array($basename, $activePlugins, true)) {
            $found[] = $label;
        }
    }

    if ($found !== []) {
        printf(
            '<div class="notice notice-warning"><p><strong>Sovereign Engine:</strong> %s %s not supported '
            . 'on the public site. This engine renders public pages through its own front controller and never '
            . 'runs WordPress\'s plugin hook/template system there, so this plugin\'s frontend features '
            . '(shortcodes, widgets, forms, checkout, scripts) will not appear to visitors -- it will only work '
            . 'inside wp-admin itself.</p></div>',
            esc_html(implode(', ', $found)),
            count($found) === 1 ? 'is active but is' : 'are active but are',
        );
    }

    // Separate warning: caching plugins don't just "not render" -- they
    // actively fight Sovereign Engine's own Redis-backed cache layer, since
    // both try to own the same response.
    $cachingPlugins = [
        'wp-super-cache/wp-cache.php' => 'WP Super Cache',
        'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
        'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
        'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
        'wp-rocket/wp-rocket.php' => 'WP Rocket',
    ];

    $foundCaching = [];
    foreach ($cachingPlugins as $basename => $label) {
        if (in_array($basename, $activePlugins, true)) {
            $foundCaching[] = $label;
        }
    }

    if ($foundCaching !== []) {
        printf(
            '<div class="notice notice-warning"><p><strong>Sovereign Engine:</strong> %s %s active, but public '
            . 'pages are already served by this engine\'s own Redis cache layer and never reach WordPress at all. '
            . 'This plugin has no effect on the live site and should be deactivated to avoid confusion.</p></div>',
            esc_html(implode(', ', $foundCaching)),
            count($foundCaching) === 1 ? 'is' : 'are',
        );
    }
});


<?php
/**
 * Blank template for the tickets page — renders only wp_head, the post
 * content (the [rcmi_tickets] shortcode), and wp_footer. No theme
 * header/footer/sidebar.
 *
 * @package RCMI_Tickets
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        html, body { margin: 0; padding: 0; min-height: 100vh; background: #f4f5f5; }
        #rcmi-tickets-app { min-height: 100vh; padding-bottom: 24px; box-sizing: border-box; }
    </style>
    <?php wp_head(); ?>
</head>
<body <?php body_class('rcmi-tickets-page'); ?>>
    <?php
    while (have_posts()) {
        the_post();
        the_content();
    }
    ?>
    <?php wp_footer(); ?>
</body>
</html>

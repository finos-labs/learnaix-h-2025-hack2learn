<?php
/**
 * Library functions for local_projectevaluator plugin
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Extend navigation to add AI Project Hub to main navigation
 */
function local_projectevaluator_extend_navigation(global_navigation $navigation) {
    global $PAGE;
    
    if (has_capability('local/projectevaluator:view', context_system::instance())) {
        // Add to the primary navigation
        $node = navigation_node::create(
            get_string('pluginname', 'local_projectevaluator'),
            new moodle_url('/local/projectevaluator/index.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'aiprojecthub',
            new pix_icon('i/course', get_string('pluginname', 'local_projectevaluator'))
        );
        
        $node->showinflatnavigation = true;
        $node->nodetype = navigation_node::NODETYPE_LEAF;
        $node->set_force_into_more_menu(false);
        
        // Check if we're currently on the plugin page to set it as active
        if (strpos($PAGE->url->get_path(), '/local/projectevaluator/') !== false) {
            $node->make_active();
        }
        
        // Add it to the navigation
        $navigation->add($node);
        
        // Add JavaScript to properly place and highlight the navigation item
        $url = new moodle_url('/local/projectevaluator/index.php');
        $isActive = strpos($PAGE->url->get_path(), '/local/projectevaluator/') !== false;
        $activeClass = $isActive ? 'active' : '';
        $ariaCurrent = $isActive ? 'aria-current="true"' : '';
        
        $PAGE->requires->js_init_code("
            require(['jquery'], function($) {
                $(document).ready(function() {
                    function addAIProjectHub() {
                        // Find the main navigation ul element
                        var navbar = $('.moremenu .navbar-nav, .primary-navigation .navbar-nav, .nav.navbar-nav');
                        
                        if (navbar.length > 0 && !$('.ai-project-hub-nav').length) {
                            // Create the navigation item with proper Moodle structure
                            var aiHubItem = $('<li class=\"nav-item ai-project-hub-nav\" data-key=\"aiprojecthub\" role=\"none\" data-forceintomoremenu=\"false\">' +
                                '<a role=\"menuitem\" class=\"nav-link {$activeClass}\" href=\"{$url->out()}\" data-disableactive=\"true\" tabindex=\"-1\" {$ariaCurrent}>' +
                                '🤖 AI-Project Hub</a></li>');
                            
                            // Remove active class from other items if this page is active
                            if ('{$activeClass}' === 'active') {
                                navbar.find('.nav-link.active').removeClass('active').removeAttr('aria-current');
                            }
                            
                            // Find the position to insert (after My courses, before Site administration)
                            var siteAdminItem = navbar.find('li[data-key=\"siteadminnode\"]');
                            if (siteAdminItem.length > 0) {
                                siteAdminItem.before(aiHubItem);
                            } else {
                                // If no site admin, add at the end
                                var lastItem = navbar.find('li[data-key]:last');
                                if (lastItem.length > 0) {
                                    lastItem.after(aiHubItem);
                                } else {
                                    navbar.append(aiHubItem);
                                }
                            }
                        }
                    }
                    
                    // Try immediately
                    addAIProjectHub();
                    
                    // Also try after a short delay
                    setTimeout(addAIProjectHub, 100);
                });
            });
        ");
    }
}
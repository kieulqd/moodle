<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace core\navigation\views;

use navigation_node;

/**
 * Class secondary_navigation_view.
 *
 * The secondary navigation view is a stripped down tweaked version of the
 * settings_navigation/navigation
 *
 * @package     core
 * @category    navigation
 * @copyright   2021 onwards Peter Dias
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class secondary extends view {
    /** @var string $headertitle The header for this particular menu*/
    public $headertitle;

    /** @var int The maximum limit of navigation nodes displayed in the secondary navigation */
    const MAX_DISPLAYED_NAV_NODES = 5;

    /**
     * Defines the default structure for the secondary nav in a course context.
     *
     * In a course context, we are curating nodes from the settingsnav and navigation objects.
     * The following mapping construct specifies which object we are fetching it from, the type of the node, the key
     * and in what order we want the node - defined as per the mockups.
     *
     * @return array
     */
    protected function get_default_course_mapping(): array {
        $nodes = [];
        $nodes['settings'] = [
            self::TYPE_CONTAINER => [
                'coursereports' => 3,
                'questionbank' => 4,
            ],
            self::TYPE_SETTING => [
                'editsettings' => 0,
                'gradebooksetup' => 2.1,
                'outcomes' => 2.2,
                'coursecompletion' => 6,
            ],
        ];
        $nodes['navigation'] = [
            self::TYPE_CONTAINER => [
                'participants' => 1,
            ],
            self::TYPE_SETTING => [
                'grades' => 2,
                'badgesview' => 7,
                'competencies' => 8,
            ],
            self::TYPE_CUSTOM => [
                'contentbank' => 5,
            ],
        ];

        return $nodes;
    }

    /**
     * Defines the default structure for the secondary nav in a module context.
     *
     * In a module context, we are curating nodes from the settingsnav object.
     * The following mapping construct specifies the type of the node, the key
     * and in what order we want the node - defined as per the mockups.
     *
     * @return array
     */
    protected function get_default_module_mapping(): array {
        return [
            self::TYPE_SETTING => [
                'modedit' => 1,
                "mod_{$this->page->activityname}_useroverrides" => 3, // Overrides are module specific.
                "mod_{$this->page->activityname}_groupoverrides" => 4,
                'roleassign' => 5,
                'filtermanage' => 6,
                'roleoverride' => 7,
                'rolecheck' => 7.1,
                'logreport' => 8,
                'backup' => 9,
                'restore' => 10,
                'competencybreakdown' => 11,
            ],
            self::TYPE_CUSTOM => [
                'advgrading' => 2,
            ],
        ];
    }

    /**
     * Define the keys of the course secondary nav nodes that should be forced into the "more" menu by default.
     *
     * @return array
     */
    protected function get_default_course_more_menu_nodes(): array {
        return [];
    }

    /**
     * Define the keys of the module secondary nav nodes that should be forced into the "more" menu by default.
     *
     * @return array
     */
    protected function get_default_module_more_menu_nodes(): array {
        return ['roleoverride', 'rolecheck', 'logreport', 'roleassign', 'filtermanage', 'backup', 'restore',
            'competencybreakdown'];
    }

    /**
     * Define the keys of the admin secondary nav nodes that should be forced into the "more" menu by default.
     *
     * @return array
     */
    protected function get_default_admin_more_menu_nodes(): array {
        return [];
    }

    /**
     * Initialise the view based navigation based on the current context.
     *
     * As part of the initial restructure, the secondary nav is only considered for the following pages:
     * 1 - Site admin settings
     * 2 - Course page - Does not include front_page which has the same context.
     * 3 - Module page
     */
    public function initialise(): void {
        global $SITE;

        if (during_initial_install() || $this->initialised) {
            return;
        }
        $this->id = 'secondary_navigation';
        $context = $this->context;
        $this->headertitle = get_string('menu');
        $defaultmoremenunodes = [];
        $maxdisplayednodes = self::MAX_DISPLAYED_NAV_NODES;

        switch ($context->contextlevel) {
            case CONTEXT_COURSE:
                if ($this->page->course->id != $SITE->id) {
                    $this->headertitle = get_string('courseheader');
                    $this->load_course_navigation();
                    $defaultmoremenunodes = $this->get_default_course_more_menu_nodes();
                }
                break;
            case CONTEXT_MODULE:
                $this->headertitle = get_string('activityheader');
                $this->load_module_navigation();
                $defaultmoremenunodes = $this->get_default_module_more_menu_nodes();
                break;
            case CONTEXT_COURSECAT:
                $this->headertitle = get_string('categoryheader');
                $this->load_category_navigation();
                break;
            case CONTEXT_SYSTEM:
                $this->headertitle = get_string('homeheader');
                $this->load_admin_navigation();
                // If the site administration navigation was generated after load_admin_navigation().
                if ($this->has_children()) {
                    // Do not explicitly limit the number of navigation nodes displayed in the site administration
                    // navigation menu.
                    $maxdisplayednodes = null;
                }
                $defaultmoremenunodes = $this->get_default_admin_more_menu_nodes();
                break;
        }

        $this->remove_unwanted_nodes();

        // Don't need to show anything if only the view node is available. Remove it.
        if ($this->children->count() == 1) {
            $this->children->remove('modulepage');
        }
        // Force certain navigation nodes to be displayed in the "more" menu.
        $this->force_nodes_into_more_menu($defaultmoremenunodes, $maxdisplayednodes);
        // Search and set the active node.
        $this->scan_for_active_node($this);
        $this->initialised = true;
    }

    /**
     * Load the course secondary navigation. Since we are sourcing all the info from existing objects that already do
     * the relevant checks, we don't do it again here.
     */
    protected function load_course_navigation(): void {
        $course = $this->page->course;

        // Initialise the main navigation and settings nav.
        // It is important that this is done before we try anything.
        $settingsnav = $this->page->settingsnav;
        $navigation = $this->page->navigation;

        $url = new \moodle_url('/course/view.php', ['id' => $course->id, 'sesskey' => sesskey()]);
        $this->add(get_string('course'), $url, self::TYPE_COURSE, null, 'coursehome');

        $nodes = $this->get_default_course_mapping();
        $nodesordered = $this->get_leaf_nodes($settingsnav, $nodes['settings'] ?? []);
        $nodesordered += $this->get_leaf_nodes($navigation, $nodes['navigation'] ?? []);
        $this->add_ordered_nodes($nodesordered);

        $coursecontext = \context_course::instance($course->id);
        if (has_capability('moodle/course:update', $coursecontext)) {
            // All additional nodes will be available under the 'Course admin' page.
            $text = get_string('courseadministration');
            $url = new \moodle_url('/course/admin.php', array('courseid' => $this->page->course->id));
            $this->add($text, $url, null, null, 'courseadmin', new \pix_icon('t/edit', $text));
        }
    }

    /**
     * Get the module's secondary navigation. This is based on settings_nav and would include plugin nodes added via
     * '_extend_settings_navigation'.
     * It populates the tree based on the nav mockup
     *
     * If nodes change, we will have to explicitly call the callback again.
     */
    protected function load_module_navigation(): void {
        $settingsnav = $this->page->settingsnav;
        $mainnode = $settingsnav->find('modulesettings', self::TYPE_SETTING);
        $nodes = $this->get_default_module_mapping();

        if ($mainnode) {
            $url = new \moodle_url('/mod/' . $this->page->activityname . '/view.php', ['id' => $this->page->cm->id]);
            $setactive = $url->compare($this->page->url, URL_MATCH_BASE);
            $node = $this->add(get_string('modulename', $this->page->activityname), $url, null, null, 'modulepage');
            if ($setactive) {
                $node->make_active();
            }
            // Add the initial nodes.
            $nodesordered = $this->get_leaf_nodes($mainnode, $nodes);
            $this->add_ordered_nodes($nodesordered);

            // We have finished inserting the initial structure.
            // Populate the menu with the rest of the nodes available.
            $this->load_remaining_nodes($mainnode, $nodes);
        }
    }

    /**
     * Load the course category navigation.
     */
    protected function load_category_navigation(): void {
        $settingsnav = $this->page->settingsnav;
        $mainnode = $settingsnav->find('categorysettings', self::TYPE_CONTAINER);
        if ($mainnode) {
            $url = new \moodle_url('/course/index.php', ['categoryid' => $this->context->instanceid]);
            $this->add($this->context->get_context_name(), $url, self::TYPE_CONTAINER, null, 'categorymain');
            $this->load_remaining_nodes($mainnode, []);
        }
    }

    /**
     * Load the site admin navigation
     */
    protected function load_admin_navigation(): void {
        $settingsnav = $this->page->settingsnav;
        $node = $settingsnav->find('root', self::TYPE_SITE_ADMIN);
        // We need to know if we are on the main site admin search page. Here the navigation between tabs are done via
        // anchors and page reload doesn't happen. On every nested admin settings page, the secondary nav needs to
        // exist as links with anchors appended in order to redirect back to the admin search page and the corresponding
        // tab.
        $isadminsearchpage = $this->page->pagetype === 'admin-search';
        if ($node) {
            $siteadminnode = $this->add($node->text, "#link$node->key", null, null, 'siteadminnode');
            if ($isadminsearchpage) {
                $siteadminnode->action = false;
                $siteadminnode->tab = "#link$node->key";
            } else {
                $siteadminnode->action = new \moodle_url("/admin/search.php", [], "link$node->key");
            }
            foreach ($node->children as $child) {
                if ($child->display && !$child->is_short_branch()) {
                    // Mimic the current boost behaviour and pass down anchors for the tabs.
                    if ($isadminsearchpage) {
                        $child->action = false;
                        $child->tab = "#link$child->key";
                    } else {
                        $child->action = new \moodle_url("/admin/search.php", [], "link$child->key");
                    }
                    $this->add_node($child);
                } else {
                    $siteadminnode->add_node($child);
                }
            }
        }
    }

    /**
     * Adds the indexed nodes to the current view. The key should indicate it's position in the tree. Any sub nodes
     * needs to be numbered appropriately, e.g. 3.1 would make the identified node be listed  under #3 node.
     *
     * @param array $nodes An array of navigation nodes to be added.
     */
    protected function add_ordered_nodes(array $nodes): void {
        ksort($nodes);
        foreach ($nodes as $key => $node) {
            // If the key is a string then we are assuming this is a nested element.
            if (is_string($key)) {
                $parentnode = $nodes[floor($key)] ?? null;
                if ($parentnode) {
                    $parentnode->add_node($node);
                }
            } else {
                $this->add_node($node);
            }
        }
    }

    /**
     * Find the remaining nodes that need to be loaded into secondary based on the current context
     *
     * @param navigation_node $completenode The original node that we are sourcing information from
     * @param array           $nodesmap The map used to populate secondary nav in the given context
     */
    protected function load_remaining_nodes(navigation_node $completenode, array $nodesmap): void {
        $flattenednodes = [];
        foreach ($nodesmap as $nodecontainer) {
            $flattenednodes = array_merge(array_keys($nodecontainer), $flattenednodes);
        }

        $populatedkeys = $this->get_children_key_list();
        $existingkeys = $completenode->get_children_key_list();
        $leftover = array_diff($existingkeys, $populatedkeys);
        foreach ($leftover as $key) {
            if (!in_array($key, $flattenednodes) && $leftovernode = $completenode->get($key)) {
                $this->add_node($leftovernode);
            }
        }
    }

    /**
     * Force certain secondary navigation nodes to be displayed in the "more" menu.
     *
     * @param array $defaultmoremenunodes Array with navigation node keys of the pre-defined nodes that
     *                                    should be added into the "more" menu by default
     * @param int|null $maxdisplayednodes The maximum limit of navigation nodes displayed in the secondary navigation
     */
    protected function force_nodes_into_more_menu(array $defaultmoremenunodes = [], ?int $maxdisplayednodes = null) {
        // Counter of the navigation nodes that are initially displayed in the secondary nav
        // (excludes the nodes from the "more" menu).
        $displayednodescount = 0;
        foreach ($this->children as $child) {
            // Skip if the navigation node has been already forced into the "more" menu.
            if ($child->forceintomoremenu) {
                continue;
            }
            // If the navigation node is in the pre-defined list of nodes that should be added by default in the
            // "more" menu or the maximum limit of displayed navigation nodes has been reached (if defined).
            if (in_array($child->key, $defaultmoremenunodes) ||
                    (!is_null($maxdisplayednodes) && $displayednodescount >= $maxdisplayednodes)) {
                // Force the node and its children into the "more" menu.
                $child->set_force_into_more_menu(true);
                continue;
            }
            $displayednodescount++;
        }
    }

    /**
     * Remove navigation nodes that should not be displayed in the secondary navigation.
     */
    protected function remove_unwanted_nodes() {
        foreach ($this->children as $child) {
            if (!$child->showinsecondarynavigation) {
                $child->remove();
            }
        }
    }
}

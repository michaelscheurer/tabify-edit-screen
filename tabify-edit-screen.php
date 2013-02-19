<?php
/*
Plugin Name: Tabify edit screen
Plugin URI: http://wp-rockstars.com/plugin/tabify-edit-screen
Description: Enables tabs in the edit screen and manage them from the back-end
Author: Marko Heijnen
Text Domain: tabify-edit-screen
Version: 0.6-beta
Author URI: http://markoheijnen.com
*/

class Tabify_Edit_Screen {
	public  $version = '0.6';
	public  $admin;
	private $editscreen_tabs;
	private $tab_location = 'default';

	function __construct() {
		if( is_admin() ) {
			add_action( 'plugins_loaded', array( $this, 'load_translation' ) );
		}
	}

	function load_translation() {
		include 'inc/admin.php';
		include 'inc/tabs.php';

		$this->admin = new Tabify_Edit_Screen_Admin();

		add_action( 'admin_menu', array( $this->admin, 'admin_menu' ) );

		add_filter( 'redirect_post_location', array( $this, 'redirect_add_current_tab' ), 10, 2 );

		add_action( 'admin_head', array( $this, 'show_tabs' ), 10 );

		load_plugin_textdomain( 'tabify-edit-screen', false, basename( dirname( __FILE__ ) ) . '/languages' );
	}

	/**
	 * When a post is saved let it return to the current selected tab.
	 *
	 * @param string $location The location the user will be sent to
	 * @param int $post_id The post id
	 * @return string $location The new location the user will be sent to
	 *
	 * @since 0.2
	 *
	 */
	function redirect_add_current_tab( $location, $post_id ) {
		if( isset( $_REQUEST['tab'] ) ) {
			$location =  add_query_arg( 'tab', esc_attr( $_REQUEST['tab'] ), $location );
		}
		return $location;
	}

	/**
	 * Show the tabs on the edit screens.
	 * This will load the tab class, tab options and actions
	 * It will also will add the required classes to all the metaboxes
	 *
	 * @since 0.1
	 *
	 */
	function show_tabs() {
		global $wp_meta_boxes;

		$screen = get_current_screen();

		if( 'post' == $screen->base ) {
			$this->tab_location = apply_filters( 'tabify_tab_location', $this->tab_location, 'posttype' );

			$post_type = $screen->post_type;
			$options   = get_option( 'tabify-edit-screen', array() );

			if( isset( $options['posttypes'] ) )
				$options = $options['posttypes'];

			// This posttype has tabs
			if( isset( $options[ $post_type ], $options[ $post_type ]['show'] ) && $options[ $post_type ]['show'] == 1 ) {
				add_filter( 'admin_body_class', array( $this, 'add_admin_body_class' ) );
				add_action( 'admin_print_footer_scripts', array( $this, 'generate_javascript' ), 9 );

				$this->editscreen_tabs = new Tabify_Edit_Screen_Tabs( $options[ $post_type ]['tabs'] );
				$default_metaboxes     = Tabify_Edit_Screen_Settings_Posttypes::get_default_items( $post_type );
				$all_metaboxes         = array();

				foreach( $wp_meta_boxes[ $post_type ] as $priorities )
					foreach( $priorities as $priority => $_metaboxes )
						foreach( $_metaboxes as $metabox )
							if( ! in_array( $metabox['id'], $default_metaboxes ) )
								$all_metaboxes[ $metabox['id'] ] = $metabox['title'];

				$this->load_tabs();

				foreach( $options[ $post_type ]['tabs'] as $tab_index => $tab ) {
					$class = 'tabifybox tabifybox-' . $tab_index;

					if( $this->editscreen_tabs->get_current_tab() != $tab_index )
						$class .= ' tabifybox-hide';

					// Backwards compatibily from 0.5 to 0.6
					if( ! isset( $tab['items'] ) && isset( $tab['metaboxes'] ) )
						$tab['items'] = $tab['metaboxes'];


					if( isset( $tab['items'] ) ) {
						foreach( $tab['items'] as $metabox_id_fallback => $metabox_id ) {
							if( intval( $metabox_id_fallback ) == 0 && $metabox_id_fallback !== 0 )
								$metabox_id = $metabox_id_fallback;

							if( ! in_array( $metabox_id, $default_metaboxes ) ) {
								if( $metabox_id == 'titlediv' || $metabox_id == 'postdivrich' ) {
									$func = create_function('', 'echo "jQuery(\"#' . $metabox_id . '\").addClass(\"' . $class . '\");";');
									add_action( 'tabify_custom_javascript' , $func );
								}
								else {
									$func = create_function( '$args', 'array_push( $args, "' . $class . '" ); return $args;' );
									add_action( 'postbox_classes_' . $post_type . '_' . $metabox_id, $func );

									if( isset( $all_metaboxes[ $metabox_id ] ) )
										unset( $all_metaboxes[ $metabox_id ] );
								}
							}
						}
					}
				}

				// Metaboxes that aren't attachted
				if( apply_filters( 'tabify_show_unattached_metaboxes', true ) ) {
					foreach( $all_metaboxes as $metabox_id => $metabox_title ) {
						$func = create_function( '$args', 'array_push( $args, "' . $class . '" ); return $args;' );
						add_action( 'postbox_classes_' . $post_type . '_' . $metabox_id, $func );
					}
				}
			}
		}
	}

	function add_admin_body_class( $body ) {
		if( $this->tab_location )
			$body .= ' tabify_tab' . $this->tab_location;

		return $body;
	}

	/**
	 * Check where tabs should be loaded and fire the right action and callback for it
	 *
	 * @since 0.5
	 *
	 */
	private function load_tabs() {
		if( 'after_title' == $this->tab_location )
			add_action( 'edit_form_after_title', array( $this, 'output_tabs' ), 9 );
		else { //default
			$tabs = $this->editscreen_tabs->get_tabs_with_container();
			$func = create_function('', 'echo "$(\'#post\').prepend(\'' . addslashes( $tabs ) . '\');";');
			add_action( 'tabify_custom_javascript' , $func );
		}
	}

	/**
	 * Outputs the tabs
	 *
	 * @since 0.5
	 *
	 */
	function output_tabs() {
		echo $this->editscreen_tabs->get_tabs_with_container( false );
	}

	/**
	 * Generate the javascript for the edit screen
	 *
	 * @since 0.1
	 *
	 */
	function generate_javascript() {
		echo '<script type="text/javascript">';
		echo 'jQuery(function($) {';
		do_action( 'tabify_custom_javascript' );
		echo '});';
		echo '</script>';
	}
}


$tabify_edit_screen = new Tabify_Edit_Screen();
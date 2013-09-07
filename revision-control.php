<?php
/*
Plugin Name: Revision Control
Plugin URI: http://dd32.id.au/wordpress-plugins/revision-control/
Description: Allows finer control over the number of Revisions stored on a global & per-type/page basis.
Author: Dion Hulse
Version: 2.2
*/

$GLOBALS['revision_control'] = new Plugin_Revision_Control();
class Plugin_Revision_Control {

	const UNLIMITED_REVISIONS = -1;
	const NO_REVISIONS = 0;

	var $version = '2.2';

	var $options = array(
		'per-type' => array(
			'post' => -1, // UNLIMITED_REVISIONS
			'page' => -1, // UNLIMITED_REVISIONS
			'all'  => -1, // UNLIMITED_REVISIONS
		),
		'revision-range' => '2..5,10,20,50,100',
	);
	var $options_loaded = false;

	function __construct() {
		// Register general hooks.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		// Limit the Revisions
		add_action( 'wp_revisions_to_keep', array( $this, 'revisions_to_keep' ), 10, 2 );
	}

	function load_translations() {
		// Load any translations.
		load_plugin_textdomain( 'revision-control', false, dirname( plugin_basename( __FILE__ ) ) . '/langs/' );
	}
	
	function admin_init() {
		$this->load_options();
		$this->load_translations();

		wp_register_script( 'revision-control', plugins_url( 'revision-control.js', __FILE__ ), array( 'jquery' ), $this->version );

		// Add the UI
		add_action( 'post_submitbox_misc_actions', array( 'Plugin_Revision_Control_UI', 'publish_misc_settings' ) );

		// Add post handlers.
		add_action('admin_post_revision-control-options', array('Plugin_Revision_Control_Ajax', 'save_options'));
		add_action('save_post', array(&$this, 'save_post'), 10, 2);
		
		// Version the terms.
		add_action('_wp_put_post_revision', array(&$this, 'version_terms') );
		// Delete the terms
		add_action('wp_delete_post_revision', array(&$this, 'delete_terms'), 10, 2 );

		// Version the postmeta
		//add_action('_wp_put_post_revision', array(&$this, 'version_postmeta') );
		// Postmeta deletion is handled by core.
	}
	
	function admin_menu() {
		add_options_page( __('Revision Control', 'revision-control'), __('Revisions', 'revision-control'), 'manage_options', 'revision-control', array('Plugin_Revision_Control_UI', 'admin_page'));
	}

	function enqueue_scripts() {
		wp_enqueue_script( 'revision-control' );
	}

	function save_post( $id, $post ) {

		if ( ! isset( $_REQUEST['_wp_nonce_revision_control'] ) || ! wp_verify_nonce( $_REQUEST['_wp_nonce_revision_control'], 'revision-control-save' ) )
			return;

		if ( ! isset($_POST['limit_revisions']) )
			return;

		$new = (int) $_POST['limit_revisions'];

		$id = 'revision' == $post->post_type ? $post->post_parent : $post->ID;
		$this->delete_old_revisions( $id, $new );

		update_post_meta( $id, '_revision-control', $new );
	}

	function revisions_to_keep( $num, $post ) {

		if ( ! $default = $this->option( $post->post_type, 'per-type' ) )
			$default = $this->option( 'all', 'per-type' );

		// Check to see if those post has a custom Revisions value:
		$post_specific = get_post_meta( $post->ID, '_revision-control', true );
		if ( '' == $post_specific )
			$post_specific = false;
		else if ( ! is_array( $post_specific ) )
			$post_specific = Plugin_Revision_Control_Compat::postmeta( $post_specific, $post );

		$limit_to = ( isset( $post_specific[0] ) && $post_specific[0] != '' ) ? $post_specific[0] : $default;

		if ( 'unlimited' == $limit_to )
			$num = Plugin_Revision_Control::UNLIMITED_REVISIONS;
		elseif ( 'never' == $limit_to )
			$num = Plugin_Revision_Control::NO_REVISIONS;
		elseif ( 'defaults' == $limit_to )
			$num = $default;
		elseif ( is_numeric( $limit_to ) )
			$num = $limit_to;

		return $num;
	}

	function delete_old_revisions( $id, $number ) {

		if ( $number == Plugin_Revision_Control::UNLIMITED_REVISIONS )
			return;

		$items = get_posts( array(
			'post_type'   => 'revision',
			'numberposts' => 1000,
			'post_parent' => $id,
			'post_status' => 'inherit',
			'order'       => 'ASC',
			'orderby'     => 'ID'
		) );

		while ( count( $items ) > $number ) {
			$item = array_shift( $items  );
			wp_delete_post_revision( $item->ID );
		}

	}
	
	function version_terms($revision_id) {
		// Attach all the terms from the parent to the revision.
		if ( ! $rev = get_post($revision_id) )
			return;
		if ( ! $post = get_post($rev->post_parent) )
			return;

		// Only worry about taxonomies which are specifically linked.
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$_terms = wp_get_object_terms( $post->ID, $taxonomy );
			$terms = array();
			foreach ( $_terms as $t )
				$terms[] = (int)$t->term_id;
			if ( ! empty( $terms ) )
				wp_set_object_terms( $revision_id, $terms, $taxonomy );
		}
	}

	function delete_terms($revision_id, $rev) {
		if ( ! $post = get_post($rev->post_parent) )
			return;

		// Delete the parent posts taxonomies from the revision.
		wp_delete_object_term_relationships($revision_id, get_object_taxonomies($post->post_type) );
	}

	function version_postmeta($revision_id) {
		// Attach all the postmeta from the parent to the revision.
		if ( ! $rev = get_post($revision_id) )
			return;
		if ( ! $post = get_post($rev->post_parent) )
			return;

		// Only worry about postmeta that are not internal wordpress items.

	}

	function load_options() {
		if ( $this->options_loaded )
			return;

		$original = $options = get_option('revision-control', array());
		$options = Plugin_Revision_Control_Compat::options($options); // Lets upgrade the options..
		if ( $options != $original ) // Update it if an upgrade has taken place.
			update_option('revision-control', $options);

		$this->options = array_merge($this->options, $options); // Some default options may be set here, unless the user modifies them
	}
	
	function option($key, $bucket = false, $default = false ) {
		if ( ! $this->options_loaded )
			$this->load_options();

		if ( $bucket )
			return isset($this->options[$bucket][$key]) ? $this->options[$bucket][$key] : $default;			
		else
			return isset($this->options[$key]) ? $this->options[$key] : $default;
	}

	function set_option($key, $value, $bucket = false) {
		if ( ! $this->options_loaded )
			$this->load_options();

		if ( $bucket )
			$this->options[$bucket][$key] = $value;
		else
			$this->options[$key] = $value;
		update_option('revision-control', $this->options);
	}

	function get_revision_limit_select_items($current = false) { 
		$items = array(
			Plugin_Revision_Control::UNLIMITED_REVISIONS => __('Unlimited number of Revisions', 'revision-control'),
			Plugin_Revision_Control::NO_REVISIONS => __('Do not store Revisions', 'revision-control')
		);

		$values = $this->option( 'revision-range', false, '5,10,20,50,100' );

		$values = preg_split( '/,\s*/', $values );

		foreach ( $values as $val ) {
			$val = trim($val);
			if ( preg_match('|^(\d+)\.\.(\d+)$|', $val, $matches) ) {
				foreach ( range( (int)$matches[1], (int)$matches[2] ) as $num )
					$items[ $num ] = sprintf( _n( 'Maximum %s Revision stored', 'Maximum %s Revisions stored', $num, 'revision-control' ), number_format_i18n($num) );
			} else if ( is_numeric($val) ) {
				$num = (int)$val;
				$items[ $num ] = sprintf( _n( 'Maximum %s Revision stored', 'Maximum %s Revisions stored', $num, 'revision-control' ), number_format_i18n($num) );
			}
		}

		if ( false != $current && is_numeric($current) && !isset($items[ $current ]) ) // Support for when the range changes and the global/per-post has changed since.
			$items[ $current ] = sprintf( _n( 'Maximum %s Revision stored', 'Maximum %s Revisions stored', $current, 'revision-control' ), number_format_i18n($current) );

		return $items;
	}
	
}

class Plugin_Revision_Control_Compat {

	static function postmeta( $meta, $post ) {

		$_meta = is_array( $meta ) ? $meta : array( $meta );

		if ( 'unlimited' === $_meta[0] )
			$_meta[0] = Plugin_Revision_Control::UNLIMITED_REVISIONS;
		elseif ( 'never' === $_meta[0] )
			$_meta[0] = Plugin_Revision_Control::NO_REVISIONS;

		if ( $_meta != $meta )
			update_post_meta( $post->ID, '_revision-control', $_meta );

		return $_meta;
	}

	static function options( $options ) {
		$_options = $options;
		if ( ! is_array($options) ) { // Upgrade from 1.0 to 1.1
			$options = array(
				'post' => $options,
				'page' => $options,
			);
		}

		if ( isset($options['post']) ) { // Upgrade from 1.1 to 2.0
			$options['per-type'] = array(
				'post' => $options['post'],
				'page' => $options['page'],
			);
			unset( $options['post'], $options['page'] );
		}
	
		// Move the strings back to Ints. just makes more sense!
		foreach ( $options['per-type'] as $type => $value ) {
			if ( 'unlimited' === $value )
				$options['per-type'][$type] = Plugin_Revision_Control::UNLIMITED_REVISIONS;
			elseif ( 'never' === $value )
				$options['per-type'][$type] = Plugin_Revision_Control::NO_REVISIONS;
			elseif ( is_numeric( $value ) )
				$options['per-type'][$type] = (int) $value;
			else
				$options['per-type'][$type] = Plugin_Revision_Control::UNLIMITED_REVISIONS;
		}

		return $options;
	}
}

class Plugin_Revision_Control_Ajax {
	static function save_options() {
		global $revision_control;
		check_Admin_referer('revision-control-options');

		$data = stripslashes_deep($_POST['options']);
		foreach ( $data as $option => $val ) {
			if ( is_string($val) ) // Option is the keyname
				 $revision_control->set_option($option, $val);
			elseif ( is_array($val) ) // Option is the bucket, key => val are the options in the group.
				foreach ( $val as $subkey => $subval ) 
					 $revision_control->set_option($subkey, $subval, $option);
		}
		wp_safe_redirect( add_query_arg('updated', 'true', wp_get_referer() ) );
	}
}

class Plugin_Revision_Control_UI {

	static function publish_misc_settings() {
		global $post, $revision_control;

		$revision_control->enqueue_scripts();

		echo '<div class="misc-pub-section misc-pub-revision-control hide-if-no-js hide-if-js">';
		echo '<span class="revisions-edit hide-if-no-js"><a href="#">' . __( 'Edit' ) . '</a>&nbsp;</span>';
		$_revisions_to_keep = $revision_control->revisions_to_keep( Plugin_Revision_Control::UNLIMITED_REVISIONS, $post );

		echo '<div class="hide-if-js" id="revisions-settings">';
			wp_nonce_field( 'revision-control-save', '_wp_nonce_revision_control' );

			echo '<label for="limit-revisions">';
			echo '<select name="limit_revisions" id="limit-revisions">';
			foreach ( $revision_control->get_revision_limit_select_items( $_revisions_to_keep ) as $val => $text ) {
				echo '<option value="' . esc_attr( $val ) . '" ' . selected( $_revisions_to_keep, $val, false ) . '>' . esc_html( $text ) . '</option>';
			}
			echo '</select>';
			echo '<a href="#" class="button hide-if-no-js">' . __( 'OK' ) . '</a>';
		echo '</div>';

		echo '</div>';
	}

	static function admin_page() {
		global $revision_control;

		echo "<div class='wrap'>";
		screen_icon('options-general');
		echo '<h2>' . __('Revision Control Options', 'revision-control') . '</h2>';
		echo '<h3>' . __('Default revision status for <em>Post Types</em>', 'revision-control') . '</h3>';
		
		if ( function_exists('post_type_supports') ) {
			$types = array();
			$_types = get_post_types();
			foreach ( $_types as $type ) {
				if ( post_type_supports($type, 'revisions') )
					$types[] = $type;
			}
		} else {
			$types = array('post', 'page');
		}

		echo '<form method="post" action="admin-post.php?action=revision-control-options">';
		wp_nonce_field('revision-control-options');

		echo '<table class="form-table">';
		echo '<tr valign="top">
				<th scope="row">' . __('Default Revision Status', 'revision-control') . '</th>
				<td><table>';
		foreach ( $types as $post_type ) {
			$post_type_name = $post_type;
			if ( !in_array( $post_type, array( 'post', 'page' ) ) ) {
				$pt = get_post_type_object($post_type);
				$post_type_name = $pt->label;
				unset($pt);
			} else {
				if ( 'post' == $post_type )
					$post_type_name = _n('Post', 'Posts', 5, 'revision-control');
				elseif ( 'page' == $post_type )
					$post_type_name = _n('Page', 'Pages', 5, 'revision-control');
			}

			echo '<tr><th style="width: auto;"><label for="options_per-type_' . esc_attr( $post_type ) . '"> <em>' . $post_type_name . '</em></label></th>';
			echo '<td align="left"><select name="options[per-type][' . esc_attr( $post_type ) . ']" id="options_per-type_' . esc_attr( $post_type ) . '">';
			$current = $revision_control->option( $post_type, 'per-type' );
			foreach ( $revision_control->get_revision_limit_select_items($current) as $option_val => $option_text ) {
				echo '<option value="' . esc_attr( $option_val ) . '"' . selected( $current, $option_val, false ) . '>' . esc_html( $option_text ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</table>';
		echo '
		</td>
		</tr>';
		echo '<tr>
		<th scope="row"><label for="options_revision-range">' . __('Revision Range', 'revision-control') . '</label></th>
				<td><textarea rows="2" cols="80" name="options[revision-range]" id="options_revision-range">' . esc_html($revision_control->option('revision-range')) . '</textarea><br />
				' . __('<em><strong>Note:</strong> This field is special. It controls what appears in the Revision Options <code>&lt;select&gt;</code> fields.<br />The basic syntax of this is simple, fields are seperated by comma\'s.<br /> A field may either be a number, OR a range.<br /> For example: <strong>1,5</strong> displays 1 Revision, and 5 Revisions. <strong>1..5</strong> on the other hand, will display 1.. 2.. 3.. 4.. 5.. Revisions.<br /> <strong>If in doubt, Leave this field alone.</strong></em>', 'revision-control') . '
				</td>
				</tr>';
		echo '</table>';
		submit_button( __('Save Changes', 'revision-control') );
		echo '
		</form>';
		echo '</div>';
	}
}

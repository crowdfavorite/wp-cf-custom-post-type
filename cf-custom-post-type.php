<?php
/*
Plugin Name: CF Custom Category Posts
Plugin URI: http://crowdfavorite.com
Description: Attaches custom post type posts to a category for later conditional display of those posts.
Version: 1
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

/*
## Long Description

** Not tested on WP < 2.7 **
** adding more post-types not yet tested **

This plugin serves the purpose of assigning posts a custom post type so that they can retain special content
but not show up in the normal flow of WordPress content display. These posts are retained for conditional
display at the discretion of the template designer.

Since wordpress only recognizes its own internal post-types some minor hackery had to be involved. To edit
the posts this plugin captures and changes the custom post-type to 'post' for before the post edit routine kicks
in so that the the default WordPress post edit screens can be used (no sense in re-inventing the wheel). 
Reassignment of the custom post-type happens at post-save.

Also, since the posts no longer show up in regular post listings a new management interface was needed to bridge
allow these posts to be edited as well as display the relationship of category to post for ease of use. The posts
are all managed from a new menu item in the Posts section of the Admin menu named Custom Posts.
*/


// Init/Setup

	add_action('init','cfcpt_init',1);
	
	/**
	 * Define our variables
	 * Filters: 
	 *	- custom_post_type_parent_cat: set the parent cat ID
	 * 	- custom_post_types: modify the custom post types
	 *
	 * default chooses between a learn-more and welcome post
	 * 	- learn-more for users not logged in
	 * 	- welcome for logged in users
	 */
	function cfcpt_init() {
		global $cfcpt_post_types, $cfcpt_parent_cat;
		
		// Special Category ID
		//$cfcpt_parent_cat = get_option('cfcpt_parent_cat'); // enable if development resumes on dynamic parent cat assignment
		$cfcpt_parent_cat = apply_filters('cfcpt_post_parents',array());
		
		// Allowed custom post types
		$cfcpt_post_types = apply_filters('cfcpt_post_types',array(
			'post-welcome' => 'Welcome',
			'post-learn-more' => 'Learn More'
		));
		
		// handle individual post creation
		if(isset($_GET['create']) && isset($_GET['create_in'])) {
			cfcpt_create_individual_post(strip_tags($_GET['create']),intval($_GET['create_in']));
			wp_redirect('edit.php?page=cf-custom-posts');
			exit;
		}
		
		// handle conversion of old post type structure
		if(current_user_can('edit_users') && isset($_GET['convert_old_posts'])) {
			cfcpt_convert_old_posts();
		}
	}
	
// Helpers
	
	/**
	 * Accessor function to get the custom post types
	 * Should be used by outside plugins/actions to get the custom post types
	 *
	 * @return array
	 */
	function cfcpt_get_types() {
		global $cfcpt_post_types;
		return $cfcpt_post_types;
	}
	
	/**
	 * Get the Custom Posts parent categories as an array of objects
	 * Uses wp_cache to return pre-pulled array if available
	 *
	 * @return object
	 */
	function cfcpt_get_parent_cats() {
		global $cfcpt_parent_cat, $blog_id;
		$blog_id = !is_null($blog_id) ? $blog_id : 1;
		
		$parent_cats = maybe_unserialize(wp_cache_get('cfcpt_parent_cats_'.$blog_id));
		if(is_array($parent_cats)) { return $parent_cats; }
		
		if(!is_array($cfcpt_parent_cat)) { $cfcpt_parent_cat = array($cfcpt_parent_cat); }
		$parent_cats = array();
		foreach($cfcpt_parent_cat as $cat) {
			$c = get_category($cat);
			$parent_cats[$c->slug] = $c; 
		}
		wp_cache_add('cfcpt_parent_cats_'.$blog_id,$parent_cats);
		return $parent_cats;
	}
	
	/**
	 * Get a list of all subcategories of our special parent category
	 * Uses wp_cache to return a pre-pulled array if available
	 * @TODO - broken with new multiple parent cats
	 * @return array
	 */
	function cfcpt_get_child_cats() {
		global $blog_id;
		$blog_id = !is_null($blog_id) ? $blog_id : 1;
		
		// attempt to pull from cache first
		$child_cats = maybe_unserialize(wp_cache_get('cfcpt_child_cats_'.$blog_id));
		if(is_array($child_cats)) { return $child_cats; }
		
		// build list of child cats
		$parents = cfcpt_get_parent_cats();
		$child_cats = array();
		foreach($parents as $parent) {
			$children = explode('/',get_category_children($parent->cat_ID));
			foreach($children as $child) {
				if(empty($child)) { continue; }
				$c = get_category($child);
				$child_cats[$parent->slug][$c->slug] = $c;
			}
		}
		wp_cache_add('cfcpt_child_cats_'.$blog_id,$child_cats);
		return $child_cats;
	}
	
	/**
	 * Quick function to check if a post is a custom post type
	 *
	 * @param object $post 
	 * @return bool
	 */
	function cfcpt_is_custom_post($post) {
		$types = cfcpt_get_types();
		if(!is_object($post)) { $post = get_post($post); }
		return array_key_exists($post->post_type, $types);
	}

// show appropriate post at head

	/**
	 * Generic output function to dump the title and content at once,
	 * mostly to serve as an implementation example and quick development tool
	 */
	function cfcpt_show_post() {
		$post = cfcpt_get_post();
		if(!is_null($post)) {
			echo '<div ',post_class(),'>'.
				 '<h2 class="entry-title custom-category-post-header">'.apply_filters('the_title',$post->post_title).'</h2>'.
				 '<div class="entry-content custom-category-post-content">',cfcpt_the_post($post),'</div>'.
				 '</div>';
		}
	}

	/**
	 * Template function to output our special content
	 * By default category templates don't call the_post or even include 'get_category_description()'
	 * so we'll just explicitly call this when needed
	 */
	function cfcpt_the_post($post=false) {
		if(!$post) { $post = cfcpt_get_post(); }
		if(!is_null($post)) {
			$post_content = apply_filters('the_content',$post->post_content);
			echo apply_filters('cfcpt_the_post',$post_content);
		}
	}

	/**
	 * Pick appropriate post to show at category page head
	 * Works off default premise of a 'learn-more' and 'welcome' distinction
	 * filter on 'cfcpt_get_post' to handle alternate conditions
	 */
	function cfcpt_get_post() {
		global $wpdb;
		$category = get_the_category();
		$cat = $category[0];
		
		if(is_user_logged_in()) {
			$p = get_posts(array(
						'cat' => $cat->term_id,
						'post_type' => 'post-welcome',
						'limit' => 1,
						'post_status' => array('publish','draft')
					));
		}
		else {
			$p = get_posts(array(
						'cat' => $cat->term_id,
						'post_type' => 'post-learn-more',
						'limit' => 1,
						'post_status' => array('publish','draft')
					));			
		}

		return apply_filters('cfcpt_get_post',isset($p[0]) ? $p[0] : false);
	}

// Modify post_type for editing

	add_action('admin_init','cfcpt_admin_init');

	/**
	 * Admin init
	 *	- add submenu page & add jQuery
	 * 	- handle custom post type save
	 *	- add handler to post edit so custom post types can be edited
	 */
	function cfcpt_admin_init() {
		global $cfcpt_post_types, $cfcpt_parent_cat, $post, $pagenow, $page_vars;
		
		// post handler for adding cats and editing parent selection
		if(strtolower($_SERVER['REQUEST_METHOD']) == 'post' && isset($_POST['cfcpt_action'])) {
			switch($_POST['cfcpt_action']) {
				case 'new-cat':
					cfcpt_add_new_cat();
					break;
				case 'edit-parent':
					cfcpt_edit_parent_cat();
					break;
			}
		}
		
		// set filter configurable page text items
		$page_vars = apply_filters('cfcpt_admin_page_vars',array(
			'page_name' => $page_name,
			'page_description' => '<p>This is the admin page for adding special categories with hidden informational posts.</p>',
			'new_category_link' => 'Add new special category',
			'new_category_name_label' => 'Name:',
			'new_category_description_label' => 'Description',
			'category_table_header' => 'Category'
		));
		
		add_submenu_page('edit.php',$page_vars['page_name'],$page_vars['page_name'],'10','cf-custom-posts','cfcpt_list_page');
		wp_enqueue_script('jquery');
		
		// only worry about the rest when editing posts
		if($pagenow != 'post.php' || !isset($_GET['post'])) { return; }
		
		$post = get_post($_GET['post']);
		if(array_key_exists($post->post_type, $cfcpt_post_types)) {
			// make sure we have a placeholder post_type
			update_post_meta($post->ID,'_post_type',$post->post_type);
			// update for editing
			$post->post_type = 'post';
			wp_cache_replace($post->ID,$post,'posts');
			add_action('edit_form_advanced','cfcpt_meta_box');
		}
	}
	
// Clear out post edit screen of un-needed items

	/**
	 * Remove all but the essential meta boxes
	 */
	function cfcpt_clear_meta_boxes() {
		global $wp_meta_boxes;		
		$allowed_boxes = apply_filters('cfcpt_allowed_meta_boxes',array(
			'submitdiv',
			'tagsdiv',
			//'postexcerpt',
			'revisionsdiv'
		));
		foreach($wp_meta_boxes['post'] as $group_id => $group) {
			foreach($group as $priority_id => $priority) {				
				foreach($priority as $id => $box) {
					if(!in_array($id, $allowed_boxes)) {
						unset($wp_meta_boxes['post'][$group_id][$priority_id][$id]);
					}
				}
			}
		}
	}
	add_action('admin_head','cfcpt_clear_meta_boxes',9999);

// Save Post
	
	/**
	 * simple hidden element to help tracking this post during save actions
	 */
	function cfcpt_meta_box() {
		echo '
			<input type="hidden" name="cfcpt" value="1" />
			';
	}
	
	add_action('save_post','cfcpt_save_custom_post_type',999,2);

	/**
	 * Since wordpress will only process its own post-types we have to set this
	 * after the fact. Update the post directly in the DB
	 *
	 * @param int $post_id 
	 * @param object $post 
	 */
	function cfcpt_save_custom_post_type($post_id,$post) {
		global $wpdb;
		if(isset($_POST['cfcpt'])) {
			$post_type = get_post_meta($post_id,'_post_type',true);
			cfcpt_update_post($post_id,$post_type);
		}
	}
	
	/**
	 * Update a post with a different post type
	 *
	 * @param int $post_id 
	 * @param string $type 
	 */
	function cfcpt_update_post($post_id,$post_type) {
		global $wpdb;
		$query = "UPDATE {$wpdb->posts} SET post_type = '{$post_type}' WHERE ID = '{$post_id}'";
		return $wpdb->query($query);
	}


// Custom listing page so we can still find them
	
	/**
	 * Save handler for our custom category-addition form
	 * Uses same naming conventions and save routines as standard category add
	 * but doesn't mess with nonces and referer checks, only makes sure the user
	 * can manage categories
	 */
	function cfcpt_add_new_cat() {
		if (!current_user_can('manage_categories')) {
			wp_die(__('Cheatin&#8217; uh?'));
		}

		if($newcat_id = wp_insert_category($_POST)) {
			wp_redirect('edit.php?page=cf-custom-posts&status=add_success&new_cat='.$newcat_id);
		} 
		else {
			wp_redirect('edit.php?page=cf-custom-posts&status=add_failed');
		}
		exit;
	}
	
	/**
	 * Save handler for parent category
	 */
	function cfcpt_edit_parent_cat() {
		if (!current_user_can('manage_categories')) {
			wp_die(__('Cheatin&#8217; uh?'));
		}
		
		$parent_cat = intval($_POST['category_parent']);
		if(update_option('cfcpt_parent_cat',$parent_cat)) {
			wp_redirect('edit.php?page=cf-custom-posts&status=parent_success&new_cat='.$parent_cat);
		} 
		else {
			wp_redirect('edit.php?page=cf-custom-posts&status=parent_cat_failed');
		}
		exit;	
	}
	
	/**
	 * Show the special category admin page
	 * @TODO generalize javascipt for new sub-cat toggle
	 */
	function cfcpt_list_page() {
		global $cfcpt_post_types, $wpdb, $page_vars;
		
		// grab our parent category info
		$parent_cats = cfcpt_get_parent_cats();		
		extract($page_vars);
		
		echo '
			<div class="wrap cf-custom-posts">
				<h2>'.$page_name.'</h2>
				'.$page_description.'
			';
		foreach($parent_cats as $parent) {
			cfcpt_cat_table($parent);
		}
		// dynamic parent editing purposefully disabled... maybe if we get time in the future it can be built out
		if(current_user_can('edit_users') && isset($dynamic_parent_editing)) {
			echo '
				<p><small><a href="#" id="parent-cat-edit-toggle">Edit Parent Category</a></small></p>
				<form method="post" action="" id="special-cat-parent" style="display: none; margin-top: 10px">
					<fieldset>
						<p><b>Notice:</b> This changes the parent category that custom posts are looked for in. If you change this on a site that is already set up then you are most likely going to break the site. The change is non-destructive, so you can change it back and restore the previous state. You shouldn&rsquo;t be changing this unless you REALLY know what you&rsquo;re up to.</p>
						<div id="parent-cats-select-lists">';
			$i = 0;
			foreach($parent_cats as $key => $parent) {
				echo cfcpt_parent_cat_select($parent,++$i);
			}
			echo '				
						</div>
						<p><a href="#" id="add_new_parent_select">Add New Parent Cat</a></p>
						<input type="hidden" name="cfcpt_action" value="edit-parent" />
						<p class="submit"><input type="submit" name="submit" value="Update" /> | <a id="cancel-parent-cat-edit" href="#">Cancel</a>
					</fieldset>
				</form>
			';
		}
		echo '
			</div>
			<script type="text/javascript">
				//<![CDATA[
					jQuery(function() {
						// new cat form toggle, needs to be general
						jQuery("#new-cat-toggle,#cancel-new-special-cat").click(function(){
							if(this.id == "new-cat-toggle") {
								link_toggle = jQuery(this);
							}
							else {
								link_toggle = jQuery("#new-cat-toggle");
							}
							// show/hide
							jQuery("#new-special-cat").slideToggle("normal",function() {
								link_toggle.parents("p").slideToggle();
							});
							return false;
						});
						
						// parent cat form toggle, fine as is
						jQuery("#parent-cat-edit-toggle,#cancel-parent-cat-edit").click(function(){
							if(this.id == "parent-cat-edit-toggle") {
								link_toggle = jQuery(this);
							}
							else {
								link_toggle = jQuery("#parent-cat-edit-toggle");
							}
							// show/hide
							jQuery("#special-cat-parent").slideToggle("normal",function(){
								link_toggle.parents("p").slideToggle();
							});
							return false;
						});
					});
				//]]>
			</script>
			';
	}
	
	/**
	 * Build a new select dropdown
	 * Will build a new parent select dropdown
	 * If a category object is passed in that object's cat_ID will be used to pre-select that category in the dropdown
	 *
	 * @param object $parent - optional, currently selected parent object 
	 * @return html
	 */
	function cfcpt_parent_cat_select($parent=null,$i) {
		$selected = null;
		$args = array(
			'hide_empty' => 0, 
			'name' => 'category_parent[]', 
			'orderby' => 'name', 
			'hierarchical' => true, 
			'show_option_none' => __('None'),
			'echo' => 0
		);
		if(is_object($parent)) {
			$args['selected'] = $parent->cat_ID; 
			
		}
		return '<div id="parent-cat-'.$i.'"><label for="special_cat_parent_select">Parent Category '.$i.'</label>'.wp_dropdown_categories($args).'</div>';		
	}
	
	/**
	 * Return the HTML for a new parent cat select 
	 *
	 * @return void
	 */
	function cfcpt_get_new_parent_cat_select() {
		$num = intval($_POST['new_num']);
		echo urlencode(cfcpt_parent_cat_select(0,$num));
		exit;
	}
	
	/**
	 * Show a table of child categories
	 *
	 * @param string $parent_cat 
	 * @return void
	 */
	function cfcpt_cat_table($parent_cat) {
		global $cfcpt_post_types, $page_vars;
		
		// grab the special categories
		$cats = get_categories(array(
					'child_of' => $parent_cat->cat_ID,
					'hide_empty' => false
				));
		// prep
		extract($page_vars);
		$urlbase = trailingslashit(get_bloginfo('wpurl')).'wp-admin/';
		$categories_admin = $urlbase.'categories.php';
			
		if(current_user_can('manage_categories')) {
			echo '
				<br />
				<h3>'.$parent_cat->name.'</h3>
				<p><a id="new-cat-toggle" href="#">&raquo; '.$new_category_link.'</a></p>
				<form method="post" action="" id="new-special-cat" style="display: none">
					<fieldset>
						<div>
							<label for="new_special_cat_name">'.$new_category_name_label.'</label><br />
							<input name="cat_name" id="new_special_cat_name" size="50" value="" />
						</div>
						<div>
							<label for="new_special_cat_desc">'.$new_category_description_label.'</label><br />
							<textarea name="category_description" id="new_special_cat_desc" rows="5" cols="50"></textarea>
						</div> 
						<input type="hidden" name="category_parent" value="'.$parent_cat->cat_ID.'" />
						<input type="hidden" name="cfcpt_action" value="new-cat" />
						<p class="submit"><input type="submit" name="submit" value="Add New" /> | <a id="cancel-new-special-cat" href="#">Cancel</a>
					</fieldset>
				</form>
				';
		}
		echo '
				<table class="widefat post fixed cfcpt">
					<thead>
						<tr class="thead">
							<th>'.$category_table_header.'</th>';
		foreach($cfcpt_post_types as $type => $name) {
			echo '
							<th class="col-'.$type.'">'.$name.'</th>';
		}
		echo '
						</tr>
					</thead>
					<tbody>
			';
		if(count($cats)) {
			$i = 0;
			foreach($cats as $cat) {
				// output row
				echo '
							<tr>
								<td id="cat-'.$cat->term_id.'"><b><a href="'.
								$categories_admin.'?action=edit&amp;cat_ID='.$cat->cat_ID.'">'.$cat->name.'</a></b></td>
								';	
				// link to each custom post					
				foreach($cfcpt_post_types as $type => $name) {
					echo '<td>';
					$post = get_posts(array(
								'cat' => $cat->term_id,
								'post_type' => $type,
								'limit' => 1,
								'post_status' => array('publish','draft')
							));				
					if(!is_null($post[0])) {
						echo '
								<a href="'.$urlbase.'post.php?action=edit&post='.$post[0]->ID.'">'.$post[0]->post_title.'</a>
						 		<span style="font-size: .8em">('.$post[0]->post_status.')</span>
							';
					}
					else {
						echo '
								<b class="no-post-type">post removed?</b> <a href="'.
								$urlbase.'edit.php?page=cf-custom-posts&create='.$type.'&create_in='.$cat->term_id.'">Create Post</a>
							';
					}
					echo '</td>'; // add &nbsp; in case post is empty the cell still gets layout
				}
				echo '
							</tr>';
			}
		}
		else {
			echo '
						<tr>
							<td colspan="'.(count($cfcpt_post_types)+1).'">
								There are currently no special categories set up. <a href="'.$categories_admin.'">Click here</a> to add special categories.
							</td>
						</tr>
				';
		}
		echo '
					</tbody>
				</table>
			';		
	}
	
// Query filter to prevent pull on front end
	
	add_action('init','cfcpt_query_filter');
	
	/**
	 * Add our filter to wp_query to exclude our special post types
	 */
	function cfcpt_query_filter() {
		if(!is_admin()) {
			add_filter('posts_where_request','cfcpt_parse_query_filter');
		}
	}

	/**
	 * Excplicitly exclude our special post types from any and all wp_query routines
	 * This ensures that the posts only show up when we call for them directly
	 *
	 * @param string $where 
	 * @return string
	 */
	function cfcpt_parse_query_filter($where) {
		global $wpdb, $cfcpt_post_types;
				
		// build exclude list
		$post_type_exclude = array();
		foreach($cfcpt_post_types AS $type => $name) {
			$post_type_exclude[] = $wpdb->posts.".post_type != '{$type}'";
		}

		// add to where clause
		if(count($post_type_exclude)) {
			$where .= ' AND ('.implode(' AND ',$post_type_exclude).')';
		}
		return $where;
	}
	
// filter on save category to automatically make our special cat posts for population
	
	add_action('create_category','cfcpt_create_category_handler',10,2);
	
	/**
	 * Add our custom posts to wordpress.
	 * Uses the previously filtered value for 'custom_post_types' to generate relevant posts
	 *
	 * @param int $term_id 
	 * @param int $term_taxonomy_id 
	 */
	function cfcpt_create_category_handler($term_id, $term_taxonomy_id) {
		global $cfcpt_parent_cat, $cfcpt_post_types;

		$category = get_category($term_id);
		if($category->category_parent == $cfcpt_parent_cat) {
			foreach($cfcpt_post_types as $type => $name) {
				cfcpt_insert_post($type,$name,$category);
			}
		}
	}

	/**
	 * Insert a single post of a single type
	 *
	 * @param string $type 
	 * @param int $cat_id 
	 */
	function cfcpt_create_individual_post($type,$cat_id) {
		global $cfcpt_post_types;
		
		$category = get_category($cat_id);		
		cfcpt_insert_post($type,$cfcpt_post_types[$type],$category);		
	}
	
	/**
	 * Create a special post with specified parameters
	 *
	 * @param string $type
	 * @param string $name
	 * @param object $category 
	 */
	function cfcpt_insert_post($type,$name,$category) {
		$post = array(
			'post_content' => 'This is the <b>'.$name.' post</b> in category <b>"'.$category->name.'"</b>. Please change this content.',
			'post_title' => $category->name.' - '.$name,
			'post_status' => 'draft',
			'post_name' => $category->name.'-'.$type,
			'comment_status' => 'closed',
			'ping_status' => 'closed',
			'post_type' => $type,
			'post_category' => array($category->cat_ID)
		);
		$post_id = wp_insert_post($post);		
		update_post_meta($post_id,'_post_type',$type);
	}

// function to convert old, tagged, posts to new, post-type, posts. Becomes obsolete after OC conversion

	function cfcpt_convert_old_posts() {
		// for the ease of accuracy we'll do this in two passes
		// one for learn-more, one for welcome

		// learn more
		$args = array(
			'showposts' => -1,
			'tag' => 'learn-more'
		);
		$learn_posts = new WP_Query($args);
		foreach($learn_posts->posts as $p) {
			cfcpt_update_post($p->ID,'post-learn-more');
		}

		// welcome
		$args['tag'] = 'welcome';
		$welcome_posts = new WP_Query($args);
		foreach($welcome_posts->posts as $p) {
			cfcpt_update_post($p->ID,'post-welcome');
		}
	}
?>
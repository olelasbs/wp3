<?php

defined( 'ABSPATH' ) or die;

$GLOBALS['processed_terms'] = array();
$GLOBALS['processed_posts'] = array();

require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

function themify_import_post( $post ) {
	global $processed_posts, $processed_terms;

	if ( ! post_type_exists( $post['post_type'] ) ) {
		return;
	}

	/* Menu items don't have reliable post_title, skip the post_exists check */
	if( $post['post_type'] !== 'nav_menu_item' ) {
		$post_exists = post_exists( $post['post_title'], '', $post['post_date'] );
		if ( $post_exists && get_post_type( $post_exists ) == $post['post_type'] ) {
			$processed_posts[ intval( $post['ID'] ) ] = intval( $post_exists );
			return;
		}
	}

	if( $post['post_type'] == 'nav_menu_item' ) {
		if( ! isset( $post['tax_input']['nav_menu'] ) || ! term_exists( $post['tax_input']['nav_menu'], 'nav_menu' ) ) {
			return;
		}
		$_menu_item_type = $post['meta_input']['_menu_item_type'];
		$_menu_item_object_id = $post['meta_input']['_menu_item_object_id'];

		if ( 'taxonomy' == $_menu_item_type && isset( $processed_terms[ intval( $_menu_item_object_id ) ] ) ) {
			$post['meta_input']['_menu_item_object_id'] = $processed_terms[ intval( $_menu_item_object_id ) ];
		} else if ( 'post_type' == $_menu_item_type && isset( $processed_posts[ intval( $_menu_item_object_id ) ] ) ) {
			$post['meta_input']['_menu_item_object_id'] = $processed_posts[ intval( $_menu_item_object_id ) ];
		} else if ( 'custom' != $_menu_item_type ) {
			// associated object is missing or not imported yet, we'll retry later
			// $missing_menu_items[] = $item;
			return;
		}
	}

	$post_parent = ( $post['post_type'] == 'nav_menu_item' ) ? $post['meta_input']['_menu_item_menu_item_parent'] : (int) $post['post_parent'];
	$post['post_parent'] = 0;
	if ( $post_parent ) {
		// if we already know the parent, map it to the new local ID
		if ( isset( $processed_posts[ $post_parent ] ) ) {
			if( $post['post_type'] == 'nav_menu_item' ) {
				$post['meta_input']['_menu_item_menu_item_parent'] = $processed_posts[ $post_parent ];
			} else {
				$post['post_parent'] = $processed_posts[ $post_parent ];
			}
		}
	}

	/**
	 * for hierarchical taxonomies, IDs must be used so wp_set_post_terms can function properly
	 * convert term slugs to IDs for hierarchical taxonomies
	 */
	if( ! empty( $post['tax_input'] ) ) {
		foreach( $post['tax_input'] as $tax => $terms ) {
			if( is_taxonomy_hierarchical( $tax ) ) {
				$terms = explode( ', ', $terms );
				$post['tax_input'][ $tax ] = array_map( 'themify_get_term_id_by_slug', $terms, array_fill( 0, count( $terms ), $tax ) );
			}
		}
	}

	$post['post_author'] = (int) get_current_user_id();
	$post['post_status'] = 'publish';

	$old_id = $post['ID'];

	unset( $post['ID'] );
	$post_id = wp_insert_post( $post, true );
	if( is_wp_error( $post_id ) ) {
		return false;
	} else {
		$processed_posts[ $old_id ] = $post_id;

		if( isset( $post['has_thumbnail'] ) && $post['has_thumbnail'] ) {
			$placeholder = themify_get_placeholder_image();
			if( ! is_wp_error( $placeholder ) ) {
				set_post_thumbnail( $post_id, $placeholder );
			}
		}

		return $post_id;
	}
}

function themify_get_placeholder_image() {
	static $placeholder_image = null;

	if( $placeholder_image == null ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		$upload = wp_upload_bits( $post['post_name'] . '.jpg', null, $wp_filesystem->get_contents( THEMIFY_DIR . '/img/image-placeholder.jpg' ) );

		if ( $info = wp_check_filetype( $upload['file'] ) )
			$post['post_mime_type'] = $info['type'];
		else
			return new WP_Error( 'attachment_processing_error', __( 'Invalid file type', 'themify' ) );

		$post['guid'] = $upload['url'];
		$post_id = wp_insert_attachment( $post, $upload['file'] );
		wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $upload['file'] ) );

		$placeholder_image = $post_id;
	}

	return $placeholder_image;
}

function themify_import_term( $term ) {
	global $processed_terms;

	if( $term_id = term_exists( $term['slug'], $term['taxonomy'] ) ) {
		if ( is_array( $term_id ) ) $term_id = $term_id['term_id'];
		if ( isset( $term['term_id'] ) )
			$processed_terms[ intval( $term['term_id'] ) ] = (int) $term_id;
		return (int) $term_id;
	}

	if ( empty( $term['parent'] ) ) {
		$parent = 0;
	} else {
		$parent = term_exists( $term['parent'], $term['taxonomy'] );
		if ( is_array( $parent ) ) $parent = $parent['term_id'];
	}

	$id = wp_insert_term( $term['name'], $term['taxonomy'], array(
		'parent' => $parent,
		'slug' => $term['slug'],
		'description' => $term['description'],
	) );
	if ( ! is_wp_error( $id ) ) {
		if ( isset( $term['term_id'] ) ) {
			$processed_terms[ intval($term['term_id']) ] = $id['term_id'];
			return $term['term_id'];
		}
	}

	return false;
}

function themify_get_term_id_by_slug( $slug, $tax ) {
	$term = get_term_by( 'slug', $slug, $tax );
	if( $term ) {
		return $term->term_id;
	}

	return false;
}

function themify_undo_import_term( $term ) {
	$term_id = term_exists( $term['slug'], $term['taxonomy'] );
	if ( $term_id ) {
		if ( is_array( $term_id ) ) $term_id = $term_id['term_id'];
		if ( isset( $term_id ) ) {
			wp_delete_term( $term_id, $term['taxonomy'] );
		}
	}
}

/**
 * Determine if a post exists based on title, content, and date
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param array $args array of database parameters to check
 * @return int Post ID if post exists, 0 otherwise.
 */
function themify_post_exists( $args = array() ) {
	global $wpdb;

	$query = "SELECT ID FROM $wpdb->posts WHERE 1=1";
	$db_args = array();

	foreach ( $args as $key => $value ) {
		$value = wp_unslash( sanitize_post_field( $key, $value, 0, 'db' ) );
		if( ! empty( $value ) ) {
			$query .= ' AND ' . $key . ' = %s';
			$db_args[] = $value;
		}
	}

	if ( !empty ( $args ) )
		return (int) $wpdb->get_var( $wpdb->prepare($query, $args) );

	return 0;
}

function themify_undo_import_post( $post ) {
	if( $post['post_type'] == 'nav_menu_item' ) {
		$post_exists = themify_post_exists( array(
			'post_name' => $post['post_name'],
			'post_modified' => $post['post_date'],
			'post_type' => 'nav_menu_item',
		) );
	} else {
		$post_exists = post_exists( $post['post_title'], '', $post['post_date'] );
	}
	if( $post_exists && get_post_type( $post_exists ) == $post['post_type'] ) {
		/**
		 * check if the post has been modified, if so leave it be
		 *
		 * NOTE: posts are imported using wp_insert_post() which modifies post_modified field
		 * to be the same as post_date, hence to check if the post has been modified,
		 * the post_modified field is compared against post_date in the original post.
		 */
		if( $post['post_date'] == get_post_field( 'post_modified', $post_exists ) ) {
			wp_delete_post( $post_exists, true ); // true: bypass trash
		}
	}
}

function themify_do_demo_import() {

	if ( isset( $GLOBALS["ThemifyBuilder_Data_Manager"] ) ) {
		remove_action( "save_post", array( $GLOBALS["ThemifyBuilder_Data_Manager"], "save_builder_text_only"), 10, 3 );
	}
$term = array (
  'term_id' => 2,
  'name' => 'Blog',
  'slug' => 'blog',
  'term_group' => 0,
  'taxonomy' => 'category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 4,
  'name' => 'News',
  'slug' => 'news',
  'term_group' => 0,
  'taxonomy' => 'category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 5,
  'name' => 'Sports',
  'slug' => 'sports',
  'term_group' => 0,
  'taxonomy' => 'category',
  'description' => '',
  'parent' => 4,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 8,
  'name' => 'Video',
  'slug' => 'video',
  'term_group' => 0,
  'taxonomy' => 'category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 9,
  'name' => 'World',
  'slug' => 'world',
  'term_group' => 0,
  'taxonomy' => 'category',
  'description' => '',
  'parent' => 4,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 10,
  'name' => 'Culture',
  'slug' => 'culture',
  'term_group' => 0,
  'taxonomy' => 'category',
  'description' => '',
  'parent' => 4,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 12,
  'name' => 'Lifestyle',
  'slug' => 'lifestyle',
  'term_group' => 0,
  'taxonomy' => 'category',
  'description' => '',
  'parent' => 4,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 59,
  'name' => 'Uncategorized',
  'slug' => 'uncategorized',
  'term_group' => 0,
  'taxonomy' => 'team-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 31,
  'name' => 'Team',
  'slug' => 'team',
  'term_group' => 0,
  'taxonomy' => 'testimonial-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 32,
  'name' => 'Testimonials',
  'slug' => 'testimonials',
  'term_group' => 0,
  'taxonomy' => 'testimonial-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 60,
  'name' => 'Uncategorized',
  'slug' => 'uncategorized',
  'term_group' => 0,
  'taxonomy' => 'testimonial-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 20,
  'name' => 'Galleries',
  'slug' => 'galleries',
  'term_group' => 0,
  'taxonomy' => 'gallery-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 41,
  'name' => 'Home Section',
  'slug' => 'home-section',
  'term_group' => 0,
  'taxonomy' => 'gallery-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 25,
  'name' => 'Photos',
  'slug' => 'photos',
  'term_group' => 0,
  'taxonomy' => 'portfolio-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 34,
  'name' => 'Videos',
  'slug' => 'videos',
  'term_group' => 0,
  'taxonomy' => 'portfolio-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 35,
  'name' => 'Vintage',
  'slug' => 'vintage',
  'term_group' => 0,
  'taxonomy' => 'portfolio-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 61,
  'name' => 'Featured',
  'slug' => 'featured',
  'term_group' => 0,
  'taxonomy' => 'portfolio-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 46,
  'name' => 'Demo 3 Menu',
  'slug' => 'demo-3-menu',
  'term_group' => 0,
  'taxonomy' => 'nav_menu',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 49,
  'name' => 'Home Menu',
  'slug' => 'home-menu',
  'term_group' => 0,
  'taxonomy' => 'nav_menu',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 53,
  'name' => 'Demo 2 Menu',
  'slug' => 'demo-2-menu',
  'term_group' => 0,
  'taxonomy' => 'nav_menu',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 67,
  'name' => 'Main Menu',
  'slug' => 'main-menu',
  'term_group' => 0,
  'taxonomy' => 'nav_menu',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$post = array (
  'ID' => 1828,
  'post_date' => '2008-06-26 01:21:13',
  'post_date_gmt' => '2008-06-26 01:21:13',
  'post_content' => 'Aliquam mattis mauris a sapien tincidunt, ac vestibulum urna porta. Aenean aliquet vulputate lacus vel venenatis. Etiam lorem sapien, vestibulum ut nisl sed, egestas dignissim enim.',
  'post_title' => 'From the Marathon',
  'post_excerpt' => '',
  'post_name' => 'from-the-marathon',
  'post_modified' => '2017-08-21 05:38:50',
  'post_modified_gmt' => '2017-08-21 05:38:50',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1828',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'category' => 'sports',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1833,
  'post_date' => '2008-06-26 01:30:51',
  'post_date_gmt' => '2008-06-26 01:30:51',
  'post_content' => 'Etiam lorem sapien, vestibulum ut nisl sed, egestas dignissim enim. Nam lacus massa, pellentesque eget pulvinar vitae, sagittis eget justo. Maecenas bibendum sit amet odio et sodales. Praesent cursus mattis tortor, ut vestibulum purus venenatis at.',
  'post_title' => 'Watercolor',
  'post_excerpt' => '',
  'post_name' => 'watercolor',
  'post_modified' => '2017-08-21 05:38:48',
  'post_modified_gmt' => '2017-08-21 05:38:48',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1833',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'category' => 'culture',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1836,
  'post_date' => '2008-06-26 01:31:26',
  'post_date_gmt' => '2008-06-26 01:31:26',
  'post_content' => 'Cras tristique feugiat neque sed vestibulum. Sed eu urna quis lacus aliquet fermentum vel sed risus. Integer laoreet pretium interdum. Proin consequat consequat feugiat. Integer pellentesque faucibus aliquet.',
  'post_title' => 'Living Art',
  'post_excerpt' => '',
  'post_name' => 'living-art',
  'post_modified' => '2017-08-21 05:38:46',
  'post_modified_gmt' => '2017-08-21 05:38:46',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1836',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'category' => 'culture',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1839,
  'post_date' => '2008-06-26 01:33:00',
  'post_date_gmt' => '2008-06-26 01:33:00',
  'post_content' => 'In convallis quis est fermentum sollicitudin. Phasellus nec purus elit. Aenean tempus tincidunt dolor, quis auctor diam auctor non. Quisque at fermentum purus, a aliquet arcu.',
  'post_title' => 'Long Exposures',
  'post_excerpt' => '',
  'post_name' => 'long-exposures',
  'post_modified' => '2017-08-21 05:38:44',
  'post_modified_gmt' => '2017-08-21 05:38:44',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1839',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'category' => 'culture',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1845,
  'post_date' => '2008-06-26 01:36:35',
  'post_date_gmt' => '2008-06-26 01:36:35',
  'post_content' => 'Donec hendrerit, lectus in dapibus consequat, libero arcu dignissim turpis, id dictum odio felis eget ante. In ullamcorper pulvinar rutrum. In id neque pulvinar, tempor orci ac, tincidunt libero. Fusce ultricies arcu at mauris semper bibendum.',
  'post_title' => 'Cooking Courses',
  'post_excerpt' => '',
  'post_name' => 'cooking-courses',
  'post_modified' => '2017-08-21 05:38:42',
  'post_modified_gmt' => '2017-08-21 05:38:42',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1845',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'category' => 'world',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1849,
  'post_date' => '2008-06-26 01:38:43',
  'post_date_gmt' => '2008-06-26 01:38:43',
  'post_content' => 'Phasellus dui erat, tincidunt pulvinar tempor at, lacinia eu lacus. Aenean euismod tellus laoreet turpis viverra facilisis. Nunc eu viverra eros, et facilisis dui. Sed pretium id risus eu tincidunt.',
  'post_title' => 'Maritime Shipping',
  'post_excerpt' => '',
  'post_name' => 'maritime-shipping',
  'post_modified' => '2017-08-21 05:38:40',
  'post_modified_gmt' => '2017-08-21 05:38:40',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1849',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'category' => 'world',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1852,
  'post_date' => '2008-06-26 01:42:25',
  'post_date_gmt' => '2008-06-26 01:42:25',
  'post_content' => 'In lobortis vehicula lectus, et venenatis velit euismod sit amet. Morbi egestas malesuada turpis, dictum consequat mauris scelerisque ac. Mauris luctus commodo lorem, pulvinar sollicitudin ante porttitor id.',
  'post_title' => 'Water Town',
  'post_excerpt' => '',
  'post_name' => 'water-town',
  'post_modified' => '2017-08-21 05:38:38',
  'post_modified_gmt' => '2017-08-21 05:38:38',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1852',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'category' => 'world',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1857,
  'post_date' => '2008-06-26 02:46:21',
  'post_date_gmt' => '2008-06-26 02:46:21',
  'post_content' => 'Nullam fringilla facilisis ultricies. Ut volutpat ultricies rutrum. In laoreet, nunc et auctor condimentum, enim lacus lacinia dolor, non accumsan leo nisl id lorem. Duis vehicula et turpis fringilla hendrerit.',
  'post_title' => 'Remote Places',
  'post_excerpt' => '',
  'post_name' => 'remote-places',
  'post_modified' => '2017-08-21 05:38:36',
  'post_modified_gmt' => '2017-08-21 05:38:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1857',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'category' => 'lifestyle',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1860,
  'post_date' => '2008-06-26 02:47:20',
  'post_date_gmt' => '2008-06-26 02:47:20',
  'post_content' => 'Duis eget tellus nisl. Donec porta orci vel iaculis porta. Vivamus aliquet, ligula et tempus mattis, tortor ipsum eleifend massa, ac gravida dui est quis dui.',
  'post_title' => 'Evening Rides',
  'post_excerpt' => '',
  'post_name' => 'evening-rides',
  'post_modified' => '2017-08-21 05:38:34',
  'post_modified_gmt' => '2017-08-21 05:38:34',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1860',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'category' => 'lifestyle',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1863,
  'post_date' => '2008-06-26 02:48:34',
  'post_date_gmt' => '2008-06-26 02:48:34',
  'post_content' => 'Proin vitae lectus eu turpis sollicitudin sagittis. Aliquam nunc odio, semper lacinia tincidunt a, dapibus vitae dolor. Class aptent taciti sociosqu ad litora torquent per conubia.',
  'post_title' => 'Learn Something New',
  'post_excerpt' => '',
  'post_name' => 'learn-something-new',
  'post_modified' => '2017-08-21 05:38:31',
  'post_modified_gmt' => '2017-08-21 05:38:31',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1863',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'category' => 'lifestyle',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1865,
  'post_date' => '2008-06-26 02:49:39',
  'post_date_gmt' => '2008-06-26 02:49:39',
  'post_content' => 'Vivamus pharetra magna fermentum tincidunt imperdiet. Aenean venenatis sollicitudin odio in ultrices. Proin a nibh at dolor rhoncus pulvinar. Nullam eget tincidunt enim.',
  'post_title' => 'Clean Air',
  'post_excerpt' => '',
  'post_name' => 'clean-air',
  'post_modified' => '2017-10-29 15:32:42',
  'post_modified_gmt' => '2017-10-29 15:32:42',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1865',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'lifestyle',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 79,
  'post_date' => '2013-07-12 06:15:51',
  'post_date_gmt' => '2013-07-12 06:15:51',
  'post_content' => 'Maecenas tincidunt congue purus. Donec fringilla felis vel dolor consectetur, vel gravida quam molestie. Curabitur ut orci a sapien feugiat auctor in sit amet nisl. Morbi justo metus, dapibus a dignissim a, accumsan sit amet odio.

Duis venenatis at diam sed aliquet. Nunc interdum odio et nibh euismod laoreet. Sed non ultrices dui, sit amet adipiscing libero. Maecenas accumsan quam eleifend quam facilisis, sit amet aliquet neque mollis. Cras sit amet sollicitudin sem. Sed tincidunt rhoncus urna a pretium. Interdum et malesuada fames ac ante ipsum primis in faucibus. Pellentesque malesuada accumsan ante ac imperdiet. Quisque eu elementum urna. Maecenas venenatis imperdiet enim at bibendum. Duis eget convallis felis, id sollicitudin mauris. Nam sem metus, sagittis non feugiat vel, porttitor eu arcu. Sed dictum, nulla ac laoreet accumsan, dui sapien vestibulum nibh, in pharetra dolor dui eu erat. Ut feugiat dictum egestas. Nam eget arcu quis mauris imperdiet pulvinar.',
  'post_title' => 'Classic Car on the Beach',
  'post_excerpt' => '',
  'post_name' => 'car',
  'post_modified' => '2017-10-29 15:32:40',
  'post_modified_gmt' => '2017-10-29 15:32:40',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?p=79',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'blog',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 82,
  'post_date' => '2013-07-12 06:24:06',
  'post_date_gmt' => '2013-07-12 06:24:06',
  'post_content' => 'Maecenas tincidunt congue purus. Donec fringilla felis vel dolor consectetur, vel gravida quam molestie. Curabitur ut orci a sapien feugiat auctor in sit amet nisl. Morbi justo metus, dapibus a dignissim a, accumsan sit amet odio.

Duis venenatis at diam sed aliquet. Nunc interdum odio et nibh euismod laoreet. Sed non ultrices dui, sit amet adipiscing libero. Maecenas accumsan quam eleifend quam facilisis, sit amet aliquet neque mollis. Cras sit amet sollicitudin sem. Sed tincidunt rhoncus urna a pretium. Interdum et malesuada fames ac ante ipsum primis in faucibus. Pellentesque malesuada accumsan ante ac imperdiet. Quisque eu elementum urna. Maecenas venenatis imperdiet enim at bibendum. Duis eget convallis felis, id sollicitudin mauris. Nam sem metus, sagittis non feugiat vel, porttitor eu arcu. Sed dictum, nulla ac laoreet accumsan, dui sapien vestibulum nibh, in pharetra dolor dui eu erat. Ut feugiat dictum egestas. Nam eget arcu quis mauris imperdiet pulvinar.',
  'post_title' => 'Meet My Best Friend',
  'post_excerpt' => '',
  'post_name' => 'meet-my-best-friend',
  'post_modified' => '2017-10-29 15:32:39',
  'post_modified_gmt' => '2017-10-29 15:32:39',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?p=82',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'blog',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 84,
  'post_date' => '2013-07-12 06:19:32',
  'post_date_gmt' => '2013-07-12 06:19:32',
  'post_content' => 'Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Fusce tristique placerat nisi et ultricies. Aliquam orci nisl, cursus vitae venenatis sit amet, laoreet in lacus. Integer ac ullamcorper sem, vel auctor ante. Interdum et malesuada fames ac ante ipsum primis in faucibus. Nulla mattis, erat sit amet pellentesque blandit, libero augue sollicitudin leo, a convallis diam purus sit amet nibh. Sed condimentum blandit nibh in semper.

Vestibulum dignissim rutrum porttitor. Curabitur lacinia, arcu sed sollicitudin semper, sem enim faucibus velit, non scelerisque enim justo et tortor. Phasellus accumsan iaculis augue, sit amet sodales mi egestas nec. Phasellus in sagittis ipsum. Morbi elementum magna et ligula tincidunt, sit amet vestibulum nibh posuere. Ut facilisis felis in tortor feugiat, ac pretium enim tempus. Praesent volutpat, lacus sed congue hendrerit, justo risus venenatis massa, non fringilla velit metus ut lacus. Maecenas tincidunt congue purus. Donec fringilla felis vel dolor consectetur, vel gravida quam molestie. Curabitur ut orci a sapien feugiat auctor in sit amet nisl. Morbi justo metus, dapibus a dignissim a, accumsan sit amet odio.',
  'post_title' => 'Miniature City',
  'post_excerpt' => '',
  'post_name' => 'miniature-city',
  'post_modified' => '2017-10-29 15:32:39',
  'post_modified_gmt' => '2017-10-29 15:32:39',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?p=84',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'blog',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1822,
  'post_date' => '2008-06-26 23:38:36',
  'post_date_gmt' => '2008-06-26 23:38:36',
  'post_content' => 'Donec auctor consectetur tellus, in hendrerit urna vulputate non. Ut elementum fringilla purus. Nam dui erat, porta eu gravida sit amet, ornare sit amet sem.',
  'post_title' => 'Dirt Championship',
  'post_excerpt' => '',
  'post_name' => 'dirt-championship',
  'post_modified' => '2017-10-29 15:32:41',
  'post_modified_gmt' => '2017-10-29 15:32:41',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1822',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'sports',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1893,
  'post_date' => '2008-06-26 21:19:12',
  'post_date_gmt' => '2008-06-26 21:19:12',
  'post_content' => 'Aliquam blandit, velit elementum bibendum dictum, est leo volutpat quam, id pellentesque nisl arcu quis purus. Pellentesque luctus lacus lorem, id ullamcorper dolor vestibulum id.',
  'post_title' => 'Views of the Burj Khalifa',
  'post_excerpt' => '',
  'post_name' => 'burj-khalifa',
  'post_modified' => '2017-10-29 15:32:41',
  'post_modified_gmt' => '2017-10-29 15:32:41',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?p=1893',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'video',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2161,
  'post_date' => '2013-07-16 18:51:39',
  'post_date_gmt' => '2013-07-16 18:51:39',
  'post_content' => 'Maecenas cursus urna vitae tellus egestas venenatis. Quisque hendrerit massa sit amet erat bibendum fringilla. Aenean quis arcu porta, consectetur mauris ut, mollis dui. Donec pharetra a quam vitae adipiscing.',
  'post_title' => 'Tandem',
  'post_excerpt' => '',
  'post_name' => 'tandem',
  'post_modified' => '2017-10-29 15:32:38',
  'post_modified_gmt' => '2017-10-29 15:32:38',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?p=2161',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'blog',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2164,
  'post_date' => '2013-07-16 18:53:03',
  'post_date_gmt' => '2013-07-16 18:53:03',
  'post_content' => 'Donec tincidunt et massa sit amet sodales. In cursus augue ac sem ornare, eu interdum odio volutpat. Donec odio quam, lacinia quis nibh at, bibendum fringilla ante.',
  'post_title' => 'Needed Vacation',
  'post_excerpt' => '',
  'post_name' => 'needed-vacation',
  'post_modified' => '2017-10-29 15:32:37',
  'post_modified_gmt' => '2017-10-29 15:32:37',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?p=2164',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'blog',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2167,
  'post_date' => '2013-07-16 18:59:11',
  'post_date_gmt' => '2013-07-16 18:59:11',
  'post_content' => 'Donec id lectus sed risus fermentum auctor. In fringilla nulla tincidunt congue vulputate. Donec auctor risus ut elit pretium, ultrices iaculis velit interdum.',
  'post_title' => 'Vegetable Fun',
  'post_excerpt' => '',
  'post_name' => 'vegetable-fun',
  'post_modified' => '2017-10-29 15:32:36',
  'post_modified_gmt' => '2017-10-29 15:32:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?p=2167',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'blog',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2178,
  'post_date' => '2013-07-16 22:05:22',
  'post_date_gmt' => '2013-07-16 22:05:22',
  'post_content' => 'Pellentesque ipsum nisi, rhoncus dictum magna at, adipiscing commodo magna. Aenean accumsan erat a lacus semper, nec vulputate magna euismod. Maecenas a lacus rhoncus, ullamcorper sem consectetur, mollis lacus.',
  'post_title' => 'The Canyon',
  'post_excerpt' => '',
  'post_name' => 'the-canyon',
  'post_modified' => '2017-10-29 15:32:36',
  'post_modified_gmt' => '2017-10-29 15:32:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?p=2178',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'blog',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2930,
  'post_date' => '2014-12-16 16:14:32',
  'post_date_gmt' => '2014-12-16 16:14:32',
  'post_content' => '<!--themify_builder_static--><h1>Fullpane Theme</h1> 
 <h4>by Themify</h4> 
 <a href="https://themify.me/demo/themes/fullpane/home/demo-2/" >Features</a> <a href="https://themify.me/themes/fullpane" >Learn More</a> 
 <h1>Horizontal Scrolling</h1> 
 <h4>Can scroll vertical &amp; horizontal.</h4> 
 <h1>Hand Crafted</h1> 
 <h4>With love.</h4> 
 <h1>Drag &amp; Drop Builder</h1> 
 <h4>The possibility is limitless with Themify Builder.</h4> 
 <h1>Features</h1> 
 <h4>Gallery, Portfolio, Testimonial, Team, Testimonial, and more.</h4> 
 <a href="https://themify.me/demo/themes/fullpane/demo-2" >View Features Page</a> 
 <h1>Explore More</h1> 
 <a href="https://themify.me/demo/themes/fullpane/home/demo-2/" >DEMO 2</a> <a href="https://themify.me/demo/themes/fullpane/home/demo-3/" >DEMO 3</a><!--/themify_builder_static-->',
  'post_title' => 'Home',
  'post_excerpt' => '',
  'post_name' => 'home',
  'post_modified' => '2018-07-18 21:02:42',
  'post_modified_gmt' => '2018-07-18 21:02:42',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?page_id=2930',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'content_width' => 'full_width',
    'menu_bar_position' => 'menubar-top',
    'hide_page_title' => 'yes',
    'section_full_scrolling' => 'yes',
    'section_scrolling_mobile' => 'on',
    'display_content' => 'content',
    'portfolio_display_content' => 'content',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h1>Fullpane Theme<\\\\/h1>\\",\\"animation_effect\\":\\"fadeInLeft\\"}},{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h4>by Themify<\\\\/h4>\\",\\"animation_effect\\":\\"fadeInRight\\"}},{\\"mod_name\\":\\"buttons\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_top\\":\\"20\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"checkbox_padding_link_apply_all\\":\\"1\\",\\"checkbox_link_margin_apply_all\\":\\"1\\",\\"checkbox_link_border_apply_all\\":\\"1\\",\\"buttons_size\\":\\"normal\\",\\"buttons_style\\":\\"outline\\",\\"display\\":\\"buttons-horizontal\\",\\"content_button\\":[{\\"label\\":\\"Features\\",\\"link\\":\\"https://themify.me/demo/themes/fullpane\\\\/home\\\\/demo-2\\\\/\\",\\"link_options\\":\\"regular\\",\\"icon_alignment\\":\\"left\\"},{\\"label\\":\\"Learn More\\",\\"link\\":\\"https:\\\\/\\\\/themify.me\\\\/themes\\\\/fullpane\\",\\"link_options\\":\\"regular\\",\\"icon_alignment\\":\\"left\\"}],\\"animation_effect\\":\\"fadeInUp\\"}}]}],\\"styling\\":{\\"background_type\\":\\"image\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_video_options\\":\\"mute\\",\\"background_image\\":\\"https://themify.me/demo/themes/fullpane\\\\/files\\\\/2018\\\\/06\\\\/demoimage17-1024x651.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"font_color\\":\\"#ffffff\\",\\"text_align\\":\\"center\\",\\"link_color\\":\\"#a6fff2\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\"}},{\\"row_order\\":\\"1\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h1>Horizontal Scrolling<\\\\/h1>\\",\\"animation_effect\\":\\"fadeInLeft\\"}},{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h4>Can scroll vertical &amp; horizontal.<\\\\/h4>\\"}}]}],\\"styling\\":{\\"background_type\\":\\"image\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_video_options\\":\\"mute\\",\\"background_image\\":\\"https://themify.me/demo/themes/fullpane\\\\/files\\\\/2018\\\\/06\\\\/demoimage30-1024x683.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"font_color\\":\\"#ffffff\\",\\"text_align\\":\\"center\\",\\"link_color\\":\\"#a6fff2\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"row_scroll_direction\\":\\"module_row_slide\\"}},{\\"row_order\\":\\"2\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h1>Hand Crafted<\\\\/h1>\\",\\"animation_effect\\":\\"fadeInLeft\\"}},{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h4>With love.<\\\\/h4>\\"}}]}],\\"styling\\":{\\"background_type\\":\\"image\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_video_options\\":\\"mute\\",\\"background_image\\":\\"https://themify.me/demo/themes/fullpane\\\\/files\\\\/2018\\\\/06\\\\/demoimage36-1024x683.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"font_color\\":\\"#ffffff\\",\\"text_align\\":\\"center\\",\\"link_color\\":\\"#a6fff2\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"row_scroll_direction\\":\\"module_row_slide\\"}},{\\"row_order\\":\\"3\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h1>Drag &amp; Drop Builder<\\\\/h1>\\"}},{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h4>The possibility is limitless with Themify Builder.<\\\\/h4>\\"}}]}],\\"styling\\":{\\"background_type\\":\\"image\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_video_options\\":\\"mute\\",\\"background_image\\":\\"https://themify.me/demo/themes/fullpane\\\\/files\\\\/2018\\\\/06\\\\/demoimage42.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"font_color\\":\\"#ffffff\\",\\"text_align\\":\\"center\\",\\"link_color\\":\\"#a6fff2\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"row_scroll_direction\\":\\"module_row_section\\"}},{\\"row_order\\":\\"4\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_image-gradient-angle\\":\\"0\\",\\"background_repeat\\":\\"repeat\\",\\"text_align\\":\\"center\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h1>Features<\\\\/h1>\\"}},{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h4>Gallery, Portfolio, Testimonial, Team, Testimonial, and more.<\\\\/h4>\\"}},{\\"mod_name\\":\\"buttons\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_top\\":\\"20\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"checkbox_padding_link_apply_all\\":\\"1\\",\\"checkbox_link_margin_apply_all\\":\\"1\\",\\"checkbox_link_border_apply_all\\":\\"1\\",\\"buttons_size\\":\\"normal\\",\\"buttons_style\\":\\"outline\\",\\"display\\":\\"buttons-horizontal\\",\\"content_button\\":[{\\"label\\":\\"View Features Page\\",\\"link\\":\\"https://themify.me/demo/themes/fullpane\\\\/demo-2\\",\\"link_options\\":\\"regular\\",\\"icon_alignment\\":\\"left\\"}]}}]}],\\"styling\\":{\\"background_type\\":\\"image\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_image\\":\\"https://themify.me/demo/themes/fullpane\\\\/files\\\\/2018\\\\/06\\\\/demoimage66.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"background_color\\":\\"756f68\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"font_color\\":\\"e8e8e8\\",\\"text_align\\":\\"center\\",\\"link_color\\":\\"f49ac1\\",\\"padding_top\\":\\"3\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"3\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\"}},{\\"row_order\\":\\"5\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h1>Explore More<\\\\/h1>\\"}},{\\"mod_name\\":\\"buttons\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"checkbox_padding_link_apply_all\\":\\"1\\",\\"checkbox_link_margin_apply_all\\":\\"1\\",\\"checkbox_link_border_apply_all\\":\\"1\\",\\"buttons_size\\":\\"normal\\",\\"buttons_style\\":\\"outline\\",\\"display\\":\\"buttons-horizontal\\",\\"content_button\\":[{\\"label\\":\\"DEMO 2\\",\\"link\\":\\"https://themify.me/demo/themes/fullpane\\\\/home\\\\/demo-2\\\\/\\",\\"link_options\\":\\"regular\\"},{\\"label\\":\\"DEMO 3\\",\\"link\\":\\"https://themify.me/demo/themes/fullpane\\\\/home\\\\/demo-3\\\\/\\",\\"link_options\\":\\"regular\\"}]}}]}],\\"styling\\":{\\"background_type\\":\\"image\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_video_options\\":\\"mute\\",\\"background_image\\":\\"https://themify.me/demo/themes/fullpane\\\\/files\\\\/2018\\\\/06\\\\/demoimage59.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"font_color\\":\\"#ffffff\\",\\"text_align\\":\\"center\\",\\"link_color\\":\\"#a6fff2\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"row_scroll_direction\\":\\"module_row_section\\"}},{\\"row_order\\":\\"6\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2453,
  'post_date' => '2014-01-02 22:47:12',
  'post_date_gmt' => '2014-01-02 22:47:12',
  'post_content' => '<h3>List Post</h3>
[team style="list-post" limit="1"  image_w="100" image_h="100"]

[hr]
<h3>Grid2</h3>
[team style="grid2" limit="2" image_w="100" image_h="100"]

[hr]
<h3>Grid3</h3>
[team style="grid3" limit="3"  image_w="100" image_h="100"]

[hr]
<h3>Grid4</h3>
[team style="grid4" limit="4" image_w="100" image_h="100"]

[hr]
<h3>Slider</h3>
[team style="slider" limit="5" visible="3" image_w="100" image_h="100"]',
  'post_title' => 'Team Layouts',
  'post_excerpt' => '',
  'post_name' => 'team-layouts',
  'post_modified' => '2017-08-21 05:39:50',
  'post_modified_gmt' => '2017-08-21 05:39:50',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?page_id=2453',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'display_content' => 'content',
    'portfolio_display_content' => 'content',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2393,
  'post_date' => '2013-12-20 20:10:23',
  'post_date_gmt' => '2013-12-20 20:10:23',
  'post_content' => '<h3>List-Post Layout fullpane blog</h3>
[list_posts style="list-post" limit="1" display="excerpt" post_date="yes" post_meta="yes" image_w="1010" image_h="400"]

[hr]
<h3>Grid2 Layout</h3>
[list_posts style="grid2" limit="2" display="excerpt" post_date="yes" post_meta="yes"  image_w="580" image_h="400"]

[hr]
<h3>Grid3 Layout</h3>
[list_posts style="grid3" limit="3" display="excerpt" post_date="no" post_meta="yes"  image_w="370" image_h="250"]

[hr]
<h3>Grid4 Layout</h3>
[list_posts style="grid4" limit="4" display="excerpt" post_date="yes" post_meta="yes" image_w="270" image_h="200"]

[hr]
<h3>Post Slider</h3>
[post_slider limit="6" visible="5" display="excerpt" post_date="no" post_meta="no"]

&nbsp;',
  'post_title' => 'Blog Layouts',
  'post_excerpt' => '',
  'post_name' => 'blog-layouts',
  'post_modified' => '2017-10-29 15:32:35',
  'post_modified_gmt' => '2017-10-29 15:32:35',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?page_id=2393',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'display_content' => 'content',
    'portfolio_layout' => 'list-post',
    'portfolio_display_content' => 'content',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2395,
  'post_date' => '2013-12-20 20:13:03',
  'post_date_gmt' => '2013-12-20 20:13:03',
  'post_content' => '[testimonial style="slider" limit="4" image_w="120" image_h="120"]

[hr]

[testimonial style="list-post" limit="1" image_w="60" image_h="60"]

[hr]

[testimonial style="grid4" limit="8" image_w="60" image_h="60"]

[hr]

[testimonial style="grid3" limit="3" image_w="60" image_h="60"]

[hr]

[testimonial style="grid2" limit="4" image_w="60" image_h="60"]',
  'post_title' => 'Testimonial Layouts',
  'post_excerpt' => '',
  'post_name' => 'testimonial-layouts',
  'post_modified' => '2017-08-21 05:39:51',
  'post_modified_gmt' => '2017-08-21 05:39:51',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?page_id=2395',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'display_content' => 'content',
    'portfolio_layout' => 'list-post',
    'portfolio_display_content' => 'content',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2397,
  'post_date' => '2013-12-20 20:14:36',
  'post_date_gmt' => '2013-12-20 20:14:36',
  'post_content' => '<h3>Portfolio Grid4</h3>
[portfolio style="grid4" limit="4" image_w="290" image_h="290"]

[hr]
<h3>Portfolio Grid3</h3>
[portfolio style="grid3" limit="3" image_w="386" image_h="386"]

[hr]
<h3>Portfolio Grid2</h3>
[portfolio style="grid2" limit="2" image_w="580" image_h="290"]

[hr]
<h3>Portfolio Slider</h3>
[portfolio style="slider" limit="7" visible="5" auto="1" image_w="232" image_h="232"]',
  'post_title' => 'Portfolio Layouts',
  'post_excerpt' => '',
  'post_name' => 'portfolio-layouts',
  'post_modified' => '2018-06-06 01:28:38',
  'post_modified_gmt' => '2018-06-06 01:28:38',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?page_id=2397',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'display_content' => 'content',
    'portfolio_layout' => 'list-post',
    'portfolio_display_content' => 'content',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2979,
  'post_date' => '2014-12-19 00:23:20',
  'post_date_gmt' => '2014-12-19 00:23:20',
  'post_content' => '<!--themify_builder_static--><h1>Beautiful Theme</h1>
 <h2 style="text-align: center;">Great For Presentions</h2>
 <h2 style="text-align: center;">Sexy Parallax Scrolling</h2>
 <h2 style="text-align: center;">Start Your Own</h2>
 
 <a href="https://themify.me/themes/fullpane" >Get Fullpane</a><!--/themify_builder_static-->',
  'post_title' => 'Demo 3',
  'post_excerpt' => '',
  'post_name' => 'demo-3',
  'post_modified' => '2018-06-06 17:04:34',
  'post_modified_gmt' => '2018-06-06 17:04:34',
  'post_content_filtered' => '',
  'post_parent' => 2930,
  'guid' => 'https://themify.me/demo/themes/fullpane/?page_id=2979',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'content_width' => 'full_width',
    'hide_page_title' => 'yes',
    'section_full_scrolling' => 'yes',
    'section_scrolling_mobile' => 'on',
    'fullpage_parallax_scrolling' => 'on',
    'display_content' => 'content',
    'portfolio_display_content' => 'content',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_image-gradient-angle\\":\\"0\\",\\"background_repeat\\":\\"repeat\\",\\"text_align\\":\\"center\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h1>Beautiful Theme<\\\\/h1>\\"}}]}],\\"styling\\":{\\"background_type\\":\\"image\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_image\\":\\"https://themify.me/demo/themes/fullpane\\\\/files\\\\/2018\\\\/06\\\\/demoimage8.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"background_color\\":\\"756f68\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"font_color\\":\\"e8e8e8\\",\\"link_color\\":\\"f49ac1\\",\\"padding_top\\":\\"3\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"3\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\"}},{\\"row_order\\":\\"1\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h2 style=\\\\\\\\\\\\\\"text-align: center;\\\\\\\\\\\\\\">Great For Presentions<\\\\/h2>\\"}}]}],\\"styling\\":{\\"background_type\\":\\"image\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_image\\":\\"https://themify.me/demo/themes/fullpane\\\\/files\\\\/2018\\\\/06\\\\/demoimage61.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"background_color\\":\\"363338\\",\\"cover_color-type\\":\\"cover_gradient\\",\\"cover_gradient-gradient\\":\\"0% rgba(71, 114, 255, 0.65)|100% rgba(255, 195, 31, 0.7)\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"font_color\\":\\"ffffff\\",\\"link_color\\":\\"fff785\\",\\"padding_top\\":\\"3\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"3\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"row_width\\":\\"fullwidth\\"}},{\\"row_order\\":\\"2\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h2 style=\\\\\\\\\\\\\\"text-align: center;\\\\\\\\\\\\\\">Sexy Parallax Scrolling<\\\\/h2>\\"}}]}],\\"styling\\":{\\"background_type\\":\\"image\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_image\\":\\"https://themify.me/demo/themes/fullpane\\\\/files\\\\/2018\\\\/06\\\\/demoimage16.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"background_color\\":\\"363338\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"font_color\\":\\"ffffff\\",\\"link_color\\":\\"fff785\\",\\"padding_top\\":\\"3\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"3\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"row_width\\":\\"fullwidth\\"}},{\\"row_order\\":\\"3\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h2 style=\\\\\\\\\\\\\\"text-align: center;\\\\\\\\\\\\\\">Start Your Own<\\\\/h2>\\"}},{\\"mod_name\\":\\"buttons\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"text_align\\":\\"center\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"checkbox_padding_link_apply_all\\":\\"1\\",\\"checkbox_link_margin_apply_all\\":\\"1\\",\\"checkbox_link_border_apply_all\\":\\"1\\",\\"buttons_size\\":\\"normal\\",\\"buttons_style\\":\\"outline\\",\\"display\\":\\"buttons-horizontal\\",\\"content_button\\":[{\\"label\\":\\"Get Fullpane\\",\\"link\\":\\"https:\\\\/\\\\/themify.me\\\\/themes\\\\/fullpane\\",\\"link_options\\":\\"regular\\",\\"icon_alignment\\":\\"left\\"}]}}]}],\\"styling\\":{\\"background_type\\":\\"image\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_image\\":\\"https://themify.me/demo/themes/fullpane\\\\/files\\\\/2018\\\\/06\\\\/demoimage12.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"background_color\\":\\"363338\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"font_color\\":\\"ffffff\\",\\"link_color\\":\\"fff785\\",\\"padding_top\\":\\"3\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"3\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"row_width\\":\\"fullwidth\\"}},{\\"row_order\\":\\"4\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2636,
  'post_date' => '2014-01-09 04:50:24',
  'post_date_gmt' => '2014-01-09 04:50:24',
  'post_content' => '',
  'post_title' => 'Portfolio',
  'post_excerpt' => '',
  'post_name' => 'portfolio',
  'post_modified' => '2017-08-21 05:39:42',
  'post_modified_gmt' => '2017-08-21 05:39:42',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?page_id=2636',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'display_content' => 'content',
    'portfolio_query_category' => '0',
    'portfolio_posts_per_page' => '12',
    'portfolio_display_content' => 'none',
    'portfolio_image_width' => '300',
    'portfolio_image_height' => '250',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2883,
  'post_date' => '2014-12-05 21:56:44',
  'post_date_gmt' => '2014-12-05 21:56:44',
  'post_content' => '<!--themify_builder_static--><h1>Welcome to Fullpane</h1> 
 <h3>Full section scrolling theme</h3> 
 <a href="https://themify.me/demo/themes/fullpane/" >HOME</a> <a href="https://themify.me/demo/themes/fullpane/home/demo-3/" >DEMO 3</a> 
 <h2 style="text-align: center;">Featured Work</h2> 
 <p>[themify_portfolio_posts style="grid4" limit="4" image_w="320" image_h="280"]</p> 
 <a href="https://themify.me/demo/themes/fullpane/post-type-layouts/portfolio-layouts/" >More Work</a> 
 
 
 
 
 <h2> <a href=""></a> </h2>
 
 <time></time>
 
 <a href="">Launch Gallery</a> 
 
 
 <ul data-id="gallery-post_type-slider-0" data-autoplay="on" data-effect="scroll" data-speed="1000" data-visible="6" data-width="100" data-wrap="yes" data-slidernav="yes" data-pager="no">
 <li> <a href="#" data-entry_id="2633" data-image="https://themify.me/demo/themes/fullpane/files/2014/01/48891913.jpg"> <img src="https://themify.me/demo/themes/fullpane/files/2014/01/48891913-150x150.jpg" alt="Another Gallery"/> </a> </li> <li> <a href="#" data-entry_id="2552" data-image="https://themify.me/demo/themes/fullpane/files/2013/07/97479520.jpg"> <img src="https://themify.me/demo/themes/fullpane/files/2013/07/97479520-150x150.jpg" alt="Colorless"/> </a> </li> <li> <a href="#" data-entry_id="2541" data-image="https://themify.me/demo/themes/fullpane/files/2014/01/117783157.jpg"> <img src="https://themify.me/demo/themes/fullpane/files/2014/01/117783157-150x150.jpg" alt="Places To Visit"/> </a> </li> <li> <a href="#" data-entry_id="2522" data-image="https://themify.me/demo/themes/fullpane/files/2014/01/102683366.jpg"> <img src="https://themify.me/demo/themes/fullpane/files/2014/01/102683366-150x150.jpg" alt="Food Gallery"/> </a> </li> <li> <a href="#" data-entry_id="2346" data-image="https://themify.me/demo/themes/fullpane/files/2013/07/116149924.jpg"> <img src="https://themify.me/demo/themes/fullpane/files/2013/07/116149924-150x150.jpg" alt="Gallery Three"/> </a> </li> <li> <a href="#" data-entry_id="2344" data-image="https://themify.me/demo/themes/fullpane/files/2013/07/74418763.jpg"> <img src="https://themify.me/demo/themes/fullpane/files/2013/07/74418763-150x150.jpg" alt="Gallery Two"/> </a> </li> <li> <a href="#" data-entry_id="2343" data-image="https://themify.me/demo/themes/fullpane/files/2013/07/26100514.jpg"> <img src="https://themify.me/demo/themes/fullpane/files/2013/07/26100514-150x150.jpg" alt="Gallery One"/> </a> </li> </ul>
 
 
 
 
 <p>[themify_testimonial_posts style="slider" title="no" limit="5" image_w="60" image_h="60" auto="45"]</p> 
 <h1>Horizontal Scroll</h1> 
 <h4>Full section scrolling feature allows viewers to scroll through your page design one row at a time like a presentation slideshow. Click on the arrow on the left to see more.</h4> 
 <h1>Cool, eh?</h1> 
 <h4>Scroll Down For More</h4> 
 
 
 
 
 
 <h2>Services</h2> 
 
 
 
 <h3>WEB DESIGN</h3> <p style="text-align: center;">Phasellus quam ligula, imperdiet porta facilisis eget, facilisis euismod elit. Vestibulum venenatis a mi non adipiscing.</p> 
 
 
 
 
 <h3>DEVELOPMENT</h3> <p style="text-align: center;">Vivamus in dolor eu lacus luctus auctor non ac turpis. Proin et rutrum dolor. Proin et rutrum dolor. Praesent venenatis purus.</p> 
 
 
 
 
 <h3>E-COMMERCE</h3> <p style="text-align: center;">Phasellus quam ligula, imperdiet porta facilisis eget, facilisis euismod elit. Vestibulum venenatis a mi non adipiscing.</p> 
 
 
 
 
 <h3>ADVERTISING</h3> <p style="text-align: center;">Phasellus quam ligula, imperdiet porta facilisis eget, facilisis euismod elit. Vestibulum venenatis a mi non adipiscing.</p> 
 
 <iframe width="1165" height="655" src="https://www.youtube.com/embed/y9VOCiPWj_w?feature=oembed&showinfo=0&#038;iv_load_policy=3&#038;nologo=1" allow="autoplay; encrypted-media" allowfullscreen></iframe> 
 
 <h2>Our Team</h2> 
 <p>[themify_team_posts style="grid4" limit="4" display="none" image_w="85" image_h="85"]</p> 
 <h2>Buy It Now</h2> 
 <h3>Get Fullpane now or view more: <a href="https://themify.me/demo/themes/fullpane/demo-2/">Demo 2</a> and <a href="https://themify.me/demo/themes/fullpane/demo-3/">Demo 3</a> page.</h3> <p> </p> 
 <a href="https://themify.me/demo/themes/fullpane/demo-2/" >DEMO 2</a> <a href="https://themify.me/demo/themes/fullpane/demo-3/" >DEMO 3</a><!--/themify_builder_static-->',
  'post_title' => 'Features',
  'post_excerpt' => '',
  'post_name' => 'demo-2',
  'post_modified' => '2018-08-02 23:16:52',
  'post_modified_gmt' => '2018-08-02 23:16:52',
  'post_content_filtered' => '',
  'post_parent' => 2930,
  'guid' => 'https://themify.me/demo/themes/fullpane/?page_id=2883',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'content_width' => 'full_width',
    'hide_page_title' => 'yes',
    'custom_menu' => 'home-menu',
    'section_full_scrolling' => 'yes',
    'section_scrolling_mobile' => 'on',
    'section_scrolling_direction' => 'horizontal',
    'fullpage_parallax_scrolling' => 'on',
    'display_content' => 'content',
    'portfolio_display_content' => 'content',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h1>Welcome to Fullpane<\\\\/h1>\\",\\"animation_effect\\":\\"fadeInLeft\\"}},{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h3>Full section scrolling theme<\\\\/h3>\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"margin_bottom\\":\\"20\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\"}},{\\"mod_name\\":\\"buttons\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"checkbox_padding_link_apply_all\\":\\"1\\",\\"checkbox_link_margin_apply_all\\":\\"1\\",\\"checkbox_link_border_apply_all\\":\\"1\\",\\"buttons_size\\":\\"normal\\",\\"buttons_style\\":\\"outline\\",\\"display\\":\\"buttons-horizontal\\",\\"content_button\\":[{\\"label\\":\\"HOME\\",\\"link\\":\\"https://themify.me/demo/themes/fullpane\\\\/\\",\\"link_options\\":\\"regular\\",\\"icon_alignment\\":\\"left\\"},{\\"label\\":\\"DEMO 3\\",\\"link\\":\\"https://themify.me/demo/themes/fullpane\\\\/home\\\\/demo-3\\\\/\\",\\"link_options\\":\\"regular\\"}]}}]}],\\"styling\\":{\\"row_anchor\\":\\"Welcome\\",\\"background_type\\":\\"image\\",\\"background_image\\":\\"https://themify.me/demo/themes/fullpane\\\\/files\\\\/2014\\\\/01\\\\/fullpane-landing-bg.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"font_color\\":\\"ffffff_1.00\\",\\"text_align\\":\\"center\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\"}},{\\"row_order\\":\\"1\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2 style=\\\\\\\\\\\\\\"text-align: center;\\\\\\\\\\\\\\">Featured Work<\\\\/h2>\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"text_align\\":\\"center\\"}},{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_image-gradient-angle\\":\\"0\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<p>[themify_portfolio_posts style=\\\\\\\\\\\\\\"grid4\\\\\\\\\\\\\\" limit=\\\\\\\\\\\\\\"4\\\\\\\\\\\\\\" image_w=\\\\\\\\\\\\\\"320\\\\\\\\\\\\\\" image_h=\\\\\\\\\\\\\\"280\\\\\\\\\\\\\\"]<\\\\/p>\\",\\"animation_effect\\":\\"fadeInUp\\"}},{\\"mod_name\\":\\"buttons\\",\\"mod_settings\\":{\\"buttons_size\\":\\"large\\",\\"buttons_style\\":\\"outline\\",\\"content_button\\":[{\\"label\\":\\"More Work\\",\\"link\\":\\"https://themify.me/demo/themes/fullpane\\\\/post-type-layouts\\\\/portfolio-layouts\\\\/\\",\\"link_options\\":\\"regular\\"}],\\"background_image-type\\":\\"image\\",\\"text_align\\":\\"center\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"checkbox_link_padding_apply_all\\":\\"padding\\",\\"link_checkbox_margin_apply_all\\":\\"margin\\",\\"link_checkbox_border_apply_all\\":\\"border\\",\\"custom_parallax_scroll_reverse_reverse\\":\\"reverse\\",\\"custom_parallax_scroll_fade_fade\\":\\"fade\\",\\"visibility_desktop_hide\\":\\"hide\\",\\"visibility_tablet_hide\\":\\"hide\\",\\"visibility_mobile_hide\\":\\"hide\\"}}]}],\\"styling\\":{\\"background_type\\":\\"gradient\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_gradient-gradient-angle\\":\\"150\\",\\"background_gradient-gradient\\":\\"0% rgba(51, 197, 255, 0.95)|98% rgba(174, 0, 255, 0.94)\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"breakpoint_mobile\\":{\\"background_type\\":\\"gradient\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"padding_top\\":\\"8\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"6\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\"},\\"row_width\\":\\"fullwidth-content\\",\\"row_anchor\\":\\"Works\\"}},{\\"row_order\\":\\"2\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"plain-text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"plain_text\\":\\"[themify_gallery_posts limit=\\\\\\\\\\\\\\"8\\\\\\\\\\\\\\" style=\\\\\\\\\\\\\\"slider\\\\\\\\\\\\\\"]\\"}}]}],\\"styling\\":{\\"row_width\\":\\"fullwidth-content\\",\\"row_anchor\\":\\"Gallery\\",\\"background_type\\":\\"image\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"animation_effect\\":\\"fadeInUp\\"}},{\\"row_order\\":\\"3\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<p>[themify_testimonial_posts style=\\\\\\\\\\\\\\"slider\\\\\\\\\\\\\\" title=\\\\\\\\\\\\\\"no\\\\\\\\\\\\\\" limit=\\\\\\\\\\\\\\"5\\\\\\\\\\\\\\" image_w=\\\\\\\\\\\\\\"60\\\\\\\\\\\\\\" image_h=\\\\\\\\\\\\\\"60\\\\\\\\\\\\\\" auto=\\\\\\\\\\\\\\"45\\\\\\\\\\\\\\"]<\\\\/p>\\",\\"font_color\\":\\"ffffff_1.00\\",\\"text_align\\":\\"center\\",\\"link_color\\":\\"ffffff_1.00\\",\\"checkbox_padding_apply_all\\":\\"padding\\",\\"checkbox_margin_apply_all\\":\\"margin\\",\\"checkbox_border_apply_all\\":\\"border\\",\\"animation_effect\\":\\"fadeInLeft\\"}}]}],\\"styling\\":{\\"background_type\\":\\"gradient\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_gradient-gradient\\":\\"0% rgba(255, 112, 241, 0.98)|100% rgba(78, 51, 255, 0.98)\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"breakpoint_mobile\\":{\\"background_type\\":\\"gradient\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"padding_top\\":\\"8\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"8\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\"},\\"row_anchor\\":\\"Testimonials\\"}},{\\"row_order\\":\\"4\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"text_align\\":\\"center\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h1>Horizontal Scroll<\\\\/h1>\\"}},{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"text_align\\":\\"center\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h4>Full section scrolling feature allows viewers to scroll through your page design one row at a time like a presentation slideshow. Click on the arrow on the left to see more.<\\\\/h4>\\"}}]}],\\"styling\\":{\\"background_type\\":\\"gradient\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_video_options\\":\\"mute\\",\\"background_gradient-gradient-angle\\":\\"298\\",\\"background_gradient-gradient\\":\\"0% rgba(25, 244, 255, 0.98)|100% rgba(255, 247, 130, 0.97)\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"font_color\\":\\"#000000\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"row_anchor\\":\\"horizontal\\"}},{\\"row_order\\":\\"5\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"text_align\\":\\"center\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h1>Cool, eh?<\\\\/h1>\\"}},{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"text_align\\":\\"center\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h4>Scroll Down For More<\\\\/h4>\\"}},{\\"mod_name\\":\\"feature\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"padding_top\\":\\"4\\",\\"padding_top_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"layout_feature\\":\\"icon-top\\",\\"circle_percentage_feature\\":\\"100\\",\\"circle_stroke_feature\\":\\"3\\",\\"circle_color_feature\\":\\"#ffffff\\",\\"circle_size_feature\\":\\"small\\",\\"icon_type_feature\\":\\"icon\\",\\"icon_feature\\":\\"fa-arrow-down\\",\\"icon_color_feature\\":\\"#ffffff\\",\\"link_options\\":\\"regular\\"}}]}],\\"styling\\":{\\"background_type\\":\\"gradient\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_video_options\\":\\"mute\\",\\"background_gradient-gradient-angle\\":\\"148\\",\\"background_gradient-gradient\\":\\"0% rgba(0, 213, 255, 0.98)|98% rgba(187, 40, 250, 0.98)\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"breakpoint_mobile\\":{\\"background_type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\"},\\"row_scroll_direction\\":\\"module_row_slide\\"}},{\\"row_order\\":\\"6\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>Services<\\\\/h2>\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"font_color\\":\\"716758\\",\\"text_align\\":\\"center\\"}},{\\"row_order\\":\\"1\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"feature\\",\\"mod_settings\\":{\\"title_feature\\":\\"WEB DESIGN\\",\\"content_feature\\":\\"<p style=\\\\\\\\\\\\\\"text-align: center;\\\\\\\\\\\\\\">Phasellus quam ligula, imperdiet porta facilisis eget, facilisis euismod elit. Vestibulum venenatis a mi non adipiscing.<\\\\/p>\\",\\"layout_feature\\":\\"icon-top\\",\\"circle_percentage_feature\\":\\"70\\",\\"circle_stroke_feature\\":\\"2\\",\\"circle_color_feature\\":\\"64B218\\",\\"circle_size_feature\\":\\"large\\",\\"icon_type_feature\\":\\"icon\\",\\"icon_feature\\":\\"fa-desktop\\",\\"icon_color_feature\\":\\"64B218\\",\\"animation_effect\\":\\"fadeInUp\\",\\"font_color\\":\\"808C7D\\"}}]},{\\"column_order\\":\\"1\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"feature\\",\\"mod_settings\\":{\\"title_feature\\":\\"DEVELOPMENT\\",\\"content_feature\\":\\"<p style=\\\\\\\\\\\\\\"text-align: center;\\\\\\\\\\\\\\">Vivamus in dolor eu lacus luctus auctor non ac turpis. Proin et rutrum dolor. Proin et rutrum dolor. Praesent venenatis purus.<\\\\/p>\\",\\"layout_feature\\":\\"icon-top\\",\\"circle_percentage_feature\\":\\"50\\",\\"circle_stroke_feature\\":\\"2\\",\\"circle_color_feature\\":\\"8352A8\\",\\"circle_size_feature\\":\\"large\\",\\"icon_type_feature\\":\\"icon\\",\\"icon_feature\\":\\"fa-calendar-o\\",\\"icon_color_feature\\":\\"8352A8\\",\\"animation_effect\\":\\"fadeInUp\\",\\"font_color\\":\\"808C7D\\"}}]},{\\"column_order\\":\\"2\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"feature\\",\\"mod_settings\\":{\\"title_feature\\":\\"E-COMMERCE\\",\\"content_feature\\":\\"<p style=\\\\\\\\\\\\\\"text-align: center;\\\\\\\\\\\\\\">Phasellus quam ligula, imperdiet porta facilisis eget, facilisis euismod elit. Vestibulum venenatis a mi non adipiscing.<\\\\/p>\\",\\"layout_feature\\":\\"icon-top\\",\\"circle_percentage_feature\\":\\"30\\",\\"circle_stroke_feature\\":\\"2\\",\\"circle_color_feature\\":\\"ff0303\\",\\"circle_size_feature\\":\\"large\\",\\"icon_type_feature\\":\\"icon\\",\\"icon_feature\\":\\"fa-shopping-cart\\",\\"icon_color_feature\\":\\"ff0303\\",\\"animation_effect\\":\\"fadeInUp\\",\\"font_color\\":\\"808C7D\\"}}]},{\\"column_order\\":\\"3\\",\\"grid_class\\":\\"col4-1\\",\\"modules\\":[{\\"mod_name\\":\\"feature\\",\\"mod_settings\\":{\\"title_feature\\":\\"ADVERTISING\\",\\"content_feature\\":\\"<p style=\\\\\\\\\\\\\\"text-align: center;\\\\\\\\\\\\\\">Phasellus quam ligula, imperdiet porta facilisis eget, facilisis euismod elit. Vestibulum venenatis a mi non adipiscing.<\\\\/p>\\",\\"layout_feature\\":\\"icon-top\\",\\"circle_percentage_feature\\":\\"70\\",\\"circle_stroke_feature\\":\\"2\\",\\"circle_color_feature\\":\\"1F94B8\\",\\"circle_size_feature\\":\\"large\\",\\"icon_type_feature\\":\\"icon\\",\\"icon_feature\\":\\"fa-bar-chart-o\\",\\"icon_color_feature\\":\\"1F94B8\\",\\"animation_effect\\":\\"fadeInUp\\",\\"font_color\\":\\"808C7D\\"}}]}]}]}],\\"styling\\":{\\"background_type\\":\\"gradient\\",\\"background_slider_size\\":\\"thumbnail\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_gradient-gradient\\":\\"0% rgba(148, 255, 255, 0.96)|100% rgba(255, 255, 255, 0.99)\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"left-top\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"breakpoint_mobile\\":{\\"background_type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"padding_top\\":\\"8\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"8\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\"},\\"row_anchor\\":\\"Services\\"}},{\\"row_order\\":\\"7\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"video\\",\\"mod_settings\\":{\\"style_video\\":\\"video-top\\",\\"url_video\\":\\"http:\\\\/\\\\/www.youtube.com\\\\/watch?v=y9VOCiPWj_w&showinfo=0&iv_load_policy=3&nologo=1\\",\\"width_video\\":\\"100\\",\\"unit_video\\":\\"%\\"}}]}],\\"styling\\":{\\"background_type\\":\\"gradient\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_gradient-gradient\\":\\"0% rgb(0, 0, 0)|100% rgba(43, 43, 43, 0.99)\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"row_anchor\\":\\"Video\\"}},{\\"row_order\\":\\"8\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"font_color\\":\\"030303\\",\\"text_align\\":\\"center\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_bottom\\":\\"20\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h2>Our Team<\\\\/h2>\\",\\"animation_effect\\":\\"fadeInLeft\\"}},{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<p>[themify_team_posts style=\\\\\\\\\\\\\\"grid4\\\\\\\\\\\\\\" limit=\\\\\\\\\\\\\\"4\\\\\\\\\\\\\\" display=\\\\\\\\\\\\\\"none\\\\\\\\\\\\\\" image_w=\\\\\\\\\\\\\\"85\\\\\\\\\\\\\\" image_h=\\\\\\\\\\\\\\"85\\\\\\\\\\\\\\"]<\\\\/p>\\",\\"animation_effect\\":\\"fadeInUp\\",\\"font_color\\":\\"030303_1.00\\",\\"link_color\\":\\"030303_1.00\\"}}]}],\\"styling\\":{\\"background_type\\":\\"gradient\\",\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_gradient-gradient\\":\\"0% rgba(207, 255, 234, 0.99)|100% rgb(255, 255, 255)\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"background_color\\":\\"8FEEFF\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"padding_right_unit\\":\\"%\\",\\"padding_left_unit\\":\\"%\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"breakpoint_mobile\\":{\\"background_type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"padding_top\\":\\"8\\",\\"padding_top_unit\\":\\"%\\",\\"padding_right_unit\\":\\"%\\",\\"padding_bottom\\":\\"8\\",\\"padding_bottom_unit\\":\\"%\\",\\"padding_left_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\"},\\"row_anchor\\":\\"Team\\"}},{\\"row_order\\":\\"9\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<h2>Buy It Now<\\\\/h2>\\",\\"animation_effect\\":\\"fadeInLeft\\",\\"font_color\\":\\"d6d6d6\\",\\"link_color\\":\\"fff3ad\\"}},{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"background_repeat\\":\\"repeat\\",\\"font_color\\":\\"d6d6d6\\",\\"link_color\\":\\"fff3ad\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"content_text\\":\\"<h3>Get Fullpane now or view more: <a href=\\\\\\\\\\\\\\"https://themify.me/demo/themes/fullpane\\\\/demo-2\\\\/\\\\\\\\\\\\\\">Demo 2<\\\\/a> and <a href=\\\\\\\\\\\\\\"https://themify.me/demo/themes/fullpane\\\\/demo-3\\\\/\\\\\\\\\\\\\\">Demo 3<\\\\/a> page.<\\\\/h3>\\\\n<p> <\\\\/p>\\",\\"animation_effect\\":\\"fadeInUp\\"}},{\\"mod_name\\":\\"buttons\\",\\"mod_settings\\":{\\"background_image-type\\":\\"image\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"checkbox_border_apply_all\\":\\"1\\",\\"checkbox_padding_link_apply_all\\":\\"1\\",\\"checkbox_link_margin_apply_all\\":\\"1\\",\\"checkbox_link_border_apply_all\\":\\"1\\",\\"buttons_size\\":\\"normal\\",\\"buttons_style\\":\\"outline\\",\\"content_button\\":[{\\"label\\":\\"DEMO 2\\",\\"link\\":\\"https://themify.me/demo/themes/fullpane\\\\/demo-2\\\\/\\",\\"link_options\\":\\"regular\\"},{\\"label\\":\\"DEMO 3\\",\\"link\\":\\"https://themify.me/demo/themes/fullpane\\\\/demo-3\\\\/\\",\\"link_options\\":\\"regular\\"}]}}]}],\\"styling\\":{\\"background_type\\":\\"image\\",\\"background_image\\":\\"https://themify.me/demo/themes/fullpane\\\\/files\\\\/2012\\\\/09\\\\/63092077.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_color\\":\\"030303_1.00\\",\\"text_align\\":\\"center\\",\\"row_anchor\\":\\"Buy\\"}},{\\"row_order\\":\\"10\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2499,
  'post_date' => '2014-01-07 01:33:49',
  'post_date_gmt' => '2014-01-07 01:33:49',
  'post_content' => '',
  'post_title' => 'Post Type Layouts',
  'post_excerpt' => '',
  'post_name' => 'post-type-layouts',
  'post_modified' => '2017-08-21 05:39:44',
  'post_modified_gmt' => '2017-08-21 05:39:44',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?page_id=2499',
  'menu_order' => 2,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'display_content' => 'content',
    'portfolio_display_content' => 'content',
    'builder_switch_frontend' => '0',
    '_themify_builder_settings_json' => '[{\\"row_order\\":\\"0\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full first last\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"mod_settings\\":{\\"content_text\\":\\"<p class=\\\\\\\\\\\\\\"p1\\\\\\\\\\\\\\"><span class=\\\\\\\\\\\\\\"s1\\\\\\\\\\\\\\">[themify_gallery_posts limit=“8”] </span></p>\\",\\"font_family\\":\\"default\\",\\"text_align_left\\":\\"left\\",\\"text_align_center\\":\\"center\\",\\"text_align_right\\":\\"right\\",\\"text_align_justify\\":\\"justify\\",\\"padding_top_unit\\":\\"px\\",\\"padding_right_unit\\":\\"px\\",\\"padding_bottom_unit\\":\\"px\\",\\"padding_left_unit\\":\\"px\\",\\"margin_top_unit\\":\\"px\\",\\"margin_right_unit\\":\\"px\\",\\"margin_bottom_unit\\":\\"px\\",\\"margin_left_unit\\":\\"px\\"}}],\\"styling\\":[]}],\\"styling\\":[]},{\\"row_order\\":\\"1\\",\\"cols\\":[{\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[],\\"styling\\":[]}],\\"styling\\":[]}]',
  ),
  'tax_input' => 
  array (
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2203,
  'post_date' => '2013-10-11 18:46:39',
  'post_date_gmt' => '2013-10-11 18:46:39',
  'post_content' => '',
  'post_title' => 'Blog',
  'post_excerpt' => '',
  'post_name' => 'blog',
  'post_modified' => '2017-08-21 05:39:53',
  'post_modified_gmt' => '2017-08-21 05:39:53',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?page_id=2203',
  'menu_order' => 4,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'query_category' => '0',
    'posts_per_page' => '5',
    'image_width' => '700',
    'image_height' => '400',
    'portfolio_layout' => 'list-post',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2286,
  'post_date' => '2013-12-11 23:38:45',
  'post_date_gmt' => '2013-12-11 23:38:45',
  'post_content' => 'Suspendisse fermentum bibendum lectus, ut imperdiet est mattis bibendum. Ut sollicitudin risus vitae lobortis venenatis.',
  'post_title' => 'Natasha Marie',
  'post_excerpt' => '',
  'post_name' => 'natasha-marie',
  'post_modified' => '2017-08-21 05:40:41',
  'post_modified_gmt' => '2017-08-21 05:40:41',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?post_type=team&#038;p=2286',
  'menu_order' => 0,
  'post_type' => 'team',
  'meta_input' => 
  array (
    'team_title' => 'Public Relations',
    'skills' => '[progress_bar label="Graphic Design" color="#ec008c" percentage="80"]
[progress_bar label="Web Design" color="#9437e3" percentage="58"]
[progress_bar label="jQuery" color="#f1972c" percentage="69"]',
    'social' => '[team-social label="Twitter" link="http://twitter.com/themify" icon="twitter"]

[team-social label="Facebook" link="http://facebook.com/themify" icon="facebook"]

[team-social label="Pinterest" link="http://pinterest.com/" icon="pinterest"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'team-category' => 'uncategorized',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 45,
  'post_date' => '2013-07-12 05:00:25',
  'post_date_gmt' => '2013-07-12 05:00:25',
  'post_content' => 'Proin gravida nibh vel velit auctor aliquet. Aenean sollicitudin, lorem quis bibendum auctor, nisi elit consequat ipsum, nec sagittis sem nibh id elit. dolor quis sollicitudin accumsan, elit turpis tempor est mattis.',
  'post_title' => 'Jacqueline Willis',
  'post_excerpt' => '',
  'post_name' => 'jacqueline-willis',
  'post_modified' => '2017-08-21 05:40:43',
  'post_modified_gmt' => '2017-08-21 05:40:43',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=team&#038;p=45',
  'menu_order' => 0,
  'post_type' => 'team',
  'meta_input' => 
  array (
    'team_title' => 'Web Designer',
    'skills' => '[progress_bar label="Logo Design" color="#088c72" percentage="74"]
[progress_bar label="Creativity" color="#f34941" percentage="83"]
[progress_bar label="Technical" color="#ec008c" percentage="67"]',
    'social' => '[team-social label="Twitter" link="http://twitter.com/themify" icon="twitter"]

[team-social label="Facebook" link="http://facebook.com/themify" icon="facebook"]

[team-social label="Pinterest" link="http://pinterest.com/" icon="pinterest"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'team-category' => 'uncategorized',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 48,
  'post_date' => '2013-07-12 05:09:49',
  'post_date_gmt' => '2013-07-12 05:09:49',
  'post_content' => 'Maecenas luctus aliquet risus ac feugiat. Curabitur enim mi, placerat sit amet porttitor ac, mollis lobortis elit. Cras sit amet erat eget dolor varius tristique. Duis eu nisl tortor. Mauris pulvinar metus eget nulla adipiscing consectetur.',
  'post_title' => 'Amy Weaver',
  'post_excerpt' => '',
  'post_name' => 'amy-weaver',
  'post_modified' => '2017-08-21 05:40:42',
  'post_modified_gmt' => '2017-08-21 05:40:42',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=team&#038;p=48',
  'menu_order' => 0,
  'post_type' => 'team',
  'meta_input' => 
  array (
    'team_title' => 'Project Manager',
    'skills' => '[progress_bar label="Project Management" color="#825ab1" percentage="80"]
[progress_bar label="Marketing" color="#ec008c" percentage="58"]
[progress_bar label="Logistics" color="#9437e3" percentage="69"]',
    'social' => '[team-social label="Twitter" link="http://twitter.com/themify" icon="twitter"]

[team-social label="Facebook" link="http://facebook.com/themify" icon="facebook"]

[team-social label="Pinterest" link="http://www.youtube.com/user/themifyme" icon="youtube"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'team-category' => 'uncategorized',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2287,
  'post_date' => '2013-12-11 23:40:32',
  'post_date_gmt' => '2013-12-11 23:40:32',
  'post_content' => 'Vivamus lacinia enim in nibh consectetur, sit amet fringilla urna consectetur. Aliquam at commodo mi. Ut adipiscing vel ipsum non mo amus lacinia enim in nibh consectetur, sit uam at commodo mi. Ut adipiscing vel ipsum non molliet fringilla urna consecte on mo amus lacinia enim in nibh consectetur, sit uam at commodo miadipiscing vel.',
  'post_title' => 'Scott Rogers',
  'post_excerpt' => '',
  'post_name' => 'scott-rogers',
  'post_modified' => '2017-08-21 05:40:38',
  'post_modified_gmt' => '2017-08-21 05:40:38',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?post_type=team&#038;p=2287',
  'menu_order' => 0,
  'post_type' => 'team',
  'meta_input' => 
  array (
    'team_title' => 'Research',
    'skills' => '[progress_bar label="Project Management" color="#825ab1" percentage="80"]
[progress_bar label="Marketing" color="#088c72" percentage="58"]
[progress_bar label="Logistics" color="#f34941" percentage="69"]',
    'social' => '[team-social label="Twitter" link="http://twitter.com/themify" icon="twitter"]

[team-social label="Facebook" link="http://facebook.com/themify" icon="facebook"]

[team-social label="Pinterest" link="http://pinterest.com/" icon="pinterest"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'team-category' => 'uncategorized',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2640,
  'post_date' => '2013-05-09 05:59:52',
  'post_date_gmt' => '2013-05-09 05:59:52',
  'post_content' => 'Proin gravida nibh vel velit auctor aliquet. Aenean sollicitudin, lorem quis bibendum auctor, nisi elit consequat ipsum, nec sagittis sem nibh id elit. dolor quis sollicitudin accumsan, elit turpis tempor est mattis.',
  'post_title' => 'John Smith',
  'post_excerpt' => '',
  'post_name' => 'john-smith',
  'post_modified' => '2017-08-21 05:40:44',
  'post_modified_gmt' => '2017-08-21 05:40:44',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?post_type=team&#038;p=2640',
  'menu_order' => 0,
  'post_type' => 'team',
  'meta_input' => 
  array (
    'team_title' => 'Web Designer',
    'skills' => '[progress_bar label="Coding" color="#f34941" percentage="83"]
[progress_bar label="Public Relation" color="#088c72" percentage="34"]
[progress_bar label="Writing" color="#ec008c" percentage="67"]',
    'social' => '[team-social label="Twitter" link="http://twitter.com/themify" icon="twitter"]

[team-social label="Facebook" link="http://facebook.com/themify" icon="facebook"]

[team-social label="Pinterest" link="http://pinterest.com/" icon="pinterest"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'team-category' => 'uncategorized',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1589,
  'post_date' => '2008-11-02 19:39:01',
  'post_date_gmt' => '2008-11-02 19:39:01',
  'post_content' => 'Suspendisse volutpat, eros congue scelerisque iaculis, magna odio sodales dui, vitae vulputate elit metus ac arcu. Mauris consequat rhoncus dolor id sagittis. Cras tortor elit, aliquet quis tincidunt eget, dignissim non tortor.',
  'post_title' => 'Extremely Happy',
  'post_excerpt' => '',
  'post_name' => 'extremely-happy',
  'post_modified' => '2017-08-21 05:41:27',
  'post_modified_gmt' => '2017-08-21 05:41:27',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/agency/?post_type=testimonial&#038;p=27',
  'menu_order' => 0,
  'post_type' => 'testimonial',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'testimonial-category' => 'testimonials',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1590,
  'post_date' => '2008-11-09 20:28:43',
  'post_date_gmt' => '2008-11-09 20:28:43',
  'post_content' => 'Nam nunc lectus, congue non egestas quis, condimentum ut arcu. Nulla placerat, tortor non egestas rutrum, mi turpis adipiscing dui, et mollis turpis tortor vel orci. Cras a fringilla nunc. Suspendisse volutpat, eros congue scelerisque iaculis, magna odio sodales dui, vitae vulputate elit metus ac arcu.',
  'post_title' => 'Super Awesome!',
  'post_excerpt' => '',
  'post_name' => 'super-awesome',
  'post_modified' => '2017-08-21 05:41:25',
  'post_modified_gmt' => '2017-08-21 05:41:25',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/agency/?post_type=testimonial&#038;p=66',
  'menu_order' => 0,
  'post_type' => 'testimonial',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'testimonial-category' => 'testimonials',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2097,
  'post_date' => '2008-06-11 21:26:15',
  'post_date_gmt' => '2008-06-11 21:26:15',
  'post_content' => 'Fusce ultrices placerat sem at rutrum. Etiam bibendum ac sapien in vulputate. Maecenas commodo elementum gravida. Vivamus odio odio, pulvinar vel leo id, fringilla ullamcorper odio.',
  'post_title' => 'Carl Schmidt',
  'post_excerpt' => '',
  'post_name' => 'carl-schmidt',
  'post_modified' => '2017-08-21 05:41:34',
  'post_modified_gmt' => '2017-08-21 05:41:34',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?post_type=testimonial&#038;p=59',
  'menu_order' => 0,
  'post_type' => 'testimonial',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'testimonial-category' => 'team',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2098,
  'post_date' => '2008-06-11 21:28:42',
  'post_date_gmt' => '2008-06-11 21:28:42',
  'post_content' => 'Sed volutpat tristique metus eget suscipit. Donec aliquam eget purus id cursus. Integer ut arcu scelerisque, porttitor eros nec, placerat eros.',
  'post_title' => 'Clara Ray',
  'post_excerpt' => '',
  'post_name' => 'clara-ray',
  'post_modified' => '2017-08-21 05:41:32',
  'post_modified_gmt' => '2017-08-21 05:41:32',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?post_type=testimonial&#038;p=61',
  'menu_order' => 0,
  'post_type' => 'testimonial',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'testimonial-category' => 'team',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2099,
  'post_date' => '2008-06-11 21:31:55',
  'post_date_gmt' => '2008-06-11 21:31:55',
  'post_content' => 'Maecenas in orci nunc. Curabitur velit sapien, mollis vel aliquam et, dignissim consequat eros. Curabitur egestas quam dapibus arcu egestas mollis.',
  'post_title' => 'Diana Jones',
  'post_excerpt' => '',
  'post_name' => 'diana-jones-2',
  'post_modified' => '2017-08-21 05:41:31',
  'post_modified_gmt' => '2017-08-21 05:41:31',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?post_type=testimonial&#038;p=63',
  'menu_order' => 0,
  'post_type' => 'testimonial',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'testimonial-category' => 'team',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2100,
  'post_date' => '2008-06-11 21:33:02',
  'post_date_gmt' => '2008-06-11 21:33:02',
  'post_content' => 'Aliquam euismod aliquet nunc, mollis consectetur sapien congue eu. Pellentesque erat mauris, varius non posuere sit amet, tempor ac velit.',
  'post_title' => 'Patricia Wolf',
  'post_excerpt' => '',
  'post_name' => 'patricia-wolf',
  'post_modified' => '2017-08-21 05:41:29',
  'post_modified_gmt' => '2017-08-21 05:41:29',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/builder/?post_type=testimonial&#038;p=65',
  'menu_order' => 0,
  'post_type' => 'testimonial',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'testimonial-category' => 'team',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 12,
  'post_date' => '2013-07-12 03:03:52',
  'post_date_gmt' => '2013-07-12 03:03:52',
  'post_content' => 'Proin gravida nibh vel velit auctor aliquet. Aenean sollicitudin, lorem quis bibendum auctor, nisi elit consequat ipsum, nec sagittis sem nibh id elit. This is Photoshop\'s version of Lorem Ipsum.',
  'post_title' => 'Couldn\'t Do It Without You Guys',
  'post_excerpt' => '',
  'post_name' => 'mike-canlas',
  'post_modified' => '2017-08-21 05:41:20',
  'post_modified_gmt' => '2017-08-21 05:41:20',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=testimonial&#038;p=12',
  'menu_order' => 0,
  'post_type' => 'testimonial',
  'meta_input' => 
  array (
    'testimonial_name' => 'Mike Canlas',
    'testimonial_title' => 'Owner',
    'external_link' => 'https://themify.me/',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'testimonial-category' => 'uncategorized',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 20,
  'post_date' => '2013-07-12 04:13:47',
  'post_date_gmt' => '2013-07-12 04:13:47',
  'post_content' => 'Rravida nibh vel velit auctor aliquet. Aenean sollicitudin, lorem quis bibendum auctor, nisi elit consequat ipsum, nec sagittis sem nibh id elit. This is Photoshop’s version of Lorem Ipsum.',
  'post_title' => 'My Site Looks Amazing Now',
  'post_excerpt' => '',
  'post_name' => 'amanda-elric',
  'post_modified' => '2017-08-21 05:41:18',
  'post_modified_gmt' => '2017-08-21 05:41:18',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=testimonial&#038;p=20',
  'menu_order' => 0,
  'post_type' => 'testimonial',
  'meta_input' => 
  array (
    'testimonial_name' => 'Amanda Elric',
    'testimonial_title' => 'Manager, Themify',
    'external_link' => 'https://themify.me/',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'testimonial-category' => 'uncategorized',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 22,
  'post_date' => '2013-07-12 04:28:32',
  'post_date_gmt' => '2013-07-12 04:28:32',
  'post_content' => 'Maecenas in orci nunc. Curabitur velit sapien, mollis vel aliquam et, dignissim consequat eros. Curabitur egestas quam dapibus arcu egestas mollis. Mauris lacinia venenatis sapien commodo rutrum.',
  'post_title' => 'Wow, amazing work guys!',
  'post_excerpt' => '',
  'post_name' => 'diana-jones',
  'post_modified' => '2017-09-25 02:30:11',
  'post_modified_gmt' => '2017-09-25 02:30:11',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=testimonial&#038;p=22',
  'menu_order' => 0,
  'post_type' => 'testimonial',
  'meta_input' => 
  array (
    'testimonial_name' => 'Diana Jones',
    'testimonial_title' => 'CEO, Nice Company',
    'external_link' => 'https://themify.me/',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'testimonial-category' => 'uncategorized',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1591,
  'post_date' => '2008-11-19 19:58:11',
  'post_date_gmt' => '2008-11-19 19:58:11',
  'post_content' => 'Mauris mattis est quis dolor venenatis vitae pharetra diam gravida. Vivamus dignissim, ligula vel ultricies varius, nibh velit pretium leo, vel placerat ipsum risus luctus purt in from also <span style="line-height: 1.5em;">disse volutpat, eros cong rpis vehicula.</span>',
  'post_title' => 'Best Services in Town!',
  'post_excerpt' => '',
  'post_name' => 'best-services-in-town',
  'post_modified' => '2017-08-21 05:41:23',
  'post_modified_gmt' => '2017-08-21 05:41:23',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/agency/?post_type=testimonial&#038;p=1152',
  'menu_order' => 0,
  'post_type' => 'testimonial',
  'meta_input' => 
  array (
    'testimonial_name' => 'Janet',
    'testimonial_title' => 'Designer',
    'external_link' => 'https://themify.me',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'testimonial-category' => 'testimonials',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1592,
  'post_date' => '2008-11-19 19:59:53',
  'post_date_gmt' => '2008-11-19 19:59:53',
  'post_content' => 'Aliquam metus diam, mattis fringilla adipiscing at, lacinia at nulla. Fusce ut sem est. In eu sagittis felis. In gravida arcu ut neque ornare vitae rutrum tu. Cras a fringilla nunc.',
  'post_title' => 'Exceeded Our Expectation',
  'post_excerpt' => '',
  'post_name' => 'exceeded-our-expectation',
  'post_modified' => '2017-08-21 05:41:22',
  'post_modified_gmt' => '2017-08-21 05:41:22',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/agency/?post_type=testimonial&#038;p=1156',
  'menu_order' => 0,
  'post_type' => 'testimonial',
  'meta_input' => 
  array (
    'testimonial_name' => 'Vanissa',
    'testimonial_title' => 'Manager',
    'external_link' => 'https://themify.me/',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'testimonial-category' => 'testimonials',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2343,
  'post_date' => '2013-12-16 19:49:21',
  'post_date_gmt' => '2013-12-16 19:49:21',
  'post_content' => 'Proin vitae accumsan congue, feugiat velit quis, sodales risus. Cras viverra sollicitudin euismod. Proin vitae accumsan arcu, elementum sagittis dolor. Sed vehicula sem vitae tellus semper luctus. Sed tincidunt auctor elit. Nullam adipiscing dapibus sem, a faucibus tur <span style="line-height: 1.5em;">vel elit eget egestas. Maecenas rutrum tempor arcu pellentesque vehicula. Nulla ac lacus accumsan, vestibulum mi id, pulvinar neque. Curabitur lacinia urna ac orci pharetra scelerisque. Phasellus et semper est, eget iaculis urna.</span>',
  'post_title' => 'Gallery One',
  'post_excerpt' => '',
  'post_name' => 'gallery-one',
  'post_modified' => '2017-08-21 05:42:30',
  'post_modified_gmt' => '2017-08-21 05:42:30',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?post_type=gallery&#038;p=2343',
  'menu_order' => 0,
  'post_type' => 'gallery',
  'meta_input' => 
  array (
    'gallery_shortcode' => '[gallery size="large" ids="2165,2162,2179,166,162"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'gallery-category' => 'home-section',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2344,
  'post_date' => '2013-12-16 20:12:44',
  'post_date_gmt' => '2013-12-16 20:12:44',
  'post_content' => 'Proin vitae accums <span style="line-height: 1.5em;">ssa congue, feugiat velit quis, sodales risus. Cras viverra sollicitudin euismod. Proin vitae accumsan arcu, elementum sagittis dolor. Sed vehicula sem vitae tellus semper luctus. Sed tincidunt auctor elit. Nullam adipiscing dapibus sem, a faucibus turpis fringilla eu</span>trum tempor arcu pellentesque vehicula. Nulla ac lacus accumsan, vestibulum mi id, pulvinar neque. Curabitur lacinia urna ac orci pharetra scelerisque. Phasellus et semper est, eget iaculis urna.',
  'post_title' => 'Gallery Two',
  'post_excerpt' => '',
  'post_name' => 'gallery-two',
  'post_modified' => '2017-08-21 05:42:28',
  'post_modified_gmt' => '2017-08-21 05:42:28',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?post_type=gallery&#038;p=2344',
  'menu_order' => 0,
  'post_type' => 'gallery',
  'meta_input' => 
  array (
    'gallery_shortcode' => '[gallery size="large" ids="162,160,159,2179,2168,2165,2162,166,158,148,149,141,144,147,140,85,83,64,1861,1866,1858,1853,1850,1846,1840,1824"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'gallery-category' => 'home-section',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2346,
  'post_date' => '2013-12-16 20:15:30',
  'post_date_gmt' => '2013-12-16 20:15:30',
  'post_content' => 'In sed massa congue, feugiat velit quis, sodales risus. Cras viverra sollicitudin euismod. Proin vitae accumsan arcu, elementum sagittis dolor. Sed vehicula sem vitae tellus semper luctus. Sed tincidunt auctor elit. Nullam adipiscing dapibus sem, a faucibus turpis fringilla eu. Nunc et nunc arcu pellentesque vehicula. Nulla ac lacus accumsan, vestibulum mi id, pulvinar neque. Curabitur lacinia urna ac orci pharetra scelerisque. Phasellus et semper est, eget iaculis urna.',
  'post_title' => 'Gallery Three',
  'post_excerpt' => '',
  'post_name' => 'gallery-three',
  'post_modified' => '2017-08-21 05:42:26',
  'post_modified_gmt' => '2017-08-21 05:42:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?post_type=gallery&#038;p=2346',
  'menu_order' => 0,
  'post_type' => 'gallery',
  'meta_input' => 
  array (
    'gallery_shortcode' => '[gallery size="large" ids="140,141,74,69,64"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'gallery-category' => 'home-section',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2522,
  'post_date' => '2014-01-07 22:31:18',
  'post_date_gmt' => '2014-01-07 22:31:18',
  'post_content' => 'Phasellus ac purus adipiscing massa dictum faucibus. Interdum et malesuada fames ac ante ipsum primis in faucibus. Vestibulum varius turpis vel pellentesque tempus. Sed vitae nulla magna. Curabitur quis diam vel dolor vulputate luctus. Mauris mollis ornare leo nec consectetur. Pellentesque tempus non turpis at pharetra.',
  'post_title' => 'Food Gallery',
  'post_excerpt' => '',
  'post_name' => 'watch-calories',
  'post_modified' => '2017-08-21 05:42:25',
  'post_modified_gmt' => '2017-08-21 05:42:25',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?post_type=gallery&#038;p=2522',
  'menu_order' => 0,
  'post_type' => 'gallery',
  'meta_input' => 
  array (
    'gallery_shortcode' => '[gallery link="file" ids="2523,2524,2525,2526,2527,2528,2529,2530,2531,2532,2533,2534,2535,2538" orderby="rand"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'gallery-category' => 'galleries',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2541,
  'post_date' => '2014-01-07 22:41:05',
  'post_date_gmt' => '2014-01-07 22:41:05',
  'post_content' => 'Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia Curae; Proin malesuada, augue a iaculis ultricies, leo lorem rutrum libero, a convallis odio nibh eget erat. Curabitur vitae dolor sed sapien bibendum congue eu in magna. In ac massa sed ligula dictum hendrerit et quis eros. Pellentesque cursus purus nec turpis cursus, non semper purus euismod.

Nulla nec porta ipsum. Nunc a libero interdum, laoreet dolor quis, tincidunt purus.',
  'post_title' => 'Places To Visit',
  'post_excerpt' => '',
  'post_name' => 'places-visit',
  'post_modified' => '2017-08-21 05:42:24',
  'post_modified_gmt' => '2017-08-21 05:42:24',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?post_type=gallery&#038;p=2541',
  'menu_order' => 0,
  'post_type' => 'gallery',
  'meta_input' => 
  array (
    'gallery_shortcode' => '[gallery link="file" size="large" ids="2542,2543,2544,2545,2546,2547,2548,2549,2550,2551,2572,2571"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'gallery-category' => 'galleries',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2552,
  'post_date' => '2014-01-07 22:50:29',
  'post_date_gmt' => '2014-01-07 22:50:29',
  'post_content' => 'In sed massa congue, feugiat velit quis, sodales risus. Cras viverra sollicitudin euismod. Proin vitae accumsan arcu, elementum sagittis dolor. Sed vehicula sem vitae tellus semper luctus. Sed tincidunt auctor elit. Nullam adipiscing dapibus sem, a faucibus turpis fringilla eu. Nunc et nunc neque.

Suspendisse fringilla vel elit eget egestas. Maecenas rutrum tempor arcu pellentesque vehicula. Nulla ac lacus accumsan, vestibulum mi id, pulvinar neque. Curabitur lacinia urna ac orci pharetra scelerisque. Phasellus et semper est, eget iaculis urna.',
  'post_title' => 'Colorless',
  'post_excerpt' => '',
  'post_name' => 'colorless',
  'post_modified' => '2017-08-21 05:42:22',
  'post_modified_gmt' => '2017-08-21 05:42:22',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?post_type=gallery&#038;p=2552',
  'menu_order' => 0,
  'post_type' => 'gallery',
  'meta_input' => 
  array (
    'gallery_shortcode' => '[gallery link="file" size="full" ids="2553,2554,2555,2556,2557,2558,2559,2560,2561,2562,2563,2564,2568,2567"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'gallery-category' => 'galleries',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2633,
  'post_date' => '2014-01-09 04:39:09',
  'post_date_gmt' => '2014-01-09 04:39:09',
  'post_content' => 'Phasellus ac purus adipiscing massa dictum faucibus. Interdum et malesuada fames ac ante ipsum primis in faucibus. Vestibulum varius turpis vel pellentesque tempus. Sed vitae nulla magna. Curabitur quis diam vel dolor vulputate luctus. Mauris mollis ornare leo nec consectetur. Pellentesque tempus non turpis at pharetra.',
  'post_title' => 'Another Gallery',
  'post_excerpt' => '',
  'post_name' => 'another-gallery',
  'post_modified' => '2017-08-21 05:42:21',
  'post_modified_gmt' => '2017-08-21 05:42:21',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?post_type=gallery&#038;p=2633',
  'menu_order' => 0,
  'post_type' => 'gallery',
  'meta_input' => 
  array (
    'gallery_shortcode' => '[gallery ids="2601,2602,2600,2608,2603,2604,2606,2607,2597"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'gallery-category' => 'galleries',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 71,
  'post_date' => '2013-07-08 06:06:17',
  'post_date_gmt' => '2013-07-08 06:06:17',
  'post_content' => 'Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia Curae; Aenean porta id orci eu sodales. Ut facilisis nisi hendrerit, pharetra lorem non, dignissim eros. Cras elit nisi, malesuada viverra risus molestie, luctus bibendum nisi. Nulla id ipsum scelerisque, fringilla purus ac, sollicitudin tellus. Quisque convallis lorem ac turpis rhoncus dignissim. Donec pulvinar, sapien id adipiscing faucibus, metus eros tincidunt quam, feugiat interdum ante risus quis nunc.',
  'post_title' => 'TV Commercial',
  'post_excerpt' => '',
  'post_name' => 'tv-commercial',
  'post_modified' => '2017-08-21 05:43:29',
  'post_modified_gmt' => '2017-08-21 05:43:29',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=portfolio&#038;p=71',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'videos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 250,
  'post_date' => '2008-01-25 19:20:02',
  'post_date_gmt' => '2008-01-25 19:20:02',
  'post_content' => 'Nulla ut mi risus. Phasellus pretium diam in risus vestibulum elementum. Donec quis ipsum sem, in elementum metus. Mauris sagittis cursus felis vitae mattis. Donec adipiscing consequat velit vitae convallis. Proin sit amet lectus non enim lobortis aliquet. Donec sit amet magna vitae ante pellentesque adipiscing.',
  'post_title' => 'In The Spotlight',
  'post_excerpt' => 'Fusce fermentum ante turpis, et congue',
  'post_name' => 'in-the-spotlight',
  'post_modified' => '2017-08-21 05:43:50',
  'post_modified_gmt' => '2017-08-21 05:43:50',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/metro/?post_type=portfolio&#038;p=250',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'featured',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 274,
  'post_date' => '2008-01-25 21:53:37',
  'post_date_gmt' => '2008-01-25 21:53:37',
  'post_content' => 'Sed sagittis, elit egestas rutrum vehicula, neque dolor fringilla lacus, ut rhoncus turpis augue vitae libero. Nam risus velit, rhoncus eget consectetur id, posuere at ligula. Vivamus imperdiet diam ac tortor tempus posuere. Curabitur at arcu id turpis posuere bibendum. Sed commodo mauris eget diam pretium cursus. In sagittis feugiat mauris, in ultrices mauris lacinia eu.',
  'post_title' => 'Photo Project',
  'post_excerpt' => 'Pellentesque diam velit, luctus vel porta',
  'post_name' => 'photo-project',
  'post_modified' => '2017-08-21 05:43:48',
  'post_modified_gmt' => '2017-08-21 05:43:48',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/metro/?post_type=portfolio&#038;p=274',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 283,
  'post_date' => '2008-01-25 22:15:36',
  'post_date_gmt' => '2008-01-25 22:15:36',
  'post_content' => 'Fusce augue velit, vulputate elementum semper congue, rhoncus adipiscing nisl. Curabitur vel risus eros, sed eleifend arcu. Donec porttitor hendrerit diam et blandit. Curabitur vitae velit ligula, vitae lobortis massa. Mauris mattis est quis dolor venenatis vitae pharetra diam gravida. Vivamus dignissim, ligula vel ultricies varius, nibh velit pretium leo, vel placerat ipsum.',
  'post_title' => 'Another Photo Shot',
  'post_excerpt' => 'Lorem ipsum dolor sit amet',
  'post_name' => 'another-photo-shot',
  'post_modified' => '2017-08-21 05:43:46',
  'post_modified_gmt' => '2017-08-21 05:43:46',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/metro/?post_type=portfolio&#038;p=283',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 288,
  'post_date' => '2008-01-25 22:22:08',
  'post_date_gmt' => '2008-01-25 22:22:08',
  'post_content' => 'In eu sagittis felis. In gravida arcu ut neque ornare vitae rutrum turpis vehicula. Nunc ultrices sem mollis metus rutrum non malesuada metus fermentum. Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia Curae; Pellentesque interdum rutrum quam.',
  'post_title' => 'Just a Model',
  'post_excerpt' => 'Fusce fermentum ante turpis, et congue',
  'post_name' => 'just-a-model',
  'post_modified' => '2017-08-21 05:43:44',
  'post_modified_gmt' => '2017-08-21 05:43:44',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/metro/?post_type=portfolio&#038;p=288',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'featured',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 291,
  'post_date' => '2008-01-25 22:25:29',
  'post_date_gmt' => '2008-01-25 22:25:29',
  'post_content' => 'The congue non egestas quis, condime estibulum congue nisl magna. Ut vulputate odio id dui convallis in adipiscing libero condimentum. Nunc et pharetra enim. Praesent pharetra, neque et luctus tempor, leo sapien faucibus leo, a dignissim turpis ipsum sed libero. Sed sed luctus purus. Aliquam faucibus turpis at libero consectetur euismod. Nam nunc lectus ntu.',
  'post_title' => 'In The Wood',
  'post_excerpt' => 'Morbi sed arcu at tortor ultricies',
  'post_name' => 'in-the-wood',
  'post_modified' => '2017-08-21 05:43:43',
  'post_modified_gmt' => '2017-08-21 05:43:43',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/metro/?post_type=portfolio&#038;p=291',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 292,
  'post_date' => '2008-01-25 22:26:46',
  'post_date_gmt' => '2008-01-25 22:26:46',
  'post_content' => 'Praesent pharetra, neque et luctus tempor estibulum congue nisl magna. Ut vulputate odio id dui convallis in adipiscing libero condimentum. Nunc et pharetra enim, leo sapien faucibus leo, a dignissim turpis ipsum sed libero. Sed sed luctus purus. Aliquam faucibus turpis at libero consectetur euismod. Nam nunc lectus, congue non egestas quis, condimentu.',
  'post_title' => 'Late Arrival',
  'post_excerpt' => 'Quisque ornare vestibulum nibh in lacinia',
  'post_name' => 'late-arrival',
  'post_modified' => '2017-08-21 05:43:41',
  'post_modified_gmt' => '2017-08-21 05:43:41',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/metro/?post_type=portfolio&#038;p=292',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 293,
  'post_date' => '2008-01-25 22:27:53',
  'post_date_gmt' => '2008-01-25 22:27:53',
  'post_content' => 'Praesent pharetra, neque et luctus tempor. Vestibulum congue nisl magna. Ut vulputate odio id dui convallis in adipiscing libero condimentum. Nunc et pharetra enim. Praesent pharetra, neque et luctus tempor, leo sapien faucibus leo, a dignissim turpis ipsum sed libero. Sed sed luctus purus. Aliquam faucibus turpis at libero consectetur euismod.',
  'post_title' => 'Summer Rain',
  'post_excerpt' => 'Vestibulum rutrum, metus vitae pretium',
  'post_name' => 'summer-rain',
  'post_modified' => '2017-08-21 05:43:39',
  'post_modified_gmt' => '2017-08-21 05:43:39',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/metro/?post_type=portfolio&#038;p=293',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1489,
  'post_date' => '2008-09-18 20:49:59',
  'post_date_gmt' => '2008-09-18 20:49:59',
  'post_content' => 'Aliquam faucibus turpis at libero consectetur euismod. Nam nunc lectus, congue non egestas quis, condimentum ut arcu. Nulla placerat, tortor non egestas rutrum, mi turpis adipiscing dui, et mollis turpis tortor vel orci. Cras a fringilla nunc. Suspendisse volutpat, eros congue scelerisque iaculis, magna odio sodales dui, vitae vulputate elit metus ac arcu. Mauris consequat rhoncus dolor id sagittis. Cras tortor elit, aliquet quis tincidunt eget, dignissim non tortor.',
  'post_title' => 'Just a Photo',
  'post_excerpt' => '',
  'post_name' => 'just-a-photo',
  'post_modified' => '2017-08-21 05:43:36',
  'post_modified_gmt' => '2017-08-21 05:43:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/agency/?post_type=portfolio&#038;p=1089',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'featured, photos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1570,
  'post_date' => '2008-09-18 20:51:12',
  'post_date_gmt' => '2008-09-18 20:51:12',
  'post_content' => 'Praesent pharetra, neque et luctus tempor, leo sapien faucibus leo, a dignissim turpis ipsum sed libero. Sed sed luctus purus. Aliquam faucibus turpis at libero consectetur euismod. Nam nunc lectus, congue non egestas quis, condimentum ut arcu. Nulla placerat, tortor non egestas rutrum, mi turpis adipiscing dui, et mollis turpis tortor vel orci. Cras a fringilla nunc. Suspendisse volutpat, eros congue scelerisque iaculis, magna odio sodales dui, vitae vulputate elit metus ac arcu.',
  'post_title' => 'Photo Two',
  'post_excerpt' => 'Praesent pharetra, neque et luctus tempor, leo sapien.',
  'post_name' => 'photo-two',
  'post_modified' => '2017-08-21 05:43:35',
  'post_modified_gmt' => '2017-08-21 05:43:35',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/agency/?post_type=portfolio&#038;p=1091',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1571,
  'post_date' => '2008-09-18 20:52:05',
  'post_date_gmt' => '2008-09-18 20:52:05',
  'post_content' => 'Aliquam metus diam, mattis fringilla adipiscing at, lacinia at nulla. Fusce ut sem est. In eu sagittis felis. In gravida arcu ut neque ornare vitae rutrum turpis vehicula. Nunc ultrices sem mollis metus rutrum non malesuada metus fermentum. Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia Curae; Pellentesque interdum rutrum quam, a pharetra est pulvinar ac. Vestibulum congue nisl magna.',
  'post_title' => 'Shot Number Three',
  'post_excerpt' => 'Aliquam metus diam, mattis fringilla adipiscing at',
  'post_name' => 'shot-number-three',
  'post_modified' => '2017-08-21 05:43:32',
  'post_modified_gmt' => '2017-08-21 05:43:32',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/agency/?post_type=portfolio&#038;p=1093',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'featured',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 1572,
  'post_date' => '2008-09-18 20:52:37',
  'post_date_gmt' => '2008-09-18 20:52:37',
  'post_content' => 'Ut euismod ligula eu tellus interdum mattis ac eu nulla. Phasellus cursus, lacus quis convallis aliquet, dolor urna ullamcorper mi, eget dapibus velit est vitae nisi. Aliquam erat nulla, sodales at imperdiet vitae, convallis vel dui. Sed ultrices felis ut justo suscipit vestibulum. Pellentesque nisl nisi, vehicula vitae hendrerit vel, mattis eget mauris. Donec consequat eros eget lectus dictum sit amet ultrices neque sodales. Aliquam metus diam, mattis fringilla adipiscing at, lacinia at nulla. Fusce ut sem est. In eu sagittis felis.',
  'post_title' => 'Beautiful Shot',
  'post_excerpt' => 'Ut euismod ligula eu tellus interdum mattis ac eu nulla.',
  'post_name' => 'beautiful-shot',
  'post_modified' => '2017-08-21 05:43:31',
  'post_modified_gmt' => '2017-08-21 05:43:31',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/agency/?post_type=portfolio&#038;p=1095',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 63,
  'post_date' => '2013-07-12 05:54:32',
  'post_date_gmt' => '2013-07-12 05:54:32',
  'post_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Phasellus accumsan consectetur erat ac sodales. Mauris rhoncus dolor sed ante vulputate, ut mollis augue semper. Etiam eleifend turpis lorem, in sollicitudin enim cursus in. Donec at interdum felis. Cras tristique eget ante sit amet iaculis. Aliquam eu egestas nulla.',
  'post_title' => 'Red Rose',
  'post_excerpt' => '',
  'post_name' => 'red-rose',
  'post_modified' => '2017-08-21 05:43:23',
  'post_modified_gmt' => '2017-08-21 05:43:23',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=portfolio&#038;p=63',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'gallery_shortcode' => '[gallery ids="143,142,144"]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'featured',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 65,
  'post_date' => '2013-07-12 05:58:27',
  'post_date_gmt' => '2013-07-12 05:58:27',
  'post_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Phasellus accumsan consectetur erat ac sodales. Mauris rhoncus dolor sed ante vulputate, ut mollis augue semper. Etiam eleifend turpis lorem, in sollicitudin enim cursus in. Donec at interdum felis. Cras tristique eget ante sit amet iaculis. Aliquam eu egestas nulla.',
  'post_title' => 'Watercolor',
  'post_excerpt' => '',
  'post_name' => 'watercolor',
  'post_modified' => '2017-08-21 05:43:22',
  'post_modified_gmt' => '2017-08-21 05:43:22',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=portfolio&#038;p=65',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'gallery_shortcode' => '[gallery ids="1644,218"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'featured',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 73,
  'post_date' => '2013-07-09 06:09:48',
  'post_date_gmt' => '2013-07-09 06:09:48',
  'post_content' => 'Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia Curae; Aenean porta id orci eu sodales. Ut facilisis nisi hendrerit, pharetra lorem non, dignissim eros. Cras elit nisi, malesuada viverra risus molestie, luctus bibendum nisi. Nulla id ipsum scelerisque, fringilla purus ac, sollicitudin tellus. Quisque convallis lorem ac turpis rhoncus dignissim. Donec pulvinar, sapien id adipiscing faucibus, metus eros tincidunt quam, feugiat interdum ante risus quis nunc.',
  'post_title' => 'Summer Vacation',
  'post_excerpt' => '',
  'post_name' => 'summer-vacation',
  'post_modified' => '2017-08-21 05:43:28',
  'post_modified_gmt' => '2017-08-21 05:43:28',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=portfolio&#038;p=73',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'vintage',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 151,
  'post_date' => '2013-07-11 23:16:09',
  'post_date_gmt' => '2013-07-11 23:16:09',
  'post_content' => 'Donec at interdum felis. Cras tristique eget ante sit amet iaculis. Aliquam eu egestas nulla. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Phasellus accumsan consectetur erat ac sodales. Mauris rhoncus dolor sed ante vulputate, ut mollis augue semper. Etiam eleifend turpis lorem, in sollicitudin enim cursus in.',
  'post_title' => 'Black &amp; White',
  'post_excerpt' => '',
  'post_name' => 'black-white',
  'post_modified' => '2017-08-21 05:43:23',
  'post_modified_gmt' => '2017-08-21 05:43:23',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=portfolio&#038;p=151',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'gallery_shortcode' => '[gallery link="file" ids="152,154,155"]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'featured, photos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 157,
  'post_date' => '2008-07-12 23:26:37',
  'post_date_gmt' => '2008-07-12 23:26:37',
  'post_content' => 'Fusce augue velit, vulputate elementum semper congue, rhoncus adipiscing nisl. Curabitur vel risus eros, sed eleifend arcu. Donec porttitor hendrerit diam et blandit. Curabitur vitae velit ligula, vitae lobortis massa. Mauris mattis est quis dolor venenatis vitae pharetra diam gravida. Vivamus dignissim, ligula vel ultricies varius, nibh velit pretium leo.',
  'post_title' => 'Dark Gallery',
  'post_excerpt' => '',
  'post_name' => 'dark-gallery',
  'post_modified' => '2017-08-21 05:43:37',
  'post_modified_gmt' => '2017-08-21 05:43:37',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=portfolio&#038;p=157',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'featured, photos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 161,
  'post_date' => '2013-07-10 23:28:30',
  'post_date_gmt' => '2013-07-10 23:28:30',
  'post_content' => 'Vivamus dignissim, ligula vel ultricies varius, nibh velit pretium leo, vel placerat ipsum risus luctus purus. Fusce augue velit, vulputate elementum semper congue, rhoncus adipiscing nisl. Curabitur vel risus eros, sed eleifend arcu. Donec porttitor hendrerit diam et blandit. Curabitur vitae velit ligula, vitae lobortis massa. Mauris mattis est quis dolor venenatis vitae pharetra diam gravida.',
  'post_title' => 'On The Ride',
  'post_excerpt' => '',
  'post_name' => 'on-the-ride',
  'post_modified' => '2017-08-21 05:43:26',
  'post_modified_gmt' => '2017-08-21 05:43:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=portfolio&#038;p=161',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 165,
  'post_date' => '2013-07-10 23:45:57',
  'post_date_gmt' => '2013-07-10 23:45:57',
  'post_content' => 'Curabitur venenatis vehicula mattis. Nunc eleifend consectetur odio sit amet viverra. Ut euismod ligula eu tellus interdum mattis ac eu nulla. Phasellus cursus, lacus quis convallis aliquet, dolor urna ullamcorper mi, eget dapibus velit est vitae nisi.',
  'post_title' => 'Red Rose',
  'post_excerpt' => '',
  'post_name' => 'red-rose-2',
  'post_modified' => '2017-08-21 05:43:24',
  'post_modified_gmt' => '2017-08-21 05:43:24',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/flat/?post_type=portfolio&#038;p=165',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'gallery_shortcode' => '[gallery ids="166,212"]',
    'builder_switch_frontend' => '0',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photos',
  ),
  'has_thumbnail' => true,
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2887,
  'post_date' => '2014-12-05 21:51:29',
  'post_date_gmt' => '2014-12-05 21:51:29',
  'post_content' => '',
  'post_title' => 'Top',
  'post_excerpt' => '',
  'post_name' => 'welcome-2',
  'post_modified' => '2018-04-18 21:46:36',
  'post_modified_gmt' => '2018-04-18 21:46:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2887',
  'menu_order' => 1,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2887',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#Welcome',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 3018,
  'post_date' => '2014-12-19 05:58:02',
  'post_date_gmt' => '2014-12-19 05:58:02',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '3018',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=3018',
  'menu_order' => 1,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2930',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2985,
  'post_date' => '2014-12-19 02:01:18',
  'post_date_gmt' => '2014-12-19 02:01:18',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2985',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2985',
  'menu_order' => 1,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2883',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 4563,
  'post_date' => '2017-03-03 02:19:37',
  'post_date_gmt' => '2017-03-03 02:19:37',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '4563',
  'post_modified' => '2018-06-06 01:14:32',
  'post_modified_gmt' => '2018-06-06 01:14:32',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=4563',
  'menu_order' => 1,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2930',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2888,
  'post_date' => '2014-12-05 21:51:29',
  'post_date_gmt' => '2014-12-05 21:51:29',
  'post_content' => '',
  'post_title' => 'Works',
  'post_excerpt' => '',
  'post_name' => 'works',
  'post_modified' => '2018-04-18 21:46:36',
  'post_modified_gmt' => '2018-04-18 21:46:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2888',
  'menu_order' => 2,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2888',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#Works',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2936,
  'post_date' => '2014-12-16 17:12:13',
  'post_date_gmt' => '2014-12-16 17:12:13',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2936',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 2883,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2936',
  'menu_order' => 2,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2985',
    '_menu_item_object_id' => '2930',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2986,
  'post_date' => '2014-12-19 02:01:49',
  'post_date_gmt' => '2014-12-19 02:01:49',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2986',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 2930,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2986',
  'menu_order' => 2,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '3018',
    '_menu_item_object_id' => '2883',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 4561,
  'post_date' => '2017-03-03 02:19:37',
  'post_date_gmt' => '2017-03-03 02:19:37',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '4561',
  'post_modified' => '2018-06-06 01:14:32',
  'post_modified_gmt' => '2018-06-06 01:14:32',
  'post_content_filtered' => '',
  'post_parent' => 2930,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=4561',
  'menu_order' => 2,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '4563',
    '_menu_item_object_id' => '2883',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 3017,
  'post_date' => '2014-12-19 05:58:02',
  'post_date_gmt' => '2014-12-19 05:58:02',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '3017',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 2930,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=3017',
  'menu_order' => 3,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '3018',
    '_menu_item_object_id' => '2979',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2889,
  'post_date' => '2014-12-05 21:51:29',
  'post_date_gmt' => '2014-12-05 21:51:29',
  'post_content' => '',
  'post_title' => 'Gallery',
  'post_excerpt' => '',
  'post_name' => 'gallery-2',
  'post_modified' => '2018-04-18 21:46:36',
  'post_modified_gmt' => '2018-04-18 21:46:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2889',
  'menu_order' => 3,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2889',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#Gallery',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2982,
  'post_date' => '2014-12-19 01:22:39',
  'post_date_gmt' => '2014-12-19 01:22:39',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2982',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 2883,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2982',
  'menu_order' => 3,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2985',
    '_menu_item_object_id' => '2979',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 4562,
  'post_date' => '2017-03-03 02:19:37',
  'post_date_gmt' => '2017-03-03 02:19:37',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '4562',
  'post_modified' => '2018-06-06 01:14:32',
  'post_modified_gmt' => '2018-06-06 01:14:32',
  'post_content_filtered' => '',
  'post_parent' => 2930,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=4562',
  'menu_order' => 3,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '4563',
    '_menu_item_object_id' => '2979',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2683,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => '',
  'post_title' => 'About',
  'post_excerpt' => '',
  'post_name' => 'main-2',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2683',
  'menu_order' => 4,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2683',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#about',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2890,
  'post_date' => '2014-12-05 21:51:29',
  'post_date_gmt' => '2014-12-05 21:51:29',
  'post_content' => '',
  'post_title' => 'Testimonials',
  'post_excerpt' => '',
  'post_name' => 'testimonials-4',
  'post_modified' => '2018-04-18 21:46:36',
  'post_modified_gmt' => '2018-04-18 21:46:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2890',
  'menu_order' => 4,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2890',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#Testimonials',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2937,
  'post_date' => '2014-12-16 17:12:13',
  'post_date_gmt' => '2014-12-16 17:12:13',
  'post_content' => '',
  'post_title' => 'Gallery Posts',
  'post_excerpt' => '',
  'post_name' => 'gallery-posts',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2937',
  'menu_order' => 4,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2937',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#gallery-post',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 4568,
  'post_date' => '2017-03-03 02:39:27',
  'post_date_gmt' => '2017-03-03 02:39:27',
  'post_content' => '',
  'post_title' => 'Portfolio',
  'post_excerpt' => '',
  'post_name' => 'portfolio-2',
  'post_modified' => '2018-06-06 01:14:32',
  'post_modified_gmt' => '2018-06-06 01:14:32',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=4568',
  'menu_order' => 4,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2397',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2684,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => '',
  'post_title' => 'Portfolio',
  'post_excerpt' => '',
  'post_name' => 'portfolio',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2684',
  'menu_order' => 5,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2684',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#portfolio',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2938,
  'post_date' => '2014-12-16 17:12:13',
  'post_date_gmt' => '2014-12-16 17:12:13',
  'post_content' => '',
  'post_title' => 'WP Gallery',
  'post_excerpt' => '',
  'post_name' => 'wp-gallery-2',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2938',
  'menu_order' => 5,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2938',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#wp-gallery',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 4569,
  'post_date' => '2017-03-03 02:40:15',
  'post_date_gmt' => '2017-03-03 02:40:15',
  'post_content' => '',
  'post_title' => 'Blog',
  'post_excerpt' => '',
  'post_name' => 'blog',
  'post_modified' => '2018-06-06 01:14:32',
  'post_modified_gmt' => '2018-06-06 01:14:32',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=4569',
  'menu_order' => 5,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2393',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 4745,
  'post_date' => '2018-04-18 21:46:36',
  'post_date_gmt' => '2018-04-18 21:46:36',
  'post_content' => '',
  'post_title' => 'Horizontal',
  'post_excerpt' => '',
  'post_name' => 'horizontal',
  'post_modified' => '2018-04-18 21:46:36',
  'post_modified_gmt' => '2018-04-18 21:46:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=4745',
  'menu_order' => 5,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '4745',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#horizontal',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2891,
  'post_date' => '2014-12-05 21:51:29',
  'post_date_gmt' => '2014-12-05 21:51:29',
  'post_content' => '',
  'post_title' => 'Services',
  'post_excerpt' => '',
  'post_name' => 'services-2',
  'post_modified' => '2018-04-18 21:46:36',
  'post_modified_gmt' => '2018-04-18 21:46:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2891',
  'menu_order' => 6,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2891',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#Services',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2685,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => '',
  'post_title' => 'Get Social',
  'post_excerpt' => '',
  'post_name' => 'get-social',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2685',
  'menu_order' => 6,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2685',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#get-social',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2939,
  'post_date' => '2014-12-16 17:12:13',
  'post_date_gmt' => '2014-12-16 17:12:13',
  'post_content' => '',
  'post_title' => 'Team Slider',
  'post_excerpt' => '',
  'post_name' => 'team-slider-2',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2939',
  'menu_order' => 6,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2939',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#team-slider',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 4566,
  'post_date' => '2017-03-03 02:32:30',
  'post_date_gmt' => '2017-03-03 02:32:30',
  'post_content' => '',
  'post_title' => 'Testimonial',
  'post_excerpt' => '',
  'post_name' => 'testimonial-2',
  'post_modified' => '2018-06-06 01:14:32',
  'post_modified_gmt' => '2018-06-06 01:14:32',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=4566',
  'menu_order' => 6,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2395',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2892,
  'post_date' => '2014-12-05 21:51:29',
  'post_date_gmt' => '2014-12-05 21:51:29',
  'post_content' => '',
  'post_title' => 'Video',
  'post_excerpt' => '',
  'post_name' => 'video-2',
  'post_modified' => '2018-04-18 21:46:36',
  'post_modified_gmt' => '2018-04-18 21:46:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2892',
  'menu_order' => 7,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2892',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#Video',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2686,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => '',
  'post_title' => 'Testimonials',
  'post_excerpt' => '',
  'post_name' => 'testimonials-2',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2686',
  'menu_order' => 7,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2686',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#testimonials',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2940,
  'post_date' => '2014-12-16 17:12:13',
  'post_date_gmt' => '2014-12-16 17:12:13',
  'post_content' => '',
  'post_title' => 'Vimeo Video',
  'post_excerpt' => '',
  'post_name' => 'video-3',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2940',
  'menu_order' => 7,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2940',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#vimeo-video',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 4567,
  'post_date' => '2017-03-03 02:32:30',
  'post_date_gmt' => '2017-03-03 02:32:30',
  'post_content' => '',
  'post_title' => 'Team',
  'post_excerpt' => '',
  'post_name' => 'team',
  'post_modified' => '2018-06-06 01:14:32',
  'post_modified_gmt' => '2018-06-06 01:14:32',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=4567',
  'menu_order' => 7,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2453',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2893,
  'post_date' => '2014-12-05 21:51:29',
  'post_date_gmt' => '2014-12-05 21:51:29',
  'post_content' => '',
  'post_title' => 'Team',
  'post_excerpt' => '',
  'post_name' => 'team-2',
  'post_modified' => '2018-04-18 21:46:36',
  'post_modified_gmt' => '2018-04-18 21:46:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2893',
  'menu_order' => 8,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2893',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#Team',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2687,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => '',
  'post_title' => 'Address',
  'post_excerpt' => '',
  'post_name' => 'address',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2687',
  'menu_order' => 8,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2687',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#address',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2941,
  'post_date' => '2014-12-16 17:12:13',
  'post_date_gmt' => '2014-12-16 17:12:13',
  'post_content' => '',
  'post_title' => 'Testimonial',
  'post_excerpt' => '',
  'post_name' => 'testimonial',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2941',
  'menu_order' => 8,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2941',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#testimonial',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2894,
  'post_date' => '2014-12-05 21:51:29',
  'post_date_gmt' => '2014-12-05 21:51:29',
  'post_content' => '',
  'post_title' => 'Buy',
  'post_excerpt' => '',
  'post_name' => 'buy',
  'post_modified' => '2018-04-18 21:46:37',
  'post_modified_gmt' => '2018-04-18 21:46:37',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2894',
  'menu_order' => 9,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2894',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#Buy',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2688,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => '',
  'post_title' => 'More',
  'post_excerpt' => '',
  'post_name' => 'more-2',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2688',
  'menu_order' => 9,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2688',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2942,
  'post_date' => '2014-12-16 17:12:13',
  'post_date_gmt' => '2014-12-16 17:12:13',
  'post_content' => '',
  'post_title' => 'Contact',
  'post_excerpt' => '',
  'post_name' => 'contact-2',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2942',
  'menu_order' => 9,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2942',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#contact',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 4744,
  'post_date' => '2018-04-18 21:46:15',
  'post_date_gmt' => '2018-04-18 21:46:15',
  'post_content' => '',
  'post_title' => 'More',
  'post_excerpt' => '',
  'post_name' => 'more',
  'post_modified' => '2018-04-18 21:46:37',
  'post_modified_gmt' => '2018-04-18 21:46:37',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=4744',
  'menu_order' => 10,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '4744',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2692,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2692',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2692',
  'menu_order' => 10,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2688',
    '_menu_item_object_id' => '2636',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2944,
  'post_date' => '2014-12-16 17:18:14',
  'post_date_gmt' => '2014-12-16 17:18:14',
  'post_content' => '',
  'post_title' => 'More',
  'post_excerpt' => '',
  'post_name' => 'more-5',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2944',
  'menu_order' => 10,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '2944',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2700,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2700',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2700',
  'menu_order' => 11,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2688',
    '_menu_item_object_id' => '2203',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2945,
  'post_date' => '2014-12-16 17:18:14',
  'post_date_gmt' => '2014-12-16 17:18:14',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2945',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2945',
  'menu_order' => 11,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2944',
    '_menu_item_object_id' => '2636',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2984,
  'post_date' => '2014-12-19 01:31:41',
  'post_date_gmt' => '2014-12-19 01:31:41',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2984',
  'post_modified' => '2018-04-18 21:46:37',
  'post_modified_gmt' => '2018-04-18 21:46:37',
  'post_content_filtered' => '',
  'post_parent' => 2883,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2984',
  'menu_order' => 11,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '4744',
    '_menu_item_object_id' => '2930',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2954,
  'post_date' => '2014-12-16 17:18:14',
  'post_date_gmt' => '2014-12-16 17:18:14',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2954',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2954',
  'menu_order' => 12,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2944',
    '_menu_item_object_id' => '2203',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2983,
  'post_date' => '2014-12-19 01:31:41',
  'post_date_gmt' => '2014-12-19 01:31:41',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2983',
  'post_modified' => '2018-04-18 21:46:37',
  'post_modified_gmt' => '2018-04-18 21:46:37',
  'post_content_filtered' => '',
  'post_parent' => 2883,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2983',
  'menu_order' => 12,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '4744',
    '_menu_item_object_id' => '2979',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2899,
  'post_date' => '2014-12-05 21:54:52',
  'post_date_gmt' => '2014-12-05 21:54:52',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2899',
  'post_modified' => '2018-04-18 21:46:37',
  'post_modified_gmt' => '2018-04-18 21:46:37',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2899',
  'menu_order' => 13,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '4744',
    '_menu_item_object_id' => '2499',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2694,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2694',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2694',
  'menu_order' => 13,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2688',
    '_menu_item_object_id' => '2499',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2906,
  'post_date' => '2014-12-05 21:54:52',
  'post_date_gmt' => '2014-12-05 21:54:52',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2906',
  'post_modified' => '2018-04-18 21:46:37',
  'post_modified_gmt' => '2018-04-18 21:46:37',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2906',
  'menu_order' => 14,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2899',
    '_menu_item_object_id' => '2203',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2695,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2695',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2695',
  'menu_order' => 14,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2694',
    '_menu_item_object_id' => '2393',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2947,
  'post_date' => '2014-12-16 17:18:14',
  'post_date_gmt' => '2014-12-16 17:18:14',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2947',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2947',
  'menu_order' => 14,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2944',
    '_menu_item_object_id' => '2499',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2903,
  'post_date' => '2014-12-05 21:54:52',
  'post_date_gmt' => '2014-12-05 21:54:52',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2903',
  'post_modified' => '2018-04-18 21:46:37',
  'post_modified_gmt' => '2018-04-18 21:46:37',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2903',
  'menu_order' => 15,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2899',
    '_menu_item_object_id' => '2397',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2697,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2697',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2697',
  'menu_order' => 15,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2694',
    '_menu_item_object_id' => '2397',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2948,
  'post_date' => '2014-12-16 17:18:14',
  'post_date_gmt' => '2014-12-16 17:18:14',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2948',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2948',
  'menu_order' => 15,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2947',
    '_menu_item_object_id' => '2393',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2904,
  'post_date' => '2014-12-05 21:54:52',
  'post_date_gmt' => '2014-12-05 21:54:52',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2904',
  'post_modified' => '2018-04-18 21:46:37',
  'post_modified_gmt' => '2018-04-18 21:46:37',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2904',
  'menu_order' => 16,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2899',
    '_menu_item_object_id' => '2395',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2698,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2698',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2698',
  'menu_order' => 16,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2694',
    '_menu_item_object_id' => '2453',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2951,
  'post_date' => '2014-12-16 17:18:14',
  'post_date_gmt' => '2014-12-16 17:18:14',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2951',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2951',
  'menu_order' => 16,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2947',
    '_menu_item_object_id' => '2397',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2902,
  'post_date' => '2014-12-05 21:54:52',
  'post_date_gmt' => '2014-12-05 21:54:52',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2902',
  'post_modified' => '2018-04-18 21:46:37',
  'post_modified_gmt' => '2018-04-18 21:46:37',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2902',
  'menu_order' => 17,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2899',
    '_menu_item_object_id' => '2453',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2699,
  'post_date' => '2014-01-09 20:33:06',
  'post_date_gmt' => '2014-01-09 20:33:06',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2699',
  'post_modified' => '2018-06-06 01:13:26',
  'post_modified_gmt' => '2018-06-06 01:13:26',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2699',
  'menu_order' => 17,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2694',
    '_menu_item_object_id' => '2395',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-3-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2952,
  'post_date' => '2014-12-16 17:18:14',
  'post_date_gmt' => '2014-12-16 17:18:14',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2952',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2952',
  'menu_order' => 17,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2947',
    '_menu_item_object_id' => '2453',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2905,
  'post_date' => '2014-12-05 21:54:52',
  'post_date_gmt' => '2014-12-05 21:54:52',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2905',
  'post_modified' => '2018-04-18 21:46:37',
  'post_modified_gmt' => '2018-04-18 21:46:37',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2905',
  'menu_order' => 18,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2899',
    '_menu_item_object_id' => '2393',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}

$post = array (
  'ID' => 2953,
  'post_date' => '2014-12-16 17:18:14',
  'post_date_gmt' => '2014-12-16 17:18:14',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '2953',
  'post_modified' => '2015-01-28 20:58:19',
  'post_modified_gmt' => '2015-01-28 20:58:19',
  'post_content_filtered' => '',
  'post_parent' => 2499,
  'guid' => 'https://themify.me/demo/themes/fullpane/?p=2953',
  'menu_order' => 18,
  'post_type' => 'nav_menu_item',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '2947',
    '_menu_item_object_id' => '2395',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'demo-2-menu',
  ),
);

if( ERASEDEMO ) {
	themify_undo_import_post( $post );
} else {
	themify_import_post( $post );
}


function themify_import_get_term_id_from_slug( $slug ) {
	$menu = get_term_by( "slug", $slug, "nav_menu" );
	return is_wp_error( $menu ) ? 0 : (int) $menu->term_id;
}

	$widgets = get_option( "widget_themify-feature-posts" );
$widgets[1002] = array (
  'title' => 'Latest Posts',
  'category' => '3',
  'show_count' => '5',
  'show_date' => NULL,
  'show_thumb' => 'on',
  'display' => 'none',
  'hide_title' => NULL,
  'thumb_width' => '75',
  'thumb_height' => '60',
  'excerpt_length' => '55',
);
update_option( "widget_themify-feature-posts", $widgets );

$widgets = get_option( "widget_themify-flickr" );
$widgets[1003] = array (
  'title' => 'Photo Stream',
  'username' => '52839779@N02',
  'show_count' => '8',
  'show_link' => NULL,
);
update_option( "widget_themify-flickr", $widgets );

$widgets = get_option( "widget_themify-twitter" );
$widgets[1004] = array (
  'title' => 'Twitter Widget',
  'username' => 'themify',
  'show_count' => '3',
  'hide_timestamp' => NULL,
  'show_follow' => NULL,
  'follow_text' => '→ Follow me',
  'include_retweets' => NULL,
  'exclude_replies' => NULL,
);
update_option( "widget_themify-twitter", $widgets );

$widgets = get_option( "widget_themify-social-links" );
$widgets[1005] = array (
  'title' => '',
  'show_link_name' => NULL,
  'open_new_window' => NULL,
  'thumb_width' => '',
  'thumb_height' => '',
);
update_option( "widget_themify-social-links", $widgets );

$widgets = get_option( "widget_themify-twitter" );
$widgets[1006] = array (
  'title' => 'Latest Tweets',
  'username' => 'themify',
  'show_count' => '3',
  'hide_timestamp' => NULL,
  'show_follow' => NULL,
  'follow_text' => '→ Follow me',
  'include_retweets' => 'on',
  'exclude_replies' => NULL,
);
update_option( "widget_themify-twitter", $widgets );

$widgets = get_option( "widget_themify-feature-posts" );
$widgets[1007] = array (
  'title' => 'Recent Posts',
  'category' => '0',
  'show_count' => '3',
  'show_date' => 'on',
  'show_thumb' => 'on',
  'display' => 'none',
  'hide_title' => NULL,
  'thumb_width' => '50',
  'thumb_height' => '50',
  'excerpt_length' => '55',
  'orderby' => 'date',
  'order' => 'DESC',
);
update_option( "widget_themify-feature-posts", $widgets );

$widgets = get_option( "widget_text" );
$widgets[1008] = array (
  'title' => 'About',
  'text' => '<h4>Purchase this <a href="https://themify.me">theme</a> now.</h4>

Follow us → [team-social label="Twitter" link="http://twitter.com/themify" icon="twitter"] [team-social label="Facebook" link="http://facebook.com/themify" icon="facebook"]

Responsive and retina-ready right out of the box, Fullpane also includes the easy-to-use Themify Builder, allowing you to build the page of your dreams without having to touch the code.',
  'filter' => true,
);
update_option( "widget_text", $widgets );



$sidebars_widgets = array (
  'sidebar-main' => 
  array (
    0 => 'themify-feature-posts-1002',
    1 => 'themify-flickr-1003',
    2 => 'themify-twitter-1004',
  ),
  'social-widget' => 
  array (
    0 => 'themify-social-links-1005',
  ),
  'footer-widget-1' => 
  array (
    0 => 'themify-twitter-1006',
  ),
  'footer-widget-2' => 
  array (
    0 => 'themify-feature-posts-1007',
  ),
  'footer-widget-3' => 
  array (
    0 => 'text-1008',
  ),
); 
update_option( "sidebars_widgets", $sidebars_widgets );

$menu_locations = array();
$menu = get_terms( "nav_menu", array( "slug" => "main-menu" ) );
if( is_array( $menu ) && ! empty( $menu ) ) $menu_locations["main-nav"] = $menu[0]->term_id;
set_theme_mod( "nav_menu_locations", $menu_locations );


$homepage = get_posts( array( 'name' => 'home', 'post_type' => 'page' ) );
			if( is_array( $homepage ) && ! empty( $homepage ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $homepage[0]->ID );
			}
			
	ob_start(); ?>a:72:{s:16:"setting-page_404";s:1:"0";s:21:"setting-webfonts_list";s:11:"recommended";s:22:"setting-default_layout";s:8:"sidebar1";s:27:"setting-default_post_layout";s:9:"list-post";s:30:"setting-default_layout_display";s:7:"content";s:25:"setting-default_more_text";s:4:"More";s:21:"setting-index_orderby";s:4:"date";s:19:"setting-index_order";s:4:"DESC";s:31:"setting-image_post_feature_size";s:5:"blank";s:32:"setting-default_page_post_layout";s:8:"sidebar1";s:38:"setting-image_post_single_feature_size";s:5:"blank";s:27:"setting-default_page_layout";s:8:"sidebar1";s:38:"setting-default_portfolio_index_layout";s:12:"sidebar-none";s:43:"setting-default_portfolio_index_post_layout";s:5:"grid4";s:39:"setting-default_portfolio_index_display";s:4:"none";s:50:"setting-default_portfolio_index_post_meta_category";s:3:"yes";s:41:"setting-default_portfolio_index_post_date";s:3:"yes";s:39:"setting-default_portfolio_single_layout";s:12:"sidebar-none";s:51:"setting-default_portfolio_single_post_meta_category";s:3:"yes";s:49:"setting-default_portfolio_single_image_post_width";s:3:"580";s:50:"setting-default_portfolio_single_image_post_height";s:3:"460";s:22:"themify_portfolio_slug";s:7:"project";s:34:"setting-default_team_single_layout";s:12:"sidebar-none";s:17:"themify_team_slug";s:4:"team";s:53:"setting-customizer_responsive_design_tablet_landscape";s:4:"1024";s:43:"setting-customizer_responsive_design_tablet";s:3:"768";s:43:"setting-customizer_responsive_design_mobile";s:3:"480";s:33:"setting-mobile_menu_trigger_point";s:4:"1200";s:24:"setting-gallery_lightbox";s:8:"lightbox";s:26:"setting-page_builder_cache";s:2:"on";s:27:"setting-script_minification";s:7:"disable";s:27:"setting-page_builder_expiry";s:1:"2";s:33:"setting-portfolio_slider_autoplay";s:3:"off";s:31:"setting-portfolio_slider_effect";s:6:"scroll";s:41:"setting-portfolio_slider_transition_speed";s:3:"500";s:32:"setting-portfolio_slider_visible";s:1:"1";s:31:"setting-portfolio_slider_scroll";s:1:"1";s:25:"setting-menu_bar_position";s:14:"menubar-bottom";s:19:"setting-exclude_rss";s:2:"on";s:22:"setting-footer_widgets";s:17:"footerwidget-3col";s:27:"setting-global_feature_size";s:5:"large";s:22:"setting-link_icon_type";s:9:"font-icon";s:32:"setting-link_type_themify-link-0";s:10:"image-icon";s:33:"setting-link_title_themify-link-0";s:7:"Twitter";s:32:"setting-link_link_themify-link-0";s:26:"http://twitter.com/themify";s:31:"setting-link_img_themify-link-0";s:85:"https://themify.me/demo/themes/fullpane/wp-content/themes/fullpane/images/twitter.png";s:32:"setting-link_type_themify-link-1";s:10:"image-icon";s:33:"setting-link_title_themify-link-1";s:8:"Facebook";s:32:"setting-link_link_themify-link-1";s:27:"http://facebook.com/themify";s:31:"setting-link_img_themify-link-1";s:86:"https://themify.me/demo/themes/fullpane/wp-content/themes/fullpane/images/facebook.png";s:32:"setting-link_type_themify-link-2";s:10:"image-icon";s:33:"setting-link_title_themify-link-2";s:7:"Google+";s:31:"setting-link_img_themify-link-2";s:89:"https://themify.me/demo/themes/fullpane/wp-content/themes/fullpane/images/google-plus.png";s:32:"setting-link_type_themify-link-3";s:10:"image-icon";s:33:"setting-link_title_themify-link-3";s:7:"YouTube";s:31:"setting-link_img_themify-link-3";s:85:"https://themify.me/demo/themes/fullpane/wp-content/themes/fullpane/images/youtube.png";s:32:"setting-link_type_themify-link-4";s:10:"image-icon";s:33:"setting-link_title_themify-link-4";s:9:"Pinterest";s:31:"setting-link_img_themify-link-4";s:87:"https://themify.me/demo/themes/fullpane/wp-content/themes/fullpane/images/pinterest.png";s:32:"setting-link_type_themify-link-6";s:9:"font-icon";s:33:"setting-link_title_themify-link-6";s:7:"Twitter";s:32:"setting-link_link_themify-link-6";s:26:"http://twitter.com/themify";s:33:"setting-link_ficon_themify-link-6";s:10:"fa-twitter";s:32:"setting-link_type_themify-link-7";s:9:"font-icon";s:33:"setting-link_title_themify-link-7";s:8:"Facebook";s:32:"setting-link_link_themify-link-7";s:27:"http://facebook.com/themify";s:33:"setting-link_ficon_themify-link-7";s:11:"fa-facebook";s:22:"setting-link_field_ids";s:239:"{"themify-link-0":"themify-link-0","themify-link-1":"themify-link-1","themify-link-2":"themify-link-2","themify-link-3":"themify-link-3","themify-link-4":"themify-link-4","themify-link-6":"themify-link-6","themify-link-7":"themify-link-7"}";s:23:"setting-link_field_hash";s:1:"8";s:30:"setting-page_builder_is_active";s:6:"enable";s:46:"setting-page_builder_animation_parallax_scroll";s:6:"mobile";s:4:"skin";s:91:"https://themify.me/demo/themes/fullpane/wp-content/themes/fullpane/themify/img/non-skin.gif";}<?php $themify_data = unserialize( ob_get_clean() );

	// fix the weird way "skin" is saved
	if( isset( $themify_data['skin'] ) ) {
		$parsed_skin = parse_url( $themify_data['skin'], PHP_URL_PATH );
		$basedir_skin = basename( dirname( $parsed_skin ) );
		$themify_data['skin'] = trailingslashit( get_template_directory_uri() ) . 'skins/' . $basedir_skin . '/style.css';
	}

	themify_set_data( $themify_data );
	
}
themify_do_demo_import();
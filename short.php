<?php

/**
 * Plugin Name: Short
 * Plugin URI: http://wordpress.org/plugins/short/
 * Description: Provide short link redirection.
 * Author: George Stephanis
 * Author URI: http://stephanis.info/
 * Text Domain: short
 */

class Stephanis_Short {

	const POST_TYPE = 'shortlink';

	/**
	 * Basic function that adds relevant hooks throughout the load process.
	 */
	public static function go() {
		add_action( 'init',                      array( __CLASS__, 'register_post_type' ) );
		add_action( 'init',                      array( __CLASS__, 'rewrite_rules' ) );
		add_filter( 'query_vars',                array( __CLASS__, 'query_vars' ) );
		add_action( 'pre_get_posts',             array( __CLASS__, 'pre_get_posts' ) );
		add_action( 'save_post',                 array( __CLASS__, 'save_post' ), 10, 2 );
		add_filter( 'pre_get_shortlink',         array( __CLASS__, 'pre_get_shortlink' ), 10, 2 );
		add_filter( 'get_sample_permalink_html', array( __CLASS__, 'get_sample_permalink_html' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns',       array( __CLASS__, 'manage_posts_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'manage_posts_custom_column' ), 10, 2 );
		
	}

	public static function rewrite_rules() {
		add_rewrite_rule( 'go/([\da-z]+)/?', 'index.php?shortlink_base36=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'shortlink_base36';
		return $vars;
	}

	public static function register_post_type() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
	
		register_post_type( self::POST_TYPE, array(
			'description' => __( 'Short Links', 'short' ),
			'labels' => array(
				'name'               => esc_html__( 'Short Links',                   'short' ),
				'singular_name'      => esc_html__( 'Short Link',                    'short' ),
				'menu_name'          => esc_html__( 'Short Links',                   'short' ),
				'all_items'          => esc_html__( 'All Short Links',               'short' ),
				'add_new'            => esc_html__( 'Add New',                       'short' ),
				'add_new_item'       => esc_html__( 'Add New Short Link',            'short' ),
				'edit_item'          => esc_html__( 'Edit Short Link',               'short' ),
				'new_item'           => esc_html__( 'New Short Link',                'short' ),
				'view_item'          => esc_html__( 'View Short Link',               'short' ),
				'search_items'       => esc_html__( 'Search Short Links',            'short' ),
				'not_found'          => esc_html__( 'No Short Links found',          'short' ),
				'not_found_in_trash' => esc_html__( 'No Short Links found in Trash', 'short' ),
			),
			'register_meta_box_cb' => array( __CLASS__, 'register_shortlinks_meta_boxes' ),
			'supports' => array(
				'title',
			),
			'public'          => true,
			'menu_position'   => 35,
			'map_meta_cap'    => true,
		) );
	}

	public static function register_shortlinks_meta_boxes() {
		add_meta_box( 'shortlink_redirect_url', __( 'Redirect URL', 'short' ), array( __CLASS__, 'shortlink_redirect_url_meta_box' ), null, 'normal', 'high' );

		remove_meta_box( 'sharing_meta', self::POST_TYPE, 'advanced' );
	}

	public static function shortlink_redirect_url_meta_box( $post ) {
		$url = get_post_meta( $post->ID, '_redirect_url', true );
		wp_nonce_field( '_redirect_url_nonce', '_redirect_url_nonce', false );
		?>
		<label for="_redirect_url"><?php esc_html_e( 'URL to redirect to', 'short' ); ?></label>
		<input type="url" id="_redirect_url" class="widefat" name="_redirect_url" value="<?php echo esc_url( $url ); ?>" placeholder="http://example.com/2015/04/15/17-top-example-urls-you-gotta-try-number-8" />
		<?php
	}

	public static function get_sample_permalink_html( $html, $post_id ) {
		$post = get_post( $post_id );
		if ( self::POST_TYPE === $post->post_type ) {
			$html = '';
		}
		return $html;
	}

	public static function pre_get_shortlink( $shortlink, $post_id ) {
		$post = get_post( $post_id );
		if ( self::POST_TYPE === $post->post_type ) {
			$shortlink = site_url( sprintf( 'go/%s', self::base10_to_base36( $post_id ) ) );
		}
		return $shortlink;
	}

	public static function manage_posts_columns( $columns ) {
		$new_columns = array(
			'shortlink'    => __( 'Short Link', 'short' ),
			'redirect_url' => __( 'Redirect URL', 'short' ),
		);
		return array_merge( array_slice( $columns, 0, 2 ), $new_columns, array_slice( $columns, 2 ) );
	}

	public static function manage_posts_custom_column( $column_name, $post_id ) {
		if ( 'shortlink' === $column_name ) {
			$short = wp_get_shortlink( $post_id );
			echo sprintf( '<a href="%1$s">%2$s</a>', esc_url( $short ), esc_html( $short ) );
		} elseif ( 'redirect_url' === $column_name ) {
			echo esc_html( get_post_meta( $post_id, '_redirect_url', true ) );
		}
	}

	public static function save_post( $post_id, $post ) {
		if ( ! wp_verify_nonce( $_POST['_redirect_url_nonce'], '_redirect_url_nonce' ) ) {
			return $post_id;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $post_id;
		}

		if ( self::POST_TYPE !== $post->post_type ) {
			return $post_id;
		}

		if ( $post->post_name !== self::base10_to_base36( $post_id ) ) {
			$post->post_name = self::base10_to_base36( $post_id );
			wp_update_post( $post );
		}

		if ( empty( $_POST['_redirect_url'] ) ) {
			return $post_id;
		}

		$url = $_POST['_redirect_url'];
		$url = esc_url_raw( $url );

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_redirect_url', $url );

		return $post_id;
	}

	public static function base10_to_base36( $base10 ) {
		$base10 = (int) $base10;
		return base_convert( $base10, 10, 36 );
	}

	public static function base36_to_base10( $base36 ) {
		$base36 = preg_replace( '/[^\da-z]+/i', '', $base36 );
		return base_convert( $base36, 36, 10 );
	}

	public static function pre_get_posts( $query ) {
		if ( empty( $query->query_vars['shortlink_base36'] ) ) {
			return;
		}

		$post_id = self::base36_to_base10( $query->query_vars['shortlink_base36'] );
		if ( ! $post_id ) {
			return;
		}

		$redirect = get_post_meta( $post_id, '_redirect_url', true );
		if ( $redirect ) {
			wp_redirect( $redirect );
			exit;
		}

	}
}

Stephanis_Short::go();

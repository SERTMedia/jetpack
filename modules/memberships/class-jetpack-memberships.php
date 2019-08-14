<?php
/**
 * Jetpack_Memberships: wrapper for memberships functions.
 *
 * @package    Jetpack
 * @since      7.3.0
 */

use Automattic\Jetpack\Connection\Client;

/**
 * Class Jetpack_Memberships
 * This class represents the Memberships functionality.
 */
class Jetpack_Memberships {
	/**
	 * CSS class prefix to use in the styling.
	 *
	 * @var string
	 */
	public static $css_classname_prefix = 'jetpack-memberships';
	/**
	 * Our CPT type for the product (plan).
	 *
	 * @var string
	 */
	public static $post_type_plan = 'jp_mem_plan';
	/**
	 * Option that will store currently set up account (Stripe etc) id for memberships.
	 *
	 * @var string
	 */
	public static $connected_account_id_option_name = 'jetpack-memberships-connected-account-id';
	/**
	 * Button block type to use.
	 *
	 * @var string
	 */
	private static $button_block_name = 'recurring-payments';

	/**
	 * These are defaults for wp_kses ran on the membership button.
	 *
	 * @var array
	 */
	private static $tags_allowed_in_the_button = array( 'br' => array() );

	/**
	 * The minimum required plan for this Gutenberg block.
	 *
	 * @var Jetpack_Memberships
	 */
	protected static $required_plan;

	/**
	 * Classic singleton pattern
	 *
	 * @var Jetpack_Memberships
	 */
	private static $instance;

	/**
	 * Jetpack_Memberships constructor.
	 */
	private function __construct() {}

	/**
	 * The actual constructor initializing the object.
	 *
	 * @return Jetpack_Memberships
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->register_init_hook();
			self::$required_plan = ( defined( 'IS_WPCOM' ) && IS_WPCOM ) ? 'value_bundle' : 'jetpack_premium';
		}

		return self::$instance;
	}
	/**
	 * Get the map that defines the shape of CPT post. keys are names of fields and
	 * 'meta' is the name of actual WP post meta field that corresponds.
	 *
	 * @return array
	 */
	private static function get_plan_property_mapping() {
		$meta_prefix = 'jetpack_memberships_';
		$properties  = array(
			'price'    => array(
				'meta' => $meta_prefix . 'price',
			),
			'currency' => array(
				'meta' => $meta_prefix . 'currency',
			),
		);
		return $properties;
	}

	/**
	 * Inits further hooks on init hook.
	 */
	private function register_init_hook() {
		add_action( 'init', array( $this, 'init_hook_action' ) );
		add_action( 'jetpack_register_gutenberg_extensions', array( $this, 'register_gutenberg_block' ) );
	}

	/**
	 * Actual hooks initializing on init.
	 */
	public function init_hook_action() {
		add_filter( 'rest_api_allowed_post_types', array( $this, 'allow_rest_api_types' ) );
		add_filter( 'jetpack_sync_post_meta_whitelist', array( $this, 'allow_sync_post_meta' ) );
		$this->setup_cpts();
	}

	/**
	 * Sets up the custom post types for the module.
	 */
	private function setup_cpts() {
		/*
		 * PLAN data structure.
		 */
		$capabilities = array(
			'edit_post'          => 'edit_posts',
			'read_post'          => 'read_private_posts',
			'delete_post'        => 'delete_posts',
			'edit_posts'         => 'edit_posts',
			'edit_others_posts'  => 'edit_others_posts',
			'publish_posts'      => 'publish_posts',
			'read_private_posts' => 'read_private_posts',
		);
		$order_args   = array(
			'label'               => esc_html__( 'Plan', 'jetpack' ),
			'description'         => esc_html__( 'Recurring Payments plans', 'jetpack' ),
			'supports'            => array( 'title', 'custom-fields', 'content' ),
			'hierarchical'        => false,
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_admin_bar'   => false,
			'show_in_nav_menus'   => false,
			'can_export'          => true,
			'has_archive'         => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'rewrite'             => false,
			'capabilities'        => $capabilities,
			'show_in_rest'        => false,
		);
		register_post_type( self::$post_type_plan, $order_args );
	}

	/**
	 * Allows custom post types to be used by REST API.
	 *
	 * @param array $post_types - other post types.
	 *
	 * @see hook 'rest_api_allowed_post_types'
	 * @return array
	 */
	public function allow_rest_api_types( $post_types ) {
		$post_types[] = self::$post_type_plan;

		return $post_types;
	}

	/**
	 * Allows custom meta fields to sync.
	 *
	 * @param array $post_meta - previously changet post meta.
	 *
	 * @return array
	 */
	public function allow_sync_post_meta( $post_meta ) {
		$meta_keys = array_map(
			array( $this, 'return_meta' ),
			$this->get_plan_property_mapping()
		);
		return array_merge( $post_meta, array_values( $meta_keys ) );
	}

	/**
	 * This returns meta attribute of passet array.
	 * Used for array functions.
	 *
	 * @param array $map - stuff.
	 *
	 * @return mixed
	 */
	public function return_meta( $map ) {
		return $map['meta'];
	}
	/**
	 * Callback that parses the membership purchase shortcode.
	 *
	 * @param array $attrs - attributes in the shortcode. `id` here is the CPT id of the plan.
	 *
	 * @return string|void
	 */
	public function render_button( $attrs ) {
		Jetpack_Gutenberg::load_assets_as_required( self::$button_block_name, array( 'thickbox', 'wp-polyfill' ) );

		if ( empty( $attrs['planId'] ) ) {
			return;
		}
		$id      = intval( $attrs['planId'] );
		$product = get_post( $id );
		if ( ! $product || is_wp_error( $product ) ) {
			return;
		}
		if ( $product->post_type !== self::$post_type_plan || 'publish' !== $product->post_status ) {
			return;
		}

		$data = array(
			'blog_id'      => self::get_blog_id(),
			'id'           => $id,
			'button_label' => __( 'Your contribution', 'jetpack' ),
			'powered_text' => __( 'Powered by WordPress.com', 'jetpack' ),
		);

		$classes = array(
			'wp-block-button__link',
			'components-button',
			'is-primary',
			'is-button',
			'wp-block-jetpack-' . self::$button_block_name,
			self::$css_classname_prefix . '-' . $data['id'],
		);
		if ( isset( $attrs['className'] ) ) {
			array_push( $classes, $attrs['className'] );
		}
		if ( isset( $attrs['submitButtonText'] ) ) {
			$data['button_label'] = $attrs['submitButtonText'];
		}
		$button_styles = array();
		if ( ! empty( $attrs['customBackgroundButtonColor'] ) ) {
			array_push(
				$button_styles,
				sprintf(
					'background-color: %s',
					sanitize_hex_color( $attrs['customBackgroundButtonColor'] )
				)
			);
		}
		if ( ! empty( $attrs['customTextButtonColor'] ) ) {
			array_push(
				$button_styles,
				sprintf(
					'color: %s',
					sanitize_hex_color( $attrs['customTextButtonColor'] )
				)
			);
		}
		$button_styles = implode( $button_styles, ';' );
		add_thickbox();
		return sprintf(
			'<button data-blog-id="%d" data-powered-text="%s" data-plan-id="%d" data-lang="%s" class="%s" style="%s">%s</button>',
			esc_attr( $data['blog_id'] ),
			esc_attr( $data['powered_text'] ),
			esc_attr( $data['id'] ),
			esc_attr( get_locale() ),
			esc_attr( implode( $classes, ' ' ) ),
			esc_attr( $button_styles ),
			wp_kses( $data['button_label'], self::$tags_allowed_in_the_button )
		);
	}

	/**
	 * Get current blog id.
	 *
	 * @return int
	 */
	public static function get_blog_id() {
		if ( defined( 'IS_WPCOM' ) && IS_WPCOM ) {
			return get_current_blog_id();
		}

		return Jetpack_Options::get_option( 'id' );
	}

	/**
	 * Get the id of the connected payment acount (Stripe etc).
	 *
	 * @return int|void
	 */
	public static function get_connected_account_id() {
		return get_option( self::$connected_account_id_option_name );
	}

	/**
	 * Get a status of connection for the site. If this is Jetpack, pass the request to wpcom.
	 *
	 * @param string $rest_base - The REST API route base for requesting connection status on wpcom.
	 *
	 * @return WP_Error|array ['products','connected_account_id','connect_url','should_upgrade_to_access_memberships','upgrade_url']
	 */
	public static function get_connection_status( $rest_base = 'memberships' ) {
		if ( ( defined( 'IS_WPCOM' ) && IS_WPCOM ) ) {
			require_lib( 'memberships' );
			$blog_id = get_current_blog_id();
			return (array) get_memberships_settings_for_site( $blog_id );
		} else {
			$blog_id  = Jetpack_Options::get_option( 'id' );
			$response = Client::wpcom_json_api_request_as_user(
				"/sites/$blog_id/{$rest_base}/status",
				'v2',
				array(),
				null
			);
			if ( is_wp_error( $response ) ) {
				if ( $response->get_error_code() === 'missing_token' ) {
					return new WP_Error( 'missing_token', __( 'Please connect your user account to WordPress.com', 'jetpack' ), 404 );
				}
				return new WP_Error( 'wpcom_connection_error', __( 'Could not connect to WordPress.com', 'jetpack' ), 404 );
			}
			$data = isset( $response['body'] ) ? json_decode( $response['body'], true ) : null;
			if ( 200 !== $response['response']['code'] && $data['code'] && $data['message'] ) {
				return new WP_Error( $data['code'], $data['message'], 401 );
			}
			return $data;
		}
	}

	/**
	 * Whether Memberships (aka Recurring Payments) are enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled_jetpack_recurring_payments() {
		// For WPCOM sites.
		if ( defined( 'IS_WPCOM' ) && IS_WPCOM && function_exists( 'has_any_blog_stickers' ) ) {
			$site_id = get_current_blog_id();
			return has_any_blog_stickers( array( 'premium-plan', 'business-plan', 'ecommerce-plan' ), $site_id );
		}

		// For all Jetpack sites.
		return Jetpack::is_active() && Jetpack_Plan::supports( 'recurring-payments' );
	}

	/**
	 * Register the Recurring Payments Gutenberg block
	 */
	public function register_gutenberg_block() {
		if ( self::is_enabled_jetpack_recurring_payments() ) {
			jetpack_register_block(
				'jetpack/recurring-payments',
				array(
					'render_callback' => array( $this, 'render_button' ),
				)
			);
		} else {
			Jetpack_Gutenberg::set_extension_unavailable(
				'jetpack/recurring-payments',
				'missing_plan',
				array(
					'required_feature' => 'memberships',
					'required_plan'    => self::$required_plan,
				)
			);
		}
	}
}
Jetpack_Memberships::get_instance();

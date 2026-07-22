<?php
/**
 * AI Starter theme functions.
 *
 * @package AI_Starter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AI_STARTER_VERSION', '1.0.0' );

/**
 * Theme setup.
 */
function ai_starter_setup() {
	load_theme_textdomain( 'ai-starter', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'custom-logo', array(
		'height'      => 80,
		'width'       => 240,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'html5', array(
		'search-form',
		'comment-form',
		'comment-list',
		'gallery',
		'caption',
		'style',
		'script',
		'navigation-widgets',
	) );

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'ai-starter' ),
			'footer'  => __( 'Footer Menu', 'ai-starter' ),
			'legal'   => __( 'Legal Menu', 'ai-starter' ),
		)
	);

	add_editor_style( 'assets/css/theme.css' );
}
add_action( 'after_setup_theme', 'ai_starter_setup' );

/**
 * Enqueue front-end assets.
 */
function ai_starter_assets() {
	wp_enqueue_style(
		'ai-starter-fonts',
		'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'ai-starter-theme',
		get_template_directory_uri() . '/assets/css/theme.css',
		array( 'ai-starter-fonts' ),
		AI_STARTER_VERSION
	);

	wp_enqueue_script(
		'ai-starter-nav',
		get_template_directory_uri() . '/assets/js/theme.js',
		array(),
		AI_STARTER_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'ai_starter_assets' );

/**
 * Preconnect for Google Fonts.
 *
 * @param array  $urls          URLs to print.
 * @param string $relation_type Relation type.
 * @return array
 */
function ai_starter_resource_hints( $urls, $relation_type ) {
	if ( 'preconnect' === $relation_type ) {
		$urls[] = array(
			'href' => 'https://fonts.googleapis.com',
		);
		$urls[] = array(
			'href'        => 'https://fonts.gstatic.com',
			'crossorigin' => 'anonymous',
		);
	}
	return $urls;
}
add_filter( 'wp_resource_hints', 'ai_starter_resource_hints', 10, 2 );

/**
 * Register block pattern category and patterns.
 */
function ai_starter_register_patterns() {
	register_block_pattern_category(
		'ai-starter',
		array( 'label' => __( 'AI Starter', 'ai-starter' ) )
	);

	$patterns = array(
		'hero',
		'services',
		'about-split',
		'benefits',
		'process',
		'stats',
		'testimonials',
		'faq',
		'cta',
		'pricing',
		'contact',
		'feature-grid',
		'page-header',
	);

	foreach ( $patterns as $pattern ) {
		$file = get_template_directory() . '/patterns/' . $pattern . '.php';
		if ( ! file_exists( $file ) ) {
			continue;
		}
		$data = include $file;
		if ( is_array( $data ) && ! empty( $data['title'] ) && ! empty( $data['content'] ) ) {
			register_block_pattern( 'ai-starter/' . $pattern, $data );
		}
	}
}
add_action( 'init', 'ai_starter_register_patterns' );

/**
 * SEO meta helpers for pages without a SEO plugin.
 */
function ai_starter_meta_tags() {
	if ( is_singular() ) {
		$desc = get_post_meta( get_the_ID(), '_ai_meta_description', true );
		if ( ! $desc ) {
			$desc = has_excerpt() ? get_the_excerpt() : wp_trim_words( wp_strip_all_tags( get_the_content() ), 28 );
		}
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
		echo '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '" />' . "\n";
		echo '<meta property="og:type" content="website" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( get_permalink() ) . '" />' . "\n";
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		if ( has_post_thumbnail() ) {
			echo '<meta property="og:image" content="' . esc_url( get_the_post_thumbnail_url( null, 'large' ) ) . '" />' . "\n";
		}
	}
}
add_action( 'wp_head', 'ai_starter_meta_tags', 1 );

/**
 * Local business schema on front page.
 */
function ai_starter_schema() {
	if ( ! is_front_page() ) {
		return;
	}
	$schema = array(
		'@context'    => 'https://schema.org',
		'@type'       => 'ProfessionalService',
		'name'        => 'Petra Müller – Astrologie Schweiz',
		'description' => 'Astrologische Beratung, Coaching und Persönlichkeitsentwicklung in Zug.',
		'url'         => home_url( '/' ),
		'areaServed'  => 'Switzerland',
		'address'     => array(
			'@type'           => 'PostalAddress',
			'addressLocality' => 'Zug',
			'addressCountry'  => 'CH',
		),
		'priceRange'  => 'CHF',
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}
add_action( 'wp_head', 'ai_starter_schema', 5 );

/**
 * Disable emoji scripts for performance.
 */
function ai_starter_disable_emojis() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
}
add_action( 'init', 'ai_starter_disable_emojis' );

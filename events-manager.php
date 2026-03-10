<?php
/**
 * Plugin Name: Events Manager
 * Description: Adds an event post type and an AJAX-powered upcoming events list.
 * Version: 1.0.0
 * Author: ozzy1986
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Text Domain: events-manager
 */

if (! defined('ABSPATH')) {
	exit;
}

const EM_POST_TYPE = 'event';
const EM_EVENT_DATE_META_KEY = 'event_date';
const EM_EVENT_PLACE_META_KEY = 'event_place';
const EM_EVENTS_PER_PAGE = 3;
const EM_AJAX_ACTION = 'em_load_events';
const EM_AJAX_NONCE_ACTION = 'em_load_events';

register_activation_hook(__FILE__, 'em_activate_plugin');
register_deactivation_hook(__FILE__, 'em_deactivate_plugin');

add_action('init', 'em_register_event_post_type');
add_action('init', 'em_register_event_meta');
add_action('add_meta_boxes', 'em_add_event_meta_box');
add_action('save_post', 'em_save_event_meta', 10, 2);
add_shortcode('events_list', 'em_render_events_shortcode');
add_action('wp_ajax_' . EM_AJAX_ACTION, 'em_ajax_load_events');
add_action('wp_ajax_nopriv_' . EM_AJAX_ACTION, 'em_ajax_load_events');

function em_activate_plugin() {
	em_register_event_post_type();
	em_register_event_meta();
	flush_rewrite_rules();
}

function em_deactivate_plugin() {
	flush_rewrite_rules();
}

function em_register_event_post_type() {
	$labels = array(
		'name'               => __('Events', 'events-manager'),
		'singular_name'      => __('Event', 'events-manager'),
		'add_new'            => __('Add Event', 'events-manager'),
		'add_new_item'       => __('Add New Event', 'events-manager'),
		'edit_item'          => __('Edit Event', 'events-manager'),
		'new_item'           => __('New Event', 'events-manager'),
		'view_item'          => __('View Event', 'events-manager'),
		'search_items'       => __('Search Events', 'events-manager'),
		'not_found'          => __('No events found.', 'events-manager'),
		'not_found_in_trash' => __('No events found in Trash.', 'events-manager'),
		'menu_name'          => __('Events', 'events-manager'),
	);

	$args = array(
		'labels'             => $labels,
		'public'             => true,
		'show_in_rest'       => true,
		'has_archive'        => true,
		'rewrite'            => array(
			'slug' => 'events',
		),
		'supports'           => array('title'),
		'menu_icon'          => 'dashicons-calendar-alt',
		'menu_position'      => 20,
		'publicly_queryable' => true,
	);

	register_post_type(EM_POST_TYPE, $args);
}

function em_register_event_meta() {
	$common_args = array(
		'single'        => true,
		'show_in_rest'  => false,
		'auth_callback' => 'em_authorize_event_meta',
	);

	register_post_meta(
		EM_POST_TYPE,
		EM_EVENT_DATE_META_KEY,
		array_merge(
			$common_args,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'em_sanitize_event_date',
			)
		)
	);

	register_post_meta(
		EM_POST_TYPE,
		EM_EVENT_PLACE_META_KEY,
		array_merge(
			$common_args,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		)
	);
}

function em_authorize_event_meta($allowed, $meta_key, $post_id, $user_id, $cap = '', $caps = array()) {
	return user_can($user_id, 'edit_post', $post_id);
}

function em_add_event_meta_box() {
	add_meta_box(
		'em-event-details',
		__('Event Details', 'events-manager'),
		'em_render_event_meta_box',
		EM_POST_TYPE,
		'normal',
		'default'
	);
}

function em_render_event_meta_box($post) {
	$event_date  = get_post_meta($post->ID, EM_EVENT_DATE_META_KEY, true);
	$event_place = get_post_meta($post->ID, EM_EVENT_PLACE_META_KEY, true);

	wp_nonce_field('em_save_event_meta', 'em_event_meta_nonce');
	?>
	<p>
		<label for="em-event-date"><strong><?php esc_html_e('Event date', 'events-manager'); ?></strong></label>
		<br>
		<input
			type="date"
			id="em-event-date"
			name="em_event_date"
			value="<?php echo esc_attr($event_date); ?>"
		>
	</p>
	<p>
		<label for="em-event-place"><strong><?php esc_html_e('Event place', 'events-manager'); ?></strong></label>
		<br>
		<input
			type="text"
			id="em-event-place"
			name="em_event_place"
			value="<?php echo esc_attr($event_place); ?>"
			class="widefat"
		>
	</p>
	<?php
}

function em_save_event_meta($post_id, $post) {
	if (! $post instanceof WP_Post || EM_POST_TYPE !== $post->post_type) {
		return;
	}

	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}

	if (wp_is_post_revision($post_id)) {
		return;
	}

	if (
		! isset($_POST['em_event_meta_nonce']) ||
		! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['em_event_meta_nonce'])), 'em_save_event_meta')
	) {
		return;
	}

	if (! current_user_can('edit_post', $post_id)) {
		return;
	}

	$event_date = '';

	if (isset($_POST['em_event_date'])) {
		$event_date = em_sanitize_event_date(wp_unslash($_POST['em_event_date']));
	}

	if ('' !== $event_date) {
		update_post_meta($post_id, EM_EVENT_DATE_META_KEY, $event_date);
	} else {
		delete_post_meta($post_id, EM_EVENT_DATE_META_KEY);
	}

	$event_place = '';

	if (isset($_POST['em_event_place'])) {
		$event_place = sanitize_text_field(wp_unslash($_POST['em_event_place']));
	}

	if ('' !== $event_place) {
		update_post_meta($post_id, EM_EVENT_PLACE_META_KEY, $event_place);
	} else {
		delete_post_meta($post_id, EM_EVENT_PLACE_META_KEY);
	}
}

function em_sanitize_event_date($value) {
	if (! is_string($value)) {
		return '';
	}

	$value = trim($value);

	if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
		return '';
	}

	$date   = DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
	$errors = DateTimeImmutable::getLastErrors();

	if (false === $date || false !== $errors && (! empty($errors['warning_count']) || ! empty($errors['error_count']))) {
		return '';
	}

	return $date->format('Y-m-d') === $value ? $value : '';
}

function em_get_today_in_site_timezone() {
	return (new DateTimeImmutable('now', wp_timezone()))->format('Y-m-d');
}

function em_get_events_query_args($page = 1) {
	$page = max(1, absint($page));

	return array(
		'post_type'           => EM_POST_TYPE,
		'post_status'         => 'publish',
		'posts_per_page'      => EM_EVENTS_PER_PAGE,
		'paged'               => $page,
		'meta_key'            => EM_EVENT_DATE_META_KEY,
		'orderby'             => 'meta_value',
		'order'               => 'ASC',
		'meta_type'           => 'DATE',
		'ignore_sticky_posts' => true,
		'meta_query'          => array(
			array(
				'key'     => EM_EVENT_DATE_META_KEY,
				'value'   => em_get_today_in_site_timezone(),
				'compare' => '>=',
				'type'    => 'DATE',
			),
		),
	);
}

function em_get_formatted_event_date($event_date) {
	if ('' === $event_date) {
		return '';
	}

	$date = DateTimeImmutable::createFromFormat('!Y-m-d', $event_date, wp_timezone());

	if (false === $date) {
		return '';
	}

	return wp_date('d.m.Y', $date->getTimestamp(), wp_timezone());
}

function em_get_map_embed_url($place) {
	if ('' === $place) {
		return '';
	}

	return 'https://www.google.com/maps?q=' . rawurlencode($place) . '&z=14&output=embed';
}

function em_get_map_link_url($place) {
	if ('' === $place) {
		return '';
	}

	return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($place);
}

function em_render_single_event($post_id) {
	$event_date     = (string) get_post_meta($post_id, EM_EVENT_DATE_META_KEY, true);
	$event_place    = (string) get_post_meta($post_id, EM_EVENT_PLACE_META_KEY, true);
	$formatted_date = em_get_formatted_event_date($event_date);
	$map_embed_url  = em_get_map_embed_url($event_place);
	$map_link_url   = em_get_map_link_url($event_place);

	ob_start();
	?>
	<article class="em-event">
		<h3 class="em-event__title"><?php echo esc_html(get_the_title($post_id)); ?></h3>
		<dl class="em-event__meta">
			<div class="em-event__meta-row">
				<dt><?php esc_html_e('Date', 'events-manager'); ?></dt>
				<dd><?php echo esc_html($formatted_date); ?></dd>
			</div>
			<div class="em-event__meta-row">
				<dt><?php esc_html_e('Place', 'events-manager'); ?></dt>
				<dd><?php echo esc_html($event_place); ?></dd>
			</div>
		</dl>
		<?php if ('' !== $map_embed_url) : ?>
			<div class="em-event__map">
				<iframe
					src="<?php echo esc_url($map_embed_url); ?>"
					title="<?php echo esc_attr(sprintf(__('Map for %s', 'events-manager'), get_the_title($post_id))); ?>"
					loading="lazy"
					referrerpolicy="no-referrer-when-downgrade"
					allowfullscreen
				></iframe>
				<p class="em-event__map-link">
					<a href="<?php echo esc_url($map_link_url); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e('Open in Google Maps', 'events-manager'); ?>
					</a>
				</p>
			</div>
		<?php endif; ?>
	</article>
	<?php

	return (string) ob_get_clean();
}

function em_render_events_markup($page = 1) {
	$query = new WP_Query(em_get_events_query_args($page));
	$html  = '';

	if ($query->have_posts()) {
		ob_start();

		while ($query->have_posts()) {
			$query->the_post();
			echo em_render_single_event(get_the_ID()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$html = (string) ob_get_clean();
	}

	wp_reset_postdata();

	return array(
		'html'     => $html,
		'has_more' => $query->max_num_pages > $page,
	);
}

function em_enqueue_assets() {
	static $is_loaded = false;

	if ($is_loaded) {
		return;
	}

	$script_handle = 'em-events-manager';
	$style_handle  = 'em-events-manager';
	$plugin_path   = plugin_dir_path(__FILE__);

	wp_enqueue_style(
		$style_handle,
		plugins_url('assets/css/events-manager.css', __FILE__),
		array(),
		(string) filemtime($plugin_path . 'assets/css/events-manager.css')
	);

	wp_enqueue_script(
		$script_handle,
		plugins_url('assets/js/events-manager.js', __FILE__),
		array(),
		(string) filemtime($plugin_path . 'assets/js/events-manager.js'),
		true
	);

	wp_add_inline_script(
		$script_handle,
		'window.EventsManagerConfig = ' . wp_json_encode(
			array(
				'action'  => EM_AJAX_ACTION,
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce(EM_AJAX_NONCE_ACTION),
			)
		) . ';',
		'before'
	);

	$is_loaded = true;
}

function em_render_events_shortcode() {
	em_enqueue_assets();

	$rendered = em_render_events_markup(1);

	ob_start();
	?>
	<div class="em-events" data-next-page="2">
		<div class="em-events__items">
			<?php if ('' !== $rendered['html']) : ?>
				<?php echo $rendered['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php else : ?>
				<p class="em-events__empty"><?php esc_html_e('No upcoming events found.', 'events-manager'); ?></p>
			<?php endif; ?>
		</div>
		<button
			type="button"
			class="em-events__more"
			<?php echo $rendered['has_more'] ? '' : 'hidden'; ?>
		>
			<?php esc_html_e('Show more', 'events-manager'); ?>
		</button>
		<p class="em-events__status" aria-live="polite" hidden></p>
	</div>
	<?php

	return (string) ob_get_clean();
}

function em_ajax_load_events() {
	check_ajax_referer(EM_AJAX_NONCE_ACTION, 'nonce');

	$page = isset($_POST['page']) ? absint(wp_unslash($_POST['page'])) : 1;

	if ($page < 2) {
		$page = 2;
	}

	$rendered = em_render_events_markup($page);

	wp_send_json_success(
		array(
			'html'      => $rendered['html'],
			'hasMore'   => $rendered['has_more'],
			'nextPage'  => $page + 1,
			'isEmpty'   => '' === $rendered['html'],
			'buttonText'=> __('Show more', 'events-manager'),
		)
	);
}

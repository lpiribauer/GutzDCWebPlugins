<?php
/**
 * Elementor widget rendering an upcoming-events list from a 3K Darts list URL.
 *
 * @package gutz-dc-events
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Icons_Manager;
use Elementor\Widget_Base;

class GUTZ_DC_Events_Widget extends Widget_Base {

	public function get_name(): string {
		return 'gutz_dc_events';
	}

	public function get_title(): string {
		return esc_html__( 'Dart Events', 'gutz-dc-events' );
	}

	public function get_icon(): string {
		return 'eicon-post-list';
	}

	public function get_categories(): array {
		return [ 'general' ];
	}

	public function get_keywords(): array {
		return [ 'dart', 'darts', 'events', 'turnier', 'gutz', '3k' ];
	}

	public function get_style_depends(): array {
		return [ 'gutz-dc-events' ];
	}

	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Time zone choices: the WordPress setting, every European zone, and UTC.
	 *
	 * @return array<string,string>
	 */
	private function get_timezone_options(): array {
		$options = [ 'site' => esc_html__( 'WordPress site setting', 'gutz-dc-events' ) ];

		foreach ( timezone_identifiers_list( DateTimeZone::EUROPE ) as $identifier ) {
			$options[ $identifier ] = $identifier;
		}

		$options['UTC'] = 'UTC';

		return $options;
	}

	private function register_content_controls(): void {
		$this->start_controls_section(
			'section_content',
			[
				'label' => esc_html__( 'Events', 'gutz-dc-events' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'list_url',
			[
				'label'       => esc_html__( 'Event list URL', 'gutz-dc-events' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => 'https://portal.3k-darts.com/frontend/events/5/mandant/1985',
				'placeholder' => 'https://portal.3k-darts.com/frontend/events/5/mandant/1985',
				'description' => esc_html__( 'The public 3K Darts event page of your club. A bare mandant key such as 1985 works too.', 'gutz-dc-events' ),
			]
		);

		$this->add_control(
			'venue_filter',
			[
				'label'       => esc_html__( 'Only this venue (Ort)', 'gutz-dc-events' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => 'Vereinsheim GUTZ DC e.V',
				'description' => esc_html__( 'Matches the Ort column, upper/lower case is ignored. Leave empty to list every venue.', 'gutz-dc-events' ),
			]
		);

		$this->add_control(
			'limit',
			[
				'label'       => esc_html__( 'Maximum events', 'gutz-dc-events' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 5,
				'description' => esc_html__( '0 shows every upcoming event.', 'gutz-dc-events' ),
			]
		);

		$this->add_control(
			'list_icon',
			[
				'label'       => esc_html__( 'Icon in front of each event', 'gutz-dc-events' ),
				'type'        => Controls_Manager::ICONS,
				'separator'   => 'before',
				'default'     => [
					'value'   => 'fas fa-bullseye',
					'library' => 'fa-solid',
				],
				'description' => esc_html__( 'Pick an icon or upload an SVG. Clear it to show no icon.', 'gutz-dc-events' ),
			]
		);

		$this->add_control(
			'date_format',
			[
				'label'       => esc_html__( 'Date format', 'gutz-dc-events' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'D, d.m.Y',
				'description' => esc_html__( 'PHP date format, translated into the site language. Add H:i to show the start time.', 'gutz-dc-events' ),
			]
		);

		$this->add_control(
			'timezone',
			[
				'label'       => esc_html__( 'Time zone', 'gutz-dc-events' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => GUTZ_DC_Events_API::DEFAULT_TIMEZONE,
				'options'     => $this->get_timezone_options(),
				'description' => esc_html__( 'The API delivers UTC. Keep this on the time zone the tournaments take place in, not the one your server runs in.', 'gutz-dc-events' ),
			]
		);

		$this->add_control(
			'show_venue',
			[
				'label'       => esc_html__( 'Show venue (Ort)', 'gutz-dc-events' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'auto',
				'options'     => [
					'auto'   => esc_html__( 'Only when no venue filter is set', 'gutz-dc-events' ),
					'always' => esc_html__( 'Always', 'gutz-dc-events' ),
					'never'  => esc_html__( 'Never', 'gutz-dc-events' ),
				],
				'description' => esc_html__( 'With a venue filter every event is at the same place, so the venue is redundant.', 'gutz-dc-events' ),
			]
		);

		$this->add_control(
			'show_registration',
			[
				'label'        => esc_html__( 'Registration link', 'gutz-dc-events' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'gutz-dc-events' ),
				'label_off'    => esc_html__( 'Hide', 'gutz-dc-events' ),
				'default'      => 'yes',
				'return_value' => 'yes',
			]
		);

		$this->add_control(
			'registration_label',
			[
				'label'     => esc_html__( 'Link text', 'gutz-dc-events' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Zur Anmeldung', 'gutz-dc-events' ),
				'condition' => [ 'show_registration' => 'yes' ],
			]
		);

		$this->add_control(
			'no_registration_text',
			[
				'label'       => esc_html__( 'Text without registration link', 'gutz-dc-events' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => esc_html__( 'Anmeldung folgt', 'gutz-dc-events' ),
				'description' => esc_html__( 'Shown when a tournament has no open registration yet. Leave empty to show nothing.', 'gutz-dc-events' ),
				'condition'   => [ 'show_registration' => 'yes' ],
			]
		);

		$this->add_control(
			'open_in_new_tab',
			[
				'label'        => esc_html__( 'Open in new tab', 'gutz-dc-events' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => [ 'show_registration' => 'yes' ],
			]
		);

		$this->add_control(
			'empty_message',
			[
				'label'       => esc_html__( 'Text when nothing is planned', 'gutz-dc-events' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => esc_html__( 'Zurzeit sind keine Turniere geplant.', 'gutz-dc-events' ),
			]
		);

		$this->add_control(
			'cache_minutes',
			[
				'label'       => esc_html__( 'Cache duration (minutes)', 'gutz-dc-events' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 60,
				'separator'   => 'before',
				'description' => esc_html__( 'How long the event data is kept before it is fetched again. 0 disables caching.', 'gutz-dc-events' ),
			]
		);

		$this->end_controls_section();
	}

	private function register_style_controls(): void {
		$this->start_controls_section(
			'section_style_list',
			[
				'label' => esc_html__( 'List', 'gutz-dc-events' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'item_gap',
			[
				'label'      => esc_html__( 'Space between events', 'gutz-dc-events' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
				'default'    => [
					'unit' => 'px',
					'size' => 16,
				],
				'selectors'  => [
					'{{WRAPPER}} .gutz-events' => 'gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'show_divider',
			[
				'label'        => esc_html__( 'Divider between events', 'gutz-dc-events' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			]
		);

		$this->add_control(
			'divider_color',
			[
				'label'     => esc_html__( 'Divider color', 'gutz-dc-events' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-events__item + .gutz-events__item' => 'border-top-color: {{VALUE}};',
				],
				'condition' => [ 'show_divider' => 'yes' ],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_icon',
			[
				'label'     => esc_html__( 'Icon', 'gutz-dc-events' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'list_icon[value]!' => '' ],
			]
		);

		$this->add_control(
			'icon_color',
			[
				'label'     => esc_html__( 'Color', 'gutz-dc-events' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-events__icon'     => 'color: {{VALUE}};',
					'{{WRAPPER}} .gutz-events__icon svg' => 'fill: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'icon_size',
			[
				'label'      => esc_html__( 'Size', 'gutz-dc-events' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [
					'px' => [
						'min' => 6,
						'max' => 64,
					],
					'em' => [
						'min'  => 0.5,
						'max'  => 4,
						'step' => 0.1,
					],
				],
				'default'    => [
					'unit' => 'em',
					'size' => 1,
				],
				'selectors'  => [
					'{{WRAPPER}} .gutz-events__icon' => 'font-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'icon_gap',
			[
				'label'      => esc_html__( 'Space to the text', 'gutz-dc-events' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 60 ] ],
				'default'    => [
					'unit' => 'px',
					'size' => 10,
				],
				'selectors'  => [
					'{{WRAPPER}} .gutz-events__item' => 'gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'icon_align',
			[
				'label'     => esc_html__( 'Vertical position', 'gutz-dc-events' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'baseline',
				'options'   => [
					'baseline'   => esc_html__( 'On the text baseline', 'gutz-dc-events' ),
					'flex-start' => esc_html__( 'Top', 'gutz-dc-events' ),
					'center'     => esc_html__( 'Middle', 'gutz-dc-events' ),
				],
				'selectors' => [
					'{{WRAPPER}} .gutz-events__icon' => 'align-self: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_text',
			[
				'label' => esc_html__( 'Date, name & venue', 'gutz-dc-events' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'date_color',
			[
				'label'     => esc_html__( 'Date color', 'gutz-dc-events' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-events__date' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'date_typography',
				'selector' => '{{WRAPPER}} .gutz-events__date',
			]
		);

		$this->add_control(
			'name_color',
			[
				'label'     => esc_html__( 'Name color', 'gutz-dc-events' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => [
					'{{WRAPPER}} .gutz-events__name' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'name_typography',
				'selector' => '{{WRAPPER}} .gutz-events__name',
			]
		);

		$this->add_control(
			'venue_color',
			[
				'label'     => esc_html__( 'Venue color', 'gutz-dc-events' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => [
					'{{WRAPPER}} .gutz-events__venue' => 'color: {{VALUE}};',
				],
				'condition' => [ 'show_venue!' => 'never' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'venue_typography',
				'selector'  => '{{WRAPPER}} .gutz-events__venue',
				'condition' => [ 'show_venue!' => 'never' ],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_link',
			[
				'label'     => esc_html__( 'Registration link', 'gutz-dc-events' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_registration' => 'yes' ],
			]
		);

		$this->add_control(
			'link_color',
			[
				'label'     => esc_html__( 'Color', 'gutz-dc-events' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-events__link' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'link_hover_color',
			[
				'label'     => esc_html__( 'Hover color', 'gutz-dc-events' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-events__link:hover, {{WRAPPER}} .gutz-events__link:focus' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'link_typography',
				'selector' => '{{WRAPPER}} .gutz-events__link',
			]
		);

		$this->add_control(
			'no_registration_color',
			[
				'label'     => esc_html__( 'Text without link: color', 'gutz-dc-events' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => [
					'{{WRAPPER}} .gutz-events__no-link' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'no_registration_typography',
				'label'    => esc_html__( 'Text without link: typography', 'gutz-dc-events' ),
				'selector' => '{{WRAPPER}} .gutz-events__no-link',
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$venue_filter = (string) ( $settings['venue_filter'] ?? '' );
		$timezone     = (string) ( $settings['timezone'] ?? GUTZ_DC_Events_API::DEFAULT_TIMEZONE );

		$events = GUTZ_DC_Events_API::get_upcoming(
			[
				'list_url'      => (string) ( $settings['list_url'] ?? '' ),
				'venue'         => $venue_filter,
				'limit'         => (int) ( $settings['limit'] ?? 0 ),
				'cache_minutes' => (int) ( $settings['cache_minutes'] ?? 60 ),
				'timezone'      => $timezone,
			]
		);

		if ( is_wp_error( $events ) ) {
			$this->render_error( $events );
			return;
		}

		if ( [] === $events ) {
			$empty = (string) ( $settings['empty_message'] ?? '' );
			if ( '' !== $empty ) {
				printf( '<p class="gutz-events__empty">%s</p>', esc_html( $empty ) );
			}
			return;
		}

		$date_format = (string) ( $settings['date_format'] ?? '' );
		if ( '' === trim( $date_format ) ) {
			$date_format = 'D, d.m.Y';
		}

		$show_link  = 'yes' === ( $settings['show_registration'] ?? '' );
		$link_label = (string) ( $settings['registration_label'] ?? '' );
		if ( '' === trim( $link_label ) ) {
			$link_label = esc_html__( 'Zur Anmeldung', 'gutz-dc-events' );
		}

		$no_link_text = trim( (string) ( $settings['no_registration_text'] ?? '' ) );

		$link_attrs = 'yes' === ( $settings['open_in_new_tab'] ?? '' )
			? ' target="_blank" rel="noopener noreferrer"'
			: '';

		$icon     = isset( $settings['list_icon'] ) && is_array( $settings['list_icon'] ) ? $settings['list_icon'] : [];
		$has_icon = ! empty( $icon['value'] );

		// 'auto' shows the venue only when the list is not already filtered to one.
		$show_venue = 'always' === ( $settings['show_venue'] ?? 'auto' )
			|| ( 'auto' === ( $settings['show_venue'] ?? 'auto' ) && '' === trim( $venue_filter ) );

		// wp_date() falls back to the site time zone, so pass the resolved one.
		$display_tz = GUTZ_DC_Events_API::resolve_timezone( $timezone );

		$this->add_render_attribute(
			'list',
			'class',
			[
				'gutz-events',
				'yes' === ( $settings['show_divider'] ?? '' ) ? 'gutz-events--divided' : '',
				$has_icon ? 'gutz-events--with-icon' : '',
			]
		);

		?>
		<ul <?php $this->print_render_attribute_string( 'list' ); ?>>
			<?php foreach ( $events as $event ) : ?>
				<li class="gutz-events__item">
					<?php if ( $has_icon ) : ?>
						<span class="gutz-events__icon" aria-hidden="true">
							<?php Icons_Manager::render_icon( $icon, [ 'aria-hidden' => 'true' ] ); ?>
						</span>
					<?php endif; ?>
					<span class="gutz-events__content">
						<time class="gutz-events__date" datetime="<?php echo esc_attr( $event['datetime_attr'] ); ?>">
							<?php echo esc_html( wp_date( $date_format, $event['timestamp'], $display_tz ) ); ?>
						</time>
						<span class="gutz-events__name"><?php echo esc_html( $event['name'] ); ?></span>
						<?php if ( $show_venue && '' !== $event['venue'] ) : ?>
							<span class="gutz-events__venue"><?php echo esc_html( $event['venue'] ); ?></span>
						<?php endif; ?>
						<?php if ( $show_link && '' !== $event['registration_url'] ) : ?>
							<a class="gutz-events__link" href="<?php echo esc_url( $event['registration_url'] ); ?>"<?php echo $link_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup ?>>
								<?php echo esc_html( $link_label ); ?>
							</a>
						<?php elseif ( $show_link && '' !== $no_link_text ) : ?>
							<span class="gutz-events__no-link"><?php echo esc_html( $no_link_text ); ?></span>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Shows API/config problems to editors only — visitors just see nothing
	 * rather than a broken looking notice.
	 */
	private function render_error( WP_Error $error ): void {
		if ( ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return;
		}

		printf(
			'<p class="gutz-events__error">%s</p>',
			esc_html( $error->get_error_message() )
		);
	}
}

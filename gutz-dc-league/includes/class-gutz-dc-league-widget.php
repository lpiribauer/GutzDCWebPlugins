<?php
/**
 * Elementor widget rendering played and upcoming matches of a 3K Darts league.
 *
 * @package gutz-dc-league
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

class GUTZ_DC_League_Widget extends Widget_Base {

	const DEFAULT_URL = 'https://portal.3k-darts.com/frontend/events/10/event/1825/phase/2637/group/0';

	public function get_name(): string {
		return 'gutz_dc_league';
	}

	public function get_title(): string {
		return esc_html__( '3K League Matches', 'gutz-dc-league' );
	}

	public function get_icon(): string {
		return 'eicon-calendar';
	}

	public function get_categories(): array {
		return [ 'general' ];
	}

	public function get_keywords(): array {
		return [ 'dart', 'darts', 'liga', 'league', 'matches', 'spiele', 'gutz', '3k' ];
	}

	public function get_style_depends(): array {
		return [ 'gutz-dc-league' ];
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
		$options = [ 'site' => esc_html__( 'WordPress site setting', 'gutz-dc-league' ) ];

		foreach ( timezone_identifiers_list( DateTimeZone::EUROPE ) as $identifier ) {
			$options[ $identifier ] = $identifier;
		}

		$options['UTC'] = 'UTC';

		return $options;
	}

	private function switcher( string $id, string $label, string $default, array $extra = [] ): void {
		$this->add_control(
			$id,
			array_merge(
				[
					'label'        => $label,
					'type'         => Controls_Manager::SWITCHER,
					'label_on'     => esc_html__( 'Show', 'gutz-dc-league' ),
					'label_off'    => esc_html__( 'Hide', 'gutz-dc-league' ),
					'default'      => $default,
					'return_value' => 'yes',
				],
				$extra
			)
		);
	}

	private function register_content_controls(): void {
		$this->start_controls_section(
			'section_content',
			[
				'label' => esc_html__( 'Matches', 'gutz-dc-league' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'league_url',
			[
				'label'       => esc_html__( 'League URL', 'gutz-dc-league' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => self::DEFAULT_URL,
				'placeholder' => self::DEFAULT_URL,
				'description' => esc_html__( 'The league page on portal.3k-darts.com. Every match day of the phase is loaded, whichever tab the URL points to.', 'gutz-dc-league' ),
			]
		);

		$this->add_control(
			'team_filter',
			[
				'label'       => esc_html__( 'Only this team', 'gutz-dc-league' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => 'GUTZ DC e.V.',
				'description' => esc_html__( 'The exact team name as 3K spells it, so a second team of the same club stays out. Home and away games are both listed. Leave empty for the whole league.', 'gutz-dc-league' ),
			]
		);

		$this->switcher(
			'show_past',
			esc_html__( 'Played matches', 'gutz-dc-league' ),
			'yes',
			[
				'separator'   => 'before',
				'description' => esc_html__( 'Lists the most recently played matches with their result above the upcoming ones.', 'gutz-dc-league' ),
			]
		);

		$this->add_control(
			'past_limit',
			[
				'label'       => esc_html__( 'How many played matches', 'gutz-dc-league' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 3,
				'condition'   => [ 'show_past' => 'yes' ],
				'description' => esc_html__( 'Counted back from today, so the newest results are shown. 0 shows all of them.', 'gutz-dc-league' ),
			]
		);

		$this->add_control(
			'limit',
			[
				'label'       => esc_html__( 'How many upcoming matches', 'gutz-dc-league' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 3,
				'description' => esc_html__( '0 shows every upcoming match.', 'gutz-dc-league' ),
			]
		);

		$this->add_control(
			'date_format',
			[
				'label'       => esc_html__( 'Date format', 'gutz-dc-league' ),
				'type'        => Controls_Manager::TEXT,
				'separator'   => 'before',
				'default'     => 'D, d.m.Y, H:i',
				'description' => esc_html__( 'PHP date format, translated into the site language.', 'gutz-dc-league' ),
			]
		);

		$this->add_control(
			'timezone',
			[
				'label'       => esc_html__( 'Time zone', 'gutz-dc-league' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => GUTZ_DC_League_API::DEFAULT_TIMEZONE,
				'options'     => $this->get_timezone_options(),
				'description' => esc_html__( 'The API delivers UTC. Keep this on the time zone the matches are played in, not the one your server runs in.', 'gutz-dc-league' ),
			]
		);

		$this->switcher(
			'show_league',
			esc_html__( 'League title link', 'gutz-dc-league' ),
			'yes',
			[
				'separator'   => 'before',
				'description' => esc_html__( 'Shows the league name above the matches, linked to the league on 3K Darts.', 'gutz-dc-league' ),
			]
		);

		$this->switcher( 'show_round', esc_html__( 'Match day', 'gutz-dc-league' ), 'yes' );
		$this->switcher( 'show_venue', esc_html__( 'Venue', 'gutz-dc-league' ), 'yes' );

		$this->add_control(
			'separator_text',
			[
				'label'       => esc_html__( 'Between the teams', 'gutz-dc-league' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '–',
				'description' => esc_html__( 'Leave empty to show nothing between home and away team.', 'gutz-dc-league' ),
			]
		);

		$this->add_control(
			'live_label',
			[
				'label'   => esc_html__( 'Label for running matches', 'gutz-dc-league' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Live', 'gutz-dc-league' ),
			]
		);

		$this->switcher(
			'show_report',
			esc_html__( 'Match link', 'gutz-dc-league' ),
			'yes',
			[
				'separator'   => 'before',
				'description' => esc_html__( 'Links to the match on 3K Darts, where the lineup and the single games are listed.', 'gutz-dc-league' ),
			]
		);

		$this->add_control(
			'report_label',
			[
				'label'     => esc_html__( 'Link text', 'gutz-dc-league' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Spielbericht', 'gutz-dc-league' ),
				'condition' => [ 'show_report' => 'yes' ],
			]
		);

		$this->add_control(
			'report_new_tab',
			[
				'label'        => esc_html__( 'Open links in new tab', 'gutz-dc-league' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			]
		);

		$this->add_control(
			'empty_message',
			[
				'label'       => esc_html__( 'Text when nothing is planned', 'gutz-dc-league' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'separator'   => 'before',
				'default'     => esc_html__( 'Zurzeit sind keine Spiele angesetzt.', 'gutz-dc-league' ),
			]
		);

		$this->add_control(
			'cache_minutes',
			[
				'label'       => esc_html__( 'Cache duration (minutes)', 'gutz-dc-league' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 60,
				'separator'   => 'before',
				'description' => esc_html__( 'How long the match plan is kept before it is fetched again. Loading it takes one request per match day, so keep caching on. 0 disables it.', 'gutz-dc-league' ),
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Color + typography pair for one element.
	 */
	private function text_style( string $prefix, string $label, string $selector, array $extra = [] ): void {
		$this->add_control(
			$prefix . '_color',
			array_merge(
				[
					'label'     => $label,
					'type'      => Controls_Manager::COLOR,
					'separator' => 'before',
					'selectors' => [ $selector => 'color: {{VALUE}};' ],
				],
				$extra
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array_merge(
				[
					'name'     => $prefix . '_typography',
					'selector' => $selector,
				],
				$extra
			)
		);
	}

	private function register_style_controls(): void {
		$this->start_controls_section(
			'section_style_list',
			[
				'label' => esc_html__( 'List', 'gutz-dc-league' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'item_gap',
			[
				'label'      => esc_html__( 'Space between matches', 'gutz-dc-league' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
				'default'    => [
					'unit' => 'px',
					'size' => 20,
				],
				'selectors'  => [
					'{{WRAPPER}} .gutz-league' => 'gap: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .gutz-league--divided .gutz-league__item + .gutz-league__item' => 'padding-top: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'show_divider',
			[
				'label'        => esc_html__( 'Divider between matches', 'gutz-dc-league' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			]
		);

		$this->add_control(
			'divider_color',
			[
				'label'     => esc_html__( 'Divider color', 'gutz-dc-league' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-league__item + .gutz-league__item' => 'border-top-color: {{VALUE}};',
				],
				'condition' => [ 'show_divider' => 'yes' ],
			]
		);

		$this->add_control(
			'past_opacity',
			[
				'label'     => esc_html__( 'Opacity of played matches', 'gutz-dc-league' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => [ 'px' => [ 'min' => 0.1, 'max' => 1, 'step' => 0.05 ] ],
				'default'   => [ 'size' => 0.5 ],
				'selectors' => [
					'{{WRAPPER}} .gutz-league__item--past' => 'opacity: {{SIZE}};',
				],
				'condition' => [ 'show_past' => 'yes' ],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_text',
			[
				'label' => esc_html__( 'Texts', 'gutz-dc-league' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->text_style(
			'league',
			esc_html__( 'League title', 'gutz-dc-league' ),
			'{{WRAPPER}} .gutz-league__title, {{WRAPPER}} .gutz-league__title-link',
			[ 'condition' => [ 'show_league' => 'yes' ] ]
		);
		$this->text_style( 'date', esc_html__( 'Date & match day', 'gutz-dc-league' ), '{{WRAPPER}} .gutz-league__meta' );
		$this->text_style( 'team', esc_html__( 'Team names', 'gutz-dc-league' ), '{{WRAPPER}} .gutz-league__team' );
		$this->text_style(
			'separator',
			esc_html__( 'Separator', 'gutz-dc-league' ),
			'{{WRAPPER}} .gutz-league__vs',
			[ 'condition' => [ 'separator_text!' => '' ] ]
		);
		$this->text_style( 'result', esc_html__( 'Result', 'gutz-dc-league' ), '{{WRAPPER}} .gutz-league__result' );
		$this->text_style(
			'venue',
			esc_html__( 'Venue', 'gutz-dc-league' ),
			'{{WRAPPER}} .gutz-league__venue',
			[ 'condition' => [ 'show_venue' => 'yes' ] ]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_report',
			[
				'label'     => esc_html__( 'Match link', 'gutz-dc-league' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_report' => 'yes' ],
			]
		);

		$this->add_control(
			'report_color',
			[
				'label'     => esc_html__( 'Color', 'gutz-dc-league' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-league__report' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'report_hover_color',
			[
				'label'     => esc_html__( 'Hover color', 'gutz-dc-league' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-league__report:hover, {{WRAPPER}} .gutz-league__report:focus' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'report_typography',
				'selector' => '{{WRAPPER}} .gutz-league__report',
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$league_url  = (string) ( $settings['league_url'] ?? '' );
		$team_filter = (string) ( $settings['team_filter'] ?? '' );
		$timezone    = (string) ( $settings['timezone'] ?? GUTZ_DC_League_API::DEFAULT_TIMEZONE );
		$cache       = (int) ( $settings['cache_minutes'] ?? 60 );

		// The API reads 0 as "none" and -1 as "all"; the control reads 0 as "all".
		$past = 0;
		if ( 'yes' === ( $settings['show_past'] ?? '' ) ) {
			$past = (int) ( $settings['past_limit'] ?? 3 );
			$past = $past > 0 ? $past : -1;
		}

		$result = GUTZ_DC_League_API::get_matches(
			[
				'league_url'    => $league_url,
				'team'          => $team_filter,
				'limit'         => (int) ( $settings['limit'] ?? 0 ),
				'past'          => $past,
				'cache_minutes' => $cache,
				'timezone'      => $timezone,
			]
		);

		if ( is_wp_error( $result ) ) {
			$this->render_notice( $result->get_error_message() );
			return;
		}

		$link_attrs = 'yes' === ( $settings['report_new_tab'] ?? '' )
			? ' target="_blank" rel="noopener noreferrer"'
			: '';

		if ( 'yes' === ( $settings['show_league'] ?? 'yes' ) ) {
			$this->render_title( $result['league'], $link_attrs );
		}

		$matches = $result['matches'];

		if ( [] === $matches ) {
			$this->render_empty( $settings, $league_url, $team_filter, $cache );
			return;
		}

		$date_format = trim( (string) ( $settings['date_format'] ?? '' ) );
		if ( '' === $date_format ) {
			$date_format = 'D, d.m.Y, H:i';
		}

		$show_round = 'yes' === ( $settings['show_round'] ?? '' );
		$show_venue = 'yes' === ( $settings['show_venue'] ?? '' );
		$separator  = trim( (string) ( $settings['separator_text'] ?? '' ) );
		$live_label = trim( (string) ( $settings['live_label'] ?? '' ) );

		$show_report  = 'yes' === ( $settings['show_report'] ?? '' );
		$report_label = trim( (string) ( $settings['report_label'] ?? '' ) );
		if ( '' === $report_label ) {
			$report_label = esc_html__( 'Spielbericht', 'gutz-dc-league' );
		}

		// wp_date() falls back to the site time zone, so pass the resolved one.
		$display_tz = GUTZ_DC_League_API::resolve_timezone( $timezone );

		$this->add_render_attribute(
			'list',
			'class',
			[
				'gutz-league',
				'yes' === ( $settings['show_divider'] ?? '' ) ? 'gutz-league--divided' : '',
			]
		);

		?>
		<ul <?php $this->print_render_attribute_string( 'list' ); ?>>
			<?php foreach ( $matches as $match ) : ?>
				<?php
				$classes = [ 'gutz-league__item' ];
				if ( $match['is_past'] && ! $match['is_live'] ) {
					$classes[] = 'gutz-league__item--past';
				}
				if ( $match['is_live'] ) {
					$classes[] = 'gutz-league__item--live';
				}

				// Running matches show their score so far, finished ones the result.
				$show_score = ( $match['has_result'] || $match['is_live'] )
					&& null !== $match['home_sets']
					&& null !== $match['away_sets'];

				$venue = $match['venue'];
				if ( '' !== $match['city'] && false === stripos( $venue, $match['city'] ) ) {
					$venue = '' === $venue ? $match['city'] : $venue . ', ' . $match['city'];
				}
				?>
				<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
					<div class="gutz-league__meta">
						<time class="gutz-league__date" datetime="<?php echo esc_attr( $match['datetime_attr'] ); ?>">
							<?php echo esc_html( wp_date( $date_format, $match['timestamp'], $display_tz ) ); ?>
						</time>
						<?php if ( $show_round && '' !== $match['round_name'] ) : ?>
							<span class="gutz-league__round"><?php echo esc_html( $match['round_name'] ); ?></span>
						<?php endif; ?>
						<?php if ( $match['is_live'] && '' !== $live_label ) : ?>
							<span class="gutz-league__live"><?php echo esc_html( $live_label ); ?></span>
						<?php endif; ?>
					</div>
					<div class="gutz-league__teams">
						<span class="gutz-league__team gutz-league__team--home"><?php echo esc_html( $match['home'] ); ?></span>
						<?php if ( $show_score ) : ?>
							<span class="gutz-league__result">
								<?php
								printf(
									/* translators: 1: sets won by the home team, 2: sets won by the away team */
									esc_html__( '%1$d:%2$d', 'gutz-dc-league' ),
									(int) $match['home_sets'],
									(int) $match['away_sets']
								);
								?>
							</span>
						<?php elseif ( '' !== $separator ) : ?>
							<span class="gutz-league__vs" aria-hidden="true"><?php echo esc_html( $separator ); ?></span>
						<?php endif; ?>
						<span class="gutz-league__team gutz-league__team--away"><?php echo esc_html( $match['away'] ); ?></span>
					</div>
					<?php if ( $show_venue && '' !== $venue ) : ?>
						<div class="gutz-league__venue"><?php echo esc_html( $venue ); ?></div>
					<?php endif; ?>
					<?php if ( $show_report && '' !== $match['report_url'] ) : ?>
						<a
							class="gutz-league__report"
							href="<?php echo esc_url( $match['report_url'] ); ?>"
							<?php
							// Every link says the same thing, so name it by the
							// fixture for anyone tabbing through the list.
							printf(
								'aria-label="%s"',
								esc_attr(
									sprintf(
										/* translators: 1: link text, 2: home team, 3: away team */
										__( '%1$s: %2$s against %3$s', 'gutz-dc-league' ),
										$report_label,
										$match['home'],
										$match['away']
									)
								)
							);
							?>
							<?php echo $link_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup ?>
						><?php echo esc_html( $report_label ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * League name, plus the phase when it says more than the generic "Liga".
	 *
	 * @param array{event_name:string,phase_name:string,url:string} $league
	 */
	private function render_title( array $league, string $link_attrs ): void {
		$title = $league['event_name'];
		$phase = $league['phase_name'];

		if ( '' !== $phase && 0 !== strcasecmp( $phase, 'Liga' ) && false === stripos( $title, $phase ) ) {
			$title = '' === $title ? $phase : $title . ' – ' . $phase;
		}

		if ( '' === $title ) {
			return;
		}

		?>
		<h6 class="gutz-league__title">
			<?php if ( '' !== $league['url'] ) : ?>
				<a
					class="gutz-league__title-link"
					href="<?php echo esc_url( $league['url'] ); ?>"
					<?php echo $link_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup ?>
				><?php echo esc_html( $title ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $title ); ?>
			<?php endif; ?>
		</h6>
		<?php
	}

	/**
	 * Nothing to list. In the editor a filter that matches no team in the
	 * league is worth calling out — otherwise a typo looks like an empty season.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function render_empty( array $settings, string $league_url, string $team_filter, int $cache ): void {
		if ( '' !== trim( $team_filter ) && $this->is_edit_mode() ) {
			$names = GUTZ_DC_League_API::get_team_names( $league_url, $cache );

			if ( is_array( $names ) && ! GUTZ_DC_League_API::filter_matches_any( $team_filter, $names ) ) {
				$this->render_notice(
					sprintf(
						/* translators: 1: team filter typed by the user, 2: comma separated team names */
						__( 'No team called "%1$s" plays in this league. Available teams: %2$s', 'gutz-dc-league' ),
						$team_filter,
						implode( ', ', $names )
					)
				);
				return;
			}
		}

		$empty = (string) ( $settings['empty_message'] ?? '' );
		if ( '' !== $empty ) {
			printf( '<p class="gutz-league__empty">%s</p>', esc_html( $empty ) );
		}
	}

	/**
	 * Shows API/config problems to editors only — visitors just see nothing
	 * rather than a broken looking notice.
	 */
	private function render_notice( string $message ): void {
		if ( ! $this->is_edit_mode() ) {
			return;
		}

		printf( '<p class="gutz-league__error">%s</p>', esc_html( $message ) );
	}

	private function is_edit_mode(): bool {
		return \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}

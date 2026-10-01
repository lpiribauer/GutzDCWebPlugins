<?php
/**
 * Elementor widget rendering the next league matches of a team.
 *
 * @package gutz-dc-matches
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

class GUTZ_DC_Matches_Widget extends Widget_Base {

	public function get_name(): string {
		return 'gutz_dc_matches';
	}

	public function get_title(): string {
		return esc_html__( 'Dart Matches', 'gutz-dc-matches' );
	}

	public function get_icon(): string {
		return 'eicon-calendar';
	}

	public function get_categories(): array {
		return [ 'general' ];
	}

	public function get_keywords(): array {
		return [ 'dart', 'darts', 'liga', 'league', 'matches', 'spiele', 'gutz' ];
	}

	public function get_style_depends(): array {
		return [ 'gutz-dc-matches' ];
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
		$options = [ 'site' => esc_html__( 'WordPress site setting', 'gutz-dc-matches' ) ];

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
				'label' => esc_html__( 'Matches', 'gutz-dc-matches' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'league_id',
			[
				'label'       => esc_html__( 'League ID', 'gutz-dc-matches' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => '420c2a5d-470c-452e-8306-51459541da4a',
				'placeholder' => '420c2a5d-470c-452e-8306-51459541da4a',
				'description' => esc_html__( 'The season league UUID from the Steeldartliga administration. A URL containing the UUID works too.', 'gutz-dc-matches' ),
			]
		);

		$this->add_control(
			'team_filter',
			[
				'label'       => esc_html__( 'Only this team', 'gutz-dc-matches' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => 'GUTZ DC',
				'description' => esc_html__( 'The exact team name as the league spells it, so a second or third team of the same club stays out. Home and away games are both listed. Leave empty for the whole league.', 'gutz-dc-matches' ),
			]
		);

		$this->add_control(
			'limit',
			[
				'label'       => esc_html__( 'Maximum matches', 'gutz-dc-matches' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 3,
				'description' => esc_html__( '0 shows every upcoming match.', 'gutz-dc-matches' ),
			]
		);

		$this->add_control(
			'show_past',
			[
				'label'        => esc_html__( 'Played matches', 'gutz-dc-matches' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'gutz-dc-matches' ),
				'label_off'    => esc_html__( 'Hide', 'gutz-dc-matches' ),
				'default'      => '',
				'return_value' => 'yes',
				'description'  => esc_html__( 'Lists the most recently played matches with their result above the upcoming ones.', 'gutz-dc-matches' ),
			]
		);

		$this->add_control(
			'past_limit',
			[
				'label'       => esc_html__( 'How many played matches', 'gutz-dc-matches' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 1,
				'default'     => 3,
				'condition'   => [ 'show_past' => 'yes' ],
				'description' => esc_html__( 'Counted back from today, so the newest results are shown.', 'gutz-dc-matches' ),
			]
		);

		$this->add_control(
			'date_format',
			[
				'label'       => esc_html__( 'Date format', 'gutz-dc-matches' ),
				'type'        => Controls_Manager::TEXT,
				'separator'   => 'before',
				'default'     => 'D, d.m.Y, H:i',
				'description' => esc_html__( 'PHP date format, translated into the site language.', 'gutz-dc-matches' ),
			]
		);

		$this->add_control(
			'timezone',
			[
				'label'       => esc_html__( 'Time zone', 'gutz-dc-matches' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => GUTZ_DC_Matches_API::DEFAULT_TIMEZONE,
				'options'     => $this->get_timezone_options(),
				'description' => esc_html__( 'The API delivers UTC. Keep this on the time zone the matches are played in, not the one your server runs in.', 'gutz-dc-matches' ),
			]
		);

		$this->add_control(
			'show_logos',
			[
				'label'        => esc_html__( 'Team logos', 'gutz-dc-matches' ),
				'type'         => Controls_Manager::SWITCHER,
				'separator'    => 'before',
				'label_on'     => esc_html__( 'Show', 'gutz-dc-matches' ),
				'label_off'    => esc_html__( 'Hide', 'gutz-dc-matches' ),
				'default'      => 'yes',
				'return_value' => 'yes',
			]
		);

		$this->add_control(
			'separator_text',
			[
				'label'       => esc_html__( 'Between the teams', 'gutz-dc-matches' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '–',
				'description' => esc_html__( 'Leave empty to show nothing between home and away team.', 'gutz-dc-matches' ),
			]
		);

		$this->add_control(
			'show_report',
			[
				'label'        => esc_html__( 'Match report link', 'gutz-dc-matches' ),
				'type'         => Controls_Manager::SWITCHER,
				'separator'    => 'before',
				'label_on'     => esc_html__( 'Show', 'gutz-dc-matches' ),
				'label_off'    => esc_html__( 'Hide', 'gutz-dc-matches' ),
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => esc_html__( 'Links to the match protocol in the league administration.', 'gutz-dc-matches' ),
			]
		);

		$this->add_control(
			'report_label',
			[
				'label'     => esc_html__( 'Link text', 'gutz-dc-matches' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Spielbericht', 'gutz-dc-matches' ),
				'condition' => [ 'show_report' => 'yes' ],
			]
		);

		$this->add_control(
			'report_new_tab',
			[
				'label'        => esc_html__( 'Open in new tab', 'gutz-dc-matches' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => [ 'show_report' => 'yes' ],
			]
		);

		$this->add_control(
			'empty_message',
			[
				'label'       => esc_html__( 'Text when nothing is planned', 'gutz-dc-matches' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => esc_html__( 'Zurzeit sind keine Spiele angesetzt.', 'gutz-dc-matches' ),
			]
		);

		$this->add_control(
			'cache_minutes',
			[
				'label'       => esc_html__( 'Cache duration (minutes)', 'gutz-dc-matches' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 60,
				'separator'   => 'before',
				'description' => esc_html__( 'How long the match data is kept before it is fetched again. 0 disables caching.', 'gutz-dc-matches' ),
			]
		);

		$this->end_controls_section();
	}

	private function register_style_controls(): void {
		$this->start_controls_section(
			'section_style_list',
			[
				'label' => esc_html__( 'List', 'gutz-dc-matches' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'item_gap',
			[
				'label'      => esc_html__( 'Space between matches', 'gutz-dc-matches' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
				'default'    => [
					'unit' => 'px',
					'size' => 20,
				],
				'selectors'  => [
					'{{WRAPPER}} .gutz-matches' => 'gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'show_divider',
			[
				'label'        => esc_html__( 'Divider between matches', 'gutz-dc-matches' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			]
		);

		$this->add_control(
			'divider_color',
			[
				'label'     => esc_html__( 'Divider color', 'gutz-dc-matches' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-matches__item + .gutz-matches__item' => 'border-top-color: {{VALUE}};',
				],
				'condition' => [ 'show_divider' => 'yes' ],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_logo',
			[
				'label'     => esc_html__( 'Logos', 'gutz-dc-matches' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_logos' => 'yes' ],
			]
		);

		$this->add_responsive_control(
			'logo_size',
			[
				'label'      => esc_html__( 'Size', 'gutz-dc-matches' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [ 'px' => [ 'min' => 16, 'max' => 160 ] ],
				'default'    => [
					'unit' => 'px',
					'size' => 44,
				],
				'selectors'  => [
					'{{WRAPPER}} .gutz-matches__logo' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'logo_gap',
			[
				'label'      => esc_html__( 'Space to the team name', 'gutz-dc-matches' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 60 ] ],
				'default'    => [
					'unit' => 'px',
					'size' => 10,
				],
				'selectors'  => [
					'{{WRAPPER}} .gutz-matches__team' => 'gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_text',
			[
				'label' => esc_html__( 'Date & teams', 'gutz-dc-matches' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'date_color',
			[
				'label'     => esc_html__( 'Date color', 'gutz-dc-matches' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-matches__date' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'date_typography',
				'selector' => '{{WRAPPER}} .gutz-matches__date',
			]
		);

		$this->add_control(
			'team_color',
			[
				'label'     => esc_html__( 'Team name color', 'gutz-dc-matches' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => [
					'{{WRAPPER}} .gutz-matches__team-name' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'team_typography',
				'selector' => '{{WRAPPER}} .gutz-matches__team-name',
			]
		);

		$this->add_control(
			'separator_color',
			[
				'label'     => esc_html__( 'Separator color', 'gutz-dc-matches' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => [
					'{{WRAPPER}} .gutz-matches__vs' => 'color: {{VALUE}};',
				],
				'condition' => [ 'separator_text!' => '' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'separator_typography',
				'selector'  => '{{WRAPPER}} .gutz-matches__vs',
				'condition' => [ 'separator_text!' => '' ],
			]
		);

		$this->add_control(
			'result_color',
			[
				'label'     => esc_html__( 'Result color', 'gutz-dc-matches' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => [
					'{{WRAPPER}} .gutz-matches__result' => 'color: {{VALUE}};',
				],
				'condition' => [ 'show_past' => 'yes' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'      => 'result_typography',
				'selector'  => '{{WRAPPER}} .gutz-matches__result',
				'condition' => [ 'show_past' => 'yes' ],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_report',
			[
				'label'     => esc_html__( 'Match report link', 'gutz-dc-matches' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_report' => 'yes' ],
			]
		);

		$this->add_control(
			'report_color',
			[
				'label'     => esc_html__( 'Color', 'gutz-dc-matches' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-matches__report' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'report_hover_color',
			[
				'label'     => esc_html__( 'Hover color', 'gutz-dc-matches' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-matches__report:hover, {{WRAPPER}} .gutz-matches__report:focus' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'report_typography',
				'selector' => '{{WRAPPER}} .gutz-matches__report',
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$league_id   = (string) ( $settings['league_id'] ?? '' );
		$team_filter = (string) ( $settings['team_filter'] ?? '' );
		$timezone    = (string) ( $settings['timezone'] ?? GUTZ_DC_Matches_API::DEFAULT_TIMEZONE );
		$cache       = (int) ( $settings['cache_minutes'] ?? 60 );

		$past = 'yes' === ( $settings['show_past'] ?? '' )
			? max( 1, (int) ( $settings['past_limit'] ?? 3 ) )
			: 0;

		$matches = GUTZ_DC_Matches_API::get_matches(
			[
				'league_id'     => $league_id,
				'team'          => $team_filter,
				'limit'         => (int) ( $settings['limit'] ?? 0 ),
				'past'          => $past,
				'cache_minutes' => $cache,
				'timezone'      => $timezone,
			]
		);

		if ( is_wp_error( $matches ) ) {
			$this->render_notice( $matches->get_error_message() );
			return;
		}

		if ( [] === $matches ) {
			$this->render_empty( $settings, $league_id, $team_filter, $cache );
			return;
		}

		$date_format = (string) ( $settings['date_format'] ?? '' );
		if ( '' === trim( $date_format ) ) {
			$date_format = 'D, d.m.Y, H:i';
		}

		$show_logos = 'yes' === ( $settings['show_logos'] ?? '' );
		$separator  = trim( (string) ( $settings['separator_text'] ?? '' ) );

		$show_report  = 'yes' === ( $settings['show_report'] ?? '' );
		$report_label = trim( (string) ( $settings['report_label'] ?? '' ) );
		if ( '' === $report_label ) {
			$report_label = esc_html__( 'Spielbericht', 'gutz-dc-matches' );
		}

		$report_attrs = 'yes' === ( $settings['report_new_tab'] ?? '' )
			? ' target="_blank" rel="noopener noreferrer"'
			: '';

		// wp_date() falls back to the site time zone, so pass the resolved one.
		$display_tz = GUTZ_DC_Matches_API::resolve_timezone( $timezone );

		$this->add_render_attribute(
			'list',
			'class',
			[
				'gutz-matches',
				'yes' === ( $settings['show_divider'] ?? '' ) ? 'gutz-matches--divided' : '',
				$show_logos ? 'gutz-matches--with-logos' : '',
			]
		);

		?>
		<ul <?php $this->print_render_attribute_string( 'list' ); ?>>
			<?php foreach ( $matches as $match ) : ?>
				<?php
				// A played match shows its score where a fixture shows the
				// separator, so both keep the same three-column shape.
				$has_result = $match['is_past'] && $match['has_result'];
				?>
				<li class="gutz-matches__item<?php echo $match['is_past'] ? ' gutz-matches__item--past' : ''; ?>">
					<time class="gutz-matches__date" datetime="<?php echo esc_attr( $match['datetime_attr'] ); ?>">
						<?php echo esc_html( wp_date( $date_format, $match['timestamp'], $display_tz ) ); ?>
					</time>
					<div class="gutz-matches__teams">
						<?php $this->render_team( $match['home'], 'home', $show_logos ); ?>
						<?php if ( $has_result ) : ?>
							<span class="gutz-matches__result">
								<?php
								printf(
									/* translators: 1: sets won by the home team, 2: sets won by the away team */
									esc_html__( '%1$d:%2$d', 'gutz-dc-matches' ),
									(int) $match['home_sets'],
									(int) $match['away_sets']
								);
								?>
							</span>
						<?php elseif ( '' !== $separator ) : ?>
							<span class="gutz-matches__vs" aria-hidden="true"><?php echo esc_html( $separator ); ?></span>
						<?php endif; ?>
						<?php $this->render_team( $match['away'], 'away', $show_logos ); ?>
					</div>
					<?php if ( $show_report && '' !== $match['report_url'] ) : ?>
						<a
							class="gutz-matches__report"
							href="<?php echo esc_url( $match['report_url'] ); ?>"
							<?php
							// Every link says the same thing, so name it by the
							// fixture for anyone tabbing through the list.
							printf(
								'aria-label="%s"',
								esc_attr(
									sprintf(
										/* translators: 1: link text, 2: home team, 3: away team */
										__( '%1$s: %2$s against %3$s', 'gutz-dc-matches' ),
										$report_label,
										$match['home']['name'],
										$match['away']['name']
									)
								)
							);
							?>
							<?php echo $report_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup ?>
						><?php echo esc_html( $report_label ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * One side of a fixture. The logo carries an empty alt because the team
	 * name sits right next to it — otherwise every team would be read twice.
	 *
	 * @param array{name:string,logo:string} $team
	 */
	private function render_team( array $team, string $side, bool $show_logos ): void {
		?>
		<span class="gutz-matches__team gutz-matches__team--<?php echo esc_attr( $side ); ?>">
			<?php if ( $show_logos ) : ?>
				<?php if ( '' !== $team['logo'] ) : ?>
					<img
						class="gutz-matches__logo"
						src="<?php echo esc_url( $team['logo'] ); ?>"
						alt=""
						loading="lazy"
						decoding="async"
					>
				<?php else : ?>
					<span class="gutz-matches__logo gutz-matches__logo--empty" aria-hidden="true"></span>
				<?php endif; ?>
			<?php endif; ?>
			<span class="gutz-matches__team-name"><?php echo esc_html( $team['name'] ); ?></span>
		</span>
		<?php
	}

	/**
	 * Nothing to list. In the editor a filter that matches no team in the
	 * league is worth calling out — otherwise a typo looks like an empty season.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function render_empty( array $settings, string $league_id, string $team_filter, int $cache ): void {
		if ( '' !== trim( $team_filter ) && $this->is_edit_mode() ) {
			$names = GUTZ_DC_Matches_API::get_team_names( $league_id, $cache );

			if ( is_array( $names ) && ! GUTZ_DC_Matches_API::filter_matches_any( $team_filter, $names ) ) {
				$this->render_notice(
					sprintf(
						/* translators: 1: team filter typed by the user, 2: comma separated team names */
						__( 'No team called "%1$s" plays in this league. Available teams: %2$s', 'gutz-dc-matches' ),
						$team_filter,
						implode( ', ', $names )
					)
				);
				return;
			}
		}

		$empty = (string) ( $settings['empty_message'] ?? '' );
		if ( '' !== $empty ) {
			printf( '<p class="gutz-matches__empty">%s</p>', esc_html( $empty ) );
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

		printf( '<p class="gutz-matches__error">%s</p>', esc_html( $message ) );
	}

	private function is_edit_mode(): bool {
		return \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}

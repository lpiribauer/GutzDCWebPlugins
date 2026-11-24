<?php
/**
 * Elementor widget rendering a custom text with the season average of a team.
 *
 * @package gutz-dc-average
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

class GUTZ_DC_Average_Widget extends Widget_Base {

	const DEFAULT_TEXT = 'Unser Team-Average in der Saison {season}: {average}';

	public function get_name(): string {
		return 'gutz_dc_average';
	}

	public function get_title(): string {
		return esc_html__( 'Dart Team Average', 'gutz-dc-average' );
	}

	public function get_icon(): string {
		return 'eicon-number-field';
	}

	public function get_categories(): array {
		return [ 'general' ];
	}

	public function get_keywords(): array {
		return [ 'dart', 'darts', 'liga', 'league', 'average', 'schnitt', 'gutz' ];
	}

	public function get_style_depends(): array {
		return [ 'gutz-dc-average' ];
	}

	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	private function register_content_controls(): void {
		$this->start_controls_section(
			'section_content',
			[
				'label' => esc_html__( 'Average', 'gutz-dc-average' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'league_id',
			[
				'label'       => esc_html__( 'League ID', 'gutz-dc-average' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => '420c2a5d-470c-452e-8306-51459541da4a',
				'placeholder' => '420c2a5d-470c-452e-8306-51459541da4a',
				'description' => esc_html__( 'The season league UUID from the Steeldartliga administration. A URL containing the UUID works too.', 'gutz-dc-average' ),
			]
		);

		$this->add_control(
			'team',
			[
				'label'       => esc_html__( 'Team', 'gutz-dc-average' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => 'GUTZ DC',
				'description' => esc_html__( 'The exact team name as the league spells it, so a second or third team of the same club is not mixed up with it.', 'gutz-dc-average' ),
			]
		);

		$this->add_control(
			'average_type',
			[
				'label'       => esc_html__( 'Average', 'gutz-dc-average' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'combined',
				'options'     => [
					'combined' => esc_html__( 'Singles and doubles', 'gutz-dc-average' ),
					'singles'  => esc_html__( 'Singles only', 'gutz-dc-average' ),
					'doubles'  => esc_html__( 'Doubles only', 'gutz-dc-average' ),
				],
				'description' => esc_html__( 'Which average {average} stands for.', 'gutz-dc-average' ),
			]
		);

		$this->add_control(
			'text',
			[
				'label'       => esc_html__( 'Text', 'gutz-dc-average' ),
				'type'        => Controls_Manager::TEXTAREA,
				'separator'   => 'before',
				'rows'        => 3,
				'default'     => self::DEFAULT_TEXT,
				'description' => esc_html__( 'Placeholders: {average}, {singles}, {doubles}, {combined}, {team}, {league}, {season}, {place}, {matches}, {legs}. {place} is the current place in the league table.', 'gutz-dc-average' ),
			]
		);

		$this->add_control(
			'decimals',
			[
				'label'   => esc_html__( 'Decimal places', 'gutz-dc-average' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 0,
				'max'     => 4,
				'default' => 2,
			]
		);

		$this->add_control(
			'html_tag',
			[
				'label'   => esc_html__( 'HTML tag', 'gutz-dc-average' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'p',
				'options' => [
					'p'    => 'p',
					'div'  => 'div',
					'span' => 'span',
					'h1'   => 'H1',
					'h2'   => 'H2',
					'h3'   => 'H3',
					'h4'   => 'H4',
					'h5'   => 'H5',
					'h6'   => 'H6',
				],
			]
		);

		$this->add_control(
			'empty_text',
			[
				'label'       => esc_html__( 'Text before the first match', 'gutz-dc-average' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => esc_html__( 'Noch keine Spiele gewertet.', 'gutz-dc-average' ),
				'description' => esc_html__( 'Shown while the team has no finalized match in this league. Leave empty to show nothing.', 'gutz-dc-average' ),
			]
		);

		$this->add_control(
			'cache_minutes',
			[
				'label'       => esc_html__( 'Cache duration (minutes)', 'gutz-dc-average' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 60,
				'separator'   => 'before',
				'description' => esc_html__( 'How long the average is kept before it is calculated again. Protocols of finished matches are always kept, so only new matches are fetched. 0 disables caching of the average.', 'gutz-dc-average' ),
			]
		);

		$this->end_controls_section();
	}

	private function register_style_controls(): void {
		$this->start_controls_section(
			'section_style_text',
			[
				'label' => esc_html__( 'Text', 'gutz-dc-average' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'align',
			[
				'label'     => esc_html__( 'Alignment', 'gutz-dc-average' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => [
					'left'   => [
						'title' => esc_html__( 'Left', 'gutz-dc-average' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center' => [
						'title' => esc_html__( 'Center', 'gutz-dc-average' ),
						'icon'  => 'eicon-text-align-center',
					],
					'right'  => [
						'title' => esc_html__( 'Right', 'gutz-dc-average' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .gutz-average' => 'text-align: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'text_color',
			[
				'label'     => esc_html__( 'Color', 'gutz-dc-average' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-average' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'text_typography',
				'selector' => '{{WRAPPER}} .gutz-average',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_value',
			[
				'label' => esc_html__( 'Average value', 'gutz-dc-average' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'value_color',
			[
				'label'     => esc_html__( 'Color', 'gutz-dc-average' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .gutz-average__value' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'value_typography',
				'selector' => '{{WRAPPER}} .gutz-average__value',
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$type = (string) ( $settings['average_type'] ?? 'combined' );
		if ( ! in_array( $type, [ 'combined', 'singles', 'doubles' ], true ) ) {
			$type = 'combined';
		}

		$average = GUTZ_DC_Average_API::get_average(
			[
				'league_id'     => (string) ( $settings['league_id'] ?? '' ),
				'team'          => (string) ( $settings['team'] ?? '' ),
				'cache_minutes' => (int) ( $settings['cache_minutes'] ?? 60 ),
			]
		);

		if ( is_wp_error( $average ) ) {
			$this->render_notice( $average->get_error_message() );
			return;
		}

		$tag = (string) ( $settings['html_tag'] ?? 'p' );
		if ( ! in_array( $tag, [ 'p', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) ) {
			$tag = 'p';
		}

		// Nothing thrown yet means there is no number to put into the text.
		if ( null === $average[ $type ] ) {
			$empty = (string) ( $settings['empty_text'] ?? '' );
			if ( '' !== $empty ) {
				printf( '<%1$s class="gutz-average gutz-average--empty">%2$s</%1$s>', $tag, esc_html( $empty ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tag is whitelisted
			}
			return;
		}

		$text = (string) ( $settings['text'] ?? '' );
		if ( '' === trim( $text ) ) {
			$text = self::DEFAULT_TEXT;
		}

		$decimals = min( 4, max( 0, (int) ( $settings['decimals'] ?? 2 ) ) );

		$replacements = [
			'{average}'  => $this->format_value( $average[ $type ], $decimals ),
			'{singles}'  => $this->format_value( $average['singles'], $decimals ),
			'{doubles}'  => $this->format_value( $average['doubles'], $decimals ),
			'{combined}' => $this->format_value( $average['combined'], $decimals ),
			'{team}'     => esc_html( $average['team'] ),
			'{league}'   => esc_html( $average['league_name'] ),
			'{season}'   => esc_html( $average['season'] ),
			'{place}'    => esc_html( number_format_i18n( $average['place'] ) ),
			'{matches}'  => esc_html( number_format_i18n( $average['matches'] ) ),
			'{legs}'     => esc_html( number_format_i18n( $average['legs'] ) ),
		];

		// The text is escaped first, so only the markup of the replacements
		// below ends up as HTML.
		$html = strtr( nl2br( esc_html( $text ) ), $replacements );

		printf( '<%1$s class="gutz-average">%2$s</%1$s>', $tag, $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tag is whitelisted, text escaped above
	}

	/** An average in the site's number format, wrapped for separate styling. */
	private function format_value( ?float $value, int $decimals ): string {
		$formatted = null === $value ? '–' : number_format_i18n( $value, $decimals );

		return '<span class="gutz-average__value">' . esc_html( $formatted ) . '</span>';
	}

	/**
	 * Shows API/config problems to editors only — visitors just see nothing
	 * rather than a broken looking notice.
	 */
	private function render_notice( string $message ): void {
		if ( ! $this->is_edit_mode() ) {
			return;
		}

		printf( '<p class="gutz-average__error">%s</p>', esc_html( $message ) );
	}

	private function is_edit_mode(): bool {
		return \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}

<?php
/**
 * Client for the 3K Darts event backend.
 *
 * The public event pages on portal.3k-darts.com (formerly
 * 2k-dart-software.com, which now redirects there) are an Angular single page
 * app, so their HTML holds no events. The table is filled from this JSON API,
 * which is what we query here:
 *
 *   https://backend4.3k-darts.com/2k-backend4/api/v1/frontend/event/page
 *       ?mandantKey=1985&eventTypeCd=TOURNAMENT&size=200&favoriteIds=
 *
 * Notes on the payload:
 *   - the "Ort" column of the public table is the `playingVenue` field
 *   - `datetime` is UTC; the site renders it in Europe/Berlin (17:00Z -> 19:00)
 *   - results come back newest first, so we re-sort ascending
 *   - the backend's nginx answers 403 to non-browser User-Agents, including
 *     WordPress' default "WordPress/x.y; https://…", so we send our own
 *
 * @package gutz-dc-events
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GUTZ_DC_Events_API {

	const API_ENDPOINT     = 'https://backend4.3k-darts.com/2k-backend4/api/v1/frontend/event/page';
	const DEFAULT_ORIGIN   = 'https://portal.3k-darts.com';
	const USER_AGENT       = 'Mozilla/5.0 (compatible; GUTZ-DC-Events/' . GUTZ_DC_EVENTS_VERSION . '; +https://www.gutz-dc.de/)';
	const DEFAULT_TIMEZONE = 'Europe/Berlin';
	const PAGE_SIZE        = 200;
	const MAX_PAGES        = 25;
	const HTTP_TIMEOUT     = 15;
	const CACHE_PREFIX     = 'gutz_dc_events_';
	const CACHE_INDEX      = 'gutz_dc_events_keys';

	/**
	 * Turns a time zone setting into a DateTimeZone.
	 *
	 * The API hands out UTC and the events themselves happen in Germany, so the
	 * default is Europe/Berlin rather than the WordPress site setting — a site
	 * left on UTC would otherwise show 17:00 for a tournament that starts 19:00.
	 * Pass 'site' to follow the WordPress setting instead.
	 */
	public static function resolve_timezone( string $timezone = self::DEFAULT_TIMEZONE ): DateTimeZone {
		$timezone = trim( $timezone );

		if ( '' === $timezone || 'site' === $timezone ) {
			return wp_timezone();
		}

		try {
			return new DateTimeZone( $timezone );
		} catch ( Exception $e ) {
			return wp_timezone();
		}
	}

	/**
	 * Pulls the mandant key out of an event list URL.
	 *
	 * Accepts the full page URL, e.g.
	 *   https://portal.3k-darts.com/frontend/events/5/mandant/1985
	 * or a bare mandant key, e.g. "1985".
	 *
	 * @return array{mandant_key:int,origin:string}|WP_Error
	 */
	public static function parse_list_url( string $url ) {
		$url = trim( $url );

		if ( '' === $url ) {
			return new WP_Error( 'gutz_dc_events_empty_url', __( 'No event list URL was given.', 'gutz-dc-events' ) );
		}

		// A bare mandant key is good enough to query the API.
		if ( preg_match( '/^\d+$/', $url ) === 1 ) {
			return [
				'mandant_key' => (int) $url,
				'origin'      => self::DEFAULT_ORIGIN,
			];
		}

		if ( preg_match( '#/mandant/(\d+)#', $url, $matches ) !== 1 ) {
			return new WP_Error(
				'gutz_dc_events_bad_url',
				__( 'That does not look like a 3K Darts event list URL. Expected something like https://portal.3k-darts.com/frontend/events/5/mandant/1985', 'gutz-dc-events' )
			);
		}

		$origin = self::DEFAULT_ORIGIN;
		$parts  = wp_parse_url( $url );
		if ( ! empty( $parts['host'] ) ) {
			$scheme = empty( $parts['scheme'] ) ? 'https' : $parts['scheme'];
			$origin = $scheme . '://' . $parts['host'];
		}

		return [
			'mandant_key' => (int) $matches[1],
			'origin'      => $origin,
		];
	}

	/**
	 * Returns upcoming events for a list URL, soonest first.
	 *
	 * @param array{
	 *     list_url?:string,
	 *     venue?:string,
	 *     event_type?:string,
	 *     limit?:int,
	 *     cache_minutes?:int
	 * } $args
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function get_upcoming( array $args = [] ) {
		$args = wp_parse_args(
			$args,
			[
				'list_url'      => '',
				'venue'         => '',
				'event_type'    => 'TOURNAMENT',
				'limit'         => 0,
				'cache_minutes' => 60,
				'timezone'      => self::DEFAULT_TIMEZONE,
			]
		);

		$target = self::parse_list_url( (string) $args['list_url'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$raw_events = self::fetch_raw_events(
			$target['mandant_key'],
			(string) $args['event_type'],
			max( 0, (int) $args['cache_minutes'] )
		);
		if ( is_wp_error( $raw_events ) ) {
			return $raw_events;
		}

		$timezone = self::resolve_timezone( (string) $args['timezone'] );
		$now      = time();
		$needle   = self::normalize( (string) $args['venue'] );

		$events = [];
		foreach ( $raw_events as $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['datetime'] ) ) {
				continue;
			}

			$venue = isset( $raw['playingVenue'] ) ? (string) $raw['playingVenue'] : '';
			if ( '' !== $needle && false === strpos( self::normalize( $venue ), $needle ) ) {
				continue;
			}

			$event = self::map_event( $raw, $timezone, $target['origin'] );
			if ( null === $event || $event['timestamp'] < $now ) {
				continue;
			}

			$events[] = $event;
		}

		usort(
			$events,
			static function ( array $a, array $b ): int {
				return $a['timestamp'] <=> $b['timestamp'];
			}
		);

		$limit = (int) $args['limit'];
		if ( $limit > 0 ) {
			$events = array_slice( $events, 0, $limit );
		}

		return $events;
	}

	/**
	 * Fetches every raw event of a type, following the API's pagination.
	 * Results are cached in a transient so page views do not hammer the API.
	 *
	 * @return array<int,mixed>|WP_Error
	 */
	private static function fetch_raw_events( int $mandant_key, string $event_type, int $cache_minutes ) {
		$cache_key = self::CACHE_PREFIX . md5( $mandant_key . '|' . $event_type );

		if ( $cache_minutes > 0 ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$events = [];

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$url = add_query_arg(
				[
					'mandantKey'  => $mandant_key,
					'eventTypeCd' => $event_type,
					'page'        => $page,
					'size'        => self::PAGE_SIZE,
					'favoriteIds' => '',
				],
				self::API_ENDPOINT
			);

			$response = wp_remote_get(
				$url,
				[
					'timeout'    => self::HTTP_TIMEOUT,
					'user-agent' => self::USER_AGENT,
					'headers'    => [
						'Accept'  => 'application/json',
						'Origin'  => self::DEFAULT_ORIGIN,
						'Referer' => self::DEFAULT_ORIGIN . '/',
					],
				]
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status < 200 || $status >= 300 ) {
				return new WP_Error(
					'gutz_dc_events_http_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'The event API answered with HTTP %d.', 'gutz-dc-events' ),
						$status
					)
				);
			}

			$payload = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $payload ) || ! isset( $payload['content'] ) || ! is_array( $payload['content'] ) ) {
				return new WP_Error(
					'gutz_dc_events_bad_payload',
					__( 'The event API returned an unexpected response.', 'gutz-dc-events' )
				);
			}

			$events = array_merge( $events, $payload['content'] );

			if ( ! empty( $payload['last'] ) || [] === $payload['content'] ) {
				break;
			}
		}

		if ( $cache_minutes > 0 ) {
			set_transient( $cache_key, $events, $cache_minutes * MINUTE_IN_SECONDS );
			self::remember_cache_key( $cache_key );
		}

		return $events;
	}

	/**
	 * Flattens a raw API event into the fields the widget renders.
	 *
	 * @return array<string,mixed>|null null when the date cannot be parsed
	 */
	private static function map_event( array $raw, DateTimeZone $timezone, string $origin ): ?array {
		try {
			$utc = new DateTimeImmutable( (string) $raw['datetime'], new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return null;
		}

		$local = $utc->setTimezone( $timezone );

		$registration_url = '';
		$db_id            = isset( $raw['dbId'] ) ? (int) $raw['dbId'] : 0;
		$form_id          = isset( $raw['registrationForm']['id'] ) ? (int) $raw['registrationForm']['id'] : 0;
		$form_active      = ! empty( $raw['registrationForm']['formActive'] );

		if ( $form_active && $db_id > 0 && $form_id > 0 ) {
			$registration_url = sprintf( '%s/frontend/events/%d/registration/%d/register', $origin, $db_id, $form_id );
		}

		return [
			'id'               => isset( $raw['id'] ) ? (int) $raw['id'] : 0,
			'name'             => isset( $raw['name'] ) ? (string) $raw['name'] : '',
			'timestamp'        => $utc->getTimestamp(),
			'datetime_attr'    => $local->format( DATE_ATOM ),
			'venue'            => isset( $raw['playingVenue'] ) ? (string) $raw['playingVenue'] : '',
			'city'             => isset( $raw['locationCity'] ) ? (string) $raw['locationCity'] : '',
			'kind'             => isset( $raw['eventKindCd'] ) ? (string) $raw['eventKindCd'] : '',
			'registration_url' => $registration_url,
		];
	}

	/**
	 * Folds a venue name down to something that compares reliably: lower case,
	 * single spaces, and no dots. The dots matter — the API spells the venue
	 * "Vereinsheim GUTZ DC e.V" while people naturally type "e.V.", and without
	 * this the filter would silently match nothing.
	 */
	private static function normalize( string $value ): string {
		$folded = preg_replace( '/[.\x{00B7}]+/u', '', $value );
		if ( null !== $folded ) {
			$value = $folded;
		}

		$collapsed = preg_replace( '/\s+/u', ' ', $value );
		if ( null !== $collapsed ) {
			$value = $collapsed;
		}

		return function_exists( 'mb_strtolower' )
			? mb_strtolower( trim( $value ), 'UTF-8' )
			: strtolower( trim( $value ) );
	}

	/** Tracks transient names so flush_cache() can clean up after itself. */
	private static function remember_cache_key( string $cache_key ): void {
		$keys = get_option( self::CACHE_INDEX, [] );
		if ( ! is_array( $keys ) ) {
			$keys = [];
		}

		if ( ! in_array( $cache_key, $keys, true ) ) {
			$keys[] = $cache_key;
			update_option( self::CACHE_INDEX, $keys, false );
		}
	}

	/** Deletes every cached event payload this plugin created. */
	public static function flush_cache(): void {
		$keys = get_option( self::CACHE_INDEX, [] );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				delete_transient( (string) $key );
			}
		}

		delete_option( self::CACHE_INDEX );
	}
}

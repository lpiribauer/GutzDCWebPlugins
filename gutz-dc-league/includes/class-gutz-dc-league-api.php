<?php
/**
 * Client for the 3K Darts league backend.
 *
 * The public league pages on portal.3k-darts.com are an Angular single page
 * app, so their HTML holds no matches. A league URL such as
 *
 *   https://portal.3k-darts.com/frontend/events/10/event/1825/phase/2637/group/0
 *
 * carries a database ID (10), an event ID (1825) and a phase ID (2637). The
 * database ID picks one of several backend servers (see BACKENDS); on that
 * server the match plan is spread over three kinds of requests:
 *
 *   GET {backend}/api/v1/frontend/event/{event}                     name, phases
 *   GET {backend}/api/v1/frontend/event/{event}/phase/{phase}       match days
 *   GET {backend}/api/v1/frontend/event/{event}/phase/{phase}/round/{round}
 *                                                                   matches
 *
 * Notes on the payload:
 *   - `datePlanned` is UTC ("2026-10-24T10:00:00.000+0000" is 12:00 in Berlin)
 *   - a match has a result once `statusCd` is FINISH; `setsHome`/`setsAway`
 *     hold the score and are missing on open matches
 *   - 3K stores no team logos, so the widget shows the venue instead
 *   - the backends' nginx answers 403 to non-browser User-Agents, including
 *     WordPress' default "WordPress/x.y; https://…", so we send our own
 *
 * @package gutz-dc-league
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GUTZ_DC_League_API {

	const DEFAULT_ORIGIN   = 'https://portal.3k-darts.com';
	const USER_AGENT       = 'Mozilla/5.0 (compatible; GUTZ-DC-League/' . GUTZ_DC_LEAGUE_VERSION . '; +https://www.gutz-dc.de/)';
	const DEFAULT_TIMEZONE = 'Europe/Berlin';
	const HTTP_TIMEOUT     = 15;
	const MAX_ROUNDS       = 60;
	const CACHE_PREFIX     = 'gutz_dc_league_';
	const CACHE_INDEX      = 'gutz_dc_league_keys';

	/**
	 * Database ID → backend base URL, as the portal's own frontend maps them.
	 * Local and test servers of the portal are left out.
	 */
	const BACKENDS = [
		1  => 'https://backend1.3k-darts.com/2k-backend1/',
		2  => 'https://backend2.3k-darts.com/2k-backend2/',
		4  => 'https://backend3.3k-darts.com/2k-backend3/',
		5  => 'https://backend4.3k-darts.com/2k-backend4/',
		6  => 'https://backend5.3k-darts.com/2k-backend5/',
		8  => 'https://backend-dartcup.3k-darts.com/2k-api/',
		9  => 'https://backend-dsab.3k-darts.com/2k-backend-dsab/',
		10 => 'https://backend-ddv.3k-darts.com/2k-backend-ddv/',
		11 => 'https://backend6.3k-darts.com/2k-backend6/',
		12 => 'https://backend7.3k-darts.com/2k-backend7/',
		13 => 'https://backend8.3k-darts.com/2k-backend8/',
		14 => 'https://backend9.3k-darts.com/2k-backend9/',
		15 => 'https://backend10.3k-darts.com/2k-backend10/',
		16 => 'https://backend11.3k-darts.com/2k-backend11/',
		17 => 'https://backend12.3k-darts.com/2k-backend12/',
		18 => 'https://backend13.3k-darts.com/2k-backend13/',
		19 => 'https://backend14.3k-darts.com/2k-backend14/',
	];

	/**
	 * Turns a time zone setting into a DateTimeZone.
	 *
	 * The API hands out UTC and the matches are played in Germany, so the
	 * default is Europe/Berlin rather than the WordPress site setting — a site
	 * left on UTC would otherwise show 10:00 for a match that starts at 12:00.
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
	 * Pulls database, event and phase ID out of a 3K league URL.
	 *
	 * The phase is optional: without it the first league phase of the event
	 * is used. Anything after the phase (the /group/… tab) is ignored, because
	 * the widget always loads every match day.
	 *
	 * @return array{db_id:int,event_id:int,phase_id:int,origin:string}|WP_Error
	 */
	public static function parse_league_url( string $url ) {
		$url = trim( $url );

		if ( '' === $url ) {
			return new WP_Error( 'gutz_dc_league_empty_url', __( 'No league URL was given.', 'gutz-dc-league' ) );
		}

		if ( preg_match( '#/events/(\d+)/event/(\d+)(?:/phase/(\d+))?#', $url, $matches ) !== 1 ) {
			return new WP_Error(
				'gutz_dc_league_bad_url',
				__( 'That does not look like a 3K Darts league URL. Expected something like https://portal.3k-darts.com/frontend/events/10/event/1825/phase/2637/group/0', 'gutz-dc-league' )
			);
		}

		$db_id = (int) $matches[1];
		if ( ! isset( self::BACKENDS[ $db_id ] ) ) {
			return new WP_Error(
				'gutz_dc_league_unknown_db',
				sprintf(
					/* translators: %d: database number taken from the URL */
					__( 'The 3K Darts database %d is not known to this plugin.', 'gutz-dc-league' ),
					$db_id
				)
			);
		}

		$origin = self::DEFAULT_ORIGIN;
		$parts  = wp_parse_url( $url );
		if ( ! empty( $parts['host'] ) ) {
			$scheme = empty( $parts['scheme'] ) ? 'https' : $parts['scheme'];
			$origin = $scheme . '://' . $parts['host'];
		}

		return [
			'db_id'    => $db_id,
			'event_id' => (int) $matches[2],
			'phase_id' => isset( $matches[3] ) ? (int) $matches[3] : 0,
			'origin'   => $origin,
		];
	}

	/**
	 * Returns matches of a league in chronological order: the most recently
	 * played ones first, then the upcoming fixtures.
	 *
	 * `limit` caps the upcoming half and 0 means "all of them", while `past`
	 * is a plain count where 0 means "none" and -1 means "all of them".
	 *
	 * @param array{
	 *     league_url?:string,
	 *     team?:string,
	 *     limit?:int,
	 *     past?:int,
	 *     cache_minutes?:int,
	 *     timezone?:string
	 * } $args
	 *
	 * @return array{
	 *     league:array{event_name:string,phase_name:string,url:string},
	 *     matches:array<int,array<string,mixed>>
	 * }|WP_Error
	 */
	public static function get_matches( array $args = [] ) {
		$args = wp_parse_args(
			$args,
			[
				'league_url'    => '',
				'team'          => '',
				'limit'         => 0,
				'past'          => 0,
				'cache_minutes' => 60,
				'timezone'      => self::DEFAULT_TIMEZONE,
			]
		);

		$payload = self::fetch_payload( (string) $args['league_url'], max( 0, (int) $args['cache_minutes'] ) );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$timezone = self::resolve_timezone( (string) $args['timezone'] );
		$now      = time();
		$needle   = self::normalize( (string) $args['team'] );

		$past     = [];
		$upcoming = [];

		foreach ( $payload['matches'] as $raw ) {
			$match = self::map_match( $raw, $timezone, $payload );
			if ( null === $match ) {
				continue;
			}

			// The filter looks at both sides so home and away games both show up.
			if ( '' !== $needle
				&& self::normalize( $match['home'] ) !== $needle
				&& self::normalize( $match['away'] ) !== $needle ) {
				continue;
			}

			// A finished match is past even if it was played ahead of schedule,
			// and an unfinished one whose start lies behind us is past as well —
			// unless it is still running, which keeps it with the upcoming ones.
			$match['is_past'] = $match['has_result']
				|| ( ! $match['is_live'] && $match['timestamp'] < $now );

			if ( $match['is_past'] ) {
				$past[] = $match;
			} else {
				$upcoming[] = $match;
			}
		}

		usort( $past, [ self::class, 'compare_matches' ] );
		usort( $upcoming, [ self::class, 'compare_matches' ] );

		// Past matches are counted backwards from now, so the ones shown are
		// always the most recent — but they still read oldest to newest.
		$past_count = (int) $args['past'];
		if ( 0 === $past_count ) {
			$past = [];
		} elseif ( $past_count > 0 ) {
			$past = array_slice( $past, -$past_count );
		}

		$limit    = (int) $args['limit'];
		$upcoming = $limit > 0 ? array_slice( $upcoming, 0, $limit ) : $upcoming;

		return [
			'league'  => [
				'event_name' => (string) $payload['event_name'],
				'phase_name' => (string) $payload['phase_name'],
				'url'        => self::league_url( $payload ),
			],
			'matches' => array_merge( $past, $upcoming ),
		];
	}

	/** Same kick-off: keep the order the league plan gives them. */
	private static function compare_matches( array $a, array $b ): int {
		return [ $a['timestamp'], $a['order'] ] <=> [ $b['timestamp'], $b['order'] ];
	}

	/**
	 * Team names taking part in the league. Used by the editor to say which
	 * spellings the team filter can match.
	 *
	 * @return array<int,string>|WP_Error
	 */
	public static function get_team_names( string $league_url, int $cache_minutes = 60 ) {
		$payload = self::fetch_payload( $league_url, max( 0, $cache_minutes ) );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$names = [];
		foreach ( $payload['matches'] as $raw ) {
			foreach ( [ 'home', 'away' ] as $side ) {
				$name = (string) $raw[ $side ];
				if ( '' !== $name && ! in_array( $name, $names, true ) ) {
					$names[] = $name;
				}
			}
		}

		sort( $names );

		return $names;
	}

	/**
	 * True when the filter names one of the given teams. Lets the editor tell a
	 * misspelled team apart from a season that is simply over.
	 *
	 * @param array<int,string> $names
	 */
	public static function filter_matches_any( string $filter, array $names ): bool {
		$needle = self::normalize( $filter );

		if ( '' === $needle ) {
			return true;
		}

		foreach ( $names as $name ) {
			if ( self::normalize( (string) $name ) === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Loads event, phase and every match day, and caches the slimmed-down
	 * result. A failure on any request fails the whole load, so a half-filled
	 * match plan never ends up in the cache.
	 *
	 * @return array{
	 *     db_id:int,event_id:int,phase_id:int,origin:string,
	 *     event_name:string,phase_name:string,
	 *     matches:array<int,array<string,mixed>>
	 * }|WP_Error
	 */
	private static function fetch_payload( string $league_url, int $cache_minutes ) {
		$target = self::parse_league_url( $league_url );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$cache_key = self::CACHE_PREFIX . md5( $target['db_id'] . '|' . $target['event_id'] . '|' . $target['phase_id'] );

		if ( $cache_minutes > 0 ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['matches'], $cached['event_name'], $cached['phase_id'] ) ) {
				// The origin is the only part that depends on the URL as typed.
				$cached['origin'] = $target['origin'];
				return $cached;
			}
		}

		$base = self::BACKENDS[ $target['db_id'] ] . 'api/v1/frontend/event/' . $target['event_id'];

		$event = self::get_json( $base );
		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$phase_id = self::pick_phase( $event['phases'] ?? [], $target['phase_id'] );
		if ( 0 === $phase_id ) {
			return new WP_Error(
				'gutz_dc_league_no_phase',
				__( 'This 3K Darts event has no league phase.', 'gutz-dc-league' )
			);
		}

		$phase = self::get_json( $base . '/phase/' . $phase_id );
		if ( is_wp_error( $phase ) ) {
			return $phase;
		}

		$rounds = isset( $phase['rounds'] ) && is_array( $phase['rounds'] ) ? $phase['rounds'] : [];
		$rounds = array_slice( $rounds, 0, self::MAX_ROUNDS );

		$matches = [];
		foreach ( $rounds as $round ) {
			if ( ! is_array( $round ) || empty( $round['id'] ) ) {
				continue;
			}

			$details = self::get_json( $base . '/phase/' . $phase_id . '/round/' . (int) $round['id'] );
			if ( is_wp_error( $details ) ) {
				return $details;
			}

			foreach ( (array) ( $details['matches'] ?? [] ) as $raw ) {
				$slim = is_array( $raw ) ? self::slim_match( $raw, $round ) : null;
				if ( null !== $slim ) {
					$matches[] = $slim;
				}
			}
		}

		$payload = [
			'db_id'      => $target['db_id'],
			'event_id'   => $target['event_id'],
			'phase_id'   => $phase_id,
			'origin'     => $target['origin'],
			'event_name' => trim( (string) ( $event['event']['name'] ?? '' ) ),
			'phase_name' => trim( (string) ( $phase['name'] ?? '' ) ),
			'matches'    => $matches,
		];

		if ( $cache_minutes > 0 ) {
			set_transient( $cache_key, $payload, $cache_minutes * MINUTE_IN_SECONDS );
			self::remember_cache_key( $cache_key );
		}

		return $payload;
	}

	/**
	 * The phase from the URL if the event has it, otherwise the first league
	 * phase, otherwise the first phase at all.
	 *
	 * @param mixed $phases
	 */
	private static function pick_phase( $phases, int $wanted ): int {
		if ( ! is_array( $phases ) ) {
			return 0;
		}

		$phases = array_values( array_filter( $phases, 'is_array' ) );
		usort(
			$phases,
			static function ( array $a, array $b ): int {
				return (int) ( $a['index'] ?? 0 ) <=> (int) ( $b['index'] ?? 0 );
			}
		);

		$first_league = 0;
		$first_any    = 0;

		foreach ( $phases as $phase ) {
			$id = (int) ( $phase['id'] ?? 0 );
			if ( 0 === $id ) {
				continue;
			}

			if ( $wanted > 0 && $id === $wanted ) {
				return $id;
			}

			if ( 0 === $first_league && 'league' === ( $phase['modeCd'] ?? '' ) ) {
				$first_league = $id;
			}

			if ( 0 === $first_any ) {
				$first_any = $id;
			}
		}

		return $first_league > 0 ? $first_league : $first_any;
	}

	/**
	 * GETs one JSON document from a 3K backend.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function get_json( string $url ) {
		$response = wp_remote_get(
			$url,
			[
				'timeout'    => self::HTTP_TIMEOUT,
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
				'gutz_dc_league_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The 3K Darts API answered with HTTP %d.', 'gutz-dc-league' ),
					$status
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'gutz_dc_league_bad_payload',
				__( 'The 3K Darts API returned an unexpected response.', 'gutz-dc-league' )
			);
		}

		return $body;
	}

	/**
	 * Keeps only what the widget renders, so the cached plan stays small — a
	 * raw match repeats the whole event and phase record.
	 *
	 * @param array<string,mixed> $round
	 *
	 * @return array<string,mixed>|null null for byes and matches without both teams
	 */
	private static function slim_match( array $raw, array $round ): ?array {
		if ( ! empty( $raw['byeHome'] ) || ! empty( $raw['byeAway'] ) ) {
			return null;
		}

		$home = self::team_name( $raw['participantHome'] ?? null );
		$away = self::team_name( $raw['participantGuest'] ?? null );
		if ( '' === $home || '' === $away ) {
			return null;
		}

		$venue = isset( $raw['playingVenue'] ) && is_array( $raw['playingVenue'] ) ? $raw['playingVenue'] : [];

		return [
			'id'         => (int) ( $raw['id'] ?? 0 ),
			'round_id'   => (int) ( $round['id'] ?? 0 ),
			'round_name' => trim( (string) ( $round['name'] ?? '' ) ),
			'order'      => (int) ( $raw['gameNrOrder'] ?? $raw['gameNr'] ?? 0 ),
			'date'       => (string) ( $raw['datePlanned'] ?? '' ),
			'status'     => (string) ( $raw['statusCd'] ?? '' ),
			'home'       => $home,
			'away'       => $away,
			'sets_home'  => isset( $raw['setsHome'] ) && is_numeric( $raw['setsHome'] ) ? (int) $raw['setsHome'] : null,
			'sets_away'  => isset( $raw['setsAway'] ) && is_numeric( $raw['setsAway'] ) ? (int) $raw['setsAway'] : null,
			'venue'      => trim( (string) ( $venue['name'] ?? '' ) ),
			'city'       => trim( (string) ( $venue['locationCity'] ?? '' ) ),
		];
	}

	/** @param mixed $participant */
	private static function team_name( $participant ): string {
		if ( ! is_array( $participant ) ) {
			return '';
		}

		$name = $participant['displayName'] ?? $participant['teamSeason']['name'] ?? '';

		return trim( (string) $name );
	}

	/**
	 * Turns a cached match into the fields the widget renders.
	 *
	 * @param array<string,mixed> $raw
	 * @param array<string,mixed> $payload
	 *
	 * @return array<string,mixed>|null null when the date is missing
	 */
	private static function map_match( $raw, DateTimeZone $timezone, array $payload ): ?array {
		if ( ! is_array( $raw ) || '' === (string) ( $raw['date'] ?? '' ) ) {
			return null;
		}

		try {
			$utc = new DateTimeImmutable( (string) $raw['date'], new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return null;
		}

		$has_result = 'FINISH' === $raw['status'] && null !== $raw['sets_home'] && null !== $raw['sets_away'];

		return [
			'id'            => (int) $raw['id'],
			'report_url'    => self::match_url( $payload, (int) $raw['round_id'], (int) $raw['id'] ),
			'home'          => (string) $raw['home'],
			'away'          => (string) $raw['away'],
			'timestamp'     => $utc->getTimestamp(),
			'datetime_attr' => $utc->setTimezone( $timezone )->format( DATE_ATOM ),
			'order'         => (int) $raw['order'],
			'round_name'    => (string) $raw['round_name'],
			'venue'         => (string) $raw['venue'],
			'city'          => (string) $raw['city'],
			'is_live'       => 'ACTIVE' === $raw['status'] || 'PAUSE' === $raw['status'],
			'home_sets'     => $raw['sets_home'],
			'away_sets'     => $raw['sets_away'],
			'has_result'    => $has_result,
			'is_past'       => false,
		];
	}

	/** Phase page on the portal, i.e. the league overview. */
	private static function league_url( array $payload ): string {
		return sprintf(
			'%s/frontend/events/%d/event/%d/phase/%d/group/0',
			untrailingslashit( (string) $payload['origin'] ),
			(int) $payload['db_id'],
			(int) $payload['event_id'],
			(int) $payload['phase_id']
		);
	}

	/** The portal opens the match day tab and highlights the match. */
	private static function match_url( array $payload, int $round_id, int $match_id ): string {
		if ( $round_id <= 0 || $match_id <= 0 ) {
			return '';
		}

		return sprintf(
			'%s/frontend/events/%d/event/%d/phase/%d/group/%d?matchId=%d',
			untrailingslashit( (string) $payload['origin'] ),
			(int) $payload['db_id'],
			(int) $payload['event_id'],
			(int) $payload['phase_id'],
			$round_id,
			$match_id
		);
	}

	/**
	 * Folds a team name for comparison: lower case and single spaces, nothing
	 * else. The name is compared in full, because a club can field several
	 * teams in the same league — "Klotzscher Darts Club 1 e.V." and
	 * "Klotzscher Darts Club 2 e.V." are different sides.
	 */
	private static function normalize( string $value ): string {
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

	/** Deletes every cached league payload this plugin created. */
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

<?php
/**
 * Client for the Mitteldeutsche Steeldartliga match backend.
 *
 * The league administration runs a GraphQL API. One request returns the whole
 * fixture list of a season league plus the teams taking part in it:
 *
 *   POST https://verwaltung.mitteldeutsche-steeldartliga.de/graphql
 *
 * Notes on the payload:
 *   - `seasonLeagueId` is a UUID, not a number
 *   - `dateTime` is genuine UTC. Saturday fixtures come back as 12:00Z in
 *     summer and 13:00Z in winter, i.e. both are 14:00 in Europe/Berlin —
 *     a naive local timestamp would not shift across the DST boundary
 *   - `team.logoThumb` is an S3 object key, not a URL; it has to be joined
 *     onto LOGO_BASE
 *   - matches come back in no particular order, so we sort ascending
 *
 * @package gutz-dc-matches
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GUTZ_DC_Matches_API {

	const API_ENDPOINT     = 'https://verwaltung.mitteldeutsche-steeldartliga.de/graphql';
	const LOGO_BASE        = 'https://league-of-darts-production.s3.eu-central-1.amazonaws.com';

	/** Match protocol page: %1$s = season league UUID, %2$s = match UUID. */
	const REPORT_URL = 'https://verwaltung.mitteldeutsche-steeldartliga.de/season-leagues/%1$s/matches/%2$s';

	/** League page: %s = season league UUID. */
	const LEAGUE_URL = 'https://verwaltung.mitteldeutsche-steeldartliga.de/season-leagues/%s';

	const SITE_ORIGIN      = 'https://verwaltung.mitteldeutsche-steeldartliga.de';
	const USER_AGENT       = '';
	const DEFAULT_TIMEZONE = 'Europe/Berlin';
	const HTTP_TIMEOUT     = 15;
	const CACHE_PREFIX     = 'gutz_dc_matches_';
	const CACHE_INDEX      = 'gutz_dc_matches_keys';

	/** The operation the league frontend itself uses. */
	const QUERY = <<<'GRAPHQL'
query seasonLeagueMatches($seasonLeagueId: String!) {
  matches(seasonLeagueId: $seasonLeagueId) {
    ...SeasonLeagueMatchFields
    __typename
  }
  seasonLeague(id: $seasonLeagueId) {
    ...SeasonLeagueMatchesFields
    __typename
  }
}

fragment SeasonLeagueMatchesSeasonTeamFields on seasonTeamType {
  id
  team {
    logoThumb
    id
    name
    __typename
  }
  __typename
}

fragment SeasonLeagueMatchFields on matchType {
  id
  homeSeasonTeam {
    id
    team {
      logoThumb
      id
      name
      __typename
    }
    __typename
  }
  awaySeasonTeam {
    id
    team {
      logoThumb
      id
      name
      __typename
    }
    __typename
  }
  code
  matchDay
  finalized
  overdue
  homeLegs
  homeSets
  awayLegs
  awaySets
  dateTime
  requestedDateTime
  __typename
}

fragment SeasonLeagueMatchesFields on seasonLeagueType {
  id
  secondLeg
  location
  league {
    id
    name
    isTournament
    __typename
  }
  seasonTeams {
    ...SeasonLeagueMatchesSeasonTeamFields
    __typename
  }
  __typename
}
GRAPHQL;

	/**
	 * Turns a time zone setting into a DateTimeZone.
	 *
	 * The API hands out UTC and the matches are played in Germany, so the
	 * default is Europe/Berlin rather than the WordPress site setting — a site
	 * left on UTC would otherwise show 12:00 for a match that starts at 14:00.
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

	/** Link to the season league page, or '' when the ID is not valid. */
	public static function league_url( string $value ): string {
		$league_id = self::parse_league_id( $value );

		return is_wp_error( $league_id ) ? '' : sprintf( self::LEAGUE_URL, rawurlencode( $league_id ) );
	}

	/**
	 * Pulls the season league UUID out of whatever the user pasted.
	 *
	 * Accepts the bare UUID or any URL that contains one, so a copied address
	 * from the league site works as well.
	 *
	 * @return string|WP_Error
	 */
	public static function parse_league_id( string $value ) {
		$value = trim( $value );

		if ( '' === $value ) {
			return new WP_Error( 'gutz_dc_matches_empty_id', __( 'No league ID was given.', 'gutz-dc-matches' ) );
		}

		if ( preg_match( '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value, $matches ) !== 1 ) {
			return new WP_Error(
				'gutz_dc_matches_bad_id',
				__( 'That does not look like a league ID. Expected a UUID such as 420c2a5d-470c-452e-8306-51459541da4a.', 'gutz-dc-matches' )
			);
		}

		return strtolower( $matches[0] );
	}

	/**
	 * Returns matches of a season league in chronological order: the most
	 * recently played ones first, then the upcoming fixtures.
	 *
	 * `limit` caps the upcoming half and 0 means "all of them", while `past`
	 * is a plain count and 0 means "none" — results are opt-in.
	 *
	 * @param array{
	 *     league_id?:string,
	 *     team?:string,
	 *     limit?:int,
	 *     past?:int,
	 *     cache_minutes?:int,
	 *     timezone?:string
	 * } $args
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function get_matches( array $args = [] ) {
		$args = wp_parse_args(
			$args,
			[
				'league_id'     => '',
				'team'          => '',
				'limit'         => 0,
				'past'          => 0,
				'cache_minutes' => 60,
				'timezone'      => self::DEFAULT_TIMEZONE,
			]
		);

		// Parsed here as well as in fetch_payload, because the match protocol
		// links are built from the season league UUID.
		$league_id = self::parse_league_id( (string) $args['league_id'] );
		if ( is_wp_error( $league_id ) ) {
			return $league_id;
		}

		$payload = self::fetch_payload( $league_id, max( 0, (int) $args['cache_minutes'] ) );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$timezone = self::resolve_timezone( (string) $args['timezone'] );
		$now      = time();
		$needle   = self::normalize( (string) $args['team'] );

		$league_name = (string) $payload['league_name'];

		$past     = [];
		$upcoming = [];

		foreach ( $payload['matches'] as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$match = self::map_match( $raw, $timezone, $league_id, $league_name );
			if ( null === $match ) {
				continue;
			}

			// The filter looks at both sides so home and away games both show up.
			if ( '' !== $needle
				&& self::normalize( $match['home']['name'] ) !== $needle
				&& self::normalize( $match['away']['name'] ) !== $needle ) {
				continue;
			}

			$match['is_past'] = $match['timestamp'] < $now;

			if ( $match['is_past'] ) {
				$past[] = $match;
			} else {
				$upcoming[] = $match;
			}
		}

		usort( $past, [ self::class, 'compare_by_date' ] );
		usort( $upcoming, [ self::class, 'compare_by_date' ] );

		// Past matches are counted backwards from now, so the ones shown are
		// always the most recent — but they still read oldest to newest.
		$past_count = max( 0, (int) $args['past'] );
		$past       = $past_count > 0 ? array_slice( $past, -$past_count ) : [];

		$limit    = (int) $args['limit'];
		$upcoming = $limit > 0 ? array_slice( $upcoming, 0, $limit ) : $upcoming;

		return array_merge( $past, $upcoming );
	}

	private static function compare_by_date( array $a, array $b ): int {
		return $a['timestamp'] <=> $b['timestamp'];
	}

	/**
	 * Team names taking part in the season league. Used by the editor to say
	 * which spellings the team filter can match.
	 *
	 * @return array<int,string>|WP_Error
	 */
	public static function get_team_names( string $league_id, int $cache_minutes = 60 ) {
		$payload = self::fetch_payload( $league_id, max( 0, $cache_minutes ) );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$names = [];
		foreach ( $payload['teams'] as $season_team ) {
			$name = isset( $season_team['team']['name'] ) ? trim( (string) $season_team['team']['name'] ) : '';
			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
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
	 * Runs the GraphQL query and caches the interesting parts of the answer.
	 *
	 * @return array{matches:array<int,mixed>,teams:array<int,mixed>,league_name:string}|WP_Error
	 */
	private static function fetch_payload( string $league_id, int $cache_minutes ) {
		$league_id = self::parse_league_id( $league_id );
		if ( is_wp_error( $league_id ) ) {
			return $league_id;
		}

		$cache_key = self::CACHE_PREFIX . md5( $league_id );

		if ( $cache_minutes > 0 ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['matches'], $cached['teams'], $cached['league_name'] ) ) {
				return $cached;
			}
		}

		$response = wp_remote_post(
			self::API_ENDPOINT,
			[
				'timeout'    => self::HTTP_TIMEOUT,
				'user-agent' => self::USER_AGENT,
				'headers'    => [
					'Content-Type'             => 'application/json',
					'Accept'                   => 'application/json',
					'Apollo-Require-Preflight' => 'true',
					'Origin'                   => self::SITE_ORIGIN,
					'Referer'                  => self::SITE_ORIGIN . '/',
				],
				'body'       => wp_json_encode(
					[
						'operationName' => 'seasonLeagueMatches',
						'variables'     => [ 'seasonLeagueId' => $league_id ],
						'query'         => self::QUERY,
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'gutz_dc_matches_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The league API answered with HTTP %d.', 'gutz-dc-matches' ),
					$status
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// GraphQL reports problems in a 200 response, so check before the data.
		if ( is_array( $body ) && ! empty( $body['errors'][0]['message'] ) ) {
			return new WP_Error(
				'gutz_dc_matches_graphql_error',
				sprintf(
					/* translators: %s: error message returned by the league API */
					__( 'The league API rejected the request: %s', 'gutz-dc-matches' ),
					(string) $body['errors'][0]['message']
				)
			);
		}

		if ( ! is_array( $body ) || ! isset( $body['data']['matches'] ) || ! is_array( $body['data']['matches'] ) ) {
			return new WP_Error(
				'gutz_dc_matches_bad_payload',
				__( 'The league API returned an unexpected response.', 'gutz-dc-matches' )
			);
		}

		$teams = $body['data']['seasonLeague']['seasonTeams'] ?? [];

		$payload = [
			'matches'     => $body['data']['matches'],
			'teams'       => is_array( $teams ) ? $teams : [],
			'league_name' => trim( (string) ( $body['data']['seasonLeague']['league']['name'] ?? '' ) ),
		];

		if ( $cache_minutes > 0 ) {
			set_transient( $cache_key, $payload, $cache_minutes * MINUTE_IN_SECONDS );
			self::remember_cache_key( $cache_key );
		}

		return $payload;
	}

	/**
	 * Flattens a raw match into the fields the widget renders.
	 *
	 * @return array<string,mixed>|null null when the date or a team is missing
	 */
	private static function map_match( array $raw, DateTimeZone $timezone, string $league_id, string $league_name ): ?array {
		if ( empty( $raw['dateTime'] ) ) {
			return null;
		}

		try {
			$utc = new DateTimeImmutable( (string) $raw['dateTime'], new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return null;
		}

		$home = self::map_team( $raw['homeSeasonTeam'] ?? null );
		$away = self::map_team( $raw['awaySeasonTeam'] ?? null );

		// A fixture without both opponents has nothing to show.
		if ( null === $home || null === $away ) {
			return null;
		}

		$home_sets = isset( $raw['homeSets'] ) && is_numeric( $raw['homeSets'] ) ? (int) $raw['homeSets'] : null;
		$away_sets = isset( $raw['awaySets'] ) && is_numeric( $raw['awaySets'] ) ? (int) $raw['awaySets'] : null;

		$id         = isset( $raw['id'] ) ? trim( (string) $raw['id'] ) : '';
		$report_url = '' === $id ? '' : sprintf( self::REPORT_URL, rawurlencode( $league_id ), rawurlencode( $id ) );

		return [
			'id'            => $id,
			'report_url'    => $report_url,
			'home'          => $home,
			'away'          => $away,
			'timestamp'     => $utc->getTimestamp(),
			'datetime_attr' => $utc->setTimezone( $timezone )->format( DATE_ATOM ),
			'league_name'   => $league_name,
			'match_day'     => isset( $raw['matchDay'] ) ? (int) $raw['matchDay'] : 0,
			'home_sets'     => $home_sets,
			'away_sets'     => $away_sets,
			// A match only has a result once the league has signed it off. An
			// overdue or postponed fixture sits in the past with empty sets.
			'has_result'    => ! empty( $raw['finalized'] ) && null !== $home_sets && null !== $away_sets,
			'is_past'       => false,
		];
	}

	/**
	 * Picks name and logo out of a season team.
	 *
	 * @param mixed $season_team
	 *
	 * @return array{name:string,logo:string}|null
	 */
	private static function map_team( $season_team ): ?array {
		if ( ! is_array( $season_team ) || ! isset( $season_team['team'] ) || ! is_array( $season_team['team'] ) ) {
			return null;
		}

		$name = trim( (string) ( $season_team['team']['name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}

		return [
			'name' => $name,
			'logo' => self::logo_url( (string) ( $season_team['team']['logoThumb'] ?? '' ) ),
		];
	}

	/**
	 * Joins an S3 object key onto the logo host. Absolute URLs are kept as they
	 * are in case the API ever starts returning them.
	 */
	private static function logo_url( string $path ): string {
		$path = trim( $path );

		if ( '' === $path ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $path ) === 1 ) {
			return $path;
		}

		return self::LOGO_BASE . '/' . ltrim( $path, '/' );
	}

	/**
	 * Folds a team name for comparison: lower case and single spaces, nothing
	 * else. The name is deliberately left otherwise intact and compared in
	 * full, because a club can field several teams in the same league —
	 * "GUTZ DC", "GUTZ DC II" and "GUTZ DC III" are three different sides, and
	 * any kind of partial match would pull all of their fixtures into one list.
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

	/** Deletes every cached match payload this plugin created. */
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

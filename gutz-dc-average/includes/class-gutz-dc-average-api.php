<?php
/**
 * Calculates the 3-dart average of a team in a Mitteldeutsche Steeldartliga
 * season league.
 *
 * The league administration runs a GraphQL API:
 *
 *   POST https://verwaltung.mitteldeutsche-steeldartliga.de/graphql
 *
 * There is no stored average, so it is worked out the way the match report
 * does it: one `matches` request for the fixture list, then one `protocol`
 * request per finalized match of the team. The average is
 * total points / total darts × 3 over every leg the team threw in.
 *
 * Notes on the protocol payload:
 *   - `rounds` is a list of blocks, each block a list of games
 *   - every game has a `scorer` list of legs; a leg holds the visit scores of
 *     both sides as strings (`home`, `away`), the start score per side
 *     (`gamePointsHome`, `gamePointsAway`, 501 when empty) and `last`, the
 *     number of darts of the checkout visit
 *   - `isTwoVsTwo` marks doubles games
 *
 * @package gutz-dc-average
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GUTZ_DC_Average_API {

	const API_ENDPOINT = 'https://verwaltung.mitteldeutsche-steeldartliga.de/graphql';
	const USER_AGENT = '';
	const HTTP_TIMEOUT = 15;
	const CACHE_PREFIX = 'gutz_dc_average_';
	const CACHE_INDEX  = 'gutz_dc_average_keys';

	/** A finalized protocol does not change any more, so it is kept a long time. */
	const PROTOCOL_CACHE_SECONDS = 30 * DAY_IN_SECONDS;

	const MATCHES_QUERY = <<<'GRAPHQL'
query seasonLeagueMatches($seasonLeagueId: String!) {
  matches(seasonLeagueId: $seasonLeagueId) {
    id matchDay finalized dateTime
    homeSeasonTeam { team { name } }
    awaySeasonTeam { team { name } }
  }
  seasonLeague(id: $seasonLeagueId) {
    season { startDate endDate }
    league { name }
    seasonTeams { team { name } }
  }
}
GRAPHQL;

	const PROTOCOL_QUERY = <<<'GRAPHQL'
query protocol($matchId: String!) {
  protocol(matchId: $matchId) {
    data {
      rounds {
        isTwoVsTwo
        scorer { home away last gamePointsHome gamePointsAway }
      }
    }
  }
}
GRAPHQL;

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
			return new WP_Error( 'gutz_dc_average_empty_id', __( 'No league ID was given.', 'gutz-dc-average' ) );
		}

		if ( preg_match( '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value, $matches ) !== 1 ) {
			return new WP_Error(
				'gutz_dc_average_bad_id',
				__( 'That does not look like a league ID. Expected a UUID such as 420c2a5d-470c-452e-8306-51459541da4a.', 'gutz-dc-average' )
			);
		}

		return strtolower( $matches[0] );
	}

	/**
	 * The season averages of one team.
	 *
	 * Averages are floats, or null while the team has not thrown a dart yet.
	 *
	 * @param array{league_id?:string,team?:string,cache_minutes?:int} $args
	 *
	 * @return array{
	 *     team:string,
	 *     league_name:string,
	 *     season:string,
	 *     place:int,
	 *     matches:int,
	 *     legs:int,
	 *     singles:?float,
	 *     doubles:?float,
	 *     combined:?float
	 * }|WP_Error
	 */
	public static function get_average( array $args = [] ) {
		$args = wp_parse_args(
			$args,
			[
				'league_id'     => '',
				'team'          => '',
				'cache_minutes' => 60,
			]
		);

		$league_id = self::parse_league_id( (string) $args['league_id'] );
		if ( is_wp_error( $league_id ) ) {
			return $league_id;
		}

		$needle = self::normalize( (string) $args['team'] );
		if ( '' === $needle ) {
			return new WP_Error( 'gutz_dc_average_empty_team', __( 'No team was given.', 'gutz-dc-average' ) );
		}

		$cache_minutes = max( 0, (int) $args['cache_minutes'] );
		$cache_key     = self::CACHE_PREFIX . md5( $league_id . '|' . $needle );

		if ( $cache_minutes > 0 ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['team'], $cached['place'], $cached['matches'] ) ) {
				return $cached;
			}
		}

		$data = self::gql( self::MATCHES_QUERY, [ 'seasonLeagueId' => $league_id ] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! isset( $data['matches'] ) || ! is_array( $data['matches'] ) || ! is_array( $data['seasonLeague'] ?? null ) ) {
			return self::bad_payload();
		}

		$season_league = $data['seasonLeague'];

		// Resolve the spelling the league uses, so the text shows the real name.
		// The API hands out the season teams already in table order (points,
		// then set difference) and the league site renders them as they come,
		// so the position in this list is the place in the table.
		$names = [];
		$team  = '';
		$place = 0;
		foreach ( array_values( (array) ( $season_league['seasonTeams'] ?? [] ) ) as $index => $season_team ) {
			$name = trim( (string) ( $season_team['team']['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}

			if ( ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}

			if ( '' === $team && self::normalize( $name ) === $needle ) {
				$team  = $name;
				$place = $index + 1;
			}
		}

		if ( '' === $team ) {
			sort( $names );

			return new WP_Error(
				'gutz_dc_average_unknown_team',
				sprintf(
					/* translators: 1: team name typed by the user, 2: comma separated team names */
					__( 'No team called "%1$s" plays in this league. Available teams: %2$s', 'gutz-dc-average' ),
					trim( (string) $args['team'] ),
					implode( ', ', $names )
				)
			);
		}

		$singles = self::new_totals();
		$doubles = self::new_totals();
		$played  = 0;

		foreach ( $data['matches'] as $match ) {
			if ( ! is_array( $match ) || empty( $match['finalized'] ) || empty( $match['id'] ) ) {
				continue;
			}

			if ( self::normalize( (string) ( $match['homeSeasonTeam']['team']['name'] ?? '' ) ) === $needle ) {
				$side = 'home';
			} elseif ( self::normalize( (string) ( $match['awaySeasonTeam']['team']['name'] ?? '' ) ) === $needle ) {
				$side = 'away';
			} else {
				continue;
			}

			$totals = self::match_totals( (string) $match['id'] );
			if ( is_wp_error( $totals ) ) {
				return $totals;
			}

			$singles = self::merge( $singles, $totals[ $side ]['singles'] );
			$doubles = self::merge( $doubles, $totals[ $side ]['doubles'] );
			++$played;
		}

		$combined = self::merge( $singles, $doubles );

		$result = [
			'team'        => $team,
			'league_name' => trim( (string) ( $season_league['league']['name'] ?? '' ) ),
			'season'      => self::season_label( $season_league['season'] ?? null ),
			'place'       => $place,
			'matches'     => $played,
			'legs'        => $combined['legs'],
			'singles'     => self::average( $singles ),
			'doubles'     => self::average( $doubles ),
			'combined'    => self::average( $combined ),
		];

		if ( $cache_minutes > 0 ) {
			set_transient( $cache_key, $result, $cache_minutes * MINUTE_IN_SECONDS );
			self::remember_cache_key( $cache_key );
		}

		return $result;
	}

	/**
	 * Singles and doubles totals of both sides of one match.
	 *
	 * @return array<string,array{singles:array,doubles:array}>|WP_Error
	 */
	private static function match_totals( string $match_id ) {
		$cache_key = self::CACHE_PREFIX . 'protocol_' . md5( $match_id );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['home'], $cached['away'] ) ) {
			return $cached;
		}

		$data = self::gql( self::PROTOCOL_QUERY, [ 'matchId' => $match_id ] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$rounds = $data['protocol']['data']['rounds'] ?? [];

		// Flatten blocks into games, like Array.prototype.flat() would.
		$games = [];
		foreach ( is_array( $rounds ) ? $rounds : [] as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( array_key_exists( 'scorer', $block ) || array_key_exists( 'isTwoVsTwo', $block ) ) {
				$games[] = $block;
				continue;
			}

			foreach ( $block as $game ) {
				if ( is_array( $game ) ) {
					$games[] = $game;
				}
			}
		}

		$totals = [];
		foreach ( [ 'home', 'away' ] as $side ) {
			$singles = self::new_totals();
			$doubles = self::new_totals();

			foreach ( $games as $game ) {
				foreach ( (array) ( $game['scorer'] ?? [] ) as $leg ) {
					$stats = is_array( $leg ) ? self::leg_stats( $leg, $side ) : null;
					if ( null === $stats ) {
						continue;
					}

					if ( ! empty( $game['isTwoVsTwo'] ) ) {
						$doubles = self::merge( $doubles, $stats );
					} else {
						$singles = self::merge( $singles, $stats );
					}
				}
			}

			$totals[ $side ] = [
				'singles' => $singles,
				'doubles' => $doubles,
			];
		}

		set_transient( $cache_key, $totals, self::PROTOCOL_CACHE_SECONDS );
		self::remember_cache_key( $cache_key );

		return $totals;
	}

	/**
	 * Mirrors the leg table on the match report: points scored and darts
	 * thrown by one side in one leg, or null if that side did not throw.
	 *
	 * @return array{points:int,darts:int,legs:int}|null
	 */
	private static function leg_stats( array $leg, string $side ): ?array {
		$visits = $leg[ $side ] ?? [];
		if ( ! is_array( $visits ) || [] === $visits ) {
			return null;
		}

		$start = (int) ( 'home' === $side ? ( $leg['gamePointsHome'] ?? 0 ) : ( $leg['gamePointsAway'] ?? 0 ) );
		if ( 0 === $start ) {
			$start = 501;
		}

		$remaining = $start;
		foreach ( $visits as $visit ) {
			$remaining -= (int) ( $visit ?? 0 );
		}

		// Only the checkout visit can be shorter than three darts.
		$last  = isset( $leg['last'] ) ? (int) $leg['last'] : 3;
		$darts = ( count( $visits ) - 1 ) * 3 + ( 0 === $remaining ? $last : 3 );

		return [
			'points' => $start - $remaining,
			'darts'  => $darts,
			'legs'   => 1,
		];
	}

	/** @return array{points:int,darts:int,legs:int} */
	private static function new_totals(): array {
		return [
			'points' => 0,
			'darts'  => 0,
			'legs'   => 0,
		];
	}

	/** @return array{points:int,darts:int,legs:int} */
	private static function merge( array $a, array $b ): array {
		return [
			'points' => $a['points'] + $b['points'],
			'darts'  => $a['darts'] + $b['darts'],
			'legs'   => $a['legs'] + $b['legs'],
		];
	}

	private static function average( array $totals ): ?float {
		return $totals['darts'] > 0 ? $totals['points'] / $totals['darts'] * 3 : null;
	}

	/**
	 * "2025/26" from the season dates, or '' if they are missing.
	 *
	 * @param mixed $season
	 */
	private static function season_label( $season ): string {
		if ( ! is_array( $season ) || empty( $season['startDate'] ) || empty( $season['endDate'] ) ) {
			return '';
		}

		try {
			$start = new DateTimeImmutable( (string) $season['startDate'] );
			$end   = new DateTimeImmutable( (string) $season['endDate'] );
		} catch ( Exception $e ) {
			return '';
		}

		$from = $start->format( 'Y' );
		$to   = $end->format( 'Y' );

		return $from === $to ? $from : $from . '/' . $end->format( 'y' );
	}

	/**
	 * Runs a GraphQL request and returns its `data`.
	 *
	 * @param array<string,mixed> $variables
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function gql( string $query, array $variables ) {
		$response = wp_remote_post(
			self::API_ENDPOINT,
			[
				'timeout' => self::HTTP_TIMEOUT,
				'user-agent' => self::USER_AGENT,
				'headers' => [
					'Content-Type'             => 'application/json',
					'Accept'                   => 'application/json',
					'Apollo-Require-Preflight' => 'true',
				],
				'body'    => wp_json_encode(
					[
						'query'     => $query,
						'variables' => $variables,
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
				'gutz_dc_average_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The league API answered with HTTP %d.', 'gutz-dc-average' ),
					$status
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// GraphQL reports problems in a 200 response, so check before the data.
		if ( is_array( $body ) && ! empty( $body['errors'][0]['message'] ) ) {
			return new WP_Error(
				'gutz_dc_average_graphql_error',
				sprintf(
					/* translators: %s: error message returned by the league API */
					__( 'The league API rejected the request: %s', 'gutz-dc-average' ),
					(string) $body['errors'][0]['message']
				)
			);
		}

		if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return self::bad_payload();
		}

		return $body['data'];
	}

	private static function bad_payload(): WP_Error {
		return new WP_Error(
			'gutz_dc_average_bad_payload',
			__( 'The league API returned an unexpected response.', 'gutz-dc-average' )
		);
	}

	/**
	 * Folds a team name for comparison: lower case and single spaces, nothing
	 * else. The name is compared in full, because a club can field several
	 * teams in the same league — "GUTZ DC" and "GUTZ DC II" are different sides.
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

	/** Deletes every cached average and protocol this plugin created. */
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

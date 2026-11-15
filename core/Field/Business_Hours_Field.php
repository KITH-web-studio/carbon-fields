<?php

namespace Carbon_Fields\Field;

class Business_Hours_Field extends Structured_Field {
	private static $editor_requested = false;

	public static function editor_timezones() {
		static $zones;
		if ( ! self::$editor_requested ) { return array(); }
		if ( null === $zones ) { $zones = timezone_identifiers_list(); }
		return $zones;
	}

	public function to_json( $load ) {
		$data = parent::to_json( $load );
		self::$editor_requested = true;
		$data['default_timezone'] = function_exists( 'wp_timezone_string' ) && in_array( wp_timezone_string(), self::editor_timezones(), true ) ? wp_timezone_string() : 'UTC';
		return $data;
	}
	const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	public function validate_data( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( ! $value ) {
			return true;
		}
		if ( ! in_array( $value['timezone'] ?? '', timezone_identifiers_list(), true ) ||
			! is_array( $value['week'] ?? null ) || ! $this->valid_list( $value['exceptions'] ?? array(), 1000 ) ) {
			return false;
		}
		foreach ( $value['week'] as $day => $intervals ) {
			if ( ! in_array( $day, self::DAYS, true ) || ! $this->valid_intervals( $intervals ) ) {
				return false;
			}
		}
		$dates = array();
		foreach ( $value['exceptions'] ?? array() as $exception ) {
			if ( ! is_array( $exception ) ) { return false; }
			$date = $exception['date'] ?? null;
			if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts ) ||
				! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) || isset( $dates[ $date ] ) ||
				! $this->valid_intervals( $exception['intervals'] ?? null ) ) {
				return false;
			}
			$dates[ $date ] = true;
		}
		return true;
	}

	private function valid_intervals( $intervals ) {
		if ( ! $this->valid_list( $intervals, 48 ) ) {
			return false;
		}
		$occupied = array();
		foreach ( $intervals as $interval ) {
			if ( ! is_array( $interval ) ) { return false; }
			$open = $this->minutes( $interval['open'] ?? null );
			$close = $this->minutes( $interval['close'] ?? null );
			if ( null === $open || null === $close || ! is_bool( $interval['next_day'] ?? false ) ) {
				return false;
			}
			$close += ! empty( $interval['next_day'] ) ? 1440 : 0;
			if ( $close <= $open || $close - $open > 1440 ) {
				return false;
			}
			foreach ( $occupied as $other ) {
				if ( $open < $other[1] && $close > $other[0] ) {
					return false;
				}
			}
			$occupied[] = array( $open, $close );
		}
		return true;
	}

	private function minutes( $time ) {
		if ( ! is_string( $time ) || ! preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])$/D', $time, $match ) ) {
			return null;
		}
		return (int) $match[1] * 60 + (int) $match[2];
	}

	public function is_open_at( array $hours, \DateTimeInterface $instant ) {
		if ( ! $hours || ! $this->validate_data( $hours ) ) {
			return false;
		}
		$now = ( new \DateTimeImmutable( '@' . $instant->getTimestamp() ) )->setTimezone( new \DateTimeZone( $hours['timezone'] ) );
		foreach ( array( $now, $now->modify( '-1 day' ) ) as $date ) {
			$intervals = $hours['week'][ self::DAYS[ (int) $date->format( 'N' ) - 1 ] ] ?? array();
			foreach ( $hours['exceptions'] ?? array() as $exception ) {
				if ( $exception['date'] === $date->format( 'Y-m-d' ) ) {
					$intervals = $exception['intervals'];
					break;
				}
			}
			foreach ( $intervals as $interval ) {
				$start = new \DateTimeImmutable( $date->format( 'Y-m-d' ) . ' ' . $interval['open'], $now->getTimezone() );
				$end = new \DateTimeImmutable( $date->format( 'Y-m-d' ) . ' ' . $interval['close'], $now->getTimezone() );
				if ( ! empty( $interval['next_day'] ) ) {
					$end = $end->modify( '+1 day' );
				}
				if ( $now >= $start && $now < $end ) {
					return true;
				}
			}
		}
		return false;
	}
}

<?php
namespace ASS;

use DateTime;
use DateTimeImmutable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculate ASAP delivery dates with working day logic.
 */
class Date_Calculator {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Calculate ASAP date based on sending days and max ship days.
	 */
	public function calculate_asap_date( array $sending_days, int $max_ship_days, array $holiday_dates ): string {
		if ( empty( $sending_days ) ) {
			return '';
		}

		// 1. Get current WordPress time
		$now = current_datetime(); // DateTimeImmutable
		
		// 2. Find next sending day
		$next_sending_day = $this->get_next_sending_day( $now, $sending_days, $holiday_dates );
		
		// 3. Add max_ship_days counting only working days (Mon-Fri) excluding holidays
		$delivery_date = $this->add_working_days( $next_sending_day, $max_ship_days, $holiday_dates );
		
		return $delivery_date->format( 'Y-m-d' );
	}

	/**
	 * Find the next available sending day.
	 */
	private function get_next_sending_day( DateTimeImmutable $start, array $sending_days, array $holidays ): DateTime {
		$date = DateTime::createFromImmutable( $start );
		$date->setTime( 0, 0, 0 );

		// If today is a sending day, we must look for the NEXT occurrence.
		// Orders made on a sending day are sent on the NEXT sending day.
		$date->modify( '+1 day' );

		$max_iterations = 365; // Safety break
		while ( $max_iterations > 0 ) {
			$day_of_week = (int) $date->format( 'N' ); // 1 (Mon) to 7 (Sun)
			$date_str    = $date->format( 'Y-m-d' );

			if ( in_array( $day_of_week, $sending_days, true ) && ! $this->is_holiday( $date_str, $holidays ) ) {
				return $date;
			}

			$date->modify( '+1 day' );
			$max_iterations--;
		}

		return $date;
	}

	/**
	 * Add working days (Mon-Fri) excluding holidays.
	 */
	private function add_working_days( DateTime $start, int $days, array $holidays ): DateTime {
		$date = clone $start;
		$remaining_days = $days;

		while ( $remaining_days > 0 ) {
			$date->modify( '+1 day' );
			$day_of_week = (int) $date->format( 'N' );
			$date_str    = $date->format( 'Y-m-d' );

			// Working days are Mon-Fri (1-5)
			if ( $day_of_week <= 5 && ! $this->is_holiday( $date_str, $holidays ) ) {
				$remaining_days--;
			}
		}

		return $date;
	}

	/**
	 * Check if a date is a holiday.
	 */
	public function is_holiday( string $date_str, array $holidays ): bool {
		foreach ( $holidays as $holiday ) {
			if ( is_array( $holiday ) && isset( $holiday['date'] ) && $holiday['date'] === $date_str ) {
				return true;
			}
			if ( is_string( $holiday ) && $holiday === $date_str ) {
				return true;
			}
		}
		return false;
	}
}


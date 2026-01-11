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
		$rule = [
			'sending_days'  => $sending_days,
			'max_ship_days' => $max_ship_days,
			'priority_days' => [],
		];
		return $this->calculate_asap_date_with_priority( $rule, $holiday_dates, [] );
	}

	/**
	 * Calculate ASAP date considering priority sending days.
	 */
	public function calculate_asap_date_with_priority( array $rule, array $holiday_dates, array $cart_products_categories ): string {
		$sending_days  = $rule['sending_days'] ?? [];
		$max_ship_days = $rule['max_ship_days'] ?? 0;
		$priority_days = $rule['priority_days'] ?? [];

		if ( empty( $sending_days ) && empty( $priority_days ) ) {
			return '';
		}

		// 1. Calculate normal next sending day
		$now = current_datetime();
		$send_out_date = null;
		
		if ( ! empty( $sending_days ) ) {
			$send_out_date = $this->get_next_sending_day( $now, $sending_days, $holiday_dates );
		}

		// 2. Check for priority days matching cart categories
		$latest_priority_date = null;
		foreach ( $priority_days as $p_day ) {
			if ( empty( $p_day['date'] ) || empty( $p_day['categories'] ) ) {
				continue;
			}
			
			$match = false;
			foreach ( $cart_products_categories as $product_cats ) {
				if ( ! empty( array_intersect( (array) $product_cats, (array) $p_day['categories'] ) ) ) {
					$match = true;
					break;
				}
			}

			if ( $match ) {
				$p_date = new DateTime( $p_day['date'] );
				$p_date->setTime( 0, 0, 0 );
				
				// Only use if it's in the future relative to today
				$today = DateTime::createFromImmutable( $now );
				$today->setTime( 0, 0, 0 );
				
				if ( $p_date > $today ) {
					if ( null === $latest_priority_date || $p_date > $latest_priority_date ) {
						$latest_priority_date = $p_date;
					}
				}
			}
		}

		// 3. Compare and use latest
		if ( $latest_priority_date ) {
			if ( null === $send_out_date || $latest_priority_date > $send_out_date ) {
				$send_out_date = $latest_priority_date;
			}
		}

		if ( ! $send_out_date ) {
			return '';
		}

		// 4. Add max_ship_days
		$delivery_date = $this->add_working_days( $send_out_date, $max_ship_days, $holiday_dates );
		
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


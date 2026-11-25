<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bus_Stations {

	const OPTION          = 'ass_bus_arrival_times';
	const CARRIER         = 'siuntos_autobusais';
	const ENABLED_OPTION  = 'extra_siuntos_autobusais_pickup_cities_siuntos_autobusais';
	const META_IDENTIFIER = 'multiparcels_location_identifier';

	const INITIAL_TIMES = [
		'alytus'         => '13:00',
		'anyksciai'      => '16:15',
		'birzai'         => '16:25',
		'druskininkai'   => '16:00',
		'jonava'         => '12:35',
		'jurbarkas'      => '15:45',
		'kaunas'         => '12:15',
		'kedainiai'      => '15:20',
		'klaipeda'       => '15:00',
		'kupiskis'       => '18:00',
		'kursenai'       => '15:30',
		'lazdijai'       => '13:45',
		'marijampole'    => '14:00',
		'mazeikiai'      => '16:30',
		'moletai'        => '13:25',
		'naujoji akmene' => '19:20',
		'pakruojis'      => '19:15',
		'palanga'        => '18:15',
		'panevezys'      => '12:30',
		'pasvalys'       => '16:25',
		'prienai'        => '14:45',
		'radviliskis'    => '15:30',
		'raseiniai'      => '17:00',
		'rokiskis'       => '15:15',
		'sakiai'         => '15:35',
		'salcininkai'    => '14:00',
		'siauliai'       => '15:30',
		'silale'         => '18:00',
		'silute'         => '17:30',
		'svencionys'     => '16:50',
		'taurage'        => '16:45',
		'telsiai'        => '18:05',
		'trakai'         => '13:20',
		'ukmerge'        => '15:30',
		'utena'          => '12:30',
		'varena'         => '13:15',
		'vilkaviskis'    => '16:30',
		'visaginas'      => '14:45',
		'zarasai'        => '15:00',
	];

	private static $stations = null;

	public static function normalize( string $text ): string {
		$text = function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text;
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	public static function all_stations(): array {
		if ( null !== self::$stations ) {
			return self::$stations;
		}

		self::$stations = [];
		if ( ! function_exists( 'MultiParcels' ) ) {
			return self::$stations;
		}

		try {
			$rows = MultiParcels()->locations->all( self::CARRIER, 'LT' );
		} catch ( \Throwable $error ) {
			return self::$stations;
		}

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$row        = (array) $row;
			$identifier = trim( (string) ( $row['identifier'] ?? '' ) );
			if ( '' === $identifier ) {
				continue;
			}
			$name    = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $row['name'] ?? '' ) ) );
			$address = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $row['address'] ?? '' ) ) );
			self::$stations[ $identifier ] = [
				'label' => trim( $name . ' ' . $address ),
				'name'  => $name,
				'city'  => trim( (string) ( $row['city'] ?? '' ) ),
			];
		}

		uasort(
			self::$stations,
			static function ( array $a, array $b ): int {
				return strcmp( self::normalize( $a['label'] ), self::normalize( $b['label'] ) );
			}
		);

		return self::$stations;
	}

	private static function enabled_cities(): array {
		if ( ! function_exists( 'MultiParcels' ) ) {
			return [];
		}

		try {
			$cities = MultiParcels()->options->get_other( self::ENABLED_OPTION );
		} catch ( \Throwable $error ) {
			return [];
		}

		$enabled = [];
		foreach ( is_array( $cities ) ? $cities : [] as $key => $city ) {
			foreach ( [ $key, $city ] as $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$enabled[ self::normalize( (string) $value ) ] = true;
				}
			}
		}
		if ( isset( $enabled['druskininkai as'] ) ) {
			$enabled['druskininkai'] = true;
		}

		return $enabled;
	}

	public static function enabled_stations(): array {
		$stations = self::all_stations();
		$enabled  = self::enabled_cities();
		if ( ! $enabled ) {
			return $stations;
		}

		return array_filter(
			$stations,
			static function ( array $station ) use ( $enabled ): bool {
				return isset( $enabled[ self::normalize( $station['name'] ) ] ) || isset( $enabled[ self::normalize( $station['city'] ) ] );
			}
		);
	}

	public static function times(): array {
		$stored = get_option( self::OPTION, [] );
		$times  = [];
		foreach ( is_array( $stored ) ? $stored : [] as $identifier => $time ) {
			$time = Pickup_Helper::sanitize_time( $time );
			if ( '' !== $time ) {
				$times[ (string) $identifier ] = $time;
			}
		}
		return $times;
	}

	public static function save_times( array $identifiers, array $values ): void {
		$times   = self::times();
		$visible = self::enabled_stations();

		foreach ( array_values( $identifiers ) as $index => $identifier ) {
			$identifier = is_scalar( $identifier ) ? trim( (string) $identifier ) : '';
			if ( '' === $identifier || ! isset( $visible[ $identifier ] ) ) {
				continue;
			}
			$time = Pickup_Helper::sanitize_time( $values[ $index ] ?? '' );
			if ( '' === $time ) {
				unset( $times[ $identifier ] );
			} else {
				$times[ $identifier ] = $time;
			}
		}

		update_option( self::OPTION, $times, true );
	}

	public static function arrival_time( \WC_Order $order ): string {
		$identifier = trim( (string) $order->get_meta( self::META_IDENTIFIER ) );
		if ( '' === $identifier ) {
			return '';
		}
		return self::times()[ $identifier ] ?? '';
	}

	private static function initial_time( array $station ): string {
		foreach ( [ self::normalize( $station['name'] ), self::normalize( $station['city'] ) ] as $value ) {
			if ( '' === $value ) {
				continue;
			}
			foreach ( self::INITIAL_TIMES as $city => $time ) {
				if ( $value === $city || 0 === strpos( $value, $city . ' ' ) ) {
					return $time;
				}
			}
		}
		return '';
	}

	public static function fill_initial_times(): void {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}

		$stations = self::enabled_stations();
		if ( ! $stations ) {
			return;
		}

		$times = [];
		foreach ( $stations as $identifier => $station ) {
			$time = self::initial_time( $station );
			if ( '' !== $time ) {
				$times[ $identifier ] = $time;
			}
		}

		add_option( self::OPTION, $times, '', true );
	}
}

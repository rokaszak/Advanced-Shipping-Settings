<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Qr_Encoder {

	const MAX_VERSION     = 10;
	const ECC_PER_BLOCK   = [ 1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24, 6 => 16, 7 => 18, 8 => 22, 9 => 22, 10 => 26 ];
	const BLOCKS          = [ 1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => 4, 7 => 4, 8 => 4, 9 => 5, 10 => 5 ];
	const TOTAL_CODEWORDS = [ 1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134, 6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346 ];
	const ALIGNMENT       = [
		1  => [],
		2  => [ 6, 18 ],
		3  => [ 6, 22 ],
		4  => [ 6, 26 ],
		5  => [ 6, 30 ],
		6  => [ 6, 34 ],
		7  => [ 6, 22, 38 ],
		8  => [ 6, 24, 42 ],
		9  => [ 6, 26, 46 ],
		10 => [ 6, 28, 50 ],
	];

	private $size      = 0;
	private $modules   = [];
	private $functions = [];

	public static function matrix( string $data ): array {
		$length  = strlen( $data );
		$version = 0;
		$capacity = 0;
		$count_bits = 8;

		for ( $candidate = 1; $candidate <= self::MAX_VERSION; $candidate++ ) {
			$capacity   = self::TOTAL_CODEWORDS[ $candidate ] - self::ECC_PER_BLOCK[ $candidate ] * self::BLOCKS[ $candidate ];
			$count_bits = $candidate < 10 ? 8 : 16;
			if ( 4 + $count_bits + 8 * $length <= $capacity * 8 ) {
				$version = $candidate;
				break;
			}
		}

		if ( 0 === $version || 0 === $length ) {
			return [];
		}

		$bits = [];
		self::append_bits( $bits, 4, 4 );
		self::append_bits( $bits, $length, $count_bits );
		for ( $i = 0; $i < $length; $i++ ) {
			self::append_bits( $bits, ord( $data[ $i ] ), 8 );
		}

		$capacity_bits = $capacity * 8;
		self::append_bits( $bits, 0, min( 4, $capacity_bits - count( $bits ) ) );
		self::append_bits( $bits, 0, ( 8 - count( $bits ) % 8 ) % 8 );
		for ( $pad = 0xEC; count( $bits ) < $capacity_bits; $pad ^= 0xEC ^ 0x11 ) {
			self::append_bits( $bits, $pad, 8 );
		}

		$codewords = [];
		foreach ( array_chunk( $bits, 8 ) as $chunk ) {
			$byte = 0;
			foreach ( $chunk as $bit ) {
				$byte = ( $byte << 1 ) | $bit;
			}
			$codewords[] = $byte;
		}

		$encoder = new self();
		return $encoder->build( $version, self::add_ecc_and_interleave( $codewords, $version ) );
	}

	public static function png( string $data, int $scale = 8, int $border = 4 ): string {
		if ( ! function_exists( 'gzcompress' ) ) {
			return '';
		}

		$matrix = self::matrix( $data );
		if ( empty( $matrix ) ) {
			return '';
		}

		$scale  = max( 1, $scale );
		$border = max( 0, $border );
		$count  = count( $matrix );
		$pixels = ( $count + 2 * $border ) * $scale;

		$white_bits = str_repeat( '1', $pixels );
		$white_line = "\0" . self::pack_bits( $white_bits );
		$raw        = str_repeat( $white_line, $border * $scale );

		foreach ( $matrix as $row ) {
			$row_bits = str_repeat( '1', $border * $scale );
			foreach ( $row as $dark ) {
				$row_bits .= str_repeat( $dark ? '0' : '1', $scale );
			}
			$row_bits .= str_repeat( '1', $border * $scale );
			$raw      .= str_repeat( "\0" . self::pack_bits( $row_bits ), $scale );
		}

		$raw .= str_repeat( $white_line, $border * $scale );

		$compressed = gzcompress( $raw, 9 );
		if ( false === $compressed ) {
			return '';
		}

		return "\x89PNG\r\n\x1a\n"
			. self::png_chunk( 'IHDR', pack( 'NNCCCCC', $pixels, $pixels, 1, 0, 0, 0, 0 ) )
			. self::png_chunk( 'IDAT', $compressed )
			. self::png_chunk( 'IEND', '' );
	}

	public static function svg( string $data, int $display_size = 180, int $border = 4 ): string {
		$matrix = self::matrix( $data );
		if ( empty( $matrix ) ) {
			return '';
		}

		$count = count( $matrix );
		$total = $count + 2 * $border;
		$path  = '';

		foreach ( $matrix as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( $dark ) {
					$path .= 'M' . ( $x + $border ) . ' ' . ( $y + $border ) . 'h1v1h-1z';
				}
			}
		}

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" width="' . (int) $display_size . '" height="' . (int) $display_size . '" shape-rendering="crispEdges" role="img">'
			. '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>'
			. '<path d="' . $path . '" fill="#000000"/>'
			. '</svg>';
	}

	private static function pack_bits( string $bits ): string {
		$remainder = strlen( $bits ) % 8;
		if ( 0 !== $remainder ) {
			$bits .= str_repeat( '0', 8 - $remainder );
		}

		$packed = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			$packed .= chr( bindec( $byte ) );
		}
		return $packed;
	}

	private static function png_chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	private static function append_bits( array &$bits, int $value, int $length ): void {
		for ( $i = $length - 1; $i >= 0; $i-- ) {
			$bits[] = ( $value >> $i ) & 1;
		}
	}

	private static function add_ecc_and_interleave( array $codewords, int $version ): array {
		$num_blocks = self::BLOCKS[ $version ];
		$ecc_length = self::ECC_PER_BLOCK[ $version ];
		$raw_count  = self::TOTAL_CODEWORDS[ $version ];
		$num_short  = $num_blocks - $raw_count % $num_blocks;
		$short_len  = intdiv( $raw_count, $num_blocks );
		$divisor    = self::rs_divisor( $ecc_length );
		$blocks     = [];
		$offset     = 0;

		for ( $i = 0; $i < $num_blocks; $i++ ) {
			$data_length = $short_len - $ecc_length + ( $i < $num_short ? 0 : 1 );
			$block       = array_slice( $codewords, $offset, $data_length );
			$offset     += $data_length;
			$ecc         = self::rs_remainder( $block, $divisor );
			if ( $i < $num_short ) {
				$block[] = 0;
			}
			$blocks[] = array_merge( $block, $ecc );
		}

		$result       = [];
		$block_length = count( $blocks[0] );
		for ( $i = 0; $i < $block_length; $i++ ) {
			for ( $j = 0; $j < $num_blocks; $j++ ) {
				if ( $i !== $short_len - $ecc_length || $j >= $num_short ) {
					$result[] = $blocks[ $j ][ $i ];
				}
			}
		}

		return $result;
	}

	private static function rs_divisor( int $degree ): array {
		$result                 = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ]  = 1;
		$root                   = 1;

		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::gf_multiply( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::gf_multiply( $root, 0x02 );
		}

		return $result;
	}

	private static function rs_remainder( array $data, array $divisor ): array {
		$result = array_fill( 0, count( $divisor ), 0 );

		foreach ( $data as $byte ) {
			$factor   = $byte ^ array_shift( $result );
			$result[] = 0;
			foreach ( $divisor as $i => $coefficient ) {
				$result[ $i ] ^= self::gf_multiply( $coefficient, $factor );
			}
		}

		return $result;
	}

	private static function gf_multiply( int $x, int $y ): int {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D ) ) & 0xFFFF;
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z & 0xFF;
	}

	private function build( int $version, array $codewords ): array {
		$this->size      = 17 + 4 * $version;
		$this->modules   = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
		$this->functions = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );

		$this->draw_function_patterns( $version );
		$this->draw_codewords( $codewords );

		$best_mask  = 0;
		$best_score = PHP_INT_MAX;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$this->apply_mask( $mask );
			$this->draw_format_bits( $mask );
			$score = $this->penalty_score();
			if ( $score < $best_score ) {
				$best_score = $score;
				$best_mask  = $mask;
			}
			$this->apply_mask( $mask );
		}

		$this->apply_mask( $best_mask );
		$this->draw_format_bits( $best_mask );

		return $this->modules;
	}

	private function set_function( int $x, int $y, bool $dark ): void {
		$this->modules[ $y ][ $x ]   = $dark;
		$this->functions[ $y ][ $x ] = true;
	}

	private function draw_function_patterns( int $version ): void {
		for ( $i = 0; $i < $this->size; $i++ ) {
			$this->set_function( 6, $i, 0 === $i % 2 );
			$this->set_function( $i, 6, 0 === $i % 2 );
		}

		$this->draw_finder( 3, 3 );
		$this->draw_finder( $this->size - 4, 3 );
		$this->draw_finder( 3, $this->size - 4 );

		$positions = self::ALIGNMENT[ $version ];
		$count     = count( $positions );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = 0; $j < $count; $j++ ) {
				$is_corner = ( 0 === $i && 0 === $j ) || ( 0 === $i && $count - 1 === $j ) || ( $count - 1 === $i && 0 === $j );
				if ( ! $is_corner ) {
					$this->draw_alignment( $positions[ $i ], $positions[ $j ] );
				}
			}
		}

		$this->draw_format_bits( 0 );
		$this->draw_version( $version );
	}

	private function draw_finder( int $x, int $y ): void {
		for ( $dy = -4; $dy <= 4; $dy++ ) {
			for ( $dx = -4; $dx <= 4; $dx++ ) {
				$distance = max( abs( $dx ), abs( $dy ) );
				$xx       = $x + $dx;
				$yy       = $y + $dy;
				if ( $xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size ) {
					$this->set_function( $xx, $yy, 2 !== $distance && 4 !== $distance );
				}
			}
		}
	}

	private function draw_alignment( int $x, int $y ): void {
		for ( $dy = -2; $dy <= 2; $dy++ ) {
			for ( $dx = -2; $dx <= 2; $dx++ ) {
				$this->set_function( $x + $dx, $y + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
			}
		}
	}

	private function draw_format_bits( int $mask ): void {
		$data      = $mask;
		$remainder = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$remainder = ( $remainder << 1 ) ^ ( ( $remainder >> 9 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $remainder ) ^ 0x5412;

		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_function( 8, $i, self::bit( $bits, $i ) );
		}
		$this->set_function( 8, 7, self::bit( $bits, 6 ) );
		$this->set_function( 8, 8, self::bit( $bits, 7 ) );
		$this->set_function( 7, 8, self::bit( $bits, 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set_function( 14 - $i, 8, self::bit( $bits, $i ) );
		}

		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_function( $this->size - 1 - $i, 8, self::bit( $bits, $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set_function( 8, $this->size - 15 + $i, self::bit( $bits, $i ) );
		}
		$this->set_function( 8, $this->size - 8, true );
	}

	private function draw_version( int $version ): void {
		if ( $version < 7 ) {
			return;
		}

		$remainder = $version;
		for ( $i = 0; $i < 12; $i++ ) {
			$remainder = ( $remainder << 1 ) ^ ( ( $remainder >> 11 ) * 0x1F25 );
		}
		$bits = ( $version << 12 ) | $remainder;

		for ( $i = 0; $i < 18; $i++ ) {
			$dark = self::bit( $bits, $i );
			$a    = $this->size - 11 + $i % 3;
			$b    = intdiv( $i, 3 );
			$this->set_function( $a, $b, $dark );
			$this->set_function( $b, $a, $dark );
		}
	}

	private static function bit( int $value, int $index ): bool {
		return 0 !== ( ( $value >> $index ) & 1 );
	}

	private function draw_codewords( array $codewords ): void {
		$total_bits = count( $codewords ) * 8;
		$index      = 0;

		for ( $right = $this->size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vertical = 0; $vertical < $this->size; $vertical++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = 0 === ( ( $right + 1 ) & 2 );
					$y      = $upward ? $this->size - 1 - $vertical : $vertical;
					if ( ! $this->functions[ $y ][ $x ] && $index < $total_bits ) {
						$this->modules[ $y ][ $x ] = self::bit( $codewords[ $index >> 3 ], 7 - ( $index & 7 ) );
						$index++;
					}
				}
			}
		}
	}

	private function apply_mask( int $mask ): void {
		for ( $y = 0; $y < $this->size; $y++ ) {
			for ( $x = 0; $x < $this->size; $x++ ) {
				if ( $this->functions[ $y ][ $x ] ) {
					continue;
				}
				switch ( $mask ) {
					case 0:
						$invert = 0 === ( $x + $y ) % 2;
						break;
					case 1:
						$invert = 0 === $y % 2;
						break;
					case 2:
						$invert = 0 === $x % 3;
						break;
					case 3:
						$invert = 0 === ( $x + $y ) % 3;
						break;
					case 4:
						$invert = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
						break;
					case 5:
						$invert = 0 === ( $x * $y ) % 2 + ( $x * $y ) % 3;
						break;
					case 6:
						$invert = 0 === ( ( $x * $y ) % 2 + ( $x * $y ) % 3 ) % 2;
						break;
					default:
						$invert = 0 === ( ( $x + $y ) % 2 + ( $x * $y ) % 3 ) % 2;
						break;
				}
				if ( $invert ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	private function penalty_score(): int {
		$score = 0;
		$size  = $this->size;
		$dark  = 0;

		$lines = [];
		for ( $y = 0; $y < $size; $y++ ) {
			$row    = '';
			$column = '';
			for ( $x = 0; $x < $size; $x++ ) {
				$row    .= $this->modules[ $y ][ $x ] ? '1' : '0';
				$column .= $this->modules[ $x ][ $y ] ? '1' : '0';
			}
			$lines[] = $row;
			$lines[] = $column;
			$dark   += substr_count( $row, '1' );
		}

		foreach ( $lines as $line ) {
			if ( preg_match_all( '/0{5,}|1{5,}/', $line, $matches ) ) {
				foreach ( $matches[0] as $run ) {
					$score += strlen( $run ) - 2;
				}
			}
			$score += 40 * ( self::count_overlapping( $line, '10111010000' ) + self::count_overlapping( $line, '00001011101' ) );
		}

		for ( $y = 0; $y < $size - 1; $y++ ) {
			for ( $x = 0; $x < $size - 1; $x++ ) {
				$color = $this->modules[ $y ][ $x ];
				if ( $color === $this->modules[ $y ][ $x + 1 ] && $color === $this->modules[ $y + 1 ][ $x ] && $color === $this->modules[ $y + 1 ][ $x + 1 ] ) {
					$score += 3;
				}
			}
		}

		$total  = $size * $size;
		$score += 10 * (int) max( 0, ceil( abs( $dark * 20 - $total * 10 ) / $total ) - 1 );

		return $score;
	}

	private static function count_overlapping( string $haystack, string $needle ): int {
		$count  = 0;
		$offset = 0;
		while ( false !== ( $position = strpos( $haystack, $needle, $offset ) ) ) {
			$count++;
			$offset = $position + 1;
		}
		return $count;
	}
}

<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ASS_PATH . 'admin/class-pickup-pdf.php';

class Pickup_Products_Pdf extends Pickup_Pdf {

	const QTY_W  = 22.0;
	const SKU_W  = 38.0;
	const ROW_P  = 1.1;
	const HEAD_Y = 4.8;

	private $has_sku  = false;
	private $page_top = 0.0;

	private static function bucket(): array {
		return [
			'orders'   => 0,
			'units'    => 0.0,
			'products' => [],
		];
	}

	private static function sku( int $product_id, int $variation_id ): string {
		static $cache = [];

		$key = $product_id . ':' . $variation_id;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$sku = '';
		foreach ( [ $variation_id, $product_id ] as $id ) {
			if ( $id > 0 ) {
				$sku = trim( (string) get_post_meta( $id, '_sku', true ) );
				if ( '' !== $sku ) {
					break;
				}
			}
		}
		$cache[ $key ] = $sku;

		return $sku;
	}

	private static function add( array &$bucket, array $row ): void {
		$bucket['orders']++;

		foreach ( (array) ( $row['items'] ?? [] ) as $item ) {
			$name       = self::clean( $item['name'] ?? '' );
			$product_id = (int) ( $item['product_id'] ?? 0 );
			$variation  = (int) ( $item['variation_id'] ?? 0 );
			$quantity   = (float) ( $item['qty'] ?? 0 );
			$key        = $product_id > 0 ? $product_id . ':' . $variation : 'name:' . mb_strtolower( $name, 'UTF-8' );

			if ( ! isset( $bucket['products'][ $key ] ) ) {
				$bucket['products'][ $key ] = [
					'name' => $name,
					'sku'  => $product_id > 0 ? self::sku( $product_id, $variation ) : '',
					'qty'  => 0.0,
				];
			}
			$bucket['products'][ $key ]['name'] = '' !== $name ? $name : $bucket['products'][ $key ]['name'];
			$bucket['products'][ $key ]['qty'] += $quantity;
			$bucket['units']                   += $quantity;
		}
	}

	private static function sorted( array $products ): array {
		$products = array_values( $products );
		$collator = class_exists( '\Collator' ) ? new \Collator( 'lt_LT' ) : null;
		if ( $collator ) {
			$collator->setAttribute( \Collator::NUMERIC_COLLATION, \Collator::ON );
		}

		usort(
			$products,
			static function ( array $a, array $b ) use ( $collator ): int {
				$result = $collator ? $collator->compare( $a['name'], $b['name'] ) : strnatcasecmp( $a['name'], $b['name'] );
				return false === $result ? strnatcasecmp( $a['name'], $b['name'] ) : (int) $result;
			}
		);

		return $products;
	}

	private static function number( float $value ): string {
		return floor( $value ) === $value ? (string) (int) $value : rtrim( rtrim( number_format( $value, 2, ',', '' ), '0' ), ',' );
	}

	private static function date_title( string $date ): string {
		if ( '' === $date ) {
			return 'Be rezervacijos datos';
		}
		$day = Pickup_Helper::day_name( $date );
		return '' !== $day ? $date . ' · ' . $day : $date;
	}

	private static function method_title( array $method ): string {
		$title = '' !== $method['label'] ? $method['label'] : 'Be pristatymo būdo';
		if ( 1 === count( $method['times'] ) && '' !== (string) key( $method['times'] ) ) {
			$title .= ' · ' . key( $method['times'] );
		}
		return $title;
	}

	private static function sections( array $rows ): array {
		$ids = [];
		foreach ( $rows as $row ) {
			foreach ( (array) ( $row['items'] ?? [] ) as $item ) {
				foreach ( [ 'product_id', 'variation_id' ] as $field ) {
					if ( ! empty( $item[ $field ] ) ) {
						$ids[ (int) $item[ $field ] ] = true;
					}
				}
			}
		}
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_keys( $ids ), false, true );
		}

		$total = self::bucket();
		$dates = [];

		foreach ( $rows as $row ) {
			self::add( $total, $row );

			$date_key = '' === $row['date'] ? '9999-99-99' : (string) $row['date'];
			if ( ! isset( $dates[ $date_key ] ) ) {
				$dates[ $date_key ] = [
					'date'    => (string) $row['date'],
					'bucket'  => self::bucket(),
					'methods' => [],
				];
			}
			self::add( $dates[ $date_key ]['bucket'], $row );

			$method_key = (string) $row['method_id'];
			if ( ! isset( $dates[ $date_key ]['methods'][ $method_key ] ) ) {
				$dates[ $date_key ]['methods'][ $method_key ] = [
					'label'  => (string) $row['method_label'],
					'bucket' => self::bucket(),
					'times'  => [],
				];
			}
			self::add( $dates[ $date_key ]['methods'][ $method_key ]['bucket'], $row );
			$dates[ $date_key ]['methods'][ $method_key ]['times'][ Pickup_Helper::get_pickup_time_display( $row['order'] ) ] = true;
		}
		ksort( $dates );

		$single = 1 === count( $dates );
		$note   = '';
		if ( $single ) {
			$only = reset( $dates );
			$note = self::date_title( $only['date'] );
			if ( 1 === count( $only['methods'] ) ) {
				$note .= ' — ' . self::method_title( reset( $only['methods'] ) );
			}
		}

		$sections = [
			[
				'level'  => 0,
				'title'  => 'Iš viso',
				'note'   => $note,
				'bucket' => $total,
			],
		];

		foreach ( $dates as $entry ) {
			$many = count( $entry['methods'] ) > 1;

			if ( ! $single ) {
				$sections[] = [
					'level'  => 1,
					'title'  => self::date_title( $entry['date'] ) . ( $many ? '' : ' — ' . self::method_title( reset( $entry['methods'] ) ) ),
					'note'   => '',
					'bucket' => $entry['bucket'],
				];
			}

			if ( ! $many ) {
				continue;
			}

			uasort(
				$entry['methods'],
				static function ( array $a, array $b ): int {
					return strnatcasecmp( $a['label'], $b['label'] );
				}
			);
			foreach ( $entry['methods'] as $method ) {
				$sections[] = [
					'level'  => $single ? 1 : 2,
					'title'  => self::method_title( $method ),
					'parent' => $single ? '' : self::date_title( $entry['date'] ),
					'note'   => '',
					'bucket' => $method['bucket'],
				];
			}
		}

		return $sections;
	}

	public static function render( array $rows, array $filters, int $shown = 0, ?callable $progress = null ): string {
		$meta     = self::base_meta( 'Produkcijos suvestinė', $rows, $filters, $shown );
		$sections = self::sections( $rows );

		try {
			$pdf = new self( $meta, 'P' );
			foreach ( $sections[0]['bucket']['products'] as $product ) {
				if ( '' !== $product['sku'] ) {
					$pdf->has_sku = true;
					break;
				}
			}
			$pdf->AddPage();
			foreach ( $sections as $section ) {
				$pdf->add_section( $section );
			}
			if ( null !== $progress ) {
				$progress( count( $rows ), count( $rows ) );
			}
			return (string) $pdf->Output( 'S' );
		} finally {
			if ( ! empty( $meta['logo']['file'] ) ) {
				wp_delete_file( $meta['logo']['file'] );
			}
		}
	}

	protected function table_head( float $y ): void {
		$this->page_top = $y;
		$this->SetY( $y );
	}

	private function right( float $y, string $text, string $style, float $size ): void {
		$this->SetFont( 'DejaVu', $style, $size );
		$this->put( $this->page_w - self::MARGIN - self::PAD - $this->GetStringWidth( $text ), $y, $text, $style, $size );
	}

	private function column_head( float $indent ): void {
		$y     = $this->GetY();
		$right = $this->page_w - self::MARGIN;

		$this->put( self::MARGIN + $indent + self::PAD, $y, 'Prekė', 'B', 7.5 );
		if ( $this->has_sku ) {
			$this->put( $right - self::QTY_W - self::SKU_W + self::PAD, $y, 'SKU', 'B', 7.5 );
		}
		$this->right( $y, 'Kiekis', 'B', 7.5 );

		$this->SetLineWidth( 0.25 );
		$this->Line( self::MARGIN + $indent, $y + self::HEAD_Y, $right, $y + self::HEAD_Y );
		$this->SetY( $y + self::HEAD_Y );
	}

	private function add_section( array $section ): void {
		$level    = (int) $section['level'];
		$bucket   = $section['bucket'];
		$products = self::sorted( $bucket['products'] );
		$indent   = 2 === $level ? 6.0 : 0.0;
		$right    = $this->page_w - self::MARGIN;
		$title    = self::clean( $section['title'] );
		$parent   = self::clean( $section['parent'] ?? '' );
		$counts   = 'Užsakymų: ' . $bucket['orders'] . ' · Vienetų: ' . self::number( (float) $bucket['units'] );
		$gap      = [ 0.0, 7.0, 4.5 ][ $level ];
		$size     = [ 13.0, 11.0, 9.5 ][ $level ];
		$band     = [ 9.5, 8.0, 6.5 ][ $level ];

		if ( 0 === $level ) {
			$counts .= ' · Skirtingų prekių: ' . count( $products );
		}

		if ( $this->GetY() + $gap + $band + self::HEAD_Y + 14 > $this->bottom ) {
			$this->AddPage();
			$gap = 0.0;
		}

		if ( '' !== $parent && abs( $this->GetY() - $this->page_top ) < 0.01 ) {
			$this->put( self::MARGIN + self::PAD, $this->page_top, $parent . ' (tęsinys)', 'B', 9 );
			$this->SetY( $this->page_top + 5.5 );
			$gap = 0.0;
		}

		$y = $this->GetY() + $gap;

		if ( $level < 2 ) {
			$this->SetLineWidth( 0 === $level ? 0.7 : 0.45 );
			$this->Line( self::MARGIN, $y, $right, $y );
		}

		$available = $right - self::MARGIN - $indent - 2 * self::PAD;
		$this->SetFont( 'DejaVu', '', 9 );
		$counts_width = $this->GetStringWidth( $counts );
		$this->SetFont( 'DejaVu', 'B', $size );

		if ( $this->GetStringWidth( $title ) + $counts_width + 6 <= $available ) {
			$this->put( self::MARGIN + $indent + self::PAD, $y + ( $band - $this->line_height( $size ) ) / 2, $title, 'B', $size );
			$this->right( $y + ( $band - $this->line_height( 9 ) ) / 2, $counts, '', 9 );
			$y += $band;
		} else {
			$y += 1.2;
			foreach ( $this->wrap( $title, $available ) as $text ) {
				$y += $this->put( self::MARGIN + $indent + self::PAD, $y, $text, 'B', $size );
			}
			$y += $this->put( self::MARGIN + $indent + self::PAD, $y, $counts, '', 9 ) + 1.2;
		}

		if ( '' !== (string) $section['note'] ) {
			$y += $this->put( self::MARGIN + self::PAD, $y - 1.5, self::clean( $section['note'] ), '', 9 ) + 0.5;
		}

		$this->SetLineWidth( 0.25 );
		$this->Line( self::MARGIN + $indent, $y, $right, $y );
		$this->SetY( $y + 0.6 );
		$this->column_head( $indent );

		if ( ! $products ) {
			$products[] = [
				'name' => '–',
				'sku'  => '',
				'qty'  => 0.0,
			];
		}

		$name_width = $right - self::MARGIN - $indent - self::QTY_W - ( $this->has_sku ? self::SKU_W : 0.0 ) - 2 * self::PAD;

		foreach ( $products as $product ) {
			$this->SetFont( 'DejaVu', '', 9 );
			$lines = $this->wrap( (string) $product['name'], $name_width );
			$lines = $lines ? $lines : [ '' ];

			$sku_lines = [];
			if ( $this->has_sku && '' !== $product['sku'] ) {
				$this->SetFont( 'DejaVu', '', 8 );
				$sku_lines = $this->wrap( self::clean( $product['sku'] ), self::SKU_W - 2 * self::PAD );
			}

			$height = max( count( $lines ) * $this->line_height( 9 ), count( $sku_lines ) * $this->line_height( 8 ), $this->line_height( 10 ) ) + 2 * self::ROW_P;

			if ( $this->GetY() + $height > $this->bottom ) {
				$this->AddPage();
				$top = $this->GetY();
				$this->put( self::MARGIN + $indent + self::PAD, $top, ( '' !== $parent ? $parent . ' — ' : '' ) . $title . ' (tęsinys)', 'B', 9 );
				$this->SetY( $top + 5.5 );
				$this->column_head( $indent );
			}

			$top  = $this->GetY();
			$line = $top + self::ROW_P;
			foreach ( $lines as $text ) {
				$line += $this->put( self::MARGIN + $indent + self::PAD, $line, $text, '', 9 );
			}

			$line = $top + self::ROW_P;
			foreach ( $sku_lines as $text ) {
				$line += $this->put( $right - self::QTY_W - self::SKU_W + self::PAD, $line, $text, '', 8 );
			}

			if ( '–' !== $product['name'] ) {
				$this->right( $top + self::ROW_P, self::number( (float) $product['qty'] ), 'B', 10 );
			}

			$this->SetLineWidth( 0.1 );
			$this->Line( self::MARGIN + $indent, $top + $height, $right, $top + $height );
			$this->SetY( $top + $height );
		}
	}
}

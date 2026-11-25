<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ASS_PATH . 'lib/tfpdf/font/unifont/ttfonts.php';
require_once ASS_PATH . 'lib/tfpdf/tfpdf.php';

class Pickup_Pdf extends \ASS\Lib\tFPDF {

	const MARGIN    = 10.0;
	const PAD       = 1.6;
	const FRESH_TOP = 23.0;
	const HEAD_H    = 7.0;
	const BOX       = 5.5;

	protected $meta    = [];
	protected $columns = [];
	protected $page_w  = 297.0;
	protected $bottom  = 197.0;

	public static function columns(): array {
		return [
			'tick'      => [ 'Atiduota', 15.0 ],
			'order'     => [ 'Užsakymas', 25.0 ],
			'code'      => [ 'Kodas', 19.0 ],
			'pickup'    => [ 'Rezervacija', 34.0 ],
			'shipping'  => [ 'Pristatymas', 42.0 ],
			'buyer'     => [ 'Pirkėjas', 46.0 ],
			'recipient' => [ 'Gavėjas', 42.0 ],
			'items'     => [ 'Prekės', 54.0 ],
		];
	}

	public static function clean( $text ): string {
		$text = is_scalar( $text ) ? (string) $text : '';
		$text = wp_check_invalid_utf8( $text, true );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[^\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	public static function view_label( string $view ): string {
		if ( 'ready' === $view ) {
			return 'Paruošti atsiimti';
		}
		return 'all' === $view ? 'Visi užsakymai' : 'Ruošiami užsakymai';
	}

	public static function filter_lines( array $filters, array $rows ): array {
		$lines = [ [ 'Būsena:', self::view_label( (string) $filters['view'] ) ] ];

		if ( '' !== $filters['date'] ) {
			if ( Pickup_Orders_Page::NO_DATE === $filters['date'] ) {
				$lines[] = [ 'Rezervacijos data:', 'be datos' ];
			} else {
				$day     = Pickup_Helper::day_name( $filters['date'] );
				$lines[] = [ 'Rezervacijos data:', '' !== $day ? $filters['date'] . ' · ' . $day : $filters['date'] ];

				$times = [];
				foreach ( $rows as $row ) {
					$times[ Pickup_Helper::get_pickup_time_display( $row['order'] ) ] = true;
				}
				if ( 1 === count( $times ) && '' !== (string) key( $times ) ) {
					$lines[] = [ 'Atsiėmimo laikas:', (string) key( $times ) ];
				}
			}
		}

		$method = (string) $filters['method'];
		if ( '' === $method && Pickup_Orders_Page::GROUP_OWN !== ( $filters['group'] ?? Pickup_Orders_Page::GROUP_OWN ) ) {
			$method = (string) $filters['group'];
		}

		if ( '' !== $method ) {
			$label = Pickup_Helper::method_display_name( $method, '' );
			foreach ( $rows as $row ) {
				if ( '' !== $label ) {
					break;
				}
				if ( $row['method_id'] === $method ) {
					$label = (string) $row['method_label'];
				}
			}
			$lines[] = [ 'Pristatymo būdas:', '' !== $label ? $label : $method ];
		}

		if ( ! empty( $filters['points'] ) ) {
			$lines[] = [ 'Atsiėmimo vietos:', implode( ', ', $filters['points'] ) ];
		}

		if ( $filters['cats'] ) {
			$lines[] = [ 'Užsakyme turi būti:', self::category_names( $filters['cats'] ) ];
		}

		if ( ! empty( $filters['xcats'] ) ) {
			$lines[] = [ 'Užsakyme neturi būti:', self::category_names( $filters['xcats'] ) ];
		}

		return $lines;
	}

	private static function category_names( array $term_ids ): string {
		$names = [];
		foreach ( $term_ids as $term_id ) {
			$term    = get_term( (int) $term_id, 'product_cat' );
			$names[] = $term && ! is_wp_error( $term ) ? (string) $term->name : '#' . $term_id;
		}
		return implode( ', ', $names );
	}

	private static function url_to_path( string $url ): string {
		$uploads = wp_get_upload_dir();
		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base    = trailingslashit( (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH ) );

		if ( '' === $path || '/' === $base || false !== strpos( $path, '..' ) || 0 !== strpos( $path, $base ) ) {
			return '';
		}
		return trailingslashit( (string) $uploads['basedir'] ) . substr( $path, strlen( $base ) );
	}

	private static function logo_source(): string {
		$candidates = [];

		$custom = absint( get_theme_mod( 'custom_logo' ) );
		if ( $custom ) {
			$candidates[] = (string) get_attached_file( $custom );
		}
		$header = (string) get_option( 'woocommerce_email_header_image', '' );
		if ( '' !== $header ) {
			$candidates[] = self::url_to_path( $header );
		}
		$icon = absint( get_option( 'site_icon' ) );
		if ( $icon ) {
			$candidates[] = (string) get_attached_file( $icon );
		}

		$file = '';
		foreach ( $candidates as $candidate ) {
			if ( '' !== $candidate && is_readable( $candidate ) && filesize( $candidate ) <= 8 * MB_IN_BYTES ) {
				$file = $candidate;
				break;
			}
		}

		return (string) apply_filters( 'ass_pickup_pdf_logo_file', $file );
	}

	private static function prepare_logo(): array {
		$file = self::logo_source();
		if ( '' === $file || ! is_readable( $file ) || ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagepng' ) ) {
			return [];
		}

		$data   = file_get_contents( $file );
		$source = false !== $data && '' !== $data ? @imagecreatefromstring( $data ) : false;
		if ( ! $source ) {
			return [];
		}

		$width  = imagesx( $source );
		$height = imagesy( $source );
		if ( $width < 1 || $height < 1 ) {
			return [];
		}

		$scale  = min( 1, 600 / max( $width, $height ) );
		$new_w  = max( 1, (int) round( $width * $scale ) );
		$new_h  = max( 1, (int) round( $height * $scale ) );
		$canvas = imagecreatetruecolor( $new_w, $new_h );

		imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
		imagealphablending( $canvas, true );
		imagecopyresampled( $canvas, $source, 0, 0, 0, 0, $new_w, $new_h, $width, $height );
		imagefilter( $canvas, IMG_FILTER_GRAYSCALE );

		$temp = wp_tempnam( 'ass-pickup-logo' );
		if ( ! $temp || ! imagepng( $canvas, $temp ) ) {
			return [];
		}

		return [
			'file'  => $temp,
			'ratio' => $new_w / $new_h,
		];
	}

	protected static function base_meta( string $title, array $rows, array $filters, int $shown ): array {
		$exported = wp_date( 'Y-m-d H:i' );
		$count    = count( $rows );
		$summary  = 'Eksportuota: ' . $exported . ' · Užsakymų: ' . $count;
		if ( $count < $shown ) {
			$summary .= ' iš ' . $shown . ' pagal filtrus';
		}

		return [
			'title'    => $title,
			'exported' => $exported,
			'summary'  => $summary,
			'filters'  => self::filter_lines( $filters, $rows ),
			'site'     => trim( self::clean( get_bloginfo( 'name' ) ) . ' · ' . (string) wp_parse_url( home_url(), PHP_URL_HOST ), ' ·' ),
			'logo'     => self::prepare_logo(),
		];
	}

	public static function render( array $rows, array $filters, int $shown = 0, ?callable $progress = null ): string {
		$meta  = self::base_meta( 'Užsakymų išdavimo lapas', $rows, $filters, $shown );
		$count = count( $rows );

		try {
			$pdf = new self( $meta );
			$pdf->AddPage();
			foreach ( array_values( $rows ) as $index => $row ) {
				$pdf->add_order( $row );
				if ( null !== $progress && ( 0 === ( $index + 1 ) % 25 || $index + 1 === $count ) ) {
					$progress( $index + 1, $count );
				}
			}
			return (string) $pdf->Output( 'S' );
		} finally {
			if ( ! empty( $meta['logo']['file'] ) ) {
				wp_delete_file( $meta['logo']['file'] );
			}
		}
	}

	public function __construct( array $meta, string $orientation = 'L' ) {
		parent::__construct( $orientation, 'mm', 'A4' );

		if ( 'P' === $orientation ) {
			$this->page_w = 210.0;
			$this->bottom = 284.0;
		}

		$this->fontpath = ASS_PATH . 'lib/tfpdf/font/';
		$this->meta     = $meta;
		$this->columns  = self::columns();

		$this->AddFont( 'DejaVu', '', 'DejaVuSansCondensed.ttf', true );
		$this->AddFont( 'DejaVu', 'B', 'DejaVuSansCondensed-Bold.ttf', true );
		$this->SetMargins( self::MARGIN, self::MARGIN, self::MARGIN );
		$this->SetAutoPageBreak( false );
		$this->SetDrawColor( 0 );
		$this->SetTextColor( 0 );
		$this->AliasNbPages();
		$this->SetTitle( (string) $meta['title'], true );
		$this->SetAuthor( (string) $meta['site'], true );
		$this->SetCreator( 'Advanced Shipping Settings', true );
	}

	protected function line_height( float $size ): float {
		return $size * 0.3528 * 1.3;
	}

	protected function put( float $x, float $y, string $text, string $style, float $size ): float {
		$this->SetFont( 'DejaVu', $style, $size );
		$height = $this->line_height( $size );
		$this->Text( $x, $y + 0.5 * $height + 0.3 * $this->FontSize, $text );
		return $height;
	}

	protected function fit_chars( string $word, float $width ): int {
		$length = mb_strlen( $word, 'UTF-8' );
		$count  = 1;
		while ( $count < $length && $this->GetStringWidth( mb_substr( $word, 0, $count + 1, 'UTF-8' ) ) <= $width ) {
			$count++;
		}
		return $count;
	}

	protected function wrap( string $text, float $width, float $hang = 0.0 ): array {
		$lines = [];
		$line  = '';

		foreach ( explode( ' ', $text ) as $word ) {
			if ( '' === $word ) {
				continue;
			}
			$limit     = $lines ? $width - $hang : $width;
			$candidate = '' === $line ? $word : $line . ' ' . $word;
			if ( $this->GetStringWidth( $candidate ) <= $limit ) {
				$line = $candidate;
				continue;
			}
			if ( '' !== $line ) {
				$lines[] = $line;
				$line    = '';
				$limit   = $width - $hang;
			}
			while ( $this->GetStringWidth( $word ) > $limit ) {
				$cut     = $this->fit_chars( $word, $limit );
				$lines[] = mb_substr( $word, 0, $cut, 'UTF-8' );
				$word    = mb_substr( $word, $cut, null, 'UTF-8' );
				$limit   = $width - $hang;
			}
			$line = $word;
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return $lines;
	}

	private function block( string $key, $text, string $style, float $size, float $hang = 0.0 ): array {
		$text = self::clean( $text );
		if ( '' === $text ) {
			return [];
		}

		$this->SetFont( 'DejaVu', $style, $size );
		$width = $this->columns[ $key ][1] - 2 * self::PAD;
		$lines = [];

		foreach ( $this->wrap( $text, $width, $hang ) as $index => $line ) {
			$lines[] = [
				'text'   => $line,
				'style'  => $style,
				'size'   => $size,
				'indent' => $index > 0 ? $hang : 0.0,
			];
		}

		return $lines;
	}

	private function fitted_block( string $key, string $text, float $size ): array {
		$text = self::clean( $text );
		if ( '' === $text ) {
			return [];
		}

		$width = $this->columns[ $key ][1] - 2 * self::PAD;
		while ( $size > 8 ) {
			$this->SetFont( 'DejaVu', 'B', $size );
			if ( $this->GetStringWidth( $text ) <= $width ) {
				break;
			}
			$size -= 0.5;
		}

		return $this->block( $key, $text, 'B', $size );
	}

	private function phone( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$parsed = Pickup_Helper::normalize_phone( $raw );
		return $parsed['valid'] ? Pickup_Helper::format_phone( $parsed['number'] ) : $raw;
	}

	private function order_cells( array $row ): array {
		$order = $row['order'];
		$ready = Pickup_Helper::STATUS_READY === $row['status'];
		$cells = [];

		$cells['order'] = array_merge(
			$this->fitted_block( 'order', '#' . $order->get_order_number(), 11 ),
			$this->block( 'order', $ready ? Pickup_Helper::ready_status_label() : Pickup_Helper::status_label( (string) $row['status'] ), '', 7.5 )
		);

		$code = $ready ? Pickup_Code::get_code( $order ) : '';
		if ( '' === $code && array_key_exists( (string) $row['method_id'], Pickup_Helper::own_locations() ) ) {
			$code = '–';
		}
		$cells['code'] = $this->fitted_block( 'code', $code, 13 );

		$cells['pickup'] = [];
		if ( '' !== $row['date'] ) {
			$label = Pickup_Helper::rule_date_label( Pickup_Helper::get_rule( (string) $row['method_id'] ), $row['date'] );
			$day   = Pickup_Helper::day_name( $row['date'] );
			if ( '' !== $label && $label !== $row['date'] ) {
				$cells['pickup'] = array_merge( $this->block( 'pickup', $label, 'B', 8.5 ), $this->block( 'pickup', '' !== $day ? $row['date'] . ' · ' . $day : $row['date'], '', 8 ) );
			} else {
				$cells['pickup'] = array_merge( $this->block( 'pickup', $row['date'], 'B', 8.5 ), $this->block( 'pickup', $day, '', 8 ) );
			}
			$cells['pickup'] = array_merge( $cells['pickup'], $this->block( 'pickup', Pickup_Helper::get_pickup_time_display( $order ), '', 8 ) );
		}

		$point             = Pickup_Helper::get_pickup_point( $order );
		$cells['shipping'] = $this->block( 'shipping', $row['method_label'], 'B', 8.5 );
		if ( '' !== $point && $point !== $row['method_label'] ) {
			$cells['shipping'] = array_merge( $cells['shipping'], $this->block( 'shipping', $point, '', 8 ) );
		}

		$cells['buyer'] = array_merge(
			$this->block( 'buyer', trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), 'B', 8.5 ),
			$this->block( 'buyer', $this->phone( (string) $order->get_billing_phone() ), '', 8 ),
			$this->block( 'buyer', $order->get_billing_email(), '', 8 )
		);

		$cells['recipient'] = array_merge(
			$this->block( 'recipient', trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ), 'B', 8.5 ),
			$this->block( 'recipient', $this->phone( method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : '' ), '', 8 )
		);

		$cells['items'] = [];
		foreach ( (array) ( $row['items'] ?? [] ) as $item ) {
			$quantity       = (float) $item['qty'];
			$quantity       = floor( $quantity ) === $quantity ? (string) (int) $quantity : (string) $quantity;
			$cells['items'] = array_merge( $cells['items'], $this->block( 'items', $quantity . ' × ' . $item['name'], '', 8, 3.0 ) );
		}

		foreach ( [ 'buyer', 'recipient', 'items' ] as $key ) {
			if ( ! $cells[ $key ] ) {
				$cells[ $key ] = $this->block( $key, '–', '', 8 );
			}
		}

		return $cells;
	}

	private function cells_height( array $cells ): float {
		$max = 0.0;
		foreach ( $cells as $lines ) {
			$sum = 0.0;
			foreach ( $lines as $line ) {
				$sum += $this->line_height( (float) $line['size'] );
			}
			$max = max( $max, $sum );
		}
		return $max;
	}

	private function verticals( float $y, float $height ): void {
		$this->SetLineWidth( 0.12 );
		$x = self::MARGIN;
		$this->Line( $x, $y, $x, $y + $height );
		foreach ( $this->columns as $column ) {
			$x += $column[1];
			$this->Line( $x, $y, $x, $y + $height );
		}
	}

	private function draw_slice( array $cells, float $height, bool $with_box ): void {
		$top = $this->GetY();
		$x   = self::MARGIN;

		foreach ( $this->columns as $key => $column ) {
			if ( 'tick' === $key ) {
				if ( $with_box ) {
					$this->SetLineWidth( 0.35 );
					$this->Rect( $x + ( $column[1] - self::BOX ) / 2, $top + self::PAD, self::BOX, self::BOX );
				}
			} else {
				$y = $top + self::PAD;
				foreach ( $cells[ $key ] ?? [] as $line ) {
					$y += $this->put( $x + self::PAD + (float) $line['indent'], $y, (string) $line['text'], (string) $line['style'], (float) $line['size'] );
				}
			}
			$x += $column[1];
		}

		$this->verticals( $top, $height );
		$this->SetLineWidth( 0.3 );
		$this->Line( self::MARGIN, $top + $height, $this->page_w - self::MARGIN, $top + $height );
		$this->SetY( $top + $height );
	}

	public function add_order( array $row ): void {
		$cells    = $this->order_cells( $row );
		$min      = self::BOX;
		$height   = max( $min, $this->cells_height( $cells ) ) + 2 * self::PAD;
		$capacity = $this->bottom - self::FRESH_TOP;

		if ( $this->GetY() + $height <= $this->bottom ) {
			$this->draw_slice( $cells, $height, true );
			return;
		}

		if ( $height <= $capacity ) {
			$this->AddPage();
			$this->draw_slice( $cells, $height, true );
			return;
		}

		$first  = true;
		$number = '#' . $row['order']->get_order_number();

		while ( true ) {
			$space = $this->bottom - $this->GetY() - 2 * self::PAD;
			if ( $space < 30 ) {
				$this->AddPage();
				$space = $this->bottom - $this->GetY() - 2 * self::PAD;
			}

			if ( ! $first ) {
				$cells['order'] = array_merge( $this->block( 'order', $number . ' (tęsinys)', 'B', 8.5 ), $cells['order'] ?? [] );
			}

			$slice = [];
			$left  = false;
			foreach ( $cells as $key => $lines ) {
				$used          = 0.0;
				$slice[ $key ] = [];
				while ( $lines ) {
					$line_height = $this->line_height( (float) $lines[0]['size'] );
					if ( $slice[ $key ] && $used + $line_height > $space ) {
						break;
					}
					$used           += $line_height;
					$slice[ $key ][] = array_shift( $lines );
				}
				$cells[ $key ] = $lines;
				$left          = $left || (bool) $lines;
			}

			$this->draw_slice( $slice, max( $first ? $min : 0.0, $this->cells_height( $slice ) ) + 2 * self::PAD, $first );
			$first = false;

			if ( ! $left ) {
				return;
			}
			$this->AddPage();
		}
	}

	public function Header() {
		$y     = self::MARGIN;
		$title = (string) $this->meta['title'];

		if ( 1 === $this->PageNo() ) {
			$x    = self::MARGIN;
			$logo = $this->meta['logo'] ?? [];

			if ( ! empty( $logo['file'] ) ) {
				$height = 12.0;
				$width  = $height * (float) $logo['ratio'];
				if ( $width > 45 ) {
					$width  = 45.0;
					$height = $width / (float) $logo['ratio'];
				}
				$this->Image( $logo['file'], $x, $y + ( 12 - $height ) / 2, $width, $height, 'PNG' );
				$x += $width + 4;
			}

			$this->put( $x, $y, $title, 'B', 15 );
			$this->put( $x, $y + 7.2, (string) $this->meta['summary'], '', 9 );
			$y += 15;

			foreach ( (array) $this->meta['filters'] as $filter ) {
				$this->SetFont( 'DejaVu', 'B', 9 );
				$label_width = $this->GetStringWidth( $filter[0] . ' ' );
				$this->put( self::MARGIN, $y, self::clean( $filter[0] ), 'B', 9 );
				$this->SetFont( 'DejaVu', '', 9 );
				$lines = $this->wrap( self::clean( $filter[1] ), $this->page_w - 2 * self::MARGIN - $label_width );
				foreach ( $lines ? $lines : [ '' ] as $line ) {
					$y += $this->put( self::MARGIN + $label_width, $y, $line, '', 9 );
				}
			}
			$y += 2.5;
		} else {
			$this->put( self::MARGIN, $y, $title . ' · Eksportuota: ' . $this->meta['exported'], '', 8 );
			$y += 6;
		}

		$this->table_head( $y );
	}

	protected function table_head( float $y ): void {
		$this->SetLineWidth( 0.45 );
		$this->Line( self::MARGIN, $y, $this->page_w - self::MARGIN, $y );
		$this->Line( self::MARGIN, $y + self::HEAD_H, $this->page_w - self::MARGIN, $y + self::HEAD_H );

		$x = self::MARGIN;
		foreach ( $this->columns as $column ) {
			$this->put( $x + self::PAD, $y + ( self::HEAD_H - $this->line_height( 8 ) ) / 2, $column[0], 'B', 8 );
			$x += $column[1];
		}
		$this->verticals( $y, self::HEAD_H );
		$this->SetY( $y + self::HEAD_H );
	}

	public function Footer() {
		$y = $this->bottom + 4;
		$this->put( self::MARGIN, $y, (string) $this->meta['site'], '', 7.5 );

		$this->SetFont( 'DejaVu', '', 7.5 );
		$width = $this->GetStringWidth( 'Puslapis ' . $this->PageNo() . ' iš 99' );
		$this->put( $this->page_w - self::MARGIN - $width, $y, 'Puslapis ' . $this->PageNo() . ' iš {nb}', '', 7.5 );
	}
}

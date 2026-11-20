<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sms_Text {

	const SINGLE_LIMIT = 160;
	const PART_LIMIT   = 153;
	const MAX_PARTS    = 3;

	const CYRILLIC = [
		'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'jo',
		'є' => 'je', 'ж' => 'z', 'з' => 'z', 'и' => 'i', 'і' => 'i', 'ї' => 'ji', 'й' => 'j', 'к' => 'k',
		'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
		'у' => 'u', 'ў' => 'u', 'ф' => 'f', 'х' => 'ch', 'ц' => 'c', 'ч' => 'c', 'ш' => 's', 'щ' => 'sc',
		'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'ju', 'я' => 'ja',
	];

	const PUNCTUATION = [
		'–' => '-', '—' => '-', '‒' => '-', '−' => '-', '~' => '-',
		'‘' => "'", '’' => "'", '‚' => "'", '`' => "'",
		'“' => '"', '”' => '"', '„' => '"', '«' => '"', '»' => '"',
		'…' => '...', '€' => 'EUR', "\u{00A0}" => ' ',
	];

	const FORBIDDEN = '^{}\\[]~|`';

	public static function plain( string $text ): string {
		$text = wp_check_invalid_utf8( $text, true );

		$characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $characters ) ) {
			$characters = [];
		}

		$output = '';
		foreach ( $characters as $character ) {
			$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $character, 'UTF-8' ) : $character;
			if ( isset( self::CYRILLIC[ $lower ] ) ) {
				$mapped  = self::CYRILLIC[ $lower ];
				$output .= $lower === $character ? $mapped : ucfirst( $mapped );
				continue;
			}
			$output .= self::PUNCTUATION[ $character ] ?? $character;
		}

		$output = remove_accents( $output );

		$clean  = '';
		$length = strlen( $output );
		for ( $i = 0; $i < $length; $i++ ) {
			$byte = ord( $output[ $i ] );
			if ( 9 === $byte || 10 === $byte || 13 === $byte ) {
				$clean .= ' ';
			} elseif ( $byte >= 32 && $byte <= 126 && false === strpos( self::FORBIDDEN, $output[ $i ] ) ) {
				$clean .= $output[ $i ];
			}
		}

		return trim( preg_replace( '/ {2,}/', ' ', $clean ) );
	}

	public static function units( string $plain_text ): int {
		return strlen( $plain_text );
	}

	public static function parts( string $plain_text ): int {
		$units = self::units( $plain_text );
		if ( 0 === $units ) {
			return 0;
		}
		return $units <= self::SINGLE_LIMIT ? 1 : (int) ceil( $units / self::PART_LIMIT );
	}

	public static function is_sendable( string $plain_text ): bool {
		$parts = self::parts( $plain_text );
		return $parts >= 1 && $parts <= self::MAX_PARTS;
	}

	public static function render( string $template, array $variables ): string {
		$replacements = [];
		foreach ( $variables as $key => $value ) {
			$replacements[ '{' . $key . '}' ] = (string) $value;
		}
		return self::plain( strtr( $template, $replacements ) );
	}
}

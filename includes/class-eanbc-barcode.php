<?php
/**
 * Validation and local SVG generation for EAN-13, EAN-8, UPC-A, UPC-E,
 * ISBN (books), ISSN (periodicals) and ISMN (sheet music) – optionally with
 * a 2- or 5-digit add-on (issue number, price).
 *
 * Works completely offline - no API token required.
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Barcode helper.
 */
class EANBC_Barcode {

	const L = array( '0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011' );
	const G = array( '0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111' );
	const R = array( '1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100' );

	/** EAN-13: parity of the left half, selected by the first digit. */
	const PARITY = array( 'LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL' );

	/** UPC-E (number system 0): parity selected by the check digit; number system 1 is inverted. */
	const UPCE_PARITY = array( 'GGGLLL', 'GGLGLL', 'GGLLGL', 'GGLLLG', 'GLGGLL', 'GLLGGL', 'GLLLGG', 'GLGLGL', 'GLGLLG', 'GLLGLG' );

	/** 5-digit add-on: parity selected by the add-on checksum. */
	const ADDON5_PARITY = array( 'GGLLL', 'GLGLL', 'GLLGL', 'GLLLG', 'LGGLL', 'LLGGL', 'LLLGG', 'LGLGL', 'LGLLG', 'LLGLG' );

	/** 2-digit add-on: parity selected by value modulo 4. */
	const ADDON2_PARITY = array( 'LL', 'LG', 'GL', 'GG' );

	/** All supported types (besides "auto"). */
	const TYPES = array( 'ean13', 'ean8', 'upca', 'upce', 'isbn', 'issn', 'ismn' );

	/**
	 * Parse and validate a code.
	 *
	 * Accepts spaces/dashes, GTIN-14 with leading zero (reduced to EAN-13),
	 * ISBN-13 / ISBN-10, ISSN (8 characters or EAN-13 with 977), ISMN (979-0… or old "M-…"),
	 * UPC-E (6 or 8 digits) and an add-on separated by a space, "+" or ">" ("9783161484100 52499").
	 *
	 * @param string $raw   User input.
	 * @param string $type  auto | ean13 | ean8 | upca | upce | isbn | issn | ismn.
	 * @param string $addon Add-on given separately (2 or 5 digits); '' = detect from the input.
	 * @return array{valid:bool,code:string,type:string,error:string,lookup:string,isbn:string,isbn10:string,issn:string,ismn:string,addon:string}
	 */
	public static function parse( $raw, $type = 'auto', $addon = '' ) {
		$raw   = trim( (string) $raw );
		$type  = in_array( $type, self::TYPES, true ) ? $type : 'auto';
		$addon = preg_replace( '/[\s\-]/', '', (string) $addon );

		if ( '' !== $addon ) {
			$result = self::parse_main( $raw, $type );
			if ( $result['valid'] ) {
				if ( preg_match( '/^(\d{2}|\d{5})$/', $addon ) ) {
					$result['addon'] = $addon;
				} else {
					$result['valid'] = false;
					$result['error'] = __( 'The add-on must have 2 or 5 digits.', 'ean-isbn-barcodes' );
				}
			}
			return $result;
		}

		$result = self::parse_main( $raw, $type );
		// "9783161484100 52499" – only split when the whole input is not valid on its own.
		if ( ! $result['valid'] && preg_match( '/^(.+?)\s*[\s+>]\s*(\d{2}|\d{5})$/', $raw, $m ) ) {
			$main = self::parse_main( $m[1], $type );
			if ( $main['valid'] ) {
				$main['addon'] = $m[2];
				return $main;
			}
		}
		return $result;
	}

	/**
	 * Parse the main code (without add-on).
	 *
	 * @param string $raw  User input.
	 * @param string $type Type.
	 * @return array
	 */
	private static function parse_main( $raw, $type ) {
		$raw = trim( (string) $raw );
		// "ISBN 978-…", "ISBN-13: …", "ISSN 0317-8471", "ISMN 979-0-…" – the prefix also sets the type.
		if ( preg_match( '/^(ISBN|ISSN|ISMN)(?:[\s\-]?1[03])?\s*:?\s*/i', $raw, $m ) ) {
			$raw  = substr( $raw, strlen( $m[0] ) );
			$type = 'auto' === $type ? strtolower( $m[1] ) : $type;
		}
		$code   = strtoupper( preg_replace( '/[\s\-\.]/', '', $raw ) );
		$result = array(
			'valid'  => false,
			'code'   => $code,
			'type'   => '',
			'error'  => '',
			'lookup' => $code,
			'isbn'   => '',
			'isbn10' => '',
			'issn'   => '',
			'ismn'   => '',
			'addon'  => '',
		);

		if ( '' === $code ) {
			$result['error'] = __( 'No EAN given.', 'ean-isbn-barcodes' );
			return $result;
		}

		// Old ISMN "M-2306-7118-7" = 979-0-2306-7118-7 (same check digit by design).
		if ( preg_match( '/^M\d{9}$/', $code ) ) {
			$code = '9790' . substr( $code, 1 );
			$type = 'auto' === $type ? 'ismn' : $type;
		}

		// ISSN: 8 characters. Without "ISSN" prefix only when it ends with X (otherwise it is an EAN-8).
		if ( preg_match( '/^\d{7}[\dX]$/', $code ) && ( 'issn' === $type || 'X' === substr( $code, -1 ) ) ) {
			$check = self::mod11_check_digit( substr( $code, 0, 7 ) );
			if ( substr( $code, -1 ) !== $check ) {
				/* translators: %s: correct check digit (0-9 or X) */
				$result['error'] = sprintf( __( 'Invalid ISSN check digit – the last character should be %s.', 'ean-isbn-barcodes' ), $check );
				return $result;
			}
			$payload          = '977' . substr( $code, 0, 7 ) . '00';
			$ean              = $payload . self::check_digit( $payload );
			$result['valid']  = true;
			$result['code']   = $ean;
			$result['type']   = 'issn';
			$result['lookup'] = $ean;
			$result['issn']   = substr( $code, 0, 4 ) . '-' . substr( $code, 4 );
			return $result;
		}

		// ISBN-10 (EAN has no 10-digit format, so this is unambiguous).
		if ( 10 === strlen( $code ) && preg_match( '/^\d{9}[\dX]$/', $code ) ) {
			$check10 = self::isbn10_check_digit( substr( $code, 0, 9 ) );
			if ( substr( $code, -1 ) !== $check10 ) {
				/* translators: %s: correct check digit (0-9 or X) */
				$result['error'] = sprintf( __( 'Invalid ISBN-10 check digit – the last character should be %s.', 'ean-isbn-barcodes' ), $check10 );
				return $result;
			}
			$payload          = '978' . substr( $code, 0, 9 );
			$isbn13           = $payload . self::check_digit( $payload );
			$result['valid']  = true;
			$result['code']   = $isbn13;
			$result['type']   = 'isbn';
			$result['lookup'] = $isbn13;
			$result['isbn']   = self::format_groups( $raw, $isbn13, true );
			$result['isbn10'] = self::format_isbn10( $result['isbn'], $isbn13 );
			return $result;
		}

		if ( ! ctype_digit( $code ) ) {
			$result['error'] = __( 'An EAN may only contain digits (an ISBN-10 may end with X).', 'ean-isbn-barcodes' );
			return $result;
		}

		// GTIN-14 with leading zero(s) is the same article as EAN-13 / UPC-A.
		if ( 14 === strlen( $code ) && '0' === $code[0] ) {
			$code = substr( $code, 1 );
		}

		if ( 'upce' === $type ) {
			return self::parse_upce( $code, $result );
		}

		$len  = strlen( $code );
		$kind = 13 === $len ? self::kind( $code ) : '';

		if ( in_array( $type, array( 'isbn', 'issn', 'ismn' ), true ) && $kind !== $type ) {
			$messages        = array(
				'isbn' => 'ismn' === $kind
					? __( 'This is an ISMN (sheet music), not an ISBN.', 'ean-isbn-barcodes' )
					: __( 'This is not an ISBN. Use an ISBN-13 (starting with 978 or 979) or an ISBN-10.', 'ean-isbn-barcodes' ),
				'issn' => __( 'This is not an ISSN. Use an 8-digit ISSN or an EAN-13 starting with 977.', 'ean-isbn-barcodes' ),
				'ismn' => __( 'This is not an ISMN. Use an ISMN-13 (starting with 979-0) or an old ISMN starting with M.', 'ean-isbn-barcodes' ),
			);
			$result['error'] = $messages[ $type ];
			return $result;
		}

		if ( 'auto' === $type ) {
			if ( 6 === $len ) {
				return self::parse_upce( $code, $result );
			}
			if ( 8 === $len && self::looks_like_upce( $code ) ) {
				return self::parse_upce( $code, $result );
			}
			$map  = array(
				8  => 'ean8',
				12 => 'upca',
				13 => '' !== $kind ? $kind : 'ean13',
			);
			$type = isset( $map[ $len ] ) ? $map[ $len ] : '';
		} elseif ( 'ean13' === $type && 12 === $len ) {
			$code = '0' . $code; // UPC-A shown as EAN-13.
			$len  = 13;
		} elseif ( 'upca' === $type && 13 === $len && '0' === $code[0] ) {
			$code = substr( $code, 1 ); // EAN-13 with leading 0 shown as UPC-A.
			$len  = 12;
		}

		$expected = array(
			'ean8'  => 8,
			'upca'  => 12,
			'ean13' => 13,
			'isbn'  => 13,
			'issn'  => 13,
			'ismn'  => 13,
		);
		if ( '' === $type || ! isset( $expected[ $type ] ) || $expected[ $type ] !== $len ) {
			/* translators: %d: number of digits entered */
			$result['error'] = sprintf( __( 'Unsupported length (%d digits). Use 8 (EAN-8, UPC-E), 12 (UPC-A) or 13 (EAN-13) digits, an ISBN-10 or an ISSN.', 'ean-isbn-barcodes' ), $len );
			return $result;
		}

		$check = self::check_digit( substr( $code, 0, -1 ) );
		if ( (int) substr( $code, -1 ) !== $check ) {
			/* translators: %d: correct check digit */
			$result['error'] = sprintf( __( 'Invalid check digit – the last digit should be %d.', 'ean-isbn-barcodes' ), $check );
			return $result;
		}

		$result['valid']  = true;
		$result['code']   = $code;
		$result['type']   = $type;
		$result['lookup'] = 'upca' === $type ? '0' . $code : $code;
		if ( 'isbn' === $type ) {
			$result['isbn']   = self::format_groups( $raw, $code, false );
			$result['isbn10'] = self::format_isbn10( $result['isbn'], $code );
		} elseif ( 'issn' === $type ) {
			$issn           = substr( $code, 3, 7 );
			$issn          .= self::mod11_check_digit( $issn );
			$result['issn'] = substr( $issn, 0, 4 ) . '-' . substr( $issn, 4 );
		} elseif ( 'ismn' === $type ) {
			$result['ismn'] = self::format_groups( $raw, $code, false );
		}
		return $result;
	}

	/**
	 * What a 13-digit code stands for: isbn (978, 979-1…9), ismn (979-0), issn (977) or ''.
	 *
	 * @param string $code 13 digits.
	 * @return string
	 */
	public static function kind( $code ) {
		if ( 13 !== strlen( $code ) ) {
			return '';
		}
		if ( 0 === strpos( $code, '977' ) ) {
			return 'issn';
		}
		if ( 0 === strpos( $code, '9790' ) ) {
			return 'ismn';
		}
		return in_array( substr( $code, 0, 3 ), array( '978', '979' ), true ) ? 'isbn' : '';
	}

	/**
	 * 978… and 979-1…9 is "Bookland" – the EAN range reserved for ISBNs.
	 *
	 * @param string $code 13 digits.
	 * @return bool
	 */
	public static function is_bookland( $code ) {
		return 'isbn' === self::kind( $code );
	}

	/**
	 * UPC-E: 6 digits (number system 0, check digit added), 8 digits, or a UPC-A that can be shortened.
	 *
	 * @param string $code   Digits.
	 * @param array  $result Result skeleton.
	 * @return array
	 */
	private static function parse_upce( $code, $result ) {
		$len = strlen( $code );
		if ( 13 === $len && '0' === $code[0] ) {
			$code = substr( $code, 1 );
			$len  = 12;
		}

		if ( 12 === $len ) {
			$check = self::check_digit( substr( $code, 0, 11 ) );
			if ( (int) $code[11] !== $check ) {
				/* translators: %d: correct check digit */
				$result['error'] = sprintf( __( 'Invalid check digit – the last digit should be %d.', 'ean-isbn-barcodes' ), $check );
				return $result;
			}
			$short = self::upca_to_upce( $code );
			if ( null === $short ) {
				$result['error'] = __( 'This UPC-A cannot be shortened to UPC-E (only codes with enough zeros can).', 'ean-isbn-barcodes' );
				return $result;
			}
			$code = $short;
		} elseif ( 6 === $len ) {
			$code = '0' . $code . self::check_digit( self::upce_to_upca( '0' . $code ) );
		} elseif ( 8 !== $len ) {
			$result['error'] = __( 'A UPC-E has 6 or 8 digits (or use a UPC-A that can be shortened).', 'ean-isbn-barcodes' );
			return $result;
		}

		if ( '0' !== $code[0] && '1' !== $code[0] ) {
			$result['error'] = __( 'A UPC-E starts with 0 or 1.', 'ean-isbn-barcodes' );
			return $result;
		}
		$payload = self::upce_to_upca( substr( $code, 0, 7 ) );
		$check   = self::check_digit( $payload );
		if ( (int) $code[7] !== $check ) {
			/* translators: %d: correct check digit */
			$result['error'] = sprintf( __( 'Invalid check digit – the last digit should be %d.', 'ean-isbn-barcodes' ), $check );
			return $result;
		}

		$result['valid']  = true;
		$result['code']   = $code;
		$result['type']   = 'upce';
		$result['lookup'] = '0' . $payload . $check;
		return $result;
	}

	/**
	 * Auto detection for 8 digits. EAN-8 starting with 0 is reserved for in-store use
	 * (restricted circulation), so a valid UPC-E wins there; with 1 only if it is no valid EAN-8.
	 * type="ean8" / type="upce" forces either.
	 *
	 * @param string $code 8 digits.
	 * @return bool
	 */
	private static function looks_like_upce( $code ) {
		if ( '0' !== $code[0] && '1' !== $code[0] ) {
			return false;
		}
		$valid_ean8 = self::check_digit( substr( $code, 0, 7 ) ) === (int) $code[7];
		$valid_upce = self::check_digit( self::upce_to_upca( substr( $code, 0, 7 ) ) ) === (int) $code[7];
		return $valid_upce && ( ! $valid_ean8 || '0' === $code[0] );
	}

	/**
	 * Expand UPC-E (number system + 6 digits) to the 11 UPC-A digits without check digit.
	 *
	 * @param string $s 7 digits.
	 * @return string
	 */
	public static function upce_to_upca( $s ) {
		$ns = $s[0];
		$d  = substr( $s, 1, 6 );
		switch ( $d[5] ) {
			case '0':
			case '1':
			case '2':
				return $ns . substr( $d, 0, 2 ) . $d[5] . '0000' . substr( $d, 2, 3 );
			case '3':
				return $ns . substr( $d, 0, 3 ) . '00000' . substr( $d, 3, 2 );
			case '4':
				return $ns . substr( $d, 0, 4 ) . '00000' . $d[4];
			default:
				return $ns . substr( $d, 0, 5 ) . '0000' . $d[5];
		}
	}

	/**
	 * Shorten a UPC-A (12 digits) to UPC-E (8 digits) if possible.
	 *
	 * @param string $a 12 digits.
	 * @return string|null
	 */
	public static function upca_to_upce( $a ) {
		$ns = $a[0];
		if ( '0' !== $ns && '1' !== $ns ) {
			return null;
		}
		$m = substr( $a, 1, 5 );
		$p = substr( $a, 6, 5 );
		if ( '00' === substr( $m, 3, 2 ) && $m[2] <= '2' && '00' === substr( $p, 0, 2 ) ) {
			$d = substr( $m, 0, 2 ) . substr( $p, 2, 3 ) . $m[2];
		} elseif ( '00' === substr( $m, 3, 2 ) && '000' === substr( $p, 0, 3 ) ) {
			$d = substr( $m, 0, 3 ) . substr( $p, 3, 2 ) . '3';
		} elseif ( '0' === $m[4] && '0000' === substr( $p, 0, 4 ) ) {
			$d = substr( $m, 0, 4 ) . $p[4] . '4';
		} elseif ( '0000' === substr( $p, 0, 4 ) && $p[4] >= '5' ) {
			$d = $m . $p[4];
		} else {
			return null;
		}
		// Must expand back to the same number (guards against ambiguous forms).
		return self::upce_to_upca( $ns . $d ) === substr( $a, 0, 11 ) ? $ns . $d . $a[11] : null;
	}

	/**
	 * Modulo 11 check digit with weights n+1…2 (ISBN-10: 9 digits, ISSN: 7 digits).
	 *
	 * @param string $payload Digits without check digit.
	 * @return string 0-9 or X.
	 */
	public static function mod11_check_digit( $payload ) {
		$sum = 0;
		$n   = strlen( $payload );
		for ( $i = 0; $i < $n; $i++ ) {
			$sum += (int) $payload[ $i ] * ( $n + 1 - $i );
		}
		$check = ( 11 - $sum % 11 ) % 11;
		return 10 === $check ? 'X' : (string) $check;
	}

	/**
	 * ISBN-10 check digit (Modulo 11, weights 10…2).
	 *
	 * @param string $payload First 9 digits.
	 * @return string 0-9 or X.
	 */
	public static function isbn10_check_digit( $payload ) {
		return self::mod11_check_digit( $payload );
	}

	/**
	 * Human readable ISBN-13 / ISMN-13. The hyphenation (group/publisher/title) depends on
	 * official range tables, so it is taken from the input when the user wrote it
	 * with hyphens or spaces; otherwise the plain 13 digits are used.
	 *
	 * @param string $raw    Input without prefix.
	 * @param string $code13 Valid 13 digits.
	 * @param bool   $from10 Input was an ISBN-10.
	 * @return string
	 */
	private static function format_groups( $raw, $code13, $from10 ) {
		$groups = preg_split( '/[\s\-\.]+/', trim( $raw ), -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $groups ) < 2 ) {
			return $code13;
		}
		if ( $from10 ) {
			array_pop( $groups );
			$groups = array_merge( array( '978' ), $groups, array( substr( $code13, -1 ) ) );
		}
		$formatted = implode( '-', $groups );
		return str_replace( '-', '', $formatted ) === $code13 ? $formatted : $code13;
	}

	/**
	 * ISBN-10 for a 978… ISBN-13 (979… has no ISBN-10).
	 *
	 * @param string $formatted Formatted ISBN-13 (see format_groups()).
	 * @param string $isbn13    ISBN-13 digits.
	 * @return string Empty if not convertible.
	 */
	private static function format_isbn10( $formatted, $isbn13 ) {
		if ( '978' !== substr( $isbn13, 0, 3 ) ) {
			return '';
		}
		$check = self::isbn10_check_digit( substr( $isbn13, 3, 9 ) );
		if ( 0 === strpos( $formatted, '978-' ) ) {
			return substr( $formatted, 4, -1 ) . $check;
		}
		return substr( $isbn13, 3, 9 ) . $check;
	}

	/**
	 * GS1 check digit (Modulo 10, weights 3/1 from the right).
	 *
	 * @param string $payload Digits without check digit.
	 * @return int
	 */
	public static function check_digit( $payload ) {
		$sum    = 0;
		$digits = strrev( $payload );
		$n      = strlen( $digits );
		for ( $i = 0; $i < $n; $i++ ) {
			$sum += (int) $digits[ $i ] * ( 0 === $i % 2 ? 3 : 1 );
		}
		return ( 10 - $sum % 10 ) % 10;
	}

	/**
	 * Build the module pattern. Returns a list of [bits, is_long] segments.
	 *
	 * @param string $code Valid code.
	 * @param string $type ean13 | ean8 | upca | upce (book codes are drawn as ean13).
	 * @return array
	 */
	private static function segments( $code, $type ) {
		$seg = array();
		if ( 'ean8' === $type ) {
			$seg[] = array( '101', true );
			for ( $i = 0; $i < 4; $i++ ) {
				$seg[] = array( self::L[ (int) $code[ $i ] ], false );
			}
			$seg[] = array( '01010', true );
			for ( $i = 4; $i < 8; $i++ ) {
				$seg[] = array( self::R[ (int) $code[ $i ] ], false );
			}
			$seg[] = array( '101', true );
			return $seg;
		}

		if ( 'upce' === $type ) {
			$parity = self::UPCE_PARITY[ (int) $code[7] ];
			if ( '1' === $code[0] ) {
				$parity = strtr( $parity, 'LG', 'GL' );
			}
			$seg[] = array( '101', true );
			for ( $i = 1; $i <= 6; $i++ ) {
				$set   = 'L' === $parity[ $i - 1 ] ? self::L : self::G;
				$seg[] = array( $set[ (int) $code[ $i ] ], false );
			}
			$seg[] = array( '010101', true );
			return $seg;
		}

		$full   = 'upca' === $type ? '0' . $code : $code;
		$parity = self::PARITY[ (int) $full[0] ];
		$seg[]  = array( '101', true );
		for ( $i = 1; $i <= 6; $i++ ) {
			$set   = 'L' === $parity[ $i - 1 ] ? self::L : self::G;
			$seg[] = array( $set[ (int) $full[ $i ] ], 'upca' === $type && 1 === $i );
		}
		$seg[] = array( '01010', true );
		for ( $i = 7; $i <= 12; $i++ ) {
			$seg[] = array( self::R[ (int) $full[ $i ] ], 'upca' === $type && 12 === $i );
		}
		$seg[] = array( '101', true );
		return $seg;
	}

	/**
	 * Module pattern of a 2- or 5-digit add-on (EAN-2 / EAN-5).
	 *
	 * @param string $addon 2 or 5 digits.
	 * @return string
	 */
	private static function addon_bits( $addon ) {
		if ( 2 === strlen( $addon ) ) {
			$parity = self::ADDON2_PARITY[ (int) $addon % 4 ];
		} else {
			$sum    = 3 * ( (int) $addon[0] + (int) $addon[2] + (int) $addon[4] ) + 9 * ( (int) $addon[1] + (int) $addon[3] );
			$parity = self::ADDON5_PARITY[ $sum % 10 ];
		}
		$bits = '1011';
		$n    = strlen( $addon );
		for ( $i = 0; $i < $n; $i++ ) {
			$bits .= ( $i ? '01' : '' ) . ( 'L' === $parity[ $i ] ? self::L : self::G )[ (int) $addon[ $i ] ];
		}
		return $bits;
	}

	/**
	 * Rectangles for a bit string (runs of "1" merged into one rect).
	 *
	 * @param string $bits   Modules.
	 * @param float  $x      Left edge.
	 * @param float  $y      Top.
	 * @param float  $m      Module width.
	 * @param float  $height Bar height.
	 * @return string
	 */
	private static function rects( $bits, $x, $y, $m, $height ) {
		$out = '';
		$len = strlen( $bits );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '1' === $bits[ $i ] ) {
				$run = 1;
				while ( $i + $run < $len && '1' === $bits[ $i + $run ] ) {
					++$run;
				}
				$out .= sprintf( '<rect x="%s" y="%s" width="%s" height="%s"/>', self::n( $x + $i * $m ), self::n( $y ), self::n( $run * $m ), self::n( $height ) );
				$i   += $run - 1;
			}
		}
		return $out;
	}

	/**
	 * Render an SVG barcode.
	 *
	 * @param string $code Valid code (see parse()).
	 * @param string $type Barcode type.
	 * @param array  $opt  module_width, bar_height, bar_color, bg_color (or ''), show_number,
	 *                     isbn_text (optional line above the bars, e.g. "ISBN 978-3-…"),
	 *                     addon (optional 2 or 5 digits).
	 * @return string SVG markup.
	 */
	public static function svg( $code, $type, $opt ) {
		$label_type = $type;
		$type       = in_array( $type, array( 'isbn', 'issn', 'ismn' ), true ) ? 'ean13' : $type; // Book & co. are EAN-13.
		$headline   = isset( $opt['isbn_text'] ) ? (string) $opt['isbn_text'] : '';
		$addon      = isset( $opt['addon'] ) && preg_match( '/^(\d{2}|\d{5})$/', (string) $opt['addon'] ) ? (string) $opt['addon'] : '';
		$m          = max( 1, (float) $opt['module_width'] );
		$h          = max( 10, (int) $opt['bar_height'] );
		$numbers    = ! empty( $opt['show_number'] );
		$fg         = $opt['bar_color'];
		$bg         = $opt['bg_color'];
		$modules    = array(
			'ean8' => 67,
			'upce' => 51,
		);
		$modules    = isset( $modules[ $type ] ) ? $modules[ $type ] : 95;
		$quiet_l    = array(
			'ean8' => 7,
			'upce' => 9,
		);
		$quiet_l    = isset( $quiet_l[ $type ] ) ? $quiet_l[ $type ] : 11;
		$quiet_r    = in_array( $type, array( 'upca' ), true ) ? 11 : 7;
		$addon_bits = '' !== $addon ? self::addon_bits( $addon ) : '';
		// Gap between symbol and add-on (standard: 7–12 modules; 9 leaves room for UPC's small check digit).
		$gap     = 9;
		$right   = '' !== $addon ? $gap + strlen( $addon_bits ) + 5 : $quiet_r;
		$top     = '' !== $headline ? 11 * $m : 0;
		$long_h  = $numbers ? $h + 5 * $m : $h;
		$total_h = ( $numbers ? $h + 10 * $m : $h ) + $top;
		$total_w = ( $quiet_l + $modules + $right ) * $m;
		$x       = $quiet_l * $m;
		$rects   = '';

		foreach ( self::segments( $code, $type ) as $segment ) {
			list( $bits, $long ) = $segment;
			$rects              .= self::rects( $bits, $x, $top, $m, $long ? $long_h : $h );
			$x                  += strlen( $bits ) * $m;
		}

		$text = '';
		$q    = $quiet_l * $m;
		if ( '' !== $addon ) {
			// Add-on: digits above, bars below them, ending with the main symbol's digit line.
			$ax     = $q + ( $modules + $gap ) * $m;
			$ay     = $numbers ? $top + 10 * $m : $top;
			$rects .= self::rects( $addon_bits, $ax, $ay, $m, $h );
		}

		if ( $numbers ) {
			$size     = 8 * $m;
			$baseline = $top + $h + 8.5 * $m;
			$digit    = static function ( $char, $cx, $small = false, $y = null ) use ( $size, $baseline ) {
				$y = null === $y ? $baseline : $y;
				return sprintf(
					'<text x="%s" y="%s"%s>%s</text>',
					self::n( $cx ),
					self::n( $small ? $y - $size * 0.15 : $y ),
					$small ? ' font-size="' . self::n( $size * 0.75 ) . '"' : '',
					esc_html( $char )
				);
			};
			if ( 'ean8' === $type ) {
				for ( $i = 0; $i < 8; $i++ ) {
					$start = $i < 4 ? 3 + $i * 7 : 8 + $i * 7; // 3 + 4*7 + 5 = 36.
					$text .= $digit( $code[ $i ], $q + ( $start + 3.5 ) * $m );
				}
			} elseif ( 'upce' === $type ) {
				$text .= $digit( $code[0], $q - 4.5 * $m, true );
				for ( $i = 1; $i <= 6; $i++ ) {
					$text .= $digit( $code[ $i ], $q + ( 3 + ( $i - 1 ) * 7 + 3.5 ) * $m );
				}
				$text .= $digit( $code[7], $q + ( 51 + 4.5 ) * $m, true );
			} elseif ( 'upca' === $type ) {
				$text .= $digit( $code[0], $q - 4.5 * $m, true );
				for ( $i = 1; $i <= 10; $i++ ) {
					$start = $i <= 5 ? 3 + $i * 7 : 8 + $i * 7;
					$text .= $digit( $code[ $i ], $q + ( $start + 3.5 ) * $m );
				}
				$text .= $digit( $code[11], $q + ( 95 + 4.5 ) * $m, true );
			} else {
				$text .= $digit( $code[0], $q - 5 * $m );
				for ( $i = 1; $i <= 12; $i++ ) {
					$start = $i <= 6 ? 3 + ( $i - 1 ) * 7 : 8 + ( $i - 1 ) * 7;
					$text .= $digit( $code[ $i ], $q + ( $start + 3.5 ) * $m );
				}
			}
			if ( '' !== $addon ) {
				$n = strlen( $addon );
				for ( $i = 0; $i < $n; $i++ ) {
					$text .= $digit( $addon[ $i ], $ax + ( 4 + $i * 9 + 3.5 ) * $m, false, $top + 8 * $m );
				}
			}
			$text = sprintf(
				'<g fill="%s" font-family="ui-monospace,SFMono-Regular,Menlo,Consolas,\'Liberation Mono\',monospace" font-size="%s" text-anchor="middle">%s</g>',
				esc_attr( $fg ),
				self::n( $size ),
				$text
			);
		}

		if ( '' !== $headline ) {
			$text .= sprintf(
				'<text x="%s" y="%s" fill="%s" font-family="ui-monospace,SFMono-Regular,Menlo,Consolas,\'Liberation Mono\',monospace" font-size="%s" text-anchor="middle" textLength="%s" lengthAdjust="spacingAndGlyphs">%s</text>',
				self::n( ( $quiet_l + $modules / 2 ) * $m ),
				self::n( 8 * $m ),
				esc_attr( $fg ),
				self::n( 8 * $m ),
				self::n( min( $modules, strlen( $headline ) * 4.8 ) * $m ),
				esc_html( $headline )
			);
		}

		/* translators: 1: barcode type (e.g. EAN-13), 2: the code */
		$label = sprintf( __( '%1$s barcode %2$s', 'ean-isbn-barcodes' ), self::type_label( $label_type ), $code . ( '' !== $addon ? ' ' . $addon : '' ) );

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" class="eanbc-svg" width="%1$s" height="%2$s" viewBox="0 0 %1$s %2$s" role="img" aria-label="%3$s"><title>%3$s</title>%4$s<g fill="%5$s">%6$s</g>%7$s</svg>',
			self::n( $total_w ),
			self::n( $total_h ),
			esc_attr( $label ),
			$bg ? sprintf( '<rect width="100%%" height="100%%" fill="%s"/>', esc_attr( $bg ) ) : '',
			esc_attr( $fg ),
			$rects,
			$text
		);
	}

	/**
	 * Display name of a type.
	 *
	 * @param string $type Type key.
	 * @return string
	 */
	public static function type_label( $type ) {
		$labels = array(
			'ean13' => 'EAN-13',
			'ean8'  => 'EAN-8',
			'upca'  => 'UPC-A',
			'upce'  => 'UPC-E',
			'isbn'  => 'ISBN',
			'issn'  => 'ISSN',
			'ismn'  => 'ISMN',
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : strtoupper( $type );
	}

	/**
	 * Compact number formatting for SVG attributes.
	 *
	 * @param float $v Value.
	 * @return string
	 */
	private static function n( $v ) {
		return rtrim( rtrim( number_format( (float) $v, 2, '.', '' ), '0' ), '.' );
	}
}

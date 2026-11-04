<?php
/**
 * Minimal QR code encoder (ISO/IEC 18004): byte mode, error correction L/M/Q/H,
 * versions 1–40, automatic mask selection, SVG output. Works offline.
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * QR code encoder.
 */
class EANBC_QR {

	/** Error correction codewords per block, by level and version (index 0 unused). */
	const ECC_PER_BLOCK = array(
		'L' => array( 0, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ),
		'M' => array( 0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28 ),
		'Q' => array( 0, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ),
		'H' => array( 0, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ),
	);

	/** Number of error correction blocks, by level and version (index 0 unused). */
	const BLOCKS = array(
		'L' => array( 0, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25 ),
		'M' => array( 0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49 ),
		'Q' => array( 0, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68 ),
		'H' => array( 0, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81 ),
	);

	/** Format information bits of each level. */
	const ECL_BITS = array(
		'L' => 1,
		'M' => 0,
		'Q' => 3,
		'H' => 2,
	);

	/**
	 * Module grid (true = dark).
	 *
	 * @var bool[][]
	 */
	private $modules = array();

	/**
	 * Function pattern marker.
	 *
	 * @var bool[][]
	 */
	private $is_function = array();

	/**
	 * Side length in modules.
	 *
	 * @var int
	 */
	private $size = 0;

	/**
	 * Encode text into a module matrix.
	 *
	 * @param string $text Data (UTF-8, encoded in byte mode).
	 * @param string $ecl  L | M | Q | H.
	 * @return bool[][]|null Rows of modules, null if the text is too long.
	 */
	public static function matrix( $text, $ecl = 'M' ) {
		$ecl  = isset( self::ECL_BITS[ $ecl ] ) ? $ecl : 'M';
		$data = array_values( unpack( 'C*', (string) $text ) ?: array() ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		$len  = count( $data );

		for ( $version = 1; $version <= 40; $version++ ) {
			$capacity = self::data_codewords( $version, $ecl ) * 8;
			$needed   = 4 + ( $version < 10 ? 8 : 16 ) + $len * 8;
			if ( $needed <= $capacity ) {
				break;
			}
		}
		if ( $version > 40 ) {
			return null;
		}

		// Bit stream: mode "byte", length, data, terminator, padding.
		$bits = '0100' . str_pad( decbin( $len ), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT );
		foreach ( $data as $byte ) {
			$bits .= str_pad( decbin( $byte ), 8, '0', STR_PAD_LEFT );
		}
		$bits     .= str_repeat( '0', min( 4, $capacity - strlen( $bits ) ) );
		$bits     .= str_repeat( '0', ( 8 - strlen( $bits ) % 8 ) % 8 );
		$codewords = array_map( 'bindec', str_split( $bits, 8 ) );
		// Pad codewords alternate 0xEC and 0x11 until the capacity is reached.
		$missing = $capacity / 8 - count( $codewords );
		for ( $i = 0; $i < $missing; $i++ ) {
			$codewords[] = 0 === $i % 2 ? 0xEC : 0x11;
		}

		$qr = new self();
		return $qr->build( $version, $ecl, self::add_ecc( $codewords, $version, $ecl ) );
	}

	/**
	 * SVG of a module matrix.
	 *
	 * @param bool[][] $matrix Modules.
	 * @param array    $opt    module (px), color, background ('' = transparent), quiet (modules), label (aria).
	 * @return string
	 */
	public static function svg( $matrix, $opt ) {
		$m     = max( 1, (float) $opt['module'] );
		$quiet = isset( $opt['quiet'] ) ? (int) $opt['quiet'] : 4;
		$n     = count( $matrix );
		$total = ( $n + 2 * $quiet ) * $m;
		$path  = '';
		foreach ( $matrix as $y => $row ) {
			for ( $x = 0; $x < $n; $x++ ) {
				if ( ! $row[ $x ] ) {
					continue;
				}
				$run = 1;
				while ( $x + $run < $n && $row[ $x + $run ] ) {
					++$run;
				}
				$path .= 'M' . ( $x + $quiet ) . ' ' . ( $y + $quiet ) . 'h' . $run . 'v1h-' . $run . 'z';
				$x    += $run - 1;
			}
		}
		$label = isset( $opt['label'] ) ? (string) $opt['label'] : 'QR code';
		$px    = rtrim( rtrim( number_format( $total, 2, '.', '' ), '0' ), '.' );
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" class="eanbc-svg eanbc-qr-svg" width="%1$s" height="%1$s" viewBox="0 0 %2$d %2$d" shape-rendering="crispEdges" role="img" aria-label="%3$s"><title>%3$s</title>%4$s<path fill="%5$s" d="%6$s"/></svg>',
			esc_attr( $px ),
			$n + 2 * $quiet,
			esc_attr( $label ),
			! empty( $opt['background'] ) ? sprintf( '<rect width="100%%" height="100%%" fill="%s"/>', esc_attr( $opt['background'] ) ) : '',
			esc_attr( $opt['color'] ),
			$path
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Capacity & error correction
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Data + ECC modules available in a version (without function patterns).
	 *
	 * @param int $version Version.
	 * @return int
	 */
	private static function raw_modules( $version ) {
		$result = ( 16 * $version + 128 ) * $version + 64;
		if ( $version >= 2 ) {
			$align   = intdiv( $version, 7 ) + 2;
			$result -= ( 25 * $align - 10 ) * $align - 55;
			if ( $version >= 7 ) {
				$result -= 36;
			}
		}
		return $result;
	}

	/**
	 * Data codewords of a version and level.
	 *
	 * @param int    $version Version.
	 * @param string $ecl     Level.
	 * @return int
	 */
	private static function data_codewords( $version, $ecl ) {
		return intdiv( self::raw_modules( $version ), 8 ) - self::ECC_PER_BLOCK[ $ecl ][ $version ] * self::BLOCKS[ $ecl ][ $version ];
	}

	/**
	 * Split into blocks, add Reed-Solomon codewords and interleave.
	 *
	 * @param int[]  $data    Data codewords.
	 * @param int    $version Version.
	 * @param string $ecl     Level.
	 * @return int[]
	 */
	private static function add_ecc( $data, $version, $ecl ) {
		$num_blocks = self::BLOCKS[ $ecl ][ $version ];
		$ecc_len    = self::ECC_PER_BLOCK[ $ecl ][ $version ];
		$raw        = intdiv( self::raw_modules( $version ), 8 );
		$num_short  = $num_blocks - $raw % $num_blocks;
		$short_len  = intdiv( $raw, $num_blocks );
		$divisor    = self::rs_divisor( $ecc_len );

		$blocks = array();
		$k      = 0;
		for ( $i = 0; $i < $num_blocks; $i++ ) {
			$dat_len = $short_len - $ecc_len + ( $i < $num_short ? 0 : 1 );
			$dat     = array_slice( $data, $k, $dat_len );
			$k      += $dat_len;
			$ecc     = self::rs_remainder( $dat, $divisor );
			if ( $i < $num_short ) {
				$dat[] = 0; // Placeholder so all blocks have equal length while interleaving.
			}
			$blocks[] = array_merge( $dat, $ecc );
		}

		$result = array();
		$width  = count( $blocks[0] );
		for ( $i = 0; $i < $width; $i++ ) {
			foreach ( $blocks as $j => $block ) {
				if ( $i !== $short_len - $ecc_len || $j >= $num_short ) {
					$result[] = $block[ $i ];
				}
			}
		}
		return $result;
	}

	/**
	 * Reed-Solomon generator polynomial.
	 *
	 * @param int $degree Degree.
	 * @return int[]
	 */
	private static function rs_divisor( $degree ) {
		$result                = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root                  = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::gf_mul( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::gf_mul( $root, 0x02 );
		}
		return $result;
	}

	/**
	 * Reed-Solomon remainder.
	 *
	 * @param int[] $data    Data.
	 * @param int[] $divisor Generator.
	 * @return int[]
	 */
	private static function rs_remainder( $data, $divisor ) {
		$result = array_fill( 0, count( $divisor ), 0 );
		foreach ( $data as $b ) {
			$factor   = $b ^ array_shift( $result );
			$result[] = 0;
			foreach ( $divisor as $i => $coef ) {
				$result[ $i ] ^= self::gf_mul( $coef, $factor );
			}
		}
		return $result;
	}

	/**
	 * Multiplication in GF(2^8) modulo x^8 + x^4 + x^3 + x^2 + 1.
	 *
	 * @param int $x Factor.
	 * @param int $y Factor.
	 * @return int
	 */
	private static function gf_mul( $x, $y ) {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Matrix
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Draw everything and choose the best mask.
	 *
	 * @param int    $version   Version.
	 * @param string $ecl       Level.
	 * @param int[]  $codewords All codewords.
	 * @return bool[][]
	 */
	private function build( $version, $ecl, $codewords ) {
		$this->size        = $version * 4 + 17;
		$this->modules     = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
		$this->is_function = $this->modules;

		$this->draw_function_patterns( $version, $ecl );
		$this->draw_codewords( $codewords );

		$best       = 0;
		$best_score = PHP_INT_MAX;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$this->apply_mask( $mask );
			$this->draw_format( $ecl, $mask );
			$score = $this->penalty();
			if ( $score < $best_score ) {
				$best       = $mask;
				$best_score = $score;
			}
			$this->apply_mask( $mask ); // XOR again = undo.
		}
		$this->apply_mask( $best );
		$this->draw_format( $ecl, $best );
		return $this->modules;
	}

	/**
	 * Set a function module.
	 *
	 * @param int  $x    Column.
	 * @param int  $y    Row.
	 * @param bool $dark Dark.
	 */
	private function set( $x, $y, $dark ) {
		$this->modules[ $y ][ $x ]     = (bool) $dark;
		$this->is_function[ $y ][ $x ] = true;
	}

	/**
	 * Finder, timing and alignment patterns, version info, reserved format area.
	 *
	 * @param int    $version Version.
	 * @param string $ecl     Level.
	 */
	private function draw_function_patterns( $version, $ecl ) {
		$size = $this->size;
		for ( $i = 0; $i < $size; $i++ ) {
			$this->set( 6, $i, 0 === $i % 2 );
			$this->set( $i, 6, 0 === $i % 2 );
		}
		foreach ( array( array( 3, 3 ), array( $size - 4, 3 ), array( 3, $size - 4 ) ) as $c ) {
			for ( $dy = -4; $dy <= 4; $dy++ ) {
				for ( $dx = -4; $dx <= 4; $dx++ ) {
					$x = $c[0] + $dx;
					$y = $c[1] + $dy;
					if ( $x >= 0 && $x < $size && $y >= 0 && $y < $size ) {
						$dist = max( abs( $dx ), abs( $dy ) );
						$this->set( $x, $y, 2 !== $dist && 4 !== $dist );
					}
				}
			}
		}

		$pos = self::alignment_positions( $version );
		$n   = count( $pos );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = 0; $j < $n; $j++ ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $n - 1 === $j ) || ( $n - 1 === $i && 0 === $j ) ) {
					continue; // Overlaps a finder pattern.
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$ring = max( abs( $dx ), abs( $dy ) );
						$this->set( $pos[ $i ] + $dx, $pos[ $j ] + $dy, 1 !== $ring );
					}
				}
			}
		}

		$this->draw_format( $ecl, 0 ); // Reserve the area; drawn properly after masking.

		if ( $version >= 7 ) {
			$rem = $version;
			for ( $i = 0; $i < 12; $i++ ) {
				$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
			}
			$bits = $version << 12 | $rem;
			for ( $i = 0; $i < 18; $i++ ) {
				$bit = ( $bits >> $i ) & 1;
				$a   = $size - 11 + $i % 3;
				$b   = intdiv( $i, 3 );
				$this->set( $a, $b, $bit );
				$this->set( $b, $a, $bit );
			}
		}
	}

	/**
	 * Centre positions of alignment patterns.
	 *
	 * @param int $version Version.
	 * @return int[]
	 */
	private static function alignment_positions( $version ) {
		if ( 1 === $version ) {
			return array();
		}
		$num    = intdiv( $version, 7 ) + 2;
		$step   = intdiv( $version * 8 + $num * 3 + 5, $num * 4 - 4 ) * 2;
		$result = array();
		for ( $i = 0, $pos = $version * 4 + 10; $i < $num - 1; $i++, $pos -= $step ) {
			array_unshift( $result, $pos );
		}
		array_unshift( $result, 6 );
		return $result;
	}

	/**
	 * Format information (level + mask), both copies, plus the dark module.
	 *
	 * @param string $ecl  Level.
	 * @param int    $mask Mask.
	 */
	private function draw_format( $ecl, $mask ) {
		$data = self::ECL_BITS[ $ecl ] << 3 | $mask;
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( $data << 10 | $rem ) ^ 0x5412;
		$bit  = static function ( $i ) use ( $bits ) {
			return ( $bits >> $i ) & 1;
		};
		$size = $this->size;

		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set( 8, $i, $bit( $i ) );
		}
		$this->set( 8, 7, $bit( 6 ) );
		$this->set( 8, 8, $bit( 7 ) );
		$this->set( 7, 8, $bit( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set( 14 - $i, 8, $bit( $i ) );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set( $size - 1 - $i, 8, $bit( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set( 8, $size - 15 + $i, $bit( $i ) );
		}
		$this->set( 8, $size - 8, true );
	}

	/**
	 * Place the codewords in the zigzag pattern.
	 *
	 * @param int[] $codewords Codewords.
	 */
	private function draw_codewords( $codewords ) {
		$size  = $this->size;
		$total = count( $codewords ) * 8;
		$i     = 0;
		for ( $right = $size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			$upward = 0 === ( ( $right + 1 ) & 2 );
			for ( $vert = 0; $vert < $size; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x = $right - $j;
					$y = $upward ? $size - 1 - $vert : $vert;
					if ( ! $this->is_function[ $y ][ $x ] && $i < $total ) {
						$this->modules[ $y ][ $x ] = 1 === ( ( $codewords[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
						++$i;
					}
				}
			}
		}
	}

	/**
	 * XOR a mask pattern onto the data modules.
	 *
	 * @param int $mask 0–7.
	 */
	private function apply_mask( $mask ) {
		$size = $this->size;
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( $this->is_function[ $y ][ $x ] ) {
					continue;
				}
				switch ( $mask ) {
					case 0:
						$flip = 0 === ( $x + $y ) % 2;
						break;
					case 1:
						$flip = 0 === $y % 2;
						break;
					case 2:
						$flip = 0 === $x % 3;
						break;
					case 3:
						$flip = 0 === ( $x + $y ) % 3;
						break;
					case 4:
						$flip = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
						break;
					case 5:
						$flip = 0 === $x * $y % 2 + $x * $y % 3;
						break;
					case 6:
						$flip = 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2;
						break;
					default:
						$flip = 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2;
				}
				if ( $flip ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	/**
	 * Penalty score of the current matrix (lower is better).
	 *
	 * @return int
	 */
	private function penalty() {
		$size  = $this->size;
		$score = 0;
		$dark  = 0;
		$lines = array();
		for ( $y = 0; $y < $size; $y++ ) {
			$row = '';
			$col = '';
			for ( $x = 0; $x < $size; $x++ ) {
				$row  .= $this->modules[ $y ][ $x ] ? '1' : '0';
				$col  .= $this->modules[ $x ][ $y ] ? '1' : '0';
				$dark += $this->modules[ $y ][ $x ] ? 1 : 0;
				// Rule 2: 2×2 blocks of one colour.
				if ( $x < $size - 1 && $y < $size - 1 ) {
					$c = $this->modules[ $y ][ $x ];
					if ( $c === $this->modules[ $y ][ $x + 1 ] && $c === $this->modules[ $y + 1 ][ $x ] && $c === $this->modules[ $y + 1 ][ $x + 1 ] ) {
						$score += 3;
					}
				}
			}
			$lines[] = $row;
			$lines[] = $col;
		}
		foreach ( $lines as $line ) {
			// Rule 1: runs of 5+ equal modules.
			if ( preg_match_all( '/0{5,}|1{5,}/', $line, $m ) ) {
				foreach ( $m[0] as $run ) {
					$score += strlen( $run ) - 2;
				}
			}
			// Rule 3: finder-like patterns.
			$padded = '0000' . $line . '0000';
			$score += 40 * ( substr_count( $padded, '00001011101' ) + substr_count( $padded, '10111010000' ) );
		}
		// Rule 4: balance of dark and light.
		$total  = $size * $size;
		$k      = (int) ceil( abs( $dark * 20 - $total * 10 ) / $total ) - 1;
		$score += max( 0, $k ) * 10;
		return $score;
	}
}

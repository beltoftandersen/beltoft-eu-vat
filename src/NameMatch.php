<?php
/**
 * Compares the company name a customer entered with the name the VAT register holds.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Pure comparison, no WordPress state except the two filters in matches(). Both names are
 * normalised (lower case, accents and Nordic letters transliterated, punctuation removed, legal
 * forms and connecting words dropped), then compared in this order: equal, one contains the other
 * as whole words, the shorter name's words are all in the longer, a similarity above the
 * threshold (typos), and for a sole trader the billing person's name in either order.
 */
final class NameMatch {

	/** Similarity percent at or above which two names count as the same. */
	public const DEFAULT_THRESHOLD = 85;

	/**
	 * Legal forms, written as they look after normalise() has removed dots and slashes.
	 * Multi-word forms are listed as phrases.
	 */
	private const LEGAL_FORMS = array(
		'aps', 'as', 'ivs', 'ps', 'ks', 'is', 'ab', 'hb', 'kb', 'oy', 'oyj', 'asa', 'gmbh', 'ug', 'ag', 'kg', 'ohg',
		'ek', 'ev', 'mbh', 'gbr', 'se', 'bv', 'nv', 'vof', 'sarl', 'sas', 'sa', 'snc', 'eurl', 'sl', 'slu', 'srl',
		'spa', 'lda', 'spk', 'sro', 'doo', 'kft', 'zrt', 'ou', 'oue', 'uab', 'sia', 'ltd', 'limited', 'llc', 'inc',
		'plc', 'llp', 'eood', 'ood', 'ad', 'epe', 'ike',
	);

	/** Words that only join names ("Mueller und Co"). */
	private const CONNECTORS = array( 'and', 'und', 'og', 'och', 'et', 'en', 'y' );

	/**
	 * The comparison the plugin uses, with the site's own rule applied last.
	 *
	 * @param string               $entered    Company the customer entered.
	 * @param string               $registered Name the VAT register returned.
	 * @param array<string,string> $context    vat_number, country, company, first_name, last_name.
	 * @return array{matched:bool,reason:string}
	 */
	public static function matches( string $entered, string $registered, array $context = array() ): array {
		/**
		 * Filters the similarity percent (0-100) at which two names count as the same.
		 *
		 * @param int $percent Default 85.
		 */
		$threshold = max( 1, min( 100, (int) apply_filters( 'beltoft_eu_vat_name_similarity', self::DEFAULT_THRESHOLD ) ) );
		$people    = array();

		if ( '' !== trim( (string) ( $context['first_name'] ?? '' ) ) && '' !== trim( (string) ( $context['last_name'] ?? '' ) ) ) {
			$people[] = trim( $context['first_name'] ) . ' ' . trim( $context['last_name'] );
		}

		$result  = self::compare( $entered, $registered, $people, $threshold );
		$matches = (bool) apply_filters( 'beltoft_eu_vat_name_matches', $result['matched'], $entered, $registered, $context );

		if ( $matches !== $result['matched'] ) {
			$result = array(
				'matched' => $matches,
				'reason'  => $matches ? 'site rule' : 'mismatch',
			);
		}

		return $result;
	}

	/**
	 * Whether the names match, and why.
	 *
	 * @param string   $entered    Company name entered (may be empty).
	 * @param string   $registered Name from the register.
	 * @param string[] $people     Person names ("First Last") to try against a sole trader.
	 * @param int      $threshold  Similarity percent.
	 * @return array{matched:bool,reason:string}
	 */
	public static function compare( string $entered, string $registered, array $people = array(), int $threshold = self::DEFAULT_THRESHOLD ): array {
		$b = self::normalise( $registered );

		if ( '' === $b ) {
			return self::no( 'mismatch' );
		}

		$candidates = array();
		$a          = self::normalise( $entered );

		if ( '' !== $a ) {
			$candidates[] = $entered;
		}

		foreach ( $people as $person ) {
			if ( '' !== self::normalise( (string) $person ) ) {
				$candidates[] = (string) $person;
			}
		}

		// The company first: the reason names the first rule that agrees.
		if ( '' !== $a ) {
			$reason = self::company_reason( $a, $b, $threshold );

			if ( '' !== $reason ) {
				return array(
					'matched' => true,
					'reason'  => $reason,
				);
			}
		}

		// A sole trader: the register holds a person, in either order ("Jensen, Hans").
		foreach ( $candidates as $candidate ) {
			if ( self::person_matches( self::normalise( $candidate, false ), self::normalise( $registered, false ), $threshold ) ) {
				return array(
					'matched' => true,
					'reason'  => 'person name',
				);
			}
		}

		return self::no( 'mismatch' );
	}

	/**
	 * Lower case, transliterated, without punctuation, legal forms, connectors and a leading or
	 * trailing "the". A name made only of such words keeps them.
	 *
	 * @param string $name       Name.
	 * @param bool   $strip_legal Whether legal forms and connectors are dropped.
	 */
	public static function normalise( string $name, bool $strip_legal = true ): string {
		$text = mb_strtolower( trim( $name ), 'UTF-8' );
		$text = strtr(
			$text,
			array(
				'æ' => 'ae',
				'ø' => 'oe',
				'å' => 'aa',
				'ä' => 'ae',
				'ö' => 'oe',
				'ü' => 'ue',
				'ß' => 'ss',
				'œ' => 'oe',
				'&' => ' and ',
			)
		);
		$text = remove_accents( $text );
		$text = (string) preg_replace( '/\b([a-z])\/([a-z])\b/', '$1$2', $text );
		$text = str_replace( array( '.', "'", "\u{2019}", "\u{2018}", '`' ), '', $text );
		$text = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		if ( ! $strip_legal ) {
			return $text;
		}

		$plain = $text;
		$text  = trim( str_replace( ' sp z oo ', ' ', ' ' . $text . ' ' ) );
		$words = array_values( array_filter( explode( ' ', $text ), 'strlen' ) );
		$kept  = array();

		foreach ( $words as $word ) {
			if ( ! in_array( $word, self::LEGAL_FORMS, true ) && ! in_array( $word, self::CONNECTORS, true ) ) {
				$kept[] = $word;
			}
		}

		while ( $kept && 'the' === $kept[0] ) {
			array_shift( $kept );
		}

		while ( $kept && 'the' === end( $kept ) ) {
			array_pop( $kept );
		}

		$result = implode( ' ', $kept );

		return '' === $result ? $plain : $result;
	}

	/**
	 * Which company rule agrees, or an empty string.
	 *
	 * @param string $a         Normalised entered name.
	 * @param string $b         Normalised registered name.
	 * @param int    $threshold Similarity percent.
	 */
	private static function company_reason( string $a, string $b, int $threshold ): string {
		if ( $a === $b ) {
			return 'equal';
		}

		$short = strlen( $a ) <= strlen( $b ) ? $a : $b;
		$long  = $short === $a ? $b : $a;

		if ( false !== strpos( ' ' . $long . ' ', ' ' . $short . ' ' ) && strlen( $short ) >= 4 && self::has_word( $short, 3 ) ) {
			return 'contains';
		}

		$short_words = explode( ' ', $short );
		$long_words  = explode( ' ', $long );

		if ( ! array_diff( $short_words, $long_words ) && self::has_word( $short, 4 ) ) {
			return 'tokens';
		}

		$percent = self::similarity( $a, $b );

		return $percent >= $threshold ? 'similarity ' . (int) floor( $percent ) : '';
	}

	/**
	 * Whether a person name (two or more words) is the register's name in either order, with
	 * extra middle names or a typo allowed.
	 *
	 * @param string $person     Normalised, legal forms kept.
	 * @param string $registered Normalised, legal forms kept.
	 * @param int    $threshold  Similarity percent.
	 */
	private static function person_matches( string $person, string $registered, int $threshold ): bool {
		$p = array_values( array_filter( explode( ' ', $person ), 'strlen' ) );
		$r = array_values( array_filter( explode( ' ', $registered ), 'strlen' ) );

		if ( count( $p ) < 2 || count( $r ) < 2 ) {
			return false;
		}

		$short = count( $p ) <= count( $r ) ? $p : $r;
		$long  = $short === $p ? $r : $p;

		if ( ! array_diff( $short, $long ) ) {
			return true;
		}

		sort( $p );
		sort( $r );

		return self::similarity( implode( ' ', $p ), implode( ' ', $r ) ) >= $threshold;
	}

	/**
	 * Similarity in percent: the better of similar_text and the Levenshtein ratio.
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 */
	private static function similarity( string $a, string $b ): float {
		if ( '' === $a || '' === $b ) {
			return 0.0;
		}

		$percent = 0.0;
		similar_text( $a, $b, $percent );

		$longest = max( strlen( $a ), strlen( $b ) );
		$ratio   = 100 * ( 1 - levenshtein( $a, $b ) / $longest );

		return max( $percent, $ratio );
	}

	/**
	 * Whether a normalised name has a word of at least $length letters.
	 *
	 * @param string $name   Normalised name.
	 * @param int    $length Letters.
	 */
	private static function has_word( string $name, int $length ): bool {
		foreach ( explode( ' ', $name ) as $word ) {
			if ( mb_strlen( $word, 'UTF-8' ) >= $length ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A refusal.
	 *
	 * @param string $reason Reason.
	 * @return array{matched:bool,reason:string}
	 */
	private static function no( string $reason ): array {
		return array(
			'matched' => false,
			'reason'  => $reason,
		);
	}
}

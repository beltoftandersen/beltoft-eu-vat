<?php
namespace BeltoftEuVat\Tests;

/**
 * The summary refreshes only through WooCommerce's own update_checkout, once, after a real edit.
 */
class JavaScriptTest extends TestCase {

	private function code(): string {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/checkout.js' );
		$js = (string) preg_replace( '#/\*.*?\*/#s', '', $js );

		return (string) preg_replace( '#//[^\n]*#', '', $js );
	}

	public function test_exactly_one_update_checkout_trigger() {
		$this->assertSame( 1, substr_count( $this->code(), 'update_checkout' ) );
	}

	public function test_nothing_is_asked_while_typing() {
		$code = $this->code();

		// The only timer is the short wait after a change of the company or billing name, never the VAT number.
		$this->assertSame( 1, substr_count( $code, 'setTimeout' ) );
		$this->assertMatchesRegularExpression( "#var nameFields = '[^']*billing_company[^']*';.*?setTimeout#s", $code );
		$this->assertStringNotContainsString( 'timer', $code );

		// The input handler drops an unfinished check and the old status line, and checks only a number that is full
		// for its country (no longer number could still be valid).
		preg_match( "#on\( 'input', field, function \( event \) \{(.*?)\n\t\} \);#s", $code, $match );
		$this->assertNotEmpty( $match );
		$this->assertSame( 1, substr_count( $match[1], 'check(' ) );
		$this->assertMatchesRegularExpression( '#if \( full\(\) && signature\(\) !== last \) \{\s*check\( false \);#', $match[1] );
		$this->assertStringNotContainsString( 'refresh(', $match[1] );
		$this->assertStringContainsString( 'xhr.abort()', $match[1] );
	}

	public function test_the_check_starts_on_leaving_the_field_or_enter() {
		$code = $this->code();

		$this->assertMatchesRegularExpression( "#on\( 'focusout change', field, function \(\) \{\s*if \( signature\(\) !== last \) \{\s*check\( false \);#s", $code );
		$this->assertMatchesRegularExpression( "#on\( 'keydown', field, function \( event \) \{\s*if \( 13 !== event\.which \) \{\s*return;\s*\}\s*event\.preventDefault\(\);\s*if \( signature\(\) !== last \) \{\s*check\( false \);#s", $code );
	}

	public function test_a_check_dropped_by_typing_is_asked_again() {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/checkout.js' );

		// Dropping an unanswered check must forget what it asked about, or a name corrected just before stays unjudged.
		$this->assertMatchesRegularExpression( '/xhr\.abort\(\);\s*xhr = null;\s*(?:\/\/[^\n]*\s*)*last = null;/', $js );
	}

	public function test_an_incomplete_number_is_not_sent() {
		$code = $this->code();

		$this->assertMatchesRegularExpression( "#if \( ! number \|\| ! complete\(\) \) \{.*?return;\s*\}\s*show\( strings\.checking#s", $code );
		$this->assertStringContainsString( 'data.formats', $code );
		$this->assertStringContainsString( 'if ( applied ) {', $code );
	}

	public function test_the_refresh_is_asked_once_after_a_check_and_never_from_a_bare_load() {
		$code = $this->code();

		// The single trigger sits in refresh(), which only the check calls (after the answer, or when there is nothing to ask).
		$this->assertSame( 2, substr_count( $code, 'refresh();' ) );
		$this->assertMatchesRegularExpression( "#\.always\( function \(\) \{\s*if \( id === seq \) \{\s*xhr = null;\s*refresh\(\);#s", $code );
		$this->assertDoesNotMatchRegularExpression( '#\$\(\s*function#', $code );
		$this->assertStringNotContainsString( '.ready(', $code );
		$this->assertStringNotContainsString( 'blockUI', $code );
		$this->assertStringNotContainsString( 'updated_checkout', $code );

		// The only load-time work: the display toggle and a check of a number already in the field.
		$this->assertMatchesRegularExpression( "#toggleWrapper\(\);\s*if \( 'checkout' === data\.context && normalised\(\) && applies\(\) \) \{\s*check\( false \);\s*\}\s*\}\( jQuery \) \);\s*$#", $code );
	}

	public function test_the_status_line_is_written_as_text_and_is_live() {
		$code = $this->code();

		$this->assertStringContainsString( 'createTextNode', $code );
		$this->assertStringNotContainsString( '.html(', $code );
		$this->assertStringContainsString( 'aria-live="polite"', $code );
		$this->assertStringContainsString( "'abort' === status", $code );
	}

	public function test_check_again_only_comes_with_retry() {
		$code = $this->code();

		$this->assertStringContainsString( 'if ( retry ) {', $code );
		$this->assertStringContainsString( 'beltoft-eu-vat-again', $code );
	}

	public function test_css_has_no_border_radius() {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/checkout.css' );

		$this->assertStringNotContainsString( 'radius', $css );
	}

	public function test_no_em_dash_anywhere_in_the_plugin() {
		$root = dirname( __DIR__ );
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src' ) );
		$files = array( $root . '/beltoft-eu-vat.php', $root . '/assets/checkout.js', $root . '/assets/checkout.css' );

		foreach ( $it as $file ) {
			if ( $file->isFile() ) {
				$files[] = $file->getPathname();
			}
		}

		$files[] = $root . '/readme.txt';

		foreach ( $files as $file ) {
			$text = (string) file_get_contents( $file );

			$this->assertStringNotContainsString( "\u{2014}", $text, $file );
			$this->assertStringNotContainsString( "\u{00B7}", $text, $file );
			$this->assertStringNotContainsString( '&middot;', $text, $file );
		}
	}
}

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

	public function test_the_trigger_is_debounced_inside_the_input_handler_for_real_input_only() {
		$code = $this->code();

		$this->assertMatchesRegularExpression( "#on\( 'input', field, function \( event \) \{\s*if \( ! event\.originalEvent \) \{\s*return;\s*\}.*setTimeout\(.*trigger\( 'update_checkout' \).*\}, 600 \)#s", $code );
	}

	public function test_no_load_time_trigger_and_no_own_ajax_or_blocking() {
		$code = $this->code();

		$this->assertDoesNotMatchRegularExpression( '#\$\(\s*function#', $code );
		$this->assertStringNotContainsString( '.ready(', $code );
		$this->assertStringNotContainsString( 'ajax', strtolower( $code ) );
		$this->assertStringNotContainsString( 'blockUI', $code );
		$this->assertStringNotContainsString( 'updated_checkout', $code );

		// The only statements at the top level of the wrapper: declarations, two bindings and the display toggle.
		$this->assertMatchesRegularExpression( "#toggleWrapper\(\);\s*\}\( jQuery \) \);\s*$#", $code );
		$top = (string) preg_replace( '#\$\( document\.body \)\.on\( \'input\'.*?\}, 600 \);\s*\} \);#s', '', $code );
		$this->assertStringNotContainsString( 'update_checkout', $top );
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

		foreach ( $files as $file ) {
			$this->assertStringNotContainsString( "\u{2014}", (string) file_get_contents( $file ), $file );
		}
	}
}

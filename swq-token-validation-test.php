<?php
declare(strict_types=1);

define( 'ABSPATH', '/' );
$GLOBALS['filters'] = [];
function add_filter( ...$args ): void { $GLOBALS['filters'][] = $args; }
function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}

require __DIR__ . '/swq-glm52-translation-tuning.php';

function invoke_private( string $method, ...$args ) {
	$reflection = new ReflectionMethod( 'SWQ_GLM52_Translation_Tuning', $method );
	return $reflection->invoke( null, ...$args );
}

function check( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$name}\n" );
		exit( 1 );
	}
	echo "PASS: {$name}\n";
}

$a = 'SWQSTRUCT000001END';
$b = 'SWQINLINE000001END';
$c = 'SWQSTRUCT000002END';
$expected = [ $a, $b, $c ];
$source = $a . "\n" . $b . "\n" . $c;
$map = [ $b => '<code>curl</code>' ];

check( count( $GLOBALS['filters'] ) === 3, 'MU Plugin boot registers all filters' );
check( true === invoke_private( 'validate_protected_tokens', $source, $expected, $source, $map, $source ), 'valid count, uniqueness, and order' );

$recoverable = $a . "\n" . $c;
$recovered = invoke_private( 'recover_uniquely_located_missing_token', $recoverable, $source, $expected );
check( str_contains( $recovered, $b ), 'single uniquely located token is recovered' );
check( true === invoke_private( 'validate_protected_tokens', $recovered, $expected, $source, $map, $recovered ), 'recovered result passes strict validation' );

$prose_source = $a . '<p>add ' . $b . ' here</p>' . $c;
$prose_missing = $a . '<p>add here</p>' . $c;
check(
	$prose_missing === invoke_private( 'recover_uniquely_located_missing_token', $prose_missing, $prose_source, $expected ),
	'ambiguous in-prose location safely fails without insertion'
);

$extra = $source . 'SWQBLOCK000009END';
$error = invoke_private( 'validate_protected_tokens', $extra, $expected, $source, $map, $extra );
check( is_wp_error( $error ) && str_contains( $error->get_error_message(), 'extra_tokens=' ), 'extra token fails with diagnostics' );

$renamed = str_replace( $b, 'SWQINLINE000099END', $source );
$error = invoke_private( 'validate_protected_tokens', $renamed, $expected, $source, $map, $renamed );
check( is_wp_error( $error ) && str_contains( $error->get_error_message(), $b ), 'renamed token fails and identifies missing token' );

$duplicate = $source . $b;
check( is_wp_error( invoke_private( 'validate_protected_tokens', $duplicate, $expected, $source, $map, $duplicate ) ), 'duplicate token fails uniqueness' );

$reordered = $c . $b . $a;
check( is_wp_error( invoke_private( 'validate_protected_tokens', $reordered, $expected, $source, $map, $reordered ) ), 'fixed token reordering fails' );

$sample = '<!-- wp:paragraph --><p>Use <code>curl</code>.</p><!-- /wp:paragraph -->';
$protected = invoke_private( 'protect_content', $sample );
check( is_array( $protected ) && 3 === count( $protected['map'] ), 'content protection creates structural and inline tokens' );
$restored = str_replace( array_keys( $protected['map'] ), array_values( $protected['map'] ), $protected['payload'] );
check( $sample === $restored, 'protected content restores byte-for-byte' );

$diagnostic = json_decode( (string) file_get_contents( '/tmp/swq-glm52-token-validation-summary.json' ), true );
$required = [ 'expected_count', 'actual_count', 'missing_tokens', 'extra_tokens', 'fixed_order', 'first_mismatch_index', 'previous_expected_token', 'next_expected_token', 'raw_response_file', 'diagnostic_file', 'source_context', 'response_context' ];
check( [] === array_diff( $required, array_keys( $diagnostic ) ), 'diagnostic JSON contains all required fields' );
check( 0600 === ( fileperms( '/tmp/swq-glm52-token-validation-summary.json' ) & 0777 ), 'diagnostic permissions are 0600' );

echo "ALL TESTS PASSED\n";

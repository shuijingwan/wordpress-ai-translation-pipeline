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

function invoke_tail_method( string $method, ...$args ) {
	$reflection = new ReflectionMethod( 'SWQ_GLM52_Translation_Tuning', $method );
	return $reflection->invoke( null, ...$args );
}

function tail_token( string $type, int $number ): string {
	return sprintf( 'SWQ%s%06dEND', $type, $number );
}

function tail_payload( array $tokens ): string {
	return implode( "\n<p>translated</p>\n", $tokens );
}

function tail_map( array $tokens ): array {
	$map = [];
	foreach ( $tokens as $token ) {
		$map[ $token ] = '<!-- protected ' . $token . ' -->';
	}
	return $map;
}

function tail_check( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$name}\n" );
		exit( 1 );
	}
	echo "PASS: {$name}\n";
}

function tail_repair( string $translated, array $expected, ?array $map = null ): array {
	return invoke_tail_method(
		'repair_missing_struct_token_tail',
		$translated,
		$expected,
		$map ?? tail_map( $expected )
	);
}

$s455 = tail_token( 'STRUCT', 455 );
$s456 = tail_token( 'STRUCT', 456 );
$s457 = tail_token( 'STRUCT', 457 );
$s458 = tail_token( 'STRUCT', 458 );
$extra = tail_token( 'STRUCT', 999 );

$expected = [ $s455, $s456, $s457, $s458 ];
$valid = tail_payload( $expected );
$result = tail_repair( $valid, $expected );
tail_check( false === $result['attempted'] && $valid === $result['translated'], 'A valid result does not trigger repair' );

$missing_one = tail_payload( [ $s455, $s456, $s457 ] ) . '</blockquote>';
$result = tail_repair( $missing_one, $expected );
tail_check( true === $result['attempted'], 'B missing final STRUCT token triggers repair' );
tail_check( $missing_one . "\n" . $s458 === $result['translated'], 'B final STRUCT token is appended at the output tail' );
tail_check(
	true === invoke_tail_method( 'validate_protected_tokens', $result['translated'], $expected, $valid, tail_map( $expected ), $result['translated'] ),
	'B repaired result passes the complete strict validator'
);

$missing_two = tail_payload( [ $s455, $s456 ] );
$result = tail_repair( $missing_two, $expected );
tail_check( true === $result['attempted'], 'C missing two trailing STRUCT tokens triggers repair' );
tail_check(
	str_ends_with( $result['translated'], $s457 . "\n" . $s458 ),
	'C trailing STRUCT tokens are appended in expected order'
);
tail_check(
	true === invoke_tail_method( 'validate_protected_tokens', $result['translated'], $expected, $valid, tail_map( $expected ), $result['translated'] ),
	'C repaired result passes the complete strict validator'
);

$result = tail_repair( tail_payload( [ $s455, $s457, $s458 ] ), $expected );
tail_check( false === $result['attempted'], 'D missing middle STRUCT token is rejected' );

$result = tail_repair( tail_payload( [ $s455, $s456, $s457, $extra ] ), $expected );
tail_check( false === $result['attempted'] && 'extra_tokens_present' === $result['reason'], 'E extra token prevents repair' );

$result = tail_repair( tail_payload( [ $s455, $s456, $s457, $s457 ] ), $expected );
tail_check( false === $result['attempted'] && 'duplicate_tokens_present' === $result['reason'], 'F duplicate token prevents repair' );

$result = tail_repair( tail_payload( [ $s456, $s455, $s457 ] ), $expected );
tail_check( false === $result['attempted'] && 'actual_fixed_sequence_not_expected_prefix' === $result['reason'], 'G fixed token order error prevents repair' );

$inline = tail_token( 'INLINE', 1 );
$non_struct_tail = [ $s455, $s456, $inline ];
$result = tail_repair( tail_payload( [ $s455, $s456 ] ), $non_struct_tail );
tail_check( false === $result['attempted'] && 'missing_non_struct_token' === $result['reason'], 'H non-STRUCT tail token prevents repair' );

$inline_1   = tail_token( 'INLINE', 59 );
$inline_2   = tail_token( 'INLINE', 60 );
$struct_a   = tail_token( 'STRUCT', 708 );
$struct_b   = tail_token( 'STRUCT', 709 );
$struct_c   = tail_token( 'STRUCT', 710 );
$struct_tail = tail_token( 'STRUCT', 942 );
$inline_reorder_expected = [ $struct_a, $inline_1, $inline_2, $struct_b, $struct_tail ];
$inline_reorder_actual   = tail_payload( [ $struct_a, $inline_2, $inline_1, $struct_b ] );
$result = tail_repair( $inline_reorder_actual, $inline_reorder_expected );
tail_check( true === $result['attempted'], 'INLINE reordering with only a trailing STRUCT missing triggers repair' );
tail_check(
	true === invoke_tail_method( 'validate_protected_tokens', $result['translated'], $inline_reorder_expected, tail_payload( $inline_reorder_expected ), tail_map( $inline_reorder_expected ), $result['translated'] ),
	'INLINE reordering repair passes completeness and fixed-order validation'
);

$fixed_middle_expected = [ $struct_a, $inline_1, $struct_b, $struct_c ];
$result = tail_repair( tail_payload( [ $struct_a, $inline_1, $struct_c ] ), $fixed_middle_expected );
tail_check( false === $result['attempted'], 'Fixed token missing from the middle is rejected' );

$fixed_reorder_expected = [ $struct_a, $struct_b, $struct_c, $struct_tail ];
$result = tail_repair( tail_payload( [ $struct_a, $struct_c, $struct_b ] ), $fixed_reorder_expected );
tail_check( false === $result['attempted'] && 'actual_fixed_sequence_not_expected_prefix' === $result['reason'], 'Fixed token reordering is rejected' );

$result = tail_repair( tail_payload( [ $struct_a, $inline_2, $struct_b ] ), $inline_reorder_expected );
tail_check( false === $result['attempted'] && 'missing_non_struct_token' === $result['reason'], 'INLINE reordering with a missing INLINE token is rejected' );

$unknown_extra = tail_token( 'HTML', 999 );
$result = tail_repair( tail_payload( [ $struct_a, $inline_2, $inline_1, $struct_b, $unknown_extra ] ), $inline_reorder_expected );
tail_check( false === $result['attempted'] && 'extra_tokens_present' === $result['reason'], 'Unknown extra token prevents repair despite INLINE reordering' );

$result = tail_repair( tail_payload( [ $struct_a, $inline_2, $inline_1, $inline_1, $struct_b ] ), $inline_reorder_expected );
tail_check( false === $result['attempted'] && 'duplicate_tokens_present' === $result['reason'], 'Duplicate INLINE token prevents repair' );

$map_without_tail = tail_map( $expected );
unset( $map_without_tail[ $s458 ] );
$result = tail_repair( $missing_one, $expected, $map_without_tail );
tail_check( false === $result['attempted'] && 'protected_content_missing' === $result['reason'], 'missing protected_content prevents repair' );

$result = tail_repair( $missing_one, $expected );
$damaged_after_repair = $result['translated'] . "\n" . $extra;
$revalidation = invoke_tail_method(
	'validate_protected_tokens',
	$damaged_after_repair,
	$expected,
	$valid,
	tail_map( $expected ),
	$damaged_after_repair
);
tail_check( is_wp_error( $revalidation ), 'I failed strict revalidation remains a failure after a repair attempt' );

$guard = 'SWQTRANSLATIONTAILGUARDEND';

$guard_result = invoke_tail_method( 'remove_translation_tail_guard', $valid . "\n" . $guard );
tail_check(
	'present' === $guard_result['translation_tail_guard_status']
	&& 1 === $guard_result['translation_tail_guard_count']
	&& $valid === $guard_result['translated'],
	'Guard present at the output tail is diagnosed and removed'
);
tail_check(
	true === invoke_tail_method( 'validate_protected_tokens', $guard_result['translated'], $expected, $valid, tail_map( $expected ), $guard_result['translated'] ),
	'Guard present with complete protected tokens succeeds'
);

$guard_result = invoke_tail_method( 'remove_translation_tail_guard', $valid );
tail_check(
	'missing' === $guard_result['translation_tail_guard_status']
	&& 0 === $guard_result['translation_tail_guard_count'],
	'Guard missing is diagnosed'
);
tail_check(
	true === invoke_tail_method( 'validate_protected_tokens', $guard_result['translated'], $expected, $valid, tail_map( $expected ), $guard_result['translated'] ),
	'Guard missing with complete protected tokens still succeeds'
);

$guard_result = invoke_tail_method( 'remove_translation_tail_guard', $missing_one . "\n" . $guard );
$result = tail_repair( $guard_result['translated'], $expected );
tail_check(
	'present' === $guard_result['translation_tail_guard_status']
	&& true === $result['attempted']
	&& true === invoke_tail_method( 'validate_protected_tokens', $result['translated'], $expected, $valid, tail_map( $expected ), $result['translated'] ),
	'Guard present does not prevent the existing one-token tail repair'
);

$missing_middle = tail_payload( [ $s455, $s457, $s458 ] );
$guard_result = invoke_tail_method( 'remove_translation_tail_guard', $missing_middle );
tail_check(
	'missing' === $guard_result['translation_tail_guard_status']
	&& is_wp_error( invoke_tail_method( 'validate_protected_tokens', $guard_result['translated'], $expected, $valid, tail_map( $expected ), $guard_result['translated'] ) ),
	'Guard missing cannot bypass a real protected-token validation failure'
);

$guard_result = invoke_tail_method( 'remove_translation_tail_guard', $valid . "\n" . $guard . "\n" . $guard );
tail_check(
	'duplicate' === $guard_result['translation_tail_guard_status']
	&& 2 === $guard_result['translation_tail_guard_count']
	&& ! str_contains( $guard_result['translated'], $guard ),
	'Duplicate Guards are diagnosed and fully removed'
);

$guard_result = invoke_tail_method( 'remove_translation_tail_guard', $s455 . "\n" . $guard . "\n" . tail_payload( [ $s456, $s457, $s458 ] ) );
tail_check(
	'misplaced' === $guard_result['translation_tail_guard_status']
	&& 1 === $guard_result['translation_tail_guard_count']
	&& ! str_contains( $guard_result['translated'], $guard ),
	'Misplaced Guard is diagnosed and fully removed'
);

$outside_wrapper = '<slytranslate-output>' . $valid . '</slytranslate-output>' . $guard;
$guard_result = invoke_tail_method(
	'remove_translation_tail_guard',
	invoke_tail_method( 'unwrap_model_output', $outside_wrapper )
);
tail_check(
	'misplaced' === $guard_result['translation_tail_guard_status']
	&& ! str_contains( $guard_result['translated'], $guard ),
	'Guard after the output wrapper is diagnosed as misplaced and removed'
);

$restored = str_replace(
	array_keys( tail_map( $expected ) ),
	array_values( tail_map( $expected ) ),
	invoke_tail_method( 'remove_translation_tail_guard', $valid . "\n" . $guard )['translated']
);
tail_check( ! str_contains( $restored, $guard ), 'Final restoration result contains no Guard' );

$prompt = invoke_tail_method( 'build_content_prompt', 'en' );
tail_check(
	str_contains( $prompt, '输出 HTML closing tag 不代表任务已经完成' )
	&& str_contains( $prompt, $guard )
	&& str_contains( $prompt, '</slytranslate-output> 之前'),
	'Prompt contains the tail-completeness and Guard rules'
);

echo "ALL TESTS PASSED\n";

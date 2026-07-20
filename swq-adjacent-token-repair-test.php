<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

function add_filter( ...$args ): void {}

require __DIR__ . '/sources/mu-plugins/swq-glm52-translation-tuning.php';

function invoke_token_method( string $method, ...$args ) {
	$reflection = new ReflectionMethod( 'SWQ_GLM52_Translation_Tuning', $method );
	return $reflection->invoke( null, ...$args );
}

function token( string $type, int $number ): string {
	return sprintf( 'SWQ%s%06dEND', $type, $number );
}

function payload( array $tokens ): string {
	return implode( "\n<p>translated text</p>\n", $tokens );
}

function check_case( bool $condition, string $name ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$name}\n" );
		exit( 1 );
	}

	echo "PASS: {$name}\n";
}

function repair( string $translated, array $expected ): string {
	return invoke_token_method( 'repair_uniquely_misnumbered_adjacent_token', $translated, $expected );
}

function check_repaired( array $expected, array $actual, string $name ): void {
	$translated = payload( $actual );
	$wanted     = payload( $expected );
	$repaired   = repair( $translated, $expected );

	check_case( $wanted === $repaired, $name );
	check_case(
		true === invoke_token_method( 'protected_token_sequence_is_strictly_valid', $repaired, $expected ),
		$name . ' passes full strict validation'
	);
}

function check_rejected( array $expected, array $actual, string $name ): void {
	$translated = payload( $actual );
	check_case( $translated === repair( $translated, $expected ), $name );
}

$s450 = token( 'STRUCT', 450 );
$s451 = token( 'STRUCT', 451 );
$s452 = token( 'STRUCT', 452 );
$s453 = token( 'STRUCT', 453 );
$s454 = token( 'STRUCT', 454 );

check_repaired(
	[ $s451, $s452, $s453 ],
	[ $s451, $s451, $s453 ],
	'previous-number duplicate is repaired'
);

check_repaired(
	[ $s451, $s452, $s453 ],
	[ $s451, $s453, $s453 ],
	'next-number duplicate is repaired'
);

$occurrence_input = $s451 . "\nkeep first\n" . $s451 . "\nkeep tail\n" . $s453;
$occurrence_wanted = $s451 . "\nkeep first\n" . $s452 . "\nkeep tail\n" . $s453;
check_case(
	$occurrence_wanted === repair( $occurrence_input, [ $s451, $s452, $s453 ] ),
	'only the mismatching occurrence is replaced'
);

check_rejected(
	[ $s451, $s453, $s454 ],
	[ $s451, $s451, $s454 ],
	'non-adjacent number is rejected'
);

$h451 = token( 'HTML', 451 );
check_rejected(
	[ $h451, $s452, $s453 ],
	[ $h451, $h451, $s453 ],
	'different token types are rejected'
);

$i451 = token( 'INLINE', 451 );
$i452 = token( 'INLINE', 452 );
$i453 = token( 'INLINE', 453 );
check_rejected(
	[ $i451, $i452, $i453 ],
	[ $i451, $i451, $i453 ],
	'INLINE token repair is rejected'
);

check_rejected(
	[ $s451, $s452, $s453 ],
	[ $s452, $s451, $s453 ],
	'multiple mismatches are rejected'
);

check_rejected(
	[ $s450, $s451, $s452, $s453 ],
	[ $s450, $s450, $s453, $s453 ],
	'multiple missing tokens are rejected'
);

check_rejected(
	[ $s450, $s451, $s452, $s453 ],
	[ $s450, $s450, $s452, $s452 ],
	'multiple duplicate tokens are rejected'
);

check_rejected(
	[ $s450, $s451, $s452, $s453 ],
	[ $s450, $s450, $s450, $s453 ],
	'a token repeated three times is rejected'
);

check_rejected(
	[ $s451, $s452, $s453 ],
	[ $s451, $s453 ],
	'different expected and actual counts are rejected'
);

$s999 = token( 'STRUCT', 999 );
check_rejected(
	[ $s451, $s452, $s453 ],
	[ $s451, $s999, $s453 ],
	'unknown strict token is rejected'
);

check_rejected(
	[ $s451, $s452, $s453 ],
	[ $s452, $s452, $s453 ],
	'mismatch at sequence start is rejected'
);

check_rejected(
	[ $s451, $s452, $s453 ],
	[ $s451, $s452, $s452 ],
	'mismatch at sequence end is rejected'
);

check_rejected(
	[ $s450, $s451, $s452, $s453 ],
	[ $s451, $s450, $s450, $s453 ],
	'inconsistent previous context is rejected'
);

check_rejected(
	[ $s450, $s451, $s452, $s453 ],
	[ $s450, $s450, $s453, $s452 ],
	'inconsistent next context is rejected'
);

check_rejected(
	[ $s451, $s452, $s453, $s454 ],
	[ $s451, $s451, $s454, $s453 ],
	'a candidate that cannot pass strict fixed order is rejected'
);
$strict_failure = payload( [ $s451, $s451, $s454, $s453 ] );
check_case(
	false === invoke_token_method(
		'protected_token_sequence_is_strictly_valid',
		$strict_failure,
		[ $s451, $s452, $s453, $s454 ]
	),
	'full strict validation remains fail-closed for the rejected candidate'
);

$valid = payload( [ $s451, $s452, $s453 ] );
check_case( $valid === repair( $valid, [ $s451, $s452, $s453 ] ), 'valid sequence remains unchanged' );
check_case(
	true === invoke_token_method( 'protected_token_sequence_is_strictly_valid', $valid, [ $s451, $s452, $s453 ] ),
	'valid unchanged sequence still passes strict validation'
);

echo "ALL TESTS PASSED\n";

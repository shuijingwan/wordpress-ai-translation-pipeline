<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

function add_filter(): void {}

function wp_json_encode( $value, int $flags = 0 ) {
	return json_encode( $value, $flags );
}

class WP_Error {
	public function __construct(
		private string $code,
		private string $message = ''
	) {}

	public function get_error_code(): string {
		return $this->code;
	}
}

require __DIR__ . '/swq-glm52-translation-tuning-draft-only.php';

$reflection = new ReflectionClass( 'SWQ_GLM52_Translation_Tuning' );
$normalize  = $reflection->getMethod( 'normalize_unique_protected_tokens' );
$validate   = $reflection->getMethod( 'validate_protected_tokens' );

$tests = [
	'zero contraction is repaired' => static function () use ( $normalize, $validate ): bool {
		$expected   = [ 'SWQSTRUCT000017END', 'SWQSTRUCT000018END', 'SWQSTRUCT000019END' ];
		$translated = 'SWQSTRUCT000017END x SWQSTRUCT00018END y SWQSTRUCT000019END';
		$normalized = $normalize->invoke( null, $translated, $expected );

		return str_contains( $normalized, 'SWQSTRUCT000018END' )
			&& true === $validate->invoke( null, $normalized, $expected );
	},
	'correct token remains unchanged' => static function () use ( $normalize, $validate ): bool {
		$expected   = [ 'SWQSTRUCT000018END' ];
		$translated = 'before SWQSTRUCT000018END after';
		$normalized = $normalize->invoke( null, $translated, $expected );

		return $translated === $normalized
			&& true === $validate->invoke( null, $normalized, $expected );
	},
	'ambiguous candidates fail' => static function () use ( $normalize, $validate ): bool {
		$expected   = [ 'SWQSTRUCT000018END' ];
		$translated = 'SWQSTRUCT00018END SWQSTRUCT0018END';
		$normalized = $normalize->invoke( null, $translated, $expected );

		return $translated === $normalized
			&& $validate->invoke( null, $normalized, $expected ) instanceof WP_Error;
	},
	'duplicate token fails' => static function () use ( $normalize, $validate ): bool {
		$expected   = [ 'SWQSTRUCT000018END' ];
		$translated = 'SWQSTRUCT000018END SWQSTRUCT000018END';
		$normalized = $normalize->invoke( null, $translated, $expected );

		return $translated === $normalized
			&& $validate->invoke( null, $normalized, $expected ) instanceof WP_Error;
	},
	'wrong fixed order fails' => static function () use ( $normalize, $validate ): bool {
		$expected   = [ 'SWQSTRUCT000018END', 'SWQSTRUCT000019END' ];
		$translated = 'SWQSTRUCT000019END SWQSTRUCT000018END';
		$normalized = $normalize->invoke( null, $translated, $expected );

		return $translated === $normalized
			&& $validate->invoke( null, $normalized, $expected ) instanceof WP_Error;
	},
];

$failures = 0;
foreach ( $tests as $name => $test ) {
	$passed = $test();
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $name . PHP_EOL;
	$failures += $passed ? 0 : 1;
}

exit( $failures > 0 ? 1 : 0 );

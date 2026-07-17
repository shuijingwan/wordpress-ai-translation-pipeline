<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$thumbnail_ids = [];
$attachment_translations = [];
$attachment_ids = [];
$set_thumbnail_calls = [];

function add_filter(): void {}

function wp_json_encode( $value, int $flags = 0 ) {
	return json_encode( $value, $flags );
}

function absint( $value ): int {
	return abs( (int) $value );
}

function get_post_thumbnail_id( int $post_id ): int {
	global $thumbnail_ids;
	return (int) ( $thumbnail_ids[ $post_id ] ?? 0 );
}

function pll_get_post( int $post_id, string $language ): int {
	global $attachment_translations;
	return (int) ( $attachment_translations[ $post_id ][ $language ] ?? 0 );
}

function get_post_type( int $post_id ): string {
	global $attachment_ids;
	return isset( $attachment_ids[ $post_id ] ) ? 'attachment' : 'post';
}

function set_post_thumbnail( int $post_id, int $thumbnail_id ) {
	global $thumbnail_ids, $set_thumbnail_calls;
	$set_thumbnail_calls[] = [ $post_id, $thumbnail_id ];
	$thumbnail_ids[ $post_id ] = $thumbnail_id;
	return 1;
}

class WP_Error {
	public function __construct(
		private string $code,
		private string $message = ''
	) {}
}

require __DIR__ . '/swq-glm52-translation-tuning-draft-only.php';

$reflection = new ReflectionClass( 'SWQ_GLM52_Translation_Tuning' );
$sync = $reflection->getMethod( 'sync_featured_image' );

$reset = static function (): void {
	global $thumbnail_ids, $attachment_translations, $attachment_ids, $set_thumbnail_calls;
	$thumbnail_ids = [];
	$attachment_translations = [];
	$attachment_ids = [];
	$set_thumbnail_calls = [];
};

$tests = [
	'source image without English attachment is reused' => static function () use ( $reset, $sync ): bool {
		global $thumbnail_ids, $attachment_ids, $set_thumbnail_calls;
		$reset();
		$thumbnail_ids[10] = 20;
		$attachment_ids[20] = true;
		$result = $sync->invoke( null, 10, 11, 'en' );

		return true === $result
			&& 20 === $thumbnail_ids[11]
			&& [ [ 11, 20 ] ] === $set_thumbnail_calls;
	},
	'English attachment translation is preferred' => static function () use ( $reset, $sync ): bool {
		global $thumbnail_ids, $attachment_translations, $attachment_ids, $set_thumbnail_calls;
		$reset();
		$thumbnail_ids[100] = 200;
		$attachment_ids[200] = true;
		$attachment_ids[201] = true;
		$attachment_translations[200]['en'] = 201;
		$result = $sync->invoke( null, 100, 101, 'en' );

		return true === $result
			&& 201 === $thumbnail_ids[101]
			&& [ [ 101, 201 ] ] === $set_thumbnail_calls;
	},
	'existing target image is not overwritten' => static function () use ( $reset, $sync ): bool {
		global $thumbnail_ids, $attachment_ids, $set_thumbnail_calls;
		$reset();
		$thumbnail_ids[100] = 200;
		$thumbnail_ids[101] = 999;
		$attachment_ids[200] = true;
		$attachment_ids[999] = true;
		$result = $sync->invoke( null, 100, 101, 'en' );

		return true === $result
			&& 999 === $thumbnail_ids[101]
			&& [] === $set_thumbnail_calls;
	},
	'source without image leaves target empty' => static function () use ( $reset, $sync ): bool {
		global $thumbnail_ids, $set_thumbnail_calls;
		$reset();
		$result = $sync->invoke( null, 100, 101, 'en' );

		return true === $result
			&& 0 === (int) ( $thumbnail_ids[101] ?? 0 )
			&& [] === $set_thumbnail_calls;
	},
];

$failures = 0;
foreach ( $tests as $name => $test ) {
	$passed = $test();
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $name . PHP_EOL;
	$failures += $passed ? 0 : 1;
}

exit( $failures > 0 ? 1 : 0 );

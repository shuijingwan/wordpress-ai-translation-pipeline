<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$thumbnail_ids = [];
$post_types = [];
$translations = [];
$languages = [];
$meta = [];
$attachment_urls = [];
$attachment_metadata = [];
$post_fields = [];
$set_thumbnail_calls = [];
$meta_update_calls = [];
$meta_delete_calls = [];
$next_attachment_id = 500;
$create_calls = 0;
$create_relation = true;
$create_language = 'en';
$create_result = true;
$set_thumbnail_result = true;
$media_support = true;
$valid_languages = [ 'en' => true ];
$polylang_post_model = null;
$meta_update_mode = 'success';
$meta_delete_mode = 'success';
$persist_update_result = true;
$events = [];

function add_filter(): void {}
function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
function absint( $value ): int { return abs( (int) $value ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function clean_post_cache(): void {}
function get_post_field( string $field, int $post_id, string $context = 'display' ): string {
	global $post_fields;
	return (string) ( $post_fields[ $post_id ][ $field ] ?? '' );
}
function get_post_thumbnail_id( int $post_id ): int {
	global $thumbnail_ids;
	return (int) ( $thumbnail_ids[ $post_id ] ?? 0 );
}
function set_post_thumbnail( int $post_id, int $attachment_id ) {
	global $thumbnail_ids, $set_thumbnail_calls, $set_thumbnail_result;
	$set_thumbnail_calls[] = [ $post_id, $attachment_id ];
	if ( ! $set_thumbnail_result ) {
		return false;
	}
	$thumbnail_ids[ $post_id ] = $attachment_id;
	return 1;
}
function get_post_type( int $post_id ): string {
	global $post_types;
	return (string) ( $post_types[ $post_id ] ?? 'post' );
}
function pll_get_post( int $post_id, string $language ): int {
	global $translations;
	return (int) ( $translations[ $post_id ][ $language ] ?? 0 );
}
function pll_get_post_language( int $post_id, string $field = 'slug' ): string {
	global $languages;
	return (string) ( $languages[ $post_id ] ?? '' );
}
function get_post_meta( int $post_id, string $key, bool $single = false ) {
	global $meta;
	return $meta[ $post_id ][ $key ] ?? '';
}
function update_post_meta( int $post_id, string $key, $value ) {
	global $meta, $meta_update_calls, $meta_update_mode;
	$meta_update_calls[] = [ $post_id, $key, $value ];
	if ( 'failure' === $meta_update_mode ) {
		return false;
	}
	$meta[ $post_id ][ $key ] = $value;
	return 'false_after_write' === $meta_update_mode ? false : 1;
}
function delete_post_meta( int $post_id, string $key ) {
	global $meta, $meta_delete_calls, $meta_delete_mode;
	$meta_delete_calls[] = [ $post_id, $key ];
	if ( 'failure' === $meta_delete_mode ) {
		return false;
	}
	unset( $meta[ $post_id ][ $key ] );
	return true;
}
function wp_get_attachment_url( int $post_id ): string {
	global $attachment_urls;
	return (string) ( $attachment_urls[ $post_id ] ?? '' );
}
function wp_get_attachment_metadata( int $post_id, bool $unfiltered = false ) {
	global $attachment_metadata;
	return $attachment_metadata[ $post_id ] ?? false;
}

class WP_Error {
	public function __construct(
		private string $code,
		private string $message = '',
		private $data = null
	) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class MockWpdb {
	public string $posts = 'wp_posts';
	public string $last_error = '';
	public function update( string $table, array $data, array $where, array $formats, array $where_formats ) {
		global $post_fields, $persist_update_result, $events;
		$events[] = 'persist';
		if ( false === $persist_update_result ) {
			$this->last_error = 'simulated persist failure';
			return false;
		}
		$post_fields[ (int) $where['ID'] ]['post_content'] = (string) $data['post_content'];
		return 1;
	}
}

class MockTranslatedPost {
	public function create_media_translation( int $source_id, string $language ): int {
		global $next_attachment_id, $create_calls, $create_relation, $create_language;
		global $create_result, $translations, $languages, $post_types, $meta;
		global $attachment_metadata, $events;
		++$create_calls;
		$events[] = 'featured_image';
		if ( ! $create_result ) {
			return 0;
		}
		$id = $next_attachment_id++;
		$post_types[ $id ] = 'attachment';
		$languages[ $id ] = $create_language;
		$meta[ $id ]['_wp_attachment_image_alt'] = $meta[ $source_id ]['_wp_attachment_image_alt'] ?? '';
		$meta[ $id ]['_wp_attached_file'] = $meta[ $source_id ]['_wp_attached_file'] ?? '';
		$attachment_metadata[ $id ] = $attachment_metadata[ $source_id ] ?? [];
		if ( $create_relation ) {
			$translations[ $source_id ][ $language ] = $id;
		}
		return $id;
	}
}

class ThrowingTranslatedPost {
	public function create_media_translation(): int {
		throw new RuntimeException( 'simulated Polylang failure' );
	}
}

class RelationThenThrowingTranslatedPost {
	public function create_media_translation( int $source_id, string $language ): int {
		global $translations, $languages, $post_types, $meta, $attachment_metadata, $events;
		$id = 500;
		$events[] = 'featured_image';
		$post_types[ $id ] = 'attachment';
		$languages[ $id ] = $language;
		$meta[ $id ] = $meta[ $source_id ] ?? [];
		$attachment_metadata[ $id ] = $attachment_metadata[ $source_id ] ?? [];
		$translations[ $source_id ][ $language ] = $id;
		throw new RuntimeException( 'exception after relation' );
	}
}

class MockModel {
	public $post;
	public function __construct( $post ) { $this->post = $post; }
	public function get_language( string $language ) {
		global $valid_languages;
		return ! empty( $valid_languages[ $language ] ) ? (object) [ 'slug' => $language ] : false;
	}
}

class MockPolylang {
	public array $options;
	public MockModel $model;
	public function __construct( $post, bool $media_support ) {
		$this->options = [ 'media_support' => $media_support ];
		$this->model = new MockModel( $post );
	}
}

function PLL(): MockPolylang {
	global $polylang_post_model, $media_support;
	return new MockPolylang( $polylang_post_model, $media_support );
}

require dirname( __DIR__ ) . '/swq-glm52-translation-tuning.php';

$class = new ReflectionClass( 'SWQ_GLM52_Translation_Tuning' );
$sync = $class->getMethod( 'sync_featured_image' );
$find_alt = $class->getMethod( 'find_translated_image_alt_in_content' );
$read_images = $class->getMethod( 'read_image_attributes_from_content' );
$create_media = $class->getMethod( 'create_polylang_media_translation' );
$finalize = $class->getMethod( 'finalize_full_article_result' );
$continue_after_apply = $class->getMethod( 'continue_after_apply' );

$reset = static function (): void {
	global $thumbnail_ids, $post_types, $translations, $languages, $meta, $attachment_urls, $attachment_metadata;
	global $post_fields, $wpdb, $persist_update_result, $events;
	global $set_thumbnail_calls, $meta_update_calls, $meta_delete_calls, $next_attachment_id;
	global $create_calls, $create_relation, $create_language, $create_result;
	global $set_thumbnail_result, $media_support, $valid_languages, $polylang_post_model;
	global $meta_update_mode, $meta_delete_mode;
	$thumbnail_ids = [];
	$post_types = [];
	$translations = [];
	$languages = [];
	$meta = [];
	$attachment_urls = [];
	$attachment_metadata = [];
	$post_fields = [];
	$set_thumbnail_calls = [];
	$meta_update_calls = [];
	$meta_delete_calls = [];
	$next_attachment_id = 500;
	$create_calls = 0;
	$create_relation = true;
	$create_language = 'en';
	$create_result = true;
	$set_thumbnail_result = true;
	$media_support = true;
	$valid_languages = [ 'en' => true ];
	$polylang_post_model = new MockTranslatedPost();
	$meta_update_mode = 'success';
	$meta_delete_mode = 'success';
	$persist_update_result = true;
	$events = [];
	$wpdb = new MockWpdb();
};

$source = static function (int $source_post = 10, int $attachment = 20): void {
	global $thumbnail_ids, $post_types, $languages, $meta, $attachment_urls, $attachment_metadata;
	$thumbnail_ids[ $source_post ] = $attachment;
	$post_types[ $attachment ] = 'attachment';
	$languages[ $attachment ] = 'zh';
	$meta[ $attachment ]['_wp_attachment_image_alt'] = '中文 alt';
	$meta[ $attachment ]['_wp_attached_file'] = '2026/07/13-5.png';
	$attachment_urls[ $attachment ] = 'https://media.example.com/2026/07/13-5.png';
	$attachment_metadata[ $attachment ] = [
		'file' => '2026/07/13-5.png',
		'sizes' => [
			'medium' => [ 'file' => '13-5-300x200.png', 'width' => 300, 'height' => 200 ],
		],
	];
};

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void {
	$tests[ $name ] = $callback;
};
$error_code = static fn($result): string => $result instanceof WP_Error ? $result->get_error_code() : '';

$test( 'source without featured image skips safely', static function () use ($reset, $sync): bool {
	$reset();
	$result = $sync->invoke( null, 10, 11, 'en', '', 'English title' );
	return 'no_source_featured_image' === $result['status'];
} );
$test( 'empty target creates and binds translated attachment', static function () use ($reset, $source, $sync): bool {
	global $thumbnail_ids, $create_calls;
	$reset(); $source();
	$result = $sync->invoke( null, 10, 11, 'en', '', 'English title' );
	return 1 === $create_calls && 500 === $thumbnail_ids[11] && 'translated_attachment_created' === $result['status'];
} );
$test( 'target using source attachment is repaired', static function () use ($reset, $source, $sync): bool {
	global $thumbnail_ids;
	$reset(); $source(); $thumbnail_ids[11] = 20;
	$sync->invoke( null, 10, 11, 'en', '', 'English title' );
	return 500 === $thumbnail_ids[11];
} );
$test( 'correct existing translation is reused without creation', static function () use ($reset, $source, $sync): bool {
	global $thumbnail_ids, $post_types, $translations, $languages, $create_calls;
	$reset(); $source();
	$post_types[21] = 'attachment'; $languages[21] = 'en'; $translations[20]['en'] = 21; $thumbnail_ids[11] = 21;
	$sync->invoke( null, 10, 11, 'en', '', 'English title' );
	return 0 === $create_calls && 21 === $thumbnail_ids[11];
} );
$test( 'existing translation replaces source attachment', static function () use ($reset, $source, $sync): bool {
	global $thumbnail_ids, $post_types, $translations, $languages, $create_calls;
	$reset(); $source();
	$post_types[21] = 'attachment'; $languages[21] = 'en'; $translations[20]['en'] = 21; $thumbnail_ids[11] = 20;
	$sync->invoke( null, 10, 11, 'en', '', 'English title' );
	return 0 === $create_calls && 21 === $thumbnail_ids[11];
} );
$test( 'manual target image and alt are preserved', static function () use ($reset, $source, $sync): bool {
	global $thumbnail_ids, $post_types, $languages, $meta, $create_calls, $meta_update_calls;
	$reset(); $source();
	$thumbnail_ids[11] = 99; $post_types[99] = 'attachment'; $languages[99] = 'en'; $meta[99]['_wp_attachment_image_alt'] = 'Manual';
	$result = $sync->invoke( null, 10, 11, 'en', '<img class="wp-image-20" alt="New">', 'Title' );
	return 'manual_featured_image_preserved' === $result['status'] && 0 === $create_calls && [] === $meta_update_calls && 'Manual' === $meta[99]['_wp_attachment_image_alt'];
} );
$test( 'two runs create only one attachment', static function () use ($reset, $source, $sync): bool {
	global $create_calls;
	$reset(); $source();
	$sync->invoke( null, 10, 11, 'en', '', 'Title' );
	$sync->invoke( null, 10, 11, 'en', '', 'Title' );
	return 1 === $create_calls;
} );
$test( 'source featured image and source alt never change', static function () use ($reset, $source, $sync): bool {
	global $thumbnail_ids, $meta;
	$reset(); $source();
	$sync->invoke( null, 10, 11, 'en', '<img class="wp-image-20" alt="English">', 'Title' );
	return 20 === $thumbnail_ids[10] && '中文 alt' === $meta[20]['_wp_attachment_image_alt'];
} );
$test( 'content alt wins over title and copied source alt', static function () use ($reset, $source, $sync): bool {
	global $meta;
	$reset(); $source();
	$sync->invoke( null, 10, 11, 'en', '<img class="wp-image-20" alt="Body alt">', 'Title alt' );
	return 'Body alt' === $meta[500]['_wp_attachment_image_alt'];
} );
$test( 'title is fallback when image is absent', static function () use ($reset, $source, $sync): bool {
	global $meta;
	$reset(); $source();
	$sync->invoke( null, 10, 11, 'en', '<p>No image</p>', 'Title &amp; More' );
	return 'Title & More' === $meta[500]['_wp_attachment_image_alt'];
} );
$test( 'old attachment alt is overwritten from content', static function () use ($reset, $source, $sync): bool {
	global $thumbnail_ids, $post_types, $translations, $languages, $meta;
	$reset(); $source();
	$post_types[21] = 'attachment'; $languages[21] = 'en'; $translations[20]['en'] = 21; $thumbnail_ids[11] = 21; $meta[21]['_wp_attachment_image_alt'] = 'AutoPoly';
	$sync->invoke( null, 10, 11, 'en', '<img class="wp-image-20" alt="Fresh">', 'Title' );
	return 'Fresh' === $meta[21]['_wp_attachment_image_alt'];
} );
$test( 'old attachment alt is overwritten from title', static function () use ($reset, $source, $sync): bool {
	global $thumbnail_ids, $post_types, $translations, $languages, $meta;
	$reset(); $source();
	$post_types[21] = 'attachment'; $languages[21] = 'en'; $translations[20]['en'] = 21; $thumbnail_ids[11] = 21; $meta[21]['_wp_attachment_image_alt'] = 'AutoPoly';
	$sync->invoke( null, 10, 11, 'en', '', 'Fresh title' );
	return 'Fresh title' === $meta[21]['_wp_attachment_image_alt'];
} );
$test( 'existing alt stays when content and title are empty', static function () use ($reset, $source, $sync): bool {
	global $thumbnail_ids, $post_types, $translations, $languages, $meta;
	$reset(); $source();
	$post_types[21] = 'attachment'; $languages[21] = 'en'; $translations[20]['en'] = 21; $thumbnail_ids[11] = 21; $meta[21]['_wp_attachment_image_alt'] = 'Keep';
	$sync->invoke( null, 10, 11, 'en', '', '' );
	return 'Keep' === $meta[21]['_wp_attachment_image_alt'];
} );
$test( 'new copied source alt is cleared without English source', static function () use ($reset, $source, $sync): bool {
	global $meta, $meta_delete_calls;
	$reset(); $source();
	$sync->invoke( null, 10, 11, 'en', '', '' );
	return '' === ( $meta[500]['_wp_attachment_image_alt'] ?? '' ) && 1 === count( $meta_delete_calls );
} );
$test( 'identical target alt causes no metadata update', static function () use ($reset, $source, $sync): bool {
	global $thumbnail_ids, $post_types, $translations, $languages, $meta, $meta_update_calls;
	$reset(); $source();
	$post_types[21] = 'attachment'; $languages[21] = 'en'; $translations[20]['en'] = 21; $thumbnail_ids[11] = 21; $meta[21]['_wp_attachment_image_alt'] = 'Same';
	$sync->invoke( null, 10, 11, 'en', '<img class="wp-image-20" alt="Same">', 'Title' );
	return [] === $meta_update_calls;
} );

$alt_cases = [
	'exact class match' => [ '<img class="wp-image-20" alt="Exact">', 'Exact' ],
	'multiple classes' => [ '<img class="alignnone wp-image-20 size-full" alt="Classes">', 'Classes' ],
	'original URL fallback' => [ '<img src="https://media.example.com/2026/07/13-5.png" alt="URL">', 'URL' ],
	'metadata sized URL fallback' => [ '<img src="https://media.example.com/2026/07/13-5-300x200.png" alt="Sized">', 'Sized' ],
	'double quoted attributes' => [ '<img alt="Double" class="wp-image-20">', 'Double' ],
	'single quoted attributes' => [ "<img alt='Single' class='wp-image-20'>", 'Single' ],
	'attribute order does not matter' => [ '<img alt="Order" src="other" class="x wp-image-20">', 'Order' ],
	'HTML entities are decoded' => [ '<img class="wp-image-20" alt="Rock &amp; Roll &quot;Now&quot;">', 'Rock & Roll "Now"' ],
	'other images are ignored' => [ '<img class="wp-image-99" alt="Wrong"><img class="wp-image-20" alt="Right">', 'Right' ],
	'first nonempty repeated image alt wins' => [ '<img class="wp-image-20" alt=""><img class="wp-image-20" alt="First"><img class="wp-image-20" alt="Second">', 'First' ],
	'blank alt is invalid' => [ '<img class="wp-image-20" alt="   ">', '' ],
	'srcset metadata sized URL fallback' => [ '<img src="other.png" srcset="other-300x200.png 300w, https://media.example.com/2026/07/13-5-300x200.png 768w" alt="Srcset">', 'Srcset' ],
	'class match has priority over earlier URL match' => [ '<img src="https://media.example.com/2026/07/13-5.png" alt="URL"><img class="wp-image-20" alt="Class">', 'Class' ],
];
foreach ( $alt_cases as $name => [$html, $expected] ) {
	$test( 'HTML: ' . $name, static function () use ($reset, $find_alt, $html, $expected): bool {
		$reset();
		$metadata = [
			'file' => '2026/07/13-5.png',
			'sizes' => [ 'medium' => [ 'file' => '13-5-300x200.png' ] ],
		];
		return $expected === $find_alt->invoke( null, $html, 20, 'https://media.example.com/2026/07/13-5.png', '2026/07/13-5.png', $metadata );
	} );
}

$test( 'existing target translation is reused by wrapper', static function () use ($reset, $source, $create_media): bool {
	global $post_types, $translations, $languages, $create_calls;
	$reset(); $source(); $post_types[21] = 'attachment'; $languages[21] = 'en'; $translations[20]['en'] = 21;
	$result = $create_media->invoke( null, 20, 'en' );
	return 21 === $result['attachment_id'] && 0 === $create_calls;
} );
$test( 'disabled media support returns explicit error', static function () use ($reset, $source, $create_media, $error_code): bool {
	global $media_support;
	$reset(); $source(); $media_support = false;
	return 'swq_featured_image_media_support_disabled' === $error_code( $create_media->invoke( null, 20, 'en' ) );
} );
$test( 'uncallable create method returns explicit error', static function () use ($reset, $source, $create_media, $error_code): bool {
	global $polylang_post_model;
	$reset(); $source(); $polylang_post_model = new stdClass();
	return 'swq_featured_image_create_method_unavailable' === $error_code( $create_media->invoke( null, 20, 'en' ) );
} );
$test( 'missing relation after creation returns explicit error', static function () use ($reset, $source, $create_media, $error_code): bool {
	global $create_relation;
	$reset(); $source(); $create_relation = false;
	return 'swq_featured_image_translation_relation_missing' === $error_code( $create_media->invoke( null, 20, 'en' ) );
} );
$test( 'wrong created attachment language returns explicit error', static function () use ($reset, $source, $create_media, $error_code): bool {
	global $create_language;
	$reset(); $source(); $create_language = 'fr';
	return 'swq_featured_image_translated_language_mismatch' === $error_code( $create_media->invoke( null, 20, 'en' ) );
} );
$test( 'creation failure returns explicit error and no source fallback', static function () use ($reset, $source, $sync, $error_code): bool {
	global $create_result, $thumbnail_ids;
	$reset(); $source(); $create_result = false;
	$result = $sync->invoke( null, 10, 11, 'en', '', 'Title' );
	return 'swq_featured_image_create_failed' === $error_code( $result ) && 0 === ( $thumbnail_ids[11] ?? 0 );
} );
$test( 'creation exception returns explicit error', static function () use ($reset, $source, $create_media, $error_code): bool {
	global $polylang_post_model;
	$reset(); $source(); $polylang_post_model = new ThrowingTranslatedPost();
	return 'swq_featured_image_create_failed' === $error_code( $create_media->invoke( null, 20, 'en' ) );
} );
$test( 'set featured image failure returns explicit error', static function () use ($reset, $source, $sync, $error_code): bool {
	global $set_thumbnail_result;
	$reset(); $source(); $set_thumbnail_result = false;
	return 'swq_full_article_featured_image_set_failed' === $error_code( $sync->invoke( null, 10, 11, 'en', '', 'Title' ) );
} );
$test( 'invalid target language returns explicit error', static function () use ($reset, $source, $create_media, $error_code): bool {
	global $valid_languages;
	$reset(); $source(); $valid_languages = [];
	return 'swq_featured_image_invalid_target_language' === $error_code( $create_media->invoke( null, 20, 'en' ) );
} );
$test( 'non attachment source returns explicit error', static function () use ($reset, $create_media, $error_code): bool {
	$reset();
	return 'swq_featured_image_invalid_source_attachment' === $error_code( $create_media->invoke( null, 20, 'en' ) );
} );
$test( 'new attachment shares attached file and metadata', static function () use ($reset, $source, $sync): bool {
	global $meta, $attachment_metadata;
	$reset(); $source();
	$sync->invoke( null, 10, 11, 'en', '<img class="wp-image-20" alt="English">', 'Title' );
	return $meta[20]['_wp_attached_file'] === $meta[500]['_wp_attached_file']
		&& $attachment_metadata[20] === $attachment_metadata[500];
} );
$test( 'failed alt update returns explicit error', static function () use ($reset, $source, $sync, $error_code): bool {
	global $meta_update_mode;
	$reset(); $source(); $meta_update_mode = 'failure';
	return 'swq_featured_image_alt_update_failed' === $error_code(
		$sync->invoke( null, 10, 11, 'en', '<img class="wp-image-20" alt="English">', 'Title' )
	);
} );
$test( 'false alt update return is accepted when reread matches', static function () use ($reset, $source, $sync): bool {
	global $meta_update_mode, $meta;
	$reset(); $source(); $meta_update_mode = 'false_after_write';
	$result = $sync->invoke( null, 10, 11, 'en', '<img class="wp-image-20" alt="English">', 'Title' );
	return 'English' === $meta[500]['_wp_attachment_image_alt']
		&& 'alt_updated_from_content' === $result['alt_status'];
} );
$test( 'failed copied alt clear returns explicit error', static function () use ($reset, $source, $sync, $error_code): bool {
	global $meta_delete_mode;
	$reset(); $source(); $meta_delete_mode = 'failure';
	return 'swq_featured_image_alt_clear_failed' === $error_code(
		$sync->invoke( null, 10, 11, 'en', '', '' )
	);
} );
$test( 'successful copied alt clear is verified', static function () use ($reset, $source, $sync): bool {
	global $meta;
	$reset(); $source();
	$result = $sync->invoke( null, 10, 11, 'en', '', '' );
	return '' === ( $meta[500]['_wp_attachment_image_alt'] ?? '' )
		&& 'new_attachment_alt_cleared' === $result['alt_status'];
} );
$test( 'Polylang exception with valid relation is recovered', static function () use ($reset, $source, $create_media): bool {
	global $polylang_post_model;
	$reset(); $source(); $polylang_post_model = new RelationThenThrowingTranslatedPost();
	$result = $create_media->invoke( null, 20, 'en' );
	return ! is_wp_error( $result )
		&& 500 === $result['attachment_id']
		&& 'polylang_exception_relation_recovered' === $result['warning_code'];
} );
$test( 'same target file without relation is diagnostic only', static function () use ($reset, $source, $sync): bool {
	global $thumbnail_ids, $post_types, $languages, $meta, $create_calls, $meta_update_calls;
	$reset(); $source();
	$thumbnail_ids[11] = 99;
	$post_types[99] = 'attachment';
	$languages[99] = 'en';
	$meta[99]['_wp_attached_file'] = $meta[20]['_wp_attached_file'];
	$meta[99]['_wp_attachment_image_alt'] = 'Historical';
	$result = $sync->invoke( null, 10, 11, 'en', '<img class="wp-image-20" alt="New">', 'Title' );
	return 'existing_same_file_without_translation_relation' === $result['status']
		&& 0 === $create_calls
		&& [] === $meta_update_calls
		&& 99 === $thumbnail_ids[11];
} );

$url_cases = [
	'query parameters are ignored after exact path match' => [
		'<img src="https://media.example.com/2026/07/image.png?ver=1&amp;x=2" alt="Query">',
		'https://origin.example.com/2026/07/image.png',
		'2026/07/image.png',
		[],
		'Query',
	],
	'CDN host may differ when complete path matches' => [
		'<img src="https://cdn.example.com/2026/07/image.png" alt="CDN">',
		'https://origin.example.com/2026/07/image.png',
		'2026/07/image.png',
		[],
		'CDN',
	],
	'different directory with same filename does not match' => [
		'<img src="https://cdn.example.com/2025/01/image.png" alt="Wrong">',
		'https://origin.example.com/2026/07/image.png',
		'2026/07/image.png',
		[],
		'',
	],
	'filename only does not match a full directory path' => [
		'<img src="image.png" alt="Wrong">',
		'https://origin.example.com/uploads/2026/07/image.png',
		'uploads/2026/07/image.png',
		[],
		'',
	],
	'declared metadata size matches' => [
		'<img src="https://cdn.example.com/2026/07/image-300x200.png" alt="Size">',
		'https://origin.example.com/2026/07/image.png',
		'2026/07/image.png',
		[ 'file' => '2026/07/image.png', 'sizes' => [ 'medium' => [ 'file' => 'image-300x200.png' ] ] ],
		'Size',
	],
	'undeclared pseudo size does not match' => [
		'<img src="https://cdn.example.com/2026/07/image-300x200.png" alt="Wrong">',
		'https://origin.example.com/2026/07/image.png',
		'2026/07/image.png',
		[ 'file' => '2026/07/image.png', 'sizes' => [] ],
		'',
	],
	'legitimate dimension suffix is not stripped' => [
		'<img src="https://cdn.example.com/2026/07/diagram.png" alt="Wrong">',
		'https://origin.example.com/2026/07/diagram-300x200.png',
		'2026/07/diagram-300x200.png',
		[ 'file' => '2026/07/diagram-300x200.png', 'sizes' => [] ],
		'',
	],
];
foreach ( $url_cases as $name => [$html, $url, $file, $metadata, $expected] ) {
	$test( 'URL: ' . $name, static function () use ($reset, $find_alt, $html, $url, $file, $metadata, $expected): bool {
		$reset();
		return $expected === $find_alt->invoke( null, $html, 20, $url, $file, $metadata );
	} );
}

$entity_cases = [
	'amp entity decodes once' => [ 'A &amp; B', 'A & B' ],
	'nested amp entity decodes once' => [ 'A &amp;amp; B', 'A &amp; B' ],
	'quote entity decodes once' => [ '&quot;Quoted&quot;', '"Quoted"' ],
	'numeric entity decodes once' => [ 'A &#38; B', 'A & B' ],
	'literal greater-than inside alt is retained' => [ 'Before > After', 'Before > After' ],
];
foreach ( $entity_cases as $name => [$encoded, $expected] ) {
	$test( 'HTML entity: ' . $name, static function () use ($reset, $find_alt, $encoded, $expected): bool {
		$reset();
		$html = '<!-- wp:image --><img data-note="x > y" class="other wp-image-20" alt="' . $encoded . '" src="other.png"><!-- /wp:image --><p>After</p>';
		return $expected === $find_alt->invoke( null, $html, 20, '', '', [] );
	} );
}

$test( 'post-processing failure returns success response warning after persisted body', static function () use ($reset, $source, $finalize): bool {
	global $create_result, $post_fields, $events;
	$reset(); $source(); $create_result = false;
	$post_fields[11] = [ 'post_content' => 'old', 'post_title' => 'English title' ];
	$result = $finalize->invoke(
		null,
		[ 'translated_post_id' => 11, 'existing' => 'kept' ],
		[ 'source_post_id' => 10, 'target_language' => 'en' ],
		11,
		'final body'
	);
	return ! is_wp_error( $result )
		&& 'kept' === $result['existing']
		&& 'final body' === $post_fields[11]['post_content']
		&& 'sync_warning' === $result['featured_image_sync']['status']
		&& 'swq_featured_image_create_failed' === $result['featured_image_sync']['warning_code']
		&& [ 'persist', 'featured_image' ] === $events;
} );
$test( 'exact persist failure remains top-level error and skips featured sync', static function () use ($reset, $source, $finalize, $error_code): bool {
	global $persist_update_result, $post_fields, $events;
	$reset(); $source(); $persist_update_result = false;
	$post_fields[11] = [ 'post_content' => 'old', 'post_title' => 'English title' ];
	$result = $finalize->invoke(
		null,
		[ 'translated_post_id' => 11 ],
		[ 'source_post_id' => 10, 'target_language' => 'en' ],
		11,
		'final body'
	);
	return 'swq_full_article_exact_persist_database_failed' === $error_code( $result )
		&& [ 'persist' ] === $events
		&& 'old' === $post_fields[11]['post_content'];
} );
$test( 'apply failure remains the original top-level error', static function () use ($reset, $continue_after_apply): bool {
	$reset();
	$apply_error = new WP_Error( 'simulated_apply_failure', 'Apply failed.' );
	return $apply_error === $continue_after_apply->invoke(
		null,
		$apply_error,
		[ 'source_post_id' => 10, 'target_language' => 'en' ],
		'final body'
	);
} );
$test( 'class token does not match a longer attachment ID', static function () use ($reset, $find_alt): bool {
	$reset();
	return '' === $find_alt->invoke(
		null,
		'<img class="wp-image-19928" alt="Wrong">',
		199,
		'',
		'',
		[]
	);
} );
$test( 'image scanner preserves complete attributes around literal greater-than', static function () use ($reset, $read_images): bool {
	$reset();
	$images = $read_images->invoke(
		null,
		'<img data-note="x > y" src="image.png?a=1&amp;b=2" srcset="a.png 1x, b.png 2x" class="one two" alt="Before > After"><!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->'
	);
	return 1 === count( $images )
		&& 'image.png?a=1&b=2' === $images[0]['src']
		&& 'a.png 1x, b.png 2x' === $images[0]['srcset']
		&& 'one two' === $images[0]['class']
		&& 'Before > After' === $images[0]['alt'];
} );

$failures = 0;
foreach ( $tests as $name => $callback ) {
	try {
		$passed = true === $callback();
	} catch ( Throwable $throwable ) {
		$passed = false;
		echo 'ERROR: ' . $name . ': ' . $throwable->getMessage() . PHP_EOL;
	}
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $name . PHP_EOL;
	$failures += $passed ? 0 : 1;
}
echo sprintf( "%d tests, %d failures\n", count( $tests ), $failures );
exit( $failures > 0 ? 1 : 0 );

<?php
/**
 * Plugin Name: SWQ GLM-5.2 Translation Tuning
 * Description: Tunes GLM-5.2 requests and enables one-shot full-article translation for SlyTranslate with language-aware prompts, safe Plaintext translation, protected structures, and exact post-content persistence.
 * Version: 2026.07.30.19
 */

defined( 'ABSPATH' ) || exit;

final class SWQ_GLM52_Translation_Tuning {

	private const REST_ROUTE = '/ai-translate/v1/ai-translate/translate-content/run';
	private const MODEL_MARKER = 'glm-5.2';
	private const NORMAL_MIN_OUTPUT_TOKENS = 1024;
	private const FULL_ARTICLE_MAX_OUTPUT_TOKENS = 32768;
	private const FULL_ARTICLE_TIMEOUT = 600;
	private const FULL_ARTICLE_RUNAWAY_OUTPUT_RATIO = 6.0;
	private const TRACE_FILE = '/tmp/swq-glm52-execution-trace.jsonl';
	private const PROMPT_POLICY_VERSION = '2026-07-30-v13';
	private const TRANSLATION_TAIL_GUARD = 'SWQTRANSLATIONTAILGUARDEND';
	private const PROTECTED_TOKEN_REGEX = '/SWQ(?:BLOCK|INLINE|HTML|STRUCT|PLAINSTART|PLAINSTOP|ALTSTART|ALTSTOP)\d{6}END/';
	private const PROTECTED_TOKEN_LIKE_REGEX = '/SWQ(?:BLOCK|INLINE|HTML|STRUCT|PLAINSTART|PLAINSTOP|ALTSTART|ALTSTOP)\d+END/';

	private static array $state = [
		'active'          => false,
		'post_id'         => 0,
		'request_count'   => 0,
		'request_model'   => '',
		'payload_chars'   => 0,
		'protected_count' => 0,
		'max_tokens'      => 0,
	];

	public static function boot(): void {
		add_filter( 'http_request_args', [ self::class, 'tune_glm52_request' ], PHP_INT_MAX - 10, 2 );
		add_filter( 'pre_http_request', [ self::class, 'guard_full_article_request' ], PHP_INT_MAX, 3 );
		add_filter( 'rest_dispatch_request', [ self::class, 'intercept_translate_content' ], PHP_INT_MAX, 4 );
	}

	public static function tune_glm52_request( array $args, string $url ): array {
		if ( 'POST' !== strtoupper( (string) ( $args['method'] ?? 'POST' ) ) || ! self::is_zhipu_chat_endpoint( $url ) ) {
			return $args;
		}

		$body = self::decode_request_body( $args['body'] ?? '' );
		if ( [] === $body ) {
			return $args;
		}

		$model = strtolower( trim( (string) ( $body['model'] ?? '' ) ) );
		if ( ! str_contains( $model, self::MODEL_MARKER ) ) {
			return $args;
		}

		self::trace_event(
			'http_request_args_before',
			[
				'active'             => self::$state['active'],
				'model'              => $model,
				'max_tokens'         => isset( $body['max_tokens'] ) ? (int) $body['max_tokens'] : null,
				'timeout'            => isset( $args['timeout'] ) ? (int) $args['timeout'] : null,
				'message_text_chars' => self::request_message_text_chars( $body ),
			]
		);

		$body['thinking'] = [ 'type' => 'disabled' ];
		$body['do_sample'] = false;
		$current_max_tokens = isset( $body['max_tokens'] ) ? (int) $body['max_tokens'] : 0;

		if ( self::$state['active'] ) {
			$body['max_tokens'] = self::FULL_ARTICLE_MAX_OUTPUT_TOKENS;
			unset( $body['chat_template_kwargs'] );
			$args['timeout'] = max( (int) ( $args['timeout'] ?? 0 ), self::FULL_ARTICLE_TIMEOUT );
			self::$state['max_tokens'] = self::FULL_ARTICLE_MAX_OUTPUT_TOKENS;
		} elseif ( $current_max_tokens < self::NORMAL_MIN_OUTPUT_TOKENS ) {
			$body['max_tokens'] = self::NORMAL_MIN_OUTPUT_TOKENS;
		}

		$encoded = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( is_string( $encoded ) ) {
			$args['body'] = $encoded;
		}

		self::trace_event(
			'http_request_args_after',
			[
				'active'             => self::$state['active'],
				'model'              => $model,
				'max_tokens'         => isset( $body['max_tokens'] ) ? (int) $body['max_tokens'] : null,
				'timeout'            => isset( $args['timeout'] ) ? (int) $args['timeout'] : null,
				'message_text_chars' => self::request_message_text_chars( $body ),
			]
		);


		return $args;
	}

	public static function guard_full_article_request( $preempt, array $parsed_args, string $url ) {
		if (
			self::is_zhipu_chat_endpoint( $url )
			&& 'POST' === strtoupper( (string) ( $parsed_args['method'] ?? 'GET' ) )
		) {
			$trace_body = self::decode_request_body( $parsed_args['body'] ?? '' );
			self::trace_event(
				'pre_http_request_seen',
				[
					'active'             => self::$state['active'],
					'preempt_type'       => self::value_type( $preempt ),
					'model'              => (string) ( $trace_body['model'] ?? '' ),
					'max_tokens'         => isset( $trace_body['max_tokens'] ) ? (int) $trace_body['max_tokens'] : null,
					'message_text_chars' => self::request_message_text_chars( $trace_body ),
				]
			);
		}

		if (
			! self::$state['active']
			|| ! self::is_zhipu_chat_endpoint( $url )
			|| 'POST' !== strtoupper( (string) ( $parsed_args['method'] ?? 'GET' ) )
		) {
			return $preempt;
		}

		self::$state['request_count']++;
		self::trace_event(
			'full_article_request_counted',
			[ 'request_count' => self::$state['request_count'] ]
		);
		$body = self::decode_request_body( $parsed_args['body'] ?? '' );
		$model = strtolower( trim( (string) ( $body['model'] ?? '' ) ) );
		self::$state['request_model'] = $model;

		if ( ! str_contains( $model, self::MODEL_MARKER ) ) {
			return new WP_Error(
				'swq_full_article_request_model_mismatch',
				sprintf(
					'Full-article translation requires GLM-5.2, but the actual request used "%s".',
					'' !== $model ? $model : 'unknown'
				)
			);
		}

		if ( self::$state['request_count'] > 1 ) {
			return new WP_Error(
				'swq_full_article_second_request_blocked',
				'Full-article translation allows exactly one model request. A retry or fallback request was blocked.'
			);
		}

		return $preempt;
	}

	public static function intercept_translate_content( $dispatch_result, $request, string $route, array $handler ) {
		unset( $handler );

		if ( self::REST_ROUTE !== $route ) {
			return $dispatch_result;
		}

		self::reset_trace();
		self::trace_event(
			'rest_route_seen',
			[
				'route'         => $route,
				'method'        => is_object( $request ) && method_exists( $request, 'get_method' )
					? (string) $request->get_method()
					: '',
				'response_type' => self::value_type( $dispatch_result ),
			]
		);

		if ( null !== $dispatch_result ) {
			self::trace_event( 'rest_bypassed', [ 'reason' => 'dispatch_result_already_set' ] );
			return $dispatch_result;
		}

		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			self::trace_event( 'rest_bypassed', [ 'reason' => 'invalid_request_object' ] );
			return $dispatch_result;
		}

		if ( method_exists( $request, 'get_method' ) && 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			self::trace_event( 'rest_bypassed', [ 'reason' => 'not_post' ] );
			return $dispatch_result;
		}

		$input = self::get_rest_input( $request );
		$model_slug = isset( $input['model_slug'] ) && is_string( $input['model_slug'] )
			? trim( sanitize_text_field( $input['model_slug'] ) )
			: '';

		self::trace_event(
			'rest_input_resolved',
			[
				'model_slug'       => $model_slug,
				'post_id'          => isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0,
				'target_language'  => (string) ( $input['target_language'] ?? '' ),
				'overwrite'        => ! empty( $input['overwrite'] ),
				'translate_title'  => ! array_key_exists( 'translate_title', $input ) || ! empty( $input['translate_title'] ),
				'input_keys'       => array_values( array_keys( $input ) ),
			]
		);

		if ( '' === $model_slug || ! str_contains( strtolower( $model_slug ), self::MODEL_MARKER ) ) {
			self::trace_event( 'rest_bypassed', [ 'reason' => 'model_slug_not_glm52' ] );
			return $dispatch_result;
		}

		if (
			! class_exists( 'SlyTranslate\\TranslationRuntime' )
			|| ! class_exists( 'SlyTranslate\\ClientTranslationWorkflowService' )
		) {
			self::trace_event( 'rest_failed', [ 'reason' => 'dependencies_unavailable' ] );
			return new WP_Error(
				'swq_full_article_slytranslate_unavailable',
				'SlyTranslate full-article dependencies are unavailable.'
			);
		}

		self::trace_event( 'rest_intercept_entered' );

		try {
			$result = SlyTranslate\TranslationRuntime::with_model_slug_override(
				[ 'model_slug' => $model_slug ],
				static fn() => self::run_full_article_job( $input )
			);

			self::trace_event(
				'rest_intercept_returned',
				[
					'result_type' => self::value_type( $result ),
					'error_code'  => is_wp_error( $result ) ? $result->get_error_code() : null,
				]
			);

			return $result;
		} catch ( Throwable $throwable ) {
			self::trace_event(
				'rest_intercept_threw',
				[
					'exception_class' => get_class( $throwable ),
					'message'         => $throwable->getMessage(),
				]
			);
			throw $throwable;
		}
	}

	private static function run_full_article_job( array $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		self::trace_event( 'job_started', [ 'post_id' => $post_id ] );
		$target_language = isset( $input['target_language'] ) && is_string( $input['target_language'] )
			? sanitize_key( $input['target_language'] )
			: '';
		$source_language = isset( $input['source_language'] ) && is_string( $input['source_language'] )
			? sanitize_key( $input['source_language'] )
			: '';
		$post_status = isset( $input['post_status'] ) && is_string( $input['post_status'] )
			? sanitize_key( $input['post_status'] )
			: '';
		$overwrite = ! empty( $input['overwrite'] );
		$translate_title = ! array_key_exists( 'translate_title', $input ) || ! empty( $input['translate_title'] );
		$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] )
			? mb_substr( sanitize_textarea_field( $input['additional_prompt'] ), 0, 2000 )
			: '';

		if ( $post_id < 1 ) {
			return new WP_Error( 'invalid_post_id', 'A valid source post ID is required.' );
		}
		if ( '' === $target_language ) {
			return new WP_Error( 'missing_target_language', 'Target language is required.' );
		}

		if (
			class_exists( 'SlyTranslate\\TranslationProgressTracker' )
			&& method_exists( 'SlyTranslate\\TranslationProgressTracker', 'clear_progress' )
		) {
			SlyTranslate\TranslationProgressTracker::clear_progress( $post_id );
		}

		$prepare_input = [
			'source_post_id'  => $post_id,
			'target_language' => $target_language,
			'overwrite'       => $overwrite,
			'post_status'     => $post_status,
		];
		if ( '' !== $source_language ) {
			$prepare_input['source_language'] = $source_language;
		}

		$prepared = SlyTranslate\ClientTranslationWorkflowService::prepare_single( $prepare_input );
		if ( is_wp_error( $prepared ) ) {
			self::trace_event( 'prepare_failed', [ 'error_code' => $prepared->get_error_code() ] );
			return $prepared;
		}
		if ( ! is_array( $prepared ) || empty( $prepared['units'] ) || ! is_array( $prepared['units'] ) ) {
			return new WP_Error( 'swq_full_article_prepare_invalid', 'SlyTranslate returned no translation units.' );
		}

		$unit_summary = [];
		foreach ( $prepared['units'] as $trace_unit ) {
			if ( ! is_array( $trace_unit ) ) {
				continue;
			}
			$unit_summary[] = [
				'id'           => (string) ( $trace_unit['id'] ?? '' ),
				'field'        => (string) ( $trace_unit['field'] ?? '' ),
				'source_chars' => self::char_length( (string) ( $trace_unit['source'] ?? '' ) ),
			];
		}
		self::trace_event( 'prepare_succeeded', [ 'units' => $unit_summary ] );

		$resolved_source_language = sanitize_key(
			(string) ( $prepared['source_language'] ?? ( '' !== $source_language ? $source_language : 'zh' ) )
		);

		$article_title = '';
		$article_excerpt = '';
		foreach ( $prepared['units'] as $unit ) {
			if ( ! is_array( $unit ) ) {
				continue;
			}
			$field = (string) ( $unit['field'] ?? '' );
			$source = (string) ( $unit['source'] ?? '' );
			if ( 'title' === $field ) {
				$article_title = $source;
			} elseif ( 'excerpt' === $field ) {
				$article_excerpt = $source;
			}
		}

		$translations                = [];
		$non_content_units           = [];
		$content_units               = [];
		$validated_translated_content = null;

		foreach ( $prepared['units'] as $unit ) {
			if ( ! is_array( $unit ) ) {
				continue;
			}

			if ( 'content' === (string) ( $unit['field'] ?? '' ) ) {
				$content_units[] = $unit;
			} else {
				$non_content_units[] = $unit;
			}
		}

		/*
		 * Translate short fields before the expensive full-article request.
		 * apply_single() indexes translations by unit id, so unit order is not
		 * significant when the translated package is persisted.
		 */
		$ordered_units = array_merge( $non_content_units, $content_units );

		foreach ( $ordered_units as $unit ) {
			$id = isset( $unit['id'] ) && is_string( $unit['id'] ) ? $unit['id'] : '';
			if ( '' === $id ) {
				continue;
			}

			$field  = (string) ( $unit['field'] ?? '' );
			$source = (string) ( $unit['source'] ?? '' );

			self::trace_event(
				'unit_started',
				[
					'id'           => $id,
					'field'        => $field,
					'source_chars'  => self::char_length( $source ),
					'target_language' => $target_language,
					'prompt_policy'   => self::PROMPT_POLICY_VERSION,
				]
			);

			if ( 'content' === $field ) {
				$translated = self::translate_full_content(
					$source,
					$target_language,
					$resolved_source_language,
					$additional_prompt,
					$post_id,
					$article_title,
					$article_excerpt
				);
			} elseif ( 'title' === $field && ! $translate_title ) {
				$translated = $source;
			} elseif ( '' === trim( $source ) ) {
				$translated = $source;
			} else {
				$translated = self::translate_field_value(
					$source,
					$target_language,
					$resolved_source_language,
					$field,
					$id,
					$post_id,
					$additional_prompt
				);
			}

			self::trace_event(
				'unit_finished',
				[
					'id'           => $id,
					'field'        => $field,
					'result_type'  => self::value_type( $translated ),
					'error_code'   => is_wp_error( $translated ) ? $translated->get_error_code() : null,
					'output_chars' => is_string( $translated ) ? self::char_length( $translated ) : null,
				]
			);

			if ( is_wp_error( $translated ) ) {
				return $translated;
			}

			if ( 'content' === $field ) {
				$validated_translated_content = (string) $translated;
			}

			$translations[] = [
				'id'         => $id,
				'translated' => (string) $translated,
			];
		}

		// New translations stay draft; overwrites preserve the target's current status.
		$apply_post_status = 'draft';
		$existing_translation_id = absint( $prepared['existing_translation_id'] ?? 0 );
		if ( $existing_translation_id > 0 ) {
			$existing_post_status = get_post_status( $existing_translation_id );
			if ( is_string( $existing_post_status ) && '' !== $existing_post_status ) {
				$apply_post_status = $existing_post_status;
			}
		}

		$apply_input = [
			'source_post_id'  => absint( $prepared['source_post_id'] ?? $post_id ),
			'target_language' => (string) ( $prepared['target_language'] ?? $target_language ),
			'source_language' => $resolved_source_language,
			'overwrite'       => ! empty( $prepared['overwrite'] ),
			'post_status'     => $apply_post_status,
			'source_hash'     => (string) ( $prepared['source_hash'] ?? '' ),
			'translations'    => $translations,
		];

		self::trace_event( 'apply_started', [ 'translation_count' => count( $translations ) ] );
		$apply_result = SlyTranslate\ClientTranslationWorkflowService::apply_single( $apply_input );
		self::trace_event(
			'apply_finished',
			[
				'result_type' => self::value_type( $apply_result ),
				'error_code'  => is_wp_error( $apply_result ) ? $apply_result->get_error_code() : null,
			]
		);

		return self::continue_after_apply(
			$apply_result,
			$apply_input,
			$validated_translated_content
		);
	}

	/**
	 * @return array|WP_Error
	 */
	private static function continue_after_apply(
		$apply_result,
		array $apply_input,
		$validated_translated_content
	) {
		if ( is_wp_error( $apply_result ) ) {
			return $apply_result;
		}
		if ( ! is_string( $validated_translated_content ) ) {
			return new WP_Error(
				'swq_full_article_validated_content_missing',
				'The validated full-article content was unavailable after SlyTranslate applied the translation.'
			);
		}

		$translated_post_id = is_array( $apply_result )
			? absint( $apply_result['translated_post_id'] ?? 0 )
			: 0;
		if ( $translated_post_id < 1 ) {
			return new WP_Error(
				'swq_full_article_translated_post_id_missing',
				'SlyTranslate did not return a valid translated post ID.'
			);
		}

		return self::finalize_full_article_result(
			$apply_result,
			$apply_input,
			$translated_post_id,
			$validated_translated_content
		);
	}

	/**
	 * Persist the translated body, then run non-transactional featured-image post-processing.
	 *
	 * Once exact persistence succeeds, the article translation is complete. A featured-image
	 * failure is therefore returned as a warning attached to the successful apply result.
	 *
	 * @return array|WP_Error
	 */
	private static function finalize_full_article_result(
		array $apply_result,
		array $apply_input,
		int $translated_post_id,
		string $validated_translated_content
	) {
		$exact_persist_result = self::persist_exact_post_content(
			$translated_post_id,
			$validated_translated_content
		);
		if ( is_wp_error( $exact_persist_result ) ) {
			return $exact_persist_result;
		}

		$source_post_id       = absint( $apply_input['source_post_id'] ?? 0 );
		$source_attachment_id = (int) get_post_thumbnail_id( $source_post_id );
		$featured_image_result = self::sync_featured_image(
			$source_post_id,
			$translated_post_id,
			(string) ( $apply_input['target_language'] ?? '' ),
			$validated_translated_content,
			(string) get_post_field( 'post_title', $translated_post_id, 'raw' )
		);

		if ( is_wp_error( $featured_image_result ) ) {
			$error_data = $featured_image_result->get_error_data();
			$error_data = is_array( $error_data ) ? $error_data : [];
			$featured_image_result = [
				'status'               => 'sync_warning',
				'source_attachment_id' => $source_attachment_id,
				'target_attachment_id' => absint(
					$error_data['target_attachment_id']
						?? $error_data['created_attachment_id']
						?? 0
				),
				'alt_source'           => '',
				'alt_status'           => '',
				'warning_code'         => $featured_image_result->get_error_code(),
				'warning_message'      => sprintf(
					'The English article was saved, but featured-image post-processing did not complete: %s',
					$featured_image_result->get_error_message()
				),
			];
			self::trace_event( 'featured_image_sync_warning', $featured_image_result );
		}

		$apply_result['featured_image_sync'] = $featured_image_result;
		return $apply_result;
	}

	private static function sync_featured_image(
		int $source_post_id,
		int $translated_post_id,
		string $target_language,
		string $translated_content = '',
		string $translated_title = ''
	) {
		$source_thumbnail_id = (int) get_post_thumbnail_id( $source_post_id );
		if ( $source_thumbnail_id < 1 ) {
			$result = [
				'status'               => 'no_source_featured_image',
				'source_attachment_id' => 0,
				'target_attachment_id' => 0,
				'alt_source'           => '',
				'alt_status'           => '',
				'warning_code'         => '',
				'warning_message'      => '',
			];
			self::trace_event( 'featured_image_sync_finished', $result );
			return $result;
		}

		$existing_thumbnail_id    = (int) get_post_thumbnail_id( $translated_post_id );
		$known_translation_id     = function_exists( 'pll_get_post' )
			? absint( pll_get_post( $source_thumbnail_id, $target_language ) )
			: 0;
		$uses_source_image_family = $existing_thumbnail_id < 1
			|| $existing_thumbnail_id === $source_thumbnail_id
			|| ( $known_translation_id > 0 && $existing_thumbnail_id === $known_translation_id );

		if ( ! $uses_source_image_family ) {
			$same_file_without_relation = (
				'attachment' === get_post_type( $existing_thumbnail_id )
				&& function_exists( 'pll_get_post_language' )
				&& $target_language === (string) pll_get_post_language( $existing_thumbnail_id, 'slug' )
				&& '' !== (string) get_post_meta( $source_thumbnail_id, '_wp_attached_file', true )
				&& (string) get_post_meta( $source_thumbnail_id, '_wp_attached_file', true )
					=== (string) get_post_meta( $existing_thumbnail_id, '_wp_attached_file', true )
			);
			$result = [
				'status'               => $same_file_without_relation
					? 'existing_same_file_without_translation_relation'
					: 'manual_featured_image_preserved',
				'source_attachment_id' => $source_thumbnail_id,
				'target_attachment_id' => $existing_thumbnail_id,
				'alt_source'           => '',
				'alt_status'           => '',
				'warning_code'         => $same_file_without_relation
					? 'existing_same_file_without_translation_relation'
					: '',
				'warning_message'      => $same_file_without_relation
					? 'The current target-language featured image shares the source file but has no Polylang translation relation; it was preserved for manual review.'
					: '',
			];
			self::trace_event( 'featured_image_sync_finished', $result );
			return $result;
		}

		$attachment_result = self::create_polylang_media_translation(
			$source_thumbnail_id,
			$target_language
		);
		if ( is_wp_error( $attachment_result ) ) {
			return $attachment_result;
		}

		$translated_attachment_id = (int) $attachment_result['attachment_id'];
		$source_url                = (string) wp_get_attachment_url( $source_thumbnail_id );
		$attached_file             = (string) get_post_meta( $source_thumbnail_id, '_wp_attached_file', true );
		$attachment_metadata       = wp_get_attachment_metadata( $source_thumbnail_id, true );
		$attachment_metadata       = is_array( $attachment_metadata ) ? $attachment_metadata : [];
		$alt                       = self::find_translated_image_alt_in_content(
			$translated_content,
			$source_thumbnail_id,
			$source_url,
			$attached_file,
			$attachment_metadata
		);
		$alt_source                = '';

		if ( '' !== $alt ) {
			$alt_source = 'content';
		} else {
			$translated_title = self::normalize_translated_title_alt( $translated_title );
			if ( '' !== $translated_title ) {
				$alt        = $translated_title;
				$alt_source = 'title';
			}
		}

		$alt_result = self::persist_translated_attachment_alt(
			$translated_attachment_id,
			$alt,
			$alt_source,
			! empty( $attachment_result['created'] )
		);
		if ( is_wp_error( $alt_result ) ) {
			return $alt_result;
		}

		if ( $translated_attachment_id !== $existing_thumbnail_id ) {
			$updated = set_post_thumbnail( $translated_post_id, $translated_attachment_id );
			if (
				false === $updated
				&& $translated_attachment_id !== (int) get_post_thumbnail_id( $translated_post_id )
			) {
				return new WP_Error(
					'swq_full_article_featured_image_set_failed',
					'Could not set the translated media attachment as the translated post featured image.',
					[
						'translated_post_id' => $translated_post_id,
						'target_attachment_id' => $translated_attachment_id,
					]
				);
			}
		}

		$result = [
			'status'               => $attachment_result['status'],
			'source_attachment_id' => $source_thumbnail_id,
			'target_attachment_id' => $translated_attachment_id,
			'alt_source'           => $alt_result['alt_source'],
			'alt_status'           => $alt_result['status'],
			'warning_code'         => (string) ( $attachment_result['warning_code'] ?? '' ),
			'warning_message'      => (string) ( $attachment_result['warning_message'] ?? '' ),
		];
		self::trace_event( 'featured_image_sync_finished', $result );
		return $result;
	}

	/**
	 * @return array{status:string,alt_source:string}|WP_Error
	 */
	private static function persist_translated_attachment_alt(
		int $translated_attachment_id,
		string $target_alt,
		string $alt_source,
		bool $created
	) {
		$meta_key    = '_wp_attachment_image_alt';
		$current_alt = (string) get_post_meta( $translated_attachment_id, $meta_key, true );

		if ( '' !== $alt_source ) {
			if ( $current_alt === $target_alt ) {
				return [ 'status' => 'alt_unchanged', 'alt_source' => $alt_source ];
			}
			update_post_meta( $translated_attachment_id, $meta_key, $target_alt );
			if ( $target_alt !== (string) get_post_meta( $translated_attachment_id, $meta_key, true ) ) {
				return new WP_Error(
					'swq_featured_image_alt_update_failed',
					'The translated attachment alt text did not match the requested value after update.',
					[ 'target_attachment_id' => $translated_attachment_id ]
				);
			}
			return [
				'status'     => 'content' === $alt_source
					? 'alt_updated_from_content'
					: 'alt_updated_from_title',
				'alt_source' => $alt_source,
			];
		}

		if ( $created && '' !== $current_alt ) {
			delete_post_meta( $translated_attachment_id, $meta_key );
			if ( '' !== (string) get_post_meta( $translated_attachment_id, $meta_key, true ) ) {
				return new WP_Error(
					'swq_featured_image_alt_clear_failed',
					'The source-language alt text copied to the new attachment could not be cleared.',
					[ 'target_attachment_id' => $translated_attachment_id ]
				);
			}
			return [ 'status' => 'new_attachment_alt_cleared', 'alt_source' => '' ];
		}

		return [ 'status' => 'no_alt_source_preserved', 'alt_source' => '' ];
	}

	/**
	 * Create or reuse Polylang's target-language attachment record.
	 *
	 * @return array{attachment_id:int,created:bool,status:string}|WP_Error
	 */
	private static function create_polylang_media_translation(
		int $source_attachment_id,
		string $target_language
	) {
		if ( ! function_exists( 'pll_get_post' ) || ! function_exists( 'PLL' ) ) {
			return new WP_Error(
				'swq_featured_image_polylang_unavailable',
				'Polylang runtime APIs are unavailable for media translation.'
			);
		}
		if ( 'attachment' !== get_post_type( $source_attachment_id ) ) {
			return new WP_Error(
				'swq_featured_image_invalid_source_attachment',
				'The source featured image is not a valid attachment.'
			);
		}

		$polylang = PLL();
		if (
			! is_object( $polylang )
			|| empty( $polylang->model )
			|| empty( $polylang->model->post )
		) {
			return new WP_Error(
				'swq_featured_image_polylang_runtime_invalid',
				'Polylang translated-post runtime is unavailable.'
			);
		}
		if ( empty( $polylang->options['media_support'] ) ) {
			return new WP_Error(
				'swq_featured_image_media_support_disabled',
				'Polylang media translation support is disabled.'
			);
		}
		if (
			'' === $target_language
			|| ! is_object( $polylang->model->get_language( $target_language ) )
		) {
			return new WP_Error(
				'swq_featured_image_invalid_target_language',
				'The requested target language is not registered in Polylang.'
			);
		}

		$existing_id = absint( pll_get_post( $source_attachment_id, $target_language ) );
		if ( $existing_id > 0 ) {
			return self::validate_polylang_media_translation(
				$source_attachment_id,
				$existing_id,
				$target_language,
				false
			);
		}
		if ( ! is_callable( [ $polylang->model->post, 'create_media_translation' ] ) ) {
			return new WP_Error(
				'swq_featured_image_create_method_unavailable',
				'Polylang create_media_translation() is not callable.'
			);
		}

		try {
			$created_id = absint(
				$polylang->model->post->create_media_translation(
					$source_attachment_id,
					$target_language
				)
			);
		} catch ( Throwable $throwable ) {
			$relation_after_exception = absint(
				pll_get_post( $source_attachment_id, $target_language )
			);
			self::trace_event(
				'featured_image_polylang_create_exception',
				[
					'source_attachment_id'    => $source_attachment_id,
					'target_language'         => $target_language,
					'exception_class'         => get_class( $throwable ),
					'exception_message'       => $throwable->getMessage(),
					'relation_after_exception' => $relation_after_exception,
					'possible_orphan_attachment' => $relation_after_exception < 1,
				]
			);
			if ( $relation_after_exception > 0 ) {
				$recovered = self::validate_polylang_media_translation(
					$source_attachment_id,
					$relation_after_exception,
					$target_language,
					true
				);
				if ( ! is_wp_error( $recovered ) ) {
					$recovered['warning_code'] = 'polylang_exception_relation_recovered';
					$recovered['warning_message'] = sprintf(
						'Polylang raised %1$s while creating the media translation, but the resulting attachment relation was valid: %2$s',
						get_class( $throwable ),
						$throwable->getMessage()
					);
					return $recovered;
				}
			}
			return new WP_Error(
				'swq_featured_image_create_failed',
				'Polylang threw an exception while creating the target-language media attachment. No valid relation was found afterward; an unlinked partial attachment may exist.',
				[
					'exception_class' => get_class( $throwable ),
					'message'         => $throwable->getMessage(),
					'relation_after_exception' => $relation_after_exception,
					'possible_orphan_attachment' => true,
				]
			);
		}
		if ( $created_id < 1 ) {
			return new WP_Error(
				'swq_featured_image_create_failed',
				'Polylang could not create the target-language media attachment.'
			);
		}

		$verified_id = absint( pll_get_post( $source_attachment_id, $target_language ) );
		if ( $verified_id < 1 || $verified_id !== $created_id ) {
			return new WP_Error(
				'swq_featured_image_translation_relation_missing',
				'Polylang did not expose the created attachment translation relation.',
				[ 'created_attachment_id' => $created_id ]
			);
		}

		return self::validate_polylang_media_translation(
			$source_attachment_id,
			$verified_id,
			$target_language,
			true
		);
	}

	/**
	 * @return array{attachment_id:int,created:bool,status:string}|WP_Error
	 */
	private static function validate_polylang_media_translation(
		int $source_attachment_id,
		int $translated_attachment_id,
		string $target_language,
		bool $created
	) {
		if ( 'attachment' !== get_post_type( $translated_attachment_id ) ) {
			return new WP_Error(
				'swq_featured_image_invalid_translated_attachment',
				'Polylang returned a translation that is not an attachment.'
			);
		}
		if (
			! function_exists( 'pll_get_post_language' )
			|| $target_language !== (string) pll_get_post_language( $translated_attachment_id, 'slug' )
		) {
			return new WP_Error(
				'swq_featured_image_translated_language_mismatch',
				'The translated media attachment language does not match the target language.'
			);
		}
		if (
			$translated_attachment_id
			!== absint( pll_get_post( $source_attachment_id, $target_language ) )
		) {
			return new WP_Error(
				'swq_featured_image_translation_relation_invalid',
				'The target attachment is not linked to the source attachment in Polylang.'
			);
		}

		return [
			'attachment_id' => $translated_attachment_id,
			'created'       => $created,
			'status'        => $created ? 'translated_attachment_created' : 'translated_attachment_reused',
		];
	}

	private static function find_translated_image_alt_in_content(
		string $content,
		int $source_attachment_id,
		string $source_url,
		string $attached_file,
		array $attachment_metadata = []
	): string {
		if ( '' === $content ) {
			return '';
		}

		$class_marker = 'wp-image-' . $source_attachment_id;
		$class_matches = [];
		$url_matches   = [];
		$allowed_paths = self::build_source_image_paths(
			$source_url,
			$attached_file,
			$attachment_metadata
		);
		foreach ( self::read_image_attributes_from_content( $content ) as $attributes ) {
			$classes    = preg_split( '/\s+/', trim( $attributes['class'] ?? '' ) );
			if ( in_array( $class_marker, $classes, true ) ) {
				$class_matches[] = $attributes;
			} elseif ( self::image_attributes_match_source( $attributes, $allowed_paths ) ) {
				$url_matches[] = $attributes;
			}
		}

		foreach ( array_merge( $class_matches, $url_matches ) as $attributes ) {
			$alt = self::normalize_image_alt( $attributes['alt'] ?? '' );
			if ( '' !== $alt ) {
				return $alt;
			}
		}
		return '';
	}

	/**
	 * Read image attributes without modifying or serializing the source HTML.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function read_image_attributes_from_content( string $content ): array {
		$images = [];
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new WP_HTML_Tag_Processor( $content );
			while ( $processor->next_tag( 'IMG' ) ) {
				$attributes = [];
				foreach ( [ 'class', 'src', 'srcset', 'alt' ] as $name ) {
					$value = $processor->get_attribute( $name );
					if ( is_string( $value ) ) {
						$attributes[ $name ] = $value;
					}
				}
				$images[] = $attributes;
			}
			return $images;
		}

		foreach ( self::scan_img_tags( $content ) as $img_tag ) {
			$attributes = [];
			preg_match_all(
				'/\b([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(["\'])(.*?)\2/s',
				$img_tag,
				$matches,
				PREG_SET_ORDER
			);
			foreach ( $matches as $match ) {
				$name = strtolower( $match[1] );
				if ( in_array( $name, [ 'class', 'src', 'srcset', 'alt' ], true ) ) {
					$attributes[ $name ] = html_entity_decode(
						$match[3],
						ENT_QUOTES | ENT_HTML5,
						'UTF-8'
					);
				}
			}
			$images[] = $attributes;
		}
		return $images;
	}

	/**
	 * Compatibility scanner for WordPress versions without WP_HTML_Tag_Processor.
	 *
	 * @return string[]
	 */
	private static function scan_img_tags( string $content ): array {
		$tags   = [];
		$length = strlen( $content );
		$offset = 0;
		while ( $offset < $length && preg_match( '/<img\b/i', $content, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$start = (int) $match[0][1];
			$quote = '';
			for ( $index = $start + strlen( $match[0][0] ); $index < $length; ++$index ) {
				$character = $content[ $index ];
				if ( '' !== $quote ) {
					if ( $character === $quote ) {
						$quote = '';
					}
					continue;
				}
				if ( '"' === $character || "'" === $character ) {
					$quote = $character;
				} elseif ( '>' === $character ) {
					$tags[] = substr( $content, $start, $index - $start + 1 );
					$offset = $index + 1;
					continue 2;
				}
			}
			break;
		}
		return $tags;
	}

	/**
	 * @param array<string,string> $attributes
	 * @param string[]             $allowed_paths
	 */
	private static function image_attributes_match_source(
		array $attributes,
		array $allowed_paths
	): bool {
		if ( empty( $allowed_paths ) ) {
			return false;
		}

		$urls = [];
		if ( ! empty( $attributes['src'] ) ) {
			$urls[] = $attributes['src'];
		}
		if ( ! empty( $attributes['srcset'] ) ) {
			foreach ( explode( ',', $attributes['srcset'] ) as $srcset_item ) {
				$urls[] = preg_split( '/\s+/', trim( $srcset_item ) )[0] ?? '';
			}
		}
		foreach ( $urls as $url ) {
			$candidate = self::normalize_image_path( $url );
			if ( '' !== $candidate && in_array( $candidate, $allowed_paths, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $attachment_metadata
	 * @return string[]
	 */
	private static function build_source_image_paths(
		string $source_url,
		string $attached_file,
		array $attachment_metadata
	): array {
		$base_paths = array_values(
			array_unique(
				array_filter(
					[
						self::normalize_image_path( $source_url ),
						self::normalize_image_path( $attached_file ),
						self::normalize_image_path( (string) ( $attachment_metadata['file'] ?? '' ) ),
					]
				)
			)
		);
		$paths = $base_paths;
		$sizes = isset( $attachment_metadata['sizes'] ) && is_array( $attachment_metadata['sizes'] )
			? $attachment_metadata['sizes']
			: [];
		foreach ( $sizes as $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
				continue;
			}
			foreach ( $base_paths as $base_path ) {
				$paths[] = self::normalize_image_path(
					rtrim( dirname( $base_path ), '/' ) . '/' . ltrim( $size['file'], '/' )
				);
			}
		}
		return array_values( array_unique( array_filter( $paths ) ) );
	}

	private static function normalize_image_path( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$path = wp_parse_url( $value, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : strtok( $value, '?#' );
		$path = rawurldecode( (string) $path );
		return '/' . ltrim( $path, '/' );
	}

	private static function normalize_image_alt( string $value ): string {
		return trim( $value );
	}

	private static function normalize_translated_title_alt( string $value ): string {
		return trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Persist the already validated translated post content byte-for-byte.
	 *
	 * SlyTranslate still performs its normal post creation, status, taxonomy,
	 * metadata, language and translation-link workflow first. This final write
	 * only restores the exact validated post_content after WordPress save
	 * filters have run. It prevents KSES or another content_save_pre filter from
	 * removing Code Block Pro attributes such as tabindex/data-* or changing
	 * serialized codeHTML escaping.
	 *
	 * @return true|WP_Error
	 */
	private static function persist_exact_post_content(
		int $translated_post_id,
		string $validated_content
	) {
		global $wpdb;

		if ( $translated_post_id < 1 || ! isset( $wpdb->posts ) ) {
			return new WP_Error(
				'swq_full_article_exact_persist_invalid_target',
				'Could not resolve the translated post table or post ID for exact content persistence.'
			);
		}

		$expected_hash = hash( 'sha256', $validated_content );
		$stored_before = (string) get_post_field(
			'post_content',
			$translated_post_id,
			'raw'
		);
		$before_hash = hash( 'sha256', $stored_before );

		self::trace_event(
			'exact_content_persist_started',
			[
				'translated_post_id' => $translated_post_id,
				'expected_chars'     => self::char_length( $validated_content ),
				'expected_hash'      => $expected_hash,
				'before_hash'        => $before_hash,
				'already_identical'  => hash_equals( $expected_hash, $before_hash ),
			]
		);

		if ( ! hash_equals( $expected_hash, $before_hash ) ) {
			$updated = $wpdb->update(
				$wpdb->posts,
				[ 'post_content' => $validated_content ],
				[ 'ID' => $translated_post_id ],
				[ '%s' ],
				[ '%d' ]
			);

			if ( false === $updated ) {
				self::trace_event(
					'exact_content_persist_failed',
					[
						'translated_post_id' => $translated_post_id,
						'database_error'     => (string) $wpdb->last_error,
					]
				);

				return new WP_Error(
					'swq_full_article_exact_persist_database_failed',
					'Could not persist the validated translated content exactly.',
					[
						'translated_post_id' => $translated_post_id,
						'database_error'     => (string) $wpdb->last_error,
					]
				);
			}
		}

		clean_post_cache( $translated_post_id );

		$stored_after = (string) get_post_field(
			'post_content',
			$translated_post_id,
			'raw'
		);
		$after_hash = hash( 'sha256', $stored_after );
		$verified   = hash_equals( $expected_hash, $after_hash );

		self::trace_event(
			'exact_content_persist_finished',
			[
				'translated_post_id' => $translated_post_id,
				'expected_hash'      => $expected_hash,
				'after_hash'         => $after_hash,
				'verified'           => $verified,
			]
		);

		if ( ! $verified ) {
			return new WP_Error(
				'swq_full_article_exact_persist_hash_mismatch',
				'The translated post content changed during the final database round trip.',
				[
					'translated_post_id' => $translated_post_id,
					'expected_hash'      => $expected_hash,
					'stored_hash'        => $after_hash,
				]
			);
		}

		return true;
	}

	private static function translate_field_value(
		string $source,
		string $to,
		string $from,
		string $field,
		string $unit_id,
		int $post_id,
		string $additional_prompt
	) {
		$prompt = self::build_field_prompt( $additional_prompt, $field, $to );
		self::trace_event(
			'field_raw_started',
			[
				'field'        => $field,
				'unit_id'      => $unit_id,
				'source_chars' => self::char_length( $source ),
			]
		);

		try {
			$result = SlyTranslate\TranslationRuntime::translate_text_raw(
				$source,
				$to,
				$from,
				$prompt
			);
		} catch ( Throwable $throwable ) {
			$result = new WP_Error( 'swq_field_translation_exception', $throwable->getMessage() );
		}

		self::trace_event(
			'field_raw_finished',
			[
				'field'        => $field,
				'unit_id'      => $unit_id,
				'result_type'  => self::value_type( $result ),
				'error_code'   => is_wp_error( $result ) ? $result->get_error_code() : null,
				'output_chars' => is_string( $result ) ? self::char_length( $result ) : null,
			]
		);

		if ( is_wp_error( $result ) ) {
			return self::wrap_field_error( $result, $post_id, $field, $unit_id, $source, '' );
		}

		$translated = self::unwrap_model_output( (string) $result );
		if ( '' === trim( $translated ) ) {
			return self::wrap_field_error(
				new WP_Error( 'invalid_translation_empty', 'The translated field is empty.' ),
				$post_id,
				$field,
				$unit_id,
				$source,
				$translated
			);
		}

		if ( ! class_exists( 'SlyTranslate\TranslationValidator' ) ) {
			return self::wrap_field_error(
				new WP_Error( 'validator_unavailable', 'SlyTranslate TranslationValidator is unavailable.' ),
				$post_id,
				$field,
				$unit_id,
				$source,
				$translated
			);
		}

		$validation = SlyTranslate\TranslationValidator::validate( $source, $translated, $to );
		if ( is_wp_error( $validation ) ) {
			$error_code = (string) $validation->get_error_code();

			if ( self::is_field_assistant_false_positive( $error_code, $source, $translated ) ) {
				self::trace_event(
					'field_assistant_validation_false_positive_bypassed',
					[
						'field'  => $field,
						'unit_id' => $unit_id,
						'signals' => self::broad_assistant_false_positive_signals( $source, $translated ),
					]
				);
				return $translated;
			}

			if ( str_contains( $error_code, 'runaway' ) ) {
				$source_length     = max( 1, self::char_length( $source ) );
				$translated_length = self::char_length( $translated );

				if ( $translated_length <= $source_length * self::FULL_ARTICLE_RUNAWAY_OUTPUT_RATIO ) {
					return $translated;
				}
			}

			return self::wrap_field_error(
				$validation,
				$post_id,
				$field,
				$unit_id,
				$source,
				$translated
			);
		}

		return $translated;
	}

	/**
	 * The generic validator treats any new occurrence of the word "critical"
	 * as prompt leakage. That is too broad for ordinary technical prose (for
	 * example, "critical warnings"). Keep the field-level exception narrower
	 * than the full-article compatibility path: only the standalone ordinary
	 * critical-word signal is bypassed, and only when no strong prompt marker
	 * is present.
	 */
	private static function is_field_assistant_false_positive( string $error_code, string $source, string $translated ): bool {
		if ( 'invalid_translation_assistant_reply' !== $error_code ) {
			return false;
		}

		$strong_markers = self::strong_prompt_leakage_markers( $source, $translated );
		if ( ! empty( $strong_markers ) ) {
			return false;
		}

		if ( 1 === preg_match( '/\bcritical\s*:/iu', $translated )
			|| 1 === preg_match( '/\bcritical\s+(?:output|instruction|instructions|rule|rules|translation|format|return|apply|keep|obey)\b/iu', $translated ) ) {
			return false;
		}

		$signals = self::broad_assistant_false_positive_signals( $source, $translated );

		return [ 'ordinary_critical_word' ] === $signals;
	}

	private static function wrap_field_error(
		WP_Error $error,
		int $post_id,
		string $field,
		string $unit_id,
		string $source,
		string $translated
	): WP_Error {
		$summary_file = '/tmp/swq-glm52-field-error-summary.json';
		$summary = [
			'post_id'          => $post_id,
			'field'            => $field,
			'unit_id'          => $unit_id,
			'source_chars'     => self::char_length( $source ),
			'output_chars'     => self::char_length( $translated ),
			'error_code'       => (string) $error->get_error_code(),
			'error_message'    => $error->get_error_message(),
			'source_excerpt'   => self::char_slice( $source, 0, 500 ),
			'output_excerpt'   => self::char_slice( $translated, 0, 500 ),
		];

		$summary_json = wp_json_encode(
			$summary,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);

		if ( is_string( $summary_json ) ) {
			@file_put_contents( $summary_file, $summary_json . "\n", LOCK_EX );
			@chmod( $summary_file, 0600 );
		}

		return new WP_Error(
			'swq_field_translation_failed',
			sprintf(
				'Field translation failed: field=%s, unit_id=%s, reason=%s. Diagnostic: %s',
				'' !== $field ? $field : '(unknown)',
				'' !== $unit_id ? $unit_id : '(unknown)',
				(string) $error->get_error_code(),
				$summary_file
			),
			$error->get_error_data()
		);
	}

	private static function translate_full_content(
		string $content,
		string $to,
		string $from,
		string $additional_prompt,
		int $post_id,
		string $article_title,
		string $article_excerpt
	) {
		$protected = self::protect_content( $content );
		if ( is_wp_error( $protected ) ) {
			return $protected;
		}

		$payload          = $protected['payload'];
		$model_payload    = $payload . "\n" . self::TRANSLATION_TAIL_GUARD;
		$map              = $protected['map'];
		$plain_regions    = $protected['plain_regions'];
		$alt_regions      = $protected['alt_regions'];
		$prompt_parts = [];

		if ( '' !== trim( $additional_prompt ) ) {
			$prompt_parts[] = "OPTIONAL ARTICLE-SPECIFIC INSTRUCTIONS:\n" . trim( $additional_prompt );
		}
		if ( '' !== trim( $article_title ) ) {
			$prompt_parts[] = "Article title for context only:\n" . trim( $article_title );
		}
		if ( '' !== trim( $article_excerpt ) ) {
			$prompt_parts[] = "Article summary for context only:\n" . trim( $article_excerpt );
		}

		$prompt_parts[] = self::build_content_prompt( $to );

		$full_prompt = implode( "\n\n", $prompt_parts );
		self::$state = [
			'active'          => true,
			'post_id'         => $post_id,
			'request_count'   => 0,
			'request_model'   => '',
			'payload_chars'   => self::char_length( $model_payload ),
			'protected_count' => count( $map ),
			'max_tokens'      => 0,
		];

		error_log(
			sprintf(
				'[SWQ GLM-5.2 Full Article] start post_id=%d payload_chars=%d protected_tokens=%d',
				$post_id,
				self::$state['payload_chars'],
				self::$state['protected_count']
			)
		);

		self::trace_event(
			'content_raw_started',
			[
				'payload_chars'   => self::char_length( $model_payload ),
				'protected_count' => count( $map ),
				'translation_tail_guard_expected' => true,
				'target_language' => $to,
				'prompt_policy'   => self::PROMPT_POLICY_VERSION,
			]
		);

		try {
			$result = SlyTranslate\TranslationRuntime::translate_text_raw( $model_payload, $to, $from, $full_prompt );
		} catch ( Throwable $throwable ) {
			$result = new WP_Error( 'swq_full_article_exception', $throwable->getMessage() );
		} finally {
			self::$state['active'] = false;
		}

		$state = self::$state;
		self::trace_event(
			'content_raw_finished',
			[
				'result_type'   => self::value_type( $result ),
				'error_code'    => is_wp_error( $result ) ? $result->get_error_code() : null,
				'output_chars'  => is_string( $result ) ? self::char_length( $result ) : null,
				'request_count' => $state['request_count'],
				'model'         => $state['request_model'],
			]
		);

		if ( is_wp_error( $result ) ) {
			error_log(
				sprintf(
					'[SWQ GLM-5.2 Full Article] failed post_id=%d requests=%d model=%s error=%s',
					$post_id,
					$state['request_count'],
					$state['request_model'],
					$result->get_error_code()
				)
			);
			return $result;
		}

		if ( 1 !== $state['request_count'] ) {
			return new WP_Error(
				'swq_full_article_request_count_invalid',
				sprintf( 'Full-article translation expected exactly one model request, but observed %d.', $state['request_count'] )
			);
		}

		$raw_model_response = (string) $result;
		$translated_payload = self::unwrap_model_output( $raw_model_response );
		$tail_guard         = self::remove_translation_tail_guard( $translated_payload );
		$translated_payload = $tail_guard['translated'];
		self::log_translation_tail_guard( $tail_guard );

		preg_match_all( self::PROTECTED_TOKEN_REGEX, $payload, $source_token_matches );
		$expected_tokens = isset( $source_token_matches[0] ) && is_array( $source_token_matches[0] )
			? $source_token_matches[0]
			: [];

		// Apply only unambiguous token repairs that pass the full strict validation.
		$translated_payload = self::normalize_unique_protected_tokens( $translated_payload, $expected_tokens );
		$translated_payload = self::recover_uniquely_located_missing_token(
			$translated_payload,
			$payload,
			$expected_tokens
		);
		$translated_payload = self::repair_uniquely_misnumbered_adjacent_token(
			$translated_payload,
			$expected_tokens
		);
		$token_validation   = self::validate_protected_tokens(
			$translated_payload,
			$expected_tokens,
			$payload,
			$map,
			$raw_model_response
		);
		if ( is_wp_error( $token_validation ) ) {
			$tail_repair = self::repair_missing_struct_token_tail(
				$translated_payload,
				$expected_tokens,
				$map
			);

			if ( ! $tail_repair['attempted'] ) {
				self::log_protected_token_tail_repair( $tail_repair, 'not_run' );
				return $token_validation;
			}

			$repaired_validation = self::validate_protected_tokens(
				$tail_repair['translated'],
				$expected_tokens,
				$payload,
				$map,
				$raw_model_response
			);
			$revalidation_status = is_wp_error( $repaired_validation ) ? 'failed' : 'passed';
			self::log_protected_token_tail_repair( $tail_repair, $revalidation_status );

			if ( is_wp_error( $repaired_validation ) ) {
				return $repaired_validation;
			}

			$translated_payload = $tail_repair['translated'];
		}

		$plaintext_validation = self::validate_plaintext_regions(
			$translated_payload,
			$plain_regions
		);
		if ( is_wp_error( $plaintext_validation ) ) {
			return $plaintext_validation;
		}

		$translation_validation = self::validate_translation( $payload, $translated_payload, $to );
		if ( is_wp_error( $translation_validation ) ) {
			return $translation_validation;
		}

		$restored_regions = self::restore_translatable_regions(
			$translated_payload,
			$plain_regions,
			$alt_regions
		);
		if ( is_wp_error( $restored_regions ) ) {
			return $restored_regions;
		}

		$restored = str_replace( array_keys( $map ), array_values( $map ), $restored_regions );

		if ( str_contains( $restored, self::TRANSLATION_TAIL_GUARD ) ) {
			return new WP_Error( 'swq_full_article_tail_guard_cleanup_failed', 'The internal translation tail guard remained after cleanup.' );
		}

		if ( preg_match( self::PROTECTED_TOKEN_REGEX, $restored ) ) {
			return new WP_Error( 'swq_full_article_restore_failed', 'One or more protected tokens remained after restoration.' );
		}

		if ( self::block_signature( $content ) !== self::block_signature( $restored ) ) {
			return new WP_Error(
				'swq_full_article_gutenberg_structure_drift',
				'The Gutenberg block structure changed during full-article translation.'
			);
		}

		error_log(
			sprintf(
				'[SWQ GLM-5.2 Full Article] success post_id=%d requests=%d model=%s max_tokens=%d source_chars=%d payload_chars=%d output_chars=%d protected_tokens=%d chunk_fallback=disabled',
				$post_id,
				$state['request_count'],
				$state['request_model'],
				$state['max_tokens'],
				self::char_length( $content ),
				$state['payload_chars'],
				self::char_length( $restored ),
				$state['protected_count']
			)
		);

		return $restored;
	}


	private static function protect_content( string $content ) {
		if ( preg_match( self::PROTECTED_TOKEN_REGEX, $content )
			|| str_contains( $content, self::TRANSLATION_TAIL_GUARD )
		) {
			return new WP_Error(
				'swq_full_article_token_collision',
				'The source content already contains a reserved protected token.'
			);
		}

		$map            = [];
		$plain_regions  = [];
		$alt_regions    = [];
		$block_index    = 0;
		$inline_index   = 0;
		$html_index     = 0;
		$struct_index   = 0;
		$plain_index    = 0;
		$alt_index      = 0;

		$replace_block = static function ( array $matches ) use ( &$map, &$block_index ): string {
			$block_index++;
			$token = sprintf( 'SWQBLOCK%06dEND', $block_index );
			$map[ $token ] = (string) $matches[0];
			return $token;
		};

		$replace_inline = static function ( array $matches ) use ( &$map, &$inline_index ): string {
			$inline_index++;
			$token = sprintf( 'SWQINLINE%06dEND', $inline_index );
			$map[ $token ] = (string) $matches[0];
			return $token;
		};

		$replace_html = static function ( string $html ) use ( &$map, &$html_index ): string {
			$html_index++;
			$token = sprintf( 'SWQHTML%06dEND', $html_index );
			$map[ $token ] = $html;
			return $token;
		};

		$replace_structure = static function ( array $matches ) use ( &$map, &$struct_index ): string {
			$struct_index++;
			$token = sprintf( 'SWQSTRUCT%06dEND', $struct_index );
			$map[ $token ] = (string) $matches[0];
			return $token;
		};

		$make_plain_region = static function (
			string $original,
			string $source_text,
			string $type,
			bool $preserve_source = false
		) use ( &$plain_regions, &$plain_index ): string {
			$plain_index++;
			$start_token = sprintf( 'SWQPLAINSTART%06dEND', $plain_index );
			$stop_token  = sprintf( 'SWQPLAINSTOP%06dEND', $plain_index );
			$plain_regions[] = [
				'start_token'     => $start_token,
				'stop_token'      => $stop_token,
				'original'        => $original,
				'source_text'     => $source_text,
				'type'            => $type,
				'preserve_source' => $preserve_source,
			];

			return $start_token . "\n" . rtrim( $source_text, "\r\n" ) . "\n" . $stop_token;
		};

		$make_alt_region = static function (
			string $original,
			string $source_text
		) use ( &$alt_regions, &$alt_index ): string {
			$alt_index++;
			$start_token = sprintf( 'SWQALTSTART%06dEND', $alt_index );
			$stop_token  = sprintf( 'SWQALTSTOP%06dEND', $alt_index );
			$alt_regions[] = [
				'start_token' => $start_token,
				'stop_token'  => $stop_token,
				'original'    => $original,
				'source_text' => $source_text,
			];

			return $start_token . "\n" . trim( $source_text ) . "\n" . $stop_token;
		};

		$payload = $content;

		/*
		 * Code Block Pro blocks explicitly marked as Plaintext are special:
		 * expose their readable contents to the model so surrounding prose can
		 * be translated with the correct semantic context. Plaintext containing
		 * Han text may be translated; Plaintext containing only machine data,
		 * URLs, domains or identifiers is restored from the exact source text.
		 * Other code languages remain protected byte-for-byte as complete blocks.
		 */
		$next = preg_replace_callback(
			'~<!--\s+wp:kevinbatdorf/code-block-pro\b[^>]*-->.*?<!--\s+/wp:kevinbatdorf/code-block-pro\s+-->~isu',
			static function ( array $matches ) use ( $replace_block, $make_plain_region ): string {
				$block = (string) $matches[0];
				$text  = self::extract_plaintext_code_block_pro_text( $block );

				if ( null !== $text ) {
					return $make_plain_region(
						$block,
						$text,
						'code_block_pro',
						! self::contains_han_text( $text )
					);
				}

				return $replace_block( $matches );
			},
			$payload
		);
		if ( ! is_string( $next ) ) {
			return new WP_Error(
				'swq_full_article_code_block_protection_failed',
				'Could not protect or prepare Code Block Pro blocks.'
			);
		}
		$payload = $next;

		$next = preg_replace_callback(
			'~<!--\s+wp:(syntaxhighlighter/code|code)\b[^>]*-->.*?<!--\s+/wp:\1\s+-->~isu',
			$replace_block,
			$payload
		);
		if ( ! is_string( $next ) ) {
			return new WP_Error(
				'swq_full_article_code_block_protection_failed',
				'Could not protect non-translatable Gutenberg code blocks.'
			);
		}
		$payload = $next;

		$next = preg_replace_callback(
			'~<!--\s+wp:preformatted\b[^>]*-->.*?<!--\s+/wp:preformatted\s+-->~isu',
			static function ( array $matches ) use ( $replace_block, $make_plain_region ): string {
				$block = (string) $matches[0];
				$text  = self::extract_pre_element_text( $block );

				if ( null !== $text && self::contains_han_text( $text ) ) {
					return $make_plain_region( $block, $text, 'preformatted' );
				}

				return $replace_block( $matches );
			},
			$payload
		);
		if ( ! is_string( $next ) ) {
			return new WP_Error(
				'swq_full_article_preformatted_protection_failed',
				'Could not protect or prepare Gutenberg preformatted blocks.'
			);
		}
		$payload = $next;

		/* Remaining standalone pre elements are handled conservatively. */
		$next = preg_replace_callback(
			'~<pre\b[^>]*>.*?</pre\s*>~isu',
			static function ( array $matches ) use ( $replace_block, $make_plain_region ): string {
				$element = (string) $matches[0];
				$text    = self::extract_pre_element_text( $element );

				if ( null !== $text && self::contains_han_text( $text ) ) {
					return $make_plain_region( $element, $text, 'raw_pre' );
				}

				return $replace_block( $matches );
			},
			$payload
		);
		if ( ! is_string( $next ) ) {
			return new WP_Error(
				'swq_full_article_pre_element_protection_failed',
				'Could not protect or prepare standalone pre elements.'
			);
		}
		$payload = $next;

		$block_patterns = [
			[
				'pattern' => '~<(script|style|textarea)\b[^>]*>.*?</\\1\s*>~isu',
				'code'    => 'swq_full_article_block_code_element_protection_failed',
				'message' => 'Could not protect non-translatable block code elements.',
			],
			[
				'pattern' => '~```[\s\S]*?```~u',
				'code'    => 'swq_full_article_markdown_fence_protection_failed',
				'message' => 'Could not protect fenced Markdown code blocks.',
			],
		];

		foreach ( $block_patterns as $rule ) {
			$next = preg_replace_callback( $rule['pattern'], $replace_block, $payload );

			if ( ! is_string( $next ) ) {
				return new WP_Error( $rule['code'], $rule['message'] );
			}

			$payload = $next;
		}

		/*
		 * Image tags are never sent to the model. A natural-language alt value
		 * is exposed separately and is safely HTML-escaped when written back.
		 */
		$next = preg_replace_callback(
			'~<img\b[^>]*>~isu',
			static function ( array $matches ) use ( $replace_html, $make_alt_region ): string {
				$tag = (string) $matches[0];
				if ( preg_match( '~\balt\s*=\s*(["\'])(.*?)\\1~isu', $tag, $alt_match ) ) {
					$alt_text = html_entity_decode(
						(string) $alt_match[2],
						ENT_QUOTES | ENT_HTML5,
						'UTF-8'
					);

					if ( '' !== trim( $alt_text ) && self::contains_han_text( $alt_text ) ) {
						return $make_alt_region( $tag, $alt_text );
					}
				}

				return $replace_html( $tag );
			},
			$payload
		);
		if ( ! is_string( $next ) ) {
			return new WP_Error(
				'swq_full_article_image_tag_protection_failed',
				'Could not protect image tags or prepare image alt text.'
			);
		}
		$payload = $next;

		$inline_patterns = [
			[
				'pattern' => '~<(code|kbd)\b[^>]*>.*?</\\1\s*>~isu',
				'code'    => 'swq_full_article_inline_code_element_protection_failed',
				'message' => 'Could not protect non-translatable inline code elements.',
			],
		];

		foreach ( $inline_patterns as $rule ) {
			$next = preg_replace_callback( $rule['pattern'], $replace_inline, $payload );

			if ( ! is_string( $next ) ) {
				return new WP_Error( $rule['code'], $rule['message'] );
			}

			$payload = $next;
		}

		/*
		 * Gutenberg block comments are non-translatable structural boundaries.
		 * Replace every remaining opening and closing block comment with an
		 * immovable token so the model cannot merge, delete or reorder blocks.
		 * Comments inside protected code blocks have already been removed from
		 * the payload and remain covered by their parent block token.
		 */
		$next = preg_replace_callback(
			'~<!--\s+/?wp:[\s\S]*?-->~u',
			$replace_structure,
			$payload
		);
		if ( ! is_string( $next ) ) {
			return new WP_Error(
				'swq_full_article_gutenberg_comment_protection_failed',
				'Could not protect Gutenberg block comments.'
			);
		}
		$payload = $next;

		return [
			'payload'       => $payload,
			'map'           => $map,
			'plain_regions' => $plain_regions,
			'alt_regions'   => $alt_regions,
		];
	}

	private static function contains_han_text( string $text ): bool {
		return 1 === preg_match( '/\p{Han}/u', $text );
	}

	private static function extract_plaintext_code_block_pro_text( string $block ): ?string {
		$attributes = self::code_block_pro_attributes( $block );
		$language   = strtolower( trim( (string) ( $attributes['language'] ?? '' ) ) );

		if ( ! in_array( $language, [ 'plaintext', 'plain', 'text', 'txt' ], true ) ) {
			return null;
		}

		if ( isset( $attributes['code'] ) && is_string( $attributes['code'] ) ) {
			return $attributes['code'];
		}

		if ( preg_match( '~<textarea\b[^>]*>(.*?)</textarea\s*>~isu', $block, $matches ) ) {
			return html_entity_decode(
				(string) $matches[1],
				ENT_QUOTES | ENT_HTML5,
				'UTF-8'
			);
		}

		return null;
	}

	private static function code_block_pro_attributes( string $block ): array {
		if (
			! preg_match(
				'~<!--\s+wp:kevinbatdorf/code-block-pro(?:\s+(\{.*?\}))?\s+-->~su',
				$block,
				$matches
			)
			|| empty( $matches[1] )
		) {
			return [];
		}

		$attributes = json_decode( (string) $matches[1], true );
		return is_array( $attributes ) ? $attributes : [];
	}

	private static function extract_pre_element_text( string $html ): ?string {
		if ( ! preg_match( '~<pre\b[^>]*>(.*?)</pre\s*>~isu', $html, $matches ) ) {
			return null;
		}

		$inner = (string) $matches[1];
		$inner = (string) preg_replace( '~<br\s*/?>~iu', "\n", $inner );
		$inner = strip_tags( $inner );

		return html_entity_decode( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function restore_translatable_regions(
		string $payload,
		array $plain_regions,
		array $alt_regions
	) {
		foreach ( $plain_regions as $region ) {
			$restored = self::restore_plain_region( $payload, $region );
			if ( is_wp_error( $restored ) ) {
				return $restored;
			}
			$payload = $restored;
		}

		foreach ( $alt_regions as $region ) {
			$restored = self::restore_alt_region( $payload, $region );
			if ( is_wp_error( $restored ) ) {
				return $restored;
			}
			$payload = $restored;
		}

		return $payload;
	}

	private static function plaintext_structure_error( string $code, string $message ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			[
				'status'         => 422,
				'swq_error_type' => 'plaintext_structure_error',
				'retryable'      => false,
			]
		);
	}

	private static function validate_plaintext_regions( string $payload, array $plain_regions ) {
		foreach ( $plain_regions as $region ) {
			$start_token = (string) ( $region['start_token'] ?? '' );
			$stop_token  = (string) ( $region['stop_token'] ?? '' );
			$source_text = (string) ( $region['source_text'] ?? '' );
			$match       = self::extract_marker_region( $payload, $start_token, $stop_token );

			if ( is_wp_error( $match ) ) {
				return self::plaintext_structure_error(
					'swq_full_article_plaintext_region_missing',
					sprintf( 'Could not restore the Plaintext region beginning with %s.', $start_token )
				);
			}

			$translated_text = (string) $match['text'];
			if ( '' !== trim( $source_text ) && '' === trim( $translated_text ) ) {
				return self::plaintext_structure_error(
					'swq_full_article_plaintext_translation_empty',
					sprintf( 'The Plaintext region beginning with %s was empty after translation.', $start_token )
				);
			}

			if ( preg_match( self::PROTECTED_TOKEN_REGEX, $translated_text ) ) {
				return self::plaintext_structure_error(
					'swq_full_article_plaintext_boundary_drift',
					sprintf(
						'The Plaintext region beginning with %s swallowed a protected boundary or adjacent region.',
						$start_token
					)
				);
			}
		}

		return true;
	}

	private static function restore_plain_region( string $payload, array $region ) {
		$start_token = (string) ( $region['start_token'] ?? '' );
		$stop_token  = (string) ( $region['stop_token'] ?? '' );
		$source_text     = (string) ( $region['source_text'] ?? '' );
		$original        = (string) ( $region['original'] ?? '' );
		$type            = (string) ( $region['type'] ?? '' );
		$preserve_source = ! empty( $region['preserve_source'] );

		$match = self::extract_marker_region( $payload, $start_token, $stop_token );
		if ( is_wp_error( $match ) ) {
			return $match;
		}

		if ( $preserve_source ) {
			/*
			 * The model saw this machine-readable value for semantic context,
			 * but restoration must use the complete original block byte-for-byte.
			 * Rebuilding a Code Block Pro block, even with identical visible text,
			 * can change serialized attributes or saved HTML and make Gutenberg
			 * report the block as invalid.
			 */
			return substr_replace(
				$payload,
				$original,
				(int) $match['offset'],
				(int) $match['length']
			);
		}

		$translated_text = self::normalize_plaintext_translation(
			(string) $match['text'],
			$source_text
		);
		if ( is_wp_error( $translated_text ) ) {
			return $translated_text;
		}

		switch ( $type ) {
			case 'code_block_pro':
				$replacement = self::rebuild_plaintext_code_block_pro( $original, $translated_text );
				break;
			case 'preformatted':
			case 'raw_pre':
				$replacement = self::rebuild_pre_element( $original, $translated_text );
				break;
			default:
				return new WP_Error(
					'swq_full_article_plaintext_region_type_invalid',
					'An unknown Plaintext region type was encountered during restoration.'
				);
		}

		if ( is_wp_error( $replacement ) ) {
			return $replacement;
		}

		return substr_replace(
			$payload,
			$replacement,
			(int) $match['offset'],
			(int) $match['length']
		);
	}

	private static function restore_alt_region( string $payload, array $region ) {
		$start_token = (string) ( $region['start_token'] ?? '' );
		$stop_token  = (string) ( $region['stop_token'] ?? '' );
		$original    = (string) ( $region['original'] ?? '' );

		$match = self::extract_marker_region( $payload, $start_token, $stop_token );
		if ( is_wp_error( $match ) ) {
			return $match;
		}

		$translated_alt = trim( (string) $match['text'] );
		$translated_alt = (string) preg_replace( '/\s+/u', ' ', $translated_alt );
		if ( '' === $translated_alt ) {
			return new WP_Error(
				'swq_full_article_alt_translation_empty',
				'An image alt translation was empty.'
			);
		}

		$replacement = self::rebuild_image_alt( $original, $translated_alt );
		if ( is_wp_error( $replacement ) ) {
			return $replacement;
		}

		return substr_replace(
			$payload,
			$replacement,
			(int) $match['offset'],
			(int) $match['length']
		);
	}

	private static function extract_marker_region(
		string $payload,
		string $start_token,
		string $stop_token
	) {
		if ( '' === $start_token || '' === $stop_token ) {
			return new WP_Error(
				'swq_full_article_marker_invalid',
				'A translatable-region marker was invalid.'
			);
		}

		$pattern = '~'
			. preg_quote( $start_token, '~' )
			. '[ \t]*\R(.*?)\R[ \t]*'
			. preg_quote( $stop_token, '~' )
			. '~su';

		if ( ! preg_match( $pattern, $payload, $matches, PREG_OFFSET_CAPTURE ) ) {
			return new WP_Error(
				'swq_full_article_marker_region_missing',
				sprintf( 'Could not restore the region beginning with %s.', $start_token )
			);
		}

		return [
			'text'   => (string) $matches[1][0],
			'offset' => (int) $matches[0][1],
			'length' => strlen( (string) $matches[0][0] ),
		];
	}

	private static function normalize_plaintext_translation(
		string $translated,
		string $source
	) {
		$source_newline = str_contains( $source, "\r\n" ) ? "\r\n" : "\n";
		$source_lf      = str_replace( [ "\r\n", "\r" ], "\n", $source );
		$translated_lf  = str_replace( [ "\r\n", "\r" ], "\n", trim( $translated, "\r\n" ) );
		$source_body    = rtrim( $source_lf, "\n" );
		$source_trailer = substr( $source_lf, strlen( $source_body ) );
		$normalized     = $translated_lf . $source_trailer;
		if ( "\r\n" === $source_newline ) {
			$normalized = str_replace( "\n", "\r\n", $normalized );
		}

		return $normalized;
	}

	private static function rebuild_plaintext_code_block_pro(
		string $block,
		string $translated_text
	) {
		$attributes = self::code_block_pro_attributes( $block );
		if ( [] === $attributes ) {
			return new WP_Error(
				'swq_full_article_plaintext_attributes_invalid',
				'Could not decode Code Block Pro attributes during Plaintext restoration.'
			);
		}

		$existing_code_html = isset( $attributes['codeHTML'] ) && is_string( $attributes['codeHTML'] )
			? $attributes['codeHTML']
			: '';
		$code_html = self::rebuild_code_block_pro_code_html( $existing_code_html, $translated_text );

		$attributes['code']              = $translated_text;
		$attributes['codeHTML']          = $code_html;
		$attributes['highestLineNumber'] = self::plaintext_line_count( $translated_text );

		if ( function_exists( 'serialize_block_attributes' ) ) {
			$encoded_attributes = serialize_block_attributes( $attributes );
		} else {
			$encoded_attributes = wp_json_encode(
				$attributes,
				JSON_UNESCAPED_UNICODE
				| JSON_UNESCAPED_SLASHES
				| JSON_HEX_TAG
				| JSON_HEX_AMP
				| JSON_HEX_APOS
				| JSON_HEX_QUOT
			);
		}

		if ( ! is_string( $encoded_attributes ) || '' === $encoded_attributes ) {
			return new WP_Error(
				'swq_full_article_plaintext_attributes_encode_failed',
				'Could not encode Code Block Pro attributes after Plaintext translation.'
			);
		}

		$next = preg_replace_callback(
			'~<!--\s+wp:kevinbatdorf/code-block-pro(?:\s+\{.*?\})?\s+-->~su',
			static fn (): string => '<!-- wp:kevinbatdorf/code-block-pro ' . $encoded_attributes . ' -->',
			$block,
			1
		);
		if ( ! is_string( $next ) ) {
			return new WP_Error(
				'swq_full_article_plaintext_comment_rebuild_failed',
				'Could not rebuild the Code Block Pro opening comment.'
			);
		}
		$block = $next;

		$escaped_text = self::escape_html_text( $translated_text );
		$next = preg_replace_callback(
			'~(<textarea\b[^>]*class=(["\'])[^"\']*code-block-pro-copy-button-textarea[^"\']*\\2[^>]*>).*?(</textarea\s*>)~isu',
			static fn ( array $matches ): string => (string) $matches[1] . $escaped_text . (string) $matches[3],
			$block,
			1
		);
		if ( ! is_string( $next ) ) {
			return new WP_Error(
				'swq_full_article_plaintext_textarea_rebuild_failed',
				'Could not rebuild the Code Block Pro copy textarea.'
			);
		}
		$block = $next;

		$next = preg_replace_callback(
			'~<pre\b[^>]*class=(["\'])[^"\']*\bshiki\b[^"\']*\\1[^>]*>.*?</pre\s*>~isu',
			static fn (): string => $code_html,
			$block,
			1
		);
		if ( ! is_string( $next ) ) {
			return new WP_Error(
				'swq_full_article_plaintext_code_html_rebuild_failed',
				'Could not rebuild the rendered Code Block Pro Plaintext HTML.'
			);
		}

		return $next;
	}

	private static function rebuild_code_block_pro_code_html(
		string $existing_code_html,
		string $translated_text
	): string {
		$opening = '<pre class="shiki light-plus" style="background-color: #FFFFFF" tabindex="0"><code>';
		$closing = '</code></pre>';

		if (
			preg_match(
				'~^(<pre\b[^>]*>\s*<code\b[^>]*>).*?(</code>\s*</pre>)$~isu',
				$existing_code_html,
				$matches
			)
		) {
			$opening = (string) $matches[1];
			$closing = (string) $matches[2];
		}

		$style = 'color: #000000';
		if (
			preg_match(
				'~<span\s+class=(["\'])line\\1>\s*<span\s+style=(["\'])(.*?)\\2>~isu',
				$existing_code_html,
				$style_match
			)
		) {
			$style = (string) $style_match[3];
		}

		$normalized = str_replace( [ "\r\n", "\r" ], "\n", $translated_text );
		$lines      = explode( "\n", $normalized );
		$line_html  = [];

		foreach ( $lines as $line ) {
			$line_html[] = '<span class="line"><span style="'
				. self::escape_html_attribute( $style )
				. '">'
				. self::escape_html_text( $line )
				. '</span></span>';
		}

		return $opening . implode( "\n", $line_html ) . $closing;
	}

	private static function rebuild_pre_element( string $html, string $translated_text ) {
		$escaped = self::escape_html_text( $translated_text );
		$next = preg_replace_callback(
			'~(<pre\b[^>]*>).*?(</pre\s*>)~isu',
			static fn ( array $matches ): string => (string) $matches[1] . $escaped . (string) $matches[2],
			$html,
			1
		);

		if ( ! is_string( $next ) ) {
			return new WP_Error(
				'swq_full_article_pre_element_rebuild_failed',
				'Could not rebuild a translated preformatted element.'
			);
		}

		return $next;
	}

	private static function rebuild_image_alt( string $tag, string $translated_alt ) {
		$escaped_alt = self::escape_html_attribute( $translated_alt );
		$replaced    = false;

		$next = preg_replace_callback(
			'~(\balt\s*=\s*)(["\'])(.*?)\\2~isu',
			static function ( array $matches ) use ( $escaped_alt, &$replaced ): string {
				$replaced = true;
				return (string) $matches[1]
					. (string) $matches[2]
					. $escaped_alt
					. (string) $matches[2];
			},
			$tag,
			1
		);

		if ( ! is_string( $next ) || ! $replaced ) {
			return new WP_Error(
				'swq_full_article_alt_rebuild_failed',
				'Could not safely write the translated image alt attribute.'
			);
		}

		return $next;
	}

	private static function plaintext_line_count( string $text ): int {
		$normalized = str_replace( [ "\r\n", "\r" ], "\n", $text );
		return max( 1, count( explode( "\n", $normalized ) ) );
	}

	private static function escape_html_text( string $text ): string {
		return htmlspecialchars(
			$text,
			ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
			'UTF-8'
		);
	}

	private static function escape_html_attribute( string $text ): string {
		return self::escape_html_text( $text );
	}

	private static function validate_translation( string $source, string $translated, string $target_language ) {
		if ( ! class_exists( 'SlyTranslate\\TranslationValidator' ) ) {
			return new WP_Error(
				'swq_full_article_validator_unavailable',
				'SlyTranslate TranslationValidator is unavailable.'
			);
		}

		$validation = SlyTranslate\TranslationValidator::validate( $source, $translated, $target_language );
		if ( ! is_wp_error( $validation ) ) {
			return true;
		}

		$error_code = (string) $validation->get_error_code();

		if ( str_contains( $error_code, 'runaway' ) ) {
			$source_length     = max( 1, self::char_length( $source ) );
			$translated_length = self::char_length( $translated );

			if ( $translated_length <= $source_length * self::FULL_ARTICLE_RUNAWAY_OUTPUT_RATIO ) {
				return true;
			}

			$summary_file  = '/tmp/swq-glm52-runaway-summary.json';
			$output_file   = '/tmp/swq-glm52-runaway-output.txt';
			$ratio         = round( $translated_length / $source_length, 4 );
			$source_blocks = self::block_signature( $source );
			$output_blocks = self::block_signature( $translated );
			$han_count     = preg_match_all( '/\p{Han}/u', $translated, $han_matches );

			$summary = [
				'post_id'                    => (int) ( self::$state['post_id'] ?? 0 ),
				'target_language'            => $target_language,
				'error_code'                 => $error_code,
				'source_chars'               => $source_length,
				'output_chars'               => $translated_length,
				'output_to_source_ratio'     => $ratio,
				'allowed_ratio'              => self::FULL_ARTICLE_RUNAWAY_OUTPUT_RATIO,
				'max_tokens'                 => (int) ( self::$state['max_tokens'] ?? 0 ),
				'protected_tokens'            => (int) ( self::$state['protected_count'] ?? 0 ),
				'source_block_entries'       => count( $source_blocks ),
				'output_block_entries'       => count( $output_blocks ),
				'block_signature_identical'  => $source_blocks === $output_blocks,
				'output_han_character_count' => false === $han_count ? null : $han_count,
				'output_starts_with'         => self::char_slice( $translated, 0, 800 ),
				'output_ends_with'           => self::char_slice( $translated, -800, 800 ),
				'full_output_file'           => $output_file,
			];

			self::write_validation_diagnostic_files( $summary_file, $output_file, $summary, $translated );

			return new WP_Error(
				'swq_full_article_' . $error_code,
				sprintf(
					'%s Diagnostic: %s',
					$validation->get_error_message(),
					$summary_file
				),
				$validation->get_error_data()
			);
		}

		/*
		 * SlyTranslate's generic assistant-reply detector intentionally uses
		 * broad heuristics. In a full technical article, however, ordinary
		 * prose may legitimately contain words such as "critical", while
		 * translated Plaintext blocks may legitimately contain labels such as
		 * "English:", "Source:" or "Target:". Those cases must not invalidate
		 * an otherwise structurally sound full-article translation.
		 *
		 * Keep blocking unmistakable prompt leakage and assistant preambles,
		 * but bypass only the broad false positive.
		 */
		if ( 'invalid_translation_assistant_reply' === $error_code ) {
			$strong_markers = self::strong_prompt_leakage_markers( $source, $translated );

			$summary_file = '/tmp/swq-glm52-assistant-validation-summary.json';
			$output_file  = '/tmp/swq-glm52-assistant-validation-output.txt';
			$summary = [
				'post_id'                 => (int) ( self::$state['post_id'] ?? 0 ),
				'target_language'         => $target_language,
				'original_error_code'     => $error_code,
				'original_error_message'  => $validation->get_error_message(),
				'strong_leakage_detected' => ! empty( $strong_markers ),
				'strong_markers'          => $strong_markers,
				'broad_false_positive_signals' => self::broad_assistant_false_positive_signals(
					$source,
					$translated
				),
				'source_chars'            => self::char_length( $source ),
				'output_chars'            => self::char_length( $translated ),
				'output_starts_with'      => self::char_slice( $translated, 0, 800 ),
				'output_ends_with'        => self::char_slice( $translated, -800, 800 ),
				'full_output_file'        => $output_file,
			];

			self::write_validation_diagnostic_files( $summary_file, $output_file, $summary, $translated );

			if ( empty( $strong_markers ) ) {
				self::trace_event(
					'assistant_validation_false_positive_bypassed',
					[
						'diagnostic' => $summary_file,
						'signals'    => $summary['broad_false_positive_signals'],
					]
				);
				return true;
			}

			return new WP_Error(
				'swq_full_article_' . $error_code,
				sprintf(
					'%s Strong prompt or assistant leakage was detected. Diagnostic: %s',
					$validation->get_error_message(),
					$summary_file
				),
				$validation->get_error_data()
			);
		}

		$summary_file = '/tmp/swq-glm52-validation-summary.json';
		$output_file  = '/tmp/swq-glm52-validation-output.txt';
		$summary = [
			'post_id'                => (int) ( self::$state['post_id'] ?? 0 ),
			'target_language'        => $target_language,
			'error_code'             => $error_code,
			'error_message'          => $validation->get_error_message(),
			'source_chars'           => self::char_length( $source ),
			'output_chars'           => self::char_length( $translated ),
			'output_starts_with'     => self::char_slice( $translated, 0, 800 ),
			'output_ends_with'       => self::char_slice( $translated, -800, 800 ),
			'full_output_file'       => $output_file,
		];

		self::write_validation_diagnostic_files( $summary_file, $output_file, $summary, $translated );

		return new WP_Error(
			'swq_full_article_' . $error_code,
			sprintf(
				'%s Diagnostic: %s',
				$validation->get_error_message(),
				$summary_file
			),
			$validation->get_error_data()
		);
	}

	private static function strong_prompt_leakage_markers( string $source, string $translated ): array {
		$source_lower     = strtolower( $source );
		$translated_lower = strtolower( $translated );
		$markers          = [];

		$exact_markers = [
			'full-article translation mode:',
			'target-language writing policy',
			'optional article-specific instructions:',
			'a token beginning with swqblock',
			'a token beginning with swqinline',
			'never translate, rename, remove, duplicate, split, wrap or renumber any protected token',
			'return only the complete translated article body',
			'critical output format',
			'mandatory translation rules',
			'critical: apply every translation rule above exactly',
			'critical: keep obeying the user-provided translation rules above',
			'additional style instructions (do not translate these lines',
			'do not rewrite unicode symbols or math notation',
			'return only the translated text and preserve html',
			'<slytranslate',
		];

		foreach ( $exact_markers as $marker ) {
			if ( false === strpos( $source_lower, $marker ) && false !== strpos( $translated_lower, $marker ) ) {
				$markers[] = $marker;
			}
		}

		$translated_plain = self::validation_plain_text( $translated );
		$source_plain     = self::validation_plain_text( $source );

		$opening_patterns = [
			'assistant_preamble' => '/^(?:sure|certainly|of course)[,!.:\s]+(?:here(?:\'s| is| are)|i(?:\'ll| will)|below)\b/iu',
			'translation_preamble' => '/^(?:here(?:\'s| is| are)|below is)\s+(?:the\s+)?(?:complete\s+)?(?:english\s+)?translation\b/iu',
			'prompt_echo_opener' => '/^(?:we\s+(?:need|have)\s+to\s+translate\b|translate\s+(?:the\s+)?(?:content|text|following|input)\s+from\b)/iu',
			'translation_heading' => '/^(?:#+\s*)?(?:translation|english translation|translated article)\s*:?(?:\R|$)/iu',
		];

		foreach ( $opening_patterns as $name => $pattern ) {
			if ( 1 !== preg_match( $pattern, $source_plain ) && 1 === preg_match( $pattern, $translated_plain ) ) {
				$markers[] = $name;
			}
		}

		return array_values( array_unique( $markers ) );
	}

	private static function broad_assistant_false_positive_signals( string $source, string $translated ): array {
		$source_lower     = strtolower( $source );
		$translated_lower = strtolower( $translated );
		$signals          = [];

		if ( false === strpos( $source_lower, 'critical' ) && false !== strpos( $translated_lower, 'critical' ) ) {
			$signals[] = 'ordinary_critical_word';
		}

		if ( false === strpos( $source_lower, 'mandatory' ) && false !== strpos( $translated_lower, 'mandatory' ) ) {
			$signals[] = 'ordinary_mandatory_word';
		}

		$source_plain     = self::validation_plain_text( $source );
		$translated_plain = self::validation_plain_text( $translated );
		$label_pattern    = '/(?:^|\b)(?:source|target|en|de|english|german|deutsch)\s*:/iu';

		if ( 1 !== preg_match( $label_pattern, $source_plain ) && 1 === preg_match( $label_pattern, $translated_plain ) ) {
			$signals[] = 'legitimate_bilingual_or_plaintext_label';
		}

		return $signals;
	}

	private static function validation_plain_text( string $text ): string {
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text );
		if ( ! is_string( $text ) ) {
			$text = '';
		}

		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return is_string( $text ) ? trim( $text ) : '';
	}

	private static function write_validation_diagnostic_files(
		string $summary_file,
		string $output_file,
		array $summary,
		string $translated
	): void {
		$summary_json = wp_json_encode(
			$summary,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);

		if ( is_string( $summary_json ) ) {
			@file_put_contents( $summary_file, $summary_json . "\n", LOCK_EX );
			@chmod( $summary_file, 0600 );
		}

		@file_put_contents( $output_file, $translated, LOCK_EX );
		@chmod( $output_file, 0600 );
	}

	private static function normalize_unique_protected_tokens( string $translated, array $expected_tokens ): string {
		preg_match_all( self::PROTECTED_TOKEN_REGEX, $translated, $strict_matches );
		$actual_tokens = isset( $strict_matches[0] ) && is_array( $strict_matches[0] ) ? $strict_matches[0] : [];
		$missing_tokens = array_values( array_diff( $expected_tokens, $actual_tokens ) );

		preg_match_all( self::PROTECTED_TOKEN_LIKE_REGEX, $translated, $token_like_matches );
		$token_like_tokens = isset( $token_like_matches[0] ) && is_array( $token_like_matches[0] )
			? $token_like_matches[0]
			: [];
		$token_like_counts = array_count_values( $token_like_tokens );
		$replacements      = [];
		$claimed_expected  = [];

		foreach ( $token_like_tokens as $token_like ) {
			if ( 1 === preg_match( '/^' . trim( self::PROTECTED_TOKEN_REGEX, '/' ) . '$/', $token_like ) ) {
				continue;
			}

			if ( 1 !== ( $token_like_counts[ $token_like ] ?? 0 )
				|| 1 !== preg_match(
					'/^SWQ(BLOCK|INLINE|HTML|STRUCT|PLAINSTART|PLAINSTOP|ALTSTART|ALTSTOP)(\d+)END$/',
					$token_like,
					$token_like_parts
				)
			) {
				return $translated;
			}

			$token_like_number = ltrim( (string) $token_like_parts[2], '0' );
			$token_like_number = '' === $token_like_number ? '0' : $token_like_number;
			$candidates        = [];

			foreach ( $missing_tokens as $missing_token ) {
				if ( 1 !== preg_match(
					'/^SWQ(BLOCK|INLINE|HTML|STRUCT|PLAINSTART|PLAINSTOP|ALTSTART|ALTSTOP)(\d{6})END$/',
					$missing_token,
					$expected_parts
				) ) {
					continue;
				}

				$expected_number = ltrim( (string) $expected_parts[2], '0' );
				$expected_number = '' === $expected_number ? '0' : $expected_number;

				if ( $token_like_parts[1] === $expected_parts[1] && $token_like_number === $expected_number ) {
					$candidates[] = $missing_token;
				}
			}

			if ( 1 !== count( $candidates ) || isset( $claimed_expected[ $candidates[0] ] ) ) {
				return $translated;
			}

			$replacements[ $token_like ]       = $candidates[0];
			$claimed_expected[ $candidates[0] ] = true;
		}

		if ( [] === $replacements ) {
			return $translated;
		}

		$normalized = strtr( $translated, $replacements );
		return self::protected_token_sequence_is_strictly_valid( $normalized, $expected_tokens )
			? $normalized
			: $translated;
	}

	private static function protected_token_sequence_is_strictly_valid( string $translated, array $expected_tokens ): bool {
		preg_match_all( self::PROTECTED_TOKEN_REGEX, $translated, $matches );
		$actual_tokens = isset( $matches[0] ) && is_array( $matches[0] ) ? $matches[0] : [];

		if ( count( $expected_tokens ) !== count( $actual_tokens )
			|| [] !== array_diff( $expected_tokens, $actual_tokens )
			|| [] !== array_diff( $actual_tokens, $expected_tokens )
			|| count( $actual_tokens ) !== count( array_unique( $actual_tokens ) )
		) {
			return false;
		}

		$expected_fixed_tokens = array_values(
			array_filter(
				$expected_tokens,
				[ self::class, 'is_fixed_order_protected_token' ]
			)
		);
		$actual_fixed_tokens = array_values(
			array_filter(
				$actual_tokens,
				[ self::class, 'is_fixed_order_protected_token' ]
			)
		);

		return $expected_fixed_tokens === $actual_fixed_tokens;
	}

	private static function recover_uniquely_located_missing_token(
		string $translated,
		string $source_payload,
		array $expected_tokens
	): string {
		preg_match_all( self::PROTECTED_TOKEN_REGEX, $translated, $matches );
		$actual_tokens  = isset( $matches[0] ) && is_array( $matches[0] ) ? $matches[0] : [];
		$missing_tokens = array_values( array_diff( $expected_tokens, $actual_tokens ) );

		if (
			1 !== count( $missing_tokens )
			|| [] !== array_diff( $actual_tokens, $expected_tokens )
			|| count( $actual_tokens ) !== count( array_unique( $actual_tokens ) )
		) {
			return $translated;
		}

		$missing_index = array_search( $missing_tokens[0], $expected_tokens, true );
		if ( false === $missing_index ) {
			return $translated;
		}

		$remaining_expected = $expected_tokens;
		array_splice( $remaining_expected, $missing_index, 1 );
		if ( $remaining_expected !== $actual_tokens ) {
			return $translated;
		}

		$missing  = $missing_tokens[0];
		$previous = $expected_tokens[ $missing_index - 1 ] ?? null;
		$next     = $expected_tokens[ $missing_index + 1 ] ?? null;
		$pattern  = '';
		$replace  = '';

		if ( is_string( $previous ) && is_string( $next ) ) {
			$source_pattern = '~' . preg_quote( $previous, '~' ) . '\\s*'
				. preg_quote( $missing, '~' ) . '\\s*' . preg_quote( $next, '~' ) . '~u';
			if ( 1 !== preg_match_all( $source_pattern, $source_payload ) ) {
				return $translated;
			}
			$pattern = '~(' . preg_quote( $previous, '~' ) . ')(\\s*)(' . preg_quote( $next, '~' ) . ')~u';
			$replace = '$1$2' . $missing . '$2$3';
		} elseif ( null === $previous && is_string( $next ) ) {
			if ( 1 !== preg_match( '~^\\s*' . preg_quote( $missing, '~' ) . '\\s*' . preg_quote( $next, '~' ) . '~u', $source_payload ) ) {
				return $translated;
			}
			$pattern = '~^(\\s*)(' . preg_quote( $next, '~' ) . ')~u';
			$replace = '$1' . $missing . '$1$2';
		} elseif ( is_string( $previous ) && null === $next ) {
			if ( 1 !== preg_match( '~' . preg_quote( $previous, '~' ) . '\\s*' . preg_quote( $missing, '~' ) . '\\s*$~u', $source_payload ) ) {
				return $translated;
			}
			$pattern = '~(' . preg_quote( $previous, '~' ) . ')(\\s*)$~u';
			$replace = '$1$2' . $missing . '$2';
		} else {
			return $translated;
		}

		if ( 1 !== preg_match_all( $pattern, $translated ) ) {
			return $translated;
		}

		$recovered = preg_replace( $pattern, $replace, $translated, 1 );
		return is_string( $recovered )
			&& self::protected_token_sequence_is_strictly_valid( $recovered, $expected_tokens )
			? $recovered
			: $translated;
	}

	private static function repair_uniquely_misnumbered_adjacent_token(
		string $translated,
		array $expected_tokens
	): string {
		preg_match_all( self::PROTECTED_TOKEN_REGEX, $translated, $matches, PREG_OFFSET_CAPTURE );
		$actual_matches = isset( $matches[0] ) && is_array( $matches[0] ) ? $matches[0] : [];
		$actual_tokens  = array_column( $actual_matches, 0 );
		$expected_count = count( $expected_tokens );

		if ( 0 === $expected_count
			|| $expected_count !== count( $actual_tokens )
			|| $expected_count !== count( array_unique( $expected_tokens ) )
		) {
			return $translated;
		}

		$missing_tokens = array_values( array_diff( $expected_tokens, $actual_tokens ) );
		if ( 1 !== count( $missing_tokens ) || [] !== array_diff( $actual_tokens, $expected_tokens ) ) {
			return $translated;
		}

		$duplicate_tokens = array_filter(
			array_count_values( $actual_tokens ),
			static fn ( int $count ): bool => $count > 1
		);
		if ( 1 !== count( $duplicate_tokens ) || 2 !== reset( $duplicate_tokens ) ) {
			return $translated;
		}

		$mismatch_indexes = [];
		foreach ( $expected_tokens as $index => $expected_token ) {
			if ( $expected_token !== $actual_tokens[ $index ] ) {
				$mismatch_indexes[] = $index;
			}
		}

		if ( 1 !== count( $mismatch_indexes ) ) {
			return $translated;
		}

		$mismatch_index = $mismatch_indexes[0];
		if ( 0 === $mismatch_index || $mismatch_index === $expected_count - 1 ) {
			return $translated;
		}

		$missing_token   = $missing_tokens[0];
		$duplicate_token = (string) array_key_first( $duplicate_tokens );
		if ( $expected_tokens[ $mismatch_index ] !== $missing_token
			|| $actual_tokens[ $mismatch_index ] !== $duplicate_token
			|| $expected_tokens[ $mismatch_index - 1 ] !== $actual_tokens[ $mismatch_index - 1 ]
			|| $expected_tokens[ $mismatch_index + 1 ] !== $actual_tokens[ $mismatch_index + 1 ]
			|| ! self::is_fixed_order_protected_token( $missing_token )
			|| ! self::is_fixed_order_protected_token( $duplicate_token )
		) {
			return $translated;
		}

		$token_pattern = '/^SWQ([A-Z]+)(\d{6})END$/';
		if ( 1 !== preg_match( $token_pattern, $missing_token, $missing_parts )
			|| 1 !== preg_match( $token_pattern, $duplicate_token, $duplicate_parts )
			|| $missing_parts[1] !== $duplicate_parts[1]
			|| 1 !== abs( (int) $missing_parts[2] - (int) $duplicate_parts[2] )
		) {
			return $translated;
		}

		$actual_match = $actual_matches[ $mismatch_index ] ?? null;
		if ( ! is_array( $actual_match )
			|| $duplicate_token !== ( $actual_match[0] ?? null )
			|| ! is_int( $actual_match[1] ?? null )
		) {
			return $translated;
		}

		$repaired = substr_replace(
			$translated,
			$missing_token,
			$actual_match[1],
			strlen( $duplicate_token )
		);

		return self::protected_token_sequence_is_strictly_valid( $repaired, $expected_tokens )
			? $repaired
			: $translated;
	}

	private static function is_fixed_order_protected_token( string $token ): bool {
		return ! str_starts_with( $token, 'SWQINLINE' );
	}

	private static function remove_translation_tail_guard( string $translated ): array {
		$count   = substr_count( $translated, self::TRANSLATION_TAIL_GUARD );
		$trimmed = trim( $translated );
		$status  = 'missing';

		if ( 1 === $count ) {
			$status = str_ends_with( $trimmed, self::TRANSLATION_TAIL_GUARD )
				&& ! str_contains( $trimmed, '<slytranslate-output>' )
				&& ! str_contains( $trimmed, '</slytranslate-output>' )
				? 'present'
				: 'misplaced';
		} elseif ( $count > 1 ) {
			$status = 'duplicate';
		}

		return [
			'translation_tail_guard_expected' => true,
			'translation_tail_guard_status'   => $status,
			'translation_tail_guard_count'    => $count,
			'translated'                      => trim( str_replace( self::TRANSLATION_TAIL_GUARD, '', $translated ) ),
		];
	}

	private static function log_translation_tail_guard( array $diagnostic ): void {
		$log_data = [
			'translation_tail_guard_expected' => (bool) $diagnostic['translation_tail_guard_expected'],
			'translation_tail_guard_status'   => (string) $diagnostic['translation_tail_guard_status'],
			'translation_tail_guard_count'    => (int) $diagnostic['translation_tail_guard_count'],
		];

		self::trace_event( 'translation_tail_guard', $log_data );
		error_log(
			sprintf(
				'[SWQ GLM-5.2 Full Article] translation_tail_guard_expected=%s translation_tail_guard_status=%s translation_tail_guard_count=%d',
				$log_data['translation_tail_guard_expected'] ? 'true' : 'false',
				$log_data['translation_tail_guard_status'],
				$log_data['translation_tail_guard_count']
			)
		);
	}

	private static function repair_missing_struct_token_tail(
		string $translated,
		array $expected_tokens,
		array $protected_map
	): array {
		preg_match_all( self::PROTECTED_TOKEN_REGEX, $translated, $matches );
		$actual_tokens = isset( $matches[0] ) && is_array( $matches[0] ) ? $matches[0] : [];

		$missing_tokens   = array_values( array_diff( $expected_tokens, $actual_tokens ) );
		$extra_tokens     = array_values( array_diff( $actual_tokens, $expected_tokens ) );
		$duplicate_tokens = array_filter(
			array_count_values( $actual_tokens ),
			static fn ( int $count ): bool => $count > 1
		);
		$result = [
			'attempted'  => false,
			'translated' => $translated,
			'tokens'     => $missing_tokens,
			'reason'     => '',
		];

		if ( [] === $missing_tokens ) {
			$result['reason'] = 'no_missing_tokens';
			return $result;
		}
		if ( [] !== $extra_tokens ) {
			$result['reason'] = 'extra_tokens_present';
			return $result;
		}
		if ( [] !== $duplicate_tokens ) {
			$result['reason'] = 'duplicate_tokens_present';
			return $result;
		}
		foreach ( $missing_tokens as $missing_token ) {
			if ( 1 !== preg_match( '/^SWQSTRUCT\d{6}END$/', $missing_token ) ) {
				$result['reason'] = 'missing_non_struct_token';
				return $result;
			}
		}

		$tail_count = count( $missing_tokens );
		if ( $missing_tokens !== array_slice( $expected_tokens, -$tail_count ) ) {
			$result['reason'] = 'missing_tokens_not_expected_tail_suffix';
			return $result;
		}

		$expected_fixed_tokens = array_values(
			array_filter(
				$expected_tokens,
				[ self::class, 'is_fixed_order_protected_token' ]
			)
		);
		$actual_fixed_tokens = array_values(
			array_filter(
				$actual_tokens,
				[ self::class, 'is_fixed_order_protected_token' ]
			)
		);

		if ( $actual_fixed_tokens !== array_slice( $expected_fixed_tokens, 0, count( $actual_fixed_tokens ) ) ) {
			$result['reason'] = 'actual_fixed_sequence_not_expected_prefix';
			return $result;
		}
		foreach ( $missing_tokens as $missing_token ) {
			if ( ! array_key_exists( $missing_token, $protected_map )
				|| ! is_string( $protected_map[ $missing_token ] )
			) {
				$result['reason'] = 'protected_content_missing';
				return $result;
			}
		}

		$separator            = '' === $translated || 1 === preg_match( '/\s$/u', $translated ) ? '' : "\n";
		$result['attempted']  = true;
		$result['translated'] = $translated . $separator . implode( "\n", $missing_tokens );
		$result['reason']     = 'eligible_tail_suffix';
		return $result;
	}

	private static function log_protected_token_tail_repair( array $repair, string $revalidation ): void {
		$diagnostic = [
			'protected_token_tail_repair_attempted'    => (bool) $repair['attempted'],
			'protected_token_tail_repair_count'        => $repair['attempted'] ? count( $repair['tokens'] ) : 0,
			'protected_token_tail_repair_tokens'       => $repair['tokens'],
			'protected_token_tail_repair_revalidation' => $revalidation,
			'protected_token_tail_repair_reason'       => (string) $repair['reason'],
		];

		self::trace_event( 'protected_token_tail_repair', $diagnostic );
		error_log(
			'[SWQ GLM-5.2 Full Article] '
			. implode(
				' ',
				array_map(
					static fn ( string $key, $value ): string => $key . '=' . (
						is_array( $value )
							? (string) wp_json_encode( $value )
							: ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value )
					),
					array_keys( $diagnostic ),
					array_values( $diagnostic )
				)
			)
		);
	}

	private static function validate_protected_tokens(
		string $translated,
		array $expected_tokens,
		string $source_payload,
		array $protected_map,
		string $raw_model_response
	) {
		preg_match_all( self::PROTECTED_TOKEN_REGEX, $translated, $matches );
		$actual_tokens = isset( $matches[0] ) && is_array( $matches[0] ) ? $matches[0] : [];

		$expected_count   = count( $expected_tokens );
		$actual_count     = count( $actual_tokens );
		$missing_tokens   = array_values( array_diff( $expected_tokens, $actual_tokens ) );
		$extra_tokens     = array_values( array_diff( $actual_tokens, $expected_tokens ) );
		$duplicate_tokens = [];
		$actual_counts    = array_count_values( $actual_tokens );

		foreach ( $actual_counts as $token => $count ) {
			if ( $count > 1 ) {
				$duplicate_tokens[ $token ] = $count;
			}
		}

		$expected_fixed_tokens = array_values(
			array_filter(
				$expected_tokens,
				[ self::class, 'is_fixed_order_protected_token' ]
			)
		);

		$actual_fixed_tokens = array_values(
			array_filter(
				$actual_tokens,
				[ self::class, 'is_fixed_order_protected_token' ]
			)
		);

		$all_tokens_valid = $expected_count === $actual_count
			&& [] === $missing_tokens
			&& [] === $extra_tokens
			&& [] === $duplicate_tokens;

		$fixed_order_valid = $expected_fixed_tokens === $actual_fixed_tokens;

		if ( $all_tokens_valid && $fixed_order_valid ) {
			return true;
		}

		$first_fixed_mismatch = 0;
		$max_fixed_count = max( count( $expected_fixed_tokens ), count( $actual_fixed_tokens ) );

		for ( $index = 0; $index < $max_fixed_count; $index++ ) {
			$expected = $expected_fixed_tokens[ $index ] ?? null;
			$actual   = $actual_fixed_tokens[ $index ] ?? null;

			if ( $expected !== $actual ) {
				$first_fixed_mismatch = $index + 1;
				break;
			}
		}

		$first_mismatch_index = null;
		$max_token_count = max( $expected_count, $actual_count );
		for ( $index = 0; $index < $max_token_count; $index++ ) {
			if ( ( $expected_tokens[ $index ] ?? null ) !== ( $actual_tokens[ $index ] ?? null ) ) {
				$first_mismatch_index = $index;
				break;
			}
		}

		$primary_missing_index = [] !== $missing_tokens
			? array_search( $missing_tokens[0], $expected_tokens, true )
			: false;
		$context_index = false !== $primary_missing_index ? $primary_missing_index : $first_mismatch_index;
		$previous_expected_token = is_int( $context_index ) && $context_index > 0
			? ( $expected_tokens[ $context_index - 1 ] ?? null )
			: null;
		$next_expected_token = is_int( $context_index )
			? ( $expected_tokens[ $context_index + 1 ] ?? null )
			: null;
		$source_context = self::token_context(
			$source_payload,
			$missing_tokens[0] ?? ( $expected_tokens[ $context_index ] ?? '' )
		);
		$response_context = self::token_gap_context(
			$translated,
			$previous_expected_token,
			$next_expected_token
		);

		$summary = [
			'post_id'                         => (int) ( self::$state['post_id'] ?? 0 ),
			'expected_count'                   => $expected_count,
			'actual_count'                     => $actual_count,
			'expected_fixed_count'             => count( $expected_fixed_tokens ),
			'actual_fixed_count'               => count( $actual_fixed_tokens ),
			'fixed_order_valid'                => $fixed_order_valid,
			'fixed_order'                      => $fixed_order_valid,
			'first_mismatch_index'             => $first_mismatch_index,
			'previous_expected_token'          => $previous_expected_token,
			'next_expected_token'              => $next_expected_token,
			'first_fixed_mismatch_position'    => $first_fixed_mismatch,
			'expected_fixed_at_mismatch'        => $first_fixed_mismatch > 0 ? ( $expected_fixed_tokens[ $first_fixed_mismatch - 1 ] ?? null ) : null,
			'actual_fixed_at_mismatch'          => $first_fixed_mismatch > 0 ? ( $actual_fixed_tokens[ $first_fixed_mismatch - 1 ] ?? null ) : null,
			'missing_tokens'                   => array_slice( $missing_tokens, 0, 20 ),
			'extra_tokens'                     => array_slice( $extra_tokens, 0, 20 ),
			'duplicate_tokens'                 => $duplicate_tokens,
			'missing_token_details'            => array_map(
				static fn ( string $token ): array => [
					'token'             => $token,
					'type'              => preg_replace( '/^SWQ([A-Z]+)\\d{6}END$/', '$1', $token ),
					'protected_content' => $protected_map[ $token ] ?? null,
				],
				array_slice( $missing_tokens, 0, 20 )
			),
			'source_context'                   => $source_context,
			'response_context'                 => $response_context,
		];

		$summary_file = '/tmp/swq-glm52-token-validation-summary.json';
		$output_file  = '/tmp/swq-glm52-token-validation-output.txt';
		$summary['raw_response_file'] = $output_file;
		$summary['diagnostic_file']   = $summary_file;
		$summary_json = wp_json_encode( $summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

		if ( is_string( $summary_json ) ) {
			@file_put_contents( $summary_file, $summary_json . "\n", LOCK_EX );
			@chmod( $summary_file, 0600 );
		}

		@file_put_contents( $output_file, $raw_model_response, LOCK_EX );
		@chmod( $output_file, 0600 );

		return new WP_Error(
			'swq_full_article_token_validation_failed',
			sprintf(
				'Protected token validation failed: expected_count=%d, actual_count=%d, missing_tokens=%s, extra_tokens=%s, fixed_order=%s, first_mismatch_index=%s, previous_expected_token=%s, next_expected_token=%s, raw_response_file=%s, diagnostic_file=%s',
				$expected_count,
				$actual_count,
				wp_json_encode( array_slice( $missing_tokens, 0, 20 ) ),
				wp_json_encode( array_slice( $extra_tokens, 0, 20 ) ),
				$fixed_order_valid ? 'yes' : 'no',
				null === $first_mismatch_index ? 'null' : (string) $first_mismatch_index,
				$previous_expected_token ?? 'null',
				$next_expected_token ?? 'null',
				$output_file,
				$summary_file
			)
		);
	}

	private static function token_context( string $text, string $token, int $radius = 240 ): ?string {
		if ( '' === $token ) {
			return null;
		}
		$position = function_exists( 'mb_strpos' ) ? mb_strpos( $text, $token, 0, 'UTF-8' ) : strpos( $text, $token );
		if ( false === $position ) {
			return null;
		}
		return self::char_slice( $text, max( 0, (int) $position - $radius ), $radius * 2 + self::char_length( $token ) );
	}

	private static function token_gap_context(
		string $text,
		?string $previous_token,
		?string $next_token,
		int $radius = 240
	): ?string {
		$anchor = is_string( $previous_token ) ? $previous_token : ( $next_token ?? '' );
		return self::token_context( $text, $anchor, $radius );
	}

	private static function unwrap_model_output( string $output ): string {
		$output = trim( $output );
		$output = (string) preg_replace( '/^```(?:html|text)?\s*/i', '', $output );
		$output = (string) preg_replace( '/\s*```\s*$/', '', $output );

		if ( preg_match( '~^\s*<slytranslate-output>\s*(.*?)\s*</slytranslate-output>\s*$~is', $output, $matches ) ) {
			return trim( (string) $matches[1] );
		}

		if ( preg_match( '~^\s*<([A-Za-z][A-Za-z0-9:_-]*)>\s*(.*?)\s*</\1>\s*$~is', $output, $matches ) ) {
			return trim( (string) $matches[2] );
		}

		return trim( $output );
	}

	private static function block_signature( string $content ): array {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return [];
		}

		$blocks = parse_blocks( $content );
		$signature = [];
		$walk = static function ( array $items, int $depth ) use ( &$walk, &$signature ): void {
			foreach ( $items as $block ) {
				$name = isset( $block['blockName'] ) && is_string( $block['blockName'] )
					? trim( $block['blockName'] )
					: '';
				if ( '' !== $name ) {
					$signature[] = $depth . ':' . $name;
				}
				if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'], $depth + 1 );
				}
			}
		};

		if ( is_array( $blocks ) ) {
			$walk( $blocks, 0 );
		}

		return $signature;
	}

	private static function build_field_prompt(
		string $additional_prompt,
		string $field,
		string $target_language
	): string {
		$field_label = '' !== $field ? $field : 'text field';
		$prompt_parts = [
			self::build_target_language_field_rules( $target_language, $field ),
		];

		if ( '' !== trim( $additional_prompt ) ) {
			$prompt_parts[] = "OPTIONAL ARTICLE-SPECIFIC INSTRUCTIONS:\n" . trim( $additional_prompt );
		}

		$prompt_parts[] = sprintf(
			'The input is a single WordPress %s value. Translate only the source text and return only the translated value. Preserve URLs, domains, placeholders, variables, shortcodes, brand names, product names, plugin names, service names, commands, parameters, configuration keys, paths, filenames and identifiers exactly. Do not add explanations, labels, quotation marks, Markdown fences or content absent from the source.',
			$field_label
		);

		return implode( "\n\n", $prompt_parts );
	}

	private static function build_content_prompt( string $target_language ): string {
		$language_rules = self::build_target_language_content_rules( $target_language );

		return "全文翻译模式：\n\n"
			. "输入内容是一篇技术文章的完整正文。请将它作为一个连贯、可直接发布的完整文档翻译成目标语言。\n\n"
			. "请根据文章内容判断其实际技术领域，不要预设每篇文章都与 WordPress、PHP、Nginx、服务器或任何其他固定技术有关。\n\n"
			. $language_rules
			. "\n\n"
			. <<<'PROMPT'
上方提供的文章标题和摘要只用于理解上下文，不得将它们加入返回的正文。

不可翻译的代码、行内代码、固定 HTML 元素、Gutenberg 区块边界以及部分可翻译区域已替换为受保护占位符。

每个受保护占位符都必须逐字符原样复制，并且在输出中必须且只能出现一次。不得遗漏、重复、额外生成、复制、翻译、改名、拆开、包裹或重新编号任何占位符，也不得把一个段落中的占位符复制到另一个段落。占位符的类型、大小写和结尾必须保持不变；编号必须始终是固定 6 位数字，所有前导零都必须保留。

特别检查输出尾部：输入末尾的每个受保护占位符，即使其前后没有任何自然语言，也必须逐个原样输出。输出 HTML closing tag 不代表任务已经完成；HTML 之后仍可能存在代表 Gutenberg closing comment 的 SWQSTRUCT Token。只有确认输入中的最后一个受保护占位符已经按原顺序输出后，才可以结束正文并输出 </slytranslate-output>。不得因为文章语义、HTML 或可见正文看起来已经结束而省略尾部占位符。

SWQTRANSLATIONTAILGUARDEND 是内部完整性哨兵，不属于 WordPress 正文。它位于所有真实正文和受保护占位符之后，必须逐字符原样输出一次，并且必须保持在所有真实正文和受保护占位符之后、</slytranslate-output> 之前。不得翻译、改名、移动、重复或省略该哨兵。

例如，SWQSTRUCT000018END、SWQBLOCK000018END、SWQINLINE000018END 和 SWQHTML000018END 必须完整保留。SWQSTRUCT000018END 不得写成 SWQSTRUCT00018END、SWQSTRUCT000018end、SWQSTRUCT 000018 END 或 SWQSTRUCT000 018END。不得在占位符内部插入空格、换行、标点、HTML 标签或 Markdown 格式。

SWQBLOCK 开头的占位符代表一个完整、独立且不可翻译的代码块或代码元素。把每个 SWQBLOCK 当作不可移动的文档锚点，保持它们原有的相对顺序和文档位置。不得为了让目标语言更流畅而反转、交换或重新组合两个 SWQBLOCK；只能改写固定 Token 前、后或之间的自然语言，同时保持 Token 顺序不变。例如原文结构是“属性中：SWQBLOCK000001END……提交：SWQBLOCK000002END”时，必须保持 000001 在 000002 之前。

SWQINLINE 开头的占位符代表自然语言中的行内代码。每个 SWQINLINE 只能在其原句或原段落内部因英文语序需要而调整位置，不得移动到其他段落、列表项、标题、表格单元格或章节，也不得把前一段的 SWQINLINE 复制到后一段。

SWQHTML 开头的占位符代表固定 HTML 元素，必须逐字符原样保留一次，且不得移动。

SWQSTRUCT 开头的占位符代表原始 Gutenberg 起始或结束区块注释，是不可移动的区块边界。必须保持完整 SWQSTRUCT 序列的数量、顺序和位置，不得交换相邻结构 Token、删除区块边界，或把一个结构区间中的内容移动到另一个结构区间。

匹配的 SWQPLAINSTART 与 SWQPLAINSTOP 之间的文本来自 Plaintext 或预格式化区块。每一对 START/STOP 是不可移动的区块级边界，必须完整、唯一、按编号顺序保留；不得把边界外的内容移入其中，也不得把区域内容移到边界外。默认翻译其中每个人类语言片段，包括作者编写的标签、注释、摘要、说明和描述，即使它们很短、单独占一行、看起来像终端输出，或与 URL、域名和标识符混合。允许翻译的自然语言可以按自然英文需要合并或拆分物理行，但区域不得为空或整体遗漏。保留机器值，但翻译其周围的人类语言说明。例如，将“中文站：”和“英文站：”译为“Chinese site:”和“English site:”，将“选择 Browser”译为“Select Browser”，并在保留域名的同时翻译“www.example.com：补丁正常输出”中的说明部分。只有当上下文明示某段原文措辞本身就是实际系统证据，修改它会造成事实错误时，才保留源语言；这项狭窄例外可用于确切界面标签、原始错误消息、原始日志或字面观测值。例如，周围正文说明“中文（中国）”是中文站实际显示的语言切换器标签时，应保留该文字。仅仅位于 Plaintext 区块、看起来像输出或只是短标签，都不足以保留源语言；无法确定自然语言片段用途时，应翻译。若 Plaintext 区域只包含 URL、域名、路径、命令、标识符、状态码或其他机器可读值，则仅将其用于理解上下文，内部文本必须逐字符原样复制且不得移动，并保持行序、行数、空行和缩进。无论区域是否允许翻译，URL、域名、路径、命令、参数、机器标识符、状态码和其他机器值都不得更改，也不得添加 Markdown 围栏或引号。

匹配的 SWQALTSTART 与 SWQALTSTOP 之间是图片 alt 值，应自然、简洁地翻译，不得添加引号；外围图片标签会另行还原。

原始 Gutenberg 区块注释由 SWQSTRUCT 表示；其余输入仍包含 HTML 标签及属性、区块属性、URL、域名、短代码、模板变量和文档结构。所有这些内容以及命令、参数、配置键、路径、文件名、标识符、产品名和插件名都必须原样保留。

原文中的每个自然语言段落只能翻译一次。不得重复、遗漏、合并、拆分、替换或重新排列 Gutenberg 区块、HTML 元素、段落、标题、列表项、表格单元格或章节；保持其层级、相对顺序和文档位置。不得把前一个段落的译文复制到相邻段落。即使两个相邻段落内容相似，也必须分别翻译并保留各自结构；不得因前后文相似而跳过其中任何一个段落，也不得自行补充源内容中不存在的段落、标题、说明或总结。

在同一个标题、段落、列表项或表格单元格内部，可以根据自然英文表达需要调整句子结构、主语、修饰语和从句，但不得跨越原有结构边界移动内容。

只翻译自然语言文本。保持原意、事实、技术区别、操作步骤、时间顺序、风险判断、确定程度和结论；不得把链接误写成重定向、把缓存条目误写成数据库记录，或混淆原文中的其他技术区别。英文应自然、准确、克制且适合技术读者，不使用营销表达。

返回前，在内部检查是否存在重复或遗漏的段落、重复或遗漏的占位符、占位符格式变化以及固定结构 Token 的数量或顺序变化；发现问题时应先自行修正，但不得输出检查过程、Token 清单、校验报告或解释。

不得添加解释、引言、结语、评论、免责声明、营销语言、Markdown 代码围栏或源文不存在的内容。最终只返回完整的英文译文正文。
PROMPT;
	}

	private static function build_target_language_field_rules( string $target_language, string $field ): string {
		$base_language = self::target_language_base( $target_language );

		if ( 'en' === $base_language ) {
			if ( 'title' === $field ) {
				return <<<'PROMPT'
TARGET-LANGUAGE WRITING POLICY — ENGLISH TITLE:

Write a natural, concise, publication-ready English technical-blog title. Do not mirror source-language word order. Avoid long noun stacks and fragmentary “-ing” constructions when a clear finite verb is more natural.

Aim for roughly 70–115 English characters when the core meaning can be preserved. Prefer one central problem, action or result in the title. Move secondary implementation details, long enumerations and minor affected components to the summary instead of appending them all to the title. A somewhat longer title is acceptable only when shortening it would remove an essential technical distinction.

Make relationships already present in the source—such as timing, cause, problem and result—clear through natural English syntax, using devices such as after, when, for, with, a colon or an em dash only when supported by the source. Keep brand, product, plugin and service names exact. Preserve the original scope and meaning. Do not add claims, clickbait, conclusions or extra detail. Use natural English headline capitalization.
PROMPT;
			}

			if ( 'excerpt' === $field ) {
				return <<<'PROMPT'
TARGET-LANGUAGE WRITING POLICY — ENGLISH SUMMARY:

Write a concise, publication-ready English summary in clear sentences. Prefer two or three sentences when the source contains enough information, and usually keep the result within roughly 250–450 English characters. Treat this as a soft target rather than a reason to omit an essential technical distinction.

State the context or problem, the principal cause or causes, and the final solution or result. Include secondary details that were intentionally omitted from the title only when they materially help the reader understand the article. Do not force every implementation detail into the summary, and do not turn it into a checklist or exhaustive itemization. Avoid vague filler, administrative wording, repetitive formulas and unsupported conclusions. Preserve precise technical distinctions and do not introduce facts, judgments or outcomes absent from the source.
PROMPT;
			}

			return <<<'PROMPT'
TARGET-LANGUAGE WRITING POLICY — ENGLISH FIELD:

Use natural, idiomatic, publication-ready English for technical readers. Reorganize source-language word order when necessary, use clear subjects and finite verbs, keep pronoun references unambiguous, and use consistent technical terminology. Preserve the source tone and degree of certainty. Avoid noun stacking, literal translation, vague filler, repetitive boilerplate and marketing language.
PROMPT;
		}

		if ( 'title' === $field ) {
			return <<<'PROMPT'
TARGET-LANGUAGE WRITING POLICY — TITLE:

Write a natural, concise, publication-ready technical title in the requested target language. Follow the target language's normal headline syntax rather than source-language word order. Make existing relationships between timing, cause, problem and result clear without adding claims, conclusions, clickbait or extra detail. Preserve brand, product, plugin and service names exactly.
PROMPT;
		}

		if ( 'excerpt' === $field ) {
			return <<<'PROMPT'
TARGET-LANGUAGE WRITING POLICY — SUMMARY:

Write a concise, publication-ready summary in the requested target language. Clearly state the context or problem, principal causes, and solution or result while preserving technical distinctions. Avoid overloaded sentences, exhaustive detail, vague filler, marketing language and facts absent from the source.
PROMPT;
		}

		return <<<'PROMPT'
TARGET-LANGUAGE WRITING POLICY:

Use natural, idiomatic technical writing in the requested target language. Follow the target language's normal syntax rather than source-language word order. Preserve tone, meaning, technical distinctions and degree of certainty. Use consistent terminology and avoid literal translation, vague filler and marketing language.
PROMPT;
	}

	private static function build_target_language_content_rules( string $target_language ): string {
		if ( 'en' === self::target_language_base( $target_language ) ) {
			return <<<'PROMPT'
TARGET-LANGUAGE WRITING POLICY — ENGLISH ARTICLE:

Write natural, accurate, publication-ready English for international technical readers. Preserve the author's first-person perspective, troubleshooting chronology, practical record style and degree of certainty. Do not rewrite a personal engineering account as official documentation, generic tutorial copy or marketing content.

Within each unchanged structural unit, reorganize sentence structure when necessary for natural English. Use direct verbs, clear subjects, unambiguous pronoun references and consistent technical terminology. Make relationships between time, cause, condition, action and result explicit when they are present in the source.

Avoid source-language word order, word-for-word translation, long noun stacks, awkward participial fragments, overloaded sentences, vague filler and repetitive formulas such as “This indicates that,” “It can be seen that,” or “After completing the above operations.” Do not over-polish or add interpretation.
PROMPT;
		}

		return <<<'PROMPT'
TARGET-LANGUAGE WRITING POLICY:

Write natural, accurate, publication-ready technical prose in the requested target language. Preserve the author's perspective, chronology, practical writing style and degree of certainty. Within each unchanged structural unit, reorganize sentence structure when necessary to follow the target language's normal syntax. Use clear references and consistent technical terminology. Avoid literal translation, source-language word order, vague filler, repetitive boilerplate, over-polishing and marketing language.
PROMPT;
	}

	private static function target_language_base( string $target_language ): string {
		$normalized = strtolower( trim( str_replace( '_', '-', $target_language ) ) );
		if ( '' === $normalized ) {
			return '';
		}

		if ( 'english' === $normalized ) {
			return 'en';
		}

		$parts = explode( '-', $normalized, 2 );
		return (string) ( $parts[0] ?? $normalized );
	}

	private static function get_rest_input( $request ): array {
		if ( method_exists( $request, 'get_json_params' ) ) {
			$payload = $request->get_json_params();
			if ( is_array( $payload ) ) {
				if ( isset( $payload['input'] ) && is_array( $payload['input'] ) ) {
					return $payload['input'];
				}
				return $payload;
			}
		}

		if ( method_exists( $request, 'get_param' ) ) {
			$input = $request->get_param( 'input' );
			if ( is_array( $input ) ) {
				return $input;
			}
		}

		if ( method_exists( $request, 'get_params' ) ) {
			$params = $request->get_params();
			return is_array( $params ) ? $params : [];
		}

		return [];
	}

	private static function decode_request_body( $raw_body ): array {
		if ( is_array( $raw_body ) ) {
			return $raw_body;
		}
		if ( ! is_string( $raw_body ) || '' === $raw_body ) {
			return [];
		}
		$body = json_decode( $raw_body, true );
		return is_array( $body ) ? $body : [];
	}

	private static function is_zhipu_chat_endpoint( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return 'open.bigmodel.cn' === $host && str_contains( $path, '/chat/completions' );
	}

	private static function reset_trace(): void {
		@file_put_contents( self::TRACE_FILE, '' );
		@chmod( self::TRACE_FILE, 0600 );
	}

	private static function trace_event( string $event, array $data = [] ): void {
		$record = [
			'time'  => gmdate( 'c' ),
			'event' => $event,
			'data'  => $data,
		];

		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			: json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( is_string( $encoded ) ) {
			@file_put_contents( self::TRACE_FILE, $encoded . "\n", FILE_APPEND | LOCK_EX );
			@chmod( self::TRACE_FILE, 0600 );
		}
	}

	private static function request_message_text_chars( array $body ): int {
		$messages = isset( $body['messages'] ) && is_array( $body['messages'] )
			? $body['messages']
			: [];
		$total = 0;

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$content = $message['content'] ?? '';
			if ( is_string( $content ) ) {
				$total += self::char_length( $content );
				continue;
			}

			if ( is_array( $content ) ) {
				foreach ( $content as $part ) {
					if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
						$total += self::char_length( $part['text'] );
					}
				}
			}
		}

		return $total;
	}

	private static function value_type( $value ): string {
		if ( is_wp_error( $value ) ) {
			return 'WP_Error';
		}
		if ( is_object( $value ) ) {
			return get_class( $value );
		}
		return gettype( $value );
	}

	private static function char_slice( string $text, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, $start, $length, 'UTF-8' );
		}

		return (string) substr( $text, $start, $length );
	}

	private static function char_length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}
}

SWQ_GLM52_Translation_Tuning::boot();

<?php
/**
 * Plugin Name: SWQ GLM-5.2 Translation Tuning
 * Description: Tunes GLM-5.2 requests and enables one-shot full-article translation for SlyTranslate with language-aware prompts, safe Plaintext translation, protected structures, and exact post-content persistence.
 * Version: 2026.07.17.15
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
	private const PROMPT_POLICY_VERSION = '2026-07-16-v11';
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

		$exact_persist_result = self::persist_exact_post_content(
			$translated_post_id,
			$validated_translated_content
		);
		if ( is_wp_error( $exact_persist_result ) ) {
			return $exact_persist_result;
		}

		return $apply_result;
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
			'payload_chars'   => self::char_length( $payload ),
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
				'payload_chars'   => self::char_length( $payload ),
				'protected_count' => count( $map ),
				'target_language' => $to,
				'prompt_policy'   => self::PROMPT_POLICY_VERSION,
			]
		);

		try {
			$result = SlyTranslate\TranslationRuntime::translate_text_raw( $payload, $to, $from, $full_prompt );
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

		$translated_payload = self::unwrap_model_output( (string) $result );

		preg_match_all( self::PROTECTED_TOKEN_REGEX, $payload, $source_token_matches );
		$expected_tokens = isset( $source_token_matches[0] ) && is_array( $source_token_matches[0] )
			? $source_token_matches[0]
			: [];

		// Repair only an unambiguous leading-zero contraction that passes the full strict validation.
		$translated_payload = self::normalize_unique_protected_tokens( $translated_payload, $expected_tokens );
		$token_validation   = self::validate_protected_tokens( $translated_payload, $expected_tokens );
		if ( is_wp_error( $token_validation ) ) {
			return $token_validation;
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
		if ( preg_match( self::PROTECTED_TOKEN_REGEX, $content ) ) {
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
		$source_lines   = explode( "\n", $source_body );
		$translated_lines = explode( "\n", $translated_lf );

		if ( count( $source_lines ) !== count( $translated_lines ) ) {
			return new WP_Error(
				'swq_full_article_plaintext_line_count_changed',
				sprintf(
					'Plaintext line count changed during translation: source=%d, translated=%d.',
					count( $source_lines ),
					count( $translated_lines )
				)
			);
		}

		foreach ( $source_lines as $index => $source_line ) {
			$indent = '';
			if ( preg_match( '/^[ \t]*/', $source_line, $indent_match ) ) {
				$indent = (string) $indent_match[0];
			}
			$translated_lines[ $index ] = $indent . ltrim( $translated_lines[ $index ], " \t" );
		}

		$normalized = implode( "\n", $translated_lines ) . $source_trailer;
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
				static fn ( string $token ): bool => ! str_starts_with( $token, 'SWQINLINE' )
			)
		);
		$actual_fixed_tokens = array_values(
			array_filter(
				$actual_tokens,
				static fn ( string $token ): bool => ! str_starts_with( $token, 'SWQINLINE' )
			)
		);

		return $expected_fixed_tokens === $actual_fixed_tokens;
	}

	private static function validate_protected_tokens( string $translated, array $expected_tokens ) {
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
				static fn ( string $token ): bool => ! str_starts_with( $token, 'SWQINLINE' )
			)
		);

		$actual_fixed_tokens = array_values(
			array_filter(
				$actual_tokens,
				static fn ( string $token ): bool => ! str_starts_with( $token, 'SWQINLINE' )
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

		$summary = [
			'post_id'                         => (int) ( self::$state['post_id'] ?? 0 ),
			'expected_count'                   => $expected_count,
			'actual_count'                     => $actual_count,
			'expected_fixed_count'             => count( $expected_fixed_tokens ),
			'actual_fixed_count'               => count( $actual_fixed_tokens ),
			'fixed_order_valid'                => $fixed_order_valid,
			'first_fixed_mismatch_position'    => $first_fixed_mismatch,
			'expected_fixed_at_mismatch'        => $first_fixed_mismatch > 0 ? ( $expected_fixed_tokens[ $first_fixed_mismatch - 1 ] ?? null ) : null,
			'actual_fixed_at_mismatch'          => $first_fixed_mismatch > 0 ? ( $actual_fixed_tokens[ $first_fixed_mismatch - 1 ] ?? null ) : null,
			'missing_tokens'                   => array_slice( $missing_tokens, 0, 20 ),
			'extra_tokens'                     => array_slice( $extra_tokens, 0, 20 ),
			'duplicate_tokens'                 => $duplicate_tokens,
		];

		$summary_file = '/tmp/swq-glm52-token-validation-summary.json';
		$output_file  = '/tmp/swq-glm52-token-validation-output.txt';
		$summary_json = wp_json_encode( $summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

		if ( is_string( $summary_json ) ) {
			@file_put_contents( $summary_file, $summary_json . "\n", LOCK_EX );
			@chmod( $summary_file, 0600 );
		}

		@file_put_contents( $output_file, $translated, LOCK_EX );
		@chmod( $output_file, 0600 );

		return new WP_Error(
			'swq_full_article_token_validation_failed',
			sprintf(
				'Protected token validation failed: expected=%d, actual=%d, fixed_order=%s. Diagnostic: %s',
				$expected_count,
				$actual_count,
				$fixed_order_valid ? 'yes' : 'no',
				$summary_file
			)
		);
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

		return "FULL-ARTICLE TRANSLATION MODE:\n\n"
			. "The input is the complete body of one technical article. Translate it as one coherent, publication-ready document into the requested target language.\n\n"
			. "Determine the article's actual technical field from its content. Do not assume that every article is about WordPress, PHP, Nginx, servers, or any other fixed technology.\n\n"
			. $language_rules
			. "\n\n"
			. <<<'PROMPT'
The article title and summary above are context only. Do not include them in the returned body.

Non-translatable code, inline code, fixed HTML elements, Gutenberg block boundaries and selected translatable regions have been replaced with protected tokens.

A token beginning with SWQBLOCK represents a complete standalone non-translatable code block or code element. Copy every SWQBLOCK token byte-for-byte, exactly once. Treat every SWQBLOCK token as an immovable document anchor: keep all SWQBLOCK tokens in their original relative order and original document position. Never reverse, swap or regroup two SWQBLOCK tokens merely to produce smoother target-language syntax. Rewrite only the natural-language text before, between and after the fixed tokens so that the target-language sentence remains accurate while the token order stays unchanged. For example, when the source structure is 'in the property: SWQBLOCK000001END ... submit: SWQBLOCK000002END', keep token 000001 before token 000002; do not rewrite it as 'submit SWQBLOCK000002END to the property SWQBLOCK000001END'. Before returning the article, perform a final check that the SWQBLOCK sequence is identical to the input sequence.

A token beginning with SWQINLINE represents inline code inside natural-language text. Copy every SWQINLINE token byte-for-byte and exactly once. An SWQINLINE token may move only within the same sentence when required by natural word order; never move it to another paragraph, list item, heading, table cell or section.

A token beginning with SWQHTML represents a fixed HTML element. Copy it byte-for-byte, exactly once, without moving it.

A token beginning with SWQSTRUCT represents an original Gutenberg opening or closing block comment. Treat every SWQSTRUCT token as an immovable block boundary. Copy it byte-for-byte, exactly once, preserve the complete SWQSTRUCT sequence, and never delete a block boundary or merge the text from two blocks into one block. Even when two adjacent source paragraphs would form a smoother target-language sentence if combined, keep both block boundaries and express the meaning within the original blocks.

Text between a matching SWQPLAINSTART token and SWQPLAINSTOP token comes from a Plaintext or preformatted block. The default rule is to translate every human-language fragment in these regions. Translate author-written labels, annotations, summaries, instructions and descriptions even when they are short, appear alone on one line, resemble terminal output, or are mixed with URLs, domains and identifiers. Preserve the machine value but translate the human explanation around it. For example, translate 中文站： and 英文站： as Chinese site: and English site:, translate 选择 Browser as Select Browser, and translate the explanatory part of www.example.com：补丁正常输出 while preserving the domain exactly. Preserve source-language wording only when the surrounding article explicitly identifies that exact wording itself as evidence from the real system and changing it would make the example factually inaccurate. This narrow exception may apply to an exact interface label, raw error message, raw log entry or literal observed value. For example, preserve 中文（中国） when the surrounding prose explains that it is the exact language-switcher label displayed on the Chinese site. Merely being inside a Plaintext block, looking like output, or being a short label is not enough reason to preserve it. When the role of a natural-language fragment remains ambiguous, translate it. A Plaintext region that contains only URLs, domains, paths, commands, identifiers, status codes or other machine-readable values is exposed so you can understand the surrounding sentence; copy that region's inner text exactly and do not relocate the region. Always preserve URLs, domains, paths, commands, parameters, machine identifiers, status codes, arrows, punctuation, line order, line count, blank lines and indentation. Do not add Markdown fences or surrounding quotation marks.

Text between a matching SWQALTSTART token and SWQALTSTOP token is an image alt value. Translate it naturally and concisely. Return the alt text without adding surrounding quotation marks. The surrounding image tag is rebuilt separately.

Never translate, rename, remove, duplicate, split, wrap or renumber any protected token. Never move SWQBLOCK, SWQHTML, SWQSTRUCT, SWQPLAINSTART, SWQPLAINSTOP, SWQALTSTART or SWQALTSTOP tokens from their original document position.

The original Gutenberg block comments are represented by SWQSTRUCT tokens. The remaining input still contains HTML tags, attributes, URLs, shortcodes, variables and document structure.

Preserve all SWQSTRUCT tokens, HTML tags, tag attributes, block attributes, URLs, domains, shortcodes, variables, commands, parameters, configuration keys, paths, filenames and identifiers exactly as they appear.

Do not add, delete, merge, split or reorder Gutenberg blocks, HTML elements, paragraphs, headings, list items, table cells or sections. Preserve their hierarchy, relative order and document position.

Within the same heading, paragraph, list item or table cell, you may reorganize sentence structure, subjects, modifiers and clauses when required for natural expression in the target language.

Translate only the natural-language text.

Preserve the original meaning, facts, technical distinctions, operational steps, chronology, risk assessments, degree of certainty and conclusions. Do not turn links into redirects, cache entries into database records, or otherwise blur technical distinctions present in the source.

Do not add explanations, introductions, conclusions, commentary, disclaimers, marketing language, Markdown code fences or content absent from the source.

Return only the complete translated article body.
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


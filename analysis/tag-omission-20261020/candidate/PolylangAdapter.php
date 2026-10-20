<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

class PolylangAdapter implements TranslationPluginAdapter, TranslationMutationAdapter {

	public function is_available(): bool {
		return function_exists( 'pll_languages_list' );
	}

	public function get_languages(): array {
		if ( ! $this->is_available() ) {
			return array();
		}
		$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
		$names = pll_languages_list( array( 'fields' => 'name' ) );
		if ( ! is_array( $slugs ) || ! is_array( $names ) || count( $slugs ) !== count( $names ) ) {
			return array();
		}

		return array_combine( $slugs, $names ) ?: array();
	}

	public function get_post_language( int $post_id ): ?string {
		if ( ! $this->is_available() ) {
			return null;
		}
		$lang = pll_get_post_language( $post_id );
		return $lang ?: null;
	}

	public function get_post_translations( int $post_id ): array {
		if ( ! $this->is_available() ) {
			return array();
		}
		return pll_get_post_translations( $post_id );
	}

	/**
	 * Whether Polylang exposes the term-translation API needed by
	 * TermTranslationService. String-table adapters never support this —
	 * their string-table path already covers term names.
	 */
	public function supports_term_translation(): bool {
		return $this->is_available()
			&& function_exists( 'pll_get_term' )
			&& function_exists( 'pll_set_term_language' )
			&& function_exists( 'pll_save_term_translations' );
	}

	public function supports_mutation_capability( string $capability ): bool {
		if ( ! $this->is_available() ) {
			return false;
		}

		if ( TranslationMutationAdapter::CAPABILITY_SET_POST_LANGUAGE === $capability ) {
			return function_exists( 'pll_set_post_language' );
		}

		if ( TranslationMutationAdapter::CAPABILITY_RELINK_TRANSLATION === $capability ) {
			return function_exists( 'pll_save_post_translations' );
		}

		return false;
	}

	public function set_post_language( int $post_id, string $target_language ) {
		if ( ! $this->supports_mutation_capability( TranslationMutationAdapter::CAPABILITY_SET_POST_LANGUAGE ) ) {
			return new \WP_Error( 'unsupported_language_mutation', __( 'The active translation plugin does not support changing post languages.', 'slytranslate' ) );
		}

		$current_language = $this->get_post_language( $post_id );
		if ( null !== $current_language && $current_language === $target_language ) {
			return true;
		}

		$result = pll_set_post_language( $post_id, $target_language );
		if ( false === $result ) {
			if ( $this->can_confirm_target_language( $post_id, $target_language ) ) {
				return true;
			}

			return new \WP_Error( 'polylang_update_failed', __( 'Polylang could not update the post language.', 'slytranslate' ) );
		}

		return true;
	}

	public function relink_post_translations( array $translations ) {
		if ( ! $this->supports_mutation_capability( TranslationMutationAdapter::CAPABILITY_RELINK_TRANSLATION ) ) {
			return new \WP_Error( 'unsupported_language_mutation', __( 'The active translation plugin does not support translation relinking.', 'slytranslate' ) );
		}

		$normalized = array();
		foreach ( $translations as $language_code => $post_id ) {
			$code = sanitize_key( (string) $language_code );
			$id   = absint( $post_id );
			if ( '' === $code || $id < 1 ) {
				continue;
			}
			$normalized[ $code ] = $id;
		}

		if ( empty( $normalized ) ) {
			return true;
		}

		$result = pll_save_post_translations( $normalized );
		if ( false === $result ) {
			return new \WP_Error( 'polylang_update_failed', __( 'Polylang could not rewrite translation links.', 'slytranslate' ) );
		}

		return true;
	}

	public function create_translation( int $source_post_id, string $target_lang, array $data ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error( 'polylang_not_available', 'Polylang is not active.' );
		}

		$post = get_post( $source_post_id );
		if ( ! $post ) {
			return new \WP_Error( 'source_post_not_found', 'Source post not found.' );
		}

		$from_lang = $this->get_post_language( $source_post_id );
		$existing  = pll_get_post( $source_post_id, $target_lang );
		$overwrite = ! empty( $data['overwrite'] );

		if ( $existing && get_post_status( $existing ) !== false && ! $overwrite ) {
			return new \WP_Error(
				'translation_exists',
				sprintf( 'A translation for language "%s" already exists (post %d).', $target_lang, $existing )
			);
		}

		$translation_id    = $existing;
		$is_new_translation = ! $translation_id;

		// This site's workflow prepares tag translations before translating posts.
		// Fail before creating a draft if any prepared mapping cannot be resolved.
		$expected_tag_ids = null;
		if ( is_object_in_taxonomy( $post->post_type, 'post_tag' ) ) {
			$source_tag_ids = wp_get_object_terms( $source_post_id, 'post_tag', array( 'fields' => 'ids', 'cache_results' => false, 'lang' => '' ) );
			if ( is_wp_error( $source_tag_ids ) ) {
				return $source_tag_ids;
			}
			$expected_tag_ids = array();
			$missing_tag_ids  = array();
			foreach ( $source_tag_ids as $source_tag_id ) {
				$target_tag_id = $this->get_uncached_tag_translation( (int) $source_tag_id, (string) $from_lang, $target_lang );
				if ( is_wp_error( $target_tag_id ) ) {
					return $target_tag_id;
				}
				if ( $target_tag_id > 0 ) {
					$expected_tag_ids[] = $target_tag_id;
				} else {
					$missing_tag_ids[] = (int) $source_tag_id;
				}
			}
			if ( $missing_tag_ids ) {
				return new \WP_Error( 'slytranslate_tag_mapping_missing', 'Tag mappings are incomplete; no new draft was created. Inspect mappings before retrying.', array(
					'source_post_id' => $source_post_id, 'target_language' => $target_lang,
					'source_tag_ids' => $source_tag_ids, 'resolved_tag_ids' => $expected_tag_ids, 'missing_source_tag_ids' => $missing_tag_ids,
				) );
			}
			$expected_tag_ids = array_values( array_unique( $expected_tag_ids ) );
			sort( $expected_tag_ids, SORT_NUMERIC );
		}

		// Use a filter to preserve the original post author.
		$author_override = function ( $data ) use ( $post ) {
			$data['post_author'] = $post->post_author;
			return $data;
		};

		if ( ! $translation_id ) {
			add_filter( 'wp_insert_post_data', $author_override, 99 );
			$translation_id = wp_insert_post( wp_slash( array(
				'post_status'       => 'draft',
				'post_title'        => $post->post_title . " ({$target_lang})",
				'post_content'      => ' ',
				'post_type'         => $post->post_type,
				'post_author'       => $post->post_author,
				'post_date'         => $post->post_date,
				'post_date_gmt'     => $post->post_date_gmt,
				'post_modified'     => $post->post_modified,
				'post_modified_gmt' => $post->post_modified_gmt,
			) ) );
			remove_filter( 'wp_insert_post_data', $author_override, 99 );

			if ( is_wp_error( $translation_id ) ) {
				return $translation_id;
			}

			$set_result = $this->set_post_language( $translation_id, $target_lang );
			// Polylang can return false here even when the post already resolves
			// to the target language shortly afterwards. Treat this as best-effort
			// and rely on the final translation-link save as the authoritative step.
			unset( $set_result );
		}

		$update_data = array( 'ID' => $translation_id );
		if ( isset( $data['post_title'] ) ) {
			$update_data['post_title'] = sanitize_text_field( $data['post_title'] );
		}
		if ( isset( $data['post_content'] ) ) {
			// Do NOT pre-filter through wp_kses_post() here. WordPress core's
			// `content_save_pre` filter (registered by kses_init_filters)
			// already runs wp_kses for users without the `unfiltered_html`
			// capability inside wp_update_post(). Calling wp_kses_post()
			// unconditionally lowercases case-sensitive SVG attributes
			// (viewBox → viewbox), strips data-* attributes from custom
			// blocks like kevinbatdorf/code-block-pro, and removes
			// `tabindex` from various tags — which causes Gutenberg to
			// invalidate the block ("Block contains unexpected or invalid
			// content").
			$update_data['post_content'] = $data['post_content'];
		}
		if ( isset( $data['post_excerpt'] ) ) {
			$update_data['post_excerpt'] = $data['post_excerpt'];
		}
		$update_data['post_status'] = $data['post_status'] ?? 'draft';

		// Opt-in slug translation: derive the slug from the already-translated
		// title (no extra LLM call). Only on newly created translations — or
		// when the existing slug is still the auto-generated stub — because
		// changing slugs of live translations breaks inbound links.
		if ( get_option( 'slytranslate_translate_slugs', '0' ) === '1'
			&& isset( $update_data['post_title'] )
			&& '' !== trim( (string) $update_data['post_title'] )
		) {
			$apply_slug = $is_new_translation;
			if ( ! $apply_slug && function_exists( 'get_post_field' ) ) {
				$current_slug = (string) get_post_field( 'post_name', $translation_id );
				$apply_slug   = '' !== $current_slug && $current_slug === sanitize_title( $post->post_title . " ({$target_lang})" );
			}

			if ( $apply_slug ) {
				$translated_slug = sanitize_title( (string) $update_data['post_title'] );
				if ( '' !== $translated_slug ) {
					$update_data['post_name'] = function_exists( 'wp_unique_post_slug' )
						? wp_unique_post_slug( $translated_slug, (int) $translation_id, (string) $update_data['post_status'], $post->post_type, (int) ( $post->post_parent ?? 0 ) )
						: $translated_slug;
				}
			}
		}

		wp_update_post( wp_slash( $update_data ) );

		// Loop-guard marker for auto-translate-on-publish: posts created by the
		// plugin must never trigger another translation round.
		update_post_meta( $translation_id, AutoPublishTranslationService::GENERATED_META_KEY, '1' );

		// Copy and process meta.
		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $key => $value ) {
				update_post_meta( $translation_id, $key, is_string( $value ) ? sanitize_text_field( $value ) : $value );
			}
		}

		// Copy taxonomy terms, translating them when Polylang provides a matching target-language term.
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy, array( 'language', 'post_translations', 'post_tag' ), true ) ) {
				continue;
			}

			$term_ids = wp_get_object_terms( $source_post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
				continue;
			}

			$translated_term_ids = array();
			foreach ( $term_ids as $term_id ) {
				$translated_term_id = pll_get_term( $term_id, $target_lang );
				if ( $translated_term_id ) {
					$translated_term_ids[] = $translated_term_id;
					continue;
				}

				if ( function_exists( 'pll_is_translated_taxonomy' ) && ! pll_is_translated_taxonomy( $taxonomy ) ) {
					$translated_term_ids[] = $term_id;
					continue;
				}

				// Opt-in: translate the missing term instead of silently
				// dropping it, so the translated post keeps its categories/tags.
				if ( TermTranslationService::is_enabled() && $this->supports_term_translation() && is_string( $from_lang ) && '' !== $from_lang ) {
					$created_term_id = TermTranslationService::translate_term( (int) $term_id, $target_lang, $from_lang );
					if ( ! is_wp_error( $created_term_id ) && $created_term_id > 0 ) {
						$translated_term_ids[] = $created_term_id;
					}
				}
			}

			if ( ! empty( $translated_term_ids ) ) {
				wp_set_object_terms( $translation_id, $translated_term_ids, $taxonomy, false );
			}
		}

		if ( null !== $expected_tag_ids ) {
			$tag_result = wp_set_object_terms( $translation_id, $expected_tag_ids, 'post_tag', false );
			if ( is_wp_error( $tag_result ) ) {
				return new \WP_Error( 'slytranslate_tag_write_failed', 'Tag assignment failed; keep the existing draft and do not retranslate automatically.', array(
					'translated_post_id' => (int) $translation_id, 'expected_tag_ids' => $expected_tag_ids,
					'cause' => $tag_result->get_error_code(),
				) );
			}
		}

		// Link the translation.
		if ( ! $this->link_translation( $source_post_id, $translation_id, $target_lang ) ) {
			return new \WP_Error( 'polylang_update_failed', __( 'Polylang could not rewrite translation links.', 'slytranslate' ), array(
				'source_post_id' => $source_post_id, 'translated_post_id' => (int) $translation_id,
			) );
		}

		// Read after all synchronous hooks, bypassing the term-query result cache.
		if ( null !== $expected_tag_ids ) {
			$actual_tag_ids = wp_get_object_terms( $translation_id, 'post_tag', array( 'fields' => 'ids', 'cache_results' => false, 'lang' => '' ) );
			if ( is_wp_error( $actual_tag_ids ) ) {
				return new \WP_Error( 'slytranslate_tag_verification_failed', 'Could not verify assigned tags; preserve the draft.', array(
					'translated_post_id' => (int) $translation_id, 'cause' => $actual_tag_ids->get_error_code(),
				) );
			}
			$actual_tag_ids = array_values( array_unique( array_map( 'intval', $actual_tag_ids ) ) );
			sort( $actual_tag_ids, SORT_NUMERIC );
			if ( $actual_tag_ids !== $expected_tag_ids ) {
				return new \WP_Error( 'slytranslate_tag_verification_failed', 'Assigned tags differ from expected tags; preserve the draft and do not retranslate automatically.', array(
					'translated_post_id' => (int) $translation_id, 'expected_tag_ids' => $expected_tag_ids, 'actual_tag_ids' => $actual_tag_ids,
					'missing_tag_ids' => array_values( array_diff( $expected_tag_ids, $actual_tag_ids ) ),
					'extra_tag_ids' => array_values( array_diff( $actual_tag_ids, $expected_tag_ids ) ),
				) );
			}
		}

		return $translation_id;
	}

	public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
		if ( ! $this->is_available() ) {
			return false;
		}
		$source_lang = pll_get_post_language( $source_post_id );
		$result = pll_save_post_translations( array(
			$source_lang => $source_post_id,
			$target_lang => $translated_post_id,
		) );
		return false !== $result;
	}

	/**
	 * Polylang stores term translations in term_taxonomy.description (3.9.0).
	 * Its public lookup API has no cache-bypass option. Read only this tag's
	 * mapping here; do not invalidate or replace any shared cache entries.
	 *
	 * @return int|\WP_Error Target tag ID, 0 if unmapped, or a read/schema error.
	 */
	private function get_uncached_tag_translation( int $source_tag_id, string $source_lang, string $target_lang ) {
		global $wpdb;

		$groups = $wpdb->get_col( $wpdb->prepare(
			"SELECT tt.description FROM {$wpdb->term_relationships} AS tr
			 INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 WHERE tr.object_id = %d AND tt.taxonomy = %s LIMIT 2",
			$source_tag_id, 'term_translations'
		) );
		if ( '' !== $wpdb->last_error || count( $groups ) > 1 ) {
			return new \WP_Error( 'slytranslate_tag_mapping_read_failed', 'Could not read one unambiguous tag translation group.', array( 'source_tag_id' => $source_tag_id ) );
		}
		if ( empty( $groups ) ) {
			return 0;
		}

		$translations = @unserialize( trim( $groups[0] ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $translations ) || (int) ( $translations[ $source_lang ] ?? 0 ) !== $source_tag_id ) {
			return new \WP_Error( 'slytranslate_tag_mapping_invalid', 'The tag translation group format or source membership is invalid.', array( 'source_tag_id' => $source_tag_id ) );
		}
		$target_tag_id = $translations[ $target_lang ] ?? 0;
		if ( false === filter_var( $target_tag_id, FILTER_VALIDATE_INT ) || (int) $target_tag_id < 0 ) {
			return new \WP_Error( 'slytranslate_tag_mapping_invalid', 'The target tag ID is invalid.', array( 'source_tag_id' => $source_tag_id ) );
		}
		return (int) $target_tag_id;
	}

	private function can_confirm_target_language( int $post_id, string $target_language ): bool {
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $post_id );
			}

			$resolved_language = $this->get_post_language( $post_id );
			if ( null !== $resolved_language && $resolved_language === $target_language ) {
				return true;
			}

			if ( function_exists( 'pll_get_post' ) ) {
				$resolved_post = absint( pll_get_post( $post_id, $target_language ) );
				if ( $resolved_post === $post_id ) {
					return true;
				}
			}

			if ( $attempt < 19 ) {
				usleep( 100000 );
			}
		}

		return false;
	}

}

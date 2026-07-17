<?php
/**
 * The sole commit protocol for category generation.
 *
 * Generation is deliberately outside this class: template, provider and retry
 * code may choose a fragment, but none of them may persist it piecemeal.  The
 * caller supplies the already-composed document and the final-document
 * validator.  This keeps the WordPress write, readback and metadata ordering
 * identical for every strategy.
 */
namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CategoryGenerationTransaction {
	/** Metadata owned by category generation and therefore rollback-safe. */
	public const META_KEYS = [
		'_tmwseo_quality_score', 'rank_math_title', 'rank_math_description',
		'rank_math_focus_keyword', 'rank_math_additional_keywords', '_tmwseo_keyword',
		'_tmwseo_secondary_keywords', '_tmwseo_rankmath_chip_report',
		'_tmwseo_image_analysis', '_tmwseo_ready_to_index', 'rank_math_robots',
		'_tmwseo_category_generation_status', '_tmwseo_category_generation_error',
		'_tmwseo_category_last_save_result',
	];

	public static function canonicalize( string $html ): string {
		// WordPress may normalize line endings; it must not otherwise alter a
		// generated document. This is the one normalization used for both hashes.
		return str_replace( [ "\r\n", "\r" ], "\n", trim( $html ) );
	}

	/** @return array<string,mixed> */
	public static function commit( \WP_Post $post, string $fragment, string $final_document, array $context = [] ): array {
		$post_id = (int) $post->ID;
		$run_id = (string) ( $context['run_id'] ?? wp_generate_uuid4() );
		$result = [ 'ok' => false, 'written' => false, 'run_id' => $run_id, 'post_id' => $post_id,
			'strategy' => (string) ( $context['strategy'] ?? 'unknown' ), 'provider' => (string) ( $context['provider'] ?? 'unknown' ),
			'failure_code' => '', 'reasons' => [], 'old_content_hash' => hash( 'sha256', self::canonicalize( (string) $post->post_content ) ),
			'fragment_hash' => hash( 'sha256', self::canonicalize( $fragment ) ), 'final_intended_hash' => hash( 'sha256', self::canonicalize( $final_document ) ),
			'persisted_readback_hash' => '', 'save_result' => null, 'finalization_status' => 'not_started',
			'readiness' => null, 'robots_before' => get_post_meta( $post_id, 'rank_math_robots', true ), 'robots_after' => null ];
		$snapshot = self::snapshot( $post );
		if ( trim( $fragment ) === '' ) { return self::failed( $result, $snapshot, 'empty_generated_fragment', [ 'Generated fragment is empty.' ] ); }
		$validate = $context['validate'] ?? null;
		if ( is_callable( $validate ) ) {
			$validation = call_user_func( $validate, $final_document );
			if ( empty( $validation['ok'] ) ) return self::failed( $result, $snapshot, 'blocked_pipeline_validation', (array) ( $validation['reasons'] ?? [ 'Final-document validation failed.' ] ) );
		}
		$save = wp_update_post( [ 'ID' => $post_id, 'post_content' => $final_document ], true );
		$result['save_result'] = is_wp_error( $save ) ? [ 'wp_error' => $save->get_error_code(), 'message' => $save->get_error_message() ] : $save;
		if ( is_wp_error( $save ) || (int) $save <= 0 ) return self::failed( $result, $snapshot, 'save_wp_error', [ is_wp_error( $save ) ? $save->get_error_message() : 'wp_update_post returned no post ID.' ] );
		clean_post_cache( $post_id );
		$readback = (string) get_post_field( 'post_content', $post_id );
		$result['persisted_readback_hash'] = hash( 'sha256', self::canonicalize( $readback ) );
		if ( ! hash_equals( $result['final_intended_hash'], $result['persisted_readback_hash'] ) ) return self::failed( $result, $snapshot, 'persisted_readback_mismatch', [ 'Persisted content differs from the intended final document.' ] );
		try {
			foreach ( [ 'persist_metadata', 'persist_chips', 'evaluate_readiness', 'apply_robots' ] as $step ) {
				if ( isset( $context[ $step ] ) && is_callable( $context[ $step ] ) ) $result[ $step ] = call_user_func( $context[ $step ], $post_id, $readback );
			}
		} catch ( \Throwable $e ) { return self::failed( $result, $snapshot, 'metadata_finalize_failed', [ $e->getMessage() ] ); }
		$result['ok'] = true; $result['written'] = true; $result['finalization_status'] = 'complete';
		$result['robots_after'] = get_post_meta( $post_id, 'rank_math_robots', true );
		update_post_meta( $post_id, '_tmwseo_category_last_save_result', wp_json_encode( $result ) );
		return $result;
	}
	private static function snapshot( \WP_Post $post ): array { $meta = []; foreach ( self::META_KEYS as $key ) $meta[ $key ] = get_post_meta( $post->ID, $key, false ); return [ 'content' => (string) $post->post_content, 'meta' => $meta ]; }
	private static function failed( array $result, array $snapshot, string $code, array $reasons ): array {
		$post_id = (int) $result['post_id']; wp_update_post( [ 'ID' => $post_id, 'post_content' => $snapshot['content'] ], true );
		foreach ( $snapshot['meta'] as $key => $values ) { delete_post_meta( $post_id, $key ); foreach ( $values as $value ) add_post_meta( $post_id, $key, $value ); }
		$result['failure_code'] = $code; $result['reasons'] = array_values( array_map( 'strval', $reasons ) ); $result['finalization_status'] = 'rolled_back';
		update_post_meta( $post_id, '_tmwseo_category_last_save_result', wp_json_encode( $result ) ); return $result;
	}
}

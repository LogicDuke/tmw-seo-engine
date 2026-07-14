<?php
/**
 * CategoryGenerationResult — the single immutable record of one generation.
 *
 * The Silver Fox inconsistency (report said age_style, sample said
 * broad_discovery) happened because the verification report was authored
 * separately from the sample run. This object makes that class of defect
 * structurally impossible: saving, samples, verification reports, admin
 * diagnostics, and tests all read the SAME object, and the generation ID
 * binds every surface to one run.
 *
 *  - input_hash:    sha256 of the canonicalized generation inputs;
 *  - generation_id: derived from input_hash + final_output_hash, so any two
 *    surfaces showing the same generation ID are provably describing the
 *    same input AND the same output;
 *  - all fields are set once in the constructor and only readable after.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.8
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CategoryGenerationResult {

	/** @var array<string,mixed> */
	private array $data;

	private const FIELDS = [
		'generation_id', 'input_hash', 'category_id', 'category_name', 'intent',
		'provider', 'content_plan', 'keyword_plan', 'raw_output_hash',
		'normalized_output_hash', 'repaired_output_hash', 'final_output_hash',
		'validation', 'similarity', 'uniqueness', 'claim_ledger',
		'grammar_repairs', 'faq_ids', 'specificity', 'stage_diffs',
		'attempts', 'final_status', 'failure_reasons',
	];

	/**
	 * @param array<string,mixed> $fields All FIELDS except generation_id
	 *                                    (derived) must be supplied.
	 */
	public function __construct( array $fields ) {
		$data = [];
		foreach ( self::FIELDS as $key ) {
			if ( $key === 'generation_id' ) { continue; }
			$data[ $key ] = $fields[ $key ] ?? null;
		}
		$data['generation_id'] = substr(
			hash( 'sha256', (string) $data['input_hash'] . '|' . (string) $data['final_output_hash'] ),
			0,
			16
		);
		$this->data = $data;
	}

	/** Canonical, order-independent hash of the generation inputs. */
	public static function hash_input( array $context, array $tracking_keywords, string $provider ): string {
		$canonical = [ 'context' => $context, 'tracking' => array_values( $tracking_keywords ), 'provider' => $provider ];
		self::ksort_deep( $canonical );
		return hash( 'sha256', (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $canonical ) : json_encode( $canonical ) ) );
	}

	/** Hash of an output stage (whitespace-insensitive so serialization noise cannot split hashes). */
	public static function hash_output( string $html ): string {
		return hash( 'sha256', trim( (string) preg_replace( '/\s+/u', ' ', $html ) ) );
	}

	public function generation_id(): string { return (string) $this->data['generation_id']; }
	public function input_hash(): string { return (string) $this->data['input_hash']; }
	public function intent(): string { return (string) $this->data['intent']; }
	public function provider(): string { return (string) $this->data['provider']; }
	public function final_status(): string { return (string) $this->data['final_status']; }
	public function final_output_hash(): string { return (string) $this->data['final_output_hash']; }
	public function attempts(): int { return (int) $this->data['attempts']; }

	/** @return mixed */
	public function get( string $key ) {
		return $this->data[ $key ] ?? null;
	}

	/** Full array form — the exact payload saved to debug meta and printed in samples/reports. */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Integrity check between this result and an independently reported view.
	 * Returns mismatch descriptions; empty array = consistent.
	 *
	 * @param array<string,mixed> $reported e.g. parsed debug meta or a report row.
	 * @return string[]
	 */
	public function verify_against( array $reported ): array {
		$mismatches = [];
		foreach ( [ 'generation_id', 'input_hash', 'intent', 'final_output_hash', 'final_status' ] as $key ) {
			if ( ! array_key_exists( $key, $reported ) ) { continue; }
			if ( (string) $reported[ $key ] !== (string) $this->data[ $key ] ) {
				$mismatches[] = $key . ': reported "' . (string) $reported[ $key ] . '" vs generated "' . (string) $this->data[ $key ] . '"';
			}
		}
		return $mismatches;
	}

	private static function ksort_deep( array &$arr ): void {
		ksort( $arr );
		foreach ( $arr as &$value ) {
			if ( is_array( $value ) && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				self::ksort_deep( $value );
			}
		}
	}
}

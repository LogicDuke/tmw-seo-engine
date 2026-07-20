<?php
/**
 * Coordinates validation and selection of candidate-only profile importers.
 *
 * @package TMWSEO\Engine\Import
 */
namespace TMWSEO\Engine\Import;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ImportManager {
    private SourceValidator $source_validator;
    /** @var ProfileImporter[] */
    private array $importers;

    /** @param ProfileImporter[] $importers */
    public function __construct( ?SourceValidator $source_validator = null, array $importers = [] ) {
        $this->source_validator = $source_validator ?? new SourceValidator();
        $this->importers = [];
        foreach ( $importers as $importer ) {
            $this->register_importer( $importer );
        }
    }

    public function register_importer( ProfileImporter $importer ): void {
        $this->importers[] = $importer;
    }

    public function import_profile( string $url ): ImportResult {
        $validation = $this->source_validator->validate( $url );
        if ( ! $validation->is_valid ) {
            return new ImportResult( [
                'status'     => ImportResult::STATUS_INVALID,
                'source_url' => $url,
                'diagnostics' => [ 'error_code' => $validation->error_code ],
                'message'    => $validation->message,
            ] );
        }

        foreach ( $this->importers as $importer ) {
            try {
                if ( ! $importer->supports( $validation->normalized_url ) ) {
                    continue;
                }
                return $importer->import_profile( $validation->normalized_url );
            } catch ( \Throwable $exception ) {
                return new ImportResult( [
                    'status'     => ImportResult::STATUS_ERROR,
                    'provider'   => $importer->provider_name(),
                    'source_url' => $validation->normalized_url,
                    'diagnostics' => [ 'exception' => get_class( $exception ) ],
                    'message'    => 'The profile importer could not complete the request.',
                ] );
            }
        }

        return new ImportResult( [
            'status'     => ImportResult::STATUS_UNSUPPORTED,
            'source_url' => $validation->normalized_url,
            'message'    => 'No profile importer is currently available.',
        ] );
    }
}

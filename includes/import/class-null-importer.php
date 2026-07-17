<?php
/**
 * No-op importer used until a provider-specific importer is registered.
 *
 * @package TMWSEO\Engine\Import
 */
namespace TMWSEO\Engine\Import;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class NullImporter implements ProfileImporter {
    public function provider_name(): string {
        return 'null';
    }

    public function supports( string $url ): bool {
        return false;
    }

    public function import_profile( string $url ): ImportResult {
        return new ImportResult( [
            'status'     => ImportResult::STATUS_UNSUPPORTED,
            'provider'   => $this->provider_name(),
            'source_url' => $url,
            'message'    => 'No profile importer is currently available.',
        ] );
    }
}

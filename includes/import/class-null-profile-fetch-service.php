<?php
/**
 * Deterministic no-op profile fetch service.
 *
 * @package TMWSEO\Engine\Import
 */
namespace TMWSEO\Engine\Import;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class NullProfileFetchService implements ProfileFetchService {
    public function fetch( ProfileFetchRequest $request ): ProfileFetchResult {
        return new ProfileFetchResult( [
            'status'      => ProfileFetchResult::STATUS_NOT_IMPLEMENTED,
            'provider'    => $request->provider,
            'source_url'  => $request->source_url,
            'username'    => $request->username,
            'diagnostics' => [
                'fetch_attempted'   => false,
                'fetch_implemented' => false,
            ],
            'warnings'    => [ 'Profile fetching is not implemented yet.' ],
            'message'     => 'The profile URL was recognized, but remote profile fetching is not implemented yet.',
        ] );
    }
}

<?php
/**
 * Unit tests for the candidate-only profile fetch service contract.
 */
namespace TMWSEO\Engine\Import;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/import/class-import-result.php';
require_once __DIR__ . '/../includes/import/class-profile-fetch-request.php';
require_once __DIR__ . '/../includes/import/class-profile-fetch-result.php';
require_once __DIR__ . '/../includes/import/interface-profile-importer.php';
require_once __DIR__ . '/../includes/import/interface-profile-fetch-service.php';
require_once __DIR__ . '/../includes/import/class-null-profile-fetch-service.php';
require_once __DIR__ . '/../includes/import/class-livejasmin-profile-importer.php';

final class ProfileFetchServiceTest extends TestCase {
    public function test_fetch_request_has_safe_defaults_and_preserves_input(): void {
        $default = new ProfileFetchRequest();
        self::assertSame( '', $default->provider );
        self::assertSame( '', $default->source_url );
        self::assertSame( '', $default->username );
        self::assertSame( 0, $default->post_id );
        self::assertSame( [], $default->context );

        $request = new ProfileFetchRequest( [
            'provider'   => 'livejasmin',
            'source_url' => 'https://www.livejasmin.com/en/chat/AbbyMurray',
            'username'   => 'AbbyMurray',
            'context'    => [ 'source' => 'test' ],
        ] );
        self::assertSame( 'livejasmin', $request->provider );
        self::assertSame( 'https://www.livejasmin.com/en/chat/AbbyMurray', $request->source_url );
        self::assertSame( 'AbbyMurray', $request->username );
        self::assertSame( [ 'source' => 'test' ], $request->context );
    }

    public function test_fetch_result_has_safe_defaults_and_preserves_data(): void {
        $default = new ProfileFetchResult();
        self::assertSame( ProfileFetchResult::STATUS_NOT_IMPLEMENTED, $default->status );
        self::assertSame( '', $default->display_name );
        self::assertSame( [], $default->raw_fields );
        self::assertSame( [], $default->attributes );
        self::assertSame( [], $default->diagnostics );
        self::assertSame( [], $default->warnings );
        self::assertSame( 'ok', ProfileFetchResult::STATUS_OK );
        self::assertSame( 'invalid', ProfileFetchResult::STATUS_INVALID );
        self::assertSame( 'error', ProfileFetchResult::STATUS_ERROR );

        $result = new ProfileFetchResult( [ 'message' => 'Test message', 'diagnostics' => [ 'test' => true ] ] );
        self::assertSame( 'Test message', $result->message );
        self::assertSame( [ 'test' => true ], $result->diagnostics );
    }

    public function test_null_fetch_service_returns_deterministic_non_persistent_result(): void {
        $before = $GLOBALS['_tmw_test_post_meta'];
        $result = ( new NullProfileFetchService() )->fetch( new ProfileFetchRequest( [
            'provider'   => 'livejasmin',
            'source_url' => 'https://www.livejasmin.com/en/chat/AbbyMurray',
            'username'   => 'AbbyMurray',
        ] ) );

        self::assertSame( ProfileFetchResult::STATUS_NOT_IMPLEMENTED, $result->status );
        self::assertSame( 'livejasmin', $result->provider );
        self::assertSame( 'https://www.livejasmin.com/en/chat/AbbyMurray', $result->source_url );
        self::assertSame( 'AbbyMurray', $result->username );
        self::assertSame( '', $result->display_name );
        self::assertSame( [], $result->raw_fields );
        self::assertSame( [], $result->attributes );
        self::assertSame( [ 'fetch_attempted' => false, 'fetch_implemented' => false ], $result->diagnostics );
        self::assertSame( [ 'Profile fetching is not implemented yet.' ], $result->warnings );
        self::assertSame( $before, $GLOBALS['_tmw_test_post_meta'] );
    }

    public function test_livejasmin_importer_injects_fetch_service_and_maps_candidate_data(): void {
        $service = new FakeProfileFetchService( new ProfileFetchResult( [
            'status'      => ProfileFetchResult::STATUS_OK,
            'provider'    => 'livejasmin',
            'source_url'  => 'https://www.livejasmin.com/en/chat/AbbyMurray',
            'username'    => 'AbbyMurray',
            'raw_fields'  => [ 'bio' => 'Candidate bio' ],
            'attributes'  => [ 'country' => 'US' ],
            'diagnostics' => [ 'candidate' => true ],
            'warnings'    => [ 'Review candidate data.' ],
            'message'     => 'Candidate data returned.',
        ] ) );
        $result = ( new LiveJasminProfileImporter( $service ) )->import_profile( 'https://livejasmin.com/chat/AbbyMurray' );

        self::assertCount( 1, $service->requests );
        self::assertSame( 'livejasmin', $service->requests[0]->provider );
        self::assertSame( 'https://www.livejasmin.com/en/chat/AbbyMurray', $service->requests[0]->source_url );
        self::assertSame( 'AbbyMurray', $service->requests[0]->username );
        self::assertSame( ImportResult::STATUS_OK, $result->status );
        self::assertSame( 'livejasmin', $result->provider );
        self::assertSame( 'https://www.livejasmin.com/en/chat/AbbyMurray', $result->source_url );
        self::assertSame( 'AbbyMurray', $result->username );
        self::assertSame( [ 'bio' => 'Candidate bio' ], $result->raw_fields );
        self::assertSame( [ 'country' => 'US' ], $result->attributes );
        self::assertSame( [ 'candidate' => true ], $result->diagnostics );
        self::assertSame( [ 'Review candidate data.' ], $result->warnings );
        self::assertSame( 'Candidate data returned.', $result->message );
    }

    public function test_livejasmin_importer_preserves_request_identity_for_empty_fetch_result_values(): void {
        $result = ( new LiveJasminProfileImporter( new FakeProfileFetchService( new ProfileFetchResult( [
            'status'     => ProfileFetchResult::STATUS_OK,
            'attributes' => [ 'country' => 'US' ],
        ] ) ) ) )->import_profile( 'https://livejasmin.com/chat/AbbyMurray' );

        self::assertSame( ImportResult::STATUS_OK, $result->status );
        self::assertSame( 'livejasmin', $result->provider );
        self::assertSame( 'https://www.livejasmin.com/en/chat/AbbyMurray', $result->source_url );
        self::assertSame( 'AbbyMurray', $result->username );
        self::assertSame( [ 'country' => 'US' ], $result->attributes );
    }

    public function test_livejasmin_importer_preserves_explicit_fetch_result_identity_overrides(): void {
        $result = ( new LiveJasminProfileImporter( new FakeProfileFetchService( new ProfileFetchResult( [
            'status'     => ProfileFetchResult::STATUS_OK,
            'provider'   => 'candidate-provider',
            'source_url' => 'https://example.com/candidate/AbbyMurray',
            'username'   => 'CandidateAbby',
        ] ) ) ) )->import_profile( 'https://livejasmin.com/chat/AbbyMurray' );

        self::assertSame( 'candidate-provider', $result->provider );
        self::assertSame( 'https://example.com/candidate/AbbyMurray', $result->source_url );
        self::assertSame( 'CandidateAbby', $result->username );
    }

    public function test_not_implemented_fetch_maps_to_non_success_and_invalid_urls_are_not_fetched(): void {
        $service = new FakeProfileFetchService( new ProfileFetchResult( [ 'status' => ProfileFetchResult::STATUS_NOT_IMPLEMENTED ] ) );
        $importer = new LiveJasminProfileImporter( $service );
        self::assertSame( ImportResult::STATUS_UNSUPPORTED, $importer->import_profile( 'https://www.livejasmin.com/en/chat/AbbyMurray' )->status );
        self::assertCount( 1, $service->requests );
        self::assertSame( ImportResult::STATUS_UNSUPPORTED, $importer->import_profile( 'https://www.livejasmin.com/en/chat/AbbyMurray/extra' )->status );
        self::assertCount( 1, $service->requests );
    }

    public function test_invalid_fetch_result_maps_to_invalid_import_result(): void {
        $importer = new LiveJasminProfileImporter( new FakeProfileFetchService( new ProfileFetchResult( [
            'status' => ProfileFetchResult::STATUS_INVALID,
        ] ) ) );
        self::assertSame( ImportResult::STATUS_INVALID, $importer->import_profile( 'https://www.livejasmin.com/en/chat/AbbyMurray' )->status );
    }

    public function test_fetch_exceptions_are_converted_to_safe_error_results(): void {
        $result = ( new LiveJasminProfileImporter( new FakeProfileFetchService( null, true ) ) )->import_profile( 'https://www.livejasmin.com/en/chat/AbbyMurray' );
        self::assertSame( ImportResult::STATUS_ERROR, $result->status );
        self::assertSame( 'The profile fetch service could not complete the request.', $result->message );
        self::assertSame( [ 'fetch_failed' => true ], $result->diagnostics );
    }
}

final class FakeProfileFetchService implements ProfileFetchService {
    /** @var ProfileFetchRequest[] */
    public array $requests = [];
    private ?ProfileFetchResult $result;
    private bool $throws;

    public function __construct( ?ProfileFetchResult $result, bool $throws = false ) {
        $this->result = $result;
        $this->throws = $throws;
    }

    public function fetch( ProfileFetchRequest $request ): ProfileFetchResult {
        $this->requests[] = $request;
        if ( $this->throws ) {
            throw new \RuntimeException( 'Fetch failure.' );
        }
        return $this->result ?? new ProfileFetchResult();
    }
}

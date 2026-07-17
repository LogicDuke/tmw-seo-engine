<?php
/**
 * Unit tests for the candidate-only public-profile import framework.
 */
namespace TMWSEO\Engine\Import;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/import/class-import-result.php';
require_once __DIR__ . '/../includes/import/class-source-validation-result.php';
require_once __DIR__ . '/../includes/import/interface-profile-importer.php';
require_once __DIR__ . '/../includes/import/class-source-validator.php';
require_once __DIR__ . '/../includes/import/class-null-importer.php';
require_once __DIR__ . '/../includes/import/class-livejasmin-profile-importer.php';
require_once __DIR__ . '/../includes/import/class-import-manager.php';
require_once __DIR__ . '/../includes/model/class-model-context-aware-provider-interface.php';
require_once __DIR__ . '/../includes/platform/class-platform-registry.php';
require_once __DIR__ . '/../includes/platform/class-platform-profiles.php';
require_once __DIR__ . '/../includes/db/class-logs.php';
require_once __DIR__ . '/../includes/admin/class-model-helper.php';

final class ProfileImportFrameworkTest extends TestCase {
    public function test_import_result_has_safe_defaults_and_accepts_data(): void {
        $default = new ImportResult();
        self::assertSame( ImportResult::STATUS_UNSUPPORTED, $default->status );
        self::assertSame( [], $default->raw_fields );
        self::assertSame( [], $default->attributes );
        self::assertSame( [], $default->diagnostics );
        self::assertSame( [], $default->warnings );
        self::assertSame( 'ok', ImportResult::STATUS_OK );
        self::assertSame( 'invalid', ImportResult::STATUS_INVALID );
        self::assertSame( 'error', ImportResult::STATUS_ERROR );

        $result = new ImportResult( [ 'status' => ImportResult::STATUS_OK, 'provider' => 'fake', 'raw_fields' => [ 'bio' => 'Candidate' ] ] );
        self::assertSame( ImportResult::STATUS_OK, $result->status );
        self::assertSame( 'fake', $result->provider );
        self::assertSame( [ 'bio' => 'Candidate' ], $result->raw_fields );
    }

    public function test_source_validation_result_has_safe_defaults(): void {
        $default = new SourceValidationResult();
        self::assertFalse( $default->is_valid );
        self::assertSame( '', $default->normalized_url );
        self::assertSame( '', $default->host );

        $valid = new SourceValidationResult( true, 'https://example.com/a', 'example.com' );
        self::assertTrue( $valid->is_valid );
        self::assertSame( 'example.com', $valid->host );
    }

    public function test_source_validator_accepts_public_https_and_normalizes_host(): void {
        $validator = new SourceValidator();
        self::assertTrue( $validator->validate( 'https://example.com/profile' )->is_valid );
        $result = $validator->validate( 'https://Example.COM/Profile?Ref=One' );
        self::assertTrue( $result->is_valid );
        self::assertSame( 'example.com', $result->host );
        self::assertSame( 'https://example.com/Profile?Ref=One', $result->normalized_url );
    }

    /** @dataProvider publicIpv4Urls */
    public function test_source_validator_accepts_public_ipv4_addresses( string $url ): void {
        self::assertTrue( ( new SourceValidator() )->validate( $url )->is_valid, $url );
    }

    /** @return array<string,array{string}> */
    public static function publicIpv4Urls(): array {
        return [
            'adjacent public range' => [ 'https://192.169.1.1/profile' ],
            'public dns' => [ 'https://8.8.8.8/profile' ],
        ];
    }

    /** @dataProvider unsafeUrls */
    public function test_source_validator_rejects_unsafe_urls( string $url ): void {
        self::assertFalse( ( new SourceValidator() )->validate( $url )->is_valid, $url );
    }

    /** @return array<string,array{string}> */
    public static function unsafeUrls(): array {
        return [
            'empty' => [ '' ], 'malformed' => [ 'not a url' ], 'http' => [ 'http://example.com' ],
            'javascript' => [ 'javascript:alert(1)' ], 'credentials' => [ 'https://user:pass@example.com' ],
            'localhost' => [ 'https://localhost/profile' ], 'localhost subdomain' => [ 'https://api.localhost/profile' ],
            'loopback ipv4' => [ 'https://127.0.0.1/profile' ], 'loopback ipv6' => [ 'https://[::1]/profile' ],
            'private ipv4' => [ 'https://192.168.1.10/profile' ], 'link local' => [ 'https://169.254.1.10/profile' ],
            'reserved' => [ 'https://192.0.2.1/profile' ], 'documentation' => [ 'https://203.0.113.1/profile' ],
        ];
    }

    public function test_null_importer_is_unsupported_and_has_no_side_effects(): void {
        $before = $GLOBALS['_tmw_test_post_meta'];
        $importer = new NullImporter();
        self::assertSame( 'null', $importer->provider_name() );
        self::assertFalse( $importer->supports( 'https://example.com/profile' ) );
        self::assertSame( ImportResult::STATUS_UNSUPPORTED, $importer->import_profile( 'https://example.com/profile' )->status );
        self::assertSame( $before, $GLOBALS['_tmw_test_post_meta'] );
    }

    /** @dataProvider supportedLiveJasminProfileUrls */
    public function test_livejasmin_importer_recognizes_public_profiles( string $url, string $username ): void {
        $before_meta = $GLOBALS['_tmw_test_post_meta'];
        $before_options = $GLOBALS['_tmw_test_options'];
        $before_transients = $GLOBALS['_tmw_test_transients'];
        $importer = new LiveJasminProfileImporter();

        self::assertSame( 'livejasmin', $importer->provider_name() );
        self::assertTrue( $importer->supports( $url ) );
        $result = $importer->import_profile( $url );
        self::assertSame( ImportResult::STATUS_OK, $result->status );
        self::assertSame( 'livejasmin', $result->provider );
        self::assertSame( $url, $result->source_url );
        self::assertSame( $username, $result->username );
        self::assertSame( 'not_implemented', $result->diagnostics['profile_fetching'] );
        self::assertSame( $before_meta, $GLOBALS['_tmw_test_post_meta'] );
        self::assertSame( $before_options, $GLOBALS['_tmw_test_options'] );
        self::assertSame( $before_transients, $GLOBALS['_tmw_test_transients'] );
    }

    /** @return array<string,array{string,string}> */
    public static function supportedLiveJasminProfileUrls(): array {
        return [
            'www host' => [ 'https://www.livejasmin.com/en/girl/Model_Name', 'Model_Name' ],
            'apex host' => [ 'https://livejasmin.com/de/girl/Model-Name?ref=profile', 'Model-Name' ],
        ];
    }

    /** @dataProvider unsupportedLiveJasminUrls */
    public function test_livejasmin_importer_rejects_non_profile_urls( string $url ): void {
        $importer = new LiveJasminProfileImporter();
        self::assertFalse( $importer->supports( $url ), $url );
        self::assertSame( ImportResult::STATUS_UNSUPPORTED, $importer->import_profile( $url )->status );
    }

    /** @return array<string,array{string}> */
    public static function unsupportedLiveJasminUrls(): array {
        return [
            'wrong host' => [ 'https://livejasmin.com.example/en/girl/model' ],
            'wrong path' => [ 'https://www.livejasmin.com/en/chat/model' ],
            'missing username' => [ 'https://www.livejasmin.com/en/girl/' ],
            'unsupported port' => [ 'https://www.livejasmin.com:8443/en/girl/model' ],
        ];
    }

    public function test_manager_validates_selects_and_returns_importer_result_without_persistence(): void {
        $before = $GLOBALS['_tmw_test_post_meta'];
        $importer = new FakeProfileImporter();
        $manager = new ImportManager( null, [ $importer ] );
        self::assertSame( ImportResult::STATUS_INVALID, $manager->import_profile( 'http://example.com' )->status );
        self::assertFalse( $importer->supported_called );
        self::assertSame( ImportResult::STATUS_OK, $manager->import_profile( 'https://example.com/profile' )->status );
        self::assertTrue( $importer->supported_called );
        self::assertSame( $before, $GLOBALS['_tmw_test_post_meta'] );
    }

    public function test_manager_returns_unsupported_and_converts_importer_exceptions(): void {
        self::assertSame( ImportResult::STATUS_UNSUPPORTED, ( new ImportManager() )->import_profile( 'https://example.com' )->status );
        $manager = new ImportManager( null, [ new ThrowingProfileImporter() ] );
        self::assertSame( ImportResult::STATUS_ERROR, $manager->import_profile( 'https://example.com' )->status );
    }

    public function test_ajax_response_logic_is_candidate_only_and_does_not_persist(): void {
        $before = $GLOBALS['_tmw_test_post_meta'];
        $valid = \TMWSEO\Engine\Admin\ModelHelper::public_profile_import_response( 'https://WWW.LiveJasmin.COM/en/girl/Model_Name' );
        self::assertSame( 'ok', $valid['status'] );
        self::assertSame( 'livejasmin', $valid['provider'] );
        self::assertSame( 'https://www.livejasmin.com/en/girl/Model_Name', $valid['source_url'] );
        self::assertSame( 'Model_Name', $valid['username'] );
        self::assertSame( 'invalid', \TMWSEO\Engine\Admin\ModelHelper::public_profile_import_response( 'http://example.com' )['status'] );
        self::assertSame( $before, $GLOBALS['_tmw_test_post_meta'] );
    }

    public function test_ajax_handler_returns_unsupported_without_persistence(): void {
        $before = $GLOBALS['_tmw_test_post_meta'];
        $_POST = [ 'post_id' => '42', 'nonce' => 'test_nonce', 'source_url' => 'https://www.livejasmin.com/en/girl/Model_Name' ];
        \TMWSEO\Engine\Admin\ModelHelper::ajax_public_profile_import();
        self::assertTrue( $GLOBALS['_tmw_test_last_json']['success'] );
        self::assertSame( 'ok', $GLOBALS['_tmw_test_last_json']['data']['status'] );
        self::assertSame( 'livejasmin', $GLOBALS['_tmw_test_last_json']['data']['provider'] );
        self::assertSame( 'Model_Name', $GLOBALS['_tmw_test_last_json']['data']['username'] );
        self::assertSame( $before, $GLOBALS['_tmw_test_post_meta'] );
        $_POST = [];
    }
}

final class FakeProfileImporter implements ProfileImporter {
    public bool $supported_called = false;
    public function provider_name(): string { return 'fake'; }
    public function supports( string $url ): bool { $this->supported_called = true; return true; }
    public function import_profile( string $url ): ImportResult { return new ImportResult( [ 'status' => ImportResult::STATUS_OK, 'provider' => 'fake', 'source_url' => $url ] ); }
}

final class ThrowingProfileImporter implements ProfileImporter {
    public function provider_name(): string { return 'throwing'; }
    public function supports( string $url ): bool { throw new \RuntimeException( 'test' ); }
    public function import_profile( string $url ): ImportResult { throw new \RuntimeException( 'test' ); }
}

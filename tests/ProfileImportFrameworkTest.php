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
require_once __DIR__ . '/../includes/import/class-import-manager.php';

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
            'reserved' => [ 'https://192.0.2.1/profile' ],
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

<?php
/**
 * Contract for importers that return candidate profile data only.
 *
 * @package TMWSEO\Engine\Import
 */
namespace TMWSEO\Engine\Import;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface ProfileImporter {
    public function provider_name(): string;

    public function supports( string $url ): bool;

    public function import_profile( string $url ): ImportResult;
}

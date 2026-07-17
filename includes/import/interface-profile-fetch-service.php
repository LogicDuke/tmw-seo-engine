<?php
/**
 * Contract for services that fetch candidate data for a recognized profile.
 *
 * @package TMWSEO\Engine\Import
 */
namespace TMWSEO\Engine\Import;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface ProfileFetchService {
    public function fetch( ProfileFetchRequest $request ): ProfileFetchResult;
}

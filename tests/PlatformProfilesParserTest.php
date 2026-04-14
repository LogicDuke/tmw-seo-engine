<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Platform\PlatformProfiles;

require_once TMWSEO_ENGINE_PATH . 'includes/platform/class-platform-registry.php';
require_once TMWSEO_ENGINE_PATH . 'includes/platform/class-platform-profiles.php';

final class PlatformProfilesParserTest extends TestCase {

    public function test_chaturbate_parses_with_and_without_trailing_slash(): void {
        $a = PlatformProfiles::parse_profile_candidate('chaturbate', 'https://chaturbate.com/AliceModel');
        $b = PlatformProfiles::parse_profile_candidate('chaturbate', 'https://chaturbate.com/AliceModel/');

        $this->assertTrue($a['success']);
        $this->assertTrue($b['success']);
        $this->assertSame('AliceModel', $a['username']);
        $this->assertSame('AliceModel', $b['username']);
    }

    public function test_camscom_parses_www_and_non_www(): void {
        $a = PlatformProfiles::parse_profile_candidate('camscom', 'https://www.cams.com/modelx');
        $b = PlatformProfiles::parse_profile_candidate('camscom', 'https://cams.com/modelx');
        $this->assertTrue($a['success']);
        $this->assertTrue($b['success']);
        $this->assertSame('modelx', $a['username']);
        $this->assertSame('modelx', $b['username']);
    }

    public function test_flirt4free_parses_query_and_video_route(): void {
        $a = PlatformProfiles::parse_profile_candidate('flirt4free', 'https://www.flirt4free.com/?model=RubySky');
        $b = PlatformProfiles::parse_profile_candidate('flirt4free', 'https://flirt4free.com/videos/girls/models/RubySky/');
        $this->assertTrue($a['success']);
        $this->assertTrue($b['success']);
        $this->assertSame('RubySky', $a['username']);
        $this->assertSame('RubySky', $b['username']);
    }

    public function test_fansly_stripchat_livejasmin_myfreecams_and_carrd(): void {
        $fansly = PlatformProfiles::parse_profile_candidate('fansly', 'https://fansly.com/amberfox/posts');
        $stripchat = PlatformProfiles::parse_profile_candidate('stripchat', 'https://es.stripchat.com/amber_fox');
        $livejasmin = PlatformProfiles::parse_profile_candidate('livejasmin', 'https://www.livejasmin.com/en/chat-html5/amberFox');
        $myfreecams = PlatformProfiles::parse_profile_candidate('myfreecams', 'https://www.myfreecams.com/#amberfox');
        $carrd = PlatformProfiles::parse_profile_candidate('carrd', 'https://amberfox.carrd.co/');

        $this->assertTrue($fansly['success']);
        $this->assertTrue($stripchat['success']);
        $this->assertTrue($livejasmin['success']);
        $this->assertTrue($myfreecams['success']);
        $this->assertTrue($carrd['success']);

        $this->assertSame('amberfox', $fansly['username']);
        $this->assertSame('amber_fox', $stripchat['username']);
        $this->assertSame('amberFox', $livejasmin['username']);
        $this->assertSame('amberfox', $myfreecams['username']);
        $this->assertSame('amberfox', $carrd['username']);
    }
}

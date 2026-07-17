<?php
namespace TMWSEO\Engine\Import;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../includes/import/class-profile-fetch-request.php';
require_once __DIR__ . '/../includes/import/class-profile-fetch-result.php';
require_once __DIR__ . '/../includes/import/interface-profile-fetch-service.php';
require_once __DIR__ . '/../includes/import/class-livejasmin-remote-profile-fetch-service.php';
final class LiveJasminRemoteProfileFetchServiceTest extends TestCase {
    private function request(): ProfileFetchRequest { return new ProfileFetchRequest(['provider'=>'livejasmin','source_url'=>'https://www.livejasmin.com/en/chat/AbbyMurray','username'=>'AbbyMurray']); }
    public function test_missing_configuration_is_safe(): void { $r=(new LiveJasminRemoteProfileFetchService(['endpoint'=>'','secret'=>'']))->fetch($this->request()); self::assertSame(ProfileFetchResult::STATUS_NOT_IMPLEMENTED,$r->status); }
    public function test_maps_bounded_json_with_request_identity(): void { $service=new LiveJasminRemoteProfileFetchService(['endpoint'=>'https://fetcher.example/v1/fetch-profile','secret'=>'x'],fn()=>['response'=>['code'=>200],'headers'=>['content-type'=>'application/json'],'body'=>json_encode(['status'=>'ok','raw_fields'=>['bio'=>'Candidate bio'],'attributes'=>['country'=>'US'],'diagnostics'=>['request_id'=>'a'],'warnings'=>[],'message'=>'Nothing saved.'])]); $r=$service->fetch($this->request()); self::assertSame('ok',$r->status);self::assertSame('livejasmin',$r->provider);self::assertSame('Candidate bio',$r->raw_fields['bio']); }
    public function test_conflicting_identity_is_rejected(): void { $service=new LiveJasminRemoteProfileFetchService(['endpoint'=>'https://fetcher.example/v1/fetch-profile','secret'=>'x'],fn()=>['response'=>['code'=>200],'headers'=>['content-type'=>'application/json'],'body'=>json_encode(['status'=>'ok','provider'=>'other'])]); self::assertSame('error',$service->fetch($this->request())->status); }
}

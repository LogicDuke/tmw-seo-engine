<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Platform\AffiliateLinkBuilder;

final class LiveJasminRedirectRuntimeTest extends TestCase {
    private const BUILDER_FILE = __DIR__ . '/../includes/platform/class-affiliate-link-builder.php';

    public function test_template_redirect_handler_is_registered_to_maybe_handle_redirect(): void {
        $source = (string) file_get_contents(self::BUILDER_FILE);

        $this->assertStringContainsString(
            "add_action('template_redirect', [__CLASS__, 'maybe_handle_redirect']);",
            $source
        );
    }


    public function test_plugin_bootstrap_initializes_affiliate_link_builder(): void {
        $pluginSource = (string) file_get_contents(__DIR__ . '/../includes/class-plugin.php');

        $this->assertStringContainsString(
            '\TMWSEO\Engine\Platform\AffiliateLinkBuilder::init();',
            $pluginSource
        );
    }
    public function test_maybe_handle_redirect_uses_resolve_go_destination(): void {
        $source = (string) file_get_contents(self::BUILDER_FILE);

        $this->assertStringContainsString(
            '$url = self::resolve_go_destination(',
            $source
        );
    }

    public function test_livejasmin_go_destination_is_canonical_ctwmsg_url(): void {
        update_option('tmwseo_platform_affiliate_settings', [
            'livejasmin' => [
                'enabled' => 1,
                'template' => 'https://www.livejasmin.com/en/free/chat/{username}?psid={psid}&pstool={pstool}&psprogram={psprogram}',
                'psid' => 'Topmodels4u',
                'pstool' => '205_1',
                'psprogram' => 'revs',
                'siteid' => 'jasmin',
                'categoryname' => 'girls',
                'pagename' => 'freechat',
            ],
        ]);

        $url = AffiliateLinkBuilder::resolve_go_destination('live_jasmin', 'Anisyia');
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('ctwmsg.com', (string) wp_parse_url($url, PHP_URL_HOST));
        $this->assertSame('Anisyia', (string) ($query['performerName'] ?? ''));
        $this->assertSame('jasmin', (string) ($query['siteId'] ?? ''));
        $this->assertSame('freechat', (string) ($query['pageName'] ?? ''));
        $this->assertSame('Topmodels4u', (string) ($query['prm']['psid'] ?? ''));
        $this->assertSame('205_1', (string) ($query['prm']['pstool'] ?? ''));
        $this->assertSame('revs', (string) ($query['prm']['psprogram'] ?? ''));
        $this->assertStringNotContainsString('www.livejasmin.com/en/free/chat', $url);
    }
}

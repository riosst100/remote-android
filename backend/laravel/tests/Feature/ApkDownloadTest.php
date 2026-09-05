<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApkDownloadTest extends TestCase
{
    private function apkPath(): string
    {
        return public_path('downloads/app-latest.apk');
    }

    protected function tearDown(): void
    {
        @unlink($this->apkPath());
        parent::tearDown();
    }

    public function test_the_download_page_is_publicly_accessible_without_login(): void
    {
        $this->get('/download')->assertOk();
    }

    public function test_the_download_page_shows_no_build_available_when_no_apk_is_published(): void
    {
        @unlink($this->apkPath());

        $response = $this->get('/download');

        $response->assertOk();
        $response->assertSee('No build available yet');
    }

    public function test_the_download_page_shows_a_download_link_once_an_apk_is_published(): void
    {
        file_put_contents($this->apkPath(), str_repeat('x', 1024 * 1024));

        $response = $this->get('/download');

        $response->assertOk();
        $response->assertSee('Download APK');
    }

    public function test_downloading_the_apk_file_works_without_login(): void
    {
        file_put_contents($this->apkPath(), 'fake-apk-bytes');

        $response = $this->get('/download/apk');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.android.package-archive');
    }

    public function test_downloading_when_no_apk_is_published_returns_404(): void
    {
        @unlink($this->apkPath());

        $this->get('/download/apk')->assertNotFound();
    }
}

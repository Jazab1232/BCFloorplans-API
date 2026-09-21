<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ImageResizeService;
use App\Services\WatermarkService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class MediaProcessingTest extends TestCase
{
    public function test_watermark_service_handles_missing_font_gracefully()
    {
        Log::shouldReceive('critical')
            ->once()
            ->withArgs(fn($message) => str_contains($message, 'FONT MISSING'));

        $watermarkService = new WatermarkService();

        // Create a dummy image using Intervention (mock or real if possible in test env)
        $img = Image::canvas(100, 100);

        $result = $watermarkService->apply($img);

        $this->assertEquals($img, $result);
    }

    public function test_image_resize_service_logs_error_on_no_variants()
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('test.jpg', 'fake-image-content');

        Log::shouldReceive('debug')->atLeast()->once();
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn($message) => str_contains($message, 'NO VARIANTS GENERATED'));

        $watermarkService = $this->createMock(WatermarkService::class);
        // We want generateVariant to fail or return nothing. 
        // ImageResizeService::processImage returns $generatedVariants.

        $resizer = new ImageResizeService($watermarkService);

        // This will likely fail because 'fake-image-content' isn't a real image
        // but it should trigger our new error log.
        try {
            $resizer->processImage('test.jpg', []);
        } catch (\Exception $e) {
            // expected
        }
    }

    public function test_tour_file_url_visibility_depends_on_payment_status()
    {
        $tourFilePaid = $this->getMockBuilder(\App\Models\TourFile::class)
            ->onlyMethods(['getIsPaidAttribute'])
            ->disableOriginalConstructor()
            ->getMock();
        $tourFilePaid->method('getIsPaidAttribute')->willReturn(true);
        $tourFilePaid->file_path = 'test/path.jpg';
        
        $arrayPaid = $tourFilePaid->toArray();
        $this->assertArrayHasKey('url', $arrayPaid);

        $tourFileUnpaid = $this->getMockBuilder(\App\Models\TourFile::class)
            ->onlyMethods(['getIsPaidAttribute'])
            ->disableOriginalConstructor()
            ->getMock();
        $tourFileUnpaid->method('getIsPaidAttribute')->willReturn(false);
        $tourFileUnpaid->file_path = 'test/path.jpg';

        $arrayUnpaid = $tourFileUnpaid->toArray();
        $this->assertArrayNotHasKey('url', $arrayUnpaid);
    }
}

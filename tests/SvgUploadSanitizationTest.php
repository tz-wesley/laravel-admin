<?php

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Form\Field\Image;
use Illuminate\Validation\ValidationException;

class SvgUploadSanitizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->be(Administrator::first(), 'admin');
    }

    public function testUnsafeSvgPayloadIsSanitizedInPlace()
    {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">
  <script>alert(1)</script>
  <style>.safe{fill:#fff}.bad{background:url(https://evil.test/payload)}</style>
  <circle id="shape" cx="10" cy="10" r="5" style="fill:#0f0" onclick="alert(2)" />
  <use xlink:href="#shape"></use>
  <a xlink:href="https://evil.test/redirect"><text>click</text></a>
  <image xlink:href="javascript:alert(3)" width="10" height="10"></image>
  <foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>
</svg>
SVG;

        $file = $this->makeUploadedSvg($svg);

        try {
            $this->invokeSvgSanitizer($file);

            $sanitized = file_get_contents($file->getRealPath());

            $this->assertStringNotContainsString('<script', $sanitized);
            $this->assertStringNotContainsString('<foreignObject', $sanitized);
            $this->assertStringNotContainsString('onload=', $sanitized);
            $this->assertStringNotContainsString('onclick=', $sanitized);
            $this->assertStringNotContainsString('https://evil.test', $sanitized);
            $this->assertStringNotContainsString('javascript:', $sanitized);
            $this->assertStringContainsString('<circle', $sanitized);
            $this->assertStringContainsString('style="fill:#0f0"', $sanitized);
            $this->assertStringContainsString('xlink:href="#shape"', $sanitized);
        } finally {
            @unlink($file->getRealPath());
        }
    }

    public function testSvgWithDoctypeIsRejected()
    {
        $svg = <<<'SVG'
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
<svg xmlns="http://www.w3.org/2000/svg">
  <circle cx="10" cy="10" r="5" />
</svg>
SVG;

        $file = $this->makeUploadedSvg($svg);

        try {
            $this->expectException(ValidationException::class);

            $this->invokeSvgSanitizer($file);
        } finally {
            @unlink($file->getRealPath());
        }
    }

    protected function invokeSvgSanitizer(\Symfony\Component\HttpFoundation\File\UploadedFile $file)
    {
        $field = new Image('image1');
        $method = new ReflectionMethod($field, 'sanitizeSvgUpload');
        $method->setAccessible(true);
        $method->invoke($field, $file);
    }

    protected function makeUploadedSvg($contents, $name = 'payload.svg')
    {
        $path = tempnam(sys_get_temp_dir(), 'svg');

        file_put_contents($path, $contents);

        return new \Illuminate\Http\UploadedFile($path, $name, 'image/svg+xml', null, true);
    }
}

<?php

namespace Encore\Admin\Form\Field;

use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Intervention\Image\Constraint;
use Intervention\Image\Facades\Image as InterventionImage;
use Intervention\Image\ImageManagerStatic;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait ImageField
{
    /**
     * Intervention calls.
     *
     * @var array
     */
    protected $interventionCalls = [];

    /**
     * Thumbnail settings.
     *
     * @var array
     */
    protected $thumbnails = [];

    /**
     * Allowed image extensions for persisted image uploads.
     *
     * @var array
     */
    protected $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'];

    /**
     * SVG elements that can execute scripts or load active content.
     *
     * @var array
     */
    protected $svgDangerousElements = [
        'audio',
        'canvas',
        'discard',
        'embed',
        'foreignobject',
        'frame',
        'frameset',
        'handler',
        'iframe',
        'object',
        'script',
        'video',
    ];

    /**
     * Default directory for file to upload.
     *
     * @return mixed
     */
    public function defaultDirectory()
    {
        return config('admin.upload.directory.image');
    }

    /**
     * Execute Intervention calls.
     *
     * @param string $target
     *
     * @return mixed
     */
    public function callInterventionMethods($target)
    {
        if (!empty($this->interventionCalls)) {
            $image = ImageManagerStatic::make($target);

            foreach ($this->interventionCalls as $call) {
                call_user_func_array(
                    [$image, $call['method']],
                    $call['arguments']
                )->save($target);
            }
        }

        return $target;
    }

    /**
     * Call intervention methods.
     *
     * @param string $method
     * @param array  $arguments
     *
     * @throws \Exception
     *
     * @return $this
     */
    public function __call($method, $arguments)
    {
        if (static::hasMacro($method)) {
            return $this;
        }

        if (!class_exists(ImageManagerStatic::class)) {
            throw new \Exception('To use image handling and manipulation, please install [intervention/image] first.');
        }

        $this->interventionCalls[] = [
            'method'    => $method,
            'arguments' => $arguments,
        ];

        return $this;
    }

    /**
     * Render a image form field.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function render()
    {
        $this->options(['allowedFileTypes' => ['image'], 'msgPlaceholder' => trans('admin.choose_image')]);

        return parent::render();
    }

    /**
     * Force image uploads to use a server-trusted image extension and a sanitized basename.
     *
     * @param UploadedFile $file
     *
     * @return string
     */
    protected function getStoreName(UploadedFile $file)
    {
        return $this->normalizeImageStoreName($file, parent::getStoreName($file));
    }

    /**
     * Generate a unique image name with a server-trusted extension.
     *
     * @param UploadedFile $file
     *
     * @return string
     */
    protected function generateUniqueName(UploadedFile $file)
    {
        return md5(uniqid()).'.'.$this->guessImageExtension($file);
    }

    /**
     * Generate a sequence image name with a server-trusted extension.
     *
     * @param UploadedFile $file
     *
     * @return string
     */
    protected function generateSequenceName(UploadedFile $file)
    {
        $index = 1;
        $extension = $this->guessImageExtension($file);
        $original = $this->normalizeImageName($file->getClientOriginalName());
        $new = sprintf('%s_%s.%s', $original, $index, $extension);

        while ($this->storage->exists("{$this->getDirectory()}/$new")) {
            $index++;
            $new = sprintf('%s_%s.%s', $original, $index, $extension);
        }

        return $new;
    }

    /**
     * Normalize the final persisted image name.
     *
     * @param UploadedFile $file
     * @param string       $name
     *
     * @return string
     */
    protected function normalizeImageStoreName(UploadedFile $file, $name)
    {
        $extension = $this->guessImageExtension($file);
        $filename = $this->normalizeImageName($name ?: $file->getClientOriginalName());

        return sprintf('%s.%s', $filename, $extension);
    }

    /**
     * Collapse dangerous basename characters and strip chained extensions.
     *
     * @param string $name
     *
     * @return string
     */
    protected function normalizeImageName($name)
    {
        $name = trim((string) $name);
        $name = pathinfo($name, PATHINFO_FILENAME);
        $name = str_replace(['\\', '/', '.'], '_', $name);
        $name = preg_replace('/[^\pL\pN_-]+/u', '_', $name) ?: '';
        $name = trim($name, '_-');

        if ($name === '') {
            return md5(uniqid());
        }

        return $name;
    }

    /**
     * Resolve the image extension from server-trusted metadata.
     *
     * @param UploadedFile $file
     *
     * @throws \UnexpectedValueException
     *
     * @return string
     */
    protected function guessImageExtension(UploadedFile $file)
    {
        $extension = strtolower((string) $file->guessExtension());

        if (in_array($extension, $this->imageExtensions, true)) {
            return $extension;
        }

        $mimeMap = [
            'image/bmp'      => 'bmp',
            'image/gif'      => 'gif',
            'image/jpeg'     => 'jpeg',
            'image/pjpeg'    => 'jpeg',
            'image/png'      => 'png',
            'image/svg+xml'  => 'svg',
            'image/webp'     => 'webp',
            'image/x-ms-bmp' => 'bmp',
        ];

        $mimeType = strtolower((string) $file->getMimeType());

        if (array_key_exists($mimeType, $mimeMap)) {
            return $mimeMap[$mimeType];
        }

        $clientExtension = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($clientExtension, $this->imageExtensions, true)) {
            return $clientExtension;
        }

        throw new \UnexpectedValueException('The uploaded file is not a supported image.');
    }

    /**
     * Sanitize active content from SVG uploads.
     *
     * @param UploadedFile $file
     *
     * @return void
     */
    protected function sanitizeImageUpload(UploadedFile $file)
    {
        if ($this->guessImageExtension($file) !== 'svg') {
            return;
        }

        $this->sanitizeSvgUpload($file);
    }

    /**
     * Sanitize an uploaded SVG file in place.
     *
     * @param UploadedFile $file
     *
     * @return void
     */
    protected function sanitizeSvgUpload(UploadedFile $file)
    {
        $path = $file->getRealPath();
        $contents = @file_get_contents($path);

        if ($contents === false) {
            $this->throwInvalidSvgUpload();
        }

        $sanitized = $this->sanitizeSvgContents($contents);

        if (@file_put_contents($path, $sanitized) === false) {
            $this->throwInvalidSvgUpload();
        }
    }

    /**
     * Remove scriptable or external-resource constructs from SVG markup.
     *
     * @param string $contents
     *
     * @return string
     */
    protected function sanitizeSvgContents($contents)
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet/i', $contents)) {
            $this->throwInvalidSvgUpload();
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadXML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if (!$loaded || !$dom->documentElement || strtolower($dom->documentElement->localName) !== 'svg') {
            $this->throwInvalidSvgUpload();
        }

        $this->sanitizeSvgAttributes($dom->documentElement);
        $this->sanitizeSvgNode($dom->documentElement);

        $sanitized = $dom->saveXML($dom->documentElement);

        if ($sanitized === false || $sanitized === '') {
            $this->throwInvalidSvgUpload();
        }

        return $sanitized;
    }

    /**
     * Recursively sanitize an SVG node tree.
     *
     * @param \DOMNode $node
     *
     * @return void
     */
    protected function sanitizeSvgNode(\DOMNode $node)
    {
        for ($child = $node->firstChild; $child; $child = $next) {
            $next = $child->nextSibling;

            if ($child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);

                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $localName = strtolower($child->localName ?: $child->nodeName);

            if (in_array($localName, $this->svgDangerousElements, true)) {
                $node->removeChild($child);

                continue;
            }

            if ($localName === 'style') {
                if (!$this->sanitizeSvgStyleElement($child)) {
                    $node->removeChild($child);
                }

                continue;
            }

            $this->sanitizeSvgAttributes($child);
            $this->sanitizeSvgNode($child);
        }
    }

    /**
     * Sanitize attributes on an SVG element.
     *
     * @param \DOMElement $element
     *
     * @return void
     */
    protected function sanitizeSvgAttributes(\DOMElement $element)
    {
        if (!$element->hasAttributes()) {
            return;
        }

        for ($index = $element->attributes->length - 1; $index >= 0; $index--) {
            $attribute = $element->attributes->item($index);

            if (!$attribute) {
                continue;
            }

            $name = strtolower($attribute->nodeName);
            $localName = strtolower($attribute->localName ?: $attribute->nodeName);
            $value = $attribute->nodeValue;

            if (preg_match('/^xmlns(?::|$)/', $name)) {
                continue;
            }

            if (strpos($localName, 'on') === 0 || $localName === 'externalresourcesrequired') {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if (in_array($name, ['href', 'xlink:href', 'src'], true) || in_array($localName, ['href', 'src'], true)) {
                $sanitized = $this->sanitizeSvgReferenceValue(
                    $value,
                    strtolower($element->localName ?: $element->nodeName) === 'image'
                );

                if ($sanitized === null) {
                    $element->removeAttributeNode($attribute);
                } else {
                    $attribute->nodeValue = $sanitized;
                }

                continue;
            }

            if ($localName === 'style') {
                $sanitized = $this->sanitizeSvgStyleValue($value);

                if ($sanitized === null) {
                    $element->removeAttributeNode($attribute);
                } else {
                    $attribute->nodeValue = $sanitized;
                }

                continue;
            }

            if ($this->containsUnsafeSvgProtocol($value)) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if (stripos($value, 'url(') !== false) {
                $sanitized = $this->sanitizeSvgStyleValue($value);

                if ($sanitized === null) {
                    $element->removeAttributeNode($attribute);
                } else {
                    $attribute->nodeValue = $sanitized;
                }
            }
        }
    }

    /**
     * Sanitize the contents of a <style> element.
     *
     * @param \DOMElement $element
     *
     * @return bool
     */
    protected function sanitizeSvgStyleElement(\DOMElement $element)
    {
        $css = '';

        foreach ($element->childNodes as $child) {
            $css .= $child->nodeValue;
        }

        $sanitized = $this->sanitizeSvgStyleValue($css);

        if ($sanitized === null) {
            return false;
        }

        while ($element->firstChild) {
            $element->removeChild($element->firstChild);
        }

        $element->appendChild($element->ownerDocument->createTextNode($sanitized));

        return true;
    }

    /**
     * Sanitize SVG inline CSS or style declarations.
     *
     * @param string $style
     *
     * @return string|null
     */
    protected function sanitizeSvgStyleValue($style)
    {
        $style = trim(preg_replace('/\/\*.*?\*\//s', '', (string) $style));

        if ($style === '') {
            return null;
        }

        if (preg_match('/(?:@import|expression\s*\(|behavior\s*:|-moz-binding|javascript\s*:)/i', $style)) {
            return null;
        }

        $invalid = false;
        $style = preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', function ($matches) use (&$invalid) {
            $reference = $this->sanitizeSvgReferenceValue($matches[2], false);

            if ($reference === null) {
                $invalid = true;

                return '';
            }

            return 'url('.$reference.')';
        }, $style);

        if ($invalid || $style === null) {
            return null;
        }

        return trim($style) ?: null;
    }

    /**
     * Sanitize an SVG reference attribute value.
     *
     * @param string $value
     * @param bool   $allowDataImage
     *
     * @return string|null
     */
    protected function sanitizeSvgReferenceValue($value, $allowDataImage = false)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $normalized = $this->normalizeSvgReferenceValue($value);

        if (strpos($normalized, '#') === 0) {
            return $value;
        }

        if ($allowDataImage && preg_match('#^data:image/(?:bmp|gif|jpe?g|png|webp);base64,[a-z0-9+/=\r\n]+$#i', $normalized)) {
            return $value;
        }

        return null;
    }

    /**
     * Detect dangerous protocols hidden in SVG attribute values.
     *
     * @param string $value
     *
     * @return bool
     */
    protected function containsUnsafeSvgProtocol($value)
    {
        $normalized = $this->normalizeSvgReferenceValue($value);

        return preg_match('/^(?:javascript|vbscript|file|ftp|http|https|data):/i', $normalized) === 1;
    }

    /**
     * Normalize an SVG reference before protocol checks.
     *
     * @param string $value
     *
     * @return string
     */
    protected function normalizeSvgReferenceValue($value)
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/[\x00-\x20]+/u', '', $value);

        return strtolower(trim($value));
    }

    /**
     * Throw a validation exception for unsafe SVG uploads.
     *
     * @return void
     */
    protected function throwInvalidSvgUpload()
    {
        throw ValidationException::withMessages([
            $this->column => ['The uploaded SVG contains unsupported or unsafe content.'],
        ]);
    }

    /**
     * @param string|array $name
     * @param int          $width
     * @param int          $height
     *
     * @return $this
     */
    public function thumbnail($name, int $width = null, int $height = null)
    {
        if (func_num_args() == 1 && is_array($name)) {
            foreach ($name as $key => $size) {
                if (count($size) >= 2) {
                    $this->thumbnails[$key] = $size;
                }
            }
        } elseif (func_num_args() == 3) {
            $this->thumbnails[$name] = [$width, $height];
        }

        return $this;
    }

    /**
     * Destroy original thumbnail files.
     *
     * @return void.
     */
    public function destroyThumbnail()
    {
        if ($this->retainable) {
            return;
        }

        foreach ($this->thumbnails as $name => $_) {
            /*  Refactoring actual remove lofic to another method destroyThumbnailFile()
            to make deleting thumbnails work with multiple as well as
            single image upload. */

            if (is_array($this->original)) {
                if (empty($this->original)) {
                    continue;
                }

                foreach ($this->original as $original) {
                    $this->destroyThumbnailFile($original, $name);
                }
            } else {
                $this->destroyThumbnailFile($this->original, $name);
            }
        }
    }

    /**
     * Remove thumbnail file from disk.
     *
     * @return void.
     */
    public function destroyThumbnailFile($original, $name)
    {
        $ext = @pathinfo($original, PATHINFO_EXTENSION);

        // We remove extension from file name so we can append thumbnail type
        $path = @Str::replaceLast('.'.$ext, '', $original);

        // We merge original name + thumbnail name + extension
        $path = $path.'-'.$name.'.'.$ext;

        if ($this->storage->exists($path)) {
            $this->storage->delete($path);
        }
    }

    /**
     * Upload file and delete original thumbnail files.
     *
     * @param UploadedFile $file
     *
     * @return $this
     */
    protected function uploadAndDeleteOriginalThumbnail(UploadedFile $file)
    {
        foreach ($this->thumbnails as $name => $size) {
            // We need to get extension type ( .jpeg , .png ...)
            $ext = pathinfo($this->name, PATHINFO_EXTENSION);

            // We remove extension from file name so we can append thumbnail type
            $path = Str::replaceLast('.'.$ext, '', $this->name);

            // We merge original name + thumbnail name + extension
            $path = $path.'-'.$name.'.'.$ext;

            /** @var \Intervention\Image\Image $image */
            $image = InterventionImage::make($file);

            $action = $size[2] ?? 'resize';
            // Resize image with aspect ratio
            $image->$action($size[0], $size[1], function (Constraint $constraint) {
                $constraint->aspectRatio();
            })->resizeCanvas($size[0], $size[1], 'center', false, '#ffffff');

            if (!is_null($this->storagePermission)) {
                $this->storage->put("{$this->getDirectory()}/{$path}", $image->encode(), $this->storagePermission);
            } else {
                $this->storage->put("{$this->getDirectory()}/{$path}", $image->encode());
            }
        }

        $this->destroyThumbnail();

        return $this;
    }
}

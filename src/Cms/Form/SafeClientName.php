<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * An upload whose client file name is valid UTF-8 (#25). `File` and `Image` pass the name to every
 * violation as `{{ name }}`, and the ICU formatter throws on invalid UTF-8 in any parameter, used or not,
 * so a crafted file name would turn a 422 into a 500. Validate the copy this returns.
 */
final class SafeClientName
{
    public static function of(UploadedFile $file): UploadedFile
    {
        $name = $file->getClientOriginalName();
        $safe = str_replace("\0", '', mb_scrub($name, 'UTF-8'));
        if ($safe === $name) {
            return $file;
        }

        // UploadedFile keeps its test flag private: a valid upload that PHP did not receive is a test one.
        $test = \UPLOAD_ERR_OK === $file->getError() && !is_uploaded_file($file->getPathname());

        return new UploadedFile($file->getPathname(), $safe, $file->getClientMimeType(), $file->getError(), $test);
    }
}

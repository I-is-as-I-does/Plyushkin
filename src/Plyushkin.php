<?php

namespace SSITU\Plyushkin;

use \SSITU\Blueprints;
use \SSITU\Jack;

class Plyushkin implements Blueprints\FlexLogsInterface

{

    use Blueprints\FlexLogsTrait;

    protected $whitelist = [
        "jpg" => "image/jpeg",
        "jpeg" => "image/jpeg",
        "gif" => "image/gif",
        "png" => "image/png",
        "webm" => "video/webm",
        "webp" => "image/webp",
        "mp4" => "video/mp4",
        "mp3" => "audio/mp3",
        "pdf" => "application/pdf",
        "svg" => "image/svg+xml",
        "ico" => "image/x-icon",
        "xml" => "application/xml",
        "json" => "application/json",
        "html" => "text/html",
        "css" => "text/css",
        "js" => "application/javascript"
    ];

    private $confirmedMimes = [];
    private $deductedMimes = [];

    public function __construct(array $whitelist = [])
    {
        if (!empty($whitelist)) {
            $this->whitelist = $whitelist;
        }
       
    }

    public function outputFileCache(string $cachePath)
    {
        $start = $this->startOutput($this->confirmedMime($cachePath));
        if ($start) {
            readfile($cachePath);
            return true;
        }
        return false;
    }

    public function saveCache(string $buffer, string $cachePath)
    {
        $save = false;
        if ($this->canSave($buffer, $cachePath)) {
            $save = Jack\File::write($buffer, $cachePath);
            if (empty($save)) {
                $this->log('error', 'cache-save-fail', ['path' => $cachePath]);
            }
        }

        return $save;
    }

    public function saveCacheAndOutput(string $buffer, string $cachePath)
    {
        $save = $this->saveCache($buffer, $cachePath);
        if ($save || !empty($buffer)) {
            $start = $this->startOutput($this->deductedMime($cachePath));
            if ($start) {
                echo $buffer;
                return true;
            }
        }
        return false;
    }

    public function cleanCacheDir(string $cacheDirPath, array $targetExts = [])
    {
        if (!is_dir($cacheDirPath)) {
            $this->log('error', 'invalid-cache-dir', ['path' => $cacheDirPath]);
            $fail = 1;
        } else {
            $globPattern = Jack\File::reqTrailingSlash($cacheDirPath) . "?*";
            $flag = 0;
            if (!empty($targetExts)) {
                $globPattern .= '.{' . implode(',', $targetExts) . '}';
                $flag = GLOB_BRACE;
            }
            $rslt = Jack\File::patternDelete($globPattern, $flag);
            if (empty($rslt)) {
                $this->log('notice', 'no-files-to-delete', ['path' => $cacheDirPath]);
                $fail = 0;
            } else {
                $fail = Jack\Array::countEmptyItms($rslt);
                if ($fail > 0) {
                    $this->log('error', 'clean-cache-dir-fail', ['fail-count' => $fail . '|' . count($rslt)]);
                }
            }
        }

        return $fail === 0;
    }

    public function deleteCacheFile(string $cachePath)
    {
        $delete = true;
        if (file_exists($cachePath)) {
            $delete = @unlink($cachePath);
            if (!$delete) {
                $this->log('error', 'delete-fail', ['path' => $cachePath]);
            }
        }

        return $delete;
    }

    private function startOutput(string $mime)
    {

        if (!empty($mime)) {
            header('Content-Type: ' . $mime);
            /* @doc: on most modern webhosts, files are gzip; thus, setting Content-Length would be inaccurate, with severe lag or failure as a result.
            If reactivating Content-Length is required: instruct server to not gzip this file. */
            # $size = filesize($cachePath);
            # header('Content-Length: ' . $size);
            header('Content-Disposition: inline');
            ob_clean();
            flush();
            return true;
        }
        return false;
    }

    private function confirmedMime(string $cachePath)
    {
        if (!array_key_exists($cachePath, $this->mimeMap)) {
            $this->confirmedMimes[$cachePath] = $this->processConfirmedMime($cachePath);
        }
        return $this->confirmedMimes[$cachePath];
    }

    private function processConfirmedMime(string $cachePath)
    {
        if (!file_exists($cachePath)) {
            $this->log('warning', 'cache-file-not-found', ['path' => $cachePath]);
            return false;
        }
        $mime = mime_content_type($cachePath);
        if (!$this->isSupportedMime($mime) && !$this->isSupportedExt($this->ext($cachePath))) {
            $this->log('warning', 'mime-not-supported', ['mime' => $mime]);
            return false;
        }
        return $mime;

    }

    private function ext(string $cachePath)
    {
        return Jack\File::getExt($cachePath);
    }

    private function deductedMime(string $cachePath)
    {
        if (!array_key_exists($cachePath, $this->deductedMimes)) {
            $this->deductedMimes[$cachePath] = $this->processDeductedMime($cachePath);
        }
        return $this->deductedMimes[$cachePath];
    }

    private function processDeductedMime(string $cachePath)
    {
        $ext = $this->ext($cachePath);
        if (!$this->isSupportedExt($ext)) {
            $this->log('warning', 'ext-not-supported', ['ext' => $ext]);
            return false;
        }
        return $this->defaultWhitelist[$ext];
    }

    private function isSupportedMime(string $mime)
    {
        return in_array($mime, $this->defaultWhitelist);
    }

    private function isSupportedExt(string $ext)
    {
        return array_key_exists($ext, $this->defaultWhitelist);
    }

    private function canSave(string $buffer, string $cachePath)
    {
        if (empty($buffer)) {
            $this->log('error', 'empty-content', ['path' => $cachePath]);
            return false;
        }
        if (!is_dir(dirname($cachePath)) && !mkdir(dirname($cachePath), 0777, true)) {
            $this->log('error', 'invalid-path', ['path' => $cachePath]);
            return false;
        }
        return !empty($this->deductedMime($cachePath));
    }
}

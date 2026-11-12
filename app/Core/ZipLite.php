<?php

namespace Fava\Core;

/**
 * Minimal read-only ZIP reader.
 *
 * Uses PHP's ZipArchive when the zip extension is available and falls back to a small
 * pure-PHP reader (central directory + gzinflate) when it is not, so that reading .docx
 * files only needs zlib – which every PHP build on shared hosting has.
 */
class ZipLite
{
    /** Force the pure-PHP reader (used by the test-suite). */
    public static $forcePurePhp = false;

    /** @var \ZipArchive|null */
    private $zip = null;
    /** @var resource|null */
    private $fh = null;
    /** @var array name => [method, compressedSize, size, localHeaderOffset, flags] */
    private $entries = [];

    public function __construct(string $path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('فایل قابل خواندن نیست.');
        }
        if (!self::$forcePurePhp && class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new \RuntimeException('فایل یک بسته ZIP/DOCX معتبر نیست.');
            }
            $this->zip = $zip;
            return;
        }
        $this->openPure($path);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->zip) {
            @$this->zip->close();
            $this->zip = null;
        }
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
        $this->fh = null;
    }

    public function has(string $name): bool
    {
        if ($this->zip) {
            return $this->zip->locateName($name) !== false;
        }
        return isset($this->entries[$name]);
    }

    /** Uncompressed size in bytes, or -1 when the entry does not exist. */
    public function size(string $name): int
    {
        if ($this->zip) {
            $st = $this->zip->statName($name);
            return $st === false ? -1 : (int) $st['size'];
        }
        return isset($this->entries[$name]) ? (int) $this->entries[$name][2] : -1;
    }

    /** @return string[] */
    public function names(): array
    {
        if ($this->zip) {
            $names = [];
            for ($i = 0; $i < $this->zip->numFiles; $i++) {
                $n = $this->zip->getNameIndex($i);
                if ($n !== false) {
                    $names[] = $n;
                }
            }
            return $names;
        }
        return array_keys($this->entries);
    }

    /** Read an entry; refuses anything larger than $maxBytes (zip-bomb guard). */
    public function read(string $name, int $maxBytes = 41943040): ?string
    {
        $size = $this->size($name);
        if ($size < 0) {
            return null;
        }
        if ($size > $maxBytes) {
            throw new \RuntimeException('بخشی از فایل بیش از حد مجاز بزرگ است.');
        }
        if ($this->zip) {
            $data = $this->zip->getFromName($name, $maxBytes + 1);
            if ($data === false) {
                return null;
            }
            if (strlen($data) > $maxBytes) {
                throw new \RuntimeException('بخشی از فایل بیش از حد مجاز بزرگ است.');
            }
            return $data;
        }
        return $this->readPure($name, $maxBytes);
    }

    // ---- pure PHP implementation -------------------------------------------

    private function openPure(string $path): void
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new \RuntimeException('فایل قابل خواندن نیست.');
        }
        $this->fh = $fh;
        $fileSize = filesize($path);
        $tailLen = (int) min($fileSize, 65557);
        fseek($fh, -$tailLen, SEEK_END);
        $tail = (string) fread($fh, $tailLen);
        $pos = strrpos($tail, "PK\x05\x06");
        if ($pos === false || strlen($tail) - $pos < 22) {
            throw new \RuntimeException('فایل یک بسته ZIP/DOCX معتبر نیست.');
        }
        $eocd = unpack('vdisk/vcdDisk/vcountDisk/vcount/VcdSize/VcdOffset', substr($tail, $pos + 4, 16));
        if ($eocd['cdOffset'] === 0xFFFFFFFF || $eocd['count'] === 0xFFFF) {
            throw new \RuntimeException('این قالب ZIP (ZIP64) پشتیبانی نمی‌شود.');
        }
        if ($eocd['cdSize'] > 16777216 || $eocd['cdOffset'] + $eocd['cdSize'] > $fileSize) {
            throw new \RuntimeException('فهرست بسته ZIP نامعتبر است.');
        }
        fseek($fh, $eocd['cdOffset']);
        $cd = $eocd['cdSize'] > 0 ? (string) fread($fh, $eocd['cdSize']) : '';
        $off = 0;
        $len = strlen($cd);
        for ($i = 0; $i < $eocd['count'] && $off + 46 <= $len; $i++) {
            if (substr($cd, $off, 4) !== "PK\x01\x02") {
                break;
            }
            $h = unpack(
                'vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/VcSize/Vsize/vnameLen/vextraLen/vcommentLen/vdiskNo/vintAttr/VextAttr/VlocalOffset',
                substr($cd, $off + 4, 42)
            );
            $name = substr($cd, $off + 46, $h['nameLen']);
            $this->entries[$name] = [$h['method'], $h['cSize'], $h['size'], $h['localOffset'], $h['flags']];
            $off += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
        }
        if (!$this->entries) {
            throw new \RuntimeException('فایل یک بسته ZIP/DOCX معتبر نیست.');
        }
    }

    private function readPure(string $name, int $maxBytes): ?string
    {
        list($method, $cSize, $size, $offset, $flags) = $this->entries[$name];
        if ($flags & 1) {
            throw new \RuntimeException('فایل رمزگذاری‌شده پشتیبانی نمی‌شود.');
        }
        if ($cSize === 0xFFFFFFFF || $size === 0xFFFFFFFF) {
            throw new \RuntimeException('این قالب ZIP (ZIP64) پشتیبانی نمی‌شود.');
        }
        fseek($this->fh, $offset);
        $local = (string) fread($this->fh, 30);
        if (strlen($local) < 30 || substr($local, 0, 4) !== "PK\x03\x04") {
            throw new \RuntimeException('ساختار بسته ZIP نامعتبر است.');
        }
        $lh = unpack('vnameLen/vextraLen', substr($local, 26, 4));
        fseek($this->fh, $offset + 30 + $lh['nameLen'] + $lh['extraLen']);
        $data = $cSize > 0 ? (string) fread($this->fh, $cSize) : '';
        if ($method === 0) {
            return $data;
        }
        if ($method !== 8) {
            throw new \RuntimeException('روش فشرده‌سازی این فایل پشتیبانی نمی‌شود.');
        }
        $out = @gzinflate($data, $maxBytes + 1);
        if ($out === false) {
            throw new \RuntimeException('بازکردن بخشی از فایل ناموفق بود.');
        }
        if (strlen($out) > $maxBytes) {
            throw new \RuntimeException('بخشی از فایل بیش از حد مجاز بزرگ است.');
        }
        return $out;
    }
}

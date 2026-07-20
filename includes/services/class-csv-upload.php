<?php
declare(strict_types=1);

namespace TMWSEO\Engine\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * CsvUpload — server-side validation of CSV file uploads.
 *
 * Replaces the four hand-rolled "is the filename ending in .csv?" checks
 * across the engine + autopilot. The file extension is whatever the
 * visitor types — an admin (or anyone who phishes the admin's nonce) can
 * upload evil.php and name it evil.csv. The PHP extension is rejected
 * by WordPress's general upload filters, but variants like evil.php.csv
 * and evil.html (which is not "obvious" PHP) sail through an extension
 * check unscathed. wp_check_filetype_and_ext sniffs the actual bytes via
 * finfo (PHP's libmagic binding) so the on-disk content has to plausibly
 * match the claimed type.
 *
 * Reference implementation copied: class-model-opportunity-admin-page.php
 * (which already used the right pattern before this consolidation).
 *
 * Returns:
 *   ['ok' => true, 'name' => 'safe-filename.csv', 'tmp' => '/tmp/abc123']
 *     on success — `name` is sanitized + finfo-corrected; use it for storage.
 *   ['ok' => false, 'error' => 'no_file' | 'upload_error' | 'not_uploaded' | 'too_large' | 'bad_filetype']
 *     on rejection — caller decides whether to wp_die / redirect / log.
 */
class CsvUpload {

    /**
     * Default 8 MB ceiling on accepted CSV size. The previous default of
     * 64 MB allowed a "5 admins × 64 MB simultaneous upload" memory-
     * exhaustion path that the audit specifically flagged. 8 MB still
     * covers the realistic ceiling for the keyword / seed / opportunity
     * CSV shapes the engine consumes (a 50k-row keyword export is ~3 MB).
     *
     * Callers SHOULD pass an explicit per-importer cap rather than
     * relying on this default — that way the cap is documented at the
     * upload site instead of hidden behind a constant in this file.
     * Pass 0 to disable the size check entirely (not recommended).
     */
    public const DEFAULT_MAX_BYTES = 8 * 1024 * 1024;

    /**
     * Validate one entry from $_FILES.
     *
     * @param array<string,mixed>|null $file       Raw $_FILES['key'] array.
     * @param int                      $max_bytes  Per-call size ceiling
     *                                             (0 disables the check).
     * @return array{ok:bool,name?:string,tmp?:string,error?:string,bytes?:int}
     */
    public static function validate($file, int $max_bytes = self::DEFAULT_MAX_BYTES): array {
        if (!is_array($file) || empty($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'no_file'];
        }

        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'upload_error', 'bytes' => 0];
        }

        $tmp = (string) $file['tmp_name'];

        // Defence vs. forged $_FILES — only accept tmp paths PHP itself
        // marked as having come from this POST.
        if (!is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'not_uploaded'];
        }

        $size = (int) ($file['size'] ?? @filesize($tmp));
        if ($max_bytes > 0 && $size > $max_bytes) {
            return ['ok' => false, 'error' => 'too_large', 'bytes' => $size];
        }

        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'bad_filetype'];
        }

        // Content-sniff via WordPress's wp_check_filetype_and_ext, which
        // delegates to finfo when available. The third arg whitelists the
        // ONE extension/mime we expect; if the sniffed type doesn't match,
        // 'ext' comes back empty.
        //
        // We accept three text/* sniffed types because libmagic's
        // identification of a 7-bit-ASCII comma-separated file is
        // inconsistent across distros — sometimes text/csv, sometimes
        // text/plain, sometimes application/csv. The extension lock
        // (.csv on disk) plus the sniffed-as-text family is the gate.
        $allowed_mimes = [
            'csv' => 'text/csv',
        ];
        $check = wp_check_filetype_and_ext($tmp, $name, $allowed_mimes);

        $sniffed_ext = (string) ($check['ext'] ?? '');
        if ($sniffed_ext !== 'csv') {
            return ['ok' => false, 'error' => 'bad_filetype', 'bytes' => $size];
        }

        // If wp_check_filetype_and_ext rewrote the filename (e.g. stripped
        // an inner ".php" to neutralise evil.php.csv), use the rewritten
        // name when persisting.
        if (!empty($check['proper_filename'])) {
            $name = (string) $check['proper_filename'];
        }

        return [
            'ok'    => true,
            'name'  => $name,
            'tmp'   => $tmp,
            'bytes' => $size,
        ];
    }
}

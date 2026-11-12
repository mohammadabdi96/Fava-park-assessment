<?php

namespace Fava\Domain;

use Fava\Core\DB;
use Fava\Core\Fa;
use Fava\Core\Jalali;
use Fava\Docx\DocxException;
use Fava\Docx\DocxReader;
use Fava\Docx\FieldExtractor;

class Proposals
{
    /** Columns that are safe to load for lists (everything except the large JSON blobs). */
    private const LIST_COLS = 'id, code, original_name, file_size, title, company, requested_amount, extract_status, status, '
        . 'reviewed_at, due_at, total_score, system_decision, result_tier, result_amount, needs_resolution, computed_at, '
        . 'final_decision, final_amount, finalized_at, created_at, updated_at';

    public static function uploadDir(): string
    {
        return STORAGE_DIR . '/uploads';
    }

    public static function filePath(array $proposal): string
    {
        return self::uploadDir() . '/' . $proposal['stored_name'];
    }

    public static function find(int $id): ?array
    {
        return DB::get()->one('SELECT * FROM {proposals} WHERE id = ?', [$id]);
    }

    public static function findByHash(string $hash): ?array
    {
        return DB::get()->one('SELECT id, code, title, company FROM {proposals} WHERE file_hash = ? ORDER BY id DESC LIMIT 1', [$hash]);
    }

    public static function fields(array $proposal): array
    {
        return json_in($proposal['fields_json'] ?? '', []);
    }

    public static function warnings(array $proposal): array
    {
        return json_in($proposal['warnings_json'] ?? '', []);
    }

    /** Parsed document (for the on-screen view of the worksheet). */
    public static function doc(int $id): array
    {
        $json = DB::get()->val('SELECT doc_json FROM {proposal_docs} WHERE proposal_id = ?', [$id]);
        return json_in((string) $json, ['blocks' => [], 'meta' => []]);
    }

    /** Scoring parameters frozen for this proposal, or the current ones while nothing has been frozen. */
    public static function config(array $proposal): array
    {
        $saved = json_in($proposal['config_json'] ?? '', null);
        $base = FormDef::get()['defaults'];
        return is_array($saved) ? FormDef::mergeConfig($base, $saved) : FormDef::currentConfig();
    }

    /** Freeze the current scoring parameters for a proposal (done when the first judge link is created). */
    public static function freezeConfig(int $id, bool $force = false): void
    {
        $p = self::find($id);
        if (!$p || (!$force && !empty($p['config_json']))) {
            return;
        }
        DB::get()->update('proposals', [
            'config_json' => json_encode(FormDef::currentConfig(), JSON_UNESCAPED_UNICODE),
            'config_at' => now_utc(),
        ], 'id = ?', [$id]);
    }

    /** P1405-007 style tracking code: Jalali year + running number within that year. */
    public static function nextCode(): string
    {
        $prefix = 'P' . Jalali::format('Y') . '-';
        $last = DB::get()->val('SELECT code FROM {proposals} WHERE code LIKE ? ORDER BY id DESC LIMIT 1', [$prefix . '%']);
        $n = 0;
        if (is_string($last) && preg_match('/-(\d+)$/', $last, $m)) {
            $n = (int) $m[1];
        }
        do {
            $n++;
            $code = $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        } while (DB::get()->val('SELECT COUNT(*) FROM {proposals} WHERE code = ?', [$code]));
        return $code;
    }

    private static function searchText(string $code, string $title, string $company, string $original): string
    {
        return mb_substr(Fa::search($code . ' ' . $title . ' ' . $company . ' ' . $original), 0, 2000);
    }

    /**
     * Store an uploaded .docx, read it and create the proposal.
     *
     * @param string $tmpPath      uploaded temp file
     * @param string $originalName file name as sent by the browser
     * @return array{id:int, duplicate:?array, status:string, warnings:array}
     * @throws DocxException when the file is not a usable Word document
     */
    public static function createFromUpload(string $tmpPath, string $originalName, ?int $adminId, bool $isUploadedFile = true): array
    {
        $originalName = self::cleanName($originalName);
        if (!preg_match('/\.docx$/i', $originalName)) {
            if (preg_match('/\.doc$/i', $originalName)) {
                throw new DocxException('قالب قدیمی Word (‎.doc) پشتیبانی نمی‌شود. فایل را در Word با «Save As» به قالب ‎.docx ذخیره و دوباره بارگذاری کنید.');
            }
            throw new DocxException('فقط فایل Word با پسوند ‎.docx پذیرفته می‌شود.');
        }
        $size = (int) @filesize($tmpPath);
        if ($size <= 0) {
            throw new DocxException('فایل خالی است یا به‌درستی بارگذاری نشده است.');
        }
        $hash = (string) hash_file('sha256', $tmpPath);
        $duplicate = self::findByHash($hash);
        if ($duplicate) {
            return ['id' => (int) $duplicate['id'], 'duplicate' => $duplicate, 'status' => 'duplicate', 'warnings' => []];
        }

        // read first: a broken file is rejected before anything is stored
        $doc = DocxReader::read($tmpPath);
        $extract = FieldExtractor::extract($doc, $originalName);

        $dir = self::uploadDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new \RuntimeException('پوشه ذخیره فایل‌ها قابل ایجاد نیست.');
        }
        $stored = gmdate('Ymd') . '-' . bin2hex(random_bytes(12)) . '.docx';
        $target = $dir . '/' . $stored;
        $moved = $isUploadedFile ? @move_uploaded_file($tmpPath, $target) : @copy($tmpPath, $target);
        if (!$moved) {
            throw new \RuntimeException('ذخیره فایل روی سرور ناموفق بود. سطح دسترسی پوشه storage/uploads را بررسی کنید.');
        }
        if (can_call('chmod')) {
            @chmod($target, 0644);
        }

        $f = $extract['fields'];
        $db = DB::get();
        try {
            $id = $db->tx(function (DB $db) use ($f, $extract, $doc, $originalName, $stored, $size, $hash, $adminId) {
                $code = self::nextCode();
                $now = now_utc();
                $id = $db->insert('proposals', [
                    'code' => $code,
                    'original_name' => mb_substr($originalName, 0, 250),
                    'stored_name' => $stored,
                    'file_size' => $size,
                    'file_hash' => $hash,
                    'doc_type' => $extract['doc_type'] !== '' ? mb_substr($extract['doc_type'], 0, 185) : null,
                    'title' => $f['title'],
                    'title_alt' => $f['title_alt'] !== '' ? $f['title_alt'] : null,
                    'company' => mb_substr($f['company'], 0, 250),
                    'expected_output' => $f['expected_output'],
                    'fields_json' => json_encode($f, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    'warnings_json' => json_encode($extract['warnings'], JSON_UNESCAPED_UNICODE),
                    'extract_status' => $extract['status'],
                    'status' => 'review',
                    'share_doc' => Settings::get('judge_show_doc', 1) ? 1 : 0,
                    'share_file' => Settings::get('judge_allow_download', 1) ? 1 : 0,
                    'search_text' => self::searchText($code, $f['title'], $f['company'], $originalName),
                    'created_by' => $adminId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $db->insert('proposal_docs', [
                    'proposal_id' => $id,
                    'doc_json' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                ]);
                return $id;
            });
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }
        return ['id' => (int) $id, 'duplicate' => null, 'status' => $extract['status'], 'warnings' => $extract['warnings']];
    }

    /** Read the stored file again (e.g. after the extractor was improved). Reviewed fields are not overwritten. */
    public static function reextract(int $id): array
    {
        $p = self::find($id);
        if (!$p) {
            throw new \RuntimeException('طرح پیدا نشد.');
        }
        $doc = DocxReader::read(self::filePath($p));
        $extract = FieldExtractor::extract($doc, (string) $p['original_name']);
        $f = $extract['fields'];
        $data = [
            'fields_json' => json_encode($f, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'warnings_json' => json_encode($extract['warnings'], JSON_UNESCAPED_UNICODE),
            'extract_status' => $extract['status'],
            'doc_type' => $extract['doc_type'] !== '' ? mb_substr($extract['doc_type'], 0, 185) : null,
            'title_alt' => $f['title_alt'] !== '' ? $f['title_alt'] : null,
            'updated_at' => now_utc(),
        ];
        if (empty($p['reviewed_at'])) {
            $data['title'] = $f['title'];
            $data['company'] = mb_substr($f['company'], 0, 250);
            $data['expected_output'] = $f['expected_output'];
            $data['search_text'] = self::searchText((string) $p['code'], $f['title'], $f['company'], (string) $p['original_name']);
        }
        $db = DB::get();
        $db->tx(function (DB $db) use ($id, $data, $doc) {
            $db->update('proposals', $data, 'id = ?', [$id]);
            $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($db->val('SELECT COUNT(*) FROM {proposal_docs} WHERE proposal_id = ?', [$id])) {
                $db->update('proposal_docs', ['doc_json' => $json], 'proposal_id = ?', [$id]);
            } else {
                $db->insert('proposal_docs', ['proposal_id' => $id, 'doc_json' => $json]);
            }
        });
        return $extract;
    }

    /** File name from the browser, without any path and without control characters. */
    public static function cleanName(string $name): string
    {
        // basename() is locale dependent on PHP 7 and can swallow leading Persian letters
        $name = (string) preg_replace('#^.*[\\\\/]#u', '', $name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name);
        return $name === '' ? 'file.docx' : mb_substr($name, 0, 250);
    }

    public static function saveDetails(int $id, array $data): void
    {
        $p = self::find($id);
        if (!$p) {
            return;
        }
        $data['updated_at'] = now_utc();
        $title = array_key_exists('title', $data) ? (string) $data['title'] : (string) $p['title'];
        $company = array_key_exists('company', $data) ? (string) $data['company'] : (string) $p['company'];
        $data['search_text'] = self::searchText((string) $p['code'], $title, $company, (string) $p['original_name']);
        DB::get()->update('proposals', $data, 'id = ?', [$id]);
    }

    public static function touch(int $id): void
    {
        DB::get()->update('proposals', ['updated_at' => now_utc()], 'id = ?', [$id]);
    }

    /**
     * Derive the process status from the facts.
     * review → ready → judging → completed → finalized
     */
    public static function refreshStatus(int $id): string
    {
        $db = DB::get();
        $p = $db->one('SELECT id, status, reviewed_at, finalized_at FROM {proposals} WHERE id = ?', [$id]);
        if (!$p) {
            return '';
        }
        $rows = $db->all('SELECT status, sent_at, first_opened_at, draft_saved_at, submitted_at FROM {assignments} WHERE proposal_id = ?', [$id]);
        $submitted = 0;
        $started = false;
        foreach ($rows as $a) {
            if ($a['status'] === 'submitted') {
                $submitted++;
            }
            if ($a['sent_at'] || $a['first_opened_at'] || $a['draft_saved_at'] || $a['submitted_at']) {
                $started = true;
            }
        }
        if ($submitted >= 2) {
            $status = !empty($p['finalized_at']) ? 'finalized' : 'completed';
        } elseif ($started) {
            $status = 'judging';
        } elseif (!empty($p['reviewed_at'])) {
            $status = 'ready';
        } else {
            $status = 'review';
        }
        if ($status !== $p['status']) {
            $db->update('proposals', ['status' => $status, 'updated_at' => now_utc()], 'id = ?', [$id]);
        }
        return $status;
    }

    /** @return array{0:array,1:int} rows (with both assignments attached), total */
    public static function page(array $filter, int $page, int $perPage): array
    {
        $db = DB::get();
        $where = [];
        $params = [];
        $status = (string) ($filter['status'] ?? '');
        if (in_array($status, ['review', 'ready', 'judging', 'completed', 'finalized'], true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        } elseif ($status === 'attention') {
            $where[] = "(status = 'review' OR status = 'completed' OR needs_resolution = 1)";
        }
        $decision = (string) ($filter['decision'] ?? '');
        if (in_array($decision, ['approve', 'conditional', 'reject'], true)) {
            $where[] = '(final_decision = ? OR (final_decision IS NULL AND system_decision = ?))';
            $params[] = $decision;
            $params[] = $decision;
        }
        $q = Fa::search((string) ($filter['q'] ?? ''));
        if ($q !== '') {
            foreach (array_slice(explode(' ', $q), 0, 6) as $word) {
                // explicit ESCAPE: MySQL and SQLite disagree about the default escape character
                $where[] = "search_text LIKE ? ESCAPE '!'";
                $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%';
            }
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $db->val('SELECT COUNT(*) FROM {proposals}' . $sqlWhere, $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $db->all(
            'SELECT ' . self::LIST_COLS . ' FROM {proposals}' . $sqlWhere
            . ' ORDER BY id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        return [self::attachAssignments($rows), $total];
    }

    public static function latest(int $limit = 8): array
    {
        $rows = DB::get()->all('SELECT ' . self::LIST_COLS . ' FROM {proposals} ORDER BY updated_at DESC, id DESC LIMIT ' . (int) $limit);
        return self::attachAssignments($rows);
    }

    private static function attachAssignments(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['id'];
        }
        $map = [];
        $list = DB::get()->all(
            'SELECT id, proposal_id, role, judge_name, judge_email, status, sent_at, first_opened_at, draft_saved_at, submitted_at, last_reminder_at '
            . 'FROM {assignments} WHERE proposal_id IN (' . implode(',', $ids) . ')'
        );
        foreach ($list as $a) {
            $map[(int) $a['proposal_id']][$a['role']] = $a;
        }
        foreach ($rows as &$r) {
            $r['tech'] = $map[(int) $r['id']]['tech'] ?? null;
            $r['biz'] = $map[(int) $r['id']]['biz'] ?? null;
        }
        unset($r);
        return $rows;
    }

    /** Number of proposals per status. */
    public static function counts(): array
    {
        $out = ['review' => 0, 'ready' => 0, 'judging' => 0, 'completed' => 0, 'finalized' => 0, 'total' => 0];
        foreach (DB::get()->all('SELECT status, COUNT(*) AS n FROM {proposals} GROUP BY status') as $row) {
            if (isset($out[$row['status']])) {
                $out[$row['status']] = (int) $row['n'];
            }
            $out['total'] += (int) $row['n'];
        }
        return $out;
    }

    public static function delete(int $id): void
    {
        $p = self::find($id);
        if (!$p) {
            return;
        }
        $db = DB::get();
        $db->tx(function (DB $db) use ($id) {
            $db->delete('assignments', 'proposal_id = ?', [$id]);
            $db->delete('proposal_docs', 'proposal_id = ?', [$id]);
            $db->delete('proposals', 'id = ?', [$id]);
        });
        $file = self::filePath($p);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

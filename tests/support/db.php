<?php
/**
 * Database helpers for the tests: a fresh, empty application database per call.
 */

use Fava\Core\Config;
use Fava\Core\DB;
use Fava\Core\Schema;
use Fava\Domain\Settings;

/** Databases to run the database tests on: always SQLite, plus MySQL/MariaDB when FAVA_TEST_MYSQL is set. */
function test_databases(): array
{
    $list = [];
    if (in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        $list['SQLite'] = function () {
            return ['driver' => 'sqlite', 'path' => tmp_dir('fava-db-') . '/test.sqlite', 'prefix' => 'fava_'];
        };
    }
    $mysql = getenv('FAVA_TEST_MYSQL');
    if ($mysql) {
        $cfg = json_decode($mysql, true);
        if (!is_array($cfg)) {
            fwrite(STDERR, "FAVA_TEST_MYSQL is not valid JSON; MySQL tests skipped.\n");
        } else {
            $list['MySQL/MariaDB'] = function () use ($cfg) {
                // a random prefix keeps parallel runs and existing tables apart
                return $cfg + ['driver' => 'mysql', 'prefix' => 't' . bin2hex(random_bytes(3)) . '_'];
            };
        }
    }
    return $list;
}

/** Point the application at a new, empty database with the schema installed. */
function fresh_app(callable $makeConfig): DB
{
    $cfg = $makeConfig();
    rm_rf(STORAGE_DIR . '/uploads'); // files of the previous test
    @unlink(STORAGE_DIR . '/reset-admin.txt');
    $db = DB::connect($cfg);
    Schema::install($db);
    DB::setInstance($db);
    Config::set('installed', true);
    Config::set('db', $cfg);
    Config::set('app_key', str_repeat('k', 64));
    Config::set('pretty_urls', true);
    Config::set('debug', false);
    Settings::flush();
    Settings::setMany(['org_name' => 'پارک فاوا', 'app_url' => 'https://assess.example.ir', 'schema_version' => Schema::VERSION]);
    if ($cfg['driver'] === 'mysql') {
        register_shutdown_function(function () use ($db) {
            foreach (array_keys(Schema::tables()) as $t) {
                try {
                    $db->pdo->exec('DROP TABLE IF EXISTS ' . $db->table($t));
                } catch (\Throwable $e) {
                }
            }
        });
    }
    return $db;
}

/** Upload a fixture the way the admin does, and return the proposal row. */
function upload_fixture(string $name, ?string $as = null): array
{
    $r = \Fava\Domain\Proposals::createFromUpload(fixture($name), $as ?? $name, null, false);
    return \Fava\Domain\Proposals::find($r['id']);
}

/** Review a proposal (what the «تأیید اطلاعات» button does). */
function review_proposal(int $id, ?float $amount = 18.5): array
{
    \Fava\Domain\Proposals::saveDetails($id, ['requested_amount' => $amount, 'reviewed_at' => now_utc()]);
    \Fava\Domain\Proposals::refreshStatus($id);
    return \Fava\Domain\Proposals::find($id);
}

/** Submit the form of one role with the given scores, as FormController::submit does. */
function submit_form(int $proposalId, string $role, array $scores, array $over = []): array
{
    $p = \Fava\Domain\Proposals::find($proposalId);
    $a = \Fava\Domain\Assignments::forProposal($proposalId)[$role];
    $cfg = \Fava\Domain\Proposals::config($p);
    $fixed = $p['requested_amount'] !== null ? (float) $p['requested_amount'] : null;
    $answers = \Fava\Domain\Scoring::normalize($role, answers_for($role, $scores, $over));
    $errors = \Fava\Domain\Scoring::validate($role, $answers, $cfg, $fixed);
    if ($errors) {
        throw new \RuntimeException('test answers are not valid: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
    }
    \Fava\Domain\Assignments::submit($a, $answers, \Fava\Domain\Scoring::judge($role, $answers, $cfg, $fixed), '127.0.0.1', 'tests');
    \Fava\Domain\Results::recompute($proposalId);
    return \Fava\Domain\Proposals::find($proposalId);
}

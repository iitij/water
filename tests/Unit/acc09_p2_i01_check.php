<?php

// Intentionally weak caller; public monetary values must not coerce implicitly.
foreach (['ExactMoney', 'FinancialAmount', 'SignedMinor', 'CashMoney', 'DecimalDomain', 'MoneyBoundary'] as $class) {
    require __DIR__.'/../../app/Support/'.$class.'.php';
}

use App\Support\DecimalDomain as Domain;
use App\Support\MoneyBoundary as Boundary;
use App\Support\SignedMinor;

$mode = $argv[1] ?? 'pure';
$count = 0;
$eq = static function ($actual, $expected) use (&$count): void {
    if ($actual !== $expected) {
        throw new RuntimeException('Check '.($count + 1).': '.var_export([$actual, $expected], true));
    }
    ++$count;
};
$reject = static function (callable $action, string $expected) use (&$count): void {
    try {
        $action();
    } catch (Throwable $error) {
        if (get_class($error) !== $expected) throw $error;
        ++$count;
        return;
    }
    throw new RuntimeException('Expected '.$expected.' at check '.($count + 1));
};
$invalid = InvalidArgumentException::class;
$overflow = OverflowException::class;

if ($mode === 'observed') {
    // Auxiliary Python SQLite engine harness, not evidence of PHP PDO behavior.
    $rows = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    foreach ($rows as $row) {
        $domain = Domain::from($row['domain']);
        $eq(Boundary::readDecimal($row['raw'], $domain, 'sqlite', true, true), $row['expected']);
    }
    echo "ACC09_P2_I01_SQLITE_OBSERVED_PASS {$count} checks\n";
    exit(0);
}

if ($mode === 'zero-compat') {
    // Differential checks against the unchanged legacy parser. The endpoint
    // range remains an independent constraint, even for a normalized zero.
    $zeroes = ['0e257', '-0e-257', '+.000E+9999999999999999999999',
        '000.000e-'.str_repeat('9', 240), '0e'.str_repeat('9', 254),
        '-0e-'.str_repeat('9', 252), str_repeat('0', 256), '  -0.00e+257  '];
    $tooLong = ['0e'.str_repeat('9', 255), '-0e-'.str_repeat('9', 253),
        str_repeat('0', 257), ' '.str_repeat('0', 256)];
    $malformed = ['0e', '0e+', '0e1.0', '0x0', "0e257\0", '0 e257'];
    $nonzeroOverflow = ['1e257', '-1e-257', '0.01e9999', '-.01e-9999',
        '1e'.str_repeat('9', 254), '1e256'];
    foreach (Domain::cases() as $domain) {
        $max = $domain->maxMinor();
        foreach ($zeroes as $text) {
            $legacy = \App\Support\FinancialAmount::parse($text)->minor;
            $eq($legacy, 0);
            $eq(Boundary::numericInput($text, $domain), $legacy);
            $eq(Boundary::numericInput($text, $domain, true, -$max, $max), $legacy);
            $eq(Boundary::numericInput($text, $domain, false, 0, 0), $legacy);
            $reject(fn () => Boundary::numericInput($text, $domain, false, 1), $overflow);
            $reject(fn () => Boundary::numericInput($text, $domain, true, -$max, -1), $overflow);
        }
        foreach (array_merge($tooLong, $malformed) as $text) {
            $reject(fn () => \App\Support\FinancialAmount::parse($text), $invalid);
            $reject(fn () => Boundary::numericInput($text, $domain), $invalid);
        }
        foreach ($nonzeroOverflow as $text) {
            $reject(fn () => \App\Support\FinancialAmount::parse($text), $overflow);
            $reject(fn () => Boundary::numericInput($text, $domain, true, -$max), $overflow);
        }
        $reject(fn () => \App\Support\FinancialAmount::parse('1e-256'), $invalid);
        $reject(fn () => Boundary::numericInput('1e-256', $domain), $invalid);
        foreach (['0e257', '-0e-257', '0e'.str_repeat('9', 254)] as $text) {
            $reject(fn () => Boundary::regexInput($text, $domain, true, -$max), $invalid);
            $reject(fn () => Boundary::readDecimal($text, $domain, 'sqlite', false, true), $invalid);
            $reject(fn () => Boundary::readDecimal($text, $domain, 'mysql', false, true), $invalid);
        }
        $reject(fn () => Boundary::numericInput('0e257', $domain, false, -1), $invalid);
        $reject(fn () => Boundary::numericInput('0e257', $domain, false, 0, $max + 1), $invalid);
        $reject(fn () => Boundary::numericInput('0e257', $domain, false, 1, 0), $invalid);
        $eq(Boundary::numericInput('1e-2', $domain, false, 1, 1), 1);
        $eq(Boundary::regexInput('0.00', $domain), 0);
        $eq(Boundary::readDecimal('0.00', $domain, 'mysql'), 0);
    }
    echo "ACC09_P2_I01_ZERO_COMPAT_PASS {$count} checks\n";
    exit(0);
}

if ($mode === 'pure') {
    $eq(PHP_INT_SIZE, 8);
    $eq(ini_get('serialize_precision'), '-1');
    foreach (Domain::cases() as $domain) {
        $max = $domain->maxMinor();
        foreach ([0, 1, -1, 100, -100, $max, $max - 1, -$max, -$max + 1] as $minor) {
            $text = SignedMinor::decimal($minor);
            $eq(Boundary::writeDecimal($minor, $domain, true), $text);
            $eq(Boundary::readDecimal($text, $domain, 'mysql', false, true), $minor);
            $eq(Boundary::readDecimal((float) $text, $domain, 'sqlite', false, true), $minor);
            $eq(Boundary::numericInput($text, $domain, true, -$max), $minor);
            $eq(Boundary::regexInput($text, $domain, true, -$max), $minor);
            $eq(Boundary::readMinor((string) $minor, false, $domain), $minor);
            $eq(Boundary::writeMinor($minor, false, $domain), $minor);
        }
        foreach ([$max + 1, -$max - 1] as $tooLarge) {
            $reject(fn () => Boundary::writeDecimal($tooLarge, $domain, true), $overflow);
            $reject(fn () => Boundary::readDecimal(SignedMinor::decimal($tooLarge), $domain, 'mysql', false, true), $overflow);
            $reject(fn () => Boundary::numericInput(SignedMinor::decimal($tooLarge), $domain, true, -$max), $overflow);
            $reject(fn () => Boundary::readMinor((string) $tooLarge, false, $domain), $overflow);
        }
        $eq(Boundary::readDecimal(null, $domain, 'mysql', true), null);
        $eq(Boundary::readDecimal('0.00', $domain, 'mysql', true), 0);
        $eq(Boundary::readDecimal(0, $domain, 'sqlite'), 0);
        $reject(fn () => Boundary::readDecimal(null, $domain, 'sqlite'), $invalid);
        $reject(fn () => Boundary::readDecimal(0.01, $domain, 'mysql'), $invalid);
        $reject(fn () => Boundary::readDecimal('-0.01', $domain, 'mysql'), $invalid);
        $reject(fn () => Boundary::readDecimal('0.001', $domain, 'sqlite'), $invalid);
        $reject(fn () => Boundary::readDecimal(0.001, $domain, 'sqlite'), $invalid);
        $reject(fn () => Boundary::readDecimal('0.00', $domain, 'other'), $invalid);
        $reject(fn () => Boundary::writeDecimal('1', $domain), $invalid);
        $reject(fn () => Boundary::writeDecimal(-1, $domain), $invalid);
        $eq(Boundary::regexInput('0001.20', $domain), 120);
        $eq(Boundary::regexInput(1.2, $domain), 120);
        $eq(Boundary::numericInput(' +001.2300e1 ', $domain), 1230);
        $eq(Boundary::numericInput('.01', $domain), 1);
        $eq(Boundary::numericInput('1.', $domain), 100);
        $eq(Boundary::numericInput('0e256', $domain), 0);
        $eq(Boundary::numericInput('0e257', $domain), 0);
        foreach (['1e1', ' +1', '1.000', ' 1 ', '.1', '-1', str_repeat('0', $domain->value - 1)] as $bad) {
            $reject(fn () => Boundary::regexInput($bad, $domain), $invalid);
        }
        foreach ([null, false, true, [], new stdClass(), INF, NAN, '', '1.001', '1e-3', '١.٠٠', "1\0"] as $bad) {
            $reject(fn () => Boundary::numericInput($bad, $domain), $invalid);
        }
        for ($i = 0; $i <= 250; ++$i) {
            $minor = $max - $i;
            foreach ([$minor, -$minor] as $signed) {
                $text = Boundary::writeDecimal($signed, $domain, true);
                $eq(Boundary::readDecimal($text, $domain, 'mysql', false, true), $signed);
                $eq(Boundary::readDecimal((float) $text, $domain, 'sqlite', false, true), $signed);
                $eq(Boundary::numericInput($text, $domain, true, -$max), $signed);
            }
        }
    }
    // Actual existing cash session endpoint cap is narrower than DECIMAL14.
    $eq(Boundary::numericInput('100000000', Domain::D14, false, 0, 10000000000), 10000000000);
    $reject(fn () => Boundary::numericInput('100000000.01', Domain::D14, false, 0, 10000000000), $overflow);
    $reject(fn () => Boundary::regexInput('0.00', Domain::D14, false, 1), $overflow);
    $reject(fn () => Boundary::numericInput('1', Domain::D14, false, -1), $invalid);
    $eq(Boundary::readMinor(null, true, null, true), null);
    $eq(Boundary::readMinor('0', true, null, true), 0);
    foreach ([0, 1, PHP_INT_MAX, 9007199254740993] as $minor) {
        $eq(Boundary::readMinor((string) $minor, true), $minor);
        $eq(Boundary::readMinor((string) -$minor, false), -$minor);
        $eq(Boundary::writeMinor($minor, true), $minor);
    }
    foreach (['9223372036854775808', '18446744073709551615', '-9223372036854775808', PHP_INT_MIN] as $bad) {
        $reject(fn () => Boundary::readMinor($bad, false), $overflow);
        $reject(fn () => Boundary::readMinor($bad, true), $overflow);
    }
    foreach (['01', '-0', '+1', '1.0', '1e2', 1.0, null, false, [], INF, NAN] as $bad) {
        $reject(fn () => Boundary::readMinor($bad, false), $invalid);
    }
    $reject(fn () => Boundary::readMinor('-1', true), $invalid);
    $reject(fn () => Boundary::writeMinor('1', true), $invalid);
    $reject(fn () => Boundary::readMinor('1000000000000', true, Domain::D12), $overflow);
    $eq(Boundary::readMinor('1000000000000', true, Domain::D14), 1000000000000);
    $eq(Boundary::numericMinorOutput(Boundary::JS_SAFE_MINOR), Boundary::JS_SAFE_MINOR);
    $reject(fn () => Boundary::numericMinorOutput(Boundary::JS_SAFE_MINOR + 1), $overflow);
    $reject(fn () => Boundary::numericMinorOutput(-Boundary::JS_SAFE_MINOR - 1), $overflow);
    $reject(fn () => Boundary::numericMajorOutput(Boundary::JS_SAFE_MINOR), $overflow);
    $reject(fn () => Boundary::numericMajorOutput(-Boundary::JS_SAFE_MINOR), $overflow);
    $eq(Boundary::numericMajorOutput(9007199254740990), 90071992547409.90);
    $eq(Boundary::numericMajorOutput(30), 0.30);
    $eq(Boundary::numericMajorOutput(-1), -0.01);
    $reject(fn () => Boundary::numericMajorOutput('30'), $invalid);
    $reject(fn () => Boundary::numericInput(0.1 + 0.2, Domain::D14), $invalid);
    $oldPrecision = ini_get('serialize_precision');
    try {
        ini_set('serialize_precision', '14');
        $reject(fn () => Boundary::readDecimal(0.01, Domain::D14, 'sqlite'), $invalid);
        $reject(fn () => Boundary::numericInput(0.01, Domain::D14), $invalid);
        $reject(fn () => Boundary::numericMajorOutput(1), $invalid);
    } finally {
        ini_set('serialize_precision', $oldPrecision);
    }
    echo "ACC09_P2_I01_PURE_PASS {$count} checks\n";
    exit(0);
}

if (! in_array($mode, ['sqlite', 'mysql'], true) || ! in_array($mode, PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('Requested real PDO driver unavailable.');
}
if ($mode === 'sqlite') {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
    $unsigned = 'BIGINT'; // SQLite has no unsigned integer domain.
} else {
    // Dedicated synthetic schema only; temporary tables never touch product tables.
    $dsn = getenv('WATER_I01_MYSQL_DSN');
    if (getenv('APP_ENV') !== 'testing' || ! is_string($dsn) || ! str_starts_with($dsn, 'mysql:')) {
        throw new RuntimeException('Explicit testing MySQL DSN required.');
    }
    $pdo = new PDO($dsn, getenv('WATER_I01_MYSQL_USER') ?: 'root', getenv('WATER_I01_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $eq($pdo->query('SELECT DATABASE()')->fetchColumn(), 'water_test');
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    if (! preg_match('/\A8\./', $version) || stripos($version, 'mariadb') !== false) {
        throw new RuntimeException('Genuine MySQL 8 required.');
    }
    $unsigned = 'BIGINT UNSIGNED';
}
$pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
$pdo->exec("CREATE TEMPORARY TABLE p2_i01_amount_probe (d10 DECIMAL(10,2), d12 DECIMAL(12,2), d14 DECIMAL(14,2), signed_minor BIGINT, unsigned_minor {$unsigned})");
$insert = $pdo->prepare('INSERT INTO p2_i01_amount_probe VALUES (?, ?, ?, ?, ?)');
$types = [];
foreach ([null, 0, 1, -1, 100, -100, 2, -2, 'max', 'near', 'negativeMax', 'negativeNear'] as $case) {
    $values = [];
    $expected = [];
    foreach (Domain::cases() as $domain) {
        $minor = match ($case) {
            'max' => $domain->maxMinor(), 'near' => $domain->maxMinor() - 1,
            'negativeMax' => -$domain->maxMinor(), 'negativeNear' => -$domain->maxMinor() + 1,
            default => $case,
        };
        $expected[] = $minor;
        $values[] = $minor === null ? null : Boundary::writeDecimal($minor, $domain, true);
    }
    $signed = $case === null ? null : ($case === 'negativeMax' ? -PHP_INT_MAX : PHP_INT_MAX);
    $nonnegative = $case === null ? null : 9007199254740993;
    $values[] = $signed === null ? null : (string) Boundary::writeMinor($signed, false);
    $values[] = $nonnegative === null ? null : (string) Boundary::writeMinor($nonnegative, true);
    $insert->execute($values);
    $row = $pdo->query('SELECT * FROM p2_i01_amount_probe')->fetch(PDO::FETCH_NUM);
    foreach (Domain::cases() as $i => $domain) {
        $types[$domain->name][get_debug_type($row[$i])] = true;
        $eq(Boundary::readDecimal($row[$i], $domain, $mode, true, true), $expected[$i]);
    }
    $types['signed_minor'][get_debug_type($row[3])] = true;
    $types['unsigned_minor'][get_debug_type($row[4])] = true;
    $eq(Boundary::readMinor($row[3], false, null, true), $signed);
    $eq(Boundary::readMinor($row[4], true, null, true), $nonnegative);
    $pdo->exec('DELETE FROM p2_i01_amount_probe');
}
// Boundary read checks for naturally representable but disallowed raw BIGINT values.
$insert->execute(['0.00', '0.00', '0.00', '-9223372036854775808', $mode === 'mysql' ? '18446744073709551615' : '-1']);
$row = $pdo->query('SELECT * FROM p2_i01_amount_probe')->fetch(PDO::FETCH_NUM);
$reject(fn () => Boundary::readMinor($row[3], false), $overflow);
$reject(fn () => Boundary::readMinor($row[4], true), $mode === 'mysql' ? $overflow : $invalid);
$pdo->exec('DELETE FROM p2_i01_amount_probe');
if ($mode === 'sqlite') {
    // SQLite NUMERIC affinity does not enforce the declared precision or scale.
    $insert->execute(['100000000.00', '0.001', '0.00', '0', '0']);
    $row = $pdo->query('SELECT * FROM p2_i01_amount_probe')->fetch(PDO::FETCH_NUM);
    $reject(fn () => Boundary::readDecimal($row[0], Domain::D10, 'sqlite'), $overflow);
    $reject(fn () => Boundary::readDecimal($row[1], Domain::D12, 'sqlite'), $invalid);
}
$pdo->exec('DROP TABLE p2_i01_amount_probe');
$pdo = null;
echo 'PDO '.$mode.' version='.$version.' PHP='.PHP_VERSION.' raw_types='.json_encode($types, JSON_THROW_ON_ERROR)."\n";
echo 'ACC09_P2_I01_'.strtoupper($mode)."_PDO_PASS {$count} checks\n";

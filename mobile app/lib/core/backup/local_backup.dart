import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

/// نسخة احتياطية محلية لبيانات الميدان (S7-08).
///
/// الفكرة العملية: القارئ في منطقة بلا شبكة يجمع عشرات القراءات في الجهاز.
/// تعطّل الجهاز أو حذف التطبيق يعني ضياع عمل يوم كامل. لذلك نكتب ملف نسخة
/// داخل مجلد التطبيق، يمكن مشاركته إلى Google Drive/واتساب، واستعادته لاحقاً.
///
/// الملف نصي (JSON) لا ثنائي: يُقرأ ويُفتّش بالعين، ولا يعتمد على إصدار SQLite.
/// ويحمل بصمة SHA-256 حتى نرفض ملفاً مقطوعاً أو معدّلاً بدل استعادة نصف بيانات.
class BackupFormat {
  BackupFormat._();

  /// نسخة صيغة الملف — تُزاد عند تغيير تركيب الحقول.
  static const int version = 1;

  static const String filePrefix = 'water-backup-';
  static const String fileSuffix = '.json';
}

/// أخطاء النسخ/الاستعادة برسائل عربية جاهزة للعرض.
class BackupException implements Exception {
  const BackupException(this.message);

  final String message;

  @override
  String toString() => message;
}

/// A name already belongs to another backup; the caller must allocate a new one.
class BackupNameConflict implements Exception {
  const BackupNameConflict();
}

/// ملف نسخة احتياطية مع بياناته الوصفية (بلا محتوى البيانات الثقيل).
class BackupFile {
  const BackupFile({
    required this.name,
    required this.createdAt,
    required this.bytes,
    this.appVersion,
    this.formatVersion = BackupFormat.version,
    this.counts = const <String, int>{},
    this.isCompatible = true,
  });

  final String name;
  final DateTime createdAt;
  final int bytes;
  final String? appVersion;
  final int formatVersion;

  /// عدد الصفوف في كل جدول (يُعرض في الواجهة قبل الاستعادة).
  final Map<String, int> counts;
  final bool isCompatible;

  int get totalRows => counts.values.fold(0, (sum, value) => sum + value);

  String get sizeLabel {
    if (bytes < 1024) return '$bytes بايت';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(1)} ك.ب';
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} م.ب';
  }
}

/// نتيجة الاستعادة — تُعرض للمستخدم كما هي بلا تجميل.
class BackupRestoreResult {
  const BackupRestoreResult({
    required this.restoredRows,
    required this.pendingActions,
    required this.warnings,
  });

  final int restoredRows;
  final int pendingActions;
  final List<String> warnings;

  static const BackupRestoreResult empty = BackupRestoreResult(
    restoredRows: 0,
    pendingActions: 0,
    warnings: <String>[],
  );
}

/// مصدر بيانات الميدان (قاعدة Drift في التطبيق، أو خريطة في الاختبارات).
abstract interface class FieldDataStore {
  Future<Map<String, dynamic>> exportFieldData();

  Future<void> importFieldData(Map<String, dynamic> data, {bool pendingActions = true});
}

/// مكان حفظ الملفات (مجلد المستندات على الجهاز، أو ذاكرة في الاختبارات).
abstract class BackupStorage {
  /// Create a new file exclusively; never replace an existing backup.
  /// Throw BackupNameConflict when the name already exists.
  Future<String> write(String name, String contents);

  Future<List<String>> list();

  Future<String> read(String name);

  Future<void> delete(String name);

  /// مسار الملف على القرص — للاختبارات يكفي الاسم.
  Future<String> pathOf(String name) async => name;

  /// مشاركة الملف خارج التطبيق. الافتراضي: لا شيء (للاختبارات).
  Future<void> share(String name, String path) async {}
}

/// مجلد `<documents>/backups` — داخل مساحة التطبيق، ويُشارك منه للخارج.
class DeviceBackupStorage implements BackupStorage {
  DeviceBackupStorage({Directory? directory}) : _directory = directory;

  final Directory? _directory;
  Directory? _resolved;

  Future<Directory> _dir() async {
    final existing = _resolved;
    if (existing != null) return existing;
    final base = _directory ?? Directory('${(await getApplicationDocumentsDirectory()).path}/backups');
    if (!await base.exists()) await base.create(recursive: true);
    return _resolved = base;
  }

  @override
  Future<String> write(String name, String contents) async {
    final dir = await _dir();
    final file = File('${dir.path}/$name');
    try {
      await file.create(exclusive: true);
    } on FileSystemException {
      if (await file.exists()) throw const BackupNameConflict();
      rethrow;
    }
    try {
      await file.writeAsString(contents, flush: true);
      return file.path;
    } catch (_) {
      // Delete only the file this call successfully reserved.
      try {
        await file.delete();
      } catch (_) {
        // A failed cleanup remains visible as an incompatible file.
      }
      rethrow;
    }
  }

  @override
  Future<List<String>> list() async {
    final dir = await _dir();
    if (!await dir.exists()) return const <String>[];
    final entries = await dir
        .list()
        .where((entity) => entity is File && entity.path.endsWith(BackupFormat.fileSuffix))
        .map((entity) => entity.path.split(Platform.pathSeparator).last)
        .toList();
    entries.sort((a, b) => b.compareTo(a)); // الأحدث أولاً (الاسم يحمل الوقت)
    return entries;
  }

  @override
  Future<String> read(String name) async {
    final dir = await _dir();
    return File('${dir.path}/$name').readAsString();
  }

  @override
  Future<String> pathOf(String name) async => '${(await _dir()).path}/$name';

  @override
  Future<void> delete(String name) async {
    final dir = await _dir();
    final file = File('${dir.path}/$name');
    if (await file.exists()) await file.delete();
  }

  @override
  Future<void> share(String name, String path) async {
    await Share.shareXFiles(
      <XFile>[XFile(path, mimeType: 'application/json', name: name)],
      subject: 'نسخة احتياطية — تطبيق المياه',
    );
  }
}

/// خدمة النسخ الاحتياطي والاستعادة.
class LocalBackupService {
  LocalBackupService({
    required FieldDataStore store,
    required BackupStorage storage,
    String? appVersion,
    DateTime Function()? clock,
  })  : _store = store,
        _storage = storage,
        _appVersion = appVersion,
        _clock = clock ?? DateTime.now;

  final FieldDataStore _store;
  final BackupStorage _storage;
  final String? _appVersion;
  final DateTime Function() _clock;

  /// الجداول التي تُحسب لها أعداد الصفوف.
  static const List<String> trackedTables = <String>[
    'squares',
    'subscribers',
    'meters',
    'meter_periods',
    'readings',
    'pending_actions',
    'settings',
  ];

  /// ينشئ نسخة ويعيد اسمها. لا يُشاركها — الواجهة تقرّر.
  Future<BackupFile> createBackup() async {
    final data = await _store.exportFieldData();
    final counts = _countsOf(data);
    if (counts.values.every((count) => count == 0)) {
      throw const BackupException('لا توجد بيانات محلية لنسخها بعد. افتح شاشة القراءات أونلاين أولاً.');
    }

    final createdAt = _clock().toUtc();
    final payload = <String, dynamic>{
      'format': 'water-field-backup',
      'version': BackupFormat.version,
      'created_at': createdAt.toIso8601String(),
      'app_version': _appVersion,
      'counts': counts,
      'checksum': checksumOf(data),
      'data': data,
    };
    final contents = const JsonEncoder.withIndent('  ').convert(payload);
    final baseName = fileNameFor(createdAt);
    final existing = (await _storage.list()).toSet();
    String? name;
    for (var sequence = 0; sequence < 10000; sequence++) {
      final candidate = sequence == 0
          ? baseName
          : '${baseName.substring(0, baseName.length - BackupFormat.fileSuffix.length)}-${sequence.toString().padLeft(4, '0')}${BackupFormat.fileSuffix}';
      if (existing.contains(candidate)) continue;
      try {
        await _storage.write(candidate, contents);
        name = candidate;
        break;
      } on BackupNameConflict {
        // Another call reserved the same name after the list operation.
      }
    }
    if (name == null) {
      throw const BackupException('تعذر تخصيص اسم نسخة احتياطية فريد.');
    }

    return BackupFile(
      name: name,
      createdAt: createdAt,
      bytes: utf8.encode(contents).length,
      appVersion: _appVersion,
      counts: counts,
    );
  }

  /// قائمة النسخ الموجودة، مرتبة من الأحدث. الملفات التالفة تُعرض كغير متوافقة
  /// بدل إخفائها — المستخدم يجب أن يعرف أنها موجودة ولا تصلح.
  Future<List<BackupFile>> listBackups() async {
    final names = await _storage.list();
    final files = <BackupFile>[];
    for (final name in names) {
      files.add(await _describe(name));
    }
    files.sort((a, b) => b.createdAt.compareTo(a.createdAt));
    return files;
  }

  Future<BackupFile> _describe(String name) async {
    final fallbackDate = _dateFromFileName(name) ?? _clock().toUtc();
    try {
      final raw = await _storage.read(name);
      final decoded = jsonDecode(raw);
      if (decoded is! Map) throw const BackupException('ملف غير معروف');
      final map = Map<String, dynamic>.from(decoded);
      final counts = _countsOf(map['data']);
      return BackupFile(
        name: name,
        createdAt: DateTime.tryParse(map['created_at']?.toString() ?? '')?.toUtc() ?? fallbackDate,
        bytes: utf8.encode(raw).length,
        appVersion: map['app_version']?.toString(),
        formatVersion: (map['version'] as num?)?.toInt() ?? 0,
        counts: counts,
        isCompatible: (map['version'] as num?)?.toInt() == BackupFormat.version,
      );
    } catch (_) {
      return BackupFile(
        name: name,
        createdAt: fallbackDate,
        bytes: 0,
        formatVersion: 0,
        isCompatible: false,
      );
    }
  }

  /// يستعيد نسخة بعد التحقق من الصيغة والبصمة.
  ///
  /// أي شك = رفض: ملف مقطوع يستعيد نصف بيانات ويبدو ناجحاً، وهذا أسوأ من خطأ.
  Future<BackupRestoreResult> restore(String name, {bool includePendingActions = false}) async {
    final raw = await _storage.read(name);
    final Map<String, dynamic> payload;
    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map) throw const BackupException('ملف النسخة ليس JSON صالحاً.');
      payload = Map<String, dynamic>.from(decoded);
    } on FormatException {
      throw const BackupException('تعذّر قراءة الملف — قد يكون تالفاً أو ليس نسخة من هذا التطبيق.');
    }

    if (payload['format'] != 'water-field-backup') {
      throw const BackupException('الملف ليس نسخة احتياطية من تطبيق المياه.');
    }
    final version = (payload['version'] as num?)?.toInt() ?? 0;
    if (version > BackupFormat.version) {
      throw BackupException(
        'النسخة من إصدار أحدث (صيغة $version). حدّث التطبيق ثم أعد المحاولة.',
      );
    }
    final data = payload['data'];
    if (data is! Map) throw const BackupException('الملف لا يحتوي بيانات.');

    final dataMap = Map<String, dynamic>.from(data);
    final expected = payload['checksum']?.toString();
    if (expected == null || expected.isEmpty) {
      throw const BackupException('النسخة بلا بصمة تحقّق — لا يمكن التأكد من سلامتها.');
    }
    if (checksumOf(dataMap) != expected) {
      throw const BackupException('بصمة الملف لا تطابق محتواه — النسخة معدّلة أو مقطوعة.');
    }

    final pending = includePendingActions ? _countOf(dataMap['pending_actions']) : 0;
    // A reading without a server id needs its queue action to be sent.
    final restorableData = Map<String, dynamic>.from(dataMap);
    var skippedReadings = 0;
    if (!includePendingActions) {
      final readings = dataMap['readings'];
      if (readings is List) {
        final syncedReadings = readings
            .where((row) => row is Map && row['server_id'] != null)
            .toList(growable: false);
        skippedReadings = readings.length - syncedReadings.length;
        restorableData['readings'] = syncedReadings;
      }
      restorableData.remove('pending_actions');
    }
    await _store.importFieldData(restorableData, pendingActions: includePendingActions);

    final counts = _countsOf(restorableData);
    final restored = counts.entries
        .where((entry) => entry.key != 'pending_actions')
        .fold<int>(0, (sum, entry) => sum + entry.value);

    final warnings = <String>[];
    if (skippedReadings > 0) {
      warnings.add('لم تُستعد $skippedReadings قراءة غير مرسلة لأن استعادة طابور المزامنة مغلقة.');
    }
    if (includePendingActions && pending > 0) {
      warnings.add(
        'أُعيدت $pending عملية مزامنة غير مرسلة. إن كانت قد أُرسلت من الجهاز الأصلي '
        'قبل أخذ النسخة فقد يُرفض تكرارها أو يظهر مرتين — راجع سجل المزامنة بعد الاتصال.',
      );
    }

    return BackupRestoreResult(
      restoredRows: restored,
      pendingActions: pending,
      warnings: warnings,
    );
  }

  Future<void> delete(String name) => _storage.delete(name);

  /// يشارك الملف عبر درج المشاركة (Drive/واتساب/بريد).
  Future<void> shareBackup(String name) async {
    await _storage.share(name, await _storage.pathOf(name));
  }

  /// بصمة مستقرة: مفاتيح مرتّبة، فلا تتغيّر بتغيّر ترتيب الإدراج في الخريطة.
  static String checksumOf(Map<String, dynamic> data) =>
      sha256.convert(utf8.encode(_canonicalJson(data))).toString();

  static String _canonicalJson(Object? value) {
    if (value is Map) {
      final keys = value.keys.map((key) => key.toString()).toList()..sort();
      return '{${keys.map((key) => '${jsonEncode(key)}:${_canonicalJson(value[key])}').join(',')}}';
    }
    if (value is List) {
      return '[${value.map(_canonicalJson).join(',')}]';
    }
    return jsonEncode(value);
  }

  static Map<String, int> _countsOf(Object? data) {
    if (data is! Map) return const <String, int>{};
    return <String, int>{
      for (final table in trackedTables) table: _countOf(data[table]),
    };
  }

  static int _countOf(Object? rows) => rows is List ? rows.length : 0;

  /// اسم الملف يحمل التاريخ والوقت: الفرز النصي = الفرز الزمني.
  static String fileNameFor(DateTime utc) {
    String two(int value) => value.toString().padLeft(2, '0');
    final stamp = '${utc.year}${two(utc.month)}${two(utc.day)}-'
        '${two(utc.hour)}${two(utc.minute)}${two(utc.second)}';
    return '${BackupFormat.filePrefix}$stamp${BackupFormat.fileSuffix}';
  }

  static DateTime? _dateFromFileName(String name) {
    final match = RegExp(r'^water-backup-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})(?:-\d{4})?\.json$')
        .firstMatch(name);
    if (match == null) return null;
    int part(int index) => int.tryParse(match.group(index) ?? '') ?? 0;
    return DateTime.utc(part(1), part(2), part(3), part(4), part(5), part(6));
  }
}

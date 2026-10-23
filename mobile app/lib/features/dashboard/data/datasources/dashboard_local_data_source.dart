import 'dart:convert';

import 'package:intl/intl.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../domain/entities/dashboard_entities.dart';
import '../models/dashboard_models.dart';
import '../models/report_models.dart';

class CachedDashboard {
  const CachedDashboard({required this.data, required this.savedAt});

  final DashboardDataModel data;
  final DateTime savedAt;
}

class CachedReport {
  const CachedReport({required this.report, required this.savedAt});

  final FinancialReportModel report;
  final DateTime savedAt;
}

class DashboardLocalDataSource {
  static const _dashboardKey = 'dev5.dashboard.cache.v1';
  static const _reportPrefix = 'dev5.report.cache.v1';

  Future<void> cacheDashboard(DashboardDataModel data) async {
    final preferences = await SharedPreferences.getInstance();
    await preferences.setString(
      _dashboardKey,
      jsonEncode({'saved_at': DateTime.now().toIso8601String(), 'data': data.toJson()}),
    );
  }

  Future<CachedDashboard?> readDashboard() async {
    final preferences = await SharedPreferences.getInstance();
    final raw = preferences.getString(_dashboardKey);
    if (raw == null) return null;
    try {
      final json = jsonDecode(raw) as Map<String, dynamic>;
      final savedAt = DateTime.tryParse(json['saved_at'] as String? ?? '');
      if (savedAt == null) return null;
      return CachedDashboard(
        data: DashboardDataModel.fromJson(json['data']),
        savedAt: savedAt,
      );
    } on FormatException {
      await preferences.remove(_dashboardKey);
      return null;
    } on TypeError {
      await preferences.remove(_dashboardKey);
      return null;
    }
  }

  Future<void> cacheReport(ReportFilter filter, FinancialReportModel report) async {
    final preferences = await SharedPreferences.getInstance();
    await preferences.setString(
      _reportKey(filter),
      jsonEncode({'saved_at': DateTime.now().toIso8601String(), 'data': report.toJson()}),
    );
  }

  Future<CachedReport?> readReport(ReportFilter filter) async {
    final preferences = await SharedPreferences.getInstance();
    final key = _reportKey(filter);
    final raw = preferences.getString(key);
    if (raw == null) return null;
    try {
      final json = jsonDecode(raw) as Map<String, dynamic>;
      final savedAt = DateTime.tryParse(json['saved_at'] as String? ?? '');
      if (savedAt == null) return null;
      return CachedReport(
        report: FinancialReportModel.fromJson(json['data']),
        savedAt: savedAt,
      );
    } on FormatException {
      await preferences.remove(key);
      return null;
    } on TypeError {
      await preferences.remove(key);
      return null;
    }
  }

  String _reportKey(ReportFilter filter) {
    final format = DateFormat('yyyy-MM-dd', 'en_US');
    final from = filter.from == null ? 'all' : format.format(filter.from!);
    final to = filter.to == null ? 'all' : format.format(filter.to!);
    return '$_reportPrefix.$from.$to';
  }
}

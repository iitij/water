import 'package:flutter/material.dart';
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../admin_shared/data/admin_api_client.dart';
import '../../data/models/ledger_statement_model.dart';

class LedgerStatementScreen extends StatefulWidget {
  const LedgerStatementScreen({
    super.key,
    required this.subscriberId,
    required this.subscriberName,
    this.client,
  });

  final int subscriberId;
  final String subscriberName;
  final AdminApiClient? client;

  @override
  State<LedgerStatementScreen> createState() => _LedgerStatementScreenState();
}

class _LedgerStatementScreenState extends State<LedgerStatementScreen> {
  late final AdminApiClient _api;
  late DateTimeRange _period;
  LedgerStatementModel? _statement;
  bool _busy = false;
  String? _error;

  String _date(DateTime date) => date.toIso8601String().substring(0, 10);
  String _money(int minor) => CurrencyFormatter.format(minor / 100);
  String get _path => '/subscribers/${widget.subscriberId}/ledger-statement';
  Map<String, dynamic> get _query => {
    'from_date': _date(_period.start),
    'to_date': _date(_period.end),
  };

  @override
  void initState() {
    super.initState();
    _api = widget.client ?? DioAdminApiClient(ApiClient().dio);
    final now = DateTime.now();
    _period = DateTimeRange(start: DateTime(now.year, now.month),
        end: DateUtils.dateOnly(now));
    _load();
  }

  Future<void> _load() async {
    if (_busy) return;
    final query = _query;
    setState(() { _busy = true; _statement = null; _error = null; });
    try {
      final result = await _api.get(_path, query: query);
      final statement = LedgerStatementModel.fromJson(result,
          subscriberId: widget.subscriberId,
          from: query['from_date'] as String, to: query['to_date'] as String);
      if (mounted) setState(() { _statement = statement; });
    } catch (error) {
      if (mounted) setState(() {
        _error = error is AdminApiException ? error.message :
            'تعذر التحقق من كشف الحساب. أعد المحاولة بعد الاتصال بالخادم.';
      });
    } finally {
      if (mounted) setState(() { _busy = false; });
    }
  }

  Future<void> _choosePeriod() async {
    final result = await showDateRangePicker(
      context: context, firstDate: DateTime(1900),
      lastDate: DateUtils.dateOnly(DateTime.now()), initialDateRange: _period,
    );
    if (result == null || !mounted || _busy) return;
    setState(() { _period = result; _statement = null; });
    await _load();
  }

  Future<void> _export(String format) async {
    if (_busy || _statement == null) return;
    setState(() { _busy = true; });
    try {
      final file = await _api.download('$_path/$format', query: {
        ..._query, 'statement_hash': _statement!.hash,
      });
      final bytes = file.bytes;
      final isPdf = format == 'pdf';
      if (isPdf ? (bytes.length < 5 ||
          String.fromCharCodes(bytes.take(5)) != '%PDF-') :
          (bytes.length < 4 || bytes[0] != 80 || bytes[1] != 75 ||
              bytes[2] != 3 || bytes[3] != 4)) {
        throw const FormatException('Invalid export file');
      }
      if (!mounted) return;
      final name = 'ledger-${widget.subscriberId}-${_date(_period.start)}-${_date(_period.end)}.$format';
      if (isPdf) {
        await Printing.sharePdf(bytes: bytes, filename: name);
      } else {
        await Share.shareXFiles([
          XFile.fromData(bytes, name: name,
              mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ], fileNameOverrides: [name]);
      }
    } catch (error) {
      if (mounted) {
        if (error is AdminApiException && error.statusCode == 409) {
          setState(() { _statement = null; _error = 'تغير كشف الحساب. حدّث الكشف قبل التصدير.'; });
        }
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(error is AdminApiException ? error.message : 'تعذر تصدير كشف الحساب.'),
        ));
      }
    } finally {
      if (mounted) setState(() { _busy = false; });
    }
  }

  Widget _metric(String label, int amount) => ListTile(
    dense: true, title: Text(label),
    trailing: Text(_money(amount), textDirection: TextDirection.ltr),
  );

  @override
  Widget build(BuildContext context) {
    final statement = _statement;
    return Scaffold(
      appBar: AppBar(title: Text('كشف الحساب المالي — ${widget.subscriberName}')),
      body: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: 1 + (statement?.entries.length ?? 0),
        itemBuilder: (context, index) {
          if (index != 0) {
            final row = statement!.entries[index - 1];
            return Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(row.description, style: Theme.of(context).textTheme.titleSmall),
                  Text('#${row.id} · ${row.date} · ${row.meterCode ?? "بلا مرجع عداد"}'),
                  _metric('مدين', row.debit),
                  _metric('دائن', row.credit),
                  _metric('الرصيد المتحرك', row.balance),
                ]),
              ),
            );
          }
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            OutlinedButton.icon(
              onPressed: _busy ? null : _choosePeriod,
              icon: const Icon(Icons.date_range_outlined),
              label: Text('${_date(_period.start)} — ${_date(_period.end)}',
                  textDirection: TextDirection.ltr),
            ),
            const Text('الأساس: تاريخ الترحيل. الموجب دائن والسالب مدين.'),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) ...[
              Text(_error!),
              TextButton(onPressed: _busy ? null : _load, child: const Text('إعادة المحاولة')),
            ],
            if (statement != null) ...[
              Card(child: Column(children: [
                _metric('رصيد الافتتاح', statement.opening),
                _metric('الحركات المدينة', statement.debit),
                _metric('الحركات الدائنة', statement.credit),
                _metric('رصيد الإغلاق', statement.closing),
              ])),
              Wrap(spacing: 8, runSpacing: 8, children: [
                FilledButton.icon(onPressed: _busy ? null : () => _export('pdf'),
                    icon: const Icon(Icons.picture_as_pdf_outlined), label: const Text('PDF')),
                OutlinedButton.icon(onPressed: _busy ? null : () => _export('xlsx'),
                    icon: const Icon(Icons.table_chart_outlined), label: const Text('Excel')),
                TextButton(onPressed: _busy ? null : _load, child: const Text('تحديث')),
              ]),
              const Text('إذا تغيرت القيود، يلزم تحديث الكشف قبل التصدير.'),
              if (statement.entries.isEmpty) const Text('لا توجد حركات خلال الفترة.'),
            ],
          ]);
        },
      ),
    );
  }
}

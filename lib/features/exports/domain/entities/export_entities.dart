import 'dart:typed_data';

import 'package:equatable/equatable.dart';
import 'package:intl/intl.dart';

enum ExportKind { subscribers, invoices, readings }

class ExportSquare extends Equatable {
  const ExportSquare({required this.id, required this.name});

  final int id;
  final String name;

  @override
  List<Object> get props => [id, name];
}

class ExportSummary extends Equatable {
  const ExportSummary({
    required this.totalSubscribers,
    required this.totalInvoices,
    required this.totalReadings,
    required this.totalSquares,
  });

  final int totalSubscribers;
  final int totalInvoices;
  final int totalReadings;
  final int totalSquares;

  int forKind(ExportKind kind) => switch (kind) {
        ExportKind.subscribers => totalSubscribers,
        ExportKind.invoices => totalInvoices,
        ExportKind.readings => totalReadings,
      };

  @override
  List<Object> get props => [totalSubscribers, totalInvoices, totalReadings, totalSquares];
}

class ExportCatalog extends Equatable {
  const ExportCatalog({required this.summary, required this.squares});

  final ExportSummary summary;
  final List<ExportSquare> squares;

  @override
  List<Object> get props => [summary, squares];
}

class ExportFilters extends Equatable {
  const ExportFilters({
    this.squareId,
    this.status,
    this.fromDate,
    this.toDate,
    this.isInvoiced,
  });

  final int? squareId;
  final String? status;
  final DateTime? fromDate;
  final DateTime? toDate;
  final bool? isInvoiced;

  bool get hasValidDates =>
      fromDate == null || toDate == null || !fromDate!.isAfter(toDate!);

  ExportFilters copyWith({
    int? squareId,
    bool clearSquare = false,
    String? status,
    bool clearStatus = false,
    DateTime? fromDate,
    bool clearFrom = false,
    DateTime? toDate,
    bool clearTo = false,
    bool? isInvoiced,
    bool clearInvoiced = false,
  }) {
    return ExportFilters(
      squareId: clearSquare ? null : squareId ?? this.squareId,
      status: clearStatus ? null : status ?? this.status,
      fromDate: clearFrom ? null : fromDate ?? this.fromDate,
      toDate: clearTo ? null : toDate ?? this.toDate,
      isInvoiced: clearInvoiced ? null : isInvoiced ?? this.isInvoiced,
    );
  }

  Map<String, dynamic> toQuery(ExportKind kind) {
    final format = DateFormat('yyyy-MM-dd', 'en_US');
    return {
      'square_id': squareId,
      if (kind != ExportKind.readings) 'status': status,
      if (kind != ExportKind.subscribers) ...{
        'from_date': fromDate == null ? null : format.format(fromDate!),
        'to_date': toDate == null ? null : format.format(toDate!),
      },
      if (kind == ExportKind.readings)
        'is_invoiced': isInvoiced == null ? null : (isInvoiced! ? '1' : '0'),
    };
  }

  @override
  List<Object?> get props => [squareId, status, fromDate, toDate, isInvoiced];
}

class ExcelDownload extends Equatable {
  const ExcelDownload({
    required this.kind,
    required this.fileName,
    required this.bytes,
  });

  final ExportKind kind;
  final String fileName;
  final Uint8List bytes;

  @override
  List<Object> get props => [kind, fileName, bytes];
}

class ExportedExcelFile extends Equatable {
  const ExportedExcelFile({
    required this.kind,
    required this.path,
    required this.fileName,
    required this.sizeInBytes,
    required this.createdAt,
  });

  final ExportKind kind;
  final String path;
  final String fileName;
  final int sizeInBytes;
  final DateTime createdAt;

  @override
  List<Object> get props => [kind, path, fileName, sizeInBytes, createdAt];
}

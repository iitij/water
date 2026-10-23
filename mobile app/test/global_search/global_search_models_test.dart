import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/global_search/data/models/global_search_models.dart';

void main() {
  group('GlobalSearchResponse', () {
    test('parses grouped results and drops empty groups', () {
      final response = GlobalSearchResponse.fromJson(<String, dynamic>{
        'query': 'احمد',
        'min_length': 2,
        'groups': <dynamic>[
          <String, dynamic>{
            'type': 'subscriber',
            'label': 'المشتركون',
            'items': <dynamic>[
              <String, dynamic>{
                'id': 7,
                'title': 'أحمد علي',
                'subtitle': 'مربع ٣ • 777000111',
                'badge': 'نشط',
                'subscriber_id': 7,
              },
            ],
          },
          <String, dynamic>{
            'type': 'invoice',
            'label': 'الفواتير',
            'items': <dynamic>[],
          },
        ],
      });

      expect(response.query, 'احمد');
      expect(response.groups, hasLength(1));
      expect(response.itemCount, 1);
      expect(response.groups.single.type, 'subscriber');
      expect(response.groups.single.items.single.title, 'أحمد علي');
    });

    test('tolerates a malformed payload without throwing', () {
      final response = GlobalSearchResponse.fromJson(<String, dynamic>{
        'groups': 'not-a-list',
      });

      expect(response.query, '');
      expect(response.isEmpty, isTrue);
      expect(response.itemCount, 0);
    });

    test('keeps numeric amounts and stringified ids', () {
      final response = GlobalSearchResponse.fromJson(<String, dynamic>{
        'groups': <dynamic>[
          <String, dynamic>{
            'type': 'invoice',
            'label': 'الفواتير',
            'items': <dynamic>[
              <String, dynamic>{
                'id': '12',
                'title': 'فاتورة 2026-0001',
                'badge': 'متأخرة',
                'amount': '1250.5',
                'subscriber_id': '5',
              },
            ],
          },
        ],
      });

      final item = response.groups.single.items.single;
      expect(item.id, 12);
      expect(item.amount, 1250.5);
      expect(item.subscriberId, 5);
      expect(item.caption, 'متأخرة');
    });
  });

  group('GlobalSearchItem.caption', () {
    test('joins badge and subtitle with a single separator', () {
      const item = GlobalSearchItem(
        id: 1,
        title: 'عداد 100',
        subtitle: 'مشترك • مسار 4',
        badge: 'نشط',
      );

      expect(item.caption, 'نشط • مشترك • مسار 4');
    });

    test('is null when there is no badge or subtitle', () {
      const item = GlobalSearchItem(id: 1, title: 'مربع 9');

      expect(item.caption, isNull);
    });
  });
}

import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/features/users_roles/data/models/rbac_models.dart';
import 'package:water_project/features/users_roles/domain/entities/rbac_entities.dart';
import 'package:water_project/features/users_roles/domain/repositories/rbac_repository.dart';
import 'package:water_project/features/users_roles/presentation/cubit/users_cubit.dart';

class _UserRepo implements RbacRepository {
  final users = <SystemUser>[
    const SystemUser(
      id: 1,
      name: 'Admin',
      email: 'admin@example.test',
      roles: ['super_admin'],
    ),
    const SystemUser(
      id: 2,
      name: 'Collector',
      email: 'collector@example.test',
      roles: ['collector'],
    ),
  ];

  @override
  Future<List<SystemUser>> getUsers({String? role}) async =>
      users.where((user) => role == null || user.roles.contains(role)).toList();

  @override
  Future<List<Role>> getRoles() async => [];

  @override
  Future<void> deleteUser(int id) async {
    final index = users.indexWhere((user) => user.id == id);
    final user = users[index];
    if (user.roles.contains('super_admin')) return;
    users[index] = SystemUser(
      id: user.id,
      name: user.name,
      email: user.email,
      roles: user.roles,
      disabledAt: DateTime.utc(2026, 11, 25),
    );
  }

  @override
  Future<void> bulkDeleteUsers(List<int> ids) async {
    for (final id in ids) {
      await deleteUser(id);
    }
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  test('legacy users are active, disabled_at preserves archived identity', () {
    final legacy = SystemUserModel.fromJson({
      'id': 2,
      'name': 'Collector',
      'email': 'collector@example.test',
      'roles': ['collector'],
    });
    final disabled = SystemUserModel.fromJson({
      'id': 2,
      'name': 'Collector',
      'email': 'collector@example.test',
      'roles': ['collector'],
      'disabled_at': '2026-11-25T00:00:00Z',
    });
    expect(legacy.isDisabled, isFalse);
    expect(disabled.isDisabled, isTrue);
    expect(disabled.id, legacy.id);
    expect(disabled.name, legacy.name);
    expect(disabled, isNot(legacy));
    expect(disabled.toJson().containsKey('disabled_at'), isFalse);
  });

  test(
    'single deactivation reloads and retains the disabled account',
    () async {
      final repo = _UserRepo();
      final cubit = UsersCubit(repo, currentUserId: 1);
      addTearDown(cubit.close);
      await cubit.load();
      await cubit.delete(cubit.state.users.last);
      expect(cubit.state.users, hasLength(2));
      expect(cubit.state.users.last.isDisabled, isTrue);
      expect(cubit.state.users.last.id, 2);
      expect(cubit.state.deletingIds, isEmpty);
    },
  );

  test('bulk refresh keeps protected and deactivated users', () async {
    final repo = _UserRepo();
    final cubit = UsersCubit(repo, currentUserId: 99);
    addTearDown(cubit.close);
    await cubit.load();
    cubit.toggleSelected(1, true);
    cubit.toggleSelected(2, true);
    await cubit.bulkDelete();
    expect(cubit.state.users, hasLength(2));
    expect(cubit.state.users.first.isDisabled, isFalse);
    expect(cubit.state.users.last.isDisabled, isTrue);
    expect(cubit.state.selectedIds, isEmpty);
  });
}

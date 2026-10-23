import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../domain/entities/rbac_entities.dart';
import '../../domain/repositories/rbac_repository.dart';

class UsersState extends Equatable {
  const UsersState({
    this.users = const [],
    this.availableRoles = const [],
    this.query = '',
    this.roleFilter,
    this.selectedIds = const {},
    this.deletingIds = const {},
    this.isLoading = false,
    this.isSaving = false,
    this.errorMessage,
  });

  final List<SystemUser> users;
  final List<Role> availableRoles;
  final String query;
  final String? roleFilter;
  final Set<int> selectedIds;
  final Set<int> deletingIds;
  final bool isLoading;
  final bool isSaving;
  final String? errorMessage;

  List<SystemUser> get visibleUsers {
    final needle = query.trim().toLowerCase();
    return users.where((user) {
      final matchesRole = roleFilter == null || user.roles.contains(roleFilter);
      final matchesQuery = needle.isEmpty ||
          user.name.toLowerCase().contains(needle) ||
          user.email.toLowerCase().contains(needle) ||
          user.roles.any((role) => role.toLowerCase().contains(needle));
      return matchesRole && matchesQuery;
    }).toList(growable: false);
  }

  UsersState copyWith({
    List<SystemUser>? users,
    List<Role>? availableRoles,
    String? query,
    String? roleFilter,
    bool clearRoleFilter = false,
    Set<int>? selectedIds,
    Set<int>? deletingIds,
    bool? isLoading,
    bool? isSaving,
    String? errorMessage,
    bool clearError = false,
  }) {
    return UsersState(
      users: users ?? this.users,
      availableRoles: availableRoles ?? this.availableRoles,
      query: query ?? this.query,
      roleFilter: clearRoleFilter ? null : roleFilter ?? this.roleFilter,
      selectedIds: selectedIds ?? this.selectedIds,
      deletingIds: deletingIds ?? this.deletingIds,
      isLoading: isLoading ?? this.isLoading,
      isSaving: isSaving ?? this.isSaving,
      errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
    );
  }

  @override
  List<Object?> get props => [
        users,
        availableRoles,
        query,
        roleFilter,
        selectedIds,
        deletingIds,
        isLoading,
        isSaving,
        errorMessage,
      ];
}

class UsersCubit extends Cubit<UsersState> {
  UsersCubit(this._repository, {this.currentUserId}) : super(const UsersState());

  final RbacRepository _repository;
  final int? currentUserId;

  Future<void> load() async {
    if (state.isLoading) return;
    emit(state.copyWith(isLoading: true, clearError: true));
    try {
      final results = await Future.wait<dynamic>([
        _repository.getUsers(role: state.roleFilter),
        _repository.getRoles(),
      ]);
      emit(
        UsersState(
          users: results[0] as List<SystemUser>,
          availableRoles: results[1] as List<Role>,
          query: state.query,
          roleFilter: state.roleFilter,
          selectedIds: state.selectedIds,
        ),
      );
    } catch (error) {
      emit(state.copyWith(isLoading: false, errorMessage: error.toString()));
    }
  }

  void search(String query) => emit(state.copyWith(query: query));

  void filterRole(String? role) {
    emit(state.copyWith(roleFilter: role, clearRoleFilter: role == null, clearError: true));
  }

  void toggleSelected(int id, bool selected) {
    emit(
      state.copyWith(
        selectedIds: selected
            ? {...state.selectedIds, id}
            : ({...state.selectedIds}..remove(id)),
      ),
    );
  }

  Future<bool> save(SystemUser user, {String? password}) async {
    if (state.isSaving) return false;
    if (user.roles.isEmpty) {
      emit(state.copyWith(errorMessage: 'اختر دوراً واحداً على الأقل للمستخدم.'));
      return false;
    }
    emit(state.copyWith(isSaving: true, clearError: true));
    try {
      final saved = user.id == 0
          ? await _repository.createUser(user, password ?? '')
          : await _repository.updateUser(user, password: password);
      final users = [...state.users];
      final index = users.indexWhere((item) => item.id == saved.id);
      if (index >= 0) {
        users[index] = saved;
      } else {
        users.add(saved);
      }
      emit(state.copyWith(users: users, isSaving: false));
      return true;
    } catch (error) {
      emit(state.copyWith(isSaving: false, errorMessage: error.toString()));
      return false;
    }
  }

  Future<void> delete(SystemUser user) async {
    if (user.id == currentUserId) {
      emit(state.copyWith(errorMessage: 'لا يمكنك حذف حسابك الحالي.'));
      return;
    }
    if (state.deletingIds.contains(user.id)) return;
    emit(state.copyWith(deletingIds: {...state.deletingIds, user.id}, clearError: true));
    try {
      await _repository.deleteUser(user.id);
      emit(
        state.copyWith(
          users: state.users.where((item) => item.id != user.id).toList(),
          selectedIds: {...state.selectedIds}..remove(user.id),
          deletingIds: {...state.deletingIds}..remove(user.id),
        ),
      );
    } catch (error) {
      emit(
        state.copyWith(
          deletingIds: {...state.deletingIds}..remove(user.id),
          errorMessage: error.toString(),
        ),
      );
    }
  }

  Future<void> bulkDelete() async {
    final ids = state.selectedIds.toList();
    if (ids.isEmpty) return;
    if (currentUserId != null && ids.contains(currentUserId)) {
      emit(state.copyWith(errorMessage: 'ألغِ تحديد حسابك الحالي قبل الحذف الجماعي.'));
      return;
    }
    emit(state.copyWith(isSaving: true, clearError: true));
    try {
      await _repository.bulkDeleteUsers(ids);
      emit(
        state.copyWith(
          users: state.users.where((user) => !ids.contains(user.id)).toList(),
          selectedIds: {},
          isSaving: false,
        ),
      );
    } catch (error) {
      emit(state.copyWith(isSaving: false, errorMessage: error.toString()));
    }
  }
}

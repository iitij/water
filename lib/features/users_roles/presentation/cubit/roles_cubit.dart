import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../domain/entities/rbac_entities.dart';
import '../../domain/repositories/rbac_repository.dart';

class RolesState extends Equatable {
  const RolesState({
    this.roles = const [],
    this.catalog,
    this.deletingIds = const {},
    this.isLoading = false,
    this.isSaving = false,
    this.errorMessage,
  });

  final List<Role> roles;
  final ShieldPermissionCatalog? catalog;
  final Set<int> deletingIds;
  final bool isLoading;
  final bool isSaving;
  final String? errorMessage;

  RolesState copyWith({
    List<Role>? roles,
    ShieldPermissionCatalog? catalog,
    Set<int>? deletingIds,
    bool? isLoading,
    bool? isSaving,
    String? errorMessage,
    bool clearError = false,
  }) {
    return RolesState(
      roles: roles ?? this.roles,
      catalog: catalog ?? this.catalog,
      deletingIds: deletingIds ?? this.deletingIds,
      isLoading: isLoading ?? this.isLoading,
      isSaving: isSaving ?? this.isSaving,
      errorMessage: clearError ? null : errorMessage ?? this.errorMessage,
    );
  }

  @override
  List<Object?> get props => [roles, catalog, deletingIds, isLoading, isSaving, errorMessage];
}

class RolesCubit extends Cubit<RolesState> {
  RolesCubit(this._repository) : super(const RolesState());

  final RbacRepository _repository;

  Future<void> load() async {
    if (state.isLoading) return;
    emit(state.copyWith(isLoading: true, clearError: true));
    try {
      final results = await Future.wait<dynamic>([
        _repository.getRoles(),
        _repository.getPermissionCatalog(),
      ]);
      emit(
        RolesState(
          roles: results[0] as List<Role>,
          catalog: results[1] as ShieldPermissionCatalog,
        ),
      );
    } catch (error) {
      emit(state.copyWith(isLoading: false, errorMessage: error.toString()));
    }
  }

  Future<bool> save(Role role) async {
    if (role.isSuperAdmin) {
      emit(state.copyWith(errorMessage: 'دور super_admin محمي ولا يمكن تعديله من المصفوفة.'));
      return false;
    }
    if (role.name.trim().isEmpty || state.isSaving) return false;
    emit(state.copyWith(isSaving: true, clearError: true));
    try {
      final saved = role.id == 0
          ? await _repository.createRole(role)
          : await _repository.updateRole(role);
      final roles = [...state.roles];
      final index = roles.indexWhere((item) => item.id == saved.id);
      if (index >= 0) {
        roles[index] = saved;
      } else {
        roles.add(saved);
      }
      roles.sort((a, b) => a.name.compareTo(b.name));
      emit(state.copyWith(roles: roles, isSaving: false));
      return true;
    } catch (error) {
      emit(state.copyWith(isSaving: false, errorMessage: error.toString()));
      return false;
    }
  }

  Future<void> delete(Role role) async {
    if (role.isSuperAdmin) {
      emit(state.copyWith(errorMessage: 'لا يمكن حذف دور super_admin.'));
      return;
    }
    if (state.deletingIds.contains(role.id)) return;
    emit(state.copyWith(deletingIds: {...state.deletingIds, role.id}, clearError: true));
    try {
      await _repository.deleteRole(role.id);
      emit(
        state.copyWith(
          roles: state.roles.where((item) => item.id != role.id).toList(),
          deletingIds: {...state.deletingIds}..remove(role.id),
        ),
      );
    } catch (error) {
      emit(
        state.copyWith(
          deletingIds: {...state.deletingIds}..remove(role.id),
          errorMessage: error.toString(),
        ),
      );
    }
  }
}

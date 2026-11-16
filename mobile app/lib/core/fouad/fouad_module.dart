import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart' show visibleForTesting;
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../features/squares/data/datasources/squares_remote_datasource.dart';
import '../../features/squares/data/repositories/squares_repository_impl.dart';
import '../../features/squares/domain/repositories/squares_repository.dart';
import '../../features/squares/presentation/cubit/squares_cubit.dart';
import '../../features/squares/presentation/pages/squares_screen.dart';
import '../../features/subscribers/data/datasources/subscribers_remote_datasource.dart';
import '../../features/subscribers/data/fake/fake_repositories.dart';
import '../../features/subscribers/data/repositories/subscribers_repository_impl.dart';
import '../../features/subscribers/domain/entities/subscriber.dart';
import '../../features/subscribers/domain/repositories/subscribers_repository.dart';
import '../../features/subscribers/presentation/cubit/subscriber360_cubit.dart';
import '../../features/subscribers/presentation/cubit/subscribers_cubit.dart';
import '../../features/subscribers/presentation/pages/subscriber_360_screen.dart';
import '../../features/subscribers/presentation/pages/subscriber_form_screen.dart';
import '../../features/subscribers/presentation/pages/subscribers_screen.dart';
import '../../features/transactions/data/datasources/transactions_remote_datasource.dart';
import '../../features/transactions/data/repositories/transactions_repository_impl.dart';
import '../../features/transactions/domain/repositories/transactions_repository.dart';
import '../../features/transactions/presentation/cubit/transactions_cubit.dart';
import '../../features/transactions/presentation/pages/transactions_screen.dart';
import 'api_client.dart';
import 'app_config.dart';

/// حاوية تبعيات وحدات فؤاد (Dev 4) — المربعات والمشتركون ودفتر الأستاذ.
class FouadDependencies {
  const FouadDependencies({
    required this.squares,
    required this.subscribers,
    required this.transactions,
  });

  final SquaresRepository squares;
  final SubscribersRepository subscribers;
  final TransactionsRepository transactions;

  factory FouadDependencies.create({Dio? dio, bool? demoMode}) {
    if (demoMode ?? AppConfig.demoMode) {
      return FouadDependencies(
        squares: FakeSquaresRepository(),
        subscribers: FakeSubscribersRepository(),
        transactions: FakeTransactionsRepository(),
      );
    }
    final api = ApiClient(dio: dio);
    return FouadDependencies(
      squares: SquaresRepositoryImpl(SquaresRemoteDatasource(api)),
      subscribers: SubscribersRepositoryImpl(SubscribersRemoteDatasource(api)),
      transactions: TransactionsRepositoryImpl(TransactionsRemoteDatasource(api)),
    );
  }
}

/// نقطة تركيب وحدات فؤاد (Dev 4) — تسجّل المستودعات في `GetIt.I` بشكل كسول وآمن (idempotent)
/// وتوفّر مسارات `GoRouter` للشاشات الأساسية (`SCR-04..07`, `SCR-19`, `SCR-20`, `SCR-22`).
class FouadModule {
  const FouadModule._();

  static bool _registered = false;

  static bool get isRegistered => GetIt.I.isRegistered<FouadDependencies>();

  static FouadDependencies get deps {
    ensure();
    return GetIt.I<FouadDependencies>();
  }

  /// تسجيل idempotent لجميع مستودعات فؤاد في `GetIt.I`.
  static FouadDependencies ensure({Dio? dio, bool? demoMode}) {
    final getIt = GetIt.I;
    if (_registered && getIt.isRegistered<FouadDependencies>()) {
      return getIt<FouadDependencies>();
    }
    if (getIt.isRegistered<FouadDependencies>()) {
      _registered = true;
      return getIt<FouadDependencies>();
    }

    final created = FouadDependencies.create(dio: dio, demoMode: demoMode);

    getIt.registerLazySingleton<FouadDependencies>(() => created);
    if (!getIt.isRegistered<SquaresRepository>()) {
      getIt.registerLazySingleton<SquaresRepository>(() => created.squares);
    }
    if (!getIt.isRegistered<SubscribersRepository>()) {
      getIt.registerLazySingleton<SubscribersRepository>(() => created.subscribers);
    }
    if (!getIt.isRegistered<TransactionsRepository>()) {
      getIt.registerLazySingleton<TransactionsRepository>(() => created.transactions);
    }

    _registered = true;
    return created;
  }

  /// مسارات `GoRouter` لوحدات فؤاد (SCR-04..07, SCR-19, SCR-20, SCR-22).
  static List<RouteBase> get routes => <RouteBase>[
        GoRoute(
          path: '/squares',
          name: 'squares',
          builder: (context, state) {
            final d = deps;
            return BlocProvider<SquaresCubit>(
              create: (_) => SquaresCubit(d.squares)..load(),
              child: const SquaresScreen(),
            );
          },
        ),
        GoRoute(
          path: '/subscribers',
          name: 'subscribers',
          builder: (context, state) {
            final d = deps;
            return RepositoryProvider<SubscribersRepository>.value(
              value: d.subscribers,
              child: MultiBlocProvider(
                providers: [
                  BlocProvider<SubscribersCubit>(
                    create: (_) => SubscribersCubit(
                      d.subscribers,
                      initialStatus: state.uri.queryParameters['status'] == 'active'
                          ? SubscriberStatus.active
                          : null,
                    )..init(),
                  ),
                ],
                child: const SubscribersScreen(),
              ),
            );
          },
        ),
        GoRoute(
          path: '/subscribers/new',
          name: 'subscriber-new',
          builder: (context, state) {
            final d = deps;
            final extra = state.extra;
            return RepositoryProvider<SubscribersRepository>.value(
              value: d.subscribers,
              child: SubscriberFormScreen(
                existing: extra is Subscriber ? extra : null,
              ),
            );
          },
        ),
        GoRoute(
          path: '/subscriber-form',
          name: 'subscriber-form',
          builder: (context, state) {
            final d = deps;
            final extra = state.extra;
            return RepositoryProvider<SubscribersRepository>.value(
              value: d.subscribers,
              child: SubscriberFormScreen(
                existing: extra is Subscriber ? extra : null,
              ),
            );
          },
        ),
        GoRoute(
          path: '/subscribers/:id',
          name: 'subscriber-360',
          builder: (context, state) {
            final d = deps;
            final id = int.tryParse(state.pathParameters['id'] ?? '') ?? 0;
            final extra = state.extra;
            final subscriber = extra is Subscriber
                ? extra
                : Subscriber(id: id, name: 'مشترك #$id');
            return RepositoryProvider<SubscribersRepository>.value(
              value: d.subscribers,
              child: MultiBlocProvider(
                providers: [
                  BlocProvider<Subscriber360Cubit>(
                    create: (_) => Subscriber360Cubit(d.subscribers)..load(subscriber.id),
                  ),
                ],
                child: Subscriber360Screen(subscriber: subscriber),
              ),
            );
          },
        ),
        GoRoute(
          path: '/subscriber-360',
          name: 'subscriber-360-legacy',
          builder: (context, state) {
            final d = deps;
            final extra = state.extra;
            final subscriber = extra is Subscriber
                ? extra
                : const Subscriber(id: 1, name: 'مشترك');
            return RepositoryProvider<SubscribersRepository>.value(
              value: d.subscribers,
              child: MultiBlocProvider(
                providers: [
                  BlocProvider<Subscriber360Cubit>(
                    create: (_) => Subscriber360Cubit(d.subscribers)..load(subscriber.id),
                  ),
                ],
                child: Subscriber360Screen(subscriber: subscriber),
              ),
            );
          },
        ),

        GoRoute(
          path: '/transactions',
          name: 'transactions',
          builder: (context, state) {
            final d = deps;
            return BlocProvider<TransactionsCubit>(
              create: (_) => TransactionsCubit(d.transactions)..load(),
              child: const TransactionsScreen(),
            );
          },
        ),
      ];

  @visibleForTesting
  static void resetForTests() {
    final getIt = GetIt.I;
    if (getIt.isRegistered<FouadDependencies>()) {
      getIt.unregister<FouadDependencies>();
    }
    if (getIt.isRegistered<SquaresRepository>()) {
      getIt.unregister<SquaresRepository>();
    }
    if (getIt.isRegistered<SubscribersRepository>()) {
      getIt.unregister<SubscribersRepository>();
    }
    if (getIt.isRegistered<TransactionsRepository>()) {
      getIt.unregister<TransactionsRepository>();
    }
    _registered = false;
  }
}

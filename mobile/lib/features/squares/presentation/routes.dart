import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../core/di.dart';
import '../domain/repositories/squares_repository.dart';
import 'cubit/squares_cubit.dart';
import 'pages/squares_screen.dart';

/// مسارات وحدة المربعات (يستوردها المهندس محمد في app_router).
class SquaresRoutes {
  static const String squares = '/squares';

  static Map<String, WidgetBuilder> routes(FouadDependencies deps) => {
        squares: (_) => BlocProvider(
              create: (_) => SquaresCubit(deps.squares)..load(),
              child: const SquaresScreen(),
            ),
      };

  static RepositoryProvider<SquaresRepository> repositoryProvider(FouadDependencies deps) =>
      RepositoryProvider<SquaresRepository>.value(value: deps.squares);
}

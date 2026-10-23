import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../core/di.dart';
import 'cubit/transactions_cubit.dart';
import 'pages/transactions_screen.dart';

class TransactionsRoutes {
  static const String transactions = '/transactions';

  static Map<String, WidgetBuilder> routes(FouadDependencies deps) => {
        transactions: (_) => BlocProvider(
              create: (_) => TransactionsCubit(deps.transactions)..load(),
              child: const TransactionsScreen(),
            ),
      };
}

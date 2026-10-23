import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../core/fouad/fouad_module.dart';
import 'cubit/subscription_fees_cubit.dart';
import 'pages/subscription_fees_screen.dart';

class SubscriptionFeesRoutes {
  static const String fees = '/subscription-fees';

  static Map<String, WidgetBuilder> routes(FouadDependencies deps, {VoidCallback? onAddFee}) => {
        fees: (_) => BlocProvider(
              create: (_) => SubscriptionFeesCubit(deps.fees)..load(),
              child: SubscriptionFeesScreen(onAddFee: onAddFee),
            ),
      };
}

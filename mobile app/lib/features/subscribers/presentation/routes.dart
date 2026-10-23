import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../core/fouad/fouad_module.dart';
import '../../../shared/widgets/subscriber_meter_selector.dart';
import '../../service_requests/presentation/cubit/service_requests_cubit.dart';
import '../../subscription_fees/presentation/cubit/subscription_fees_cubit.dart';
import '../domain/entities/subscriber.dart';
import 'cubit/subscriber360_cubit.dart';
import 'cubit/subscribers_cubit.dart';
import 'pages/subscriber_360_screen.dart';
import 'pages/subscriber_form_screen.dart';
import 'pages/subscribers_screen.dart';

class SubscribersRoutes {
  static const String subscribers = '/subscribers';
  static const String subscriberForm = '/subscriber-form';
  static const String subscriber360 = '/subscriber-360';

  static Map<String, WidgetBuilder> routes(FouadDependencies deps) => {
        subscribers: (context) => MultiBlocProvider(
              providers: [
                BlocProvider(create: (_) => SubscribersCubit(deps.subscribers)..init()),
                BlocProvider(create: (_) => ServiceRequestsCubit(deps.requests)),
              ],
              child: SubscribersScreen(
                onOpen360: (s) => Navigator.of(context).pushNamed(subscriber360, arguments: s),
              ),
            ),
        subscriberForm: (_) => const SubscriberFormScreen(),
        subscriber360: (context) {
          final subscriber = ModalRoute.of(context)!.settings.arguments as Subscriber;
          return MultiBlocProvider(
            providers: [
              BlocProvider(create: (_) => Subscriber360Cubit(deps.subscribers)..load(subscriber.id)),
              BlocProvider(create: (_) => SubscriptionFeesCubit(deps.fees)..subscriberId = subscriber.id..load()),
              BlocProvider(create: (_) => ServiceRequestsCubit(deps.requests)..subscriberId = subscriber.id..load()),
            ],
            child: Subscriber360Screen(subscriber: subscriber),
          );
        },
      };

  /// نافذة إضافة (رسوم اشتراك / طلب وايت) بمنتقي المشترك-العداد المشترك.
  static Future<({Subscriber subscriber, MeterBalance? meter})?> pickSubscriberMeter(
    BuildContext context,
    FouadDependencies deps,
  ) =>
      showSubscriberMeterPicker(context, repository: deps.subscribers);
}

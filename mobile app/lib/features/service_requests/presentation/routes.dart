import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../../core/fouad/fouad_module.dart';
import 'cubit/service_requests_cubit.dart';
import 'pages/service_requests_screen.dart';

class ServiceRequestsRoutes {
  static const String requests = '/service-requests';

  static Map<String, WidgetBuilder> routes(
    FouadDependencies deps, {
    VoidCallback? onAddRequest,
  }) => {
    requests: (_) => BlocProvider(
      create: (_) => ServiceRequestsCubit(deps.requests)..load(),
      child: ServiceRequestsScreen(onAddRequest: onAddRequest),
    ),
  };
}

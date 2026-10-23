import '../features/service_requests/data/datasources/service_requests_remote_datasource.dart';
import '../features/service_requests/data/repositories/service_requests_repository_impl.dart';
import '../features/service_requests/domain/repositories/service_requests_repository.dart';
import '../features/squares/data/datasources/squares_remote_datasource.dart';
import '../features/squares/data/repositories/squares_repository_impl.dart';
import '../features/squares/domain/repositories/squares_repository.dart';
import '../features/subscribers/data/datasources/subscribers_remote_datasource.dart';
import '../features/subscribers/data/fake/fake_repositories.dart';
import '../features/subscribers/data/repositories/subscribers_repository_impl.dart';
import '../features/subscribers/domain/repositories/subscribers_repository.dart';
import '../features/subscription_fees/data/datasources/subscription_fees_remote_datasource.dart';
import '../features/subscription_fees/data/repositories/subscription_fees_repository_impl.dart';
import '../features/subscription_fees/domain/repositories/subscription_fees_repository.dart';
import '../features/transactions/data/datasources/transactions_remote_datasource.dart';
import '../features/transactions/data/repositories/transactions_repository_impl.dart';
import '../features/transactions/domain/repositories/transactions_repository.dart';
import 'config/app_config.dart';
import 'network/api_client.dart';

/// حاوية تبعيات وحدات فؤاد (تُدمج في injection_container الخاص بالمهندس محمد عند التكامل).
class FouadDependencies {
  FouadDependencies._(this.squares, this.subscribers, this.fees, this.requests, this.transactions);

  final SquaresRepository squares;
  final SubscribersRepository subscribers;
  final SubscriptionFeesRepository fees;
  final ServiceRequestsRepository requests;
  final TransactionsRepository transactions;

  factory FouadDependencies.create() {
    if (AppConfig.demoMode) {
      return FouadDependencies._(
        FakeSquaresRepository(),
        FakeSubscribersRepository(),
        FakeSubscriptionFeesRepository(),
        FakeServiceRequestsRepository(),
        FakeTransactionsRepository(),
      );
    }
    final api = ApiClient();
    final requests = ServiceRequestsRepositoryImpl(ServiceRequestsRemoteDatasource(api));
    return FouadDependencies._(
      SquaresRepositoryImpl(SquaresRemoteDatasource(api)),
      SubscribersRepositoryImpl(
        SubscribersRemoteDatasource(api),
        tankerLookup: requests.lastAmountFor,
      ),
      SubscriptionFeesRepositoryImpl(SubscriptionFeesRemoteDatasource(api)),
      requests,
      TransactionsRepositoryImpl(TransactionsRemoteDatasource(api)),
    );
  }
}

import 'package:locker_api/api.dart';
import 'package:locker_app/services/user_service.dart';

class ItemService {
  ItemService({required UserService userService}) : _userService = userService;

  final UserService _userService;

  ApiClient _createClient() {
    return ApiClient(
      basePath: '${_userService.instanceUrl}/api',
      authentication: HttpBearerAuth()..accessToken = _userService.token,
    );
  }

  Future<List<Item>> getBorrowedItems() async {
    final client = _createClient();
    return await ItemApi(client).itemsBorrowed() ?? [];
  }

  Future<List<Item>> getItems() async {
    final client = _createClient();
    return await ItemApi(client).itemsIndex() ?? [];
  }

  Future<Item?> getItem(int id) async {
    return getItems()
        .then((items) => items.firstWhere((item) => item.id == id));
  }

  Future<void> borrowItem(int id) async {
    final client = _createClient();
    await ItemApi(client).itemsBorrow(id);
  }

  Future<void> returnItem(int id) async {
    final client = _createClient();
    await ItemApi(client).itemsReturn(id);
  }
}

import 'package:flutter/material.dart';
import 'dashboard_screen.dart';
import 'products_screen.dart';
import 'customers_screen.dart';
import 'locations_screen.dart';
import 'expenses_screen.dart';
import 'announcements_screen.dart';
import 'stock_screen.dart';
import 'counts_screen.dart';
import 'movements_screen.dart';
import 'orders_screen.dart';
import 'shifts_screen.dart';
import 'workers_screen.dart';
import 'pos_screen.dart';
import 'pos_sales_screen.dart';
import 'reviews_screen.dart';
import 'loyalty_screen.dart';
import 'plan_screen.dart';
import 'permissions_screen.dart';
import 'reports_screen.dart';
import 'appearance_screen.dart';
import 'settings_screen.dart';
import 'account_screen.dart';
import 'notifications_screen.dart';
import 'pending_screen.dart';

/// Mapa de rutas = claves del menú que envía el servidor (ws_mobile_me).
Widget routeScreens(String route) {
  switch (route) {
    case 'products': return const ProductsScreen();
    case 'locations': return const LocationsScreen();
    case 'stock': return const StockScreen();
    case 'counts': return const CountsScreen();
    case 'movements': return const MovementsScreen();
    case 'orders': return const OrdersScreen();
    case 'shifts': return const ShiftsScreen();
    case 'workers': return const WorkersScreen();
    case 'customers': return const CustomersScreen();
    case 'pos': return const PosScreen();
    case 'pos-sales': return const PosSalesScreen();
    case 'reviews': return const ReviewsScreen();
    case 'loyalty': return const LoyaltyScreen();
    case 'expenses': return const ExpensesScreen();
    case 'anuncios': return const AnnouncementsScreen();
    case 'plan': return const PlanScreen();
    case 'permissions': return const PermissionsScreen();
    case 'reports': return const ReportsScreen();
    case 'appearance': return const AppearanceScreen();
    case 'settings': return const SettingsScreen();
    case 'account': return const AccountScreen();
    case 'notificaciones':
    case 'notifications': return const NotificationsScreen();
    case 'pending': return const PendingScreen();
    default: return const DashboardScreen();
  }
}

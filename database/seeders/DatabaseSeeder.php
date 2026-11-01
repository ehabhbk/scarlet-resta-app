<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent guard: skip if already seeded
        if (\App\Models\User::where('email', 'admin@scarletresta.com')->exists()) {
            $this->command->info('Database already seeded. Skipping.');
            return;
        }

        // 1. Restaurant (idempotent)
        $restaurant = \App\Models\Restaurant::updateOrCreate(
            ['slug' => 'scarlet-restaurant'],
            [
                'name' => 'scarlet restaurant',
                'phone' => '+966500000000',
                'email' => 'info@scarletresta.com',
                'address' => 'الرياض، المملكة العربية السعودية',
                'currency' => 'SAR',
                'symbol' => 'ر.س',
                'tax_number' => '300000000000000',
                'tax_rate' => 0.15,
                'timezone' => 'Asia/Riyadh',
                'locale' => 'ar',
                'primary_color' => '#0f172a',
                'is_active' => true,
            ]
        );

        // 2. Permissions
        $permissions = [
            // Dashboard
            'view_dashboard',
            // Products
            'view_products', 'create_products', 'edit_products', 'delete_products',
            // Categories
            'view_categories', 'create_categories', 'edit_categories', 'delete_categories',
            // Orders
            'view_orders', 'create_orders', 'edit_orders', 'delete_orders', 'cancel_orders',
            // Tables
            'view_tables', 'create_tables', 'edit_tables', 'delete_tables',
            // Kitchen
            'view_kitchen', 'update_kitchen_order',
            // Inventory
            'view_inventory', 'create_inventory', 'edit_inventory', 'delete_inventory',
            // Recipes
            'view_recipes', 'create_recipes', 'edit_recipes',
            // Purchases & Suppliers
            'view_purchases', 'create_purchases', 'edit_purchases', 'view_suppliers', 'create_suppliers', 'edit_suppliers',
            // Customers
            'view_customers', 'create_customers', 'edit_customers',
            // Employees
            'view_employees', 'create_employees', 'edit_employees',
            // Finance
            'view_finance', 'create_expenses', 'edit_expenses', 'view_payments', 'close_shift',
            // Reports
            'view_reports', 'export_reports',
            // Settings
            'view_settings', 'edit_settings',
            // Users & Roles
            'view_users', 'create_users', 'edit_users', 'delete_users',
            'view_roles', 'create_roles', 'edit_roles', 'delete_roles',
        ];

        foreach ($permissions as $perm) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }

        // 3. Roles with permissions
        $rolePermissions = [
            'Super Admin' => $permissions,
            'Admin' => array_diff($permissions, ['delete_users', 'delete_roles']),
            'Manager' => ['view_dashboard','view_products','create_products','edit_products','view_categories','create_categories','edit_categories','view_orders','create_orders','edit_orders','cancel_orders','view_tables','edit_tables','view_kitchen','update_kitchen_order','view_inventory','create_inventory','edit_inventory','view_recipes','create_recipes','edit_recipes','view_purchases','create_purchases','edit_purchases','view_suppliers','create_suppliers','edit_suppliers','view_customers','create_customers','edit_customers','view_employees','create_employees','edit_employees','view_finance','create_expenses','edit_expenses','view_payments','close_shift','view_reports','export_reports','view_settings','edit_settings','view_users','create_users','edit_users','view_roles','create_roles','edit_roles'],
            'Cashier' => ['view_dashboard','view_products','view_categories','create_orders','edit_orders','view_tables','edit_tables','view_kitchen','view_customers','create_customers','view_finance','view_payments','close_shift'],
            'Waiter' => ['view_dashboard','view_products','view_categories','create_orders','edit_orders','view_tables','edit_tables','view_kitchen','view_customers','create_customers'],
            'Chef' => ['view_kitchen','update_kitchen_order','view_products'],
            'Accountant' => ['view_dashboard','view_finance','create_expenses','edit_expenses','view_payments','close_shift','view_reports','export_reports','view_purchases','view_suppliers'],
            'Inventory Manager' => ['view_inventory','create_inventory','edit_inventory','view_purchases','create_purchases','edit_purchases','view_suppliers','create_suppliers','edit_suppliers','view_recipes','create_recipes','edit_recipes'],
        ];

        foreach ($rolePermissions as $roleName => $perms) {
            $role = \Spatie\Permission\Models\Role::updateOrCreate(
                ['name' => $roleName, 'guard_name' => 'sanctum'],
                ['guard_name' => 'sanctum']
            );
            $role->syncPermissions($perms);
        }

        // 4. Super Admin User
        $admin = \App\Models\User::create([
            'name' => 'المشرف العام',
            'email' => 'admin@scarletresta.com',
            'password' => Hash::make('admin123'),
            'restaurant_id' => $restaurant->id,
            'phone' => '+966500000001',
            'is_active' => true,
        ]);
        $admin->assignRole('Super Admin', 'sanctum');

        // 5. Categories
        $categories = [];
        $categoryNames = [
            ['name' => 'المشروبات الساخنة', 'color' => '#ef4444'],
            ['name' => 'المشروبات الباردة', 'color' => '#3b82f6'],
            ['name' => 'الوجبات الرئيسية', 'color' => '#10b981'],
            ['name' => 'المقبلات', 'color' => '#f59e0b'],
            ['name' => 'الحلويات', 'color' => '#ec4899'],
        ];
        foreach ($categoryNames as $cn) {
            $categories[] = \App\Models\Category::create([
                'restaurant_id' => $restaurant->id,
                'name' => $cn['name'],
                'slug' => Str::slug($cn['name']),
                'color' => $cn['color'],
                'sort_order' => array_search($cn, $categoryNames),
                'is_active' => true,
            ]);
        }

        // 6. Products
        $products = [
            ['name' => 'قهوة عربية', 'category' => 'المشروبات الساخنة', 'sale_price' => 12.00, 'cost_price' => 4.00, 'stock' => 100],
            ['name' => 'لاتيه', 'category' => 'المشروبات الساخنة', 'sale_price' => 18.00, 'cost_price' => 7.00, 'stock' => 80],
            ['name' => 'كابوشينو', 'category' => 'المشروبات الساخنة', 'sale_price' => 18.00, 'cost_price' => 7.00, 'stock' => 80],
            ['name' => 'أيس كوفي', 'category' => 'المشروبات الباردة', 'sale_price' => 20.00, 'cost_price' => 8.00, 'stock' => 60],
            ['name' => 'عصير برتقال', 'category' => 'المشروبات الباردة', 'sale_price' => 15.00, 'cost_price' => 6.00, 'stock' => 50],
            ['name' => 'برجر دجاج', 'category' => 'الوجبات الرئيسية', 'sale_price' => 35.00, 'cost_price' => 14.00, 'stock' => 40],
            ['name' => 'برجر لحم', 'category' => 'الوجبات الرئيسية', 'sale_price' => 45.00, 'cost_price' => 20.00, 'stock' => 40],
            ['name' => 'بطاطس مقلية', 'category' => 'المقبلات', 'sale_price' => 15.00, 'cost_price' => 5.00, 'stock' => 60],
            ['name' => 'ناجتس دجاج', 'category' => 'المقبلات', 'sale_price' => 22.00, 'cost_price' => 9.00, 'stock' => 50],
            ['name' => 'كعكة شوكولاتة', 'category' => 'الحلويات', 'sale_price' => 25.00, 'cost_price' => 10.00, 'stock' => 30],
        ];
        foreach ($products as $p) {
            $cat = \App\Models\Category::where('name', $p['category'])->first();
            \App\Models\Product::create([
                'restaurant_id' => $restaurant->id,
                'category_id' => $cat->id,
                'name' => $p['name'],
                'slug' => Str::slug($p['name']),
                'sku' => 'SKU-' . Str::upper(Str::random(6)),
                'sale_price' => $p['sale_price'],
                'cost_price' => $p['cost_price'],
                'current_stock' => $p['stock'],
                'min_stock' => 5,
                'is_available' => true,
                'is_best_seller' => in_array($p['name'], ['برجر لحم', 'كابوشينو']),
                'sort_order' => array_search($p, $products),
            ]);
        }

        // 7. Areas & Tables
        $mainHall = \App\Models\Area::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'الصالة الرئيسية',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $vip = \App\Models\Area::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'الصالون الخاص (VIP)',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        for ($i = 1; $i <= 10; $i++) {
            \App\Models\Table::create([
                'restaurant_id' => $restaurant->id,
                'area_id' => $i <= 6 ? $mainHall->id : $vip->id,
                'name' => 'طاولة ' . $i,
                'code' => 'T-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'capacity' => $i <= 6 ? 4 : 6,
                'status' => 'available',
                'is_active' => true,
            ]);
        }

        // 8. Kitchen Stations
        foreach (['الشواء', 'المقليات', 'المشروبات', 'الحلويات', 'المقبلات'] as $idx => $station) {
            \App\Models\KitchenStation::create([
                'restaurant_id' => $restaurant->id,
                'name' => $station,
                'color' => ['#ef4444', '#f59e0b', '#3b82f6', '#ec4899', '#10b981'][$idx],
                'sort_order' => $idx,
                'is_active' => true,
            ]);
        }

        // 9. Inventory Units
        foreach (['كيلوغرام', 'قطعة', 'لتر', 'صندوق', 'حزمة'] as $idx => $unit) {
            \App\Models\InventoryUnit::create([
                'restaurant_id' => $restaurant->id,
                'name' => $unit,
                'abbreviation' => ['kg', 'pcs', 'L', 'box', 'bundle'][$idx],
                'sort_order' => $idx,
                'is_active' => true,
            ]);
        }

        // 10. Suppliers
        $suppliers = [
            ['name' => 'مؤسسة الأغذية المتحدة', 'phone' => '+966500000010', 'contact_person' => 'أحمد محمد'],
            ['name' => 'مزرعة النajem', 'phone' => '+966500000011', 'contact_person' => 'سليمان علي'],
            ['name' => 'موزع المشروبات northern', 'phone' => '+966500000012', 'contact_person' => 'fatima ahmed'],
        ];
        foreach ($suppliers as $s) {
            \App\Models\Supplier::create(array_merge($s, [
                'restaurant_id' => $restaurant->id,
                'balance' => 0,
                'is_active' => true,
            ]));
        }

        // 11. Inventory Items
        $unitKg = \App\Models\InventoryUnit::where('abbreviation', 'kg')->first();
        $unitPcs = \App\Models\InventoryUnit::where('abbreviation', 'pcs')->first();
        $supplier1 = \App\Models\Supplier::first();
        $inventoryItems = [
            ['name' => 'دجاج طازج', 'unit' => 'kg', 'stock' => 50, 'min' => 10, 'cost' => 18.00, 'supplier' => $supplier1],
            ['name' => 'لحم بقري', 'unit' => 'kg', 'stock' => 30, 'min' => 5, 'cost' => 45.00, 'supplier' => $supplier1],
            ['name' => 'بطاطس', 'unit' => 'kg', 'stock' => 40, 'min' => 8, 'cost' => 3.00, 'supplier' => $supplier1],
            ['name' => 'بن قهوة', 'unit' => 'kg', 'stock' => 20, 'min' => 3, 'cost' => 35.00, 'supplier' => $supplier1],
            ['name' => 'حليب', 'unit' => 'L', 'stock' => 30, 'min' => 5, 'cost' => 5.00, 'supplier' => $supplier1],
        ];
        foreach ($inventoryItems as $ii) {
            $unit = \App\Models\InventoryUnit::where('abbreviation', $ii['unit'])->first();
            \App\Models\InventoryItem::create([
                'restaurant_id' => $restaurant->id,
                'unit_id' => $unit->id,
                'supplier_id' => $ii['supplier']->id,
                'name' => $ii['name'],
                'sku' => 'INV-' . Str::upper(Str::random(6)),
                'category' => 'general',
                'current_stock' => $ii['stock'],
                'min_stock' => $ii['min'],
                'reorder_qty' => $ii['stock'] * 2,
                'cost_per_unit' => $ii['cost'],
                'is_active' => true,
            ]);
        }

        // 12. Customers
        for ($i = 1; $i <= 5; $i++) {
            \App\Models\Customer::create([
                'restaurant_id' => $restaurant->id,
                'name' => 'عميل تجريبي ' . $i,
                'phone' => '+966500000' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'customer_type' => 'regular',
                'loyalty_points' => 0,
                'total_spent' => 0,
                'total_orders' => 0,
                'is_active' => true,
            ]);
        }

        // 13. Employees & Users
        $roles = ['Cashier', 'Waiter', 'Chef', 'Accountant', 'Inventory Manager'];
        foreach ($roles as $idx => $jobTitle) {
            $user = \App\Models\User::create([
                'name' => 'موظف ' . $jobTitle,
                'email' => strtolower($jobTitle) . '@scarletresta.com',
                'password' => Hash::make('password123'),
                'restaurant_id' => $restaurant->id,
                'phone' => '+9665000001' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                'is_active' => true,
            ]);
            $user->assignRole($jobTitle, 'sanctum');
            \App\Models\Employee::create([
                'restaurant_id' => $restaurant->id,
                'user_id' => $user->id,
                'employee_id' => 'EMP-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                'name' => 'موظف ' . $jobTitle,
                'job_title' => $jobTitle,
                'phone' => '+9665000001' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                'hire_date' => now()->subYear(),
                'salary' => 5000.00,
                'status' => 'active',
            ]);
        }

        $this->command->info('Database seeded successfully!');
    }
}
